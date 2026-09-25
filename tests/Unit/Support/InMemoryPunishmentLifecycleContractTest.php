<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Support;

use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

final class InMemoryPunishmentLifecycleContractTest extends TestCase
{
    public function testPublicationRequiresCurrentGeneratedScoreAndValidL2Parameters(): void
    {
        $store = new InMemoryRateLimitStore(new FixedClock());
        $previous = $store->mutateGenerationBoundScore('previous', null, null, 600, 8)->state;
        self::assertNotNull($previous);

        $this->expectException(\InvalidArgumentException::class);
        $store->blockWithPunishmentLifecycleTracking('current', 'previous', 1, str_repeat('a', 32), 2, 60, 600, 2, 600, 86400);
    }

    public function testPublicationRejectsNonPositiveGenerationAndL1(): void
    {
        $store = new InMemoryRateLimitStore(new FixedClock());
        $store->mutateGenerationBoundScore('current', null, null, 600, 8);

        foreach ([[0, 2], [1, 1]] as [$generation, $level]) {
            try {
                $store->blockWithPunishmentLifecycleTracking('current', null, $generation, str_repeat('b', 32), $level, 60, 600, 2, 600, 86400);
                self::fail('Invalid lifecycle publication parameters were accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
