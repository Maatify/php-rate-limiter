# DEC-029 — No Concurrency Exception After a Committed K4/K5 Write (K4 Punishment Publication)

## Decision ID

`DEC-029`

## Status

`ACTIVE` — activated in [PR #168](https://github.com/Maatify/php-rate-limiter/pull/168) (step S of #160) on the Owner's decision relayed on the PR
([comment 6456065101](https://github.com/Maatify/php-rate-limiter/pull/168#issuecomment-6456065101),
[comment 6466624572](https://github.com/Maatify/php-rate-limiter/pull/168#issuecomment-6466624572)), after the measurement of
`investigations/flaky-160/stepS/REPORT.md`. Earlier status: `PROPOSED` (PR #166, principles only). The explicit security approval that
`docs/FAILURE_SEMANTICS.md` requires for a change to its §10.1 is given by the Owner's comment on PR #168 before the PR is marked Ready.

## Date

Proposed 2026-10-24; activated 2026-10-28.

## Decision Authority

**Owner** (selection of Path B for #160, by way of a decision record first, then a measurement, then `src/`): the Owner's
decision on [PR #165 comment 6375378458](https://github.com/Maatify/php-rate-limiter/pull/165#issuecomment-6375378458), answering
the Lead's recommendation in [PR #165 comment 6375214721](https://github.com/Maatify/php-rate-limiter/pull/165#issuecomment-6375214721);
the scope of P1 (K4 and K5 only) and the unchanged L1741 on the Owner's decision of step M; the activation on the PR #168 comments cited in Status.
The options B-1, B-2 and B-3 are those of `investigations/flaky-160/phase2b/REPORT.md` §5.

## Scope / Concern

What a caller observes when the K4 punishment publication of `EvaluationPipeline::persistWinningCandidates` exhausts its
generation-fence budget **after** the request's allowance unit, K4 mutation and K5 `+2` have been committed (formerly
`RateLimitConcurrencyException('K4 punishment publication conflict budget exhausted.')`, L2168), and the general rule that
follows for any exception of this kind raised after a committed K4 or K5 write.

## Context

- **Source of the question.** #160, T-ALLOW (`KnownDeviceAllowanceConcurrencyIntegrationTest`) asserts
  `k5 = 2 × (consumed − conflicts)`, which treats a `RateLimitConcurrencyException` as "no K5 update".
- **Phase 2** (`investigations/flaky-160/phase2/REPORT.md`, merged `2cf8565`): A-REC (full-record tap) 1 / 5,000; the one failing
  run has a worker with the unit, the K5 `+2` **and** a `RateLimitConcurrencyException` (P-FAIL refuted, the unit was
  committed). A-RATE (no tap) 4 / 1,000 = 0.40 %, Wilson [0.156 %, 1.024 %].
- **Phase 2b** (`investigations/flaky-160/phase2b/REPORT.md`, merged `dddea39`): the throw site is L2168, reached when the loop
  `for ($attempt = 0; $attempt < 3; …)` over `blockWithPunishmentLifecycleTracking` ends without an applied publication
  (budget 3). The loop has two exits without an applied publication, both followed by the same throw: **r3**, three attempts
  all not applied; and **b2**, the re-read K4 level falls below 2 (`if ($latestLevel < 2) break;`) before the budget is used.
  A-DET 20 / 20: exception from L2168, K4 applied, K5 `+2`, no K4 block, three publication calls; control 0 / 20.
  A-NAT 2 / 2,000 = 0.10 %, both on the three-attempt route; with the phase-2 instance, 3 / 3 natural instances recorded. In
  the two instances recorded with PHP-side calls the K4 block did **not** exist at the throw (prediction (ii) refuted, 0 / 2,
  §3.3); it appeared 1–10 ms later through another worker.
- **Step M** (`investigations/flaky-160/stepM/REPORT.md`, merged `d71c0af`): exactly two sites in `src/` throw
  `RateLimitConcurrencyException`: L1741 (the K4 mutation budget) and L2168 (the K4 publication budget).
- **Not this record's subject: the K4-mutation conflicts.** The K4-mutation budget conflicts (6,342 conflict workers in the 2,000
  A-NAT runs of step M, 6,340 in its 1,998 passing runs; in the step-S A-NAT run 6,362 in 2,000 passing runs) did **not** commit a
  K4 mutation or a K5 increment for that request. What is committed before the throw at L1741 is the **allowance unit of a known
  device and K1** (for the measured command `k1_spray = +5`), not K4 and not K5 (K5 follows K4 in the loop). `K = 0` in the
  test equation therefore means the absence of K5 only, not the absence of every write.
- **Contract today (before this record).** `RateLimiterEngine::limit` (L344) catches `RateLimitConcurrencyException` and turns it
  into `HARD_BLOCK` level 2, `retryAfter 1` and cause `k4_concurrency_conflict`. A Host built with the builder never sees the
  exception, but a result whose cause and duration do not match the written state: at L2168 K4 and K5 have been recorded and no
  block is published; at L1741 neither K4 nor K5 was recorded. The exception itself is seen by a caller of `EvaluationPipeline`
  directly. `docs/FAILURE_SEMANTICS.md` §10.1 and `docs/DECISION_MATRIX.md` stated that exhaustion returns a transient HARD
  failure with `RateLimitConcurrencyException` provenance without distinguishing "nothing was recorded" from "recorded,
  publication lost".

## Decision

1. **P1 — The result matches the state after a K4/K5 write.** `RateLimitConcurrencyException` is not thrown for a request after
   the request's first **K4 mutation or K5 increment** has been committed. The scope of P1 is the account/device scores K4 and K5
   only. **Outside P1, and stated explicitly:** (a) K1–K3, the aggregate IP/UA/fingerprint scores; (b) the known-device allowance
   unit, a bounded consumer (point 6). **Effect:** after the throw at L1741 the allowance unit and K1 are recorded and K4/K5 are
   not; L1741 stays as it is and is measured (point 7).
2. **P2 — Publication behind the fence is best-effort after the write.** The publication of the K4 penalty stays fenced by
   the generation (DEC-007 is not touched). The publication makes **at most four calls and three snapshot reads** (the three
   attempts of before plus one final attempt on the generation read after the third failure; no read after the last call).
   When the budget is exhausted after a committed write:
   1. the request returns the decision computed from the state it wrote (level ≥ 2 ⇒ the block decision, with its level and
      duration unchanged) and leaves the persistence of the block to the first later publisher that succeeds (B-3). The cause
      `k4_publication_deferred` (context reason `k4_publication_deferred`, scope `k4`) is reported in the existing
      `RateLimitMetadataDTO`/`RateLimitContextMetadataDTO` shapes; no DTO changes. A snapshot that cannot be read (b1) is treated
      the same way;
   2. if the level of the re-read snapshot is below 2 (route b2), the K4 block is dropped, the K4 contribution is removed from the
      candidates and the decision is recomputed through the existing aggregation (K5 and every other candidate unchanged; possibly
      ALLOW), with no block and no exception;
   3. the remaining blocks of the request are always persisted.
   B-2 (acknowledge an existing block and return its decision) is **rejected on evidence**: no K4 block existed at the
   moment of the throw in 2 / 2 instances (REPORT 2b §3.3), so it would have changed none of the observed cases.
3. **P3 — The decision follows the state.** The decision of a request is derived from the state written, not from the success
   of the publication: two requests in the same state get the same decision whether or not one of them published the block. The
   level in the result is the level of this request's write, also when a later publisher persists a higher one (unchanged from
   the success path).
4. **P4 — One record per request.** No rollback of K4 or K5 after commit, and no double recording.
5. **P5 — What this record decided at activation.** The number of attempts (four calls, three reads), that no DTO or exception
   shape changes, that no interface changes, and that the test's equation holds without relaxation (every remaining conflict is a
   mutation conflict at L1741, with K = 0).
6. **The allowance unit is a bounded consumer.** A retry after the exception at L1741 may consume a second allowance unit for the
   same failure. The consumer is bounded by the allowance limit (`getKnownDeviceAccountAllowance()`, 8 in `LoginProtectionPolicy`),
   so it does not accumulate without bound; it is outside P1 and not a breach of P4.
7. **L1741 is a measured, unchanged site.** `K4 generation mutation conflict budget exhausted.` keeps throwing. Measured in step M
   and re-measured in step S: A-DET-1741 20 / 20 (control 20 / 20) in both; in benign flows through the pipeline across the Unit,
   Integration and System suites: 7 throws, all in the named intended tests, 0 unexpected, on both the base and the change. If a
   non-zero number appears in a benign flow the scope question is reopened on evidence.

## Rationale

- **Why P1.** The reason is the match between the result and the state (P3). After K4 and K5 have been recorded for the
  request, the written state calls for a decision derived from it; reporting `HARD_BLOCK`/`retryAfter 1`/cause
  `k4_concurrency_conflict` tells the Host a cause and a duration that do not match the state: no block published, and less
  than the K4 score written requires. This is a defect in the truth of the result, not in the ability to retry. (The earlier
  argument "a Host retry would record twice" is withdrawn: the Engine catches the exception and does not expose it to the Host, and
  at L1741 a retry consumes a second bounded allowance unit, not a double K4/K5 record.)
- **Why not B-2.** The recorded throws had no block to recognise (0 / 2).
- **Why B-1 then B-3 together.** B-1 alone only shrinks the window (the lost race recurs under a bounded budget); B-3 alone
  gives up a cheap re-read. Both stay within DEC-007: the fence is never bypassed, the block is published only with a
  generation that matches.
- **Why a record before code.** The change alters what a Host observes (P1) and who persists the block (P2), both contract
  statements; Decision Governance requires the record to precede the change, and the 5-b2 lesson requires the benign cost to
  be measured before `src/`.

## Consequences

- **For the Host.** No `RateLimitConcurrencyException` for the publication budget. A request may return the block decision while
  the K4 block is not yet persisted for a few milliseconds until another publisher succeeds (1–10 ms in the two instances of
  phase 2b; **no natural deferral occurred in the 2,000 runs of step S, so this is not re-measured**; its covering evidence is the
  scripted `PublicationDeferralTest`). The cause `k4_publication_deferred` replaces `k4_concurrency_conflict` for this case.
- **Measured at activation** (`investigations/flaky-160/stepS/REPORT.md`, E-CI; 165.4 runner-minutes over two dispatches):
  1. A-NAT 2,000 runs with the change: 2,000 / 2,000 pass, no exception from the publication site, publication attempts
     1 / 2 / 3 / 4 / deferred = 2,153 / 3,401 / 79 / 5 / 0 workers; the five that reached the fourth attempt would have thrown before.
  2. Publication latency under 16 workers (median of `persistWinningCandidates` calls that included a K4 publication): 6.346 ms
     against 6.010 ms for the base, ratio 1.051 (bound 1.25).
  3. Decisions and test results: 1,388,697 `RateLimiterEngine::limit` results in 248 shared tests, 0 differences; 1,966 base and
     1,973 change tests all passing; the differences between the suites are the renamed L441 test and the seven new tests.
  4. The inventory of sites that throw `RateLimitConcurrencyException` after a committed write is complete (step M §2): L1741 and
     L2168 only.
- **Test.** Under this record the equation `k5 = 2 × (consumed − conflicts)` holds without relaxation (every remaining conflict is
  a K = 0 conflict at L1741). The test changed under this record: `PostPunishmentReentryLifecycleTest::testThreePublicationConflictsReturnTransientHardWithoutCircuitFailure`
  (L441) became `testExhaustedPublicationIsDeferredWithoutCircuitFailure` (HARD / 2 / 60, `k4_publication_deferred`, four calls);
  `PublicationDeferralTest` and `ScriptedPublicationRateLimitStore` were added.
- **Contract documents.** `docs/FAILURE_SEMANTICS.md` §10.1, `docs/DECISION_MATRIX.md` and `RATE_LIMITER_PACKAGE_REFERENCE.md` state
  the new contract and keep L1741 as it was. The versioning of `FAILURE_SEMANTICS.md` is this record and the CHANGELOG entry
  (the document has no version header); the explicit security approval is the Owner's comment on PR #168.
- **State.** No new key and no new capability.

## Relationship to Other Decisions

- **DEC-007** — the generation fence stays; this record changes what happens when its budget is exhausted after a write.
- **DEC-016 / #158** — time authority unchanged.
- **DEC-026 / DEC-027 / DEC-022 / DEC-028** — the admission scope and its package constants are not touched.
- **DEC-003** — hard-block cycle accounting stays atomic with the publication.
- This record modifies no ACTIVE decision and supersedes none.

## Canonical Contract / Current Owner

`docs/FAILURE_SEMANTICS.md` (§10.1, the provenance of the exception) and `docs/DECISION_MATRIX.md` (the lifecycle paragraph) carry
the contract; code owner: `EvaluationPipeline` (`persistWinningCandidates`, `finalizeDecision`).

## Supersedes

None

## Superseded By

None
