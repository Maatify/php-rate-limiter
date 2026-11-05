<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\MarkerRace;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\DeviceIdentityResolverInterface;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;

/**
 * F-185-17 (#185, the Lead's adv6/r2): failed logins of one account without a fingerprint (a Host resolver that reports
 * none). Each failure adds k4_failure (3) to K4, and k4_repeated_missing_fp (6) more when the `last_missing_fp` marker
 * shows a previous missing-fingerprint failure within 1800 s. Sequentially: 3, then 3 + 3 + 6 = 12 (HARD_BLOCK L3).
 */
final class MarkerRaceSetup
{
    public const NAMESPACE = 'f185-17';

    public const NOW = 1_793_793_600;

    public static function fail(RedisCommandExecutorInterface $executor, int $at = self::NOW): RateLimitResultDTO
    {
        $resolver = new class implements DeviceIdentityResolverInterface {
            public function resolve(RateLimitContextDTO $context): DeviceIdentityDTO
            {
                return new DeviceIdentityDTO('', 'LOW', false, false, $context->ua);
            }
        };

        return RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('f185-17-key', 'f185-17-fingerprint', 'prod'),
            new RedisFullCapabilityStore($executor, self::NAMESPACE),
            new RecordingFailureSignalEmitter(),
        )->withClock(new FixedClock('@' . $at))->withDeviceIdentityResolver($resolver)->build()->limit(
            new RateLimitContextDTO('198.51.100.61', 'Mozilla/5.0 Chrome/120.0', 'account-r2', null, null, false),
            RateLimitCommand::recordFailure('login_protection'),
        );
    }
}
