<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisAdmissionOperations;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\AdmissionFenceControlInterface;
use Maatify\RateLimiter\Service\RateLimiterInterface;
use Maatify\RateLimiter\Tests\Support\Admission\PublicRedisAdmissionFixture;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\OverlayWire;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * T-5 (DEC-037), the boundaries of "one request is one save": what the admission layer and the circuit breaker may
 * write around a `checkOnly` evaluation, and which of those writes can precede a classified rejection.
 */
#[Group('t5-request-atomicity')]
final class RequestAtomicityBoundariesRedisTest extends TestCase
{
    private const NOW = 1_000_000;

    private const NAMESPACE = 't5-boundaries';

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

    /** @return array<string, string> key => DUMP and absolute expiry of every key */
    private function state(): array
    {
        $keys = $this->executor->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        sort($keys);
        $state = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $dump = $this->executor->execute(['DUMP', $key]);
            self::assertIsString($dump);
            $expiry = $this->executor->execute(['PEXPIRETIME', $key]);
            self::assertIsInt($expiry);
            $state[$key] = bin2hex($dump) . '@' . $expiry;
        }

        return $state;
    }

    private function admissionScript(string $name): string
    {
        $script = (new \ReflectionClass(RedisAdmissionOperations::class))->getConstant($name);
        self::assertIsString($script);

        return $script;
    }

    private function runtime(CallableRedisCommandExecutor|RespRedisCommandExecutor $executor, int $now = self::NOW): RateLimiterInterface
    {
        $runtime = PublicRedisAdmissionFixture::build($executor, self::NAMESPACE, $now);
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $runtime);

        return $runtime;
    }

    private function context(string $account): RateLimitContextDTO
    {
        return new RateLimitContextDTO(ip: '192.0.2.10', ua: 'redis-gate', accountId: $account);
    }

    private function openFence(): void
    {
        $runtime = $this->runtime($this->executor);
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $runtime);
        $runtime->openAdmissionFence('login');
        $runtime->limit($this->context('account-seed'), RateLimitCommand::checkOnly('login_protection'));
    }

    public function testAnAdmissionCheckOnlyRejectedInItsEvaluationLeavesRedisUntouched(): void
    {
        $this->openFence();
        $before = $this->state();
        $evaluations = 0;
        $saves = 0;
        $rejection = new RateLimiterException('malformed bounded metadata');
        $executor = new CallableRedisCommandExecutor(function (array $command) use (&$evaluations, &$saves, $rejection): mixed {
            if (OverlayWire::isCommit($command)) {
                $saves++;
            }
            if (OverlayWire::isScript($command, '__ov_result') && OverlayWire::touchesFamily($command, 'distinct-meta')) {
                if (++$evaluations === 3) {
                    throw $rejection;
                }
            }

            return $this->executor->execute($command);
        });

        try {
            $this->runtime($executor)->limit($this->context('account-new'), RateLimitCommand::checkOnly('login_protection'));
            self::fail('The classified rejection must reach the caller.');
        } catch (RateLimiterException $caught) {
            self::assertSame($rejection, $caught);
        }

        self::assertSame(3, $evaluations);
        self::assertSame(0, $saves);
        self::assertSame($before, $this->state(), 'Neither the lane, nor the evaluation, nor a reservation wrote anything.');
    }

    public function testAnAdmissionCheckOnlyRejectedInItsReservationLeavesRedisUntouched(): void
    {
        $this->openFence();
        $before = $this->state();
        $reservations = 0;
        $saves = 0;
        $reserve = $this->admissionScript('RESERVE');
        $rejection = new RateLimiterException('malformed admission state: test');
        $executor = new CallableRedisCommandExecutor(function (array $command) use (&$reservations, &$saves, $reserve, $rejection): mixed {
            if (OverlayWire::isCommit($command)) {
                $saves++;
            }
            if (OverlayWire::isScript($command, $reserve)) {
                $reservations++;
                throw $rejection;
            }

            return $this->executor->execute($command);
        });

        try {
            $this->runtime($executor)->limit($this->context('account-new'), RateLimitCommand::checkOnly('login_protection'));
            self::fail('The classified rejection must reach the caller.');
        } catch (RateLimiterException $caught) {
            self::assertSame($rejection, $caught);
        }

        self::assertSame(1, $reservations, 'The evaluation completed and the reservation was reached.');
        self::assertSame(0, $saves, 'The evaluation that preceded the rejected reservation was not saved.');
        self::assertSame($before, $this->state());
    }

    public function testAnAdmissionCheckOnlyThatSucceedsSavesEvaluationAndPermitTogether(): void
    {
        $this->openFence();
        $before = $this->state();
        $saves = 0;
        $executor = new CallableRedisCommandExecutor(function (array $command) use (&$saves): mixed {
            if (OverlayWire::isCommit($command)) {
                $saves++;
            }

            return $this->executor->execute($command);
        });

        $result = $this->runtime($executor)->limit($this->context('account-new'), RateLimitCommand::checkOnly('login_protection'));

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertNotNull($result->metadata?->permit, 'A permit was issued.');
        self::assertSame(1, $saves, 'The evaluation and the reservation are one save.');
        $after = $this->state();
        self::assertNotEmpty(array_filter(array_keys($after), static fn(string $key): bool => str_contains($key, ':permit:')), 'The permit is stored.');
        self::assertNotSame($before, $after);
    }

    public function testAnOutcomeKeepsItsClaimWhenTheEvaluationAfterItIsRejected(): void
    {
        $this->openFence();
        $runtime = $this->runtime($this->executor);
        $check = $runtime->limit($this->context('account-new'), RateLimitCommand::checkOnly('login_protection'));
        $permit = $check->metadata?->permit;
        self::assertNotNull($permit);
        $before = $this->state();
        $rejection = new RateLimiterException('malformed bounded metadata');
        $executor = new CallableRedisCommandExecutor(function (array $command) use ($rejection): mixed {
            if (OverlayWire::isScript($command, '__ov_result') && OverlayWire::touchesFamily($command, 'distinct-meta')) {
                throw $rejection;
            }

            return $this->executor->execute($command);
        });

        try {
            $this->runtime($executor)->limit($this->context('account-new'), new RateLimitCommand('login_protection', 1, false, true, false, $permit));
            self::fail('The classified rejection must reach the caller.');
        } catch (RateLimiterException $caught) {
            self::assertSame($rejection, $caught);
        }

        // DEC-024 / DEC-036: the claim and the admission state it mutates are one atomic operation of their own; they
        // stay when the evaluation that follows is rejected. Nothing of the evaluation was saved.
        $changed = array_keys(array_diff_assoc($this->state(), $before));
        self::assertNotEmpty($changed);
        foreach ($changed as $key) {
            self::assertSame(1, preg_match('/:(permit|adm:[a-z]+):/', $key), 'Only the claimed permit and its admission state changed: ' . $key);
        }
    }

    public function testACircuitRecoveryProbeWritesOnlyCircuitStateBeforeARejectedEvaluation(): void
    {
        $config = new RateLimiterConfig('test_secret', 'test_fp', 'prod');
        $outage = new class {
            public bool $active = true;
        };
        $executor = new CallableRedisCommandExecutor(function (array $command) use ($outage): mixed {
            if ($outage->active && OverlayWire::isScript($command, '__ov_result')) {
                throw new BackendFailureException('Test outage.');
            }

            return $this->executor->execute($command);
        });
        $limiter = static fn(int $now): RateLimiterInterface => RateLimiterBuilder::fromFullCapabilityStore(
            $config,
            new RedisFullCapabilityStore($executor, self::NAMESPACE),
            new RecordingFailureSignalEmitter(),
        )->withClock(new FixedClock('@' . $now))->build();
        $command = RateLimitCommand::checkOnly('login_protection');
        for ($i = 0; $i < 3; $i++) {
            $limiter(self::NOW + $i)->limit($this->context('account-' . $i), $command);
        }
        $outage->active = false;
        $before = $this->state();
        self::assertNotEmpty(array_filter(array_keys($before), static fn(string $key): bool => str_contains($key, ':circuit:')), 'The circuit recorded the outage.');

        $rejection = new RateLimiterException('malformed bounded metadata');
        $rejecting = new CallableRedisCommandExecutor(function (array $command) use ($rejection): mixed {
            if (OverlayWire::isScript($command, '__ov_result') && OverlayWire::touchesFamily($command, 'distinct-meta')) {
                throw $rejection;
            }

            return $this->executor->execute($command);
        });
        try {
            RateLimiterBuilder::fromFullCapabilityStore($config, new RedisFullCapabilityStore($rejecting, self::NAMESPACE), new RecordingFailureSignalEmitter())
                ->withClock(new FixedClock('@' . (self::NOW + 400)))->build()
                ->limit($this->context('account-probe'), $command);
        } catch (RateLimiterException $caught) {
            self::assertSame($rejection, $caught);
        }

        $changed = array_keys(array_diff_assoc($this->state(), $before));
        self::assertNotEmpty($changed, 'The recovery probe wrote its lease before the evaluation was rejected.');
        foreach ($changed as $key) {
            self::assertSame(1, preg_match('/:(circuit|probe):/', $key), 'Only circuit-breaker keys changed before the rejection: ' . $key);
        }
    }
}
