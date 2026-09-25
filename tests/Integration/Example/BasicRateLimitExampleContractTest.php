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
        self::assertSame($previous->expiresAt, $previousAfter->expiresAt);
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
}
