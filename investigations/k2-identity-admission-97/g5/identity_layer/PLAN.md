# G5 — Identity layer: K5 material without the UA, K5 separation, binding — pre-registered plan (#97, #110)

> **Status:** PRE-REGISTRATION, plan **v1**. Committed **before** any G5 harness code exists and before any G5 result. Evidence
> only. **Not** an architecture, policy, threshold, contract or public-API approval. No change to `src/`, `tests/`,
> `composer.json`, `composer.lock`, presets, public contracts, ADRs, standards, DEC-015 / DEC-019 / DEC-021 (ACTIVE, unchanged) /
> DEC-022 (PROPOSED) / DEC-023 / DEC-024 (ACTIVE), or any earlier study. The proposal stays PROPOSED.
> **Baseline:** the branch is cut from `main@8d13da5`. `src/`, `composer.json`, `composer.lock` are byte-identical to the G1
> baseline `4c6ca618d8206ba502961eaf06ffb82a5d61b50d` (checked by `run.sh`).
> **Design:** [`../../IDENTITY_LAYER_DESIGN.md`](../../IDENTITY_LAYER_DESIGN.md) (committed before this plan). This plan measures
> the rules written there; it adds none.
> **Owner scope (step 3 of #97):** `U_src` 24, `F_src` 4 (net), `T` = 30 minutes and `C` = 4 are **archive values of G4 v2**, not
> choices. Test values only. No production value, no public API shape for the id, the credential or the proof.
> **G4 v2 archive:** [`../../g4/integrated_v2/`](../../g4/integrated_v2/) (and through it G4, G3, G3 v2, G1) is reused
> **read-only** and is never edited or regenerated. It supplies the builders, the gate, the Host model and the recorded numbers.

## 0. Question and sub-questions

**Question.** Can the K5 material be *account + instance id* without the UA (`IAP§5` rule 2), with K5 separated by the binding,
without losing any DEC-021 guarantee for a known device? (G4 and G4 v2 kept the UA inside the material; `G4_V2_…` §9.)

| ID | Sub-question | Gate |
| --- | --- | --- |
| Q1 | Is the same instance after a UA change the same device, with the same limits? | **G1 — UAX** |
| Q2 | Does a changed or forged instance id give any extra budget? | **G2 — IDM** |
| Q3 | Does a known device keep DEC-021's protection on each door, including after it falls to U? | **G3 — KDP** |
| Q4 | Do the allowance, K5, Anti-Equilibrium and the K4 overflow keep their guarantees? | **G4 — MECH** |
| Q5 | Is the absence of an id a defined path that reproduces today's behavior with no worse cell? | **G5 — ABS** |

## 1. Pre-plan feasibility pilot (disclosed)

Before this plan a **non-registered pilot** (scratch scripts, not committed, reference arms and throw-away ablation trees only) was
run to check that the harness can answer the question at all. It is repeated inside the registered run; none of it is a gate
result. It shaped three choices below, and the numbers are `PILOT`:

| Pilot fact | Consequence in this plan |
| --- | --- |
| The DEC-021 pipeline (repo `src/`, stable known device, Login, 24 h) gives 45 / 44 / 45 reaches at 600 / 300 / 60 s | The recorded DEC-021 numbers are reproduced by the harness; the S0 arm is run in the registered run as the DEC-021 reference |
| The G4 pipeline on the **P lane** gives 45 / 44 / 45; assembled `FULL2` with a valid token gives 45 / 44 / 45; on the **U lane** the pipeline alone gives 144 / 79 / 77 and assembled `FULL2` with the token lost gives **51** at every interval (the bucket ceiling) | DEC-021's reach number is retained on the P family and replaced by the bucket bound on the U family **in the G4 assembly itself**, independently of the K5 material. The absolute comparison of `LOST` with 45 / 44 / 45 (item 3d) is therefore **reported, not gated**, with that attribution (§5.2, G3) |
| Removing the allowance, or only the K4 overflow, changes the P-lane numbers (128 / 83 / 76) and nothing on the U lane; removing K5 or the device watcher changes both lanes | The mechanism detectors (G4) are measured at **pipeline level** on the lane where the mechanism is enforced: the P admission caps (`P_proof` per day) would mask them in the assembled arm |

## 2. Builds and arms

### 2.1 Pipeline builds (sha-256 pinned, built by `build_g5.sh`; the repository tree is never modified)

| Build | Content |
| --- | --- |
| `CAND`, `G4` | the archived builds, unchanged (G4 pipeline `45d201d0…63fd`). `G4` is the **reference** build |
| `G5` | `G4` plus `patches/g5-k5-id-material.diff`: the additive id component on `DeviceIdentityDTO` (`deviceIdHash`, `previousDeviceIdHash`), produced by `DeviceIdentityResolver` from the same normalized `sessionDeviceId` with the current and previous hasher (resolver owns the material, DFP §5.1.1); in the pipeline, K5, the K5 micro-cap and the device Anti-Equilibrium watcher use the id component **when present** and `fingerprintHash` otherwise (current and previous generation). Every other consumer of `fingerprintHash` is unchanged (design §2.1) |
| `G5-NOALW` | `G5` with the known-device allowance consumption disabled (`$allowanceLimit = null`) |
| `G5-NOOVF` | `G5` with the allowance counted and the K4 overflow delta **not** applied |
| `G5-NOK5` | `G5` with the K5 failure delta forced to 0 |
| `G5-NOAE` | `G5` with the device Anti-Equilibrium watcher disabled (`deviceAntiEquilibriumKeys` returns null) |
| `S0` | the repository's own unpatched `src/` (pipeline sha `40213945…eab6`, asserted), run in its own process with `G1_ARM` unset: the DEC-021 reference |

### 2.2 Arms

All arms replay with the G4 v2 assembly values (study values, as the archive): U lane `U_src` 24, failure cap 4 (net), bucket
C = 4, T = 1800 s with refund on success; P lane budgets `P_acct` 10 / 60, `P_proof` 6 / 30 with refund on a recorded success,
`N_P` 10 per day; token TTL 30 days sliding, absolute 90 days (E2: 600 s); taint 3; password-change binding on. Reference Host
`pre+out`.

| Arm | Build | Role |
| --- | --- | --- |
| `REF` | `G4` | `FULL2` exactly as G4 v2 (UA in the K5 material). Primary reference; must reproduce the archive (V1) |
| `NOUA` | `G5` | `FULL2` with K5 material = id. **Primary arm** |
| `D-NOALW`, `D-NOOVF`, `D-NOK5`, `D-NOAE` | the four ablation builds | detectors of G4 (and, as marked, of G3 and G5) |
| `D-IDMINT` | `G5` | detector of G2: the U-lane account bucket and failure set are keyed by *account + id* (a new id mints a new budget) |
| `S0` | `S0` | DEC-021 reference, pipeline only |

`REF` and `NOUA` run the assembled replay (admission layer, Host model, binding) and the pipeline-level fixtures. The ablation
and `S0` arms run **pipeline-level** fixtures only (§4.2); `D-IDMINT` runs BRU24 / BRU24-HV.

## 3. Input integrity (reused unchanged)

* **IN**: the archived assertion `g4v2AssertInput()` runs inside every G5 replay: every row that declares an instance id carries
  it in the DTO's `sessionDeviceId`. A violation aborts with exit 6.
* **D-IN**: the archived scenario `g4/integrated_v2/scenarios/v2_detector.php` is run **unchanged** and its output is compared
  byte for byte with the archive; a miss is exit 6.

## 4. Fixtures

### 4.1 Assembled-level (admission layer + Host), arms `REF` and `NOUA`

| ID | Shape | Used by |
| --- | --- | --- |
| **STREAMS** | the 22 G3 streams through the **corrected** G4 v2 builder, strategies as G4 v2, attack id policies `fresh` and `const`; flows `pre+out` and `pre`; **and** the same streams with no ids (`withIds = false`) | G2, G3, G5, R |
| HAR-B, HAR-N, BRU24, BRU24-HV, REC, CROSS, STEAL (password-change variant), CLEAR, E2, K5U, BOOT, BOOT-X | the G4 v2 fixtures, ported unchanged | R, G2 (HAR-N, BRU24), V1 |
| CGN-B | the archived `v2_cgn.php`, run **unchanged** (an admission-layer model with no pipeline); its output is compared byte for byte with the archive | R |
| **UAX-NP** (new) | an owner with a valid token and one id logs in successfully 12 times in one day (3,600 s apart), each with a different browser major (chrome/120 … chrome/131) | G1 |
| **FORGE** (new) | STEAL's setup up to 600 s (token copied to the thief), then the thief presents the **stolen token with a fresh id each attempt**, failing every 15 s to 43,200 s | G2 |
| **MINT** (new) | a device the Host marks known, no token, a **fresh id per attempt**, failing every 60 s for 24 h, on the U lane | G2 |
| **LOST** (new) | a device the Host marks known, **token lost** (never issued), constant id and UA, failing every 600, 300 and 60 s for 24 h | G3 |
| **TOKEN** (new) | as LOST with a valid token (P lane) | G3 |
| **UAX-ASM** (new) | an owner with a valid token and one id; 12 failures 60 s apart, the browser major changing from the 7th | G1 |
| **MIXPRES** (new) | one account, two devices of the same owner: device A sends an id, device B does not; B fails four times, then A fails twice (all known) | G5 |

### 4.2 Pipeline-level (no admission layer), arms `REF`, `NOUA`, the four ablations and `S0`

Run through the real resolver and pipeline with the controlled clock; the lane is set explicitly (`P` or `U`; `S0` has none).
Context: one account, one IP, `isDevicePreviouslyVerifiedForAccount = true`, untrusted id, Login policy; flow `pre+out` (a failure
is recorded only if the pre-check allowed). "Reach" = pre-checks allowed.

| ID | Shape |
| --- | --- |
| **STABLE-i** (i = 600, 300, 60) | constant id and UA, failing every i seconds for 24 h |
| **KDL** | 12 failures 60 s apart, constant id and UA (the G3 `KD` shape) |
| **UAC12** | as KDL with the browser major changing from the 7th failure, **same id** (the G3 `UAC` shape) |
| **UAX24** | failing every 60 s for 24 h; the browser major changes at 12 h, same id. Control: the same without the change |
| **AE** | 4 failures, 3 pre-checks inside the soft window, 300 s later a pre-check and a failure, then pre-checks of the same device and of the owner's **second device** (another id) — the `KnownDeviceAntiEquilibriumScopeTest` sequence |
| **ROT-i** (i = 300) | a **fresh id per attempt**, constant UA, failing every i seconds for 24 h |
| **UAROT-i** (i = 300) | constant id, the UA alternating every i seconds |
| **NOID-\*** | STABLE-300, KDL, UAC12 and AE with **no id** (the A0 path) |

Each KDL / UAC12 / AE row records the decision string, the K5 key and score, the persisted level on the K5 key and on K4, the
allowance counter and its key (lane purpose included), and, for the second device, the pre-check decision.

## 5. Gates

### 5.1 Harness validity

| Gate | Condition | On failure |
| --- | --- | --- |
| **V0** | the no-id rows of the G5 replay on `CAND` (S24) equal the G4 v2 archive's V0 rows (34 / 34, every field the archive checks) | exit 5 |
| **V1** | `REF` reproduces the G4 v2 archive's `FULL2` rows **field for field** (arm label aside) on every STREAMS row (both flows), HAR-B, HAR-N, BRU24, BRU24-HV, REC, CROSS, STEAL, CLEAR, E2, K5U, BOOT, BOOT-X, and the CGN-B bytes. Also: the pipeline-level `STABLE-i` and `KDL` rows of `REF` (P lane) equal those of `S0` (45 / 44 / 45) | exit 5 |
| **IN**, **D-IN** | §3 | exit 6 |

### 5.2 The five gates (all mandatory; `NOUA` unless stated)

**G1 — UAX (same instance, UA change = same device, same limits).**
1. `UAC12`: the decision string equals the `KDL` string, on the P lane **and** on the U lane.
2. The K5 key of the context before the UA change equals the K5 key after (computed by the harness from the resolver's id
   component), and the K5 score read at the end is under that one key.
3. The allowance counter after `UAC12` equals its value after `KDL` and is read from one key.
4. `UAX24`: reach equals the control's reach (P and U lane).
5. `UAX-ASM`: every request of the owner stays on the **P** lane after the UA change (the binding is to the instance).
6. `UAX-NP`: 0 refusals, every request on P (the daily new-device allowance is keyed by the same material).
**Detector:** `REF` must fail at least one of conditions 1, 2 and 6.

**G2 — IDM (a changed or forged id gives no extra budget).**
1. Best-response reach against the recorded G4 v2 `FULL2` values: BRU24 attacker admissions, and the best-response reach (max
   over strategies and id policies of `reachForeign + reachProof`, as the archived report) of X1, BRU and BRD, and of HAR-N's
   attack rows: each `≤` the archive's `FULL2` value. (The archive values are the G4 v2 report's 51, and 4 / 5 / 5; the run
   reads them from the archive, it does not copy them.)
2. `FORGE`: the thief's P-lane admissions are **0**, and its U-lane reach is `≤` `REF`'s.
3. `MINT`: reach on the U lane is `≤` `REF`'s and `≤ 52` (the registered ceiling `C + 86,400 / T`).
**Detector:** `D-IDMINT` admits more than 52 attacker requests on BRU24.

**G3 — KDP (a known device keeps DEC-021's protection on each door).**
1. **P family, absolute.** `TOKEN` reach at 600 / 300 / 60 s equals the `S0` value of `STABLE-i` (the recorded 45 / 44 / 45) and
   equals `REF`'s; pipeline-level `STABLE-i` on the P lane equals the same.
2. **K5 ladder, both doors.** `KDL` decision string equals `S0`'s and `REF`'s on the P lane and on the U lane.
3. **UA change, not weaker than DEC-021.** `UAC12` reach `≤ S0`'s `UAC12` reach, on both lanes.
4. **U family, relative.** `LOST` reach (assembled, token lost) is `≤` `REF`'s at each interval; and on the U lane the K5 ladder
   (item 2) holds.
5. **Streams.** On `KD` and `UAC` (STREAMS, `pre+out`) `NOUA`'s legit-denied and reach are `≤` `REF`'s.
**Reported, not gated (3d):** `LOST` reach against 45 / 44 / 45. The pilot already shows 51 for `REF`; by §1 this is a property of
the G3 / G4 U lane, attributed by the `REF` row, and is a step-4 question. A `NOUA` value above `REF`'s fails item 4.
**Detector:** `D-NOK5` and `D-NOALW` each differ from `NOUA` on `KDL` / `STABLE-300` (P lane).

**G4 — MECH (the four mechanisms keep their guarantees; each detector must fire).**
Pipeline-level, `NOUA == REF == S0` on each row below, and the ablation must be strictly worse:

| Mechanism | Fixture (lane) | Guarantee measured | Detector arm and firing condition |
| --- | --- | --- | --- |
| allowance | `STABLE-300`, `STABLE-600`, `STABLE-60` (P) | reach 44 / 45 / 45 | `D-NOALW` reach `>` `NOUA`'s at every interval |
| K4 overflow | same | same, and the overflow delta lands on `K4P` | `D-NOOVF` reach `>` `NOUA`'s at every interval |
| K5 | `KDL` (P and U) | decision string; the persisted level is on the K5 key (id-derived) and **not** on K4; the second device is allowed | `D-NOK5` string differs (more admissions) on both lanes |
| Anti-Equilibrium, one watcher | `AE` (P and U) | decision string; no K4 block; the second device is allowed | `D-NOAE`: the second device is **not** allowed |

Also reported: on the U lane the allowance and K4 overflow have no observable enforcement (`STABLE-300` U equal in `NOUA`,
`D-NOALW`, `D-NOOVF`), recorded as a finding with the design §5 row 4 attribution.

**G5 — ABS (the absence of an id is a defined path with no worse cell).**
1. STREAMS with no ids, both flows: every row of `NOUA` equals `REF`'s on every field.
2. `NOID-*` pipeline fixtures: `NOUA == REF == S0` on every recorded field (UAC12 differing from KDL, as DEC-021 ships).
3. `MIXPRES`: the K5 keys of A and B differ, and A's decisions equal A's decisions in the same fixture without B's failures.
**Detector:** `D-NOK5` differs from `NOUA` on `NOID-KDL` (the no-id fixtures can see a lost K5).

### 5.3 Regression checks (verdict-relevant, §6 rule 4)

`E2`, `K5U`, `STEAL`, `CROSS`, `HAR-B` (assembled, `NOUA` row equals `REF`'s on every field, and both equal the archive), and
`BOOT-X`, `CGN-B` as they are (BOOT-X `NOUA` row equals `REF`'s; CGN-B bytes equal the archive). On STREAMS with ids, every cell
that is **not UA-varying** is identical between `NOUA` and `REF`. A cell is *UA-varying* when some instance id of its rows carries
two or more normalized UAs; the set is computed by the harness from the rows. For those cells the report lists every difference,
and the attacker's reach `reachForeign + reachProof` of `NOUA` must be `≤` `REF`'s (a G2 extension).

## 6. Verdict rules (fixed now)

1. A harness-validity failure (V0, V1, IN, D-IN) ⇒ no verdict; the run is invalid.
2. A gate whose detector does not fire is **not evidenced**: it counts as a failed gate, named.
3. **SUPPORTED — LIMITED SCOPE** if G1–G5 all pass with every detector firing **and** no regression check fails; otherwise
   **NO-GO AFTER EXPERIMENT**, naming every failed gate (`G1` … `G5`, or `R:<fixture>`).
4. A regression failure is named `R:<fixture>` and counts as a failed gate: a regression would mean a guarantee was lost.
5. No gate is re-scoped after a result. A failure of a gate does **not** re-open fixed evidence (the collective penalties removal,
   the best-response rule, the G4 v2 pre+out results). A change needs a new plan.
6. Nothing here is an Architecture GO, an ADR, a production value, an Owner decision on `U_src`, `T` or K5, or a statement that
   Runtime is ready.

## 7. Reporting rules

* Every table separates **measured as planned** from **post-hoc**, **pipeline fixtures** from **models** (CGN-B), and `REF` from
  `NOUA`. The report states, per number, whether it equals the archive.
* The 3d comparison and the U-lane allowance finding are reported with their attribution; neither changes the verdict.
* The proposal receives dated "Post-G5" notes (English and Arabic). If the verdict is SUPPORTED, a PROPOSED decision-record draft
  for the identity layer (K5 material, separation, binding) is prepared for step 4, **without any value**.

## 8. What this cannot prove

Real cookies, tokens and Hosts; Redis, concurrency, failover, DEC-015 degraded mode; the id's public shape; real browser-update
traffic; the K5 migration and rollback (design §6, `DERIVED`, not measured); the operational reader; K3 / K2 / churn / dilution
behavior under a constant id with a rotating UA (unchanged by design, design §2.1); the proof-acquisition paths of G2; multi-IP
spray and `U_glob`; production values. The password-change event is a modelled Host event.

## 9. Reproduction

```bash
bash investigations/k2-identity-admission-97/g5/identity_layer/run.sh
```

Builds the G4 trees with the archived builder and the G5 trees with `build_g5.sh` (patches sha-256 pinned), runs every scenario
under `-d memory_limit=1G`, the archived D-IN and CGN-B scenarios unchanged, the `S0` scenario in its own process, and the
report; diffs against `expected/`. Exit 0 = SUPPORTED; 3 = harness valid and NO-GO; 5 = V0 / V1 miss; 6 = IN / D-IN failure;
1 = output differs or a scenario fails. `UPDATE=1` regenerates `expected/`.
