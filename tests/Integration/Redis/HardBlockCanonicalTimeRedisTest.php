<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\NativeDomain;
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
#[Group('php32')]
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

    /**
     * Caller times as exact decimals. The first four are the 14-to-16-digit values where Lua's `%.14g` once wrote "1e+14"; they are above a 32-bit
     * `int` and are skipped there, per case (see `time()`). The last is a control a 32-bit caller can give (2147000000: `now + 300 + 86400` — the pause and its retention — and
     * `now + 400` stay native): it runs on every width and keeps the canonical-text and next-call-reads-it contract covered on 32-bit, where no
     * native time reaches the 1e14 range at which the defect lived.
     *
     * @return iterable<string, array{string}>
     */
    public static function times(): iterable
    {
        yield 'control 99999999999999 (14 digits)' => ['99999999999999'];
        yield '1e14' => ['100000000000000'];
        yield 'about 1.2e15' => ['1234567890123456'];
        yield '2^53 - 100000 (the retention window still fits)' => ['9007199254640992'];
        yield 'a 32-bit-native control (10 digits)' => ['2147000000'];
    }

    private static function time(string $decimal): int
    {
        return NativeDomain::int($decimal, 'the "a 32-bit-native control" data set of this class (canonical member text and the next call reading it).');
    }

    #[DataProvider('times')]
    public function testTheCycleMemberIsCanonicalAndTheNextCallReadsIt(string $nowText): void
    {
        $now = self::time($nowText);
        $first = $this->store->blockWithCycleTracking('hb', null, 2, 60, $now, 600, 3, 300, 86400);

        self::assertTrue($first->newCycle);
        self::assertSame([(string) $now], $this->members('cycle', 'hb'), 'The member is the caller time in canonical text.');
        $next = $this->store->blockWithCycleTracking('hb', null, 2, 60, $now + 1, 600, 3, 300, 86400);
        self::assertFalse($next->newCycle, 'The live block is the same cycle.');
        self::assertSame(1, $next->cycleCount);
        self::assertSame(0, $this->store->readDecayPauseState('hb', null, $now, $now + 1)->elapsedPausedSeconds);
    }

    #[DataProvider('times')]
    public function testThePauseMemberIsCanonical(string $nowText): void
    {
        $now = self::time($nowText);
        // A threshold of one cycle activates the pause on the first block.
        $first = $this->store->blockWithCycleTracking('hb', null, 2, 60, $now, 600, 1, 300, 86400);

        self::assertTrue($first->pauseActivated);
        self::assertSame($now + 300, $first->pauseUntil);
        self::assertSame([$now . ':' . ($now + 300)], $this->members('pause', 'hb'));
        $this->store->blockWithCycleTracking('hb', null, 2, 60, $now + 61, 600, 1, 300, 86400);
        self::assertSame(300, $this->store->readDecayPauseState('hb', null, $now, $now + 400)->elapsedPausedSeconds);
    }
}
