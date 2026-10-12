# Workstream B — Anti-Evasion Security Validation (#97, #100)

**Status: EVIDENCE ONLY — Draft, must stay unmerged.** No change to `src/`, `tests/`, `docs/`, `DECISION_MATRIX.md`, thresholds, ADRs,
CI or any public contract. Nothing here selects, approves or recommends a policy. Everything lives under
`investigations/workstream-b-anti-evasion/` (outside PHPUnit/PHPStan/php-cs-fixer scopes; PHP files are still syntax-checked and
whitespace-checked by the repository CI scripts, which scan every tracked file).

Builds on `investigations/workstream-b-correlation/` (PR #103): its `Lab` harness (REAL `EvaluationPipeline` + REAL
`DeviceIdentityResolver` + reference stores + controlled clock), its published streams L1–L4 / X1–X8 (used **unchanged**) and its
Redis-safety model are reused, not copied.

## 1. Evidence levels (never merged into one result)

| Level | What ran | Where |
|---|---|---|
| **S0 Runtime Observed** | The repository's **unpatched** `EvaluationPipeline` (sha256 `4b259d33…f4fc`, asserted by every scenario) at `56f601cdc9a7062391395c326f1867d472a4ee49` | repository checkout |
| **Candidate Experimental Runtime** | The same baseline exported with `git archive` into an isolated directory + `patches/wsb-candidates.patch` (only `src/Service/EvaluationPipeline.php`, hash after patch `d0c6b07a…`) selected with env `WSB_CANDIDATE`. Built by `build_candidate.sh`, which fails with exit 3 if the target hash differs and exit 4 if the patch no longer applies (both proven by self-tests). A candidate label pointed at the baseline tree is refused (exit 4). | `results/work/candidate` (git-ignored scratch) |
| **Offline Prediction** | The PR #103 policy model (`P`, `A2`, `P0` have **no** runtime implementation and remain offline only). Compared with the experimental runtime in `expected/summary.md`; it differs where the model lacks K4 / K1-spray / score protections or assumes the M2 distinct-arrival WATCH. | `workstream-b-correlation/` |

Candidates (experimental, **not** policy proposals):

| Id | Definition (as implemented in the patch) |
|---|---|
| **A1** | Runtime *control*: a triggered rule never enforces when device confidence is LOW. (Not the model's A1: no M2, which needs a store-contract signal.) |
| **PW** | Triggered rule enforces only with ≥ 3 distinct **failing** members as evidence (K2 scope: fingerprints; fingerprint scope: K1s; bounded sets cap 3, TTL 600) **and** only after a *fresh failure* since the last block expired (per-window latch, 60 s windows). LOW dilution never enforces. State built only from existing store capabilities. |
| **P2** | PW + per-UA failure-ratio gate for LOW dilution (per-window failure/success counters + capped failing-K1 sets). |
| **RL** (extra hypothesis, task §6) | **Recovery latch only**: S0 detection and enforcement are unchanged except that, after a correlation block expires, re-issuing it requires a *new distinct arrival* in the set (count above a per-set tally, or a member rejected at the cap) since expiry. Outcome-independent: no failure evidence, no LOW exemption, no new trust assumption. |

Documented limitations of the experimental code: current-secret only (no previous-generation bridging for the PW/P2 witness state), no IPv6
macro-scope witnesses, window-sum/tally approximations (read-then-increment pairs), ratio denominator summed over windows (not union).

## 2. Reproduce

```bash
composer install
bash investigations/workstream-b-anti-evasion/run.sh              # builds the candidate tree, runs everything, diffs against expected/
UPDATE=1 bash investigations/workstream-b-anti-evasion/run.sh     # regenerate expected/ (review the git diff!)
REDIS_SPOTCHECK=0 bash investigations/workstream-b-anti-evasion/run.sh
```

Recorded versions: PHP 8.5.9, Composer 2.10.2, Redis server 8.10.0, PHPUnit 11.5.x (`results/actual/versions.txt`, `candidate_build_info.txt`
after a run). Stages: `a01_matrix` (attack-vs-legitimate, 3 Host flows + policy shapes), `a02_boundaries` (exact 60/600/1800 s boundaries,
recovery, re-issue), `a03_rotation_ephemeral_backend`, `a04_state_bounds` (memory exhaustion, caps), `a05_redis` (real Redis, concurrency),
`a06_indistinguishability`, `a07_dec003_behavior`, `report.php` (gates + generated `summary.md`), then **self-tests** that must detect: removed storage cap,
removed freshness/recovery, S0 with correlation silently removed, moved block boundary, moved DEC-003 cycle clock, provenance mislabelling, absent/modified/drifted patch, and nine fail-closed evidence-deletion cases.

**Redis safety (inherits F103-01):** `run.sh` starts its own loopback instances (`--bind 127.0.0.1 --protected-mode yes`, no persistence),
proves with a decoy server holding a sentinel key that foreign / mismatched-pid / mismatched-dir / non-empty servers are refused (rc 3,3,3,3,
sentinel intact), ignores `REDIS_HOST`/`REDIS_PORT`, never issues FLUSH*/CONFIG SET/DEBUG, uses its own namespaces. Concurrency workers verify
process id + working directory before touching anything.

### 2.1 Review corrections (F104-01 … F104-03)

* **F104-01 — patch committed and pinned.** The repository ignores `*.patch` globally, so `patches/wsb-candidates.patch` had not been added. `patches/.gitignore`
  now carries a scoped exception for that one file. `build_candidate.sh` pins the patch sha-256 (`7643bf01…44db1`) and the patched
  `EvaluationPipeline.php` sha-256 (`fc681175…929b`), and refuses: target hash mismatch (exit 3), patch drift (4), patch absent (5), patch bytes modified (6),
  patched target different (7). Self-test `selftest_build_recipe_refuses_bad_inputs` proves 3/5/6/4. The final run was repeated from a fresh clean clone (see the PR).
* **F104-02 — fail-closed gates.** `report.php` validates required evidence before any verdict: every matrix cell (`wsbMatrixCells()`), per-variant scenario files, the
  required assertion / row identifiers of A02–A07, four A06 pairs, and `redis_status.txt` (`RAN` / `SKIPPED`, written by `run.sh`). Missing, empty or malformed input
  gives the gate `UNPROVEN`, writes `evidence_problems.txt` and exits 5; an intentionally skipped Redis stage (`REDIS_SPOTCHECK=0`) gives `UNPROVEN` for A.4/A.5 and is never
  counted as proven. Gate D2 requires the four `indistinguishable: I1…I4` assertions per variant (not only the four `A06.pair` records), and every A01 cell is validated for the twelve core integer fields (present, integer, non-negative, `denied = byCorrelation + other`, counts ≤ attempts) — a missing field such as `legitDeniedByCorrelation` makes B/C/D/A.6 `UNPROVEN`. Eleven self-tests (`selftest_failclosed_*`) delete or damage representative A02/A03/A04/A05/A07/matrix/status evidence and require exit code + no PASS.
  Legitimate `NOT_APPLICABLE` (A.5 for S0/A1) is kept.
* **F104-03 — DEC-003 by behaviour.** The patch-text regex is relabelled `A.7s` (INFO, "limited structural scope check, NOT a DEC-003 proof"). Gate `A.7` now comes from
  `scenarios/a07_dec003_behavior.php` on every variant: transition-only cycles with strictly increasing counts; pause activations and paused-decay seconds equal to a DEC-003
  reference computed from the cycle times the runtime itself issued (so intended policy differences — a variant issuing different blocks — are separated from contract breakage);
  no correlation / witness / cycle mutation while a persisted block is active; one logical history across Current/Previous (UNPROVEN if the variant issues no post-rotation
  block); a single failure creates no cycle. Self-test `selftest_dec003_cycle_pause_semantics_s0` moves the cycle clock by 1 s and must be detected.

## 3. Threat matrix → streams

Published `L1–L4, L1f, L2, L3, X1–X8` unchanged. Added: `Y1` combined IP+FP+account rotation; `Y2a/b/c/c2` N−1 gaming (601 s vs 1801 s
cycles; churn and LOW dilution); `Y4b/c/d/e/f` shared-K2 poisoning (cheap burst, sustained, zero-failure, K4-proof insider, 2-account pool);
`Y6b/c` recovery abuse; `Y7` fingerprint-pool cycler; `Y9a/b` IPv6 `/64` and `/48`. Each stream has a controls table (who controls IP / UA /
fingerprint / account, and where the outcome comes from) in `fixtures/anti_evasion.php::wsbControls()` and an attack class
(`evasion | poisoning | both | benign`) that decides which metric is the objective. Memory exhaustion = `a04`/`a05`; command duplication /
record-only / check-only = the three flows of `a01`; Login with and without account id, OTP, API_OVERUSE (Host only calls `recordSuccess`) and a
custom API_OVERUSE policy = the shapes of `a01`.

Per cell the matrix records: attacker attempts / reaching verification / denied (split into *correlation-attributable* vs *other*, by a paired
control run with churn/dilution correlation disabled), first denial second, legitimate attempts / denied / attributable / first and last
denial second / first allowed after the last denial, block issuances **by enforcement-key scope** (K1–K5, from the DEC-003 cycle log),
Retry-After values and levels of denials. *Winning source* is not exposed by the public result; it is attributed by the paired control.

## 4. Results (generated — see `expected/summary.md` for every table; `expected/gates.jsonl` is the machine-readable gate list)

### 4.1 What S0 detects / misses (runtime observed)
Detects: fingerprint churn (X1, attacker reaches verification 2×), distributed LOW (X2: 6 of 121), IPv6 `/48` macro scope (Y9b: 3 of 12), combined
rotation partially (Y1: 8 of 121). **Misses or only partly stops:** IP+FP rotation without an account (X3 anonymous: 121 of 121), slow-roll (X5),
spaced N−1 hover (Y2b/Y2c2 in record-only: 0 denied), padded ratio is *not* an S0 weakness (X7 6 of 121) — and the fingerprint-pool cycler Y7 is stopped
(3 of 241). K4 hides correlation on single-account streams (X1, X3, Y2): use the `login-anon` shape for the pure correlation signal.
The published double observation by `pre+out` also makes N−1 gaming look better in that flow than in record-only.

### 4.2 Candidate gates (PASS / FAIL / UNPROVEN / NOT_APPLICABLE; `(owner)` = needs an Owner decision)

| Gate | A1 | PW | P2 | RL |
|---|---|---|---|---|
| A.1 rotation continuity | PASS | **FAIL** | **FAIL** | PASS |
| A.2 N−1 WATCH preserved | PASS | **FAIL** (owner) | **FAIL** (owner) | PASS |
| A.3 backend failure propagates | PASS | PASS | PASS | PASS |
| A.4 bounded storage / TTL on every key | PASS | PASS | PASS | PASS |
| A.5 atomic evidence under concurrency (real Redis) | N/A | **FAIL** (owner) | **FAIL** (owner) | UNPROVEN |
| A.6 lifecycle coverage (record-only, check-only, API_OVERUSE) | **FAIL** | **FAIL** | **FAIL** | PASS |
| A.7 DEC-003 **behaviour** (A07: cycle transitions, 2-cycle/600 s pause vs a reference computed from the runtime cycle times, no mutation under an active block, Current/Previous continuity) | PASS | PASS | PASS | PASS |
| A.8 §5.3 normative text unchanged | **FAIL** (owner) | **FAIL** (owner) | **FAIL** (owner) | **FAIL** (owner) |
| B security regression vs S0 | **FAIL** | **FAIL** | **FAIL** | **FAIL** (Y7 only) |
| C fairness under paid poisoning | **FAIL** | **FAIL** | **FAIL** | **FAIL** |
| D residual threats | analysed | analysed | analysed | analysed |

Key measured facts behind the gates:

* **PW**: removes the shared-NAT false positives (L1 0/134, L2 0/121) but the attacker reaches verification **121×** on X2/X7 (S0: 6), 12× on
  Y9b (S0: 3), 16× on Y1 (S0: 8); hosts that only call `checkOnly`, or only `recordSuccess` (API_OVERUSE), get **no** correlation at all; a pair split across
  a key rotation is **not** convicted; the WATCH boundary (1800 s) is lost. Recovery improves (lone legitimate client first allowed at 62 s instead of
  601 s). **Real Redis:** the evidence tally is a read-then-increment pair; 8 concurrent failures of **one** fingerprint inflate it to ≥ 3 (the evidence
  rule is satisfied by a single fingerprint) — fixing this needs an atomic "added" signal = store-contract change.
* **P2**: restores X2 (7) but X7 (success padding on the attacker's own account) evades fully (121) and X8 (8-IP botnet) weaponizes it
  (48 of 50 legitimate users denied, S0: 49).
* **A1**: LOW exemption alone loses X2/X2L/X7 and LOW N−1 (Y2c) entirely and fixes nothing for fingerprinted NAT clients.
* **RL** (hypothesis): L1 127→14 of 134, L1f 127→59 (K4 typo blocks remain), recovery abuse Y6b/c improved, every published X1–X8 evasion result
  unchanged (X2 6, X7 6, X8 4, Y9b 3), lifecycle coverage kept. **Does not fix:** LOW populations (L2/L3 104 of 121), botnet collateral (X8 49/50),
  poisoning with a *fresh* member per block cycle (cost: zero failures). **Loses:** the fingerprint-pool cycler Y7 (record-only: 36 of 241 denied vs 238;
  `pre+out`: reach 5 vs 3). Latch race safety UNPROVEN (needs a post-expiry concurrent test; Redis owns time).
* **Collateral that no correlation candidate touches:** with an attacker that rotates accounts, K1 credential-spray / K1 score blocks the shared IP in
  `pre+out` flows even with churn/dilution correlation disabled (`C2` tables, "with correlation disabled" column). A candidate cannot be called a
  Shared-K2 collateral fix while that residual exists.

### 4.3 Indistinguishability (A06)
Four attacker/legitimate pairs carry byte-identical observable tuples (`t, ip, UA, fingerprint, account id, outcome`; equal sha256) and are treated
identically on every variant: cookie-clearing colleagues mistyping vs a fingerprint-rotating insider; a password-expiry failure wave vs distributed LOW
guessing; a shared account between typos vs success padding; a roaming device vs a replayed fingerprint. No policy over these inputs can separate
them. Outcomes are Host-provided and attacker-forgeable (X7), so they cannot be a trusted exoneration signal. Remaining architectural options
(none implemented, none recommended): a Host-attested request identity / verified-outcome signal (new trust assumption, new contract); a signed
device token with server-side binding (new Public API); per-account (not per-IP/UA) correlation (changes the model); accept the collateral and
document it (status quo, S0).

## 5. Decision matrix (for the Owner — no option is selected here)

| Option | Gains (measured) | Losses (measured) | Needs |
|---|---|---|---|
| O1 keep S0 | all published evasion results; Y7 stopped | shared-NAT FP (L1 127/134), LOW population FP (L2 104/121), 94 % of K2 decay paused (#103) | nothing |
| O2 evidence gating (PW) | NAT FP removed; fast recovery | X2/X7/Y9b/Y1 evasion, check-only & API_OVERUSE hosts blind, WATCH and rotation continuity lost, concurrency-forgeable evidence | contract §5.3/§5.0 change, rotation-capable witness store, atomic "added" primitive |
| O3 + ratio gate (P2) | restores X2 | X7 evades, X8 weaponizes, same as O2 | same as O2 + ratio state |
| O4 LOW advisory (A1) | LOW FP removed | X2/X7/Y2c evasion | contract change |
| O5 recovery latch (RL) | NAT FP largely removed (127→14), recovery abuse reduced, no evasion loss on X1–X8/Y9b | Y7 pool cycler, LOW FP and botnet collateral unchanged, free fresh-member poisoning, latch concurrency unproven | contract §5.3 text change; Owner decision on the Y7 trade-off |
| O6 new signal (attested outcome / device token) | could separate the A06 pairs | new trust assumption / Public API | ADR + new contract |

**Engineering conclusion: No candidate passes the gates.** PW and P2 trade a large, measured evasion loss and new forgeable inputs for the FP gain.
RL is the only hypothesis that improves false positives without losing any published X1–X8 evasion result, but it still fails gate B (Y7) and gate C
(free fresh-member poisoning, botnet collateral) and is unproven under concurrency. The decision between O1 and O5, and whether the Y7 loss is
acceptable, is an Owner Security/Availability decision (Owner Decision Required). No ADR, contract change, runtime fix or merge is requested here.

## 6. What was and was not proven

* **S0 Runtime Observed:** all matrix, boundary, rotation, ephemeral, memory-cap, backend-failure and real-Redis results for the unpatched pipeline.
* **Candidate Experimental Runtime:** the same battery for A1/PW/P2/RL on the isolated patched copy; **not** production-verified.
* **Offline / UNPROVEN:** `P`, `A2`, `P0`; Redis time boundaries (Redis owns time, DEC-016: only immediate sequences on real Redis, every exact boundary is on the
  controlled clock); RL latch concurrency; IPv6 witness state; any production traffic distribution (streams are small and synthetic).
* Exact boundaries are fixed TTL from creation, not sliding; the double observation of `pre+out` and the K4 / K1-spray protections change which
  protection fires — hence the attribution columns.
