<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Support;

use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

final class InMemoryPunishmentLifecycleContractTest extends TestCase
{
    /**
     * With Current absent, a structurally valid Previous (generated or
     * legacy) is historical/read-only, not a publishable source: publication
     * is an ordinary unapplied conflict, never an exception, and Previous is
     * never mutated. A wholly absent source is the same ordinary conflict.
     */
    public function testPublicationWithAbsentCurrentIsOrdinaryConflictNotException(): void
    {
        $store = new InMemoryRateLimitStore(new FixedClock());

        $generatedPrevious = $store->mutateGenerationBoundScore('generated-previous', null, null, 600, 8)->state;
        self::assertNotNull($generatedPrevious);
        $generatedTransition = $store->blockWithPunishmentLifecycleTracking('new-current-1', 'generated-previous', $generatedPrevious->generation ?? 1, str_repeat('a', 32), 2, 60, 600, 2, 600, 86400);
        self::assertFalse($generatedTransition->applied);
        self::assertNull($generatedTransition->cycle);
        self::assertNull($generatedTransition->block);
        self::assertNull($generatedTransition->postPunishmentReentry);
        $generatedPreviousAfter = $store->readGenerationBoundScoreState('generated-previous', null);
        self::assertNotNull($generatedPreviousAfter);
        self::assertSame($generatedPrevious->value, $generatedPreviousAfter->value);
        self::assertSame($generatedPrevious->updatedAt, $generatedPreviousAfter->updatedAt);
        self::assertSame($generatedPrevious->expiresAt, $generatedPreviousAfter->expiresAt);
        self::assertSame($generatedPrevious->generation, $generatedPreviousAfter->generation);

        $legacyStore = new InMemoryRateLimitStore(new FixedClock());
        $legacyStore->set('legacy-previous', 4, 500);
        $legacyPreviousBefore = $legacyStore->get('legacy-previous');
        self::assertNotNull($legacyPreviousBefore);
        $legacyTransition = $legacyStore->blockWithPunishmentLifecycleTracking('new-current-2', 'legacy-previous', 1, str_repeat('b', 32), 2, 60, 600, 2, 600, 86400);
        self::assertFalse($legacyTransition->applied);
        self::assertNull($legacyTransition->cycle);
        self::assertNull($legacyTransition->block);
        self::assertNull($legacyTransition->postPunishmentReentry);
        $legacyPreviousAfter = $legacyStore->get('legacy-previous');
        self::assertNotNull($legacyPreviousAfter);
        self::assertSame($legacyPreviousBefore->value, $legacyPreviousAfter->value);
        self::assertSame($legacyPreviousBefore->updatedAt, $legacyPreviousAfter->updatedAt);

        $noSourceTransition = $store->blockWithPunishmentLifecycleTracking('no-source-current', null, 1, str_repeat('c', 32), 2, 60, 600, 2, 600, 86400);
        self::assertFalse($noSourceTransition->applied);
        self::assertNull($noSourceTransition->cycle);
        self::assertNull($noSourceTransition->block);
        self::assertNull($noSourceTransition->postPunishmentReentry);
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

    public function testPublicationRejectsEveryInvalidParameterInTheFullMatrix(): void
    {
        $store = new InMemoryRateLimitStore(new FixedClock());
        $store->mutateGenerationBoundScore('current', null, null, 600, 8);

        $baseline = [1, 2, 60, 600, 2, 600, 86400];
        $invalidValues = [0, 1, 0, 0, 0, 0, 0];
        foreach (array_keys($baseline) as $index) {
            $params = $baseline;
            $params[$index] = $invalidValues[$index];
            [$generation, $level, $duration, $window, $threshold, $pause, $retention] = $params;
            try {
                $store->blockWithPunishmentLifecycleTracking('current', null, $generation, str_repeat('c', 32), $level, $duration, $window, $threshold, $pause, $retention);
                self::fail('Invalid lifecycle publication parameters were accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * Republishing the same exact K4 generation preserves the existing
     * lifecycle identity instead of adopting a newly proposed one.
     */
    public function testRepublicationOfTheSameGenerationPreservesTheExistingLifecycleIdentity(): void
    {
        $clock = new FixedClock();
        $store = new InMemoryRateLimitStore($clock);
        $created = $store->mutateGenerationBoundScore('stable-identity', null, null, 600, 8);
        self::assertTrue($created->applied);

        $idA = str_repeat('a', 32);
        $first = $store->blockWithPunishmentLifecycleTracking('stable-identity', null, 1, $idA, 2, 30, 600, 3, 600, 86400);
        self::assertTrue($first->applied);
        self::assertSame($idA, $first->postPunishmentReentry?->id);

        $idB = str_repeat('b', 32);
        $refresh = $store->blockWithPunishmentLifecycleTracking('stable-identity', null, 1, $idB, 2, 30, 600, 3, 600, 86400);
        self::assertTrue($refresh->applied);
        self::assertSame($idA, $refresh->postPunishmentReentry?->id);
        self::assertNotSame($idB, $refresh->postPunishmentReentry?->id);

        $clock->setNow($clock->now()->modify('+31 seconds'));
        $state = $store->readGenerationBoundScoreState('stable-identity', null);
        self::assertSame($idA, $state?->postPunishmentReentry?->id);

        $next = $store->mutateGenerationBoundScore('stable-identity', null, $state, 600, 9);
        self::assertTrue($next->applied);
        self::assertSame(2, $next->state?->generation);
        $idC = str_repeat('c', 32);
        $second = $store->blockWithPunishmentLifecycleTracking('stable-identity', null, 2, $idC, 2, 30, 600, 3, 600, 86400);
        self::assertTrue($second->applied);
        self::assertSame($idC, $second->postPunishmentReentry?->id);
        self::assertNotSame($idA, $second->postPunishmentReentry?->id);
    }
}
