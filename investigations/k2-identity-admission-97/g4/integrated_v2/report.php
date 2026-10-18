<?php

declare(strict_types=1);

// G4 v2 report (PLAN.md §6, §7, §8): harness validity (V0, V1, IN, D-IN), Part R gates (G4 definitions), Part N gates,
// necessity, changes from the G4 archive, Q4; writes summary.md and gates.json.
// Exit 5 = V0 or V1 miss; 6 = D-IN miss; 3 = harness valid and FULL2 NO-GO (pre+out); 0 = FULL2 supported (pre+out).
//   php report.php <actual-dir> <out-dir>

$in = $argv[1] ?? '';
$outDir = $argv[2] ?? '';
$k2 = dirname(__DIR__, 2);
$g4a = "$k2/g4/integrated/expected";

/** @return list<array<string, mixed>> */
function rows(string $file): array
{
    $out = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $out[] = json_decode($l, true, 512, JSON_THROW_ON_ERROR);
    }

    return $out;
}

$builds = ['G4', 'NOESC', 'C5'];
$streams = array_merge(...array_map(static fn(string $b): array => rows("$in/v2_streams.$b.jsonl"), $builds));
$v0 = rows("$in/v2_streams.CAND.jsonl");
$fx = array_merge(...array_map(static fn(string $b): array => rows("$in/v2_fixtures.$b.jsonl"), $builds));
$cgn = rows("$in/v2_cgn.G4.jsonl");
$din = rows("$in/v2_detector.G4.jsonl");
$v2 = rows("$k2/g3/admission_budgets_v2/expected/v2_fc.CAND.jsonl");
$g4Streams = array_merge(...array_map(static fn(string $b): array => rows("$g4a/g4_streams.$b.jsonl"), $builds));
$g4Fx = array_merge(...array_map(static fn(string $b): array => rows("$g4a/g4_fixtures.$b.jsonl"), $builds));
$g4Cgn = rows("$g4a/g4_cgn.G4.jsonl");

// ---- V0 ------------------------------------------------------------------------------------------------------------------------
$arch = [];
foreach ($v2 as $r) {
    if ($r['arm'] === 'S24' && $r['flow'] === 'pre+out') {
        $arch[$r['stream'] . '|' . $r['strategy']] = $r;
    }
}
$miss = [];
foreach ($v0 as $r) {
    $k = $r['stream'] . '|' . $r['strategy'];
    foreach (['m', 'legitDeniedByCause', 'attackDeniedByCause', 'dec', 'reach', 'kinds', 'lanes'] as $f) {
        if (! isset($arch[$k]) || $arch[$k][$f] !== $r[$f]) {
            $miss[] = "V0 $k $f";
        }
    }
}
if (count($v0) !== count($arch)) {
    $miss[] = 'V0 row count ' . count($v0) . ' vs ' . count($arch);
}

// ---- V1: Part R reproduces the G4 archive on the unaffected fixtures --------------------------------------------------------------
$unaffected = ['HAR-B', 'HAR-N', 'CROSS', 'STEAL', 'CLEAR', 'E2', 'BOOT', 'BOOT-X'];
$g4FxIdx = [];
foreach ($g4Fx as $r) {
    $g4FxIdx[$r['fixture'] . '|' . $r['arm']] = $r;
}
$v1n = 0;
foreach ($fx as $r) {
    if (! str_starts_with($r['arm'], 'R-') || ! in_array($r['fixture'], $unaffected, true)) {
        continue;
    }
    $ga = $r['arm'] === 'R-FULL+R-NO-BIND' ? 'FULL+NO-BIND' : substr($r['arm'], 2);
    $x = $g4FxIdx[$r['fixture'] . '|' . $ga] ?? null;
    $v1n++;
    if ($x === null) {
        $miss[] = "V1 missing {$r['fixture']} $ga";
        continue;
    }
    $x['arm'] = $r['arm'];
    if ($x !== $r) {
        $miss[] = "V1 {$r['fixture']} {$r['arm']}";
    }
}
foreach ($g4Cgn as $x) {
    $v1n++;
    $found = false;
    foreach ($cgn as $r) {
        if ($r['arm'] === 'R-' . $x['arm'] && $r['n'] === $x['n'] && $r['lambda'] === $x['lambda'] && $r['sprayer'] === $x['sprayer']) {
            $found = true;
            $x['arm'] = $r['arm'];
            if ($x !== $r) {
                $miss[] = "V1 CGN-B {$r['arm']} {$r['n']}/{$r['lambda']}";
            }
        }
    }
    $found || $miss[] = "V1 CGN-B missing {$x['arm']}";
}
$v1Expected = count(array_filter($g4Fx, static fn(array $r): bool => in_array($r['fixture'], $unaffected, true))) + count($g4Cgn);
if ($v1n !== $v1Expected) {
    $miss[] = "V1 compared $v1n rows, expected $v1Expected";
}
if ($miss !== []) {
    fwrite(STDOUT, "HARNESS VALIDITY MISS\n" . implode("\n", $miss) . "\n");
    exit(5);
}
$dinOk = $din !== [] && array_reduce($din, static fn(bool $c, array $r): bool => $c && $r['pass'], true);
if (! $dinOk) {
    fwrite(STDOUT, "D-IN MISS\n");
    exit(6);
}

// ---- aggregates ----------------------------------------------------------------------------------------------------------------
function best(array $rows, string $arm, string $flow): array
{
    $o = [];
    foreach ($rows as $r) {
        if (($r['arm'] ?? null) !== $arm || ($r['flow'] ?? null) !== $flow || ! isset($r['m'])) {
            continue;
        }
        $c = $o[$r['stream']] ?? ['legit' => 0, 'reach' => 0, 'causes' => []];
        $c['legit'] = max($c['legit'], $r['m']['legitDenied']);
        $c['reach'] = max($c['reach'], $r['m']['reachForeign'] + $r['m']['reachProof']);
        if ($r['m']['legitDenied'] >= $c['legit']) {
            $c['causes'] = $r['legitDeniedByCause'];
        }
        $o[$r['stream']] = $c;
    }

    return $o;
}

$S0 = [];
foreach (rows("$k2/g3/admission_budgets/expected/s00_s0.jsonl") as $r) {
    $c = $S0[$r['stream']] ?? ['legit' => 0, 'reach' => 0];
    $c['legit'] = max($c['legit'], $r['m']['legitDenied']);
    $c['reach'] = max($c['reach'], $r['m']['reachForeign']);
    $S0[$r['stream']] = $c;
}
$B = ['S24' => best($v0, 'S24', 'pre+out'), 'S12' => best($v2, 'S12', 'pre+out'), 'S24pre' => best($v2, 'S24', 'pre')];
$rArms = ['R-FULL', 'R-FULL-40', 'R-NO-BIND', 'R-NO-FCN', 'R-NO-TB', 'R-NO-K5SEP', 'R-ESC', 'R-WEAKBIND'];
$nArms = ['FULL2', 'FULL2-40', 'N-PG1', 'N-NORENEW', 'N-NOPWB', 'N-NOK5SEP', 'P-NETF'];
$preArms = ['R-FULL', 'R-FULL-40', 'R-NO-BIND', 'R-NO-TB', 'FULL2', 'FULL2-40', 'N-PG1', 'P-NETF'];
foreach (array_merge($rArms, $nArms) as $a) {
    $B[$a] = best($streams, $a, 'pre+out');
}
foreach ($preArms as $a) {
    $B["$a pre"] = best($streams, $a, 'pre');
}
$G = [];
foreach (['FULL', 'FULL-40', 'NO-BIND', 'NO-FCN', 'NO-TB', 'NO-K5SEP', 'ESC', 'WEAKBIND'] as $a) {
    $G[$a] = best($g4Streams, $a, 'pre+out');
}
foreach (['FULL', 'FULL-40', 'NO-BIND', 'NO-TB'] as $a) {
    $G["$a pre"] = best($g4Streams, $a, 'pre');
}
$F = [];
foreach ($fx as $r) {
    $F[$r['fixture']][$r['arm']] = $r;
}
$C = [];
foreach ($cgn as $r) {
    $C[$r['arm']][$r['n'] . '/' . $r['lambda'] . '/' . ($r['sprayer'] ? 'X' : '-')] = $r;
}
$benign = ['L1', 'ABA_B', 'D2', 'N12', 'Nu4', 'OFF12', 'Y6b', 'KD', 'UAC', 'L3', 'D1'];
$spray = ['SP1n', 'SP3', 'SP6', 'ABA_A'];
$deltas = [0, 60, 300, 900, 1800, 3600];
$sum = static fn(array $days, string $k): int => array_sum(array_column($days, $k));

// ---- gates (shared definitions) ------------------------------------------------------------------------------------------------
$common = static function (string $full, string $noBind, string $weak, string $boot) use ($B, $S0, $F, $C, $benign, $spray, $deltas, $sum): array {
    $g = [];
    $d = [];
    foreach ($benign as $s) {
        $v = $B[$full][$s]['legit'];
        if ($v > $S0[$s]['legit'] || $v > $B['S24'][$s]['legit']) {
            $d[] = "$s $v > " . ($v > $S0[$s]['legit'] ? "S0 {$S0[$s]['legit']}" : "S24 {$B['S24'][$s]['legit']}") . ' (' . json_encode($B[$full][$s]['causes']) . ')';
        }
    }
    $g['A1'] = ['pass' => $d === [], 'detail' => $d === [] ? ['every benign stream ≤ S0 and ≤ S24'] : $d];
    $g['A2'] = ['pass' => $B[$full]['MIX']['legit'] <= $S0['MIX']['legit'], 'detail' => ["MIX legit {$B[$full]['MIX']['legit']} (S0 {$S0['MIX']['legit']})"]];
    $d = [];
    $ok = true;
    foreach ($spray as $s) {
        $ok = $ok && $B[$full][$s]['reach'] <= $B['S12'][$s]['reach'];
        $d[] = "$s {$B[$full][$s]['reach']} (S12 {$B['S12'][$s]['reach']})";
    }
    $g['A3'] = ['pass' => $ok, 'detail' => $d];
    $b = $F['BRU24'][$full];
    $g['B1'] = ['pass' => $b['attackAdmitted'] <= 52 && $b['attackAdmitted'] === $b['tbTakesRecount'], 'detail' => ["admitted {$b['attackAdmitted']}, recount {$b['tbTakesRecount']}, ceiling 52"]];
    $d = [];
    $ok = true;
    foreach ($deltas as $x) {
        $w = $F['REC'][$full]['waitFromArrival'][(string) $x] ?? null;
        $ok = $ok && $w !== null && $w <= max(0, 1860 - $x);
        $d[] = "δ $x: " . ($w ?? 'never') . ' ≤ ' . max(0, 1860 - $x);
    }
    $g['B2'] = ['pass' => $ok, 'detail' => $d];
    $g['C1'] = ['pass' => $F['HAR-B'][$full]['m']['legitDenied'] === 0, 'detail' => ["owner denials {$F['HAR-B'][$full]['m']['legitDenied']}"],
        'detector' => ['fired' => $F['HAR-B'][$noBind]['m']['legitDenied'] >= 1, 'detail' => "$noBind owner denials {$F['HAR-B'][$noBind]['m']['legitDenied']}"]];
    $g['C2'] = ['pass' => $F['BRU24-HV'][$full]['attackAdmitted'] <= 52, 'detail' => ["admitted {$F['BRU24-HV'][$full]['attackAdmitted']}, ceiling 52"]];
    $c = $F['CROSS'];
    $g['C3'] = ['pass' => $c[$full]['victimPAdmitted'] === 0 && $c[$full]['victimReach'] <= $c[$noBind]['victimReach'],
        'detail' => ["victim P {$c[$full]['victimPAdmitted']}; reach {$c[$full]['victimReach']} ($noBind {$c[$noBind]['victimReach']})"],
        'detector' => ['fired' => $c[$weak]['victimPAdmitted'] >= 1, 'detail' => "$weak victim P {$c[$weak]['victimPAdmitted']}"]];
    $bo = $F['BOOT'][$boot];
    $bx = $F['BOOT-X'][$boot];
    $key = array_key_first($bo['perDay']);
    $share = $sum($bo['perDay'][$key], 'refused') / max(1, $sum($bo['perDay'][$key], 'n'));
    $g['C5'] = ['pass' => $share <= 0.01 && $bx['fullBoundRefused'] === 0,
        'detail' => ['BOOT refused ' . $sum($bo['perDay'][$key], 'refused') . ' / ' . $sum($bo['perDay'][$key], 'n'), "BOOT-X bound-instance refusals {$bx['fullBoundRefused']}"],
        'detector' => ['fired' => $bx['noBindRefusedWhereFullBound'] >= 1, 'detail' => "$noBind refused {$bx['noBindRefusedWhereFullBound']} day-1 requests from bound instances"]];
    if (! isset($C[$full])) {
        $g['C6'] = ['pass' => false, 'detail' => ['not run for this arm'], 'class' => 'MODEL'];
    } else {
    $cn = $C[$full]['5000/1/-'];
    $cx = $C[$full]['5000/1/X'];
    $g['C6'] = ['pass' => $cn['days'][2]['refusedShare'] <= 0.01 && $sum($cx['days'], 'boundRefused') === 0,
        'detail' => ["day-3 share {$cn['days'][2]['refusedShare']}", 'bound refusals with sprayer ' . $sum($cx['days'], 'boundRefused')], 'class' => 'MODEL'];
    }
    $g['P'] = ['pass' => $B["$full pre"]['L1']['legit'] <= 0 && $B["$full pre"]['D2']['legit'] <= 36,
        'detail' => ["pre L1 {$B["$full pre"]['L1']['legit']} ≤ 0, D2 {$B["$full pre"]['D2']['legit']} ≤ 36"]];

    return $g;
};

// Part R: G4 definitions on R-FULL.
$R = $common('R-FULL', 'R-NO-BIND', 'R-WEAKBIND', 'R-FULL+R-NO-BIND');
$st = $F['STEAL']['R-FULL'];
$R['C4'] = ['pass' => $st['thiefPMaxPerWindow'] <= 6 && $st['thiefPBeforeRevoke'] + $st['thiefPAfterRevoke'] <= 30 && $st['thiefPAfterRevoke'] === 0
    && $st['ownerWaitAfterRevoke'] !== null && $st['ownerWaitAfterRevoke'] <= 1800,
    'detail' => ["thief P/window {$st['thiefPMaxPerWindow']}, 24 h " . ($st['thiefPBeforeRevoke'] + $st['thiefPAfterRevoke']) . ", after {$st['thiefPAfterRevoke']}; owner wait " . ($st['ownerWaitAfterRevoke'] ?? 'never')]];
$R['E'] = ['pass' => $F['E2']['R-FULL']['identical'], 'detail' => ['identical: ' . ($F['E2']['R-FULL']['identical'] ? 'yes' : 'no')],
    'detector' => ['fired' => ! $F['E2']['R-NO-K5SEP']['identical'], 'detail' => 'R-NO-K5SEP differs: ' . ($F['E2']['R-NO-K5SEP']['identical'] ? 'no' : 'yes')]];
$order = ['A1', 'A2', 'A3', 'B1', 'B2', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'E', 'P'];
$R = array_merge(array_flip($order), $R);
$rFailed = [];
foreach ($R as $id => $g) {
    if (! $g['pass'] || (isset($g['detector']) && ! $g['detector']['fired'])) {
        $rFailed[] = $id . ($g['pass'] ? ' (detector)' : '');
    }
}
$rVerdict = $rFailed === [] ? 'EXPERIMENTALLY SUPPORTED — LIMITED SCOPE' : 'NO-GO AFTER EXPERIMENT (' . implode(', ', $rFailed) . ')';

// Part N: FULL2.
$N = $common('FULL2', 'R-NO-BIND', 'R-WEAKBIND', 'FULL2+R-NO-BIND');
$st = $F['STEAL']['FULL2'];
$np = $F['STEAL']['N-NOPWB'];
$N['C4v2'] = ['pass' => $st['thiefPMaxPerWindow'] <= 6 && $st['thiefPBeforeChange'] + $st['thiefPAfterChange'] <= 30 && $st['thiefPAfterChange'] === 0
    && $st['thiefUAfterChange'] <= 28 && $st['ownerWaitAfterChange'] !== null && $st['ownerWaitAfterChange'] <= 60 && $st['ownerLaneAtAdmission'] === 'P',
    'detail' => ["thief P/window {$st['thiefPMaxPerWindow']} ≤ 6, 24 h " . ($st['thiefPBeforeChange'] + $st['thiefPAfterChange']) . " ≤ 30, after the change {$st['thiefPAfterChange']}; thief U after {$st['thiefUAfterChange']} ≤ 28",
        'owner admitted after ' . ($st['ownerWaitAfterChange'] ?? 'never') . ' s on ' . ($st['ownerLaneAtAdmission'] ?? '—') . ' (≤ 60 s, P)'],
    'detector' => ['fired' => $np['ownerWaitAfterChange'] === null || $np['ownerWaitAfterChange'] > 60, 'detail' => 'N-NOPWB owner wait ' . ($np['ownerWaitAfterChange'] ?? 'never')]];
$N['E'] = ['pass' => $F['E2']['FULL2']['identical'], 'detail' => ['identical: ' . ($F['E2']['FULL2']['identical'] ? 'yes' : 'no')],
    'detector' => ['fired' => ! $F['E2']['N-NORENEW']['identical'], 'detail' => 'N-NORENEW differs: ' . ($F['E2']['N-NORENEW']['identical'] ? 'no' : 'yes')]];
$N['K5'] = ['pass' => $F['K5U']['FULL2']['identicalFrom960'], 'detail' => ['owner rows from 960 s identical between fail and ctl: ' . ($F['K5U']['FULL2']['identicalFrom960'] ? 'yes' : 'no')],
    'detector' => ['fired' => ! $F['K5U']['N-NOK5SEP']['identicalFrom960'], 'detail' => 'N-NOK5SEP differs: ' . ($F['K5U']['N-NOK5SEP']['identicalFrom960'] ? 'no' : 'yes')]];
$nOrder = ['A1', 'A2', 'A3', 'B1', 'B2', 'C1', 'C2', 'C3', 'C4v2', 'C5', 'C6', 'E', 'K5', 'P'];
$N = array_merge(array_flip($nOrder), $N);
$nFailed = [];
foreach ($N as $id => $g) {
    if ($id !== 'P' && (! $g['pass'] || (isset($g['detector']) && ! $g['detector']['fired']))) {
        $nFailed[] = $id . ($g['pass'] ? ' (detector)' : '');
    }
}
$nVerdict = $nFailed === [] ? 'EXPERIMENTALLY SUPPORTED — LIMITED SCOPE (pre+out Hosts)' : 'NO-GO AFTER EXPERIMENT (' . implode(', ', $nFailed) . ')';
$pResult = $N['P']['pass'] ? 'PASS' : 'FAIL';

// ---- necessity -------------------------------------------------------------------------------------------------------------------
$fmt = static fn(int|float|null $v): string => $v === null || $v === PHP_INT_MAX ? 'never' : (string) $v;
$measures = static function (string $a) use ($B, $F, $C, $benign, $spray, $deltas, $preArms, $sum): array {
    $m = [];
    foreach (array_merge($benign, ['MIX']) as $s) {
        $m["legit $s"] = $B[$a][$s]['legit'];
    }
    foreach ($spray as $s) {
        $m["reach $s"] = $B[$a][$s]['reach'];
    }
    $m['BRU24 admitted'] = $F['BRU24'][$a]['attackAdmitted'];
    foreach ($deltas as $x) {
        $m["REC wait δ$x"] = $F['REC'][$a]['waitFromArrival'][(string) $x] ?? PHP_INT_MAX;
    }
    $m['HAR-B owner denials'] = $F['HAR-B'][$a]['m']['legitDenied'];
    $m['BRU24-HV admitted'] = $F['BRU24-HV'][$a]['attackAdmitted'];
    $m['CROSS victim reach'] = $F['CROSS'][$a]['victimReach'];
    $m['CROSS victim P'] = $F['CROSS'][$a]['victimPAdmitted'];
    $s = $F['STEAL'][$a];
    $m['STEAL thief P / window'] = $s['thiefPMaxPerWindow'];
    $m['STEAL thief P after'] = $s['thiefPAfterRevoke'] ?? $s['thiefPAfterChange'];
    $m['STEAL owner wait'] = ($s['ownerWaitAfterRevoke'] ?? $s['ownerWaitAfterChange'] ?? null) ?? PHP_INT_MAX;
    $m['E2 differs'] = $F['E2'][$a]['identical'] ? 0 : 1;
    if (isset($F['K5U'][$a])) {
        $m['K5U differs'] = $F['K5U'][$a]['identicalFrom960'] ? 0 : 1;
    }
    if (in_array($a, $preArms, true)) {
        $m['pre legit L1'] = $B["$a pre"]['L1']['legit'];
        $m['pre legit D2'] = $B["$a pre"]['D2']['legit'];
        $m['pre reach BRU'] = $B["$a pre"]['BRU']['reach'];
    }
    $pair = ['R-FULL' => ['R-FULL+R-NO-BIND', 'FULL'], 'R-NO-BIND' => ['R-FULL+R-NO-BIND', 'NO-BIND'], 'FULL2' => ['FULL2+R-NO-BIND', 'FULL2']][$a] ?? null;
    if ($pair !== null) {
        [$row, $key] = $pair;
        $bx = $F['BOOT-X'][$row];
        $m['BOOT refusals'] = $sum($F['BOOT'][$row]['perDay'][$key], 'refused');
        $m['BOOT-X refusals'] = $sum($bx['perDay'][$key], 'refused');
        $m['BOOT-X refusals of bound instances'] = $key === 'NO-BIND' ? $bx['noBindRefusedWhereFullBound'] : $bx['fullBoundRefused'];
    }
    if (isset($C[$a])) {
        $m['CGN 5000/1 day-3 refused'] = $C[$a]['5000/1/-']['days'][2]['refused'];
        $m['CGN 5000/1 + sprayer, day-2 refused'] = $C[$a]['5000/1/X']['days'][1]['refused'];
    }

    return $m;
};
$necessity = static function (string $base, array $exp) use ($measures, $fmt): array {
    $full = $measures($base);
    $out = [];
    foreach ($exp as $a => $e) {
        $worse = $better = [];
        foreach ($measures($a) as $k => $v) {
            if (! array_key_exists($k, $full)) {
                continue;
            }
            if ($v > $full[$k]) {
                $worse[] = "$k {$fmt($v)} ($base {$fmt($full[$k])})";
            } elseif ($v < $full[$k]) {
                $better[] = "$k {$fmt($v)} ($base {$fmt($full[$k])})";
            }
        }
        $out[$a] = ['expected' => $e, 'worse' => $worse, 'better' => $better, 'shown' => $worse !== []];
    }

    return $out;
};
$needN = $necessity('FULL2', ['N-PG1' => 'A1', 'N-NORENEW' => 'E', 'N-NOPWB' => 'C4v2', 'N-NOK5SEP' => 'K5']);
$needR = $necessity('R-FULL', ['R-NO-BIND' => 'C1, C5, C6', 'R-NO-FCN' => 'A3', 'R-NO-TB' => 'B1, pre BRU reach', 'R-NO-K5SEP' => 'E', 'R-ESC' => 'B2']);

// ---- changes from the G4 archive -----------------------------------------------------------------------------------------------
$changes = [];
foreach (['FULL', 'FULL-40', 'NO-BIND', 'NO-FCN', 'NO-TB', 'NO-K5SEP', 'ESC', 'WEAKBIND'] as $a) {
    foreach (['pre+out' => '', 'pre' => ' pre'] as $flow => $suf) {
        if (! isset($G["$a$suf"])) {
            continue;
        }
        foreach ($G["$a$suf"] as $s => $old) {
            $new = $B["R-$a$suf"][$s];
            foreach (['legit' => 'legit denied', 'reach' => 'attack reach'] as $f => $label) {
                if ($old[$f] !== $new[$f]) {
                    $changes[] = [$a, $flow, $s, $label, $old[$f], $new[$f]];
                }
            }
        }
    }
    foreach (['BRU24', 'BRU24-HV'] as $fxid) {
        $old = $g4FxIdx["$fxid|$a"]['attackAdmitted'];
        $new = $F[$fxid]["R-$a"]['attackAdmitted'];
        if ($old !== $new) {
            $changes[] = [$a, 'pre+out', $fxid, 'attacker admissions', $old, $new];
        }
    }
    foreach ($deltas as $x) {
        $old = $g4FxIdx["REC|$a"]['waitFromArrival'][(string) $x] ?? null;
        $new = $F['REC']["R-$a"]['waitFromArrival'][(string) $x] ?? null;
        if ($old !== $new) {
            $changes[] = [$a, 'pre+out', "REC δ $x", 'owner wait', $old ?? 'never', $new ?? 'never'];
        }
    }
}

// Row level: every Part R stream row whose metrics or causes differ from its G4 archive row.
$g4RowIdx = [];
foreach ($g4Streams as $r) {
    $g4RowIdx[$r['arm'] . '|' . $r['flow'] . '|' . $r['stream'] . '|' . $r['strategy'] . '|' . $r['ids']] = $r;
}
$rowChanges = [];
$rowCount = 0;
foreach ($streams as $r) {
    if (! str_starts_with($r['arm'], 'R-')) {
        continue;
    }
    $rowCount++;
    $x = $g4RowIdx[substr($r['arm'], 2) . '|' . $r['flow'] . '|' . $r['stream'] . '|' . $r['strategy'] . '|' . $r['ids']];
    if ($x['dec'] === $r['dec'] && $x['lanes'] === $r['lanes'] && $x['reach'] === $r['reach'] && $x['m'] === $r['m']
        && $x['legitDeniedByCause'] === $r['legitDeniedByCause'] && $x['attackDeniedByCause'] === $r['attackDeniedByCause']) {
        continue;
    }
    $d = [];
    foreach ($r['m'] as $k => $v) {
        if ($x['m'][$k] !== $v) {
            $d[] = "$k {$x['m'][$k]} → $v";
        }
    }
    $rowChanges[] = [substr($r['arm'], 2), $r['flow'], $r['stream'], $r['strategy'], $r['ids'], $d === [] ? 'decisions or causes only' : implode(', ', $d),
        'attack ' . json_encode($x['attackDeniedByCause'], JSON_UNESCAPED_SLASHES) . '; legit ' . json_encode($x['legitDeniedByCause'], JSON_UNESCAPED_SLASHES),
        'attack ' . json_encode($r['attackDeniedByCause'], JSON_UNESCAPED_SLASHES) . '; legit ' . json_encode($r['legitDeniedByCause'], JSON_UNESCAPED_SLASHES)];
}

// ---- summary.md ----------------------------------------------------------------------------------------------------------------
$gateRows = static function (array $gates, array $md): array {
    $md[] = '| Gate | Result | Detail | Detector | Evidence |';
    $md[] = '| --- | --- | --- | --- | --- |';
    foreach ($gates as $id => $g) {
        $det = isset($g['detector']) ? ($g['detector']['fired'] ? 'fired: ' : 'NOT fired: ') . $g['detector']['detail'] : '—';
        $md[] = "| $id | " . ($g['pass'] ? 'PASS' : 'FAIL') . ' | ' . implode('; ', $g['detail']) . " | $det | " . ($g['class'] ?? 'pipeline, as planned') . ' |';
    }

    return $md;
};
$md = ['# G4 v2 integrated re-assembly — generated summary', '',
    '## Harness validity', '',
    '| Check | Result |', '| --- | --- |',
    '| V0 | PASS: ' . count($v0) . ' rows equal the G3 v2 S24 archive |',
    "| V1 | PASS: $v1n Part R rows equal the G4 archive on the unaffected fixtures (HAR-B, HAR-N, CROSS, STEAL, CLEAR, E2, BOOT, BOOT-X, CGN-B) |",
    '| IN | PASS: no input-assertion violation in any scenario |',
    '| D-IN | PASS: ' . implode('; ', array_map(static fn(array $r): string => "{$r['family']} reverted {$r['revertedRejected']}/{$r['reverted']} rejected, corrected {$r['correctedRejected']}/{$r['corrected']} rejected", $din)) . ' |',
    '', '## Part N — `FULL2`', '', "Verdict (PLAN.md §7.3, pre+out Hosts): **$nVerdict**", '',
    "Gate P (checkOnly-only Hosts, PLAN.md §7.4): **$pResult**", ''];
$md = $gateRows($N, $md);
$md[] = '';
$md[] = '## Part R — G4 with corrected input (`R-FULL`, G4 definitions)';
$md[] = '';
$md[] = "Verdict (G4 PLAN.md §5): **$rVerdict**. G4 archive: NO-GO AFTER EXPERIMENT (A1, C4, E, P).";
$md[] = '';
$md = $gateRows($R, $md);
$md[] = '';
$md[] = '## Changes from the G4 archive (G4 arm → Part R arm; best response over strategies and id policies)';
$md[] = '';
if ($changes === []) {
    $md[] = 'None.';
} else {
    $md[] = '| G4 arm | Flow | Stream / fixture | Measure | G4 archive | Part R |';
    $md[] = '| --- | --- | --- | --- | --- | --- |';
    foreach ($changes as [$a, $flow, $s, $l, $o, $n]) {
        $md[] = "| $a | $flow | $s | $l | $o | $n |";
    }
}
$md[] = '';
$md[] = '### Row level (every Part R stream row, ' . $rowCount . ' rows, against its G4 archive row)';
$md[] = '';
if ($rowChanges === []) {
    $md[] = 'No row differs.';
} else {
    $md[] = count($rowChanges) . ' of ' . $rowCount . ' rows differ:';
    $md[] = '';
    $md[] = '| G4 arm | Flow | Stream | Strategy | Ids | Metrics G4 → Part R | Denial causes, G4 archive | Denial causes, Part R |';
    $md[] = '| --- | --- | --- | --- | --- | --- | --- | --- |';
    foreach ($rowChanges as $c) {
        $md[] = '| ' . implode(' | ', $c) . ' |';
    }
}
$md[] = '';
$md[] = '## Streams, `pre+out` (legitimate denied / best-response attack reach, U + P)';
$md[] = '';
$cols = ['S24', 'FULL2', 'FULL2-40', 'N-PG1', 'N-NORENEW', 'N-NOPWB', 'N-NOK5SEP', 'P-NETF', 'R-FULL', 'R-FULL-40', 'R-NO-BIND', 'R-NO-FCN', 'R-NO-TB', 'R-NO-K5SEP', 'R-ESC', 'R-WEAKBIND'];
$md[] = '| Stream | S0 | ' . implode(' | ', $cols) . ' |';
$md[] = '| --- | --- |' . str_repeat(' --- |', count($cols));
foreach (array_keys($B['FULL2']) as $s) {
    $line = "| $s | {$S0[$s]['legit']} / {$S0[$s]['reach']} |";
    foreach ($cols as $a) {
        $line .= " {$B[$a][$s]['legit']} / {$B[$a][$s]['reach']} |";
    }
    $md[] = $line;
}
$md[] = '';
$md[] = '## Streams, `pre` (checkOnly-only Hosts)';
$md[] = '';
$md[] = '| Stream | S24 pre | ' . implode(' | ', array_map(static fn(string $a): string => "$a pre", $preArms)) . ' |';
$md[] = '| --- | --- |' . str_repeat(' --- |', count($preArms));
foreach (array_keys($B['FULL2 pre']) as $s) {
    $line = '| ' . $s . ' | ' . (isset($B['S24pre'][$s]) ? "{$B['S24pre'][$s]['legit']} / {$B['S24pre'][$s]['reach']}" : '—') . ' |';
    foreach ($preArms as $a) {
        $line .= " {$B["$a pre"][$s]['legit']} / {$B["$a pre"][$s]['reach']} |";
    }
    $md[] = $line;
}
$md[] = '';
$md[] = '## Legitimate denial causes, `pre+out` (streams with denials)';
$md[] = '';
$md[] = '| Stream | FULL2 | R-FULL |';
$md[] = '| --- | --- | --- |';
foreach ($B['FULL2'] as $s => $x) {
    if ($x['legit'] > 0 || $B['R-FULL'][$s]['legit'] > 0) {
        $md[] = "| $s | {$x['legit']} " . json_encode($x['causes'], JSON_UNESCAPED_SLASHES) . " | {$B['R-FULL'][$s]['legit']} " . json_encode($B['R-FULL'][$s]['causes'], JSON_UNESCAPED_SLASHES) . ' |';
    }
}
$md[] = '';
$md[] = '## Fixtures (pipeline, as planned)';
$md[] = '';
$fxArms = array_merge($nArms, $rArms);
$md[] = '| Fixture | ' . implode(' | ', $fxArms) . ' |';
$md[] = '| --- |' . str_repeat(' --- |', count($fxArms));
$line = static function (string $label, callable $f) use ($fxArms, &$md): void {
    $md[] = "| $label | " . implode(' | ', array_map($f, $fxArms)) . ' |';
};
$line('HAR-B owner denied / attack reach', static fn(string $a): string => "{$F['HAR-B'][$a]['m']['legitDenied']} / {$F['HAR-B'][$a]['m']['reachForeign']}");
$line('HAR-N owner denied / attack reach', static fn(string $a): string => "{$F['HAR-N'][$a]['m']['legitDenied']} / {$F['HAR-N'][$a]['m']['reachForeign']}");
$line('BRU24 admitted (recount)', static fn(string $a): string => "{$F['BRU24'][$a]['attackAdmitted']} ({$F['BRU24'][$a]['tbTakesRecount']})");
$line('BRU24-HV admitted', static fn(string $a): string => (string) $F['BRU24-HV'][$a]['attackAdmitted']);
foreach ($deltas as $x) {
    $line("REC wait, δ = $x", static fn(string $a): string => (string) ($F['REC'][$a]['waitFromArrival'][(string) $x] ?? 'never'));
}
$line('CROSS victim reach / P', static fn(string $a): string => "{$F['CROSS'][$a]['victimReach']} / {$F['CROSS'][$a]['victimPAdmitted']}");
$line('STEAL thief P max / window', static fn(string $a): string => (string) $F['STEAL'][$a]['thiefPMaxPerWindow']);
$line('STEAL thief P before / after the change (revoke)', static fn(string $a): string => ($F['STEAL'][$a]['thiefPBeforeChange'] ?? $F['STEAL'][$a]['thiefPBeforeRevoke']) . ' / ' . ($F['STEAL'][$a]['thiefPAfterChange'] ?? $F['STEAL'][$a]['thiefPAfterRevoke']));
$line('STEAL thief U after', static fn(string $a): string => (string) ($F['STEAL'][$a]['thiefUAfterChange'] ?? $F['STEAL'][$a]['thiefUAfterRevoke']));
$line('STEAL owner denied before', static fn(string $a): string => (string) ($F['STEAL'][$a]['ownerDeniedBeforeChange'] ?? $F['STEAL'][$a]['ownerDeniedBeforeRevoke']));
$line('STEAL owner wait after (lane)', static fn(string $a): string => array_key_exists('ownerWaitAfterChange', $F['STEAL'][$a])
    ? ($F['STEAL'][$a]['ownerWaitAfterChange'] ?? 'never') . ' (' . ($F['STEAL'][$a]['ownerLaneAtAdmission'] ?? '—') . ')'
    : ($F['STEAL'][$a]['ownerWaitAfterRevoke'] ?? 'never') . ' (U)');
$line('CLEAR denied', static fn(string $a): string => (string) $F['CLEAR'][$a]['m']['legitDenied']);
$line('E2 identical', static fn(string $a): string => $F['E2'][$a]['identical'] ? 'yes' : 'no');
$line('K5U identical from 960 s', static fn(string $a): string => isset($F['K5U'][$a]) ? ($F['K5U'][$a]['identicalFrom960'] ? 'yes' : 'no') : '—');
$md[] = '';
$md[] = '### E2 owner rows (decisions / lanes; population vs control)';
$md[] = '';
$md[] = '| Arm | Population: decisions | lanes | causes | Control: decisions | lanes | causes |';
$md[] = '| --- | --- | --- | --- | --- | --- | --- |';
foreach ($fxArms as $a) {
    $e = $F['E2'][$a];
    $md[] = "| $a | `{$e['pop']['ownerDec']}` | `{$e['pop']['ownerLanes']}` | " . json_encode($e['pop']['ownerDeniedByCause'], JSON_UNESCAPED_SLASHES)
        . " | `{$e['ctl']['ownerDec']}` | `{$e['ctl']['ownerLanes']}` | " . json_encode($e['ctl']['ownerDeniedByCause'], JSON_UNESCAPED_SLASHES) . ' |';
}
$md[] = '';
$md[] = '### K5U owner rows from 960 s (decisions / lanes; `fail` vs `ctl`)';
$md[] = '';
$md[] = '| Arm | fail: decisions | lanes | causes (all rows) | ctl: decisions | lanes | causes (all rows) |';
$md[] = '| --- | --- | --- | --- | --- | --- | --- |';
foreach ($nArms as $a) {
    $e = $F['K5U'][$a];
    $md[] = "| $a | `{$e['fail']['from960Dec']}` | `{$e['fail']['from960Lanes']}` | " . json_encode($e['fail']['ownerDeniedByCause'], JSON_UNESCAPED_SLASHES)
        . " | `{$e['ctl']['from960Dec']}` | `{$e['ctl']['from960Lanes']}` | " . json_encode($e['ctl']['ownerDeniedByCause'], JSON_UNESCAPED_SLASHES) . ' |';
}
$md[] = '';
$md[] = '### BOOT / BOOT-X (legitimate attempts / refused per day)';
$md[] = '';
$md[] = '| Fixture | Pair | Arm | Day 1 | Day 2 | Day 3 | Bound-instance refusals | NO-BIND refusals where bound (day 1) |';
$md[] = '| --- | --- | --- | --- | --- | --- | --- | --- |';
foreach (['BOOT', 'BOOT-X'] as $f) {
    foreach (['FULL2+R-NO-BIND', 'R-FULL+R-NO-BIND'] as $pair) {
        $x = $F[$f][$pair];
        foreach ($x['perDay'] as $arm => $p) {
            $md[] = "| $f | $pair | $arm | {$p[0]['n']} / {$p[0]['refused']} | {$p[1]['n']} / {$p[1]['refused']} | {$p[2]['n']} / {$p[2]['refused']} | {$x['fullBoundRefused']} | {$x['noBindRefusedWhereFullBound']} |";
        }
    }
}
$md[] = '';
$md[] = '## CGN-B (`MODEL`, 4 replications; per day: attempts / refused (share); bound attempts / bound refused)';
$md[] = '';
$md[] = '| Arm | N | λ | Sprayer | Day 1 | Day 2 | Day 3 | Bound at end |';
$md[] = '| --- | --- | --- | --- | --- | --- | --- | --- |';
foreach ($cgn as $r) {
    $cells = array_map(static fn(array $x): string => "{$x['attempts']} / {$x['refused']} ({$x['refusedShare']}); {$x['boundAttempts']} / {$x['boundRefused']}", $r['days']);
    $md[] = "| {$r['arm']} | {$r['n']} | {$r['lambda']} | " . ($r['sprayer'] ? 'yes' : 'no') . ' | ' . implode(' | ', $cells) . " | {$r['boundAtEnd']} |";
}
$md[] = '';
foreach (['Part N necessity (against FULL2)' => $needN, 'Part R necessity (against R-FULL, G4 expectations)' => $needR] as $title => $need) {
    $md[] = "## $title";
    $md[] = '';
    $md[] = '| Arm | Registered expectation | Worse | Better | Necessity |';
    $md[] = '| --- | --- | --- | --- | --- |';
    foreach ($need as $a => $n) {
        $md[] = "| $a | {$n['expected']} | " . ($n['worse'] === [] ? '—' : implode('; ', $n['worse'])) . ' | '
            . ($n['better'] === [] ? '—' : implode('; ', $n['better'])) . ' | ' . ($n['shown'] ? 'shown' : 'not shown necessary') . ' |';
    }
    $md[] = '';
}
$md[] = '## Q4: FULL2 (U_src 24) against FULL2-40, reported without a winner (rows that differ)';
$md[] = '';
$md[] = '| Stream | Flow | FULL2 | FULL2-40 |';
$md[] = '| --- | --- | --- | --- |';
foreach (['pre+out' => ['FULL2', 'FULL2-40'], 'pre' => ['FULL2 pre', 'FULL2-40 pre']] as $flow => [$x, $y]) {
    foreach ($B[$x] as $s => $v) {
        $w = $B[$y][$s];
        if ($v['legit'] !== $w['legit'] || $v['reach'] !== $w['reach']) {
            $md[] = "| $s | $flow | {$v['legit']} / {$v['reach']} | {$w['legit']} / {$w['reach']} |";
        }
    }
}
foreach (['5000/1/-', '5000/1/X', '5000/4/-', '500/4/-'] as $k) {
    $md[] = "| CGN-B $k (`MODEL`) | — | day refusals " . implode(', ', array_column($C['FULL2'][$k]['days'], 'refused')) . ' | day refusals ' . implode(', ', array_column($C['FULL2-40'][$k]['days'], 'refused')) . ' |';
}
$md[] = '';
$md[] = '## P-NETF (reported only, PLAN.md §2.2)';
$md[] = '';
$pn = $common('P-NETF', 'R-NO-BIND', 'R-WEAKBIND', 'FULL2+R-NO-BIND');
$md[] = '| Gate (FULL2 definition) | P-NETF |';
$md[] = '| --- | --- |';
foreach (['A1', 'A2', 'A3', 'B1', 'B2', 'C2', 'P'] as $id) {
    $md[] = "| $id | " . ($pn[$id]['pass'] ? 'pass' : 'fail') . ': ' . implode('; ', $pn[$id]['detail']) . ' |';
}
$md[] = '';

file_put_contents("$outDir/summary.md", implode("\n", $md));
file_put_contents("$outDir/gates.json", json_encode(['partN' => ['verdict' => $nVerdict, 'gateP' => $pResult, 'gates' => $N, 'necessity' => $needN],
    'partR' => ['verdict' => $rVerdict, 'gates' => $R, 'necessity' => $needR, 'changesFromG4' => $changes, 'rowChangesFromG4' => $rowChanges],
    'validity' => ['V0' => count($v0), 'V1' => $v1n, 'D-IN' => $din]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
foreach (['N' => $N, 'R' => $R] as $p => $gates) {
    foreach ($gates as $id => $g) {
        printf("%s %-5s %s%s\n", $p, $id, $g['pass'] ? 'PASS' : 'FAIL', isset($g['detector']) ? ' (detector ' . ($g['detector']['fired'] ? 'fired' : 'NOT fired') . ')' : '');
    }
}
echo "Part N: $nVerdict; gate P: $pResult\nPart R: $rVerdict\nchanges from G4: " . count($changes) . ' aggregate, ' . count($rowChanges) . " of $rowCount stream rows\n";
exit($nFailed === [] ? 0 : 3);
