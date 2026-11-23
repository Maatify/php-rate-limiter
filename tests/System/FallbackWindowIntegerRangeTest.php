<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\FailureFallbackConfigurationProviderInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\DTO\FailureFallbackConfigurationDTO;
use Maatify\RateLimiter\DTO\FailureFallbackRuleDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\Enum\FailureFallbackDimensionEnum;
use Maatify\RateLimiter\Service\LocalFallbackLimiter;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Study262\OutageRateLimitStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #285: the fallback window arithmetic must stay exact when the end of a window lies beyond the platform `int` range. At
 * 2147483647 (2038-01-19T03:14:07Z) a 600-second window ends at 2147484000, which does not fit a 32-bit `int`; the remaining
 * time must still be an exact integer on both integer widths. Every scenario is expressed without any value above PHP_INT_MAX
 * so it runs unchanged on PHP 32-bit and 64-bit.
 */
#[Group('php32')]
final class FallbackWindowIntegerRangeTest extends TestCase
{
    private const POLICY = 'int_range_policy';
    private const IP = '198.51.100.91';
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';
    private const LAST_INT32_SECOND = '@2147483647';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFallbackState();
    }

    protected function tearDown(): void
    {
        $this->resetFallbackState();
        parent::tearDown();
    }

    public function testLoginPolicyRuntimeAnswersExactRetryAfterWhenWindowEndExceedsInt32(): void
    {
        $clock = new FixedClock(self::LAST_INT32_SECOND);
        $circuit = new InMemoryCircuitBreakerStore();
        $runtime = (new RateLimiterBuilder(
            new RateLimiterConfig('int-range-key', 'int-range-fingerprint', 'prod'),
            new OutageRateLimitStore(true, $clock),
            new StatefulInMemoryCorrelationStore($clock),
            $circuit,
            new RecordingFailureSignalEmitter(),
        ))->withClock($clock)->withPolicy(new LoginProtectionPolicy())->build();
        $context = new RateLimitContextDTO(self::IP, self::UA, 'acct');
        $command = RateLimitCommand::checkOnly('login_protection');
        for ($i = 0; $i < 3; $i++) {
            $runtime->limit($context, $command); // classified failures trip the circuit
        }
        $this->resetFallbackState();

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $runtime->limit($context, $command)->decision);
        }
        $denied = $runtime->limit($context, $command);

        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $denied->decision);
        $this->assertSame(2, $denied->blockLevel);
        $this->assertSame(353, $denied->retryAfter);
    }

    public function testNewWindowStartsAtTheLimitAndExpiredWindowIsCollected(): void
    {
        $policy = $this->policy([[FailureFallbackDimensionEnum::ACCOUNT, 2, 600]]);
        // Window [2147482800, 2147483400): its end fits int32 and it is exhausted.
        $clock = new FixedClock('@2147482900');
        $this->assertNull(LocalFallbackLimiter::evaluate($clock, $policy, 'DEGRADED_MODE', self::IP, 'acct', self::UA));
        $this->assertNull(LocalFallbackLimiter::evaluate($clock, $policy, 'DEGRADED_MODE', self::IP, 'acct', self::UA));
        $this->assertSame(500, LocalFallbackLimiter::evaluate($clock, $policy, 'DEGRADED_MODE', self::IP, 'acct', self::UA));

        // Next window [2147483400, 2147484000) has an end beyond int32: the old counter is collected, the new one starts at 1.
        $clock->setNow(new \DateTimeImmutable(self::LAST_INT32_SECOND));
        $this->assertNull(LocalFallbackLimiter::evaluate($clock, $policy, 'DEGRADED_MODE', self::IP, 'acct', self::UA));
        $this->assertSame([1], $this->counterValues());
        $this->assertNull(LocalFallbackLimiter::evaluate($clock, $policy, 'DEGRADED_MODE', self::IP, 'acct', self::UA));
        $this->assertSame(353, LocalFallbackLimiter::evaluate($clock, $policy, 'DEGRADED_MODE', self::IP, 'acct', self::UA));
        // The overflowing window is not collected while it is still current.
        $this->assertSame([3], $this->counterValues());
    }

    public function testLargestWaitAmongRejectingRulesAndEachCounterChargedOnce(): void
    {
        // At 2147483647: 300s window has 53s left, 3600s window has 2753s left, 86400s window has 61953s left (never rejects).
        $policy = $this->policy([
            [FailureFallbackDimensionEnum::ACCOUNT, 1, 300],
            [FailureFallbackDimensionEnum::IP_PREFIX, 1, 3600],
            [FailureFallbackDimensionEnum::IP_PREFIX_NORMALIZED_USER_AGENT, 100, 86400],
        ]);
        $clock = new FixedClock(self::LAST_INT32_SECOND);

        $this->assertNull(LocalFallbackLimiter::evaluate($clock, $policy, 'DEGRADED_MODE', self::IP, 'acct', self::UA));
        $this->assertSame([1, 1, 1], $this->counterValues());

        $retryAfter = LocalFallbackLimiter::evaluate($clock, $policy, 'DEGRADED_MODE', self::IP, 'acct', self::UA);

        $this->assertSame(2753, $retryAfter);
        $this->assertSame([2, 2, 2], $this->counterValues(), 'one evaluation charges each applied rule exactly once');
    }

    public function testWindowStartBelowTheIntRangeStillAnswersTheExactRemainingTime(): void
    {
        // F293-01: at -2147483648 a 3-second window starts at -2147483649, below the 32-bit int range; its end (-2147483646) fits.
        $policy = $this->policy([[FailureFallbackDimensionEnum::ACCOUNT, 1, 3]]);
        $clock = new FixedClock('@-2147483648');

        $this->assertNull(LocalFallbackLimiter::evaluate($clock, $policy, 'DEGRADED_MODE', self::IP, 'acct', self::UA));
        $this->assertSame(2, LocalFallbackLimiter::evaluate($clock, $policy, 'DEGRADED_MODE', self::IP, 'acct', self::UA));
        $this->assertSame([2], $this->counterValues());

        // The next window is a new bucket: the old counter is collected and the allowance starts again.
        $clock->setNow(new \DateTimeImmutable('@-2147483645'));
        $this->assertNull(LocalFallbackLimiter::evaluate($clock, $policy, 'DEGRADED_MODE', self::IP, 'acct', self::UA));
        $this->assertSame([1], $this->counterValues());
    }

    public function testRetryAfterFollowsTheSharedCounterOwnWindowNotTheCurrentRule(): void
    {
        // F293-02: two configurations of one policy name (windows 60 and 90) reach the same bucket-0 counter.
        $first = $this->policy([[FailureFallbackDimensionEnum::ACCOUNT, 1, 60]]);
        $second = $this->policy([[FailureFallbackDimensionEnum::ACCOUNT, 1, 90]]);
        $clock = new FixedClock('@10');

        $this->assertNull(LocalFallbackLimiter::evaluate($clock, $first, 'DEGRADED_MODE', self::IP, 'acct', self::UA));
        $clock->setNow(new \DateTimeImmutable('@40'));

        // The counter was created for the 60-second window (ends at 60), so 20 seconds remain whichever rule reads it.
        $this->assertSame(20, LocalFallbackLimiter::evaluate($clock, $second, 'DEGRADED_MODE', self::IP, 'acct', self::UA));
        $this->assertSame([2], $this->counterValues());
        $clock->setNow(new \DateTimeImmutable('@60'));
        $this->assertNull(LocalFallbackLimiter::evaluate($clock, $second, 'DEGRADED_MODE', self::IP, 'acct', self::UA), 'the counter is collected at its own window end');
    }

    /**
     * @param list<array{FailureFallbackDimensionEnum, int, int}> $rules
     */
    private function policy(array $rules): BlockPolicyInterface
    {
        $configuration = new FailureFallbackConfigurationDTO(array_map(
            static fn(array $rule): FailureFallbackRuleDTO => new FailureFallbackRuleDTO($rule[0], $rule[1], $rule[2]),
            $rules,
        ));

        return new class (self::POLICY, $configuration) implements BlockPolicyInterface, FailureFallbackConfigurationProviderInterface {
            public function __construct(private readonly string $name, private readonly FailureFallbackConfigurationDTO $configuration) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getFailureFallbackConfiguration(): FailureFallbackConfigurationDTO
            {
                return $this->configuration;
            }

            public function getScoreThresholds(): PolicyThresholdsDTO
            {
                return new PolicyThresholdsDTO();
            }

            public function getScoreDeltas(): ScoreDeltasDTO
            {
                return new ScoreDeltasDTO();
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

    /** @return list<int> */
    private function counterValues(): array
    {
        $counters = (new \ReflectionClass(LocalFallbackLimiter::class))->getProperty('counters')->getValue();
        $this->assertIsArray($counters);
        $values = [];
        foreach ($counters as $counter) {
            $this->assertIsArray($counter);
            $this->assertIsInt($counter['count']);
            $values[] = $counter['count'];
        }
        sort($values);

        return $values;
    }

    private function resetFallbackState(): void
    {
        $reflection = new \ReflectionClass(LocalFallbackLimiter::class);
        $reflection->getProperty('counters')->setValue(null, []);
        $reflection->getProperty('trackedSubjects')->setValue(null, []);
        $reflection->getProperty('trackedSubjectExpiries')->setValue(null, []);
    }
}
