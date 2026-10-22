<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use DateTimeZone;
use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Enum\PolicyCapabilityEnum;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Service\FixedWindowSimpleRateLimiter;
use Maatify\RateLimiter\Tests\Support\Clock\CountingClock;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Clock\RedisAnchoredClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\FullCapability\FullCapabilityInMemoryStore;
use Maatify\RateLimiter\Tests\Support\FullCapability\LegacySignatureFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\SharedCommon\Infrastructure\SystemClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * #153 phase 2 red tests (investigations/clock-skew-153/phase2/PLAN.md §4.1,
 * §8): one time authority, the caller's clock, on the official Redis store.
 * RED-1a, RED-1b, RED-2, RED-3, RED-4, RED-5, RED-8, RED-9 and RED-10 fail on
 * `main@f5897b0`; RED-6 and RED-7 are regression guards that pass before and
 * after. Virtual clock advances stand in for real waits: today the Redis side
 * does not see them, after the fix every comparison follows the caller.
 * RED-11 (F-P2-03, PLAN §12) fails on `38c5ce7`, the first RESULTS head.
 */
final class CallerTimeAuthorityRedisTest extends TestCase
{
    private const VALIDATOR_MESSAGE = 'Bounded correlation store returned an invalid snapshot.';

    /**
     * `login_protection` without `DISTRIBUTED_ACCOUNT`: with the application
     * behind Redis, the bounded snapshot (A) throws on the first pre-check of
     * every window and masks the decay comparison (B) on the preset itself,
     * so B is exercised on this mirror (#156 PLAN §1.2 "A would mask B–I").
     */
    private const LOGIN_MIRROR = 'login_mirror_without_distributed';

    private RespRedisCommandExecutor $executor;

    private RespRedisCommandExecutor $clockConnection;

    private RedisFullCapabilityStore $store;

    private string $host;

    private int $port;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $portValue = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $portValue === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->host = $host;
        $this->port = (int) $portValue;
        $this->executor = new RespRedisCommandExecutor($this->host, $this->port);
        $this->executor->execute(['FLUSHDB']);
        $this->clockConnection = new RespRedisCommandExecutor($this->host, $this->port);
        $this->store = new RedisFullCapabilityStore($this->executor, 'integration:caller-time');
    }

    /** RED-1a: phase 1's 100 % cell (application one second behind Redis). */
    public function testFreshPreChecksDoNotThrowWhenTheApplicationIsOneSecondBehindRedis(): void
    {
        $runtime = $this->runtime(new RedisAnchoredClock($this->clockConnection, -1.0));

        self::assertSame([], $this->freshPreCheckFailures($runtime, 'red1a', 100));
    }

    /** RED-1b: the zero-skew second-boundary crossing, made deterministic. */
    public function testFreshPreChecksDoNotThrowWhenTheCallerReadsTheClockJustBeforeARedisSecondBoundary(): void
    {
        $runtime = $this->runtime(new RedisAnchoredClock($this->clockConnection, 0.0, true));

        self::assertSame([], $this->freshPreCheckFailures($runtime, 'red1b', 100));
    }

    /**
     * RED-2 (B): a client that honours a decay `retryAfter` returns exactly
     * on time with the application clock two seconds behind Redis.
     */
    public function testRetryAfterHonouringClientIsAdmittedOnTimeWhenTheApplicationIsBehind(): void
    {
        $refused = [];
        for ($i = 0; $i < 100; $i++) {
            $clock = new RedisAnchoredClock($this->clockConnection, -2.0);
            $runtime = $this->runtime($clock);
            $context = $this->context('red2-' . $i, $i);

            $runtime->limit($context, RateLimitCommand::recordFailure(self::LOGIN_MIRROR));
            $second = $runtime->limit($context, RateLimitCommand::recordFailure(self::LOGIN_MIRROR));
            self::assertNotSame(RateLimitResultDTO::DECISION_ALLOW, $second->decision, 'Two failures must refuse.');
            self::assertGreaterThan(0, $second->retryAfter ?? 0);

            $clock->advance((float) $second->retryAfter);
            $return = $runtime->limit($context, RateLimitCommand::checkOnly(self::LOGIN_MIRROR));
            if ($return->decision !== RateLimitResultDTO::DECISION_ALLOW) {
                $refused[] = $i . ':' . $return->decision;
            }
        }

        self::assertSame([], $refused);
    }

    /**
     * RED-3 (E): with the application clock one second ahead, a client that
     * polls during a lifecycle L2 block and returns after the polled
     * `retryAfter` is admitted with re-entry evidence.
     */
    public function testPolledReturnAfterALifecycleBlockGetsReentryWhenTheApplicationIsAhead(): void
    {
        $missed = [];
        for ($i = 0; $i < 100; $i++) {
            $clock = new RedisAnchoredClock($this->clockConnection, 1.0);
            $runtime = $this->runtime($clock);
            $context = $this->context('red3-' . $i, 1000 + $i);

            for ($failure = 0; $failure < 3; $failure++) {
                $runtime->limit($context, RateLimitCommand::recordFailure('login_protection'));
            }
            $poll = $runtime->limit($context, RateLimitCommand::checkOnly('login_protection'));
            self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $poll->decision, 'Three failures must hard-block.');
            self::assertGreaterThan(0, $poll->retryAfter ?? 0);

            $clock->advance((float) $poll->retryAfter);
            $return = $runtime->limit($context, RateLimitCommand::checkOnly('login_protection'));
            if ($return->decision !== RateLimitResultDTO::DECISION_ALLOW || $return->metadata?->postPunishmentReentry === null) {
                $missed[] = $i . ':' . $return->decision;
            }
        }

        self::assertSame([], $missed);
    }

    /**
     * RED-4 (#155): a window in the last fraction of a second of its own
     * life (logical expiry reached, key still physically present) is expired
     * and recreated, never reported as malformed, at every site of #155.
     *
     * @param callable(RedisFullCapabilityStore, string): int $operation
     */
    #[DataProvider('logicallyExpiredSites')]
    public function testLogicallyExpiredButPhysicallyPresentStateIsRecreatedNotMalformed(string $site, string $dataFamily, string $metaFamily, bool $livePrevious, int $expectedCount, callable $operation): void
    {
        $second = $this->secondWithRoom();
        if ($livePrevious) {
            $previous = $site . '-previous';
            $this->executor->execute(['SADD', $this->key('distinct', $previous), 'previous-member']);
            $this->executor->execute(['EXPIRE', $this->key('distinct', $previous), '300']);
            $this->executor->execute(['HSET', $this->key('distinct-meta', $previous), 'expiresAt', (string) ($second + 300)]);
            $this->executor->execute(['EXPIRE', $this->key('distinct-meta', $previous), '300']);
        }
        $this->seedTail($dataFamily, $metaFamily, $site, (string) $second);

        $count = $operation($this->store, $site);

        self::assertSame($expectedCount, $count, 'The expired window must restart without its old members.');
    }

    /** @return iterable<string, array{string, string, string, bool, int, callable(RedisFullCapabilityStore, string): int}> */
    public static function logicallyExpiredSites(): iterable
    {
        yield 'DISTINCT_ADD L721' => ['tail-distinct', 'distinct', 'distinct-meta', false, 1, static fn(RedisFullCapabilityStore $store, string $key): int => $store->addDistinct($key, 'new-member', 600)];
        yield 'WATCH_INCREMENT L745' => ['tail-watch', 'watch', 'watch-meta', false, 1, static fn(RedisFullCapabilityStore $store, string $key): int => $store->incrementWatchFlag($key, 600)];
        yield 'BOUNDED_SNAPSHOT L781' => ['tail-bounded', 'distinct', 'distinct-meta', false, 1, static fn(RedisFullCapabilityStore $store, string $key): int => $store->addDistinctBoundedWithSnapshot($key, 'new-member', 600, 4)->count];
        yield 'ROTATED_SNAPSHOT L834 (no live previous)' => ['tail-rotated', 'distinct', 'distinct-meta', false, 1, static fn(RedisFullCapabilityStore $store, string $key): int => $store->addDistinctBoundedWithSnapshotAcrossRotation($key, $key . '-bridge', $key . '-absent-previous', 'new-member', 'new-member-previous', 600, 4)->count];
        // Rotation branch: a live previous member plus the new member through the bridge.
        yield 'ROTATED_SNAPSHOT L874 (live previous)' => ['tail-rotation', 'distinct', 'distinct-meta', true, 2, static fn(RedisFullCapabilityStore $store, string $key): int => $store->addDistinctBoundedWithSnapshotAcrossRotation($key, $key . '-bridge', $key . '-previous', 'new-member', 'new-member-previous', 600, 4)->count];
    }

    /**
     * RED-5 (P-TAIL): with the application clock ahead by `a`, a pre-check in
     * the last `a` seconds of a device window neither throws nor reports
     * malformed state.
     */
    public function testPreCheckInTheLastSecondsOfAWindowDoesNotThrowWhenTheApplicationIsAhead(): void
    {
        $failures = [];
        foreach ([0.5, 1.0, 2.0] as $ahead) {
            for ($j = 0; $j < 20; $j++) {
                $clock = new RedisAnchoredClock($this->clockConnection, $ahead);
                $runtime = $this->runtime($clock);
                $context = $this->context(sprintf('red5-%.1f-%d', $ahead, $j), 2000 + (int) ($ahead * 100) + $j);

                $runtime->limit($context, RateLimitCommand::checkOnly('login_protection'));
                $clock->advance(600.0 - $ahead / 2);
                try {
                    $runtime->limit($context, RateLimitCommand::checkOnly('login_protection'));
                } catch (Throwable $error) {
                    $failures[] = sprintf('%.1f/%d %s: %s', $ahead, $j, $error::class, $error->getMessage());
                }
            }
        }

        self::assertSame([], $failures);
    }

    /**
     * RED-6 (guard, after LT-P2-02 (a)): a lifecycle K4 score written by a
     * Host two seconds behind Redis and then read, mutated, published and
     * claimed by a Host two seconds ahead raises no consistency error.
     */
    public function testLifecycleStateWrittenBehindAndUsedAheadRaisesNoConsistencyError(): void
    {
        $writer = $this->runtime(new RedisAnchoredClock($this->clockConnection, -2.0));
        $reader = $this->runtime(new RedisAnchoredClock($this->clockConnection, 2.0));
        $context = $this->context('red6', 3000);

        $writer->limit($context, RateLimitCommand::recordFailure(self::LOGIN_MIRROR));
        $writer->limit($context, RateLimitCommand::recordFailure(self::LOGIN_MIRROR));
        $reader->limit($context, RateLimitCommand::checkOnly(self::LOGIN_MIRROR));
        $blocked = $reader->limit($context, RateLimitCommand::recordFailure(self::LOGIN_MIRROR));
        $reader->limit($context, RateLimitCommand::checkOnly(self::LOGIN_MIRROR));
        $reentry = $blocked->metadata?->postPunishmentReentry;
        if ($reentry !== null) {
            $reader->claimPostPunishmentReentry($context, self::LOGIN_MIRROR, $reentry->id);
        }

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $blocked->decision);
    }

    /** RED-7 (guard, BC2): a store implementing only today's contracts still loads and runs. */
    public function testStoreImplementingOnlyTheExistingContractsStillLoadsAndRuns(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store = new LegacySignatureFullCapabilityStore(new FullCapabilityInMemoryStore($clock));
        $runtime = RateLimiterBuilder::fromFullCapabilityStore($this->config(), $store, new RecordingFailureSignalEmitter())
            ->withClock($clock)
            ->build();
        $context = $this->context('red7', 4000);

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $runtime->limit($context, RateLimitCommand::checkOnly('login_protection'))->decision);
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $runtime->limit($context, RateLimitCommand::recordFailure('login_protection'))->decision);
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $runtime->limit($context, RateLimitCommand::recordSuccess('login_protection'))->decision);
    }

    /** RED-8 (LT-P2-05): the Host clock is read once per `limit()` and once per claim. */
    public function testTheHostClockIsReadOncePerLimitAndPerClaim(): void
    {
        $clock = new CountingClock(new SystemClock(new DateTimeZone('UTC')));
        $runtime = $this->runtime($clock);
        $reads = [];
        $commands = [
            'checkOnly login' => RateLimitCommand::checkOnly('login_protection'),
            'recordFailure login' => RateLimitCommand::recordFailure('login_protection'),
            'recordSuccess login' => RateLimitCommand::recordSuccess('login_protection'),
            'checkOnly otp' => RateLimitCommand::checkOnly('otp_protection'),
            'recordFailure otp' => RateLimitCommand::recordFailure('otp_protection'),
            'api heavy' => new RateLimitCommand('api_heavy_protection'),
        ];
        foreach ($commands as $name => $command) {
            $before = $clock->reads();
            $runtime->limit($this->context('red8', 5000), $command);
            $reads[$name] = $clock->reads() - $before;
        }
        $before = $clock->reads();
        $runtime->claimPostPunishmentReentry($this->context('red8', 5000), 'login_protection', str_repeat('a', 32));
        $reads['claim'] = $clock->reads() - $before;

        self::assertSame(array_fill_keys(array_keys($reads), 1), $reads);
    }

    /**
     * RED-9 (LT-P2-09, simple runtime): with the application clock two
     * seconds behind Redis, a refused consume reports the full window as its
     * `retryAfter`, not the window plus the skew.
     */
    public function testSimpleFixedWindowRetryAfterIsTheWindowWhenTheApplicationIsBehind(): void
    {
        $limiter = new FixedWindowSimpleRateLimiter(
            [new FixedWindowThrottlePolicy('checkout', 1, 60)],
            $this->store,
            new FixedClock('@' . (int) floor((new RedisAnchoredClock($this->clockConnection))->redisNow() - 2.0)),
            'current-secret',
            'prod',
            null,
        );

        self::assertTrue($limiter->consume('checkout', 'red9')->allowed);
        $refused = $limiter->consume('checkout', 'red9');

        self::assertFalse($refused->allowed);
        self::assertSame(60, $refused->retryAfter);
    }

    /**
     * RED-10 (LT-P2-12): when the caller's preflight passes but Redis itself
     * rejects a relative expiry (the caller is behind Redis at Redis's own
     * millisecond boundary), the script deletes everything the call created,
     * so the keyspace dump before equals the dump after, and the error is the
     * named `expiry not representable` as a `RateLimiterException`.
     *
     * @param callable(RedisFullCapabilityStore, int): mixed $operation
     */
    #[DataProvider('expiryFamilies')]
    public function testRedisRejectedExpiryDeletesEverythingTheCallCreated(string $family, callable $operation): void
    {
        $this->executor->execute(['SET', 'unrelated-key', 'kept']);
        $store = new RedisFullCapabilityStore(
            $this->executor,
            'integration:caller-time',
            new RedisAnchoredClock($this->clockConnection, -1.0),
        );
        $rejected = false;
        $error = null;
        for ($attempt = 0; $attempt < 5 && ! $rejected; $attempt++) {
            $second = $this->waitForRedisSubsecond(870_000, 930_000);
            $before = $this->dumpKeyspace();
            try {
                $operation($store, 9_223_372_036_854_775 - $second);
            } catch (\Maatify\RateLimiter\Exception\RateLimiterException $exception) {
                $error = $exception;
            }
            self::assertSame($before, $this->dumpKeyspace(), $family . ' left residue');
            $rejected = $error !== null && str_contains($error->getMessage(), 'expiry not representable');
        }

        self::assertTrue($rejected, $family . ': Redis did not reject, or the error was not the named one: ' . ($error?->getMessage() ?? 'none'));
    }

    /**
     * The T:3137 families. Budgets are absent: a duration this close to the
     * Redis bound already fails the budget script's exact-integer check (L611)
     * before any expiry is written, so Redis's own boundary is unreachable.
     *
     * @return iterable<string, array{string, callable(RedisFullCapabilityStore, int): mixed}>
     */
    public static function expiryFamilies(): iterable
    {
        yield 'score increment' => ['score increment', static fn(RedisFullCapabilityStore $s, int $ttl): mixed => $s->increment('r10-score', $ttl, 1)];
        yield 'score set' => ['score set', static function (RedisFullCapabilityStore $s, int $ttl): mixed {
            $s->set('r10-set', 5, $ttl);

            return null;
        }];
        yield 'block' => ['block', static function (RedisFullCapabilityStore $s, int $ttl): mixed {
            $s->block('r10-block', 1, $ttl);

            return null;
        }];
        yield 'distinct' => ['distinct', static fn(RedisFullCapabilityStore $s, int $ttl): mixed => $s->addDistinct('r10-distinct', 'member', $ttl)];
        yield 'watch' => ['watch', static fn(RedisFullCapabilityStore $s, int $ttl): mixed => $s->incrementWatchFlag('r10-watch', $ttl)];
        yield 'bounded' => ['bounded', static fn(RedisFullCapabilityStore $s, int $ttl): mixed => $s->addDistinctBoundedWithSnapshot('r10-bounded', 'member', $ttl, 5)];
        yield 'rotated' => ['rotated', static fn(RedisFullCapabilityStore $s, int $ttl): mixed => $s->addDistinctBoundedWithSnapshotAcrossRotation('r10-current', 'r10-bridge', 'r10-previous', 'member', 'other', $ttl, 5)];
        yield 'watch rotated' => ['watch rotated', static fn(RedisFullCapabilityStore $s, int $ttl): mixed => $s->incrementWatchFlagAcrossRotation('r10-watch-current', 'r10-watch-previous', $ttl)];
    }

    /**
     * RED-11 (F-P2-03, PLAN §12, LT-P2-14): at its logical deadline a watch
     * window whose keys are still physically present is expired on the
     * rotation path as on the plain path (LT-P2-03); one second earlier it
     * still counts; a live previous generation is read, never written.
     */
    public function testRotatedWatchWindowRestartsAtItsLogicalDeadlineLikeThePlainWatch(): void
    {
        $now = $this->secondWithRoom();
        $previous = 'red11-previous-live';
        $this->executor->execute(['SET', $this->key('watch', $previous), '3']);
        $this->executor->execute(['EXPIRE', $this->key('watch', $previous), '300']);
        $this->executor->execute(['HSET', $this->key('watch-meta', $previous), 'expiresAt', (string) ($now + 300)]);
        $this->executor->execute(['EXPIRE', $this->key('watch-meta', $previous), '300']);
        $previousBefore = [$this->executor->execute(['DUMP', $this->key('watch', $previous)]), $this->executor->execute(['DUMP', $this->key('watch-meta', $previous)])];

        $plain = [$this->store->incrementWatchFlagAt('red11-plain', 30, $now), $this->store->incrementWatchFlagAt('red11-plain', 30, $now + 30)];
        $rotated = [
            $this->store->incrementWatchFlagAcrossRotationAt('red11-current', 'red11-previous-absent', 30, $now),
            $this->store->incrementWatchFlagAcrossRotationAt('red11-current', 'red11-previous-absent', 30, $now + 30),
        ];
        $beforeDeadline = [
            $this->store->incrementWatchFlagAcrossRotationAt('red11-control', 'red11-previous-absent', 30, $now),
            $this->store->incrementWatchFlagAcrossRotationAt('red11-control', 'red11-previous-absent', 30, $now + 29),
        ];
        $withPrevious = [
            $this->store->incrementWatchFlagAcrossRotationAt('red11-current-with-previous', $previous, 30, $now),
            $this->store->incrementWatchFlagAcrossRotationAt('red11-current-with-previous', $previous, 30, $now + 30),
        ];

        self::assertSame([1, 1], $plain);
        self::assertSame([1, 1], $rotated, 'The rotated watch window must restart at its logical deadline.');
        self::assertSame([1, 2], $beforeDeadline);
        self::assertSame([4, 4], $withPrevious, 'The current window restarts; the live previous count (3) is still added.');
        self::assertSame($previousBefore, [$this->executor->execute(['DUMP', $this->key('watch', $previous)]), $this->executor->execute(['DUMP', $this->key('watch-meta', $previous)])]);
    }

    /** @return list<string> */
    private function freshPreCheckFailures(CompositeRateLimiterRuntimeInterface $runtime, string $prefix, int $count): array
    {
        $failures = [];
        for ($i = 0; $i < $count; $i++) {
            try {
                $runtime->limit($this->context($prefix . '-' . $i, $i), RateLimitCommand::checkOnly('login_protection'));
            } catch (Throwable $error) {
                $failures[] = $i . ' ' . $error::class . ': ' . $error->getMessage();
            }
        }

        if ($failures !== []) {
            self::assertStringContainsString(self::VALIDATOR_MESSAGE, $failures[0]);
        }

        return $failures;
    }

    private function runtime(ClockInterface $clock): CompositeRateLimiterRuntimeInterface
    {
        $mirror = new class extends LoginProtectionPolicy {
            public function getName(): string
            {
                return 'login_mirror_without_distributed';
            }

            public function getCapabilities(): array
            {
                return [PolicyCapabilityEnum::CREDENTIAL_SPRAY, PolicyCapabilityEnum::TRUSTED_AUTHENTICATION];
            }
        };

        return RateLimiterBuilder::fromFullCapabilityStore($this->config(), $this->store, new RecordingFailureSignalEmitter())
            ->withClock($clock)
            ->withPolicy($mirror)
            ->build();
    }

    private function config(): RateLimiterConfig
    {
        return new RateLimiterConfig('caller-time-key', 'caller-time-fingerprint', 'prod');
    }

    private function context(string $account, int $index): RateLimitContextDTO
    {
        // One IPv4 address per account (benchmarking range), so no IP-scoped rule interferes.
        $ip = '198.' . (18 + intdiv($index, 65536)) . '.' . (intdiv($index, 256) % 256) . '.' . ($index % 256);

        return new RateLimitContextDTO($ip, 'Mozilla/5.0', $account, ['device' => 'd-' . $account]);
    }

    /** Waits, if needed, until the Redis second has at least 0.4 s left, and returns that second. */
    private function secondWithRoom(): int
    {
        $clock = new RedisAnchoredClock($this->clockConnection);
        while (true) {
            $now = $clock->redisNow();
            if ($now - floor($now) <= 0.6) {
                return (int) floor($now);
            }
            usleep((int) ((1.0 - ($now - floor($now))) * 1_000_000) + 1_000);
        }
    }

    /**
     * Seeds the state a window has in the last fraction of a second of its
     * life: logical expiry equal to the current Redis second, physical TTL
     * still positive.
     */
    private function seedTail(string $dataFamily, string $metaFamily, string $logical, string $expiresAt): void
    {
        $data = $this->key($dataFamily, $logical);
        $meta = $this->key($metaFamily, $logical);
        if ($dataFamily === 'watch') {
            $this->executor->execute(['SET', $data, '3', 'PX', '900']);
        } else {
            $this->executor->execute(['SADD', $data, 'old-member']);
            $this->executor->execute(['PEXPIRE', $data, '900']);
        }
        $this->executor->execute(['HSET', $meta, 'expiresAt', $expiresAt]);
        $this->executor->execute(['PEXPIRE', $meta, '900']);
    }

    /** Waits until the Redis sub-second part is inside the window and returns the Redis second. */
    private function waitForRedisSubsecond(int $fromMicroseconds, int $toMicroseconds): int
    {
        for ($i = 0; $i < 2_000; $i++) {
            $time = $this->executor->execute(['TIME']);
            self::assertIsArray($time);
            self::assertTrue(isset($time[0], $time[1]) && is_numeric($time[0]) && is_numeric($time[1]));
            $microseconds = (int) $time[1];
            if ($microseconds >= $fromMicroseconds && $microseconds <= $toMicroseconds) {
                return (int) $time[0];
            }
            usleep(2_000);
        }
        self::fail('Redis sub-second window was not reached.');
    }

    /** @return array<string, string> key => DUMP payload, sorted by key */
    private function dumpKeyspace(): array
    {
        $keys = $this->executor->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        $dump = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $value = $this->executor->execute(['DUMP', $key]);
            $dump[$key] = is_string($value) ? bin2hex($value) : '';
        }
        ksort($dump);

        return $dump;
    }

    private function key(string $family, string $logical): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', 'integration:caller-time') . ':' . $family . ':' . hash('sha256', $logical);
    }
}
