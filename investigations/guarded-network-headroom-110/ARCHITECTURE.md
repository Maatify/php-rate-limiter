# #110 Guarded Network Headroom — architecture contract and feasibility

**Status: STUDY / EVIDENCE ONLY.** This file defines the one design measured in this directory, its invariants and limits, and the
feasibility verdict. It is not a policy, not DEC-020 and not a Runtime change. DEC-019 stays ACTIVE; #97 / #100 / #106 / #110 stay open.
Every code reference is to the baseline `main@1ad9af289f83f86346667a014dd486f9c4b8fc08` (`src/Service/EvaluationPipeline.php`
sha-256 `4b259d33…f4fc`). The measured results are in [`REPORT.md`](REPORT.md).

Starting point: the Lead review in [#110 comment 6151764966](https://github.com/Maatify/php-rate-limiter/issues/110#issuecomment-6151764966)
(×5 is a comparison hypothesis, not a gate; the guard must be a real pre-verification budget, atomic under concurrency, and must not rely on
`increment()` being atomic across K1 + K2).

## 1. What S0 does today (the facts the design must respect)

| Rule | Anchor (scope) | Members | Cap | N−1 WATCH | Set TTL / WATCH TTL | Observed on | Persists |
|---|---|---|---|---|---|---|---|
| K1 credential spray (`observeCredentialSpray`, line 1478) | `credential_spray:` + K1 (IP prefix) | HMAC of `correlationId ?? accountId` | 5 | at count 4, 2nd increment | 600 s fixed / 1800 s fixed | **checkOnly only** (line 302); policies with `CREDENTIAL_SPRAY` | K1 L2 (`PenaltyLadder` L2 = 60 s), advisory for trusted sessions (line 469) |
| K2 churn (`observeChurn`, line 1278) | K2 (IP prefix + normalised UA) | fingerprint hash | 3 | at count 2, 2nd increment | 600 s / 1800 s | **every command** (`checkCorrelationRules`, line 1111) when a fingerprint hash exists | K2 L2 / 60 s |
| LOW dilution (line 1191) | fingerprint hash | K1 | 6 | at count 5, 2nd increment | 600 s / 1800 s | every command, not ephemeral | LOW → K2 L2 / 60 s; otherwise a two-window confirmation → K3 |
| IPv6 macro scopes (`resolveAdaptiveIpv6Scopes`, line 846) | /48, /40, /32 hierarchy (activation caps 2 / 4 / 8) | — | spray / churn run their own rule on every active macro scope | same | same | same as the rule | the canonical K1 / K2 only |

Store contracts used by these rules (no change needed or made):

* `CorrelationStoreInterface` (lines 10–14): the TTL is set atomically with the **first** mutation and is never refreshed; `incrementWatchFlag()`
  is one atomic fixed-TTL counter; a malformed result or lost atomicity must throw.
* `CorrelationRotationStoreInterface` (line 10): every operation atomic; `incrementWatchFlagAcrossRotation()` increments the current key only and
  returns current + read-only previous. The official Redis adapter does it in one Lua script (`RedisFullCapabilityStore.php` line 1392).
* `BoundedCorrelationStoreInterface`: bounded add = one atomic operation; a **set already above the requested cap is malformed**:
  `BoundedCorrelationResultValidator.php` line 23, Redis Lua line 776 (`malformed bounded state`), reference store line 58.
* `RateLimitStoreInterface::increment()` (line 17) is atomic **for one key only**. Nothing in the package offers an atomic operation over two
  keys of different scopes, and this design does not pretend otherwise.
* `RateLimitCommand` (lines 27–33) carries policy, cost and one of `isPreCheck / isFailure / isSuccess` — **no logical-attempt identity**: a
  `checkOnly` and the `recordFailure` of the same Host attempt cannot be paired by the library.
* `RateLimiterEngine::limit()` converts only `BackendFailureException` (line 289) to the policy failure mode; malformed state and unknown
  throwables propagate unchanged (line 233).

## 2. Three different things called "network headroom" (task §4.A)

1. **Raising the correlation cardinality thresholds** (5→25, 3→15, 6→30). This changes how many *distinct identities* a scope may show inside
   one 600 s window before the rule fires. It is **not** a number of requests: inside the window a scope can repeat known identities without
   limit, and the N−1 WATCH moves with the cap (count 24 / 14 / 29). This is arm **TH5**.
2. **Raising the number of requests that reach verification before the check fires.** Nothing in S0 counts requests per network scope for
   correlation: the reach of an attacker is a *consequence* of identities, WATCH, block duration (60 s) and the stale 600 s set (#100 renewal).
   A ×5 identity cap does not imply ×5 reach (measured: TH5 TMSd 2→200, XN1n `pre` 10→600).
3. **Raising a network budget while keeping account and device limits independent.** K4 / K5 / budget / anti-equilibrium / §5.2 distributed
   account / flood are untouched in both candidate arms; only the three rules of §1 change. This is what arm **GNH5** guards.

Effect of the ×5 thresholds on the S0 machinery (all measured in this study):

* **N−1 WATCH**: moves to 24 / 14 / 29; an attacker cycling ≤ 23 subjects / ≤ 13 fingerprints never reaches it (XN1, XN1n, XN1k).
* **Cardinality caps / bounded memory**: sets grow to 25 / 15 / 30 members (G04 S2: max 25 observed; ≤ 30 asserted).
* **Fixed TTL**: unchanged (600 s sets, 1800 s WATCH; the budget 600 s); nothing is refreshed.
* **Block renewal (#100)**: unchanged mechanism — a live set re-issues after each 60 s block (G02 B6, both S0 and GNH5).
* **IPv6 macro scopes**: each macro spray / churn observation is its own rule scope (own x5 set, own budget on GNH5).
* **Rotation**: Current/Previous reads are unchanged; a bounded set written above the S0 cap is **malformed for the S0 code** (rollback hazard).

## 3. The one design: Guarded Network Headroom (GNH5)

For each of the three rules, on every evaluation where S0 evaluates it (same commands, same scopes, same macro scopes):

```text
s0  = S0 rule on the S0 keys           (cap c, WATCH at c−1)         -- same keys and rule as S0 (the STATE diverges: see Rollback below)
x5  = same rule on "gnh5:x5:" keys      (cap 5c, WATCH at 5c−1)       -- separate namespace, never visible to the S0 code
if x5:            trigger                                             -- the x5 rule as in TH5
elif not s0:      no trigger                                          -- S0 would allow: allow
else:             n = incrementWatchAcrossRotation("gnh5:budget:<rule>:<scope>", previous scope key, 600)
                  trigger iff n > B[rule]                             -- headroom admission iff n <= B
```

A trigger produces exactly the S0 candidate (same level, duration and persistence key). Experimental budgets B: spray 20, churn 12, dilution 24
evaluated commands per scope per fixed 600 s window (= x5 cap − S0 cap). The patch (`patches/gnh-candidates.patch`, one file, +83 / −30 lines)
replaces the bodies of `observeChurn` / `observeCredentialSpray` and the dilution threshold block by `gnhGuardedRule()` and adds two private
helpers; TH5 is the same patch with `GNH_ARM=TH5` (x5 rule on the S0 keys, no budget).

### Answers to task §4.B

| # | Question | Answer in this design | Evidence |
|---|---|---|---|
| 1 | Scope of the budget | One budget per **rule scope** (K1 spray scope, K2 churn scope, LOW dilution scope, and each IPv6 macro scope of a rule). K1 and K2 budgets are independent. | G02, G03 H, G04 S1 |
| 2 | Unit | One **evaluated command** in the zone (S0 rule fires, x5 does not). Not a request, not a logical attempt. | G02 B4 |
| 3 | One logical attempt without double counting | **Impossible with the current contracts**: `RateLimitCommand` has no attempt identity. Churn / dilution run on both the `checkOnly` and the outcome command, so `pre+out` spends 2 per attempt (fail-closed: halves the benefit, never raises the bound). Spray runs at `checkOnly` only, so it spends 1. | G02 B4: `pre+out` 7 attempts vs record-only 14 for B=12; matrix N12 `pre+out` 32/61 vs `out` 7/61 |
| 4 | Window | Fixed 600 s from the first headroom admission (WATCH-flag TTL semantics, never refreshed). ≤ B per fixed window; ≤ 2B in any sliding 600 s (boundary burst). The S0 N−1 WATCH memory is 1800 s, **longer than the budget window** (see §5). | G02 B2: 20 + 20, sliding max 39 |
| 5 | Concurrent Redis workers | The budget is **one key, one atomic Lua script** (`incrementWatchFlagAcrossRotation`): every worker gets a distinct value; admissions are exactly the values ≤ B. A worker refused by a block persisted in the burst consumes nothing. | G06 C1 6 of 16 with 6 left, C2 4 of 8 with 4 left (3/3 rounds each) |
| 6 | `checkOnly` vs `recordFailure` / `recordSuccess` | Every command that evaluates the rule spends; `checkOnly` is the only command that can stop a verification. | G02 B3 / B4 |
| 7 | Record-only Hosts | The budget bounds the **ALLOW decisions returned** to record-only calls (≤ B per window), not verifications: in record-only every attempt is verified before the library sees it (S0 too). Spray is never observed record-only (S0 property). | matrix `out` rows; G02 B4 `out` 14/41 |
| 8 | Redis failure | A failing budget operation throws `BackendFailureException` (no silent zero). Through the REAL engine (measured): `FAIL_CLOSED` L2/600 at t=4 and t=5; once the circuit breaker opens, the **DEC-015 degraded path ALLOWS t=6..8** (3 requests the budget never admitted) and its local fallback limit denies t=9..11. **The B-bound is a normal-mode property only**: during `DEGRADED_MODE` the budget is not consulted, and the outage bound is the existing DEC-015 fallback, not GNH5. A global / parallel B-bound during an outage is UNPROVEN. | G04 F1 |
| 9 | Current / Previous rotation | Budget = current + read-only previous (`incrementWatchFlagAcrossRotation`); the previous generation is never written. Spent budget survives rotation. | G03 R3: 10 before, exactly 10 after |
| 10 | New Store capability / public contract | **None.** Only `addBoundedCorrelation()` and `incrementWatchAcrossRotation()` (existing private helpers over existing capabilities); no public / protected method, no interface, no DTO, no Builder change. | G4g static check on the committed patch |
| 11 | One attacker inside the NAT exhausting the budget | After exhaustion the scope is decided exactly like S0 (the S0 rule fires, the S0 candidate is issued). A GNH5 trigger implies the S0 rule fired on the same request **as evaluated on GNH5's own (diverged) state**; measured, no cell is denied more than on S0 (G3a), but that is a measured result over 165 cells, not a proof. It **can** take the whole benefit away from everyone behind that NAT. | G02 B5 (colleague 15/15 denied on S0 and GNH5, 0 on TH5); matrix BURN; G3a 0 regressions in 165 cells |

**Cross-key reservation.** A request in the zone of both K1 and K2 spends both budgets, without a reservation spanning the two keys. Because
there is no refund, a request refused by one budget may still spend the other (measured: G06 C4, at least one K1 slot spent by a request K2
refused, every round). The over-spend is fail-closed: admissions stay ≤ the smaller remaining budget (C4: exactly 5 of 8 with K2 5 left).
An exact two-key reservation would need a new atomic multi-key Store capability — a contract change, not built here.

**Rollback / mixed version (corrected after Lead review F115-01).** GNH5 keeps its larger sets in a separate namespace and never writes an
S0 set above the S0 cap, so the REAL S0 code runs on GNH5 state **without an exception** (G04 R1/R2, G05 P3/P4). That is all R1/R2 prove.
GNH5 does **not** write the S0 keys "exactly as S0 would": a request the S0 rule would stop and the budget admits continues through the rest of
`checkCorrelationRules()` and later requests are not refused by a persisted block, so they keep observing. Measured (G04 R3, same history,
key for key, `gnh5:` keys excluded): the S0-family keys differ after every tested history (probe head 3 extra keys + 3 differing values; ABA_A /
D2 / XN1 first 300 s: 10 / 14 / 18 extra keys and 2 / 3 / 9 differing values; XN1 also 4 S0 keys absent on GNH5). The churn → dilution path
is confirmed: device 3, stopped by S0's churn rule at t=2, never reaches the dilution observation on S0 (0 members) but does on GNH5 (1
member). Decision consequence of that divergence (G04 R4, REAL S0 code continuing after the GNH5 history vs after an S0-only history): probe
tail 0 differing decisions, ABA_A 0, D2 0, XN1 **1** of 200; mixed version vs S0-only: SP1n reach 4 → 5, D2 legit denied 29 → 23. Rollback
**semantic** parity therefore does not hold; it is bounded only by these measured cells, and any other history is UNPROVEN. **TH5** (the in-place ×5) writes S0 sets above the S0 cap: the S0 code then
throws `RateLimiterException` (`bounded distinct state exceeds its configured capacity` on the reference store, `malformed bounded state` on
Redis), which the engine does not convert (line 233) — for up to 600 s after a rollback or on every S0 node of a mixed fleet.

## 4. Invariants (what the design claims, each one measured)

| ID | Invariant | Measured by |
|---|---|---|
| I1 | GNH5 never triggers where the S0 rule (on GNH5's own state) does not fire on the same request | G3a: 0 of 165 legitimate cells more denied than S0 (both candidate arms) |
| I2 | ≤ B headroom admissions per rule scope per fixed 600 s window, including under simultaneous requests | G02 B1/B2/B3, G06 C1/C2/C4 |
| I3 | Spent budget survives Current/Previous rotation | G03 R3 |
| I4 | While the backend is healthy, the budget is consulted on every in-zone evaluation; a failing budget operation is typed (never a silent zero) and the engine decides by the policy failure mode — `FAIL_CLOSED`, then the DEC-015 degraded path, which ALLOWS without the budget (measured 3 ALLOWs) | G04 F1 |
| I5 | The S0 code can read GNH5 state without an exception (rollback / mixed version). **Not** state or decision parity: S0-family keys diverge (R3) and decisions may differ (R4: XN1 1/200, mixed D2 29→23) | G04 R1/R2/R3/R4, G05 P3/P4 |
| I6 | Every key GNH5 adds is fixed-TTL 600 s or 1800 s; at most one x5 set per S0 rule set and one budget flag per in-zone rule scope; no per-request key | G04 S1/S2, G05 P2 |
| I7 | No public contract, interface or Store capability change | G4g |

## 5. Limits of the design (not fixed here; they are why the security trade-off is what it is)

* **Per-scope, not per-attacker.** The bound is B per scope per window. An attacker who controls more scopes (more IPs, more UAs, more /64s of
  a /48 up to the macro activation) gets B per scope: SP4 (8 IPs) 78 → 294 (`pre+out`) / 510 (`pre`); Y1 (4-IP pool) 8 → 32 / 56.
* **The budget window is shorter than the S0 N−1 memory.** S0 remembers an N−1 hover for 1800 s (WATCH TTL); the budget forgets after 600 s.
  Low-and-slow hovers that S0 catches only through WATCH get a fresh budget every window: SP2 60 → 72/72, SP4u 480 → 576/576.
* **Commands, not attempts** (task §4.B.3): `pre+out` Hosts get half the fairness benefit of `pre`-only or record-only Hosts on churn /
  dilution.
* **The budget is shared by everyone behind the scope.** One party (attacker or busy neighbour) can spend it; then everyone is back to S0.
* **×5 identity headroom is the same for API_OVERUSE**: the rules are shared by every policy; the patch does not separate API from Login/OTP
  (the Lead asked for separate rules — not designed here).

## 6. Feasibility verdict (task §4.C)

**`FEASIBLE_WITH_EXISTING_CONTRACTS`** — for the guard exactly as specified in §3: a per-rule-scope, fixed-window, pre-verification budget
whose admission bound is atomic under concurrency and rotation-aware **while the backend is healthy**, and whose failure is typed, built only from
existing Store capabilities (I2–I7 measured). It does not retain the B-bound during an outage (DEC-015 degraded path decides; I4) and it does
not preserve S0 correlation state or decisions after rollback (I5).

This verdict is about **buildability of the guard**, not acceptance of the candidate. Two properties the task asks about are **not**
achievable within the current contracts and are recorded as contract blockers, not worked around:

1. counting one *logical attempt* across `checkOnly` + outcome (needs an attempt identity in the command or a Host signal);
2. one *atomic reservation across K1 and K2* (needs a new multi-key Store capability; today: two independent counters, fail-closed over-spend).

A third limit — a per-attacker (cross-scope) bound — would need a scope above K1/K2 that the package does not model; not designed here.
Whether the measured trade-off (REPORT §2–§3) is acceptable is an Owner decision under Decision Governance (REPORT §6).
