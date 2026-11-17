# #258 — outcome claim of an admission policy while the circuit recovers

Reviewed baseline `main@7014c796274f30495372a430403a164fd55296ea` (tree `ed972d4115226a787680e289b825b4c41f0cb821`).

Test: `tests/Integration/Redis/AdmissionOutcomeDuringRecoveryRedisTest.php` (18 tests, groups `integration`, `admission-recovery-258`, `php32`).
Run: `run-in-docker.sh <64|32> <phpunit arguments>` from the repository root (isolated `redis:7.0.15-alpine` per call;
`maatify-rl-php64:local` linux/arm64 PHP 8.4.27 INT 8, `maatify-rl-php32:local` linux/386 PHP 8.4.27 INT 4, ext-redis 6.3.0).

| Log (`raw/`) | Source | Result |
| --- | --- | --- |
| `original-64.log`, `original-32.log` | `src/` of the baseline `main@7014c796` | 18 tests, **6 failures** (the recovering-circuit cases) on both widths |
| `fixed-64.log`, `fixed-32.log` | with the fix | 18 tests, 5444 assertions, OK |
| `fixed-php32group-32.log` | with the fix, `--group php32` on PHP_INT_SIZE 4 | 792 tests, 43299 assertions, 64 skips |
| `mutants.log` | three mutants of `RateLimiterEngine::limitWithAdmission()`, both widths | no memo of the accepted claim: 2 failures; score skipped on the re-evaluation: 2; score pipeline run twice: 4 |
| `fixed-full-64.log`, `original-releaseverification-64.log` | first round (head `2f2fc4f1`), whole suite on the local image | the only failures are `tests/Unit/ReleaseVerification` (the image has no `git`), identical on the baseline |

The conflict test asserts the returned answer and the exact stored score and budget fields of the account (two score hashes, 5 and
the generation-bound 3, and a budget count of 1) after one recalculation, for a conflict caused by another request that closes the
circuit and for a conflict on an unrelated key the request read (the abandoned attempt is checked to have saved nothing, and the
final evaluation saves the recovery step with its score).

Prerequisites of the reproduction: an admission policy with an open fence; a permit issued while the circuit is closed; the circuit
`HALF_OPEN` and probe-eligible within the 30 s permit deadline. On one monotonic clock the documented timings (300 s open, 120 s
healthy) cannot elapse inside that deadline, so the test reaches the state with a second worker whose Host clock is behind.
