# G1 targeted falsification — distributed-account punishment ablation and T1 negative fixtures (#97)

> **Follow-up:** the Lead's review of this step
> ([comment 6229298358](https://github.com/Maatify/php-rate-limiter/pull/126#issuecomment-6229298358)) required the New Device
> Flood counterexample. Its result, and the single final G1 disposition, are in [`G1_FINAL_DISPOSITION.md`](G1_FINAL_DISPOSITION.md).
> This file is unchanged otherwise.

> **Status:** G1 EVIDENCE, append-only. It answers the Principal Architect's G1 verification addendum review
> ([PR #126 comment 6228568914](https://github.com/Maatify/php-rate-limiter/pull/126#issuecomment-6228568914)):
> F126-G1-R2-01 (the optional DA ablation) and F126-G1-R2-02 (T1). R-1 / G1 scope only.
> * No `src/`, DTO, preset, standard, ADR, policy, threshold or issue change.
> * No G2, no merge. DEC-019 stays ACTIVE.
> * This is not an Owner question. The variant verdict below is an engineering result, not a request to choose.
> **Pre-declared:** [`g1/verify/DA_T1_PLAN.md`](g1/verify/DA_T1_PLAN.md), committed in `8e6c2e9` before any result of this
> step. Rules N1–N4 and the T1 status rule were fixed there.
> **Append-only:** the original study, the verification addendum and their archived evidence are unchanged.
> [`run_da_t1.sh`](g1/verify/run_da_t1.sh) runs `run_verify.sh` first, which itself re-runs `run.sh`, and requires both
> evidence sets to compare clean. Results are in [`g1/verify/expected_da_t1/`](g1/verify/expected_da_t1/), with
> [`summary_da_t1.md`](g1/verify/expected_da_t1/summary_da_t1.md) and `gates_da_t1.json`.

## 1. Verdict

| Arm | N1 hardened gates | N2 benign DA denials | N3 owner effect isolation | N4 admission bounds | Engineering verdict |
| --- | --- | --- | --- | --- | --- |
| **CAND** (as archived) | pass | **384** | **fails**: MX 0+2 (+4), MX 0+1+2+3 (+7), MXE (+8) | pass | **NO-GO**, confirming the Lead's finding by the pre-declared rule |
| **CAND-DA-M** (no member K5 persistence) | pass | **384** | **fails**: MX 0+2 (+4), MX 0+1+2+3 (+5), MXE (+8) | pass | **NO-GO** |
| **CAND-no-DA-punishment** (observation only) | pass | **0** | **passes**: every MX subset identical to its control; MXE 0 population-origin denials | pass | **GO as an engineering component** |

**Why the literal ablation is not enough.** Removing only the *other* members' K5 persistence still persists the HARD block on the K5 of the **current** request, and that request is chosen by group membership. Whenever the owner's own device is the request that qualifies the window, the owner is punished. MXE shows exactly this. In phase B the same device runs unproven after its proof expired, and it is HARD-blocked 8 / 8 times on `distributed_account_attack`. That K5 block then carries into phase C, when the device is proven again. CAND-DA-M changes **no** matrix cell relative to CAND. The only principle-consistent form is to observe the device window and penalize nothing on group association alone.

**Overall position, unchanged.** A component GO is not an adoption GO. The candidate as a whole stays **NO-GO for adoption** (Lead, comment 6228568914) because of:
* the unproven package proof boundary (C8 / I5): the library does not bind proof to account and operation, and binding it needs an API decision;
* T1 (§4);
* the distributed-spray reach that no sampled parameter set removes (`G1_VERIFICATION.md` §6).

## 2. What removing the DA punishment costs (`MEASURED`, CAND-no-DA-punishment against CAND)

Every changed cell of the 644-cell matrix (52 cells), strategy by strategy. Best-response values are in parentheses.

| Stream | Where | CAND → no-DA | Best response |
| --- | --- | --- | --- |
| BRD (100 IPs → one account) | `pre`, `uarot` / `rotate`, all sets, CAND and ALLU | `reach_foreign` 7 → **20** | unchanged: 20 (the non-rotating attacker already reached `U_acct`·windows) |
| BRU | `pre`, `uarot` / `rotate` | 3 → **16** | unchanged |
| BRU | `pre+out`, `uarot` / `rotate` | 2 → 3 | unchanged |
| X1 | `pre`, `uarot` / `rotate` | 3 → 4 | unchanged |
| X7 | `uarot` / `rotate` | `reach_own` 4 → 5 (attacker's own account) | unchanged |
| D2 | R-src40, `pre+out` / `pre` | legitimate denied **27 → 7** / 24 → 0 | — |
| MIX | R-src40 | legitimate denied **59 → 35**; foreign reach unchanged (55) | — |
| D2, MIX | R and R-src4 | the 24 DA denials become `cap:src` refusals of the same requests (count unchanged) | — |

Further results:
* **No other cell changes.** That covers SP*, POI, ABA_*, TM*, KS50, D24K1 and API, in every set and every flow.
* **The original scenarios are unaffected.** DV-1 … DV-6 and the lifecycle scenarios (K5-1 … 3, DEC-021 coupling, DEC-007 on K4P, LT-1 S1–S6) produce output byte-identical to the archived CAND on both ablation arms.

**Diagnostic with the account cap not binding** (added after the pre-declared run, X03, disclosed). At R, `U_acct = 4` binds before the DA rule can act, so X03 compares the arms at Ua16-s40 and Uaoff-s40:
* the device-rotating `pre` attacker gains up to the account bound: BRD 7 → 80 / 100, BRU 3 → 20, X1 3 → 5;
* the **best response is unchanged in every cell** (`bestResponseChanges: []`), because a non-rotating attacker is not caught by DA and already reaches 100 when `U_acct` is unbound;
* D2 and MIX improve as above;
* HAR and HARp are unchanged.

**Reading.** In the sampled streams the DA punishment never lowers the attacker's best response. It only stops attackers who rotate devices *and* run against a checkOnly-only Host, and a rational attacker simply does not rotate. `U_acct` is the actual per-target bound. That makes `U_acct` load-bearing in any successor: an unbound account cap leaves single-target guessing bounded only by `U_src` / `U_glob` with or without DA. The DA rule's legitimate role is detection and observation.

## 3. Owner effect isolation (N3)

| Arm | MX subsets with the owner's secret and other devices (0+2, 0+1+2+3) | MXE phase B: same device, U | MXE phase C: P again |
| --- | --- | --- | --- |
| CAND | owner HARD 5 and 8 vs control 3; DA origin | `HHHHHHHH` (DA) vs control `SHHSSSSS` | differs; +8 population-origin denials |
| CAND-DA-M | 5 and 6 vs 3; DA origin | `HHHHHHHH` (DA) | differs; +8 |
| CAND-no-DA-punishment | **3 and 3, identical to control** | `SSSSSSSS`: soft, account-level K4 from the other devices' failures | differs from control without population origin (K4 / K5 / allowance only) |

In MXE phase B, the unproven owner is still denied: soft, on the account K4 that the other devices' failures raised. That is the HAR residual of `G1_VERIFICATION.md` §6, which is a property of the U lane (S0 does the same) and not a group-device punishment. Only a proof protects that owner (HARp 0 / 15).

## 4. T1 (F126-G1-R2-02)

| Fixture | original reference | fixed reference | original Redis | fixed Redis |
| --- | --- | --- | --- | --- |
| T1-N1 wrong-type permit key | n/a | n/a | **FAIL**: counters written, then error | pass: refused `permit_exists` before any write |
| T1-N2 re-issue a CLAIMED id | **FAIL**: second `accepted` | pass (`duplicate`) | **FAIL**: second `accepted` | pass |
| T1-N3 re-issue an ISSUED id | **FAIL**: record overwritten | pass | **FAIL** | pass |
| T1-N4a refused by a counter, expired members present | pass | pass | pass | pass |
| T1-N4b refused by the device cap, expired members present (added) | pass | pass | **FAIL**: current **and previous** sets pruned on refusal | pass |
| T1-N5 admitted, previous set read-only | pass | pass | **FAIL**: previous set written | pass |
| T1-N6 regression | — | claim series pass | — | counter race 3/3/3, device race 1/1/1, R-3 value-level, claim series: pass |

Both review findings reproduce. The original T1 breaks single issuance (a re-issued id is accepted a second time) and breaks all-or-nothing on Redis (counter writes before the permit write fails). The original Redis store also writes device sets before deciding, which is a **T0** defect for P device admission, not only a T1 one.

The fixed pair (`g1/verify/lib/t1v2.php`, study code) validates everything first, refuses an existing permit id and never writes a device set on refusal or a previous-generation set at all.

**Status:**
* The original T1 is **NOT RECOMMENDED**; its failing fixtures are kept.
* The fixed stores: fixtures pass in isolation (single Redis), **`UNPROVEN` for production**.
* T1 remains optional. A T0-only architecture needs only the device-set fix (N4b / N5).

Disclosures:
* **T1-N4b is an addition.** The pre-declared T1-N4 named a counter refusal, which the original code handles correctly. The path the review names is the device-cap refusal.
* **The fixture clock was moved before archiving.** It was moved to mid-window so the device-set fixtures' earlier reservations share the counters' window. Without that, T1-N4a refused on the device cap instead of the counter.

## 5. Provenance

| Item | Value |
| --- | --- |
| Base / candidate / trace | as `G1_VERIFICATION.md` §2 |
| CAND-DA-M | trace + [`g1-ablation-da-members.diff`](g1/verify/patches/g1-ablation-da-members.diff) (sha-256 `95f66fd9…c97e77d`); pipeline `abd2e51f…5fafe6e7` |
| CAND-no-DA-punishment | trace + [`g1-ablation-no-da-punishment.diff`](g1/verify/patches/g1-ablation-no-da-punishment.diff) (`c56e7dff…0844355`); pipeline `fa29130a…0e0ca96` |
| Builder | [`g1/verify/build_da.sh`](g1/verify/build_da.sh): hashed, and fails on any difference outside the pipeline. Provenance accepts `G1_BUILD=DAM` / `DAO` |
| Precondition | the CAND (trace) arm reproduces 644 / 644 archived matrix cells and the archived DV / lifecycle outputs byte for byte |
| Reproduction | `bash investigations/k2-identity-admission-97/g1/verify/run_da_t1.sh` (Redis 7.2.6 owned by the runner); re-run compared clean |

## 6. For the Lead

* **Selection (engineering):** the DA rule as an **observation-only** signal. It records device windows and occurrences and penalizes no device on group association. The measured cost is limited to device-rotating attackers on checkOnly-only Hosts, and to the `U_acct` bound. Best response is unchanged.
* **Consequence:** `U_acct` (or an equivalent per-target bound) is load-bearing and cannot be dropped. The unproven owner's protection against targeted harassment still depends on the proof (D3), not on any device rule.
* **Still open, not decided here:**
  * the typed proof/operation binding at the package boundary (an API decision);
  * whether T1 is pursued at all (and if so, with the fixed store semantics);
  * the device-set write-before-decide fix for T0;
  * the distributed-spray reach trade-off.
