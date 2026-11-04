<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitOperationalSnapshotDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-185-03 (#185): one definition of account presence, `accountId !== null && accountId !== ''`, through the Builder, the
 * public runtime and the operational reader on a real Redis. `"0"` is a present identifier and takes the path of `"1"`
 * (K4 / K5 derived, 4 of 25 failed logins reach the verifier, then SOFT_BLOCK, K5 visible to the reader); `""` is absence
 * and behaves as `null`. On `b3e77e4` `"0"` and `""` threw `Missing current opaque anchor …` at the first `checkOnly`.
 */
#[Group('f-185-03')]
final class AccountPresenceRedisTest extends TestCase
{
    private const POLICY = 'login_protection';

    private const ROUNDS = 25;

    private RespRedisCommandExecutor $executor;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->executor = new RespRedisCommandExecutor($host, (int) $port);
    }

    private function builder(): RateLimiterBuilder
    {
        $this->executor->execute(['FLUSHDB']);

        return RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('f185-03-key', 'f185-03-fingerprint', 'prod'),
            new RedisFullCapabilityStore($this->executor, 'f185-03'),
            new RecordingFailureSignalEmitter(),
        )->withClock(new FixedClock('2026-11-04 12:00:00'));
    }

    private static function context(?string $accountId): RateLimitContextDTO
    {
        return new RateLimitContextDTO('198.51.100.7', 'Mozilla/5.0 Chrome/120.0', $accountId, null, 'session-device-1', true);
    }

    /**
     * Failed logins of one trusted session on its own device: `checkOnly`, and `recordFailure` after each admission.
     *
     * @return array{decisions: list<string>, snapshot: RateLimitOperationalSnapshotDTO, keys: int}
     */
    private function failedLogins(?string $accountId): array
    {
        $builder = $this->builder();
        $limiter = $builder->build();
        $context = self::context($accountId);
        $decisions = [];
        for ($i = 0; $i < self::ROUNDS; $i++) {
            $result = $limiter->limit($context, RateLimitCommand::checkOnly(self::POLICY));
            $decisions[] = $result->decision;
            if ($result->decision === RateLimitResultDTO::DECISION_ALLOW) {
                $limiter->limit($context, RateLimitCommand::recordFailure(self::POLICY));
            }
        }
        $keys = $this->executor->execute(['DBSIZE']);
        self::assertIsInt($keys);

        return ['decisions' => $decisions, 'snapshot' => $builder->buildOperationalReader()->readScorePolicy($context, self::POLICY), 'keys' => $keys];
    }

    /** @return iterable<string, array{?string}> */
    public static function identifiers(): iterable
    {
        yield '"0"' => ['0'];
        yield '""' => [''];
        yield '"00"' => ['00'];
        yield '"1"' => ['1'];
        yield 'null' => [null];
    }

    #[DataProvider('identifiers')]
    public function testCheckOnlyReturnsADecisionForEveryIdentifier(?string $accountId): void
    {
        $result = $this->builder()->build()->limit(self::context($accountId), RateLimitCommand::checkOnly(self::POLICY));

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertSame('NORMAL', $result->failureMode);
    }

    public function testZeroIsAPresentIdentifierAndTakesThePathOfOne(): void
    {
        $zero = $this->failedLogins('0');
        $one = $this->failedLogins('1');

        self::assertSame($one['decisions'], $zero['decisions']);
        self::assertSame(4, count(array_keys($zero['decisions'], RateLimitResultDTO::DECISION_ALLOW, true)));
        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $zero['decisions'][4]);
        self::assertSame($one['keys'], $zero['keys'], 'The same scopes are written for "0" and "1".');
        foreach (['0' => $zero, '1' => $one] as $label => $arm) {
            self::assertNotNull($arm['snapshot']->scopes->k4, $label . ': K4 is derived.');
            self::assertNotNull($arm['snapshot']->scopes->k5, $label . ': K5 is derived.');
            self::assertNotNull($arm['snapshot']->budget, $label . ': the account budget is read.');
        }
        // The reader reads the K5 key the enforcement path wrote: its score is the one "1" shows, not an empty scope.
        $zeroK5 = $zero['snapshot']->scopes->k5?->score;
        $oneK5 = $one['snapshot']->scopes->k5?->score;
        self::assertNotNull($zeroK5);
        self::assertNotNull($oneK5);
        self::assertSame($oneK5->value, $zeroK5->value);
        self::assertGreaterThan(0, $zeroK5->value);
    }

    public function testTheEmptyIdentifierIsAbsenceAndBehavesAsNull(): void
    {
        $empty = $this->failedLogins('');
        $null = $this->failedLogins(null);

        self::assertSame($null['decisions'], $empty['decisions']);
        self::assertSame($null['keys'], $empty['keys'], 'No account scope is written for "".');
        foreach (['""' => $empty, 'null' => $null] as $label => $arm) {
            self::assertNull($arm['snapshot']->scopes->k4, $label . ': no K4.');
            self::assertNull($arm['snapshot']->scopes->k5, $label . ': no K5.');
            self::assertNull($arm['snapshot']->budget, $label . ': no account budget.');
        }
        self::assertEquals($null['snapshot']->scopes, $empty['snapshot']->scopes);
    }

    public function testControlAPresentIdentifierAndAbsenceDiffer(): void
    {
        $present = $this->failedLogins('00');
        $absent = $this->failedLogins(null);

        self::assertNotSame($absent['decisions'], $present['decisions']);
        self::assertNotNull($present['snapshot']->scopes->k5);
        self::assertNull($absent['snapshot']->scopes->k5);
    }
}
