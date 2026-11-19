<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
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
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\NullCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\ThrowingRateLimitStore;
use Maatify\RateLimiter\Tests\Support\Study262\FallbackStudyRuntime as R;
use PHPUnit\Framework\TestCase;

/**
 * #262 study — a CHARACTERIZATION of the current behaviour, not a statement of the contract: two independent public runtimes
 * (RateLimiterBuilder, own stores/circuit store/emitter/clock) in ONE PHP process that use the same policy name and different
 * environmentScope values. Each test states its inputs and the observed result; the expected values below are what the code does
 * today (LocalFallbackLimiter's namespace is the policy name only), and they are recorded as evidence when STUDY_262_EVIDENCE names a file.
 * The static fallback state is reset before every scenario and kept between the calls of one scenario, because sharing is what is measured.
 */
final class FallbackEnvironmentScopeSharingStudy262Test extends TestCase
{
    private const POLICY = 'study_262_policy';
    private const OTHER_IP = '203.0.113.7';

    protected function setUp(): void
    {
        parent::setUp();
        R::resetFallbackState();
    }

    protected function tearDown(): void
    {
        R::resetFallbackState();
        parent::tearDown();
    }

    public function testBaselineSingleRuntimeAllowsTwoThenDeniesWithWindowRetryAfter(): void
    {
        $a = R::build('production', self::POLICY, 'typed');
        $calls = [$a->limit(), $a->limit(), $a->limit()];

        $this->assertSame(['ALLOW', 'ALLOW', 'HARD_BLOCK'], array_map(static fn(RateLimitResultDTO $r): string => $r->decision, $calls));
        $this->assertDenial($calls[2], 590);
        $this->assertSame([3, 3], R::fallbackState()['countValues']); // one counter per rule: the IP-prefix rule and the (unreachable-cap) UA rule
        $this->record('S01_baseline_single_runtime', ['envs' => ['production'], 'policy' => self::POLICY, 'limit' => 2, 'calls' => 3], ['ALLOW', 'ALLOW', 'HARD_BLOCK'], $this->observe($calls, R::fallbackState()));
    }

    public function testSameEnvironmentTwoRuntimesShareTheAllowance(): void
    {
        $a = R::build('production', self::POLICY, 'typed');
        $b = R::build('production', self::POLICY, 'typed');
        $calls = [$a->limit(), $a->limit(), $b->limit()];

        $this->assertSame('HARD_BLOCK', $calls[2]->decision);
        $this->assertDenial($calls[2], 590);
        $this->assertSame([3, 3], R::fallbackState()['countValues']); // one counter per rule: the IP-prefix rule and the (unreachable-cap) UA rule
        $this->record('S02_same_env_two_runtimes', ['envs' => ['production', 'production'], 'policy' => self::POLICY, 'sequence' => 'A,A,B'], 'B denied (shared allowance)', $this->observe($calls, R::fallbackState()));
    }

    public function testDifferentEnvironmentsInOneProcessShareTheSameAllowance(): void
    {
        $a = R::build('production', self::POLICY, 'typed');
        $b = R::build('staging', self::POLICY, 'typed');
        $calls = [$a->limit(), $a->limit(), $b->limit()];

        // The staging runtime never made a request of its own, yet its first fallback call is denied by the production runtime's count.
        $this->assertSame(['ALLOW', 'ALLOW', 'HARD_BLOCK'], array_map(static fn(RateLimitResultDTO $r): string => $r->decision, $calls));
        $this->assertDenial($calls[2], 590);
        $state = R::fallbackState();
        $this->assertSame(2, $state['counters']); // one per rule; a separate namespace per environment would show 4
        $this->assertSame([3, 3], $state['countValues']);
        $this->assertSame(2, $state['populations']);
        $this->record('S03_different_env_one_process', ['envs' => ['production', 'staging'], 'policy' => self::POLICY, 'sequence' => 'A(production),A,B(staging)'], 'current behaviour: one shared counter', $this->observe($calls, $state));
    }

    public function testInterleavedDifferentEnvironmentsConsumeOneCounterInArrivalOrder(): void
    {
        $a = R::build('production', self::POLICY, 'typed');
        $b = R::build('staging', self::POLICY, 'typed');
        $calls = [$a->limit(), $b->limit(), $a->limit(), $b->limit()];

        $this->assertSame(['ALLOW', 'ALLOW', 'HARD_BLOCK', 'HARD_BLOCK'], array_map(static fn(RateLimitResultDTO $r): string => $r->decision, $calls));
        $this->assertSame([4, 4], R::fallbackState()['countValues']);
        $this->record('S04_interleaved_envs', ['envs' => ['production', 'staging'], 'sequence' => 'A,B,A,B'], 'one counter, arrival order', $this->observe($calls, R::fallbackState()));
    }

    public function testCircuitStateIsPerRuntimeWhileTheAllowanceIsShared(): void
    {
        $a = R::build('production', self::POLICY, 'typed');
        $b = R::build('staging', self::POLICY, 'typed');
        for ($i = 0; $i < 3; $i++) {
            $a->limit();
        }
        $stateAfterA = ['A' => $a->circuitState(), 'B' => $b->circuitState()];
        $bResult = $b->limit();

        // Quota: B is denied by A's consumption. Circuit: A's open state is not B's (B's own store saw one failure only).
        $this->assertSame('HARD_BLOCK', $bResult->decision);
        $this->assertNotNull($stateAfterA['A']);
        $this->assertNull($stateAfterA['B']);
        $this->record('S05_circuit_vs_quota', ['envs' => ['production', 'staging'], 'sequence' => 'A,A,A,B'], 'quota shared, circuit per runtime', ['bDecision' => $bResult->decision, 'circuitBeforeB' => $stateAfterA, 'circuitAfterB' => ['A' => $a->circuitState(), 'B' => $b->circuitState()], 'fallback' => R::fallbackState()]);
    }

    public function testControlDifferentPolicyNameIsIsolatedAcrossEnvironments(): void
    {
        $a = R::build('production', self::POLICY, 'typed');
        $b = R::build('staging', self::POLICY . '_other', 'typed');
        $calls = [$a->limit(), $a->limit(), $b->limit()];

        $this->assertSame('ALLOW', $calls[2]->decision);
        $this->assertSame(4, R::fallbackState()['counters']);
        $this->record('S06_control_different_policy', ['envs' => ['production', 'staging'], 'policies' => [self::POLICY, self::POLICY . '_other']], 'B allowed, separate counters (4 in total)', $this->observe($calls, R::fallbackState()));
    }

    public function testControlDifferentSubjectIsIsolatedAcrossEnvironments(): void
    {
        $a = R::build('production', self::POLICY, 'typed');
        $b = R::build('staging', self::POLICY, 'typed');
        $calls = [$a->limit(), $a->limit(), $b->limit(self::OTHER_IP)];

        $this->assertSame('ALLOW', $calls[2]->decision);
        $this->assertSame(4, R::fallbackState()['counters']);
        $this->record('S07_control_different_subject', ['envs' => ['production', 'staging'], 'ips' => [R::IP, self::OTHER_IP]], 'B allowed, separate counters (4 in total)', $this->observe($calls, R::fallbackState()));
    }

    public function testControlHealthyBackendNeverTouchesTheFallbackAllowance(): void
    {
        $a = R::build('production', self::POLICY, 'healthy');
        $calls = [];
        for ($i = 0; $i < 5; $i++) {
            $calls[] = $a->limit();
        }

        foreach ($calls as $call) {
            $this->assertSame('NORMAL', $call->failureMode);
            $this->assertSame('ALLOW', $call->decision);
        }
        $this->assertSame(0, R::fallbackState()['counters']);
        $this->record('S08_control_healthy_backend', ['envs' => ['production'], 'backend' => 'healthy', 'calls' => 5], 'no fallback state', $this->observe($calls, R::fallbackState()));
    }

    public function testControlNonOperationalErrorPropagatesAndConsumesNothing(): void
    {
        $bad = R::build('production', self::POLICY, 'untyped');
        $thrown = [];
        for ($i = 0; $i < 3; $i++) {
            try {
                $bad->limit();
                $thrown[] = null;
            } catch (\Throwable $e) {
                $thrown[] = $e::class;
            }
        }
        $afterErrors = R::fallbackState();
        $this->assertSame(0, $afterErrors['counters']);
        $this->assertNotContains(null, $thrown);

        // Full allowance remains for the same policy under a typed failure: two allowed, then the third denied.
        $b = R::build('staging', self::POLICY, 'typed');
        $calls = [$b->limit(), $b->limit(), $b->limit()];
        $this->assertSame(['ALLOW', 'ALLOW', 'HARD_BLOCK'], array_map(static fn(RateLimitResultDTO $r): string => $r->decision, $calls));
        $this->record('S09_control_non_operational_error', ['envs' => ['production(untyped RuntimeException)', 'staging(typed)']], 'exceptions propagate, no quota consumed', ['thrown' => $thrown, 'afterErrors' => $afterErrors] + $this->observe($calls, R::fallbackState()));
    }

    public function testExpiryIsByWindowNotByEnvironment(): void
    {
        $a = R::build('production', self::POLICY, 'typed');
        $b = R::build('staging', self::POLICY, 'typed');
        $a->limit();
        $a->limit();
        $this->assertSame('HARD_BLOCK', $b->limit()->decision);

        // Past the 600s window boundary (12:10:00) the other environment's call collects the expired counter and starts a new one.
        $next = new \DateTimeImmutable('2025-01-01 12:10:00');
        $a->clock->setNow($next);
        $b->clock->setNow($next);
        $after = [$b->limit(), $b->limit(), $b->limit()];
        $this->assertSame(['ALLOW', 'ALLOW', 'HARD_BLOCK'], array_map(static fn(RateLimitResultDTO $r): string => $r->decision, $after));
        $this->assertSame([3, 3], R::fallbackState()['countValues']); // one counter per rule: the IP-prefix rule and the (unreachable-cap) UA rule
        $this->record('S10_expiry_by_window', ['envs' => ['production', 'staging'], 'rollover' => '12:10:00'], 'new window, counter restarted by any env', $this->observe($after, R::fallbackState()));
    }

    public function testTrackedSubjectCapAndOverflowBucketAreSharedAcrossEnvironments(): void
    {
        $a = R::build('production', self::POLICY, 'typed', 1);
        $b = R::build('staging', self::POLICY, 'typed', 1);
        $c = R::build('staging', self::POLICY . '_other', 'typed', 1);
        for ($i = 0; $i < 4096; $i++) {
            $this->assertSame('ALLOW', $a->limit('10.' . intdiv($i, 65536) . '.' . intdiv($i % 65536, 256) . '.' . ($i % 256))->decision);
        }
        $filled = R::fallbackState();
        $this->assertSame(2 * 4096, $filled['trackedSubjects']); // 4096 per rule population (IP-prefix and UA rules)

        // The 4097th distinct subject takes the overflow bucket; a staging subject never seen before lands in the SAME overflow bucket.
        $overflowFirst = $a->limit('203.0.113.200');
        $stagingNew = $b->limit('203.0.113.201');
        $otherPolicy = $c->limit('203.0.113.202');

        $this->assertSame('ALLOW', $overflowFirst->decision);
        $this->assertSame('HARD_BLOCK', $stagingNew->decision);
        $this->assertSame('ALLOW', $otherPolicy->decision);
        $this->record('S11_cap_and_overflow', ['limit' => 1, 'fill' => '4096 distinct IPs via production', 'then' => ['production new ip', 'staging new ip', 'staging other policy new ip']], 'staging shares the overflow bucket; other policy does not', ['trackedAfterFill' => $filled['trackedSubjects'], 'overflowFirst(production)' => $overflowFirst->decision, 'stagingNewSubject' => $stagingNew->decision, 'stagingNewRetryAfter' => $stagingNew->retryAfter, 'otherPolicyNewSubject' => $otherPolicy->decision, 'fallback' => R::fallbackState()]);
    }

    public function testAdvancedPathEnginesShareTheSameWay(): void
    {
        $production = $this->engine('production');
        $staging = $this->engine('staging');
        $context = new RateLimitContextDTO(R::IP, R::UA, null);
        $command = RateLimitCommand::checkOnly(self::POLICY);
        $calls = [$production->limit($context, $command), $production->limit($context, $command), $staging->limit($context, $command)];

        $this->assertSame(['ALLOW', 'ALLOW', 'HARD_BLOCK'], array_map(static fn(RateLimitResultDTO $r): string => $r->decision, $calls));
        $this->record('S12_advanced_path_engines', ['envs' => ['production', 'staging'], 'construction' => 'EvaluationPipeline + RateLimiterEngine'], 'same sharing as the Builder', $this->observe($calls, R::fallbackState()));
    }

    public function testSeparatePhpProcessDoesNotShareTheAllowance(): void
    {
        $a = R::build('production', self::POLICY, 'typed');
        $parent = [$a->limit(), $a->limit(), $a->limit()];
        $this->assertSame('HARD_BLOCK', $parent[2]->decision);
        $parentState = R::fallbackState();

        $script = \dirname(__DIR__, 2) . '/investigations/fallback-env-scope-262/child.php';
        $process = proc_open([PHP_BINARY, $script, 'staging', self::POLICY, '3'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $code = proc_close($process);
        $this->assertSame(0, $code, (string) $err);
        $child = json_decode((string) $out, true, 8, JSON_THROW_ON_ERROR);
        $this->assertIsArray($child);

        $this->assertSame(['ALLOW', 'ALLOW', 'HARD_BLOCK'], $child['decisions']);
        $this->assertNotSame(getmypid(), $child['pid']);
        $this->assertSame($parentState, R::fallbackState());
        $this->record('S13_separate_process', ['parent' => 'production x3', 'child' => 'staging x3 in a new PHP process'], 'child has its own full allowance', ['parentDecisions' => array_map(static fn(RateLimitResultDTO $r): string => $r->decision, $parent), 'parentState' => $parentState, 'child' => $child]);
    }

    private function engine(string $environmentScope): RateLimiterEngine
    {
        $clock = new FixedClock(R::NOW);
        $store = new ThrowingRateLimitStore();
        $correlation = new NullCorrelationStore();
        $emitter = new RecordingFailureSignalEmitter();
        $pipeline = new EvaluationPipeline($store, $correlation, new BudgetTracker($store, $clock), new AntiEquilibriumGate($correlation), new DecayCalculator($clock), new EphemeralBucket($correlation), 'test_secret', $environmentScope, $clock);

        return new RateLimiterEngine(
            new DeviceIdentityResolver(new FingerprintHasher('test_secret')),
            $pipeline,
            new CircuitBreaker(new InMemoryCircuitBreakerStore(), $emitter, $clock),
            new FailureModeResolver(),
            $emitter,
            $clock,
            [R::policy(self::POLICY, 2)],
        );
    }

    private function assertDenial(RateLimitResultDTO $result, int $retryAfter): void
    {
        $this->assertSame('HARD_BLOCK', $result->decision);
        $this->assertSame(2, $result->blockLevel);
        $this->assertSame($retryAfter, $result->retryAfter);
        $this->assertSame('fallback_limit_exceeded', $result->metadata?->cause);
    }

    /**
     * @param list<RateLimitResultDTO> $calls
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function observe(array $calls, array $state): array
    {
        return [
            'calls' => array_map(static fn(RateLimitResultDTO $r): array => ['decision' => $r->decision, 'blockLevel' => $r->blockLevel, 'retryAfter' => $r->retryAfter, 'failureMode' => $r->failureMode, 'cause' => $r->metadata?->cause], $calls),
            'fallback' => $state,
        ];
    }

    /**
     * @param array<string, mixed> $inputs
     * @param array<string, mixed>|string|list<string> $expected
     * @param array<string, mixed> $observed
     */
    private function record(string $scenario, array $inputs, array|string $expected, array $observed): void
    {
        $file = getenv('STUDY_262_EVIDENCE');
        if (!\is_string($file) || $file === '') {
            return;
        }
        $line = json_encode(['scenario' => $scenario, 'phpIntSize' => PHP_INT_SIZE, 'phpVersion' => PHP_VERSION, 'inputs' => $inputs, 'expectedCurrentBehaviour' => $expected, 'observed' => $observed], JSON_THROW_ON_ERROR);
        $this->assertNotFalse(file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX));
    }
}
