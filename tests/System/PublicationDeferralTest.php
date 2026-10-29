<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
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
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\ScriptedPublicationRateLimitStore;
use PHPUnit\Framework\TestCase;

/**
 * DEC-029 (#160 step S): no RateLimitConcurrencyException after the request's
 * K4 mutation and K5 increment are committed. The K4 punishment publication
 * makes at most four calls (three plus one final attempt on the re-read
 * generation); a publication that is still not applied is deferred to the next
 * successful publisher and the decision of the written state is returned with
 * the cause `k4_publication_deferred`.
 *
 * Every scenario is the third `login_protection` failure of one account, run
 * on a scripted store and on an unscripted control store; the state written by
 * the request is compared with the control.
 */
final class PublicationDeferralTest extends TestCase
{
    private const ACCOUNT = 'deferral-account';
    private const DEVICE = 'deferral-device';
    private const MIXED_ACCOUNT = 'deferral-mixed-account';
    /** Between two failures of the mixed scenario every block it wrote (at most L3, 300 s) has expired. */
    private const MIXED_STEP_SECONDS = 301;

    public function testExhaustedPublicationIsDeferredWithoutExceptionAndKeepsTheBlockDecision(): void
    {
        $control = $this->controlRun();
        [$pipeline, $store, $clock] = $this->pipelineScenario();
        $store->arm(PHP_INT_MAX);

        $result = $this->failure($pipeline);

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame($control['result']->blockLevel, $result->blockLevel);
        self::assertSame($control['result']->retryAfter, $result->retryAfter);
        self::assertSame(2, $result->blockLevel);
        self::assertSame(60, $result->retryAfter);
        self::assertSame('k4_publication_deferred', $result->metadata?->cause);
        self::assertSame('k4_publication_deferred', $result->metadata->context?->reason);
        self::assertSame('k4', $result->metadata->context->scope);
        self::assertSame(4, $store->publicationCalls);
        self::assertSame(3, $store->snapshotReadsAfterLoss);
        self::assertSame($control['scores'], $this->scores($store));
        self::assertSame([], array_diff_key($this->blocks($store), $control['blocks']), 'no block beyond the control');
        self::assertNotSame($control['blocks'], $this->blocks($store), 'the K4 block itself is deferred');
        unset($clock);
    }

    public function testDeferredRequestThroughTheEngineDoesNotReachTheConcurrencyCatch(): void
    {
        $clock = new FixedClock();
        $store = new ScriptedPublicationRateLimitStore($clock);
        $circuit = new InMemoryCircuitBreakerStore();
        $engine = $this->engine($clock, $store, $circuit);
        $context = $this->context();
        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));
        $store->arm(PHP_INT_MAX);

        $result = $engine->limit($context, RateLimitCommand::recordFailure('login_protection'));

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(2, $result->blockLevel);
        self::assertSame(60, $result->retryAfter);
        self::assertSame('NORMAL', $result->failureMode);
        self::assertSame('k4_publication_deferred', $result->metadata?->cause);
        self::assertNotSame('k4_concurrency_conflict', $result->metadata->cause);
        self::assertSame(0, $circuit->saveCount());
    }

    public function testDeferredK4StillPublishesTheRemainingBlocks(): void
    {
        // The third login failure carries a single block, so the ordering of
        // K4 before another key is built directly: one winning candidate whose
        // persistence names the lifecycle-eligible K4 key first, then K5.
        [$pipeline, $store] = $this->pipelineScenario();
        $store->arm(PHP_INT_MAX);
        $this->failure($pipeline);
        $k4Key = $this->controlRun()['k4Key'];
        $k5Key = hash_hmac('sha256', 'login_protection:rate_limiter:k5:v2:prod:' . self::ACCOUNT . ':' . self::DEVICE, 'test_secret');
        $generation = $store->readGenerationBoundScoreState($k4Key, null)?->generation;
        self::assertNotNull($generation);
        (new \ReflectionProperty($pipeline, 'lifecycleEligibleKeys'))->setValue($pipeline, [$k4Key => $generation]);
        $store->arm(PHP_INT_MAX);
        $candidate = [
            'decision' => RateLimitResultDTO::DECISION_HARD_BLOCK,
            'level' => 2,
            'retryAfter' => 60,
            'source' => 'test',
            'persistence' => [
                ['key' => $k4Key, 'previousKey' => null, 'level' => 2, 'duration' => 60],
                ['key' => $k5Key, 'previousKey' => null, 'level' => 2, 'duration' => 60],
            ],
            'scopes' => ['account'],
        ];

        (new \ReflectionMethod($pipeline, 'persistWinningCandidates'))->invoke(
            $pipeline,
            new LoginProtectionPolicy(),
            [$candidate],
            RateLimitResultDTO::DECISION_HARD_BLOCK,
        );

        $blocks = $this->blocks($store);
        self::assertSame(1, $blocks[$k4Key] ?? null, 'the L2 K4 block is deferred; only the earlier L1 block remains');
        self::assertSame(2, $blocks[$k5Key] ?? null, 'the K5 block is published after the deferred K4');
    }

    public function testFinalAttemptAppliesAfterThreeLostPublications(): void
    {
        $control = $this->controlRun();
        [$pipeline, $store] = $this->pipelineScenario();
        $store->arm(3);

        $result = $this->failure($pipeline);

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(2, $result->blockLevel);
        self::assertSame(60, $result->retryAfter);
        self::assertNull($result->metadata?->cause);
        self::assertSame(4, $store->publicationCalls);
        self::assertSame($control['blocks'], $this->blocks($store));
        self::assertSame($control['scores'], $this->scores($store));
    }

    public function testLevelFallingBelowTwoAfterTheReadReturnsTheDecisionOfThatStateWithoutException(): void
    {
        [$pipeline, $store] = $this->pipelineScenario();
        $store->arm(PHP_INT_MAX, ScriptedPublicationRateLimitStore::AFTER_LOSS_LEVEL_DROP);

        $result = $this->failure($pipeline);

        // The only candidate of this request was the K4 one (K5 is not a
        // candidate for an unverified device), so the state decides ALLOW.
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertSame(0, $result->blockLevel);
        self::assertSame(0, $result->retryAfter);
        self::assertNull($result->metadata?->cause, 'a dropped K4 is not a deferred publication');
        self::assertSame(1, $store->publicationCalls);
        self::assertLessThan(2, $this->blocksOfKey($store, $this->controlRun()['k4Key'])[$this->controlRun()['k4Key']] ?? 0, 'no L2 K4 block');
    }

    public function testNoReadableSnapshotIsDeferredLikeAnExhaustedBudget(): void
    {
        $control = $this->controlRun();
        [$pipeline, $store] = $this->pipelineScenario();
        $store->arm(PHP_INT_MAX, ScriptedPublicationRateLimitStore::AFTER_LOSS_NO_SNAPSHOT);

        $result = $this->failure($pipeline);

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(2, $result->blockLevel);
        self::assertSame(60, $result->retryAfter);
        self::assertSame('k4_publication_deferred', $result->metadata?->cause);
        self::assertSame($control['scores'], $this->scores($store));
        self::assertSame(1, $store->publicationCalls);
    }

    public function testDeferralNeverAffectsMoreThanOneK4Key(): void
    {
        // LT-S-07: one logical K4 key per request. The pipeline keeps a single
        // lifecycle-eligible key, so one cause is enough.
        [$pipeline, $store] = $this->pipelineScenario();
        $store->arm(PHP_INT_MAX);
        $this->failure($pipeline);
        $pipelineReflection = new \ReflectionProperty($pipeline, 'lifecycleEligibleKeys');
        $eligible = $pipelineReflection->getValue($pipeline);
        self::assertIsArray($eligible);
        self::assertCount(1, $eligible);
    }

    /**
     * The mixed shape: one candidate persisting both K4 and K5 (EvaluationPipeline
     * L1983–L2003). A known device on an untrusted session that has spent the
     * known-device allowance scores K5 and K4 in the same failure; the twelfth
     * failure of mixedScenario() carries K4 at lifecycle L3 and K5 at L2.
     */
    public function testMixedCandidateWithoutLossBlocksAtTheK4LevelAndPublishesBoth(): void
    {
        $control = $this->mixedControlRun();
        [$pipeline, $store] = $this->mixedScenario();

        $result = $this->mixedFailure($pipeline);

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(3, $result->blockLevel, 'the K4 level, the higher of the two');
        self::assertSame($control['result']->retryAfter, $result->retryAfter);
        self::assertNull($result->metadata?->cause);
        self::assertSame(3, $store->checkBlock($control['k4Key'])?->level);
        self::assertSame(2, $store->checkBlock($control['k5Key'])?->level);
        self::assertSame($control['scores'], $this->scores($store));
    }

    public function testDeferredK4InAMixedCandidateKeepsTheDecisionAndPublishesK5(): void
    {
        $control = $this->mixedControlRun();
        [$pipeline, $store] = $this->mixedScenario();
        $store->arm(PHP_INT_MAX);

        $result = $this->mixedFailure($pipeline);

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(3, $result->blockLevel);
        self::assertSame($control['result']->retryAfter, $result->retryAfter);
        self::assertSame('k4_publication_deferred', $result->metadata?->cause);
        self::assertSame('k4_publication_deferred', $result->metadata->context?->reason);
        self::assertSame('k4', $result->metadata->context->scope);
        self::assertSame(4, $store->publicationCalls);
        self::assertSame(3, $store->snapshotReadsAfterLoss);
        self::assertSame(2, $store->checkBlock($control['k5Key'])?->level, 'LT-S-06: the remaining blocks are published');
        self::assertNull($store->checkBlock($control['k4Key']), 'the K4 block is deferred');
        self::assertSame($control['scores'], $this->scores($store));
    }

    /** @return array{0: EvaluationPipeline, 1: ScriptedPublicationRateLimitStore, 2: FixedClock} */
    private function pipelineScenario(): array
    {
        $clock = new FixedClock();
        $store = new ScriptedPublicationRateLimitStore($clock);
        $pipeline = $this->pipeline($clock, $store);
        $this->failure($pipeline);
        $this->failure($pipeline);

        return [$pipeline, $store, $clock];
    }

    /** @return array{result: RateLimitResultDTO, scores: array<string, int>, blocks: array<string, int>, k4Key: string} */
    private function controlRun(): array
    {
        $clock = new FixedClock();
        $store = new InMemoryRateLimitStore($clock);
        $pipeline = $this->pipeline($clock, $store);
        $this->failure($pipeline);
        $this->failure($pipeline);
        $result = $this->failure($pipeline);

        return [
            'result' => $result,
            'scores' => $this->scores($store),
            'blocks' => $this->blocks($store),
            'k4Key' => hash_hmac('sha256', 'login_protection:rate_limiter:k4:v2:prod:' . self::ACCOUNT, 'test_secret'),
        ];
    }

    /**
     * The first eleven failures of the mixed scenario (PLAN §1.3), the clock
     * advanced past every block after each.
     *
     * @return array{0: EvaluationPipeline, 1: ScriptedPublicationRateLimitStore, 2: FixedClock}
     */
    private function mixedScenario(): array
    {
        $clock = new FixedClock();
        $store = new ScriptedPublicationRateLimitStore($clock);
        $pipeline = $this->pipeline($clock, $store);
        for ($failure = 1; $failure <= 11; $failure++) {
            $this->mixedFailure($pipeline);
            $clock->setNow($clock->now()->modify('+' . self::MIXED_STEP_SECONDS . ' seconds'));
        }

        return [$pipeline, $store, $clock];
    }

    /**
     * The twelve failures of the mixed scenario on an unscripted store. The
     * scores pin the mixed shape of the twelfth: K4 12 (L3), K5 14 (L2).
     *
     * @return array{result: RateLimitResultDTO, scores: array<string, int>, k4Key: string, k5Key: string}
     */
    private function mixedControlRun(): array
    {
        $clock = new FixedClock();
        $store = new InMemoryRateLimitStore($clock);
        $pipeline = $this->pipeline($clock, $store);
        for ($failure = 1; $failure <= 11; $failure++) {
            $this->mixedFailure($pipeline);
            $clock->setNow($clock->now()->modify('+' . self::MIXED_STEP_SECONDS . ' seconds'));
        }
        $result = $this->mixedFailure($pipeline);
        $fingerprint = (new DeviceIdentityResolver(new FingerprintHasher('test_secret')))->resolve($this->mixedContext())->fingerprintHash;
        $k4Key = hash_hmac('sha256', 'login_protection:rate_limiter:k4:v2:prod:' . self::MIXED_ACCOUNT, 'test_secret');
        $k5Key = hash_hmac('sha256', 'login_protection:rate_limiter:k5:v2:prod:' . self::MIXED_ACCOUNT . ':' . $fingerprint, 'test_secret');
        $scores = $this->scores($store);
        self::assertSame(12, $scores[$k4Key] ?? null, 'K4 at L3 in the twelfth failure');
        self::assertSame(14, $scores[$k5Key] ?? null, 'K5 at L2 in the twelfth failure');
        self::assertSame(3, $store->checkBlock($k4Key)?->level);
        self::assertSame(2, $store->checkBlock($k5Key)?->level);

        return ['result' => $result, 'scores' => $scores, 'k4Key' => $k4Key, 'k5Key' => $k5Key];
    }

    private function mixedFailure(EvaluationPipeline $pipeline): RateLimitResultDTO
    {
        $context = $this->mixedContext();
        $device = (new DeviceIdentityResolver(new FingerprintHasher('test_secret')))->resolve($context);

        return $pipeline->process(new LoginProtectionPolicy(), $context, RateLimitCommand::recordFailure('login_protection'), $device);
    }

    /** A device verified for the account, on an untrusted session: the generic candidate branch. */
    private function mixedContext(): RateLimitContextDTO
    {
        return new RateLimitContextDTO(
            '198.51.100.10',
            'Mozilla/5.0 lifecycle',
            self::MIXED_ACCOUNT,
            ['device' => self::DEVICE],
            isDevicePreviouslyVerifiedForAccount: true,
        );
    }

    private function failure(EvaluationPipeline $pipeline): RateLimitResultDTO
    {
        $policy = new LoginProtectionPolicy();
        $context = $this->context();
        $device = (new DeviceIdentityResolver(new FingerprintHasher('test_secret')))->resolve($context);

        return $pipeline->process($policy, $context, RateLimitCommand::recordFailure('login_protection'), $device);
    }

    private function pipeline(FixedClock $clock, InMemoryRateLimitStore $store): EvaluationPipeline
    {
        $correlation = new StatefulInMemoryCorrelationStore($clock);

        return new EvaluationPipeline(
            $store,
            $correlation,
            new BudgetTracker($store, $clock),
            new AntiEquilibriumGate($correlation),
            new DecayCalculator($clock),
            new EphemeralBucket($correlation),
            'test_secret',
            'prod',
            $clock,
            null,
        );
    }

    private function engine(FixedClock $clock, InMemoryRateLimitStore $store, InMemoryCircuitBreakerStore $circuit): RateLimiterEngine
    {
        $correlation = new StatefulInMemoryCorrelationStore($clock);
        $signals = new RecordingFailureSignalEmitter();
        $pipeline = new EvaluationPipeline(
            $store,
            $correlation,
            new BudgetTracker($store, $clock),
            new AntiEquilibriumGate($correlation),
            new DecayCalculator($clock),
            new EphemeralBucket($correlation),
            'test_secret',
            'prod',
            $clock,
            null,
        );

        return new RateLimiterEngine(
            new DeviceIdentityResolver(new FingerprintHasher('test_secret')),
            $pipeline,
            new CircuitBreaker($circuit, $signals, $clock),
            new FailureModeResolver(),
            $signals,
            $clock,
            [new LoginProtectionPolicy(), new OtpProtectionPolicy()],
        );
    }

    private function context(): RateLimitContextDTO
    {
        return new RateLimitContextDTO('198.51.100.10', 'Mozilla/5.0 lifecycle', self::ACCOUNT, ['device' => self::DEVICE]);
    }

    /** @return array<string, int> */
    private function scores(InMemoryRateLimitStore $store): array
    {
        /** @var array<string, array{value: int}> $data */
        $data = (new \ReflectionProperty(InMemoryRateLimitStore::class, 'data'))->getValue($store);
        $scores = array_map(static fn(array $row): int => $row['value'], $data);
        ksort($scores);

        return $scores;
    }

    /** @return array<string, int> */
    private function blocks(InMemoryRateLimitStore $store): array
    {
        /** @var array<string, array{level: int}> $blocks */
        $blocks = (new \ReflectionProperty(InMemoryRateLimitStore::class, 'blocks'))->getValue($store);
        $levels = array_map(static fn(array $row): int => $row['level'], $blocks);
        ksort($levels);

        return $levels;
    }

    /** @return array<string, int> */
    private function blocksOfKey(InMemoryRateLimitStore $store, string $key): array
    {
        return array_intersect_key($this->blocks($store), [$key => true]);
    }
}
