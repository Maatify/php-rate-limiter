<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\AdmissionCapableFullStoreInterface;
use Maatify\RateLimiter\Repository\AdmissionPermitStoreInterface;
use Maatify\RateLimiter\Tests\Support\Admission\SettlementPort;
use Maatify\RateLimiter\Tests\Support\Admission\SettlementScenarios as Sc;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Settlement of expired permits (5b2.1/PLAN.md §1, §5), run unchanged against the Redis store and the in-memory reference
 * store. Results are observed only through the public store operations.
 */
#[Group('settle')]
abstract class AdmissionSettlementContractTestCase extends TestCase
{
    /** @return AdmissionCapableFullStoreInterface&AdmissionPermitStoreInterface */
    abstract protected function newStore(): AdmissionCapableFullStoreInterface&AdmissionPermitStoreInterface;

    private function port(): SettlementPort
    {
        $store = $this->newStore();

        return new SettlementPort(
            $store->reserveAdmission(...),
            $store->applyAdmissionOutcome(...),
            static fn(string $id, \Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum $kind, int $now) => $store->claimAdmissionPermit($id, 'U', 'acctT', 'login', $kind, $now),
        );
    }

    public function testSettle1ADueMemberIsCountedExactlyFromItsDeadline(): void
    {
        self::assertSame(
            ['beforeDeadline' => true, 'atDeadlineNew' => false, 'atDeadlineOwn' => true],
            Sc::exactBound($this->port()),
        );
    }

    public function testSettle2OutcomesBeforeTheDeadlineAndTheNetRule(): void
    {
        self::assertSame([
            'successBeforeDeadlineThenNew' => true,
            'droppedOutcomeThenNew' => false,
            'explicitFailureThenNew' => false,
            'netSuccessThenNew' => true,
        ], Sc::outcomes($this->port()));
    }

    public function testSettle2TheKeepsSettledFlagKeepsAFailureAndCancelsAPendingMember(): void
    {
        self::assertSame(['settledKeptThenNew' => false, 'pendingCancelledThenNew' => true], Sc::keepsSettledFlag($this->port()));
    }

    public function testSettle3ASuccessNeverRemovesADueMember(): void
    {
        self::assertSame(['dueSurvivesSuccess' => false], Sc::dueSurvivesSuccess($this->port()));
    }

    public function testSettle3CountedOnceAndALateOutcomeKeepsTheMembership(): void
    {
        self::assertSame([
            'ownAgain' => true,
            'ownWithNewPermit' => true,
            'newAdmitted' => true,
            'lateClaim' => 'late',
            'newAfterLate' => true,
            'newAtSize3' => false,
        ], Sc::onceAndLate($this->port()));
    }

    public function testSettle4CountersAndBucketAreNotRefundedBySettlement(): void
    {
        self::assertSame(['counter' => false, 'bucket' => false], Sc::noRefund($this->port()));
    }

    public function testAReserveWithoutAPermitWritesNoPendingMember(): void
    {
        $port = $this->port();
        // T0: the set stays a failure-only set (5-b2 property), nothing becomes due.
        Sc::reserve($port, Sc::N, Sc::member('s:t0', 'acct-t0', 1));
        self::assertTrue(Sc::reserve($port, Sc::N + 1000, Sc::member('s:t0', 'new-t0', 1)));
    }

    public function testADeadlineBeyondTheSetLifetimeIsRefusedBeforeAnyWrite(): void
    {
        $port = $this->port();
        $tooLate = Sc::permit('permit-ttl', Sc::N + Sc::TTL + 1);
        try {
            Sc::reserve($port, Sc::N, Sc::member('s:ttl', 'acct-ttl', 1), $tooLate);
            self::fail('A deadline beyond the set lifetime must be refused.');
        } catch (RateLimiterException) {
            // Nothing was written: the same permit id is still free and the member is not pending.
        }
        self::assertTrue(Sc::reserve($port, Sc::N, Sc::member('s:ttl', 'acct-ttl', 1), Sc::permit('permit-ttl', Sc::N + Sc::D)));
    }
}
