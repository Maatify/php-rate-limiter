<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Enum\AdmissionLaneEnum;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Tests\Support\Admission\FamilyLab;
use Maatify\RateLimiter\Tests\Support\Admission\OptedLoginPolicy;
use Maatify\RateLimiter\Tests\Support\Admission\PipelineMutant;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Gate LIFECYCLE (DEC-007; contract 4.3 and 8.3). K4P gets the generation-bound primitives through the `k4` slot with
 * `PunishmentLifecycleStoreInterface` unchanged; its re-entry evidence is exposed on the proven lane only;
 * `recordSuccess` never resets it. The fourth point (an opted policy that is not a re-entry policy is refused a claim)
 * lives in the engine, which this PR does not touch: `PostPunishmentReentryLifecycleTest` covers it unchanged.
 */
#[Group('lifecycle')]
final class FamilyLifecycleTest extends TestCase
{
    private static function context(): RateLimitContextDTO
    {
        return new RateLimitContextDTO(
            '198.51.100.40',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            'acct-life',
            null,
            'dev-life',
            false,
            [],
            false,
            null,
            clientInstanceId: 'dev-life',
        );
    }

    private static function k4pKey(): string
    {
        return hash_hmac('sha256', 'login_protection:rate_limiter:k4p:v2:prod:acct-life', FamilyLab::SECRET);
    }

    private static function k4Key(): string
    {
        return hash_hmac('sha256', 'login_protection:rate_limiter:k4:v2:prod:acct-life', FamilyLab::SECRET);
    }

    /** Three failures from an unknown device on the proven lane: the third is the hard block. */
    private function hardBlockOnP(FamilyLab $p): void
    {
        $last = '';
        for ($i = 0; $i < 3; $i++) {
            $p->at($i);
            $last = $p->run(self::context(), 'fail')['d'];
        }
        self::assertSame('H', $last);
    }

    /**
     * @param class-string<EvaluationPipeline> $class
     * @return array{p: FamilyLab, u: FamilyLab, none: FamilyLab}
     */
    private function labs(string $class = EvaluationPipeline::class): array
    {
        $p = new FamilyLab(new OptedLoginPolicy(), AdmissionLaneEnum::P, $class);

        return [
            'p' => $p,
            'u' => new FamilyLab(new OptedLoginPolicy(), AdmissionLaneEnum::U, $class, shareStateWith: $p),
            'none' => new FamilyLab(new OptedLoginPolicy(), null, $class, shareStateWith: $p),
        ];
    }

    public function testK4PGetsTheGenerationBoundPrimitivesThroughTheK4Slot(): void
    {
        ['p' => $p] = $this->labs();
        $this->hardBlockOnP($p);

        $block = $p->store->checkBlock(self::k4pKey());
        self::assertNotNull($block);
        self::assertSame(2, $block->level);

        $state = $p->store->readGenerationBoundScoreState(self::k4pKey(), null);
        self::assertNotNull($state);
        self::assertNotNull($state->generation);

        // Atomic hard block with cycle accounting on the K4P key.
        $cycles = array_values(array_filter($p->store->cycleLog, static fn(array $e): bool => $e['key'] === self::k4pKey()));
        self::assertCount(1, $cycles);
        self::assertSame(1, $cycles[0]['result']->cycleCount);

        // The reference store exposes the evidence once the block has expired (DEC-007).
        $p->at(603);
        $afterBlock = $p->store->readGenerationBoundScoreState(self::k4pKey(), null);
        self::assertNotNull($afterBlock?->postPunishmentReentry);
        self::assertNull($p->store->readGenerationBoundScoreState(self::k4Key(), null), 'Nothing is written on the K4 key.');
    }

    public function testReentryEvidenceIsServedAndClaimedOnTheProvenLaneOnly(): void
    {
        ['p' => $p, 'u' => $u, 'none' => $none] = $this->labs();
        $this->hardBlockOnP($p);
        $p->at(603);

        $servedOnP = $p->result(self::context(), 'check');
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $servedOnP->decision);
        $id = $servedOnP->metadata?->postPunishmentReentry?->id;
        self::assertNotNull($id);

        self::assertNull($u->result(self::context(), 'check')->metadata?->postPunishmentReentry, 'Not exposed on U.');
        self::assertNull($none->result(self::context(), 'check')->metadata?->postPunishmentReentry, 'Not exposed without a lane.');

        $device = $p->resolver->resolve(self::context());
        $name = 'login_protection';
        self::assertFalse($p->pipeline->claimPostPunishmentReentry(self::context(), $device, $name, $id, AdmissionLaneEnum::U));
        self::assertFalse($p->pipeline->claimPostPunishmentReentry(self::context(), $device, $name, $id, null));
        self::assertTrue($p->pipeline->claimPostPunishmentReentry(self::context(), $device, $name, $id, AdmissionLaneEnum::P));
        self::assertFalse($p->pipeline->claimPostPunishmentReentry(self::context(), $device, $name, $id, AdmissionLaneEnum::P), 'One claim.');
    }

    public function testRecordSuccessNeverResetsTheEvidence(): void
    {
        ['p' => $p] = $this->labs();
        $this->hardBlockOnP($p);
        $p->at(603);
        $before = $p->store->readGenerationBoundScoreState(self::k4pKey(), null);

        self::assertSame('A', $p->run(self::context(), 'ok')['d']);
        $after = $p->store->readGenerationBoundScoreState(self::k4pKey(), null);

        self::assertNotNull($before?->postPunishmentReentry);
        self::assertNotNull($after?->postPunishmentReentry);
        self::assertSame($before->generation, $after->generation);
        self::assertSame($before->postPunishmentReentry->id, $after->postPunishmentReentry->id);
    }

    public function testDetectorK4PMergedIntoK4ExposesTheEvidenceOnU(): void
    {
        $mutant = PipelineMutant::pipeline(methodBodies: ['k4KeyType' => "return 'k4';"]);
        ['p' => $p, 'u' => $u] = $this->labs($mutant);
        $this->hardBlockOnP($p);
        $p->at(603);

        self::assertNotNull(
            $u->result(self::context(), 'check')->metadata?->postPunishmentReentry,
            'With one key for both families the evidence is visible on U, which is what the gate forbids.',
        );
    }
}
