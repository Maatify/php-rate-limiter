<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Service;

use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\E1StoreBinding;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FullCapability\FullCapabilityInMemoryStore;
use PHPUnit\Framework\TestCase;

/** Negative composition protection of the closed advanced constructor (DEC-046). */
#[\PHPUnit\Framework\Attributes\Group('php32')]
final class ClosedAccountCompositionTest extends TestCase
{
    public function testAnInjectedBudgetTrackerOnAnotherResourceIsRejectedByTheSignature(): void
    {
        $clock = new FixedClock('2026-01-01 12:00:00');
        $root = new FullCapabilityInMemoryStore($clock);
        $foreign = new FullCapabilityInMemoryStore($clock);
        $this->expectException(\TypeError::class);
        (new \ReflectionClass(EvaluationPipeline::class))->newInstanceArgs([$root, $root, new BudgetTracker($foreign, $clock), new AntiEquilibriumGate($root), new DecayCalculator($clock), null, 'secret', 'prod', $clock]);
    }

    public function testAnAccountViewCannotBePassedAsTheRoot(): void
    {
        $clock = new FixedClock('2026-01-01 12:00:00');
        $root = new FullCapabilityInMemoryStore($clock);
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('distinct, stable, idempotent');
        E1StoreBinding::capture($root->e1Keyspace());
    }

    public function testAClosedBindingCannotBeReusedForAnotherRoot(): void
    {
        $clock = new FixedClock('2026-01-01 12:00:00');
        $root = new FullCapabilityInMemoryStore($clock);
        $binding = E1StoreBinding::capture($root);
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('another root');
        $binding->forRoot(new FullCapabilityInMemoryStore($clock));
    }

    public function testTheCapturedHandleIsReusedWithoutAnotherViewLookup(): void
    {
        $clock = new FixedClock('2026-01-01 12:00:00');
        $root = new class ($clock) extends FullCapabilityInMemoryStore {
            public int $lookups = 0;
            public function e1Keyspace(): static
            {
                $this->lookups++;
                return parent::e1Keyspace();
            }
        };
        $binding = E1StoreBinding::capture($root);
        $lookups = $root->lookups;
        self::assertSame($binding->view, $binding->forRoot($root));
        self::assertSame($lookups, $root->lookups);
    }

    public function testThePipelineClosesStoresWhileRetainingEachExplicitClockRole(): void
    {
        $evaluationClock = new FixedClock('2026-01-01 12:00:00');
        $budgetClock = new FixedClock('2025-01-01 12:00:00');
        $root = new FullCapabilityInMemoryStore($evaluationClock);
        $pipeline = new EvaluationPipeline($root, $root, new DecayCalculator($evaluationClock), 'secret', 'prod', $evaluationClock, $budgetClock, null, null);
        $budget = (new \ReflectionProperty($pipeline, 'budgetTracker'))->getValue($pipeline);
        self::assertInstanceOf(BudgetTracker::class, $budget);
        $key = 'e1.' . str_repeat('a', 64);
        $root->e1Keyspace()->incrementBudgetAt($key, 86400, 3, $budgetClock->now()->getTimestamp());
        self::assertSame(3, $budget->getStatus($key)->count, 'The budget does not use the later evaluation clock.');
        $gate = (new \ReflectionProperty($pipeline, 'antiEquilibriumGate'))->getValue($pipeline);
        $bucket = (new \ReflectionProperty($pipeline, 'ephemeralBucket'))->getValue($pipeline);
        self::assertInstanceOf(AntiEquilibriumGate::class, $gate);
        self::assertInstanceOf(\Maatify\RateLimiter\Service\EphemeralBucket::class, $bucket);
        self::assertNull((new \ReflectionProperty($gate, 'clock'))->getValue($gate));
        self::assertNull((new \ReflectionProperty($bucket, 'clock'))->getValue($bucket));
        self::assertSame($root, (new \ReflectionProperty($bucket, 'store'))->getValue($bucket));
        self::assertSame($root->e1Keyspace(), (new \ReflectionProperty($gate, 'store'))->getValue($gate));
    }

    public function testExtraDeriverAndRemovedNamedArgumentsAreRejectedBeforeComposition(): void
    {
        $clock = new FixedClock('2026-01-01 12:00:00');
        $root = new FullCapabilityInMemoryStore($clock);
        $args = [$root, $root, new DecayCalculator($clock), 'secret', 'prod', $clock, $clock, null, null, null];
        $constructor = new \ReflectionClass(EvaluationPipeline::class);
        foreach ([array_merge($args, [new \stdClass()]), array_merge($args, ['budgetTracker' => new BudgetTracker($root, $clock)]), array_merge($args, ['accountKeys' => new \stdClass()])] as $invalid) {
            try {
                $constructor->newInstanceArgs($invalid);
                self::fail('Removed injection must be rejected.');
            } catch (\Error|RateLimiterException $failure) {
                self::assertNotSame('', $failure->getMessage());
            }
        }
        self::assertSame(0, $root->writeCount());
    }

    public function testSelfReturningAndUnstableAdaptersAreRejectedWithoutWrites(): void
    {
        $clock = new FixedClock('2026-01-01 12:00:00');
        $selfReturning = new class ($clock) extends FullCapabilityInMemoryStore {
            public function e1Keyspace(): static
            {
                return $this;
            }
        };
        $unstable = new class ($clock) extends FullCapabilityInMemoryStore {
            public function e1Keyspace(): static
            {
                return clone $this;
            }
        };
        foreach ([$selfReturning, $unstable] as $root) {
            try {
                E1StoreBinding::capture($root);
                self::fail('Invalid structural identity must fail.');
            } catch (RateLimiterException $failure) {
                self::assertStringContainsString('distinct, stable, idempotent', $failure->getMessage());
            }
            self::assertSame(0, $root->writeCount());
        }
    }

    public function testPreflightPolicyValuesAreFrozenAndHostReentryIsProtected(): void
    {
        $clock = new FixedClock('2026-01-01 12:00:00');
        $root = new FullCapabilityInMemoryStore($clock);
        $pipeline = new EvaluationPipeline($root, $root, new DecayCalculator($clock), 'secret', 'prod', $clock, $clock, null, null);
        $context = new \Maatify\RateLimiter\DTO\RateLimitContextDTO('203.0.113.1', 'browser', 'account');
        $device = new \Maatify\RateLimiter\DTO\DeviceIdentityDTO(null, 'LOW', false, false, 'browser');
        $policy = new class extends \Maatify\RateLimiter\Config\LoginProtectionPolicy {
            public int $reads = 0;
            public function getScoreDeltas(): \Maatify\RateLimiter\DTO\ScoreDeltasDTO
            {
                if (++$this->reads > 1) {
                    throw new \LogicException('Policy was re-read after preflight.');
                }
                return parent::getScoreDeltas();
            }
        };
        $pipeline->process($policy, $context, \Maatify\RateLimiter\Command\RateLimitCommand::recordFailure($policy->getName()), $device);
        self::assertSame(1, $policy->reads);
        $reentrant = new class ($pipeline, $context, $device) extends \Maatify\RateLimiter\Config\LoginProtectionPolicy {
            public function __construct(private EvaluationPipeline $pipeline, private \Maatify\RateLimiter\DTO\RateLimitContextDTO $context, private \Maatify\RateLimiter\DTO\DeviceIdentityDTO $device) {}
            public function getScoreDeltas(): \Maatify\RateLimiter\DTO\ScoreDeltasDTO
            {
                $this->pipeline->process(new \Maatify\RateLimiter\Config\LoginProtectionPolicy(), $this->context, \Maatify\RateLimiter\Command\RateLimitCommand::checkOnly('login_protection'), $this->device);
                return parent::getScoreDeltas();
            }
        };
        $writes = $root->writeCount();
        try {
            $pipeline->process($reentrant, $context, \Maatify\RateLimiter\Command\RateLimitCommand::checkOnly('login_protection'), $device);
            self::fail('Preflight Host code cannot reenter the graph.');
        } catch (\Maatify\RateLimiter\Exception\OverlappingRequestException) {
            self::assertSame($writes, $root->writeCount());
        }
    }

    public function testReaderDoesNotAcquireAnUnusedCorrelationView(): void
    {
        $clock = new FixedClock('2026-01-01 12:00:00');
        $rate = new FullCapabilityInMemoryStore($clock);
        $correlation = $this->createMock(\Maatify\RateLimiter\Repository\CorrelationStoreInterface::class);
        $correlation->expects(self::never())->method('addDistinct');
        $builder = new \Maatify\RateLimiter\Builder\RateLimiterBuilder(
            new \Maatify\RateLimiter\Config\RateLimiterConfig('secret', 'fp', 'prod'),
            $rate,
            $correlation,
            new \Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore(),
            new \Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter(),
        );
        $reader = $builder->withClock($clock)->buildOperationalReader();
        self::assertNull($reader->readScorePolicy(new \Maatify\RateLimiter\DTO\RateLimitContextDTO('203.0.113.1', 'browser', 'account'), 'login_protection')->scopes->k4?->score);
    }

    public function testAnE1CapableStoreStillNeedsTheCapabilitiesOfTheActualProcessingPath(): void
    {
        $clock = new FixedClock('2026-01-01 12:00:00');
        $rate = new \Maatify\RateLimiter\Tests\Support\RateLimiter\BaseOnlyInMemoryRateLimitStore($clock);
        $correlation = new FullCapabilityInMemoryStore($clock);
        $pipeline = new EvaluationPipeline($rate, $correlation, new DecayCalculator($clock), 'secret', 'prod', $clock, $clock, null, null);
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('HardBlockCycleStoreInterface');
        $pipeline->process(new \Maatify\RateLimiter\Config\LoginProtectionPolicy(), new \Maatify\RateLimiter\DTO\RateLimitContextDTO('203.0.113.1', 'browser', 'account'), \Maatify\RateLimiter\Command\RateLimitCommand::checkOnly('login_protection'), new \Maatify\RateLimiter\DTO\DeviceIdentityDTO(null, 'LOW', false, false, 'browser'));
    }

    public function testTheCapturedGateRetainsBackendAndCallerTimeDispatch(): void
    {
        foreach ([false, true] as $callerTime) {
            $clock = new class ('2026-01-01 12:00:00') extends FixedClock {
                public int $reads = 0;
                public function now(): \DateTimeImmutable
                {
                    $this->reads++;
                    return parent::now();
                }
            };
            $root = new class ($clock) extends FullCapabilityInMemoryStore {
                /** @var list<int|null> */
                public array $watchTimes = [];
                public function incrementWatchFlag(string $key, int $ttlSeconds): int
                {
                    $this->watchTimes[] = null;
                    return 1;
                }
                public function incrementWatchFlagAt(string $key, int $ttlSeconds, int $now): int
                {
                    $this->watchTimes[] = $now;
                    return 1;
                }
            };
            $pipeline = new EvaluationPipeline($root, $root, new DecayCalculator($clock), 'secret', 'prod', $clock, $clock, $callerTime ? $clock : null, null);
            $gate = (new \ReflectionProperty($pipeline, 'antiEquilibriumGate'))->getValue($pipeline);
            self::assertInstanceOf(AntiEquilibriumGate::class, $gate);
            $gate->recordSoftBlock('e1.' . str_repeat('a', 64));
            self::assertSame([$callerTime ? 1767268800 : null], $root->watchTimes);
            self::assertSame($callerTime ? 1 : 0, $clock->reads);
        }
    }

    public function testAWellTypedCustomViewStillHasToProveCancellationConformance(): void
    {
        $clock = new FixedClock('2026-01-01 12:00:00');
        $bad = new class ($clock) extends FullCapabilityInMemoryStore implements \Maatify\RateLimiter\Repository\RequestAtomicStoreInterface {
            /** Deliberately broken: a legal signature with no rollback of the separately cloned view's state. */
            public function transactRequest(callable $evaluation): mixed
            {
                return $evaluation();
            }
        };
        $binding = E1StoreBinding::capture($bad); // Structural checks cannot inspect Host transaction semantics.
        $view = $binding->forRoot($bad);
        self::assertInstanceOf(FullCapabilityInMemoryStore::class, $view);
        $key = 'e1.' . str_repeat('a', 64);
        try {
            $bad->transactRequest(static function () use ($view, $key): void {
                $view->increment($key, 600);
                throw new \RuntimeException('cancel');
            });
        } catch (\RuntimeException $failure) {
            self::assertSame('cancel', $failure->getMessage());
        }
        self::assertNotNull($view->get($key), 'Negative conformance control: the cancelled account write leaked. Such an adapter is unsupported despite passing structural checks.');
        self::assertNull($bad->get($key));
    }

    public function testReaderBindingIncludesAnActualRequestScopedClock(): void
    {
        $clock = new \Maatify\RateLimiter\Service\RequestScopedClock(new FixedClock());
        $first = new FullCapabilityInMemoryStore(new FixedClock());
        $second = new FullCapabilityInMemoryStore(new FixedClock());
        $config = new \Maatify\RateLimiter\Config\RateLimiterConfig('secret', 'fp', 'prod');
        $emitter = new \Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter();
        $a = \Maatify\RateLimiter\Builder\RateLimiterBuilder::fromFullCapabilityStore($config, $first, $emitter)
            ->withExecutionContextProvider(new \Maatify\RateLimiter\Tests\Support\Ownership\StubContextProvider(static fn(): string => 'context'))->withClock($clock);
        $reader = $a->buildOperationalReader();
        $b = \Maatify\RateLimiter\Builder\RateLimiterBuilder::fromFullCapabilityStore($config, $second, $emitter)
            ->withExecutionContextProvider(new \Maatify\RateLimiter\Tests\Support\Ownership\StubContextProvider(static fn(): string => 'context'))->withClock($clock);
        try {
            $b->buildOperationalReader();
            self::fail('A shared request clock cannot be rebound to another provider.');
        } catch (RateLimiterException) {
            self::assertNotSame([], get_class_methods($reader));
        }
        $b->withClock(new \Maatify\RateLimiter\Service\RequestScopedClock(new FixedClock()))->buildOperationalReader();
    }

    public function testTheReaderCannotAcceptAnOldDeriverOrExtraArguments(): void
    {
        $clock = new FixedClock();
        $root = new FullCapabilityInMemoryStore($clock);
        $args = [new \Maatify\RateLimiter\Service\DeviceIdentityResolver(new \Maatify\RateLimiter\Service\FingerprintHasher('fp')), $root, $root, new DecayCalculator($clock), $clock, 'secret', 'prod', null];
        foreach ([array_merge($args, [new \Maatify\RateLimiter\Tests\Fixtures\KeyScopeV2Reference('foreign')]), array_merge($args, [null, new \stdClass()]), array_merge($args, ['accountKeys' => new \stdClass()])] as $invalid) {
            try {
                (new \ReflectionClass(\Maatify\RateLimiter\Service\RateLimitOperationalReader::class))->newInstanceArgs($invalid);
                self::fail('Old derivation injection must be refused.');
            } catch (\Error $failure) {
                self::assertNotSame('', $failure->getMessage());
            }
        }
        self::assertFalse(enum_exists('Maatify\\RateLimiter\\Enum\\KeyScopeEncodingEnum'));
        self::assertFalse(interface_exists('Maatify\\RateLimiter\\Service\\AccountScopeKeyDeriverInterface'));
        self::assertFalse(class_exists('Maatify\\RateLimiter\\Service\\V2AccountScopeKeyDeriver'));
    }

    public function testANonIdempotentViewIsRejectedBeforeStorage(): void
    {
        $root = $this->createMock(FullCapabilityInMemoryStore::class);
        $view = $this->createMock(FullCapabilityInMemoryStore::class);
        $root->expects(self::never())->method('get');
        $view->expects(self::never())->method('get');
        $root->method('e1Keyspace')->willReturn($view);
        $view->method('e1Keyspace')->willReturn($root);
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('distinct, stable, idempotent');
        E1StoreBinding::capture($root);
    }

    public function testANonStaticAdapterReturnFailsAtItsPhpBoundary(): void
    {
        $root = $this->createMock(\Maatify\RateLimiter\Repository\E1KeyspaceStoreInterface::class);
        $root->method('e1Keyspace')->willReturnCallback(static fn(): FullCapabilityInMemoryStore => new FullCapabilityInMemoryStore(new FixedClock()));
        $this->expectException(\TypeError::class);
        E1StoreBinding::capture($root);
    }

    public function testTheCapturedBucketRetainsBackendAndCallerTimeAcrossRotation(): void
    {
        foreach ([false, true] as $callerTime) {
            foreach ([false, true] as $rotation) {
                $roleClock = new class ('2026-01-01 12:00:00') extends FixedClock {
                    public int $reads = 0;
                    public function now(): \DateTimeImmutable
                    {
                        $this->reads++;
                        return parent::now();
                    }
                };
                $clock = new FixedClock('2025-01-01 12:00:00');
                $root = new class ($clock) extends FullCapabilityInMemoryStore {
                    /** @var list<string> */
                    public array $calls = [];
                    public function addDistinctBounded(string $key, string $item, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO
                    {
                        $this->calls[] = 'current:backend';
                        return new \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO(1, true);
                    }
                    public function addDistinctBoundedAt(string $key, string $item, int $ttlSeconds, int $maxDistinct, int $now): \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO
                    {
                        $this->calls[] = 'current:' . $now;
                        return new \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO(1, true);
                    }
                    public function addDistinctBoundedAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct): \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO
                    {
                        $this->calls[] = 'rotation:backend';
                        return new \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO(1, true);
                    }
                    public function addDistinctBoundedAcrossRotationAt(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct, int $nowMilliseconds): \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO
                    {
                        $this->calls[] = 'rotation:' . $nowMilliseconds;
                        return new \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO(1, true);
                    }
                    public function addDistinctBoundedAcrossRotationAtMilliseconds(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct, string $nowMilliseconds): \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO
                    {
                        $this->calls[] = 'rotation:' . $nowMilliseconds;
                        return new \Maatify\RateLimiter\DTO\BoundedDistinctResultDTO(1, true);
                    }
                };
                $pipeline = new EvaluationPipeline($root, $root, new DecayCalculator($clock), 'secret', 'prod', $clock, $clock, null, $callerTime ? $roleClock : null);
                $bucket = (new \ReflectionProperty($pipeline, 'ephemeralBucket'))->getValue($pipeline);
                self::assertInstanceOf(\Maatify\RateLimiter\Service\EphemeralBucket::class, $bucket);
                $ip = new \Maatify\RateLimiter\DTO\BoundedCorrelationObservationDTO('ip', 'member', $rotation ? 'ip-old' : null, $rotation ? 'member-old' : null, $rotation ? 'ip-bridge' : null);
                $account = new \Maatify\RateLimiter\DTO\BoundedCorrelationObservationDTO('account', 'member', $rotation ? 'account-old' : null, $rotation ? 'member-old' : null, $rotation ? 'account-bridge' : null);
                self::assertFalse($bucket->check($ip, $account)->isEphemeral);
                $expected = ($rotation ? 'rotation:' : 'current:') . ($callerTime ? ($rotation ? '1767268800000' : '1767268800') : 'backend');
                self::assertSame([$expected, $expected], $root->calls);
                self::assertSame($callerTime ? 2 : 0, $roleClock->reads);
            }
        }
    }

    public function testBuilderReaderNeedsNoMutationOnlyCapabilityOrCorrelationView(): void
    {
        $root = $this->createMockForIntersectionOfInterfaces([
            \Maatify\RateLimiter\Repository\OperationalReadOnlyStoreInterface::class,
            \Maatify\RateLimiter\Repository\E1KeyspaceStoreInterface::class,
        ]);
        $view = clone $root;
        $root->method('e1Keyspace')->willReturn($view);
        $view->method('e1Keyspace')->willReturn($view);
        $root->expects(self::never())->method('increment');
        $view->expects(self::never())->method('increment');
        self::assertInstanceOf(\Maatify\RateLimiter\Repository\RateLimitStoreInterface::class, $root);
        self::assertNotInstanceOf(\Maatify\RateLimiter\Repository\HardBlockCycleStoreInterface::class, $root);
        self::assertNotInstanceOf(\Maatify\RateLimiter\Repository\SnapshotFencedScoreStoreInterface::class, $root);
        $builder = new \Maatify\RateLimiter\Builder\RateLimiterBuilder(
            new \Maatify\RateLimiter\Config\RateLimiterConfig('secret', 'fp', 'prod'),
            $root,
            $this->createStub(\Maatify\RateLimiter\Repository\CorrelationStoreInterface::class),
            new \Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore(),
            new \Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter(),
        );
        $reader = $builder->withClock(new FixedClock())->buildOperationalReader();
        self::assertNull($reader->readScorePolicy(new \Maatify\RateLimiter\DTO\RateLimitContextDTO('203.0.113.1', 'browser', 'account'), 'login_protection')->scopes->k4?->score);
    }
}
