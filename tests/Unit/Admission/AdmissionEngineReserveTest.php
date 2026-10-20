<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The reservation at `checkOnly`, the refusal shape (S7, LT-2 with `blockLevel` 1), the permit (S9, OD-1 b) and the outcome
 * hooks of the engine (contract §2.1, §2.2, §3; plan §3 D-RES, D-OUT, LT-16).
 */
#[Group('admission-engine')]
final class AdmissionEngineReserveTest extends TestCase
{
    private const IP = '198.51.100.7';

    private function assertRefusal(RateLimitResultDTO $r, string $cause, string $scope): void
    {
        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $r->decision);
        self::assertSame(1, $r->blockLevel);
        self::assertSame('NORMAL', $r->failureMode);
        self::assertSame($cause, EngineLab::cause($r));
        self::assertSame($cause, $r->metadata?->context?->reason);
        self::assertSame($scope, EngineLab::scope($r));
        self::assertGreaterThan(0, $r->retryAfter);
    }

    // ---- refusal shape: the six causes ------------------------------------------------------------------------------

    public function testSourceCapacityRefusesToTheEndOfTheWindow(): void
    {
        $lab = new EngineLab(EngineLab::budgets(sourceCapacity: 2));
        $lab->at(125);
        for ($i = 0; $i < 2; $i++) {
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $lab->check($lab->context('acct-' . $i, self::IP))->decision);
        }
        $r = $lab->check($lab->context('acct-9', self::IP));

        $this->assertRefusal($r, 'source_capacity', 'source');
        $windowEnd = (intdiv($lab->now(), 600) + 1) * 600;
        self::assertSame($windowEnd - $lab->now(), $r->retryAfter);
        // Nothing is persisted: the refusal is not a block, so the next window admits again.
        $lab->at(600);
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $lab->check($lab->context('acct-9', self::IP))->decision);
    }

    public function testAccountBucketRefusesWithTheRefillWait(): void
    {
        $lab = new EngineLab(EngineLab::budgets(bucketTakes: 1));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $lab->check($lab->context('acct-1', self::IP))->decision);
        $r = $lab->check($lab->context('acct-1', '203.0.113.9'));

        $this->assertRefusal($r, 'account_bucket', 'account');
        self::assertLessThanOrEqual(1800, $r->retryAfter);
    }

    public function testSourceFailureCapRefusesAFurtherAccountOfTheSameSource(): void
    {
        $lab = new EngineLab(EngineLab::budgets(sourceFailureCap: 1));
        $ctx = $lab->context('acct-1', self::IP);
        $permit = EngineLab::permit($lab->check($ctx));
        self::assertNotSame('', $permit);
        $lab->outcome($ctx, false, $permit);

        $this->assertRefusal($lab->check($lab->context('acct-2', self::IP)), 'source_failure_cap', 'source');
        // The failed account itself is a member and is not refused by the cap.
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $lab->check($ctx)->decision);
    }

    public function testProofCapacityOnTheProvenDoor(): void
    {
        $lab = new EngineLab(EngineLab::budgets(proofAccountWindow: 1));
        $credential = $lab->mint('acct-1', 'inst-1');
        $ctx = $lab->context('acct-1', self::IP, 'inst-1', $credential);
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $lab->check($ctx)->decision);

        $this->assertRefusal($lab->check($ctx), 'proof_capacity', 'proof');
    }

    public function testNewDeviceCapIsOnTheFingerprintMember(): void
    {
        $lab = new EngineLab(EngineLab::budgets(newDevices: 1));
        $credential = $lab->mint('acct-1', 'inst-1');
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $lab->check($lab->context('acct-1', self::IP, 'inst-1', $credential, 'device-a'))->decision);
        // The same instance id with another fingerprint is another member (NP-IN): the id is not the member.
        $this->assertRefusal($lab->check($lab->context('acct-1', self::IP, 'inst-1', $credential, 'device-b')), 'new_device_cap', 'device');
        // The first device is an existing member and is admitted.
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $lab->check($lab->context('acct-1', self::IP, 'inst-1', $credential, 'device-a'))->decision);
    }

    public function testSourceProofFailureCapOnTheProvenDoor(): void
    {
        $lab = new EngineLab(EngineLab::budgets(crossAccountFailureCap: 1));
        $a = $lab->context('acct-1', self::IP, 'inst-1', $lab->mint('acct-1', 'inst-1'));
        $permit = EngineLab::permit($lab->check($a));
        self::assertNotSame('', $permit);
        $lab->outcome($a, false, $permit);

        $b = $lab->context('acct-2', self::IP, 'inst-2', $lab->mint('acct-2', 'inst-2'));
        $this->assertRefusal($lab->check($b), 'source_proof_failure_cap', 'source');
    }

    // ---- permit ---------------------------------------------------------------------------------------------------------

    public function testAnAdmissionOfAnOptedPolicyCarriesAnOpaqueBoundedPermit(): void
    {
        $lab = new EngineLab();
        $permit = EngineLab::permit($lab->check($lab->context('acct-1', self::IP)));

        self::assertIsString($permit);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_.-]{20,512}\z/', $permit);
    }

    public function testThePermitTokenStaysBoundedWhateverTheHostPutsInTheCredentialId(): void
    {
        $lab = new EngineLab();
        $credential = $lab->mint('acct-1', 'inst-1', jti: str_repeat('j', 3000));
        $permit = (string) EngineLab::permit($lab->check($lab->context('acct-1', self::IP, 'inst-1', $credential)));

        self::assertLessThanOrEqual(512, strlen($permit));
        // The bounded reference still binds the outcome to its proof: the success is accepted and refunds.
        $result = $lab->outcome($lab->context('acct-1', self::IP, 'inst-1', $credential), true, $permit);
        self::assertStringStartsNotWith('permit_', (string) EngineLab::cause($result));
    }

    public function testANonOptedPolicyIsUntouchedByTheRuntime(): void
    {
        $lab = new EngineLab(EngineLab::budgets(sourceCapacity: 1), policies: [new \Maatify\RateLimiter\Config\OtpProtectionPolicy()]);
        for ($i = 0; $i < 3; $i++) {
            $r = $lab->check($lab->context('acct-' . $i, self::IP), 'otp_protection');
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $r->decision);
            self::assertNull(EngineLab::permit($r));
        }
        // An unbound outcome of a non-opted policy is T0, as today.
        $r = $lab->engine->limit($lab->context('acct-1', self::IP), RateLimitCommand::recordFailure('otp_protection'));
        self::assertNotSame('', $r->decision);
    }

    public function testAnOutcomeWithNoPermitIsAContractViolationForAnOptedPolicy(): void
    {
        $lab = new EngineLab();
        $this->expectException(RateLimiterException::class);
        $lab->engine->limit($lab->context('acct-1', self::IP), RateLimitCommand::recordFailure(EngineLab::POLICY));
    }

    /** @return array<string, string> */
    private function causeOf(EngineLab $lab, RateLimitResultDTO $r): array
    {
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $r->decision);
        self::assertSame(0, $r->blockLevel);
        self::assertSame(0, $r->retryAfter);
        self::assertSame('permit', EngineLab::scope($r));

        return ['cause' => (string) EngineLab::cause($r)];
    }

    public function testEveryNonAcceptedClaimIsAnAllowResultWithItsCauseAndNoScoring(): void
    {
        $lab = new EngineLab();
        $ctx = $lab->context('acct-1', self::IP);
        $permit = (string) EngineLab::permit($lab->check($ctx));

        // First outcome accepted: a normal evaluation result (no permit cause).
        $accepted = $lab->outcome($ctx, false, $permit);
        self::assertStringStartsNotWith('permit_', (string) EngineLab::cause($accepted));
        // Duplicate, then conflict.
        self::assertSame(['cause' => 'permit_duplicate'], $this->causeOf($lab, $lab->outcome($ctx, false, $permit)));
        self::assertSame(['cause' => 'permit_conflict'], $this->causeOf($lab, $lab->outcome($ctx, true, $permit)));
        // A token that never existed, and a MAC failure, are both unknown.
        self::assertSame(['cause' => 'permit_unknown'], $this->causeOf($lab, $lab->outcome($ctx, false, substr($permit, 0, -2) . 'zz')));
        self::assertSame(['cause' => 'permit_unknown'], $this->causeOf($lab, $lab->outcome($ctx, false, 'not-a-token')));
        // Another account presenting the permit is rejected.
        self::assertSame(['cause' => 'permit_rejected'], $this->causeOf($lab, $lab->outcome($lab->context('acct-2', self::IP), false, $permit)));
    }

    /**
     * Replaces the 5-b2.1 settlement version of this test (PLAN §11.8, the Owner's decision of 2026-10-20): under the store default
     * an expired permit is a tombstone, so a late outcome is `permit_late`, scores nothing and leaves no failure behind.
     */
    public function testALateOutcomeIsLateAndLeavesNoFailure(): void
    {
        $lab = new EngineLab(EngineLab::budgets(sourceFailureCap: 1));
        $ctx = $lab->context('acct-1', self::IP);
        $permit = (string) EngineLab::permit($lab->check($ctx));
        $lab->at(31);

        self::assertSame(['cause' => 'permit_late'], $this->causeOf($lab, $lab->outcome($ctx, true, $permit)));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $lab->check($lab->context('acct-2', self::IP))->decision);
    }

    // ---- outcome effects (D-OUT) ------------------------------------------------------------------------------------------

    /**
     * Replaces the 5-b2.1 settlement version (PLAN §11.8): a dropped outcome is a tombstone (SF §2.3 I9) and never becomes a
     * failure, before or after the deadline. A recorded failure is what the cap counts.
     */
    public function testADroppedOutcomeNeverBecomesAFailure(): void
    {
        $lab = new EngineLab(EngineLab::budgets(sourceFailureCap: 1));
        $lab->check($lab->context('acct-1', self::IP));
        $lab->at(29);
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $lab->check($lab->context('acct-2', self::IP))->decision);
        $lab->at(631);
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $lab->check($lab->context('acct-3', self::IP))->decision);
        $failed = $lab->context('acct-4', self::IP);
        $lab->outcome($failed, false, (string) EngineLab::permit($lab->check($failed)));
        $this->assertRefusal($lab->check($lab->context('acct-5', self::IP)), 'source_failure_cap', 'source');
    }

    public function testASuccessRemovesTheNetFailureAndRefundsTheBucket(): void
    {
        $lab = new EngineLab(EngineLab::budgets(sourceFailureCap: 1, bucketTakes: 1));
        $ctx = $lab->context('acct-1', self::IP);
        $p1 = (string) EngineLab::permit($lab->check($ctx));
        $lab->outcome($ctx, true, $p1);

        // The success refunded the one bucket take and left no failure: the same account is admitted again, and once that
        // admission also ends in a success another account is admitted after the deadline.
        $again = $lab->check($ctx);
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $again->decision);
        $lab->outcome($ctx, true, (string) EngineLab::permit($again));
        $lab->at(31);
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $lab->check($lab->context('acct-2', self::IP))->decision);
    }

    public function testAFailureIsNeverRefunded(): void
    {
        $lab = new EngineLab(EngineLab::budgets(bucketTakes: 1));
        $ctx = $lab->context('acct-1', self::IP);
        $permit = (string) EngineLab::permit($lab->check($ctx));
        $lab->outcome($ctx, false, $permit);

        $this->assertRefusal($lab->check($ctx), 'account_bucket', 'account');
    }

    public function testASuccessOnTheProvenDoorRefundsTheCountersAndALaterFailureEntersD8(): void
    {
        $lab = new EngineLab(EngineLab::budgets(proofAccountWindow: 1, crossAccountFailureCap: 1));
        $a = $lab->context('acct-1', self::IP, 'inst-1', $lab->mint('acct-1', 'inst-1'));
        $p1 = (string) EngineLab::permit($lab->check($a));
        $lab->outcome($a, true, $p1);
        // Refunded: the window counter of the account is free again.
        $p2 = (string) EngineLab::permit($lab->check($a));
        $lab->outcome($a, false, $p2);

        // The recorded failure is in the D8 set.
        $b = $lab->context('acct-2', self::IP, 'inst-2', $lab->mint('acct-2', 'inst-2'));
        $this->assertRefusal($lab->check($b), 'source_proof_failure_cap', 'source');
    }

    /**
     * D-OUT as amended (PLAN §11.2): a success on the proven door never sends the D8 scope, so a recorded D8 failure of the
     * account stays in the set after a later success of the same account (with the flag off a success on a set-size scope would
     * remove it). Replaces the 5-b2.1 "cancels a pending D8 member" test (§11.8).
     */
    public function testASuccessOnTheProvenDoorNeverRemovesARecordedD8Failure(): void
    {
        $lab = new EngineLab(EngineLab::budgets(crossAccountFailureCap: 1));
        $a = $lab->context('acct-1', self::IP, 'inst-1', $lab->mint('acct-1', 'inst-1'));
        $lab->outcome($a, false, (string) EngineLab::permit($lab->check($a)));
        $lab->outcome($a, true, (string) EngineLab::permit($lab->check($a)));

        $b = $lab->context('acct-2', self::IP, 'inst-2', $lab->mint('acct-2', 'inst-2'));
        $this->assertRefusal($lab->check($b), 'source_proof_failure_cap', 'source');
        self::assertSame([], array_values(array_filter(
            $lab->admissionStore->outcomes,
            static fn(AdmissionOutcomeDTO $o): bool => $o->outcome === AdmissionOutcomeKindEnum::SUCCESS
                && in_array('source_proof_failure_cap', array_map(static fn(AdmissionScopeDTO $s): string => $s->name, $o->scopes), true),
        )), 'no success outcome carries the D8 scope');
    }

    /** A success on the proven door with no recorded failure leaves the D8 set empty. */
    public function testASuccessOnTheProvenDoorIsNoD8Failure(): void
    {
        $lab = new EngineLab(EngineLab::budgets(crossAccountFailureCap: 1));
        $a = $lab->context('acct-1', self::IP, 'inst-1', $lab->mint('acct-1', 'inst-1'));
        $lab->outcome($a, true, (string) EngineLab::permit($lab->check($a)));
        $lab->at(31);

        $b = $lab->context('acct-2', self::IP, 'inst-2', $lab->mint('acct-2', 'inst-2'));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $lab->check($b)->decision, 'a success on P counts as no D8 failure');
    }
}
