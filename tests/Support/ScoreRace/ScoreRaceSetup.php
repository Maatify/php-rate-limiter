<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\ScoreRace;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\RateLimiterInterface;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;

/**
 * F-185-08 (#185): the shared scenario of the score race. One trusted session of account `account-1` fails a login;
 * `login_protection` scores K5 (+2) and K1 (`k1_spray`, +5). The race starts at `NOW`, 1500 s after a first failure, so
 * both scores are fully decayed (K5 every 300 s, K1 every 180 s) and every write of the race carries a decay credit.
 */
final class ScoreRaceSetup
{
    public const NAMESPACE = 'f185-08';

    public const NOW = '2026-11-04 12:00:00';

    public const AGED = '2026-11-04 11:35:00';

    public static function limiter(RedisCommandExecutorInterface $executor, string $at): RateLimiterInterface
    {
        return RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('f185-08-key', 'f185-08-fingerprint', 'prod'),
            new RedisFullCapabilityStore($executor, self::NAMESPACE),
            new RecordingFailureSignalEmitter(),
        )->withClock(new FixedClock($at))->build();
    }

    public static function context(): RateLimitContextDTO
    {
        return new RateLimitContextDTO('198.51.100.60', 'Mozilla/5.0 Chrome/120.0', 'account-1', null, 'session-device-1', true);
    }

    public static function fail(RateLimiterInterface $limiter): void
    {
        $limiter->limit(self::context(), RateLimitCommand::recordFailure('login_protection'));
    }
}
