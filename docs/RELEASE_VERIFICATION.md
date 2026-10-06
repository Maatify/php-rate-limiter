# Release and Published Artifact Verification Guide

This document describes the repository-owned, maintained mechanisms for **Release Artifact Verification (RAV)** and **Published Artifact Verification (PAV)** for `maatify/php-rate-limiter`, satisfying:
- `CI_WORKFLOW_STANDARD.md` §2.5 (Release Artifact Verification)
- `CI_WORKFLOW_STANDARD.md` §2.6 (Published Artifact Verification)
- `CI_WORKFLOW_STANDARD.md` §2.7 (Immutable Published Version)
- `COMPOSER_PACKAGE_STANDARD.md` §26 & §26.1 (Verification Rigor and Delivery Mode Requirements)
- `LIBRARY_PRESENTATION_STANDARD.md` §14 & §23 (Distribution Safety and Readme Parity)

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
  │ • Target-agnostic pre-publication inspection                │
  │ • Evaluates repository tree at candidate commit SHA         │
  │ • Verifies SemVer, SHA matching, git tree cleanliness       │
  │ • Checks distribution hygiene & required public files       │
  │ • Verifies README, CHANGELOG unreleased, SECURITY matrix    │
  │ • Enforces Human Semantic Review Record boundary            │
  └─────────────────────────────────────────────────────────────┘
              │
      ( Tag & Publish )
              │
              ▼
   Stage B: Post-Publication
  ┌─────────────────────────────────────────────────────────────┐
  │ Published Artifact Verification (PAV)                       │
  │ • Target-agnostic post-publication inspection               │
  │ • Installs package in fresh, isolated consumer environment  │
  │ • Proves exact version resolution                           │
  │ • Proves actual installation mode (dist vs source)          │
  │ • Prohibits source fallback when dist archive exposed       │
  │ • Enforces pre-existing Decision Record if source-only      │
  │ • Validates reference correspondence against qualified SHA  │
  │ • Inspects installed directory files and manifest integrity │
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
2. **Candidate SHA Identity**: Validates candidate SHA is a 40-character hexadecimal string matching the current repository `HEAD`.
3. **Working Tree Cleanliness**: Validates git status is completely clean (untracked, modified, or staged files cause immediate failure).
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
5. **Distribution Hygiene**: Ensures no temporary, cache, or build junk is checked in (`.phpunit.cache`, `coverage`, `.DS_Store`, `*~`, `*.bak`, `*.swp`).
6. **Composer Package Manifest**: Validates package name is `maatify/php-rate-limiter`, type is `library`, and license is `MIT`.
7. **README Artifact Identity**: Validates that `README.md` explicitly references the target version.
8. **CHANGELOG Allocation & Date Semantics**:
   - Ensures an entry exists for the target version.
   - Strictly enforces that unreleased pre-publication candidates do **not** carry calendar release dates (must be marked `unreleased` or `upcoming`).
9. **SECURITY Supported Versions**: Validates pre-release semantics (supported during release candidate cycle, but explicitly not a released stable version).
10. **Semantic Review Verification**: Fails closed unless an authorized Human Semantic Review Record is provided, or `--skip-semantic-review` is explicitly passed for preliminary / dry-run inspection.

### 2.2 CLI Invocations

Using Composer:
```bash
composer release:verify-artifact -- --target=1.0.0-rc.3 --candidate-sha=<40_CHAR_COMMIT_SHA> [options]
```

Using native PHP:
```bash
php scripts/release/verify-release-artifact.php --target=1.0.0-rc.3 --candidate-sha=<40_CHAR_COMMIT_SHA> [options]
```

### 2.3 Options & Arguments

| Option | Required | Description |
|---|---|---|
| `--target=<version>` | **Yes** | Target release version (e.g., `1.0.0-rc.3`). |
| `--candidate-sha=<sha>` | **Yes** | 40-character hexadecimal git commit SHA of candidate. |
| `--repo-path=<path>` | No | Path to repository root (defaults to working directory). |
| `--no-clean-check` | No | Skip clean working tree requirement (use only in offline testing). |
| `--semantic-review-record=<path>` | Conditional | Path to JSON file containing signed/authorized semantic review evidence. |
| `--skip-semantic-review` | Conditional | Explicit flag for dry-run/automation where review record is pending. |
| `--format=<summary\|json>` | No | Output format (default: `summary`). |

### 2.4 Semantic Review Record Schema
Automated pattern matching cannot replace human semantic review of documentation accuracy. To satisfy the fail-closed semantic review check, provide a record file via `--semantic-review-record=<path>`:

```json
{
  "target": "1.0.0-rc.3",
  "candidate_sha": "d9138bd257e8cff384c5b5a8d284cff84237be5a",
  "reviewer": "Reviewer Name <reviewer@example.com>",
  "reviewed_at": "2026-10-06T20:00:00Z",
  "reviewed_documents": [
    "README.md",
    "CHANGELOG.md",
    "SECURITY.md",
    "RATE_LIMITER_PACKAGE_REFERENCE.md"
  ],
  "disposition": "APPROVED",
  "notes": "Verified semantic accuracy and contract alignment."
}
```

---

## 3. Published Artifact Verification (PAV) — Post-Publication

### 3.1 Purpose & Checks Performed
PAV validates that an artifact published to Packagist, GitHub Releases, or a VCS repository can be installed cleanly by consumers and matches the qualified release. It deterministically enforces:
1. **Isolated Clean Installation**: Runs `composer require` in an isolated directory with isolated `COMPOSER_HOME` and clean cache.
2. **Exact Version Resolution**: Confirms that Composer resolves the exact target version (e.g., `1.0.0-rc.3`).
3. **Actual Installation Mode Proof**:
   - Inspects `vendor/composer/installed.json` for `installation-source`.
   - Proves whether the package was installed via `dist` (archive) or `source` (git clone).
   - Rejects unprovable or ambiguous installation sources.
4. **Prohibition of Silent Fallback**: If Composer repository metadata exposes a `dist` archive, `dist` **must** be the installation mode. Falling back to `source` when `dist` is exposed constitutes a preference/delivery defect and fails verification.
5. **Source-Only Delivery Governance**: If `dist` is not exposed and delivery is `source`-only, verification requires an `ACTIVE` Owner-approved Decision Record justifying source-only delivery.
6. **Release Reference Correspondence**: Validates that the installed package's `reference` in `installed.json` exactly matches the qualified release commit SHA or tag.
7. **Installed Artifact Content**: Verifies that the installed directory contains all required consumer files and that installed metadata is intact.

### 3.2 CLI Invocations

Using Composer:
```bash
composer release:verify-published-artifact -- --target=1.0.0-rc.3 --qualified-sha=<40_CHAR_COMMIT_SHA> [options]
```

Using native PHP:
```bash
php scripts/release/verify-published-artifact.php --target=1.0.0-rc.3 --qualified-sha=<40_CHAR_COMMIT_SHA> [options]
```

### 3.3 Options & Arguments

| Option | Required | Description |
|---|---|---|
| `--target=<version>` | **Yes** | Published target version (e.g., `1.0.0-rc.3`). |
| `--qualified-sha=<sha>` | **Yes** | Expected 40-character commit SHA corresponding to the release. |
| `--qualified-reference=<ref>` | No | Qualified release tag or git reference (defaults to target version). |
| `--package=<name>` | No | Composer package name (defaults to `maatify/php-rate-limiter`). |
| `--expected-mode=<dist\|source>`| No | Expected install mode (defaults to `dist`). |
| `--source-only-decision-file=<path>` | Conditional | Path to active Decision Record when testing source-only delivery. |
| `--composer-repository=<url\|json>` | No | Custom Composer repository endpoint or JSON config (e.g., local mirror or testing VCS). |
| `--installed-path=<path>` | No | Offline inspection mode: path to pre-installed package directory. |
| `--installed-json-path=<path>` | No | Offline inspection mode: path to `vendor/composer/installed.json`. |
| `--keep-temp` | No | Retain temporary test workspace for post-mortem debugging. |
| `--format=<summary\|json>` | No | Output format (default: `summary`). |

---

## 4. Automation and CI Policy

1. **Non-Trivial Release Requirement**: Both RAV and PAV are mandatory, maintained repository tools under `CI_WORKFLOW_STANDARD.md@5.0.0`.
2. **Deterministic & Agnostic**: Neither tool is hardcoded to a specific release version (e.g., `1.0.0-rc.2` or `1.0.0-rc.3`). They accept any valid Semantic Version target and candidate commit SHA.
3. **Execution Boundary**: Implementing this tooling establishes conformance with `CI_WORKFLOW_STANDARD.md`. Executing an actual RAV or PAV run for future releases (such as `1.0.0-rc.3`) occurs only during their respective release preparation work units.
