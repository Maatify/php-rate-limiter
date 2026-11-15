<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #227: a score increment whose result the caller's PHP `int` cannot hold is rejected before anything is written.
 *
 * Redis stores the score as a 64-bit decimal; the store returns it as a native `int` (`RateLimitStoreInterface::increment()`),
 * which on a build with `PHP_INT_SIZE` 4 ends at 2147483647. Before the correction the script incremented first and PHP
 * rejected the reply afterwards, so the direct increment left a value (and a new `updatedAt`) that no caller of that width could
 * read back, while the same call inside `transactRequest()` discarded it. The script now judges the exact signed decimal sum
 * against the caller's `PHP_INT_MAX` / `PHP_INT_MIN` (passed as arguments) before any write, in the same atomic script.
 *
 * Every case runs through the direct store and through `transactRequest()`. The state is compared before and after as a hash
 * and as the exact `PEXPIRETIME` (an `int`, or its decimal string where it exceeds the native integer: see #219).
 */
#[Group('integration')]
#[Group('php32')]
#[Group('score-range')]
final class ScoreIncrementNativeRangeRedisTest extends TestCase
{
    private const NOW = 1_793_793_600;

    private const NAMESPACE = 'score-range';

    private const OVERFLOW = 'score integer overflow';

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

    private function rawKey(string $logical): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':score:' . hash('sha256', $logical);
    }

    /** @return array{hash: array<string, string>, expiry: int|string} */
    private function snapshot(string $logical = 's'): array
    {
        $flat = $this->executor->execute(['HGETALL', $this->rawKey($logical)]);
        self::assertIsArray($flat);
        $hash = [];
        for ($i = 0; $i + 1 < count($flat); $i += 2) {
            self::assertIsString($flat[$i]);
            self::assertIsString($flat[$i + 1]);
            $hash[$flat[$i]] = $flat[$i + 1];
        }
        ksort($hash);
        $expiry = $this->executor->execute(['PEXPIRETIME', $this->rawKey($logical)]);
        self::assertTrue(is_int($expiry) || is_string($expiry));

        return ['hash' => $hash, 'expiry' => $expiry];
    }

    private function seed(string $value, string $logical = 's', int $updatedAt = self::NOW): void
    {
        $this->executor->execute(['HSET', $this->rawKey($logical), 'value', $value, 'updatedAt', (string) $updatedAt]);
        $this->executor->execute(['EXPIRE', $this->rawKey($logical), '3600']);
    }

    /** @return array<string, array{bool}> */
    public static function paths(): array
    {
        return ['direct store' => [false], 'inside transactRequest()' => [true]];
    }

    private function increment(bool $overlay, int $amount, int $now = self::NOW + 10, string $logical = 's'): int
    {
        if (! $overlay) {
            return $this->store->incrementAt($logical, 3600, $amount, $now);
        }
        return $this->store->transactRequest(fn(): int => $this->store->incrementAt($logical, 3600, $amount, $now));
    }

    private function assertRejectedUnchanged(bool $overlay, int $amount, string $logical = 's'): void
    {
        $before = $this->snapshot($logical);
        $caught = null;
        try {
            $this->increment($overlay, $amount, self::NOW + 20, $logical);
        } catch (\Throwable $exception) {
            $caught = $exception;
        }
        // The state is judged first: a rejection that had already written something is the defect, whatever it raised.
        self::assertSame($before, $this->snapshot($logical), 'Hash, updatedAt and the exact expiry are unchanged.');
        self::assertInstanceOf(RateLimiterException::class, $caught, 'The increment must be rejected.');
        self::assertStringContainsString(self::OVERFLOW, $caught->getMessage());
    }

    #[DataProvider('paths')]
    public function testTheUpperEdgeIsReachedExactlyAndOneBeyondIsRejectedBeforeAnyWrite(bool $overlay): void
    {
        self::assertSame(1, $this->increment($overlay, 1));
        self::assertSame(PHP_INT_MAX, $this->increment($overlay, PHP_INT_MAX - 1));
        self::assertSame((string) PHP_INT_MAX, $this->snapshot()['hash']['value']);

        $this->assertRejectedUnchanged($overlay, 1);
        $this->assertRejectedUnchanged($overlay, PHP_INT_MAX);
    }

    #[DataProvider('paths')]
    public function testOneBeyondByValidIncrementsIsRejectedFromBelowTheEdge(bool $overlay): void
    {
        $this->seed((string) (PHP_INT_MAX - 1));

        $this->assertRejectedUnchanged($overlay, 2);
        self::assertSame(PHP_INT_MAX, $this->increment($overlay, 1));
    }

    #[DataProvider('paths')]
    public function testTheLowerEdgeIsReachedExactlyAndUnderflowIsRejectedBeforeAnyWrite(bool $overlay): void
    {
        self::assertSame(PHP_INT_MIN, $this->increment($overlay, PHP_INT_MIN), 'An absent key is created at the lower edge.');

        $this->assertRejectedUnchanged($overlay, -1);
        $this->assertRejectedUnchanged($overlay, PHP_INT_MIN);

        $this->executor->execute(['DEL', $this->rawKey('s')]);
        $this->seed('-1');
        $this->assertRejectedUnchanged($overlay, PHP_INT_MIN);
        self::assertSame(PHP_INT_MIN, $this->increment($overlay, PHP_INT_MIN + 1), 'From -1 the lower edge is reached exactly.');
    }

    #[DataProvider('paths')]
    public function testAnAbsentKeyIsCreatedAtTheUpperEdgeAndRepresentableControlsStillWork(bool $overlay): void
    {
        self::assertSame(PHP_INT_MAX, $this->increment($overlay, PHP_INT_MAX, self::NOW, 'upper'));

        self::assertSame(5, $this->increment($overlay, 5, self::NOW, 'control'));
        $created = $this->snapshot('control');
        self::assertSame(5, $this->increment($overlay, 0, self::NOW + 30, 'control'), 'Zero is representable.');
        self::assertSame(0, $this->increment($overlay, -5, self::NOW + 40, 'control'));
        self::assertSame('0', $this->snapshot('control')['hash']['value']);
        self::assertSame((string) (self::NOW + 40), $this->snapshot('control')['hash']['updatedAt'], 'A successful increment sets updatedAt to the caller time.');
        self::assertSame((string) self::NOW, $created['hash']['updatedAt']);
    }

    public function testTheTtlOfAnExistingScoreIsPreservedBySuccessfulIncrements(): void
    {
        $this->seed('5');
        $before = $this->snapshot();

        self::assertSame(8, $this->store->incrementAt('s', 3600, 3, self::NOW + 50));

        $after = $this->snapshot();
        self::assertSame($before['expiry'], $after['expiry'], 'The existing expiry is kept to the millisecond.');
        self::assertSame('8', $after['hash']['value']);
        self::assertSame((string) (self::NOW + 50), $after['hash']['updatedAt']);
    }

    public function testAStoredValueAlreadyOutsideTheCallersRangeIsRejectedBeforeAnyWrite(): void
    {
        if (PHP_INT_SIZE === 8) {
            self::markTestSkipped('A 64-bit caller can read every value the script stores; the case needs a 32-bit caller.');
        }
        // A 64-bit worker may have stored these on the same Redis; a 32-bit caller cannot read them back.
        foreach (['2147483648', '2147483654', '-2147483649', '9223372036854775807', '-9223372036854775808'] as $stored) {
            foreach ([false, true] as $overlay) {
                $this->executor->execute(['DEL', $this->rawKey('s')]);
                $this->seed($stored);
                foreach ([0, 1, -1] as $amount) {
                    $this->assertRejectedUnchanged($overlay, $amount);
                }
            }
        }
    }

    public function testAStoredValueAtTheCallersEdgeIsStillReadable(): void
    {
        $this->seed((string) PHP_INT_MAX);
        self::assertSame(PHP_INT_MAX - 1, $this->store->incrementAt('s', 3600, -1, self::NOW + 60));
        $this->seed((string) PHP_INT_MIN, 'low');
        self::assertSame(PHP_INT_MIN + 1, $this->store->incrementAt('low', 3600, 1, self::NOW + 60));
    }

    public function testConcurrentIncrementsNearTheEdgeAreExactAndAtomic(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required.');
        }
        $headroom = 5;
        $workers = 8;
        $perWorker = 3;
        $this->seed((string) (PHP_INT_MAX - $headroom));

        $children = [];
        $release = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($release);
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
                    $ok = 0;
                    $rejected = 0;
                    $other = [];
                    for ($i = 0; $i < $perWorker; $i++) {
                        try {
                            $store->incrementAt('s', 3600, 1, self::NOW + 70);
                            $ok++;
                        } catch (RateLimiterException $exception) {
                            str_contains($exception->getMessage(), self::OVERFLOW) ? $rejected++ : $other[] = $exception->getMessage();
                        } catch (\Throwable $exception) {
                            $other[] = $exception::class;
                        }
                    }
                    fwrite($pair[1], json_encode(['ok' => $ok, 'rejected' => $rejected, 'other' => $other], JSON_THROW_ON_ERROR) . "\n");
                    exit(0);
                }
                fclose($pair[1]);
                $children[] = [$pid, $pair[0]];
            }
            fclose($release[0]);
            // Bounded readiness: every worker has built its connection before any increment is released.
            foreach ($children as [$pid, $stream]) {
                stream_set_timeout($stream, 20);
                self::assertSame("ready\n", fgets($stream), 'Worker ' . $pid . ' became ready.');
            }
            fwrite($release[1], str_repeat("go\n", $workers));
            fclose($release[1]);

            $ok = 0;
            $rejected = 0;
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

        self::assertSame($headroom, $ok, 'Exactly the headroom was granted.');
        self::assertSame($workers * $perWorker - $headroom, $rejected);
        self::assertSame((string) PHP_INT_MAX, $this->snapshot()['hash']['value'], 'The score ends exactly on the edge, never past it.');
    }

    public function testAPublicRequestWhoseScoreWouldOverflowSavesNothing(): void
    {
        $limiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('test_secret', 'test_fp', 'prod'),
            new RedisFullCapabilityStore($this->executor, 'score-range-public'),
            new RecordingFailureSignalEmitter(),
        )->withClock(new FixedClock('@' . self::NOW))->build();
        $context = new RateLimitContextDTO('192.0.2.1', 'ua', 'account-edge', null, 'device-edge', false);
        $limiter->limit($context, RateLimitCommand::recordFailure('login_protection'));

        $scoreKeys = $this->executor->execute(['KEYS', '*:score:*']);
        self::assertIsArray($scoreKeys);
        self::assertNotSame([], $scoreKeys);
        foreach ($scoreKeys as $key) {
            self::assertIsString($key);
            $this->executor->execute(['HSET', $key, 'value', (string) (PHP_INT_MAX - 1)]);
        }
        $before = $this->everything();

        try {
            $limiter->limit($context, RateLimitCommand::recordFailure('login_protection'));
            self::fail('The request must be rejected.');
        } catch (BackendFailureException $exception) {
            self::fail('Raised BackendFailureException: ' . $exception->getMessage());
        } catch (RateLimiterException $exception) {
            // The pipeline's own score arithmetic ("Score arithmetic overflow") judges the sum first; the store's script is the second guard.
            self::assertMatchesRegularExpression('/overflow/i', $exception->getMessage());
        }

        self::assertSame($before, $this->everything(), 'No part of the request was saved: every key, its content and its exact expiry are unchanged.');
    }

    /** @return array<string, array{string, int|string}> */
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
}
