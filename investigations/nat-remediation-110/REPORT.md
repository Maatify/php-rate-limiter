# #110 plan 1 — OQC measured against S0 (evidence report)

Level of every number: **S0** = the unpatched runtime (`S0_RUNTIME_OBSERVED`); **OQC / OQCW / OQCE** = the isolated patched candidate
(`CANDIDATE_EXPERIMENTAL_RUNTIME`, `patches/oqc-candidates.patch`). All numbers are generated in [`expected/summary.md`](expected/summary.md) by
`report.php` from deterministic, synthetic streams on the real `EvaluationPipeline`; they are not production incident statistics. Counts are requests.
Flows: `pre+out` = Host calls `checkOnly` then records the outcome if allowed; `pre` = `checkOnly` only; `out` = record-only. **Nothing here is a
decision**: DEC-019 remains ACTIVE; the Owner decides DEC-020 under Decision Governance.

## 1. Gates

| Gate | S0 | OQC | OQCW | OQCE |
|---|---|---|---|---|
| G1 baseline reproduction (published S0 numbers **and the #100 Lead control ABA_A 3/120**, reproduced on the real pipeline) | PASS | — | — | — |
| G2 checkOnly-only Hosts keep S0 exactly (every `pre` cell identical) | — | **PASS** | FAIL (by design: Q1 changes K1 everywhere — `Nu4` 47→4, `Nu5` 47→39, `K1R4` reach 4→200) | **PASS** |
| G3 `API_OVERUSE` / custom API untouched | — | PASS | PASS | PASS |
| G4 protection bound (extra reach ≤ triggers + max(0, 5 − S0 reach) + 1) | — | PASS | FAIL (`K1R4` +4 with 0 triggers: 4 failing subjects never trigger K1 under Q1) | PASS |
| G5 fairness non-regression (no benign / mixed / poisoning cell worse than S0) | — | FAIL (`MIXN` record-only 0→15; `MIXN`/otp record-only 4→19) | FAIL (same) | FAIL (`MIXN` 49→53 and record-only 0→53; `Y4c` 28→29; `Y6c` out 27→29) |
| G6 targeted probes | PASS | PASS | PASS | PASS |
| G7 rotation (3 modes) and IPv6 macro scopes | PASS | PASS | PASS | PASS |
| G8 storage bounds, rollback, mixed version | PASS | PASS | PASS | PASS |
| G9 real Redis parity, TTL hygiene, markers | PASS | PASS | PASS | PASS |
| G10 concurrency on real Redis (F111-02): simultaneous `checkOnly` bursts on live scopes, block level inside one burst | PASS | PASS | PASS | FAIL (escalation reaches L4 inside one burst) |

G10 PASS means the concurrency measurement is valid, not that the candidate is safe under concurrency: its result (§4, §5.1) is that the per-cycle leak of every candidate arm equals the concurrency of the burst.

A FAIL above is a **finding** against the candidate (the evidence is valid). The three findings are explained in §5.

## 2. Fairness — legitimate requests denied (Login with account)

| Stream | Flow | S0 | OQC | OQCW | OQCE | What still denies on OQC |
|---|---|---|---|---|---|---|
| D2 shared NAT office, fresh fingerprint per login, 8 % typos | pre+out | 117/121 | **30/121** | 30/121 | 53/121 | distributed-account K5 ×18, K2 churn from the typos ×12 |
| D2 | out | 115/121 | **16/121** | 16/121 | 51/121 | K2 churn ×15 |
| D2 | pre (checkOnly only) | 115/121 | 115/121 | 115/121 | 115/121 | S0 (silent scope, by design) |
| L1 two stable colleagues, no typos | pre+out | 127/134 | **0/134** | 0 | 0 | — |
| L1f the same with 8 % typos | pre+out | 127/134 | 96/134 | 96 | 96 | score (K4/K5 of the typists) ×60, K2 churn ×34 |
| N3 / N6 / N12 stable fingerprints, same UA | pre+out | 56/61 | **0 / 9 / 2** | same | 0 / 14 / 2 | score ×5, K2 churn ×4 |
| Nu4 / Nu6 / Nu12 (K1-isolation control) | pre+out | 47/61 | **0/61** | 0 | 0 | — |
| N12T / N12M trusted / half trusted | pre+out | 56/61 | 2/61 | 2 | 2 | score |
| ABA_B innocent A/B/A pair (#100) | pre+out | 118/120 | **0/120** | 0 | 0 | — |
| ABA_B1 ABA_B + two typos by B | pre+out | 118/120 | 0/120 | 0 | 0 | — |
| GAP typos, successes, 700 s silence, return | pre+out | 50/52 | **0/52** | 0 | 0 | — (the stale 1800 s WATCH never gets its second increment) |
| OTPB OTP office, 10 % mistyped codes | pre+out | 58/61 | 13/61 | 13 | 12 | score ×5, K2 churn ×8 |
| D1 LOW population, 600 users (#97) | pre+out | 596/600 | 596/600 | 596 | 596 | unchanged: LOW dilution is outside OQC |
| D6 3-fingerprint burst then one lone client | pre+out | 120/300 | 12/300 | 12 | 12 | |
| X4 / X6 NAT with an insider rotating fingerprints | pre+out | 79/81 | 4/81 | 4 | 4 | |
| Y4d / Y6b poisoning with successes only | pre+out | 9/30 · 28/30 | **0 · 0** | 0 · 0 | 0 · 0 | successes are no longer evidence |
| Y4f / Y6c poisoning paying failures | pre+out | 79/81 · 28/30 | 12/81 · 5/30 | same | 75/81 · 25/30 | escalation punishes the shared scope |
| MIX D2 office + spray attacker behind the same NAT | pre+out | 117/121 | 51/121 | 51 | 106/121 | |
| MIXN 12 isolated colleagues + attacker | pre+out | 49/61 | 15/61 | 15 | **53/61** | |
| MIXN | out | **0/61** | **15/61** | 15 | 53/61 | Record-only is a post-outcome signal; it does not protect K1 before verification |

Other shapes (pre+out): D2 on OTP 117→49 (OQC) / 65 (OQCE); D2 on Login-without-account 117→15 / 51; OTPB on OTP 58→34 / 32.

## 3. Protection — attacker attempts that reached verification (Login, pre+out), with correlation blocks issued in brackets

| Stream | S0 | OQC | OQCW | OQCE | checkOnly-only (S0 = OQC = OQCE) |
|---|---|---|---|---|---|
| ABA_A the #100 Lead control (2 fingerprints alternating, 120 / 1200 s) | **3**/120 (35) | **23**/120 (36) | 23 (36) | **5**/120 (4) | 3 |
| SP1f spray, fresh fingerprint per request | 2/200 (17) | 12/200 (18) | 12 | 5/200 (4) | 2 |
| SP1n spray, no fingerprint | 4/200 (10) | 14/200 (10) | 14 | 7/200 (3) | 4 |
| SP3 credential stuffing, 1000 accounts | 7/1000 (17) | 24/1000 (17) | 24 | 7/1000 (3) | 7 |
| SP6 one hour of spray | 20/1200 (59) | 79/1200 (59) | 79 | **14**/1200 (6) | 20 |
| SP2 slow spray at 4 victims / 601 s | 60/72 (12) | 72/72 (12) | 72 | 72/72 (12) | 60 |
| SP4 8-IP botnet, shared UA (dilution) | 78/576 | 78/576 | 78 | 78 | 78 |
| SP4u 8-IP botnet, own UA each, 4 victims / IP / 601 s | 480/576 (96) | 576/576 (96) | 576 | 576 | 480 |
| SP4b 3 victims / IP / cycle | 432/432 | 432 | 432 | 432 | 432 |
| SP5 spray from a trusted session device | 200/200 | 200 | 200 | 200 | 200 |
| SP5c trusted session device + fresh fingerprint per request | 2/200 (17) | 12/200 (18) | 12 | 5/200 (4) | 2 |
| K1R4 4 victims retried for 10 min | 4/200 (10) | 8/200 (4) | 8/200 (**0**) | 7/200 (3) | 4 (OQCW: 200) |
| X1 fingerprint churn against one victim | 2/61 | 2/61 | 2 | 2 | 2 |
| X2 / X7 distributed LOW (dilution) | 6/121 | 6/121 | 6 | 6 | 6 |
| Y1 4-IP pool, fresh fingerprint + account per request | 8/121 (68) | 48/121 (72) | 48 | 20/121 (16) | 8 |
| Y2a 2 fingerprints / 601 s hovering at N−1 | 6/8 | 8/8 | 8 | 8 | 6 |
| Y7 fixed pool of 3 fingerprints | 3/241 (35) | 23/241 (36) | 23 | 5/241 (4) | 3 |
| Y9a one IPv6 /64, rotating interface id | 2/31 (5) | 7/31 (5) | 7 | 4/31 (2) | 2 |
| Y9b six /64 of one /48 | 3/12 (10) | 12/12 (9) | 12 | 12/12 (9) | 3 |
| D5 churn attacker padding every failure with a success | 3/122 (18) | 14/122 (18) | 14 | 7/122 (4) | 3 |
| D7 persistent single-K2 attacker, 1 / s for 30 min | 4/1801 (53) | 34/1801 (54) | 34 | **5**/1801 (4) | 4 |
| MIX / MIXT / MIXN attacker behind the office NAT | 0 / 3 / 2 | 13 / 14 / 16 | same | 6 / 8 / 9 | 0 / 3 / 2 |

Record-only Hosts (attacker requests whose `recordFailure` **returned a block**): SP1n 0→195/200, SP3 0→991/1000, SP6 0→1174/1200, ABA_A 117→117,
Y1 113→113 on OQC. A record-only Host never calls `checkOnly`, so no persisted block is consulted before any verification: every one of these requests was
verified by the Host before the library saw it, and a returned block prevents nothing unless the Host acts on the returned decision itself. S0 has no K1
signal at all for record-only Hosts (#108 L2); OQC returns one from the second failure of a scope. This is a **post-outcome signal**, not prevention
(F111-03).

## 4. The mechanism, as measured

* **One K1 observation per logical attempt** (O02 C1): `11111000` in pre+out on every candidate arm — the 5th distinct failing subject is observed at its
  `recordFailure` (after verification), the 6th is denied at `checkOnly`. Record-only: `01111100` — the first failure only makes the scope live.
* **The liveness marker** (O02 M1, O04 B1/B2, O05 P4): 0 keys after `checkOnly`, exactly 2 window keys per scope after the first outcome (K1 scope +
  K2 scope), no new key for later outcomes in the same 600 s window, 2 more in the next window, each pair expires 1200 s after its window write; 50 scopes
  → 100 keys, 0 per request; present on real Redis with TTL ≤ 1200; every arm key gone 1801 s after a stream.
* **Success never erases failure evidence** (O02 P1, D5, Y4d/Y6b): five own-account successes between failures do not move the K1 count; D5's padding
  attacker still pays one verification per block cycle; the successes-only poisoners (Y4d, Y6b) no longer poison the office at all.
* **checkOnly-only Hosts are S0 to the byte** (G2 PASS for OQC and OQCE, 317 cells each). **Record-only Hosts get a post-outcome K1 signal** (and its collateral, §5).
* **Concurrency (O06, real Redis, F111-02)**: on an outcome-live scope the candidate observes nothing at `checkOnly`, so a burst of simultaneous requests
  that all precede the first `recordFailure` is verified in full: **16 of 16** simultaneous distinct failing subjects pass a live K1 scope (S0: 3 of 16,
  the K1 set is observed atomically at `checkOnly`), **8 of 8** simultaneous distinct fingerprints pass a live K2 scope (S0: 0 of 8 in every measured
  round, at most 1 by the sequential rule), identical on OQC, OQCW and OQCE in every one of 3 rounds; the next sequential `checkOnly` after the burst is
  denied on every arm; the same K1 burst on a *silent* scope passes exactly 4 of 16 on every arm (the silent path is S0, observed atomically). Hence the
  sequential "+1 per correlation block" of §5.1 is a lower bound: **the per-cycle leak equals the concurrency of the attacker**, and escalation (OQCE)
  shortens the number of cycles, not the leak inside one. **OQCE under concurrency escalates inside a single burst**: the concurrent failures each
  re-issue the block before the previous one is persisted, so one burst reaches **L4 / 1800 s** (G10 FAIL for OQCE); a NAT office whose colleagues
  mistype simultaneously would be locked out for 30 minutes.
* **Rotation** (O03): 6 streams × 3 modes, every arm within the reference ± 2 (D2 on OQC: 32/32/30 vs 30). **IPv6**: the all-success /48 office goes from
  25/31 denied (S0, macro spray) to 0/31 on every candidate arm; the attacker rotating /64s stays at 5/200.
* **Rollback / mixed version** (O04 R1/R2): the unpatched rule continues on candidate state without error (second 600 s of ABA_A: 1 verification, S0
  semantics back); alternating old/new code per request never errors (SP1n 14 vs 14, ABA_A 13 vs 23).
* **Typo cost** (O02 T1): on a 3-device NAT two typos by one colleague inside 600 s cost the *other* colleagues four denials (one 60 s K2 churn block at
  t=200); the typist's own 14 later denials are her K4/K5 score, identical on S0. The K2 WATCH-at-2 rule (root cause 2 of #110) is untouched by OQC.

## 5. Findings the Owner must weigh (each is a measured number, not an estimate)

1. **The attacker leak is "+1 verification per correlation block" for a sequential attacker, and that is 3→23 on the #100 control.** Because the evidence
   arrives after the verification, every expired block admits one more verified attempt before it is re-issued: ABA_A 3→23/120, SP1n 4→14, SP3 7→24,
   SP6 20→79, D7 4→34, Y1 8→48, SP5c 2→12. The #100 Lead gate was "no increase over S0"; OQC does not meet it, and the G4 bound (≤ triggers + untouched
   5-subject budget + 1) only formalises the leak, it does not remove it. The slow hovers SP2 (60→72/72) and SP4u (480→576/576) lose S0's
   WATCH-at-pre-check catch entirely. **Under concurrency the "+1" becomes "+N"** (O06): 16 of 16 simultaneous subjects pass a live K1 scope and 8 of 8
   fingerprints a live K2 scope where S0 passes 3 and 0. Any design that moves enforcement from the pre-check to the outcome carries this property; it
   is the cost of evidence arriving after the verification, not a tuning issue.
2. **Escalation (OQCE) buys the leak back — ABA_A 5, SP3 7, SP6 14 (below S0), D7 5 — at a fairness price**: D2 53/121 instead of 30 (still far from
   S0's 117), MIX 106/121, and it is *worse than S0* on the mixed isolated office MIXN (49→53) and on the failure-paying poisoners Y4c/Y6c. Escalation
   applies only to live scopes, so checkOnly-only Hosts stay S0 (G2 PASS).
3. **Record-only Hosts get a post-outcome K1 signal and its shared-NAT collateral at once**: the `recordFailure` of SP1n/SP3/SP6 returns a block
   195/991/1174 times instead of 0 (after the verification already happened, §3), and MIXN (12 colleagues + an attacker behind the NAT) goes from 0/61
   to 15/61 colleagues denied. S0 had neither. This is the G5 FAIL on OQC/OQCW.
4. **OQCW (K1 WATCH on new members) changes K1 for every Host, not only outcome-reporting ones** (G2 FAIL, by construction of Q1): `Nu4` 47→4 and
   `K1R4` 4→200 in checkOnly-only flows. It adds nothing over OQC where outcomes are reported (every pre+out cell identical). It is a separate K1 policy
   question (the documented 5 vs the implemented 4+repeat), not an OQC arm.
5. **What OQC does not touch is now visible in the residue**: the remaining D2 denials are distributed-account K5 (18) and K2 churn from typos (12); L1f
   stays at 96/134 because two typists' K4/K5 scores and the K2 WATCH-at-2 rule are S0; D1/LOW (#97) is unchanged at 596/600.

## 6. Limits of the proof

Deterministic synthetic streams, the reference stores and the controlled clock (real Redis for sequential parity, TTL hygiene and the concurrent bursts of
O06; the 60/600/1200 s boundaries are proved on the controlled clock; the S0 rule that denies the second fingerprint of a concurrent K2 burst, where the
sequential run allows it, was not isolated). Rotation on the candidate's extra keys (tally, re-issue counter) is keyed on the Current scope only
(documented). The candidate is an investigation patch with `getenv` arm selection and is **not** production code; a Runtime change would be a separate,
reviewed Work Unit after an Owner decision under DEC-019 §5 / Decision Governance. Nothing here closes #97, #100 or #106.
