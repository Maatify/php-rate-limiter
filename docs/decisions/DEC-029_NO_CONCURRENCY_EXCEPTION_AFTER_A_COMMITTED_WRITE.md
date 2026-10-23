# DEC-029 — No Concurrency Exception After a Committed Write (K4 Punishment Publication)

## Decision ID

`DEC-029`

## Status

`PROPOSED` — principles only. No code, no test and no contract document changes with this record. Activation (`ACTIVE`) is
recorded in the `src/` PR, after the measurement step, on the Owner's decision.

## Date

2026-10-24

## Decision Authority

**Owner** (selection of Path B for #160, by way of a decision record first, then a measurement, then `src/`); the principles
are proposed by the Lead's assignment and become binding only on the Owner's acceptance. The options B-1, B-2 and B-3 are
those of `investigations/flaky-160/phase2b/REPORT.md` §5.

## Scope / Concern

What a caller observes when the K4 punishment publication of `EvaluationPipeline::persistWinningCandidates` exhausts its
generation-fence budget **after** the request's allowance unit, K4 mutation and K5 `+2` have been committed
(`RateLimitConcurrencyException('K4 punishment publication conflict budget exhausted.')`, `src/Service/EvaluationPipeline.php`
L2168), and the general rule that follows for any exception of this kind raised after a committed write.

## Context

- **Source of the question.** #160, T-ALLOW (`KnownDeviceAllowanceConcurrencyIntegrationTest`) asserts
  `k5 = 2 × (consumed − conflicts)`, which treats a `RateLimitConcurrencyException` as "no K5 update".
- **Phase 2** (`investigations/flaky-160/phase2/REPORT.md`, merged `2cf8565`): A-REC (full-record tap) 1 / 5,000; the one failing
  run has a worker with the unit, the K5 `+2` **and** a `RateLimitConcurrencyException` (P-FAIL refuted, the unit was
  committed). A-RATE (no tap) 4 / 1,000 = 0.40 %, Wilson [0.156 %, 1.024 %].
- **Phase 2b** (`investigations/flaky-160/phase2b/REPORT.md`, merged `dddea39`): the throw site is L2168, reached when the loop
  `for ($attempt = 0; $attempt < 3; …)` over `blockWithPunishmentLifecycleTracking` ends without an applied publication
  (budget 3). A-DET 20 / 20: exception from L2168, K4 applied, K5 `+2`, no K4 block, three publication calls; control 0 / 20.
  A-NAT 2 / 2,000 = 0.10 %, both on the three-attempt route; with the phase-2 instance, 3 / 3 natural instances recorded. In
  the two instances recorded with PHP-side calls the K4 block did **not** exist at the throw (prediction (ii) refuted, 0 / 2,
  §3.3); it appeared 1–10 ms later through another worker.
- **Not this record's subject.** The K4-mutation budget conflicts (6,340 conflict workers in the 1,998 passing runs, all
  K = 0: nothing committed for that request) are the case the test's equation was written for, and they stay as they are
  (P1 below is satisfied by them).
- **Contract today.** `docs/FAILURE_SEMANTICS.md` §10.1 (L621) and `docs/DECISION_MATRIX.md` (L1018) state that exhaustion
  returns a transient HARD failure with `RateLimitConcurrencyException` provenance, without distinguishing "nothing was
  recorded" from "recorded, publication lost".

## Decision

1. **P1 — Exception cleanliness.** `RateLimitConcurrencyException` is thrown only when no write of the request has been
   committed. After the first committed write (allowance unit, K4 mutation or K5 increment) no concurrency exception is
   thrown for that request. The reason is observability: a Host cannot tell "not recorded" from "recorded, then the
   publication failed", and a retry of the second would record the failure twice.
2. **P2 — Publication behind the fence is best-effort after the write.** The publication of the K4 penalty stays fenced by
   the generation (DEC-007 is not touched). When the publication budget is exhausted after a committed write:
   1. one final attempt is made with a generation read afresh (B-1);
   2. if it also loses, the request returns the decision computed from the last consistent K4 snapshot (level ≥ 2 ⇒ the
      block decision) and leaves the persistence of the block to the first later publisher that succeeds (B-3).
   B-2 (acknowledge an existing block and return its decision) is **rejected on evidence**: no K4 block existed at the
   moment of the throw in 2 / 2 instances (REPORT 2b §3.3), so it would have changed none of the observed cases.
3. **P3 — The decision follows the state.** The decision of a request is derived from the state written, not from the success
   of the publication: two requests in the same state get the same decision whether or not one of them published the block.
4. **P4 — One record per request.** No rollback of K4 or K5 after commit, and no double recording.
5. **P5 — What this record does not decide.** The number of attempts, the shape of any DTO or exception, any interface change,
   and the test's equation. All are decided after the measurement step.

## Rationale

- **Why P1.** The exception is the only signal a Host has about whether to retry; raised after a commit it makes the safe
  action (retry) the wrong one.
- **Why not B-2.** The recorded throws had no block to recognise (0 / 2).
- **Why B-1 then B-3 together.** B-1 alone only shrinks the window (the lost race recurs under a bounded budget); B-3 alone
  gives up a cheap re-read. Both stay within DEC-007: the fence is never bypassed, the block is published only with a
  generation that matches.
- **Why a record before code.** The change alters what a Host observes (P1) and who persists the block (P2), both contract
  statements; Decision Governance requires the record to precede the change, and the 5-b2 lesson requires the benign cost to
  be measured before `src/`.

## Consequences

- **For the Host (once ACTIVE).** No `RateLimitConcurrencyException` after a committed write. A request may return the block
  decision while the K4 block is not yet persisted for a few milliseconds (observed 1–10 ms in the two instances) until
  another publisher succeeds.
- **To measure before activation (the next step; not started by this record).**
  1. The A-NAT harness re-run with the change on E-CI: the throw must disappear at a size that would have shown ≥ 3 instances.
  2. The number of publication attempts and the publication latency under 16 workers.
  3. The admission suites, for the cost to the benign user (the 5-b2 lesson), and that a block is eventually published when no
     other worker is mutating (single-worker race).
  4. An inventory of any other site that throws `RateLimitConcurrencyException` after a committed write (not done here; P1 is
     stated for the rule, the measured site is L2168).
- **Test.** Under this path the equation `k5 = 2 × (consumed − conflicts)` holds without relaxation, because every remaining
  conflict is a K = 0 conflict. This is a claim to be checked in the measurement, not a result.
- **State.** No new key and no new capability is proposed.

## Relationship to Other Decisions

- **DEC-007** — the generation fence stays; this record changes what happens when its budget is exhausted after a write.
- **DEC-016 / #158** — time authority unchanged.
- **DEC-026 / DEC-027 / DEC-022 / DEC-028** — the admission scope and its package constants are not touched.
- **DEC-003** — hard-block cycle accounting stays atomic with the publication.
- This record modifies no ACTIVE decision and supersedes none.

## Canonical Contract / Current Owner

`docs/FAILURE_SEMANTICS.md` (§10.1, the provenance of the exception, L621) and `docs/DECISION_MATRIX.md` (L1018) are the two
places to be updated **at activation**; they are not updated now. Code owner at activation: `EvaluationPipeline`
(`persistWinningCandidates`, L2133–L2168).

## Supersedes

None

## Superseded By

None
