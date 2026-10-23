# #160 fix (a) — one clock reading in `addBoundedSnapshot()`: measurement

Assignment and ruling LT-160A-01 (option b: the S0 fixture is not touched; S0 wiring is a held shared clock in the two mixed-version tests).

## Change

* `src/Service/EvaluationPipeline.php::addBoundedSnapshot()`: `$nowAt = $this->clock->now()` once; `$now = $nowAt->getTimestamp()`; `$nowAt` goes to `CallerTimeStore` in both branches and `$now` to the validator.
* `tests/Unit/Service/BoundedSnapshotSingleClockReadTest.php` (commit `f1847df`, red on `main@2203a68`: test (i) fails at `BoundedCorrelationResultValidator.php:62` with `Bounded correlation store returned an invalid snapshot.`; (ii), (iii), (iv) pass).
* `KnownDeviceMixedVersionIntegrationTest` / `KnownDeviceMixedVersionSystemTest`: store and S0 worker share one `RequestScopedClock`; every `process()` runs inside `holding()`.
* `tests/Fixtures/S0Baseline/S0EvaluationPipeline.php` and `investigations/flaky-160/harness/m.php` and all other flaky-160 evidence: unchanged. The added arm lives in `harness/m_fixa.php` (copy of `m.php` plus `det-held`).

## Measurement

Local, no runners: Redis 8 on 127.0.0.1:6391 (`redis-server`, no persistence), `php investigations/flaky-160/harness/m_fixa.php --subarm=<arm> --n=20`, run at commit `149966ad047a95d40d19a3b6b6c9e7f5a6e05f7c` (HEAD after the fix `73dfa2e`, the wiring `1f967be` and the harness copy). Count = trials whose outcome was an exception `Bounded correlation store returned an invalid snapshot.`.

| arm | prediction | before (#161) | measured |
|---|---|---|---|
| `det` (S0 fixture, clocks T / T+1) | 20/20 (fixture unchanged) | 20/20 | **20/20** |
| `det-control` | 0/20 | 0/20 | **0/20** |
| `det-held` (shared held clock, step host clock, `holding()`) | 0/20 | new | **0/20**, host clock read once per trial (20/20 trials) |
| `ep-det` (direct EvaluationPipeline, step clock) | 0/20 | 20/20 | **0/20** (one clock read per trial) |
| `ep-det-control` | 0/20 | 0/20 | **0/20** |
| `ep-builder-limit` | 0/20 | 0/20 | **0/20** |
| `ep-builder-outside` | 0/20 | 20/20 | **0/20** |

No prediction was contradicted. Not measured here: the natural-rate arms (`nat`, `ep-nat`); the other four test families (T-CLAIM, T-ALLOW, T-K4, T-HAND) are phase 2.

Also run locally on the same tree: `KnownDeviceMixedVersionIntegrationTest` (3 tests, 104 assertions, green on real Redis), `KnownDeviceMixedVersionSystemTest` (3, green), `S0BaselineFixtureProvenanceTest` (green, unchanged).
