<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #230: the budget, seeded budget, watch and rotated watch increments (and the simple fixed-window throttle that consumes a budget)
 * reject a result that the caller's PHP `int` cannot hold before they write anything.
 *
 * Their scripts guarded the 64-bit range only, so on a build with `PHP_INT_SIZE` 4 the count 2147483648 was stored and PHP then
 * rejected the reply, as for the score in #227. The scripts now receive the caller's `PHP_INT_MAX` and judge the exact decimal sum
 * against it before the write. Every rejection is compared as a byte-exact `DUMP` and the exact `PEXPIRETIME` of every key before and
 * after, so a changed value, a changed metadata hash, a created key and a one-millisecond expiry change are all visible.
 */
#[Group('integration')]
#[Group('php32')]
#[Group('counter-range')]
final class CounterIncrementNativeRangeRedisTest extends TestCase
{
    private const NOW = 1_793_793_600;

    private const NAMESPACE = 'counter-range';

    private RespRedisCommandExecutor $executor;

    private RedisFullCapabilityStore $store;

    private string $host;

    private int $port;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->host = $host;
        $this->port = (int) $port;
        $this->executor = new RespRedisCommandExecutor($host, $this->port);
        $this->executor->execute(['FLUSHDB']);
        $this->store = new RedisFullCapabilityStore($this->executor, self::NAMESPACE);
    }

    private function rawKey(string $family, string $logical): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':' . $family . ':' . hash('sha256', $logical);
    }

    private function seedBudget(string $logical, string $count, int $start = self::NOW, int $duration = 86400): void
    {
        $this->executor->execute(['HSET', $this->rawKey('budget', $logical), 'count', $count, 'epochStart', (string) $start, 'epochDuration', (string) $duration]);
        $this->executor->execute(['EXPIRE', $this->rawKey('budget', $logical), (string) $duration]);
    }

    private function seedWatch(string $logical, string $count): void
    {
        $this->executor->execute(['SET', $this->rawKey('watch', $logical), $count]);
        $this->executor->execute(['EXPIRE', $this->rawKey('watch', $logical), '1800']);
        $this->executor->execute(['HSET', $this->rawKey('watch-meta', $logical), 'expiresAt', (string) (self::NOW + 1800)]);
        $this->executor->execute(['EXPIRE', $this->rawKey('watch-meta', $logical), '1800']);
    }

    /** @return array<string, array{string, int|string}> byte-exact DUMP and exact PEXPIRETIME of every key */
    private function everything(): array
    {
        $keys = $this->executor->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        sort($keys);
        $all = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $dump = $this->executor->execute(['DUMP', $key]);
            self::assertIsString($dump);
            $expiry = $this->executor->execute(['PEXPIRETIME', $key]);
            self::assertTrue(is_int($expiry) || is_string($expiry));
            $all[$key] = [bin2hex($dump), $expiry];
        }

        return $all;
    }

    /** @return array<string, array{bool}> */
    public static function paths(): array
    {
        return ['direct store' => [false], 'inside transactRequest()' => [true]];
    }

    /**
     * @template T
     * @param callable(): T $call
     * @return T
     */
    private function through(bool $overlay, callable $call): mixed
    {
        return $overlay ? $this->store->transactRequest($call) : $call();
    }

    /** The call must be rejected as a classified `RateLimiterException` with `$message`, and every key must be byte- and millisecond-identical. */
    private function assertRejected(string $message, callable $call): void
    {
        $before = $this->everything();
        $caught = null;
        try {
            $call();
        } catch (\Throwable $exception) {
            $caught = $exception;
        }
        // The state is judged first: a rejection that had already written something is the defect, whatever it raised.
        self::assertSame($before, $this->everything(), $message . ': no value, metadata, key or expiry changed.');
        self::assertInstanceOf(RateLimiterException::class, $caught, $message . ': the call must be rejected.');
        self::assertStringContainsString($message, $caught->getMessage());
    }

    private function assertBudget(int $count, BudgetStateDTO $state): void
    {
        self::assertSame($count, $state->count);
    }

    // ---- budget ---------------------------------------------------------------------------------------------------------------

    #[DataProvider('paths')]
    public function testABudgetReachesTheCallersMaximumExactlyAndOneBeyondIsRejectedBeforeAnyWrite(bool $overlay): void
    {
        $this->seedBudget('b', (string) (PHP_INT_MAX - 1));
        $before = $this->everything();
        $state = $this->through($overlay, fn(): BudgetStateDTO => $this->store->incrementBudgetAt('b', 86400, 1, self::NOW + 5));
        $this->assertBudget(PHP_INT_MAX, $state);
        $after = $this->everything();
        $key = $this->rawKey('budget', 'b');
        self::assertSame($before[$key][1], $after[$key][1], 'An increment of the active epoch keeps its expiry to the millisecond (no renewal).');
        self::assertSame(self::NOW, $state->epochStart, 'The epoch start is not renewed.');

        $this->assertRejected('budget count overflow', fn() => $this->through($overlay, fn(): BudgetStateDTO => $this->store->incrementBudgetAt('b', 86400, 1, self::NOW + 6)));
    }

    #[DataProvider('paths')]
    public function testABudgetAmountThatWouldPassTheMaximumIsRejectedAndOneThatReachesItIsGranted(bool $overlay): void
    {
        $this->seedBudget('b', (string) (PHP_INT_MAX - 5));
        $this->assertRejected('budget count overflow', fn() => $this->through($overlay, fn(): BudgetStateDTO => $this->store->incrementBudgetAt('b', 86400, 6, self::NOW + 5)));
        $this->assertBudget(PHP_INT_MAX, $this->through($overlay, fn(): BudgetStateDTO => $this->store->incrementBudgetAt('b', 86400, 5, self::NOW + 5)));

        $this->executor->execute(['DEL', $this->rawKey('budget', 'b')]);
        $this->seedBudget('c', '1');
        $this->assertRejected('budget count overflow', fn() => $this->through($overlay, fn(): BudgetStateDTO => $this->store->incrementBudgetAt('c', 86400, PHP_INT_MAX, self::NOW + 5)));
    }

    #[DataProvider('paths')]
    public function testAnAbsentBudgetIsCreatedAtTheMaximumAndAnExpiredEpochIsRenewedWhateverItsCount(bool $overlay): void
    {
        $this->assertBudget(PHP_INT_MAX, $this->through($overlay, fn(): BudgetStateDTO => $this->store->incrementBudgetAt('new', 86400, PHP_INT_MAX, self::NOW)));

        // A stale epoch is replaced by a fresh one with the amount, as before, even when its stale count is the maximum.
        $this->seedBudget('stale', (string) PHP_INT_MAX, self::NOW - 90_000, 86400);
        $this->executor->execute(['PERSIST', $this->rawKey('budget', 'stale')]);
        $this->executor->execute(['EXPIRE', $this->rawKey('budget', 'stale'), '600']);
        $renewed = $this->through($overlay, fn(): BudgetStateDTO => $this->store->incrementBudgetAt('stale', 86400, 3, self::NOW));
        $this->assertBudget(3, $renewed);
        self::assertSame(self::NOW, $renewed->epochStart);
    }

    public function testAStoredBudgetCountOutsideTheCallersRangeIsRejectedBeforeAnyWrite(): void
    {
        if (PHP_INT_SIZE === 8) {
            self::markTestSkipped('A 64-bit caller can read every count the script stores; the case needs a 32-bit caller.');
        }
        // A 64-bit worker may have stored these on the same Redis; a 32-bit caller cannot read them back.
        foreach (['2147483648', '2147483654', '9223372036854775807'] as $stored) {
            foreach ([false, true] as $overlay) {
                $this->executor->execute(['DEL', $this->rawKey('budget', 'm')]);
                $this->seedBudget('m', $stored);
                $this->assertRejected('budget count overflow', fn() => $this->through($overlay, fn(): BudgetStateDTO => $this->store->incrementBudgetAt('m', 86400, 1, self::NOW + 5)));
            }
        }
    }

    // ---- seeded budget --------------------------------------------------------------------------------------------------------

    #[DataProvider('paths')]
    public function testASeededBudgetKeepsTheSameRangeRuleOnItsActiveEpochAndItsSeedPath(bool $overlay): void
    {
        $seed = new BudgetStateDTO(7, self::NOW);
        $this->seedBudget('s', (string) (PHP_INT_MAX - 1));
        $this->assertBudget(PHP_INT_MAX, $this->through($overlay, fn(): BudgetStateDTO => $this->store->incrementBudgetWithSeedAt('s', 86400, $seed, 1, self::NOW + 5)));
        $this->assertRejected('budget count overflow', fn() => $this->through($overlay, fn(): BudgetStateDTO => $this->store->incrementBudgetWithSeedAt('s', 86400, $seed, 1, self::NOW + 6)));

        // No current epoch: the seed carries over, and a seed whose sum passes the range is rejected by the existing check, before any write.
        $this->assertRejected('seeded budget count overflow', fn() => $this->through($overlay, fn(): BudgetStateDTO => $this->store->incrementBudgetWithSeedAt('absent', 86400, new BudgetStateDTO(PHP_INT_MAX, self::NOW), 1, self::NOW + 5)));
        $carried = $this->through($overlay, fn(): BudgetStateDTO => $this->store->incrementBudgetWithSeedAt('absent', 86400, new BudgetStateDTO(PHP_INT_MAX - 1, self::NOW), 1, self::NOW + 5));
        $this->assertBudget(PHP_INT_MAX, $carried);
        self::assertSame(self::NOW, $carried->epochStart, 'The seed epoch start is kept.');
    }

    public function testAStoredSeededBudgetCountOutsideTheCallersRangeIsRejectedBeforeAnyWrite(): void
    {
        if (PHP_INT_SIZE === 8) {
            self::markTestSkipped('The case needs a 32-bit caller.');
        }
        $this->seedBudget('s', '2147483654');
        $this->assertRejected('budget count overflow', fn() => $this->store->incrementBudgetWithSeedAt('s', 86400, new BudgetStateDTO(1, self::NOW), 1, self::NOW + 5));
    }

    // ---- watch ----------------------------------------------------------------------------------------------------------------

    #[DataProvider('paths')]
    public function testAWatchReachesTheCallersMaximumExactlyAndOneBeyondIsRejectedBeforeAnyWrite(bool $overlay): void
    {
        $this->seedWatch('w', (string) (PHP_INT_MAX - 1));
        self::assertSame(PHP_INT_MAX, $this->through($overlay, fn(): int => $this->store->incrementWatchFlagAt('w', 1800, self::NOW + 5)));
        $this->assertRejected('watch count overflow', fn() => $this->through($overlay, fn(): int => $this->store->incrementWatchFlagAt('w', 1800, self::NOW + 6)));
        self::assertSame(1, $this->through($overlay, fn(): int => $this->store->incrementWatchFlagAt('fresh', 1800, self::NOW)), 'An absent watch starts at one.');
    }

    public function testAStoredWatchCountOutsideTheCallersRangeIsRejectedBeforeAnyWrite(): void
    {
        if (PHP_INT_SIZE === 8) {
            self::markTestSkipped('The case needs a 32-bit caller.');
        }
        foreach (['2147483648', '9223372036854775807'] as $stored) {
            foreach ([false, true] as $overlay) {
                $this->executor->execute(['FLUSHDB']);
                $this->seedWatch('w', $stored);
                $this->assertRejected('watch count overflow', fn() => $this->through($overlay, fn(): int => $this->store->incrementWatchFlagAt('w', 1800, self::NOW + 5)));
            }
        }
    }

    // ---- rotated watch --------------------------------------------------------------------------------------------------------

    #[DataProvider('paths')]
    public function testARotatedWatchJudgesTheSumOfCurrentAndPreviousAndWritesNothingWhenItPassesTheRange(bool $overlay): void
    {
        // Previous at the maximum, Current absent: the answered sum would be max + 1; Current must not be created.
        $this->seedWatch('prev', (string) PHP_INT_MAX);
        $this->assertRejected('watch count overflow', fn() => $this->through($overlay, fn(): int => $this->store->incrementWatchFlagAcrossRotationAt('cur', 'prev', 1800, self::NOW + 5)));
        self::assertSame(0, $this->executor->execute(['EXISTS', $this->rawKey('watch', 'cur')]), 'Current was not created.');
        self::assertSame(0, $this->executor->execute(['EXISTS', $this->rawKey('watch-meta', 'cur')]), 'Its metadata was not created.');

        // Previous at max - 1: the sum reaches the maximum exactly.
        $this->executor->execute(['FLUSHDB']);
        $this->seedWatch('prev', (string) (PHP_INT_MAX - 1));
        self::assertSame(PHP_INT_MAX, $this->through($overlay, fn(): int => $this->store->incrementWatchFlagAcrossRotationAt('cur', 'prev', 1800, self::NOW + 5)));

        // Current is now 1 and Previous max - 1: the next call would answer max + 1 and must write nothing, neither Current nor shared metadata.
        $this->assertRejected('watch count overflow', fn() => $this->through($overlay, fn(): int => $this->store->incrementWatchFlagAcrossRotationAt('cur', 'prev', 1800, self::NOW + 6)));
    }

    public function testARotatedWatchWithAnExpiredPreviousIgnoresItAndAStoredCountOutsideTheRangeIsRejected(): void
    {
        $this->seedWatch('prev', (string) PHP_INT_MAX);
        $this->executor->execute(['HSET', $this->rawKey('watch-meta', 'prev'), 'expiresAt', (string) (self::NOW - 1)]);
        self::assertSame(1, $this->store->incrementWatchFlagAcrossRotationAt('cur', 'prev', 1800, self::NOW), 'A logically expired Previous counts for nothing.');

        if (PHP_INT_SIZE === 4) {
            $this->executor->execute(['FLUSHDB']);
            $this->seedWatch('prev', '2147483648');
            $this->assertRejected('watch count overflow', fn() => $this->store->incrementWatchFlagAcrossRotationAt('cur', 'prev', 1800, self::NOW + 5));
        }
    }

    // ---- simple fixed-window throttle (a budget consumer) -------------------------------------------------------------------

    public function testTheSimpleThrottleRejectsAConsumptionThatWouldPassTheRangeAndWritesNothing(): void
    {
        $throttle = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('test_secret', 'test_fp', 'prod'),
            new RedisFullCapabilityStore($this->executor, 'counter-range-throttle'),
            new RecordingFailureSignalEmitter(),
        )->withClock(new FixedClock('@' . self::NOW))->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('bulk', PHP_INT_MAX, 3600))->build();

        $throttle->consume('bulk', 'subject', PHP_INT_MAX - 1);
        $throttle->consume('bulk', 'subject', 1);

        $this->assertRejected('budget count overflow', fn() => $throttle->consume('bulk', 'subject', 1));
        $this->assertRejected('budget count overflow', fn() => $throttle->consume('bulk', 'subject', PHP_INT_MAX));
    }

    // ---- contention ------------------------------------------------------------------------------------------------------------

    /**
     * Fork `$workers` processes, release them together after all are ready (bounded), run `$operation` `$perWorker` times in each, and
     * return the granted and the classified-rejected counts.
     *
     * @param callable(RedisFullCapabilityStore): mixed $operation
     * @return array{int, int}
     */
    private function race(int $workers, int $perWorker, string $rejection, callable $operation): array
    {
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required.');
        }
        $children = [];
        $release = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($release);
        $ok = 0;
        $rejected = 0;
        try {
            for ($w = 0; $w < $workers; $w++) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                self::assertIsArray($pair);
                $pid = pcntl_fork();
                self::assertNotSame(-1, $pid);
                if ($pid === 0) {
                    fclose($pair[0]);
                    fclose($release[1]);
                    $store = new RedisFullCapabilityStore(new RespRedisCommandExecutor($this->host, $this->port), self::NAMESPACE);
                    fwrite($pair[1], "ready\n");
                    stream_set_timeout($release[0], 20);
                    fgets($release[0]);
                    $granted = 0;
                    $refused = 0;
                    $other = [];
                    for ($i = 0; $i < $perWorker; $i++) {
                        try {
                            $operation($store);
                            $granted++;
                        } catch (RateLimiterException $exception) {
                            str_contains($exception->getMessage(), $rejection) ? $refused++ : $other[] = $exception->getMessage();
                        } catch (\Throwable $exception) {
                            $other[] = $exception::class;
                        }
                    }
                    fwrite($pair[1], json_encode(['ok' => $granted, 'rejected' => $refused, 'other' => $other], JSON_THROW_ON_ERROR) . "\n");
                    exit(0);
                }
                fclose($pair[1]);
                $children[] = [$pid, $pair[0]];
            }
            fclose($release[0]);
            foreach ($children as [$pid, $stream]) {
                stream_set_timeout($stream, 20);
                self::assertSame("ready\n", fgets($stream), 'Worker ' . $pid . ' became ready.');
            }
            fwrite($release[1], str_repeat("go\n", $workers));
            fclose($release[1]);
            foreach ($children as [$pid, $stream]) {
                $line = fgets($stream);
                self::assertIsString($line, 'Worker ' . $pid . ' reported.');
                $result = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($result);
                self::assertSame([], $result['other'], 'Only the classified overflow was raised.');
                self::assertIsInt($result['ok']);
                self::assertIsInt($result['rejected']);
                $ok += $result['ok'];
                $rejected += $result['rejected'];
            }
        } finally {
            foreach ($children as [$pid, $stream]) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                pcntl_waitpid($pid, $status);
            }
        }

        return [$ok, $rejected];
    }

    public function testConcurrentBudgetIncrementsNearTheMaximumAreExactAndAtomic(): void
    {
        $this->seedBudget('race', (string) (PHP_INT_MAX - 5));

        [$ok, $rejected] = $this->race(8, 3, 'budget count overflow', static fn(RedisFullCapabilityStore $s): BudgetStateDTO => $s->incrementBudgetAt('race', 86400, 1, self::NOW + 5));

        self::assertSame(5, $ok, 'Exactly the headroom was granted.');
        self::assertSame(19, $rejected);
        self::assertSame((string) PHP_INT_MAX, $this->executor->execute(['HGET', $this->rawKey('budget', 'race'), 'count']), 'The count ends on the maximum, never past it.');
    }

    public function testConcurrentWatchIncrementsNearTheMaximumAreExactAndAtomic(): void
    {
        $this->seedWatch('race', (string) (PHP_INT_MAX - 5));

        [$ok, $rejected] = $this->race(8, 3, 'watch count overflow', static fn(RedisFullCapabilityStore $s): int => $s->incrementWatchFlagAt('race', 1800, self::NOW + 5));

        self::assertSame(5, $ok);
        self::assertSame(19, $rejected);
        self::assertSame((string) PHP_INT_MAX, $this->executor->execute(['GET', $this->rawKey('watch', 'race')]));
    }

    // ---- a public request -----------------------------------------------------------------------------------------------------

    public function testAPublicRequestWhoseBudgetWouldOverflowSavesNothing(): void
    {
        $limiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('test_secret', 'test_fp', 'prod'),
            new RedisFullCapabilityStore($this->executor, 'counter-range-public'),
            new RecordingFailureSignalEmitter(),
        )->withClock(new FixedClock('@' . self::NOW))->build();
        $context = new RateLimitContextDTO('192.0.2.1', 'ua', 'account-budget-edge', null, 'device-edge', false);
        $limiter->limit($context, RateLimitCommand::recordFailure('login_protection'));

        $budgetKeys = $this->executor->execute(['KEYS', '*:budget:*']);
        self::assertIsArray($budgetKeys);
        self::assertNotSame([], $budgetKeys, 'The request created a budget.');
        foreach ($budgetKeys as $key) {
            self::assertIsString($key);
            $this->executor->execute(['HSET', $key, 'count', (string) PHP_INT_MAX]);
        }
        $before = $this->everything();

        $caught = null;
        try {
            $limiter->limit($context, RateLimitCommand::recordFailure('login_protection'));
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        self::assertSame($before, $this->everything(), 'No part of the request was saved: every key, its content and its exact expiry are unchanged.');
        self::assertInstanceOf(RateLimiterException::class, $caught);
        self::assertMatchesRegularExpression('/overflow/i', $caught->getMessage());
    }
}
