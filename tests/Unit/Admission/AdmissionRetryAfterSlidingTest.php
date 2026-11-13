<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for F-185-35: exact sliding retryAfter under sliding windows.
 *
 * Given a counter or set scope with capacity 2 and window 600 s.
 * At t = 125, two requests are admitted (their expiry is 125 + 600 = 725).
 * Immediately at t = 125 (+0), the capacity is reached.
 * The sliding retryAfter is the exact seconds until the oldest live admission expires:
 * - At t = 125 (+0): retryAfter = 600 (SOFT_BLOCK)
 * - At t = 126 (+1): retryAfter = 599 (SOFT_BLOCK)
 * - At t = 724 (+599): retryAfter = 1 (SOFT_BLOCK)
 * - At t = 725 (+600): admission is ALLOW
 *
 * Verifies that at t + retryAfter, request is DECISION_ALLOW, and at t + retryAfter - 1,
 * request is DECISION_SOFT_BLOCK with retryAfter = 1.
 */
final class AdmissionRetryAfterSlidingTest extends TestCase
{
    private const IP = '198.51.100.99';

    public function testSlidingRetryAfterExactValuesAcrossWindow(): void
    {
        $lab = new EngineLab(EngineLab::budgets(sourceCapacity: 2));

        // Start at t0 = 125 s from lab baseline
        $lab->at(125);
        $t0 = $lab->now();

        // Admit 2 requests to exhaust capacity (capacity = 2, TTL = 600)
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $lab->check($lab->context('acct-1', self::IP))->decision);
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $lab->check($lab->context('acct-2', self::IP))->decision);

        // At +0 (t = 125): capacity reached, retryAfter must be 600
        $r0 = $lab->check($lab->context('acct-3', self::IP));
        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $r0->decision);
        self::assertSame(600, $r0->retryAfter);

        // At +1 (t = 126): retryAfter must be 599
        $lab->at(125 + 1);
        $r1 = $lab->check($lab->context('acct-3', self::IP));
        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $r1->decision);
        self::assertSame(599, $r1->retryAfter);

        // At +599 (t = 724): retryAfter must be 1
        $lab->at(125 + 599);
        $r599 = $lab->check($lab->context('acct-3', self::IP));
        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $r599->decision);
        self::assertSame(1, $r599->retryAfter);

        // At +600 (t = 725): window has slid past the oldest admission, request is ALLOW
        $lab->at(125 + 600);
        $r600 = $lab->check($lab->context('acct-3', self::IP));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $r600->decision);
    }

    public function testRetryAfterBoundaryGuaranteesAllowanceAtTarget(): void
    {
        $lab = new EngineLab(EngineLab::budgets(sourceCapacity: 2));
        $lab->at(200);

        // Fill capacity
        $lab->check($lab->context('acct-a', self::IP));
        $lab->check($lab->context('acct-b', self::IP));

        // Advance 50 seconds into window (t = 250)
        $lab->at(250);
        $refusal = $lab->check($lab->context('acct-c', self::IP));
        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $refusal->decision);
        $retryAfter = $refusal->retryAfter;
        self::assertSame(550, $retryAfter);

        // At t + retryAfter - 1 (t = 799): must still be SOFT_BLOCK with retryAfter = 1
        $lab->at(250 + $retryAfter - 1);
        $beforeTarget = $lab->check($lab->context('acct-c', self::IP));
        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $beforeTarget->decision);
        self::assertSame(1, $beforeTarget->retryAfter);

        // At t + retryAfter (t = 800): must be ALLOW
        $lab->at(250 + $retryAfter);
        $atTarget = $lab->check($lab->context('acct-c', self::IP));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $atTarget->decision);
    }
}
