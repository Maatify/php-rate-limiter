# Contributing to Maatify Rate Limiter

`maatify/php-rate-limiter` is a standalone, framework-agnostic Composer package. Runtime code belongs under `src/`; tests belong under `tests/`; package behavior and boundaries are documented in [RATE_LIMITER_PACKAGE_REFERENCE.md](RATE_LIMITER_PACKAGE_REFERENCE.md) and the supporting files under `docs/`.

## Local verification

The repository does not commit `composer.lock`. Run the following from the repository root; dependency resolution may create an ignored temporary lock file.

```bash
composer validate --strict
composer update --no-interaction --prefer-dist --no-progress
composer dump-autoload --optimize --strict-psr
composer check-platform-reqs
composer analyse
composer test
composer test:integration
composer audit --no-interaction --abandoned=fail
bash scripts/ci/run-consumer-verification.sh
bash scripts/ci/check-php-syntax.sh
bash scripts/ci/check-readme-examples.sh
bash scripts/ci/run-examples.sh
bash scripts/ci/check-whitespace.sh
ACTIONLINT_BIN=/path/to/actionlint bash scripts/ci/lint-workflows.sh
```

`composer test` runs the full maintained Unit, Integration, and System suites. `composer test:integration` is the focused canonical entrypoint for the maintained Integration suite.

To verify the lowest supported dependency bounds on PHP 8.4, run:

```bash
composer update --prefer-lowest --prefer-stable --no-interaction --prefer-dist --no-progress
composer dump-autoload --optimize --strict-psr
composer check-platform-reqs
composer analyse
composer test
composer test:integration
```

The CI workflow runs the same checks on PHP 8.4 and 8.5. Workflow linting uses actionlint v1.7.12 with a verified checksum; install that version locally or provide its path through `ACTIONLINT_BIN`.

## Pull requests

Keep changes within the requested scope, preserve runtime behavior unless a separately authorized correction is required, and include the exact verification results in the pull request description. Do not commit `composer.lock`, generated dependencies, or local credentials.
