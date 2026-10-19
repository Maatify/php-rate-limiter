<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Tests\Fixtures\S0Baseline\S0LoginProtectionPolicy;
use Maatify\RateLimiter\Tests\Support\Admission\PipelineMutant;
use Maatify\RateLimiter\Tests\Support\Admission\RecordingCorrelationStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Gate S0-PARITY, pipeline half (5b3a/PLAN.md section 5; contract section 8.3).
 *
 * "Today" is `main@1c23b61`. The goldens below were recorded by running this exact scenario on the unmodified
 * pipeline of that commit, before any line of 5-b3a existed: the decision string and the sha-256 of the normalized
 * state of both stores (lifecycle ids are random and masked). With the lane omitted or passed as null the pipeline
 * must reproduce them, and it must ignore an instance id carried by the device.
 *
 * The older `tests/Fixtures/S0Baseline` fixture is the DEC-021 mixed-version reference (main@4d37be5); it is not
 * today's behaviour and is exercised, unchanged, by its own tests.
 */
#[Group('s0')]
final class FamilyS0ParityTest extends TestCase
{
    private const GOLDEN = [
        'login_protection' => [
            'decisions' => 'AAAAAAAAAAHAAHAHHAAAAAHAHAHHAAHHHAAASHAAHHAHHHAHHAAHAAASAAHSAHHSHHSAAASAHHHAHAHHHHSHSSHSSSHHSHAHHSAHASHSASHHHAHHHAHAHHSAAHHAHHHHHHHHHAAHHHAAHHHAAAHHHAAHHAAAHHHA',
            'state' => '19bfcf2976edd0781c38567340170df9faa7641f1cc94d6bd110e3ec4753679c',
        ],
        'otp_protection' => [
            'decisions' => 'AAAAAASAAAHASHSHHASASSHAHSHHSSHHHSSHHHSAHHHHHHHHHAAHHSAAHHHAAHHAHHHHAAAHHHAAHHHAHHHHAAHHAAAHHHSAHHAHAHHHAAHHAAAHHAHAHHHAAHHAHAHHHHAHHAAAHHAHAHHHAAHHAAAHHHAAHHHH',
            'state' => '568dbec2cdf326769fd1dabae76f0fa130f7a7d7aac2745bc5c0b35bc32cacab',
        ],
    ];

    /** @return iterable<string, array{0: BlockPolicyInterface}> */
    public static function policies(): iterable
    {
        yield 'login' => [new LoginProtectionPolicy()];
        yield 'otp' => [new OtpProtectionPolicy()];
    }

    #[DataProvider('policies')]
    public function testLaneOmittedAndLaneNullReproduceTheRecordedBaseline(BlockPolicyInterface $policy): void
    {
        $golden = self::GOLDEN[$policy->getName()];

        $omitted = self::play($policy, EvaluationPipeline::class, 'both');
        $explicitNull = self::play($policy, EvaluationPipeline::class, 'both', passNull: true);

        self::assertSame($golden['decisions'], $omitted['decisions']);
        self::assertSame($golden['state'], $omitted['state']);
        self::assertSame($golden['decisions'], $explicitNull['decisions']);
        self::assertSame($golden['state'], $explicitNull['state']);
        self::assertGreaterThan(2, count(array_unique(str_split($golden['decisions']))), 'The scenario must mix allow, soft and hard decisions.');
    }

    /** @return iterable<string, array{0: BlockPolicyInterface}> */
    public static function allPolicies(): iterable
    {
        yield from self::policies();
        yield 'login-k4-only' => [new S0LoginProtectionPolicy()];
    }

    #[DataProvider('allPolicies')]
    public function testAnInstanceIdOnTheDeviceChangesNothingWithoutALane(BlockPolicyInterface $policy): void
    {
        $withId = self::play($policy, EvaluationPipeline::class, 'clientOnly');
        $withoutId = self::play($policy, EvaluationPipeline::class, 'none');

        self::assertSame($withoutId['decisions'], $withId['decisions']);
        self::assertSame($withoutId['state'], $withId['state']);
    }

    public function testTheComparisonIsSensitiveToTheStateItGuards(): void
    {
        // Harness validity: another scenario variant gives another state, so equality above is not vacuous.
        $one = self::play(new LoginProtectionPolicy(), EvaluationPipeline::class, 'both');
        $other = self::play(new LoginProtectionPolicy(), EvaluationPipeline::class, 'none');

        self::assertNotSame($one['state'], $other['state']);
    }

    public function testApplyingTheIdMaterialToNonOptedPoliciesBreaksParity(): void
    {
        // Detector (contract 8.3, S0-PARITY): a build whose K5 material seam ignores the lane applies the id to a
        // policy that did not opt in. The id-ignored check and the recorded baseline must both fail on it.
        $mutant = PipelineMutant::pipeline(methodBodies: [
            'k5Material' => 'return $device->deviceIdHash ?? $device->fingerprintHash;',
        ]);
        $policy = new LoginProtectionPolicy();

        $withId = self::play($policy, $mutant, 'clientOnly');
        $withoutId = self::play($policy, $mutant, 'none');
        $broken = self::play($policy, $mutant, 'both');

        self::assertNotSame($withoutId['state'], $withId['state']);
        self::assertNotSame(self::GOLDEN['login_protection']['state'], $broken['state']);
    }

    /**
     * @param class-string<EvaluationPipeline> $pipelineClass
     * @param 'both'|'clientOnly'|'none' $idMode `both` also carries the id as the session device id, as the study did
     * @return array{decisions: string, state: string}
     */
    public static function play(BlockPolicyInterface $policy, string $pipelineClass, string $idMode, bool $passNull = false): array
    {
        $clock = new FixedClock('2026-01-01 12:00:00');
        $store = new InMemoryRateLimitStore($clock);
        $corr = new RecordingCorrelationStore($clock);
        $pipeline = new $pipelineClass(
            $store,
            $corr,
            new BudgetTracker($store, $clock),
            new AntiEquilibriumGate($corr),
            new DecayCalculator($clock),
            new EphemeralBucket($corr),
            'lab-secret',
            'prod',
            $clock,
        );
        $resolver = new DeviceIdentityResolver(new FingerprintHasher('lab-secret'));
        $name = $policy->getName();
        $decisions = '';

        for ($step = 0; $step < 160; $step++) {
            $n = $step % 4;
            $known = $step % 3 !== 0;
            $instance = $idMode === 'none' ? null : ($known ? 'inst-' . $n : ($step % 5 === 0 ? null : 'new-' . $step));
            $context = new RateLimitContextDTO(
                '198.51.100.' . (10 + $n),
                $step % 7 === 0
                    ? 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15'
                    : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                'acct-' . $n,
                null,
                $idMode === 'both' ? $instance : null,
                false,
                [],
                $known,
                null,
                clientInstanceId: $instance,
            );
            $command = match ($step % 5) {
                0, 3 => RateLimitCommand::checkOnly($name),
                1, 2 => RateLimitCommand::recordFailure($name),
                default => RateLimitCommand::recordSuccess($name),
            };
            $device = $resolver->resolve($context);
            $result = $passNull
                ? $pipeline->process($policy, $context, $command, $device, null)
                : $pipeline->process($policy, $context, $command, $device);
            $decisions .= $result->decision === RateLimitResultDTO::DECISION_ALLOW
                ? 'A'
                : ($result->decision === RateLimitResultDTO::DECISION_HARD_BLOCK ? 'H' : 'S');
            $clock->setNow($clock->now()->modify('+45 seconds'));
        }

        // Lifecycle ids are random 128-bit values; they are the only non-deterministic bytes of the state.
        $state = preg_replace('/s:32:"[0-9a-f]{32}";/', 's:32:"ID";', serialize([$store, $corr]));

        return ['decisions' => $decisions, 'state' => hash('sha256', (string) $state)];
    }
}
