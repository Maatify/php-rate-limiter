# DEC-033 — Sliding Admission Windows for the Admission Layer

## Decision ID

`DEC-033`

## Status

`PROPOSED` — in the PR of PR-P (#185), on the Owner's decision relayed on #185 ([comment 6675589019](https://github.com/Maatify/php-rate-limiter/issues/185#issuecomment-6675589019), LT-185-30 decision (b)), amending DEC-025 and DEC-028 on the Lead's review LT-185-31b ([comment 6679420838](https://github.com/Maatify/php-rate-limiter/issues/185#issuecomment-6679420838)). It becomes `ACTIVE` upon Owner acceptance of the measured values at merge.

## Date

Proposed 2026-11-06.

## Decision Authority

**Owner**, on LT-185-30 decision (b) ([#185 comment 6675589019](https://github.com/Maatify/php-rate-limiter/issues/185#issuecomment-6675589019)),
approving the uniform sliding window implementation across all admission mechanisms, with the Lead's ruling and corrections
on LT-185-31b ([#185 comment 6679420838](https://github.com/Maatify/php-rate-limiter/issues/185#issuecomment-6679420838)).

## Scope / Concern

Amending DEC-025 (Decisions 1.4, 3.1, 8.2) and DEC-028 (Clause 2) to replace fixed admission window buckets (`w<...>`, `d<...>`)
with sliding windows (`ZSET`) across all windowed and daily admission scopes (`source_failure_cap`, `source_proof_failure_cap`,
`source_capacity`, `proof_account_window`, `proof_proof_window`, `proof_account_day`, `proof_proof_day`), while keeping the
DEC-025 / DEC-028 constants (`W=600`, `F=4`, `U_src=24`, `P_acct=10/60`, `P_proof=6/30`, `N_P=10`, `D=30`, `G=600`).

## Context

The initial admission layer implementation keyed windowed counters and failure sets by fixed bucket (`w<intdiv(t, W)>` with TTL `2W`
or `d<intdiv(t, DAY)>` with TTL `2*DAY`). Across a bucket boundary, an adversary could double their allowance within a narrow burst
(e.g. 8 failures within 14 s against net failure cap `F=4`, 48 requests against `U_src=24`, or 120 requests against daily cap 60 in
two hours). The Owner decided (LT-185-30 (b)) that all admission window mechanisms must slide uniformly so that any interval of length
`W` (or `DAY`) strictly enforces the decided budget.

## Decision

1. **Scopes that slide:**
   - `source_failure_cap` (`SET_SIZE`, U-lane failure cap `F=4`): slides over window `W = 600 s`. Member is account/subject; score is expiry `now + W`.
   - `source_proof_failure_cap` (`SET_SIZE`, P-lane D8 failure cap `F=4`): slides over window `W = 600 s`. Member is account/subject; score is expiry `now + W`.
   - `source_capacity` (`COUNTER`, U-lane source capacity `U_src=24`): slides over window `W = 600 s`. Member is `permit_id`; score is expiry `now + W`.
   - `proof_account_window` (`COUNTER`, P-lane account cap `P_acct=10`): slides over window `W = 600 s`. Member is `permit_id`; score is expiry `now + W`.
   - `proof_proof_window` (`COUNTER`, P-lane proof cap `P_proof=6`): slides over window `W = 600 s`. Member is `permit_id`; score is expiry `now + W`.
   - `proof_account_day` (`COUNTER`, P-lane daily account cap `60`): slides over day `DAY = 86400 s`. Member is `permit_id`; score is expiry `now + DAY`.
   - `proof_proof_day` (`COUNTER`, P-lane daily proof cap `30`): slides over day `DAY = 86400 s`. Member is `permit_id`; score is expiry `now + DAY`.
   - `account_bucket` (`BUCKET`, `C=4`, `T=1800 s`): continuous leaky bucket (unchanged; mathematically continuous, no window boundaries).
   - `new_device_cap` (`DEVICE_MEMBER`, `N_P=10`): already slides per device (`ZSET` of fingerprint members scored by `now + DAY`, unchanged).

2. **Storage and operations contract (`RedisAdmissionOperations`):**
   - **Counting (Gate Phase 1):** `ZCOUNT cur (nowStr +inf` counts live members whose score exceeds caller's `now`. Pure read; a refusal writes nothing and never prunes (L26 contract: *A refusal writes nothing, never prunes*).
   - **Admitted write / failure write (Phase 3):** Prunes expired members (`ZREMRANGEBYSCORE cur -inf nowStr`), writes the member (`ZADD cur (now + ttl) <member>`), and refreshes key expiry (`EXPIRE cur ttl`).
   - **Maximum member count:** Bounded by the scope's capacity; since refusals do not write, the member count of any key never exceeds its cap.
   - **Success refund (`proofRefund`):** Removes the admitted reservation's member (`ZREM cur <permit_id>`) from `pacct`, `pacctd`, `pproof`, and `pproofd`. A missing or already-pruned member is a no-op, never an error. Success after pruning does not restore a slot from a previous window.
   - **Settlement of expired permits (pending set, family `p`):** Due members (`score <= now`) count as failures as long as `deadline > now - W`. The pending key's lifetime is extended to cover the newest deadline + W, never shortened.
   - **Structural validation (DEC-017):** `checkType(cur, 'zset', ...)` and `canonicalScores(cur)`. Any unexpected type or malformed score yields `malformed admission state` without write.

3. **Key strategy and TTL (`AdmissionRuntime`):**
   - Bucket labels `w<...>` and `d<...>` are removed from sliding keys. Keys carry scope purpose and target hash only.
   - Key `TTL = W` (600 s) for windowed scopes, `DAY` (86400 s) for daily scopes.
   - Counter member is `permit_id` from `AdmissionPermitDTO`.

4. **Upgrade and migration:**
   - Old keys (Redis `set` or `string` carrying previous bucket hashes) cannot be distinguished by key name pattern (the window tag was inside the HMAC). They expire by their TTL (`<= 2W` for windows, `<= 2*DAY` for daily) and are rejected as malformed state (DEC-017) until expired.
   - Unreleased development/test environments are cleaned by clearing the library namespace (`FLUSHDB` on a dedicated database) or waiting for TTL expiry.

5. **Amendments to DEC-025 and DEC-028:**
   - **DEC-025 Decisions 1.4, 3.1, 8.2:** The phrasing "per window" and "per day" is amended to continuous sliding windows of duration `W` (600 s) and `DAY` (86400 s). All numerical constants (`W=600`, `F=4`, `U_src=24`, `P_acct=10/60`, `P_proof=6/30`, `N_P=10`) remain unchanged.
   - **DEC-028 Clause 2:** The package constants are applied through sliding windows of duration `W` (600 s) and `DAY` (86400 s). Timing constants (`D=30`, `G=600`) remain unchanged.

## Rationale

- **Uniform sliding behavior:** Prevents boundary double-bursting across both short (600 s) and daily (86400 s) horizons.
- **`permit_id` as counter member:** Naturally unique per admission request, avoiding synthetic nonces and ensuring exact matching for success refunds (`ZREM`).
- **Pruning on write only:** Respects the read-only refusal contract and keeps refusal latency minimal.

## Consequences

- **State:** Keys for sliding scopes become Redis `zset` with at most `cap` members per key.
- **Pre-registered measurement plan:**
  - `NM-10 parity`: 100% decision and state parity between `RedisAdmissionOperations` and `AdmissionInMemoryReferenceStore`.
  - `G7 PX4`: Office shared cost (`TPAIRT`) expected 21/61 unchanged; attacker reach `TMS <= 29` and `TMSd <= 40`.
  - `Runtime burst across boundaries`: Max allowed within any sliding interval equals cap: `ufail`/`pfail` 4 (not 8), `src` 24 (not 48), `pacct` 10 (not 20), `pproof` 6 (not 12), `pacctd` 60 (not 120), `pproofd` 30 (not 60).
- **Goldens and archive streams:** Replayed archive scenarios crossing boundaries are updated to reflect the stronger sliding enforcement.

## Relationship to Other Decisions

- **Amends DEC-025 and DEC-028:** Modifies window semantics from fixed buckets to sliding windows.
- **DEC-017:** Enforces type and score canonical validation.
- **DEC-024, DEC-026:** Unchanged.

## Canonical Contract / Current Owner

`src/Service/AdmissionRuntime.php`, `src/Repository/Redis/RedisAdmissionOperations.php`, `tests/Support/Admission/AdmissionInMemoryReferenceStore.php`, `docs/KEY_STRATEGY.md` §4.8, `docs/FAILURE_SEMANTICS.md`.

## Supersedes

None

## Superseded By

None
