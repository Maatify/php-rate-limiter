<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #240: the lifecycle suites moved to the DEC-039 decimal-millisecond capability so that they run on a 32-bit build, where `NOW * 1000` is a float.
 * The native-int millisecond interface stays covered where its instant fits (64-bit): for the same instant it answers exactly what the decimal one
 * does, for the read, the mutation and the claim of a generation-bound score.
 */
#[Group('integration')]
#[Group('php32')]
final class NativeMillisecondInterfaceParityRedisTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private RedisFullCapabilityStore $store;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $executor = new RespRedisCommandExecutor($host, (int) $port);
        $executor->execute(['FLUSHDB']);
        $this->store = new RedisFullCapabilityStore($executor, 'native-ms-parity');
    }

    public function testTheNativeIntAndTheDecimalMillisecondInterfacesAnswerTheSame(): void
    {
        if (PHP_INT_SIZE < 8) {
            self::markTestSkipped('Outside the 32-bit public input domain: the native-int millisecond interface cannot receive 1.8e12. Covered on this width by the decimal-millisecond capability, which every lifecycle suite now uses (CallerTimeDecimalMillisecondsRedisTest, CanonicalStoredValueRedisTest, MutationSourceRedisTest, ClaimValidatesBeforeLiveBlockRedisTest).');
        }
        $ms = self::NOW * 1000;
        $decimal = (string) $ms;

        self::assertNull($this->store->readGenerationBoundScoreStateAt('p-int', null, $ms));
        self::assertNull($this->store->readGenerationBoundScoreStateAtMilliseconds('p-dec', null, $decimal));

        $viaInt = $this->store->mutateGenerationBoundScoreAt('p-int', null, null, 600, 5, $ms);
        $viaDecimal = $this->store->mutateGenerationBoundScoreAtMilliseconds('p-dec', null, null, 600, 5, $decimal);
        self::assertTrue($viaInt->applied);
        self::assertTrue($viaDecimal->applied);
        self::assertEquals($viaInt->state, $viaDecimal->state, 'The same mutation answers the same state through either interface.');

        self::assertEquals(
            $this->store->readGenerationBoundScoreStateAt('p-int', null, $ms),
            $this->store->readGenerationBoundScoreStateAtMilliseconds('p-dec', null, $decimal),
            'The same read answers the same state.',
        );
        self::assertSame(
            $this->store->claimPostPunishmentReentryAt('p-int', null, str_repeat('a', 32), $ms),
            $this->store->claimPostPunishmentReentryAtMilliseconds('p-dec', null, str_repeat('a', 32), $decimal),
            'A claim over a score without evidence answers the same.',
        );
    }
}
