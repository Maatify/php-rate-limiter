# Unified Security Feasibility — #97 / #100

**Status: FEASIBILITY EVIDENCE — Draft, must stay unmerged.** One test-only candidate (*Policy-Specific Corroboration + Scope-Safe Recovery*, **PSC**) measured against S0 (current `main`), EQL and EQV (PR #105). Nothing here selects, recommends or implements a policy; no change to `src/`, `tests/`, `docs/`, ADRs, Redis Store interfaces, CI, version, or the PR #103/#104/#105 studies and goldens. Everything lives in this directory.

Start with **[FEASIBILITY.md](FEASIBILITY.md)** (verdict, numbers, owner decisions). Generated evidence: [expected/summary.md](expected/summary.md) and [expected/gates.jsonl](expected/gates.jsonl).

Related: [#97](https://github.com/Maatify/php-rate-limiter/issues/97), [#100](https://github.com/Maatify/php-rate-limiter/issues/100) (both remain open); #106 is the separate K1 credential-spray investigation. Builds on `investigations/workstream-b-correlation/` (#103), `investigations/workstream-b-anti-evasion/` (#104) and `investigations/workstream-b-anti-evasion/design/` (#105), reused **unchanged**.

## Layout

| Path | Purpose |
|---|---|
| `FEASIBILITY.md` | The report: candidate, results, why separation is not obtainable, gates, residual risks, Owner decisions |
| `patches/psc-candidate.patch`, `build_candidate.sh` | Pinned experimental runtime (baseline `b34f12eb…` + one patch to `EvaluationPipeline`, `private` members only) and its refusing build recipe. `build_candidate.sh eq` builds PR #105's runtime from its published patch, read in place |
| `lib/fsb.php`, `fixtures/feasibility.php` | Harness extension: provenance of five variants, decision-scope derivation, operation counting, stream/cell catalogue |
| `scenarios/f01_matrix.php` | Attack-vs-legitimate matrix: Login / login-anon / OTP / API_OVERUSE / custom API × checkOnly+outcome / record-only / checkOnly-only |
| `scenarios/f02_sensitivity.php` | The same candidate with its window cap raised (separability sweep) |
| `scenarios/f03_recovery_boundaries.php` | 60/600/1800 s horizons, fixed window, renewal, sustained leak, N-1 gaming |
| `scenarios/f04_rotation_ephemeral_failure.php` | Current/Previous rotation, ephemeral overflow, backend-failure propagation, minimal custom store |
| `scenarios/f05_state_cost.php` | Bounded state, TTL hygiene, flood amplification, per-command operation counts |
| `scenarios/f06_redis.php` | Real Redis: concurrency, TTL, live recovery on the Redis clock, outage |
| `scenarios/f07_poisoning_cost.php` | Price of poisoning the NAT cohort through correlation |
| `report.php` | 19 gates + verdict + `summary.md`; fails closed (exit 5) on missing/empty/malformed evidence |
| `run.sh` | Everything above plus self-tests that must *detect* mutations, mislabelled provenance, bad build inputs and damaged evidence |
| `expected/` | Golden machine outputs (diffed by `run.sh`) |
| `results/` | Scratch (`actual/`, `work/`: git-ignored; never committed) |

## Reproduce

```bash
composer install
bash investigations/workstream-b-anti-evasion/feasibility/run.sh                 # diffs against expected/
UPDATE=1 bash investigations/workstream-b-anti-evasion/feasibility/run.sh        # regenerate expected/ (review the git diff!)
REDIS_SPOTCHECK=0 bash investigations/workstream-b-anti-evasion/feasibility/run.sh
FSB_REDIS_LIVE=0 bash investigations/workstream-b-anti-evasion/feasibility/run.sh
```

Runtime ≈ 10 minutes. Real-Redis stage: `run.sh` starts and proves ownership of its own loopback instances (decoy-server refusal proof); it never touches a Redis it did not start.
