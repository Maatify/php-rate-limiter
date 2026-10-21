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
