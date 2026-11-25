<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Service;

use Maatify\RateLimiter\Exception\OverlappingRequestException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\ContextDomain;
use Maatify\RateLimiter\Service\OwnershipRegistry;
use Maatify\RateLimiter\Tests\Support\Ownership\StubContextProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F300-03/04/05: one contract for admitting a resource set when a member is already occupied: one owning token, one domain, the
 * owner's context, then (and only then) bind and reserve under the owner's token, with a rollback that restores what it changed.
 */
#[Group('php32')]
final class OwnershipRegistryAdmitSetTest extends TestCase
{
    private function resource(): object
    {
        return new class {};
    }

    /** @param list<object> $resources */
    private function own(array $resources, string $entry): \Maatify\RateLimiter\Service\CallScope
    {
        OwnershipRegistry::bindAll($resources, null); // the default domain, as a built graph binds its resources

        return OwnershipRegistry::acquire($resources, $entry, ContextDomain::default());
    }

    public function testASetWithAFreeMemberJoinsTheOwnersTokenAndEndsWithIt(): void
    {
        $owned = $this->resource();
        $free = $this->resource();
        $owner = $this->own([$owned], 'owner');
        self::assertSame('unbound', OwnershipRegistry::domainKind($free));

        $join = OwnershipRegistry::admitSet([$owned, $free], 'nested');
        self::assertSame($owner->token, $join->token, 'F300-04: one token covers every resource of the call.');
        self::assertTrue(OwnershipRegistry::isOccupied($free));
        self::assertSame('default', OwnershipRegistry::domainKind($free));

        // A crossing opened for one resource protects the other: admission on the joined member is refused during it.
        OwnershipRegistry::crossing($owned, function () use ($free): void {
            $refused = false;
            try {
                OwnershipRegistry::admit($free, 'reentry');
            } catch (OverlappingRequestException) {
                $refused = true;
            }
            self::assertTrue($refused, 'Host re-entry on the joined resource is refused while the owner is in a Host crossing.');
        });
        // Outside a crossing the owner's own nested operation is admitted on the joined member.
        self::assertNull(OwnershipRegistry::admit($free, 'nested-op'));

        $join->release();
        self::assertFalse(OwnershipRegistry::isOccupied($free), 'The joined reservation ends with the call.');
        self::assertSame('unbound', OwnershipRegistry::domainKind($free), 'The binding the join made is undone with it.');
        self::assertTrue(OwnershipRegistry::isOccupied($owned), 'The owner is untouched.');
        $owner->release();
        self::assertFalse(OwnershipRegistry::isOccupied($owned));
    }

    public function testTheOwnerEndingFirstEndsTheJoinedReservations(): void
    {
        $owned = $this->resource();
        $free = $this->resource();
        $owner = $this->own([$owned], 'owner');
        $join = OwnershipRegistry::admitSet([$owned, $free], 'nested');
        self::assertTrue(OwnershipRegistry::isOccupied($free));

        $owner->release(); // out of order, as when an owner's Fiber is destroyed

        self::assertFalse(OwnershipRegistry::isOccupied($free), 'No stale reservation outlives the owner.');
        self::assertSame('unbound', OwnershipRegistry::domainKind($free));
        $join->release(); // idempotent
        self::assertFalse(OwnershipRegistry::isOccupied($owned));
    }

    public function testARefusalChangesNothingNotEvenTheBindingOfAnUnboundMember(): void
    {
        $unbound = $this->resource();
        $a = $this->resource();
        $b = $this->resource();

        // Two members owned by two different calls (two tokens): refused, and the unbound member is not bound.
        $first = $this->own([$a], 'first');
        $second = $this->own([$b], 'second');
        try {
            OwnershipRegistry::admitSet([$a, $b, $unbound], 'nested');
            self::fail('Two owners cannot be joined.');
        } catch (OverlappingRequestException) {
            self::assertSame('unbound', OwnershipRegistry::domainKind($unbound));
            self::assertFalse(OwnershipRegistry::isOccupied($unbound));
        }
        $second->release();
        $first->release();

        // A member owned by another execution context: refused, nothing bound.
        $owned = $this->resource();
        $fiber = new \Fiber(function () use ($owned): void {
            $test = $this;
            $scope = $test->own([$owned], 'fiber-owner');
            \Fiber::suspend();
            $scope->release();
        });
        $fiber->start();
        try {
            OwnershipRegistry::admitSet([$owned, $unbound], 'main-context');
            self::fail('Another context owns a member.');
        } catch (OverlappingRequestException) {
            self::assertSame('unbound', OwnershipRegistry::domainKind($unbound));
        }
        $fiber->resume();
        self::assertFalse(OwnershipRegistry::isOccupied($owned));
    }

    public function testAMemberBoundToAnotherDomainRefusesTheWholeSetBeforeAnythingIsBound(): void
    {
        $provider = new StubContextProvider(static fn(): int => 1);
        $owned = $this->resource();
        $foreign = $this->resource();
        $unbound = $this->resource();
        OwnershipRegistry::bindAll([$foreign], $provider);
        $owner = $this->own([$owned], 'owner');

        try {
            OwnershipRegistry::admitSet([$owned, $foreign, $unbound], 'nested');
            self::fail('A member of another domain refuses the set.');
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('Inconsistent execution context domain', $exception->getMessage());
        }
        self::assertSame('provider', OwnershipRegistry::domainKind($foreign), 'The foreign binding is untouched.');
        self::assertSame('unbound', OwnershipRegistry::domainKind($unbound), 'No member was bound by the refused call.');
        self::assertFalse(OwnershipRegistry::isOccupied($foreign));
        self::assertFalse(OwnershipRegistry::isOccupied($unbound));
        $owner->release();
    }
}
