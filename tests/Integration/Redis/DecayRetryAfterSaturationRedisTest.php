<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\RateLimiterInterface;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-185-14 (#185): from the public API on a real Redis, the built-in `api_heavy_protection` charged with a representable
 * cost whose score needs more seconds of decay than a PHP int holds. On `4e90064` (and on the first F-185-11 head) the
 * request raised `TypeError` from `DecayCalculator::secondsUntilBelowThreshold()` after its write, and every later
 * `checkOnly` on the same scope raised it again. The duration now saturates at `PHP_INT_MAX`.
 */
#[Group('f-185-14')]
final class DecayRetryAfterSaturationRedisTest extends TestCase
{
    private RespRedisCommandExecutor $executor;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->executor = new RespRedisCommandExecutor($host, (int) $port);
        $this->executor->execute(['FLUSHDB']);
    }

    private function limiter(): RateLimiterInterface
    {
        return RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('f185-14-key', 'f185-14-fingerprint', 'prod'),
            new RedisFullCapabilityStore($this->executor, 'f185-14'),
            new RecordingFailureSignalEmitter(),
        )->withClock(new FixedClock('2026-11-04 12:00:00'))->build();
    }

    private static function context(): RateLimitContextDTO
    {
        return new RateLimitContextDTO('198.51.100.40', 'Mozilla/5.0 Chrome/120.0', null);
    }

    public function testAScoreBeyondTheRepresentableDurationSaturatesAndTheNextCheckOnlyDoesNotThrow(): void
    {
        $limiter = $this->limiter();

        $charged = $limiter->limit(self::context(), RateLimitCommand::recordFailure('api_heavy_protection', intdiv(PHP_INT_MAX, 180) + 1));
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $charged->decision);
        self::assertSame(PHP_INT_MAX, $charged->retryAfter, 'The decay duration saturates at PHP_INT_MAX.');

        $next = $limiter->limit(self::context(), RateLimitCommand::checkOnly('api_heavy_protection'));
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $next->decision, 'The scope stays blocked; checkOnly does not throw.');
    }

    public function testControlARepresentableDurationIsUnchanged(): void
    {
        // The value `4e90064` returns for this charge (measured before the change).
        $result = $this->limiter()->limit(self::context(), RateLimitCommand::recordFailure('api_heavy_protection', 1_000_000));

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(299910300, $result->retryAfter);
    }
}
