<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Example;

use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use PHPUnit\Framework\TestCase;

final class BasicRateLimitExampleContractTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        ob_start();
        require_once dirname(__DIR__, 3) . '/examples/basic-rate-limit.php';
        ob_end_clean();
    }

    public function testExampleHonorsEmptyConflictCasIdentityAndCurrentGenerationExpiry(): void
    {
        $clock = new FixedClock();
        $store = new \ExampleRateLimitStore($clock);

        $created = $store->mutateGenerationBoundScore('current', null, null, 600, 8);
        self::assertTrue($created->applied);
        self::assertNotNull($created->state);
        self::assertSame(1, $created->state->generation);
        self::assertSame($clock->now()->getTimestamp() + 600, $created->state->expiresAt);
        self::assertFalse($store->mutateGenerationBoundScore('current', null, null, 600, 9)->applied);

        $state = $store->readGenerationBoundScoreState('current', null);
        self::assertNotNull($state);
        self::assertFalse($store->mutateGenerationBoundScore(
            'current',
            null,
            new GenerationBoundScoreStateDTO($state->source, $state->value, $state->updatedAt + 1, $state->expiresAt, $state->generation),
            600,
            9,
        )->applied);

        $next = $store->mutateGenerationBoundScore('current', null, $state, 600, 9);
        self::assertTrue($next->applied);
        self::assertNotNull($next->state);
        self::assertSame(2, $next->state->generation);
        self::assertSame($state->expiresAt, $next->state->expiresAt);
    }

    public function testExamplePreservesLegacyAndPreviousExpiryDuringHandoff(): void
    {
        $clock = new FixedClock();
        $store = new \ExampleRateLimitStore($clock);

        $store->set('legacy-current', 4, 500);
        $legacyState = $store->readGenerationBoundScoreState('legacy-current', null);
        self::assertNotNull($legacyState);
        $legacyMutation = $store->mutateGenerationBoundScore('legacy-current', null, $legacyState, 600, 5);
        self::assertTrue($legacyMutation->applied);
        self::assertNotNull($legacyMutation->state);
        self::assertSame(1, $legacyMutation->state->generation);
        self::assertSame($legacyState->expiresAt, $legacyMutation->state->expiresAt);

        $previous = $store->mutateGenerationBoundScore('previous', null, null, 700, 10)->state;
        self::assertNotNull($previous);
        $previousSnapshot = $store->readGenerationBoundScoreState('new-current', 'previous');
        self::assertNotNull($previousSnapshot);
        $handoff = $store->mutateGenerationBoundScore('new-current', 'previous', $previousSnapshot, 900, 11);
        self::assertTrue($handoff->applied);
        self::assertNotNull($handoff->state);
        self::assertSame(2, $handoff->state->generation);
        self::assertSame($previous->expiresAt, $handoff->state->expiresAt);
        $previousAfter = $store->readGenerationBoundScoreState('previous', null);
        self::assertNotNull($previousAfter);
        self::assertSame($previous->value, $previousAfter->value);
        self::assertSame($previous->updatedAt, $previousAfter->updatedAt);
        self::assertSame($previous->expiresAt, $previousAfter->expiresAt);
        self::assertSame($previous->generation, $previousAfter->generation);
    }

    public function testExampleRejectsMutationWhenOnlyExpiresAtIsStale(): void
    {
        $clock = new FixedClock();
        $store = new \ExampleRateLimitStore($clock);
        $created = $store->mutateGenerationBoundScore('stale-expiry-cas', null, null, 600, 8);
        self::assertTrue($created->applied);
        $state = $created->state;
        self::assertNotNull($state);

        $staleExpiry = new GenerationBoundScoreStateDTO($state->source, $state->value, $state->updatedAt, $state->expiresAt + 1, $state->generation);
        $conflict = $store->mutateGenerationBoundScore('stale-expiry-cas', null, $staleExpiry, 600, 9);

        self::assertFalse($conflict->applied);
        self::assertNull($conflict->state);
        $after = $store->readGenerationBoundScoreState('stale-expiry-cas', null);
        self::assertNotNull($after);
        self::assertSame($state->value, $after->value);
        self::assertSame($state->updatedAt, $after->updatedAt);
        self::assertSame($state->expiresAt, $after->expiresAt);
        self::assertSame($state->generation, $after->generation);
    }

    public function testExampleHandlesLegacyPreviousHandoffThroughTheNonGenerationApi(): void
    {
        $clock = new FixedClock();
        $store = new \ExampleRateLimitStore($clock);
        $store->set('legacy-previous-handoff-previous', 6, 500);
        $legacyPreviousBefore = $store->get('legacy-previous-handoff-previous');
        self::assertNotNull($legacyPreviousBefore);

        $snapshot = $store->readGenerationBoundScoreState('legacy-previous-handoff-current', 'legacy-previous-handoff-previous');
        self::assertNotNull($snapshot);
        self::assertSame(GenerationBoundScoreStateDTO::SOURCE_PREVIOUS, $snapshot->source);
        self::assertNull($snapshot->generation);

        $handoff = $store->mutateGenerationBoundScore('legacy-previous-handoff-current', 'legacy-previous-handoff-previous', $snapshot, 600, 7);

        self::assertTrue($handoff->applied);
        self::assertNotNull($handoff->state);
        self::assertSame(1, $handoff->state->generation);
        self::assertSame($snapshot->expiresAt, $handoff->state->expiresAt);
        $legacyPreviousAfter = $store->get('legacy-previous-handoff-previous');
        self::assertNotNull($legacyPreviousAfter);
        self::assertSame($legacyPreviousBefore->value, $legacyPreviousAfter->value);
        self::assertSame($legacyPreviousBefore->updatedAt, $legacyPreviousAfter->updatedAt);
    }

    public function testExamplePublishesEvidenceAndAllowsExactlyOneClaimThenInvalidatesEvidenceOnMutation(): void
    {
        $clock = new FixedClock();
        $store = new \ExampleRateLimitStore($clock);
        $state = $store->mutateGenerationBoundScore('claim-current', null, null, 600, 8)->state;
        self::assertNotNull($state);

        $transition = $store->blockWithPunishmentLifecycleTracking('claim-current', null, 1, str_repeat('a', 32), 2, 30, 600, 3, 600, 86400);
        self::assertTrue($transition->applied);
        $clock->setNow($clock->now()->modify('+31 seconds'));
        self::assertTrue($store->claimPostPunishmentReentry('claim-current', null, str_repeat('a', 32)));
        self::assertFalse($store->claimPostPunishmentReentry('claim-current', null, str_repeat('a', 32)));

        $current = $store->readGenerationBoundScoreState('claim-current', null);
        self::assertNotNull($current);
        self::assertTrue($store->mutateGenerationBoundScore('claim-current', null, $current, 600, 9)->applied);
        self::assertNull($store->readGenerationBoundScoreState('claim-current', null)?->postPunishmentReentry);
    }

    public function testExampleRejectsInvalidPublicationParameters(): void
    {
        $clock = new FixedClock();
        $store = new \ExampleRateLimitStore($clock);
        $store->mutateGenerationBoundScore('previous-only', null, null, 600, 8);

        $baseline = [1, 2, 60, 600, 2, 600, 86400];
        $invalidValues = [0, 1, 0, 0, 0, 0, 0];
        $failures = 0;
        foreach (array_keys($baseline) as $index) {
            $params = $baseline;
            $params[$index] = $invalidValues[$index];
            [$generation, $level, $duration, $window, $threshold, $pause, $retention] = $params;
            try {
                $store->blockWithPunishmentLifecycleTracking('previous-only', null, $generation, str_repeat('a', 32), $level, $duration, $window, $threshold, $pause, $retention);
            } catch (\InvalidArgumentException) {
                $failures++;
            }
        }
        self::assertSame(count($baseline), $failures);
    }

    /**
     * With Current absent, a structurally valid Previous (generated or
     * legacy) is historical/read-only, not a publishable source: publication
     * is an ordinary unapplied conflict, never an exception, and Previous is
     * never mutated. A wholly absent source is the same ordinary conflict.
     */
    public function testExamplePublicationWithAbsentCurrentIsOrdinaryConflictNotException(): void
    {
        $clock = new FixedClock();
        $store = new \ExampleRateLimitStore($clock);

        $generatedPrevious = $store->mutateGenerationBoundScore('generated-previous-only', null, null, 600, 8)->state;
        self::assertNotNull($generatedPrevious);
        $generatedTransition = $store->blockWithPunishmentLifecycleTracking('new-current-1', 'generated-previous-only', $generatedPrevious->generation ?? 1, str_repeat('b', 32), 2, 60, 600, 2, 600, 86400);
        self::assertFalse($generatedTransition->applied);
        self::assertNull($generatedTransition->cycle);
        self::assertNull($generatedTransition->block);
        self::assertNull($generatedTransition->postPunishmentReentry);
        $generatedPreviousAfter = $store->readGenerationBoundScoreState('generated-previous-only', null);
        self::assertNotNull($generatedPreviousAfter);
        self::assertSame($generatedPrevious->value, $generatedPreviousAfter->value);
        self::assertSame($generatedPrevious->updatedAt, $generatedPreviousAfter->updatedAt);
        self::assertSame($generatedPrevious->expiresAt, $generatedPreviousAfter->expiresAt);
        self::assertSame($generatedPrevious->generation, $generatedPreviousAfter->generation);

        $store->set('legacy-previous-only', 4, 500);
        $legacyPreviousBefore = $store->get('legacy-previous-only');
        self::assertNotNull($legacyPreviousBefore);
        $legacyTransition = $store->blockWithPunishmentLifecycleTracking('new-current-2', 'legacy-previous-only', 1, str_repeat('c', 32), 2, 60, 600, 2, 600, 86400);
        self::assertFalse($legacyTransition->applied);
        self::assertNull($legacyTransition->cycle);
        self::assertNull($legacyTransition->block);
        self::assertNull($legacyTransition->postPunishmentReentry);
        $legacyPreviousAfter = $store->get('legacy-previous-only');
        self::assertNotNull($legacyPreviousAfter);
        self::assertSame($legacyPreviousBefore->value, $legacyPreviousAfter->value);
        self::assertSame($legacyPreviousBefore->updatedAt, $legacyPreviousAfter->updatedAt);

        $noSourceTransition = $store->blockWithPunishmentLifecycleTracking('no-source-current', null, 1, str_repeat('d', 32), 2, 60, 600, 2, 600, 86400);
        self::assertFalse($noSourceTransition->applied);
        self::assertNull($noSourceTransition->cycle);
        self::assertNull($noSourceTransition->block);
        self::assertNull($noSourceTransition->postPunishmentReentry);
    }

    /**
     * Republishing the same exact K4 generation preserves the existing
     * lifecycle identity instead of adopting a newly proposed one.
     */
    public function testExampleRepublicationOfTheSameGenerationPreservesTheExistingLifecycleIdentity(): void
    {
        $clock = new FixedClock();
        $store = new \ExampleRateLimitStore($clock);
        $created = $store->mutateGenerationBoundScore('example-stable-identity', null, null, 600, 8);
        self::assertTrue($created->applied);

        $idA = str_repeat('a', 32);
        $first = $store->blockWithPunishmentLifecycleTracking('example-stable-identity', null, 1, $idA, 2, 30, 600, 3, 600, 86400);
        self::assertTrue($first->applied);
        self::assertSame($idA, $first->postPunishmentReentry?->id);

        $idB = str_repeat('b', 32);
        $refresh = $store->blockWithPunishmentLifecycleTracking('example-stable-identity', null, 1, $idB, 2, 30, 600, 3, 600, 86400);
        self::assertTrue($refresh->applied);
        self::assertSame($idA, $refresh->postPunishmentReentry?->id);
        self::assertNotSame($idB, $refresh->postPunishmentReentry->id);

        $clock->setNow($clock->now()->modify('+31 seconds'));
        $state = $store->readGenerationBoundScoreState('example-stable-identity', null);
        self::assertSame($idA, $state?->postPunishmentReentry?->id);

        $next = $store->mutateGenerationBoundScore('example-stable-identity', null, $state, 600, 9);
        self::assertTrue($next->applied);
        self::assertSame(2, $next->state?->generation);
        $idC = str_repeat('c', 32);
        $second = $store->blockWithPunishmentLifecycleTracking('example-stable-identity', null, 2, $idC, 2, 30, 600, 3, 600, 86400);
        self::assertTrue($second->applied);
        self::assertSame($idC, $second->postPunishmentReentry?->id);
        self::assertNotSame($idA, $second->postPunishmentReentry->id);
    }
}
