# G4 v2 — Integrated re-assembly after F131-01 (#97)

> **Status:** G4 v2 INVESTIGATION EVIDENCE for architectural review. Not an ADR, not a policy, not a production value, not a
> statement that Runtime is ready, and not an Owner decision on `U_src`, T, K5 separation or any proposed component. No change to
> `src/`, `tests/`, `composer.json`, public contracts, presets, thresholds, ADRs, standards, or any earlier study or archive
> (the G4 archive is reused read-only). DEC-015, DEC-019 and DEC-021 stay ACTIVE; the proposal stays PROPOSED.
> **Pre-registration:** [`g4/integrated_v2/PLAN.md`](g4/integrated_v2/PLAN.md) (plan v1), committed in `d6028c8` **before** any
> G4 v2 harness code or result. Gates, values and verdict rules were not changed afterwards (§7).
> **Owner authorisation (2026-10-18):** G4 v2 with the scope of the plan. `U_src` 24 (40 reported), T = 30 minutes and C = 4 are
> study values only.
> **Evidence classes:** `MEASURED` (archived harness output, pipeline fixtures as planned), `MODEL` (the CGN-B admission-layer
> simulation, no pipeline), `POST-HOC` (analysis of the archived output added after the results, not in any gate), `DERIVED`.

## 1. Baseline and provenance

| Item | Value |
| --- | --- |
| Source baseline | `main@ac97c1232dd1c03f73cacaffdffb7ed9345f06ef`; `src/`, `composer.json`, `composer.lock` byte-identical to `4c6ca61` (checked by `run.sh`) |
| Builds | the archived G4 builder, unchanged: `CAND`, `NOESC`, `G4` (NOESC + K5 separation, pipeline `45d201d0…63fd`), `C5` (CAND + K5 separation, `3111c315…6f79`); K5 separation patch `7af55e4a…b278b1` |
| New code | [`lib/g4v2.php`](g4/integrated_v2/lib/g4v2.php): corrected builders, the input assertion, the Host model with the password-change binding and sliding renewal (`G4V2Host`), the gate with the P-lane refund budget (`G4V2Gate`, `G4V2PBudget`), and the replay with both hooks. Four scenarios, `report.php`, `run.sh`. The G4 library is reused read-only |
| Reproduction | `bash investigations/k2-identity-admission-97/g4/integrated_v2/run.sh`; two consecutive runs byte-identical. Exit 0 = `FULL2` supported for `pre+out` |

### 1.1 Harness validity (PLAN.md §1, §6.1)

| Check | Result |
| --- | --- |
| **V0** | PASS: S24 on CAND through the v2 replay equals the G3 v2 archive, 34 / 34 rows |
| **V1** | PASS: 74 Part R rows equal the G4 archive **field for field** on every fixture F131-01 did not affect (HAR-B, HAR-N, CROSS, STEAL, CLEAR, E2, BOOT, BOOT-X, CGN-B) |
| **IN** | PASS: every replayed row that declares an instance id carries it in `sessionDeviceId`; the assertion runs inside the replay of every scenario |
| **D-IN** | PASS: the assertion rejects 68 / 68 STREAMS groups built with the archived G4 builder, the BRU24 rows built with the archived G4 union, and HAR-B rows reset to the source context; it rejects none of the corrected rows |

## 2. Verdicts (PLAN.md §7)

| Part | Verdict |
| --- | --- |
| **Part N — `FULL2`, reference Host (`pre+out`)** | **EXPERIMENTALLY SUPPORTED — LIMITED SCOPE.** All 13 gates pass and all 6 detectors fire |
| Gate P — checkOnly-only Hosts (`pre`) | **FAIL**: L1 72 (≤ 0), D2 61 (≤ 36) |
| Part R — G4 with corrected input | **NO-GO AFTER EXPERIMENT (A1, C4, E, P)**: the same verdict and the same failed gates as the G4 archive |

Scope of the Part N verdict: synthetic streams, reference stores and a controlled clock; study-code tokens and a Host model; the
password change modelled as a proven Host event; the UA kept in the K5 material (§9). It is not an Architecture GO.

## 3. What changed from G4 after the input correction (Part R, `MEASURED`)

* **No gate result, no best-response number and no fixture number changed.** Every G4 gate re-measured with the planned input
  (A1, A2, A3, B1, B2, C2, P, and all the others) has the value G4 archived; BRU24, BRU24-HV and REC are identical in every arm; the
  necessity results and the Q4 comparison are unchanged.
* **5 of 684 stream rows differ**, all in the `ESC` arm (K4 escalation active on U), all with the `none` strategy (constant UA)
  and a fresh instance id per attacker request. Their penalty causes shift and some secondary metrics change (for example BRU
  `reachForeign` 3 → 2, X7 `verifierCalls` 121 → 44); none of them is the best response, which is the maximum over strategies and
  id policies. The full list is in [`expected/summary.md`](g4/integrated_v2/expected/summary.md) ("Row level").
* **What this settles.** The G4 numbers that F131-01 marked "not evidenced as planned" (G4 report §6.4) are now measured with the
  planned input and are the same. Why the input mattered so little is `DERIVED`, not tested: the UA stays in the K5 material
  (declared deviation), and in these streams an instance id mostly separates the same requests that the UA already separates.

## 4. Part N gates (`FULL2`)

| Gate | Result | `MEASURED` (`FULL2`) | Detector | R-FULL (= G4) |
| --- | --- | --- | --- | --- |
| A1 benign ≤ S0, ≤ S24 | PASS | L1 0, ABA_B 0, D2 24, Y6b 0, KD 9, UAC 6; all others 0 | — | FAIL: L1 72, ABA_B 94, Y6b 10 |
| A2 MIX ≤ S0 | PASS | 54 (S0 117) | — | 54 |
| A3 spray ≤ S12 | PASS | SP1n 4, SP3 8, SP6 24, ABA_A 8 | — | same |
| B1 per-account bound | PASS | BRU24 51, recount 51, ceiling 52 | — | 51 |
| B2 recovery | PASS | wait 60 s at δ = 0, else 0 | — | same |
| C1 bound owner | PASS | HAR-B 0 owner denials | `R-NO-BIND` 14 | 0 |
| C2 Host violation | PASS | BRU24-HV 51 | — | 51 |
| C3 cross-account | PASS | 0 P on victims, reach 4 | `R-WEAKBIND` 6 on P | same |
| **C4v2** stolen token, return path | PASS | thief ≤ 2 P / window, 9 in 24 h, **0 after the change**, U after the change 27 ≤ 28; **owner admitted at once (0 s) on P** | `N-NOPWB`: owner never admitted | FAIL: owner never admitted (719 refusals) |
| C5 NAT bootstrap | PASS | BOOT 0 / 383; BOOT-X 0 bound-instance refusals | `R-NO-BIND` 28 | same |
| C6 CGNAT (`MODEL`) | PASS | day-3 share 0.0096 %; 0 bound refusals with the sprayer | — | same |
| **E** E2 | PASS | owner decisions and lanes identical with and without the population | `N-NORENEW` differs | FAIL |
| **K5** own U failures | PASS | owner rows from 960 s identical between `fail` and `ctl` | `N-NOK5SEP` differs (12 rows) | not gated in G4 |
| P checkOnly-only | **FAIL** | `pre`: L1 72, D2 61 | — | FAIL (same numbers) |

## 5. Findings

### 5.1 P-lane refund budget (Q3)

Refunding successful P-lane logins removes every A1 failure of G4: L1 72 → 0, ABA_B 94 → 0 (S0 118, S24 72), Y6b 10 → 0. The
stolen-token bound is unchanged: the thief, who only fails, still reaches at most 2 P-lane attempts per window and 9 in the day,
bounded by `N_P` and K4P exactly as in G4, and 0 after the epoch bump. Necessity: `N-PG1` (the G1 budgets) brings back L1 72, ABA_B
94, Y6b 10 and makes E2 diverge again.

### 5.2 Password-change binding (Q2)

The password change is the event that revokes the stolen token, so it is also the moment the owner proves possession. Issuing the
new token there means the owner never needs the strangers' door: in STEAL the owner's first login after the change is admitted on
P at once, while the thief, still failing every 15 s from rotating IPs, is held on U by the bucket (27 admissions in 12 h, ceiling
28) and has 0 P admissions. Without it (`N-NOPWB`, the G4 behaviour) the owner is never admitted: 719 refusals, all `cap:acct_tb`.

### 5.3 Sliding renewal (Q2)

With renewal on every verified P success, the E2 owner never loses the token, so the population on the account no longer changes
any owner decision (E passes). Without renewal (`N-NORENEW`) the G4 failure returns: the token expires at 600 s, the owner falls to
U and the population drains the bucket (28 `cap:acct_tb`).

`POST-HOC` observation (not a gate): because the owner now stays on P, the owner's own three failed attempts at 630, 750 and 870 s
are P-lane failures, and the pipeline's P-lane penalties then refuse 12 of the owner's 38 rows (`soft` 10, `pen:unpersisted` 2),
identically with and without the population. In G4 the same rows failed on U and the owner had no penalty denial in the control run. This is
the owner penalising themself, not attribution to strangers, but it is a cost of keeping a typing owner on P and belongs to the
"negative score on a bound device is a throttle only" item of the proposal (§7, §13).

### 5.4 K5 separation (Q4)

The new K5U gate is the case G4 saw only post-hoc: the owner's token reaches its absolute limit, the owner fails three times on U
**on the same instance**, succeeds on U and is rebound. With K5 separation the owner's 24 later P-lane rows are identical with and
without those U failures. Without it (`N-NOK5SEP`) the same rows show `pen:K5` 2 and `soft` 10: the owner's own U history is read on
P. K5 separation is **shown necessary** under the registered rule for the first time (in G4 it was "not shown necessary" because the
E2 gate failed for another reason; Part R confirms that result for the G4 arms).

### 5.5 checkOnly-only Hosts (Q6, gate P FAIL)

A refund needs a recorded outcome, so in `pre` the refund budget is the G1 budget: L1 72 (`pre`) is unchanged, and D2 61 comes from
the bucket, which cannot refund either. Binding still helps these Hosts against the strangers' door (L1 122 → 72, Nu4 41 → 0 against
`R-NO-BIND`). The reported alternative `P-NETF` gets L1 0 in `pre`, but only because without outcomes it counts nothing: a stolen
token on such a Host would have no P-lane budget at all except `N_P`. Neither design gives a checkOnly-only Host both.

### 5.6 `U_src` 24 or 40 (reported, no winner)

As in G4: 40 lowers D2 from 24 to 2 and MIX from 54 to 42 in `pre+out`, and CGN-B bootstrap refusals (N = 5,000, λ = 1, day 1:
3,996 → 987); single-IP spray is the same in `pre+out` and higher in `pre` (SP6 240 against 144).

### 5.7 What the assembly still does not fix

* **Newcomers next to a co-located sprayer:** BOOT-X day 1, 68 of 125 requests refused, all from browsers not yet bound; CGN-B with
  the sprayer, 9,587 day-2 refusals at N = 5,000, all unbound clients (§2.9 indistinguishability).
* **MIX** 54 (below S0 117 and S24 63); **X1 / BRU / BRD** reach 4 / 5 / 5 against 2 / 3 / 3 on S0 (the bucket's initial C = 4).
* **checkOnly-only Hosts** (§5.5).
* A token that reaches its **absolute** limit during a continuing attack sends the owner to the strangers' door (the K5U set-up);
  only the password-change path is proven to bypass it.

## 6. Necessity (registered rule)

| Removed from `FULL2` | Expectation | Worse than `FULL2` (`MEASURED`) | Result |
| --- | --- | --- | --- |
| P refund (`N-PG1`) | A1 | L1 72, ABA_B 94, Y6b 10; E2 diverges | shown |
| Sliding renewal (`N-NORENEW`) | E | E2 diverges | shown |
| Password-change binding (`N-NOPWB`) | C4v2 | STEAL owner never admitted | shown |
| K5 separation (`N-NOK5SEP`) | K5 | K5U diverges | shown |

Part R repeats the G4 necessity results with the planned input: binding, the net failure cap, the bucket and the absence of U-lane
escalation shown, K5 separation not shown (E2 gate). Both tables are in [`expected/summary.md`](g4/integrated_v2/expected/summary.md).

## 7. Plan conformance

* **Plan unchanged after results.** Gates, values, arms and verdict rules are those of `d6028c8`.
* **Interpretations.** K5U's "absolute limit at 600 s" is modelled by a binding chain that started 90 days minus 600 s before the
  fixture. The BOOT pair for `FULL2` uses `R-NO-BIND` as registered for the detectors. CGN-B runs for the Part R arms that G4 ran
  and for `FULL2` / `FULL2-40`; `P-NETF` has no CGN-B run, and its C6 is not reported.
* **Development corrections before the first archived run** (none changes a scenario after its results were seen): a PHP 8 nested
  ternary in the BOOT pairing; in `report.php`, the V1 row-count check, C6 for an arm without CGN-B, the BOOT / CGN-B measures in the
  necessity table, and a cause column that joined two arrays with `+` (the F131-01 defect class) — replaced by two labelled fields.
* **Post-hoc material** (excluded from every gate): §3's explanation of why the input mattered little, and §5.3's observation on the
  owner's own P-lane penalties.

## 8. What this means for the proposal (`DERIVED`, not decisions)

| Proposal item | Effect of G4 v2 |
| --- | --- |
| §7 trust from success | The binding, with the refund budget, sliding renewal and the password-change binding, passes every registered gate for Hosts that record outcomes. The remaining owner residual is a new device or a token past its absolute lifetime during a continuing attack |
| §7 "Stolen cookie" | The thief stays bounded (2 / window, 9 / day here) and loses P at the password change; the owner gets P back at the same moment. The owner's P logins before the change can still be refused by the thief's P-lane failures (2 of 6 here) |
| §6.2 bucket, T = 30 min | Bound 51 / 52 and recovery as before; the starvation of an owner on U during an attack is avoided for bound owners by the two return paths, not by the bucket |
| §15 decision 3, K5 separation | Shown necessary by the K5U gate |
| checkOnly-only Hosts | Still failing (gate P); a P budget without outcomes is either the G1 budget or no budget |
| §6.3 `U_src` | §5.6 |

## 9. Limits

As G4: reference stores and a controlled clock; no Redis, concurrency or failover; synthetic streams and a CGN model; study-code
tokens and a Host model, not real cookies; the UA stays in the K5 material (the instance id is in `sessionDeviceId`, untrusted); the
stolen-token thief rotates its UA; the proof-acquisition paths of G2 are not combined; multi-IP spray and `U_glob` are out of scope;
the security of the Host's password-change or recovery flow is outside the library. Test values only.
