---
name: maatify-strict-release-acceptance
description: Operational execution of the mandatory strict Lead acceptance workflow for SemVer RC and Stable release boundaries.
---

# Maatify Strict Release Acceptance

## Purpose

Enable any Lead/Executor to execute Mandatory Pre-Release Strict Lead Acceptance without inventing review criteria. This skill operationalizes the existing canonical contract only.

## Canonical Contract Reference

- **Standard Identity:** `std-ai-collaboration-workflow` (Mandatory Pre-Release Strict Lead Acceptance)
- **Durable Decision:** `DEC-023` (`MANDATORY_PRE_RELEASE_STRICT_LEAD_ACCEPTANCE_DECISION.md`)
- **Supporting References:** `AI_COLLABORATION_WORKFLOW_AR.md`, `STANDARDS_ADOPTION_STANDARD_AR.md`

## Operational Procedure

### Phase 1 — Candidate Identity

1. **Establish repository root**: Determine `$REPO_ROOT` containing `.git`. Do not assume branch/commit.
2. **Determine working tree state**: Record clean/unclean state (do not proceed under ambiguous state per canonical contract).
3. **Identify exact candidate commit SHA**: Determine the **exact candidate commit SHA** under review (not "latest", not "current version", not "the PR", not "the branch"). This SHA is the identity for all evidence.
4. **Capture PR/branch context if applicable**: If review is tied to a PR/branch, record it explicitly alongside the exact candidate SHA.
5. **Bind evidence to candidate**: Every review evidence item must be traceable to this exact candidate SHA.

### Phase 2 — Governance Context

1. **Execute context lifecycle**: Run `maatify-standards-context-lifecycle` against the repository state.
2. **Enforce mechanical validation gate**: The deterministic helper's `mechanical_status` is a **mechanical validation gate / evidence only**. If the helper result is not mechanically valid according to its own contract, **STOP**. Do not infer/repair it.
3. **Respect canonical Resolution Status**: The canonical governance Resolution Status remains exclusively owned by `STANDARDS_ADOPTION_STANDARD_AR.md §16` (values: `VALID`, `INVALID`, `OWNER DECISION REQUIRED` as defined there). The Skill must not redefine these as review verdicts nor conflate them with `mechanical_status`.
4. **Satisfy both**: Acceptance of Governance Context requires satisfying the relevant parts of (a) mechanical helper validation, (b) the canonical Resolution Status contract (§16), and (c) lifecycle rules. Each retains its independent ownership. Do not unify them into a single "VALID" that collapses ownership/semantics.
5. **Do not guess governance**: Apply fail-closed rules from the context lifecycle.

### Phase 3 — Canonical Contract

1. **Read fresh from repository**: Read `std-ai-collaboration-workflow` (Mandatory Pre-Release Strict Lead Acceptance) fresh from current repository state. Do not rely on memory or prior prompt.
2. **Read applicable active Decisions**: Read any active Decisions that impose acceptance evidence (including `DEC-023`).
3. **Read other canonical constraints**: Read `AI_COLLABORATION_WORKFLOW_AR.md` and `STANDARDS_ADOPTION_STANDARD_AR.md` to the extent they govern acceptance handling for this boundary.
4. **Derive contract set**: Build the authoritative contract set from repository evidence only.

### Phase 4 — Build Review Matrix (Coverage Matrix)

Derive the review matrix **only** from the canonical contract and repository evidence. The Coverage Matrix must cover **all** of the following dimensions (no additional coverage categories):
1. **Each Standard** in the **Final Resolved Applicable Standards Set**.
2. **Each applicable ACTIVE Decision**.
3. **Each material applicable instruction** from **AGENTS.md**.
4. **Each material artifact capability/contract**.

Do not invent additional criteria. If any Standard, applicable ACTIVE Decision, material applicable instruction from AGENTS.md, or material artifact capability/contract lacks review coverage in this matrix, that constitutes a **blocking** condition.

Derive the review matrix **only** from:
```text
Canonical Contract
+
Applicable Active Decisions
+
Final Resolved Applicable Standards Set
+
Material applicable AGENTS.md instructions
+
Material artifact capabilities/contracts
+
Repository Evidence Requirements
```

For each requirement record:
- **Requirement**: exact requirement statement (as stated in canonical source)
- **Source**: canonical file/path + section (or Decision id/path)
- **Expected Evidence**: what must exist to satisfy it (file, exact commit, diff, deterministic test/result, deterministic helper output, canonical Standard/Decision reference, documented decision)
- **Actual Evidence**: concrete evidence found in repository/state, including paths/SHAs/refs that tie to **exact candidate SHA**
- **Coverage**: reference to which of the four required dimensions this item satisfies (Standard(s), applicable ACTIVE Decision(s), AGENTS.md material instruction(s), or material artifact capability/contract)
- **Status**: classification per Phase 6

### Phase 5 — Evidence Rules

1. **Evidence must be verifiable**: Each requirement must have evidence that is inspectable in repository/state.
2. **Acceptable evidence**: repository file, exact commit, diff (against base/candidate), test result (deterministic), deterministic helper output, applicable canonical Standard/Decision, documented decision that is attributable to exact candidate SHA.
3. **Unacceptable evidence**: `"I checked it"`, `"I believe it"`, `"looks correct"`, `"probably okay"`, or any unsubstantiated claim. Treat missing evidence as missing (not success).
4. **Tie to candidate**: Evidence must correspond to the exact candidate SHA (not a different ref).
5. **No inference**: Do not infer presence from proximity or naming.

### Phase 6 — Status Classification

#### Separation of Mechanical Status and Canonical Resolution Status
- The deterministic governance helper provides **mechanical evidence/gating only** (`mechanical_status`). This is not the canonical governance Resolution Status.
- The **canonical governance Resolution Status** remains owned by `STANDARDS_ADOPTION_STANDARD_AR.md §16` (including `VALID`, `INVALID`, `OWNER DECISION REQUIRED` as defined there). Do not create a Skill-local taxonomy to replace or duplicate it.
- No independent Stage 2 taxonomy (e.g. `APPLICABLE | NOT APPLICABLE | UNRESOLVED | OWNER DECISION REQUIRED`) is introduced here. Stage 2 applicability outcomes follow the canonical process.
- Do **not** redefine `VALID`, `INVALID`, or `OWNER DECISION REQUIRED` as review verdicts. These are not review verdicts; they are handled per the canonical Adoption/Resolution contract.

#### Review Verdicts (only)
Use **only** the verdict statuses permitted for review findings:
```text
PASS | FAIL | UNRESOLVED | N/A
```

Constraints:
- `UNRESOLVED` is a blocking status. Do not replace `UNRESOLVED` with `INVALID` or `OWNER DECISION REQUIRED`.
- Do **not** introduce an independent status set beyond the above for review verdicts.
- **Never** convert missing evidence to `PASS`.
- **Never** treat `REQUESTED` as approved.
- Structural Invalidity and Owner Decision Required are handled strictly per the canonical contract as defined in `AI_COLLABORATION_WORKFLOW_AR.md` and `STANDARDS_ADOPTION_STANDARD_AR.md` (not by reclassifying them into review verdicts).
- If evidence is ambiguous relative to the exact candidate SHA and cannot be resolved from inspectable evidence → classify as `UNRESOLVED` (blocking). Do not guess.

### Phase 7 — Review Order (mandatory, Standards-First inside every Gate)

Execute in this order:

```text
Candidate Identity (Phase 1)
        ↓
Governance Context (Phase 2)
        ↓
Canonical Contract (Phase 3)
        ↓
Standards-First Review Sequence (applied inside each Gate)
        ↓
Structural Integrity
        ↓
Required Standards / Decisions
        ↓
Implementation Evidence
        ↓
Scope / Behavioral Review
        ↓
Release-specific Requirements
        ↓
Final Reconciliation (Phase 8)
        ↓
Verdict (Phase 9)
```

**Standards-First inside every Gate (mandatory, non-negotiable):** For each Gate, the review must proceed in the following order before evaluating implementation/evidence specific to that Gate:

```text
Applicable AGENTS.md
→ Resolved Applicable Standards
→ Gate Scope + Canonical Owning Standard(s)
→ DECISIONS_INDEX
→ Applicable ACTIVE Decisions
→ Current Repository Evidence
→ Direct Lead Review
→ Verdict
```

This order must be enforced **inside every Gate**. Do not substitute this order with only the general review order above. Implementation/evidence review must not begin before the canonical Standards and applicable ACTIVE Decisions for that Gate have been identified and confirmed. The determination of canonical Standards/Decisions applicable to the Gate is a prerequisite to reviewing evidence against them.

If any prerequisite fails or prevents reliable review: **STOP**. Do not continue with default/assumed answers.

### Phase 8 — Final Reconciliation

Before issuing verdict:

1. **Re-check candidate SHA**: Confirm exact candidate SHA is unchanged.
2. **Re-check repository state**: Confirm repository state has not materially changed since evidence collection (or re-collect affected evidence).
3. **Re-check applicable set**: Confirm Applicable Standards/Decisions unchanged for this candidate.
4. **Verify evidence ownership**: Verify every evidence item in matrix is attributable to the same exact candidate SHA.
5. **If candidate changed**: Previous verdict does not apply automatically. Restart acceptance from Phase 1 for the new candidate.
6. **If governance context changed materially**: Perform required reconciliation per context lifecycle before continuing.
7. **Cross-validate**: Ensure no evidence was inferred or substituted.

### Phase 9 — Verdict

1. **Distinguish roles**: `Lead Acceptance` means the acceptance conditions were examined and evaluated against the **exact candidate SHA** per canonical contract. It does **not** mean `Owner Merge Authorization`.
2. **No merge authorization implied**: Merge authorization requires separate explicit Owner authorization (if applicable).
3. **State verdict against exact SHA**: Record verdict clearly tied to exact candidate SHA and the evidence matrix. Review findings use only `PASS | FAIL | UNRESOLVED | N/A`.
4. **Fail-closed**: If any required evidence is missing or cannot be tied to exact candidate SHA → reflect non-acceptance per canonical contract. If unresolved from canonical evidence → `UNRESOLVED` (blocking), with no substitution to other statuses.

## Critical Rules

- **No redefinition**: This Skill must not redefine Adoption Standard, AI Collaboration Workflow, Versioning Policy, Decisions, or Owner Authority.
- **Canonical wins**: If contradiction between this Skill and any canonical Standard/Decision → **canonical Standard/Decision wins**. Do not resolve by creating/modifying rules inside this Skill.
- **No guessing**: If authoritative evidence insufficient → `STOP → classify according to canonical contract → do not guess`.
- **Exact identity required**: Identity is the **exact candidate commit SHA**. Never substitute "latest"/"current version"/"the PR"/"the branch".
- **Contract-constrained classification**: For review findings, status classification is limited strictly to `PASS | FAIL | UNRESOLVED | N/A`. Structural Invalidity and Owner Decision Required are not reclassified into that set; they are handled per `AI_COLLABORATION_WORKFLOW_AR.md` and `STANDARDS_ADOPTION_STANDARD_AR.md`.

## Final Rule

Lead acceptance is evidence-driven and candidate-bound. Every conclusion must be backed by inspectable evidence tied to the exact candidate SHA.
