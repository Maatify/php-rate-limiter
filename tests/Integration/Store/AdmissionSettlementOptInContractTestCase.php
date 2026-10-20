<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Repository\AdmissionCapableFullStoreInterface;
use Maatify\RateLimiter\Repository\AdmissionPermitStoreInterface;
use Maatify\RateLimiter\Tests\Support\Admission\SettlementPort;
use Maatify\RateLimiter\Tests\Support\Admission\SettlementScenariosOff as Off;
use Maatify\RateLimiter\Tests\Support\Admission\SettlementScenarios as On;
use PHPUnit\Framework\TestCase;

/**
 * 5b2.2 contract on both stores (reference parity in both modes): with `settlesOnExpiry` the results of 5-b2.1, without it
 * the tombstone semantics of d6aea4d.
 */
abstract class AdmissionSettlementOptInContractTestCase extends TestCase
{
    /** @return AdmissionCapableFullStoreInterface&AdmissionPermitStoreInterface */
    abstract protected function newStore(): AdmissionCapableFullStoreInterface&AdmissionPermitStoreInterface;

    private function port(): SettlementPort
    {
        $store = $this->newStore();

        return new SettlementPort(
            $store->reserveAdmission(...),
            $store->applyAdmissionOutcome(...),
            static fn(string $id, AdmissionOutcomeKindEnum $kind, int $now) => $store->claimAdmissionPermit($id, 'U', 'acctT', 'login', $kind, $now),
        );
    }

    public function testWithTheFlagTheExactBoundSettlesAtTheDeadline(): void
    {
        self::assertSame(['beforeDeadline' => true, 'atDeadlineNew' => false, 'atDeadlineOwn' => true], On::exactBound($this->port()));
    }

    public function testWithTheFlagOutcomesBeforeTheDeadlineAndTheNetRule(): void
    {
        self::assertSame(
            ['successBeforeDeadlineThenNew' => true, 'droppedOutcomeThenNew' => false, 'explicitFailureThenNew' => false, 'netSuccessThenNew' => true],
            On::outcomes($this->port()),
        );
    }

    public function testWithTheFlagTheKeepsSettledRuleAndTheDueMemberRule(): void
    {
        self::assertSame(['settledKeptThenNew' => false, 'pendingCancelledThenNew' => true], On::keepsSettledFlag($this->port()));
        self::assertSame(['dueSurvivesSuccess' => false], On::dueSurvivesSuccess($this->port()));
    }

    public function testWithTheFlagOnceAndLateAndNoRefund(): void
    {
        self::assertSame([
            'ownAgain' => true,
            'ownWithNewPermit' => true,
            'newAdmitted' => true,
            'lateClaim' => 'late',
            'newAfterLate' => true,
            'newAtSize3' => false,
        ], On::onceAndLate($this->port()));
        self::assertSame(['counter' => false, 'bucket' => false], On::noRefund($this->port()));
    }

    public function testWithoutTheFlagNothingSettlesAndTheLateClaimStillSaysLate(): void
    {
        self::assertSame(['beforeDeadline' => true, 'atDeadlineNew' => true, 'atDeadlineOwn' => true], Off::exactBound($this->port()));
        $outcomes = Off::outcomes($this->port());
        self::assertTrue($outcomes['droppedOutcomeThenNew'], 'a dropped outcome never counts at the default');
        self::assertTrue($outcomes['successBeforeDeadlineThenNew']);
        self::assertFalse($outcomes['explicitFailureThenNew'], 'an explicit failure still counts at once');
    }

    public function testWithoutTheFlagSuccessKeepsNothingSettled(): void
    {
        // d6aea4d has no such concept: a success removes a settled failure whatever `successKeepsSettled` says.
        self::assertSame(['settledKeptThenNew' => true, 'pendingCancelledThenNew' => true], Off::keepsSettledFlag($this->port()));
    }
}
