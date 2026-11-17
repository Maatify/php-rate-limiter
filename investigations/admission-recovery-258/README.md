# #258 — outcome claim of an admission policy while the circuit recovers

Reviewed baseline `main@7014c796274f30495372a430403a164fd55296ea` (tree `ed972d4115226a787680e289b825b4c41f0cb821`).

Test: `tests/Integration/Redis/AdmissionOutcomeDuringRecoveryRedisTest.php` (17 tests, groups `integration`, `admission-recovery-258`, `php32`).
Run: `run-in-docker.sh <64|32> <phpunit arguments>` from the repository root (isolated `redis:7.0.15-alpine` per call;
`maatify-rl-php64:local` linux/arm64 PHP 8.4.27 INT 8, `maatify-rl-php32:local` linux/386 PHP 8.4.27 INT 4, ext-redis 6.3.0).

| Log (`raw/`) | Source | Result |
| --- | --- | --- |
| `original-64.log`, `original-32.log` | unmodified `src/` of the baseline | 17 tests, **5 failures** (all recovering-circuit cases) on both widths |
| `fixed-64.log`, `fixed-32.log` | with the fix | 17 tests, 4106 assertions, OK |
| `fixed-php32group-32.log` | with the fix, `--group php32` on PHP_INT_SIZE 4 | 791 tests, 41961 assertions, 64 skips |
| `fixed-full-64.log` | with the fix, whole suite on the local image | the only failures are `tests/Unit/ReleaseVerification` (the local image has no `git`) |
| `original-releaseverification-64.log` | unmodified `src/` | the same 100 errors and 4 failures: environment, not the change |

Prerequisites of the reproduction: an admission policy with an open fence; a permit issued while the circuit is closed; the circuit
`HALF_OPEN` and probe-eligible within the 30 s permit deadline. On one monotonic clock the documented timings (300 s open, 120 s
healthy) cannot elapse inside that deadline, so the test reaches the state with a second worker whose Host clock is behind.
