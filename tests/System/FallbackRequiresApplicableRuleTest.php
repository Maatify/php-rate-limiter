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
use Maatify\RateLimiter\DTO\FailureStateDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\Enum\FailureFallbackDimensionEnum;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Service\LocalFallbackLimiter;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Study262\OutageRateLimitStore;
use PHPUnit\Framework\TestCase;

/**
 * #283: the process-local fallback may answer "allow" only after at least one rule of the policy's effective fallback configuration
 * has been applied to the request context. When no rule applies (for example an ACCOUNT-only configuration and a request without an
 * account) the answer is the existing conservative refusal, with no window (Retry-After 60), and no counter is consumed.
 *
 * Every scenario runs through the public runtime (`RateLimiterBuilder::build()`) with synthetic data, on both degraded entry paths:
 * the classified backend failure that trips the circuit, and the circuit that is already OPEN or recovering (HALF_OPEN).
 */
final class FallbackRequiresApplicableRuleTest extends TestCase
{
    private const POLICY = 'applicable_rule_policy';
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';
    private const IP = '198.51.100.90';
    private const WINDOW = 600;
    private const NOW = '2025-01-01 12:00:10';
    private const NO_WINDOW_RETRY_AFTER = 60;

    private FixedClock $clock;
    private InMemoryCircuitBreakerStore $circuitStore;

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

    /** @return array<string, array{string}> */
    public static function degradedPaths(): array
    {
        return [
            'classified backend failure that trips the circuit' => ['trip'],
            'open circuit' => ['open'],
            'recovering circuit (half-open)' => ['half_open'],
        ];
    }

    /** @dataProvider degradedPaths */
    #[\PHPUnit\Framework\Attributes\DataProvider('degradedPaths')]
    public function testNoApplicableRuleRefusesConservativelyWithoutConsumingAnyCounter(string $path): void
    {
        // ACCOUNT is the only rule and the request names no account (null, then empty string = absent).
        $runtime = $this->runtime([[FailureFallbackDimensionEnum::ACCOUNT, 5, self::WINDOW]]);
        $this->enterPath($runtime, $path);

        foreach ([null, '', null] as $accountId) {
            $result = $this->limit($runtime, $accountId);

            $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
            $this->assertSame(2, $result->blockLevel);
            $this->assertSame(self::NO_WINDOW_RETRY_AFTER, $result->retryAfter);
            $this->assertSame('DEGRADED_MODE', $result->failureMode);
        }
        $this->assertSame([], $this->counterValues(), 'a request no rule applied to consumes no fallback counter');
    }

    /** @dataProvider degradedPaths */
    #[\PHPUnit\Framework\Attributes\DataProvider('degradedPaths')]
    public function testOneApplicableRuleAmongSeveralStillDecidesWithinAndAfterTheAllowance(string $path): void
    {
        // ACCOUNT does not apply (no account); IP_PREFIX applies and carries the allowance of 2 per 600s window.
        $runtime = $this->runtime([
            [FailureFallbackDimensionEnum::ACCOUNT, 5, self::WINDOW],
            [FailureFallbackDimensionEnum::IP_PREFIX, 2, self::WINDOW],
        ]);
        $this->enterPath($runtime, $path);

        $first = $this->limit($runtime, null);
        $second = $this->limit($runtime, null);
        $third = $this->limit($runtime, null);

        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $first->decision);
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $second->decision);
        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $third->decision);
        $this->assertSame('fallback_limit_exceeded', $third->metadata?->cause);
        // Retry-After is the real remaining fixed window (bucket 12:00:00-12:10:00, now 12:00:10), not the no-window value.
        $this->assertSame($this->remaining(self::WINDOW), $third->retryAfter);
        $this->assertSame([3], $this->counterValues(), 'one rule applied, consumed once per final answer');
    }

    /** @dataProvider degradedPaths */
    #[\PHPUnit\Framework\Attributes\DataProvider('degradedPaths')]
    public function testSingleApplicableRuleAllowsWithinTheQuotaAndRefusesAfterItWithRetryAfter(string $path): void
    {
        $runtime = $this->runtime([[FailureFallbackDimensionEnum::ACCOUNT, 2, self::WINDOW]]);
        $this->enterPath($runtime, $path);

        foreach ([1, 2] as $_call) {
            $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->limit($runtime, 'acct-1')->decision);
        }
        $denied = $this->limit($runtime, 'acct-1');

        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $denied->decision);
        $this->assertSame($this->remaining(self::WINDOW), $denied->retryAfter);
        $this->assertSame([3], $this->counterValues());

        // A different account has its own allowance: the rule applies to it as well.
        $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->limit($runtime, 'acct-2')->decision);
    }

    public function testOfficialPresetValuesAndRetryAfterAreUnchanged(): void
    {
        // Login: ACCOUNT 3 + IP_PREFIX 20 per 600s. The IP rule always applies; account 'acct' allows 3 then denies with the window hint.
        $clock = new FixedClock(self::NOW);
        $this->clock = $clock;
        $this->circuitStore = new InMemoryCircuitBreakerStore();
        $runtime = (new RateLimiterBuilder(
            new RateLimiterConfig('applicable-rule-key', 'applicable-rule-fingerprint', 'prod'),
            new OutageRateLimitStore(true, $clock),
            new StatefulInMemoryCorrelationStore($clock),
            $this->circuitStore,
            new RecordingFailureSignalEmitter(),
        ))->withClock($clock)->withPolicy(new LoginProtectionPolicy())->build();
        $command = RateLimitCommand::checkOnly('login_protection');
        $context = new RateLimitContextDTO(self::IP, self::UA, 'acct');
        for ($i = 0; $i < 3; $i++) {
            $runtime->limit($context, $command); // classified failures: FAIL_CLOSED until the circuit trips on the third
        }
        $this->assertSame(FailureStateDTO::STATE_OPEN, $this->circuitStore->load('login_protection')?->status);
        $this->resetFallbackState();

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(RateLimitResultDTO::DECISION_ALLOW, $runtime->limit($context, $command)->decision);
        }
        $denied = $runtime->limit($context, $command);
        $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $denied->decision);
        $this->assertSame($this->remaining(self::WINDOW), $denied->retryAfter);
        $this->assertSame('fallback_limit_exceeded', $denied->metadata?->cause);
    }

    public function testLimiterEvaluateRefusesWhenNoRuleAppliesAndAllowsOtherwise(): void
    {
        $clock = new FixedClock(self::NOW);
        $accountOnly = $this->policy([[FailureFallbackDimensionEnum::ACCOUNT, 1, self::WINDOW]]);

        $this->assertSame(self::NO_WINDOW_RETRY_AFTER, LocalFallbackLimiter::evaluate($clock, $accountOnly, 'DEGRADED_MODE', self::IP, null, self::UA));
        $this->assertNull(LocalFallbackLimiter::evaluate($clock, $accountOnly, 'DEGRADED_MODE', self::IP, 'acct', self::UA));
        // Outside degraded modes the limiter still imposes nothing.
        $this->assertNull(LocalFallbackLimiter::evaluate($clock, $accountOnly, 'NORMAL', self::IP, null, self::UA));
    }

    /**
     * @param list<array{FailureFallbackDimensionEnum, int, int}> $rules
     */
    private function runtime(array $rules): CompositeRateLimiterRuntimeInterface
    {
        $this->clock = new FixedClock(self::NOW);
        $this->circuitStore = new InMemoryCircuitBreakerStore();

        return (new RateLimiterBuilder(
            new RateLimiterConfig('applicable-rule-key', 'applicable-rule-fingerprint', 'prod'),
            new OutageRateLimitStore(true, $this->clock),
            new StatefulInMemoryCorrelationStore($this->clock),
            $this->circuitStore,
            new RecordingFailureSignalEmitter(),
        ))
            ->withClock($this->clock)
            ->withPolicy($this->policy($rules))
            ->build();
    }

    /**
     * Bring the runtime to the point where the NEXT call is the first call served on the given degraded path.
     *
     * trip:      two classified failures; the next (third) call is the one that trips the circuit and is answered in the same call.
     * open:      the circuit is already OPEN.
     * half_open: the open duration has passed and the (still failing) probe has moved the circuit to HALF_OPEN.
     */
    private function enterPath(CompositeRateLimiterRuntimeInterface $runtime, string $path): void
    {
        $failures = $path === 'trip' ? 2 : 3;
        for ($i = 0; $i < $failures; $i++) {
            $result = $runtime->limit(new RateLimitContextDTO('203.0.113.250', self::UA, 'warmup'), RateLimitCommand::checkOnly(self::POLICY));
            if ($i < 2) {
                // Classified failures before the trip: still fail-closed. The tripping call is answered by the degraded path.
                $this->assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
            }
        }

        if ($path === 'half_open') {
            $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:00:10 +301 seconds'));
            $runtime->limit(new RateLimitContextDTO('203.0.113.250', self::UA, 'warmup'), RateLimitCommand::checkOnly(self::POLICY));
            $this->assertSame(FailureStateDTO::STATE_HALF_OPEN, $this->circuitStore->load(self::POLICY)?->status);
        } elseif ($path === 'open') {
            $this->assertSame(FailureStateDTO::STATE_OPEN, $this->circuitStore->load(self::POLICY)?->status);
        } else {
            $this->assertNotSame(FailureStateDTO::STATE_OPEN, $this->circuitStore->load(self::POLICY)?->status);
        }
        $this->resetFallbackState();
    }

    private function limit(CompositeRateLimiterRuntimeInterface $runtime, ?string $accountId): RateLimitResultDTO
    {
        return $runtime->limit(new RateLimitContextDTO(self::IP, self::UA, $accountId), RateLimitCommand::checkOnly(self::POLICY));
    }

    private function remaining(int $window): int
    {
        $now = $this->clock->now()->getTimestamp();

        return (intdiv($now, $window) + 1) * $window - $now;
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

    /**
     * A custom reusable policy with no capability and a typed fallback configuration: fail-closed while the circuit is closed,
     * degraded (local fallback) once it is OPEN or HALF_OPEN.
     *
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
}
