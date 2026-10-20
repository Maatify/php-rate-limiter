<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\ArchiveData;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\FixtureReplays;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\GateReport;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\Lt16Cases;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\ReplaySession;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\StreamReplay;
use Maatify\RateLimiter\Tests\Support\Admission\CountingPipeline;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;
use Maatify\RateLimiter\Tests\Support\Admission\ServiceMutant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * NM-10 (b), the binding per admission (5b3b/PLAN.md §11.3): on BRU24 and STEAL, at every registered drop share, the Host model
 * sends besides the registered outcome of each admission the six cases of LT-16 for the same admission. No admission is scored
 * twice and none without its permit: the outcome commands the pipeline scores equal the accepted claims and equal those of the
 * same replay without the six cases, the replay rows of the two runs are identical, and every case returns its registered
 * result. Detector: an optional-permit engine (an outcome without a permit is scored on the unproven lane) scores a duplicate.
 */
#[Group('nm10')]
#[Group('archive')]
final class AdmissionNm10BindingTest extends TestCase
{
    private const CASES = ['missing', 'duplicate', 'conflict', 'rejected', 'late', 'unknown'];

    public static function setUpBeforeClass(): void
    {
        ini_set('memory_limit', '1G');
    }

    /** @return iterable<string, array{float}> */
    public static function dropShares(): iterable
    {
        foreach ([0.10, 0.25, 0.50, 1.00] as $share) {
            yield 'share ' . $share => [$share];
        }
    }

    /**
     * @param class-string $engineClass
     * @return array{rows: array<string, mixed>, scored: int, accepted: int}
     */
    private static function bru(float $share, ?Lt16Cases $cases, string $engineClass = RateLimiterEngine::class): array
    {
        $lab = new EngineLab(StreamReplay::fullBudgets(), engineClass: $engineClass, pipelineClass: CountingPipeline::class);
        $s = new ReplaySession($lab, 'pre+out', $share, observer: $cases?->observer());
        $rows = $s->replay(ArchiveData::get()->bru(false))->result();
        /** @var CountingPipeline $pipeline */
        $pipeline = $lab->pipeline;

        return ['rows' => $rows, 'scored' => $pipeline->scoredOutcomes, 'accepted' => self::accepted($rows['reach'], $share)];
    }

    /** The outcomes the registered Host sends: one per reached attempt not dropped by the §4 rule. */
    private static function accepted(string $reach, float $share): int
    {
        $n = 0;
        $j = 0;
        for ($i = 0, $len = strlen($reach); $i < $len; $i++) {
            if ($reach[$i] === '1' && ! StreamReplay::selects($j++, $share)) {
                $n++;
            }
        }

        return $n;
    }

    /** @return array{rows: array<string, mixed>, scored: int} */
    private static function steal(float $share, ?Lt16Cases $cases): array
    {
        $labs = [];
        $rows = (new FixtureReplays(static function () use (&$labs): EngineLab {
            return $labs[] = new EngineLab(StreamReplay::fullBudgets(), pipelineClass: CountingPipeline::class);
        }, $cases?->observer(), $share))->steal();
        /** @var CountingPipeline $pipeline */
        $pipeline = $labs[0]->pipeline;

        return ['rows' => $rows, 'scored' => $pipeline->scoredOutcomes];
    }

    #[DataProvider('dropShares')]
    public function testBru24NoAdmissionIsScoredTwiceOrWithoutItsPermit(float $share): void
    {
        $cases = new Lt16Cases();
        $honest = self::bru($share, null);
        $abused = self::bru($share, $cases);
        GateReport::emit('nm10-b', ['stream' => 'BRU24', 'share' => $share, 'scored' => $abused['scored'], 'accepted' => $honest['accepted'], 'cases' => $cases->seen]);

        self::assertSame([], $cases->wrong);
        self::assertSame($honest['rows'], $abused['rows'], 'the six cases change no replay row');
        self::assertSame($honest['accepted'], $honest['scored'], 'the honest Host: one scoring per sent outcome');
        self::assertSame($honest['scored'], $abused['scored'], 'the six cases are never scored');
        self::assertGreaterThan(0, $cases->seen['missing'] ?? 0);
    }

    #[DataProvider('dropShares')]
    public function testStealNoAdmissionIsScoredTwiceOrWithoutItsPermit(float $share): void
    {
        $cases = new Lt16Cases();
        $honest = self::steal($share, null);
        $abused = self::steal($share, $cases);
        GateReport::emit('nm10-b', ['stream' => 'STEAL', 'share' => $share, 'scored' => $abused['scored'], 'cases' => $cases->seen]);

        self::assertSame([], $cases->wrong);
        self::assertSame($honest['rows'], $abused['rows'], 'the six cases change no STEAL value');
        self::assertSame($honest['scored'], $abused['scored'], 'the six cases are never scored');
    }

    /** Over the four shares every one of the six cases occurs on each of BRU24 and STEAL (otherwise (b) is not evidenced). */
    public function testEveryCaseOccursOnBothStreams(): void
    {
        foreach (['BRU24', 'STEAL'] as $stream) {
            $cases = new Lt16Cases();
            foreach ([0.10, 0.25, 0.50, 1.00] as $share) {
                $stream === 'BRU24' ? self::bru($share, $cases) : self::steal($share, $cases);
            }
            self::assertSame([], $cases->wrong);
            foreach (self::CASES as $case) {
                self::assertGreaterThan(0, $cases->seen[$case] ?? 0, "{$stream}: the case {$case} occurred");
            }
        }
    }

    /** DETECTOR: an engine that accepts an outcome with no permit and scores it unbound records a duplicate twice on BRU24. */
    public function testDetectorAnOptionalPermitScoresADuplicateTwiceOnBru24(): void
    {
        $engine = ServiceMutant::load('RateLimiterEngine', [], [[
            'throw new RateLimiterException("Policy {$policy->getName()} invalid use: an outcome of an admission policy requires the permit issued with its admission.");',
            'return $this->pipeline->process($policy, $context, $request, $device, \\Maatify\\RateLimiter\\Enum\\AdmissionLaneEnum::U);',
        ]]);
        $cases = new Lt16Cases();
        $honest = self::bru(0.10, null, $engine);
        $abused = self::bru(0.10, $cases, $engine);

        self::assertContains('missing was accepted', $cases->wrong);
        self::assertGreaterThan($honest['scored'], $abused['scored'], 'DETECTOR: the outcome sent without its permit is scored a second time');
    }
}
