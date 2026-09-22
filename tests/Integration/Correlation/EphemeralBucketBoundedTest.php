<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Correlation;

use Maatify\RateLimiter\DTO\BoundedCorrelationObservationDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Repository\CorrelationStoreInterface;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use PHPUnit\Framework\TestCase;

final class EphemeralBucketBoundedTest extends TestCase
{
    private FixedClock $clock;
    private StatefulInMemoryCorrelationStore $store;
    private EphemeralBucket $bucket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->store = new StatefulInMemoryCorrelationStore($this->clock);
        $this->bucket = new EphemeralBucket($this->store);
    }

    public function testAccountCapRejectsOnlyTheEleventhNewDeviceAndKeepsKnownDevicesAdmitted(): void
    {
        for ($number = 1; $number <= 10; $number++) {
            $state = $this->bucket->check(
                $this->observation('ip-scope', "ip-member-{$number}"),
                $this->observation('account-scope', "account-member-{$number}"),
            );
            self::assertFalse($state->isEphemeral);
            self::assertSame($number, $state->accountDeviceCount);
        }

        $overflow = $this->bucket->check(
            $this->observation('ip-scope', 'ip-member-11'),
            $this->observation('account-scope', 'account-member-11'),
        );
        self::assertTrue($overflow->isEphemeral);
        self::assertSame(10, $overflow->accountDeviceCount);
        self::assertSame(10, $this->store->distinctCount('account-scope'));

        $known = $this->bucket->check(
            $this->observation('ip-scope', 'ip-member-1'),
            $this->observation('account-scope', 'account-member-1'),
        );
        self::assertFalse($known->isEphemeral);
        self::assertSame(10, $known->accountDeviceCount);
    }

    public function testIpCapRejectsTheFiftyFirstNewDeviceAndDoesNotGrowFurther(): void
    {
        for ($number = 1; $number <= 50; $number++) {
            self::assertFalse(
                $this->bucket->check($this->observation('ip-scope', "member-{$number}"))->isEphemeral,
            );
        }

        self::assertTrue($this->bucket->check($this->observation('ip-scope', 'member-51'))->isEphemeral);
        self::assertSame(50, $this->store->distinctCount('ip-scope'));
        self::assertTrue($this->bucket->check($this->observation('ip-scope', 'member-52'))->isEphemeral);
        self::assertSame(50, $this->store->distinctCount('ip-scope'));
    }

    public function testStoreBoundaryContainsOpaqueReferencesOnly(): void
    {
        $rawAccount = 'account-raw-value';
        $rawIp = '198.51.100.77';
        $rawFingerprint = 'fingerprint-raw-value';
        $this->bucket->check(
            $this->observation(hash('sha256', 'ip'), hash_hmac('sha256', $rawFingerprint, 'secret')),
            $this->observation(hash('sha256', 'account'), hash_hmac('sha256', $rawFingerprint, 'secret')),
        );

        self::assertNotContains($rawAccount, $this->store->distinctItems('account'));
        self::assertNotContains($rawIp, $this->store->distinctItems('ip'));
        self::assertNotContains($rawFingerprint, $this->store->distinctItems('account'));
        foreach ($this->store->distinctKeys() as $key) {
            self::assertStringNotContainsString($rawAccount, $key);
            self::assertStringNotContainsString($rawIp, $key);
            self::assertStringNotContainsString($rawFingerprint, $key);
        }
    }

    public function testMissingBoundedCapabilityFailsWhenADeviceMustBeObserved(): void
    {
        $baseOnlyStore = new class implements CorrelationStoreInterface {
            public function addDistinct(string $key, string $item, int $ttlSeconds): int
            {
                return 1;
            }

            public function incrementWatchFlag(string $key, int $ttlSeconds): int
            {
                return 1;
            }

            public function getWatchFlag(string $key): int
            {
                return 0;
            }
        };
        $bucket = new EphemeralBucket($baseOnlyStore);

        $this->expectException(RateLimiterException::class);
        $bucket->check($this->observation('ip-scope', 'member'));
    }

    public function testRotationUsesReadOnlyPreviousAndCountsLogicalMembersOnce(): void
    {
        $this->store->addDistinct('previous-ip', 'previous-member', 900);
        $result = $this->bucket->check(
            new BoundedCorrelationObservationDTO(
                'current-ip',
                'current-member',
                'previous-ip',
                'new-previous-member',
                'bridge-ip',
            ),
        );

        self::assertSame(2, $result->ipDeviceCount);
        self::assertSame(['previous-member'], $this->store->distinctItems('previous-ip'));
        self::assertSame(['current-member'], $this->store->distinctItems('bridge-ip'));
    }

    public function testRotationShapesUseOneLogicalPreviousMember(): void
    {
        $shapes = [
            'outer-only' => [
                'current-key' => 'current-outer',
                'previous-key' => 'previous-outer',
                'current-member' => 'same-fingerprint-current-secret',
                'previous-member' => 'same-fingerprint-previous-secret',
            ],
            'fingerprint-only' => [
                'current-key' => 'same-outer',
                'previous-key' => 'same-outer',
                'current-member' => 'new-fingerprint-secret',
                'previous-member' => 'old-fingerprint-secret',
            ],
            'both-rotated' => [
                'current-key' => 'current-outer',
                'previous-key' => 'previous-outer',
                'current-member' => 'new-both',
                'previous-member' => 'old-both',
            ],
        ];

        foreach ($shapes as $shape => $values) {
            $store = new StatefulInMemoryCorrelationStore($this->clock);
            $store->addDistinctBounded($values['previous-key'], $values['previous-member'], 900, 50);
            $bucket = new EphemeralBucket($store);

            $state = $bucket->check(new BoundedCorrelationObservationDTO(
                $values['current-key'],
                $values['current-member'],
                $values['previous-key'],
                $values['previous-member'],
                'bridge-' . $shape,
            ));

            self::assertFalse($state->isEphemeral, $shape);
            self::assertSame(1, $state->ipDeviceCount, $shape);
            self::assertSame([$values['previous-member']], $store->distinctItems($values['previous-key']), $shape);
            self::assertSame([], $store->distinctItems('bridge-' . $shape), $shape);
        }
    }

    private function observation(string $key, string $member): BoundedCorrelationObservationDTO
    {
        return new BoundedCorrelationObservationDTO($key, $member);
    }
}
