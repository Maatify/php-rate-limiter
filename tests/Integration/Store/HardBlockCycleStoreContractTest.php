<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

final class HardBlockCycleStoreContractTest extends TestCase
{
    private FixedClock $clock;
    private InMemoryRateLimitStore $store;

    protected function setUp(): void
    {
        $this->clock = new FixedClock('@1000');
        $this->store = new InMemoryRateLimitStore($this->clock);
    }

    public function testFirstAndSecondCycleReachExactThresholdWhileRefreshAndEscalationDoNotCreateCycles(): void
    {
        $first = $this->block('key', 1000, 10);
        self::assertTrue($first->newCycle);
        self::assertSame(1, $first->cycleCount);
        self::assertFalse($first->pauseActivated);

        $second = $this->block('key', 1010, 10);
        self::assertTrue($second->newCycle);
        self::assertSame(2, $second->cycleCount);
        self::assertTrue($second->pauseActivated);
        self::assertSame(1610, $second->pauseUntil);

        $this->setNow(1011);
        $refresh = $this->store->blockWithCycleTracking('key', null, 2, 10, 1011, 21600, 2, 600, 86400);
        self::assertFalse($refresh->newCycle);
        self::assertSame(2, $refresh->cycleCount);
        self::assertFalse($refresh->pauseActivated);
        self::assertSame(1610, $refresh->pauseUntil);

        $this->setNow(1012);
        $escalation = $this->store->blockWithCycleTracking('key', null, 3, 10, 1012, 21600, 2, 600, 86400);
        self::assertFalse($escalation->newCycle);
        self::assertSame(2, $escalation->cycleCount);
        self::assertFalse($escalation->pauseActivated);
        self::assertSame(1610, $escalation->pauseUntil);
    }

    public function testL1DoesNotTrackButL1ToL2StartsARealCycle(): void
    {
        $this->store->block('key', 1, 10);
        $result = $this->block('key', 1010, 10);

        self::assertTrue($result->newCycle);
        self::assertSame(1, $result->cycleCount);
    }

    public function testExactExpiryStartsANewCycleAndExactWindowBoundaryIsInclusive(): void
    {
        $first = $this->store->blockWithCycleTracking('boundary', null, 2, 21600, 1000, 21600, 3, 600, 86400);
        self::assertTrue($first->newCycle);

        $atBoundary = $this->store->blockWithCycleTracking('boundary', null, 2, 1, 22600, 21600, 3, 600, 86400);
        self::assertTrue($atBoundary->newCycle);
        self::assertSame(2, $atBoundary->cycleCount);
        self::assertFalse($atBoundary->pauseActivated);

        $afterBoundary = $this->store->blockWithCycleTracking('boundary', null, 2, 1, 22601, 21600, 3, 600, 86400);
        self::assertTrue($afterBoundary->newCycle);
        self::assertSame(2, $afterBoundary->cycleCount);
    }

    public function testPauseIsFixedAndASecondCycleDuringPauseDoesNotExtendIt(): void
    {
        $this->block('key', 1000, 10);
        $pause = $this->block('key', 1010, 10);
        self::assertSame(1610, $pause->pauseUntil);

        $duringPause = $this->block('key', 1020, 10);
        self::assertTrue($duringPause->newCycle);
        self::assertFalse($duringPause->pauseActivated);
        self::assertSame(1610, $duringPause->pauseUntil);

        $this->setNow(1610);
        $nextPause = $this->block('key', 1610, 10);
        self::assertTrue($nextPause->newCycle);
        self::assertTrue($nextPause->pauseActivated);
        self::assertSame(2210, $nextPause->pauseUntil);
    }

    public function testKeysHaveIndependentCycleHistories(): void
    {
        $this->block('key-a', 1000, 10);
        $this->block('key-a', 1010, 10);
        $other = $this->block('key-b', 1010, 10);

        self::assertSame(1, $other->cycleCount);
        self::assertFalse($other->pauseActivated);
        self::assertSame(0, $other->pauseUntil);
    }

    public function testPreviousHistoryIsAdoptedOnceAndPreviousBlockRemainsReadOnly(): void
    {
        $previous = $this->store->blockWithCycleTracking('previous', null, 2, 1000, 1000, 21600, 2, 600, 86400);
        self::assertTrue($previous->newCycle);
        $previousBlock = $this->store->checkBlock('previous');
        self::assertNotNull($previousBlock);
        self::assertSame(2000, $previousBlock->expiresAt);

        $adopted = $this->store->blockWithCycleTracking('current', 'previous', 2, 100, 1001, 21600, 2, 600, 86400);
        self::assertFalse($adopted->newCycle);
        self::assertSame(1, $adopted->cycleCount);
        $previousBlockAfterAdoption = $this->store->checkBlock('previous');
        self::assertNotNull($previousBlockAfterAdoption);
        self::assertSame(2000, $previousBlockAfterAdoption->expiresAt);

        $second = $this->store->blockWithCycleTracking('current', 'previous', 2, 100, 2000, 21600, 2, 600, 86400);
        self::assertTrue($second->newCycle);
        self::assertSame(2, $second->cycleCount);
        self::assertTrue($second->pauseActivated);
        $this->setNow(2000);
        self::assertNull($this->store->checkBlock('previous'));

        $sameInvocation = $this->store->blockWithCycleTracking('current', 'previous', 2, 100, 2000, 21600, 2, 600, 86400);
        self::assertFalse($sameInvocation->newCycle);
        self::assertSame(2, $sameInvocation->cycleCount);
    }

    public function testOpaqueHistoricalMemberIsPersistedAsItsOwnCurrentTarget(): void
    {
        $result = $this->store->blockWithCycleTracking('opaque-member', null, 2, 60, 1000, 21600, 2, 600, 86400);

        self::assertTrue($result->newCycle);
        self::assertSame(2, $this->store->checkBlock('opaque-member')?->level);
        self::assertSame(1, $result->cycleCount);
    }

    public function testReadPauseStateUnionsIntervalsAndSupportsCompletedPauseLazyAccounting(): void
    {
        $this->block('key', 1000, 10);
        $this->block('key', 1010, 10);
        $this->block('key', 1020, 10);

        $state = $this->store->readDecayPauseState('key', null, 1000, 3000);
        self::assertSame(600, $state->elapsedPausedSeconds);
        self::assertSame(0, $state->activePauseUntil);

        $this->setNow(3000);
        $calculator = new DecayCalculator($this->clock);
        self::assertSame(2, $calculator->calculateDecay(10, 1000, 0, 'account', $state->elapsedPausedSeconds));
    }

    public function testCompletedPauseIsIgnoredAfterItsTwentyFourHourRetention(): void
    {
        $this->block('key', 1000, 10);
        $this->block('key', 1010, 10);

        $state = $this->store->readDecayPauseState('key', null, 1000, 88010);

        self::assertSame(0, $state->elapsedPausedSeconds);
        self::assertSame(0, $state->activePauseUntil);
    }

    public function testActivePauseIsAddedToScoreDerivedRetryAfter(): void
    {
        $this->block('key', 1000, 10);
        $this->block('key', 1010, 10);
        $this->setNow(1050);
        $state = $this->store->readDecayPauseState('key', null, 1000, 1050);

        $calculator = new DecayCalculator($this->clock);
        self::assertSame(1150, $calculator->secondsUntilBelowThreshold(
            5,
            1000,
            0,
            'account',
            5,
            $state->elapsedPausedSeconds,
            $state->activePauseUntil,
        ));
    }

    public function testReadIsSafeForUnknownBaseAndPreviousState(): void
    {
        $state = $this->store->readDecayPauseState('current', 'previous', 1000, 2000);

        self::assertSame(0, $state->elapsedPausedSeconds);
        self::assertSame(0, $state->activePauseUntil);
    }

    private function block(string $key, int $now, int $duration): \Maatify\RateLimiter\DTO\HardBlockCycleResultDTO
    {
        $this->setNow($now);

        return $this->store->blockWithCycleTracking($key, null, 2, $duration, $now, 21600, 2, 600, 86400);
    }

    private function setNow(int $timestamp): void
    {
        $this->clock->setNow(new \DateTimeImmutable('@' . $timestamp));
    }
}
