<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
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
 * as it was, and a request whose read state moved is calculated again from a fresh read.
 *
 * Everything runs through the public builder on an isolated Redis.
 */
#[Group('t5-request-atomicity')]
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
     * @return array<string, array{string, mixed, int}>
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
            self::assertIsInt($expiry);
            $state[$key] = [$type, $content, $expiry];
        }

        return $state;
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
            self::assertGreaterThan($this->redisNow() * 1000, $expiry, $key . ' has an expiry in the future.');
            if ($family === 'distinct-meta') {
                self::assertIsArray($content);
                self::assertIsString($content['expiresAt']);
                // The key lives for its TTL from the Redis clock; the metadata records the same span on the request clock.
                self::assertEqualsWithDelta(
                    intdiv($expiry, 1000) - $this->redisNow(),
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
            self::assertEqualsWithDelta($expiry, $actual[$key][2], 5000, 'Expiry of ' . $key);
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

    public function testTheRetryBudgetMustBePositive(): void
    {
        $this->expectException(RateLimiterException::class);
        new RedisFullCapabilityStore($this->executor, self::NAMESPACE, null, 0);
    }
}
