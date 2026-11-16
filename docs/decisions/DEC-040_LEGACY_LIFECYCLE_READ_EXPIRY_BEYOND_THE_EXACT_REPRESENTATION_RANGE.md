# DEC-040 — Legacy Lifecycle Read Expiry Beyond the Exact Representation Range

## Decision ID

`DEC-040`

## Status

`ACTIVE` — Owner-approved on [#250](https://github.com/Maatify/php-rate-limiter/issues/250) ("approve Option 0 in the corrected wording"; assignment: [comment 6924506128](https://github.com/Maatify/php-rate-limiter/issues/250#issuecomment-6924506128); the corrected wording is the Lead review [comment 6924439744](https://github.com/Maatify/php-rate-limiter/issues/250#issuecomment-6924439744)).

## Date

2026-11-16

## Decision Authority / Deciders

**Owner**, explicit approval of Option 0 of the #250 proposal (keep the current read behavior; no new cap, no new refusal, no change to the Redis lifecycle runtime), recorded in the assignment on #250. Context: the investigation report ([comment 6917197987](https://github.com/Maatify/php-rate-limiter/issues/250#issuecomment-6917197987)) and the independent Lead review above.

## Scope / Concern

The Redis lifecycle **read** of a **legacy** generation-bound score: a Current or Previous score with no stored generation and no stored `expiresAt` (`RedisFullCapabilityStore`, the legacy branch of the lifecycle read script, reached directly and inside `transactRequest()`). It decides what that read answers, and what it may refuse, when the Redis integer values it depends on are too large for Lua to hold exactly. It does not concern generated state, any other Redis TTL, or any other store.

## Context

A legacy score has no logical expiry of its own. The read maps its remaining physical life onto the caller's timeline: `ceil((callerNowMilliseconds + remainingPTTL) / 1000)` (DEC-016, application note of #160). That value is the snapshot's expiry; the first real mutation writes the one the snapshot carries and never derives it again.

Facts established on isolated Redis 7.0.15 with PHP 8.4.27 on 32-bit and 64-bit builds (investigation and Lead review linked above; they are evidence, not failure rates):

- Redis stores such an expiry exactly and answers `PTTL` / `PEXPIRETIME` exactly on the wire, up to the signed 64-bit maximum.
- Lua receives a Redis integer reply as a double. At 2^53 and above two different exact integers can reach the script as the same double, so no arithmetic on that double recovers the lost bits. The seconds commands (`TTL`, `EXPIRETIME`) are exact but only to within half a second, which does not decide the ceiling. No exact channel exists inside the script.
- #249 computes the ceiling without leaving Lua's exact range, but only on one branch: the **split branch**, used when the PTTL the script sees is positive and at most `2^53 − 1` ms (and the caller clock is a canonical valid value). Above that bound the script deliberately keeps the historical arithmetic, **even when a particular PTTL is exactly representable in Lua**. `2^53 − 1` is therefore the bound of the exact calculation, not a rejection limit, and it does not say that every larger value is approximated: for example caller `0` with PTTL `9007199254742000` reaches Lua without loss (`string.format('%.0f', …)` returns it unchanged), yet the historical arithmetic answers `9007199254743` where the exact ceiling is `9007199254742`, direct and in the overlay.
- In `transactRequest()` the request overlay first captures the absolute expiry (`PEXPIRETIME`, a Lua number) and subtracts its own clock. If the absolute value cannot be held exactly, it is rounded **before** the subtraction, so a true PTTL at or below `2^53 − 1` is not enough for an exact overlay answer: the PTTL the script sees must itself come from a lossless capture of the absolute expiry (measured example: absolute expiry `9007199254740995`).
- Outside the proven-exact conditions the existing code may return an approximate expiry, or may refuse. The refusal is the existing validation: for a very large value the script emits an expiry formatted in exponent notation, which is not a canonical integer, so the read replies with a malformed-expiry failure (`malformed generation-bound score expiry` in the test harness) although the seconds would fit a 64-bit `int`. On a 32-bit build an expiry that does not fit the native DTO is refused with a classified `RateLimiterException`. A bounded calculation guarantees neither valid persisted state nor native DTO success.
- The read changes no key and no expiry in either path.

The default K4 lifetime (86400 s) never creates such a lifetime, but the public path may read legacy state that already exists, so the case is not described as unreachable from the public interface.

## Decision

1. **Keep the current read behavior.** The legacy read keeps the derivation of the Context and the split calculation of #249. A read that returns a validated expiry is exact when **both** conditions hold: (a) the PTTL the script sees is positive and at most `2^53 − 1` ms, with a canonical valid caller clock and an accurate remaining life, and (b) the relevant lifetime was captured without loss (in `transactRequest()`, the absolute expiry must reach the overlay unrounded, since the overlay can round it before the subtraction). Above the split bound the original calculation remains, even for a PTTL that is exactly representable. Outside those conditions the existing replies or failures are preserved as they are: the read may answer an expiry that is not exact, or may be refused by the existing validation, with no fixed error bound and no new refusal.
2. **No new bound.** No cap, clamp, new refusal, change of the native DTO range, substitution of Redis time for the caller's clock, new client-side Redis command, or change of the overlay is introduced. Malformed state stays a failure and is never turned into absence.
3. **Statements this record does not make.** It does not say that every legacy read succeeds, that every answer is exact, that a representable operand is enough for exactness, that a true PTTL below the bound alone guarantees overlay accuracy, that bounded arithmetic guarantees valid state or a native DTO, that an inexact answer is wrong by at most one second, or that PHP64 accepts everything whose seconds fit an `int`. It does not extend to generated state, which keeps its stored `expiresAt` as the authority, nor to other Redis TTLs.
4. **Unchanged contracts.** The caller's clock keeps the logical time; Current is read first and Previous is the fallback; the read performs no write; the snapshot's accepted expiry remains the source for the mutation and is not derived a second time (DEC-016); the request overlay keeps its documented behavior (DEC-037).
5. **Reopening.** An exact treatment, an explicit refusal of the inexact range, or a limit is a different decision and needs its own record (the options A to D of the #250 proposal are the starting point).

## Rationale

- The inexact range is far outside any lifetime the package creates. The investigation shows that bits lost in the conversion of a Redis integer reply to a Lua number cannot be recovered from that number, and that none of the commands examined inside the script returns the missing digits. The one exact design evaluated (option C: the client reads the exact value over RESP and passes it to the script) does not preserve the atomic read, the request overlay or the Host executor contract. This does not show that no other exact design exists; it limits the conclusion to the current path, the commands examined and the evaluated option.
- Refusing the range would turn state the read accepts today into an error, and a limit would be a new domain rule; both are decisions the Owner has not taken.
- Recording the boundary of the exactness guarantee, instead of an implied one, keeps the contract honest: exact on the split branch with lossless inputs, historical elsewhere.

## Consequences

- A consumer may rely on an exact legacy expiry when the PTTL the script sees is positive and at most `2^53 − 1` milliseconds, the caller clock is valid, and the lifetime was captured without loss; it must not rely on exactness above that bound (even for a PTTL that is representable), or in the overlay when the absolute expiry cannot be held exactly.
- The existing controls of #249 (the largest exact PTTL, the first PTTL above it, the historical-compatibility case, the unpinned real-PTTL test) stay the executable evidence; this record changes no test and no source.
- Anyone who needs an exact or an explicitly refused answer for that range must raise a new decision; no code change is implied here.

## Evidence

| Source | Used for | Class |
| --- | --- | --- |
| [#250 investigation](https://github.com/Maatify/php-rate-limiter/issues/250#issuecomment-6917197987) | where precision is lost (Lua number conversion, overlay capture), the same-double pair, the options | `MEASURED` |
| [#250 Lead review](https://github.com/Maatify/php-rate-limiter/issues/250#issuecomment-6924439744) | overlay absolute-expiry rounding, exponent-notation refusal, wording of Option 0 | `MEASURED` |
| `tests/Integration/Redis/LifecycleLegacyReadExpiryRedisTest.php` | exact arithmetic where inputs are exact, historical compatibility controls beyond the range, 32-bit refusal, direct and overlay | `MEASURED` |
| [CI 43860315867](https://github.com/Maatify/php-rate-limiter/actions/runs/43860315867) | the merged state `6ea5413c` that this record documents | `MEASURED` |

## Relationship to Other Decisions

- **DEC-016:** the legacy expiry mapping and snapshot authority (#160); this record completes it for the representation boundary and does not restate or replace it.
- **DEC-007:** the lifecycle, source selection and fencing of K4; unchanged.
- **DEC-017 / DEC-039:** the semantic timestamp and decimal caller-clock domains (`0` to `2^53 − 1`); the PTTL remaining life is a separate quantity and is not bounded by them.
- **DEC-018:** the reply boundary of the official Redis adapter; unchanged.
- **DEC-037:** the request overlay; its capture of the absolute expiry is described here and not altered.

## Canonical Contract / Current Owner

`RedisFullCapabilityStore` (the legacy branch of the lifecycle read), `RedisRequestOverlay`, `tests/Integration/Redis/LifecycleLegacyReadExpiryRedisTest.php`, `RATE_LIMITER_PACKAGE_REFERENCE.md` (legacy paragraph under the Runtime Workflow).

## Supersedes

None

## Superseded By

None
