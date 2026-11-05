<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-185-19 (#185, the Lead's adv6/r4): a persisted block level, watch count and budget count is used only when it is
 * canonical decimal text (a level is one digit 1..6, L1-L6; a counter is '0' or has no leading zero). On `e513638` they
 * went through `tonumber()` or `^%-?%d+$` first: a level "02", "2.0", "0x2", " 2", "2e0" or "+2" was read as 2, a watch
 * count "-5" was returned and incremented to -4, a budget count "-5" or "05" was used. Now each is `malformed …` from the
 * script itself (not a raw Redis error), and the state is left byte-identical: no delete, no repair (DEC-017).
 */
#[Group('f-185-19')]
final class CanonicalStoredValueRedisTest extends TestCase
{
    private const NOW = 1_793_793_600;

    private const NAMESPACE = 'f185-19';

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

    private function key(string $family, string $logical): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':' . $family . ':' . hash('sha256', $logical);
    }

    /** @return array<string, string> `DUMP` of every key. */
    private function dump(): array
    {
        $keys = $this->executor->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        $out = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $bytes = $this->executor->execute(['DUMP', $key]);
            self::assertIsString($bytes);
            $out[$key] = $bytes;
        }
        ksort($out);

        return $out;
    }

    private function assertMalformed(callable $operation, string $message): void
    {
        $before = $this->dump();
        try {
            $operation();
            self::fail('A non-canonical persisted value was accepted.');
        } catch (BackendFailureException $exception) {
            self::fail('Malformed state raised BackendFailureException: ' . $exception->getMessage());
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
        self::assertSame($before, $this->dump(), 'Malformed state is left byte-identical.');
    }

    /** @return iterable<string, array{string}> */
    public static function nonCanonicalLevels(): iterable
    {
        foreach (['02', '2.0', '0x2', ' 2', '2e0', '+2', '7', '0'] as $level) {
            yield json_encode($level, JSON_THROW_ON_ERROR) => [$level];
        }
    }

    #[DataProvider('nonCanonicalLevels')]
    public function testANonCanonicalBlockLevelIsMalformedOnEveryReader(string $level): void
    {
        $this->store->mutateGenerationBoundScoreAt('k', null, null, 600, 5, self::NOW * 1000);
        $this->store->blockAt('k', 2, 600, self::NOW);
        $this->executor->execute(['HSET', $this->key('block', 'k'), 'level', $level]);

        $this->assertMalformed(fn() => $this->store->checkBlockAt('k', self::NOW), 'malformed block state');
        $this->assertMalformed(fn() => $this->store->readGenerationBoundScoreStateAt('k', null, self::NOW * 1000), 'malformed hard-block state');
        $this->assertMalformed(fn() => $this->store->claimPostPunishmentReentryAt('k', null, str_repeat('a', 32), self::NOW * 1000), 'malformed hard-block state');
        $this->assertMalformed(fn() => $this->store->blockWithCycleTracking('k', null, 2, 60, self::NOW, 600, 3, 300, 86400), 'malformed hard-block state');
    }

    public function testControlCanonicalLevelsOneToSixAreRead(): void
    {
        foreach (['1', '2', '6'] as $level) {
            $this->store->blockAt('k', 2, 600, self::NOW);
            $this->executor->execute(['HSET', $this->key('block', 'k'), 'level', $level]);

            self::assertSame((int) $level, $this->store->checkBlockAt('k', self::NOW)?->level);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function nonCanonicalCounts(): iterable
    {
        foreach (['05', '-5', '+5', '1e1', '5.0', '0x10', ' 5', '-0'] as $count) {
            yield json_encode($count, JSON_THROW_ON_ERROR) => [$count];
        }
    }

    #[DataProvider('nonCanonicalCounts')]
    public function testANonCanonicalWatchCountIsMalformedBeforeIncr(string $count): void
    {
        $this->store->incrementWatchFlagAt('w', 600, self::NOW);
        $this->executor->execute(['SET', $this->key('watch', 'w'), $count, 'KEEPTTL']);

        $this->assertMalformed(fn() => $this->store->getWatchFlag('w'), 'malformed watch value');
        $this->assertMalformed(fn() => $this->store->incrementWatchFlagAt('w', 600, self::NOW), 'malformed watch value');
    }

    #[DataProvider('nonCanonicalCounts')]
    public function testANonCanonicalBudgetCountIsMalformed(string $count): void
    {
        $this->store->incrementBudgetAt('b', 86400, 1, self::NOW);
        $this->executor->execute(['HSET', $this->key('budget', 'b'), 'count', $count]);

        $this->assertMalformed(fn() => $this->store->getBudgetAt('b', self::NOW), 'malformed budget state');
        $this->assertMalformed(fn() => $this->store->incrementBudgetAt('b', 86400, 1, self::NOW), 'malformed budget state');
    }

    public function testABudgetCountAbovePhpIntMaxIsMalformed(): void
    {
        $this->store->incrementBudgetAt('b', 86400, 1, self::NOW);
        $this->executor->execute(['HSET', $this->key('budget', 'b'), 'count', '9223372036854775808']);

        $this->assertMalformed(fn() => $this->store->getBudgetAt('b', self::NOW), 'malformed budget state');
        $this->assertMalformed(fn() => $this->store->incrementBudgetAt('b', 86400, 1, self::NOW), 'malformed budget state');
    }

    public function testControlCanonicalCountsAreUsed(): void
    {
        foreach (['0', '5'] as $count) {
            $this->executor->execute(['FLUSHDB']);
            $this->store->incrementWatchFlagAt('w', 600, self::NOW);
            $this->executor->execute(['SET', $this->key('watch', 'w'), $count, 'KEEPTTL']);
            self::assertSame((int) $count, $this->store->getWatchFlag('w'));
            self::assertSame((int) $count + 1, $this->store->incrementWatchFlagAt('w', 600, self::NOW));

            $this->store->incrementBudgetAt('b', 86400, 1, self::NOW);
            $this->executor->execute(['HSET', $this->key('budget', 'b'), 'count', $count]);
            self::assertSame((int) $count, $this->store->getBudgetAt('b', self::NOW)?->count);
            self::assertSame((int) $count + 1, $this->store->incrementBudgetAt('b', 86400, 1, self::NOW)->count);
        }
    }
}
