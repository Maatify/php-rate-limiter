# Identity-Aware Bounded Admission — Security Feasibility (Gate B, #97)

> **Status:** PAPER ANALYSIS ONLY. **NOT APPROVED. NO PRODUCTION CHANGE.** Nothing here was executed; no number in this file
> is new. Every quoted measurement comes from a merged study and carries its stream, arm and that study's baseline.
> **Companion of:** [`ARCHITECTURE_PROPOSAL.md`](ARCHITECTURE_PROPOSAL.md) (Gate A, contract, Owner decisions, recommendation;
> cited here as `P§n`). Code baseline `main@4c6ca618d8206ba502961eaf06ffb82a5d61b50d`. Labels as in `P` (`CODE`, `CONTRACT`,
> `MEASURED`, `DERIVED`, `PROPOSED`, `UNPROVEN`).
> **Scope rule.** Gate A found Owner-level contradictions (GA-1 … GA-5, `P§2`). This file analyses the mechanism **as if** the
> relevant decision were taken, and marks every such result with the decision it depends on. A result marked
> "conditional on OD-n" is not a claim that the design is acceptable; it is what the design would give if the Owner chose so.

---

## 1. Model and notation

| Symbol | Meaning |
| --- | --- |
| `W` | Admission window length. Windows are fixed: `j = floor(t / W)`, `t` caller-supplied (DEC-016) |
| `x` | A scope: `acctU(subject)`, `src(source)`, `globU(class)`, `cid(hint)`, `acctP(account)`, `proof(ref)`, `globP(class)`, `srcP(source)` (all per operation) |
| `c_x` | Capacity of scope `x` per window (`U_acct`, `U_src`, `U_glob`, `U_cid`, `P_acct`, `P_proof`, `P_glob`, `P_src`). **No value is proposed or approved** (OD-6) |
| `n_x(j)` | Counter of scope `x` in window `j` |
| `S(r)` | Scope set reserved by request `r`; depends on its lane (`P§6.1`) |
| `class` | Verifier class: all policies that call one verifier (`P§6.1`) |
| `σ` | Number of sources the attacker controls; `m` accounts for which it holds proofs; `h_a` proofs held for account `a`; `O` its own accounts; `T` its target accounts |
| `D_x` | Legitimate demand on scope `x` per window |

**Reserve(r)** (`PROPOSED`): atomically, if every `x ∈ S(r)` satisfies `n_x(j) + n_x^prev(j) < c_x`, increment `n_x(j)` for all
`x ∈ S(r)` and write one permit; otherwise write nothing. `n_x^prev` is the counter of the previous key generation (§6.4).

**Host contract H1** (`PROPOSED`): on a lane-governed route, every verifier call is preceded by its own `ALLOW` permit, and a
permit is used for at most one verifier call. H1 is satisfied by `pre` and `pre+out` routes that follow the contract; it is not
satisfied by `out` routes and cannot be checked by the library.

---

## 2. Admission accounting (B1)

### 2.1 Unit

The unit is the **admission**: one permit, authorizing one verifier call for one (lane, target subject, operation). It is
neither the HTTP request (a refused request consumes nothing) nor the outcome (an admitted attempt with no outcome stays
consumed). This makes every bound below independent of whether, when and how often outcomes arrive.

### 2.2 Timing

Reservation happens **before** the verifier, at `checkOnly` (or a new `admit()`, OD-3). Only `pre` and `pre+out` routes can be
bounded. An `out` route reaches the library after the verifier ran; the library can score the outcome and influence what the
Host does next, but it cannot bound verifier calls (`P§6.3`). This is a property of the call order, not of the design.

### 2.3 Attempt identity, retries and outcomes

| Event | Handling (`PROPOSED`) | Why |
| --- | --- | --- |
| Admission reply lost, Host retries `admit()` | A second permit is charged | Idempotent admission needs a Host key bound to exactly one verifier call; without that guarantee, deduplication would hand the same permit to two verifier calls (H1 broken). An optional Host idempotency key is admissible only with that guarantee |
| Host calls the verifier twice on one permit | Contract violation (H1); not detectable | The library never sees verifier calls |
| `complete(permit, outcome)` once | `ISSUED → DONE`, then scoring on the lane's punishment keys | One outcome per attempt |
| Duplicate or replayed `complete` | No-op, `applied = false` | Targets the failure class measured in #116, where tuple counters let a replayed success resolve another attempt (`MEASURED` L24/L25/L26, X1 FAIL, `main@72f6dea`); effectiveness `UNPROVEN` until tested |
| `complete` with a different subject, operation or lane | Rejected, not applied | Outcome cannot be re-attributed |
| Outcome after the permit expired | Scored as an unbound outcome (as in `out`), counters untouched | Risk evidence is not dropped; it cannot be deduplicated, so a Host bug double-scores (fails toward denial) |
| No outcome (`pre`, client abort, crash) | Permit expires consumed; no scoring | Bounds unaffected; escalation does not learn (as S0 `pre`) |

The permit id is the public `attemptId`; carrying it from admission to outcome is the public-contract change GA-5 / OD-3.

### 2.4 Atomic all-or-nothing

One script per lane reservation checks every scope of `S(r)` before its first write and writes all or nothing (§6.2). U and P
use disjoint keys, so no cross-lane atomicity is needed. Punishment state (K4U/K4P/K5 blocks) is read before the reservation and
is not part of the atomic step (as today: `EP:498-549` is read-only). `DERIVED`: a block being published concurrently can let
in-flight requests through, but each of them still holds a counted unit, so capacity bounds are unaffected.

### 2.5 Refunds

**None**, and no cancellation. Reasons:

1. A refund returns capacity to a shared pool, which a neighbour can then use (comparison C-15(a)); credit from own successes
   was the measured failure of #116 (`MEASURED` Y6b, successful logins on attacker-owned accounts, 2 → 36/36, E4 FAIL).
2. Refunds need outcome identity to be replay-safe (#116 X1); without refunds, outcomes never touch counters.
3. Without refunds the bounds hold identically in `pre`, `pre+out` and with dropped outcomes.

Cost: legitimate successes consume capacity exactly like failures, so every scope must be sized for legitimate demand, not for
legitimate failures (§5 rows 3–4).

### 2.6 New identities, sessions and accounts

| New thing | Creates capacity? | Basis |
| --- | --- | --- |
| UA, client id, fingerprint, untrusted session id | **No.** No parent scope is keyed on them; a hint may key a sub-limit that is an extra conjunct of `S(r)` | I3 |
| Trusted session / proof for an account already proven | No: shares `P_acct`; adds `P_proof ≤ P_acct` | I5 |
| Proof for another account the attacker holds | Yes: `P_acct` of that account, bounded in aggregate only by `P_glob` / `P_src` | I7; OD-7 |
| New attacker-registered account | Yes, for itself only (`reach_own`); no capacity for foreign targets | I8 |
| New source (IP) | Yes: `U_src` of that source; foreign reach still bounded by `U_acct` (conditional) and `U_glob` | I1, §5 row 6 |

**Non-existent accounts.** The Host passes a target subject derived from the claimed identifier whether or not an account
exists (`PROPOSED`, `P§6.1`). Existing and non-existent subjects follow the same U path; P is impossible without a proof, so a
requester without a proof sees U either way and learns nothing about existence from the decision. Keys are written only on
admission (§6.6), so random identifiers create at most one `acctU` key per **admitted** attempt; refused attempts create none.
Timing side channels are a Host concern and out of scope.

### 2.7 Where each limit applies

| Scope | U lane | P lane | Anonymous API | API principal |
| --- | --- | --- | --- | --- |
| Source | `U_src` | none by default; `P_src` optional (OD-7) | K1 parent | none |
| Target account / principal | `U_acct` (conditional on OD-2) | `P_acct` | — | `R_principal` |
| Per hint / proof | `U_cid ≤ U_src` (optional) | `P_proof ≤ P_acct` | K2/K3, `A_cid` sub-limits | — |
| Service (verifier class) | `U_glob` (optional) | `P_glob` (optional) | `A_glob` (optional) | `R_glob` (optional) |

### 2.8 Both lanes together

Per account `a` and window: verifier calls `≤ U_acct + P_acct` (if `U_acct` exists), else `≤ min(σ·U_src, U_glob) + P_acct`.
Per verifier class and window: `≤ U_glob + P_glob` when both exist; otherwise there is **no finite total**: it grows with the
number of sources, targets and proofs (§4). Splitting the lanes does not make P admissions free; both count in the total.

---

## 3. Invariants

Conditions (each invariant names the ones it needs):

| ID | Condition |
| --- | --- |
| C1 | Reserve is linearizable: one Redis primary, one script, no lost acknowledged write |
| C2 | Host contract H1 holds on every lane-governed route |
| C3 | Parent scopes are derived only from server-side facts: source from the Host's resolved client IP, subject from the Host, class from configuration |
| C4 | Admission counters are never decremented |
| C5 | At most two key generations per window, reserve checks their sum, rotation interval ≥ `W` |
| C6 | One authoritative time for window indices; worker skew `ε ≪ W` |
| C7 | The backend is healthy (DEC-015 degraded mode not active) |
| C8 | The Host validates proof binding; the library checks `proof.account = target` and `proof.operation = operation` |

| ID | Statement | Needs | Proof sketch | Breaks when |
| --- | --- | --- | --- | --- |
| **I1** | `admitted(x, j) ≤ c_x` for every scope and window | C1, C4, C5, C6 | Each admission increments `n_x` by exactly one after observing `n_x + n_x^prev < c_x`; linearizability orders admissions, so no two observe the same value | lost acknowledged writes (§6.9); two rotations in one window; skew moving requests across windows |
| I1′ | Any interval of length `W`: `≤ 2·c_x`; any duration `T`: `≤ c_x·(⌈T/W⌉ + 1)` | I1 | An interval meets at most two fixed windows | — |
| **I2** | Verifier calls attributable to `x` `≤ admitted(x, j)` | I1, C2 | H1 maps verifier calls injectively to permits | `out` routes; Host reuse of a permit; old workers (§6.7) |
| **I3** | For fixed source, subject and class, U admissions `≤` the minimum of the adopted parents (`U_src`, `U_acct`, `U_glob`) whatever the number of UAs, client ids, fingerprints or sessions used | I1, C3 | No parent key contains a client-chosen value; hint sub-limits are additional conjuncts and can only refuse | a parent keyed on a client value (forbidden by `P§6.1`) |
| **I4** | U traffic never changes a P counter, and U punishment never closes the P lane | C1, OD-5 | Disjoint key namespaces; K4U not read on P, K4P not read on U (`P§7`) | without the K4 split (GA-4) |
| **I5** | P admissions for account `a` come only from holders of a proof for `a`; `≤ P_acct` per window | I1, C8 | Lane P requires the equality check | a Host that mis-binds proofs (`MEASURED` control SPV 4 → 200/200, #119) |
| **I6** | Per account: `≤ U_acct + P_acct` per window | I1, I2, OD-2(a) | Sum of disjoint lanes | without `U_acct`: U part bounded only by `min(σ·U_src, U_glob)` |
| **I7** | Per class: `≤ U_glob + P_glob` per window | I1, I2, OD-6 | Sum of lanes | without globals: no finite total; with per-policy instead of per-class globals: `× number of policies` (B4-6) |
| **I8** | New attacker accounts add no foreign-target capacity | C3, C4 | Foreign U scopes are keyed by the target subject; no refunds or credits move capacity between subjects | refunds or credits (excluded by C4) |
| **I9** | I1–I8 do not depend on outcomes | C4 | Outcomes never touch counters | — |
| **I10** | New keys per window `≤ |S(r)|·admitted`; TTL `≤ 2W` | C1 | The script writes only on admission | a non-atomic implementation that writes before checking (#116 F116-02 lesson) |

I1, I1′, I3, I8, I9 and I10 depend only on the mechanism decisions (OD-1, OD-3, OD-4); I4–I7 additionally depend on OD-2,
OD-5 and OD-6 as stated. None is a runtime proof.

---

## 4. Reach decomposition

Per window, for an attacker with `σ` sources, target set `T`, proofs for `m` accounts (`h_a` proofs on account `a`), own
accounts `O`:

| Metric | Bound | Status |
| --- | --- | --- |
| `reach_foreign` (U lane: targets without a proof) | `≤ min(σ·U_src, |T|·U_acct, U_glob)`; without `U_acct`: `≤ min(σ·U_src, U_glob)` | `DERIVED`; `U_acct` part conditional on OD-2 |
| `reach_proof` (P lane on accounts whose proof it holds — stolen or own) | `≤ min(Σ_a min(P_acct, h_a·P_proof), P_glob)`; with `P_src`: also `≤ σ·P_src` | `DERIVED`; OD-2(c), OD-7 |
| `reach_own` (padding on own accounts) | inside `reach_proof` if proven, inside the U bound otherwise; never adds foreign capacity (I8) | `DERIVED` |
| Total verifier operations | `≤ U_glob + P_glob`, else unbounded in `σ`, `|T|`, `m` | `DERIVED` |
| Legitimate denied on a shared scope `x` | if the attacker can submit `≥ c_x` reservations at the start of a window, legitimate admissions on `x` can be 0 for that window; in general `≥ D_x − (c_x − attacker_x)` refusals | `DERIVED` |
| Resource exhaustion | refused requests still cost one script execution and the punishment reads (as today) | `DERIVED` |
| Residual false positives | no correlation-derived punishment; refusals are capacity refusals, scoped and non-persistent | `PROPOSED` |

`reach_proof` mixes two different threats that the brief's `reach_own` does not separate: a **stolen** proof (the account is
someone else's; guesses are a real compromise risk, so per-account and aggregate bounds matter) and an **own** account
(padding; only resource cost). Expected compromised accounts grow with the total guesses, not only per account, so the
aggregate term matters for stolen proofs (`DERIVED`).

---

## 5. Threat-model verdicts (B2)

Verdict scale: **PASS** (property holds under the stated conditions), **CONDITIONAL** (holds only after the named Owner
decision), **RESIDUAL** (cannot be solved in scope; harm is bounded and named), **FAIL** (the design does not provide it).

| # | Case | Required property | Design result | Verdict | Evidence |
| --- | --- | --- | --- | --- | --- |
| 1 | A/B/A behind one IP and UA | Many clients ≠ one punished actor | Churn is an observation; A and B each consume `U_src`; nothing persists | **PASS** for attribution; availability needs `U_src ≥ D_src` | `MEASURED` S0 ABA_B 118/120 legitimate denied, churn-caused (#116 on `main@72f6dea`; #115). OAAA removed the loop for successful users (118 → 0) by another mechanism. U/P effect `UNPROVEN` |
| 2 | D1 / LOW cross-IP | A common UA is not one identity | No dilution punishment; each user is its own source; refusal only if `U_glob < D_glob` or the user's own `U_acct` is exhausted | **PASS** conditional on `U_glob` sizing (OD-6) | `MEASURED` D1 596/600 (S0N) → 0/600 when the cohort is no longer one fingerprint (#125, `main@9e95f2f`). Attacker side: X2 (new IP and victim per attempt) is bounded only by `U_glob`; without it, 121/121 — S0's best-response reach (UA rotation, #125); the S0 naive one-UA cell was 6/121 |
| 3 | Office day 0 | What happens with no prior proof | Every employee is U; refusals `≥ N·d − U_src` per window; without OD-2(c) Login has no way out of U | **RESIDUAL** (indistinguishable from an attacker at the same source) | `MEASURED` S0 context: 86–88 of 100 office browsers without a successful login per day (#118 R8, `main@a6c79f4`); day-0 gives nothing to known-device relief (#119 R13) |
| 4 | Shared NAT + attacker | Who can drain which share | `U_src`: anyone at the source; `U_acct(a)`: anyone anywhere; `U_glob`: anyone; `P_acct(a)`: only holders of a proof for `a`; office P shares untouchable by the attacker | **RESIDUAL** for U; **CONDITIONAL** (OD-2(c), OD-5) for P | Same-IP office and rotating attacker give identical observables (#105 A06; #125 same-IP tally). No credit, so a co-located attacker gets at most `U_src` — unlike #116 where it used colleagues' credit (`MEASURED` E3 FAIL, MIX/X4/X6 +2) |
| 5 | UA / id rotation | No new units | No scope keyed on UA or id (I3). On a proven device a UA change gives a new passive K5, but `P_proof` is keyed on the proof reference (`P§4.5`) | **PASS** | `MEASURED` S0 minted scope by UA major: TMSd 2/200 vs TMUA 36/200 (#120); X2 6 → 121/121 by UA rotation (#125) |
| 6 | Multiple IPs | Limits beyond one source | Foreign U reach `≤ min(σ·U_src, |T|·U_acct, U_glob)`. One target: `U_acct` per window regardless of `σ`. IPv6: a `/48` holds 65 536 `/64` sources; the hierarchy is `UNPROVEN`. IPv4 exact: CGNAT users share one `U_src` | **CONDITIONAL** (OD-2(a)) for one target; **RESIDUAL** for many targets without `U_glob` | `MEASURED` S0 BRD (one account, new IP per attempt) checkOnly-only best response 22/100 victim guesses (#125). Under U/P: `≤ U_acct·(⌈T/W⌉+1)` independent of outcomes (`DERIVED`, conditional) |
| 7 | 12 trusted accounts | Multiplication; total verifier cost | P reach `≤ min(12·P_acct, P_glob)` per window (per account `≤ P_acct`); with `P_src`, `≤ P_src` | **RESIDUAL** without `P_src`; OD-7 | `MEASURED` S0 TMSd 2/200; when only per-account DEC-021 bounds remain, TMSd 2 → 60/200 and gain grows with accounts held (#119 R3, R6, model on the real pipeline); office N12T ≡ attacker TMS request for request (#119 M3). In `pre`, relief variants reached 200/200 (#119 R4); here `pre` is bounded by `P_acct` per window (`DERIVED`) |
| 8 | Target-account harassment | Owner keeps a usable share | Owner with a proof: `P_acct` and K4P untouched by U (I4). Owner without a proof: shares `U_acct` and K4U with the attacker | **CONDITIONAL** (OD-2(c), OD-5) for proven owners; **FAIL** for unproven owners (renewable, as with S0 K4 score) | `CODE` today: K4 blocks reach the owner's trusted session (`P§7`); not measured |
| 9 | Anonymous API | What can be proven | Per-source and per-class capacity; no attribution; no per-person fairness within a source | **PASS** (capacity); **RESIDUAL** (fairness) | `MEASURED` D1API 596/600 → 0/600 once the cohort is not one fingerprint (#125): correlation, not rate, caused that harm |
| 10 | Missing outcomes | What the library guarantees | Per-window bounds hold (I9); no escalation without outcomes; `out` has no pre-verifier bound | **PASS** (`pre`, `pre+out` with drops); **FAIL** (`out`, by call order) | `MEASURED` S0 D24K1 `pre` 1440/1440 (#119 R16) |
| 11 | Redis failure / rotation | Bounds stay correct or weaken explicitly | Rotation: dual-generation sum keeps I1 if interval ≥ `W`. Failover: lost acknowledged reserves re-issue capacity. Outage: DEC-015 degraded mode uses process-local counters without lanes | **DEGRADED, explicit** (§6) | `CODE` `LocalFallbackLimiter.php:37-43`; `MEASURED` #116 O6: a failing admission operation produced 8 `DEGRADED_MODE` ALLOWs where S0 kept blocking (`main@72f6dea`) |

---

## 6. Storage feasibility (B3)

### 6.1 Proposed contract (description only; no interface is added)

| Operation | Input | Effect | Failure |
| --- | --- | --- | --- |
| Reserve | class, list of `{key, capacity, previousKey?}`, permit `{id, lane, subject, operation, ttl}`, caller time | All-or-nothing increment of every key plus permit creation; or nothing | Typed backend failure for availability; malformed state is a `RateLimiterException`, never a backend failure (DEC-015 provenance), and leaves no partial write |
| Complete | permit id, expected `{lane, subject, operation}`, outcome, caller time | `ISSUED → DONE` once; returns `applied` | Same |
| Read (operational) | keys | Counters and remaining window for operators | Read-only |

### 6.2 Single Redis

One Lua script per reservation validates every key's type and every permit field **before the first write** (the #116 F116-02
fix: Redis does not roll back a script's earlier writes), then writes. Window index and TTL come from caller time; the script
never calls `TIME` (DEC-016). Replies follow DEC-018. This is the supported topology: the official adapter targets one logical
non-clustered Redis (DEC-006 history; `src/Repository/Redis/RedisFullCapabilityStore.php:27`).

### 6.3 Redis Cluster

A script may only touch keys of one hash slot. Two options, neither built:

* **One slot per verifier class** (hash tag on the class): atomic, but every admission of the class lands on one shard, which
  becomes the throughput ceiling and a hot key.
* **Per-key reserve with compensation**: increment each scope with an atomic compare, roll back the earlier ones on refusal.
  `DERIVED`: no over-admission, because each scope's own check is atomic and admission needs all of them; but transient
  over-reservation refuses legitimate requests, and a worker that dies between steps leaks units until the window ends
  (comparison C-14). A lost reply on the increment fails closed and also leaks.

### 6.4 Secret rotation

Keys are HMACs of the secret (as `EP:2781-2784`), so a new secret starts empty. Reserve reads the previous generation counter
and admits only if the sum is below capacity, writing only the current one — the existing read-only-previous model
(`KS` §4.3). `DERIVED`: correct while the previous key lives; a second rotation within one window drops the oldest counter and
re-issues up to `c_x`, hence C5 and the existing no-overlapping-generations rule (`DFP` §5.1.6).

### 6.5 Replay and duplicate outcomes

The permit record makes `complete` idempotent (§2.3). Scoring runs after the `DONE` transition; a crash between the two loses
one scoring (fails toward fewer penalties, bounded by one per crash). Putting scoring inside the script would bind it to the
DEC-007 generation-bound K4 mutation, which already has its own atomic primitive; combining them is `UNPROVEN`.

### 6.6 TTL and cardinality

Counters live `W` plus the previous generation's remainder (`≤ 2W`); permits live a Host-declared verifier-latency bound
`≤ W`. Keys are created only on admission (I10). Per window, new keys `≤ |S(r)|·admitted`; with `U_glob` this is finite, without
it it scales with the attacker's admission rate across sources — the same order as today's per-IP keys. Hint sub-limit keys
are capped per source (the existing 50-per-IP device cap, `EphemeralBucket.php:21`) with one overflow bucket.

### 6.7 Old and new workers

Old workers do not reserve, so their verifier calls are not counted: in a mixed fleet the bound is `c_x + calls through old
workers` (`UNPROVEN` in size). Old workers also keep writing K1/K2/K3 correlation blocks, which new workers honour read-only to
expiry, so collective blocks continue while mixed. `PROPOSED` fence: an operator-set admission epoch enables the new path only
after the fleet is upgraded.

### 6.8 Rollback

S0 ignores admission counters, permits and K4P; they expire by TTL. K4U is the legacy K4 key, so U-lane history survives;
P-lane punishments do not. Rolling forward within the same window finds the old counters; traffic admitted by S0 meanwhile was
not counted, so that window's bound becomes `c_x + S0-phase admissions`. Nothing is re-issued beyond that (`DERIVED`).

### 6.9 Failover and lost writes

With asynchronous replication, a promoted replica can miss acknowledged reservations and permits. `DERIVED`: counters fall back
and up to `L` units are re-issued, `L` = reservations acknowledged within the replication lag before the failover; lost
permits make their outcomes unscored. `WAIT` narrows `L` but does not give linearizability. I1 becomes "per window, modulo
`L` at each failover"; the size of `L` is `UNPROVEN`.

### 6.10 DEC-015 degraded mode

While the circuit is open no store call happens, so no admission is possible. Login and OTP fall to `FAIL_CLOSED` and then
`DEGRADED_MODE` with process-local counters (`RLE:308-345`); API to `FAIL_OPEN` with its local limits. `DERIVED` from `CODE`
(`LocalFallbackLimiter.php:37-43`, static per PHP process): admissions per account during an outage `≤ N_proc · 3` per 600 s
under `AUTHENTICATION_PRIMARY`, with no lanes, so owner protection is absent. Keeping the U/P bounds through an outage would
need either `FAIL_CLOSED` throughout (availability cost) or a change to DEC-015/DEC-011 — OD-8. A partial failure of the
admission operation must take the typed failure path for the whole request, never a partial allow (#116 O6 lesson).

---

## 7. Falsification (B4)

| ID | Attack | Result | Structural gap |
| --- | --- | --- | --- |
| B4-1 | Sybil identities (UA, client id, fingerprint, cookie, session churn) | **Holds** (I3): no parent keyed on them; sub-limits only refuse | none, given C3 |
| B4-2 | Attacker with valid accounts | Own accounts: `reach_own` bounded by P/U scopes, no transfer to foreign targets (I8; no refunds, so #116 Y6b's washing cannot occur). X7-style padding on own accounts consumes the attacker's own scopes only | aggregate own-account cost counts toward totals; bounded only by globals |
| B4-3 | Drain U to deny new users | **Succeeds by design**: `U_src` by a co-located attacker; `U_acct(a)` by anyone (if adopted); `U_glob` by a distributed attacker denies every unproven user of the class, renewably each window | inherent; the price of a finite U bound. Owner must accept or drop the scope (OD-2, OD-6) |
| B4-4 | Drain P to deny proven users | `P_acct(a)`: only with proofs for `a`; one stolen proof is held to `P_proof` (`P_acct ≥ k·P_proof`). `P_glob`: an attacker with enough own proven accounts (`m ≥ P_glob / P_acct`) denies every proven user | `P_glob` turns account registration into a lane-wide denial lever (OD-6) |
| B4-5 | Global ceiling as a denial lever | Any finite `U_glob`/`P_glob` that binds before the verifier saturates converts verifier overload into refusal of every legitimate user of the lane; without it, distributed many-victim attacks have no aggregate bound (X2: 121/121 in S0 best response) | trade-off, not solvable by identity; OD-6 |
| B4-6 | Bypass the global by splitting operations or policies | State is namespaced per policy (`EP:2607`); login, re-authentication and password change may all call one verifier and may all be `login_protection` or customs (DEC-008 context). Per-policy ceilings multiply by the number of policies | needs the verifier-class grouping of `P§6.1`, which is new configuration (OD-6) |
| B4-7 | Concurrent requests | One linearizable script ⇒ no over-admission (I1). Punishment reads are not atomic with the reserve; extra in-flight requests still hold counted units (§2.4). Cluster with compensation: no over-admission, transient false refusals (§6.3) | Cluster throughput (one slot) |
| B4-8 | Re-issue on rotation or rollback | Rotation: safe if interval ≥ `W` (§6.4); two rotations in one window re-issue `c_x`. Rollback / roll-forward in one window: `+` S0-phase admissions (§6.8) | operational preconditions, not code |
| B4-9 | Proof attributed to another account | Library refuses a proof whose account or operation differs (C8). A Host that mis-binds proofs defeats it | Host compliance (`MEASURED` control SPV 4 → 200/200, #119). Contrast S0: a Host-attested trusted session spraying 200 fresh accounts reached 200/200 (SP5, #108); with a proof that names its own account, those requests are U for each target and bounded by `U_src` (`DERIVED`) |
| B4-10 | Unintended loss of K4 protection | Removed on purpose: U-lane evidence no longer closes P; DEC-007 re-entry is not served to P. Kept: every S0 K4 mechanism on U (K4U = legacy K4); DEC-021 per device and allowance on both lanes; K4P for proven overflow. `pre` P traffic is not scored (as S0) but is now bounded by `P_acct` per window | none found beyond the intended removals; depends on OD-5 being implemented as specified |

---

## 8. Residuals that cannot be solved in scope

1. **Co-located indistinguishability.** At one source, an unproven office and an attacker present the same observables (#105
   A06, #125). `U_src` trades one against the other; no identity input changes that without a proof.
2. **Proofless Login.** Without OD-2(c), every Login user is U every day (GA-1); owner protection does not apply to Login.
3. **Renewable denial of unproven owners.** Through `U_acct` (if adopted) and K4U score (as S0 K4 today).
4. **Global ceilings.** Either a lane-wide denial lever or no aggregate bound against distributed many-victim attacks.
5. **Linear reach in proofs held** (stolen sessions), unless `P_src` re-creates the source-scoped bound that also hits a trusted
   office (OD-7).
6. **Address granularity.** IPv4 exact (CGNAT shares one source); IPv6 `/64` (a `/48` holder has 65 536 sources).
7. **Record-only Hosts** get no pre-verifier bound.
8. **Outage and failover** weaken bounds to per-worker local limits and to "modulo lost writes".
9. **Host compliance**: one permit per verifier call (H1) and proof binding (C8) cannot be verified by the library.
10. **Fixed-window edges**: up to `2·c_x` in any interval of length `W`.
11. **Mixed fleet and rollback windows**: bounds `UNPROVEN` while old workers serve traffic.

---

## 9. What a prototype must measure before any GO

Paper cannot settle these; each needs the isolated prototype the Owner may authorize (OD-10), with thresholds swept as
parameters:

* Replay of the merged streams (D1, L1, OFF12, ABA_A/ABA_B, D2, Nu4/Nu12, N12T, TMS/TMSd/TMUA, SP1n/SP3/SP6, SP5, X1/X2/X7,
  BRU/BRD, D24K1) in `pre`, `pre+out`, `out` and mixed routes, reporting per stream: `reach_foreign`, `reach_proof`,
  `reach_own`, total verifier operations, legitimate denied by reason (capacity vs punishment), and per-window maxima — judged
  against I1–I10, not against S0 parity.
* Barrier-synchronised concurrency on real Redis with mutation (remove atomicity; the test must fail), replayed and
  out-of-order `complete`, expired permits.
* Rotation (one and two within a window), mixed fleet, rollback and roll-forward, fault injection for lost replies and failover
  with acknowledged-write loss, DEC-015 outage.
* Office multi-day bootstrap with and without a proof contract; CGNAT and IPv6 hierarchy cells.
* The K4 lane split against DEC-007 lifecycle and DEC-021 regressions (`KnownDeviceAntiEquilibriumScopeTest` scenarios).
