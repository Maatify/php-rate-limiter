# Stage 5-b3b results (partial) — AdmissionRuntime, lanes, permit, outcome hooks, documents

Tested tree: `9a417f0` (this file is the only later change). PHP 8.5.9, Redis 7.0.15 (`docker/redis-integration`). **Partial:** commit 2b-2 (the fixture gates, LT-23) and four contract gates are not written yet (see "Not covered"). Nothing here is a verdict.

## Suites

| Run | Result |
| --- | --- |
| Full suite, normal dependencies (PHPStan 2.3.1), Redis, `COMPOSER_PROCESS_TIMEOUT=0 composer test` | `Tests: 1748, Assertions: 20735, Failures: 10` — the 10 are the registered-PASS tests of NM-10 and NM-5 listed below; every other test passes (baseline at `7057abc`: 1674; +74) |
| Full suite, `--prefer-lowest --prefer-stable` (phpunit 11.5.50, PHPStan 2.0.0), Redis | `Tests: 1748, Assertions: 20731, Failures: 10` — the same 10 |
| PHPStan max, normal and lowest | `[OK] No errors` (`--memory-limit=1G`) |
| `composer format:check` | clean |

## Conditions of the rulings

| Condition | Evidence |
| --- | --- |
| LT-22 (1): only lines 52 and 95 of `AdmissionRegistrationRulesTest` change, to a helper in the same file | `git diff --diff-filter=M --name-only 7057abc -- tests` lists that one file. Whole diff: `new AdmissionRuntime()` → `self::runtime()` twice, and the added private static `runtime()` building a real runtime (reference store, `AdmissionCredentialCodec('binding-secret')`, `EngineLab::budgets()`, `FixedClock`, `'key-secret'`, `'prod'`). No assertion, exception message or data set changed. 16 tests pass |
| LT-22 (2): `assertAdmissionCapability` unchanged | sha-256 of its text `5926175faa72b708729062f59e0719c1f060dd079ae53fe809b7c9682e15dcc3` at `7057abc` and at HEAD |
| LT-22 (3): dormancy tests unchanged and passing | `testEngineWithoutAdmissionRuntimeRejectsAValidAdmissionPolicy` and the builder tests pass unmodified |
| LT-20 (1): the archive is not touched | `git diff --stat 7057abc -- investigations/k2-identity-admission-97` outside `5b3b/` is empty |
| LT-20 (3): sha-256 and row counts of the dump | `streams.jsonl` 91 lines `932767ad…66ab`; `bru.jsonl` 2 lines `c27486c4…7204`; `constants.json` 1 line `dfcc3d7f…ffa0`. The 91 tuples equal the tuples of the G5 v2 archive; for every one of the 57 tuples of the G4 v2 archive the number of non-event rows equals `strlen(dec)` of the archived `FULL2` row (tested) |
| LT-20 (2), (4) | events are the archive's own closures executed against an archived Host and recorded by observable effect; the dump runs at test time with `PHP_INI_SCAN_DIR=` and `memory_limit=1G` |

## Gates

| Gate | Result |
| --- | --- |
| **LANE** (`lane`) | pass: a verified credential selects the proven door; eight failed checks (wrong account, wrong group, wrong instance, expired, stale epoch, tampered MAC, foreign binding key, none) are the unproven door and neither a refusal nor an exception; `isDevicePreviouslyVerifiedForAccount`, a trusted session and an instance id alone never select it; unproven requests write no proven state. Detector: `laneFromVerdict` mutated to let `isKnownForAccount` select P puts a known-device request on the proven door |
| Refusal shape and the six causes, permit, outcome hooks (`admission-engine`) | pass: `SOFT_BLOCK`, `blockLevel` 1, `retryAfter` to the window end (or the bucket wait), `reason` = cause, `scope` ∈ source / account / proof / device; a permit (at most 512 characters, also with a 3,000-character credential id) on every admission; `permit_duplicate / conflict / rejected / late / unknown` are `ALLOW` results with scope `permit` and no scoring; an outcome with no permit is a `RateLimiterException`; a dropped outcome counts as one failure exactly from the deadline; success refunds the bucket and the proven counters, failure never; D8 is cancelled by a success and a failure enters it |
| **FAIL** | pass: a typed outage of the reserve, the epoch read, the claim and the outcome takes the existing `FAIL_CLOSED` path; malformed state is a `RateLimiterException`; an unclassified throwable escapes and never enters the circuit; an open circuit performs no admission, epoch or fence call. Detector: a `catch (\Throwable)` mutation of the engine hides the unclassified throwable |
| **Fence** (LT-13) | pass: a closed fence is the engine without admission (no permit, no reserve, unbound outcomes accepted); an open fence enables it. Detector: `fenceOpen` returning true reserves through a closed fence |
| S8, LT-21, LT-12, LT-18 | pass: `revokeAccountBinding` and `bindingEpoch` on the engine; budgets validated (`D ≤ W`); the pipeline and `SourcePrefix` derive the same source for IPv4, IPv6, mapped and loopback |
| **RP-G4** (streams) | pass with one declared difference: every `FULL2` `pre+out` row of `v2_streams.G4.jsonl` is reproduced field for field (decision, reach, kinds, lanes, metrics) **except `UAC`**: 9 legit denials, the archived G5 v2 value (`AAASSSSSHSSS`), not 6. A2 holds (MIX 54, S0 117); A3 holds (SP1n 4, SP3 8, SP6 24, ABA_A 8) |
| **RP-G5** (streams) | pass: every `NOUA2` row of `g5v2_streams.G5V2.jsonl` in `pre+out`, 91 tuples including the no-id variants, equal field for field with the Host counts (`issued`, `tainted`) |
| Gate P (reported) | the arm without the mechanism reproduces **every** archived `pre` row; with the mechanism the `pre` rows of ABA_A, D2, MIX, N12, OFF12, POI, SP1n and SP6 differ (settlement counts each unclaimed admission as a failure) |
| **NM-10** | detector 1 fired: without the mechanism at share 1.00, SP6 144, SP3 48, SP1n 24, ABA_A 48, A3 fails. **Registered PASS not met** — see F-5b3b-NM10 |
| **NM-10 B1** | pass at shares 0, 0.10, 0.25, 0.50, 1.00: BRU24 admitted 51 ≤ 52, equal to the independent recount of admitted bucket reservations; `account_bucket` 5,709 |
| **NM-5** | harness validity pass (`ρ` 1, abandonment 0 = RP-G4 values); detector fired (`ρ` 3 refuses benign streams more than `ρ` 1). **Registered PASS not met** — see F-5b3b-NM5 |

## Findings (each is a red test of a registered PASS condition; none weakened)

1. **F-5b3b-A1-UAC.** A1 fails at its own baseline (drop share 0, `ρ` 1): `UAC` 9 against S0 6 and S24 6. K5 is on the client instance id; the production row equals the G5 v2 archive row, the declared owner cost of DEC-026 draft Delta item 4. The registered "A1 holds at every share and combination" cannot hold at its baseline.
2. **F-5b3b-NM10.** With the mechanism: A3 fails at 1.00 (SP1n 13 / 12, SP3 48 / 24, SP6 78 / 72); benign streams are refused above S0 and S24 from 0.25 (L1 7), 0.50 (L1 31, D2 62, N12 4, OFF12 5) and 1.00 (L1 72, ABA_B 94, D2 80, N12 12, OFF12 8, Y6b 10). Without the mechanism at 0.50: A3 holds (SP6 47) and L1 31; so the clause "at the highest share where the mechanism passes, the arm without it fails" is not evidenced (the mechanism passes at no registered share).
3. **F-5b3b-NM5.** `ρ` 2: L1 82, ABA_B 98, D2 87, Y6b 13; `ρ` 3: L1 112, ABA_B 110, D2 109, Nu4 32, Y6b 22; abandonment 0.10 / 0.25 add D2 +2 / +13, ABA_B +0 / +36, L1 0 / 7. A probe is a permit never claimed (no refund of the proven counters, a failure for the net set).
4. **Gate P.** Reported above; no verdict.

The numbers of findings 2 and 3 are the measured values of this run (`StreamGates`, `A1` / `A3` over the archived best-response definitions); A1 compares against S0 (`s00_s0.jsonl`), S24 (`v2_streams.CAND.jsonl`) and S12 (`v2_fc.CAND.jsonl`), read at run time.

## Not covered here (named)

* **Commit 2b-2, LT-23:** the fixture gates (RP-G4 C1, C3, C4v2, C5, C6, E, K5; RP-G5 `UAX-NP`, `D-IDMINT`, `BRU24` 51 / `FORGE` / `MINT` / `STEAL` / `NP-IN`; RP-G6; RP-G7; RP-GLOB for fixtures; B2 REC), pending the ruling on LT-23.
* **Written after the seams are registered:** NM-7 at engine level (mixed fleet behind the fence, M6 rollback), the S0 goldens through the engine with a lane, CYCLES and LIFECYCLE with the lane from the engine, SC-1…SC-6 with the co-tenant harm per stream, the CVH scenarios (§8.1). The stream gates above already exercise the engine end to end; these are separate registered gates.
* LT-14 reported test: written (an outcome from another source lands on that source's failure set; the admission source's pending entry settles at the deadline).

## Open items

1. The four findings and LT-23.
2. The reference store changed in 5-b2.1 is not modified here; `PreSettlementReferenceStore` is its verbatim copy at `d6aea4d` (the arm without the mechanism), `MergedAdmissionOperations` the verbatim Redis operations.
3. `phpstan.neon` has one `excludePaths` entry (the dump script runs in its own process against the archive's global functions). `AdmissionArchiveStreamsTest` raises the test process memory limit to 1G, as the archive's own runner does.
