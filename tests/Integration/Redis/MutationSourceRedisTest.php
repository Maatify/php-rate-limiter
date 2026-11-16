<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\RateLimiter\Tests\Support\Redis\StateSnapshot;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-185-09 (#185): the generation-bound mutation chooses its source as the read does (LT-P2-02, LT-185-09). A source is
 * live only while its PTTL is positive and its logical `expiresAt` is ahead of the caller's time; Previous is consulted
 * only when Current is physically absent. A Current that is logically expired but physically present is absent: the
 * mutation writes generation 1 over it, drops its lifecycle evidence and claim marker, and never looks at Previous. On
 * `4e90064` the mutation took such a Current as its source by `PTTL > 0` alone, so `read = null` was followed by three
 * unapplied mutations and, from the public API, `HARD_BLOCK` with cause `k4_concurrency_conflict`.
 */
#[Group('f-185-09')]
#[Group('php32')]
final class MutationSourceRedisTest extends TestCase
{
    private const NOW = 1_800_000_000;

    /** The same instant as the decimal epoch milliseconds the DEC-039 capability takes; `NOW * 1000` is a float on a 32-bit build (#240). */
    private const NOW_MS = self::NOW . '000';

    private const NAMESPACE = 'f185-09';

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

    /** A generated score, physically alive for 600 s, whose logical expiry is `$expiresAt`. */
    private function seed(string $logical, int $generation, int $value, int $expiresAt, bool $evidence = false): void
    {
        $score = $this->key('score', $logical);
        $fields = ['value', (string) $value, 'updatedAt', (string) ($expiresAt - 300), 'generation', (string) $generation, 'expiresAt', (string) $expiresAt];
        if ($evidence) {
            array_push($fields, 'reentryId', str_repeat('c', 32), 'reentryValidUntil', (string) $expiresAt, 'reentryGeneration', (string) $generation);
        }
        $this->executor->execute(['HSET', $score, ...$fields]);
        $this->executor->execute(['EXPIRE', $score, '600']);
    }

    /** @return array<string, string> */
    private function hash(string $logical): array
    {
        $flat = $this->executor->execute(['HGETALL', $this->key('score', $logical)]);
        self::assertIsArray($flat);
        $out = [];
        for ($i = 0; $i + 1 < count($flat); $i += 2) {
            self::assertIsString($flat[$i]);
            self::assertIsString($flat[$i + 1]);
            $out[$flat[$i]] = $flat[$i + 1];
        }
        ksort($out);

        return $out;
    }

    /** One score key's byte-exact `DUMP` and exact `PEXPIRETIME` (F241-01: DUMP alone misses an expiry-only write). */
    private function dump(string $logical): string
    {
        return StateSnapshot::of($this->executor, $this->key('score', $logical));
    }

    /** @return array<string, string> every key's byte-exact `DUMP` and exact `PEXPIRETIME`. */
    private function allState(): array
    {
        return StateSnapshot::all($this->executor);
    }

    public function testALogicallyExpiredCurrentWithALiveTtlIsAbsentAndANewGenerationIsWrittenOverIt(): void
    {
        $this->seed('current', 3, 7, self::NOW - 10, evidence: true);
        $this->executor->execute(['SET', $this->key('reentry-claim', 'current'), str_repeat('c', 32), 'EX', '600']);

        self::assertNull($this->store->readGenerationBoundScoreStateAtMilliseconds('current', null, self::NOW_MS), 'read = null');
        $mutation = $this->store->mutateGenerationBoundScoreAtMilliseconds('current', null, null, 600, 2, self::NOW_MS);

        self::assertTrue($mutation->applied, 'mutate(expected = null) is applied.');
        self::assertNotNull($mutation->state);
        self::assertSame(1, $mutation->state->generation, 'The generation restarts at 1.');
        self::assertSame(2, $mutation->state->value);
        self::assertSame(
            ['expiresAt' => (string) (self::NOW + 600), 'generation' => '1', 'updatedAt' => (string) self::NOW, 'value' => '2'],
            $this->hash('current'),
            'The logically expired evidence is dropped; only the new generation remains.',
        );
        self::assertSame(0, $this->executor->execute(['EXISTS', $this->key('reentry-claim', 'current')]), 'The claim marker is dropped.');
        // A second store instance: the read after the write is a new observation, not the first read again.
        $state = (new RedisFullCapabilityStore($this->executor, self::NAMESPACE))->readGenerationBoundScoreStateAtMilliseconds('current', null, self::NOW_MS);
        self::assertNotNull($state);
        self::assertSame(GenerationBoundScoreStateDTO::SOURCE_CURRENT, $state->source);
        self::assertSame(1, $state->generation);
    }

    public function testALogicallyExpiredCurrentIsOverwrittenWithoutLookingAtALivePrevious(): void
    {
        $this->seed('current', 3, 7, self::NOW - 10);
        $this->seed('previous', 5, 9, self::NOW + 300);
        $previous = $this->dump('previous');

        self::assertNull($this->store->readGenerationBoundScoreStateAtMilliseconds('current', 'previous', self::NOW_MS), 'The read does not fall back to Previous.');
        $mutation = $this->store->mutateGenerationBoundScoreAtMilliseconds('current', 'previous', null, 600, 2, self::NOW_MS);

        self::assertTrue($mutation->applied);
        self::assertSame('1', $this->hash('current')['generation']);
        self::assertSame($previous, $this->dump('previous'), 'Previous is left untouched.');
    }

    public function testALogicallyExpiredPreviousIsAbsentWhenCurrentIsPhysicallyAbsent(): void
    {
        $this->seed('previous', 5, 9, self::NOW - 10);
        $previous = $this->dump('previous');

        self::assertNull($this->store->readGenerationBoundScoreStateAtMilliseconds('current', 'previous', self::NOW_MS));
        $mutation = $this->store->mutateGenerationBoundScoreAtMilliseconds('current', 'previous', null, 600, 2, self::NOW_MS);

        self::assertTrue($mutation->applied);
        self::assertSame('1', $this->hash('current')['generation']);
        self::assertSame($previous, $this->dump('previous'), 'Previous is left untouched.');
    }

    public function testMalformedEvidenceOnALogicallyExpiredCurrentStillFailsAndIsNotOverwritten(): void
    {
        $this->seed('current', 3, 7, self::NOW - 10);
        $this->executor->execute(['HSET', $this->key('score', 'current'), 'reentryId', str_repeat('c', 32)]);
        $before = $this->allState();

        try {
            $this->store->mutateGenerationBoundScoreAtMilliseconds('current', null, null, 600, 2, self::NOW_MS);
            self::fail('Partial lifecycle evidence on a logically expired Current was overwritten.');
        } catch (RateLimiterException $exception) {
            // A BackendFailureException is not caught: it propagates and fails the test, as the removed explicit branch did. The decimal-millisecond
            // interface documents only RateLimiterException as thrown, which is why a catch for the other type is dead code to the analyser.
            self::assertStringContainsString('malformed lifecycle evidence', $exception->getMessage());
        }
        self::assertSame($before, $this->allState(), 'Malformed state is not repaired (DEC-017): every key, its bytes and its exact expiry are unchanged.');
    }

    /** F241-01 detector: an expiry-only +1 ms move, with the bytes untouched, is visible to both snapshots this class uses, and to the all-key one on a control key. */
    public function testTheSnapshotsRejectAnExpiryOnlyOneMillisecondMove(): void
    {
        $this->seed('current', 3, 7, self::NOW + 300);
        $this->seed('previous', 5, 9, self::NOW + 300);
        $this->executor->execute(['SET', 'unrelated-control', 'x', 'EX', '600']);

        foreach (['current', 'previous'] as $moved) {
            $key = $this->key('score', $moved);
            $entry = $this->dump($moved);
            $all = $this->allState();

            StateSnapshot::perturbExpiryByOneMillisecond($this->executor, $key);

            self::assertNotSame($entry, $this->dump($moved), 'The per-key snapshot detects +1 ms on ' . $moved . '.');
            self::assertNotSame($all, $this->allState(), 'The all-key snapshot detects +1 ms on ' . $moved . '.');
        }
        $all = $this->allState();
        StateSnapshot::perturbExpiryByOneMillisecond($this->executor, 'unrelated-control');
        self::assertNotSame($all, $this->allState(), 'The all-key snapshot detects +1 ms on an unrelated control key.');
    }

    public function testControlALiveCurrentAndALivePreviousKeepTheirSources(): void
    {
        $this->seed('previous', 5, 9, self::NOW + 300);
        $fromPrevious = $this->store->readGenerationBoundScoreStateAtMilliseconds('current', 'previous', self::NOW_MS);
        self::assertNotNull($fromPrevious);
        self::assertSame(GenerationBoundScoreStateDTO::SOURCE_PREVIOUS, $fromPrevious->source);
        self::assertFalse($this->store->mutateGenerationBoundScoreAtMilliseconds('current', 'previous', null, 600, 2, self::NOW_MS)->applied, 'A live Previous is a source: expected = null is a conflict.');
        self::assertTrue($this->store->mutateGenerationBoundScoreAtMilliseconds('current', 'previous', $fromPrevious, 600, 2, self::NOW_MS)->applied);

        $this->executor->execute(['FLUSHDB']);
        $this->seed('current', 3, 7, self::NOW + 300);
        $current = $this->store->readGenerationBoundScoreStateAtMilliseconds('current', null, self::NOW_MS);
        self::assertNotNull($current);
        self::assertFalse($this->store->mutateGenerationBoundScoreAtMilliseconds('current', null, null, 600, 2, self::NOW_MS)->applied, 'A live Current is a source: expected = null is a conflict.');
        $mutation = $this->store->mutateGenerationBoundScoreAtMilliseconds('current', null, $current, 600, 2, self::NOW_MS);
        self::assertTrue($mutation->applied);
        self::assertSame(4, $mutation->state?->generation);
    }

    private function recordFailure(string $at): RateLimitResultDTO
    {
        $limiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('f185-09-key', 'f185-09-fingerprint', 'prod'),
            new RedisFullCapabilityStore($this->executor, self::NAMESPACE),
            new RecordingFailureSignalEmitter(),
        )->withClock(new FixedClock($at))->build();

        return $limiter->limit(new RateLimitContextDTO('198.51.100.50', 'Mozilla/5.0 Chrome/120.0', 'account-1'), RateLimitCommand::recordFailure('login_protection'));
    }

    /**
     * From the public API: one failed login writes K4 (generation-bound, 86400 s), then the caller's clock moves past the
     * logical expiry while the physical TTL, relative to Redis's own clock, is still alive (caller ahead), or stays
     * behind the write (caller behind). Neither is a concurrency conflict.
     */
    public function testRecordFailureNeverReturnsAConcurrencyConflictWithACallerClockAheadOrBehind(): void
    {
        $first = $this->recordFailure('2026-11-04 12:00:00');
        self::assertNotSame('k4_concurrency_conflict', $first->metadata?->cause);

        foreach (['ahead' => '2026-11-05 12:01:00', 'behind' => '2026-11-04 11:00:00'] as $direction => $at) {
            $result = $this->recordFailure($at);
            self::assertNotSame('k4_concurrency_conflict', $result->metadata?->cause, 'caller clock ' . $direction);
            self::assertNotSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision, 'caller clock ' . $direction . ': a second failure is not a hard block.');
        }
    }
}
