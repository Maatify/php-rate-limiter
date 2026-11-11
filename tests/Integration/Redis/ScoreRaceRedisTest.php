<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\RateLimiter\Tests\Support\Redis\OverlayWire;
use Maatify\RateLimiter\Tests\Support\ScoreRace\ScoreRaceSetup;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-185-08 (#185, DEC-030): a score write that carries a decay credit is fenced by its snapshot. K5 = 5 and K1 = 5, both
 * fully decayed (1500 s old); two failed logins of the same trusted session add +2 to K5 and +5 to K1. Run one after the
 * other, K5 = 4 and K1 = 10. With both processes held at a Redis barrier before their first score write, so both read the
 * same snapshot, `4e90064` sent the same credit twice (`netChange` −3 and 0): K5 = −1 and K1 = 5 in every round. The fenced
 * write lets one credit through; the other process misses, reads again, finds no credit and adds its delta.
 */
#[Group('f-185-08')]
final class ScoreRaceRedisTest extends TestCase
{
    private const ROUNDS = 50;

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

    /**
     * One failure 1500 s before the race writes K5 (+2) and K1 (+5); K5 is then set to 5, as in the reviewer's case.
     *
     * @return array{k5: string, k1: string} The two score keys.
     */
    private function seed(): array
    {
        $this->executor->execute(['FLUSHDB']);
        $increment = (new \ReflectionClass(RedisFullCapabilityStore::class))->getConstant('SCORE_INCREMENT');
        self::assertIsString($increment);
        $recorder = new class ($this->executor, $increment) implements RedisCommandExecutorInterface {
            public ?string $k5 = null;

            public ?string $k1 = null;

            public function __construct(private RedisCommandExecutorInterface $inner, private string $increment) {}

            /** @param non-empty-list<int|string|float> $command */
            public function execute(array $command): mixed
            {
                if ($command[0] === 'EVAL' && OverlayWire::isScript($command, $this->increment) && is_string($command[3] ?? null)) {
                    // SCORE_INCREMENT's ARGV: TTL, amount, caller time; K5 gains k5_failure (2), K1 k1_spray (5).
                    match ((string) ($command[5] ?? '')) {
                        '2' => $this->k5 = $command[3],
                        '5' => $this->k1 = $command[3],
                        default => null,
                    };
                }

                return $this->inner->execute($command);
            }
        };
        ScoreRaceSetup::fail(ScoreRaceSetup::limiter($recorder, ScoreRaceSetup::AGED));
        $k5 = $recorder->k5;
        $k1 = $recorder->k1;
        self::assertNotNull($k5, 'K5 (+2) is written by the seeding failure.');
        self::assertNotNull($k1, 'K1 (+5) is written by the seeding failure.');
        $this->executor->execute(['HSET', $k5, 'value', '5']);

        return ['k5' => $k5, 'k1' => $k1];
    }

    /** @return array{k5: string, k1: string} */
    private function race(bool $barrier): array
    {
        $keys = $this->seed();
        $command = fn(string $barrierKey): string => implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, '-d', 'xdebug.mode=off', dirname(__DIR__, 2) . '/Support/ScoreRace/score-race-worker.php', $this->host, $this->port, $barrierKey, '2'])) . ' 2>&1';
        if ($barrier) {
            $handles = [popen($command('f185-08:barrier'), 'r'), popen($command('f185-08:barrier'), 'r')];
        } else {
            $handles = [];
            foreach ([0, 1] as $_) {
                $handle = popen($command(''), 'r');
                self::assertNotFalse($handle);
                self::assertSame("ok\n", stream_get_contents($handle));
                self::assertSame(0, pclose($handle));
            }
        }
        foreach ($handles as $handle) {
            self::assertNotFalse($handle);
            self::assertSame("ok\n", stream_get_contents($handle));
            self::assertSame(0, pclose($handle));
        }
        $k5 = $this->executor->execute(['HGET', $keys['k5'], 'value']);
        $k1 = $this->executor->execute(['HGET', $keys['k1'], 'value']);
        self::assertIsString($k5);
        self::assertIsString($k1);

        return ['k5' => $k5, 'k1' => $k1];
    }

    public function testConcurrentWritesWithADecayCreditKeepTheSequentialResult(): void
    {
        $outcomes = [];
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $outcome = $this->race(true);
            $outcomes[] = 'K5=' . $outcome['k5'] . ' K1=' . $outcome['k1'];
        }

        self::assertSame(array_fill(0, self::ROUNDS, 'K5=4 K1=10'), $outcomes);
    }

    public function testControlSequentialWritesGiveFourAndTen(): void
    {
        $outcomes = [];
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $outcome = $this->race(false);
            $outcomes[] = 'K5=' . $outcome['k5'] . ' K1=' . $outcome['k1'];
        }

        self::assertSame(array_fill(0, self::ROUNDS, 'K5=4 K1=10'), $outcomes);
    }
}
