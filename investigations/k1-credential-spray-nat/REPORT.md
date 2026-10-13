# #106 — K1 credential-spray / shared-NAT: findings

**EVIDENCE ONLY. No Runtime, threshold, ADR or policy is changed, selected or recommended.** Baseline `origin/main@4d9495ded05f647ce0421352781ce948ceeaa606`; level
`S0_RUNTIME_OBSERVED` (unpatched `EvaluationPipeline`, sha-256 `4b259d33…f4fc`). Every number below is generated in [`expected/summary.md`](expected/summary.md) (tables) and
`expected/*.jsonl` (raw rows); method and limits in [`README.md`](README.md). Counts are **requests**, not people. Arms: **A** = S0 · **B** = churn+dilution off · **C** = K1 spray off ·
**D** = both off (B/C/D are **TEST ONLY** controls in the injected correlation store; the pipeline is never patched).

## 1. Verdict on the D2 attribution (108/121)

The published number is reproduced exactly (A 117/121, B 108/121, record-only 115 / 7), and **the attribution to K1 is confirmed but incomplete**:

| D2, Login, `pre+out` | legit denied |
|---|---|
| A — S0 | **117 / 121** |
| B — churn/dilution off (the published "no-correlation control") | **108 / 121** |
| C — K1 spray off only | **117 / 121** |
| D — both off | **27 / 121** |

* Inside the 108 that survive the published control, **81 are K1 spray** (108 → 27 once K1 spray is also off) and **27 are other independent protections** (24 are
  distributed-account K5 blocks — a fresh fingerprint per login looks like one account from many devices — and 3 score/other). Winning source of the 108: `persisted:K1(spray)` ×65,
  `issued:K1(spray)` ×33, K1(spray+dist) ×9, K5 distributed ×1.
* **But in S0 itself K1 is not what the colleagues feel first.** Turning only K1 off changes nothing (117 → 117): the K2 churn rule denies the same requests independently.
  Per-request partition of the 117 S0 denials: **0** freed only by K1-off, **8** only by churn/dilution-off, **83** freed by *either* alone (each is sufficient — overlap),
  **26** still denied with both off. So #100's K2 fault and #106's K1 mechanism **mask each other**: an honest fairness result needs both examined together, and neither alone moves D2.
* The 108 exists only for a Host that passes an account id **and** calls `checkOnly`: with Login-without-account-id the same D2 gives B = 0/121 (K1 has no subject); in the
  record-only flow B = 7/121 (K1 never observes there).

## 2. What K1 does to a benign shared NAT, in isolation

`Nu*` = **K1-isolation control** (each colleague has a different browser major, so K2 cannot churn — not a realistic population). 40 minutes, 1 login / 40 s, 8 % typos, Login `pre+out`:

| Colleagues behind the NAT | A S0 | C K1 spray off | winning source (A) |
|---|---|---|---|
| 3 (`Nu3`) | 2 / 61 | 2 / 61 | score/other only — K1 silent |
| **4 (`Nu4`)** | **47 / 61** | 0 / 61 | `issued/persisted:K1(spray)` |
| 5 (`Nu5`) | 48 / 61 | 6 / 61 | K1(spray) |
| 6 / 12 (`Nu6`, `Nu12`) | 47 / 61 | 0 / 61 | K1(spray) |
| 12, all **trusted** devices (`Nu12T`) | **0 / 61** | 0 / 61 | advisory — no denial |
| 12, half trusted (`Nu12M`) | 24 / 61 | 0 / 61 | the untrusted half, K1(spray) |

Robust to the typo seed: 20 seeds give exactly 47/61 (A and B) for 4–12 colleagues; arm C ≤ 15. Mechanism, measured on the controlled clock (`K03`):

1. **The effective trigger is 4 distinct subjects plus one more request, not 5.** The 4th distinct subject arms the 4/5 WATCH; the WATCH counter increments on every request that
   finds cardinality 4 — including a repeat by an existing member — so *4 subjects + any 2nd request* is a `HARD_BLOCK L2` (`B2`: `0:a#1 1:a#2 2:a#3 3:a#4 4:B(60)#4`). Three subjects with
   any number of repeats never trigger (`B3`).
2. **The denial window is the 600 s set, not the 60 s block.** After the first block the set stays full; each 60 s K1 block is re-issued by the next request (t = 4, 64, … 544) and the
   last one outlives the set: members *and new subjects* are denied until **t = 604** (`B4`). The window is fixed — later members never extend it (`B5`: denied at 599, allowed at 600).
3. A second 4/5 inside the fixed 1800 s WATCH TTL is enforced (`B6`: t ≤ 1802 denied, ≥ 1803 allowed).

Trusted-session advisory works for the trusted device (`Nu12T` 0/61, and a trusted probe is allowed every second while untrusted traffic of the same IP is denied), but **it is not an
exemption from state**: a trusted request that meets the threshold still persists the K1 block (596 K1 block writes for one trusted client probing for 696 s), and that block denies
the untrusted colleagues. Only a session-bound trusted device is advisory: untrusted, session-id-but-not-trusted, trusted-flag-without-session-id and *previously verified (known)*
devices are all denied (`T1`).

## 3. What K1 buys against real spray (same budget, clock, scopes)

Attacker attempts that reached credential verification, Login `pre+out`:

| Attack | A S0 | B (§5.3 off) | C (K1 off) | D (both off) | who stops it in S0 |
|---|---|---|---|---|---|
| `SP1n` 1 IP, no fingerprint, 200 victims @1/3 s | **4 / 200** | 4 | **200** | 200 | **K1 only** |
| `SP3` stuffing, 1 IP, 1000 accounts @1/s | **7 / 1000** | 7 | **1000** | 1000 | **K1 only** |
| `SP6` 1 IP, 1 victim / 3 s for **1 hour** | **20 / 1200** | 20 | **1200** | 1200 | **K1 only** |
| `SP2` slow spray, 4 victims / 601 s, 3 h | 60 / 72 | 60 | 72 | 72 | K1 WATCH, weakly |
| `SP1f` spray with a fresh fingerprint per request | 2 / 200 | 4 | **2** | 200 | K2 churn (K1 adds 2 more) |
| `SP4` 8-IP botnet, shared UA | 78 / 576 | 480 | 78 | 576 | K2 **dilution**, not K1 |
| `SP4u` 8-IP botnet, own UA per IP, 4 victims / IP / 601 s | 480 / 576 | 480 | 576 | 576 | K1 WATCH (96 blocks), weakly |
| `SP4b` 8-IP botnet, 3 victims / IP / 601 s | **432 / 432** | 432 | 432 | 432 | **nobody** (K1 blind: < 4 per IP) |
| `SP5` spray from a trusted session device | **200 / 200** | 200 | 200 | 200 | **nobody** (advisory; trust is Host-attested) |

So: for a single-IP attacker without a usable fingerprint **K1 is the only control in S0** and it caps one IP at ≈ 20 verified accounts per hour; removing it gives that attacker
the whole list. It does nothing against < 4 subjects per IP, against distributed sources that stay under 4 per IP, or against a device the Host marked trusted.

## 4. The frontier (measured points — no threshold sweep was run, none is proposed)

Turning K1 spray off (B → D isolates K1; A → C is the product with only K1 removed):

| | benign NAT relief (legit requests denied) | attacker cost (verification attempts gained) |
|---|---|---|
| `Nu4…Nu12`, `Nu12M` | 47/61 → 0–6/61 · 24/61 → 0/61 | `SP1n` 4 → 200 · `SP3` 7 → 1000 · `SP6` 20 → 1200 · `SP2` 60 → 72 · `SP4u` 480 → 576 |
| **`D2` (realistic fresh-fingerprint office)** | **117 → 117** (A→C) · 108 → 27 (B→D) | same as above |
| `Nu12T` (all trusted) | already 0 | — |

Mixed streams (colleagues **and** a spray attacker behind the same NAT, one clock):

* `MIXN` (12 untrusted isolated colleagues + attacker): **49/61 colleagues denied while the attacker still gets 2/200**; with K1 off: 0/61 denied and 200/200 verified. The collateral is
  **independent of the attacker** — the colleagues alone already cause 47/61 (`Nu12`).
* `MIXU` (same, colleagues **trusted**): **0/61 denied and the attacker gets 2/200** — the one configuration where K1 simultaneously protects and is fair, and it depends on the Host
  attesting session-bound device trust.
* `MIX` (D2 office + attacker): A 117/121 · 0/200 attacker; D 27/121 · 200/200 — the same trade-off, hidden behind K2.

## 5. Lifecycle, Host inputs, IPv6, rotation, resources

* **Lifecycle (`K04`).** Spray is observed by `checkOnly` only. `pre+out` and `pre` make the identical number of observations (4 for 4 subjects; 5 for 8 — the active K1 block then
  short-circuits the engine); the outcome command adds none (no double count). **Record-only Hosts have no K1 spray protection at all**: 0 observations, 200/200 (`SP1n`), 1000/1000 (`SP3`).
  Login and OTP behave identically.
* **The correlation subject is Host-supplied and decides everything (`K05 S1–S5`).** No subject (login without account id and without `correlationId`): K1 observes nothing, 200/200
  verified. A **constant** `correlationId` (e.g. a tenant id): one subject forever, 200/200 verified. A **per-request-unique** id (e.g. a request id): every request is a new subject — the
  attacker is stopped (4/200) but a single legitimate user with 6 retries is denied from the 5th request (2/6). An opaque per-username id or the account id: correct (attacker 4/200, user 0/6).
* **IPv6 (`K05 H`).** Macro `/48` / `/40` spray scopes both stop an attacker rotating `/64`s (5 / 200 vs 200/200 with K1 off) **and** deny a benign office whose hosts sit in
  different `/64`s of one `/48` (**25 / 31** denied vs 0 with K1 off; every denial a K1 block issued through the current `/64` K1).
* **Rotation.** 3 subjects under the previous secret + 2 under the current one deny the 5th; a subject already counted in the previous set is not double counted; 4 + 4 across a
  rotation does **not** reset (4 verified, not 8); a pre-rotation K1 block is enforced after it. Same behaviour on real Redis.
* **Resources / concurrency.** A spray set never exceeds 5 members and every K1 owns at most one set + one WATCH flag (5000 subjects from one IP → 1 set / 5 members / 1 flag); fixed
  600 s / 1800 s TTLs never extended; everything expires. The bound is **per K1 (per IP)**: 2000 IPs × 6 subjects → 1996 sets. **Real Redis:** 16 simultaneous distinct subjects × 8 rounds →
  exactly 4 allowed every round, each set exactly 5 members; 12 simultaneous requests of one subject × 6 rounds → all allowed; decision parity with the reference store.

## 6. Can K1 tell an attacker from a legitimate NAT? — NO-GO for a classifier; OWNER DECISION REQUIRED

Within K1's inputs (IP scope, opaque subject, trust flag) a benign office with ≥ 4 distinct accounts and a spray against 4+ accounts are **the same observation**; the two populations in this
study differ only in magnitude (12 vs 200 distinct subjects) and in signals K1 does not read (outcome mix, fingerprint/UA diversity, request rhythm). No new threshold, outcome-qualified
counting or classifier was evaluated or is claimed; the evidence neither shows a safe change to K1 nor proves K1 is correct as is. Fairness improves only where something outside the
spray set separates the two (**trusted session** → `Nu12T`, `MIXU`); resistance falls exactly where K1 is the sole control (§3).

### Decisions that need the Owner (none is taken here)

1. **Is the 4-subjects-plus-one-request behaviour intended?** §5.0 says a WATCH flag "observed twice" upgrades the decision; the implementation counts *requests* at cardinality 4, so the
   effective K1 threshold is 4 distinct subjects, not the documented 5, and a legitimate 4-account NAT is denied for up to ≈ 604 s. (Contract wording vs behaviour, not a bug claim.)
2. **Fairness target for shared NATs.** Accept ≥ 4 untrusted accounts / 10 min per IP as spray by definition, or define an alternative — each alternative must be justified against §3 (every
   point on the frontier trades roughly 4 → 200 / 7 → 1000 verified attempts for the NAT relief above). The study evaluates no alternative.
3. **Record-only Hosts.** Is "no K1 protection for Hosts that call only `recordFailure` / `recordSuccess`" acceptable, or must it be documented as a Host requirement?
4. **Host subject contract.** Constant and per-request-unique `correlationId`s silently disable or invert K1; should the contract state the stability requirement (or should any validation exist)?
5. **Trusted advisory.** SP5 shows a device the Host marks trusted sprays freely; and trusted requests persist K1 blocks (write amplification + untrusted collateral). Is advisory-with-persistence the intended
   contract? (It matches the current §5.1 text.)
6. **Sequencing with #97 / #100.** Because K1 and K2 overlap (83 of D2's 117 denials are each independently sufficient), a K2-only fix leaves D2 at ≈ 108/121 and a K1-only change leaves it at 117/121.
   Any benign-NAT claim must be measured with both. This study neither closes nor resolves #97 / #100.

## 7. Limits of the proof

* Reference store for everything except `K07` (real Redis for parity, TTL hygiene, concurrency, rotation). Redis time (DEC-016) is not used for the exact 600 / 1800 s boundaries — those are on the
  controlled clock; `K07` asserts only structure and concurrency.
* Deterministic simulation: streams are synthetic (the D2 stream is #105's, unchanged). Typo seeds are swept (20); no statistical confidence interval is claimed. Attack streams contain no randomness.
  `Nu*` populations are isolation controls, not realistic offices; `N*` / `D2` are the realistic ones (and are masked by K2).
* Attribution is observational (store signals + persisted-block log + a four-arm counterfactual). Per-request counterfactuals are path dependent; rotation scenarios carry no source labels.
* Concurrency was proven for one K1 set with ≤ 16 simultaneous requests, not at production scale; no multi-instance Redis / failover / clock-skew / memory-pressure runs.
* A trusted device is modelled as the Host's attestation (`sessionDeviceId` + `isSessionTrusted`); forgery of that attestation is out of the library's control and is shown only as `SP5`.
* No Host-specific input or data is used; no new threshold, Redis / API change or migration is proposed.
