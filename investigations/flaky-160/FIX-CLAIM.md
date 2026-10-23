# #160 T-CLAIM fix — test only: block duration 1 → 60 s

Assignment 2. Basis: phase 2 C-NAT 100,000 / 100,000 compatible, C-DET-1 20 / 20. `LIFECYCLE_CLAIM` judges `expiresAt > nowSeconds`, as the contract of a one-second block requires; the test assumed a one-second block cannot end between the block call and the claim call. No change under `src/`.

## Change

* `tests/Integration/Redis/RedisFullCapabilityStoreIntegrationTest.php::testGenerationBoundLifecycleIsAtomicAndClaimDoesNotConsumeEvidence`: the block duration argument of `blockWithPunishmentLifecycleTracking` is 60 instead of 1, with a one-line comment. The `DEL` → claim `true` → claim `false` path is unchanged.
* Two reproduction tests, injected `FixedClock` (commit `47eb45f`, both green on `main@2cf8565`, the red commit adds no failing test because the defect is in the test, not in `src/`): `testClaimAfterOneSecondBlockSucceedsOnceTheClaimClockPassesTheBlockExpiry` (1 s block, claim at T + 1 → `true`, contract) and `testClaimStaysRefusedWhileTheBlockOutlivesTheClaimClockStep` (60 s block, same step → `false`, recurrence).

## "Red" — the original test under T → T + 1 with an injected clock

`fix-claim/inject-clock.py` (the four inserted lines recorded in `fix-claim/inject-clock.diff`, generated with `git diff --no-index -U0` between the test at `47eb45f` and the injected copy: no context lines, so no whitespace-only context) gives the **original** test a `FixedClock` at `time()` for the store and moves it to T + 1 immediately before the first claim; nothing else changes. Local Redis 7.0.15 (CI compose image), real executor.

| tree | result | log |
|---|---|---|
| `47eb45f` (duration 1) + injection | **fails**: `Failed asserting that true is false`, first claim assertion (original L1097, L1101 with the injection's four added lines) | `fix-claim/logs/injected-before-fix.txt` |
| fix (duration 60) + injection | **passes** (12 assertions) | `fix-claim/logs/injected-after-fix.txt` |

## Measurement after the fix (assignment item 3)

`fix-claim/c_fix.php` is `phase2/harness/c.php` with the one argument changed 1 → 60 (`fix-claim/c_fix.diff`; the original harness is untouched). `php fix-claim/c_fix.php --root=<repo> --port=<redis> --arm=cdet --n=20` on the fixed tree; rows in `fix-claim/logs/cdet.rows.jsonl`, summary in `cdet.summary.json`. A trial is valid when the block applied and the block key's `PTTL > 0` before the claim (20 / 20 valid in every row).

| row | prediction | measured claim `true` |
|---|---|---|
| C-DET-1 (claim at T + 1) | 0 / 20 | **0 / 20** |
| C-DET-0 (control, all T) | 0 / 20 | **0 / 20** |
| C-DET-2 (T + 1 from READ on) | 0 / 20 | **0 / 20** (READ re-entry null 20 / 20) |

No prediction was contradicted. Not measured: the natural-rate flake rate of this test on CI (a 60-second block cannot end between the calls, so the 1-second-boundary mechanism is removed by construction, not by sample).

## Other checks, same tree

`RedisFullCapabilityStoreIntegrationTest` whole file on real Redis 118 tests, 3,748 assertions, green; PHPStan level max on the file clean; `php-cs-fixer check` 0 of 361 files.

The red-proof logs were regenerated once with the `-U0` diff's script (F-164-01): pre-fix tree + injection fails at L1101, fixed tree + injection passes (12 assertions); both logs replace the earlier ones.
