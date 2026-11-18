<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Service;

use Fiber;
use Maatify\RateLimiter\Contract\ExecutionContextProviderInterface;
use Maatify\RateLimiter\Exception\OverlappingRequestException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\ContextDomain;
use Maatify\RateLimiter\Service\OwnershipRegistry;
use Maatify\RateLimiter\Tests\Support\Ownership\StubContextProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * DEC-041: the ownership registry on its own, with plain objects as resources (no Redis): all-or-none acquisition and the cleanup of
 * exactly what a call took, the two-phase acquisition around a Host provider, the failure-atomic domain binding, the provider lease of an
 * active scope, retention (nothing holds a resource or an execution context alive) and the nesting rule.
 */
#[Group('unit')]
#[Group('php32')]
#[Group('ownership-260')]
final class OwnershipRegistryTest extends TestCase
{
    private function default(): ContextDomain
    {
        return ContextDomain::default();
    }

    /** A resource bound to the default domain. */
    private function resource(): \stdClass
    {
        $resource = new \stdClass();
        OwnershipRegistry::bindAll([$resource], null);

        return $resource;
    }

    private function provider(callable $currentContext): StubContextProvider
    {
        return new StubContextProvider($currentContext);
    }

    // ---- acquisition: all-or-none, own-record cleanup (CE-7, CE-8, CE-26) ----------------------------------------------------------

    public function testARefusedAcquisitionClearsOnlyWhatItTookAndNeverAnotherCallsSlot(): void
    {
        $r1 = $this->resource();
        $r2 = $this->resource();
        $a = OwnershipRegistry::acquire([$r2], 'limit', $this->default());

        try {
            OwnershipRegistry::acquire([$r1, $r2], 'limit', $this->default());
            self::fail('B must be refused.');
        } catch (OverlappingRequestException $refusal) {
            self::assertSame('limit', $refusal->entry);
        }

        self::assertFalse(OwnershipRegistry::isOccupied($r1), 'The resource B had taken before meeting the occupied one is free again.');
        self::assertTrue(OwnershipRegistry::isOccupied($r2), 'A still owns its resource.');
        $third = OwnershipRegistry::acquire([$r1], 'consume', $this->default());
        self::assertTrue(OwnershipRegistry::isOccupied($r1), 'A third call acquires the freed resource.');
        $third->release();
        $a->release();
        self::assertFalse(OwnershipRegistry::isOccupied($r2));
    }

    public function testAnAliasedResourceIsAcquiredOnceAndACallNeverRefusesItself(): void
    {
        $store = $this->resource();

        $scope = OwnershipRegistry::acquire(OwnershipRegistry::collect([$store, $store, $store]), 'limit', $this->default());

        self::assertTrue(OwnershipRegistry::isOccupied($store));
        $scope->release();
        $scope->release();
        self::assertFalse(OwnershipRegistry::isOccupied($store), 'Release is idempotent.');
    }

    public function testAReleaseClearsASlotOnlyWhereItStillHoldsThisCallsRecord(): void
    {
        $r = $this->resource();
        $a = OwnershipRegistry::acquire([$r], 'limit', $this->default());
        $a->release();
        $b = OwnershipRegistry::acquire([$r], 'limit', $this->default());

        $a->release();

        self::assertTrue(OwnershipRegistry::isOccupied($r), 'A stale release does not clear a slot that another call reused.');
        $b->release();
        self::assertFalse(OwnershipRegistry::isOccupied($r));
    }

    public function testClearingWithAStaleRecordNeverEmptiesASlotAnotherCallReused(): void
    {
        $r = $this->resource();
        $a = OwnershipRegistry::acquire([$r], 'limit', $this->default());
        $staleRecords = $a->records();
        $a->release();
        $b = OwnershipRegistry::acquire([$r], 'limit', $this->default());

        OwnershipRegistry::clear($r, $staleRecords[0]);

        self::assertTrue(OwnershipRegistry::isOccupied($r), 'A record that is no longer in the slot clears nothing: B still owns it.');
        $b->release();
        self::assertFalse(OwnershipRegistry::isOccupied($r));
    }

    // ---- the provider: two phases (CE-22) ------------------------------------------------------------------------------------------

    public function testAProviderThatReentersTheLibraryIsRefusedBeforeItIsCalledAgain(): void
    {
        $r = new \stdClass();
        $refusals = 0;
        $inner = null;
        $provider = $this->provider(function () use (&$refusals, $r, &$inner): null {
            try {
                OwnershipRegistry::acquire([$r], 'inner', ContextDomain::forProvider($inner));
            } catch (OverlappingRequestException) {
                $refusals++;
            }

            return null;
        });
        $inner = $provider;
        $domain = ContextDomain::forProvider($provider);
        OwnershipRegistry::bindAll([$r], $provider);

        $scope = OwnershipRegistry::acquire([$r], 'outer', $domain);

        self::assertSame(1, $refusals, 'The re-entrant call was refused.');
        self::assertSame(1, $provider->calls, 'The provider was not called again by the refused call.');
        $scope->release();
    }

    public function testAProviderThatThrowsReleasesOnlyThisCallsReservationsAndSurfacesAClassifiedFailure(): void
    {
        $r1 = new \stdClass();
        $r2 = new \stdClass();
        $boom = new \RuntimeException('provider failed');
        $provider = $this->provider(static function () use ($boom): never {
            throw $boom;
        });
        OwnershipRegistry::bindAll([$r1, $r2], $provider);
        $other = $this->resource();
        $busy = OwnershipRegistry::acquire([$other], 'limit', $this->default());

        try {
            OwnershipRegistry::acquire([$r1, $r2], 'limit', ContextDomain::forProvider($provider));
            self::fail('The provider failure surfaces.');
        } catch (RateLimiterException $failure) {
            self::assertNotInstanceOf(OverlappingRequestException::class, $failure, 'A provider failure is not a busy refusal.');
            self::assertSame($boom, $failure->getPrevious());
        }

        self::assertFalse(OwnershipRegistry::isOccupied($r1));
        self::assertFalse(OwnershipRegistry::isOccupied($r2));
        self::assertTrue(OwnershipRegistry::isOccupied($other), 'Another call\'s slot is never touched.');
        $busy->release();
    }

    public function testAProviderThatYieldsKeepsTheReservationsAndEveryOtherCallIsRefused(): void
    {
        $r = new \stdClass();
        $provider = $this->provider(static function (): int {
            Fiber::suspend('lookup');

            return 7;
        });
        OwnershipRegistry::bindAll([$r], $provider);
        $domain = ContextDomain::forProvider($provider);
        $a = new Fiber(static function () use ($r, $domain): void {
            $scope = OwnershipRegistry::acquire([$r], 'a', $domain);
            $scope->release();
        });
        $a->start();
        self::assertTrue($a->isSuspended());

        $refused = false;
        try {
            OwnershipRegistry::acquire([$r], 'b', $domain);
        } catch (OverlappingRequestException) {
            $refused = true;
        }

        self::assertTrue($refused, 'The reservation stays while the provider is suspended.');
        $a->resume();
        self::assertFalse(OwnershipRegistry::isOccupied($r), 'A completed and released.');
    }

    // ---- nesting rule: owner context, no crossing (CE-12) -------------------------------------------------------------------------

    public function testNestedOperationsFromTheOwnersContextAreAdmittedAndOtherContextsAndCrossingsAreRefused(): void
    {
        $r = $this->resource();
        $scope = OwnershipRegistry::acquire([$r], 'limit', $this->default());

        self::assertNull(OwnershipRegistry::admit($r, 'nested'), 'Same context, no crossing: nested.');

        $fromAnotherFiber = null;
        $fiber = new Fiber(static function () use ($r, &$fromAnotherFiber): void {
            try {
                OwnershipRegistry::admit($r, 'independent');
                $fromAnotherFiber = 'admitted';
            } catch (OverlappingRequestException) {
                $fromAnotherFiber = 'refused';
            }
        });
        $fiber->start();
        self::assertSame('refused', $fromAnotherFiber, 'Another execution context is refused.');

        $reentrant = OwnershipRegistry::crossing($r, function () use ($r): string {
            try {
                OwnershipRegistry::admit($r, 're-entrant');

                return 'admitted';
            } catch (OverlappingRequestException) {
                return 'refused';
            }
        });
        self::assertSame('refused', $reentrant, 'A call from inside a Host crossing is refused even from the owner\'s context.');
        self::assertNull(OwnershipRegistry::admit($r, 'nested again'), 'The crossing has ended.');
        $scope->release();
    }

    public function testADirectOperationWithNoOwnerIsAShortLivedOwnerForItsWholeDuration(): void
    {
        $r = $this->resource();

        $scope = OwnershipRegistry::admit($r, 'direct');

        self::assertNotNull($scope);
        self::assertTrue(OwnershipRegistry::isOccupied($r));
        $refused = null;
        $fiber = new Fiber(static function () use ($r, &$refused): void {
            try {
                OwnershipRegistry::admit($r, 'second');
                $refused = false;
            } catch (OverlappingRequestException) {
                $refused = true;
            }
        });
        $fiber->start();
        self::assertTrue($refused);
        $scope->release();
        self::assertFalse(OwnershipRegistry::isOccupied($r));
    }

    // ---- domain binding: set-wide, failure-atomic, immutable (CE-19, CE-20, CE-28, CE-30) ---------------------------------------

    public function testMixedDomainsAreRefusedAtBindingAndNothingIsBound(): void
    {
        $p = $this->provider(static fn(): null => null);
        $q = $this->provider(static fn(): null => null);
        $unbound = new \stdClass();
        $boundToP = new \stdClass();
        OwnershipRegistry::bindAll([$boundToP], $p);

        try {
            OwnershipRegistry::bindAll([$unbound, $boundToP], $q);
            self::fail('Two providers over one resource are refused.');
        } catch (RateLimiterException $failure) {
            self::assertStringContainsString('domain', $failure->getMessage());
        }

        self::assertSame('unbound', OwnershipRegistry::domainKind($unbound), 'A failed set-wide binding records nothing.');
        self::assertSame('provider', OwnershipRegistry::domainKind($boundToP));
        OwnershipRegistry::bindAll([$unbound], $p);
        self::assertSame('provider', OwnershipRegistry::domainKind($unbound), 'A later graph in the other domain binds the resource normally.');
        $default = new \stdClass();
        OwnershipRegistry::bindAll([$default], null);
        $this->expectException(RateLimiterException::class);
        OwnershipRegistry::bindAll([$default], $p);
    }

    public function testASameProviderInstanceIsAcceptedAndAnotherInstanceOfTheSameClassIsNot(): void
    {
        $factory = fn(): StubContextProvider => $this->provider(static fn(): null => null);
        $p = $factory();
        $resource = new \stdClass();
        OwnershipRegistry::bindAll([$resource], $p);

        OwnershipRegistry::bindAll([$resource], $p);

        $this->expectException(RateLimiterException::class);
        OwnershipRegistry::bindAll([$resource], $factory());
    }

    public function testARollbackUndoesExactlyWhatThatBindingBound(): void
    {
        $p = $this->provider(static fn(): null => null);
        $existing = new \stdClass();
        $fresh = new \stdClass();
        OwnershipRegistry::bindAll([$existing], $p);

        $ledger = OwnershipRegistry::bindAll([$existing, $fresh], $p);
        OwnershipRegistry::rollback($ledger);

        self::assertSame('unbound', OwnershipRegistry::domainKind($fresh));
        self::assertSame('provider', OwnershipRegistry::domainKind($existing), 'An existing binding is never changed.');
    }

    public function testAnEntryAdoptsTheDomainOfItsResourcesAndRefusesResourcesOfTwoDomains(): void
    {
        $p = $this->provider(static fn(): null => null);
        $fresh = new \stdClass();
        self::assertTrue(OwnershipRegistry::adopt([$fresh])->isDefault(), 'All-unbound resources bind to the default domain.');
        self::assertSame('default', OwnershipRegistry::domainKind($fresh));

        $boundToP = new \stdClass();
        OwnershipRegistry::bindAll([$boundToP], $p);
        $unbound = new \stdClass();
        $domain = OwnershipRegistry::adopt([$unbound, $boundToP]);
        self::assertFalse($domain->isDefault(), 'An entry over a provider-bound store inherits its provider.');
        self::assertSame($p, $domain->rawProvider());
        self::assertSame('provider', OwnershipRegistry::domainKind($unbound));

        $this->expectException(RateLimiterException::class);
        OwnershipRegistry::adopt([$fresh, $boundToP]);
    }

    // ---- provider lifetime: leases, vacated bindings, replacement eligibility (CE-29, CE-31, CE-32) -------------------------------

    public function testAnActiveScopeKeepsItsProviderAliveAndAnotherDomainIsRefusedUntilItEnds(): void
    {
        $store = new \stdClass();
        $provider = $this->provider(static fn(): null => null);
        OwnershipRegistry::bindAll([$store], $provider);
        $scope = OwnershipRegistry::admit($store, 'direct');
        self::assertNotNull($scope, 'A direct call that inherited the provider from a graph is a short-lived owner.');

        // The Host drops the graph and its last separate reference to the provider while the call is in flight.
        unset($provider);
        gc_collect_cycles();
        $other = $this->provider(static fn(): null => null);

        self::assertSame('provider', OwnershipRegistry::domainKind($store), 'The lease keeps the provider alive: the binding is not vacated.');
        try {
            OwnershipRegistry::bindAll([$store], $other);
            self::fail('Another domain must be refused while the call is active.');
        } catch (RateLimiterException) {
            self::assertTrue(OwnershipRegistry::isOccupied($store), 'The active call\'s record is untouched.');
        }

        $scope->release();
        unset($scope);
        gc_collect_cycles();
        self::assertSame('vacated', OwnershipRegistry::domainKind($store), 'After the last release the provider may expire.');
        OwnershipRegistry::bindAll([$store], $other);
        self::assertSame('provider', OwnershipRegistry::domainKind($store), 'A vacated binding with no owner is replaceable.');
    }

    public function testAVacatedBindingIsNeverFilledSilentlyByADirectCall(): void
    {
        $store = new \stdClass();
        $provider = $this->provider(static fn(): null => null);
        OwnershipRegistry::bindAll([$store], $provider);
        unset($provider);
        gc_collect_cycles();
        self::assertSame('vacated', OwnershipRegistry::domainKind($store));

        try {
            OwnershipRegistry::admit($store, 'direct');
            self::fail('A direct call on a vacated binding fails fast.');
        } catch (RateLimiterException $failure) {
            self::assertStringContainsString('gone', $failure->getMessage());
        }
        self::assertSame('vacated', OwnershipRegistry::domainKind($store), 'It did not fall back to the default domain.');
    }

    public function testAReplacementIsRejectedWhileAnOwnerAReservationOrALookupStillExistsOnAVacatedBinding(): void
    {
        $store = new \stdClass();
        $provider = $this->provider(static fn(): null => null);
        OwnershipRegistry::bindAll([$store], $provider);
        $scope = OwnershipRegistry::acquire([$store], 'limit', ContextDomain::forProvider($provider));
        // Forced defensive case: the weak provider reference expires while the record still exists.
        $slots = (new \ReflectionProperty(OwnershipRegistry::class, 'slots'))->getValue();
        self::assertInstanceOf(\WeakMap::class, $slots);
        $slot = $slots[$store];
        self::assertInstanceOf(\Maatify\RateLimiter\Service\ResourceSlot::class, $slot);
        $slot->provider = \WeakReference::create($this->provider(static fn(): null => null));
        self::assertSame('vacated', OwnershipRegistry::domainKind($store));

        try {
            OwnershipRegistry::bindAll([$store], $this->provider(static fn(): null => null));
            self::fail('The replacement must be rejected.');
        } catch (RateLimiterException $failure) {
            self::assertStringContainsString('ownership state still exists', $failure->getMessage());
        }

        self::assertTrue(OwnershipRegistry::isOccupied($store), 'The old owner\'s record is neither modified nor released.');
        $scope->release();
    }

    // ---- retention (CE-23, CE-24) --------------------------------------------------------------------------------------------------

    public function testNothingHoldsAResourceAliveAfterTheCallAndAnExpiredObjectContextEqualsNothing(): void
    {
        $resource = new \stdClass();
        OwnershipRegistry::bindAll([$resource], null);
        $scope = OwnershipRegistry::acquire([$resource], 'limit', $this->default());
        $scope->release();
        $weak = \WeakReference::create($resource);

        unset($resource, $scope);
        gc_collect_cycles();

        self::assertNull($weak->get(), 'The registry, the token and the record do not keep the resource alive.');

        // An object context is held weakly: once it has expired the owner's identity equals nothing.
        $store = new \stdClass();
        /** @var \ArrayObject<string, \stdClass|null> $box */
        $box = new \ArrayObject(['context' => new \stdClass()]);
        $provider = $this->provider(static fn(): \stdClass => $box['context'] ?? new \stdClass());
        OwnershipRegistry::bindAll([$store], $provider);
        $owner = OwnershipRegistry::acquire([$store], 'limit', ContextDomain::forProvider($provider));
        self::assertNull(OwnershipRegistry::admit($store, 'nested'), 'The live context is the owner\'s.');
        $box['context'] = null;
        gc_collect_cycles();

        $this->expectException(OverlappingRequestException::class);
        try {
            OwnershipRegistry::admit($store, 'after the context expired');
        } finally {
            $owner->release();
        }
    }

    public function testAKeyOfAnotherLiveContextIsRefusedAndAScalarKeyIsComparedByValue(): void
    {
        $store = new \stdClass();
        $current = 1;
        $provider = $this->provider(static function () use (&$current): int {
            return $current;
        });
        OwnershipRegistry::bindAll([$store], $provider);
        $owner = OwnershipRegistry::acquire([$store], 'limit', ContextDomain::forProvider($provider));
        self::assertNull(OwnershipRegistry::admit($store, 'same key'));

        $current = 2;
        $this->expectException(OverlappingRequestException::class);
        try {
            OwnershipRegistry::admit($store, 'other key');
        } finally {
            $owner->release();
        }
    }

    public function testDecoratedAliasingOfAStoreIsNotRecognisedAndIsOutsideTheSupport(): void
    {
        // Two graphs wrap one underlying store in two different Host decorator objects: the registry keys on object identity and sees two
        // resources (CE-9, a declared limit, not a repaired case): nothing is refused, so such a composition is not supported.
        $decoratorOne = $this->resource();
        $decoratorTwo = $this->resource();

        $first = OwnershipRegistry::acquire([$decoratorOne], 'limit', $this->default());
        $second = OwnershipRegistry::acquire([$decoratorTwo], 'limit', $this->default());

        self::assertTrue(OwnershipRegistry::isOccupied($decoratorOne) && OwnershipRegistry::isOccupied($decoratorTwo));
        $first->release();
        $second->release();
    }

    public function testAProviderThatBreaksItsContractWithAnEqualKeyForTwoLiveContextsIsTreatedAsOne(): void
    {
        // CE-23, the contract violation characterised: the same key for two live contexts is one context to the registry, so the second is
        // treated as nested. This is the provider author's obligation (keys unique among live contexts), not a detectable state.
        $store = new \stdClass();
        $provider = $this->provider(static fn(): int => 5);
        OwnershipRegistry::bindAll([$store], $provider);
        $owner = OwnershipRegistry::acquire([$store], 'limit', ContextDomain::forProvider($provider));

        self::assertNull(OwnershipRegistry::admit($store, 'second context with the same key'));
        $owner->release();
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    private static function parameterNames(string $class): array
    {
        return array_map(
            static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
            (new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [],
        );
    }

    public function testOnlyTheStoreConstructorGainsAnOptionalProviderParameter(): void
    {
        $store = self::parameterNames(\Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore::class);
        self::assertSame('executionContext', end($store), 'The one additive optional trailing parameter.');
        self::assertTrue((new \ReflectionParameter([\Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore::class, '__construct'], 'executionContext'))->isOptional());
        foreach ([
            \Maatify\RateLimiter\Service\RateLimiterEngine::class,
            \Maatify\RateLimiter\Service\FixedWindowSimpleRateLimiter::class,
            \Maatify\RateLimiter\Service\CompositeRateLimiterRuntime::class,
            \Maatify\RateLimiter\Service\RateLimitOperationalReader::class,
            \Maatify\RateLimiter\Service\SimpleRateLimitOperationalReader::class,
            \Maatify\RateLimiter\Service\CompositeRateLimitOperationalReader::class,
        ] as $entry) {
            self::assertNotContains('executionContext', self::parameterNames($entry), $entry . ' keeps its constructor: it reads its domain from its resources.');
        }
    }

    public function testADestroyedSuspendedFiberUnwindsAndReleasesItsSlots(): void
    {
        $r = $this->resource();
        $a = new Fiber(function () use ($r): void {
            $scope = OwnershipRegistry::acquire([$r], 'limit', $this->default());
            try {
                Fiber::suspend('inside the call');
            } finally {
                $scope->release();
            }
        });
        $a->start();
        self::assertTrue(OwnershipRegistry::isOccupied($r));

        unset($a);
        gc_collect_cycles();

        self::assertFalse(OwnershipRegistry::isOccupied($r), 'Destroying the suspended Fiber ran its finally and released the slots.');
    }

    // ---- the inventory trusts package classes, never a method name (F266-14) -------------------------------------------------------

    public function testAHostObjectWithAMethodNamedOwnedResourcesIsAResourceByIdentityAndItsCodeIsNeverCalled(): void
    {
        $host = new class {
            public int $calls = 0;

            /** @return list<object> */
            public function ownedResources(): array
            {
                $this->calls++;

                return [];
            }
        };

        $collected = OwnershipRegistry::collect([$host]);

        self::assertSame([$host], $collected, 'the Host object itself is the protected resource');
        self::assertSame(0, $host->calls, 'the Host method was not called to build the set');

        $implementing = new class implements \Maatify\RateLimiter\Service\OwnedResources {
            public function ownedResources(): array
            {
                return [];
            }
        };
        self::assertSame([$implementing], OwnershipRegistry::collect([$implementing]), 'implementing the internal interface does not make a Host class package-owned');
    }

    public function testAPackageClassIsStillExpandedAndARawHostClockIsStillExcluded(): void
    {
        $inner = new \Maatify\RateLimiter\Service\RequestScopedClock(new \Maatify\RateLimiter\Tests\Support\Clock\FixedClock('@1000000'));
        $rawClock = new \Maatify\RateLimiter\Tests\Support\Clock\FixedClock('@1000000');

        self::assertSame([$inner], OwnershipRegistry::collect([], [], [$inner, $rawClock]));
    }

    public function testASharedHostStoreWithAnOwnedResourcesMethodStillRefusesTheSecondCall(): void
    {
        $host = new class {
            /** @return list<object> */
            public function ownedResources(): array
            {
                return [];
            }
        };
        OwnershipRegistry::bindAll([$host], null);
        $firstGraph = OwnershipRegistry::collect([$host]);
        $secondGraph = OwnershipRegistry::collect([$host]);

        $scope = OwnershipRegistry::acquire($firstGraph, 'first', $this->default());
        try {
            $this->expectException(OverlappingRequestException::class);
            OwnershipRegistry::acquire($secondGraph, 'second', $this->default());
        } finally {
            $scope->release();
        }
    }

    public function testAHostClockPassesEveryReadingThroughOneToOneAndIsNeverWrappedTwice(): void
    {
        $raw = new \Maatify\RateLimiter\Tests\Support\Clock\FixedClock('@1000000');
        $wrapped = \Maatify\RateLimiter\Service\HostClock::of($raw);

        self::assertNotSame($raw, $wrapped);
        self::assertSame($wrapped, \Maatify\RateLimiter\Service\HostClock::of($wrapped), 'an already wrapped clock is returned as it is');
        $scoped = new \Maatify\RateLimiter\Service\RequestScopedClock($raw);
        self::assertSame($scoped, \Maatify\RateLimiter\Service\HostClock::of($scoped), 'the request clock is never wrapped');
        self::assertEquals($raw->now(), $wrapped->now(), 'the reading is the Host reading');
        self::assertEquals($raw->getTimezone(), $wrapped->getTimezone());
        self::assertSame([$wrapped], OwnershipRegistry::collect([], [], [$wrapped, $raw]), 'only the wrapper is a resource, the raw clock is not');
    }

    public function testARollbackKeepsTheBindingAnotherCallStandsOn(): void
    {
        $resource = new \stdClass();
        $p = $this->provider(static fn() => 1);
        $first = OwnershipRegistry::bindAll([$resource], $p);
        $second = OwnershipRegistry::bindAll([$resource], $p);
        self::assertSame('provider', OwnershipRegistry::domainKind($resource));

        OwnershipRegistry::rollback($first);
        self::assertSame('provider', OwnershipRegistry::domainKind($resource), 'the second call still stands on it');

        OwnershipRegistry::rollback($second);
        self::assertSame('unbound', OwnershipRegistry::domainKind($resource), 'nobody stands on it any more');
    }

    public function testAnAdoptionStandsOnTheBindingUnlessItIsPartOfTheBuildThatRecordedIt(): void
    {
        $p = $this->provider(static fn() => 1);

        $own = new \stdClass();
        $ledger = OwnershipRegistry::bindAll([$own], $p);
        OwnershipRegistry::buildScope(static fn() => OwnershipRegistry::adopt([$own]));
        OwnershipRegistry::rollback($ledger);
        self::assertSame('unbound', OwnershipRegistry::domainKind($own), 'an entry of the same build does not pin a build that fails');

        $host = new \stdClass();
        $ledger = OwnershipRegistry::bindAll([$host], $p);
        OwnershipRegistry::buildScope(static fn() => OwnershipRegistry::hostRegion(static fn() => OwnershipRegistry::adopt([$host])));
        OwnershipRegistry::rollback($ledger);
        self::assertSame('provider', OwnershipRegistry::domainKind($host), 'an entry built by hand from Host code stands on the binding it adopted');

        $loose = new \stdClass();
        $ledger = OwnershipRegistry::bindAll([$loose], $p);
        OwnershipRegistry::adopt([$loose]);
        OwnershipRegistry::rollback($ledger);
        self::assertSame('provider', OwnershipRegistry::domainKind($loose), 'an entry built outside any build stands on the binding it adopted');
    }

    public function testABuildStartedFromHostCodeKnowsItsOwnComponents(): void
    {
        $p = $this->provider(static fn() => 1);
        $resource = new \stdClass();
        $ledger = OwnershipRegistry::bindAll([$resource], $p);

        // A build running inside the Host code of another build: its own component adopts without pinning, so its failure undoes it.
        OwnershipRegistry::buildScope(static function () use ($resource): void {
            OwnershipRegistry::hostRegion(static function () use ($resource): void {
                OwnershipRegistry::buildScope(static fn() => OwnershipRegistry::adopt([$resource]));
            });
        });
        OwnershipRegistry::rollback($ledger);
        self::assertSame('unbound', OwnershipRegistry::domainKind($resource));
    }

    public function testTheBuildFramesOfAFiberNeverMarkAnotherContext(): void
    {
        $p = $this->provider(static fn() => 1);
        $resource = new \stdClass();
        $fiber = new Fiber(static function (): void {
            OwnershipRegistry::hostRegion(static function (): void {
                Fiber::suspend('inside the Host code of a build');
            });
        });
        $fiber->start(); // a build is suspended inside its Host code

        $ledger = OwnershipRegistry::bindAll([$resource], $p);
        OwnershipRegistry::buildScope(static fn() => OwnershipRegistry::adopt([$resource]));
        OwnershipRegistry::rollback($ledger);
        self::assertSame('unbound', OwnershipRegistry::domainKind($resource), 'an independent build on another context is not affected by the suspended one');

        $fiber->resume();
        self::assertTrue($fiber->isTerminated());
    }

    public function testRepeatedSuccessfulReplacementsKeepABoundedNumberOfBindingRecords(): void
    {
        $resource = new \stdClass();
        $weak = [];
        for ($i = 0; $i < 128; $i++) {
            $provider = $this->provider(static fn() => 1);
            OwnershipRegistry::bindAll([$resource], $provider); // replaces the vacated binding of the previous round
            $weak[] = \WeakReference::create($provider);
            unset($provider);
            gc_collect_cycles();
        }

        $collected = count(array_filter($weak, static fn(\WeakReference $reference): bool => $reference->get() === null));
        self::assertSame(128, $collected, 'every provider was collected');
        self::assertSame('vacated', OwnershipRegistry::domainKind($resource));
        self::assertLessThanOrEqual(1, \Maatify\RateLimiter\Tests\Support\Ownership\BindingProbe::records($resource), 'only the record of the current binding is reachable from the resource');
    }

    public function testTheHoldersOfAReplacedBindingNeverKeepTheReplacementAlive(): void
    {
        $resource = new \stdClass();
        $old = $this->provider(static fn() => 1);
        OwnershipRegistry::bindAll([$resource], $old); // a graph that stands on the first generation for good
        unset($old);
        gc_collect_cycles();
        self::assertSame('vacated', OwnershipRegistry::domainKind($resource));

        $new = $this->provider(static fn() => 2);
        $ledger = OwnershipRegistry::bindAll([$resource], $new);
        self::assertSame('provider', OwnershipRegistry::domainKind($resource));
        OwnershipRegistry::rollback($ledger);
        self::assertSame('vacated', OwnershipRegistry::domainKind($resource), 'the failed replacement returns to the state it replaced');
    }

    public function testAnActiveCallDelaysTheRollbackToItsLastRelease(): void
    {
        $resource = new \stdClass();
        $p = $this->provider(static fn() => 1);
        $ledger = OwnershipRegistry::bindAll([$resource], $p);
        $scope = OwnershipRegistry::acquire([$resource], 'limit', ContextDomain::forProvider($p));

        OwnershipRegistry::rollback($ledger);
        self::assertSame('provider', OwnershipRegistry::domainKind($resource), 'the active call keeps its domain');

        $scope->release();
        self::assertSame('unbound', OwnershipRegistry::domainKind($resource), 'the overdue rollback happens at the release');
    }
}
