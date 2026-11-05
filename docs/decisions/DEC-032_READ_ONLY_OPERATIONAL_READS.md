# DEC-032 — Read-Only Operational Reads

## Decision ID

`DEC-032`

## Status

`PROPOSED` — in the PR of F-185-26/28 (#185, PR-O), on the Owner's decisions relayed on #185 (LT-185-26 (a), LT-185-26a). It
becomes `ACTIVE` on the Owner's word at merge, after the regression this record names (Consequences) and the Lead's review.

## Date

Proposed 2026-11-05.

## Decision Authority

**Owner**, on LT-185-26 (a) and LT-185-26a ([#185 comment 6663632815](https://github.com/Maatify/php-rate-limiter/issues/185#issuecomment-6663632815)),
with the Owner's decisions of 2026-11-05 relayed by the Lead on #185.

## Scope / Concern

The block and budget reads of Operational Read (`RateLimitOperationalReader`, `SimpleRateLimitOperationalReader`, composed by
`RateLimiterBuilder::buildOperationalReader()`, DEC-012): the store capability they read through, and what the composition
requires of the Host's rate-limit store.

## Context

- **F-185-26 (train 7, the Lead's adversarial review, adv8 `r5`).** Both readers are documented read-only, and both read blocks
  and budgets through `checkBlock()` / `getBudget()`. In `RedisFullCapabilityStore` those are `BLOCK_GET` / `BUDGET_GET`, which
  delete a logically expired key as they read it (LT-P2-03). A reader run at a block's expiry removed the block key
  (`EXISTS` 1 → 0); a simple-throttle snapshot read after its interval removed the budget key.
- **Why the runtime reads keep deleting.** The deletion is the runtime's housekeeping on a path that writes anyway (LT-P2-03);
  the defect is only that Operational Read shares it.
- **Why not a fallback in the composition.** A fallback to `checkBlock()` / `getBudget()` for a store without the new reads
  would leave F-185-26 open for that store without a word (LT-185-26a).

## Decision

1. **Read-only reads.** `OperationalReadOnlyStoreInterface` (extends `RateLimitStoreInterface`) adds `peekBlock(key):
   ?BlockStateDTO` and `peekBudget(key): ?BudgetStateDTO`, and its caller-time mirror
   `CallerTimeOperationalReadOnlyStoreInterface` (DEC-016) adds `peekBlockAt(key, now)` / `peekBudgetAt(key, now)`. Each reads
   with the same validation as the runtime read, answers null for an expired block or a budget past its epoch, and writes
   nothing: no delete, no TTL change.
2. **Malformed state is an error.** The same error the runtime read raises, and the key is left as it is (DEC-017).
3. **The readers read through them.** Over a store that implements the interface, both readers read blocks and budgets with the
   read-only methods, at the caller's time when the store supports it.
4. **`buildOperationalReader()` requires the interface** of the rate-limit store, with the message `Operational Read requires a
   store implementing OperationalReadOnlyStoreInterface (#185, DEC-032).`, and never falls back to the deleting reads.
5. **The runtime is unchanged.** `checkBlock()` / `getBudget()` and their scripts keep deleting an expired key.
6. **The readers' constructors are unchanged** (DEC-012 §4): a reader built directly over a store without the interface keeps
   `checkBlock()` / `getBudget()`, as DEC-031 leaves its low-level constructors.
7. **Implementation.** `RedisFullCapabilityStore` implements the caller-time interface with `BLOCK_PEEK` / `BUDGET_PEEK`, which
   share their validation text with `BLOCK_GET` / `BUDGET_GET` (`BLOCK_VALIDATE`, `BUDGET_VALIDATE`; the runtime scripts are
   byte-identical to what they were) and end without the `DEL`.

## Rationale

- **A separate read, not a flag on the runtime read:** the runtime's methods and scripts stay exactly as they are, and a Host
  store implements two small reads.
- **Required by the composition, not optional:** the reason of DEC-030 and DEC-031 — an optional capability would leave the
  defect open for a Host store.
- **Constructors unchanged:** DEC-012 §4 keeps the reader usable directly; refusing there would amend an ACTIVE decision.

## Consequences

- **For a Host using `RedisFullCapabilityStore`:** no change to configure; Operational Read no longer deletes.
- **For a Host store before 1.0:** `buildOperationalReader()` fails with the message above until the rate-limit store implements
  `peekBlock()` / `peekBudget()` (and `peekBlockAt()` / `peekBudgetAt()` for the caller's time). A store that never deletes on
  read can return its `checkBlock()` / `getBudget()`. This is the upgrade note of the CHANGELOG entry.
- **Measured before activation:** the regression of the Lead's adv8 `r5` through the public API (score reader and simple reader),
  the store-level reads, the same-error validation, and the control that the runtime reads still delete
  (`OperationalReadOnlyReadsRedisTest`, `OperationalReadOnlyPreflightTest`).
- **State.** No new key and no new shape.

## Relationship to Other Decisions

- **DEC-012** — Operational Read stays a separate, read-only composition. `buildOperationalReader()` still skips `build()`'s
  mutation-only preflight; it now requires the one capability it reads through, which §3 allows (it fails only on capabilities
  the reader never uses). §4 is kept: the reader stays usable directly.
- **DEC-016** — the caller-time mirror follows the additive `CallerTime*` pattern.
- **DEC-017** — what is stored is validated before it is answered.
- **DEC-030, DEC-031** — the same additive-capability pattern, required at composition time.
- This record modifies no ACTIVE decision and supersedes none.

## Canonical Contract / Current Owner

`OperationalReadOnlyStoreInterface`, `CallerTimeOperationalReadOnlyStoreInterface`, `RateLimiterBuilder::buildOperationalReader()`
(the requirement), `RateLimitOperationalReader`, `SimpleRateLimitOperationalReader`, and `RedisFullCapabilityStore`
(`BLOCK_PEEK`, `BUDGET_PEEK`).

## Supersedes

None

## Superseded By

None
