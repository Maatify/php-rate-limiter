# Workstream B — Correlation Security Evidence (#97, #100)

**Status: EVIDENCE ONLY — Draft, must stay unmerged.** No change to `src/`, `docs/`, `DECISION_MATRIX.md §5.3`,
`DEVICE_FINGERPRINT.md`, `KEY_STRATEGY.md`, thresholds, ADRs or any runtime path. Nothing here selects, recommends or
implements a policy. Everything under `investigations/workstream-b-correlation/` is outside `src/`, `tests/`, `examples/`
(so it is outside PHPUnit suites, PHPStan and php-cs-fixer scopes) and is not part of the package contract.

Related: [#97](https://github.com/Maatify/php-rate-limiter/issues/97), [#100](https://github.com/Maatify/php-rate-limiter/issues/100).
Independent of #98 and #99.

## 0. Revision 2 — response to the Direct Lead Review of `37c3558` (F103-01 … F103-05)

| Finding | Correction (files) | Evidence |
|---|---|---|
| **F103-01** unsafe `FLUSHDB` | `scenarios/s08_redis_spotcheck.php` no longer reads `REDIS_HOST`/`REDIS_PORT`, never issues `FLUSHDB`/`FLUSHALL`/`CONFIG SET`, connects to `127.0.0.1` only, and uses a separate key namespace per scenario. It proceeds only if `run.sh` proves ownership: the server's `process_id` equals the pid of the instance `run.sh` started, its `dir` equals the directory `run.sh` created, and it is empty (`DBSIZE 0`). `run.sh` starts its own instances with `--bind 127.0.0.1 --protected-mode yes`, on a free port. | `run.sh` stage `s08_redis_safety`: starts a **decoy** Redis holding a sentinel key and proves that (1) a foreign server selected via inherited `REDIS_HOST/REDIS_PORT`, (2) a mismatched pid, (3) a mismatched dir and (4) a non-empty server with matching pid/dir are all **refused (exit 3)** and the sentinel stays intact; the real run then executes with a hostile `REDIS_HOST=198.51.100.1` that is ignored. Output: `s08_redis_safety PASS (… rc=3,3,3,3; decoy sentinel intact)`. |
| **F103-02** witnesses before the active-block check | `model/PolicyModel.php::observeCommand()` now checks active K2/K3 blocks **first** and returns before any witness/ratio write, exactly like `EvaluationPipeline::process()`. | New `model/regression.php` → `expected/model_regression.jsonl`: record-only host, attacker keeps failing during the 60 s block, then only a lone success after expiry. Correct model: `80:A, 85:A`, 0 witness writes while denied. The rejected legacy ordering (kept behind a flag) writes 7 witnesses from denied requests and **re-blocks at 80**. Matrix goldens regenerated. |
| **F103-03** boolean-only crosscheck | `model/crosscheck.php` now compares, for **14 streams × 3 flows (pre+out, record-only, checkOnly-only) = 42 runs / 4,683 events**: per-step decision, block level and retry-after, `reachedVerification`, the persisted **K2 and K3 remaining seconds** for each event's keys, and the total number of persisted hard-block issuances against the real `HardBlockCycleStoreInterface` log (2,640 cycles). Gate in `run.sh`: every counter must be 0. | `expected/model_crosscheck.jsonl`: **0 mismatches** on all of the above. **Not compared** (opaque or outside the model): WATCH counters, distinct-set contents, scores, K1/K4/K5 state, winning `source`. The equivalence claim is therefore limited to S0 on those observables and these fixtures; **A1/A2/P/PW/P2/P0 remain unverified offline predictions.** |
| **F103-04** harness flow mismatch | `Lab::attempt()` now uses host-realistic semantics for `pre+out`: the outcome is recorded **only if `checkOnly` allowed**, like `replayStream()`. The unconditional double call survives only as the explicitly named **`pre+out-unconditional` — INVALID CONTROL** flow (extra rows in `s04`). Independent `pre` and `out` flows are kept. | All goldens regenerated; headline numbers unchanged (`104/121`, `127/134`, `63/67`, `64/67`, all `s04` first-block positions and persisted K2 state identical to the previous goldens) because a denied request returns before any mutation — only the displayed `steps` strings and operation counters changed. |
| **F103-05** unbounded model state | The model's witness structures are now explicitly typed. **P, P0, A2 = IDEALIZED-UNBOUNDED** timestamped witnesses (labelled in every `F05.matrix` row, field `state`). **PW and P2 = bounded window model**: failure witnesses in 60 s window sets capped at 3 members per (scope, window), 10 windows kept, older windows evicted; ratio state = two integers per UA per window plus a failing-K1 set capped at 6. | `peakBoundedStateEntries` is now reported: PW peaks at 10–54 entries on benign/single-K2 streams but **230 (X2), 232 (X2L), 320 (X3), 193 (X8)** where an attacker creates many distinct K2 scopes: per-scope caps hold, the **total** scales with distinct anchors. P2 shows 17 on X2 only because it denies the attacker (denied requests write nothing). No claim is made that witness limits are implemented, nor that rotation/ephemeral behaviour of witness state works — neither is modelled. |

**Additional correction found while re-running (not a reviewer finding):** the Redis stage intermittently failed (`invalid snapshot`) when a wall-clock second ticked during the run, because it paired a frozen PHP clock with Redis-owned time (DEC-016). The stage's clock now reads `TIME` from the same Redis instance, `run.sh` waits for each instance to exit before starting the next (a dying instance removes its pidfile) and retries on a fresh owned instance. Verified: 5 consecutive full runs with `RUN_PHPUNIT=1`, all stages PASS, no leftover Redis processes.

## 1. Provenance (B-F01)

| Item | Value |
|---|---|
| Study baseline (original report) | `main@7b6a23d283dcf6e4d3afc79f64404e2630a30577` |
| Latest `origin/main` at evidence time | `1668a0cc39978ff68b63377adfbc059fe3bd61d1` (#99 / #102: bounded fallback Retry-After) |
| Published artifact compared | `v1.0.0-rc.3` = `d1f43635cb447b21c51754743dfe9bf710ab8b83` |
| Files changed between `7b6a23d` and `1668a0c` | `CHANGELOG.md`, `docs/FAILURE_SEMANTICS.md`, `docs/guides/USAGE_GUIDE.md`, `src/Service/LocalFallbackLimiter.php`, `src/Service/RateLimiterEngine.php`, `tests/System/RateLimiterEngineFallback*Test.php` — **no correlation, identity, key, store or DECISION_MATRIX/DEVICE_FINGERPRINT/KEY_STRATEGY file** |
| Integration of moved `main` | Only after comparing the touched files above; this branch had no commits, so it was fast-forwarded (`git merge --ff-only`) — no merge commit, no rebase of any work. The harness drives `EvaluationPipeline` directly (the engine only adds circuit-breaker/fallback, i.e. #99 scope). |
| Runtime | PHP 8.5.9, Composer 2.10.2, PHPUnit 11.5.57 (see `results/actual/versions.txt` after a run) |
| Result across artifacts | **All 11 deterministic stages are byte-identical** on `v1.0.0-rc.3`, `7b6a23d` and `1668a0c` (command below). |

```bash
# from the repository root
composer install
bash investigations/workstream-b-correlation/run.sh                       # current tree, diffs against expected/
bash investigations/workstream-b-correlation/reproduce-published.sh v1.0.0-rc.3
bash investigations/workstream-b-correlation/reproduce-published.sh 7b6a23d283dcf6e4d3afc79f64404e2630a30577
RUN_PHPUNIT=1 bash investigations/workstream-b-correlation/run.sh         # + the 3 existing correlation test files (45 tests / 749 assertions)
UPDATE=1 bash investigations/workstream-b-correlation/run.sh              # regenerate expected/ (review the git diff!)
```

Real-Redis stage: `run.sh` starts its **own** `redis-server` (and a decoy for the safety proof) on loopback, on the first free port from `REDIS_PORT` (default 6399); the PHP stage ignores `REDIS_HOST`/`REDIS_PORT` and verifies ownership before touching anything (§0, F103-01). `REDIS_SPOTCHECK=0` skips it. A long PHPUnit/CI run may be in progress on a machine —
`run.sh` never invokes the whole suite and never kills other processes.

## 2. Layout

```
lib/bootstrap.php            Lab: REAL EvaluationPipeline + REAL DeviceIdentityResolver, reference stores, FixedClock,
                             per-command counters, DEC-003 cycle recorder, churn/dilution-only disabler, Lcg (own PRNG)
lib/replay.php               host-realistic replay of fixture streams through the real pipeline
fixtures/streams.php         deterministic event streams L1–L4, X1–X8 shared by harness and model
scenarios/s01..s08           machine-readable JSONL scenario runners (below)
model/PolicyModel.php        OFFLINE model: S0 (current §5.3) + alternatives A1/A2/P/PW/P2/P0 + observation mechanisms
model/crosscheck.php         S0 model vs real pipeline: per step/level/retry, reached flag, K2/K3 remaining, cycle count (gate: all 0)
model/regression.php         F103-02 regression (denied record-only requests must not feed witnesses)
model/run_matrix.php         attack-vs-legitimate policy matrix (B-F05)
model/mechanisms.php         "once per logical attempt" mechanisms × host flows (B-F02)
expected/*.jsonl             golden machine outputs (diffed by run.sh)
```

## 3. Corrigendum to the first report (found while building this evidence)

1. The published first-report numbers for **P** (`51/81`, `11` missed) came from an earlier private variant that observed correlation
   **only at `checkOnly`**. It is preserved here as **`P0`** and reproduces them exactly (`X4 51/81 · 25/40`, `X1 50/61 · 11`). Measured below, `P0`
   disables correlation for hosts that only call `recordSuccess/recordFailure` — so it is **not** a valid candidate.
   The model's `P/PW/P2` now observe on every command with a distinct-arrival WATCH (mechanism M2) and **do not** improve X4/X6 collateral (`73/81` vs `79/81`).
2. Single legitimate client after a shared-K2 trigger: first unblocked second is **621**, not 600 (the last re-block, issued ≤ 599 s, lasts up to 60 s).
3. C1b (one new LOW user / 60 s): **24/41 (58 %)** blocked, not "≈ 50 %".
4. "R8 non-determinism" was imprecise: K3 confirmation is a **deterministic function of the wall-clock phase** of the set (table in §4.1).
5. DEC-003 interaction was inferred; it is now **measured** (§4.4) and is large.
6. "K4/spray/budget partially compensate" was unproven; it is now **measured** (§4.5) and is false for X2 and for any anonymous policy.

## 4. Mapping of the Lead findings to exact files, commands and outputs

### 4.1 B-F01 — executable evidence, provenance, the requested numbers
- Harness/model/fixtures/commands: this directory. Reported numbers reproduced by the **real pipeline** (`expected/s01_dilution.jsonl`, `s02_churn.jsonl`):
  - `104/121` — `C1.every-20s` · `127/134` — `B7.two-legit-clients-4000s` (A `63/67` + B `64/67`).
  - `51/81` and `11` — offline model `P0` rows of `expected/model_matrix.jsonl` (X4 / X1). The model is validated against the real pipeline:
    `expected/model_crosscheck.jsonl` shows **0 mismatches** on the observables listed in §0 (F103-03) over 42 runs / 4,683 events. That limits the claim to S0 on those observables; it is not a proof for any alternative policy.
- **The 45 tests** (`CorrelationBehaviorTest`, `BoundedCorrelationCoverageTest`, `Ipv6AdaptiveAggregationTest`): `RUN_PHPUNIT=1` → `tests=45 assertions=749 failures=0 errors=0`.
  Limits (counted with `grep`, not assumed): `CorrelationBehaviorTest` and `BoundedCorrelationCoverageTest` use **`checkOnly` only** (0 `recordSuccess`, 0 `recordFailure`);
  `Ipv6AdaptiveAggregationTest` has 1 `recordSuccess`, 0 `recordFailure`; none exercises benign independent clients, typos, record-only hosts,
  double observation, recovery timelines, WATCH carry-over, DEC-003 or collateral. They freeze the current outcome; they do not establish it as safe policy.
- R8 (wall-clock phase) — `expected/s01_dilution.jsonl` `R8.k3-confirmation-phase`: K3 confirmation fires only when the live 600 s set spans a wall-clock
  window boundary (creation offset 100→first K3 after 500 s, 300→300 s, 500→120 s; offsets 0 and 590 never fire).

### 4.2 B-F02 — lifecycle compatibility (`scenarios/s04_lifecycle_commands.php`, `model/mechanisms.php`)
Independent flows × policies measured on the **real pipeline**: Login, OTP, `ApiHeavyProtectionPolicy` (API_OVERUSE) and a custom API_OVERUSE subclass
(`custom_api_overuse`), each with `checkOnly+record`, `checkOnly-only`, `recordSuccess-only`, and (auth policies) `recordFailure-only`.
- Correlation fires in **every** command and **every** policy (`expected/s04_lifecycle_commands.jsonl`): shared-K2 churn blocks at logical attempt **2** with
  `checkOnly+record` (issued by the record step, 11 distinct-ops/2 WATCH-ops) and at **3** with a single command; LOW dilution at **5** vs **6**.
  So **record-only hosts, including API_OVERUSE hosts that only call `recordSuccess()`, are covered today.** Block is persisted on K2 and enforced for API_OVERUSE (`K2/K3` are API-heavy key types).
- Mechanisms on the model, same streams (`expected/model_mechanisms.jsonl`):
  | Mechanism | `checkOnly+record` | record-only | checkOnly-only | Needs |
  |---|---|---|---|---|
  | current (every command) | double-observes (L1 `127/134`) | `124/134` | `124/134` | — |
  | **M1** observe at checkOnly only | `124/134` | **`0/134` legit blocked but X1 attacker `0/61` blocked — correlation silently off** | `124/134` | nothing; **breaks record-only hosts** |
  | **M2** WATCH counts distinct arrivals | `8/134` | `8/134` | `8/134` | an `added` signal from the bounded-set result (today only `count/accepted`; `added` exists only on the snapshot variant) ⇒ **store-contract change** |
  | **M3** 2 s debounce per (scope,member) | `124/134` | `124/134` | `124/134` | no new identity, but is a **timing heuristic** and does not remove the block (A/B/A still repeats) |
- **Conclusion (a contract decision, not a proven invariant):** exact once-per-logical-attempt idempotence requires a request identity shared by `checkOnly` and `record*`;
  none exists. M1 breaks supported flows; M3 is heuristic; only M2 removes the *WATCH* double count without hosts adding anything, but it needs a store-contract signal. M2 does **not** by itself fix #97 (LOW dilution still saturates at the cap: L2 `102/121`).

### 4.3 B-F03 — evidence & attribution threat model (spec items marked PROPOSED / UNPROVEN; nothing implemented)
- **Who attests a failure:** only the host, by calling `recordFailure()`. The package cannot tell a typo from malice; a failure is evidence of "a credential check failed", never of intent.
  Therefore the only usable statistic is *distinct failing members*, not "a failure happened".
- **Proposed witness state (not accepted):** (a) per K2: bounded set of failing fingerprint HMACs, cap 3, TTL 600; (b) per fingerprint: bounded set of failing K1 HMACs, cap 3, TTL 600 — both via the
  existing `addDistinctBounded`/rotation capabilities, opaque HMAC members, policy+env domain-separated; (c) latch: WATCH-style counter. **Freshness "failure at/after block expiry" cannot be expressed with fixed-TTL sets**
  (no per-member timestamps). Two options: window-keyed sets (model `PW`, 60 s windows × 10, conservative by ≤ 60 s at the boundary) or a **new store capability** (contract decision). `P` (idealized timestamps) and `PW` (bounded windows) give identical outputs on every fixture, but only `PW` has any claim to boundedness (§0, F103-05).
- **Ordering at the boundary:** a block is active iff `expiresAt > now` (measured: `F04.block-expiry-boundary` t=79 blocked `+1s`, t=80 re-issued). A witness in the same 60 s window as expiry counts as fresh (PW). Not evidenced on the real runtime — specification only.
- **Current + previous / ephemeral:** witness sets would need the same coordinated bridge as churn (no Cartesian pairs); overflow members keep K2 witnesses but never create K3/K5.
  The real runtime evidence available today is only for the *existing* sets (§4.4); witness-set rotation/ephemeral behaviour is **UNPROVEN** until implemented.
- **Collateral bound X4 / weaponization X6 (model, `expected/model_matrix.jsonl`):** an attacker/insider sharing the K2 and failing with rotating fingerprints blocks the colleagues in every policy:
  legit blocked S0 `79/81`, A1 `78/81`, A2 `75/81`, P/PW/P2 `73/81`; **evidence-gating does not remove shared-K2 collateral and does not stop a same-NAT insider from weaponizing it** (the insider only needs failures, which cost nothing).
  Under S0 the insider needs no failures at all.
- **P2 ratio gate (bounded denominator = per-UA success/failure counters + capped failing-K1 set, 60 s windows × 10):**
  X8 weaponization — an 8-IP botnet failing on a popular UA blocks **48/50** legitimate LOW users (S0 `49/50`, P `0/50`);
  X7 evasion — padding each failure with two successful logins on the attacker's own account drops detection from `115/121` to **`0/121`**. P2 is both weaponizable and evadable ⇒ **not accepted by default**.

### 4.4 B-F04 — dependency/correctness proofs (`scenarios/s03_recovery_boundaries.php`, `s05_dec003_cycles.php`, `s06_scope_rotation_caps.php`, `s08_redis_spotcheck.php`)
- **DEC-003 measured** (every persisted correlation block goes through `blockWithCycleTracking`; host-realistic skip-on-denial flow): two legitimate clients behind one NAT for 2 h →
  **115 K2 cycles, 12 decay-pause activations, 6,780 of 7,200 s (94 %) of K2 decay paused** (Login); API_OVERUSE: 113 / 12 / 6,840 s. Only K2 is affected (other keys: 0). A single genuine trigger = 1 cycle.
- **Exact boundaries** (fixed TTL from creation, not sliding): block `t=79 +1s`, `t=80` re-issue; distinct set `t=599` re-block, **`t=600` allowed**; WATCH `1809` blocked, **`1810` allowed**;
  first unblocked second for a lone legitimate client after the trigger: **621**; fresh independent pair at 700/710 is convicted at 710 by the stale WATCH.
- **Isolation:** K2 correlation block leaves K4/K5/K3 and scores untouched (`F04.isolation.k4-k5`); Login block does not affect OTP (`F04.isolation.policy`).
  IPv6: two clients in one `/64` block like IPv4; six clients on six `/64` of one `/48` are blocked at the third client's own K2 (macro scope → current K2 only), see `expected/s02_churn.jsonl`.
- **Rotation:** none / outer-only / fingerprint-only / both: identical decisions; a pair split across an outer-key rotation (A under old secret, B under new + previous) blocks at B with set sizes ≤ 1 (bridge bounded).
- **Caps / bounded state:** 1 fingerprint × 600 IPs → largest set 6 (cap 6); 1 K2 × 600 fingerprints → 3 (cap 3); account/IP device caps and ephemeral overflow as in `F04.ephemeral.overflow-then-rotation` (K2 churn still fires; no K3/K5 for overflow).
  **Caveat (measured, not hidden):** 600 distinct requests created 3,600 sets (each ≤ cap, TTL ≤ 1800 s): state is bounded **per scope** and by TTL, but the *number* of scopes scales with distinct anchors; a witness design adds more scopes per request and must be sized, not assumed free.
- **Real Redis** (`expected/s08_redis_spotcheck.jsonl`; ownership-verified instance, immediate sequences only because Redis owns time — DEC-016): churn A/B/A, LOW dilution (5th client blocked at `recordSuccess`),
  **record-only** LOW dilution (6th client blocked), largest Redis set `SCARD = 6`, TTL classes `600/900/1200/1800`. Matches the reference stores.

### 4.5 B-F05 — security trade-off matrix (`expected/model_matrix.jsonl`, `expected/s07_compensation.jsonl`)
Streams: benign shared NAT (valid / with 8 % typos), LOW population (valid / typos), roaming device; attacks X1 (FP rotation, one K2), X2 (LOW distributed, new IP+account each),
X3 (FP+IP rotation), X5 (slow-roll), plus X2L, X4, X6–X8. Selected rows (legit blocked / attack blocked / failed attempts that reached credential verification):

| Stream | S0 (current) | A1 | P / PW | P2 | P0 (pre-check-only) |
|---|---|---|---|---|---|
| L1 NAT valid | 127/134 | 8/134 | 0 | 0 | 0 |
| L1f NAT + typos | 127/134 | 8/134 | 0 | 0 | 0 |
| L2/L3 LOW population | 104/121 | 0 | 0 | 0 | 0 |
| X1 FP rotation | 60/61 · reached **2** | 59/61 · 2 | 59/61 · **12** | 59/61 · 12 | 50/61 · 11 |
| X2 LOW distributed | 116/121 · reached **6** | 0/121 · **121** | 0/121 · **121** | 115/121 · 7 | 0/121 · 121 |
| X2L X2 + legit users | legit **29/30** · 117/121 | 0/30 · 0/121 | 0/30 · 0/121 | legit **28/30** · 115/121 | 0/30 · 0/121 |
| X4/X6 shared-K2 attacker/insider | legit 79/81 | 78/81 | 73/81 | 73/81 | 51/81 |
| X7 padded X2 | 116/121 | 0 | 0 | **0** | 0 |
| X8 botnet weaponization | legit 49/50 | 0/50 | 0/50 | **48/50** | 0/50 |

**Measured compensation (real pipeline, correlation churn/dilution disabled only — spray, distributed-account, flood, budgets, scores, K4/K5 untouched)** — attack attempts that reach credential verification:

| Attack | Login + account id | Login anonymous | API_OVERUSE (no account) |
|---|---|---|---|
| X1 FP rotation | 2 → 2 (K4 stops it) | 2 → **61/61** | blocked 59/61 → **0/61** |
| X2 LOW distributed | 6 → **121/121** | 6 → **121/121** | blocked 115/121 → **0/121** |
| X3 FP+IP rotation | 2 → 2 (K4) | **121/121** both | blocked 0/121 both |

(first number correlation active; second disabled; the API_OVERUSE column shows blocked attempts because that policy is driven by `recordSuccess()` only, so every attempt "reaches" the next step). **K4 compensates only when a single account is hammered and an account id exists; nothing in the real runtime compensates for X2 (distributed, one failure per account) in any policy, nor any attack without account identity.**
So weakening §5.3 *loses* X2 coverage outright; the lone mitigation candidate (P2) is weaponizable and evadable. This is a trade-off for the owner, not an evidence-backed recommendation.

## 5. What this evidence does NOT establish
- No policy is selected or recommended; P/PW/P2/A1/A2/P0 are modeled only and unimplemented. Their witness/latch mechanics on the real store (freshness, rotation, ephemeral) are UNPROVEN.
- Real-Redis evidence is semantic spot-checks only (Redis time is not controllable). OTP and API_OVERUSE use the same correlation code path; they are measured in `s04` (flows) and `s05`/`s07` (API), not exhaustively for every scenario.
- Model streams are synthetic and small; absolute percentages are properties of those streams, not of any production traffic.
- DEC-003 effect on *scores* (decayed K2 score impact on API_OVERUSE throttling) is measured as lost decay time, not as downstream throttle changes.
