# Current State — identity inputs, keys and consumption on `main@a6c79f4`

Baseline: `main@a6c79f45e79171e26031fc720822428e09e6e36c` (DEC-019 ACTIVE, DEC-021 ACTIVE). All line references are to that
tree. Evidence labels used in this study:

| Label | Meaning |
|---|---|
| `[CODE]` | read directly from `src/` at the baseline (file:line given) |
| `[CONTRACT]` | stated in a canonical document (`docs/…`) or an ACTIVE decision |
| `[TEST]` | asserted by an existing test in `tests/` |
| `[H01]` | measured by this study's isolated harness (`expected/summary.md`), S0-on-main runtime, reference stores |
| `[PRIOR]` | measured by a merged earlier study under `investigations/` |
| `[ANALYTIC]` | derived by reasoning from the above; not executed |

## 1. Responsibility of each identity field today

`RateLimitContextDTO` ([`src/DTO/RateLimitContextDTO.php:23-33`](../../src/DTO/RateLimitContextDTO.php#L23)) carries nine
inputs. The fields named in the brief are used as follows `[CODE]`:

| Field | Where it is read | Responsibility today |
|---|---|---|
| `ip` | `EvaluationPipeline::buildKeys()` [`:2611-2613`](../../src/Service/EvaluationPipeline.php#L2611), `getIpPrefix()` `:2786` | K1 / K2 / K3 network scope (IPv4 exact, IPv6 `/64`); IPv6 macro scopes for correlation only |
| `ua` | `DeviceIdentityResolver::resolve()` [`:30`](../../src/Service/DeviceIdentityResolver.php#L30) → `normalizeUserAgent()` `:57-74` | reduced to `browser/major` (`other/0` fallback); part of K2 **and** of the DeviceFP raw string |
| `clientFingerprint` | `DeviceIdentityResolver.php:31-33`, canonical JSON `:79-146` | folded into the DeviceFP raw string; non-empty → confidence `MEDIUM` |
| `sessionDeviceId` | `DeviceIdentityResolver.php:34` | folded into the DeviceFP raw string **whether trusted or not**; with `isSessionTrusted` → `HIGH` |
| `isSessionTrusted` | `DeviceIdentityResolver.php:35` | `isTrustedSession = sessionDeviceId !== '' && isSessionTrusted`; drives trusted K1 advisory (`EvaluationPipeline.php:513`, `:584`, `:1509`), known-device classification (`:2388-2391`), budget floor (`:2194`) |
| `isDevicePreviouslyVerifiedForAccount` | passed through `DeviceIdentityResolver.php:49` | second half of `isKnownForAccount` (`EvaluationPipeline.php:2390`) → K5 scoring, DEC-021 allowance and K5 thresholds |
| `accountId` | `buildKeys()` `:2614-2615`, auxiliary keys `:2363-2386`, spray fallback `:1481` | K4 / K5 enforcement identity; budget, allowance, Anti-Equilibrium, flood; spray subject when `correlationId` is null |
| `correlationId` | **only** `EvaluationPipeline.php:1012` (IPv6 participation) and `:1481` (spray subject) | `correlationSubject = correlationId ?? accountId` for K1 credential spray; nothing else |

`correlationId` is therefore a **security subject** for spray, not a client identity: a Host that sets one constant
`correlationId` for many accounts makes them count as one spray subject. This is designed behaviour, asserted by
`CredentialSprayAndTrustedK1Test::testCorrelationIdIsAStableSubjectAcrossAccountsWhileK4RemainsSeparate`
(`tests/Integration/Correlation/CredentialSprayAndTrustedK1Test.php:123`) `[TEST]` and measured as a 200/200 sprayer
reach when a browser id is put there (`[H01]` SPR_S / M4; `[PRIOR]` #108 "constant id 200/200"). It must not be reused as
the browser identity.

## 2. Inputs available before and after authentication

| Input | Anonymous / pre-auth (`checkOnly` before the credential is verified) | After successful authentication |
|---|---|---|
| `ip`, `ua` | yes | yes |
| `clientFingerprint` | yes (Host-collected, optional) | yes |
| `accountId` | the **claimed** account of the attempt (Login/OTP), or null on anonymous endpoints | the authenticated account |
| `correlationId` | Host-chosen subject (e.g. normalized username blind index) | same |
| `sessionDeviceId` + `isSessionTrusted` | **contract: not available** — Level 3 is "Post-authentication only" and "Bound to authenticated AccountID" (`DEVICE_FINGERPRINT.md` §3.3; `DECISION_MATRIX.md` §0 "Trusted Session Device") `[CONTRACT]` | yes |
| `isDevicePreviouslyVerifiedForAccount` | Host fact about the (claimed) account + device | same |

Runtime does **not** enforce the pre/post split: `sessionDeviceId` is accepted without `accountId` and without trust, and is
folded into the DeviceFP in both cases `[CODE]` `DeviceIdentityResolver.php:34-39`. Nothing in the package identifies a
browser *instance* across the authentication boundary; the only continuous thing is the composite DeviceFP.

## 3. DeviceFP derivation

```text
raw  = "v2|" . normalizeUserAgent(ua) . "|" . canonicalJson(clientFingerprint) . "|" . (sessionDeviceId ?? "")
DeviceFP          = HMAC(raw, currentFingerprintSecret)
previousDeviceFP  = HMAC(raw, previousFingerprintSecret)   // only when configured
confidence        = trustedSession ? HIGH : (clientFp !== "" ? MEDIUM : LOW)
```

`[CODE]` `DeviceIdentityResolver.php:30-51`; `[TEST]` `DeviceIdentityResolverContractTest.php:149` asserts the exact raw
string. Consequences:

* `fingerprintHash` is **never null and never empty** with the default resolver (`FingerprintHasher::hash()` returns a hex
  HMAC, `src/Service/FingerprintHasher.php:33-36`). A request with no client data still has `HMAC("v2|firefox/130||")`.
* With no client data (LOW), **every browser of the same normalized UA has the same DeviceFP**, on every IP. LOW is a UA
  cohort, not a device (`[PRIOR]` EQ `DESIGN.md:38-45`).
* Any change in UA major, client hints or session id produces a **different DeviceFP**: there is no notion of "the same
  client with a changed attribute".

## 4. Effect of a UA or session-id change on fingerprint stability

| Change | DeviceFP | K3 / K5 | K4 | Measured |
|---|---|---|---|---|
| Browser update (UA major) | new | new keys; the old K5 score/history is not read for the new FP | unchanged | `[H01]` UAS→UAC: known owner reach 5 → 8 of 12, `distinctK5 = 2` in every mode, including a stable browser id (O10) |
| New session id (login / re-login) | new (session id is in the raw string) | new | unchanged | `[PRIOR]` #113: "each session is a distinct fingerprint"; 12 sessions on one IP+UA are churn-denied |
| Client hint change | new | new | unchanged | `[ANALYTIC]` from the raw string |

Persistent account history is carried only by K4 and the account-scoped auxiliary state (budget, allowance,
Anti-Equilibrium, flood) `[CONTRACT]` `DEVICE_FINGERPRINT.md` §3.3 "Persistent enforcement history survives session-identifier
rotation". DEC-021's fingerprint-independent allowance exists precisely because K5 history does not survive FP changes
(`DEC-021` Rationale) `[CONTRACT]`.

## 5. K1–K5 composition and scope

`[CODE]` `EvaluationPipeline::buildKeys()` [`:2603-2618`](../../src/Service/EvaluationPipeline.php#L2603); all keys are
`HMAC("{policy}:rate_limiter:{kN}:v2:{env}:{scope}", secret)`.

| Key | Scope value | Created when | Login/OTP enforcement source today |
|---|---|---|---|
| K1 | IP prefix | always | credential-spray HARD L2 persisted on K1 (`:1473-1527`); the Login/OTP presets define **no** K1 threshold, so `k1_spray` score deltas never produce a level (`LoginProtectionPolicy::getScoreThresholds()`, `PolicyThresholdsDTO` defaults null) |
| K2 | IP prefix + normalized UA | always | churn HARD L2 60 s (`:1207-1222`); LOW dilution HARD L2 60 s (`:1264-1279`) |
| K3 | IP prefix + DeviceFP | always (FP never null) | MEDIUM/HIGH dilution after two-window confirmation (`:1286-1327`) |
| K4 | account | `accountId` set | score thresholds Login 5/8/12, OTP 4/7/10; budget; Anti-Equilibrium; flood SOFT; distributed 3rd occurrence L4 |
| K5 | account + DeviceFP | `accountId` set | DEC-021 K5 thresholds 8/12/16 (both presets); distributed-account involved-device blocks; flood HARD |

Pre-attempt hard-block check reads **every** key's persisted block ≥ L2 (`:498-548`), except K1 for a trusted session on a
`TRUSTED_AUTHENTICATION` policy (`:513`). A K1 or K2 block therefore denies **every** client on that IP (K1) or IP + UA
major (K2), whatever its account or browser.

## 6. Actual consumption and escalation limits (Login / OTP presets)

| Rule | Window / cap | Threshold | Effect | Code |
|---|---|---|---|---|
| Credential spray | 600 s bounded set, cap 5, members = HMAC(`correlationId ?? accountId`) | 5 distinct, or 4 + second WATCH (1800 s) | HARD L2 on K1 (PenaltyLadder L2) | `:1473-1572`, observed only on `checkOnly` (`:314`) |
| K2 churn | 600 s bounded set, cap 3, members = DeviceFP | 3 distinct, or 2 + second WATCH (1800 s) | HARD L2 60 s on K2 | `:1162-1222`, `:1329-1344`; observed on **every** command (`:300`) |
| Dilution | 600 s set per DeviceFP, cap 6 IP members | 6, or 5 + WATCH | LOW → K2; MEDIUM/HIGH → K3 after second window | `:1230-1327` |
| Device caps (ephemeral) | 900 s; 10 FP per account, 50 FP per IP | over cap → ephemeral (no K3/K5 writes) | | `EphemeralBucket.php:20-22`; `EvaluationPipeline.php:205-238` |
| Distributed account | 600 s snapshot, cap 4 K5 | 4, or 3 + WATCH | HARD L2 on involved K5s; 3rd occurrence / 24 h → K4 L4 | `:640-740`, `checkOnly` only |
| New-device flood | ≥ 6 account members in device-cap window | | SOFT on K4, then HARD on new K5 | `:328-375` |
| Unknown-device failure | | K4 +3 (Login) / +5 (OTP) | K4 levels | `calculateDeltas()` `:2675-2728` |
| Known-device failure | | K5 +2 / +4; allowance 8 / 2 per 24 h then also K4 | K5 levels on K5 only | `:1600-1616`, DEC-021 |
| Budget | 24 h, Login 20 / OTP 10 | | one SOFT per cooldown | `:1750-1830`, `DECISION_MATRIX.md` §2.4 |
| Anti-Equilibrium | 3 issued SOFT / 6 h, account or device watcher | | HARD L2 on K4 or K5 | `:399-441`, `:456-474` |

`recordSuccess` never decreases any score, set or watch (`isSuccess` is read only at `:457` and `:2242`) `[CODE]`. A
legitimate successful login still feeds K2 churn and dilution (`:300` is not gated on command type).

## 7. Churn, credential spray and LOW — how they are counted

* **Churn** counts **distinct DeviceFP values under one K2** (IP + UA major). It cannot distinguish one client cycling
  fingerprints from several clients that each keep a stable fingerprint. Three stable browsers on one IP + UA are enough
  (`[PRIOR]` #108 N3 56/61, all K2 churn; `[TEST]` `CorrelationBehaviorTest.php:52`). `DECISION_MATRIX.md` §5.3 words the
  rule as "≥ 3 **changes** within 10 minutes"; the implemented quantity is distinct members, not changes (see Finding
  F-HMCI-02 in `REPORT.md`).
* **Credential spray** counts **distinct subjects per K1** at `checkOnly`, before the outcome is known. An office where
  more than four people log in to their own accounts within 10 minutes is, to this rule, identical to a sprayer
  (`[PRIOR]` #108 D2 81/108 denials K1; `[H01]` OFF100 / M0 90/100 users denied, every first denial a K1 block).
* **LOW** (no client data, no trusted session) collapses every browser of one UA major into one DeviceFP. LOW therefore
  never produces churn by itself but makes the K2 dilution scope a population (`[PRIOR]` #97, D1 596/600).

## 8. Limits of the anonymous client before login

An anonymous request has only `ip`, `ua`, optional `clientFingerprint` and optional `correlationId`. K4/K5 do not exist,
so the only per-client memory is K3 (IP + DeviceFP), which with LOW is the whole UA cohort on that IP. There is **no
per-browser budget before authentication** that survives a change of the claimed account: changing the target account
changes K4/K5, and only K1/K2/K3 (all network-shared) remain. This is the structural reason the anonymous phase is judged
collectively.

## 9. Effect of identifier rotation on consumption and protection (S0-on-main)

`[H01]` O9: on the shipped runtime, rotating the id on every attempt **never** gains verifier reach over a stable id:
SPR_R 7/3/3/3/7 and BRU_R 3/2/2/2/3 (M0..M4) against SPR_S 7/7/7/200/200 and BRU_S 3/3/3/7/3. With the id inside the
DeviceFP (M1–M3), rotation trips K2 churn after the second attempt. With M0/M4 the id does not reach the DeviceFP at all.
So S0 already punishes rotation, but it does so through the **same** rule (K2 churn) that punishes a legitimate office
(`[H01]` O2). Any design that relieves the office from churn must keep a rotation bound from somewhere else.

## 10. Does the current state meet the original intent for NAT?

The intent is explicit `[CONTRACT]`:

* `KEY_STRATEGY.md` §1 "Reduce false positives in shared IP environments"; §3.2 K2 "Differentiate devices behind shared
  IPs / Reduce NAT/VPN false positives"; §3.3 K3 "Fair enforcement under shared IPs".
* `DEVICE_FINGERPRINT.md` §1 "Fair enforcement in shared IP environments"; §3.2 the Level 2 client ID "Improves
  differentiation under NAT/VPN".

It is **not met** for the common corporate case (one NAT, one browser major):

1. K2 differentiates by UA major only; one browser major means one K2 `[CODE]`.
2. Adding the per-browser differentiation the contract recommends (§3.2 "Client-generated random ID") makes the
   population trip churn, which is a K2 HARD for everyone on that IP + UA: OFF12 8 → 10 of 12, OFF100 90 → 97 of 100
   users denied `[H01]` O2.
3. K1 spray counts accounts, not clients; independent people on one IP look like a sprayer `[PRIOR]` #108, `[H01]` O1.

DEC-019 records this availability harm as accepted only temporarily `[CONTRACT]`.

## 11. Contract vs implementation differences found

| # | Contract | Implementation | Severity for this study |
|---|---|---|---|
| D1 | `DECISION_MATRIX.md` §2.2 / §2.2.1 / §2.4.1.2 / §3.1 / §3.1.1: "missing DeviceFP" scores K2 +4/+6 and K4 +6/+8 (repeated), and is budget-eligible | the default resolver never produces a missing fingerprint, so these rules are reachable only with a custom `DeviceIdentityResolverInterface`; every test that reaches them hand-builds a `DeviceIdentityDTO` with a null/empty hash (`RateLimiterBudgetOwnerSafetyTest.php:135`, `:153`; `AccountAuxiliaryStateIsolationTest.php:44-181`) | Finding F-HMCI-01 |
| D2 | `DECISION_MATRIX.md` §5.3 "≥ 3 changes" of fingerprint | distinct DeviceFP members per K2 | Finding F-HMCI-02 |
| D3 | `DEVICE_FINGERPRINT.md` §3.2 client ID "Improves differentiation under NAT/VPN" | the same differentiation triggers §7.1 churn on one IP + UA | Finding F-HMCI-02 |
| D4 | `DEVICE_FINGERPRINT.md` §3.3 session id "Post-authentication only", "Bound to authenticated AccountID" | accepted without `accountId` and without trust, still folded into DeviceFP | Finding F-HMCI-03 (Host-responsibility boundary, documentation) |
