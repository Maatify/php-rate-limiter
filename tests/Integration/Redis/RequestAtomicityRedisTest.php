<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Exception\OverlappingRequestException;
use Maatify\RateLimiter\Exception\RateLimitConcurrencyException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Repository\Redis\RedisRequestOverlay;
use Maatify\RateLimiter\Service\RateLimiterInterface;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\OverlayWire;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * T-5 (#185, PR #205): one evaluation request is one atomic save. Every step of the request sees the results of the
 * earlier ones, nothing reaches Redis until the decision is final, a classified rejection leaves Redis byte for byte
 * as it was, and a request whose read state moved is calculated again from a fresh read only when no operational
 * backend failure was observed during its evaluation. A normal return after an incomplete overlay step cannot save;
 * only a verified conflict in trusted held state can justify recalculation after a thrown callback error (DEC-051).
 *
 * Runtime cases use the public builder; failure-precedence controls use the public store transaction on an isolated Redis.
 */
#[Group('t5-request-atomicity')]
#[Group('php32')]
final class RequestAtomicityRedisTest extends TestCase
{
    private const NOW = 1_793_793_600;

    private const NAMESPACE = 't5-atomic';

    private RespRedisCommandExecutor $executor;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->executor = new RespRedisCommandExecutor($host, (int) $port);
        $this->executor->execute(['FLUSHDB']);
    }

    private function limiter(CallableRedisCommandExecutor|RespRedisCommandExecutor $executor, bool $rotated = false): RateLimiterInterface
    {
        $config = $rotated
            ? new RateLimiterConfig('test_secret', 'test_fp', 'prod', 'previous_secret', 'previous_fp')
            : new RateLimiterConfig('test_secret', 'test_fp', 'prod');

        return RateLimiterBuilder::fromFullCapabilityStore(
            $config,
            new RedisFullCapabilityStore($executor, self::NAMESPACE),
            new RecordingFailureSignalEmitter(),
        )->withClock(new FixedClock('@' . self::NOW))->build();
    }

    private function context(string $account): RateLimitContextDTO
    {
        return new RateLimitContextDTO(ip: '192.0.2.1', ua: 'ua', accountId: $account);
    }

    /**
     * Type, canonical content and absolute expiry of every key.
     *
     * The expiry is the exact `PEXPIRETIME` reply: an `int` when it fits the native integer, else its decimal string (32-bit PHP).
     *
     * @return array<string, array{string, mixed, int|string}>
     */
    private function state(): array
    {
        $keys = $this->executor->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        sort($keys);
        $state = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $type = $this->executor->execute(['TYPE', $key]);
            $type = is_array($type) ? ($type['ok'] ?? '') : $type;
            self::assertIsString($type);
            $content = match ($type) {
                'string' => $this->executor->execute(['GET', $key]),
                'hash' => $this->sortedFlat($this->executor->execute(['HGETALL', $key])),
                'set' => $this->sorted($this->executor->execute(['SMEMBERS', $key])),
                'zset' => $this->executor->execute(['ZRANGE', $key, 0, -1, 'WITHSCORES']),
                default => self::fail('Unexpected key type ' . $type),
            };
            $expiry = $this->executor->execute(['PEXPIRETIME', $key]);
            self::assertTrue(is_int($expiry) || is_string($expiry), 'PEXPIRETIME replies with an exact integer.');
            $state[$key] = [$type, $content, $expiry];
        }

        return $state;
    }

    /**
     * An exact expiry reply as a float for arithmetic: a millisecond epoch value (below 2^53) is exact in a double on every width,
     * so a one-millisecond difference is preserved; the digits are checked so that no larger value is silently rounded.
     */
    private static function milliseconds(int|string $expiry): float
    {
        self::assertMatchesRegularExpression('/\A-?\d{1,15}\z/', (string) $expiry, 'An expiry in milliseconds is exact in a double.');

        return (float) $expiry;
    }

    private function redisNow(): int
    {
        $time = $this->executor->execute(['TIME']);
        self::assertIsArray($time);
        self::assertIsString($time[0]);

        return (int) $time[0];
    }

    /** @return list<string> */
    private function sorted(mixed $values): array
    {
        self::assertIsArray($values);
        $strings = [];
        foreach ($values as $value) {
            self::assertIsString($value);
            $strings[] = $value;
        }
        sort($strings);

        return $strings;
    }

    /** @return array<string, string> */
    private function sortedFlat(mixed $flat): array
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

    /** @param list<int|string|float> $command */
    private static function isOverlayEval(array $command): bool
    {
        return $command[0] === 'EVAL' && is_string($command[1]) && str_contains($command[1], '__ov_result');
    }

    /** @param list<int|string|float> $command */
    private static function isCommit(array $command): bool
    {
        return OverlayWire::isCommit($command);
    }

    /** @param list<int|string|float> $command */
    private static function touchesDistinctMeta(array $command): bool
    {
        foreach (OverlayWire::keys($command) as $key) {
            if (str_contains($key, ':distinct-meta:')) {
                return true;
            }
        }

        return false;
    }

    public function testClassifiedRejectionOfANewAccountLeavesRedisUntouchedAndNeverCallsTheSave(): void
    {
        $command = RateLimitCommand::checkOnly('login_protection');
        $this->limiter($this->executor)->limit($this->context('account-one'), $command);
        $before = $this->state();
        self::assertNotEmpty($before);

        $probe = new class {
            public int $observationsPrepared = 0;
            public int $saves = 0;
            /** @var list<string> */
            public array $outside = [];
        };
        $rejection = new RateLimiterException('malformed bounded metadata');
        $executor = new CallableRedisCommandExecutor(function (array $command) use ($probe, $rejection): mixed {
            if (self::isCommit($command)) {
                $probe->saves++;
            } elseif (! self::isOverlayEval($command) && end($command) !== 'verify') {
                $probe->outside[] = implode(',', OverlayWire::keys($command));
            }
            if (self::isOverlayEval($command) && self::touchesDistinctMeta($command)) {
                // The third bounded observation is rejected, after the earlier ones were prepared.
                if ($probe->observationsPrepared === 2) {
                    throw $rejection;
                }
                $probe->observationsPrepared++;
            }

            return $this->executor->execute($command);
        });

        try {
            $this->limiter($executor)->limit($this->context('account-two'), $command);
            self::fail('The classified rejection must reach the caller.');
        } catch (RateLimiterException $caught) {
            self::assertSame($rejection, $caught);
        }

        self::assertSame(2, $probe->observationsPrepared, 'At least one observation was prepared before the rejection.');
        self::assertSame(0, $probe->saves, 'The save was never called.');
        foreach ($probe->outside as $keys) {
            self::assertStringContainsString(':circuit:', $keys, 'Only the circuit-breaker state read (and the read-only state check of the request) runs outside the held-back evaluation.');
        }
        self::assertSame($before, $this->state(), 'Keys, values and expiry are unchanged.');
    }

    /** @return iterable<string, array{bool}> */
    public static function rotationPaths(): iterable
    {
        yield 'without key rotation' => [false];
        yield 'with key rotation' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rotationPaths')]
    public function testSuccessfulRequestSavesEveryChangeWithItsExpiry(bool $rotated): void
    {
        $saves = 0;
        $executor = new CallableRedisCommandExecutor(function (array $command) use (&$saves): mixed {
            if (self::isCommit($command)) {
                $saves++;
            }

            return $this->executor->execute($command);
        });
        $limiter = $this->limiter($executor, $rotated);
        $result = $limiter->limit($this->context('account-one'), RateLimitCommand::recordFailure('login_protection'));

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertSame(1, $saves, 'The whole request is one save.');

        $state = $this->state();
        $families = [];
        foreach ($state as $key => [$type, $content, $expiry]) {
            $family = explode(':', $key)[4] ?? '';
            $families[$family] = ($families[$family] ?? 0) + 1;
            self::assertGreaterThan($this->redisNow() * 1000.0, self::milliseconds($expiry), $key . ' has an expiry in the future.');
            if ($family === 'distinct-meta') {
                self::assertIsArray($content);
                self::assertIsString($content['expiresAt']);
                // The key lives for its TTL from the Redis clock; the metadata records the same span on the request clock.
                self::assertEqualsWithDelta(
                    floor(self::milliseconds($expiry) / 1000.0) - $this->redisNow(),
                    ((int) $content['expiresAt']) - self::NOW,
                    2,
                    'The metadata expiry matches the key expiry.',
                );
            }
        }
        self::assertGreaterThanOrEqual(2, $families['distinct'] ?? 0, 'Both device-cap scopes are saved.');
        self::assertGreaterThanOrEqual(1, $families['score'] ?? 0, 'The failure scores are saved.');
        self::assertSame($families['distinct'] ?? 0, $families['distinct-meta'] ?? 0, 'Every set has its metadata.');

        if ($rotated) {
            // A second request from the same device now reads the first request's saved state.
            $again = $limiter->limit($this->context('account-one'), RateLimitCommand::checkOnly('login_protection'));
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $again->decision);
        }
    }

    public function testConcurrentValidRequestsRecalculateTheStalePlanWithoutLosingOrRepeatingAnUpdate(): void
    {
        $failure = RateLimitCommand::recordFailure('login_protection');

        // The control: the two requests one after the other, B first, as the recalculated order will be.
        $this->limiter($this->executor)->limit($this->context('account-two'), $failure);
        $this->limiter($this->executor)->limit($this->context('account-one'), $failure);
        $expected = $this->state();
        $this->executor->execute(['FLUSHDB']);

        $probe = new class {
            public bool $competed = false;
            /** @var list<mixed> */
            public array $saves = [];
        };
        $other = null;
        $executor = new CallableRedisCommandExecutor(function (array $command) use ($probe, &$other): mixed {
            if (self::isCommit($command)) {
                if (! $probe->competed) {
                    // Request B completes on the same state before request A saves its plan.
                    $probe->competed = true;
                    $other = $this->limiter($this->executor)->limit($this->context('account-two'), RateLimitCommand::recordFailure('login_protection'));
                }
                $reply = $this->executor->execute($command);
                $probe->saves[] = $reply;

                return $reply;
            }

            return $this->executor->execute($command);
        });

        $result = $this->limiter($executor)->limit($this->context('account-one'), $failure);

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertInstanceOf(RateLimitResultDTO::class, $other);
        self::assertSame([0, 1], $probe->saves, 'The stale plan was rejected and the recalculated one saved.');
        $actual = $this->state();
        self::assertSame(array_keys($expected), array_keys($actual), 'The same keys exist.');
        foreach ($expected as $key => [$type, $content, $expiry]) {
            self::assertSame($type, $actual[$key][0], $key);
            self::assertSame($content, $actual[$key][1], 'No update was lost and none was applied twice: ' . $key);
            self::assertEqualsWithDelta(self::milliseconds($expiry), self::milliseconds($actual[$key][2]), 5000.0, 'Expiry of ' . $key);
        }
    }

    public function testACommandTheOverlayDoesNotSupportIsRejectedBeforeAnythingIsSaved(): void
    {
        $store = new RedisFullCapabilityStore($this->executor, self::NAMESPACE);
        $store->incrementAt('existing', 60, 1, self::NOW);
        $before = $this->state();
        $eval = new \ReflectionMethod($store, 'eval');

        try {
            $store->transactRequest(function () use ($store, $eval): void {
                $store->incrementAt('first-write', 60, 1, self::NOW);
                $eval->invoke($store, "return redis.call('HINCRBYFLOAT', KEYS[1], 'a', '1.5')", ['unsupported-command-key'], []);
            });
            self::fail('The unsupported command must be rejected.');
        } catch (RateLimiterException $caught) {
            self::assertStringContainsString('does not support command HINCRBYFLOAT', $caught->getMessage());
        }

        self::assertSame($before, $this->state(), 'The write held back before the rejection never reached Redis.');
    }

    public function testTheCommitChecksTheWholePlanBeforeItsFirstWrite(): void
    {
        $valid = json_encode(['t' => 's', 'v' => 'written', 'o' => 'n', 'd' => 1], JSON_THROW_ON_ERROR);
        $malformed = json_encode(['t' => 'h', 'v' => 'not-a-hash', 'o' => 'n', 'd' => 1], JSON_THROW_ON_ERROR);
        $plan = json_encode([['plan-first', $valid], ['plan-second', $malformed]], JSON_THROW_ON_ERROR);

        try {
            $this->executor->execute(['EVAL', RedisRequestOverlay::commitScript(), 2, 'plan-first', 'plan-second', $plan]);
            self::fail('A malformed plan must be rejected.');
        } catch (RateLimiterException $caught) {
            self::assertStringContainsString('request commit: malformed plan', $caught->getMessage());
        }

        self::assertSame([], $this->state(), 'The valid entry that precedes the malformed one was not written.');
    }

    /**
     * An executor that refuses every save the way a competing request would: the commit finds the state moved.
     *
     * @return array{CallableRedisCommandExecutor, object{commits: int}}
     */
    private function alwaysConflicting(): array
    {
        $probe = new class {
            public int $commits = 0;
        };
        $executor = new CallableRedisCommandExecutor(function (array $command) use ($probe): mixed {
            if (OverlayWire::isCommit($command)) {
                $probe->commits++;

                return 0;
            }
            return $this->executor->execute($command);
        });

        return [$executor, $probe];
    }

    public function testARequestThatKeepsConflictingStopsWithinItsRetryBudgetAndSavesNothing(): void
    {
        [$executor, $probe] = $this->alwaysConflicting();
        $limiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('test_secret', 'test_fp', 'prod'),
            new RedisFullCapabilityStore($executor, self::NAMESPACE, null, 1),
            new RecordingFailureSignalEmitter(),
        )->withClock(new FixedClock('@' . self::NOW))->build();

        $result = $limiter->limit($this->context('account-one'), RateLimitCommand::checkOnly('login_protection'));

        // DEC-029: exhausted retries are the engine's concurrency answer, never a half-saved request.
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertGreaterThanOrEqual(2, $probe->commits, 'A request always gets a second evaluation.');
        self::assertLessThan(32, $probe->commits, 'A spent budget stops the retries before the attempt limit.');
        self::assertSame([], $this->state(), 'Nothing was saved.');
    }

    public function testARequestThatKeepsConflictingStopsAtTheAttemptLimit(): void
    {
        [$executor, $probe] = $this->alwaysConflicting();
        $store = new RedisFullCapabilityStore($executor, self::NAMESPACE, null, 600_000);

        try {
            $store->transactRequest(static function () use ($store): void {
                $store->incrementAt('conflicting', 60, 1, self::NOW);
            });
            self::fail('The retries must be exhausted.');
        } catch (RateLimitConcurrencyException) {
            self::assertSame(32, $probe->commits);
        }
        self::assertSame([], $this->state());
    }

    /**
     * Exact physical content and expiry through the independent oracle, including absence of every held new key.
     *
     * @return array<string, array{string, int|string}>
     */
    private function exactSnapshot(): array
    {
        $keys = $this->executor->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        sort($keys);
        $snapshot = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $dump = $this->executor->execute(['DUMP', $key]);
            $expiry = $this->executor->execute(['PEXPIRETIME', $key]);
            self::assertIsString($dump);
            self::assertTrue(is_int($expiry) || is_string($expiry));
            $snapshot[$key] = [bin2hex($dump), $expiry];
        }

        return $snapshot;
    }

    /** @return iterable<string, array{string}> */
    public static function failurePrecedenceCases(): iterable
    {
        yield 'A: observed failure then later error over genuinely stale state' => ['backend-stale'];
        yield 'B: observed failure then later error over current state' => ['backend-current'];
        yield 'C: caught backend failures then normal return preserves first identity' => ['backend-return'];
        yield 'C: direct backend failure preserves identity' => ['backend-direct'];
        yield 'C: overlap is refused without verification or retry' => ['overlap'];
        yield 'D: error over current state without backend failure' => ['current'];
        yield 'D: error over genuinely stale state without backend failure' => ['stale'];
    }

    /**
     * F-G4-07: a transient executor outage must not become a successful second evaluation when a later error meets
     * a real rival write. The same wire/oracle detector also protects exception identity and ordinary stale-state retry.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('failurePrecedenceCases')]
    public function testObservedFailurePrecedenceAndOrdinaryStaleStateRetry(string $scenario): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        self::assertIsString($host);
        self::assertIsString($port);
        // Neither the tested connection nor the rival connection is the oracle connection.
        $wire = new RespRedisCommandExecutor($host, (int) $port);
        $rival = new RedisFullCapabilityStore(new RespRedisCommandExecutor($host, (int) $port), self::NAMESPACE);
        $rival->incrementAt('precedence-a', 3600, 10, self::NOW);
        $before = $this->exactSnapshot();
        $postRival = $before;
        $backend = new BackendFailureException('F-G4-07 transient overlay outage');
        $secondBackend = new BackendFailureException('F-G4-07 second overlay outage');
        $later = new \LogicException('F-G4-07 later callback error');
        $overlap = new OverlappingRequestException('F-G4-07 narrow overlap control');
        $hasBackend = str_starts_with($scenario, 'backend-');
        $hasRival = $scenario === 'backend-stale' || $scenario === 'stale';
        $probe = new class {
            public int $evaluations = 0;
            public int $rivals = 0;
            public bool $injected = false;
            public bool $tracingRequest = true;
            /** @var list<mixed> */
            public array $verifies = [];
            /** @var list<mixed> */
            public array $commits = [];
            /** @var list<mixed> */
            public array $followingCommits = [];
            /** @var list<bool> */
            public array $caughtBackendIdentities = [];
            /** @var list<array{int, int, bool}> */
            public array $heldObservations = [];
        };
        $executor = new CallableRedisCommandExecutor(function (array $command) use ($wire, $rival, $probe, $backend, $secondBackend, $hasBackend, $hasRival, &$postRival): mixed {
            $keys = OverlayWire::keys($command);
            $faultKey = 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':score:' . hash('sha256', 'precedence-fault');
            $secondFaultKey = 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':score:' . hash('sha256', 'precedence-second-fault');
            if (self::isOverlayEval($command) && in_array($faultKey, $keys, true) && ! $probe->injected) {
                $probe->injected = true;
                if ($hasRival) {
                    $probe->rivals++;
                    $rival->incrementAt('precedence-a', 3600, 5, self::NOW);
                    $postRival = $this->exactSnapshot();
                }
                if ($hasBackend) {
                    // Fail before the command is sent; evalInRequest records this actual executor exception.
                    throw $backend;
                }
            }
            if (self::isOverlayEval($command) && in_array($secondFaultKey, $keys, true)) {
                throw $secondBackend;
            }
            $reply = $wire->execute($command);
            if (OverlayWire::isScript($command, RedisRequestOverlay::commitScript()) && end($command) === 'verify') {
                $probe->verifies[] = $reply;
            } elseif (self::isCommit($command)) {
                if ($probe->tracingRequest) {
                    $probe->commits[] = $reply;
                } else {
                    $probe->followingCommits[] = $reply;
                }
            }

            return $reply;
        });
        $store = new RedisFullCapabilityStore($executor, self::NAMESPACE);
        $e1 = $store->e1Keyspace();
        $caught = null;
        $returned = null;
        try {
            $returned = $store->transactRequest(function () use ($store, $e1, $probe, $before, $backend, $secondBackend, $later, $overlap, $scenario): string {
                $probe->evaluations++;
                $a = $store->incrementAt('precedence-a', 3600, 2, self::NOW);
                $e = $e1->incrementAt('e1.' . hash('sha256', 'precedence-e1'), 3600, 3, self::NOW);
                $probe->heldObservations[] = [
                    $store->get('precedence-a')->value ?? -1,
                    $e1->get('e1.' . hash('sha256', 'precedence-e1'))->value ?? -1,
                    $a === ($probe->evaluations === 1 ? 12 : 17) && $e === 3
                        && ($probe->evaluations !== 1 || $this->exactSnapshot() === $before),
                ];
                if ($scenario === 'overlap') {
                    throw $overlap;
                }
                try {
                    $store->incrementAt('precedence-fault', 3600, 1, self::NOW);
                } catch (BackendFailureException $failure) {
                    $probe->caughtBackendIdentities[] = $failure === $backend;
                    if ($scenario === 'backend-direct') {
                        throw $failure;
                    }
                    if ($scenario === 'backend-return') {
                        try {
                            $store->incrementAt('precedence-second-fault', 3600, 1, self::NOW);
                        } catch (BackendFailureException $secondFailure) {
                            $probe->caughtBackendIdentities[] = $secondFailure === $secondBackend;
                        }

                        return 'normal return after observed failures';
                    }

                    throw $later;
                }
                if ($probe->evaluations === 1) {
                    throw $later;
                }

                return 'saved second evaluation';
            });
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        // Gather outcome, actual verify/commit replies and exact Redis state BEFORE any rejecting assertion. On BASE,
        // A reports evaluations=2, verify=[0], commit=[1], success, and changed post-rival state in one diagnostic.
        $after = $this->exactSnapshot();
        $ordinaryRetry = $scenario === 'stale';
        $expectedException = match ($scenario) {
            'backend-return', 'backend-direct' => $backend,
            'overlap' => $overlap,
            'stale' => null,
            default => $later,
        };
        $actual = [
            'evaluations' => $probe->evaluations,
            'verifyReplies' => $probe->verifies,
            'commitReplies' => $probe->commits,
            'rivals' => $probe->rivals,
            'returned' => $returned,
            'sameException' => $caught === $expectedException,
            'caughtType' => $caught === null ? null : $caught::class,
            'backendIdentities' => $probe->caughtBackendIdentities,
            'heldObservations' => $probe->heldObservations,
            'postRivalUnchanged' => $after === $postRival,
            'finalScores' => [$rival->get('precedence-a')?->value, $rival->e1Keyspace()->get('e1.' . hash('sha256', 'precedence-e1'))?->value, $rival->get('precedence-fault')?->value],
        ];
        $expected = [
            'evaluations' => $ordinaryRetry ? 2 : 1,
            'verifyReplies' => $ordinaryRetry ? [0] : ($scenario === 'current' ? [1] : []),
            'commitReplies' => $ordinaryRetry ? [1] : [],
            'rivals' => $hasRival ? 1 : 0,
            'returned' => $ordinaryRetry ? 'saved second evaluation' : null,
            'sameException' => true,
            'caughtType' => $expectedException === null ? null : $expectedException::class,
            'backendIdentities' => $scenario === 'backend-return' ? [true, true] : ($hasBackend ? [true] : []),
            'heldObservations' => $ordinaryRetry ? [[12, 3, true], [17, 3, true]] : [[12, 3, true]],
            'postRivalUnchanged' => ! $ordinaryRetry,
            'finalScores' => $ordinaryRetry ? [17, 3, 1] : [$hasRival ? 15 : 10, null, null],
        ];
        self::assertSame($expected, $actual, 'F-G4-07 wire/oracle diagnostic: ' . json_encode($actual, JSON_THROW_ON_ERROR));

        // A new request on the same store and E1 view must obtain ownership, start from fresh state and save once.
        $probe->tracingRequest = false;
        $following = $store->transactRequest(static function () use ($store, $e1): array {
            return [
                $store->incrementAt('precedence-following', 3600, 7, self::NOW),
                $e1->incrementAt('e1.' . hash('sha256', 'precedence-following-e1'), 3600, 11, self::NOW),
            ];
        });
        self::assertSame([7, 11], $following);
        self::assertSame([1], $probe->followingCommits);
        self::assertSame(7, $rival->get('precedence-following')?->value);
        self::assertSame(11, $rival->e1Keyspace()->get('e1.' . hash('sha256', 'precedence-following-e1'))?->value);
        $afterFollowing = $this->exactSnapshot();
        foreach ($after as $key => $physical) {
            self::assertSame($physical, $afterFollowing[$key]);
        }
        self::assertCount(count($after) + 2, $afterFollowing, 'Only the two new requested writes were saved.');
    }

    /** A separate real connection for the tested executor or a rival; the suite executor stays the oracle. */
    private function independentWire(): RespRedisCommandExecutor
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        self::assertIsString($host);
        self::assertIsString($port);

        return new RespRedisCommandExecutor($host, (int) $port);
    }

    private static function physicalScore(string $logical, bool $e1 = false): string
    {
        return 'maatify:rate-limiter:' . ($e1 ? 'e1' : 'v1') . ':' . hash('sha256', self::NAMESPACE) . ':score:' . hash('sha256', $logical);
    }

    /**
     * @param non-empty-list<int|string|float> $command
     * @param list<array{string, list<string>, mixed}> $trace
     */
    private static function traceRequestWire(array $command, mixed $reply, array &$trace): void
    {
        $kind = self::isCommit($command) ? 'commit'
            : (OverlayWire::isScript($command, RedisRequestOverlay::commitScript()) && end($command) === 'verify' ? 'verify' : 'overlay');
        $trace[] = [$kind, OverlayWire::keys($command), OverlayWire::result($command, $reply)];
    }

    /**
     * @param list<array{string, list<string>, mixed}> $trace
     * @return array{verify: list<mixed>, commit: list<mixed>, verifyKeys: list<list<string>>, commitKeys: list<list<string>>}
     */
    private static function requestSaveTrace(array $trace): array
    {
        $summary = ['verify' => [], 'commit' => [], 'verifyKeys' => [], 'commitKeys' => []];
        foreach ($trace as [$kind, $keys, $reply]) {
            if ($kind === 'verify' || $kind === 'commit') {
                $summary[$kind][] = $reply;
                $summary[$kind . 'Keys'][] = $keys;
            }
        }

        return $summary;
    }

    /**
     * Keep successful controls' actual wire and exact-snapshot evidence in CLI logs as well as failure messages.
     * This bounded test-only diagnostic uses STDERR, outside PHPUnit's captured application output.
     *
     * @param array<string, mixed> $evidence
     */
    private static function incompleteDiagnostic(string $case, array $evidence): string
    {
        $message = 'F-G4-09 ' . $case . ' diagnostic: ' . json_encode($evidence, JSON_THROW_ON_ERROR);
        fwrite(STDERR, $message . PHP_EOL);

        return $message;
    }

    /** @return iterable<string, array{string}> */
    public static function incompleteOverflowCases(): iterable
    {
        foreach (['caught-rival', 'caught-no-rival', 'caught-no-write', 'e1-nested', 'first-of-two', 'later-error', 'unhandled',
            'known-conflict', 'unknown-conflict', 'normal-known-conflict', 'backend-return', 'backend-later', 'overlap', 'clone'] as $case) {
            yield $case => [$case];
        }
    }

    /**
     * F-G4-09 A/B/C/E: an actual overflow on an unread B returns no delta. Catching it cannot authorize A's save.
     * A rival runs only after Redis's actual error reply. Diagnostics collect outcome, wire and exact state together.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('incompleteOverflowCases')]
    public function testIncompleteOverflowReadSetCannotAuthorizeASave(string $case): void
    {
        $rival = new RedisFullCapabilityStore($this->independentWire(), self::NAMESPACE);
        $e1 = $case === 'e1-nested';
        $b = $e1 ? 'e1.' . hash('sha256', 'incomplete-b') : 'incomplete-b';
        $rivalB = $e1 ? $rival->e1Keyspace() : $rival;
        $rival->incrementAt('incomplete-a', 3600, 10, self::NOW);
        $rivalB->incrementAt($b, 3600, PHP_INT_MAX, self::NOW);
        if ($case === 'first-of-two') {
            $rival->incrementAt('incomplete-c', 3600, PHP_INT_MAX, self::NOW);
        }
        $expectedState = $this->exactSnapshot();
        $wire = $this->independentWire();
        $probe = new class {
            public int $evaluations = 0;
            public int $rivals = 0;
            public bool $cloneChannel = false;
            /** @var list<\Throwable> */
            public array $errors = [];
            /** @var list<array{string, list<string>, mixed}> */
            public array $trace = [];
            /** @var list<array{string, list<string>, mixed}> */
            public array $cloneTrace = [];
        };
        $backend = new BackendFailureException('incomplete mixed backend sentinel');
        $later = new \LogicException('incomplete later callback sentinel');
        $overlap = new OverlappingRequestException('incomplete overlap control');
        $physicalB = self::physicalScore($b, $e1);
        $hasRival = in_array($case, ['caught-rival', 'e1-nested', 'known-conflict', 'unknown-conflict', 'normal-known-conflict'], true);
        $heldFirst = in_array($case, ['known-conflict', 'unknown-conflict', 'normal-known-conflict'], true);
        $rivalChangesA = $case === 'known-conflict' || $case === 'normal-known-conflict';
        $executor = new CallableRedisCommandExecutor(function (array $command) use ($wire, $rival, $rivalB, $b, $physicalB, $probe, $hasRival, $rivalChangesA, $backend, &$expectedState): mixed {
            $keys = OverlayWire::keys($command);
            if (self::isOverlayEval($command) && in_array(self::physicalScore('incomplete-backend'), $keys, true)) {
                throw $backend;
            }
            try {
                $reply = $wire->execute($command);
            } catch (\Throwable $error) {
                $probe->trace[] = ['error', $keys, $error::class . ': ' . $error->getMessage()];
                $probe->errors[] = $error;
                if ($hasRival && $probe->rivals === 0 && in_array($physicalB, $keys, true)) {
                    $probe->rivals++;
                    $rivalB->incrementAt($b, 3600, -1, self::NOW);
                    if ($rivalChangesA) {
                        $rival->incrementAt('incomplete-a', 3600, 5, self::NOW);
                    }
                    $expectedState = $this->exactSnapshot();
                }

                throw $error;
            }
            if ($probe->cloneChannel) {
                self::traceRequestWire($command, $reply, $probe->cloneTrace);
            } else {
                self::traceRequestWire($command, $reply, $probe->trace);
            }

            return $reply;
        });
        $root = new RedisFullCapabilityStore($executor, self::NAMESPACE);
        $target = $e1 ? $root->e1Keyspace() : $root;
        $clone = null;
        $caughtSteps = [];
        $evaluate = function () use ($root, $target, $b, $case, $heldFirst, $probe, $later, $overlap, &$clone, &$caughtSteps, &$expectedState): string {
            $probe->evaluations++;
            if ($heldFirst) {
                $root->incrementAt('incomplete-a', 3600, 2, self::NOW);
            }
            try {
                $target->incrementAt($b, 3600, 1, self::NOW);
            } catch (\Throwable $first) {
                $caughtSteps[] = $first;
                if ($case === 'first-of-two') {
                    try {
                        $root->incrementAt('incomplete-c', 3600, 1, self::NOW);
                    } catch (\Throwable $second) {
                        $caughtSteps[] = $second;
                    }
                }
                if ($case === 'known-conflict' || $case === 'unknown-conflict' || $case === 'unhandled') {
                    throw $first;
                }
                if ($case === 'clone') {
                    $clone = clone($root);
                    $probe->cloneChannel = true;
                    try {
                        $clone->transactRequest(static fn(): int => $clone->incrementAt('incomplete-clone', 3600, 7, self::NOW));
                    } finally {
                        $probe->cloneChannel = false;
                    }
                    $expectedState = $this->exactSnapshot();
                    $viewCopy = clone($root->e1Keyspace());
                    $viewCopy->transactRequest(static fn(): int => $viewCopy->incrementAt('e1.' . hash('sha256', 'incomplete-cloned-view'), 3600, 3, self::NOW));
                }
                if (! $heldFirst && $case !== 'caught-no-write') {
                    $root->incrementAt('incomplete-a', 3600, 2, self::NOW);
                }
                if ($case === 'backend-return' || $case === 'backend-later') {
                    try {
                        $root->incrementAt('incomplete-backend', 3600, 1, self::NOW);
                    } catch (BackendFailureException $failure) {
                        $caughtSteps[] = $failure;
                    }
                }
                if ($case === 'later-error' || $case === 'backend-later') {
                    throw $later;
                }
                if ($case === 'overlap') {
                    throw $overlap;
                }

                return 'caught incomplete step';
            }

            return 'clean evaluation';
        };
        $caught = null;
        $returned = null;
        try {
            $returned = $root->transactRequest($e1 ? static fn(): mixed => $target->transactRequest($evaluate) : $evaluate);
        } catch (\Throwable $error) {
            $caught = $error;
        }
        $after = $this->exactSnapshot();
        $summary = self::requestSaveTrace($probe->trace);
        $first = $probe->errors[0] ?? null;
        $knownRetry = $case === 'known-conflict';
        $expectedError = match ($case) {
            'known-conflict' => null,
            'backend-return' => $backend,
            'later-error', 'backend-later' => $later,
            'overlap' => $overlap,
            default => $first,
        };
        $expectedVerify = $knownRetry ? [0] : ($case === 'unknown-conflict' || ($case === 'later-error') ? [1] : []);
        $actual = [
            'evaluations' => $probe->evaluations, 'rivals' => $probe->rivals, 'verify' => $summary['verify'], 'commit' => $summary['commit'],
            'returned' => $returned, 'sameError' => $caught === $expectedError, 'stateUnchanged' => $after === $expectedState,
            'A' => $rival->get('incomplete-a')?->value, 'B' => $rivalB->get($b)?->value,
            'firstNativeOverflow' => $first !== null && str_contains($first->getMessage(), 'score integer overflow'),
        ];
        $expected = [
            'evaluations' => $knownRetry ? 2 : 1, 'rivals' => $hasRival ? 1 : 0, 'verify' => $expectedVerify, 'commit' => $knownRetry ? [1] : [],
            'returned' => $knownRetry ? 'clean evaluation' : null, 'sameError' => true, 'stateUnchanged' => ! $knownRetry,
            'A' => $knownRetry ? 17 : ($rivalChangesA ? 15 : 10), 'B' => $knownRetry ? PHP_INT_MAX : ($hasRival ? PHP_INT_MAX - 1 : PHP_INT_MAX),
            'firstNativeOverflow' => true,
        ];
        self::assertSame($expected, $actual, self::incompleteDiagnostic('overflow/' . $case, ['actual' => $actual, 'wire' => $probe->trace, 'expectedSnapshot' => $expectedState, 'afterSnapshot' => $after]));
        self::assertNotNull($first);
        self::assertSame($first, $caughtSteps[0]);
        if ($case === 'first-of-two') {
            self::assertCount(2, $probe->errors);
            self::assertNotSame($probe->errors[0], $probe->errors[1]);
            self::assertSame($probe->errors, $caughtSteps);
        }
        foreach ($summary['verifyKeys'] as $keys) {
            self::assertNotContains($physicalB, $keys, 'Verification has only the trusted earlier read set, not a repaired B read.');
        }
        if ($case === 'clone') {
            self::assertNotNull($clone);
            self::assertSame([1], self::requestSaveTrace($probe->cloneTrace)['commit']);
            self::assertSame(7, $rival->get('incomplete-clone')?->value);
            self::assertNull($rival->e1Keyspace()->get('e1.' . hash('sha256', 'incomplete-cloned-view')));
        }
        // Distinct request, same root/view: ownership, held writes and both failure markers must be clean.
        $probe->trace = [];
        $root->transactRequest(static function () use ($root, $target): void {
            $root->incrementAt('incomplete-following-root', 3600, 7, self::NOW);
            $target->incrementAt($target === $root ? 'incomplete-following-view' : 'e1.' . hash('sha256', 'incomplete-following-view'), 3600, 11, self::NOW);
        });
        self::assertSame([1], self::requestSaveTrace($probe->trace)['commit']);
        self::assertSame(7, $rival->get('incomplete-following-root')?->value);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedIncompleteCases(): iterable
    {
        foreach (['outer', 'json', 'delta-scalar', 'delta-entry', 'prefix-throw'] as $case) {
            yield $case => [$case];
        }
    }

    /** The executor corrupts a real overlay reply; a valid delta prefix must never leak into trusted verification. */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedIncompleteCases')]
    public function testMalformedOverlayResponseIsIncompleteAndDeltaImportIsAllOrNone(string $case): void
    {
        $rival = new RedisFullCapabilityStore($this->independentWire(), self::NAMESPACE);
        $rival->incrementAt('malformed-a', 3600, 10, self::NOW);
        $expectedState = $this->exactSnapshot();
        $wire = $this->independentWire();
        $trace = [];
        $evaluations = 0;
        $altered = 0;
        $prefixKey = null;
        $watch = 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':watch:' . hash('sha256', 'malformed-watch');
        $executor = new CallableRedisCommandExecutor(function (array $command) use ($wire, $rival, $case, $watch, &$trace, &$altered, &$prefixKey, &$expectedState): mixed {
            $reply = $wire->execute($command);
            self::traceRequestWire($command, $reply, $trace);
            if (self::isOverlayEval($command) && in_array($watch, OverlayWire::keys($command), true) && $altered === 0) {
                $altered++;
                self::assertIsArray($reply);
                self::assertIsString($reply[0]);
                $delta = json_decode($reply[0], true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($delta);
                self::assertGreaterThanOrEqual(2, count($delta), 'The malformed-tail control starts with a real multi-key delta.');
                self::assertIsArray($delta[0]);
                self::assertIsString($delta[0][0]);
                $prefixKey = $delta[0][0];
                if ($case === 'prefix-throw') {
                    $rival->incrementWatchFlagAt('malformed-watch', 3600, self::NOW);
                    $expectedState = $this->exactSnapshot();
                }
                if ($case === 'outer') {
                    return false;
                }
                $reply[0] = match ($case) {
                    'json' => '{',
                    'delta-scalar' => 'null',
                    default => json_encode([$delta[0], ['malformed-tail', null]], JSON_THROW_ON_ERROR),
                };
            }

            return $reply;
        });
        $store = new RedisFullCapabilityStore($executor, self::NAMESPACE);
        $first = null;
        $caught = null;
        $returned = null;
        try {
            $returned = $store->transactRequest(function () use ($store, $case, &$evaluations, &$first): string {
                $evaluations++;
                $store->incrementAt('malformed-a', 3600, 2, self::NOW);
                try {
                    $store->incrementWatchFlagAt('malformed-watch', 3600, self::NOW);
                } catch (\Throwable $error) {
                    $first ??= $error;
                    if ($case === 'prefix-throw') {
                        throw $error;
                    }
                }

                return 'normal return after malformed reply';
            });
        } catch (\Throwable $error) {
            $caught = $error;
        }
        $after = $this->exactSnapshot();
        $summary = self::requestSaveTrace($trace);
        $actual = ['evaluations' => $evaluations, 'altered' => $altered, 'verify' => $summary['verify'], 'commit' => $summary['commit'],
            'returned' => $returned, 'sameFirst' => $first !== null && $caught === $first, 'unchanged' => $after === $expectedState,
            'errorClass' => $caught === null ? null : $caught::class];
        $expected = ['evaluations' => 1, 'altered' => 1, 'verify' => $case === 'prefix-throw' ? [1] : [], 'commit' => [],
            'returned' => null, 'sameFirst' => true, 'unchanged' => true, 'errorClass' => $case === 'json' ? \JsonException::class : RateLimiterException::class];
        self::assertSame($expected, $actual, self::incompleteDiagnostic('malformed/' . $case, ['actual' => $actual, 'wire' => $trace, 'expectedSnapshot' => $expectedState, 'afterSnapshot' => $after]));
        self::assertNotNull($prefixKey);
        foreach ($summary['verifyKeys'] as $keys) {
            self::assertSame([self::physicalScore('malformed-a')], $keys, 'Only the complete earlier delta was imported.');
            self::assertNotContains($prefixKey, $keys, 'A valid prefix of the failed delta is not a trusted read.');
        }
        $trace = [];
        $store->transactRequest(static fn(): int => $store->incrementAt('malformed-following', 3600, 7, self::NOW));
        self::assertSame([1], self::requestSaveTrace($trace)['commit']);
        self::assertSame(7, $rival->get('malformed-following')?->value);
    }

    /** @return iterable<string, array{bool}> */
    public static function completeOrUndispatchedFailureCases(): iterable
    {
        yield 'validation before dispatch' => [false];
        yield 'semantic PHP validation after complete delta' => [true];
    }

    /** Completeness is a dispatch/import boundary, not a catch-all ban on errors a callback handles. */
    #[\PHPUnit\Framework\Attributes\DataProvider('completeOrUndispatchedFailureCases')]
    public function testPreDispatchAndPostImportSemanticErrorsDoNotMarkAnAttempt(bool $semantic): void
    {
        $rival = new RedisFullCapabilityStore($this->independentWire(), self::NAMESPACE);
        $rival->incrementAt('boundary-a', 3600, 10, self::NOW);
        $wire = $this->independentWire();
        $trace = [];
        $altered = 0;
        $executor = new CallableRedisCommandExecutor(function (array $command) use ($wire, $semantic, &$trace, &$altered): mixed {
            $reply = $wire->execute($command);
            self::traceRequestWire($command, $reply, $trace);
            if ($semantic && self::isOverlayEval($command) && in_array(self::physicalScore('semantic-b'), OverlayWire::keys($command), true)) {
                self::assertIsArray($reply);
                $reply[1] = ['not-an-integer', self::NOW]; // The actual delta is complete and unchanged.
                $altered++;
            }

            return $reply;
        });
        $store = new RedisFullCapabilityStore($executor, self::NAMESPACE);
        $handled = null;
        $result = $store->transactRequest(function () use ($store, $semantic, &$handled): string {
            try {
                $store->incrementAt('semantic-b', $semantic ? 3600 : 0, 1, self::NOW);
            } catch (RateLimiterException $error) {
                $handled = $error;
            }
            $store->incrementAt('boundary-a', 3600, 2, self::NOW);

            return 'complete read set';
        });
        self::assertInstanceOf(RateLimiterException::class, $handled);
        self::assertSame('complete read set', $result);
        self::assertSame($semantic ? 1 : 0, $altered);
        self::assertSame([], self::requestSaveTrace($trace)['verify']);
        self::assertSame([1], self::requestSaveTrace($trace)['commit']);
        self::assertSame(12, $rival->get('boundary-a')?->value);
        self::assertSame($semantic ? 1 : null, $rival->get('semantic-b')?->value);
    }

    /** A real public Admission read on a wrong-type fence must share the root's incomplete-step marker. */
    public function testIncompleteAdmissionOverlayStepCannotSaveTheRootRequest(): void
    {
        $rival = new RedisFullCapabilityStore($this->independentWire(), self::NAMESPACE);
        $rival->bumpAdmissionFence('incomplete-admission', null);
        $keys = $this->executor->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        self::assertCount(1, $keys);
        self::assertIsString($keys[0]);
        self::assertStringContainsString(':adm:a1:fence:', $keys[0]);
        $this->executor->execute(['DEL', $keys[0]]);
        $this->executor->execute(['HSET', $keys[0], 'wrong-type', 'fixture']);
        $before = $this->exactSnapshot();
        $wire = $this->independentWire();
        $trace = [];
        $dispatch = new class {
            public ?\Throwable $error = null;
        };
        $executor = new CallableRedisCommandExecutor(function (array $command) use ($wire, &$trace, $dispatch): mixed {
            try {
                $reply = $wire->execute($command);
            } catch (\Throwable $error) {
                $dispatch->error = $error;
                $trace[] = ['error', OverlayWire::keys($command), $error::class . ': ' . $error->getMessage()];
                throw $error;
            }
            self::traceRequestWire($command, $reply, $trace);

            return $reply;
        });
        $store = new RedisFullCapabilityStore($executor, self::NAMESPACE);
        $handled = null;
        $caught = null;
        $returned = null;
        try {
            $returned = $store->transactRequest(function () use ($store, &$handled): string {
                try {
                    $store->readAdmissionFence('incomplete-admission', null);
                } catch (\Throwable $error) {
                    $handled = $error;
                }
                $store->incrementAt('after-incomplete-admission', 3600, 1, self::NOW);

                return 'caught admission error';
            });
        } catch (\Throwable $error) {
            $caught = $error;
        }
        $actual = ['returned' => $returned, 'sameError' => $caught !== null && $caught === $handled && $caught === $dispatch->error,
            'verify' => self::requestSaveTrace($trace)['verify'], 'commit' => self::requestSaveTrace($trace)['commit'],
            'unchanged' => $before === $this->exactSnapshot()];
        self::assertSame(
            ['returned' => null, 'sameError' => true, 'verify' => [], 'commit' => [], 'unchanged' => true],
            $actual,
            self::incompleteDiagnostic('admission', ['actual' => $actual, 'wire' => $trace, 'expectedSnapshot' => $before, 'afterSnapshot' => $this->exactSnapshot()]),
        );
        self::assertInstanceOf(RateLimiterException::class, $caught);
        self::assertStringContainsString('malformed admission state: fence key has type hash', $caught->getMessage());
        $trace = [];
        $store->transactRequest(static fn(): int => $store->incrementAt('admission-following', 3600, 7, self::NOW));
        self::assertSame([1], self::requestSaveTrace($trace)['commit']);
        self::assertSame(7, $rival->get('admission-following')?->value);
    }

    /** The engine must not count a native incomplete-step error as an outage or consume a fallback allowance. */
    public function testIncompleteNativeErrorDoesNotBecomeAnEngineFallbackOrFailureSignal(): void
    {
        $failing = new RedisFullCapabilityStore($this->independentWire(), self::NAMESPACE);
        $failing->incrementAt('engine-overflow', 3600, PHP_INT_MAX, self::NOW);
        $before = $this->exactSnapshot();
        $wire = $this->independentWire();
        $dispatch = new class {
            public ?RateLimiterException $error = null;
        };
        $executor = new CallableRedisCommandExecutor(function (array $command) use ($wire, $failing, $dispatch): mixed {
            $reply = $wire->execute($command);
            if (self::isOverlayEval($command) && $dispatch->error === null) {
                try {
                    $failing->incrementAt('engine-overflow', 3600, 1, self::NOW);
                } catch (RateLimiterException $error) {
                    $dispatch->error = $error;
                    throw $error;
                }
            }

            return $reply;
        });
        $signals = new RecordingFailureSignalEmitter();
        $limiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('test_secret', 'test_fp', 'prod'),
            new RedisFullCapabilityStore($executor, self::NAMESPACE),
            $signals,
        )->withClock(new FixedClock('@' . self::NOW))->build();
        $caught = null;
        try {
            $limiter->limit($this->context('incomplete-engine'), RateLimitCommand::checkOnly('login_protection'));
        } catch (\Throwable $error) {
            $caught = $error;
        }
        self::assertInstanceOf(RateLimiterException::class, $dispatch->error);
        self::assertSame($dispatch->error, $caught, 'The public engine throws the native non-backend error instead of returning fallback.');
        self::assertSame($before, $this->exactSnapshot(), 'No circuit-reporting or held evaluation state was persisted.');
        self::assertSame([], $signals->getEmitted());
    }

    public function testTheRetryBudgetMustBePositive(): void
    {
        $this->expectException(RateLimiterException::class);
        new RedisFullCapabilityStore($this->executor, self::NAMESPACE, null, 0);
    }
}
