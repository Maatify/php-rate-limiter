# G6 budgets — two registered measurements for the step-4 decision paper: pre-registered plan (#97, step 4)

> **Status:** PRE-REGISTRATION, plan **v1**. Committed **before** any G6 harness code, patch or result. Evidence only. **Not** an architecture, policy, threshold,
> contract or public-API approval. No change to `src/`, `tests/`, `composer.json`, `composer.lock`, presets, public contracts, ADRs, standards,
> DEC-015 / DEC-019 / DEC-021 (ACTIVE, unchanged), DEC-022 (PROPOSED), DEC-023 / DEC-024 (ACTIVE), `DECISIONS_INDEX.md`, or any earlier study or archive.
> **Baseline:** the branch is cut from `main@f4851eb` (G5 v2, PR #138, merged). `src/`, `composer.json`, `composer.lock` are byte-identical to the G1 baseline
> `4c6ca618d8206ba502961eaf06ffb82a5d61b50d` (checked by `run.sh`).
> **Archives, read-only:** [`../../g5/identity_layer_v2/`](../../g5/identity_layer_v2/) and [`../../g5/identity_layer/`](../../g5/identity_layer/) (library, scenarios, builder, patches,
> `expected/`), and through them G4 v2, G4, G3 v2, G3 and G1. They are reused and never edited or regenerated.
> **Owner scope (step 4, the assignment of 2026-10-19):** prepare the Level-2 decisions of DEC-022 Condition 3 as a decision paper; this plan registers the **only two
> measurements the assignment allows** (item ج). Every number in the paper comes from a merged archive at its archive values (`U_src` 24 / 40, `F` 4, `T` 1800 s, `C` 4,
> `P_acct` 10 per window and 60 per day, `P_proof` 6 and 30, `N_P` 10 per day, `U_glob` 1000, TTL 30 days sliding and 90 days absolute, taint 3). **No value is proposed as a
> choice.** Test values only.

## 0. Question and the two arms

| Arm | Question | Kind |
| --- | --- | --- |
| **Arm 1 — UKD** (decision 5, F-G5-04) | Can a **known device that lost its token** (it is on the U door) be kept under DEC-021's allowance and K4 overflow, **without raising the reach of any attacker cell** above the reference? | gated, verdict |
| **Arm 2 — TMS-FULL2** (decision 8) | What do the three multi-session cells of #113 (`TMS`, `TMSd`, `TMX`) reach on **`FULL2`** (and on `FULL2` with the G5 v2 identity layer), against `S0` at the same baseline? | reporting only |

Arm 1 has a verdict because the assignment gives it a gate. Arm 2 has **none**: DEC-022 keeps the TMS family as Level-2 gates and the Owner sets the line (assignment, item ج.2).
Nothing here measures any other decision of the paper; where evidence is missing the paper records a gate-shaped "needs a measurement" item instead.

## 1. Pre-plan disclosure

No G6 pilot was run. Known before this plan, and used by it:

1. The G5 v2 harness was run **unchanged** on the machine that will run G6 (PHP 8.5.9; the G5 v2 archive was produced on PHP 8.4.4): exit 0, every output byte-identical to its
   `expected/` (including `summary.md`, `gates.json`). That checks the toolchain, not any G6 arm.
2. The G5 v2 archive rows were read: `NOUA2` `LOST` = 51 / 51 / 51; `TOKEN` = 45 / 44 / 45; `S0` `STABLE-i` = 45 / 44 / 45; `BRU24` = `BRU24-HV` = 51; `FORGE` U reach 27; `MINT` 51.
3. The G1 archive rows of `S0` (`m01_matrix.S0.jsonl`) were read for the TMS family: `TMS` 7/61 (`pre+out`) and 6/61 (`pre`); `TMSd` 2/200 (both); **`TMX` 60/200 in `pre+out`** and
   200/200 in `pre`. `TMX` is **not** 200/200 on this baseline: the 200/200 quoted in DEC-022 is #113's, measured at baseline `ce89cb2`, before DEC-021 (PR #117) added the known-device
   K5 thresholds and allowance. The reference of arm 2 is therefore `S0` **at this baseline**, re-run in this study (§2.2, V4), and #113's values are quoted as history.
4. No UKD run and no TMS-on-FULL2 run exists. The design of §2.1 is a reading of the pipeline source (`EvaluationPipeline.php` of the `G5V2` tree), not a measurement.

## 2. Builds and arms

### 2.1 Arm 1 — the UK lane

DEC-021's allowance and K4 overflow are inert on the U door of every assembled arm since G3 (the `NOESC` build drops every account-scoped U candidate): `LOST` 51 / 51 / 51, pipeline alone on the U
lane 144 / 79 / 77, against 45 / 44 / 45 on S0 and on the P lane (G5 v2 F-G5-04). The written proposal of G5 v2 (§7 of its plan) is: *a known device that lost its token on U stays under
DEC-021's allowance and K4 overflow, instead of being bounded only by the account bucket ceiling.* It is made testable as follows, and only as follows.

**Build `G6UK`** = the `G5V2` tree + [`patches/g6-uk-lane.diff`](patches/g6-uk-lane.diff) (sha-256 pinned in `build_g6.sh`, written after this plan and before any result). The patch adds a third
value `UK` to the study lane switch of the pipeline and changes **nothing else**. A request on lane `UK` is processed as a U-lane request except that:

| Item | Lane `U` (as `G5V2`) | Lane `UK` |
| --- | --- | --- |
| account penalty key (`K4`) | `k4` | **`k4uk`** (its own score, levels and 24 h budget) |
| allowance counter, account watcher, budget cooldown, device watcher | the unsuffixed purposes | the same purposes with the suffix **`_uk`** |
| the U-lane ablation (only device-scoped candidates survive; the 24 h budget suppressed) | applied | **not applied**: the account-scoped candidates of the DEC-021 mechanism (K4 score levels, account Anti-Equilibrium, budget) survive, on the `uk` state only |
| distributed-account and new-device-flood reactions | evaluated, then dropped by the ablation | **not evaluated** (they would otherwise survive the missing ablation) |
| K5, micro-cap, K1 / K2 / K3 scoring, source and bucket admission | as U | **as U** (K5 stays `k5`, never `k5p`: K5 separation is untouched) |

With no `UK` request the build is the `G5V2` build (checked by V0 and V1). The lane is requested by the harness for a request that is **on the U door and Host-known**, where *known* is the
pipeline's own predicate, evaluated by the harness on the resolver's DTO: `isTrustedSession || isDevicePreviouslyVerifiedForAccount` (`isKnownForAccount()`). The **admission layer stays on
the U door** for such a request (`U_src`, net failure cap, bucket with refund): the proposal adds a bound; it removes none.

Why this respects DEC-022 Decision 1: the `uk` state is written only by requests that arrive through the U door **and** that the Host already vouches for. It is a door-scoped state of the Subject, not a
shared-scope penalty; a stranger the Host does not mark known never writes or reads it. The cost is that a Host that marks an attacker known lets that attacker write the state (the Host-violation case,
measured by `BRU24-HV`).

**Arms (all with the G5 v2 assembly values; `NOUA2` rules: `N_P` member on the fingerprint, K5 on the id, `K4P` rule A):**

| Arm | Build | UK lane | Role |
| --- | --- | --- | --- |
| `NOUA2` | `G5V2` (archive) and `G6UK` (new code, lane never requested) | off | the **reference**; its archived rows are the reference values; the `G6UK` replay is V0 |
| **`UKD`** | `G6UK` | **on** | the proposal |
| `D-UKBYP` | `G6UK` | off, **admission bypass** for a known device on U | detector of gate U2: the other way to "route a known device", as G3 v2's known-device routing (the request skips the U admission layer) |

### 2.2 Arm 2 — the TMS family

| Arm | Build | Notes |
| --- | --- | --- |
| `REF` (= `FULL2` of G4 v2) | `G4` | the archived G5 code path (`g5Cell('REF')`, `g5Replay`), unchanged |
| `NOUA2` (`FULL2` + identity layer of G5 v2) | `G5V2` | the archived G5 v2 code path, unchanged |
| `S0` | the repository's own unpatched `src/` (pipeline sha `40213945…eab6`, asserted), own process, `G1_ARM` unset | reference at **this** baseline |

Streams `TMS`, `TMSd`, `TMX` of the G1 stream table (`g1Streams()`, source `akdr`, #119, built from #113's streams), materialised by the corrected G4 v2 builder `g4v2FromStream` exactly as the 22
G3 streams are. Attack id policies `fresh` and `const` and the no-id path `none` (G5 v2 §4.1); flows `pre+out` and `pre`. The `proof` of a request is the archived mapping M1 (a request whose session is bound to its
own account holds a proof, hence a Host-issued credential and the P lane when ids are present): this is an **inherited modelling assumption**, not a G6 choice. `TMX` is Host-violating by `DECISION_MATRIX.md` §0
(one session against 12 accounts): only its own-account slice holds a proof. Two control streams, `N12` and `SP1n`, are replayed with the same code to prove the harness (V3).
`N12T`'s attacker twin `TMS` is not an arm of its own.

## 3. Input integrity and validity

* **IN:** the archived assertion `g4v2AssertInput()` runs inside every assembled replay (exit 6). **D-IN:** the archived `g4/integrated_v2/scenarios/v2_detector.php` is run **unchanged** on the `G4` tree and
  compared byte for byte with its archive (exit 6). **NP-IN:** the G5 v2 audit (`g5v2NpAudit`) runs after every P-lane admission of every `G6UK` replay (exit 7).
* **V0 (inertness, assembled):** `NOUA2` replayed through the new code on `G6UK` (lane never requested) equals the G5 v2 archive **field for field** on the STREAMS rows (all rows including the no-id rows) and on
  the fixture rows the new fixtures scenario reproduces. Exit 5.
* **V1 (inertness, pipeline level):** the 28 pipeline rows of `G6UK` on the U and the P lane equal the G5 v2 archive's `g5v2_pipeline.G5V2.jsonl`. Exit 5.
* **V2 (S0 reference):** the archived `g5_s0.php` run unchanged is byte-identical to the G5 v2 archive's `g5_s0.S0.jsonl`. Exit 5.
* **V3 (arm-2 controls):** the rows of `N12` and `SP1n` produced by the arm-2 scenario equal the archive rows of `REF` (`g5_streams.G4.jsonl`) and `NOUA2` (`g5v2_streams.G5V2.jsonl`) field for field. Exit 5.
* **V4 (arm-2 S0):** the `S0` rows of `TMS`, `TMSd`, `TMX` (`pre+out` and `pre`) produced in this run equal the G1 archive's `m01_matrix.S0.jsonl` rows (`m`, `dec`, `lanes`, `reach`, `kinds`, causes). Exit 5.
* Provenance: every pipeline sha-256 (`G5V2` and `G6UK` pinned in the builder, `G4` and `S0` as archived) is asserted by the scenarios; resolver and DTO are the G5 ones (asserted). Exit 4.

## 4. Fixtures

### 4.1 Arm 1, assembled (admission layer + Host), arms `NOUA2` (V0), `UKD`, `D-UKBYP` (BRU24 and BRU24-HV and `LOST` only)

G5 v2's, unchanged in shape and encoding: **STREAMS** (the 22 G3 streams, attack id policies `fresh` and `const`, flows `pre+out` and `pre`, and the same streams with no ids), HAR-B, HAR-N, BRU24, BRU24-HV, REC, CROSS,
CLEAR, E2, K5U, BOOT, BOOT-X, UAX-NP, FORGE, MINT, LOST, TOKEN, UAX-ASM, MIXPRES-ASM, and the STEAL fixture (thief admissions only). CGN-B is not re-run (no change on the P door or in the admission layer).

### 4.2 Arm 1, pipeline level (no admission layer), `G6UK`

`STABLE-600 / 300 / 60` and `KDL` on the **UK lane** (reach, maximum `k4uk` score, decision string): the mechanism evidence that the proposal restores DEC-021's bound on a separate state. Reported, not gated.

### 4.3 Arm 2

`TMS`, `TMSd`, `TMX` × `{fresh, const, none}` × `{pre+out, pre}` on `REF` and `NOUA2`; `S0` × `{pre+out, pre}`. Per row: reach split by lane, reach in the first 600 s, maximum reach per account, denial causes.

## 5. Gates (arm 1)

A gate is **all** of its items. A gate whose detector does not fire is not evidenced and counts as failed, named.

**U1 — LOST (the known device that lost its token stays under DEC-021's bound).** `UKD` `LOST` reach at 600 / 300 / 60 s is **≤ 45 / 44 / 45**, the `S0` `STABLE-600 / 300 / 60` values **read from the archive**
(`g5_s0.S0.jsonl`, V2).
**Detector:** the reference `NOUA2` `LOST` (archive) exceeds the bound at at least one interval (51 / 51 / 51 against 45 / 44 / 45).

**U2 — no attacker reach above the reference.** For every attacker cell, `UKD` is **≤ `NOUA2`'s archived value**:

1. every STREAMS row (stream × strategy × id policy × flow): `reachForeign + reachProof`;
2. `BRU24` and `BRU24-HV` `attackAdmitted` (archive 51 / 51);
3. HAR-B and HAR-N attack reach (`reachForeign + reachProof`);
4. `FORGE`: thief P admissions (archive 0) and thief U reach (27); `MINT` reach (51) and P lane count (0); `CROSS` victim reach (4); `BOOT-X` attack reach (192);
5. STEAL: thief P admissions before the change (9), per window (2), after the change (0), and thief U reach after (27).

**Detector:** `D-UKBYP` admits more than the reference on `BRU24-HV` (the archive's 51; the G3 v2 control admitted 5,760 of 5,760): the check can see a known-device route that skips admission.

## 6. Verdict rules (fixed now)

1. A harness-validity failure (V0, V1, V2, V3, V4, IN, D-IN, NP-IN, provenance) ⇒ no verdict; the run is invalid.
2. **Arm 1:** **SUPPORTED — LIMITED SCOPE** if U1 and U2 pass with their detectors firing; otherwise **NO-GO AFTER EXPERIMENT**, naming every failed item (`U1`, `U2.<n>`, or the detector).
3. **Arm 2:** no verdict. The rows are reported; the line, if any, is the Owner's (DEC-022 Decision 4).
4. No gate is re-scoped after a result. A failure does not re-open fixed evidence. A change needs a new plan.
5. Nothing here is an Architecture GO, an ADR, a production value, an Owner decision on any budget, or a statement that Runtime is ready. A passing arm 1 says that the proposal is **measurable
   without a loss in these cells**; it does not say that the Owner should adopt it.

## 7. Reporting rules (not gated)

* Every table separates **measured as planned** from **post-hoc**, pipeline fixtures from models, `NOUA2` from `UKD`, gated from reported. The report states per number whether it equals the archive.
* **Arm 1, the cost side (reported, with numbers):** every STREAMS row where `UKD` differs from `NOUA2` (legit-denied, reach, causes); the fixtures where it differs (HAR owner denials, E2, K5U, CLEAR, BOOT,
  UAX-NP, REC, STEAL owner); the number of requests routed to the UK lane per row; `UKD` `TOKEN` and the pipeline-level UK-lane rows against `S0`.
* **Arm 1, limits stated, not measured:** a Host that marks an attacker known (the `BRU24-HV` row is the only measurement); OTP; API; Redis, concurrency, failover, DEC-015 degraded mode; the migration of the
  `uk` state; the K5 / allowance interaction across the three states (`k5` / `k5p`, `allowance` / `_p` / `_uk`).
* **Arm 2:** the table of §4.3, the best response over id policies, `S0` at this baseline, the history (#113 at `ce89cb2`: `TMS` 7/61, `TMSd` 2/200, `TMX` 200/200; #119 `MODEL` 61/61 and 60/200 for relief
  variants; the G1 candidate of `G1_VERIFICATION.md` §6.2 item 5: `TMS` P 61/61, `TMSd` 60, `TMX` 12 F + 5 own-proof P), and what bounds each cell on `FULL2` (denial causes). No gate, no verdict, no line.

## 8. What this cannot prove

Real cookies, tokens and Hosts; Redis, concurrency, failover, DEC-015 degraded mode; the id's public shape; the strength of a Host's "known" signal (arm 1 depends on it exactly as DEC-021 does); the proof-acquisition
model M1 for a multi-session attacker (arm 2 inherits it); `TMS`-family cells with real cookies per account; OTP and API; any value other than the archive values; the other seven decisions of the paper
(they are answered from merged archives or recorded as "needs a measurement").

## 9. Reproduction

```bash
bash investigations/k2-identity-admission-97/g6/budgets/run.sh
```

Builds the G4, G5 and G5V2 trees with the archived builders (run unchanged), then `G6UK` with `build_g6.sh` (patch sha-256 pinned); runs every scenario under `-d memory_limit=1G` (**passed to every `php` call,
the first included**); runs the archived D-IN and the archived `g5_s0` scenarios unchanged; runs the `S0` TMS scenario in its own process; the report; and diffs against `expected/`. Exit 0 = arm 1 SUPPORTED;
3 = harness valid and arm 1 NO-GO; 5 = V0 / V1 / V2 / V3 / V4 miss; 6 = IN / D-IN failure; 7 = NP-IN failure; 4 = baseline / provenance; 1 = output differs or a scenario fails. `UPDATE=1` regenerates `expected/`.
