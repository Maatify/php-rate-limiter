<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Tests\Support\Admission\Archive\FixtureReplays;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Stage 5-c, LT-28: the 5-b3b archive gates that run on the package constants, re-run on the runtime the BUILDER composes
 * (`EngineLab::throughBuilder()`: `RateLimiterBuilder`, the Login opt-in variant, the reference store). A rerun is not a
 * re-registration: the replays and the comparisons are the existing tests' own code, invoked by reflection and unchanged;
 * only the lab differs. The `PX4` arm is the one whose budgets equal the package constants (`FULL2` + the D8 rule); the
 * `FULL2` arm without D8, every ablation and every mutant detector stay on the direct engine (5c/PLAN.md §7.3, LT-28).
 */
#[Group('archive')]
#[Group('builder-rerun')]
final class AdmissionBuilderRerunTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        ini_set('memory_limit', '1G');
    }

    /** @return mixed the private static method of an existing gate test, invoked unchanged */
    private static function invoke(string $class, string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod($class, $method))->invoke(null, ...$arguments);
    }

    /**
     * RP-G7 / RP-G6 through the builder: the 210 `PX4` rows of G7 (every G4 v2 and G7 tuple, `pre+out` and `pre`), replayed
     * with `AdmissionArchiveG7Test::replay()` on builder labs and judged by `testEveryArchivedG7RowIsReproducedFieldForField`.
     */
    public function testEveryArchivedG7Px4RowIsReproducedThroughTheBuilder(): void
    {
        $replays = [];
        /** @var \Generator<int, array{stream: string, strategy: string, ids: string, rows: list<array<string, mixed>>}> $tuples */
        $tuples = self::invoke(AdmissionArchiveG7Test::class, 'tuples');
        foreach ($tuples as $t) {
            foreach (['pre+out', 'pre'] as $flow) {
                $replays[$t['stream'] . '|' . $t['strategy'] . '|' . $t['ids'] . '|' . $flow]
                    = self::invoke(AdmissionArchiveG7Test::class, 'replay', $t, $flow, EngineLab::throughBuilder());
                gc_collect_cycles();
            }
        }
        $cache = new \ReflectionProperty(AdmissionArchiveG7Test::class, 'replays');
        $before = $cache->getValue();
        $cache->setValue(null, ['PX4' => $replays]);
        try {
            (new AdmissionArchiveG7Test('testEveryArchivedG7RowIsReproducedFieldForField'))->testEveryArchivedG7RowIsReproducedFieldForField('PX4');
        } finally {
            $cache->setValue(null, $before);
        }
    }

    /**
     * RP-G4 / RP-G5 fixtures through the builder on `PX4`, judged by `AdmissionArchiveFixturesTest::assertReproduced()`.
     * `BRU24` and `BRU24-HV` are compared without `tbTakesRecount`: the independent recount reads the direct lab's admission
     * log, which a builder lab does not have (it stays evidenced on the direct lab, B1).
     */
    public function testThePx4FixturesAreReproducedThroughTheBuilder(): void
    {
        $fixtures = new FixtureReplays(static fn(): EngineLab => EngineLab::throughBuilder());
        $test = new AdmissionArchiveFixturesTest('testHarBAndHarN');
        $assert = new \ReflectionMethod(AdmissionArchiveFixturesTest::class, 'assertReproduced');
        $reproduced = static function (string $fixture, array $production) use ($assert, $test): void {
            $assert->invoke($test, $fixture, 'PX4', $production);
        };

        $reproduced('HAR-B', $fixtures->rowFixture('HAR-B'));
        $reproduced('HAR-N', $fixtures->rowFixture('HAR-N'));
        $reproduced('REC', $fixtures->rec());
        $reproduced('CROSS', $fixtures->cross());
        $reproduced('CLEAR', $fixtures->rowFixture('CLEAR'));
        $reproduced('E2', $fixtures->e2());
        $reproduced('K5U', $fixtures->k5u());
        $reproduced('STEAL', $fixtures->steal());
        $reproduced('UAX-NP', $fixtures->uaxNp());
        $reproduced('FORGE', $fixtures->forge());
        $reproduced('MINT', $fixtures->mint());
        $reproduced('LOST', $fixtures->lostToken('LOST'));
        $reproduced('TOKEN', $fixtures->lostToken('TOKEN'));
        $reproduced('UAX-ASM', $fixtures->uaxAsm());
        $reproduced('MIXPRES-ASM', $fixtures->mixpres());

        foreach (['BOOT', 'BOOT-X'] as $name) {
            /** @var array<string, mixed> $archive */
            $archive = self::invoke(AdmissionArchiveFixturesTest::class, 'archived', $name, 'PX4+R-NO-BIND');
            /** @var list<string> $fields */
            $fields = self::invoke(AdmissionArchiveFixturesTest::class, 'differences', $fixtures->boot($name), $archive);
            self::assertSame([], $fields, "{$name} / PX4 through the builder: measured difference in " . implode(', ', $fields));
        }

        foreach (['BRU24' => false, 'BRU24-HV' => true] as $name => $hv) {
            $production = $fixtures->bru($hv);
            /** @var array<string, mixed> $archive */
            $archive = self::invoke(AdmissionArchiveFixturesTest::class, 'archived', $name, 'PX4');
            unset($production['tbTakesRecount'], $archive['tbTakesRecount']);
            /** @var list<string> $fields */
            $fields = self::invoke(AdmissionArchiveFixturesTest::class, 'differences', $production, $archive);
            self::assertSame([], $fields, "{$name} / PX4 through the builder: measured difference in " . implode(', ', $fields));
        }
    }
}
