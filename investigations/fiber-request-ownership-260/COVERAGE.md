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

Declared gaps (not covered, by design or by limit): policy callbacks are not crossing-marked; decorated aliasing is unsupported; a suspended call never destroyed keeps ownership; a non-Fiber scheduler without a provider is covered only inside guarded crossings; a custom non-package store is protected at graph entries only.
