---
name: maatify-standards-context-lifecycle
description: Establish, maintain, and reconcile the authoritative Standards and Decision governance context across engineering tasks, resumes, and remediations.
---

# Maatify Standards Context Lifecycle

## Purpose

Establish, maintain, and reconcile the authoritative Standards and Decision governance context during engineering tasks. This skill explains how an Executor executes the existing normative contract, not a new one.

## Canonical Contract Reference

- **Standard Identity:** `std-ai-collaboration-workflow` (§7.1 Standards Context Lifecycle & Governance Context Reconciliation)
- **Adoption/Governance:** `STANDARDS_ADOPTION_STANDARD_AR.md` (including §16 Resolution Status), `DEC-031` (§3)

## Operational Procedure

### Phase 1 — Repository Truth

1. **Determine `$REPO_ROOT`**: Locate the enclosing directory that contains `.git`. Do not assume a branch, commit, or working directory.
2. **Verify repository state**: Confirm `.git` exists and the working tree state is known. If `$REPO_ROOT` cannot be determined, **STOP**. Do not guess.
3. **Do not infer facts**: Treat any missing repository fact as unknown. Never substitute `main` or `HEAD` for a pinned reference.

### Phase 2 — Governance Context Helper

1. **Run deterministic helper** (repository-local, no network access):
   ```bash
   php "$REPO_ROOT/.agents/helpers/maatify-governance-context.php" --root="$REPO_ROOT"
   ```
2. **Read `mechanical_status` as the mechanical validation gate**: Read `mechanical_status` from the helper output as the mechanical validation gate (mechanical evidence). If the mechanical validation gate indicates invalid/failed per the helper’s contract, **STOP**. Do not attempt to repair the helper result by inference. Do not reinterpret helper output as a semantic judgment.
3. **Consume other mechanically knowable facts when required**: When the lifecycle procedure requires it, other helper fields (e.g. `adoption_commit`, `profile_activations`, `resolved_applicable_standards`, `overall_resolution_status`, `indexed_active_decisions`, `decision_index`, `operational_assets`, `governance_mode`, or other mechanically knowable facts exposed by the helper) may be consumed as mechanically knowable evidence/facts. Do not assume such fields replace the canonical Resolution Status or make semantic decisions (applicability, compliance, materiality, ownership, quality, policy).
4. **Do not override Adoption Commit**: Do not substitute current `HEAD` for the pinned Adoption Commit. Do not use `main` as fallback. Do not use any other source snapshot as fallback.
5. **Fail-closed on missing exact Adoption Commit**: If the exact Adoption Commit is not available, follow the fail-closed contract per `STANDARDS_ADOPTION_STANDARD_AR.md`. Handle per the Resolution Status contract in §16 (and related provisions). Do not add network/fetch/fallback.
6. **No network augmentation**: Do not add `git fetch`, `gh`, or any network access to satisfy validation.

### Phase 3 — Identify Authoritative Local Standards

1. **Consumer repository**: Start from the locally pinned adoption state. Identify Adoption Standard, active Profiles, inherited Profiles required, Final Resolved Applicable Standards Set, and `STANDARDS_MANIFEST.md` as present in that state. Do not treat the mere existence of any file in `standards/` as proof of applicability.
2. **Canonical standards repository** (`Maatify/php-engineering-standards`): The canonical Standards are maintained locally in this repository under `standards/` and `docs/decisions/`. Treat this as an independent canonical-local state (separate from upstream adoption resolution). No network resolution.
3. **Do not read entire tree by default**: Only load files required to resolve current task context.

### Phase 4 — Resolution Order (mandatory, non-reversible)

```text
Repository Truth
        ↓
Mechanical Governance Validation (helper)
        ↓
Stage 1: Structural / Transitive Resolution
        ↓
Stage 2: Canonical Standard Applicability
        ↓
Final Resolved Applicable Standards Set
        ↓
Active Decisions
        ↓
Task Execution Context
```

Do not reverse this order.

### Phase 5 — Stage 1: Structural / Transitive Resolution

1. **Active Profiles**: Read active Profiles from the authoritative state.
2. **Resolve `Extends`**: Resolve inheritance/transitive relations.
3. **Resolve inherited Profiles**: Identify all inherited Profiles required.
4. **Check references**: Verify required references exist; detect broken references.
5. **Check cycles**: Detect cycles in the profile/extension graph.
6. **Candidate Standard References**: Build Candidate Standard References. Profile membership is a candidate signal only, not sufficient to establish applicability.
7. **Structural issues**: If structural validity cannot be satisfied from authoritative evidence, handle per canonical contract (no guessing).

### Phase 6 — Stage 2: Canonical Standard Applicability

For each Candidate Standard, evaluate applicability by applying only the canonical contract (no independent taxonomy invented here):

1. **Open canonical Standard**: Read the canonical Standard file.
2. **Read Applicability**: Read `Applicability`.
3. **Read Conditional Applicability (if present)**: Read conditional clauses.
4. **Evaluate against facts**: Evaluate against task scope and artifact facts that are actually available in the repository/state. Do not infer unobserved facts.
5. **Apply canonical definitions only**: Use only `Applicability` and `Conditional Applicability` as defined in that canonical Standard. Do not transfer applicability between Standards. Do not invent applicability rules.
6. **Determine applicability outcome per canonical process**: Deterministic exclusion is an applicability result (not an Exception or Invalid).
7. **If indeterminate**: If applicability cannot be determined from canonical evidence, **refer to and apply the Resolution Status contract defined in `STANDARDS_ADOPTION_STANDARD_AR.md §16`**. Do not invent a separate status taxonomy (e.g. `APPLICABLE | NOT APPLICABLE | UNRESOLVED | OWNER DECISION REQUIRED`) for Stage 2.

### Phase 7 — Final Resolved Applicable Standards Set

Only Standards whose applicability is established per the canonical process (Stage 2) enter the **Final Resolved Applicable Standards Set**. Do not add reference/support standards "just in case", standards deemed "useful" by the Executor, standards present only by repository existence, or standards not justified by canonical applicability.

### Phase 8 — Active Decisions

1. **Read index**: `docs/decisions/DECISIONS_INDEX.md` (authoritative).
2. **Assess relevance**: For each active Decision, determine whether it applies to current task/context. Do not assume all apply.
3. **Read Decision**: Open relevant Decision file(s).
4. **Apply only relevant**: Apply only Decisions demonstrably relevant to current task/context.
5. **Do not guess**: If applicability/state unresolved from evidence → follow canonical contract (no guessing).

### Phase 9 — Governance Context Reconciliation

#### 9.a Initial / Adoption Resolution (establishment only)

This path is used **only** when creating, establishing, or completing the authoritative Adoption state, or when the canonical contract explicitly requires adoption verification. It follows this sequence strictly:

```text
Repository Truth (Phase 1)
        ↓
Mechanical Governance Validation (Phase 2)
        ↓
Stage 1: Structural / Transitive Resolution (Phase 5)
        ↓
Stage 2: Canonical Standard Applicability (Phase 6)
        ↓
Final Resolved Applicable Standards Set (Phase 7)
        ↓
Active Decisions (Phase 8)
```

Constraints for this path:
- Resolve adoption state strictly from the authoritative local adoption/pinning sources as required by `std-ai-collaboration-workflow §7.1` and `DEC-031 §3`.
- Do **not** introduce network/fetch/fallback during this resolution.
- Treat the canonical Standards repository state (`standards/`, `docs/decisions/`) as independent from upstream adoption resolution.

#### 9.b Normal Governance Context Reconciliation (engineering tasks)

For normal engineering work (including task start, resume after context loss, material scope change, material concern, relevant active Decision appears, Executor/agent change, remediation, before final acceptance), perform **Normal Governance Context Reconciliation**.

When a **Completed Valid local Selective Pinned Adoption** exists, reconciliation must:
- **Consume** the existing locally pinned Adoption state (do not re-resolve Adoption from upstream).
- **Not** re-run Adoption Resolution from upstream sources.
- **Not** rely on floating upstream Standards.
- **Not** change the Adoption Commit.
- **Not** rewrite `STANDARDS_MANIFEST.md`.
- **Not** require upstream network/fetch/fallback.
- **Not** wholesale re-run Stage 1–Stage 2 across the entire set solely due to routine reconciliation; targeted re-evaluation of only the affected scope is permitted if material change occurs (per canonical applicability/resolution contract), while preserving local adoption boundaries. No automatic upstream adoption refresh.

Required steps for Normal Governance Context Reconciliation:
1. **Re-establish Repository Truth** (Phase 1) and **re-run Mechanical Governance Validation** (Phase 2) against current state.
2. **Preserve the locally pinned adoption state** as-is.
3. **Check for material changes** in affected scope, artifacts, Standards/Decisions, or pinned state.
4. **If material change exists in affected scope/artifact facts**: perform **targeted re-evaluation** of only the affected parts (not a full Adoption Resolution) consistent with canonical applicability/resolution contract; maintain local adoption boundaries and do not convert this into automatic upstream adoption refresh.
5. **If no material change**: maintain current context without re-running Stage 1–Stage 2 in full.
6. **Boundaries**: Remain within reconciliation boundaries defined by `std-ai-collaboration-workflow §7.1`. No network/fetch/fallback.

The general phase sequence (Phase 4) describes the overall order but does **not** imply that full Stage 1 and full Stage 2 are executed on every Normal Reconciliation. The distinction between (A) Initial/Adoption Resolution and (B) Normal Governance Context Reconciliation must remain explicit and non-ambiguous.

### Phase 10 — No-Guessing / Fail-Closed Rules

Executor MUST NOT:
- infer missing repository facts
- invent applicability
- invent Decision applicability
- substitute current `HEAD` for pinned Adoption Commit
- substitute `main` as fallback
- use floating references or network/fetch to satisfy validation
- treat missing evidence as success
- silently repair malformed adoption state
- treat `REQUESTED` exception as approved
- waive Structural Invalidity
- create new governance rules inside this Skill
- assume presence implies applicability
- invent an independent taxonomy to replace the canonical Resolution Status contract (`STANDARDS_ADOPTION_STANDARD_AR.md §16`)

If authoritative evidence is insufficient to proceed:
```text
STOP
→ classify according to canonical contract
→ do not guess
```

## Final Rule

If any contradiction arises between this Skill and a canonical Standard/Decision, **the canonical Standard/Decision wins**. Do not resolve contradictions by modifying rules inside this Skill. Resolution of indeterminacy follows `STANDARDS_ADOPTION_STANDARD_AR.md §16` (not an invented status set).
