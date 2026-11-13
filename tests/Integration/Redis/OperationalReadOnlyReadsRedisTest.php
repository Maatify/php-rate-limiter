<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Redis\OverlayWire;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RecordingRedisCommandExecutor;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-185-26 (#185, LT-185-26 (a), LT-185-26a, DEC-032): Operational Read writes nothing. On `23501a2` the operational readers
 * read blocks and budgets through `checkBlock()` / `getBudget()`, whose scripts (BLOCK_GET, BUDGET_GET) delete a logically
 * expired key as they read it, so reading at the block's expiry removed the block key (adv8 `r5`). The readers now read
 * through BLOCK_PEEK / BUDGET_PEEK: the same validation, an expired key is absent, and the key stays where it is. The runtime
 * reads keep deleting (LT-P2-03).
 */
#[Group('f-185-26')]
final class OperationalReadOnlyReadsRedisTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const NAMESPACE = 'f185-26';

    private RespRedisCommandExecutor $executor;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->executor = new RespRedisCommandExecutor($host, (int) $port);
        $this->executor->execute(['FLUSHDB']);
    }

    private function store(?RedisCommandExecutorInterface $executor = null): RedisFullCapabilityStore
    {
        return new RedisFullCapabilityStore($executor ?? $this->executor, self::NAMESPACE);
    }

    private function raw(string $family, string $logical): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':' . $family . ':' . hash('sha256', $logical);
    }

    /** @return array{int, int} EXISTS and TTL of a key. */
    private function presence(string $key): array
    {
        $exists = $this->executor->execute(['EXISTS', $key]);
        $ttl = $this->executor->execute(['TTL', $key]);
        self::assertIsInt($exists);
        self::assertIsInt($ttl);

        return [$exists, $ttl];
    }

    private function dump(string $key): string
    {
        $dump = $this->executor->execute(['DUMP', $key]);
        self::assertIsString($dump);

        return $dump;
    }

    /** adv8 r5, store level: an expired block is absent to the peek and stays in Redis, byte for byte. */
    public function testPeekBlockAnswersNullForAnExpiredBlockAndLeavesTheKey(): void
    {
        $store = $this->store();
        $store->blockAt('b', 3, 60, self::NOW);
        $key = $this->raw('block', 'b');
        $before = $this->dump($key);

        self::assertNull($store->peekBlockAt('b', self::NOW + 60));
        self::assertNull($store->peekBlockAt('b', self::NOW + 61));
        self::assertSame(1, $this->presence($key)[0]);
        self::assertSame($before, $this->dump($key));
    }

    /** adv8 r5, store level: a budget past its epoch is absent to the peek and stays in Redis, byte for byte. */
    public function testPeekBudgetAnswersNullForAnEndedEpochAndLeavesTheKey(): void
    {
        $store = $this->store();
        $store->incrementBudgetAt('acct', 86400, 1, self::NOW);
        $key = $this->raw('budget', 'acct');
        $before = $this->dump($key);

        self::assertNull($store->peekBudgetAt('acct', self::NOW + 86400));
        self::assertSame(1, $this->presence($key)[0]);
        self::assertSame($before, $this->dump($key));
    }

    /** Live state: the peek answers what the runtime read answers. */
    public function testPeekAnswersAsTheRuntimeReadOnLiveState(): void
    {
        $store = $this->store();
        $store->blockAt('b', 4, 60, self::NOW);
        $store->incrementBudgetAt('acct', 86400, 3, self::NOW);

        self::assertEquals($store->checkBlockAt('b', self::NOW + 59), $store->peekBlockAt('b', self::NOW + 59));
        self::assertEquals($store->getBudgetAt('acct', self::NOW + 10), $store->peekBudgetAt('acct', self::NOW + 10));
        self::assertNull($store->peekBlockAt('absent', self::NOW));
        self::assertNull($store->peekBudgetAt('absent', self::NOW));
    }

    /** The same validation: malformed state is the same error through the peek, and nothing is deleted (DEC-017). */
    public function testPeekRaisesTheSameMalformedErrorsAndDeletesNothing(): void
    {
        $block = $this->raw('block', 'bad');
        $this->executor->execute(['HSET', $block, 'level', '7', 'expiresAt', (string) (self::NOW + 60)]);
        $this->executor->execute(['EXPIRE', $block, '60']);
        $budget = $this->raw('budget', 'bad');
        $this->executor->execute(['HSET', $budget, 'count', '-1', 'epochStart', (string) self::NOW, 'epochDuration', '60']);
        $this->executor->execute(['EXPIRE', $budget, '60']);
        $store = $this->store();

        foreach ([
            'block' => [fn() => $store->peekBlockAt('bad', self::NOW), fn() => $store->checkBlockAt('bad', self::NOW)],
            'budget' => [fn() => $store->peekBudgetAt('bad', self::NOW), fn() => $store->getBudgetAt('bad', self::NOW)],
        ] as $label => [$peek, $runtime]) {
            $messages = [];
            foreach ([$peek, $runtime] as $read) {
                try {
                    $read();
                    self::fail($label . ': malformed state must raise.');
                } catch (RateLimiterException $exception) {
                    $messages[] = $exception->getMessage();
                }
            }
            self::assertStringContainsString('malformed ' . $label . ' state', $messages[0]);
            self::assertSame($messages[1], $messages[0], $label . ': the peek and the runtime read raise the same error.');
        }
        self::assertSame(1, $this->presence($block)[0]);
        self::assertSame(1, $this->presence($budget)[0]);
    }

    /** Control (LT-P2-03): the runtime reads still delete a logically expired key. */
    public function testTheRuntimeReadsStillDeleteAnExpiredKey(): void
    {
        $store = $this->store();
        $store->blockAt('b', 3, 60, self::NOW);
        $store->incrementBudgetAt('acct', 86400, 1, self::NOW);

        self::assertNull($store->checkBlockAt('b', self::NOW + 61));
        self::assertNull($store->getBudgetAt('acct', self::NOW + 86400));
        self::assertSame(0, $this->presence($this->raw('block', 'b'))[0]);
        self::assertSame(0, $this->presence($this->raw('budget', 'acct'))[0]);
    }

    /**
     * adv8 r5 through the public API: blocks written by the runtime are read by the operational reader after they expire;
     * every block key is still in Redis afterwards, and the reader ran no deleting script.
     */
    public function testTheScoreReaderLeavesExpiredBlockKeysInRedis(): void
    {
        $reflection = new ReflectionClass(RedisFullCapabilityStore::class);
        $blockScripts = [$reflection->getConstant('HARD_BLOCK'), $reflection->getConstant('BLOCK_SET')];
        $deleting = [$reflection->getConstant('BLOCK_GET'), $reflection->getConstant('BUDGET_GET')];
        $spy = $this->spy();
        $config = new RateLimiterConfig('key-1', 'fp-1', 'prod');
        $emitter = new RecordingFailureSignalEmitter();
        $limiter = RateLimiterBuilder::fromFullCapabilityStore($config, $this->store($spy), $emitter)
            ->withClock(new FixedClock('2026-11-05 12:00:00'))
            ->build();
        $context = new RateLimitContextDTO('203.0.113.9', 'Mozilla/5.0 Chrome/120.0', 'acct-r5', null, 'dev-1', false);
        $result = null;
        for ($i = 0; $i < 12; $i++) {
            $result = $limiter->limit($context, RateLimitCommand::recordFailure('login_protection'));
            if ($result->decision === 'HARD_BLOCK') {
                break;
            }
        }
        self::assertSame('HARD_BLOCK', $result->decision);
        self::assertIsInt($result->retryAfter);
        $blockKeys = [];
        foreach ($spy->commands as $command) {
            if (array_filter($blockScripts, static fn(mixed $script): bool => is_string($script) && OverlayWire::isScript($command, $script)) !== []) {
                self::assertIsString($command[3]);
                $blockKeys[$command[3]] = true;
            }
        }
        self::assertNotSame([], $blockKeys);

        $readSpy = $this->spy();
        $reader = RateLimiterBuilder::fromFullCapabilityStore($config, $this->store($readSpy), $emitter)
            ->withClock(new FixedClock('2026-11-05 12:00:00 +' . ($result->retryAfter + 1) . ' seconds'))
            ->buildOperationalReader();
        $snapshot = $reader->readScorePolicy($context, 'login_protection');

        foreach ([$snapshot->scopes->k1, $snapshot->scopes->k2, $snapshot->scopes->k3, $snapshot->scopes->k4, $snapshot->scopes->k5] as $scope) {
            self::assertNull($scope?->activeHardBlock, 'The expired block is absent to the reader.');
        }
        foreach (array_keys($blockKeys) as $key) {
            self::assertSame(1, $this->presence((string) $key)[0], 'The reader leaves the expired block key in Redis.');
        }
        foreach ($readSpy->commands as $command) {
            self::assertFalse($command[0] === 'EVAL' && in_array($command[1], $deleting, true), 'The reader runs no deleting read.');
        }
    }

    /** The simple reader: a window past its interval reads as 0 and its budget key stays in Redis. */
    public function testTheSimpleReaderLeavesAnEndedWindowInRedis(): void
    {
        $config = new RateLimiterConfig('key-1', 'fp-1', 'prod');
        $policy = new FixedWindowThrottlePolicy('checkout', 5, 60);
        $clock = new FixedClock('2026-11-05 12:00:00');
        $spy = $this->spy();
        $builder = RateLimiterBuilder::fromFullCapabilityStore($config, $this->store($spy), new RecordingFailureSignalEmitter())
            ->withSimpleThrottlePolicy($policy)
            ->withClock($clock);
        $builder->build()->consume('checkout', 'subject-1', 2);
        $budgetKeys = [];
        foreach ($spy->commands as $command) {
            if ($command[0] === 'EVAL' && is_string($command[3] ?? null) && str_contains($command[3], ':budget:')) {
                $budgetKeys[$command[3]] = true;
            }
        }
        self::assertCount(1, $budgetKeys);
        $key = (string) array_key_first($budgetKeys);
        $before = $this->dump($key);

        self::assertSame(2, $builder->buildOperationalReader()->readSimpleThrottle('checkout', 'subject-1')->count);
        $clock->setNow(new \DateTimeImmutable('2026-11-05 12:01:00'));
        self::assertSame(0, $builder->buildOperationalReader()->readSimpleThrottle('checkout', 'subject-1')->count);
        self::assertSame($before, $this->dump($key), 'The reader leaves the ended window in Redis, byte for byte.');
    }

    private function spy(): RecordingRedisCommandExecutor
    {
        return new RecordingRedisCommandExecutor($this->executor);
    }
}
