# Stage 5-c-0 plan — the atomic activation of the admission layer (#97, step 5, last stage)

> **Status:** PLAN ONLY (stage 5-c-0). No code, no test, no decision record, no index line, no canonical document is changed by the PR that adds this file. Nothing here is a decision: every item marked "recommendation" is the executor's, for the Owner or the Lead to rule on. Base: `main@05922dd` (#147, 5-b3b).
> **Binding inputs:** `RUNTIME_IMPLEMENTATION_CONTRACT.md` (RIC) §0.1, §1.1, §5, §6, §7, §8, §9.3 and Appendix A; DEC-019 Decisions 5 and 6; DEC-021; DEC-022 (`PROPOSED`) Decisions 1–4 and "Conditions to become ACTIVE"; DEC-023; DEC-024 points 1–4 and C-c1…C-c7; DEC-025; Decision Governance §3, §5, §6, §9; the stage records `5b2/`, `5b2.1/`, `5b2.2/`, `5b3a/`, `5b3b/` and the Lead reviews of #144–#149 (the 5-b3b review is [#147 comment 6297302409](https://github.com/Maatify/php-rate-limiter/pull/147#issuecomment-6297302409), notes N-147-01…04).
> **Rules kept from every earlier stage:** plan before code; red tests before the implementation; no registered condition is changed after it has run; a contract question is raised as an LT before the build; no existing test is edited without a ruling; a confirmed finding blocks Ready and merge.
> **Line citations** are `main@05922dd`.
> **Since commit 1 of the atomic stage (§7, base `main@13870bb`)** this PR carries the atomic 5-c change itself, as #147 grew from its plan commit. §1–§6 are kept as written when the Owner and the Lead ruled on them; what their rulings change is stated in §7, not by editing §1–§6.

## 0. What 5-c is, in one paragraph

5-c is the first activation of DEC-024 (the change that lets a policy opt in). DEC-024 point 4 binds it to a record that supersedes DEC-021 entirely, in the same PR (RIC §5 point 3). The assignment adds, in the same PR: DEC-022 `ACTIVE` with its four conditions proven by reference to gates; DEC-019's exit with an explicit owner for the rest of its scope; the one builder line (RIC §1.1); the LOCKED amendments (RIC §9.3); the deferred contract corrections; the canonical documents from dormant to active; the CVH scenarios (RIC §8.1); and S0-PARITY first. **Several of these need Owner decisions that do not exist yet (§1).** Two of them were found while preparing this plan and were not in the assignment (OD-9, OD-10); they block DEC-022 `ACTIVE` as its text stands.

## 1. Owner decisions requested

Each decision lists its options, the measured cost of each (archive or 5-b value, with its source; `DERIVED` where nothing was measured), where it is recorded, and the executor's recommendation. None is decided by this plan.

### OD-3 — the one-time K5 restart for a device that starts sending an instance id (F-5b3a-LT9)

**Question.** Accept, as a declared cost of DEC-026, that a device which starts sending `clientInstanceId` restarts its K5 state once (reading A, as built in 5-b3a), and correct the second sentence of M4 (RIC L226) and ILD §6.2, which describe a transition window that has no input in any shape S1–S9 and no length in any decision (5-b3a PLAN §2, LT-9 ruling).

| Option | What it means | Measured cost | Evidence |
| --- | --- | --- | --- |
| **(a) accept the restart; correct M4 and ILD §6.2** | No transition window. The device's K5 score, micro-cap count and device-watcher events are not carried to the id-derived key; a device with no id is unaffected (A0). M4's second sentence and ILD §6.2 are corrected (not rewritten: a dated correction note, §4.6) | **Proxy (same mechanism: one K5 key change mid-ladder):** the `REF` arm of G5, where a browser-major change gives a known device a new K5 key: `UAC12` decisions `AAAA!SSSAAAA!SSS` against `AAAA!SSSSSA!HSSS` without the change (the second half is a new K5 and the ladder restarts: 8 `A` against 5); `UAX24` reach on U 82 against 77 in 24 h (+5), on P 45 against 45 (the fingerprint-independent allowance binds). **Upper bound for an attacker:** the id is a free identifier (DEC-022 Decision 3); the best response that mints a fresh id per attempt is already measured on the production code: `MINT` 51 admissions in 24 h (ceiling 52), `FORGE` 0 P admissions (5-b3b RESULTS, RP-G5). A one-time restart cannot exceed what a fresh id per attempt already gets | `G5_IDENTITY_LAYER.md` §3.1; `G5_V2_IDENTITY_LAYER.md` §3.2, §6; `5b3b/RESULTS.md` RP-G5 |
| (b) build reading B (old fingerprint material read as the "previous" K5 derivation during a window) | A new input (a window flag or a configuration value) and a new value (the window length, "at least the longest K5-family lifetime", stated by no decision; RIC §0.1: a new value is a new decision); a new gate (the K5 history carried, no Cartesian read, DFP §5.1.6); and a change of the Appendix A text the Owner accepted as written (OD-2: Consequences say "a one-time K5 restart (§6, M4)") | **Not measured.** Needs a registered measurement before any code (the lesson of 5-b2.1: absence-keyed mechanisms are measured first) | 5-b3a PLAN §2 |

**Recorded in:** DEC-026 Consequences (already says "a one-time K5 restart"; option (a) keeps Appendix A verbatim), RIC M4 and ILD §6.2 correction notes, DFP §5.1 (already describes a restart). **Recommendation: (a).**

### OD-4 — DEC-022 Decision 2, "These signals stay observable" (F-5b3a-LT10a)

**Question.** DEC-022 L125–L130 lists four signals that "stay observable". The opted path built in 5-b3a does not write two of them (the study skipped the whole churn, dilution and spray calls, and production reproduces the study for parity; 5-b3a D4).

| Signal (DEC-022 L125–L129) | Opted path today (`main@05922dd`) | Evidence |
| --- | --- | --- |
| K2 churn | **not written** (`checkCorrelationRules` not called with a lane) | 5-b3a D4; `FamilyGuardsTest` G2 |
| LOW dilution downgraded to K2 | **not written** (same call) | same |
| API Heavy LOW moderate K3 signal persisted on K2 | **not applicable**: `API_OVERUSE` cannot be combined with the admission capability (REG); API Heavy stays S0 | 5-b1 REG; DEC-025 8.4 |
| Missing-fingerprint deltas scored on K2 | **written as a score** (the K1/K2/K3 scores stay); no K1/K2/K3 candidate on P (5-b3a D5); on U every non-K5 threshold candidate is account-scoped and is dropped by the U rule (`EvaluationPipeline::checkThresholds`, `deviceScopedOnly`) | 5-b3a D4, D5; `FamilyGuardsTest` G3b |

| Option | What it means | Measured cost |
| --- | --- | --- |
| **(a) narrow Decision 2's list at activation** to what the opted path observes: the K2 score (including missing-fingerprint deltas) stays observable; churn and LOW dilution are **not observed** for opted policies; the API item does not apply to them | Text change of DEC-022 (`PROPOSED`, so amendable before activation), DM §12 and KS §3.2 wording | No protection is lost (none of the four enforces anything on the opted path). Lost: an operator signal of UA churn behind a source for opted policies. Every RP gate was measured exactly so (study parity) |
| (b) add an observe-only churn / dilution pass before activation | Pipeline writes the bounded churn and dilution observations for opted policies without forming any candidate | **Not measured.** Needs a registered pre-measurement: RP-G4 / RP-G5 / RP-G7 decisions identical (an observation changes no decision), and the store cost per opted `checkOnly` (today's S0 writes the same sets, so the cost is the S0 cost; not measured on the admission path). A separate PR before 5-c |

**Recorded in:** DEC-022 Decision 2 (activation text), DM §12, KS §3.2 and §6.1. **Recommendation: (a)**; (b) can follow as its own measured change if an operator signal is wanted.

### OD-5 — the first policy that opts in

**Question.** Which policy is the first to declare `ADMISSION_RESERVATION`, with which budget group and Host mode. Without it nothing is activated: the builder line only makes opting in possible.

| Option | What it means | Measured cost | Constraint |
| --- | --- | --- | --- |
| **(a) an official opt-in variant for Login only**, chosen explicitly by the Host (`withPolicy()`); `login_protection` itself unchanged | A package class = today's `LoginProtectionPolicy` values (DEC-021 values unchanged, DEC-025 9.1) + S1 + S2 (`PRE_AND_OUT`, the only case) + one budget group shared by login, re-authentication and password change | The assembly measured as G7 `PX4` (= `FULL2` + the D8 rule), reproduced on the production code (5-b3b RESULTS): `N12T` 0/61; TMS 29/61, TMSd 40/200 in `pre+out` (S0 7/61, 2/200: declared cost X2 of DEC-025 8); co-located office 21/61 (TPAIRT); owner `UAC` 9 (S0 6; declared, DEC-026 Delta 4); `pre` behaviour if the Host drops outcomes (NM-10 at share 1.00 = L1 72, D2 61, SP6 144); probes cost capacity (NM-5 on `FULL2`: `ρ` 2 L1 82, ABA_B 98, D2 85) | OTP was never measured under admission (every stream is Login); an OTP variant would need its own measurement |
| (b) no official variant: only custom policies opt in | The package ships the capability and the builder line; each Host composes S1 + S2 itself | Same assembly, but each composition is the Host's; REG catches the structural errors only | No package-owned measured preset |
| (c) `login_protection` itself opts in | Every existing Host of the preset would have to send permits (an outcome without a permit is a `RateLimiterException` for an opted policy, LT-16) | Breaks every `pre` and `out` Host of the preset | **Excluded by ACTIVE decisions:** DEC-024 C-c1 and R-1 (L92), DEC-023 R-3 (L107); would need a new decision and a major version |

**Sub-decision OD-5.1 (found while preparing this plan) — the variant's policy name.** Policy names are state namespaces (`POLICIES.md` §0; every K4/K5/budget/auxiliary key starts with `{policy}`). Two readings:

| | Same name `login_protection` (the variant replaces the preset by same-name registration) | New name (e.g. `login_protection_admission`) |
| --- | --- | --- |
| State of a Host that switches | K4 history continues as K4U, old K1/K2/K3 blocks are honoured on U until expiry (M3), rollback keeps K4U (M6). **This is what NM-7 / M6 measured** (`EngineLab::POLICY` = `login_protection`, 5-b3b RESULTS NM-7) | Every K4, K5, budget, allowance and watcher key starts empty: a one-time restart of the whole account and device state for every switching Host (an attacker under a K4 block at the switch is released). **Not measured** |
| Identity | One name, two official behaviours (selected by class) | One name per behaviour |

**Recorded in:** POLICIES.md (new variant section; §7.2 from dormant to active), CHANGELOG, Package Reference, DEC-026 Canonical Contract (the class name). **Recommendation: (a), Login only, budget group `login` (the value every 5-b gate used), Host mode `PRE_AND_OUT`, and OD-5.1 = same name**, because it is the only reading whose migration was measured; the Owner may prefer a new name and accept an unmeasured one-time restart, in which case a migration gate is registered first.

### OD-6 — the explicit record for the rest of DEC-019's scope

**Question.** DEC-019 (`ACTIVE`) governs K1 spray, K2 churn / dilution, LOW passive-UA behaviour, Login / OTP, `API_OVERUSE` and custom policies, and `checkOnly` limitations, for every policy. After 5-c the opted path replaces part of that for opted policies only; Decision Governance §9 has no partial supersession, and two `ACTIVE` records in overlapping scope with conflicting rules are an unresolved conflict (§6, L178). DEC-019 cannot stay `ACTIVE` unchanged (its Decision 1 "retain the shipped S0 behavior" would contradict the opted path) and cannot be edited in place (§9).

| Option | What it means | Cost |
| --- | --- | --- |
| **(a) a successor record** (next free number) `ACTIVE` that supersedes DEC-019 and restates, verbatim where it applies, DEC-019's posture **for every policy that did not opt in**: K1 credential spray, K2 churn and dilution, LOW passive-UA behaviour, API anti-evasion (`API_OVERUSE` is not eligible, DEC-025 8.4), the `checkOnly` limitations of `pre` and `out` Hosts (DEC-023), and custom policies under DEC-008; DEC-019 `SUPERSEDED`; #97, #100, #106 stay open for non-opted policies | One material decision per record (§4 default). Measured cost of keeping S0 there = today's harm, unchanged: D1 596/600, D2 117/121, ABA_B 118/120, trusted office `N12T` 55/61 in `pre` and `out` (DEC-019, DEC-023 Consequences) |
| (b) the same restatement inside DEC-022's activating text; DEC-019 superseded by DEC-022 | DEC-022's own text allows either form ("What this proposes to replace", DEC-019 row) | Two material decisions in one record (principle for opted policies + retained posture for the rest) |

**Recorded in:** the successor record or DEC-022; DEC-019 status and `Superseded By`; the index; `POLICIES.md` §5 and DM §5 interim notes (L559–L561, POLICIES L436). **Recommendation: (a).** Depends on OD-9.

### OD-7 — the operator procedure that opens the fleet fence (LT-13 deferred "the package-owned method" to 5-c)

**What exists (5-b3b, LT-13).** The fence is an integer per budget group under the reserved purpose `admission_fence`, read by `AdmissionRuntime::fenceOpen()` through `readBindingEpoch`; open when ≥ 1; written only through the store's `bumpBindingEpoch`; the package has no operator method (KS §4.6, L772–L777). Closed means the opted policy runs exactly the S0 path (`RateLimiterEngine.php:327`).

**Two properties found while preparing this plan (not in any document; raised as F-5c0-05, see LT-27):**
1. The fence key is `HMAC(env \0 admission_fence \0 group, keySecret)` with **no previous-generation key** (`AdmissionRuntime.php:76–83`). A key-secret rotation therefore **closes the fence silently**: every opted policy returns to the S0 path until an operator opens it again under the new secret, and outcomes of permits issued before the rotation are then accepted as unbound legacy outcomes (`AdmissionEngineFenceFailTest` L37: "no permit is required while the fence is closed").
2. The store operation is a bump (current = max + 1): **the fence cannot be closed by any package operation**; closing means deleting the Redis key or rotating the secret.

| Option | What it means | Measured cost |
| --- | --- | --- |
| (a) a package method to open (and close) the fence, on an additive interface reached by `instanceof` like S8 | A new public shape (DEC-008 BC; joins the shape list). Close needs a new store operation (S3 has none): a store change with its own NM-6-style gate | Open: none beyond NM-7. Close: not built, not measured |
| **(a′) a package method to open only** + an operator runbook for order, re-opening after a key rotation, and closing | Open is one `bumpBindingEpoch` on the derived key; no store change | NM-7 (5-b3b): behind a closed fence the mixed fleet decides as the all-old fleet; opened while mixed, admitted per window 8 / 7 / 7 (old share 0.25) and 8 / 5 / 7 (0.50), within `c_x` 24 + old-worker calls (30 / 60) |
| (b) runbook only | The operator derives the HMAC by hand from KS §4.6 and calls the store | Same NM-7 numbers; error-prone derivation (a wrong key leaves the fence closed, which is the S0 direction, never the open direction) |

Every option carries N-147-01: one store read per request of an opted policy, even with the fence closed; an outage of that read takes the typed path (D-FAIL). **Who owns the runbook** (package `docs/` vs the Host's operations) is part of the decision. **Recorded in:** USAGE_GUIDE (operator section), FAILURE_SEMANTICS §4.5, KS §4.6 (LOCKED amendment), RIC §6 M2. **Recommendation: (a′), runbook in the package `docs/`**, with LT-27 deciding whether the fence follows the previous key across a rotation.

### OD-8 — `D` = 30 s and `G` = 600 s as package constants

**Question.** The permit deadline `D` and grace `G` are study values (archive `g1/scenarios/m05_host_modes.php:55–56`, registered in 5-b3b PLAN §4 and accepted by the Lead), **not in DEC-025**. RIC §0.1: "a different value is a new decision"; the builder line needs them as package constants.

**Measured cost.** With settlement off (5-b2.2), an outcome claimed in `[t0 + D, t0 + D + G)` is `permit_late` and scores nothing (LT-16); after that it is `permit_unknown`. For a Host whose verifier answer arrives later than 30 s, that attempt is a dropped outcome. The cost of drops was measured at `D` 30 on the production code (NM-10, 5-b3b RESULTS): share 0.10 A1 and A3 hold; 0.25 A1 `L1 7`; 0.50 `L1 31`; 1.00 the `pre` values (L1 72, ABA_B 94, D2 61, SP6 144). State: one permit record per admission for `D + G` = 630 s. Only `D` 30 / `G` 600 were measured; `D ≤ W` is enforced by `AdmissionBudgetsDTO`.

| Option | What it means | Cost |
| --- | --- | --- |
| (a) inside DEC-026 | Adds a Delta item to the text the Owner accepted as written (OD-2); DEC-026's concern is the verifier bound, not permit timing | Reopens OD-2 |
| **(b) a small separate record** (next free number), one decision: "permit timing `D` 30 s, `G` 600 s, package constants; with them the DEC-025 values become the package constants of the builder line" | Decision Governance §4 default (one material decision per record) | None beyond the record |
| (c) amend DEC-025 | DEC-025 says a later change to any of its nine points supersedes the whole record (§9, its Rationale L82) | A full supersession of DEC-025 for two values outside its scope |

**Recommendation: (b).** The second clause matters: DEC-025 says its values "become production values only through the step-5 Runtime authorisation" (L86), and no record names that moment yet.

### OD-9 — the scope of DEC-022 at activation (found while preparing this plan; F-5c0-01)

**Conflict.** DEC-022 Decisions 1 and 2 are written for **every** policy: "Where the rule applies on activation: UA-similarity penalties (Decision 2) only" (L105), and its Scope covers Login, OTP, API Heavy and custom policies (L32). Three `ACTIVE` constraints keep every non-opted policy at S0: DEC-023 R-3 (L107: making FULL2's removal of collective penalties the behaviour of policies that did not opt in is rejected), DEC-024 C-c1, and S0-PARITY (the gate of every 5-b stage and the first gate of 5-c). Activating DEC-022 as written would make every official preset non-conforming on the day it becomes `ACTIVE` (K2 churn and LOW dilution still persist `HARD_BLOCK` on `login_protection`, `otp_protection`, `api_heavy_protection`).

| Option | What it means | Cost |
| --- | --- | --- |
| **(a) DEC-022 applies to policies that opt in under DEC-024**; the rest keeps DEC-019's posture under OD-6's record | DEC-022's Scope and the "Where the rule applies on activation" sentence amended before activation (it is `PROPOSED`) | Non-opted policies keep today's harm (OD-6 row (a)); #97 is fixed only for Hosts that opt in |
| (b) DEC-022 applies to every policy | Changes every official preset and custom policy | Excluded by DEC-023 R-3, DEC-024 C-c1, S0-PARITY; unmeasured for `pre` / `out` Hosts (DEC-023: SP6 144 at `U_src` 24, no net cap, no refund) |

**Recommendation: (a).**

### OD-10 — DEC-022 Decision 4 against the measured best-response cells (found while preparing this plan; F-5c0-02)

**Conflict.** DEC-022 Decision 4 (L148): "Best-response cells stay gates, and any regression in them stays a gate failure; BRD is an example." Condition 4 requires the runtime to be measured on those gates. The production code reproduces the archive (RP-G4 streams field for field, 5-b3b RESULTS) and the archive is above S0 on several of them:

| Cell (`pre+out`) | S0 | Production (= `FULL2` / `PX4`) | Declared where |
| --- | --- | --- | --- |
| `X1` / `BRU` / `BRD` reach | 2 / 3 / 3 | **4 / 5 / 5** | Decision paper D2.2 (`C` plus the refill at `T` 30, `DERIVED` for `C`; no other `C` measured); listed as an effect in DEC-023 Consequences (L136); **not in DEC-025's declared-cost list** (L88) |
| MIX attack reach | 0 | **8** | DEC-023 Consequences; not in DEC-025's list |
| SP1n / SP3 / SP6 | 4 / 7 / 20 | 4 / **8** / **24** | DEC-025 L88 ("S0's collective spray denial is retired at `U_src` 24 / `F` 4", SP6 named); gate A3 ≤ S12 |
| ABA_A | 3 | **8** | covered by the same sentence only by reading (not named) |
| TMS / TMSd (paid identifiers) | 7/61, 2/200 | **29/61, 40/200** | DEC-025 decision 8, declared cost X2 (explicit) |

| Option | What it means | Cost |
| --- | --- | --- |
| **(a) DEC-022's activating text states that Decision 4's gates are judged against the bounds the Level-2 decision chose**, and lists every cell above with its value as a declared cost, citing DEC-025 2.2 and 1.4 and DEC-023 Consequences | The Owner accepts, explicitly and by cell, the regressions not yet named in DEC-025 (`X1` / `BRU` / `BRD`, MIX attack, ABA_A, SP3) | The numbers above (first-window reach + 2 per cell; per day the bucket bound 52 holds, `BRU24` 51) |
| (b) treat them as gate failures | DEC-022 cannot become `ACTIVE`; DEC-019 cannot exit for the opted path; 5-c stops at DEC-026 + the builder line only if the Owner also accepts an opted path without DEC-022 (which contradicts DEC-019 Decision 1) | A new budget measurement first (decision paper N-2: other `C`; `T` 60 still gives 4 / 4 / 4) |

**Recommendation: (a)**, with the per-cell list; this plan does not judge the security trade-off.

## 2. DEC-022 Condition 4 — the map to evidence

Condition 4 (DEC-022 L238): "measured on the DEC-019 Decision 5 list and on the best-response and paid-identifier gates of Decision 4, with independent Lead review on the exact HEAD". Each row cites the gate that evidenced it on the production code and its stage record. "Not covered" rows are raised in §3 and are **not** declared proven.

### 2.1 DEC-019 Decision 5 (L93)

| Item | Evidence on the production code | Status |
| --- | --- | --- |
| `checkOnly`-only and record-only guarantees | Mode eligibility is structural: S2 has the single case `PRE_AND_OUT`; `pre` and `out` are not representable (REG, 5-b1 `AdmissionRegistrationRulesTest`). Record-only on an opted policy: an outcome without a permit is a `RateLimiterException` (LT-16; LANE/REG 2a, 5-b3b). A declared `pre+out` Host that drops outcomes runs as `pre` (NM-10 reported at share 1.00 = the archived `pre` rows; gate P reported L1 72, D2 61). Non-opted policies: S0 (S0-PARITY in every stage) | **Covered** for what DEC-023 promises; the A-2 residual is declared (NM-10 NO-GO as a store mechanism, Owner decision of 2026-10-20) |
| K1 + K2 overlap | Both collective paths are off for opted policies (GUARDS G-a, G3b, 5-b3a). The replacement bounds are measured on the streams where #108 measured the overlap: D2 24 (S0 117, both-off arm 27), SP1n / SP3 / SP6 4 / 8 / 24 (RP-G4 A3, 5-b3b) | **Covered** |
| IPv6 macro scopes | The source is IPv4 exact or IPv6 `/64` (`SourcePrefix`, LT-18). No gate replays a `/48` holder rotating `/64` sources; SF §5 row 6: "the hierarchy is `UNPROVEN`". The IPv4 multi-source analogues are measured (`BRD` one account, new IP per attempt: 5 per first window, `BRU24` 51 per day; `X2` 121/121 = P4 open, DEC-025) | **Not covered → LT-26** |
| Current / Previous rotation | Store: counters sum current + previous, write current only; previous device set never written (ROT, NM-6 T1-N5, 5-b2). Pipeline: K5 continues across a secret rotation; reader parity across a rotation (5-b3a). Codec: two binding keys by key id (5-b1). **Not shown:** the size of the capacity re-issued by a second rotation inside one window (RIC §8.3 ROT: "the gate shows its size"); the restart of the set-size and bucket scopes at a rotation (KS §4.8); the fence at a rotation (OD-7 point 1) | **Partly covered → LT-27** |
| Trusted / nontrusted collateral | RP-G7 X1: `N12T` 0/61, no legitimate row refused more under the D8 cap; TPAIRT office 21/61 (declared, DEC-025 8); RP-G6 (5-b3b) | **Covered** |
| Block renewal (#100) | A1 `ABA_B` 0 (S0 118), RP-G4 field for field; recovery fixture `REC` reproduced; a capacity refusal leaves no state after its window (SC-2) and is never a block, cycle or escalation (SC-3, CYCLES) | **Covered** |
| cap / N−1 | S0's N−1 WATCH paths (spray, churn, dilution) are not executed for opted policies (GUARDS G-b). The replacement caps are exact under concurrency (NM-6 R-1 3/3/3, R-2 1/1/1; NM-7 at 16 and 64 processes, 5-b2) and at the best response (`BRU24` 51 ≤ 52 with an independent recount, NM-10 (c)) | **Covered**; the comparison with S0 at the cap is OD-10 |

### 2.2 DEC-022 Decision 4 (L144–L159)

| Class | Cells | Evidence | Status |
| --- | --- | --- | --- |
| Constant-UA cells → observations | naive `X2`, `X7` constant-UA | reproduced in the RP-G4 / RP-G5 streams (5-b3b); recorded, not gated | Covered as observations |
| Best-response cells stay gates | `BRD`, `BRU`, `X1` | 4 / 5 / 5 against S0 2 / 3 / 3 (RP-G4) | **Regression → OD-10** |
| Single-IP spray and co-located attacker | SP1n, SP3, SP6, MIX | A3 4 / 8 / 24 ≤ S12; A2 MIX legitimate 54 ≤ S0 117; MIX attack 8 (S0 0) | Gate A3 / A2 pass; attack side → OD-10 |
| Paid identifiers | TMS family; `X1/API`, `Y7/API` | TMS 29/61, TMSd 40/200 (RP-G7, declared cost X2); API: `API_OVERUSE` is not eligible (REG), so the API cells are S0 by S0-PARITY | Covered (TMS declared; API unchanged) |
| Independent Lead review on the exact HEAD | — | 5-b1 #144, 5-b2 #145, 5-b3a #146, 5-b2.1 #148, 5-b2.2 #149, 5-b3b #147: PASS at their heads; the 5-c review is on the atomic PR's HEAD | Pending (the 5-c review) |

### 2.3 Conditions 1–3

Condition 1: DEC-023 and DEC-024 (`ACTIVE`). Condition 2: UA-free K5 with DEC-021 parity, G5 v2 `SUPPORTED — LIMITED SCOPE`, reproduced on the production code (RP-G5 pipeline part 5-b3a; RP-G5 fixtures and NP-IN 932 / 932 / 0, 5-b3b). Condition 3: DEC-025 (`ACTIVE`). These are cited in DEC-022's activating text by record and gate, not restated.

## 3. Lead technical questions raised before the build (LT-26 …)

| ID | Question | Recommendation |
| --- | --- | --- |
| **LT-26** (IPv6 macro, §2.1) | Is the DEC-019 IPv6 item proven by the IPv4 multi-source equivalence (`DERIVED`), or does 5-c add a registered cell? | Add one registered gate in the 5-c PR (existing `EngineLab`, no new value): `BRD` and `X2` replayed with each attempt from a different `/64` inside one `/48`; PASS = the IPv4 values (5 per first window, 51 per day for one account; 121/121 for `X2` = P4, declared open); detector: a source function that keys on `/48` changes the values. If not added, DEC-022's text names the hierarchy as `UNPROVEN` (SF row 6), not as proven |
| **LT-27** (rotation, §2.1; F-5c0-05) | (1) Show the size of a second rotation inside one window (RIC §8.3 ROT). (2) Measure the set-size / bucket restart at a key rotation (KS §4.8 says the interval rule "prices" it; no number). (3) The fence at a key rotation: read the previous key as `binding_epoch` does (reverses part of the LT-13 ruling "no previous-generation key"), or keep it and document "a rotation closes the fence" | (1) and (2): one registered test each in 5-c, reported values, detector = a store that writes the previous key (T1-N5). (3): the Lead's ruling; the executor's preference is to read the previous key (a rotation then never silently returns opted policies to S0), with a test |
| **LT-28** (builder reruns) | Which 5-b3b gates can be "re-run through the builder"? The builder will hold the DEC-025 values including the D8 rule (`F` 4), but the `FULL2` stream gates (RP-G4, RP-G5 streams, NM-10, NM-5) run with D8 disabled (`StreamReplay::fullBudgets()` sets `crossAccountFailureCap` 1,000,000) because `FULL2` has no D8 rule; the detectors load mutated runtime classes (`ServiceMutant`), which a builder cannot inject | Through the builder: the G7 `PX4` arm (210 rows field for field), the fixtures on `PX4`, SC-1…6, CYCLES / LIFECYCLE, LANE, FAIL, fence, the permit cases, NM-7 engine and the S0 goldens. Stay on the direct engine (as reference and detector evidence, unchanged): the `FULL2`-without-D8 arms, every ablation and every mutant detector. A rerun is not a re-registration: no condition changes |
| **LT-29** (index) | Beyond the three status changes, the index rows of DEC-023 (`None until the capability ships in step 5 (then POLICIES.md, DEC-008)`), DEC-024 (`None until step 5 … under DEC-019 and DEC-021`) and DEC-025 (`None until step 5`; "Study values only, becoming production values in step 5") become stale at 5-c. Update the index columns only (the records' own texts are historical), or also add a dated note to those records? | Index columns only, each change listed in RESULTS (the #139 / #142 lesson: re-check every description whose record's status or owner changed) |
| **LT-30** (existing test) | `AdmissionRegistrationRulesTest::testBuilderRejectsAFullyEquippedAdmissionPolicyBecauseItNeverBuildsTheRuntime` (5-b1, L116) asserts the dormancy that 5-c removes; it fails by design | Replace its assertion by the new DORMANT statement (the builder builds the runtime, with the package constants) as a declared, ruled test change (precedent LT-22, 5-b1 `RateLimiterConfigTest`); the engine-level dormancy test (L100, an engine with no runtime rejects S1) stays unchanged and still passes |
| **LT-31** (builder line) | Construct `AdmissionRuntime` always when the store and a binding key allow it, or only when at least one registered policy declares S1? | Only when a policy declares S1: a composition without one is then byte-identical to today (no runtime object, no fence read), which is the strongest form of S0-PARITY; the binding key stays mandatory only in that case (LT-6) |
| **LT-32** (constants holder) | Where the DEC-025 values and `D` / `G` live in `src/` | One `@internal` package-owned factory (e.g. `AdmissionBudgetsDTO::packageDefaults()`) as the only place in `src/` with those numbers; the builder calls it; `RateLimiterConfig` and the builder get no setter (no Host path). The grep gate of every 5-b stage becomes "the numbers appear in that factory only" |

## 4. The atomic PR (after the Owner decisions and the Lead rulings)

### 4.1 Content

1. **Decision records** (Decision Governance §3, §5, §6, §9):
   - `docs/decisions/DEC-026_<title>.md`: the text of RIC Appendix A with the DEC-021 sections it names reproduced **verbatim** from DEC-021 (Context; Decisions 1–6 with the pre-check clause of Decision 3 narrowed by Delta 1; Rationale; Consequences with M1–M5; Alternatives Not Adopted) and the four Delta items; `Status ACTIVE`, the activation date, `Supersedes DEC-021`. A mechanical check is registered: every carried DEC-021 section is byte-identical to `main@05922dd` except the clause Delta 1 names.
   - DEC-021: `Status SUPERSEDED` and `Superseded By DEC-026` only (precedent DEC-009 → DEC-013); no other line.
   - DEC-022 `ACTIVE`: the four conditions proven by reference (§2, by record and gate name), the amendments of OD-4, OD-9, OD-10, and the Level-1 `KS` §2 source-capacity amendment named as its canonical contract.
   - DEC-019 per OD-6 (`SUPERSEDED`, `Superseded By` the successor) and the successor record.
   - The `D` / `G` record per OD-8.
   - `DECISIONS_INDEX.md`: new lines and the status, supersession and description changes of DEC-019, DEC-021, DEC-022, plus LT-29.
2. **The builder line** (RIC §1.1, LT-31, LT-32): `RateLimiterBuilder::build()` constructs `AdmissionRuntime` with the package constants; `bindingKeySecret` mandatory when a policy declares S1 (LT-6, already enforced at `RateLimiterBuilder.php:322`); nothing else in the builder changes.
3. **The first opting policy** per OD-5 / OD-5.1, and the fence method per OD-7.
4. **Code references to DEC-021** (DEC-024 point 4): `EvaluationPipeline.php` L74, L426, L2377 → DEC-026.
5. **LOCKED amendments** (RIC §9.3): `KS` §4.6 (`binding_epoch`, `admission_fence`, the `_p` purposes from reserved to active) and §3.4 / §3.5 (K4P, K5p, the K5 material) under DEC-026; `KS` §2 (the source-capacity class) and DM §10 under DEC-022.
6. **Contract corrections named in 5-b** (RIC, dated correction notes in place, as done for OD-1 and LT-15): RP-G4 `UAC` 6 → 9 (the G5 v2 value, F-5b3b-A1-UAC; L259); M3 "honoured read-only on U; not read on P" (F-5b3a-LT10b; L225); L111 (the distributed-account snapshot member is the K5 key, F-5b3a-LT10c); M4 second sentence and ILD §6.2 per OD-3 (F-5b3a-LT9); the shape list gains `AdmissionLaneEnum` (LT-11), `AdmissionBudgetsDTO` (LT-12), `AdmissionLaneDTO`, `AdmissionBindingEpochInterface` (LT-21), `SourcePrefix` (LT-18), `successKeepsSettled` (LT-S2), `settlesOnExpiry` (5-b2.2) and the six permit causes (`permit_missing` as an exception; `permit_duplicate`, `permit_conflict`, `permit_rejected`, `permit_late`, `permit_unknown` as `ALLOW` with scope `permit`, LT-16).
7. **Canonical documents from dormant to active:** `POLICIES.md` (§7.2 and the variant), `USAGE_GUIDE.md` (operator section of OD-7, N-147-01), `DECISION_MATRIX.md` (§12, the DEC-021 → DEC-026 headings of §2.2.2 / §2.5.3 / §3.1.2, the DEC-019 notes of §5), `FAILURE_SEMANTICS.md` §4.5, `DEVICE_FINGERPRINT.md` §5.1, `KEY_STRATEGY.md`, `CHANGELOG.md`, `RATE_LIMITER_PACKAGE_REFERENCE.md`, `llms.txt`, `docs/RELEASE_VERIFICATION.md` (the new CVH scenarios in Stage C). A registered grep lists every remaining "dormant" / "the builder never constructs" sentence; each is either changed or listed as historical.
8. **CVH scenarios** (RIC §8.1, N-147-04), through the public composition (`RateLimiterBuilder`) on real Redis with `FixedClock`: CVH-1 the variant with the fence closed = the S0 goldens; CVH-2 fence opened by the OD-7 procedure, a reservation refused with `source_capacity`; CVH-3 Host-minted credential (public codec) selects P, a tampered one gives U; CVH-4 password change: `revokeAccountBinding()`, `bindingEpoch()` read, re-issue, old credential on U; CVH-5 the permit cases (`permit_missing` exception, `permit_duplicate`); CVH-6 a Host that drops every outcome is held only by the `pre` bounds (the A-2 residual, shown not hidden). Exact expected values are registered in the plan amendment of commit 1, read from the 5-b3b fixtures, not typed.
9. **N-147 notes:** N-147-01 documented (item 7); N-147-03 recorded as it stands in DM §12 ("a refused pre-check keeps the observations written before the admission stage", LT-17); N-147-04 = item 8; N-147-02 in a separate operations PR **before** the atomic PR (the Lead's note: PHP 8.5 leg for the archive suite and timeouts, since 5-c adds suite time), cited in RESULTS.

### 4.2 Gates (S0-PARITY first)

| Order | Gate | PASS | Detector |
| --- | --- | --- | --- |
| 1 | **S0-PARITY** | (i) the existing suite passes with no edit except the tests ruled under LT-30; (ii) the 5-b3a goldens (`FamilyS0ParityTest`) and the 5-b3b engine goldens (Login `19bfcf29…679c`, OTP `568dbec2…cacab`) unchanged; (iii) **new builder goldens recorded on the unmodified `main@05922dd` before any `src/` change** (commit 2): `login_protection`, `otp_protection`, `api_heavy_protection` and a custom non-opted policy built by `RateLimiterBuilder::build()`, with and without `bindingKeySecret`, the Host sending `clientInstanceId` and `admissionCredential`; decision strings and the sha-256 of the normalised store state equal at the base and at HEAD | a builder that treats every policy as opted (the engine's opt-in check mutated) breaks (iii) |
| 2 | **DORMANT** (new meaning) | the runtime is constructed only by the builder and only with the package constants: its `AdmissionBudgetsDTO` equals the DEC-025 table and the `D` / `G` of OD-8 (read by reflection); no public path sets a budget (`RateLimiterConfig`, builder API listed); without an opted policy no runtime is constructed (LT-31) | a builder that reads one budget from configuration fails |
| 3 | **REG** through the builder | the 5-b1 registration rules hold for the variant and for custom policies; binding key mandatory with S1 | the existing 5-b1 detectors |
| 4 | **Reruns through the builder** (LT-28) | the listed 5-b3b gates pass unchanged on the builder-composed runtime | the 5-b3b detectors stay on the direct engine and still fire |
| 5 | **CVH** (item 8) | each scenario its registered values | each scenario names its detector in the plan amendment |
| 6 | LT-26 / LT-27 gates if ruled so | as ruled | as ruled |
| 7 | **Records** | the DEC-021 verbatim check of item 1; every index reference resolves; no `ACTIVE` pair with overlapping scope and conflicting rule (Governance §6) | — |
| 8 | **Static** | PHPStan max normal and `--prefer-lowest --prefer-stable`; full suite in both modes with full logs (`--display-errors`, `COMPOSER_PROCESS_TIMEOUT=0`); `composer format:check`; the constants grep of LT-32; CI green | — |

### 4.3 Commit order

1. Plan amendment: the Owner decisions and Lead rulings quoted with links; the CVH values and the golden recipe registered.
2. Builder goldens recorded on the unmodified base (data + recorder; no `src/` change).
3. Decision records and index (item 1).
4. Red tests: DORMANT, REG through the builder, the reruns, CVH, the variant, LT-26 / LT-27 tests as ruled, and the ruled change of LT-30 (each line disclosed).
5. Implementation: constants factory, builder line, variant, fence method, DEC-021 → DEC-026 code comments.
6. LOCKED and canonical documents (items 5, 7).
7. Contract and ILD corrections and the shape list (item 6).
8. `5c/RESULTS.md` and full logs at the exact tested tree.

Any test changed after commit 4 is disclosed in an "Amendments after the tests" section with its reason; no registered condition is changed after it has run.

## 5. Outside 5-c, by name

- **Default presets opting in** (`login_protection`, `otp_protection` declaring S1): a later Owner decision (DEC-024 R-1; AP §6.5 end state). The free mode stays on today's defaults.
- **An OTP opt-in variant:** never measured under admission.
- **API protection** (DEC-025 8.4): unchanged; `API_OVERUSE` is not eligible.
- **DEC-025 open items:** option 5.4 (the UK lane, gate N-4), P4 (global ceiling, gate N-6), other `C` / `T` (N-2).
- **Option B, a `pre` contract** (DEC-023, `DEFERRED`).
- **Settlement of expired permits** stays opt-in and off (`settlesOnExpiry`, 5-b2.2); NM-10 is NO-GO as a store mechanism; the A-2 residual stays a Host duty.
- **A credential issuer** in the library, credential lifetimes and the taint rule (RIC §0.2: Host-side); the binding-key rotation interval value (§0.2).
- **K4P rules B and C** (DEC-025 4.A).
- **Removing the unsuffixed U allowance write** (RIC §2.2 L112: an optimisation needing its own decision).
- **Reading B of the K5 "previous" material** and **the observe-only churn / dilution pass**, if OD-3 (a) and OD-4 (a).
- **Failover bound `L`** (reported only, NM-7) and any clustered Redis topology.
- **Closing #97, #100, #106:** they stay open for policies that do not opt in (OD-6).
- **Release, tag and version bump:** the Owner's separate decision after merge.

## 6. Limits

This plan reads the code and the records at `main@05922dd`; every number is quoted from a merged archive or a merged 5-b RESULTS file, and the four new findings (F-5c0-01 scope, F-5c0-02 best-response, F-5c0-05 fence at rotation and no close, and the D8-disabled stream gates of LT-28) come from reading the records and the code, not from a new run. Nothing is decided here; the atomic PR starts only after the Owner decisions OD-3 … OD-10 and the Lead rulings LT-26 … LT-32 are posted and recorded in commit 1.

## 7. Owner decisions and Lead rulings, recorded (commit 1 of the atomic stage; base `main@13870bb`)

Recorded before any test or line of the implementation. Nothing in this section was run against anything.

### 7.1 Sources

| What | Where |
| --- | --- |
| Lead rulings LT-26 … LT-32 and verification of F-5c0-01 / 02 / 05 | [#150 comment 6298046892](https://github.com/Maatify/php-rate-limiter/pull/150#issuecomment-6298046892) |
| Owner: OD-10 deferred to the study G8 | [#150 comment 6298114042](https://github.com/Maatify/php-rate-limiter/pull/150#issuecomment-6298114042) |
| G8 (bucket capacity `C` ∈ {2, 3, 4} at `T` 1800 on the production code), merged as evidence | #151, `main@8f8ecc3`; notes N-151-01 / N-151-02 in its Lead review |
| Owner decisions OD-3 … OD-10 and the authorisation of the operations PR, then of this PR | [#150 comment 6299051848](https://github.com/Maatify/php-rate-limiter/pull/150#issuecomment-6299051848) |
| Operations PR for N-147-02 (archive suite on PHP 8.4 and 8.5, Lowest 20 min with the archive split) | #152, `main@13870bb` |
| Base of the atomic PR and the timeout item | [#150 comment 6300269333](https://github.com/Maatify/php-rate-limiter/pull/150#issuecomment-6300269333) |

### 7.2 Owner decisions (2026-10-20, comment 6299051848)

| OD | Decision as posted | What it fixes in this PR |
| --- | --- | --- |
| OD-3 | (a) | The one-time K5 restart is a declared cost of DEC-026 (Appendix A text unchanged); M4 sentence 2 and ILD §6.2 get a dated correction note; reading B is not built |
| OD-4 | (a) | DEC-022 Decision 2's "stay observable" list narrowed to what the opted path observes (the K2 score, including missing-fingerprint deltas); churn and LOW dilution not observed for opted policies; the API item does not apply |
| OD-5 | (a), OD-5.1 same name | An official Login-only variant chosen explicitly by the Host; budget group `login`; mode `PRE_AND_OUT`; policy name `login_protection` (the migration and rollback measured by NM-7 / M6) |
| OD-6 | (a) | A successor record supersedes DEC-019 and restates its posture verbatim for policies that do not opt in; DEC-019 `SUPERSEDED`; #97, #100, #106 stay open for them |
| OD-7 | (a′) | A package method that **opens** the fence only, on an additive interface reached by `instanceof`; a runbook in `docs/` (order, re-opening after a secret rotation, closing by deleting the key); the fence reads the current key, else the previous one (LT-27 (3)) |
| OD-8 | (b) | A small separate record: `D` 30 s and `G` 600 s are package constants, and with them the DEC-025 values become the package constants of the builder line |
| OD-9 | (a) | DEC-022's scope at activation = policies that opt in under DEC-024; the others stay under the OD-6 record |
| OD-10 | (a) with `C` = 4 (G8) | DEC-022's activation text names each cell by number **and by its governing mechanism**: `X1` / `BRU` / `BRD` 4 / 5 / 5 (the bucket: `C` / `C`+1 / `C`+1; `C` = 2 returns them to S0 at the price of an owner lockout on K5U, 26 refusals, `pre` D2 85 and KD 10, G8); MIX attack 8, ABA_A 8, SP3 8 (`F` / `U_src`, unchanged by any `C`; A3 holds at S12); TMS 29/61, TMSd 40/200 (D8, declared cost X2) |

### 7.3 Lead rulings (comment 6298046892)

| LT | Ruling | Consequence for this PR |
| --- | --- | --- |
| LT-26 | As recommended | One registered gate (§7.6.3); DEC-022's text names the IPv6 hierarchy `UNPROVEN` (SF §5 row 6); the gate proves `/64` equivalence only |
| LT-27 | (1), (2) one registered test each, reported values, detector T1-N5; **(3) the fence reads the current key, else the previous one**, as `binding_epoch`, with a test and the runbook line "re-open under the new secret before the previous one is withdrawn"; no close operation in 5-c | §7.6.4; a change of `AdmissionRuntime::fenceOpen()` (amends part of LT-13 on measured grounds) |
| LT-28 | As recommended | Through the builder: the G7 `PX4` arm (210 rows), the fixtures on `PX4`, SC-1…6, CYCLES / LIFECYCLE, LANE, FAIL, the fence, the permit cases, NM-7 engine, the S0 goldens. On the direct engine, unchanged: the `FULL2` arms without D8, every ablation, every mutant detector. A rerun is not a re-registration |
| LT-29 | As recommended | Index columns only for DEC-023 / 024 / 025, each change listed in RESULTS; the records' texts stay historical |
| LT-30 | As recommended, **and the test method is renamed** to match the new statement | A declared, ruled change of `AdmissionRegistrationRulesTest::testBuilderRejectsAFullyEquippedAdmissionPolicyBecauseItNeverBuildsTheRuntime` (5-b1, L116); the engine test without a runtime (L100) is unchanged |
| LT-31 | As recommended | The runtime is constructed only when a registered policy declares S1; any other composition is today's, byte for byte, with no runtime object and no fence read |
| LT-32 | As recommended | One `@internal` factory `AdmissionBudgetsDTO::packageDefaults()`, the only place in `src/` holding the numbers, with a docblock citing DEC-025 item by item and the OD-8 record; no setter in `RateLimiterConfig` or the builder; grep gate "the numbers appear in the factory only" |

### 7.4 Operational items and notes carried into this PR

* **N-147-02** is closed by #152 (not part of this diff).
* **Timeouts** (comment 6300269333): `Test Suite` (7:57 of 10 min) and `Integration Test Suite` (9:33 of 15) are re-checked in `5c/RESULTS.md` after measuring `composer test:core` and the integration suite at the atomic HEAD; any change is proposed there, not made in this PR.
* **N-151-01:** DEC-022's activation text states that G8 ran on the reference store, not on Redis (accepted for a budget study: the store logic is the one NM-6 proved identical).
* **N-151-02** (F-G8-06): `USAGE_GUIDE.md` gains a Host recommendation: re-issue the credential before its absolute expiry (sliding renewal) even at `C` = 4, because an owner whose credential expires in the middle of a typing-error burst falls to the U door.

### 7.5 What these rulings change in §1–§4 (named here; §1–§4 are not edited)

| Item | Now |
| --- | --- |
| Record numbers (proposed; the Owner may renumber) | **DEC-026** replaces DEC-021 (RIC Appendix A); **DEC-027** succeeds DEC-019 (OD-6), proposed title "Retained Correlation Posture for Policies That Do Not Opt Into Admission"; **DEC-028** permit timing and package constants (OD-8), proposed title "Admission Permit Timing and the Package Constants of the Admission Runtime". DEC-022 becomes `ACTIVE` under its own number |
| The variant (OD-5) | `Config\LoginProtectionAdmissionPolicy`, extending `LoginProtectionPolicy` (not final, `LoginProtectionPolicy.php:25`): same name, values, thresholds, deltas, fallback and DEC-007 lifecycle; adds `ADMISSION_RESERVATION` to the inherited capabilities and implements `AdmissionReservationPolicyInterface` (`PRE_AND_OUT`, `login`). Class name proposed; the Lead may rename |
| The fence method (OD-7) | `Service\AdmissionFenceControlInterface::openAdmissionFence(string $budgetGroup): int` (returns the fence value after the bump), implemented by `RateLimiterEngine` and `CompositeRateLimiterRuntime` exactly as S8; a `RateLimiterException` when no admission runtime is configured. The bump writes the current key and reads the previous one (`bumpBindingEpoch(current, previous)`). Name proposed |
| Runbook (OD-7) | A section of `docs/guides/USAGE_GUIDE.md` ("Operating the admission fence"): roll the whole fleet to this version, then open per budget group; one store read per opted request even while closed (N-147-01); after a key-secret rotation re-open under the new secret before the previous secret is withdrawn; closing = deleting the fence key in Redis (no package operation); a closed fence = the opted policy runs the S0 path and outcomes need no permit |
| §4.2 row 5 (CVH detectors) | The CVH installs the package as a consumer and cannot load mutants (`scripts/ci/check-consumer-verification-boundary.sh`); each scenario carries its own **control** instead (§7.6.5). The mutant detectors stay in PHPUnit (§4.2 row 4) |
| §2 map, IPv6 row | Becomes "`/64` equivalence proven by LT-26's gate; the `/48` hierarchy `UNPROVEN`" once the gate passes |
| §2 map, rotation row | Becomes covered once LT-27 (1)–(3) pass, with the re-issued sizes reported |

### 7.6 Registrations (before any test is written)

#### 7.6.1 S0-PARITY — builder goldens (gate 1; recorded in commit 2 on the unmodified base)

* **Composition.** `RateLimiterBuilder::fromFullCapabilityStore($config, new FullCapabilityInMemoryStore($clock), new RecordingFailureSignalEmitter())->withClock($clock)->withPolicy($policy)->build()`, with `$config` = `new RateLimiterConfig('lab-secret', 'lab-fp-secret', 'prod')` and the same with `bindingKeySecret` set. Clock `FixedClock('2026-01-01 12:00:00')`.
* **Policies.** `LoginProtectionPolicy`, `OtpProtectionPolicy`, `ApiHeavyProtectionPolicy`, and the custom non-opted authentication policy of the 5-b1 tests (`DeclaresCapabilityWithoutInterfacePolicy('custom_auth')`, capability `TRUSTED_AUTHENTICATION`).
* **Scenario A** = the 160-step scenario of `FamilyS0ParityTest` / `AdmissionEngineS0GoldensTest` (same contexts, commands and clock, the Host sending `clientInstanceId` and a credential minted by `AdmissionCredentialCodec`), played through the composite runtime.
* **Scenario B (collective rules)** = 60 steps from one IPv4 source `203.0.113.7`: account `acct-(step % 8)`, a user agent whose browser major is `100 + step` (a new K2 per step), no instance id, commands `checkOnly` then `recordFailure` alternating, clock +20 s per step. It exercises the K1 spray and K2 churn / dilution paths that scenario A does not reach; the reason is the one of the assignment: a builder line that routed a non-opted policy into the opted path would turn those collective rules off.
* **Golden** per policy and scenario: the decision string (`A` / `S` / `H`) and the sha-256 of `serialize($store)` with every 32-hex random id replaced by `ID` (the masking of the 5-b3a goldens). **Both configurations (with and without `bindingKeySecret`) must give the same golden.**
* **Where.** `tests/Unit/Admission/AdmissionBuilderS0GoldensTest.php`, the goldens a constant of the class, group `s0`. Commit 2 adds this test and nothing else; it passes on `main@13870bb` (`git diff 13870bb -- src` empty at commit 2) and must pass unchanged at HEAD.
* **Detector (commit 4).** A builder whose engine treats every policy as opted (the engine's opt-in check mutated to return the budget group for every policy, fence open) changes at least one golden.

#### 7.6.2 DORMANT, new meaning (gate 2)

The runtime constructed by `build()` for a composition with the variant carries an `AdmissionBudgetsDTO` equal, field by field, to: `windowSeconds` 600, `sourceCapacity` 24, `sourceFailureCap` 4, `accountBucketTakes` 4, `accountBucketSeconds` 1800, `proofAccountWindow` 10, `proofAccountDay` 60, `proofProofWindow` 6, `proofProofDay` 30, `newDevicesPerDay` 10, `crossAccountFailureCap` 4 (DEC-025 1.4, 2.2, 3.1, 8 / RIC §0.1), `permitDeadlineSeconds` 30, `permitGraceSeconds` 600 (DEC-028). The test types these values with their citations; it is the only place outside the factory that does. Read by reflection through the engine. Also: a composition without an opted policy has no runtime object (LT-31); the public API of `RateLimiterConfig` and `RateLimiterBuilder` has no budget input (reflection over public methods and constructor parameters). Detector: a builder that takes one budget from configuration fails the field comparison.

#### 7.6.3 LT-26 — IPv6 `/64` equivalence

The archived G4 v2 `BRD` and `X2` tuples (all variants in `streams.jsonl`, `pre+out`), replayed on the `FULL2` lab as RP-G4 does, twice: with the dumped IPv4 sources, and with each distinct IPv4 source mapped injectively to its own `/64` inside `2001:db8:aaaa::/48` (`2001:db8:aaaa:{n}::1`, `n` the index of first appearance). **PASS:** the two replays are equal row by row on `dec`, `reach`, `kinds`, `lanes` and `m`. **Detector:** an `AdmissionRuntime` mutant whose source is the `/48` (`SourcePrefix::of($context->ip)` → `SourcePrefix::of($context->ip, 48)`, exact-line mutation by `ServiceMutant`) makes at least one row differ.

#### 7.6.4 LT-27 — rotation

On the `PX4` lab (reference store), one source and one budget group:
1. **Second rotation in one window.** Spend `U_src` (24 `checkOnly` of distinct unknown accounts, each followed by a success outcome) at `t` 0…; rotate the key secret once (current = s2, previous = s1) at `t` 100 and count admissions until the first refusal; rotate again (current = s3, previous = s2) at `t` 200 and count again. **Registered:** after the first rotation 0 further admissions (counters sum current + previous, C-c3); after the second the number re-issued is **reported** (expected by the contract: 24, the capacity re-issue RIC §8.3 asks to show).
2. **Set-size and bucket scopes at one rotation.** Fill the U net failure set (4 failed accounts) and one account bucket (4 takes); rotate once; **report** how many further distinct failing accounts are admitted before `source_failure_cap` and how many takes before `account_bucket` (KS §4.8: they restart).
3. **Fence across a rotation.** Open under s1; rotate to (s2, s1): the fence reads open; rotate to (s3, s2) without re-opening: closed; open under s3: open. **Detector:** the 5-b3b `fenceOpen()` (current key only) reads closed right after the first rotation.

Detector of (1) and (2): T1-N5 (a store that writes the previous key) changes the count after the first rotation.

#### 7.6.5 CVH scenarios (gate 5; `consumer-verification/verify.php`, real Redis, `FixedClock`, the variant through `RateLimiterBuilder::fromFullCapabilityStore`)

Values are DEC-025 values, not archive rows typed by hand. Each scenario uses its own source address and accounts.

| # | Scenario | PASS | Control |
| --- | --- | --- | --- |
| CVH-1 | Variant, fence never opened, scenario A without credentials | the decision string equals the same scenario on `LoginProtectionPolicy` in a second environment scope; no key containing `:adm:` exists afterwards | the same comparison with the fence open differs |
| CVH-2 | Open the fence with `openAdmissionFence('login')`; 24 `checkOnly` from one IPv4 for 24 distinct unknown accounts, each followed by `recordSuccess` with its permit | 24 `ALLOW`, each with a permit; the 25th `checkOnly` is `SOFT_BLOCK`, `blockLevel` 1, cause `source_capacity`, scope `source`, `0 < retryAfter ≤ 600` | the 25th from a second source is `ALLOW` |
| CVH-3 | After CVH-2, a credential minted with `AdmissionCredentialCodec` (subject, group `login`, instance, `exp` in the future, epoch 0) from the exhausted source | `ALLOW` (P is not bounded by `U_src`) | the same credential with one byte of the MAC changed: `source_capacity` |
| CVH-4 | `revokeAccountBinding()` on the composite runtime, then `bindingEpoch()` | epoch 1; the epoch-0 credential is refused `source_capacity` from the exhausted source; a credential re-issued with epoch 1 is `ALLOW` | the epoch-0 credential before the revocation is `ALLOW` |
| CVH-5 | Permits | `recordFailure` without a permit throws `RateLimiterException`; a permit claimed twice: the second result is `ALLOW`, `blockLevel` 0, cause `permit_duplicate`, scope `permit` | the first claim is accepted (no `permit_*` cause) |
| CVH-6 | The A-2 residual, shown | a Host that reports failures: after 4 distinct failed accounts from one source, the 5th account's `checkOnly` is `source_failure_cap`; a Host that drops every outcome: 24 distinct accounts admitted and the 25th `source_capacity` (the `pre` bound) | — (the two arms are each other's control) |

#### 7.6.6 Records gate (gate 7)

Every DEC-021 section DEC-026 carries is byte-identical to `docs/decisions/DEC-021_KNOWN_DEVICE_VERIFIER_BOUND.md` at `main@13870bb`, except the Decision 3 clause narrowed by Delta 1 (a script compares them and prints the differing lines). The same check applies to the DEC-019 sections DEC-027 restates verbatim. The diff of DEC-021 and DEC-019 themselves is limited to `Status` and `Superseded By` (precedent DEC-009 → DEC-013).

### 7.7 Commit 4 — the red tests: what they are, what changed in existing test code, and two questions raised

**Tests added** (each fails, errors or is not yet runnable before commit 5, except where stated):

| File | Gates |
| --- | --- |
| `tests/Unit/Admission/AdmissionBuilderActivationTest.php` | DORMANT (§7.6.2: package constants by field, no runtime without an opted policy, no public budget input, detector = a builder mutant whose line `AdmissionBudgetsDTO::packageDefaults()` is replaced, the anchor registered to occur exactly once in the builder); REG through the builder (store capability, binding key); the variant (OD-5, OD-5.1); the fence method (OD-7); a closed fence = the `login_protection` golden decisions of scenario A (control: open fence); SC-1, LANE, the permit and the rollback through the builder at the package constants |
| `tests/System/AdmissionBuilderRerunTest.php` (group `archive`) | LT-28 reruns: the 210 G7 `PX4` rows and the `PX4` fixtures on builder labs, judged by the existing tests' own comparison code invoked by reflection, unchanged |
| `tests/Unit/Admission/AdmissionRotationTest.php` | LT-27 (1), (2), (3) and their detectors |
| `tests/System/AdmissionIpv6SourceEquivalenceTest.php` (group `archive`) | LT-26. **Passes on the current code**: it tests the existing `SourcePrefix`; its detector fires |
| `consumer-verification/verify.php` | CVH-1 … CVH-6 (§7.6.5), added before the summary; the summary gains an `admission` entry |

**Existing test code changed (declared):**

1. `AdmissionRegistrationRulesTest::testBuilderRejectsAFullyEquippedAdmissionPolicyBecauseItNeverBuildsTheRuntime` → `testBuilderBuildsTheRuntimeForAFullyEquippedAdmissionPolicy` (LT-30, ruled): same composition, the assertion is now that the builder constructed the runtime. The engine test without a runtime (L100) is unchanged.
2. `tests/Support/Admission/EngineLab.php`: one **additive** static factory `throughBuilder()` (and its imports). The constructor and every existing method are unchanged; every existing test builds its lab exactly as before. Precedent for support-file changes: LT-24.

**Amendment of a registration before its gate ran on the implementation (LT-26, §7.6.3).** The dumped `X2` tuples carry study labels (`atk1` …) as their sources, not IPv4 addresses (the `BRD` tuples carry addresses). The mapping is therefore over every distinct dumped source, address or label, each to its own `/64` inside `2001:db8:aaaa::/48`. The PASS condition and the detector are unchanged.

**LT-33 (raised; rerun scope, LT-28).** LT-28 lists CYCLES / LIFECYCLE, FAIL and NM-7 engine among the gates re-run through the builder. They cannot run through it unchanged:
- CYCLES / LIFECYCLE need the cycle-recording store as the pipeline's score store, separate from the admission store.
- FAIL needs the fault-injecting admission store on the engine's path.
- NM-7's mixed fleet uses an old-worker engine mutant.
- The other unit gates (SC-2 … SC-6, the LANE and permit cases) shrink capacities to 1 or 2.

None of that is possible through a builder with package constants and no budget input. The B1 recount and NP-IN read the direct lab's admission log.

**Built through the builder:**
- the G7 `PX4` rows;
- the `PX4` fixtures (BRU24 without its recount);
- SC-1, LANE, the permit, the fence, the rollback;
- the closed-fence S0 check and the builder goldens.

**Direct only, unchanged:** the rest. The executor asks the Lead to accept this split.

**LT-34 (raised; LT-27 detectors).** The ruling names T1-N5 as the detector of LT-27 (1) and (2). The T1-N5 detector exists only as the study's original Redis reserve script (`StudyOriginalReserve`), and it writes the previous **device set**, not the counters and sets these tests measure. Used instead:
- **(1):** a build without the previous generation, which re-issues the full 24 at the first rotation.
- **(2):** reported with no detector. Set-size and bucket scopes have no previous generation by contract (KS §4.8), so there is no previous write to detect.

The executor asks the Lead to accept both.

**Red state before commit 5:**
- `LoginProtectionAdmissionPolicy`, `AdmissionFenceControlInterface` and `AdmissionBudgetsDTO::packageDefaults()` do not exist, and the builder constructs no runtime.
- PHPStan and the CI jobs that run these tests (and CVH) are red by design until commit 5, as in 5-b3b 2a.

### 7.8 Commit 5 — the implementation, and the amendments after the tests (each with its reason; no gate or detector weakened)

**Implementation:**
- `AdmissionBudgetsDTO::packageDefaults()` (LT-32, DEC-028).
- `Config\LoginProtectionAdmissionPolicy`: OD-5 / OD-5.1 (§7.5).
- `Service\AdmissionFenceControlInterface::openAdmissionFence()`, implemented by `RateLimiterEngine` and `CompositeRateLimiterRuntime`, which delegate to `AdmissionRuntime::openFence()`.
- The fence read reads the current key, else the previous one, and the open writes the current key (LT-27 (3)).
- The builder line `RateLimiterBuilder::admissionRuntime()`: a runtime only when a registered policy declares the capability (LT-31), with `AdmissionBudgetsDTO::packageDefaults()`, the anchor that occurs exactly once.
- `EvaluationPipeline`: the three DEC-021 references now name DEC-026 (DEC-024 point 4).
- The docblocks that said the admission surfaces were dormant now describe what ships: `AdmissionBudgetsDTO`, `AdmissionReservationStoreInterface`, `AdmissionPermitStoreInterface`, `AdmissionReservationPolicyInterface`, `PolicyCapabilityEnum::ADMISSION_RESERVATION`, `AdmissionBindingControlInterface`, `AdmissionBindingEpochInterface`, `AdmissionRuntime`, the builder's capability assertion.

**Amendments after the tests (commit 4):**
1. `EngineLab::throughBuilder()`: the check `! $runtime instanceof AdmissionFenceControlInterface` was removed. PHPStan max proves it always true once the composite implements the interface, and its unused import went with it. No assertion is involved.
2. CVH (`consumer-verification/verify.php`): the admission compositions use the builder's default system clock instead of a frozen `FixedClock`, and CVH-1 no longer advances any clock.
   - **Reason, measured locally:** the bounded correlation snapshots of `RedisFullCapabilityStore` take their expiry from Redis `TIME` (DEC-016 allows this). `BoundedCorrelationResultValidator::snapshot()` compares it with the caller's clock with no tolerance.
   - Once real time moved past the frozen clock, every `checkOnly` that reaches a bounded snapshot threw "invalid snapshot". The plain `login_protection` preset threw as well, so this is not the admission path.
   - The scattered `X`s that made CVH-1 differ came from this.
   - The CVH-1 failure message now carries both decision strings.
   - The PASS conditions and the controls are unchanged.
3. `AdmissionBuilderActivationTest::fields()` reads the thirteen fields by name instead of through a dynamic property, because PHPStan 2.0.0 (lowest dependencies) types the dynamic read as `mixed`. The count check against the registered fields stays, and no assertion changed.

**N-5c-01 (observation for the Lead; existing behaviour on `main`, not introduced here).** The validator rejects a Redis-time snapshot whose expiry exceeds caller-now + TTL. A production Host whose application clock is behind the Redis clock by one second or more, or a request that crosses a second boundary between the caller's `now()` and the script's `TIME`, could get a `RateLimiterException` from any policy that observes a bounded correlation, S0 included. It is outside 5-c. A separate issue is recommended, with a measurement of the boundary case before any change (`DEC-016`, `BoundedCorrelationResultValidator`).

**Local results at commit 5 (normal dependencies, PHPStan 2.3.1):**
- PHPStan max: no errors. `composer format:check`: clean.
- `tests/Unit/Admission`: 262 tests, all pass.
- The builder reruns and LT-26: 4 tests, 235 assertions, all pass.
- LT-27 reported values:
  - `REPORT lt-27-rotation-capacity {"afterFirstRotation":0,"afterSecondRotation":24}`.
  - `REPORT lt-27-set-and-bucket-restart {"failingAccountsAfterRotation":4,"bucketTakesAfterRotation":4}`.
- CVH: two clean runs, PASS.
- The complete suite, normal dependencies, with Redis: `OK (1932 tests, 25263 assertions)` in 18:50 (the 1,892 of 5-b3b plus the 40 tests of commits 2 and 4).
- Lowest dependencies (PHPStan 2.0.0, PHPUnit 11.5.50): PHPStan max has no errors after amendment 3; `tests/Unit/Admission` (262), the builder reruns and LT-26 pass.
- The complete lowest suite, with full logs at the exact tested tree, belongs to `5c/RESULTS.md`.
