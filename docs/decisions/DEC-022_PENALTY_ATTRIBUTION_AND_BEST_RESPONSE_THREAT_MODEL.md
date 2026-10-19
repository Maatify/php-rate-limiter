# DEC-022 — Penalty Attribution and Best-Response Threat Model for Client-Identity Correlation (#97)

## Decision ID

`DEC-022`

`DEC-020` remains intentionally unallocated, as recorded in DEC-021. DEC-022 is not that
reserved label: it proposes only the UA-similarity part of a DEC-019 replacement.

## Status

`PROPOSED`

This is a proposal. It has not been approved. Under Decision Governance §5 a `PROPOSED`
decision is **not implementation authority**: no Runtime, contract, preset, threshold, test,
study gate or review verdict may rely on it as an approved rule. It may be cited as
non-binding research context, provided the citation states that the change has not been
approved. It does not change the status, text or effect of DEC-019 or DEC-021, which remain
`ACTIVE` and unchanged. The current runtime authorities are the ones DEC-019 names.

## Date

2026-10-18

## Decision Authority / Deciders

**Owner.** Every material policy, security trade-off, value, activation, merge and release
in this record is the Owner's decision. This record was prepared as step 1 of the Owner's
five-step plan for #97, set out after the merge of PR #132, and is documentation only. It
records no Owner approval of its content.

## Scope / Concern

- Where a persisted penalty may be written when correlation is based on client identity:
  K2 (`IP_PREFIX + UA`) and LOW-confidence K3 (passive, UA-only fingerprint) in Login, OTP,
  API Heavy, and custom policies that declare the DEC-008 capabilities.
- The reference attacker against which #97 remedies are judged.
- The security acceptance criteria that #97 studies and remedies are measured against.

Out of scope (named in [Three decision levels](#three-decision-levels) as separate later
decisions): any change to the protection a source has against credential spray (K1, #106),
every budget value, the FULL2 assembly and its components, and every DEC-021 bound.

## Context

#97 documents legitimate users who are hard-blocked because they share an IP and a browser
major (K2) or a passive UA-only fingerprint (LOW K3) with other people. DEC-019 kept the
shipped S0 correlation behavior as a deliberate, temporary, security-first posture, while
#97, #100 and #106 stay open. Its Decision 6 sets the exit conditions: an Owner-approved
replacement material decision, its durable ADR and canonical contract, and separately
proven and reviewed Runtime.

The merged studies #108 to #132 (see [Evidence](#evidence)) converge on two points:

1. **The harm comes from where the penalty is written.** Candidates that changed thresholds,
   key material or credit rules but kept writing penalties on a scope shared by many people
   either kept the #97 harm or moved it to the NAT. GNH5 (#115) and OAAA (#116) kept most
   of it (D1 596/600 on S0; 584 and 579 under GNH5 and OAAA). PSC (#107) reduced D1 to
   180/600, still denied, and was NO-GO on attacker reach (DEC-019). CIR (#125) removed
   the cohort denials but raised two browsers without client fingerprints behind one router
   from 0/134 to 127/134 denied. The candidates that stopped writing UA-similarity
   penalties removed the cohort denials: G1 (#126) and CIR (#125).
2. **The protection K2 gives against UA-based evasion only stops an attacker who keeps one
   UA.** In #125 (F97-01) LOW dilution stopped the X2 attacker that kept one UA (6/121). The
   same attacker reached 121/121 by rotating the browser major, while the same rule denied
   596/600 legitimate users.

The studies also show that K2 enforcement does more than catch UA-based evasion. On S0 it
is the bound that stops an attacker holding valid sessions for many accounts (#113,
`TMSd` 2/200). It also carries low-volume API anti-evasion: DEC-019 records S0 as
stronger than EQV there.
Converting K2 to observation is therefore not free against attackers who are not naive.
This record states that cost; it does not waive it.

The Owner stated the positions this record relies on on 2026-10-17. They are recorded in
`investigations/k2-identity-admission-97/IDENTITY_ATTRIBUTION_PROPOSAL.md` §3:

- The UA is not protection, because any attacker can change it.
- The account owner must not be locked out because someone attacks the owner's email.
- Proof acquisition is optional.
- Protection is not only Login.

## Decision

**Proposed, not approved.** If it becomes `ACTIVE` under the
[conditions](#conditions-to-become-active) below, this record would establish the
following principles. It sets no value, threshold, key name, store contract or Runtime
mechanism.

1. **Attribution rule.** A persisted penalty is written only on one of two scopes:
   - the relationship between one Actor and one Subject (for example account + device, K5);
     or
   - the door of the Subject through which the failure arrived.

   The doors are separated at the attribution level:
   - **U (strangers' door):** failures of requesters without valid evidence for that
     Subject.
   - **P (proven door):** failures through valid evidence.

   Failures attributed to U never reach P state or a P budget. A scope that holds many
   people (IP or IPv6 prefix, IP + UA, passive fingerprint, churn / dilution / spray sets)
   may have only two roles: observation, or a per-window capacity bound that neither
   escalates nor persists. No one is penalised for *resembling* someone else.

   **Where the rule applies on activation:** UA-similarity penalties (Decision 2) only.
   Several existing contracts do not conform to the rule today. Applying it to any of them
   changes how much protection it gives, so it takes effect only through the later
   decision named below. Until that decision exists, each contract stays as it is, owned by
   its current authority, and is not changed by this record:

   | Contract that does not conform today | Later decision that owns it |
   | --- | --- |
   | K1 credential-spray `HARD_BLOCK` and the Login K1 +5 credential-spray score (#106) | Level 2 |
   | API Heavy K1 severe `HARD_BLOCK` L3 | Level 2 |
   | API Heavy K2 minor `SOFT_BLOCK` L1, persisted per scope | Level 2 |
   | Distributed-account punishment | Level 2 |
   | New-device-flood punishment | Level 2 |
   | MEDIUM/HIGH dilution `HARD_BLOCK (DeviceFP)` on K3 | Level 3 |
   | Account-wide K4 penalties with no U/P door split (K4 scoring, account Anti-Equilibrium, the DEC-007 lifecycle) | Levels 2 and 3 |

   The FAIL_OPEN per-node K1/K2 throttles already conform: they are non-persisted capacity
   bounds.
2. **K2 is an observation key for correlation and UA similarity.** No `HARD_BLOCK` and no
   persisted penalty is derived from UA similarity at LOW confidence:
   - K2 churn;
   - LOW dilution downgraded to K2;
   - the API Heavy LOW moderate K3 signal persisted on K2;
   - missing-fingerprint deltas scored on K2.

   These signals stay observable. K2 penalties are not moved to K1 or to any other shared
   key. Two K2 uses are not UA-similarity attribution and are not changed by this decision:
   K2 as the API Heavy minor-limit rate scope (Decision 1 table, Level 2), and the FAIL_OPEN
   K2 throttle.
3. **Reference threat model.** The reference attacker is the **best response over free
   identifiers**: UA, cookie, client instance id and fingerprint. An identifier is "free"
   when the attacker can mint an unbounded number of them without paying anything outside
   the library. This definition, from `IDENTITY_ATTRIBUTION_PROPOSAL.md` §3, does not
   depend on which bounds a design places, so it is not circular.
   - IPs, credentials and valid sessions cost something outside the library and are not
     free.
   - Attacker-owned accounts are a declared count N in the threat model, neither free nor
     expensive.
   - Success never earns credit on a shared scope.
4. **Change of security acceptance criteria** (part of the proposed policy, not approved).
   - Cells in which the attacker keeps a constant UA become **observations**: they are
     recorded and published, and they are not gates. These are the naive X2 and X7
     constant-UA variants.
   - **Best-response cells stay gates**, and any regression in them stays a gate failure;
     BRD is an example.
   - Single-IP spray (SP1n, SP3, SP6) and the co-located attacker (MIX) are not naive cells
     and stay gates. Where their gate is set belongs to Level 2.
   - Cells whose attacker pays for non-free identifiers stay gates. Examples are valid
     sessions for several accounts (TMS family) and API fingerprint rotation (X1/API,
     Y7/API).

   This change authorises nobody to ignore a security regression. It reclassifies only the
   constant-UA cells. The other costs of Decision 2 are declared under
   [Consequences](#consequences) and stay gates.

## Three decision levels

The #97 work has produced three different kinds of decision. This record proposes only the
first.

| Level | What it is | Status in this record |
| --- | --- | --- |
| **1. Architectural principle** | The attribution rule, K2 as observation, the reference threat model, and the change of acceptance criteria (Decision 1–4). The evidence for it is consistent across the merged studies | **Proposed here** |
| **2. Security decision on cost** | Any relaxation of a source's protection against credential spray (K1 role, source capacity `U_src`, failure cap `F`). The per-account guessing budget (`T`, `C`). The disposition of the distributed-account and new-device-flood punishments. Any replacement of the bounds K2 enforcement gives today against multi-session and API rotation attackers. Each needs the Owner to approve its measured cost | **Not proposed.** Named as separate later decision(s). No value is stated or implied |
| **3. Experimental design with limited scope** | The FULL2 assembly (#132): client instance id, device binding, U/P lanes, P-lane refund budget, sliding renewal, password-change binding, K5 separation. Status: EXPERIMENTALLY SUPPORTED — LIMITED SCOPE, for Hosts that record outcomes only | **Not proposed.** Named as separate later decision(s). Several of its components would amend DEC-021 or LOCKED contracts |

Level 1 does not imply Level 2 or Level 3, and approving Level 1 approves neither. Level 1
cannot become `ACTIVE` alone, because Decision 2 removes bounds that only a Level 2
decision can replace (Condition 3).

## What this proposes to replace

This is a map of references only. It amends nothing. It lists what would change if DEC-022
became `ACTIVE`, and separates three kinds of effect:

- **#97:** a direct consequence of the UA-similarity remedy.
- **Not #97:** touched by the general principle but outside the #97 remedy. Any change
  here needs its own decision. This record does not approve weakening API or K1
  protection.
- **Unchanged.**

| Contract | Today | Effect if DEC-022 became `ACTIVE` | Relation |
| --- | --- | --- | --- |
| **DEC-019** (whole record) | Interim preserve-default posture for K1 / K2 / LOW-UA correlation. Decision 6 exits only through an Owner-approved replacement material decision, its ADR and canonical contract, and proven and reviewed Runtime | DEC-022 could be that replacement **for the UA/K2/LOW part only**. Decision Governance §9 has no partial supersession. The activation step must therefore give the rest of DEC-019's scope an explicit `ACTIVE` owner, either as unchanged retained posture inside the activating record or in a successor record: K1 credential spray (#106), API anti-evasion, `checkOnly` limitations, and custom policies under DEC-008. This record does not choose the form | #97 (partial) |
| DEC-019 Decision 5 | Future changes must quantify fairness and attacker reach: `checkOnly`-only / record-only guarantees, K1 + K2 overlap, IPv6 macro scopes, Current/Previous rotation, trusted / nontrusted collateral, block renewal, cap / N−1 | Carried unchanged into Condition 4 | #97 |
| `KEY_STRATEGY.md` §3.2 (K2) | Purposes: differentiate devices behind shared IPs; reduce NAT/VPN false positives; "safer fallback when DeviceFP confidence is LOW" | K2 keeps its observation purposes and loses its correlation and UA-similarity enforcement purposes, including the LOW fallback. K2 as the API Heavy rate scope and as the FAIL_OPEN throttle scope is not #97 and is unchanged (rows `DECISION_MATRIX.md` §4.1 and `FAILURE_SEMANTICS.md` below) | #97 (correlation, LOW fallback); not #97 (API rate, FAIL_OPEN) |
| `KEY_STRATEGY.md` §3.3 (K3, LOW rule) | LOW K3 may throttle | LOW K3 is observation, like K2. Any MEDIUM/HIGH K3 role is Level 3 | #97 (LOW); not #97 (MEDIUM/HIGH) |
| `KEY_STRATEGY.md` §5.1 / §5.2 | K2 is a secondary key for Login and OTP. §5.1 says "Missing fingerprint accelerates K4 escalation only", while `DECISION_MATRIX.md` §2.2 also scores K2 +4 (an existing inconsistency between the two contracts) | K2 secondary in observation only. The contract update must resolve the inconsistency, keeping missing-fingerprint escalation on K4 | #97 |
| `KEY_STRATEGY.md` §5.3 | API Heavy: K2 and K3 primary, K1 secondary | The LOW K3 → K2 persistence ends (#97). K2's role as the API minor-limit rate scope and K1's API role are API protection, not #97: unchanged by this record, and any reshaping is a separate decision | #97 (LOW persistence); not #97 (API rate) |
| `KEY_STRATEGY.md` §6.1 | Canonical correlations | "Rapid churn of K3 under one K2" and LOW "same DeviceFP across many K1" become observation (#97). "Many correlation subjects under one K1" (spray) is Level 2, not #97, unchanged here. "Many K3 under one K4" (distributed account) is Level 2, not #97 | #97 (churn, LOW dilution); not #97 (spray, distributed account) |
| `KEY_STRATEGY.md` §3.1 (IPv6 hierarchy) | Spray and churn observe macro scopes; enforcement persists only the current canonical `/64` K1 or `/64 + UA` K2 | Macro churn persists no K2 (#97). The `/64` K1 spray persistence is Level 2 and unchanged here | #97 (K2); not #97 (K1) |
| `KEY_STRATEGY.md` §4.3.5 | Bounded correlation and rotation state | Still needed for observation; no change proposed | Unchanged |
| `KEY_STRATEGY.md` §4.6 | LOCKED account auxiliary keys | Untouched by Level 1. A revocation epoch counter (Level 3) would need its own amendment | Not #97 |
| `DECISION_MATRIX.md` §1 (evaluation order) and §2.1 (pre-attempt check) | Step 2 resolves active `HARD_BLOCK` on K4/K5/K3/K1/K2; step 4 evaluates correlation | K2 leaves the set of keys that can hold a correlation `HARD_BLOCK` (#97). The K1 row is Level 2, not #97. A pre-verifier admission stage is Condition 1, not part of this record | #97 (K2); not #97 (K1, admission stage) |
| `DECISION_MATRIX.md` §2.2 (Login scoring) | "Missing device fingerprint → K2 +4" | No persisted K2 penalty from a missing fingerprint. "Repeated missing fingerprint → K4 +6" (and the OTP +8) stays on undivided K4 as today, under the non-conformance row for account-wide K4 in Decision 1, until Levels 2 and 3; once the U/P door split exists it applies on the door the failure came through. Where the first missing-fingerprint signal goes is later design | #97 |
| `DECISION_MATRIX.md` §3.1 (OTP scoring) | "OTP failure without device fingerprint → K2 +6" | As §2.2. OTP K4/K5 rules and `DECISION_MATRIX.md` §3.1.2 (DEC-021) unchanged | #97 |
| `DECISION_MATRIX.md` §4.1 (API Heavy) | Minor K2 `SOFT_BLOCK` L1; moderate K3 `HARD_BLOCK` L2, persisted on K2 at LOW; severe K1 `HARD_BLOCK` L3 | The LOW moderate signal persisted on K2 as `HARD_BLOCK` ends (#97). DEC-019 records that S0 low-volume API anti-evasion is stronger than EQV, so a measured API replacement is part of Conditions 3 and 4. The minor K2 limit and the severe K1 limit are not #97 and are unchanged here | #97 (LOW); not #97 (minor, severe) |
| `DECISION_MATRIX.md` §5.0 (WATCH) | N−1 WATCH for spray, churn, dilution | Churn and LOW dilution WATCH become observation (#97). Spray WATCH is Level 2 | #97; not #97 (spray) |
| `DECISION_MATRIX.md` §5.1 (K1 spray) | `HARD_BLOCK (IP)` at five distinct subjects in 600 s; advisory for trusted sessions | **No change proposed.** Any change to K1 spray protection is Level 2 | Not #97 |
| `DECISION_MATRIX.md` §5.2 / §5.2.1 (distributed account), §5.4 (new-device flood) | Population-based K5 / account penalties | Touched by Decision 1: G1 found them to be the same group-attribution error. Their disposition depends on a per-target bound existing first (G1 final disposition §2.2), so it is Level 2. No change proposed here | Not #97 |
| `DECISION_MATRIX.md` §5.3 (churn / dilution) | Churn `HARD_BLOCK (IP + UA)`; LOW dilution downgraded to `HARD_BLOCK (IP + UA)`; MEDIUM/HIGH dilution `HARD_BLOCK (DeviceFP)` after confirmation | Churn and LOW dilution become observation (#97, #100). MEDIUM/HIGH dilution is not #97 and is left to Level 3 | #97; not #97 (MEDIUM/HIGH) |
| `DECISION_MATRIX.md` §2.4, §2.5, §3.3 (budgets, Anti-Equilibrium) | Account budgets; account and device watchers (DEC-021) | Unchanged by Level 1. Any U/P split of account state must state its effect on these sections, DEC-007 and DEC-021 | Unchanged |
| `POLICIES.md` §2, §3, §4 (presets that depend on K2) | `login_protection` and `otp_protection` list K2 and score missing fingerprints on it; `api_heavy_protection` uses K2 for rate enforcement and for LOW K3 persistence | The K2 enforcement uses in these presets change as the matching `DECISION_MATRIX.md` rows above. Custom policies with `CREDENTIAL_SPRAY`, `DISTRIBUTED_ACCOUNT` or `API_OVERUSE` follow the same contracts. The DEC-008 capability model is unchanged | #97 (rows above) |
| `POLICIES.md` §1, §5 | "Correlation rules are enforced …"; DEC-019 interim note | Wording follows the amended correlation contract; the DEC-019 note follows DEC-019's exit | #97 |
| `DEVICE_FINGERPRINT.md` §7.1 / §7.2 | Churn detection is mandatory and MUST trigger increased scoring weight, accelerated escalation and correlation-based enforcement | Detection stays mandatory. Correlation enforcement on IP + UA becomes observation. Increased weight and accelerated escalation never apply to IP + UA. Today they stay on undivided K4 under the non-conformance row for account-wide K4 in Decision 1; once the U/P door split exists they apply to a Subject door or an Actor × Subject relationship | #97 |
| `DEVICE_FINGERPRINT.md` §4.2, §5.1 | Confidence levels; fingerprint-secret rotation | Unchanged by Level 1. A client instance id that never raises confidence, and K5 material without the UA, are Level 3 / Condition 2 | Not #97 (Level 3) |
| `docs/guides/USAGE_GUIDE.md` | States that `/64 + UA` K2 state is persisted and that the API Heavy LOW moderate signal is persisted against K2 | Follows the amended `DECISION_MATRIX.md` §4.1 and §5.3 and `KEY_STRATEGY.md` §3.1 | #97 |
| `FAILURE_SEMANTICS.md` FAIL_OPEN local guardrails | Per-node, per-minute K1 / K2 coarse throttles with no shared state (DEC-011, DEC-015) | Already a non-persisted capacity bound; consistent with Decision 1; unchanged | Unchanged |
| DEC-021 | Known-device K5 bound, allowance, device watcher | Unchanged by Level 1. Condition 2 measures UA-free K5 against these bounds. K5 separation, tying the allowance to the P family, and "a negative score on a bound device is a throttle only" (Level 3) would each amend DEC-021 through their own decision | Not #97 (Level 3) |
| DEC-003, DEC-007, DEC-008, DEC-015 | Cycle pause; K4 lifecycle; capability model; failure semantics | Unchanged. Any new key or door introduced later must state its DEC-003 cycles, DEC-007 lifecycle, DEC-008 capability and DEC-015 failure behavior | Unchanged |

## Conditions to become ACTIVE

These are the remaining steps of the Owner's plan. This record does not assume that any of
them will be approved. A step that is refused or fails its gates leaves DEC-022 open to
`REJECTED` or `DEFERRED` under Decision Governance §5.

1. **Owner decision on the `checkOnly` contract (step 2).** Whether a pre-verifier atomic
   reservation inside `checkOnly` is permitted. This would be a behavior change for the
   policies that enable it, and it needs store integrity: no write before the decision, and
   the previous generation read-only. The decision must also state what is promised to
   `checkOnly`-only Hosts and to record-only Hosts. FULL2 fails gate P for `checkOnly`-only
   Hosts (#132), and record-only Hosts get no pre-verifier bound in any measured design.
   Step 2 was decided on 2026-10-19 by DEC-023 (mode eligibility) and DEC-024 (opt-in T0 reservation).
2. **Measurement of UA-free K5 with DEC-021 parity (step 3).** K5 material without the UA
   when a client instance id is present, and today's derivation without one. It must be
   measured against the DEC-021 known-device bounds. No study has implemented it: G4 and
   G4 v2 kept the UA in the K5 material as a declared deviation (#132 §9).
3. **Owner choice of the budgets (step 4).** Source capacity (`U_src`), failure cap (`F`)
   and per-account guessing budget (`T`, `C`), plus any API and multi-session replacement
   bounds. Each is a Level 2 security decision with its measured cost stated, including
   where the single-IP spray gate is set. This record assumes no value.
   Step 4 was decided on 2026-10-19 by DEC-025 (declared study-value budgets).
4. **Runtime proven and reviewed (step 5).** An implementation of the approved design,
   measured on the DEC-019 Decision 5 list and on the best-response and paid-identifier
   gates of Decision 4, with independent Lead review on the exact HEAD.

Final activation is the Owner's decision. Under Decision Governance §3 and §9, the decision
record (status change, the disposition of DEC-019 and the index), the canonical contract
updates listed above, and the implementation may be delivered atomically in one later
bounded scope, as DEC-021 was in #117.

## Rationale

- **Why attribution, not a new identity.** Every candidate that kept penalties on a shared
  scope kept part or most of the harm (#107, #115, #116) or moved it to the NAT (#125). Every
  candidate that stopped the UA-similarity penalties removed the #97 cohort denials (#125
  and #126 G1: D1 596 → 0). The finding is
  consistent across designs with different identities and parameters, which is why it is
  proposed as a principle and separated from the values.
- **Why best response over free identifiers.** A gate that a free identifier change defeats
  measures the attacker's choice, not the library's protection (#125 F97-01). Excluding
  paid identifiers from "free" keeps the cells that protection actually depends on as gates:
  spray from one IP, multiple valid sessions, own accounts.
- **Why only Level 1 now.** Levels 2 and 3 have open measurements (UA-free K5,
  `checkOnly`-only Hosts) and security costs that only the Owner can accept. Recording them
  here would turn study values into implied policy.

## Consequences

**While `PROPOSED` (now):** no Runtime, contract, preset, threshold or test changes.
DEC-019 and DEC-021 stay `ACTIVE` and unchanged. #97, #100 and #106 stay open. S0 remains
the deliberate interim posture under DEC-019, and its exit follows DEC-019 Decision 6.

**If activated (declared costs of Level 1, independent of Levels 2 and 3):**

- The naive attacker who keeps one UA is no longer stopped by LOW dilution. On S0 that
  stop is X2 6/121, and the best response already reaches 121/121 (#125).
- The bound that K2 churn gives today against an attacker holding valid sessions for many
  accounts goes away unless Level 2 replaces it. On S0: `TMSd` 2/200, `TMS` 7/61 (#113).
  Under modelled relief: 61/61 (#119, `MODEL`). These cells stay gates.
- The low-volume API anti-evasion that K2 gives today goes away unless Level 2 replaces it.
  DEC-019 records S0 as stronger than EQV, where X1 had 61/61. These cells stay gates.
- An owner without proof under a sustained attack is not protected by Level 1. G1 HAR:
  14/15 refused at every parameter set; owner with proof (HARp): 0/15. Only proof or
  binding (Level 3) changes that.

## Evidence

Every number is quoted from the merged study named, at that study's baseline. None is new.
Evidence class:

- `MEASURED`: archived harness output on the real pipeline or a pinned candidate.
- `MODEL`: a simulation without the pipeline.
- `DERIVED`: reasoning, with nothing executed.

Study arm parameters are study values only and are not repeated here.

| PR | Study (folder under `investigations/`) | Evidence used here | Class |
| --- | --- | --- | --- |
| #108 | `k1-credential-spray-nat/` (#106) | D2 office, legitimate denied of 121: S0 117; churn/dilution off 108; K1 spray off 117; both off 27. K1 and K2 mask each other. K1-isolated Nu4: 47/61. K1 off: SP1n 4 → 200, SP3 7 → 1000, SP6 20 → 1200. 8-IP botnet SP4b: 432/432 on S0 | `MEASURED` |
| #111 | `nat-remediation-110/` | OQC family: the per-cycle leak of every candidate arm equals the concurrency of the burst | `MEASURED` |
| #113 | `trusted-multi-session-s0/` | On S0 the shared K2 churn set is what bounds an attacker with valid sessions for 12 accounts: `TMSd` 2/200, `TMS` 7/61. Host-violating `TMX`: 200/200. Record-only: 200/200 | `MEASURED` |
| #115 | `guarded-network-headroom-110/` | GNH5: D1 596 → 584 (partial). A per-scope bound multiplies by the scopes the attacker holds. Rollback does not preserve S0 state. NO-GO | `MEASURED` |
| #116 | `outcome-aware-atomic-admission-110/` | OAAA: L1 (browsers with client fingerprints) 127/134 → 0/134, D1 596 → 579. Successful logins on attacker-owned accounts wash credit: Y6b 2 → 36/36. One all-or-nothing atomic reservation per request is exact under real concurrency. NO-GO | `MEASURED` |
| #118 | `host-managed-client-identity-110/` | Browser id in today's inputs: NAT collateral 90 → 97 of 100 browsers; as a pre-auth trusted session, sprayer 200/200 instead of 7 (H01). On MAIN, 86–88 of 100 office browsers have no successful login on each of 14 days (H02 B1, real pipeline). Idealized standing and pool-term limits (H02 B2–B6) | `MEASURED` (H01, H02 B1); `MODEL` (H02 B2–B6) |
| #119 | `account-scoped-known-device-relief-110/` | Relieving K1/K2 for known devices relieves the attacker equally: `TMS` 7/61 → 61/61, `TMSd` 2/200 → 60/200. NO-GO | `MODEL` |
| #123 | `nat-architecture-proposals-110/` | Eleven designs recorded verbatim with a paper comparison; no measurement | `DERIVED` |
| #125 | `ua-client-identity-97/` | CIR: D1 596/600 → 0/600, L3 104/121 → 0/121, but L1 (two browsers without client fingerprints) 0/134 → 127/134 (NO-GO). F97-01: LOW dilution stops only the constant-UA X2 (6/121); browser-major rotation reaches 121/121. A constant id raises checkOnly-only BRD 22 → 37/100 | `MEASURED` |
| #126 | `k2-identity-admission-97/` (G1) | C10-H: 0 benign denials of K1/K2/K3/correlation origin; D1 596 → 0, D1API 595 → 0, L3 104 → 0. No sampled parameter set beats S0 on both axes. HAR 14/15, HARp 0/15. Distributed-account and flood punishments: observation only (engineering result). Per-target bound load-bearing. Complete candidate NO-GO | `MEASURED` |
| #127 | `k2-identity-admission-97/` (G2) | Proof cases: owner admitted 12/12 with key (A) and link (B); 11/12 refused without a proof path. Short codes NO-GO. Overall NO-GO AFTER EXPERIMENT (E2, device history) | `MEASURED`; `DERIVED` (short codes) |
| #129 | `k2-identity-admission-97/` (G3) | Gross failure cap and per-account bucket: NO-GO AFTER EXPERIMENT as registered. Source midpoint and CGN: reporting only | `MEASURED`; `MODEL` (CGN) |
| #130 | `k2-identity-admission-97/` (G3 v2) | Net failure cap: EXPERIMENTALLY SUPPORTED — LIMITED SCOPE, `pre+out` only. Known-device routing supported only for honest Hosts: a Host marking the attacker as known lets 5,760/5,760 attempts reach the verifier | `MEASURED` |
| #131 | `k2-identity-admission-97/` (G4) | First integrated assembly with binding: NO-GO AFTER EXPERIMENT (A1, C4, E, P). Input deviation F131-01 recorded | `MEASURED` |
| #132 | `k2-identity-admission-97/` (G4 v2) | Part R reproduces G4 with corrected input. FULL2: 13 gates pass for `pre+out` (EXPERIMENTALLY SUPPORTED — LIMITED SCOPE). Gate P fails for `checkOnly`-only Hosts: L1 72, D2 61. E2 identical with and without the population. K5 separation shown necessary. MIX 54 against S0 117. X1 / BRU / BRD 4 / 5 / 5 against S0 2 / 3 / 3. UA kept in K5 material (UA-free K5 not measured). CGNAT C6 | `MEASURED`; `MODEL` (C6) |

Studies merged before #108 (#103–#107: PSC, EQL/EQV, A06) are cited only as DEC-019
records them. PRs #109 (DEC-019) and #117 (DEC-021) are decision records, not evidence. #112, #124 and
#128 are corrections without new evidence. The proposal itself,
`IDENTITY_ATTRIBUTION_PROPOSAL.md` and its Arabic companion, is `PROPOSED` design text, not
evidence, and is unchanged by this record.

## Relationship to Other Decisions

- **DEC-019 and DEC-021:** unchanged, and remain `ACTIVE`. As a `PROPOSED` record, DEC-022
  does not conflict with either. How DEC-019 exits is set out in
  [What this proposes to replace](#what-this-proposes-to-replace).
- **DEC-003, DEC-007, DEC-008, DEC-015:** preserved unchanged.
- **Other `ACTIVE` decisions:** not affected.

## Supersedes

None

## Superseded By

None
