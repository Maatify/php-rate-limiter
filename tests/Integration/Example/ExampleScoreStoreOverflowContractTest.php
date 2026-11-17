<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Example;

use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * #252: the runnable score stores of the three maintained examples are copied by consumers as implementations of `RateLimitStoreInterface::increment()`,
 * whose contract is: a result outside the native `int` range of the caller's PHP build fails with `RateLimiterException` (the failure of the examples' own calculation, as exercised here; a backend script error reaches a caller as its Host executor surfaces it, DEC-018) BEFORE any stored value, timestamp
 * or expiry changes (no clamp, no wrap, no value the caller cannot read back). They used to add unchecked, store the PHP float and only then fail with a
 * `TypeError` on their `int` return type.
 *
 * The tests call the examples' real public `increment()` (never a copy of the arithmetic) with the SAME logical inputs on both widths, expressed with
 * `PHP_INT_MAX` / `PHP_INT_MIN` of the running build (the contract is the caller's own range, so these are the boundaries of each width). The record is
 * observed by reflection, read-only, and compared as a whole, with its types: a refusal must leave the value (an `int`, never a float), `updatedAt` and
 * `expiresAt` exactly as they were. Where the store takes a clock the clock is advanced between the successful write and the refused call, and the refused call
 * passes a different TTL, so a refusal that touched the time or the expiry would be visible with no sleep. `SimpleThrottleExampleStore` reads `time()`
 * itself and cannot be driven, so `testARefusalLeavesAnOldTimestampAloneOnEveryExample` seeds its record, by reflection, with an `updatedAt` and an `expiresAt`
 * that differ from the real `time()` (F253-02): a refusal that rewrote `updatedAt` inside the same second would otherwise be invisible.
 */
#[Group('integration')]
#[Group('php32')]
#[Group('example-score-overflow')]
final class ExampleScoreStoreOverflowContractTest extends TestCase
{
    private const KEY = 'overflow-control';

    public static function setUpBeforeClass(): void
    {
        ob_start();
        require_once dirname(__DIR__, 3) . '/examples/basic-rate-limit.php';
        require_once dirname(__DIR__, 3) . '/examples/operational-read.php';
        require_once dirname(__DIR__, 3) . '/examples/simple-fixed-window.php';
        ob_end_clean();
    }

    /** @return array<string, array{string, bool}> example name, whether its clock can be driven */
    public static function examples(): array
    {
        return ['basic' => ['basic', true], 'operational' => ['operational', true], 'simple' => ['simple', false]];
    }

    private function store(string $example, FixedClock $clock): RateLimitStoreInterface
    {
        return match ($example) {
            'basic' => new \ExampleRateLimitStore($clock),
            'operational' => new \OperationalExampleRateLimitStore($clock),
            'simple' => new \SimpleThrottleExampleStore(),
            default => throw new \LogicException($example),
        };
    }

    /** @return array<string, mixed> the whole observable record of the store, read without touching it */
    private function observe(object $store): array
    {
        $observed = ['counters' => (new ReflectionProperty($store, 'counters'))->getValue($store)];
        if (property_exists($store, 'writes')) {
            $observed['writes'] = (new ReflectionProperty($store, 'writes'))->getValue($store);
        }

        return $observed;
    }

    /** @return array<mixed> the one record of the control key, read without touching the store */
    private function record(object $store): array
    {
        $counters = $this->observe($store)['counters'];
        self::assertIsArray($counters);
        $record = $counters[self::KEY] ?? null;
        self::assertIsArray($record);

        return $record;
    }

    private function advance(FixedClock $clock, int $seconds): void
    {
        $clock->setNow($clock->now()->modify('+' . $seconds . ' seconds'));
    }

    /** @param callable(): int $increment */
    private function assertRefusedUnchanged(object $store, FixedClock $clock, bool $drivable, callable $increment, string $label): void
    {
        $before = $this->observe($store);
        if ($drivable) {
            $this->advance($clock, 1);
        }
        $caught = null;
        try {
            $increment();
        } catch (\Throwable $exception) {
            $caught = $exception;
        }
        self::assertSame($before, $this->observe($store), $label . ': the refused call left the whole record (value, updatedAt, expiresAt, write count) exactly as it was.');
        self::assertInstanceOf(RateLimiterException::class, $caught, $label . ': an out-of-range result is refused with RateLimiterException.');
    }

    #[DataProvider('examples')]
    public function testCreationAndInRangeIncrementsAreUnchanged(string $example, bool $drivable): void
    {
        $store = $this->store($example, new FixedClock());

        self::assertSame(5, $store->increment(self::KEY, 600, 5));
        self::assertSame(8, $store->increment(self::KEY, 600, 3));
        self::assertSame(6, $store->increment(self::KEY, 600, -2));
        self::assertSame(6, $store->increment(self::KEY, 600, 0));
        $record = $this->record($store);
        self::assertSame(6, $record['value']);
        self::assertIsInt($record['expiresAt']);
    }

    #[DataProvider('examples')]
    public function testTheUpperBoundaryIsReachedExactlyAndOneStepBeyondIsRefusedUnchanged(string $example, bool $drivable): void
    {
        $clock = new FixedClock();
        $store = $this->store($example, $clock);

        self::assertSame(PHP_INT_MAX - 1, $store->increment(self::KEY, 600, PHP_INT_MAX - 1));
        self::assertSame(PHP_INT_MAX, $store->increment(self::KEY, 600, 1));
        self::assertSame(PHP_INT_MAX, $this->record($store)['value']);

        $this->assertRefusedUnchanged($store, $clock, $drivable, fn(): int => $store->increment(self::KEY, 7200, 1), 'MAX + 1');
        $this->assertRefusedUnchanged($store, $clock, $drivable, fn(): int => $store->increment(self::KEY, 7200, PHP_INT_MAX), 'MAX + MAX');
        self::assertSame(PHP_INT_MAX, $store->increment(self::KEY, 7200, 0), 'An amount of zero is still no change.');
    }

    #[DataProvider('examples')]
    public function testAHugeAmountOverAnOrdinaryValueIsRefusedUnchanged(string $example, bool $drivable): void
    {
        $clock = new FixedClock();
        $store = $this->store($example, $clock);
        $store->increment(self::KEY, 600, 2);

        $this->assertRefusedUnchanged($store, $clock, $drivable, fn(): int => $store->increment(self::KEY, 7200, PHP_INT_MAX), '2 + MAX');
        self::assertSame(PHP_INT_MAX, $store->increment('other-key', 600, PHP_INT_MAX), 'A new record may hold the maximum.');
    }

    #[DataProvider('examples')]
    public function testTheLowerBoundaryIsReachedExactlyAndOneStepBeyondIsRefusedUnchanged(string $example, bool $drivable): void
    {
        $clock = new FixedClock();
        $store = $this->store($example, $clock);

        self::assertSame(PHP_INT_MIN + 1, $store->increment(self::KEY, 600, PHP_INT_MIN + 1));
        self::assertSame(PHP_INT_MIN, $store->increment(self::KEY, 600, -1));
        self::assertSame(PHP_INT_MIN, $this->record($store)['value']);

        $this->assertRefusedUnchanged($store, $clock, $drivable, fn(): int => $store->increment(self::KEY, 7200, -1), 'MIN - 1');
        $this->assertRefusedUnchanged($store, $clock, $drivable, fn(): int => $store->increment(self::KEY, 7200, PHP_INT_MIN), 'MIN + MIN');
    }

    #[DataProvider('examples')]
    public function testAHugeNegativeAmountOverANegativeValueIsRefusedUnchanged(string $example, bool $drivable): void
    {
        $clock = new FixedClock();
        $store = $this->store($example, $clock);
        $store->increment(self::KEY, 600, -1);

        $this->assertRefusedUnchanged($store, $clock, $drivable, fn(): int => $store->increment(self::KEY, 7200, PHP_INT_MIN), '-1 + MIN');
        self::assertSame(PHP_INT_MIN + 1, $store->increment('other-key', 600, PHP_INT_MIN + 1));
    }

    /**
     * An expired existing record sitting at the boundary is replaced by a valid amount (it is not an overflow). The expired record is seeded by reflection with
     * a positive old `updatedAt` and `expiresAt`, so the expiry predicate of a PRESENT key is exercised on every example, `SimpleThrottleExampleStore` included
     * (it reads `time()`, so its new lifetime is bounded by the real clock around the call).
     */
    #[DataProvider('examples')]
    public function testAnExpiredRecordAtTheBoundaryIsReplacedByAValidAmount(string $example, bool $drivable): void
    {
        $clock = new FixedClock();
        $store = $this->store($example, $clock);
        $reference = $example === 'simple' ? time() : $clock->now()->getTimestamp();
        (new ReflectionProperty($store, 'counters'))->setValue($store, [
            self::KEY => ['value' => PHP_INT_MAX, 'updatedAt' => $reference - 2000, 'expiresAt' => $reference - 1000],
        ]);

        $before = time();
        self::assertSame(3, $store->increment(self::KEY, 600, 3), 'An expired MAX is replaced, not incremented into an overflow.');
        $after = time();
        $record = $this->record($store);
        self::assertSame(3, $record['value']);
        if ($drivable) {
            self::assertSame($clock->now()->getTimestamp(), $record['updatedAt']);
            self::assertSame($clock->now()->getTimestamp() + 600, $record['expiresAt']);
        } else {
            self::assertIsInt($record['updatedAt']);
            self::assertIsInt($record['expiresAt']);
            self::assertGreaterThanOrEqual($before, $record['updatedAt']);
            self::assertLessThanOrEqual($after, $record['updatedAt']);
            self::assertGreaterThanOrEqual($before + 600, $record['expiresAt']);
            self::assertLessThanOrEqual($after + 600, $record['expiresAt']);
        }
    }

    /** The Operational Read example counts every score write: creation and a successful update add one each, a refusal and a zero-write read add none. */
    public function testTheOperationalWriteCountRisesOnEachSuccessfulScoreWrite(): void
    {
        $clock = new FixedClock();
        $store = new \OperationalExampleRateLimitStore($clock);
        self::assertSame(0, $store->writeCount());

        $store->increment(self::KEY, 600, PHP_INT_MAX - 1);
        self::assertSame(1, $store->writeCount(), 'Creation is one write.');
        $store->increment(self::KEY, 600, 1);
        self::assertSame(2, $store->writeCount(), 'A successful update of a live record is one write.');
        $store->increment(self::KEY, 600, 0);
        self::assertSame(3, $store->writeCount(), 'An in-range zero increment is a successful update.');

        try {
            $store->increment(self::KEY, 600, 1);
            self::fail('MAX + 1 must be refused.');
        } catch (RateLimiterException) {
        }
        self::assertSame(3, $store->writeCount(), 'The refusal adds no write.');
    }

    /** A live record's TTL is not renewed by a successful increment, and the refusal is a different path from this one. */
    #[DataProvider('examples')]
    public function testASuccessfulIncrementOfALiveRecordKeepsItsExpiry(string $example, bool $drivable): void
    {
        $clock = new FixedClock();
        $store = $this->store($example, $clock);
        $store->increment(self::KEY, 600, 4);
        $expiresAt = $this->record($store)['expiresAt'];
        if ($drivable) {
            $this->advance($clock, 5);
        }

        self::assertSame(5, $store->increment(self::KEY, 7200, 1));
        self::assertSame($expiresAt, $this->record($store)['expiresAt']);
    }

    /** F253-02: the record is seeded with a timestamp far from the current time, so a refusal that wrote `updatedAt` (to the clock or to `time()`) changes it. */
    #[DataProvider('examples')]
    public function testARefusalLeavesAnOldTimestampAloneOnEveryExample(string $example, bool $drivable): void
    {
        $clock = new FixedClock();
        $store = $this->store($example, $clock);
        $now = $clock->now()->getTimestamp();
        $seeded = [self::KEY => ['value' => PHP_INT_MAX, 'updatedAt' => $now - 1000, 'expiresAt' => $now + 5000]];
        if ($example === 'simple') {
            $seeded[self::KEY]['updatedAt'] = time() - 1000;
            $seeded[self::KEY]['expiresAt'] = time() + 5000;
        }
        (new ReflectionProperty($store, 'counters'))->setValue($store, $seeded);

        $this->assertRefusedUnchanged($store, $clock, $drivable, fn(): int => $store->increment(self::KEY, 7200, 1), 'seeded MAX + 1');
        $record = $this->record($store);
        self::assertSame($seeded[self::KEY]['updatedAt'], $record['updatedAt'], 'The old updatedAt is untouched by the refusal.');
        self::assertSame($seeded[self::KEY]['expiresAt'], $record['expiresAt']);
    }
}
