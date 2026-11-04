<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\RateLimiterInterface;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-185-11 (#185): a score write is rejected with `RateLimiterException` before it happens when its arithmetic is not
 * representable as a PHP int. On `4e90064` the overflow became a float that a later `(int)` cast narrowed, and the
 * narrowed value was stored. Cases A-D are the LT-185-11 probe on a real Redis through the Builder:
 *
 * - A: access 1 and cost `PHP_INT_MAX` twice; the second request overflowed `decayed + delta` and stored -1.
 * - B: a policy access of 2^33 and cost 2^31 - 1 overflowed `access * cost` and stored -8589934592 with ALLOW.
 * - C: a policy access of `PHP_INT_MAX` and cost 1 twice; overflow at `decayed + delta`, stored -1.
 * - D (control): access 1 and cost 2^31 - 1 twice gives 4294967294 on K1, K2 and K3.
 */
#[Group('f-185-11')]
final class ScoreOverflowRedisTest extends TestCase
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

    private static function policy(int $access): BlockPolicyInterface
    {
        return new class ($access) implements BlockPolicyInterface {
            public function __construct(private readonly int $access) {}

            public function getName(): string
            {
                return 'overflow_probe';
            }

            public function getScoreThresholds(): PolicyThresholdsDTO
            {
                $never = new ScoreThresholdsDTO(PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX);

                return new PolicyThresholdsDTO(k1: $never, k2: $never, k3: $never);
            }

            public function getScoreDeltas(): ScoreDeltasDTO
            {
                return new ScoreDeltasDTO(access: $this->access);
            }

            public function getFailureMode(): string
            {
                return 'FAIL_CLOSED';
            }

            public function getBudgetConfig(): ?BudgetConfigDTO
            {
                return null;
            }
        };
    }

    private function limiter(BlockPolicyInterface $policy): RateLimiterInterface
    {
        return RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('f185-11-key', 'f185-11-fingerprint', 'prod'),
            new RedisFullCapabilityStore($this->executor, 'f185-11'),
            new RecordingFailureSignalEmitter(),
        )->withPolicy($policy)->withClock(new FixedClock('2026-11-04 12:00:00'))->build();
    }

    private static function context(): RateLimitContextDTO
    {
        return new RateLimitContextDTO('198.51.100.20', 'Mozilla/5.0 Chrome/120.0', null);
    }

    /** @return list<string> The stored score values (K1, K2 and K3 for these policies), sorted. */
    private function scores(): array
    {
        $keys = $this->executor->execute(['KEYS', '*:score:*']);
        self::assertIsArray($keys);
        $values = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $value = $this->executor->execute(['HGET', $key, 'value']);
            self::assertIsString($value);
            $values[] = $value;
        }
        sort($values);

        return $values;
    }

    private static function assertRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('A score overflow was not rejected.');
        } catch (BackendFailureException $exception) {
            self::fail('A score overflow raised BackendFailureException: ' . $exception->getMessage());
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('not representable as a PHP integer', $exception->getMessage());
        }
    }

    public function testCaseAAccessOneWithCostPhpIntMaxIsRejectedAtTheSecondWrite(): void
    {
        $limiter = $this->limiter(self::policy(1));
        $limiter->limit(self::context(), RateLimitCommand::recordFailure('overflow_probe', PHP_INT_MAX));
        $max = (string) PHP_INT_MAX;
        self::assertSame([$max, $max, $max], $this->scores(), 'The first charge of PHP_INT_MAX is representable.');

        self::assertRejected(fn() => $limiter->limit(self::context(), RateLimitCommand::recordFailure('overflow_probe', PHP_INT_MAX)));

        self::assertSame([$max, $max, $max], $this->scores(), 'The rejected request wrote no score.');
    }

    public function testCaseBACostCapDoesNotHelpAndTheProductIsRejectedBeforeAnyWrite(): void
    {
        $limiter = $this->limiter(self::policy(2 ** 33));

        self::assertRejected(fn() => $limiter->limit(self::context(), RateLimitCommand::recordFailure('overflow_probe', 2 ** 31 - 1)));

        self::assertSame([], $this->scores(), 'K1, K2 and K3 are absent after the rejection.');
        self::assertSame(0, $this->executor->execute(['DBSIZE']), 'access x cost is rejected before any I/O.');
    }

    public function testCaseCADeltaOfPhpIntMaxWithCostOneIsRejectedAtTheSecondWrite(): void
    {
        $limiter = $this->limiter(self::policy(PHP_INT_MAX));
        $limiter->limit(self::context(), RateLimitCommand::recordFailure('overflow_probe', 1));
        $max = (string) PHP_INT_MAX;
        self::assertSame([$max, $max, $max], $this->scores());

        self::assertRejected(fn() => $limiter->limit(self::context(), RateLimitCommand::recordFailure('overflow_probe', 1)));

        self::assertSame([$max, $max, $max], $this->scores(), 'The rejected request wrote no score.');
    }

    public function testCaseDControlARepresentableChargeIsExact(): void
    {
        $limiter = $this->limiter(self::policy(1));
        $limiter->limit(self::context(), RateLimitCommand::recordFailure('overflow_probe', 2 ** 31 - 1));
        $limiter->limit(self::context(), RateLimitCommand::recordFailure('overflow_probe', 2 ** 31 - 1));

        self::assertSame(['4294967294', '4294967294', '4294967294'], $this->scores());
    }
}
