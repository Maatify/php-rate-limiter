<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Service;

use Fiber;
use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\DTO\SimpleRateLimitResultDTO;
use Maatify\RateLimiter\Service\FixedWindowSimpleRateLimiter;
use Maatify\RateLimiter\Service\OwnershipRegistry;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Ownership\HostStore;
use Maatify\RateLimiter\Tests\Support\Ownership\HostStoreWithClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * DEC-041, F266-14, through the public entry: two `FixedWindowSimpleRateLimiter` instances that share a Host store which implements
 * only `RateLimitStoreInterface` (never the package's Redis store) and has a method named like the package's internal inventory method.
 * The store is protected as a shared resource by its object identity, also when it implements the clock interface as well (the
 * exclusion of a raw Host clock is by role, not by type). The refusal string is the class name so the test also runs on a source
 * without the refusal.
 */
#[Group('unit')]
#[Group('php32')]
#[Group('ownership-260')]
final class HostStoreOwnershipTest extends TestCase
{
    private const REFUSAL = 'Maatify\\RateLimiter\\Exception\\OverlappingRequestException';

    /** @return array<string, array{HostStore}> */
    public static function stores(): array
    {
        return [
            'a Host store' => [new HostStore()],
            'a Host store that is also a clock' => [new HostStoreWithClock()],
        ];
    }

    private function limiter(HostStore $store): FixedWindowSimpleRateLimiter
    {
        return new FixedWindowSimpleRateLimiter([new FixedWindowThrottlePolicy('api', 5, 60)], $store, new FixedClock('@1000000'), 'secret', 'prod');
    }

    #[DataProvider('stores')]
    public function testASecondRuntimeSharingAHostStoreIsRefusedBeforeItTouchesTheStoreAndTheFirstCompletes(HostStore $store): void
    {
        $first = $this->limiter($store);
        $second = $this->limiter($store);
        self::assertSame(0, $store->inventoryCalls, 'building the runtimes never called the Host method');

        $a = new Fiber(fn() => $first->consume('api', 'subject-a'));
        $store->suspendIn = $a;
        $a->start();
        self::assertFalse($a->isTerminated(), 'the first call is suspended inside the Host store');
        $callsInFlight = $store->calls;

        $outcome = null;
        try {
            $second->consume('api', 'subject-b');
        } catch (\Throwable $caught) {
            $outcome = $caught::class;
        }

        self::assertSame(self::REFUSAL, $outcome, 'the second runtime is refused');
        self::assertSame($callsInFlight, $store->calls, 'the refused call never reached the store');
        self::assertSame(0, $store->inventoryCalls, 'the Host inventory method was never called');

        $store->suspendIn = null;
        $a->resume();
        self::assertTrue($a->isTerminated(), 'the first call completes');
        $result = $a->getReturn();
        self::assertInstanceOf(SimpleRateLimitResultDTO::class, $result);
        self::assertTrue($result->allowed);
        self::assertFalse(OwnershipRegistry::isOccupied($store), 'everything was released');

        self::assertTrue($second->consume('api', 'subject-b')->allowed, 'the next call after the release runs');
        self::assertSame(0, $store->inventoryCalls);
    }

    #[DataProvider('stores')]
    public function testTheInventoryKeepsTheStoreWhateverInterfacesItImplements(HostStore $store): void
    {
        $clock = new FixedClock('@1000000');

        self::assertSame([$store], OwnershipRegistry::collect([$store], [], [$clock]), 'the raw clock is excluded by its role, the store stays');
        self::assertSame([$store, $clock], OwnershipRegistry::collect([$store, $clock], [], []), 'an object passed in the store role is a resource whatever it implements');
        self::assertSame(0, $store->inventoryCalls);
    }
}
