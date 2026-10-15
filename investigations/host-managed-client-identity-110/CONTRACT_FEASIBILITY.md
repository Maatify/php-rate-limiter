# Contract Feasibility — Host-Managed Client Instance Identity

Verdict: **`CONTRACT_CHANGE_REQUIRED`** for the only direction that is not refuted (C). Nothing below is approved;
each item names the Owner decision it would need. Evidence labels as in [`CURRENT_STATE.md`](CURRENT_STATE.md).

## 1. What is possible with the current contracts (no change)

| Possible today | Value for NAT | Evidence |
|---|---|---|
| Host puts a browser id in `clientFingerprint` | negative: more K2 churn | `[H01]` O2 |
| Host puts it in `sessionDeviceId` untrusted | same as above, at LOW confidence | `[H01]` O3 |
| Host uses `correlationId` for it | removes spray protection, no NAT gain | `[H01]` O7, O8 |
| Host marks pre-auth cookie holders trusted | breaks `DEVICE_FINGERPRINT.md` §3.3 / `DECISION_MATRIX.md` §0; sprayer 200/200 | `[H01]` O4–O6 |
| Host supplies a stable id via a custom `DeviceIdentityResolverInterface` that returns a DeviceFP equal to the instance id only (ignoring UA) | stable K3/K5 per browser across updates, but still one churn member per browser and still MEDIUM/LOW semantics; contradicts §5.1.2 (raw identity owned by the resolver contract) and §7.1 | `[ANALYTIC]` |

No combination of existing inputs separates instance identity from the composite fingerprint and from trust. Direction D
(re-interpretation without new input) is excluded by `SECURITY_ANALYSIS.md` §1.

## 2. What needs a normative change

| Item | Current owner | Change needed | Decision class |
|---|---|---|---|
| New optional input `clientInstanceId` | `RateLimitContextDTO` (public DTO), `jsonSerialize()` shape | additive last constructor parameter `?string $clientInstanceId = null` and serialized key | public API (minor, additive) |
| Meaning of a host client id | `DEVICE_FINGERPRINT.md` §3.2 "Client ID MUST NOT be treated as stable identity" | new section: instance identity is stable for rate-limit state, not a fingerprint level, not trust, never in the DeviceFP raw string | canonical contract, security review |
| Key set | `KEY_STRATEGY.md` §3 "fixed, minimal key set" K1–K5; §10 "Key definitions are part of the public contract" | add K6 (policy + instance), its auxiliary sets and their bounds; version bump + migration note | canonical contract |
| Collective rules | `DECISION_MATRIX.md` §5.1 spray, §5.3 churn, §2.1 pre-attempt checks (K1 / K2 apply to all) | established-instance exceptions with per-instance replacements and the network ceiling | material policy — requires a new ADR (`DECISION_GOVERNANCE_STANDARD_AR.md` §2–§4); must supersede or explicitly complement DEC-019 §6 "Exit … requires a future Owner-approved replacement" |
| DEC-021 interaction | DEC-021 §5–§6 | none — HMCI must not change account rules; the ADR must state it | statement only |
| Host guidance | `docs/guides/USAGE_GUIDE.md` (has no identity guidance today) | how to issue, protect, pass, rotate the id; never in `correlationId`; never as trust | documentation |

`DEC-020` is unallocated and reserved by the merged #110 evidence for "the pending shared-NAT replacement decision"
(`DEC-021` header). This study does not create, number or propose the text of any ADR.

## 3. Store capabilities

`[ANALYTIC]`, to be proven by a candidate:

* Bounded distinct sets with and without rotation (`BoundedCorrelationStoreInterface`,
  `BoundedCorrelationRotationStoreInterface`) cover per-instance spray, per-instance churn, replay, `E_acct`, `E_net` and
  `C_net`. These are existing capabilities used by spray / churn / device caps.
* `RateLimitStoreInterface` scores / blocks cover K6.
* Standing record: a value with TTL (`RateLimitStoreInterface::set()`), written only after an admitted establishment.
  Whether "admit to `E_acct` and write standing" needs one atomic operation (new capability) or tolerates a two-step write
  (admitted but standing missing ⇒ instance stays `NEW`, a fail-safe direction) is an open point (O-3).
* No mandatory new capability is assumed; if one is needed it must be additive and optional (DEC-005/DEC-008 pattern),
  with S0 behaviour when absent.

## 4. Backward compatibility

| Aspect | Effect |
|---|---|
| Source | additive optional parameter at the end of the DTO constructor; positional and named callers unchanged |
| Serialization | `jsonSerialize()` gains one key — consumers that compare the array exactly would see a difference (minor-version note) |
| Behaviour without the field | must be **bit-identical** to S0-on-main (gate G-S0) |
| DeviceFP / K1–K5 keys | unchanged (the id is not in the raw string) ⇒ no key migration, no flush |
| Custom resolvers | unaffected (`DeviceIdentityResolverInterface::resolve()` unchanged) |
| Custom policies (DEC-008) | the established-instance exceptions would need a capability (e.g. opt-in), so custom policies keep S0 unless they opt in |

## 5. Redis / concurrency / rollback

* Redis: new keys follow the existing HMAC namespacing (`{policy}:rate_limiter:…:v1:{env}:…`); bounded sets use the same
  Lua-backed operations as spray / churn. Key hygiene and wrong-type handling must be re-tested (as #116 Q6 did).
* Concurrency: observations at `checkOnly` are atomic bounded admissions (same as S0 spray); establishment at
  `recordSuccess` is bounded by `E_acct` atomically. Not measured here.
* Rollback: S0-on-main ignores the new keys; decisions return to S0 (availability regression only). Roll-forward: an
  established record written before rollback may still be valid after roll-forward until its TTL — acceptable only if
  the caps were enforced when it was written. Mixed fleet: old workers = S0 for everybody; the instance sees alternating
  availability, never weaker security. To be measured (gate G-MIX).
* Fingerprint / key secret rotation: K6 and the establishment sets need the current / previous generation model of
  `KEY_STRATEGY.md` §4.3 (read previous, write current, bridge); standing must not be lost on outer-secret rotation (the
  #115 incidental finding that outer-only rotation hard-blocks a stable device shows this path is fragile).
