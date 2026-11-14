<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;

/**
 * Decimal-millisecond mirror of `CallerTimePunishmentLifecycleStoreInterface` (#215, DEC-039).
 *
 * Additive, optional capability. `CallerTimePunishmentLifecycleStoreInterface` takes the caller's time in milliseconds as a native
 * `int`, which cannot hold a current epoch value where `PHP_INT_SIZE` is 4. A store that implements this
 * interface receives the exact value as a canonical decimal string through the separately named method below,
 * and the runtime prefers it. Every other contract of the extended interface is unchanged and still applies.
 *
 * Input domain of `$nowMilliseconds`: `0`, or digits with no leading zero, from 0 to 9007199254740991
 * (2^53 - 1, the exact integer range of Redis Lua). The second of the instant is the decimal without its last
 * three digits (`0` when it has three digits or fewer).
 *
 * Dispatch by the runtime, per call: a store that implements this interface gets the decimal and never the
 * native-integer method. A store that implements only `CallerTimePunishmentLifecycleStoreInterface` is called as before with the
 * native integer when the value fits one, and otherwise the runtime throws `RateLimiterException` before calling
 * it. A store that implements neither keeps its own clock.
 *
 * Why separate methods: PHP lets an implementation widen its own parameter, but widening the published interface
 * parameter in place would force every existing int-only implementation to accept the new type, and one that does
 * not would stop loading. An added method leaves every existing store compatible (DEC-039).
 */
interface CallerTimeDecimalMillisecondsPunishmentLifecycleStoreInterface extends CallerTimePunishmentLifecycleStoreInterface
{
    /**
     * `CallerTimePunishmentLifecycleStoreInterface::readGenerationBoundScoreStateAt()` with the caller's time as a canonical decimal string.
     *
     * Same operation, parameters, return value, atomicity and failure semantics as that method, which extends
     * `PunishmentLifecycleStoreInterface::readGenerationBoundScoreState()`; only the type of the time differs. `$nowMilliseconds` is the
     * caller's semantic time in epoch milliseconds, the only time authority of the call; a generated expiry is judged at it.
     *
     * @param string $nowMilliseconds canonical decimal epoch milliseconds, `0` or no leading zero, at most 9007199254740991
     * @throws \Maatify\RateLimiter\Exception\RateLimiterException before any backend command, when `$nowMilliseconds`
     *         is negative (DEC-017), is not a canonical decimal integer, or is above 9007199254740991 (DEC-039)
     * @see CallerTimePunishmentLifecycleStoreInterface::readGenerationBoundScoreStateAt()
     * @see PunishmentLifecycleStoreInterface::readGenerationBoundScoreState()
     */
    public function readGenerationBoundScoreStateAtMilliseconds(string $currentKey, ?string $previousKey, string $nowMilliseconds): ?GenerationBoundScoreStateDTO;

    /**
     * `CallerTimePunishmentLifecycleStoreInterface::mutateGenerationBoundScoreAt()` with the caller's time as a canonical decimal string.
     *
     * Same operation, parameters, return value, atomicity and failure semantics as that method, which extends
     * `PunishmentLifecycleStoreInterface::mutateGenerationBoundScore()`; only the type of the time differs. `$nowMilliseconds` is the
     * caller's semantic time in epoch milliseconds, the only time authority of the call; `updatedAt` is its second and a created generation expires at ceil((now ms + TTL) / 1 s).
     *
     * @param string $nowMilliseconds canonical decimal epoch milliseconds, `0` or no leading zero, at most 9007199254740991
     * @throws \Maatify\RateLimiter\Exception\RateLimiterException before any backend command, when `$nowMilliseconds`
     *         is negative (DEC-017), is not a canonical decimal integer, or is above 9007199254740991 (DEC-039)
     * @see CallerTimePunishmentLifecycleStoreInterface::mutateGenerationBoundScoreAt()
     * @see PunishmentLifecycleStoreInterface::mutateGenerationBoundScore()
     */
    public function mutateGenerationBoundScoreAtMilliseconds(
        string $currentKey,
        ?string $previousKey,
        ?GenerationBoundScoreStateDTO $expectedState,
        int $ttlSeconds,
        int $newValue,
        string $nowMilliseconds,
    ): GenerationBoundScoreMutationDTO;

    /**
     * `CallerTimePunishmentLifecycleStoreInterface::claimPostPunishmentReentryAt()` with the caller's time as a canonical decimal string.
     *
     * Same operation, parameters, return value, atomicity and failure semantics as that method, which extends
     * `PunishmentLifecycleStoreInterface::claimPostPunishmentReentry()`; only the type of the time differs. `$nowMilliseconds` is the
     * caller's semantic time in epoch milliseconds, the only time authority of the call; the claim is judged at it.
     *
     * @param string $nowMilliseconds canonical decimal epoch milliseconds, `0` or no leading zero, at most 9007199254740991
     * @throws \Maatify\RateLimiter\Exception\RateLimiterException before any backend command, when `$nowMilliseconds`
     *         is negative (DEC-017), is not a canonical decimal integer, or is above 9007199254740991 (DEC-039)
     * @see CallerTimePunishmentLifecycleStoreInterface::claimPostPunishmentReentryAt()
     * @see PunishmentLifecycleStoreInterface::claimPostPunishmentReentry()
     */
    public function claimPostPunishmentReentryAtMilliseconds(string $currentKey, ?string $previousKey, string $lifecycleId, string $nowMilliseconds): bool;
}
