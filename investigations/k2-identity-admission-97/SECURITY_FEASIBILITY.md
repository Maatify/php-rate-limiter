# Identity-Aware Bounded Admission — Security Feasibility (Gate B, #97)

> **Status:** PAPER ANALYSIS ONLY. **NOT APPROVED. NO PRODUCTION CHANGE.** Nothing here was executed; no number in this file
> is new. Every quoted measurement comes from a merged study and carries its stream, arm and that study's baseline.
> **Companion of:** [`ARCHITECTURE_PROPOSAL.md`](ARCHITECTURE_PROPOSAL.md) (Gate A, contract, Owner decisions, recommendation;
> cited here as `P§n`). Code baseline `main@4c6ca618d8206ba502961eaf06ffb82a5d61b50d`. Labels as in `P` (`CODE`, `CONTRACT`,
> `MEASURED`, `DERIVED`, `PROPOSED`, `UNPROVEN`).
> **Scope rule.** Gate A found contract gaps that need Owner decisions (GA-1 … GA-4, `P§2`). This file analyses the mechanism
> **as if** the relevant decision were taken, and marks every such result with the decision it depends on (Owner decisions
> R-1, D1–D7 and Lead choices LT-1 … LT-7, `P§11`). A result marked "per Dn" is not a claim that the design is acceptable; it is
> what the design would give if the Owner chose so.
> **Revision:** corrected after four Direct Lead Reviews of PR #126 (F126-01 … 05, R2-01 … 04, R3-01 … 05, R4-01 … 03); see
> `P§13`.

---

## 1. Model and notation

| Symbol | Meaning |
| --- | --- |
| `W` | Admission window length. Windows are fixed: `j = floor(t / W)`, `t` caller-supplied (DEC-016) |
| `x` | A scope: `acctU(subject)`, `src(source)`, `globU(class)`, `cid(hint)`, `acctP(account)`, `proof(ref)`, `globP(class)`, `srcP(source)` (all per operation) |
| `c_x` | Capacity of scope `x` per window (`U_acct`, `U_src`, `U_glob`, `U_cid`, `P_acct`, `P_proof`, `P_glob`, `P_src`). **No value is proposed or approved** (D5) |
| `n_x(j)` | Counter of scope `x` in window `j` |
| `S(r)` | Scope set reserved by request `r`; depends on its lane (`P§6.1`) |
| `class` | Verifier class: all policies that call one verifier (`P§6.1`) |
| `σ` | Number of sources the attacker controls; `m` accounts for which it holds proofs; `h_a` proofs held for account `a`; `O` its own accounts; `T` its target accounts |
| `D_x` | Legitimate demand on scope `x` per window |

**Reserve(r)** (`PROPOSED`): atomically, if every `x ∈ S(r)` satisfies `n_x(j) + n_x^prev(j) < c_x`, increment `n_x(j)` for all
`x ∈ S(r)` and, with T1, write one permit record; otherwise write nothing. `n_x^prev` is the counter of the previous key generation (§6.4).

**Host contract H1** (`PROPOSED`): on a lane-governed route, every verifier call is preceded by its own admitted `checkOnly`
(`ALLOW`), and one admission is used for at most one verifier call. H1 is satisfied by `pre` and `pre+out` routes that follow the contract; it is not
satisfied by `out` routes and cannot be checked by the library.

---

## 2. Admission accounting (B1)

### 2.1 Unit

The unit is the **admission**: one irreversible reservation, authorizing one verifier call for one (lane, target subject,
operation). It is neither the HTTP request (a refused request consumes nothing) nor the outcome (an admitted attempt with no
outcome stays consumed). This makes every bound below independent of whether, when and how often outcomes arrive. With tier T1
(`P§6.5`) an admission also carries a permit record; with T0 it does not, and the bounds are the same.

### 2.2 Timing

Reservation happens **before** the verifier, inside `checkOnly` for a policy that opts in (`P§6.2`). Only `pre` and `pre+out`
routes can be bounded. An `out` route reaches the library after the verifier ran; the library can score the outcome and
influence what the Host does next, but it cannot bound verifier calls (`P§6.4`). This is a property of the call order, not of
the design.

### 2.3 Outcomes, retries and replay (F126-02)

**Admission side (T0 and T1).**

| Event | Handling (`PROPOSED`) | Why |
| --- | --- | --- |
| `checkOnly` reply lost, Host retries | A second admission is charged | Idempotent admission needs a Host key bound to exactly one verifier call; without that guarantee, deduplication would let two verifier calls share one admission (H1 broken) |
| Host calls the verifier twice on one admission | Contract violation (H1); not detectable | The library never sees verifier calls |
| Backend error during the reservation | Typed backend failure for the whole request (DEC-015 path); no partial allow | #116 O6 lesson (§6.10) |

**Outcome side, T0.** `recordFailure` / `recordSuccess` stay unbound, exactly as today: each call is scored, so a duplicated
outcome scores twice and a dropped one scores nothing (`CODE`: `EP:380-396` runs on every failure command). T0 changes nothing
here and claims nothing more.

**Outcome side, T1.** The permit record has four states. The library reports which one it found; it never converts a
permit-bearing outcome into an unbound one.

| State | Meaning | Lifetime |
| --- | --- | --- |
| `ISSUED` | Admitted; an outcome may still be accepted | until the deadline `D` (a Host-declared verifier-latency bound, `≤ W`) |
| `CLAIMED` | One outcome accepted; scoring has been attempted once | tombstone until `D + G` (`G` = duplicate-delivery grace) |
| `EXPIRED` | Deadline passed with no outcome | tombstone until `D + G` |
| `UNKNOWN` | No record: never issued, tombstone gone, or lost in a failover | — |

| Outcome event | State found | Result | Scoring |
| --- | --- | --- | --- |
| First outcome, matching subject / operation / lane | `ISSUED` | `ISSUED → CLAIMED` (atomic compare-and-set), `accepted` | once, after the claim |
| Duplicate or replay | `CLAIMED` | `duplicate` | none |
| Conflicting outcome (success after failure, or the reverse) | `CLAIMED` | `conflict`; the first outcome stands | none |
| Mismatched subject, operation or lane | any | `rejected` | none |
| Late outcome | `EXPIRED` | `late`, rejected | none |
| Unknown permit | `UNKNOWN` | `unknown`, rejected | none |
| Backend error on the claim; Host retries | `CLAIMED` if the claim landed, else `ISSUED` | `duplicate` or `accepted` | at most once |
| Crash after the claim, before or during scoring | `CLAIMED` | a retry returns `duplicate` | the outcome is lost |
| Failover loses an acknowledged claim | `ISSUED` again on the promoted replica | a retry is `accepted` | a second scoring is possible |

Guarantee, named precisely: **at most one scoring per permit, modulo claims lost in a failover.** Not exactly-once: scoring
writes several keys through separate store operations (K4 generation-bound mutation, K5, budget, allowance, watchers), and the
pipeline is not transactional across them (DEC-021 Consequences), so a crash between the claim and the last scoring write loses
part or all of that outcome. Making it exactly-once would need either one atomic primitive covering the claim and every scoring
write (it would have to absorb DEC-007's generation-bound K4 mutation — `UNPROVEN`) or idempotent scoring writes keyed by permit
(no such Store contract exists). **That gap is stated, not closed.**

Consequences (`DERIVED`): a lost or late outcome costs one scoring; it never changes an admission bound (I9), because outcomes
never touch counters. An attacker who can delay the Host past `D` (for example a slow verifier input) drops its own failure
scoring, but each attempt still consumed an admission. The `out` path is a separate legacy route, unbound by design; a
permit-bearing route must not fall back to it when its outcome is rejected. The #116 replay failure class (`MEASURED`
L24/L25/L26, X1 FAIL, `main@72f6dea`) is addressed by binding outcomes to one permit; its effectiveness is `UNPROVEN` until
tested.

### 2.4 Atomic all-or-nothing

One script per lane reservation checks every scope of `S(r)` before its first write and writes all or nothing (§6.2). U and P
use disjoint keys, so no cross-lane atomicity is needed. Penalty state (K4/K5 blocks) is read before the reservation and
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
| Proof for another account the attacker holds | Yes: `P_acct` of that account, bounded in aggregate only by `P_glob` / `P_src` | I7; D5 |
| New attacker-registered account | Yes, for itself only (`reach_own`); no capacity for foreign targets | I8 |
| New source (IP) | Yes: `U_src` of that source; foreign reach still bounded by `U_acct` (per D4) and `U_glob` | I1, §5 row 6 |

**Non-existent accounts.** The Host passes a target subject derived from the claimed identifier whether or not an account
exists (`PROPOSED`, `P§6.1`). Existing and non-existent subjects follow the same U path; P is impossible without a proof, so a
requester without a proof sees U either way and learns nothing about existence from the decision. Keys are written only on
admission (§6.6), so random identifiers create at most one `acctU` key per **admitted** attempt; refused attempts create none.
Timing side channels are a Host concern and out of scope.

### 2.7 Where each limit applies

| Scope | U lane | P lane | Anonymous API | API principal |
| --- | --- | --- | --- | --- |
| Source | `U_src` | none (`P_src` not recommended, D5) | K1 parent | none |
| Target account / principal | `U_acct` (per D4) | `P_acct` (window and day) | — | `R_principal` |
| Per hint / proof | `U_cid ≤ U_src` (optional, identified clients only) | `P_proof ≤ P_acct` (window and day) | optional identified-client sub-limit `≤ K1`; no K2 / K3 | — |
| Service (verifier class) | `U_glob` (optional) | `P_glob` (optional) | `A_glob` (optional) | `R_glob` (optional) |

### 2.8 Both lanes together

Per account `a` and window: verifier calls `≤ U_acct + P_acct` (if `U_acct` exists), else `≤ min(σ·U_src, U_glob) + P_acct`.
Per verifier class and window: `≤ U_glob + P_glob` when both exist; otherwise there is **no finite total**: it grows with the
number of sources, targets and proofs (§4). Splitting the lanes does not make P admissions free; both count in the total.

### 2.9 Unproven-owner availability: decision class versus effect (F126-03)

**Decision class.** A `U_acct` refusal is a capacity `SOFT_BLOCK`: not persisted, not counted by Anti-Equilibrium, never
escalated, attributed to nobody (`P§6.3`). It is therefore not the account-wide `HARD_BLOCK` on attempt counts that `POL` §1
forbids.

**Availability effect.** It can still refuse an owner. The question is whether any rule avoids that without giving up the bound.

**Setting.** Unproven requests for one target account `a`. The owner holds no proof. The library sees the account subject,
the source and client-chosen hints; hints may only refuse (I3), and the attacker can copy or rotate them. The attacker controls
`σ` sources, none of them the owner's, and can send at least `c` requests per window. The rule is useful: when nothing else has
consumed capacity, a request from a source with no history is admitted (otherwise no unproven user is ever admitted).

**Claim** (`DERIVED`). For such traffic a rule cannot have both properties:

* **(B)** attacker admissions on `a` per window `≤ c`, with `c` independent of `σ`;
* **(O)** an owner at a source the attacker does not use is never refused because of the attacker's requests.

**Proof.** Consider the capacity left on `a` for a source with no history.

1. If admissions from other sources can reduce it to zero (an account-wide counter or an account-wide penalty), the attacker
   uses its sources to do so before the owner arrives; the owner is refused. Repeating each window makes the refusal
   renewable. (B) can hold; (O) fails.
2. Otherwise a floor `f ≥ 1` survives any consumption at other sources. The attacker's sources are, to the rule, sources with
   no history like the owner's (hints are copyable, I3), so each of them also gets `f`: attacker admissions `≥ σ·f`, growing
   with `σ`. (O) can hold; (B) fails.

The two cases are exhaustive. Only a proof (D3) escapes the dichotomy. ∎

**Corollaries.**

* S0 is already in case 1: its account-wide K4 score lets failures from any source refuse every unproven request on the account
  in `pre+out` (`P§7.1`). `U_acct` adds the same effect in `pre` and for attempts that never report a failure.
* (O) says nothing about a co-located attacker: at a shared source the attacker consumes the floor too, so no rule over these
  observables protects that owner.
* An outcome-only rule (count failures, not attempts) is case 1 for `pre+out` and leaves `pre` unbounded per account.

**Options** (for D4, `P§11.2`):

| Option | Rule | (B) | (O) | Note |
| --- | --- | --- | --- | --- |
| U-1 | no per-account cap; K4 score only | in `pre+out` only | no (K4 is case 1) | today's behaviour; `pre` unbounded per account |
| U-2 | `U_acct` per window (case 1) | yes | no | renewable refusal of unproven owners, as S0's K4 in `pre+out` |
| U-3 | per-source floor `f` on `a`, plus `U_acct` above it | `≤ U_acct + σ·f` | yes for a clean source | re-creates source scaling |

Proven owners are outside the dichotomy: their requests are in P, whose capacity and penalties unproven traffic cannot
reach (I4a, I4b), subject to the device-secret condition of `P§7.3` and the shared-allowance coupling of `P§7.2` item 3.

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
| C9 | Every P request carries a server-issued, secret, per-device `sessionDeviceId`, and the resolver keeps it in the fingerprint material (`P§7.3` P-1) |

| ID | Statement | Needs | Proof sketch | Breaks when |
| --- | --- | --- | --- | --- |
| **I1** | `admitted(x, j) ≤ c_x` for every scope and window | C1, C4, C5, C6 | Each admission increments `n_x` by exactly one after observing `n_x + n_x^prev < c_x`; linearizability orders admissions, so no two observe the same value | lost acknowledged writes (§6.9); two rotations in one window; skew moving requests across windows |
| I1′ | Any interval of length `W`: `≤ 2·c_x`; any duration `T`: `≤ c_x·(⌈T/W⌉ + 1)` | I1 | An interval meets at most two fixed windows | — |
| **I2** | Verifier calls attributable to `x` `≤ admitted(x, j)` | I1, C2 | H1 maps verifier calls injectively to admissions | `out` routes; Host reuse of an admission; old workers (§6.7) |
| **I3** | For fixed source, subject and class, U admissions `≤` the minimum of the adopted parents (`U_src`, `U_acct`, `U_glob`) whatever the number of UAs, client ids, fingerprints or sessions used | I1, C3 | No parent key contains a client-chosen value; hint sub-limits are additional conjuncts and can only refuse | a parent keyed on a client value (forbidden by `P§6.1`) |
| **I4a** | U traffic never changes a P admission counter and never writes K4P, a P budget or a P watcher, so it cannot consume P capacity or create a P-lane **account** penalty | C1, D2 | Disjoint key namespaces (`P§6.1`, `P§7.2`, M-A); K4U is not read for P requests and K4P is not read for U requests | a non-lane-scoped implementation (GA-4). **Not covered:** the shared DEC-021 allowance (`P§7.2` item 3) |
| **I4b** | U traffic cannot create a **device-level** penalty on a P request (K5 score or block, device watcher, micro-cap, distributed-account or flood candidate, ephemeral routing) unless it presents that device's `sessionDeviceId` | C1, C9, D3 | Rule P-1 keys every P request's K5 family on a secret value; P-2 keeps P out of the U device-population sets and candidates; P-3 gives P its own device cap (`P§7.3`) | a request presenting the same `sessionDeviceId` (same device without a proof, or a stolen secret); a custom resolver that drops `sessionDeviceId` from the material; old workers on paths 5–7 during a mixed rollout |
| **I5** | P admissions for account `a` come only from holders of a proof for `a`; `≤ P_acct` per window | I1, C8 | Lane P requires the equality check | a Host that mis-binds proofs (`MEASURED` control SPV 4 → 200/200, #119) |
| **I6** | Per account: `≤ U_acct + P_acct` per window | I1, I2, D4 (U-2) | Sum of disjoint lanes | without `U_acct`: U part bounded only by `min(σ·U_src, U_glob)` |
| **I7** | Per class: `≤ U_glob + P_glob` per window | I1, I2, D5 | Sum of lanes | without globals: no finite total; with per-policy instead of per-class globals: `× number of policies` (B4-6) |
| **I8** | New attacker accounts add no foreign-target capacity | C3, C4 | Foreign U scopes are keyed by the target subject; no refunds or credits move capacity between subjects | refunds or credits (excluded by C4) |
| **I9** | I1–I8 do not depend on outcomes | C4 | Outcomes never touch counters | — |
| **I10** | Keys are written only on admission: new admission keys per class and window `≤ (s + p)·A_j` plus capped hint keys (§6.6); counters live `≤ 2W`, permits `≤ D + G` | C1 | The script checks before its first write and writes nothing on refusal | a non-atomic implementation that writes before checking (#116 F116-02 lesson). `A_j` is finite only if the global ceilings exist; otherwise storage grows with the admissions obtainable across sources (§6.6) |

I1, I1′, I3, I8, I9 and I10 depend only on the mechanism (D1 with the Lead choices LT-2 and LT-4); I4a–I7 additionally depend on
D2, D3, D4 and D5 as stated. None is a runtime proof.

---

## 4. Reach decomposition

Per window, for an attacker with `σ` sources, target set `T`, proofs for `m` accounts (`h_a` proofs on account `a`), own
accounts `O`:

| Metric | Bound | Status |
| --- | --- | --- |
| `reach_foreign` (U lane: targets without a proof) | `≤ min(σ·U_src, |T|·U_acct, U_glob)`; without `U_acct`: `≤ min(σ·U_src, U_glob)` | `DERIVED`; `U_acct` part per D4 |
| `reach_proof` (P lane on accounts whose proof it holds — stolen or own) | `≤ min(Σ_a min(P_acct, h_a·P_proof), P_glob)`; with `P_src`: also `≤ σ·P_src` | `DERIVED`; D3, D5 |
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
| 2 | D1 / LOW cross-IP | A common UA is not one identity | No dilution punishment; each user is its own source; refusal only if `U_glob < D_glob` or the user's own `U_acct` is exhausted | **PASS** conditional on `U_glob` sizing (D5) | `MEASURED` D1 596/600 (S0N) → 0/600 when the cohort is no longer one fingerprint (#125, `main@9e95f2f`). Attacker side: X2 (new IP and victim per attempt) is bounded only by `U_glob`; without it, 121/121 — S0's best-response reach (UA rotation, #125); the S0 naive one-UA cell was 6/121 |
| 3 | Office day 0 | What happens with no prior proof | Every employee is U; refusals `≥ N·d − U_src` per window; without D3 Login has no way out of U | **RESIDUAL** (indistinguishable from an attacker at the same source) | `MEASURED` S0 context: 86–88 of 100 office browsers without a successful login per day (#118 R8, `main@a6c79f4`); day-0 gives nothing to known-device relief (#119 R13) |
| 4 | Shared NAT + attacker | Who can drain which share | `U_src`: anyone at the source; `U_acct(a)`: anyone anywhere; `U_glob`: anyone; `P_acct(a)`: only holders of a proof for `a`; office P shares untouchable by the attacker | **RESIDUAL** for U, and `U_src` itself needs D7 (`P§6.7`); **CONDITIONAL** (D3, D2) for P | Same-IP office and rotating attacker give identical observables (#105 A06; #125 same-IP tally). No credit, so a co-located attacker gets at most `U_src` — unlike #116 where it used colleagues' credit (`MEASURED` E3 FAIL, MIX/X4/X6 +2) |
| 5 | UA / id rotation | No new units | No scope keyed on UA or id (I3). K5 derivation is unchanged, so a UA change gives a known device a new K5; across UA changes it stays bounded by the fingerprint-independent DEC-021 allowance and then by its lane's K4U / K4P scoring (`P§4.5`), and by `P_proof`, keyed on the proof reference | **PASS** for capacity; per-device K5 history does not survive a UA change (as today, `MEASURED` UAC 8/12, #125) |
| 6 | Multiple IPs | Limits beyond one source | Foreign U reach `≤ min(σ·U_src, |T|·U_acct, U_glob)`. One target: `U_acct` per window regardless of `σ`. IPv6: a `/48` holds 65 536 `/64` sources; the hierarchy is `UNPROVEN`. IPv4 exact: CGNAT users share one `U_src` | **CONDITIONAL** (D4, U-2) for one target; **RESIDUAL** for many targets without `U_glob` | `MEASURED` S0 BRD (one account, new IP per attempt) checkOnly-only best response 22/100 victim guesses (#125). Under U/P: `≤ U_acct·(⌈T/W⌉+1)` independent of outcomes (`DERIVED`, conditional) |
| 7 | 12 trusted accounts | Multiplication; total verifier cost | P reach `≤ min(12·P_acct, P_glob)` per window (per account `≤ P_acct`); with `P_src`, `≤ P_src` | **RESIDUAL** without `P_src`; D5 | `MEASURED` S0 TMSd 2/200; when only per-account DEC-021 bounds remain, TMSd 2 → 60/200 and gain grows with accounts held (#119 R3, R6, model on the real pipeline); office N12T ≡ attacker TMS request for request (#119 M3). In `pre`, relief variants reached 200/200 (#119 R4); here `pre` is bounded by `P_acct` per window (`DERIVED`) |
| 8 | Target-account harassment | Owner keeps a usable share | Owner with a proof: `P_acct` / `P_proof` and K4P untouched by U (I4a), and device-level state untouched unless U presents the device's `sessionDeviceId` (I4b); another holder of a proof for the account can still penalize the proven lane, and historical-known U requests can exhaust the shared allowance (`P§7.2`). Owner without a proof: case 1 or case 2 of §2.9, chosen by D4 | **CONDITIONAL** (D2, D3) for proven owners; **RESIDUAL, proven** (§2.9) for unproven owners: renewable refusal under U-1/U-2 (as S0's K4 score), source scaling under U-3 | `CODE` today: K4 blocks reach the owner's trusted session (`P§7.1`); not measured |
| 9 | Anonymous API | What can be proven | Recommended (`P§9.2`): K1 per-source window capacity and optional per-class ceiling, nothing persisted or escalated, no IP + UA sub-limit, no attribution. Today K1 L3, K3 L2 and the LOW-K3 → K2 remap are persisted, renewable shared penalties (`P§9.1`) | **PASS** (bounded capacity, under D1 and D7 — the K1 ceiling is an IP-only refusal, `P§6.7`); **RESIDUAL** (a heavy co-located client exhausts the shared K1 window; no per-person fairness without an identifier) | `MEASURED` D1API 596/600 → 0/600 once the cohort is not one fingerprint (#125) — correlation-caused; the rate paths' collateral is `UNPROVEN` in size. K2's false sharing: two clients on one IP and UA needing 80 calls each lose 40 to K2 = 120 (`P§9.2`, `DERIVED`) |
| 10 | Missing outcomes | What the library guarantees | Per-window bounds hold (I9); no escalation without outcomes; `out` has no pre-verifier bound | **PASS** (`pre`, `pre+out` with drops); **FAIL** (`out`, by call order) | `MEASURED` S0 D24K1 `pre` 1440/1440 (#119 R16) |
| 11 | Redis failure / rotation | Bounds stay correct or weaken explicitly | Rotation: dual-generation sum keeps I1 if interval ≥ `W`. Failover: lost acknowledged reserves re-issue capacity. Outage: DEC-015 degraded mode uses process-local counters without lanes | **DEGRADED, explicit** (§6) | `CODE` `LocalFallbackLimiter.php:37-43`; `MEASURED` #116 O6: a failing admission operation produced 8 `DEGRADED_MODE` ALLOWs where S0 kept blocking (`main@72f6dea`) |

---

## 6. Storage feasibility (B3)

### 6.1 Proposed contract (description only; no interface is added)

| Operation | Tier | Input | Effect | Failure |
| --- | --- | --- | --- | --- |
| Reserve | T0, T1 | class, list of `{key, capacity, previousKey?}`, caller time; T1 adds a permit `{id, lane, subject, operation, deadline D, grace G}` | All-or-nothing increment of every key (T1: plus a permit in state `ISSUED`); or nothing | Typed backend failure for availability; malformed state is a `RateLimiterException`, never a backend failure (DEC-015 provenance), and leaves no partial write |
| Claim outcome | T1 | permit id, expected `{lane, subject, operation}`, outcome, caller time | Compare-and-set `ISSUED → CLAIMED`; returns `accepted`, `duplicate`, `conflict`, `rejected`, `late` or `unknown` (§2.3) | Same |
| Read (operational) | both | keys | Counters and remaining window for operators | Read-only |

### 6.2 Single Redis

One Lua script per reservation validates every key's type (and, with T1, every permit field) **before the first write** (the #116 F116-02
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

T0: outcomes are unbound and scored per call, as today. T1: one compare-and-set per outcome, scoring after it; the guarantee is
at most one scoring per permit, modulo claims lost in a failover; exactly-once is not offered (§2.3). Putting the scoring inside
the claim script would have to absorb DEC-007's generation-bound K4 mutation, which already has its own atomic primitive;
combining them is `UNPROVEN`.

### 6.6 TTL and cardinality (F126-05)

Per verifier class and window `j`, with `A_j` admissions, `s` scopes per admission and `p = 1` for T1 (`0` for T0):

| Item | New keys in window `j` | Lifetime |
| --- | --- | --- |
| Admission counters | `≤ s·A_j` (a scope key is created by the first admission on it in the window and reused after) | `W` plus the previous generation's remainder (`≤ 2W`) |
| Permit records (T1) | `= A_j` | `D + G`, which may overlap the next window |
| Hint sub-limit keys | `≤ 50` per source (existing cap, `EphemeralBucket.php:21`); later clients use the parent only, no shared overflow | `W` |
| P device-cap entries (`P§7.3` P-3) | `≤` the device secrets the Host issued per account | `W` |
| Refused requests | `0` | — |

Live admission keys at any instant: at most the counters of the two windows alive plus the permits issued in the last `D + G`
seconds. `A_j ≤ U_glob + P_glob` (plus `A_glob`, `R_glob` for API) **only when those ceilings exist.** Without them `A_j` has
no fixed ceiling: an attacker with `σ` sources and random subjects obtains up to `σ·U_src` admissions per window — `U_acct`
does not stop it, because every random subject is a new scope — and each admission creates up to `s + p` keys. Storage then
grows linearly with the admission rate obtainable across sources: the same order as today's per-source K1/K2 keys and
per-subject K4 keys, but not "bounded" in the absolute sense.

Outside this count: correlation sets kept as observations (existing per-set caps: spray 5, churn 3, dilution 6, device caps
10 / 50) and outcome-scoring keys (K4, K5, budget, allowance, watchers), which are created per scored subject as they are today.

### 6.7 Old and new workers

Old workers do not reserve, so their verifier calls are not counted: in a mixed fleet the bound is `c_x + calls through old
workers` (`UNPROVEN` in size). Old workers also keep writing K1/K2/K3 correlation blocks, which new workers honour read-only to
expiry, so collective blocks continue while mixed. `PROPOSED` fence: an operator-set admission epoch enables the new path only
after the fleet is upgraded.

### 6.8 Rollback

S0 ignores admission counters and permits; they expire by TTL. K4U (= the legacy K4 key) and K5 keep their keys and derivations (`P§7.2`, `P§4.5`),
so their history survives; K4P (P-lane account penalties) is lost on rollback, at most its TTL, and S0 again enforces it on every requester. Rolling forward within the same window finds the old counters; traffic
admitted by S0 meanwhile was not counted, so that window's bound becomes `c_x + S0-phase admissions`. Nothing is re-issued
beyond that (`DERIVED`).

### 6.9 Failover and lost writes

With asynchronous replication, a promoted replica can miss acknowledged reservations and permits. `DERIVED`: counters fall back
and up to `L` units are re-issued, `L` = reservations acknowledged within the replication lag before the failover; lost
permit records make their outcomes `unknown` (unscored), and a lost claim allows a second scoring (§2.3). `WAIT` narrows `L` but does not give linearizability. I1 becomes "per window, modulo
`L` at each failover"; the size of `L` is `UNPROVEN`.

### 6.10 DEC-015 degraded mode

While the circuit is open no store call happens, so no admission is possible. Login and OTP fall to `FAIL_CLOSED` and then
`DEGRADED_MODE` with process-local counters (`RLE:308-345`); API to `FAIL_OPEN` with its local limits. `DERIVED` from `CODE`
(`LocalFallbackLimiter.php:37-43`, static per PHP process): admissions per account during an outage `≤ N_proc · 3` per 600 s
under `AUTHENTICATION_PRIMARY`, with no lanes, so owner protection is absent. Keeping the U/P bounds through an outage would
need either `FAIL_CLOSED` throughout (availability cost) or a change to DEC-015/DEC-011 — D6. A partial failure of the
admission operation must take the typed failure path for the whole request, never a partial allow (#116 O6 lesson).

---

## 7. Falsification (B4)

| ID | Attack | Result | Structural gap |
| --- | --- | --- | --- |
| B4-1 | Sybil identities (UA, client id, fingerprint, cookie, session churn) | **Holds** (I3): no parent keyed on them; sub-limits only refuse | none, given C3 |
| B4-2 | Attacker with valid accounts | Own accounts: `reach_own` bounded by P/U scopes, no transfer to foreign targets (I8; no refunds, so #116 Y6b's washing cannot occur). X7-style padding on own accounts consumes the attacker's own scopes only | aggregate own-account cost counts toward totals; bounded only by globals |
| B4-3 | Drain U to deny new users | **Succeeds by design**: `U_src` by a co-located attacker; `U_acct(a)` by anyone (if adopted); `U_glob` by a distributed attacker denies every unproven user of the class, renewably each window | inherent; the price of a finite U bound. Owner must accept or drop the scope (D4, D5) |
| B4-4 | Drain P to deny proven users | `P_acct(a)`: only with proofs for `a`; one stolen proof is held to `P_proof` (`P_acct ≥ k·P_proof`). `P_glob`: an attacker with enough own proven accounts (`m ≥ P_glob / P_acct`) denies every proven user | `P_glob` turns account registration into a lane-wide denial lever (D5) |
| B4-5 | Global ceiling as a denial lever | Any finite `U_glob`/`P_glob` that binds before the verifier saturates converts verifier overload into refusal of every legitimate user of the lane; without it, distributed many-victim attacks have no aggregate bound (X2: 121/121 in S0 best response) | trade-off, not solvable by identity; D5 |
| B4-6 | Bypass the global by splitting operations or policies | State is namespaced per policy (`EP:2607`); login, re-authentication and password change may all call one verifier and may all be `login_protection` or customs (DEC-008 context). Per-policy ceilings multiply by the number of policies | needs the verifier-class grouping of `P§6.1`, which is new configuration (LT-5) |
| B4-7 | Concurrent requests | One linearizable script ⇒ no over-admission (I1). Punishment reads are not atomic with the reserve; extra in-flight requests still hold counted units (§2.4). Cluster with compensation: no over-admission, transient false refusals (§6.3) | Cluster throughput (one slot) |
| B4-8 | Re-issue on rotation or rollback | Rotation: safe if interval ≥ `W` (§6.4); two rotations in one window re-issue `c_x`. Rollback / roll-forward in one window: `+` S0-phase admissions (§6.8) | operational preconditions, not code |
| B4-9 | Proof attributed to another account | Library refuses a proof whose account or operation differs (C8). A Host that mis-binds proofs defeats it | Host compliance (`MEASURED` control SPV 4 → 200/200, #119). Contrast S0: a Host-attested trusted session spraying 200 fresh accounts reached 200/200 (SP5, #108); with a proof that names its own account, those requests are U for each target and bounded by `U_src` (`DERIVED`) |
| B4-10 | Unintended loss of K4 protection | Removed on purpose: U evidence no longer penalizes the proven lane (account level: K4U/K4P; device level: P-1 … P-3), and P evidence no longer hardens the U lane (it hardens K4P). Kept: every S0 K4 mechanism on U (K4U = legacy K4); the same mechanisms on P through K4P; DEC-021 K5 derivation and thresholds and the shared allowance unchanged, overflow to the failing request's lane key; P caps per account and per proof, which also hold in `pre`. Distributed-account and flood no longer observe proven devices (`P§7.3` costs). Rejected because they lose protection: M-C and M-G (`P§7.5`) | the shared-allowance coupling (`P§7.2` item 3); own-device K5 coupling (`P§7.3`); K4P lost on rollback (§6.8) |

---

## 8. Residuals that cannot be solved in scope

1. **Co-located indistinguishability.** At one source, an unproven office and an attacker present the same observables (#105
   A06, #125). `U_src` trades one against the other; no identity input changes that without a proof.
2. **Proofless Login.** Without D3, every Login user is U every day (GA-1); owner protection does not apply to Login.
3. **Renewable denial of unproven owners.** Proven unavoidable for any per-target bound independent of attacker sources (§2.9),
   S0's K4 score included; the alternative (U-3) scales with attacker sources.
4. **Global ceilings.** Either a lane-wide denial lever or no aggregate bound against distributed many-victim attacks.
5. **Linear reach in proofs held** (stolen sessions), unless `P_src` re-creates the source-scoped bound that also hits a trusted
   office (D5).
6. **Address granularity.** IPv4 exact (CGNAT shares one source); IPv6 `/64` (a `/48` holder has 65 536 sources).
7. **Record-only Hosts** get no pre-verifier bound.
8. **Outage and failover** weaken bounds to per-worker local limits and to "modulo lost writes".
9. **Host compliance**: one admission per verifier call (H1) and proof binding (C8) cannot be verified by the library.
10. **Fixed-window edges**: up to `2·c_x` in any interval of length `W`.
11. **Mixed fleet and rollback windows**: bounds `UNPROVEN` while old workers serve traffic.
12. **Shared DEC-021 allowance**: historical-known U requests can exhaust it, removing the `A_kd` grace for the owner's own
    known-device P failures (`P§7.2` item 3); another holder of a proof and device secret for the account can penalize its proven
    lane (item 4); a U request presenting a proven device's `sessionDeviceId` shares its K5 (`P§7.3`).
13. **Source-only refusals** (`U_src`, anonymous-API K1) conflict with the LOCKED `KS` §2 unless D7 amends it (`P§6.7`).

---

## 9. What a prototype must measure before any GO

Paper cannot settle these; each needs the isolated comparative study the Owner may authorize at gate G1 (`P§11`, R-1), with
thresholds swept as parameters:

* Replay of the merged streams (D1, L1, OFF12, ABA_A/ABA_B, D2, Nu4/Nu12, N12T, TMS/TMSd/TMUA, SP1n/SP3/SP6, SP5, X1/X2/X7,
  BRU/BRD, D24K1) in `pre`, `pre+out`, `out` and mixed routes, reporting per stream: `reach_foreign`, `reach_proof`,
  `reach_own`, total verifier operations, legitimate denied by reason (capacity vs punishment), and per-window maxima — judged
  against I1–I10, not against S0 parity.
* Barrier-synchronised concurrency on real Redis with mutation (remove atomicity; the test must fail), replayed and
  out-of-order and conflicting outcomes, expired and unknown permits (T1).
* Rotation (one and two within a window), mixed fleet, rollback and roll-forward, fault injection for lost replies and failover
  with acknowledged-write loss, DEC-015 outage.
* Office multi-day bootstrap with and without a proof contract; CGNAT and IPv6 hierarchy cells.
* LT-1: M-A against S0 as a comparison control (and M-C optionally) on six scenarios — S1 a stolen proof failing over days,
  S2 the same with UA rotation, S3 OTP step-up by an attacker who knows the password, S4 several proofs for one account, S5 the
  owner's successes mixed with a stolen proof's failures, S6 the owner's own typos — plus historical-known U failures exhausting
  the shared allowance and concurrent P admissions in flight (`P§7.4`), reporting `reach_foreign`, `reach_proof` and owner
  denials; and DEC-007 / DEC-021 regressions (`KnownDeviceAntiEquilibriumScopeTest` scenarios).
* API: K1-parent-only against K1 + K2 (+ LOW-K3) window sub-limits, and against today's score penalties, on shared-NAT and
  heavy-client streams.
* Device-level isolation: the nine paths of `P§7.3` with U requests sharing and not sharing a proven device's
  `sessionDeviceId`, including flood, distributed-account and device-cap exhaustion, on new and old workers.
* Source capacity ceiling: SC-1 … SC-6 of `P§6.7`.
* T0 integration: probe factor `ρ`, abandoned admissions and retries of real Host flows on the opt-in variants.
