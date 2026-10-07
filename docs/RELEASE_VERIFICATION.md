# Release and Published Artifact Verification Guide

This document describes the repository-owned, maintained mechanisms for **Release Artifact Verification (RAV)** and **Published Artifact Verification (PAV)** for `maatify/php-rate-limiter`, satisfying:
- `CI_WORKFLOW_STANDARD.md` §2.5 (Release Artifact Verification)
- `CI_WORKFLOW_STANDARD.md` §2.6 (Published Artifact Verification)
- `CI_WORKFLOW_STANDARD.md` §2.7 (Immutable Published Version)
- `COMPOSER_PACKAGE_STANDARD.md` §26 & §26.1 (Distribution/Archive Safety and Source-Only Delivery)
- `LIBRARY_PRESENTATION_STANDARD.md` §8.1.2, §14 & §23 (Distribution Safety, License Identity, and Readme Parity)

The tooling is target-agnostic. Examples use the placeholders `<target>` and `<candidate-sha>`; nothing here asserts the state of any particular release.

---

## 1. Architectural Distinction & Lifecycle Stages

Three separate verification mechanisms exist and none substitutes for another:

```text
[ Commit Candidate Selected ]
              │
              ▼
   Stage A: Pre-Publication
  ┌─────────────────────────────────────────────────────────────┐
  │ Release Artifact Verification (RAV)                         │
  │ • Qualifies the exact candidate SHA / tree for target X     │
  │ • Reads immutable Git objects, not the mutable worktree     │
  │ • Proves the real `git archive` carries all required content│
  │ • Proves source-only governance from an immutable Decision  │
  │ • Emits complete, schema-validated qualification evidence   │
  └─────────────────────────────────────────────────────────────┘
              │
      ( Tag & Publish )
              │
              ▼
   Stage B: Post-Publication
  ┌─────────────────────────────────────────────────────────────┐
  │ Published Artifact Verification (PAV)                       │
  │ • Rejects incomplete RAV evidence BEFORE any network work   │
  │ • Resolves ONLY from the qualification-bound channel        │
  │ • Runs Composer in a fully controlled, isolated environment │
  │ • Proves exact resolved version and actual install mode     │
  │ • Retains dist/source metadata (credentials redacted)       │
  │ • Proves installed content equals the qualified manifest    │
  └─────────────────────────────────────────────────────────────┘
              │
              ▼
   Stage C: Compatibility Matrix
  ┌─────────────────────────────────────────────────────────────┐
  │ Consumer Verification Harness (CVH)                         │
  │ • Multi-version PHP runtime matrix validation               │
  └─────────────────────────────────────────────────────────────┘
```

> **Meaning of PASS.** A qualifying RAV PASS means the exact candidate SHA is release-qualified under the complete applicable contract. A qualifying PAV PASS means the artifact actually delivered for the exact published version has been independently verified against that qualified evidence. Inspection and test fixtures can never produce a qualifying PASS (they report `INSPECTION_ONLY`). A caller-supplied command-line value is never evidence by itself. Everything is fail-closed.

---

## 2. Release Artifact Verification (RAV) — Pre-Publication

### 2.1 Evidence boundary: Git objects, not the worktree

Qualification is for the exact Git candidate commit and tree. RAV therefore:

1. requires a real Git repository, a 40-hex candidate SHA that is a `commit` object, `HEAD == candidate`, and a **clean worktree and index**;
2. resolves `<candidate-sha>^{tree}`;
3. exports the candidate tree's blobs from Git objects into a private snapshot (raw object content: no attributes, filters, `export-ignore` or EOL conversion) and evaluates **every content check against that snapshot**;
4. builds the content manifest from that snapshot, and proves distribution from the actual `git archive` of the candidate.

The clean checked-out worktree is still required, but no check reads a filesystem representation that could differ from the candidate object while claiming immutable candidate evidence.

### 2.2 Checks performed

1. **Target version syntax** — strict Semantic Versioning.
2. **Git candidate identity** — as above.
3. **Package identity and Composer manifest** — name `maatify/php-rate-limiter`, license `proprietary`, no static `"version"`.
4. **Required release-facing content** — `src`, `composer.json`, `README.md`, `LICENSE`, `CHANGELOG.md`, `SECURITY.md`, `RATE_LIMITER_PACKAGE_REFERENCE.md`, `docs/guides/USAGE_GUIDE.md`, `examples`, `llms.txt`.
5. **Distribution safety** — no forbidden tracked artifacts: `.env`/`.env.*` (except `.env.example|dist|sample`), `auth.json`, `composer.lock`, `*.pem`, `*.key`, `*.swp`, `*.bak`, `*~`, `.DS_Store`, and `vendor/`, `.idea/`, `.vscode/`, `.phpunit.cache/`, `coverage/`.
6. **Candidate archive verification (nested content)** — the real `git archive` is generated, extracted, and compared with the candidate tree **file by file**:
   - every tracked file under a required path (for example `src/Nested/CriticalRuntimeFile.php`, not just `src/`) must be present in the archive with identical content, so nested omissions caused by `.gitattributes` `export-ignore` or other archive behavior fail;
   - the effective impact of `composer.json` `archive.exclude` is evaluated (gitignore-style matching, last match wins, `!` re-includes, excluded directories exclude their files). If it removes any required file, RAV fails. If it is absent the evidence records `NOT_APPLICABLE`; `archive.exclude` is never added just to exercise the verifier;
   - no forbidden entry may enter the archive.
7. **README identity**, 8. **CHANGELOG undated exact-target heading and `[Unreleased]` boundary**, 9. **SECURITY pre-release lifecycle semantics** — structural checks on the candidate snapshot.
10. **Canonical semantic review** — all 8 claims `CONFIRMED` for this exact target and candidate (see §2.5).
11. **Delivery policy** — `dist` (default) or `source-only` (see §2.6). Any other value fails.
12. **Qualification-evidence schema** — the emitted evidence is validated against the same schema PAV enforces (see §2.4); a violation fails RAV.

### 2.3 CLI

```bash
composer release:verify-artifact -- --target=<target> --candidate-sha=<candidate-sha> --semantic-review-file=<path> --output-evidence=<evidence-path> [options]
```

```bash
php scripts/release/verify-release-artifact.php --target=<target> --candidate-sha=<candidate-sha> --semantic-review-file=<path> --output-evidence=<evidence-path> [options]
```

| Option | Required | Description |
|---|---|---|
| `--target=<version>` | **Yes** | Exact target SemVer. |
| `--candidate-sha=<sha>` | **Yes** | 40-hex candidate commit SHA. |
| `--semantic-review-file=<path>` | **Yes** | Canonical semantic review JSON (alias `--semantic-review-record`); `reviewed_at` must not be later than the RAV start (§2.5). |
| `--repo-path=<path>` | No | Repository root (default: this repository). |
| `--delivery-policy=<mode>` | No | `dist` (default) or `source-only`. |
| `--source-only-decision-id=<id>` | source-only | Decision ID, e.g. `DEC-NNN`. |
| `--source-only-decision-file=<path>` | source-only | Canonical `docs/decisions/DEC-NNN_*.md` path. |
| `--source-only-commit=<sha>` | source-only | Immutable **40-hex commit** of the Decision (verified from Git objects). |
| `--output-evidence=<evidence-path>` | **Yes** | Durable destination for the qualification evidence (see §2.7). Omitting it is a usage failure (exit 2) and no verification runs. |
| `--output-json=<report-path>` / `--format=<summary\|json>` | No | Optional report output (same destination rules as the evidence). |

There is no option to choose the Composer channel for `dist`: the package-approved channel is fixed by the package (`https://repo.packagist.org`). For `source-only` the channel comes only from the Owner-approved Decision Record.

### 2.4 Qualification evidence schema (`schema_version` `2.0.0`)

One canonical schema (`QualificationEvidenceSchema`) is produced by RAV and enforced by PAV:

| Field | Requirement |
|---|---|
| `schema_version` | exactly `2.0.0` |
| `status` | `PASS` |
| `package_name` | `maatify/php-rate-limiter` |
| `target_version` | valid SemVer |
| `candidate_sha`, `candidate_tree_sha` | 40-hex |
| `qualification_started_at`, `qualified_at` | strict UTC `YYYY-MM-DDTHH:MM:SSZ`; `semantic_review.reviewed_at ≤ qualification_started_at ≤ qualified_at` |
| `delivery_policy` | `dist` or `source-only`; anything else fails |
| `approved_distribution_channel` | credential-free URL; for `dist` must equal the package-approved channel |
| `semantic_review` | the validated record: schema `1.0.0`, same target and candidate, `APPROVED`, reviewer, strict-UTC `reviewed_at` **not later than `qualification_started_at`**, all 8 claims `CONFIRMED` |
| `content_manifest` | SHA-256 of exactly the 10 required paths; no malformed/ambiguous paths, no missing or extra entries |
| `distribution_evidence` | `git-archive` verified; required files in archive; none missing; archive content equals tree; no forbidden entries; `.gitattributes` and `composer.json` `archive.exclude` impact recorded |
| `source_only_decision` | `null` for `dist`; complete immutable qualification-time evidence for `source-only` (§2.6) |

Missing, empty, malformed, wrong-schema, wrong-target/SHA, partial-manifest, future-dated-review, or incoherent evidence is rejected by PAV before any Composer work.

### 2.5 Canonical Semantic Review Record (schema `1.0.0`)

Pattern matching cannot replace human review of documentation truth, so qualifying RAV requires:

```json
{
  "schema_version": "1.0.0",
  "target": "<target>",
  "candidate_sha": "<candidate-sha>",
  "reviewer": "Reviewer <reviewer@example.invalid>",
  "reviewed_at": "YYYY-MM-DDTHH:MM:SSZ",
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
  "notes": "optional"
}
```

The validated record is retained inside the qualification evidence.

**Chronology.** The review must already exist when RAV consumes it: `reviewed_at <= qualification_started_at` (a review exactly at the boundary or earlier is eligible; a later one fails). `reviewed_at` must be strict UTC (`YYYY-MM-DDTHH:MM:SSZ`); offsets, local time, date-only or free-form values fail rather than being normalised. The rule is enforced twice: by RAV when it reads the record, and by the qualification-evidence schema, so PAV also rejects fabricated or tampered evidence containing a future-dated review. It is deliberately not derived from Git author/committer timestamps.

### 2.6 Source-only delivery: immutable qualification-time proof

Source-only delivery is a generic verifier capability; the normal policy for this package is `dist`, and no source-only Decision exists for it. When `delivery_policy = source-only`, RAV proves — **from Git objects at the supplied immutable commit, never from current working-tree text or a commit string** — before qualification:

1. the reference is a full 40-hex SHA, exists as a **commit object**, and is an **ancestor of the candidate** (so the Decision pre-exists the qualification boundary);
2. the Decision Record path and `docs/decisions/DECISIONS_INDEX.md` both exist **in that commit**;
3. the record declares its own Decision ID and Status `ACTIVE`, and the Index row at that commit is `ACTIVE` for that record path;
4. at the candidate, the record is **byte-identical** (same blob) and the Index row is still `ACTIVE` (no silent amendment);
5. Owner approval: the record's `## Decision Authority` states `Owner-approved`, and the declaration has `Owner Approval: APPROVED`, an `Approving Authority`, an `Approval Date` and an `Effective Date`;
6. timing: the approval/effective instant is **strictly earlier than the RAV start**. Date-only values are ambiguous about the time of day, so they are resolved to the *end* of that UTC day (a same-day approval is therefore not yet effective); missing or unparseable timing fails;
7. the declaration names the exact package, a credential-free Composer channel, `Delivery Mode: source-only`, `Intentional Canonical Delivery: yes`, a non-empty dist rationale, and a bounded version scope that covers the target.

Decision Records that may authorize source-only delivery use the repository's existing sections (`## Decision ID`, `## Status`, `## Decision Authority`, `## Supersedes`, `## Superseded By`) plus:

```markdown
## Source-Only Delivery Declaration

- Package: maatify/php-rate-limiter
- Composer Channel: https://packages.example.invalid/maatify
- Delivery Mode: source-only
- Intentional Canonical Delivery: yes
- Version Scope: 1.0.x, 1.1.0-rc.1
- Dist Rationale: <why dist is intentionally not offered>
- Owner Approval: APPROVED
- Approving Authority: <Owner authority>
- Approval Date: YYYY-MM-DD
- Effective Date: YYYY-MM-DD
- Maintenance Owner: <when applicable>
```

`Version Scope` is a comma-separated list of exact SemVer versions, minor lines (`1.0.x`) or major lines (`1.x`); anything else is malformed and fails. A Decision created or approved after qualification cannot retroactively qualify a target.

The retained evidence records: Decision ID, record path, immutable commit, record and index blob IDs, record and index status at qualification, Owner approval evidence, effective instant, package, **approved channel**, version scope and coverage, delivery mode, rationale, maintenance owner, qualification target, candidate SHA and RAV start time. That channel becomes the PAV channel.

### 2.7 Evidence persistence is part of PASS

Qualification evidence that is not durably retained cannot be consumed by PAV or recorded by CI, so **the public qualifying RAV can report PASS only after the complete evidence has been persisted**:

```bash
composer release:verify-artifact -- --target=<target> --candidate-sha=<candidate-sha> --semantic-review-file=<path> --output-evidence=<evidence-path>
```

- `--output-evidence=<evidence-path>` is **mandatory**. Without it the command prints usage and exits `2`; it never verifies and never prints PASS.
- The destination must be **outside the candidate repository** (not the root, `.git/`, any subdirectory, or a symlink into it) so persisting cannot dirty the qualified candidate. The parent directory must already exist and be writable, and the destination must **not already exist** (stale evidence is never overwritten). The destination is validated before any verification work.
- Persistence is atomic and complete: JSON is encoded with fail-on-error semantics, written to a temporary file in the destination directory, byte-count checked, flushed and closed, re-read and decoded, then atomically renamed and re-read. The persisted JSON must equal the generated evidence and satisfy the canonical schema, and the candidate must still be clean at the candidate SHA. A failure at any step — unwritable or invalid destination, directory destination, short write, encoding or read-back failure, malformed or differing persisted data — is a **RAV FAIL with a non-zero exit** and leaves no final evidence file.
- `ReleaseArtifactVerifier::verify()` is the in-memory engine used by unit tests. Its result is **not** a release qualification; only `verifyQualifying()` (used by the CLI) returns PASS, and only after persistence.

---

## 3. Published Artifact Verification (PAV) — Post-Publication

### 3.1 CLI

```bash
composer release:verify-published-artifact -- --qualification-evidence=<evidence-path> --output-json=<report-path> [options]
```

```bash
php scripts/release/verify-published-artifact.php --qualification-evidence=<evidence-path> --output-json=<report-path> [options]
```

| Option | Required | Description |
|---|---|---|
| `--qualification-evidence=<path>` | **Yes** | RAV qualification evidence (alias `--rav-evidence`). |
| `--output-json=<report-path>` | **Yes** | Durable machine-readable PAV report (see §3.8). Omitting it is a usage failure (exit 2). |
| `--target=<version>` / `--qualified-sha=<sha>` | No | Assertions that must equal the evidence. |
| `--package=<name>` | No | Must equal the evidence package. |
| `--composer-repository=<url>` | No | **Assertion only.** It must equal the approved channel bound in the evidence; otherwise PAV fails. It never selects the channel. |
| `--keep-temp` | No | Retain the isolated workspace for debugging (otherwise it is removed on success *and* failure). |
| `--format=<summary\|json>` | No | Console output format. |

### 3.2 Order of verification

1. **Complete evidence validation** (schema §2.4) — before any network work.
2. **Channel binding** — the repository used is the evidence's `approved_distribution_channel`; a differing caller value fails. Default Packagist is explicitly disabled in the consumer manifest so resolution cannot silently use another source.
3. **Source-only historical chain** (source-only only) — proven before installation (§3.5). Unavailable repository history fails closed.
4. **Isolated Composer installation** (§3.3).
5. **Exact resolved version** (§3.4).
6. **Installation mode, delivery metadata, SHA correspondence** (§3.6).
7. **Installed content correspondence** (§3.7).

### 3.3 Composer isolation and effective-configuration evidence

PAV runs in a fresh external root with an isolated `HOME`, `COMPOSER_HOME` (containing an explicit empty `config.json`), `COMPOSER_CACHE_DIR`, `COMPOSER_VENDOR_DIR` and `COMPOSER` (root manifest). **The child process inherits nothing**: its environment is built explicitly from those values, `COMPOSER_NO_INTERACTION`, `PATH`, and transport-only settings (`HTTP(S)_PROXY`, `NO_PROXY`, `SSL_CERT_FILE`, `SSL_CERT_DIR`, `CURL_CA_BUNDLE`) that cannot alter repository resolution or install mode. Therefore `COMPOSER_AUTH`, `COMPOSER_ROOT_VERSION`, `COMPOSER_MIRROR_PATH_REPOS`, `COMPOSER_PREFER_STABLE`, `COMPOSER_MINIMAL_CHANGES`, `COMPOSER_WITH_ALL_DEPENDENCIES`, any inherited `COMPOSER_*`, `XDG_*`, `GIT_CONFIG_*` and similar developer-machine policy are cleared. Plugins and scripts are disabled (`--no-plugins --no-scripts`, `allow-plugins: false`). Qualifying PAV uses no credentials.

The report retains non-secret audit evidence: Composer version, effective repository/channel, requested install flag and the effective `preferred-install` configuration, isolated root/home/cache, the controlled variables that were set, the **names** (never values) of inherited variables that were cleared, and the names of transport settings passed through. Credentials, `auth.json`, authorization headers and environment secrets are never persisted; the process environment is never dumped.

### 3.4 Exact resolved-version proof

The root constraint is not evidence. After installation PAV reads Composer-supported metadata (`vendor/composer/installed.json` and `composer.lock`) and requires: observed package name equals the qualified package, observed version equals the qualification target (a leading `v` is normalised), and `composer.lock` agrees. Missing, duplicated, mismatching or ambiguous metadata fails. Requested version, resolved version, lock version and installed package name are retained. The package's own `composer.json` (which intentionally has no static version) is never used.

### 3.5 Source-only historical chain

PAV consumes the same immutable evidence RAV produced and proves it again from history:

- the retained evidence is **reproduced** from the Decision commit and candidate objects and must equal what was retained (same Decision ID, path, immutable reference, package, channel, scope, target, candidate, timing);
- the candidate is contained in current history, and the Decision remains discoverable in the current Index with the same record path;
- **still `ACTIVE`:** the Index and record agree and the record is unchanged;
- **now `SUPERSEDED`:** the historical record still proves the old state; the current record and Index both say `SUPERSEDED`; the approved declaration and authority were **not rewritten**; the record and Index agree on `Superseded By`; each successor exists in the Index and as a record, agrees between record and Index, and **records that it supersedes** the predecessor (symmetry); cycles are rejected; the chain must end in an `ACTIVE` Decision. Merely checking `Superseded By != None` is insufficient and not accepted;
- repository or history unavailable (for example no Git history), missing or non-discoverable records, a broken or asymmetric chain, or a rewritten record: **FAIL**.

A later legitimate supersession does not invalidate the historical qualification; it also does not make the superseded Decision current authority.

### 3.6 Installation mode, delivery metadata, SHA correspondence

The mode is proven from Composer's installed-package metadata (`installation-source`), never from flags. PAV retains: `installation-source`, `dist` type/URL/reference, `source` type/URL/reference, resolved package and version, and install path. URLs are redacted (userinfo removed, query values masked).

- If `dist` is exposed (installed metadata, or lock — which must agree) and the observed mode is `source`: **FAIL**, even if Composer succeeded, the source content is correct, or a source-only Decision exists.
- If `dist` is not exposed, `source` is exposed and observed, the qualified policy is `source-only`, and the historical chain passes: allowed. Otherwise: FAIL.
- The observed `dist`/`source` reference must equal the qualified candidate SHA. A tag string equal to a caller tag is not accepted.

### 3.7 Content correspondence

The installed package is compared against the **complete** RAV content manifest (an incomplete or absent manifest fails): every manifest path must exist and hash identically, so nested differences or injected files inside `src`/`examples` change the directory hash and fail. The installed package must also contain every required file, no forbidden/sensitive/development material (`.env*`, `auth.json`, keys, `vendor/`, `composer.lock`, IDE and cache directories, backup/swap files) and no symbolic links, and an installed `composer.json` with the proprietary license and no static version.

---

### 3.8 Report persistence is part of PASS

CI Workflow §2.6 requires the exact version, resolved source/dist identity, installation mode, installed path/content evidence and result to be retained, so **the public qualifying PAV can report PASS only after its complete report has been persisted**:

- `--output-json=<report-path>` is **mandatory** (usage failure, exit `2`, without it) and follows the same destination rules as §2.7: outside the repository tree, existing parent directory, not already existing, validated before any network work. Temporary isolated Composer roots are never the durable destination.
- The persisted JSON is the complete result: `status`, package, target, qualified SHA, `installation_mode`, `installed_path`, `composer_audit` (version, effective channel, install preference, controlled environment), `delivery_evidence` (installation source, dist/source type/redacted URL/reference, resolved package/version/install path), every check (resolved version, installation mode, qualified reference, installed content, forbidden content), `failures` and `verified_at`. A FAIL result is persisted too, for retention.
- Write, read-back or comparison failure turns the result into **FAIL with a non-zero exit**; `OVERALL RESULT: PASS` is printed only after persistence succeeded.
- `PublishedArtifactVerifier::verify()` is the in-memory engine; the public lifecycle is `verifyQualifying()`.

---

## 4. Qualifying vs. Test-Only Boundaries

| Capability | Qualifying | Test-only |
|---|---|---|
| RAV | `ReleaseArtifactVerifier::verifyQualifying()` (the CLI): verification **plus** durable evidence persistence | in-memory `verify()` results and `is_qualifying=false` (`INSPECTION_ONLY`) |
| PAV | `PublishedArtifactVerifier::verifyQualifying()` (the CLI): real isolated Composer resolution from the bound channel **plus** durable report persistence | in-memory `verify()` and `inspectPreinstalledFixture()` (always `INSPECTION_ONLY`) |
| Repositories | only the qualification-bound approved channel | fixture/fake repositories exist only inside unit tests and never produce a PASS |
| Evidence input | complete schema-valid RAV evidence | partial structures are used only against individual `evaluate*` methods |

---

## 5. Automation and CI Policy

1. **Maintained tools:** RAV and PAV are repository-owned tooling under `CI_WORKFLOW_STANDARD.md@5.0.0`; their behavior is covered by the unit suite under `tests/Unit/ReleaseVerification/`.
2. **Deterministic & agnostic:** neither tool is hard-coded to a release version; both accept any valid SemVer target and candidate SHA.
3. **Fail-closed:** qualifying commands have no skip flags.
4. **Execution boundary:** having this tooling establishes conformance. Running RAV or PAV for an actual release occurs only during that release's own preparation and post-publication work units.
