<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Tests\Support\Admission\Archive\ArchiveData;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\FixtureReplays;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\GateReport;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\InputViolation;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\NpAudit;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\ReplaySession;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\StreamReplay;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;
use Maatify\RateLimiter\Tests\Support\Admission\PipelineMutant;
use Maatify\RateLimiter\Tests\Support\Admission\ServiceMutant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The fixture gates of LT-23 (5b3b/PLAN.md §10.4, ruled with four conditions, §11.6): RP-G4 fixtures (HAR-B / HAR-N, BRU24, REC,
 * CROSS, CLEAR, E2, K5U, BOOT / BOOT-X, STEAL), RP-G5 fixtures (UAX-NP, FORGE, MINT, LOST / TOKEN, UAX-ASM, MIXPRES-ASM, NP-IN,
 * the D-NPID detector), and RP-G7 X4 for the same fixtures on the arm with the cross-account cap. Every fixture is replayed
 * through the REAL engine (`FixtureReplays`), its rows come from the archive's own functions or a cited transcription
 * (`archive-dump.php`), IN runs before every replay, and the reference is the archived row of the same fixture and arm
 * (`g7/cross_account_p/expected/g7_fixtures.G5V2.jsonl`: `NOUA2` = the FULL2 budgets without the cross-account cap, `PX4` = the
 * production budgets with it). Equal field for field = reproduced; a difference is reported with its fields and fails the gate.
 */
#[Group('archive')]
#[Group('lt23')]
final class AdmissionArchiveFixturesTest extends TestCase
{
    private const G7FX = 'g7/cross_account_p/expected/g7_fixtures.G5V2.jsonl';

    public static function setUpBeforeClass(): void
    {
        ini_set('memory_limit', '1G');
    }

    /** @return array<string, mixed> the archived `fx` row of a fixture and arm */
    private static function archived(string $fixture, string $arm, string $file = self::G7FX): array
    {
        foreach (ArchiveData::expected($file) as $row) {
            if (($row['fixture'] ?? null) === $fixture && ($row['arm'] ?? null) === $arm) {
                return $row;
            }
        }

        throw new \OutOfBoundsException("No archived row {$fixture} / {$arm} in {$file}.");
    }

    /** The arm of the archive and the lab that reproduces it. */
    private static function lab(string $arm): EngineLab
    {
        return match ($arm) {
            'NOUA2' => new EngineLab(StreamReplay::fullBudgets()),
            'PX4' => new EngineLab(EngineLab::budgets()),
            default => throw new \LogicException("Unknown arm {$arm}."),
        };
    }

    private static function replays(string $arm, ?\Closure $observer = null): FixtureReplays
    {
        return new FixtureReplays(static fn(): EngineLab => self::lab($arm), $observer);
    }

    /**
     * Normalise a row for the comparison: causes in the engine's grouping (`FixtureReplays::archiveCauses`), and of the causes
     * only the capacity part compared by name; denials without a capacity cause are compared by count (the engine's penalty
     * results carry no study `pen:*` label).
     *
     * @param array<array-key, mixed> $row
     * @return array<array-key, mixed>
     */
    private static function normalise(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if (in_array($key, ['s', 'fixture', 'arm', 'k5KeyA', 'k5KeyB', 'keysDiffer'], true)) {
                continue;
            }
            if (is_array($value) && (str_ends_with((string) $key, 'ByCause') || in_array($key, ['causes', 'fullCauses', 'noBindCauses'], true))) {
                /** @var array<string, int> $value */
                $out[$key] = FixtureReplays::split(FixtureReplays::archiveCauses($value));
                continue;
            }
            $out[$key] = is_array($value) ? self::normalise($value) : $value;
        }
        ksort($out);

        return $out;
    }

    /**
     * @param array<string, mixed> $production
     * @param array<string, mixed> $archive
     * @return list<string> the top-level fields that differ
     */
    private static function differences(array $production, array $archive): array
    {
        $p = self::normalise($production);
        $a = self::normalise($archive);
        $fields = [];
        foreach (array_unique(array_merge(array_keys($p), array_keys($a))) as $field) {
            if (($p[$field] ?? null) !== ($a[$field] ?? null)) {
                $fields[] = (string) $field;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $production
     */
    private function assertReproduced(string $fixture, string $arm, array $production): void
    {
        $archive = self::archived($fixture, $arm);
        $fields = self::differences($production, $archive);
        self::assertSame([], $fields, "{$fixture} / {$arm}: measured difference in " . implode(', ', $fields)
            . "\nproduction " . json_encode(self::normalise($production)) . "\narchive    " . json_encode(self::normalise($archive)));
    }

    /** @return iterable<string, array{string}> */
    public static function arms(): iterable
    {
        yield 'NOUA2 (FULL2 budgets)' => ['NOUA2'];
        yield 'PX4 (production budgets)' => ['PX4'];
    }

    // ---- harness validity ------------------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $row */
    private static function int(array $row, string ...$path): int
    {
        $v = $row;
        foreach ($path as $key) {
            $v = is_array($v) ? ($v[$key] ?? null) : null;
        }

        return is_int($v) ? $v : throw new \UnexpectedValueException('No archived integer at ' . implode('.', $path) . '.');
    }

    public function testTheDumpCountsOfTheFixturesAndTheG7Streams(): void
    {
        $data = ArchiveData::get();
        GateReport::emit('dump-manifest', $data->manifest);
        self::assertSame(14, $data->manifest['g7streams.jsonl']['lines'], 'N12T, TPAIRT, TMS, TMSd, TMX by strategy and id policy');
        self::assertSame(25, $data->manifest['fixtures.jsonl']['lines']);
        $plain = static fn(string $n): int => count(array_filter($data->fixtureRows($n), static fn(array $r): bool => ! isset($r['effects'])));
        // Row counts the archive states (attempts, legitimate requests), read from the archived rows where it records them.
        self::assertSame(self::int(self::archived('FORGE', 'NOUA2'), 'attempts'), $plain('FORGE'));
        self::assertSame(self::int(self::archived('MINT', 'NOUA2'), 'attempts'), $plain('MINT'));
        $har = self::archived('HAR-B', 'NOUA2');
        self::assertSame(self::int($har, 'm', 'attackAttempts') + self::int($har, 'm', 'legitAttempts'), $plain('HAR-B'));
        $boot = self::archived('BOOT', 'NOUA2+R-NO-BIND');
        $legit = 0;
        foreach ([0, 1, 2] as $d) {
            $legit += self::int($boot, 'perDay', 'FULL2', (string) $d, 'n');
        }
        self::assertSame($legit, count(array_filter($data->fixtureRows('BOOT'), static fn(array $r): bool => ($r['kind'] ?? '') === 'L')));
    }

    /** @return iterable<string, array{string}> */
    public static function fixtureFamilies(): iterable
    {
        foreach (['HAR-B', 'REC', 'CROSS', 'CLEAR', 'E2:pop', 'K5U:fail', 'BOOT-X', 'UAX-NP', 'FORGE', 'MINT', 'TOKEN:600', 'UAX-ASM:flip', 'MIXPRES-ASM:both'] as $f) {
            yield $f => [$f];
        }
        yield 'STEAL' => ['STEAL'];
    }

    /** IN on every fixture family as dumped, and D-IN: the same family with the instance id reverted out of the context is rejected. */
    #[DataProvider('fixtureFamilies')]
    public function testInAcceptsEveryFixtureAndDInRejectsItsRevertedFamily(string $name): void
    {
        $data = ArchiveData::get();
        $parts = $name === 'STEAL' ? [$data->fixtureRows('STEAL', 'pre'), $data->fixtureRows('STEAL', 'after')] : [$data->fixtureRows($name)];
        $declared = 0;
        foreach ($parts as $rows) {
            ReplaySession::assertInput($rows);
            $declared += count(array_filter($rows, static fn(array $r): bool => ($r['inst'] ?? null) !== null));
        }
        self::assertGreaterThan(0, $declared, "{$name} declares instance ids");
        // D-IN: revert the context to the source expression (the F131-01 defect: the id never reaches the DTO).
        $reverted = [];
        foreach ($parts[0] as $r) {
            if (isset($r['ctx'])) {
                $r['ctx']['sessionDeviceId'] = null;
            }
            $reverted[] = $r;
        }
        try {
            ReplaySession::assertInput($reverted);
            self::fail("D-IN: the reverted {$name} family was accepted");
        } catch (InputViolation) {
            $this->addToAssertionCount(1);
        }
    }

    // ---- RP-G4 / RP-G7 X4 fixtures ---------------------------------------------------------------------------------------------

    #[DataProvider('arms')]
    #[Group('rp-g4')]
    public function testHarBAndHarN(string $arm): void
    {
        $this->assertReproduced('HAR-B', $arm, self::replays($arm)->rowFixture('HAR-B'));
        $this->assertReproduced('HAR-N', $arm, self::replays($arm)->rowFixture('HAR-N'));
    }

    #[DataProvider('arms')]
    #[Group('rp-g4')]
    public function testBru24AndBru24Hv(string $arm): void
    {
        $this->assertReproduced('BRU24', $arm, self::replays($arm)->bru(false));
        $this->assertReproduced('BRU24-HV', $arm, self::replays($arm)->bru(true));
    }

    #[DataProvider('arms')]
    #[Group('rp-g4')]
    public function testRec(string $arm): void
    {
        $this->assertReproduced('REC', $arm, self::replays($arm)->rec());
    }

    #[DataProvider('arms')]
    #[Group('rp-g4')]
    public function testCross(string $arm): void
    {
        $this->assertReproduced('CROSS', $arm, self::replays($arm)->cross());
    }

    #[DataProvider('arms')]
    #[Group('rp-g4')]
    public function testClear(string $arm): void
    {
        $this->assertReproduced('CLEAR', $arm, self::replays($arm)->rowFixture('CLEAR'));
    }

    #[DataProvider('arms')]
    #[Group('rp-g4')]
    public function testE2(string $arm): void
    {
        $this->assertReproduced('E2', $arm, self::replays($arm)->e2());
    }

    #[DataProvider('arms')]
    #[Group('rp-g4')]
    public function testK5u(string $arm): void
    {
        $this->assertReproduced('K5U', $arm, self::replays($arm)->k5u());
    }

    #[DataProvider('arms')]
    #[Group('rp-g4')]
    public function testBootAndBootX(string $arm): void
    {
        foreach (['BOOT', 'BOOT-X'] as $name) {
            $production = self::replays($arm)->boot($name);
            $archive = self::archived($name, $arm . '+R-NO-BIND');
            // The production NO-BIND arm is the engine with a Host that never binds; the archived one is the study's R-NO-BIND
            // cell (Part R). The comparison is the archived row's; a difference of the NO-BIND side is reported by its fields.
            $fields = self::differences($production, $archive);
            self::assertSame([], $fields, "{$name} / {$arm}: measured difference in " . implode(', ', $fields)
                . "\nproduction " . json_encode(self::normalise($production)) . "\narchive    " . json_encode(self::normalise($archive)));
        }
    }

    #[DataProvider('arms')]
    #[Group('rp-g4')]
    #[Group('rp-g5')]
    public function testSteal(string $arm): void
    {
        $this->assertReproduced('STEAL', $arm, self::replays($arm)->steal());
    }

    // ---- RP-G5 fixtures ----------------------------------------------------------------------------------------------------------

    #[DataProvider('arms')]
    #[Group('rp-g5')]
    public function testUaxNp(string $arm): void
    {
        $this->assertReproduced('UAX-NP', $arm, self::replays($arm)->uaxNp());
    }

    #[DataProvider('arms')]
    #[Group('rp-g5')]
    public function testForgeAndMint(string $arm): void
    {
        $this->assertReproduced('FORGE', $arm, self::replays($arm)->forge());
        $this->assertReproduced('MINT', $arm, self::replays($arm)->mint());
    }

    #[DataProvider('arms')]
    #[Group('rp-g5')]
    public function testLostAndToken(string $arm): void
    {
        $this->assertReproduced('LOST', $arm, self::replays($arm)->lostToken('LOST'));
        $this->assertReproduced('TOKEN', $arm, self::replays($arm)->lostToken('TOKEN'));
    }

    #[DataProvider('arms')]
    #[Group('rp-g5')]
    public function testUaxAsmAndMixpresAsm(string $arm): void
    {
        $this->assertReproduced('UAX-ASM', $arm, self::replays($arm)->uaxAsm());
        $this->assertReproduced('MIXPRES-ASM', $arm, self::replays($arm)->mixpres());
    }

    /** NP-IN: every audited P admission of the fixtures and of STEAL carries the fingerprint member and none the id member. */
    #[Group('rp-g5')]
    public function testNpInEveryProvenAdmissionCarriesTheFingerprintMember(): void
    {
        foreach (['FIXTURES', 'STEAL'] as $scenario) {
            $audit = new NpAudit();
            $r = self::replays('NOUA2', $audit->observer());
            if ($scenario === 'STEAL') {
                $r->steal();
            } else {
                foreach (['HAR-B', 'HAR-N', 'CLEAR'] as $f) {
                    $r->rowFixture($f);
                }
                $r->bru(false);
                $r->bru(true);
                $r->rec();
                $r->cross();
                $r->e2();
                $r->k5u();
                $r->boot('BOOT');
                $r->boot('BOOT-X');
                $r->uaxNp();
                $r->forge();
                $r->mint();
                $r->lostToken('LOST');
                $r->lostToken('TOKEN');
                $r->uaxAsm();
                $r->mixpres();
            }
            $archived = null;
            foreach (ArchiveData::expected(self::G7FX) as $row) {
                if (($row['s'] ?? null) === 'np' && $row['arm'] === 'NOUA2' && $row['scenario'] === $scenario) {
                    $archived = $row;
                }
            }
            self::assertNotNull($archived);
            self::assertSame(0, $audit->withoutMember, "{$scenario}: a P admission without an N_P member");
            self::assertSame($audit->pAdmitted, $audit->memberIsFingerprint, "{$scenario}: every P admission carries the fingerprint member");
            self::assertSame(0, $audit->memberIsIdComponent, "{$scenario}: no P admission carries the id member");
            self::assertSame(
                ['pAdmitted' => $archived['pAdmitted'], 'memberIsFingerprint' => $archived['memberIsFingerprint'], 'memberIsIdComponent' => $archived['memberIsIdComponent']],
                ['pAdmitted' => $audit->pAdmitted, 'memberIsFingerprint' => $audit->memberIsFingerprint, 'memberIsIdComponent' => $audit->memberIsIdComponent],
                "{$scenario}: the audited counts equal the archived NP-IN row",
            );
        }
    }

    /**
     * RP-G5 detector D-NPID: the `N_P` member keyed on the instance id (a mutation of the registered seam `deviceMember`) gives
     * the archived D-NPID values: STEAL thief 20 P and owner wait 1,260 s, UAX-NP 0 refusals.
     */
    #[Group('rp-g5')]
    public function testDetectorNpOnTheIdGivesTheArchivedDNpidValues(): void
    {
        $runtime = ServiceMutant::load('AdmissionRuntime', [], [[
            "\$this->key(\$group, 'dev', (string) \$device->fingerprintHash, ''),\n            \$previousMember,",
            "\$this->key(\$group, 'dev', (string) (\$device->deviceIdHash ?? \$device->fingerprintHash), ''),\n            \$previousMember,",
        ]]);
        $r = new FixtureReplays(static fn(): EngineLab => new EngineLab(StreamReplay::fullBudgets(), runtimeClass: $runtime));
        $steal = $r->steal();
        $np = $r->uaxNp();
        $archivedSteal = self::archived('STEAL', 'D-NPID', 'g5/identity_layer_v2/expected/g5v2_costs.G5V2.jsonl');
        $archivedNp = self::archived('UAX-NP', 'D-NPID', 'g5/identity_layer_v2/expected/g5v2_costs.G5V2.jsonl');

        self::assertSame($archivedSteal['thiefPBeforeChange'], $steal['thiefPBeforeChange'], 'DETECTOR: thief P before the change (archived 20)');
        self::assertSame($archivedSteal['ownerWaitAfterChange'], $steal['ownerWaitAfterChange'], 'DETECTOR: owner wait (archived 1,260 s)');
        self::assertSame($archivedNp['refused'], $np['refused'], 'DETECTOR: UAX-NP refusals (archived 0)');
    }

    /** RP-G5: the archived D-IDMINT value (the G2 detector) is shown, read from the archive, not reproduced. */
    #[Group('rp-g5')]
    public function testTheArchivedDIdmintValueIsShown(): void
    {
        $row = null;
        foreach (ArchiveData::expected('g5/identity_layer_v2/expected/g5v2_fixtures.G5V2.jsonl') as $r) {
            if (($r['fixture'] ?? null) === 'BRU24' && $r['arm'] === 'D-IDMINT') {
                $row = $r;
            }
        }
        self::assertNotNull($row);
        self::assertSame(5760, $row['attackAdmitted']);
        self::assertSame(51, self::replays('NOUA2')->bru(false)['attackAdmitted'], 'the production BRU24 value against the archived detector value');
    }

    // ---- RP-G4 arm detectors (the four production switches of PLAN §5.2) --------------------------------------------------------

    /** No P refund (the registered seam `proofRefund` returns no scope): the proven owner is refused like a `pre` Host (L1 72). */
    #[Group('rp-g4')]
    public function testDetectorNoProvenRefundRefusesTheL1Owner(): void
    {
        $runtime = ServiceMutant::load('AdmissionRuntime', ['proofRefund' => 'return [];']);
        $best = 0;
        foreach (ArchiveData::get()->streams() as $s) {
            if ($s['stream'] === 'L1' && $s['ids'] !== 'none') {
                $best = max($best, StreamReplay::run($s['rows'], 'pre+out', new EngineLab(StreamReplay::fullBudgets(), runtimeClass: $runtime))['m']['legitDenied']);
            }
        }
        self::assertSame(72, $best, 'DETECTOR: without the proven refund L1 is refused as in the archived pre flow');
    }

    /** No renewal (the Host model without sliding renewal): E2 diverges from the archived row. */
    #[Group('rp-g4')]
    public function testDetectorNoRenewalMakesE2Diverge(): void
    {
        $e2 = self::replays('NOUA2')->e2(renew: false);
        self::assertNotSame([], self::differences($e2, self::archived('E2', 'NOUA2')), 'DETECTOR: E2 without renewal differs');
    }

    /** No password-change binding: the owner is never admitted after the change and is refused by the account bucket. */
    #[Group('rp-g4')]
    public function testDetectorNoPasswordBindingLeavesTheStealOwnerOut(): void
    {
        $steal = self::replays('NOUA2')->steal(passwordBinding: false);
        self::assertNull($steal['ownerWaitAfterChange'], 'DETECTOR: the owner is never admitted');
        self::assertSame(['cap:acct_tb' => 719], $steal['ownerDeniedAfterChangeByCause']);
    }

    /** No K5 separation (the 5-b3a seam `k5KeyType` returns `k5` on P): K5U diverges from the archived row. */
    #[Group('rp-g4')]
    public function testDetectorNoK5SeparationMakesK5uDiverge(): void
    {
        $pipeline = PipelineMutant::pipeline(['k5KeyType' => "return 'k5';"]);
        $k5u = (new FixtureReplays(static fn(): EngineLab => new EngineLab(StreamReplay::fullBudgets(), pipelineClass: $pipeline)))->k5u();
        self::assertNotSame([], self::differences($k5u, self::archived('K5U', 'NOUA2')), 'DETECTOR: K5U without the K5 separation differs');
    }
}
