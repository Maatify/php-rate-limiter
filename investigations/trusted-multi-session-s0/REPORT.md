# #110 trust family — the R23-01 counter-case on S0: report

**Evidence only.** Level `S0_RUNTIME_OBSERVED` (unpatched `EvaluationPipeline`, sha-256 `4b259d33…f4fc`, baseline `ce89cb2`). No candidate was built;
no design is selected, approved or rejected here. DEC-019 stays ACTIVE; #97 / #100 / #106 / #110 stay open. All numbers below are generated in
[`expected/summary.md`](expected/summary.md) (gates G1–G4 PASS: provenance, baseline reproduction `N12T` 56/61 and `SP5` 200/200, stream construction,
evidence completeness). Counts are requests, not people.

## 1. The question and the answer in one table (Login with account)

The R23-01 attacker: valid trusted sessions for 12 accounts behind one shared IP + User-Agent, every attempt fails.

| Stream | What it is | `pre+out` reach | `pre` (checkOnly only) | `out` (record-only) | What bounds it |
|---|---|---|---|---|---|
| `TMS` | the attacker at the trusted office's own cadence (= `N12T` with `ok` → `fail`) | **7/61** (2 in the first 600 s, max 2 per account) | 6/61 | 61/61 | K2 churn from request #2 on (the sessions are 12 distinct fingerprints on one IP+UA); K1 fires too but is advisory for trusted |
| `TMSd` | dense, 1 / 3 s cycling the 12 targets | **2/200** | 2/200 | 200/200 | K2 churn at request #2, re-issued every 60 s; 10 K2 blocks over 600 s |
| `TMSs` | dense, sequential per target (17 each) | **18/204** (17 on the first target, 1 on the second) | 18/204 | 204/204 | the first target is verified 17 times before the second session makes the K2 set churn |
| `TMR` | `TMSd` rotating the client fingerprint every attempt | **2/200** | 2/200 | 200/200 | K2 churn; §5.2 distributed-account also fires (K5 blocks) but K2 is first |
| `TMU` | untrusted control (same 12 session ids, `isSessionTrusted = false`) | **2/200** | 2/200 | 200/200 | identical to `TMSd` in `pre+out` / `pre`: trust changes nothing for this attacker in S0, because K2 (not K1) is what denies him |
| `TMX` | Host-violating control: one trusted session against all 12 targets | **200/200** (17 per account) | 200/200 | 200/200 | **nothing**: K1 issues 196 blocks, all advisory; K2 sees one fingerprint; no K4/K5/budget denial in 600 s |
| `TMXb` | `TMX` sequential, 25 per account, 900 s | **300/300** (25 per account) | 300/300 | 300/300 | **nothing** (see §3.3) |
| `SP5` | #108 reference: one trusted session, 200 fresh accounts | 200/200 | 200/200 | 200/200 | nothing (published) |

**Reading.** In the shipped runtime the only thing standing between the multi-session attacker and his targets is the **shared K2 churn set** (IP + UA,
fingerprint members): it denies him from the second request (2/200 dense, 7/61 at the office cadence) in both pre-check flows. Every per-account
protection is silent for a trusted session at these volumes (`TMX`/`TMXb`: 25 failures per account, zero denials). Record-only (`out`) is 200/200
everywhere, as #111 established: the decision arrives after the verification.

## 2. S0 cannot tell the trusted office from the trusted multi-session attacker

`TMS` is `N12T` request for request (same t, NAT, browser, session-derived fingerprint index, trust flag); only the outcome differs (`ok` with 8 % typos →
`fail`) and the account names. `t02_trace.php` compares the two decision streams index by index:

| Flow | `N12T` denied | `TMS` denied | decisions that differ |
|---|---|---|---|
| `pre+out` | 56/61 | 56/61 | **0** |
| `pre` | 55/61 | 55/61 | **0** |
| `out` | 55/61 | 55/61 | **0** |

The denial sources are the same multiset on both sides (`issued/persisted: K2(churn)` and `K2+K1(spray+churn)`). **In S0 the outcome history plays no
part in K2 / K1 enforcement**, so the benign trusted office and the attacker holding its sessions receive byte-identical decisions. This is a statement
about S0, not about every possible design: the outcome streams *do* differ (61 failures vs ≈5 typos in 61), which is exactly the signal the outcome-based
family (plan 1 / OQC) used — and that family was measured NO-GO in #111 for its own reasons (post-verification leak, +N under concurrency).

## 3. What the S0 controls say about any design that makes K2 advisory for trusted requests

These are **inferences from S0 controls**, labelled as such; no candidate was measured.

### 3.1 The cross-account bound is K2 and only K2

`TMU` = `TMSd` in every pre-check cell: K1 is already advisory for trusted and its blocks never decide anything here; the K1 denial labels in the tables are
attribution of co-active blocks, the denying key is K2 (see the per-request traces in `summary.md` §7: `check:H2/60` issued by K2 at request #1, then
`persisted:K2(churn)`). §5.2 (distributed account, per K4) fires only in `TMR` (fingerprint rotation inside one account) and after K2 has already denied.

### 3.2 `TMX` is the S0-side proxy for "`TMSd` with K2 advisory"

`TMX` presents one fingerprint to K2, so the shared K2 set never churns; everything else (K1 advisory, per-account K4/K5/budget, 12 targets, 1 / 3 s)
is `TMSd`. A design that makes the K2 correlation block advisory for the trusted request removes exactly the control that separates `TMSd` (2/200) from
`TMX` (200/200). Plan 2's account guard (cap 3 fingerprints per account) does not change this: `TMSd` shows **one** fingerprint per account, so the guard
never triggers; §5.2 (cap 4 per account) likewise. The composite's per-cell reach on `TMSd` is therefore bounded below by `TMX`'s 200/200 unless it keeps
some cross-account control on IP + UA — and that control is K2 under another name, which would deny `N12T` again (§2).

### 3.3 The per-account bound for a trusted session is far above the K2 window

`TMXb`: 25 consecutive failures on each of 12 accounts from one trusted session, 300/300 verified, **no denial of any kind**. Why, on the code at
`ce89cb2`: for a known device the Login failure delta goes to K5 (+2), and the Login policy defines thresholds for K4 only; K4 is not scored for a known
device; K1 is advisory for trusted; the 24 h budget counts a known device's failures only above `known_device_micro_cap = 8` and needs 20 — 25 − 8 = 17 < 20.
So within the K2 window a trusted session's failures on one account are bounded only by the 24 h budget, i.e. effectively unbounded at these volumes.
OTP (thresholds 4/7/10, budget 10) behaves the same on `TMX` (200/200; the 24 `persisted:K1(spray)` labels are post-verification `recordFailure` returns).
This is a **pre-existing S0 property** (it is why `SP5` is 200/200 in #108); the trust family would extend it from "one session, many accounts" to
"many sessions, many accounts".

### 3.4 Mixed NAT

`TPAIRT` / `TPAIRM` (office + the `TMSd` attacker behind one NAT): office 58/61 denied, attacker 1/200 in `pre+out`, 0/200 in `pre`; K2 denies both
populations alike, 32 K2 blocks over 40 min. Under an advisory-K2 design the office would pass and so would the attacker (§3.2).

## 4. Limits of the inference

- S0 only, deterministic synthetic streams, reference stores and a controlled clock; no candidate, no Redis concurrency (which any candidate would owe,
  per #111).
- "Trusted session" here is the Host's assertion exactly as the contract defines it (`isSessionTrusted` + non-empty `sessionDeviceId`); the library cannot
  verify it. `TMX` / `TMXb` / `SP5` are *Host-violating* by `DECISION_MATRIX.md` §0 (a session bound to `vic0` is not a trusted session for `vic1..11`); they
  are measured as controls for "what the per-account protections do without K2", not as compliant traffic.
- §3.2 is a bound by construction (`TMX` ≡ `TMSd` minus K2 churn), not a measurement of plan 2, plan 3 or 2+3. A candidate that keeps **any** cross-account
  control for trusted requests on IP + UA must be measured on `TMSd` / `TMS` / `TPAIRT` and on `N12T` together; the two numbers move together in S0 (§2).
- The attacker owning 12 valid trusted sessions has, by the contract's own definition, already authenticated to those 12 accounts. Whether that is in the
  rate limiter's threat model is an Owner decision (R23 review: not to be assumed out of scope); this study only gives the numbers.

## 5. What this changes in #110

- The R23-01 objection is **confirmed with numbers**: S0 bounds the multi-session trusted attacker at 2/200 (dense) and 7/61 (office cadence) through K2
  alone; `TMU` proves trust is irrelevant to that bound; `TMX` shows what remains without it (200/200).
- The executor's earlier claim "`TMULTI-S` is bounded by N × K4 in S0" is **refuted**: K4 never fires for a known device on Login; the bound was K2.
- Plans 2, 3 and 2+3 **as written** (K2 correlation advisory for every trusted account-bound request) cannot meet the per-cell "no increase over S0" gate on
  `TMSd` / `TMS` / `TPAIRT`; the inference is structural (§3.2) and would be confirmed, not discovered, by a candidate build.
- Not shown: that every more selective design is impossible. The outcome streams differ (§2), the per-account bound could be made to exist for trusted
  sessions (§3.3 is an S0 property the Owner may want to look at independently of #110), and a cross-account control that distinguishes `N12T` from
  `TMS` would need a signal S0 does not use.
