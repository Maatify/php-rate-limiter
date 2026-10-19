# Stage 5-b2.1 results — settlement of expired permits in the admission store

Tested tree: `c6f1327` (implementation and documents; this file is the only later change). PHP 8.5.9, Redis 7.0.15 (`docker/redis-integration`) through `scripts/ci/run-with-redis-service.sh`.

## Suites

| Run | Result |
| --- | --- |
| Full suite, normal dependencies (PHPStan 2.3.1), Redis, `COMPOSER_PROCESS_TIMEOUT=0 composer test` | `OK (1674 tests, 20452 assertions)`, 7 min 39 s (baseline at `d6aea4d`: 1641; +33 are the settlement tests) |
| Full suite, `composer update --prefer-lowest --prefer-stable` (phpunit 11.5.50, phpstan 2.0.0), Redis | first run: `Tests: 1674, Errors: 1` (the failing test was **not identified**: the log was cut to its tail); the identical run repeated: `OK (1674 tests, 20346 assertions)`; the settlement group alone, three more times: `OK (33 tests, 53 assertions)` each. The single error is not reproduced and not explained |
| PHPStan max, normal and lowest | `[OK] No errors` (`--memory-limit=1G`, as in 5-b3a: the default 128 M kills a parallel worker) |
| `composer format:check` | clean |

## Gates (each detector fired)

| Gate | Result |
| --- | --- |
| SETTLE-1 exact bound | pass on Redis and on the reference store: at `deadline − 1` a new account is admitted, at `deadline` it is refused, the member itself is still admitted. Detectors: the operations as merged at `d6aea4d` (`MergedAdmissionOperations`, verbatim copy) fail it (nothing is counted at the deadline); an exclusive bound (`'(' .. now`, a mutation of the production source) fails it |
| SETTLE-2 | pass: success before the deadline leaves no membership; a dropped outcome counts from the deadline; an explicit failure counts at once; the net rule (no flag) removes a settled failure; with `successKeepsSettled` a success keeps a settled failure and cancels a pending member. Detector: ignoring the flag (mutation) breaks it |
| SETTLE-3 once, late, due survives | pass: two later reservations (one with a second permit) and a late claim (`late`) add no second count (size stays 2 of cap 3, 3 only after a third explicit failure); a success at `now ≥ deadline` does not remove the due member (Lead addition 2). Detectors: a success that removes a due member (mutation) and a per-reservation member (`s.member .. nowStr`, mutation) both fail |
| SETTLE-4 | pass: a refused reservation and a late claim after the deadline leave the whole dump unchanged, counters and bucket included; counters and bucket stay spent. Detector: a read that prunes the pending member (mutation) changes the dump and is seen |
| NM-6 on the changed operations | pass: a refused reservation writes nothing (`p` key included) and refreshes no TTL; the `p` key lives for the scope lifetime and a second pending member does not renew it (Lead addition 1); a `p` key of the wrong type or without an expiry is a `RateLimiterException` from the reservation and from both outcomes with the dump unchanged; a permit deadline beyond the set lifetime is a `RateLimiterException` before any write (LT-S3). The 5-b2 NM-6 fixtures run unmodified and pass |
| NM-7 concurrency | pass: 16 and 64 processes, 3 rounds, cap 4 with one settled failure: exactly `3/3/3` admitted at both levels, counted size ≤ 4. Detector: the non-atomic control (count, then write) exceeds 3 in at least one round |
| 5-b2 parity | every 5-b2 test passes. `git diff --diff-filter=M --name-only d6aea4d -- tests` lists **one** file, `tests/Support/Admission/AdmissionInMemoryReferenceStore.php`: the assignment requires the reference store (the executable specification) to follow the same change. No test class, no fixture and no NM-6 / NM-7 file of 5-b2 was edited |
| No constants | the diff of `src/` contains no `D`, `G`, budget or window constant (grep of the added lines for 30, 600, 1800, 86400: empty); all values arrive as request parameters |

## Amendments after the tests were committed (`283c6b4`)

1. `AdmissionSettlementNm7RedisTest`: `max($all)` → `max([...$all, 0])`, and `AdmissionScopeSettlementFlagTest`: `assertTrue(true)` → `addToAssertionCount(1)`. Type and static-analysis only (PHPStan max), no assertion changed.
2. Nothing else in the tests changed after the red commit; the red run (12 contract failures on both stores, 5 unit / NM-7 failures, 10 errors from the not-yet-existing `pendingKey()` and anchors) is the one of `283c6b4`.

## Open items

1. **Net rule with two admissions.** One pending member per account and source: a success claimed for either admission removes it on the U door (the existing net rule), so a dropped outcome of the other admission is not counted. Stated in the plan; not a gate.
2. **The unidentified lowest-dependencies error** above.
3. **Pending scan.** The set-size gate scans the due members of one pending key; it is bounded by the admissions of one source window (they are counter-gated), not by a constant in the script.
4. After merge: the PASS conditions of NM-10 and NM-5 are registered in the 5-b3b plan on this mechanism and that plan is frozen (assignment §6).
