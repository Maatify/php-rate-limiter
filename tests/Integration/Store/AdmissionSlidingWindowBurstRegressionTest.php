<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\DTO\AdmissionBudgetsDTO;
use Maatify\RateLimiter\DTO\AdmissionCredentialDTO;
use Maatify\RateLimiter\DTO\AdmissionLaneDTO;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Enum\AdmissionLaneEnum;
use Maatify\RateLimiter\Service\AdmissionCredentialCodec;
use Maatify\RateLimiter\Service\AdmissionRuntime;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use PHPUnit\Framework\Attributes\Group;

/**
 * Boundary burst regressions (#185, DEC-033): under fixed tumbling windows (main@eb4e7400), an adversary
 * or burst crossing a window or day boundary could double their allowance within a narrow burst window (e.g.
 * 8 failures instead of 4, 48 requests instead of 24, etc.).
 *
 * Under sliding windows (DEC-033), keys are unbucketed and scored by expiration, strictly bounding every
 * sliding interval of length W (600 s) or DAY (86400 s) to the configured capacity.
 *
 * Verifies all 7 sliding scopes using keys derived directly by the runtime:
 * - ufail:  4 allowed across boundary, 5th rejected (4 not 8)
 * - pfail:  4 allowed across boundary, 5th rejected (4 not 8)
 * - src:   24 allowed across boundary, 25th rejected (24 not 48)
 * - pacct: 10 allowed across boundary, 11th rejected (10 not 20)
 * - pproof: 6 allowed across boundary, 7th rejected (6 not 12)
 * - pacctd: 60 allowed across daily boundary, 61st rejected (60 not 120)
 * - pproofd: 30 allowed across daily boundary, 31st rejected (30 not 60)
 */
#[Group('burst-regression')]
final class AdmissionSlidingWindowBurstRegressionTest extends AdmissionRedisGateTestCase
{
    private const KEY_SECRET = 'test-burst-key-secret-185';
    private const BINDING_SECRET = 'test-burst-binding-secret-185';
    private const ENV_SCOPE = 'burst-test';
    private const GROUP = 'login';
    private const POLICY = 'login_policy';

    private FixedClock $clock;
    private AdmissionCredentialCodec $codec;
    private AdmissionRuntime $runtime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FixedClock('@1000000');
        $this->codec = new AdmissionCredentialCodec(self::BINDING_SECRET);
        $this->runtime = new AdmissionRuntime(
            $this->store,
            $this->codec,
            AdmissionBudgetsDTO::packageDefaults(),
            $this->clock,
            self::KEY_SECRET,
            self::ENV_SCOPE,
        );
    }

    private function setTime(int $t): void
    {
        $this->clock->setNow(new \DateTimeImmutable('@' . $t));
    }

    private function allowedResult(): RateLimitResultDTO
    {
        return new RateLimitResultDTO(RateLimitResultDTO::DECISION_ALLOW, null, null, 'NORMAL');
    }

    private function emptyDevice(): DeviceIdentityDTO
    {
        return new DeviceIdentityDTO(null, 'LOW', false);
    }

    private function context(string $ip, ?string $account = null): RateLimitContextDTO
    {
        return new RateLimitContextDTO(ip: $ip, ua: 'burst-agent', accountId: $account);
    }

    private function provenLane(string $subject, string $jti, int $now): AdmissionLaneDTO
    {
        $claims = new AdmissionCredentialDTO(
            subject: $subject,
            budgetGroups: [self::GROUP],
            instance: 'inst-burst',
            jti: $jti,
            expiresAt: $now + 86400,
            boundAt: $now,
            epoch: 0,
        );

        return new AdmissionLaneDTO(AdmissionLaneEnum::P, $claims);
    }

    /**
     * ufail: Net failure cap F=4 across window boundary W=600 (590s to 604s).
     * 4 failures allowed; 5th rejected within 14 seconds (4 not 8).
     */
    public function testUfailBoundaryBurstPermitsFourNotEight(): void
    {
        $ip = '203.0.113.10';

        // 4 failures in window 0 at t=590
        $this->setTime(590);
        for ($i = 1; $i <= 4; $i++) {
            $ctx = $this->context($ip, "user-ufail-$i");
            $res = $this->runtime->reserve(self::POLICY, self::GROUP, $ctx, $this->emptyDevice(), new AdmissionLaneDTO(AdmissionLaneEnum::U), $this->allowedResult());
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $res->decision, "Reservation $i at t=590 must be allowed");
            $token = (string) $res->metadata?->permit;
            self::assertNotEmpty($token);
            $this->runtime->applyOutcome(self::POLICY, self::GROUP, $ctx, $token, false);
        }

        // At t=604 (14s later, across boundary 600): 5th attempt must be refused with source_failure_cap
        $this->setTime(604);
        $ctx5 = $this->context($ip, 'user-ufail-5');
        $res5 = $this->runtime->reserve(self::POLICY, self::GROUP, $ctx5, $this->emptyDevice(), new AdmissionLaneDTO(AdmissionLaneEnum::U), $this->allowedResult());
        self::assertFalse($res5->isAllowed(), '5th attempt across boundary must be blocked');
        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $res5->decision);
        self::assertSame('source_failure_cap', $res5->metadata?->cause);
    }

    /**
     * pfail: Cross-account proven failure cap F=4 across window boundary W=600 (590s to 604s).
     * 4 failures allowed; 5th rejected within 14 seconds (4 not 8).
     */
    public function testPfailBoundaryBurstPermitsFourNotEight(): void
    {
        $ip = '203.0.113.20';

        // 4 proven failures in window 0 at t=590
        $this->setTime(590);
        for ($i = 1; $i <= 4; $i++) {
            $ctx = $this->context($ip, "user-pfail-$i");
            $lane = $this->provenLane("user-pfail-$i", "jti-pfail-$i", 590);
            $res = $this->runtime->reserve(self::POLICY, self::GROUP, $ctx, $this->emptyDevice(), $lane, $this->allowedResult());
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $res->decision, "Reservation $i at t=590 must be allowed");
            $token = (string) $res->metadata?->permit;
            self::assertNotEmpty($token);
            $this->runtime->applyOutcome(self::POLICY, self::GROUP, $ctx, $token, false);
        }

        // At t=604 (14s later, across boundary 600): 5th attempt must be refused with source_proof_failure_cap
        $this->setTime(604);
        $ctx5 = $this->context($ip, 'user-pfail-5');
        $lane5 = $this->provenLane('user-pfail-5', 'jti-pfail-5', 604);
        $res5 = $this->runtime->reserve(self::POLICY, self::GROUP, $ctx5, $this->emptyDevice(), $lane5, $this->allowedResult());
        self::assertFalse($res5->isAllowed(), '5th attempt across boundary must be blocked');
        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $res5->decision);
        self::assertSame('source_proof_failure_cap', $res5->metadata?->cause);
    }

    /**
     * src: Source capacity counter U_src=24 across window boundary W=600 (590s to 604s).
     * 24 reservations allowed; 25th rejected within 14 seconds (24 not 48).
     */
    public function testSrcBoundaryBurstPermitsTwentyFourNotFortyEight(): void
    {
        $ip = '203.0.113.30';

        // 24 reservations in window 0 at t=590
        $this->setTime(590);
        for ($i = 1; $i <= 24; $i++) {
            $ctx = $this->context($ip);
            $res = $this->runtime->reserve(self::POLICY, self::GROUP, $ctx, $this->emptyDevice(), new AdmissionLaneDTO(AdmissionLaneEnum::U), $this->allowedResult());
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $res->decision, "Reservation $i at t=590 must be allowed");
        }

        // At t=604 (14s later, across boundary 600): 25th attempt must be refused with source_capacity
        $this->setTime(604);
        $ctx25 = $this->context($ip);
        $res25 = $this->runtime->reserve(self::POLICY, self::GROUP, $ctx25, $this->emptyDevice(), new AdmissionLaneDTO(AdmissionLaneEnum::U), $this->allowedResult());
        self::assertFalse($res25->isAllowed(), '25th reservation across boundary must be blocked');
        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $res25->decision);
        self::assertSame('source_capacity', $res25->metadata?->cause);
    }

    /**
     * pacct: Proven account counter P_acct=10 across window boundary W=600 (590s to 604s).
     * 10 reservations allowed; 11th rejected within 14 seconds (10 not 20).
     */
    public function testPacctBoundaryBurstPermitsTenNotTwenty(): void
    {
        $account = 'acct-pacct-test';

        // 10 reservations in window 0 at t=590
        $this->setTime(590);
        for ($i = 1; $i <= 10; $i++) {
            $ctx = $this->context("203.0.113.$i", $account);
            $lane = $this->provenLane($account, "jti-pacct-$i", 590);
            $res = $this->runtime->reserve(self::POLICY, self::GROUP, $ctx, $this->emptyDevice(), $lane, $this->allowedResult());
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $res->decision, "Reservation $i at t=590 must be allowed");
        }

        // At t=604 (14s later, across boundary 600): 11th attempt must be refused with proof_account_window
        $this->setTime(604);
        $ctx11 = $this->context('203.0.113.11', $account);
        $lane11 = $this->provenLane($account, 'jti-pacct-11', 604);
        $res11 = $this->runtime->reserve(self::POLICY, self::GROUP, $ctx11, $this->emptyDevice(), $lane11, $this->allowedResult());
        self::assertFalse($res11->isAllowed(), '11th reservation across boundary must be blocked');
        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $res11->decision);
        self::assertSame('proof_capacity', $res11->metadata?->cause);
        self::assertSame('proof', $res11->metadata->context?->scope);
    }

    /**
     * pproof: Proven proof counter P_proof=6 across window boundary W=600 (590s to 604s).
     * 6 reservations allowed; 7th rejected within 14 seconds (6 not 12).
     */
    public function testPproofBoundaryBurstPermitsSixNotTwelve(): void
    {
        $proofJti = 'fixed-jti-pproof-test';

        // 6 reservations in window 0 at t=590
        $this->setTime(590);
        for ($i = 1; $i <= 6; $i++) {
            $account = "acct-pproof-$i";
            $ctx = $this->context("203.0.113.$i", $account);
            $lane = $this->provenLane($account, $proofJti, 590);
            $res = $this->runtime->reserve(self::POLICY, self::GROUP, $ctx, $this->emptyDevice(), $lane, $this->allowedResult());
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $res->decision, "Reservation $i at t=590 must be allowed");
        }

        // At t=604 (14s later, across boundary 600): 7th attempt must be refused with proof_proof_window
        $this->setTime(604);
        $ctx7 = $this->context('203.0.113.7', 'acct-pproof-7');
        $lane7 = $this->provenLane('acct-pproof-7', $proofJti, 604);
        $res7 = $this->runtime->reserve(self::POLICY, self::GROUP, $ctx7, $this->emptyDevice(), $lane7, $this->allowedResult());
        self::assertFalse($res7->isAllowed(), '7th reservation across boundary must be blocked');
        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $res7->decision);
        self::assertSame('proof_capacity', $res7->metadata?->cause);
        self::assertSame('proof', $res7->metadata->context?->scope);
    }

    /**
     * pacctd: Proven account daily counter P_acct_day=60 across daily boundary DAY=86400.
     * 6 batches of 10 spaced across Day 0; at t=86410 window scopes are clear but daily cap is full.
     * 60 reservations allowed; 61st rejected within 20 seconds across day boundary (60 not 120).
     */
    public function testPacctdBoundaryBurstPermitsSixtyNotOneHundredTwenty(): void
    {
        $account = 'acct-pacctd-test';

        // 6 batches of 10 spaced by 600s in Day 0 (t=82790 .. 85790)
        for ($batch = 0; $batch < 6; $batch++) {
            $t = 82790 + ($batch * 600);
            $this->setTime($t);
            for ($i = 1; $i <= 10; $i++) {
                $idx = ($batch * 10) + $i;
                $ctx = $this->context("203.0.113.$idx", $account);
                $lane = $this->provenLane($account, "jti-pacctd-$idx", $t);
                $res = $this->runtime->reserve(self::POLICY, self::GROUP, $ctx, $this->emptyDevice(), $lane, $this->allowedResult());
                self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $res->decision, "Reservation $idx at t=$t must be allowed");
            }
        }

        // At t=86410 (Day 1, 620s after last batch): window counter expired, but daily counter has 60 entries.
        // 61st attempt must be refused with proof_account_day
        $this->setTime(86410);
        $ctx61 = $this->context('203.0.113.61', $account);
        $lane61 = $this->provenLane($account, 'jti-pacctd-61', 86410);
        $res61 = $this->runtime->reserve(self::POLICY, self::GROUP, $ctx61, $this->emptyDevice(), $lane61, $this->allowedResult());
        self::assertFalse($res61->isAllowed(), '61st reservation across day boundary must be blocked');
        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $res61->decision);
        self::assertSame('proof_capacity', $res61->metadata?->cause);
        self::assertSame('proof', $res61->metadata->context?->scope);
    }

    /**
     * pproofd: Proven proof daily counter P_proof_day=30 across daily boundary DAY=86400.
     * 5 batches of 6 spaced across Day 0; at t=86410 window scopes are clear but daily cap is full.
     * 30 reservations allowed; 31st rejected within 20 seconds across day boundary (30 not 60).
     */
    public function testPproofdBoundaryBurstPermitsThirtyNotSixty(): void
    {
        $proofJti = 'fixed-jti-pproofd-test';

        // 5 batches of 6 spaced by 600s in Day 0 (t=83390 .. 85790)
        for ($batch = 0; $batch < 5; $batch++) {
            $t = 83390 + ($batch * 600);
            $this->setTime($t);
            for ($i = 1; $i <= 6; $i++) {
                $idx = ($batch * 6) + $i;
                $account = "acct-pproofd-$idx";
                $ctx = $this->context("203.0.113.$idx", $account);
                $lane = $this->provenLane($account, $proofJti, $t);
                $res = $this->runtime->reserve(self::POLICY, self::GROUP, $ctx, $this->emptyDevice(), $lane, $this->allowedResult());
                self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $res->decision, "Reservation $idx at t=$t must be allowed");
            }
        }

        // At t=86410 (Day 1, 620s after last batch): window counter expired, but daily counter has 30 entries.
        // 31st attempt must be refused with proof_proof_day
        $this->setTime(86410);
        $ctx31 = $this->context('203.0.113.31', 'acct-pproofd-31');
        $lane31 = $this->provenLane('acct-pproofd-31', $proofJti, 86410);
        $res31 = $this->runtime->reserve(self::POLICY, self::GROUP, $ctx31, $this->emptyDevice(), $lane31, $this->allowedResult());
        self::assertFalse($res31->isAllowed(), '31st reservation across day boundary must be blocked');
        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $res31->decision);
        self::assertSame('proof_capacity', $res31->metadata?->cause);
        self::assertSame('proof', $res31->metadata->context?->scope);
    }
}
