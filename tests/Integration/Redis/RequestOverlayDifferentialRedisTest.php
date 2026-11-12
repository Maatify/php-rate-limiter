<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * T-5 (DEC-037): the request overlay keeps the behaviour of the production Redis scripts. The same operations run in
 * order against two stores on one Redis: the first calls each store method directly (the scripts run on Redis), the
 * second runs each one inside `transactRequest()` (the same scripts run on the overlay and the commit saves the
 * result). After every operation the returned value, or the exception class and message, and the whole keyspace
 * (type, content, expiry) must be equal.
 */
#[Group('t5-request-atomicity')]
final class RequestOverlayDifferentialRedisTest extends TestCase
{
    private const NOW = 1_793_793_600;

    private const NOW_MS = self::NOW * 1000;

    private RespRedisCommandExecutor $executor;

    private RedisFullCapabilityStore $direct;

    private RedisFullCapabilityStore $overlay;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->executor = new RespRedisCommandExecutor($host, (int) $port);
        $this->executor->execute(['FLUSHDB']);
        $this->direct = new RedisFullCapabilityStore($this->executor, 'diff-direct');
        $this->overlay = new RedisFullCapabilityStore($this->executor, 'diff-overlay');
    }

    /**
     * @return array<string, array{string, mixed, int}> keyed by the key with its namespace replaced
     */
    private function state(string $namespace): array
    {
        $prefix = 'maatify:rate-limiter:v1:' . hash('sha256', $namespace) . ':';
        $keys = $this->executor->execute(['KEYS', $prefix . '*']);
        self::assertIsArray($keys);
        sort($keys);
        $state = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $type = $this->executor->execute(['TYPE', $key]);
            $type = is_array($type) ? ($type['ok'] ?? '') : $type;
            $content = match ($type) {
                'string' => $this->executor->execute(['GET', $key]),
                'hash' => $this->hash($this->executor->execute(['HGETALL', $key])),
                'set' => $this->members($this->executor->execute(['SMEMBERS', $key])),
                'zset' => $this->executor->execute(['ZRANGE', $key, 0, -1, 'WITHSCORES']),
                default => self::fail('Unexpected key type ' . (is_string($type) ? $type : 'unknown')),
            };
            $expiry = $this->executor->execute(['PEXPIRETIME', $key]);
            self::assertIsInt($expiry);
            $state['NS:' . substr($key, strlen($prefix))] = [(string) $type, $content, $expiry];
        }

        return $state;
    }

    /** @return list<string> */
    private function members(mixed $values): array
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
    private function hash(mixed $flat): array
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

    /** @return array{mixed, ?string} the value, or the exception class and message */
    private function outcome(callable $run): array
    {
        try {
            return [$run(), null];
        } catch (\Throwable $e) {
            return [null, $e::class . ': ' . $e->getMessage()];
        }
    }

    /** @param callable(RedisFullCapabilityStore): mixed $operation */
    private function both(string $name, callable $operation): void
    {
        $expected = $this->outcome(fn(): mixed => $operation($this->direct));
        $actual = $this->outcome(fn(): mixed => $this->overlay->transactRequest(fn(): mixed => $operation($this->overlay)));
        self::assertEquals($expected, $actual, $name . ': result');

        $directState = $this->state('diff-direct');
        $overlayState = $this->state('diff-overlay');
        self::assertSame(array_keys($directState), array_keys($overlayState), $name . ': keys');
        foreach ($directState as $key => [$type, $content, $expiry]) {
            self::assertSame($type, $overlayState[$key][0], $name . ': type of ' . $key);
            self::assertSame($content, $overlayState[$key][1], $name . ': content of ' . $key);
            self::assertEqualsWithDelta($expiry, $overlayState[$key][2], 3000, $name . ': expiry of ' . $key);
        }
    }

    /**
     * Raw state surgery applied identically to both namespaces.
     *
     * @param callable(\Closure(string, string): string): void $mutate
     */
    private function surgery(callable $mutate): void
    {
        foreach (['diff-direct', 'diff-overlay'] as $namespace) {
            $mutate(fn(string $family, string $logical): string => 'maatify:rate-limiter:v1:' . hash('sha256', $namespace) . ':' . $family . ':' . hash('sha256', $logical));
        }
    }

    public function testScoresBlocksBudgetsAndCountersBehaveAsTheScripts(): void
    {
        $now = self::NOW;
        $this->both('increment 1', fn($s) => $s->incrementAt('score', 3600, 2, $now));
        $this->both('increment 2', fn($s) => $s->incrementAt('score', 3600, 2, $now + 5));
        $this->both('snapshot write', fn($s) => $s->incrementIfSnapshotAt('score', 3600, 4, $now + 5, 7, $now + 9));
        $this->both('stale snapshot write', fn($s) => $s->incrementIfSnapshotAt('score', 3600, 4, $now + 5, 9, $now + 10));
        $this->both('replace', fn($s) => $s->replaceScoreAt('replaced', 9, 3600, $now));
        $this->both('set', fn($s) => $s->setAt('set', 5, 3600, $now));
        $this->both('overflow', fn($s) => $s->incrementAt('score', 3600, PHP_INT_MAX, $now + 11));
        $this->both('block', fn($s) => $s->blockAt('block', 2, 60, $now));
        $this->both('block read', fn($s) => $s->checkBlockAt('block', $now + 10));
        $this->both('block peek expired', fn($s) => $s->peekBlockAt('block', $now + 100));
        $this->both('block read expired (cleanup)', fn($s) => $s->checkBlockAt('block', $now + 100));
        $this->both('budget 1', fn($s) => $s->incrementBudgetAt('budget', 3600, 1, $now));
        $this->both('budget 2', fn($s) => $s->incrementBudgetAt('budget', 3600, 3, $now + 7));
        $this->both('budget read', fn($s) => $s->getBudgetAt('budget', $now + 8));
        $this->both('budget next epoch', fn($s) => $s->incrementBudgetAt('budget', 3600, 1, $now + 4000));
        $this->both('budget seeded', fn($s) => $s->incrementBudgetWithSeedAt('seeded', 3600, new BudgetStateDTO(4, $now), 1, $now + 1));
        $this->both('budget read expired (cleanup)', fn($s) => $s->getBudgetAt('budget', $now + 9000));
        $this->both('watch 1', fn($s) => $s->incrementWatchFlagAt('watch', 600, $now));
        $this->both('watch 2', fn($s) => $s->incrementWatchFlagAt('watch', 600, $now + 3));
        $this->both('watch after expiry', fn($s) => $s->incrementWatchFlagAt('watch', 600, $now + 700));
        $this->both('probe lease', fn($s) => $s->acquireProbeLease('policy', $now, 30));
        $this->both('probe lease held', fn($s) => $s->acquireProbeLease('policy', $now + 1, 30));
    }

    public function testDistinctSetsSnapshotsAndRotationBehaveAsTheScripts(): void
    {
        $now = self::NOW;
        $nowMs = self::NOW_MS;
        foreach (['a', 'b', 'a', 'c'] as $i => $member) {
            $this->both('distinct ' . $i, fn($s) => $s->addDistinctAt('distinct', $member, 600, $now + $i));
        }
        foreach (['a', 'b', 'c', 'a'] as $i => $member) {
            $this->both('bounded ' . $i, fn($s) => $s->addDistinctBoundedAt('bounded', $member, 600, 2, $now + $i));
        }
        foreach (['a', 'b', 'b'] as $i => $member) {
            $this->both('snapshot ' . $i, fn($s) => $s->addDistinctBoundedWithSnapshotAt('snapshot', $member, 600, 3, $now + $i));
        }
        $this->both('bounded expired', fn($s) => $s->addDistinctBoundedAt('bounded', 'z', 600, 2, $now + 700));
        foreach (['a', 'b', 'c'] as $i => $member) {
            $this->both('rotated ' . $i, fn($s) => $s->addDistinctBoundedWithSnapshotAcrossRotationAt('rot-cur', 'rot-bridge', 'rot-prev', $member, 'p' . $member, 600, 5, $nowMs + $i));
        }
        $this->surgery(function (\Closure $key): void {
            $this->executor->execute(['SADD', $key('distinct', 'rot-prev'), 'pa', 'pz']);
            $this->executor->execute(['EXPIRE', $key('distinct', 'rot-prev'), '500']);
            $this->executor->execute(['HSET', $key('distinct-meta', 'rot-prev'), 'expiresAt', (string) (self::NOW + 500)]);
            $this->executor->execute(['EXPIRE', $key('distinct-meta', 'rot-prev'), '500']);
        });
        $this->both('rotated over a live previous', fn($s) => $s->addDistinctBoundedWithSnapshotAcrossRotationAt('rot-cur', 'rot-bridge', 'rot-prev', 'new', 'pnew', 600, 5, $nowMs + 9));
        $this->both('rotated bounded', fn($s) => $s->addDistinctBoundedAcrossRotationAt('rot2-cur', 'rot2-bridge', 'rot-prev', 'q', 'pq', 600, 3, $nowMs + 10));
        $this->both('rotated alias', fn($s) => $s->addDistinctBoundedWithSnapshotAcrossRotationAt('rot-prev', 'alias-bridge', 'rot-prev', 'fresh', 'pfresh', 600, 5, $nowMs + 11));
        $this->both('rotated distinct', fn($s) => $s->addDistinctAcrossRotationAt('rot3-cur', 'rot3-bridge', 'rot-prev', 'x', 'px', 600, $nowMs + 12));
        $this->both('rotated watch', fn($s) => $s->incrementWatchFlagAcrossRotationAt('watch-cur', 'watch-prev', 600, $now));
        $this->both('device-cap pair', fn($s) => $s->addDeviceCapPairAt('cap-account', 'device-1', 10, 'cap-source', 'device-1', 10, 60, $now));
        $this->both('device-cap pair again', fn($s) => $s->addDeviceCapPairAt('cap-account', 'device-2', 10, 'cap-source', 'device-1', 10, 60, $now + 1));
        $this->both('observation group', fn($s) => $s->commitBoundedObservationGroup(static function () use ($s, $now): array {
            [$snapshot, $plan] = $s->readBoundedObservationPlanAt('group', 'member', 3, 600, $now);
            [$watch, $watchPlan] = $s->readWatchObservationPlanAt('group-watch', 1800, $now);

            return [[$snapshot->count, $watch], [$plan, $watchPlan]];
        }));
    }

    public function testHardBlocksLifecycleAndGenerationScoresBehaveAsTheScripts(): void
    {
        $now = self::NOW;
        $nowMs = self::NOW_MS;
        for ($i = 0; $i < 3; $i++) {
            $this->both('hard block ' . $i, fn($s) => $s->blockWithCycleTracking('hard', 'hard-prev', 2, 60, $now + $i * 70, 600, 2, 300, 900));
        }
        $this->both('decay pause', fn($s) => $s->readDecayPauseState('hard', 'hard-prev', $now, $now + 200));
        $this->both('generation read (absent)', fn($s) => $s->readGenerationBoundScoreStateAt('k4', null, $nowMs));
        $this->both('generation mutate (create)', fn($s) => $s->mutateGenerationBoundScoreAt('k4', null, null, 86400, 3, $nowMs));
        $state = $this->direct->readGenerationBoundScoreStateAt('k4', null, $nowMs);
        $this->both('generation read', fn($s) => $s->readGenerationBoundScoreStateAt('k4', null, $nowMs + 1000));
        $this->both('generation mutate (fenced)', fn($s) => $s->mutateGenerationBoundScoreAt('k4', null, $state, 86400, 6, $nowMs + 1000));
        $this->both('generation mutate (stale)', fn($s) => $s->mutateGenerationBoundScoreAt('k4', null, $state, 86400, 9, $nowMs + 2000));
        $id = str_repeat('a1', 16);
        $this->both('lifecycle block', fn($s) => $s->blockWithPunishmentLifecycleTrackingAt('k4', null, 1, $id, 2, 60, 600, 2, 300, 900, $nowMs + 3000));
        $this->both('lifecycle block again', fn($s) => $s->blockWithPunishmentLifecycleTrackingAt('k4', null, 2, str_repeat('b2', 16), 2, 60, 600, 2, 300, 900, $nowMs + 90_000));
        $this->both('claim', fn($s) => $s->claimPostPunishmentReentryAt('k4', null, $id, $nowMs + 91_000));
        $this->both('claim replay', fn($s) => $s->claimPostPunishmentReentryAt('k4', null, $id, $nowMs + 92_000));
    }

    public function testClassifiedRejectionsOfMalformedStateAreTheSameAndWriteNothing(): void
    {
        $now = self::NOW;
        $this->both('watch', fn($s) => $s->incrementWatchFlagAt('bad-watch', 600, $now));
        $this->both('bounded', fn($s) => $s->addDistinctBoundedWithSnapshotAt('bad-set', 'a', 600, 3, $now));
        $this->both('score', fn($s) => $s->incrementAt('bad-score', 3600, 1, $now));
        $this->both('block', fn($s) => $s->blockAt('bad-block', 2, 60, $now));
        $this->surgery(function (\Closure $key): void {
            $this->executor->execute(['DEL', $key('watch-meta', 'bad-watch')]);
            $this->executor->execute(['DEL', $key('distinct-meta', 'bad-set')]);
            $this->executor->execute(['HDEL', $key('score', 'bad-score'), 'updatedAt']);
            $this->executor->execute(['HSET', $key('block', 'bad-block'), 'expiresAt', 'not-a-number']);
        });
        $this->both('watch (meta deleted)', fn($s) => $s->incrementWatchFlagAt('bad-watch', 600, $now + 1));
        $this->both('bounded (meta deleted)', fn($s) => $s->addDistinctBoundedWithSnapshotAt('bad-set', 'b', 600, 3, $now + 1));
        $this->both('score (field deleted)', fn($s) => $s->incrementAt('bad-score', 3600, 1, $now + 1));
        $this->both('block (bad expiry)', fn($s) => $s->checkBlockAt('bad-block', $now + 1));
        $this->both('block publish (bad expiry)', fn($s) => $s->blockAt('bad-block', 3, 60, $now + 1));
    }
}
