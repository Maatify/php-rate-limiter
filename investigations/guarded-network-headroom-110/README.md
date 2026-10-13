# #110 Guarded Network Headroom — architecture feasibility and isolated security experiment

**Status: EVIDENCE ONLY — Draft PR for independent review.** Nothing here changes `src/`, `tests/`, `docs/`, any ADR / Decision, Composer, CI,
the public API or any Store contract, and nothing here selects, approves or recommends a policy. Everything lives under
`investigations/guarded-network-headroom-110/`. DEC-019 stays ACTIVE and binding; ×5 and the budgets are experimental parameters.

Related: [#110](https://github.com/Maatify/php-rate-limiter/issues/110) (the comparative study; this measures the Lead's "Network Headroom ×5"
direction from [comment 6151764966](https://github.com/Maatify/php-rate-limiter/issues/110#issuecomment-6151764966)),
[#97](https://github.com/Maatify/php-rate-limiter/issues/97), [#100](https://github.com/Maatify/php-rate-limiter/issues/100),
[#106](https://github.com/Maatify/php-rate-limiter/issues/106) (**not** closed or resolved here). #114 is out of scope.

Baseline: `Maatify/php-rate-limiter` `main@1ad9af289f83f86346667a014dd486f9c4b8fc08` (merged #113). Reused unchanged: the PR #103 `Lab` (REAL
`EvaluationPipeline` + REAL `DeviceIdentityResolver` + reference stores + controlled clock), the PR #104 streams and Redis-safety model, the
PR #105 D streams, the PR #108 K1 harness, the PR #111 #110 streams and the PR #113 trusted multi-session streams.

* Design, invariants, feasibility verdict: [`ARCHITECTURE.md`](ARCHITECTURE.md)
* Results, measured vs unmeasured, Owner decisions: [`REPORT.md`](REPORT.md)
* Every table, generated: [`expected/summary.md`](expected/summary.md); gates: [`expected/gates.jsonl`](expected/gates.jsonl)

## 1. Arms

| Arm | Runtime | What it is |
|---|---|---|
| **S0** | repository `src/` (sha-256 `4b259d33…f4fc`) | the shipped runtime, unpatched (`S0_RUNTIME_OBSERVED`) |
| **TH5** | isolated patched tree (`d7413b9d…`), `GNH_ARM=TH5` | control: K1 spray / K2 churn / LOW dilution caps ×5 (25 / 15 / 30), WATCH at the new N−1, on the S0 keys |
| **GNH5** | same tree, `GNH_ARM=GNH5` | S0 rule on the S0 keys + ×5 rule on a separate key namespace + a per-rule-scope fixed-window headroom budget (spray 20, churn 12, dilution 24 evaluated commands per 600 s), spent atomically before verification |

The candidate is the committed `patches/gnh-candidates.patch` (one file, `src/Service/EvaluationPipeline.php`, sha-256 pinned before and after),
applied by `build_candidate.sh` to a `git archive` of the baseline in an isolated tree. The patched pipeline throws unless `GNH_ARM` is `TH5` or
`GNH5`, so the experimental tree can never run as S0.

## 2. What is covered

| File | Content |
|---|---|
| `scenarios/g01_matrix.php` | 81 streams × 3 flows on Login, plus OTP / Login-without-account / `API_OVERUSE` / DEC-008 custom API cells, **per arm** (311 cells): the #108, #104, #105, #111, #113 streams and the study's own XN1 / XN1n / XN1k (N−1 gaming at the ×5 level), BURN (a neighbour spending the NAT headroom) and N12V (previously verified devices). S0 invariants (hard-fail) reproduce the published numbers |
| `scenarios/g02_guard.php` | the guard: exact budget (K1, K2), fixed window and boundary burst, `pre` / `pre+out` / record-only spending, one party exhausting the NAT, 60 / 600 s boundaries and renewal, DEC-003 cycles |
| `scenarios/g03_rotation_ipv6.php` | three rotation modes on 7 streams, the budget across rotation, an incidental S0 rotation observation (R4), IPv6 /48 office and /64 → /48 → /40 attackers |
| `scenarios/g04_bounds_rollback.php` (+ `g04_child.php`) | keys per in-zone scope, TTL bounds, budget backend failure through the REAL `RateLimiterEngine` (incl. the DEC-015 degraded ALLOWs), rollback mid-window and mixed version with the REAL S0 code in another process on the same state, S0-key state parity key for key (incl. the churn → dilution path) and S0 decision parity after rollback / mixed version |
| `scenarios/g05_redis.php` (+ `g05_child.php`) | official Redis store: parity with the reference store, TTL and budget flags, rollback and mixed version with the REAL S0 code |
| `scenarios/g06_concurrency.php` (+ `g06_worker.php`) | real Redis concurrency: simultaneous requests above the remaining budget at the boundary (K1, K2), a fresh-scope burst, two budgets on the same requests |
| `report.php` | fail-closed evidence validation (exit 5) and gates E0, G1a/G1b (Bounded Security), G2 (trade-off), G3a/G3b (fairness), G4a–G4h (operational; G4h = S0 state / decision parity after rollback); generated `summary.md` |

Attribution is the PR #108 method (persisted blocks before the request, blocks issued by it from the DEC-003 cycle log, correlation signals
observed by the store), extended in `lib/gnh.php` with the final decision, level / Retry-After, the GNH5 headroom admissions (budget increments
≤ B) and alive correlation keys.

## 3. Reproduce

```bash
composer install
bash investigations/guarded-network-headroom-110/run.sh                     # everything, diffed against expected/ (~3 min)
UPDATE=1 bash investigations/guarded-network-headroom-110/run.sh            # regenerate expected/ (review the git diff!)
REDIS_SPOTCHECK=0 bash investigations/guarded-network-headroom-110/run.sh   # real-Redis gates become UNPROVEN, never PASS
```

Recorded versions go to `results/actual/versions.txt` (`results/` is git-ignored scratch; the committed evidence is `expected/`). The run **fails
closed**: the baseline gate refuses any change under `src/`, `tests/` or `composer.json`; every scenario aborts with exit 4 unless the loaded
pipeline is the pinned unpatched baseline (S0, repository tree) or the pinned patched candidate (TH5 / GNH5, isolated tree), and refuses a
foreign study's arm variable (`OQC_ARM`, `WSB_CANDIDATE`); `report.php` exits 5 and marks every gate UNPROVEN on missing, empty, malformed or
mislabelled evidence, on a failed S0 baseline invariant, or when the committed patch does not match its pin.

**Redis safety (inherits F103-01 / PR #104).** `run.sh` starts its own loopback instances (`--save "" --appendonly no`, its own directory),
proves with a decoy server that foreign / mismatched / non-empty servers are refused (rc 3,3,3,3, sentinel intact), ignores `REDIS_HOST` /
`REDIS_PORT`, never issues `FLUSH*` / `CONFIG SET` / `DEBUG` / `SHUTDOWN`, and stops only the instances it started. Raw Redis TTLs are never
recorded.

**Self-tests (negative controls, all in `run.sh`):** a candidate arm on the repository tree, the S0 arm on the candidate tree, an unknown arm and a
foreign arm variable are refused (exit 4); the patched tree refuses to run without a valid `GNH_ARM` even when the harness provenance is bypassed;
a drifted patch is refused by the hash pin (exit 6) and by `git apply` (exit 4); a wrong baseline target hash is refused (exit 3); a violated
candidate assertion is fatal under `GNH_STRICT=1` and a violated S0 invariant is always fatal; twenty-two fail-closed evidence cases make
`report.php` exit 5 with no PASS gate; three gate-detection cases inject a violation into valid evidence and require G1a, G1b and G3a to turn
FAIL.
