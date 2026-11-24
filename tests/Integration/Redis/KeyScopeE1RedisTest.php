<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Enum\KeyScopeEncodingEnum;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Service\E1AccountScopeKeyDeriver;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\OverlayWire;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * DEC-044 / E1 contract AC-4 to AC-9 on an isolated Redis, through the public builder and the real store: where the account-bearing keys live,
 * that the operational reader follows the writer, that plain strings keep their v1 keys, that the E1 view refuses what is not an E1 key, and that
 * a request touching both layouts is one transaction (one commit per attempt, one ownership, conflict, ACL, interruption, DEC-045). Every
 * observation is read from outside Lua. Synthetic data only.
 */
#[Group('integration')]
#[Group('php32')]
#[Group('key-scope-e1')]
final class KeyScopeE1RedisTest extends TestCase
{
    private const NOW = 1_793_793_600;
    private const NAMESPACE = 'e1-284';
    private const SECRET = 'test_secret';
    private const ENV = 'prod';
    private const POLICY = 'login_protection';
    private const ACCOUNT = 'acct-e1';
    private const PASSWORD = 'synthetic-e1-password';

    private RespRedisCommandExecutor $admin;
    private string $host;
    private int $port;
    /** @var list<string> */
    private array $users = [];

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->host = $host;
        $this->port = (int) $port;
        $this->admin = new RespRedisCommandExecutor($host, $this->port);
        $this->admin->execute(['FLUSHDB']);
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            foreach ($this->users as $user) {
                $this->admin->execute(['ACL', 'DELUSER', $user]);
            }
            $this->admin->execute(['FLUSHDB']);
        }
    }

    private function config(): RateLimiterConfig
    {
        return new RateLimiterConfig(self::SECRET, 'test_fp', self::ENV);
    }

    private function builder(RedisCommandExecutorInterface $executor, KeyScopeEncodingEnum $encoding, ?RedisFullCapabilityStore $store = null): RateLimiterBuilder
    {
        return RateLimiterBuilder::fromFullCapabilityStore(
            $this->config(),
            $store ?? new RedisFullCapabilityStore($executor, self::NAMESPACE),
            new RecordingFailureSignalEmitter(),
        )->withClock(new FixedClock('@' . self::NOW))->withKeyScopeEncoding($encoding);
    }

    private function runtime(RedisCommandExecutorInterface $executor, KeyScopeEncodingEnum $encoding): CompositeRateLimiterRuntimeInterface
    {
        return $this->builder($executor, $encoding)->build();
    }

    private function context(string $account = self::ACCOUNT): RateLimitContextDTO
    {
        return new RateLimitContextDTO(
            ip: '192.0.2.77',
            ua: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            accountId: $account,
        );
    }

    private function failures(CompositeRateLimiterRuntimeInterface $runtime, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $runtime->limit($this->context(), RateLimitCommand::recordFailure(self::POLICY));
        }
    }

    /** @return list<string> */
    private function keys(): array
    {
        $keys = [];
        $cursor = '0';
        do {
            $reply = $this->admin->execute(['SCAN', $cursor, 'COUNT', 1000]);
            self::assertIsArray($reply);
            self::assertIsString($reply[0]);
            $cursor = $reply[0];
            self::assertIsArray($reply[1]);
            foreach ($reply[1] as $key) {
                self::assertIsString($key);
                $keys[] = $key;
            }
        } while ($cursor !== '0');
        sort($keys);

        return $keys;
    }

    /** @return array<string, array{string, string}> every key: byte-exact DUMP (hex) and PEXPIRETIME as text */
    private function snapshot(): array
    {
        $state = [];
        foreach ($this->keys() as $key) {
            if (str_contains($key, ':circuit:')) {
                continue; // the circuit breaker records a backend failure itself; it is not a write of the request
            }
            $dump = $this->admin->execute(['DUMP', $key]);
            self::assertIsString($dump);
            $expiry = $this->admin->execute(['PEXPIRETIME', $key]);
            self::assertTrue(is_int($expiry) || is_string($expiry));
            $state[$key] = [bin2hex($dump), (string) $expiry];
        }

        return $state;
    }

    private static function e1Prefix(): string
    {
        return 'maatify:rate-limiter:e1:' . hash('sha256', self::NAMESPACE) . ':';
    }

    private static function v1Prefix(): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':';
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private static function under(array $keys, string $prefix): array
    {
        return array_values(array_filter($keys, static fn(string $key): bool => str_starts_with($key, $prefix)));
    }

    private function e1ScoreKey(string $purpose = 'k4'): string
    {
        $logical = E1AccountScopeKeyDeriver::key(self::SECRET, [$purpose, self::POLICY, self::ENV, self::ACCOUNT]);

        return self::e1Prefix() . 'score:' . hash('sha256', $logical);
    }

    // ---------------------------------------------------------------------------------------------------------------- separation

    public function testAnE1RuntimeKeepsTheAccountKeysOnlyUnderTheE1PrefixAndEveryOtherKeyWhereV2KeepsIt(): void
    {
        $this->failures($this->runtime($this->admin, KeyScopeEncodingEnum::V2), 3);
        $v2Keys = $this->keys();
        self::assertSame([], self::under($v2Keys, self::e1Prefix()), 'A V2 runtime writes nothing under the E1 prefix.');
        $this->admin->execute(['FLUSHDB']);

        $this->failures($this->runtime($this->admin, KeyScopeEncodingEnum::E1), 3);
        $e1Keys = $this->keys();

        $underE1 = self::under($e1Keys, self::e1Prefix());
        self::assertNotSame([], $underE1, 'The account-bearing state exists under the E1 prefix.');
        self::assertContains($this->e1ScoreKey('k4'), $underE1, 'The K4 score is at the key the contract derives.');
        foreach ($underE1 as $key) {
            $family = explode(':', substr($key, strlen(self::e1Prefix())))[0];
            self::assertContains($family, ['score', 'block', 'budget', 'cycle', 'pause', 'reentry-claim', 'watch', 'watch-meta']);
        }
        // K1, K2 and K3 stay where V2 keeps them: every v1 score key of the E1 run is a v1 score key of the V2 run.
        $v2Scores = self::under($v2Keys, self::v1Prefix() . 'score:');
        $e1V1Scores = self::under($e1Keys, self::v1Prefix() . 'score:');
        self::assertSame([], array_values(array_diff($e1V1Scores, $v2Scores)), 'No v1 score key of the E1 run is new.');
        self::assertSame(count($v2Scores), count($e1V1Scores) + count(self::under($underE1, self::e1Prefix() . 'score:')), 'The account scores moved to E1, nothing else.');
        // Set B (derivative) keys differ by design and stay under v1: the only new v1 keys of the E1 run are watch and distinct hashes of the E1 keys.
        foreach (array_diff($e1Keys, $v2Keys) as $key) {
            if (! str_starts_with($key, self::v1Prefix())) {
                continue;
            }
            $family = explode(':', substr($key, strlen(self::v1Prefix())))[0];
            self::assertContains($family, ['watch', 'watch-meta', 'distinct', 'distinct-meta'], 'Only the documented derivative families differ.');
        }
    }

    public function testTheOperationalReaderFollowsTheWriterAndNeverReadsTheOtherEncoding(): void
    {
        $e1Runtime = $this->runtime($this->admin, KeyScopeEncodingEnum::E1);
        $this->failures($e1Runtime, 2);
        $v2Reader = $this->builder($this->admin, KeyScopeEncodingEnum::V2)->buildOperationalReader();
        $e1Reader = $this->builder($this->admin, KeyScopeEncodingEnum::E1)->buildOperationalReader();

        $e1Snapshot = $e1Reader->readScorePolicy($this->context(), self::POLICY);
        $v2Snapshot = $v2Reader->readScorePolicy($this->context(), self::POLICY);

        self::assertNotNull($e1Snapshot->scopes->k4?->score, 'The E1 reader finds the account state the E1 writer saved.');
        self::assertNull($v2Snapshot->scopes->k4?->score, 'A V2 reader cannot read E1 state.');

        $this->admin->execute(['FLUSHDB']);
        $this->failures($this->runtime($this->admin, KeyScopeEncodingEnum::V2), 2);
        self::assertNotNull($v2Reader->readScorePolicy($this->context(), self::POLICY)->scopes->k4?->score);
        self::assertNull($e1Reader->readScorePolicy($this->context(), self::POLICY)->scopes->k4?->score, 'An E1 reader cannot read V2 state.');
    }

    public function testRotationWritesOnlyToCurrentAndNeverReadsAV2KeyAsPrevious(): void
    {
        $previous = new RateLimiterConfig('previous_secret', 'previous_fp', self::ENV);
        $rotated = new RateLimiterConfig(self::SECRET, 'test_fp', self::ENV, 'previous_secret', 'previous_fp');
        $store = new RedisFullCapabilityStore($this->admin, self::NAMESPACE);
        $make = static fn(RateLimiterConfig $config): CompositeRateLimiterRuntimeInterface => RateLimiterBuilder::fromFullCapabilityStore($config, $store, new RecordingFailureSignalEmitter())
            ->withClock(new FixedClock('@' . self::NOW))->withKeyScopeEncoding(KeyScopeEncodingEnum::E1)->build();

        $make($previous)->limit($this->context(), RateLimitCommand::recordFailure(self::POLICY));
        $before = array_keys($this->snapshot());
        $make($rotated)->limit($this->context(), RateLimitCommand::recordFailure(self::POLICY));
        $after = array_keys($this->snapshot());

        $currentScore = self::e1Prefix() . 'score:' . hash('sha256', E1AccountScopeKeyDeriver::key(self::SECRET, ['k4', self::POLICY, self::ENV, self::ACCOUNT]));
        $previousScore = self::e1Prefix() . 'score:' . hash('sha256', E1AccountScopeKeyDeriver::key('previous_secret', ['k4', self::POLICY, self::ENV, self::ACCOUNT]));
        self::assertContains($previousScore, $before);
        self::assertContains($currentScore, $after, 'After rotation the write goes to Current.');
        self::assertContains($previousScore, $after, 'Previous is kept, read-only.');
        self::assertSame([], self::under($after, self::v1Prefix() . 'score:' . hash('sha256', 'x')), 'No V2 key is derived for a Set A identity by an E1 runtime.');
    }

    // ---------------------------------------------------------------------------------------------------------------- plain strings and the view

    public function testPlainStringsKeepTheirV1KeysWithE1SelectedAndTheE1ViewRefusesWhatIsNotAnE1Key(): void
    {
        $store = new RedisFullCapabilityStore($this->admin, self::NAMESPACE);
        $this->builder($this->admin, KeyScopeEncodingEnum::E1, $store)->build();

        $shaped = 'e1.' . str_repeat('a', 64);
        $plain = [$shaped, str_repeat('b', 64), 'e1.not-hex', 'plain-string'];
        foreach ($plain as $string) {
            $store->incrementAt($string, 60, 1, self::NOW);
            $store->blockAt($string, 1, 60, self::NOW);
            $store->incrementBudgetAt($string, 86400, 1, self::NOW);
            $store->incrementWatchFlagAt($string, 60, self::NOW);
        }
        $keys = $this->keys();
        self::assertSame([], self::under($keys, self::e1Prefix()), 'A plain string never reaches the E1 prefix.');
        foreach ($plain as $string) {
            foreach (['score', 'block', 'budget', 'watch'] as $family) {
                self::assertContains(self::v1Prefix() . $family . ':' . hash('sha256', $string), $keys, "{$family}: {$string}");
            }
        }

        $view = $store->e1Keyspace();
        self::assertSame($view, $store->e1Keyspace(), 'The view is created once.');
        $before = $this->snapshot();
        foreach ([
            static fn() => $view->incrementAt('plain-string', 60, 1, self::NOW),
            static fn() => $view->incrementAt('e1.' . str_repeat('A', 64), 60, 1, self::NOW),
            static fn() => $view->addDistinctAt('e1.' . str_repeat('c', 64), 'm', 60, self::NOW),
        ] as $call) {
            try {
                $call();
                self::fail('The E1 view refuses what is not an E1 key, and a foreign family, before any command.');
            } catch (RateLimiterException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame($before, $this->snapshot());

        $view->incrementAt($shaped, 60, 1, self::NOW);
        self::assertContains(self::e1Prefix() . 'score:' . hash('sha256', $shaped), $this->keys(), 'An E1 key given to the view lands under the E1 prefix.');
    }

    public function testComposingE1OverAStoreWithoutTheCapabilityFailsBeforeAnyCommand(): void
    {
        $builder = new RateLimiterBuilder(
            $this->config(),
            new \Maatify\RateLimiter\Tests\Support\RateLimiter\BaseOnlyInMemoryRateLimitStore(new FixedClock('@' . self::NOW)),
            new \Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore(new FixedClock('@' . self::NOW)),
            new \Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore(),
            new RecordingFailureSignalEmitter(),
        );
        $builder->withKeyScopeEncoding(KeyScopeEncodingEnum::E1);

        foreach ([static fn() => $builder->build(), static fn() => $builder->buildOperationalReader()] as $compose) {
            try {
                $compose();
                self::fail('E1 over a store without the capability must fail at composition.');
            } catch (RateLimiterException $exception) {
                self::assertStringContainsString('E1KeyspaceStoreInterface', $exception->getMessage());
            }
        }
        self::assertSame([], $this->keys(), 'No command reached Redis.');
    }

    // ---------------------------------------------------------------------------------------------------------------- one transaction, two layouts

    /**
     * An executor that records every commit and lets a test act on it.
     *
     * @param list<non-empty-list<float|int|string>> $commits
     * @param (callable(non-empty-list<float|int|string>, int): mixed)|null $onCommit
     */
    private function recording(array &$commits, ?callable $onCommit = null): CallableRedisCommandExecutor
    {
        /** @param non-empty-list<float|int|string> $command */
        return new CallableRedisCommandExecutor(function (array $command) use (&$commits, $onCommit): mixed {
            if (OverlayWire::isCommit($command)) {
                $commits[] = $command;
                if ($onCommit !== null) {
                    return $onCommit($command, count($commits));
                }
            }

            return $this->admin->execute($command);
        });
    }

    public function testARequestThatWritesBothLayoutsIsOneCommitOnOneStoreInstance(): void
    {
        $commits = [];
        $runtime = $this->runtime($this->recording($commits), KeyScopeEncodingEnum::E1);

        $this->failures($runtime);

        self::assertCount(1, $commits, 'One commit per successful attempt, for both layouts.');
        $keys = $this->keys();
        self::assertNotSame([], self::under($keys, self::v1Prefix() . 'score:'), 'K1 to K3 are saved in v1.');
        self::assertContains($this->e1ScoreKey('k4'), $keys, 'K4 is saved in e1, in the same commit.');
        $declared = array_slice($commits[0], 3, (int) $commits[0][2]);
        self::assertNotSame([], array_filter($declared, static fn(mixed $key): bool => str_starts_with((string) $key, self::e1Prefix())));
        self::assertNotSame([], array_filter($declared, static fn(mixed $key): bool => str_starts_with((string) $key, self::v1Prefix())));
    }

    public function testAConflictInEitherLayoutSavesNothingAndTheRequestIsEvaluatedAgain(): void
    {
        foreach (['e1', 'v1'] as $layout) {
            $this->admin->execute(['FLUSHDB']);
            $commits = [];
            $rival = new RedisFullCapabilityStore($this->admin, self::NAMESPACE);
            $snapshots = [];
            $executor = $this->recording($commits, function (array $command, int $attempt) use (&$snapshots, $rival, $layout): mixed {
                if ($attempt === 1) {
                    // A key of the chosen layout changes between the read and the commit.
                    if ($layout === 'e1') {
                        $rival->e1Keyspace()->incrementAt(E1AccountScopeKeyDeriver::key(self::SECRET, ['k4', self::POLICY, self::ENV, self::ACCOUNT]), 86400, 1, self::NOW);
                    } else {
                        $logical = hash_hmac('sha256', self::POLICY . ':rate_limiter:k1:v2:' . self::ENV . ':' . \Maatify\RateLimiter\Service\SourcePrefix::of('192.0.2.77', 64), self::SECRET);
                        $rival->incrementAt($logical, 86400, 1, self::NOW);
                    }
                    $snapshots['before'] = $this->snapshot();
                    $result = $this->admin->execute($command);
                    $snapshots['after'] = $this->snapshot();

                    return $result;
                }

                return $this->admin->execute($command);
            });
            $this->failures($this->runtime($executor, KeyScopeEncodingEnum::E1));

            self::assertGreaterThanOrEqual(2, count($commits), "{$layout}: a conflicting attempt is followed by a new attempt.");
            self::assertSame($snapshots['before'], $snapshots['after'], "{$layout}: the conflicting commit saved none of the request's writes, in either layout.");
            self::assertContains($this->e1ScoreKey('k4'), $this->keys(), "{$layout}: the re-evaluated request saved both layouts.");
        }
    }

    /** A request written straight at the store: a v1 key and an E1 key, saved by one `transactRequest()`. */
    private function mixedRequest(RedisFullCapabilityStore $store): void
    {
        $e1Key = E1AccountScopeKeyDeriver::key(self::SECRET, ['k4', self::POLICY, self::ENV, self::ACCOUNT]);
        $store->transactRequest(function () use ($store, $e1Key): void {
            $store->incrementAt('k1-plain-key', 86400, 1, self::NOW);
            $store->e1Keyspace()->incrementAt($e1Key, 86400, 1, self::NOW);
        });
    }

    public function testAnInterruptionBeforeTheSaveReachedRedisSavesNothingAndALostReplySavesBothLayoutsTogether(): void
    {
        // (iii) the save never reached Redis: no commit was delivered.
        $commits = [];
        $dropped = $this->recording($commits, static function (): mixed {
            throw new BackendFailureException('connection lost before the save');
        });
        $before = $this->snapshot();
        try {
            $this->mixedRequest(new RedisFullCapabilityStore($dropped, self::NAMESPACE));
            self::fail('The caller sees the backend failure.');
        } catch (BackendFailureException) {
            $this->addToAssertionCount(1);
        }
        self::assertCount(1, $commits);
        self::assertSame($before, $this->snapshot(), 'Nothing of the request is saved, in either layout.');

        // (iv) Redis applied the commit and the reply was lost: the caller's outcome is unknown, the save is whole.
        $commits = [];
        $lost = $this->recording($commits, function (array $command): mixed {
            $this->admin->execute($command);

            throw new BackendFailureException('reply lost after the save');
        });
        try {
            $this->mixedRequest(new RedisFullCapabilityStore($lost, self::NAMESPACE));
            self::fail('The caller sees an unknown outcome.');
        } catch (BackendFailureException) {
            $this->addToAssertionCount(1);
        }
        self::assertCount(1, $commits, 'The save is not retried as if it had failed.');
        $keys = $this->keys();
        self::assertContains(self::v1Prefix() . 'score:' . hash('sha256', 'k1-plain-key'), $keys);
        self::assertContains($this->e1ScoreKey('k4'), $keys, 'Both layouts were saved together, never one.');
    }

    public function testBothViewsShareOneRequestStateAndOneOwnership(): void
    {
        $store = new RedisFullCapabilityStore($this->admin, self::NAMESPACE);
        $view = $store->e1Keyspace();
        $e1Key = E1AccountScopeKeyDeriver::key(self::SECRET, ['k4', self::POLICY, self::ENV, self::ACCOUNT]);

        $store->transactRequest(function () use ($store, $view, $e1Key): void {
            $store->incrementAt('k1-plain-key', 86400, 1, self::NOW);
            $view->incrementAt($e1Key, 86400, 1, self::NOW);
            // The writes are held back for the one commit: nothing is in Redis yet, in either layout.
            self::assertSame([], $this->keys());
            // An independent execution context is refused on either view while the request owns the store (DEC-041).
            foreach ([[$store, 'other'], [$view, $e1Key]] as [$target, $logical]) {
                $fiber = new \Fiber(static fn() => $target->incrementAt($logical, 60, 1, self::NOW));
                try {
                    $fiber->start();
                    self::fail('An overlapping call is refused on either view.');
                } catch (\Maatify\RateLimiter\Exception\OverlappingRequestException) {
                    $this->addToAssertionCount(1);
                }
            }
        });

        self::assertCount(2, $this->keys(), 'One commit saved both layouts.');
    }

    public function testARequestIsRefusedWhenAKeyItReadInEitherLayoutHasAnExpiryTheSaveCannotCompare(): void
    {
        $runtime = $this->runtime($this->admin, KeyScopeEncodingEnum::E1);
        $this->failures($runtime);
        self::assertSame(1, $this->admin->execute(['PEXPIREAT', $this->e1ScoreKey('k4'), '9007199254740993']));
        $before = $this->snapshot();

        try {
            $this->failures($runtime);
            self::fail('DEC-045: a writing request over a key whose expiry cannot be compared exactly is refused.');
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('expiry beyond the exactly readable range', $exception->getMessage());
        }
        self::assertSame($before, $this->snapshot(), 'Nothing was saved in either layout.');
    }

    // ---------------------------------------------------------------------------------------------------------------- ACL

    /**
     * @param list<string> $denied commands the account lacks
     * @param list<string> $keys
     */
    private function userExecutor(string $user, array $denied, array $keys): RespRedisCommandExecutor
    {
        $commands = ['eval', 'time', 'exists', 'type', 'pexpiretime', 'get', 'hgetall', 'smembers', 'zrange', 'del', 'set', 'hset', 'sadd', 'zadd', 'pexpireat', 'hget', 'hmget', 'ping', 'evalsha'];
        $rules = array_map(static fn(string $command): string => '+' . $command, array_values(array_diff($commands, $denied)));
        $this->admin->execute(['ACL', 'SETUSER', $user, 'reset', 'on', '>' . self::PASSWORD, ...$keys, ...$rules]);
        $this->users[] = $user;
        $executor = new RespRedisCommandExecutor($this->host, $this->port, RespRedisCommandExecutor::MODE_EVAL);
        self::assertSame('OK', $executor->execute(['AUTH', $user, self::PASSWORD]));

        return $executor;
    }

    public function testAnAccountWithOnlyTheV1GrantIsRefusedAtTheEntryAndAnIncompleteWriteGrantAtTheSave(): void
    {
        // (i) v1 grant only: refused where the E1 keys are read, nothing changes, no commit is reached.
        $v1Only = $this->userExecutor('e1-v1-only', [], ['~maatify:rate-limiter:v1:*', '~']);
        $commits = [];
        $before = $this->snapshot();
        try {
            $this->failures($this->runtime(new CallableRedisCommandExecutor(function (array $command) use (&$commits, $v1Only): mixed {
                if (OverlayWire::isCommit($command)) {
                    $commits[] = $command;
                }

                return $v1Only->execute($command);
            }), KeyScopeEncodingEnum::E1));
            self::fail('An account without the E1 grant is refused.');
        } catch (\Throwable $exception) {
            self::assertStringNotContainsString('request commit', $exception->getMessage(), 'This is the entry guard, not the DEC-042 save guard.');
        }
        self::assertSame([], $commits);
        self::assertSame($before, $this->snapshot());

        // (ii) the account reads and evaluates in both layouts but lacks a write command of the plan: DEC-042 from the commit.
        $noHset = $this->userExecutor('e1-no-hset', ['hset'], ['~maatify:rate-limiter:*', '~']);
        $before = $this->snapshot();
        try {
            $this->failures($this->runtime($noHset, KeyScopeEncodingEnum::E1));
            self::fail('A missing write permission refuses the save.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('NOPERM request commit', $exception->getMessage());
        }
        self::assertSame($before, $this->snapshot(), 'Both layouts are unchanged.');

        // The same request succeeds once the grant is complete.
        $complete = $this->userExecutor('e1-complete', [], ['~maatify:rate-limiter:*', '~']);
        $this->failures($this->runtime($complete, KeyScopeEncodingEnum::E1));
        self::assertContains($this->e1ScoreKey('k4'), $this->keys());
    }

    /** A float: a millisecond epoch value does not fit a 32-bit int, and below 2^53 it is exact in a double. */
    private function redisTimeMilliseconds(): float
    {
        $time = $this->admin->execute(['TIME']);
        self::assertIsArray($time);
        self::assertIsString($time[0]);
        self::assertIsString($time[1]);

        return (float) $time[0] * 1000 + (float) intdiv((int) $time[1], 1000);
    }

    public function testExpiryOfE1KeysFollowsTheThreeCasesOfTheContract(): void
    {
        $store = new RedisFullCapabilityStore($this->admin, self::NAMESPACE);
        $view = $store->e1Keyspace();
        $e1Key = E1AccountScopeKeyDeriver::key(self::SECRET, ['k4', self::POLICY, self::ENV, self::ACCOUNT]);
        $v1Key = hash_hmac('sha256', 'v2-comparison-key', self::SECRET);

        // Case 1: the logical deadline from caller time is exact and identical across layouts (the physical key differs, the stored deadline does not).
        $store->blockAt($v1Key, 2, 600, self::NOW);
        $view->blockAt($e1Key, 2, 600, self::NOW);
        $v1Block = $this->admin->execute(['HGETALL', self::v1Prefix() . 'block:' . hash('sha256', $v1Key)]);
        $e1Block = $this->admin->execute(['HGETALL', self::e1Prefix() . 'block:' . hash('sha256', $e1Key)]);
        self::assertIsArray($v1Block);
        self::assertIsArray($e1Block);
        self::assertSame($v1Block, $e1Block, 'The logical deadline stored from the same caller time is identical in both layouts.');
        self::assertContains((string) (self::NOW + 600), array_map(static fn(mixed $value): string => is_scalar($value) ? (string) $value : '', $e1Block));

        // Case 3: a new physical TTL on the Redis clock is bracketed by two TIME samples, in each layout; the delta is judged, never the absolute value.
        foreach (['v1' => [$store, $v1Key, self::v1Prefix()], 'e1' => [$view, $e1Key, self::e1Prefix()]] as $layout => [$target, $logical, $prefix]) {
            $this->admin->execute(['DEL', $prefix . 'score:' . hash('sha256', $logical)]);
            $before = $this->redisTimeMilliseconds();
            $target->incrementAt($logical, 600, 1, self::NOW);
            $after = $this->redisTimeMilliseconds();
            $expiry = $this->admin->execute(['PEXPIRETIME', $prefix . 'score:' . hash('sha256', $logical)]);
            self::assertTrue(is_int($expiry) || is_string($expiry));
            $expiryMs = (float) $expiry;
            self::assertGreaterThanOrEqual($before + 600000, $expiryMs, "{$layout}: the TTL starts after the first TIME sample.");
            self::assertLessThanOrEqual($after + 600000, $expiryMs, "{$layout}: and before the second.");
        }

        // Case 2: a physical expiry the path must preserve is kept literally in one run, in the E1 layout, through a request commit.
        $budgetKey = E1AccountScopeKeyDeriver::key(self::SECRET, ['budget_cooldown', self::POLICY, self::ENV, self::ACCOUNT]);
        $view->incrementBudgetAt($budgetKey, 86400, 1, self::NOW);
        $physical = self::e1Prefix() . 'budget:' . hash('sha256', $budgetKey);
        $before = $this->expiryText($physical);
        $view->transactRequest(static fn() => $view->incrementBudgetAt($budgetKey, 86400, 1, self::NOW + 5));
        self::assertSame($before, $this->expiryText($physical), 'An increment of the active epoch keeps its physical expiry to the millisecond (no renewal).');
    }

    private function expiryText(string $key): string
    {
        $expiry = $this->admin->execute(['PEXPIRETIME', $key]);
        self::assertTrue(is_int($expiry) || is_string($expiry));

        return (string) $expiry;
    }
}
