<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\DTO\AdmissionPermitDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionPermitClaimEnum;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
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

    private function builder(RedisCommandExecutorInterface $executor, ?RedisFullCapabilityStore $store = null): RateLimiterBuilder
    {
        return RateLimiterBuilder::fromFullCapabilityStore(
            $this->config(),
            $store ?? new RedisFullCapabilityStore($executor, self::NAMESPACE),
            new RecordingFailureSignalEmitter(),
        )->withClock(new FixedClock('@' . self::NOW));
    }

    private function runtime(RedisCommandExecutorInterface $executor): CompositeRateLimiterRuntimeInterface
    {
        return $this->builder($executor)->build();
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

    /**
     * Byte-exact DUMP (hex) and PEXPIRETIME as text. Request-failure tests exclude independently reported circuit
     * state by default; direct-store inventory checks explicitly include every key, regardless of prefix or family.
     *
     * @return array<string, array{string, string}>
     */
    private function snapshot(bool $includeCircuit = false): array
    {
        $state = [];
        foreach ($this->keys() as $key) {
            if (! $includeCircuit && str_contains($key, ':circuit:')) {
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

    public function testTheDefaultRuntimeUsesE1AndKeepsNonAccountScoreKeys(): void
    {
        $this->failures($this->runtime($this->admin), 3);
        $keys = $this->keys();
        self::assertContains($this->e1ScoreKey(), $keys);
        $device = (new \Maatify\RateLimiter\Service\DeviceIdentityResolver(new \Maatify\RateLimiter\Service\FingerprintHasher('test_fp')))->resolve($this->context());
        $expected = [
            \Maatify\RateLimiter\Tests\Support\NonAccountKeyOracle::k1(self::POLICY, self::ENV, '192.0.2.77', self::SECRET),
            \Maatify\RateLimiter\Tests\Support\NonAccountKeyOracle::k2(self::POLICY, self::ENV, '192.0.2.77', $device->normalizedUa, self::SECRET),
            \Maatify\RateLimiter\Tests\Support\NonAccountKeyOracle::k3(self::POLICY, self::ENV, '192.0.2.77', (string) $device->fingerprintHash, self::SECRET),
        ];
        $expected = array_map(static fn(string $logical): string => self::v1Prefix() . 'score:' . hash('sha256', $logical), $expected);
        foreach (self::under($keys, self::v1Prefix() . 'score:') as $key) {
            self::assertContains($key, $expected, 'Only unchanged K1-K3 score identities belong to the root.');
        }
        foreach (self::under($keys, self::e1Prefix()) as $key) {
            $family = explode(':', substr($key, strlen(self::e1Prefix())))[0];
            self::assertContains($family, ['score', 'block', 'budget', 'cycle', 'pause', 'reentry-claim', 'watch', 'watch-meta']);
        }
    }

    public function testTheDefaultReaderIgnoresHistoricalV2AccountStateAndFollowsE1(): void
    {
        $root = new RedisFullCapabilityStore($this->admin, self::NAMESPACE);
        $legacy = (new \Maatify\RateLimiter\Tests\Fixtures\KeyScopeV2Reference(self::ENV))->derive('k4', self::POLICY, self::ACCOUNT, self::SECRET);
        $root->set($legacy, 77, 86400);
        $reader = $this->builder($this->admin)->buildOperationalReader();
        self::assertNull($reader->readScorePolicy($this->context(), self::POLICY)->scopes->k4?->score);
        $before = $root->get($legacy);
        $this->failures($this->runtime($this->admin), 2);
        self::assertNotNull($reader->readScorePolicy($this->context(), self::POLICY)->scopes->k4?->score);
        self::assertEquals($before, $root->get($legacy), 'Historical V2 is neither read as Previous nor written.');
    }

    /** T13/C8: an isolated demonstration of an unsupported transition, never a migration recipe. */
    public function testHistoricalV2AndCurrentE1WorkersCannotSeeEachOthersAccountBlocks(): void
    {
        $clock = new FixedClock('@' . self::NOW);
        $root = new RedisFullCapabilityStore($this->admin, self::NAMESPACE, $clock);
        $old = new \Maatify\RateLimiter\Tests\Fixtures\PreG1\EvaluationPipeline(
            $root,
            $root,
            new \Maatify\RateLimiter\Service\BudgetTracker($root, $clock),
            new \Maatify\RateLimiter\Service\AntiEquilibriumGate($root),
            new \Maatify\RateLimiter\Service\DecayCalculator($clock),
            new \Maatify\RateLimiter\Service\EphemeralBucket($root),
            self::SECRET,
            self::ENV,
            $clock,
        );
        $policy = new \Maatify\RateLimiter\Config\LoginProtectionPolicy();
        $device = new \Maatify\RateLimiter\DTO\DeviceIdentityDTO(null, 'LOW', false, false, 'browser');
        $legacy = (new \Maatify\RateLimiter\Tests\Fixtures\KeyScopeV2Reference(self::ENV))->derive('k4', self::POLICY, self::ACCOUNT, self::SECRET);
        $e1 = (new E1AccountScopeKeyDeriver(self::ENV))->derive('k4', self::POLICY, self::ACCOUNT, self::SECRET);
        $root->blockAt($legacy, 2, 600, self::NOW);
        $check = RateLimitCommand::checkOnly(self::POLICY);
        self::assertSame('HARD_BLOCK', $old->process($policy, $this->context(), $check, $device)->decision);
        self::assertSame('ALLOW', $this->runtime($this->admin)->limit($this->context(), $check)->decision, 'E1 cannot see a V2 account block.');
        self::assertSame(2, $root->peekBlockAt($legacy, self::NOW)?->level);
        $other = 'other-account';
        $otherE1 = (new E1AccountScopeKeyDeriver(self::ENV))->derive('k4', self::POLICY, $other, self::SECRET);
        $root->e1Keyspace()->blockAt($otherE1, 2, 600, self::NOW);
        self::assertSame('HARD_BLOCK', $this->runtime($this->admin)->limit($this->context($other), $check)->decision);
        self::assertSame('ALLOW', $old->process($policy, $this->context($other), $check, $device)->decision, 'V2 rollback cannot see an E1 account block.');
        self::assertNull($root->peekBlockAt($e1, self::NOW), 'A logical prefix does not reroute a root call.');
    }

    public function testTheClosedBudgetTrackerReadsTheAccountViewThroughThePublicRuntime(): void
    {
        $root = new RedisFullCapabilityStore($this->admin, self::NAMESPACE);
        $key = (new E1AccountScopeKeyDeriver(self::ENV))->derive('k4', self::POLICY, self::ACCOUNT, self::SECRET);
        $root->e1Keyspace()->incrementBudgetAt($key, 86400, 20, self::NOW);
        $result = $this->runtime($this->admin)->limit($this->context(), RateLimitCommand::checkOnly(self::POLICY));
        self::assertSame('SOFT_BLOCK', $result->decision, 'A root-bound BudgetTracker would incorrectly report an empty budget.');
        self::assertSame(3, $result->blockLevel);
        self::assertNull($root->peekBudgetAt($key, self::NOW));
        self::assertSame(20, $root->e1Keyspace()->peekBudgetAt($key, self::NOW)?->count);
    }

    public function testTheClosedGateReadsAccountHistoryThroughThePublicRuntime(): void
    {
        $root = new RedisFullCapabilityStore($this->admin, self::NAMESPACE);
        $key = (new E1AccountScopeKeyDeriver(self::ENV))->derive('aux.anti_equilibrium', self::POLICY, self::ACCOUNT, self::SECRET);
        for ($i = 0; $i < 3; $i++) {
            $root->e1Keyspace()->incrementWatchFlagAt($key, 21600, self::NOW);
        }
        $result = $this->runtime($this->admin)->limit($this->context(), RateLimitCommand::recordFailure(self::POLICY));
        self::assertSame('HARD_BLOCK', $result->decision, 'A root-bound Gate would lose the account history.');
        self::assertSame(0, $root->getWatchFlag($key));
        self::assertSame(3, $root->e1Keyspace()->getWatchFlag($key));
    }

    public function testRotationWritesOnlyToCurrentAndNeverReadsAV2KeyAsPrevious(): void
    {
        $previous = new RateLimiterConfig('previous_secret', 'previous_fp', self::ENV);
        $rotated = new RateLimiterConfig(self::SECRET, 'test_fp', self::ENV, 'previous_secret', 'previous_fp');
        $store = new RedisFullCapabilityStore($this->admin, self::NAMESPACE);
        $make = static fn(RateLimiterConfig $config): CompositeRateLimiterRuntimeInterface => RateLimiterBuilder::fromFullCapabilityStore($config, $store, new RecordingFailureSignalEmitter())
            ->withClock(new FixedClock('@' . self::NOW))->build();

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
        $this->builder($this->admin, $store)->build();

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

    public function testComposingOverAStoreWithoutTheCapabilityFailsBeforeCommands(): void
    {
        $store = $this->createStub(\Maatify\RateLimiter\Tests\Support\FullCapability\NonE1FullStoreInterface::class);
        $builder = RateLimiterBuilder::fromFullCapabilityStore($this->config(), $store, new RecordingFailureSignalEmitter());
        foreach ([static fn() => $builder->build(), static fn() => $builder->buildOperationalReader()] as $compose) {
            try {
                $compose();
                self::fail('The missing account capability must fail composition.');
            } catch (RateLimiterException $exception) {
                self::assertStringContainsString('E1KeyspaceStoreInterface', $exception->getMessage());
            }
        }
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
        $runtime = $this->runtime($this->recording($commits));

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
                        $logical = \Maatify\RateLimiter\Tests\Support\NonAccountKeyOracle::k1(self::POLICY, self::ENV, \Maatify\RateLimiter\Service\SourcePrefix::of('192.0.2.77', 64), self::SECRET);
                        $rival->incrementAt($logical, 86400, 1, self::NOW);
                    }
                    $snapshots['before'] = $this->snapshot();
                    $result = $this->admin->execute($command);
                    $snapshots['after'] = $this->snapshot();

                    return $result;
                }

                return $this->admin->execute($command);
            });
            $this->failures($this->runtime($executor));

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
        $runtime = $this->runtime($this->admin);
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
            })));
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
            $this->failures($this->runtime($noHset));
            self::fail('A missing write permission refuses the save.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('NOPERM request commit', $exception->getMessage());
        }
        self::assertSame($before, $this->snapshot(), 'Both layouts are unchanged.');

        // The same request succeeds once the grant is complete.
        $complete = $this->userExecutor('e1-complete', [], ['~maatify:rate-limiter:*', '~']);
        $this->failures($this->runtime($complete));
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

    public function testBothBuildOrdersAlwaysAgreeOnE1WithoutASelector(): void
    {
        foreach ([false, true] as $readerFirst) {
            $this->admin->execute(['FLUSHDB']);
            $builder = $this->builder($this->admin);
            $reader = $readerFirst ? $builder->buildOperationalReader() : null;
            $runtime = $builder->build();
            $reader ??= $builder->buildOperationalReader();
            $this->failures($runtime);
            self::assertNotNull($reader->readScorePolicy($this->context(), self::POLICY)->scopes->k4?->score);
            self::assertFalse(in_array('withKeyScopeEncoding', get_class_methods($builder), true));
        }
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
                    $this->builder($this->admin, $store)->build();
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

    /**
     * Millisecond fixtures and contemporary Redis epochs are exact doubles on PHP32/64. No native-int epoch cast
     * or float outside the exact-integer range is accepted by this oracle.
     */
    private static function exactMilliseconds(string $milliseconds): float
    {
        self::assertMatchesRegularExpression('/\A(?:0|[1-9][0-9]{0,15})\z/D', $milliseconds);
        $value = (float) $milliseconds;
        self::assertLessThanOrEqual(9007199254740991.0, $value);

        return $value;
    }

    /**
     * The relative deadline is bounded by independent Redis TIME samples immediately around its creating command.
     * A backwards clock, fractional sample or out-of-range calculation fails rather than enlarging the window.
     *
     * @return array{float, float}
     */
    private static function relativeLifetimeBounds(float $before, float $after, int $ttlSeconds): array
    {
        self::assertSame(floor($before), $before);
        self::assertSame(floor($after), $after);
        self::assertGreaterThanOrEqual(0.0, $before);
        self::assertGreaterThanOrEqual($before, $after, 'Redis time must not move backwards.');
        self::assertGreaterThan(0, $ttlSeconds);
        $lower = $before + $ttlSeconds * 1000.0;
        $upper = $after + $ttlSeconds * 1000.0;
        self::assertLessThanOrEqual(9007199254740991.0, $upper);

        return [$lower, $upper];
    }

    /**
     * Check absent (-2), persistent (-1), or live physical lifetime without rounding. Equal live bounds express an
     * exact retained/absolute reference, never an expiry measured from the very result being judged.
     *
     * @param array{float, float} $bounds
     */
    private static function assertPhysicalLifetime(string $actual, array $bounds): void
    {
        [$lower, $upper] = $bounds;
        self::assertGreaterThanOrEqual($lower, $upper);
        if ($lower === -2.0 || $lower === -1.0) {
            self::assertSame($lower, $upper);
            self::assertSame($lower === -2.0 ? '-2' : '-1', $actual);

            return;
        }
        $expiry = self::exactMilliseconds($actual);
        self::assertGreaterThanOrEqual($lower, $expiry, 'Physical expiry precedes its operation-specific reference.');
        self::assertLessThanOrEqual($upper, $expiry, 'Physical expiry exceeds its operation-specific reference.');
    }

    /**
     * DEC-040's legacy read maps remaining Redis life to the caller's clock. At the read instant t in [before,after],
     * PTTL is in [physicalExpiry-after,physicalExpiry-before]. The DTO is never an input to these expected bounds.
     *
     * @return array{int, int}
     */
    private static function legacyReadBounds(string $callerNowMs, string $physicalExpiry, float $before, float $after): array
    {
        self::assertSame(floor($before), $before);
        self::assertSame(floor($after), $after);
        self::assertGreaterThanOrEqual(0.0, $before);
        self::assertGreaterThanOrEqual($before, $after, 'The read window must be ordered.');
        $expiry = self::exactMilliseconds($physicalExpiry);
        $caller = self::exactMilliseconds($callerNowMs);
        self::assertGreaterThan($after, $expiry, 'The fixture must stay live for the entire read window.');
        $latest = $caller + ($expiry - $before);
        self::assertLessThanOrEqual(9007199254740991.0, $latest);
        $lower = ceil(($caller + ($expiry - $after)) / 1000.0);
        $upper = ceil($latest / 1000.0);
        self::assertGreaterThanOrEqual(0.0, $lower);
        self::assertLessThanOrEqual((float) PHP_INT_MAX, $upper);

        return [(int) $lower, (int) $upper];
    }

    /** @param array{int, int} $bounds */
    private static function assertLegacyReadExpiry(int $actual, array $bounds): void
    {
        self::assertGreaterThanOrEqual($bounds[0], $actual, 'Legacy expiry is below caller time plus the minimum possible PTTL.');
        self::assertLessThanOrEqual($bounds[1], $actual, 'Legacy expiry is above caller time plus the maximum possible PTTL.');
    }

    /**
     * Runs the original operations unchanged. Each command gets its own TIME bracket through an oracle connection
     * separate from the tested executor. Every physical key is checked after every operation: only the declared new
     * or renewed lifetimes may move; the rest retain exact expiry. Read-only commands retain DUMP as well.
     *
     * @param ?\Closure(int): void $afterCommand isolated negative-control hook after the real command; absent in parity runs
     * @return array{list<string>, array<string, list<string>>} semantic results and sorted exact DUMPs per family;
     * independent live expiries are validated against their own references before being left out of cross-run parity
     */
    private function layoutRun(bool $e1, ?\Closure $afterCommand = null): array
    {
        $this->admin->execute(['FLUSHDB']);
        if ($e1) {
            $current = E1AccountScopeKeyDeriver::key(self::SECRET, ['k4', self::POLICY, self::ENV, self::ACCOUNT]);
            $previous = E1AccountScopeKeyDeriver::key('previous_secret', ['k4', self::POLICY, self::ENV, self::ACCOUNT]);
            $life = E1AccountScopeKeyDeriver::key(self::SECRET, ['k4p', self::POLICY, self::ENV, self::ACCOUNT]);
            $prefix = self::e1Prefix();
        } else {
            $current = hash_hmac('sha256', 'k4|current', self::SECRET);
            $previous = hash_hmac('sha256', 'k4|previous', 'previous_secret');
            $life = hash_hmac('sha256', 'k4p|current', self::SECRET);
            $prefix = self::v1Prefix();
        }
        $key = static fn(string $family, string $logical): string => $prefix . $family . ':' . hash('sha256', $logical);
        // References come from the fixture's API contracts, not TTLs read from the command's result or ARGV.
        // Hard-block cycle/pause retention is 3600 / (60+3600); the claim has 600-61 seconds of logical life.
        $lifetimes = [
            [$key('score', $current) => 600],
            [$key('block', $current) => 600],
            [$key('budget', $current) => 86400],
            [$key('watch', $current) => 600, $key('watch-meta', $current) => 600],
            [$key('block', $current) => 60, $key('cycle', $current) => 3600, $key('pause', $current) => 3660],
            [],
            [$key('score', $life) => 600],
            [$key('block', $life) => 60, $key('cycle', $life) => 3600, $key('pause', $life) => 3660],
            [$key('reentry-claim', $life) => 539],
            [], [], [], [],
        ];
        $readOnly = [5, 10, 11, 12];
        $commandIndex = 0;
        $legacyBounds = null;
        $wire = new RespRedisCommandExecutor($this->host, $this->port);
        $executor = new CallableRedisCommandExecutor(function (array $command) use ($wire, $lifetimes, $readOnly, $key, $current, $afterCommand, &$commandIndex, &$legacyBounds): mixed {
            self::assertArrayHasKey($commandIndex, $lifetimes, 'The original fixture sends exactly the declared operations.');
            $prior = $this->snapshot(true);
            $before = $this->redisTimeMilliseconds();
            $reply = $wire->execute($command);
            $afterCommand?->__invoke($commandIndex);
            $after = $this->redisTimeMilliseconds();
            self::assertGreaterThanOrEqual($before, $after, 'Redis time must not move backwards.');
            $actual = $this->snapshot(true);
            $expectedKeys = array_unique([...array_keys($prior), ...array_keys($lifetimes[$commandIndex])]);
            sort($expectedKeys);
            self::assertSame($expectedKeys, array_keys($actual), 'No undeclared, missing or Previous key may appear.');
            foreach ($actual as $physical => [, $expiry]) {
                if (isset($lifetimes[$commandIndex][$physical])) {
                    $bounds = self::relativeLifetimeBounds($before, $after, $lifetimes[$commandIndex][$physical]);
                } else {
                    self::assertArrayHasKey($physical, $prior);
                    $retained = self::exactMilliseconds($prior[$physical][1]);
                    $bounds = [$retained, $retained];
                }
                self::assertPhysicalLifetime($expiry, $bounds);
                self::assertGreaterThan($after, self::exactMilliseconds($expiry), 'The physical fixture remains live.');
            }
            if (in_array($commandIndex, $readOnly, true)) {
                self::assertSame($prior, $actual, 'A read preserves every DUMP and exact physical expiry.');
            }
            if ($commandIndex === 10) {
                $legacyBounds = self::legacyReadBounds(self::NOW . '000', $prior[$key('score', $current)][1], $before, $after);
            }
            $commandIndex++;

            return $reply;
        });
        $store = new RedisFullCapabilityStore($executor, self::NAMESPACE);
        $target = $e1 ? $store->e1Keyspace() : $store;
        // E1 holds no distinct family. Instrumentation above does not alter everyFamily's operations or other caller.
        $returned = $this->everyFamily($target, $current, $life, $previous, false);
        $physicalScore = $key('score', $current);
        $legacyFields = array_keys($this->rawHash($physicalScore));
        sort($legacyFields);
        self::assertSame(['updatedAt', 'value'], $legacyFields, 'The read fixture is legacy, without generation or logical expiresAt.');
        $state = $target->readGenerationBoundScoreStateAtMilliseconds($current, $previous, self::NOW . '000');
        self::assertNotNull($state);
        self::assertNotNull($legacyBounds);
        self::assertLegacyReadExpiry($state->expiresAt, $legacyBounds);
        self::assertSame('current', $state->source);
        self::assertSame(1, $state->value);
        self::assertSame(self::NOW, $state->updatedAt);
        self::assertNull($state->generation);
        self::assertNull($state->postPunishmentReentry);
        $semantic = $state->jsonSerialize();
        self::assertIsArray($semantic);
        // The only derived field is already checked independently, including its read-only boundary and source selection.
        $semantic['expiresAt'] = 'validated caller-time plus independently bounded PTTL';
        $returned[] = print_r($semantic, true);
        $returned[] = print_r($target->checkBlockAt($current, self::NOW + 1), true);
        $returned[] = print_r($target->peekBudgetAt($current, self::NOW + 1), true);
        self::assertSame(13, $commandIndex, 'All per-operation lifetime references were exercised.');

        $final = $this->snapshot(true);
        $expectedFinalKeys = [];
        foreach ($lifetimes as $declared) {
            $expectedFinalKeys = array_unique([...$expectedFinalKeys, ...array_keys($declared)]);
        }
        sort($expectedFinalKeys);
        self::assertSame($expectedFinalKeys, array_keys($final), 'The final complete inventory contains only declared keys.');
        if ($prefix === '') {
            self::fail('A layout prefix must not accept every key.');
        }
        $values = [];
        foreach ($final as $physical => [$dump]) {
            self::assertStringStartsWith($prefix, $physical, 'Every inventoried key belongs to the selected layout.');
            $family = explode(':', substr($physical, strlen($prefix)))[0];
            $values[$family][] = $dump;
        }
        ksort($values);
        foreach ($values as &$list) {
            sort($list);
        }

        return [$returned, $values];
    }

    /** Prove the same production-facing oracle accepts valid boundary pairs and refuses concrete wrong lifetimes. */
    public function testLayoutTimeOraclesAcceptCorrectBoundaryPairsAndRejectWrongLifetimes(): void
    {
        // With the SAME physical expiry, a one-ms advance crosses the legacy ceil boundary. Both answers are correct.
        $first = self::legacyReadBounds(self::NOW . '000', '1800000600001', 1800000000000.0, 1800000000000.0);
        $second = self::legacyReadBounds(self::NOW . '000', '1800000600001', 1800000000001.0, 1800000000001.0);
        self::assertSame([1793794201, 1793794201], $first);
        self::assertSame([1793794200, 1793794200], $second);
        self::assertLegacyReadExpiry(1793794201, $first);
        self::assertLegacyReadExpiry(1793794200, $second);
        self::assertNotSame(1793794201, 1793794200, 'The former cross-run exact comparison rejects this correct pair.');

        // Independent operations straddle the old minute-rounding boundary; each has exactly its contractual 600-s TTL.
        $left = self::relativeLifetimeBounds(1800000029999.0, 1800000029999.0, 600);
        $right = self::relativeLifetimeBounds(1800000030001.0, 1800000030001.0, 600);
        self::assertSame([1800000629999.0, 1800000629999.0], $left);
        self::assertSame([1800000630001.0, 1800000630001.0], $right);
        self::assertPhysicalLifetime('1800000629999', $left);
        self::assertPhysicalLifetime('1800000630001', $right);
        self::assertNotSame(round(1800000629999.0 / 60000), round(1800000630001.0 / 60000), 'Old minute rounding rejects two correct relative deadlines.');
        self::assertPhysicalLifetime('-2', [-2.0, -2.0]);
        self::assertPhysicalLifetime('-1', [-1.0, -1.0]);

        $wrong = [
            'legacy ceil below range' => static fn() => self::assertLegacyReadExpiry(1793794200, $first),
            'legacy ceil above range' => static fn() => self::assertLegacyReadExpiry(1793794202, $first),
            'relative TTL one ms short' => static fn() => self::assertPhysicalLifetime('1800000629998', $left),
            'relative TTL one ms long' => static fn() => self::assertPhysicalLifetime('1800000630000', $left),
            'lost expiry' => static fn() => self::assertPhysicalLifetime('-1', $left),
            'lost key' => static fn() => self::assertPhysicalLifetime('-2', $left),
            'unapproved renewal of retained expiry' => static fn() => self::assertPhysicalLifetime('1800000630000', [1800000629999.0, 1800000629999.0]),
            'persistent turned live' => static fn() => self::assertPhysicalLifetime('1800000629999', [-1.0, -1.0]),
            'absent turned persistent' => static fn() => self::assertPhysicalLifetime('-1', [-2.0, -2.0]),
            'backwards operation clock' => static fn() => self::relativeLifetimeBounds(1800000000001.0, 1800000000000.0, 600),
            'backwards read clock' => static fn() => self::legacyReadBounds(self::NOW . '000', '1800000600001', 1800000000001.0, 1800000000000.0),
            'expired during read' => static fn() => self::legacyReadBounds(self::NOW . '000', '1800000600001', 1800000600000.0, 1800000600001.0),
        ];
        foreach ($wrong as $name => $check) {
            $rejected = false;
            try {
                $check();
            } catch (\PHPUnit\Framework\AssertionFailedError) {
                $rejected = true;
            }
            self::assertTrue($rejected, 'The actual oracle must reject: ' . $name);
        }
        self::assertCount(12, $wrong, 'Every negative control executed without a skip or timing retry.');
    }

    /** The strict layout path must reject an undeclared circuit key even during a read-only operation, in either layout. */
    public function testLayoutInventoryRejectsAnUndeclaredCircuitKeyOutsideItsPrefix(): void
    {
        $sentinel = 'unexpected:circuit:sentinel';
        foreach ([false, true] as $e1) {
            $injections = 0;
            $failure = null;
            $filtered = [];
            $complete = [];
            try {
                $this->layoutRun($e1, function (int $commandIndex) use ($sentinel, &$injections): void {
                    if ($commandIndex === 5) {
                        // The real readDecayPauseState just returned; no store write or TTL fixture is substituted.
                        $this->admin->execute(['SET', $sentinel, 'unauthorized-key']);
                        $injections++;
                    }
                });
            } catch (\PHPUnit\Framework\ExpectationFailedException $exception) {
                $failure = $exception;
                $filtered = $this->snapshot();
                $complete = $this->snapshot(true);
            } finally {
                $this->admin->execute(['DEL', $sentinel]);
            }
            self::assertSame(1, $injections, 'The sentinel was actually inserted after the read in this layout.');
            self::assertInstanceOf(\PHPUnit\Framework\ExpectationFailedException::class, $failure);
            self::assertStringContainsString('No undeclared, missing or Previous key may appear.', $failure->getMessage());
            $comparison = $failure->getComparisonFailure();
            self::assertNotNull($comparison, 'The rejection came from the actual key-set comparison, before TTL checks.');
            $expectedKeys = $comparison->getExpected();
            $actualKeys = $comparison->getActual();
            self::assertIsArray($expectedKeys);
            self::assertIsArray($actualKeys);
            self::assertNotContains($sentinel, $expectedKeys);
            self::assertContains($sentinel, $actualKeys);
            $expectedWithSentinel = [...$expectedKeys, $sentinel];
            sort($expectedWithSentinel);
            self::assertSame($expectedWithSentinel, $actualKeys, 'The sentinel is the sole inventory mismatch.');
            self::assertArrayNotHasKey($sentinel, $filtered, 'The default snapshot semantics used by other tests are preserved.');
            self::assertSame('-1', $complete[$sentinel][1], 'The key-set assertion rejected the key before any live-TTL assertion.');
            self::assertNotContains($sentinel, $this->keys(), 'The isolated control removes its sentinel.');
        }
    }

    public function testEveryE1FamilyBehavesLikeItsV1CounterpartOnTheRealStoreIncludingLifecycleAndRotation(): void
    {
        [$v1Returned, $v1Values] = $this->layoutRun(false);
        [$e1Returned, $e1Values] = $this->layoutRun(true);

        self::assertSame(['block', 'budget', 'cycle', 'pause', 'reentry-claim', 'score', 'watch', 'watch-meta'], array_keys($e1Values), 'All eight E1 families are written, in the E1 prefix.');
        self::assertSame(array_keys($v1Values), array_keys($e1Values));
        self::assertSame($v1Returned, $e1Returned, 'Every call has the same semantic result after independent legacy-expiry validation.');
        self::assertSame($v1Values, $e1Values, 'Every family stores byte-identical content; each physical lifetime was already checked against its own operation reference.');
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
                $this->failures($this->runtime($executor));
                self::fail("{$label}: the save must be refused.");
            } catch (\Throwable $exception) {
                self::assertStringContainsString('NOPERM', $exception->getMessage(), $label);
            }
            self::assertNotSame([], $commits, "{$label}: the request reached the commit (the save guard, not the entry guard).");
            self::assertSame($before, $this->snapshot(), "{$label}: both layouts are unchanged.");
        }
    }

    // ---------------------------------------------------------------------------------------------------------------- round 3: F295-02 (failed build) and F295-03 (lifecycle through the real composition)

    public function testAFailedProviderBuildCanBeCorrectedForRuntimeAndReader(): void
    {
        $bound = new StubContextProvider(static fn(): int => 1);
        $other = new StubContextProvider(static fn(): int => 2);
        foreach (['build', 'buildOperationalReader'] as $method) {
            $store = new RedisFullCapabilityStore($this->admin, self::NAMESPACE, null, 500, $bound);
            $builder = $this->builder($this->admin, $store)->withExecutionContextProvider($other);
            try {
                $builder->{$method}();
                self::fail('Provider mismatch must fail.');
            } catch (RateLimiterException $exception) {
                self::assertStringContainsString('domain', $exception->getMessage());
            }
            $builder->withExecutionContextProvider($bound);
            $builder->{$method}();
        }
    }

    public function testAFailingOuterBuildPreservesTheNestedSuccessfulReader(): void
    {
        $builder = $this->builder($this->admin);
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

        $this->failures($builder->build());
        self::assertNotNull($sink['reader']->readScorePolicy($this->context(), self::POLICY)->scopes->k4?->score);
    }



    public function testACancelledFiberBuildLeavesTheBuilderAndReaderUsable(): void
    {
        foreach (['build' => static fn(RateLimiterBuilder $b) => $b->build(), 'reader' => static fn(RateLimiterBuilder $b) => $b->buildOperationalReader()] as $kind => $compose) {
            $builder = $this->builder($this->admin);
            /** @var \ArrayObject<string, mixed> $sink */
            $sink = new \ArrayObject();
            // A Host getter that suspends the first build it is called from, inside the build.
            $policy = new class ($sink) extends \Maatify\RateLimiter\Config\LoginProtectionPolicy {
                /** @param \ArrayObject<string, mixed> $sink */
                public function __construct(private \ArrayObject $sink) {}

                public function getName(): string
                {
                    $this->suspendOnce();

                    return 'suspending_policy';
                }

                public function getCapabilities(): array
                {
                    $this->suspendOnce();

                    return parent::getCapabilities();
                }

                private function suspendOnce(): void
                {
                    if (isset($this->sink['armed']) && ! isset($this->sink['suspended'])) {
                        $this->sink['suspended'] = true;
                        \Fiber::suspend();
                    }
                }
            };
            $builder->withPolicy($policy);

            $sink['armed'] = true;
            $fiber = new \Fiber(static function () use ($builder, $compose): void {
                $compose($builder);
            });
            $fiber->start();
            self::assertTrue($fiber->isSuspended(), "{$kind}: the build is suspended inside the Host getter.");

            $weak = \WeakReference::create($fiber);
            unset($fiber);
            gc_collect_cycles();
            self::assertNull($weak->get(), "{$kind}: the suspended Fiber is destroyed.");

            // The attempt ended without succeeding: the selection is free and the build works under the new choice.
            $compose($builder);
            $builder->buildOperationalReader();
        }
    }

    /** Whether the builder refuses the selection (the exception is released here, so it does not keep a suspended Fiber's frames alive). */


    /**
     * @return array{claimed: bool, again: bool, e1: list<string>, v1: list<string>}
     */
    private function lifecycleThroughTheRealComposition(bool $rotated): array
    {
        $this->admin->execute(['FLUSHDB']);
        $clock = new FixedClock('@' . self::NOW);
        $store = new RedisFullCapabilityStore($this->admin, self::NAMESPACE);
        $make = function (RateLimiterConfig $config) use ($store, $clock): CompositeRateLimiterRuntimeInterface {
            return RateLimiterBuilder::fromFullCapabilityStore($config, $store, new RecordingFailureSignalEmitter())->withClock($clock)->build();
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

    public function testLifecycleAndRotationThroughTheRealCompositionReachE1(): void
    {
        foreach ([false, true] as $rotated) {
            $label = $rotated ? 'after a rotation' : 'one secret';
            $e1 = $this->lifecycleThroughTheRealComposition($rotated);

            self::assertContains('reentry-claim', $e1['e1'], "E1 {$label}: the claim marker is under the E1 prefix.");
            self::assertSame([], $e1['v1'], "E1 {$label}: no claim marker is under v1 (the claim was not routed to the V2 layout).");
            self::assertSame(['budget', 'cycle', 'reentry-claim', 'score', 'watch', 'watch-meta'], $e1['e1'], "E1 {$label}: every account-scope family the lifecycle touched is under the E1 prefix.");
            self::assertTrue($e1['claimed']);
            self::assertFalse($e1['again'], "{$label}: the marker is one-shot in E1.");
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
    private function budgetRotationRun(RateLimiterConfig $previousGeneration, RateLimiterConfig $rotated): array
    {
        $this->admin->execute(['FLUSHDB']);
        $store = new RedisFullCapabilityStore($this->admin, self::NAMESPACE);
        $make = static fn(RateLimiterConfig $config): CompositeRateLimiterRuntimeInterface => RateLimiterBuilder::fromFullCapabilityStore($config, $store, new RecordingFailureSignalEmitter())
            ->withClock(new FixedClock('@' . self::NOW))->build();

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

    public function testBudgetRotationSeedsCurrentFromPreviousThroughTheSoleE1Runtime(): void
    {
        $scenarios = [
            'secret rotation' => [new RateLimiterConfig('previous_secret', 'previous_fp', self::ENV), new RateLimiterConfig(self::SECRET, 'test_fp', self::ENV, 'previous_secret', 'previous_fp')],
            'device generation rotation' => [new RateLimiterConfig(self::SECRET, 'previous_fp', self::ENV), new RateLimiterConfig(self::SECRET, 'test_fp', self::ENV, null, 'previous_fp')],
        ];
        foreach ($scenarios as $label => [$previousGeneration, $rotated]) {
            $shapes = [];
            foreach ([true] as $encoding) {
                $run = $this->budgetRotationRun($previousGeneration, $rotated);
                $before = $run['before'];
                $after = $run['after'];
                $new = array_diff_key($after, $before);
                $name = "{$label} under E1";

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
                $wrong = self::v1Prefix();
                $right = self::e1Prefix();
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
                $shapes['e1'] = array_values($after);
                sort($shapes['e1']);
            }
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

    /**
     * @return array<string, string>
     */
    private function rawHash(string $physicalKey): array
    {
        $raw = $this->admin->execute(['HGETALL', $physicalKey]);
        self::assertIsArray($raw);
        $out = [];
        for ($i = 0, $n = count($raw); $i + 1 < $n; $i += 2) {
            $field = $raw[$i];
            $val = $raw[$i + 1];
            self::assertTrue(is_string($field) || is_int($field));
            self::assertTrue(is_string($val) || is_int($val));
            $out[(string) $field] = (string) $val;
        }

        return $out;
    }

    public function testWarmRootCopyAtomicSaveAndCancelIsolateE1ScoreAndAdmissionState(): void
    {
        $trace = [];
        $traced = new CallableRedisCommandExecutor(function (array $command) use (&$trace): mixed {
            $trace[] = $command;

            return $this->admin->execute($command);
        });
        $original = new RedisFullCapabilityStore($traced, self::NAMESPACE);
        $e1Key = E1AccountScopeKeyDeriver::key(self::SECRET, ['k4', self::POLICY, self::ENV, self::ACCOUNT]);
        $epochKey = 'a1.epoch.' . str_repeat('a', 64);

        // Warm both lazy admission helpers on the idle root before cloning.
        self::assertSame(0, $original->readBindingEpoch($epochKey));
        self::assertSame(
            AdmissionPermitClaimEnum::UNKNOWN,
            $original->claimAdmissionPermitWithOutcome('U', 'subj', 'login', new AdmissionOutcomeDTO('login', AdmissionOutcomeKindEnum::SUCCESS, [], 'p-warm-probe'), self::NOW),
        );

        $copy = clone $original;
        $copyView = $copy->e1Keyspace();
        $before = $this->snapshot();

        // 1. Cancelled transaction on warm copy: E1 score, epoch bump, and reservation must all leave Redis unchanged.
        $cancelScope = new AdmissionScopeDTO('src', AdmissionScopeKindEnum::COUNTER, 'c-warm-cancel', 5, 600, null, 'p-warm-cancel');
        $cancelReq = new AdmissionReservationRequestDTO('login', [$cancelScope], new AdmissionPermitDTO('p-warm-cancel', 'U', 'subj-warm', 'login', self::NOW + 30, 600));
        $sentinel1 = new \RuntimeException('sentinel-warm-cancel');
        $caught1 = null;
        try {
            $copy->transactRequest(function () use ($copy, $copyView, $e1Key, $epochKey, $cancelReq, $sentinel1): int {
                $copyView->incrementAt($e1Key, 600, 1, self::NOW);
                self::assertSame(1, $copyView->get($e1Key)?->value);
                self::assertSame(1, $copy->bumpBindingEpoch($epochKey));
                self::assertSame(1, $copy->readBindingEpoch($epochKey));
                self::assertTrue($copy->reserveAdmission($cancelReq, self::NOW)->admitted);
                // Oracle outside transaction sees nothing yet.
                self::assertSame([], $this->keys());

                throw $sentinel1;
            });
        } catch (\RuntimeException $e) {
            $caught1 = $e;
        }
        self::assertSame($sentinel1, $caught1, 'Exact sentinel exception must escape cancelled transaction');
        self::assertSame($before, $this->snapshot(), 'Cancelled warm-copy transaction must leave Redis DUMP+PEXPIRETIME unchanged');
        self::assertSame(0, $copy->readBindingEpoch($epochKey));

        // 2. Non-cancelled transaction on warm copy: E1 score, epoch bump, and reservation commit atomically in 1 commit.
        $beforeSuccess = $this->snapshot();
        $traceStart = count($trace);
        $commitScope = new AdmissionScopeDTO('src', AdmissionScopeKindEnum::COUNTER, 'c-warm-commit', 5, 600, null, 'p-warm-commit');
        $commitReq = new AdmissionReservationRequestDTO('login', [$commitScope], new AdmissionPermitDTO('p-warm-commit', 'U', 'subj-warm', 'login', self::NOW + 30, 600));
        $copy->transactRequest(function () use ($copy, $copyView, $e1Key, $epochKey, $commitReq, $beforeSuccess): void {
            $copyView->incrementAt($e1Key, 600, 1, self::NOW);
            self::assertSame(1, $copy->bumpBindingEpoch($epochKey));
            self::assertTrue($copy->reserveAdmission($commitReq, self::NOW)->admitted);
            // Independent oracle sees no early writes before commit.
            self::assertSame($beforeSuccess, $this->snapshot());
        });
        $commits = array_values(array_filter(array_slice($trace, $traceStart), static fn(array $cmd): bool => OverlayWire::isCommit($cmd)));
        self::assertCount(1, $commits, 'Overlay must commit once for the transaction');

        $admOps = new \Maatify\RateLimiter\Repository\Redis\RedisAdmissionOperations($this->admin, self::NAMESPACE);
        $physE1Score = self::e1Prefix() . 'score:' . hash('sha256', $e1Key);
        $physEpoch = $admOps->epochKey($epochKey);
        $physPermit = $admOps->permitKey('p-warm-commit');
        $physCounter = $admOps->scopeKey('login', AdmissionScopeKindEnum::COUNTER, 'c-warm-commit');

        $commitKeys = OverlayWire::keys($commits[0]);
        self::assertContains($physE1Score, $commitKeys);
        self::assertContains($physEpoch, $commitKeys);
        self::assertContains($physPermit, $commitKeys);
        self::assertContains($physCounter, $commitKeys);

        $expectedKeys = [$physE1Score, $physEpoch, $physPermit, $physCounter];
        sort($expectedKeys);
        $actualKeys = $this->keys();
        sort($actualKeys);
        self::assertSame($expectedKeys, $actualKeys, 'Oracle must see exactly the four expected physical keys and no extra keys');

        self::assertSame(['value' => '1', 'updatedAt' => (string) self::NOW], $this->rawHash($physE1Score));
        $scorePttl = $this->admin->execute(['PTTL', $physE1Score]);
        self::assertIsInt($scorePttl);
        self::assertGreaterThan(0, $scorePttl);
        self::assertLessThanOrEqual(600_000, $scorePttl);

        self::assertSame('1', $this->admin->execute(['GET', $physEpoch]));

        $permitFields = $this->rawHash($physPermit);
        self::assertSame('ISSUED', $permitFields['state'] ?? null);
        self::assertSame('U', $permitFields['lane'] ?? null);
        self::assertSame('subj-warm', $permitFields['subject'] ?? null);
        self::assertSame('login', $permitFields['operation'] ?? null);
        self::assertSame((string) (self::NOW + 30), $permitFields['deadline'] ?? null);
        self::assertSame('600', $permitFields['grace'] ?? null);
        $permitPttl = $this->admin->execute(['PTTL', $physPermit]);
        self::assertIsInt($permitPttl);
        self::assertGreaterThan(0, $permitPttl);
        self::assertLessThanOrEqual(630_000, $permitPttl);

        self::assertSame(['p-warm-commit'], $this->admin->execute(['ZRANGE', $physCounter, '0', '-1']));
        self::assertSame((string) (self::NOW + 600), $this->admin->execute(['ZSCORE', $physCounter, 'p-warm-commit']));
        $counterPttl = $this->admin->execute(['PTTL', $physCounter]);
        self::assertIsInt($counterPttl);
        self::assertGreaterThan(0, $counterPttl);
        self::assertLessThanOrEqual(600_000, $counterPttl);

        self::assertSame(1, $copyView->get($e1Key)?->value);
        self::assertSame(1, $copy->readBindingEpoch($epochKey));

        // 3. Subsequent independent cancel on the same warm copy leaves the committed state unchanged.
        $afterCommit = $this->snapshot();
        $sentinel2 = new \RuntimeException('sentinel-warm-cancel-2');
        $caught2 = null;
        try {
            $copy->transactRequest(static function () use ($copy, $copyView, $e1Key, $epochKey, $sentinel2): void {
                $copyView->incrementAt($e1Key, 600, 2, self::NOW);
                $copy->bumpBindingEpoch($epochKey);

                throw $sentinel2;
            });
        } catch (\RuntimeException $e) {
            $caught2 = $e;
        }
        self::assertSame($sentinel2, $caught2);
        self::assertSame($afterCommit, $this->snapshot());
        self::assertSame(1, $copy->readBindingEpoch($epochKey));
    }

    public function testActiveRootCopyNeverInheritsOverlayOrBackendFailureState(): void
    {
        $trace = [];
        $backendFailureSentinel = new BackendFailureException('synthetic backend failure');
        $failureSwitch = new class {
            public bool $fail = false;
        };
        $executor = new CallableRedisCommandExecutor(function (array $command) use (&$trace, $failureSwitch, $backendFailureSentinel): mixed {
            $trace[] = $command;
            if ($failureSwitch->fail) {
                throw $backendFailureSentinel;
            }

            return $this->admin->execute($command);
        });
        $original = new RedisFullCapabilityStore($executor, self::NAMESPACE);

        // 1. Clone during active held write, then cancel original: copy must not see stale overlay and commits independently to Redis.
        $copyAfterCancel = null;
        $before = $this->snapshot();
        $sentinelC1 = new \LogicException('sentinel-orig-cancel');
        $caughtC1 = null;
        try {
            $original->transactRequest(function () use ($original, &$copyAfterCancel, $sentinelC1): void {
                $original->incrementAt('active-orig-cancel', 600, 1, self::NOW);
                $copyAfterCancel = clone $original;

                throw $sentinelC1;
            });
        } catch (\Throwable $e) {
            $caughtC1 = $e;
        }
        self::assertSame($sentinelC1, $caughtC1);
        self::assertInstanceOf(RedisFullCapabilityStore::class, $copyAfterCancel);
        self::assertSame($before, $this->snapshot());
        self::assertNull($copyAfterCancel->get('active-orig-cancel'));
        self::assertNotContains(self::v1Prefix() . 'score:' . hash('sha256', 'active-orig-cancel'), $this->keys());

        $traceStartC1 = count($trace);
        $copyAfterCancel->transactRequest(static function () use ($copyAfterCancel): void {
            $copyAfterCancel->incrementAt('copy-independent-commit', 600, 1, self::NOW);
        });
        $c1Commits = array_values(array_filter(array_slice($trace, $traceStartC1), static fn(array $cmd): bool => OverlayWire::isCommit($cmd)));
        self::assertCount(1, $c1Commits);
        $physCopyCommit = self::v1Prefix() . 'score:' . hash('sha256', 'copy-independent-commit');
        self::assertContains($physCopyCommit, OverlayWire::keys($c1Commits[0]));
        self::assertSame(['value' => '1', 'updatedAt' => (string) self::NOW], $this->rawHash($physCopyCommit));
        self::assertNull($copyAfterCancel->get('active-orig-cancel'));

        $snapBeforeCopyCancel = $this->snapshot();
        $sentinelCopyCancel = new \RuntimeException('sentinel-copy-cancel');
        $caughtCopyCancel = null;
        try {
            $copyAfterCancel->transactRequest(static function () use ($copyAfterCancel, $sentinelCopyCancel): void {
                $copyAfterCancel->incrementAt('copy-independent-commit', 600, 2, self::NOW);

                throw $sentinelCopyCancel;
            });
        } catch (\RuntimeException $e) {
            $caughtCopyCancel = $e;
        }
        self::assertSame($sentinelCopyCancel, $caughtCopyCancel);
        self::assertSame($snapBeforeCopyCancel, $this->snapshot());

        // 2. Clone during active held write, then commit original: copy must see real committed state in Redis (not frozen array).
        $copyAfterCommit = null;
        $original->transactRequest(function () use ($original, &$copyAfterCommit): void {
            $original->incrementAt('active-orig-commit', 600, 2, self::NOW);
            $copyAfterCommit = clone $original;
        });
        $physActiveCommit = self::v1Prefix() . 'score:' . hash('sha256', 'active-orig-commit');
        self::assertSame(['value' => '2', 'updatedAt' => (string) self::NOW], $this->rawHash($physActiveCommit));
        self::assertInstanceOf(RedisFullCapabilityStore::class, $copyAfterCommit);

        $traceStartC2 = count($trace);
        $copyAfterCommit->transactRequest(static function () use ($copyAfterCommit): void {
            $copyAfterCommit->incrementAt('active-orig-commit', 600, 3, self::NOW);
        });
        $c2Commits = array_values(array_filter(array_slice($trace, $traceStartC2), static fn(array $cmd): bool => OverlayWire::isCommit($cmd)));
        self::assertCount(1, $c2Commits);
        self::assertContains($physActiveCommit, OverlayWire::keys($c2Commits[0]));
        self::assertSame(['value' => '5', 'updatedAt' => (string) self::NOW], $this->rawHash($physActiveCommit));

        // 3. Backend failure recorded on active original's requestState before clone does not poison the clone.
        $copyAfterFailure = null;
        $beforeFailure = $this->snapshot();
        $caughtFailure = null;
        try {
            $original->transactRequest(function () use ($original, $failureSwitch, $backendFailureSentinel, &$copyAfterFailure): void {
                $original->incrementAt('orig-before-fail', 600, 1, self::NOW);
                $failureSwitch->fail = true;
                $caughtInner = null;
                try {
                    $original->incrementAt('fail-key', 600, 1, self::NOW);
                } catch (BackendFailureException $e) {
                    $caughtInner = $e;
                } finally {
                    $failureSwitch->fail = false;
                }
                self::assertSame($backendFailureSentinel, $caughtInner);
                // requestBackendFailure is now recorded on $original before cloning.
                $copyAfterFailure = clone $original;
            });
        } catch (\Throwable $e) {
            $caughtFailure = $e;
        }
        self::assertSame($backendFailureSentinel, $caughtFailure);
        self::assertSame($beforeFailure, $this->snapshot(), 'Failed original request must leave Redis unchanged');
        self::assertInstanceOf(RedisFullCapabilityStore::class, $copyAfterFailure);
        self::assertNull($copyAfterFailure->get('orig-before-fail'));

        $traceStartC3 = count($trace);
        $copyAfterFailure->transactRequest(static function () use ($copyAfterFailure): void {
            $copyAfterFailure->incrementAt('copy-after-failure', 600, 1, self::NOW);
        });
        $c3Commits = array_values(array_filter(array_slice($trace, $traceStartC3), static fn(array $cmd): bool => OverlayWire::isCommit($cmd)));
        self::assertCount(1, $c3Commits);
        $physCopyAfterFailure = self::v1Prefix() . 'score:' . hash('sha256', 'copy-after-failure');
        self::assertContains($physCopyAfterFailure, OverlayWire::keys($c3Commits[0]));
        self::assertSame(['value' => '1', 'updatedAt' => (string) self::NOW], $this->rawHash($physCopyAfterFailure));
    }

    public function testE1ViewSharingInvariantAcrossRootViewAndCopyOfView(): void
    {
        $trace = [];
        $traced = new CallableRedisCommandExecutor(function (array $command) use (&$trace): mixed {
            $trace[] = $command;

            return $this->admin->execute($command);
        });
        $original = new RedisFullCapabilityStore($traced, self::NAMESPACE);
        $e1Key = E1AccountScopeKeyDeriver::key(self::SECRET, ['k4', self::POLICY, self::ENV, self::ACCOUNT]);
        $epochKey = 'a1.epoch.' . str_repeat('d', 64);

        // Warm admission/outcome helpers on root WITHOUT caching e1View yet.
        self::assertSame(0, $original->readBindingEpoch($epochKey));
        self::assertSame(
            AdmissionPermitClaimEnum::UNKNOWN,
            $original->claimAdmissionPermitWithOutcome('U', 'subj-d', 'login', new AdmissionOutcomeDTO('login', AdmissionOutcomeKindEnum::SUCCESS, [], 'p-d-probe'), self::NOW),
        );

        // 1. First e1Keyspace() created DURING a held request on the warm root, cloned into $viewCopy, then cancelled.
        $before = $this->snapshot();
        $view = null;
        $viewCopy = null;
        $sentinelD = new \RuntimeException('sentinel-view-cancel');
        $caughtD = null;
        $cancelScope = new AdmissionScopeDTO('src', AdmissionScopeKindEnum::COUNTER, 'c-view-cancel', 5, 600, null, 'p-view-cancel');
        $cancelReq = new AdmissionReservationRequestDTO('login', [$cancelScope], new AdmissionPermitDTO('p-view-cancel', 'U', 'subj-d', 'login', self::NOW + 30, 600));
        try {
            $original->transactRequest(function () use ($original, $e1Key, $epochKey, $cancelReq, $before, $sentinelD, &$view, &$viewCopy): void {
                $view = $original->e1Keyspace();
                $viewCopy = clone $view;
                self::assertSame($view, $original->e1Keyspace());
                self::assertNotSame($view, $viewCopy);
                self::assertSame([$original], $viewCopy->ownedResources(), 'Copy of a view must remain bound to the same root');

                // Nested held writes across root, view, and copy of view + warm admission helpers.
                $original->incrementAt('v1-shared-cancel', 600, 1, self::NOW);
                $view->incrementAt($e1Key, 600, 1, self::NOW);
                $viewCopy->incrementAt($e1Key, 600, 2, self::NOW);
                self::assertSame(3, $view->get($e1Key)?->value);
                self::assertSame(1, $viewCopy->bumpBindingEpoch($epochKey));
                self::assertTrue($viewCopy->reserveAdmission($cancelReq, self::NOW)->admitted);
                self::assertSame($before, $this->snapshot(), 'Oracle sees no early writes before commit');

                throw $sentinelD;
            });
        } catch (\RuntimeException $e) {
            $caughtD = $e;
        }
        self::assertSame($sentinelD, $caughtD);
        self::assertSame($before, $this->snapshot());
        self::assertInstanceOf(RedisFullCapabilityStore::class, $view);
        self::assertInstanceOf(RedisFullCapabilityStore::class, $viewCopy);

        // 2. Committing one transaction across root, view, and copy of view saves atomically in 1 commit.
        $traceStart = count($trace);
        $commitScope = new AdmissionScopeDTO('src', AdmissionScopeKindEnum::COUNTER, 'c-view-commit', 5, 600, null, 'p-view-commit');
        $commitReq = new AdmissionReservationRequestDTO('login', [$commitScope], new AdmissionPermitDTO('p-view-commit', 'U', 'subj-d', 'login', self::NOW + 30, 600));
        $original->transactRequest(function () use ($original, $view, $viewCopy, $e1Key, $epochKey, $commitReq, $before): void {
            $original->incrementAt('v1-shared-commit', 600, 1, self::NOW);
            $view->incrementAt($e1Key, 600, 1, self::NOW);
            $viewCopy->incrementAt($e1Key, 600, 2, self::NOW);
            self::assertSame(1, $viewCopy->bumpBindingEpoch($epochKey));
            $reservation = $viewCopy->reserveAdmission($commitReq, self::NOW);
            self::assertInstanceOf(\Maatify\RateLimiter\DTO\AdmissionReservationResultDTO::class, $reservation);
            self::assertTrue($reservation->admitted);
            self::assertSame($before, $this->snapshot());
        });
        $commits = array_values(array_filter(array_slice($trace, $traceStart), static fn(array $cmd): bool => OverlayWire::isCommit($cmd)));
        self::assertCount(1, $commits, 'Root, view, and copy of view must share one atomic commit');

        $admOps = new \Maatify\RateLimiter\Repository\Redis\RedisAdmissionOperations($this->admin, self::NAMESPACE);
        $physV1 = self::v1Prefix() . 'score:' . hash('sha256', 'v1-shared-commit');
        $physE1 = self::e1Prefix() . 'score:' . hash('sha256', $e1Key);
        $physEpoch = $admOps->epochKey($epochKey);
        $physPermit = $admOps->permitKey('p-view-commit');
        $physCounter = $admOps->scopeKey('login', AdmissionScopeKindEnum::COUNTER, 'c-view-commit');
        $commitKeys = OverlayWire::keys($commits[0]);
        self::assertContains($physV1, $commitKeys);
        self::assertContains($physE1, $commitKeys);
        self::assertContains($physEpoch, $commitKeys);
        self::assertContains($physPermit, $commitKeys);
        self::assertContains($physCounter, $commitKeys);

        self::assertSame(['value' => '1', 'updatedAt' => (string) self::NOW], $this->rawHash($physV1));
        self::assertSame(['value' => '3', 'updatedAt' => (string) self::NOW], $this->rawHash($physE1));
        self::assertSame('1', $this->admin->execute(['GET', $physEpoch]));
        self::assertSame('ISSUED', $this->rawHash($physPermit)['state'] ?? null);
        self::assertSame(['p-view-commit'], $this->admin->execute(['ZRANGE', $physCounter, '0', '-1']));
        self::assertSame((string) (self::NOW + 600), $this->admin->execute(['ZSCORE', $physCounter, 'p-view-commit']));

        $rootCopy = clone $original;
        $rootCopyView = $rootCopy->e1Keyspace();
        self::assertSame([$rootCopy], $rootCopyView->ownedResources(), 'View of a root copy must be bound to the root copy');
        self::assertNull($rootCopyView->get('e1.' . str_repeat('b', 64)));
    }
}
