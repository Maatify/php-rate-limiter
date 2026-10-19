# DEC-023 — Host Mode Contract for `checkOnly` and Mode Eligibility of New Admission Policies (#97)

## Decision ID

`DEC-023`

## Status

`ACTIVE` — on the Owner's decision of 2026-10-19.

This record decides **mode eligibility only**. Whether `checkOnly` may take an admission reservation is a separate material
decision, recorded in [DEC-024](DEC-024_OPT_IN_ATOMIC_ADMISSION_RESERVATION_IN_CHECKONLY.md) (Decision Governance §4: one
material decision per record). This record approves neither FULL2, nor any value, nor any Runtime: it fixes the contract
that each Host mode is offered if such a capability is approved and shipped later.

## Date

2026-10-19

## Decision Authority / Deciders

**Owner.** Step 2 of the Owner's five-step plan for #97 (set out after the merge of PR #132; step 1 is DEC-022, PR #133). The
options were prepared in PR #134, merged as `PROPOSED` with no option selected (`f718283`). On 2026-10-19 the Owner chose option A with
sub-option A-2, deferred option B, and approved option C as a principle (recorded separately in DEC-024). The decision is
recorded on GitHub in [PR #134 comment 6256657121](https://github.com/Maatify/php-rate-limiter/pull/134#issuecomment-6256657121), posted by the Lead on the Owner's behalf after the Owner's
explicit approval. It is a contract and capability decision only: not an approval of FULL2, of any value (`U_src`, `F`, `T`,
`C`), of any Runtime, or of a Public API change.

Technical review: the independent Lead reviews of PR #134
([comment 6255448192](https://github.com/Maatify/php-rate-limiter/pull/134#issuecomment-6255448192),
[comment 6256342944](https://github.com/Maatify/php-rate-limiter/pull/134#issuecomment-6256342944)).

## Scope / Concern

- What the library guarantees to a Host in each integration mode, with the repository's terms: `pre+out` (`checkOnly`, then
  `recordFailure` / `recordSuccess`), `pre` (checkOnly-only), and `out` (record-only).
- Which modes may be offered FULL2 (#132) and any other policy whose guarantees need refunds or recorded outcomes.

Out of scope: whether and how `checkOnly` may reserve (DEC-024); every value (`U_src`, `F`, `T`, `C`, budgets; step 4); the
binding design, K5 material and the DEC-021 bounds (step 3); and Runtime (step 5).

## Context

DEC-019 Decision 5 requires any change to the S0 posture to quantify the `checkOnly`-only and record-only guarantees.
DEC-022 (`PROPOSED`, not approved) names this decision as step 2 in its Condition 1. The evidence is collected, per mode and
per guarantee, in the decision paper
[`investigations/k2-identity-admission-97/HOST_MODE_CONTRACT_DECISION.md`](../../investigations/k2-identity-admission-97/HOST_MODE_CONTRACT_DECISION.md).
Its matrix, in short:

| Mode | Source ceiling | Net failure cap | Per-account bound | Bucket refund | P door and budget | P refund | Known-device routing ² | Binding, owner side |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `pre+out` | GUARANTEED | GUARANTEED | GUARANTEED | GUARANTEED | CONDITIONAL | GUARANTEED | CONDITIONAL | GUARANTEED |
| `pre` | GUARANTEED (outer ceiling only) | NOT AVAILABLE | NOT MEASURED (NM-9) | NOT AVAILABLE | NOT MEASURED (NM-1) | NOT AVAILABLE ¹ | CONDITIONAL | NOT MEASURED (NM-2) |
| `out` | NOT AVAILABLE | NOT AVAILABLE | NOT AVAILABLE | NOT AVAILABLE | NOT AVAILABLE | NOT AVAILABLE | NOT AVAILABLE | NOT MEASURED (NM-3) |

¹ In `pre` the P budget and the P refund exclude each other: with refunds L1 72; with `P-NETF` L1 0, but a stolen token
has no P budget except `N_P` (#132 §5.5). ² Not part of FULL2; measured on the G3 v2 `TBK` arms (#130). The conditions,
the evidence for every cell and the gates NM-1 … NM-10 are in the paper (§3–§5).

Three facts from that evidence shape the decision:

1. **`out` gets no pre-verifier bound from any design.** Every decision arrives after the verifier (#113: record-only has
   full reach on every stream, e.g. `TMSd` 200/200; `ARCHITECTURE_PROPOSAL.md` §6.4). The same is true of S0 today (#108: 0
   spray observations in `out`).
2. **`pre` gets what a reservation alone can give, and nothing that needs an outcome.** The net failure cap is inert
   (#130), refunds cannot happen (#130: L1 122/134, D2 61), gate P fails (#132: L1 72, D2 61), and the DEC-021 bound
   never learns (#119: `D24K1` 1440/1440).
3. **Every admission bound is a reservation inside `checkOnly`.** Whether that reservation may exist is DEC-024. Without
   it, this record fixes the eligibility contract but no admission component has a permitted implementation in any mode
   (paper §2 C-1).

## Decision

1. **The current contract stays as it is.** The `checkOnly` / `recordFailure` / `recordSuccess` contract is unchanged for
   every mode and every existing policy. No default changes implicitly.
2. **A new capability, for `pre+out` only.** FULL2, and any policy whose guarantees depend on refunds or recorded outcomes,
   is a **new capability**. It is available to `pre+out` Hosts only, and **not available** to `pre` and `out` Hosts. The
   DEC-008 capability model carries that per-mode statement.
3. **Eligibility is enforced by the library through a declaration (A-2).** The library cannot see a Host's mode from today's
   inputs: `RateLimiterBuilder::withPolicy()` takes no mode, and the intent is carried per call by `RateLimitCommand`. A new
   additive declaration lets the library refuse or ignore the capability for a Host that has not declared `pre+out`. It does
   nothing more. Its form is not designed in this record; it will be an additive Public API surface.

   **A declaration is not evidence that outcomes are complete.** It does not prove that the Host actually calls
   `recordFailure` and `recordSuccess` after every attempt. A Host that declares `pre+out` but does not record its outcomes
   runs, for those attempts, as `pre`: the net failure cap is inert, nothing is refunded, and gate P fails (#130, #132).
   This trust boundary, **outcome completeness (missing or late outcomes)**, is `UNPROVEN`, and the declaration does not
   resolve it. It is the open item NM-10 (paper §5).
4. **NM-10 is a precondition.** No activation of the capability may happen before the NM-10 gate (paper §5) is registered,
   with the outcome-drop shares that its template leaves to the later plan, and passes.
5. **A separate preventive contract for `pre` (option B) is `DEFERRED`.** Option B was that contract: its own security
   guarantees for `pre` Hosts, designed in a later, separately authorised study, without exempting known devices from their
   budgets. No known information source gives a `pre` Host
   both the protection and the refund: in `pre` the P budget and the P refund exclude each other (#132 §5.5), and the only
   outcome-related input the evidence shows there, Host-side token issuance and renewal, was never measured as a stand-in for
   a recorded success (paper §6; NM-8). Deferral does not remove or change today's support for `pre` Hosts: point 1 applies
   to them unchanged.

### Not adopted

- **A-1, eligibility as a contract statement only.** Not adopted: under A-1 a `pre` or `out` Host that enables the capability
  anyway is outside the contract and gets the matching matrix row. For a `pre` Host that means losing the S0 protection kept
  for it, with only the outer ceiling in its place (SP6 144 at `U_src` 24, #132) and the measured costs L1 72, D2 61.
- **R-2, requiring every Host to record outcomes, or changing the signatures or meaning of `checkOnly`, `recordFailure` or
  `recordSuccess` for existing policies.** Rejected: it modifies the current Public API and drops the `pre` and `out` usages
  it supports today.
- **R-3, making FULL2's removal of collective penalties the supported or default behaviour for `pre` or `out` Hosts, or for
  policies that did not opt in.** Rejected: it weakens the S0 protection that DEC-019 keeps. In `pre`, the K2 cross-account
  bound (`TMSd` 2/200, #113) and the K1 spray protection (SP6 20 in `pre+out`, #108 §3; the same observations in `pre`, #108
  §5) would be replaced at most by the outer source ceiling (SP6 144 at `U_src` 24, #132), with no net cap and no refund
  available. A-2 prevents enabling the capability without a declaration; reaching the weaker behaviour through missing
  outcomes after a declaration stays open (point 3; NM-10, paper §5).

R-1 (turning the reservation on by default) concerns the reservation and is recorded in DEC-024.

## Rationale

- **Why a decision about modes, not values.** The evidence separates by mode before it separates by value: the same FULL2
  passes every gate in `pre+out` and fails gate P in `pre` with identical parameters (#132). No value choice changes which
  inputs a mode gives the library.
- **Why the current contract stays for everyone.** The `pre` and `out` rows lose most of FULL2's guarantees, and R-3 shows
  the cost of moving them anyway. Keeping S0 for them preserves DEC-019's posture where no measured replacement exists.
- **Why A-2 over A-1.** Under A-1 a misconfigured `pre` Host reaches the R-3 state silently; A-2 makes the library refuse it
  without a declaration. The residual gap is outcome completeness, which no declaration closes (point 3), so it is a
  precondition (point 4).
- **Why B is deferred, not rejected.** The evidence names what a `pre` contract could and could not deliver (paper §6) but
  no source that gives it both protection and refund. The question stays open for a later, separately authorised study.

## Consequences

Declared costs and effects, from existing evidence:

| Item | Effect |
| --- | --- |
| Legitimate users | `pre+out` Hosts that opt in: every benign stream at or below S0 (#132 A1 PASS): D1 596 → 0, D2 117 → 24, ABA_B 118 → 0, L3 104 → 0, MIX 117 → 54. `pre` and `out` Hosts, and every Host that does not opt in: S0 unchanged, including its harm (trusted office `N12T` denied 55/61 in `pre` and in `out`, #113) |
| Attacker reach | `pre+out` opt-in, at study values (#132, S0 in brackets): SP6 24 (20), SP3 8 (7), SP1n 4 (4), ABA_A 8 (3), POI 96 (80), MIX attack 8 (0), X1 / BRU / BRD 4 / 5 / 5 (2 / 3 / 3). The S0 K2 bound against valid sessions for several accounts (`TMSd` 2/200, #113) was not measured on FULL2; DEC-022 (`PROPOSED`, not approved) proposes to keep those cells as gates. `pre` and `out`: S0 unchanged |
| DEC-008 | Reuses the typed capability model: a new capability plus the Store capability it needs, failing fast at registration without it (`ARCHITECTURE_PROPOSAL.md` §10, DEC-007 precedent). The official names, the existing capabilities and every custom policy that does not opt in keep their behaviour (DEC-008 Backward Compatibility 1–4). The per-mode eligibility is stated in `docs/POLICIES.md` by the decision that ships the capability |
| DEC-015 | Unchanged. Admission bounds do not hold in degraded mode (`ARCHITECTURE_PROPOSAL.md` §10, Owner decision D6). The fallback is one code path for every command but counts calls, so per mode: a `pre+out` attempt uses two fallback units, a `pre` attempt one, and in `out` the fallback decision arrives after the verifier, with no pre-verifier bound (paper §2 C-7) |
| DEC-021 | Unchanged in every mode. Its statement that check-only (`pre`) Hosts report no failures, so its bound cannot learn from them, stays true. K5 separation and the allowance coupling are step 3 |
| Public API | No change to any existing method signature or command, and no change to the meaning of an existing case. Additive public surfaces when the capability ships: a new case on the existing public `PolicyCapabilityEnum`, a new Store capability interface (`ARCHITECTURE_PROPOSAL.md` §10), new values in the existing `RateLimitMetadataDTO::$cause` string (§6.5), and the eligibility declaration (point 3). Optional context inputs (instance id, token) are step 3 |

**Implementation.** A-2 is implemented only with step 5, under a separate Runtime decision; this record is not that
authorisation. Until then no Runtime, contract, preset, threshold or test changes. The canonical contracts
(`docs/POLICIES.md`, `docs/guides/USAGE_GUIDE.md`, `docs/DECISION_MATRIX.md`) are updated with that implementation,
under Decision Governance §3.

**Unchanged:** DEC-019, DEC-021 and DEC-022 (still `PROPOSED`). #97, #100 and #106 stay open.

## Evidence

Every number is quoted from the merged source named. None is new. The per-cell evidence is in the decision paper (§4).

| Source | Used for | Class |
| --- | --- | --- |
| DEC-019 Decision 5 | The `checkOnly`-only / record-only guarantees as a condition of any change | Decision record |
| DEC-021 Consequences | Record-only Hosts gain decisions but no verifier-reach reduction; check-only Hosts report no failures | Decision record |
| DEC-022 Condition 1 | This decision as step 2 | Decision record (`PROPOSED`) |
| #108 `k1-credential-spray-nat/` | K1 spray: SP1n 4, SP3 7, SP6 20 in `pre+out`; identical observations in `pre`; none in `out` (200/200, 1000/1000) | `MEASURED` |
| #113 `trusted-multi-session-s0/` | `TMSd` 2/200 in `pre+out` and `pre`, 200/200 in `out`; `TMX` 200/200; `N12T` 56 / 55 / 55 of 61 | `MEASURED` |
| #119 `account-scoped-known-device-relief-110/` | `D24K1` `pre` 1440/1440, `KS1` `pre` 200/200 (`[MAIN]`) | `MEASURED` (`[MAIN]`) |
| #129 / #130 `k2-identity-admission-97/` (G3, G3 v2) | Net cap inert in `pre`; no refund in `pre` (L1 122/134, D2 61); `out` reach (SP6 1200, BRU 100) | `MEASURED` |
| #132 `k2-identity-admission-97/` (G4 v2) | FULL2 `pre+out` gates; gate P FAIL (L1 72, D2 61); `P-NETF`; `pre` table | `MEASURED`; `MODEL` (C6) |
| `ARCHITECTURE_PROPOSAL.md` §6.4, §6.5, §10 | Modes; additive surfaces | `DERIVED` (proposal text) |

## Relationship to Other Decisions

- **DEC-024:** the companion decision on the `checkOnly` reservation (former option C of this record). This record's
  admission components need it; it does not depend on this record.
- **DEC-019:** unchanged and `ACTIVE`. This record answers the `checkOnly`-only / record-only item of its Decision 5 for
  FULL2 and its components; it is not a replacement of DEC-019.
- **DEC-021:** unchanged and `ACTIVE`.
- **DEC-022:** unchanged and `PROPOSED`. This record and DEC-024 decide its Condition 1. That does not activate DEC-022.
- **DEC-008, DEC-015:** unchanged; effects as stated under Consequences.
- **DEC-003, DEC-007:** unchanged; not affected.

## Canonical Contract / Current Owner

None until the capability ships in step 5; its eligibility will then be stated in `docs/POLICIES.md` (DEC-008). The current
`checkOnly` and outcome semantics stay with `RateLimitCommand`, `docs/guides/USAGE_GUIDE.md` and
`docs/DECISION_MATRIX.md`, under DEC-019 and DEC-021.

## Supersedes

None

## Superseded By

None
