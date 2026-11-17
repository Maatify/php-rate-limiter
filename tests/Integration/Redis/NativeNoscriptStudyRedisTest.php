<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\AdmissionFenceControlInterface;
use Maatify\RateLimiter\Service\RateLimiterInterface;
use Maatify\RateLimiter\Tests\Support\Admission\PublicRedisAdmissionFixture;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\OverlayWire;
use Maatify\RateLimiter\Tests\Support\Redis\PhpRedisScriptExecutor;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * EVALSHA study, second round (#210, `investigations/evalsha-210/noscript/`): can a Host adapter over a NATIVE client tell a script-cache
 * miss from every other reply, inside the executor contract as it stands (DEC-018)?
 *
 * The client is ext-redis `rawCommand()` and nothing else (the version is printed by `shape-probe.php` and recorded in `RESULTS.md`);
 * the adapter is `PhpRedisScriptExecutor` (test support). Every shape asserted here is the observed behaviour of the installed
 * ext-redis; it is not a claim about another version, Predis or any other client. Single node, isolated Redis.
 *
 * Group A the reply shapes; B the classification (cold cache, `SCRIPT FLUSH`, an ordinary error, `NOSCRIPTISH`, a nil reply, a stale
 * error text); C a lost request and a lost reply; D the request overlay's all-or-none save, rejection and expiry through the adapter.
 */
#[Group('integration')]
#[Group('evalsha-study')]
#[Group('native-noscript')]
final class NativeNoscriptStudyRedisTest extends TestCase
{
    private const NOW = 1_793_793_600;

    private const NAMESPACE = 'noscript-study';

    private string $host;

    private int $port;

    private RespRedisCommandExecutor $raw;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        if (! extension_loaded('redis') || ! class_exists(\Redis::class)) {
            self::markTestSkipped('ext-redis is not loaded (the 32-bit image has none).');
        }
        $this->host = $host;
        $this->port = (int) $port;
        $this->raw = new RespRedisCommandExecutor($host, $this->port, RespRedisCommandExecutor::MODE_EVAL);
        $this->raw->execute(['FLUSHALL']);
        $this->raw->execute(['SCRIPT', 'FLUSH']);
        $this->raw->execute(['CONFIG', 'RESETSTAT']);
    }

    private function redis(?string $host = null, ?int $port = null): \Redis
    {
        $redis = new \Redis();
        self::assertTrue($redis->connect($host ?? $this->host, $port ?? $this->port, 5.0));

        return $redis;
    }

    private function adapter(bool $evalsha = true, bool $clears = true, bool $confirms = false): PhpRedisScriptExecutor
    {
        return new PhpRedisScriptExecutor($this->redis(), $evalsha, $clears, $confirms);
    }

    /**
     * A raw call that returns: the value and the last error text (`getLastError()`), read right after it. An exception fails the test.
     *
     * @return array{mixed, string|null}
     */
    private function observe(\Redis $redis, string ...$command): array
    {
        return [$redis->rawCommand(...$command), $redis->getLastError()];
    }

    /** A raw call that must throw: the exception. */
    private function observeThrown(\Redis $redis, string ...$command): \Throwable
    {
        try {
            $redis->rawCommand(...$command);
        } catch (\Throwable $error) {
            return $error;
        }
        self::fail('The call returned; an exception was expected.');
    }

    // ---- A. the reply shapes of ext-redis rawCommand() -------------------------------------------------------------------------

    public function testAnErrorReplyOfAKnownCodeIsFalseWithTheTextInGetLastErrorAndANilReplyIsFalseWithNone(): void
    {
        $redis = $this->redis();
        $redis->clearLastError();

        self::assertSame([false, null], $this->observe($redis, 'GET', 'absent-key'), 'A nil reply: false, no error text.');

        [$value, $text] = $this->observe($redis, 'EVALSHA', str_repeat('0', 40), '0');
        self::assertFalse($value, 'A cache miss is not an exception.');
        self::assertIsString($text);
        self::assertStringStartsWith('NOSCRIPT No matching script', $text);

        $redis->clearLastError();
        self::assertTrue($redis->rawCommand('SET', 'a-string', '1'));
        [$value, $text] = $this->observe($redis, 'EVAL', "return redis.call('LPUSH', KEYS[1], 'x')", '1', 'a-string');
        self::assertFalse($value, 'An ordinary Redis error (WRONGTYPE): also false.');
        self::assertIsString($text);
        self::assertStringStartsWith('WRONGTYPE', $text);
    }

    public function testTheErrorTextIsStickyAndOnlyAClearBeforeTheCommandMakesFalseUnambiguous(): void
    {
        $redis = $this->redis();
        $redis->clearLastError();
        $this->observe($redis, 'EVALSHA', str_repeat('1', 40), '0');

        [$value, $text] = $this->observe($redis, 'GET', 'absent-key');
        self::assertFalse($value);
        self::assertIsString($text, 'A later nil reply still carries the text of the earlier failed command.');
        self::assertStringStartsWith('NOSCRIPT', $text);

        [, $text] = $this->observe($redis, 'SET', 'k', 'v');
        self::assertIsString($text, 'So does a later success.');

        $redis->clearLastError();
        self::assertSame([false, null], $this->observe($redis, 'GET', 'absent-key'), 'After clearLastError() the same nil reply has no text.');
    }

    public function testAnErrorReplyOfAnUnknownCodeIsAnExceptionAndAClosedHandleReconnectsBeforeTheWrite(): void
    {
        $redis = $this->redis();
        $error = $this->observeThrown($redis, 'EVAL', "return redis.error_reply('MY custom error')", '0');
        self::assertInstanceOf(\RedisException::class, $error);
        self::assertStringContainsString('MY custom error', $error->getMessage());

        // A handle that was closed (or whose connection the server killed) is reconnected by ext-redis BEFORE the command is written: the
        // command then runs once and its reply comes back as usual, with no exception and no error text.
        $redis->close();
        $redis->clearLastError();
        self::assertSame(1, $redis->rawCommand('INCR', 'after-close'), 'Reconnected, executed once.');
        self::assertNull($redis->getLastError());
        self::assertSame('1', $this->raw->execute(['GET', 'after-close']));
    }

    public function testALegitimateReplyThatIsFalseIsANilNotAnError(): void
    {
        $redis = $this->redis();
        $redis->clearLastError();
        self::assertSame([false, null], $this->observe($redis, 'EVAL', 'return false', '0'), 'A script that returns false is a nil reply: false and no error text.');
        self::assertSame([false, null], $this->observe($redis, 'EVAL', "return redis.call('GET', KEYS[1])", '1', 'absent-key'));
    }

    // ---- B. the classification inside the adapter -------------------------------------------------------------------------------

    public function testAColdCacheCostsOneNoscriptThenOneEvalAndTheEffectHappensOnce(): void
    {
        $adapter = $this->adapter();
        $script = "return redis.call('INCR', KEYS[1])";

        self::assertSame(1, $adapter->execute(['EVAL', $script, 1, 'k']));
        self::assertSame([1, 1, 1, 2], [$adapter->evalshaCalls, $adapter->noscriptMisses, $adapter->fallbackEvals, $adapter->commands], 'EVALSHA, NOSCRIPT, EVAL: one fallback.');

        self::assertSame(2, $adapter->execute(['EVAL', $script, 1, 'k']));
        self::assertSame([2, 1, 1, 3], [$adapter->evalshaCalls, $adapter->noscriptMisses, $adapter->fallbackEvals, $adapter->commands], 'Warm: one command, no new miss.');
        self::assertSame('2', $this->raw->execute(['GET', 'k']), 'Each call took effect exactly once.');
    }

    public function testAScriptFlushBetweenCallsCostsOneFallbackPerFlushAndNoEffectIsRepeated(): void
    {
        $adapter = $this->adapter();
        $script = "return redis.call('INCR', KEYS[1])";
        $adapter->execute(['EVAL', $script, 1, 'k']);

        for ($i = 2; $i <= 4; $i++) {
            $this->raw->execute(['SCRIPT', 'FLUSH']);
            self::assertSame($i, $adapter->execute(['EVAL', $script, 1, 'k']));
        }
        self::assertSame(4, $adapter->noscriptMisses, 'The first call and each of the three flushes missed once.');
        self::assertSame(4, $adapter->fallbackEvals);
        self::assertSame('4', $this->raw->execute(['GET', 'k']));
    }

    public function testAnOrdinaryRedisErrorIsRethrownUnchangedWithoutAFallbackAndTheScriptRanOnce(): void
    {
        $adapter = $this->adapter();
        $script = "redis.call('INCR', KEYS[1]) return redis.call('LPUSH', KEYS[2], 'x')";
        $this->raw->execute(['SET', 'wrong', 'string']);
        $this->raw->execute(['SCRIPT', 'LOAD', $script]);

        try {
            $adapter->execute(['EVAL', $script, 2, 'effects', 'wrong']);
            self::fail('The error must reach the caller.');
        } catch (\RedisException $error) {
            self::assertStringStartsWith('WRONGTYPE', $error->getMessage(), 'The server text, unchanged.');
        }

        self::assertSame([0, 0], [$adapter->noscriptMisses, $adapter->fallbackEvals]);
        self::assertSame(1, $adapter->commands, 'Nothing was sent again.');
        self::assertSame('1', $this->raw->execute(['GET', 'effects']));
    }

    public function testAnErrorCodeThatOnlyStartsLikeNoscriptIsNotACacheMiss(): void
    {
        $adapter = $this->adapter();
        $script = "redis.call('INCR', KEYS[1]) return redis.error_reply('NOSCRIPTISH deliberate')";
        $this->raw->execute(['SCRIPT', 'LOAD', $script]);

        try {
            $adapter->execute(['EVAL', $script, 1, 'lookalike']);
            self::fail('The error must reach the caller.');
        } catch (\RedisException $error) {
            self::assertSame('NOSCRIPTISH deliberate', $error->getMessage());
        }

        self::assertSame([0, 0, 1], [$adapter->noscriptMisses, $adapter->fallbackEvals, $adapter->commands]);
        self::assertSame('1', $this->raw->execute(['GET', 'lookalike']));
    }

    public function testALegitimateNilReplyIsReturnedAndIsNotTakenForAMissAfterAnEarlierFailedCommand(): void
    {
        $adapter = $this->adapter();
        $script = "redis.call('INCR', KEYS[1]) return false";
        $this->raw->execute(['SCRIPT', 'LOAD', $script]);
        try {
            $adapter->execute(['EVALSHA', str_repeat('2', 40), 0]);
            self::fail('A command that fails.');
        } catch (\RedisException) {
        }

        self::assertFalse($adapter->execute(['EVAL', $script, 1, 'nil']), 'The nil reply is returned as the native false.');
        self::assertSame(0, $adapter->noscriptMisses, 'It was not mistaken for a miss.');
        self::assertSame('1', $this->raw->execute(['GET', 'nil']), 'It ran once and was not replayed.');
    }

    /** The NEGATIVE CONTROL: an adapter that does not clear the sticky text takes a later legitimate nil reply for an error. */
    public function testWithoutClearingTheStickyTextALegitimateNilReplyIsMisreadAsAnError(): void
    {
        $adapter = $this->adapter(true, false);
        $script = "redis.call('INCR', KEYS[1]) return false";
        $this->raw->execute(['SCRIPT', 'LOAD', $script]);
        try {
            $adapter->execute(['EVALSHA', str_repeat('3', 40), 0]);
            self::fail('A command that fails.');
        } catch (\RedisException) {
        }

        try {
            $adapter->execute(['EVAL', $script, 1, 'nil']);
            self::fail('The stale text makes this look like an error.');
        } catch (\RedisException $error) {
            self::assertStringStartsWith('NOSCRIPT', $error->getMessage(), 'The stale NOSCRIPT text of the earlier command.');
        }
        self::assertSame(1, $adapter->noscriptMisses, 'And a stale NOSCRIPT is counted as a miss: the script would be replayed.');
        self::assertSame('2', $this->raw->execute(['GET', 'nil']), 'Two effects: the script ran, was taken for a miss and ran again.');
    }

    /**
     * A script that itself answers with an error whose code is NOSCRIPT is byte-identical on the wire to a cache miss. The classification
     * by the whole error code cannot tell them apart; no script of the package answers with that code (see the next test).
     */
    public function testALookalikeNoscriptFromAScriptThatRanIsReplayedByTheCodeOnlyClassification(): void
    {
        $adapter = $this->adapter();
        $script = "redis.call('INCR', KEYS[1]) return redis.error_reply('NOSCRIPT No matching script. Please use EVAL.')";
        $this->raw->execute(['SCRIPT', 'LOAD', $script]);

        self::assertSame(2, $this->tryExecute($adapter, $script, 'fake-miss'), 'The effect happened twice.');
        self::assertSame(1, $adapter->fallbackEvals);
    }

    public function testNoScriptOfThePackageAnswersWithTheCodeNoscript(): void
    {
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'NOSCRIPT')) {
                $offenders[] = $file->getPathname();
            }
        }
        self::assertSame([], $offenders, 'A package script that answered with a NOSCRIPT error would be replayed by a code-only classification.');
    }

    public function testTheConfirmingVariantReplaysNothingForTheLookalike(): void
    {
        $adapter = $this->adapter(true, true, true);
        $script = "redis.call('INCR', KEYS[1]) return redis.error_reply('NOSCRIPT No matching script. Please use EVAL.')";
        $this->raw->execute(['SCRIPT', 'LOAD', $script]);

        self::assertSame(1, $this->tryExecute($adapter, $script, 'fake-miss'), 'SCRIPT EXISTS finds the script: not a miss, one effect.');
        self::assertSame(0, $adapter->fallbackEvals);
    }

    /**
     * The confirming variant is not adopted: two clients start cold together, the first one's fallback `EVAL` loads the script, and the
     * second one's `SCRIPT EXISTS` then finds it and raises an error for a call that never ran (deterministic here: the other client is
     * a hook between the miss and the confirmation).
     */
    public function testTheConfirmingVariantFailsACallThatNeverRanWhenAnotherClientLoadsTheScriptFirst(): void
    {
        $script = "return redis.call('INCR', KEYS[1])";
        $confirming = $this->adapter(true, true, true);
        $confirming->betweenMissAndConfirmation = function () use ($script): void {
            $this->raw->execute(['SCRIPT', 'LOAD', $script]);
        };
        try {
            $confirming->execute(['EVAL', $script, 1, 'race']);
            self::fail('The confirming variant raises.');
        } catch (\RedisException $error) {
            self::assertStringStartsWith('NOSCRIPT', $error->getMessage());
        }
        self::assertNull($this->raw->execute(['GET', 'race']), 'The call never ran: a spurious failure of a harmless miss.');

        $this->raw->execute(['SCRIPT', 'FLUSH']);
        $codeOnly = $this->adapter();
        $this->raw->execute(['SCRIPT', 'LOAD', $script]);
        self::assertSame(1, $codeOnly->execute(['EVAL', $script, 1, 'race']), 'The code-only adapter simply runs the script that is there.');
    }

    private function tryExecute(PhpRedisScriptExecutor $adapter, string $script, string $key): int
    {
        try {
            $adapter->execute(['EVAL', $script, 1, $key]);
            self::fail('The script answers with an error.');
        } catch (\RedisException) {
        }
        $effects = $this->raw->execute(['GET', $key]);
        self::assertIsString($effects);

        return (int) $effects;
    }

    // ---- C. a lost request and a lost reply ------------------------------------------------------------------------------------

    /** @return array<string, array{string, int, int}> */
    public static function lostTransport(): array
    {
        return [
            'the request is lost before the server sees it' => ['--drop-request-on-script=1', 0, 0],
            'the reply is lost after the server ran the script' => ['--drop-reply-on-script=1', 1, 1],
        ];
    }

    #[DataProvider('lostTransport')]
    public function testALostRequestOrReplyIsNeverReplayedByTheAdapterOrByExtRedis(string $fault, int $effects, int $serverEvalshaCalls): void
    {
        $script = "return redis.call('INCR', KEYS[1])";
        $this->raw->execute(['SCRIPT', 'LOAD', $script]);
        [$process, $proxyPort] = $this->proxy($fault);
        try {
            $adapter = new PhpRedisScriptExecutor($this->redis('127.0.0.1', $proxyPort), true);
            try {
                $adapter->execute(['EVAL', $script, 1, 'lost']);
                self::fail('The transport failure must reach the caller.');
            } catch (\RedisException $error) {
                self::assertNotSame('', $error->getMessage());
            }
            self::assertSame([1, 0, 1], [$adapter->evalshaCalls, $adapter->fallbackEvals, $adapter->commands], 'One command was written by the adapter; no fallback.');
        } finally {
            $this->stop($process);
        }

        $stored = $this->raw->execute(['GET', 'lost']);
        self::assertSame($effects === 0 ? null : (string) $effects, $stored);
        $stats = $this->raw->execute(['INFO', 'commandstats']);
        self::assertIsString($stats);
        self::assertSame($serverEvalshaCalls, preg_match('/cmdstat_evalsha:calls=(\d+)/', $stats, $m) === 1 ? (int) $m[1] : 0, 'The server received the command at most once: ext-redis did not resend it.');
    }

    // ---- D. DEC-037 through the adapter -----------------------------------------------------------------------------------------

    private function limiter(RedisCommandExecutorInterface $executor): RateLimiterInterface
    {
        return RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('test_secret', 'test_fp', 'prod'),
            new RedisFullCapabilityStore($executor, self::NAMESPACE),
            new RecordingFailureSignalEmitter(),
        )->withClock(new FixedClock('@' . self::NOW))->build();
    }

    private function context(string $account): RateLimitContextDTO
    {
        return new RateLimitContextDTO(ip: '192.0.2.1', ua: 'ua', accountId: $account);
    }

    /** @return array<string, array{string, mixed, float}> type, canonical content and exact expiry of every key */
    private function state(): array
    {
        $keys = $this->raw->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        sort($keys);
        $state = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $type = $this->raw->execute(['TYPE', $key]);
            self::assertIsString($type);
            $content = match ($type) {
                'string' => $this->raw->execute(['GET', $key]),
                'hash' => $this->flat($this->raw->execute(['HGETALL', $key])),
                'set' => $this->sorted($this->raw->execute(['SMEMBERS', $key])),
                'zset' => $this->zset($key, $this->raw->execute(['ZRANGE', $key, 0, -1, 'WITHSCORES'])),
                default => self::fail('Unexpected key type ' . $type),
            };
            $expiry = $this->raw->execute(['PEXPIRETIME', $key]);
            self::assertTrue(is_int($expiry) || is_string($expiry));
            $name = str_contains($key, ':adm:permit:') ? 'permit:' . sha1(serialize($content)) : $key;
            $state[$name] = [$type, $content, (float) $expiry];
        }
        ksort($state);

        return $state;
    }

    /** @return list<string> */
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
    private function sorted(mixed $values): array
    {
        self::assertIsArray($values);
        $strings = array_map(static fn(mixed $v): string => is_string($v) ? $v : '', $values);
        sort($strings);

        return $strings;
    }

    /**
     * @param array<string, array{string, mixed, float}> $expected
     * @param array<string, array{string, mixed, float}> $actual
     */
    private function assertSameState(array $expected, array $actual, string $why): void
    {
        self::assertSame(array_keys($expected), array_keys($actual), $why . ': the same keys exist.');
        foreach ($expected as $key => [$type, $content, $expiry]) {
            self::assertSame($type, $actual[$key][0], $key);
            self::assertSame($content, $actual[$key][1], $why . ': ' . $key);
            self::assertEqualsWithDelta($expiry, $actual[$key][2], 5000.0, 'Expiry of ' . $key);
        }
    }

    /** @param list<int|string|float> $command */
    private static function isOverlayEval(array $command): bool
    {
        return $command[0] === 'EVAL' && is_string($command[1]) && str_contains($command[1], '__ov_result');
    }

    public function testARequestIsSavedByOneCommitThroughTheAdapterAndAColdCommitScriptCostsOneFallbackNotASecondSave(): void
    {
        $failure = RateLimitCommand::recordFailure('login_protection');
        $this->limiter($this->raw)->limit($this->context('account-one'), $failure);
        $expected = $this->state();
        $this->raw->execute(['FLUSHALL']);
        $this->raw->execute(['SCRIPT', 'FLUSH']);

        $adapter = $this->adapter();
        $saves = 0;
        $executor = new CallableRedisCommandExecutor(function (array $command) use ($adapter, &$saves): mixed {
            if (OverlayWire::isCommit($command)) {
                $saves++;
            }

            return $adapter->execute($command);
        });
        $result = $this->limiter($executor)->limit($this->context('account-one'), $failure);

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertSame(1, $saves, 'One save was sent.');
        self::assertGreaterThan(0, $adapter->noscriptMisses, 'The cache was cold: every script missed once.');
        self::assertSame($adapter->noscriptMisses, $adapter->fallbackEvals);
        $this->assertSameState($expected, $this->state(), 'The same final state as the EVAL run, written once');
    }

    public function testTheCacheLostJustBeforeTheCommitDoesNotRepeatOrLoseTheSave(): void
    {
        $failure = RateLimitCommand::recordFailure('login_protection');
        $this->limiter($this->raw)->limit($this->context('account-one'), $failure);
        $expected = $this->state();
        $this->raw->execute(['FLUSHALL']);

        $adapter = $this->adapter();
        $warm = $this->limiter($adapter);
        $warm->limit($this->context('warm-up'), RateLimitCommand::checkOnly('login_protection'));
        $this->raw->execute(['FLUSHALL']);
        $commits = 0;
        $executor = new CallableRedisCommandExecutor(function (array $command) use ($adapter, &$commits): mixed {
            if (OverlayWire::isCommit($command)) {
                $commits++;
                $this->raw->execute(['SCRIPT', 'FLUSH']);
            }

            return $adapter->execute($command);
        });
        $adapter->noscriptMisses = $adapter->fallbackEvals = 0;
        $result = $this->limiter($executor)->limit($this->context('account-one'), $failure);

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertSame(1, $commits);
        self::assertStringEndsWith('[commit]', $adapter->missedScripts[0] ?? '', 'The commit was the first call to find the cache empty.');
        self::assertSame($adapter->noscriptMisses, $adapter->fallbackEvals, 'Each miss, the commit and a script called after it, was answered by exactly one fallback EVAL.');
        self::assertCount(1, array_filter($adapter->missedScripts, static fn(string $m): bool => str_ends_with($m, '[commit]')), 'The commit missed once.');
        $this->assertSameState($expected, $this->state(), 'Saved once, not lost and not applied twice');
    }

    public function testAClassifiedRejectionLeavesRedisUntouchedAndNeverCallsTheSaveThroughTheAdapter(): void
    {
        $command = RateLimitCommand::checkOnly('login_protection');
        $adapter = $this->adapter();
        $this->limiter($adapter)->limit($this->context('account-one'), $command);
        $before = $this->state();
        self::assertNotEmpty($before);

        $probe = new class {
            public int $prepared = 0;
            public int $saves = 0;
        };
        $rejection = new RateLimiterException('malformed bounded metadata');
        $executor = new CallableRedisCommandExecutor(function (array $c) use ($probe, $rejection, $adapter): mixed {
            if (OverlayWire::isCommit($c)) {
                $probe->saves++;
            }
            if (self::isOverlayEval($c) && array_filter(OverlayWire::keys($c), static fn(string $k): bool => str_contains($k, ':distinct-meta:')) !== []) {
                if ($probe->prepared === 2) {
                    throw $rejection;
                }
                $probe->prepared++;
            }

            return $adapter->execute($c);
        });

        try {
            $this->limiter($executor)->limit($this->context('account-two'), $command);
            self::fail('The classified rejection must reach the caller.');
        } catch (RateLimiterException $caught) {
            self::assertSame($rejection, $caught);
        }

        self::assertSame(2, $probe->prepared);
        self::assertSame(0, $probe->saves, 'The save was never called.');
        self::assertSame($before, $this->state(), 'Keys, values and expiry are unchanged.');
    }

    public function testAStalePlanIsRecalculatedAndSavedOnceThroughTheAdapterWithTheCacheLostBeforeEachCommit(): void
    {
        $failure = RateLimitCommand::recordFailure('login_protection');
        $this->limiter($this->raw)->limit($this->context('account-two'), $failure);
        $this->limiter($this->raw)->limit($this->context('account-one'), $failure);
        $expected = $this->state();
        $this->raw->execute(['FLUSHALL']);

        $adapter = $this->adapter();
        $competed = false;
        $saves = [];
        $other = null;
        $executor = new CallableRedisCommandExecutor(function (array $command) use ($adapter, &$competed, &$saves, &$other): mixed {
            if (OverlayWire::isCommit($command)) {
                $this->raw->execute(['SCRIPT', 'FLUSH']);
                if (! $competed) {
                    $competed = true;
                    $other = $this->limiter($this->raw)->limit($this->context('account-two'), RateLimitCommand::recordFailure('login_protection'));
                }
                $reply = $adapter->execute($command);
                $saves[] = $reply;

                return $reply;
            }

            return $adapter->execute($command);
        });
        $result = $this->limiter($executor)->limit($this->context('account-one'), $failure);

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertInstanceOf(RateLimitResultDTO::class, $other);
        self::assertSame([0, 1], $saves, 'The stale plan was rejected and the recalculated one saved, each commit after a cache loss.');
        $this->assertSameState($expected, $this->state(), 'No update lost and none applied twice');
    }

    public function testThePublicWorkloadEndsInTheSameStateWithTheSameDecisionsAsEvalOverTheRespClient(): void
    {
        $baseline = $this->workload($this->raw);
        $this->raw->execute(['FLUSHALL']);
        $this->raw->execute(['SCRIPT', 'FLUSH']);
        $experiment = $this->workload($this->adapter());

        self::assertSame($baseline['decisions'], $experiment['decisions']);
        $this->assertSameState($baseline['state'], $experiment['state'], 'Native EVALSHA against RESP EVAL');
    }

    /** @return array{decisions: list<string>, state: array<string, array{string, mixed, float}>} */
    private function workload(RedisCommandExecutorInterface $executor): array
    {
        $limiter = $this->limiter($executor);
        $admission = PublicRedisAdmissionFixture::build($executor, 'noscript-study-admission', self::NOW);
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $admission);
        $admission->openAdmissionFence('login');
        $decisions = [];
        for ($i = 0; $i < 4; $i++) {
            foreach ([RateLimitCommand::checkOnly('login_protection'), RateLimitCommand::recordFailure('login_protection'), RateLimitCommand::checkOnly('login_protection')] as $command) {
                $decisions[] = $limiter->limit($this->context('acct-' . $i), $command)->decision;
            }
        }
        for ($i = 0; $i < 3; $i++) {
            $check = $admission->limit($this->context('adm-' . $i), RateLimitCommand::checkOnly('login_protection'));
            $decisions[] = $check->decision;
            $permit = $check->metadata?->permit;
            self::assertNotNull($permit);
            $decisions[] = $admission->limit($this->context('adm-' . $i), new RateLimitCommand('login_protection', 1, false, true, false, $permit))->decision;
        }
        for ($i = 0; $i < 6; $i++) {
            $decisions[] = $limiter->limit($this->context('hot'), RateLimitCommand::recordFailure('login_protection'))->decision;
        }

        return ['decisions' => $decisions, 'state' => $this->state()];
    }

    public function testAnExactExpiryAndAOneMillisecondDifferenceSurviveTheNativeScriptPath(): void
    {
        $adapter = $this->adapter();
        $script = "redis.call('SET', KEYS[1], 'v') redis.call('PEXPIREAT', KEYS[1], ARGV[1]) return redis.call('PEXPIRETIME', KEYS[1])";
        $first = $adapter->execute(['EVAL', $script, 1, 'expiring-a', '4102444800123']);
        $second = $adapter->execute(['EVAL', $script, 1, 'expiring-b', '4102444800124']);

        self::assertSame(4102444800123, $first);
        self::assertSame(4102444800124, $second);
        self::assertSame($first, $this->raw->execute(['PEXPIRETIME', 'expiring-a']), 'Read from outside Lua, the same exact value.');
        self::assertNotSame($first, $second);
    }

    // ---- the proxy of the lost-transport cases ----------------------------------------------------------------------------------

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
}
