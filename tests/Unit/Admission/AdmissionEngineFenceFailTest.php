<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\AdmissionBindingControlInterface;
use Maatify\RateLimiter\Service\AdmissionBindingEpochInterface;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;
use Maatify\RateLimiter\Tests\Support\Admission\ServiceMutant;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The fleet fence (LT-13, M2), the FAIL gate (DEC-015, C-c5), S8 and its read side (LT-21), and the reported outcome-source
 * behaviour (LT-14).
 */
#[Group('admission-engine')]
final class AdmissionEngineFenceFailTest extends TestCase
{
    private const IP = '198.51.100.7';

    // ---- fence ------------------------------------------------------------------------------------------------------------

    public function testAClosedFenceIsTheEngineWithoutAdmissionByteForByte(): void
    {
        $lab = new EngineLab(EngineLab::budgets(sourceCapacity: 1), openFence: false);
        for ($i = 0; $i < 3; $i++) {
            $r = $lab->check($lab->context('acct-' . $i, self::IP));
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $r->decision);
            self::assertNull(EngineLab::permit($r), 'no permit is issued while the fence is closed');
        }
        // Outcomes are legacy unbound outcomes (no permit is required while the fence is closed).
        $r = $lab->engine->limit($lab->context('acct-1', self::IP), RateLimitCommand::recordFailure(EngineLab::POLICY));
        self::assertStringStartsNotWith('permit_', (string) EngineLab::cause($r));
        self::assertSame([], array_values(array_filter($lab->admissionStore->calls, static fn(string $c): bool => $c === 'reserve' || $c === 'outcome' || $c === 'claim')));
    }

    public function testAnOpenFenceEnablesTheAdmissionPath(): void
    {
        $lab = new EngineLab(EngineLab::budgets(sourceCapacity: 1), openFence: false);
        $lab->check($lab->context('acct-1', self::IP));
        $lab->openFence();
        $lab->check($lab->context('acct-2', self::IP));

        $r = $lab->check($lab->context('acct-3', self::IP));
        self::assertSame('source_capacity', EngineLab::cause($r));
    }

    public function testDetectorAFenceThatIsIgnoredAdmitsThroughAClosedFence(): void
    {
        $class = ServiceMutant::load('AdmissionRuntime', ['fenceOpen' => 'return true;']);
        $lab = new EngineLab(EngineLab::budgets(sourceCapacity: 1), openFence: false, runtimeClass: $class);
        $lab->check($lab->context('acct-1', self::IP));

        self::assertSame('source_capacity', EngineLab::cause($lab->check($lab->context('acct-2', self::IP))), 'DETECTOR: an ignored fence reserves although it is closed');
    }

    // ---- FAIL ----------------------------------------------------------------------------------------------------------------

    public function testATypedOutageInTheReserveTakesTheExistingTypedPathWithNoPartialWrite(): void
    {
        $lab = new EngineLab();
        $before = serialize($lab->store);
        $lab->admissionStore->fail['reserve'] = new BackendFailureException('reserve down');
        $r = $lab->check($lab->context('acct-1', self::IP));

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $r->decision);
        self::assertSame('FAIL_CLOSED', $r->failureMode);
        self::assertNull(EngineLab::permit($r));
        // Only the pipeline's own pre-check observation may have been written; no admission state exists.
        unset($lab->admissionStore->fail['reserve']);
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $lab->check($lab->context('acct-1', self::IP))->decision);
        self::assertNotSame('', $before);
    }

    public function testATypedOutageInTheEpochReadIsTheSamePath(): void
    {
        $lab = new EngineLab();
        $credential = $lab->mint('acct-1', 'inst-1');
        $lab->admissionStore->fail['readEpoch'] = new BackendFailureException('epoch down');
        $r = $lab->check($lab->context('acct-1', self::IP, 'inst-1', $credential));

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $r->decision);
        self::assertSame('FAIL_CLOSED', $r->failureMode);
    }

    public function testATypedOutageInTheOutcomeAndTheClaimIsTheSamePath(): void
    {
        foreach (['claim', 'outcome'] as $operation) {
            $lab = new EngineLab();
            $ctx = $lab->context('acct-1', self::IP);
            $permit = (string) EngineLab::permit($lab->check($ctx));
            $lab->admissionStore->fail[$operation] = new BackendFailureException($operation . ' down');
            $r = $lab->outcome($ctx, false, $permit);

            self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $r->decision, $operation);
            self::assertSame('FAIL_CLOSED', $r->failureMode, $operation);
        }
    }

    public function testMalformedStoredStateIsARateLimiterExceptionAndNeverFallback(): void
    {
        $lab = new EngineLab();
        $lab->admissionStore->fail['reserve'] = new RateLimiterException('malformed admission state');
        $this->expectException(RateLimiterException::class);
        $lab->check($lab->context('acct-1', self::IP));
    }

    public function testAnUnclassifiedThrowableEscapesAndNeverEntersTheCircuitOrTheFallback(): void
    {
        $lab = new EngineLab();
        $lab->admissionStore->fail['reserve'] = new \LogicException('unclassified');
        try {
            $lab->check($lab->context('acct-1', self::IP));
            self::fail('The unclassified throwable must escape.');
        } catch (\LogicException) {
            unset($lab->admissionStore->fail['reserve']);
        }
        // The circuit saw no failure: the next request is processed normally.
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $lab->check($lab->context('acct-1', self::IP))->decision);
    }

    public function testDetectorACatchAllInTheEngineWouldHideTheUnclassifiedThrowable(): void
    {
        $class = ServiceMutant::load('RateLimiterEngine', [], [['} catch (BackendFailureException) {', '} catch (\Throwable) {']]);
        $lab = new EngineLab(engineClass: $class);
        $lab->admissionStore->fail['reserve'] = new \LogicException('unclassified');
        $r = $lab->check($lab->context('acct-1', self::IP));

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $r->decision, 'DETECTOR: a catch-all turns an unclassified throwable into a typed outage');
    }

    public function testDegradedModeAdmitsNothingAndReadsNoLaneEpochOrFence(): void
    {
        $lab = new EngineLab();
        // Open the circuit with typed outages until the engine serves from the bounded fallback.
        $lab->admissionStore->fail['reserve'] = new BackendFailureException('down');
        for ($i = 0; $i < 12; $i++) {
            $lab->check($lab->context('acct-1', self::IP));
        }
        $ctx = $lab->context('acct-1', self::IP, 'inst-1', $lab->mint('acct-1', 'inst-1'));
        $lab->admissionStore->calls = [];
        $lab->admissionStore->fail = [];
        $r = $lab->check($ctx);

        self::assertSame([], $lab->admissionStore->calls, 'an open circuit performs no admission call, no epoch read, no fence read');
        self::assertNotSame('', $r->decision);
    }

    // ---- S8 and its read side ------------------------------------------------------------------------------------------------

    public function testTheEngineImplementsTheBindingControlAndTheEpochRead(): void
    {
        $lab = new EngineLab();
        self::assertInstanceOf(AdmissionBindingControlInterface::class, $lab->engine);
        self::assertInstanceOf(AdmissionBindingEpochInterface::class, $lab->engine);
        self::assertSame(0, $lab->engine->bindingEpoch('acct-1'));
        $lab->engine->revokeAccountBinding('acct-1');
        $lab->engine->revokeAccountBinding('acct-1');
        self::assertSame(2, $lab->engine->bindingEpoch('acct-1'));
        self::assertSame(0, $lab->engine->bindingEpoch('acct-2'));
    }

    // ---- LT-14: reported behaviour --------------------------------------------------------------------------------------------

    public function testReportedAnOutcomeFromAnotherSourceIsScoredAndLeavesTheAdmissionSourceUnchanged(): void
    {
        // Declared behaviour, not a gate (Lead, LT-14): the failure set of an outcome is keyed by the OUTCOME request's source.
        $lab = new EngineLab(EngineLab::budgets(sourceFailureCap: 1));
        $admitted = $lab->context('acct-1', self::IP);
        $permit = (string) EngineLab::permit($lab->check($admitted));
        $lab->outcome($lab->context('acct-1', '203.0.113.50'), false, $permit);

        // The failure landed on 203.0.113.50: that source is capped, the admission source is not (its pending entry settles at D).
        $lab->at(31);
        self::assertSame('source_failure_cap', EngineLab::cause($lab->check($lab->context('acct-2', '203.0.113.50'))));
    }
}
