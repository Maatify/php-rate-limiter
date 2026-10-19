<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionPermitClaimEnum;
use Maatify\RateLimiter\Repository\AdmissionCapableFullStoreInterface;
use Maatify\RateLimiter\Repository\AdmissionPermitStoreInterface;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionScenarioBuilder as S;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Contract of the admission store operations (contract §2.4), run unchanged against every implementation: the Redis store
 * (real Redis) and the in-memory reference store. Results are observed only through the public operations.
 */
#[Group('admission-store')]
abstract class AdmissionStoreContractTestCase extends TestCase
{
    protected const NOW = S::NOW;

    /** @return AdmissionCapableFullStoreInterface&AdmissionPermitStoreInterface */
    abstract protected function newStore(): AdmissionCapableFullStoreInterface&AdmissionPermitStoreInterface;

    private function admitted(AdmissionCapableFullStoreInterface $store, \Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO $request, int $now = self::NOW): bool
    {
        return $store->reserveAdmission($request, $now)->admitted;
    }

    public function testCounterGateAdmitsExactlyCapacityAndNamesTheRefusingScope(): void
    {
        $store = $this->newStore();
        for ($i = 0; $i < 3; $i++) {
            self::assertTrue($this->admitted($store, S::request(S::counter('src', 'c:src', 3))));
        }
        $refused = $store->reserveAdmission(S::request(S::counter('src', 'c:src', 3)), self::NOW);
        self::assertFalse($refused->admitted);
        self::assertSame('src', $refused->refusedScope);
    }

    public function testCounterKeyExpiresAfterItsRelativeTtl(): void
    {
        $store = $this->newStore();
        self::assertTrue($this->admitted($store, S::request(S::counter('src', 'c:ttl', 1, 100))));
        self::assertFalse($this->admitted($store, S::request(S::counter('src', 'c:ttl', 1, 100)), self::NOW + 50));
        self::assertTrue($this->admitted($store, S::request(S::counter('src', 'c:ttl', 1, 100)), self::NOW + 101));
    }

    public function testCounterRotationSumsPreviousAndNeverWritesIt(): void
    {
        $store = $this->newStore();
        self::assertTrue($this->admitted($store, S::request(S::counter('src', 'c:old', 10))));
        self::assertTrue($this->admitted($store, S::request(S::counter('src', 'c:old', 10))));
        self::assertTrue($this->admitted($store, S::request(S::counter('src', 'c:new', 3, 1200, 'c:old'))));
        self::assertFalse($this->admitted($store, S::request(S::counter('src', 'c:new', 3, 1200, 'c:old'))));
        // The previous key still holds exactly 2: one more admission up to 3, then a refusal.
        self::assertTrue($this->admitted($store, S::request(S::counter('src', 'c:old', 3))));
        self::assertFalse($this->admitted($store, S::request(S::counter('src', 'c:old', 3))));
    }

    public function testDeviceMemberGateAdmitsExistingMembersAtCapacityAndRefusesNewOnes(): void
    {
        $store = $this->newStore();
        self::assertTrue($this->admitted($store, S::request(S::device('np', 'd:cur', 2, 'a'))));
        self::assertTrue($this->admitted($store, S::request(S::device('np', 'd:cur', 2, 'b'))));
        $refused = $store->reserveAdmission(S::request(S::device('np', 'd:cur', 2, 'c')), self::NOW);
        self::assertFalse($refused->admitted);
        self::assertSame('np', $refused->refusedScope);
        self::assertTrue($this->admitted($store, S::request(S::device('np', 'd:cur', 2, 'a'))));
    }

    public function testDeviceMemberOfThePreviousSetIsExistingAndNothingIsWrittenForIt(): void
    {
        $store = $this->newStore();
        self::assertTrue($this->admitted($store, S::request(S::device('np', 'd:prev', 5, 'x'))));
        self::assertTrue($this->admitted($store, S::request(S::device('np', 'd:prev', 5, 'y'))));
        // Two live members in the previous set count against the capacity of the union.
        self::assertFalse($this->admitted($store, S::request(S::device('np', 'd:cur', 2, 'z', 86400, 'd:prev', 'zz'))));
        // A member of the previous set is existing: admitted at capacity, and nothing is written to the current set.
        self::assertTrue($this->admitted($store, S::request(S::device('np', 'd:cur', 2, 'q', 86400, 'd:prev', 'x'))));
        self::assertTrue($this->admitted($store, S::request(S::device('np', 'd:cur', 1, 'fresh'))));
        self::assertFalse($this->admitted($store, S::request(S::device('np', 'd:cur', 1, 'fresher'))));
    }

    public function testDeviceMembersExpireAfterTheirRelativeLifetime(): void
    {
        $store = $this->newStore();
        self::assertTrue($this->admitted($store, S::request(S::device('np', 'd:exp', 2, 'a', 100))));
        self::assertTrue($this->admitted($store, S::request(S::device('np', 'd:exp', 2, 'b', 100))));
        self::assertFalse($this->admitted($store, S::request(S::device('np', 'd:exp', 2, 'c', 100)), self::NOW + 99));
        self::assertTrue($this->admitted($store, S::request(S::device('np', 'd:exp', 2, 'c', 100)), self::NOW + 101));
    }

    public function testSetSizeGateRefusesAFurtherAccountOnlyAndOutcomesMaintainTheSet(): void
    {
        $store = $this->newStore();
        $set = static fn(string $account) => S::setSize('fail_src', 's:u', 2, $account);
        // A reservation never writes the set.
        self::assertTrue($this->admitted($store, S::request($set('probe-1'))));
        self::assertTrue($this->admitted($store, S::request($set('probe-2'))));
        self::assertTrue($this->admitted($store, S::request($set('probe-3'))));

        $store->applyAdmissionOutcome(S::outcome(AdmissionOutcomeKindEnum::FAILURE, $set('acct-1')), self::NOW);
        $store->applyAdmissionOutcome(S::outcome(AdmissionOutcomeKindEnum::FAILURE, $set('acct-2')), self::NOW);
        $refused = $store->reserveAdmission(S::request($set('acct-3')), self::NOW);
        self::assertFalse($refused->admitted);
        self::assertSame('fail_src', $refused->refusedScope);
        self::assertTrue($this->admitted($store, S::request($set('acct-1'))));

        // A later success of the same account removes it from the net set (U only).
        $store->applyAdmissionOutcome(S::outcome(AdmissionOutcomeKindEnum::SUCCESS, $set('acct-1')), self::NOW);
        self::assertTrue($this->admitted($store, S::request($set('acct-3'))));
        // A repeated failure of a member does not grow the set.
        $store->applyAdmissionOutcome(S::outcome(AdmissionOutcomeKindEnum::FAILURE, $set('acct-2')), self::NOW);
        $store->applyAdmissionOutcome(S::outcome(AdmissionOutcomeKindEnum::FAILURE, $set('acct-2')), self::NOW);
        self::assertTrue($this->admitted($store, S::request($set('acct-2'))));
        self::assertTrue($this->admitted($store, S::request($set('acct-3'))));
    }

    public function testSetSizeSetExpiresWithItsWindow(): void
    {
        $store = $this->newStore();
        $set = static fn(string $account) => S::setSize('d8', 's:p', 1, $account, 600);
        $store->applyAdmissionOutcome(S::outcome(AdmissionOutcomeKindEnum::FAILURE, $set('acct-1')), self::NOW);
        self::assertFalse($this->admitted($store, S::request($set('acct-2')), self::NOW + 599));
        self::assertTrue($this->admitted($store, S::request($set('acct-2')), self::NOW + 601));
    }

    public function testBucketAdmitsCapacityTakesThenRefusesWithTheRefillWaitAndRefills(): void
    {
        $store = $this->newStore();
        $bucket = static fn() => S::bucket('acct_tb', 'b:acct', 4, 1800);
        for ($i = 0; $i < 4; $i++) {
            self::assertTrue($this->admitted($store, S::request($bucket())));
        }
        $refused = $store->reserveAdmission(S::request($bucket()), self::NOW);
        self::assertFalse($refused->admitted);
        self::assertSame('acct_tb', $refused->refusedScope);
        self::assertSame(1800, $refused->retryAfterSeconds);
        $later = $store->reserveAdmission(S::request($bucket()), self::NOW + 1799);
        self::assertFalse($later->admitted);
        self::assertSame(1, $later->retryAfterSeconds);
        self::assertTrue($this->admitted($store, S::request($bucket()), self::NOW + 1800));
        self::assertFalse($this->admitted($store, S::request($bucket()), self::NOW + 1800));
    }

    public function testBucketRefundOnSuccessOnlyAndNeverAboveCapacity(): void
    {
        $store = $this->newStore();
        $bucket = static fn() => S::bucket('acct_tb', 'b:refund', 4, 1800);
        for ($i = 0; $i < 4; $i++) {
            self::assertTrue($this->admitted($store, S::request($bucket())));
        }
        $store->applyAdmissionOutcome(S::outcome(AdmissionOutcomeKindEnum::FAILURE, $bucket()), self::NOW);
        self::assertFalse($this->admitted($store, S::request($bucket())));
        $store->applyAdmissionOutcome(S::outcome(AdmissionOutcomeKindEnum::SUCCESS, $bucket()), self::NOW);
        self::assertTrue($this->admitted($store, S::request($bucket())));
        self::assertFalse($this->admitted($store, S::request($bucket())));

        // Refunds on a full (or absent) bucket do not create credit above the capacity.
        $fresh = static fn() => S::bucket('acct_tb', 'b:full', 4, 1800);
        for ($i = 0; $i < 3; $i++) {
            $store->applyAdmissionOutcome(S::outcome(AdmissionOutcomeKindEnum::SUCCESS, $fresh()), self::NOW);
        }
        for ($i = 0; $i < 4; $i++) {
            self::assertTrue($this->admitted($store, S::request($fresh())));
        }
        self::assertFalse($this->admitted($store, S::request($fresh())));
    }

    public function testCounterRefundOnSuccessOnlyAndRepeatedOutcomesApplyTwice(): void
    {
        $store = $this->newStore();
        $counter = static fn() => S::counter('p_acct', 'c:refund', 3);
        for ($i = 0; $i < 3; $i++) {
            self::assertTrue($this->admitted($store, S::request($counter())));
        }
        $store->applyAdmissionOutcome(S::outcome(AdmissionOutcomeKindEnum::FAILURE, $counter()), self::NOW);
        self::assertFalse($this->admitted($store, S::request($counter())));
        // No idempotency for T0: two success outcomes refund two units.
        $store->applyAdmissionOutcome(S::outcome(AdmissionOutcomeKindEnum::SUCCESS, $counter()), self::NOW);
        $store->applyAdmissionOutcome(S::outcome(AdmissionOutcomeKindEnum::SUCCESS, $counter()), self::NOW);
        self::assertTrue($this->admitted($store, S::request($counter())));
        self::assertTrue($this->admitted($store, S::request($counter())));
        self::assertFalse($this->admitted($store, S::request($counter())));
    }

    public function testRefundNeverGoesBelowZero(): void
    {
        $store = $this->newStore();
        $counter = static fn() => S::counter('p_acct', 'c:floor', 2);
        $store->applyAdmissionOutcome(S::outcome(AdmissionOutcomeKindEnum::SUCCESS, $counter()), self::NOW);
        self::assertTrue($this->admitted($store, S::request($counter())));
        self::assertTrue($this->admitted($store, S::request($counter())));
        self::assertFalse($this->admitted($store, S::request($counter())));
    }

    public function testReservationIsAllOrNothingAcrossItsScopes(): void
    {
        $store = $this->newStore();
        self::assertTrue($this->admitted($store, S::request(S::counter('a', 'c:a', 5), S::counter('b', 'c:b', 1))));
        $refused = $store->reserveAdmission(S::request(S::counter('a', 'c:a', 5), S::counter('b', 'c:b', 1)), self::NOW);
        self::assertFalse($refused->admitted);
        self::assertSame('b', $refused->refusedScope);
        // 'a' holds 1, not 2: the refused request wrote nothing to it.
        self::assertTrue($this->admitted($store, S::request(S::counter('a', 'c:a', 2))));
        self::assertFalse($this->admitted($store, S::request(S::counter('a', 'c:a', 2))));
    }

    public function testBucketAndDeviceMemberAreNotWrittenWhenALaterScopeRefuses(): void
    {
        $store = $this->newStore();
        self::assertTrue($this->admitted($store, S::request(S::counter('full', 'c:full', 1))));
        $request = static fn() => S::request(
            S::bucket('acct_tb', 'b:aon', 1, 1800),
            S::device('np', 'd:aon', 1, 'dev-1'),
            S::counter('full', 'c:full', 1),
        );
        $refused = $store->reserveAdmission($request(), self::NOW);
        self::assertFalse($refused->admitted);
        self::assertSame('full', $refused->refusedScope);
        // The bucket kept its credit and the device set stayed empty.
        self::assertTrue($this->admitted($store, S::request(S::bucket('acct_tb', 'b:aon', 1, 1800))));
        self::assertTrue($this->admitted($store, S::request(S::device('np', 'd:aon', 1, 'dev-2'))));
    }

    public function testFirstRefusingScopeInRequestOrderIsNamed(): void
    {
        $store = $this->newStore();
        self::assertTrue($this->admitted($store, S::request(S::counter('x', 'c:x', 1), S::counter('y', 'c:y', 1))));
        $refused = $store->reserveAdmission(S::request(S::counter('y', 'c:y', 1), S::counter('x', 'c:x', 1)), self::NOW);
        self::assertSame('y', $refused->refusedScope);
    }

    public function testPermitLifecycleReportsEveryClaimReason(): void
    {
        $store = $this->newStore();
        $claim = static fn(AdmissionPermitStoreInterface $s, string $id, AdmissionOutcomeKindEnum $o, int $now, string $subject = 'acctT'): AdmissionPermitClaimEnum
            => $s->claimAdmissionPermit($id, 'U', $subject, 'login', $o, $now);
        $issue = fn(string $id) => self::assertTrue($this->admitted($store, S::withPermit(S::permit($id, self::NOW + 30), S::counter('src', 'c:permit', 100))));

        $issue('p-ok');
        self::assertSame(AdmissionPermitClaimEnum::ACCEPTED, $claim($store, 'p-ok', AdmissionOutcomeKindEnum::FAILURE, self::NOW + 5));
        self::assertSame(AdmissionPermitClaimEnum::DUPLICATE, $claim($store, 'p-ok', AdmissionOutcomeKindEnum::FAILURE, self::NOW + 6));
        self::assertSame(AdmissionPermitClaimEnum::CONFLICT, $claim($store, 'p-ok', AdmissionOutcomeKindEnum::SUCCESS, self::NOW + 7));

        $issue('p-wrong');
        self::assertSame(AdmissionPermitClaimEnum::REJECTED, $claim($store, 'p-wrong', AdmissionOutcomeKindEnum::FAILURE, self::NOW + 5, 'acctOther'));
        self::assertSame(AdmissionPermitClaimEnum::ACCEPTED, $claim($store, 'p-wrong', AdmissionOutcomeKindEnum::FAILURE, self::NOW + 6));

        $issue('p-late');
        self::assertSame(AdmissionPermitClaimEnum::LATE, $claim($store, 'p-late', AdmissionOutcomeKindEnum::FAILURE, self::NOW + 30));
        self::assertSame(AdmissionPermitClaimEnum::LATE, $claim($store, 'p-late', AdmissionOutcomeKindEnum::FAILURE, self::NOW + 629));
        self::assertSame(AdmissionPermitClaimEnum::UNKNOWN, $claim($store, 'p-late', AdmissionOutcomeKindEnum::FAILURE, self::NOW + 630));

        self::assertSame(AdmissionPermitClaimEnum::UNKNOWN, $claim($store, 'p-never', AdmissionOutcomeKindEnum::FAILURE, self::NOW));
    }

    public function testExistingPermitIdIsRefusedAndTheRecordIsUnchanged(): void
    {
        $store = $this->newStore();
        self::assertTrue($this->admitted($store, S::withPermit(S::permit('p-1', self::NOW + 30), S::counter('src', 'c:pe', 100))));
        $again = $store->reserveAdmission(S::withPermit(S::permit('p-1', self::NOW + 500), S::counter('src', 'c:pe', 100)), self::NOW + 1);
        self::assertFalse($again->admitted);
        self::assertSame('permit_exists', $again->refusedScope);
        // The original deadline stands: a claim after it is late, not accepted.
        self::assertSame(AdmissionPermitClaimEnum::LATE, $store->claimAdmissionPermit('p-1', 'U', 'acctT', 'login', AdmissionOutcomeKindEnum::FAILURE, self::NOW + 40));
        // The refused re-issue wrote nothing: the counter holds 1.
        self::assertTrue($this->admitted($store, S::request(S::counter('src', 'c:pe', 2))));
        self::assertFalse($this->admitted($store, S::request(S::counter('src', 'c:pe', 2))));
    }

    public function testRefusedReservationIssuesNoPermit(): void
    {
        $store = $this->newStore();
        self::assertTrue($this->admitted($store, S::request(S::counter('src', 'c:np', 1))));
        $refused = $store->reserveAdmission(S::withPermit(S::permit('p-none', self::NOW + 30), S::counter('src', 'c:np', 1)), self::NOW);
        self::assertFalse($refused->admitted);
        self::assertSame(AdmissionPermitClaimEnum::UNKNOWN, $store->claimAdmissionPermit('p-none', 'U', 'acctT', 'login', AdmissionOutcomeKindEnum::FAILURE, self::NOW + 1));
    }

    public function testBindingEpochReadsCurrentElsePreviousElseZeroAndBumpWritesCurrentOnly(): void
    {
        $store = $this->newStore();
        self::assertSame(0, $store->readBindingEpoch('e:acct', null));
        self::assertSame(1, $store->bumpBindingEpoch('e:acct', null));
        self::assertSame(1, $store->readBindingEpoch('e:acct', null));
        self::assertSame(2, $store->bumpBindingEpoch('e:acct', null));

        // After a rotation the new current key is absent: the read falls back to the previous one.
        self::assertSame(2, $store->readBindingEpoch('e:new', 'e:acct'));
        self::assertSame(3, $store->bumpBindingEpoch('e:new', 'e:acct'));
        self::assertSame(3, $store->readBindingEpoch('e:new', 'e:acct'));
        // The previous key is never written.
        self::assertSame(2, $store->readBindingEpoch('e:acct', null));
    }
}
