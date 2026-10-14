# #110 Outcome-Aware Atomic Admission (OAAA) — architecture, feasibility and isolated security / fairness experiment

**Status: EVIDENCE ONLY — Draft PR for independent review.** Nothing here changes `src/`, `tests/`, `docs/`, any ADR / Decision, Composer, CI,
the public API, Redis keys or any Store contract, and nothing here selects, approves or recommends a policy. Everything lives under
`investigations/outcome-aware-atomic-admission-110/`. DEC-019 stays ACTIVE and binding; the OAAA parameters are experimental values.

Related: [#110](https://github.com/Maatify/php-rate-limiter/issues/110) (Owner direction
[comment 6156215783](https://github.com/Maatify/php-rate-limiter/issues/110#issuecomment-6156215783)),
[#97](https://github.com/Maatify/php-rate-limiter/issues/97), [#100](https://github.com/Maatify/php-rate-limiter/issues/100),
[#106](https://github.com/Maatify/php-rate-limiter/issues/106) (**not** closed or resolved here). #114 is out of scope.

Baseline: `Maatify/php-rate-limiter` `main@72f6dea8ab0729e63492b622e682e334c527c966` (merged #115). Reused unchanged: the PR #103 `Lab` (REAL
`EvaluationPipeline` + REAL `DeviceIdentityResolver` + reference stores + controlled clock), the PR #104 / #105 / #108 / #111 / #113 / #115
streams and harness layers, and the PR #103 Redis-ownership safety model.

Read in this order:

1. [`ARCHITECTURE.md`](ARCHITECTURE.md) — the one design, answers to A1–A5, invariants, known limits (Stage A, committed before the run)
2. [`CONTRACT_FEASIBILITY.md`](CONTRACT_FEASIBILITY.md) — verdict `FEASIBLE_WITH_OPTIONAL_CONTRACT_EXTENSION` (Stage C, committed before the run)
3. [`SECURITY_ENVELOPE.md`](SECURITY_ENVELOPE.md) — pre-registered gates and decision rule (Stage B, committed before the run)
4. [`REPORT.md`](REPORT.md) — measured results, FAIL / UNPROVEN list, Owner decisions, final engineering verdict
5. [`expected/summary.md`](expected/summary.md) and [`expected/gates.jsonl`](expected/gates.jsonl) — every table and gate, generated

## 1. Arms

| Arm | Runtime | What it is |
|---|---|---|
| **S0** | repository `src/` (pipeline sha-256 `4b259d33…f4fc`, Redis adapter `520574d4…97a4`) | the shipped runtime, unpatched (`S0_RUNTIME_OBSERVED`) |
| **OAAA** | isolated patched tree, `OAAA_ARM=OAAA` | S0 rules unchanged + one atomic, credit-gated, outcome-linked admission reservation over the correlation candidates (`CANDIDATE_EXPERIMENTAL_RUNTIME`) |

The candidate is the committed `patches/oaaa-candidate.patch` (zero-context, re-pinned after the F116-02 Lua fix; 3 files: `EvaluationPipeline.php`, the official Redis adapter, a new
experimental `OutcomeAdmissionStoreInterface`; every hash pinned before and after), applied by `build_candidate.sh` to a `git archive` of the
baseline. The patched pipeline throws unless `OAAA_ARM=OAAA`, so the experimental tree can never run as S0; the harness refuses a candidate on
the repository tree, S0 on the candidate tree, an unknown arm and a foreign study's arm variable. No control arm was needed: S0 is the control.

## 2. What is covered

| File | Content |
|---|---|
| `scenarios/a01_matrix.php` | 89 streams × 3 Host flows on Login, plus OTP, Login without account, a DEC-008 custom authentication policy, `API_OVERUSE` and a custom API policy, **per arm**; request-for-request bit strings and per-60 s / per-scope / per-600 s reach so every delta is exact. S0 invariants (hard-fail) reproduce the published numbers; `report.php` also checks every common cell against the merged #115 S0 golden |
| `scenarios/a02_lifecycle.php` | 27 reservation-lifecycle probes (L1–L23 before the run, L24–L27 after the Lead review): credit gating, pre-only, refunds, same-tuple refund (MEDIUM vs LOW), cross-account substitution, replay, duplicate failure, out-of-order, late completion, epoch rollover, day / global tiers, own-account credit cap, trusted advisory, persisted block, API / anon / no-capability equality, cancel, all-or-nothing, pre+out pairing, record-only; after the Lead review (F116): two pending + replayed success (L24), replayed success with no new attempt (L25), MEDIUM reorder (L26), mixed pre+out / pre-only flows on one scope (L27) |
| `scenarios/a03_rotation_ipv6.php` | 3 rotation modes on 9 streams vs the same-arm reference, units / credits / global tiers across rotation, the #115 R4 single-device observation, IPv6 /48 office, /64 and /48 rotating attackers, an attacker inside the office /48 |
| `scenarios/a04_failure_rollback_bounds.php` (+ `a04_child.php`) | storage bounds, public surface by reflection, OAAA-operation failure and whole-store outage through the REAL `RateLimiterEngine` (DEC-015), rollback / mixed version with the REAL S0 code in another process, S0-family state parity, rollback decision parity, roll-forward |
| `scenarios/a05_redis.php` (+ `a05_child.php`) | official Redis store: decision and unit parity with the reference store, key hygiene, malformed OAAA state (no partial write), rollback and mixed version with the REAL S0 code; after F116: wrong-type / malformed keys with before / error / after snapshots (Q6), replay / reorder parity (Q7), mixed flows (Q8) |
| `scenarios/a06_concurrency.php` (+ `a06_worker.php`) | real Redis, separate PHP processes released at one Redis instant: one / zero unit left, K1+K2 all-or-nothing, global tier, successes racing pre-checks, replayed completion, expiry during verification, rotation with active reservations |
| `scenarios/a07_host_probe.php` | the public `RateLimiterBuilder` + `RateLimiterInterface::limit()` over Redis, one framework-free Host adapter (`checkOnly → verify → outcome`), identical code on both arms; H3 two routes (pre+out and pre-only) on one limiter (F116-03) |
| `scenarios/a08_prefix_lua_probe.php` | F116-02 historical evidence: the SUPERSEDED `0df807f` Redis adapter (hash-pinned, rebuilt from git history) probed with the wrong-type cases — it leaves partial writes |
| `report.php` | fail-closed evidence validation (exit 5) and the pre-registered gates E1–E6, F1–F2, O1–O10 and the decision rule, plus X1–X3 added after the Lead review (never relaxing a registered gate); generated `summary.md` |

## 3. Reproduce

```bash
composer install
bash investigations/outcome-aware-atomic-admission-110/run.sh                     # everything, diffed against expected/ (~6 min)
UPDATE=1 bash investigations/outcome-aware-atomic-admission-110/run.sh            # regenerate expected/ (review the git diff!)
REDIS_SPOTCHECK=0 bash investigations/outcome-aware-atomic-admission-110/run.sh   # Redis-backed gates become UNPROVEN, never PASS
```

`results/` is git-ignored scratch; the committed evidence is `expected/`. The run **fails closed**: the baseline gate refuses any change under
`src/`, `tests/`, `docs/` or `composer.json`; every scenario aborts with exit 4 unless the loaded runtime is the pinned unpatched baseline (S0,
repository tree, no experimental interface) or the pinned patched candidate (OAAA, isolated tree); `report.php` exits 5 and marks every gate
UNPROVEN on missing, empty, malformed or mislabelled evidence, a wrong runtime hash, a missing scenario cell, assertion or data row, a failed
S0 invariant, an S0 cell that does not reproduce the merged #115 S0 golden, or a patch that does not match its pin.

**Redis safety (inherits F103-01 / PR #104).** `run.sh` starts its own loopback instances (`--save "" --appendonly no`, its own directory),
proves with a decoy server that foreign / mismatched / non-empty servers are refused, ignores `REDIS_HOST` / `REDIS_PORT`, never issues
`FLUSH*` / `CONFIG SET` / `DEBUG` / `SHUTDOWN`, and stops only the instances it started. `a06` C7 simulates the passage of time by expiring the
study's own keys (`PEXPIRE`) on its own instance.

**Self-tests (negative controls, all in `run.sh`):** provenance ×5 (candidate on the repository tree, S0 on the candidate tree, unknown arm,
foreign arm variable, the patched tree refusing to run unlabelled), patch pinning ×3 (drifted patch by hash, drifted patch by `git apply`,
wrong baseline hash), strict mode ×2, fail-closed evidence ×25 (missing / empty / corrupted files, missing cell, field, assertion and data row,
unreproduced S0 invariant and S0 cell, wrong S0 / candidate / Redis-adapter runtime hash, candidate labelled S0, swapped arm labels, unknown
assertion, deleted Redis / concurrency / host evidence, missing Redis status, deleted superseded-adapter probe, invalid patch provenance), missing gate ×1, and gate
detection ×6 (E2, E5, E6, F2, X2 and O1 must turn FAIL on injected violations) — 42 in total.
