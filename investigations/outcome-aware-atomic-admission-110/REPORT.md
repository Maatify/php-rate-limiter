# #110 Outcome-Aware Atomic Admission (OAAA) — report

**Status: STUDY / EVIDENCE ONLY. Study Only — No Production Runtime Change.** Baseline `main@72f6dea8ab0729e63492b622e682e334c527c966`.
DEC-019 ACTIVE; no DEC-020; #97 / #100 / #106 / #110 open. Every number below is `MEASURED` on a real runtime (S0 = repository tree,
OAAA = pinned isolated tree) and is reproduced from `expected/` by `run.sh`, unless it is explicitly marked `DERIVED`, `UNMEASURED`,
`UNPROVEN` or `CONTRACT_BLOCKED`. The full generated tables are in [`expected/summary.md`](expected/summary.md); the gates in
[`expected/gates.jsonl`](expected/gates.jsonl). The design is in [`ARCHITECTURE.md`](ARCHITECTURE.md), the feasibility verdict in
[`CONTRACT_FEASIBILITY.md`](CONTRACT_FEASIBILITY.md), the pre-registered envelope and decision rule in [`SECURITY_ENVELOPE.md`](SECURITY_ENVELOPE.md)
(all three committed in `d7c8623` before the full run).

## 0. Answer to the final engineering question

> Can a simple optional mode of a general PHP library use trusted authentication outcomes to reduce shared-NAT collateral in #97 / #100 /
> #106, with an atomic pre-verification reservation that stops concurrency leakage and keeps extra attacker attempts inside a small, explicit,
> measured budget?

**Architecturally yes, with an optional contract extension; under the Lead's provisional envelope, as parameterised here, no.**

* The mechanism works as specified and is measured: one all-or-nothing reservation per request over every fired scope + day tier + global
  tiers (no GNH5 over-spend: `a06` C3 0 units taken in 3/3 rounds), exact under real concurrency (`a06` C1: exactly 1 of 16 with one unit
  left, 3/3 rounds; C4: exactly 12 of 16 at the global cap), replay-, duplicate-, late- and cross-account-safe (`a02` L6–L11, `a06` C6/C7),
  rotation-safe (no reset, no write to a previous generation: `a03` R2, `a06` C8), bounded storage (`a04` S1/S2, `a05` Q2).
* A pure attacker (no Host-verified success in its scopes) is **request-for-request S0** in every one of the published protection cells
  (SP1n 4, SP3 7, SP6 20, SP2 60, SP4 78, ABA_A 3, TMSd 2, XN1 4, D7 4, …: Δ = 0); `pre`-only Hosts, `API_OVERUSE`, account-less requests and
  stores without the capability are byte-identical to S0 (O1, O2, O3 PASS).
* The price is paid by **co-located attackers and attackers with a few own accounts**: up to 2 units per scope per 600 s and 6 per scope
  per day (measured worst Δ +6 over a 3 h stream, OWN2 / OWN4; +2 to +5 in co-located NAT cells). In S0 = 0 cells that is +2 per 600 s,
  which **exceeds the Lead's provisional +1 / 600 s (E3 FAIL)**.
* The pre-registered forbidden row E4 and the provisional row E1 FAIL on the two #104 poisoning streams whose "attack" requests are 100 %
  **successful logins on attacker-owned accounts** (Y6b 2 → 36/36, Y4d 1 → 3/3). OAAA returns the unit of every verified success, so such
  requests are never stopped; no credential is guessed and the poisoned legitimate client is protected better (Y6b legitimate denials
  28 → 0), but by the rule as registered this is a full-reach cell. Restricted to failing attempts (post-hoc view, not a gate) there is no
  collapse anywhere and the worst Δ is +6.
* Fairness: decisive for #100 (ABA_B 118 → 0) and for the K1 offices of #106 (Nu4 / Nu6 / Nu12 47 → 0, N12 56 → 6); partial for D2
  (117 → 70) and MIX (117 → 78); **minimal for #97 D1 (596 → 579)** while #97 L1 goes 127 → 0. Only `pre+out` (and record-only) Hosts gain
  anything; `pre`-only Hosts gain nothing.

**Final engineering verdict: `NO-GO`** (pre-registered rule: forbidden row E4 FAIL; provisional rows E1 and E3 FAIL). Any further step would
in addition be **`CONTRACT_CHANGE_REQUIRED`**: OAAA cannot be expressed with the existing Store contracts (CONTRACT_FEASIBILITY.md §2), and an
Owner-approved optional Store capability is a precondition for any adoption.

## 1. Provenance

| | |
|---|---|
| Baseline | `main@72f6dea8ab0729e63492b622e682e334c527c966` (= `origin/main` at start and at delivery) |
| S0 runtime | repository `src/`: pipeline `4b259d3397adf492d9767572141c9bdf5efc5744886bce63592ae9f36b95f4fc`, Redis adapter `520574d4b0758470c0fc36ec304001825cf20a183e28bf6a999bcd41d54297a4`, no experimental interface |
| Patch | `patches/oaaa-candidate.patch` sha-256 `e8032f5de23e7185d49daa371c08dc56d7edf723268b24e2d0ece3ff24639aac` (zero-context, 3 files, +540 / −5) |
| OAAA runtime | pipeline `2af6f5599c809a829c0a7c792e35e49dc9e777482f2560250fa60f59067ffa20`, Redis adapter `a8af48c7661f1a466fe12416530598f7677583ae3683f7dd4a1eb3456002977f`, interface `8db98be51202774d48f540e577b8619895bf6046f45ae3b8e00d8f16058942e2` |
| Patch history | the first run used a functionally identical patch (pipeline `46d7e2d2…`, interface `b930b8c3…`); PHPStan level max on the candidate then found that the OAAA helpers had been inserted between `candidate()`'s docblock and its signature, and one over-narrow `@param` on the interface. Both fixed (doc / placement only); the rerun is byte-identical on every measurement, gate and the summary — only the 7 OAAA provenance rows changed |
| S0 reproduction | the published invariants (D2 117/121, SP1n/SP3/SP6 4/7/20, L1 127, D1 596, X1/X2/X7/Y9b, ABA_A 3, ABA_B 118, TMSd 2, TMX 200, Nu4 47, SP2 60) **and** every one of the ≥ 300 cells in common with the merged #115 S0 golden (fail-closed) |
| Matrix | 89 streams, 355 cells per arm (Login 3 flows, OTP, Login-anon, DEC-008 custom Login, API, custom API) |

## 2. Security (attack cells; Δ = OAAA − S0 attack requests reaching the Host verifier)

### 2.1 Gates

| Gate | Status | Measured |
|---|---|---|
| E4 forbidden: no full collapse | **FAIL** | `Y6b` pre+out 2 → 36 / 36, `Y4d` pre+out 1 → 3 / 3 — both streams' attack requests are all successful logins on attacker-owned accounts |
| E5 forbidden: no replay / concurrency unbounded admission | **PASS** | `a02` L6–L11, L21, L22; `a06` C1–C8, real Redis, 3 rounds each |
| E6 forbidden: enforceable cross-scope aggregate | **PASS** | 29 multi-scope cells: worst aggregated Δ per fixed 600 s = 2, worst total Δ = 6 (OWN4, 8 IPs, 3 h); `a02` L13: 12 of 14 at the global cap; `a06` C4: 12 of 16 |
| E1 provisional: ≤ +6 / scope / 600 s | **FAIL** | worst 12 (`Y6b`, successful own-account logins); every other cell ≤ 3 |
| E2 provisional: ≤ +2 / burst | **PASS** | single-scope bursts C1 / C2 / C3: +1 / 0 / 0 in every round |
| E3 provisional: S0 = 0 cells ≤ +1 / 600 s, total ≤ 6 / scope | **FAIL** | 12 S0 = 0 cells; 5 exceed +1: MIX +2, X4 +2, X6 +2, Y4e +2 (total 4), Y4f +2 (total 4) — all co-located attackers failing next to credited users |

### 2.2 Who gains what (pre+out, Login)

| Attacker | Examples | Δ reach | Why |
|---|---|---|---|
| Pure attacker, no verified success in its scopes | SP1n, SP1f, SP3, SP6, SP2, SP4, SP4u, SP4b, ABA_A, K1R4, D7, XN1/XN1n/XN1k, TMS/TMSd/TMSs/TMR, Y1, Y2a, Y7, Y9a/Y9b, X1, X2 | **0** (request-for-request S0) | no credit → effective cap 0 (I1) |
| Attacker with own accounts (credit farming) | OWN1 +1, OWNK2 +1, OWN6 +2 (1 h), OWN2 +6 (3 h), OWN4 +6 (8 IPs, 3 h), D5 0 | ≤ 2 / scope / 600 s, ≤ 6 / scope / day | credit set capped at 2 accounts; day tier 6 |
| Co-located with a credited office | MIX +2, MIXT +1, MIXU +2, MIXN +5, TPAIRT +4, TPAIRM +4, BURN +4, X4/X6 +2, Y4c +2, Y4e/Y4f +4, Y6c +2, X7 +1 | ≤ 2 / scope / 600 s, ≤ 6 / scope / day | uses the colleagues' credit |
| Identical tuple (stolen MEDIUM fingerprint / LOW) | TGTM +3, TGTL +5, TGT +3 (distinct tuple) | as co-located | same-tuple refund ≤ 2 per colleague success (MEDIUM only) |
| Trusted-session attackers | SP5 200 → 200, SP5c 2 → 2, TMSd 2 → 2 | 0 | K1 advisory untouched; no credit from failures |
| Own-account successes only (no credential guessed) | Y6b +34, Y4d +2 | all pass | a verified success always returns its unit |

Windows: worst Δ per 60 s = 3 (Y6b), otherwise ≤ 2; worst rolling 600 s Δ = 12 (Y6b), otherwise ≤ 5; worst Δ per fixed 1 800 s = 34 (Y6b),
otherwise ≤ 5. Requests S0 denied and OAAA let reach the verifier ("extra while S0 blocks") equal the net Δ except in 11 cells where
OAAA also denies some attempts S0 admitted (path shift): MIXT 4 for net +1, MIXN 7 / +5, TPAIRT and TPAIRM 5 / +4, BURN 8 / +4, OWN6 11 / +2,
OWNK2 2 / +1, TGT 9 / +3, TGTM 10 / +3, TGTL 7 / +5, MIXN OTP 7 / +5. The gross figure is the one to use for "which attempts got through
because of OAAA"; the net figure for "how many more in total".
Record-only (`out`) cells: every attempt is verified before the library is called in both arms, so OAAA cannot add or remove reach there.

### 2.3 Concurrency (real Redis, separate processes, one Redis instant)

| Case | S0 admitted | OAAA admitted | Evidence |
|---|---|---|---|
| C1 one unit left, 16 at once | 0,0,0 | **1,1,1** | scope ends at exactly 2 units |
| C2 no unit left, 16 at once | 0,0,0 | 0,0,0 | |
| C3 K1 credited + K2 no credit, 8 at once | 0,0,0 | 0,0,0 | **0 units taken on K1 and global** (no over-spend) |
| C4 8 credited scopes, 16 at once | 0,0,0 | **12,12,12** | global epoch tier = 12 |
| C5 16 credited colleagues, pre-check + success at once | 0 | ≥ 2 (racy) | units never > 2 or < 0; global mirrors scope; a unit stays spent only for an admitted attempt whose success met the S0 block persisted by the first refusal — a **legitimate-concurrency limit** |
| C6 one success replayed by 8 workers | — | — | resolved once: 2 → 0 units |
| C7 expiry during verification (simulated `PEXPIRE` on own keys) | — | — | late success returns nothing; never into a new epoch |
| C8 rotation with 2 open old-generation reservations, 16 at once | 0,0,0 | 0,0,0 | old generation stays at 2 (never written, never reset) |

## 3. Fairness (Login, legitimate requests denied, S0 → OAAA)

| Issue | Stream | pre+out | pre | out |
|---|---|---|---|---|
| #97 | D1 (LOW population) | 596 → 579 | 595 → 595 | 595 → 49 |
| #97 | L1 | 127 → 0 | 124 → 124 | 124 → 0 |
| #97 | L1f | 127 → 96 | 124 → 124 | 125 → 56 |
| #97 | X2L | 29 → 29 | 29 → 29 | 29 → 2 |
| #100 | ABA_B | **118 → 0** | 117 → 117 | 117 → 0 |
| #100 | ABA_B1 | 118 → 0 | 117 → 117 | 117 → 0 |
| #100 | GAP | 50 → 30 | 49 → 49 | 49 → 0 |
| #100 | N12 | 56 → 6 | 55 → 55 | 55 → 2 |
| #106 | Nu4 / Nu6 / Nu12 | **47 → 0** each | 47 → 47 | 0 → 0 |
| #106 | D2 | 117 → 70 | 115 → 115 | 115 → 13 |
| #106 | MIX / MIXN / MIXT | 117 → 78 / 49 → 24 / 58 → 22 | unchanged | 115 → 34 / 0 → 0 / 55 → 17 |
| #106 | N12T | 56 → 6 | 55 → 55 | 55 → 2 |

* **#100 is the clear win**: the K2 churn / WATCH / block-renewal loop disappears for successful users (ABA_B, ABA_B1); block rearm after 60 s
  is not reached because no correlation block is persisted while units last. Persistent historical blocks are still enforced (`a02` L16).
* **#106 K1 offices** (stable devices) are fully freed; offices with a fresh fingerprint per login (D2) are only partly freed — every login
  is a new tuple, typos are never refunded and 6 unrefunded failures exhaust the scope for the day.
* **#97 is mixed and must not be read as solved**: L1 127 → 0, but the D1 LOW population barely moves (596 → 579): its LOW dilution scope is
  one device-anchored scope shared by the whole population and its legitimate typo volume (≈ 48 in 10 min) exceeds the 2 / 600 s, 6 / day
  units; LOW tuples get no same-tuple refund.
* F2 PASS: no cell denies more legitimate requests in total; 59 individual legitimate requests across all non-API cells are newly denied
  (path differences, e.g. D2 2, MIX 3, L1f 5), always outweighed in their cell.
* Host probe (`a07`, public Builder over Redis): office 38 → 28 of 40 denied, co-located sprayer 0 → 2 of 20 verified; the pre-only Host is
  identical to S0.

## 4. Operational

| Gate | Status | Measured |
|---|---|---|
| O1 pre-only = S0 | PASS | every `pre` cell request-for-request identical |
| O2 API / custom API / Login-anon = S0 | PASS | identical; no OAAA store call |
| O3 store without capability = S0 | PASS | D2 / ABA_B / SP1n / OWN1 identical |
| O4 bounded storage | PASS | credit sets ≤ 2, TTL ≤ 86 400 s, tuples ≤ grants, nothing alive after a day; every Redis key has a TTL |
| O5 rotation | **FAIL** | D2 under outer-secret rotation: OAAA +3 legitimate denials vs S0 +0 (tuples and current credits restart with the new secret); all attacker cells: no extra reach from rotation; R2 / R3 / H1 / H2 PASS |
| O6 DEC-015 | **UNPROVEN** | a failing OAAA operation: FAIL_CLOSED, then **8 DEGRADED_MODE ALLOWs where S0 keeps blocking** (OAAA-specific partial-outage regression); whole correlation-store outage: identical on both arms (10 DEGRADED_MODE ALLOWs). The bound is a healthy-backend property |
| O7a rollback / mixed: no exception | PASS | reference store (6 histories, 4 mixed) and Redis (Q4, Q5) |
| O7b S0-family state parity | **FAIL** | identical only for ABA_A; D2 / MIX +14 keys and 5 differing values, OWN1 +10 / −8 keys, Nu12 +8 keys, ABA_B 1 differing value |
| O7c rollback decision parity | **FAIL** | ABA_B, D2, MIX: 2 decisions differ; ABA_A, XN1, OWN1: 0; mixed D2 legit denied 22 vs 29 (S0-only) |
| O7d roll-forward | PASS | reservations taken before a rollback stay spent; no phantom refund, no reset |
| O8 Host integration | PASS | public Builder + `limit()` on Redis, identical adapter code; no new Host call |
| O9 provenance / integrity | PASS | |
| O10 public surface | PASS | only the experimental interface and its two methods on the Redis adapter; pipeline, engine, command, context, result, Builder unchanged |

Malformed OAAA state on Redis is an explicit `RateLimiterException` (never a `BackendFailureException`, never a silent zero) and leaves no
partial write (`a05` Q3). Redis Lua and the reference store agree on decisions **and** units for five lifecycle sequences (`a05` Q1).

## 5. FAIL / UNPROVEN / CONTRACT_BLOCKED / UNMEASURED

| Item | Status |
|---|---|
| E4 full reach on Y6b, Y4d (own-account successes) | FAIL |
| E1 Y6b 12 per scope per 600 s (own-account successes) | FAIL |
| E3 co-located attackers +2 / 600 s in S0 = 0 cells (MIX, X4, X6, Y4e, Y4f) | FAIL |
| O5 D2 rotation +3 legitimate denials | FAIL |
| O7b S0-family state parity, O7c rollback decision parity | FAIL |
| O6 bound during an outage (DEC-015 degraded ALLOW) | UNPROVEN |
| Atomic multi-scope reservation, refund and credit gate with existing Store contracts | CONTRACT_BLOCKED (CONTRACT_FEASIBILITY.md §2) |
| Redis Cluster (multi-key script across slots) | UNMEASURED (official adapter is non-clustered) |
| CAP 1 / other parameterisations, a deployment-sized global tier | UNMEASURED (one candidate only, by mandate) |
| Real Host verification latency and real legitimate retry patterns (the streams model typos without immediate retry) | UNMEASURED |

## 6. Owner decisions (none is taken or assumed here)

1. **Do successful logins on attacker-owned accounts count as "attack reach"?** The registered E4 / E1 say yes (Y6b, Y4d FAIL). If the
   Owner rules that only failing attempts count, E4 has no collapse and E1's worst becomes 3 (post-hoc view in `summary.md` §3).
2. **Is +2 per 600 s (≤ 6 per day) per scope acceptable for co-located / own-account attackers in cells S0 stops completely**, versus the
   Lead's provisional +1? A cap of 1 per 600 s would meet E3 but breaks the typo-then-retry pattern (one typo spends the epoch) — UNMEASURED.
3. **Optional Store contract**: approve or reject, in principle, a new optional `OutcomeAdmissionStoreInterface` (two atomic operations) plus a
   policy opt-in. Without it OAAA cannot exist; with it, custom stores keep S0.
4. **Global tier sizing** (12 / 600 s, 48 / day per policy here): an aggregate security bound that a large deployment exhausts with legitimate
   unrefunded failures (then S0 everywhere).
5. **Outage**: accept that the bound does not hold under DEC-015 degraded mode, including the OAAA-specific partial-failure case (8 ALLOWs).
6. **Rollback**: accept that S0-family state and a few decisions differ after rolling back (O7b / O7c), as for every admitting candidate.

## 7. Engineering verdict

**`NO-GO`** for the measured parameterisation (pre-registered rule: E4 FAIL; E1, E3 FAIL), with **`CONTRACT_CHANGE_REQUIRED`** as a
precondition for any variant. What is established and reusable regardless of the verdict: an outcome-linked, credit-gated, all-or-nothing
reservation keeps pure attackers at S0 exactly, is concurrency-, replay- and rotation-safe on Redis, needs no Host change for the documented
flow, and removes the #100 loop and the #106 K1-office collateral; it cannot remove D1-scale #97 collateral, and its residual cost is
concentrated in co-located and own-account attackers.
