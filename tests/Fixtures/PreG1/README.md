# Historical V2 mixed-version replay

These four test-only source fixtures come from the exact G1 base `6db137868f4abaebb94a287f75a3af1742d8d3bc` (tree `b03cf3e2e267ab8c863edc8264e80923a7f1812e`): `src/Service/EvaluationPipeline.php`, `AccountScopeKeyDeriverInterface.php`, `V2AccountScopeKeyDeriver.php` and `src/Enum/KeyScopeEncodingEnum.php`.

Only namespace/import resolution and formatting changed. `PreG1ReplayProvenanceTest` checks every executable body token against that base; it excludes whitespace/comments and the relocated namespace/import declarations. Standalone helpers and store primitives are the maintained unchanged implementations. The fixture is never on production Composer autoload.

The historical S0-versus-V2 known-device tests keep their original decisions, rotation expectations and mixed-fleet detectors against this frozen later V2 worker. They remain historical evidence, not a claim that the G1 worker can read V2 account state. `KeyScopeE1RedisTest::testHistoricalV2AndCurrentE1WorkersCannotSeeEachOthersAccountBlocks` separately runs the current runtime and historical V2 worker against isolated real Redis and proves the unsupported transition in both directions. Current rotation/lifecycle/cycle/known-device suites run E1.
