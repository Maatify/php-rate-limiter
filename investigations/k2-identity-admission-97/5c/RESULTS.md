# Stage 5-c results — the atomic activation of the admission layer (#97, step 5)

Tested tree: `400d7fa` (base `main@13870bb`; commits in order: the plan `c02a7c0`, commit 1 `d8a21b2`, commit 2 `6f0d7bd`, commit 3 `f32194b`, commit 4 `b2fcfc9`, commit 5 `e1eedae`, the Lead's process note `973f1dc`, commit 6 `deb1327`, commit 7 `f519859`, the records check `400d7fa`). This file and the logs under `5c/logs/` are the only later change. PHP 8.5.9, Redis 7.0.15 (`docker/redis-integration`) through `scripts/ci/run-with-redis-service.sh`; a fresh install for each mode (`rm -rf vendor composer.lock`, then `composer install`, or `composer update --prefer-lowest --prefer-stable`).

## Suites (full logs, not tails: `5c/logs/`)

| Run | Result |
| --- | --- |
| Complete suite, normal dependencies (PHPUnit 11.5.57, PHPStan 2.3.1), `COMPOSER_PROCESS_TIMEOUT=0 composer test -- --display-errors` | `OK (1937 tests, 25577 assertions)`, 18:55; no error, warning, risky or skipped test. Base `13870bb`: 1,892 tests (+45: builder goldens 16, builder activation 15, rotation 5, builder reruns 2, LT-26 2, records check 5) — `suite-normal.log` |
| Complete suite, lowest dependencies (PHPUnit 11.5.50, PHPStan 2.0.0) | `OK (1937 tests, 25435 assertions)`, 19:48; no error, warning, risky or skipped test — `suite-lowest.log`. The first lowest run on the same tree was ended at 37 % (732 of 1,937 tests, none failed) by an external `SIGTERM` (`Terminated: 15`; `run-with-redis-service.sh` only traps it), not by a test; the run was repeated on the same tree and only the complete log is kept |
| `composer test:core`, normal | `OK (1832 tests, 24423 assertions)`, 10:30 locally — `core-normal.log` |
| `composer test:integration`, normal | `OK (512 tests, 9714 assertions)`, 13:22 locally — `integration-normal.log` |
| PHPStan max, normal (2.3.1) and lowest (2.0.0), `--memory-limit=1G` | `[OK] No errors` in both — `phpstan-normal.log`, `phpstan-lowest.log` |
| Consumer Verification Harness (`scripts/ci/run-consumer-verification.sh`, two clean runs) | `PASS` and `PASS`, 5:24; the summary's `admission` entry: CVH-1 variant behind a closed fence `AAAAAASAAASSAASSSSSSSHHSSHHHSHHHHHHHHHHH` = `login_protection` preset; CVH-2 `SOFT_BLOCK` level 1 `source_capacity`; CVH-3, CVH-4, CVH-5 as registered; CVH-6 reporting Host `source_failure_cap`, dropping Host 24 admitted — `cvh.log` |
| `composer format:check` | clean (the advisories of `DTOComplianceTest` are of `main`) |
| CI on `400d7fa` (run 39507798602) | 16 / 16 jobs pass |

The 29 `REPORT` lines of the two complete runs are identical; they are the reported values quoted below.

### Timeouts (item of [#150 comment 6300269333](https://github.com/Maatify/php-rate-limiter/pull/150#issuecomment-6300269333); reported, nothing changed here)

| CI job on `400d7fa` | Time | Limit |
| --- | --- | --- |
| Test Suite (PHP 8.4) / (PHP 8.5) | 7:28 / 7:22 | 10 |
| Integration Test Suite | 9:14 | 15 |
| Lowest Supported Dependencies | 13:05 | 20 |
| Archive Replay Suite (PHP 8.4) / (PHP 8.5) | 4:50 / 4:26 | 25 |
| Consumer Verification Harness | 5:26 | 15 |

`test:core` grew by 41 tests (1,791 → 1,832) and the Test Suite jobs stay below the 7:57 measured before; no limit needs a change for this PR. The headroom of Test Suite (about 2.5 minutes) is the smallest; the executor recommends revisiting it with the next suite growth, not now.

## Conditions of the assignment

| Condition | Evidence |
| --- | --- |
| `src/` changes | 14 files: two added (`Config/LoginProtectionAdmissionPolicy.php`, `Service/AdmissionFenceControlInterface.php`), twelve modified (the builder line, `packageDefaults()`, the fence read and open, the engine and composite delegation, the DEC-021 → DEC-026 comments, the dormancy docblocks) |
| No DEC-025 number or `D` / `G` outside the factory | the added lines of `git diff 13870bb 400d7fa -- src` hold the thirteen values only inside `AdmissionBudgetsDTO::packageDefaults()`; the other matches are docblocks (the factory's own and the decision numbers in the builder's) |
| Existing test files changed | `tests/Unit/Admission/AdmissionRegistrationRulesTest.php` (LT-30, ruled), `tests/Support/Admission/EngineLab.php` (the additive `throughBuilder()`, PLAN §7.7, and amendment 1 of §7.8), `consumer-verification/verify.php` (the CVH scenarios, PLAN §7.6.5, and amendment 2 of §7.8). No test of `main` deleted |
| Archive untouched | `git diff --stat 13870bb 400d7fa -- investigations/k2-identity-admission-97/g1 … g8` is empty |
| DEC-021 and DEC-019 | changed only in `Status` and `Superseded By` (the records check below). Their status lines keep the historical sentence: a reading of the DEC-009 precedent that keeps the history, accepted by the Lead |

## Gates and the mode each ran in (LT-33 condition)

`builder` = the runtime composed by `RateLimiterBuilder` (`EngineLab::throughBuilder()` or the public composition); `direct` = the engine built directly (`EngineLab`, the 5-b harness); `store` = the store alone.

| Gate | Mode | Result |
| --- | --- | --- |
| S0-PARITY, builder goldens (4 policies × 2 scenarios × with / without binding key; recorded on `13870bb`) | builder | pass, unchanged |
| S0-PARITY, 5-b3a goldens (`FamilyS0ParityTest`) and 5-b3b engine goldens | direct | pass, unchanged |
| S0-PARITY, the variant behind a closed fence = the `login_protection` golden decisions (control: open fence differs) | builder | pass |
| DORMANT (new meaning): the package constants field by field; no runtime without an opted policy; no public budget input; detector = builder mutant on the anchor `AdmissionBudgetsDTO::packageDefaults()` | builder | pass; detector fires |
| REG through the builder (store capability, binding key) and the 5-b1 rules | builder / direct | pass |
| The variant (name, capabilities, mode, group, inherited values) | builder | pass |
| The fence method; LT-27 (3) fence across a rotation (detector: the 5-b3b current-only read) | builder | pass; detector fires |
| LT-27 (1) rotation capacity (detector: a build without the previous generation) and (2) set and bucket restart (reported) | builder | `afterFirstRotation` 0, `afterSecondRotation` 24 (reported); `failingAccountsAfterRotation` 4, `bucketTakesAfterRotation` 4 (reported, now in `KEY_STRATEGY.md` §4.8); detector re-issues 24 at the first rotation |
| RP-G7 / RP-G6, the 210 G7 `PX4` rows | builder and direct | pass on both |
| RP-G4 / RP-G5 fixtures on `PX4` (BRU24 compared without its recount) | builder and direct | pass on both |
| B1 recount of BRU24, NP-IN | direct | pass |
| RP-G4 / RP-G5 `FULL2` streams (no D8), NM-10, NM-5, gate P, RP-GLOB, CGN-B | direct | pass (as at 5-b3b) |
| RP-G7 `NOUA2` arm, every ablation and mutant detector | direct | pass; detectors fire |
| SC-1 | builder and direct | pass |
| SC-2 … SC-6 | direct | pass |
| LANE (verified credential from an exhausted source; tampered → U) | builder and direct | pass |
| Permit (missing, duplicate) | builder and direct | pass |
| Rollback (a build without the variant ignores the admission state) | builder and direct (M6) | pass |
| CYCLES / LIFECYCLE, FAIL, NM-7 mixed fleet | direct | pass |
| NM-6 / NM-7 store level | store (real Redis) | pass (as at 5-b2) |
| LT-26 IPv6 `/64` equivalence (`BRD`, `X2`; detector: a `/48` source) | direct (`FULL2` lab) | pass; detector fires |
| Records (verbatim) | — | pass (below) |
| CVH-1 … CVH-6 | public composition, real Redis | pass |

## Records gate (mechanical; `tests/Unit/Tooling/DecisionRecordsVerbatimTest.php`)

```
REPORT records-verbatim-dec026 {"source":"DEC-021_KNOWN_DEVICE_VERIFIER_BOUND.md","target":"DEC-026_KNOWN_DEVICE_VERIFIER_BOUND_FOR_THE_ADMISSION_LAYER.md","bytesBySection":{"Decision Authority / Deciders":1423,"Context":1029,"Decision":3554,"Rationale":1995,"Consequences":4419,"Alternatives Not Adopted":1168}}
REPORT records-verbatim-dec027 {"source":"DEC-019_INTERIM_SECURITY_FIRST_CORRELATION_POSTURE.md","target":"DEC-027_RETAINED_CORRELATION_POSTURE_FOR_POLICIES_THAT_DO_NOT_OPT_INTO_ADMISSION.md","bytesBySection":{"Decision Authority":612,"Scope / Concern":419,"Context":1958,"Decision":2594,"Rationale":345,"Consequences":652,"Relationship to Other Decisions":271}}
REPORT records-verbatim-appendix-a {"paragraphs":11,"sha256":"5642bd4dfa6df3d6d5d6fec6821a913e083c68a0d9a4d41404e6ce5bb2607cfd"}
```

- Every section DEC-026 carries from DEC-021 (six) and every section DEC-027 carries from DEC-019 (seven) occurs in the new record byte for byte. DEC-026 carries Decision 3 whole: Delta 1 narrows it by its own statement and edits no carried byte, so the exception the plan allowed for that clause is not used.
- DEC-021 and DEC-019 without `Status` and `Superseded By` hash to the values computed at `13870bb` (`3daedc05…0c6b12`, `e72d61e5…d03907`).
- The Appendix A block of the contract hashes to its `13870bb` value and each of its eleven paragraphs occurs in DEC-026.
- Detector: one changed byte in a carried section is not found.

## Index (LT-29)

`DECISIONS_INDEX.md`: new rows DEC-026, DEC-027, DEC-028 (`ACTIVE`); DEC-019 and DEC-021 `SUPERSEDED` with `Superseded By` and a historical canonical-owner column; DEC-022 `ACTIVE` with its activation scope and canonical contracts; the canonical-owner column of DEC-023, DEC-024 and DEC-025 and one dated clause in each of their descriptions (first activation, DEC-026, DEC-028). The records' own texts of DEC-023 / 024 / 025 are unchanged.

## Corrections and amendments declared

- PLAN §7.7: the LT-26 mapping covers every dumped source (the `X2` sources are study labels); LT-33 and LT-34 raised, and **commit 5 proceeded before the LT-33/LT-34 rulings; both were later accepted unchanged**.
- PLAN §7.8: the three amendments after the tests (an always-true check removed from `EngineLab`; the CVH admission compositions on the system clock; `fields()` typed for PHPStan 2.0.0).
- `KEY_STRATEGY.md` §4.8: "a binding-secret rotation restarts them" corrected to "a key-secret rotation": the scope keys are derived from the key secret (5-b3b D-KEY), which is what LT-27 rotated.
- The contract and ILD corrections of commit 7 are dated notes next to the text they correct.

## Open

None of this PR. **N-5c-01** is issue [#153](https://github.com/Maatify/php-rate-limiter/issues/153) (bounded correlation snapshot rejects a Redis-time expiry under caller clock skew; existing behaviour of `main`, S0 included; outside 5-c, for the Owner after it).

## Not covered here (named)

- An OTP opt-in variant (never measured), default presets opting in, API protection, DEC-025's open items (5.4, P4, other `C` / `T`), option B (`pre` contract), a library credential issuer, K4P rules B and C, reading B of the K5 previous material, an observe-only churn / dilution pass, failover bound `L`, clustered Redis — PLAN §5.
- Closing #97, #100, #106: they stay open for the policies that do not opt in (DEC-027).
