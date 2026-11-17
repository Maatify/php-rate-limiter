# #259 — local fallback allowance across repeated recovery evaluations

Baseline `main@945b549e3653ccd5370a604d53a70f90e5114764` (tree `af9a95447a668d959717f3149f44ef3f5942b0ed`).

Test: `tests/Integration/Redis/LocalFallbackRecoveryAccountingRedisTest.php` (17 tests, groups `integration`, `fallback-accounting-259`, `php32`).
Run: `run-in-docker.sh <64|32> <phpunit arguments>` from the repository root (isolated `redis:7.0.15-alpine` per call;
`maatify-rl-php64:local` linux/arm64 PHP 8.4.27 INT 8, `maatify-rl-php32:local` linux/386 PHP 8.4.27 INT 4, ext-redis 6.3.0).

Measured on the baseline (login policy, allowance 3 per account in 600 s, circuit tripped by a real outage, first probe moment):

| One public call | Units consumed (baseline) | Intended |
| --- | ---: | ---: |
| no conflict | 1 | 1 |
| one conflict, final answer fallback | 2 | 1 |
| three conflicts, final answer fallback | more than 3: the call itself was refused (`fallback_limit_exceeded`) | 1 |
| retries exhausted (concurrency block) | 32 | 0 |
| failed save, repeated in the process | 3 | 1 |
| save applied, reply lost | 2 | 1 |
| conflict, final answer a normal evaluation | 1 | 0 |

| Log (`raw/`) | Source | Result |
| --- | --- | --- |
| `original-64.log`, `original-32.log` | baseline `src/` | 17 tests, 11 failures on both widths |
| `previous-delivery.log` | the first delivery `2bcbdb58` (F265-01) | the two post-save cases fail on both widths: `HARD_BLOCK / FAIL_CLOSED` instead of the chosen `ALLOW / DEGRADED_MODE` |
| `fixed-64.log`, `fixed-32.log` | with the fix | 17 tests, 480 assertions, OK |
| `fixed-php32group-32.log` | with the fix, `--group php32`, PHP_INT_SIZE 4 | 809 tests, 43779 assertions, 64 skips |
| `related-64.log`, `related-32.log` | #258 and recovery suites (`AdmissionOutcomeDuringRecovery`, `RequestAtomicityBoundaries`, `RequestAtomicityRecoveryOutage`) | 33 tests, 5991 assertions, OK on both |
| `mutants.log` | removing the required charge: 14 failures; charging the final answer twice: 14; resolving the mode again for the charge (F265-01): 2 (both widths) | |
| `fixed-full-64.log` | whole suite on the local image | only `tests/Unit/ReleaseVerification` fails (the image has no `git`; same on the baseline) |

The charge is made from the answer the recovery evaluation chose: the mode that answer carries is used, and nothing is resolved or
read again after the recovery save. The post-save regression fails every Redis command that touches the circuit after a recovery save
was applied, for both return paths (the step saved with the request, and the step repeated on its own after a failed first save), and
asserts the answer, the single charge, the committed circuit and probe lease, the absence of further circuit reads and the signals.

Prerequisite: a recovery step that writes (an `OPEN` circuit past its interval, or a `HALF_OPEN` one probe-eligible) so that its save can
conflict or fail, and a policy with a bounded fallback configuration.
