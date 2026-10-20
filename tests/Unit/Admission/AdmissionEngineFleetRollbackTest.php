<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Service\AdmissionRuntime;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionInMemoryReferenceStore;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\FailureModeResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\GateReport;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;
use Maatify\RateLimiter\Tests\Support\Admission\ServiceMutant;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * NM-7, engine part (5b3b/PLAN.md §5.2 and §4: 4 workers round-robin, old-worker shares 0.25 and 0.50; store-level concurrency
 * and failover are the 5-b2 values of record and are not re-run). An old worker is an engine of today's build: no admission
 * runtime and the policy without the capability (`LoginProtectionPolicy`, the same name), over the same stores and clock.
 *
 * Registered reading (raised with the tests, before any run): behind the fence (closed while the fleet is mixed) the reported
 * bound is S0's: the new workers behave exactly as old ones (every decision equals an all-old fleet's, and no reservation, claim
 * or outcome reaches the admission store). With the fence open while the fleet is still mixed, the bound per window is reported
 * as `c_x + old-worker calls`: admissions never exceed it. Detector: a fence that is ignored (`fenceOpen` returns true) makes the
 * new workers reserve behind a closed fence, so the mixed fleet is no longer S0.
 *
 * M6 rollback: an old engine on the stores of the new build ignores the admission counters, sets and permits (its decisions equal
 * those it gives on a pipeline state with no admission history), K4U survives (the old build reads the account's K4 key the new
 * build wrote on U), the P-family state is listed by name and not read, and a roll-forward within the window finds the old counters.
 */
#[Group('nm7-engine')]
final class AdmissionEngineFleetRollbackTest extends TestCase
{
    private const IP = '203.0.113.60';

    private static function oldWorker(EngineLab $lab, ?EvaluationPipeline $pipeline = null): RateLimiterEngine
    {
        $emitter = new RecordingFailureSignalEmitter();

        return new RateLimiterEngine(
            new DeviceIdentityResolver(new FingerprintHasher(EngineLab::FINGERPRINT_SECRET)),
            $pipeline ?? $lab->pipeline,
            new CircuitBreaker(new InMemoryCircuitBreakerStore(), $emitter, $lab->clock),
            new FailureModeResolver(),
            $emitter,
            $lab->clock,
            [new LoginProtectionPolicy()],
            null,
        );
    }

    /** An old worker over another score store (the same pipeline construction as `EngineLab`, the lab's clock). */
    private static function oldWorkerOn(AdmissionInMemoryReferenceStore $store, EngineLab $lab): RateLimiterEngine
    {
        $pipeline = new EvaluationPipeline(
            $store,
            $store->correlationStore(),
            new BudgetTracker($store, $lab->clock),
            new AntiEquilibriumGate($store->correlationStore()),
            new DecayCalculator($lab->clock),
            new EphemeralBucket($store->correlationStore()),
            EngineLab::KEY_SECRET,
            EngineLab::ENV,
            $lab->clock,
        );

        return self::oldWorker($lab, $pipeline);
    }

    /**
     * A spray from one source over three windows (one new account every 5 s, each attempt a failure), served round-robin by four
     * workers of which the first `$old` are old. Returns the decisions and, per window, admissions, calls through old workers.
     *
     * @param class-string $runtimeClass
     * @return array{decisions: string, perWindow: array<int, array{admitted: int, oldCalls: int}>, storeCalls: list<string>}
     */
    private static function fleet(int $old, bool $fenceOpen, string $runtimeClass = AdmissionRuntime::class): array
    {
        $lab = new EngineLab(EngineLab::budgets(), $fenceOpen, runtimeClass: $runtimeClass);
        $workers = [];
        for ($w = 0; $w < 4; $w++) {
            $workers[] = $w < $old ? self::oldWorker($lab) : $lab->engine;
        }
        $lab->admissionStore->calls = [];
        $decisions = '';
        $perWindow = [];
        for ($i = 0; $i < 360; $i++) {
            $lab->at(5 * $i);
            $engine = $workers[$i % 4];
            $ctx = $lab->context('acct-f' . $i, self::IP);
            $check = $engine->limit($ctx, RateLimitCommand::checkOnly(EngineLab::POLICY));
            $w = intdiv(5 * $i, 600);
            $perWindow[$w] ??= ['admitted' => 0, 'oldCalls' => 0];
            $perWindow[$w]['oldCalls'] += $i % 4 < $old ? 1 : 0;
            $decision = $check->decision;
            if ($decision === RateLimitResultDTO::DECISION_ALLOW) {
                $perWindow[$w]['admitted']++;
                $decision = $engine->limit($ctx, new RateLimitCommand(EngineLab::POLICY, 1, false, true, false, EngineLab::permit($check)))->decision;
            }
            $decisions .= $decision[0];
        }

        return ['decisions' => $decisions, 'perWindow' => $perWindow, 'storeCalls' => array_values(array_unique($lab->admissionStore->calls))];
    }

    /** @return iterable<string, array{int}> */
    public static function oldShares(): iterable
    {
        yield 'old share 0.25' => [1];
        yield 'old share 0.50' => [2];
    }

    #[DataProvider('oldShares')]
    public function testBehindTheFenceTheMixedFleetIsS0(int $old): void
    {
        $mixed = self::fleet($old, false);
        $allOld = self::fleet(4, false);

        self::assertSame($allOld['decisions'], $mixed['decisions'], 'a closed fence: every decision is the all-old fleet\'s');
        self::assertSame(['readEpoch'], $mixed['storeCalls'], 'only the fence is read; no reservation, claim or outcome');
    }

    #[DataProvider('oldShares')]
    public function testWithTheFenceOpenWhileMixedAdmissionsStayWithinTheReportedBound(int $old): void
    {
        $run = self::fleet($old, true);
        $cx = EngineLab::budgets()->sourceCapacity;
        GateReport::emit('nm7-fleet', ['oldWorkers' => $old, 'cx' => $cx, 'perWindow' => $run['perWindow']]);

        foreach ($run['perWindow'] as $w => $v) {
            self::assertLessThanOrEqual($cx + $v['oldCalls'], $v['admitted'], "window $w: admitted <= c_x + old-worker calls");
        }
    }

    public function testDetectorAnIgnoredFenceMakesTheNewWorkersReserveBehindIt(): void
    {
        $runtime = ServiceMutant::load('AdmissionRuntime', ['fenceOpen' => 'return true;']);
        $mixed = self::fleet(2, false, $runtime);
        $allOld = self::fleet(4, false);

        self::assertNotSame($allOld['decisions'], $mixed['decisions'], 'DETECTOR: behind a closed fence the new workers refuse');
    }

    // ---- M6 rollback ---------------------------------------------------------------------------------------------------------

    private static function k4Key(string $type, string $account): string
    {
        return hash_hmac('sha256', "login_protection:rate_limiter:{$type}:v2:prod:{$account}", EngineLab::KEY_SECRET);
    }

    public function testM6RollbackIgnoresTheAdmissionStateKeepsK4UAndRollForwardFindsTheOldCounters(): void
    {
        $lab = new EngineLab(EngineLab::budgets());
        // New build: twenty admissions from the source (its counter at 20 of 24), among them a U failure of one account (K4U),
        // and a P failure of another account from another source (K4P and the P family).
        for ($i = 0; $i < 18; $i++) {
            $lab->check($lab->context('acct-n' . $i, self::IP));
        }
        $u = $lab->context('acct-u', self::IP);
        $lab->outcome($u, false, EngineLab::permit($lab->check($u)));
        $p = $lab->context('acct-p', '198.51.100.90', 'inst-p', $lab->mint('acct-p', 'inst-p'));
        $lab->outcome($p, false, EngineLab::permit($lab->check($p)));
        $lab->check($lab->context('acct-n18', self::IP));

        // K4U is the account's K4 key (the slot the old build reads); K4P is its own family.
        self::assertNotNull($lab->store->get(self::k4Key('k4', 'acct-u')), 'K4U written on the K4 key');
        self::assertNotNull($lab->store->get(self::k4Key('k4p', 'acct-p')), 'K4P written on its own key');
        self::assertNull($lab->store->get(self::k4Key('k4', 'acct-p')), 'the P failure wrote nothing on K4');

        // Rollback: the old build on the stores of the new build, and on a deep copy of them with every admission structure
        // (counters, device sets, failure sets, pending members, buckets, permits, epochs) emptied. Same decisions = the old build
        // ignores the admission state.
        $copy = unserialize(serialize($lab->store));
        self::assertInstanceOf(AdmissionInMemoryReferenceStore::class, $copy);
        foreach (['counters', 'devices', 'sets', 'pending', 'buckets', 'permits', 'epochs'] as $property) {
            $reflection = new \ReflectionProperty(AdmissionInMemoryReferenceStore::class, $property);
            if (in_array($property, ['counters', 'sets', 'buckets', 'permits', 'epochs'], true)) {
                self::assertNotSame([], $reflection->getValue($lab->store), "the new build left {$property} (the copy differs)");
            }
            $reflection->setValue($copy, []);
        }
        $old = self::oldWorker($lab);
        $oldOnCopy = self::oldWorkerOn($copy, $lab);
        $onStore = '';
        $onCopy = '';
        // Three old-build requests from the source of the counter (fewer than the old build's own spray threshold, so it leaves no
        // collective block there: LT-10b, an old block is honoured on U) and eighteen from another source.
        for ($i = 19; $i < 40; $i++) {
            $ip = $i < 22 ? self::IP : '203.0.113.61';
            $onStore .= $old->limit($lab->context('acct-n' . $i, $ip), RateLimitCommand::checkOnly(EngineLab::POLICY))->decision[0];
            $onCopy .= $oldOnCopy->limit($lab->context('acct-n' . $i, $ip), RateLimitCommand::checkOnly(EngineLab::POLICY))->decision[0];
        }
        self::assertSame($onCopy, $onStore, 'the old build ignores the admission counters, sets and permits');
        self::assertNotNull($lab->store->get(self::k4Key('k4', 'acct-u')), 'K4U survives the rollback and is the key the old build reads');
        self::assertSame(
            $oldOnCopy->limit($u, RateLimitCommand::checkOnly(EngineLab::POLICY))->decision,
            $old->limit($u, RateLimitCommand::checkOnly(EngineLab::POLICY))->decision,
        );
        self::assertNotNull($lab->store->get(self::k4Key('k4p', 'acct-p')), 'K4P is still in the store after the rollback; the old build has no reader of it');
        GateReport::emit('nm7-m6', ['oldBuildDecisions' => $onStore, 'lost' => ['K4P', 'K5p', 'k5p', 'anti_equilibrium_device_p', 'known_device_allowance_p', 'anti_equilibrium_p', 'budget_cooldown_p']]);

        // Roll-forward within the same window: the new build finds its counter (20 of 24 used before the rollback; the three
        // old-build admissions from that source were not counted: the bound of the window is c_x + old-build admissions), so
        // exactly four more are admitted.
        $forward = '';
        for ($i = 40; $i < 50; $i++) {
            $forward .= $lab->check($lab->context('acct-n' . $i, self::IP))->decision[0];
        }
        self::assertSame('AAAASSSSSS', $forward, 'roll-forward finds the old counter');
    }
}
