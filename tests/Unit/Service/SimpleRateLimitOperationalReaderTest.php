<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Service;

use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\Config\SimpleThrottlePolicyInterface;
use Maatify\RateLimiter\DTO\SimpleRateLimitOperationalSnapshotDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\FixedWindowSimpleRateLimiter;
use Maatify\RateLimiter\Service\SimpleRateLimitOperationalReader;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

final class SimpleRateLimitOperationalReaderTest extends TestCase
{
    private FixedClock $clock;
    private InMemoryRateLimitStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->store = new InMemoryRateLimitStore($this->clock);
    }

    public function testNoPersistedWindowReportsZeroCountAndNullBoundaries(): void
    {
        $reader = $this->reader([new FixedWindowThrottlePolicy('checkout', 3, 60)], 'current-secret');

        $snapshot = $reader->read('checkout', 'subject-1');

        self::assertSame(0, $snapshot->count);
        self::assertSame(3, $snapshot->remaining);
        self::assertNull($snapshot->epochStart);
        self::assertNull($snapshot->resetAt);
        self::assertFalse($snapshot->fromPreviousGeneration);
    }

    public function testStateCreatedThroughRealConsumeIsReadCorrectlyWithoutASecondMutation(): void
    {
        $limiter = $this->limiter([new FixedWindowThrottlePolicy('checkout', 3, 60)], 'current-secret');
        $limiter->consume('checkout', 'subject-2', 2);
        $limiter->consume('checkout', 'subject-2', 3);

        $reader = $this->reader([new FixedWindowThrottlePolicy('checkout', 3, 60)], 'current-secret');
        $writesBefore = $this->store->writeCount();
        $snapshot = $reader->read('checkout', 'subject-2');

        self::assertSame(5, $snapshot->count);
        self::assertSame(0, $snapshot->remaining);
        self::assertNotNull($snapshot->epochStart);
        self::assertSame($snapshot->epochStart + 60, $snapshot->resetAt);
        self::assertSame($writesBefore, $this->store->writeCount(), 'Reading must not mutate persisted state.');
    }

    public function testCountAboveLimitStillReportsPersistedCountWhileRemainingIsClampedToZero(): void
    {
        $limiter = $this->limiter([new FixedWindowThrottlePolicy('checkout', 1, 60)], 'current-secret');
        $limiter->consume('checkout', 'subject-3');
        $limiter->consume('checkout', 'subject-3');

        $reader = $this->reader([new FixedWindowThrottlePolicy('checkout', 1, 60)], 'current-secret');
        $snapshot = $reader->read('checkout', 'subject-3');

        self::assertSame(2, $snapshot->count);
        self::assertSame(0, $snapshot->remaining);
    }

    public function testCurrentStateWinsWhenCurrentAndPreviousBothExist(): void
    {
        $previousLimiter = $this->limiter([new FixedWindowThrottlePolicy('checkout', 5, 60)], 'previous-secret');
        $previousLimiter->consume('checkout', 'subject-4');

        $currentLimiter = $this->limiter([new FixedWindowThrottlePolicy('checkout', 5, 60)], 'current-secret');
        $currentLimiter->consume('checkout', 'subject-4');

        $reader = $this->reader(
            [new FixedWindowThrottlePolicy('checkout', 5, 60)],
            'current-secret',
            'previous-secret',
        );
        $snapshot = $reader->read('checkout', 'subject-4');

        self::assertSame(1, $snapshot->count);
        self::assertFalse($snapshot->fromPreviousGeneration);
    }

    public function testPreviousIsUsedOnlyWhenCurrentIsAbsent(): void
    {
        $previousLimiter = $this->limiter([new FixedWindowThrottlePolicy('checkout', 5, 60)], 'previous-secret');
        $previousLimiter->consume('checkout', 'subject-5');

        $reader = $this->reader(
            [new FixedWindowThrottlePolicy('checkout', 5, 60)],
            'current-secret',
            'previous-secret',
        );
        $snapshot = $reader->read('checkout', 'subject-5');

        self::assertSame(1, $snapshot->count);
        self::assertTrue($snapshot->fromPreviousGeneration);
    }

    public function testReadingPreviousDoesNotCreateCurrentDoesNotIncrementPreviousAndDoesNotChangeBoundary(): void
    {
        $previousLimiter = $this->limiter([new FixedWindowThrottlePolicy('checkout', 5, 60)], 'previous-secret');
        $previousLimiter->consume('checkout', 'subject-6');

        $reader = $this->reader(
            [new FixedWindowThrottlePolicy('checkout', 5, 60)],
            'current-secret',
            'previous-secret',
        );
        $writesBefore = $this->store->writeCount();

        $first = $reader->read('checkout', 'subject-6');
        $second = $reader->read('checkout', 'subject-6');

        self::assertSame($writesBefore, $this->store->writeCount(), 'Reading Previous must not mutate any persisted state.');
        self::assertSame(1, $first->count);
        self::assertSame(1, $second->count, 'Repeated reads must not increment Previous.');
        self::assertSame($first->epochStart, $second->epochStart);
        self::assertSame($first->resetAt, $second->resetAt);

        // Current must still be absent: a Current-secret consume must still
        // start a fresh window rather than continuing from a migrated state.
        $currentLimiter = $this->limiter([new FixedWindowThrottlePolicy('checkout', 5, 60)], 'current-secret');
        $freshConsume = $currentLimiter->consume('checkout', 'subject-6');
        self::assertSame(4, $freshConsume->remaining, 'A fresh Current epoch must start at count=1, not seeded from Previous.');
    }

    public function testBlankSubjectRaisesRateLimiterException(): void
    {
        $reader = $this->reader([new FixedWindowThrottlePolicy('checkout', 3, 60)], 'current-secret');

        $this->expectException(RateLimiterException::class);
        $reader->read('checkout', '   ');
    }

    public function testInvalidDirectlyImplementedPolicyIsRejectedBeforeStorageAccess(): void
    {
        $invalidPolicy = new class implements SimpleThrottlePolicyInterface {
            public function getName(): string
            {
                return 'invalid';
            }

            public function getLimit(): int
            {
                return 0;
            }

            public function getIntervalSeconds(): int
            {
                return 60;
            }
        };

        $writesBefore = $this->store->writeCount();

        $this->expectException(RateLimiterException::class);
        try {
            new SimpleRateLimitOperationalReader([$invalidPolicy], $this->store, $this->clock, 'current-secret', 'prod');
        } finally {
            self::assertSame($writesBefore, $this->store->writeCount(), 'Validation must fail before any storage access.');
        }
    }

    public function testJsonOutputContainsNoRawSubjectKeySecretsOrPhysicalKeys(): void
    {
        $limiter = $this->limiter([new FixedWindowThrottlePolicy('checkout', 3, 60)], 'current-secret');
        $limiter->consume('checkout', 'raw-subject-marker-value');

        $reader = $this->reader([new FixedWindowThrottlePolicy('checkout', 3, 60)], 'current-secret');
        $snapshot = $reader->read('checkout', 'raw-subject-marker-value');
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR);

        $physicalKey = hash_hmac(
            'sha256',
            pack('N', strlen('rate_limiter')) . 'rate_limiter'
            . pack('N', strlen('simple_fixed_window')) . 'simple_fixed_window'
            . pack('N', strlen('v1')) . 'v1'
            . pack('N', strlen('prod')) . 'prod'
            . pack('N', strlen('checkout')) . 'checkout'
            . pack('N', strlen('3')) . '3'
            . pack('N', strlen('60')) . '60'
            . pack('N', strlen('raw-subject-marker-value')) . 'raw-subject-marker-value',
            'current-secret',
        );

        self::assertStringNotContainsString('raw-subject-marker-value', $json);
        self::assertStringNotContainsString('current-secret', $json);
        self::assertStringNotContainsString($physicalKey, $json);
    }

    public function testReadPathInvokesNoSimpleMutationPrimitive(): void
    {
        $limiter = $this->limiter([new FixedWindowThrottlePolicy('checkout', 3, 60)], 'current-secret');
        $limiter->consume('checkout', 'subject-7');

        $reader = $this->reader([new FixedWindowThrottlePolicy('checkout', 3, 60)], 'current-secret');
        $writesBefore = $this->store->writeCount();
        $reader->read('checkout', 'subject-7');

        self::assertSame($writesBefore, $this->store->writeCount());
    }

    /**
     * @param SimpleThrottlePolicyInterface[] $policies
     */
    private function limiter(array $policies, string $keySecret, ?string $previousKeySecret = null): FixedWindowSimpleRateLimiter
    {
        return new FixedWindowSimpleRateLimiter($policies, $this->store, $this->clock, $keySecret, 'prod', $previousKeySecret);
    }

    /**
     * @param SimpleThrottlePolicyInterface[] $policies
     */
    private function reader(array $policies, string $keySecret, ?string $previousKeySecret = null): SimpleRateLimitOperationalReader
    {
        return new SimpleRateLimitOperationalReader($policies, $this->store, $this->clock, $keySecret, 'prod', $previousKeySecret);
    }
}
