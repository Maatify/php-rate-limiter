<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;

/**
 * Decimal-millisecond mirror of `CallerTimePunishmentLifecycleStoreInterface` (#215, DEC-039).
 *
 * Additive, optional capability. `CallerTimePunishmentLifecycleStoreInterface` takes the caller's time in
 * milliseconds as a native `int`, which cannot hold a current epoch value where `PHP_INT_SIZE`
 * is 4. A store that implements this interface receives the exact value as a canonical decimal string
 * (`0` or no leading zero, at most 9007199254740991) through the separately named
 * method, and the runtime prefers it. A store that implements only `CallerTimePunishmentLifecycleStoreInterface` keeps
 * its behaviour and is called with the native integer when the value fits, otherwise the runtime
 * throws a `RateLimiterException` before calling it. A store that implements neither keeps its own clock.
 */
interface CallerTimeDecimalMillisecondsPunishmentLifecycleStoreInterface extends CallerTimePunishmentLifecycleStoreInterface
{
    /** `readGenerationBoundScoreStateAt()` with `$nowMilliseconds` as a canonical decimal string. */
    public function readGenerationBoundScoreStateAtMilliseconds(string $currentKey, ?string $previousKey, string $nowMilliseconds): ?GenerationBoundScoreStateDTO;

    /** `mutateGenerationBoundScoreAt()` with `$nowMilliseconds` as a canonical decimal string. */
    public function mutateGenerationBoundScoreAtMilliseconds(
        string $currentKey,
        ?string $previousKey,
        ?GenerationBoundScoreStateDTO $expectedState,
        int $ttlSeconds,
        int $newValue,
        string $nowMilliseconds,
    ): GenerationBoundScoreMutationDTO;

    /** `claimPostPunishmentReentryAt()` with `$nowMilliseconds` as a canonical decimal string. */
    public function claimPostPunishmentReentryAtMilliseconds(string $currentKey, ?string $previousKey, string $lifecycleId, string $nowMilliseconds): bool;
}
