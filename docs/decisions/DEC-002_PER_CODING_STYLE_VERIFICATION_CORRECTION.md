# DEC-002 — Repository PER Coding Style 3.1 Verification Correction

## Decision ID

DEC-002

## Status

ACTIVE

## Date

2026-09-21

## Scope / Concern

Repository-wide PHP coding-style verification

## Decision Authority

Lead technical decision within the Owner-authorized Stage 1 standards-remediation scope

## Context

DEC-001 adopted `method_argument_space.on_multiline=ignore` to protect the PER-CS 3.1 multiline-array opening-bracket rule. Fresh Full Review established that this blanket override also disables part of the required multiline argument-list verification. Removing the override exposed the specific source-shape conflict in `examples/basic-rate-limit.php`. The source shape resolves both contracts without weakening the baseline or expanding the verifier without need.

## Decision

- Base verifier: `php-cs-fixer/shim ^3.95`.
- Base ruleset: `@PER-CS3x0` without a `method_argument_space` multiline override.
- PER 3.1 extension: `scripts/ci/check-per-cs-31-delta.php`.
- Complete verification entry point: `composer format:check`.
- Canonical owner: `docs/php-engineering-standards/standards/php/PHP_CODING_STYLE_STANDARD.md`.
- Do not use `method_argument_space.on_multiline=ignore`.
- Do not add a third verifier.
- Do not disable a PER baseline rule to resolve a source-format conflict.
- Multiline array arguments must be shaped in source so they remain compatible with PER 3.1 when their shape conflicts with fully multiline argument lists.

## Consequences

- PHP-CS-Fixer retains responsibility for the complete multiline argument-list baseline.
- The repository-owned delta verifier remains unchanged and is limited to uncovered PER 3.1 requirements.
- The affected example uses a source shape accepted by both the baseline and the PER 3.1 delta rule without changing evaluation or runtime semantics.
- `composer format:check` remains the complete non-mutating verification entry point.

## Supersedes

DEC-001

## Superseded By

None

## Canonical Contract / Current Owner

`docs/php-engineering-standards/standards/php/PHP_CODING_STYLE_STANDARD.md`
