<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\BlockRace\BlockRaceSetup;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-185-16 (#185, LT-185-16, the Lead's adv6/r1): a block write never downgrades a live block. On `e513638` `BLOCK_SET`
 * and `HARD_BLOCK` Phase C wrote `level` / `expiresAt` unconditionally: request A, paused before its L1 block write while
 * request B published L2 (60 s), then replaced it with L1 (15 s); 20 s later the account was processed again (K5 12 to
 * 14) and a second DEC-003 cycle was counted. Now the live stronger block stays and each request keeps its own computed
 * decision (DEC-029 P3): the race ends exactly as the two requests do one after the other.
 */
#[Group('f-185-16')]
final class BlockNoDowngradeRedisTest extends TestCase
{
    private const ROUNDS = 50;

    private const NOW = 1_793_793_600;

    private string $host;

    private string $port;

    private RespRedisCommandExecutor $executor;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->host = $host;
        $this->port = $port;
        $this->executor = new RespRedisCommandExecutor($host, (int) $port);
    }

    /** @return array{score: string, block: string, cycle: string} K5 = 8 before the race. */
    private function seed(): array
    {
        $this->executor->execute(['FLUSHDB']);
        $increment = (new \ReflectionClass(RedisFullCapabilityStore::class))->getConstant('SCORE_INCREMENT');
        self::assertIsString($increment);
        $recorder = new class ($this->executor, $increment) implements RedisCommandExecutorInterface {
            public ?string $k5 = null;

            public function __construct(private RedisCommandExecutorInterface $inner, private string $increment) {}

            /** @param non-empty-list<int|string|float> $command */
            public function execute(array $command): mixed
            {
                if ($command[0] === 'EVAL' && ($command[1] ?? null) === $this->increment && (string) ($command[5] ?? '') === '2' && is_string($command[3] ?? null)) {
                    $this->k5 = $command[3];
                }

                return $this->inner->execute($command);
            }
        };
        BlockRaceSetup::fail($recorder);
        $score = $recorder->k5;
        self::assertNotNull($score, 'K5 (+2) is written by the seeding failure.');
        $this->executor->execute(['HSET', $score, 'value', '8']);

        return ['score' => $score, 'block' => str_replace(':score:', ':block:', $score), 'cycle' => str_replace(':score:', ':cycle:', $score)];
    }

    /**
     * @param array{score: string, block: string, cycle: string} $keys
     *
     * @return array{block: mixed, k5: mixed, cycles: mixed, later: RateLimitResultDTO}
     */
    private function outcome(array $keys): array
    {
        $state = ['block' => $this->executor->execute(['HGETALL', $keys['block']]), 'k5' => $this->executor->execute(['HGET', $keys['score'], 'value'])];
        $later = BlockRaceSetup::fail($this->executor, '2026-11-04 12:00:20');

        return [...$state, 'cycles' => $this->executor->execute(['ZRANGE', $keys['cycle'], '0', '-1']), 'later' => $later];
    }

    /** @param array{block: mixed, k5: mixed, cycles: mixed, later: RateLimitResultDTO} $outcome */
    private static function assertTheSequentialResult(array $outcome, string $round): void
    {
        self::assertSame(['level', '2', 'expiresAt', (string) (self::NOW + 60)], $outcome['block'], $round . ': the L2 block stays until +60.');
        self::assertSame('12', $outcome['k5'], $round);
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $outcome['later']->decision, $round . ': at +20 s the request is refused.');
        self::assertSame(40, $outcome['later']->retryAfter, $round);
        self::assertSame([(string) self::NOW], $outcome['cycles'], $round . ': one DEC-003 cycle.');
    }

    public function testALateWeakerBlockWriteDoesNotDowngradeTheLiveBlock(): void
    {
        $worker = dirname(__DIR__, 2) . '/Support/BlockRace/block-race-worker.php';
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $keys = $this->seed();
            $flag = 'f185-16:flag:' . $round;
            $command = implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, '-d', 'xdebug.mode=off', $worker, $this->host, $this->port, $keys['block'], $flag])) . ' 2>&1';
            $handle = popen($command, 'r');
            self::assertNotFalse($handle);
            $deadline = microtime(true) + 15;
            while ($this->executor->execute(['GET', $flag . ':a-at-block']) === null) {
                self::assertLessThan($deadline, microtime(true), 'Request A never reached its block write.');
                usleep(500);
            }

            $b = BlockRaceSetup::fail($this->executor);
            $this->executor->execute(['SET', $flag . ':b-done', '1']);
            $a = json_decode(trim((string) stream_get_contents($handle)), true);
            self::assertSame(0, pclose($handle));
            $this->executor->execute(['DEL', $flag . ':a-at-block', $flag . ':b-done']);

            self::assertSame(['decision' => 'SOFT_BLOCK', 'blockLevel' => 1, 'retryAfter' => 900], $a, 'Round ' . $round . ': A keeps its computed decision.');
            self::assertSame([RateLimitResultDTO::DECISION_HARD_BLOCK, 2], [$b->decision, $b->blockLevel]);
            self::assertTheSequentialResult($this->outcome($keys), 'Round ' . $round);
        }
    }

    public function testControlTheTwoFailuresOneAfterTheOther(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $keys = $this->seed();
            $a = BlockRaceSetup::fail($this->executor);
            $b = BlockRaceSetup::fail($this->executor);

            self::assertSame([RateLimitResultDTO::DECISION_SOFT_BLOCK, 1, 900], [$a->decision, $a->blockLevel, $a->retryAfter]);
            self::assertSame([RateLimitResultDTO::DECISION_HARD_BLOCK, 2], [$b->decision, $b->blockLevel]);
            self::assertTheSequentialResult($this->outcome($keys), 'Round ' . $round);
        }
    }

    private function storeKey(string $family, string $logical): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', 'f185-16-store') . ':' . $family . ':' . hash('sha256', $logical);
    }

    public function testHardBlockDoesNotReplaceALiveL3WithAnL2(): void
    {
        $this->executor->execute(['FLUSHDB']);
        $store = new RedisFullCapabilityStore($this->executor, 'f185-16-store');

        $store->blockWithCycleTracking('k', null, 3, 300, self::NOW, 600, 3, 300, 86400);
        $l2 = $store->blockWithCycleTracking('k', null, 2, 60, self::NOW + 5, 600, 3, 300, 86400);

        self::assertFalse($l2->newCycle, 'The live L3 block is the same cycle.');
        self::assertSame(['level', '3', 'expiresAt', (string) (self::NOW + 300)], $this->executor->execute(['HGETALL', $this->storeKey('block', 'k')]));
        self::assertSame(3, $store->checkBlockAt('k', self::NOW + 5)?->level);
    }

    public function testTheSameLevelExtendsAndNeverShortens(): void
    {
        $this->executor->execute(['FLUSHDB']);
        $store = new RedisFullCapabilityStore($this->executor, 'f185-16-store');

        $store->blockAt('k', 2, 600, self::NOW);
        $store->blockAt('k', 2, 60, self::NOW + 10);
        self::assertSame(self::NOW + 600, $store->checkBlockAt('k', self::NOW + 10)?->expiresAt, 'A shorter block of the same level does not shorten it.');

        $store->blockAt('k', 2, 900, self::NOW + 10);
        self::assertSame(self::NOW + 910, $store->checkBlockAt('k', self::NOW + 10)?->expiresAt, 'A longer block of the same level extends it.');

        $store->blockAt('k', 3, 60, self::NOW + 10);
        self::assertSame(3, $store->checkBlockAt('k', self::NOW + 10)?->level, 'A higher level always replaces it.');
    }

    public function testALogicallyExpiredBlockIsReplaced(): void
    {
        $this->executor->execute(['FLUSHDB']);
        $store = new RedisFullCapabilityStore($this->executor, 'f185-16-store');

        $store->blockAt('k', 3, 600, self::NOW);
        $store->blockAt('k', 1, 15, self::NOW + 601);

        self::assertSame(1, $store->checkBlockAt('k', self::NOW + 601)?->level);
    }

    public function testAMalformedExistingBlockIsAnErrorNotOverwritten(): void
    {
        $this->executor->execute(['FLUSHDB']);
        $store = new RedisFullCapabilityStore($this->executor, 'f185-16-store');
        $store->blockAt('k', 2, 600, self::NOW);
        $this->executor->execute(['HSET', $this->storeKey('block', 'k'), 'level', '02']);
        $before = $this->executor->execute(['DUMP', $this->storeKey('block', 'k')]);

        try {
            $store->blockAt('k', 1, 15, self::NOW);
            self::fail('A malformed block was overwritten.');
        } catch (BackendFailureException $exception) {
            self::fail('Malformed state raised BackendFailureException: ' . $exception->getMessage());
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('malformed block state', $exception->getMessage());
        }
        self::assertSame($before, $this->executor->execute(['DUMP', $this->storeKey('block', 'k')]));
    }
}
