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
use Maatify\RateLimiter\Tests\Support\Ownership\StubContextProvider;
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

    // ---------------------------------------------------------------------------------------------------------------- F295-01 / F295-02

    public function testACopyOfAStoreNeverSharesTheE1ViewOfTheOriginalAndACancelledRequestLeavesNeitherLayoutWritten(): void
    {
        $e1Key = E1AccountScopeKeyDeriver::key(self::SECRET, ['k4', self::POLICY, self::ENV, self::ACCOUNT]);
        $original = new RedisFullCapabilityStore($this->admin, self::NAMESPACE);
        $originalView = $original->e1Keyspace();
        $copy = clone $original;
        $copyView = $copy->e1Keyspace();

        self::assertNotSame($originalView, $copyView, 'F295-01: a copy gets a view of its own, never the original\'s.');
        self::assertSame($copyView, $copy->e1Keyspace());
        self::assertSame($originalView, $original->e1Keyspace(), 'The original keeps its view.');

        foreach ([['the original', $original, $originalView], ['the copy', $copy, $copyView]] as [$label, $store, $view]) {
            $before = $this->snapshot();
            try {
                $store->transactRequest(function () use ($store, $view, $e1Key): int {
                    $store->incrementAt('cancelled-v1-key', 600, 1, self::NOW);
                    $view->incrementAt($e1Key, 600, 1, self::NOW);

                    return $this->cancelTheRequest($store !== $view);
                });
                self::fail('The callback cancels the request.');
            } catch (\Throwable $exception) {
                self::assertInstanceOf(\LogicException::class, $exception);
            }
            self::assertSame($before, $this->snapshot(), "{$label}: cancelling the request leaves no trace in either layout.");
        }

        // The same request, not cancelled, saves both layouts through the copy's own state and none of it appears through the original's.
        $copy->transactRequest(static function () use ($copy, $copyView, $e1Key): void {
            $copy->incrementAt('kept-v1-key', 600, 1, self::NOW);
            $copyView->incrementAt($e1Key, 600, 1, self::NOW);
        });
        self::assertContains(self::v1Prefix() . 'score:' . hash('sha256', 'kept-v1-key'), $this->keys());
        self::assertContains(self::e1Prefix() . 'score:' . hash('sha256', $e1Key), $this->keys());
    }

    public function testTheEncodingIsFixedByTheFirstBuildWhicheverOfTheRuntimeAndTheReaderComesFirst(): void
    {
        foreach ([
            'runtime then reader' => [static fn(RateLimiterBuilder $b) => $b->build(), static fn(RateLimiterBuilder $b) => $b->buildOperationalReader()],
            'reader then runtime' => [static fn(RateLimiterBuilder $b) => $b->buildOperationalReader(), static fn(RateLimiterBuilder $b) => $b->build()],
        ] as $label => [$first, $second]) {
            foreach ([[KeyScopeEncodingEnum::V2, KeyScopeEncodingEnum::E1], [KeyScopeEncodingEnum::E1, KeyScopeEncodingEnum::V2]] as [$chosen, $other]) {
                $builder = $this->builder($this->admin, $chosen);
                $first($builder);
                try {
                    $builder->withKeyScopeEncoding($other);
                    self::fail("{$label}: changing the encoding after a build must be refused.");
                } catch (RateLimiterException $exception) {
                    self::assertStringContainsString('fixed once', $exception->getMessage());
                }
                $builder->withKeyScopeEncoding($chosen); // the same selection is harmless
                $second($builder);

                // The runtime and the reader of this builder agree: the reader sees the score the runtime wrote.
                $runtime = $builder->build();
                $this->failures($runtime);
                $reader = $builder->buildOperationalReader();
                self::assertNotNull($reader->readScorePolicy($this->context(), self::POLICY)->scopes->k4?->score, "{$label} under {$chosen->value}: the reader sees the K4 score the runtime wrote.");
                $this->admin->execute(['FLUSHDB']);
            }
        }

        // A build that failed before deriving anything does not fix the selection.
        $builder = new RateLimiterBuilder(
            $this->config(),
            new \Maatify\RateLimiter\Tests\Support\RateLimiter\BaseOnlyInMemoryRateLimitStore(new FixedClock('@' . self::NOW)),
            new \Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore(new FixedClock('@' . self::NOW)),
            new \Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore(),
            new RecordingFailureSignalEmitter(),
        );
        $builder->withKeyScopeEncoding(KeyScopeEncodingEnum::E1);
        try {
            $builder->build();
            self::fail('E1 over an incapable store fails.');
        } catch (RateLimiterException) {
            $this->addToAssertionCount(1);
        }
        self::assertSame($builder, $builder->withKeyScopeEncoding(KeyScopeEncodingEnum::V2));
    }

    // ---------------------------------------------------------------------------------------------------------------- F295-03: the ten families

    /**
     * Every store method that writes a family, for one logical string. Returns what the calls returned.
     *
     * @return list<string>
     */
    private function everyFamily(RedisFullCapabilityStore $store, string $logical, string $life, ?string $previous = null, bool $withDistinct = true): array
    {
        $out = [];
        $out[] = (string) $store->incrementAt($logical, 600, 1, self::NOW);
        $store->blockAt($logical, 1, 600, self::NOW);
        $out[] = print_r($store->incrementBudgetAt($logical, 86400, 1, self::NOW), true);
        $out[] = (string) $store->incrementWatchFlagAt($logical, 600, self::NOW);
        if ($withDistinct) {
            $out[] = (string) $store->addDistinctAt($logical, 'member', 600, self::NOW);
        }
        $out[] = print_r($store->blockWithCycleTracking($logical, $previous, 2, 60, self::NOW, 3600, 1, 60, 3600), true);
        $out[] = print_r($store->readDecayPauseState($logical, $previous, self::NOW - 10, self::NOW), true);
        // The lifecycle works on a score key written by the lifecycle mutation (its own state shape), so it has its own logical key.
        $lifecycleId = str_repeat('ab', 16);
        $out[] = print_r($store->mutateGenerationBoundScoreAtMilliseconds($life, $previous, null, 600, 5, self::NOW . '000'), true);
        $out[] = print_r($store->blockWithPunishmentLifecycleTrackingAt($life, $previous, 1, $lifecycleId, 2, 60, 3600, 1, 60, 3600, self::NOW), true);
        $out[] = $store->claimPostPunishmentReentryAtMilliseconds($life, $previous, $lifecycleId, (self::NOW + 61) . '000') ? 'claimed' : 'not-claimed';
        $out[] = (string) $store->incrementWatchFlagAcrossRotationAt($logical, $previous ?? $logical . '-prev', 600, self::NOW);

        return $out;
    }

    /** @return list<string> the family segment of each key under a prefix */
    private function families(string $prefix): array
    {
        $families = [];
        foreach (self::under($this->keys(), $prefix) as $key) {
            $families[explode(':', substr($key, strlen($prefix)))[0]] = true;
        }
        $names = array_keys($families);
        sort($names);

        return $names;
    }

    public function testDirectPlainStringsGiveTheV1KeysInAllTenFamiliesWithE1SelectedOrNot(): void
    {
        $tenFamilies = ['block', 'budget', 'cycle', 'distinct', 'distinct-meta', 'pause', 'reentry-claim', 'score', 'watch', 'watch-meta'];
        foreach (['e1.' . str_repeat('a', 64), str_repeat('b', 64), 'e1.not-hex', 'e1.' . str_repeat('A', 64), 'plain-string', "nul\0byte"] as $string) {
            $baseline = null;
            $selectedRun = null;
            foreach ([false, true] as $selected) {
                $this->admin->execute(['FLUSHDB']);
                $store = new RedisFullCapabilityStore($this->admin, self::NAMESPACE);
                if ($selected) {
                    $this->builder($this->admin, KeyScopeEncodingEnum::E1, $store)->build();
                }
                $returned = $this->everyFamily($store, $string, $string . '|lifecycle');
                $keys = $this->keys();
                self::assertSame([], self::under($keys, self::e1Prefix()), 'A plain string never reaches the E1 prefix.');
                self::assertSame($tenFamilies, $this->families(self::v1Prefix()), 'All ten families are reached, under v1.');
                $dumps = array_map(static fn(array $entry): string => $entry[0], $this->snapshot());
                if ($selected) {
                    $selectedRun = [$keys, $dumps, $returned];
                } else {
                    $baseline = [$keys, $dumps, $returned];
                }
            }
            self::assertNotNull($baseline);
            self::assertNotNull($selectedRun);
            self::assertSame($baseline[0], $selectedRun[0], 'The physical keys equal those with E1 unselected.');
            self::assertSame($baseline[1], $selectedRun[1], 'The stored values equal those with E1 unselected.');
            self::assertSame($baseline[2], $selectedRun[2], 'The results equal those with E1 unselected.');
        }
    }

    /** @return array{list<string>, array<string, list<string>>} the results and, per family, the sorted byte-exact values */
    private function layoutRun(bool $e1): array
    {
        $this->admin->execute(['FLUSHDB']);
        $store = new RedisFullCapabilityStore($this->admin, self::NAMESPACE);
        if ($e1) {
            $target = $store->e1Keyspace();
            $current = E1AccountScopeKeyDeriver::key(self::SECRET, ['k4', self::POLICY, self::ENV, self::ACCOUNT]);
            $previous = E1AccountScopeKeyDeriver::key('previous_secret', ['k4', self::POLICY, self::ENV, self::ACCOUNT]);
            $life = E1AccountScopeKeyDeriver::key(self::SECRET, ['k4p', self::POLICY, self::ENV, self::ACCOUNT]);
            $prefix = self::e1Prefix();
        } else {
            $target = $store;
            $current = hash_hmac('sha256', 'k4|current', self::SECRET);
            $previous = hash_hmac('sha256', 'k4|previous', 'previous_secret');
            $life = hash_hmac('sha256', 'k4p|current', self::SECRET);
            $prefix = self::v1Prefix();
        }
        // E1 holds no `distinct` family (invariant 4): the derivative hashes stay in v1.
        $returned = $this->everyFamily($target, $current, $life, $previous, false);
        $returned[] = print_r($target->readGenerationBoundScoreState($current, $previous), true);
        $returned[] = print_r($target->checkBlockAt($current, self::NOW + 1), true);
        $returned[] = print_r($target->peekBudgetAt($current, self::NOW + 1), true);

        $values = [];
        foreach (self::under($this->keys(), $prefix) as $key) {
            $family = explode(':', substr($key, strlen($prefix)))[0];
            $dump = $this->admin->execute(['DUMP', $key]);
            self::assertIsString($dump);
            $expiry = (float) $this->expiryText($key);
            $values[$family][] = bin2hex($dump) . '@' . (string) (int) round($expiry / 60000);
        }
        ksort($values);
        foreach ($values as &$list) {
            sort($list);
        }

        return [$returned, $values];
    }

    public function testEveryE1FamilyBehavesLikeItsV1CounterpartOnTheRealStoreIncludingLifecycleAndRotation(): void
    {
        [$v1Returned, $v1Values] = $this->layoutRun(false);
        [$e1Returned, $e1Values] = $this->layoutRun(true);

        self::assertSame(['block', 'budget', 'cycle', 'pause', 'reentry-claim', 'score', 'watch', 'watch-meta'], array_keys($e1Values), 'All eight E1 families are written, in the E1 prefix.');
        self::assertSame(array_keys($v1Values), array_keys($e1Values));
        self::assertSame($v1Returned, $e1Returned, 'Every call returns the same result through the view as through the store.');
        self::assertSame($v1Values, $e1Values, 'Every family stores the same values and the same (minute-rounded) expiries in both layouts.');
    }

    // ---------------------------------------------------------------------------------------------------------------- F295-04: ACL per layout

    public function testARefusedWriteInEitherLayoutAloneReachesTheCommitAndLeavesBothLayoutsUnchanged(): void
    {
        // Redis 7 selectors: the account holds every command of the plan on every key of the namespace except `hset`, which a selector grants on one layout only.
        $cases = [
            'writes refused in the E1 layout only' => ['e1-no-write', '(+hset ~maatify:rate-limiter:v1:*)'],
            'writes refused in the v1 layout only' => ['v1-no-write', '(+hset ~maatify:rate-limiter:e1:*)'],
        ];
        foreach ($cases as $label => [$user, $selector]) {
            $account = $this->userExecutor($user, ['hset'], ['~maatify:rate-limiter:*', '~', $selector]);
            $commits = [];
            $executor = new CallableRedisCommandExecutor(function (array $command) use (&$commits, $account): mixed {
                if (OverlayWire::isCommit($command)) {
                    $commits[] = $command;
                }

                return $account->execute($command);
            });
            $before = $this->snapshot();
            try {
                $this->failures($this->runtime($executor, KeyScopeEncodingEnum::E1));
                self::fail("{$label}: the save must be refused.");
            } catch (\Throwable $exception) {
                self::assertStringContainsString('NOPERM', $exception->getMessage(), $label);
            }
            self::assertNotSame([], $commits, "{$label}: the request reached the commit (the save guard, not the entry guard).");
            self::assertSame($before, $this->snapshot(), "{$label}: both layouts are unchanged.");
        }
    }

    // ---------------------------------------------------------------------------------------------------------------- round 3: F295-02 (failed build) and F295-03 (lifecycle through the real composition)

    public function testAFailedBuildDoesNotFixTheSelectionButTheFirstSuccessfulBuildDoes(): void
    {
        $bound = new StubContextProvider(static fn(): int => 1);
        $other = new StubContextProvider(static fn(): int => 2);
        foreach (['runtime' => static fn(RateLimiterBuilder $b) => $b->build(), 'reader' => static fn(RateLimiterBuilder $b) => $b->buildOperationalReader()] as $kind => $compose) {
            foreach ([[KeyScopeEncodingEnum::E1, KeyScopeEncodingEnum::V2], [KeyScopeEncodingEnum::V2, KeyScopeEncodingEnum::E1]] as [$first, $second]) {
                $store = new RedisFullCapabilityStore($this->admin, self::NAMESPACE, null, 500, $bound);
                $builder = RateLimiterBuilder::fromFullCapabilityStore($this->config(), $store, new RecordingFailureSignalEmitter())
                    ->withClock(new FixedClock('@' . self::NOW))
                    ->withKeyScopeEncoding($first)
                    ->withExecutionContextProvider($other);
                try {
                    $compose($builder);
                    self::fail("{$kind}: another provider over a bound store fails the build (DEC-041).");
                } catch (RateLimiterException $exception) {
                    self::assertStringContainsString('domain', $exception->getMessage());
                }

                // Nothing was built, so nothing is fixed: the other encoding is accepted, the cause is corrected and the build succeeds.
                $builder->withKeyScopeEncoding($second)->withExecutionContextProvider($bound);
                $compose($builder);

                // Now it is fixed.
                try {
                    $builder->withKeyScopeEncoding($first);
                    self::fail("{$kind}: the first successful build fixes the selection.");
                } catch (RateLimiterException $exception) {
                    self::assertStringContainsString('fixed once', $exception->getMessage());
                }
            }
        }
    }

    public function testAFailingOuterBuildNeverUndoesTheSelectionAReaderBuiltInsideItAlreadyFixed(): void
    {
        $builder = $this->builder($this->admin, KeyScopeEncodingEnum::V2);
        /** @var \ArrayObject<string, mixed> $sink */
        $sink = new \ArrayObject();
        // A Host policy callback that builds a reader from this same builder while the outer build() runs, then makes the outer build fail.
        $policy = new class ($builder, $sink) extends \Maatify\RateLimiter\Config\LoginProtectionPolicy {
            /** @param \ArrayObject<string, mixed> $sink */
            public function __construct(private RateLimiterBuilder $builder, private \ArrayObject $sink) {}

            public function getName(): string
            {
                return 'nested_failing_policy';
            }

            public function getCapabilities(): array
            {
                if (! isset($this->sink['reader'])) {
                    $this->sink['reader'] = true;
                    $this->sink['reader'] = $this->builder->buildOperationalReader();

                    throw new \RuntimeException('the outer build fails after the nested reader succeeded');
                }

                return parent::getCapabilities();
            }
        };
        $builder->withPolicy($policy);

        try {
            $builder->build();
            self::fail('The outer build fails.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('nested reader succeeded', $exception->getMessage());
        }
        self::assertInstanceOf(\Maatify\RateLimiter\Service\CompositeRateLimitOperationalReaderInterface::class, $sink['reader'], 'The nested reader was built before the outer failure.');

        try {
            $builder->withKeyScopeEncoding(KeyScopeEncodingEnum::E1);
            self::fail('The nested successful build fixed the selection; the outer failure must not undo it.');
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('fixed once', $exception->getMessage());
        }
    }

    public function testTheSelectionCannotChangeWhileABuildIsRunningAndIsFreeAgainOnlyWhenEveryAttemptFailed(): void
    {
        foreach ([false, true] as $outerFails) {
            $builder = $this->builder($this->admin, KeyScopeEncodingEnum::V2);
            /** @var \ArrayObject<string, mixed> $sink */
            $sink = new \ArrayObject();
            // A Host policy callback that tries to switch the encoding from inside the running build.
            $policy = new class ($builder, $sink, $outerFails) extends \Maatify\RateLimiter\Config\LoginProtectionPolicy {
                /** @param \ArrayObject<string, mixed> $sink */
                public function __construct(private RateLimiterBuilder $builder, private \ArrayObject $sink, private bool $fail) {}

                public function getName(): string
                {
                    return 'switching_policy';
                }

                public function getCapabilities(): array
                {
                    if (! isset($this->sink['tried'])) {
                        $this->sink['tried'] = true;
                        try {
                            $this->builder->withKeyScopeEncoding(KeyScopeEncodingEnum::E1);
                            $this->sink['refused'] = false;
                        } catch (RateLimiterException) {
                            $this->sink['refused'] = true;
                        }
                        if ($this->fail) {
                            throw new \RuntimeException('the only attempt fails');
                        }
                    }

                    return parent::getCapabilities();
                }
            };
            $builder->withPolicy($policy);

            if ($outerFails) {
                try {
                    $builder->build();
                    self::fail('The only attempt fails.');
                } catch (\RuntimeException) {
                    $this->addToAssertionCount(1);
                }
                self::assertTrue($sink['refused'], 'The switch from inside the running build was refused.');
                // Every attempt failed and nothing was fixed: the selection is free again.
                self::assertSame($builder, $builder->withKeyScopeEncoding(KeyScopeEncodingEnum::E1));

                continue;
            }

            $runtime = $builder->build();
            self::assertTrue($sink['refused'], 'The switch from inside the running build was refused.');
            $this->failures($runtime);
            self::assertSame([], $this->families(self::e1Prefix()), 'The runtime keeps the encoding it was built with: nothing under the E1 prefix.');
            self::assertNotSame([], self::under($this->keys(), self::v1Prefix() . 'score:'));
            $reader = $builder->buildOperationalReader();
            self::assertNotNull($reader->readScorePolicy($this->context(), self::POLICY)->scopes->k4?->score, 'The reader agrees with the runtime.');
        }
    }

    /**
     * @return array{claimed: bool, again: bool, e1: list<string>, v1: list<string>}
     */
    private function lifecycleThroughTheRealComposition(KeyScopeEncodingEnum $encoding, bool $rotated): array
    {
        $this->admin->execute(['FLUSHDB']);
        $clock = new FixedClock('@' . self::NOW);
        $store = new RedisFullCapabilityStore($this->admin, self::NAMESPACE);
        $make = function (RateLimiterConfig $config) use ($store, $clock, $encoding): CompositeRateLimiterRuntimeInterface {
            return RateLimiterBuilder::fromFullCapabilityStore($config, $store, new RecordingFailureSignalEmitter())->withClock($clock)->withKeyScopeEncoding($encoding)->build();
        };
        $writer = $make($rotated ? new RateLimiterConfig('previous_secret', 'previous_fp', self::ENV) : $this->config());
        $runtime = $rotated ? $make(new RateLimiterConfig(self::SECRET, 'test_fp', self::ENV, 'previous_secret', 'previous_fp')) : $writer;

        for ($i = 0; $i < 3; $i++) {
            $writer->limit($this->context(), RateLimitCommand::recordFailure(self::POLICY));
        }
        $poll = $runtime->limit($this->context(), RateLimitCommand::checkOnly(self::POLICY));
        self::assertSame(\Maatify\RateLimiter\DTO\RateLimitResultDTO::DECISION_HARD_BLOCK, $poll->decision, 'Three failures hard-block, through the previous identity after a rotation.');
        $clock->setNow($clock->now()->modify('+' . ((int) $poll->retryAfter + 1) . ' seconds'));
        $back = $runtime->limit($this->context(), RateLimitCommand::checkOnly(self::POLICY));
        $reentry = $back->metadata?->postPunishmentReentry;
        self::assertNotNull($reentry, 'The client returning after the block gets re-entry evidence.');
        $claimed = $runtime->claimPostPunishmentReentry($this->context(), self::POLICY, $reentry->id);
        $again = $runtime->claimPostPunishmentReentry($this->context(), self::POLICY, $reentry->id);

        $keys = $this->keys();

        return ['claimed' => $claimed, 'again' => $again, 'e1' => $this->families(self::e1Prefix()), 'v1' => self::under($keys, self::v1Prefix() . 'reentry-claim:')];
    }

    public function testLifecycleAndRotationThroughTheRealCompositionReachTheLayoutTheEncodingSelects(): void
    {
        foreach ([false, true] as $rotated) {
            $label = $rotated ? 'after a rotation' : 'one secret';
            $v2 = $this->lifecycleThroughTheRealComposition(KeyScopeEncodingEnum::V2, $rotated);
            $e1 = $this->lifecycleThroughTheRealComposition(KeyScopeEncodingEnum::E1, $rotated);

            self::assertSame([], $v2['e1'], "V2 {$label}: no key under the E1 prefix, in any family.");
            self::assertNotSame([], $v2['v1'], "V2 {$label}: the claim marker is under v1.");
            self::assertContains('reentry-claim', $e1['e1'], "E1 {$label}: the claim marker is under the E1 prefix.");
            self::assertSame([], $e1['v1'], "E1 {$label}: no claim marker is under v1 (the claim was not routed to the V2 layout).");
            self::assertSame(['budget', 'cycle', 'reentry-claim', 'score', 'watch', 'watch-meta'], $e1['e1'], "E1 {$label}: every account-scope family the lifecycle touched is under the E1 prefix.");
            self::assertSame($v2['claimed'], $e1['claimed'], "{$label}: the claim has the same outcome in both encodings.");
            self::assertTrue($e1['claimed']);
            self::assertFalse($e1['again'], "{$label}: the marker is one-shot in E1.");
            self::assertSame($v2['again'], $e1['again']);
        }
    }

    /** A known device of the account: the failure is counted by the known-device micro-cap budget (a fingerprint-bound account key). */
    private function knownDeviceContext(): RateLimitContextDTO
    {
        return new RateLimitContextDTO(
            ip: '192.0.2.78',
            ua: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            accountId: self::ACCOUNT,
            clientFingerprint: ['device' => 'known-device-1'],
            isDevicePreviouslyVerifiedForAccount: true,
        );
    }

    /**
     * Budget rotation through the real composition: the previous generation writes, the rotated runtime seeds its Current budget from it.
     *
     * @return array{before: array<string, list<string>>, after: array<string, list<string>>}
     */
    private function budgetRotationRun(KeyScopeEncodingEnum $encoding, RateLimiterConfig $previousGeneration, RateLimiterConfig $rotated): array
    {
        $this->admin->execute(['FLUSHDB']);
        $store = new RedisFullCapabilityStore($this->admin, self::NAMESPACE);
        $make = static fn(RateLimiterConfig $config): CompositeRateLimiterRuntimeInterface => RateLimiterBuilder::fromFullCapabilityStore($config, $store, new RecordingFailureSignalEmitter())
            ->withClock(new FixedClock('@' . self::NOW))->withKeyScopeEncoding($encoding)->build();

        $make($previousGeneration)->limit($this->knownDeviceContext(), RateLimitCommand::recordFailure(self::POLICY));
        $before = $this->budgetEntries();
        $make($rotated)->limit($this->knownDeviceContext(), RateLimitCommand::recordFailure(self::POLICY));

        return ['before' => $before, 'after' => $this->budgetEntries()];
    }

    /** @return array<string, list<string>> every budget key (all prefixes) and its stored fields */
    private function budgetEntries(): array
    {
        $entries = [];
        foreach ($this->keys() as $key) {
            if (preg_match('/:budget:/', $key) !== 1) {
                continue;
            }
            $fields = $this->admin->execute(['HGETALL', $key]);
            self::assertIsArray($fields);
            $entries[$key] = array_values(array_map(static fn(mixed $field): string => is_scalar($field) ? (string) $field : '', $fields));
        }

        return $entries;
    }

    public function testBudgetRotationSeedsCurrentFromPreviousThroughTheRuntimeInBothEncodings(): void
    {
        $scenarios = [
            'secret rotation' => [new RateLimiterConfig('previous_secret', 'previous_fp', self::ENV), new RateLimiterConfig(self::SECRET, 'test_fp', self::ENV, 'previous_secret', 'previous_fp')],
            'device generation rotation' => [new RateLimiterConfig(self::SECRET, 'previous_fp', self::ENV), new RateLimiterConfig(self::SECRET, 'test_fp', self::ENV, null, 'previous_fp')],
        ];
        foreach ($scenarios as $label => [$previousGeneration, $rotated]) {
            $shapes = [];
            foreach ([KeyScopeEncodingEnum::V2, KeyScopeEncodingEnum::E1] as $encoding) {
                $run = $this->budgetRotationRun($encoding, $previousGeneration, $rotated);
                $before = $run['before'];
                $after = $run['after'];
                $new = array_diff_key($after, $before);
                $name = "{$label} under {$encoding->value}";

                self::assertNotSame([], $new, "{$name}: the rotated runtime created a Current budget key (the seed).");
                foreach ($before as $key => $fields) {
                    if ($label === 'secret rotation') {
                        self::assertSame($fields, $after[$key] ?? null, "{$name}: a Previous budget key is read, never written.");

                        continue;
                    }
                    // A fingerprint-only rotation keeps the account budget key (K4 does not depend on the device), so that key is the Current one: it counts on.
                    $changed = $after[$key] ?? null;
                    self::assertTrue($changed === $fields || ($changed !== null && (int) $changed[1] === (int) $fields[1] + 1), "{$name}: an unchanged account key either stays or counts on by one.");
                }
                $wrong = $encoding === KeyScopeEncodingEnum::E1 ? self::v1Prefix() : self::e1Prefix();
                $right = $encoding === KeyScopeEncodingEnum::E1 ? self::e1Prefix() : self::v1Prefix();
                self::assertSame([], self::under(array_keys($after), $wrong . 'budget:'), "{$name}: no budget key in the other layout (account-scope budget keys only).");
                self::assertSame(array_keys($after), self::under(array_keys($after), $right . 'budget:'), "{$name}: every budget key is under the selected prefix.");

                $counts = array_map(static fn(array $fields): int => (int) ($fields[1] ?? 0), array_values($before));
                foreach ($new as $key => $fields) {
                    self::assertSame('count', $fields[0], $name);
                    self::assertContains((int) $fields[1] - 1, $counts, "{$name}: the seeded Current count is a Previous count plus this request's one.");
                    $epochStart = array_search('epochStart', $fields, true);
                    self::assertNotFalse($epochStart);
                    self::assertSame(array_values($before)[0][array_search('epochStart', array_values($before)[0], true) + 1], $fields[$epochStart + 1], "{$name}: the seed keeps the Previous epoch start.");
                }
                $shapes[$encoding->value] = array_values($after);
                sort($shapes[$encoding->value]);
            }
            self::assertSame($shapes['v2'], $shapes['e1'], "{$label}: the saved budget state is identical in both encodings.");
        }
    }

    /** Cancels the request on purpose (declared `int` so the static analysis does not treat the callback as never returning). */
    private function cancelTheRequest(bool $really): int
    {
        if ($really) {
            throw new \LogicException('cancel on purpose');
        }

        return 0;
    }

    private function expiryText(string $key): string
    {
        $expiry = $this->admin->execute(['PEXPIRETIME', $key]);
        self::assertTrue(is_int($expiry) || is_string($expiry));

        return (string) $expiry;
    }
}
