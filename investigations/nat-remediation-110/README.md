# #110 plan 1 — OQC (Outcome-Qualified Correlation) candidate measurement

**Status: EVIDENCE ONLY — Draft PR for independent review.** Nothing here changes `src/`, `tests/`, `docs/`, any ADR/Decision, Composer, CI or any
public / Redis / store contract, and nothing here selects, approves or recommends a policy. Everything lives under `investigations/nat-remediation-110/`
(outside the PHPUnit / PHPStan / php-cs-fixer scopes and not part of the package contract). DEC-019 stays ACTIVE and binding.

Related: [#110](https://github.com/Maatify/php-rate-limiter/issues/110) (the comparative study this measures plan 1 of), [#97](https://github.com/Maatify/php-rate-limiter/issues/97),
[#100](https://github.com/Maatify/php-rate-limiter/issues/100) and [#106](https://github.com/Maatify/php-rate-limiter/issues/106) (**not** closed or resolved here).

Baseline: `Maatify/php-rate-limiter` `origin/main@63fbb4abf0a6d7899d063d1fbd5d6fc7a1920ba1` (merged #109). The study reuses, unchanged, the PR #103 `Lab`
(REAL `EvaluationPipeline` + REAL `DeviceIdentityResolver` + reference stores + controlled clock), the PR #104 anti-evasion streams and Redis-safety model, the
PR #105 D streams and the PR #108 K1 harness (per-request attribution, K1 streams). **Result and numbers: [`REPORT.md`](REPORT.md); every table is generated in
[`expected/summary.md`](expected/summary.md).**

## 1. Question

Measure, on the real pipeline, the plan-1 candidate of #110 against S0 on the #110 protection / fairness matrix, in the three Host flows, with rotation, IPv6,
storage bounds, rollback and real Redis — so the Owner can decide DEC-020 on numbers. Three arms are measured as #110 lists them:

| Arm | What it is |
|---|---|
| **S0** | the repository's unpatched runtime (level `S0_RUNTIME_OBSERVED`, sha-256 `4b259d33…f4fc`) |
| **OQC** | plan 1: for policies with the `CREDENTIAL_SPRAY` or `DISTRIBUTED_ACCOUNT` capability, K1 spray and K2 churn are observed **and** evaluated only when the scope is *outcome-live* and the command is `recordFailure`, or when the scope is *silent* (then S0 exactly). A scope is outcome-live when any outcome command was processed for it in the current or the previous 600 s window (two window-id marker keys per scope, TTL 1200 s, like S0's `dilution_confirmation`), so liveness lasts 600–1200 s after the last reported outcome. Markers are written **after** the snapshot (the outcome that makes a silent scope live never observes). Scopes: K1 = IP, K2 = IP+UA, every active IPv6 macro hierarchy scope (own markers). Marker keys are a plain sha-256 of the already-opaque scope key (not derived from the rotating secret); Current and Previous keys are read, Current is written. Dilution, K3/K4/K5, WATCH, thresholds, TTLs, the ladder, trusted advisory, `API_OVERUSE` and every Public/Store contract are untouched; the state uses only the existing WATCH-flag capability. |
| **OQCW** | OQC + the K1 WATCH counts *new members* only (#110 arm 1b / Q1) |
| **OQCE** | OQC + re-issue escalation on **live** scopes: 1st block L2/60 s, 2nd L3/300 s, 3rd+ L4/1800 s within 1800 s, per K1 / K2 scope (#110 arm 1d). Silent scopes and checkOnly-only Hosts keep S0 exactly. |

The candidate is the committed `patches/oqc-candidates.patch` (one file, `src/Service/EvaluationPipeline.php`, sha-256 pinned before and after) applied by
`build_candidate.sh` to a `git archive` of the baseline in an isolated tree; the arm is selected at run time by env `OQC_ARM`, which the patched pipeline reads.
**The design answers of #110 taken for this candidate** (all open for the Owner): Q2 silent-phase evidence is kept (no reset); Q3 one marker pair per observed
scope, not secret-derived, Current+Previous read; Q4 K1 is *moved* from pre-check to `recordFailure` on live scopes, with the snapshot-before/write-after order
that makes exactly one observation per logical attempt (proved in O02 C1/C2). Two design corrections were forced by the measurements themselves and are part
of the committed patch: the liveness marker is two 600 s windows / TTL 1200 (a fixed 600 s marker expired during block chains and recreated the #100 loop
inside OQC — D2 was 53/121 with it, 30/121 without), and IPv6 macro scopes carry their own markers (otherwise every colleague's first request in a new /64 fed
the /48 spray scope and the all-success office was denied 5/31).

## 2. What is covered

| File | Content |
|---|---|
| `scenarios/o01_matrix.php` | 68 streams × 3 flows on Login, plus OTP / Login-without-account / `API_OVERUSE` / custom-API cells, **per arm** (317 rows each): the #108 K1 streams, the #104 L/X/Y streams, the #105 D1/D5/D6/D7 streams and the #110 streams (`ABA_B`, `ABA_B1`, `ABA_A`, `K1R4`, `SP5c`, `GAP`, `OTPB`). S0 invariants (hard-fail) reproduce the published numbers **and the #100 Lead control `ABA_A` 3/120** (until now executor-attested only) |
| `scenarios/o02_targeted.php` | A/B/A pair (t=62 probe, attacker timeline and bound), exactly one K1 observation per logical attempt (pre+out and record-only), marker lifecycle (write on first outcome, fixed per-window TTL 1200, no renewal inside a window), success / padding never erases failure evidence, typo cost on a 3-device NAT, the WATCH gap after a 700 s silence |
| `scenarios/o03_rotation_ipv6.php` | Current/Previous rotation in three modes (outer secret + fingerprint bridged / outer only / fingerprint secret only) mid-stream on 6 streams vs the no-rotation reference on the same arm; IPv6 /48 office (successes, typos), attacker rotating /64s of one /48 and /48s of one /40 |
| `scenarios/o04_bounds_rollback.php` | exact marker keys and expiry for one scope, 2 keys per scope (3 on OQCW) for fifty scopes and 0 per request, every arm key expired 1801 s after a stream, rollback (old rule on candidate state), mixed version (alternating old/new per request) |
| `scenarios/o05_redis.php` | real Redis (instance owned and proven by `run.sh`): sequential decision parity with the reference store (A/B/A pair, spray, record-only), TTL hygiene, the two liveness markers on the adapter |
| `scenarios/o06_concurrency.php` (+ `o06_worker.php`) | real Redis, **concurrency** (F111-02): 16 simultaneous distinct failing subjects on a live K1 scope, 8 simultaneous distinct fingerprints on a live K2 scope, the same K1 burst on a silent scope (control), the block after each burst, the highest block level issued inside one burst; two-phase Redis-clock barrier (every worker's `checkOnly` precedes every worker's `recordFailure`) |
| `report.php` | fail-closed gates G1–G10 per arm (exit 5 on any missing / malformed / mislabelled evidence; a violated candidate assertion or bound is a **finding**, not an evidence problem), generated `summary.md` |

**Attribution** is the PR #108 method unchanged: for every request the harness records the persisted blocks active on its canonical keys before it, the blocks it
issued (DEC-003 cycle log → enforcement key) and the correlation signals the store observed during it. `correlationIssuances` (K1 + K2 blocks issued) is the
"trigger" count behind the protection bound of gate G4: extra attacker verifications vs S0 ≤ triggers + max(0, 5 − S0 reach) + 1.

## 3. Reproduce

```bash
composer install
bash investigations/nat-remediation-110/run.sh                 # everything, diffed against expected/ (~2 min, 4 arms concurrently)
UPDATE=1 bash investigations/nat-remediation-110/run.sh        # regenerate expected/ (review the git diff!)
REDIS_SPOTCHECK=0 bash investigations/nat-remediation-110/run.sh   # gate G9 becomes UNPROVEN, never PASS
```

Recorded versions are written to `results/actual/versions.txt` (`results/` is git-ignored scratch; the committed evidence is `expected/`). The run **fails closed**:
the baseline gate refuses any change under `src/` or the Composer files; every scenario aborts with exit 4 unless the loaded pipeline is the pinned unpatched
baseline (arm S0, repository tree) or the pinned patched candidate (candidate arms, isolated tree); `report.php` exits 5 and marks every gate UNPROVEN on
missing, malformed or mislabelled evidence.

**Redis safety (inherits F103-01 / PR #104).** `run.sh` starts its own loopback instances, proves with a decoy server that foreign / mismatched / non-empty
servers are refused (rc 3,3,3,3, sentinel intact), ignores `REDIS_HOST` / `REDIS_PORT`, never issues `FLUSH*` / `CONFIG SET` / `DEBUG` / `SHUTDOWN` against
anything it did not start. Raw Redis TTL values depend on wall time and are never recorded.

**Self-tests (negative controls, all in `run.sh`):** a candidate arm on the repository tree, the S0 arm on the candidate tree and an unknown arm must be refused
(exit 4); a drifted patch must be refused by the hash pin (exit 6) and by `git apply` when the pin is bypassed (exit 4); a violated candidate assertion must be
fatal under `OQC_STRICT=1`; fourteen fail-closed cases (deleted per-arm files, a deleted matrix cell, a removed field, a deleted assertion row, forged S0 and
candidate pipeline hashes, a swapped arm label, an unknown assertion row, deleted Redis and concurrency evidence) must all make `report.php` exit 5 with no PASS gate.
