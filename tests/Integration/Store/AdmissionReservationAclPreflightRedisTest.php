<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionAdmissionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\DTO\AdmissionSharedConfigDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Exception\AdmissionConfigurationException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\AdmissionFenceControlInterface;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionScenarioBuilder as S;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\RateLimiter\Tests\Support\Redis\StateSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * #281 F-G4-02: direct `reserveAdmission()` (`RESERVE`) and `applyAdmissionOutcome()` (`OUTCOME`) under partial
 * Redis ACL accounts. Both scripts derive their exact write plan from validated state and check every planned
 * command with `redis.acl_check_cmd` before the first write (DEC-042 pattern).
 */
#[Group('integration')]
#[Group('admission-reservation-acl-g4')]
#[Group('php32')]
final class AdmissionReservationAclPreflightRedisTest extends AdmissionRedisGateTestCase
{
    private const NOW = S::NOW;
    private const PASSWORD = 'synthetic-g4-02-password';

    /** @var list<string> */
    private array $users = [];

    protected function tearDown(): void
    {
        if (isset($this->executor)) {
            foreach ($this->users as $user) {
                $this->executor->execute(['ACL', 'DELUSER', $user]);
            }
            $this->executor->execute(['FLUSHDB']);
        }
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function reserveRefusals(): iterable
    {
        yield 'unregistered shared cfg + counter + permit: SET refused' => ['cfg_counter_permit', 'SET'];
        yield 'counter + permit: ZREMRANGEBYSCORE refused' => ['counter_permit', 'ZREMRANGEBYSCORE'];
        yield 'counter + permit: ZADD refused' => ['counter_permit', 'ZADD'];
        yield 'counter + permit: HSET (permit) refused' => ['counter_permit', 'HSET'];
        yield 'counter + permit: EXPIRE refused' => ['counter_permit', 'EXPIRE'];
        yield 'bucket + permit: HSET refused' => ['bucket_permit', 'HSET'];
        yield 'settling set + permit: ZREMRANGEBYSCORE on pending refused' => ['settle_permit', 'ZREMRANGEBYSCORE'];
        yield 'settling set + permit: ZADD on pending refused' => ['settle_permit', 'ZADD'];
        yield 'device + permit: ZADD refused' => ['device_permit', 'ZADD'];
    }

    #[DataProvider('reserveRefusals')]
    public function testDirectReserveAclRefusalLeavesRedisSnapshotUnchanged(string $case, string $denied): void
    {
        $request = $this->buildReserveRequest($case);
        $before = $this->observe();

        $restricted = $this->storeOf($this->account('res-' . $case . '-' . strtolower($denied), [$denied]));
        $message = null;
        try {
            $restricted->reserveAdmission($request, self::NOW);
        } catch (RateLimiterException $e) {
            $message = $e->getMessage();
        }

        self::assertSame($before, $this->observe(), 'Refused direct RESERVE must leave all keys, values, and PEXPIRETIME unchanged');
        self::assertNotNull($message);
        self::assertStringContainsString('NOPERM', $message);
        self::assertStringContainsString(strtolower($denied), strtolower($message));
        self::assertStringNotContainsString('p-g4-', $message);
        self::assertStringNotContainsString('maatify:rate-limiter', $message);
        self::assertStringNotContainsString('acct-', $message);

        // Whole account succeeds cleanly with the exact same request.
        $res = $this->store->reserveAdmission($request, self::NOW);
        self::assertTrue($res->admitted);
        self::assertSame('ISSUED', $this->executor->execute(['HGET', $this->keys->permitKey((string) $request->permit?->id), 'state']));
    }

    /** @return iterable<string, array{0: string, 1: AdmissionOutcomeKindEnum, 2: string}> */
    public static function outcomeRefusals(): iterable
    {
        yield 'failure set: ZREMRANGEBYSCORE refused' => ['failure_set', AdmissionOutcomeKindEnum::FAILURE, 'ZREMRANGEBYSCORE'];
        yield 'failure set: ZADD refused' => ['failure_set', AdmissionOutcomeKindEnum::FAILURE, 'ZADD'];
        yield 'failure set: EXPIRE refused' => ['failure_set', AdmissionOutcomeKindEnum::FAILURE, 'EXPIRE'];
        yield 'settling failure (late ZREM on pending): ZREM refused' => ['settling_failure', AdmissionOutcomeKindEnum::FAILURE, 'ZREM'];
        yield 'success set + counter: ZREM refused' => ['success_set_counter', AdmissionOutcomeKindEnum::SUCCESS, 'ZREM'];
        yield 'success bucket partial refund: HSET refused' => ['bucket_partial', AdmissionOutcomeKindEnum::SUCCESS, 'HSET'];
        yield 'success bucket partial refund: EXPIRE refused' => ['bucket_partial', AdmissionOutcomeKindEnum::SUCCESS, 'EXPIRE'];
        yield 'success bucket full refund: DEL refused' => ['bucket_full', AdmissionOutcomeKindEnum::SUCCESS, 'DEL'];
    }

    #[DataProvider('outcomeRefusals')]
    public function testDirectOutcomeAclRefusalLeavesRedisSnapshotUnchanged(string $case, AdmissionOutcomeKindEnum $kind, string $denied): void
    {
        $scopes = $this->arrangeOutcomeScopes($case);
        $outcome = new AdmissionOutcomeDTO(S::CLASS_NAME, $kind, $scopes);
        $before = $this->observe();

        $restricted = $this->storeOf($this->account('out-' . $case . '-' . strtolower($denied), [$denied]));
        $message = null;
        try {
            $restricted->applyAdmissionOutcome($outcome, self::NOW + 1);
        } catch (RateLimiterException $e) {
            $message = $e->getMessage();
        }

        self::assertSame($before, $this->observe(), 'Refused direct OUTCOME must leave all keys, values, and PEXPIRETIME unchanged');
        self::assertNotNull($message);
        self::assertStringContainsString('NOPERM', $message);
        self::assertStringContainsString(strtolower($denied), strtolower($message));
        self::assertStringNotContainsString('maatify:rate-limiter', $message);
        self::assertStringNotContainsString('acct-', $message);

        // Whole account applies cleanly.
        $this->store->applyAdmissionOutcome($outcome, self::NOW + 2);
        self::assertNotSame($before, $this->observe());
    }

    public function testUnusedCommandsAreNotRequiredAndPendingExpireBranchMatchesState(): void
    {
        // 1. Registered shared cfg: SET is not in RESERVE plan, so an account denied SET still succeeds.
        $cfg = AdmissionSharedConfigDTO::forLimit('a1.cfg.' . str_repeat('c', 64), 1);
        $firstReq = new AdmissionReservationRequestDTO(S::CLASS_NAME, [S::counter('src', 'c:unused-1', 10)], S::permit('p-unused-1', self::NOW + 30), $cfg);
        self::assertTrue($this->store->reserveAdmission($firstReq, self::NOW)->admitted);

        $noSetAccount = $this->storeOf($this->account('no-set', ['SET']));
        $secondReq = new AdmissionReservationRequestDTO(S::CLASS_NAME, [S::counter('src', 'c:unused-2', 10)], S::permit('p-unused-2', self::NOW + 30), $cfg);
        self::assertTrue($noSetAccount->reserveAdmission($secondReq, self::NOW)->admitted);

        // 2. Existing device (s.exists == true) with no other writes: empty write plan succeeds even when ZADD/EXPIRE are denied.
        $devScope = S::device('dev', 'd:existing', 5, 'dev-1');
        self::assertTrue($this->store->reserveAdmission(new AdmissionReservationRequestDTO(S::CLASS_NAME, [$devScope]), self::NOW)->admitted);
        $noWriteAccount = $this->storeOf($this->account('no-zadd-expire', ['ZREMRANGEBYSCORE', 'ZADD', 'EXPIRE']));
        self::assertTrue($noWriteAccount->reserveAdmission(new AdmissionReservationRequestDTO(S::CLASS_NAME, [$devScope]), self::NOW)->admitted);

        // 3. Pending ZSET with surviving member and TTL >= life: EXPIRE on pending is NOT issued and original TTL is not shortened.
        $pendingKey = $this->keys->pendingKey(S::CLASS_NAME, 's:pend-keep');
        $this->executor->execute(['ZADD', $pendingKey, (string) (self::NOW + 1000), 'other-member']);
        $this->executor->execute(['EXPIRE', $pendingKey, '1000']);
        $origPttl = $this->executor->execute(['PTTL', $pendingKey]);
        self::assertIsInt($origPttl);
        self::assertGreaterThan(900_000, $origPttl);

        $settlesScope = new AdmissionScopeDTO('s', \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::SET_SIZE, 's:pend-keep', 4, 600, 's:pend-keep', 'p-pend-keep', null, false, true);
        self::assertTrue($this->store->reserveAdmission(S::withPermit(S::permit('p-pend-keep', self::NOW + 30), $settlesScope), self::NOW)->admitted);
        $afterPttl = $this->executor->execute(['PTTL', $pendingKey]);
        self::assertIsInt($afterPttl);
        self::assertGreaterThan(900_000, $afterPttl, 'Surviving pending member with longer TTL must not be shortened');

        // 4. Pending ZSET where all existing members are expired (<= now - ttl): ZREMRANGEBYSCORE deletes last member, so EXPIRE IS required.
        $expiredPendingKey = $this->keys->pendingKey(S::CLASS_NAME, 's:pend-recreate');
        $this->executor->execute(['ZADD', $expiredPendingKey, (string) (self::NOW - 700), 'stale-member']);
        $this->executor->execute(['EXPIRE', $expiredPendingKey, '1000']);
        $recreateScope = new AdmissionScopeDTO('s', \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::SET_SIZE, 's:pend-recreate', 4, 600, 's:pend-recreate', 'p-pend-recreate', null, false, true);
        $recreateReq = S::withPermit(S::permit('p-pend-recreate', self::NOW + 30), $recreateScope);
        $beforeRecreate = $this->observe();

        $noExpire = $this->storeOf($this->account('no-expire-recreate', ['EXPIRE']));
        $recreateErr = null;
        try {
            $noExpire->reserveAdmission($recreateReq, self::NOW);
        } catch (RateLimiterException $e) {
            $recreateErr = $e->getMessage();
        }
        self::assertNotNull($recreateErr);
        self::assertStringContainsString('EXPIRE', $recreateErr);
        self::assertSame($beforeRecreate, $this->observe(), 'Refused EXPIRE after last-member prune must leave pending ZSET unchanged');

        self::assertTrue($this->store->reserveAdmission($recreateReq, self::NOW)->admitted);
        $recreatedTtl = $this->executor->execute(['TTL', $expiredPendingKey]);
        self::assertSame(630, $recreatedTtl, 'Recreated pending ZSET must receive life=630 TTL');
    }

    public function testNonAclRefusalsAndPublicOverlayBehaviorArePreserved(): void
    {
        // 1. Gate refusal, config mismatch, and duplicate permit write nothing and never write previous.
        $cfg1 = AdmissionSharedConfigDTO::forLimit('a1.cfg.' . str_repeat('d', 64), 1);
        $cfg2 = AdmissionSharedConfigDTO::forLimit('a1.cfg.' . str_repeat('d', 64), 2);
        $c1 = new AdmissionScopeDTO('src', \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::COUNTER, 'c:ctrl', 1, 600, 'c:ctrl-prev', 'm-1');
        self::assertTrue($this->store->reserveAdmission(new AdmissionReservationRequestDTO(S::CLASS_NAME, [$c1], S::permit('p-ctrl-1', self::NOW + 30), $cfg1), self::NOW)->admitted);
        $snap = $this->observe();

        // Gate refusal
        $refused = $this->store->reserveAdmission(new AdmissionReservationRequestDTO(S::CLASS_NAME, [new AdmissionScopeDTO('src', \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::COUNTER, 'c:ctrl', 1, 600, 'c:ctrl-prev', 'm-2')], S::permit('p-ctrl-2', self::NOW + 30), $cfg1), self::NOW);
        self::assertFalse($refused->admitted);
        self::assertSame($snap, $this->observe());

        // Duplicate permit
        $dup = $this->store->reserveAdmission(new AdmissionReservationRequestDTO(S::CLASS_NAME, [S::counter('src', 'c:ctrl-other', 5)], S::permit('p-ctrl-1', self::NOW + 30), $cfg1), self::NOW);
        self::assertFalse($dup->admitted);
        self::assertSame('permit_exists', $dup->refusedScope);
        self::assertSame($snap, $this->observe());

        // Config mismatch
        $mismatchThrew = false;
        try {
            $this->store->reserveAdmission(new AdmissionReservationRequestDTO(S::CLASS_NAME, [S::counter('src', 'c:ctrl-other', 5)], S::permit('p-ctrl-3', self::NOW + 30), $cfg2), self::NOW);
        } catch (AdmissionConfigurationException) {
            $mismatchThrew = true;
        }
        self::assertTrue($mismatchThrew);
        self::assertSame($snap, $this->observe());

        // 2. Public runtime checkOnly inside request overlay: whole ACL succeeds; missing HSET fails at DEC-042 commit with unchanged keyspace.
        $rlConfig = new RateLimiterConfig('outer-acl', 'fp-acl', 'prod', bindingKeySecret: 'bind-acl', admissionTrustDomain: 'acl.example.test', admissionStateSecret: str_repeat('s', 32));
        $wholeStore = $this->storeOf($this->account('overlay-whole', []));
        $rtWhole = RateLimiterBuilder::fromFullCapabilityStore($rlConfig, $wholeStore, new RecordingFailureSignalEmitter())
            ->withClock(new FixedClock('@' . self::NOW))
            ->withPolicy(new LoginProtectionAdmissionPolicy())
            ->build();
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $rtWhole);
        $rtWhole->openAdmissionFence('login');

        $ctx = new RateLimitContextDTO('198.51.100.88', 'Mozilla/5.0 Chrome/126', 'acct-overlay-1');
        $ok = $rtWhole->limit($ctx, RateLimitCommand::checkOnly('login_protection'));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $ok->decision);
        self::assertNotNull($ok->metadata?->permit);

        $beforeDenyOverlay = $this->observe();
        $denyHsetStore = $this->storeOf($this->account('overlay-deny-hset', ['HSET']));
        $rtDeny = RateLimiterBuilder::fromFullCapabilityStore($rlConfig, $denyHsetStore, new RecordingFailureSignalEmitter())
            ->withClock(new FixedClock('@' . self::NOW))
            ->withPolicy(new LoginProtectionAdmissionPolicy())
            ->build();
        $commitErr = null;
        try {
            $rtDeny->limit(new RateLimitContextDTO('198.51.100.89', 'Mozilla/5.0 Chrome/126', 'acct-overlay-2'), RateLimitCommand::checkOnly('login_protection'));
        } catch (RateLimiterException $e) {
            $commitErr = $e->getMessage();
        }
        self::assertNotNull($commitErr);
        self::assertStringContainsString('NOPERM request commit', $commitErr);
        self::assertSame($beforeDenyOverlay, $this->observe(), 'DEC-042 commit refusal must leave Redis keyspace unchanged');
    }

    private function buildReserveRequest(string $case): AdmissionReservationRequestDTO
    {
        $cfg = AdmissionSharedConfigDTO::forLimit('a1.cfg.' . str_repeat('e', 64), 1);

        return match ($case) {
            'cfg_counter_permit' => new AdmissionReservationRequestDTO(S::CLASS_NAME, [S::counter('src', 'c:g4-' . $case, 10)], S::permit('p-g4-' . $case, self::NOW + 30), $cfg),
            'counter_permit' => S::withPermit(S::permit('p-g4-' . $case, self::NOW + 30), S::counter('src', 'c:g4-' . $case, 10)),
            'bucket_permit' => S::withPermit(S::permit('p-g4-' . $case, self::NOW + 30), S::bucket('tok', 'b:g4-' . $case, 3, 60)),
            'settle_permit' => S::withPermit(S::permit('p-g4-' . $case, self::NOW + 30), new AdmissionScopeDTO('s', \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::SET_SIZE, 's:g4-' . $case, 4, 600, 's:g4-' . $case, 'm-g4', null, false, true)),
            'device_permit' => S::withPermit(S::permit('p-g4-' . $case, self::NOW + 30), S::device('dev', 'd:g4-' . $case, 5, 'dev-g4')),
            default => throw new \InvalidArgumentException("Unknown case {$case}"),
        };
    }

    /** @return list<AdmissionScopeDTO> */
    private function arrangeOutcomeScopes(string $case): array
    {
        if ($case === 'failure_set') {
            return [S::setSize('ufail', 's:g4-out-fail', 4, 'acct-g4-1')];
        }
        if ($case === 'settling_failure') {
            $scope = new AdmissionScopeDTO('pfail', \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::SET_SIZE, 's:g4-out-settle', 4, 600, 's:g4-out-settle', 'm-g4-settle', null, false, true);
            self::assertTrue($this->store->reserveAdmission(S::withPermit(S::permit('p-g4-arr-settle', self::NOW + 30), $scope), self::NOW)->admitted);

            return [$scope];
        }
        if ($case === 'success_set_counter') {
            $set = S::setSize('uset', 's:g4-out-succ', 4, 'acct-g4-2');
            $cnt = S::counter('src', 'c:g4-out-succ', 10, member: 'm-g4-cnt');
            self::assertTrue($this->store->reserveAdmission(new AdmissionReservationRequestDTO(S::CLASS_NAME, [$set, $cnt]), self::NOW)->admitted);

            return [$set, $cnt];
        }
        if ($case === 'bucket_partial') {
            $b = S::bucket('tok', 'b:g4-partial', 3, 60);
            self::assertTrue($this->store->reserveAdmission(new AdmissionReservationRequestDTO(S::CLASS_NAME, [$b]), self::NOW)->admitted);
            self::assertTrue($this->store->reserveAdmission(new AdmissionReservationRequestDTO(S::CLASS_NAME, [$b]), self::NOW)->admitted);

            return [$b];
        }
        $b = S::bucket('tok', 'b:g4-full', 3, 60);
        self::assertTrue($this->store->reserveAdmission(new AdmissionReservationRequestDTO(S::CLASS_NAME, [$b]), self::NOW)->admitted);

        return [$b];
    }

    /** @return array<string, string> */
    private function observe(): array
    {
        return StateSnapshot::all($this->executor);
    }

    /** @param list<string> $denied */
    private function account(string $name, array $denied): RespRedisCommandExecutor
    {
        $user = 'aclg4-' . substr(hash('sha256', $name), 0, 16);
        $rules = array_map(static fn(string $command): string => '-' . strtolower($command), $denied);
        $this->executor->execute(['ACL', 'SETUSER', $user, 'reset', 'on', '>' . self::PASSWORD, '~*', '+@all', ...$rules]);
        $this->users[] = $user;
        $connection = new RespRedisCommandExecutor($this->host, $this->port, RespRedisCommandExecutor::MODE_EVAL);
        self::assertSame('OK', $connection->execute(['AUTH', $user, self::PASSWORD]));

        return $connection;
    }

    private function storeOf(RespRedisCommandExecutor $connection): RedisFullCapabilityStore
    {
        return new RedisFullCapabilityStore($connection, self::NAMESPACE);
    }
}
