<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use PHPUnit\Framework\TestCase;

final class BoundedCorrelationStoreContractTest extends TestCase
{
    private FixedClock $clock;
    private StatefulInMemoryCorrelationStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->store = new StatefulInMemoryCorrelationStore($this->clock);
    }

    public function testDistinctMembersAreAcceptedOnlyUntilCapacityAndDuplicatesStayAccepted(): void
    {
        self::assertSame(1, $this->store->addDistinctBounded('set', 'one', 600, 2)->count);
        self::assertTrue($this->store->addDistinctBounded('set', 'one', 600, 2)->accepted);
        self::assertSame(1, $this->store->addDistinctBounded('set', 'one', 600, 2)->count);
        self::assertTrue($this->store->addDistinctBounded('set', 'two', 600, 2)->accepted);

        $rejected = $this->store->addDistinctBounded('set', 'three', 600, 2);
        self::assertSame(2, $rejected->count);
        self::assertFalse($rejected->accepted);
        self::assertSame(['one', 'two'], $this->store->distinctItems('set'));

        $rejectedAgain = $this->store->addDistinctBounded('set', 'three', 600, 2);
        self::assertSame(2, $rejectedAgain->count);
        self::assertFalse($rejectedAgain->accepted);
        self::assertSame(['one', 'two'], $this->store->distinctItems('set'));
    }

    public function testBoundedTtlIsEstablishedOnceAndNeverRefreshed(): void
    {
        $this->store->addDistinctBounded('set', 'one', 600, 3);
        $expiry = $this->store->distinctExpiresAt('set');
        self::assertNotNull($expiry);

        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:01:00'));
        $this->store->addDistinctBounded('set', 'two', 600, 3);
        self::assertSame($expiry, $this->store->distinctExpiresAt('set'));
    }

    public function testInvalidArgumentsAndCorruptOverCapacityStateFailExplicitly(): void
    {
        $this->expectException(RateLimiterException::class);
        $this->store->addDistinctBounded('set', 'one', 0, 1);
    }

    public function testInvalidCapacityFailsExplicitly(): void
    {
        $this->expectException(RateLimiterException::class);
        $this->store->addDistinctBounded('set', 'one', 600, 0);
    }

    public function testCorruptOverCapacityStateFailsInsteadOfClamping(): void
    {
        $this->store->addDistinct('set', 'one', 600);
        $this->store->addDistinct('set', 'two', 600);

        $this->expectException(RateLimiterException::class);
        $this->store->addDistinctBounded('set', 'three', 600, 1);
    }

    public function testRotationKeepsPreviousReadOnlyAndBoundsBridgeToPreviousTtl(): void
    {
        $this->store->addDistinct('previous', 'old', 600);
        $previousItems = $this->store->distinctItems('previous');
        $previousExpiry = $this->store->distinctExpiresAt('previous');

        $result = $this->store->addDistinctBoundedAcrossRotation(
            'current',
            'bridge',
            'previous',
            'new',
            'new-old-alias',
            600,
            2,
        );

        self::assertSame(2, $result->count);
        self::assertTrue($result->accepted);
        self::assertSame($previousItems, $this->store->distinctItems('previous'));
        self::assertSame($previousExpiry, $this->store->distinctExpiresAt('previous'));
        self::assertSame($previousExpiry, $this->store->distinctExpiresAt('bridge'));
        self::assertSame(['new'], $this->store->distinctItems('bridge'));
    }

    public function testKnownPreviousMemberDoesNotDoubleCountAndDoesNotEnterBridge(): void
    {
        $this->store->addDistinct('previous', 'old', 600);
        $result = $this->store->addDistinctBoundedAcrossRotation(
            'current',
            'bridge',
            'previous',
            'current-old',
            'old',
            600,
            1,
        );

        self::assertSame(1, $result->count);
        self::assertTrue($result->accepted);
        self::assertSame([], $this->store->distinctItems('bridge'));
        self::assertSame(['old'], $this->store->distinctItems('previous'));
    }

    public function testRotationRejectsAtomicallyAtEffectiveCapacity(): void
    {
        $this->store->addDistinct('previous', 'old', 600);
        $this->store->addDistinctBoundedAcrossRotation('current', 'bridge', 'previous', 'new', 'unknown', 600, 2);
        $currentItems = $this->store->distinctItems('current');
        $bridgeItems = $this->store->distinctItems('bridge');

        $rejected = $this->store->addDistinctBoundedAcrossRotation(
            'current',
            'bridge',
            'previous',
            'rejected',
            'rejected-old',
            600,
            2,
        );

        self::assertSame(2, $rejected->count);
        self::assertFalse($rejected->accepted);
        self::assertSame($currentItems, $this->store->distinctItems('current'));
        self::assertSame($bridgeItems, $this->store->distinctItems('bridge'));
    }

    public function testEqualPhysicalKeyDeduplicatesFingerprintOnlyAlias(): void
    {
        $this->store->addDistinct('same', 'previous-member', 600);

        $alias = $this->store->addDistinctBoundedAcrossRotation(
            'same',
            'unused-bridge',
            'same',
            'current-member',
            'previous-member',
            600,
            2,
        );
        self::assertSame(1, $alias->count);
        self::assertTrue($alias->accepted);

        $new = $this->store->addDistinctBoundedAcrossRotation(
            'same',
            'unused-bridge',
            'same',
            'new-member',
            'new-previous-member',
            600,
            2,
        );
        self::assertSame(2, $new->count);
        self::assertTrue($new->accepted);

        $rejected = $this->store->addDistinctBoundedAcrossRotation(
            'same',
            'unused-bridge',
            'same',
            'over-cap',
            'over-cap-previous',
            600,
            2,
        );
        self::assertSame(2, $rejected->count);
        self::assertFalse($rejected->accepted);
    }

    public function testCorruptPreviousTtlFailsBeforeCurrentOrBridgeMutation(): void
    {
        $this->store->addDistinct('previous', 'old', 600);
        $this->store->corruptDistinctTtl('previous');

        $this->expectException(RateLimiterException::class);
        try {
            $this->store->addDistinctBoundedAcrossRotation('current', 'bridge', 'previous', 'new', 'old', 600, 2);
        } finally {
            self::assertSame([], $this->store->distinctItems('current'));
            self::assertSame([], $this->store->distinctItems('bridge'));
        }
    }
}
