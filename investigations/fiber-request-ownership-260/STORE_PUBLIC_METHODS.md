# #260 — every public method of `RedisFullCapabilityStore`, classified from the source at the delivery base

Generated from `src/Repository/Redis/RedisFullCapabilityStore.php` at the commit named in `raw/source.txt` of the verification run (a mechanical count of `public function` declarations: **79** = the 78 of the baseline plus the new `ownedResources()`; the historical figure of 78 was the starting point and is confirmed).

Guard points (corrected for the review of `c9eb4dd5`, F266-11): every public wrapper that reads the store's own clock (`fallbackNow()` / `fallbackNowMilliseconds()`, 23 wrappers) and `save()` is a `guarded()` whole, so the scope is taken before the clock or the held request state is read and the clock read is a Host crossing; the table below names the guard point of each row. `command()` admits every Redis command and marks the executor as a Host crossing; `eval()` admits before the held request state is read; `guarded()` wraps a whole method where it issues several commands or runs a callback. A direct call with no owner is a short-lived owner for its whole duration; a call from the owner's context with no open crossing is nested; anything else is refused with `OverlappingRequestException`. Only pure local methods are exempt, with the reason stated.

| # | Method | Resources | Local / backend | Structure | Guard point | Exemption |
| ---: | --- | --- | --- | --- | --- | --- |
| 1 | `__construct` | none | local | none | not a guard: binds the configured provider's domain (`bindAll`) when one is passed | constructor, no state of a call is touched |
| 2 | `ownedResources` | this store | local | none | exempt | pure local getter: returns `[$this]`, no backend, no shared mutable state |
| 3 | `increment` | this store | backend | delegates to `incrementAt()` (same structure as the delegate) | its own `guarded()` before the clock is read (the whole wrapper, the clock read is a Host crossing), then the delegate's `eval()` | not exempt |
| 4 | `incrementAt` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 5 | `incrementIfSnapshot` | this store | backend | delegates to `incrementIfSnapshotAt()` (same structure as the delegate) | its own `guarded()` before the clock is read (the whole wrapper, the clock read is a Host crossing), then the delegate's `eval()` | not exempt |
| 6 | `incrementIfSnapshotAt` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 7 | `replaceScore` | this store | backend | delegates to `replaceScoreAt()` (same structure as the delegate) | its own `guarded()` before the clock is read (the whole wrapper, the clock read is a Host crossing), then the delegate's `eval()` | not exempt |
| 8 | `replaceScoreAt` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 9 | `get` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 10 | `set` | this store | backend | delegates to `setAt()` (same structure as the delegate) | its own `guarded()` before the clock is read (the whole wrapper, the clock read is a Host crossing), then the delegate's `eval()` | not exempt |
| 11 | `setAt` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 12 | `block` | this store | backend | delegates to `blockAt()` (same structure as the delegate) | its own `guarded()` before the clock is read (the whole wrapper, the clock read is a Host crossing), then the delegate's `eval()` | not exempt |
| 13 | `blockAt` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 14 | `checkBlock` | this store | backend | delegates to `checkBlockAt()` (same structure as the delegate) | its own `guarded()` before the clock is read (the whole wrapper, the clock read is a Host crossing), then the delegate's `eval()` | not exempt |
| 15 | `checkBlockAt` | this store | backend | single command via `blockState()` | `eval()` | not exempt |
| 16 | `peekBlock` | this store | backend | delegates to `peekBlockAt()` (same structure as the delegate) | its own `guarded()` before the clock is read (the whole wrapper, the clock read is a Host crossing), then the delegate's `eval()` | not exempt |
| 17 | `peekBlockAt` | this store | backend | single command via `blockState()` | `eval()` | not exempt |
| 18 | `getBudget` | this store | backend | delegates to `getBudgetAt()` (same structure as the delegate) | its own `guarded()` before the clock is read (the whole wrapper, the clock read is a Host crossing), then the delegate's `eval()` | not exempt |
| 19 | `getBudgetAt` | this store | backend | single command via `budgetState()` | `eval()` | not exempt |
| 20 | `peekBudget` | this store | backend | delegates to `peekBudgetAt()` (same structure as the delegate) | its own `guarded()` before the clock is read (the whole wrapper, the clock read is a Host crossing), then the delegate's `eval()` | not exempt |
| 21 | `peekBudgetAt` | this store | backend | single command via `budgetState()` | `eval()` | not exempt |
| 22 | `incrementBudget` | this store | backend | delegates to `incrementBudgetAt()` (same structure as the delegate) | its own `guarded()` before the clock is read (the whole wrapper, the clock read is a Host crossing), then the delegate's `eval()` | not exempt |
| 23 | `incrementBudgetAt` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 24 | `incrementBudgetWithSeed` | this store | backend | delegates to `incrementBudgetWithSeedAt()` (same structure as the delegate) | its own `guarded()` before the clock is read (the whole wrapper, the clock read is a Host crossing), then the delegate's `eval()` | not exempt |
| 25 | `incrementBudgetWithSeedAt` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 26 | `addDistinct` | this store | backend | delegates to `addDistinctAt()` (same structure as the delegate) | its own `guarded()` before the clock is read (the whole wrapper, the clock read is a Host crossing), then the delegate's `eval()` | not exempt |
| 27 | `addDistinctAt` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 28 | `incrementWatchFlag` | this store | backend | delegates to `incrementWatchFlagAt()` (same structure as the delegate) | its own `guarded()` before the clock is read (the whole wrapper, the clock read is a Host crossing), then the delegate's `eval()` | not exempt |
| 29 | `incrementWatchFlagAt` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 30 | `getWatchFlag` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 31 | `addDistinctBounded` | this store | backend | delegates to `addDistinctBoundedAt()` (same structure as the delegate) | its own `guarded()` before the clock is read (the whole wrapper, the clock read is a Host crossing), then the delegate's `eval()` | not exempt |
| 32 | `addDistinctBoundedAt` | this store | backend | delegates to `addDistinctBoundedWithSnapshotAt()` (same structure as the delegate) | the delegate's `eval()` | not exempt |
| 33 | `addDistinctBoundedWithSnapshot` | this store | backend | delegates to `addDistinctBoundedWithSnapshotAt()` (same structure as the delegate) | its own `guarded()` before the clock is read (the whole wrapper, the clock read is a Host crossing), then the delegate's `eval()` | not exempt |
| 34 | `addDistinctBoundedWithSnapshotAt` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 35 | `addDeviceCapPairAt` | this store | backend | plan read then `commitBoundedObservationGroup` (whole-method wrapper) | through `commitBoundedObservationGroup`; the plan read is nested in it | not exempt |
| 36 | `prepareDeviceCapPairAt` | this store | backend | plan read (one command) and a closure whose later call is a separate `commitDeviceCapPlans` | `eval()` at the read; the deferred commit is admitted on its own when the closure is called | not exempt; the read and the deferred commit are two independent backend calls, as before |
| 37 | `readDeviceCapPairPlanAt` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 38 | `readBoundedObservationPlanAt` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 39 | `readWatchObservationPlanAt` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 40 | `commitBoundedObservationGroup` | this store | backend | callback plus up to 3 commit commands | whole-method `guarded('commitBoundedObservationGroup')` | not exempt |
| 41 | `commitDeviceCapPlans` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 42 | `addDistinctAcrossRotation` | this store | backend | delegates to `addDistinctAcrossRotationAtMilliseconds()` (same structure as the delegate) | its own `guarded()` before the clock is read (the whole wrapper, the clock read is a Host crossing), then the delegate's `eval()` | not exempt |
| 43 | `addDistinctAcrossRotationAt` | this store | backend | delegates to `addDistinctAcrossRotationAtMilliseconds()` (same structure as the delegate) | the delegate's `eval()` | not exempt |
| 44 | `addDistinctAcrossRotationAtMilliseconds` | this store | backend | delegates to `addDistinctBoundedWithSnapshotAcrossRotationAtMilliseconds()` (same structure as the delegate) | the delegate's `eval()` | not exempt |
| 45 | `addDistinctBoundedAcrossRotation` | this store | backend | delegates to `addDistinctBoundedAcrossRotationAtMilliseconds()` (same structure as the delegate) | its own `guarded()` before the clock is read (the whole wrapper, the clock read is a Host crossing), then the delegate's `eval()` | not exempt |
| 46 | `addDistinctBoundedAcrossRotationAt` | this store | backend | delegates to `addDistinctBoundedAcrossRotationAtMilliseconds()` (same structure as the delegate) | the delegate's `eval()` | not exempt |
| 47 | `addDistinctBoundedAcrossRotationAtMilliseconds` | this store | backend | delegates to `addDistinctBoundedWithSnapshotAcrossRotationAtMilliseconds()` (same structure as the delegate) | the delegate's `eval()` | not exempt |
| 48 | `addDistinctBoundedWithSnapshotAcrossRotation` | this store | backend | delegates to `addDistinctBoundedWithSnapshotAcrossRotationAtMilliseconds()` (same structure as the delegate) | its own `guarded()` before the clock is read (the whole wrapper, the clock read is a Host crossing), then the delegate's `eval()` | not exempt |
| 49 | `addDistinctBoundedWithSnapshotAcrossRotationAt` | this store | backend | delegates to `addDistinctBoundedWithSnapshotAcrossRotationAtMilliseconds()` (same structure as the delegate) | the delegate's `eval()` | not exempt |
| 50 | `addDistinctBoundedWithSnapshotAcrossRotationAtMilliseconds` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 51 | `incrementWatchFlagAcrossRotation` | this store | backend | delegates to `incrementWatchFlagAcrossRotationAt()` (same structure as the delegate) | its own `guarded()` before the clock is read (the whole wrapper, the clock read is a Host crossing), then the delegate's `eval()` | not exempt |
| 52 | `incrementWatchFlagAcrossRotationAt` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 53 | `load` | this store | backend | delegates to `loadWithVersion()` (same structure as the delegate) | the delegate's `eval()` | not exempt |
| 54 | `loadWithVersion` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 55 | `saveIfVersion` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 56 | `save` | this store | backend | single command (an `EVAL` held in an open request, or one `SET` otherwise) | its own `guarded()` before the held state is read, then `eval()` / `command()` | not exempt |
| 57 | `acquireProbeLease` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 58 | `blockWithCycleTracking` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 59 | `readDecayPauseState` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 60 | `readGenerationBoundScoreState` | this store | backend | delegates to `readGenerationBoundScoreStateAtMilliseconds()` (same structure as the delegate) | its own `guarded()` before the clock is read (the whole wrapper, the clock read is a Host crossing), then the delegate's `eval()` | not exempt |
| 61 | `readGenerationBoundScoreStateAt` | this store | backend | delegates to `readGenerationBoundScoreStateAtMilliseconds()` (same structure as the delegate) | the delegate's `eval()` | not exempt |
| 62 | `readGenerationBoundScoreStateAtMilliseconds` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 63 | `mutateGenerationBoundScore` | this store | backend | delegates to `mutateGenerationBoundScoreAtMilliseconds()` (same structure as the delegate) | its own `guarded()` before the clock is read (the whole wrapper, the clock read is a Host crossing), then the delegate's `eval()` | not exempt |
| 64 | `mutateGenerationBoundScoreAt` | this store | backend | delegates to `mutateGenerationBoundScoreAtMilliseconds()` (same structure as the delegate) | the delegate's `eval()` | not exempt |
| 65 | `mutateGenerationBoundScoreAtMilliseconds` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 66 | `blockWithPunishmentLifecycleTracking` | this store | backend | delegates to `blockWithPunishmentLifecycleTrackingAt()` (same structure as the delegate) | its own `guarded()` before the clock is read (the whole wrapper, the clock read is a Host crossing), then the delegate's `eval()` | not exempt |
| 67 | `blockWithPunishmentLifecycleTrackingAt` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 68 | `claimPostPunishmentReentry` | this store | backend | delegates to `claimPostPunishmentReentryAtMilliseconds()` (same structure as the delegate) | its own `guarded()` before the clock is read (the whole wrapper, the clock read is a Host crossing), then the delegate's `eval()` | not exempt |
| 69 | `claimPostPunishmentReentryAt` | this store | backend | delegates to `claimPostPunishmentReentryAtMilliseconds()` (same structure as the delegate) | the delegate's `eval()` | not exempt |
| 70 | `claimPostPunishmentReentryAtMilliseconds` | this store | backend | single command (one `eval`) | `eval()` then `command()` (nested) | not exempt |
| 71 | `reserveAdmission` | this store | backend | single command through the admission operations (one `eval` per method) | `guarded('admission')` on the admission executor, then `command()` (nested) | not exempt |
| 72 | `applyAdmissionOutcome` | this store | backend | single command through the admission operations (one `eval` per method) | `guarded('admission')` on the admission executor, then `command()` (nested) | not exempt |
| 73 | `rebindAdmissionConfig` | this store | backend | single command through the admission operations (one `eval` per method) | `guarded('admission')` on the admission executor, then `command()` (nested) | not exempt |
| 74 | `readBindingEpoch` | this store | backend | single command through the admission operations (one `eval` per method) | `guarded('admission')` on the admission executor, then `command()` (nested) | not exempt |
| 75 | `bumpBindingEpoch` | this store | backend | single command through the admission operations (one `eval` per method) | `guarded('admission')` on the admission executor, then `command()` (nested) | not exempt |
| 76 | `claimAdmissionPermit` | this store | backend | single command through the admission operations (one `eval` per method) | `guarded('admission')` on the admission executor, then `command()` (nested) | not exempt |
| 77 | `claimAdmissionPermitWithOutcome` | this store | backend | single command, its own atomic command (#258, DEC-036), never held in a request overlay | `command()` through the outcome executor | not exempt: the claim stays its own atomic command, its caller is admitted |
| 78 | `isHealthy` | this store | backend | single command (PING) | `command()` | not exempt: the health check is admitted like any backend operation |
| 79 | `transactRequest` | this store | backend | whole method (callback, up to 32 evaluations, one commit) | whole-method `guarded('transactRequest')`, before the callback and before `requestState` is inspected | not exempt |
