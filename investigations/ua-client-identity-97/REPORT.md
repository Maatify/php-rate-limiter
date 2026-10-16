# Report — Host-managed client identity and UA cross-IP denial (#97)

Baseline `main@9e95f2fcca689440e9b0afc50cd9ef4158c096b3` (DEC-019 and DEC-021 ACTIVE). **Investigation only — no production
runtime change.** #97, #100, #106 and #120 stay OPEN. Nothing here is a Runtime approval, an ADR or a fix.

Evidence tags: `[CODE]` source fact with `file:line`; `[CONTRACT]` canonical document; `[TEST]` this study's harness on the real,
unpatched pipeline (numbers from [`expected/summary.md`](expected/summary.md), pinned as O1–O17 by `report.php`); `[ANALYTIC]`
reasoning from the above; `[POST-HOC]` the one probe added after the pre-registered run (never part of the verdict);
`[UNPROVEN]` not established here.

## 1. Verdict

**`NO-GO`** for the candidate **CIR** (host-issued instance id carried in `clientFingerprint`, user agent left out of the
fingerprint material), computed mechanically by the pre-registered rule of [`VALIDATION_PLAN.md`](VALIDATION_PLAN.md) §5. Six
hard gates fail: G-S1, G-S1b, G-S3, G-S4, G-S5 and G-F5. In order of weight:

| # | Failure | Numbers `[TEST]` | Root cause |
|---|---|---|---|
| 1 | **Shared NAT** (G-F5) — decisive | L1 (2 browsers, one NAT, one browser major): **0/134 → 127/134** legitimate denials; OFF12 (12 browsers): **8/13 → 11/13**; IPv6 L1 the same | per-client ids give one IP + UA several fingerprints, which the K2 churn rule counts (≥ 3, or 2 + WATCH) → shared K2 HARD in these streams. With churn neutralised (counterfactual) L1 is 0/134 and OFF12 8/13: the measured regression in these three streams is churn-attributed (#100). Other NAT sizes, arrival rates and browser mixes were not measured |
| 2 | **Enforcement-path downgrade** by any constant id (G-S1, G-S4) | against a naive attacker who only replays one id: X2 **6 → 121/121**, stolen id POI **4 → 32/180**. Against the attacker's best response (today a UA rotation): victim-targeted guesses unchanged in every `pre+out` cell (X7 40/40 in both), **+15 in checkOnly-only BRD (22 → 37/100)** | `[CODE]` any non-empty `clientFingerprint` makes the request MEDIUM; MEDIUM dilution needs two consecutive windows and blocks only the request's own K3. `[TEST]` In the 10-minute X2 stream the IP-rotating constant-id client was never blocked; in the 30-minute RM2 stream (a legitimate client with a stable id) the MEDIUM path did block (K3, 32/60 vs 48/60 on LOW) |
| 3 | **UA-blindness** (G-S1b, hence G-S3) | one id + rotating UA, checkOnly-only: BRU 3 → 100, X1 3 → 61, BRD 7 → 37 compared with the same strategy on today's resolver | a UA change no longer looks like a new device, so device-rotation signals that today's derivation gets from UA rotation disappear. The attacker's *best* response in these cells is unchanged except BRD (row 2) |

G-S3's DEC-021 part passes on verifiable attribution (review F125-01, O17): in KD and UAC, for CIRI and S0I in every flow with a
denial, the runtime wrote the first owner denial — a SOFT on `recordFailure` — to the **device** Anti-Equilibrium watcher and not
the account watcher, which it does only when every winning SOFT candidate is device-scoped (K5 alone); and with K5 unable to
produce a level, the same request is allowed with identical earlier decisions. G-S3 fails only through G-S1b's BRU/BRD cells.

The X7 total-reach failure of G-S1 (44 → 121/121) consists **only** of admitted padding logins on the attacker's own account;
victim guesses are 40/40 in both worlds (`[POST-HOC]` metric, O16). It is a gate failure as registered and stays one; it is not
a victim-facing loss. G-S5 is a strict-formula failure of negligible size (one or two extra correlation calls per command, 7 at
most, in `static_uarot` cells: detection paths are entered more often; bounded, not an amplification).

What the candidate **does** deliver `[TEST]`: the #97 cohort denials disappear (D1 596/600 → 0/600, L3 104/121 → 0/121, app
UAs `other/0` 596/600 → 0/600, `API_OVERUSE` D1 596/600 → 0/600 for the official and a custom policy); K1 spray, K4 brute force
and DEC-021 K5 behaviour are unchanged or better under `pre+out`; a browser update no longer resets K5 (UAC known-device reach
8/12 → 5/12); fingerprint-secret rotation is decision-transparent; the no-id path is byte-identical to S0 (G-S2, 177 checks);
and the candidate composes through the official `RateLimiterBuilder::withDeviceIdentityResolver()` with identical decisions.
**In the measured shared-NAT streams it moves the #97 harm onto the NAT (L1 0 → 127/134 for two browsers behind one router), and
it weakens enforcement in the measured naive and checkOnly-only attack cells — so it is not a fix.**

## 2. Root cause of #97

* `[CODE]` The default resolver always hashes the normalized UA: `v2|{ua}|{clientFp}|{sessionDeviceId}`, confidence LOW without
  a client fingerprint ([`DeviceIdentityResolver.php:30-41`](../../src/Service/DeviceIdentityResolver.php)). Without
  `clientFingerprint`, every user of one browser major (or every app UA that falls back to `other/0`,
  [`:57-74`](../../src/Service/DeviceIdentityResolver.php)) has **the same fingerprint**.
* `[CODE]` Dilution counts distinct K1 scopes per fingerprint (≥ 6 in 600 s, or 5 + WATCH); at LOW confidence it returns a K2
  `HARD_BLOCK` L2/60 s on the current IP + UA
  ([`EvaluationPipeline.php:1230-1278`](../../src/Service/EvaluationPipeline.php)); at MEDIUM/HIGH it needs a second-window
  confirmation and blocks only K3 ([`:1280-1326`](../../src/Service/EvaluationPipeline.php)). It runs on `checkOnly`, before any
  outcome.
* `[CONTRACT]` [`DEVICE_FINGERPRINT.md:92`](../../docs/DEVICE_FINGERPRINT.md) says the passive fingerprint "MUST NOT be used
  alone for blocking decisions", while [`DECISION_MATRIX.md:703`](../../docs/DECISION_MATRIX.md) §5.3 turns a LOW dilution into
  a K2 hard block. That tension is the contractual core of #97 (finding F97-04).
* `[TEST]` Reproduced exactly on the baseline (O1): D1 596/600 denied, all K2-attributed and correlation-caused; X2 and X7 attack
  reach 6/121; L1 with client fingerprints 127/134 — the published S0 numbers of #105 / #107 / #115.

## 3. Options compared

All arms run the unpatched pipeline; only the `DeviceIdentityResolverInterface` implementation differs (public extension point,
[`RateLimiterBuilder.php:127-133`](../../src/Builder/RateLimiterBuilder.php)).

| Arm | What it is | Status |
|---|---|---|
| `S0N` | shipped resolver, Host sends no id (today's default) | baseline |
| `S0I` | shipped resolver, Host sends `clientFingerprint = ['ciid' => id]` — the Owner's idea with **zero code change** | measured |
| `CIRI` | **candidate CIR**: as `S0I`, but the UA is left out of the fingerprint material when a client fingerprint is present; K2 unchanged | pre-registered candidate → `NO-GO` |
| `CIRN` | CIR, Host sends no id | parity arm (byte-identical to `S0N`) |
| `NUAN` / `NUAI` | UA removed everywhere; no id ⇒ `null` fingerprint | Q4 control, not a candidate |
| `CIRI-CHOFF` | CIRI with only the churn set neutralised | attribution counterfactual |
| `CIRLI` | CIR, but an untrusted id keeps LOW confidence (no MEDIUM lift) | `[POST-HOC]` diagnostic probe, not in the verdict |

Why this is not a repeat of the HMCI study (#118): HMCI measured an id in today's inputs **on one NAT** and with the shipped
derivation. It did not measure the #97 cross-IP case (D1/L3/D1A), the attacker's best response across worlds, a UA-blind
derivation, the null-fingerprint path, or rotation / mixed-fleet / composition effects. Where the streams overlap, the numbers
agree (OFF12 8 → 11 with ids; UAC known-device reach 5 vs 8).

## 4. Answers to the study questions

### Q1 — Can the existing fingerprint infrastructure carry an instance id?

* **Carrying: yes, no new field.** `[CONTRACT]` §3.2 already lists a "Client-generated random ID"
  ([`DEVICE_FINGERPRINT.md:108`](../../docs/DEVICE_FINGERPRINT.md)); `[TEST]` with `['ciid' => id]`, each actor gets its own
  fingerprint (D1: 600 distinct) and the #97 cohort denials disappear **without any code change** (`S0I` decision strings equal
  `CIRI` on D1, L3, D1A, L1, OFF12 — O3).
* **But the field is not neutral.** A non-empty `clientFingerprint` lifts confidence to MEDIUM
  ([`DeviceIdentityResolver.php:37`](../../src/Service/DeviceIdentityResolver.php)), and MEDIUM selects the weak dilution path.
  `[TEST]` That is the enforcement-path downgrade of §1 (row 2), and it exists today for any Host that forwards a client-held
  value (`S0I` with one constant id: X2 121/121 — finding F97-02).
* **Resolver change needed for continuity:** leaving the UA out of the material when an id is present. `[TEST]` It keeps one
  fingerprint and one K5 across a browser update (UAC: 1 vs 2 K5 keys; known-device reach 5/12 vs 8/12, i.e. DEC-021 bounds the
  device better).
* **Cost of UA-blindness `[TEST]`:** a UA change no longer looks like a new device. An attacker holding one id while rotating the
  UA is seen as one device, so the distributed-account / device-rotation signals that S0I gets "for free" from UA rotation are
  lost (G-S1b: BRU checkOnly-only 3 → 100, X1 checkOnly-only 3 → 61, BRD checkOnly-only 7 → 37; X7 44 → 121 total reach, all
  own-account padding). In the checkOnly-only flow the attacker's *best* response is unchanged for BRU and X1 (S0N `none` already
  reaches 100/100 and 61/61 because nothing is recorded — the known OD-3 limitation), but not for BRD (22 → 37 victim guesses).
* **`sessionDeviceId`:** `[TEST]` a browser that logs in without a session id and later re-authenticates with a trusted one has two
  fingerprints on one IP + UA, which already triggers churn **today** (SESS 6/8 denied in S0N; 0/8 with churn neutralised —
  O9, finding F97-03). The candidate neither creates nor fixes it.

### Q2 — What happens to K2?

* The candidate keeps **K2 = IP + normalized UA** unchanged; replacing the UA in K2 by the id was not adopted because K2 is the
  churn anchor: the "many fingerprints under one IP + UA" signal only exists while K2 does *not* contain the fingerprint.
  `[ANALYTIC]` Literal `K2 = IP + id` would equal K3 for id-carrying clients (K3 = IP + DeviceFP), duplicating a key and
  deleting churn detection.
* `[TEST]` K3 already does the per-client job in the measured streams: with ids, D1/L3 legitimate users never share a K3. The
  problem there is not K2's identity: when one IP carries several stable ids, the K2 churn block applies to **everyone** on the
  IP + UA `[CODE]`, and in L1 and OFF12 that produced the whole measured regression (0 → 127/134, 8 → 11/13; churn-attributed by
  the counterfactual — O6). `[ANALYTIC]` On that key, the measured office tuples and an attacker rotating the same number of ids
  give the same churn tally (cf. A06 indistinguishability of #105); this is shown for these tuples, not for every NAT.
* IPv6 `[TEST]`: L1 over one `/64` behaves identically (127/134). The pre-registered V6D1 (600 users inside one `/48`) is dominated
  by K1 / adaptive-IPv6 aggregation in every arm (596–598/600) and says nothing about identity; the `[POST-HOC]` V6D1W (distinct
  `/32`s) reproduces IPv4 D1 (596 → 0).

### Q3 — Is the same id across IPs evidence of attack?

* Not by itself. `[TEST]` A device roaming 12 IPs (L4) goes from 8/12 denied today (LOW cohort) to 0/12; Wi-Fi/mobile/VPN
  switching (RM1) is unaffected by identity (34/60 in every arm — K4 soft blocks from two typos, out of scope, identical across
  arms). A carrier that changes egress IP on every request (RM2) still loses **32/60** with an id (K3, MEDIUM dilution) instead
  of 48/60: better, not fixed.
* `[TEST]` The attacker's side shows why "same id across IPs" cannot simply be discounted: with MEDIUM, one constant id across 121
  IPs reaches 121/121 in X2, where sending nothing reaches 6/121. Against today's best response (UA rotation, already 121/121)
  that is no extra reach in `pre+out`; in checkOnly-only BRD it is +15 victim guesses (22 → 37/100). With LOW kept
  (`[POST-HOC]` CIRLI) the constant id is caught exactly like no id (X2 6/121, BRD checkOnly-only 22/100).
* `[TEST]` What the shipped LOW dilution protected against in the measured X2 / X7 streams: only the attacker that sent
  **nothing** and kept **one** UA. Rotating the browser major on every request defeated it (X2: 6 → 121/121, O10; cf. #120), while
  the same rule denied 596/600 D1 users. Other attacker models (slower or partial UA rotation, small UA pools, other rates) were
  not measured, so this is not a claim about every attacker.

### Q4 — What happens when the id is missing?

* `[TEST]` With CIR, a missing or `[]` id is byte-identical to S0 (G-S2): the no-id population keeps today's #97 denials (D1
  596/600 under `CIRN`). The candidate is an opt-in for Hosts that issue ids; it does not fix #97 for the default integration.
* `[TEST]` Removing the UA everywhere (NUA, `fingerprintHash = null`) is **not safe** (O8): dilution and churn are skipped for
  null fingerprints ([`EvaluationPipeline.php:1170`](../../src/Service/EvaluationPipeline.php)), so X2 and X7 go 6 → 121/121;
  the missing-fingerprint scoring becomes reachable for the first time in default use and is weaker than the device path —
  per-account brute force BRU 3 → 11/100 and BRD 3 → 11/100; a known device is denied on K4 instead of DEC-021's K5 path. Login
  has no K2 thresholds, so `k2_missing_fp` scores a key that never enforces
  ([`LoginProtectionPolicy.php:57-58`](../../src/Config/LoginProtectionPolicy.php),
  [`EvaluationPipeline.php:2700`](../../src/Service/EvaluationPipeline.php)). This confirms F-HMCI-01 with numbers.

### Q5 — How can an attacker abuse the identifier?

| Strategy | Effect `[TEST]` (Login, `pre+out` unless noted) |
|---|---|
| no id | identical to S0 (G-S2) |
| constant id | **escapes LOW cross-IP enforcement** (X2 6 → 121/121, equal to today's UA-rotation best response); checkOnly-only BRD 22 → 37/100 victim guesses; X7 total reach 44 → 121 is own-account padding only (victim guesses 40/40 either way) |
| new id per request | same reach as today's UA rotation (X2 121/121, X7 44/121); one IP, one victim account: 2/61 in every strategy (account K4 decides) |
| mass-generated ids (one IP) | bounded by Ephemeral caps and K1 (MASS 4/300 → rotate 2/300); no free quota |
| same id across IPs | MEDIUM path: never blocked while IPs rotate (see constant id) |
| stolen / imitated id | POI: attacker reach 4 → 32/180 against the replayed-UA baseline (today's UA-rotation best response is 80/180); owner denials 12 → 8/15 (narrower collateral, weaker containment); no trust conferred (confidence and trust flags equal to the shipped resolver on every request — G-S4a) |
| several valid accounts / sessions | K4/K5 and DEC-021 unchanged; UA-blindness keeps one K5 across UA changes (UAC reach 8 → 5) |
| constant id + rotating UA, checkOnly-only | BRU 3 → 100, X1 3 → 61, BRD 7 → 37 vs the same strategy on S0 (G-S1b) |

**Quota multiplication:** none measured for K1 spray (SP ≤ 7/200 for every arm and strategy) or K4 brute force under `pre+out` (BRU 2–3/100).
**Trust from possession:** none at the resolver; the gain is an *enforcement-path downgrade*, not trust.

## 5. Security and fairness comparison

Best attacker reach over each world's strategies (`pre+out`) against the legitimate cost:

| | Today (S0N) | Ids, shipped resolver (S0I) | Candidate (CIRI) | `[POST-HOC]` CIRLI |
|---|---|---|---|---|
| #97 cohort D1 / L3 / D1A / D1API legit denied | 596 / 104 / 596 / 596 | 0 / 0 / 0 / 0 | 0 / 0 / 0 / 0 | 0 / 0 / 0 / 0 |
| Shared NAT L1 / OFF12 legit denied | 0/134 / 8/13 | 127/134 / 11/13 | 127/134 / 11/13 | 127/134 / 11/13 |
| X2 constant id vs no id (naive attacker) | 6/121 (no id possible) | 121/121 | 121/121 | 6/121 |
| X2 best reach | 121/121 | 121/121 | 121/121 | 121/121 |
| X7 best total reach (incl. own-account padding) | 44/121 | 121/121 | 121/121 | 44/121 |
| X7 best victim guesses `[POST-HOC]` metric | 40/40 | 40/40 | 40/40 | 40/40 |
| BRD checkOnly-only best victim guesses | 22/100 | 37/100 | 37/100 | 22/100 |
| POI attacker / owner (stolen vs replayed UA) | 4/180 / 12/15 | 32/180 / 8/15 | 32/180 / 8/15 | 4/180 / 12/15 |
| POI best attacker reach | 80/180 | 80/180 | 80/180 | 80/180 |
| SP / BRU / BRD best reach (`pre+out`) | 7 / 3 / 3 | 7 / 3 / 3 | 7 / 3 / 3 | 7 / 3 / 3 |
| Browser update, known device reach | 8/12 | 8/12 | 5/12 | 5/12 |

**Indistinguishability boundary `[TEST]` + `[ANALYTIC]`:**

* *Cross-IP:* in the measured streams, 600 independent users with their own ids (D1) and one attacker with a new id on each of
  121 IPs (X2 `rotate`) present the same per-request identity tuples (new IP, new id, popular UA). `[ANALYTIC]` A rule that sees
  only those tuples and spares the former cannot separate the latter by identity; today that attacker is not caught either (UA
  rotation, X2 121/121). What can be kept is today's protection against a *constant* identity — and that requires the id **not**
  to lift confidence (CIRLI keeps a constant id at X2 6/121 and BRD checkOnly-only 22/100).
* *Same IP:* two colleagues with stable ids and one attacker rotating two ids produce the same K2 churn tally. Separating them
  needs time (a stable id re-appears, a rotating one does not) or outcome evidence — both are #100 design questions, not
  identity questions.

## 6. Findings on `main` (not fixed here; each needs a disposition)

| ID | Finding | Evidence |
|---|---|---|
| F97-01 | In the measured X2 stream, LOW dilution stopped only the attacker that sent no id and kept one UA: rotating the browser major gave 121/121, while the same rule denied 596/600 legitimate D1 users. Other attacker models not measured | `[TEST]` O10, O1 |
| F97-02 | `[CODE]` Any non-empty `clientFingerprint` (an id, a buggy Host's `['ciid' => '']`, an attacker's cookie) is MEDIUM and takes the MEDIUM dilution path, for every Host that forwards one. `[TEST]` In the measured 10-minute streams that path never caught the IP-rotating constant-id client (X2 121/121 vs 6/121 without an id; shared-empty-id D1 0/600) | `[TEST]` O4, MALD1; `[CODE]` `EvaluationPipeline.php:1264-1326` |
| F97-03 | One browser that logs in, then re-authenticates with a trusted `sessionDeviceId`, triggers K2 churn on itself (SESS 6/8 today) | `[TEST]` O9 |
| F97-04 | `DEVICE_FINGERPRINT.md:92` ("MUST NOT be used alone for blocking decisions") vs `DECISION_MATRIX.md` §5.3 LOW → K2 HARD | `[CONTRACT]` |
| F97-05 | `clientFingerprint` has no size bound: a 1 MiB value is accepted and hashed on every call (depth > 512 and non-serialisable values are rejected) | `[TEST]` MAL |
| F97-06 | Changing the fingerprint derivation was a rollout hazard in the measured MIX / ROLL cells: one device had two fingerprints, which triggered churn hard blocks and reset K3/K5 (KD reach 5 → 6 mixed, 5 → 7 at the switch). `[ANALYTIC]` The same applies to any derivation change that gives one device two values on one IP + UA | `[TEST]` O13 |

## 7. Recommendation

1. **Do not adopt CIR**, and **do not advise Hosts to put an instance id in `clientFingerprint`** to address #97 (this confirms
   HMCI recommendation 1 with cross-IP evidence): in the measured streams it removes the #97 collateral but moves the harm onto
   shared NATs (L1 0 → 127/134, OFF12 8 → 11/13) and downgrades enforcement for constant or stolen ids and in checkOnly-only BRD.
2. Keep DEC-019's interim posture for #97; this study adds no reason to change it.
3. The evidence narrows the next bounded question for the Owner `[POST-HOC]`: an instance identity can relieve #97 **without**
   the enforcement-path downgrade only if an unattested id **does not lift confidence** (CIRLI passes every security gate it was checked
   against except the UA-blindness cells of G-S1b, and keeps the full #97 relief) — **and only after #100 removes the shared-NAT
   churn penalty** (CIRLI still fails L1/OFF12 exactly like CIRI). CIRLI contradicts `DEVICE_FINGERPRINT.md` §4.2 and was never
   pre-registered; it is a direction for a new, pre-registered study after #100, not a candidate.

## 8. Owner decisions requested

1. Accept this study as evidence only, with verdict `NO-GO` for CIR.
2. Confirm that no Host guidance recommending an instance id in `clientFingerprint` is published.
3. Decide whether #97 identity work is **sequenced after #100** (the measured blocker), and whether the "unattested id stays LOW"
   contract question (§4.2) may be examined then.
4. Dispose of F97-01 … F97-06 (documentation, a separate issue, or no action).

## 9. Reconciliation of the Lead review (PR #125, review 5551380198 on `084a88e`)

| Finding | Resolution |
|---|---|
| F125-01 (P2) G-S3 inferred the K5 source from the K5 score alone | The score test is replaced by two required pieces of evidence per cell (`report.php` G-S3, O17): the runtime's own Anti-Equilibrium write at the first denial (device watcher +1, account watcher +0 ⇒ every winning SOFT candidate was K5-only; concurrent K4 / budget / account-scoped SOFT sources would have forced the account watcher) and a K5-level ablation counterfactual (`Ci97K5LevelAblatedLoginPolicy`: identical decisions before the denial, the denied command ALLOW). A HARD first denial is reported as not attributed. The gate wording of `VALIDATION_PLAN.md` §5 is unchanged; the evidence required is stricter. Result: **8/8 actual first-denial attributions PASS** (KD / UAC × CIRI / S0I × `pre+out` / `out`), and 16/16 non-inherited G-S3 checks PASS (those 8, 4 owner-reach comparisons and 4 `pre` cells with no denial, not applicable); of G-S3's 17 checks, the 17th is the inherited BRU/BRD failure, so G-S3 still FAILs through G-S1b; verdict unchanged |
| F125-02 (P3) inferences broader than the evidence | Claims about "every shared NAT", "only a naive attacker", "never hit" and "any derivation change" are restricted to the measured streams (L1, OFF12, V6L1; X2, X7, BRD; MIX/ROLL) with `[CODE]` / `[TEST]` / `[ANALYTIC]` separated; unmeasured NAT sizes, rates, browser mixes and attacker models are stated as such. One claim was also corrected on substance: the MEDIUM path did block a stable-id client in the 30-minute RM2 stream, so "an IP-rotating client is never hit" is now limited to the 10-minute X2 stream |

## 10. Limits

* `[UNPROVEN]` OTP, Redis and concurrency: not exercised. No claim here depends on atomicity — CIR changes only the values passed
  to the existing Store operations, never their number or kind beyond the bounded G-S5 delta — but no Redis guarantee is
  offered for any candidate.
* `[CODE]` DEC-015 backend-failure handling is untouched: the local fallback uses IP, account and raw UA, not the fingerprint
  ([`RateLimiterEngine.php:318`](../../src/Service/RateLimiterEngine.php)); not separately exercised.
* Streams are synthetic and deterministic; they are not production statistics. D1/L3/L1/L4/X1–X8 are the merged historical
  streams, unchanged.
* DEC-008: covered for the official and a custom `API_OVERUSE` policy only.
* After the pre-registered run, the harness gained corrections and additions that do not change any gate formula, threshold,
  stream or the verdict rule (recorded in `VALIDATION_PLAN.md` §7): the G-S3 "K5 path" check is skipped where no owner denial
  exists (checkOnly-only); two pinned observation texts were corrected to the measured values; the post-hoc probe CIRLI, the
  post-hoc IPv6 cell V6D1W and the post-hoc victim-guess metric were added for diagnosis only; after review F125-01 the G-S3 K5-path
  evidence was made stricter (runtime watcher witness + ablation, §9).
