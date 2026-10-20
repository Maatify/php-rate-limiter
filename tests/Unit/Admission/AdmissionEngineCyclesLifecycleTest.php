<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;
use Maatify\RateLimiter\Tests\Support\Admission\PipelineMutant;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Gates CYCLES and LIFECYCLE re-run with the lane COMING FROM THE ENGINE (5b3b/PLAN.md §5.2): the proven lane is the one the
 * engine resolves from a verified credential, every outcome carries the permit of its admission. K4 / K4P and K5 / K5p keep
 * separate cycle histories; a capacity refusal creates no cycle; K4P re-entry evidence is served and claimed on the proven lane
 * only. Detectors: the 5-b3a seams `k4KeyType` and `k5KeyType`. An opted policy persists no account-scoped state on U, so the K4
 * history comes from the same engine with its fence closed (today's K4 key), as at a rollout. The U bucket is raised in this harness
 * (`bucketTakes` 100) so that the failures reach a hard block on the unproven door; no gate value depends on it.
 */
#[Group('cycles')]
#[Group('lifecycle')]
final class AdmissionEngineCyclesLifecycleTest extends TestCase
{
    private const IP = '198.51.100.30';
    private const ACCOUNT = 'acct-cycles';
    private const INSTANCE = 'dev-cycles';

    /** @param class-string<EvaluationPipeline> $pipeline */
    private static function lab(string $pipeline = EvaluationPipeline::class, bool $openFence = true): EngineLab
    {
        return new EngineLab(EngineLab::budgets(bucketTakes: 100), $openFence, pipelineClass: $pipeline, recordCycles: true);
    }

    private static function context(EngineLab $lab, bool $proven, bool $known): RateLimitContextDTO
    {
        return new RateLimitContextDTO(
            self::IP,
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            self::ACCOUNT,
            null,
            self::INSTANCE,
            false,
            [],
            $known,
            null,
            clientInstanceId: self::INSTANCE,
            admissionCredential: $proven ? $lab->mint(self::ACCOUNT, self::INSTANCE) : null,
        );
    }

    /**
     * Admitted failures through the engine (check, then the outcome with its permit) until the outcome is a hard block. A Host
     * cannot fail without an admission: a soft-blocked check is retried after its `retryAfter`, as a Host would.
     *
     * @return array{at: int, retryAfter: int} the time of the hard block and its duration
     */
    private static function failUntilHard(EngineLab $lab, int $start, bool $proven, bool $known): array
    {
        $t = $start;
        for ($i = 0; $i < 30; $i++) {
            $lab->at($t);
            $ctx = self::context($lab, $proven, $known);
            $check = $lab->check($ctx);
            if ($check->decision !== RateLimitResultDTO::DECISION_ALLOW) {
                $t += max(1, $check->retryAfter);
                continue;
            }
            $outcome = $lab->outcome($ctx, false, EngineLab::permit($check));
            if ($outcome->decision === RateLimitResultDTO::DECISION_HARD_BLOCK) {
                self::assertNotNull($outcome->retryAfter, 'a hard block carries its duration');

                return ['at' => $t, 'retryAfter' => $outcome->retryAfter];
            }
            $t++;
        }

        self::fail('no hard block was reached through the engine');
    }

    /**
     * A hard block on the first door, then one on the second door after the first block expired, for the same account. The
     * first door "S0" is the engine with its fence closed (no lane: the K4 key of today).
     *
     * @param 'S0'|'U'|'P' $first
     * @return list<array{key: string, cycleCount: int, newCycle: bool}>
     */
    private static function twoBlocks(EngineLab $lab, string $first, bool $known): array
    {
        $a = self::failUntilHard($lab, 0, $first === 'P', $known);
        if ($first === 'S0') {
            $lab->openFence();
        }
        self::failUntilHard($lab, $a['at'] + $a['retryAfter'] + 1, true, $known);

        return array_map(
            static fn(array $e): array => ['key' => $e['key'], 'cycleCount' => $e['result']->cycleCount, 'newCycle' => $e['result']->newCycle],
            $lab->cycles->cycleLog ?? [],
        );
    }

    public function testK4AndK4PKeepIndependentCycleHistoriesWithTheLaneFromTheEngine(): void
    {
        $log = self::twoBlocks(self::lab(openFence: false), 'S0', false);

        self::assertCount(2, $log);
        self::assertNotSame($log[0]['key'], $log[1]['key']);
        self::assertSame(1, $log[0]['cycleCount']);
        self::assertSame(1, $log[1]['cycleCount'], 'the first K4P block is cycle 1 whatever K4 did');
    }

    public function testK5AndK5pKeepIndependentCycleHistoriesWithTheLaneFromTheEngine(): void
    {
        $log = self::twoBlocks(self::lab(), 'U', true);

        self::assertCount(2, $log);
        self::assertNotSame($log[0]['key'], $log[1]['key']);
        self::assertSame(1, $log[1]['cycleCount']);
    }

    public function testDetectorK4KeyTypeSharedAcrossLanes(): void
    {
        $log = self::twoBlocks(self::lab(PipelineMutant::pipeline(methodBodies: ['k4KeyType' => "return 'k4';"]), false), 'S0', false);

        self::assertSame($log[0]['key'], $log[1]['key']);
        self::assertSame(2, $log[1]['cycleCount'], 'DETECTOR: a shared account key makes the second block cycle 2');
    }

    public function testDetectorK5KeyTypeSharedAcrossLanes(): void
    {
        $log = self::twoBlocks(self::lab(PipelineMutant::pipeline(methodBodies: ['k5KeyType' => "return 'k5';"])), 'U', true);

        self::assertSame($log[0]['key'], $log[1]['key']);
        self::assertSame(2, $log[1]['cycleCount'], 'DETECTOR: a shared device key makes the second block cycle 2');
    }

    /**
     * The SC-3 clause of CYCLES (LT-17 reading): a capacity refusal creates no cycle, block, pause or watcher event. The pre-check
     * runs before the reservation and writes its observations either way (reported by SC-3), so the comparison is with a twin lab
     * whose bucket admits the same twenty checks: the score store and the cycle log end identical.
     */
    public function testACapacityRefusalCreatesNoCycle(): void
    {
        $refusing = new EngineLab(EngineLab::budgets(bucketTakes: 1), recordCycles: true);
        $admitting = new EngineLab(EngineLab::budgets(bucketTakes: 100), recordCycles: true);
        foreach ([$refusing, $admitting] as $lab) {
            $ctx = self::context($lab, false, false);
            $lab->outcome($ctx, false, EngineLab::permit($lab->check($ctx)));
            for ($i = 1; $i <= 20; $i++) {
                $lab->at($i);
                $r = $lab->check($ctx);
                self::assertSame($lab === $refusing ? 'account_bucket' : null, EngineLab::cause($r));
            }
        }

        self::assertSame(serialize($admitting->cycles), serialize($refusing->cycles), 'twenty capacity refusals write what twenty admitted pre-checks write');
        self::assertCount(0, $refusing->cycles->cycleLog ?? []);
    }

    // ---- LIFECYCLE ---------------------------------------------------------------------------------------------------------

    /**
     * The 5-b3a LIFECYCLE block through the engine: three admissions on the proven door (unknown device) whose three outcomes are
     * failures, the third a hard block; then the clock at 603 s, as in `FamilyLifecycleTest`.
     */
    private static function blockedOnP(EngineLab $lab): void
    {
        $permits = [];
        for ($i = 0; $i < 3; $i++) {
            $lab->at($i);
            $permits[] = EngineLab::permit($lab->check(self::context($lab, true, false)));
        }
        $last = '';
        foreach ($permits as $permit) {
            $last = $lab->outcome(self::context($lab, true, false), false, $permit)->decision;
        }
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $last);
        $lab->at(603);
    }

    public function testReentryEvidenceIsServedAndClaimedOnTheProvenLaneFromTheEngineOnly(): void
    {
        $lab = self::lab();
        self::blockedOnP($lab);

        $served = $lab->check(self::context($lab, true, false));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $served->decision);
        $id = $served->metadata?->postPunishmentReentry?->id;
        self::assertNotNull($id);
        self::assertNull($lab->check(self::context($lab, false, false))->metadata?->postPunishmentReentry, 'not exposed on U');

        self::assertFalse($lab->engine->claimPostPunishmentReentry(self::context($lab, false, false), EngineLab::POLICY, $id), 'U cannot claim');
        self::assertTrue($lab->engine->claimPostPunishmentReentry(self::context($lab, true, false), EngineLab::POLICY, $id));
        self::assertFalse($lab->engine->claimPostPunishmentReentry(self::context($lab, true, false), EngineLab::POLICY, $id), 'one claim');
    }

    public function testDetectorK4PMergedIntoK4ExposesTheEvidenceOnUThroughTheEngine(): void
    {
        $lab = self::lab(PipelineMutant::pipeline(methodBodies: ['k4KeyType' => "return 'k4';"]));
        self::blockedOnP($lab);

        self::assertNotNull($lab->check(self::context($lab, false, false))->metadata?->postPunishmentReentry, 'DETECTOR: evidence visible on U');
    }
}
