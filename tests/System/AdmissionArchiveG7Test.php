<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Tests\Support\Admission\Archive\ArchiveData;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\FixtureReplays;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\ReplaySession;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\StreamReplay;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;
use Maatify\RateLimiter\Tests\Support\Admission\ServiceMutant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type Tuple from ArchiveData
 * @phpstan-type Replay array{dec: string, reach: string, kinds: string, lanes: string, m: array<string, int>, issued: int, tainted: int, legitDeniedByCause: array<string, int>, attackDeniedByCause: array<string, int>, refusedPx: int, reachU: int, reachP: int, reachFirst600: int, reachPerAccountMax: int, accountsReached: int}
 * @phpstan-type Archived array{stream: string, strategy: string, ids: string, flow: string, dec: string, reach: string, kinds: string, lanes: string, m: array<string, int>, issued: int, tainted: int, legitDeniedByCause: array<array-key, mixed>, attackDeniedByCause: array<array-key, mixed>, refusedPx?: int, reachU?: int, reachP?: int, reachFirst600?: int, reachPerAccountMax?: int, accountsReached?: int}
 *
 * Gates RP-G6 and RP-G7 (5b3b/PLAN.md §5.2) on the G7 archive (`g7/cross_account_p/expected/g7_streams.G5V2.jsonl`): the 22 G3
 * streams and the office / multi-session cells `N12T`, `TPAIRT`, `TMS`, `TMSd`, `TMX`, every strategy and id policy (and the
 * no-id path), flows `pre+out` and `pre`, replayed through the REAL engine on the arm without the cross-account cap (`NOUA2`, the
 * FULL2 budgets) and on the production budgets with it (`PX4`). Every row is compared field for field with its archived row; the
 * gates are the archive's X1, X3, X4 and the RP-G6 reference values; the detector `D-PXALL` is a mutation of the registered seam
 * `entersCrossAccountSet` (every admitted P outcome enters D8).
 */
#[Group('archive')]
final class AdmissionArchiveG7Test extends TestCase
{
    private const G7 = 'g7/cross_account_p/expected/g7_streams.G5V2.jsonl';
    /** @var array<string, array<string, Replay>> arm => key => replay result */
    private static array $replays = [];

    public static function setUpBeforeClass(): void
    {
        ini_set('memory_limit', '1G');
    }

    /** @param class-string|null $runtimeClass */
    private static function lab(string $arm, ?string $runtimeClass = null): EngineLab
    {
        $budgets = $arm === 'NOUA2' ? StreamReplay::fullBudgets() : EngineLab::budgets();

        return $runtimeClass === null ? new EngineLab($budgets) : new EngineLab($budgets, runtimeClass: $runtimeClass);
    }

    /** @return \Generator<int, Tuple> */
    private static function tuples(): \Generator
    {
        yield from ArchiveData::get()->streams();
        yield from ArchiveData::get()->g7Streams();
    }

    /**
     * One replay with the archive's extra measures (`g6TmsRow`, `refusedPx`).
     *
     * @param Tuple $tuple
     * @return Replay
     */
    private static function replay(array $tuple, string $flow, EngineLab $lab): array
    {
        $rows = $tuple['rows'];
        $s = new ReplaySession($lab, $flow);
        $s->replay($rows);
        $r = $s->result();
        $plain = array_values(array_filter($rows, static fn(array $x): bool => ! isset($x['effects'])));
        $byLane = ['U' => 0, 'P' => 0];
        $first600 = 0;
        $acct = [];
        for ($i = 0, $n = strlen($r['reach']); $i < $n; $i++) {
            if ($r['reach'][$i] !== '1') {
                continue;
            }
            $byLane[$r['lanes'][$i]]++;
            $first600 += $plain[$i]['t'] < 600 ? 1 : 0;
            $a = $plain[$i]['ctx']['accountId'] ?? '';
            $acct[$a] = ($acct[$a] ?? 0) + 1;
        }
        $legit = [];
        $attack = [];
        foreach ($r['causes'] as $c => $k) {
            $legit[FixtureReplays::causeCode($c)] = ($legit[FixtureReplays::causeCode($c)] ?? 0) + $k;
        }
        foreach ($r['attackCauses'] as $c => $k) {
            $attack[FixtureReplays::causeCode($c)] = ($attack[FixtureReplays::causeCode($c)] ?? 0) + $k;
        }
        unset($s);

        return ['dec' => $r['dec'], 'reach' => $r['reach'], 'kinds' => $r['kinds'], 'lanes' => $r['lanes'], 'm' => $r['m'],
            'issued' => $r['issued'], 'tainted' => $r['tainted'], 'legitDeniedByCause' => $legit, 'attackDeniedByCause' => $attack,
            'refusedPx' => ($legit['cap:pfail_src'] ?? 0) + ($attack['cap:pfail_src'] ?? 0),
            // g6TmsRow (g6/budgets/lib/g6.php:345-362)
            'reachU' => $byLane['U'], 'reachP' => $byLane['P'], 'reachFirst600' => $first600,
            'reachPerAccountMax' => $acct === [] ? 0 : max($acct), 'accountsReached' => count($acct)];
    }

    /** @return array<string, Replay> key `stream|strategy|ids|flow` => replay, cached per arm */
    private static function all(string $arm): array
    {
        if (! isset(self::$replays[$arm])) {
            $out = [];
            foreach (self::tuples() as $t) {
                foreach (['pre+out', 'pre'] as $flow) {
                    $out[$t['stream'] . '|' . $t['strategy'] . '|' . $t['ids'] . '|' . $flow] = self::replay($t, $flow, self::lab($arm));
                    gc_collect_cycles();
                }
            }
            self::$replays[$arm] = $out;
        }

        return self::$replays[$arm];
    }

    /** @return array<string, Archived> archived rows of an arm keyed as `all()` */
    private static function archived(string $arm): array
    {
        $out = [];
        foreach (ArchiveData::expected(self::G7) as $row) {
            if (! in_array($row['s'] ?? null, ['streams', 'xs', 'tms'], true) || $row['arm'] !== $arm) {
                continue;
            }
            foreach (['stream', 'strategy', 'ids', 'flow', 'dec', 'reach', 'kinds', 'lanes'] as $field) {
                if (! is_string($row[$field] ?? null)) {
                    throw new \UnexpectedValueException("An archived G7 row has no string {$field}.");
                }
            }
            if (! is_array($row['m'] ?? null) || ! is_int($row['issued'] ?? null) || ! is_array($row['legitDeniedByCause'] ?? null)) {
                throw new \UnexpectedValueException('An archived G7 row has no metrics.');
            }
            /** @var Archived $row */
            $out[$row['stream'] . '|' . $row['strategy'] . '|' . $row['ids'] . '|' . $row['flow']] = $row;
        }

        return $out;
    }

    /**
     * Best response over id policies (and strategies) of a stream in a flow: max of reach (foreign + proof) and of legit denied.
     *
     * @param array<string, Replay> $rows
     * @return array{reach: int, legit: int}
     */
    private static function best(array $rows, string $stream, string $flow): array
    {
        $reach = $legit = 0;
        foreach ($rows as $key => $r) {
            [$s, , , $f] = explode('|', $key);
            if ($s === $stream && $f === $flow) {
                $reach = max($reach, $r['m']['reachForeign'] + $r['m']['reachProof']);
                $legit = max($legit, $r['m']['legitDenied']);
            }
        }

        return ['reach' => $reach, 'legit' => $legit];
    }

    /** @param array<string, mixed> $row */
    private static function text(array $row, string $field): string
    {
        return is_string($row[$field] ?? null) ? $row[$field] : throw new \UnexpectedValueException("An archived row has no string {$field}.");
    }

    /** @param array<string, mixed> $row an archived row with a metrics map `m` */
    private static function metric(array $row, string $name): int
    {
        $m = $row['m'] ?? null;

        return is_array($m) && is_int($m[$name] ?? null) ? $m[$name] : throw new \UnexpectedValueException("An archived row has no metric {$name}.");
    }

    /** @return iterable<string, array{string}> */
    public static function arms(): iterable
    {
        yield 'NOUA2' => ['NOUA2'];
        yield 'PX4' => ['PX4'];
    }

    /**
     * Every archived G7 row of the arm, field for field (decision, reach, kinds, lanes, metrics, Host counts, capacity causes by
     * name and the other denials by count, `refusedPx`, and the `g6TmsRow` measures on the `tms` rows). `uaVarying` and
     * `matVarying` are measures of the study's cell (its K5 material) and are not compared.
     */
    #[DataProvider('arms')]
    #[Group('rp-g6')]
    #[Group('rp-g7')]
    public function testEveryArchivedG7RowIsReproducedFieldForField(string $arm): void
    {
        $mine = self::all($arm);
        $diff = [];
        $compared = 0;
        foreach (self::archived($arm) as $key => $row) {
            self::assertArrayHasKey($key, $mine, $key);
            $p = $mine[$key];
            $fields = [];
            foreach (['dec', 'reach', 'kinds', 'lanes', 'm', 'issued', 'tainted'] as $f) {
                if ($p[$f] !== $row[$f]) {
                    $fields[] = $f;
                }
            }
            foreach (['legitDeniedByCause', 'attackDeniedByCause'] as $f) {
                if (FixtureReplays::split(FixtureReplays::archiveCauses($p[$f])) !== FixtureReplays::split(FixtureReplays::archiveCauses($row[$f]))) {
                    $fields[] = $f;
                }
            }
            foreach (['refusedPx', 'reachU', 'reachP', 'reachFirst600', 'reachPerAccountMax', 'accountsReached'] as $f) {
                if (array_key_exists($f, $row) && $p[$f] !== $row[$f]) {
                    $fields[] = $f;
                }
            }
            if ($fields !== []) {
                $diff[] = $key . ': ' . implode(',', $fields);
            }
            $compared++;
        }

        self::assertSame(210, $compared, '182 STREAMS rows, 10 xs rows and 18 tms rows of the arm');
        self::assertSame([], $diff, 'measured differences against the G7 archive');
    }

    /** RP-G6: on the arm without the cross-account cap TMS 61/61 and TMSd 120/200; the archived S0 values are the detector. */
    #[Group('rp-g6')]
    public function testRpG6TmsAndTmsdOnTheArmWithoutD8AgainstTheArchivedS0(): void
    {
        $rows = self::all('NOUA2');
        foreach (['pre+out', 'pre'] as $flow) {
            self::assertSame(61, self::best($rows, 'TMS', $flow)['reach'], "TMS {$flow}");
            self::assertSame(120, self::best($rows, 'TMSd', $flow)['reach'], "TMSd {$flow}");
        }
        $s0 = [];
        foreach (ArchiveData::expected('g7/cross_account_p/expected/g6_tms_s0.S0.jsonl') as $row) {
            if (($row['flow'] ?? null) === 'pre+out') {
                $s0[self::text($row, 'stream')] = max($s0[self::text($row, 'stream')] ?? 0, self::metric($row, 'reachForeign') + self::metric($row, 'reachProof'));
            }
        }
        self::assertSame(7, $s0['TMS'], 'DETECTOR: the archived S0 TMS reach (7/61)');
        self::assertSame(2, $s0['TMSd'], 'DETECTOR: the archived S0 TMSd reach (2/200)');
        self::assertGreaterThan($s0['TMS'], self::best($rows, 'TMS', 'pre+out')['reach'], 'the unpatched pipeline value is below the arm value');
    }

    /** RP-G7 X1: the office and every legitimate row under the cap are refused no more than on the reference, both flows. */
    #[Group('rp-g7')]
    public function testRpG7X1TheOfficeAndTheLegitimateRows(): void
    {
        $ref = self::all('NOUA2');
        $px = self::all('PX4');
        $above = [];
        foreach ($px as $key => $r) {
            if (str_starts_with($key, 'TPAIRT|') || str_starts_with($key, 'TMS|') || str_starts_with($key, 'TMSd|') || str_starts_with($key, 'TMX|')) {
                continue;
            }
            if ($r['m']['legitDenied'] > $ref[$key]['m']['legitDenied']) {
                $above[] = "{$key} {$r['m']['legitDenied']} > {$ref[$key]['m']['legitDenied']}";
            }
        }
        self::assertSame([], $above);
        self::assertSame(0, $px['N12T|none|fresh|pre+out']['m']['legitDenied']);
        self::assertSame(1, $px['N12T|none|none|pre']['m']['legitDenied'], 'the archived pre / none value (ref 1)');
    }

    /** RP-G7 X3: TMX best response under the cap is at most the reference (26/200 pre+out, 34/200 pre). */
    #[Group('rp-g7')]
    public function testRpG7X3Tmx(): void
    {
        $ref = self::all('NOUA2');
        $px = self::all('PX4');
        self::assertSame(['reach' => 26, 'legit' => 0], self::best($px, 'TMX', 'pre+out'));
        self::assertSame(['reach' => 34, 'legit' => 0], self::best($px, 'TMX', 'pre'));
        self::assertLessThanOrEqual(self::best($ref, 'TMX', 'pre+out')['reach'], self::best($px, 'TMX', 'pre+out')['reach']);
    }

    /** RP-G7 X4: no attacker cell above the reference (every row, reach foreign + proof). */
    #[Group('rp-g7')]
    public function testRpG7X4NoAttackerCellAboveTheReference(): void
    {
        $ref = self::all('NOUA2');
        $above = [];
        foreach (self::all('PX4') as $key => $r) {
            $mine = $r['m']['reachForeign'] + $r['m']['reachProof'];
            $theirs = $ref[$key]['m']['reachForeign'] + $ref[$key]['m']['reachProof'];
            if ($mine > $theirs) {
                $above[] = "{$key} {$mine} > {$theirs}";
            }
        }
        self::assertSame([], $above);
    }

    /** RP-G7 declared costs (X2) and TPAIRT, as registered: TMS 29/61, TMSd 40/200 (pre+out); 61/61, 120/200 (pre, inert). */
    #[Group('rp-g7')]
    public function testRpG7DeclaredCostsAndTpairt(): void
    {
        $px = self::all('PX4');
        $ref = self::all('NOUA2');
        self::assertSame(29, self::best($px, 'TMS', 'pre+out')['reach']);
        self::assertSame(40, self::best($px, 'TMSd', 'pre+out')['reach']);
        self::assertSame(61, self::best($px, 'TMS', 'pre')['reach']);
        self::assertSame(120, self::best($px, 'TMSd', 'pre')['reach']);
        self::assertSame(21, self::best($px, 'TPAIRT', 'pre+out')['legit'], 'the co-located office pays 21/61');
        self::assertSame(120, self::best($ref, 'TPAIRT', 'pre+out')['reach'], 'TPAIRT attacker on the reference');
        self::assertSame(72, self::best($px, 'TPAIRT', 'pre+out')['reach'], 'TPAIRT attacker under the cap');
    }

    /** DETECTOR D-PXALL: every admitted P outcome enters D8; the office is refused (N12T 32/61, N12 24/61, fresh, pre+out). */
    #[Group('rp-g7')]
    public function testDetectorDPxallRefusesTheOffice(): void
    {
        $runtime = ServiceMutant::load('AdmissionRuntime', ['entersCrossAccountSet' => 'return true;']);
        $denied = [];
        foreach (self::tuples() as $t) {
            if (in_array($t['stream'], ['N12T', 'N12'], true) && $t['ids'] === 'fresh') {
                $denied[$t['stream']] = self::replay($t, 'pre+out', self::lab('PX4', $runtime))['m']['legitDenied'];
            }
        }
        $archived = [];
        foreach (ArchiveData::expected(self::G7) as $row) {
            if (($row['arm'] ?? null) === 'D-PXALL' && $row['flow'] === 'pre+out' && $row['ids'] === 'fresh') {
                $archived[self::text($row, 'stream')] = self::metric($row, 'legitDenied');
            }
        }
        ksort($denied);
        ksort($archived);
        self::assertSame(['N12' => 24, 'N12T' => 32], $archived, 'the archived D-PXALL values');
        self::assertSame($archived, $denied, 'DETECTOR: D-PXALL refuses the office');
    }
}
