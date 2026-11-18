<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Fiber;
use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\OwnershipRegistry;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Ownership\StubContextProvider;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * DEC-041: the optional execution-context provider on the official store and the Builder, against an isolated Redis: identity for a
 * scheduler whose contexts are not Fibers, the inheritance and the failure-atomic binding of a graph's resources, the retention of a
 * provider by the store that was configured with it, and the provider lease of an active direct call that inherited its provider.
 */
#[Group('integration')]
#[Group('fiber-ownership-260')]
#[Group('php32')]
final class ExecutionContextProviderRedisTest extends TestCase
{
    private const REFUSAL = 'Maatify\\RateLimiter\\Exception\\OverlappingRequestException';

    private const NOW = 1_000_000;

    private string $host;

    private int $port;

    /** @var (callable(array<mixed>, string): void)|null */
    private $hook = null;

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
        $this->hook = null;
    }

    private function store(?StubContextProvider $provider = null): RedisFullCapabilityStore
    {
        $connection = new RespRedisCommandExecutor($this->host, $this->port);

        return new RedisFullCapabilityStore(new CallableRedisCommandExecutor(function (array $command) use ($connection): mixed {
            if ($this->hook !== null) {
                ($this->hook)($command, 'before');
            }

            return $connection->execute($command);
        }), 'provider-260', null, 500, $provider);
    }

    private function config(): RateLimiterConfig
    {
        return new RateLimiterConfig('test_secret', 'test_fp', 'prod');
    }

    private function graph(RedisFullCapabilityStore $store, ?StubContextProvider $provider = null): RateLimiterBuilder
    {
        $builder = RateLimiterBuilder::fromFullCapabilityStore($this->config(), $store, new RecordingFailureSignalEmitter())->withClock(new FixedClock('@' . self::NOW));
        if ($provider !== null) {
            $builder->withExecutionContextProvider($provider);
        }

        return $builder;
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

    // ---- identity for a scheduler that is not Fibers (CE-10, CE-12, CE-18, CE-21) ---------------------------------------------------

    public function testAProviderSeparatesTheContextsOfASchedulerThatIsNotFibers(): void
    {
        $key = new \ArrayObject(['now' => 'ctx-1']);
        $provider = new StubContextProvider(static fn(): string => (string) $key['now']);
        $store = $this->store($provider);
        $nested = null;
        $refusedFromTheOtherContext = null;

        $store->transactRequest(function () use ($store, $key, &$nested, &$refusedFromTheOtherContext): void {
            $nested = $store->acquireProbeLease('nested', self::NOW, 120);
            $key['now'] = 'ctx-2'; // the scheduler switches to another context while the callback is suspended (no Host crossing is open)
            $refusedFromTheOtherContext = $this->refusalOf(static fn() => $store->acquireProbeLease('other', self::NOW, 120));
            $key['now'] = 'ctx-1'; // back in the owner's context
            $store->acquireProbeLease('nested-again', self::NOW, 120);
        });

        self::assertTrue($nested, 'The owner\'s own context is nested.');
        self::assertSame(self::REFUSAL, $refusedFromTheOtherContext, 'Another context of the same provider is refused by identity, with no Host crossing open.');
        self::assertGreaterThan(0, $provider->calls);
    }

    public function testWithoutAProviderTheContextsOfANonFiberSchedulerLookAlikeAndTheCallIsTreatedAsNested(): void
    {
        $store = $this->store();
        $result = null;

        $store->transactRequest(function () use ($store, &$result): void {
            // A scheduler that is not Fibers and has no provider: its contexts all look like the main stack. The declared limit.
            $result = $store->acquireProbeLease('looks-nested', self::NOW, 120);
        });

        self::assertTrue($result, 'Documented limit: nothing separates the contexts of a non-Fiber scheduler without a provider (only guarded Host crossings are covered).');
    }

    public function testAProviderPassedToTheStoreConstructorIsRetainedWithoutAHostVariable(): void
    {
        $store = $this->store(new StubContextProvider(static fn(): int => 1));

        gc_collect_cycles();

        self::assertSame('provider', OwnershipRegistry::domainKind($store), 'The store retains its configured provider for its lifetime.');
        self::assertTrue($store->acquireProbeLease('policy', self::NOW, 120), 'A direct call works with the retained provider.');
    }

    // ---- inheritance and failure-atomic binding (CE-19, CE-28, CE-30) --------------------------------------------------------------

    public function testAGraphInheritsTheProviderOfItsStoreAndAnotherProviderOrDomainIsRefusedBeforeAnythingIsBound(): void
    {
        $p = new StubContextProvider(static fn(): int => 1);
        $q = new StubContextProvider(static fn(): int => 2);
        $store = $this->store($p);

        $inherited = $this->graph($store)->build();
        self::assertInstanceOf(\Maatify\RateLimiter\Service\CompositeRateLimiterRuntime::class, $inherited);
        self::assertSame('provider', OwnershipRegistry::domainKind($store), 'A graph with no configured provider inherits the store\'s.');

        $fresh = $this->store();
        try {
            RateLimiterBuilder::fromFullCapabilityStore($this->config(), $store, new RecordingFailureSignalEmitter())->withExecutionContextProvider($q)->build();
            self::fail('Another provider instance over a bound store is refused.');
        } catch (RateLimiterException $failure) {
            self::assertStringContainsString('domain', $failure->getMessage());
        }
        self::assertSame('unbound', OwnershipRegistry::domainKind($fresh), 'An unrelated resource was never bound by the failed build.');

        // A graph in the default domain first, then a graph with a provider over the same store: refused.
        $defaultGraphStore = $this->store();
        $this->graph($defaultGraphStore)->build();
        $this->expectException(RateLimiterException::class);
        $this->graph($defaultGraphStore, $p)->build();
    }

    public function testAFailedBuildBindsNothingOfItsOwnAndALaterGraphOfTheOtherDomainBindsNormally(): void
    {
        $p = new StubContextProvider(static fn(): int => 1);
        $q = new StubContextProvider(static fn(): int => 2);
        $rate = $this->store();
        $correlation = $this->store($p);
        $circuit = $this->store();

        try {
            (new RateLimiterBuilder($this->config(), $rate, $correlation, $circuit, new RecordingFailureSignalEmitter()))->withExecutionContextProvider($q)->build();
            self::fail('The correlation store is bound to P.');
        } catch (RateLimiterException) {
        }

        self::assertSame('unbound', OwnershipRegistry::domainKind($rate), 'The resources that were free are still unbound.');
        self::assertSame('unbound', OwnershipRegistry::domainKind($circuit));
        (new RateLimiterBuilder($this->config(), $rate, $correlation, $circuit, new RecordingFailureSignalEmitter()))->withExecutionContextProvider($p)->build();
        self::assertSame('provider', OwnershipRegistry::domainKind($rate), 'A later graph in the other domain binds them normally.');
    }

    // ---- the active direct call that inherited its provider from a graph (CE-31) -------------------------------------------------

    public function testAfterEveryGraphThatSuppliedTheProviderIsDestroyedADirectCallFailsFastAndNeverFallsBackToTheDefaultDomain(): void
    {
        $store = $this->store();
        (function () use ($store): void {
            $provider = new StubContextProvider(static fn(): int => 1);
            $graph = $this->graph($store, $provider)->build();
            self::assertInstanceOf(\Maatify\RateLimiter\Service\CompositeRateLimiterRuntime::class, $graph);
            self::assertSame('provider', OwnershipRegistry::domainKind($store));
        })();
        gc_collect_cycles();

        self::assertSame('vacated', OwnershipRegistry::domainKind($store), 'The graph and the last reference to its provider are gone, with no call in flight.');
        try {
            $store->acquireProbeLease('policy', self::NOW, 120);
            self::fail('A direct call on a vacated binding fails fast.');
        } catch (RateLimiterException $failure) {
            self::assertStringContainsString('gone', $failure->getMessage());
        }
        self::assertSame('vacated', OwnershipRegistry::domainKind($store), 'It did not fall back to the default domain.');
        $this->graph($store, new StubContextProvider(static fn(): int => 3))->build();
        self::assertSame('provider', OwnershipRegistry::domainKind($store), 'Only an explicit new graph replaces the vacated binding.');
    }

    public function testAnActiveInheritedProviderCallIsNotReconfiguredAndTheVacatedBindingIsReplaceableAfterItEnds(): void
    {
        $store = $this->store();
        $provider = new StubContextProvider(static fn(): int => 1);
        $graph = $this->graph($store, $provider)->build();
        self::assertInstanceOf(\Maatify\RateLimiter\Service\CompositeRateLimiterRuntime::class, $graph);
        $a = new Fiber(static fn(): bool => $store->acquireProbeLease('policy-a', self::NOW, 120));
        $fired = false;
        $this->hook = static function (array $command, string $phase) use ($a, &$fired): void {
            if (! $fired && Fiber::getCurrent() === $a) {
                $fired = true;
                Fiber::suspend('A in the executor');
            }
        };
        $a->start();
        self::assertTrue($a->isSuspended());

        // The Host drops the graph and its last separate reference to the provider while the direct call is in flight.
        unset($graph, $provider);
        gc_collect_cycles();
        $q = new StubContextProvider(static fn(): int => 2);

        self::assertSame('provider', OwnershipRegistry::domainKind($store), 'The active call\'s lease keeps its provider: the binding is not vacated.');
        $failure = $this->refusalOf(fn() => $this->graph($store, $q)->build());
        self::assertSame(RateLimiterException::class, $failure, 'Another domain is refused (a configuration error) without touching the call in flight.');
        $this->hook = null;
        $a->resume();
        self::assertTrue($a->getReturn(), 'The call in flight completes with its own provider contract.');

        unset($a);
        gc_collect_cycles();
        self::assertSame('vacated', OwnershipRegistry::domainKind($store), 'After the call ends and the lease is dropped the provider may expire.');
        $this->graph($store, $q)->build();
        self::assertSame('provider', OwnershipRegistry::domainKind($store), 'A new graph may then replace the vacated binding.');
    }
}
