<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Service\AdmissionRuntime;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionInMemoryReferenceStore;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\ArchiveData;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\GateReport;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\ReplaySession;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;
use Maatify\RateLimiter\Tests\Support\Admission\ServiceMutant;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Gates SC-1 … SC-6 (AP §6.7; contract §8.3; 5b3b/PLAN.md §5.2, LT-17 reading of SC-3) through the engine, each with the
 * mutation of the real `src/` file that breaks it. The admission store is a separate reference store, so the lab's `store` holds
 * the pipeline state only.
 */
#[Group('sc')]
final class AdmissionEngineSourceCapacityTest extends TestCase
{
    private const IP = '203.0.113.50';

    /**
     * @param class-string $runtimeClass
     * @param class-string $engineClass
     */
    private static function lab(int $sourceCapacity = 24, string $runtimeClass = AdmissionRuntime::class, string $engineClass = RateLimiterEngine::class): EngineLab
    {
        return new EngineLab(
            EngineLab::budgets(sourceCapacity: $sourceCapacity),
            policies: [new OtpProtectionPolicy()],
            runtimeClass: $runtimeClass,
            engineClass: $engineClass,
            admissionInner: new AdmissionInMemoryReferenceStore(new FixedClock('@' . EngineLab::T0)),
        );
    }

    /** Pre-checks with no outcome from one source, one account each: the source counter is the only scope that fills. */
    private static function exhaust(EngineLab $lab, int $n = 24, string $prefix = 'acct-s'): void
    {
        for ($i = 0; $i < $n; $i++) {
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $lab->check($lab->context($prefix . $i, self::IP))->decision);
        }
    }

    private static function state(object $store): string
    {
        return hash('sha256', (string) preg_replace('/s:32:"[0-9a-f]{32}";/', 's:32:"ID";', serialize($store)));
    }

    /** @param class-string $runtimeClass */
    private static function refusalOf(string $runtimeClass = AdmissionRuntime::class): RateLimitResultDTO
    {
        $lab = self::lab(runtimeClass: $runtimeClass);
        self::exhaust($lab);

        return $lab->check($lab->context('acct-next', self::IP));
    }

    // ---- SC-1 cause -------------------------------------------------------------------------------------------------------------

    public function testSc1ASourceRefusalCarriesItsOwnCause(): void
    {
        $r = self::refusalOf();

        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $r->decision);
        self::assertSame(1, $r->blockLevel);
        self::assertSame('source_capacity', EngineLab::cause($r));
        self::assertSame('source_capacity', $r->metadata?->context?->reason, 'the reason carries the cause');
        self::assertSame('source', EngineLab::scope($r));
    }

    public function testDetectorSc1TheSourceRefusalNamedAsAnotherCause(): void
    {
        $runtime = ServiceMutant::load('AdmissionRuntime', [], [["'source_capacity' => ['source_capacity', 'source'],", "'source_capacity' => ['account_bucket', 'account'],"]]);

        self::assertNotSame('source_capacity', self::refusalOf($runtime)->metadata?->cause, 'DETECTOR');
    }

    // ---- SC-2 no persistence ------------------------------------------------------------------------------------------------------

    /**
     * @param class-string $runtimeClass
     * @return array{before: string, after: string, next: string}
     */
    private static function persistence(string $runtimeClass = AdmissionRuntime::class): array
    {
        $lab = self::lab(runtimeClass: $runtimeClass);
        self::exhaust($lab);
        $admission = $lab->admissionStore->inner;
        $before = self::state($admission);
        self::assertSame('source_capacity', EngineLab::cause($lab->check($lab->context('acct-next', self::IP))));
        $after = self::state($admission);
        $lab->at(600);

        return ['before' => $before, 'after' => $after, 'next' => $lab->check($lab->context('acct-next', self::IP))->decision];
    }

    public function testSc2ARefusalWritesNothingAndTheNextWindowIsAdmittedWithNoCarriedState(): void
    {
        $p = self::persistence();

        self::assertSame($p['before'], $p['after'], 'the refusal wrote nothing to the admission store');
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $p['next']);
    }

    public function testDetectorSc2ARefusalThatWrites(): void
    {
        $runtime = ServiceMutant::load('AdmissionRuntime', [], [[
            "        if (! \$result->admitted) {\n",
            "        if (! \$result->admitted) {\n            \$this->store->applyAdmissionOutcome(new AdmissionOutcomeDTO(\$budgetGroup, AdmissionOutcomeKindEnum::FAILURE, \$scopes), \$t);\n",
        ]]);

        $p = self::persistence($runtime);
        self::assertNotSame($p['before'], $p['after'], 'DETECTOR');
    }

    // ---- SC-3 no escalation (LT-17) -------------------------------------------------------------------------------------------------

    /**
     * @param class-string $engineClass
     * @return array{refused: string, admitted: string, observation: bool}
     */
    private static function escalation(string $engineClass = RateLimiterEngine::class): array
    {
        $refusing = self::lab(24, engineClass: $engineClass);
        $admitting = self::lab(1000, engineClass: $engineClass);
        self::exhaust($refusing);
        self::exhaust($admitting);
        $before = self::state($refusing->store);
        self::assertSame('source_capacity', EngineLab::cause($refusing->check($refusing->context('acct-next', self::IP))));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $admitting->check($admitting->context('acct-next', self::IP))->decision);

        return ['refused' => self::state($refusing->store), 'admitted' => self::state($admitting->store), 'observation' => $before !== self::state($refusing->store)];
    }

    public function testSc3ACapacityRefusalChangesNoScoreBudgetWatcherOrCycle(): void
    {
        $e = self::escalation();
        GateReport::emit('sc3', ['preCheckObservationWritesBeforeTheRefusal' => $e['observation']]);

        self::assertSame($e['admitted'], $e['refused'], 'the refused request left the pipeline state an admitted pre-check leaves');
    }

    public function testDetectorSc3AnEngineThatScoresTheRefusal(): void
    {
        $engine = ServiceMutant::load('RateLimiterEngine', [], [[
            '            return $admission->reserve($policy->getName(), $group, $context, $device, $lane, $result);',
            '            $reserved = $admission->reserve($policy->getName(), $group, $context, $device, $lane, $result);'
            . ' if ($reserved->decision !== RateLimitResultDTO::DECISION_ALLOW) { $this->pipeline->process($policy, $context, RateLimitCommand::recordFailure($policy->getName()), $device, $lane->lane); }'
            . ' return $reserved;',
        ]]);

        $e = self::escalation($engine);
        self::assertNotSame($e['admitted'], $e['refused'], 'DETECTOR');
    }

    // ---- SC-4 lane scope ----------------------------------------------------------------------------------------------------------

    /**
     * @param class-string $runtimeClass
     * @return array{p: string, other: string}
     */
    private static function laneScope(string $runtimeClass = AdmissionRuntime::class): array
    {
        $lab = self::lab(runtimeClass: $runtimeClass);
        self::exhaust($lab);
        $p = $lab->check($lab->context('acct-p', self::IP, 'inst-p', $lab->mint('acct-p', 'inst-p')));
        $other = $lab->engine->limit($lab->context('acct-otp', self::IP), RateLimitCommand::checkOnly('otp_protection'));

        return ['p' => $p->decision, 'other' => $other->decision];
    }

    public function testSc4ProvenAndOtherPolicyRequestsFromTheExhaustedSourceAreAdmitted(): void
    {
        self::assertSame(['p' => RateLimitResultDTO::DECISION_ALLOW, 'other' => RateLimitResultDTO::DECISION_ALLOW], self::laneScope());
    }

    public function testDetectorSc4AProvenDoorThatCarriesTheSourceScope(): void
    {
        $runtime = ServiceMutant::load('AdmissionRuntime', [], [[
            "        \$scopes = [\n            \$this->counter('proof_account_window'",
            "        \$scopes = [\n            \$this->counter('source_capacity', \$group, 'src', SourcePrefix::of(\$context->ip), \$window, \$b->sourceCapacity, 2 * \$b->windowSeconds),\n            \$this->counter('proof_account_window'",
        ]]);

        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, self::laneScope($runtime)['p'], 'DETECTOR');
    }

    // ---- SC-5 rotation invariance -------------------------------------------------------------------------------------------------

    /** @param class-string $runtimeClass */
    private static function rotating(string $runtimeClass = AdmissionRuntime::class): int
    {
        $lab = self::lab(runtimeClass: $runtimeClass);
        $admitted = 0;
        for ($i = 0; $i < 100; $i++) {
            $lab->at($i);
            $context = new RateLimitContextDTO(
                self::IP,
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/' . (100 + $i) . '.0',
                'acct-r' . $i,
                ['device' => 'fp-' . $i],
                'inst-r' . $i,
                false,
                [],
                false,
                null,
                clientInstanceId: 'inst-r' . $i,
            );
            $admitted += $lab->check($context)->decision === RateLimitResultDTO::DECISION_ALLOW ? 1 : 0;
        }

        return $admitted;
    }

    public function testSc5UaIdAndFingerprintRotationGainNothingBeyondTheCeiling(): void
    {
        self::assertSame(24, self::rotating());
    }

    public function testDetectorSc5AUaKeyedSourceScope(): void
    {
        $runtime = ServiceMutant::load('AdmissionRuntime', [], [[
            "\$scopes = [\$this->counter('source_capacity', \$group, 'src', \$source, \$window,",
            "\$scopes = [\$this->counter('source_capacity', \$group, 'src', \$source . \$context->ua, \$window,",
        ]]);

        self::assertGreaterThan(24, self::rotating($runtime), 'DETECTOR');
    }

    // ---- SC-6 co-tenant harm --------------------------------------------------------------------------------------------------------

    /**
     * Every archived stream through the production budgets, `pre+out`: each legitimate refusal that the store attributes to the
     * source counter (an independent record: `FaultInjectingAdmissionStore::$refused`) reaches the Host as `source_capacity` with
     * scope `source`; the counts are reported per stream.
     *
     * @param class-string $runtimeClass
     * @return array{report: array<string, int>, misattributed: list<string>}
     */
    private static function coTenants(string $runtimeClass = AdmissionRuntime::class, bool $all = true): array
    {
        $report = [];
        $wrong = [];
        $tuples = (static function () use ($all): \Generator {
            yield from ArchiveData::get()->streams();
            if ($all) {
                yield from ArchiveData::get()->g7Streams();
            }
        })();
        foreach ($tuples as $t) {
            if (! $all && $t['stream'] !== 'MIX') {
                continue;
            }
            $lab = new EngineLab(EngineLab::budgets(), runtimeClass: $runtimeClass);
            $s = new ReplaySession($lab, 'pre+out');
            ReplaySession::assertInput($t['rows']);
            $key = $t['stream'] . '|' . $t['strategy'] . '|' . $t['ids'];
            $report[$key] = 0;
            foreach ($t['rows'] as $row) {
                if (isset($row['effects'])) {
                    $s->event($row['effects'], $row['t']);
                    continue;
                }
                $n = count($lab->admissionStore->refused);
                $step = $s->step($row);
                $new = array_slice($lab->admissionStore->refused, $n);
                if ($step['kind'] === 'X' || ! in_array('source_capacity', $new, true)) {
                    continue;
                }
                $report[$key]++;
                if ($step['cause'] !== 'source_capacity' || $step['scope'] !== 'source') {
                    $wrong[] = "{$key} t={$row['t']}: {$step['cause']} / {$step['scope']}";
                }
            }
            unset($s, $lab);
            gc_collect_cycles();
        }

        return ['report' => array_filter($report), 'misattributed' => $wrong];
    }

    public function testSc6CoTenantHarmIsAttributedToTheSourceAndReportedPerStream(): void
    {
        $c = self::coTenants();
        GateReport::emit('sc6', ['legitSourceCapacityRefusals' => $c['report']]);

        self::assertSame([], $c['misattributed']);
        self::assertNotSame([], $c['report'], 'at least one stream has a co-tenant refused by the source ceiling');
    }

    public function testDetectorSc6ASourceRefusalReportedOnAnotherScope(): void
    {
        $runtime = ServiceMutant::load('AdmissionRuntime', [], [["'source_capacity' => ['source_capacity', 'source'],", "'source_capacity' => ['source_capacity', 'account'],"]]);

        self::assertNotSame([], self::coTenants($runtime, false)['misattributed'], 'DETECTOR');
    }
}
