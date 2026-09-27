<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Builder;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitOperationalSnapshotDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\CircuitBreakerStoreInterface;
use Maatify\RateLimiter\Repository\CorrelationStoreInterface;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\RateLimiter\Service\CompositeRateLimitOperationalReaderInterface;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Service\DeviceIdentityResolverInterface;
use Maatify\RateLimiter\Service\RateLimitOperationalReaderInterface;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\BaseOnlyInMemoryRateLimitStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

final class RateLimiterBuilderOperationalReaderTest extends TestCase
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

    public function testBuildOperationalReaderReturnsTheCompositeOperationalReaderContract(): void
    {
        $reader = $this->builder()->buildOperationalReader();

        self::assertInstanceOf(CompositeRateLimitOperationalReaderInterface::class, $reader);
    }

    public function testExistingBuildReturnContractRemainsUnchanged(): void
    {
        self::assertInstanceOf(CompositeRateLimiterRuntimeInterface::class, $this->builder()->build());
    }

    public function testExistingOperationalReaderInterfaceReadSignatureRemainsUnchanged(): void
    {
        $method = (new \ReflectionClass(RateLimitOperationalReaderInterface::class))->getMethod('read');

        $parameters = array_map(
            static fn(\ReflectionParameter $parameter): string => (string) $parameter->getType() . ' $' . $parameter->getName(),
            $method->getParameters(),
        );

        self::assertSame([
            'Maatify\\RateLimiter\\DTO\\RateLimitContextDTO $context',
            'Maatify\\RateLimiter\\Config\\BlockPolicyInterface $policy',
        ], $parameters);
        self::assertSame(
            'Maatify\\RateLimiter\\DTO\\RateLimitOperationalSnapshotDTO',
            (string) $method->getReturnType(),
        );
    }

    public function testCustomClockIsUsedByTheOperationalGraph(): void
    {
        $clock = new FixedClock('2025-02-03 04:05:06');
        $reader = $this->builder()->withClock($clock)->buildOperationalReader();

        $snapshot = $reader->readScorePolicy(
            new RateLimitContextDTO('192.0.2.10', 'Mozilla/5.0'),
            'login_protection',
        );

        self::assertSame($clock->now()->getTimestamp(), $snapshot->observedAt);

        $simpleSnapshot = $this->builder()
            ->withClock($clock)
            ->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('checkout', 3, 60))
            ->buildOperationalReader()
            ->readSimpleThrottle('checkout', 'subject-1');

        self::assertSame($clock->now()->getTimestamp(), $simpleSnapshot->observedAt);
    }

    public function testCustomDeviceResolverIsUsedByScoreOperationalReads(): void
    {
        $resolver = new class implements DeviceIdentityResolverInterface {
            public int $calls = 0;

            public function resolve(RateLimitContextDTO $context): DeviceIdentityDTO
            {
                $this->calls++;

                return new DeviceIdentityDTO('custom-fingerprint', 'LOW', false, false, 'custom-ua');
            }
        };

        $reader = $this->builder()->withDeviceIdentityResolver($resolver)->buildOperationalReader();
        $reader->readScorePolicy(new RateLimitContextDTO('192.0.2.11', 'ignored'), 'api_heavy_protection');

        self::assertSame(1, $resolver->calls);
    }

    public function testSameNameScorePolicyReplacementIsTheExactPolicyUsedByReadScorePolicy(): void
    {
        $replacement = new class extends LoginProtectionPolicy {
            public function getName(): string
            {
                return 'login_protection';
            }
        };

        $reader = $this->builder()->withPolicy($replacement)->buildOperationalReader();
        /** @var array<string, \Maatify\RateLimiter\Config\BlockPolicyInterface> $policies */
        $policies = $this->privateProperty($reader, 'scorePolicies');

        self::assertSame($replacement, $policies['login_protection']);
    }

    public function testDifferentlyNamedCustomScorePolicyIsDiscoverableByName(): void
    {
        $customPolicy = new class extends ApiHeavyProtectionPolicy {
            public function getName(): string
            {
                return 'custom_policy';
            }
        };

        $reader = $this->builder()->withPolicy($customPolicy)->buildOperationalReader();
        $snapshot = $reader->readScorePolicy(new RateLimitContextDTO('192.0.2.12', 'Mozilla/5.0'), 'custom_policy');

        self::assertInstanceOf(RateLimitOperationalSnapshotDTO::class, $snapshot);
        self::assertSame('custom_policy', $snapshot->policyName);
    }

    public function testUnknownScorePolicyRaisesRateLimiterException(): void
    {
        $reader = $this->builder()->buildOperationalReader();

        $this->expectException(RateLimiterException::class);
        $reader->readScorePolicy(new RateLimitContextDTO('192.0.2.13', 'Mozilla/5.0'), 'never_registered');
    }

    public function testSameNameSimplePolicyReplacementIsReflectedInReadSimpleThrottle(): void
    {
        $original = new FixedWindowThrottlePolicy('checkout', 1, 60);
        $replacement = new FixedWindowThrottlePolicy('checkout', 5, 60);

        $reader = $this->builder()
            ->withSimpleThrottlePolicy($original)
            ->withSimpleThrottlePolicy($replacement)
            ->buildOperationalReader();

        $snapshot = $reader->readSimpleThrottle('checkout', 'subject-1');

        self::assertSame(5, $snapshot->limit);
    }

    public function testDifferentlyNamedSimplePolicyIsDiscoverable(): void
    {
        $reader = $this->builder()
            ->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('checkout', 3, 60))
            ->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('search', 10, 30))
            ->buildOperationalReader();

        $snapshot = $reader->readSimpleThrottle('search', 'subject-1');

        self::assertSame(10, $snapshot->limit);
        self::assertSame(30, $snapshot->intervalSeconds);
    }

    public function testUnknownSimplePolicyRaisesRateLimiterException(): void
    {
        $reader = $this->builder()->buildOperationalReader();

        $this->expectException(RateLimiterException::class);
        $reader->readSimpleThrottle('never_registered', 'subject-1');
    }

    /**
     * Read-only construction must not require capabilities that Operational
     * Read never invokes solely to construct the read surface. This store
     * only implements the base RateLimitStoreInterface — build() would fail
     * fast on it (PunishmentLifecycleStoreInterface/HardBlockCycleStoreInterface
     * are absent), but buildOperationalReader() must not.
     */
    public function testReadOnlyConstructionDoesNotRequireMutationOnlyCapabilities(): void
    {
        $builder = new RateLimiterBuilder(
            $this->config,
            new BaseOnlyInMemoryRateLimitStore($this->clock),
            $this->correlationStore,
            $this->circuitBreakerStore,
            $this->failureSignalEmitter,
        );

        $reader = $builder->buildOperationalReader();

        self::assertInstanceOf(CompositeRateLimitOperationalReaderInterface::class, $reader);

        $snapshot = $reader->readScorePolicy(new RateLimitContextDTO('192.0.2.14', 'Mozilla/5.0'), 'login_protection');
        self::assertInstanceOf(RateLimitOperationalSnapshotDTO::class, $snapshot);
    }

    private function builder(): RateLimiterBuilder
    {
        return new RateLimiterBuilder(
            $this->config,
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
