# DEC-035 — Sliding Admission Permit Timing and the Package Constants of the Admission Runtime (replaces DEC-028)

## Decision ID

`DEC-035`

## Status

`PROPOSED` — proposed in PR-Q (#185) to supersede DEC-028 under Decision Governance Standard §9.
Activation on Owner approval on the PR, followed by activation commit.

## Date

2026-11-10

## Decision Authority

**Owner.** Reopening and supersession of DEC-028 pursuant to Decision Governance Standard §9, authorized under LT-185-33 ([#185 comment 6772966460](https://github.com/Maatify/php-rate-limiter/issues/185#issuecomment-6772966460)).

DEC-028's original authority and history:
Owner decision of 2026-10-20 (OD-8 (b)) in [PR #150 comment 6299051848](https://github.com/Maatify/php-rate-limiter/pull/150#issuecomment-6299051848), delivered atomically with DEC-026. Lead ruling LT-32 on the single holder of the values in [PR #150 comment 6298046892](https://github.com/Maatify/php-rate-limiter/pull/150#issuecomment-6298046892).

Sliding window authority: Owner decision LT-185-30 (b) ([#185 comment 6675589019](https://github.com/Maatify/php-rate-limiter/issues/185#issuecomment-6675589019)).

## Scope / Concern

The values the admission runtime applies when `RateLimiterBuilder::build()` constructs it for a policy that declares `ADMISSION_RESERVATION`: the permit deadline `D`, the permit grace `G`, and the DEC-034 budgets, as package constants operating under continuous sliding window semantics.

## Context

The required permit (OD-1 (b); RIC = `investigations/k2-identity-admission-97/RUNTIME_IMPLEMENTATION_CONTRACT.md`, §3) needs a deadline `D` (`D ≤ W`, security feasibility §2.3) and a grace `G`. Both were study values: `g1/scenarios/m05_host_modes.php:55–56` (`$D = 30`, `$G = 600`), registered in `5b3b/PLAN.md` §4 and accepted by the Lead (paths under `investigations/k2-identity-admission-97/`); they are not in DEC-025 / DEC-034. RIC §0.1 makes every admission value a package constant, not Host-configurable, and says that a different value is a new decision. Under DEC-033, windowed and daily admission scopes operate as continuous sliding windows (`ZSET`).

## Decision

1. **Permit timing.** `D` = 30 s, `G` = 600 s. An outcome is claimed against its permit until `t0 + D`; in `[t0 + D, t0 + D + G)` the claim is `permit_late`, after it `permit_unknown`; neither scores. The permit record lives `D + G` = 630 s.
2. **Package constants.** The values the builder gives the admission runtime are, field by field: sliding window `W` 600 s; `U_src` 24; net failure cap `F` 4; account bucket `C` 4 over `T` 1800 s; `P_acct` 10 per sliding window `W` and 60 per sliding day `DAY` (86400 s); `P_proof` 6 per sliding window `W` and 30 per sliding day `DAY`; `N_P` 10 per sliding day; the cross-account proven-door failure cap 4 per source and sliding window `W` (DEC-034 decisions 1.4, 2.2, 3.1, 8 as 8.2; RIC §0.1); and `D`, `G` above. All windowed and daily scopes operate as continuous sliding windows (DEC-033). From this record's activation they are the production values of DEC-034.
3. **Not Host-configurable.** One package-owned `@internal` factory holds them (`AdmissionBudgetsDTO::packageDefaults()`, LT-32); neither `RateLimiterConfig` nor the builder has an input for any of them.
4. **Change.** A different value for any of them is a new decision: for `D` or `G` a record that supersedes this one, for a DEC-034 value a record that supersedes DEC-034 (its own rule), together with a registered measurement.

## Rationale

- **Why 30 / 600.** They are the only values measured: NM-10 and NM-5 ran with them on the production code (`5b3b/RESULTS.md`). `D` 30 ≤ `W` 600, which `AdmissionBudgetsDTO` enforces.
- **Why a separate record.** OD-2 accepted DEC-026 as written, and its concern is the verifier bound, not permit timing; amending DEC-034 would supersede all nine of its points for two values outside its scope (Decision Governance §4, §9).
- **Why constants.** The DEC-015 precedent: a bound that protects every Host is package-owned (the 4096-subject fallback bound).
- **Why continuous sliding windows:** As decided under LT-185-30 (b) and DEC-033, sliding windows prevent boundary double-bursting across both short (600 s) and daily (86400 s) horizons.

## Consequences

- **Late outcomes are dropped outcomes.** A Host whose verifier answer arrives more than 30 s after `checkOnly` loses the scoring of that attempt. The cost of drops on the production code (NM-10, `5b3b/RESULTS.md`): share 0.10, A1 and A3 hold; 0.25, A1 `L1 7`; 0.50, `L1 31`; 1.00, the `pre` values (L1 72, ABA_B 94, D2 61, SP6 144).
- **State.** One permit record per admission for 630 s.
- **No Host tuning.** A Host that needs other values has no supported path until a new decision.
- **Supersedes DEC-028:** Replaces DEC-028 upon activation under Decision Governance Standard §9.

## Relationship to Other Decisions

- **Supersedes DEC-028** in full.
- **DEC-034:** Its declared values become the package constants here.
- **DEC-033:** Operates the sliding window mechanism and storage structure for the constants governed here.
- **DEC-026:** Activated with DEC-028 historically.
- **DEC-024:** The reservation this timing serves.
- **DEC-023:** `pre+out` only.
- **DEC-015:** Unchanged (no admission in degraded mode).

## Canonical Contract / Current Owner

`AdmissionBudgetsDTO::packageDefaults()`, `RateLimiterBuilder` (the builder line), `docs/guides/USAGE_GUIDE.md` (the permit timing as a Host duty), `docs/KEY_STRATEGY.md` §4.8 (permit record lifetime).

## Supersedes

`DEC-028`

## Superseded By

None
