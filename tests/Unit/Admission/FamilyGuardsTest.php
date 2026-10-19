<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Enum\AdmissionLaneEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Tests\Support\Admission\FamilyLab;
use Maatify\RateLimiter\Tests\Support\Admission\OptedLoginPolicy;
use Maatify\RateLimiter\Tests\Support\Admission\OptedLoginWithCollectiveThresholdsPolicy;
use Maatify\RateLimiter\Tests\Support\Admission\PipelineMutant;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Gate GUARDS (5b3a/PLAN.md section 5): the study guards of the opted policy as explicit rules, one test each, each
 * with the mutation that must break it. Decisions: D2, D4 to D8.
 */
#[Group('guards')]
final class FamilyGuardsTest extends TestCase
{
    private const SECRET = FamilyLab::SECRET;

    private static function ua(int $major): string
    {
        return "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/{$major}.0.0.0 Safari/537.36";
    }

    private static function context(string $ip, string $account, string $device, int $major = 126, bool $known = false): RateLimitContextDTO
    {
        return new RateLimitContextDTO(
            $ip,
            self::ua($major),
            $account,
            null,
            $device,
            false,
            [],
            $known,
            null,
            clientInstanceId: $device,
        );
    }

    /** @param class-string<EvaluationPipeline> $class */
    private function lab(?AdmissionLaneEnum $lane, string $class = EvaluationPipeline::class, ?BlockPolicyInterface $policy = null): FamilyLab
    {
        return new FamilyLab($policy ?? new OptedLoginPolicy(), $lane, $class);
    }

    /** One source, many accounts, one device each: the shape of a collective (K1/K2/K3) attack. */
    private function sourceSweep(FamilyLab $lab, int $requests): string
    {
        $decisions = '';
        for ($i = 0; $i < $requests; $i++) {
            $lab->at($i);
            $decisions .= $lab->run(self::context('203.0.113.50', "acct-{$i}", "dev-{$i}"), 'check')['d'];
        }

        return $decisions;
    }

    /**
     * @param class-string<EvaluationPipeline> $class
     * @return list<string> decisions on null, U and P
     */
    private function sweepOnEveryLane(string $class = EvaluationPipeline::class): array
    {
        return [
            $this->sourceSweep($this->lab(null, $class), 8),
            $this->sourceSweep($this->lab(AdmissionLaneEnum::U, $class), 8),
            $this->sourceSweep($this->lab(AdmissionLaneEnum::P, $class), 8),
        ];
    }

    public function testG1CollectiveBlocksAreNotIssuedOnEitherLane(): void
    {
        [$none, $u, $p] = $this->sweepOnEveryLane();

        self::assertStringContainsString('H', $none, 'Without a lane the collective rules still block the source.');
        self::assertSame('AAAAAAAA', $u);
        self::assertSame('AAAAAAAA', $p);
    }

    public function testG2ChurnDilutionAndSprayObservationsAreNotWrittenWithALane(): void
    {
        $counts = [];
        foreach ([null, AdmissionLaneEnum::U, AdmissionLaneEnum::P] as $lane) {
            $lab = $this->lab($lane);
            for ($i = 0; $i < 4; $i++) {
                $lab->at($i);
                $lab->run(self::context('203.0.113.50', "acct-{$i}", "dev-{$i}"), 'check');
            }
            $counts[$lane->value ?? 'none'] = count($lab->corr->distinctKeys);
        }

        // U keeps only the two device-cap observations per request; P keeps none; no lane keeps the rest.
        self::assertSame(8, $counts['U']);
        self::assertSame(0, $counts['P']);
        self::assertGreaterThan(8, $counts['none']);
    }

    public function testG3ProvenLaneStopsTheDeviceCapObservationFloodAndK1ToK3(): void
    {
        $key = hash_hmac('sha256', 'login_protection:rate_limiter:aux:flood_stage:v1:prod:acct-f', self::SECRET);
        $incrementsOf = function (?AdmissionLaneEnum $lane) use ($key): int {
            $lab = $this->lab($lane);
            for ($i = 0; $i < 7; $i++) {
                $lab->at($i);
                $lab->run(self::context('198.51.100.5', 'acct-f', "dev-f{$i}", 120 + $i), 'check');
            }

            return count(array_keys($lab->corr->watchIncrements, $key, true));
        };

        self::assertGreaterThan(0, $incrementsOf(AdmissionLaneEnum::U), 'Flood bookkeeping stays on U.');
        self::assertSame(0, $incrementsOf(AdmissionLaneEnum::P), 'No flood on P.');
    }

    public function testG3bProvenLaneIgnoresK1ToK3ThresholdsButUnprovenKeepsThemUnderTheUnprovenRule(): void
    {
        $policy = new OptedLoginWithCollectiveThresholdsPolicy();
        $decision = function (?AdmissionLaneEnum $lane) use ($policy): string {
            $lab = $this->lab($lane, policy: $policy);
            $lab->at(0);

            return $lab->run(self::context('198.51.100.9', 'acct-c', 'dev-c'), 'fail')['d'];
        };

        self::assertSame('S', $decision(null), 'The K1 score alone refuses without a lane.');
        self::assertSame('A', $decision(AdmissionLaneEnum::P), 'A proven request is not refused by a K1 threshold.');
        self::assertSame('A', $decision(AdmissionLaneEnum::U), 'The unproven rule removes the account-scoped K1 candidate.');
    }

    public function testG3cOldCollectiveBlocksAreHonouredOnUAndNotReadOnP(): void
    {
        // Contract M3 vs the study (PLAN.md LT-10b): a block written without a lane, read read-only on U, skipped on P.
        $none = $this->lab(null);
        self::assertStringContainsString('H', $this->sourceSweep($none, 6));

        $u = new FamilyLab(new OptedLoginPolicy(), AdmissionLaneEnum::U, shareStateWith: $none);
        $p = new FamilyLab(new OptedLoginPolicy(), AdmissionLaneEnum::P, shareStateWith: $none);
        $none->at(7);
        $probe = self::context('203.0.113.50', 'acct-new', 'dev-new');

        self::assertSame('H', $u->run($probe, 'check')['d']);
        self::assertSame('A', $p->run($probe, 'check')['d']);
    }

    public function testG4UnprovenLaneNeverPersistsAnAccountPenaltyAndNeverAddsABudgetCandidate(): void
    {
        $decisions = static function (?AdmissionLaneEnum $lane, FamilyLab $lab): string {
            $out = '';
            for ($i = 0; $i < 25; $i++) {
                $lab->at($i * 10);
                $out .= $lab->run(self::context('198.51.100.7', 'acct-d', 'dev-d'), 'fail')['d'];
            }

            return $out;
        };

        $none = $this->lab(null);
        $u = $this->lab(AdmissionLaneEnum::U);

        self::assertStringContainsString('H', $decisions(null, $none), 'Without a lane an account hard block is issued.');
        self::assertSame(str_repeat('A', 25), $decisions(AdmissionLaneEnum::U, $u), 'U refuses nothing at account scope, budget included.');

        $k4 = hash_hmac('sha256', 'login_protection:rate_limiter:k4:v2:prod:acct-d', self::SECRET);
        self::assertNull($u->store->checkBlock($k4));
        $score = $u->store->get($k4);
        self::assertNotNull($score, 'The account score is still written and readable.');
        self::assertGreaterThan(0, $score->value);
    }

    public function testG5K4PHasItsOwnAccountKeyAndRuleAHasNoScope(): void
    {
        $lab = $this->lab(AdmissionLaneEnum::P);
        $lab->at(0);
        $lab->run(self::context('198.51.100.7', 'acct-e', 'dev-e'), 'fail');

        $k4p = hash_hmac('sha256', 'login_protection:rate_limiter:k4p:v2:prod:acct-e', self::SECRET);
        $k4 = hash_hmac('sha256', 'login_protection:rate_limiter:k4:v2:prod:acct-e', self::SECRET);

        self::assertNotNull($lab->store->get($k4p));
        self::assertNull($lab->store->get($k4));
    }

    public function testAnUnoptedPolicyCannotBeGivenALane(): void
    {
        $lab = new FamilyLab(new LoginProtectionPolicy(), AdmissionLaneEnum::U);
        $lab->at(0);

        $this->expectException(RateLimiterException::class);
        $lab->run(self::context('198.51.100.7', 'acct-g', 'dev-g'), 'check');
    }

    public function testAnOptedPolicyWithoutALaneIsToday(): void
    {
        $lab = new FamilyLab(new OptedLoginPolicy(), null);
        $lab->at(0);

        self::assertSame('A', $lab->run(self::context('198.51.100.7', 'acct-g', 'dev-g'), 'check')['d']);
    }

    // ---- detectors: each mutation must break the rule it guards -------------------------------------------------

    public function testDetectorCollectiveRulesOnEveryLaneBreakG1AndG2(): void
    {
        $mutant = PipelineMutant::pipeline(methodBodies: ['appliesCollectiveCorrelation' => 'return true;']);

        [, , $p] = $this->sweepOnEveryLane($mutant);
        self::assertStringContainsString('H', $p, 'G1 must fail on P.');

        $lab = $this->lab(AdmissionLaneEnum::U, $mutant);
        for ($i = 0; $i < 4; $i++) {
            $lab->at($i);
            $lab->run(self::context('203.0.113.50', "acct-{$i}", "dev-{$i}"), 'check');
        }
        self::assertGreaterThan(8, count($lab->corr->distinctKeys), 'G2 must fail on U.');
    }

    public function testDetectorProvenLaneFlagBreaksG3(): void
    {
        $mutant = PipelineMutant::pipeline(methodBodies: ['isProvenLane' => 'return false;']);
        $key = hash_hmac('sha256', 'login_protection:rate_limiter:aux:flood_stage:v1:prod:acct-f', self::SECRET);

        $lab = $this->lab(AdmissionLaneEnum::P, $mutant);
        for ($i = 0; $i < 7; $i++) {
            $lab->at($i);
            $lab->run(self::context('198.51.100.5', 'acct-f', "dev-f{$i}", 120 + $i), 'check');
        }

        self::assertGreaterThan(0, count(array_keys($lab->corr->watchIncrements, $key, true)));
        self::assertNotSame([], $lab->corr->distinctKeys);
    }

    public function testDetectorCollectiveKeyTypesOnTheProvenLaneBreakG3bAndG3c(): void
    {
        $mutant = PipelineMutant::pipeline(methodBodies: ['ignoresCollectiveKeyType' => 'return false;']);

        $lab = $this->lab(AdmissionLaneEnum::P, $mutant, new OptedLoginWithCollectiveThresholdsPolicy());
        $lab->at(0);
        self::assertSame('S', $lab->run(self::context('198.51.100.9', 'acct-c', 'dev-c'), 'fail')['d']);

        $none = $this->lab(null);
        $this->sourceSweep($none, 6);
        $p = new FamilyLab(new OptedLoginPolicy(), AdmissionLaneEnum::P, $mutant, shareStateWith: $none);
        $none->at(7);
        self::assertSame('H', $p->run(self::context('203.0.113.50', 'acct-new', 'dev-new'), 'check')['d']);
    }

    public function testDetectorDeviceScopeRuleBreaksG4(): void
    {
        $mutant = PipelineMutant::pipeline(methodBodies: ['keepsDeviceScopeOnly' => 'return false;']);
        $lab = $this->lab(AdmissionLaneEnum::U, $mutant);
        $decisions = '';
        for ($i = 0; $i < 25; $i++) {
            $lab->at($i * 10);
            $decisions .= $lab->run(self::context('198.51.100.7', 'acct-d', 'dev-d'), 'fail')['d'];
        }

        self::assertStringContainsString('H', $decisions);
    }

    public function testDetectorBudgetRuleBreaksG4InIsolation(): void
    {
        $mutant = PipelineMutant::pipeline(methodBodies: ['suppressesBudget' => 'return false;']);
        $lab = $this->lab(AdmissionLaneEnum::U, $mutant);
        $decisions = '';
        for ($i = 0; $i < 25; $i++) {
            $lab->at($i * 10);
            $decisions .= $lab->run(self::context('198.51.100.7', 'acct-d', 'dev-d'), 'fail')['d'];
        }

        self::assertStringContainsString('S', $decisions, 'The 24 h budget adds a candidate once it is no longer suppressed.');
        self::assertStringNotContainsString('H', $decisions, 'Only the budget rule was removed, not the account rule.');
    }
}
