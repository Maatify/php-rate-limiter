# Host mode contract for `checkOnly` and mode eligibility of the new policies — decision paper (#97, step 2)

> **Status:** DECISION PAPER for the Owner. It prepares the step 2 decision of the Owner's five-step plan for #97 and takes no
> decision. The options are in [DEC-023](../../docs/decisions/DEC-023_HOST_MODE_CONTRACT_AND_MODE_ELIGIBILITY.md) (`PROPOSED`);
> none is selected. Documentation only: no change to `src/`, `tests/`, `composer.json`, any public contract, preset, threshold
> or value, and no change to the text or status of DEC-019, DEC-021 or DEC-022. No new measurement: every number below is
> quoted from a merged study or decision record, at that source's baseline.
> **Owner decision (2026-10-19, [PR #134 comment 6256657121](https://github.com/Maatify/php-rate-limiter/pull/134#issuecomment-6256657121)):** option A with A-2 and option B deferred, recorded in
> [DEC-023](../../docs/decisions/DEC-023_HOST_MODE_CONTRACT_AND_MODE_ELIGIBILITY.md) (`ACTIVE`); option C as a principle,
> recorded in [DEC-024](../../docs/decisions/DEC-024_OPT_IN_ATOMIC_ADMISSION_RESERVATION_IN_CHECKONLY.md) (`ACTIVE`). The rest
> of this paper, including the matrix and the gates, is unchanged and records the evidence as it stood when the Owner chose.
> **Question.** For each Host mode, what the library can guarantee, what needs a recorded outcome to be guaranteed, and what the
> Host cannot get past. The answer is a contract and capability decision, not a choice of value.

## 0. Terms

| Term | Meaning in this repository |
| --- | --- |
| **`pre+out`** | The Host calls `checkOnly` before the verifier and `recordFailure` / `recordSuccess` after it (the reference Host of every #97 study) |
| **`pre`** (checkOnly-only) | The Host calls `checkOnly` before the verifier and never records an outcome |
| **`out`** (record-only) | The Host calls only `recordFailure` / `recordSuccess`, after the verifier |
| **FULL2** | The assembly measured in G4 v2 (#132): the G4 assembly (the G1 candidate pipeline with the collective K1 / K2 / K3 / correlation penalties removed, `U_src`, net failure cap, per-account bucket with refund, no account-scoped escalation on U, U/P lanes with binding, K5 separation, no known-device routing) plus the P-lane refund budget, sliding renewal and the password-change binding (`g4/integrated_v2/PLAN.md` §2.2, §3; `g4/integrated/PLAN.md` §1) |
| **Reach** | Attacker attempts that reached the verifier (best response where the study reports one) |
| `MEASURED` / `MODEL` / `DERIVED` / `CODE` | Archived harness output / simulation without the pipeline / reasoning with nothing executed / read from the code at `1cc715e` |

`U_src`, `F`, `T`, `C` and every budget appear here only as the study values the cited archives used. This paper states no
value for any of them; values are step 4.

## 1. Today's contract per mode (S0, not changed by this paper)

| Mode | K1 credential spray | K2 cross-account bound (valid sessions for 12 accounts) | DEC-021 known-device bound | Pre-verifier reach | Trusted office (`N12T`) legitimate denied |
| --- | --- | --- | --- | --- | --- |
| `pre+out` | Observed at `checkOnly`: SP1n 4/200, SP3 7/1000, SP6 20/1200 (#108) | `TMSd` 2/200, `TMS` 7/61 (#113); per UA major: 20 majors give 36/200 (#119 F-AKDR-01, `[MAIN]`) | Per account: Login stable known device 45 / 44 / 45 per 24 h (DEC-021, measured in `pre+out`) | Bounded by the rules on the left | 56/61 (#113) |
| `pre` | Same observations as `pre+out`; the outcome command adds none (#108 §5) | `TMSd` 2/200, `TMS` 6/61 (#113) | **Inert:** `D24K1` 1440/1440, `KS1` 200/200 (#119, `[MAIN]`; stated in DEC-021) | Bounded by the pre-check rules (K1 spray, K2 / K3 churn and dilution, distributed-account, new-device flood); no outcome-based scoring (`ARCHITECTURE_PROPOSAL.md` §3.1) | 55/61 (#113) |
| `out` | **None:** 0 observations, SP1n 200/200, SP3 1000/1000 (#108 §5) | **None:** full reach on every stream, e.g. `TMSd` 200/200, `TMS` 61/61 (#113) | Decisions, but no verifier-reach reduction (DEC-021) | **None:** every decision arrives after the verifier (#113, `ARCHITECTURE_PROPOSAL.md` §6.4). Correlation is still observed on record commands (`ARCHITECTURE_PROPOSAL.md` §3.1) | 55/61 (#113) |

DEC-019 Decision 5 makes the `checkOnly`-only and record-only guarantees an explicit condition of any change to this
posture. The matrix below answers that item for FULL2 and its components.

## 2. Preconditions common to every cell

A cell is read **under** these conditions. They apply to every mode, so they are not repeated in the cells; C-7 states its per-mode effect in its own row.

| # | Precondition | Source |
| --- | --- | --- |
| C-1 | **`checkOnly` may reserve** (DEC-023 option C). Every admission component (columns a–g) is an atomic reservation taken at `checkOnly` (`ARCHITECTURE_PROPOSAL.md` §6.2). If option C is not approved, columns a–g are NOT AVAILABLE in every mode, and column h has no measured form, because every measurement of it ran with the admission layer | `ARCHITECTURE_PROPOSAL.md` §6.2, §6.5, GA-5 |
| C-2 | **Store integrity P2, for the T0 admission-reservation state** (G1 names it T0 device-set integrity): no reservation write before the decision and no part of it written on a T0 refusal; previous generation read-only. The original study Redis store failed T1-N4b / N5; the fixed study store passes. It does not cover the observation writes `checkOnly` already makes in S0, where it is not globally read-only: bounded correlation and WATCH observations (`docs/DECISION_MATRIX.md` §5.0, §5.1; `docs/guides/USAGE_GUIDE.md`) | `G1_FINAL_DISPOSITION.md` §3 P2, §4 item 4 |
| C-3 | **Values are the Owner's** (step 4). Every number is a study value | the Owner's five-step plan; DEC-022 Condition 3 (`PROPOSED`, not approved) |
| C-4 | **Runtime proven and reviewed** (step 5) | the Owner's five-step plan; DEC-022 Condition 4 (`PROPOSED`, not approved) |
| C-5 | **Study scope:** reference stores and a controlled clock; no Redis, concurrency or failover; synthetic streams; study-code tokens and a Host model; the password change modelled as a proven Host event | `G4_V2_INTEGRATED_REASSEMBLY.md` §2, §9; `G3_V2_NET_CAP_KNOWN_ROUTING.md` §7 |
| C-6 | **The UA stays in the K5 material** in every FULL2 measurement; UA-free K5 is step 3 and does not depend on the mode | `G4_V2_INTEGRATED_REASSEMBLY.md` §9; DEC-022 Condition 2 (`PROPOSED`, not approved) |
| C-7 | **Degraded mode (DEC-015):** admission bounds do not hold and lanes are absent in fallback. Every command takes the same fallback code path, which does not receive the command, but it consumes its counters once per call. So the per-mode effect differs (`DERIVED` from the code): a `pre+out` attempt makes two calls and uses two fallback units; a `pre` attempt uses one; in `out` the fallback decision arrives after the verifier, so it gives no pre-verifier bound | `ARCHITECTURE_PROPOSAL.md` §10 (D6); `CODE` `src/Service/RateLimiterEngine.php:308-323`, `:418-440`; `src/Service/LocalFallbackLimiter.php:60-73` ("consumed exactly once per evaluation") |

## 3. The matrix

**Cell values.**

- **GUARANTEED** — a merged study measured the property holding in this mode, within that study's scope and under §2.
- **CONDITIONAL** — holds only under the mode-specific condition written in the cell.
- **NOT AVAILABLE** — the mechanism cannot act in this mode, because it needs an input the mode does not give. Where an
  archive exists for the mode, it confirms this.
- **NOT MEASURED** — no merged study measured it in this mode, and it does not follow by construction. Each such cell names
  its gate in §5.

**Columns** (the guarantee or availability that FULL2 or its components give):

| Col | Guarantee | Kind | Component |
| --- | --- | --- | --- |
| **a** | Source capacity ceiling: single-source reach bounded per window | security | `U_src` |
| **b** | Net failure cap: single-IP spray held near S0 | security | `F_src` (net) |
| **c** | Per-account bound: per-target guessing bounded, owner recovery bounded | security | per-account bucket |
| **d** | Bucket refund: success-heavy users and offices with typos on the U door are not refused | availability | refund on recorded success |
| **e** | P door and its budget: strangers never admitted on P; a stolen token bounded on P and revoked by the password change | security | U/P lanes, binding boundary, P budgets, password-change binding |
| **f** | P-budget refund: heavy bound users are not refused on P | availability | P-lane refund budget |
| **g** | Known-device routing: the owner's known device is routed around the bucket (KD / UAC relief) | availability | **not part of FULL2**; G3 v2 `TBK` arms |
| **h** | Binding, owner side: a bound owner is not denied by an attack; bound browsers behind a shared office IP are not refused; the owner's decisions do not depend on a population or on the owner's own U failures. CGNAT (gate C6) is a `MODEL` result and is not part of this column's claim | availability | binding, sliding renewal, K5 separation |

| Mode | a | b | c | d | e | f | g | h |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| **`pre+out`** | GUARANTEED | GUARANTEED | GUARANTEED | GUARANTEED | CONDITIONAL — the Host's password-change / recovery flow is a proven event | GUARANTEED | CONDITIONAL — Host-honest known-device signals | GUARANTEED |
| **`pre`** | GUARANTEED — outer ceiling only | NOT AVAILABLE | NOT MEASURED → NM-9 | NOT AVAILABLE | NOT MEASURED → NM-1 | NOT AVAILABLE ¹ | CONDITIONAL — Host-honest known-device signals | NOT MEASURED → NM-2 |
| **`out`** | NOT AVAILABLE | NOT AVAILABLE | NOT AVAILABLE | NOT AVAILABLE | NOT AVAILABLE | NOT AVAILABLE | NOT AVAILABLE | NOT MEASURED → NM-3 |

¹ **e and f exclude each other in `pre`.** With the FULL2 refund budget, f is NOT AVAILABLE (L1 72). The reported alternative
`P-NETF` gives f in `pre` (L1 0), but only because without outcomes it counts nothing, so e then has no P budget except `N_P`
(`G4_V2_INTEGRATED_REASSEMBLY.md` §5.5: "Neither design gives a checkOnly-only Host both").

## 4. Evidence per cell

Every number is quoted as archived. "S24 `pre`" and "S40 `pre` / `out`" are the source-cap-only reference arms of the same
archives.

### 4.1 `pre+out`

| Cell | Value | Evidence | Class |
| --- | --- | --- | --- |
| PO-a | GUARANTEED | The source reservation needs no outcome. Source cap alone (S24): SP6 144, SP3 48, SP1n 24, ABA_A 48 (G3 v2 §3). With the net cap in FULL2: gate A3 PASS (G4 v2 §4) | `MEASURED` |
| PO-b | GUARANTEED | `FCN4-24`: EXPERIMENTALLY SUPPORTED — LIMITED SCOPE, `pre+out` Hosts only (G3 v2 §2). SP6 24 (S0 20), SP3 8 (7), SP1n 4 (4), ABA_A 8 (3), MIX attack 8 (0); it does not dominate S0 (G3 v2 §4.1). FULL2: A3 PASS, same values | `MEASURED` |
| PO-c | GUARANTEED | FULL2 B1 PASS: BRU24 admitted 51, recount 51, ceiling 52; B2 PASS: owner wait 60 s at δ = 0, else 0; C2 PASS: BRU24-HV 51 (G4 v2 §4). BRU / BRD reach 5 against S0 3 (G4 v2 §5.7) | `MEASURED` |
| PO-d | GUARANTEED | FULL2 A1 PASS: L1 0, ABA_B 0, D2 24, Y6b 0, KD 9, UAC 6, all other benign streams 0 (G4 v2 §4). D2 24 comes from the source caps (`cap:src` 22, `cap:fail_src` 2), not from the bucket (G4 v2 `expected/summary.md`, denial causes) | `MEASURED` |
| PO-e | CONDITIONAL | C3 PASS: CROSS 0 P admissions on victims, reach 4. C4v2 PASS: the thief reaches at most 2 P attempts per window and 9 in 24 h, 0 after the change; U after the change 27 ≤ 28; the owner is admitted at once on P (G4 v2 §4, §5.2). Condition: the password change is modelled as a proven Host event, and the security of that flow is outside the library (G4 v2 §2 scope, §9). Before the change, the thief's P-lane failures cost the owner 2 of 6 P logins (G4 v2 §8) | `MEASURED` |
| PO-f | GUARANTEED | P refund: L1 72 → 0, ABA_B 94 → 0, Y6b 10 → 0; the stolen-token bound is unchanged (G4 v2 §5.1). Necessity shown: `N-PG1` brings back L1 72, ABA_B 94, Y6b 10 (G4 v2 §6) | `MEASURED` |
| PO-g | CONDITIONAL | Not part of FULL2: in G4 and G4 v2 the known-device flags never skip the bucket (`g4/integrated/PLAN.md` §1). Measured on G3 v2 `TBK30R`: UAC 6 and KD 9, equal to S40 (G3 v2 §4.2). Condition: an honest Host. When the Host marks the attacker as known, all 5,760 BRU24 attempts reach the verifier (G3 v2 §4.3). The per-account bound for known devices that would close this was not tested (G3 v2 §4.3) → open item NM-4 | `MEASURED` |
| PO-h | GUARANTEED | C1 PASS: HAR-B 0 owner denials (`R-NO-BIND` 14). C5 PASS: BOOT 0/383 refused; BOOT-X 0 refusals of bound instances (`R-NO-BIND` 28). C6 PASS is `MODEL` and supports the CGNAT case only as a model, outside this cell's claim. E PASS: E2 owner decisions identical with and without the population. K5 PASS: K5U owner rows identical between `fail` and `ctl` (G4 v2 §4). Residuals that are not gates: newcomers next to a co-located sprayer (BOOT-X day 1, 68 of 125 refused, all unbound); a token at its absolute limit during an attack sends the owner to U (G4 v2 §5.7) | `MEASURED`; `MODEL` (C6) |

### 4.2 `pre` (checkOnly-only)

| Cell | Value | Evidence | Class |
| --- | --- | --- | --- |
| PR-a | GUARANTEED — outer ceiling only | FULL2 `pre`: SP6 144, SP3 48, SP1n 24, ABA_A 48, equal to S24 `pre` (G4 v2 `expected/summary.md`, `pre` table). The same Host gets SP6 24 in `pre+out`. "Those Hosts get the outer ceiling only: SP6 144 at 24" (G3 v2 §4.1). At `U_src` 40, SP6 240 in `pre` (G4 v2 §5.6) | `MEASURED` |
| PR-b | NOT AVAILABLE | The net cap counts recorded failures. "The cap is inert without outcomes (FCN-4 PASS: decisions identical to S24 / S40)" (G3 v2 §4.1). The gross cap is inert in `pre` too: FC4 / FC8 `pre` equal S40 `pre` on every stream (G3 v1 `expected/summary.md`) | `MEASURED` |
| PR-c | NOT MEASURED → NM-9 | The bucket admits by reservation, so it needs no outcome to bound. The streams show it in `pre`: BRU / BRD reach 5 in FULL2 `pre`, against 80 for S24 `pre`; X1 4 against 17 (G4 v2 `pre` table; G3 v2 §4.2 "BRU 5 against S40's 80"). The fixtures that carry the guarantee, the per-account bound (BRU24, B1) and owner recovery (REC, B2), were run in `pre+out` only (G4 v2 PLAN §6.3) | `MEASURED` (streams); fixtures not run |
| PR-d | NOT AVAILABLE | "Refunds need outcomes, so a checkOnly-only Host loses success-heavy streams again: L1 122 of 134 and D2 61 under TBK30R" (G3 v2 §4.2). In FULL2 `pre`, D2 61 comes from the bucket (G4 v2 §5.5). With a fixed window instead of the bucket (`R-NO-TB` `pre`): D2 24, but BRU 80 and X1 17 (G4 v2 `pre` table) | `MEASURED` |
| PR-e | NOT MEASURED → NM-1 | CROSS and STEAL ran in `pre+out` only (G4 v2 PLAN §6.3). In `pre`, FULL2's P budget is the G1 budget, which counts admissions (G4 v2 PLAN §3: "Without outcomes (`pre`) nothing is refunded"); the K4P penalties that bound the thief in `pre+out` (G4 v2 §5.1) need recorded failures. With `P-NETF` the stolen token has no P budget except `N_P` (G4 v2 §5.5) | `DERIVED` |
| PR-f | NOT AVAILABLE | FULL2 `pre`: L1 72, ABA_B 94, Y6b 10, unchanged by the refund because nothing is recorded (G4 v2 §5.5). Gate P FAIL: L1 72 (≤ 0), D2 61 (≤ 36) (G4 v2 §2). `P-NETF` `pre`: L1 0, ABA_B 0, Y6b 0, only by giving up e (footnote ¹) | `MEASURED` |
| PR-g | CONDITIONAL | In `pre`, the routing removes the KD and UAC denials that the bucket causes: TB30R `pre` KD 8, UAC 8 (G3 v1 `expected/summary.md`) against `TBK30R` `pre` KD 0, UAC 0 (G3 v2 `expected/summary.md`). The routing skips the bucket, so the Host-violation result of PO-g (5,760/5,760, measured in `pre+out`) applies; in `pre` there is also no K5 scoring to fall back on (DEC-021: check-only Hosts report no failures). Condition: an honest Host. Not part of FULL2 | `MEASURED`; `DERIVED` (Host violation in `pre`) |
| PR-h | NOT MEASURED → NM-2 | Issuance is Host-side, so binding exists in `pre` (`g4/integrated/PLAN.md` §1.1). Streams, FULL2 `pre` against `R-NO-BIND` `pre`: L1 122 → 72, Nu4 41 → 0, Y6b 26 → 10, UAC 8 → 4, KD 8 → 4 (G4 v2 §5.5 and `pre` table). The owner fixtures that carry the guarantee (HAR-B, BOOT / BOOT-X, E2, K5U) ran in `pre+out` only (G4 v2 PLAN §6.3) | `MEASURED` (streams); fixtures not run |

### 4.3 `out` (record-only)

| Cell | Value | Evidence | Class |
| --- | --- | --- | --- |
| RO-a | NOT AVAILABLE | No `checkOnly`, so no admission: "No pre-verifier bound. The result may only gate what the Host does after verification" (`ARCHITECTURE_PROPOSAL.md` §6.4). S40 `out`: SP6 1200, SP3 1000, SP1n 200, ABA_A 120 (G3 v1 `expected/summary.md`). Record-only has full reach on every #113 stream (e.g. `TMSd` 200/200, `TMS` 61/61) | `DERIVED`; `MEASURED` (G3 v1, #113) |
| RO-b | NOT AVAILABLE | As RO-a. The gross cap in `out`: FC8 `out` equals S40 `out` on every stream (G3 v1 `expected/summary.md`). G3 v2 ran no `out` flow (G3 v2 §6) | `DERIVED`; `MEASURED` (G3 v1) |
| RO-c | NOT AVAILABLE | As RO-a. TB30R `out`: BRU 100, BRD 100, X1 61, the same reach as S40 `out` (G3 v1 `expected/summary.md`) | `DERIVED`; `MEASURED` (G3 v1) |
| RO-d | NOT AVAILABLE | No bucket, so nothing to refund. For reference, legitimate decisions after the verifier in `out`: S40 D2 7, KD 9, UAC 6; TB30R D2 0, KD 9, UAC 6 (G3 v1 `expected/summary.md`) | `DERIVED`; `MEASURED` (G3 v1) |
| RO-e | NOT AVAILABLE | The P lane is an admission lane at `checkOnly` (`ARCHITECTURE_PROPOSAL.md` §6.1, §6.4); in `out` there is no pre-verifier P budget. G4 and G4 v2 ran no `out` flow | `DERIVED` |
| RO-f | NOT AVAILABLE | No P budget, so nothing to refund | `DERIVED` |
| RO-g | NOT AVAILABLE | The routing acts on the bucket, which does not exist in `out` | `DERIVED` |
| RO-h | NOT MEASURED → NM-3 | The owner can still be refused after the verifier in `out` (S40 `out` D2 7, KD 9, UAC 6; G3 v1). Whether binding's lane split of penalties protects a bound owner in these post-verifier decisions was never run: G4 and G4 v2 have no `out` flow | — |

## 5. Gates for the cells that are not measured

Each cell gate (NM-1 … NM-4, NM-9) is written so it can be pre-registered unchanged: every threshold is one already
registered in the plan named, and no new value is introduced. The option gates NM-5, NM-7 and NM-10 are templates: the rates and
ceilings they need are values the later plan must register, and none is set here. None of these gates is run in this step.
Each belongs to step 5 or to a separately authorised study.

| ID | Cell | Gate (registrable form) | Detector | Placement |
| --- | --- | --- | --- | --- |
| **NM-1** | PR-e | Run CROSS and STEAL for FULL2 in `pre`, and report `P-NETF` beside it. PASS: the C3 condition and the thief clauses of C4v2, as registered in `g4/integrated_v2/PLAN.md` §6.3, hold in `pre` | `R-WEAKBIND` in `pre` admits at least one victim request on P | The `pre` study of option B, or step 5 |
| **NM-2** | PR-h | Run HAR-B, BOOT / BOOT-X, E2 and K5U for FULL2 in `pre`. PASS: C1, C5, E and K5 as registered in `g4/integrated_v2/PLAN.md` §6.3, evaluated on `pre` decisions | the registered detectors (`R-NO-BIND`, `N-NORENEW`, `N-NOK5SEP`) run in `pre`. Under the registered rule, a detector that does not fire means the gate is not evidenced | The `pre` study of option B, or step 5 |
| **NM-3** | RO-h | Run HAR-B, BOOT / BOOT-X and E2 for FULL2 in `out`. PASS: C1, C5 and E as registered, evaluated on the decisions returned after the verifier. Reach is reported only; there is no pre-verifier bound by construction | `R-NO-BIND` in `out` refuses the owner or a bound instance at least once | Step 5, or a separate study |
| **NM-4** | PO-g, PR-g (open item) | Only if known-device routing is ever proposed again: BRU24-HV with routing plus a per-account bound for known devices, in `pre+out` and `pre`. PASS: admissions within the C2 ceiling registered in `g4/integrated_v2/PLAN.md` §6.3 | the same arm without the per-account bound reaches more than that ceiling | A separate study; not needed for FULL2, which does not route |
| **NM-9** | PR-c | Run BRU24 and REC for FULL2 in `pre`. PASS: B1 and B2 as registered in `g4/integrated_v2/PLAN.md` §6.3, evaluated on `pre` decisions | `R-NO-TB` in `pre` (fixed window instead of the bucket) exceeds the B1 ceiling, as its `pre+out` run does (BRU24 2,304) | The `pre` study of option B, or step 5 |

The options in DEC-023 raise five further questions that the merged evidence cannot settle. They belong to an option, not
to a cell:

| ID | Option | Gate (registrable form) | Detector | Placement |
| --- | --- | --- | --- | --- |
| **NM-5** | C | Host integration under a `checkOnly` reservation: probe factor `ρ`, admissions spent without a verifier call, retries and double submits (`ARCHITECTURE_PROPOSAL.md` §6.5, the LT-2 evidence list in §11.2). Template: replay the G4 v2 benign streams at rates the later plan registers (none is set here). PASS: the A1 condition of `g4/integrated_v2/PLAN.md` §6.3 holds at every registered rate. Harness validity (not a detector): at `ρ` = 1 with no abandonment the archived FULL2 values are reproduced | sensitivity: at the highest registered `ρ`, at least one benign stream is refused more often than at `ρ` = 1; if not, the harness does not exercise `ρ` and the gate is not evidenced | Step 5, or a separate study |
| **NM-10** | A-2 (open item) | **Outcome reconciliation.** No measured design guarantees that every admission reserved at `checkOnly` receives a recorded outcome, so a Host that declares `pre+out` and drops outcomes is, for those attempts, a `pre` Host (DEC-023 A-2); outcome completeness (missing or late outcomes) is `UNPROVEN`. The only per-attempt (per-permit) binding of outcomes to admissions in the evidence is tier T1 (`ARCHITECTURE_PROPOSAL.md` §6.5, GA-5): an optional permit token on `ALLOW` and on the outcome commands, giving at most one scoring per permit. Its specified state machine marks an admission with no outcome by its deadline `EXPIRED`, and a late outcome is rejected with no scoring (`SECURITY_FEASIBILITY.md` §2.3). #116 (OAAA) measured a per-tuple binding, in which the Host outcome resolves one pending reservation of the same tuple; it is not idempotent per attempt (gate X1 FAIL) and admits a pre-only route on a credited scope (gate X3 FAIL) (`investigations/outcome-aware-atomic-admission-110/REPORT.md` §0a), and a missing outcome there leaves the unit spent (`investigations/outcome-aware-atomic-admission-110/ARCHITECTURE.md`); the study's verdict is NO-GO (`investigations/outcome-aware-atomic-admission-110/REPORT.md` §0). G1 measured T1: original form NO-GO; with the fixed study store `UNPROVEN` for production (`G1_FINAL_DISPOSITION.md` §1). Two design questions, not a choice and with no preference: (a) an admission with no outcome within a deadline is treated as a failure (fail-safe); (b) T1. Both are outside step 2. Template: replay FULL2 in `pre+out` with a Host that drops a share of its outcomes; the shares are values the later plan registers (none is set here). PASS: A1, A3 and B1 of `g4/integrated_v2/PLAN.md` §6.3 hold with the chosen mechanism. Gate P is reported only, not a PASS condition | at the highest registered drop share, the same arm without the mechanism fails A3; when every outcome is dropped it reproduces FULL2 `pre` (SP6 144, SP3 48, SP1n 24, ABA_A 48 against the S12 bounds 72, 24, 12, 24 of A3; gate P reported as L1 72, D2 61) | Step 5, or a separate study |
| **NM-6** | C | Production store integrity: the T1-N4b and T1-N5 equivalents and the R-1 / R-2 races on the production store (`G1_FINAL_DISPOSITION.md` §4 item 4). PASS: no reservation-state write on a refused reservation, the previous generation never written | the original study Redis store, which failed T1-N4b / N5 (`G1_DA_T1.md`), fails the same fixtures | Step 5 |
| **NM-7** | C | Operational envelope: real Redis concurrency, failover, a mixed fleet behind the fence, and rollback (`G1_FINAL_DISPOSITION.md` §3 P5; `ARCHITECTURE_PROPOSAL.md` §10, migration steps 1–4). Template: the ceilings are the values the later plan registers. PASS: concurrent admissions never exceed the reserved scope's ceiling; rollback behaves as `ARCHITECTURE_PROPOSAL.md` §10 step 4 states | a non-atomic reservation (check, then increment) exceeds the ceiling under the same concurrency | Step 5 |
| **NM-8** | B | Whether the Host-side token issuance or renewal after a verified success (§6, last item) can replace a recorded success for a `pre` Host. Arm: FULL2 in `pre`, with refunds triggered by that Host event. PASS: gate P of `g4/integrated_v2/PLAN.md` §6.3 and the NM-1 conditions both hold in `pre` | the same arm without the trigger reproduces FULL2 `pre` (gate P FAIL) | The `pre` study of option B |

## 6. What can and cannot be guaranteed for a `pre` Host (input for option B)

`DERIVED` from §4.2. Evidence only; this is not a design.

**Can be given by reservation alone** (no outcome needed), under §2:

- **An outer source ceiling** (PR-a, GUARANTEED). Its size is the source cap itself, because the net cap that brings spray
  close to S0 in `pre+out` is inert: SP6 144 in `pre` against 24 in `pre+out` at `U_src` 24; 240 at 40 (G4 v2 §5.6).
- **A per-account bound** (PR-c: shown on the streams; the fixtures are NM-9), at a measured cost to legitimate users that a
  `pre` Host cannot remove: with the bucket, BRU 5 and D2 61; with a fixed window, D2 24 but BRU 80 and X1 17 (G4 v2 `pre`
  table). No measured design gives a `pre` Host both.
- **Some relief on the strangers' door from binding**, because issuance is Host-side (PR-h streams: L1 122 → 72, Nu4 41 → 0).
  The owner guarantee itself is NM-2.

**Cannot be guaranteed without an additional information source that carries outcomes to the library:**

- the net failure cap (PR-b);
- any refund, on the bucket or on P (PR-d, PR-f);
- a P budget that bounds a stolen token **and** refunds heavy bound users at the same time (footnote ¹);
- outcome-based escalation (`ARCHITECTURE_PROPOSAL.md` §6.4: "no outcome-based escalation");
- the DEC-021 known-device bound, which learns only from recorded failures (`D24K1` 1440/1440, §1).

**Excluded by option B's own condition.** Option B keeps known devices inside their budgets. Known-device routing as tested
(PR-g) skips the bucket, so it is outside option B. What routing relieved in `pre` was the bucket's KD / UAC cost on the U
door (TB30R `pre` 8 / 8 → `TBK30R` `pre` 0 / 0; G3 v1 and G3 v2). In FULL2 `pre` the remaining KD 4 / UAC 4 come from the
P budget, not from the bucket: `R-NO-TB` `pre` (no bucket) still has 4 / 4, and `P-NETF` `pre` (same bucket, P budget counting
recorded failures) has 0 / 0 (G4 v2 `pre` table). So `P-NETF` removes them in `pre`, at the cost in footnote ¹: a stolen token
then has no P budget except `N_P`.

**The only outcome-related input the evidence shows in `pre`.** Token issuance and sliding renewal happen at the Host after a
verified success, in every flow (`g4/integrated/PLAN.md` §1.1; `g4/integrated_v2/PLAN.md` §3). Whether that can stand in for a
recorded success, for example for a refund, was never measured. It is a question for the scope of the option B study, not a
finding.

## 7. Limits of this paper

- It reads the archives as merged; it re-runs nothing and adds no number.
- "GUARANTEED" means measured within the cited study's scope (§2 C-5). It is not a production guarantee and not an
  Architecture GO. FULL2 itself is EXPERIMENTALLY SUPPORTED — LIMITED SCOPE for `pre+out` (G4 v2 §2).
- Binding, K5 material and DEC-021 appear only as far as they change what a mode can get (step 3 owns them).
- The TMS family (valid sessions for several accounts) was not among the G4 v2 streams. What FULL2 gives against it in any
  mode is not measured here. DEC-022 (`PROPOSED`, not approved) proposes, in its Decision 4 and Conditions 3 and 4, to keep
  those cells as gates.
