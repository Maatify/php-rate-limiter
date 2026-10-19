# G5 v2 — Identity layer after the G5 results: K5 on the id, `N_P` on the fingerprint, the fate of `K4P` (#97, step 3)

> **Status:** G5 v2 INVESTIGATION EVIDENCE for architectural review. Not an ADR, not a policy, not a production value, not a public API
> shape, not a statement that Runtime is ready, and not an Owner decision on `U_src`, `T`, K5, `N_P`, `K4P` or any proposed component. No change to
> `src/`, `tests/`, `composer.json`, public contracts, presets, thresholds, ADRs, standards, `DECISIONS_INDEX.md`, or any earlier study or archive (the G5 and
> G4 v2 archives are reused read-only). DEC-015, DEC-019, DEC-021, DEC-023 and DEC-024 stay ACTIVE and unchanged; DEC-022 and the proposal stay PROPOSED.
> **Design amendments:** [`IDENTITY_LAYER_DESIGN.md`](IDENTITY_LAYER_DESIGN.md) §"v2 amendments", committed in `ed4176d` before the plan.
> **Pre-registration:** [`g5/identity_layer_v2/PLAN.md`](g5/identity_layer_v2/PLAN.md) (v1 in `da1e9fc`, v1.1 in `614093a`), committed **before** the harness
> (`8017ef6`) and before any archived result. Gates, values and verdict rules were not changed afterwards (§7).
> **Owner scope (step 3):** the identity layer only. `U_src` 24, `F_src` 4, `T` = 30 minutes and `C` = 4 are archive values of G4 v2, not choices. Test values only.
> **Evidence classes:** `MEASURED` (archived harness output, as planned), `MODEL` (CGN-B), `DERIVED`. There is no `POST-HOC` material in this report.

## 1. Baseline and provenance

| Item | Value |
| --- | --- |
| Source baseline | the branch is cut from `main@8f18780` (G5, PR #137, merged); `src/`, `composer.json`, `composer.lock` byte-identical to `4c6ca61` (checked by `run.sh`) |
| Reference builds | the archived builders, run unchanged: `CAND`, `G4` (pipeline `45d201d0…63fd`); `S0` = the repository's own unpatched `src/` (pipeline `40213945…eab6`, asserted) |
| New builds | `G5V2` = the G5 tree (`g5-k5-id-material.diff`, unchanged) + [`patches/g5v2-k4p-scope.diff`](g5/identity_layer_v2/patches/g5v2-k4p-scope.diff) (a settable scope on the account-wide P penalty state; inert when `null`); four ablations = `G5V2` + the four **G5 ablation patches, unchanged**. Every patch and pipeline sha-256 is pinned in [`build_g5v2.sh`](g5/identity_layer_v2/build_g5v2.sh); resolver and DTO are the G5 ones (asserted) |
| New code | [`lib/g5v2.php`](g5/identity_layer_v2/lib/g5v2.php), four scenarios, [`report.php`](g5/identity_layer_v2/report.php), [`run.sh`](g5/identity_layer_v2/run.sh). The G5 library, the G5 scenarios (run **unchanged** for the reference arm), the G4 v2 library and the Host model are reused read-only |
| `N_P` | not in `src/`: the `p_device` member of the admission layer's reservation (the study's `G1Admission`), which receives its member material from the caller. The primary arm passes `fingerprintHash`; the check that it did is NP-IN (§1.1), a read of the admission store's member sets, not a statement |
| Reproduction | `bash investigations/k2-identity-admission-97/g5/identity_layer_v2/run.sh`; two consecutive runs byte-identical, the second under a `php.ini` that sets only `memory_limit=128M` (the runner passes `-d memory_limit=1G` to **every** php call, the first included). Exit 0 = SUPPORTED; 3 = harness valid and NO-GO |

### 1.1 Harness validity (PLAN.md §3)

| Check | Result |
| --- | --- |
| **V0** | PASS: the no-id rows of the replay on `CAND` equal the G4 v2 archive, 34 / 34 |
| **V1** | PASS: `REF` equals the G4 v2 archive **field for field** on 114 STREAMS rows and 12 fixture rows; pipeline-level `STABLE-i` and `KDL` of `REF` on the P lane equal `S0` |
| **V2** | PASS: the seven reference-side outputs (the G5 scenarios for `CAND`, `G4` and `S0`, the archived D-IN and CGN-B) are **byte-identical to the G5 archive** |
| **V3** | PASS: 8 equalities with the G5 archive. `NOUA2` rows equal the G5 post-hoc `NOUA-FPN` rows (STEAL, `UAX-NP`, three stream cells); `D-NPID` STEAL equals G5's `NOUA` STEAL; `REF` STEAL and `UAX-NP` through the new code equal the G5 `REF` rows. The new patch is inert with no scope set |
| **IN / D-IN** | PASS: the archived assertion runs in every replay; the archived `v2_detector.php` run unchanged is byte-identical (3 families) |
| **NP-IN** | PASS: 28 audited scenario parts, 3,254 P admissions. Every `fingerprint` arm: all audited admissions have the fingerprint member in the store's sets and **none** has the id-component member; `D-NPID`: the reverse, 0 fingerprint members (table in `expected/summary.md`) |

## 2. Verdict (PLAN.md §6)

**SUPPORTED — LIMITED SCOPE.**

| Gate | Result | Detector |
| --- | --- | --- |
| **G1 — UAX** same instance after a UA change is the same device for K5 (`UAX-NP` a declared cost) | **PASS** | fires (`REF` fails items 1, 2 and 4; `D-NPID` refuses 0 on `UAX-NP`, `REF` 2) |
| **G2 — IDM** a changed or forged id gives no extra budget | **PASS** | fires (`D-IDMINT` admits 5,760 of 5,760 on BRU24) |
| **G3 — KDP** a known device keeps DEC-021's protection | **PASS** (item 5 against the designed behavior) | fires |
| **G4 — MECH** allowance, K5, Anti-Equilibrium and the K4 overflow keep their guarantees | **PASS** | fires, all four |
| **G5 — ABS** the absence of an id is a defined path | **PASS** (item 2 without the U-lane `S0` comparison) | fires |
| R:STEAL (rule A) | **PASS** (thief 9 = `REF` 9; owner wait 0) | fires (`D-NPID` thief 20 > 9) |
| R:STREAMS, R:STREAMS-UA, R:STREAMS-X7 | **PASS** | — |
| all other regression checks (E2, K5U, CROSS, HAR-B, HAR-N, BRU24, BRU24-HV, REC, CLEAR, BOOT, BOOT-X, CGN-B) | **PASS** | — |

What the verdict says, and what it does not:

* It says that the registered plan, with the registered amendments, passes. The primary arm is the one G5's post-hoc arm `NOUA-FPN` already described; the plan said so before the run (PLAN.md §1), so the pass is
  **not** independent evidence that `N_P` on the fingerprint works: it is the same measurement under registered, detector-backed gates, plus V3 proving the equality with the post-hoc arm.
* It does **not** say that the `K4P` rule matters (finding F-G5v2-03: with `N_P` on the fingerprint rules A, B and C give the same STEAL numbers), that `UAX-NP` is acceptable to the Owner, or that Runtime is ready.
* Scope: synthetic streams, reference stores and a controlled clock; study-code tokens and a Host model; the password change modelled as a proven Host event; the id travelling in `sessionDeviceId`;
  `N_P` a model of the admission layer. Not an Architecture GO.

## 3. Results (`MEASURED`)

Full tables, every value and every detector: [`g5/identity_layer_v2/expected/summary.md`](g5/identity_layer_v2/expected/summary.md).

### 3.1 G1 — UAX

`UAC12` string equals `KDL` on the P and the U lane (`AAAA!SSSSSA!HSSS`), one K5 key and one allowance counter (5 = 5) across the UA change; `UAX24` reach 45 / 45 (P), 77 / 77 (U); `UAX-ASM` stays on `PPPPPPPPPPPP`.
**Item 6 (declared cost):** `UAX-NP` refuses 2 of 12 (`cap:p_device`), exactly `REF`'s 2. `D-NPID` refuses 0. Detector: `REF` fails items 1, 2 (P and U) and 4 (U: 82 / 77).

### 3.2 G2 — IDM

BRU24 admissions 51 (archive 51); X1 4, BRU 5, BRD 5 (archive 4 / 5 / 5); HAR-N 4 (archive 4); `FORGE` 0 P admissions, 27 U reaches (`REF` 27); `MINT` 51 (`REF` 51, ceiling 52). `D-IDMINT`: 5,760.

### 3.3 G3 — KDP

Items 1–4 as G5: `TOKEN` 45 / 44 / 45 = `REF` = `S0`; `KDL` string equal on both lanes; `UAC12` reach 5 against `S0` 8; `LOST` 51 / 51 / 51 ≤ `REF`.
**Item 5 (amended):** attacker reach 0 / 0 on `KD` and `UAC`; `KD` legit-denied 9 = `REF` 9; **`UAC` equals `KD` of the same arm** (`AAASSSSSHSSS`, 9 denials), i.e. one K5 across the browser update.
The owner's excess against `REF` on `UAC` (6 → 9, decisions `AAASSSAASSAS` → `AAASSSSSHSSS`) is the declared cost of F-G5-06 (a).

### 3.4 G4 — MECH and G5 — ABS

G4: allowance / overflow `STABLE-600 / 300 / 60` (P) 45 / 44 / 45, max K4 score 7, equal to `REF` and `S0`; K5 `KDL` and `AE` on both lanes equal to `REF` and `S0`; every detector fires (`D-NOALW` and `D-NOOVF` 128 / 83 / 76; `D-NOK5` `AAAAAAAAAAAA`;
`D-NOAE` as G5). G5: no-id STREAMS 68 / 68 rows equal to `REF`; `NOID-KDL`, `NOID-UAC12`, `NOID-AE` equal to `REF` and `S0` on both lanes; `NOID-STABLE-300` P lane 44 = `REF` = `S0`, U lane 79 = `REF`
(the `S0` comparison, 44, left the gate: F-G5-04); `MIXPRES` keys differ, A unchanged by B.

### 3.5 R:STEAL, R:STREAMS

STEAL, primary arm: thief 9 P admissions before the change (`REF` 9), at most 2 per window (`REF` 2), owner wait after the change 0 s on the P lane, owner denied before the change 2 (`REF` 2).
`D-NPID` (the member on the id, G5's `NOUA`): 20 / 5 / 1,260 s, reproducing G5's `NOUA` row (V3).
STREAMS: 114 rows carry ids; **28 are material-varying** under the widened rule (14 under G5's UA-only rule); the 86 others are identical to `REF`. Among the 28, exactly **one** differs from `REF`: `UAC / none / fresh / pre+out`
(legit-denied 6 → 9, the designed behavior); `X7 / rotate / const` and `X7 / uarot / const` equal `REF` on every field in both flows (R:STREAMS-X7), and the attacker reach is `≤` `REF`'s on all 28.

## 4. Findings (`MEASURED`)

| ID | Finding |
| --- | --- |
| **F-G5v2-01** | **`N_P` on the fingerprint removes F-G5-01 and F-G5-02.** The thief that keeps the id and rotates IP and UA consumes `N_P` again: STEAL thief 9, owner wait 0, owner denied before the change 2, all equal to `REF`; `X7 / rotate / const` own-account reach back to `REF`'s with its 70 `cap:p_device` refusals. K5, the micro-cap and the device watcher stay on the id, and G1 items 1–5, G3 items 1–4 and G4 hold as in G5 |
| **F-G5v2-02** | **The declared cost of `N_P` on the fingerprint is a curve, not a step.** Twelve successful logins a day with `k` distinct browser majors: refusals 0 for `k` ≤ 10, 1 for 11, 2 for 12; the reference gives the same numbers and `D-NPID` gives 0 for every `k`. A normal browser update consumes one member |
| **F-G5v2-03** | **The `K4P` rule has no observable effect on STEAL once `N_P` is on the fingerprint.** Rules A, B and C give thief 9 / owner denied 2 / owner wait 0. Under the id-keyed member (G5's behavior) rules B and C remove the 1,260 s owner wait (to 0) but **not** the owner's 5 denials before the change and not the thief's 20 admissions |
| **F-G5v2-04** | **Rule C isolates a second proof holder, and costs the account bound.** STEAL2 under the primary member: the second device's own credential is denied 25 times before the change under A and B, **0 under C**. MULTITOK-S (eight credentials, one fixed presentation each): P admissions 38 under A and B, **120 under C** (denials by `pen:K4P` 144 → 88); under the id member the same 38 → 120. With rotating presentations `N_P` binds first (10 in every arm). Rule B has no cross-holder effect before the event (25 = A) |
| **F-G5v2-05** | **After the bump the second holder is locked out in every arm, the reference included.** STEAL2: the device whose credential is voided and not re-issued is on the U lane and is refused by the account bucket (`cap:acct_tb`, 719 refusals) for the rest of the day, because the thief's U-lane attempts hold it. Independent of the `K4P` rule and of the member; a U-lane / Host re-issue property (design §4.3 re-issues only the device that changed the password) |
| **F-G5v2-06** | **The widened "material-varying" rule classes 14 more rows (28 against 14); only `UAC` differs.** F-G5-06 (c) is closed: no cell outside `UAC` differs from `REF`, and `UAC`'s difference is the designed behavior (equal to the same arm's `KD`) |
| **F-G5-04 (carried)** | **The U-family known device keeps 51 in 24 h, against DEC-021's 45 / 44 / 45** (`LOST` NOUA2 = `REF` = 51; pipeline alone on the U lane 144 / 79 / 77; allowance and overflow enforce nothing on the U lane, 79 / 79 / 79). Unchanged by this step and by the layer; **one written proposal for step 4, not measured:** a known device that lost its token on U stays under DEC-021's allowance and K4 overflow instead of being bounded only by the account bucket ceiling |

## 5. Reported, not gated

### 5.1 `K4P` arms and the member material (PLAN.md §2.2, §4.2)

| Arm | member | `K4P` rule | STEAL thief P before / after | STEAL owner denied before | STEAL owner wait after (s) | STEAL2 O2 denied before | MULTITOK P admitted (rotating / fixed) |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `REF` | fingerprint | A | 9 / 0 | 2 | 0 | 25 | 10 / 38 |
| **`NOUA2` (primary)** | fingerprint | **A** | 9 / 0 | 2 | 0 | 25 | 10 / 38 |
| `NOUA2/B` | fingerprint | B | 9 / 0 | 2 | 0 | 25 | 10 / 38 |
| `NOUA2/C` | fingerprint | C | 9 / 0 | 2 | 0 | **0** | 10 / **120** |
| `D-NPID` | id | A | 20 / 0 | 5 | **1,260** | 71 | 38 / 38 |
| `D-NPID/B` | id | B | 20 / 0 | 5 | 0 | 71 | 38 / 38 |
| `D-NPID/C` | id | C | 20 / 0 | 5 | 0 | 0 | 120 / 120 |

Costs, as measured and as derived (design V2-2):

| Rule | Owner | Second proof holder | Account |
| --- | --- | --- | --- |
| A (primary) | nothing new to pay once `N_P` is on the fingerprint | pays for the thief's failures before the event (25 denials) and is locked out after it (F-G5v2-05) | bound as in G4 v2 (38 in MULTITOK-S) |
| B | same as A (0 wait under the primary member; removes the 1,260 s under the id member) | same as A | same as A; `DERIVED`: needs the epoch as a pipeline input, drops pre-event evidence for every actor, and is as strong as the Host's proof of the event |
| C | same as A; removes the 1,260 s under the id member | isolated before the event (0 denials); locked out after it like every arm | **weaker**: 120 P admissions for eight credentials against 38 (`K4P` per credential); `DERIVED`: one penalty state per credential in storage |

### 5.2 Other

`UAX-NP-K`, the UAC owner cost, the NP-IN counts and the pipeline-level numbers: `expected/summary.md`.

## 6. Necessity (the registered detectors, `MEASURED`)

| Removed from `NOUA2` | Worse than `NOUA2` | Result |
| --- | --- | --- |
| `N_P` on the fingerprint (`D-NPID`) | STEAL thief 20 against 9; `UAX-NP` 0 refusals against 2 | shown |
| UA-free material (= `REF`) | `UAC12` ladder restarts, K5 key splits, `UAX24` U reach 82 / 77 | shown |
| id-keyed budgets (`D-IDMINT`) | BRU24 admissions 5,760 against 51 | shown |
| allowance (`D-NOALW`) / K4 overflow (`D-NOOVF`) | `STABLE-i` 128 / 83 / 76 against 45 / 44 / 45; K4 score 0 against 7 | shown |
| K5 (`D-NOK5`) | `KDL` all admitted; `STABLE` 144 / 288 / 1,440 | shown |
| device watcher (`D-NOAE`) | second device refused (P); main device not escalated (U) | shown |

## 7. Plan conformance

* **Plan unchanged after the harness commit.** Gates, arms, values and verdict rules are those of `614093a`.
* **Amendments before the harness commit** (PLAN.md §10): A1 V3 also checks the `REF` STEAL and `UAX-NP` rows produced by the new code against the G5 archive (it can only make the run stricter); A2 `MULTITOK-S` added to the reporting fixtures. A2 was
  motivated by one development run of the cost scenario (not archived), which showed `N_P` binding first in every arm; no gate depends on it.
* **Pre-plan disclosure** (PLAN.md §1): the primary arm had already been measured post-hoc in G5; stated before the run and again in §2.
* **Interpretations.** The `S0` comparisons of G5 item 2 use the behavioral fields on both lanes (the plan's text), where G5 also compared every recorded field on the U lane. The "UAC equals the same arm's `KD`" test compares the
  decision string and legit-denied of the two streams under the same id policy. `K4P` rules B and C are realised as a key suffix (epoch, credential id) on the P lane's account penalty state, which is equal, for every read after
  the bump, to clearing it (rule B), not as a deletion.
* **Development corrections before the first archived run** (none changes a result after it was seen): the report read the STREAMS file's `np` summary row as a stream row (filtered); a count line for material-varying rows was added to the report.
* **The first run attempted under a reduced `php.ini`** loaded the local `conf.d` of the machine and printed a startup warning (a missing `imagick.so`) into the scenario outputs; it was repeated with the scan directory emptied. It concerns the check, not the harness.
* No post-hoc material.

## 8. What this means for the proposal (`DERIVED`, not decisions)

| Proposal item | Effect of G5 v2 |
| --- | --- |
| §5 rule 2 (K5 = account + id, no UA) | **Measured and now passing** under the amended gates: a browser update is not a new device for K5; a new or forged id mints no budget; DEC-021's ladder, allowance, overflow and device watcher keep their guarantees on the P family exactly; the absent-id path reproduces the assembly and DEC-021's K5 behavior. Declared cost: the `UAC` owner keeps their K5 history (6 → 9 denials) |
| §5 rule 2, "device" for the new-device allowance | **The member is the fingerprint, not the id** (design V2-1). Cost: a browser update consumes a member; refusals only beyond 10 distinct versions in a day (F-G5v2-02). With this the thief that rotates everything but the id is bounded as in the reference |
| §7 K5 separation by the binding | unchanged, still necessary |
| §7 "Stolen cookie" / `P§7.2` item 4 | the `K4P` rule is a **step-4 Owner decision** with a measured menu (F-G5v2-03, 04, 05): A costs the second holder, C costs the account bound, B costs a pipeline input and the Host's proof of the event |
| DEC-021 parity | P family retained; U family replaced by the bucket bound (F-G5-04), independent of this step |

**Owner decisions this report leaves to step 4 (none is made here):** (1) whether `UAX-NP`'s cost (the curve of F-G5v2-02) is acceptable; (2) the `K4P` rule (A, B or C); (3) the U-family known-device bound (F-G5-04, with the one written proposal);
(4) whether Hosts re-issue credentials to the other holders at a password change (F-G5v2-05).

A **decision-record draft** for the identity layer, as `PROPOSED` text with no value, is in [`g5/identity_layer_v2/DECISION_DRAFT_IDENTITY_LAYER.md`](g5/identity_layer_v2/DECISION_DRAFT_IDENTITY_LAYER.md). It is not under `docs/decisions/` and has no identifier or index entry:
Decision Governance requires every record under `docs/decisions/` to be discoverable from the index, and this PR does not touch `DECISIONS_INDEX.md`.

## 9. Limits

Reference stores and a controlled clock; no Redis, concurrency or failover; synthetic streams; study-code tokens and a Host model, not real cookies; the id in `sessionDeviceId` (untrusted), no public shape; `N_P` a model of the admission layer;
the K5 migration and rollback of the design (§6) are `DERIVED` and not measured; real browser-update traffic is not measured (the curve of F-G5v2-02 is per distinct version in a day, not a rate of updates); the operational reader is not
exercised; K3, K2, churn, dilution and `device_cap_account` keep the UA-inclusive fingerprint by design, so a constant id with a rotating UA still looks like several devices to them; the thief rotates IP and UA; the strength of the Host's proof of
a password change (what rule B would depend on) is not measured; the proof-acquisition paths of G2 are not combined; multi-IP spray and `U_glob` are out of scope; test values only.
