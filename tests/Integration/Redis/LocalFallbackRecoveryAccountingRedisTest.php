<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\FailureSignalDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\LocalFallbackLimiter;
use Maatify\RateLimiter\Service\RateLimiterInterface;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\OverlayWire;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #259: how much of the process-local fallback allowance one public call consumes while the circuit recovers.
 *
 * A recovery step runs inside `transactRequest()`, which evaluates the whole request again when its save finds that another request
 * changed what it read (DEC-037). The local fallback counters are process memory: an overlay cannot hold them back. The intended
 * accounting (DEC-011, DEC-015, `LocalFallbackLimiter::evaluate()`): a public call whose final answer is the degraded answer consumes
 * its allowance once; an attempt that is abandoned, repeated or answered by something else consumes none.
 *
 * Everything goes through the public builder, the official Redis store and an isolated Redis; the circuit is tripped by a real
 * outage. The `login_protection` fallback allows 3 requests per account in a 600 s window.
 */
#[Group('integration')]
#[Group('fallback-accounting-259')]
#[Group('php32')]
final class LocalFallbackRecoveryAccountingRedisTest extends TestCase
{
    private const OPENED = 999_000;

    private const PROBE = 999_400;

    private const NS = 'fallback-259';

    private RespRedisCommandExecutor $raw;

    private RecordingFailureSignalEmitter $signals;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->raw = new RespRedisCommandExecutor($host, (int) $port);
        $this->raw->execute(['FLUSHDB']);
        $this->signals = new RecordingFailureSignalEmitter();
        $reflection = new \ReflectionClass(LocalFallbackLimiter::class);
        foreach (['counters', 'trackedSubjects', 'trackedSubjectExpiries'] as $property) {
            $reflection->getProperty($property)->setValue(null, []);
        }
    }

    private function limiterAt(CallableRedisCommandExecutor|RespRedisCommandExecutor $executor, int $now): RateLimiterInterface
    {
        return RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('test_secret', 'test_fp', 'prod'),
            new RedisFullCapabilityStore($executor, self::NS),
            $this->signals,
        )->withClock(new FixedClock('@' . $now))->build();
    }

    private function context(string $account, string $ip = '198.51.100.7'): RateLimitContextDTO
    {
        return new RateLimitContextDTO(ip: $ip, ua: 'fallback-259', accountId: $account);
    }

    /** Three failed requests during an outage trip the circuit (`OPEN`, `openSince` = OPENED + 2). */
    private function tripTheCircuit(): void
    {
        $outage = new CallableRedisCommandExecutor(function (array $command): mixed {
            if (OverlayWire::isScript($command, '__ov_result')) {
                throw new BackendFailureException('Test outage.');
            }

            return $this->raw->execute($command);
        });
        for ($i = 0; $i < 3; $i++) {
            $this->limiterAt($outage, self::OPENED + $i)->limit($this->context('outage-' . $i, '203.0.113.' . (10 + $i)), RateLimitCommand::checkOnly('login_protection'));
        }
        self::assertSame('OPEN', $this->circuitStatus());
        $this->signals->clear();
    }

    private function circuitStatus(): string
    {
        $keys = $this->raw->execute(['KEYS', '*:circuit:*']);
        self::assertIsArray($keys);
        if ($keys === []) {
            return 'ABSENT';
        }
        self::assertIsString($keys[0]);
        $json = $this->raw->execute(['GET', $keys[0]]);
        self::assertIsString($json);
        $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsString($decoded['status']);

        return $decoded['status'];
    }

    private function circuitKey(): string
    {
        $keys = $this->raw->execute(['KEYS', '*:circuit:*']);
        self::assertIsArray($keys);
        self::assertIsString($keys[0] ?? null);

        return $keys[0];
    }

    /** The local fallback units the account has consumed in the current window (read from the limiter's own counters). */
    private function consumed(string $account): int
    {
        $namespace = 'fallback:' . hash('sha256', 'login_protection');
        $suffix = ':subject:' . hash('sha256', $namespace . ':account:' . $account);
        $counters = (new \ReflectionClass(LocalFallbackLimiter::class))->getProperty('counters')->getValue();
        self::assertIsArray($counters);
        $total = 0;
        foreach ($counters as $key => $counter) {
            if (str_ends_with((string) $key, $suffix) && str_contains((string) $key, ':account:')) {
                self::assertIsArray($counter);
                self::assertIsInt($counter['count'] ?? null);
                $total += $counter['count'];
            }
        }

        return $total;
    }

    /** @return array<string, string> key => exact DUMP and PEXPIRETIME, read from outside Lua */
    private function snapshot(): array
    {
        $keys = $this->raw->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        sort($keys);
        $state = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $dump = $this->raw->execute(['DUMP', $key]);
            self::assertIsString($dump);
            $expiry = $this->raw->execute(['PEXPIRETIME', $key]);
            self::assertTrue(is_int($expiry) || is_string($expiry));
            $state[$key] = bin2hex($dump) . '@' . $expiry;
        }

        return $state;
    }

    /** @return list<string> */
    private function signalTypes(): array
    {
        return array_values(array_map(static fn($signal): string => $signal->type, $this->signals->getEmitted()));
    }

    /** The local fallback units a policy has consumed on one source address (all `ip_prefix` buckets). */
    private function consumedByAddress(string $policy): int
    {
        $namespace = 'fallback:' . hash('sha256', $policy);
        $counters = (new \ReflectionClass(LocalFallbackLimiter::class))->getProperty('counters')->getValue();
        self::assertIsArray($counters);
        $total = 0;
        foreach ($counters as $key => $counter) {
            if (str_starts_with((string) $key, $namespace . ':ip_prefix:') && ! str_contains((string) $key, ':ip_prefix_ua:')) {
                self::assertIsArray($counter);
                self::assertIsInt($counter['count'] ?? null);
                $total += $counter['count'];
            }
        }

        return $total;
    }

    private static function cause(RateLimitResultDTO $result): ?string
    {
        return $result->metadata?->cause;
    }

    /**
     * Public calls of the account after the first one, until the first fallback denial.
     *
     * @return int how many of them were answered (the allowance the first call left over)
     */
    private function leftOver(string $account): int
    {
        $answered = 0;
        for ($i = 1; $i <= 8; $i++) {
            $result = $this->limiterAt($this->raw, self::PROBE + $i)->limit($this->context($account), RateLimitCommand::checkOnly('login_protection'));
            if (self::cause($result) === 'fallback_limit_exceeded') {
                return $answered;
            }
            $answered++;
        }

        return $answered;
    }

    /**
     * One public call of `acct` at the first probe moment, with the commit of its recovery step intercepted by `$onCommit(commitNumber, command)`.
     *
     * @param callable(int, list<int|string|float>): void $onCommit may change Redis or throw before the commit is sent
     * @return array{RateLimitResultDTO, int, int} the answer, how many saves were attempted and how many local units the account consumed
     */
    private function callWith(callable $onCommit): array
    {
        $commits = 0;
        $executor = new CallableRedisCommandExecutor(function (array $command) use (&$commits, $onCommit): mixed {
            if (OverlayWire::isCommit($command)) {
                $commits++;
                $onCommit($commits, $command);
            }

            return $this->raw->execute($command);
        });
        $result = $this->limiterAt($executor, self::PROBE)->limit($this->context('acct'), RateLimitCommand::checkOnly('login_protection'));

        return [$result, $commits, $this->consumed('acct')];
    }

    /** Another request probes at the same moment, saves first and is answered by the fallback itself (its own account and address). */
    private function rivalProbes(): void
    {
        $rival = $this->limiterAt($this->raw, self::PROBE)->limit($this->context('rival', '192.0.2.99'), RateLimitCommand::checkOnly('login_protection'));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $rival->decision);
        self::assertSame('HALF_OPEN', $this->circuitStatus());
    }

    public function testACallWithoutAConflictConsumesTheAllowanceOnce(): void
    {
        $this->tripTheCircuit();

        [$result, $commits, $consumed] = $this->callWith(static function (): void {});

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision, 'The probe answers the call from the fallback.');
        self::assertSame(1, $commits, 'One save: lease and state change.');
        self::assertSame(1, $consumed);
        self::assertSame('HALF_OPEN', $this->circuitStatus());
        self::assertSame(2, $this->leftOver('acct'), 'Allowance 3: the first call used one, two calls follow, then the denial.');
    }

    public function testAConflictThatEndsInTheFallbackAnswerConsumesTheAllowanceOnce(): void
    {
        $this->tripTheCircuit();

        [$result, $commits, $consumed] = $this->callWith(function (int $commit): void {
            if ($commit === 1) {
                $this->rivalProbes();
            }
        });

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertSame(1, $commits, 'The first save found the circuit changed; the evaluation was repeated, and the repeat saves nothing.');
        self::assertSame(1, $consumed, 'One public call, one unit; the abandoned attempt consumed none.');
        self::assertSame(2, $this->leftOver('acct'));
        self::assertSame([], $this->signalTypes(), 'No transition was announced.');
    }

    public function testRepeatedConflictsConsumeTheAllowanceOnce(): void
    {
        $this->tripTheCircuit();

        [$result, $commits, $consumed] = $this->callWith(function (int $commit, array $command): void {
            if ($commit <= 3) {
                // The expiry of the circuit key changes under the request: its fingerprint differs, the state does not.
                self::assertSame(1, $this->raw->execute(['PEXPIRE', $this->circuitKey(), (string) (3_600_000 + $commit)]));
            }
        });

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertSame(4, $commits, 'Three conflicts and a fourth evaluation that saved.');
        self::assertSame(1, $consumed, 'Four evaluations of one public call consume one unit.');
        self::assertSame('HALF_OPEN', $this->circuitStatus());
        self::assertSame(2, $this->leftOver('acct'));
    }

    public function testExhaustedRetriesAnswerWithTheConcurrencyBlockAndConsumeNothing(): void
    {
        $this->tripTheCircuit();
        $before = $this->snapshot();
        $attempt = 0;

        [$result, $commits, $consumed] = $this->callWith(function (int $commit) use (&$attempt): void {
            $attempt++;
            self::assertSame(1, $this->raw->execute(['PEXPIRE', $this->circuitKey(), (string) (3_600_000 + $attempt)]));
        });

        self::assertSame('k4_concurrency_conflict', self::cause($result), 'Not a fallback answer.');
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertGreaterThan(1, $commits, 'The evaluation was repeated until its bound.');
        self::assertSame(0, $consumed, 'The call was not answered by the fallback: it consumes none of its allowance.');
        self::assertSame('OPEN', $this->circuitStatus(), 'Nothing was saved.');
        self::assertSame(array_keys($before), array_keys($this->snapshot()), 'No key was added: no probe lease, no other state.');
        self::assertSame([], $this->signalTypes());
        self::assertSame(3, $this->leftOver('acct'));
    }

    public function testASaveThatFailsConsumesTheAllowanceOnce(): void
    {
        $this->tripTheCircuit();
        $before = $this->snapshot();

        [$result, $commits, $consumed] = $this->callWith(static function (): void {
            throw new BackendFailureException('The save did not reach Redis.');
        });

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision, 'The step runs once more in this process only (DEC-037): the call is answered by the fallback.');
        self::assertGreaterThanOrEqual(2, $commits);
        self::assertSame(1, $consumed, 'The failed attempts and their repeats consume one unit in all.');
        self::assertSame($before, $this->snapshot(), 'No save reached Redis: every key, DUMP and PEXPIRETIME as before.');
        self::assertSame([], $this->signalTypes());
        self::assertSame(2, $this->leftOver('acct'));
    }

    public function testASaveWhoseReplyWasLostConsumesTheAllowanceOnce(): void
    {
        $this->tripTheCircuit();
        $lost = false;
        $commits = 0;
        $executor = new CallableRedisCommandExecutor(function (array $command) use (&$lost, &$commits): mixed {
            if (OverlayWire::isCommit($command)) {
                $commits++;
                if (! $lost) {
                    $lost = true;
                    $this->raw->execute($command);
                    throw new BackendFailureException('The reply to the save was lost.');
                }
            }

            return $this->raw->execute($command);
        });

        $result = $this->limiterAt($executor, self::PROBE)->limit($this->context('acct'), RateLimitCommand::checkOnly('login_protection'));

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertSame('HALF_OPEN', $this->circuitStatus(), 'The save was applied.');
        self::assertSame(1, $this->consumed('acct'), 'The unknown outcome of the save does not consume a second unit.');
        self::assertSame(2, $this->leftOver('acct'));
    }

    public function testAConflictThatEndsInANormalEvaluationConsumesNothing(): void
    {
        $this->tripTheCircuit();

        [$result, , $consumed] = $this->callWith(function (int $commit): void {
            if ($commit === 1) {
                $this->rivalProbes();
                // Healthy for the interval, a later worker closes the circuit: the repeated evaluation runs normally.
                $closing = $this->limiterAt($this->raw, self::PROBE + 121)->limit($this->context('closer', '192.0.2.98'), RateLimitCommand::checkOnly('login_protection'));
                self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $closing->decision);
                self::assertSame('CLOSED', $this->circuitStatus());
            }
        });

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertNotSame('fallback_limit_exceeded', self::cause($result));
        self::assertSame(0, $consumed, 'The final answer is a normal evaluation: the abandoned attempt consumed no fallback allowance.');
        self::assertSame([FailureSignalDTO::TYPE_CB_RECOVERED], $this->signalTypes(), 'The circuit recovered once (by the closing request), announced once.');
    }

    /** @return array<string, array{int}> */
    public static function conflictCounts(): array
    {
        return ['one public call, one conflict' => [1], 'one public call, two conflicts' => [2]];
    }

    #[DataProvider('conflictCounts')]
    public function testIndependentPublicCallsEachConsumeTheirOwnAllowance(int $conflicts): void
    {
        $this->tripTheCircuit();
        $perturbed = 0;
        [, , $consumedFirst] = $this->callWith(function (int $commit) use (&$perturbed, $conflicts): void {
            if ($commit <= $conflicts) {
                $perturbed++;
                self::assertSame(1, $this->raw->execute(['PEXPIRE', $this->circuitKey(), (string) (3_600_000 + $commit)]));
            }
        });
        self::assertSame($conflicts, $perturbed);
        self::assertSame(1, $consumedFirst);

        // Further public calls of the same account are different calls: each consumes one unit until the allowance (3) is spent.
        foreach ([2, 3] as $units) {
            $result = $this->limiterAt($this->raw, self::PROBE + $units)->limit($this->context('acct'), RateLimitCommand::checkOnly('login_protection'));
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
            self::assertSame($units, $this->consumed('acct'));
        }
        $denied = $this->limiterAt($this->raw, self::PROBE + 4)->limit($this->context('acct'), RateLimitCommand::checkOnly('login_protection'));
        self::assertSame('fallback_limit_exceeded', self::cause($denied));
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $denied->decision);
        self::assertSame(600 - (self::PROBE + 4) % 600, $denied->retryAfter, 'Retry-After is the rest of the 600 s window.');
        self::assertSame(4, $this->consumed('acct'), 'The denied call is counted as before.');
    }

    public function testADifferentAccountOnTheSameAddressConsumesItsOwnAccountAllowance(): void
    {
        $this->tripTheCircuit();
        $this->callWith(function (int $commit): void {
            if ($commit === 1) {
                $this->rivalProbes();
            }
        });

        $other = $this->limiterAt($this->raw, self::PROBE + 1)->limit($this->context('someone-else'), RateLimitCommand::checkOnly('login_protection'));

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $other->decision);
        self::assertSame(1, $this->consumed('someone-else'));
        self::assertSame(1, $this->consumed('acct'));
    }

    public function testAPreAndOutAttemptConsumesTwoUnitsOneForEachRealCall(): void
    {
        $this->tripTheCircuit();

        $this->callWith(function (int $commit): void {
            if ($commit === 1) {
                $this->rivalProbes();
            }
        });
        self::assertSame(1, $this->consumed('acct'), 'The `checkOnly` of the attempt, after one conflict.');
        $outcome = $this->limiterAt($this->raw, self::PROBE + 1)->limit($this->context('acct'), RateLimitCommand::recordFailure('login_protection'));

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $outcome->decision);
        self::assertSame(2, $this->consumed('acct'), 'A pre+out attempt uses two fallback units: the outcome call is a second public call.');
    }

    public function testAnActiveReEntryGuardAnswersWithoutTheFallbackAllowance(): void
    {
        $this->tripTheCircuit();
        $key = $this->circuitKey();
        $state = $this->raw->execute(['GET', $key]);
        self::assertIsString($state);
        $decoded = json_decode($state, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $decoded['failClosedUntil'] = self::PROBE + 300;
        $this->raw->execute(['SET', $key, json_encode($decoded, JSON_THROW_ON_ERROR)]);
        $before = $this->snapshot();

        [$result, $commits, $consumed] = $this->callWith(static function (): void {});

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame('FAIL_CLOSED', $result->failureMode);
        self::assertSame(0, $consumed, 'FAIL_CLOSED never consumes a fallback allowance.');
        self::assertSame(0, $commits);
        self::assertSame($before, $this->snapshot());
    }

    public function testAFailOpenPolicyOnAClosedCircuitConsumesTheAllowanceOnce(): void
    {
        $outage = new CallableRedisCommandExecutor(function (array $command): mixed {
            if (OverlayWire::isScript($command, '__ov_result')) {
                throw new BackendFailureException('Test outage.');
            }

            return $this->raw->execute($command);
        });

        $result = $this->limiterAt($outage, self::OPENED)->limit($this->context('api-user'), RateLimitCommand::checkOnly('api_heavy_protection'));

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertSame('FAIL_OPEN', $result->failureMode);
        self::assertSame(1, $this->consumedByAddress('api_heavy_protection'));
    }

    public function testADegradedPolicyOnAnOpenCircuitThatCannotProbeYetConsumesTheAllowanceOnce(): void
    {
        $this->tripTheCircuit();

        $result = $this->limiterAt($this->raw, self::OPENED + 10)->limit($this->context('acct'), RateLimitCommand::checkOnly('login_protection'));

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertSame('DEGRADED_MODE', $result->failureMode);
        self::assertSame(1, $this->consumed('acct'));
    }

    /** @return array<string, array{bool}> */
    public static function recoveryReturnPaths(): array
    {
        return [
            'the recovery step is saved with the request' => [false],
            'the first save failed and the step is repeated on its own (sequential recovery)' => [true],
        ];
    }

    #[DataProvider('recoveryReturnPaths')]
    public function testAnAppliedRecoverySaveKeepsTheSelectedFallbackAnswerWhenLaterCircuitReadsFail(bool $firstSaveFails): void
    {
        $this->tripTheCircuit();
        $saved = false;
        $failedOnce = false;
        $circuitReadsAfterTheSave = 0;
        $executor = new CallableRedisCommandExecutor(function (array $command) use (&$saved, &$failedOnce, &$circuitReadsAfterTheSave, $firstSaveFails): mixed {
            if ($saved && OverlayWire::touchesFamily($command, 'circuit')) {
                $circuitReadsAfterTheSave++;
                throw new BackendFailureException('Connection unavailable after the recovery save.');
            }
            if ($firstSaveFails && ! $failedOnce && OverlayWire::isCommit($command)) {
                $failedOnce = true;
                throw new BackendFailureException('The first save did not reach Redis.');
            }
            $reply = $this->raw->execute($command);
            if (OverlayWire::isCommit($command) && ($reply === 1 || $reply === '1')) {
                $saved = true;
            }

            return $reply;
        });

        $result = $this->limiterAt($executor, self::PROBE)->limit($this->context('acct'), RateLimitCommand::checkOnly('login_protection'));

        self::assertTrue($saved, 'A recovery save was applied.');
        self::assertSame('HALF_OPEN', $this->circuitStatus());
        self::assertNotSame([], $this->raw->execute(['KEYS', '*:probe:*']), 'The probe lease was saved with the state.');
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision, 'The answer chosen before the save stands.');
        self::assertSame('DEGRADED_MODE', $result->failureMode);
        self::assertSame(0, $result->retryAfter);
        self::assertSame(1, $this->consumed('acct'), 'Charged once, from the chosen answer.');
        self::assertSame(0, $circuitReadsAfterTheSave, 'Nothing is resolved or read again after the recovery save: the charge uses the chosen answer.');
        self::assertSame([], $this->signalTypes());
    }

    public function testTheChosenAnswerIsRefusedWithTheDenialMetadataWhenTheAllowanceIsSpent(): void
    {
        $this->tripTheCircuit();
        foreach ([1, 2, 3] as $units) {
            $this->limiterAt($this->raw, self::PROBE + $units)->limit($this->context('acct'), RateLimitCommand::checkOnly('login_protection'));
        }
        self::assertSame(3, $this->consumed('acct'));

        $refused = $this->limiterAt($this->raw, self::PROBE + 4)->limit($this->context('acct'), RateLimitCommand::checkOnly('login_protection'));

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $refused->decision);
        self::assertSame('DEGRADED_MODE', $refused->failureMode);
        self::assertSame('fallback_limit_exceeded', self::cause($refused));
        self::assertSame(600 - (self::PROBE + 4) % 600, $refused->retryAfter);
        self::assertSame(4, $this->consumed('acct'));
    }
}
