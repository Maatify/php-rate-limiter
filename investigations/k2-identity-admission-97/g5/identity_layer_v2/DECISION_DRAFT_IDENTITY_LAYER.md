# DRAFT — Identity layer for #97: K5 material, the new-device member, K5 separation and the binding (`PROPOSED`, no values)

> **This is draft text for a future decision record, not a decision record.** It has no `DEC-` identifier, is not under `docs/decisions/`, and has no entry in `DECISIONS_INDEX.md` (Decision Governance requires every record
> under `docs/decisions/` to be discoverable from the index, and the PR that carries this draft does not touch the index). Under Decision Governance §5 a `PROPOSED` text is **not implementation authority** and may be cited only as
> non-binding research context. It changes nothing in DEC-015, DEC-019, DEC-021, DEC-023, DEC-024 (all ACTIVE) or DEC-022 (PROPOSED). It contains **no value**: no threshold, budget, TTL, size, window or production setting.
> Prepared because the G5 v2 verdict is `SUPPORTED — LIMITED SCOPE` ([`../../G5_V2_IDENTITY_LAYER.md`](../../G5_V2_IDENTITY_LAYER.md)); every Owner decision below is the Owner's.

## Status

`PROPOSED` (draft; allocation of an identifier and indexing are for the step that records the Owner's decision).

## Scope / Concern

Step 3 of the Owner's five-step plan for #97: what "device" means inside the library for K5 and for the new-device member of the proof lane, how K5 is separated by the admission lane, and the credential (binding) that selects the lane.
Out of scope: every budget and threshold (step 4), Runtime (step 5), the public shape of the instance id, the credential and the proof, and the U-lane known-device bound (below).

## Proposed principles (evidence in G5 and G5 v2)

1. **K5 material.** When a request carries an instance id, the K5 key, the K5 micro-cap and the device Anti-Equilibrium watcher are keyed by account and that id, with **no User-Agent**; without an id they keep today's derivation exactly (the absent-id path, a declared and finite path). A browser update is not a new device
   for K5. *(Measured: G1 items 1–5, G3, G4, G5 of G5 v2.)*
2. **The new-device member is the fingerprint, not the id.** The proof lane's per-account new-device member is keyed by today's UA-inclusive fingerprint. "Device" for K5 is a long-lived instance; "device" for the new-device count is a count of distinct presentations, and an
   id copied with a stolen credential is a constant that must not stop the count. *(Measured: G5 F-G5-01 / F-G5-02 against G5 v2 R:STEAL and R:STREAMS-X7, detector `D-NPID`.)*
3. **K5 separation by the lane.** State written by requests the library verifies as bound goes to the proof family (K5, micro-cap, device watcher, known-device allowance, the account penalty state, the account watcher and cooldown); other requests go to the other family. The same device material names the
   device in both families; only the family label differs. The lane is chosen by one fact: a verified binding. *(Measured: G4 v2 K5U, G5 G4.)*
4. **The binding.** The Host issues, the library verifies. A credential is typed for one subject and one budget group set, carries an instance and an epoch, expires, and is revoked by a per-account epoch. Issuance happens only at Host events (a success on the U lane by an untainted instance, a proven password change or recovery, sliding renewal). *(Design §4; G4 v2.)*
5. **An id mints no budget.** Every budget is keyed by account, source or credential id, never by the id alone; a forged or fresh id gains nothing beyond what DEC-021 already allows for fingerprint rotation. *(Measured: G2.)*

## Declared costs (stated so that they are accepted or refused, not discovered)

* An owner who mistypes across a browser update keeps their K5 history (the designed behavior of principle 1; G5 v2 `UAC`: 6 → 9 denials against the reference).
* The new-device member on the fingerprint makes each distinct browser version seen in a day consume one member; the cost curve is measured (`UAX-NP-K`: no refusal up to the cap, then one per extra version), and equals the reference's.
* The second holder of a proof of the same account is locked out after a password change by the U lane's account bucket, in every arm, the reference included (F-G5v2-05).

## Owner decisions required (none is made here)

| # | Decision | Measured menu |
| --- | --- | --- |
| 1 | Accept the cost of principle 2 (the `UAX-NP-K` curve) | G5 v2 F-G5v2-02 |
| 2 | The fate of the account-wide proof-lane penalty at a password change or recovery: **A** not cleared (the tested primary), **B** keyed by account and epoch, **C** keyed by account and credential | G5 v2 §5.1: A costs the second holder; B costs a pipeline input and rests on the Host's proof of the event; C isolates the second holder and weakens the account bound |
| 3 | The known-device bound on the U lane (F-G5-04): the measured 51 in 24 hours against DEC-021's 45 / 44 / 45 comes from the U lane's bucket, not from this layer. Written proposal, **not measured:** a known device that lost its token stays under DEC-021's allowance and K4 overflow instead of being bounded only by the account bucket ceiling | G5 F-G5-04, G5 v2 §4 |
| 4 | Whether Hosts re-issue credentials to the other holders at a password change | G5 v2 F-G5v2-05 |

## Consequences if adopted (for the recording step)

* A full replacement record of DEC-021 is due at the first activation (DEC-024 item 4); this layer is one of the replaced parts.
* `KS` §4.6 is locked: a binding-epoch auxiliary purpose needs an amendment, and `DFP` §4.2 / §5.1 (the id component as a resolver output with two generations; the K5-material migration as a generation event) need amendments (design §7). The operational reader builds K5 keys on its own and would follow the same material.
* Migration (a one-time K5 restart for devices that start sending an id, two-derivation reads for a bounded window, never concurrent with a secret rotation) and rollback are `DERIVED` in the design (§6) and **not measured**.

## Not decided here

Any value; the public shape of the id, credential and proof; Redis, concurrency and failover behavior; the strength of the Host's proof of a password change; K3, K2, churn and dilution behavior under a constant id with a rotating UA (unchanged, design §2.1); the proof-acquisition paths of G2 combined; multi-IP spray and `U_glob`.
