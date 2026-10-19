# G5 — Identity layer: K5 material without the UA, K5 separation and the binding (#97, step 3)

> **Status:** G5 INVESTIGATION EVIDENCE for architectural review. Not an ADR, not a policy, not a production value, not a public API
> shape, not a statement that Runtime is ready, and not an Owner decision on `U_src`, `T`, K5 or any proposed component. No change to
> `src/`, `tests/`, `composer.json`, public contracts, presets, thresholds, ADRs, standards, or any earlier study or archive (the G4 v2
> archive is reused read-only). DEC-015, DEC-019, DEC-021, DEC-023 and DEC-024 stay ACTIVE and unchanged; DEC-022 and the proposal
> stay PROPOSED.
> **Design:** [`IDENTITY_LAYER_DESIGN.md`](IDENTITY_LAYER_DESIGN.md), committed in `8283d4b` before the plan.
> **Pre-registration:** [`g5/identity_layer/PLAN.md`](g5/identity_layer/PLAN.md) (v1 in `72183b0`, v1.1 in `2b43e92`), committed **before**
> the harness (`94bffa4`) and before any archived result. Gates, values and verdict rules were not changed afterwards (§6).
> **Owner scope (step 3):** the identity layer only. `U_src` 24, `F_src` 4, `T` = 30 minutes and `C` = 4 are archive values of G4 v2,
> not choices. Test values only.
> **Evidence classes:** `MEASURED` (archived harness output, as planned), `MODEL` (CGN-B), `POST-HOC` (added after the results, in no
> gate), `DERIVED`.

## 1. Baseline and provenance

| Item | Value |
| --- | --- |
| Source baseline | the branch is cut from `main@8d13da5`; `src/`, `composer.json`, `composer.lock` byte-identical to `4c6ca61` (checked by `run.sh`) |
| Reference builds | the archived G4 builder, unchanged: `CAND`, `G4` (pipeline `45d201d0…63fd`) |
| New builds | `G5` = `G4` + [`patches/g5-k5-id-material.diff`](g5/identity_layer/patches/g5-k5-id-material.diff) (an additive id component on `DeviceIdentityDTO`, produced by `DeviceIdentityResolver` with the current and previous hasher; K5, the K5 micro-cap and the device Anti-Equilibrium watcher use it when present and `fingerprintHash` otherwise); four ablations `G5-NOALW`, `G5-NOOVF`, `G5-NOK5`, `G5-NOAE`. Every patch and every resulting pipeline / resolver / DTO is sha-256 pinned in [`build_g5.sh`](g5/identity_layer/build_g5.sh) |
| DEC-021 reference | the repository's own unpatched `src/` (`S0`, pipeline `40213945…eab6`, asserted), run in its own process |
| New code | [`lib/g5.php`](g5/identity_layer/lib/g5.php), [`lib/g5fx.php`](g5/identity_layer/lib/g5fx.php), six scenarios, [`report.php`](g5/identity_layer/report.php), [`run.sh`](g5/identity_layer/run.sh). The G4 v2 library, builders and Host model are reused read-only |
| Reproduction | `bash investigations/k2-identity-admission-97/g5/identity_layer/run.sh` (about 3.5 minutes); two consecutive runs byte-identical, the second under a `php.ini` that sets only `memory_limit=128M` (the runner passes `-d memory_limit=1G` to every php call from the start). Exit 0 = SUPPORTED; 3 = harness valid and NO-GO |

### 1.1 Harness validity (PLAN.md §5.1)

| Check | Result |
| --- | --- |
| **V0** | PASS: the no-id rows of the G5 replay on `CAND` equal the G4 v2 archive, 34 / 34 |
| **V1** | PASS: `REF` (`FULL2` as G4 v2) equals the archive **field for field** on 114 STREAMS rows (both flows) and 12 fixture rows (HAR-B, HAR-N, BRU24, BRU24-HV, REC, CROSS, STEAL, CLEAR, E2, K5U, BOOT, BOOT-X); the archived `v2_cgn.php` run unchanged is byte-identical; pipeline-level `STABLE-i` and `KDL` of `REF` on the P lane equal `S0` |
| **IN / D-IN** | PASS: the archived assertion runs in every replay; the archived `v2_detector.php` run unchanged is byte-identical (3 families) |

## 2. Verdicts (PLAN.md §6)

**NO-GO AFTER EXPERIMENT — G3, G5, R:STEAL, R:STREAMS.**

| Gate | Result | Detector |
| --- | --- | --- |
| **G1 — UAX** same instance after a UA change is the same device | **PASS** | fires (`REF` splits K5, fails the ladder, gains 5 reaches on `UAX24` (U) and refuses 2 of 12 new-device logins) |
| **G2 — IDM** a changed or forged id gives no extra budget | **PASS** | fires (`D-IDMINT` admits 5,760 of 5,760 on BRU24) |
| **G3 — KDP** a known device keeps DEC-021's protection | **FAIL** (item 5, stream `UAC`: legit-denied 6 → 9) | fires |
| **G4 — MECH** allowance, K5, Anti-Equilibrium and the K4 overflow keep their guarantees | **PASS** | fires, all four |
| **G5 — ABS** the absence of an id is a defined path | **FAIL** (item 2, `NOID-STABLE-300` on the U lane: 79 against `S0` 44; equals `REF`) | fires |
| R:STEAL | **FAIL** (thief P admissions before the change 9 → 20; owner wait after the password change 0 → 1,260 s) | — |
| R:STREAMS | **FAIL** (1 of 100 not-UA-varying cells differs: X7 / rotate / const) | — |

What the failures are, in one line each (`MEASURED`, attribution in §4):

* **G3 item 5** is the designed behaviour of a UA-free K5: the owner who keeps mistyping after a browser update is the same device and
  keeps its K5 history. It is an owner cost, not a lost bound; the registered item compared legit-denied with `REF`, which counts it as a
  loss (a plan inconsistency with design §5 row 1, §4).
* **G5 item 2** is not an identity-layer effect: `NOUA` equals `REF` on every absent-id fixture, and equals `S0` on every K5 fixture
  (`KDL`, `UAC12`, `AE`) on both lanes. The U lane differs from `S0` on `STABLE-300` (79 against 44) for the reason the pre-plan pilot
  already showed: the G4 U lane has no K4 escalation.
* **R:STEAL** and **R:STREAMS** are one effect, and it is **not** the K5 material: the P-lane new-device member (`N_P`) keyed by the id
  lets a stolen-token thief that keeps the id and rotates IP and UA stop consuming `N_P` (§4, `POST-HOC` arm `NOUA-FPN`).

Scope: synthetic streams, reference stores and a controlled clock; study-code tokens and a Host model; the password change modelled as a
proven Host event; the id travelling in `sessionDeviceId`. It is not an Architecture GO and it is not a statement about any other
component of the assembly.

## 3. Results (`MEASURED`)

Full tables, every value and every detector: [`g5/identity_layer/expected/summary.md`](g5/identity_layer/expected/summary.md).

### 3.1 G1 — UAX (PASS)

| Measure | `NOUA` | `REF` |
| --- | --- | --- |
| `UAC12` (12 failures, browser major changes at the 7th, same id) against `KDL` (no change), P and U lane | identical, `AAAA!SSSSSA!HSSS` | `AAAA!SSSAAAA!SSS` (the second half is a new K5, the ladder restarts) |
| K5 key before and after the change | one key, score 9 | two keys |
| DEC-021 allowance counter | one key, 5 after both | one key |
| `UAX24` reach with and without the flip at 12 h | 45 / 45 (P), 77 / 77 (U) | 45 / 45 (P), **82 / 77 (U)**: the UA change alone buys 5 extra reaches on the U lane |
| `UAX-ASM` lanes after the change | `PPPPPPPPPPPP` (the binding is to the instance) | same |
| `UAX-NP` (12 successful logins a day, a new browser major each time) | 0 refusals | 2 refused (`cap:p_device`) |

### 3.2 G2 — IDM (PASS)

BRU24 attacker admissions 51 (archive 51); best-response reach X1 4, BRU 5, BRD 5 (archive 4 / 5 / 5); HAR-N attack reach 4 (archive 4).
`FORGE` (stolen token presented with a fresh id each attempt): 0 P admissions, 27 U reaches (`REF` 27). `MINT` (a Host-known device, a fresh
id per attempt, 24 h): 51 (`REF` 51, ceiling 52). Detector `D-IDMINT`: 5,760 admissions.

### 3.3 G3 — KDP (FAIL on item 5; items 1–4 PASS)

| Item | Result |
| --- | --- |
| 1 P family, absolute | `TOKEN` (assembled, valid token) 45 / 44 / 45 at 600 / 300 / 60 s = `REF` = `S0` = the recorded DEC-021 numbers; pipeline `STABLE-i` on the P lane the same |
| 2 K5 ladder, both doors | `KDL` string `AAAA!SSSSSA!HSSS` = `REF` = `S0` on the P and the U lane |
| 3 UA change not weaker than DEC-021 | `UAC12` reach 5 against `S0` 8 (P and U) |
| 4 U family, relative | `LOST` (token lost) 51 / 51 / 51 ≤ `REF` 51 / 51 / 51 |
| 5 streams `KD`, `UAC` | `KD` 9 / 9 (legit-denied, `NOUA` / `REF`); **`UAC` 9 / 6: FAIL**; reach 0 / 0 (the owner is the only actor) |

Reported, not gated (3d): `LOST` 51 / 51 / 51 against `S0` 45 / 44 / 45. Pipeline alone on the U lane: 144 / 79 / 77. `REF` has the same 51, so
this is the G3 / G4 U lane (the bucket ceiling replaces DEC-021's K4-overflow bound), independent of the K5 material (§4, F-G5-04).

### 3.4 G4 — MECH (PASS, every detector fires)

| Mechanism | `NOUA` = `REF` = `S0` | Detector |
| --- | --- | --- |
| allowance | `STABLE-600 / 300 / 60` (P) reach 45 / 44 / 45, max K4 score 7 | `D-NOALW` 128 / 83 / 76 |
| K4 overflow | same, the overflow delta lands on K4P | `D-NOOVF` 128 / 83 / 76, max K4 score 0 < 7 |
| K5 | `KDL` (P, U): string equal; K5 level 2 on the id-derived key, K4 level 0; the second device allowed | `D-NOK5` `AAAAAAAAAAAA` (reach 144 / 288 / 1,440 on `STABLE`) |
| Anti-Equilibrium, one watcher | `AE` (P, U): `AAAA!SSSS\|AfH2`, K5 block 2, no K4 block, the second device allowed | `D-NOAE`: second device `H` (P); main device `AAAA!SSSS\|AfS1`, not escalated (U) |

### 3.5 G5 — ABS (FAIL on item 2 only; items 1 and 3 PASS)

STREAMS with no ids, both flows: 68 / 68 rows of `NOUA` equal `REF` on every field. `NOID-KDL`, `NOID-UAC12`, `NOID-AE` equal `REF` and `S0` on both
lanes (`NOID-UAC12` differs from `KDL` exactly as DEC-021 ships: a UA change is a new K5); `NOID-STABLE-300` P lane 44 = `REF` = `S0`; **U lane 79 = `REF`,
`S0` 44 (FAIL as registered)**. `MIXPRES` (U lane, pipeline level): K5 keys differ, device A's decisions `AA` with and without B's four failures.

### 3.6 Regression checks

PASS: E2, K5U, CROSS, HAR-B, HAR-N, BRU24, BRU24-HV, REC, CLEAR, BOOT, BOOT-X, CGN-B (byte-identical), and the attacker reach of the two
UA-varying cells that differ (X7 / uarot / const 40 / 40, UAC 0 / 0). **FAIL:** STEAL and 1 of 100 not-UA-varying STREAMS cells (§4).

## 4. Findings (`MEASURED` unless stated)

| ID | Finding |
| --- | --- |
| **F-G5-01** | **The id-keyed new-device member `N_P` loosens the bound on a stolen-token thief that rotates IP and UA.** In STEAL the thief keeps the stolen id and rotates IP and UA every request. With the member on the fingerprint (`REF`) every rotation is a new device and `N_P` (10 per day) refuses the thief: 9 P admissions in the 12 hours before the password change. With the member on the id it is one device: 20 (still within the registered 6 / window and 30 / day). In X7 / rotate / const the attacker's own-account reach goes 11 → 81 and 70 `cap:p_device` refusals disappear (its foreign reach is unchanged, 40 / 40). This is the cause of R:STEAL and R:STREAMS (`POST-HOC` below) |
| **F-G5-02** | **The thief's extra admissions fall on the owner.** The thief's recorded failures are scored on the account-wide P penalty (`K4P`, `P§7.2` item 4). With 20 admissions instead of 9, the owner's first login after the password change waits 1,260 s (21 soft refusals), against 0, and 5 owner rows are refused before the change against 2. The password-change binding and the epoch do not clear `K4P`. This is the "stolen cookie consumes P" residual of `IAP§7`, made larger by F-G5-01 |
| **F-G5-03** | **UAC owner cost, by design.** An owner who mistypes on a device that changes browser major mid-way keeps one K5: 9 legit denials instead of 6 on the `UAC` stream (decisions `AAASSSSSHSSS` instead of `AAASSSAASSAS`). Verifier reach is unchanged. DEC-021's own K5 ladder, continued across a UA change |
| **F-G5-04** | **The U-family known device does not keep DEC-021's number, with or without this step.** Token lost: 51 reaches in 24 h at every interval (`REF` and `NOUA`), against 45 / 44 / 45 under DEC-021; the pipeline alone gives 144 / 79 / 77 on the U lane. On the P family DEC-021's numbers hold exactly (45 / 44 / 45). A G3 / G4 property (no U-lane K4 escalation), already in the pre-plan pilot; a step-4 (budgets) question |
| **F-G5-05** | **On the U lane the allowance and the K4 overflow enforce nothing** (`STABLE-300` 79 in `NOUA`, `D-NOALW`, `D-NOOVF`), and on the P lane the allowance's whole enforcement *is* the overflow (`D-NOALW` and `D-NOOVF` give identical numbers). K5 and the device watcher are enforced on both lanes |
| **F-G5-06** | **Plan defects found by the results (listed, not corrected after the results).** (a) Item G3.5 counts the UAC owner's extra denials as a loss; design §5 row 1 predicts them. (b) Item G5.2 compares the U lane with `S0` on a fixture (`STABLE-300`) where the G4 U lane differs from `S0` by the disclosed pilot fact. (c) The "UA-varying" rule of §5.3 looks at the UA only; X7 / rotate / const keeps the UA and varies the client fingerprint, which the id-keyed material also ignores, so the cell was classed as not varying. (d) The design table (§2.1) chose `M` for the `N_P` member; F-G5-01 is the cost of that choice and the registered gates measured it correctly. None changes the verdict; a new plan is needed to change any of them |
| **F-G5-07** | **The detectors fire where the guarantee is observable.** The allowance and overflow are only observable on the P lane; the device watcher's second-device effect only on the P lane (plan amendment A2) |

### Post-hoc attribution (`POST-HOC`, excluded from every gate)

A sixth arm, `NOUA-FPN`, is `NOUA` with the P-lane new-device member kept on the fingerprint (K5, micro-cap and device watcher still on the id).

| Cell | `NOUA-FPN` | equals `REF` | equals `NOUA` |
| --- | --- | --- | --- |
| STEAL | thief 9 before the change, owner wait 0, owner refused 2 | **yes** | no |
| X7 / rotate / const, X7 / uarot / const | own-account reach 11, 70 × `cap:p_device` | **yes** | no |
| UAC / none / fresh | legit-denied 9 | no | **yes** |
| UAX-NP | 2 refused (`cap:p_device`) | yes | no |

So the UA-free K5 material alone explains the `UAC` owner cost (F-G5-03) and nothing in STEAL or in the streams; the id-keyed `N_P` member alone explains
F-G5-01 / F-G5-02 and the G1 item that `REF` fails (`UAX-NP`). The two requirements pull the member in opposite directions: a browser update must not be a new
device (G1), and a thief that rotates everything but the id must not stop consuming the new-device allowance (STEAL).

## 5. Necessity (the registered detectors, `MEASURED`)

| Removed from `NOUA` | Worse than `NOUA` | Result |
| --- | --- | --- |
| UA-free material (= `REF`) | UAC12 ladder restarts, K5 key splits, `UAX-NP` refuses 2 | shown |
| id-keyed budgets (`D-IDMINT`: bucket per account + id) | BRU24 admissions 5,760 against 51 | shown |
| allowance (`D-NOALW`) | `STABLE-i` 128 / 83 / 76 against 45 / 44 / 45 | shown |
| K4 overflow (`D-NOOVF`) | same numbers; K4 score 0 against 7 | shown |
| K5 (`D-NOK5`) | `KDL` all admitted; `STABLE` 144 / 288 / 1,440 | shown |
| device watcher (`D-NOAE`) | second device refused (P); main device not escalated (U) | shown |

## 6. Plan conformance

* **Plan unchanged after the harness commit.** Gates, arms, values and verdict rules are those of `2b43e92`.
* **Amendments before the harness commit** (PLAN.md §10): A1 `MIXPRES` measured at pipeline level on the U lane (its assembled form, `MIXPRES-ASM`, is run and reported: the U-lane account bucket couples any two U requests of an account, in `REF` and `NOUA` alike); A2 the `D-NOAE` firing condition evaluated per lane. Both were found while the fixtures were first executed on the reference and primary arms; neither touches a gate on the primary arm.
* **Pre-plan pilot** (disclosed in PLAN.md §1, not committed): it reproduced DEC-021's 45 / 44 / 45 and showed the 51 of F-G5-04 before the plan was written.
* **Interpretations.** The `S0` comparisons of G5.2 use every recorded field on the U lane (the key formulas coincide) and the behavioural fields on the P lane (the key names differ by construction: `k5p`, `k4p`). The `S0` reach of `KD` / `UAC` streams is not used; the pipeline-level `KDL` / `UAC12` against `S0` stand in for it.
* **Development corrections before the first archived run** (none changes a result after it was seen): the report read the reference arm as `G4` instead of `REF` and the ablations as `G5-*` instead of `D-*`; the STEAL / BOOT / HAR / E2 / K5U builders were moved from the scenario into `lib/g5fx.php` (output verified byte-identical before the commit); `maxK4score` was added to the `STABLE` rows to measure "the overflow lands on K4P" as the plan states; `uaVarying` was added to the stream rows (ignored by V1).
* **Post-hoc material**, excluded from every gate: the `NOUA-FPN` arm and §4's attribution.

## 7. What this means for the proposal (`DERIVED`, not decisions)

| Proposal item | Effect of G5 |
| --- | --- |
| §5 rule 2 (K5 = account + id, no UA) | **Measured.** A browser update is not a new device (G1); a new or forged id mints no budget (G2); DEC-021's K5 ladder, allowance, overflow and device watcher keep their guarantees on the P family exactly (G3 items 1–3, G4); the absent-id path reproduces the assembly (G5 item 1) and DEC-021's K5 behaviour (G5, `NOID-KDL/UAC12/AE`). Cost: the `UAC` owner keeps their K5 history (F-G5-03) |
| §5 rule 2, "device" for the new-device allowance | **Not free.** `N_P` keyed by the id loosens the bound on a rotating stolen-token thief and makes the owner pay (F-G5-01, F-G5-02). The member material is a step-4 decision, not an identity-layer default |
| §7 K5 separation by the binding | The separation itself is unchanged and still necessary (G4 v2 K5U passes on `NOUA`). No new evidence against it |
| §7 "Stolen cookie" | F-G5-01 / F-G5-02 add a measured owner cost with an id-keyed `N_P`; `K4P` is not cleared by the password change |
| DEC-021 parity | P family retained; U family replaced by the bucket bound (F-G5-04), independent of this step |

No decision-record draft is prepared: the verdict is not SUPPORTED (PLAN.md §7).

## 8. Limits

Reference stores and a controlled clock; no Redis, concurrency or failover; synthetic streams; study-code tokens and a Host model, not real cookies; the id in
`sessionDeviceId` (untrusted), no public shape; the K5 migration and rollback of the design (§6) are `DERIVED` and not measured; the operational reader
(`RateLimitOperationalReader`) is not exercised; K3, K2, churn, dilution and `device_cap_account` keep the UA-inclusive fingerprint by design, so a constant id
with a rotating UA still looks like several devices to them; the stolen-token thief rotates IP and UA; the proof-acquisition paths of G2 are not combined;
multi-IP spray and `U_glob` are out of scope; test values only.
