<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\RateLimiter\Tests\Support\Redis\StateSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-185-27 (#185): `claimPostPunishmentReentryAt` (LIFECYCLE_CLAIM) validates the score hash before a live block
 * answers "no claim". On `78526b5` a live L2+ block returned 0 before the score hash was read, so malformed state
 * (a non-canonical value, partial or malformed lifecycle evidence, evidence on a legacy score) was hidden while the
 * same state without the block was an error, and `readGenerationBoundScoreStateAt` raised it even with the block
 * (adv8 `r2_claim_hides_malformed.php`). Malformed state is now the same error with or without the live block and
 * leaves every key byte-identical (DEC-017); a live block over a valid score still answers false and writes no marker.
 */
#[Group('f-185-27')]
#[Group('php32')]
final class ClaimValidatesBeforeLiveBlockRedisTest extends TestCase
{
    private const NOW = 1_800_000_000;

    /** The same instant as the decimal epoch milliseconds the DEC-039 capability takes; `NOW * 1000` is a float on a 32-bit build (#240). */
    private const NOW_MS = self::NOW . '000';

    private const NAMESPACE = 'f185-27';

    private const KEY = 'k4';

    private RespRedisCommandExecutor $executor;

    private RedisFullCapabilityStore $store;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->executor = new RespRedisCommandExecutor($host, (int) $port);
        $this->executor->execute(['FLUSHDB']);
        $this->store = new RedisFullCapabilityStore($this->executor, self::NAMESPACE);
    }

    private static function id(): string
    {
        return str_repeat('ab', 16);
    }

    /** @return array<string, list<string>> Score fields of each seeded state. */
    private static function scores(): array
    {
        $until = (string) (self::NOW + 86400);
        $now = (string) self::NOW;
        $evidence = ['reentryId', self::id(), 'reentryValidUntil', $until, 'reentryGeneration', '1'];

        return [
            'non-canonical value' => ['value', '05', 'updatedAt', $now, 'generation', '1', 'expiresAt', $until, ...$evidence],
            'partial evidence' => ['value', '5', 'updatedAt', $now, 'generation', '1', 'expiresAt', $until, 'reentryId', self::id()],
            'malformed lifecycle id' => ['value', '5', 'updatedAt', $now, 'generation', '1', 'expiresAt', $until, 'reentryId', 'not-an-id', 'reentryValidUntil', $until, 'reentryGeneration', '1'],
            'evidence on a legacy score' => ['value', '5', 'updatedAt', $now, ...$evidence],
            'valid' => ['value', '5', 'updatedAt', $now, 'generation', '1', 'expiresAt', $until, ...$evidence],
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        foreach (self::scores() as $label => $_) {
            if ($label !== 'valid') {
                yield $label => [$label];
            }
        }
    }

    private function rawKey(string $family): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':' . $family . ':' . hash('sha256', self::KEY);
    }

    private function seed(string $score, bool $liveBlock): void
    {
        $this->executor->execute(['FLUSHDB']);
        $this->executor->execute(['HSET', $this->rawKey('score'), ...self::scores()[$score]]);
        $this->executor->execute(['EXPIRE', $this->rawKey('score'), '86400']);
        if ($liveBlock) {
            $this->executor->execute(['HSET', $this->rawKey('block'), 'level', '3', 'expiresAt', (string) (self::NOW + 600)]);
            $this->executor->execute(['EXPIRE', $this->rawKey('block'), '600']);
        }
    }

    private function claim(): bool
    {
        return $this->store->claimPostPunishmentReentryAtMilliseconds(self::KEY, null, self::id(), self::NOW_MS);
    }

    /** The claim's error message for the seeded state, failing on any other outcome. */
    private function claimError(string $label): string
    {
        try {
            $claimed = $this->claim();
            self::fail($label . ': malformed state answered ' . var_export($claimed, true) . '.');
        } catch (BackendFailureException $exception) {
            self::fail($label . ': malformed state raised BackendFailureException: ' . $exception->getMessage());
        } catch (RateLimiterException $exception) {
            return $exception->getMessage();
        }
    }

    /** @return array<string, string> every key's byte-exact `DUMP` and exact `PEXPIRETIME` (F241-01: DUMP alone misses an expiry-only write). */
    private function dump(): array
    {
        return StateSnapshot::all($this->executor);
    }

    #[DataProvider('malformed')]
    public function testMalformedScoreIsTheSameErrorWithOrWithoutALiveBlock(string $score): void
    {
        $this->seed($score, false);
        $withoutBlock = $this->claimError($score . ' without a block');

        $this->seed($score, true);
        $before = $this->dump();
        $withBlock = $this->claimError($score . ' under a live L3 block');

        self::assertSame($withoutBlock, $withBlock, 'A live block must not change how malformed state is reported.');
        self::assertSame($before, $this->dump(), 'Malformed state must be left unchanged: every key, its bytes and its exact expiry.');
    }

    /** Control: a live block over a valid score still answers false and writes no claim marker. */
    /** F241-01 detector: an expiry-only +1 ms move, with the bytes untouched, is visible to this class's no-mutation snapshot, on the score and on a control key. */
    public function testTheSnapshotRejectsAnExpiryOnlyOneMillisecondMove(): void
    {
        foreach (['score', 'block'] as $moved) {
            $this->seed('valid', true);
            $this->executor->execute(['SET', 'unrelated-control', 'x', 'EX', '600']);
            $before = $this->dump();

            StateSnapshot::perturbExpiryByOneMillisecond($this->executor, $this->rawKey($moved));

            self::assertNotSame($before, $this->dump(), 'A +1 ms expiry-only change on ' . $moved . ' is detected.');
        }
        $this->seed('valid', true);
        $this->executor->execute(['SET', 'unrelated-control', 'x', 'EX', '600']);
        $before = $this->dump();
        StateSnapshot::perturbExpiryByOneMillisecond($this->executor, 'unrelated-control');
        self::assertNotSame($before, $this->dump(), 'A +1 ms expiry-only change on an unrelated control key is detected.');
    }

    public function testALiveBlockOverAValidScoreStillAnswersNoClaim(): void
    {
        $this->seed('valid', true);
        $before = $this->dump();

        self::assertFalse($this->claim());
        self::assertSame($before, $this->dump(), 'No claim marker is written under a live block.');
    }

    /** Control: without a block the valid score is claimed once. */
    public function testAValidScoreWithoutABlockIsClaimedOnce(): void
    {
        $this->seed('valid', false);

        self::assertTrue($this->claim());
        self::assertFalse($this->claim(), 'The second claim of the same evidence answers false.');
    }
}
