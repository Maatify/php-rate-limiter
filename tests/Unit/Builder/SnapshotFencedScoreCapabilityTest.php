<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Builder;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\FullCapabilityStoreInterface;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\FullCapability\FullCapabilityInMemoryStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-185-08 (#185, DEC-030): `build()` requires `SnapshotFencedScoreStoreInterface` when a registered policy writes a score,
 * and names it; the operational reader does not require it (DEC-012).
 */
#[Group('f-185-08')]
final class SnapshotFencedScoreCapabilityTest extends TestCase
{
    private function builder(FullCapabilityStoreInterface $store): RateLimiterBuilder
    {
        return RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('key-secret', 'fingerprint-secret', 'prod'),
            $store,
            new RecordingFailureSignalEmitter(),
        )->withClock(new FixedClock('2026-11-04 12:00:00'));
    }

    public function testAStoreWithoutTheCapabilityIsRejectedAtBuildWithItsName(): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessageMatches('/^Policy [a-z_]+ writes scores and requires SnapshotFencedScoreStoreInterface \(#185, DEC-030\)\.$/');

        $this->builder($this->createStub(FullCapabilityStoreInterface::class))->build();
    }

    public function testTheOperationalReaderDoesNotRequireTheCapability(): void
    {
        $this->builder($this->createStub(FullCapabilityStoreInterface::class))->buildOperationalReader();

        $this->addToAssertionCount(1);
    }

    public function testControlAStoreWithTheCapabilityBuilds(): void
    {
        $this->builder(new FullCapabilityInMemoryStore(new FixedClock('2026-11-04 12:00:00')))->build();

        $this->addToAssertionCount(1);
    }
}
