<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\BoundedCorrelationResultValidator;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BoundedCorrelationSnapshotStoreContractTest extends TestCase
{
    private FixedClock $clock;
    private StatefulInMemoryCorrelationStore $store;

    protected function setUp(): void
    {
        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->store = new StatefulInMemoryCorrelationStore($this->clock);
    }

    public function testSnapshotReportsAdmissionDuplicatesAndBoundedMembers(): void
    {
        $first = $this->store->addDistinctBoundedWithSnapshot('set', 'one', 600, 4);
        self::assertSame(1, $first->count);
        self::assertTrue($first->accepted);
        self::assertTrue($first->added);
        self::assertSame(['one'], $first->members);

        $duplicate = $this->store->addDistinctBoundedWithSnapshot('set', 'one', 600, 4);
        self::assertTrue($duplicate->accepted);
        self::assertFalse($duplicate->added);
        self::assertSame($first->expiresAt, $duplicate->expiresAt);
        self::assertSame($first->members, $duplicate->members);

        foreach (['two', 'three', 'four'] as $member) {
            $snapshot = $this->store->addDistinctBoundedWithSnapshot('set', $member, 600, 4);
            self::assertTrue($snapshot->accepted);
            self::assertTrue($snapshot->added);
        }

        $rejected = $this->store->addDistinctBoundedWithSnapshot('set', 'five', 600, 4);
        self::assertFalse($rejected->accepted);
        self::assertFalse($rejected->added);
        self::assertSame(4, $rejected->count);
        self::assertCount(4, $rejected->members);
        self::assertSame(['one', 'two', 'three', 'four'], $this->store->distinctItems('set'));
    }

    public function testSnapshotExpiryIsFixedAndDuplicateDoesNotRefreshIt(): void
    {
        $first = $this->store->addDistinctBoundedWithSnapshot('set', 'one', 600, 4);
        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:01:00'));
        $duplicate = $this->store->addDistinctBoundedWithSnapshot('set', 'one', 600, 4);

        self::assertSame($first->expiresAt, $duplicate->expiresAt);
        self::assertSame($first->expiresAt, $this->store->distinctExpiresAt('set'));
    }

    public function testInvalidTtlAndCapacityFailExplicitly(): void
    {
        $this->expectException(RateLimiterException::class);
        $this->store->addDistinctBoundedWithSnapshot('set', 'one', 0, 4);
    }

    public function testInvalidCapacityFailsExplicitly(): void
    {
        $this->expectException(RateLimiterException::class);
        $this->store->addDistinctBoundedWithSnapshot('set', 'one', 600, 0);
    }

    public function testRotationSnapshotUsesPreviousPlusBridgeAndLeavesPreviousReadOnly(): void
    {
        $this->store->addDistinct('previous', 'old', 600);
        $previousItems = $this->store->distinctItems('previous');
        $previousExpiry = $this->store->distinctExpiresAt('previous');

        $snapshot = $this->store->addDistinctBoundedWithSnapshotAcrossRotation(
            'current',
            'bridge',
            'previous',
            'new',
            'new-old-alias',
            600,
            4,
        );

        self::assertSame(2, $snapshot->count);
        self::assertTrue($snapshot->accepted);
        self::assertTrue($snapshot->added);
        self::assertSame(['old', 'new'], $snapshot->members);
        self::assertSame($previousItems, $this->store->distinctItems('previous'));
        self::assertSame($previousExpiry, $this->store->distinctExpiresAt('previous'));
        self::assertSame($previousExpiry, $snapshot->expiresAt);
        self::assertSame($previousExpiry, $this->store->distinctExpiresAt('bridge'));

        $duplicate = $this->store->addDistinctBoundedWithSnapshotAcrossRotation(
            'current',
            'bridge',
            'previous',
            'new',
            'new-old-alias',
            600,
            4,
        );
        self::assertTrue($duplicate->accepted);
        self::assertFalse($duplicate->added);
        self::assertSame($snapshot->members, $duplicate->members);
    }

    public function testEqualKeyAliasDoesNotDoubleCountAndRotatedCapRejectsWithoutMutation(): void
    {
        $this->store->addDistinct('same', 'old', 600);
        $known = $this->store->addDistinctBoundedWithSnapshotAcrossRotation(
            'same',
            'bridge',
            'same',
            'new',
            'old',
            600,
            1,
        );
        self::assertSame(1, $known->count);
        self::assertTrue($known->accepted);
        self::assertFalse($known->added);
        self::assertSame(['old'], $known->members);

        $rejected = $this->store->addDistinctBoundedWithSnapshotAcrossRotation(
            'same',
            'bridge',
            'same',
            'third',
            'third-old',
            600,
            1,
        );
        self::assertFalse($rejected->accepted);
        self::assertFalse($rejected->added);
        self::assertSame(['old'], $rejected->members);
        self::assertSame([], $this->store->distinctItems('bridge'));
    }

    #[DataProvider('malformedSnapshots')]
    public function testMalformedSnapshotsFailClosed(BoundedDistinctSnapshotDTO $snapshot): void
    {
        $this->expectException(RateLimiterException::class);
        BoundedCorrelationResultValidator::snapshot($snapshot, 4, 1000, 600, 'current');
    }

    /** @return array<string, array{BoundedDistinctSnapshotDTO}> */
    public static function malformedSnapshots(): array
    {
        return [
            'zero count' => [new BoundedDistinctSnapshotDTO(0, true, false, [], 1100)],
            'count mismatch' => [new BoundedDistinctSnapshotDTO(2, true, true, ['one'], 1100)],
            'duplicate members' => [new BoundedDistinctSnapshotDTO(2, true, true, ['one', 'one'], 1100)],
            'empty member' => [new BoundedDistinctSnapshotDTO(1, true, true, [''], 1100)],
            'invalid expiry' => [new BoundedDistinctSnapshotDTO(1, true, true, ['one'], 1000)],
            'expiry beyond ttl' => [new BoundedDistinctSnapshotDTO(1, true, true, ['one'], 1601)],
            'rejected added' => [new BoundedDistinctSnapshotDTO(4, false, true, ['one', 'two', 'three', 'four'], 1100)],
            'rejected below cap' => [new BoundedDistinctSnapshotDTO(3, false, false, ['one', 'two', 'three'], 1100)],
            'added not accepted' => [new BoundedDistinctSnapshotDTO(1, false, true, ['one'], 1100)],
            'known member omitted' => [new BoundedDistinctSnapshotDTO(1, true, false, ['other'], 1100)],
        ];
    }
}
