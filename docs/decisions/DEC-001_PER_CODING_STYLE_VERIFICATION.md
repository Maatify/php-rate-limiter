# DEC-001 — Repository PER Coding Style 3.1 Verification Mechanism

## Status

ACTIVE

## Date

2026-09-21

## Scope / Concern

Repository-wide PHP coding-style verification.

## Decision Authority

Lead technical decision within the Owner-authorized Stage 1 standards-remediation scope.

## Decision

- Base verifier: `php-cs-fixer/shim ^3.95`.
- Pinned baseline: `@PER-CS3x0`.
- PER-CS 3.1 compatibility override: `method_argument_space` does not rewrite multiline argument layout, so the delta verifier can enforce the PER-CS 3.1 array-opening rule.
- PER 3.1 extension: repository-owned `scripts/ci/check-per-cs-31-delta.php`.
- `composer format:check` is the complete non-mutating PER Coding Style 3.1 verification entry point.
- `composer format` applies the PHP-CS-Fixer baseline and then runs the non-mutating PER 3.1 delta verification.
- The canonical normative owner remains `docs/php-engineering-standards/standards/php/PHP_CODING_STYLE_STANDARD.md`.
- Neither PHP-CS-Fixer nor the delta verifier replaces the canonical Standard.

## Rationale

PHP-CS-Fixer is a stable development tool suitable for this repository. Its built-in PER ruleset currently covers PER-CS 3.0, not the complete PER-CS 3.1 contract. PHP-FIG PER Coding Style 3.1 remains the normative baseline, so the repository-owned delta verifier covers the identified normative differences from PER-CS 3.0 to 3.1. This preserves the stable Composer dependency policy without unstable exceptions.

## Consequences

- No unstable dependencies are required.
- The repository's `minimum-stability` remains `stable`.
- The repository's `prefer-stable` remains `true`.
- No consumer runtime dependency is added.
- `format:check` is the complete repository-owned verification entry point.
- If PHP-CS-Fixer gains native stable PER-CS 3.1 support, removal of the delta verifier requires a separate reviewed Work Unit; it is not removed automatically.

## Supersedes

None

## Superseded By

None

## Canonical Contract / Current Owner

`docs/php-engineering-standards/standards/php/PHP_CODING_STYLE_STANDARD.md`
