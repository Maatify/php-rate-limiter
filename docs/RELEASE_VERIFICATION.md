# Release and Published Artifact Verification Guide

This document describes the repository-owned, maintained mechanisms for **Release Artifact Verification (RAV)** and **Published Artifact Verification (PAV)** for `maatify/php-rate-limiter`, satisfying:
- `CI_WORKFLOW_STANDARD.md` §2.5 (Release Artifact Verification)
- `CI_WORKFLOW_STANDARD.md` §2.6 (Published Artifact Verification)
- `CI_WORKFLOW_STANDARD.md` §2.7 (Immutable Published Version)
- `COMPOSER_PACKAGE_STANDARD.md` §26 & §26.1 (Verification Rigor and Delivery Mode Requirements)
- `LIBRARY_PRESENTATION_STANDARD.md` §8.1.2, §14 & §23 (Distribution Safety, License Identity, and Readme Parity)

---

## 1. Architectural Distinction & Lifecycle Stages

To preserve release integrity across the release lifecycle, three separate verification mechanisms exist:

```text
[ Commit Candidate Qualified ]
              │
              ▼
   Stage A: Pre-Publication
  ┌─────────────────────────────────────────────────────────────┐
  │ Release Artifact Verification (RAV)                         │
  │ • Target-agnostic pre-publication qualification             │
  │ • Evaluates Git candidate commit object & tree              │
  │ • Requires checked-out HEAD == candidate SHA                │
  │ • Requires clean Git worktree and clean index               │
  │ • Inspects candidate Git archive export preservation        │
  │ • Computes deterministic content manifest hashes            │
  │ • Enforces canonical Semantic Review Record & claims        │
  │ • Enforces undated exact target heading in CHANGELOG        │
  │ • Validates proprietary license identity                    │
  │ • Emits machine-readable qualification evidence JSON        │
  └─────────────────────────────────────────────────────────────┘
              │
      ( Tag & Publish )
              │
              ▼
   Stage B: Post-Publication
  ┌─────────────────────────────────────────────────────────────┐
  │ Published Artifact Verification (PAV)                       │
  │ • Target-agnostic post-publication qualification            │
  │ • Consumes authoritative RAV qualification evidence         │
  │ • Executes fresh, isolated external Composer resolution     │
  │ • Proves exact version resolution                           │
  │ • Proves actual installation mode (dist vs source)          │
  │ • Prohibits source fallback when dist archive was exposed   │
  │ • Enforces qualification-time Decision for source-only      │
  │ • Verifies observed reference equals qualified SHA          │
  │ • Verifies installed files match content manifest hashes    │
  │ • Captures isolated Composer audit evidence (redacting auth)│
  └─────────────────────────────────────────────────────────────┘
              │
              ▼
   Stage C: Compatibility Matrix
  ┌─────────────────────────────────────────────────────────────┐
  │ Consumer Verification Harness (CVH)                         │
  │ • Multi-version PHP runtime matrix validation               │
  │ • Redis integration and public consumer contract validation │
  └─────────────────────────────────────────────────────────────┘
```

> **Lifecycle Constraint**: RAV, PAV, and CVH represent separate lifecycle stages. RAV is executed **before** tagging and publication. PAV is executed **after** publication to public registry/VCS. CVH validates consumer runtime contract compatibility across the matrix.

---

## 2. Release Artifact Verification (RAV) — Pre-Publication

### 2.1 Purpose & Checks Performed
RAV validates that a git commit candidate is ready for tagging and publication. It deterministically enforces:
1. **Target Version Syntax**: Validates strict Semantic Versioning (`MAJOR.MINOR.PATCH[-PRERELEASE][+BUILD]`).
2. **Git Candidate Commit & Working Tree Identity**:
   - Proves repository is a valid Git worktree.
   - Validates candidate SHA is a 40-character hexadecimal string.
   - Proves candidate SHA exists as a valid `commit` object in Git.
   - Proves current checked-out `HEAD` equals the candidate SHA.
   - Resolves candidate tree SHA (`<candidate_sha>^{tree}`).
   - Proves Git working tree and index are completely clean (no uncommitted, modified, or untracked changes).
3. **Package Identity and Composer Manifest**:
   - Package name is `maatify/php-rate-limiter`.
   - Package license is `proprietary` (matching `LICENSE` and `composer.json`).
   - Manifest contains no static `"version"` field (versions governed by Git tags).
4. **Required Release-Facing Files**: Ensures all consumer-facing files are present:
   - `composer.json`
   - `README.md`
   - `LICENSE`
   - `CHANGELOG.md`
   - `SECURITY.md`
   - `RATE_LIMITER_PACKAGE_REFERENCE.md`
   - `docs/guides/USAGE_GUIDE.md`
   - `examples`
   - `llms.txt`
   - `src`
5. **Distribution Hygiene**: Ensures no temporary, cache, or build junk is checked in (`.env`, `.env.local`, `composer.lock`, `*.pem`, `*.key`, `.phpunit.cache`, `coverage`, `.DS_Store`, `*~`, `*.bak`, `*.swp`).
6. **Candidate Git Archive Export Verification**: Generates a candidate archive stream (`git archive`) and proves that no required release-facing file is excluded by `.gitattributes` or export filtering.
7. **README Artifact Identity**: Validates that `README.md` references the target version without false pre-publication claims.
8. **CHANGELOG Undated Target Section and Boundaries**:
   - Strictly enforces that the target heading is an **undated exact-target section**: `## [<target>]`.
   - Rejects dated headings (`## [<target>] - YYYY-MM-DD`) and status suffixes (`- upcoming`, `- unreleased`, `(planned)`).
   - Enforces `[Unreleased]` boundary rules: `## [Unreleased]` must exist unless semantic review confirms that no represented changes remain unallocated.
9. **SECURITY Supported Versions**: Validates pre-release semantics (supported during release candidate cycle, but explicitly not a released stable version).
10. **Canonical Semantic Review Verification**: Fails closed unless an authorized canonical Semantic Review Record is provided, with all 8 required assertions confirmed.
11. **Content Manifest Hashing**: Computes deterministic SHA-256 hashes of all required release-facing files and `composer.json`.
12. **Delivery Policy Governance**: Evaluates intended delivery policy (`dist` by default, or validates `source-only` qualification-time Decision parameters).

### 2.2 CLI Invocations

Using Composer:
```bash
composer release:verify-artifact -- --target=1.0.0-rc.3 --candidate-sha=<40_CHAR_COMMIT_SHA> --semantic-review-file=<path> [options]
```

Using native PHP:
```bash
php scripts/release/verify-release-artifact.php --target=1.0.0-rc.3 --candidate-sha=<40_CHAR_COMMIT_SHA> --semantic-review-file=<path> [options]
```

### 2.3 Options & Arguments

| Option | Required | Description |
|---|---|---|
| `--target=<version>` | **Yes** | Target release version (e.g., `1.0.0-rc.3`). |
| `--candidate-sha=<sha>` | **Yes** | 40-character hexadecimal git commit SHA of candidate. |
| `--semantic-review-file=<path>` | **Yes** | Path to canonical semantic review evidence JSON file (alias: `--semantic-review-record`). |
| `--repo-path=<path>` | No | Path to repository root (defaults to working directory). |
| `--delivery-policy=<mode>` | No | Delivery policy (`dist` or `source-only`, default: `dist`). |
| `--source-only-decision-id=<id>` | Conditional | Decision ID when delivery policy is `source-only` (e.g. `DEC-019`). |
| `--source-only-decision-file=<path>` | Conditional | Path to source-only Decision Record file. |
| `--source-only-commit=<sha>` | Conditional | Immutable commit SHA of source-only Decision Record. |
| `--output-evidence=<path>` | No | Path to write machine-readable RAV qualification evidence JSON for subsequent PAV. |
| `--output-json=<path>` | No | Path to write verification report JSON. |
| `--format=<summary\|json>` | No | Output format (default: `summary`). |

### 2.4 Canonical Semantic Review Record Schema
Automated pattern matching cannot replace human semantic review of documentation accuracy. Qualifying RAV requires an authorized semantic review record file conforming to schema `1.0.0`:

```json
{
  "schema_version": "1.0.0",
  "target": "1.0.0-rc.3",
  "candidate_sha": "d9138bd257e8cff384c5b5a8d284cff84237be5a",
  "reviewer": "Lead Reviewer <lead@maatify.dev>",
  "reviewed_at": "2026-10-06T20:00:00Z",
  "disposition": "APPROVED",
  "claims": {
    "readme.release_artifact_identity": "CONFIRMED",
    "readme.exact_install_target": "CONFIRMED",
    "readme.pre_publication_truth": "CONFIRMED",
    "changelog.target_allocation": "CONFIRMED",
    "changelog.undated_target_preparation": "CONFIRMED",
    "changelog.no_unallocated_represented_changes": "CONFIRMED",
    "security.lifecycle_support_semantics": "CONFIRMED",
    "package_reference.consumer_identity_consistency": "CONFIRMED"
  },
  "notes": "Verified semantic accuracy and contract alignment."
}
```

All 8 claims must be explicitly present and set to `"CONFIRMED"`. Missing or unconfirmed claims cause immediate RAV failure.

---

## 3. Published Artifact Verification (PAV) — Post-Publication

### 3.1 Purpose & Checks Performed
PAV validates that an artifact published to Packagist, GitHub Releases, or a VCS repository can be installed cleanly by consumers and matches the qualified release. It deterministically enforces:
1. **Authoritative Qualification Evidence Consumption**: Consumes the machine-readable qualification evidence JSON emitted by RAV.
2. **Isolated External Composer Installation**: Spawns a clean external directory with isolated `COMPOSER_HOME` and clean cache, requiring exact `maatify/php-rate-limiter:<target>`.
3. **Audit Evidence Capture**: Records Composer version, effective repository configuration, and environment, redacting any authentication tokens or credentials.
4. **Exact Version Resolution**: Confirms that Composer resolves the exact target version (e.g., `1.0.0-rc.3`).
5. **Actual Installation Mode Proof**:
   - Inspects `vendor/composer/installed.json` for `installation-source`.
   - Proves whether the package was installed via `dist` (archive) or `source` (git clone).
   - Rejects unprovable or ambiguous installation sources.
6. **Prohibition of Silent Fallback**: If Composer repository metadata exposes a `dist` archive, `dist` **must** be the installation mode. Falling back to `source` when `dist` is exposed constitutes a preference/delivery defect and fails verification.
7. **Source-Only Delivery Governance**: If `dist` is not exposed and delivery is `source`-only:
   - Proves that RAV qualification evidence authorized `source-only` delivery.
   - Proves the Decision existed and was `ACTIVE` at qualification time (predating RAV).
   - Validates that current Decision status in `DECISIONS_INDEX.md` is `ACTIVE` or legitimately `SUPERSEDED` with a coherent supersession chain.
8. **Release Reference Proof (SHA Correspondence)**: Validates that the installed package's `reference` in `installed.json` exactly matches the release-qualified candidate commit SHA.
9. **Installed Artifact Content & Hash Correspondence**:
   - Verifies that all required release-facing files are present in the installed package directory.
   - Computes SHA-256 hashes of all installed files and directories, comparing them against the `content_manifest` recorded in RAV qualification evidence.
   - Verifies installed `composer.json` has `license: "proprietary"` and no static `"version"` property.

### 3.2 CLI Invocations

Using Composer:
```bash
composer release:verify-published-artifact -- --qualification-evidence=<path_to_rav_evidence.json> [options]
```

Using native PHP:
```bash
php scripts/release/verify-published-artifact.php --qualification-evidence=<path_to_rav_evidence.json> [options]
```

### 3.3 Options & Arguments

| Option | Required | Description |
|---|---|---|
| `--qualification-evidence=<path>` | **Yes** | Path to machine-readable RAV qualification evidence JSON (alias: `--rav-evidence`). |
| `--target=<version>` | No | Expected published target version (must match qualification evidence). |
| `--qualified-sha=<sha>` | No | Expected 40-character commit SHA (must match qualification evidence). |
| `--package=<name>` | No | Composer package name (defaults to `maatify/php-rate-limiter`). |
| `--composer-repository=<url\|json>` | No | Custom Composer repository endpoint or JSON config (e.g., local mirror or testing VCS). |
| `--keep-temp` | No | Retain temporary test workspace for post-mortem debugging. |
| `--output-json=<path>` | No | Write machine-readable JSON report to file. |
| `--format=<summary\|json>` | No | Output format (default: `summary`). |

---

## 4. Automation and CI Policy

1. **Non-Trivial Release Requirement**: Both RAV and PAV are mandatory, maintained repository tools under `CI_WORKFLOW_STANDARD.md@5.0.0`.
2. **Deterministic & Agnostic**: Neither tool is hardcoded to a specific release version (e.g., `1.0.0-rc.2` or `1.0.0-rc.3`). They accept any valid Semantic Version target and candidate commit SHA.
3. **Strict Fail-Closed Enforcement**: Qualifying commands provide no skip flags. Preinstalled fixture inspection is restricted to internal unit tests and emits `INSPECTION_ONLY`, never qualifying `PASS`.
4. **Execution Boundary**: Implementing this tooling establishes conformance with `CI_WORKFLOW_STANDARD.md`. Executing an actual RAV or PAV run for future releases (such as `1.0.0-rc.3`) occurs only during their respective release preparation work units.
