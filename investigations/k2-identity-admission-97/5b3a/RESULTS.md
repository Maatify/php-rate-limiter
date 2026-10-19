# Stage 5-b3a results — family split in the pipeline and the K5 material

Tested tree: `7f9353f` (HEAD of the implementation; this file is the only later change). PHP 8.5.9 on the machine of the run, Redis 7.0.15 (`docker/redis-integration`) via `scripts/ci/run-with-redis-service.sh`. Every number below is a measured value of this run; every archive value is quoted from `g5/identity_layer_v2/expected/` and was **read by the tests at run time**, not re-typed.

## Suites

| Run | Result |
| --- | --- |
| Full suite, normal dependencies, Redis, `COMPOSER_PROCESS_TIMEOUT=0 composer test` | `OK (1641 tests, 20057 assertions)`, 6 min 27 s |
| Full suite, `composer update --prefer-lowest --prefer-stable` (phpunit 11.5.50, phpstan 2.0.0), Redis | `OK (1641 tests, 20439 assertions)`, 6 min 37 s |
| New tests only (`--filter Family`) | `OK (91 tests, 243 assertions)`, about 5 s |
| PHPStan max, normal dependencies | `[OK] No errors` |
| PHPStan max, lowest (2.0.0) | `[OK] No errors` with `--memory-limit=1G`. With the default 128 M a parallel worker is killed by the memory limit; the same crash reproduces on the **base** `1c23b61` with the same lowest vendor, so it is the tool at that version on this machine, not this change |
| `composer format:check` (php-cs-fixer + PER-CS 3.1 delta) | clean |

Baseline at `1c23b61`: 1550 tests (5-b2 results); this PR adds 91, total 1641.

## Gates (each detector fired)

| Gate | Result |
| --- | --- |
| **S0-PARITY (1)** existing suites, no edit to any existing test | pass; `git diff --diff-filter=M --name-only 1c23b61 -- tests` is empty (every test change is an added file). The 5-b1 REG / shapes / codec / S0-parity classes (113 tests) and the DEC-021 mixed-version and operational-reader system tests (58 tests) pass unchanged |
| **S0-PARITY (2)** lane omitted or `null` = today | pass: decision string and sha-256 of the normalized state equal the goldens recorded on the unmodified pipeline of `1c23b61` (Login `19bfcf29…679c`, OTP `568dbec2…cacab`, 160 steps each, 3 decision classes present) |
| **S0-PARITY (3)** an instance id on the device changes nothing without a lane | pass for Login, OTP and the K4-only Login fixture policy (identical decisions and state with `clientInstanceId` and without) |
| S0-PARITY detector | a build whose `k5Material` seam ignores the lane breaks both the id-ignored check and the golden |
| **REG**, **DORMANT** | the 5-b1 tests pass unchanged. `git diff --stat 1c23b61 -- src/Service/RateLimiterEngine.php src/Builder/RateLimiterBuilder.php src/Repository composer.json composer.lock src/Service/FingerprintHasher.php src/Service/DeviceIdentityResolver.php` is empty: the engine, the builder, the stores and the resolver are byte-identical, so no lane is passed in production and no policy can opt in |
| **RP-G5 (pipeline part)** | pass: all 28 NOUA2 rows of `g5v2_pipeline.G5V2.jsonl` (STABLE-600/300/60, KDL, UAC12, UAX24, AE, MIXPRES, ROT-300, UAROT-300, NOID-STABLE-300/KDL/UAC12/AE, on P and on U) reproduced field for field, including key prefixes, allowance counts and block levels. STABLE-600/300/60 on P = 45 / 44 / 45; UAC12 = KDL string on both lanes, one K5 key, one allowance counter, UAX24 reach = control (45 on P, 77 on U) |
| RP-G5 detectors | no allowance: P STABLE reach 128 / 83 / 76, equal to the archived `D-NOALW` rows; no K5: KDL `AAAAAAAAAAAA` on both lanes, equal to `D-NOK5`; no overflow: equal to `D-NOOVF` rows; no device watcher: equal to `D-NOAE` rows (P differs in `secondDevice`, U in the decision string); K5 on the fingerprint: UAC12 string equals the archived NOID-UAC12 string and differs from KDL on both lanes; no `_p` suffix: the P allowance counter leaves its own key |
| **GUARDS** | pass: (G1) a source sweep that blocks without a lane (`AAHHHHHH`) is `AAAAAAAA` on U and on P; (G2) with a lane, churn, dilution and spray observations are not written: 8 distinct-set observations on U for 4 requests (the two device-cap ones per request), 0 on P, more without a lane; (G3) flood bookkeeping stays on U and is absent on P, the device-cap observation is absent on P; (G3b) a K1 threshold refuses without a lane (`S`) and not on P or U; (G3c) an old collective block is honoured on U (`H`) and not read on P (`A`); (G4) U never persists an account block nor adds a budget candidate over 25 unknown-device failures, the K4 score is still written; (G5) K4P is its own key (rule A, no scope); an un-opted policy given a lane is refused with `RateLimiterException` |
| GUARDS detectors | `appliesCollectiveCorrelation` true: a block appears on P and the U observation count rises above 8; `isProvenLane` false: device-cap and flood return on P; `ignoresCollectiveKeyType` false: the K1 threshold refuses on P and the old block blocks P; `keepsDeviceScopeOnly` false: an account hard block persists on U; `suppressesBudget` false: a budget `SOFT_BLOCK` appears on U with no `HARD_BLOCK` (isolated from the account rule) |
| **CYCLES** | pass: K4 (no lane) and K4P, K5 (U) and K5p (P) are different keys in the cycle log and the second family's first block is cycle 1 and a new cycle |
| CYCLES detectors | `k4KeyType` always `k4`: same key, second block is cycle 2; `k5KeyType` always `k5`: same key, cycle 2 |
| **LIFECYCLE** | pass: K4P hard block published through the unchanged `PunishmentLifecycleStoreInterface` (generation present, one cycle entry on the K4P key, evidence after expiry); the evidence is served on P only and a `U` request and a laneless request do not carry it; `claimPostPunishmentReentry` with lane `U` or none fails and with lane `P` succeeds once; `recordSuccess` leaves generation and evidence id unchanged. Point 4 (a non-re-entry policy is refused a claim) is in the engine, untouched; `PostPunishmentReentryLifecycleTest` passes unchanged |
| LIFECYCLE detector | `k4KeyType` always `k4`: the U request carries the evidence |
| **READER-PARITY** | pass on all 8 cases (U/P × id/no-id × one secret/rotated): the reader with the lane reads K5 score 10, micro-cap 5, account budget 2 (and the previous-generation flags exactly when rotated); the P budget cooldown is read on its `_p` key; without the lane the lane's state is not read (U without an id is today's key set, so the laneless read equals it, asserted) |
| READER detectors | reader on the fingerprint (U, id): misses K5; reader without a previous material (P, id, rotated): misses the rotated state; reader on `k4` (P): misses the K4P budget; reader on `k5` (P): misses K5p; reader without the `_p` suffix: misses the P cooldown |
| Pipeline rotation | pass: after a secret rotation the next K5 failure continues the score (`H` on U and P); detector: a pipeline without a previous material restarts it (not `H`) |

## Not covered here (named, by plan)

5-b3b: `AdmissionRuntime`, lane resolution, the reservation call and its refusal shape, the permit, outcome hooks, the builder line, the engine passing a lane, the composite reader and public reader interface asking for a lane, `DECISION_MATRIX.md`, `POLICIES.md`, `USAGE_GUIDE.md`, `FAILURE_SEMANTICS.md`, DEC-026. Gates RP-G4, RP-G6, RP-G7, RP-GLOB, FAIL, SC-1…6, LANE, NM-5, NM-7 (mixed fleet, rollback), NM-10, and the RP-G5 parts `UAX-NP`, `D-IDMINT`, `BRU24`, `FORGE`, `MINT`, `STEAL`, `NP-IN`, `R:STEAL`, `R:STREAMS` (they need `N_P`, the admission counters, a credential or the password-change binding).

## Open items for the Lead

1. **LT-9 (PLAN §2).** The K5 "previous" key under M4 / M5: reading A (same material, previous secret) is built; reading B (the fingerprint material as the previous generation during a transition window) is not. M4 says both "a one-time K5 restart" and "reads the old material as the previous derivation", and nothing in S1–S9 tells the pipeline that a window is open or how long it is. `previousK5Material()` is the single seam where B would go.
2. **LT-10a.** Contract L107 says the correlation observations "the study kept" stay. The study's `if (false && …)` skipped the whole call of churn, dilution and spray, so those observations are not written for an opted policy; production reproduces that (it is what the archive numbers were measured on). The observations that remain are the K2/K1 scores, the device-cap observations (U), the distributed-account observation (U, candidate dropped) and flood bookkeeping (U).
3. **LT-10b.** M3 says old K1/K2/K3 blocks are honoured read-only for opted policies. The study and this PR honour them on U and do not read them on P.
4. **LT-10c.** L111 lists "distributed-account snapshots" among the fingerprint consumers, but the snapshot member is the K5 enforcement key itself (`buildDistributedDeviceObservation`), so under the study's K5 material it follows the K5 key. Inert in the lanes (skipped on P, candidate dropped on U).
5. **New shape not in S1–S9:** `Enum\AdmissionLaneEnum` (`@internal`), the typed form of the contract's "lane" call parameter.
6. **A lane is refused for a policy without the admission capability** in `process()`; `claimPostPunishmentReentry()` receives only a policy name, so the lane is trusted there.
7. Amendments after the tests were committed are in `PLAN.md` §9 (six items, each with its reason; no gate or detector weakened).
