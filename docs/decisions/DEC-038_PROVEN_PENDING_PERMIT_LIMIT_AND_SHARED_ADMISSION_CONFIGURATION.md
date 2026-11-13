# DEC-038 — Optional Proven-Lane Pending-Permit Limit and Shared Admission Configuration

## Decision ID

`DEC-038`

## Status

`PROPOSED` — drafted in PR [#207](https://github.com/Maatify/php-rate-limiter/pull/207) (#206) for review. Activation needs an explicit Owner authorisation on the PR and an activation commit. Nothing in this record is active.

## Date

2026-11-13

## Decision Authority / Deciders

**Owner**, direction of the design for review, relayed in the [Lead's draft on #207](https://github.com/Maatify/php-rate-limiter/pull/207#pullrequestreview-5891142786) (review of `e06105c`: [CHANGES REQUIRED](https://github.com/Maatify/php-rate-limiter/pull/207#pullrequestreview-5891081272)). Scope split: [#185 comment 6805835020](https://github.com/Maatify/php-rate-limiter/issues/185#issuecomment-6805835020).

## Scope / Concern

An optional, Host-set bound on the permits one source holds at once on the proven lane (P) of a budget group, and the rule that every worker sharing one Redis holds one effective value of it, "no limit" included.

## Context

DEC-036 makes the outcome claim atomic and DEC-033 bounds recorded failures; neither bounds the permits that are reserved but not yet claimed. A source with many accounts and proofs can hold as many proven reservations at once as the other gates allow (60 of 60 in the Lead's historical run; 64 of 64 in `investigations/proven-pending-permits-206/RESULTS.md`). DEC-028 makes the admission budgets package constants with no Host input. A Host-set limit is therefore an exception to DEC-028, and a limit that differs between workers is a silent inconsistency: the stricter worker refuses what the looser one admits.

## Decision

1. **The limit.** `RateLimiterBuilder::withProvenPendingPermitLimit(int $limit)`, a positive integer: the largest number of pending permits one source (the admission source prefix) holds in a budget group on lane P. A permit is pending from its reservation until its outcome is claimed (success or failure) or its deadline `D` passes. A reservation over the limit is refused with the cause `source_pending_permits` and writes nothing.
2. **No default.** The package supplies no value. **Not calling the method means the option is disabled**: the lane is bounded as before (recorded-failure cap, per-account and per-proof counters).
3. **Independent of failure counters.** The slot is its own counter (member = permit id, lifetime `D`). It never enters, and is never read by, the failure sets; a failure outcome frees the slot and enters `pfail` as DEC-033 says.
4. **Release.** The slot is freed by the accepted outcome of either kind inside the atomic claim of DEC-036, once: a repeated outcome of the same permit is `permit_duplicate` or `permit_conflict` and applies nothing. After a key-secret rotation the outcome frees the slot under the previous secret's key as well, so a permit reserved before the rotation frees its slot after it. Expiry of the permit frees it without an outcome. Request preservation stays as DEC-037 states.
5. **One effective value per budget group.** All workers on one Redis hold the same effective value, `off` included. Every reservation, on either lane, carries the worker's value; the store compares it with the registered one in the same script, before any gate and before any write, and raises `AdmissionConfigurationException` when they differ. The first accepted reservation of a group registers its value. The registration is read under the current key secret, else the previous one, so a key rotation keeps it.
6. **Workers never change the shared value.** A mismatched worker fails; it does not overwrite. The value changes only through the explicit operator call `AdmissionSharedConfigControlInterface::rebindAdmissionSharedConfig($budgetGroup)`, which registers the calling worker's value.
7. **A store that cannot hold it.** `build()` refuses a limit on a store that does not implement `SharedAdmissionConfigStoreInterface` (the consistency of the workers could not be proven). A disabled worker on such a store is not checked.
8. **Relationship to DEC-028.** The limit is the only admission bound a Host may set; every other budget and the permit timing stay package constants. If the Owner rules that this changes DEC-028's scope, the relationship is settled under Decision Governance Standard §9 before activation.

## Rationale

- **Why optional and unset by default:** the right value depends on the Host's verifier latency and its largest shared source (the measurements give the curve); a package default would be a guess that refuses legitimate users or does nothing.
- **Why the store compares:** a check in the store is atomic with the reservation, adds no round trip, and cannot be skipped by a worker that forgot to check.
- **Why fail rather than adopt the stricter or the looser value:** either choice silently changes another worker's enforcement. A loud error is recoverable; a silent divergence is not.
- **Why a separate counter:** counting recorded failures to bound pending permits would couple two different quantities (DEC-033's cap counts outcomes, not permits).

## Consequences

- **Changing the value (coordinated transition).** Workers are not meant to run two values at once. Every change follows the same steps; what each one guarantees differs and is stated per transition below.
  1. **Pause.** Stop new reservations of the budget group on every worker (the Host's own maintenance or routing switch; the package has no pause call, and closing the fence is not one). Note `T_pause`, the latest moment any worker still accepted a reservation.
  2. **Isolate.** Remove from rotation, or stop, every worker that can pass the check: a worker on a version that predates this record carries no value and is not compared, so it would keep reserving under the old value or under `off`. A current worker holding another value is not a risk (it fails with `AdmissionConfigurationException`) but is replaced as part of the roll.
  3. **Wait for the permits issued before the pause.** Each ends when its outcome is claimed or at its deadline, so the last one ends by `T_pause + D` (D = 30 s; add the clock skew between workers). Nothing in the store reports it directly, and nothing needs to: a permit past its deadline is `permit_late` and applies nothing, so it neither holds nor frees a slot. The operator knows the permits issued during `off` have ended when `T_pause + D` (plus skew) has passed or every one of them has been claimed.
  4. **Rebind.** From one worker that carries the agreed value (every worker of the group already deployed with it), call `rebindAdmissionSharedConfig($budgetGroup)`.
  5. **Resume.** Allow reservations again.
  - **`off` to an effective limit.** Permits issued while `off` hold no slot, so step 3 is required. When steps 1 to 3 have completed before the rebind, the first reservation after the resume is counted, the real pending count of the group is bounded by the limit from then on, and the surplus is refused with `source_pending_permits`. Rebinding before step 3 has ended gives no such guarantee: the real count can exceed the limit by the permits still outstanding from `off`.
  - **One effective limit to another.** Both count the same counter, so step 3 is not needed for counting. Raising the limit makes room at once. Lowering it revokes no permit: the existing permits stay, the pending count may remain above the new limit, and new reservations are refused until the count falls below it (permits leave by outcome or at their 30 s deadline). No guarantee that the count is under the new limit is made at the resume.
  - **An effective limit to `off`.** Disabling stops enforcing the limit: the counter is ignored and permits reserved while `off` hold no slot.
- **State.** One counter per source in the group (lifetime `D`) and one registered value per group (no expiry, like the epoch and fence keys).
- **Operational.** A worker with a different value raises `AdmissionConfigurationException` (an `RateLimiterException`, not a refusal, never a `BackendFailureException`, never a fallback). The budget group's requests on that worker fail until it is corrected.
- **Not decided here:** a default value, a limit for lane U, a per-account limit.

## Evidence

| Source | Used for | Class |
| --- | --- | --- |
| `tests/Integration/Store/AdmissionProvenPendingPermitRedisTest.php` | the limit under parallel workers, release on success, failure, expiry and after a key rotation, replay frees nothing, source isolation | `MEASURED` |
| `tests/Integration/Store/AdmissionSharedConfigRedisTest.php` | two identical workers share one limit; different values and enabled/disabled fail before reserving; rebind; existing permits; rotation; the transition from `off` (permits issued while off, settled or expired, then rebind and resume: the first reservation counts, the surplus is refused, sources isolated, a stale worker fails) and the same rebind without the wait (off permits stay uncounted) | `MEASURED` |
| `investigations/proven-pending-permits-206/RESULTS.md` | the cost to legitimate users per limit and the isolation of sources | `MEASURED` |

## Relationship to Other Decisions

- **DEC-028:** exception for one Host-set bound; see Decision 8. Not superseded.
- **DEC-033:** unchanged; the failure caps and the sliding windows are independent of this counter.
- **DEC-034, DEC-035:** `PROPOSED`, unchanged by this record.
- **DEC-036:** the atomic outcome claim that frees the slot.
- **DEC-037:** request preservation, unchanged.
- **DEC-015:** unchanged (no admission in degraded mode).

## Canonical Contract / Current Owner

`RateLimiterBuilder::withProvenPendingPermitLimit()`, `AdmissionBudgetsDTO::$provenPendingPermitCap`, `AdmissionRuntime` (scope and release), `RedisAdmissionOperations` (comparison and registration), `AdmissionSharedConfigControlInterface`, `docs/guides/USAGE_GUIDE.md`.

## Supersedes

None

## Superseded By

None
