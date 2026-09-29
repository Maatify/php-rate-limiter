<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\DTO\HardBlockCycleResultDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\TestCase;

final class RedisFullCapabilityStoreIntegrationTest extends TestCase
{
    private RedisFullCapabilityStore $store;

    private RespRedisCommandExecutor $executor;

    private string $namespace = 'integration:test';

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $portValue = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $portValue === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $port = (int) $portValue;
        $this->executor = new RespRedisCommandExecutor($host, $port);
        $this->executor->execute(['FLUSHDB']);
        $this->store = new RedisFullCapabilityStore($this->executor, $this->namespace);
    }

    public function testScoreBudgetCorrelationAndIsolationUseRealRedis(): void
    {
        self::assertTrue($this->store->isHealthy());
        self::assertSame(2, $this->store->increment('score', 60, 2));
        self::assertSame(5, $this->store->increment('score', 60, 3));
        self::assertSame(5, $this->store->get('score')?->value);
        $budget = $this->store->incrementBudget('budget', 60, 2);
        self::assertSame(2, $budget->count);
        $storedBudget = $this->store->getBudget('budget');
        self::assertNotNull($storedBudget);
        self::assertSame($budget->count, $storedBudget->count);
        self::assertSame($budget->epochStart, $storedBudget->epochStart);
        self::assertSame(1, $this->store->addDistinct('members', 'one', 60));
        self::assertSame(1, $this->store->incrementWatchFlag('watch', 60));
    }

    public function testOverCapBoundedStateFailsBeforeDuplicateOrNewMemberMutation(): void
    {
        $setKey = $this->key('distinct', 'over-cap');
        $metaKey = $this->key('distinct-meta', 'over-cap');
        $this->raw(['SADD', $setKey, 'one', 'two', 'three']);
        $this->raw(['EXPIRE', $setKey, '60']);
        $this->raw(['HSET', $metaKey, 'expiresAt', (string) ($this->redisNow() + 60)]);
        $this->raw(['EXPIRE', $metaKey, '60']);
        $before = [$this->raw(['SMEMBERS', $setKey]), $this->raw(['PTTL', $setKey]), $this->hashMap($metaKey)];

        $this->assertOperationFails(fn(): mixed => $this->store->addDistinctBoundedWithSnapshot('over-cap', 'one', 60, 2));
        $this->assertOperationFails(fn(): mixed => $this->store->addDistinctBoundedWithSnapshot('over-cap', 'four', 60, 2));
        self::assertSame($before[0], $this->raw(['SMEMBERS', $setKey]));
        self::assertGreaterThan(0, $this->integer($this->raw(['PTTL', $setKey])));
        self::assertSame($before[2], $this->hashMap($metaKey));
    }

    public function testBridgePhysicalTtlIsBoundedByPreviousPttlAndPreviousIsReadOnly(): void
    {
        $previous = $this->key('distinct', 'bridge-previous');
        $previousMeta = $this->key('distinct-meta', 'bridge-previous');
        $this->raw(['SADD', $previous, 'old']);
        $this->raw(['PEXPIRE', $previous, '10000']);
        $this->raw(['HSET', $previousMeta, 'expiresAt', (string) ($this->redisNow() + 60)]);
        $this->raw(['PEXPIRE', $previousMeta, '10000']);
        $beforeMembers = $this->raw(['SMEMBERS', $previous]);
        $beforePttl = $this->integer($this->raw(['PTTL', $previous]));

        $this->store->addDistinctBoundedWithSnapshotAcrossRotation('bridge-current', 'bridge', 'bridge-previous', 'new', 'unknown', 60, 10);
        $bridgePttl = $this->integer($this->raw(['PTTL', $this->key('distinct', 'bridge')]));
        $bridgeMetaPttl = $this->integer($this->raw(['PTTL', $this->key('distinct-meta', 'bridge')]));
        self::assertGreaterThan(0, $bridgePttl);
        self::assertGreaterThan(0, $bridgeMetaPttl);
        self::assertSame($beforeMembers, $this->raw(['SMEMBERS', $previous]));
        self::assertGreaterThan(0, $this->integer($this->raw(['PTTL', $previous])));
    }

    public function testBridgeUsesExactPreviousRemainingLifetimeAndNeverRefreshesIt(): void
    {
        $previous = $this->key('distinct', 'g12-bridge-previous');
        $previousMeta = $this->key('distinct-meta', 'g12-bridge-previous');
        $this->raw(['SADD', $previous, 'old']);
        $this->raw(['PEXPIRE', $previous, 6_000]);
        $expiry = $this->redisNow() + 2;
        $this->raw(['HSET', $previousMeta, 'expiresAt', (string) $expiry]);
        $this->raw(['PEXPIRE', $previousMeta, 6_000]);
        $beforeMembers = $this->raw(['SMEMBERS', $previous]);
        $beforeMeta = $this->hashMap($previousMeta);
        $beforeDataPttl = $this->integer($this->raw(['PTTL', $previous]));
        $beforeMetaPttl = $this->integer($this->raw(['PTTL', $previousMeta]));
        $authoritativeRemainingMs = ($expiry * 1000) - $this->redisNowMilliseconds();

        $this->store->addDistinctBoundedWithSnapshotAcrossRotation(
            'g12-bridge-current',
            'g12-bridge',
            'g12-bridge-previous',
            'new',
            'unknown',
            60,
            10,
        );

        $bridgePttl = $this->integer($this->raw(['PTTL', $this->key('distinct', 'g12-bridge')]));
        $bridgeMetaPttl = $this->integer($this->raw(['PTTL', $this->key('distinct-meta', 'g12-bridge')]));
        self::assertLessThanOrEqual($beforeDataPttl, $bridgePttl);
        self::assertLessThanOrEqual($beforeMetaPttl, $bridgePttl);
        self::assertLessThanOrEqual($beforeDataPttl, $bridgeMetaPttl);
        self::assertLessThanOrEqual($beforeMetaPttl, $bridgeMetaPttl);
        self::assertLessThanOrEqual($authoritativeRemainingMs, $bridgePttl);
        self::assertLessThanOrEqual($authoritativeRemainingMs, $bridgeMetaPttl);
        self::assertSame($expiry, $this->integer($this->raw(['HGET', $this->key('distinct-meta', 'g12-bridge'), 'expiresAt'])));
        self::assertSame($beforeMembers, $this->raw(['SMEMBERS', $previous]));
        self::assertSame($beforeMeta, $this->hashMap($previousMeta));
        self::assertLessThanOrEqual($beforeDataPttl, $this->integer($this->raw(['PTTL', $previous])));
        self::assertLessThanOrEqual($beforeMetaPttl, $this->integer($this->raw(['PTTL', $previousMeta])));
    }

    public function testPersistentCycleAndPauseStateWithoutTtlFailsBeforeMutation(): void
    {
        $now = $this->redisNow();
        $cases = [
            ['cycle', 'g12-current-cycle-no-ttl', null],
            ['cycle', 'g12-previous-cycle-no-ttl', 'g12-previous-cycle-no-ttl-source'],
            ['pause', 'g12-current-pause-no-ttl', null],
            ['pause', 'g12-previous-pause-no-ttl', 'g12-previous-pause-no-ttl-source'],
        ];

        foreach ($cases as [$family, $current, $previous]) {
            $source = $previous ?? $current;
            $key = $this->key($family, $source);
            if ($family === 'cycle') {
                $this->raw(['ZADD', $key, $now, (string) $now]);
            } else {
                $this->raw(['ZADD', $key, $now, $now . ':' . ($now + 30)]);
            }
            $before = [$this->raw(['ZRANGE', $key, 0, -1, 'WITHSCORES']), $this->integer($this->raw(['PTTL', $key]))];

            if ($family === 'cycle') {
                $this->assertOperationFails(fn(): mixed => $this->store->blockWithCycleTracking($current, $previous, 2, 60, $now, 600, 2, 60, 120));
            } else {
                $this->assertOperationFails(fn(): mixed => $this->store->readDecayPauseState($current, $previous, $now - 60, $now));
            }

            self::assertSame($before[0], $this->raw(['ZRANGE', $key, 0, -1, 'WITHSCORES']));
            self::assertSame(-1, $before[1]);
            self::assertSame(-1, $this->integer($this->raw(['PTTL', $key])));
        }
    }

    public function testPersistentPausePublicationFailsBeforeAnyLifecycleMutation(): void
    {
        foreach ([
            ['g12-persistent-current-pause', null],
            ['g12-persistent-current-pause-new', 'g12-persistent-previous-pause'],
        ] as [$current, $previous]) {
            $source = $previous ?? $current;
            $now = $this->redisNow();
            $pause = $this->key('pause', $source);
            $this->raw(['ZADD', $pause, $now, $now . ':' . ($now + 30)]);
            $keys = [];
            foreach (['block', 'cycle', 'pause'] as $family) {
                $key = $this->key($family, $source);
                $state = $family === 'block' ? $this->raw(['HGETALL', $key]) : $this->raw(['ZRANGE', $key, 0, -1, 'WITHSCORES']);
                $keys[$family] = [$this->raw(['EXISTS', $key]), $state, $this->integer($this->raw(['PTTL', $key]))];
            }
            $currentKeys = [$this->key('block', $current), $this->key('cycle', $current), $this->key('pause', $current)];
            $this->assertOperationFails(fn(): mixed => $this->store->blockWithCycleTracking($current, $previous, 2, 60, $now, 600, 2, 60, 120));
            foreach ($keys as $family => [$exists, $state, $pttl]) {
                $key = $this->key($family, $source);
                self::assertSame($exists, $this->raw(['EXISTS', $key]));
                if ($family === 'block') {
                    self::assertSame($state, $this->raw(['HGETALL', $key]));
                } else {
                    self::assertSame($state, $this->raw(['ZRANGE', $key, 0, -1, 'WITHSCORES']));
                }
                self::assertSame($pttl, $this->integer($this->raw(['PTTL', $key])));
            }
            foreach ($currentKeys as $familyIndex => $key) {
                $family = ['block', 'cycle', 'pause'][$familyIndex];
                if ($previous === null && $family === 'pause') {
                    self::assertSame(1, $this->integer($this->raw(['EXISTS', $key])));
                    self::assertSame($keys['pause'][1], $this->raw(['ZRANGE', $key, 0, -1, 'WITHSCORES']));
                    self::assertSame(-1, $this->integer($this->raw(['PTTL', $key])));
                } else {
                    self::assertSame(0, $this->integer($this->raw(['EXISTS', $key])));
                }
            }
        }
    }

    public function testGenerationPreservesExactIntegerAboveLuaDoubleRangeAndRejectsMaxIncrement(): void
    {
        $now = $this->redisNow();
        $generation = 9007199254740993;
        $key = $this->key('score', 'g12-exact-generation');
        $this->raw(['HSET', $key, 'value', '8', 'updatedAt', (string) $now, 'generation', (string) $generation, 'expiresAt', (string) ($now + 601)]);
        $this->raw(['EXPIRE', $key, 600]);
        $state = $this->store->readGenerationBoundScoreState('g12-exact-generation', null);
        self::assertNotNull($state);
        self::assertSame($generation, $state->generation);

        $mutation = $this->store->mutateGenerationBoundScore('g12-exact-generation', null, $state, 600, 9);
        self::assertTrue($mutation->applied);
        self::assertSame($generation + 1, $mutation->state?->generation);

        $maxKey = $this->key('score', 'g12-generation-max');
        $this->raw(['HSET', $maxKey, 'value', '8', 'updatedAt', (string) $now, 'generation', (string) PHP_INT_MAX, 'expiresAt', (string) ($now + 601)]);
        $this->raw(['EXPIRE', $maxKey, 600]);
        $before = $this->hashMap($maxKey);
        $maxState = new GenerationBoundScoreStateDTO(GenerationBoundScoreStateDTO::SOURCE_CURRENT, 8, $now, $now + 601, PHP_INT_MAX);
        $this->assertOperationFails(fn(): mixed => $this->store->mutateGenerationBoundScore('g12-generation-max', null, $maxState, 600, 9));
        self::assertSame($before, $this->hashMap($maxKey));
    }

    /**
     * The PHP-side ensureGenerationTtlRepresentable() preflight reads Redis
     * TIME once and can go stale before the EVAL that performs the actual
     * mutation reads Redis TIME again. Proving the boundary is genuinely
     * atomic requires showing that the LIFECYCLE_MUTATE script itself
     * rejects an unrepresentable deadline using its own same-script TIME
     * read, independent of whatever the PHP preflight decided. A decorator
     * that fabricates the bare ['TIME'] reply consumed only by the PHP
     * preflight (never the EVAL's internal redis.call('TIME')) makes the
     * preflight accept a TTL that the real, current Redis TIME inside the
     * script cannot represent: if the in-script guard were ever removed,
     * this test would fail because the write would go through.
     */
    public function testGenerationBoundScoreCreationEnforcesAtomicInScriptTtlGuard(): void
    {
        $key = 'g12-atomic-generation-ttl-guard';
        $scoreKey = $this->key('score', $key);
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $scoreKey])));

        $innerExecutor = $this->executor;
        $staleTimeExecutor = new class ($innerExecutor) implements RedisCommandExecutorInterface {
            public function __construct(private readonly RedisCommandExecutorInterface $inner) {}

            public function execute(array $command): mixed
            {
                if ($command === ['TIME']) {
                    return ['0', '0'];
                }

                return $this->inner->execute($command);
            }
        };
        $store = new RedisFullCapabilityStore($staleTimeExecutor, $this->namespace);

        $ttlSeconds = 9007199254740;
        $this->assertOperationFails(fn(): mixed => $store->mutateGenerationBoundScore($key, null, null, $ttlSeconds, 1));

        self::assertSame(0, $this->integer($this->raw(['EXISTS', $scoreKey])));
    }

    public function testScoreReadAndIncrementPreserveExactIntegersAboveLuaDoubleRange(): void
    {
        $key = $this->key('score', 'g12-exact-score');
        $this->raw(['HSET', $key, 'value', '9007199254740993', 'updatedAt', (string) $this->redisNow()]);
        $this->raw(['EXPIRE', $key, 60]);
        $initialState = $this->store->get('g12-exact-score');
        self::assertNotNull($initialState);
        self::assertSame(9007199254740993, $initialState->value);
        self::assertSame(9007199254740994, $this->store->increment('g12-exact-score', 60, 1));
        $incrementedState = $this->store->get('g12-exact-score');
        self::assertNotNull($incrementedState);
        self::assertSame(9007199254740994, $incrementedState->value);

        $overflow = $this->key('score', 'g12-score-overflow');
        $this->raw(['HSET', $overflow, 'value', (string) PHP_INT_MAX, 'updatedAt', (string) $this->redisNow()]);
        $this->raw(['EXPIRE', $overflow, 60]);
        $before = $this->hashMap($overflow);
        $this->assertOperationFails(fn(): mixed => $this->store->increment('g12-score-overflow', 60, 1));
        self::assertSame($before, $this->hashMap($overflow));
    }

    public function testUnrepresentableCallerTimeArithmeticFailsBeforeAnyRedisMutation(): void
    {
        $this->assertOperationFails(fn(): mixed => $this->store->acquireProbeLease('g12-overflow-lease', PHP_INT_MAX, 1));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('probe', 'g12-overflow-lease')])));

        $this->assertOperationFails(fn(): mixed => $this->store->blockWithCycleTracking('g12-overflow-block', null, 2, 1, PHP_INT_MAX, 60, 2, 30, 120));
        foreach (['block', 'cycle', 'pause'] as $family) {
            self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key($family, 'g12-overflow-block')])));
        }

        $this->assertOperationFails(fn(): mixed => $this->store->mutateGenerationBoundScore('g12-overflow-score', null, null, PHP_INT_MAX, 1));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('score', 'g12-overflow-score')])));

        $this->assertOperationFails(fn(): mixed => $this->store->mutateGenerationBoundScore('g12-lua-exact-overflow', null, null, 9007199254740992, 1));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('score', 'g12-lua-exact-overflow')])));

        $negativeNow = -9007199254740992;
        $this->assertOperationFails(fn(): mixed => $this->store->acquireProbeLease('g12-negative-lua-time', $negativeNow, 1));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('probe', 'g12-negative-lua-time')])));
        $this->assertOperationFails(fn(): mixed => $this->store->blockWithCycleTracking('g12-negative-lua-time-block', null, 2, 1, $negativeNow, 60, 2, 30, 120));
        foreach (['block', 'cycle', 'pause'] as $family) {
            self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key($family, 'g12-negative-lua-time-block')])));
        }
    }

    /**
     * `now` itself is exact within the Lua-safe integer range, but the
     * subtraction boundaries the HARD_BLOCK script derives from it
     * (`now - cycleWindow`, `now - pauseHistoryRetention`) are not. Each
     * must be rejected before any Redis mutation, not merely after `now`
     * passes its own addition-based preflight.
     */
    public function testHardBlockCycleSubtractionBoundaryFailsBeforeAnyRedisMutation(): void
    {
        $now = -9007199254740990;
        self::assertGreaterThanOrEqual(-9007199254740991, $now);
        self::assertLessThanOrEqual(9007199254740991, $now);

        $this->assertOperationFails(fn(): mixed => $this->store->blockWithCycleTracking('g12-cycle-window-subtraction-overflow', null, 2, 1, $now, 60, 2, 30, 120));
        foreach (['block', 'cycle', 'pause'] as $family) {
            self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key($family, 'g12-cycle-window-subtraction-overflow')])));
        }

        $this->assertOperationFails(fn(): mixed => $this->store->blockWithCycleTracking('g12-pause-retention-subtraction-overflow', null, 2, 1, $now, 1, 2, 30, 60));
        foreach (['block', 'cycle', 'pause'] as $family) {
            self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key($family, 'g12-pause-retention-subtraction-overflow')])));
        }
    }

    /**
     * G12-R03-A: `now`, `now + pause`, and `now + pauseHistoryRetention`
     * are each individually representable, but the actual boundary the
     * script computes when it activates a fresh pause —
     * `now + pause + pauseHistoryRetention`, i.e.
     * `latestPauseUntil + pauseHistoryRetention` — is not. This must be
     * rejected before Phase C, not merely after each addend passes its
     * own isolated check.
     */
    public function testHardBlockCombinedPauseRetentionBoundaryFailsBeforeAnyRedisMutation(): void
    {
        $now = 9007199254740991 - 100;
        $durationSeconds = 1;
        $cycleWindowSeconds = 60;
        $cycleThreshold = 1;
        $pauseSeconds = 50;
        $pauseHistoryRetentionSeconds = 100;

        self::assertLessThanOrEqual(9007199254740991, $now + $pauseSeconds);
        self::assertLessThanOrEqual(9007199254740991, $now + $pauseHistoryRetentionSeconds);
        self::assertGreaterThan(9007199254740991, $now + $pauseSeconds + $pauseHistoryRetentionSeconds);

        $this->assertOperationFails(fn(): mixed => $this->store->blockWithCycleTracking(
            'g12-combined-pause-retention-overflow',
            null,
            2,
            $durationSeconds,
            $now,
            $cycleWindowSeconds,
            $cycleThreshold,
            $pauseSeconds,
            $pauseHistoryRetentionSeconds,
        ));
        foreach (['block', 'cycle', 'pause'] as $family) {
            self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key($family, 'g12-combined-pause-retention-overflow')])));
        }
    }

    /**
     * G12-R03-A: a structurally valid persisted pause record, once
     * combined with this call's own retention parameter, pushes
     * `latestPauseUntil + pauseHistoryRetention` past the Lua-exact
     * boundary even though this call's own `now`/duration/window/pause
     * parameters are all ordinary small values. A pre-existing active
     * hard block keeps this call from starting a new cycle, so the
     * rejection is attributable only to the persisted pause history's own
     * derived arithmetic, not to this call's own `now`.
     */
    public function testHardBlockPersistedPauseHistoryDerivedBoundaryFailsBeforeAnyRedisMutation(): void
    {
        $finish = 9007199254740991 - 30;
        $start = $finish - 10;
        $now = 1_000;
        $pauseHistoryRetentionSeconds = 60;

        self::assertGreaterThan(9007199254740991, $finish + $pauseHistoryRetentionSeconds);

        $pauseKey = $this->key('pause', 'g12-persisted-pause-retention-overflow');
        $blockKey = $this->key('block', 'g12-persisted-pause-retention-overflow');
        $this->raw(['ZADD', $pauseKey, (string) $start, $start . ':' . $finish]);
        $this->raw(['EXPIRE', $pauseKey, 600]);
        $this->raw(['HSET', $blockKey, 'level', '2', 'expiresAt', (string) ($now + 3600)]);
        $this->raw(['EXPIRE', $blockKey, 600]);
        $beforePause = $this->raw(['ZRANGE', $pauseKey, 0, -1, 'WITHSCORES']);
        $beforeBlock = $this->hashMap($blockKey);

        $this->assertOperationFails(fn(): mixed => $this->store->blockWithCycleTracking(
            'g12-persisted-pause-retention-overflow',
            null,
            2,
            1,
            $now,
            60,
            2,
            1,
            $pauseHistoryRetentionSeconds,
        ));

        self::assertSame($beforePause, $this->raw(['ZRANGE', $pauseKey, 0, -1, 'WITHSCORES']));
        self::assertSame($beforeBlock, $this->hashMap($blockKey));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('cycle', 'g12-persisted-pause-retention-overflow')])));
    }

    /**
     * G12-R03-B: `durationSeconds` is a large-but-ordinarily-valid
     * positive PHP int. The punishment-lifecycle path uses real Redis
     * TIME (DEC-016), not caller time, so this cannot be preflighted from
     * PHP — the rejection must happen inside HARD_BLOCK itself, using the
     * very Redis TIME read it performs, before any write, leaving a
     * pre-existing valid generated score, its generation, and its
     * lifecycle evidence completely untouched.
     */
    public function testPunishmentLifecycleRedisTimeDerivedBoundaryFailsBeforeAnyRedisMutation(): void
    {
        $mutation = $this->store->mutateGenerationBoundScore('g12-lifecycle-redis-time-overflow', null, null, 600, 8);
        self::assertTrue($mutation->applied);
        self::assertNotNull($mutation->state);
        self::assertNotNull($mutation->state->generation);
        $generation = $mutation->state->generation;
        $score = $this->key('score', 'g12-lifecycle-redis-time-overflow');
        $beforeScore = $this->hashMap($score);

        $this->assertOperationFails(fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking(
            'g12-lifecycle-redis-time-overflow',
            null,
            $generation,
            str_repeat('a', 32),
            2,
            9007199254740991,
            600,
            2,
            60,
            120,
        ));

        self::assertSame($beforeScore, $this->hashMap($score));
        foreach (['block', 'cycle', 'pause'] as $family) {
            self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key($family, 'g12-lifecycle-redis-time-overflow')])));
        }
    }

    /**
     * G12-R03-C: each of these primitives previously wrote (HSET/SADD/SET)
     * before validating that the caller-supplied TTL could actually be
     * represented and applied via EXPIRE. A TTL this large is still an
     * ordinarily valid positive PHP int (PHP_INT_MAX), but must now be
     * rejected before the first write, not after a persisted hash/set
     * member is already created without — or with an unintended — TTL.
     */
    public function testBackendImpossibleTtlFailsBeforeFirstWriteAcrossMutationFamilies(): void
    {
        $hugeTtl = PHP_INT_MAX;

        $this->assertOperationFails(fn(): mixed => $this->store->increment('g12-r03c-score-increment', $hugeTtl, 1));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('score', 'g12-r03c-score-increment')])));

        $this->assertOperationFails(function (): void {
            $this->store->set('g12-r03c-score-set', 5, PHP_INT_MAX);
        });
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('score', 'g12-r03c-score-set')])));

        $this->assertOperationFails(function (): void {
            $this->store->block('g12-r03c-block-set', 2, PHP_INT_MAX);
        });
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('block', 'g12-r03c-block-set')])));

        $this->assertOperationFails(fn(): mixed => $this->store->addDistinct('g12-r03c-distinct-add', 'member', $hugeTtl));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('distinct', 'g12-r03c-distinct-add')])));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('distinct-meta', 'g12-r03c-distinct-add')])));

        $this->assertOperationFails(fn(): mixed => $this->store->incrementWatchFlag('g12-r03c-watch-increment', $hugeTtl));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('watch', 'g12-r03c-watch-increment')])));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('watch-meta', 'g12-r03c-watch-increment')])));

        $this->assertOperationFails(fn(): mixed => $this->store->addDistinctBoundedWithSnapshot('g12-r03c-bounded-snapshot', 'member', $hugeTtl, 5));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('distinct', 'g12-r03c-bounded-snapshot')])));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('distinct-meta', 'g12-r03c-bounded-snapshot')])));

        $this->assertOperationFails(fn(): mixed => $this->store->addDistinctBoundedWithSnapshotAcrossRotation('g12-r03c-rotated-current', 'g12-r03c-rotated-bridge', 'g12-r03c-rotated-previous', 'member', 'member', $hugeTtl, 5));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('distinct', 'g12-r03c-rotated-current')])));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('distinct-meta', 'g12-r03c-rotated-current')])));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('distinct', 'g12-r03c-rotated-bridge')])));

        $this->assertOperationFails(fn(): mixed => $this->store->incrementWatchFlagAcrossRotation('g12-r03c-watch-rotated-current', 'g12-r03c-watch-rotated-previous', $hugeTtl));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('watch', 'g12-r03c-watch-rotated-current')])));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('watch-meta', 'g12-r03c-watch-rotated-current')])));
    }

    /**
     * G12-R03-D / R03-C: a value merely above the Lua double's
     * exact-integer range (2^53) is NOT automatically corruption — Set/Hash
     * metadata `expiresAt` fields are validated and compared string-safe,
     * so they accept any canonical non-negative integer within the real
     * Redis relative-expiry backend bound (`intdiv(PHP_INT_MAX, 1000)`),
     * and probe-lease expiry accepts anything within PHP-int64 range.
     * Persisted hard-block cycle timestamps are the one deliberate
     * exception: they participate in real Redis ZSET-score arithmetic,
     * which genuinely cannot exceed Lua-exact precision, so that boundary
     * remains enforced there specifically (item 20). Each case below uses
     * a value that is genuinely beyond the relevant field's real backend
     * bound (not merely beyond 2^53), and each read/mutation-sensitive
     * path fails explicitly before any write.
     */
    public function testGenuinelyBackendImpossiblePersistedTemporalFieldsFailExplicitlyBeforeWrite(): void
    {
        $now = $this->redisNow();
        $abovePhpIntMax = (string) PHP_INT_MAX . '0';
        self::assertGreaterThan(PHP_INT_MAX, $abovePhpIntMax);
        $aboveBackendBound = '9223372036854776';
        self::assertGreaterThan(9223372036854775, (int) $aboveBackendBound);
        $aboveLuaExactRange = '9007199254740993';
        self::assertGreaterThan(9007199254740991, (int) $aboveLuaExactRange);

        $leaseKey = $this->key('probe', 'g12-r03d-lease');
        $this->raw(['SET', $leaseKey, $abovePhpIntMax]);
        $this->raw(['EXPIRE', $leaseKey, 600]);
        $this->assertOperationFails(fn(): mixed => $this->store->acquireProbeLease('g12-r03d-lease', $now, 60));
        self::assertSame($abovePhpIntMax, $this->raw(['GET', $leaseKey]));

        $blockKey = $this->key('block', 'g12-r03d-block');
        $this->raw(['HSET', $blockKey, 'level', '2', 'expiresAt', $aboveBackendBound]);
        $this->raw(['EXPIRE', $blockKey, 600]);
        $beforeBlock = $this->hashMap($blockKey);
        $this->assertOperationFails(fn(): mixed => $this->store->checkBlock('g12-r03d-block'));
        self::assertSame($beforeBlock, $this->hashMap($blockKey));

        $distinctMeta = $this->key('distinct-meta', 'g12-r03d-distinct');
        $distinctData = $this->key('distinct', 'g12-r03d-distinct');
        $this->raw(['SADD', $distinctData, 'existing']);
        $this->raw(['EXPIRE', $distinctData, 600]);
        $this->raw(['HSET', $distinctMeta, 'expiresAt', $aboveBackendBound]);
        $this->raw(['EXPIRE', $distinctMeta, 600]);
        $beforeMembers = $this->raw(['SMEMBERS', $distinctData]);
        $this->assertOperationFails(fn(): mixed => $this->store->addDistinct('g12-r03d-distinct', 'new-member', 60));
        self::assertSame($beforeMembers, $this->raw(['SMEMBERS', $distinctData]));

        $watchData = $this->key('watch', 'g12-r03d-watch');
        $watchMeta = $this->key('watch-meta', 'g12-r03d-watch');
        $this->raw(['SET', $watchData, '3']);
        $this->raw(['EXPIRE', $watchData, 600]);
        $this->raw(['HSET', $watchMeta, 'expiresAt', $aboveBackendBound]);
        $this->raw(['EXPIRE', $watchMeta, 600]);
        $this->assertOperationFails(fn(): mixed => $this->store->incrementWatchFlag('g12-r03d-watch', 60));
        self::assertSame('3', $this->raw(['GET', $watchData]));

        $cycleKey = $this->key('cycle', 'g12-r03d-cycle');
        $this->raw(['ZADD', $cycleKey, $aboveLuaExactRange, $aboveLuaExactRange]);
        $this->raw(['EXPIRE', $cycleKey, 600]);
        $beforeCycle = $this->raw(['ZRANGE', $cycleKey, 0, -1, 'WITHSCORES']);
        $this->assertOperationFails(fn(): mixed => $this->store->blockWithCycleTracking('g12-r03d-cycle', null, 2, 60, $now, 600, 2, 60, 120));
        self::assertSame($beforeCycle, $this->raw(['ZRANGE', $cycleKey, 0, -1, 'WITHSCORES']));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('block', 'g12-r03d-cycle')])));
    }

    /**
     * G12-R03-D Current lifecycle temporal matrix: `updatedAt`, `expiresAt`,
     * and `reentryValidUntil` are canonical non-negative PHP integers that
     * legitimately exceed 2^53 whenever the physical PTTL of the score key
     * itself stays within Lua-exact range (the physical-vs-authoritative
     * consistency check only ever rejects a physical deadline that
     * OUTLIVES the authoritative expiry, never the reverse). Read, mutate,
     * and claim must each preserve these values exactly rather than
     * silently rounding them.
     */
    public function testCurrentLifecycleTemporalFieldsAboveLuaExactRangeAreHandledExactly(): void
    {
        $aboveExact = 9007199254740993;
        $expiresAt = $aboveExact + 1000;
        $key = 'g12-r03d-current-matrix';
        $score = $this->key('score', $key);
        $reentryId = str_repeat('a', 32);
        $this->raw([
            'HSET', $score,
            'value', '8',
            'updatedAt', (string) $aboveExact,
            'generation', '1',
            'expiresAt', (string) $expiresAt,
            'reentryId', $reentryId,
            'reentryValidUntil', (string) $expiresAt,
            'reentryGeneration', '1',
        ]);
        $this->raw(['EXPIRE', $score, 600]);

        $state = $this->store->readGenerationBoundScoreState($key, null);
        self::assertNotNull($state);
        self::assertSame($aboveExact, $state->updatedAt);
        self::assertSame($expiresAt, $state->expiresAt);
        self::assertSame(1, $state->generation);
        self::assertNotNull($state->postPunishmentReentry);
        self::assertSame($reentryId, $state->postPunishmentReentry->id);
        self::assertSame($expiresAt, $state->postPunishmentReentry->validUntil);

        self::assertTrue($this->store->claimPostPunishmentReentry($key, null, $reentryId));
        self::assertSame($reentryId, $this->raw(['GET', $this->key('reentry-claim', $key)]));

        $mutation = $this->store->mutateGenerationBoundScore($key, null, $state, 600, 9);
        self::assertTrue($mutation->applied);
        self::assertNotNull($mutation->state);
        self::assertSame($expiresAt, $mutation->state->expiresAt);
        self::assertSame(2, $mutation->state->generation);
        self::assertSame(0, $this->integer($this->raw(['HEXISTS', $score, 'reentryId'])));
    }

    /**
     * G12-R03-D Previous lifecycle temporal matrix: the same exactness
     * requirement applies to a Previous-fallback snapshot, which remains
     * read-only — no refresh, no repair, no mutation of Previous itself.
     */
    public function testPreviousLifecycleTemporalFieldsAboveLuaExactRangeAreHandledExactlyAndReadOnly(): void
    {
        $aboveExact = 9007199254740995;
        $expiresAt = $aboveExact + 1000;
        $previousKey = 'g12-r03d-previous-matrix-previous';
        $currentKey = 'g12-r03d-previous-matrix-current';
        $previousScore = $this->key('score', $previousKey);
        $this->raw([
            'HSET', $previousScore,
            'value', '4',
            'updatedAt', (string) $aboveExact,
            'generation', '1',
            'expiresAt', (string) $expiresAt,
        ]);
        $this->raw(['EXPIRE', $previousScore, 600]);
        $before = $this->hashMap($previousScore);
        $beforePttl = $this->integer($this->raw(['PTTL', $previousScore]));

        $state = $this->store->readGenerationBoundScoreState($currentKey, $previousKey);
        self::assertNotNull($state);
        self::assertSame(GenerationBoundScoreStateDTO::SOURCE_PREVIOUS, $state->source);
        self::assertSame($aboveExact, $state->updatedAt);
        self::assertSame($expiresAt, $state->expiresAt);

        self::assertSame($before, $this->hashMap($previousScore));
        self::assertLessThanOrEqual($beforePttl, $this->integer($this->raw(['PTTL', $previousScore])));
    }

    /**
     * G12-R03-C item 17: every structural/temporal validation in
     * LIFECYCLE_CLAIM completes before the claim marker is written. A
     * malformed `reentryValidUntil` (non-canonical, not merely
     * out-of-range) must fail before SET/PEXPIREAT of the marker, leaving
     * no marker key behind.
     */
    public function testClaimMarkerNotCreatedWhenReentryValidUntilIsMalformed(): void
    {
        $now = $this->redisNow();
        $key = 'g12-r03d-claim-marker-malformed';
        $score = $this->key('score', $key);
        $reentryId = str_repeat('b', 32);
        $this->raw([
            'HSET', $score,
            'value', '5',
            'updatedAt', (string) $now,
            'generation', '1',
            'expiresAt', (string) ($now + 600),
            'reentryId', $reentryId,
            'reentryValidUntil', '00' . ($now + 600),
            'reentryGeneration', '1',
        ]);
        $this->raw(['EXPIRE', $score, 600]);
        $beforeScore = $this->hashMap($score);

        $this->assertOperationFails(fn(): mixed => $this->store->claimPostPunishmentReentry($key, null, $reentryId));

        self::assertSame($beforeScore, $this->hashMap($score));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('reentry-claim', $key)])));
    }

    /**
     * G12-R03-C item 9: a value above 2^53 is not automatically rejected —
     * primitives that can pass the TTL to Redis as a decimal string, or
     * compute `expiresAt` via exact decimal-string addition, must still
     * succeed exactly for a TTL like `2^53` (9007199254740992), which
     * exceeds the Lua double's exact-integer range but remains well within
     * both PHP-int and Redis relative-expiry representability.
     */
    public function testAboveLuaExactRangeTtlRemainsUsableWhereBackendSupportsIt(): void
    {
        $ttl = 9007199254740992;
        self::assertGreaterThan(9007199254740991, $ttl);

        self::assertSame(5, $this->store->increment('g12-r03c-above-exact-increment', $ttl, 5));
        $scoreState = $this->store->get('g12-r03c-above-exact-increment');
        self::assertNotNull($scoreState);
        self::assertSame(5, $scoreState->value);
        $scorePttlMs = $this->integer($this->raw(['PTTL', $this->key('score', 'g12-r03c-above-exact-increment')]));
        self::assertGreaterThan(0, $scorePttlMs);
        self::assertLessThanOrEqual($ttl * 1000, $scorePttlMs);
        self::assertGreaterThan(($ttl - 60) * 1000, $scorePttlMs);

        $before = $this->redisNow();
        $this->store->block('g12-r03c-above-exact-block', 3, $ttl);
        $blockState = $this->store->checkBlock('g12-r03c-above-exact-block');
        self::assertNotNull($blockState);
        self::assertSame(3, $blockState->level);
        self::assertGreaterThan(9007199254740991, $blockState->expiresAt);
        self::assertGreaterThanOrEqual($before + $ttl - 5, $blockState->expiresAt);
        self::assertLessThanOrEqual($before + $ttl + 5, $blockState->expiresAt);

        $snapshot = $this->store->addDistinctBoundedWithSnapshot('g12-r03c-above-exact-bounded', 'member-one', $ttl, 5);
        self::assertTrue($snapshot->accepted);
        self::assertTrue($snapshot->added);
        self::assertGreaterThan(9007199254740991, $snapshot->expiresAt);
        self::assertLessThanOrEqual($before + $ttl + 5, $snapshot->expiresAt);
    }

    /**
     * DEC-017: `0` is a valid Unix epoch timestamp, not a "no timestamp"
     * sentinel, for caller-supplied `$now`. The cycle-tracking path must
     * not confuse a legitimate epoch-zero cycle with "no cycle retained"
     * when deciding whether to extend the cycle key's TTL.
     */
    public function testEpochZeroCallerTimeBlockWithCycleTrackingSucceedsWithFiniteTtl(): void
    {
        $result = $this->store->blockWithCycleTracking('g12-dec017-epoch-zero', null, 2, 60, 0, 21_600, 2, 600, 86_400);

        self::assertTrue($result->newCycle);
        self::assertSame(1, $result->cycleCount);
        self::assertFalse($result->pauseActivated);
        self::assertSame(0, $result->pauseUntil);

        $cycleKey = $this->key('cycle', 'g12-dec017-epoch-zero');
        self::assertSame(['0'], $this->raw(['ZRANGE', $cycleKey, 0, -1]));
        $cyclePttl = $this->integer($this->raw(['PTTL', $cycleKey]));
        self::assertGreaterThan(0, $cyclePttl);

        $blockKey = $this->key('block', 'g12-dec017-epoch-zero');
        self::assertSame(1, $this->integer($this->raw(['EXISTS', $blockKey])));
        $blockPttl = $this->integer($this->raw(['PTTL', $blockKey]));
        self::assertGreaterThan(0, $blockPttl);
        self::assertSame('60', $this->raw(['HGET', $blockKey, 'expiresAt']));

        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('pause', 'g12-dec017-epoch-zero')])));
    }

    /**
     * DEC-017: a negative caller-supplied semantic timestamp is rejected
     * explicitly before any backend access, for both the mutating
     * cycle-tracking capability and the read-only decay-pause capability.
     */
    public function testNegativeCallerSemanticTimestampsFailExplicitlyBeforeAnyRedisAccess(): void
    {
        $this->assertOperationFails(fn(): mixed => $this->store->blockWithCycleTracking('g12-dec017-negative-now', null, 2, 60, -1, 21_600, 2, 600, 86_400));
        foreach (['block', 'cycle', 'pause'] as $family) {
            self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key($family, 'g12-dec017-negative-now')])));
        }

        $this->assertOperationFails(fn(): mixed => $this->store->readDecayPauseState('g12-dec017-negative-read-now', null, 0, -1));
        $this->assertOperationFails(fn(): mixed => $this->store->readDecayPauseState('g12-dec017-negative-read-from', null, -1, 0));
    }

    /**
     * G12-R03-E: readDecayPauseState() is read-only and receives
     * caller-supplied semantic time per DEC-016 — it must never
     * substitute Redis TIME for it, but it must still refuse to compute
     * an interval whose arithmetic cannot be exact rather than silently
     * rounding, and it must reject a malformed persisted pause boundary
     * the same way.
     */
    public function testReadDecayPauseStateRejectsUnrepresentableCallerTimeAndMalformedHistory(): void
    {
        $this->assertOperationFails(fn(): mixed => $this->store->readDecayPauseState('g12-r03e-now-overflow', null, 0, PHP_INT_MAX));
        $this->assertOperationFails(fn(): mixed => $this->store->readDecayPauseState('g12-r03e-from-overflow', null, PHP_INT_MIN, 0));

        $now = $this->redisNow();
        $pauseKey = $this->key('pause', 'g12-r03e-malformed-history');
        $malformedFinish = '9007199254740993';
        $this->raw(['ZADD', $pauseKey, (string) ($now - 10), ($now - 10) . ':' . $malformedFinish]);
        $this->raw(['EXPIRE', $pauseKey, 600]);
        $this->assertOperationFails(fn(): mixed => $this->store->readDecayPauseState('g12-r03e-malformed-history', null, $now - 100, $now));
    }

    /**
     * The physical TTL of Current data/metadata is deliberately shortened
     * far below the 60-second TTL requested on each over-cap call. An
     * erroneous refresh back to a fresh 60s would then overshoot this
     * shortened baseline by a margin far larger than any elapsed-time
     * jitter between snapshot and assertion, so it cannot slip through
     * un-detected the way a same-magnitude before/after TTL comparison
     * could. Duplicate-member and new-member rejections are asserted
     * separately, each against its own freshly captured baseline.
     */
    public function testRotationOverCapFailsForDuplicateAndNewMemberWithoutBridgeMutation(): void
    {
        $current = $this->key('distinct', 'g12-rotation-over-cap');
        $meta = $this->key('distinct-meta', 'g12-rotation-over-cap');
        $this->raw(['SADD', $current, 'one', 'two', 'three']);
        $authoritativeExpiry = $this->redisNow() + 3600;
        $this->raw(['HSET', $meta, 'expiresAt', (string) $authoritativeExpiry]);
        $this->raw(['PEXPIRE', $current, 5_000]);
        $this->raw(['PEXPIRE', $meta, 5_000]);
        $bridge = $this->key('distinct', 'g12-rotation-bridge');
        $bridgeMeta = $this->key('distinct-meta', 'g12-rotation-bridge');
        $keysBefore = $this->redisKeys('distinct');
        $membersBaseline = $this->raw(['SMEMBERS', $current]);
        $metaBaseline = $this->hashMap($meta);

        $dataPttlBaseline = $this->integer($this->raw(['PTTL', $current]));
        $metaPttlBaseline = $this->integer($this->raw(['PTTL', $meta]));
        $this->assertOperationFails(fn(): mixed => $this->store->addDistinctBoundedWithSnapshotAcrossRotation('g12-rotation-over-cap', 'g12-rotation-bridge', 'missing-previous', 'one', 'one', 60, 2));
        self::assertSame($membersBaseline, $this->raw(['SMEMBERS', $current]));
        self::assertSame($metaBaseline, $this->hashMap($meta));
        self::assertLessThanOrEqual($dataPttlBaseline, $this->integer($this->raw(['PTTL', $current])));
        self::assertLessThanOrEqual($metaPttlBaseline, $this->integer($this->raw(['PTTL', $meta])));
        self::assertSame(-2, $this->integer($this->raw(['PTTL', $bridge])));
        self::assertSame(-2, $this->integer($this->raw(['PTTL', $bridgeMeta])));
        self::assertSame($keysBefore, $this->redisKeys('distinct'));

        $dataPttlBaseline = $this->integer($this->raw(['PTTL', $current]));
        $metaPttlBaseline = $this->integer($this->raw(['PTTL', $meta]));
        $this->assertOperationFails(fn(): mixed => $this->store->addDistinctBoundedWithSnapshotAcrossRotation('g12-rotation-over-cap', 'g12-rotation-bridge', 'missing-previous', 'four', 'four', 60, 2));
        self::assertSame($membersBaseline, $this->raw(['SMEMBERS', $current]));
        self::assertSame($metaBaseline, $this->hashMap($meta));
        self::assertLessThanOrEqual($dataPttlBaseline, $this->integer($this->raw(['PTTL', $current])));
        self::assertLessThanOrEqual($metaPttlBaseline, $this->integer($this->raw(['PTTL', $meta])));
        self::assertSame(-2, $this->integer($this->raw(['PTTL', $bridge])));
        self::assertSame(-2, $this->integer($this->raw(['PTTL', $bridgeMeta])));
        self::assertSame($keysBefore, $this->redisKeys('distinct'));
    }

    public function testAliasedRotationReadsPreviousWithoutWritingOrRefreshingIt(): void
    {
        $logical = 'g12-aliased-rotation';
        $physical = $this->key('distinct', $logical);
        $meta = $this->key('distinct-meta', $logical);
        $this->raw(['SADD', $physical, 'old']);
        $this->raw(['PEXPIRE', $physical, 5_000]);
        $this->raw(['HSET', $meta, 'expiresAt', (string) ($this->redisNow() + 60)]);
        $this->raw(['PEXPIRE', $meta, 5_000]);
        $members = $this->raw(['SMEMBERS', $physical]);
        $beforeMeta = $this->hashMap($meta);
        $beforePttl = $this->integer($this->raw(['PTTL', $physical]));

        $snapshot = $this->store->addDistinctBoundedWithSnapshotAcrossRotation($logical, 'g12-aliased-bridge', $logical, 'new', 'old', 60, 3);
        self::assertTrue($snapshot->accepted);
        self::assertFalse($snapshot->added);
        self::assertSame(1, $snapshot->count);
        self::assertSame(['old'], $snapshot->members);
        self::assertSame($members, $this->raw(['SMEMBERS', $physical]));
        self::assertSame($beforeMeta, $this->hashMap($meta));
        self::assertLessThanOrEqual($beforePttl, $this->integer($this->raw(['PTTL', $physical])));
        self::assertSame(-2, $this->integer($this->raw(['PTTL', $this->key('distinct', 'g12-aliased-bridge')])));
    }

    public function testAliasedRotationAcceptsNewLogicalRepresentationWithoutPhysicalPreviousMutation(): void
    {
        $logical = 'g12-aliased-rotation-new';
        $physical = $this->key('distinct', $logical);
        $meta = $this->key('distinct-meta', $logical);
        $this->raw(['SADD', $physical, 'old']);
        $this->raw(['PEXPIRE', $physical, 5_000]);
        $this->raw(['HSET', $meta, 'expiresAt', (string) ($this->redisNow() + 60)]);
        $this->raw(['PEXPIRE', $meta, 5_000]);
        $beforeMembers = $this->raw(['SMEMBERS', $physical]);
        $beforeMeta = $this->hashMap($meta);
        $beforeDataPttl = $this->integer($this->raw(['PTTL', $physical]));
        $beforeMetaPttl = $this->integer($this->raw(['PTTL', $meta]));

        $snapshot = $this->store->addDistinctBoundedWithSnapshotAcrossRotation($logical, 'g12-aliased-rotation-new-bridge', $logical, 'new', 'unknown', 60, 3);
        self::assertTrue($snapshot->accepted);
        self::assertTrue($snapshot->added);
        self::assertSame(2, $snapshot->count);
        self::assertSame(['new', 'old'], $snapshot->members);
        self::assertSame($this->integer($this->raw(['HGET', $meta, 'expiresAt'])), $snapshot->expiresAt);
        self::assertSame($beforeMembers, $this->raw(['SMEMBERS', $physical]));
        self::assertSame($beforeMeta, $this->hashMap($meta));
        self::assertLessThanOrEqual($beforeDataPttl, $this->integer($this->raw(['PTTL', $physical])));
        self::assertLessThanOrEqual($beforeMetaPttl, $this->integer($this->raw(['PTTL', $meta])));
        self::assertSame(-2, $this->integer($this->raw(['PTTL', $this->key('distinct', 'g12-aliased-rotation-new-bridge')])));
    }

    public function testInvalidCircuitStatusAndBlockLevelsFailExplicitly(): void
    {
        $circuit = $this->key('circuit', 'invalid-status');
        $this->raw(['SET', $circuit, json_encode(['status' => 'BROKEN', 'failures' => [], 'reEntries' => [], 'lastFailure' => 0, 'openSince' => 0, 'lastSuccess' => 0, 'failClosedUntil' => 0], JSON_THROW_ON_ERROR)]);
        $this->assertOperationFails(fn(): mixed => $this->store->load('invalid-status'));
        $this->assertOperationFails(function (): void {
            $this->store->block('invalid-level', 0, 60);
        });
        $this->assertOperationFails(function (): void {
            $this->store->block('invalid-level-high', 7, 60);
        });
        $this->assertOperationFails(function (): void {
            $this->store->block('invalid-level-negative', -1, 60);
        });
        foreach (['invalid-level', 'invalid-level-high', 'invalid-level-negative'] as $logical) {
            self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('block', $logical)])));
        }

        foreach (['0', '-1', '7'] as $index => $invalidLevel) {
            $logical = 'persisted-invalid-level-' . $index;
            $persisted = $this->key('block', $logical);
            $this->raw(['HSET', $persisted, 'level', $invalidLevel, 'expiresAt', (string) ($this->redisNow() + 60)]);
            $this->raw(['EXPIRE', $persisted, '60']);
            $this->assertOperationFails(fn(): mixed => $this->store->checkBlock($logical));
        }
    }

    public function testCycleAndPunishmentPublicationRejectUpperLevelBeforeMutation(): void
    {
        $now = $this->redisNow();
        $this->assertOperationFails(fn(): mixed => $this->store->blockWithCycleTracking('g12-level-seven-cycle', null, 7, 60, $now, 600, 2, 60, 120));
        foreach (['block', 'cycle', 'pause'] as $family) {
            self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key($family, 'g12-level-seven-cycle')])));
        }

        $mutation = $this->store->mutateGenerationBoundScore('g12-level-seven-punishment', null, null, 600, 8);
        self::assertTrue($mutation->applied);
        $score = $this->key('score', 'g12-level-seven-punishment');
        $beforeScore = $this->hashMap($score);
        $this->assertOperationFails(fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking('g12-level-seven-punishment', null, 1, str_repeat('a', 32), 7, 60, 600, 2, 60, 120));
        self::assertSame($beforeScore, $this->hashMap($score));
        foreach (['block', 'cycle', 'pause'] as $family) {
            self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key($family, 'g12-level-seven-punishment')])));
        }
    }

    /**
     * Complements the upper-boundary (L7) proof above with the lower
     * hard-block boundary: L1 is below the L2+ hard-block floor and must
     * be rejected before any Redis mutation for both cycle-tracking and
     * punishment-lifecycle publication, the latter leaving a pre-existing
     * valid generated score — including its lifecycle evidence — entirely
     * untouched.
     */
    public function testCycleAndPunishmentPublicationRejectLowerLevelBeforeMutation(): void
    {
        $now = $this->redisNow();
        $this->assertOperationFails(fn(): mixed => $this->store->blockWithCycleTracking('g12-level-one-cycle', null, 1, 60, $now, 600, 2, 60, 120));
        foreach (['block', 'cycle', 'pause'] as $family) {
            self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key($family, 'g12-level-one-cycle')])));
        }

        $score = $this->key('score', 'g12-level-one-punishment');
        $expiresAt = $now + 600;
        $this->raw([
            'HSET', $score,
            'value', '8',
            'updatedAt', (string) $now,
            'generation', '1',
            'expiresAt', (string) $expiresAt,
            'reentryId', str_repeat('e', 32),
            'reentryValidUntil', (string) $expiresAt,
            'reentryGeneration', '1',
        ]);
        $this->raw(['EXPIRE', $score, 600]);
        $beforePunishmentScore = $this->hashMap($score);

        $this->assertOperationFails(fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking('g12-level-one-punishment', null, 1, str_repeat('f', 32), 1, 60, 600, 2, 60, 120));
        self::assertSame($beforePunishmentScore, $this->hashMap($score));
        foreach (['block', 'cycle', 'pause'] as $family) {
            self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key($family, 'g12-level-one-punishment')])));
        }
    }

    public function testLifecycleRejectsOutOfPhpIntScoreBeforeClaimOrPublicationMutation(): void
    {
        $now = $this->redisNow();
        $invalid = '9223372036854775808';
        $claimKey = $this->key('score', 'g12-malformed-claim-score');
        $claimId = str_repeat('b', 32);
        $this->raw(['HSET', $claimKey, 'value', $invalid, 'updatedAt', (string) $now, 'generation', '1', 'expiresAt', (string) ($now + 600), 'reentryId', $claimId, 'reentryValidUntil', (string) ($now + 600), 'reentryGeneration', '1']);
        $this->raw(['EXPIRE', $claimKey, 600]);
        $beforeClaim = $this->hashMap($claimKey);
        $this->assertOperationFails(fn(): mixed => $this->store->claimPostPunishmentReentry('g12-malformed-claim-score', null, $claimId));
        self::assertSame($beforeClaim, $this->hashMap($claimKey));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('reentry-claim', 'g12-malformed-claim-score')])));

        $currentKey = $this->key('score', 'g12-malformed-current-publication');
        $this->raw(['HSET', $currentKey, 'value', $invalid, 'updatedAt', (string) $now, 'generation', '1', 'expiresAt', (string) ($now + 600)]);
        $this->raw(['EXPIRE', $currentKey, 600]);
        $beforeCurrent = $this->hashMap($currentKey);
        $this->assertOperationFails(fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking('g12-malformed-current-publication', null, 1, str_repeat('c', 32), 2, 60, 600, 2, 60, 120));
        self::assertSame($beforeCurrent, $this->hashMap($currentKey));
        foreach (['block', 'cycle', 'pause'] as $family) {
            self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key($family, 'g12-malformed-current-publication')])));
        }

        $previousKey = $this->key('score', 'g12-malformed-previous-publication');
        $this->raw(['HSET', $previousKey, 'value', $invalid, 'updatedAt', (string) $now, 'generation', '1', 'expiresAt', (string) ($now + 600)]);
        $this->raw(['EXPIRE', $previousKey, 600]);
        $beforePrevious = $this->hashMap($previousKey);
        $this->assertOperationFails(fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking('g12-malformed-previous-current', 'g12-malformed-previous-publication', 1, str_repeat('d', 32), 2, 60, 600, 2, 60, 120));
        self::assertSame($beforePrevious, $this->hashMap($previousKey));
        foreach (['block', 'cycle', 'pause'] as $family) {
            self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key($family, 'g12-malformed-previous-current')])));
        }
    }

    public function testPublicBuilderRejectsBrokenPersistedCircuitWithoutFallback(): void
    {
        $this->raw(['SET', $this->key('circuit', 'api_heavy_protection'), json_encode([
            'status' => 'BROKEN',
            'failures' => [],
            'reEntries' => [],
            'lastFailure' => 0,
            'openSince' => 0,
            'lastSuccess' => 0,
            'failClosedUntil' => 0,
        ], JSON_THROW_ON_ERROR)]);
        $limiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('g12-circuit-key', 'g12-circuit-fingerprint', 'prod'),
            $this->store,
            new RecordingFailureSignalEmitter(),
        )->build();
        $context = new RateLimitContextDTO('198.51.100.60', 'Mozilla/5.0', 'g12-broken-circuit', []);

        $this->assertOperationFails(fn(): mixed => $limiter->limit($context, new RateLimitCommand('api_heavy_protection')));
    }

    public function testEveryFullCapabilityOperationRoundTripsAgainstRealRedis(): void
    {
        $this->store->set('set-value', 7, 60);
        self::assertSame(7, $this->store->get('set-value')?->value);

        $this->store->block('blocked', 3, 60);
        self::assertSame(3, $this->store->checkBlock('blocked')?->level);

        $seed = new BudgetStateDTO(5, time() - 60);
        $budget = $this->store->incrementBudgetWithSeed('seeded-budget', 600, $seed, 2);
        self::assertSame(7, $budget->count);
        self::assertSame($seed->epochStart, $budget->epochStart);
        self::assertSame(7, $this->store->getBudget('seeded-budget')?->count);

        self::assertSame(1, $this->store->addDistinctAcrossRotation(
            'distinct-current',
            'distinct-bridge',
            'distinct-previous',
            'new-member',
            'unknown-previous-member',
            60,
        ));
        self::assertSame(1, $this->store->incrementWatchFlag('watch-previous', 60));
        self::assertSame(2, $this->store->incrementWatchFlagAcrossRotation('watch-current', 'watch-previous', 60));
        self::assertSame(1, $this->store->getWatchFlag('watch-current'));
    }

    public function testGenerationBoundLifecycleIsAtomicAndClaimDoesNotConsumeEvidence(): void
    {
        $mutation = $this->store->mutateGenerationBoundScore('lifecycle-current', null, null, 600, 8);
        self::assertTrue($mutation->applied);
        self::assertNotNull($mutation->state);
        self::assertSame(1, $mutation->state->generation);

        $id = str_repeat('a', 32);
        $transition = $this->store->blockWithPunishmentLifecycleTracking(
            'lifecycle-current',
            null,
            1,
            $id,
            2,
            1,
            21600,
            2,
            600,
            86400,
        );
        self::assertTrue($transition->applied);
        self::assertNotNull($transition->postPunishmentReentry);
        self::assertSame($id, $transition->postPunishmentReentry->id);

        $duringBlock = $this->store->readGenerationBoundScoreState('lifecycle-current', null);
        self::assertNotNull($duringBlock);
        self::assertNull($duringBlock->postPunishmentReentry);

        self::assertFalse($this->store->claimPostPunishmentReentry('lifecycle-current', null, $id));
        $this->executor->execute(['DEL', 'maatify:rate-limiter:v1:' . hash('sha256', $this->namespace) . ':block:' . hash('sha256', 'lifecycle-current')]);
        self::assertTrue($this->store->claimPostPunishmentReentry('lifecycle-current', null, $id));
        self::assertFalse($this->store->claimPostPunishmentReentry('lifecycle-current', null, $id));
        self::assertNotNull($this->store->readGenerationBoundScoreState('lifecycle-current', null)?->postPunishmentReentry);
    }

    public function testLifecycleCyclesUseRedisTimeExactlyOnceAndPauseIsNotRenewed(): void
    {
        $mutation = $this->store->mutateGenerationBoundScore('redis-time-cycle', null, null, 600, 8);
        self::assertTrue($mutation->applied);

        $first = $this->store->blockWithPunishmentLifecycleTracking(
            'redis-time-cycle',
            null,
            1,
            str_repeat('a', 32),
            2,
            60,
            21600,
            2,
            600,
            86400,
        );
        self::assertTrue($first->applied);
        self::assertNotNull($first->cycle);
        self::assertTrue($first->cycle->newCycle);
        self::assertSame(1, $first->cycle->cycleCount);
        self::assertFalse($first->cycle->pauseActivated);

        $refresh = $this->store->blockWithPunishmentLifecycleTracking(
            'redis-time-cycle',
            null,
            1,
            str_repeat('b', 32),
            2,
            60,
            21600,
            2,
            600,
            86400,
        );
        self::assertTrue($refresh->applied);
        self::assertNotNull($refresh->cycle);
        self::assertFalse($refresh->cycle->newCycle);
        self::assertSame(1, $refresh->cycle->cycleCount);
        self::assertFalse($refresh->cycle->pauseActivated);

        // End only the active block fixture; cycle and lifecycle evidence stay in Redis.
        $fixtureNow = $this->redisTime();
        $cycleKey = $this->key('cycle', 'redis-time-cycle');
        $this->raw(['DEL', $cycleKey]);
        $this->raw(['ZADD', $cycleKey, $fixtureNow - 1, (string) ($fixtureNow - 1)]);
        $this->raw(['EXPIRE', $cycleKey, 21600]);
        $this->raw(['DEL', $this->key('block', 'redis-time-cycle')]);
        $before = $this->redisTime();
        $second = $this->store->blockWithPunishmentLifecycleTracking(
            'redis-time-cycle',
            null,
            1,
            str_repeat('c', 32),
            2,
            60,
            21600,
            2,
            600,
            86400,
        );
        $after = $this->redisTime();

        self::assertTrue($second->applied);
        self::assertNotNull($second->cycle);
        self::assertTrue($second->cycle->newCycle);
        self::assertSame(2, $second->cycle->cycleCount);
        self::assertTrue($second->cycle->pauseActivated);
        self::assertGreaterThanOrEqual($before + 600, $second->cycle->pauseUntil);
        self::assertLessThanOrEqual($after + 600, $second->cycle->pauseUntil);

        $duplicate = $this->store->blockWithPunishmentLifecycleTracking(
            'redis-time-cycle',
            null,
            1,
            str_repeat('d', 32),
            2,
            60,
            21600,
            2,
            600,
            86400,
        );
        self::assertTrue($duplicate->applied);
        self::assertNotNull($duplicate->cycle);
        self::assertFalse($duplicate->cycle->newCycle);
        self::assertSame(2, $duplicate->cycle->cycleCount);
        self::assertFalse($duplicate->cycle->pauseActivated);
        self::assertSame($second->cycle->pauseUntil, $duplicate->cycle->pauseUntil);
    }

    public function testGenerationMutationInvalidatesOldLifecycleIdentityAndFreshPublicationUsesNewIdentity(): void
    {
        $first = $this->store->mutateGenerationBoundScore('lifecycle-fence', null, null, 600, 8);
        self::assertTrue($first->applied);
        self::assertSame(1, $first->state?->generation);
        $idA = str_repeat('a', 32);
        $firstTransition = $this->store->blockWithPunishmentLifecycleTracking('lifecycle-fence', null, 1, $idA, 2, 60, 21600, 2, 600, 86400);
        self::assertTrue($firstTransition->applied);

        $second = $this->store->mutateGenerationBoundScore('lifecycle-fence', null, $this->store->readGenerationBoundScoreState('lifecycle-fence', null), 600, 10);
        self::assertTrue($second->applied);
        self::assertSame(2, $second->state?->generation);
        self::assertNull($this->store->readGenerationBoundScoreState('lifecycle-fence', null)?->postPunishmentReentry);
        self::assertFalse($this->store->claimPostPunishmentReentry('lifecycle-fence', null, $idA));

        $idB = str_repeat('b', 32);
        $secondTransition = $this->store->blockWithPunishmentLifecycleTracking('lifecycle-fence', null, 2, $idB, 2, 60, 21600, 2, 600, 86400);
        self::assertTrue($secondTransition->applied);
        self::assertSame($idB, $secondTransition->postPunishmentReentry?->id);
        $this->executor->execute(['DEL', $this->key('block', 'lifecycle-fence')]);
        self::assertFalse($this->store->claimPostPunishmentReentry('lifecycle-fence', null, $idA));
        self::assertTrue($this->store->claimPostPunishmentReentry('lifecycle-fence', null, $idB));
        self::assertFalse($this->store->claimPostPunishmentReentry('lifecycle-fence', null, $idB));
    }

    /**
     * Republishing the same exact K4 generation preserves the existing
     * lifecycle identity instead of adopting a newly proposed one, per the
     * DEC-007 stable-identity contract: the same lifecycle for the same
     * generation must keep surfacing the same opaque ID.
     */
    public function testRepublicationOfTheSameGenerationPreservesTheExistingLifecycleIdentity(): void
    {
        $mutation = $this->store->mutateGenerationBoundScore('stable-identity', null, null, 600, 8);
        self::assertTrue($mutation->applied);
        self::assertSame(1, $mutation->state?->generation);

        $idA = str_repeat('a', 32);
        $first = $this->store->blockWithPunishmentLifecycleTracking('stable-identity', null, 1, $idA, 2, 30, 21600, 3, 600, 86400);
        self::assertTrue($first->applied);
        self::assertSame($idA, $first->postPunishmentReentry?->id);

        $idB = str_repeat('b', 32);
        $refresh = $this->store->blockWithPunishmentLifecycleTracking('stable-identity', null, 1, $idB, 2, 30, 21600, 3, 600, 86400);
        self::assertTrue($refresh->applied);
        self::assertSame($idA, $refresh->postPunishmentReentry?->id);
        self::assertNotSame($idB, $refresh->postPunishmentReentry->id);

        $this->raw(['DEL', $this->key('block', 'stable-identity')]);
        $state = $this->store->readGenerationBoundScoreState('stable-identity', null);
        self::assertSame($idA, $state?->postPunishmentReentry?->id);
    }

    public function testLegacyGenerationlessMutationConflictsWhenWriterAddsGeneration(): void
    {
        $key = $this->key('score', 'legacy-cas');
        $this->executor->execute(['HSET', $key, 'value', '4', 'updatedAt', (string) time()]);
        $this->executor->execute(['EXPIRE', $key, '600']);
        $legacy = $this->store->readGenerationBoundScoreState('legacy-cas', null);
        self::assertNotNull($legacy);
        self::assertNull($legacy->generation);

        $this->executor->execute(['HSET', $key, 'generation', '1', 'expiresAt', (string) (time() + 601)]);
        $stale = $this->store->mutateGenerationBoundScore('legacy-cas', null, $legacy, 600, 9);
        self::assertFalse($stale->applied);
    }

    public function testClaimUsesCurrentAsAuthoritativeOverPrevious(): void
    {
        $previousKey = $this->key('score', 'claim-previous');
        $currentKey = $this->key('score', 'claim-current');
        $now = time();
        $this->executor->execute(['HSET', $previousKey, 'value', '8', 'updatedAt', (string) $now, 'generation', '1', 'expiresAt', (string) ($now + 601), 'reentryId', str_repeat('a', 32), 'reentryValidUntil', (string) ($now + 601), 'reentryGeneration', '1']);
        $this->executor->execute(['EXPIRE', $previousKey, '600']);
        $this->executor->execute(['HSET', $currentKey, 'value', '9', 'updatedAt', (string) $now, 'generation', '2', 'expiresAt', (string) ($now + 601)]);
        $this->executor->execute(['EXPIRE', $currentKey, '600']);

        self::assertFalse($this->store->claimPostPunishmentReentry('claim-current', 'claim-previous', str_repeat('a', 32)));
    }

    public function testMalformedLifecycleClaimStateRaisesExplicitFailure(): void
    {
        $now = $this->redisNow();
        $partial = $this->key('score', 'claim-malformed-partial');
        $this->raw(['HSET', $partial, 'value', '8', 'updatedAt', (string) $now, 'expiresAt', (string) ($now + 601), 'generation', '1', 'reentryId', str_repeat('a', 32)]);
        $this->raw(['EXPIRE', $partial, '600']);
        $this->assertOperationFails(fn(): mixed => $this->store->claimPostPunishmentReentry('claim-malformed-partial', null, str_repeat('a', 32)));

        $invalidId = $this->key('score', 'claim-malformed-id');
        $this->raw(['HSET', $invalidId, 'value', '8', 'updatedAt', (string) $now, 'expiresAt', (string) ($now + 601), 'generation', '1', 'reentryId', str_repeat('z', 32), 'reentryValidUntil', (string) ($now + 601), 'reentryGeneration', '1']);
        $this->raw(['EXPIRE', $invalidId, '600']);
        $this->assertOperationFails(fn(): mixed => $this->store->claimPostPunishmentReentry('claim-malformed-id', null, str_repeat('z', 32)));

        $malformedBlockScore = $this->key('score', 'claim-malformed-block');
        $malformedBlock = $this->key('block', 'claim-malformed-block');
        $this->raw(['HSET', $malformedBlockScore, 'value', '8', 'updatedAt', (string) $now, 'expiresAt', (string) ($now + 601), 'generation', '1', 'reentryId', str_repeat('a', 32), 'reentryValidUntil', (string) ($now + 601), 'reentryGeneration', '1']);
        $this->raw(['EXPIRE', $malformedBlockScore, '600']);
        $this->raw(['HSET', $malformedBlock, 'level', '2']);
        $this->raw(['EXPIRE', $malformedBlock, '600']);
        $this->assertOperationFails(fn(): mixed => $this->store->claimPostPunishmentReentry('claim-malformed-block', null, str_repeat('a', 32)));
    }

    public function testMalformedHardBlockFailsPublicationBeforeChangingAnyLifecycleState(): void
    {
        $now = $this->redisNow();
        $score = $this->key('score', 'publication-malformed-block');
        $block = $this->key('block', 'publication-malformed-block');
        $cycle = $this->key('cycle', 'publication-malformed-block');
        $pause = $this->key('pause', 'publication-malformed-block');
        $this->raw(['HSET', $score, 'value', '8', 'updatedAt', (string) $now, 'generation', '1', 'expiresAt', (string) ($now + 600)]);
        $this->raw(['EXPIRE', $score, '600']);
        $this->raw(['ZADD', $cycle, $now, (string) $now]);
        $this->raw(['EXPIRE', $cycle, '600']);
        $this->raw(['ZADD', $pause, $now, $now . ':' . ($now + 30)]);
        $this->raw(['EXPIRE', $pause, '86400']);
        $this->raw(['HSET', $block, 'level', '2']);
        $this->raw(['EXPIRE', $block, '600']);
        $before = [$this->hashMap($score), $this->hashMap($block), $this->raw(['ZRANGE', $cycle, 0, -1, 'WITHSCORES']), $this->raw(['ZRANGE', $pause, 0, -1, 'WITHSCORES'])];

        $this->assertOperationFails(fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking('publication-malformed-block', null, 1, str_repeat('a', 32), 2, 60, 600, 3, 600, 86400));

        self::assertSame($before, [$this->hashMap($score), $this->hashMap($block), $this->raw(['ZRANGE', $cycle, 0, -1, 'WITHSCORES']), $this->raw(['ZRANGE', $pause, 0, -1, 'WITHSCORES'])]);
        self::assertGreaterThan(0, $this->integer($this->raw(['PTTL', $score])));
    }

    public function testMalformedPauseFailsPublicationBeforeChangingAnyLifecycleState(): void
    {
        $now = $this->redisNow();
        $score = $this->key('score', 'publication-malformed-pause');
        $cycle = $this->key('cycle', 'publication-malformed-pause');
        $pause = $this->key('pause', 'publication-malformed-pause');
        $this->raw(['HSET', $score, 'value', '8', 'updatedAt', (string) $now, 'generation', '1', 'expiresAt', (string) ($now + 600)]);
        $this->raw(['EXPIRE', $score, '600']);
        $this->raw(['ZADD', $cycle, $now, (string) $now]);
        $this->raw(['EXPIRE', $cycle, '600']);
        $this->raw(['ZADD', $pause, $now, 'malformed']);
        $this->raw(['EXPIRE', $pause, '86400']);
        $before = [$this->hashMap($score), $this->raw(['ZRANGE', $cycle, 0, -1, 'WITHSCORES']), $this->raw(['ZRANGE', $pause, 0, -1, 'WITHSCORES'])];

        $this->assertOperationFails(fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking('publication-malformed-pause', null, 1, str_repeat('b', 32), 2, 60, 600, 3, 600, 86400));

        self::assertSame($before, [$this->hashMap($score), $this->raw(['ZRANGE', $cycle, 0, -1, 'WITHSCORES']), $this->raw(['ZRANGE', $pause, 0, -1, 'WITHSCORES'])]);
        self::assertGreaterThan(0, $this->integer($this->raw(['PTTL', $score])));
    }

    public function testPartialLifecycleEvidenceFailsReadExplicitly(): void
    {
        $now = $this->redisNow();
        $key = $this->key('score', 'read-malformed-partial');
        $this->raw(['HSET', $key, 'value', '8', 'updatedAt', (string) $now, 'generation', '1', 'expiresAt', (string) ($now + 601), 'reentryId', str_repeat('a', 32)]);
        $this->raw(['EXPIRE', $key, '600']);

        $this->assertOperationFails(fn(): mixed => $this->store->readGenerationBoundScoreState('read-malformed-partial', null));
    }

    public function testPartialLifecycleEvidenceFailsMutationWithoutChangingPhysicalState(): void
    {
        $now = $this->redisNow();
        $key = $this->key('score', 'mutation-malformed-partial');
        $this->raw(['HSET', $key, 'value', '8', 'updatedAt', (string) $now, 'generation', '1', 'expiresAt', (string) ($now + 601), 'reentryId', str_repeat('b', 32)]);
        $this->raw(['EXPIRE', $key, '600']);
        $before = $this->hashMap($key);
        $expected = new GenerationBoundScoreStateDTO(
            GenerationBoundScoreStateDTO::SOURCE_CURRENT,
            8,
            $now,
            $now + 601,
            1,
        );

        $this->assertOperationFails(fn(): mixed => $this->store->mutateGenerationBoundScore('mutation-malformed-partial', null, $expected, 600, 9));

        self::assertSame($before, $this->hashMap($key));
        self::assertGreaterThan(0, $this->integer($this->raw(['TTL', $key])));
    }

    public function testCompleteStaleLifecycleEvidenceIsNonSatisfyingButNotCorrupt(): void
    {
        $now = $this->redisNow();
        $key = $this->key('score', 'complete-stale-evidence');
        $id = str_repeat('c', 32);
        $this->raw(['HSET', $key, 'value', '8', 'updatedAt', (string) $now, 'generation', '2', 'expiresAt', (string) ($now + 601), 'reentryId', $id, 'reentryValidUntil', (string) ($now + 601), 'reentryGeneration', '1']);
        $this->raw(['EXPIRE', $key, '600']);

        $state = $this->store->readGenerationBoundScoreState('complete-stale-evidence', null);
        self::assertNotNull($state);
        self::assertNull($state->postPunishmentReentry);
        self::assertFalse($this->store->claimPostPunishmentReentry('complete-stale-evidence', null, $id));
    }

    public function testAbsentLifecycleEvidenceRemainsValidState(): void
    {
        $now = $this->redisNow();
        $key = $this->key('score', 'absent-lifecycle-evidence');
        $this->raw(['HSET', $key, 'value', '8', 'updatedAt', (string) $now, 'generation', '1', 'expiresAt', (string) ($now + 601)]);
        $this->raw(['EXPIRE', $key, '600']);

        $state = $this->store->readGenerationBoundScoreState('absent-lifecycle-evidence', null);
        self::assertNotNull($state);
        self::assertNull($state->postPunishmentReentry);
        $mutation = $this->store->mutateGenerationBoundScore('absent-lifecycle-evidence', null, $state, 600, 9);
        self::assertTrue($mutation->applied);
        self::assertNull($mutation->state?->postPunishmentReentry);
    }

    public function testGeneratedStateWithoutExpiresAtFailsReadClaimAndMutationWhileLegacyStateRemainsSupported(): void
    {
        $now = $this->redisNow();
        $generatedRead = $this->key('score', 'generated-missing-expiry-read');
        $this->raw(['HSET', $generatedRead, 'value', '8', 'updatedAt', (string) $now, 'generation', '1']);
        $this->raw(['EXPIRE', $generatedRead, '600']);
        $this->assertOperationFails(fn(): mixed => $this->store->readGenerationBoundScoreState('generated-missing-expiry-read', null));

        $generatedClaim = $this->key('score', 'generated-missing-expiry-claim');
        $this->raw(['HSET', $generatedClaim, 'value', '8', 'updatedAt', (string) $now, 'generation', '1', 'reentryId', str_repeat('a', 32), 'reentryValidUntil', (string) ($now + 601), 'reentryGeneration', '1']);
        $this->raw(['EXPIRE', $generatedClaim, '600']);
        $this->assertOperationFails(fn(): mixed => $this->store->claimPostPunishmentReentry('generated-missing-expiry-claim', null, str_repeat('a', 32)));

        $generatedMutation = $this->key('score', 'generated-missing-expiry-mutation');
        $this->raw(['HSET', $generatedMutation, 'value', '8', 'updatedAt', (string) $now, 'generation', '1']);
        $this->raw(['EXPIRE', $generatedMutation, '600']);
        $expected = new GenerationBoundScoreStateDTO(
            GenerationBoundScoreStateDTO::SOURCE_CURRENT,
            8,
            $now,
            $now + 601,
            1,
        );
        $this->assertOperationFails(fn(): mixed => $this->store->mutateGenerationBoundScore('generated-missing-expiry-mutation', null, $expected, 600, 9));
        $this->assertOperationFails(fn(): mixed => $this->store->mutateGenerationBoundScore('generated-missing-expiry-mutation', null, null, 600, 9));

        $legacy = $this->key('score', 'legacy-missing-expiry-compatible');
        $this->raw(['HSET', $legacy, 'value', '4', 'updatedAt', (string) $now]);
        $this->raw(['EXPIRE', $legacy, '600']);
        $legacyState = $this->store->readGenerationBoundScoreState('legacy-missing-expiry-compatible', null);
        self::assertNotNull($legacyState);
        self::assertNull($legacyState->generation);
    }

    public function testConcurrentRedisClaimsHaveExactlyOneWinner(): void
    {
        $now = $this->redisNow();
        $id = str_repeat('c', 32);
        $score = $this->key('score', 'claim-concurrent');
        $this->raw(['HSET', $score, 'value', '8', 'updatedAt', (string) $now, 'expiresAt', (string) ($now + 601), 'generation', '1', 'reentryId', $id, 'reentryValidUntil', (string) ($now + 601), 'reentryGeneration', '1']);
        $this->raw(['EXPIRE', $score, '600']);

        $workers = 8;
        $this->runConcurrentWorkers($workers, function (int $index) use ($id): void {
            $claimed = $this->workerStore()->claimPostPunishmentReentry('claim-concurrent', null, $id);
            $host = getenv('REDIS_INTEGRATION_HOST');
            $port = getenv('REDIS_INTEGRATION_PORT');
            if ($host === false || $port === false) {
                exit(1);
            }
            (new RespRedisCommandExecutor($host, (int) $port))->execute([
                'SET',
                $this->key('claim-result', 'concurrent-' . $index),
                $claimed ? '1' : '0',
                'EX',
                '60',
            ]);
        });

        $winners = 0;
        for ($index = 0; $index < $workers; $index++) {
            $winners += $this->integer($this->raw(['GET', $this->key('claim-result', 'concurrent-' . $index)]));
        }
        self::assertSame(1, $winners);
    }

    public function testLifecyclePublicationRejectsPhysicallyInconsistentScoreWithoutPartialWrites(): void
    {
        $now = $this->redisNow();
        $score = $this->key('score', 'publication-inconsistent');
        $this->raw(['HSET', $score, 'value', '8', 'updatedAt', (string) $now, 'generation', '1', 'expiresAt', (string) ($now + 1)]);
        $this->raw(['EXPIRE', $score, '600']);

        $this->assertOperationFails(fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking('publication-inconsistent', null, 1, str_repeat('a', 32), 2, 60, 21600, 2, 600, 86400));
        self::assertSame(-2, $this->integer($this->raw(['PTTL', $this->key('block', 'publication-inconsistent')])));
        self::assertSame([], $this->raw(['ZRANGE', $this->key('cycle', 'publication-inconsistent'), '0', '-1']));
    }

    public function testGeneratedPhysicalExpiryCorruptionFailsReadMutateClaimAndPublication(): void
    {
        $now = $this->redisNow();
        foreach (['read', 'mutate', 'claim', 'publication'] as $operation) {
            $logical = 'physical-corruption-' . $operation;
            $score = $this->key('score', $logical);
            $fields = ['value', '8', 'updatedAt', (string) $now, 'generation', '1', 'expiresAt', (string) ($now + 1)];
            if ($operation !== 'read' && $operation !== 'mutate') {
                $fields = array_merge($fields, ['reentryId', str_repeat('a', 32), 'reentryValidUntil', (string) ($now + 1), 'reentryGeneration', '1']);
            }
            $this->raw(array_merge(['HSET', $score], $fields));
            $this->raw(['EXPIRE', $score, '600']);
            $this->assertOperationFails(match ($operation) {
                'read' => fn(): mixed => $this->store->readGenerationBoundScoreState($logical, null),
                'mutate' => fn(): mixed => $this->store->mutateGenerationBoundScore($logical, null, new GenerationBoundScoreStateDTO(GenerationBoundScoreStateDTO::SOURCE_CURRENT, 8, $now, $now + 1, 1), 600, 9),
                'claim' => fn(): mixed => $this->store->claimPostPunishmentReentry($logical, null, str_repeat('a', 32)),
                default => fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking($logical, null, 1, str_repeat('a', 32), 2, 60, 600, 2, 600, 86400),
            });
        }
    }

    public function testPersistentHardBlockReadAndPublicationPreconditionsFailExplicitly(): void
    {
        $now = $this->redisNow();
        $score = $this->key('score', 'persistent-block-read');
        $block = $this->key('block', 'persistent-block-read');
        $this->raw(['HSET', $score, 'value', '8', 'updatedAt', (string) $now, 'generation', '1', 'expiresAt', (string) ($now + 600)]);
        $this->raw(['EXPIRE', $score, '600']);
        $this->raw(['HSET', $block, 'level', '2', 'expiresAt', (string) ($now + 60)]);
        $this->assertOperationFails(fn(): mixed => $this->store->readGenerationBoundScoreState('persistent-block-read', null));

        $mutation = $this->store->mutateGenerationBoundScore('publication-previous-only', null, null, 600, 8);
        self::assertTrue($mutation->applied);
        $this->assertOperationFails(fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking('publication-previous-only', null, 0, str_repeat('a', 32), 2, 60, 600, 2, 600, 86400));
        $this->assertOperationFails(fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking('publication-previous-only', null, 1, str_repeat('a', 32), 1, 60, 600, 2, 600, 86400));
        $this->assertOperationFails(fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking('publication-previous-only', null, 1, str_repeat('a', 32), 2, 0, 600, 2, 600, 86400));

        $previous = $this->store->mutateGenerationBoundScore('publication-previous', null, null, 600, 8);
        self::assertTrue($previous->applied);
        $previousOnlyTransition = $this->store->blockWithPunishmentLifecycleTracking('publication-new-current', 'publication-previous', 1, str_repeat('b', 32), 2, 60, 600, 2, 600, 86400);
        self::assertFalse($previousOnlyTransition->applied);
        self::assertNull($previousOnlyTransition->cycle);
        self::assertNull($previousOnlyTransition->block);
        self::assertNull($previousOnlyTransition->postPunishmentReentry);
    }

    /**
     * R1 — malformed stored generation matrix. Zero, negative, and
     * non-integer stored generation on an otherwise current-generated-looking
     * physical score fail READ, MUTATE, CLAIM, and PUBLICATION explicitly,
     * even when PUBLICATION is called with a structurally valid positive
     * expectedGeneration.
     */
    public function testMalformedStoredGenerationFailsAcrossReadMutateClaimAndPublication(): void
    {
        $now = $this->redisNow();
        foreach (['0', '-1', 'not-a-number'] as $index => $malformedGeneration) {
            $logical = 'r1-malformed-generation-' . $index;
            $score = $this->key('score', $logical);
            $this->raw(['HSET', $score, 'value', '8', 'updatedAt', (string) $now, 'generation', $malformedGeneration, 'expiresAt', (string) ($now + 600)]);
            $this->raw(['EXPIRE', $score, '600']);

            $this->assertOperationFails(fn(): mixed => $this->store->readGenerationBoundScoreState($logical, null));
            $this->assertOperationFails(fn(): mixed => $this->store->mutateGenerationBoundScore($logical, null, null, 600, 9));
            $this->assertOperationFails(fn(): mixed => $this->store->claimPostPunishmentReentry($logical, null, str_repeat('a', 32)));
            $this->assertOperationFails(fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking($logical, null, 3, str_repeat('a', 32), 2, 60, 600, 2, 600, 86400));
        }
    }

    /**
     * R2 — control case distinguishing corruption from ordinary concurrency.
     * A structurally valid stored generation that simply does not match the
     * expected generation is an unapplied conflict, not an exception.
     */
    public function testValidGenerationMismatchDuringPublicationIsOrdinaryConflictNotCorruption(): void
    {
        $now = $this->redisNow();
        $logical = 'r2-valid-generation-mismatch';
        $score = $this->key('score', $logical);
        $this->raw(['HSET', $score, 'value', '8', 'updatedAt', (string) $now, 'generation', '3', 'expiresAt', (string) ($now + 601)]);
        $this->raw(['EXPIRE', $score, '600']);
        $before = $this->hashMap($score);

        $transition = $this->store->blockWithPunishmentLifecycleTracking($logical, null, 4, str_repeat('a', 32), 2, 60, 600, 2, 600, 86400);

        self::assertFalse($transition->applied);
        self::assertSame($before, $this->hashMap($score));
        self::assertSame(-2, $this->integer($this->raw(['PTTL', $this->key('block', $logical)])));
    }

    /**
     * R3 — a legacy generation-less score can never structurally carry
     * complete DEC-007 lifecycle evidence; the combination is impossible
     * persisted state, not hidden evidence, a false claim, or an ordinary
     * mismatch, across every lifecycle operation.
     */
    public function testLegacyScoreWithCompleteLifecycleEvidenceFailsAcrossReadMutateClaimAndPublication(): void
    {
        $now = $this->redisNow();
        $id = str_repeat('a', 32);
        foreach (['read', 'mutate', 'claim', 'publication'] as $operation) {
            $logical = 'r3-legacy-complete-evidence-' . $operation;
            $score = $this->key('score', $logical);
            $this->raw(['HSET', $score, 'value', '8', 'updatedAt', (string) $now, 'reentryId', $id, 'reentryValidUntil', (string) ($now + 600), 'reentryGeneration', '1']);
            $this->raw(['EXPIRE', $score, '600']);

            $this->assertOperationFails(match ($operation) {
                'read' => fn(): mixed => $this->store->readGenerationBoundScoreState($logical, null),
                'mutate' => fn(): mixed => $this->store->mutateGenerationBoundScore($logical, null, null, 600, 9),
                'claim' => fn(): mixed => $this->store->claimPostPunishmentReentry($logical, null, $id),
                default => fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking($logical, null, 1, $id, 2, 60, 600, 2, 600, 86400),
            });
        }
    }

    /**
     * R5 — publication with an absent Current classifies Previous by its own
     * structural validity, not by its mere presence. A persistent (PTTL ==
     * -1) or finite structurally malformed Previous is an explicit failure;
     * a structurally valid Previous (generated or legacy) is Previous's
     * historical/read-only nature making it a non-publishable source, so it
     * is an ordinary unapplied conflict, never an exception. Neither case
     * writes Current or mutates Previous.
     */
    public function testCurrentAbsentPublicationClassifiesPreviousByStructuralValidityNotPresence(): void
    {
        $now = $this->redisNow();

        // D1: structurally valid generated Previous -> ordinary conflict, not a failure.
        $validGeneratedPrevious = $this->key('score', 'r5-valid-generated-previous');
        $this->raw(['HSET', $validGeneratedPrevious, 'value', '8', 'updatedAt', (string) $now, 'generation', '1', 'expiresAt', (string) ($now + 601)]);
        $this->raw(['EXPIRE', $validGeneratedPrevious, '600']);
        $validGeneratedBefore = $this->hashMap($validGeneratedPrevious);
        $validGeneratedTransition = $this->store->blockWithPunishmentLifecycleTracking('r5-valid-generated-current', 'r5-valid-generated-previous', 1, str_repeat('a', 32), 2, 60, 600, 2, 600, 86400);
        self::assertFalse($validGeneratedTransition->applied);
        self::assertNull($validGeneratedTransition->cycle);
        self::assertNull($validGeneratedTransition->block);
        self::assertNull($validGeneratedTransition->postPunishmentReentry);
        self::assertSame($validGeneratedBefore, $this->hashMap($validGeneratedPrevious));
        self::assertSame(-2, $this->integer($this->raw(['PTTL', $this->key('score', 'r5-valid-generated-current')])));
        self::assertSame(-2, $this->integer($this->raw(['PTTL', $this->key('block', 'r5-valid-generated-current')])));

        // D2: structurally valid legacy (generation-less) Previous -> ordinary conflict, not a failure.
        $validLegacyPrevious = $this->key('score', 'r5-valid-legacy-previous');
        $this->raw(['HSET', $validLegacyPrevious, 'value', '4', 'updatedAt', (string) $now]);
        $this->raw(['EXPIRE', $validLegacyPrevious, '500']);
        $validLegacyBefore = $this->hashMap($validLegacyPrevious);
        $validLegacyTransition = $this->store->blockWithPunishmentLifecycleTracking('r5-valid-legacy-current', 'r5-valid-legacy-previous', 1, str_repeat('b', 32), 2, 60, 600, 2, 600, 86400);
        self::assertFalse($validLegacyTransition->applied);
        self::assertNull($validLegacyTransition->cycle);
        self::assertNull($validLegacyTransition->block);
        self::assertNull($validLegacyTransition->postPunishmentReentry);
        self::assertSame($validLegacyBefore, $this->hashMap($validLegacyPrevious));
        self::assertSame(-2, $this->integer($this->raw(['PTTL', $this->key('score', 'r5-valid-legacy-current')])));

        // E1: Previous persisted without a physical deadline -> explicit failure.
        $persistentPrevious = $this->key('score', 'r5-persistent-previous');
        $this->raw(['HSET', $persistentPrevious, 'value', '8', 'updatedAt', (string) $now, 'generation', '1', 'expiresAt', (string) ($now + 600)]);
        $persistentBefore = $this->hashMap($persistentPrevious);
        $this->assertOperationFails(fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking('r5-persistent-current', 'r5-persistent-previous', 1, str_repeat('c', 32), 2, 60, 600, 2, 600, 86400));
        self::assertSame(-1, $this->integer($this->raw(['PTTL', $persistentPrevious])));
        self::assertSame($persistentBefore, $this->hashMap($persistentPrevious));
        self::assertSame(-2, $this->integer($this->raw(['PTTL', $this->key('score', 'r5-persistent-current')])));

        // E2: Previous finite but structurally malformed (generation = 0) -> explicit failure.
        $malformedPrevious = $this->key('score', 'r5-malformed-previous');
        $this->raw(['HSET', $malformedPrevious, 'value', '8', 'updatedAt', (string) $now, 'generation', '0', 'expiresAt', (string) ($now + 600)]);
        $this->raw(['EXPIRE', $malformedPrevious, '600']);
        $malformedBefore = $this->hashMap($malformedPrevious);
        $this->assertOperationFails(fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking('r5-malformed-current', 'r5-malformed-previous', 1, str_repeat('d', 32), 2, 60, 600, 2, 600, 86400));
        self::assertSame($malformedBefore, $this->hashMap($malformedPrevious));
        self::assertSame(-2, $this->integer($this->raw(['PTTL', $this->key('score', 'r5-malformed-current')])));
    }

    /**
     * Current absent, Previous live: the same full structural lifecycle
     * validation the general contract requires (core fields, generation,
     * expiry, and evidence) applies to Previous before it can be classified
     * as an ordinary conflict. Partial lifecycle evidence, a generation-less
     * (legacy) Previous carrying complete evidence, and a generated Previous
     * whose physical deadline outlives its authoritative expiry are all
     * structural corruption, not ordinary conflicts.
     */
    public function testCurrentAbsentPublicationRunsFullStructuralValidationOnPreviousEvidenceAndPhysicalExpiry(): void
    {
        $now = $this->redisNow();

        $partialEvidencePrevious = $this->key('score', 'r5-partial-evidence-previous');
        $this->raw(['HSET', $partialEvidencePrevious, 'value', '8', 'updatedAt', (string) $now, 'generation', '3', 'expiresAt', (string) ($now + 601), 'reentryId', str_repeat('a', 32)]);
        $this->raw(['EXPIRE', $partialEvidencePrevious, '600']);
        $partialEvidenceBefore = $this->hashMap($partialEvidencePrevious);
        $this->assertOperationFails(fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking('r5-partial-evidence-current', 'r5-partial-evidence-previous', 1, str_repeat('e', 32), 2, 60, 600, 2, 600, 86400));
        self::assertSame($partialEvidenceBefore, $this->hashMap($partialEvidencePrevious));
        self::assertSame(-2, $this->integer($this->raw(['PTTL', $this->key('score', 'r5-partial-evidence-current')])));

        $legacyEvidencePrevious = $this->key('score', 'r5-legacy-evidence-previous');
        $this->raw(['HSET', $legacyEvidencePrevious, 'value', '8', 'updatedAt', (string) $now, 'reentryId', str_repeat('b', 32), 'reentryValidUntil', (string) ($now + 601), 'reentryGeneration', '1']);
        $this->raw(['EXPIRE', $legacyEvidencePrevious, '600']);
        $legacyEvidenceBefore = $this->hashMap($legacyEvidencePrevious);
        $this->assertOperationFails(fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking('r5-legacy-evidence-current', 'r5-legacy-evidence-previous', 1, str_repeat('f', 32), 2, 60, 600, 2, 600, 86400));
        self::assertSame($legacyEvidenceBefore, $this->hashMap($legacyEvidencePrevious));
        self::assertSame(-2, $this->integer($this->raw(['PTTL', $this->key('score', 'r5-legacy-evidence-current')])));

        $physicallyInconsistentPrevious = $this->key('score', 'r5-physical-inconsistent-previous');
        $this->raw(['HSET', $physicallyInconsistentPrevious, 'value', '8', 'updatedAt', (string) $now, 'generation', '1', 'expiresAt', (string) ($now + 10)]);
        $this->raw(['EXPIRE', $physicallyInconsistentPrevious, '600']);
        $physicallyInconsistentBefore = $this->hashMap($physicallyInconsistentPrevious);
        $this->assertOperationFails(fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking('r5-physical-inconsistent-current', 'r5-physical-inconsistent-previous', 1, str_repeat('c', 32), 2, 60, 600, 2, 600, 86400));
        self::assertSame($physicallyInconsistentBefore, $this->hashMap($physicallyInconsistentPrevious));
        self::assertSame(-2, $this->integer($this->raw(['PTTL', $this->key('score', 'r5-physical-inconsistent-current')])));
    }

    /**
     * Current absent with no Previous key at all (no live source whatsoever)
     * is an ordinary unapplied conflict, not a failure.
     */
    public function testPublicationWithNoLiveSourceAtAllIsOrdinaryConflict(): void
    {
        $transition = $this->store->blockWithPunishmentLifecycleTracking('r5-no-source-current', null, 1, str_repeat('e', 32), 2, 60, 600, 2, 600, 86400);

        self::assertFalse($transition->applied);
        self::assertNull($transition->cycle);
        self::assertNull($transition->block);
        self::assertNull($transition->postPunishmentReentry);
        self::assertSame(-2, $this->integer($this->raw(['PTTL', $this->key('score', 'r5-no-source-current')])));
        self::assertSame(-2, $this->integer($this->raw(['PTTL', $this->key('block', 'r5-no-source-current')])));
    }

    /**
     * Full publication parameter precondition matrix: each of the seven
     * parameters is rejected individually with the other six valid.
     */
    public function testPublicationRejectsEachInvalidParameterAcrossTheFullMatrix(): void
    {
        $mutation = $this->store->mutateGenerationBoundScore('publication-parameter-matrix', null, null, 600, 8);
        self::assertTrue($mutation->applied);

        $baseline = [1, 2, 60, 600, 2, 600, 86400];
        $invalidValues = [0, 1, 0, 0, 0, 0, 0];
        foreach (array_keys($baseline) as $index) {
            $params = $baseline;
            $params[$index] = $invalidValues[$index];
            [$generation, $level, $duration, $window, $threshold, $pause, $retention] = $params;
            $this->assertOperationFails(
                fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking('publication-parameter-matrix', null, $generation, str_repeat('a', 32), $level, $duration, $window, $threshold, $pause, $retention),
            );
        }
    }

    public function testClaimMarkerCorruptionIsExplicitAndForeignMarkerDoesNotSilentlySuppress(): void
    {
        $id = str_repeat('b', 32);
        $mutation = $this->store->mutateGenerationBoundScore('marker-corruption', null, null, 600, 8);
        self::assertTrue($mutation->applied);
        $transition = $this->store->blockWithPunishmentLifecycleTracking('marker-corruption', null, 1, $id, 2, 1, 21600, 2, 600, 86400);
        self::assertTrue($transition->applied);
        $this->raw(['DEL', $this->key('block', 'marker-corruption')]);
        $marker = $this->key('reentry-claim', 'marker-corruption');
        $this->raw(['SET', $marker, $id]);
        $this->assertOperationFails(fn(): mixed => $this->store->claimPostPunishmentReentry('marker-corruption', null, $id));

        $foreignId = str_repeat('c', 32);
        $mutation = $this->store->mutateGenerationBoundScore('marker-foreign', null, null, 600, 8);
        self::assertTrue($mutation->applied);
        $transition = $this->store->blockWithPunishmentLifecycleTracking('marker-foreign', null, 1, $id, 2, 1, 21600, 2, 600, 86400);
        self::assertTrue($transition->applied);
        $this->raw(['DEL', $this->key('block', 'marker-foreign')]);
        $this->raw(['SET', $this->key('reentry-claim', 'marker-foreign'), $foreignId, 'PX', '60000']);
        $this->assertOperationFails(fn(): mixed => $this->store->claimPostPunishmentReentry('marker-foreign', null, $id));
    }

    public function testPreviousClaimIsReadOnlyAndWritesOnlyCurrentMarker(): void
    {
        $now = $this->redisNow();
        $id = str_repeat('d', 32);
        $previous = $this->key('score', 'claim-previous-only');
        $this->raw(['HSET', $previous, 'value', '8', 'updatedAt', (string) $now, 'expiresAt', (string) ($now + 601), 'generation', '1', 'reentryId', $id, 'reentryValidUntil', (string) ($now + 601), 'reentryGeneration', '1']);
        $this->raw(['EXPIRE', $previous, '600']);
        $before = $this->hashMap($previous);

        self::assertTrue($this->store->claimPostPunishmentReentry('claim-previous-only-current', 'claim-previous-only', $id));

        self::assertSame($before, $this->hashMap($previous));
        self::assertSame($id, $this->raw(['GET', $this->key('reentry-claim', 'claim-previous-only-current')]));
        $markerTtl = $this->integer($this->raw(['TTL', $this->key('reentry-claim', 'claim-previous-only-current')]));
        self::assertGreaterThan(0, $markerTtl);
        self::assertLessThanOrEqual(600, $markerTtl);
        self::assertFalse($this->store->claimPostPunishmentReentry('claim-previous-only-current', 'claim-previous-only', $id));
    }

    public function testLifecycleClaimDoesNotMutateScoreBlockCycleBudgetOrGenerationEvidence(): void
    {
        $mutation = $this->store->mutateGenerationBoundScore('claim-nonmutation', null, null, 600, 8);
        self::assertTrue($mutation->applied);
        $id = str_repeat('e', 32);
        $transition = $this->store->blockWithPunishmentLifecycleTracking('claim-nonmutation', null, 1, $id, 2, 1, 21600, 2, 600, 86400);
        self::assertTrue($transition->applied);
        $this->raw(['DEL', $this->key('block', 'claim-nonmutation')]);
        $this->store->incrementBudget('claim-nonmutation-budget', 600, 3);

        $scoreBefore = $this->hashMap($this->key('score', 'claim-nonmutation'));
        $cycleBefore = $this->raw(['ZRANGE', $this->key('cycle', 'claim-nonmutation'), '0', '-1', 'WITHSCORES']);
        $budgetBefore = $this->hashMap($this->key('budget', 'claim-nonmutation-budget'));
        self::assertTrue($this->store->claimPostPunishmentReentry('claim-nonmutation', null, $id));
        self::assertSame($scoreBefore, $this->hashMap($this->key('score', 'claim-nonmutation')));
        self::assertSame($cycleBefore, $this->raw(['ZRANGE', $this->key('cycle', 'claim-nonmutation'), '0', '-1', 'WITHSCORES']));
        self::assertSame($budgetBefore, $this->hashMap($this->key('budget', 'claim-nonmutation-budget')));
        self::assertSame($id, $this->raw(['GET', $this->key('reentry-claim', 'claim-nonmutation')]));
    }

    public function testLegacyHandoffAndSameValueMutationAdvanceGenerationExactlyOnce(): void
    {
        $now = $this->redisNow();
        $legacyCurrent = $this->key('score', 'legacy-current-boundary');
        $this->raw(['HSET', $legacyCurrent, 'value', '4', 'updatedAt', (string) $now]);
        $this->raw(['EXPIRE', $legacyCurrent, '600']);
        $legacyTtlBefore = $this->integer($this->raw(['TTL', $legacyCurrent]));
        $legacyState = $this->store->readGenerationBoundScoreState('legacy-current-boundary', null);
        self::assertNotNull($legacyState);
        self::assertNull($legacyState->generation);
        $sameValue = $this->store->mutateGenerationBoundScore('legacy-current-boundary', null, $legacyState, 600, 4);
        self::assertTrue($sameValue->applied);
        self::assertSame(1, $sameValue->state?->generation);
        $legacyTtlAfter = $this->integer($this->raw(['TTL', $legacyCurrent]));
        self::assertLessThanOrEqual($legacyTtlAfter, $legacyTtlBefore);
        self::assertGreaterThanOrEqual($legacyTtlBefore - 2, $legacyTtlAfter);

        $previous = $this->key('score', 'legacy-previous-boundary');
        $this->raw(['HSET', $previous, 'value', '6', 'updatedAt', (string) $now]);
        $this->raw(['EXPIRE', $previous, '600']);
        $previousBefore = $this->hashMap($previous);
        $previousState = $this->store->readGenerationBoundScoreState('legacy-handoff-current', 'legacy-previous-boundary');
        self::assertNotNull($previousState);
        self::assertNull($previousState->generation);
        $handoff = $this->store->mutateGenerationBoundScore('legacy-handoff-current', 'legacy-previous-boundary', $previousState, 600, 7);
        self::assertTrue($handoff->applied);
        self::assertSame(1, $handoff->state?->generation);
        self::assertSame($previousBefore, $this->hashMap($previous));
        self::assertFalse($this->store->mutateGenerationBoundScore('legacy-handoff-current', 'legacy-previous-boundary', $previousState, 600, 8)->applied);

        $generationOne = $this->store->mutateGenerationBoundScore('same-second-value', null, null, 600, 8);
        self::assertTrue($generationOne->applied);
        $generationTwo = $this->store->mutateGenerationBoundScore('same-second-value', null, $generationOne->state, 600, 8);
        self::assertTrue($generationTwo->applied);
        self::assertSame(2, $generationTwo->state?->generation);
        self::assertNull($this->store->readGenerationBoundScoreState('same-second-value', null)?->postPunishmentReentry);
    }

    public function testLifecycleMutationPreservesPhysicalAndAuthoritativeExpiryAcrossAllK4Boundaries(): void
    {
        $now = $this->redisNow();

        $legacyPreviousKey = $this->key('score', 'ttl-contract-legacy-previous');
        $this->raw(['HSET', $legacyPreviousKey, 'value', '4', 'updatedAt', (string) $now]);
        $this->raw(['PEXPIRE', $legacyPreviousKey, '1200']);
        $legacyPreviousBefore = $this->hashMap($legacyPreviousKey);
        $legacyPreviousPttl = $this->integer($this->raw(['PTTL', $legacyPreviousKey]));
        $legacyPreviousState = $this->store->readGenerationBoundScoreState('ttl-contract-legacy-current', 'ttl-contract-legacy-previous');
        self::assertNotNull($legacyPreviousState);
        $legacyHandoff = $this->store->mutateGenerationBoundScore('ttl-contract-legacy-current', 'ttl-contract-legacy-previous', $legacyPreviousState, 86400, 5);
        self::assertTrue($legacyHandoff->applied);
        self::assertSame(1, $legacyHandoff->state?->generation);
        self::assertLessThanOrEqual($legacyPreviousPttl + 25, $this->integer($this->raw(['PTTL', $this->key('score', 'ttl-contract-legacy-current')])));
        self::assertSame($legacyPreviousBefore, $this->hashMap($legacyPreviousKey));

        $generatedPrevious = $this->store->mutateGenerationBoundScore('ttl-contract-generated-previous', null, null, 600, 6);
        self::assertTrue($generatedPrevious->applied);
        self::assertNotNull($generatedPrevious->state);
        for ($generation = 2; $generation <= 3; $generation++) {
            $generatedPrevious = $this->store->mutateGenerationBoundScore('ttl-contract-generated-previous', null, $generatedPrevious->state, 86400, 5 + $generation);
            self::assertTrue($generatedPrevious->applied);
            self::assertSame($generation, $generatedPrevious->state?->generation);
        }
        $generatedPreviousKey = $this->key('score', 'ttl-contract-generated-previous');
        $generatedPreviousBefore = $this->hashMap($generatedPreviousKey);
        $generatedExpiry = $generatedPrevious->state->expiresAt;
        $generatedPreviousPttl = $this->integer($this->raw(['PTTL', $generatedPreviousKey]));
        $generatedPreviousState = $this->store->readGenerationBoundScoreState('ttl-contract-generated-current', 'ttl-contract-generated-previous');
        self::assertNotNull($generatedPreviousState);
        $generatedHandoff = $this->store->mutateGenerationBoundScore('ttl-contract-generated-current', 'ttl-contract-generated-previous', $generatedPreviousState, 86400, 7);
        self::assertTrue($generatedHandoff->applied);
        self::assertSame(4, $generatedHandoff->state?->generation);
        self::assertSame($generatedExpiry, $generatedHandoff->state->expiresAt);
        self::assertLessThanOrEqual($generatedPreviousPttl + 25, $this->integer($this->raw(['PTTL', $this->key('score', 'ttl-contract-generated-current')])));
        self::assertSame($generatedPreviousBefore, $this->hashMap($generatedPreviousKey));

        $current = $this->store->mutateGenerationBoundScore('ttl-contract-current', null, null, 600, 8);
        self::assertTrue($current->applied);
        self::assertNotNull($current->state);
        $currentKey = $this->key('score', 'ttl-contract-current');
        $currentBeforePttl = $this->integer($this->raw(['PTTL', $currentKey]));
        $currentExpiry = $current->state->expiresAt;
        $currentMutation = $this->store->mutateGenerationBoundScore('ttl-contract-current', null, $current->state, 86400, 9);
        self::assertTrue($currentMutation->applied);
        self::assertSame(2, $currentMutation->state?->generation);
        self::assertSame($currentExpiry, $currentMutation->state->expiresAt);
        self::assertLessThanOrEqual($currentBeforePttl + 25, $this->integer($this->raw(['PTTL', $currentKey])));

        $subSecond = $this->store->mutateGenerationBoundScore('ttl-contract-sub-second', null, null, 600, 1);
        self::assertTrue($subSecond->applied);
        $subSecondKey = $this->key('score', 'ttl-contract-sub-second');
        $this->raw(['PEXPIRE', $subSecondKey, '900']);
        $subSecondPttl = $this->integer($this->raw(['PTTL', $subSecondKey]));
        self::assertSame(0, intdiv($subSecondPttl, 1000));
        self::assertGreaterThan(0, $subSecondPttl);
        $subSecondState = $this->store->readGenerationBoundScoreState('ttl-contract-sub-second', null);
        self::assertNotNull($subSecondState);
        $subSecondMutation = $this->store->mutateGenerationBoundScore('ttl-contract-sub-second', null, $subSecondState, 86400, 2);
        self::assertTrue($subSecondMutation->applied);
        self::assertSame(2, $subSecondMutation->state?->generation);
        self::assertLessThanOrEqual($subSecondPttl + 25, $this->integer($this->raw(['PTTL', $subSecondKey])));

        $noExpiryKey = $this->key('score', 'ttl-contract-no-expiry');
        $noExpiryNow = $this->redisNow();
        $this->raw(['HSET', $noExpiryKey, 'value', '3', 'updatedAt', (string) $noExpiryNow, 'generation', '3', 'expiresAt', (string) ($noExpiryNow + 600)]);
        $noExpiryHash = $this->hashMap($noExpiryKey);
        self::assertSame(-1, $this->integer($this->raw(['PTTL', $noExpiryKey])));
        $noExpiryState = new GenerationBoundScoreStateDTO(GenerationBoundScoreStateDTO::SOURCE_CURRENT, 3, $noExpiryNow, $noExpiryNow + 600, 3);
        $this->assertOperationFails(fn(): mixed => $this->store->readGenerationBoundScoreState('ttl-contract-no-expiry', null));
        $this->assertOperationFails(fn(): mixed => $this->store->mutateGenerationBoundScore('ttl-contract-no-expiry', null, $noExpiryState, 600, 4));
        $this->assertOperationFails(fn(): mixed => $this->store->claimPostPunishmentReentry('ttl-contract-no-expiry', null, str_repeat('a', 32)));
        self::assertSame($noExpiryHash, $this->hashMap($noExpiryKey));

        $stale = $this->store->mutateGenerationBoundScore('ttl-contract-stale', null, null, 600, 1);
        self::assertTrue($stale->applied);
        $staleKey = $this->key('score', 'ttl-contract-stale');
        $staleState = $stale->state;
        self::assertNotNull($staleState);
        $this->raw(['DEL', $staleKey]);
        $staleMutation = $this->store->mutateGenerationBoundScore('ttl-contract-stale', null, $staleState, 600, 2);
        self::assertFalse($staleMutation->applied);
        self::assertSame(-2, $this->integer($this->raw(['PTTL', $staleKey])));

        $empty = $this->store->mutateGenerationBoundScore('ttl-contract-empty', null, null, 600, 1);
        self::assertTrue($empty->applied);
        self::assertSame(1, $empty->state?->generation);
        self::assertGreaterThan(0, $this->integer($this->raw(['PTTL', $this->key('score', 'ttl-contract-empty')])));
    }

    public function testConcurrentPreviousHandoffHasNoDoubleSeedAndGenerationMutationsHaveNoLostUpdates(): void
    {
        $now = $this->redisNow();
        $previous = $this->key('score', 'concurrent-previous');
        $this->raw(['HSET', $previous, 'value', '5', 'updatedAt', (string) $now]);
        $this->raw(['EXPIRE', $previous, '600']);
        $observedPrevious = $this->store->readGenerationBoundScoreState('concurrent-current', 'concurrent-previous');
        self::assertNotNull($observedPrevious);

        $workers = 6;
        $this->runConcurrentWorkers($workers, function (int $index) use ($observedPrevious): void {
            $mutation = $this->workerStore()->mutateGenerationBoundScore('concurrent-current', 'concurrent-previous', $observedPrevious, 600, 6);
            $host = getenv('REDIS_INTEGRATION_HOST');
            $port = getenv('REDIS_INTEGRATION_PORT');
            if ($host === false || $port === false) {
                exit(1);
            }
            (new RespRedisCommandExecutor($host, (int) $port))->execute([
                'SET',
                $this->key('handoff-result', (string) $index),
                $mutation->applied ? '1' : '0',
                'EX',
                '60',
            ]);
        });
        $handoffWinners = 0;
        for ($index = 0; $index < $workers; $index++) {
            $handoffWinners += $this->integer($this->raw(['GET', $this->key('handoff-result', (string) $index)]));
        }
        self::assertSame(1, $handoffWinners);
        self::assertSame($observedPrevious->value, $this->store->readGenerationBoundScoreState('concurrent-previous', null)?->value);
        $handoffState = $this->store->readGenerationBoundScoreState('concurrent-current', 'concurrent-previous');
        self::assertNotNull($handoffState);
        self::assertSame(6, $handoffState->value);
        self::assertSame(1, $handoffState->generation);

        $this->store->mutateGenerationBoundScore('concurrent-mutations', null, null, 600, 0);
        $this->runConcurrentWorkers($workers, function (): void {
            for ($attempt = 0; $attempt < 8; $attempt++) {
                $store = $this->workerStore();
                $state = $store->readGenerationBoundScoreState('concurrent-mutations', null);
                if ($state !== null && $store->mutateGenerationBoundScore('concurrent-mutations', null, $state, 600, $state->value + 1)->applied) {
                    return;
                }
                usleep(1000);
            }
            exit(1);
        });
        $final = $this->store->readGenerationBoundScoreState('concurrent-mutations', null);
        self::assertNotNull($final);
        self::assertSame($workers, $final->value);
        self::assertSame($workers + 1, $final->generation);
    }

    public function testPublicBuilderWorkflowUsesTheOfficialRedisAggregateStore(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $limiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('redis-key-secret', 'redis-fingerprint-secret', 'prod'),
            $this->store,
            new RecordingFailureSignalEmitter(),
        )->withClock($clock)->build();
        $context = new RateLimitContextDTO(
            '198.51.100.20',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'redis-public-workflow',
            ['device' => 'integration'],
        );

        $login = $limiter->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $otp = $limiter->limit($context, RateLimitCommand::recordFailure('otp_protection'));
        $api = $limiter->limit($context, new RateLimitCommand('api_heavy_protection'));

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $login->decision);
        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $otp->decision);
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $api->decision);
    }

    public function testPublicBuilderWorkflowUsesOfficialRedisRotationShapes(): void
    {
        $shapes = [
            ['new-outer', 'stable-fingerprint', 'old-outer', null],
            ['stable-outer', 'new-fingerprint', null, 'old-fingerprint'],
            ['new-outer-both', 'new-fingerprint-both', 'old-outer-both', 'old-fingerprint-both'],
        ];

        foreach ($shapes as $index => [$outer, $fingerprint, $previousOuter, $previousFingerprint]) {
            $account = 'redis-public-rotation-' . $index;
            $oldLimiter = RateLimiterBuilder::fromFullCapabilityStore(
                new RateLimiterConfig(
                    $previousOuter ?? $outer,
                    $previousFingerprint ?? $fingerprint,
                    'prod',
                ),
                $this->store,
                new RecordingFailureSignalEmitter(),
            )->build();
            for ($device = 1; $device <= 3; $device++) {
                $oldLimiter->limit($this->deviceContext($account, $device), RateLimitCommand::checkOnly('login_protection'));
            }

            $currentLimiter = RateLimiterBuilder::fromFullCapabilityStore(
                new RateLimiterConfig($outer, $fingerprint, 'prod', $previousOuter, $previousFingerprint),
                $this->store,
                new RecordingFailureSignalEmitter(),
            )->build();
            $rotated = $currentLimiter->limit(
                $this->deviceContext($account, 4),
                RateLimitCommand::checkOnly('login_protection'),
            );

            self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $rotated->decision);
            self::assertSame(2, $rotated->blockLevel);
        }
    }

    public function testPublicBuilderRotationWritesCurrentStateAndLeavesPreviousRedisStateReadOnly(): void
    {
        $account = 'redis-public-read-only-rotation';
        $oldLimiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('public-old-outer', 'public-stable-fingerprint', 'prod'),
            $this->store,
            new RecordingFailureSignalEmitter(),
        )->build();
        for ($device = 1; $device <= 3; $device++) {
            $oldLimiter->limit($this->deviceContext($account, $device), RateLimitCommand::checkOnly('login_protection'));
        }

        $previousKeys = $this->redisKeys('distinct');
        self::assertNotEmpty($previousKeys);
        $previousSnapshot = [];
        foreach ($previousKeys as $key) {
            $previousSnapshot[$key] = [
                $this->raw(['SMEMBERS', $key]),
                $this->integer($this->raw(['PTTL', $key])),
                $this->hashMap(str_replace(':distinct:', ':distinct-meta:', $key)),
                $this->integer($this->raw(['PTTL', str_replace(':distinct:', ':distinct-meta:', $key)])),
            ];
        }

        $currentLimiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig(
                'public-new-outer',
                'public-stable-fingerprint',
                'prod',
                'public-old-outer',
                null,
            ),
            $this->store,
            new RecordingFailureSignalEmitter(),
        )->build();
        $rotated = $currentLimiter->limit(
            $this->deviceContext($account, 4),
            RateLimitCommand::checkOnly('login_protection'),
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $rotated->decision);
        $currentKeys = $this->redisKeys('distinct');
        self::assertNotEmpty(array_diff($currentKeys, $previousKeys));
        foreach ($previousSnapshot as $key => [$members, $pttl, $metadata, $metadataPttl]) {
            self::assertSame($members, $this->raw(['SMEMBERS', $key]));
            self::assertLessThanOrEqual($pttl, $this->integer($this->raw(['PTTL', $key])));
            $metadataKey = str_replace(':distinct:', ':distinct-meta:', $key);
            self::assertSame($metadata, $this->hashMap($metadataKey));
            self::assertLessThanOrEqual($metadataPttl, $this->integer($this->raw(['PTTL', $metadataKey])));
        }
    }

    /**
     * The outer-key rotation proof above changes the outer key secret
     * while the fingerprint secret stays stable, which is not a
     * fingerprint-only rotation. This proves the same read-only-previous
     * contract holds through the public runtime when only the fingerprint
     * secret rotates and the outer key secret is unchanged.
     */
    public function testPublicBuilderFingerprintOnlyRotationLeavesPreviousRedisStateReadOnly(): void
    {
        $account = 'redis-public-fingerprint-only-rotation';
        $oldLimiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('public-fingerprint-only-outer', 'public-old-fingerprint', 'prod'),
            $this->store,
            new RecordingFailureSignalEmitter(),
        )->build();
        for ($device = 1; $device <= 3; $device++) {
            $oldLimiter->limit($this->deviceContext($account, $device), RateLimitCommand::checkOnly('login_protection'));
        }

        $previousKeys = $this->redisKeys('distinct');
        self::assertNotEmpty($previousKeys);
        $previousSnapshot = [];
        foreach ($previousKeys as $key) {
            $previousSnapshot[$key] = [
                $this->raw(['SMEMBERS', $key]),
                $this->integer($this->raw(['PTTL', $key])),
                $this->hashMap(str_replace(':distinct:', ':distinct-meta:', $key)),
                $this->integer($this->raw(['PTTL', str_replace(':distinct:', ':distinct-meta:', $key)])),
            ];
        }

        $currentLimiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig(
                'public-fingerprint-only-outer',
                'public-new-fingerprint',
                'prod',
                null,
                'public-old-fingerprint',
            ),
            $this->store,
            new RecordingFailureSignalEmitter(),
        )->build();
        $rotated = $currentLimiter->limit(
            $this->deviceContext($account, 4),
            RateLimitCommand::checkOnly('login_protection'),
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $rotated->decision);
        $currentKeys = $this->redisKeys('distinct');
        self::assertNotEmpty(array_diff($currentKeys, $previousKeys));
        foreach ($previousSnapshot as $key => [$members, $pttl, $metadata, $metadataPttl]) {
            self::assertSame($members, $this->raw(['SMEMBERS', $key]));
            self::assertLessThanOrEqual($pttl, $this->integer($this->raw(['PTTL', $key])));
            $metadataKey = str_replace(':distinct:', ':distinct-meta:', $key);
            self::assertSame($metadata, $this->hashMap($metadataKey));
            self::assertLessThanOrEqual($metadataPttl, $this->integer($this->raw(['PTTL', $metadataKey])));
        }
    }

    public function testPublicBuilderMigratesPreviousBudgetIntoCurrentGeneration(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $context = new RateLimitContextDTO(
            '198.51.100.40',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'redis-public-budget-migration',
            ['device' => 'migration'],
        );
        $oldLimiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('budget-old-outer', 'budget-old-fingerprint', 'prod'),
            $this->store,
            new RecordingFailureSignalEmitter(),
        )->withClock($clock)->build();
        $oldLimiter->limit($context, RateLimitCommand::recordFailure('login_protection'));

        $previousKeys = $this->redisKeys('budget');
        self::assertNotEmpty($previousKeys);
        $previousKey = $previousKeys[0];
        $previousState = $this->hashMap($previousKey);
        self::assertArrayHasKey('count', $previousState);
        self::assertArrayHasKey('epochStart', $previousState);
        $previousPttl = $this->integer($this->raw(['PTTL', $previousKey]));

        $currentLimiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig(
                'budget-new-outer',
                'budget-new-fingerprint',
                'prod',
                'budget-old-outer',
                'budget-old-fingerprint',
            ),
            $this->store,
            new RecordingFailureSignalEmitter(),
        )->withClock($clock)->build();
        $currentLimiter->limit($context, RateLimitCommand::recordFailure('login_protection'));

        $currentKeys = array_values(array_diff($this->redisKeys('budget'), $previousKeys));
        self::assertNotEmpty($currentKeys);
        $currentState = $this->hashMap($currentKeys[0]);
        self::assertSame((int) $previousState['count'] + 1, (int) $currentState['count']);
        self::assertSame($previousState['epochStart'], $currentState['epochStart']);
        self::assertSame($previousState, $this->hashMap($previousKey));
        self::assertLessThanOrEqual($previousPttl, $this->integer($this->raw(['PTTL', $previousKey])));
    }

    public function testPublicBuilderPersistsHardBlockCycleAndPauseThroughLimitWorkflow(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $policy = new class implements BlockPolicyInterface {
            public function getName(): string
            {
                return 'public_hard_cycle';
            }

            public function getScoreThresholds(): PolicyThresholdsDTO
            {
                return new PolicyThresholdsDTO(k4: new ScoreThresholdsDTO(1, 1, 1));
            }

            public function getScoreDeltas(): ScoreDeltasDTO
            {
                return new ScoreDeltasDTO(k4_failure: 1);
            }

            public function getFailureMode(): string
            {
                return 'FAIL_CLOSED';
            }

            public function getBudgetConfig(): ?BudgetConfigDTO
            {
                return null;
            }
        };
        $limiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('public-hard-cycle-key', 'public-hard-cycle-fingerprint', 'prod'),
            $this->store,
            new RecordingFailureSignalEmitter(),
        )->withClock($clock)->withPolicy($policy)->build();
        $context = new RateLimitContextDTO(
            '198.51.100.41',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'redis-public-hard-cycle',
            ['device' => 'hard-cycle'],
        );

        $first = $limiter->limit($context, RateLimitCommand::recordFailure('public_hard_cycle'));
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $first->decision);
        $clock->setNow($clock->now()->modify('+61 seconds'));
        $second = $limiter->limit($context, RateLimitCommand::recordFailure('public_hard_cycle'));
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $second->decision);

        $pauseKeys = $this->redisKeys('pause');
        self::assertNotEmpty($pauseKeys);
        self::assertGreaterThan(0, $this->integer($this->raw(['PTTL', $pauseKeys[0]])));
        self::assertNotEmpty($this->redisKeys('cycle'));
    }

    public function testPublicBuilderWorkflowUsesOfficialRedisBudgetAndCircuitProbePaths(): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $limiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('redis-public-key', 'redis-public-fingerprint', 'prod'),
            $this->store,
            new RecordingFailureSignalEmitter(),
        )->withClock($clock)->build();
        $context = new RateLimitContextDTO(
            '198.51.100.30',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'redis-public-budget',
            ['device' => 'budget'],
        );

        self::assertNotSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $limiter->limit($context, RateLimitCommand::recordFailure('login_protection'))->decision);
        self::assertNotSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $limiter->limit($context, RateLimitCommand::recordFailure('login_protection'))->decision);

        $openedAt = $clock->now()->getTimestamp();
        $this->store->save('api_heavy_protection', new CircuitBreakerStateDTO('OPEN', [$openedAt, $openedAt, $openedAt], $openedAt, $openedAt, 0, [$openedAt], 0));
        $clock->setNow(new \DateTimeImmutable('@' . ($openedAt + 300)));
        $probe = $limiter->limit($context, new RateLimitCommand('api_heavy_protection'));

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $probe->decision);
        self::assertSame('DEGRADED_MODE', $probe->failureMode);
        self::assertGreaterThan(0, $this->integer($this->raw(['TTL', $this->key('probe', 'api_heavy_protection')])));
    }

    public function testConcurrentIncrementsAreNotLost(): void
    {
        $this->runConcurrentWorkers(12, function (int $index): void {
            $this->workerStore()->increment('concurrent-increment', 600);
        });

        self::assertSame(12, $this->store->get('concurrent-increment')?->value);
    }

    public function testConcurrentBudgetSeedInitializationKeepsOneEpochAndAllIncrements(): void
    {
        $seed = new BudgetStateDTO(5, time() - 60);
        $this->runConcurrentWorkers(12, function (int $index) use ($seed): void {
            $this->workerStore()->incrementBudgetWithSeed('concurrent-budget', 600, $seed);
        });

        $budget = $this->store->getBudget('concurrent-budget');
        self::assertNotNull($budget);
        self::assertSame(17, $budget->count);
        self::assertSame($seed->epochStart, $budget->epochStart);
    }

    public function testConcurrentRotatedAdmissionsNeverExceedEffectiveCapacity(): void
    {
        $this->store->addDistinct('concurrent-previous', 'old-member', 600);
        $this->runConcurrentWorkers(12, function (int $index): void {
            $this->workerStore()->addDistinctBoundedWithSnapshotAcrossRotation(
                'concurrent-current',
                'concurrent-bridge',
                'concurrent-previous',
                'member-' . $index,
                'unknown-previous-' . $index,
                600,
                4,
            );
        });

        $snapshot = $this->store->addDistinctBoundedWithSnapshotAcrossRotation(
            'concurrent-current',
            'concurrent-bridge',
            'concurrent-previous',
            'observer',
            'unknown-observer',
            600,
            4,
        );
        self::assertFalse($snapshot->accepted);
        self::assertSame(4, $snapshot->count);
        self::assertCount(3, $this->rawMembers('concurrent-bridge'));
    }

    public function testConcurrentProbeLeaseHasExactlyOneWinner(): void
    {
        $now = time();
        $this->runConcurrentWorkers(12, function (int $index) use ($now): void {
            $store = $this->workerStore();
            if ($store->acquireProbeLease('concurrent-policy', $now, 60)) {
                $store->increment('probe-winners', 600);
            }
        });

        self::assertSame(1, $this->store->get('probe-winners')?->value);
        self::assertFalse($this->store->acquireProbeLease('concurrent-policy', $now + 1, 60));
    }

    public function testConcurrentHardBlockCycleTransitionActivatesOnePause(): void
    {
        $base = time();
        $this->store->blockWithCycleTracking('concurrent-hard-block', null, 2, 5, $base, 21600, 2, 600, 86400);
        $this->runConcurrentWorkers(12, function (int $index) use ($base): void {
            $store = $this->workerStore();
            $result = $store->blockWithCycleTracking('concurrent-hard-block', null, 2, 100, $base + 10, 21600, 2, 600, 86400);
            if ($result->pauseActivated) {
                $store->increment('pause-activation-winners', 600);
            }
        });

        self::assertSame(1, $this->store->get('pause-activation-winners')?->value);
        $pause = $this->store->readDecayPauseState('concurrent-hard-block', null, $base, $base + 10);
        self::assertSame($base + 610, $pause->activePauseUntil);
    }

    public function testBoundedSnapshotAndCircuitStateRoundTrip(): void
    {
        $first = $this->store->addDistinctBoundedWithSnapshot('bounded', 'one', 60, 2);
        self::assertSame(['one'], $first->members);
        self::assertTrue($this->store->addDistinctBoundedWithSnapshot('bounded', 'two', 60, 2)->added);
        self::assertFalse($this->store->addDistinctBoundedWithSnapshot('bounded', 'three', 60, 2)->accepted);
    }

    public function testRotatedBoundedStateUsesCapacityAndEstablishesBothFixedTtls(): void
    {
        self::assertSame(1, $this->store->addDistinct('previous', 'old', 60));
        $previousExpiry = $this->integer($this->raw(['HGET', $this->key('distinct-meta', 'previous'), 'expiresAt']));

        $snapshot = $this->store->addDistinctBoundedWithSnapshotAcrossRotation(
            'current',
            'bridge',
            'previous',
            'new',
            'new-old-alias',
            60,
            2,
        );

        self::assertSame(2, $snapshot->count);
        self::assertTrue($snapshot->accepted);
        self::assertTrue($snapshot->added);
        self::assertSame(['old', 'new'], $snapshot->members);
        self::assertGreaterThan(0, $this->integer($this->raw(['TTL', $this->key('distinct', 'current')])));
        self::assertGreaterThan(0, $this->integer($this->raw(['TTL', $this->key('distinct', 'bridge')])));
        self::assertLessThanOrEqual($previousExpiry, $this->integer($this->raw(['HGET', $this->key('distinct-meta', 'bridge'), 'expiresAt'])));
        self::assertSame($previousExpiry, $this->integer($this->raw(['HGET', $this->key('distinct-meta', 'previous'), 'expiresAt'])));
    }

    public function testRotatedBoundedStateRejectsNewMemberAtCapacityWithoutGrowth(): void
    {
        $this->store->addDistinct('previous', 'old-one', 60);
        $this->store->addDistinct('previous', 'old-two', 60);

        $rejected = $this->store->addDistinctBoundedWithSnapshotAcrossRotation(
            'current',
            'bridge',
            'previous',
            'new',
            'not-known',
            60,
            2,
        );

        self::assertFalse($rejected->accepted);
        self::assertFalse($rejected->added);
        self::assertSame(2, $rejected->count);
        self::assertSame(['old-one', 'old-two'], $rejected->members);
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('distinct', 'bridge')])));
    }

    public function testPreviousPauseIsAdoptedAndReadAcrossRotation(): void
    {
        $base = time();
        $this->hardBlock('previous', $base, 10);
        $this->hardBlock('previous', $base + 10, 10);

        $state = $this->store->readDecayPauseState('current', 'previous', $base, $base + 20);

        self::assertSame(10, $state->elapsedPausedSeconds);
        self::assertSame($base + 610, $state->activePauseUntil);
    }

    public function testPreviousCompletedPauseRemainsVisibleToLazyDecayUntilExactRetentionBoundary(): void
    {
        $base = time();
        $this->hardBlock('previous', $base, 10);
        $this->hardBlock('previous', $base + 10, 10);

        $retained = $this->store->readDecayPauseState('current', 'previous', $base, $base + 610 + 86400 - 1);
        self::assertSame(600, $retained->elapsedPausedSeconds);

        $expired = $this->store->readDecayPauseState('current', 'previous', $base, $base + 610 + 86400);
        self::assertSame(0, $expired->elapsedPausedSeconds);
    }

    public function testActivePreviousPausePreventsCurrentPauseRenewalAndAdoptionIsStable(): void
    {
        $base = time();
        $this->hardBlock('previous', $base, 10);
        $this->hardBlock('previous', $base + 10, 10);

        $first = $this->hardBlock('current', $base + 20, 10, 'previous');
        self::assertFalse($first->pauseActivated);
        self::assertSame($base + 610, $first->pauseUntil);

        $second = $this->hardBlock('current', $base + 30, 10, 'previous');
        self::assertFalse($second->pauseActivated);
        self::assertSame($base + 610, $second->pauseUntil);

        $currentOnly = $this->store->readDecayPauseState('current', null, $base, $base + 30);
        self::assertSame($base + 610, $currentOnly->activePauseUntil);
    }

    public function testCurrentCycleTtlExtendsToTheLatestCycleAndWindow(): void
    {
        $base = time();
        $this->store->blockWithCycleTracking('retention-cycle', null, 2, 1, $base, 60, 10, 30, 120);
        $cycleKey = $this->key('cycle', 'retention-cycle');
        $this->raw(['EXPIRE', $cycleKey, 1]);

        $this->store->blockWithCycleTracking('retention-cycle', null, 2, 1, $base + 10, 60, 10, 30, 120);

        self::assertGreaterThanOrEqual(50, $this->integer($this->raw(['TTL', $cycleKey])));
    }

    public function testFirstPauseTtlCoversPauseDurationAndRetention(): void
    {
        $base = time();
        $result = $this->store->blockWithCycleTracking('retention-pause', null, 2, 1, $base, 60, 1, 60, 120);

        self::assertTrue($result->pauseActivated);
        self::assertGreaterThanOrEqual(170, $this->integer($this->raw(['TTL', $this->key('pause', 'retention-pause')])));
    }

    public function testLaterRetainedPauseExtendsCurrentPauseTtlWithoutTouchingPreviousTtl(): void
    {
        $base = time();
        $this->store->blockWithCycleTracking('retention-pauses', null, 2, 1, $base, 21600, 1, 60, 120);
        $pauseKey = $this->key('pause', 'retention-pauses');
        $this->raw(['EXPIRE', $pauseKey, 1]);

        $later = $this->store->blockWithCycleTracking('retention-pauses', null, 2, 1, $base + 70, 21600, 2, 60, 120);

        self::assertTrue($later->pauseActivated);
        self::assertGreaterThanOrEqual(170, $this->integer($this->raw(['TTL', $pauseKey])));
    }

    public function testPreviousHistoryTtlIsReadOnlyDuringAdoption(): void
    {
        $base = time();
        $this->store->blockWithCycleTracking('retention-previous', null, 2, 1, $base, 60, 1, 60, 120);
        $previousCycleKey = $this->key('cycle', 'retention-previous');
        $this->raw(['EXPIRE', $previousCycleKey, 30]);
        $before = $this->integer($this->raw(['TTL', $previousCycleKey]));

        $this->store->blockWithCycleTracking('retention-current', 'retention-previous', 2, 1, $base + 1, 60, 10, 60, 120);

        $after = $this->integer($this->raw(['TTL', $previousCycleKey]));
        self::assertLessThanOrEqual($before, $after);
        self::assertGreaterThanOrEqual($before - 1, $after);
    }

    public function testMalformedPersistedScoreBlockBudgetAndCorrelationStateFailsExplicitly(): void
    {
        $scoreKey = $this->key('score', 'malformed-score');
        $this->raw(['HSET', $scoreKey, 'value', 'not-an-integer', 'updatedAt', '1']);
        $this->raw(['EXPIRE', $scoreKey, 60]);
        $this->assertOperationFails(fn(): mixed => $this->store->get('malformed-score'));
        $this->raw(['HSET', $scoreKey, 'value', '1', 'updatedAt', '1.5']);
        $this->assertOperationFails(fn(): mixed => $this->store->increment('malformed-score', 60));

        $scoreWithoutTtl = $this->key('score', 'malformed-score-without-ttl');
        $this->raw(['HSET', $scoreWithoutTtl, 'value', '1', 'updatedAt', '1']);
        $this->assertOperationFails(fn(): mixed => $this->store->increment('malformed-score-without-ttl', 60));

        $blockKey = $this->key('block', 'malformed-block');
        $this->raw(['HSET', $blockKey, 'level', '2.5', 'expiresAt', (string) (time() + 60)]);
        $this->raw(['EXPIRE', $blockKey, 60]);
        $this->assertOperationFails(fn(): mixed => $this->store->checkBlock('malformed-block'));

        $budgetKey = $this->key('budget', 'malformed-budget');
        $this->raw(['HSET', $budgetKey, 'count', '1']);
        $this->raw(['EXPIRE', $budgetKey, 60]);
        $this->assertOperationFails(fn(): mixed => $this->store->incrementBudget('malformed-budget', 60));
        $this->raw(['HSET', $budgetKey, 'count', '1', 'epochStart', (string) time(), 'epochDuration', '1.5']);
        $this->assertOperationFails(fn(): mixed => $this->store->getBudget('malformed-budget'));

        $distinctKey = $this->key('distinct', 'malformed-distinct');
        $distinctMetaKey = $this->key('distinct-meta', 'malformed-distinct');
        $this->raw(['SADD', $distinctKey, 'member']);
        $this->raw(['EXPIRE', $distinctKey, 60]);
        $this->raw(['HSET', $distinctMetaKey, 'expiresAt', 'not-an-integer']);
        $this->raw(['EXPIRE', $distinctMetaKey, 60]);
        $this->assertOperationFails(fn(): mixed => $this->store->addDistinct('malformed-distinct', 'new', 60));

        $watchKey = $this->key('watch', 'malformed-watch');
        $this->raw(['SET', $watchKey, 'not-an-integer', 'EX', 60]);
        $this->assertOperationFails(fn(): mixed => $this->store->getWatchFlag('malformed-watch'));
        $this->raw(['SET', $watchKey, '1', 'EX', 60]);
        $this->raw(['HSET', $this->key('watch-meta', 'malformed-watch'), 'expiresAt', 'not-an-integer']);
        $this->raw(['EXPIRE', $this->key('watch-meta', 'malformed-watch'), 60]);
        $this->assertOperationFails(fn(): mixed => $this->store->incrementWatchFlag('malformed-watch', 60));

        $boundedKey = $this->key('distinct', 'malformed-bounded');
        $this->raw(['SADD', $boundedKey, 'member']);
        $this->raw(['EXPIRE', $boundedKey, 60]);
        $this->assertOperationFails(fn(): mixed => $this->store->addDistinctBoundedWithSnapshot('malformed-bounded', 'new', 60, 2));

        $previousKey = $this->key('distinct', 'malformed-rotation-previous');
        $this->raw(['SADD', $previousKey, 'old']);
        $this->raw(['EXPIRE', $previousKey, 60]);
        $this->assertOperationFails(fn(): mixed => $this->store->addDistinctBoundedWithSnapshotAcrossRotation('malformed-rotation-current', 'malformed-rotation-bridge', 'malformed-rotation-previous', 'new', 'unknown', 60, 2));
    }

    public function testMalformedLeaseAndHardBlockHistoryFailExplicitly(): void
    {
        $leaseKey = $this->key('probe', 'malformed-lease');
        $this->raw(['SET', $leaseKey, 'not-an-integer', 'EX', 60]);
        $this->assertOperationFails(fn(): mixed => $this->store->acquireProbeLease('malformed-lease', time(), 60));
        $this->raw(['SET', $leaseKey, (string) time()]);
        $this->assertOperationFails(fn(): mixed => $this->store->acquireProbeLease('malformed-lease', time(), 60));

        $cycleKey = $this->key('cycle', 'malformed-cycle');
        $this->raw(['ZADD', $cycleKey, 1, 'not-a-timestamp']);
        $this->raw(['EXPIRE', $cycleKey, 60]);
        $this->assertOperationFails(fn(): mixed => $this->store->blockWithCycleTracking('malformed-cycle', null, 2, 60, time(), 21600, 2, 600, 86400));

        $pauseKey = $this->key('pause', 'malformed-pause');
        $this->raw(['ZADD', $pauseKey, 1, 'not-an-interval']);
        $this->raw(['EXPIRE', $pauseKey, 60]);
        $this->assertOperationFails(fn(): mixed => $this->store->readDecayPauseState('malformed-pause', null, time() - 60, time()));
    }

    public function testRedisIntegrationScriptPreservesVerificationAndCleanupStatuses(): void
    {
        $cases = [
            [0, 0, 0],
            [7, 0, 7],
            [0, 9, 9],
            [7, 9, 7],
        ];
        $script = dirname(__DIR__, 3) . '/scripts/ci/run-integration-with-redis.sh';
        $fakeDocker = dirname(__DIR__, 2) . '/Support/Redis/fake-docker.sh';

        foreach ($cases as [$verificationStatus, $cleanupStatus, $expectedStatus]) {
            $environment = getenv();
            $environment['REDIS_INTEGRATION_DOCKER_BIN'] = $fakeDocker;
            $environment['REDIS_INTEGRATION_TEST_STATUS'] = (string) $verificationStatus;
            $environment['REDIS_FAKE_COMPOSE_UP_STATUS'] = '0';
            $environment['REDIS_FAKE_COMPOSE_DOWN_STATUS'] = (string) $cleanupStatus;
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open('bash ' . escapeshellarg($script), $descriptors, $pipes, dirname(__DIR__, 3), $environment);
            if (! is_resource($process)) {
                self::fail('Unable to start Redis integration script harness.');
            }
            /** @var array{0: resource, 1: resource, 2: resource} $pipes */
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame($expectedStatus, proc_close($process));
        }
    }

    public function testNamespaceValidationAndFamilyIsolationUseHashNamespacedKeys(): void
    {
        foreach (['', ' ', '{invalid}', 'invalid/slash', str_repeat('a', 129)] as $invalidNamespace) {
            $this->assertOperationFails(fn(): RedisFullCapabilityStore => new RedisFullCapabilityStore($this->executor, $invalidNamespace));
        }

        $validNamespace = new RedisFullCapabilityStore($this->executor, 'integration.valid:namespace-1');
        self::assertNull($validNamespace->get('same-logical-key'));
        $otherNamespace = new RedisFullCapabilityStore($this->executor, 'integration:other');

        $logical = 'same-logical-key';
        self::assertSame(1, $this->store->increment($logical, 60));
        self::assertNull($otherNamespace->get('same-logical-key'));

        $this->store->block($logical, 2, 1);
        $budget = $this->store->incrementBudget($logical, 60);
        self::assertSame(1, $budget->count);
        self::assertSame(1, $this->store->addDistinct($logical, 'member', 60));
        self::assertSame(1, $this->store->incrementWatchFlag($logical, 60));
        $this->store->save($logical, new CircuitBreakerStateDTO('CLOSED', [], 0, 0, 0, [], 0));
        self::assertTrue($this->store->acquireProbeLease($logical, time(), 60));

        $base = time();
        $this->store->blockWithCycleTracking($logical, null, 2, 1, $base + 2, 21600, 2, 600, 86400);
        $this->store->blockWithCycleTracking($logical, null, 2, 1, $base + 4, 21600, 2, 600, 86400);
        $this->store->block($logical, 2, 60);

        self::assertSame(1, $this->store->get($logical)?->value);
        self::assertSame(2, $this->store->checkBlock($logical)?->level);
        self::assertSame('CLOSED', $this->store->load($logical)?->status);
        foreach (['score', 'block', 'budget', 'distinct', 'distinct-meta', 'watch', 'watch-meta', 'circuit', 'probe', 'cycle', 'pause'] as $family) {
            self::assertSame(1, $this->integer($this->raw(['EXISTS', $this->key($family, $logical)])), $family);
        }
    }

    public function testScoreUpdatedAtTracksPersistedRedisStateAcrossWrites(): void
    {
        self::assertSame(1, $this->store->increment('updated-at', 60));
        $first = $this->store->get('updated-at');
        self::assertNotNull($first);
        self::assertSame($first->value, $this->integer($this->raw(['HGET', $this->key('score', 'updated-at'), 'value'])));
        self::assertSame($first->updatedAt, $this->integer($this->raw(['HGET', $this->key('score', 'updated-at'), 'updatedAt'])));

        usleep(1_100_000);
        self::assertSame(2, $this->store->increment('updated-at', 60));
        $second = $this->store->get('updated-at');
        self::assertNotNull($second);
        self::assertGreaterThan($first->updatedAt, $second->updatedAt);
        self::assertSame($second->value, $this->integer($this->raw(['HGET', $this->key('score', 'updated-at'), 'value'])));
        self::assertSame($second->updatedAt, $this->integer($this->raw(['HGET', $this->key('score', 'updated-at'), 'updatedAt'])));
    }

    public function testFiniteSubsecondTtlRemainsValidForScoreBudgetAndProbeLease(): void
    {
        self::assertSame(1, $this->store->increment('subsecond-score', 60));
        $scoreKey = $this->key('score', 'subsecond-score');
        $this->raw(['PEXPIRE', $scoreKey, 50]);
        self::assertSame(0, $this->integer($this->raw(['TTL', $scoreKey])));
        self::assertSame(2, $this->store->increment('subsecond-score', 60));

        $budget = $this->store->incrementBudget('subsecond-budget', 60);
        $budgetKey = $this->key('budget', 'subsecond-budget');
        $this->raw(['PEXPIRE', $budgetKey, 50]);
        self::assertSame(0, $this->integer($this->raw(['TTL', $budgetKey])));
        self::assertSame($budget->epochStart, $this->store->incrementBudget('subsecond-budget', 60)->epochStart);

        $now = time();
        self::assertTrue($this->store->acquireProbeLease('subsecond-probe', $now, 60));
        $probeKey = $this->key('probe', 'subsecond-probe');
        $this->raw(['PEXPIRE', $probeKey, 50]);
        self::assertSame(0, $this->integer($this->raw(['TTL', $probeKey])));
        self::assertFalse($this->store->acquireProbeLease('subsecond-probe', $now + 1, 60));
    }

    public function testScoreAndBlockWindowsKeepFixedTtlAndExpireAtExactBoundary(): void
    {
        self::assertSame(2, $this->store->increment('score-window', 60, 2));
        $scoreKey = $this->key('score', 'score-window');
        $this->raw(['PEXPIRE', $scoreKey, 5_000]);
        $scorePttl = $this->integer($this->raw(['PTTL', $scoreKey]));
        self::assertGreaterThan(0, $scorePttl);
        self::assertSame(5, $this->store->increment('score-window', 60, 3));
        $scoreAfterPttl = $this->integer($this->raw(['PTTL', $scoreKey]));
        self::assertLessThanOrEqual($scorePttl, $scoreAfterPttl);
        self::assertLessThan(60_000, $scoreAfterPttl);
        $this->store->set('score-window', 9, 60);
        self::assertSame(9, $this->store->get('score-window')?->value);
        $this->raw(['PEXPIRE', $scoreKey, 50]);
        usleep(80_000);
        self::assertNull($this->store->get('score-window'));

        $this->store->block('block-window', 2, 60);
        self::assertSame(2, $this->store->checkBlock('block-window')?->level);
        $this->raw(['PEXPIRE', $this->key('block', 'block-window'), 50]);
        usleep(80_000);
        self::assertNull($this->store->checkBlock('block-window'));

        $this->store->block('block-logical-boundary', 2, 60);
        $logicalBoundaryKey = $this->key('block', 'block-logical-boundary');
        $this->raw(['HSET', $logicalBoundaryKey, 'expiresAt', $this->redisNow()]);
        $this->raw(['PEXPIRE', $logicalBoundaryKey, 60_000]);
        self::assertSame(1, $this->integer($this->raw(['EXISTS', $logicalBoundaryKey])));
        self::assertNull($this->store->checkBlock('block-logical-boundary'));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $logicalBoundaryKey])));
    }

    public function testBudgetEpochAndSeedSemanticsAreFixedAndExplicit(): void
    {
        $first = $this->store->incrementBudget('budget-matrix', 60, 2);
        $budgetKey = $this->key('budget', 'budget-matrix');
        $this->raw(['PEXPIRE', $budgetKey, 5_000]);
        $pttl = $this->integer($this->raw(['PTTL', $budgetKey]));
        $second = $this->store->incrementBudget('budget-matrix', 60, 3);
        self::assertSame($first->epochStart, $second->epochStart);
        $afterPttl = $this->integer($this->raw(['PTTL', $budgetKey]));
        self::assertLessThanOrEqual($pttl, $afterPttl);
        self::assertLessThan(60_000, $afterPttl);

        $this->raw(['PEXPIRE', $budgetKey, 50]);
        usleep(1_100_000);
        self::assertNull($this->store->getBudget('budget-matrix'));
        $newEpoch = $this->store->incrementBudget('budget-matrix', 60, 1);
        self::assertNotSame($first->epochStart, $newEpoch->epochStart);

        $seed = new BudgetStateDTO(20, time() - 10);
        $seeded = $this->store->incrementBudgetWithSeed('budget-seeded-valid', 60, $seed, 2);
        self::assertSame(22, $seeded->count);
        self::assertSame($seed->epochStart, $seeded->epochStart);
        $seededAgain = $this->store->incrementBudgetWithSeed('budget-seeded-valid', 60, new BudgetStateDTO(999, time()), 3);
        self::assertSame(25, $seededAgain->count);
        self::assertSame($seed->epochStart, $seededAgain->epochStart);

        $expiredSeed = $this->store->incrementBudgetWithSeed('budget-seeded-expired', 60, new BudgetStateDTO(20, time() - 120), 2);
        self::assertSame(2, $expiredSeed->count);
        $current = $this->store->incrementBudget('budget-seed-current-wins', 60, 4);
        $currentWins = $this->store->incrementBudgetWithSeed('budget-seed-current-wins', 60, new BudgetStateDTO(999, time() - 10), 1);
        self::assertSame(5, $currentWins->count);
        self::assertSame($current->epochStart, $currentWins->epochStart);
    }

    public function testExpiredExistingBudgetRejectsImpossibleFreshDurationBeforeReplacement(): void
    {
        $budgetKey = $this->key('budget', 'expired-existing-requested-duration');
        $now = $this->redisNow();
        $this->raw(['HSET', $budgetKey, 'count', 7, 'epochStart', $now - 2, 'epochDuration', 1]);
        $this->raw(['EXPIRE', $budgetKey, 60]);
        $before = $this->hashMap($budgetKey);

        $failed = false;
        try {
            $this->store->incrementBudget('expired-existing-requested-duration', 9007199254740992);
        } catch (\Throwable) {
            $failed = true;
        }

        self::assertTrue($failed);
        self::assertSame($before, $this->hashMap($budgetKey));
        self::assertSame(1, $this->integer($this->raw(['EXISTS', $budgetKey])));
    }

    public function testActiveCurrentIgnoresUnrepresentableSeedBoundary(): void
    {
        $current = $this->store->incrementBudget('seed-boundary-current-wins', 60, 4);

        $result = $this->store->incrementBudgetWithSeed(
            'seed-boundary-current-wins',
            60,
            new BudgetStateDTO(999, PHP_INT_MAX),
        );

        self::assertSame(5, $result->count);
        self::assertSame($current->epochStart, $result->epochStart);

        $persisted = $this->store->getBudget('seed-boundary-current-wins');
        self::assertNotNull($persisted);
        self::assertSame(5, $persisted->count);
        self::assertSame($current->epochStart, $persisted->epochStart);
    }

    public function testDistinctWatchAndBoundedWindowsDoNotRefreshAndExpire(): void
    {
        $firstDistinctCount = $this->store->addDistinct('distinct-matrix', 'one', 60);
        self::assertSame(1, $firstDistinctCount);
        $distinctKey = $this->key('distinct', 'distinct-matrix');
        $this->raw(['PEXPIRE', $distinctKey, 5_000]);
        $this->raw(['PEXPIRE', $this->key('distinct-meta', 'distinct-matrix'), 5_000]);
        $distinctPttl = $this->integer($this->raw(['PTTL', $distinctKey]));
        $duplicateDistinctCount = $this->store->addDistinct('distinct-matrix', 'one', 60);
        self::assertSame($firstDistinctCount, $duplicateDistinctCount);
        $distinctAfterPttl = $this->integer($this->raw(['PTTL', $distinctKey]));
        self::assertLessThanOrEqual($distinctPttl, $distinctAfterPttl);
        self::assertLessThan(60_000, $distinctAfterPttl);
        self::assertSame(2, $this->store->addDistinct('distinct-matrix', 'two', 60));
        $this->raw(['PEXPIRE', $distinctKey, 50]);
        $this->raw(['PEXPIRE', $this->key('distinct-meta', 'distinct-matrix'), 50]);
        usleep(80_000);
        self::assertSame(1, $this->store->addDistinct('distinct-matrix', 'after-expiry', 60));

        self::assertSame(0, $this->store->getWatchFlag('missing-watch'));
        self::assertSame(1, $this->store->incrementWatchFlag('watch-matrix', 60));
        $watchKey = $this->key('watch', 'watch-matrix');
        $this->raw(['PEXPIRE', $watchKey, 5_000]);
        $this->raw(['PEXPIRE', $this->key('watch-meta', 'watch-matrix'), 5_000]);
        $watchPttl = $this->integer($this->raw(['PTTL', $watchKey]));
        self::assertSame(2, $this->store->incrementWatchFlag('watch-matrix', 60));
        $watchAfterPttl = $this->integer($this->raw(['PTTL', $watchKey]));
        self::assertLessThanOrEqual($watchPttl, $watchAfterPttl);
        self::assertLessThan(60_000, $watchAfterPttl);
        $this->raw(['PEXPIRE', $watchKey, 50]);
        $this->raw(['PEXPIRE', $this->key('watch-meta', 'watch-matrix'), 50]);
        usleep(80_000);
        self::assertSame(1, $this->store->incrementWatchFlag('watch-matrix', 60));

        $first = $this->store->addDistinctBoundedWithSnapshot('bounded-matrix', 'one', 60, 2);
        $boundedExpiry = $first->expiresAt;
        $boundedKey = $this->key('distinct', 'bounded-matrix');
        $this->raw(['PEXPIRE', $boundedKey, 5_000]);
        $this->raw(['PEXPIRE', $this->key('distinct-meta', 'bounded-matrix'), 5_000]);
        $boundedPttl = $this->integer($this->raw(['PTTL', $boundedKey]));
        $second = $this->store->addDistinctBoundedWithSnapshot('bounded-matrix', 'one', 60, 2);
        self::assertSame($boundedExpiry, $second->expiresAt);
        $boundedAfterPttl = $this->integer($this->raw(['PTTL', $boundedKey]));
        self::assertLessThanOrEqual($boundedPttl, $boundedAfterPttl);
        self::assertLessThan(60_000, $boundedAfterPttl);
        self::assertTrue($this->store->addDistinctBoundedWithSnapshot('bounded-matrix', 'two', 60, 2)->accepted);
        self::assertFalse($this->store->addDistinctBoundedWithSnapshot('bounded-matrix', 'three', 60, 2)->accepted);
    }

    public function testRotationKeepsPreviousReadOnlyAndBoundsBridgeTtlWithoutRefresh(): void
    {
        self::assertSame(1, $this->store->addDistinct('rotation-previous', 'old', 60));
        $previousKey = $this->key('distinct', 'rotation-previous');
        $previousMetaKey = $this->key('distinct-meta', 'rotation-previous');
        $previousMembers = $this->raw(['SMEMBERS', $previousKey]);
        $this->raw(['PEXPIRE', $previousKey, 10_000]);
        $this->raw(['PEXPIRE', $previousMetaKey, 10_000]);
        $previousPttl = $this->integer($this->raw(['PTTL', $previousKey]));
        $previousExpiry = $this->integer($this->raw(['HGET', $previousMetaKey, 'expiresAt']));

        $first = $this->store->addDistinctBoundedWithSnapshotAcrossRotation('rotation-current', 'rotation-bridge', 'rotation-previous', 'new', 'not-known', 60, 3);
        self::assertSame(['old', 'new'], $first->members);
        $currentKey = $this->key('distinct', 'rotation-current');
        $currentMetaKey = $this->key('distinct-meta', 'rotation-current');
        $bridgeKey = $this->key('distinct', 'rotation-bridge');
        $bridgeMetaKey = $this->key('distinct-meta', 'rotation-bridge');
        self::assertLessThanOrEqual($previousExpiry, $this->integer($this->raw(['HGET', $this->key('distinct-meta', 'rotation-bridge'), 'expiresAt'])));
        self::assertSame($previousExpiry, $first->expiresAt);

        $this->raw(['PEXPIRE', $currentKey, 5_000]);
        $this->raw(['PEXPIRE', $currentMetaKey, 5_000]);
        $this->raw(['PEXPIRE', $bridgeKey, 5_000]);
        $this->raw(['PEXPIRE', $bridgeMetaKey, 5_000]);
        $currentPttl = $this->integer($this->raw(['PTTL', $currentKey]));
        $bridgePttl = $this->integer($this->raw(['PTTL', $bridgeKey]));

        $duplicate = $this->store->addDistinctBoundedWithSnapshotAcrossRotation('rotation-current', 'rotation-bridge', 'rotation-previous', 'new', 'not-known', 60, 3);
        self::assertSame($first->members, $duplicate->members);
        self::assertFalse($duplicate->added);
        $currentAfterDuplicate = $this->integer($this->raw(['PTTL', $currentKey]));
        $bridgeAfterDuplicate = $this->integer($this->raw(['PTTL', $bridgeKey]));
        self::assertLessThanOrEqual($currentPttl, $currentAfterDuplicate);
        self::assertLessThan(60_000, $currentAfterDuplicate);
        self::assertLessThanOrEqual($bridgePttl, $bridgeAfterDuplicate);
        self::assertLessThan(60_000, $bridgeAfterDuplicate);

        $secondNew = $this->store->addDistinctBoundedWithSnapshotAcrossRotation('rotation-current', 'rotation-bridge', 'rotation-previous', 'second-new', 'not-known', 60, 3);
        self::assertSame(['old', 'new', 'second-new'], $secondNew->members);
        self::assertTrue($secondNew->added);
        self::assertLessThanOrEqual($currentAfterDuplicate, $this->integer($this->raw(['PTTL', $currentKey])));
        self::assertLessThanOrEqual($bridgeAfterDuplicate, $this->integer($this->raw(['PTTL', $bridgeKey])));
        self::assertSame($previousMembers, $this->raw(['SMEMBERS', $previousKey]));
        self::assertSame($previousExpiry, $this->integer($this->raw(['HGET', $previousMetaKey, 'expiresAt'])));
        self::assertLessThanOrEqual($previousPttl, $this->integer($this->raw(['PTTL', $previousKey])));

        self::assertSame(1, $this->store->addDistinct('known-previous', 'old-known', 60));
        $known = $this->store->addDistinctBoundedWithSnapshotAcrossRotation(
            'known-current',
            'known-bridge',
            'known-previous',
            'current-known',
            'old-known',
            60,
            3,
        );
        self::assertTrue($known->accepted);
        self::assertFalse($known->added);
        self::assertSame(1, $known->count);
        self::assertSame(['old-known'], $known->members);
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('distinct', 'known-bridge')])));
        self::assertSame($this->integer($this->raw(['HGET', $this->key('distinct-meta', 'known-previous'), 'expiresAt'])), $known->expiresAt);
    }

    public function testHardBlockCycleBoundariesAndPauseUnionUseRealRedis(): void
    {
        $base = time();
        $first = $this->store->blockWithCycleTracking('hard-matrix', null, 2, 60, $base, 60, 2, 30, 120);
        self::assertTrue($first->newCycle);
        self::assertSame(1, $first->cycleCount);
        self::assertFalse($first->pauseActivated);

        $active = $this->store->blockWithCycleTracking('hard-matrix', null, 3, 60, $base + 10, 60, 2, 30, 120);
        self::assertFalse($active->newCycle);
        self::assertSame(1, $active->cycleCount);

        $this->store->blockWithCycleTracking('hard-exact', null, 2, 60, $base, 60, 2, 30, 120);
        $exact = $this->store->blockWithCycleTracking('hard-exact', null, 2, 60, $base + 60, 60, 2, 30, 120);
        self::assertTrue($exact->newCycle);
        self::assertSame(2, $exact->cycleCount);

        $threshold = $this->store->blockWithCycleTracking('hard-threshold', null, 2, 60, $base, 120, 2, 30, 120);
        self::assertFalse($threshold->pauseActivated);
        $threshold = $this->store->blockWithCycleTracking('hard-threshold', null, 2, 60, $base + 61, 120, 2, 30, 120);
        self::assertTrue($threshold->pauseActivated);
        self::assertSame($base + 91, $threshold->pauseUntil);
        $renewal = $this->store->blockWithCycleTracking('hard-threshold', null, 2, 60, $base + 62, 60, 2, 30, 120);
        self::assertFalse($renewal->pauseActivated);
        self::assertSame($base + 91, $renewal->pauseUntil);

        $union = $this->store->readDecayPauseState('hard-threshold', null, $base, $base + 80);
        self::assertSame(19, $union->elapsedPausedSeconds);
        self::assertSame($base + 91, $union->activePauseUntil);
    }

    public function testHardBlockUsesCanonicalInclusiveBoundaryAndPrunesOlderCycles(): void
    {
        $base = time();
        $this->store->blockWithCycleTracking('hard-canonical-exact', null, 2, 1, $base, 21600, 2, 600, 86400);
        $exact = $this->store->blockWithCycleTracking('hard-canonical-exact', null, 2, 1, $base + 21600, 21600, 2, 600, 86400);
        self::assertTrue($exact->newCycle);
        self::assertSame(2, $exact->cycleCount);
        self::assertTrue($exact->pauseActivated);
        self::assertSame($base + 22200, $exact->pauseUntil);

        $this->store->blockWithCycleTracking('hard-canonical-outside', null, 2, 1, $base, 21600, 2, 600, 86400);
        $outside = $this->store->blockWithCycleTracking('hard-canonical-outside', null, 2, 1, $base + 21601, 21600, 2, 600, 86400);
        self::assertTrue($outside->newCycle);
        self::assertSame(1, $outside->cycleCount);
        self::assertFalse($outside->pauseActivated);
    }

    public function testHardBlockAdoptsPreviousHistoryWithoutMutatingPreviousPhysicalState(): void
    {
        $base = time();
        $this->store->blockWithCycleTracking('hard-previous-read-only', null, 2, 600, $base, 21600, 2, 600, 86400);
        $previousCycleKey = $this->key('cycle', 'hard-previous-read-only');
        $previousBlockKey = $this->key('block', 'hard-previous-read-only');
        $this->raw(['PEXPIRE', $previousCycleKey, 5_000]);
        $this->raw(['PEXPIRE', $previousBlockKey, 5_000]);
        $previousMembers = $this->raw(['ZRANGE', $previousCycleKey, 0, -1]);
        $previousBlockExpires = $this->raw(['HGET', $previousBlockKey, 'expiresAt']);
        $previousCyclePttl = $this->integer($this->raw(['PTTL', $previousCycleKey]));
        $previousBlockPttl = $this->integer($this->raw(['PTTL', $previousBlockKey]));

        $current = $this->store->blockWithCycleTracking('hard-current-read-only', 'hard-previous-read-only', 2, 1, $base + 601, 21600, 2, 600, 86400);
        self::assertTrue($current->newCycle);
        self::assertSame(2, $current->cycleCount);
        $repeat = $this->store->blockWithCycleTracking('hard-current-read-only', 'hard-previous-read-only', 2, 1, $base + 601, 21600, 2, 600, 86400);
        self::assertFalse($repeat->newCycle);
        self::assertSame(2, $repeat->cycleCount);
        self::assertSame($previousMembers, $this->raw(['ZRANGE', $previousCycleKey, 0, -1]));
        self::assertSame($previousBlockExpires, $this->raw(['HGET', $previousBlockKey, 'expiresAt']));
        self::assertLessThanOrEqual($previousCyclePttl, $this->integer($this->raw(['PTTL', $previousCycleKey])));
        self::assertLessThanOrEqual($previousBlockPttl, $this->integer($this->raw(['PTTL', $previousBlockKey])));
    }

    public function testHardBlockStartsAnotherCanonicalPauseAfterThePreviousPauseCompletes(): void
    {
        $base = time();
        $this->store->blockWithCycleTracking('hard-later-pause', null, 2, 1, $base, 21600, 2, 600, 86400);
        $firstPause = $this->store->blockWithCycleTracking('hard-later-pause', null, 2, 1, $base + 2, 21600, 2, 600, 86400);
        self::assertTrue($firstPause->pauseActivated);
        self::assertSame($base + 602, $firstPause->pauseUntil);

        $secondPause = $this->store->blockWithCycleTracking('hard-later-pause', null, 2, 1, $base + 603, 21600, 2, 600, 86400);
        self::assertTrue($secondPause->newCycle);
        self::assertTrue($secondPause->pauseActivated);
        self::assertSame($base + 1203, $secondPause->pauseUntil);

        $unknown = $this->store->readDecayPauseState('hard-unknown-current', 'hard-unknown-previous', $base, $base + 1);
        self::assertSame(0, $unknown->elapsedPausedSeconds);
        self::assertSame(0, $unknown->activePauseUntil);
    }

    public function testCompleteCircuitStateRoundTripAndProbeLeaseBoundary(): void
    {
        $state = new CircuitBreakerStateDTO('OPEN', [10, 20], 20, 20, 30, [40], 50);
        $this->store->save('policy', $state);
        $loaded = $this->store->load('policy');
        self::assertNotNull($loaded);
        self::assertSame($state->jsonSerialize(), $loaded->jsonSerialize());
        self::assertTrue($this->store->acquireProbeLease('policy', 100, 10));
        self::assertFalse($this->store->acquireProbeLease('policy', 109, 10));
        self::assertTrue($this->store->acquireProbeLease('policy', 110, 10));
    }

    public function testHealthBoundaryPreservesExecutorFailureProvenance(): void
    {
        $invalidPing = new class implements RedisCommandExecutorInterface {
            /** @param non-empty-list<int|string|float> $command */
            public function execute(array $command): mixed
            {
                return 'NOT_PONG';
            }
        };
        $throwing = new class implements RedisCommandExecutorInterface {
            /** @param non-empty-list<int|string|float> $command */
            public function execute(array $command): mixed
            {
                throw new \RuntimeException('backend unavailable');
            }
        };
        $typed = new class implements RedisCommandExecutorInterface {
            /** @param non-empty-list<int|string|float> $command */
            public function execute(array $command): mixed
            {
                throw new BackendFailureException('backend unavailable');
            }
        };
        self::assertFalse((new RedisFullCapabilityStore($invalidPing, 'health-invalid'))->isHealthy());
        $typedStore = new RedisFullCapabilityStore($typed, 'health-typed');
        self::assertFalse($typedStore->isHealthy());
        $throwingStore = new RedisFullCapabilityStore($throwing, 'health-throwing');
        $this->expectException(\RuntimeException::class);
        $throwingStore->isHealthy();
    }

    public function testHealthBoundaryPropagatesTypeError(): void
    {
        $typeError = new class implements RedisCommandExecutorInterface {
            /** @param non-empty-list<int|string|float> $command */
            public function execute(array $command): mixed
            {
                throw new \TypeError('programming failure');
            }
        };
        $store = new RedisFullCapabilityStore($typeError, 'health-type-error');

        $this->expectException(\TypeError::class);
        $store->isHealthy();
    }

    public function testRespExecutorSeparatesMalformedRepliesFromTransportTermination(): void
    {
        foreach ([':not-an-integer' . "\r\n", '$not-a-length' . "\r\n", '*not-an-array-length' . "\r\n", '+PONG' . "\n"] as $reply) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            self::assertIsArray($pair);
            $executor = $this->executorWithSocket($pair[0]);
            fwrite($pair[1], $reply);

            $exception = null;
            try {
                $executor->execute(['PING']);
            } catch (\Throwable $caught) {
                $exception = $caught;
            } finally {
                fclose($pair[0]);
                fclose($pair[1]);
            }
            self::assertNotNull($exception, 'Malformed RESP reply was accepted.');
            self::assertNotSame(BackendFailureException::class, $exception::class);
        }

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertIsArray($pair);
        $executor = $this->executorWithSocket($pair[0]);
        fwrite($pair[1], '-ERR server failure' . "\r\n");
        try {
            $executor->execute(['PING']);
            self::fail('Redis server error reply was accepted.');
        } catch (\Maatify\RateLimiter\Exception\RateLimiterException $exception) {
            self::assertSame(\Maatify\RateLimiter\Exception\RateLimiterException::class, $exception::class);
        } finally {
            fclose($pair[0]);
            fclose($pair[1]);
        }

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertIsArray($pair);
        $executor = $this->executorWithSocket($pair[0]);
        fwrite($pair[1], '$5' . "\r\nabc");
        stream_socket_shutdown($pair[1], STREAM_SHUT_WR);
        $transportEnded = false;
        try {
            $executor->execute(['PING']);
        } catch (BackendFailureException $exception) {
            $transportEnded = true;
        } finally {
            fclose($pair[0]);
        }
        self::assertTrue($transportEnded, 'Determinable RESP EOF was not classified as transport failure.');
    }

    public function testStatefulUnknownExecutorFailurePropagatesUnchanged(): void
    {
        $throwing = new class implements RedisCommandExecutorInterface {
            /** @param non-empty-list<int|string|float> $command */
            public function execute(array $command): mixed
            {
                throw new \RuntimeException('backend unavailable');
            }
        };
        $throwingStore = new RedisFullCapabilityStore($throwing, 'health-throwing-stateful');

        $this->expectException(\RuntimeException::class);
        $throwingStore->increment('stateful', 60);
    }

    private function executorWithSocket(mixed $socket): RespRedisCommandExecutor
    {
        $reflection = new \ReflectionClass(RespRedisCommandExecutor::class);
        /** @var RespRedisCommandExecutor $executor */
        $executor = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('socket')->setValue($executor, $socket);
        return $executor;
    }

    /**
     * G12-R03-C: Redis validates a relative expiry in milliseconds
     * (`ttl * 1000 + command-time ms <= 9223372036854775807`). The whole-second
     * sum `ttl + nowSeconds <= 9223372036854775` is a strictly weaker check: at
     * `ttl + nowSeconds == 9223372036854775` it accepts while Redis rejects
     * whenever the current sub-second component exceeds 807 ms. Each family is
     * driven inside that 807-999 ms window with exactly that TTL: a seconds-only
     * preflight would pass it through to a real Redis expiry error after the
     * first write, so the assertions fail unless the preflight is
     * millisecond-aware and rejects before any mutation. `SET ... EX` is
     * covered by the same preflight: real Redis only rejects `ttl * 1000`
     * overflow there and silently wraps the absolute deadline, dropping the
     * key, so the preflight is the only thing that prevents an untracked
     * write.
     */
    public function testRelativeExpiryPreflightIsMillisecondAccurateAtTheRealRedisBoundary(): void
    {
        $operations = [
            'score-increment' => function (int $ttl): void {
                $this->store->increment('g12-ms-score-increment', $ttl, 1);
            },
            'score-set' => function (int $ttl): void {
                $this->store->set('g12-ms-score-set', 5, $ttl);
            },
            'block' => function (int $ttl): void {
                $this->store->block('g12-ms-block', 2, $ttl);
            },
            'distinct' => function (int $ttl): void {
                $this->store->addDistinct('g12-ms-distinct', 'member', $ttl);
            },
            'watch' => function (int $ttl): void {
                $this->store->incrementWatchFlag('g12-ms-watch', $ttl);
            },
            'bounded' => function (int $ttl): void {
                $this->store->addDistinctBoundedWithSnapshot('g12-ms-bounded', 'member', $ttl, 5);
            },
            'rotated' => function (int $ttl): void {
                $this->store->addDistinctBoundedWithSnapshotAcrossRotation('g12-ms-rotated-current', 'g12-ms-rotated-bridge', 'g12-ms-rotated-previous', 'member', 'other', $ttl, 5);
            },
            'watch-rotated' => function (int $ttl): void {
                $this->store->incrementWatchFlagAcrossRotation('g12-ms-watch-rotated-current', 'g12-ms-watch-rotated-previous', $ttl);
            },
            'lease' => function (int $ttl): void {
                $this->store->acquireProbeLease('g12-ms-lease', $this->redisNow(), $ttl);
            },
        ];

        foreach ($operations as $family => $operation) {
            $this->raw(['FLUSHDB']);
            $rejected = false;
            for ($attempt = 0; $attempt < 5 && ! $rejected; ++$attempt) {
                $seconds = $this->waitForSubsecondWindow(870_000, 930_000);
                $ttl = 9_223_372_036_854_775 - $seconds;
                $this->assertOperationFails(function () use ($operation, $ttl): void {
                    $operation($ttl);
                });
                self::assertSame(0, $this->integer($this->raw(['DBSIZE'])), $family . ' left residue after rejection');
                $this->raw(['SET', 'g12-ms-probe', 'x']);
                try {
                    $this->raw(['EXPIRE', 'g12-ms-probe', $ttl]);
                } catch (\Throwable) {
                    // Real Redis' EXPIRE rejects this exact relative expiry in this window.
                    $rejected = $this->redisNow() === $seconds;
                }
                $this->raw(['DEL', 'g12-ms-probe']);
            }
            self::assertTrue($rejected, $family . ' boundary window was not reached deterministically');
            self::assertSame(0, $this->integer($this->raw(['DBSIZE'])));

            // Three whole seconds below the boundary is representable in
            // milliseconds regardless of the sub-second component.
            $operation(9_223_372_036_854_775 - $this->redisNow() - 3);
            self::assertGreaterThan(0, $this->integer($this->raw(['DBSIZE'])), $family . ' rejected a representable TTL');
        }
    }

    /**
     * R01 / R03-C: a live Previous whose physical lifetime is in the widened
     * (> 2^53 ms) domain still rotates exactly when at least one bridge bound
     * is exactly representable, and fails before any Current/Bridge mutation
     * when none is. Previous stays read-only in every case.
     */
    public function testWidenedTtlLivePreviousRotationIsExactOrFailsBeforeMutation(): void
    {
        $previous = $this->key('distinct', 'g12-r01-prev');
        $previousMeta = $this->key('distinct-meta', 'g12-r01-prev');
        $widenedSeconds = 9_000_000_000_000_000;
        $this->raw(['SADD', $previous, 'old']);
        $this->raw(['PEXPIRE', $previous, '9000000000000000000']);
        $this->raw(['HSET', $previousMeta, 'expiresAt', (string) ($this->redisNow() + $widenedSeconds)]);
        $this->raw(['PEXPIRE', $previousMeta, '9000000000000000000']);
        self::assertGreaterThan(9_007_199_254_740_991, $this->integer($this->raw(['PTTL', $previous])));
        $beforeState = $this->stateFingerprint();
        $beforePttl = [$this->integer($this->raw(['PTTL', $previous])), $this->integer($this->raw(['PTTL', $previousMeta]))];

        // Every bridge bound is above the Lua exact range: nothing can be
        // bounded exactly, so the rotation fails before writing anything.
        $this->assertOperationFails(fn(): mixed => $this->store->addDistinctBoundedWithSnapshotAcrossRotation('g12-r01-cur', 'g12-r01-bridge', 'g12-r01-prev', 'new', 'unknown', $widenedSeconds, 10));
        self::assertSame($beforeState, $this->stateFingerprint());
        self::assertLessThanOrEqual($beforePttl[0], $this->integer($this->raw(['PTTL', $previous])));
        self::assertLessThanOrEqual($beforePttl[1], $this->integer($this->raw(['PTTL', $previousMeta])));

        // An ordinary requested TTL is exact, so the bridge is bounded by it.
        $snapshot = $this->store->addDistinctBoundedWithSnapshotAcrossRotation('g12-r01-cur', 'g12-r01-bridge', 'g12-r01-prev', 'new', 'unknown', 60, 10);
        self::assertTrue($snapshot->accepted);
        $bridgePttl = $this->integer($this->raw(['PTTL', $this->key('distinct', 'g12-r01-bridge')]));
        $bridgeMetaPttl = $this->integer($this->raw(['PTTL', $this->key('distinct-meta', 'g12-r01-bridge')]));
        foreach ([$bridgePttl, $bridgeMetaPttl] as $pttl) {
            self::assertGreaterThan(0, $pttl);
            self::assertLessThanOrEqual(60_000, $pttl);
        }
        self::assertSame($beforeState[$previous], $this->stateFingerprint()[$previous]);
        self::assertSame($beforeState[$previousMeta], $this->stateFingerprint()[$previousMeta]);
        self::assertLessThanOrEqual($beforePttl[0], $this->integer($this->raw(['PTTL', $previous])));
        self::assertLessThanOrEqual($beforePttl[1], $this->integer($this->raw(['PTTL', $previousMeta])));
    }

    public function testWidenedTtlRequestWithExactPreviousBoundsProducesExactBridge(): void
    {
        $previous = $this->key('distinct', 'g12-r01b-prev');
        $previousMeta = $this->key('distinct-meta', 'g12-r01b-prev');
        $this->raw(['SADD', $previous, 'old']);
        $this->raw(['PEXPIRE', $previous, '6000']);
        $expiry = $this->redisNow() + 30;
        $this->raw(['HSET', $previousMeta, 'expiresAt', (string) $expiry]);
        $this->raw(['PEXPIRE', $previousMeta, '9000']);
        $beforePreviousPttl = $this->integer($this->raw(['PTTL', $previous]));
        $beforeMetaPttl = $this->integer($this->raw(['PTTL', $previousMeta]));
        $authoritativeRemainingMs = ($expiry * 1000) - $this->redisNowMilliseconds();

        $this->store->addDistinctBoundedWithSnapshotAcrossRotation('g12-r01b-cur', 'g12-r01b-bridge', 'g12-r01b-prev', 'new', 'unknown', 9_000_000_000_000_000, 10);

        foreach ([$this->key('distinct', 'g12-r01b-bridge'), $this->key('distinct-meta', 'g12-r01b-bridge')] as $bridgeKey) {
            $pttl = $this->integer($this->raw(['PTTL', $bridgeKey]));
            self::assertGreaterThan(0, $pttl);
            self::assertLessThanOrEqual($beforePreviousPttl, $pttl);
            self::assertLessThanOrEqual($beforeMetaPttl, $pttl);
            self::assertLessThanOrEqual($authoritativeRemainingMs, $pttl);
        }
        self::assertLessThanOrEqual($beforePreviousPttl, $this->integer($this->raw(['PTTL', $previous])));
        self::assertLessThanOrEqual($beforeMetaPttl, $this->integer($this->raw(['PTTL', $previousMeta])));
    }

    /**
     * DEC-017: persisted cycle/pause timestamps and hard-block `expiresAt` are
     * non-negative. A negative value is malformed state: every publication and
     * read fails before any write and Previous stays read-only.
     */
    public function testNegativePersistedHistoryFailsPublicationAndReadBeforeAnyWrite(): void
    {
        $cases = [
            'current-cycle' => ['cycle', false, '-5', '-5'],
            'previous-cycle' => ['cycle', true, '-5', '-5'],
            'current-pause' => ['pause', false, '-10:-5', '-10'],
            'previous-pause' => ['pause', true, '-10:-5', '-10'],
            'current-block' => ['block', false, '-5', ''],
            'previous-block' => ['block', true, '-5', ''],
        ];

        foreach (['cycle-tracking', 'lifecycle'] as $route) {
            foreach ($cases as $name => [$family, $onPrevious, $member, $score]) {
                $this->raw(['FLUSHDB']);
                $now = $this->redisNow();
                $current = 'g12-neg-' . $route . '-' . $name . '-cur';
                $previousLogical = 'g12-neg-' . $route . '-' . $name . '-prev';
                if ($route === 'lifecycle') {
                    $scoreKey = $this->key('score', $current);
                    $this->raw(['HSET', $scoreKey, 'value', '8', 'updatedAt', (string) $now, 'generation', '1', 'expiresAt', (string) ($now + 600)]);
                    $this->raw(['EXPIRE', $scoreKey, '600']);
                }
                $target = $this->key($family, $onPrevious ? $previousLogical : $current);
                if ($family === 'block') {
                    $this->raw(['HSET', $target, 'level', '2', 'expiresAt', $member]);
                } else {
                    $this->raw(['ZADD', $target, $score, $member]);
                }
                $this->raw(['EXPIRE', $target, '600']);
                $beforeState = $this->stateFingerprint();
                $beforePttl = $this->integer($this->raw(['PTTL', $target]));

                if ($route === 'cycle-tracking') {
                    $this->assertOperationFails(fn(): mixed => $this->store->blockWithCycleTracking($current, $previousLogical, 2, 60, $now, 600, 2, 60, 120));
                } else {
                    $this->assertOperationFails(fn(): mixed => $this->store->blockWithPunishmentLifecycleTracking($current, $previousLogical, 1, str_repeat('c', 32), 2, 60, 600, 3, 60, 120));
                }
                self::assertSame($beforeState, $this->stateFingerprint(), $route . '/' . $name . ' mutated state');
                self::assertLessThanOrEqual($beforePttl, $this->integer($this->raw(['PTTL', $target])));

                if ($family === 'pause') {
                    $this->assertOperationFails(fn(): mixed => $this->store->readDecayPauseState($current, $previousLogical, 0, $now));
                    self::assertSame($beforeState, $this->stateFingerprint());
                }
            }
        }
    }

    public function testScoreUpdatedAtMustBeCanonicalNonNegativePhpIntAndIsNeverSilentlyRepaired(): void
    {
        foreach (['-1', '0001', '9223372036854775808', '99999999999999999999999', '1.5', ''] as $index => $updatedAt) {
            $logical = 'g12-score-updated-at-' . $index;
            $key = $this->key('score', $logical);
            $this->raw(['HSET', $key, 'value', '7', 'updatedAt', $updatedAt]);
            $this->raw(['EXPIRE', $key, '600']);
            $before = $this->hashMap($key);
            $pttl = $this->integer($this->raw(['PTTL', $key]));

            $this->assertOperationFails(fn(): mixed => $this->store->increment($logical, 60, 1));
            $this->assertOperationFails(fn(): mixed => $this->store->get($logical));

            self::assertSame($before, $this->hashMap($key));
            self::assertLessThanOrEqual($pttl, $this->integer($this->raw(['PTTL', $key])));
        }

        $key = $this->key('score', 'g12-score-updated-at-valid');
        $this->raw(['HSET', $key, 'value', '7', 'updatedAt', '0']);
        $this->raw(['EXPIRE', $key, '600']);
        self::assertSame(0, $this->store->get('g12-score-updated-at-valid')?->updatedAt);
        self::assertSame(8, $this->store->increment('g12-score-updated-at-valid', 60, 1));
    }

    public function testBudgetEpochStartMustBeCanonicalNonNegativeAndFailsBeforeAnyMutation(): void
    {
        foreach (['-1', '0001', '1.5', '1e3', ' 5', '9223372036854775808', ''] as $index => $epochStart) {
            $logical = 'g12-budget-malformed-' . $index;
            $key = $this->key('budget', $logical);
            $this->raw(['HSET', $key, 'count', '5', 'epochStart', $epochStart, 'epochDuration', '60']);
            $this->raw(['EXPIRE', $key, '600']);
            $before = $this->hashMap($key);
            $pttl = $this->integer($this->raw(['PTTL', $key]));

            $this->assertOperationFails(fn(): mixed => $this->store->incrementBudget($logical, 60, 1));
            $this->assertOperationFails(fn(): mixed => $this->store->getBudget($logical));
            $this->assertOperationFails(fn(): mixed => $this->store->incrementBudgetWithSeed($logical, 60, new BudgetStateDTO(1, $this->redisNow()), 1));

            self::assertSame($before, $this->hashMap($key), 'epochStart ' . $epochStart . ' was repaired or mutated');
            self::assertLessThanOrEqual($pttl, $this->integer($this->raw(['PTTL', $key])));
        }
    }

    public function testBudgetEpochStartAboveLuaExactRangeButInsidePhpIntIsHandledExactly(): void
    {
        foreach (['9007199254740993', '9223372036854775807'] as $index => $epochStart) {
            $logical = 'g12-budget-above-exact-' . $index;
            $key = $this->key('budget', $logical);
            $this->raw(['HSET', $key, 'count', '5', 'epochStart', $epochStart, 'epochDuration', '60']);
            $this->raw(['EXPIRE', $key, '600']);

            $state = $this->store->getBudget($logical);
            self::assertNotNull($state);
            self::assertSame((int) $epochStart, $state->epochStart);
            $incremented = $this->store->incrementBudget($logical, 60, 2);
            self::assertSame(7, $incremented->count);
            self::assertSame((int) $epochStart, $incremented->epochStart);
            $seeded = $this->store->incrementBudgetWithSeed($logical, 60, new BudgetStateDTO(99, 1), 1);
            self::assertSame(8, $seeded->count);
            self::assertSame((int) $epochStart, $seeded->epochStart);
            self::assertSame($epochStart, $this->raw(['HGET', $key, 'epochStart']));
        }
    }

    public function testBudgetSeedEpochStartIsValidatedOnlyWhenTheSeedIsUsed(): void
    {
        // A relevant negative seed is rejected and nothing is created.
        $this->assertOperationFails(fn(): mixed => $this->store->incrementBudgetWithSeed('g12-budget-seed-negative', 60, new BudgetStateDTO(3, -1), 1));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('budget', 'g12-budget-seed-negative')])));

        // A relevant seed whose expiry Redis cannot represent fails before mutation.
        $this->assertOperationFails(fn(): mixed => $this->store->incrementBudgetWithSeed('g12-budget-seed-too-large', 60, new BudgetStateDTO(3, PHP_INT_MAX), 1));
        self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('budget', 'g12-budget-seed-too-large')])));

        // An expired Current is not replaced when the relevant seed is malformed.
        $key = $this->key('budget', 'g12-budget-seed-expired-current');
        $this->raw(['HSET', $key, 'count', '7', 'epochStart', (string) ($this->redisNow() - 2), 'epochDuration', '1']);
        $this->raw(['EXPIRE', $key, '60']);
        $before = $this->hashMap($key);
        $this->assertOperationFails(fn(): mixed => $this->store->incrementBudgetWithSeed('g12-budget-seed-expired-current', 60, new BudgetStateDTO(3, -1), 1));
        self::assertSame($before, $this->hashMap($key));

        // A seed above the Lua exact range but inside PHP int64 is preserved exactly.
        $seeded = $this->store->incrementBudgetWithSeed('g12-budget-seed-above-exact', 60, new BudgetStateDTO(3, 9_007_199_254_740_993), 1);
        self::assertSame(4, $seeded->count);
        self::assertSame(9_007_199_254_740_993, $seeded->epochStart);
        self::assertSame('9007199254740993', $this->raw(['HGET', $this->key('budget', 'g12-budget-seed-above-exact'), 'epochStart']));
        self::assertGreaterThan(0, $this->integer($this->raw(['PTTL', $this->key('budget', 'g12-budget-seed-above-exact')])));
    }

    public function testCircuitBreakerRejectsNegativeTimestampsOnLoadAndSaveWithoutBackendFailure(): void
    {
        $valid = ['status' => 'OPEN', 'failures' => [10, 20], 'reEntries' => [30], 'lastFailure' => 20, 'openSince' => 20, 'lastSuccess' => 0, 'failClosedUntil' => 0];
        $negatives = [
            'failures' => ['failures' => [10, -20]],
            'reEntries' => ['reEntries' => [-30]],
            'lastFailure' => ['lastFailure' => -1],
            'openSince' => ['openSince' => -1],
            'lastSuccess' => ['lastSuccess' => -1],
            'failClosedUntil' => ['failClosedUntil' => -1],
        ];

        foreach ($negatives as $field => $override) {
            $policy = 'g12-circuit-negative-' . $field;
            $this->raw(['SET', $this->key('circuit', $policy), json_encode(array_merge($valid, $override), JSON_THROW_ON_ERROR)]);
            $this->assertOperationFails(fn(): mixed => $this->store->load($policy));

            $saved = 'g12-circuit-save-' . $field;
            $good = new CircuitBreakerStateDTO('OPEN', [10], 10, 10, 0, [20], 0);
            $this->store->save($saved, $good);
            $before = $this->raw(['GET', $this->key('circuit', $saved)]);
            $bad = new CircuitBreakerStateDTO(
                'OPEN',
                $override['failures'] ?? [10],
                $override['lastFailure'] ?? 10,
                $override['openSince'] ?? 10,
                $override['lastSuccess'] ?? 0,
                $override['reEntries'] ?? [20],
                $override['failClosedUntil'] ?? 0,
            );
            $this->assertOperationFails(function () use ($saved, $bad): void {
                $this->store->save($saved, $bad);
            });
            self::assertSame($before, $this->raw(['GET', $this->key('circuit', $saved)]));

            $missing = 'g12-circuit-save-missing-' . $field;
            $this->assertOperationFails(function () use ($missing, $bad): void {
                $this->store->save($missing, $bad);
            });
            self::assertSame(0, $this->integer($this->raw(['EXISTS', $this->key('circuit', $missing)])));
        }

        $zero = new CircuitBreakerStateDTO('CLOSED', [0], 0, 0, 0, [0], 0);
        $this->store->save('g12-circuit-zero', $zero);
        self::assertSame($zero->jsonSerialize(), $this->store->load('g12-circuit-zero')?->jsonSerialize());
    }

    /** @return array<string, string> */
    private function stateFingerprint(): array
    {
        $keys = $this->raw(['KEYS', '*']);
        self::assertIsArray($keys);
        $fingerprint = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $dump = $this->raw(['DUMP', $key]);
            self::assertIsString($dump);
            $fingerprint[$key] = bin2hex($dump);
        }
        ksort($fingerprint);

        return $fingerprint;
    }

    /** Block until Redis TIME's sub-second component lies in the window; return its whole seconds. */
    private function waitForSubsecondWindow(int $fromMicroseconds, int $toMicroseconds): int
    {
        for ($i = 0; $i < 2_000; ++$i) {
            $time = $this->raw(['TIME']);
            self::assertIsArray($time);
            $microseconds = $this->integer($time[1] ?? 0);
            if ($microseconds >= $fromMicroseconds && $microseconds <= $toMicroseconds) {
                return $this->integer($time[0] ?? 0);
            }
            usleep(2_000);
        }
        self::fail('Redis sub-second window was not reached.');
    }

    private function hardBlock(string $currentKey, int $now, int $duration, ?string $previousKey = null): HardBlockCycleResultDTO
    {
        return $this->store->blockWithCycleTracking(
            $currentKey,
            $previousKey,
            2,
            $duration,
            $now,
            21600,
            2,
            600,
            86400,
        );
    }

    private function deviceContext(string $accountId, int $index): RateLimitContextDTO
    {
        return new RateLimitContextDTO(
            '198.51.100.21',
            'Mozilla/5.0 Chrome/' . $index,
            $accountId,
            ['device' => 'device-' . $index],
        );
    }

    /** @param non-empty-list<int|string|float> $command */
    private function raw(array $command): mixed
    {
        return $this->executor->execute($command);
    }

    private function redisTime(): int
    {
        $time = $this->raw(['TIME']);

        self::assertIsArray($time);

        $seconds = $time[0] ?? 0;

        return is_int($seconds) || is_string($seconds) ? (int) $seconds : 0;
    }

    private function redisNow(): int
    {
        $time = $this->raw(['TIME']);
        self::assertIsArray($time);
        self::assertArrayHasKey(0, $time);

        return $this->integer($time[0]);
    }

    private function redisNowMilliseconds(): int
    {
        $time = $this->raw(['TIME']);
        self::assertIsArray($time);
        self::assertArrayHasKey(0, $time);
        self::assertArrayHasKey(1, $time);

        return $this->integer($time[0]) * 1000 + intdiv($this->integer($time[1]), 1000);
    }

    /**
     * @return list<string>
     */
    private function redisKeys(string $family): array
    {
        $keys = $this->raw(['KEYS', $this->familyPrefix($family) . ':*']);
        self::assertIsArray($keys);
        $result = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $result[] = $key;
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function hashMap(string $key): array
    {
        $raw = $this->raw(['HGETALL', $key]);
        self::assertIsArray($raw);
        $values = array_values($raw);
        self::assertSame(0, count($values) % 2);
        $result = [];
        for ($index = 0; $index < count($values); $index += 2) {
            self::assertIsString($values[$index]);
            self::assertIsString($values[$index + 1]);
            $result[$values[$index]] = $values[$index + 1];
        }

        return $result;
    }

    /**
     * @param callable(int): void $worker
     */
    private function runConcurrentWorkers(int $workerCount, callable $worker): void
    {
        $pids = [];
        for ($index = 0; $index < $workerCount; $index++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                self::fail('Unable to fork Redis concurrency worker.');
            }
            if ($pid === 0) {
                try {
                    $worker($index);
                    exit(0);
                } catch (\Throwable) {
                    exit(1);
                }
            }
            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            $status = 0;
            self::assertSame($pid, pcntl_waitpid($pid, $status));
            if (! is_int($status)) {
                self::fail('Redis concurrency worker status was malformed.');
            }
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
    }

    private function workerStore(): RedisFullCapabilityStore
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $portValue = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $portValue === false) {
            throw new \RuntimeException('Redis integration environment is missing.');
        }

        return new RedisFullCapabilityStore(
            new RespRedisCommandExecutor($host, (int) $portValue),
            $this->namespace,
        );
    }

    /**
     * @return list<string>
     */
    private function rawMembers(string $logicalKey): array
    {
        $members = $this->raw(['SMEMBERS', $this->key('distinct', $logicalKey)]);
        self::assertIsArray($members);
        /** @var list<mixed> $members */
        $members = array_values($members);
        $result = [];
        foreach ($members as $member) {
            self::assertIsString($member);
            $result[] = $member;
        }

        return $result;
    }

    private function key(string $family, string $logical): string
    {
        return $this->familyPrefix($family) . ':' . hash('sha256', $logical);
    }

    private function familyPrefix(string $family): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', $this->namespace) . ':' . $family;
    }

    private function integer(mixed $value): int
    {
        self::assertTrue(is_int($value) || is_string($value) || is_float($value));

        return (int) $value;
    }

    private function assertOperationFails(callable $operation): void
    {
        try {
            $operation();
        } catch (BackendFailureException $exception) {
            self::fail('Malformed-state assertion received BackendFailureException: ' . $exception->getMessage());
        } catch (RateLimiterException) {
            self::addToAssertionCount(1);
            return;
        } catch (\Throwable) {
            self::fail('Malformed-state assertion received an unexpected exception type.');
        }

        self::fail('Malformed persisted Redis state was accepted.');
    }
}
