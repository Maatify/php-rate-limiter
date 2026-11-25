<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionAdmissionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\FailureSignalDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisAdmissionOperations;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\AdmissionFenceControlInterface;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Tests\Support\Admission\PublicRedisAdmissionFixture;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\OverlayWire;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #258: the outcome of an admission policy while the circuit is recovering, through the public builder and the official Redis store.
 *
 * DEC-036: the permit claim and the admission effects of the outcome are one atomic operation. DEC-037 item 7: that operation is an
 * independent command that runs before the evaluation and stays claimed when the evaluation is then rejected; the recovery step
 * (probe lease and state change) is part of the request and is saved with it, or not at all; the transition signals follow the save.
 *
 * The recovering circuit is reached the way a fleet reaches it: a worker whose Host clock is behind trips and half-opens the circuit
 * (the documented timings, 300 s open and 120 s healthy, cannot elapse inside the 30 s permit deadline on a single monotonic clock).
 * Every scenario runs against a CLOSED circuit as the control.
 */
#[Group('integration')]
#[Group('admission-recovery-258')]
#[Group('php32')]
final class AdmissionOutcomeDuringRecoveryRedisTest extends TestCase
{
    private const T0 = 1_000_000;

    private const NS = 'recovery-258';

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
    }

    /** @return array<string, array{string}> */
    public static function circuits(): array
    {
        return ['closed circuit (control)' => ['closed'], 'recovering circuit, probe eligible' => ['recovering']];
    }

    private static function family(string $key): string
    {
        if (preg_match('/\}:adm:([a-z]+):/', $key, $m) === 1) {
            return 'adm:' . $m[1];
        }

        return preg_match('/^maatify:rate-limiter:(?:v1|e1):[0-9a-f]{64}:([a-z0-9_-]+):/', $key, $m) === 1 ? $m[1] : 'other';
    }

    /** @return array<string, string> key => exact DUMP and PEXPIRETIME read from outside Lua */
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

    /**
     * @param array<string, string> $before
     * @param array<string, string> $after
     * @return list<string> the families with an added, changed or removed key
     */
    private static function changedFamilies(array $before, array $after): array
    {
        $changed = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changed[self::family($key)] = true;
            }
        }
        $families = array_keys($changed);
        sort($families);

        return $families;
    }

    /**
     * @param array<string, string> $state
     * @return array<string, string> the keys of one family
     */
    private static function only(array $state, string $family): array
    {
        return array_filter($state, static fn(string $key): bool => self::family($key) === $family, ARRAY_FILTER_USE_KEY);
    }

    private function runtime(CallableRedisCommandExecutor|RespRedisCommandExecutor $executor, int $now): CompositeRateLimiterRuntimeInterface
    {
        return RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('redis-gate-key', 'redis-gate-fingerprint', 'test', bindingKeySecret: PublicRedisAdmissionFixture::BINDING_SECRET),
            new RedisFullCapabilityStore($executor, self::NS),
            $this->signals,
        )->withClock(new FixedClock('@' . $now))->withPolicy(new LoginProtectionAdmissionPolicy())->build();
    }

    private function context(string $account): RateLimitContextDTO
    {
        return new RateLimitContextDTO(ip: '192.0.2.10', ua: 'redis-gate', accountId: $account);
    }

    private function failureOutcome(string $permit): RateLimitCommand
    {
        return new RateLimitCommand('login_protection', 1, false, true, false, $permit);
    }

    private function successOutcome(string $permit): RateLimitCommand
    {
        return new RateLimitCommand('login_protection', 1, false, false, true, $permit);
    }

    private function claimScript(): string
    {
        $script = (new \ReflectionClass(RedisAdmissionOperations::class))->getConstant('CLAIM_WITH_OUTCOME');
        self::assertIsString($script);

        return $script;
    }

    private function reserveScript(): string
    {
        $script = (new \ReflectionClass(RedisAdmissionOperations::class))->getConstant('RESERVE');
        self::assertIsString($script);

        return $script;
    }

    /** The Host clock of a worker that is behind trips and half-opens the circuit; the circuit is then probe-eligible at T0 + 10. */
    private function recoverTheCircuitOnALaggingWorker(): void
    {
        $outage = new CallableRedisCommandExecutor(function (array $command): mixed {
            if (OverlayWire::isScript($command, '__ov_result')) {
                throw new BackendFailureException('Test outage.');
            }

            return $this->raw->execute($command);
        });
        $lagging = self::T0 - 1000;
        for ($i = 0; $i < 3; $i++) {
            $this->runtime($outage, $lagging + $i)->limit($this->context('outage-' . $i), RateLimitCommand::checkOnly('login_protection'));
        }
        $this->runtime($this->raw, $lagging + 400)->limit($this->context('first-probe'), RateLimitCommand::checkOnly('login_protection'));
        self::assertSame('HALF_OPEN', $this->circuitStatus(), 'The circuit is half-open: the next eligible request probes it.');
        $this->signals->clear();
    }

    private function circuitStatus(): string
    {
        foreach (array_keys($this->snapshot()) as $key) {
            if (self::family($key) === 'circuit') {
                $json = $this->raw->execute(['GET', $key]);
                self::assertIsString($json);
                $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
                self::assertIsArray($decoded);
                self::assertIsString($decoded['status']);

                return $decoded['status'];
            }
        }

        return 'ABSENT';
    }

    /** @return string the permit of an allowed `checkOnly` of the account `acct`, issued at T0 while the circuit is closed */
    private function issuePermit(): string
    {
        $runtime = $this->runtime($this->raw, self::T0);
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $runtime);
        $runtime->openAdmissionFence('login');
        $runtime->limit($this->context('seed'), RateLimitCommand::checkOnly('login_protection'));
        $permit = $runtime->limit($this->context('acct'), RateLimitCommand::checkOnly('login_protection'))->metadata?->permit;
        self::assertNotNull($permit);

        return $permit;
    }

    private function prepare(string $circuit): string
    {
        $permit = $this->issuePermit();
        if ($circuit === 'recovering') {
            $this->recoverTheCircuitOnALaggingWorker();
        }

        return $permit;
    }

    /** @return array<string, string> the permit record of `acct` as stored (state and outcome) */
    private function permitRecord(): array
    {
        $record = [];
        foreach (array_keys($this->snapshot()) as $key) {
            if (self::family($key) !== 'adm:permit') {
                continue;
            }
            $fields = $this->raw->execute(['HGETALL', $key]);
            self::assertIsArray($fields);
            $map = [];
            for ($i = 0; $i < count($fields); $i += 2) {
                $name = $fields[$i];
                $value = $fields[$i + 1] ?? null;
                self::assertTrue(is_string($name) && is_string($value));
                $map[$name] = $value;
            }
            if (($map['subject'] ?? '') === 'acct') {
                $record = $map;
            }
        }

        return $record;
    }

    /** @return CallableRedisCommandExecutor an executor that counts claims and saves and can reject the evaluation after the claim */
    private function observing(int &$claims, int &$commits, ?\Throwable $rejectEvaluation = null): CallableRedisCommandExecutor
    {
        $claim = $this->claimScript();

        return new CallableRedisCommandExecutor(function (array $command) use (&$claims, &$commits, $claim, $rejectEvaluation): mixed {
            if (OverlayWire::isScript($command, $claim)) {
                $claims++;
            }
            if (OverlayWire::isCommit($command)) {
                $commits++;
            }
            if ($rejectEvaluation !== null && OverlayWire::isScript($command, '__ov_result') && OverlayWire::touchesFamily($command, 'distinct-meta')) {
                throw $rejectEvaluation;
            }

            return $this->raw->execute($command);
        });
    }

    /**
     * The score and budget state of the account as stored (exact field maps read from outside Lua), the effects of the score
     * pipeline of a recorded failure.
     *
     * @return list<array{string, array<string, string>}> family and fields, in a stable order
     */
    private function recorded(): array
    {
        $recorded = [];
        foreach (array_keys($this->snapshot()) as $key) {
            $family = self::family($key);
            if ($family !== 'score' && $family !== 'budget') {
                continue;
            }
            $fields = $this->raw->execute(['HGETALL', $key]);
            self::assertIsArray($fields);
            $map = [];
            for ($i = 0; $i < count($fields); $i += 2) {
                $name = $fields[$i];
                $value = $fields[$i + 1] ?? null;
                self::assertTrue(is_string($name) && is_string($value));
                $map[$name] = $value;
            }
            ksort($map);
            $recorded[] = [$family, $map];
        }
        usort($recorded, static fn(array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));

        return $recorded;
    }

    /** The one recorded failure of `acct` at T0 + 10: two score hashes (5 and the generation-bound 3) and a budget count of 1; a missing or a repeated recording differs. */
    private function assertTheFailureWasRecordedOnce(): void
    {
        $at = (string) (self::T0 + 10);
        $expected = [
            ['budget', ['count' => '1', 'epochDuration' => '86400', 'epochStart' => $at]],
            ['score', ['generation' => '1', 'expiresAt' => (string) (self::T0 + 10 + 86400), 'updatedAt' => $at, 'value' => '3']],
            ['score', ['updatedAt' => $at, 'value' => '5']],
        ];
        foreach ($expected as &$entry) {
            ksort($entry[1]);
        }
        unset($entry);
        usort($expected, static fn(array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
        self::assertSame($expected, $this->recorded(), 'The failure of the account was recorded exactly once.');
    }

    /** @return string|null the exact PEXPIRETIME reply (a decimal string where it exceeds the native integer), null for no expiry */
    private function expiry(string $key): ?string
    {
        $reply = $this->raw->execute(['PEXPIRETIME', $key]);
        self::assertTrue(is_int($reply) || is_string($reply));

        return (string) $reply !== '' && ctype_digit((string) $reply) && (string) $reply !== '0' ? (string) $reply : null;
    }

    /** @return list<string> */
    private function signalTypes(): array
    {
        return array_values(array_map(static fn(FailureSignalDTO $signal): string => $signal->type, $this->signals->getEmitted()));
    }

    #[DataProvider('circuits')]
    public function testARejectedEvaluationAfterTheOutcomeKeepsTheClaimAndSavesNoRecovery(string $circuit): void
    {
        $permit = $this->prepare($circuit);
        $before = $this->snapshot();
        $claims = $commits = 0;
        $rejection = new RateLimiterException('malformed bounded metadata');

        try {
            $this->runtime($this->observing($claims, $commits, $rejection), self::T0 + 10)->limit($this->context('acct'), $this->failureOutcome($permit));
            self::fail('The classified rejection must reach the caller.');
        } catch (RateLimiterException $caught) {
            self::assertSame($rejection, $caught);
        }

        $after = $this->snapshot();
        self::assertSame(1, $claims, 'One claim reached Redis.');
        self::assertSame(0, $commits, 'The rejected evaluation saved nothing.');
        $record = $this->permitRecord();
        self::assertSame([], $this->recorded(), 'The rejected evaluation recorded no score.');
        self::assertSame('CLAIMED', $record['state'] ?? null, 'DEC-036/037: the claim stays when the evaluation after it is rejected.');
        self::assertSame('failure', $record['outcome'] ?? null);
        $changed = self::changedFamilies($before, $after);
        self::assertSame([], array_values(array_filter($changed, static fn(string $family): bool => ! str_starts_with($family, 'adm:'))), 'Only the permit and its admission state changed: ' . implode(',', $changed));
        self::assertContains('adm:permit', $changed);
        self::assertSame(self::only($before, 'circuit'), self::only($after, 'circuit'), 'The circuit state is as it was.');
        self::assertSame(self::only($before, 'probe'), self::only($after, 'probe'), 'The probe lease is as it was.');
        self::assertSame([], $this->signalTypes());
        if ($circuit === 'recovering') {
            self::assertSame('HALF_OPEN', $this->circuitStatus());
        }
    }

    /** @return array<string, array{string, string}> */
    public static function circuitsAndKinds(): array
    {
        return [
            'closed, failure' => ['closed', 'failure'],
            'closed, success' => ['closed', 'success'],
            'recovering, failure' => ['recovering', 'failure'],
            'recovering, success' => ['recovering', 'success'],
        ];
    }

    #[DataProvider('circuitsAndKinds')]
    public function testASuccessfulEvaluationSavesTheOutcomeEffectsOnceAndTheRecoveryWithTheRequest(string $circuit, string $kind): void
    {
        $permit = $this->prepare($circuit);
        $before = $this->snapshot();
        $claims = $commits = 0;
        $outcome = $kind === 'failure' ? $this->failureOutcome($permit) : $this->successOutcome($permit);

        $this->runtime($this->observing($claims, $commits), self::T0 + 10)->limit($this->context('acct'), $outcome);

        $after = $this->snapshot();
        self::assertSame(1, $claims, 'One claim.');
        if ($kind === 'failure') {
            $this->assertTheFailureWasRecordedOnce();
        }
        if ($circuit === 'recovering') {
            self::assertSame(1, $commits, 'The recovery step is saved with the request, in one save.');
        } else {
            self::assertLessThanOrEqual(1, $commits, 'At most one save (a request that changes nothing sends none).');
        }
        $record = $this->permitRecord();
        self::assertSame('CLAIMED', $record['state'] ?? null);
        self::assertSame($kind, $record['outcome'] ?? null);
        $changed = self::changedFamilies($before, $after);
        self::assertContains('adm:permit', $changed);
        if ($circuit === 'recovering') {
            self::assertSame('CLOSED', $this->circuitStatus(), 'The probe closed the circuit with the request.');
            self::assertSame([FailureSignalDTO::TYPE_CB_RECOVERED], $this->signalTypes(), 'Announced once, after the save.');
        } else {
            self::assertSame([], $this->signalTypes());
        }
    }

    #[DataProvider('circuits')]
    public function testADuplicateOutcomeRepeatsNeitherTheAdmissionEffectsNorTheScore(string $circuit): void
    {
        $permit = $this->prepare($circuit);
        $claims = $commits = 0;
        try {
            $this->runtime($this->observing($claims, $commits, new RateLimiterException('malformed bounded metadata')), self::T0 + 10)->limit($this->context('acct'), $this->failureOutcome($permit));
            self::fail('The classified rejection must reach the caller.');
        } catch (RateLimiterException) {
        }
        self::assertSame('CLAIMED', $this->permitRecord()['state'] ?? null);
        $afterFirst = $this->snapshot();

        $claims = $commits = 0;
        $second = $this->runtime($this->observing($claims, $commits), self::T0 + 11)->limit($this->context('acct'), $this->failureOutcome($permit));

        $afterSecond = $this->snapshot();
        self::assertSame('permit_duplicate', $second->metadata?->cause);
        self::assertSame([], $this->recorded(), 'A duplicate does not re-enter the score pipeline.');
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $second->decision);
        $changed = self::changedFamilies($afterFirst, $afterSecond);
        self::assertSame([], array_values(array_filter($changed, static fn(string $family): bool => ! in_array($family, ['circuit', 'probe'], true))), 'A duplicate re-applies no admission effect and does not re-enter the score pipeline: ' . implode(',', $changed));
    }

    #[DataProvider('circuits')]
    public function testAConflictingOutcomeIsRefusedAfterTheFirstClaim(string $circuit): void
    {
        $permit = $this->prepare($circuit);
        $claims = $commits = 0;
        try {
            $this->runtime($this->observing($claims, $commits, new RateLimiterException('malformed bounded metadata')), self::T0 + 10)->limit($this->context('acct'), $this->failureOutcome($permit));
            self::fail('The classified rejection must reach the caller.');
        } catch (RateLimiterException) {
        }
        $afterFirst = $this->snapshot();

        $second = $this->runtime($this->raw, self::T0 + 11)->limit($this->context('acct'), $this->successOutcome($permit));

        self::assertSame('permit_conflict', $second->metadata?->cause);
        self::assertSame([], $this->recorded(), 'A conflicting outcome records no score.');
        $changed = self::changedFamilies($afterFirst, $this->snapshot());
        self::assertSame([], array_values(array_filter($changed, static fn(string $family): bool => ! in_array($family, ['circuit', 'probe'], true))), implode(',', $changed));
        self::assertSame('failure', $this->permitRecord()['outcome'] ?? null, 'The first outcome stands.');
    }

    /** @return array<string, array{bool}> */
    public static function conflicts(): array
    {
        return [
            'another request closes the circuit first' => [true],
            'an unrelated key the request read changes' => [false],
        ];
    }

    #[DataProvider('conflicts')]
    public function testARedisConflictRecalculatesTheEvaluationClaimsOnceAndRecordsTheScoreOnce(bool $rivalClosesTheCircuit): void
    {
        $permit = $this->prepare('recovering');
        $beforeRequest = $this->snapshot();
        $claims = $commits = 0;
        $claim = $this->claimScript();
        $racing = new CallableRedisCommandExecutor(function (array $command) use (&$claims, &$commits, $claim, $rivalClosesTheCircuit, $beforeRequest): mixed {
            if (OverlayWire::isScript($command, $claim)) {
                $claims++;
            }
            if (OverlayWire::isCommit($command)) {
                $commits++;
                if ($commits === 1) {
                    if ($rivalClosesTheCircuit) {
                        // Another request probes first and closes the circuit: what this request read has changed.
                        $inner = $this->runtime($this->raw, self::T0 + 10)->limit($this->context('rival'), RateLimitCommand::checkOnly('login_protection'));
                        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $inner->decision);
                    } else {
                        // The expiry of a key the request read changes: the circuit is untouched, the fingerprint differs.
                        $keys = array_values(array_filter(
                            OverlayWire::keys($command),
                            fn(string $key): bool => ! in_array(self::family($key), ['circuit', 'probe', 'score', 'budget'], true) && ! str_starts_with(self::family($key), 'adm:') && $this->expiry($key) !== null,
                        ));
                        self::assertNotSame([], $keys, 'The save names a key the request read and left unchanged.');
                        $was = $this->expiry($keys[0]);
                        self::assertSame(1, $this->raw->execute(['PEXPIRE', $keys[0], '123456789']));
                        self::assertNotSame($was, $this->expiry($keys[0]));
                    }
                } elseif ($commits === 2 && ! $rivalClosesTheCircuit) {
                    // The attempt abandoned for the conflict saved nothing: not the claim's effects on the score, not the recovery step.
                    self::assertSame([], $this->recorded(), 'The abandoned attempt recorded no score.');
                    self::assertSame(self::only($beforeRequest, 'circuit'), self::only($this->snapshot(), 'circuit'));
                    self::assertSame(self::only($beforeRequest, 'probe'), self::only($this->snapshot(), 'probe'));
                }
            }

            return $this->raw->execute($command);
        });

        $result = $this->runtime($racing, self::T0 + 10)->limit($this->context('acct'), $this->failureOutcome($permit));

        self::assertSame(2, $commits, 'The conflict was followed by exactly one more evaluation and its save.');
        self::assertSame(1, $claims, 'The claim is one atomic operation of its own: a recalculated request does not claim again.');
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertNull($result->metadata?->cause, 'The recalculated request answers as the recorded failure, not as a refused claim.');
        self::assertSame(0, $result->retryAfter);
        self::assertSame('CLAIMED', $this->permitRecord()['state'] ?? null);
        self::assertSame('failure', $this->permitRecord()['outcome'] ?? null);
        $this->assertTheFailureWasRecordedOnce();
        self::assertSame('CLOSED', $this->circuitStatus(), $rivalClosesTheCircuit ? 'The rival closed it.' : 'The final evaluation saved the recovery transition with the request.');
        self::assertSame(
            1,
            count(array_filter($this->signalTypes(), static fn(string $type): bool => $type === FailureSignalDTO::TYPE_CB_RECOVERED)),
            'The circuit recovered once, announced once.',
        );
    }

    /** @return array<string, array{string, bool}> */
    public static function lostMessages(): array
    {
        return [
            'closed, request lost' => ['closed', false],
            'closed, reply lost' => ['closed', true],
            'recovering, request lost' => ['recovering', false],
            'recovering, reply lost' => ['recovering', true],
        ];
    }

    #[DataProvider('lostMessages')]
    public function testALostClaimRequestLeavesThePermitIssuedAndALostReplyLeavesItClaimedOnce(string $circuit, bool $replyLost): void
    {
        $permit = $this->prepare($circuit);
        $before = $this->snapshot();
        $claim = $this->claimScript();
        $claims = 0;
        $lossy = new CallableRedisCommandExecutor(function (array $command) use ($claim, &$claims, $replyLost): mixed {
            if (OverlayWire::isScript($command, $claim)) {
                $claims++;
                if (! $replyLost) {
                    throw new BackendFailureException('Request lost.');
                }
                $this->raw->execute($command);
                throw new BackendFailureException('Reply lost.');
            }

            return $this->raw->execute($command);
        });

        $this->runtime($lossy, self::T0 + 10)->limit($this->context('acct'), $this->failureOutcome($permit));

        self::assertSame(1, $claims, 'One attempt: a lost message is not replayed.');
        $record = $this->permitRecord();
        $changed = self::changedFamilies($before, $this->snapshot());
        if ($replyLost) {
            self::assertSame('CLAIMED', $record['state'] ?? null, 'The operation completed in Redis: the claim and its admission effects are stored.');
            self::assertContains('adm:permit', $changed);
            self::assertNotContains('score', $changed, 'Score recording is absent after an unknown outcome (DEC-036 consequences); a retry does not re-enter it.');
        } else {
            self::assertSame('ISSUED', $record['state'] ?? null, 'Nothing reached Redis: the permit can still be claimed.');
            self::assertNotContains('adm:permit', $changed);
        }
    }

    #[DataProvider('circuits')]
    public function testARejectedOrCompletedAdmissionCheckOnlyKeepsTheRequestAtomicity(string $circuit): void
    {
        $this->prepare($circuit);
        $before = $this->snapshot();
        $this->signals->clear();
        $rejected = new CallableRedisCommandExecutor(function (array $command): mixed {
            if (OverlayWire::isScript($command, $this->reserveScript())) {
                throw new RateLimiterException('malformed admission state: test');
            }

            return $this->raw->execute($command);
        });
        try {
            $this->runtime($rejected, self::T0 + 10)->limit($this->context('new-account'), RateLimitCommand::checkOnly('login_protection'));
            self::fail('The classified rejection must reach the caller.');
        } catch (RateLimiterException) {
        }
        self::assertSame($before, $this->snapshot(), 'A rejected reservation saves neither the evaluation, nor a permit, nor the probe lease, nor the transition.');
        self::assertSame([], $this->signalTypes());

        $commits = $claims = 0;
        $result = $this->runtime($this->observing($claims, $commits), self::T0 + 10)->limit($this->context('new-account'), RateLimitCommand::checkOnly('login_protection'));

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertNotNull($result->metadata?->permit);
        self::assertSame(1, $commits, 'Evaluation, reservation and recovery step are one save.');
        self::assertSame($circuit === 'recovering' ? [FailureSignalDTO::TYPE_CB_RECOVERED] : [], $this->signalTypes());
    }
}
