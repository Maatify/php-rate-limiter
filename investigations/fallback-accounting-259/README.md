# #259 — local fallback allowance across repeated recovery evaluations

Baseline `main@945b549e3653ccd5370a604d53a70f90e5114764` (tree `af9a95447a668d959717f3149f44ef3f5942b0ed`).

Test: `tests/Integration/Redis/LocalFallbackRecoveryAccountingRedisTest.php` (14 tests, groups `integration`, `fallback-accounting-259`, `php32`).
Run: `run-in-docker.sh <64|32> <phpunit arguments>` from the repository root (isolated `redis:7.0.15-alpine` per call;
`maatify-rl-php64:local` linux/arm64 PHP 8.4.27 INT 8, `maatify-rl-php32:local` linux/386 PHP 8.4.27 INT 4, ext-redis 6.3.0).

Measured on the baseline (login policy, allowance 3 per account in 600 s, circuit tripped by a real outage, first probe moment):

| One public call | Units consumed (baseline) | Intended |
| --- | ---: | ---: |
| no conflict | 1 | 1 |
| one conflict, final answer fallback | 2 | 1 |
| three conflicts, final answer fallback | 4 (the call itself was refused, `fallback_limit_exceeded`) | 1 |
| retries exhausted (concurrency block) | 32 | 0 |
| failed save, repeated in the process | 3 | 1 |
| save applied, reply lost | 2 | 1 |
| conflict, final answer a normal evaluation | 1 | 0 |

| Log (`raw/`) | Source | Result |
| --- | --- | --- |
| `original-64.log`, `original-32.log` | baseline `src/` | 14 tests, 10 failures on both widths |
| `fixed-64.log`, `fixed-32.log` | with the fix | 14 tests, 416 assertions, OK |
| `fixed-php32group-32.log` | with the fix, `--group php32`, PHP_INT_SIZE 4 | 806 tests, 43715 assertions, 64 skips |
| `mutants.log` | removing the required charge: 11 failures; charging the final answer twice: 11 failures (both widths) | |
| `fixed-full-64.log` | whole suite on the local image | only `tests/Unit/ReleaseVerification` fails (the image has no `git`; same on the baseline) |

Prerequisite: a recovery step that writes (an `OPEN` circuit past its interval, or a `HALF_OPEN` one probe-eligible) so that its save can
conflict or fail, and a policy with a bounded fallback configuration.
