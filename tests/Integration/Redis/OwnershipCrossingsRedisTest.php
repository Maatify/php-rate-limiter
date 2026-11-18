<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Fiber;
use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FailureModeResolver;
use Maatify\RateLimiter\Service\FixedWindowSimpleRateLimiter;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\RateLimiter\Service\DeviceIdentityResolverInterface;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\OwnershipRegistry;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Ownership\BindingProbe;
use Maatify\RateLimiter\Tests\Support\Ownership\BuiltDuringBuild;
use Maatify\RateLimiter\Tests\Support\Ownership\CrossingProbe;
use Maatify\RateLimiter\Tests\Support\Ownership\ProbeClock;
use Maatify\RateLimiter\Tests\Support\Ownership\ReentrantSimplePolicy;
use Maatify\RateLimiter\Tests\Support\Ownership\StubContextProvider;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\SharedCommon\Contracts\ClockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #260 (DEC-041), the corrections of the review of `c9eb4dd5` (F266-10..14): Host crossings that were not marked (policy getters, the
 * store's own clock before its guard, the readers' clock and device resolver), the failed reader build that left a provider binding, and
 * the inventory that trusted a method name. Every refusal is identified by the class name as a string so that the same file runs against
 * the reviewed source, where each of these re-entries was accepted and reached the executor (a PING), and fails for that reason.
 */
#[Group('integration')]
#[Group('fiber-ownership-260')]
#[Group('php32')]
final class OwnershipCrossingsRedisTest extends TestCase
{
    private const REFUSAL = 'Maatify\\RateLimiter\\Exception\\OverlappingRequestException';

    private string $host;

    private int $port;

    /** @var list<array<mixed>> every command that reached an executor of this test */
    private array $commands = [];

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->host = $host;
        $this->port = (int) $port;
        (new RespRedisCommandExecutor($host, $this->port))->execute(['FLUSHDB']);
        $this->commands = [];
    }

    private function store(?ClockInterface $clock = null, ?\Closure $before = null): RedisFullCapabilityStore
    {
        $connection = new RespRedisCommandExecutor($this->host, $this->port);

        return new RedisFullCapabilityStore(new CallableRedisCommandExecutor(function (array $command) use ($connection, $before): mixed {
            $this->commands[] = $command;
            if ($before !== null) {
                $before($command);
            }

            return $connection->execute($command);
        }), 'crossing-260', $clock);
    }

    private function pings(): int
    {
        return count(array_filter($this->commands, static fn(array $command): bool => ($command[0] ?? null) === 'PING'));
    }

    private function config(): RateLimiterConfig
    {
        return new RateLimiterConfig('test_secret', 'test_fp', 'prod');
    }

    private function builder(RedisFullCapabilityStore $store, ?ClockInterface $clock = null): RateLimiterBuilder
    {
        return RateLimiterBuilder::fromFullCapabilityStore($this->config(), $store, new RecordingFailureSignalEmitter())->withClock($clock ?? new FixedClock('@1000000'));
    }

    private function refusalOf(callable $call): ?string
    {
        try {
            $call();
        } catch (\Throwable $caught) {
            return $caught::class;
        }

        return null;
    }

    // ---- F266-10: policy callbacks are Host code ---------------------------------------------------------------------------------------

    public function testASimpleThrottlePolicyThatReentersTheStoreIsRefusedBeforeTheExecutorAndTheOrdinaryPolicyRuns(): void
    {
        $store = $this->store();
        $policy = new ReentrantSimplePolicy();
        $runtime = $this->builder($store)->withSimpleThrottlePolicy($policy)->build();

        self::assertTrue($runtime->consume('api', 'control')->allowed, 'an ordinary policy is consumed');
        $this->commands = [];

        $policy->reenter = static fn() => $store->isHealthy();
        self::assertSame(self::REFUSAL, $this->refusalOf(fn() => $runtime->consume('api', 'subject')));
        self::assertSame(0, $this->pings(), 'the re-entrant health check never reached the executor');
        self::assertFalse(OwnershipRegistry::isOccupied($store), 'the refused call released everything');

        $policy->reenter = null;
        self::assertTrue($runtime->consume('api', 'again')->allowed, 'the runtime serves the next call');
    }

    public function testAScorePolicyThatReentersTheStoreFromAGetterIsRefusedBeforeTheExecutor(): void
    {
        $probe = new CrossingProbe();
        $policy = new class ($probe) extends LoginProtectionPolicy {
            public function __construct(private readonly CrossingProbe $probe) {}

            public function getScoreDeltas(): ScoreDeltasDTO
            {
                $this->probe->fire();

                return parent::getScoreDeltas();
            }
        };
        $store = $this->store();
        $probe->store = $store;
        $runtime = $this->builder($store)->withPolicy($policy)->build();
        $context = new RateLimitContextDTO(ip: '198.51.100.7', ua: 'crossing-260', accountId: 'acct-a');

        $runtime->limit($context, RateLimitCommand::checkOnly('login_protection'));
        $this->commands = [];

        $probe->armed = true;
        self::assertSame(self::REFUSAL, $this->refusalOf(fn() => $runtime->limit($context, RateLimitCommand::recordFailure('login_protection'))));
        self::assertSame(0, $this->pings(), 'the policy getter never reached the executor through the library');
        self::assertFalse(OwnershipRegistry::isOccupied($store));

        $probe->armed = false;
        $runtime->limit($context, RateLimitCommand::checkOnly('login_protection'));
        self::assertGreaterThan(0, count($this->commands), 'the runtime serves the next call');
    }

    public function testAScorePolicyThresholdGetterReadByThePipelineCannotReenterTheStore(): void
    {
        $probe = new CrossingProbe();
        $policy = new class ($probe) extends LoginProtectionPolicy {
            public function __construct(private readonly CrossingProbe $probe) {}

            public function getScoreThresholds(): PolicyThresholdsDTO
            {
                $this->probe->fire();

                return parent::getScoreThresholds();
            }
        };
        $store = $this->store();
        $probe->store = $store;
        $runtime = $this->builder($store)->withPolicy($policy)->build();
        $context = new RateLimitContextDTO(ip: '198.51.100.7', ua: 'crossing-260', accountId: 'acct-a');

        $runtime->limit($context, RateLimitCommand::checkOnly('login_protection'));
        $this->commands = [];

        $probe->armed = true;
        self::assertSame(self::REFUSAL, $this->refusalOf(fn() => $runtime->limit($context, RateLimitCommand::recordFailure('login_protection'))));
        self::assertSame(0, $this->pings(), 'the pipeline getter never reached the executor through the library');
        self::assertFalse(OwnershipRegistry::isOccupied($store));
    }

    // ---- F266-11: the store's own clock is read under its guard -------------------------------------------------------------------------

    public function testAnIncrementOverAnOwnedStoreIsRefusedBeforeItsClockIsRead(): void
    {
        $probe = new CrossingProbe();
        $store = $this->store(new ProbeClock($probe), static function () use ($probe): void {
            $probe->suspendHere();
        });
        $fiber = new Fiber(fn() => $store->get('held'));
        $probe->suspendIn = $fiber;
        $fiber->start();
        self::assertFalse($fiber->isTerminated());

        $before = $probe->reads;
        $other = new Fiber(fn() => $store->increment('k', 60));
        $outcome = null;
        try {
            $other->start();
        } catch (\Throwable $caught) {
            $outcome = $caught::class;
        }

        self::assertSame(self::REFUSAL, $outcome);
        self::assertSame($before, $probe->reads, 'the refused call read the Host clock zero times');
        $probe->suspendIn = null;
        $fiber->resume();
        self::assertTrue($fiber->isTerminated());
    }

    public function testACallIsRefusedWhileAnotherIsSuspendedInsideTheStoresOwnClock(): void
    {
        $probe = new CrossingProbe();
        $store = $this->store(new ProbeClock($probe));
        $a = new Fiber(fn() => $store->increment('k', 60));
        $probe->suspendIn = $a;
        $a->start();
        self::assertFalse($a->isTerminated(), 'call A is suspended inside the store clock');

        $outcome = null;
        $b = new Fiber(fn() => $store->isHealthy());
        try {
            $b->start();
        } catch (\Throwable $caught) {
            $outcome = $caught::class;
        }

        self::assertSame(self::REFUSAL, $outcome, 'the store was owned from before its clock was read');
        self::assertSame(0, $this->pings());
        $probe->suspendIn = null;
        $a->resume();
        self::assertTrue($a->isTerminated());
        self::assertFalse(OwnershipRegistry::isOccupied($store));
    }

    public function testTheStoreClockOfTheSecondsAndOfTheMillisecondsPathsCannotReenterTheStore(): void
    {
        $probe = new CrossingProbe();
        $store = $this->store(new ProbeClock($probe));
        $probe->store = $store;
        $store->increment('control', 60);

        $probe->armed = true;
        $this->commands = [];
        self::assertSame(self::REFUSAL, $this->refusalOf(fn() => $store->increment('k', 60)), 'seconds path');
        self::assertSame(self::REFUSAL, $this->refusalOf(fn() => $store->addDistinctAcrossRotation('a', 'b', 'c', 'm1', 'm0', 60)), 'milliseconds path');
        self::assertSame(0, $this->pings());
        self::assertSame([], $this->commands, 'no command was sent by either refused call');

        $probe->armed = false;
        self::assertSame(2, $store->increment('control', 60), 'the store serves the next call with its own clock authority');
        self::assertFalse(OwnershipRegistry::isOccupied($store));
    }

    // ---- F266-12: the readers' clock and device resolver --------------------------------------------------------------------------------

    public function testTheSimpleReaderClockCannotReenterTheStoreAndAnOrdinaryReadStaysReadOnly(): void
    {
        $probe = new CrossingProbe();
        $store = $this->store();
        $probe->store = $store;
        $reader = $this->builder($store, new ProbeClock($probe))->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('api', 5, 60))->buildOperationalReader();

        $snapshot = $reader->readSimpleThrottle('api', 'subject');
        self::assertSame(0, $snapshot->count);
        $writes = array_filter($this->commands, static fn(array $command): bool => ! in_array($command[0] ?? null, ['PING', 'EVAL', 'EVALSHA'], true));
        self::assertSame([], array_values($writes), 'an ordinary read sends no write command');

        $probe->armed = true;
        $this->commands = [];
        self::assertSame(self::REFUSAL, $this->refusalOf(fn() => $reader->readSimpleThrottle('api', 'subject')));
        self::assertSame(0, $this->pings(), 'the clock never reached the executor through the library');
        self::assertFalse(OwnershipRegistry::isOccupied($store));
    }

    public function testTheScoreReaderDeviceResolverCannotReenterTheStore(): void
    {
        $probe = new CrossingProbe();
        $resolver = new class (new DeviceIdentityResolver(new FingerprintHasher('test_fp')), $probe) implements DeviceIdentityResolverInterface {
            public function __construct(private readonly DeviceIdentityResolver $inner, private readonly CrossingProbe $probe) {}

            public function resolve(RateLimitContextDTO $context): DeviceIdentityDTO
            {
                $this->probe->fire();

                return $this->inner->resolve($context);
            }
        };
        $store = $this->store();
        $probe->store = $store;
        $reader = $this->builder($store)->withDeviceIdentityResolver($resolver)->buildOperationalReader();
        $context = new RateLimitContextDTO(ip: '198.51.100.7', ua: 'crossing-260', accountId: 'acct-a');

        $reader->readScorePolicy($context, 'login_protection');

        $probe->armed = true;
        $this->commands = [];
        self::assertSame(self::REFUSAL, $this->refusalOf(fn() => $reader->readScorePolicy($context, 'login_protection')));
        self::assertSame(0, $this->pings());
        self::assertFalse(OwnershipRegistry::isOccupied($store));
    }

    // ---- F266-13: a failed reader build leaves no binding of its own ---------------------------------------------------------------------

    public function testAFailedReaderBuildBindsNothingAndAnExistingBindingIsKept(): void
    {
        $p = new StubContextProvider(static fn() => 1);
        $q = new StubContextProvider(static fn() => 2);
        $failing = new ReentrantSimplePolicy('broken', 0);

        $store = $this->store();
        self::assertSame('unbound', OwnershipRegistry::domainKind($store));
        try {
            $this->builder($store)->withExecutionContextProvider($p)->withSimpleThrottlePolicy($failing)->buildOperationalReader();
            self::fail('the reader build must fail on the policy of limit 0');
        } catch (RateLimiterException) {
        }
        self::assertSame('unbound', OwnershipRegistry::domainKind($store), 'the failed build left nothing bound');

        $reader = $this->builder($store)->withExecutionContextProvider($q)->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('api', 5, 60))->buildOperationalReader();
        self::assertSame(0, $reader->readSimpleThrottle('api', 's')->count, 'a later valid build in another domain succeeds');
        self::assertSame('provider', OwnershipRegistry::domainKind($store));

        // A binding that existed before the failing build stays exactly as it was.
        $bound = $this->store();
        OwnershipRegistry::bindAll([$bound], $p);
        try {
            $this->builder($bound)->withExecutionContextProvider($p)->withSimpleThrottlePolicy($failing)->buildOperationalReader();
            self::fail('the reader build must fail');
        } catch (RateLimiterException) {
        }
        self::assertSame('provider', OwnershipRegistry::domainKind($bound), 'the previous binding is unchanged');
    }

    // ---- F266-15: a build that fails never takes the binding of a runtime built meanwhile from Host code -----------------------------------

    /** @return array<string, array{bool}> */
    public static function buildPaths(): array
    {
        return ['build()' => [false], 'buildOperationalReader()' => [true]];
    }

    #[DataProvider('buildPaths')]
    public function testAFailedBuildLeavesTheBindingOfARuntimeBuiltMeanwhileFromHostCode(bool $reader): void
    {
        $p = new StubContextProvider(static fn() => 1);
        $store = $this->store();
        $held = new BuiltDuringBuild();
        $failing = new ReentrantSimplePolicy('broken', 0);
        $failing->reenter = function () use ($held, $store, $p, $reader, $failing): void {
            $failing->reenter = null;
            $other = $this->builder($store)->withExecutionContextProvider($p)->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('b', 5, 60));
            if ($reader) {
                $held->reader = $other->buildOperationalReader();
                $held->reader->readSimpleThrottle('b', 'first');
            } else {
                $held->runtime = $other->build();
                $held->runtime->consume('b', 'first');
            }
        };

        try {
            $first = $this->builder($store)->withExecutionContextProvider($p)->withSimpleThrottlePolicy($failing);
            $reader ? $first->buildOperationalReader() : $first->build();
            self::fail('the first build must fail on the policy of limit 0');
        } catch (RateLimiterException) {
        }

        self::assertSame('provider', OwnershipRegistry::domainKind($store), 'the binding the built runtime stands on is kept');
        $this->commands = [];
        if ($reader) {
            self::assertNotNull($held->reader, 'the Host code built a reader during the first build');
            self::assertSame(0, $held->reader->readSimpleThrottle('b', 'after')->count, 'the reader built meanwhile is valid after the failure');
        } else {
            self::assertNotNull($held->runtime, 'the Host code built a runtime during the first build');
            self::assertTrue($held->runtime->consume('b', 'after')->allowed, 'the runtime built meanwhile is valid after the failure');
        }
        self::assertGreaterThan(0, count($this->commands), 'it reached the executor');
    }

    // ---- F266-17: the lifetime of a binding and the rollback of a failed build --------------------------------------------------------

    #[DataProvider('buildPaths')]
    public function testAFailedBuildLeavesTheBindingOfAnEntryBuiltDirectlyFromHostCode(bool $reader): void
    {
        $p = new StubContextProvider(static fn() => 1);
        $store = $this->store();
        $held = new BuiltDuringBuild();
        $failing = new ReentrantSimplePolicy('broken', 0);
        $failing->reenter = function () use ($held, $store, $failing): void {
            $failing->reenter = null;
            // Not the Builder: an entry class built by hand over the same store, from inside the getter.
            $held->direct = new FixedWindowSimpleRateLimiter([new FixedWindowThrottlePolicy('b', 5, 60)], $store, new FixedClock('@1000000'), 'secret', 'prod');
            self::assertTrue($held->direct->consume('b', 'first')->allowed, 'the entry built directly inherits the provider and reaches the executor');
        };

        try {
            $first = $this->builder($store)->withExecutionContextProvider($p)->withSimpleThrottlePolicy($failing);
            $reader ? $first->buildOperationalReader() : $first->build();
            self::fail('the first build must fail on the policy of limit 0');
        } catch (RateLimiterException) {
        }

        self::assertNotNull($held->direct);
        self::assertSame('provider', OwnershipRegistry::domainKind($store), 'the entry built directly stands on the binding');
        $this->commands = [];
        self::assertTrue($held->direct->consume('b', 'after')->allowed, 'it is still valid after the first build failed');
        self::assertGreaterThan(0, count($this->commands), 'it reached the executor');
    }

    #[DataProvider('buildPaths')]
    public function testAFailedReplacementOfAVacatedBindingRestoresTheVacatedStateAndALaterBuildSucceeds(bool $reader): void
    {
        $store = $this->store();
        (function () use ($store): void {
            $first = new StubContextProvider(static fn() => 1);
            $this->builder($store)->withExecutionContextProvider($first)->build();
            self::assertSame('provider', OwnershipRegistry::domainKind($store));
        })();
        gc_collect_cycles();
        self::assertSame('vacated', OwnershipRegistry::domainKind($store), 'the first graph and its provider are gone');

        $q = new StubContextProvider(static fn() => 2); // stays alive at the Host
        try {
            $second = $this->builder($store)->withExecutionContextProvider($q)->withSimpleThrottlePolicy(new ReentrantSimplePolicy('broken', 0));
            $reader ? $second->buildOperationalReader() : $second->build();
            self::fail('the build over the vacated binding must fail on the policy of limit 0');
        } catch (RateLimiterException) {
        }

        self::assertSame('vacated', OwnershipRegistry::domainKind($store), 'the failed replacement is undone, the provider that is alive at the Host does not keep the store');
        $this->commands = [];
        try {
            $store->isHealthy();
            self::fail('a direct call on the vacated binding is rejected');
        } catch (RateLimiterException $failure) {
            self::assertStringContainsString('gone', $failure->getMessage());
        }
        self::assertSame([], $this->commands, 'the rejected direct call sent nothing');

        $third = new StubContextProvider(static fn() => 3);
        $runtime = $this->builder($store)->withExecutionContextProvider($third)->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('c', 5, 60))->build();
        self::assertTrue($runtime->consume('c', 'ok')->allowed, 'a sound build with another provider succeeds on the vacated store');
        self::assertSame('provider', OwnershipRegistry::domainKind($store));
    }

    #[DataProvider('buildPaths')]
    public function testAnActiveDirectCallKeepsItsDomainUntilItsReleaseThenTheOverdueRollbackHappens(bool $reader): void
    {
        $p = new StubContextProvider(static fn() => 1);
        $store = $this->store();
        $held = new BuiltDuringBuild();
        $failing = new ReentrantSimplePolicy('broken', 0);
        $failing->reenter = function () use ($held, $store, $failing): void {
            $failing->reenter = null;
            $held->fiber = new Fiber(fn() => $store->transactRequest(static function (): int {
                Fiber::suspend('inside the direct transaction');

                return 1;
            }));
            $held->fiber->start();
        };

        try {
            $first = $this->builder($store)->withExecutionContextProvider($p)->withSimpleThrottlePolicy($failing);
            $reader ? $first->buildOperationalReader() : $first->build();
            self::fail('the first build must fail on the policy of limit 0');
        } catch (RateLimiterException) {
        }

        self::assertNotNull($held->fiber);
        self::assertFalse($held->fiber->isTerminated(), 'the direct call is still active when the build fails');
        self::assertSame('provider', OwnershipRegistry::domainKind($store), 'the active call keeps its domain until its release');
        self::assertTrue(OwnershipRegistry::isOccupied($store));

        $held->fiber->resume();
        self::assertTrue($held->fiber->isTerminated());
        self::assertFalse(OwnershipRegistry::isOccupied($store));
        self::assertSame('unbound', OwnershipRegistry::domainKind($store), 'the overdue rollback happens at the last release');

        $q = new StubContextProvider(static fn() => 2);
        $runtime = $this->builder($store)->withExecutionContextProvider($q)->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('c', 5, 60))->build();
        self::assertTrue($runtime->consume('c', 'ok')->allowed, 'a later build with another provider succeeds');
    }

    // ---- F266-18: the build state follows the build, not the process ---------------------------------------------------------------

    #[DataProvider('buildPaths')]
    public function testABuildSuspendedInAFiberNeverChangesTheRollbackOfAnIndependentBuild(bool $reader): void
    {
        $pa = new StubContextProvider(static fn() => 1);
        $q = new StubContextProvider(static fn() => 2);
        $storeA = $this->store();
        $storeB = $this->store();
        $suspendingPolicy = new ReentrantSimplePolicy('a', 5);
        $suspendingPolicy->reenter = static function () use ($suspendingPolicy): void {
            $suspendingPolicy->reenter = null;
            Fiber::suspend('inside a policy getter of a build');
        };
        $fiberA = new Fiber(function () use ($storeA, $pa, $suspendingPolicy, $reader): mixed {
            $a = $this->builder($storeA)->withExecutionContextProvider($pa)->withSimpleThrottlePolicy($suspendingPolicy);

            return $reader ? $a->buildOperationalReader() : $a->build();
        });
        $fiberA->start();
        self::assertFalse($fiberA->isTerminated(), 'build A is suspended inside its Host getter');

        // Build B, on the main stack, over a store and a provider of its own, fails at the policy of limit 0.
        try {
            $b = $this->builder($storeB)->withExecutionContextProvider($q)->withSimpleThrottlePolicy(new ReentrantSimplePolicy('broken', 0));
            $reader ? $b->buildOperationalReader() : $b->build();
            self::fail('build B must fail on the policy of limit 0');
        } catch (RateLimiterException) {
        }
        self::assertSame('unbound', OwnershipRegistry::domainKind($storeB), 'the failed independent build left nothing bound, whatever build A is doing');

        $other = new StubContextProvider(static fn() => 3);
        $ok = $this->builder($storeB)->withExecutionContextProvider($other)->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('c', 5, 60));
        $reader ? $ok->buildOperationalReader() : $ok->build();
        self::assertSame('provider', OwnershipRegistry::domainKind($storeB), 'a sound build with another provider succeeds');

        $fiberA->resume();
        self::assertTrue($fiberA->isTerminated(), 'build A resumes and completes');
        self::assertSame('provider', OwnershipRegistry::domainKind($storeA));
    }

    #[DataProvider('buildPaths')]
    public function testABuildStartedFromAGetterOfAnotherBuildOnTheSameStackKnowsItsOwnComponents(bool $reader): void
    {
        $pa = new StubContextProvider(static fn() => 1);
        $q = new StubContextProvider(static fn() => 2);
        $storeA = $this->store();
        $storeB = $this->store();
        $outcome = new BuiltDuringBuild();
        $nesting = new ReentrantSimplePolicy('a', 5);
        $nesting->reenter = function () use ($nesting, $storeB, $q, $reader, $outcome): void {
            $nesting->reenter = null;
            // The getter of build A builds, with the Builder, a graph of its own that fails.
            try {
                $b = $this->builder($storeB)->withExecutionContextProvider($q)->withSimpleThrottlePolicy(new ReentrantSimplePolicy('broken', 0));
                $reader ? $b->buildOperationalReader() : $b->build();
                self::fail('the nested build must fail');
            } catch (RateLimiterException) {
            }
            // The same stack builds a second graph that succeeds and works at once.
            $ok = $this->builder($storeB)->withExecutionContextProvider($q)->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('c', 5, 60));
            if ($reader) {
                $outcome->reader = $ok->buildOperationalReader();
                $outcome->reader->readSimpleThrottle('c', 'first');
            } else {
                $outcome->runtime = $ok->build();
                $outcome->runtime->consume('c', 'first');
            }
        };

        $a = $this->builder($storeA)->withExecutionContextProvider($pa)->withSimpleThrottlePolicy($nesting);
        $reader ? $a->buildOperationalReader() : $a->build();

        self::assertSame('provider', OwnershipRegistry::domainKind($storeA));
        self::assertSame('provider', OwnershipRegistry::domainKind($storeB), 'the nested graph that succeeded keeps its binding');
        $this->commands = [];
        if ($reader) {
            self::assertNotNull($outcome->reader);
            self::assertSame(0, $outcome->reader->readSimpleThrottle('c', 'after')->count);
        } else {
            self::assertNotNull($outcome->runtime);
            self::assertTrue($outcome->runtime->consume('c', 'after')->allowed);
        }
        self::assertGreaterThan(0, count($this->commands));
    }

    #[DataProvider('buildPaths')]
    public function testANestedFailedBuildOnTheSameStackLeavesItsStoreUnbound(bool $reader): void
    {
        $pa = new StubContextProvider(static fn() => 1);
        $q = new StubContextProvider(static fn() => 2);
        $storeA = $this->store();
        $storeB = $this->store();
        $kind = null;
        $nesting = new ReentrantSimplePolicy('a', 5);
        $nesting->reenter = function () use ($nesting, $storeB, $q, $reader, &$kind): void {
            $nesting->reenter = null;
            try {
                $b = $this->builder($storeB)->withExecutionContextProvider($q)->withSimpleThrottlePolicy(new ReentrantSimplePolicy('broken', 0));
                $reader ? $b->buildOperationalReader() : $b->build();
            } catch (RateLimiterException) {
            }
            $kind = OwnershipRegistry::domainKind($storeB);
        };

        $a = $this->builder($storeA)->withExecutionContextProvider($pa)->withSimpleThrottlePolicy($nesting);
        $reader ? $a->buildOperationalReader() : $a->build();

        self::assertSame('unbound', $kind, 'the nested failed build undid the binding of its own components while build A was still running');
        self::assertSame('unbound', OwnershipRegistry::domainKind($storeB));
    }

    // ---- F266-19: the registry keeps a bounded history -----------------------------------------------------------------------------

    public function testManySuccessiveGraphsOnOneStoreKeepABoundedNumberOfBindingRecords(): void
    {
        $store = $this->store();
        $weak = [];
        for ($i = 0; $i < 128; $i++) {
            (function () use ($store, &$weak): void {
                $provider = new StubContextProvider(static fn() => 1);
                $weak[] = \WeakReference::create($provider);
                $this->builder($store)->withExecutionContextProvider($provider)->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('c', 5, 60))->build();
            })();
            gc_collect_cycles();
        }

        self::assertSame(128, count(array_filter($weak, static fn(\WeakReference $reference): bool => $reference->get() === null)), 'every provider was collected');
        self::assertSame('vacated', OwnershipRegistry::domainKind($store));
        self::assertLessThanOrEqual(1, BindingProbe::records($store), 'one record, the current binding, is reachable from the store');
    }

    // ---- F266-20 follow-up: the lifetime of an adoption ----------------------------------------------------------------------------

    #[DataProvider('buildPaths')]
    public function testAGraphWhoseStoreCallbackPointsBackToItIsCollectedWithItsStoreProviderAndAdoptionRecords(bool $reader): void
    {
        $weak = [];
        gc_collect_cycles(); // garbage of earlier tests must not be collected inside the measurement
        $recordsBefore = BindingProbe::adoptionRecords();
        (function () use (&$weak, $reader, $recordsBefore): void {
            $holder = new BuiltDuringBuild();
            $provider = new StubContextProvider(static fn() => 1);
            // The callback that belongs to the store points back to the graph through the holder.
            $store = $this->store(null, static function () use ($holder): void {
                $holder->noop();
            });
            $builder = $this->builder($store)->withExecutionContextProvider($provider)->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('c', 5, 60));
            if ($reader) {
                $holder->reader = $builder->buildOperationalReader();
                $holder->reader->readSimpleThrottle('c', 'first');
                $weak['graph'] = \WeakReference::create($holder->reader);
            } else {
                $holder->runtime = $builder->build();
                $holder->runtime->consume('c', 'first');
                $weak['graph'] = \WeakReference::create($holder->runtime);
            }
            $weak['store'] = \WeakReference::create($store);
            $weak['provider'] = \WeakReference::create($provider);
            self::assertGreaterThan($recordsBefore, BindingProbe::adoptionRecords(), 'the graph recorded the adoptions of its entry classes');
        })();
        gc_collect_cycles();

        foreach ($weak as $name => $reference) {
            self::assertNull($reference->get(), "the $name was collected once the Host dropped its references");
        }
        self::assertSame($recordsBefore, BindingProbe::adoptionRecords(), 'the adoption records disappeared with their entry classes');
    }

    #[DataProvider('buildPaths')]
    public function testAnEntryBuiltFromAGetterThatDiedBeforeTheFailureHoldsNothingAnyMore(bool $reader): void
    {
        $p = new StubContextProvider(static fn() => 1);
        $q = new StubContextProvider(static fn() => 2); // alive at the Host
        $store = $this->store();
        $failing = new ReentrantSimplePolicy('broken', 0);
        $failing->reenter = function () use ($store, $failing): void {
            $failing->reenter = null;
            // An entry class built by hand from the getter, used for nothing and dropped before the build fails.
            (static function () use ($store): void {
                new FixedWindowSimpleRateLimiter([new FixedWindowThrottlePolicy('b', 5, 60)], $store, new FixedClock('@1000000'), 'secret', 'prod');
            })();
        };

        try {
            $first = $this->builder($store)->withExecutionContextProvider($p)->withSimpleThrottlePolicy($failing);
            $reader ? $first->buildOperationalReader() : $first->build();
            self::fail('the first build must fail on the policy of limit 0');
        } catch (RateLimiterException) {
        }

        self::assertSame('unbound', OwnershipRegistry::domainKind($store), 'nothing stands on the binding any more: the entry that did is gone');
        $ok = $this->builder($store)->withExecutionContextProvider($q)->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('c', 5, 60));
        $reader ? $ok->buildOperationalReader() : $ok->build();
        self::assertSame('provider', OwnershipRegistry::domainKind($store), 'a sound build with another provider succeeds');
    }

    // ---- F266-16: a hand-assembled engine over a raw Host clock -------------------------------------------------------------------------

    public function testAnEngineAssembledByHandCannotReenterTheStoreFromItsRawClock(): void
    {
        $probe = new CrossingProbe();
        $store = $this->store();
        $probe->store = $store;
        $clock = new ProbeClock($probe);
        $engine = $this->handAssembledEngine($store, $clock);
        $context = new RateLimitContextDTO(ip: '198.51.100.7', ua: 'crossing-260', accountId: 'acct-a');

        $control = $engine->limit($context, RateLimitCommand::checkOnly('login_protection'));
        self::assertNotSame('', $control->decision, 'a sound direct call is served');
        self::assertGreaterThan(0, $probe->reads, 'the raw clock was read');

        $probe->armed = true;
        $this->commands = [];
        self::assertSame(self::REFUSAL, $this->refusalOf(fn() => $engine->limit($context, RateLimitCommand::checkOnly('login_protection'))));
        self::assertSame(0, $this->pings(), 'the raw clock never reached the executor through the library');
        self::assertFalse(OwnershipRegistry::isOccupied($store));

        $probe->armed = false;
        $engine->limit($context, RateLimitCommand::checkOnly('login_protection'));
    }

    private function handAssembledEngine(RedisFullCapabilityStore $store, ClockInterface $clock): RateLimiterEngine
    {
        $budgetTracker = new BudgetTracker($store, $clock);
        $gate = new AntiEquilibriumGate($store, $clock);
        $decay = new DecayCalculator($clock);
        $bucket = new EphemeralBucket($store, $clock);
        $pipeline = new EvaluationPipeline($store, $store, $budgetTracker, $gate, $decay, $bucket, 'test_secret', 'prod', $clock, null);

        return new RateLimiterEngine(
            new DeviceIdentityResolver(new FingerprintHasher('test_fp')),
            $pipeline,
            new CircuitBreaker($store, new RecordingFailureSignalEmitter(), $clock),
            new FailureModeResolver(),
            new RecordingFailureSignalEmitter(),
            $clock,
            [new LoginProtectionPolicy()],
        );
    }
}
