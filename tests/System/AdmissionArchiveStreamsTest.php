<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Tests\Support\Admission\Archive\ArchiveData;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\GateReport;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\StreamGates;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\StreamReplay;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;
use Maatify\RateLimiter\Tests\Support\Admission\PreSettlementReferenceStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Gates RP-G4 (streams), RP-G5 (streams), gate P, NM-10 and NM-5 of 5b3b/PLAN.md §5.2, on archived streams replayed through
 * the REAL engine (`StreamReplay`). Every archive value is read at run time from the archive's own `expected/` files; the
 * rows come from the archive's own functions through `archive-dump.php` (LT-20).
 *
 * NM-10 and NM-5 are registered as re-registered on 2026-10-20 (5b3b/PLAN.md §11.3, §11.4: the mechanism is the required permit
 * only; settlement is off by default). The arm without the permit mechanism of NM-10 (a) is the admission store exactly as merged
 * at d6aea4d: in memory `PreSettlementReferenceStore` here, and `MergedAdmissionOperations` on real Redis in
 * `AdmissionNm10RedisParityTest`. A1 excludes `UAC` as a declared cost (§11.5, `StreamGates::DECLARED_COSTS`).
 */
#[Group('archive')]
final class AdmissionArchiveStreamsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // The archive's own runner uses 1G for the same replays (run.sh, PHP_MEMORY_LIMIT); the default 128M is not enough
        // for the replay sets of this class next to the rest of the suite.
        ini_set('memory_limit', '1G');
    }

    private const FULL2 = 'g4/integrated_v2/expected/v2_streams.G4.jsonl';
    private const G5V2 = 'g5/identity_layer_v2/expected/g5v2_streams.G5V2.jsonl';

    private static function mechanism(): EngineLab
    {
        return new EngineLab(StreamReplay::fullBudgets());
    }

    private static function withoutMechanism(): EngineLab
    {
        return new EngineLab(StreamReplay::fullBudgets(), admissionInner: new PreSettlementReferenceStore(new FixedClock('@' . EngineLab::T0)));
    }

    /** @return array<string, array<string, mixed>> archive rows keyed `stream|strategy|ids|flow` */
    private static function index(string $file, ?string $arm = null): array
    {
        $out = [];
        foreach (ArchiveData::expected($file) as $row) {
            /** @var array{stream: string, strategy: string, ids: string, flow?: string, arm: string} $r */
            $r = $row;
            if (! isset($r['flow']) || ($arm !== null && $r['arm'] !== $arm)) {
                continue;
            }
            $out[$r['stream'] . '|' . $r['strategy'] . '|' . $r['ids'] . '|' . $r['flow']] = $row;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $expected
     * @return list<string> the fields that differ
     */
    private static function differences(array $result, array $expected): array
    {
        $d = [];
        foreach (['dec', 'reach', 'kinds', 'lanes', 'm'] as $field) {
            if ($result[$field] !== $expected[$field]) {
                $d[] = $field;
            }
        }

        return $d;
    }

    /**
     * @param \Closure(): EngineLab $labFor
     * @return list<string> tuples whose replay differs from the archive rows, with the differing fields
     */
    private function compare(string $file, ?string $arm, string $flow, \Closure $labFor, bool $withNone, bool $hostCounts = false): array
    {
        $expected = self::index($file, $arm);
        $diff = [];
        foreach (ArchiveData::get()->streams() as $s) {
            if (($s['ids'] === 'none') !== $withNone && ! ($withNone && $s['ids'] !== 'none')) {
                continue;
            }
            $key = $s['stream'] . '|' . $s['strategy'] . '|' . $s['ids'] . '|' . $flow;
            if (! isset($expected[$key])) {
                continue;
            }
            $result = StreamReplay::run($s['rows'], $flow, $labFor());
            $fields = self::differences($result, $expected[$key]);
            if ($hostCounts && ($result['issued'] !== $expected[$key]['issued'] || $result['tainted'] !== $expected[$key]['tainted'])) {
                $fields[] = 'host';
            }
            if ($fields !== []) {
                $diff[] = $key . ': ' . implode(',', $fields);
            }
        }

        return $diff;
    }

    // ---- harness validity (LT-20 condition 3) ------------------------------------------------------------------------------

    public function testTheDumpCountsEqualTheArchiveCounts(): void
    {
        $data = ArchiveData::get();
        $expected = self::index(self::FULL2, 'FULL2');
        $checked = 0;
        foreach ($data->streams() as $s) {
            if ($s['ids'] === 'none') {
                continue;
            }
            $key = $s['stream'] . '|' . $s['strategy'] . '|' . $s['ids'] . '|pre+out';
            self::assertArrayHasKey($key, $expected, $key);
            $nonEvents = count(array_filter($s['rows'], static fn(array $r): bool => ! isset($r['effects'])));
            /** @var string $dec */
            $dec = $expected[$key]['dec'];
            self::assertSame(strlen($dec), $nonEvents, "row count of $key");
            $checked++;
        }
        self::assertSame(57, $checked);
        $g5Tuples = count(array_filter(array_keys(self::index(self::G5V2)), static fn(string $k): bool => str_ends_with($k, '|pre+out')));
        self::assertSame($g5Tuples, $data->manifest['streams.jsonl']['lines'], 'tuples dumped = the tuples of the G5 v2 archive (the 57 of G4 v2 plus the "none" variants)');
        self::assertSame(2, $data->manifest['bru.jsonl']['lines']);
        foreach ($data->manifest as $file => $info) {
            self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $info['sha256'], $file);
        }
    }

    // ---- RP-G4 (streams) -----------------------------------------------------------------------------------------------------

    public function testRpG4Full2StreamRowsReproduceTheArchiveExceptTheDeclaredUacCost(): void
    {
        $diff = $this->compare(self::FULL2, 'FULL2', 'pre+out', self::mechanism(...), false);

        // UAC: the K5 of an opted policy is on the client instance id (G5 v2), so a browser update is the same device and the
        // owner's UAC denials are 9 and not 6 (G5 v2 archive, DEC-026 draft Delta item 4). Everything else is field for field.
        self::assertSame(['UAC|none|fresh|pre+out: dec,reach,m'], $diff);
        $g5 = self::index(self::G5V2)['UAC|none|fresh|pre+out'];
        $uac = null;
        foreach (ArchiveData::get()->streams() as $s) {
            if ($s['stream'] === 'UAC' && $s['ids'] === 'fresh') {
                $uac = StreamReplay::run($s['rows'], 'pre+out', self::mechanism());
            }
        }
        self::assertNotNull($uac);
        self::assertSame($g5['dec'], $uac['dec'], 'the production UAC row equals the G5 v2 archive row');
        self::assertSame(9, $uac['m']['legitDenied']);
    }

    /** A1 (with `UAC` the declared cost, PLAN §11.5), A2 and A3 on the FULL2 streams; `UAC` is the G5 v2 value 9. */
    public function testRpG4GatesA1A2A3HoldWithUacTheDeclaredCost(): void
    {
        $best = StreamGates::best(StreamGates::replayAll(self::mechanism(...), 'pre+out'));

        self::assertTrue(StreamGates::a2($best));
        self::assertSame([], StreamGates::a3($best));
        self::assertSame([], StreamGates::a1($best));
        self::assertSame(9, $best['UAC']['legit'], 'UAC = the G5 v2 value (summary.md L79, L193: 6 -> 9)');
        $reach = array_intersect_key(array_map(static fn(array $c): int => $c['reach'], $best), array_flip(StreamGates::SPRAY));
        ksort($reach);
        self::assertSame(['ABA_A' => 8, 'SP1n' => 4, 'SP3' => 8, 'SP6' => 24], $reach);
        self::assertSame(54, $best['MIX']['legit']);
    }

    // ---- RP-G5 (streams, pre+out) ----------------------------------------------------------------------------------------------

    public function testRpG5EveryNoua2StreamRowOfG5V2IsReproducedFieldForFieldWithTheHostCounts(): void
    {
        self::assertSame([], $this->compare(self::G5V2, 'NOUA2', 'pre+out', self::mechanism(...), true, true));
    }

    // ---- gate P (reported) and the mechanism-less arm --------------------------------------------------------------------------

    public function testTheArmWithoutTheMechanismReproducesEveryArchivedPreRow(): void
    {
        // The pre flow of FULL2 (and of G5 v2): without settlement nothing records the dropped outcomes, so the archived rows return.
        self::assertSame([], $this->compare(self::G5V2, 'NOUA2', 'pre', self::withoutMechanism(...), true, true));
    }

    /**
     * Gate P (reported; replaces the settlement version, PLAN §11.8): at the store default the production `pre` rows are the
     * archived `pre` rows (a `checkOnly`-only Host changes no bound), with the reference values L1 72 and D2 61.
     */
    public function testGatePReportedTheProductionPreRowsAreTheArchivedPreRows(): void
    {
        self::assertSame([], $this->compare(self::G5V2, 'NOUA2', 'pre', self::mechanism(...), true, true));
        $best = StreamGates::best(StreamGates::replayAll(self::mechanism(...), 'pre'));
        GateReport::emit('gate-p', ['L1' => $best['L1']['legit'], 'D2' => $best['D2']['legit'], 'SP6' => $best['SP6']['reach']]);
        self::assertSame(72, $best['L1']['legit']);
        self::assertSame(61, $best['D2']['legit']);
    }

    // ---- NM-10 (re-registered 2026-10-20, PLAN §11.3) --------------------------------------------------------------------------

    /** @return iterable<string, array{float}> */
    public static function dropShares(): iterable
    {
        foreach ([0.10, 0.25, 0.50, 1.00] as $share) {
            yield 'share ' . $share => [$share];
        }
    }

    /**
     * NM-10 (a), in memory: the arm with the permit (the production store at its default) and the arm without it (the store as
     * merged at d6aea4d) give the same replay row by row for every archived stream, ids and no ids, at the share.
     */
    #[DataProvider('dropShares')]
    #[Group('nm10')]
    public function testNm10aTheTwoArmsAreIdenticalRowByRowInMemory(float $share): void
    {
        $diff = [];
        $tuples = 0;
        foreach (ArchiveData::get()->streams() as $s) {
            $with = StreamReplay::run($s['rows'], 'pre+out', self::mechanism(), $share);
            $without = StreamReplay::run($s['rows'], 'pre+out', self::withoutMechanism(), $share);
            if ($with !== $without) {
                $diff[] = $s['stream'] . '|' . $s['strategy'] . '|' . $s['ids'];
            }
            $tuples++;
        }

        self::assertSame(91, $tuples);
        self::assertSame([], $diff, "rows that differ between the arms at share $share");
    }

    /** NM-10, reported at every share (not a gate): A1 (without `UAC`) and A3, the characterisation of the A-2 remainder. */
    #[DataProvider('dropShares')]
    #[Group('nm10')]
    public function testNm10ReportedA1AndA3AtTheShare(float $share): void
    {
        $best = StreamGates::best(StreamGates::replayAll(self::mechanism(...), 'pre+out', $share));
        $a1 = StreamGates::a1($best);
        $a3 = StreamGates::a3($best);
        GateReport::emit('nm10-a1-a3', ['share' => $share, 'A1' => $a1, 'A3' => $a3]);

        self::assertCount(count(StreamGates::BENIGN) + count(StreamGates::SPRAY) + 1, array_intersect_key($best, array_flip([...StreamGates::BENIGN, ...StreamGates::SPRAY, 'MIX'])));
    }

    /**
     * NM-10, reported: a Host that drops every outcome is the `pre` flow (the archived FULL2 `pre` reach, A3 fails), on both
     * arms. It is the A-2 remainder the USAGE_GUIDE duty names, not a gate. (Was the settlement-era detector 1; renamed, §11.8.)
     */
    #[Group('nm10')]
    public function testNm10ReportedAtShareOneADroppingHostIsThePreFlowOnBothArms(): void
    {
        foreach ([self::mechanism(...), self::withoutMechanism(...)] as $labFor) {
            $best = StreamGates::best(StreamGates::replayAll($labFor, 'pre+out', 1.0));
            $reach = array_intersect_key(array_map(static fn(array $c): int => $c['reach'], $best), array_flip(StreamGates::SPRAY));
            ksort($reach);
            self::assertSame(['ABA_A' => 48, 'SP1n' => 24, 'SP3' => 48, 'SP6' => 144], $reach);
            self::assertNotSame([], StreamGates::a3($best));
        }
    }

    #[Group('nm10')]
    public function testNm10B1Bru24HoldsAtEveryShareAndEqualsTheIndependentRecount(): void
    {
        foreach ([0.0, 0.10, 0.25, 0.50, 1.00] as $share) {
            $lab = self::mechanism();
            $r = StreamReplay::run(ArchiveData::get()->bru(false), 'pre+out', $lab, $share);
            $takes = count(array_filter($lab->admissionStore->admitted, static function ($request): bool {
                foreach ($request->scopes as $scope) {
                    if ($scope->kind === \Maatify\RateLimiter\Enum\AdmissionScopeKindEnum::BUCKET) {
                        return true;
                    }
                }

                return false;
            }));
            $admitted = substr_count($r['reach'], '1');

            self::assertLessThanOrEqual(52, $admitted, "BRU24 at share $share");
            self::assertSame($takes, $admitted, "independent recount at share $share");
        }
        $reference = StreamReplay::run(ArchiveData::get()->bru(false), 'pre+out', self::mechanism());
        self::assertSame(51, substr_count($reference['reach'], '1'));
        self::assertSame(['account_bucket' => 5709], $reference['attackCauses']);
    }

    // ---- NM-5 -----------------------------------------------------------------------------------------------------------------------

    /**
     * The worst (best-response) legit refusals of each benign stream and the causes of that replay.
     *
     * @param list<array{stream: string, strategy: string, ids: string, result: array{dec: string, reach: string, kinds: string, lanes: string, m: array<string, int>, issued: int, tainted: int, causes: array<string, int>, attackCauses: array<string, int>}}> $replays
     * @return array<string, array{legit: int, causes: array<string, int>}>
     */
    private static function worstPerBenignStream(array $replays): array
    {
        $out = [];
        foreach ($replays as $r) {
            if (! in_array($r['stream'], StreamGates::BENIGN, true)) {
                continue;
            }
            $legit = $r['result']['m']['legitDenied'];
            if ($legit >= ($out[$r['stream']]['legit'] ?? -1)) {
                $out[$r['stream']] = ['legit' => $legit, 'causes' => $r['result']['causes']];
            }
        }
        ksort($out);

        return $out;
    }

    /** @return iterable<string, array{int, float}> */
    public static function probeCombinations(): iterable
    {
        yield 'rho 2' => [2, 0.0];
        yield 'rho 3' => [3, 0.0];
        yield 'abandon 0.10' => [1, 0.10];
        yield 'abandon 0.25' => [1, 0.25];
        yield 'rho 3 and abandon 0.25' => [3, 0.25];
    }

    /**
     * NM-5 (re-registered, PLAN §11.4), reported per benign stream: the legit refusals by cause at the combination and their
     * difference from `rho` 1, abandonment 0. A probe or an abandoned admission is a reservation no success refunds (the bucket
     * and the counters); no A1 condition (`rho` > 1 is outside C-c7).
     */
    #[DataProvider('probeCombinations')]
    #[Group('nm5')]
    public function testNm5ReportedTheEffectOfTheUnrefundedReservationsPerBenignStream(int $rho, float $abandon): void
    {
        $base = StreamGates::replayAll(self::mechanism(...), 'pre+out');
        $run = StreamGates::replayAll(self::mechanism(...), 'pre+out', 0.0, $rho, $abandon);
        $one = self::worstPerBenignStream($base);
        $now = self::worstPerBenignStream($run);
        $report = [];
        foreach ($now as $stream => $v) {
            $report[$stream] = ['legit' => $v['legit'], 'delta' => $v['legit'] - ($one[$stream]['legit'] ?? 0), 'causes' => $v['causes']];
        }
        GateReport::emit('nm5', ['rho' => $rho, 'abandon' => $abandon, 'streams' => $report]);

        self::assertSame(StreamGates::BENIGN, array_values(array_intersect(StreamGates::BENIGN, array_keys($report))), 'every benign stream is reported');
    }

    #[Group('nm5')]
    public function testNm5HarnessValidityRhoOneNoAbandonmentIsTheRpG4Values(): void
    {
        $base = StreamGates::best(StreamGates::replayAll(self::mechanism(...), 'pre+out', 0.0, 1, 0.0));
        $rows = $this->compare(self::FULL2, 'FULL2', 'pre+out', self::mechanism(...), false);

        self::assertSame(['UAC|none|fresh|pre+out: dec,reach,m'], $rows);
        self::assertSame(54, $base['MIX']['legit']);
    }

    #[Group('nm5')]
    public function testNm5DetectorTheHighestRhoRefusesABenignStreamMoreThanRhoOne(): void
    {
        $one = StreamGates::best(StreamGates::replayAll(self::mechanism(...), 'pre+out', 0.0, 1));
        $three = StreamGates::best(StreamGates::replayAll(self::mechanism(...), 'pre+out', 0.0, 3));
        $more = array_filter(StreamGates::BENIGN, static fn(string $s): bool => $three[$s]['legit'] > $one[$s]['legit']);

        self::assertNotSame([], $more, 'DETECTOR: at rho 3 at least one benign stream is refused more than at rho 1');
    }
}
