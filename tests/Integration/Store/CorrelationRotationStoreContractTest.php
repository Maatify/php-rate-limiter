<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\CorrelationRotationStoreInterface;
use Maatify\RateLimiter\Repository\CorrelationStoreInterface;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\NullCorrelationStore;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use PHPUnit\Framework\TestCase;

final class CorrelationRotationStoreContractTest extends TestCase
{
    private FixedClock $clock;
    private StatefulInMemoryCorrelationStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->store = new StatefulInMemoryCorrelationStore($this->clock);
    }

    public function testBaseContractRemainsUnchangedAndLegacyStoreStaysValid(): void
    {
        self::assertSame(
            ['addDistinct', 'incrementWatchFlag', 'getWatchFlag'],
            array_map(
                static fn(\ReflectionMethod $method): string => $method->getName(),
                (new \ReflectionClass(CorrelationStoreInterface::class))->getMethods(),
            ),
        );
        self::assertInstanceOf(CorrelationStoreInterface::class, new NullCorrelationStore());
        self::assertInstanceOf(CorrelationRotationStoreInterface::class, $this->store);
    }

    public function testBaseWindowsKeepTheirInitialTtl(): void
    {
        $this->store->addDistinct('base-set', 'one', 600);
        $this->store->incrementWatchFlag('base-watch', 1800);
        $setExpiry = $this->store->distinctExpiresAt('base-set');
        $watchExpiry = $this->store->watchExpiresAt('base-watch');

        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:01:00'));
        $this->store->addDistinct('base-set', 'two', 600);
        $this->store->incrementWatchFlag('base-watch', 1800);

        self::assertSame($setExpiry, $this->store->distinctExpiresAt('base-set'));
        self::assertSame($watchExpiry, $this->store->watchExpiresAt('base-watch'));
    }

    public function testDistinctRotationDeduplicatesAndCapsBridgeTtlWithoutWritingPrevious(): void
    {
        $this->store->addDistinct('previous', 'old-subject', 600);
        $previousItems = $this->store->distinctItems('previous');
        $previousExpiry = $this->store->distinctExpiresAt('previous');

        self::assertSame(
            1,
            $this->store->addDistinctAcrossRotation(
                'current',
                'bridge',
                'previous',
                'current-old-subject',
                'old-subject',
                600,
            ),
        );
        self::assertSame(['current-old-subject'], $this->store->distinctItems('current'));
        self::assertSame([], $this->store->distinctItems('bridge'));

        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:01:00'));
        self::assertSame(
            2,
            $this->store->addDistinctAcrossRotation(
                'current',
                'bridge',
                'previous',
                'current-new-subject',
                'new-subject',
                600,
            ),
        );

        self::assertSame(['current-old-subject', 'current-new-subject'], $this->store->distinctItems('current'));
        self::assertSame(['current-new-subject'], $this->store->distinctItems('bridge'));
        self::assertSame($previousItems, $this->store->distinctItems('previous'));
        self::assertSame($previousExpiry, $this->store->distinctExpiresAt('previous'));
        self::assertSame($previousExpiry, $this->store->distinctExpiresAt('bridge'));

        $bridgeExpiry = $this->store->distinctExpiresAt('bridge');
        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:02:00'));
        self::assertSame(
            3,
            $this->store->addDistinctAcrossRotation(
                'current',
                'bridge',
                'previous',
                'current-third-subject',
                'third-subject',
                600,
            ),
        );
        self::assertSame($bridgeExpiry, $this->store->distinctExpiresAt('bridge'));

        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:10:00'));
        self::assertSame(
            1,
            $this->store->addDistinctAcrossRotation(
                'current',
                'bridge',
                'previous',
                'current-after-expiry',
                'after-expiry',
                600,
            ),
        );
        self::assertSame(['current-after-expiry'], $this->store->distinctItems('current'));
    }

    public function testWatchRotationAddsOnlyCurrentAndKeepsBothTtlsFixed(): void
    {
        $this->store->incrementWatchFlag('previous-watch', 1800);
        $this->store->incrementWatchFlag('previous-watch', 1800);
        $previousExpiry = $this->store->watchExpiresAt('previous-watch');

        self::assertSame(
            3,
            $this->store->incrementWatchFlagAcrossRotation('current-watch', 'previous-watch', 1800),
        );
        self::assertSame(2, $this->store->watchValue('previous-watch'));
        self::assertSame(1, $this->store->watchValue('current-watch'));

        $currentExpiry = $this->store->watchExpiresAt('current-watch');
        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:01:00'));
        self::assertSame(
            4,
            $this->store->incrementWatchFlagAcrossRotation('current-watch', 'previous-watch', 1800),
        );
        self::assertSame(2, $this->store->watchValue('current-watch'));
        self::assertSame($currentExpiry, $this->store->watchExpiresAt('current-watch'));
        self::assertSame($previousExpiry, $this->store->watchExpiresAt('previous-watch'));
        self::assertSame(2, $this->store->watchValue('previous-watch'));
    }

    public function testCorruptPreviousDistinctStateFailsBeforePartialMutation(): void
    {
        $this->store->addDistinct('previous', 'old-subject', 600);
        $this->store->corruptDistinctTtl('previous');

        $this->expectException(RateLimiterException::class);
        try {
            $this->store->addDistinctAcrossRotation(
                'current',
                'bridge',
                'previous',
                'current-subject',
                'old-subject',
                600,
            );
        } finally {
            self::assertSame([], $this->store->distinctItems('current'));
            self::assertSame([], $this->store->distinctItems('bridge'));
        }
    }

    public function testCorruptPreviousWatchFailsBeforePartialMutation(): void
    {
        $this->store->incrementWatchFlag('previous-watch', 1800);
        $this->store->corruptWatchTtl('previous-watch');

        $this->expectException(RateLimiterException::class);
        try {
            $this->store->incrementWatchFlagAcrossRotation('current-watch', 'previous-watch', 1800);
        } finally {
            self::assertSame(0, $this->store->watchValue('current-watch'));
            self::assertNull($this->store->watchExpiresAt('current-watch'));
        }
    }
}
