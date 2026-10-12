# Design & Proof Gate — Evidence-Qualified Correlation Enforcement (#97 / #100)

**Status: DESIGN & PROOF — Draft, must stay unmerged until the independent review and the Owner decisions.** No change to `src/`, `tests/`,
`docs/`, `DECISION_MATRIX.md`, ADRs, Composer files, CI, version or changelog. Nothing here selects or approves a policy.
Start with **[DESIGN.md](DESIGN.md)** (verdict, contract delta, security regression, cost, Owner decisions, follow-up flow); the generated evidence is
[expected/summary.md](expected/summary.md) and [expected/gates.jsonl](expected/gates.jsonl).

Builds on `investigations/workstream-b-correlation/` (PR #103: `Lab` harness = REAL `EvaluationPipeline` + REAL `DeviceIdentityResolver` + reference stores +
controlled clock) and `investigations/workstream-b-anti-evasion/` (PR #104: streams L1–L4 / X1–X8 / Y*, `wsbMeasure`, A06 and A07), reused **unchanged**.

## 1. Evidence levels (never merged into one result)

| Level | What ran |
|---|---|
| **S0 Runtime Observed** | The repository's unpatched `EvaluationPipeline` (sha-256 `4b259d33…f4fc`) at baseline `107b795d9454a89ea5f5752d6a7ccf7053a6e7e0`; asserted by every scenario. |
| **Candidate Experimental Runtime** | The same baseline exported with `git archive` into `results/work/candidate` + `patches/eq-candidates.patch` (one file, pure additions to private members, sha-256 pinned; `build_candidate.sh` refuses a wrong target hash (exit 3), a drifted/inapplicable patch (4), an absent patch (5), modified patch bytes (6), a wrong patched hash (7)). Variants are selected with env `WSB_CANDIDATE`: `EQN`, `EQW`, `EQL`. |
| **Reference (not re-run)** | PR #104's published candidates (RL/PW/P2/A1) are read from `../expected/` only for the comparison table. |

Variants: **EQL** = recommended base design; **EQW** = alternative (LOW rule kept, enforced only with fresh evidence); **EQN** = rejected ablation. Test-only fault
injectors (`EQ_MUTATE=ungated|no_marker|no_previous|long_marker|low_enforce|low_narrow`) exist only to prove that the assertions can fail and to reproduce the LOW-scope options.

## 2. Reproduce

```bash
composer install
bash investigations/workstream-b-anti-evasion/design/run.sh              # builds the candidate tree, runs everything, diffs against expected/
UPDATE=1 bash investigations/workstream-b-anti-evasion/design/run.sh     # regenerate expected/ (review the git diff!)
REDIS_SPOTCHECK=0 bash investigations/workstream-b-anti-evasion/design/run.sh   # skip real Redis: every Redis gate becomes UNPROVEN
DSG_REDIS_LIVE=0 bash investigations/workstream-b-anti-evasion/design/run.sh    # skip only the 63 s live-recovery check (UNPROVEN)
```

Recorded versions: `results/actual/versions.txt` after a run (PHP 8.5.9, Redis server 8.10.0 at the time of writing). Runtime ≈ 8 minutes (two 63 s live-recovery waits).

**Redis safety (inherits F103-01 / PR #104):** `run.sh` starts its own loopback instances (`--bind 127.0.0.1 --protected-mode yes`, no persistence), proves with a decoy server holding a
sentinel key that foreign / mismatched-pid / mismatched-dir / non-empty servers are refused (rc 3,3,3,3, sentinel intact), ignores `REDIS_HOST`/`REDIS_PORT`, never issues FLUSH*/CONFIG SET/DEBUG,
uses its own namespaces; workers verify process id + working directory before touching anything.

## 3. Files

| Path | Purpose |
|---|---|
| `DESIGN.md` | The design, contract delta, regression table, cost, Owner decisions, follow-up flow |
| `patches/eq-candidates.patch`, `build_candidate.sh` | Pinned experimental runtime and its refusing build recipe |
| `lib/dsg.php`, `fixtures/design.php` | Harness extension (provenance, D1–D7 streams, cell lists, operation-counting store) |
| `scenarios/d01_matrix.php` | Attack-vs-legitimate matrix: 36 streams × Login (3 flows) + login-anon / OTP / API_OVERUSE / custom API_OVERUSE, each with a no-correlation control |
| `scenarios/d02_recovery_boundaries.php` | Exact 60/600/1800 s boundaries, first denial, recovery, renewal, evidence window, sustained-leak bound |
| `scenarios/d03_rotation_ephemeral_failure.php` | Current/Previous continuity (sets, markers, blocks), ephemeral overflow, backend-failure propagation, minimal custom store |
| `scenarios/d04_state_bounds_ops.php` | Caps, TTL hygiene, key growth, exact per-command store-operation counts |
| `scenarios/d05_redis.php` | Real Redis: semantics, TTL hygiene, concurrency (cap race, duplicate race, marker atomicity), live recovery on the Redis clock |
| `scenarios/d06_api_surface.php` | Reflection fingerprint of the public surface of all 99 package classes (+ sensitivity controls) |
| `scenarios/d07_low_scope.php` | The LOW-scope options (advisory / narrow / wide) on one runtime, for OD-1 |
| PR #104 `a06`, `a07` | Indistinguishability and DEC-003 behaviour, run unchanged on every variant |
| `report.php` | 16 gates + verdict + generated `summary.md`; **fails closed** (exit 5) on missing / empty / malformed evidence |
| `run.sh` | Everything above, plus self-tests that must *detect* mutations, mislabelled provenance, bad build inputs and damaged evidence |

## 4. Gates

`G1` public API surface identical · `G2` store contract (existing base operations only; minimal custom store runs) · `G3` independent protections unchanged (no-correlation control equals S0 in every cell) ·
`G4` benign traffic not denied by correlation · `G5` recovery (controlled + live Redis clock) · `G6` attacker verification vs S0 (every attack cell classified, never averaged) · `G7` Redis concurrency / atomic evidence ·
`G8` rotation + ephemeral · `G9` N-1 WATCH boundary equals S0 · `G10` bounded state / TTL · `G11` failure propagation · `G12` DEC-003 behaviour · `G13` lifecycle coverage (pre+out / record-only / check-only; API_OVERUSE) ·
`G14` cost · `G15` indistinguishability honesty · `G16` provenance and S0 = PR #104 baseline. Vocabulary: `PASS | FAIL | UNPROVEN | NOT_APPLICABLE | OWNER_DECISION_REQUIRED`.
