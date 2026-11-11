<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\MarkerRace\MarkerRaceSetup;
use Maatify\RateLimiter\Tests\Support\Redis\OverlayWire;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-185-17 (#185, DEC-031, the Lead's adv6/r2): the `last_missing_fp` marker is read and written in one atomic step
 * (`replaceScore()`). On `e513638` the pipeline read it (`get`) and wrote it later (`set`): two concurrent failures both
 * read "no marker", so neither added `k4_repeated_missing_fp` (K4 = 6, SOFT_BLOCK L1, against 12 and HARD_BLOCK L3 one
 * after the other). The marker keeps the TTL `set()` gave it: a fresh 3600 s on every write.
 */
#[Group('f-185-17')]
final class MissingFingerprintMarkerRedisTest extends TestCase
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

    /** @return array{marker: string, k4: string} learned from one failure, then the state is flushed. */
    private function keys(): array
    {
        $this->executor->execute(['FLUSHDB']);
        $reflection = new \ReflectionClass(RedisFullCapabilityStore::class);
        $writes = [];
        foreach (['SCORE_SET', 'SCORE_REPLACE'] as $name) {
            $script = $reflection->hasConstant($name) ? $reflection->getConstant($name) : null;
            if (is_string($script)) {
                $writes[] = $script;
            }
        }
        $mutate = $reflection->getConstant('LIFECYCLE_MUTATE');
        self::assertIsString($mutate);
        $recorder = new class ($this->executor, $writes, $mutate) implements RedisCommandExecutorInterface {
            public ?string $marker = null;

            public ?string $k4 = null;

            /** @param list<string> $writes */
            public function __construct(private RedisCommandExecutorInterface $inner, private array $writes, private string $mutate) {}

            /** @param non-empty-list<int|string|float> $command */
            public function execute(array $command): mixed
            {
                $key = $command[3] ?? null;
                if ($command[0] === 'EVAL' && is_string($key)) {
                    if (array_filter($this->writes, fn(string $write): bool => OverlayWire::isScript($command, $write)) !== [] && (string) ($command[5] ?? '') === (string) MarkerRaceSetup::NOW) {
                        $this->marker = $key;
                    }
                    if (OverlayWire::isScript($command, $this->mutate)) {
                        $this->k4 = $key;
                    }
                }

                return $this->inner->execute($command);
            }
        };
        MarkerRaceSetup::fail($recorder);
        self::assertNotNull($recorder->marker, 'The marker is written by a missing-fingerprint failure.');
        self::assertNotNull($recorder->k4, 'K4 is written by the failure.');
        $this->executor->execute(['FLUSHDB']);

        return ['marker' => $recorder->marker, 'k4' => $recorder->k4];
    }

    private function k4(string $key): mixed
    {
        return $this->executor->execute(['HGET', $key, 'value']);
    }

    public function testTheSecondOfTwoConcurrentFailuresSeesTheMarker(): void
    {
        $worker = dirname(__DIR__, 2) . '/Support/MarkerRace/marker-race-worker.php';
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $keys = $this->keys();
            $command = implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, '-d', 'xdebug.mode=off', $worker, $this->host, $this->port, $keys['marker'], 'f185-17:barrier'])) . ' 2>&1';
            $handles = [popen($command, 'r'), popen($command, 'r')];
            $decisions = [];
            foreach ($handles as $handle) {
                self::assertNotFalse($handle);
                $decisions[] = json_decode(trim((string) stream_get_contents($handle)), true);
                self::assertSame(0, pclose($handle));
            }

            self::assertSame('12', $this->k4($keys['k4']), 'Round ' . $round . ': K4 = 3 + 3 + 6.');
            self::assertContains(['decision' => RateLimitResultDTO::DECISION_HARD_BLOCK, 'blockLevel' => 3], $decisions, 'Round ' . $round);
            $check = MarkerRaceSetup::fail($this->executor);
            self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $check->decision);
        }
    }

    public function testControlTwoFailuresOneAfterTheOther(): void
    {
        $keys = $this->keys();
        $first = MarkerRaceSetup::fail($this->executor);
        $second = MarkerRaceSetup::fail($this->executor);

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $first->decision);
        self::assertSame([RateLimitResultDTO::DECISION_HARD_BLOCK, 3], [$second->decision, $second->blockLevel]);
        self::assertSame('12', $this->k4($keys['k4']));
    }

    /**
     * The marker written at t = 0 and again at t = 3500 keeps a fresh TTL from its last write, so a failure at t = 3700
     * (200 s after the last one, inside the 1800 s window) still adds the repeated delta. A write that set the TTL only
     * on creation would have let the marker expire at t = 3600.
     */
    public function testTheMarkerTtlIsRefreshedOnEveryWrite(): void
    {
        $keys = $this->keys();
        MarkerRaceSetup::fail($this->executor);
        // 3500 s later the first marker has 100 s of its 3600 s left.
        $this->executor->execute(['PEXPIRE', $keys['marker'], '100000']);

        MarkerRaceSetup::fail($this->executor, MarkerRaceSetup::NOW + 3500);
        $ttl = $this->executor->execute(['TTL', $keys['marker']]);
        self::assertIsInt($ttl);
        self::assertGreaterThan(3590, $ttl, 'The write at t = 3500 gave the marker a fresh 3600 s.');
        self::assertSame((string) (MarkerRaceSetup::NOW + 3500), $this->executor->execute(['HGET', $keys['marker'], 'value']));
        self::assertSame('3', $this->k4($keys['k4']), 'At t = 3500 the marker was 3500 s old: no repeated delta.');

        $third = MarkerRaceSetup::fail($this->executor, MarkerRaceSetup::NOW + 3700);
        self::assertSame('12', $this->k4($keys['k4']), 'At t = 3700 the marker is 200 s old: 3 + 3 + 6.');
        self::assertSame([RateLimitResultDTO::DECISION_HARD_BLOCK, 3], [$third->decision, $third->blockLevel]);
    }
}
