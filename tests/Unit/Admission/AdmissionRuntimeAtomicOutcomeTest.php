<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Tests\Support\Admission\CountingPipeline;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;
use PHPUnit\Framework\TestCase;

final class AdmissionRuntimeAtomicOutcomeTest extends TestCase
{
    public function testRuntimeCompletesAndScoresOnePermitThroughTheAtomicStoreOperation(): void
    {
        $lab = new EngineLab(pipelineClass: CountingPipeline::class);
        $context = $lab->context('account');
        $permit = EngineLab::permit($lab->check($context));
        self::assertNotNull($permit);
        self::assertInstanceOf(CountingPipeline::class, $lab->pipeline);

        $lab->admissionStore->calls = [];
        $result = $lab->outcome($context, true, $permit);

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertSame(['claimWithOutcome'], array_values(array_diff($lab->admissionStore->calls, ['readEpoch'])));
        self::assertCount(1, $lab->admissionStore->outcomes);
        self::assertSame(AdmissionOutcomeKindEnum::SUCCESS, $lab->admissionStore->outcomes[0]->outcome);
        self::assertSame(1, $lab->pipeline->scoredOutcomes);

        $lab->admissionStore->calls = [];
        $duplicate = $lab->outcome($context, true, $permit);

        self::assertSame('permit_duplicate', EngineLab::cause($duplicate));
        self::assertSame(['claimWithOutcome'], array_values(array_diff($lab->admissionStore->calls, ['readEpoch'])));
        self::assertCount(1, $lab->admissionStore->outcomes);
        self::assertSame(1, $lab->pipeline->scoredOutcomes);
    }

    public function testUnavailableAtomicStoreDoesNotApplyOrScoreTheOutcome(): void
    {
        $lab = new EngineLab(pipelineClass: CountingPipeline::class);
        $context = $lab->context('account');
        $permit = EngineLab::permit($lab->check($context));
        self::assertNotNull($permit);
        self::assertInstanceOf(CountingPipeline::class, $lab->pipeline);

        $lab->admissionStore->calls = [];
        $lab->admissionStore->fail['claimWithOutcome'] = new BackendFailureException('Test store unavailable');
        $result = $lab->outcome($context, true, $permit);

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame('FAIL_CLOSED', $result->failureMode);
        self::assertSame(['claimWithOutcome'], array_values(array_diff($lab->admissionStore->calls, ['readEpoch'])));
        self::assertSame([], $lab->admissionStore->outcomes);
        self::assertSame(0, $lab->pipeline->scoredOutcomes);
    }
}
