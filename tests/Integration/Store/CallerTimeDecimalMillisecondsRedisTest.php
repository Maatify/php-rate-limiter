<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use DateTimeImmutable;
use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RecordingRedisCommandExecutor;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The caller's epoch time in milliseconds reaches Redis and Lua exactly, from the public Builder and runtime and from the store
 * itself, on 32-bit and 64-bit PHP alike (#215, DEC-039). The decisions below were taken on 64-bit PHP and are the reference the
 * 32-bit run must reproduce.
 */
#[Group('integration')]
#[Group('php32')]
final class CallerTimeDecimalMillisecondsRedisTest extends TestCase
{
    private const START = '2026-11-14 10:00:00.123456 UTC';

    private const START_MILLISECONDS = '1794650400123';

    private const NAMESPACE = 'integration:caller-time-decimal';

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

    public function testPublicRuntimeK4AndK4PDecisionsAtAContemporaryEpochMatchThe64BitReference(): void
    {
        $clock = new FixedClock(self::START);
        $recording = new RecordingRedisCommandExecutor($this->executor);
        $runtime = $this->runtime($recording, $clock, new RateLimiterConfig('k', 'f', 'test'));
        $context = $this->context();

        $observed = [];
        $steps = [
            ['+0 seconds', 'fail'], ['+1 second', 'fail'], ['+1 second', 'fail'], ['+1 second', 'fail'], ['+1 second', 'fail'],
            ['+61 seconds', 'fail'], ['+301 seconds', 'check'], ['+1 second', 'ok'], ['+1 second', 'check'], ['+1 second', 'fail'], ['+1 second', 'fail'],
        ];
        $instants = [];
        foreach ($steps as [$advance, $command]) {
            $clock->setNow($clock->now()->modify($advance));
            $instants[] = $this->exactMilliseconds($clock->now());
            $result = $runtime->limit($context, match ($command) {
                'fail' => RateLimitCommand::recordFailure('login_protection'),
                'ok' => RateLimitCommand::recordSuccess('login_protection'),
                default => RateLimitCommand::checkOnly('login_protection'),
            });
            $observed[] = [$result->decision, $result->blockLevel ?? 0, $result->retryAfter];
        }

        self::assertSame([
            [RateLimitResultDTO::DECISION_ALLOW, 0, 0],
            [RateLimitResultDTO::DECISION_SOFT_BLOCK, 1, 1200],
            [RateLimitResultDTO::DECISION_HARD_BLOCK, 2, 60],
            [RateLimitResultDTO::DECISION_HARD_BLOCK, 2, 59],
            [RateLimitResultDTO::DECISION_HARD_BLOCK, 2, 58],
            [RateLimitResultDTO::DECISION_HARD_BLOCK, 3, 300],
            [RateLimitResultDTO::DECISION_ALLOW, 0, 0],
            [RateLimitResultDTO::DECISION_ALLOW, 0, 0],
            [RateLimitResultDTO::DECISION_ALLOW, 0, 0],
            [RateLimitResultDTO::DECISION_HARD_BLOCK, 3, 300],
            [RateLimitResultDTO::DECISION_HARD_BLOCK, 3, 299],
        ], $observed);

        // The instants at which the K4 state was read, claimed or mutated reached the store as their exact decimal, never the saturated native maximum.
        $sent = json_encode($recording->commands, JSON_THROW_ON_ERROR);
        $reached = array_values(array_filter($instants, static fn(string $milliseconds): bool => str_contains($sent, '"' . $milliseconds . '"')));
        self::assertContains(self::START_MILLISECONDS, $reached);
        self::assertGreaterThanOrEqual(5, count($reached), 'At least the first decisions and the post-expiry re-entry decisions forward the exact clock.');
        self::assertStringNotContainsString('"2147483647"', $sent);
    }

    public function testKeyRotationContinuesTheSameK4StateAcrossTheSecretChange(): void
    {
        $clock = new FixedClock(self::START);
        $before = $this->runtime($this->executor, $clock, new RateLimiterConfig('k', 'f', 'test'));
        $after = $this->runtime($this->executor, $clock, new RateLimiterConfig('k2', 'f', 'test', 'k'));
        $context = $this->context();

        $observed = [];
        foreach ([$before, $before, $after, $after, $after] as $runtime) {
            $result = $runtime->limit($context, RateLimitCommand::recordFailure('login_protection'));
            $observed[] = [$result->decision, $result->blockLevel ?? 0, $result->retryAfter];
            $clock->setNow($clock->now()->modify('+1 second'));
        }

        self::assertSame([
            [RateLimitResultDTO::DECISION_ALLOW, 0, 0],
            [RateLimitResultDTO::DECISION_SOFT_BLOCK, 1, 1200],
            [RateLimitResultDTO::DECISION_HARD_BLOCK, 2, 60],
            [RateLimitResultDTO::DECISION_HARD_BLOCK, 2, 59],
            [RateLimitResultDTO::DECISION_HARD_BLOCK, 2, 58],
        ], $observed);
    }

    public function testTheLifecycleOperationsGetTheExactDecimalAndDeriveTheSameSecondsAndExpiry(): void
    {
        $recording = new RecordingRedisCommandExecutor($this->executor);
        $store = new RedisFullCapabilityStore($recording, self::NAMESPACE);

        self::assertNull($store->readGenerationBoundScoreStateAtMilliseconds('k4', null, self::START_MILLISECONDS));
        $mutation = $store->mutateGenerationBoundScoreAtMilliseconds('k4', null, null, 100, 5, self::START_MILLISECONDS);

        self::assertTrue($mutation->applied);
        self::assertNotNull($mutation->state);
        self::assertSame(5, $mutation->state->value);
        self::assertSame(1794650400, $mutation->state->updatedAt);
        // A generated expiry is the ceiling of (now ms + TTL) / 1 s: 1794650500.123 rounds up.
        self::assertSame(1794650501, $mutation->state->expiresAt);
        foreach ($recording->commands as $command) {
            self::assertSame(self::START_MILLISECONDS, (string) end($command), 'The caller time is the last argument of each lifecycle script, exact.');
        }

        $read = $store->readGenerationBoundScoreStateAtMilliseconds('k4', null, '1794650500999');
        self::assertNotNull($read);
        self::assertSame(5, $read->value);
        self::assertSame(1794650501, $read->expiresAt);
        // One millisecond before the second boundary the score is still live, at the boundary it has expired.
        self::assertNull($store->readGenerationBoundScoreStateAtMilliseconds('k4', null, '1794650501000'));
    }

    public function testTheStoreOwnClockIsExactForTheMethodsWithoutACallerTime(): void
    {
        $recording = new RecordingRedisCommandExecutor($this->executor);
        $store = new RedisFullCapabilityStore($recording, self::NAMESPACE, new FixedClock(self::START));

        $mutation = $store->mutateGenerationBoundScore('k4-own', null, null, 100, 7);

        self::assertTrue($mutation->applied);
        self::assertNotNull($mutation->state);
        self::assertSame(1794650400, $mutation->state->updatedAt);
        self::assertSame(1794650501, $mutation->state->expiresAt);
        self::assertSame(self::START_MILLISECONDS, (string) end($recording->commands[0]));
    }

    public function testTheRotationOperationsAcceptTheExactDecimalAndKeepTheirSemantics(): void
    {
        $store = new RedisFullCapabilityStore($this->executor, self::NAMESPACE);

        $first = $store->addDistinctBoundedWithSnapshotAcrossRotationAtMilliseconds('cur', 'bridge', 'prev', 'm1', 'm0', 600, 2, self::START_MILLISECONDS);
        self::assertSame(1, $first->count);
        self::assertTrue($first->accepted);
        self::assertTrue($first->added);
        $second = $store->addDistinctBoundedWithSnapshotAcrossRotationAtMilliseconds('cur', 'bridge', 'prev', 'm2', 'm0', 600, 2, '1794650401123');
        self::assertSame(2, $second->count);
        $third = $store->addDistinctBoundedAcrossRotationAtMilliseconds('cur', 'bridge', 'prev', 'm3', 'm0', 600, 2, '1794650402123');
        self::assertSame(2, $third->count);
        self::assertFalse($third->accepted, 'The third distinct member exceeds the bound of two.');
        self::assertSame(2, $store->addDistinctAcrossRotationAtMilliseconds('cur', 'bridge', 'prev', 'm1', 'm0', 600, '1794650403123'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function unacceptableTimes(): iterable
    {
        yield 'negative' => ['-1', 'Caller time must be a non-negative Unix timestamp.'];
        yield 'leading zero' => ['01794650400123', 'canonical decimal integer'];
        yield 'fraction' => ['1794650400123.5', 'canonical decimal integer'];
        yield 'above the exact range' => ['9007199254740992', 'is not representable'];
    }

    #[DataProvider('unacceptableTimes')]
    public function testAnUnacceptableTimeIsRejectedBeforeAnyWrite(string $time, string $message): void
    {
        $store = new RedisFullCapabilityStore($this->executor, self::NAMESPACE);

        foreach ([
            static fn() => $store->readGenerationBoundScoreStateAtMilliseconds('k4', null, $time),
            static fn() => $store->mutateGenerationBoundScoreAtMilliseconds('k4', null, null, 100, 5, $time),
            static fn() => $store->claimPostPunishmentReentryAtMilliseconds('k4', null, 'life', $time),
            static fn() => $store->addDistinctAcrossRotationAtMilliseconds('c', 'b', 'p', 'm1', 'm0', 60, $time),
            static fn() => $store->addDistinctBoundedAcrossRotationAtMilliseconds('c', 'b', 'p', 'm1', 'm0', 60, 3, $time),
            static fn() => $store->addDistinctBoundedWithSnapshotAcrossRotationAtMilliseconds('c', 'b', 'p', 'm1', 'm0', 60, 3, $time),
        ] as $call) {
            try {
                $call();
                self::fail('An unacceptable caller time must be rejected.');
            } catch (RateLimiterException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
            }
        }
        self::assertSame(0, $this->executor->execute(['DBSIZE']));
    }

    /** @return iterable<string, array{string, int}> */
    public static function unrepresentableExpiries(): iterable
    {
        // 1 s of TTL needs 1000 ms of headroom below 2^53 - 1; this clock leaves 991.
        yield 'one second of TTL past the exact range' => ['9007199254740000', 1];
        yield 'a TTL whose milliseconds exceed a native integer' => [self::START_MILLISECONDS, intdiv(PHP_INT_MAX, 1000) + 1];
    }

    #[DataProvider('unrepresentableExpiries')]
    public function testAGenerationExpiryBeyondTheExactRangeIsRejectedWithTheExistingClassification(string $time, int $ttlSeconds): void
    {
        $store = new RedisFullCapabilityStore($this->executor, self::NAMESPACE);

        try {
            $store->mutateGenerationBoundScoreAtMilliseconds('k4', null, null, $ttlSeconds, 1, $time);
            self::fail('An expiry that is not representable must be rejected.');
        } catch (RateLimiterException $exception) {
            self::assertSame('Generation-bound score expiry is not representable.', $exception->getMessage());
        }
        self::assertSame(0, $this->executor->execute(['DBSIZE']));
    }

    private function runtime(\Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface $executor, FixedClock $clock, RateLimiterConfig $config): CompositeRateLimiterRuntimeInterface
    {
        return RateLimiterBuilder::fromFullCapabilityStore($config, new RedisFullCapabilityStore($executor, self::NAMESPACE), new RecordingFailureSignalEmitter())
            ->withClock($clock)
            ->withPolicy(new LoginProtectionPolicy())
            ->build();
    }

    private function context(): RateLimitContextDTO
    {
        return new RateLimitContextDTO('198.51.100.40', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36', 'acct-life', null, 'dev-life', false, [], false, null, clientInstanceId: 'dev-life');
    }

    /** Computed here on its own so the test does not borrow the production formatter. */
    private function exactMilliseconds(DateTimeImmutable $instant): string
    {
        return $instant->format('U') . $instant->format('v');
    }
}
