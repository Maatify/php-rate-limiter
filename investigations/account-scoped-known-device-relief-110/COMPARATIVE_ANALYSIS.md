# Comparative Analysis — main, S0 (prior) and the relief variants

Three reference points, never mixed in one number:

* **S0** `[PRIOR]`: the shipped runtime before DEC-021, as measured by #113. Numbers are read from
  `investigations/trusted-multi-session-s0/expected/t01_matrix.jsonl` and never re-run.
* **main** `[MAIN]`: today's unpatched runtime (DEC-021 ACTIVE).
* **variants** `[MODEL]`: relief models on main (`ARCHITECTURE.md` §4).

## 1. S0 versus main (what DEC-021 changed for this question)

From `CURRENT_STATE.md` §6 and `expected/summary.md` §2:

* **Unchanged:**
  * the trusted office collateral (N12T 56/61);
  * the K2 bound of the multi-session attacker (TMSd 2/200, TMS 7/61);
  * every check-only result;
  * SP5 (200/200).
* **Changed, in `pre+out` only:**
  * TMSs 18 → 5;
  * TMX 200 → 60;
  * TMXb 300 → 48.

  DEC-021 now bounds a known device per account, at about 5 Login verifier reaches per account per 600 s.

* **Consequence for this study:** on S0, relieving K2 for trusted requests would have left **no** per-account bound. #113 §3.2 gave 200/200 as
  the conditional expectation. On main, DEC-021 supplies one, so the measured relief result is 60/200 in `pre+out`, not 200/200. In `pre`
  it is 200/200, exactly the #113 expectation, because DEC-021 does not learn from check-only Hosts.

## 2. The variants against main

### 2.1 Availability

| Population | MAIN | A | B | C1 | C2 | C3 | D1 | D2 |
|---|---|---|---|---|---|---|---|---|
| N12T trusted office (denied / 61) | 56 | 0 | 56 | 0 | 0 | 0 | 0 | 28 |
| N12M half trusted (denied / 61) | 56 | 30 | 56 | 30 | 28 | 26 | 30 | 34 |
| N12 untrusted, unknown (denied / 61) | 56 | 56 | 56 | 56 | 56 | 56 | 56 | 56 |
| N100 LOW, 100 % known (locked / 100) | 88 | 88 | 0 | 0 | 0 | 0 | 0 | 80 |
| N100 MED, 100 % known | 95 | 88 | 95 | 0 | 0 | 0 | 0 | 80 |
| N100 LOW, 90 % known: unknown locked / 10 | 10 | 10 | 9 | 9 | 8 | 1 | 9 | 10 |
| N100 MED, 90 % known: unknown locked / 10 | 10 | 10 | 10 | 9 | 9 | 6 | 9 | 10 |
| N250 LOW, 90 % known: unknown locked / 25 | 24 | 24 | 22 | 22 | 22 | 16 | 22 | 25 |
| Bootstrap LOW, day 0 / day 13 locked / 100 | 87 / 88 | — | — | 87 / 10 | 87 / 5 | 87 / 0 | 87 / 10 | — |
| Bootstrap MED, day 0 / day 13 locked / 100 | 95 / 95 | — | — | 95 / 41 | 95 / 40 | 95 / 24 | 95 / 41 | — |

### 2.2 Security (attacker reach, Login)

| Attacker | MAIN | A | B | C1 | C2 | C3 | D1 | D2 |
|---|---|---|---|---|---|---|---|---|
| TMS (12 sessions, office cadence) / 61 | 7 | 61 | 7 | 61 | 61 | 61 | 61 | 33 |
| TMSd `pre+out` / 200 | 2 | 60 | 2 | 60 | 60 | 60 | 60 | 25 |
| TMSd `pre` / 200 | 2 | 200 | 2 | 200 | 200 | 200 | 200 | 85 |
| D24T `pre+out` / day | 172 | 504 | 172 | 504 | 504 | 504 | 248 | 247 |
| KS50 `pre+out` / 200 | 2 | 200 | 2 | 200 | 200 | 200 | 200 | 20 |
| SPV (Host flag on any account) / 200 | 4 | 4 | 200 | 200 | 200 | 200 | 200 | 5 |
| NC sprayer on N100 LOW 90 % / morning | 13 | 13 | 14 | 14 | 16 | 21 | 14 | 11 |
| NC sprayer, bootstrap LOW, max per day | 13 | — | — | 15 | 14 | 23 | — | — |
| KC on N100 LOW 90 % / 200 | 1 | 1 | 60 | 60 | 60 | 60 | 60 | 12 |

**Reading:**

* Every variant that removes the office's collateral (A, C1, C2, C3, D1) gives the credentialed attacker the same relief.
* The variants that do not (B in MED, A in LOW, D2) leave the office mostly blocked.
* C3 is the best for unknown users and the worst for the credential-less sprayer.
* D1 is the only variant that lowers the 24 h gain (504 → 248, against main's 172). It changes nothing in the first 600 s, or for
  check-only Hosts.

## 3. The #110 unified framework (A–H), for C1 and D1

| Item | C1 | D1 |
|---|---|---|
| **A. What changes** | Enforcement scope only: a known request is not denied by K1 / K2. Evidence source, trigger and thresholds unchanged; the known request still counts and still triggers collective blocks | same, while the account's DEC-021 allowance is unspent |
| **B. Protection vs main** | Credential-less streams (SP1n, SP2, SP3, SP4*, SP6, the X / Y / D series): **identical to main** whenever they carry no known request (G-INERT, by construction). Mixed with a known office: NC −3 … +3 across the O01 cells under C1 (`expected/summary.md` §7). Known-device attackers: TMS 7 → 61, TMSd 2 → 60 (`pre` 200), D24T 172 → 504, KS50 2 → 200. Host-violation control (not compliant traffic): SPV 4 → 200 | same, except D24T 172 → 248 |
| **C. Fairness** | Only streams whose users are known change: N12T 56 → 0, N12M 56 → 30. The framework's #97 D1 / L1, #100 ABA_B and #106 Nu4 / Nu12 / D2 streams carry no known request, so they are **unchanged** (G-INERT). They improve only to the extent their users become known (bootstrap, §2.1) | same |
| **D. Mandatory dimensions** | `pre+out` / `pre` / `out` measured. Login and OTP measured. IPv6, rotation, Redis concurrency, custom policies, API_OVERUSE: analytic (`SECURITY_ANALYSIS.md` §10). Trusted and untrusted collateral measured (N12T, N12M, offices) | same; plus the allowance read is a time-of-check race |
| **E. Compatibility** | No public API, Builder, DTO or Policy change; no new key; no migration. It depends on the Host flag, whose semantics would need Host guidance | same |
| **F. What it does not solve** | Unknown / new / cookie-less users, day 0, LOW cohorts of anonymous users (#97 D1), the ABA_B innocent pair (#100), untrusted colleagues (#106) | same |
| **G. Open points / contradictions** | Same relief for office and attacker (`ARCHITECTURE.md` §3); check-only collapse; amplification of Host-contract violations (SPV, a control); the pool effect if consumption is dropped (C3) | same; and D1 does not bound the first 600 s |
| **H. Root causes addressed** | (4) "enforcement scope wider than the actor", for known requests only. (1) command feeding, (2) WATCH counting requests, (3) level-triggered evaluation and (5) the K1 / K2 overlap are untouched. (5) forces K1 and K2 relief together (R9) | same |

## 4. Earlier #110 studies (`[PRIOR]` figures as recorded in #110)

| Study | Idea | Office gain | Attacker cost | Verdict |
|---|---|---|---|---|
| #111 OQC | outcome-qualified correlation evidence | D2 117 → 30 | A/B/A 3 → 23; Redis K1 3 → 16/16 | NO-GO |
| #113 trust family on S0 | K2 advisory for trusted requests (inferred) | — | TMSd 2 → 200 expected (TMX control) | structural NO-GO; candidate unmeasured |
| #115 GNH5 | ×5 network headroom + budget | D1 596 → 584, ABA_B 118 → 106, D2 117 → 93, Nu 47 → 0 | TMSd 2 → 8, SP4 78 → 294 | NO-GO |
| #116 OAAA | outcome-aware atomic admission | ABA_B 118 → 0, Nu 47 → 0, D2 117 → 70, L1 127 → 0, D1 596 → 579 | Y6b 2 → 36/36 on own accounts; `pre`-only X3 FAIL | NO-GO |
| #118 HMCI | host-issued browser id / earned standing | bootstrap-limited (day 9 in idealized model) | B5 13 → 22 per day (model); M3 sprayer 200/200 | C not acceptable; direction INCONCLUSIVE |
| **this study** | relief for host-proven known devices | known office 56 → 0; known browsers fully relieved; unknown unchanged | TMS 7 → 61, TMSd 2 → 60 (`pre` 200), D24T 172 → 504 (D1 248); Host-violation control SPV 4 → 200 | **NO-GO** for the seven modelled variants |

What this study adds to them:

* **#113:** the trust family's K2 relief, measured on post-DEC-021 main and extended to previously-verified devices. The structural NO-GO is
  confirmed with numbers. DEC-021 makes the `pre+out` cost finite (60, not 200, per 600 s). The `pre` cost is complete.
* **#118:** the account-scoped D variant HMCI named, measured. "Zero new cross-account subjects" holds, but the gain is not zero. B5 is
  reproduced on the real pipeline when consumption is dropped (C3) and mostly avoided when it is kept (C1 / C2). The bootstrap gap is
  measured without idealization: day 0 equals main in every run.
* **#116:** the same "own-account success" weakness appears in a different form. The proof the attacker holds is a proof of association, not
  of the current attempt.
