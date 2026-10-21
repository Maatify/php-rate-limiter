# Identity layer design — K5 material, K5 separation and the binding (#97, step 3)

> **Status:** DESIGN ON PAPER for architectural review, written **before** any step-3 code and before the step-3 plan
> ([`g5/identity_layer/PLAN.md`](g5/identity_layer/PLAN.md)). Not an ADR, not a policy, not a production value, not a public API
> shape, and not an Owner decision. No change to `src/`, `tests/`, `composer.json`, presets, contracts, ADRs, standards, or any
> earlier study. DEC-015, DEC-019, DEC-021 and DEC-023 / DEC-024 stay ACTIVE and unchanged; DEC-022 and the proposal stay PROPOSED.
> Nothing here re-opens the UA-as-identity question, the K2 harm, or `U_src`. Step 4 (budgets) and step 5 (Runtime) are not
> merged into this step.
> **Evidence classes:** `DESIGN` (a rule written here, to be tested by the plan), `DERIVED` (read from the cited contract),
> `PILOT` (a measurement of the **reference** arms taken before the plan to check that the harness can answer the question; it is
> repeated inside the registered run and is not a gate by itself).
> **Citation keys:** `DEC-021` = `docs/decisions/DEC-021_KNOWN_DEVICE_VERIFIER_BOUND.md`; `DFP` = `docs/DEVICE_FINGERPRINT.md`;
> `KS` = `docs/KEY_STRATEGY.md`; `DM` = `docs/DECISION_MATRIX.md`; `P§` = [`ARCHITECTURE_PROPOSAL.md`](ARCHITECTURE_PROPOSAL.md);
> `IAP§` = [`IDENTITY_ATTRIBUTION_PROPOSAL.md`](IDENTITY_ATTRIBUTION_PROPOSAL.md).

> **Post-G5 note (2026-10-19).** Measured by [`G5_IDENTITY_LAYER.md`](G5_IDENTITY_LAYER.md) (registered verdict NO-GO AFTER EXPERIMENT: G3, G5,
> R:STEAL, R:STREAMS). The text below is the design as written before the plan and is not edited. What the results say about it: §2.1's K5, micro-cap and
> device-watcher rows, §3, §4.1–§4.3 and §5 rows 2–8 were measured and hold on the P family; **the `N_P` row of the §2.1 table (member keyed by the id) is the
> one choice the results do not support** (G5 F-G5-01, F-G5-02), and §5 row 4's U-family sentence is confirmed (F-G5-04). §6 (migration, rollback) stays `DERIVED`.

> **Post-G5 v2 note (2026-10-19).** The amendments at the end of this document (§"v2 amendments") put the `N_P` member on the fingerprint and write the `K4P` rule as three arms; measured by
> [`G5_V2_IDENTITY_LAYER.md`](G5_V2_IDENTITY_LAYER.md) (registered verdict SUPPORTED — LIMITED SCOPE). The text above is still not edited.

## 0. The question and what this document settles

**Question (step 3).** Can the K5 material be *account + instance id* without the User-Agent (`IAP§5` rule 2), with K5 separated
by the binding, without losing any guarantee DEC-021 gives a known device? G4 and G4 v2 could not answer it because they kept the
UA inside the material as a declared deviation (`G4_V2_INTEGRATED_REASSEMBLY.md` §9).

This document fixes, on paper: the K5 material with and without an id (§2); the K5 split into a U family and a P family (§3); the
binding — issuance, verification, revocation, Subject / Budget Group, password change, sliding renewal (§4); the item-by-item
DEC-021 parity table (§5); migration and rollback (§6); and the contracts this would touch, by reference only (§7). §8 lists what
stays open. The plan then pre-registers the measurement.

## 1. Vocabulary

| Term | Meaning here |
| --- | --- |
| **instance id** | The optional, separate `clientInstanceId` of `IAP§5`. Opaque, random-looking, minted by the Host, **untrusted** (a client can send any value). In the studies it travels in `sessionDeviceId` (a study carrier only; **no public shape is proposed here**) |
| **device material** `M` | The string that stands for "this device" inside one account's K5 family |
| **U family** | State written by requests that are **not** admitted by a valid binding (the strangers' door): `K5`, device micro-cap, device Anti-Equilibrium watcher, allowance counter, `K4` |
| **P family** | State written by requests admitted by a valid binding (the proven door): `K5p`, `k5p` micro-cap, P device watcher, `known_device_allowance_p`, `K4P` (`P§7.2`, M-A; G4 patch `g4-k5-separation.diff`) |
| **binding** | A Host-issued, library-verified, account-and-instance-bound credential that is the only way into the P lane (`IAP§7`; condition P1) |
| **absent** | The request carries no instance id (empty or missing) |

## 2. K5 material

### 2.1 Rule

| Case | `M` | K5 key |
| --- | --- | --- |
| **id present** | `"id1\|" + instanceId` hashed by the **resolver** with the current (and, during a rotation, the previous) fingerprint hasher | `K5 = HMAC(policy : lane-family : version : env : accountId : M)` — the `K5` / `K5p` family of §3 |
| **absent** (`DESIGN`, the declared-absence path, §2.3) | exactly today's `fingerprintHash` (`v2\|normalizedUa\|normalizedClientFp\|sessionDeviceId`, DFP §5.1.2) | exactly today's K5 |

The material contains **neither the UA nor `clientFingerprint`** when an id is present. That is the whole meaning of "a browser
update is not a new device". K5 stays `AccountID + Device` (KS §3.5): only what "Device" means for a request with an id changes.

`DERIVED` constraint — **the resolver owns the material.** DFP §5.1.1 forbids the pipeline from rebuilding or re-hashing device
material: the normalized raw identity belongs to `DeviceIdentityResolver` only, which hashes it once per generation. The id
component must therefore come out of the resolver as an additional component of the device identity (current and previous
generation, like `fingerprintHash` / `previousFingerprintHash`); the pipeline only *selects* between the two components. This
document fixes that requirement, not the field names or the DTO shape (no public API is proposed). The study build realises it as
an additive component on the existing DTO and resolver, which is the smallest change that respects the contract.

`DESIGN` — **what else uses `M`.** The id-derived material replaces the fingerprint component in the consumers that mean "this
device **for this account**"; every consumer that means "this *source*" keeps today's UA-inclusive component. Rule 2 of `IAP§5`
speaks of K5 only; this table is the explicit closure of the question "what is a device" so that no consumer is changed by
accident.

| Consumer | Scope | Material | Why |
| --- | --- | --- | --- |
| K5 score and persisted K5 level (DEC-021 §2) | account + device | **`M` (id)** | rule 2 |
| K5 micro-cap `microcap:k5…` (KS §4.5.2) | account + device | **`M` (id)** | it is the same device's budget eligibility |
| device Anti-Equilibrium watcher `anti_equilibrium_device` (KS §4.6, DEC-021 §5) | account + device | **`M` (id)** | the watcher escalates the K5 level of that device |
| P-lane new-device member of `N_P` (`P§7.3`) | account + device | **`M` (id)** | a browser update must not consume the daily new-device allowance |
| K3 (IP + device), K2 (IP + UA), churn and dilution sets, `device_cap_ip`, distributed-account snapshots, `device_cap_account` | source or cross-device correlation | today's `fingerprintHash` | observation of the *source*; changing them is the UA-as-identity / K2 question that this step does not re-open (K2 harm is DEC-019's). **Consequence, disclosed:** a client that keeps one id and rotates its UA still produces UA-rotation signals at source scope |

### 2.2 What an id can and cannot do (`DESIGN`)

* **A new id mints no budget** (`IAP§5` rule 3). Every budget the design relies on is keyed by account, by source, or by the
  token's own identifier — never by the id alone: `U_acct` bucket, failure cap, `U_src`, `U_glob`, `P_acct`, `P_proof` (token id),
  the DEC-021 allowance (fingerprint-independent account counter), `N_P` (per account). The one id-keyed state is K5 (and its
  micro-cap and device watcher), and those exist only for a request the Host marks **known** (`isKnownForAccount`, DFP §4.3).
* So an attacker that mints a fresh id per attempt gets a fresh K5 **only if the Host already told the library the device is
  known**. That is the same exposure DEC-021 already accepts for fingerprint rotation, and it is bounded the same way: by the
  fingerprint-independent allowance and then by the K4 overflow (`DEC-021` Rationale: "K5 thresholds alone. Rejected: a device that
  rotates its fingerprint … is not bounded per account").
* An id never raises confidence (`IAP§5` rule 1): confidence stays a function of `clientFingerprint` and of a **trusted** session.
* An id never proves anything (`IAP§5` rule 6). Proof is the binding (§4); the id only says "the client seen before".

### 2.3 Absence is a declared path, not a weaker unannounced behavior (`DESIGN`)

A request without an id follows the **A0 path**, which is defined, finite and visible:

| Aspect | A0 |
| --- | --- |
| K5 / micro-cap / device watcher | today's derivation, byte for byte (§2.1). The K5 family is exactly DEC-021 as shipped |
| lane | **U only.** The binding names an instance, so without an id the boundary has nothing to compare and the request cannot enter P |
| trust minting | none: a success without an id cannot start a binding chain (nothing to bind) |
| budgets | the U-lane budgets of the assembly, identical to a client that sends an id but holds no valid binding |
| grouping | the client is an **anonymous individual**: it is not grouped with every user of the same browser in any penalty (`IAP§5` rule 4); the only place where today's browser-shaped material survives is K5 inside one account |
| visibility | the decision carries the identity source (`id` / `derived`) as an internal diagnostic so that a fleet where the Host forgot to send ids is observable and not silently weaker. Whether and how it is exposed is **not** decided here (no public API) |
| comparison | the arm without ids must reproduce the assembly's behavior with today's derivation **in every cell**, with no cell worse (plan gate 5) |

The same applies per request, not per client: a Host that sends ids from some entry points and not others gets A0 on the others,
with no interaction (an id-bearing device and an id-less device of the same account have different K5 keys).

## 3. K5 separation by the binding: families U and P

The lane is chosen by **one** fact: whether the library verified a binding for this request (§4.2). Nothing else selects it.

### 3.1 What is written, where, from which outcome

| State | U family (`not bound`) | P family (`bound`) | Written from |
| --- | --- | --- | --- |
| K5 score / persisted level | `K5(account, M)` | `K5p(account, M)` | the request's own lane outcome only |
| K5 micro-cap | `microcap:k5` | `microcap:k5p` | same |
| device Anti-Equilibrium watcher | `anti_equilibrium_device` | `anti_equilibrium_device_p` | issued K5-only `SOFT_BLOCK` of that lane |
| known-device allowance counter | `known_device_allowance` | `known_device_allowance_p` | known-device failures of that lane |
| account score and persisted account levels | `K4` (U) | `K4P` | non-device evidence of that lane |
| account Anti-Equilibrium, budget cooldown | U keys | `*_p` keys | of that lane |
| P admission counters (`P_acct`, `P_proof`, `N_P`) | — | keyed by account / token id | reserved before the verifier, refunded on a recorded success (G4 v2 §3 `FULL2`) |

The same `M` (same id) names the device in both families, only the **family label** differs: the owner's history while on U (e.g.
after a token reached its absolute limit) is never read when the same device is on P, which is the case G4 v2 gated (K5U) and
`N-NOK5SEP` showed necessary.

### 3.2 Invariants (`DESIGN`, each is tested or inherited)

1. **U never writes P state.** A request that is not bound reads and writes only U-family keys. (`G1` C9 / `g1PStateLeaks`.)
2. **P is entered only through §4.2.** No Host flag, header, id or `isKnownForAccount` value selects P.
3. **Failure reaches the lane it came from.** A bound request that fails scores `K5p` / `K4P`; the same account's strangers'-door
   attempts keep scoring `K5` / `K4` (U).
4. **Same material, different label.** Separating by family never needs a second device identity: `M` is the same string, so a
   device that moves between lanes (token expiry, password change) is the same device in both.
5. **No laundering.** A device cannot move failures from one family to the other: leaving U for P needs a verified binding, and a
   binding needs a successful login (the password) — the argument of `IAP§13` (E2 / K-6, "safe against history laundering") is unchanged.

## 4. The binding

### 4.1 Issuance (`DESIGN`)

The Host issues; the library verifies. Issuance is allowed only at these events, all Host-side:

| Event | Issued to | Condition |
| --- | --- | --- |
| **U-lane success** | the instance that succeeded | the Host reports a verified success on the **U** lane **and** the instance has **no taint**: fewer than 3 distinct **other** accounts failed from that instance in the preceding 600 s window (`G4_TAINT`; failures on the same account never count). A new chain starts (`bindAt = now`) |
| **password change / account recovery** | the device that performed it | the Host proves the event; the library never learns it from the client. Epoch bump **and** a new chain for that device, with no U-lane request involved (G4 v2 `FULL2`) |
| **sliding renewal** | the presented token | after every verified **P** success: same token id and chain, `exp = min(now + TTL, bindAt + absolute)`; the absolute lifetime is never extended |

A P success never starts a new chain and a U success on a tainted instance issues nothing. Issuance is **not** the library's call:
the library exposes only the verification side; the Host decides when to issue (the same split as `isDevicePreviouslyVerified`).
The study values (TTL 30 days sliding, absolute 90 days) are archive values, not a choice.

### 4.2 Verification (`DESIGN`, condition P1)

A request enters P iff **all** hold; any failure means U (never a refusal by itself):

| Check | Compared with |
| --- | --- |
| integrity | the credential's MAC under the **binding key** (§4.5) |
| **Subject** | the credential's account equals the request's account |
| **Budget Group** | the credential's group set contains the **Budget Group of the operation** (`IAP§4`: login + re-authentication + password change share one group), so renaming an operation never mints a second budget and a credential for another group is refused |
| **instance** | the credential's instance equals the request's presented id (the id only, never the UA) |
| expiry | `now < exp` and `now < bindAt + absolute` |
| **epoch** | the credential's epoch equals the account's current epoch (§4.3) |

The credential's identifier (`jti`) is the key of `P_proof`. A UA change does **not** appear in the table: the binding is to an
instance, not to a browser build, so a browser update keeps the proven door.

What this cannot verify (`IAP§7`): possession. A thief who copies both the id and the credential is accepted as the bound device,
bounded by `P_acct` / `P_proof` / `N_P` and revoked by the epoch.

### 4.3 Revocation: a per-account epoch (`DESIGN`)

* One integer per account. The credential carries the value at issuance; verification compares it with the current value.
* Bumped on password change, "log out everywhere", and Host-detected compromise. **Everything issued before the bump is void**
  (not "the previous generation is still read"), which is why this is **not** a `KS` §10.1 generation-bound namespace: those mean
  "previous is still readable during rotation"; merging the two meanings would break one of them.
* Storage: an account auxiliary key under `KS` §4.6 (a new locked purpose, e.g. `binding_epoch`); **`KS` §4.6 is LOCKED, so this
  needs an amendment, which this step does not make** (§7).
* Cost of reading the epoch is one account-key read on a request that already carries a credential; an absent epoch reads as 0.
* The bump is also the moment the owner re-enters P: at a password change the Host issues the new credential to the owner's device
  in the same step (§4.1), so the owner never needs the strangers' door during a continuing attack (G4 v2 STEAL).

### 4.4 Link to the Subject and the Budget Group (`DESIGN`, P1)

The credential is typed **for one Subject and one Budget Group set**. The library refuses it for another account or another
group; a Host cannot route a credential made for account A into account B or into an unrelated operation class. This is the P1
condition (`IAP§8`, `G2` §9) and is what makes "a P success on one operation" not a P success on a different verifier. Assurance
per operation (a stronger proof for sensitive operations) is a property of the operation, not of the binding, and stays outside
this design.

### 4.5 Key management (`DESIGN`)

* The credential is MAC'd under its own **binding key** (with a key id) that rotates **independently** of the fingerprint secret
  and of the outer key secret, with the same current + at most one previous rule.
* A binding-key rotation changes how credentials verify, not how K5 is derived, so it never contributes a second generation to
  K5; the interaction with the fingerprint rotation is §6.3.

### 4.6 Sliding renewal and the absolute limit (`DESIGN`, as measured in G4 v2)

Renewal on every verified P success keeps an active owner on P (G4 v2 E2). A token that reaches its **absolute** limit during a
continuing attack sends the owner to the strangers' door; only the password-change path was proven to bypass it
(`G4_V2_INTEGRATED_REASSEMBLY.md` §5.7). That residual is unchanged by this step.

## 5. DEC-021 parity, item by item

`PILOT` facts used below (reference arms, before the plan; repeated in the registered run): the DEC-021 pipeline (repo `src/`)
gives a stable known device 45 / 44 / 45 verifier reaches in 24 h at 600 / 300 / 60 s (the recorded numbers); the G4 pipeline on
the **P lane** gives the same 45 / 44 / 45; on the **U lane** the pipeline alone gives 144 / 79 / 77, and the assembled `FULL2`
arm (admission layer, token lost) gives 51 for each interval (the bucket ceiling, `B1`).

| # | DEC-021 item | With UA-free `M` | With K5 separation | Known device that falls to U (token lost) |
| --- | --- | --- | --- | --- |
| 1 | **K5 thresholds** 8 / 12 / 16, L1 at the 4th Login failure, L2 at the 6th, L3 at the 8th; decay 1 point / 300 s | unchanged for a constant UA. A UA change no longer resets K5: the same instance keeps one K5 score, so a browser update **tightens** an owner who is mid-typo (the device is the same device) | the ladder is identical on each family | the ladder applies on `K5` (U). Enforced by the pipeline |
| 2 | **K5 level persisted on the device key only**, never on K4 | the device key is the id-derived key; other devices of the account (other ids) are not blocked | persisted on `K5` or `K5p` by lane; never on K4 / K4P | persisted on `K5`; the owner's other devices stay allowed |
| 3 | **Known-device account allowance** (Login 8 / 24 h, fingerprint-independent, fixed epoch) | the counter is not derived from any device material, so it is untouched | **tied to the P family** (`known_device_allowance_p`) — this is the DEC-021 change `IAP§15` row 3 already named; U keeps its own counter | counted on the U counter, **but see row 4: on U it enforces nothing** |
| 4 | **Overflow: failures beyond the allowance also add the K4 delta** | unchanged | on P: K4 delta lands on `K4P`, whose levels are persisted and enforced (pipeline-only P lane reproduces 45 / 44 / 45; removing the overflow gives 128 / 83 / 76, `PILOT`). On U: lands on `K4`, whose levels are **not** persisted in the assembly (the G3 "no U-lane escalation" element) | **the known device on U loses the K4-overflow enforcement of DEC-021.** The bound is the account bucket instead (51 in 24 h, `PILOT`, against 45 / 44 / 45). This is a property of the G3 / G4 U lane, present with or without a UA-free `M`, and is a **step-4 (budgets)** question, not an identity-layer one |
| 5 | **Anti-Equilibrium, one watcher per issued SOFT**: device watcher only for a K5-only SOFT, account watcher otherwise | the device watcher key uses `M`, so UA changes do not split its history | `anti_equilibrium_device` (U) / `_p` (P) | active on U (the K5-only SOFT is enforced) |
| 6 | **Micro-cap** (budget eligibility of a known device after 8 K5 failures / 24 h) | `M`-keyed; a UA change no longer restarts the count | `k5` / `k5p` families | active on U |
| 7 | **No account-wide lock from K5** (device scope) | the owner's second device (another id) is never blocked by the first's K5 | unchanged | unchanged |
| 8 | **K5 24 h TTL, rotation survival** (current writable, previous read-only) | the id component rotates with the fingerprint hasher pair (§2.1), so the existing two-generation read applies unchanged | unchanged | unchanged |

Net statement of the design (`DESIGN`, to be tested): the identity layer changes **only** rows 1, 5 and 6 in the direction of
"one instance, one history", leaves rows 2, 3, 4, 7, 8 as they are, and adds no new way for an id to mint budget. The U-family
row 4 is stated here so that it is not discovered later: DEC-021's reach number for a known device is retained on the P family
and replaced by the bucket bound on the U family, independent of this step.

## 6. Migration and rollback

### 6.1 One-time K5 restart (`DESIGN`)

A device that starts sending an id changes its K5 key (from the fingerprint-derived to the id-derived material). Its old K5 score,
micro-cap count and device-watcher events are no longer on its key: **a one-time K5 restart** for exactly those devices. Devices
that never send an id (A0) are unaffected. The restart is bounded by the K5 family's lifetimes (24 h score TTL and fixed
micro-cap epoch, plus the persisted level durations).

Laundering concern: sending an id to shed a K5 level is possible only for a device the Host marks known and is bounded by the
fingerprint-independent allowance (§2.2) — the same exposure as changing UA today.

### 6.2 Reading with two derivations during the transition (`DESIGN`)

For a bounded **transition window** the library reads the K5 family under the legacy (fingerprint-derived) material as the
"previous" derivation, and writes only the id-derived key — the existing current-writable / previous-read-only rule
(`KS` §4.3.3), never `max(v1, v2)` (`DFP` §5.1.6). The window is at least the longest K5-family lifetime.

**Correction (stage 5-c, PR #150, 2026-10-20; F-5b3a-LT9, Owner OD-3 (a)).** This section is withdrawn as a mechanism: no
transition window is implemented, because no input opens or closes it and no decision gives its length. The "previous" K5
derivation is the same material under the previous secret (reading A, ruled in 5-b3a), and a device that starts sending an id
restarts its K5 once (§6.1), a cost declared by DEC-026. The text above is kept as the design that was proposed.

### 6.3 Relation to `DFP` §5.1.6 — never concurrent with a secret rotation (`DESIGN`)

The architecture holds **one** previous generation. A change of K5 material is a change of the fingerprint component's
generation, so it uses that same slot. Therefore **a K5-material migration must not start while a fingerprint-secret or
outer-secret rotation still needs its previous generation, and a rotation must not start during the migration window**: two
overlapping generations would require reading K5 state under (old material × old secret), (new material × old secret) and
(new material × new secret) — the Cartesian combination §5.1 forbids, and the K5 micro-cap cannot be merged across them.
Operationally: the migration window is scheduled as a rotation event. A binding-key rotation (§4.5) is independent and may
overlap.

### 6.4 Rollback (`DESIGN`)

| After a code rollback to a build without the identity layer | Result |
| --- | --- |
| K5 / micro-cap / device watcher written under the id material | unreadable by the old build, which derives from the fingerprint: **the device restarts K5 again** |
| P-family state (`K5p`, `K4P`, `known_device_allowance_p`, P watchers, `P_*` counters) | ignored and expires by TTL — the same class as "K4P is lost on rollback" (`P§11.2` LT-6, `IAP§13`): the gate is "no exception, loss documented", not parity with S0 |
| the fingerprint-independent allowance counter | survives (it is account-keyed) and is shared again across lanes by the old build |
| credentials | ignored (the old build has no boundary); epoch values are inert |
| roll-forward | the id-derived K5 written before the rollback is read again if still alive |

## 7. Contracts touched — by reference only

No contract is changed by this step. The table lists what a later decision record would have to amend; DEC-021's full
replacement record is step 5 (DEC-024 item 4).

| Contract | Section | What would change |
| --- | --- | --- |
| DEC-021 | Decision 1–5 | K5 material (id), the allowance tied to the P family, the device watcher and micro-cap keyed by `M`; a **full replacement record** is written in step 5, not here |
| DFP | §4.2 (confidence), §5.1 (rotation) | the instance id as a component that never raises confidence; the resolver producing the id component with current and previous generation; the K5-migration slot as a generation event (§6.3) |
| KS | §3.5 (K5), §4.6 (auxiliary keys, **LOCKED**) | K5 = account + device material (id, else fingerprint); family labels `k5` / `k5p`; a new locked purpose for the binding epoch (**needs an amendment**) |
| DM | §2.2.2, §3.1.2 | the allowance and the device watcher by family; the known device's behavior on each door |

Also noted, not part of this list: `RateLimitOperationalReader` builds K5 keys on its own (read-only status path) and would have
to follow the same material.

## 8. Open items and limits

* The public shape of the instance id, the credential and the proof is **not** proposed (out of scope by decision).
* The known-device row 4 (§5): DEC-021's K4-overflow enforcement does not exist on the U family of the assembly; its replacement
  is the bucket bound. Whether that is acceptable, or the U family needs a different known-device bound, belongs to step 4.
* `device_cap_account` and the other source-scoped consumers keep today's fingerprint (§2.1): a constant id with a rotating UA
  still looks like several devices to them. Whether that is acceptable is not decided here.
* Real cookies, Redis, concurrency, failover, DEC-015 degraded mode, and the strength of the Host's issuance and recovery flows
  are outside the library and outside this step.

## v2 amendments (2026-10-19)

> **Status:** amendments written **after** the G5 results ([`G5_IDENTITY_LAYER.md`](G5_IDENTITY_LAYER.md), NO-GO AFTER EXPERIMENT: G3, G5, R:STEAL, R:STREAMS) and **before**
> the G5 v2 plan and harness (`g5/identity_layer_v2/PLAN.md`). The text above is the design as committed in `8283d4b` and is not edited; where an
> amendment contradicts it, the amendment wins for G5 v2 only. Same status as the rest of the document: `DESIGN`, not an ADR, not a policy, not a
> production value, not a public API shape, not an Owner decision. DEC-015, DEC-019, DEC-021, DEC-023, DEC-024 stay ACTIVE and unchanged; DEC-022
> and the proposal stay PROPOSED. Nothing here re-opens fixed evidence (the G5 results on the P family, G4 v2 `pre+out`, the removal of the collective
> penalties). Evidence classes as above; `MEASURED` below cites the archived G5 numbers and nothing newer.

### V2-1. The P-lane new-device member `N_P` is keyed by the fingerprint, not by the id (amends §2.1, row "P-lane new-device member of `N_P`")

| Consumer | Scope | v2 material | Why |
| --- | --- | --- | --- |
| K5 score and persisted level, K5 micro-cap, device Anti-Equilibrium watcher | account + device | **`M` (id)** | unchanged from §2.1 |
| P-lane new-device member of `N_P` (`P§7.3`) | account + client presentation | **today's `fingerprintHash`** (`v2\|normalizedUa\|normalizedClientFp\|sessionDeviceId`, UA-inclusive) | see below |

**Why the two "devices" must differ.** K5 and `N_P` answer opposite questions about the word "device":

* For **K5** the device is a long-lived *instance*: its failure history must survive a browser update (G1 UAX, `MEASURED` in G5: one K5 key across the UA
  change, the ladder continues).
* For **`N_P`** the device is a *count of distinct presentations an account sees per day*: a browser update must not be free for an actor that rotates
  presentations on purpose. A stolen-token thief copies the token **and** the id; the id is therefore a constant for the thief, and a member keyed by the id
  stops counting it. The fingerprint also contains the UA and the client fingerprint, which the thief has to rotate (IP and UA rotation is what the
  studies model) to avoid per-source limits, and so it keeps consuming `N_P`.

`MEASURED` in G5 (F-G5-01, F-G5-02, post-hoc arm `NOUA-FPN`): with the member on the id the thief's P admissions before the password change went 9 → 20, the owner's wait after
the change 0 → 1,260 s, and the attacker's own-account reach in X7 / rotate / const 11 → 81 (70 `cap:p_device` refusals disappeared); with the member on the
fingerprint and K5 on the id every one of those cells equals the reference.

**Declared cost.** An owner whose browser changes version consumes one `N_P` member per distinct version seen in a day. `MEASURED` in G5 (post-hoc): 12 successful logins
a day, a new browser major each time, `N_P` = 10 per day → 2 refused (`cap:p_device`), the same number the reference produces. The identity layer adds no refusal
here; it does not remove the reference's either. Whether this number is a cost to accept or a gate is **registered in the plan before the run**, not after.

**Where the member lives.** `N_P` is not part of `src/`: it is the `p_device` member of the admission layer's reservation (the study's `G1Admission`; a
Host or admission component in a runtime), which receives its member material from the caller. The id component added to the resolver and DTO (G5 patch, unchanged) is
consumed by K5, the micro-cap and the device watcher in the pipeline only. So "the member is on the fingerprint" is a property of the admission call, and the
G5 v2 harness proves it in code (a per-admission audit of the reservation's member set against the resolver's fingerprint and id components, plan §3) rather than by
this paragraph.

Every other row of the §2.1 table is unchanged (K3, K2, churn, dilution, `device_cap_ip`, `device_cap_account` keep the fingerprint).

### V2-2. The fate of `K4P` at a password change or recovery (new rule; amends §4.1, §4.3)

§4.1 and §4.3 say the epoch is bumped and the owner's device gets a new chain. They say nothing about the account-wide P penalty state, and G5 F-G5-02 showed that the
state is not cleared: the thief's recorded failures stay on the account after the change. The "account-wide P penalty state" here means everything derived from the P
lane's `k4p` key: the K4P score, its persisted levels and budget, plus the account Anti-Equilibrium watcher and the budget cooldown of the P family (`*_p`). The known-device allowance
counter (`known_device_allowance_p`), K5p, the device watcher, `N_P` and the P admission counters keep their scope under every option.

Three options, written as arms to be **measured**, each with its cost to the account owner and to the holder of a *second proof of the same account* (`P§7.2` item 4: a second
device with its own credential):

| Arm | Rule | Cost to the owner | Cost to a second proof holder | Cost of the design |
| --- | --- | --- | --- | --- |
| **A** | `K4P` is account-wide and **not** cleared at the event (G4 v2 / G5 behavior) | pays for whatever a thief scored on `K4P` before the change, for as long as the persisted levels last; bounded by the P admission counters and `N_P` | the same account-wide state penalizes every credential of the account: failures presented with credential 1 can deny credential 2, before and after the event | none new: no new mechanism, no new input to the pipeline, `K4P` keeps the meaning it has in `P§7.2` |
| **B** | `K4P` state is keyed by account **and epoch**: an epoch bump leaves the old state unreachable (equal, for every read after the bump, to clearing it) | starts clean after a proven event | no gain before the event; at the bump every older credential is void, so the second holder falls to the U lane anyway | the pipeline must read the epoch (a new input today unknown to it); pre-event evidence against **every** actor of the account is dropped, including evidence about an attacker that regains a P credential through a later U success; the clearing is only as strong as the Host's proof of the event (a Host step-up question, `Gate 0/1` blueprint) |
| **C** | `K4P` state is keyed by account **and the presented credential's id** (`jti`) instead of the account | a re-issued credential (new chain, new `jti`) starts clean | isolated: failures presented with credential 1 never reach credential 2 | `K4P` stops being account-wide: an attacker holding *k* credentials of one account gets *k* penalty states. The account-wide bound left is the P admission counters (`P_acct`); the levels no longer escalate per account. One state per credential in storage |

**Primary arm: A.** The plan registers A as the arm whose result decides R:STEAL, **before** the run; B and C are measured and reported, and no gate depends on them. The reasons, in
order: (1) A is the behavior already measured in G4 v2 and G5 and the meaning `P§7.2` item 4 gives `K4P`; it needs no new contract surface or pipeline input. (2) B and C each
trade one cost for another that only an Owner decision on the Host's proof of the event (B) or on what "account-wide" must mean (C) can weigh, and the harness cannot price those
costs, only report the measurable half of them. (3) Disclosed, not a reason: G5's post-hoc arm `NOUA-FPN` already showed that with `N_P` on the fingerprint the thief and the owner
in STEAL equal the reference under A, so the registered gate for A is known to be passable; what the plan adds is the same measurement under registered gates, plus B and C. If A
fails its gate in the run the verdict is NO-GO on R:STEAL and B or C cannot rescue it (rule 5 of the plan).

The arms are also run with the member on the id (the G5 behavior) so that the report can say whether B or C would have repaired F-G5-02 at all.

### V2-3. What the amendments do not change

K5 material, K5 micro-cap and the device watcher stay on the id (§2.1). The K5 separation by the binding, the binding itself (§4), the absent-id path (§2.3), migration and
rollback (§6) are unchanged. K5 migration and rollback stay `DERIVED`. The step-4 question of §5 row 4 (the known device that fell to U keeps the bucket bound rather than DEC-021's K4 overflow)
is **not** part of G5 v2: it is recorded there as F-G5-04 with a single written proposal for step 4 and is not measured.
