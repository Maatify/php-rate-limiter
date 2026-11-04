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
 * F-185-10 (#185): `blockWithPunishmentLifecycleTracking` validates the Current score's lifecycle evidence before any
 * optimistic comparison (`PunishmentLifecycleStoreInterface`, "structural validation of the persisted state always runs
 * before any optimistic comparison"). Malformed evidence (partial, bad id, bad `reentryValidUntil`, bad
 * `reentryGeneration`) is a malformed-state failure whether `$expectedGeneration` matches the stored generation, does not
 * match it, or the score is logically expired, and leaves every key byte-identical (`DUMP`). On `4e90064` a generation
 * mismatch or a logical expiry returned an ordinary unapplied conflict before the evidence was read.
 */
#[Group('f-185-10')]
final class LifecycleEvidenceValidationRedisTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const KEY = 'f185-10-score';

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
        $this->store = new RedisFullCapabilityStore($this->executor, 'f185-10');
    }

    /** @return array<string, list<string>> Evidence fields written on top of the core score fields. */
    private static function evidence(): array
    {
        $validUntil = (string) (self::NOW + 600);

        return [
            'partial' => ['reentryId', str_repeat('a', 32)],
            'bad-id' => ['reentryId', 'not-a-lifecycle-id', 'reentryValidUntil', $validUntil, 'reentryGeneration', '1'],
            'bad-until' => ['reentryId', str_repeat('a', 32), 'reentryValidUntil', '00' . $validUntil, 'reentryGeneration', '1'],
            'bad-generation' => ['reentryId', str_repeat('a', 32), 'reentryValidUntil', $validUntil, 'reentryGeneration', '0'],
            'valid' => ['reentryId', str_repeat('a', 32), 'reentryValidUntil', $validUntil, 'reentryGeneration', '1'],
        ];
    }

    /**
     * The three optimistic branches: the expected generation matches the stored one, differs from it, or matches it on
     * a score whose logical `expiresAt` has passed while its physical TTL is alive.
     *
     * @return array<string, array{int, bool}> expected generation, logically expired
     */
    private static function branches(): array
    {
        return ['expected = generation' => [1, false], 'expected != generation' => [2, false], 'logically expired' => [1, true]];
    }

    /** @return iterable<string, array{string, int, bool}> */
    public static function malformed(): iterable
    {
        foreach (self::evidence() as $evidence => $_) {
            if ($evidence === 'valid') {
                continue;
            }
            foreach (self::branches() as $branch => [$expected, $expired]) {
                yield $evidence . ' / ' . $branch => [$evidence, $expected, $expired];
            }
        }
    }

    private function seed(string $evidence, bool $expired): void
    {
        $updatedAt = $expired ? self::NOW - 100 : self::NOW;
        $expiresAt = $expired ? self::NOW - 10 : self::NOW + 600;
        $score = 'maatify:rate-limiter:v1:' . hash('sha256', 'f185-10') . ':score:' . hash('sha256', self::KEY);
        $this->executor->execute(['HSET', $score, 'value', '5', 'updatedAt', (string) $updatedAt, 'generation', '1', 'expiresAt', (string) $expiresAt, ...self::evidence()[$evidence]]);
        $this->executor->execute(['EXPIRE', $score, '600']);
    }

    private function publish(int $expectedGeneration): bool
    {
        return $this->store->blockWithPunishmentLifecycleTrackingAt(self::KEY, null, $expectedGeneration, str_repeat('b', 32), 2, 60, 600, 2, 60, 120, self::NOW)->applied;
    }

    /** @return array<string, string> `DUMP` of every key, so "unchanged" is byte-exact. */
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

    #[DataProvider('malformed')]
    public function testMalformedEvidenceFailsBeforeEveryOptimisticComparison(string $evidence, int $expectedGeneration, bool $expired): void
    {
        $this->seed($evidence, $expired);
        $before = $this->dump();

        try {
            $applied = $this->publish($expectedGeneration);
            self::fail('Malformed lifecycle evidence was accepted as ' . ($applied ? 'applied' : 'an unapplied conflict') . '.');
        } catch (BackendFailureException $exception) {
            self::fail('Malformed lifecycle evidence raised BackendFailureException: ' . $exception->getMessage());
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('malformed lifecycle', $exception->getMessage());
        }

        self::assertSame($before, $this->dump(), 'Malformed state must be left byte-identical.');
    }

    /** Control: valid evidence keeps the three optimistic outcomes. */
    public function testValidEvidenceKeepsTheOptimisticOutcomes(): void
    {
        foreach (self::branches() as $branch => [$expectedGeneration, $expired]) {
            $this->executor->execute(['FLUSHDB']);
            $this->seed('valid', $expired);
            $before = $this->dump();

            $applied = $this->publish($expectedGeneration);

            if ($expectedGeneration === 1 && ! $expired) {
                self::assertTrue($applied, $branch);
                self::assertNotSame($before, $this->dump(), $branch . ': an applied publication writes the block.');
            } else {
                self::assertFalse($applied, $branch);
                self::assertSame($before, $this->dump(), $branch . ': an ordinary conflict writes nothing.');
            }
        }
    }
}
