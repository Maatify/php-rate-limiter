<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\RateLimiter\Tests\Support\Redis\StateSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #248: a legacy score (no stored `expiresAt`, no generation) is mapped onto the caller's timeline by its remaining physical life: the logical expiry is
 * `ceil((nowMilliseconds + PTTL) / 1000)`. The script computed `floor((nowMs + pttl + 999) / 1000)`, whose numerator leaves Lua's exact integer range near
 * the top of the clock domain, so it answered one whole second late whenever `nowMs + PTTL` was a whole second (a read-only wrong answer).
 *
 * The answer depends on the LIVE PTTL, which ticks with Redis's clock, so a real-time probe can only hit the defect by luck. The deterministic detector pins
 * the PTTL: `PinnedPttlExecutor` rewrites the one statement that reads it in the legacy branch (`local pttl = redis.call('PTTL', source)`) to a constant before
 * the script is sent, and everything else is the unmodified script on a real Redis. Each case states the clock, the pinned PTTL and the exact answer as an
 * independent decimal constant (never recomputed with the formula under test). A separate test runs the unmodified script on a real PTTL and bounds it with
 * outside PTTL readings: the answer is then fixed for the whole window, with no sleep.
 *
 * Far-future clocks are outside the 32-bit native `int` range: a 32-bit worker must keep refusing them (the DTO cannot hold the answer) without touching
 * Redis, never skip; contemporary clocks succeed on both widths. Every read, success or refusal, is judged on the byte-exact DUMP and exact PEXPIRETIME of
 * EVERY key (a foreign control included), directly and inside `transactRequest()`.
 */
#[Group('integration')]
#[Group('php32')]
#[Group('lifecycle-legacy-read')]
final class LifecycleLegacyReadExpiryRedisTest extends TestCase
{
    private const NAMESPACE = 'legacy-read';

    private const PTTL_STATEMENT = "local pttl = redis.call('PTTL', source)";

    private RespRedisCommandExecutor $executor;

    private PinnedPttlExecutor $pinned;

    private RedisFullCapabilityStore $pinnedStore;

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
        $this->pinned = new PinnedPttlExecutor($this->executor, self::PTTL_STATEMENT);
        $this->pinnedStore = new RedisFullCapabilityStore($this->pinned, self::NAMESPACE);
        $this->store = new RedisFullCapabilityStore($this->executor, self::NAMESPACE);
        $this->executor->execute(['SET', 'unrelated-control', 'kept', 'EX', '5000']);
    }

    private function key(string $logical): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':score:' . hash('sha256', $logical);
    }

    private function seedLegacy(string $logical, int $physicalMilliseconds): void
    {
        $this->executor->execute(['HSET', $this->key($logical), 'value', '8', 'updatedAt', '1']);
        $this->executor->execute(['PEXPIRE', $this->key($logical), (string) $physicalMilliseconds]);
    }

    /**
     * Clock (decimal ms), pinned PTTL (ms), the exact answer (decimal seconds), whether a 32-bit `int` holds it, and whether Previous is exercised too.
     *
     * @return array<string, array{string, int, string, bool, bool}>
     */
    public static function cases(): array
    {
        return [
            // The defect: 9007199254739000 + 1000 ms is exactly the whole second 9007199254740.
            'the defect: whole second, PTTL 1000' => ['9007199254739000', 1000, '9007199254740', false, true],
            'whole second, PTTL 1' => ['9007199254739000', 1, '9007199254740', false, false],
            'whole second, PTTL 999' => ['9007199254739000', 999, '9007199254740', false, false],
            // The sum is exactly a whole second from a fractional clock.
            'fraction 999 ms, PTTL 1: sum is a whole second' => ['9007199254738999', 1, '9007199254739', false, true],
            'fraction 999 ms, PTTL 2: one ms past the second' => ['9007199254738999', 2, '9007199254740', false, false],
            'fraction 1 ms, PTTL 999: sum is a whole second' => ['9007199254739001', 999, '9007199254740', false, true],
            'fraction 1 ms, PTTL 1000: one ms past the second' => ['9007199254739001', 1000, '9007199254741', false, false],
            'fraction 500 ms, PTTL 500: sum is a whole second' => ['9007199254739500', 500, '9007199254740', false, false],
            // The top of the clock domain (2^53 - 1) and the sums that pass 2^53.
            'the clock maximum, PTTL 1: sum is 2^53' => ['9007199254740991', 1, '9007199254741', false, true],
            'the clock maximum, PTTL 9: sum is a whole second' => ['9007199254740991', 9, '9007199254741', false, false],
            'the clock maximum, PTTL 10: one ms past the second' => ['9007199254740991', 10, '9007199254742', false, false],
            // Contemporary clocks: held by every width.
            'contemporary, whole second, PTTL 1000' => ['1800000000000', 1000, '1800000001', true, true],
            'contemporary, 1 ms in, PTTL 1000' => ['1800000000001', 1000, '1800000002', true, false],
            'contemporary, 999 ms in, PTTL 1: a whole second' => ['1800000000999', 1, '1800000001', true, false],
            'contemporary, 999 ms in, PTTL 2' => ['1800000000999', 2, '1800000002', true, false],
            'contemporary, 1500 ms of PTTL' => ['1800000000500', 1500, '1800000002', true, false],
        ];
    }

    /** @return array<string, array{string, int, string, bool, string, bool}> case x source x path */
    public static function casesBySourceAndPath(): array
    {
        $rows = [];
        foreach (self::cases() as $label => [$clock, $pttl, $expected, $native, $withPrevious]) {
            foreach (($withPrevious ? ['current', 'previous'] : ['current']) as $source) {
                foreach (['direct' => false, 'overlay' => true] as $path => $overlay) {
                    $rows[$label . ' / ' . $source . ' / ' . $path] = [$clock, $pttl, $expected, $native, $source, $overlay];
                }
            }
        }

        return $rows;
    }

    /** @param callable(): ?GenerationBoundScoreStateDTO $read */
    private function viaPath(callable $read, bool $overlay, RedisFullCapabilityStore $store): ?GenerationBoundScoreStateDTO
    {
        return $overlay ? $store->transactRequest($read) : $read();
    }

    /** The exact answer is the ceil of the sum, wherever the sum falls; Redis is never written, directly or inside a request. */
    #[DataProvider('casesBySourceAndPath')]
    public function testTheLegacyExpiryIsTheExactCeilOfClockPlusRemainingLife(string $clock, int $pttl, string $expected, bool $native, string $source, bool $overlay): void
    {
        $this->seedLegacy($source === 'current' ? 'k' : 'prev', 5000);
        $this->pinned->pin($pttl);
        $before = StateSnapshot::all($this->executor);
        $read = fn(): ?GenerationBoundScoreStateDTO => $this->pinnedStore->readGenerationBoundScoreStateAtMilliseconds('k', $source === 'previous' ? 'prev' : null, $clock);

        if (PHP_INT_SIZE < 8 && ! $native) {
            try {
                $this->viaPath($read, $overlay, $this->pinnedStore);
                self::fail('Seconds above 2^31 - 1 cannot be held by a 32-bit int.');
            } catch (RateLimiterException $exception) {
                self::assertStringContainsString('Malformed generation-bound expiry', $exception->getMessage());
            }
            self::assertGreaterThanOrEqual(1, $this->pinned->replaced, 'The pinned statement was in the script that ran.');
            self::assertSame($before, StateSnapshot::all($this->executor), 'Refused without any change: every key, its bytes and its exact expiry.');

            return;
        }

        $state = $this->viaPath($read, $overlay, $this->pinnedStore);

        self::assertGreaterThanOrEqual(1, $this->pinned->replaced, 'The pinned statement was in the script that ran.');
        self::assertNotNull($state);
        self::assertSame($source, $state->source);
        self::assertSame(8, $state->value);
        self::assertSame(1, $state->updatedAt);
        self::assertNull($state->generation);
        self::assertSame($expected, (string) $state->expiresAt);
        self::assertSame($before, StateSnapshot::all($this->executor), 'A read changes nothing: every key, its bytes and its exact expiry.');
    }

    /** @return array<string, array{bool}> */
    public static function paths(): array
    {
        return ['direct' => [false], 'overlay' => [true]];
    }

    /**
     * The unmodified script on a real PTTL. Outside PTTL readings bound the PTTL the script saw to (0, 1000], and the exact answer at this clock is then 9007199254740
     * for every value in that window, so the assertion does not depend on the millisecond (no sleep). It detects the defect only when the live PTTL happens to hit
     * the whole-second sum; the pinned cases above are the deterministic detector.
     */
    #[DataProvider('paths')]
    public function testARealPttlInsideTheWindowAnswersTheSameExactExpiry(bool $overlay): void
    {
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $this->seedLegacy('k', 1000);
            $before = StateSnapshot::all($this->executor);
            $pttlBefore = $this->executor->execute(['PTTL', $this->key('k')]);
            $read = fn(): ?GenerationBoundScoreStateDTO => $this->store->readGenerationBoundScoreStateAtMilliseconds('k', null, '9007199254739000');
            $error = null;
            $state = null;
            try {
                $state = $this->viaPath($read, $overlay, $this->store);
            } catch (RateLimiterException $exception) {
                $error = $exception;
            }
            $pttlAfter = $this->executor->execute(['PTTL', $this->key('k')]);
            if (! is_int($pttlBefore) || ! is_int($pttlAfter) || $pttlBefore <= 0 || $pttlBefore > 1000 || $pttlAfter <= 0 || $pttlAfter > $pttlBefore) {
                continue;
            }
            self::assertSame($before, StateSnapshot::all($this->executor), 'A read changes nothing.');
            if (PHP_INT_SIZE < 8) {
                self::assertInstanceOf(RateLimiterException::class, $error);
                self::assertStringContainsString('Malformed generation-bound expiry', $error->getMessage());

                return;
            }
            self::assertNull($error);
            self::assertNotNull($state);
            self::assertSame('9007199254740', (string) $state->expiresAt);

            return;
        }
        self::markTestSkipped('The PTTL of the call could not be bounded inside (0, 1000] ms in 20 attempts; the pinned cases are the detector.');
    }

    /** A stored generated expiry is the authority and is never recomputed; on 32-bit the unrepresentable second keeps being refused. */
    #[DataProvider('paths')]
    public function testAGeneratedStoredExpiryIsReturnedAsStored(bool $overlay): void
    {
        $this->executor->execute(['HSET', $this->key('k'), 'value', '8', 'updatedAt', '9007199254738', 'generation', '1', 'expiresAt', '9007199254740']);
        $this->executor->execute(['EXPIRE', $this->key('k'), '600']);
        $before = StateSnapshot::all($this->executor);
        $read = fn(): ?GenerationBoundScoreStateDTO => $this->store->readGenerationBoundScoreStateAtMilliseconds('k', null, '9007199254739000');

        if (PHP_INT_SIZE < 8) {
            try {
                $this->viaPath($read, $overlay, $this->store);
                self::fail('A stored expiry above 2^31 - 1 cannot be held by a 32-bit int.');
            } catch (RateLimiterException $exception) {
                self::assertStringContainsString('Malformed generation-bound', $exception->getMessage());
            }
        } else {
            $state = $this->viaPath($read, $overlay, $this->store);
            self::assertNotNull($state);
            self::assertSame(1, $state->generation);
            self::assertSame('9007199254740', (string) $state->expiresAt);
        }
        self::assertSame($before, StateSnapshot::all($this->executor));
    }

    /** A logically expired generated score is absent (null) on every width, and a read writes nothing. */
    #[DataProvider('paths')]
    public function testALogicallyExpiredGeneratedScoreIsAbsent(bool $overlay): void
    {
        $this->executor->execute(['HSET', $this->key('k'), 'value', '8', 'updatedAt', '1', 'generation', '1', 'expiresAt', '9007199254738']);
        $this->executor->execute(['EXPIRE', $this->key('k'), '600']);
        $before = StateSnapshot::all($this->executor);

        $state = $this->viaPath(fn(): ?GenerationBoundScoreStateDTO => $this->store->readGenerationBoundScoreStateAtMilliseconds('k', null, '9007199254739000'), $overlay, $this->store);

        self::assertNull($state);
        self::assertSame($before, StateSnapshot::all($this->executor));
    }

    /** Malformed state stays an explicit failure (never absence), on every width. */
    #[DataProvider('paths')]
    public function testMalformedStateIsStillAnExplicitFailure(bool $overlay): void
    {
        $this->executor->execute(['HSET', $this->key('k'), 'value', '8', 'updatedAt', '1', 'generation', 'x', 'expiresAt', '9007199254740']);
        $this->executor->execute(['EXPIRE', $this->key('k'), '600']);
        $before = StateSnapshot::all($this->executor);
        try {
            $this->viaPath(fn(): ?GenerationBoundScoreStateDTO => $this->store->readGenerationBoundScoreStateAtMilliseconds('k', null, '9007199254739000'), $overlay, $this->store);
            self::fail('A malformed generation must be rejected, not read as absence.');
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('alformed', $exception->getMessage());
        }
        self::assertSame($before, StateSnapshot::all($this->executor));
    }

    /** No key at all is absence (null). */
    #[DataProvider('paths')]
    public function testAMissingScoreIsAbsent(bool $overlay): void
    {
        $before = StateSnapshot::all($this->executor);

        $state = $this->viaPath(fn(): ?GenerationBoundScoreStateDTO => $this->store->readGenerationBoundScoreStateAtMilliseconds('k', null, '9007199254739000'), $overlay, $this->store);

        self::assertNull($state);
        self::assertSame($before, StateSnapshot::all($this->executor));
    }
}

/**
 * Test-only decorator: replaces the single statement that reads a legacy score's PTTL with a constant in every script it forwards, so the arithmetic that
 * follows runs on a PTTL the test fixes. Everything else (the key reads, the validation, the answer) is the unmodified production script on a real Redis.
 */
final class PinnedPttlExecutor implements RedisCommandExecutorInterface
{
    public int $replaced = 0;

    private int $pttl = 1;

    public function __construct(private readonly RedisCommandExecutorInterface $inner, private readonly string $statement) {}

    public function pin(int $pttl): void
    {
        $this->pttl = $pttl;
        $this->replaced = 0;
    }

    public function execute(array $command): mixed
    {
        if ($command[0] === 'EVAL' && is_string($command[1] ?? null) && str_contains($command[1], $this->statement)) {
            $script = str_replace($this->statement, 'local pttl = ' . $this->pttl, $command[1], $count);
            $this->replaced += $count;
            $command = [$command[0], $script, ...array_slice($command, 2)];
        }

        return $this->inner->execute($command);
    }
}
