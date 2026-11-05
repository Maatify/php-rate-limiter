<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-185-20 (#185, the Lead's adv6/r5): HARD_BLOCK stores its cycle and pause members as the caller time in canonical
 * decimal text. On `e513638` they went through Lua's `tostring()` (`%.14g`): a caller time of 1e14 or more, which the
 * script accepts (up to 2^53), was stored as "1e+14", and the next call read its own history as malformed.
 */
#[Group('f-185-20')]
final class HardBlockCanonicalTimeRedisTest extends TestCase
{
    private const NAMESPACE = 'f185-20';

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

    /** @return list<string> */
    private function members(string $family, string $logical): array
    {
        $members = $this->executor->execute(['ZRANGE', $this->key($family, $logical), '0', '-1']);
        self::assertIsArray($members);

        return array_values(array_map(static fn(mixed $member): string => is_string($member) ? $member : '', $members));
    }

    /** @return iterable<string, array{int}> */
    public static function times(): iterable
    {
        yield 'control 99999999999999 (14 digits)' => [99_999_999_999_999];
        yield '1e14' => [100_000_000_000_000];
        yield 'about 1.2e15' => [1_234_567_890_123_456];
        yield '2^53 - 100000 (the retention window still fits)' => [9_007_199_254_640_992];
    }

    #[DataProvider('times')]
    public function testTheCycleMemberIsCanonicalAndTheNextCallReadsIt(int $now): void
    {
        $first = $this->store->blockWithCycleTracking('hb', null, 2, 60, $now, 600, 3, 300, 86400);

        self::assertTrue($first->newCycle);
        self::assertSame([(string) $now], $this->members('cycle', 'hb'), 'The member is the caller time in canonical text.');
        $next = $this->store->blockWithCycleTracking('hb', null, 2, 60, $now + 1, 600, 3, 300, 86400);
        self::assertFalse($next->newCycle, 'The live block is the same cycle.');
        self::assertSame(1, $next->cycleCount);
        self::assertSame(0, $this->store->readDecayPauseState('hb', null, $now, $now + 1)->elapsedPausedSeconds);
    }

    #[DataProvider('times')]
    public function testThePauseMemberIsCanonical(int $now): void
    {
        // A threshold of one cycle activates the pause on the first block.
        $first = $this->store->blockWithCycleTracking('hb', null, 2, 60, $now, 600, 1, 300, 86400);

        self::assertTrue($first->pauseActivated);
        self::assertSame($now + 300, $first->pauseUntil);
        self::assertSame([$now . ':' . ($now + 300)], $this->members('pause', 'hb'));
        $this->store->blockWithCycleTracking('hb', null, 2, 60, $now + 61, 600, 1, 300, 86400);
        self::assertSame(300, $this->store->readDecayPauseState('hb', null, $now, $now + 400)->elapsedPausedSeconds);
    }
}
