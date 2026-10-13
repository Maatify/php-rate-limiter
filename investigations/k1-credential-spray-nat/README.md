# #106 — K1 credential-spray / shared-NAT evidence

**Status: EVIDENCE ONLY — Draft PR for independent review.** Nothing here changes `src/`, `tests/`, `docs/`, `DECISION_MATRIX.md`, any ADR/Decision, Composer, CI or any
public / Redis / store contract, and nothing here selects, approves or recommends a threshold or policy. Everything lives under
`investigations/k1-credential-spray-nat/` (outside the PHPUnit / PHPStan / php-cs-fixer scopes and not part of the package contract).

Related: [#106](https://github.com/Maatify/php-rate-limiter/issues/106) (this study), [#97](https://github.com/Maatify/php-rate-limiter/issues/97) and
[#100](https://github.com/Maatify/php-rate-limiter/issues/100) (the §5.3 correlation issues — **not** closed or resolved here).

Baseline: `Maatify/php-rate-limiter` `origin/main@4d9495ded05f647ce0421352781ce948ceeaa606` (merged #107). The study reuses, unchanged, the PR #103 `Lab`
(REAL `EvaluationPipeline` + REAL `DeviceIdentityResolver` + reference stores + controlled clock), the PR #104 `wsb` harness and Redis-safety model, and the PR #105 D2
stream. It does not rerun the #105 / #107 design runs and does not touch their goldens.

## 1. Question

Task #106: independently prove or refute that D2's **108 / 121** legitimate denials are caused by the K1 credential-spray rule (`DECISION_MATRIX.md` §5.1, `KEY_STRATEGY.md`
§4.3.4, `EvaluationPipeline::checkCredentialSpray`), separate K1 from §5.3 (K2 churn / dilution) and from every other protection, and measure what a K1 change would cost in
actual credential-spray resistance. **Result and numbers: [`REPORT.md`](REPORT.md); every table is generated in [`expected/summary.md`](expected/summary.md).**

## 2. Method — isolated TEST-ONLY controls, unpatched pipeline

There is **no candidate tree and no patch**: the pipeline under test is always the repository's own unpatched `EvaluationPipeline` (sha-256 `4b259d33…f4fc`, asserted by
every scenario; a candidate label is refused with exit 4). The controls live in the *injected correlation store* (`lib/k1.php::K1Store`):

| Arm | What it is | How |
|---|---|---|
| **A** | S0 as shipped | — |
| **B** | **TEST ONLY** — churn (600 s / cap 3) and dilution (600 s / cap 6) sets disabled | the set answers `count 1, accepted` |
| **C** | **TEST ONLY** — K1 credential-spray sets disabled (base K1, current/bridge/previous rotation and the IPv6 macro scopes; (600 s, cap 5) is used by no other pipeline state) | same |
| **D** | **TEST ONLY** — both disabled | same |

Everything else stays the real code: K1..K5 scores and thresholds, K4 budgets / anti-equilibrium, device caps, distributed-account snapshots, flood stage, persisted blocks,
DEC-003 cycles, trusted-session advisory. All arms replay the same events on the same controlled clock with the same scopes.

**Attribution.** The public result exposes no winning source. For every request the harness records (a) which persisted blocks were active on its canonical keys *before* it,
(b) which blocks it *issued* (DEC-003 cycle log → enforcement key K1…K5), (c) which correlation signals the store observed *during* it (spray / churn / dilution / distributed).
A denial is reported as `persisted:Kn(origin)` or `issued:Kn(signals)`; the paired arms add a per-request counterfactual partition (denied in A → freed by turning spray off,
by turning churn/dilution off, by either, or by neither). Path dependence is real (each arm has its own state) — the same attribution method as PR #104 / #105.
Limits: rotation scenarios read canonical *current* keys only, so a block on a previous-generation key carries no source label there.

## 3. What is covered

| File | Content |
|---|---|
| `scenarios/k02_matrix.php` | 28 streams × 4 arms × flows `pre+out` / `pre` (checkOnly only) / `out` (record-only), Login; plus Login-without-account-id and OTP shapes. D2 baseline reproduction (117 / 108 / 115 / 7) and control-effectiveness assertions |
| `scenarios/k03_boundaries_recovery.php` | 4/5 and 5/5, WATCH counting requests, repeats, recovery (60 s block vs 600 s set), 600 s fixed window, 1800 s WATCH, trusted probe, OTP, negative control |
| `scenarios/k04_lifecycle_trust.php` | which Host commands observe spray, double-count check, record-only blind spot, trust qualification matrix, advisory persistence |
| `scenarios/k05_ipv6_rotation_subject.php` | IPv6 `/48` and `/40` macro scopes (legit office vs rotating attacker), Current/Previous rotation, correlation-subject quality (absent / opaque / constant / per-request unique) |
| `scenarios/k06_bounds.php` | storage cap, fixed TTL, expiry, per-IP bound |
| `scenarios/k07_redis.php` (+ `k07_worker.php`) | real Redis: decision parity, TTL hygiene, atomic cap under concurrency (16 simultaneous subjects × 8 rounds), duplicate race, rotation |
| `scenarios/k08_seed_sensitivity.php` | 20 typo seeds for the benign populations (observed range, not a statistical confidence interval — the simulation is deterministic) |
| `report.php` | fail-closed gates (exit 5 on any missing / empty / malformed / violated evidence), generated `summary.md` |

Streams (`lib/k1.php::k1Streams`): benign `D2`, `N3…N12` (realistic same-UA offices), `Nu3…Nu12` (**K1-isolation control**: a different browser major per colleague so §5.3 cannot
fire — not a realistic population), trusted `T` / half-trusted `M` variants; attacks `SP1f` / `SP1n` (single-IP spray with / without fingerprint), `SP3` (stuffing, 1000 accounts),
`SP2` (slow spray hovering at 4/5), `SP4` / `SP4u` / `SP4b` (8-IP botnet), `SP5` (spray from a trusted session device); mixed `MIX`, `MIXT`, `MIXU`, `MIXN` (colleagues and attacker
behind the same NAT).

## 4. Reproduce

```bash
composer install
bash investigations/k1-credential-spray-nat/run.sh                 # runs everything, diffs against expected/, ~80 s
UPDATE=1 bash investigations/k1-credential-spray-nat/run.sh        # regenerate expected/ (review the git diff!)
REDIS_SPOTCHECK=0 bash investigations/k1-credential-spray-nat/run.sh   # gate G10 becomes UNPROVEN, never PASS
```

Recorded versions are written to `results/actual/versions.txt` (`results/` is git-ignored scratch; the committed evidence is `expected/`). The run **fails closed**:
the baseline gate refuses any change under `src/`, `tests/`, `docs/`, `composer.json` or `composer.lock`; every scenario aborts with exit 4 unless the loaded pipeline is the
unpatched baseline; `report.php` exits 5 and marks every gate UNPROVEN on missing or violated evidence.

**Redis safety (inherits F103-01 / PR #104).** `run.sh` starts its own loopback instances (`--bind 127.0.0.1 --protected-mode yes`, no persistence), proves with a *decoy*
server holding a sentinel key that foreign / mismatched-pid / mismatched-dir / non-empty servers are refused (rc 3,3,3,3, sentinel intact), ignores `REDIS_HOST` / `REDIS_PORT`,
never issues `FLUSH*` / `CONFIG SET` / `DEBUG` / `SHUTDOWN` against anything it did not start, and every worker re-verifies process id and working directory. Raw Redis TTL values
depend on wall time and are never recorded; only booleans / cardinalities are.

**Self-tests (negative controls, all in `run.sh`):** a broken control (arm C not disabling spray) must be detected; a one-second move of the 600 s window must be detected; a
candidate label must be refused (exit 4); ten fail-closed cases (deleted scenario outputs, one deleted matrix cell, a removed matrix field, a deleted assertion row, a violated
assertion, deleted Redis evidence) must all make `report.php` exit 5 with no PASS gate.
