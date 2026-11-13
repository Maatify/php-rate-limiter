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

    /** Three failed requests during an outage open the circuit; returns the limiter factory used for the later requests. */
    private function openCircuit(RecordingFailureSignalEmitter $signals): void
    {
        $outage = new CallableRedisCommandExecutor(function (array $command): mixed {
            if (OverlayWire::isScript($command, '__ov_result')) {
                throw new BackendFailureException('Test outage.');
            }

            return $this->executor->execute($command);
        });
        $command = RateLimitCommand::checkOnly('login_protection');
        for ($i = 0; $i < 3; $i++) {
            $this->limiterAt($outage, self::NOW + $i, $signals)->limit($this->context('account-' . $i), $command);
        }
        self::assertNotEmpty(array_filter(array_keys($this->state()), static fn(string $key): bool => str_contains($key, ':circuit:')), 'The circuit recorded the outage.');
    }

    private function limiterAt(CallableRedisCommandExecutor|RespRedisCommandExecutor $executor, int $now, RecordingFailureSignalEmitter $signals): RateLimiterInterface
    {
        return RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('test_secret', 'test_fp', 'prod'),
            new RedisFullCapabilityStore($executor, self::NAMESPACE),
            $signals,
        )->withClock(new FixedClock('@' . $now))->build();
    }

    /** @return list<string> the types of the signals emitted so far */
    private function signalTypes(RecordingFailureSignalEmitter $signals): array
    {
        return array_values(array_map(static fn($signal): string => $signal->type, $signals->getEmitted()));
    }

    public function testARejectedRequestLeavesTheRecoveryProbeUnsaved(): void
    {
        $signals = new RecordingFailureSignalEmitter();
        $this->openCircuit($signals);
        $this->limiterAt($this->executor, self::NOW + 400, $signals)->limit($this->context('account-step'), RateLimitCommand::checkOnly('login_protection'));
        $signals->clear();
        $before = $this->state();
        $rejection = new RateLimiterException('malformed bounded metadata');
        $saves = 0;
        $rejecting = new CallableRedisCommandExecutor(function (array $command) use ($rejection, &$saves): mixed {
            if (OverlayWire::isCommit($command)) {
                $saves++;
            }
            if (OverlayWire::isScript($command, '__ov_result') && OverlayWire::touchesFamily($command, 'distinct-meta')) {
                throw $rejection;
            }

            return $this->executor->execute($command);
        });

        // HALF_OPEN and healthy for the interval: the next request's probe would close the circuit.
        try {
            $this->limiterAt($rejecting, self::NOW + 400 + 121, $signals)->limit($this->context('account-probe'), RateLimitCommand::checkOnly('login_protection'));
            self::fail('The classified rejection must reach the caller.');
        } catch (RateLimiterException $caught) {
            self::assertSame($rejection, $caught);
        }

        self::assertSame(0, $saves);
        self::assertSame($before, $this->state(), 'Neither the probe lease nor the circuit state was written.');
        self::assertSame([], $this->signalTypes($signals), 'No transition was announced for a step that was not saved.');
    }

    public function testARecoveryProbeIsSavedWithItsRequestAndRecoversOnce(): void
    {
        $signals = new RecordingFailureSignalEmitter();
        $this->openCircuit($signals);
        $signals->clear();
        $command = RateLimitCommand::checkOnly('login_protection');
        $saves = 0;
        $counting = new CallableRedisCommandExecutor(function (array $c) use (&$saves): mixed {
            if (OverlayWire::isCommit($c)) {
                $saves++;
            }

            return $this->executor->execute($c);
        });

        // First probe: OPEN -> HALF_OPEN, the request itself is still answered degraded; lease and state are one save.
        $this->limiterAt($counting, self::NOW + 400, $signals)->limit($this->context('account-1'), $command);
        self::assertSame(1, $saves);
        self::assertNotEmpty(array_filter(array_keys($this->state()), static fn(string $key): bool => str_contains($key, ':probe:')), 'The lease is stored.');

        // Two requests are eligible at once: the second one's evaluation starts while the first one saves.
        $rival = null;
        $racing = new CallableRedisCommandExecutor(function (array $c) use (&$rival, $signals, $command): mixed {
            if ($rival === null && OverlayWire::isCommit($c)) {
                $rival = true;
                $inner = $this->limiterAt($this->executor, self::NOW + 400 + 121, $signals)->limit($this->context('account-rival'), $command);
                self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $inner->decision);
            }

            return $this->executor->execute($c);
        });
        $outer = $this->limiterAt($racing, self::NOW + 400 + 121, $signals)->limit($this->context('account-outer'), $command);

        self::assertTrue($rival);
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $outer->decision, 'The loser recomputed against the recovered circuit.');
        self::assertSame(
            1,
            count(array_filter($this->signalTypes($signals), static fn(string $type): bool => $type === \Maatify\RateLimiter\DTO\FailureSignalDTO::TYPE_CB_RECOVERED)),
            'The circuit recovered once, announced once.',
        );
    }
}
