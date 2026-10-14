# #110 Outcome-Aware Atomic Admission (OAAA) — architecture contract

**Status: STUDY / EVIDENCE ONLY.** This file is the Stage A contract of the one design measured in this directory. It is not a policy, not
DEC-020 and not a Runtime change. DEC-019 stays ACTIVE; #97 / #100 / #106 / #110 stay open. Every code reference is to the baseline
`main@72f6dea8ab0729e63492b622e682e334c527c966` (`src/Service/EvaluationPipeline.php` sha-256 `4b259d33…f4fc`). The feasibility verdict is in
[`CONTRACT_FEASIBILITY.md`](CONTRACT_FEASIBILITY.md), the pre-registered numeric envelope in [`SECURITY_ENVELOPE.md`](SECURITY_ENVELOPE.md),
the measured results in [`REPORT.md`](REPORT.md).

Mandate: Owner direction in [#110 comment 6156215783](https://github.com/Maatify/php-rate-limiter/issues/110#issuecomment-6156215783)
(a limited, explicit, measured security margin versus S0 is acceptable **in principle** for an isolated candidate evaluation; no amount,
candidate or Runtime change is approved).

## 0. What is new here, and what is not

OAAA is not OQC, not GNH5, not the trust family and not a threshold change. The rejected designs and the architectural difference:

| Rejected design (evidence) | Why it failed | What OAAA does instead |
|---|---|---|
| OQC / OQCE (#111) | moved the decision to `recordFailure`, i.e. **after** verification; one concurrent burst admitted 16/16 | every extra admission is a **pre-verification** reservation, taken atomically before the Host verifies |
| GNH5 (#115) | one counter per rule scope; refusal of K2 could still spend K1 (no cross-key reservation); counted *commands*, so `pre+out` spent 2; budget forgot after 600 s while S0 WATCH remembers 1800 s (SP2 60→72/72) | **one atomic all-or-nothing reservation over every fired scope + a day tier + global tiers**; a reservation is resolved by the Host outcome (spent on failure, returned on success), so a successful attempt costs nothing and a pure attacker's attempt is never returned |
| TH5 / EQL / EQV / PSC | raised cardinality thresholds: an attacker gets the whole identity headroom with no request bound | the S0 rules, caps, WATCH and keys are **unchanged**; OAAA only decides, per request where S0 would issue a correlation block, whether an atomic unit can be taken |
| Trust family 2 / 3 / 2+3 (#113) | a trusted label exempted K2 for every trusted request, losing the only cross-account IP+UA control | no exemption from labels: trusted sessions get exactly the same reservation rule; K1 trusted-advisory is left as S0 |

The one new ingredient is **Host-reported outcome evidence used as credit, never as a label**: a scope may lend headroom only after Host-verified
successes of distinct accounts were recorded in it, and only up to a small fixed cap. A scope that has never seen a verified success (a
pure attacker) gets **zero** headroom — exactly S0.

## 1. Facts of S0 the design must respect

| Fact | Where (baseline) |
|---|---|
| `RateLimitCommand` carries `policyName`, `cost` and one of `isPreCheck` / `isFailure` / `isSuccess`. **No attempt identity, no reservation identity.** | `src/Command/RateLimitCommand.php` lines 27–33, 56, 67, 78 |
| K1 credential spray (`credential_spray:` + K1, cap 5, WATCH at 4) is observed on **`checkOnly` only** | `EvaluationPipeline.php` line 302, 1422, 1478 |
| K2 churn (cap 3, WATCH at 2) and LOW dilution (anchor = device hash, members = K1, cap 6) run on **every** command; churn returns early, so a churn-blocked request is never dilution-observed | line 1111–1276 |
| A firing rule persists a 60 s L2 block on K1 (spray), K2 (churn, LOW dilution) or K3 (non-LOW dilution); persisted blocks are checked first and return early | lines 454, 2032 |
| Trusted sessions: K1 spray is **advisory** (`trusted_advisory:credential_spray`, persisted but not enforced on the trusted request); K2 and every other key are enforced | lines 454 (K1 skip), 1459 |
| `RateLimitStoreInterface::increment()` is atomic for **one key**; `CorrelationStoreInterface` offers bounded add and a single WATCH counter; **no interface offers a decrement, a refund, or an atomic operation over several keys** | `RateLimitStoreInterface.php` line 26, `CorrelationStoreInterface.php` line 36, `CorrelationRotationStoreInterface.php` line 40 |
| The official Redis adapter already runs multi-key Lua scripts (rotation snapshots, hard-block cycles); it is documented as **non-clustered** | `RedisFullCapabilityStore.php` lines 1388, 1521; README "official optional non-clustered Redis implementation" |
| `RateLimiterEngine::limit()` converts only `BackendFailureException`: FAIL_CLOSED first, then (DEC-015) `DEGRADED_MODE` **ALLOW** with the bounded local fallback; malformed state propagates | `RateLimiterEngine.php` lines 233, 289–330 |
| Login and OTP presets declare `CREDENTIAL_SPRAY`, `DISTRIBUTED_ACCOUNT`, `TRUSTED_AUTHENTICATION`; `API_OVERUSE` is a separate, exclusive capability | `LoginProtectionPolicy.php` 36–38, `OtpProtectionPolicy.php` 36–38, engine registration |

## 2. The design

### 2.1 Where OAAA acts

OAAA is active on a command only when **all** hold (otherwise the command is S0, byte for byte):

1. the experimental opt-in is on (study: env `OAAA_ARM=OAAA` in the isolated tree; proposed contract: a policy capability, §6);
2. the policy is an authentication policy (`CREDENTIAL_SPRAY` or `TRUSTED_AUTHENTICATION`) and **not** `API_OVERUSE`;
3. the correlation store implements the experimental `OutcomeAdmissionStoreInterface`;
4. the context has an account (K4) — account-less requests are S0.

It touches **only** the candidates whose source is `correlation` (K2 churn, LOW / non-LOW dilution) or `credential_spray` (K1, untrusted).
K4 / K5 scores, the account budget, anti-equilibrium, §5.2 distributed account, new-device flood, persisted blocks, DEC-003 cycles, trusted
advisory spray, decay, post-punishment re-entry are executed exactly as in S0.

### 2.2 Objects

| Object | Key (logical) | Content | Lifetime |
|---|---|---|---|
| **scope epoch tier** | `oaaa:v1:e:<rule>:<observation key>` (one per fired rule scope: canonical K1 spray, canonical K2 churn, dilution anchor, every IPv6 macro spray / churn scope) | `e` epoch id, `u` units in use | fixed 600 s from creation, never refreshed |
| **scope day tier** | `oaaa:v1:d:<rule>:<observation key>` | `e`, `u` | fixed 86 400 s |
| **global tiers** | `oaaa:v1:g:e:<sha256(policy:env)>`, `oaaa:v1:g:d:…` (secret-independent: rotation cannot reset them) | `e`, `u` | 600 s / 86 400 s |
| **credit set** | `oaaa:v1:c:<rule>:<observation key>:<floor(now/600)>` | HMAC of the K4 of accounts with a **Host-reported success** in that scope and window, at most 2 members | fixed 1 200 s (covers the current and the next window) |
| **tuple** | HMAC(`policy`, `env`, K4, device hash, K1, K2) under the active secret | per tier: `p` unresolved reservations, `f` recorded failures, `e` the tier epoch they were taken in | fixed 600 s |

Units: a **unit** is one possible verification the S0 rule would have stopped. `u` counts units taken and not returned.

### 2.3 One attempt (task A1)

```text
1. checkOnly arrives                        S0 pipeline runs unchanged up to and including the correlation rules
2. individual + shared protection           S0 candidates are computed (K4/K5/§5.2/flood/... untouched)
3. headroom?                                 only if a non-advisory correlation candidate fired; fired scopes F = {spray/churn/dilution scopes that fired}
4. atomic reservation                       reserveAdmission(tuple, tiers(F) + day tiers(F) + global tiers):
                                               for every tier: u + u(previous generation) < effective cap
                                               effective cap = 2 for an epoch tier, but never more than |credits(current ∪ previous window)|
                                                             = 6 for a day tier, 12 / 48 for the global tiers
                                               all tiers admit → take one unit on EVERY tier and record p+1 in the tuple; else take nothing
5. decision                                 granted → the correlation candidates are removed (no block persisted); refused → S0 candidates, S0 block
                                            granted but another protection still denies → the reservation is cancelled at once (no verification happens)
6. Host verifies                            (outside the library)
7. recordSuccess / recordFailure            same context → same tuple; completeAdmission(tuple, outcome) resolves ONE unresolved reservation per tier:
                                               failure: p−1, f+1 (the unit stays spent)
                                               success: p−1 and the unit is returned; plus up to 2 recorded failures of the SAME tuple are returned
                                                        (MEDIUM / HIGH devices only); the account joins the credit sets of the request's scopes
8. finalize / expire                        an unresolved reservation is never returned: it stays spent until its tier epoch expires (600 s / 1 day)
```

On the outcome command the S0 rules run again (churn / dilution are observed on every command). If one fires: a **success** never issues a
correlation block (it cannot be an attack attempt and the unit was already returned); a **failure** whose reservation was resolved in step 7 is
already paid; an **unpaired** failure (record-only Host, or the rule fired only now) must take a unit like a pre-check (reserve, then resolve as
failure) or receives the S0 candidate.

### 2.4 Reservation identity — answers to task A1

| Question | Answer |
|---|---|
| Who owns the reservation id? | **The package.** There is no id on the wire. The reservation is a counter inside a package-derived tuple: HMAC(policy, env, K4, device hash, K1, K2) under the active key secret. The Host never sees, sends or chooses it. |
| Package-generated or Host-provided? | Package-generated from the same context the Host already passes; **no `RateLimitCommand` change** and no new Host call for `pre+out` Hosts. |
| Can it be forged or reused? | The tuple is the request context itself. A client that presents *the same* account + device + IP + UA as a legitimate user **is** that tuple (the identical-tuple case, §5 and TGTM / TGTL); presenting it gives no unit by itself — units come only from the shared, credit-gated tiers. Reuse = a second outcome; see "outcome twice". |
| Single-use? | **Per tuple, not per attempt** (corrected after F116-01). Each outcome resolves at most one unresolved counter `p` of its tuple, but several concurrent attempts of one tuple are **fungible**: a replayed or misattributed success can resolve *another* pending attempt of the same tuple (`a02` L24: 0 units left instead of 1). Attempt-level single use needs an attempt-bound identity, i.e. a Host-visible token or equivalent: **CONTRACT_CHANGE_REQUIRED** (CONTRACT_FEASIBILITY.md §2a). |
| How is it bound to policy, scope and attempt? | policy and env are in the tuple and tier keys; the scopes are the tier keys (the tuple records a counter **per tier key and per tier epoch**); the attempt is one `p` unit. |
| Outcome arrives twice | A duplicate failure returns nothing. A duplicate success returns **another pending reservation of the same tuple, if any** (`a02` L24), and **up to 2 recorded failures of the same tuple even without a new successful attempt** (`a02` L25, L7) — measured defects of the tuple design (gate X1 FAIL), bounded to units already taken by that tuple, never units of another tuple. |
| Outcome never arrives | The unit stays spent (`p` is never returned). Pre-only Hosts therefore never return units; they also never earn credit, so for them OAAA ≡ S0. |
| Process dies after the reservation | Same as a missing outcome: spent until the tier epoch ends. No cleanup job; no unbounded history. |
| Can units be returned? | Only by a Host-reported **success** of the same tuple (a genuine one or a Host replay of one — F116-01), only to the same tier **epoch** (an expired / recreated tier is never credited), only for current-generation tiers (rotation never writes a previous generation). |
| Refund exploitation | A refund needs a Host-verified success of the **same account** from the same device, IP and UA. An attacker can only produce it for an account it owns (own attempts are harmless) or has already compromised. Cross-account refund is impossible: the account is part of the tuple. |
| Attacker succeeds on an account it owns | Its own tuple's unit is returned (net zero) and that account enters the credit sets of the attacker's scopes: at most **2 distinct accounts per scope per window** count, so own accounts can raise a scope's epoch cap from 0 to at most 2 — never more (measured: OWN1, OWN2, OWN6, OWN4, OWNK2, D5). |
| Double counting `checkOnly` + outcome | The pre-check takes one unit; the outcome command resolves it instead of taking another. Spray runs only on `checkOnly` (S0); churn / dilution that fire again on the outcome command are covered by the resolution (paired) — a second unit is taken only if a scope fires on the outcome that did not fire on the pre-check (fail-closed over-count, never under-count). |

### 2.5 Atomicity and cross-scope security (task A2)

* **One atomic operation over every tier of the request.** A request firing K1 spray and K2 churn reserves `e:spray:K1`, `e:churn:K2`, both day
  tiers and both global tiers **in one script**: all admit and all take one unit, or none changes. GNH5's over-spend (K1 spent by a request K2
  refused, #115 G06 C4) cannot happen. Measured on real Redis: `a06` C3.
* **One user exhausting a NAT.** Possible, and intended: when the scope's units are used, the scope is decided exactly like S0 (the S0
  candidate and its 60 s block). A NAT neighbour who fails a lot takes the headroom away from everyone behind it (BURN, MIX, TGT*).
* **Several IPs, UAs, IPv6 macro scopes.** Each scope has its own epoch (2) and day (6) tier; the **global** epoch (12 / 600 s) and day (48 /
  86 400 s) tiers are in every reservation, so an attacker holding many scopes is bounded in aggregate, independently of how many scopes it
  controls (OWN4: 8 IPs). The global tiers are per policy and per environment, shared by the whole deployment: a busy deployment exhausts them
  and falls back to S0 everywhere (fail-closed, an Owner sizing decision — REPORT §7).
* **What the existing Store interfaces offer:** no decrement, no multi-key atomic operation, no set cardinality read without insert. A
  reservation that is atomic across scopes, credit-gated and refundable **cannot** be expressed with them (CONTRACT_FEASIBILITY.md §2).
* **New capability needed:** yes — one optional interface, two operations (`reserveAdmission`, `completeAdmission`), implemented in the study
  by the reference store (`lib/oaaa_store.php`) and by two Lua scripts in the isolated copy of the official Redis adapter.
* **Custom stores:** a store without the capability keeps S0 behaviour (no exception, no partial OAAA) — measured (`a02` L19).

### 2.6 Outcome evidence (task A3)

| Threat | Resistance |
|---|---|
| Fabricated successful logins | The library never accepts a success claim from the client; only the Host's `recordSuccess`. A Host-verified success on an attacker-owned account returns only that account's own unit and adds at most one credit member; ≤ 2 members per scope per window. |
| Success on an attacker-owned account | as above (OWN*, D5) |
| Failure / success replay | **Not attempt-level idempotent** (F116-01, gate X1 FAIL): a replayed success can resolve another pending attempt of the same tuple and return up to 2 recorded failures without a new successful attempt (`a02` L24 / L25, Redis `a05` Q7). Bounded to the tuple's own units. |
| Cross-account substitution | the account is part of the tuple: a success on A cannot touch a reservation taken for V (`a02` L6) |
| Missing outcome | unit stays spent (`a02` L3) |
| Delayed outcome | returned only while the tuple (600 s) and the same tier epoch are alive; otherwise stays spent (`a02` L10, L11) |
| Out-of-order outcomes | LOW tuples: the totals are the same in either order (`a02` L9). **MEDIUM / HIGH tuples: order-dependent** — success-then-failure leaves 1 unit, failure-then-success 0 (`a02` L26, Redis `a05` Q7; F116-01). |
| Multiple workers finalizing one attempt | one Lua script per completion; 8 concurrent duplicates of one success with ONE pending reservation resolve it once (`a06` C6). With two or more pending reservations of the tuple a duplicate resolves the next one (F116-01). |
| Result after expiry | as delayed; never credited into a new epoch (epoch id check) |
| False trusted-session labels | the trusted flag plays **no** role in OAAA (same rule for trusted and untrusted); S0 trusted-advisory spray unchanged |
| One success clearing other accounts' history | impossible: a success returns units of its own tuple only; S0 sets, WATCH flags, K4/K5 scores and persisted blocks are never cleared |

### 2.7 Consumer integration (task A4)

* **`pre+out` Hosts (the documented flow):** no new call, no new field. The Host keeps calling `checkOnly`, verifies, then `recordFailure` /
  `recordSuccess` with the **same context**. A context that differs between the two commands is simply not paired (fail-closed: the unit stays
  spent).
* **`pre`-only Hosts:** an isolated pre-only Host (no outcome-reporting traffic on the same scope) earns no credit and stays at S0 (every `pre` cell). **Corrected after F116-03:** a pre-only route that shares a scope with credited `pre+out` traffic is admitted from that credit where S0 denies, and its units stay spent until the epoch ends (`a02` L27: 2 admitted vs 0; Redis `a05` Q8; public API `a07` H3; gate X3 FAIL). OAAA cannot tell which route will report an outcome, and this study does not invent Host-intent detection.
* **record-only (`out`) Hosts:** no pre-verification protection exists in S0 either; OAAA only changes the decision returned to the outcome
  command (successes never get correlation blocks; failures need a unit).
* No Laravel / framework, no JavaScript, no WebAuthn, no external service, no client fingerprint proof; Native PHP through `RateLimiterInterface::limit()`.
* Host probe: `scenarios/a07_host_probe.php` builds the limiter with the public `RateLimiterBuilder` over the (patched copy of the) official
  Redis adapter and runs `checkOnly → verifier → recordSuccess|recordFailure` with a password table standing in for the Host's verifier.

### 2.8 Existing protection (task A5)

| Protection | Under OAAA |
|---|---|
| K4 / K5 scores, account budget, anti-equilibrium | unchanged (runs before and independently of OAAA; can still deny an OAAA-admitted request, which then cancels its reservation). Not assumed to compensate for K1/K2 (#114). |
| Trusted-session rules | unchanged: K1 advisory for trusted stays S0 (OAAA never touches `trusted_advisory:` candidates); K2 for trusted follows the same OAAA rule as untrusted. |
| Device caps (10 / 50), ephemeral routing | unchanged |
| §5.2 distributed account | unchanged (pre-check only, account-scoped) |
| N−1 WATCH | unchanged keys and semantics; OAAA only replaces the *decision* of a WATCH-triggered candidate when a unit is granted. The day tier (6 / scope / day) is the long-horizon bound that the 600 s GNH5 budget lacked. |
| Persisted blocks | unchanged: an active block returns before any OAAA step; OAAA never removes or shortens a block. |
| DEC-003 block cycles | unchanged for every block OAAA lets S0 persist. Fewer blocks are persisted while units are available, so cycle escalation is slower for collateral (intended) and for a co-located attacker while units last (bounded by the units). |
| Current / Previous rotation | tiers read the previous generation's units read-only (`u + u_prev < cap`), credits read both generations, refunds touch only current-generation tiers, the global tiers are secret-independent: rotation cannot reset a bound (`a03`). |
| IPv6 adaptive scopes | every fired macro spray / churn scope is its own tier in the same atomic reservation (`a03`). |
| DEC-015 | an OAAA store failure is a `BackendFailureException` like any other: FAIL_CLOSED, then DEGRADED_MODE ALLOW with the local fallback. **The OAAA bound does not hold during an outage** (it is a healthy-backend property) — measured, UNPROVEN as a bound (`a04` F1/F2). |

## 3. Invariants (each one measured)

| ID | Invariant | Measured by |
|---|---|---|
| I1 | A scope with no Host-verified success of any account in the current or previous 600 s window grants nothing: pure attackers are S0 | matrix: every attack cell without own successes; `a02` L1 |
| I2 | At most 2 units in use per scope epoch, 6 per scope day, 12 per 600 s and 48 per day globally — under any concurrency | `a02` L2/L12/L13, `a06` C1–C5 |
| I3 | A reservation is all-or-nothing across every tier of the request | `a02` L21, `a06` C3 |
| I4 | A unit is returned only by a Host success of the same tuple, into the same epoch, current generation only — **per tuple, not per attempt** (F116-01: replays and reordering are not attempt-idempotent) | `a02` L4–L11, L24–L26, `a03` R2, `a06` C6, `a05` Q7 |
| I5 | **Isolated** `pre`-only histories, account-less, `API_OVERUSE` and capability-less stores are byte-identical to S0; a pre-only route sharing a credited scope is NOT (F116-03) | matrix bit strings, `a02` L17–L19, L27, `a05` Q8, `a07` H3 |
| I6 | Every OAAA key has a fixed TTL ≤ 86 400 s; credit sets ≤ 2 members; tuples ≤ granted reservations | `a04` S1/S2, `a05` T1 |
| I7 | No public contract change other than the one optional interface (study tree only) and the opt-in | static check of the committed patch (`a04` P1) |

## 4. Known limits (designed in; measured, not hidden)

* **Co-located attacker.** An attacker behind a NAT whose colleagues succeed uses the colleagues' credit: up to 2 units per scope per 600 s
  and 6 per day. This is the security price of the fairness gain and applies to S0 = 0 cells (MIX, TPAIRT, TGT*).
* **Own accounts.** Any attacker that can log into 2 accounts it owns from its scope gets the same 2 / 6 units (OWN*).
* **Identical tuples.** A client presenting a colleague's exact account + device + IP + UA is that colleague's tuple; with a stolen MEDIUM
  fingerprint the colleague's next success returns up to 2 of the attacker's failures (TGTM). LOW tuples get no retroactive refund (TGTL).
* **LOW population (#97 D1).** The LOW dilution scope is one device-anchored scope shared by the whole LOW population; its legitimate typo
  volume exceeds any per-scope cap compatible with the envelope, so OAAA cannot remove D1 collateral (expected and measured).
* **Fresh-fingerprint offices (D2).** Every login is a new tuple; typos are never refunded; 6 unrefunded failures exhaust the scope for the day.
* **Global tiers are deployment-wide.** Their size is an Owner sizing decision; at 12 / 48 they are an aggregate security bound, not a
  fairness guarantee for large deployments.
* **Outage.** The bound is a healthy-backend property (DEC-015 degraded ALLOW is unchanged).
* **Rollback / mixed version.** OAAA admits requests S0 would have blocked, so the S0-family state written afterwards differs; "no exception"
  is not "state parity" (#115 F115-01). Measured in `a04` R1–R4 and `a05` P3/P4.

## 5. Corrections after Direct Lead Review 5512846831 (PR #116, 2026-10-14)

This contract was committed before the run (`d7c8623`); the corrections below are made in place and listed here so nothing is hidden.

* **F116-01 (replay).** The tuple is a fungible per-tuple counter, not an attempt identity. Claims of "single-use", "replay-safe" and
  "exact whatever the order" were too broad and are corrected in §2.4 / §2.6 / I4. Measured: a replayed success resolves another pending
  attempt (L24), a success with no new successful attempt returns a recorded failure (L25), MEDIUM outcome order changes the result (L26),
  identically on Redis (Q7). Attempt-level idempotency requires an attempt-bound identity: **CONTRACT_CHANGE_REQUIRED**, recorded as a distinct
  blocker. No production API is designed here.
* **F116-02 (Lua partial writes).** "Validate then write / no partial write" did not hold for wrong-type tuple / credit keys: Redis does not
  roll back writes made before a script error. The isolated patch now checks the TYPE of every tuple, tier and credit key and the integer form
  of the tuple's pending fields before the first write (re-pinned; the pipeline is unchanged). Measured on real Redis: the current patch leaves
  every involved key unchanged (`a05` Q6); the superseded `0df807f` adapter left a created tier behind after a failed reservation and refunded
  a unit before a failed completion (`a08`). The claim now reads "for the malformed-state cases probed in Q3 / Q6".
* **F116-03 (pre-only).** "Pre-only Hosts = S0" holds for isolated histories only; mixed flows on a shared scope differ (§2.7, I5).
