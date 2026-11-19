<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Study262;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\FailureFallbackConfigurationProviderInterface;
use Maatify\RateLimiter\Config\PolicyCapabilityProviderInterface;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\DTO\FailureFallbackConfigurationDTO;
use Maatify\RateLimiter\DTO\FailureFallbackRuleDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;
use Maatify\RateLimiter\Enum\FailureFallbackDimensionEnum;
use Maatify\RateLimiter\Enum\PolicyCapabilityEnum;
use Maatify\RateLimiter\Service\LocalFallbackLimiter;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;

/**
 * #262 study: one independent public runtime (RateLimiterBuilder) with its own stores, circuit store, emitter and clock, whose only
 * parameters are the environment scope, the policy name and the backend behaviour. Used by the study test and the separate-process control.
 */
final class FallbackStudyRuntime
{
    public const NOW = '2025-01-01 12:00:10';
    public const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36';
    public const IP = '198.51.100.90';
    public const WINDOW = 600;

    public function __construct(
        public readonly CompositeRateLimiterRuntimeInterface $runtime,
        public readonly InMemoryCircuitBreakerStore $circuitStore,
        public readonly string $policyName,
        public readonly string $environmentScope,
        public readonly FixedClock $clock,
    ) {}

    /** @param 'typed'|'untyped'|'healthy' $backend */
    public static function build(string $environmentScope, string $policyName, string $backend, int $ipLimit = 2): self
    {
        $clock = new FixedClock(self::NOW);
        $store = match ($backend) {
            'typed' => new OutageRateLimitStore(true, $clock),
            'untyped' => new OutageRateLimitStore(false, $clock),
            'healthy' => new InMemoryRateLimitStore($clock),
        };
        $circuitStore = new InMemoryCircuitBreakerStore();
        $runtime = (new RateLimiterBuilder(
            new RateLimiterConfig('study-262-key-secret', 'study-262-fingerprint-secret', $environmentScope),
            $store,
            new StatefulInMemoryCorrelationStore($clock),
            $circuitStore,
            new RecordingFailureSignalEmitter(),
        ))
            ->withClock($clock)
            ->withPolicy(self::policy($policyName, $ipLimit))
            ->build();

        return new self($runtime, $circuitStore, $policyName, $environmentScope, $clock);
    }

    public function limit(string $ip = self::IP, ?string $accountId = null): RateLimitResultDTO
    {
        return $this->runtime->limit(new RateLimitContextDTO($ip, self::UA, $accountId), RateLimitCommand::checkOnly($this->policyName));
    }

    /** The circuit state as the circuit store itself holds it, read independently of the runtime (null when never written). */
    public function circuitState(): ?string
    {
        return $this->circuitStore->load($this->policyName)?->status;
    }

    /** @return array{counters: int, countValues: list<int>, populations: int, trackedSubjects: int} read from the limiter's own state */
    public static function fallbackState(): array
    {
        $reflection = new \ReflectionClass(LocalFallbackLimiter::class);
        $counters = $reflection->getProperty('counters')->getValue();
        $tracked = $reflection->getProperty('trackedSubjects')->getValue();
        \assert(\is_array($counters) && \is_array($tracked));
        $values = [];
        foreach ($counters as $counter) {
            \assert(\is_array($counter) && \is_int($counter['count']));
            $values[] = $counter['count'];
        }
        sort($values);
        $subjects = 0;
        foreach ($tracked as $population) {
            \assert(\is_array($population));
            $subjects += \count($population);
        }

        return ['counters' => \count($counters), 'countValues' => $values, 'populations' => \count($tracked), 'trackedSubjects' => $subjects];
    }

    public static function resetFallbackState(): void
    {
        $reflection = new \ReflectionClass(LocalFallbackLimiter::class);
        $reflection->getProperty('counters')->setValue(null, []);
        $reflection->getProperty('trackedSubjects')->setValue(null, []);
        $reflection->getProperty('trackedSubjectExpiries')->setValue(null, []);
    }

    public static function policy(string $name, int $ipLimit): BlockPolicyInterface
    {
        // API_OVERUSE requires both IP dimensions; the UA one is unreachable so the IP-prefix rule is the only active limit.
        $configuration = new FailureFallbackConfigurationDTO([
            new FailureFallbackRuleDTO(FailureFallbackDimensionEnum::IP_PREFIX, $ipLimit, self::WINDOW),
            new FailureFallbackRuleDTO(FailureFallbackDimensionEnum::IP_PREFIX_NORMALIZED_USER_AGENT, 1_000_000, self::WINDOW),
        ]);

        return new class ($name, $configuration) implements BlockPolicyInterface, PolicyCapabilityProviderInterface, FailureFallbackConfigurationProviderInterface {
            public function __construct(private readonly string $name, private readonly FailureFallbackConfigurationDTO $configuration) {}

            public function getName(): string
            {
                return $this->name;
            }

            /** @return list<PolicyCapabilityEnum> */
            public function getCapabilities(): array
            {
                return [PolicyCapabilityEnum::API_OVERUSE];
            }

            public function getFailureFallbackConfiguration(): FailureFallbackConfigurationDTO
            {
                return $this->configuration;
            }

            public function getScoreThresholds(): PolicyThresholdsDTO
            {
                return new PolicyThresholdsDTO(k1: new ScoreThresholdsDTO(1000, 1000, 1000), k2: new ScoreThresholdsDTO(1000, 1000, 1000), k3: new ScoreThresholdsDTO(1, 1, 1));
            }

            public function getScoreDeltas(): ScoreDeltasDTO
            {
                return new ScoreDeltasDTO(access: 1);
            }

            public function getFailureMode(): string
            {
                return 'FAIL_OPEN';
            }

            public function getBudgetConfig(): ?BudgetConfigDTO
            {
                return null;
            }
        };
    }
}
