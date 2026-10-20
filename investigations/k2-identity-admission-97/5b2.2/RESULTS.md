# Stage 5-b2.2 results — settlement is optional per scope and off by default

Tested tree: `13fe07e` (the implementation, the documents and the LT-24 edit; this file and the two logs are the only later change). PHP 8.5.9, Redis 7.0.15 (`docker/redis-integration`) via `scripts/ci/run-with-redis-service.sh`.

## Suites (full logs, not tails: `5b2.2/logs/suite-normal.log`, `5b2.2/logs/suite-lowest.log`, run with `composer test -- --display-errors`, `COMPOSER_PROCESS_TIMEOUT=0`)

| Run | Result |
| --- | --- |
| Full suite, normal dependencies (phpunit 11.5.57, PHPStan 2.3.1), Redis | `OK (1703 tests, 23749 assertions)`, 9 min 7 s (baseline at `7057abc`: 1674; +29 new) |
| Full suite, `composer update --prefer-lowest --prefer-stable` (phpunit 11.5.50, PHPStan 2.0.0), Redis | `OK (1703 tests, 23201 assertions)`, 9 min 2 s |
| PHPStan max, normal and lowest | `[OK] No errors` (`--memory-limit=1G`) |
| `composer format:check` | clean |

## Gates (each detector fired)

| Gate | Result |
| --- | --- |
| **OFF-PARITY** | pass. Without `settlesOnExpiry` the production operations equal the operations as merged at `d6aea4d` (`MergedAdmissionOperations`, verbatim): the six SETTLE scenarios (built without the flag, `SettlementScenariosOff`, a verbatim copy of the 5-b2.1 builder) and five seeded sequences of 300 mixed operations each (counters with a previous generation, a device set, two failure sets with random `successKeepsSettled`, a bucket, permits with deadlines, claims, a wrong-type set injected and removed) give the same result and the same full key dump (type, content, TTL class) **at every step**. The sequences admit, refuse, claim and meet the malformed state |
| OFF-PARITY detector | a mutation of the production Lua that ignores the flag (`s.settles = true`) diverges from `d6aea4d` |
| Default behaviour | pass: no `adm:p:` key is ever created; a permit whose deadline lies beyond the set lifetime is accepted (the LT-S3 guard applies only with the flag); a dropped outcome never counts, whatever the clock; `successKeepsSettled` has no effect without the flag |
| **ON-PARITY** | pass: with the flag the results of 5-b2.1: its whole settlement suite (SETTLE-1…4, NM-6 on the changed operations, NM-7 concurrency 16 / 64, the four mutation detectors) passes **unmodified** after the LT-24 edit, and a new class repeats SETTLE-1…4 on both stores |
| Reference parity | the contract fixtures give identical results on Redis and the reference store in both modes |
| 5-b2 parity | every 5-b2 test passes unmodified |
| Shape | the DTO unit test of the new field (default false, `SET_SIZE` only, with `successKeepsSettled`) passes; the 5-b2.1 DTO test is unchanged |

## Conditions of the ruling

**Existing files modified under `tests/`** (`git diff --diff-filter=M --name-only 7057abc -- tests` lists exactly these three):

1. `tests/Support/Admission/AdmissionInMemoryReferenceStore.php` — the reference store follows the same table (the assignment requires it): the pending write, the settled size, the success rule and the lifetime guard apply only to a scope with `settlesOnExpiry`.
2. `tests/Support/Admission/SettlementScenarios.php` — LT-24, one argument:
```diff
-        return new AdmissionScopeDTO('fail_src', AdmissionScopeKindEnum::SET_SIZE, $key, $capacity, self::TTL, null, $account, null, $keepsSettled);
+        return new AdmissionScopeDTO('fail_src', AdmissionScopeKindEnum::SET_SIZE, $key, $capacity, self::TTL, null, $account, null, $keepsSettled, settlesOnExpiry: true);
```
3. `tests/Support/Admission/settle-race-worker.php` — LT-24, one argument:
```diff
-$scope = new AdmissionScopeDTO('fail_src', AdmissionScopeKindEnum::SET_SIZE, $spec['key'], $spec['capacity'], 1200, null, 'new-' . $index);
+$scope = new AdmissionScopeDTO('fail_src', AdmissionScopeKindEnum::SET_SIZE, $spec['key'], $spec['capacity'], 1200, null, 'new-' . $index, settlesOnExpiry: true);
```
No test class, assertion or scenario step changed.

**`MergedAdmissionOperations` against `d6aea4d`.** sha-256 of the five Lua blocks of `RedisAdmissionOperations` as merged at `d6aea4d` and of `tests/Support/Admission/MergedAdmissionOperations.php` (the heredoc bodies concatenated): both `3614fca368bf7bcf6b06d3d53a8d29791302ae5f497053e636cedba88baa2a11`.

**`src/`**: two files changed against `7057abc` (`src/DTO/AdmissionScopeDTO.php`, `src/Repository/Redis/RedisAdmissionOperations.php`); no constant for `D`, `G` or a budget.

## Amendments after the tests were committed (`red` commit of this PR)

1. The OFF-PARITY and default-behaviour tests first imported `SettlementScenarios` as their flag-less builder and `SettlementScenariosOn` (a flagged copy) as the ON builder. After the LT-24 ruling `SettlementScenarios` is the flagged one, so the imports changed: the flag-less builder is the new `SettlementScenariosOff` (a verbatim copy of the 5-b2.1 builder at `7057abc`) and the ON builder is `SettlementScenarios` itself; the redundant `SettlementScenariosOn` was deleted. No assertion changed.
2. `OptInParityRunner`: the clock steps were changed (mostly 0–15 s, one step in ten up to 400 s) so that permits are claimed before and after their deadlines, a forced reservation at step 125 meets the injected wrong-type set, and the per-seed non-vacuity assertion was loosened from `claim:accepted` to `claim:` (seed 42 has no accepted claim; the aggregate over the five seeds requires every claim kind: see the correction F149-03). Typing only afterwards (`RedisAdmissionOperations|MergedAdmissionOperations`). No parity assertion changed.

## Claim kinds exercised by the OFF-PARITY sequences (per seed, 300 steps, production operations)

| Seed | accepted | late | unknown | duplicate | conflict | rejected |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | 1 | 6 | 51 | 1 | 1 | 0 |
| 7 | 2 | 6 | 47 | 0 | 1 | 0 |
| 42 | 0 | 7 | 60 | 0 | 0 | 0 |
| 2026 | 2 | 4 | 45 | 1 | 0 | 0 |
| 90210 | 2 | 6 | 43 | 1 | 1 | 0 |
| **all five** | **7** | **29** | **246** | **3** | **3** | **0** |

Seed 42 has no accepted claim, which is why the per-seed check is `claim:`; the aggregate test (added in the correction commit) requires accepted, late, unknown, duplicate and conflict at least once over the five seeds. `rejected` is not exercised by these sequences (the permit fixtures always claim with the admission's own lane, subject and operation); it is covered by the 5-b2 PERMIT gate.

## Documents

`RUNTIME_IMPLEMENTATION_CONTRACT.md` §2.4 and §3 row (b) (optional per scope, the default is the tombstone, NM-10 NO-GO as a store mechanism by reference to #147), `KEY_STRATEGY.md` §4.8 (the `p` family is written only when a scope settles), a second dated note in `SECURITY_FEASIBILITY.md` §2.3, `CHANGELOG.md`, `RATE_LIMITER_PACKAGE_REFERENCE.md`, `llms.txt`. The row "Effect on legitimate users" of §3 (b) already carries the 5-b2.1 text on `main@7057abc` ("…and is implemented in the store (5-b2.1, §2.4 …)"); this PR changes that row from the 5-b2.1 wording to the 5-b2.2 wording (an opt-in of the store, off by default).

## Corrections after review (F149-01, F149-02, F149-03)

1. **F149-01:** trailing whitespace removed from the two logs (`suite-normal.log` 10 lines, `suite-lowest.log` 11 lines; nothing else changed).
2. **F149-02:** the incorrect sentence about §3 row (b) under "Documents" is replaced (above).
3. **F149-03:** the relaxation of `claim:accepted` to `claim:` in `testOffParityASeededSequence…` (amendment 2) is kept per seed and compensated by `testOffParityTheFiveSeedsTogetherPractiseEveryClaimKind` and the table above. Amendment 2 is amended to say so: the per-seed check was loosened because seed 42 has no accepted claim, and the aggregate test restores the guarantee.

## Open items

1. After the merge, `#147` is re-founded on this PR and its plan is amended (the Owner's decision, items 1–6): the runtime passes `F` and D8 without the flag; NM-10 and NM-5 are re-registered; `UAC` leaves A1 as a declared cost; the USAGE_GUIDE duty is stated.
2. The runtime of `#147` sends the D8 scope on a success with `successKeepsSettled = true`; under the default that flag is inert and the store would `SREM` a settled D8 failure, so the amended runtime must not send the D8 scope on a success when the flag is off (the `d6aea4d` contract). Stated here for the amendment.
