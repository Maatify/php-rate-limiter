<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\BlockRace;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;

/**
 * F-185-16 (#185, the Lead's adv6/r1): two known-device failures of one trusted session on `login_protection`, K5 = 8 (the
 * L1 threshold). Sequentially the first reaches 10 (L1, 15 s) and the second 12 (L2, 60 s).
 */
final class BlockRaceSetup
{
    public const NAMESPACE = 'f185-16';

    public const NOW = '2026-11-04 12:00:00';

    public static function fail(RedisCommandExecutorInterface $executor, string $at = self::NOW): RateLimitResultDTO
    {
        return RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('f185-16-key', 'f185-16-fingerprint', 'prod'),
            new RedisFullCapabilityStore($executor, self::NAMESPACE),
            new RecordingFailureSignalEmitter(),
        )->withClock(new FixedClock($at))->build()->limit(
            new RateLimitContextDTO('198.51.100.60', 'Mozilla/5.0 Chrome/120.0', 'account-r1', null, 'session-device-1', true),
            RateLimitCommand::recordFailure('login_protection'),
        );
    }
}
