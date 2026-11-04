<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitOperationalKeyStateDTO;
use Maatify\RateLimiter\DTO\RateLimitOperationalSnapshotDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\DeviceIdentityResolverInterface;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionRedisDump;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-185-04 (#185): the presence rule of F-185-03 (`!== null && !== ''`) for `fingerprintHash` and `correlationId`, through
 * the Builder, the public runtime and the operational reader on a real Redis.
 *
 * - A Host resolver (`withDeviceIdentityResolver()`) returning `fingerprintHash` `"0"` is a present fingerprint and takes
 *   the path of `"1"`, with K3 / K5 derived alike by enforcement and by the reader; `""` is absence and behaves as `null`.
 * - The correlation subject is `correlationId` when present, else the account identifier when present: `correlationId`
 *   `""` with no account observes no spray, and with account `"1"` observes the subject `"1"`.
 */
#[Group('f-185-04')]
final class IdentityPresenceRedisTest extends TestCase
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

    private function builder(?DeviceIdentityResolverInterface $resolver = null): RateLimiterBuilder
    {
        $this->executor->execute(['FLUSHDB']);
        $builder = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('f185-04-key', 'f185-04-fingerprint', 'prod'),
            new RedisFullCapabilityStore($this->executor, 'f185-04'),
            new RecordingFailureSignalEmitter(),
        )->withClock(new FixedClock('2026-11-04 12:00:00'));

        return $resolver === null ? $builder : $builder->withDeviceIdentityResolver($resolver);
    }

    /** A Host resolver that returns the given current fingerprint for every request. */
    private static function resolver(?string $fingerprintHash, bool $trusted): DeviceIdentityResolverInterface
    {
        return new class ($fingerprintHash, $trusted) implements DeviceIdentityResolverInterface {
            public function __construct(private readonly ?string $fingerprintHash, private readonly bool $trusted) {}

            public function resolve(RateLimitContextDTO $context): DeviceIdentityDTO
            {
                return new DeviceIdentityDTO(
                    $this->fingerprintHash,
                    $this->trusted ? 'HIGH' : 'MEDIUM',
                    $this->trusted,
                    false,
                    DeviceIdentityResolver::normalizeUserAgent($context->ua),
                );
            }
        };
    }

    /**
     * Failed requests of account `"1"` on one device: `checkOnly`, and `recordFailure` after each admission. K5 is scored
     * by `login_protection` on a trusted session, K3 by the access deltas of `api_heavy_protection`.
     *
     * @return array{decisions: list<string>, snapshot: RateLimitOperationalSnapshotDTO, keys: int}
     */
    private function failures(string $policy, ?string $fingerprintHash, bool $trusted): array
    {
        $builder = $this->builder(self::resolver($fingerprintHash, $trusted));
        $limiter = $builder->build();
        $context = new RateLimitContextDTO('198.51.100.8', 'Mozilla/5.0 Chrome/120.0', '1');
        $decisions = [];
        for ($i = 0; $i < self::ROUNDS; $i++) {
            $result = $limiter->limit($context, RateLimitCommand::checkOnly($policy));
            $decisions[] = $result->decision;
            if ($result->decision === RateLimitResultDTO::DECISION_ALLOW) {
                $limiter->limit($context, RateLimitCommand::recordFailure($policy));
            }
        }
        $keys = $this->executor->execute(['DBSIZE']);
        self::assertIsInt($keys);

        return ['decisions' => $decisions, 'snapshot' => $builder->buildOperationalReader()->readScorePolicy($context, $policy), 'keys' => $keys];
    }

    private static function scope(RateLimitOperationalSnapshotDTO $snapshot, string $scope): ?RateLimitOperationalKeyStateDTO
    {
        return $scope === 'k3' ? $snapshot->scopes->k3 : $snapshot->scopes->k5;
    }

    /** @return array<string, array{string, bool, string}> */
    private static function fingerprintScopes(): array
    {
        return ['k5' => ['login_protection', true, 'k5'], 'k3' => ['api_heavy_protection', false, 'k3']];
    }

    public function testAFingerprintOfZeroIsPresentAndEnforcementAndTheReaderDeriveTheSameK3AndK5(): void
    {
        foreach (self::fingerprintScopes() as [$policy, $trusted, $scope]) {
            $zero = $this->failures($policy, '0', $trusted);
            $one = $this->failures($policy, '1', $trusted);

            self::assertSame($one['decisions'], $zero['decisions'], $scope);
            self::assertSame($one['keys'], $zero['keys'], $scope . ': the same scopes are written for fingerprint "0" and "1".');
            $zeroState = self::scope($zero['snapshot'], $scope);
            $oneState = self::scope($one['snapshot'], $scope);
            self::assertNotNull($zeroState, $scope . ' is derived for fingerprint "0".');
            self::assertNotNull($oneState, $scope . ' is derived for fingerprint "1".');
            // The reader reads the key enforcement wrote: the stored score is the one "1" shows, and it is not empty.
            self::assertNotNull($zeroState->score, $scope . ': the reader found no score enforcement wrote.');
            self::assertNotNull($oneState->score);
            self::assertSame($oneState->score->value, $zeroState->score->value, $scope);
            self::assertGreaterThan(0, $zeroState->score->value, $scope);
        }
    }

    public function testAnEmptyFingerprintIsAbsenceAndBehavesAsNull(): void
    {
        foreach (self::fingerprintScopes() as [$policy, $trusted, $scope]) {
            $empty = $this->failures($policy, '', $trusted);
            $null = $this->failures($policy, null, $trusted);

            self::assertSame($null['decisions'], $empty['decisions'], $scope);
            self::assertSame($null['keys'], $empty['keys'], $scope . ': no fingerprint scope is written for "".');
            foreach (['""' => $empty, 'null' => $null] as $label => $arm) {
                self::assertNull($arm['snapshot']->scopes->k3, $scope . ' ' . $label . ': no K3.');
                self::assertNull($arm['snapshot']->scopes->k5, $scope . ' ' . $label . ': no K5.');
            }
            self::assertEquals($null['snapshot']->scopes, $empty['snapshot']->scopes, $scope);
        }
    }

    /**
     * One pre-check of `login_protection` (a credential-spray policy) and the complete Redis state it leaves.
     *
     * @return array<string, array{type: string, value: mixed, hasTtl: bool}>
     */
    private function preCheck(?string $correlationId, ?string $accountId): array
    {
        $context = new RateLimitContextDTO('198.51.100.9', 'Mozilla/5.0 Chrome/120.0', $accountId, null, null, false, [], false, $correlationId);
        $result = $this->builder()->build()->limit($context, RateLimitCommand::checkOnly(self::POLICY));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);

        return AdmissionRedisDump::take($this->executor);
    }

    public function testAnEmptyCorrelationIdWithNoAccountObservesNoSpray(): void
    {
        $empty = $this->preCheck('', null);
        $none = $this->preCheck(null, null);
        $subject = $this->preCheck('subject-a', null);

        self::assertSame($none, $empty, 'correlationId "" must leave the state of no subject at all.');
        self::assertNotSame($none, $subject, 'Control: a present correlation subject is observed.');
    }

    public function testAnEmptyCorrelationIdFallsBackToThePresentAccount(): void
    {
        $empty = $this->preCheck('', '1');
        $fallback = $this->preCheck(null, '1');
        $other = $this->preCheck('subject-b', '1');

        self::assertSame($fallback, $empty, 'correlationId "" with account "1" must observe the subject "1".');
        self::assertNotSame($fallback, $other, 'Control: a present correlationId is a different subject.');
    }
}
