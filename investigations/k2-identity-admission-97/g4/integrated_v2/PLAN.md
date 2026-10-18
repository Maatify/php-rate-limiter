# G4 v2 — Integrated re-assembly after F131-01 — pre-registered plan (#97, #110)

> **Status:** PRE-REGISTRATION, plan **v1**. Committed **before** any G4 v2 harness code exists and before any G4 v2 result.
> Evidence only. **Not** an architecture, policy, threshold or contract approval. No change to `src/`, `tests/`, `composer.json`,
> public contracts, presets, ADRs, standards, or any earlier study (G1, G2, G3, G3 v2, G4 and their archives). DEC-015, DEC-019 and
> DEC-021 stay ACTIVE and unchanged; the proposal stays PROPOSED.
> **Baseline:** `main@ac97c1232dd1c03f73cacaffdffb7ed9345f06ef` (G4 merged). `src/`, `composer.json`, `composer.lock` are
> byte-identical to the G1 baseline `4c6ca618d8206ba502961eaf06ffb82a5d61b50d`.
> **Owner authorisation (2026-10-18):** G4 v2 with the scope below: correct the F131-01 input defect and re-measure every affected
> gate with its G4 definition; design and measure the owner's return path to the proven door and a P-lane budget that does not
> charge successes; keep K5 separation, with a gate on the case G4 observed post-hoc. `U_src` 24 (40 reported), T = 30 minutes and
> C = 4 are study values only.
> **G4 archive:** [`../integrated/`](../integrated/) is reused **read-only** and is never edited or regenerated.

## 0. Questions

| ID | Question |
| --- | --- |
| **Q1 — corrected G4** | With the instance id actually delivered to the pipeline, what do the G4 arms measure, and which G4 numbers change? |
| **Q2 — return path** | Can a bound owner get back to the proven door **while an attack continues**, after a password change (STEAL) and after a token would have expired (E2), with the thief still bounded and revocable? |
| **Q3 — P budget** | Does a P-lane budget that refunds successful logins remove the refusals of heavy bound users, while the stolen-token bound and `N_P` hold? |
| **Q4 — K5 separation** | Do an owner's own U-lane failures on the same instance stay out of the owner's later P-lane decisions? |
| **Q5 — assembly** | Does the new assembly (`FULL2`) pass every registered gate for the reference Host (`pre+out`)? |
| **Q6 — checkOnly-only** | What does `FULL2` give a Host that never reports outcomes (`pre`)? Gate P is evaluated and its result written as it is. |

## 1. Input integrity (the F131-01 correction)

* **Fix.** The v2 stream and BRU builders replace the context explicitly (`$row['ctx'] = …` / `array_replace`), never with a
  left-biased array union, so the context that carries the instance id in `sessionDeviceId` is the one replayed. `g4Ctx()` keeps its
  G4 definition (instance id in `sessionDeviceId`, trust flag and every other field of the source context unchanged).
* **Assertion (mandatory, IN).** Inside the v2 replay, for **every** replayed row that declares an instance id (`inst` not null),
  the DTO's `sessionDeviceId` must equal `inst`. The first violation aborts the scenario with exit 6; `run.sh` then exits 6. V0 rows
  declare no instance id by definition and are therefore outside the assertion.
* **Detector (D-IN, registered).** A dedicated scenario rebuilds each fixture family with the context reverted to the source
  context: STREAMS through the **archived G4 builder** `g4FromStream()` (the real defect), BRU24 through the archived G4 union
  expression, and one `g4Row1`-built fixture (HAR-B) with `sessionDeviceId` reset to the source value. D-IN passes only if the
  assertion rejects **every** reverted family and accepts the corrected rows of the same families. A D-IN miss is a harness failure
  (exit 6).
* **V1 (reproduction of the unaffected G4 fixtures).** The G4 configurations re-run in v2 (§2.1) must reproduce the G4 archive
  **field for field** on the fixtures F131-01 did not affect: HAR-B, HAR-N, CROSS, STEAL, CLEAR, E2, BOOT, BOOT-X and CGN-B. A miss
  is a harness failure (exit 5). V0 (S24 on CAND through the v2 replay equals the G3 v2 archive, 34 rows) is kept as in G4.

## 2. Arms

### 2.1 Part R — the G4 arms re-measured with the corrected input

Same builds, values and definitions as G4 `PLAN.md` §1.2; only the input is corrected. Every fixture is re-run (nothing carried
over from the archive).

| Arm | G4 arm |
| --- | --- |
| `R-FULL`, `R-FULL-40`, `R-NO-BIND`, `R-NO-FCN`, `R-NO-TB`, `R-NO-K5SEP`, `R-ESC`, `R-WEAKBIND` | `FULL`, `FULL-40`, `NO-BIND`, `NO-FCN`, `NO-TB`, `NO-K5SEP`, `ESC`, `WEAKBIND` |

`pre` runs for `R-FULL`, `R-FULL-40`, `R-NO-BIND`, `R-NO-TB` (as G4).

### 2.2 Part N — the new assembly

`FULL2` = `R-FULL` plus three components (§3). All on the G4 build (NOESC + K5 separation) unless stated.

| Arm | Differs from `FULL2` by | Role |
| --- | --- | --- |
| `FULL2` | — | the assembly |
| `FULL2-40` | `U_src` = 40 | comparison, reported without a winner |
| `N-PG1` | the G1 P-lane budgets (no refund) | leave-one-out for the P budget |
| `N-NORENEW` | no sliding renewal (token lifetime fixed at issue) | leave-one-out for renewal; detector for E |
| `N-NOPWB` | password change revokes only (G4 behaviour); no token issued by the change | leave-one-out for the password-change binding |
| `N-NOK5SEP` | NOESC build (no K5 separation) | leave-one-out for K5 separation; detector for K5 |
| `P-NETF` | P-lane budgets count net failures (recorded at outcome) instead of refunding successes | alternative design, **reported only** |

`pre` runs for `FULL2`, `FULL2-40`, `N-PG1`, `P-NETF`. Detectors that need an arm without binding or with a weak boundary use
`R-NO-BIND` and `R-WEAKBIND`, whose U side and Host model are those of `FULL2` without binding / with the weak boundary (the new
components act only on bound devices and on outcomes no attacker in those fixtures produces).

## 3. New components (study code, Host model and gate only)

| Component | Definition |
| --- | --- |
| **Password-change binding** | A password change or account recovery is a proven Host event. At that moment the Host bumps the account's epoch **and** issues a new token to the device that performed the change (same holder and instance), with a new binding chain. No U-lane request is involved |
| **Sliding renewal** | After every verified success on the P lane the Host renews the presented token: same `jti` and binding chain, `exp = min(now + TTL, bindAt + 90 days)`. TTL = 30 days (600 s in E2). A U-lane success on an untainted instance starts a new chain (`bindAt = now`) as in G4. Renewal is Host-side, so it happens in every flow |
| **P-lane refund budget** | `P_acct` 10 / window and 60 / day, `P_proof` 6 / window and 30 / day keyed by the token id (G1 set R values), reserved all-or-nothing before the verifier and **refunded on a recorded success** to the bucket the reservation was taken from. Failures are never refunded. `N_P` (10 new devices per account per day) and `P_glob` stay exactly as in G1. Without outcomes (`pre`) nothing is refunded |
| `P-NETF` (reported) | same caps, but the counters count only recorded failures; admission checks the failure counts |

Token, taint (≥ 3 distinct other accounts failed in the 600 s window), boundary and epoch are those of G4 §1.1.

## 4. Fixtures

All G4 fixtures with their G4 shapes (G4 `PLAN.md` §2), built by the v2 builders. Changed or new:

| ID | Shape | Purpose |
| --- | --- | --- |
| `STEAL` | as G4 up to 43,200 s. At 43,200 s the owner changes the password **from the bound device** (`N-NOPWB` and Part R: epoch bump only). The owner then logs in at 43,260 s and retries every 60 s until admitted; the thief keeps failing every 15 s from rotating IPs to 86,400 s | C4 |
| `E2` | the G4 rows unchanged (token TTL 600 s at 0; owner P logins every 60 s to 540 s with one typo; owner attempts at 630, 750, 870 s failing and at 900 s succeeding; logins every 60 s from 960 s to 2,400 s with typos at 1,200, 1,800, 2,280 s; population of 20 rotating devices failing every 15 s from 0 to 2,400 s; control without the population) | E |
| `K5U` (new) | the owner's token reaches its absolute limit at 600 s (binding chain started 90 days earlier), so the owner is on U from then on **on the same instance**. Owner rows as E2, no population. Variant `fail`: the attempts at 630, 750, 870 s fail; variant `ctl`: those three rows are absent. The U success at 900 s starts a new chain | K5 |

## 5. Values (test values only)

As G4 §3 unless stated: `U_src` 24 (40 in `FULL2-40` and `R-FULL-40`); `F_src` 4 (net); bucket C = 4, T = 1800 s, refund on success;
`U_glob` 1000; P-lane caps as §3; `N_P` 10 / day; token TTL 30 days sliding, absolute 90 days (E2: TTL 600 s); taint 3; CGN
threshold ε = 1 %. Reference Host: `pre+out`.

## 6. Gates

### 6.1 Harness validity

| Gate | Condition | On failure |
| --- | --- | --- |
| **V0** | as G4: S24 on CAND through the v2 replay equals the G3 v2 archive (34 rows) | exit 5 |
| **V1** | Part R reproduces the G4 archive field for field on HAR-B, HAR-N, CROSS, STEAL, CLEAR, E2, BOOT, BOOT-X, CGN-B | exit 5 |
| **IN** | no assertion violation in any scenario | exit 6 |
| **D-IN** | the assertion rejects every reverted family and accepts the corrected ones | exit 6 |

### 6.2 Part R

Every G4 gate (A1, A2, A3, B1, B2, C1, C2, C3, C4, C5, C6, E, P) with its G4 definition and detector, on `R-FULL`, evaluated
afresh. Verdict by G4 `PLAN.md` §5, reported as **"G4 with corrected input"**. The report lists every number that differs from the
G4 archive.

### 6.3 Part N (`FULL2`)

| Gate | PASS condition (`FULL2`, `pre+out` unless stated) | Detector that must fire |
| --- | --- | --- |
| **A1** benign | as G4: every G3 benign stream except MIX ≤ S0 and ≤ S24 | — |
| **A2** | as G4: MIX ≤ S0 (117) | — |
| **A3** | as G4: SP1n, SP3, SP6, ABA_A best-response reach ≤ S12 | — |
| **B1** | as G4: BRU24 attacker admissions ≤ 52, equal to the independent recount | — |
| **B2** | as G4: REC wait from arrival ≤ max(0, 1860 − δ) | — |
| **C1** | as G4: HAR-B 0 owner denials | `R-NO-BIND` denies the owner at least once |
| **C2** | as G4: BRU24-HV attacker admissions ≤ 52 | — |
| **C3** | as G4: CROSS 0 P admissions on victims, reach ≤ `R-NO-BIND` | `R-WEAKBIND` admits ≥ 1 victim request on P |
| **C4v2** | STEAL: thief P admissions ≤ 6 per window and ≤ 30 in the 24 h; 0 thief P admissions after the change; thief U admissions after the change ≤ 28 (C + 43,200 / T); the owner's first admitted login after the change is **on P** and **≤ 60 s** after the first attempt (43,260 s) | `N-NOPWB` does not admit the owner within 60 s |
| **C5** | as G4 (BOOT ≤ 1 %; BOOT-X 0 refusals of bound instances) | `R-NO-BIND` refuses ≥ 1 day-1 request from an instance `FULL2` had bound |
| **C6** (`MODEL`) | as G4: CGN-B N = 5,000, λ = 1, day-3 refusal share ≤ 1 % without the sprayer; 0 refusals of bound clients with it | — |
| **E** | as G4: E2 owner decisions and lanes identical with and without the population | `N-NORENEW` differs |
| **K5** | K5U: the owner's decisions on every row from 960 s identical between `fail` and `ctl` | `N-NOK5SEP` differs |
| **P** (`pre`) | as G4: L1 ≤ 0 and D2 ≤ 36 | — |

### 6.4 Necessity (registered rule, as G4)

For each leave-one-out arm the report names the registered measurements that worsen against `FULL2`; none worsened ⇒ "not shown
necessary". Expectations: `N-PG1` → A1; `N-NORENEW` → E; `N-NOPWB` → C4v2; `N-NOK5SEP` → K5. Not gates.

## 7. Verdict rules (fixed now)

1. A harness-validity failure (V0, V1, IN, D-IN) ⇒ no verdict; the run is invalid.
2. A detector that does not fire ⇒ the gate it guards is not evidenced.
3. **Part N, reference Host (`pre+out`):** `FULL2` is **EXPERIMENTALLY SUPPORTED — LIMITED SCOPE** if A1, A2, A3, B1, B2, C1, C2,
   C3, C4v2, C5, C6, E and K5 all pass with every detector firing; otherwise **NO-GO AFTER EXPERIMENT**, naming every failed gate.
4. **Gate P** is evaluated and written as it is (PASS or FAIL) next to the verdict, as the result for checkOnly-only Hosts; it does
   not change the `pre+out` verdict.
5. **Part R** gets the G4 verdict rule (G4 `PLAN.md` §5), labelled "G4 with corrected input".
6. No gate is re-scoped after a result; a change needs a new plan. Nothing here is an Architecture GO, an ADR, a production value,
   an Owner decision on `U_src` or T, or a statement that Runtime is ready.

## 8. Reporting rules

* Every table separates **measured as planned** from **post-hoc**, and **pipeline fixtures** from **models** (CGN-B).
* `FULL2` against `FULL2-40` is reported on every stream and on CGN-B without declaring a winner; `P-NETF` is reported on every
  gate and stream without a verdict.
* Changes from G4 are listed per number (G4 archive → Part R).
* `IDENTITY_ATTRIBUTION_PROPOSAL.md` receives dated "Post-G4 v2" notes in the style of the earlier notes.

## 9. What this cannot prove

As G4 §6: real cookies, tokens and Hosts; Redis, concurrency, failover, DEC-015 degraded mode; the UA-free K5 material (the
instance id stays in `sessionDeviceId`, untrusted, so the UA remains in the K5 material); real CGNAT traffic; the proof-acquisition
paths of G2; multi-IP spray and `U_glob` design; production values. The password-change event is modelled as a Host event; the
strength of the Host's recovery flow is outside the library and outside this study.

## 10. Reproduction

```bash
bash investigations/k2-identity-admission-97/g4/integrated_v2/run.sh
```

Builds the G4 trees with the archived G4 builder (sha-256 pinned), runs every scenario, the D-IN detector and the report, and
diffs against `expected/`. Exit 0 = `FULL2` supported for `pre+out`; 3 = harness valid and `FULL2` is NO-GO; 5 = V0 or V1 miss;
6 = IN or D-IN failure; 1 = output differs or a scenario fails. `UPDATE=1` regenerates `expected/`.
