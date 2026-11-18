# Coverage: requirements, CE-1..CE-32 and the mutation controls (#260, DEC-041)

Files: `F` = `tests/Integration/Redis/FiberRequestOwnershipRedisTest.php` (24 tests),
`P` = `tests/Integration/Redis/ExecutionContextProviderRedisTest.php` (7),
`R` = `tests/Unit/Service/OwnershipRegistryTest.php` (22). Redis-backed tests run on a real isolated Redis.
Test names are abbreviated by their leading words; the exact names are the `test*` methods of those files.

| Case | What is asserted | Test(s) |
|---|---|---|
| 14 original | overlap refused at entry; holder rejected, refused call never answered; either order; Host clock not shared; backend failure not crossed; shared store across runtimes; independent runtimes unaffected; recovery signal only after save; sequential saves; nested one save and outer clock | F `testAnIndependentCallIsRefused…`, `…CanBeRejected…`, `testWhicheverCallStartsSecond…`, `…HostClockReadingOfTheCallHolding…`, `testABackendFailureIsNeverCrossed…`, `testACallOnAnotherRuntimeSharing…`, `…AfterTheCallInFlightEnds…`, `testRuntimesWithTheirOwnStore…`, `testARecoveryTransitionIsAnnounced…`, `testSequentialCalls…`, `testANestedRequestInsideOneRequest…` |
| CE-1 | nested capability runs, other context refused | F `…CallbackSuspendedInsideADirectTransaction`; R `testNestedOperationsFromTheOwnersContext…` |
| CE-2 | no-write direct transaction refused while owner is in the Host clock | F `testADirectTransactionWithoutWrites…` |
| CE-3 | commit window | F `testACallArrivingInTheCommitWindow…` |
| CE-4 | direct capability suspended in executor | F `…DirectCapabilityIsSuspendedInTheExecutor` |
| CE-5 | claim and health check refused for unrelated caller | F `testAnOutcomeClaimAndAHealthCheck…` |
| CE-6 | partial sharing (correlation or circuit store only) | F `testGraphsThatShareOnlyTheCorrelationStore…` |
| CE-7 / CE-26 | all-or-none acquisition, clears only what it took | R `testARefusedAcquisitionClearsOnlyWhatItTook…` |
| CE-8 | aliased resource acquired once | R `testAnAliasedResourceIsAcquiredOnce…` |
| CE-9 | decorated aliasing: limit characterization | R `testDecoratedAliasingOfAStore…` |
| CE-10 | non-Fiber scheduler with and without provider | P `testAProviderSeparatesTheContexts…`, `testWithoutAProviderTheContexts…` |
| CE-11 | same-stack executor and emitter re-entry | F `testACallMadeFromInsideTheExecutor…`, `…SignalEmitter…` |
| CE-12 | same context nested, other refused | R `testNestedOperationsFromTheOwnersContext…`, `testADirectOperationWithNoOwner…` |
| CE-13 | exception not retried, not a backend failure | F `testAnOverlapRefusalInsideATransactionIsNotRetried…` |
| CE-14 | mutation controls | `mutants.sh` M01..M12 (see RESULTS) |
| CE-15 | destroyed suspended Fiber releases | F `testADestroyedSuspendedFiberReleases…`; R `testADestroyedSuspendedFiberUnwinds…`, `testAReleaseClearsASlotOnlyWhere…` |
| CE-16 / CE-24 | nothing retained after call | R `testNothingHoldsAResourceAlive…` |
| CE-18 | direct call on a graph-bound store uses its provider | P `testAGraphInheritsTheProviderOfItsStore…` |
| CE-19 | mixed domains refused at binding | R `testMixedDomainsAreRefused…`, `testASameProviderInstance…`; P (same test as CE-18) |
| CE-20 | equal keys of other live contexts | R `testAKeyOfAnotherLiveContext…` |
| CE-21 | constructor path, no reconfiguration | P `testAProviderPassedToTheStoreConstructor…` |
| CE-22 | provider re-enters, yields, throws | R `testAProviderThatReenters…`, `…Throws…`, `…Yields…` |
| CE-23 | key reuse, contract violation | R `testAProviderThatBreaksItsContract…` |
| CE-25 | readers' resource set; consume/readers/admission refused | F `testConsumeTheOperationalReaders…` |
| CE-27 | lookup cleanup order, stale record | R `testClearingWithAStaleRecord…`, `testAReleaseClearsASlotOnly…` |
| CE-28 | partial binding, failed build rollback | R `testARollbackUndoesExactly…`, `testAnEntryAdoptsTheDomain…`; P `testAFailedBuildBindsNothing…` |
| CE-29 | provider retained by the store; vacated binding fails fast | P `…RetainedWithoutAHostVariable`, `…AfterEveryGraph…Destroyed…`; R `testAVacatedBindingIsNeverFilled…` |
| CE-30 | constructor surface | R `testOnlyTheStoreConstructorGains…` |
| CE-31 | active inherited-provider call keeps its provider | P `testAnActiveInheritedProviderCall…`; R `testAnActiveScopeKeepsItsProvider…` |
| CE-32 | replacement ineligible while owner/reservation/lookup | R `testAReplacementIsRejectedWhile…` |
| CE-17 | #258/#259/DEC-037 preserved | the existing `AdmissionOutcomeDuringRecovery…`, `LocalFallbackRecoveryAccounting…`, `RequestAtomicityBoundaries…` suites in the full-suite run |

Public methods of `RedisFullCapabilityStore`: `STORE_PUBLIC_METHODS.md` (79 rows, each classified).

Declared gaps (not covered, by design or by limit): decorated aliasing is unsupported; a suspended call never destroyed keeps ownership; a non-Fiber scheduler without a provider is covered only inside guarded crossings; a custom non-package store is protected at graph entries only.

## Review corrections of `c9eb4dd5` (F266-10..14)

Test files `tests/Integration/Redis/OwnershipCrossingsRedisTest.php` (8 tests), `tests/Unit/Service/HostStoreOwnershipTest.php` (4) and three tests in `OwnershipRegistryTest`. Each fails on the reviewed source `c9eb4dd5` on both widths and passes on the corrected head (see `RESULTS.md`).

| Finding | Test |
|---|---|
| F266-10 policy getters | `testASimpleThrottlePolicyThatReentersTheStore…`, `testAScorePolicyThatReentersTheStoreFromAGetter…` (control: the ordinary policy runs, the next call is served) |
| F266-11 store clock before the guard | `testAnIncrementOverAnOwnedStoreIsRefusedBeforeItsClockIsRead` (0 clock reads on refusal), `testACallIsRefusedWhileAnotherIsSuspendedInsideTheStoresOwnClock`, `testTheStoreClockOfTheSecondsAndOfTheMillisecondsPaths…` (re-entry from the same context; both paths; the clock authority is unchanged). `save()` is guarded as a whole structurally; its pre-guard read of the held state is not separately observable as a wrong answer, so it has no red test of its own |
| F266-12 readers' clock and resolver | `testTheSimpleReaderClockCannotReenterTheStore…` (control: an ordinary read stays read-only), `testTheScoreReaderDeviceResolverCannotReenterTheStore` |
| F266-13 reader build leaves a binding | `testAFailedReaderBuildBindsNothingAndAnExistingBindingIsKept` (the pre-existing binding is kept; a later build in another domain succeeds) |
| F266-14 method-name inventory | `OwnershipRegistryTest::testAHostObjectWithAMethodNamedOwnedResources…`, `testASharedHostStoreWithAnOwnedResourcesMethodStillRefusesTheSecondCall`, `testAPackageClassIsStillExpanded…`; through the public entry, `HostStoreOwnershipTest` (two `FixedWindowSimpleRateLimiter` instances over a Host store that implements only `RateLimitStoreInterface`, with an `ownedResources()` method: the second call is refused before the store is touched and the Host method is never called; the first completes; the next call after the release runs; a second row for a Host store that is also a clock) and `testTheInventoryKeepsTheStoreWhateverInterfacesItImplements` (the raw clock is excluded by role, never by type) |

Mutants M13–M22 (`mutants.sh`) detect each of these corrections when it is removed.

## Second review of the correction, `02d51f24` (F266-15, F266-16)

| Finding | Test |
|---|---|
| F266-15 a failed build takes the binding of a runtime built meanwhile | `OwnershipCrossingsRedisTest::testAFailedBuildLeavesTheBindingOfARuntimeBuiltMeanwhileFromHostCode` (data sets `build()` and `buildOperationalReader()`, same provider; the runtime built from the Host getter is served before and after the failure); `OwnershipRegistryTest::testARollbackKeepsTheBindingAnotherCallStandsOn`; the control without the second runtime stays `testAFailedReaderBuildBindsNothing…` (unbound after the failure) |
| F266-16 a hand-assembled engine over a raw Host clock | `OwnershipCrossingsRedisTest::testAnEngineAssembledByHandCannotReenterTheStoreFromItsRawClock` (refused, 0 PINGs, the sound call is served; the Builder path is the existing clock tests); `OwnershipRegistryTest::testAHostClockPassesEveryReadingThroughOneToOneAndIsNeverWrappedTwice` (one reading in, one reading out; the request clock and a wrapped clock are never wrapped again) |

Reviewed collaborators that read a raw clock inside a call: `CircuitBreaker`, `BudgetTracker`, `AntiEquilibriumGate`, `DecayCalculator`, `EphemeralBucket`, `AdmissionRuntime`, `EvaluationPipeline`, `RateLimiterEngine` (each wraps a raw clock in `HostClock` at construction; the engine's clock is the one `LocalFallbackLimiter` receives, so its policy marks and its clock reads anchor on the same resource), and `FixedWindowSimpleRateLimiter` and the two readers (their clock is marked through their own store, unchanged). Mutants M23–M25 detect each correction when it is removed.

## Third review, `f0f9db3a` (F266-17: the lifetime of a binding and the rollback of a failed build)

| Case | Test (both `build()` and `buildOperationalReader()`) |
|---|---|
| 1. an entry class built by hand from Host code stands on the binding it adopted | `OwnershipCrossingsRedisTest::testAFailedBuildLeavesTheBindingOfAnEntryBuiltDirectlyFromHostCode`; `OwnershipRegistryTest::testAnAdoptionInsideAHostRegionStandsOnTheBindingAndOutsideItDoesNot` |
| 2. a failed replacement of a vacated binding returns to the vacated state; a direct call is rejected; a sound build with a third provider succeeds | `testAFailedReplacementOfAVacatedBindingRestoresTheVacatedStateAndALaterBuildSucceeds`; `OwnershipRegistryTest::testTheHoldersOfAReplacedBindingNeverKeepTheReplacementAlive` |
| 3. a direct call that is active during the rollback keeps its domain until its last release, then the overdue rollback happens | `testAnActiveDirectCallKeepsItsDomainUntilItsReleaseThenTheOverdueRollbackHappens`; `OwnershipRegistryTest::testAnActiveCallDelaysTheRollbackToItsLastRelease` |
| kept controls | the Builder-built B of F266-15 (`testAFailedBuildLeavesTheBindingOfARuntimeBuiltMeanwhileFromHostCode`), the ordinary failed build (`testAFailedReaderBuildBindsNothing…`), CE-29 (`…AfterEveryGraphThatSuppliedTheProviderIsDestroyed…`), CE-31 (`…AnActiveInheritedProviderCall…`), CE-32 (`…AReplacementIsRejectedWhile…`), `testARollbackKeepsTheBindingAnotherCallStandsOn` |

Mutants M26–M29 detect each of these corrections when it is removed.

## Fourth review, `b8f264f2` (F266-18, F266-19)

| Finding | Test (both `build()` and `buildOperationalReader()` where it is a build) |
|---|---|
| F266-18 a build suspended in a Fiber changes the rollback of an independent build | `OwnershipCrossingsRedisTest::testABuildSuspendedInAFiberNeverChangesTheRollbackOfAnIndependentBuild` (deterministic suspend/resume order; the independent store and provider; a sound later build succeeds; A resumes and completes); `OwnershipRegistryTest::testTheBuildFramesOfAFiberNeverMarkAnotherContext` |
| F266-18 a nested failed build from the same stack | `testANestedFailedBuildOnTheSameStackLeavesItsStoreUnbound`; `testABuildStartedFromAGetterOfAnotherBuildOnTheSameStackKnowsItsOwnComponents` (control: the nested graph that succeeds stays valid and reaches the executor); `OwnershipRegistryTest::testABuildStartedFromHostCodeKnowsItsOwnComponents`, `testAnAdoptionStandsOnTheBindingUnlessItIsPartOfTheBuildThatRecordedIt` |
| F266-19 a growing history of binding records | `testManySuccessiveGraphsOnOneStoreKeepABoundedNumberOfBindingRecords` (128 graphs, 128 providers collected, at most one record reachable); `OwnershipRegistryTest::testRepeatedSuccessfulReplacementsKeepABoundedNumberOfBindingRecords` |
| kept controls | F266-15/17 tests, the failed replacement and the rollback after the last owner's release (`testAFailedReplacementOfAVacatedBinding…`, `testAnActiveDirectCallKeepsItsDomain…`), and that an older record never releases a newer one (`testTheHoldersOfAReplacedBindingNeverKeepTheReplacementAlive`) |

Mutants M30 and M31 detect F266-18 when its correction is removed. F266-19 has no one-textual-change mutant (the fault is a property that chains records); its control is the red run against `b8f264f2`.

## Fifth review, `6afce55d` (F266-20: build isolation across execution contexts)

The correction of F266-18 kept the Builder's own entry classes apart from entries of Host code by a stack of build frames per Fiber (and a shared one for everything that is not a Fiber), so a non-Fiber scheduler (coroutines of an extension, a provider-defined scheduler) that interleaves two builds on one PHP stack was outside the approved contract (M2, M7). The mechanism is removed: no state about a build is shared between contexts. Each entry class records the holds it took when it adopted existing bindings against its own object (a weak map keyed by the entry), the Builder keeps the list of the entry classes it constructed itself, and a failed build releases those holds first and then rolls its own binding back; entries built by Host code from a getter are not in any such list and keep their holds.

| Requirement | Test |
|---|---|
| a build releases the holds of its own entries and of no other entry (an entry of Host code, also built from a getter, keeps its hold) | `OwnershipRegistryTest::testABuildReleasesTheHoldsOfItsOwnEntriesAndOfNoOtherEntry`; `OwnershipCrossingsRedisTest::testAFailedBuildLeavesTheBindingOfAnEntryBuiltDirectlyFromHostCode`, `…ARuntimeBuiltMeanwhileFromHostCode` |
| a build with only its own entries is undone completely, whatever the order of the releases | `testABuildWithOnlyItsOwnEntriesIsUndoneCompletelyWhateverTheOrderOfTheReleases` |
| two interleaved builds, in every order, with explicit ledgers and entries and no Fiber, stack or ambient state: the failed one is undone, the one that succeeded keeps its binding; the same for builds over one store | `testTwoInterleavedBuildsOverIndependentStoresAreIsolatedInEveryOrder`, `testTwoInterleavedBuildsOverTheSameStoreKeepTheBindingWhileOneStillStandsOnIt` |
| the mechanism of the finding cannot come back | `testTheRegistryKeepsNoBuildStateShapedByTheExecutionContext` (the registry keeps only its two weak maps: slots and adoptions) |
| a build suspended in a Fiber, a nested failed build on the same stack, a nested build that succeeds, two builds with different providers on one stack, the 128 replacements | the F266-18/19 tests of `OwnershipCrossingsRedisTest` and `OwnershipRegistryTest`, unchanged and passing |

What was run and what was not: the Fiber cases and the explicit-order models above ran on actual PHP_INT_SIZE 8 and 4. **No scheduler other than PHP Fibers was run** (no Swoole or similar extension in the Docker image); the claim for such schedulers rests on the removal of every piece of build state that depends on the context, shown by the structural test, and on the interleaving models. Mutants M26 and M30–M33 detect the removal of each part of the mechanism.

### Follow-up of the fifth review (lifetime of an adoption)

The reviewer asked for two controls on `3796b4d7` before any change: (1) a graph whose store's callback points back to it (through a holder) is collected, with its store and provider, once the Host drops its references, and the adoption records disappear; (2) an entry built from a getter that died before the build failed leaves no extra hold. Results on `3796b4d7`, both widths (`raw-lifetime-followup/`): **(1) passed** (the graph, the store and the provider were collected and the records vanished: no leak through the weak map on PHP 8.4.27); **(2) failed**: the hold of an entry that was already gone stayed counted, so after the build failed the store stayed bound to the failed build's provider and a sound build with another provider that was alive at the Host was refused. Correction: a hold is an object (`AdoptionHold`) that names its resource weakly and releases itself when its entry is destroyed or when its build releases it, so the registry keeps no strong reference to a store through an entry and a dead entry holds nothing. Tests: `OwnershipCrossingsRedisTest::testAGraphWhoseStoreCallbackPointsBackToItIsCollectedWithItsStoreProviderAndAdoptionRecords` (kept as a control, both build paths), `testAnEntryBuiltFromAGetterThatDiedBeforeTheFailureHoldsNothingAnyMore` (red on `3796b4d7`), `OwnershipRegistryTest::testAnEntryThatDiedReleasesItsHoldAndTheRegistryNamesItsResourceOnlyWeakly` (red on `3796b4d7`). Mutant M34 (a hold that does not release itself on destruction) detects the correction.
