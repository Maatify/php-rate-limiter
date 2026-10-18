# G4 — Integrated assembly: identity, attribution, budgets and device binding — pre-registered plan (#97, #110)

> **Status:** PRE-REGISTRATION, plan **v1**. Committed **before** any G4 harness code exists and before any G4 result. Evidence
> only. **Not** an architecture, policy, threshold or contract approval. No change to `src/`, `tests/`, `composer.json`, public
> contracts, presets, ADRs, standards, or the G1 / G2 / G3 evidence. DEC-015, DEC-019 and DEC-021 stay ACTIVE and unchanged.
> **Baseline:** `main@730e4e1ed731d3c1d01eec45aadd49466b2a19a5`. `src/`, `composer.json`, `composer.lock` are byte-identical to the
> G1 baseline `4c6ca618d8206ba502961eaf06ffb82a5d61b50d`, so the G1 / G3 builders apply unchanged.
> **Scope source:** [`../../IDENTITY_ATTRIBUTION_PROPOSAL.md`](../../IDENTITY_ATTRIBUTION_PROPOSAL.md) and the G3 / G3 v2 reports.
> Those studies measured the parts **separately** (the net failure cap on a build with K4 escalation, the bucket on a build without
> it and at `U_src` = 40) and never built device binding. G4 measures the **assembly**.
> **Owner input (2026-10-18):** K5 separation by binding is approved **for this study** (proposal §15, decision 3). T = 30 minutes
> remains the study value (decision 4). No value here is a production value.

## 0. Questions

| ID | Question |
| --- | --- |
| **Q1 — assembly** | Does the full assembly keep, at once, the results each part reached alone: benign streams at or below S0, single-IP spray near S0, the per-account bound `C + ⌊86400/T⌋`, and recovery within T? |
| **Q2 — binding** | Does device binding (the proposal's "trust from success", the Owner's trust-score idea) deliver what it promises: an owner on a bound device is never refused because of strangers; a bound office behind a NAT or a CGNAT is served even next to a co-located sprayer; a Host's false "known" flag no longer bypasses the account bound; a token never works on another account; a stolen token is bounded and revocable? |
| **Q3 — necessity** | Is every component needed? Each is removed in turn (leave-one-out), and each must worsen at least one registered measurement when removed. |
| **Q4 — `U_src`** | With the net failure cap in place, does 40 or 24 serve better? (G3 v2 measured `FCN4-40` with fewer D2 denials than `FCN4-24` and the same single-IP spray.) |
| **Q5 — E2** | Does K5 separation remove the history-mediated divergence that made #127 NO-GO? |
| **Q6 — checkOnly-only Hosts** | Binding is issued by the Host after its own verifier result, so it also exists for Hosts that never report outcomes. Does it relieve the heavy legitimate users the bucket hurt there (G3 v2: L1 122/134 in `pre`)? |

## 1. The assembly (`FULL`)

| Layer | Definition in G4 |
| --- | --- |
| Pipeline | G1 candidate (`CAND`: collective K1 / K2 / K3 / correlation penalties removed) + the G3 ablation `g3-ablation-no-k4u-persist.diff` (`NOESC`: no account-scoped refusal or persistence on the U lane) + a new **K5 separation** patch `g4-k5-separation.diff`: on the P lane the K5 key, the K5 micro-cap key, the device Anti-Equilibrium watcher and the DEC-021 known-device allowance use their own `…p` namespaces, so P-lane device history is written only by P-lane outcomes. The patch is written after this plan and before any result; its sha-256 is pinned in the report |
| Instance id (stand-in for `clientInstanceId`) | Carried in `sessionDeviceId` **untrusted**, so confidence stays LOW (proposal §5 rule 1) and K5 = account + (UA, id). The proposal's UA-free K5 material (rule 2) is **not** implemented; a browser update therefore changes K5. Declared deviation |
| U lane (strangers' door) | `U_src` = 24 per 600 s; net failure cap `F_src` = 4 (G3 v2 `FCN`); per-account token bucket C = 4, T = 1800 s, refund on success (G3 `TB30R`); **no** known-device routing; `U_glob` 1000 |
| P lane (proven door) | Entered only with a valid **binding token** (§1.1). G1 P scopes: `P_acct` 10 / window and 60 / day, `P_proof` 6 / window and 30 / day keyed by the token id, `N_P` 10 new devices per day. P-lane penalties on `K4P` and the separated K5 |
| Host-asserted flags | `isSessionTrusted` and `isDevicePreviouslyVerifiedForAccount` keep their pipeline meaning (DEC-021 classification) but **never** select the P lane and never skip the bucket |

### 1.1 Binding (study code, the "trust from success" layer)

* **Issuance (Host model).** After a U-lane request that reached the verifier with outcome `ok`, the Host issues a token for
  (account, instance) if the instance is not tainted. Tokens are HMAC-SHA256 over `{v, account, instance, iat, exp, epoch, jti}` with
  a study key. `exp` = 30 days (except the E2 fixture). The client stores the token in its jar under its instance id; clearing cookies
  drops both. Issuance is Host-side, so it happens in every flow, including `pre`.
* **Taint.** Not issued if the instance failed on **≥ 3 distinct other accounts** in the current 600 s window before the success.
  Failures on the same account never count.
* **Boundary (library model).** A request is P **iff** it carries a token whose MAC is valid, whose account equals the request's
  account, whose instance equals the request's instance id, that has not expired, and whose epoch equals the account's current
  revocation epoch. Anything else is U. The P-lane proof reference is the token id.
* **Revocation.** A per-account epoch, bumped on password change; older tokens stop verifying.

### 1.2 Arms

| Arm | Build | Differs from `FULL` by | Role |
| --- | --- | --- | --- |
| `FULL` | G4 | — | the assembly |
| `FULL-40` | G4 | `U_src` = 40 | Q4 |
| `NO-BIND` | G4 | no binding: every request U | leave-one-out |
| `NO-FCN` | G4 | no failure cap | leave-one-out |
| `NO-TB` | G4 | fixed window `U_acct` = 16 instead of the bucket | leave-one-out |
| `NO-K5SEP` | NOESC (G3) | no K5 separation | leave-one-out, and **detector** for Q5 |
| `ESC` | CAND + K5SEP | K4 escalation active on the U lane | leave-one-out |
| `WEAKBIND` | G4 | the boundary skips the account check | **detector** for the cross-account gate |
| `S0`, `S12`, `S24`, `S40` | archived / CAND | — | references (S0 and S12 / S24 / S40 from the G3 archive; S24 re-run for V0) |

## 2. Fixtures

Clock, replay and stream materialisation as G1 / G3 (integer seconds, attacker first at equal times, fixed seeds).

| ID | Shape | Purpose |
| --- | --- | --- |
| `STREAMS` | the G3 §4.1 streams. Every legitimate actor keeps one instance id (`i:` + actor); an actor the stream marks trusted, previously verified, or proven starts with a token for its own account. Attack rows run with two id policies, **fresh id per request** and **one constant id**; best response = maximum over policies and the G1 strategies | Q1, Q3, Q4; `pre` for `FULL`, `FULL-40`, `NO-BIND` (Q6) |
| `HAR-B` | G1 `HAR` (attacker fails every 10 s from 30 rotating sources for 1,800 s; owner logs in every 120 s) with the owner's instance already bound | Q2 owner protection |
| `HAR-N` | the same, owner on a new instance (no token) | §2.9 residual: reported |
| `BRU24`, `REC` | G3 fixtures (one victim; attacker every 15 s for 24 h; owner arrival offsets δ ∈ {0, 60, 300, 900, 1800, 3600} s), attacker with a fresh id per request | Q1 per-account bound and recovery |
| `BRU24-HV` | `BRU24` with every attacker request flagged `isDevicePreviouslyVerifiedForAccount` (no token) | Q2: the Host-violation hole of G3 v2 (5,760 / 5,760) |
| `CROSS` | an attacker bound on its own account presents that token while spraying 200 victim accounts from one IP, one attempt each every 3 s | Q2 cross-account |
| `STEAL` | the owner is bound; at 600 s a thief copies the owner's instance id and token and fails on the owner's account every 15 s from rotating IPs; the owner logs in every 2 h from the bound device; at 43,200 s the owner changes the password (epoch +1) and logs in again (U, then rebound); 24 h | Q2 stolen token |
| `BOOT` | 40 browsers behind one IP, each with its own account, 3 logins per day at fixed-seed times over 3 days; 5 % of logins are a typo followed by a correct retry 30 s later; variant `BOOT-X` adds a co-located sprayer on day 1 (09:00–17:00), one distinct victim account every 10 s | Q2 NAT bootstrap |
| `CGN-B` | admission-layer model (as G3 CGN, no pipeline: no collective penalty exists, per-account state is irrelevant to success-only users): N ∈ {500, 5,000} clients behind one source, λ ∈ {1, 4} logins per client per day, peak factor 3, 3 days, 5 % typos with retry; a client is bound after its first verified success; with and without a co-located sprayer at 1 / s during day 2 | Q2 CGNAT |
| `CLEAR` | one user, 3 logins per day for 5 days, clearing cookies every day (new instance, no token), behind the `BOOT` office IP | reported |
| `E2` | the owner is bound with a 600 s token; P logins every 60 s from 0 to 600 s (one typo); token expired: U attempts at 630–870 s with 3 failures; successful U login at 900 s (rebinding); P logins every 60 s to 2,400 s with typos. Population: 20 rotating devices failing on the account every 15 s from 0 to 2,400 s. Control: the same owner rows without the population | Q5 |

## 3. Values (test values only)

G1 set `R` for every value not named here. `U_src` 24 (40 in `FULL-40`, 12 / 24 / 40 for references); `F_src` 4; bucket C = 4,
T = 1800 s; fixed-window `U_acct` 16 in `NO-TB`; taint threshold 3 distinct accounts per 600 s; token lifetime 30 days (600 s in
`E2`); CGN reporting threshold ε = 1 %.

## 4. Gates

| Gate | PASS condition (`FULL` unless stated) | Detector that must fire |
| --- | --- | --- |
| **V0** | `S24` re-run on CAND through the G4 replay (no ids, no binding, plain source cap) equals the G3 v2 archive field for field. A miss is a harness failure (exit 5). No archived row exists for the full composition, so it has no V0 | — |
| **A1** benign | every G3 §4.1 benign stream except MIX: legitimate denials ≤ S0, and ≤ S24 | — |
| **A2** co-located residual | MIX legitimate denials ≤ S0 (117) | — |
| **A3** spray | SP1n, SP3, SP6, ABA_A best-response reach ≤ S12 | — |
| **B1** per-account bound | `BRU24` attacker admissions ≤ 52, equal to an independent recount | — |
| **B2** recovery | `REC`: wait from the owner's arrival ≤ max(0, 1860 − δ) for every δ | — |
| **C1** bound owner | `HAR-B`: 0 owner denials | `NO-BIND` denies the owner in `HAR-B` at least once |
| **C2** Host-violation hole closed | `BRU24-HV` attacker admissions ≤ 52 | — |
| **C3** cross-account | `CROSS`: 0 P-lane admissions on any victim account; victim reach ≤ the `CROSS` reach of `NO-BIND` | `WEAKBIND` admits ≥ 1 victim request on the P lane |
| **C4** stolen token | `STEAL`: thief verifier calls ≤ 6 per window and ≤ 30 per day (`P_proof`); 0 thief P admissions after the epoch bump; the owner's first U login after the bump is admitted within T | — |
| **C5** NAT bootstrap | `BOOT`: total legitimate refusals ≤ 1 % of legitimate attempts; `BOOT-X`: 0 refusals of requests from already-bound instances | in `BOOT-X`, `NO-BIND` refuses ≥ 1 day-1 request from an instance that `FULL` had already bound at that time |
| **C6** CGNAT | `CGN-B`, N = 5,000, λ = 1: day-3 refusal share ≤ 1 % without the sprayer; with the sprayer, 0 refusals of bound clients | — |
| **E** E2 | owner P-lane decisions identical with and without the population | `NO-K5SEP` differs |
| **P** checkOnly-only | `pre` flow: L1 and D2 legitimate denials ≤ S24 `pre` (0 and 36) | — |

**Necessity (Q3), reported with a registered rule:** for each leave-one-out arm, the report names the registered measurements that
worsen when the component is removed. A component with **no** worsened measurement is reported as **"not shown necessary"**.
Registered expectations: `NO-BIND` → C1, C5, C6; `NO-FCN` → A3; `NO-TB` → B1 and the `pre` BRU reach; `NO-K5SEP` → E; `ESC` → B2.
An expectation that does not materialise is reported as such; it is not a gate.

**Q4** is reported: `FULL` against `FULL-40` on every stream, in `pre+out` and `pre`. No winner is declared by the harness.

## 5. Verdict rules (fixed now)

1. A detector that does not fire ⇒ the gate it guards is not evidenced.
2. The assembly is **EXPERIMENTALLY SUPPORTED — LIMITED SCOPE** if every gate of §4 passes with every detector firing. The verdict
   lists its scope: synthetic streams, reference stores, a study-code binding, and the deviations of §1.
3. Otherwise **NO-GO AFTER EXPERIMENT**, naming every failed gate. No gate is re-scoped after a result; a v2 needs a new plan.
4. Nothing here is an Architecture GO, an ADR, a production value, an Owner decision on `U_src` or T, or a statement that Runtime is
   ready.

## 6. What this cannot prove

Real cookies and tokens, a real Host, Redis, concurrency, failover, DEC-015 degraded mode, rotation and mixed fleets; the UA-free K5
material; real CGNAT traffic (the CGN fixture is a model); the proof acquisition paths of G2 (not combined here); multi-IP spray and
`U_glob` design (P4); production values.

## 7. Reproduction

```bash
bash investigations/k2-identity-admission-97/g4/integrated/run.sh
```

Builds `CAND` and `NOESC` with the G3 builder, then `G4` (NOESC + K5 separation) and `CAND + K5SEP`, all sha-256 pinned; runs every
scenario; writes `summary.md` and `gates.json`; diffs against `expected/`. Exit 0 = every gate passes; 3 = harness valid and a gate
fails or a detector does not fire; 5 = V0 miss; 1 = output differs or a scenario fails. `UPDATE=1` regenerates `expected/`.
