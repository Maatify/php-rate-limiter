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

    /** @return iterable<string, array{0: string, 1: string, 2: int, 3: int}> */
    public static function reserveRefusals(): iterable
    {
        yield 'unregistered shared cfg + counter + permit: SET refused' => ['cfg_counter_permit', 'SET', 1, 6];
        yield 'unregistered shared cfg + counter + permit: HSET (permit) refused' => ['cfg_counter_permit', 'HSET', 5, 6];
        yield 'counter + permit: ZREMRANGEBYSCORE refused' => ['counter_permit', 'ZREMRANGEBYSCORE', 1, 5];
        yield 'counter + permit: ZADD refused' => ['counter_permit', 'ZADD', 2, 5];
        yield 'counter + permit: HSET (permit) refused' => ['counter_permit', 'HSET', 4, 5];
        yield 'counter + permit: EXPIRE refused' => ['counter_permit', 'EXPIRE', 3, 5];
        yield 'bucket + permit: HSET refused' => ['bucket_permit', 'HSET', 1, 4];
        yield 'settling set + permit: ZREMRANGEBYSCORE on pending refused' => ['settle_permit', 'ZREMRANGEBYSCORE', 1, 5];
        yield 'settling set + permit: ZADD on pending refused' => ['settle_permit', 'ZADD', 2, 5];
        yield 'device + permit: ZADD refused' => ['device_permit', 'ZADD', 2, 5];
    }

    #[DataProvider('reserveRefusals')]
    public function testDirectReserveAclRefusalLeavesRedisSnapshotUnchanged(string $case, string $denied, int $step, int $total): void
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
        self::assertSame("NOPERM admission reservation: the account may not run {$denied} (plan step {$step} of {$total})", $message);
        self::assertStringNotContainsString('p-g4-', $message);
        self::assertStringNotContainsString('maatify:rate-limiter', $message);
        self::assertStringNotContainsString('acct-', $message);

        // Whole account succeeds cleanly with the exact same request.
        $res = $this->store->reserveAdmission($request, self::NOW);
        self::assertTrue($res->admitted);
        self::assertSame('ISSUED', $this->executor->execute(['HGET', $this->keys->permitKey((string) $request->permit?->id), 'state']));
    }

    public function testDirectReserveKeySpecificPermitExpireRefusalAfterCfgAndCounter(): void
    {
        $request = $this->buildReserveRequest('cfg_counter_permit');
        $permitKey = $this->keys->permitKey((string) $request->permit?->id);
        $counterKey = $this->keys->scopeKey(S::CLASS_NAME, \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::COUNTER, 'c:g4-cfg_counter_permit');
        $before = $this->observe();

        // Allow EXPIRE on counterKey, deny EXPIRE only on permitKey (step 6 of 6).
        $restricted = $this->storeOf($this->accountWithSelectors(
            'res-cfg-permit-expire',
            ['EXPIRE'],
            ["(+expire ~{$counterKey})"],
        ));
        $message = null;
        try {
            $restricted->reserveAdmission($request, self::NOW);
        } catch (RateLimiterException $e) {
            $message = $e->getMessage();
        }

        self::assertSame($before, $this->observe(), 'Refused permit EXPIRE after cfg+counter must leave Redis unchanged');
        self::assertSame('NOPERM admission reservation: the account may not run EXPIRE (plan step 6 of 6)', $message);

        $res = $this->store->reserveAdmission($request, self::NOW);
        self::assertTrue($res->admitted);
        self::assertSame('ISSUED', $this->executor->execute(['HGET', $permitKey, 'state']));
    }

    /** @return iterable<string, array{0: string, 1: AdmissionOutcomeKindEnum, 2: string, 3: int, 4: int}> */
    public static function outcomeRefusals(): iterable
    {
        yield 'failure set: ZREMRANGEBYSCORE refused' => ['failure_set', AdmissionOutcomeKindEnum::FAILURE, 'ZREMRANGEBYSCORE', 1, 3];
        yield 'failure set: ZADD refused' => ['failure_set', AdmissionOutcomeKindEnum::FAILURE, 'ZADD', 2, 3];
        yield 'failure set: EXPIRE refused' => ['failure_set', AdmissionOutcomeKindEnum::FAILURE, 'EXPIRE', 3, 3];
        yield 'settling failure (late ZREM on pending): ZREM refused' => ['settling_failure', AdmissionOutcomeKindEnum::FAILURE, 'ZREM', 4, 4];
        yield 'success set + counter: ZREM refused' => ['success_set_counter', AdmissionOutcomeKindEnum::SUCCESS, 'ZREM', 1, 2];
        yield 'success bucket partial refund: HSET refused' => ['bucket_partial', AdmissionOutcomeKindEnum::SUCCESS, 'HSET', 1, 2];
        yield 'success bucket partial refund: EXPIRE refused' => ['bucket_partial', AdmissionOutcomeKindEnum::SUCCESS, 'EXPIRE', 2, 2];
        yield 'success bucket full refund: DEL refused' => ['bucket_full', AdmissionOutcomeKindEnum::SUCCESS, 'DEL', 1, 1];
    }

    #[DataProvider('outcomeRefusals')]
    public function testDirectOutcomeAclRefusalLeavesRedisSnapshotUnchanged(string $case, AdmissionOutcomeKindEnum $kind, string $denied, int $step, int $total): void
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
        self::assertSame("NOPERM admission outcome: the account may not run {$denied} (plan step {$step} of {$total})", $message);
        self::assertStringNotContainsString('maatify:rate-limiter', (string) $message);
        self::assertStringNotContainsString('acct-', (string) $message);

        // Whole account applies cleanly with exact branch side-effects and without claiming any permit.
        $this->store->applyAdmissionOutcome($outcome, self::NOW + 2);
        $this->assertOutcomeApplied($case);
    }

    public function testDirectOutcomeSuccessPartialZremRefusalLeavesSetAndCounterUnchanged(): void
    {
        $scopes = $this->arrangeOutcomeScopes('success_set_counter');
        $outcome = new AdmissionOutcomeDTO(S::CLASS_NAME, AdmissionOutcomeKindEnum::SUCCESS, $scopes);
        $setKey = $this->keys->scopeKey(S::CLASS_NAME, \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::SET_SIZE, 's:g4-out-succ');
        $before = $this->observe();

        // Allow ZREM on setKey, deny ZREM only on counterKey (plan step 2 of 2).
        $restricted = $this->storeOf($this->accountWithSelectors('out-partial-zrem', ['ZREM'], ["(+zrem ~{$setKey})"]));
        $message = null;
        try {
            $restricted->applyAdmissionOutcome($outcome, self::NOW + 1);
        } catch (RateLimiterException $e) {
            $message = $e->getMessage();
        }

        self::assertSame($before, $this->observe(), 'Refused ZREM on counter (step 2/2) must not remove set member');
        self::assertSame('NOPERM admission outcome: the account may not run ZREM (plan step 2 of 2)', $message);

        $this->store->applyAdmissionOutcome($outcome, self::NOW + 2);
        $this->assertOutcomeApplied('success_set_counter');
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

        // 3. Pending ZSET with surviving member and TTL >= life: EXPIRE on pending is NOT issued (denied on pendingKey, allowed on permitKey) and PEXPIRETIME is literally unchanged.
        $pendingKey = $this->keys->pendingKey(S::CLASS_NAME, 's:pend-keep');
        $setKeyKeep = $this->keys->scopeKey(S::CLASS_NAME, \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::SET_SIZE, 's:pend-keep');
        $permitKeepKey = $this->keys->permitKey('p-pend-keep');
        $this->executor->execute(['ZADD', $pendingKey, (string) (self::NOW + 1000), 'other-member']);
        $this->executor->execute(['EXPIRE', $pendingKey, '1000']);
        $origPexpireTime = $this->executor->execute(['PEXPIRETIME', $pendingKey]);
        self::assertTrue(is_int($origPexpireTime) || is_string($origPexpireTime));

        // Also proves F304-01: ZCOUNT on pending is NOT required (allowed only on setKeyKeep).
        $noPendingExpireOrZcount = $this->storeOf($this->accountWithSelectors(
            'no-pend-expire-keep',
            ['EXPIRE', 'ZCOUNT'],
            ["(+expire ~{$permitKeepKey})", "(+zcount ~{$setKeyKeep})"],
        ));
        $settlesScope = new AdmissionScopeDTO('s', \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::SET_SIZE, 's:pend-keep', 4, 600, 's:pend-keep', 'p-pend-keep', null, false, true);
        self::assertTrue($noPendingExpireOrZcount->reserveAdmission(S::withPermit(S::permit('p-pend-keep', self::NOW + 30), $settlesScope), self::NOW)->admitted);
        $afterPexpireTime = $this->executor->execute(['PEXPIRETIME', $pendingKey]);
        self::assertSame($origPexpireTime, $afterPexpireTime, 'Surviving pending member with longer TTL must keep exact PEXPIRETIME');

        // 4. Pending ZSET where all existing members are expired (<= now - ttl): ZREMRANGEBYSCORE deletes last member, so EXPIRE on pending IS required (plan step 3 of 5), even when EXPIRE on permitKey is allowed.
        $expiredPendingKey = $this->keys->pendingKey(S::CLASS_NAME, 's:pend-recreate');
        $permitRecreateKey = $this->keys->permitKey('p-pend-recreate');
        $this->executor->execute(['ZADD', $expiredPendingKey, (string) (self::NOW - 700), 'stale-member']);
        $this->executor->execute(['EXPIRE', $expiredPendingKey, '1000']);
        $origRecreatePexpireTime = $this->executor->execute(['PEXPIRETIME', $expiredPendingKey]);
        self::assertTrue(is_int($origRecreatePexpireTime) || is_string($origRecreatePexpireTime));
        $recreateScope = new AdmissionScopeDTO('s', \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::SET_SIZE, 's:pend-recreate', 4, 600, 's:pend-recreate', 'p-pend-recreate', null, false, true);
        $recreateReq = S::withPermit(S::permit('p-pend-recreate', self::NOW + 30), $recreateScope);
        $beforeRecreate = $this->observe();

        $noPendingExpire = $this->storeOf($this->accountWithSelectors(
            'no-pend-expire-recreate',
            ['EXPIRE'],
            ["(+expire ~{$permitRecreateKey})"],
        ));
        $recreateErr = null;
        try {
            $noPendingExpire->reserveAdmission($recreateReq, self::NOW);
        } catch (RateLimiterException $e) {
            $recreateErr = $e->getMessage();
        }
        self::assertSame('NOPERM admission reservation: the account may not run EXPIRE (plan step 3 of 5)', $recreateErr);
        self::assertSame($beforeRecreate, $this->observe(), 'Refused EXPIRE after last-member prune must leave pending ZSET unchanged');

        self::assertTrue($this->store->reserveAdmission($recreateReq, self::NOW)->admitted);
        $recreatedTtl = $this->executor->execute(['TTL', $expiredPendingKey]);
        $recreatedPexpireTime = $this->executor->execute(['PEXPIRETIME', $expiredPendingKey]);
        self::assertSame(630, $recreatedTtl, 'Recreated pending ZSET must receive life=630 TTL');
        self::assertTrue(is_int($recreatedPexpireTime) || is_string($recreatedPexpireTime));
        self::assertNotSame($origRecreatePexpireTime, $recreatedPexpireTime);

        // 5. F304-01: isolate all 4 non-writing settling branches (ZCOUNT on pending denied, unused writes denied):
        // (a) hasPermit=false, inSet=false on settling scope: no writes at all, exact snapshot unchanged.
        $noPermitSetKey = $this->keys->scopeKey(S::CLASS_NAME, \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::SET_SIZE, 's:pend-no-permit');
        $noPermitAccount = $this->storeOf($this->accountWithSelectors(
            'no-permit-restricted',
            ['SET', 'ZREMRANGEBYSCORE', 'ZADD', 'HSET', 'EXPIRE', 'DEL', 'ZCOUNT'],
            ["(+zcount ~{$noPermitSetKey})"],
        ));
        $noPermitScope = new AdmissionScopeDTO('s', \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::SET_SIZE, 's:pend-no-permit', 4, 600, 's:pend-no-permit', 'm-no-permit', null, false, true);
        $beforeNoPermit = $this->observe();
        self::assertTrue($noPermitAccount->reserveAdmission(new AdmissionReservationRequestDTO(S::CLASS_NAME, [$noPermitScope]), self::NOW)->admitted);
        self::assertSame($beforeNoPermit, $this->observe());

        // (b) hasPermit=true, inSet=true (already settled): pending writes and ZCOUNT on pending denied; only permitKey is written.
        $settledSetKey = $this->keys->scopeKey(S::CLASS_NAME, \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::SET_SIZE, 's:pend-already-settled');
        $settledPendingKey = $this->keys->pendingKey(S::CLASS_NAME, 's:pend-already-settled');
        $settledPermitKey = $this->keys->permitKey('p-pend-already-settled');
        $this->executor->execute(['ZADD', $settledSetKey, (string) (self::NOW + 500), 'm-settled']);
        $this->executor->execute(['EXPIRE', $settledSetKey, '500']);
        $this->executor->execute(['ZADD', $settledPendingKey, (string) (self::NOW + 100), 'other-pend']);
        $this->executor->execute(['EXPIRE', $settledPendingKey, '500']);
        $beforeAlreadySettled = $this->observe();

        $alreadySettledAccount = $this->storeOf($this->accountWithSelectors(
            'already-settled-restricted',
            ['SET', 'ZREMRANGEBYSCORE', 'ZADD', 'HSET', 'EXPIRE', 'DEL', 'ZCOUNT'],
            ["(+zcount ~{$settledSetKey})", "(+hset ~{$settledPermitKey})", "(+expire ~{$settledPermitKey})"],
        ));
        $alreadySettledScope = new AdmissionScopeDTO('s', \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::SET_SIZE, 's:pend-already-settled', 4, 600, 's:pend-already-settled', 'm-settled', null, false, true);
        self::assertArrayNotHasKey($settledPermitKey, $beforeAlreadySettled);
        self::assertTrue($alreadySettledAccount->reserveAdmission(S::withPermit(S::permit('p-pend-already-settled', self::NOW + 30), $alreadySettledScope), self::NOW)->admitted);
        $afterAlreadySettled = $this->observe();
        self::assertArrayHasKey($settledPermitKey, $afterAlreadySettled);
        $afterWithoutPermit = $afterAlreadySettled;
        unset($afterWithoutPermit[$settledPermitKey]);
        self::assertSame($beforeAlreadySettled, $afterWithoutPermit, 'Already-settled permit reservation must leave every other key (including no deletions) identical');
        self::assertSame(
            ['ISSUED', 'U', 'acctT', 'login', (string) (self::NOW + 30), '600'],
            $this->executor->execute(['HMGET', $settledPermitKey, 'state', 'lane', 'subject', 'operation', 'deadline', 'grace']),
        );
        self::assertSame(630, $this->executor->execute(['TTL', $settledPermitKey]));

        // (c) Gate refusal on settling scope (cap=1 with 1 settled member) and (d) permit_exists on settling scope:
        $fullSetKey = $this->keys->scopeKey(S::CLASS_NAME, \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::SET_SIZE, 's:pend-gate-full');
        $this->executor->execute(['ZADD', $fullSetKey, (string) (self::NOW + 500), 'm-blocker']);
        $this->executor->execute(['EXPIRE', $fullSetKey, '500']);
        $beforeSettlingRefusals = $this->observe();

        $settlingRefusalAccount = $this->storeOf($this->accountWithSelectors(
            'settling-refusals-restricted',
            ['SET', 'ZREMRANGEBYSCORE', 'ZADD', 'HSET', 'EXPIRE', 'DEL', 'ZCOUNT'],
            ["(+zcount ~{$fullSetKey})", "(+zcount ~{$settledSetKey})"],
        ));
        $fullScope = new AdmissionScopeDTO('s', \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::SET_SIZE, 's:pend-gate-full', 1, 600, 's:pend-gate-full', 'm-blocked', null, false, true);
        $gateRefused = $settlingRefusalAccount->reserveAdmission(S::withPermit(S::permit('p-pend-blocked', self::NOW + 30), $fullScope), self::NOW);
        self::assertFalse($gateRefused->admitted);
        self::assertSame('s', $gateRefused->refusedScope);
        self::assertSame($beforeSettlingRefusals, $this->observe());

        $dupSettling = $settlingRefusalAccount->reserveAdmission(S::withPermit(S::permit('p-pend-already-settled', self::NOW + 30), new AdmissionScopeDTO('s', \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::SET_SIZE, 's:pend-already-settled', 4, 600, 's:pend-already-settled', 'm-new', null, false, true)), self::NOW);
        self::assertFalse($dupSettling->admitted);
        self::assertSame('permit_exists', $dupSettling->refusedScope);
        self::assertSame($beforeSettlingRefusals, $this->observe());

        // 6. OUTCOME unused-command controls (each asserts exact before/after snapshot):
        // - Bucket failure needs no DEL/HSET/EXPIRE; absent bucket success needs no DEL/HSET/EXPIRE.
        $noBucketWriteAccount = $this->storeOf($this->account('out-no-bucket-writes', ['DEL', 'HSET', 'EXPIRE']));
        $beforeNoBucket = $this->observe();
        $noBucketWriteAccount->applyAdmissionOutcome(new AdmissionOutcomeDTO(S::CLASS_NAME, AdmissionOutcomeKindEnum::FAILURE, [S::bucket('tok', 'b:unused-fail', 3, 60)]), self::NOW + 1);
        $this->assertSnapshotUnchanged($beforeNoBucket);
        $noBucketWriteAccount->applyAdmissionOutcome(new AdmissionOutcomeDTO(S::CLASS_NAME, AdmissionOutcomeKindEnum::SUCCESS, [S::bucket('tok', 'b:unused-absent', 3, 60)]), self::NOW + 1);
        $this->assertSnapshotUnchanged($beforeNoBucket);
        // - Counter failure with releasesOnFailure=false needs no ZREM.
        $noZremAccount = $this->storeOf($this->account('out-no-zrem', ['ZREM']));
        $noZremAccount->applyAdmissionOutcome(new AdmissionOutcomeDTO(S::CLASS_NAME, AdmissionOutcomeKindEnum::FAILURE, [S::counter('src', 'c:unused-no-rel', 10, member: 'm-1')]), self::NOW + 1);
        $this->assertSnapshotUnchanged($beforeNoBucket);
        // - Set-size success with keepsSettled=true and no future pending member needs no ZREM; kept member stays intact.
        $keptSetKey = $this->keys->scopeKey(S::CLASS_NAME, \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::SET_SIZE, 's:unused-kept');
        $keptScope = new AdmissionScopeDTO('s', \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::SET_SIZE, 's:unused-kept', 4, 600, 's:unused-kept', 'm-kept', null, true, true);
        $this->executor->execute(['ZADD', $keptSetKey, (string) (self::NOW + 500), 'm-kept']);
        $this->executor->execute(['EXPIRE', $keptSetKey, '500']);
        $beforeKept = $this->observe();
        $noZremAccount->applyAdmissionOutcome(new AdmissionOutcomeDTO(S::CLASS_NAME, AdmissionOutcomeKindEnum::SUCCESS, [$keptScope]), self::NOW + 1);
        self::assertSame($beforeKept, $this->observe());
    }

    public function testNonAclRefusalsAndPublicOverlayBehaviorArePreserved(): void
    {
        // 1. Seed an existing previous generation key with real bytes and PEXPIRETIME, then verify gate refusal, duplicate permit, config mismatch, and ACL refusal leave all state (including previous) untouched.
        $prevCounterKey = $this->keys->scopeKey(S::CLASS_NAME, \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::COUNTER, 'c:ctrl-prev');
        $this->executor->execute(['ZADD', $prevCounterKey, (string) (self::NOW + 400), 'prev-member-1']);
        $this->executor->execute(['EXPIRE', $prevCounterKey, '400']);
        $prevDumpBefore = $this->executor->execute(['DUMP', $prevCounterKey]);
        $prevPexpireBefore = $this->executor->execute(['PEXPIRETIME', $prevCounterKey]);
        self::assertIsString($prevDumpBefore);
        self::assertTrue(is_int($prevPexpireBefore) || is_string($prevPexpireBefore));

        $cfg1 = AdmissionSharedConfigDTO::forLimit('a1.cfg.' . str_repeat('d', 64), 2);
        $cfg2 = AdmissionSharedConfigDTO::forLimit('a1.cfg.' . str_repeat('d', 64), 3);
        $c1 = new AdmissionScopeDTO('src', \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::COUNTER, 'c:ctrl', 2, 600, 'c:ctrl-prev', 'm-1');
        self::assertTrue($this->store->reserveAdmission(new AdmissionReservationRequestDTO(S::CLASS_NAME, [$c1], S::permit('p-ctrl-1', self::NOW + 30), $cfg1), self::NOW)->admitted);
        $snap = $this->observe();
        self::assertSame($prevDumpBefore, $this->executor->execute(['DUMP', $prevCounterKey]), 'Successful admission must read previous without writing it');
        self::assertSame($prevPexpireBefore, $this->executor->execute(['PEXPIRETIME', $prevCounterKey]));

        // Admitted plan with existing previous generation where a late write (HSET permit, step 4 of 5) is denied leaves both cur and previous unchanged.
        $lateRefusalAccount = $this->storeOf($this->account('prev-admitted-late-hset', ['HSET']));
        $lateErr = null;
        try {
            $lateRefusalAccount->reserveAdmission(new AdmissionReservationRequestDTO(S::CLASS_NAME, [new AdmissionScopeDTO('src', \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::COUNTER, 'c:ctrl', 3, 600, 'c:ctrl-prev', 'm-late')], S::permit('p-ctrl-late', self::NOW + 30), $cfg1), self::NOW);
        } catch (RateLimiterException $e) {
            $lateErr = $e->getMessage();
        }
        self::assertSame('NOPERM admission reservation: the account may not run HSET (plan step 4 of 5)', $lateErr);
        self::assertSame($snap, $this->observe(), 'Late ACL refusal on admitted plan with existing previous must leave cur and previous unchanged');

        // Restricted account with write-commands denied proves Phase 1/2 refusals never require write-command permissions or touch previous.
        $readOnlyRestricted = $this->storeOf($this->account('phase12-no-writes', ['SET', 'ZREMRANGEBYSCORE', 'ZADD', 'HSET', 'EXPIRE', 'DEL']));

        // Gate refusal (1 in cur + 1 in prev == cap 2)
        $refused = $readOnlyRestricted->reserveAdmission(new AdmissionReservationRequestDTO(S::CLASS_NAME, [new AdmissionScopeDTO('src', \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::COUNTER, 'c:ctrl', 2, 600, 'c:ctrl-prev', 'm-2')], S::permit('p-ctrl-2', self::NOW + 30), $cfg1), self::NOW);
        self::assertFalse($refused->admitted);
        self::assertSame($snap, $this->observe());

        // Duplicate permit
        $dup = $readOnlyRestricted->reserveAdmission(new AdmissionReservationRequestDTO(S::CLASS_NAME, [S::counter('src', 'c:ctrl-other', 5)], S::permit('p-ctrl-1', self::NOW + 30), $cfg1), self::NOW);
        self::assertFalse($dup->admitted);
        self::assertSame('permit_exists', $dup->refusedScope);
        self::assertSame($snap, $this->observe());

        // Config mismatch
        $mismatchThrew = false;
        try {
            $readOnlyRestricted->reserveAdmission(new AdmissionReservationRequestDTO(S::CLASS_NAME, [S::counter('src', 'c:ctrl-other', 5)], S::permit('p-ctrl-3', self::NOW + 30), $cfg2), self::NOW);
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
            $this->store->applyAdmissionOutcome(new AdmissionOutcomeDTO(S::CLASS_NAME, AdmissionOutcomeKindEnum::FAILURE, [$set]), self::NOW);
            self::assertTrue($this->store->reserveAdmission(new AdmissionReservationRequestDTO(S::CLASS_NAME, [$cnt]), self::NOW)->admitted);
            self::assertNotNull($this->executor->execute(['ZSCORE', $this->keys->scopeKey(S::CLASS_NAME, \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::SET_SIZE, 's:g4-out-succ'), 'acct-g4-2']));
            self::assertNotNull($this->executor->execute(['ZSCORE', $this->keys->scopeKey(S::CLASS_NAME, \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::COUNTER, 'c:g4-out-succ'), 'm-g4-cnt']));

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

    private function assertOutcomeApplied(string $case): void
    {
        if ($case === 'failure_set') {
            $setKey = $this->keys->scopeKey(S::CLASS_NAME, \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::SET_SIZE, 's:g4-out-fail');
            self::assertSame((string) (self::NOW + 2 + 1200), $this->executor->execute(['ZSCORE', $setKey, 'acct-g4-1']));
            self::assertSame(1200, $this->executor->execute(['TTL', $setKey]));

            return;
        }
        if ($case === 'settling_failure') {
            $setKey = $this->keys->scopeKey(S::CLASS_NAME, \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::SET_SIZE, 's:g4-out-settle');
            $pendingKey = $this->keys->pendingKey(S::CLASS_NAME, 's:g4-out-settle');
            $permitKey = $this->keys->permitKey('p-g4-arr-settle');
            self::assertSame((string) (self::NOW + 2 + 600), $this->executor->execute(['ZSCORE', $setKey, 'm-g4-settle']));
            self::assertSame(600, $this->executor->execute(['TTL', $setKey]));
            self::assertNull($this->executor->execute(['ZSCORE', $pendingKey, 'm-g4-settle']));
            // applyAdmissionOutcome never claims the permit.
            self::assertSame('ISSUED', $this->executor->execute(['HGET', $permitKey, 'state']));

            return;
        }
        if ($case === 'success_set_counter') {
            self::assertNull($this->executor->execute(['ZSCORE', $this->keys->scopeKey(S::CLASS_NAME, \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::SET_SIZE, 's:g4-out-succ'), 'acct-g4-2']));
            self::assertNull($this->executor->execute(['ZSCORE', $this->keys->scopeKey(S::CLASS_NAME, \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::COUNTER, 'c:g4-out-succ'), 'm-g4-cnt']));

            return;
        }
        if ($case === 'bucket_partial') {
            $bKey = $this->keys->scopeKey(S::CLASS_NAME, \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::BUCKET, 'b:g4-partial');
            // 2 reservations at NOW (capacity 3 * 60 = 180 -> remaining 60) + 2s elapsed + 1 refund (+60) at NOW+2 = 122, TTL = 180 - 122 = 58
            self::assertSame('122', $this->executor->execute(['HGET', $bKey, 'credit']));
            self::assertSame(58, $this->executor->execute(['TTL', $bKey]));

            return;
        }
        self::assertSame(0, $this->executor->execute(['EXISTS', $this->keys->scopeKey(S::CLASS_NAME, \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::BUCKET, 'b:g4-full')]));
    }

    /** @return array<string, string> */
    private function observe(): array
    {
        return StateSnapshot::all($this->executor);
    }

    /** @param array<string, string> $expected */
    private function assertSnapshotUnchanged(array $expected): void
    {
        self::assertSame($expected, $this->observe());
    }

    /** @param list<string> $denied */
    private function account(string $name, array $denied): RespRedisCommandExecutor
    {
        return $this->accountWithSelectors($name, $denied, []);
    }

    /**
     * @param list<string> $denied
     * @param list<string> $selectors Redis 7 key-scoped ACL selectors (e.g. `(+expire ~key)`)
     */
    private function accountWithSelectors(string $name, array $denied, array $selectors): RespRedisCommandExecutor
    {
        $user = 'aclg4-' . substr(hash('sha256', $name), 0, 16);
        $rules = array_map(static fn(string $command): string => '-' . strtolower($command), $denied);
        $this->executor->execute(['ACL', 'SETUSER', $user, 'reset', 'on', '>' . self::PASSWORD, '~*', '+@all', ...$rules, ...$selectors]);
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
