# G3 — Admission budgets: failure cap, source midpoint, CGNAT and per-account bucket — pre-registered plan (#97)

> **Status:** PRE-REGISTRATION, plan version **v1**. Committed **before** any harness code exists and before any result.
> Evidence only. **Not** an architecture, policy, threshold or contract approval. No change to `src/`, `tests/`,
> `composer.json`, public contracts, presets, ADRs, standards, or the G1 / G2 evidence. DEC-015, DEC-019 and DEC-021 stay
> ACTIVE and unchanged.
> **Baseline:** `main@597fda0a2c728232cdf7f3d697ab46f2abeedede`. `src/`, `composer.json` and `composer.lock` there are
> byte-identical to the G1 baseline `4c6ca618d8206ba502961eaf06ffb82a5d61b50d`
> (`git diff --stat 4c6ca61 597fda0 -- src composer.json composer.lock` is empty), so the G1 candidate builds apply unchanged.
> **Scope source:** the "actually open" items of [`../../IDENTITY_ATTRIBUTION_PROPOSAL.md`](../../IDENTITY_ATTRIBUTION_PROPOSAL.md)
> §13 and step 4 of its §14. Results whose outcome is already known from merged studies (that proposal's §13 first table) are
> **not** re-tested here except as reproduction and regression checks.
> **Owner input:** T = 30 minutes is the study value of the per-account guessing budget (proposal §15, decision 4, 2026-10-17).
> The other values below are test values chosen by this plan; none is approved or recommended for production.

## 0. Questions

| ID | Question | Why it is open |
| --- | --- | --- |
| **Q-FC** | Can a cap on the **distinct accounts a source has failed on**, inside `U_src` = 40 as the outer ceiling, bring single-IP spray reach to the level of the tight value (`U_src` = 12) while keeping the benign results of the loose value (`U_src` = 40)? | G1 measured only flat attempt caps; no `U_src` value beats S0 on both axes (`G1_VERIFICATION.md` §6). OQCE (#111) suggests an outcome-aware source scope can, but that family was NO-GO for its own reasons |
| **Q-MID** | Where does `U_src` = 24 fall between the two measured points? | G1 measured 12 and 40 only |
| **Q-CG** | What share of legitimate unproven attempts does a source cap refuse behind a large shared source (CGNAT)? | G1 streams are small offices (2–12 clients) |
| **Q-TB** | Does a per-account token bucket that refills one attempt every T, with no escalation and no daily cap, bound guessing on one account at `C + 86400 / T` per day while letting an unproven owner back in within about T after the attack stops? What does it cost benign streams, with and without refunding successes? | The proposal replaced "K4U escalation or not" by this budget (§6.2); G1 measured only fixed windows with escalation active |

Out of scope (each needs a decision or a design first): device binding and its taint threshold, K5 separation and the E2
regression check (pending Owner decision 3), proof acquisition (G2), multi-IP spray and `U_glob` design (P4: POI, ABA_A),
Redis Cluster / failover / DEC-015 integration (implementation stage), production values.

## 1. Hypotheses

* **H-FC.** For at least one tested `F_src`, the FC arm (§2.2) gives, in `pre+out`, single-IP spray reach (SP1n, SP3, SP6,
  MIX attack part, ABA_A) **≤** the CAND `Ua16-s12` reach of the same stream, **and** legitimate denials on every benign
  stream **≤** the CAND `Ua16-s40` value of the same stream.
* **H-FC-pre.** In `pre` (checkOnly only, no outcomes) FC is inert and equals `Ua16-s40` decision for decision. Declared, not
  a goal: a failure cap needs outcomes.
* **H-TB-bound.** For each T, attacker admissions on one account over 24 h are ≤ `C + ⌊86400 / T⌋`, whatever the number of
  sources, user agents, client ids or devices.
* **H-TB-recovery.** For each T, an unproven owner arriving after the attack stops is admitted no later than `T + 60 s`
  after the attack's last request, in every tested arrival offset.
* **H-TB-benign.** With refund-on-success (TB-R arms, §2.4), legitimate denials on every benign stream are **≤** the
  `Ua16-s40` CAND value of the same stream.

Q-MID and Q-CG are measurements with registered reporting rules (§5), not hypotheses with a pass/fail.

## 2. Mechanism

All arms run on the G1 candidate pipeline (`CAND`, built by the G1 builders from `g1/patches/g1-candidate.diff`, sha-256
pinned) and the G1 admission layer (`g1/lib/admission.php`, `G1Admission` / `G1RefStore`), **reused unchanged**. New
behaviour lives only in a G3 wrapper around `G1Admission` and, for the no-escalation arms, in one ablation patch applied over
the CAND build. Nothing in `src/` changes.

### 2.1 Reference set

`S40` = G1 parameter set `R` with `U_acct` = 16 and `U_src` = 40 (the G1 sweep cell `Ua16-s40`), i.e. `W` = 600 s,
`U_src` = 40, `U_acct` = 16, `U_glob` = 1000, P parameters as `R`. `S12` = the same with `U_src` = 12 (`Ua16-s12`). Both are
G1 cells and must reproduce the archived `g1/verify/expected/v07_sweep.CAND.jsonl` rows exactly (gate V0).

### 2.2 FC — failure cap inside source capacity

A per-(source, window) set `FAILSET` of HMAC'd account subjects.

1. **On `recordFailure`** of a U-lane request from source `s` on account `a`: add `a` to `FAILSET(s, w)`.
2. **On `checkOnly`** of a U-lane request from `s` on `a`: if `a ∉ FAILSET(s, w)` and `|FAILSET(s, w)| ≥ F_src`, refuse with
   capacity cause `cap:fail_src` (not persisted, not escalated, attributed to nobody). Otherwise reserve the normal S40 scopes
   (`U_src` = 40 outer ceiling, `U_acct`, `U_glob`) exactly as `G1Admission`.
3. Successes never remove a member and never give credit (no refunds, no washing; #116 E4).
4. Accounts already in the set keep their retries (typos on one's own account are not new subjects), bounded by `U_acct` and
   `U_src`.
5. In `pre` there is no `recordFailure`, so `FAILSET` stays empty and FC equals S40 (H-FC-pre).

| Arm | Definition | Role |
| --- | --- | --- |
| `FC4` | FC with `F_src` = 4 | candidate (4 is S0's K1 distinct-subject trigger) |
| `FC8` | FC with `F_src` = 8 | candidate |
| `FCALL8` | as `FC8`, but **any** recorded outcome (success or failure) adds the account to the set | **detector**: must show more benign denials than `FC8` on ≥ 1 of L1, N12, Nu4, D2 — proves that counting failures only is what protects the office |
| `FCNA8` | as `FC8`, but the set check and the reservation are not atomic: 40 simultaneous `checkOnly` read the set before any outcome | **measured only**: the burst leak of an outcome-coupled scope (#111), bounded by the outer `U_src` |

### 2.3 MID — source midpoint

`S24` = S40 with `U_src` = 24. Run on the same streams as §4.1. Measurement only.

### 2.4 TB — per-account token bucket

`U_acct` (fixed window) is replaced by a per-account bucket on U-lane requests: capacity `C`, refill one token every `T`
seconds, a reservation takes one token, an empty bucket refuses with `cap:acct_tb` (not persisted, not escalated). Source
and global scopes are the S40 ones.

The no-escalation arms also apply **`g3-ablation-no-k4u-persist.diff`** over the CAND build: for U-lane requests, the K4 (=
K4U) score keeps being written and read for observation, but it never produces a persisted block or a refusal, and the 24 h
login budget never refuses a U-lane request. P-lane behaviour, K4P, K5 and DEC-021 are untouched. The patch is written after
this plan and before any result; its sha-256 is pinned in the report.

| Arm | Bucket | Escalation | Refund | Role |
| --- | --- | --- | --- | --- |
| `G1ESC` | fixed window `U_acct` = 16 (S40) | as G1 (K4U escalates, 24 h budget active) | no | **control and detector**: must refuse the owner later than 30 min after the attack stops in ≥ 1 arrival offset of `REC` — shows that escalation outlives the attack |
| `FW16` | fixed window `U_acct` = 16 | none (ablation) | no | **shape detector**: its 24 h reach on `BRU24` must exceed `TB10`'s — shows that recovery time is set by refill rate, not capacity |
| `TB10` / `TB30` / `TB60` | `C` = 4, `T` = 600 / 1800 / 3600 s | none | no | candidates for H-TB-bound and H-TB-recovery |
| `TB10R` / `TB30R` / `TB60R` | as above | none | a `recordSuccess` on the same account by the same request returns its token (pre+out only; never above `C`) | candidates for H-TB-benign. A refund is per target only: a success on account `a` requires `a`'s password, so it cannot wash a foreign account's bucket |

`T` = 1800 s (TB30, TB30R) is the Owner's study value; 600 and 3600 bracket it.

## 3. Values (test values only)

| Name | Value |
| --- | --- |
| `W`, `U_glob`, P parameters | G1 set `R` |
| `U_src` | 40 (S40, FC, TB); 24 (S24); 12 (S12) |
| `U_acct` (fixed window) | 16 |
| `F_src` | 4, 8 |
| Bucket `C`, `T` | 4; 600, 1800, 3600 s |
| CGNAT `N` | 50, 500, 5000 clients behind one source |
| CGNAT unproven attempts per client per day `λ` | 1, 4 |
| CGNAT share of attempts already in P `p` | 0, 0.8 (a **modelled** stand-in for binding; binding itself is not built) |
| Peak factor | 3 (the busiest hour carries 3× the mean hourly rate) |
| Reporting threshold `ε` | 1 % of legitimate unproven attempts refused |

## 4. Streams and fixtures

Clock and replay exactly as G1 (`g1Replay`, integer seconds, attacker first at equal times, fixed seeds).

### 4.1 Archived streams (unchanged, from `g1Streams()`)

Benign: L1, D2, ABA_B, N12, Nu4, OFF12, Y6b, KD, UAC, L3, D1, MIX (legitimate part). Attack: SP1n, SP3, SP6, ABA_A, MIX
(attack part), X2, X7, X1, BRU, BRD, POI. Strategies as `g1Strategies()`; best response = maximum over strategies, as G1.
Flows: `pre+out` for every arm; `pre` for S40, FC4, FC8, TB30, TB30R; `out` reported for FC8 and TB30R only.

### 4.2 New fixtures

| ID | Shape | Used by |
| --- | --- | --- |
| `BURST40` | one source, 100 `checkOnly` on 100 distinct accounts at the same second, outcomes (all failures) recorded 2 s later; repeated every window for 3 windows | FC arms (FCNA8 leak) |
| `BRU24` | one victim account; the attacker fails every 15 s for 86 400 s, rotating source, UA and client id per request (best response over free identifiers) | TB arms, G1ESC, FW16 (H-TB-bound) |
| `REC` | the BRU24 attacker for 1800 s, then silence; an unproven owner (new device, correct password) arrives at attack end + δ, δ ∈ {0, 60, 300, 900, 1800, 3600} s, and retries every 60 s until admitted or 2 h pass; each δ an independent run | TB arms, G1ESC, FW16 (H-TB-recovery) |
| `CGN` | admission-layer simulation only (no pipeline: under the attribution rule no collective penalty exists, so only `U_src` decides at the source). `N` clients behind one source, each with its own account; Poisson arrivals with rate `λ · (1 − p) · peak / 86400` per client during one peak hour; 24 replications with fixed seeds; with and without a co-located attacker spraying distinct accounts at 1 / s from the same source; plus one mobile client whose source changes every 120 s | S12, S24, S40, FC8 (with the attacker run's failures feeding FAILSET) |

## 5. Metrics, gates and reporting rules

Metrics per cell: legitimate denials (count and cause), attacker `reach_foreign` (best response, per window and total),
per-target reach, owner time from attack end to admission, refusals by cause, refunds, store writes.

| Gate | PASS condition | Detector that must fire |
| --- | --- | --- |
| **V0** reproduction | S12 and S40 CAND rows reproduce the archived G1 v07 rows decision for decision; S0 references reproduce the archived G1 S0 rows (`g1/expected/m01_matrix.S0.jsonl`, and `g1/verify/expected/v07_sweep.S0.jsonl` for HAR / HARp). A miss is a harness failure (exit 5), not a finding | — |
| **FC-1** spray | for `FC4` or `FC8` (the same `F_src` for FC-1 and FC-2): every single-IP spray stream (SP1n, SP3, SP6, MIX attack, ABA_A) has best-response reach ≤ its S12 value | — |
| **FC-2** benign | same `F_src`: every benign stream of §4.1 has legitimate denials ≤ its S40 value | `FCALL8` > `FC8` on ≥ 1 of L1, N12, Nu4, D2 |
| **FC-3** pre | `pre` decisions of FC4 and FC8 identical to S40 | — |
| **TB-1** bound | each TB arm: attacker admissions on `BRU24` ≤ `C + ⌊86400 / T⌋`, recounted independently from the admission log | `FW16` reach on `BRU24` > `TB10` reach |
| **TB-2** recovery | each TB arm: on `REC`, for every δ the owner is admitted ≤ `T + 60` s after the attack's last request | `G1ESC` refuses the owner > 1800 s after the attack's last request for ≥ 1 δ |
| **TB-3** benign | each TB-R arm: every benign stream of §4.1 has legitimate denials ≤ its S40 value | — |
| **TB-4** archived attacks | reported for every TB arm in `pre+out` and `pre`: BRU, BRD, X1, X2, X7; no gate |  — |

Reporting rules (no pass/fail):

* **MID:** report S12, S24 and S40 side by side for every stream of §4.1.
* **CG:** for each `U_src` arm, `λ` and `p`, report the largest tested `N` whose mean refused share is ≤ `ε` (none, 50, 500 or
  5000), with and without the attacker; report the mobile client's refusals separately.
* **FCNA8 / BURST40:** report admissions per burst above `F_src` (the leak) and confirm it never exceeds `U_src`.
* **TB without refund:** report benign denials of TB10 / TB30 / TB60 against S40; expected to be high on L1-shaped streams.

## 6. Verdict rules (fixed now)

1. A detector that does not fire ⇒ the gate it guards is **not evidenced**, and no candidate can be called supported on it.
2. FC is **EXPERIMENTALLY SUPPORTED — LIMITED SCOPE** if FC-1, FC-2 and FC-3 pass for at least one `F_src`; otherwise **NO-GO
   AFTER EXPERIMENT** for FC. The verdict names the `F_src` and is limited to `pre+out` Hosts.
3. A TB value of T is **EXPERIMENTALLY SUPPORTED — LIMITED SCOPE** if TB-1 and TB-2 pass for its no-refund arm and TB-3 passes
   for its refund arm; otherwise **NO-GO AFTER EXPERIMENT** for that T. Each T is judged separately.
4. MID and CG never produce a verdict; they feed the Owner's spray-budget decision.
5. No result here is an Architecture GO, an ADR, a production value or a statement that Runtime is ready.
6. A substantive change to arms, values, fixtures or gates after a result requires plan v2 with the reason, keeping v1 results.

## 7. What this cannot prove

* Real CGNAT traffic: `CGN` is a Poisson model with assumed `λ`, `p` and peak; it shows shape and order of magnitude only.
* The effect of device binding: `p` is a modelled share, not a built mechanism.
* Production Redis behaviour, concurrency beyond the reference store's simulated burst, DEC-015 degraded mode, rotation and
  mixed fleets.
* Multi-IP spray (P4: POI, ABA_A over many sources) and `U_glob`.
* That a Host calls the library in the right order; FC needs outcomes, so record-only and checkOnly-only Hosts get the outer
  ceiling only.

## 8. Reproduction

```bash
bash investigations/k2-identity-admission-97/g3/admission_budgets/run.sh
```

The runner builds the G1 `CAND` tree with the G1 builders (sha-256 pinned) and the `CAND` + `g3-ablation-no-k4u-persist`
tree into the git-ignored `results/`, runs every scenario, writes `summary.md` and `gates.json`, and diffs everything against
`expected/`. It exits non-zero on any gate violation, any detector that does not fire, any V0 miss, or any difference.
`UPDATE=1` regenerates `expected/`.
