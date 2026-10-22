# #153 phase 2 — one time authority: the caller's clock in the scripts (plan)

> **Status:** PLAN ONLY. No code, no test, no decision record and no canonical document is changed by the PR that adds this file. Nothing here is a decision. Every item marked **recommendation** is the executor's, for the Lead or the Owner to rule on.
> **Base:** `main@f5897b0`. `src/`, `tests/` and `composer.json` are byte-identical to `1e951ee`, so every line citation below holds at both.
> **Decision being implemented:** Owner, [#153 comment](https://github.com/Maatify/php-rate-limiter/issues/153): option (a), one time authority = the caller's clock passed to the scripts that compute or compare `expiresAt` / `updatedAt`, instead of `TIME`. This also covers the silent decisions B/E and #155. Option (b), a validator tolerance, was not chosen.
> **Binding inputs:**
> * DEC-016 L35–L41 (a contract that receives the caller's `now` uses it; `TIME` only where no semantic time exists) and L57–L59.
> * Phase 1 inventory: `investigations/clock-skew-153/REPORT.md` §6.
> * #156 inventory: `investigations/clock-mixes-153/PLAN.md` §1.1, §1.3, §1.4; its REPORT.
> * #155.
> * DEC-008 "Backward Compatibility" L105–L116, and the repo's DEC-008 analysis convention BC1–BC4 as defined in `investigations/k2-identity-admission-97/5b2.1/PLAN.md` L33: BC1 no existing signature changes; BC2 no existing implementer breaks; BC3 defaults preserve behaviour; BC4 additive docs.
> * KS §4.3.3 L415–L423 (write-current / read-previous; at most one historical state).
> **Rules kept from every earlier stage:** plan before code; red tests before the implementation; no registered condition is changed after it has run; a contract question is raised as an LT before the build; no existing test is edited without a ruling; a confirmed finding blocks Ready and merge; the PR stays Draft.

## 0. What this plan has to give (the assignment)

1. A binding inventory of every Lua site that calls `TIME` and then writes or compares `expiresAt` / `updatedAt` / `reentryValidUntil` or derives an expiry from it (§1).
2. The design: each script takes `now` (and `nowMs` where needed) from `ARGV`; the interface changes with a DEC-008 analysis; the validator keeps no tolerance (§2).
3. The transition: state written with Redis time before the upgrade is read as it is, per key type, worse or better, bounded by the real skew (§3).
4. The gates, registered before code (§4).
5. Out of scope, by name (§5).
6. The order of commits (§6).

**Read before the assignment's text could be followed as written (§7):**
* Adding a parameter to an existing interface method breaks every Host store that implements it. PHP refuses to load the class; measured, §2.4.
* Absolute expiries taken from the caller's clock would delete keys immediately under a fixed or simulated clock (§2.3).
* Several in-script invariants combine the caller's time with Redis's `PTTL` (§2.5).
* In a fleet, "one clock" holds per Host, not across Hosts (§2.7).

These are LT-P2-01 … LT-P2-10 (§7); the build waits for the rulings.

## 1. Binding inventory (`READ` at `main@f5897b0`)

`S` = `src/Repository/Redis/RedisFullCapabilityStore.php`, `EP` = `src/Service/EvaluationPipeline.php`.

**Shared prelude `LUA_TIME_PRELUDE` (S L49–L98):**
* `TIME` at L50;
* `now` / `nowStr` (Redis seconds) at L51–L52;
* `nowMsStr` / `nowMs` at L53–L54;
* `ttlBackendRepresentable()` (L94–L97) uses `nowMsStr`, so that a relative `EXPIRE` stays below Redis's int64 millisecond limit.

**PHP-side `TIME`:** `ensureGenerationTtlRepresentable()` S L1908–L1929 (`TIME` at L1913), a representability check only, called from `mutateGenerationBoundScore` (L1628).

**Scripts with no `TIME`:** `CIRCUIT_READ` L140, `SCORE_GET` L148, `WATCH_GET` L755, `PAUSE_READ` L1203 (it already takes the caller's `now`, `ARGV[2]`, L1214), and every script of `RedisAdmissionOperations` (all caller time).

In the table, "PHP reader" = where the stored value is compared with the caller's clock today. The ids A–I, L-1…L-5 are the phase 1 / #156 ids.

| # | Script (S) and `TIME` use | Written with Redis time today | Compared with Redis time inside the script | Store method → interface (caller `now` in the signature?) | PHP reader (caller clock) | What changes when `now` is passed |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | `SCORE_INCREMENT` L100 (prelude) | `updatedAt` L117, L130; relative `EXPIRE` on create L118 | — | `increment` L1266 → `RateLimitStoreInterface` L26 (no) | B: `DecayCalculator` L44–L45, L84–L85 via `EP` L2816/L2819, L1661/L1669; G: `EP` L2324, L2340 (cooldown marker); H: `RateLimitOperationalReader` L158–L190, L278 | `updatedAt` = caller `now`; `EXPIRE` stays relative (§2.3) |
| 2 | `SCORE_SET` L170 (prelude) | `updatedAt` L172; `EXPIRE` L173 | — | `set` L1286 → RSI L44 (no) | B (same readers); caller `EP` L1675 already passes a caller timestamp as the **value** | `updatedAt` = caller `now` |
| 3 | `BLOCK_SET` L541 (prelude) | `expiresAt` = `now + duration` L543; `EXPIRE` L545 | — | `block` L1292 → RSI L54 (no); caller `EP` L2170 (level < 2 only) | E/E′ via `BLOCK_GET` | `expiresAt` = caller `now` + duration |
| 4 | `BLOCK_GET` L549 (`TIME` at L569) | — | block dropped **and deleted** when `expiresAt ≤` Redis second L569–L570 (L-3) | `checkBlock` L1299 → RSI L62 (no); callers with `$now` in scope: `EP` L531 (L549/L558), L2740 (L2746), `RateLimitOperationalReader` L167 | E: `EP` L550, L559, L571; E′: L2740–L2747 | drop/delete by caller `now`: one authority for honour and deletion |
| 5 | `BUDGET_INCREMENT` L576 (prelude) | `epochStart` L613; `EXPIRE duration` L614 | active epoch iff `now < end` L606; L611 representability | `incrementBudget` L1325 → RSI L91 (no) | F: `BudgetTracker` L56; `FixedWindowSimpleRateLimiter` L135/L148–L154 (simple runtime `retryAfter`); `SimpleRateLimitOperationalReader` L130–L135; H | `epochStart` and the epoch test from caller `now` |
| 6 | `BUDGET_GET` L618 (prelude) | — | epoch dropped and deleted when `now ≥ end` L643–L644 | `getBudget` L1312 → RSI L73 (no) | as 5 | as 5 |
| 7 | `BUDGET_SEED` L650 (prelude) | `epochStart` L700; `EXPIRE` L701; a carried seed keeps the seed's start L694 with `EXPIRE remaining` L691–L695 | L679, L689 | `incrementBudgetWithSeed` L1336 → `BudgetSeedStoreInterface` L46–L51 (no; docblock L28–L35 states an implicit "now") | as 5 | as 5; `remaining = seedEnd − now` from caller `now` |
| 8 | `DISTINCT_ADD` L705 (prelude) | meta `expiresAt` L725; `EXPIRE` L725 | **#155 site** L721 (`expires ≤ now` ⇒ `malformed`) | `addDistinct` L1348 → `CorrelationStoreInterface` L26 (no); no caller in `src/` | — | as 10 |
| 9 | `WATCH_INCREMENT` L730 (prelude) | meta `expiresAt` L750; `SET EX` / `EXPIRE` L750 | **#155 site** L745 | `incrementWatchFlag` L1354 → `CorrelationStoreInterface` L36 (no); callers `AntiEquilibriumGate` L32 (no clock), `EP` L394, L1316, L1430, L1749 | — | as 10 |
| 10 | `BOUNDED_SNAPSHOT` L763 (prelude) | meta `expiresAt` L795; `EXPIRE` L795; returned L798 | **#155 site** L781 | `addDistinctBoundedWithSnapshot` L1371 → `BoundedCorrelationSnapshotStoreInterface` L21–L26 (no); `addDistinctBounded` L1365 → `BoundedCorrelationStoreInterface` L21 (no); callers `EP` L875 (`$now` L867), `EphemeralBucket` L93 (no clock), `EP` L1392 | **A**: `BoundedCorrelationResultValidator` L58–L59 with `EP` L867 | `expiresAt` = caller `now + ttl`, the **same** integer as L867 (§2.2); "logically expired, physically present" = expired, not malformed (§2.3) |
| 11 | `ROTATED_SNAPSHOT` L801 (prelude) | current meta `expiresAt` L846–L847, L899; bridge `PEXPIRE` from `prevExpiry·1000 − nowMs` L928, L942–L944 | previous treated absent at `prevExpiry ≤ now` L822; **#155 sites** L834, L874; bridge L881 | `…WithSnapshotAcrossRotation` L1391 → `BoundedCorrelationSnapshotRotationStoreInterface` L19–L27 (no); L1379, L1385 → `CorrelationRotationStoreInterface` L24, `BoundedCorrelationRotationStoreInterface` L17 (no); callers `EP` L900 (`$now` L867), `EphemeralBucket` L115, `EP` L1411 | **A** | as 10; bridge lifetime from caller `nowMs` |
| 12 | inline watch rotation L1403 (prelude) | current meta `expiresAt` L1436 | previous active iff `expiresAt > now` L1418 | `incrementWatchFlagAcrossRotation` L1400 → `CorrelationRotationStoreInterface` L40 (no); caller `EP` L1438 | — | as 10 |
| 13 | `LEASE` L967 (prelude, representability only L977) | — (stores caller `ARGV[1] + lease` L978) | — (caller compare L975) | `acquireProbeLease` L1490 → `CircuitBreakerProbeStoreInterface` L18 (**yes**) | `CircuitBreaker` | representability from the caller's value; no `TIME` |
| 14 | `LIFECYCLE_MUTATE` L177 (`TIME` L178–L180) | create: `updatedAt` = Redis s, `expiresAt` = ⌈(Redis ms + ttl)/1 s⌉, **`PEXPIREAT` absolute** L276–L279; update: `updatedAt` L327, `PEXPIREAT` capped L325–L328; legacy expiry from `nowMs + PTTL` L323 | **invariant** `expiresAt·1000 ≥ nowMs + PTTL` L308–L310; expired L321 | `mutateGenerationBoundScore` L1625 → `PunishmentLifecycleStoreInterface` L76–L82 (no); caller `EP` L1714 | B, C | `updatedAt` / `expiresAt` from caller `now` / `nowMs`; physical deadline relative (§2.3); invariant §2.5 |
| 15 | `LIFECYCLE_READ` L334 (`TIME` L335–L338) | — | legacy expiry L388; **invariant** L393; expired L394; **L-4** block active L403, evidence served L419 | `readGenerationBoundScoreState` L1575 → `PunishmentLifecycleStoreInterface` L52 (no); callers `EP` L279 (`$now` L280), L1710, L2126 | C: `EP` L280–L283 | all comparisons by caller `now` |
| 16 | `LIFECYCLE_CLAIM` L424 (`TIME` L425–L428) | claim marker `PEXPIREAT` capped by `nowMs + PTTL` L521–L534 | **L-2** block active L475; **invariant** L501–L502; expiry L503; `validUntil` L513 | `claimPostPunishmentReentry` L1726 → `PunishmentLifecycleStoreInterface` L148 (no); caller `EP` L173 ← `RateLimiterEngine` L435 | — | as 15 |
| 17 | `HARD_BLOCK` L982 (`TIME` L985; used only on the lifecycle path, L1036–L1042) | lifecycle path: block `expiresAt` L1188, pause `start:finish` L1172–L1173, cycle members L1143, `reentryValidUntil` L1197; relative `EXPIRE` L1189, `extendUntil` L1103–L1107 | **invariants** L1079, L1091; L1093; **L-1** L1138; **L-5** L1122, L1161, L1168 | lifecycle: `blockWithPunishmentLifecycleTracking` L1686 → `PunishmentLifecycleStoreInterface` L128–L139 (no; PHP passes `ARGV[5] = 0`, L1697); cycle path: `blockWithCycleTracking` L1507 → `HardBlockCycleStoreInterface` L23 (**yes**, caller `EP` L2161) | E, I (via `PAUSE_READ`) | lifecycle path takes the caller `now` like the cycle path; `TIME` removed |

**What the inventory shows (`READ`):**
* Every stored timestamp that the PHP side or another script compares with the caller's clock is written by rows 1–3, 5, 7, 10–12, 14 and 17. They are compared in rows 4, 6, 8–12 and 14–17.
* Only rows 13 and 17-cycle take the caller's time today. The other contracts have no `now` in their signatures.

## 2. Design

### 2.1 The principle

* Every script in §1 receives the caller's time in `ARGV`: integer seconds, plus integer milliseconds where the script works in milliseconds (rows 11, 14–17). `redis.call('TIME')` is removed from all of them and from the prelude.
* The PHP `TIME` at S L1913 is replaced by the same caller value.
* Every comparison listed in §1 then uses one value.

### 2.2 One `now` per request (LT-P2-05)

The pipeline reads the clock separately at many sites for one request:
* `EP` L280, L531, L867, L1315, L1672–L1693, L1776, L1780, L2161, L2740, L2765;
* `DecayCalculator` L44, L84;
* `BudgetTracker` L55.

If the stored value and the comparing value come from two readings of one clock, a second boundary between the two readings still splits them: phase 1's zero-skew crossing, between caller instants. **Recommendation:** the engine reads the clock once per `limit()` call and hands that value to every store call and every comparison of the request. With `FixedClock` all readings are already equal, which is why S0-PARITY on `FixedClock` can be byte-identical (§4).

### 2.3 Physical lifetime stays relative; "logically expired, physically present" is expired (LT-P2-03)

* **The assignment's text** computes the relative `EXPIRE` from the caller's own time, so the #155 defect disappears. Read literally, with an absolute `EXPIREAT now + ttl`, this **breaks fixed and custom clocks**: under `FixedClock('2025-01-01 12:00:00')` (`tests/Support/Clock/FixedClock.php` L13, the suite's default), every key would be created already expired and deleted at once. DEC-016 L42–L43 keeps fixed/custom clock behaviour coherent. `LIFECYCLE_MUTATE`'s `PEXPIREAT` (L279, L328) and `LIFECYCLE_CLAIM`'s (L534) have the same property once their `nowMs` is the caller's.
* **Recommendation:**
  * the physical lifetime stays relative (`EXPIRE ttl`; `PEXPIRE` for the millisecond scripts), on Redis's clock, where it only bounds memory;
  * the logical deadline is the caller's `now + ttl`;
  * every check "the key exists but its logical `expiresAt ≤ now`" (L721, L745, L781, L834, L874, and the equivalents in rows 4, 6, 12, 14–16) means **expired**: the script treats the state as absent and recreates it, instead of returning `malformed … metadata`.
* **That is the #155 fix:** at zero skew the physical life outlives the logical one by the creation's sub-second fraction, and that sliver becomes "expired", not "malformed".
* **Cost:** a stored deadline in the past is no longer detected as corruption. Every other structural check stays.

### 2.4 Interfaces and DEC-008 (LT-P2-01)

**Measured for this plan** (PHP 8.5.9; a scratch script, not committed):
* An interface method that gains an optional trailing `?int $now = null` makes **every class that implements the old signature fail to load**: `Fatal error: Declaration of HostStore::add(string $key, int $ttl): int must be compatible with StoreV2::add(string $key, int $ttl, ?int $now = null): int`.
* An old implementation that receives the extra argument from a new caller ignores it silently (`func_num_args()` = 3, no error).

**Who implements these interfaces** (from the PHP-side map for this plan):
* `src/`: only `RedisFullCapabilityStore` (S L41).
* `tests/Support`: about twenty classes, e.g. `InMemoryRateLimitStore` L22, `StatefulInMemoryCorrelationStore` L13, `FullCapabilityInMemoryStore` L19. Most take an injected `ClockInterface`.
* Anonymous and inline stores in about twenty test files.
* `examples/`: `basic-rate-limit.php` L65, L499; `simple-fixed-window.php` L35, L160 (PHP `time()`); `operational-read.php` L40.
* Investigation libraries.
* Every Host store: the public documents invite them. `README.md` L62–L64, L132–L134, L187; `docs/guides/USAGE_GUIDE.md` L22, L33, L127–L128, L214–L240 ("custom implementers must add its methods", L238); `docs/KEY_STRATEGY.md` L291–L293, L494–L495; `RATE_LIMITER_PACKAGE_REFERENCE.md` L65, L80, L234–L236, L607–L608, L826–L827.

| Option | BC1 (no existing signature changes) | BC2 (no existing implementer breaks) | BC3 (defaults preserve behaviour) | BC4 (additive docs) | Cost |
| --- | --- | --- | --- | --- | --- |
| (i) as assigned: `?int $now = null` added to the existing interface methods, falling back to the store's injected clock | **fails** (nine interfaces change). It also contradicts DEC-016's own Decision: "Existing public interfaces remain unchanged" (L42) | **fails**: every Host store, every test store and every example store stops loading until edited | a Host store that is edited but ignores `$now` keeps its own time silently | — | a major-version break; every test support store is edited |
| **(ii) recommendation:** new additive capability interfaces whose methods take `now` (the repo's established pattern: `BoundedCorrelationSnapshotStoreInterface`, `BudgetSeedStoreInterface`, `HardBlockCycleStoreInterface`, KS L291–L293); `RedisFullCapabilityStore` and the test support stores implement them; the pipeline calls them when the store implements them, else the existing methods | holds | holds | a store that does not opt in keeps today's behaviour: its own clock, i.e. the phase 1 / #156 mixes **for that store only**; the official store opts in | new interfaces documented as optional | more interfaces (one per existing time-less interface, or one aggregate; sub-question of LT-P2-01); the "not implemented" branch is a second path to test |

The validator `BoundedCorrelationResultValidator` keeps **no tolerance** under either option (Owner, option (b) not chosen).

### 2.5 In-script invariants that combine the caller's time with Redis's `PTTL` (LT-P2-02)

`expiresAt · 1000 ≥ nowMs + PTTL` is checked at five places:
* `LIFECYCLE_MUTATE` L310;
* `LIFECYCLE_READ` L393;
* `LIFECYCLE_CLAIM` L502;
* `HARD_BLOCK` L1079, L1091.

`nowMs + PTTL` caps deadlines at `LIFECYCLE_MUTATE` L309, L325 and `LIFECYCLE_CLAIM` L501, L521. `PTTL` is the physical remainder on Redis's clock.

With the caller's `nowMs` and a relative physical lifetime (§2.3), `nowMs_read + PTTL_read = expiry_physical + (σ_read − σ_write)`. So the invariant fails when the reader's clock is ahead of the writer's by more than the ⌈·⌉ slack (< 1 s). That cannot happen with one Host and a stable offset. It can happen across Hosts, or after a clock step (§2.7).

| Option | What it means | Cost |
| --- | --- | --- |
| **(a) recommendation:** compare logical deadlines only (both from callers); keep `PTTL` only as "the key is alive" (`> 0`); drop the five invariants and the four caps, or replace the caps with the logical deadline | No cross-clock arithmetic remains | The "inconsistent generated score expiry" corruption checks are removed; a test that asserts them needs a ruling |
| (b) `PEXPIRETIME` (Redis ≥ 7.0) instead of `nowMs + PTTL` | The physical absolute deadline, with no clock | Still compares a caller deadline with a Redis-clock deadline; adds a documented Redis minimum, which does not exist today (no Redis version is stated in `README.md` or `docs/`; CI runs 7.0.15) |
| (c) store the physical deadline as a hash field | Exact, clock-free | A state-shape change: legacy keys lack the field, needing a migration rule |

### 2.6 The store's fallback clock (LT-P2-06)

* `RedisFullCapabilityStore`'s constructor (S L1257–L1264) takes no clock today, and the file uses no `ClockInterface`.
* **Recommendation:** an optional trailing `?ClockInterface $clock = null` constructor parameter. The class is `final`, so this satisfies BC1/BC2 for constructors. It defaults to `SystemClock(UTC)`, not `TIME`.
* It is used only by the **existing** methods when they are called directly, without the new capability, e.g. by a Host or by `RateLimitOperationalReader`'s own reads.
* The builder never relies on it: the pipeline always passes `now`.

### 2.7 What one authority covers, and what it does not (LT-P2-04)

* Within one Host, every stored timestamp and every comparison come from one clock, so phase 1's exception and #156's B/E flips cannot occur. The acceptance in §4.6 checks this.
* **Across Hosts** sharing one Redis, writes come from several clocks.
* A snapshot written by Host W and read by Host R makes the unchanged validator throw (L59, `expiresAt > now + ttl`) when W's clock is ahead of R's. It throws for as long as R's clock is behind the creation second: phase 1's law (REPORT §3.2), with "Redis" replaced by "the writer".
* A **backward clock step** on one Host (an NTP correction) does the same to its own earlier writes.
* The B/E/F/I comparisons have the same inter-Host character.
* So option (a) moves the requirement from "Host clock = Redis clock" to "Host clocks agree with each other". Today, a fleet synced among itself but not with Redis throws; after the change it does not.
* This needs a ruling, not a tolerance (the Owner rejected one). The choices are:
  * accept and document the residual (FAILURE_SEMANTICS, USAGE_GUIDE: fleets must share a time source; the law and its bound);
  * measure it first with a two-clock arm (§4.7);
  * or both.

### 2.8 Unchanged

* The admission contracts (`RedisAdmissionOperations`): already caller time; NM-6 shows no change.
* `PAUSE_READ`.
* Every TTL, window, budget and ladder value.
* Rotation (KS §4.3.3: current-first, previous read-only).

## 3. Transition: state written with Redis time before the upgrade

**No migration and no `max`.** After the upgrade, a key written with Redis time `R0` is read as it is and compared with the caller's `now`, for the rest of that key's life. Today the same values are already compared with the caller's clock in PHP (A–I); after the upgrade the script compares them too.

Per key type, with σ = caller − Redis on the reading Host:

| Key type (row in §1) | Stored with Redis time | After the upgrade, behind by `s` | After the upgrade, ahead by `a` | Bound |
| --- | --- | --- | --- | --- |
| score `updatedAt` (1, 2, 14) | `⌊R0⌋` | decay exactly as today's B (PHP already uses the caller's clock); unchanged until the key's next write, which stores the caller's time | as today | until the next write or the key's TTL; `|σ|` per decay boundary |
| L1 block `expiresAt` (3, read by 4) | `⌊R0⌋ + D` | `BLOCK_GET` now honours it until the caller reaches it, i.e. up to `s` later than today, but never beyond the physical lifetime `R0 + D` (Redis clock): at most the creation's sub-second fraction (< 1 s) longer | ends `a` earlier, the same as today's PHP E | < 1 s (behind), `a` (ahead) |
| lifecycle L2+ block `expiresAt` (17, read by 4, 15, 16, 17) | `⌊R0⌋ + D` | as L1: < 1 s longer at most; the re-entry evidence is served by the same comparison | ends `a` earlier (today's E) | same |
| cycle-path block (17) | caller time already | — | — | none |
| budget `epochStart` (5–7) | `⌊R0⌋` | active until the caller's end, bounded by the physical `EXPIRE duration` (Redis): < 1 s longer at most | ends `a` earlier (today's F, PHP side) | < 1 s / `a` |
| snapshot / distinct / watch meta `expiresAt` (8–12) | `⌊R0⌋ + ttl` | the physical key goes first: absent, so a new window, as today | logically expired while physically present for `a`: **expired** by §2.3, so a new window. Without §2.3 this would be `malformed` on every ahead Host at every old key's tail | `a` |
| validator on an old snapshot (A) | `⌊R0⌋ + 600` | throws (UP) only for a window created within `s` before the request, i.e. once, for keys created in the last `s` seconds before the switch | no LO: §2.3 returns a fresh window | `s` seconds after the switch, once per window |
| generation-bound K4 (14–16) | `updatedAt` `⌊R0⌋`, `expiresAt` ⌈(R0 ms + 86,400 s)/1 s⌉, physical absolute | comparisons as above; the five invariants of §2.5 compare `expiresAt` with caller `nowMs + PTTL` | **ahead by more than the ⌈·⌉ slack: `inconsistent generated score expiry` on every read of every pre-upgrade lifecycle key**, unless LT-P2-02 removes or replaces the invariant | until each key's 86,400 s life ends |
| cycle members, pause intervals (17, L-5, I) | lifecycle path: `⌊R0⌋` | window edges (21,600 s, 86,400 s, 600 s) move by `σ`, as today's L-5 | same | `|σ|` |
| re-entry `validUntil`, claim marker (15–17) | score `expiresAt` | served until the caller reaches it, bounded by the physical score life | blanked `a` earlier (today's C) | < 1 s / `a` |

**Rolling deploys** (old and new code on different Hosts at once): the old code writes Redis time and the new code writes caller time, to the same keys. Every effect above applies with σ of the pair (writer, reader). It is bounded by the larger skew and ends when the last old Host is gone.

**Conclusion (`DERIVED`):** with §2.3 and LT-P2-02 option (a), no key type needs a migration or a new record. Every transition effect is bounded by `|σ|`, or by < 1 s on the physical side, and by the key's remaining life. If LT-P2-02 is ruled (c), legacy lifecycle keys need a migration rule, raised then.

## 4. Gates (registered before code)

Every red test is committed before the implementation and fails on `f5897b0`. "Before" = `f5897b0`, "after" = the fix head. All on real Redis (the CI image) unless stated.

### 4.1 Red tests

| Gate | Setup | Before | After |
| --- | --- | --- | --- |
| **RED-1a** | `login_protection` `checkOnly`, fresh account + device + IP, clock = Redis time − 1 s (phase 1 `ALIGN-BEHIND1`) | 100 / 100 `RateLimiterException` (validator) | 0 / 100 |
| **RED-1b** | the zero-skew crossing made deterministic: clock = (Redis second boundary) − 1 µs at L867, i.e. the caller still in the previous second | 100 / 100 | 0 / 100 |
| **RED-1c** | `SYS` clock, 10,000 fresh requests (phase 1 `SYS_F_LOGIN`) | not gated (phase 1: 0–2 / 10,000) | 0 / 10,000 |
| **RED-2 (B)** | the retryAfter-honouring client of #156 (P-B): two failures, L1 refusal, wait `retryAfter`, return; clock behind 2 s | 100 / 100 refused again | 0 / 100 |
| **RED-3 (E)** | lifecycle L2 block, clock ahead 1 s, client returns after `retryAfter` (#156 P-E) | blocked without re-entry (#156: 98–100 / 100) | `ALLOW` with re-entry, 100 / 100 |
| **RED-4 (#155)** | P-HT: 20 windows created at a Redis fraction ≥ 0.5, one request each inside `[S + 600, S + 600 + f)` | 20 / 20 `malformed bounded metadata` | 0 / 20 (no error; a fresh window) |
| **RED-5** (added) | P-TAIL: caller ahead by `a` ∈ {0.5, 1, 2}, request in the last `a` seconds of a window (phase 1 §4) | 60 / 60 validator exceptions (LO) | 0 / 60, and no `malformed` error |
| **RED-6** (added, depends on LT-P2-02) | lifecycle K4 score written by a clock behind by 2 s, read, mutated and claimed by a clock ahead by 2 s | — (does not occur today) | no `inconsistent generated score expiry`; decisions as by the logical deadline |
| **RED-7** (added, BC) | a store implementing only today's interfaces (a copy of the old signatures, inline in the test) registered with the builder | loads and runs | still loads and runs (BC2); the official store takes the new path |
| **RED-8** (added, §2.2) | a recording clock and executor (phase 1 harness technique): for every request, the integer passed to each script equals the request's single `now` | — | 100 % |

### 4.2 S0-PARITY

* Every existing test passes **without modification**; any change needs a ruling.
* The builder goldens and the 5-b3a / 5-b3b goldens are byte-identical.
* On `FixedClock` with the in-memory stores, every decision is byte-identical. Those stores already use the injected clock, so the change does not reach them.
* **Disclosed in advance:** Redis integration and system tests that combine a `FixedClock` with the Redis store currently store Redis time while the clock is frozen. After the fix they store the frozen time. Any assertion that changes there is reported by name with its cause, before any test edit is proposed (it needs a ruling).

### 4.3 Suites and analysis

* PHPStan max with normal and lowest dependencies.
* The full suite in both modes (normal, lowest) with full logs (`--display-errors`, `COMPOSER_PROCESS_TIMEOUT=0`).
* CVH twice.
* NM-6 on the admission contracts, unchanged.

### 4.4 Detectors

* A red test that passes on `f5897b0` invalidates its gate.
* RED-1a, RED-4 and RED-5 each carry the phase 1 detector: the same arm on `f5897b0` reproduces the phase 1 count.

### 4.5 Non-regression of the measured S0 behaviour

* At `σ` = 0 on `SYS` clocks, the #156 stream outcomes (S-BEN, S-RA; S-ATK was reported unmeasured) equal the pre-fix outcomes, except the requests that phase 1 / #156 attributed to the defect.
* Each difference is listed with its attribution.

### 4.6 Final acceptance

* Rerun the #154 harness (E-SAME, every cell) on the fixed code: **0 validator exceptions in every cell**, P-HT 0 / 20, P-TAIL 0 / 60.
  * The phase 1 detectors read inverted: D-100 becomes 0 / 2,000.
  * V-3 is re-registered for this run as "0 exceptions, 0 UP/LO".
* Rerun the #156 probe groups (P-B, P-E, P-RA): **0 flips at every `σ`**.
* The mechanism of the rerun is LT-P2-07: the archived runners refuse any baseline other than theirs (V-1).

### 4.7 Optional, by ruling on LT-P2-04

* A two-clock arm: two runtimes, writer ahead by 1 s and reader at 0, sharing one Redis.
* It measures the residual of §2.7 on the fixed code: the validator count and B/E flips.

## 5. Out of scope, by name

* N-154-01 (#157), the flaky test.
* Any change of TTL, window, budget, ladder or capacity values.
* Any time tolerance (option (b)).
* Any change to the admission contracts.
* The non-bounded K1/K2/K3 policy logic.
* Migration tooling, unless LT-P2-02 is ruled (c).

## 6. Order

1. This plan (alone).
2. The Lead's rulings on §7, recorded as a dated amendment (alone).
3. Red tests (alone; CI red by design until 4).
4. Implementation, possibly split by script family if the Lead asks, each commit alone.
5. Documents:
   * DEC-016 dated note (LT-P2-08);
   * `docs/FAILURE_SEMANTICS.md`;
   * `docs/KEY_STRATEGY.md`;
   * `docs/guides/USAGE_GUIDE.md` Integration Boundaries;
   * `RATE_LIMITER_PACKAGE_REFERENCE.md`, `CHANGELOG.md`, `llms.txt`;
   * the docblocks that state Redis time today: S L33–L35, S L1652, `BudgetSeedStoreInterface` L28–L35, `HardBlockCycleStoreInterface` L18–L21 / L38–L41 (DEC-016 wording), and the new interfaces' own docblocks.
6. RESULTS (gate by gate, tested tree, logs).

Each commit alone. Any contract question that appears during the build is raised as an LT before the code it concerns.

## 7. Questions for the Lead before any test or code (LT)

* **LT-P2-01 — how the store contracts receive `now`.** The assigned form (an optional `?int $now = null` on the existing interface methods) fails BC1 and BC2. PHP refuses to load any class implementing the old signature (§2.4, measured), and the public documents invite Host stores. It also contradicts DEC-016 L42 ("Existing public interfaces remain unchanged"), which this change implements. **Recommendation:** (ii), additive capability interfaces with `now` and the pipeline's fallback to the existing methods. Sub-question: one interface per existing time-less interface (nine), or one aggregate interface for the official store and the test support stores.
* **LT-P2-02 — the five `nowMs + PTTL` invariants and four caps** (§2.5). **Recommendation:** (a), logical deadlines only, with `PTTL` kept as "alive". No existing test asserts that message by text (searched: `tests/`). The tests that assert such a state fails, through `assertOperationFails`, are listed by name in the tests commit with a ruling request. (b) adds a Redis ≥ 7.0 requirement; (c) changes the state shape and needs a migration.
* **LT-P2-03 — "logically expired, physically present" = expired, not malformed** (§2.3). It is the #155 fix, and it makes ahead Hosts and the transition safe. It removes the detection of a stored past deadline as corruption. **Recommendation:** accept. No existing test asserts those messages by text (searched: `tests/`). The tests that build that shape and assert failure are listed by name in the tests commit with a ruling request.
* **LT-P2-04 — the residual across Hosts and after clock steps** (§2.7). The validator stays exact, so a writer ahead of a reader re-creates phase 1's law between Hosts. **Recommendation:** document it in FAILURE_SEMANTICS and USAGE_GUIDE as the requirement "one time source for the fleet", and measure it once with the optional arm §4.7 before RESULTS. The Owner's premise ("both sides one clock") holds per Host, so this needs the Owner's word, not only the Lead's.
* **LT-P2-05 — one `now` per request** (§2.2). **Recommendation:** read the clock once per `limit()` call (and once per `claimPostPunishmentReentry`) and pass it down. Gate RED-8.
* **LT-P2-06 — the official store's fallback clock** (§2.6). **Recommendation:** an optional trailing `?ClockInterface` constructor parameter, defaulting to `SystemClock(UTC)`, never `TIME`.
* **LT-P2-07 — how the acceptance reruns the archived harnesses.** `investigations/clock-skew-153/harness/run.sh` refuses any baseline but `1e951ee` (V-1), and the #156 runner its own. **Recommendation:** a runner under `investigations/clock-skew-153/phase2/acceptance/` that `require`s the archived `lib.php` files unchanged (blob SHAs recorded, as #156 did), with V-1 set to the fix head. No archived file is edited.
* **LT-P2-08 — DEC-016's text.** Its Consequences (L57–L59) say the score, budget, bounded-state, watch and persistence primitives "may continue to use Redis `TIME`". After the change none does. The assignment asks for a dated note. Is a dated note enough under the Decision Governance standard, or is a superseding record needed? The executor reads the change as an application of DEC-016's own rule 1 once the contracts receive `now`, so a dated note.
* **LT-P2-09 — the simple runtime.** `FixedWindowSimpleRateLimiter` (L135, L148–L154) and `SimpleRateLimitOperationalReader` (L130–L135) read budget epochs written by rows 5–7. **Recommendation:** in scope; the same scripts and the same fix, with S0-PARITY covering their tests.
* **LT-P2-10 — `TIME` used only for representability** (prelude L94–L97 for relative `EXPIRE`, `LEASE` L977, S L1913). After the change no script has Redis time. **Recommendation:** check representability against the caller's value. The int64-millisecond limit is far from any real clock, and with a fixed past clock the relative `EXPIRE` stays representable by construction.

## 8. Lead rulings and Owner decision (2026-10-21, recorded before any test or code)

Sources:
* Lead rulings: [PR #158 comment 6323172871](https://github.com/Maatify/php-rate-limiter/pull/158#issuecomment-6323172871).
* Owner decision on LT-P2-04 and the authorisation to build: [comment 6323267208](https://github.com/Maatify/php-rate-limiter/pull/158#issuecomment-6323267208).

§0–§7 are kept as written and read together with this section. Where they differ, this section governs. The inventory (§1) and the transition (§3) were accepted as written.

| LT | Ruling | Effect on the plan |
| --- | --- | --- |
| P2-01 | **(ii).** Additive capability interfaces that take `now`, **one per existing time-less interface (a 1:1 mirror of today's boundaries)**, not one aggregate. The pipeline takes the new path on `instanceof`, else the existing method. The official store and every support store implement them. USAGE_GUIDE states that a Host store that does not implement them keeps the phase 1 / #156 behaviour, for that store only. Form (i) rejected (BC1/BC2, DEC-016 L42) | §2.4 option (ii); nine new interfaces |
| P2-02 | **(a).** Logical deadlines only. `PTTL` means "alive" only (> 0). The five invariants (L310, L393, L502, L1079, L1091) and the four caps (L309, L325, L501, L521) are removed. The tests asserting failure of those states are listed by name in the tests commit, each awaiting its own ruling | §2.5 option (a) |
| P2-03 | **Accepted.** The physical lifetime stays relative. "Logically expired, physically present" = expired, not malformed; this is the #155 fix. Its cost (a stored past deadline is no longer detected as corruption) is stated explicitly in FAILURE_SEMANTICS | §2.3 |
| P2-04 | **Owner decision.** The residual across servers (writer ahead of reader, or a backward clock step) is accepted and **documented as a Host duty**: "a fleet that shares one Redis shares one time source", in USAGE_GUIDE (Integration Boundaries) and FAILURE_SEMANTICS, with the law and its bound. It is **measured once** with the two-clock arm (§4.7: writer ahead by 1 s, reader at 0, one Redis) before RESULTS, so it is written with its numbers. No tolerance in the validator. Standards reference: PACKAGE_BUILDING_STANDARD §2 and §25 (`ClockInterface` from shared-common is the only time abstraction; the Host owns its policy) | §2.7; §4.7 becomes **required**, not optional |
| P2-05 | **Accepted.** One clock reading per `limit()` and per `claimPostPunishmentReentry()`, passed to every store call and every comparison | §2.2; RED-8 |
| P2-06 | **Accepted.** An optional trailing `?ClockInterface $clock = null` constructor parameter on the official store, defaulting to `SystemClock(UTC)`, never `TIME` | §2.6 |
| P2-07 | **Accepted.** An acceptance runner under `phase2/acceptance/` that `require`s the two archived `lib.php` files unchanged (blob SHAs recorded), with V-1 on the fix head. No archived file is touched | §4.6 |
| P2-08 | **A dated "Application note"** in DEC-016 Consequences (L57–L59): the primitives now receive `now`, so rule 1 applies to them. DEC-016's decision itself is unchanged, so there is no superseding record (Governance §9: no rewriting; dated appending is the established pattern). The index description of DEC-016 is re-checked | §6 docs |
| P2-09 | **In scope.** `FixedWindowSimpleRateLimiter` and `SimpleRateLimitOperationalReader` use the same scripts. S0-PARITY covers their tests | §1 rows 5–7 |
| P2-10 | **Accepted.** Representability is checked on the caller's value. No `TIME` remains in any script | prelude, `LEASE`, S L1913 |

**Binding additions to the gates (Lead):**
* RED-6 runs after LT-P2-02 (a).
* The Redis + `FixedClock` tests that will store the frozen time are disclosed by name before any edit.
* The final acceptance is as in §4.6: a full rerun of #154 and of the #156 probes on the fix head, with 0 exceptions and 0 flips.

**Order (Owner):** this amendment → red tests RED-1…8, with the named lists of affected `FixedClock` / Redis tests and malformed-state tests, before any edit → implementation → documents (DEC-016 application note, FAILURE_SEMANTICS, USAGE_GUIDE, KS) → two-clock arm and acceptance runner (#154 and #156 probes rerun) → RESULTS with both full logs. Each commit alone. Any contract question is raised as an LT before the build.

## 9. Test-time note (2026-10-21, with the red tests, before any implementation)

Written with the red-tests commit, before any `src/` change and before any edit of an existing test. It records how each gate is realised, the named lists the Owner asked for, and two new contract questions.

### 9.1 How the red tests are realised (`tests/Integration/Redis/CallerTimeAuthorityRedisTest.php`)

* **A virtual clock instead of real waits.**
  * `tests/Support/Clock/RedisAnchoredClock.php` returns the Redis `TIME` plus a fixed skew plus a virtual advance that the test moves forward.
  * Today the Redis side does not see the advance; after the fix every comparison follows the caller. This reproduces #156's probes at the comparison they measured, in milliseconds, without its 600–1,200 s waits.
  * The real-time probes themselves (P-B, P-E, P-RA, P-HT, P-TAIL) stay in the acceptance rerun (§4.6).
* **RED-1a, RED-1b:** as §4.1, 100 fresh pre-checks each.
  * RED-1b's clock returns the start of the current Redis second minus 1 µs.
  * On `f5897b0`: 100 / 100 validator exceptions in each.
* **RED-1c (10,000 `SYS` requests) is measured by the acceptance rerun (`SYS_F_*` cells of #154), not in the suite.**
  * It is not red before the fix (phase 1: 0–2 / 10,000).
  * 10,000 real-Redis requests would take about 30–80 s of the Test Suite job, whose margin is about 2.5 min (N-5c-02).
* **RED-2 (B)** runs on a mirror of `login_protection` without `DISTRIBUTED_ACCOUNT` (`login_mirror_without_distributed`, an anonymous subclass in the test).
  * On the preset itself, behind by 2 s, the snapshot exception (A) fires at the first pre-check and masks B (#156 PLAN §1.2, "A would mask B–I").
  * On `f5897b0`: 100 / 100 returning clients refused again (`SOFT_BLOCK`).
* **RED-3 (E):** `login_protection`, ahead 1 s, three failures → lifecycle L2 block → poll → wait the polled `retryAfter` → return.
  * On `f5897b0`: 100 / 100 `HARD_BLOCK` without re-entry.
* **RED-4 (#155) uses seeded state:** the exact state a window has in the last fraction of a second of its own life (logical `expiresAt` = the current Redis second, both keys alive for 900 ms), at the five sites L721, L745, L781, L834, L874.
  * On `f5897b0`: `malformed … metadata` / `malformed current bounded state` at all five.
  * The engine-level P-HT (20 windows, real 600 s wait) stays in the acceptance rerun.
* **RED-5 (P-TAIL):** virtual advance of `600 − a/2`, 60 accounts.
  * On `f5897b0`: validator exceptions.
* **RED-6 and RED-7 are regression guards and pass before the fix (by design).**
  * RED-6 uses the mirror policy for the same masking reason.
  * RED-7 uses `tests/Support/FullCapability/LegacySignatureFullCapabilityStore.php`, which implements exactly today's `FullCapabilityStoreInterface` (27 methods, generated by reflection from `f5897b0`).
* **RED-8 is realised as "the Host clock is read once per call".**
  * `tests/Support/Clock/CountingClock.php` counts the reads across `limit()` for six commands and `claimPostPunishmentReentry()`.
  * It implies §4.1's wording (every value of a request is the same integer) without depending on script argument positions.
  * On `f5897b0`: 5 to 12 reads per call.
* **RED-9 (added, LT-P2-09):**
  * Setup: the simple fixed window, with the application 2 s behind Redis.
  * Assertion: a refused consume reports `retryAfter` = the window (60).
  * On `f5897b0`: 62.

### 9.2 Named list A — Redis + `FixedClock` tests that will store the frozen time after the fix

Their outcome is expected unchanged: every stored time and every comparison come from the same frozen clock. Each is reported by name in RESULTS. Any change is a finding, with no edit before a ruling.

* `RedisFullCapabilityStoreExtRedisIntegrationTest`:
  * `testFirstRuntimeRequestOnEmptyRedisWorksWithoutSeedingOrHostNormalization` (L85);
  * `testWrongTypeCircuitStateEscapesPublicRuntimeWithoutFallbackOrSignals` (L113);
  * `testRealRedisCircuitRecoversThroughHealthyPhpRedisPing` (L132).
* `RedisFullCapabilityStoreIntegrationTest`:
  * `testPublicBuilderWorkflowUsesTheOfficialRedisAggregateStore` (L1970);
  * `testPublicBuilderMigratesPreviousBudgetIntoCurrentGeneration` (L2143);
  * `testPublicBuilderPersistsHardBlockCycleAndPauseThroughLimitWorkflow` (L2189);
  * `testPublicBuilderWorkflowUsesOfficialRedisBudgetAndCircuitProbePaths` (L2242).
* `RedisHealthStatusReplyRegressionTest`:
  * `testOperationalReadReportsBackendHealthyForBooleanTruePing` (L39);
  * `testCircuitRecoversThroughBooleanTruePingWithoutWallClockWaiting` (L61).
* `KnownDeviceAllowanceConcurrencyIntegrationTest` (its `pipeline()` helper uses `FixedClock('@' . redisNow)`, L239–L241):
  * `testOverlappingFailuresConsumeTheAllowanceExactlyOncePerAccount` (L86);
  * `testLoginOverlapSplitsIntoRecordedConflictAndEarlyBlockExactly` (L112);
  * `testOverlappingFailuresSeedThePreviousEpochExactlyOnceUnderRotation` (L145).

### 9.3 Named list B — tests whose assertions change outcome under LT-P2-02 (invariants and caps removed)

Each awaits its own ruling. None is edited before it. All are in `tests/Integration/Redis/RedisFullCapabilityStoreIntegrationTest.php`. The state each one seeds is valid under LT-P2-02 (a); today only a removed check rejects it.

| Test | Line | Removed check that rejects it today | Assertion that will fail |
| --- | --- | --- | --- |
| `testLifecyclePublicationRejectsPhysicallyInconsistentScoreWithoutPartialWrites` | L1465 | `HARD_BLOCK` L1091 (seed `expiresAt = now + 1`, `EXPIRE 600`, L1469–L1470) | L1472 (then L1473–L1474) |
| `testGeneratedPhysicalExpiryCorruptionFailsReadMutateClaimAndPublication` | L1477 | L393, L310, L502, L1091 (seed L1483–L1488) | L1489, first iteration |
| `testCurrentAbsentPublicationRunsFullStructuralValidationOnPreviousEvidenceAndPhysicalExpiry` (third case) | L1660 | L1079 (seed L1681–L1682) | L1684 |
| `testMalformedStoredGenerationFailsAcrossReadMutateClaimAndPublication` (case `'-1'`, MUTATE only) | L1530 | L310: MUTATE's `validInteger(…, false)` (L195–L204, L291) accepts negative generations, so only the invariant rejects `'-1'`. After the fix MUTATE returns `{0}` without an exception. See LT-P2-11 | L1540, second iteration |
| `testLifecycleMutationPreservesPhysicalAndAuthoritativeExpiryAcrossAllK4Boundaries` | L1825 | cap L325 (the mutation's physical deadline bounded by the source's) | L1887 always; L1839, L1873 in most runs |
| `testPreviousClaimIsReadOnlyAndWritesOnlyCurrentMarker` | L1748 | cap L521 (claim marker bounded by the score's physical deadline) | L1763 (`TTL <= 600`) |

**Checked and not affected:** about 40 other lifecycle, correlation and malformed-state tests. The structural checks that reject them (format, missing field, TTL −1, partial evidence) do not change. No existing test seeds a "logically expired, physically present" bounded / distinct / watch state, so LT-P2-03 changes no existing test.

**At risk, depending on the implementation:** `testCurrentLifecycleTemporalFieldsAboveLuaExactRangeAreHandledExactly` (L595). It holds if the uncapped relative deadlines are computed in exact decimal strings, which the implementation must do.

**Plan correction:** §2.3 says "equivalents in rows 4, 6, 12, 14–16". Row 12 (the inline watch-rotation script) has no malformed-on-expired check; an expired previous is already ignored at L1418.

### 9.4 Named list C — existing tests whose comments or names would state Redis time after the fix

Each awaits a ruling on whether to edit the comment or name. Their assertions are expected to pass, except under LT-P2-12.

* `RedisFullCapabilityStoreIntegrationTest::testGenerationBoundScoreCreationEnforcesAtomicInScriptTtlGuard` (L254; docblock L241–L253: "its own same-script TIME read").
* `…::testPunishmentLifecycleRedisTimeDerivedBoundaryFailsBeforeAnyRedisMutation` (L443, name).
* `…::testLifecycleCyclesUseRedisTimeExactlyOnceAndPauseIsNotRenewed` (L1103, name; its bounds L1171–L1172 compare with `redisTime()`, so they hold where Host and Redis share a clock, as in CI).
* `RedisSimpleFixedWindowIntegrationTest` class docblock L24–L27 ("resolves budget-epoch validity from the Redis server's own real-time clock (Lua TIME)").

### 9.5 New contract questions (LT, before the code they concern)

* **LT-P2-11 — `LIFECYCLE_MUTATE` accepts a negative generation.**
  * READ (L383), CLAIM (L499) and PUBLICATION (L1089) require `^[1-9]%d*$`. MUTATE's `validInteger(observedGeneration, false)` (L195–L204, used at L291) accepts `'-1'`.
  * Today that is masked by the L310 invariant, which LT-P2-02 removes (list B, L1530).
  * **Recommendation:** MUTATE validates the stored generation with the same positive-integer rule as READ / CLAIM / PUBLICATION. This is one existing structural rule applied consistently, with no change to valid state. List B's L1530 row then passes unchanged.
* **LT-P2-12 — exact representability at the Redis boundary without `TIME`.**
  * `testRelativeExpiryPreflightIsMillisecondAccurateAtTheRealRedisBoundary` (L3137) asserts that each primitive rejects a relative expiry at Redis's own millisecond boundary **with no residue** (L3178).
  * Under LT-P2-10 as ruled (check on the caller's value), a caller clock behind Redis's at that boundary passes the check. Redis's `EXPIRE` then rejects the expiry **after** the script has written the data, leaving a key with no TTL: a malformed state, created by the store.
  * The boundary is absurd in practice (TTL near 2^63 ms), but the guarantee is real and asserted.
  * Options:
    * **(a) recommendation:** Redis stays the judge without `TIME`. The script calls `EXPIRE` / `PEXPIRE` through `redis.pcall`; on an error it deletes exactly the keys it created in that call and returns the same error. No residue, exact at Redis's boundary, and the caller's clock is not involved. L3137 passes unchanged.
    * (b) the check uses the caller's milliseconds: exact only when the Host and Redis clocks agree, so residue is possible under skew. L3137 passes in CI (one kernel) and not under skew.
    * (c) as ruled: L3137 fails deterministically and needs a ruling.

### 9.6 Observation (no change)

`WATCH_GET` (L755–L760) reads only the physical watch key and has no `TIME`, so it is outside the inventory and unchanged. A watch whose logical expiry has passed stays readable for its physical remainder, as today.

## 10. Rulings on §9 (2026-10-21, recorded before the implementation)

Source: [PR #158 comment 6323629474](https://github.com/Maatify/php-rate-limiter/pull/158#issuecomment-6323629474) (Lead; the Owner accepted both recommendations).

| Item | Ruling | Gate |
| --- | --- | --- |
| LT-P2-11 | **Accepted.** MUTATE applies the same positive-integer rule to the observed generation (non-positive = `malformed generation-bound score state`), so the four operations agree | L1530's three cases (`'0'`, `'-1'`, `'not-a-number'`) pass **unmodified** on all four paths after the invariants are removed |
| LT-P2-12 | **Accepted.** The preflight on the caller's value stays as a cheap check. Redis itself is the final judge through `redis.pcall` on every `EXPIRE` / `PEXPIRE` / `SET … EX`. On an error the script deletes **everything this call created** (all or nothing, as the admission rule) and returns an error reply named for the cause (`expiry not representable`), which surfaces as `RateLimiterException`, never `BackendFailureException` | L3137 passes unmodified; an added test proves the atomic deletion (keyspace dump before = after when Redis rejects) |
| List B | **A declared edit limited to the invariant / cap lines, no test deleted.** L1465, L1477, L1660 (third case): "physically inconsistent = rejected" becomes "the logical deadline governs; `PTTL` is checked as alive only", every other structural check unchanged. L1530: no edit (LT-P2-11 covers it). L1825, L1748: the assertion is restated as "the physical life is relative ≤ the requested one, and the logical deadline = the caller's `now` + ttl". Each edit is shown in RESULTS with a before / after diff and its reason (precedent LT-22 / LT-30) | — |
| List C | **A declared text-only edit** (docblocks and names), listed in RESULTS | — |
| §9.1 points (virtual clock, RED-1c in the acceptance, the mirror policy, RED-8's form) | **All four accepted**, on condition that the real probes (#154 in full, #156's probes, RED-1c) stay in the acceptance as registered, and their result is a **PASS condition, not a report** | §4.6 |
| `WATCH_GET` outside the inventory | Accepted; stated in RESULTS | — |

## 11. Build-time note (2026-10-21, before the implementation commit)

Two corrections made while building, recorded with their measured cause. They depart from the **text** of §2 and §8–§10, not from their principle.

### 11.1 Milliseconds for the rotation and lifecycle operations (§2.1)

* **What the text said.** §2.1: "integer seconds, plus integer milliseconds where the script works in milliseconds (rows 11, 14–17)". The first build passed whole seconds to every operation.
* **Measured cause.** `testBridgeUsesExactPreviousRemainingLifetimeAndNeverRefreshesIt` (`RedisFullCapabilityStoreIntegrationTest` L104) failed: "Failed asserting that 1999 is equal to 1974 or is less than 1974".
  * The bridge's lifetime is the previous window's remaining time in milliseconds (`planBridgePttl`).
  * From whole seconds, it overshoots the previous window's logical end by the sub-second part of the caller's time.
  * Seconds would also have changed a generated K4 expiry from ⌈(now ms + TTL) / 1 s⌉ to `now + TTL`, i.e. up to one second shorter.
* **Correction.** These methods take the caller's time in milliseconds (`$nowMilliseconds`): `…AcrossRotationAt` for the three operations that run `ROTATED_SNAPSHOT`, and `readGenerationBoundScoreStateAt`, `mutateGenerationBoundScoreAt`, `claimPostPunishmentReentryAt`.
  * The scripts derive the second as ⌊ms / 1000⌋.
  * A generated expiry is ⌈(now ms + TTL) / 1 s⌉, as today.
  * `blockWithPunishmentLifecycleTrackingAt` takes seconds, like `blockWithCycleTracking` on the same script: with the invariants removed (LT-P2-02) `HARD_BLOCK` needs no milliseconds.
  * The physical remainder of a mutated or claimed key is `expiresAt · 1000 − now ms`, relative (`PEXPIRE`), uncapped.
* **Result.** L104 passes unchanged.

### 11.2 `SET … EX` is not used as Redis's judge of an expiry (§10, LT-P2-12)

* **What the text said.** §10: `redis.pcall` on every `EXPIRE` / `PEXPIRE` / `SET … EX`.
* **Measured cause.** The same script was run on both versions: `pcall` of `SET k v EX <ttl>` and of `EXPIRE k <ttl>`, with `ttl` = one second past Redis's bound for the current Redis second.

  | Redis | `SET … EX` past the bound | `EXPIRE` past the bound |
  | --- | --- | --- |
  | 7.0.15 (the CI image) | error `invalid expire time in 'set' command` | error `invalid expire time in 'expire' command` |
  | 8.10.0 (E-SAME) | **`OK`, and nothing is stored** (`EXISTS` 0) | error `invalid expire time in 'expire' command` |

  On 8.10.0, `testRelativeExpiryPreflightIsMillisecondAccurateAtTheRealRedisBoundary` (L3137) failed for the probe lease ("Malformed persisted Redis state was accepted"): its `SET … EX` past the bound returned `OK`, wrote nothing and raised no error.
* **Correction.** Every `SET … EX` in the scripts becomes `SET` followed by `redis.pcall('EXPIRE', …)` / `PEXPIRE`, with the same all-or-nothing undo. This covers the watch flag (create), the rotated watch flag (create), the probe lease and the claim marker. A lease key that already exists takes the expiry first and is then written with `KEEPTTL`.
* **Result.** L3137 passes unchanged on 8.10.0, and RED-10 passes on all eight families.
* **For FAILURE_SEMANTICS (documents commit):** Redis does not judge an expiry given through `SET … EX` the same way on every version, so the store never relies on it as the judge; it uses only `EXPIRE` / `PEXPIRE`.

## 12. Rulings on RESULTS `38c5ce7` (2026-10-22, recorded before the F-P2-03 test)

**Sources:**
* Lead rulings: [PR #158 comment 6334226943](https://github.com/Maatify/php-rate-limiter/pull/158#issuecomment-6334226943).
* Record of the questions: [comment 6334270939](https://github.com/Maatify/php-rate-limiter/pull/158#issuecomment-6334270939).

| Item | Ruling | Effect |
| --- | --- | --- |
| LT-P2-13 | **(a).** The registered #156 condition stays **FAIL as written**; it is not redefined after the run. A new registered acceptance condition is added (text below, §12.1). The one-second resolution is documented in FAILURE_SEMANTICS §2.5 under the fleet duty. Millisecond deadlines are out of scope (a state-shape change) | §12.1; documents |
| LT-P2-14 (F-P2-03) | **Fixed in this PR.** First, a red test on real Redis: at the logical deadline the rotation path returns `[1, 1]`, as the plain path does. Then the fix, in the rotation script only: for the current window and its metadata, the same `expires ≤ now ⇒ DEL` before counting; the previous generation stays read-only. The fix is a declared, dated edit. Afterwards, on the new head: the full suites in both modes, PHPStan, CVH, gate §4.5 and #154 E-SAME. The #156 probes and the two-clock arm are rerun only for a cell that a grep shows exercises secret rotation | §12.2 |
| LT-P2-15 (new) | The P-TAIL / P-HT `HARD_BLOCK` cause is to be extracted from the logs (the `cause` chain and the rule that issued the block). It is a descriptive addition to `report.php`, with no verdict change, written into RESULTS §3. Expected reading (Lead): the probe accounts' own failures accumulating on the Login policy; to be confirmed or refuted by the log | §12.3 (LT-P2-17) |

Also accepted as declared: F-P2-02, and the omission of gate §4.5 with its addition before its run.

### 12.1 The new registered condition (LT-P2-13), and a contradiction found before registering it (LT-P2-16)

**The ruled text:** «after one time authority, every remaining twin difference is explained by whole-second quantization of each twin's own clock: 0 unexplained, |Δ| of the end time ≤ 1 s, 0 exceptions, **0 `retryAfter` difference**».

**Measured on the current outputs (`acceptance/expected/`), before registering:**
* 19,500 probe steps where both twins have a probe row. Of those with an equal primary decision, `retryAfter` differs in 1,373.
* **713 steps differ by exactly 1 s.** No step differs by 2–⌈|σ|⌉ s. These occur in nearly every family, including P-I, P-RA1, P-RA2 and P-SAT, which have 0 decision changes.
  * The remaining wait is rounded up to whole seconds on two second grids, so two twins of the same state can be told waits 1 s apart.
* **660 steps differ by more, up to 3,299 s.** All are in CYCLE-NA (P-E2, P-E3) and in E-DOCKER P-E2 CYCLE-NA.
  * In each, one twin has passed its end and has been handed to the cycle pause, while the other is still inside the block.
  * By the end-time prediction of RESULTS §4: 633 predicted, 27 within ± 2 ms of an end, **0 unexplained**.
* **So the clause "0 `retryAfter` difference" fails on the current numbers:** 20 CYCLE-NA cells on E-SAME and 2 on E-DOCKER through the second class, and most cells through the first. That contradicts the ruling's premise ("this condition passes on the current numbers").

**LT-P2-16. Options:**
* **(a) Recommendation.** Replace the clause with "0 unexplained `retryAfter` difference". Every `retryAfter` difference between twins with an equal decision is either:
  * exactly 1 s (the remaining wait rounded on two second grids); or
  * a quantization-predicted (or ± 2 ms ambiguous) handover in which one twin has passed its end.

  The other clauses stay as ruled.
* (b) Keep the clause literal. The new condition then also fails, in the cells above.

Nothing is registered or implemented for §12.1 before this ruling.

### 12.2 Grep for secret rotation (LT-P2-14, which cells are rerun)

* **The watch-rotation path is reached only with a distinct previous key.** `EvaluationPipeline::incrementWatchAcrossRotation` (L1434–L1448) falls back to the plain path when `$previousKey === null || $previousKey === $currentKey`. A previous key exists only when `RateLimiterConfig` has a previous secret.
* **#156:** every twin is built with `P1\baseConfig()` (`investigations/clock-mixes-153/harness/mixlib.php` L388), i.e. `new RateLimiterConfig('k153-active', 'f153-active', 'prod')` with no previous secret (`clock-skew-153/harness/lib.php` L404–L407). `rotatedConfig` is not referenced in `clock-mixes-153/harness/`.
* **Two-clock arm:** `new RateLimiterConfig('two-clock-key', 'two-clock-fp', 'prod')` (`phase2/acceptance/two_clock.php` L68), with no previous secret.
* **Conclusion:** no #156 probe cell and no two-clock cell exercises the rotation path, so neither is rerun. #154 has rotation cells (`ROT-F`, `ROT-R`, `ROT-P` use `rotatedConfig()`, its `run.php` L45–L48) and is rerun on E-SAME as ruled.

### 12.3 LT-P2-15: the logs do not carry the cause (LT-P2-17)

**What the archived records hold.** The archived `tail.php` writes, per target:
* the decision string (`o`);
* the exception (`x`);
* script errors (`err`);
* the snapshot calls (`s`).

`Lab::request` (`lib.php` L296–L310) keeps only `$result->decision`. Neither `blockLevel`, `retryAfter` nor `metadata` (`signal`, `cause`) is written, and the Redis command log is not kept per target. The cause therefore cannot be extracted from the existing logs.

**What the records do show** (E-SAME, `tail.jsonl.gz`):
* Each probe is a `checkOnly`. No failure is recorded for these accounts.
* In each burst of targets due within milliseconds of each other, the first targets are `ALLOW` and the following ones `HARD_BLOCK`. Example: P-TAIL a = 2, accounts T2.0-0…3 `ALLOW`, then T2.0-4… `HARD_BLOCK`.
* All 80 accounts come from one /24 of the benchmark range.

That pattern is consistent with a per-source admission limit on `checkOnly` (DEC-024 / DEC-026, capacity 4). It is not consistent with failure accumulation, since there are no failures. Neither reading is measured.

**LT-P2-17. Options:**
* **(a) Recommendation.** A descriptive instrument, `phase2/acceptance/tail_cause.php`, that `require`s the archived `lib.php` unchanged.
  * It replays `tail.php`'s targets: same accounts, contexts, schedule and clock arms, in a fresh namespace.
  * It records each result's `decision`, `blockLevel`, `retryAfter` and `metadata` (`signal`, `cause`), plus the Redis script names of the call.
  * It runs on E-SAME after the #154 rerun, on the same head. It is reported in RESULTS §3, with no verdict change.
* (b) Leave the cause unattributed.

### 12.4 Rulings on LT-P2-16 and LT-P2-17, and the registered condition (2026-10-22, recorded before its implementation)

Source: [PR #158 comment 6337114762](https://github.com/Maatify/php-rate-limiter/pull/158#issuecomment-6337114762) (Lead). The comment also corrects the Lead's own LT-P2-13 wording: "0 `retryAfter` difference" was written without reading the `retryAfter` column. The rule is the same for everyone: no condition is registered before it has been checked against the data.

| Item | Ruling |
| --- | --- |
| LT-P2-16 | "0 unexplained `retryAfter` difference" replaces the literal clause. The two explained classes are named in the condition with their bounds (below). Any difference outside both is unexplained, and the cell fails. The originally registered #156 condition stays **FAIL as written**; this one is registered with its date |
| FAILURE_SEMANTICS §2.5 | Sentence, verbatim from the ruling: «المواعيد المخزَّنة بدقة ثانية كاملة. مضيفان يختلفان بكسر ثانية قد يختلفان بقرار واحد عند حدّ موعد، وبـ `retryAfter` بثانية واحدة؛ وعند تسليم كتلة إلى إيقاف دورة قد يختلف `retryAfter` المُبلَّغ بطول الإيقاف. الحد الأقصى لفرق زمن النهاية ثانية واحدة.» It is written in the document's language (English) with that content, under the fleet duty |
| LT-P2-17 | **Accepted.** A descriptive rerun script, `tail_cause.php`, on the same head, records each P-TAIL / P-HT target's full result: decision, `cause`, level, `retryAfter` and scope. No verdict changes. The Lead's expected reading, to be confirmed or refuted by the measurement: the probes run on `login_protection`, the preset and not the opt-in variant, so the admission source capacity does not apply. "4 `ALLOW` then `HARD_BLOCK` from one /24" matches S0's collective source rules (K1 credential spray / distributed account), because the probe accounts share one source. It is not a time effect. If confirmed, RESULTS §3 notes that the probe design (many accounts from one /24) triggers S0 rules, which does not touch the gate condition (exceptions only) |

**Registered condition C-156-Q (LT-P2-13 as amended by LT-P2-16), evaluated by `acceptance/report.php` over each registered #156 probe cell. These are the cells of `report.php`'s existing table, E-SAME and E-DOCKER, with no rerun (§12.2).** A cell passes when all of the following hold:
1. **0 exceptions** in either twin, on any row.
2. **0 unexplained primary changes.** A probe step whose primary decision (decision | re-entry | exception) differs between the twins counts as explained only in P-B or P-E (P-E2, P-E3). There, the end-time rule of RESULTS §4 must predict it, or an end must lie within ± 2 ms of a twin's request span (ambiguous):
   * the ends are `uS + 1200 − σ` vs `u0 + 1200` for P-B, and `E_S − σ` vs `E_Z` for P-E;
   * the prediction is that a twin refuses while its request starts before its own end.

   In every other family (P-RA1, P-RA2, P-I, P-SAT), any primary change is unexplained. For P-I this includes every paired step, as in the original condition.
3. **|Δ end| ≤ 1 s** on every P-B / P-E probe step that has both ends.
4. **0 unexplained `retryAfter` differences.** A probe step with equal primary decision and different `retryAfter` is explained only as:
   * **(i)** |`retryAfter`_S − `retryAfter`_Z| = **exactly 1 s**: the remaining time rounded up on two second grids (713 steps on the outputs at `8a8082a`); or
   * **(ii)** a **CYCLE-NA** (P-E2, P-E3) step where the L3 block hands over to the cycle pause in one twin. This is identified as a step that the end-time rule predicts or that is ambiguous (± 2 ms). Its `retryAfter` difference is then bounded by the pause length (660 steps at `8a8082a`: 633 predicted, 27 ambiguous).

   Any other `retryAfter` difference is unexplained and fails the cell. `lv` differences are reported, not gated.
