# G5 v2 — Identity layer after the G5 results: pre-registered plan (#97, #110, step 3)

> **Status:** PRE-REGISTRATION, plan **v1.1** (v1 = commit `da1e9fc`; the two amendments of §10 were committed before the harness commit and before any
> archived result). Committed **after** the design amendments
> ([`../../IDENTITY_LAYER_DESIGN.md`](../../IDENTITY_LAYER_DESIGN.md) §"v2 amendments", commit `ed4176d`) and **before** any G5 v2 harness code
> and before any G5 v2 result. Evidence only. **Not** an architecture, policy, threshold, contract or public-API approval. No change to `src/`,
> `tests/`, `composer.json`, `composer.lock`, presets, public contracts, ADRs, standards, DEC-015 / DEC-019 / DEC-021 (ACTIVE, unchanged) /
> DEC-022 (PROPOSED) / DEC-023 / DEC-024 (ACTIVE), `DECISIONS_INDEX.md`, or any earlier study or archive. The proposal stays PROPOSED.
> **Baseline:** the branch is cut from `main@8f18780` (G5, PR #137, merged). `src/`, `composer.json`, `composer.lock` are byte-identical to the G1
> baseline `4c6ca618d8206ba502961eaf06ffb82a5d61b50d` (checked by `run.sh`).
> **Archives, read-only:** [`../identity_layer/`](../identity_layer/) (G5: its library, scenarios, builder, patches, `expected/`) and
> [`../../g4/integrated_v2/`](../../g4/integrated_v2/) (and through them G4, G3 v2, G3, G1) are reused read-only and never edited or regenerated.
> **Owner scope (step 3):** `U_src` 24, `F_src` 4 (net), `T` = 30 minutes and `C` = 4 are **archive values of G4 v2**, not choices. Test values only.
> No production value, no public API shape for the id, the credential or the proof.

## 0. Question and what changes against G5

G5 showed that K5 without the UA, keyed by the instance id, keeps every DEC-021 guarantee on the P family (G1 items 1–5, G2, G3 items 1–4,
G4, G5 items 1 and 3), and that its failures are not in the K5 material: they are the id-keyed `N_P` member (F-G5-01, F-G5-02) and three plan
defects (F-G5-06). G5 v2 re-runs the question with **one new plan** that fixes those and nothing else:

| ID | Change | Where it is decided |
| --- | --- | --- |
| C1 | `N_P` (P-lane new-device member) on the **fingerprint**; K5, micro-cap and device watcher stay on the **id** | design V2-1 |
| C2 | the fate of `K4P` at a password change: arms **A** (primary, registered now), **B**, **C**; B and C are reporting | design V2-2, §2.2 |
| C3 | `UAX-NP` is a **declared cost**, not a gate on zero (registered now, §5.2 G1 item 6) | §5.2 |
| C4 | G3 item 5 measures stream `UAC` against the **designed behavior** (one K5 across a browser update) | F-G5-06 (a), §5.2 |
| C5 | the U-lane `STABLE-300` comparison with `S0` leaves G5 item 2 and becomes reporting **F-G5-04** with one written step-4 proposal | F-G5-06 (b), §7 |
| C6 | the "UA-varying" rule of §5.3 is widened to **material-varying** (UA or client fingerprint) | F-G5-06 (c), §5.3 |

Everything else — V0, V1, IN, D-IN, gates G1, G2, G4 verbatim, G3 items 1–4, the regression list, the archive values — is G5's, unchanged.

| ID | Sub-question | Gate |
| --- | --- | --- |
| Q1 | Is the same instance after a UA change the same device for K5, with the declared cost on `N_P`? | **G1 — UAX** |
| Q2 | Does a changed or forged instance id give any extra budget? | **G2 — IDM** |
| Q3 | Does a known device keep DEC-021's protection on each door, including after it falls to U? | **G3 — KDP** |
| Q4 | Do the allowance, K5, Anti-Equilibrium and the K4 overflow keep their guarantees? | **G4 — MECH** |
| Q5 | Is the absence of an id a defined path that reproduces today's behavior with no worse cell? | **G5 — ABS** |
| Q6 | Does the stolen-token thief stay at or below the reference, and does the owner enter at once after the change? | **R:STEAL** |

## 1. Pre-plan disclosure

No G5 v2 pilot was run. Known before this plan and used by it, all `MEASURED` in the G5 archive: the post-hoc arm `NOUA-FPN` (K5 on the id, `N_P` on the fingerprint,
`K4P` account-wide and never cleared) equals the reference on STEAL, on `UAX-NP` and on the two X7 stream cells, and equals G5's `NOUA` on `UAC`. That is exactly this plan's primary arm
(`NOUA2`, §2.2). Consequences, stated so that nobody reads them as independent confirmation: (1) the gates that the post-hoc arm already answered are *expected* to pass, and what
the registered run adds is the same measurement under registered, detector-backed gates (and V3, §3); (2) the primary `K4P` arm was chosen with that knowledge (design V2-2) and the
reason it is also the arm with no new mechanism is given there. The build script of G5 was executed once to inspect the resulting trees; nothing was measured.

## 2. Builds and arms

### 2.1 Pipeline builds (sha-256 pinned; the repository tree is never modified)

| Build | Content |
| --- | --- |
| `CAND`, `G4` | the archived builds, built by the G5 builder run unchanged (`G4` pipeline `45d201d0…63fd`). `G4` is the **reference** build |
| `G5V2` | the G5 tree (`G4` + `../identity_layer/patches/g5-k5-id-material.diff`, K5 / micro-cap / device watcher on the id) plus [`patches/g5v2-k4p-scope.diff`](patches/g5v2-k4p-scope.diff): a settable **scope** for the account-wide P penalty state (the `k4p` key, hence score, levels and budget; the account Anti-Equilibrium watcher and the budget cooldown of the P family). With no scope set (`null`) the pipeline is byte-for-byte the behavior of G5; with a scope the P-lane keys carry a suffix. It never touches `N_P` (not in `src/`, design V2-1) and never touches the K5 consumers |
| `G5V2-NOALW`, `G5V2-NOOVF`, `G5V2-NOK5`, `G5V2-NOAE` | `G5V2` plus the **four G5 ablation patches, applied unchanged** (their sha-256 are asserted against the values pinned in the G5 builder) |
| `S0` | the repository's own unpatched `src/` (pipeline sha `40213945…eab6`, asserted), run in its own process with `G1_ARM` unset: the DEC-021 reference |

### 2.2 Arms

All assembled arms replay with the G4 v2 assembly values (as G5): U lane `U_src` 24, failure cap 4 (net), bucket C = 4, T = 1800 s with refund on success; P lane `P_acct`
10 / 60, `P_proof` 6 / 30 with refund on a recorded success, `N_P` 10 per day; token TTL 30 days sliding, absolute 90 days (E2: 600 s); taint 3; password-change binding on.
Reference Host `pre+out`.

| Arm | Build | `N_P` member | K5 material | `K4P` rule | Role |
| --- | --- | --- | --- | --- | --- |
| `REF` | `G4` | fingerprint | fingerprint | A | `FULL2` as G4 v2. Primary reference; **the G5 scenarios run unchanged** (§3, V2) |
| **`NOUA2`** | `G5V2` | **fingerprint** | id | **A** | **Primary arm.** Rule A: `K4P` is account-wide and not cleared (design V2-2) |
| `NOUA2/B` | `G5V2` | fingerprint | id | B (account + epoch) | **Reporting** (STEAL family only) |
| `NOUA2/C` | `G5V2` | fingerprint | id | C (account + credential `jti`) | **Reporting** (STEAL family only) |
| `D-NPID` | `G5V2` | **id** | id | A | **Detector** of the member (and G5's `NOUA`, reproduced: V3) |
| `D-NPID/B`, `D-NPID/C` | `G5V2` | id | id | B, C | **Reporting**: would B or C have repaired F-G5-02 under the id-keyed member |
| `D-NOALW`, `D-NOOVF`, `D-NOK5`, `D-NOAE` | the four ablation builds | — | — | A | detectors of G4 (and, as marked, of G3 and G5), pipeline level |
| `D-IDMINT` | `G5V2` | fingerprint | id | A | detector of G2 (the U-lane account bucket keyed by account + id) |
| `S0` | `S0` | — | — | — | DEC-021 reference, pipeline only |

**`K4P` rules, as measured** (`G5V2` only; the harness sets the scope before every request of the replay):

| Rule | Scope set on the P lane | Meaning |
| --- | --- | --- |
| A | none (`null`) | G4 v2 / G5 behavior |
| B | `e<epoch of the account at the request>` | an epoch bump leaves the old state unreachable |
| C | `j<jti of the presented credential>` | one penalty state per credential |

The **primary arm is `NOUA2` (member on the fingerprint, rule A).** It is registered here, before any run. Arms B and C, and the `D-NPID` arms, appear in no gate except where §5 names them
as detectors; a result in B or C never changes the verdict.

## 3. Input integrity and validity (reused, plus three checks)

* **IN**: the archived assertion `g4v2AssertInput()` runs inside every replay: every row that declares an instance id carries it in the DTO's `sessionDeviceId`. Exit 6.
* **D-IN**: the archived `g4/integrated_v2/scenarios/v2_detector.php` is run **unchanged** and compared byte for byte with the archive. Exit 6.
* **NP-IN** (new): in every assembled replay of a G5V2 arm, after each P-lane admission, the harness reads the **admission store's member sets** (the `p_device` sets of the reference
  store, independently of the gate's variable) and requires: for a `fingerprint` arm, the member derived from the request's `fingerprintHash` is present in a set and the member
  derived from its id component is present in none; for the `id` arm (`D-NPID`) the reverse. Counts are emitted per scenario and arm. A violation aborts with exit 7.
  This is what makes "`N_P` is on the fingerprint" a measured fact of the run rather than a sentence of the design.
* **V0**: the no-id rows of the replay on `CAND` (S24) equal the G4 v2 archive's V0 rows (34 / 34). **V1**: `REF` equals the G4 v2 archive's `FULL2` rows field for field on every STREAMS row,
  HAR-B, HAR-N, BRU24, BRU24-HV, REC, CROSS, STEAL, CLEAR, E2, K5U, BOOT, BOOT-X, the CGN-B bytes, and pipeline-level `STABLE-i` / `KDL` of `REF` (P lane) equal `S0`. Exit 5.
* **V2** (new): every reference-side output of this run (`CAND` / `G4` / `S0` scenarios of G5, the archived D-IN and CGN-B) is **byte-identical to the G5 archive**: the G5 scenarios are run
  **unchanged** for the reference arm, so a difference means the harness or a build changed. Exit 5.
* **V3** (new): the primary arm is the arm G5 measured post-hoc, and the detector arm is G5's primary: `NOUA2` rows equal the G5 archive's post-hoc rows (`g5_posthoc.G5.jsonl`: STEAL, `UAX-NP`,
  and the three stream cells), and the `D-NPID` STEAL row equals the G5 archive's `NOUA` STEAL row, field for field (arm label aside). This proves that the new patch is inert with no scope set and
  that the new harness reproduces the old one where it should. **[A1]** The `REF` STEAL and `UAX-NP` rows produced by the new harness code (arm `REF` of `g5v2_costs`) must equal the G5 archive's `REF` rows
  likewise, so the port is checked on the reference build too. Exit 5.

## 4. Fixtures

### 4.1 Assembled (admission layer + Host), arms `REF`, `NOUA2`, `D-IDMINT` (BRU24 / BRU24-HV only)

G5's fixtures, unchanged in shape and encoding: **STREAMS** (the 22 G3 streams, attack id policies `fresh` and `const`, flows `pre+out` and `pre`, and the same streams with no ids), HAR-B, HAR-N, BRU24, BRU24-HV,
REC, CROSS, CLEAR, E2, K5U, BOOT, BOOT-X, **UAX-NP**, FORGE, MINT, LOST, TOKEN, UAX-ASM, MIXPRES-ASM. CGN-B is the archived script run unchanged. Each stream row of `NOUA2` also records `matVarying`
(§5.3).

### 4.2 The STEAL family (arms `REF` and the six G5V2 arms of §2.2)

| ID | Shape | Used by |
| --- | --- | --- |
| **STEAL** | G5's STEAL (password-change variant), unchanged: owner token copied to a thief at 600 s, the thief fails every 15 s (rotating IP and UA, the owner's id and token) to 43,200 s, the owner changes the password there, the owner then tries every 60 s from 43,260 s until admitted | R:STEAL |
| **STEAL2** (new, reported) | STEAL on an account with **two credential holders**: the owner (O1) and a second device (O2, id `i:o2`, its own credential issued at 0). O2 logs in successfully every 600 s from 120 s. The thief copies O1's credential. Same event at 43,200 s (an epoch bump voids every credential; only O1's device is re-issued). Recorded: O2 denials before the change (count, by cause), O2's lane and wait after it, O1 as in STEAL | reporting: cost to a second proof holder |
| **MULTITOK** (new, reported) | one account, **eight** credentials (eight holders `mt1`…`mt8`, one id each, issued at 0); an attacker holding all eight fails every 15 s (rotating IP and UA, round robin over the credentials) from 60 s to 86,399 s; no password change | reporting: cost of rule C (K4P stops being account-wide) |
| **MULTITOK-S** (new, reported, **[A2]**) | MULTITOK with each credential presented from **one fixed (IP, UA) pair** (eight presentations, so `N_P` on the fingerprint does not bind first) instead of rotating IP and UA on every request | reporting: cost of rule C under the primary member |
| **UAX-NP-K** (new, reported) | `UAX-NP` with `k` distinct browser majors over the twelve logins (`k` ∈ 1, 2, 5, 10, 11, 12; the major index is ⌊12-login index × k / 12⌋), one id, one credential | reporting: the cost curve of `N_P` on the fingerprint |

STEAL is the only member of this family that is gated.

### 4.3 Pipeline level (no admission layer), arms `REF`, `NOUA2`, the four ablations and `S0`

G5's, unchanged (STABLE-i, KDL, UAC12, UAX24, AE, ROT-300, UAROT-300, NOID-STABLE-300, NOID-KDL, NOID-UAC12, NOID-AE, MIXPRES), P and U lane, run through `G5V2` and its ablations with no scope set.

## 5. Gates

### 5.1 What a gate is

A gate is **all** of its numbered items. A gate whose detector does not fire is not evidenced and counts as failed, named. `NOUA2` unless stated.

### 5.2 The gates (amended texts are marked **[v2]**)

**G1 — UAX (same instance, UA change = same device for K5).**
1. `UAC12`: the decision string equals the `KDL` string, on the P lane **and** on the U lane.
2. The K5 key before the UA change equals the K5 key after, and the K5 score is read under that one key.
3. The allowance counter after `UAC12` equals its value after `KDL` and is read from one key.
4. `UAX24`: reach equals the control's reach (P and U lane).
5. `UAX-ASM`: every request of the owner stays on the **P** lane after the UA change.
6. **[v2]** `UAX-NP` is a **declared cost**, accepted here before the run: twelve successful logins a day, a new browser major each time, against `N_P` = 10 per day, refuse `12 − 10 = 2` logins, exactly as
   the reference does; the identity layer neither adds nor removes any. It is **not** a gate on zero. What is gated: `NOUA2` refuses **the same number as `REF`** and every refusal has the cause
   `cap:p_device`. The acceptance is the plan's, on the Lead's instruction; the Owner may revise it for step 4. `UAX-NP-K` (§4.2) reports the cost curve.
**Detector [v2]:** `REF` fails at least one of items 1, 2 and 4 **and** `D-NPID` refuses fewer logins than `REF` on `UAX-NP` (the check can see the member).

**G2 — IDM (a changed or forged id gives no extra budget).** G5's, verbatim:
1. Best-response reach against the recorded G4 v2 `FULL2` values (read from the archive): BRU24 attacker admissions, the best-response reach of X1, BRU and BRD, and HAR-N's attack rows: each `≤` the archive's value.
2. `FORGE`: the thief's P-lane admissions are **0**, and its U-lane reach is `≤` `REF`'s.
3. `MINT`: U-lane reach `≤` `REF`'s and `≤ 52`.
**Detector:** `D-IDMINT` admits more than 52 attacker requests on BRU24.

**G3 — KDP (a known device keeps DEC-021's protection on each door).**
1. **P family, absolute.** `TOKEN` reach at 600 / 300 / 60 s equals `S0`'s `STABLE-i` and equals `REF`'s; pipeline-level `STABLE-i` on the P lane equals the same.
2. **K5 ladder, both doors.** `KDL` string equals `S0`'s and `REF`'s on the P lane and on the U lane.
3. **UA change, not weaker than DEC-021.** `UAC12` reach `≤ S0`'s, on both lanes.
4. **U family, relative.** `LOST` reach is `≤` `REF`'s at each interval.
5. **[v2] Streams `KD` and `UAC` (`pre+out`, every id policy that carries ids).**
   (a) the attacker reach (`reachForeign + reachProof`) of `NOUA2` is `≤` `REF`'s on both streams;
   (b) on `KD` (no UA change) legit-denied of `NOUA2` is `≤` `REF`'s;
   (c) the **designed behavior** of the UA-free K5 on `UAC`: for the same id policy, `NOUA2`'s `UAC` decision string and legit-denied **equal `NOUA2`'s own `KD`** (one K5 across the browser update; the two streams
   differ only in the UA from the seventh failure).
   The owner's excess denials against `REF` on `UAC` (G5: 6 → 9) are a **declared cost, reported with their numbers** (§7), not a loss.
**Reported, not gated (3d):** `LOST` reach against 45 / 44 / 45 (F-G5-04, §7).
**Detector:** `D-NOK5` and `D-NOALW` each differ from `NOUA2` on `KDL` / `STABLE-300` (P lane).

**G4 — MECH.** G5's, verbatim, with `NOUA2` in place of `NOUA` and the `G5V2-*` ablations in place of `G5-*`: allowance, K4 overflow (`STABLE-600 / 300 / 60`, P lane: `NOUA2 == REF == S0` in reach and in max K4 score; detectors
`D-NOALW`, `D-NOOVF` strictly worse at every interval; `D-NOOVF` max K4 score below `NOUA2`'s); K5 (`KDL`, P and U: string `= REF = S0`, level persisted on the K5 key and not on K4, second device allowed; `D-NOK5` string differs); Anti-Equilibrium,
one watcher (`AE`, P and U: string `= REF = S0`, no K4 block, second device allowed; `D-NOAE`: second device not allowed on P, main device not escalated on U).

**G5 — ABS (the absence of an id is a defined path with no worse cell).**
1. STREAMS with no ids, both flows: every row of `NOUA2` equals `REF`'s on every field.
2. **[v2]** `NOID-*` pipeline fixtures, both lanes: `NOUA2 == REF` on every recorded field; and `NOUA2 == S0` on the behavioral fields of `NOID-KDL`, `NOID-UAC12`, `NOID-AE` (both lanes, `NOID-UAC12` differing from
   `NOID-KDL` exactly as DEC-021 ships) and of `NOID-STABLE-300` **on the P lane**. The U-lane `NOID-STABLE-300` comparison with `S0` is **removed from the gate**: it is a property of the U lane of G3 / G4 (no K4 escalation), not of
   the identity layer, and is reported as **F-G5-04** (§7). The U-lane `NOID-STABLE-300` of `NOUA2` still equals `REF`'s (kept in the first clause).
3. `MIXPRES` (pipeline level, U lane): the K5 keys of A and B differ, and A's decisions equal A's decisions in the same fixture without B's failures.
**Detector:** `D-NOK5` differs from `NOUA2` on `NOID-KDL` (P and U).

### 5.3 Regression checks (verdict-relevant)

`E2`, `K5U`, `CROSS`, `HAR-B`, `HAR-N`, `BRU24`, `BRU24-HV`, `REC`, `CLEAR`, `BOOT`, `BOOT-X` (the `NOUA2` row equals `REF`'s on every field, and `REF`'s equals the archive), `CGN-B` (bytes equal the archive).
Plus:

* **R:STEAL** (registered, primary arm, rule A). All of: (1) `thiefPBeforeChange` of `NOUA2` `≤` `REF`'s; (2) `thiefPMaxPerWindow` `≤` `REF`'s; (3) `ownerWaitAfterChange` is **0** (the owner is admitted at the first
  attempt after the change) and `ownerLaneAtAdmission` equals `REF`'s. **Detector:** `D-NPID` has `thiefPBeforeChange` above `REF`'s. If the detector does not fire, R:STEAL is not evidenced and fails.
* **R:STREAMS**: every STREAMS row of a cell that is **not material-varying** is identical between `NOUA2` and `REF` (every field). A cell is *material-varying* **[v2]** when some instance id of its rows
  resolves to **two or more distinct `fingerprintHash` values** (the resolver's own normalization of the UA and of the client fingerprint; the id component is the same for one id, so any difference
  is the UA or the client fingerprint). The set is computed by the harness from the rows. For material-varying cells the report lists every difference.
* **R:STREAMS-UA**: on material-varying cells the attacker reach (`reachForeign + reachProof`) of `NOUA2` is `≤` `REF`'s.
* **R:STREAMS-X7** (new): the cells `X7 / rotate / const` and `X7 / uarot / const` (both flows) equal `REF` on every field. They are material-varying and are expected to equal the reference now that `N_P` is back on the
  fingerprint; **if they differ it is a new finding, not a classification.**

## 6. Verdict rules (fixed now)

1. A harness-validity failure (V0, V1, V2, V3, IN, D-IN, NP-IN) ⇒ no verdict; the run is invalid.
2. A gate whose detector does not fire is **not evidenced**: it counts as a failed gate, named.
3. **SUPPORTED — LIMITED SCOPE** if G1–G5 (the **amended** texts of §5.2) all pass with every detector firing **and** no regression check fails; otherwise **NO-GO AFTER EXPERIMENT**, naming every failed gate
   (`G1` … `G5`, or `R:<check>`).
4. A regression failure is named `R:<check>` and counts as a failed gate.
5. No gate is re-scoped after a result. A failure does **not** re-open fixed evidence: the G5 results on the P family, G4 v2 in `pre+out`, the removal of the collective penalties. A change needs a new plan.
   Arms B and C never rescue a failed R:STEAL, and never fail a passing one.
6. Nothing here is an Architecture GO, an ADR, a production value, an Owner decision on `U_src`, `T`, K5, `N_P` or `K4P`, or a statement that Runtime is ready.

## 7. Reporting rules

* Every table separates **measured as planned** from **post-hoc**, **pipeline fixtures** from **models** (CGN-B), `REF` from `NOUA2`, gated from reported. The report states, per number, whether it equals the archive.
* **Reported with their numbers, not gated:**
  * **K4P arms.** STEAL, STEAL2 and MULTITOK for every arm of §2.2: the cost to the owner (denials before the change, wait after), to a second proof holder (STEAL2: O2 denials, lane and wait), to the thief
    (admissions before and after), and to the account when the attacker holds several credentials (MULTITOK), for rule A, B and C and for each member material.
  * **`UAX-NP-K`** and the `NP-IN` counts.
  * **UAC.** The owner's legit-denied against `REF` and against `NOUA2`'s own `KD` (declared cost, G3 item 5).
  * **F-G5-04 — the U-family known device.** `LOST` 51 / 51 / 51 against 45 / 44 / 45 under DEC-021 and the pipeline-only U lane (144 / 79 / 77), with `REF` equal; the U-lane `NOID-STABLE-300` against `S0`.
    It is a property of the U lane of G3 / G4, independent of this step. **One written proposal for step 4, not measured here:** a known device that lost its token on U stays under DEC-021's allowance and
    K4 overflow, instead of being bounded only by the account bucket ceiling.
  * Material-varying STREAMS cells with a difference, every difference listed.
* The proposal receives dated "Post-G5 v2" notes (English and Arabic). If the verdict is SUPPORTED, a PROPOSED decision-record **draft** for the identity layer (K5 material, `N_P` on the fingerprint, separation, binding, the
  `K4P` rule) is prepared **without any value** and **without indexing** (`DECISIONS_INDEX.md` is not touched).

## 8. What this cannot prove

Real cookies, tokens and Hosts; Redis, concurrency, failover, DEC-015 degraded mode; the id's public shape; real browser-update traffic (the number of updates per day an owner meets is not measured); the K5 migration and
rollback (design §6, `DERIVED`); the operational reader; K3 / K2 / churn / dilution behavior under a constant id with a rotating UA (unchanged by design); the proof-acquisition paths of G2; multi-IP spray and `U_glob`;
the step-4 U-lane known-device bound; production values. The password-change event is a modelled Host event; the strength of the Host's proof of it (what rule B would depend on) is not measured. `N_P` is a model of
the admission layer, not `src/` code.

## 9. Reproduction

```bash
bash investigations/k2-identity-admission-97/g5/identity_layer_v2/run.sh
```

Builds the G4 and G5 trees with the archived builders (run unchanged), then the `G5V2` trees with `build_g5v2.sh` (patches sha-256 pinned), runs every scenario under `-d memory_limit=1G` (**passed to every `php` call, the first included**),
the G5 scenarios unchanged for the reference arm, the archived D-IN and CGN-B scenarios unchanged, the `S0` scenario in its own process, and the report; diffs against `expected/`. Exit 0 = SUPPORTED; 3 = harness valid and NO-GO;
5 = V0 / V1 / V2 / V3 miss; 6 = IN / D-IN failure; 7 = NP-IN failure; 4 = baseline / provenance; 1 = output differs or a scenario fails. `UPDATE=1` regenerates `expected/`.

## 10. Amendments (before the harness commit and before any archived result)

Both were found while the harness was being developed; neither touches a gate, an arm, a value or a verdict rule. They are listed so that they can be audited against `da1e9fc`. The development run that
motivated A2 (one execution of the cost scenario on the six G5V2 arms, not archived) showed that with `N_P` on the fingerprint a rotating attacker is bounded at 10 P admissions by `N_P` itself in every `K4P` arm,
which hides the cost of rule C; no gate depends on it.

| # | Amendment | Why |
| --- | --- | --- |
| A1 | V3 also requires the `REF` STEAL and `UAX-NP` rows produced by the new code to equal the G5 archive's | the reference arm of the cost scenario is run by the new code (the G5 scenarios cannot run STEAL2, MULTITOK or the `K4P` arms); the check proves that the port reproduces the archived reference row. It can only make the run stricter |
| A2 | `MULTITOK-S` is added to §4.2 (reporting only) | with rotating presentations `N_P` binds first in every arm; the fixed-presentation variant measures what rule C costs when the attacker is not stopped by `N_P` |
