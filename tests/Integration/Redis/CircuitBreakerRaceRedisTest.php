<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\DTO\FailureStateDTO;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-185-18 (#185, DEC-031, the Lead's adv6/r3): concurrent backend failures are all counted by the circuit breaker. On
 * `e513638` `reportFailure()` loaded the state, appended one failure and saved it with a plain `SET`: three processes that
 * read the same state each saved a one-failure state, and the circuit stayed CLOSED. The save is now fenced by the
 * version it was computed from (`saveIfVersion()`); a miss reads again, so the third failure trips the circuit (OPEN).
 */
#[Group('f-185-18')]
final class CircuitBreakerRaceRedisTest extends TestCase
{
    private const ROUNDS = 20;

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
     * Three failures released together (barrier before the first save) or one after the other.
     *
     * @return array{status: string, failures: int}
     */
    private function round(bool $concurrent): array
    {
        $this->executor->execute(['FLUSHDB']);
        $worker = dirname(__DIR__, 2) . '/Support/CircuitRace/circuit-race-worker.php';
        $command = fn(int $parties): string => implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, '-d', 'xdebug.mode=off', $worker, $this->host, $this->port, 'f185-18', 'f185-18:barrier', (string) $parties])) . ' 2>&1';
        if ($concurrent) {
            $handles = [popen($command(3), 'r'), popen($command(3), 'r'), popen($command(3), 'r')];
            foreach ($handles as $handle) {
                self::assertNotFalse($handle);
                self::assertIsArray(json_decode(trim((string) stream_get_contents($handle)), true));
                self::assertSame(0, pclose($handle));
            }
        } else {
            for ($i = 0; $i < 3; $i++) {
                $this->executor->execute(['DEL', 'f185-18:barrier']);
                $handle = popen($command(1), 'r');
                self::assertNotFalse($handle);
                self::assertIsArray(json_decode(trim((string) stream_get_contents($handle)), true));
                self::assertSame(0, pclose($handle));
            }
        }
        $state = (new RedisFullCapabilityStore($this->executor, 'f185-18'))->load('login_protection');
        self::assertNotNull($state);

        return ['status' => $state->status, 'failures' => count($state->failures)];
    }

    public function testThreeConcurrentFailuresTripTheCircuit(): void
    {
        $outcomes = [];
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $outcomes[] = $this->round(true);
        }

        self::assertSame(array_fill(0, self::ROUNDS, ['status' => FailureStateDTO::STATE_OPEN, 'failures' => 3]), $outcomes);
    }

    public function testControlThreeFailuresOneAfterTheOther(): void
    {
        $outcomes = [];
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $outcomes[] = $this->round(false);
        }

        self::assertSame(array_fill(0, self::ROUNDS, ['status' => FailureStateDTO::STATE_OPEN, 'failures' => 3]), $outcomes);
    }
}
