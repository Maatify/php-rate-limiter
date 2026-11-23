<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #287: a request commit (`transactRequest()`) keeps the physical expiry a key already has, to the millisecond, or refuses before the first
 * write when Lua cannot read that expiry exactly. Lua reads `PEXPIRETIME` as a double, which holds integers exactly only below 2^53, so an
 * expiry at or above 2^53 milliseconds used to be saved one millisecond off, and a rival that moved only that expiry was not noticed.
 *
 * Every check reads `DUMP` and `PEXPIRETIME` from outside Lua as decimal text and compares them byte for byte and millisecond for millisecond.
 */
#[Group('integration')]
#[Group('php32')]
#[Group('request-expiry-stability')]
final class RequestSaveExpiryStabilityRedisTest extends TestCase
{
    private const NOW = 1_793_793_600;

    private const NAMESPACE = 'expiry-287';

    /** The first integer a double cannot tell from its neighbour: 2^53. */
    private const EXACT_LIMIT = '9007199254740992';

    private RespRedisCommandExecutor $executor;

    private RedisFullCapabilityStore $store;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->executor = new RespRedisCommandExecutor($host, (int) $port);
        $this->executor->execute(['FLUSHDB']);
        $this->store = new RedisFullCapabilityStore($this->executor, self::NAMESPACE);
    }

    private function rawKey(): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':budget:' . hash('sha256', 'b');
    }

    /** Seed an active-epoch budget; `$expiry` is the exact absolute expiry in milliseconds as decimal text, or null for none. */
    private function seed(?string $expiry): void
    {
        $key = $this->rawKey();
        $this->executor->execute(['HSET', $key, 'count', '1', 'epochStart', (string) self::NOW, 'epochDuration', '86400']);
        if ($expiry !== null) {
            self::assertSame(1, $this->executor->execute(['PEXPIREAT', $key, $expiry]));
        }
    }

    /** @return array{string, string} byte-exact DUMP (hex) and PEXPIRETIME as decimal text */
    private function observed(): array
    {
        $dump = $this->executor->execute(['DUMP', $this->rawKey()]);
        self::assertIsString($dump);
        $expiry = $this->executor->execute(['PEXPIRETIME', $this->rawKey()]);
        self::assertTrue(is_int($expiry) || is_string($expiry));

        return [bin2hex($dump), (string) $expiry];
    }

    private function expiryOf(string $key): string
    {
        $expiry = $this->executor->execute(['PEXPIRETIME', $key]);
        self::assertTrue(is_int($expiry) || is_string($expiry));

        return (string) $expiry;
    }

    /** The counter as Redis holds it after the call, read from outside the store. */
    private function storedCount(): string
    {
        $count = $this->executor->execute(['HGET', $this->rawKey(), 'count']);
        self::assertIsString($count);

        return $count;
    }

    /** @param callable(): ?GenerationBoundScoreStateDTO $call */
    private function outcome(callable $call): string
    {
        try {
            $state = $call();

            return 'answer:' . ($state === null ? 'null' : $state->value . ':' . $state->expiresAt);
        } catch (RateLimiterException $exception) {
            return 'refusal:' . $exception->getMessage();
        }
    }

    private function increment(): BudgetStateDTO
    {
        return $this->store->incrementBudgetAt('b', 86400, 1, self::NOW + 5);
    }

    /** @return array<string, array{string}> */
    public static function exactlyReadableExpiries(): array
    {
        $ordinary = (string) ((time() + 86400) * 1000 + 123);

        return [
            'ordinary expiry with odd milliseconds' => [$ordinary],
            'one below the exact limit' => ['9007199254740991'],
            'two below the exact limit' => ['9007199254740990'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function unreadableExpiries(): array
    {
        return [
            'the exact limit' => [self::EXACT_LIMIT],
            'one above the exact limit' => ['9007199254740993'],
            'three above the exact limit' => ['9007199254740995'],
            'far above' => ['9223372036854775000'],
            'the Redis maximum' => ['9223372036854775807'],
        ];
    }

    #[DataProvider('exactlyReadableExpiries')]
    public function testAnExpiryLuaReadsExactlyIsSavedLiterally(string $expiry): void
    {
        $this->seed($expiry);

        $state = $this->store->transactRequest(fn(): BudgetStateDTO => $this->increment());

        self::assertSame(2, $state->count);
        self::assertSame('2', $this->storedCount(), 'The save reached Redis: the counter is measured there, not in the reply.');
        self::assertSame($expiry, $this->observed()[1], 'The expiry is kept to the millisecond.');
    }

    public function testAKeyWithoutExpiryStaysWithoutExpiryThroughARequestCommit(): void
    {
        // The circuit-breaker state is a plain key with no expiry; a request that rewrites it must not give it one.
        $this->store->save('p', new CircuitBreakerStateDTO('CLOSED', [], 0, 0, 0, []));
        $key = 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':circuit:' . hash('sha256', 'p');
        $before = $this->expiryOf($key);

        $this->store->transactRequest(function (): void {
            $state = $this->store->load('p');
            self::assertNotNull($state);
            $this->store->save('p', new CircuitBreakerStateDTO('OPEN', [], 5, 5, 0, [], 0));
        });

        self::assertSame('-1', $before, 'The key starts without an expiry.');
        self::assertSame($before, $this->expiryOf($key), 'The request commit gave it none.');
    }

    #[DataProvider('unreadableExpiries')]
    public function testAnExpiryLuaCannotReadExactlyRefusesTheSaveBeforeTheFirstWrite(string $expiry): void
    {
        $this->seed($expiry);
        $before = $this->observed();
        self::assertSame($expiry, $before[1]);

        $caught = null;
        try {
            $this->store->transactRequest(fn(): BudgetStateDTO => $this->increment());
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        self::assertSame($before, $this->observed(), 'Nothing was written: the value and the expiry are unchanged.');
        self::assertInstanceOf(RateLimiterException::class, $caught);
        self::assertStringContainsString('expiry beyond the exactly readable range', $caught->getMessage());
        self::assertStringNotContainsString('malformed plan', $caught->getMessage(), 'The exactness guard comes before the validation of a plan derived from a rounded value (F294-04).');
        self::assertStringNotContainsString($this->rawKey(), $caught->getMessage(), 'No key name in the refusal.');
    }

    public function testARivalThatMovesOnlyTheExpiryByOneMillisecondIsDetectedAndTheRequestIsCalculatedAgain(): void
    {
        $base = (time() + 86400) * 1000 + 123; // one time sample: the rival differs from the seeded expiry by exactly 1 ms
        $expiry = (string) $base;
        $rival = (string) ($base + 1);
        $this->seed($expiry);

        $evaluations = 0;
        $state = $this->store->transactRequest(function () use (&$evaluations, $rival): BudgetStateDTO {
            $evaluations++;
            $state = $this->increment();
            if ($evaluations === 1) {
                // Between the read and the save a rival changes only the expiry.
                self::assertSame(1, $this->executor->execute(['PEXPIREAT', $this->rawKey(), $rival]));
            }

            return $state;
        });

        self::assertSame(2, $evaluations, 'The moved expiry is a conflict: the request is read and calculated again.');
        self::assertSame(2, $state->count);
        self::assertSame('2', $this->storedCount());
        self::assertSame($rival, $this->observed()[1], 'The rival expiry is preserved, not overwritten by the first read.');
    }

    public function testARivalThatCrossesIntoTheUnreadableRangeIsAConflictFirstAndTheSaveIsThenRefused(): void
    {
        // F294-02: the transition from 2^53-1 (exact) to 2^53+1 proves the state changed, so DEC-037's conflict path applies: the request is
        // evaluated again. The second evaluation reads an expiry that cannot be saved back exactly, so the save is refused before any write.
        $this->seed('9007199254740991');
        $rival = '9007199254740993';

        $evaluations = 0;
        $caught = null;
        try {
            $this->store->transactRequest(function () use (&$evaluations, $rival): BudgetStateDTO {
                $evaluations++;
                $state = $this->increment();
                if ($evaluations === 1) {
                    self::assertSame(1, $this->executor->execute(['PEXPIREAT', $this->rawKey(), $rival]));
                }

                return $state;
            });
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        self::assertSame(2, $evaluations, 'The crossing is a conflict: the request is evaluated again before it is refused.');
        self::assertInstanceOf(RateLimiterException::class, $caught);
        self::assertStringContainsString('expiry', $caught->getMessage());
        self::assertSame($rival, $this->observed()[1], 'The rival expiry is untouched.');
        self::assertSame('1', $this->storedCount(), 'Nothing was saved.');
    }

    public function testAReadInsideARequestAnswersAsTheDirectReadDoesWhenTheExpiryIsUnreadable(): void
    {
        // F294-01 (DEC-040): the guard concerns the save only. A legacy lifecycle read keeps its answer, or its own refusal, inside transactRequest().
        $key = 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':score:' . hash('sha256', 'k');
        $this->executor->execute(['HSET', $key, 'value', '8', 'updatedAt', '1']);
        self::assertSame(1, $this->executor->execute(['PEXPIREAT', $key, '9007199254740995']));
        $before = $this->executor->execute(['DUMP', $key]);
        $read = fn(): ?GenerationBoundScoreStateDTO => $this->store->readGenerationBoundScoreStateAtMilliseconds('k', null, '1800000000000');

        $direct = $this->outcome($read);
        $inside = $this->outcome(fn(): ?GenerationBoundScoreStateDTO => $this->store->transactRequest($read));

        self::assertSame($direct, $inside, 'Inside transactRequest() the read answers exactly as the direct read does.');
        if (PHP_INT_SIZE === 8) {
            self::assertStringStartsWith('answer:8:', $direct, 'On a 64-bit build the legacy read of this expiry succeeds.');
        }
        self::assertSame($before, $this->executor->execute(['DUMP', $key]));
        self::assertSame('9007199254740995', $this->expiryOf($key), 'A read changes nothing.');
    }

    private function scoreKey(): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':score:' . hash('sha256', 'a');
    }

    private function seedReadOnlyKey(string $expiry): void
    {
        $this->executor->execute(['HSET', $this->scoreKey(), 'value', '5', 'updatedAt', '1']);
        self::assertSame(1, $this->executor->execute(['PEXPIREAT', $this->scoreKey(), $expiry]));
    }

    public function testAReadOnlyKeyOfAWritingRequestMustBeProvablyUnchangedOrTheSaveIsRefused(): void
    {
        // F294-03: A is only read; a rival moves its expiry from ...995 to ...996, which Lua sees as the same double. The request writes B, so it is refused.
        $this->seed((string) ((time() + 86400) * 1000 + 123));
        $this->seedReadOnlyKey('9007199254740995');
        $rival = '9007199254740996';

        $caught = null;
        try {
            $this->store->transactRequest(function () use ($rival): BudgetStateDTO {
                $this->store->get('a');
                $state = $this->increment();
                self::assertSame(1, $this->executor->execute(['PEXPIREAT', $this->scoreKey(), $rival]));

                return $state;
            });
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(RateLimiterException::class, $caught);
        self::assertStringContainsString('expiry beyond the exactly readable range', $caught->getMessage());
        self::assertSame('1', $this->storedCount(), 'B was not written.');
        self::assertSame($rival, $this->expiryOf($this->scoreKey()), 'The rival expiry of A is untouched.');
    }

    public function testAReadOnlyRequestOverAnUnreadableExpiryIsNotRefused(): void
    {
        // DEC-040: reading alone stays as it was.
        $this->seedReadOnlyKey('9007199254740995');

        $state = $this->store->transactRequest(fn(): ?RateLimitStateDTO => $this->store->get('a'));

        self::assertNotNull($state);
        self::assertSame(5, $state->value);
        self::assertSame('9007199254740995', $this->expiryOf($this->scoreKey()));
    }

    public function testADirectCallCanCreateAnExpiryAboveTheRangeAndARequestSaveOverItIsRefused(): void
    {
        // F294-05: the direct call accepts a TTL the policies never use; the expiry it creates is beyond 2^53 ms.
        if (PHP_INT_SIZE < 8) {
            self::markTestSkipped('A 32-bit build cannot pass a TTL that large; the other cases reach the range with PEXPIREAT.');
        }
        $this->store->incrementAt('big', 9_007_199_254_741, 1, time());
        $key = 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':score:' . hash('sha256', 'big');
        self::assertGreaterThanOrEqual(self::EXACT_LIMIT, $this->expiryOf($key));
        $before = $this->expiryOf($key);

        $caught = null;
        try {
            $this->store->transactRequest(fn(): int => $this->store->incrementAt('big', 9_007_199_254_741, 1, time()));
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(RateLimiterException::class, $caught);
        self::assertStringContainsString('expiry beyond the exactly readable range', $caught->getMessage());
        self::assertSame($before, $this->expiryOf($key));
    }
}
