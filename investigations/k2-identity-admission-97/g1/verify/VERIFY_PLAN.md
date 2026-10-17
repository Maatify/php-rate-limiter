# G1 verification addendum — pre-declared plan

> **Status:** PRE-DECLARATION, committed **before** any verification result exists. It responds to the Principal Architect's
> independent G1 evidence review
> ([PR #126 comment 6228274086](https://github.com/Maatify/php-rate-limiter/pull/126#issuecomment-6228274086), findings
> F126-G1-01 … 04). Scope: the Owner's R-1 authorization for isolated G1 evidence
> ([comment 6227307995](https://github.com/Maatify/php-rate-limiter/pull/126#issuecomment-6227307995)). **Evidence only:** no
> change to `src/`, `tests/`, `docs/`, ADRs, thresholds, defaults or issues; no new API or DTO; no G2/G3.
> **Immutable inputs:** [`../PLAN.md`](../PLAN.md) (pre-registration, `0f40ce5`). The original harness (`../lib/`,
> `../scenarios/`, `../report.php`, `../run.sh`) and the archived evidence (`../expected/`) are kept, and `../run.sh` must still
> reproduce them byte for byte. The original gate results, including the disclosed first C4 FAIL and its correction, stay as
> recorded. This addendum adds separately labelled checks; it never overwrites an original result.
> **Candidate:** unchanged. The candidate patch (`../patches/g1-candidate.diff`) and the study parameter sets are not tuned.

## 1. Method

* **Separate harness:** `verify/` holds its own libraries, scenarios, report, runner and archived outputs
  (`verify/expected/`). Original and hardened gate definitions are shown side by side.
* **Trace build (F126-G1-03, C10):** the candidate tree plus an **instrumentation-only** patch, `verify/patches/g1-trace.diff`.
  It records which candidates the pipeline built, their sources, the key types that produced score levels, and active blocks.
  It writes nothing to any store and changes no decision. Equivalence is a precondition: every matrix cell replayed on the trace
  build must produce `dec`, `lanes` and `reach` strings identical to the archived `../expected/m01_matrix.CAND.jsonl`.
  If any cell differs, the trace build is rejected and C10-H is `INCONCLUSIVE`.
* **Mutation controls:** every hardened gate has at least one deliberately defective variant. The same checker must report
  FAIL on it. A gate whose detector does not fire on its mutant is reported `INCONCLUSIVE`, never PASS. Mutants exist only in
  `verify/` (harness wrappers, or a separately hashed mutant tree). They never touch the candidate patch.

## 2. Hardened gates

| ID | Original (kept as recorded) | Hardened definition | Detector control (must FAIL) |
| --- | --- | --- | --- |
| **C9-H** (F-01) | After the replay, accounts **without** any P request have no K4P / P watcher / P cooldown | **Event level, mixed accounts included:** for **every U-lane command and every U admission**, the P state of the request's account and of every account that already holds P state is identical before and after. P state = K4P score, block (level and expiry) and budget; P account watcher; P budget cooldown; for the current **and** previous pipeline secret; plus every P admission counter (`pacct`, `pacct_day`, `pproof`, `pproof_day`, `pglob`) and P device set, current and previous admission generation. Measured on every matrix cell (sets R, R-src4, R-src40) **and** on a dedicated mixed-account fixture `MX`: a P owner with live P state; interleaved U failures on the same account from rotating sources and UAs, a historical-known U device, a U request presenting the owner's `sessionDeviceId` without proof, and an untrusted session; owner P requests in between; and a secret rotation (pipeline previous secret + admission previous generation) handed over mid-fixture. The number of U events checked against an account **already carrying P state** is reported as coverage and must be > 0 | **M-C9a:** one U event of `MX` is executed with the pipeline lane forced to P (a lane leak); **M-C9b:** U admission also reserves the P counters (an admission leak). The original checker is also run on both mutants, to show the gap |
| **C4-H** (F-02) | No P lane in SP5 / POI; event-level foreign-proof count 0 | **Split into two claims.** (a) *Host mapping M1* (conditional on the Host): the archived P lanes are re-derived from the raw stream fields (session id, target account, `sessionDeviceId`), independently of `G1Cell::lane()`, and must match exactly. (b) *Package boundary:* negative tests B1 mismatched proof account, B2 empty device secret, B3 Host-asserted trusted session of another target, B4 proof minted for another operation, B5 direct lane claim at the isolated pipeline (`g1SetLane('P')`) and at the admission layer for a foreign account. B1–B3 must yield U through M1. B4 and B5 are expected **not** to be enforced: the isolated patch has no typed proof binding and none may be built (no new API). They are reported `NOT ENFORCED ⇒ package-boundary guarantee UNPROVEN`, with the measured consequence of a forged P claim: reach on the victim, the victim's K4P state, and the owner's next P decision | B1–B3 are themselves the negative tests; B5 demonstrates the absent boundary |
| **C1-H** (F-03) | Matrix recount from the admission log + Redis race exact | Matrix recount from the admission log **and** an independent recount from the replayed events (lane, source prefix, account, window of every request that reached the verifier). Both must be ≤ cap. Redis R-1 exact **and** the R-1m mutation control must have detected over-admission. A missing or undetected control is FAIL | **M-C1:** a reference store that admits one unit over cap on every scope; both recounts must flag it |
| **R3-H** (F-03) | Refused, key names unchanged | Refused **and** key names **and** every counter value unchanged (reference store and Redis) | **M-R3:** a store that increments the first counter before checking (partial write) |
| **C10-H** (F-03) | No benign denial cause string matching `pen:…K[123]` | Every benign denial of every CAND / ALLU matrix cell is attributed through the trace build. Origins are: the score key types (`k1` … `k5`, `k4p`) behind the winning candidates; active blocks; persisted K4 / K4P / K5 blocks carrying the origin that wrote them (a K1/K2 score level persisted on K4 counts as K1/K2); and the non-score sources (`correlation`, `credential_spray`, `flood`, `budget`, `anti_equilibrium`, `recovery_guard`, `distributed_account_attack`). PASS iff no benign denial has a `k1`, `k2`, `k3`, `correlation` or `credential_spray` origin, and every denial is attributed (any unattributed benign denial is FAIL) | **M-C10:** the trace build with the D1 correlation candidate re-enabled (the `if (false && …)` guard removed). The checker must report correlation-origin benign denials |

Decision rule (unchanged from `PLAN.md` §6): a hardened gate that FAILs on the candidate is a core-invariant failure ⇒
**NO-GO** for that variant, with the failing fixtures kept. `INCONCLUSIVE` is not PASS. The package-boundary half of C4 is
`UNPROVEN` by construction and is reported, not gated.

## 3. `U_acct` sweep (F-04), limited and pre-declared

* **Grid:** `U_acct` ∈ {4, 8, 16, 32, off} × `U_src` ∈ {12, 40}. Every other parameter is as R. Arms: **CAND** and **ALLU**
  (ALLU = no proof honoured = Login day-0 before D3). Flow `pre+out`. For #125 attack streams, the best of `none` / `uarot` /
  `rotate` is reported. Nothing is selected or recommended from it.
* **Streams (archived, unchanged):**
  * benign / owner: L1, ABA_B, D2, N12, Nu4, Y6b, KD, UAC, MIX, OFF12, POI (owner part), L3, D1;
  * attack: POI, SP6, SP3, SP1n, ABA_A, X2, X1, X7, BRU, BRD, MIX, Y6b.
* **New fixture HAR (account-target harassment), defined here:** 1800 s.
  * Owner: `acct-har` logs in from `198.51.100.140` (Firefox 130) every 120 s, outcome `ok`.
  * Attacker: fails on `acct-har` every 10 s (180 attempts) with Chrome 126, from 30 sources `192.0.2.150` … `192.0.2.179`, rotating per attempt.
  * Variants: **HAR** (owner unproven) and **HARp** (owner holds an own-account proof: trusted session `sess-acct-har` with a device secret).
  * Run on S0 too, for reference.
* **Metrics per cell:**
  * legitimate denied / attempts, with causes;
  * attacker `reach_foreign`, `reach_proof`, `reach_own`;
  * per-target reach (maximum per account and window, and number of accounts reached);
  * verifier calls.
* **Screen (reported, S0 is not an oracle):** for each (arm, set), `B` = benign streams with more legitimate denials than S0, and `A` = attack streams with more `reach_foreign` than S0's best response. A set "dominates S0 on the sample" iff `A = B = 0`. The answer to "is any sampled set a plausible candidate on both axes" is reported with both counts and the absolute numbers. Owner harm under HAR is reported separately.

## 4. Outputs

* `verify/expected/*.jsonl`, `verify/expected/gates_verify.json` and `verify/expected/summary_verify.md`, reproducible with `bash investigations/k2-identity-admission-97/g1/verify/run_verify.sh`.
* The runner also re-runs `../run.sh` and requires the original evidence to compare clean.
* Report: `../../G1_VERIFICATION.md`, with the Lead matrix:
  * what was measured;
  * what is a harness / Host assumption;
  * which of I1–I10 are evidenced;
  * what remains `UNPROVEN`;
  * the sweep screen.
