<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
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
final class ClaimValidatesBeforeLiveBlockRedisTest extends TestCase
{
    private const NOW = 1_800_000_000;

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
        return $this->store->claimPostPunishmentReentryAt(self::KEY, null, self::id(), self::NOW * 1000);
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

    /** @return array<string, string> `DUMP` of every key, so "unchanged" is byte-exact. */
    private function dump(): array
    {
        $keys = $this->executor->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        $out = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $bytes = $this->executor->execute(['DUMP', $key]);
            self::assertIsString($bytes);
            $out[$key] = $bytes;
        }
        ksort($out);

        return $out;
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
        self::assertSame($before, $this->dump(), 'Malformed state must be left byte-identical.');
    }

    /** Control: a live block over a valid score still answers false and writes no claim marker. */
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
