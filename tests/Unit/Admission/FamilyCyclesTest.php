<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Enum\AdmissionLaneEnum;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionTestPolicy;
use Maatify\RateLimiter\Tests\Support\Admission\FamilyLab;
use Maatify\RateLimiter\Tests\Support\Admission\PipelineMutant;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Gate CYCLES (DEC-003; contract 4.2 and 8.3): K4 and K4P, K5 and K5p are independent logical identities for the
 * hard-block cycle history. A capacity refusal does not exist before 5-b3b, so its SC-3 clause is not part of this PR.
 */
#[Group('cycles')]
final class FamilyCyclesTest extends TestCase
{
    private static function context(bool $known): RateLimitContextDTO
    {
        return new RateLimitContextDTO(
            '198.51.100.30',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            'acct-cycles',
            null,
            'dev-cycles',
            false,
            [],
            $known,
            null,
            clientInstanceId: 'dev-cycles',
        );
    }

    /** Failures until the request that issues a hard block; returns whether one was issued. */
    private function failUntilHard(FamilyLab $lab, int $start, bool $known): bool
    {
        for ($i = 0; $i < 12; $i++) {
            $lab->at($start + $i);
            if ($lab->run(self::context($known), 'fail')['d'] === 'H') {
                return true;
            }
        }

        return false;
    }

    /**
     * Two hard blocks of one account on two families, 200 s apart (a level-2 block lasts 60 s).
     *
     * @param class-string<EvaluationPipeline> $class
     * @return list<array{key: string, cycleCount: int, newCycle: bool}>
     */
    private function twoBlocks(string $class, ?AdmissionLaneEnum $first, ?AdmissionLaneEnum $second, bool $known): array
    {
        $a = new FamilyLab(new AdmissionTestPolicy(), $first, $class);
        self::assertTrue($this->failUntilHard($a, 0, $known));
        $b = new FamilyLab(new AdmissionTestPolicy(), $second, $class, shareStateWith: $a);
        self::assertTrue($this->failUntilHard($b, 200, $known));

        return array_map(
            static fn(array $entry): array => ['key' => $entry['key'], 'cycleCount' => $entry['result']->cycleCount, 'newCycle' => $entry['result']->newCycle],
            $a->store->cycleLog,
        );
    }

    public function testK4AndK4PKeepIndependentCycleHistories(): void
    {
        $log = $this->twoBlocks(EvaluationPipeline::class, null, AdmissionLaneEnum::P, false);

        self::assertCount(2, $log);
        self::assertNotSame($log[0]['key'], $log[1]['key']);
        self::assertSame(1, $log[0]['cycleCount']);
        self::assertSame(1, $log[1]['cycleCount'], 'The first K4P block is cycle 1 whatever K4 did.');
        self::assertTrue($log[1]['newCycle']);
    }

    public function testK5AndK5pKeepIndependentCycleHistories(): void
    {
        $log = $this->twoBlocks(EvaluationPipeline::class, AdmissionLaneEnum::U, AdmissionLaneEnum::P, true);

        self::assertCount(2, $log);
        self::assertNotSame($log[0]['key'], $log[1]['key']);
        self::assertSame(1, $log[0]['cycleCount']);
        self::assertSame(1, $log[1]['cycleCount']);
        self::assertTrue($log[1]['newCycle']);
    }

    public function testDetectorSharedAccountKeyAcrossLanesSharesTheCycle(): void
    {
        $mutant = PipelineMutant::pipeline(methodBodies: ['k4KeyType' => "return 'k4';"]);
        $log = $this->twoBlocks($mutant, null, AdmissionLaneEnum::P, false);

        self::assertSame($log[0]['key'], $log[1]['key']);
        self::assertSame(2, $log[1]['cycleCount'], 'A shared key makes the second block cycle 2.');
    }

    public function testDetectorSharedDeviceKeyAcrossLanesSharesTheCycle(): void
    {
        $mutant = PipelineMutant::pipeline(methodBodies: ['k5KeyType' => "return 'k5';"]);
        $log = $this->twoBlocks($mutant, AdmissionLaneEnum::U, AdmissionLaneEnum::P, true);

        self::assertSame($log[0]['key'], $log[1]['key']);
        self::assertSame(2, $log[1]['cycleCount']);
    }
}
