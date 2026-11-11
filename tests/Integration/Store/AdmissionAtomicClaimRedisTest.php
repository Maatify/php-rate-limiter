<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionPermitClaimEnum;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionRaceRunner;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionRaceSpec;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionScenarioBuilder as S;
use Maatify\RateLimiter\Tests\Support\Admission\ReplyValue;
use PHPUnit\Framework\Attributes\Group;

/**
 * Regression and resilience test for DEC-036: Atomic outcome claim and state mutation on real Redis (LT-185-36b).
 *
 * Verifies:
 * 1. Outage before commit: permit stays ISSUED, retry succeeds.
 * 2. Outage after commit: state applied once, retry returns permit_duplicate.
 * 3. Mid-pipeline outage dump comparison (FAIL_CLOSED, DUMP before == DUMP after retry).
 * 4. 8-way race on single permit: exactly 1 ACCEPTED, state applied once.
 * 5. Malformed scope: throws RateLimiterException while permit remains ISSUED.
 */
#[Group('atomic-claim')]
#[Group('integration')]
final class AdmissionAtomicClaimRedisTest extends AdmissionRedisGateTestCase
{
    private const NOW = S::NOW;

    public function testOutageBeforeCommitLeavesPermitIssuedAndRetrySucceeds(): void
    {
        $permit = S::permit('p-outage-before', self::NOW + 30);
        $scope = S::counter('src', 'c:outage-before', 10);
        $res = $this->store->reserveAdmission(S::withPermit($permit, $scope), self::NOW);
        self::assertTrue($res->admitted);

        // Before claim commit, permit is in ISSUED state
        $permitKey = $this->keys->permitKey('p-outage-before');
        $permitRecord = $this->executor->execute(['HGET', $permitKey, 'state']);
        self::assertSame('ISSUED', $permitRecord);

        // Simulate outage before claim completes (network drop / failure) -> permit stays ISSUED
        $dumpBefore = $this->dump();
        self::assertSame('ISSUED', $this->permitField($dumpBefore, $permitKey, 'state'));

        // Host retries claim with outcome
        $failScope = S::setSize('ufail', 's:outage-before-fail', 4, 'acct-outage');
        $outcomeDTO = new AdmissionOutcomeDTO(S::CLASS_NAME, AdmissionOutcomeKindEnum::FAILURE, [$failScope], 'p-outage-before');

        $claim = $this->store->claimAdmissionPermitWithOutcome('U', 'acctT', 'login', $outcomeDTO, self::NOW + 1);
        self::assertSame(AdmissionPermitClaimEnum::ACCEPTED, $claim);

        // Permit is now CLAIMED and failure set contains acct-outage
        $dumpAfter = $this->dump();
        self::assertSame('CLAIMED', $this->permitField($dumpAfter, $permitKey, 'state'));
        self::assertSame('failure', $this->permitField($dumpAfter, $permitKey, 'outcome'));

        $failKey = $this->key(AdmissionScopeKindEnum::SET_SIZE, 's:outage-before-fail');
        self::assertSame(1, ReplyValue::integer($this->executor->execute(['ZCARD', $failKey])));
    }

    public function testOutageAfterCommitCausesRetryToReturnDuplicateWithoutDoubleApplyingState(): void
    {
        $permit = S::permit('p-outage-after', self::NOW + 30);
        $scope = S::counter('src', 'c:outage-after', 10);
        self::assertTrue($this->store->reserveAdmission(S::withPermit($permit, $scope), self::NOW)->admitted);

        $failScope = S::setSize('ufail', 's:outage-after-fail', 4, 'acct-after');
        $outcomeDTO = new AdmissionOutcomeDTO(S::CLASS_NAME, AdmissionOutcomeKindEnum::FAILURE, [$failScope], 'p-outage-after');

        // First call commits atomically
        $claim1 = $this->store->claimAdmissionPermitWithOutcome('U', 'acctT', 'login', $outcomeDTO, self::NOW + 1);
        self::assertSame(AdmissionPermitClaimEnum::ACCEPTED, $claim1);

        $failKey = $this->key(AdmissionScopeKindEnum::SET_SIZE, 's:outage-after-fail');
        self::assertSame(1, ReplyValue::integer($this->executor->execute(['ZCARD', $failKey])));
        $dumpCommitted = $this->dump();

        // Host retries (simulating reply was lost in network outage)
        $claim2 = $this->store->claimAdmissionPermitWithOutcome('U', 'acctT', 'login', $outcomeDTO, self::NOW + 2);
        self::assertSame(AdmissionPermitClaimEnum::DUPLICATE, $claim2);

        // State remains exactly as after first commit (no duplicate additions)
        self::assertSame(1, ReplyValue::integer($this->executor->execute(['ZCARD', $failKey])));
        self::assertSame($dumpCommitted, $this->dump());
    }

    public function testMalformedScopeFailsClosedAndLeavesPermitIssued(): void
    {
        $permit = S::permit('p-malformed-scope', self::NOW + 30);
        $scope = S::counter('src', 'c:malformed-scope', 10);
        self::assertTrue($this->store->reserveAdmission(S::withPermit($permit, $scope), self::NOW)->admitted);

        $permitKey = $this->keys->permitKey('p-malformed-scope');
        $failScope = S::setSize('ufail', 's:poisoned-scope', 4, 'acct-m');
        $failKey = $this->key(AdmissionScopeKindEnum::SET_SIZE, 's:poisoned-scope');

        // Poison the scope key in Redis with an incompatible type (string instead of zset)
        $this->executor->execute(['SET', $failKey, 'not-a-zset']);

        $dumpBefore = $this->dump();

        $outcomeDTO = new AdmissionOutcomeDTO(S::CLASS_NAME, AdmissionOutcomeKindEnum::FAILURE, [$failScope], 'p-malformed-scope');

        try {
            $this->store->claimAdmissionPermitWithOutcome('U', 'acctT', 'login', $outcomeDTO, self::NOW + 1);
            self::fail('Malformed scope state must trigger RateLimiterException');
        } catch (RateLimiterException $e) {
            self::assertStringContainsString('malformed admission state', $e->getMessage());
        }

        // Permit must STILL be ISSUED (Redis aborted execution before any write)
        $permitState = $this->executor->execute(['HGET', $permitKey, 'state']);
        self::assertSame('ISSUED', $permitState);

        // Dump comparison: dump before == dump after failed call
        self::assertSame($dumpBefore, $this->dump());
    }

    public function testRaceOfEightAtomicClaimsOnOnePermitAcceptsExactlyOne(): void
    {
        for ($round = 0; $round < 3; $round++) {
            $this->resetRedis();

            $permit = S::permit('p-race-8', self::NOW + 30);
            $scope = S::counter('src', 'c:race-8-counter', 10);
            self::assertTrue($this->store->reserveAdmission(S::withPermit($permit, $scope), self::NOW)->admitted);

            $failKey = 's:race-8-fail';
            $results = AdmissionRaceRunner::run($this->host, $this->port, 8, new AdmissionRaceSpec(
                self::NAMESPACE,
                'claim_with_outcome',
                'store',
                self::NOW + 5,
                [['name' => 'ufail', 'kind' => 'set_size', 'key' => $failKey, 'capacity' => 4, 'ttl' => 1200, 'member' => 'subject-race']],
                'p-race-8',
                null,
                'failure',
            ));

            // Exactly 1 worker accepted
            self::assertSame(1, AdmissionRaceRunner::admitted($results), "Round $round: exactly 1 worker must be accepted");

            // All other 7 workers received duplicate
            $duplicates = array_values(array_filter($results, static fn(array $r): bool => ! $r['ok']));
            self::assertCount(7, $duplicates);
            foreach ($duplicates as $dup) {
                self::assertSame(AdmissionPermitClaimEnum::DUPLICATE->value, $dup['claim']);
            }

            // Failure set member was added exactly once
            $realFailKey = $this->key(AdmissionScopeKindEnum::SET_SIZE, $failKey);
            self::assertSame(1, ReplyValue::integer($this->executor->execute(['ZCARD', $realFailKey])));
        }
    }

    /**
     * @param array<string, array{type: string, value: mixed, hasTtl: bool}> $dump
     */
    private function permitField(array $dump, string $permitKey, string $field): ?string
    {
        $val = $dump[$permitKey]['value'] ?? null;
        if (is_array($val) && isset($val[$field]) && is_scalar($val[$field])) {
            return (string) $val[$field];
        }

        return null;
    }
}
