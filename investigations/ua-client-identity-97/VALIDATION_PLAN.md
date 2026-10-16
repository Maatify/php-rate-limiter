# Validation plan — Host-managed client identity and UA cross-IP denial (#97)

**Status: PRE-REGISTERED.** This file was committed on its own, before any harness or result existed, so that the git
history shows the gates were fixed before measurement (task §8: "do not change acceptance criteria after unfavourable
results"). After that commit, this file may only gain the "Executed / result" column in §6 and the clearly marked
post-run notes in §7. A change to any gate, threshold, stream, strategy set or verdict rule after that commit is a
protocol violation and must be reported as one.

Baseline: `Maatify/php-rate-limiter` `main@9e95f2fcca689440e9b0afc50cd9ef4158c096b3`. DEC-019 and DEC-021 ACTIVE.

## 1. Arms (runtime × integration)

The `EvaluationPipeline`, stores, policies and every other runtime class are the repository's **unpatched** `src/` at the
baseline (sha-256 pinned and asserted). Only the `DeviceIdentityResolverInterface` implementation differs, and it is
injected through the existing public extension point (`RateLimiterBuilder::withDeviceIdentityResolver()`), so no arm
patches `src/`.

| Arm | Resolver | Legitimate clients send |
|---|---|---|
| `S0N` | shipped default `DeviceIdentityResolver` | nothing (current default deployment; the #97 reproduction) |
| `S0I` | shipped default | `clientFingerprint = ['ciid' => <host-issued instance id>]` (zero-code integration of the Owner's idea) |
| `CIRI` | **candidate CIR** (below) | the same instance id |
| `CIRN` | candidate CIR | nothing (backward-parity arm) |
| `NUAN` / `NUAI` | control NUA (below) — answers Q4 only, not a candidate | nothing / the instance id |
| `CIRI-CHOFF` | CIR, with the K2 churn distinct set (600 s / cap 3) neutralised in the store | the instance id — **counterfactual attribution only**, never a candidate |

**Candidate CIR (Client-Instance Resolver)** — the smallest resolver change that answers Q1:

* `clientFingerprint` null or `[]` → **exactly** the shipped derivation (`v2|normalizedUa||sessionDeviceId`, LOW/HIGH as
  today).
* `clientFingerprint` non-empty → the fingerprint material omits the user agent: the UA slot is replaced by a constant, so
  the material is `v2|<constant>|normalizedClientFp|sessionDeviceId`. Canonicalisation, the session id, confidence
  (MEDIUM, or HIGH for a trusted session), trust flags, `normalizedUa` (hence **K2 = IP + normalized UA, unchanged**), the
  previous-generation hash and every exception are the shipped ones.
* No new input, no new field, no Store/Redis/key-format change, no pipeline change.

**Control NUA (No-UA)** — "remove UA from DeviceFP" taken literally: as CIR, but when both `clientFingerprint` and
`sessionDeviceId` are absent the resolver returns `fingerprintHash = null` (LOW). It exists to measure Q4 (what the
missing-fingerprint paths do once they become reachable in default use). It is not eligible for GO.

## 2. Attacker strategy sets

The attacker controls IP choice, the User-Agent string and — only in a world where the Host forwards a client-held
identifier — the identifier value.

* `Σ_N` (world `S0N`, Host forwards no identifier): `none` (no id, fixed popular UA — the historical streams), `uarot`
  (no id, a new browser major on every request).
* `Σ_I` (worlds `S0I`, `CIRI`, `NUAI`): `none`, `uarot`, `static` (one constant id everywhere), `rotate` (a new id on every
  request), `static_uarot` (one constant id, new browser major every request). Stream `POI` adds `stolen` (the victim's
  own id).

## 3. Streams

All streams are deterministic (controlled clock, fixed seeds, no wall clock). Historical streams are taken **unchanged**
from the merged harnesses; in id-carrying arms each legitimate actor carries its own stable id.

| ID | Class | Shape | Source |
|---|---|---|---|
| D1 | benign | 600 users, 600 distinct IPs, 1 login/s, one popular UA, 8 % typos | `workstream-b-anti-evasion/design/lib/dsg.php` (unchanged) |
| L3 | benign | 121 users, distinct IPs, one UA, 8 % typos | `workstream-b-correlation/fixtures/streams.php` (unchanged) |
| D1A | benign | D1 shape; users cycle through 3 app UAs that all normalize to `other/0` (Android okhttp, iOS CFNetwork, custom) | new |
| L4 | benign | one device roaming 12 IPs, 1 login / 20 s | streams.php (unchanged) |
| RM1 | benign | one client cycling Wi-Fi / mobile / VPN (3 IPs), 1 login / 60 s for 60 min, 8 % typos | new |
| RM2 | benign | one client whose carrier egress IP changes on every request, 1 login / 30 s for 30 min | new |
| L1 | benign | NAT: 2 clients alternating every 30 s for ~67 min, valid logins | streams.php (unchanged) |
| OFF12 | benign | NAT: 12 browsers, one NAT IP, one UA, own accounts, 1 arrival / 30 s, ~10 % typo then retry | shape of `host-managed-client-identity-110` OFF12 |
| SESS | benign | one browser: pre-auth login (id only), then re-authentication with the same id plus a trusted `sessionDeviceId`, 4 cycles in 30 min | new |
| X2 | attack | new IP + new victim account per attempt, every 5 s, 121 attempts | streams.php (unchanged) |
| X7 | attack | X2 padded with 2 successful logins on its own account per failure | streams.php (unchanged) |
| X2L | both | X2 + a legitimate LOW population on the same UA | streams.php (unchanged) |
| X8 | both | 8-IP botnet failing + legitimate LOW users on the same UA | streams.php (unchanged) |
| SP | attack | one IP, 200 distinct accounts, one failure every 6 s (K1 spray) | shape of HMCI SPR |
| BRU | attack | one IP, one account, 100 failures every 30 s (K4) | shape of HMCI BRU |
| BRD | attack | one account, a new IP per attempt, 100 failures every 30 s (K4 / distributed account) | new |
| X1 | attack | one IP, one victim account, 61 failures every 10 s | streams.php shape |
| MASS | attack | one IP, 300 attempts every 2 s on distinct accounts (identifier farming / device caps) | new |
| POI | both | an owner logs in every 120 s from home for 30 min; an attacker on 8 IPs fails every 10 s, replaying the owner's UA (`S0N`) or the owner's id (`stolen`) | new |
| KD | owner | a previously-verified (Host fact) device fails 12 times every 60 s (DEC-021) | shape of HMCI UAS |
| UAC | owner | KD, the browser updates Firefox 130 → 131 before failure 7, id unchanged | shape of HMCI UAC |
| D1API / X1API / X2API | benign / attack / attack | D1 events, one IP every 2 s for 10 min, X2 events — on `API_OVERUSE` | D1 / X2 unchanged; X1API new |

Policy shapes: Login for every non-API stream; `api` (official `ApiHeavyProtectionPolicy`) and `api_custom` (custom
`API_OVERUSE` subclass, DEC-008) for the API streams. Flows: `pre+out` (checkOnly, then record only if allowed), `pre`
(checkOnly only) and `out` (record only); API streams use `out` (recordSuccess-only, the documented API integration)
and `pre+out`. OTP is **not** in scope of this plan and will be reported as `[UNPROVEN]`.

Lifecycle cells (each compared with the same cell without the lifecycle event):

* `ROT` — fingerprint-secret rotation (current + previous hasher, `DEVICE_FINGERPRINT.md` §5.1) on D1, X2/`static`.
* `V6` — D1 and L1 over IPv6 (distinct `/64`s; one shared `/64`).
* `MIX` — a mixed-version fleet: requests alternate between an `S0I` worker and a `CIRI` worker on one shared store (L1,
  KD).
* `ROLL` — deployment switch `S0I` → `CIRI` halfway through BRU and KD on a shared store.
* `MAL` — missing, `[]`, `['ciid' => '']`, `['ciid' => null]`, non-serialisable, 1 MiB and 600-deep identifiers (resolver
  outcome per input), plus D1 where every client sends the same empty id `['ciid' => '']` (a buggy Host).

## 4. Metrics

Per cell, from the public results only:

* per-request `check` and `record` decision strings (`A` allow, `S` soft, `H` hard, `-` not issued);
* `legitDenied` / `legitAttempts` and `legitUsersDenied`;
* `reach` = attacker attempts whose `checkOnly` allowed (flows `pre`, `pre+out`); for `out` every attempt reaches the
  verifier by construction, so only `recordDenied` is reported;
* attribution of each first legitimate denial to the persisted hard blocks active at that moment (K1 … K5), and, for
  benign streams, a correlation-disabled counterfactual (`ChurnDilutionDisabledStore` of PR #103);
* correlation-store calls per command (distinct-set and watch calls);
* distinct fingerprint / K3 / K5 keys per actor where relevant.

## 5. Gates and verdict rule

All gates are evaluated mechanically by `report.php` from the archived per-request evidence.

### Security gates (hard)

| Gate | Definition |
|---|---|
| G-S1 best response | For every attack stream, shape and flow ∈ {`pre`, `pre+out`}: `max_{s∈Σ_I} reach(CIRI, s) ≤ max_{s∈Σ_N} reach(S0N, s)` |
| G-S1b same input | For every attack stream, shape, flow ∈ {`pre`, `pre+out`} and `s ∈ Σ_I`: `reach(CIRI, s) ≤ reach(S0I, s)` |
| G-S2 no-id parity | `CIRN` decision strings are byte-identical to `S0N` for every stream, shape and flow; and `CIRI` equals `S0N` byte-for-byte on pure-attack streams for `s ∈ {none, uarot}` |
| G-S3 K1 / K4 / DEC-021 | Covered for SP, BRU, BRD by G-S1/G-S1b; additionally KD and UAC: `reach(CIRI) ≤ reach(S0I)` and the DEC-021 K5 path is the denying path in both |
| G-S4 possession ≠ trust | (a) on every request of every `CIRI` cell, CIR's confidence and trust flags equal the shipped resolver's on the same context; (b) POI: owner `legitDenied(CIRI, stolen) ≤ legitDenied(S0N, none)` and attacker `reach(CIRI, stolen) ≤ reach(S0N, none)` |
| G-S5 bounded cost | for every cell, the maximum correlation-store calls per command of `CIRI` ≤ that of `S0I` for the same stream / strategy / flow |
| G-S6 rotation | `ROT`: with a previous fingerprint hasher configured, `CIRI` decision strings equal `CIRI` without rotation on fresh state |

### Availability gates (hard)

| Gate | Definition |
|---|---|
| G-F1 #97 relief | D1, L3 (Login, every flow) and D1API (`api`, `api_custom`, every flow): `legitDenied(CIRI) ≤ 1 %` of legitimate attempts **and** no first legitimate denial attributed to a K2 block |
| G-F2 `other/0` relief | D1A: same thresholds as G-F1 |
| G-F3 UA-update continuity | UAC under `CIRI`: exactly 1 distinct fingerprint and 1 distinct K5 key |
| G-F4 mobility | L4, RM1, RM2 (every flow): `legitDenied(CIRI) ≤ legitDenied(S0N)` |
| G-F5 shared NAT | L1, OFF12, V6-L1 (every flow): `legitDenied(CIRI) ≤ legitDenied(S0N)` |
| G-F6 session transition | SESS (every flow): `legitDenied(CIRI) ≤ legitDenied(S0N)` |

### Disclosure items (pre-registered as non-blocking; must be reported with numbers)

* `MIX` and `ROLL` deltas (transient deployment effects; a non-zero delta requires a stated mitigation).
* `MAL` per-input outcomes, including the shared-empty-id D1 cell.
* `NUA*` results (the Q4 answer) and `CIRI-CHOFF` (attribution of any G-F5 / G-F6 failure).
* `S0N` best-response reach per attack stream (how much protection the shipped LOW dilution gives against an adaptive
  attacker).

### Verdict rule

* **NO-GO** if any hard gate (G-S1 … G-S6, G-F1 … G-F6) FAILs. Availability gates are hard because the defect under
  study (#97) is itself a denial of service to legitimate users; trading it for a measured denial elsewhere is not a
  fix.
* **INCONCLUSIVE** if no hard gate FAILs but at least one hard gate cannot be evaluated (missing or invalid evidence).
* **GO FOR OWNER REVIEW** only if every hard gate PASSes — and even then it is a reviewable candidate, not an
  implementation approval.

## 6. Execution record

Executed with `bash investigations/ua-client-identity-97/run.sh` on the baseline; evidence in `expected/`, gate details in
`expected/gates.json`, tables in `expected/summary.md`.

| Gate | Executed / result | Checks | Decisive failures |
|---|---|---|---|
| G-S1 best response | executed — **FAIL** | 24 | X7 `pre` / `pre+out`: CIRI 121 vs S0N 44; BRD `pre`: 37 vs 22 |
| G-S1b same input | executed — **FAIL** | 122 | `static_uarot`: X7 121 vs 44; BRU `pre` 100 vs 3; X1 `pre` 61 vs 3; BRD `pre` 37 vs 7; BRU `pre+out` 3 vs 2 |
| G-S2 no-id parity | executed — PASS | 177 | — |
| G-S3 K1 / K4 / DEC-021 | executed — **FAIL** | 17 | only through G-S1b's BRU / BRD cells; KD / UAC owner reach and K5 path PASS (K5 path proven by runtime watcher witness + ablation, see §7 note 7) |
| G-S4 possession ≠ trust | executed — **FAIL** | 242 | (a) PASS on every CIRI request; (b) POI attacker reach 32 vs 4 (`pre`, `pre+out`) |
| G-S5 bounded cost | executed — **FAIL** | 236 | 20 `static_uarot` cells, +1 or +2 correlation calls per command (maximum 7) |
| G-S6 rotation | executed — PASS | 2 | — |
| G-F1 #97 relief | executed — PASS | 10 | — |
| G-F2 `other/0` relief | executed — PASS | 3 | — |
| G-F3 UA-update continuity | executed — PASS | 3 | — |
| G-F4 mobility | executed — PASS | 9 | — |
| G-F5 shared NAT | executed — **FAIL** | 9 | L1 0 → 127/134; OFF12 8 → 11/13; V6L1 0 → 127/134 (every flow) |
| G-F6 session transition | executed — PASS | 3 | — |

Verdict by the §5 rule: **NO-GO**.

Not executed (as announced in §3): OTP; Redis; concurrency.

## 7. Post-run notes

Everything below happened after the pre-registered run. None of it changes a gate formula, threshold, stream, strategy set or the
verdict rule.

1. **Operationalisation of G-S3** (wording fixed before the run, implementation chosen after): "owner reach" = owner attempts whose
   `checkOnly` allowed; "the DEC-021 K5 path is the denying path" = the K5 score at the owner's first denial is ≥ the K5 `l1`
   threshold (8). In checkOnly-only cells no failure is ever recorded, so no owner is denied in either arm; the first harness
   version reported those vacuous cells as FAIL and was corrected to "not applicable". G-S3 still FAILs, through G-S1b.
2. **G-S4a** keeps the registered equality test (`confMismatch`); a separate `confEscalation` counter was added for the post-hoc
   probe, whose confidence is lower than the shipped resolver's by design.
3. **Two pinned observation texts** (O7, O13) were corrected to the measured values (known-device reach is 5 vs 8, and 6 / 7 vs 5;
   the first draft had read denied counts instead of allowed counts). Observations are claims checked by `report.php`, not gates.
4. **Post-hoc probe CIRLI** (`scenarios/c03_posthoc_probe.php`): the CIR derivation with an untrusted id kept at LOW. Added because
   the run showed the MEDIUM lift as a root cause. It is labelled in the evidence, excluded from the verdict, and its gate results
   are reported only as diagnosis.
5. **Post-hoc cell V6D1W** (`c02`): the pre-registered V6D1 places all 600 users in one `/48`, where adaptive IPv6 aggregation
   dominates every arm (596–598/600). V6D1W puts each user in its own `/32`; it is disclosure only. V6D1 stays as registered.
6. **Post-hoc metric "victim-guess reach"** (`report.php`, O16): attacker attempts that are failed guesses against accounts the
   attacker does not own. Added because G-S1's X7 failure turned out to consist only of admitted padding logins on the attacker's
   own account. It explains the size of the registered failures; it does not replace `reach` in any gate.
7. **G-S3 K5-path evidence strengthened** (Lead review F125-01 on `084a88e`): note 1's score test ("K5 score ≥ l1 at the first
   denial") did not prove that K5 was the winning source. It is replaced by two required conditions per cell with a denial: the
   runtime wrote that SOFT to the device Anti-Equilibrium watcher and not to the account watcher (only a K5-only winning set does
   that), and under `Ci97K5LevelAblatedLoginPolicy` the earlier decisions are identical and the denied command is ALLOW. A HARD
   first denial counts as not attributed. The §5 wording is unchanged; the `out` flow is now also attributed. Result unchanged
   (PASS for the K5 part; G-S3 FAIL through G-S1b).
