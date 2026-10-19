<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Tests\Support\Admission\DeclaresCapabilityWithoutInterfacePolicy;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\FullCapability\FullCapabilityInMemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * Gate S0-PARITY (RUNTIME_IMPLEMENTATION_CONTRACT.md §8.3; DEC-024 C-c1, DEC-008 BC 1-4).
 *
 * The existing suite and the S0 baseline fixtures are the first half of the gate and are not modified.
 * This test is the second half: a Host that starts sending the new context inputs to a policy that
 * did not opt in changes no decision and no stored state, for the three official presets and for a
 * custom policy without the capability.
 */
final class AdmissionS0ParityTest extends TestCase
{
    private const POLICIES = ['login_protection', 'otp_protection', 'api_heavy_protection', 'plain-auth'];

    public function testNewContextInputsChangeNoDecisionAndNoStateForPoliciesThatDidNotOptIn(): void
    {
        foreach (self::POLICIES as $policy) {
            $baseline = $this->run($policy, withNewInputs: false, account: 'acct-1');
            $withInputs = $this->run($policy, withNewInputs: true, account: 'acct-1');

            self::assertSame($baseline['decisions'], $withInputs['decisions'], "decisions differ for {$policy}");
            self::assertSame($baseline['state'], $withInputs['state'], "stored state differs for {$policy}");
        }
    }

    public function testTheComparisonCanTellTwoDifferentRunsApart(): void
    {
        // Detector: the harness is sensitive. A run that really differs (another account hits
        // another key and a different history) is told apart from the baseline.
        $baseline = $this->run('login_protection', withNewInputs: false, account: 'acct-1');
        $different = $this->run('login_protection', withNewInputs: false, account: 'acct-2');

        self::assertNotSame($baseline['state'], $different['state']);
        self::assertNotEmpty($baseline['state']);
        self::assertNotEmpty($baseline['decisions']);
    }

    public function testRunsExerciseEveryCommandKindAndReachAnEnforcingDecision(): void
    {
        $decisions = $this->run('login_protection', withNewInputs: true, account: 'acct-1')['decisions'];
        $kinds = array_unique(array_map(static fn(array $d): string => $d['decision'], $decisions));

        self::assertGreaterThan(1, count($kinds), 'The scripted sequence must reach a non-ALLOW decision.');
    }

    /**
     * @return array{decisions: list<array{decision: string, level: int, retryAfter: int, mode: string, cause: ?string}>, state: array<string, mixed>}
     */
    private function run(string $policy, bool $withNewInputs, string $account): array
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store = new FullCapabilityInMemoryStore($clock);
        $runtime = $this->runtime($clock, $store);
        $decisions = [];

        for ($step = 0; $step < 14; $step++) {
            $context = new RateLimitContextDTO(
                '198.51.100.' . (10 + ($step % 3)),
                'Mozilla/5.0 Chrome/120.0 Safari/537.36',
                $account,
                ['device' => 'd' . ($step % 2)],
                'session-' . ($step % 2),
                $step % 5 === 0,
                [],
                $step % 4 === 0,
                null,
                clientInstanceId: $withNewInputs ? 'instance-' . $step : null,
                admissionCredential: $withNewInputs ? 'credential-' . $step : null,
            );
            $command = match ($step % 3) {
                0 => RateLimitCommand::checkOnly($policy),
                1 => RateLimitCommand::recordFailure($policy),
                default => $step % 2 === 0 ? RateLimitCommand::recordSuccess($policy) : RateLimitCommand::recordFailure($policy),
            };
            $result = $runtime->limit($context, $command);
            $decisions[] = [
                'decision' => $result->decision,
                'level' => $result->blockLevel,
                'retryAfter' => $result->retryAfter,
                'mode' => $result->failureMode,
                'cause' => $result->metadata?->cause,
            ];
            $clock->setNow($clock->now()->modify('+7 seconds'));
        }

        return ['decisions' => $decisions, 'state' => ['store' => serialize($store)]];
    }

    private function runtime(FixedClock $clock, FullCapabilityInMemoryStore $store): CompositeRateLimiterRuntimeInterface
    {
        return RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('key-secret', 'fingerprint-secret', 'prod'),
            $store,
            new RecordingFailureSignalEmitter(),
        )
            ->withClock($clock)
            ->withPolicy(new DeclaresCapabilityWithoutInterfacePolicy('plain-auth'))
            ->build();
    }
}
