<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-185-24 and F-185-25 (#185), the Lead's adv8 `r4_numeric_bounds.php` on a real Redis.
 *
 * - F-185-24: `incrementBudget()` / `incrementBudgetAt()` and the seeded `incrementBudgetWithSeed()` /
 *   `incrementBudgetWithSeedAt()` reject an amount below 1 with `RateLimiterException` before any write, and
 *   `BUDGET_INCREMENT` / `BUDGET_SEED` themselves accept only a canonical positive amount (one rule for every budget
 *   increment). On `78526b5` an amount of -5 created a budget with count -5 that every later read rejected as
 *   `malformed budget state`.
 * - F-185-25: an increment past the PHP integer range is a classified error that writes nothing: `budget count overflow`
 *   (`BUDGET_INCREMENT`, and the active epoch of `BUDGET_SEED`), `watch count overflow` (`WATCH_INCREMENT`, the rotated
 *   watch, including the answered sum of Current and Previous), as `SCORE_INCREMENT` answers `score integer overflow`. On
 *   `78526b5` the first three surfaced Redis's raw `ERR increment or decrement would overflow`, and the rotated sum created
 *   Current before PHP rejected the reply as malformed.
 */
#[Group('f-185-24')]
#[Group('f-185-25')]
final class NumericBoundsRedisTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const NAMESPACE = 'f185-24';

    private const MAX = '9223372036854775807';

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

    private function rawKey(string $family, string $logical): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':' . $family . ':' . hash('sha256', $logical);
    }

    private function seedBudget(string $logical, string $count): void
    {
        $this->executor->execute(['HSET', $this->rawKey('budget', $logical), 'count', $count, 'epochStart', (string) self::NOW, 'epochDuration', '86400']);
        $this->executor->execute(['EXPIRE', $this->rawKey('budget', $logical), '86400']);
    }

    private function seedWatch(string $logical, string $count): void
    {
        $this->executor->execute(['SET', $this->rawKey('watch', $logical), $count]);
        $this->executor->execute(['EXPIRE', $this->rawKey('watch', $logical), '1800']);
        $this->executor->execute(['HSET', $this->rawKey('watch-meta', $logical), 'expiresAt', (string) (self::NOW + 1800)]);
        $this->executor->execute(['EXPIRE', $this->rawKey('watch-meta', $logical), '1800']);
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

    /** The call must raise `RateLimiterException` with `$message` and leave every key byte-identical. */
    private function assertRejectedWithoutWrite(string $message, callable $call): void
    {
        $before = $this->dump();
        try {
            $call();
            self::fail('Expected RateLimiterException: ' . $message);
        } catch (BackendFailureException $exception) {
            self::fail('Raised BackendFailureException: ' . $exception->getMessage());
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
        self::assertSame($before, $this->dump(), $message . ': the state must be left byte-identical.');
    }

    /** @return iterable<string, array{int}> */
    public static function amountsBelowOne(): iterable
    {
        yield '-5' => [-5];
        yield '0' => [0];
    }

    #[DataProvider('amountsBelowOne')]
    public function testABudgetAmountBelowOneIsRejectedBeforeAnyWrite(int $amount): void
    {
        $this->assertRejectedWithoutWrite('Budget amount must be positive.', fn() => $this->store->incrementBudgetAt('fresh', 86400, $amount, self::NOW));
        $this->assertRejectedWithoutWrite('Budget amount must be positive.', fn() => $this->store->incrementBudget('fresh', 86400, $amount));
        self::assertNull($this->store->getBudgetAt('fresh', self::NOW));

        $this->seedBudget('active', '3');
        $this->assertRejectedWithoutWrite('Budget amount must be positive.', fn() => $this->store->incrementBudgetAt('active', 86400, $amount, self::NOW + 1));
    }

    /** The seeded path follows the same rule: fresh, inside a live seed, and on an active Current epoch. */
    #[DataProvider('amountsBelowOne')]
    public function testASeededBudgetAmountBelowOneIsRejectedBeforeAnyWrite(int $amount): void
    {
        $liveSeed = new BudgetStateDTO(4, self::NOW);
        $expiredSeed = new BudgetStateDTO(4, self::NOW - 90000);
        $this->assertRejectedWithoutWrite('Budget amount must be positive.', fn() => $this->store->incrementBudgetWithSeedAt('fresh', 86400, $liveSeed, $amount, self::NOW + 1));
        $this->assertRejectedWithoutWrite('Budget amount must be positive.', fn() => $this->store->incrementBudgetWithSeedAt('fresh', 86400, $expiredSeed, $amount, self::NOW + 1));
        $this->assertRejectedWithoutWrite('Budget amount must be positive.', fn() => $this->store->incrementBudgetWithSeed('fresh', 86400, $liveSeed, $amount));
        self::assertNull($this->store->getBudgetAt('fresh', self::NOW + 1));

        $this->seedBudget('active', '3');
        $this->assertRejectedWithoutWrite('Budget amount must be positive.', fn() => $this->store->incrementBudgetWithSeedAt('active', 86400, $liveSeed, $amount, self::NOW + 1));
    }

    /** @return iterable<string, array{string}> */
    public static function nonCanonicalAmounts(): iterable
    {
        foreach (['-5', '0', '05', '1.5', '', '9223372036854775808'] as $amount) {
            yield var_export($amount, true) => [$amount];
        }
    }

    /** The script checks the amount itself, for a caller that reaches it without the PHP guard. */
    #[DataProvider('nonCanonicalAmounts')]
    public function testTheBudgetScriptRejectsANonCanonicalAmount(string $amount): void
    {
        $script = (new ReflectionClass(RedisFullCapabilityStore::class))->getConstant('BUDGET_INCREMENT');
        self::assertIsString($script);
        $this->seedBudget('active', '3');

        $this->assertRejectedWithoutWrite('malformed budget amount', fn() => $this->executor->execute(['EVAL', $script, '1', $this->rawKey('budget', 'active'), '86400', $amount, (string) (self::NOW + 1)]));
        $this->assertRejectedWithoutWrite('malformed budget amount', fn() => $this->executor->execute(['EVAL', $script, '1', $this->rawKey('budget', 'fresh'), '86400', $amount, (string) (self::NOW + 1)]));
    }

    /** `BUDGET_SEED` checks the amount itself as well (ARGV: duration, seed start, seed count, amount, seeded, overflow, now). */
    #[DataProvider('nonCanonicalAmounts')]
    public function testTheSeedScriptRejectsANonCanonicalAmount(string $amount): void
    {
        $script = (new ReflectionClass(RedisFullCapabilityStore::class))->getConstant('BUDGET_SEED');
        self::assertIsString($script);
        $this->seedBudget('active', '3');

        foreach (['active', 'fresh'] as $logical) {
            $this->assertRejectedWithoutWrite('malformed budget amount', fn() => $this->executor->execute(['EVAL', $script, '1', $this->rawKey('budget', $logical), '86400', (string) self::NOW, '4', $amount, '5', '0', (string) (self::NOW + 1)]));
        }
    }

    /** Control: a positive seeded amount keeps the seeded semantics (count = seed + amount inside the seed's epoch). */
    public function testAPositiveSeededAmountStillApplies(): void
    {
        $state = $this->store->incrementBudgetWithSeedAt('seeded', 86400, new BudgetStateDTO(4, self::NOW), 2, self::NOW + 1);
        self::assertSame([6, self::NOW], [$state->count, $state->epochStart]);
        self::assertSame(7, $this->store->incrementBudgetWithSeedAt('seeded', 86400, new BudgetStateDTO(4, self::NOW), 1, self::NOW + 2)->count);
    }

    /** Control: positive amounts keep the fixed-window semantics. */
    public function testPositiveAmountsStillIncrement(): void
    {
        self::assertSame(2, $this->store->incrementBudgetAt('control', 86400, 2, self::NOW)->count);
        self::assertSame(3, $this->store->incrementBudgetAt('control', 86400, 1, self::NOW + 1)->count);
    }

    public function testABudgetCountOverflowIsClassifiedAndWritesNothing(): void
    {
        $this->seedBudget('full', self::MAX);
        $this->assertRejectedWithoutWrite('budget count overflow', fn() => $this->store->incrementBudgetAt('full', 86400, 1, self::NOW + 1));
        $this->assertRejectedWithoutWrite('budget count overflow', fn() => $this->store->incrementBudgetWithSeedAt('full', 86400, new BudgetStateDTO(1, self::NOW), 1, self::NOW + 1));
        self::assertSame(PHP_INT_MAX, $this->store->getBudgetAt('full', self::NOW + 1)?->count);
    }

    public function testAWatchCountOverflowIsClassifiedAndWritesNothing(): void
    {
        $this->seedWatch('full', self::MAX);
        $this->assertRejectedWithoutWrite('watch count overflow', fn() => $this->store->incrementWatchFlagAt('full', 1800, self::NOW));
        $this->assertRejectedWithoutWrite('watch count overflow', fn() => $this->store->incrementWatchFlagAcrossRotationAt('full', 'absent-previous', 1800, self::NOW));
        self::assertSame(PHP_INT_MAX, $this->store->getWatchFlag('full'));
    }

    /** The rotated watch answers Current plus Previous; a sum past the range is rejected before Current is written. */
    public function testARotatedWatchSumPastTheRangeIsClassifiedBeforeCurrentIsWritten(): void
    {
        $this->seedWatch('previous', self::MAX);
        $this->assertRejectedWithoutWrite('watch count overflow', fn() => $this->store->incrementWatchFlagAcrossRotationAt('current', 'previous', 1800, self::NOW));
        self::assertSame(0, $this->executor->execute(['EXISTS', $this->rawKey('watch', 'current')]));
    }

    /** Controls: one below the bound still reaches it exactly, and the score overflow keeps its message. */
    public function testOneBelowTheBoundStillReachesItExactly(): void
    {
        $belowMax = (string) (PHP_INT_MAX - 1);
        $this->seedBudget('near', $belowMax);
        self::assertSame(PHP_INT_MAX, $this->store->incrementBudgetAt('near', 86400, 1, self::NOW + 1)->count);
        $this->seedWatch('near', $belowMax);
        self::assertSame(PHP_INT_MAX, $this->store->incrementWatchFlagAt('near', 1800, self::NOW));

        $score = 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':score:' . hash('sha256', 's');
        $this->executor->execute(['HSET', $score, 'value', self::MAX, 'updatedAt', (string) self::NOW]);
        $this->executor->execute(['EXPIRE', $score, '600']);
        $this->assertRejectedWithoutWrite('score integer overflow', fn() => $this->store->incrementAt('s', 600, 1, self::NOW));
    }
}
