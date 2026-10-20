<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\DTO\AdmissionCredentialDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Service\AdmissionCredentialCodec;
use Maatify\RateLimiter\Service\AdmissionRuntime;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FailureModeResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionInMemoryReferenceStore;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;
use Maatify\RateLimiter\Tests\Support\Admission\PipelineMutant;
use Maatify\RateLimiter\Tests\Support\Admission\RecordingCorrelationStore;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Gate S0-PARITY through the ENGINE (5b3b/PLAN.md §5.2 row S0-PARITY): the 5-b3a scenario of `FamilyS0ParityTest` played through
 * `RateLimiterEngine::limit()` with an admission runtime attached and its fence open, a Host sending `clientInstanceId` and a
 * minted `admissionCredential` to the non-opted Login and OTP policies, reproduces the 5-b3a goldens (decisions and state hash;
 * Login `19bfcf29…679c`, OTP `568dbec2…cacab`), read from `FamilyS0ParityTest::GOLDEN` by reflection and never re-typed. The same
 * holds with no runtime and with the fence closed. Detector: the 5-b3a seam `k5Material` ignoring the lane.
 */
#[Group('s0')]
final class AdmissionEngineS0GoldensTest extends TestCase
{
    /** @return array{decisions: string, state: string} */
    private static function golden(string $policy): array
    {
        /** @var array<string, array{decisions: string, state: string}> $golden */
        $golden = (new \ReflectionClassConstant(FamilyS0ParityTest::class, 'GOLDEN'))->getValue();

        return $golden[$policy];
    }

    /** @return iterable<string, array{BlockPolicyInterface, 'open'|'closed'|'none'}> */
    public static function cases(): iterable
    {
        foreach (['login' => new LoginProtectionPolicy(), 'otp' => new OtpProtectionPolicy()] as $name => $policy) {
            yield "$name, runtime, fence open, credential" => [$policy, 'open'];
            yield "$name, runtime, fence closed, credential" => [$policy, 'closed'];
            yield "$name, no runtime, credential" => [$policy, 'none'];
        }
    }

    /**
     * The scenario of `FamilyS0ParityTest::play()` (same contexts, commands and clock), through the engine.
     *
     * @param class-string<EvaluationPipeline> $pipelineClass
     * @param 'open'|'closed'|'none' $runtime
     * @return array{decisions: string, state: string}
     */
    private static function play(BlockPolicyInterface $policy, string $runtime, string $pipelineClass = EvaluationPipeline::class): array
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
        $codec = new AdmissionCredentialCodec(EngineLab::BINDING_SECRET);
        $admission = null;
        if ($runtime !== 'none') {
            $admissionStore = new AdmissionInMemoryReferenceStore($clock);
            $admission = new AdmissionRuntime($admissionStore, $codec, EngineLab::budgets(), $clock, 'lab-secret', 'prod');
            if ($runtime === 'open') {
                // The fence of the Login budget group is open; the policies under test name no budget group at all.
                $admissionStore->bumpBindingEpoch(AdmissionRuntime::fenceKey('prod', 'login', 'lab-secret'), null);
            }
        }
        $emitter = new RecordingFailureSignalEmitter();
        $engine = new RateLimiterEngine(
            new DeviceIdentityResolver(new FingerprintHasher('lab-secret')),
            $pipeline,
            new CircuitBreaker(new InMemoryCircuitBreakerStore(), $emitter, $clock),
            new FailureModeResolver(),
            $emitter,
            $clock,
            [$policy],
            $admission,
        );
        $name = $policy->getName();
        $decisions = '';
        for ($step = 0; $step < 160; $step++) {
            $n = $step % 4;
            $known = $step % 3 !== 0;
            $instance = $known ? 'inst-' . $n : ($step % 5 === 0 ? null : 'new-' . $step);
            $credential = $instance === null ? null : $codec->encode(new AdmissionCredentialDTO(
                'acct-' . $n,
                ['login'],
                $instance,
                'jti-' . $n,
                $clock->now()->getTimestamp() + 86400,
                $clock->now()->getTimestamp(),
                0,
            ));
            $context = new RateLimitContextDTO(
                '198.51.100.' . (10 + $n),
                $step % 7 === 0
                    ? 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15'
                    : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                'acct-' . $n,
                null,
                $instance,
                false,
                [],
                $known,
                null,
                clientInstanceId: $instance,
                admissionCredential: $credential,
            );
            $command = match ($step % 5) {
                0, 3 => RateLimitCommand::checkOnly($name),
                1, 2 => RateLimitCommand::recordFailure($name),
                default => RateLimitCommand::recordSuccess($name),
            };
            $result = $engine->limit($context, $command);
            $decisions .= $result->decision === RateLimitResultDTO::DECISION_ALLOW
                ? 'A'
                : ($result->decision === RateLimitResultDTO::DECISION_HARD_BLOCK ? 'H' : 'S');
            $clock->setNow($clock->now()->modify('+45 seconds'));
        }
        $state = preg_replace('/s:32:"[0-9a-f]{32}";/', 's:32:"ID";', serialize([$store, $corr]));

        return ['decisions' => $decisions, 'state' => hash('sha256', (string) $state)];
    }

    /** @param 'open'|'closed'|'none' $runtime */
    #[DataProvider('cases')]
    public function testTheEngineReproducesThe5b3aGoldens(BlockPolicyInterface $policy, string $runtime): void
    {
        self::assertSame(self::golden($policy->getName()), self::play($policy, $runtime));
    }

    public function testDetectorAK5MaterialSeamThatIgnoresTheLaneBreaksTheGoldenThroughTheEngine(): void
    {
        $mutant = PipelineMutant::pipeline(methodBodies: ['k5Material' => 'return $device->deviceIdHash ?? $device->fingerprintHash;']);

        self::assertNotSame(self::golden('login_protection')['state'], self::play(new LoginProtectionPolicy(), 'open', $mutant)['state']);
    }
}
