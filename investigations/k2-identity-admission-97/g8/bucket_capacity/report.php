<?php

declare(strict_types=1);

/*
 * G8 report (PLAN.md §4–§7): harness validity V1–V3 and the detector D, gates B1, B2, A1, A3, PG per `C` and arm, the recorded values,
 * `summary.md` and `gates.json`. Every archive value is read here, at run time, from the archive's own `expected/` files.
 *
 *   php report.php <actual-dir> <out-dir>       exit 0 = valid run; 5 = V or D failed
 */

use Maatify\RateLimiter\Tests\Support\Admission\Archive\ArchiveData;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\FixtureReplays;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\StreamGates;
use Maatify\RateLimiter\Tests\System\AdmissionArchiveFixturesTest;

require __DIR__ . '/../../../../vendor/autoload.php';
require __DIR__ . '/lib/g8.php';

[$in, $out] = [$argv[1] ?? '', $argv[2] ?? ''];
if (! is_dir($in) || ! is_dir($out)) {
    fwrite(STDERR, "usage: php report.php <actual-dir> <out-dir>\n");
    exit(2);
}

const G7 = 'g7/cross_account_p/expected/g7_streams.G5V2.jsonl';
const G7FX = 'g7/cross_account_p/expected/g7_fixtures.G5V2.jsonl';
const G4V2 = 'g4/integrated_v2/expected/v2_streams.G4.jsonl';
const G4V2FX = 'g4/integrated_v2/expected/v2_fixtures.G4.jsonl';
const ARCHIVE_ARM = ['FULL2' => 'NOUA2', 'PX4' => 'PX4'];
const GATED_C = [2, 3, 4];
const DELTAS = ['REC' => [0, 60, 300, 900, 1800, 3600]];

/** @return list<array<string, mixed>> */
function lines(string $file): array
{
    $rows = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: throw new RuntimeException("Missing {$file}.") as $line) {
        $rows[] = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
    }

    return $rows;
}

function key4(array $r): string
{
    return $r['stream'] . '|' . $r['strategy'] . '|' . $r['ids'] . '|' . $r['flow'];
}

// ---- load ---------------------------------------------------------------------------------------------------------------------------

$st = $fx = $lost = $meta = [];
foreach (G8\ARMS as $arm) {
    foreach (G8\CS as $c) {
        foreach (lines("{$in}/g8_streams.{$arm}.C{$c}.jsonl") as $r) {
            if ($r['s'] === 'meta') {
                $meta[$arm][$c] = $r['budgets'];
            } else {
                $st[$arm][$c][key4($r)] = $r;
            }
        }
        foreach (lines("{$in}/g8_fixtures.{$arm}.C{$c}.jsonl") as $r) {
            if ($r['s'] === 'fx') {
                $fx[$arm][$c][$r['fixture']] = $r;
            } else {
                $lost[$arm][$c][$r['fixture']] = $r;
            }
        }
        if ($meta[$arm][$c]['accountBucketTakes'] !== $c) {
            throw new RuntimeException("The runtime of {$arm} C{$c} did not receive C = {$c}.");
        }
    }
}

// ---- definitions (PLAN.md §3) -----------------------------------------------------------------------------------------------------------

/** StreamGates::best() over the G3 stream rows of a flow; ids `none` excluded unless asked (the 57 tuples of G4 v2 §6.3). */
function bestG3(array $rows, string $flow, bool $withNone = false): array
{
    $o = [];
    foreach ($rows as $r) {
        if ($r['set'] !== 'streams' || $r['flow'] !== $flow || (! $withNone && $r['ids'] === 'none')) {
            continue;
        }
        $c = $o[$r['stream']] ?? ['legit' => 0, 'reach' => 0];
        $c['legit'] = max($c['legit'], $r['m']['legitDenied']);
        $c['reach'] = max($c['reach'], $r['m']['reachForeign'] + $r['m']['reachProof']);
        $o[$r['stream']] = $c;
    }

    return $o;
}

/** The G7 best response of a stream (every tuple of it, AdmissionArchiveG7Test::best()). */
function bestG7(array $rows, string $stream, string $flow): array
{
    $reach = $legit = 0;
    foreach ($rows as $r) {
        if ($r['stream'] === $stream && $r['flow'] === $flow) {
            $reach = max($reach, $r['m']['reachForeign'] + $r['m']['reachProof']);
            $legit = max($legit, $r['m']['legitDenied']);
        }
    }

    return ['reach' => $reach, 'legit' => $legit];
}

/** The comparison of AdmissionArchiveFixturesTest (its own private method, called unchanged). */
function fixtureDifferences(array $production, array $archive): array
{
    unset($production['C'], $archive['C']);

    return (new ReflectionMethod(AdmissionArchiveFixturesTest::class, 'differences'))->invoke(null, $production, $archive);
}

function archivedFixture(string $fixture, string $arm, string $file = G7FX): ?array
{
    foreach (ArchiveData::expected($file) as $row) {
        if (($row['fixture'] ?? null) === $fixture && ($row['arm'] ?? null) === $arm && ($row['s'] ?? null) === 'fx') {
            return $row;
        }
    }

    return null;
}

/** First request where dec / reach / lanes / cz differ: null if the rows are equal. */
function firstDivergence(array $a, array $b): ?int
{
    $n = strlen($a['dec']);
    if (strlen($b['dec']) !== $n) {
        return 0;
    }
    for ($i = 0; $i < $n; $i++) {
        foreach (['dec', 'reach', 'lanes', 'cz'] as $f) {
            if ($a[$f][$i] !== $b[$f][$i]) {
                return $i;
            }
        }
    }

    return null;
}

// ---- V1, V2, V3 (PLAN.md §4) ------------------------------------------------------------------------------------------------------------

$v = [];
foreach (G8\ARMS as $arm) {
    $mine = $st[$arm][G8\REFERENCE_C];
    $diff = [];
    $compared = 0;
    foreach (ArchiveData::expected(G7) as $row) {
        if (! in_array($row['s'] ?? null, ['streams', 'xs', 'tms'], true) || $row['arm'] !== ARCHIVE_ARM[$arm]) {
            continue;
        }
        $key = key4($row);
        $p = $mine[$key] ?? null;
        $compared++;
        if ($p === null) {
            $diff[] = "{$key}: missing";
            continue;
        }
        // AdmissionArchiveG7Test::testEveryArchivedG7RowIsReproducedFieldForField, the same fields and normalisation.
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
    }
    $v['V1'][$arm] = ['archiveArm' => ARCHIVE_ARM[$arm], 'compared' => $compared, 'rowsProduced' => count($mine), 'differences' => $diff,
        'pass' => $compared === 210 && count($mine) === 210 && $diff === []];

    $diff = [];
    $compared = 0;
    foreach ($fx[$arm][G8\REFERENCE_C] as $name => $row) {
        $archiveArm = ARCHIVE_ARM[$arm] . (in_array($name, ['BOOT', 'BOOT-X'], true) ? '+R-NO-BIND' : '');
        $archived = archivedFixture($name, $archiveArm);
        $compared++;
        if ($archived === null) {
            $diff[] = "{$name}: no archived row {$archiveArm}";
            continue;
        }
        $fields = fixtureDifferences($row, $archived);
        if ($fields !== []) {
            $diff[] = "{$name}: " . implode(',', $fields);
        }
    }
    $v['V2'][$arm] = ['compared' => $compared, 'differences' => $diff, 'pass' => $compared === 19 && $diff === []];
}
$diff = [];
$compared = 0;
foreach (ArchiveData::expected(G4V2) as $row) {
    if (($row['arm'] ?? null) !== 'FULL2' || ($row['flow'] ?? null) !== 'pre+out' || ($row['s'] ?? null) !== 'streams') {
        continue;
    }
    $key = key4($row);
    $p = $st['FULL2'][G8\REFERENCE_C][$key] ?? null;
    $compared++;
    $fields = $p === null ? ['missing'] : array_values(array_filter(['dec', 'reach', 'kinds', 'lanes', 'm'], static fn(string $f): bool => $p[$f] !== $row[$f]));
    if ($fields !== []) {
        $diff[$key] = implode(',', $fields);
    }
}
// Every row equal except `UAC|none|fresh` (the declared G5 v2 cost, 5-b3b RP-G4).
$v['V3'] = ['compared' => $compared, 'differences' => $diff, 'pass' => $compared === 57 && array_diff(array_keys($diff), ['UAC|none|fresh|pre+out']) === []];

// ---- D (PLAN.md §4) ----------------------------------------------------------------------------------------------------------------------

$bound = static fn(array $budgets): int => $budgets['accountBucketTakes'] + intdiv(86400, $budgets['accountBucketSeconds']);
$d = [];
$referenceBound = $bound($meta['PX4'][G8\REFERENCE_C]);
foreach (G8\ARMS as $arm) {
    $b = $fx[$arm][G8\DETECTOR_C]['BRU24'];
    $pre = bestG3($st[$arm][G8\DETECTOR_C], 'pre');
    $po = bestG3($st[$arm][G8\DETECTOR_C], 'pre+out');
    $d[$arm] = [
        'bru24' => $b['attackAdmitted'], 'bru24Recount' => $b['tbTakesRecount'], 'bru24HV' => $fx[$arm][G8\DETECTOR_C]['BRU24-HV']['attackAdmitted'],
        'boundAtC4' => $referenceBound, 'L1pre' => $pre['L1']['legit'], 'D2pre' => $pre['D2']['legit'],
        'X1/BRU/BRD pre+out' => [$po['X1']['reach'], $po['BRU']['reach'], $po['BRD']['reach']],
        'X1/BRU/BRD pre' => [$pre['X1']['reach'], $pre['BRU']['reach'], $pre['BRD']['reach']],
        'fires' => $b['attackAdmitted'] > $referenceBound && $b['attackAdmitted'] === $b['tbTakesRecount'] && $pre['L1']['legit'] === 72,
    ];
}
$noTb = [];
foreach (['BRU24', 'BRU24-HV'] as $f) {
    $noTb[$f] = archivedFixture($f, 'R-NO-TB', G4V2FX)['attackAdmitted'] ?? null;
}
foreach (['pre+out', 'pre'] as $flow) {
    $o = [];
    foreach (ArchiveData::expected(G4V2) as $row) {
        if (($row['arm'] ?? null) === 'R-NO-TB' && ($row['flow'] ?? null) === $flow) {
            $c = $o[$row['stream']] ?? ['legit' => 0, 'reach' => 0];
            $c['legit'] = max($c['legit'], $row['m']['legitDenied']);
            $c['reach'] = max($c['reach'], $row['m']['reachForeign'] + $row['m']['reachProof']);
            $o[$row['stream']] = $c;
        }
    }
    $noTb[$flow] = ['L1' => $o['L1']['legit'], 'D2' => $o['D2']['legit'], 'X1/BRU/BRD' => [$o['X1']['reach'], $o['BRU']['reach'], $o['BRD']['reach']]];
}

$valid = $v['V1']['FULL2']['pass'] && $v['V1']['PX4']['pass'] && $v['V2']['FULL2']['pass'] && $v['V2']['PX4']['pass'] && $v['V3']['pass']
    && $d['FULL2']['fires'] && $d['PX4']['fires'];

// ---- gates (PLAN.md §5) ------------------------------------------------------------------------------------------------------------------

$s0 = StreamGates::s0();
$s24 = StreamGates::s24();
$s12 = StreamGates::s12();
$g = [];
$best = [];
foreach (G8\ARMS as $arm) {
    foreach (G8\CS as $c) {
        $best[$arm][$c] = ['pre+out' => bestG3($st[$arm][$c], 'pre+out'), 'pre' => bestG3($st[$arm][$c], 'pre'),
            'pre+out all' => bestG3($st[$arm][$c], 'pre+out', true), 'pre all' => bestG3($st[$arm][$c], 'pre', true)];
    }
    foreach (GATED_C as $c) {
        $budgets = $meta[$arm][$c];
        $limit = $bound($budgets);
        $T = $budgets['accountBucketSeconds'];
        $b1 = [];
        $b1Pass = true;
        foreach (['BRU24', 'BRU24-HV'] as $f) {
            $r = $fx[$arm][$c][$f];
            $b1[$f] = ['admitted' => $r['attackAdmitted'], 'recount' => $r['tbTakesRecount'], 'bound' => $limit];
            $b1Pass = $b1Pass && $r['attackAdmitted'] <= $limit && $r['attackAdmitted'] === $r['tbTakesRecount'];
        }
        $b2 = [];
        $b2Pass = true;
        foreach (DELTAS['REC'] as $delta) {
            $w = $fx[$arm][$c]['REC']['waitFromArrival'][$delta] ?? null;
            $b2[$delta] = ['wait' => $w, 'limit' => max(0, $T + 60 - $delta)];
            $b2Pass = $b2Pass && $w !== null && $w <= max(0, $T + 60 - $delta);
        }
        $a1 = StreamGates::a1($best[$arm][$c]['pre+out']);
        $a3 = StreamGates::a3($best[$arm][$c]['pre+out']);
        $g[$arm][$c] = ['B1' => ['pass' => $b1Pass, 'values' => $b1], 'B2' => ['pass' => $b2Pass, 'values' => $b2],
            'A1' => ['pass' => $a1 === [], 'failing' => $a1], 'A3' => ['pass' => $a3 === [], 'failing' => $a3]];
        if ($c === G8\REFERENCE_C) {
            continue;
        }
        $identical = $uFirst = 0;
        $other = [];
        foreach ($st[$arm][G8\REFERENCE_C] as $key => $ref) {
            $row = $st[$arm][$c][$key];
            $i = firstDivergence($row, $ref);
            if ($i === null) {
                $identical++;
            } elseif ($row['lanes'][$i] === 'U' && $ref['lanes'][$i] === 'U') {
                $uFirst++;
            } else {
                $other[] = "{$key} at request {$i}: lane {$row['lanes'][$i]} / {$ref['lanes'][$i]}";
            }
        }
        $pg2 = [];
        $pg2Pass = true;
        foreach (['pre+out', 'pre'] as $flow) {
            foreach (['TMS' => ['reach'], 'TMSd' => ['reach'], 'TPAIRT' => ['reach', 'legit']] as $stream => $fields) {
                $now = bestG7($st[$arm][$c], $stream, $flow);
                $ref = bestG7($st[$arm][G8\REFERENCE_C], $stream, $flow);
                foreach ($fields as $f) {
                    $pg2["{$stream} {$f} {$flow}"] = [$now[$f], $ref[$f]];
                    $pg2Pass = $pg2Pass && $now[$f] === $ref[$f];
                }
            }
        }
        $g[$arm][$c]['PG'] = ['gated' => $arm === 'PX4', 'pass' => $other === [] && $pg2Pass, 'rows' => count($st[$arm][$c]), 'identical' => $identical,
            'uFirst' => $uFirst, 'otherFirst' => $other, 'PG-2' => $pg2, 'PG-2 pass' => $pg2Pass];
    }
}

// ---- recorded values -----------------------------------------------------------------------------------------------------------------

$cells = ['X1', 'BRU', 'BRD', 'MIX', 'ABA_A', 'SP3', 'SP1n', 'SP6'];
$benign = ['L1', 'D2', 'ABA_B', 'KD', 'OFF12', 'N12', 'Y6b', 'Nu4', 'L3', 'D1', 'UAC', 'MIX'];
$fixtureDiffs = [];
foreach (G8\ARMS as $arm) {
    foreach ([2, 3, G8\DETECTOR_C] as $c) {
        foreach ($fx[$arm][G8\REFERENCE_C] as $name => $ref) {
            $fields = fixtureDifferences($fx[$arm][$c][$name], $ref);
            if ($fields !== []) {
                $fixtureDiffs[$arm][$c][$name] = $fields;
            }
        }
    }
}

// ---- gates.json --------------------------------------------------------------------------------------------------------------------------

file_put_contents("{$out}/gates.json", json_encode([
    'plan' => 'PLAN.md v1',
    'valid' => $valid,
    'validity' => $v,
    'detector' => ['D-NOTB' => $d, 'R-NO-TB (G4 v2 study arm, context)' => $noTb],
    'gates' => $g,
    'budgets' => $meta,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

// ---- summary.md --------------------------------------------------------------------------------------------------------------------------

$pf = static fn(bool $p): string => $p ? 'PASS' : '**FAIL**';
$cl = static fn(int $c): string => $c === G8\DETECTOR_C ? 'D-NOTB' : "C {$c}";
$md = [];
$md[] = '# G8 bucket capacity — results (generated by report.php; PLAN.md v1)';
$md[] = '';
$md[] = 'Every number below is `MEASURED` through the production engine (`EngineLab`). Archive values (S0, S24, S12, the G7 and G4 v2 rows) are read at run time from the archive files.';
$md[] = '';
$md[] = '## 1. Harness validity and detector (PLAN.md §4)';
$md[] = '';
$md[] = '| Check | FULL2 | PX4 |';
$md[] = '| --- | --- | --- |';
$md[] = sprintf('| V1: 210 stream rows at C 4 = G7 archive (`NOUA2` / `PX4`) field for field | %s (%d compared, %d differ) | %s (%d compared, %d differ) |',
    $pf($v['V1']['FULL2']['pass']), $v['V1']['FULL2']['compared'], count($v['V1']['FULL2']['differences']),
    $pf($v['V1']['PX4']['pass']), $v['V1']['PX4']['compared'], count($v['V1']['PX4']['differences']));
$md[] = sprintf('| V2: fixture rows at C 4 = G7 archive | %s (%d compared, %d differ) | %s (%d compared, %d differ) |',
    $pf($v['V2']['FULL2']['pass']), $v['V2']['FULL2']['compared'], count($v['V2']['FULL2']['differences']),
    $pf($v['V2']['PX4']['pass']), $v['V2']['PX4']['compared'], count($v['V2']['PX4']['differences']));
$md[] = sprintf('| V3: 57 `FULL2` `pre+out` rows at C 4 = G4 v2 archive except `UAC\|none\|fresh` | %s (%d compared; differ: %s) | — |',
    $pf($v['V3']['pass']), $v['V3']['compared'], $v['V3']['differences'] === [] ? 'none' : implode('; ', array_map(static fn(string $k, string $f): string => "`{$k}` {$f}", array_keys($v['V3']['differences']), $v['V3']['differences'])));
$md[] = sprintf('| D (`D-NOTB`, C 1,000,000): BRU24 > %d with recount equal, `L1` `pre` = 72 | %s (BRU24 %d, recount %d, `L1` pre %d) | %s (BRU24 %d, recount %d, `L1` pre %d) |', $referenceBound,
    $d['FULL2']['fires'] ? 'fires' : '**does not fire**', $d['FULL2']['bru24'], $d['FULL2']['bru24Recount'], $d['FULL2']['L1pre'],
    $d['PX4']['fires'] ? 'fires' : '**does not fire**', $d['PX4']['bru24'], $d['PX4']['bru24Recount'], $d['PX4']['L1pre']);
$md[] = '';
$md[] = sprintf('**Study valid: %s.** Differences listed: %s.', $valid ? 'yes' : '**NO**',
    ($all = array_merge($v['V1']['FULL2']['differences'], $v['V1']['PX4']['differences'], $v['V2']['FULL2']['differences'], $v['V2']['PX4']['differences'])) === [] ? 'none in V1 / V2' : implode('; ', $all));
$md[] = '';
$md[] = 'Detector context (reported): `D-NOTB` BRU24-HV ' . $d['FULL2']['bru24HV'] . ' / ' . $d['PX4']['bru24HV'] . ' (FULL2 / PX4); `D2` `pre` ' . $d['FULL2']['D2pre'] . ' / ' . $d['PX4']['D2pre']
    . '; `X1` / `BRU` / `BRD` `pre+out` ' . implode(' / ', $d['FULL2']['X1/BRU/BRD pre+out']) . ' and ' . implode(' / ', $d['PX4']['X1/BRU/BRD pre+out'])
    . '; `pre` ' . implode(' / ', $d['FULL2']['X1/BRU/BRD pre']) . ' and ' . implode(' / ', $d['PX4']['X1/BRU/BRD pre']) . '. The G4 v2 study arm `R-NO-TB` (not the production bucket): BRU24 ' . $noTb['BRU24']
    . ', BRU24-HV ' . $noTb['BRU24-HV'] . '; `pre+out` `X1` / `BRU` / `BRD` ' . implode(' / ', $noTb['pre+out']['X1/BRU/BRD']) . '; `pre` `L1` ' . $noTb['pre']['L1'] . ', `D2` ' . $noTb['pre']['D2']
    . ', `X1` / `BRU` / `BRD` ' . implode(' / ', $noTb['pre']['X1/BRU/BRD']) . '.';
$md[] = '';
$md[] = '## 2. Gates per `C` and arm (PLAN.md §5, `pre+out`)';
$md[] = '';
$md[] = '| Arm | C | B1 (BRU24 / BRU24-HV ≤ bound, recount) | B2 (REC waits at δ 0 / 60 / 300 / 900 / 1800 / 3600 s) | A1 | A3 | PG (P door) |';
$md[] = '| --- | --- | --- | --- | --- | --- | --- |';
foreach (G8\ARMS as $arm) {
    foreach (GATED_C as $c) {
        $x = $g[$arm][$c];
        $pg = $c === G8\REFERENCE_C ? 'reference' : sprintf('%s%s: %d identical, %d first divergence on U, %d other; PG-2 %s', $x['PG']['gated'] ? '' : 'reported, ', $pf($x['PG']['pass']),
            $x['PG']['identical'], $x['PG']['uFirst'], count($x['PG']['otherFirst']), $x['PG']['PG-2 pass'] ? 'equal' : '**differs**');
        $md[] = sprintf('| %s | %d | %s: %d / %d of %d (recount %d / %d) | %s: %s | %s%s | %s%s | %s |', $arm, $c, $pf($x['B1']['pass']),
            $x['B1']['values']['BRU24']['admitted'], $x['B1']['values']['BRU24-HV']['admitted'], $x['B1']['values']['BRU24']['bound'],
            $x['B1']['values']['BRU24']['recount'], $x['B1']['values']['BRU24-HV']['recount'],
            $pf($x['B2']['pass']), implode(' / ', array_map(static fn(array $w): string => $w['wait'] === null ? 'never' : (string) $w['wait'], $x['B2']['values'])),
            $pf($x['A1']['pass']), $x['A1']['failing'] === [] ? '' : ' (' . implode('; ', $x['A1']['failing']) . ')',
            $pf($x['A3']['pass']), $x['A3']['failing'] === [] ? '' : ' (' . implode('; ', $x['A3']['failing']) . ')', $pg);
    }
}
foreach (G8\ARMS as $arm) {
    foreach ([2, 3] as $c) {
        foreach ($g[$arm][$c]['PG']['otherFirst'] as $o) {
            $md[] = "* PG-1 {$arm} C {$c}: first divergence not on U: `{$o}`";
        }
    }
}
$md[] = '';
$md[] = '## 3. The table (PLAN.md §7, `pre+out`; best response with ids, legitimate refusals for `L1` … `KD`, reach otherwise)';
$md[] = '';
$md[] = '| Row | X1 | BRU | BRD | MIX atk | ABA_A | SP3 | L1 | D2 | ABA_B | KD | LOST-24h (600 / 300 / 60 s) | REC wait δ 0 (max over δ) | BRU24 (bound) |';
$md[] = '| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |';
$md[] = sprintf('| S0 | %d | %d | %d | %d | %d | %d | %d | %d | %d | %d | — | — | — |', $s0['X1']['reach'], $s0['BRU']['reach'], $s0['BRD']['reach'], $s0['MIX']['reach'], $s0['ABA_A']['reach'],
    $s0['SP3']['reach'], $s0['L1']['legit'], $s0['D2']['legit'], $s0['ABA_B']['legit'], $s0['KD']['legit']);
foreach (G8\ARMS as $arm) {
    foreach ([4, 3, 2] as $c) {
        $b = $best[$arm][$c]['pre+out'];
        $lo = $fx[$arm][$c]['LOST']['byInterval'];
        $waits = array_filter($fx[$arm][$c]['REC']['waitFromArrival'], static fn($w): bool => $w !== null);
        $md[] = sprintf('| %s C %d%s | %d | %d | %d | %d | %d | %d | %d | %d | %d | %d | %d / %d / %d | %s (%s) | %d (%d) |', $arm, $c, $c === 4 ? ' (reference)' : '',
            $b['X1']['reach'], $b['BRU']['reach'], $b['BRD']['reach'], $b['MIX']['reach'], $b['ABA_A']['reach'], $b['SP3']['reach'],
            $b['L1']['legit'], $b['D2']['legit'], $b['ABA_B']['legit'], $b['KD']['legit'], $lo[600]['reach'], $lo[300]['reach'], $lo[60]['reach'],
            $fx[$arm][$c]['REC']['waitFromArrival'][0] ?? 'never', $waits === [] ? 'never' : (string) max($waits),
            $fx[$arm][$c]['BRU24']['attackAdmitted'], $g[$arm][$c]['B1']['values']['BRU24']['bound']);
    }
}
$md[] = '';
$md[] = '## 4. OD-10 cells against S0, S12 and C 4 (reach; `pre+out` and `pre`)';
$md[] = '';
$md[] = '| Arm | C | Flow | ' . implode(' | ', $cells) . ' |';
$md[] = '| --- | --- | --- | ' . implode(' | ', array_fill(0, count($cells), '---')) . ' |';
$md[] = '| S0 | — | pre+out | ' . implode(' | ', array_map(static fn(string $s): string => (string) $s0[$s]['reach'], $cells)) . ' |';
$md[] = '| S12 | — | pre+out | ' . implode(' | ', array_map(static fn(string $s): string => isset($s12[$s]) ? (string) $s12[$s]['reach'] : '—', $cells)) . ' |';
foreach (G8\ARMS as $arm) {
    foreach (['pre+out', 'pre'] as $flow) {
        foreach ([4, 3, 2] as $c) {
            $b = $best[$arm][$c][$flow];
            $md[] = "| {$arm} | {$c} | {$flow} | " . implode(' | ', array_map(static fn(string $s): string => (string) $b[$s]['reach'], $cells)) . ' |';
        }
    }
}
$md[] = '';
$md[] = 'Over all 91 tuples (the no-id variants included), where a value differs from the table above:';
$md[] = '';
$noted = false;
foreach (G8\ARMS as $arm) {
    foreach (['pre+out', 'pre'] as $flow) {
        foreach ([4, 3, 2] as $c) {
            foreach (array_merge($cells, $benign) as $s) {
                foreach (['reach', 'legit'] as $f) {
                    $a = $best[$arm][$c][$flow][$s][$f];
                    $b = $best[$arm][$c][$flow . ' all'][$s][$f];
                    if ($a !== $b) {
                        $md[] = "* {$arm} C {$c} {$flow} `{$s}` {$f}: {$a} with ids, {$b} with the no-id variants";
                        $noted = true;
                    }
                }
            }
        }
    }
}
if (! $noted) {
    $md[] = '* none';
}
$md[] = '';
$md[] = '## 5. Benign streams: legitimate refusals and the difference from C 4 (A1 references S0 / S24)';
$md[] = '';
$md[] = '| Arm | Flow | C | ' . implode(' | ', $benign) . ' |';
$md[] = '| --- | --- | --- | ' . implode(' | ', array_fill(0, count($benign), '---')) . ' |';
$md[] = '| S0 | pre+out | — | ' . implode(' | ', array_map(static fn(string $s): string => (string) $s0[$s]['legit'], $benign)) . ' |';
$md[] = '| S24 | pre+out | — | ' . implode(' | ', array_map(static fn(string $s): string => (string) $s24[$s]['legit'], $benign)) . ' |';
foreach (G8\ARMS as $arm) {
    foreach (['pre+out', 'pre'] as $flow) {
        foreach ([4, 3, 2] as $c) {
            $md[] = "| {$arm} | {$flow} | {$c} | " . implode(' | ', array_map(static function (string $s) use ($best, $arm, $flow, $c): string {
                $now = $best[$arm][$c][$flow][$s]['legit'];
                $delta = $now - $best[$arm][4][$flow][$s]['legit'];

                return $c === 4 ? (string) $now : sprintf('%d (%+d)', $now, $delta);
            }, $benign)) . ' |';
        }
    }
}
$md[] = '';
$md[] = '## 6. Known device that lost its credential (F-G5-04): LOST and TOKEN detail';
$md[] = '';
$md[] = '| Fixture | Arm | C | Interval (s) | Requests | Reach 24 h | P lane | First refusal at (s) | Refused in [0, 600) | Refused in [0, 1800) | Refused in 24 h | Causes |';
$md[] = '| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |';
foreach (['LOST', 'TOKEN'] as $name) {
    foreach (G8\ARMS as $arm) {
        foreach ([4, 3, 2] as $c) {
            foreach ($lost[$arm][$c][$name]['byInterval'] as $iv => $r) {
                $md[] = sprintf('| %s | %s | %d | %d | %d | %d | %d | %s | %d | %d | %d | %s |', $name, $arm, $c, $iv, $r['requests'], $r['reach'], $r['pLane'],
                    $r['firstRefusalT'] === null ? 'never' : (string) $r['firstRefusalT'], $r['refusedFirst600'], $r['refusedFirst1800'], $r['refused24h'],
                    $r['causes'] === [] ? '—' : implode(', ', array_map(static fn(string $k, int $n): string => "{$k} {$n}", array_keys($r['causes']), $r['causes'])));
            }
        }
    }
}
$md[] = '';
$md[] = '## 7. Fixtures that differ from C 4 (fields, the comparison of AdmissionArchiveFixturesTest)';
$md[] = '';
foreach (G8\ARMS as $arm) {
    foreach ([3, 2, G8\DETECTOR_C] as $c) {
        $list = $fixtureDiffs[$arm][$c] ?? [];
        $md[] = sprintf('* %s %s: %s', $arm, $cl($c), $list === [] ? 'none' : implode('; ', array_map(static fn(string $n, array $f): string => "{$n} (" . implode(', ', $f) . ')', array_keys($list), $list)));
    }
}
$md[] = '';
$md[] = 'Values of the fixtures that differ (C 4 → C 3 → C 2; the per-request strings `dec`, `reach`, `kinds`, `lanes` are in the jsonl):';
$md[] = '';
$shown = false;
foreach (G8\ARMS as $arm) {
    $names = array_unique(array_merge(array_keys($fixtureDiffs[$arm][3] ?? []), array_keys($fixtureDiffs[$arm][2] ?? [])));
    foreach ($names as $name) {
        foreach (array_diff(array_keys($fx[$arm][4][$name]), ['s', 'C', 'arm', 'fixture', 'dec', 'reach', 'kinds', 'lanes']) as $f) {
            $vals = array_map(static fn(int $c): string => json_encode($fx[$arm][$c][$name][$f] ?? null, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), [4, 3, 2]);
            if (count(array_unique($vals)) > 1) {
                $md[] = "* {$arm} {$name} `{$f}`: " . implode(' → ', array_map(static fn(string $x): string => "`{$x}`", $vals));
                $shown = true;
            }
        }
    }
}
if (! $shown) {
    $md[] = '* none';
}
$md[] = '';
file_put_contents("{$out}/summary.md", implode("\n", $md));

echo sprintf("study valid: %s\n", $valid ? 'yes' : 'NO');
foreach (G8\ARMS as $arm) {
    foreach (GATED_C as $c) {
        $x = $g[$arm][$c];
        echo sprintf("%-5s C %d  B1 %s  B2 %s  A1 %s  A3 %s  PG %s\n", $arm, $c, $x['B1']['pass'] ? 'PASS' : 'FAIL', $x['B2']['pass'] ? 'PASS' : 'FAIL',
            $x['A1']['pass'] ? 'PASS' : 'FAIL', $x['A3']['pass'] ? 'PASS' : 'FAIL', isset($x['PG']) ? ($x['PG']['pass'] ? 'PASS' : 'FAIL') . ($x['PG']['gated'] ? '' : ' (reported)') : 'reference');
    }
}
exit($valid ? 0 : 5);
