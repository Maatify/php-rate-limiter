# Current State — known device, trusted session and network correlation on `main@ebeb0a3`

Baseline: `main@ebeb0a32c8fd42e0d4eb4d2500f30ea14036ad92` (DEC-019 ACTIVE, DEC-021 ACTIVE). All line references are to that tree. The
labels are defined in [`README.md`](README.md). `EP` = `src/Service/EvaluationPipeline.php`, `DIR` = `src/Service/DeviceIdentityResolver.php`.

## 1. The five things the brief separates, and what the code actually has

| Concept (brief) | Input / state in the code today | Who asserts it | Can the library check it? |
|---|---|---|---|
| 1. Device / browser identity | `DeviceFP = HMAC("v2|" . uaMajor . "|" . canonical(clientFingerprint) . "|" . (sessionDeviceId ?? ""))` `[CODE]` `DIR:39-41` | derived from client data + Host session id | no: LOW = one value per UA major on every IP; any change in UA major, hints or session id gives a new DeviceFP (`[PRIOR]` HMCI `CURRENT_STATE.md` §3–§4) |
| 2. Device ↔ account association | `isDevicePreviouslyVerifiedForAccount` (bool, default `false`) `[CODE]` `src/DTO/RateLimitContextDTO.php:31`, passed through unchanged `DIR:49` | Host only (`DEVICE_FINGERPRINT.md` §4.3, `DECISION_MATRIX.md` §0) `[CONTRACT]` | **no**: it is a bare boolean, not bound to the DeviceFP, the account id or any token |
| 3. Trust of the current session | `isTrustedSession = sessionDeviceId !== '' && isSessionTrusted` `[CODE]` `DIR:35` | Host (`DECISION_MATRIX.md` §0: Level 3 id, bound to the same account, matching the server's association) `[CONTRACT]` | only "non-empty + flag". The binding to the account is the Host's promise (HMCI F-HMCI-03) |
| 4. Account risk / history | K4 score and levels; account auxiliary state: budget, `known_device_allowance` (DEC-021), Anti-Equilibrium, flood (`EP:2363-2386`) `[CODE]` | library | yes, but only from **recorded** outcomes |
| 5. Shared network risk | K1 (IP prefix), K2 (IP prefix + UA major), the spray / churn / dilution bounded sets, device caps `[CODE]` `EP:2603-2618`, `EP:1162-1327`, `EP:1473-1571` | library | yes, but the scope is the whole IP (K1) or IP + UA major (K2) |

`isKnownForAccount = isTrustedSession || isDevicePreviouslyVerifiedForAccount` `[CODE]` `EP:2388-2391`, `[CONTRACT]` `DECISION_MATRIX.md` §0,
`DEVICE_FINGERPRINT.md` §4.3. A proof for account A is a proof for A only by the Host's construction. Nothing in the library ties the flag
to the account it is sent with (measured consequence: `VMX`, `SPV` in `SECURITY_ANALYSIS.md` §2).

## 2. Answers to the brief's §4 questions

| Question | Answer on `main` | Evidence |
|---|---|---|
| When is `isKnownForAccount = true`? | Exactly when the Host sends `isDevicePreviouslyVerifiedForAccount = true`, or a non-empty `sessionDeviceId` with `isSessionTrusted = true`. No other input (fingerprint, K5 presence, HIGH confidence) makes it true | `[CODE]` `EP:2388-2391`, `DIR:35`; `[CONTRACT]` `DECISION_MATRIX.md` §0 rules; `[TEST]` `tests/Unit/Device/DeviceIdentityResolverContractTest.php` |
| What actually proves the association? | Only the Host's assertion. The library performs no verification and must not infer it from K5 presence or HIGH confidence | `[CONTRACT]` `DEVICE_FINGERPRINT.md` §4.3 rules 1–4 |
| Can the Host prove it **before** login? | `isDevicePreviouslyVerifiedForAccount`: yes. It is a Host fact about (its device record, the claimed account), which the Host can look up before checking the password. A trusted session: no, by contract. It is "post-authentication only" and bound to the authenticated account. On a login form the user has no session for the claimed account yet | `[CONTRACT]` `DEVICE_FINGERPRINT.md` §3.3 "Availability: Post-authentication only", §4.3 |
| Conditions of a trusted session | Level 3 server-issued id, bound to the same account, matching the server's stored association. The library checks only non-empty + flag | `[CONTRACT]` `DECISION_MATRIX.md` §0; `[CODE]` `DIR:35` |
| Logout / session end | The trusted flag disappears with the session. The device stays known only if the Host also sends the previously-verified fact. Because `sessionDeviceId` is part of the DeviceFP, a new session (or none) gives a **new DeviceFP**: new K3 / K5 keys. K4 and the account auxiliary state (budget, allowance) survive | `[CODE]` `DIR:39`; `[CONTRACT]` `DEVICE_FINGERPRINT.md` §3.3 "Persistent enforcement history survives session-identifier rotation" |
| UA or fingerprint change | New DeviceFP, so new K3 / K5 and K5 history restarts. The previously-verified fact is the Host's and does not change. The DEC-021 allowance is fingerprint-independent and keeps counting | `[CODE]` `DIR:39`, `EP:1600-1616`; `[MAIN]` `TMR`, `VMR`, `TMUA` (`expected/summary.md` §3) |
| Trusted session vs previously verified | Both make the device known (K5 scoring, DEC-021 allowance, micro-cap, device Anti-Equilibrium watcher). **Only** a trusted session also gets: K1 advisory, the budget trusted floor, HIGH confidence, and the session id in its DeviceFP | §3 below |
| Where are K1 / K2 consumed? | K2 churn, LOW dilution and the device caps: on **every** command (check, failure, success). K1 spray: on `checkOnly` only. Distributed-account snapshots: on `checkOnly` only | `[CODE]` `EP:209-229` (device caps), `EP:300` (churn / dilution, unconditional), `EP:314` (spray, pre-check), `EP:286` (distributed, pre-check); `[TEST]` `CredentialSprayAndTrustedK1Test::testCheckOnlyThenRecordFailureDoesNotObserveSprayTwice` (`tests/Integration/Correlation/CredentialSprayAndTrustedK1Test.php:259`) |
| Where is the denial applied? | Two places: (1) `checkActiveBlocks` denies on any persisted block ≥ L2 on K1…K5 of the request, **except K1 for a trusted session** on a `TRUSTED_AUTHENTICATION` policy; (2) the request's own candidates are aggregated in `finalizeDecision` | `[CODE]` `EP:498-549` (skip at `:513`), `EP:2139-2235` |
| Can consumption be separated from enforcement? | It already is, for exactly one case: K1 for trusted sessions. A trusted request's spray candidate is marked `trusted_advisory:`, removed from its own decision, but still **persisted** on K1 for everyone else. Its observation stays in the set | `[CODE]` `EP:1509-1511`, `EP:2151-2158`, `EP:2221`, `EP:2232`; `[TEST]` `CredentialSprayAndTrustedK1Test.php:69` (trusted follow-up ALLOW, untrusted follow-up HARD, set unchanged); `[CONTRACT]` `DECISION_MATRIX.md` §5.1 "Advisory Constraint" |
| Does this separation weaken protection? | Measured in this study for every extension (`REPORT.md` §2) | `[MODEL]` |

## 3. What known / trusted changes in the pipeline today

| Mechanism | Trusted session | Previously verified only | Unknown | Code |
|---|---|---|---|---|
| Failure delta | K5 +2 (Login) / +4 (OTP) | same | K4 +3 / +5 | `EP:2703-2720` |
| DEC-021 allowance (fingerprint-independent, 24 h, Login 8 / OTP 2), then also K4 | yes | yes | n/a | `EP:1600-1616` |
| K5 thresholds 8 / 12 / 16, level persisted on K5 only | yes | yes | n/a | `LoginProtectionPolicy::getScoreThresholds()`, `EP:1909-1940` |
| Budget: known failures count only above the micro-cap (Login 8) | yes | yes | counted directly | `EP:1771-1790` |
| Device Anti-Equilibrium watcher (K5-only SOFT) | yes | yes | n/a | `EP:424-441`, `EP:456-474` |
| K1 persisted block not enforced | **yes** | no | no | `EP:513` |
| K1 score / spray candidate advisory (still persisted) | **yes** | no | no | `EP:584-585`, `EP:1509-1511`, `EP:1871-1887` |
| Budget trusted floor (level − 1, ≥ floor) | **yes** | no | no | `EP:2194-2196` |
| K2 churn / LOW dilution enforced | yes | yes | yes | `EP:1207-1222`, `EP:1264-1279`; `EP:498-549` |
| Counted in the K2 churn / dilution sets | yes | yes | yes | `EP:300` |
| Counted in the K1 spray set | yes | yes | yes | `EP:314` |

**So on main, a previously-verified device receives no network relief at all, and a trusted session receives K1 relief only.** Every
known device is still fully subject to K2. `[MAIN]` `N12T` (12 trusted colleagues) is denied 56/61, all through K2 churn / K2+K1, exactly as on
S0 (`expected/summary.md` §2).

## 4. Command semantics that decide the result

* `checkOnly` = pre-check: `isPreCheck = true`. `recordFailure` / `recordSuccess` = outcome commands `[CODE]`
  `src/Command/RateLimitCommand.php:56-80`.
* **Pre-checks never score K4 / K5** (`EP:380`: updates only when `! isPreCheck`). The DEC-021 allowance and the K5 thresholds learn only
  from recorded failures. A Host that only calls `checkOnly` (`pre`) gives the account bound nothing to learn from. DEC-021 states this
  (`[CONTRACT]` DEC-021 "check-only (`pre`) Hosts report no failures, so the bound cannot learn from them").
  `[MAIN]` `D24K1` `pre`: one compliant trusted session on its own account reaches the verifier 1440/1440 times in 24 h; `KS1` `pre` 200/200.
* `recordSuccess` never lowers any score, set or WATCH (HMCI `CURRENT_STATE.md` §6) `[PRIOR]`. A legitimate success still feeds K2 churn
  (`EP:300`).
* Budget pre-check enforcement: Login `precheck_enforcement = true`, OTP `false` `[CODE]` `LoginProtectionPolicy::getBudgetConfig()`,
  `OtpProtectionPolicy.php:92`.

## 5. Counting rules (unchanged by this study)

| Rule | Window / cap | Threshold | Effect | Code |
|---|---|---|---|---|
| Credential spray | 600 s set, cap 5, member = HMAC(`correlationId ?? accountId`) | 5 distinct, or 4 + second WATCH (1800 s) | HARD L2 persisted on K1 | `EP:1473-1571` |
| K2 churn | 600 s set, cap 3, member = DeviceFP | 3 distinct, or 2 + second WATCH | HARD L2 60 s persisted on K2 | `EP:1174-1222`, `EP:1329-1344` |
| LOW dilution | 600 s set per DeviceFP, cap 6 IP members | 6, or 5 + WATCH | HARD L2 60 s on K2 (LOW) / K3 after two windows | `EP:1230-1327` |
| Device caps | 900 s; 10 FP / account, 50 FP / IP | above cap: ephemeral (no K3 / K5) | known failures then score K4 | `src/Service/EphemeralBucket.php:20-22`, `EP:2708-2713` |
| DEC-003 cycles | 2 new cycles / 6 h → 600 s decay pause | | | `EP:56-59`, Store `blockWithCycleTracking` |

Both network rules count **distinct identities in a shared scope**: spray counts accounts, churn counts DeviceFPs. Neither can tell one actor
from several, nor a benign office from an attacker holding the office's proofs. `[MAIN]` M3: N12T and TMS receive identical decisions request
for request in all three flows (`expected/summary.md` §9).

## 6. Main versus the historical S0 on the #113 streams

S0 is `[PRIOR]` (#113, never re-run); main is `[MAIN]`. Login, attacker reach / legitimate denials (`expected/summary.md` §2):

| Stream | S0 pre+out | main pre+out | S0 pre | main pre | Changed by |
|---|---|---|---|---|---|
| N12T (trusted office) | 56/61 denied | 56/61 | 55/61 | 55/61 | nothing: K2 |
| TMS (12 sessions, office cadence) | 7/61 | 7/61 | 6/61 | 6/61 | nothing: K2 |
| TMSd (12 sessions, dense) | 2/200 | 2/200 | 2/200 | 2/200 | nothing: K2 |
| TMSs (sequential per target) | 18/204 | **5/204** | 18/204 | 18/204 | DEC-021 K5 thresholds on the first target |
| TMX (one session vs 12 accounts, Host-violating) | 200/200 | **60/200** | 200/200 | 200/200 | DEC-021 (pre+out only) |
| TMXb (25 per account) | 300/300 | **48/300** | 300/300 | 300/300 | DEC-021 (pre+out only) |
| SP5 (trusted, 200 fresh accounts) | 200/200 | 200/200 | 200/200 | 200/200 | nothing: each account is failed once |

So on main the cross-account bound of the multi-session attacker is still **K2 alone**, as #113 found on S0. DEC-021 added a per-account bound
in `pre+out` (about 5 verifier reaches per account per 600 s for Login, about 3 for OTP; see `TMX`). It added nothing for check-only Hosts.
