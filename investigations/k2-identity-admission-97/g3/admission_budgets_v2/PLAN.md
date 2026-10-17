# G3 v2 — Net failure cap and known-device routing — pre-registered plan (#97)

> **Status:** PRE-REGISTRATION, plan **v2** of the G3 admission-budget study. Committed **before** any v2 harness code exists and
> before any v2 result. Evidence only. **Not** an architecture, policy, threshold or contract approval. No change to `src/`,
> `tests/`, `composer.json`, public contracts, presets, ADRs, standards, or the G1 / G2 / G3 v1 evidence. DEC-015, DEC-019 and
> DEC-021 stay ACTIVE and unchanged.
> **Baseline:** `main@3465dd371c9dafc0d5056fae560cfe1a4042fa8f`. `src/`, `composer.json` and `composer.lock` there are
> byte-identical to the G1 baseline `4c6ca618d8206ba502961eaf06ffb82a5d61b50d`, so the G1 and G3 builds apply unchanged.
> **Why v2:** G3 v1 ([`../admission_budgets/PLAN.md`](../admission_budgets/PLAN.md), report
> [`../../G3_ADMISSION_BUDGETS.md`](../../G3_ADMISSION_BUDGETS.md)) ended NO-GO for FC and for TB. v1 rule 6 requires a new plan for
> any change of arms, fixtures or gates. **v1 and its results are not modified;** v2 lives in its own folder and reuses the v1
> library read-only.

## 0. What v1 showed and what v2 changes

| v1 finding | v2 change | Reason |
| --- | --- | --- |
| FC4 refused offices whose typos produced failures on several accounts in one window (D2 32 vs S40 27) | **Net failure cap:** an account counts against the source only while its last outcome from that source in the window is a failure; a later success on the same account from the same source removes it | A typo followed by a successful retry is not spray. An attacker cannot remove a victim account this way, because that needs the victim's password, so no foreign failure can be washed out (#116 E4 does not apply) |
| — | A "failures ≥ 2 per account" rule is **not** tested | Spray tries each victim once (SP3: 1,000 accounts, one attempt each), so such a rule would never count a spray victim. This was proposed in conversation and is rejected here on that ground |
| FC refused the office in MIX, where a sprayer shares the source | MIX is evaluated against a separate **residual** bound (≤ S0), not against S40 | A co-located attacker and an unproven office are indistinguishable at the source (`F§5`, rows 3–4); G3 CGN measured 92–100 % co-tenant refusal for every source cap. v2 reports it and requires only that MIX is not worse than today |
| TB-3 failed on UAC: the bucket counted a known-device owner's own failures | **Known-device routing:** a U-lane request whose device is known for the account (`isTrustedSession` or `isDevicePreviouslyVerifiedForAccount`, the pipeline's own `isKnownForAccount` rule) is not charged to the account bucket; it stays under `U_src`, `U_glob` and the DEC-021 K5 path | In the proposal a known or bound device is in P and never meets the strangers' bucket; binding is not built, so the Host's existing known-device signal stands in for it |
| TB-2 was unsatisfiable for owners arriving after `T + 60` s (plan defect) | **TB-2 corrected:** wait counted from the owner's own arrival must be ≤ `max(0, T + 60 − δ)` | The v1 wording required an admission before the owner had arrived |
| `U_src` = 24 had no benign stream worse than S0 | FC arms run with outer ceilings **24 and 40** | 24 is now a candidate for the spray budget |

## 1. Hypotheses

* **H-FCN.** For at least one (outer ceiling `X`, `F_src`), the net failure cap gives, in `pre+out`, single-IP spray reach (SP1n,
  SP3, SP6, ABA_A) **≤ S12**, legitimate denials on every non-co-located benign stream **≤ S`X`**, and MIX legitimate denials
  **≤ S0**.
* **H-TBK.** For each T, the bucket with refund and known-device routing keeps H-TB-bound and the corrected H-TB-recovery of v1,
  and legitimate denials on every benign stream (UAC and KD included) **≤ S40**.

## 2. Arms

All arms reuse, unchanged: the G1 candidate build (`CAND`), the G3 v1 ablation build (`NOESC`), the G1 admission layer, and the v1
`G3Gate` / `g3Replay` (`../admission_budgets/lib/g3.php`). v2 adds a subclass-free wrapper in `lib/g3v2.php` that only changes
(a) when an account enters or leaves the failure set and (b) whether a request is charged to the account bucket.

| Arm | Build | Outer `U_src` | Definition | Role |
| --- | --- | --- | --- | --- |
| `S24`, `S40`, `S12` | CAND | 24 / 40 / 12 | v1 references, re-run; must equal the v1 archive (V0) | reference |
| `FCN4-24`, `FCN8-24` | CAND | 24 | net failure cap, `F_src` = 4 / 8 | candidate |
| `FCN4-40`, `FCN8-40` | CAND | 40 | net failure cap, `F_src` = 4 / 8 | candidate |
| `FC4-40` | CAND | 40 | v1 FC4 (gross failures), re-run | **detector**: must exceed `FCN4-40` on D2 — shows the net rule is what relieves typo offices |
| `TBK10R`, `TBK30R`, `TBK60R` | NOESC | 40 | v1 TB-R (C = 4, refund on success) + known-device routing | candidate |
| `TB30R` | NOESC | 40 | v1 TB30R, re-run | **detector**: must still exceed S40 on UAC — shows routing is what fixes it |
| `TBK30R-HV` | NOESC | 40 | `TBK30R` on `BRU24-HV`: the attacker's requests carry `isDevicePreviouslyVerifiedForAccount = true` (a Host that vouches for the wrong device) | **measured only**: the reach a Host violation buys through routing |

## 3. Values (test values only)

As v1 (`W` = 600 s, `U_acct` fixed window 16 where used, `U_glob` 1000, P parameters of G1 set `R`, bucket `C` = 4, `T` = 600 /
1800 / 3600 s), plus outer `U_src` = 24 for the `-24` arms. No new numeric value is introduced.

## 4. Streams and fixtures

The v1 §4.1 streams in `pre+out` for every arm; `pre` for `FCN4-24`, `FCN4-40`, `TBK30R`. The v1 `BRU24` and `REC` fixtures for
the TB arms, unchanged. New: `BRU24-HV` = `BRU24` with `isDevicePreviouslyVerifiedForAccount = true` on every attacker request.
CGN is not re-run (it does not depend on the v2 changes for spray-free traffic; its co-located-attacker result is a theorem-level
residual).

## 5. Gates

| Gate | PASS condition | Detector that must fire |
| --- | --- | --- |
| **V0** | `S12`, `S24`, `S40` (CAND) and `TB30R` (NOESC) rows equal the archived v1 rows (`../admission_budgets/expected/`) field for field. A miss is a harness failure (exit 5) | — |
| **FCN-1** spray | for an arm `FCN{F}-{X}`: SP1n, SP3, SP6 and ABA_A best-response reach ≤ S12 | — |
| **FCN-2** benign | same arm: every v1 §4.1 benign stream except MIX has legitimate denials ≤ S`X` | `FC4-40` > `FCN4-40` on D2 |
| **FCN-3** residual | same arm: MIX legitimate denials ≤ S0 (117), and MIX attack reach ≤ S`X` | — |
| **FCN-4** pre | `pre` decisions of `FCN4-24` / `FCN4-40` identical to S24 / S40 | — |
| **TBK-1** bound | each `TBK` arm: attacker admissions on `BRU24` ≤ `C + ⌊86400 / T⌋`, equal to the independent recount | — |
| **TBK-2** recovery | each `TBK` arm, `REC`: for every δ, wait from the owner's arrival ≤ `max(0, T + 60 − δ)` | — |
| **TBK-3** benign | each `TBK` arm: every v1 §4.1 benign stream (UAC and KD included) has legitimate denials ≤ S40 | `TB30R` > S40 on UAC |

`TBK30R-HV` and the `out` flow are reported without a gate.

## 6. Verdict rules (fixed now)

1. A detector that does not fire ⇒ the gate it guards is not evidenced; no candidate is supported on it.
2. FCN is **EXPERIMENTALLY SUPPORTED — LIMITED SCOPE** if FCN-1, FCN-2, FCN-3 and FCN-4 pass for at least one arm; the verdict
   names the arm(s) and is limited to `pre+out` Hosts. Otherwise **NO-GO AFTER EXPERIMENT**.
3. A `TBK` value of T is **EXPERIMENTALLY SUPPORTED — LIMITED SCOPE** if TBK-1, TBK-2 and TBK-3 pass for it; otherwise **NO-GO
   AFTER EXPERIMENT** for that T. Each T is judged separately, and the support is conditional on Host-honest known-device
   signals (`TBK30R-HV` reports the cost of a dishonest Host).
4. No result here is an Architecture GO, an ADR, a production value, or an Owner decision on `U_src` or T.
5. Any substantive change after a v2 result requires plan v3 with the reason, keeping v2 results.

## 7. What this cannot prove

Everything listed in v1 §7, plus: known-device routing trusts the Host's flag exactly as DEC-021 does today; the binding of the
proposal (a library-verified token) is not built, so this is a stand-in, not a test of binding. The co-located attacker case
(MIX) is reported against S0 only, because no source rule can separate it.

## 8. Reproduction

```bash
bash investigations/k2-identity-admission-97/g3/admission_budgets_v2/run.sh
```

Builds the CAND and NOESC trees with the v1 builder (`../admission_budgets/build_g3.sh`, hashes pinned), runs the v2 scenarios,
writes `summary.md` and `gates.json`, and diffs against `expected/`. Exit 0 = every gate passes; 3 = harness valid and a gate fails
or a detector does not fire; 5 = V0 miss; 1 = output differs from `expected/` or a scenario fails. `UPDATE=1` regenerates `expected/`.
