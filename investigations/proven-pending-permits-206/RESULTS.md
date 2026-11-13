# Pending-permit limit on the proven lane (#206)

Code under test: commit `0a1c72e` (`feat(admission): optional pending-permit limit per source on the proven lane (#206)`). The full suite
(2,383 tests, 74,184 assertions) and the measurements below ran on that tree, on isolated Redis instances on their own ports
(8.10.0), PHP 8.5.9. `eb1395d` was not reachable from the remote, so it was not used as a reference; the implementation follows the
current contracts.

## What it does

`RateLimiterBuilder::withProvenPendingPermitLimit(int $limit)` bounds the proven-lane permits one source holds at once. A permit holds
one slot from its reservation until its outcome is claimed (success or failure) or its deadline `D` (30 s) passes. A request over the
limit is refused with the cause `source_pending_permits` and writes nothing.

- **Independent of recorded failures.** The slot is a counter (`ppend`, member = permit id), not a failure set. A refusal by it never
  enters `pfail`; a failure outcome releases the slot and enters `pfail` as before.
- **Release once.** The permit claim is one-shot: a replay returns `permit_duplicate` (or `permit_conflict` when the outcome flips) and
  applies nothing, so it frees no second slot.
- **No option, no limit.** Without the call the lane is bounded only by the recorded-failure cap and the per-account and per-proof
  counters, as before. The package ships no default.

## Evidence (public Redis tests, `AdmissionProvenPendingPermitRedisTest`)

| Claim | Test | Result |
|---|---|---|
| Pending permits never exceed the limit under parallel independent accounts, credentials and proofs, and the surplus is refused by the limit itself | `testLimitHoldsUnderParallelProvenChecksAndNamesItsOwnCause` | 40 workers, limit 5: 5 admitted, 35 refused with `source_pending_permits`; 5 live pending members |
| No option keeps the historical behaviour | `testNoLimitConfiguredKeepsTheHistoricalUnboundedBehaviour` | 40 of 40 admitted, 0 pending state written |
| Success and failure each free a slot; a replay frees none | `testSuccessAndFailureReleaseASlotAndAReplayReleasesNoSecondSlot` | pass |
| Expiry frees slots with no outcome; a late outcome frees nothing | `testDeadlineExpiryFreesTheSlotsWithoutAnOutcome` | held at deadline − 1 s, free at the deadline |
| A full source does not affect another; refusals do not consume the failure cap | `testSourcesDoNotShareSlotsAndTheLimitIsIndependentOfFailureCounters` | pass |

The release test fails when the failure release is removed from the Redis scripts (checked by mutation).

## User cost (`measure.php`, same commit)

Model: users behind one source, each with its own account, credential and proof. A user makes one attempt, the verifier takes 100 ms,
then the success is recorded with the permit. Arrivals are spread at random over J ms. Three repetitions per cell, admitted of users.

| users | J ms | limit 1 | limit 2 | limit 4 | limit 8 | limit 16 | limit 32 | none |
|---|---|---|---|---|---|---|---|---|
| 8 | 0 | 1/1/1 | 2/2/2 | 4/4/4 | 8/8/8 | 8/8/8 | 8/8/8 | 8/8/8 |
| 8 | 400 | 2/2/3 | 6/6/5 | 8/8/8 | 8/8/8 | 8/8/8 | 8/8/8 | 8/8/8 |
| 16 | 0 | 1/1/1 | 2/2/2 | 4/4/4 | 8/8/8 | 16/16/16 | 16/16/16 | 16/16/16 |
| 16 | 400 | 3/3/3 | 5/6/6 | 11/12/9 | 16/16/16 | 16/16/16 | 16/16/16 | 16/16/16 |
| 32 | 400 | 4/4/4 | 7/7/8 | 14/14/12 | 25/22/24 | 32/32/32 | 32/32/32 | 32/32/32 |
| 64 | 400 | 4/4/4 | 8/7/8 | 16/16/13 | 27/28/27 | 50/48/53 | 64/64/64 | 64/64/64 |

Every refusal in these runs carried `source_pending_permits`. In a simultaneous burst (J 0) admitted equals `min(users, limit)`
exactly. With spread arrivals, legitimate refusals appear when the limit is below the overlap of verifications: about 80 arrivals/s with a
100 ms verifier (32 users over 400 ms) is an average overlap near 8 and refuses 7 to 10 users at limit 8; about 160/s (64 users) needs
limit 32 to refuse none.

### A flooded source and another source

64 independent proven credentials with no outcomes on source A, then 16 legitimate users on source B:

| limit | A admitted | A refused | B admitted of 16 | B refused |
|---|---|---|---|---|
| 1 | 1 | 63 | 4 | 12 |
| 2 | 2 | 62 | 6 | 10 |
| 4 | 4 | 60 | 12 | 4 |
| 8 | 8 | 56 | 16 | 0 |
| 16 | 16 | 48 | 16 | 0 |
| 32 | 32 | 32 | 16 | 0 |
| none | 64 | 0 | 16 | 0 |

B's refusals at limits 1 to 4 are B's own limit, not A's: the same 16 users alone (table above, 16 users, J 400) were admitted 3, 5 to 6
and 9 to 12 at those limits. The deterministic isolation check is the PHPUnit test above (source A full, source B admits its own limit).

## Trade-off

The limit is a ceiling on simultaneous verifications per source. Too low, and legitimate users behind a shared source (an office or a
carrier-grade NAT) are refused while others verify: at 32 users the cost is 7 to 10 refusals at limit 8 and none at 16. Too high, or unset,
and one source can hold as many independent proven reservations as the other gates allow (64 of 64 here; 60 of 60 in the historical run). A
limit needs to sit above the legitimate peak overlap of the busiest source (arrival rate × verifier latency) and below the number of open
attempts the Host accepts from one source. No default is proposed here: the right value depends on the Host's verifier latency and its
largest shared source, and the table gives the Lead the curve to choose from.

## Limits of this evidence

- One attempt per user, no retry after a refusal; the 100 ms verifier time and the arrival spread are a model, not a Host measurement.
- The clock is fixed inside the measurement, so expiry is covered only by the PHPUnit test, not by `measure.php`.
- Process start-up and scheduling add noise: repetitions differ by a few users at the same cell.
- Key-secret rotation: a permit reserved before the rotation holds its slot under the previous secret's key. The first version released only under the current key, so such a slot stayed held until the 30 s deadline; fixed in the follow-up commit named in #207 (the outcome now also releases under the previous key), with a public Redis test for success, failure and replay.
- The U lane has no equivalent limit; this change does not touch it.
- DEC-028 states that no Host input sets the admission budgets. The option is a Host-set bound: the locked-surface tests were changed for the added methods, and the relationship is drafted in DEC-038 (`PROPOSED`). DEC-034 and DEC-035 remain `PROPOSED`.
- Workers that predate the shared-configuration check carry no value and are not checked.
