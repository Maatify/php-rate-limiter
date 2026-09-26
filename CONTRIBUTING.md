# Contributing to Maatify Rate Limiter

`maatify/php-rate-limiter` is the standalone, framework-agnostic Composer package
with Composer identity `maatify/php-rate-limiter`. It is proprietary and remains
in pre-release development. Runtime code belongs under `src/`; tests belong under
`tests/`; package behavior and boundaries are documented in
[RATE_LIMITER_PACKAGE_REFERENCE.md](RATE_LIMITER_PACKAGE_REFERENCE.md) and the
supporting files under `docs/`.

## Package boundaries

The package owns deterministic rate-limit evaluation, bounded scoring, budgets,
correlation, circuit-breaker behavior, failure modes, the public DTO/service
contracts, and the official Redis persistence semantics. The host owns account and
session truth, the Redis client and connection lifecycle when using the official
Redis store, custom storage and locking when selecting another backend, transport
responses, authorization, logging destinations, and cross-domain reporting. Keep
the runtime framework-agnostic and storage-agnostic at the contract/core level.

The canonical source topology is a single capability:

```text
src/{Command,Config,Contract,DTO,Exception,Repository,Service}/
```

Do not introduce capability wrappers, duplicate namespaces, compatibility shims,
or alternate source roots.

## Ways to contribute

- Improve tests, documentation, examples, or verification scripts while keeping
  the current public and behavioral contract intact.
- Propose a focused correction with evidence from the current runtime and tests.
- Discuss security, storage, concurrency, or architecture concerns before coding
  when the change affects a package boundary.

## Architecture discussion

Any change to the public API, behavioral contract, architecture, source topology,
persistence boundary, or security semantics must be discussed and approved before
implementation when it goes beyond a localized, proven fix. A GitHub Issue is not
required for every change; use the appropriate repository discussion or maintainer
approval route for the scope and sensitivity of the proposal.

## Local verification

The repository does not commit `composer.lock`. Run the following from the repository root; dependency resolution may create an ignored temporary lock file.

```bash
composer validate --strict
composer update --no-interaction --prefer-dist --no-progress
composer dump-autoload --optimize --strict-psr
composer check-platform-reqs
composer format:check
composer format
composer analyse
composer test
composer test:unit
composer test:integration
composer audit --no-interaction --abandoned=fail
bash scripts/ci/run-consumer-verification.sh
bash scripts/ci/check-php-syntax.sh
bash scripts/ci/check-readme-examples.sh
bash scripts/ci/run-examples.sh
bash scripts/ci/check-whitespace.sh
ACTIONLINT_BIN=/path/to/actionlint bash scripts/ci/lint-workflows.sh
```

`composer format:check` is the complete non-mutating PER Coding Style 3.1
verification command: it checks the PHP-CS-Fixer PER-CS 3.0 baseline and the
repository-owned PER 3.1 delta verifier. `composer format` applies baseline
formatting and then runs the same non-mutating delta verification; any remaining
PER 3.1 delta violation requires a manual mechanical fix.

`composer test` runs the full maintained Unit, Integration, and System suites.
`composer test:unit` runs the Unit suite, and `composer test:integration` is the
focused canonical entrypoint for the Integration suite. It requires Docker with
Compose support for the repository-owned disposable Redis 7.0.15 service and PHP
`pcntl` in the local Integration runner because the required Redis concurrency
proofs use independent forked PHP workers; it runs the Integration suite and
guarantees teardown. `ext-redis` and Predis are not required, and `ext-pcntl` is
not a package runtime dependency.
System tests are included
in the full `composer test` run and protect end-to-end engine workflows and
behavioral contracts.

Run the consumer verification harness twice from clean consumer directories:

```bash
bash scripts/ci/run-consumer-verification.sh
```

The harness requires Docker with Compose support and reuses the repository-owned
Redis 7.0.15 Compose contract. Each of its two runs creates a fresh disposable
Redis state and cleans it afterward. It validates the package as an external
Composer consumer, including autoloading and the public runtime surface.
`ext-redis` and Predis are not required.

To verify the lowest supported dependency bounds on PHP 8.4, run:

```bash
composer update --prefer-lowest --prefer-stable --no-interaction --prefer-dist --no-progress
composer dump-autoload --optimize --strict-psr
composer check-platform-reqs
composer analyse
composer test
composer test:integration
```

The CI verification mapping is intentionally split across its gates:

- The complete `composer test` matrix runs on PHP 8.4 and 8.5, representing the full Unit, Integration, and System suite on both versions.
- The focused `composer test:integration` job runs independently on PHP 8.4.
- PHP syntax, PHPStan, Composer audit, Consumer Verification, and lowest-supported dependency verification run on PHP 8.4.
- The PHP coding-style gate runs on PHP 8.5.
- Workflow lint and whitespace are repository-level gates, not PHP compatibility-matrix jobs.

Workflow linting uses actionlint v1.7.12 with a verified checksum; install that version locally or provide its path through `ACTIONLINT_BIN`.

## Pull requests and review expectations

Keep changes within the requested scope, preserve runtime behavior unless a
separately authorized correction is required, and include the exact verification
results in the pull request description. Explain public, architectural, security,
concurrency, or persistence effects explicitly. Do not change pinned standards or
silently expand the package boundary. Do not commit generated dependencies or
local credentials.

## Security reporting

Report sensitive security vulnerabilities to `support@maatify.dev`. Do not publish
exploit details, credentials, or other sensitive vulnerability information in a
public GitHub issue. Use public issues only for non-sensitive defects and general
discussion.

## Composer lock policy

This standalone library intentionally does not commit `composer.lock`. Contributors
must validate both the declared dependency constraints and the supported lowest
dependency bounds locally. CI resolves dependencies from `composer.json`; temporary
lock files created by Composer remain local and must not be added to the repository.
