<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisAdmissionOperations;
use Maatify\RateLimiter\Tests\Support\Admission\MergedAdmissionOperations;
use Maatify\RateLimiter\Tests\Support\Admission\OperationsMutant;
use Maatify\RateLimiter\Tests\Support\Admission\OptInParityRunner;
use Maatify\RateLimiter\Tests\Support\Admission\SettlementPort;
use Maatify\RateLimiter\Tests\Support\Admission\SettlementScenariosOff as Off;
use Maatify\RateLimiter\Tests\Support\Admission\SettlementScenarios as On;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * 5b2.2 gates on real Redis: OFF-PARITY (without `settlesOnExpiry` the production operations equal the operations as merged at
 * d6aea4d, step by step, result and full dump), the default behaviour (tombstone), ON-PARITY (with the flag, the results of
 * 5-b2.1) and the detector that ignores the flag.
 */
#[Group('settle-opt')]
final class AdmissionSettlementOptInRedisTest extends AdmissionRedisGateTestCase
{
    private function port(object $ops): SettlementPort
    {
        /** @var RedisAdmissionOperations|MergedAdmissionOperations $ops */
        return new SettlementPort(
            static fn($request, int $now) => $ops->reserve($request, $now),
            static fn($outcome, int $now) => $ops->applyOutcome($outcome, $now),
            static fn(string $id, AdmissionOutcomeKindEnum $kind, int $now) => $ops->claimPermit($id, 'U', 'acctT', 'login', $kind, $now),
        );
    }

    /**
     * @param \Closure(SettlementPort): mixed $scenario
     * @return array{result: mixed, dumps: list<array<string, array{type: string, value: mixed, hasTtl: bool}>>, trace: list<string>}
     */
    private function recorded(object $ops, \Closure $scenario): array
    {
        $this->resetRedis();
        $dumps = [];
        $trace = [];
        $inner = $this->port($ops);
        $recording = new SettlementPort(
            function ($request, int $now) use ($inner, &$dumps, &$trace) {
                $r = ($inner->reserve)($request, $now);
                $trace[] = 'reserve:' . json_encode([$r->admitted, $r->refusedScope, $r->retryAfterSeconds]);
                $dumps[] = $this->dump();

                return $r;
            },
            function ($outcome, int $now) use ($inner, &$dumps, &$trace): void {
                ($inner->outcome)($outcome, $now);
                $trace[] = 'outcome:' . $outcome->outcome->value;
                $dumps[] = $this->dump();
            },
            function (string $id, AdmissionOutcomeKindEnum $kind, int $now) use ($inner, &$dumps, &$trace) {
                $c = ($inner->claim)($id, $kind, $now);
                $trace[] = 'claim:' . $c->value;
                $dumps[] = $this->dump();

                return $c;
            },
        );

        return ['result' => $scenario($recording), 'dumps' => $dumps, 'trace' => $trace];
    }

    private function production(): RedisAdmissionOperations
    {
        return new RedisAdmissionOperations($this->executor, self::NAMESPACE);
    }

    private function merged(): MergedAdmissionOperations
    {
        return new MergedAdmissionOperations($this->executor, self::NAMESPACE);
    }

    // ---- OFF-PARITY ---------------------------------------------------------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function settleScenarios(): iterable
    {
        foreach (['exactBound', 'outcomes', 'keepsSettledFlag', 'dueSurvivesSuccess', 'onceAndLate', 'noRefund'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('settleScenarios')]
    public function testOffParityTheSettleScenariosGiveTheSameResultsAndDumpsAsD6aea4d(string $name): void
    {
        $run = static fn(SettlementPort $port): mixed => Off::$name($port);
        $merged = $this->recorded($this->merged(), $run);
        $production = $this->recorded($this->production(), $run);

        self::assertSame($merged['trace'], $production['trace']);
        self::assertSame($merged['result'], $production['result']);
        self::assertSame($merged['dumps'], $production['dumps'], "the key dump after every step of $name");
    }

    /** @return iterable<string, array{int}> */
    public static function seeds(): iterable
    {
        foreach ([1, 7, 42, 2026, 90210] as $seed) {
            yield 'seed ' . $seed => [$seed];
        }
    }

    #[DataProvider('seeds')]
    public function testOffParityASeededSequenceOfEveryOperationKindIsIdenticalStepByStep(int $seed): void
    {
        $this->resetRedis();
        $a = OptInParityRunner::run($this->merged(), $this->executor, $seed, 300);
        $this->resetRedis();
        $b = OptInParityRunner::run($this->production(), $this->executor, $seed, 300);

        self::assertCount(300, $a);
        foreach ($a as $i => $step) {
            self::assertSame($step['record'], $b[$i]['record'], "result of step $i");
            self::assertSame($step['dump'], $b[$i]['dump'], "key dump after step $i");
        }
        // The sequence is not vacuous: it admits, refuses, claims (accepted, late or unknown) and meets the malformed state.
        $records = implode(' ', array_column($a, 'record'));
        self::assertStringContainsString('reserve:[true', $records);
        self::assertStringContainsString('reserve:[false', $records);
        self::assertStringContainsString('claim:', $records);
        self::assertStringContainsString('throw:' . RateLimiterException::class, $records);
    }

    public function testOffParityTheFiveSeedsTogetherPractiseEveryClaimKind(): void
    {
        // Each seed alone only has to claim (the loosened per-seed check); together the seeds must exercise an accepted claim, a
        // late one, an unknown one, and the duplicate and conflict reasons, so the OFF-PARITY sequences are not vacuous.
        $counts = [];
        foreach ([1, 7, 42, 2026, 90210] as $seed) {
            $this->resetRedis();
            foreach (OptInParityRunner::run($this->production(), $this->executor, $seed, 300) as $step) {
                if (str_starts_with($step['record'], 'claim:')) {
                    $kind = substr($step['record'], 6);
                    $counts[$kind] = ($counts[$kind] ?? 0) + 1;
                }
            }
        }
        foreach (['accepted', 'late', 'unknown', 'duplicate', 'conflict'] as $kind) {
            self::assertGreaterThanOrEqual(1, $counts[$kind] ?? 0, "claim kind $kind over the five seeds: " . json_encode($counts));
        }
    }

    public function testTheDefaultWritesNoPendingKeyAndAppliesNoLifetimeGuard(): void
    {
        $port = $this->port($this->production());
        // A permit whose deadline lies beyond the set lifetime is accepted at the default (the d6aea4d contract).
        self::assertTrue(Off::reserve($port, Off::N, Off::member('s:off', 'acct-a', 1), Off::permit('permit-off', Off::N + Off::TTL + 500)));
        foreach (array_keys($this->dump()) as $key) {
            self::assertStringNotContainsString(':adm:p:', $key, 'no pending key at the default');
        }
        // Tombstone semantics: an admission with no outcome never counts, whatever the clock.
        self::assertTrue(Off::reserve($port, Off::N + 5000, Off::member('s:off', 'new', 1)));
    }

    // ---- ON-PARITY ----------------------------------------------------------------------------------------------------

    public function testOnParityTheSettleScenariosWithTheFlagGiveThe5b21Results(): void
    {
        $port = $this->port($this->production());

        self::assertSame(['beforeDeadline' => true, 'atDeadlineNew' => false, 'atDeadlineOwn' => true], On::exactBound($port));
        self::assertSame(['settledKeptThenNew' => false, 'pendingCancelledThenNew' => true], On::keepsSettledFlag($port));
        self::assertSame(['dueSurvivesSuccess' => false], On::dueSurvivesSuccess($port));
        self::assertSame(['counter' => false, 'bucket' => false], On::noRefund($port));
        self::assertSame(
            ['successBeforeDeadlineThenNew' => true, 'droppedOutcomeThenNew' => false, 'explicitFailureThenNew' => false, 'netSuccessThenNew' => true],
            On::outcomes($port),
        );
    }

    public function testTheDefaultIsTheTombstoneWhereTheFlagSettles(): void
    {
        $port = $this->port($this->production());

        // Without the flag nothing is counted at the deadline: the arms differ exactly where settlement matters.
        self::assertSame(['beforeDeadline' => true, 'atDeadlineNew' => true, 'atDeadlineOwn' => true], Off::exactBound($port));
        self::assertTrue(Off::outcomes($port)['droppedOutcomeThenNew'], 'a dropped outcome does not count at the default');
    }

    // ---- Detector -----------------------------------------------------------------------------------------------------------

    public function testDetectorIgnoringTheFlagBreaksOffParity(): void
    {
        $class = OperationsMutant::load([['s.settles = ARGV[base + 5] == \'1\'', 's.settles = true']]);
        $mutant = new $class($this->executor, self::NAMESPACE);
        $run = static fn(SettlementPort $port): mixed => Off::exactBound($port);
        $merged = $this->recorded($this->merged(), $run);
        $mutated = $this->recorded($mutant, $run);

        self::assertNotSame($merged['dumps'], $mutated['dumps'], 'DETECTOR: a store that ignores the flag diverges from d6aea4d');
    }
}
