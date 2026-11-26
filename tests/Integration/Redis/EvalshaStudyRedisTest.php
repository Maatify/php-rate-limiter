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
use Maatify\RateLimiter\Service\AdmissionFenceControlInterface;
use Maatify\RateLimiter\Tests\Support\Admission\PublicRedisAdmissionFixture;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * EVALSHA study (#210, `investigations/evalsha-210/`): correctness of the experimental script path of the test client.
 * The path is not part of the package; the default mode stays `eval`. Isolated Redis, exact replies.
 *
 * - the wire command is `EVALSHA` of the exact text sent, and a cold cache costs one `NOSCRIPT` round trip, then `EVAL`;
 * - only an identified `NOSCRIPT` reply triggers the fallback; another error reply is rethrown and the script ran once;
 * - a lost request and a lost reply (a proxy that closes the connection) are never replayed;
 * - the same public workload ends in the same state, with the same decisions, in every script mode, including a cache loss
 *   before every script call and before every commit;
 * - an exact `PEXPIRETIME` and a one-millisecond difference survive the path.
 */
#[Group('integration')]
#[Group('evalsha-study')]
#[Group('php32')]
final class EvalshaStudyRedisTest extends TestCase
{
    private const NOW = 1_793_793_600;

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
        $setup = new RespRedisCommandExecutor($host, $this->port, 'eval');
        $setup->execute(['FLUSHALL']);
        $setup->execute(['CONFIG', 'RESETSTAT']);
    }

    private function client(string $mode, ?int $port = null): RespRedisCommandExecutor
    {
        return new RespRedisCommandExecutor($this->host, $port ?? $this->port, $mode);
    }

    private function raw(): RespRedisCommandExecutor
    {
        return $this->client('eval');
    }

    public function testAColdCacheCostsOneNoscriptRoundTripThenEvalAndAWarmCacheCostsNone(): void
    {
        $raw = $this->raw();
        $client = $this->client('evalsha');
        $script = "return redis.call('INCR', KEYS[1])";

        self::assertSame(1, $client->execute(['EVAL', $script, 1, 'k']));
        self::assertSame([1, 1, 1, 2], [$client->evalshaCalls, $client->noscriptMisses, $client->fallbackEvals, $client->commands], 'Cold: EVALSHA, NOSCRIPT, EVAL.');

        $client->resetCounters();
        self::assertSame(2, $client->execute(['EVAL', $script, 1, 'k']));
        self::assertSame([1, 0, 0, 1], [$client->evalshaCalls, $client->noscriptMisses, $client->fallbackEvals, $client->commands], 'Warm: one EVALSHA.');
        self::assertSame('2', $raw->execute(['GET', 'k']), 'Each call took effect exactly once.');
    }

    public function testTheHashIsOfTheExactTextAndHeldDataTravelsInArgvNotInTheScript(): void
    {
        $client = $this->client('evalsha');
        $script = "return ARGV[1]";
        self::assertSame('alpha', $client->execute(['EVAL', $script, 0, 'alpha']));
        $client->resetCounters();
        self::assertSame('beta', $client->execute(['EVAL', $script, 0, 'beta']));
        self::assertSame(0, $client->noscriptMisses, 'Different data, same script text, same hash: no new script.');
        self::assertSame(1, $this->raw()->execute(['SCRIPT', 'EXISTS', sha1($script)]) === [1] ? 1 : 0);
    }

    public function testAnotherErrorReplyIsRethrownWithoutAFallbackAndTheScriptRanOnce(): void
    {
        $raw = $this->raw();
        $client = $this->client('evalsha');
        $script = "redis.call('INCR', KEYS[1]) return redis.error_reply('ERR deliberate')";
        $raw->execute(['SCRIPT', 'LOAD', $script]);

        try {
            $client->execute(['EVAL', $script, 1, 'effects']);
            self::fail('The error reply must reach the caller.');
        } catch (RateLimiterException $error) {
            self::assertStringContainsString('deliberate', $error->getMessage());
        }

        self::assertSame(0, $client->noscriptMisses);
        self::assertSame(0, $client->fallbackEvals);
        self::assertSame('1', $raw->execute(['GET', 'effects']), 'The script that answered with an error ran once and was not replayed.');
    }

    public function testAnErrorCodeThatOnlyStartsLikeNoscriptIsNotACacheMissAndIsNeverReplayed(): void
    {
        $raw = $this->raw();
        $client = $this->client('evalsha');
        $script = "redis.call('INCR', KEYS[1]) return redis.error_reply('NOSCRIPTISH deliberate')";
        $raw->execute(['SCRIPT', 'LOAD', $script]);

        try {
            $client->execute(['EVAL', $script, 1, 'lookalike']);
            self::fail('The error reply must reach the caller.');
        } catch (RateLimiterException $error) {
            self::assertStringContainsString('NOSCRIPTISH deliberate', $error->getMessage());
        }

        self::assertSame(0, $client->noscriptMisses, 'A different error code is not a cache miss.');
        self::assertSame(0, $client->fallbackEvals);
        self::assertSame('1', $raw->execute(['GET', 'lookalike']), 'The loaded script ran once and was not replayed.');
    }

    /** @return array<string, array{string, string}> */
    public static function lostTransport(): array
    {
        return [
            'the request is lost before the server sees it' => ['--drop-request-on-script=1', '0'],
            'the reply is lost after the server executed it' => ['--drop-reply-on-script=1', '1'],
        ];
    }

    #[DataProvider('lostTransport')]
    public function testALostRequestOrReplyIsNeverReplayed(string $fault, string $expectedEffects): void
    {
        $raw = $this->raw();
        $script = "return redis.call('INCR', KEYS[1])";
        $raw->execute(['SCRIPT', 'LOAD', $script]);
        [$process, $proxyPort] = $this->proxy($fault);
        try {
            // The proxy listens on loopback of the machine that runs the tests, whatever host the server has.
            $client = new RespRedisCommandExecutor('127.0.0.1', $proxyPort, 'evalsha');
            try {
                $client->execute(['EVAL', $script, 1, 'lost']);
                self::fail('The transport failure must reach the caller.');
            } catch (BackendFailureException) {
                // classified as an unknown outcome, as for EVAL
            }
            self::assertSame(1, $client->evalshaCalls);
            self::assertSame(0, $client->fallbackEvals, 'An unknown transport result is not a NOSCRIPT miss.');
            self::assertSame(1, $client->commands, 'Nothing was sent again.');
        } finally {
            $this->stop($process);
        }

        $stored = $raw->execute(['GET', 'lost']);
        self::assertSame($expectedEffects === '0' ? null : '1', $stored);
        $stats = $raw->execute(['INFO', 'commandstats']);
        self::assertIsString($stats);
        self::assertSame($expectedEffects === '0' ? 0 : 1, $this->calls($stats, 'evalsha') - 0, 'The server ran the script at most once.');
    }

    private function calls(string $stats, string $command): int
    {
        return preg_match('/cmdstat_' . $command . ':calls=(\d+)/', $stats, $m) === 1 ? (int) $m[1] : 0;
    }

    /** @return array{resource, int} */
    private function proxy(string $fault): array
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $name = stream_socket_get_name($probe, false);
        self::assertIsString($name);
        $listen = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($probe);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/investigations/evalsha-210/delay-proxy.php', (string) $listen, $this->host, (string) $this->port, '0', $fault],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        for ($i = 0; $i < 100; $i++) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $listen, $e1, $e2, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return [$process, $listen];
            }
            usleep(50_000);
        }
        self::fail('The proxy did not start.');
    }

    /** @param resource $process */
    private function stop($process): void
    {
        proc_terminate($process);
        proc_close($process);
    }

    /** @return array<string, array{string}> */
    public static function modes(): array
    {
        return [
            'evalsha, warm' => [RespRedisCommandExecutor::MODE_EVALSHA],
            'evalsha, cache flushed before every script call' => [RespRedisCommandExecutor::MODE_EVALSHA_FLUSH_EACH],
            'evalsha, cache flushed before every commit' => [RespRedisCommandExecutor::MODE_EVALSHA_FLUSH_COMMIT],
        ];
    }

    /**
     * A public request mix, run twice from an empty Redis, once with EVAL and once in the mode: the decisions are equal and
     * the final state is equal (contents exactly, expiries within the five seconds the two runs may differ by).
     */
    #[DataProvider('modes')]
    public function testThePublicWorkloadEndsInTheSameStateWithTheSameDecisionsAsEval(string $mode): void
    {
        $baseline = $this->workload(RespRedisCommandExecutor::MODE_EVAL);
        $this->raw()->execute(['FLUSHALL']);
        $experiment = $this->workload($mode);

        self::assertSame($baseline['decisions'], $experiment['decisions']);
        self::assertSame(array_keys($baseline['state']), array_keys($experiment['state']), 'The same keys exist.');
        foreach ($baseline['state'] as $key => [$type, $content, $expiry]) {
            self::assertSame($type, $experiment['state'][$key][0], $key);
            self::assertSame($content, $experiment['state'][$key][1], 'Same content: ' . $key);
            self::assertEqualsWithDelta($expiry, $experiment['state'][$key][2], 5000.0, 'Same expiry: ' . $key);
        }
    }

    /** @return array{decisions: list<string>, state: array<string, array{string, mixed, float}>} */
    private function workload(string $mode): array
    {
        $executor = $this->client($mode);
        $signals = new RecordingFailureSignalEmitter();
        $limiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('test_secret', 'test_fp', 'prod'),
            new RedisFullCapabilityStore($executor, 'evalsha-study'),
            $signals,
        )->withClock(new FixedClock('@' . self::NOW))->build();
        $admission = PublicRedisAdmissionFixture::build($executor, 'evalsha-study-admission', self::NOW);
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $admission);
        $admission->openAdmissionFence('login');
        $decisions = [];
        $context = static fn(string $account, string $device = 'device-a'): RateLimitContextDTO => new RateLimitContextDTO('192.0.2.1', 'ua', $account, null, $device, false);
        for ($i = 0; $i < 4; $i++) {
            foreach ([RateLimitCommand::checkOnly('login_protection'), RateLimitCommand::recordFailure('login_protection'), RateLimitCommand::checkOnly('login_protection')] as $command) {
                $decisions[] = $limiter->limit($context('acct-' . $i), $command)->decision;
            }
        }
        for ($i = 0; $i < 3; $i++) {
            $check = $admission->limit($context('adm-' . $i), RateLimitCommand::checkOnly('login_protection'));
            $decisions[] = $check->decision;
            $permit = $check->metadata?->permit;
            self::assertNotNull($permit, 'The reservation issued a permit.');
            $decisions[] = $admission->limit($context('adm-' . $i), new RateLimitCommand('login_protection', 1, false, true, false, $permit))->decision;
        }
        for ($i = 0; $i < 6; $i++) {
            $decisions[] = $limiter->limit($context('hot'), RateLimitCommand::recordFailure('login_protection'))->decision;
        }

        return ['decisions' => $decisions, 'state' => $this->state()];
    }

    /** @return array<string, array{string, mixed, float}> */
    private function state(): array
    {
        $raw = $this->raw();
        $keys = $raw->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        sort($keys);
        $state = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $type = $raw->execute(['TYPE', $key]);
            self::assertIsString($type);
            $content = match ($type) {
                'string' => $raw->execute(['GET', $key]),
                'hash' => $this->flat($raw->execute(['HGETALL', $key])),
                'set' => $this->listSorted($raw->execute(['SMEMBERS', $key])),
                'zset' => $this->zset($key, $raw->execute(['ZRANGE', $key, 0, -1, 'WITHSCORES'])),
                default => self::fail('Unexpected key type ' . $type),
            };
            $expiry = $raw->execute(['PEXPIRETIME', $key]);
            self::assertTrue(is_int($expiry) || is_string($expiry));
            // A permit id is random, so its key differs between two runs: it is identified by its content instead.
            $name = (str_contains($key, ':adm:a1:permit:') || str_contains($key, ':adm:permit:')) ? 'permit:' . sha1(serialize($content)) : $key;
            $state[$name] = [$type, $content, (float) $expiry];
        }

        ksort($state);

        return $state;
    }

    /**
     * A sorted set of the admission scopes has the random permit id as its member: those are compared by score (and count).
     *
     * @return list<string>
     */
    private function zset(string $key, mixed $flat): array
    {
        self::assertIsArray($flat);
        $strings = array_values(array_map(static fn(mixed $v): string => is_string($v) ? $v : '', $flat));
        if (! str_contains($key, ':adm:')) {
            return $strings;
        }
        $scores = [];
        for ($i = 1; $i < count($strings); $i += 2) {
            $scores[] = $strings[$i];
        }
        sort($scores);

        return $scores;
    }

    /** @return array<string, string> */
    private function flat(mixed $flat): array
    {
        self::assertIsArray($flat);
        $map = [];
        for ($i = 0; $i + 1 < count($flat); $i += 2) {
            self::assertIsString($flat[$i]);
            self::assertIsString($flat[$i + 1]);
            $map[$flat[$i]] = $flat[$i + 1];
        }
        ksort($map);

        return $map;
    }

    /** @return list<string> */
    private function listSorted(mixed $values): array
    {
        self::assertIsArray($values);
        $strings = array_map(static fn(mixed $v): string => is_string($v) ? $v : '', $values);
        sort($strings);

        return $strings;
    }

    /** @return array<string, array{string}> */
    public static function scriptModes(): array
    {
        return array_map(static fn(array $row): array => $row, self::modes() + ['eval' => [RespRedisCommandExecutor::MODE_EVAL]]);
    }

    #[DataProvider('scriptModes')]
    public function testAnExactExpiryAndAOneMillisecondDifferenceSurviveTheScriptPath(string $mode): void
    {
        $client = $this->client($mode);
        $script = "redis.call('SET', KEYS[1], 'v') redis.call('PEXPIREAT', KEYS[1], ARGV[1]) return redis.call('PEXPIRETIME', KEYS[1])";
        $first = $client->execute(['EVAL', $script, 1, 'expiring-a', '4102444800123']);
        $second = $client->execute(['EVAL', $script, 1, 'expiring-b', '4102444800124']);

        self::assertSame(PHP_INT_SIZE === 8 ? 4102444800123 : '4102444800123', $first);
        self::assertSame(PHP_INT_SIZE === 8 ? 4102444800124 : '4102444800124', $second);
        self::assertSame($first, $this->raw()->execute(['PEXPIRETIME', 'expiring-a']), 'Read from outside Lua, the same exact value.');
        self::assertNotSame($first, $second);
    }

    public function testANativeClientReturnsFalseForNoscriptAndForAnErrReplyAndOnlyAnUnknownPrefixThrows(): void
    {
        if (! class_exists(\Redis::class)) {
            self::markTestSkipped('ext-redis is not loaded (the 32-bit image has none).');
        }
        $redis = new \Redis();
        $redis->connect($this->host, $this->port);
        $redis->clearLastError();

        // Shapes observed with ext-redis rawCommand() (the version is recorded in the study): an error reply is not an exception.
        self::assertFalse($redis->rawCommand('EVALSHA', sha1('return 1 -- never loaded'), '0'), 'NOSCRIPT: false.');
        self::assertStringStartsWith('NOSCRIPT', (string) $redis->getLastError(), 'The error text is available only through getLastError().');
        $redis->clearLastError();

        self::assertFalse($redis->rawCommand('EVAL', "return redis.error_reply('ERR deliberate')", '0'), 'An ERR reply: also false, indistinguishable from NOSCRIPT by the return value.');
        self::assertSame('ERR deliberate', $redis->getLastError());
        $redis->clearLastError();

        try {
            $redis->rawCommand('EVAL', "return redis.error_reply('MY err')", '0');
            self::fail('A reply with a custom error prefix throws.');
        } catch (\RedisException $error) {
            self::assertStringContainsString('MY err', $error->getMessage());
        }
    }
}
