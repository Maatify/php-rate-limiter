<?php

declare(strict_types=1);

// G4 report (PLAN.md §4, §5): V0 against the G3 v2 archive, gates, detectors, necessity, Q4; writes summary.md and gates.json.
// Exit 5 = V0 miss; 3 = harness valid and a gate fails or a detector does not fire; 0 = every gate passes.
//   php report.php <actual-dir> <out-dir>

$in = $argv[1] ?? '';
$outDir = $argv[2] ?? '';
$g3 = dirname(__DIR__, 2) . '/g3';

/** @return list<array<string, mixed>> */
function rows(string $file): array
{
    $out = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $out[] = json_decode($l, true, 512, JSON_THROW_ON_ERROR);
    }

    return $out;
}

$streams = array_merge(...array_map(static fn(string $b): array => rows("$in/g4_streams.$b.jsonl"), ['G4', 'NOESC', 'C5']));
$v0 = rows("$in/g4_streams.CAND.jsonl");
$fx = array_merge(...array_map(static fn(string $b): array => rows("$in/g4_fixtures.$b.jsonl"), ['G4', 'NOESC', 'C5']));
$cgn = rows("$in/g4_cgn.G4.jsonl");
$v2 = rows("$g3/admission_budgets_v2/expected/v2_fc.CAND.jsonl");

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
            $miss[] = "$k $f";
        }
    }
}
if (count($v0) !== count($arch)) {
    $miss[] = 'row count ' . count($v0) . ' vs archive ' . count($arch);
}
if ($miss !== []) {
    fwrite(STDOUT, "V0 MISS\n" . implode("\n", $miss) . "\n");
    exit(5);
}

// ---- per-stream aggregates (best response over strategies and id policies) ----------------------------------------------------
function best(array $rows, string $arm, string $flow): array
{
    $o = [];
    foreach ($rows as $r) {
        if (($r['arm'] ?? null) !== $arm || ($r['flow'] ?? null) !== $flow || ! isset($r['m'])) {
            continue;
        }
        $c = $o[$r['stream']] ?? ['legit' => 0, 'reach' => 0, 'p' => 0, 'causes' => []];
        $c['legit'] = max($c['legit'], $r['m']['legitDenied']);
        $c['reach'] = max($c['reach'], $r['m']['reachForeign'] + $r['m']['reachProof']);
        $c['p'] = max($c['p'], $r['m']['reachProof']);
        if ($r['m']['legitDenied'] >= $c['legit']) {
            $c['causes'] = $r['legitDeniedByCause'];
        }
        $o[$r['stream']] = $c;
    }

    return $o;
}

$S0 = [];
foreach (rows("$g3/admission_budgets/expected/s00_s0.jsonl") as $r) {
    $c = $S0[$r['stream']] ?? ['legit' => 0, 'reach' => 0];
    $c['legit'] = max($c['legit'], $r['m']['legitDenied']);
    $c['reach'] = max($c['reach'], $r['m']['reachForeign']);
    $S0[$r['stream']] = $c;
}
$B = ['S24' => best($v0, 'S24', 'pre+out'), 'S12' => best($v2, 'S12', 'pre+out'), 'S24pre' => best($v2, 'S24', 'pre')];
$arms = ['FULL', 'FULL-40', 'NO-BIND', 'NO-FCN', 'NO-TB', 'NO-K5SEP', 'ESC', 'WEAKBIND'];
$preArms = ['FULL', 'FULL-40', 'NO-BIND', 'NO-TB'];
foreach ($arms as $a) {
    $B[$a] = best($streams, $a, 'pre+out');
}
foreach ($preArms as $a) {
    $B["$a pre"] = best($streams, $a, 'pre');
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

// ---- gates ---------------------------------------------------------------------------------------------------------------------
$gates = [];
$gate = static function (string $id, bool $pass, array $detail, ?array $det = null) use (&$gates): void {
    $gates[$id] = ['pass' => $pass, 'detail' => $detail] + ($det === null ? [] : ['detector' => $det]);
};
$gate('V0', true, [count($v0) . ' rows equal the G3 v2 S24 archive field for field']);

$d = [];
foreach ($benign as $s) {
    $v = $B['FULL'][$s]['legit'];
    if ($v > $S0[$s]['legit'] || $v > $B['S24'][$s]['legit']) {
        $d[] = "$s $v > " . ($v > $S0[$s]['legit'] ? "S0 {$S0[$s]['legit']}" : "S24 {$B['S24'][$s]['legit']}") . ' (' . json_encode($B['FULL'][$s]['causes']) . ')';
    }
}
$gate('A1', $d === [], $d === [] ? ['every benign stream ≤ S0 and ≤ S24'] : $d);
$gate('A2', $B['FULL']['MIX']['legit'] <= $S0['MIX']['legit'], ["MIX legit {$B['FULL']['MIX']['legit']} (S0 {$S0['MIX']['legit']})"]);
$d = [];
foreach ($spray as $s) {
    $d[] = "$s {$B['FULL'][$s]['reach']} (S12 {$B['S12'][$s]['reach']})" . ($B['FULL'][$s]['reach'] > $B['S12'][$s]['reach'] ? ' FAIL' : '');
}
$gate('A3', ! str_contains(implode('', $d), 'FAIL'), $d);
$b = $F['BRU24']['FULL'];
$gate('B1', $b['attackAdmitted'] <= 52 && $b['attackAdmitted'] === $b['tbTakesRecount'], ["admitted {$b['attackAdmitted']}, recount {$b['tbTakesRecount']}, ceiling 52"]);
$d = [];
$ok = true;
foreach ($deltas as $x) {
    $w = $F['REC']['FULL']['waitFromArrival'][(string) $x] ?? null;
    $lim = max(0, 1860 - $x);
    $ok = $ok && $w !== null && $w <= $lim;
    $d[] = "δ $x: " . ($w ?? 'never') . " ≤ $lim";
}
$gate('B2', $ok, $d);
$gate('C1', $F['HAR-B']['FULL']['m']['legitDenied'] === 0, ["owner denials {$F['HAR-B']['FULL']['m']['legitDenied']}"],
    ['fired' => $F['HAR-B']['NO-BIND']['m']['legitDenied'] >= 1, 'detail' => "NO-BIND owner denials {$F['HAR-B']['NO-BIND']['m']['legitDenied']}"]);
$gate('C2', $F['BRU24-HV']['FULL']['attackAdmitted'] <= 52, ["admitted {$F['BRU24-HV']['FULL']['attackAdmitted']}, ceiling 52 (G3 v2 TBK30R-HV: 5,760)"]);
$c = $F['CROSS'];
$gate('C3', $c['FULL']['victimPAdmitted'] === 0 && $c['FULL']['victimReach'] <= $c['NO-BIND']['victimReach'],
    ["victim P admissions {$c['FULL']['victimPAdmitted']}; victim reach {$c['FULL']['victimReach']} (NO-BIND {$c['NO-BIND']['victimReach']})"],
    ['fired' => $c['WEAKBIND']['victimPAdmitted'] >= 1, 'detail' => "WEAKBIND victim P admissions {$c['WEAKBIND']['victimPAdmitted']}"]);
$st = $F['STEAL']['FULL'];
$stDay = $st['thiefPBeforeRevoke'] + $st['thiefPAfterRevoke'];
$gate('C4', $st['thiefPMaxPerWindow'] <= 6 && $stDay <= 30 && $st['thiefPAfterRevoke'] === 0 && $st['ownerWaitAfterRevoke'] !== null && $st['ownerWaitAfterRevoke'] <= 1800, [
    "thief P per window max {$st['thiefPMaxPerWindow']} ≤ 6; thief P in 24 h $stDay ≤ 30; thief P after the bump {$st['thiefPAfterRevoke']}",
    'owner first admitted U login after the bump: ' . ($st['ownerWaitAfterRevoke'] === null ? 'never within the fixture (FAIL)' : "after {$st['ownerWaitAfterRevoke']} s"),
]);
$bo = $F['BOOT']['FULL+NO-BIND'];
$bx = $F['BOOT-X']['FULL+NO-BIND'];
$sum = static fn(array $days, string $k): int => array_sum(array_column($days, $k));
$bootShare = $sum($bo['perDay']['FULL'], 'refused') / max(1, $sum($bo['perDay']['FULL'], 'n'));
$gate('C5', $bootShare <= 0.01 && $bx['fullBoundRefused'] === 0,
    ['BOOT refusals ' . $sum($bo['perDay']['FULL'], 'refused') . ' / ' . $sum($bo['perDay']['FULL'], 'n'), "BOOT-X refusals of bound instances {$bx['fullBoundRefused']}"],
    ['fired' => $bx['noBindRefusedWhereFullBound'] >= 1, 'detail' => "NO-BIND refused {$bx['noBindRefusedWhereFullBound']} day-1 requests from instances FULL had bound"]);
$cn = $C['FULL']['5000/1/-'];
$cx = $C['FULL']['5000/1/X'];
$gate('C6', $cn['days'][2]['refusedShare'] <= 0.01 && $sum($cx['days'], 'boundRefused') === 0,
    ["day-3 refusal share without sprayer {$cn['days'][2]['refusedShare']}", 'bound refusals with sprayer ' . $sum($cx['days'], 'boundRefused')]);
$gate('E', $F['E2']['FULL']['identical'], ['owner decisions / lanes identical with and without population: ' . ($F['E2']['FULL']['identical'] ? 'yes' : 'no')],
    ['fired' => ! $F['E2']['NO-K5SEP']['identical'], 'detail' => 'NO-K5SEP differs: ' . ($F['E2']['NO-K5SEP']['identical'] ? 'no' : 'yes')]);
$gate('P', $B['FULL pre']['L1']['legit'] <= 0 && $B['FULL pre']['D2']['legit'] <= 36,
    ["pre L1 {$B['FULL pre']['L1']['legit']} ≤ 0, D2 {$B['FULL pre']['D2']['legit']} ≤ 36"]);

$failed = [];
foreach ($gates as $id => $g) {
    if (! $g['pass'] || (isset($g['detector']) && ! $g['detector']['fired'])) {
        $failed[] = $id . (! $g['pass'] ? '' : ' (detector)');
    }
}
$verdict = $failed === [] ? 'EXPERIMENTALLY SUPPORTED — LIMITED SCOPE' : 'NO-GO AFTER EXPERIMENT (' . implode(', ', $failed) . ')';

// ---- necessity (Q3) -------------------------------------------------------------------------------------------------------------
$measures = static function (string $a) use ($B, $F, $C, $benign, $spray, $deltas, $sum, $preArms): array {
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
    $m['STEAL thief P / window'] = $F['STEAL'][$a]['thiefPMaxPerWindow'];
    $m['STEAL thief P after bump'] = $F['STEAL'][$a]['thiefPAfterRevoke'];
    $m['STEAL owner wait'] = $F['STEAL'][$a]['ownerWaitAfterRevoke'] ?? PHP_INT_MAX;
    $m['E2 differs'] = $F['E2'][$a]['identical'] ? 0 : 1;
    if (in_array($a, $preArms, true)) {
        $m['pre legit L1'] = $B["$a pre"]['L1']['legit'];
        $m['pre legit D2'] = $B["$a pre"]['D2']['legit'];
        $m['pre reach BRU'] = $B["$a pre"]['BRU']['reach'];
    }
    if (in_array($a, ['FULL', 'NO-BIND'], true)) {
        $bx = $F['BOOT-X']['FULL+NO-BIND'];
        $m['BOOT refusals'] = $sum($F['BOOT']['FULL+NO-BIND']['perDay'][$a], 'refused');
        $m['BOOT-X refusals'] = $sum($bx['perDay'][$a], 'refused');
        $m['BOOT-X refusals of FULL-bound instances'] = $a === 'FULL' ? $bx['fullBoundRefused'] : $bx['noBindRefusedWhereFullBound'];
    }
    if (isset($C[$a])) {
        $m['CGN 5000/1 day-3 refused'] = $C[$a]['5000/1/-']['days'][2]['refused'];
        $m['CGN 5000/1 + sprayer, day-2 refused'] = $C[$a]['5000/1/X']['days'][1]['refused'];
    }

    return $m;
};
$fmt = static fn(int|float $v): string => $v === PHP_INT_MAX ? 'never' : (string) $v;
$full = $measures('FULL');
$need = [];
foreach (['NO-BIND' => 'C1, C5, C6', 'NO-FCN' => 'A3', 'NO-TB' => 'B1, pre BRU reach', 'NO-K5SEP' => 'E', 'ESC' => 'B2'] as $a => $exp) {
    $worse = [];
    $better = [];
    foreach ($measures($a) as $k => $v) {
        if (! array_key_exists($k, $full)) {
            continue;
        }
        if ($v > $full[$k]) {
            $worse[] = "$k {$fmt($v)} (FULL {$fmt($full[$k])})";
        } elseif ($v < $full[$k]) {
            $better[] = "$k {$fmt($v)} (FULL {$fmt($full[$k])})";
        }
    }
    $need[$a] = ['expected' => $exp, 'worse' => $worse, 'better' => $better, 'shown' => $worse !== []];
}

// ---- summary.md ----------------------------------------------------------------------------------------------------------------
$md = ['# G4 integrated assembly — generated summary', '', "Verdict (PLAN.md §5): **$verdict**", '', '## Gates', '',
    '| Gate | Result | Detail | Detector |', '| --- | --- | --- | --- |'];
foreach ($gates as $id => $g) {
    $det = isset($g['detector']) ? ($g['detector']['fired'] ? 'fired: ' : 'NOT fired: ') . $g['detector']['detail'] : '—';
    $md[] = "| $id | " . ($g['pass'] ? 'PASS' : 'FAIL') . ' | ' . implode('; ', $g['detail']) . " | $det |";
}
$md[] = '';
$md[] = '## Streams, `pre+out` (legitimate denied / best-response attack reach, U + P)';
$md[] = '';
$cols = ['S24', 'FULL', 'FULL-40', 'NO-BIND', 'NO-FCN', 'NO-TB', 'NO-K5SEP', 'ESC', 'WEAKBIND'];
$md[] = '| Stream | S0 | ' . implode(' | ', $cols) . ' |';
$md[] = '| --- | --- |' . str_repeat(' --- |', count($cols));
foreach (array_keys($B['FULL']) as $s) {
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
foreach (array_keys($B['FULL pre']) as $s) {
    $line = '| ' . $s . ' | ' . (isset($B['S24pre'][$s]) ? "{$B['S24pre'][$s]['legit']} / {$B['S24pre'][$s]['reach']}" : '—') . ' |';
    foreach ($preArms as $a) {
        $line .= " {$B["$a pre"][$s]['legit']} / {$B["$a pre"][$s]['reach']} |";
    }
    $md[] = $line;
}
$md[] = '';
$md[] = '## Legitimate denial causes, `FULL` `pre+out` (streams with denials)';
$md[] = '';
$md[] = '| Stream | Denied | Causes |';
$md[] = '| --- | --- | --- |';
foreach ($B['FULL'] as $s => $x) {
    if ($x['legit'] > 0) {
        $md[] = "| $s | {$x['legit']} | " . json_encode($x['causes'], JSON_UNESCAPED_SLASHES) . ' |';
    }
}
$md[] = '';
$md[] = '## Fixtures';
$md[] = '';
$fxArms = ['FULL', 'FULL-40', 'NO-BIND', 'NO-FCN', 'NO-TB', 'NO-K5SEP', 'ESC', 'WEAKBIND'];
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
$line('STEAL thief P before / after bump', static fn(string $a): string => "{$F['STEAL'][$a]['thiefPBeforeRevoke']} / {$F['STEAL'][$a]['thiefPAfterRevoke']}");
$line('STEAL thief U after bump', static fn(string $a): string => (string) $F['STEAL'][$a]['thiefUAfterRevoke']);
$line('STEAL owner denied before bump', static fn(string $a): string => (string) $F['STEAL'][$a]['ownerDeniedBeforeRevoke']);
$line('STEAL owner wait after bump (+5 s)', static fn(string $a): string => ($F['STEAL'][$a]['ownerWaitAfterRevoke'] ?? 'never') . ' (' . ($F['STEAL'][$a]['ownerWaitOffset5'] ?? 'never') . ')');
$line('CLEAR denied', static fn(string $a): string => (string) $F['CLEAR'][$a]['m']['legitDenied']);
$line('E2 identical', static fn(string $a): string => $F['E2'][$a]['identical'] ? 'yes' : 'no');
$md[] = '';
$md[] = '### E2 owner rows (decision / lane per owner row; population vs control)';
$md[] = '';
$md[] = '| Arm | Population: decisions | lanes | Control: decisions | lanes |';
$md[] = '| --- | --- | --- | --- | --- |';
foreach ($fxArms as $a) {
    $e = $F['E2'][$a];
    $md[] = "| $a | `{$e['pop']['ownerDec']}` | `{$e['pop']['ownerLanes']}` | `{$e['ctl']['ownerDec']}` | `{$e['ctl']['ownerLanes']}` |";
}
$md[] = '';
$md[] = '### BOOT / BOOT-X (legitimate attempts / refused per day)';
$md[] = '';
$md[] = '| Fixture | Arm | Day 1 | Day 2 | Day 3 | Causes |';
$md[] = '| --- | --- | --- | --- | --- | --- |';
foreach (['BOOT', 'BOOT-X'] as $f) {
    foreach (['FULL', 'NO-BIND'] as $a) {
        $p = $F[$f]['FULL+NO-BIND']['perDay'][$a];
        $cs = $F[$f]['FULL+NO-BIND'][$a === 'FULL' ? 'fullCauses' : 'noBindCauses'];
        $md[] = "| $f | $a | {$p[0]['n']} / {$p[0]['refused']} | {$p[1]['n']} / {$p[1]['refused']} | {$p[2]['n']} / {$p[2]['refused']} | " . json_encode($cs, JSON_UNESCAPED_SLASHES) . ' |';
    }
}
$md[] = '';
$md[] = "BOOT-X: FULL refusals of bound instances {$bx['fullBoundRefused']}; NO-BIND refusals of day-1 requests from instances FULL had bound {$bx['noBindRefusedWhereFullBound']}; sprayer reach under FULL {$bx['fullAttackReach']}.";
$md[] = '';
$md[] = '## CGN-B (4 replications; per day: attempts / refused (refused share); bound attempts / bound refused)';
$md[] = '';
$md[] = '| Arm | N | λ | Sprayer | Day 1 | Day 2 | Day 3 | Bound at end |';
$md[] = '| --- | --- | --- | --- | --- | --- | --- | --- |';
foreach ($cgn as $r) {
    $cells = array_map(static fn(array $x): string => "{$x['attempts']} / {$x['refused']} ({$x['refusedShare']}); {$x['boundAttempts']} / {$x['boundRefused']}", $r['days']);
    $md[] = "| {$r['arm']} | {$r['n']} | {$r['lambda']} | " . ($r['sprayer'] ? 'yes' : 'no') . ' | ' . implode(' | ', $cells) . " | {$r['boundAtEnd']} |";
}
$md[] = '';
$md[] = '## Necessity (Q3): registered measurements that worsen when a component is removed';
$md[] = '';
$md[] = '| Arm | Registered expectation | Worse than FULL | Better than FULL | Necessity |';
$md[] = '| --- | --- | --- | --- | --- |';
foreach ($need as $a => $n) {
    $md[] = "| $a | {$n['expected']} | " . ($n['worse'] === [] ? '—' : implode('; ', $n['worse'])) . ' | '
        . ($n['better'] === [] ? '—' : implode('; ', $n['better'])) . ' | ' . ($n['shown'] ? 'shown' : 'not shown necessary') . ' |';
}
$md[] = '';
$md[] = '## Q4: FULL (U_src 24) against FULL-40 (streams where they differ)';
$md[] = '';
$md[] = '| Stream | Flow | FULL | FULL-40 |';
$md[] = '| --- | --- | --- | --- |';
foreach (['pre+out' => ['FULL', 'FULL-40'], 'pre' => ['FULL pre', 'FULL-40 pre']] as $flow => [$x, $y]) {
    foreach ($B[$x] as $s => $v) {
        $w = $B[$y][$s];
        if ($v['legit'] !== $w['legit'] || $v['reach'] !== $w['reach']) {
            $md[] = "| $s | $flow | {$v['legit']} / {$v['reach']} | {$w['legit']} / {$w['reach']} |";
        }
    }
}
foreach (['5000/1/-', '5000/1/X', '5000/4/-', '500/4/-'] as $k) {
    $md[] = "| CGN-B $k | — | day refusals " . implode(', ', array_column($C['FULL'][$k]['days'], 'refused')) . ' | day refusals ' . implode(', ', array_column($C['FULL-40'][$k]['days'], 'refused')) . ' |';
}
$md[] = '';

file_put_contents("$outDir/summary.md", implode("\n", $md));
file_put_contents("$outDir/gates.json", json_encode(['verdict' => $verdict, 'gates' => $gates, 'necessity' => $need],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
foreach ($gates as $id => $g) {
    printf("%-4s %s%s\n", $id, $g['pass'] ? 'PASS' : 'FAIL', isset($g['detector']) ? ' (detector ' . ($g['detector']['fired'] ? 'fired' : 'NOT fired') . ')' : '');
}
echo "verdict: $verdict\n";
exit($failed === [] ? 0 : 3);
