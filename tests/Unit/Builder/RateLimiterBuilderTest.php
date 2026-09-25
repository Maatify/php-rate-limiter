<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Builder;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Config\PostPunishmentReentryPolicyInterface;
use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\CircuitBreakerStoreInterface;
use Maatify\RateLimiter\Repository\CorrelationStoreInterface;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\RateLimiter\Service\DeviceIdentityResolverInterface;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\RateLimiterInterface;
use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\BaseOnlyInMemoryRateLimitStore;
use Maatify\SharedCommon\Infrastructure\SystemClock;
use PHPUnit\Framework\TestCase;

final class RateLimiterBuilderTest extends TestCase
{
    private RateLimiterConfig $config;
    private FixedClock $clock;
    private RateLimitStoreInterface $rateLimitStore;
    private CorrelationStoreInterface $correlationStore;
    private CircuitBreakerStoreInterface $circuitBreakerStore;
    private FailureSignalEmitterInterface $failureSignalEmitter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->config = new RateLimiterConfig('key-secret', 'fingerprint-secret', 'prod');
        $this->rateLimitStore = new InMemoryRateLimitStore($this->clock);
        $this->correlationStore = new StatefulInMemoryCorrelationStore($this->clock);
        $this->circuitBreakerStore = new InMemoryCircuitBreakerStore();
        $this->failureSignalEmitter = new RecordingFailureSignalEmitter();
    }

    public function testBuildReturnsInterfaceAndProvidesAllDefaultPolicies(): void
    {
        $engine = $this->builder()->build();

        self::assertInstanceOf(RateLimiterInterface::class, $engine);

        foreach (['login_protection', 'otp_protection', 'api_heavy_protection'] as $policyName) {
            $result = $engine->limit(
                new RateLimitContextDTO('192.0.2.10', 'Mozilla/5.0', 'account-1', ['fp' => 'one']),
                RateLimitCommand::checkOnly($policyName),
            );

            self::assertInstanceOf(RateLimitResultDTO::class, $result, $policyName);
        }

        /** @var array<string, BlockPolicyInterface> $policies */
        $policies = $this->privateProperty($engine, 'policies');
        self::assertSame(
            ['login_protection', 'otp_protection', 'api_heavy_protection'],
            array_keys($policies),
        );
    }

    public function testCustomClockIsSharedByAllClockDependentGraphNodes(): void
    {
        $clock = new FixedClock('2025-02-03 04:05:06');
        $engine = $this->builder()->withClock($clock)->build();

        /** @var EvaluationPipeline $pipeline */
        $pipeline = $this->privateProperty($engine, 'pipeline');
        /** @var CircuitBreaker $circuitBreaker */
        $circuitBreaker = $this->privateProperty($engine, 'circuitBreaker');
        /** @var BudgetTracker $budgetTracker */
        $budgetTracker = $this->privateProperty($pipeline, 'budgetTracker');
        /** @var DecayCalculator $decayCalculator */
        $decayCalculator = $this->privateProperty($pipeline, 'decayCalculator');

        self::assertSame($clock, $this->privateProperty($engine, 'clock'));
        self::assertSame($clock, $this->privateProperty($pipeline, 'clock'));
        self::assertSame($clock, $this->privateProperty($circuitBreaker, 'clock'));
        self::assertSame($clock, $this->privateProperty($budgetTracker, 'clock'));
        self::assertSame($clock, $this->privateProperty($decayCalculator, 'clock'));
    }

    public function testDefaultClockIsUtcSystemClock(): void
    {
        $engine = $this->builder()->build();
        $clock = $this->privateProperty($engine, 'clock');

        self::assertInstanceOf(SystemClock::class, $clock);
        self::assertSame('UTC', $clock->getTimezone()->getName());
    }

    public function testCustomDeviceIdentityResolverIsUsedByTheComposedEngine(): void
    {
        $resolver = new class implements DeviceIdentityResolverInterface {
            public int $calls = 0;

            public function resolve(RateLimitContextDTO $context): DeviceIdentityDTO
            {
                $this->calls++;

                return new DeviceIdentityDTO('custom-fingerprint', 'LOW', false, false, 'custom-ua');
            }
        };

        $engine = $this->builder()->withDeviceIdentityResolver($resolver)->build();
        $engine->limit(
            new RateLimitContextDTO('192.0.2.11', 'ignored', 'account-2'),
            RateLimitCommand::checkOnly('api_heavy_protection'),
        );

        self::assertSame(1, $resolver->calls);
    }

    public function testPolicyWithMatchingNameReplacesOnlyThatDefault(): void
    {
        $replacement = new class extends LoginProtectionPolicy {
            public function getName(): string
            {
                return 'login_protection';
            }
        };

        $engine = $this->builder()->withPolicy($replacement)->build();
        /** @var array<string, BlockPolicyInterface> $policies */
        $policies = $this->privateProperty($engine, 'policies');

        self::assertSame($replacement, $policies['login_protection']);
        self::assertInstanceOf(OtpProtectionPolicy::class, $policies['otp_protection']);
        self::assertInstanceOf(ApiHeavyProtectionPolicy::class, $policies['api_heavy_protection']);
        self::assertCount(3, $policies);
    }

    public function testPolicyWithNewNameIsAddedWithoutRemovingDefaults(): void
    {
        $customPolicy = new class extends ApiHeavyProtectionPolicy {
            public function getName(): string
            {
                return 'custom_api_policy';
            }
        };

        $engine = $this->builder()->withPolicy($customPolicy)->build();
        /** @var array<string, BlockPolicyInterface> $policies */
        $policies = $this->privateProperty($engine, 'policies');

        self::assertSame($customPolicy, $policies['custom_api_policy']);
        self::assertCount(4, $policies);
        self::assertArrayHasKey('login_protection', $policies);
        self::assertArrayHasKey('otp_protection', $policies);
        self::assertArrayHasKey('api_heavy_protection', $policies);
    }

    public function testBuilderExposesOnlyTheLockedPublicSurface(): void
    {
        $publicMethods = array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(RateLimiterBuilder::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        self::assertSame([
            '__construct',
            'fromFullCapabilityStore',
            'withClock',
            'withDeviceIdentityResolver',
            'withPolicy',
            'build',
        ], $publicMethods);
    }

    public function testFullCapabilityNamedConstructorHasTheLockedSignature(): void
    {
        $reflection = new \ReflectionClass(RateLimiterBuilder::class);

        self::assertTrue($reflection->hasMethod('fromFullCapabilityStore'));
        $method = $reflection->getMethod('fromFullCapabilityStore');
        self::assertTrue($method->isStatic());
        $returnType = $method->getReturnType();
        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertFalse($returnType->isBuiltin());
        self::assertContains($returnType->getName(), ['self', RateLimiterBuilder::class]);
        $parameterNames = [
            'config',
            'store',
            'failureSignalEmitter',
        ];
        self::assertSame(
            $parameterNames,
            array_map(
                static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
                $method->getParameters(),
            ),
        );
    }

    public function testBuilderWiresIndependentCurrentAndPreviousGenerationsWithoutCartesianProbing(): void
    {
        $cases = [
            'none' => [null, null, null, null],
            'outer-only' => ['previous-key', null, 'previous-key', null],
            'fingerprint-only' => [null, 'previous-fingerprint', null, 'previous-fingerprint'],
            'both' => ['previous-key', 'previous-fingerprint', 'previous-key', 'previous-fingerprint'],
        ];

        foreach ($cases as $name => [$previousKey, $previousFingerprint, $expectedKey, $expectedFingerprint]) {
            $config = new RateLimiterConfig(
                'active-key',
                'active-fingerprint',
                'prod',
                $previousKey,
                $previousFingerprint,
            );
            $engine = $this->builder($config)->build();
            /** @var EvaluationPipeline $pipeline */
            $pipeline = $this->privateProperty($engine, 'pipeline');
            /** @var DeviceIdentityResolver $resolver */
            $resolver = $this->privateProperty($engine, 'deviceResolver');

            self::assertSame($expectedKey, $this->privateProperty($pipeline, 'previousSecret'), $name);
            /** @var FingerprintHasher|null $previousHasher */
            $previousHasher = $this->privateProperty($resolver, 'previousHasher');
            self::assertSame($expectedFingerprint === null, $previousHasher === null, $name);
            if ($previousHasher !== null) {
                self::assertSame($expectedFingerprint, $this->privateProperty($previousHasher, 'secret'), $name);
            }
        }
    }

    public function testDefaultOptInRegistryFailsFastWithoutLifecycleCapability(): void
    {
        $builder = new RateLimiterBuilder(
            $this->config,
            new BaseOnlyInMemoryRateLimitStore($this->clock),
            $this->correlationStore,
            $this->circuitBreakerStore,
            $this->failureSignalEmitter,
        );

        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('requires PunishmentLifecycleStoreInterface');
        $builder->build();
    }

    public function testRegistryWithoutOptInPoliciesBuildsWithBaseStore(): void
    {
        $baseStore = new BaseOnlyInMemoryRateLimitStore($this->clock);
        $builder = new RateLimiterBuilder(
            $this->config,
            $baseStore,
            $this->correlationStore,
            $this->circuitBreakerStore,
            $this->failureSignalEmitter,
        );
        $nonOptInLogin = new class extends ApiHeavyProtectionPolicy {
            public function getName(): string
            {
                return 'login_protection';
            }
        };
        $nonOptInOtp = new class extends ApiHeavyProtectionPolicy {
            public function getName(): string
            {
                return 'otp_protection';
            }
        };

        $engine = $builder->withPolicy($nonOptInLogin)->withPolicy($nonOptInOtp)->build();
        self::assertInstanceOf(RateLimiterInterface::class, $engine);
    }

    public function testCustomOptInPoliciesRejectInvalidK4AndFailureModeContracts(): void
    {
        $missingK4 = new class implements BlockPolicyInterface, PostPunishmentReentryPolicyInterface {
            public function getName(): string
            {
                return 'missing_k4';
            }
            public function getScoreThresholds(): PolicyThresholdsDTO
            {
                return new PolicyThresholdsDTO();
            }
            public function getScoreDeltas(): \Maatify\RateLimiter\DTO\ScoreDeltasDTO
            {
                return new \Maatify\RateLimiter\DTO\ScoreDeltasDTO(k4_failure: 1);
            }
            public function getFailureMode(): string
            {
                return 'FAIL_CLOSED';
            }
            public function getBudgetConfig(): ?\Maatify\RateLimiter\DTO\BudgetConfigDTO
            {
                return null;
            }
        };
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Must enforce Account (K4) thresholds');
        $this->builder()->withPolicy($missingK4)->build();
    }

    public function testCustomOptInPoliciesRejectNonMonotonicK4AndFailOpen(): void
    {
        $invalidThresholds = new class implements BlockPolicyInterface, PostPunishmentReentryPolicyInterface {
            public function getName(): string
            {
                return 'invalid_thresholds';
            }
            public function getScoreThresholds(): PolicyThresholdsDTO
            {
                return new PolicyThresholdsDTO(k4: new ScoreThresholdsDTO(4, 3, 10));
            }
            public function getScoreDeltas(): \Maatify\RateLimiter\DTO\ScoreDeltasDTO
            {
                return new \Maatify\RateLimiter\DTO\ScoreDeltasDTO(k4_failure: 1);
            }
            public function getFailureMode(): string
            {
                return 'FAIL_CLOSED';
            }
            public function getBudgetConfig(): ?\Maatify\RateLimiter\DTO\BudgetConfigDTO
            {
                return null;
            }
        };
        try {
            $this->builder()->withPolicy($invalidThresholds)->build();
            self::fail('Invalid custom K4 thresholds were accepted.');
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('monotonic', $exception->getMessage());
        }

        $failOpen = new class extends LoginProtectionPolicy implements PostPunishmentReentryPolicyInterface {
            public function getName(): string
            {
                return 'fail_open_opt_in';
            }
            public function getFailureMode(): string
            {
                return 'FAIL_OPEN';
            }
        };
        try {
            $this->builder()->withPolicy($failOpen)->build();
            self::fail('FAIL_OPEN custom opt-in was accepted.');
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('FAIL_OPEN', $exception->getMessage());
        }
    }

    private function builder(?RateLimiterConfig $config = null): RateLimiterBuilder
    {
        return new RateLimiterBuilder(
            $config ?? $this->config,
            $this->rateLimitStore,
            $this->correlationStore,
            $this->circuitBreakerStore,
            $this->failureSignalEmitter,
        );
    }

    private function privateProperty(object $object, string $property): mixed
    {
        return (new \ReflectionProperty($object, $property))->getValue($object);
    }
}
