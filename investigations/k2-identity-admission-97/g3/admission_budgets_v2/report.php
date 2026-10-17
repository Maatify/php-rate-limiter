<?php

declare(strict_types=1);

// G3 v2 report (PLAN.md §5, §6): V0 against the v1 archive, gates, detectors, verdicts; writes summary.md and gates.json.
// Exit 5 = V0 miss; 3 = harness valid and a gate fails or a detector does not fire; 0 = every gate passes.
//   php report.php <actual-dir> <out-dir>

$in = $argv[1] ?? '';
$outDir = $argv[2] ?? '';
$v1 = dirname(__DIR__) . '/admission_budgets/expected';

/** @return list<array<string, mixed>> */
function rows(string $file): array
{
    $out = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $out[] = json_decode($l, true, 512, JSON_THROW_ON_ERROR);
    }

    return $out;
}

$fc = rows("$in/v2_fc.CAND.jsonl");
$tb = rows("$in/v2_tb.NOESC.jsonl");
$f = ['dec', 'reach', 'kinds', 'lanes', 'legitDeniedByCause'];

// ---- V0 ------------------------------------------------------------------------------------------------------------------------
$miss = [];
$idx = static function (array $rows, callable $keep): array {
    $o = [];
    foreach ($rows as $r) {
        if ($keep($r)) {
            $o[$r['arm'] . '|' . $r['flow'] . '|' . $r['stream'] . '|' . $r['strategy']] = $r;
        }
    }

    return $o;
};
$v1s01 = $idx(rows("$v1/s01_sweep.CAND.jsonl"), static fn(array $r): bool => $r['s'] === 's01');
$checked = 0;
foreach ($fc as $r) {
    $arm = $r['arm'] === 'FC4-40' ? 'FC4' : $r['arm'];
    if (! in_array($arm, ['S12', 'S24', 'S40', 'FC4'], true)) {
        continue;
    }
    $k = $arm . '|' . $r['flow'] . '|' . $r['stream'] . '|' . $r['strategy'];
    if (! isset($v1s01[$k])) {
        continue; // flows v1 did not run (S24 pre) are new rows, not V0 rows
    }
    $checked++;
    foreach ($f as $x) {
        if ($v1s01[$k][$x] !== $r[$x]) {
            $miss[] = "fc $k $x";
        }
    }
}
$v1s03 = rows("$v1/s03_tb.NOESC.jsonl");
$v1tb = $idx($v1s03, static fn(array $r): bool => $r['fixture'] === 'stream' && $r['arm'] === 'TB30R');
foreach ($tb as $r) {
    if ($r['arm'] !== 'TB30R') {
        continue;
    }
    if ($r['fixture'] === 'stream') {
        $k = 'TB30R|' . $r['flow'] . '|' . $r['stream'] . '|' . $r['strategy'];
        $checked++;
        foreach ($f as $x) {
            if (! isset($v1tb[$k]) || $v1tb[$k][$x] !== $r[$x]) {
                $miss[] = "tb $k $x";
            }
        }
    } else {
        foreach ($v1s03 as $o) {
            if ($o['arm'] === 'TB30R' && $o['fixture'] === $r['fixture'] && ($o['delta'] ?? null) === ($r['delta'] ?? null)) {
                $checked++;
                $a = $r['fixture'] === 'BRU24' ? 'attackAdmitted' : 'admittedAfterAttackEnd';
                if ($o[$a] !== $r[$a]) {
                    $miss[] = "tb {$r['fixture']} " . ($r['delta'] ?? '');
                }
            }
        }
    }
}

// ---- per-stream best response --------------------------------------------------------------------------------------------------
function best(array $rows, string $arm, string $flow): array
{
    $o = [];
    foreach ($rows as $r) {
        if (($r['arm'] ?? null) !== $arm || ($r['flow'] ?? null) !== $flow || ! isset($r['m'])) {
            continue;
        }
        $c = $o[$r['stream']] ?? ['legit' => 0, 'reach' => 0];
        $c['legit'] = max($c['legit'], $r['m']['legitDenied']);
        $c['reach'] = max($c['reach'], $r['m']['reachForeign']);
        $o[$r['stream']] = $c;
    }

    return $o;
}

function decs(array $rows, string $arm, string $flow): array
{
    $o = [];
    foreach ($rows as $r) {
        if (($r['arm'] ?? null) === $arm && ($r['flow'] ?? null) === $flow && isset($r['dec'])) {
            $o[$r['stream'] . '|' . $r['strategy']] = $r['dec'];
        }
    }
    ksort($o);

    return $o;
}

$S0 = [];
foreach (rows("$v1/s00_s0.jsonl") as $r) {
    $c = $S0[$r['stream']] ?? ['legit' => 0, 'reach' => 0];
    $c['legit'] = max($c['legit'], $r['m']['legitDenied']);
    $c['reach'] = max($c['reach'], $r['m']['reachForeign']);
    $S0[$r['stream']] = $c;
}
$B = [];
foreach (['S12', 'S24', 'S40', 'FCN4-24', 'FCN8-24', 'FCN4-40', 'FCN8-40', 'FC4-40'] as $a) {
    $B[$a] = best($fc, $a, 'pre+out');
}
foreach (['TB30R', 'TBK10R', 'TBK30R', 'TBK60R'] as $a) {
    $B[$a] = best($tb, $a, 'pre+out');
}
$benign = ['L1', 'ABA_B', 'D2', 'N12', 'Nu4', 'OFF12', 'Y6b', 'KD', 'UAC', 'L3', 'D1'];
$spray = ['SP1n', 'SP3', 'SP6', 'ABA_A'];
$gates = [];

// ---- FCN -----------------------------------------------------------------------------------------------------------------------
$fcnPass = [];
foreach (['FCN4-24' => 'S24', 'FCN8-24' => 'S24', 'FCN4-40' => 'S40', 'FCN8-40' => 'S40'] as $a => $ref) {
    $d1 = $d2 = $d3 = [];
    foreach ($spray as $s) {
        if ($B[$a][$s]['reach'] > $B['S12'][$s]['reach']) {
            $d1[] = "$s {$B[$a][$s]['reach']} > S12 {$B['S12'][$s]['reach']}";
        }
    }
    foreach ($benign as $s) {
        if ($B[$a][$s]['legit'] > $B[$ref][$s]['legit']) {
            $d2[] = "$s {$B[$a][$s]['legit']} > $ref {$B[$ref][$s]['legit']}";
        }
    }
    if ($B[$a]['MIX']['legit'] > $S0['MIX']['legit']) {
        $d3[] = "MIX legit {$B[$a]['MIX']['legit']} > S0 {$S0['MIX']['legit']}";
    }
    if ($B[$a]['MIX']['reach'] > $B[$ref]['MIX']['reach']) {
        $d3[] = "MIX attack {$B[$a]['MIX']['reach']} > $ref {$B[$ref]['MIX']['reach']}";
    }
    $gates["FCN-1 $a"] = ['pass' => $d1 === [], 'detail' => $d1];
    $gates["FCN-2 $a"] = ['pass' => $d2 === [], 'detail' => $d2];
    $gates["FCN-3 $a"] = ['pass' => $d3 === [], 'detail' => $d3 === [] ? ["MIX legit {$B[$a]['MIX']['legit']} (S0 {$S0['MIX']['legit']}), attack {$B[$a]['MIX']['reach']} ($ref {$B[$ref]['MIX']['reach']})"] : $d3];
    $pre = null;
    if (in_array($a, ['FCN4-24', 'FCN4-40'], true)) {
        $pre = decs($fc, $a, 'pre') === decs($fc, $ref, 'pre');
        $gates["FCN-4 $a"] = ['pass' => $pre, 'detail' => $pre ? ["pre identical to $ref"] : ["pre differs from $ref"]];
    }
    // PLAN.md §6 rule 2 needs FCN-4 too; it is registered only for the FCN4 arms, so FCN8 arms cannot be supported.
    $fcnPass[$a] = $d1 === [] && $d2 === [] && $d3 === [] && $pre === true;
}
$det1 = $B['FC4-40']['D2']['legit'] > $B['FCN4-40']['D2']['legit'];
$gates['FCN-2 detector FC4-40'] = ['pass' => $det1, 'detail' => ["D2 FC4-40 {$B['FC4-40']['D2']['legit']} vs FCN4-40 {$B['FCN4-40']['D2']['legit']}"]];

// ---- TBK -----------------------------------------------------------------------------------------------------------------------
$bru = [];
$rec = [];
$hv = null;
foreach ($tb as $r) {
    if ($r['fixture'] === 'BRU24') {
        $bru[$r['arm']] = $r;
    } elseif ($r['fixture'] === 'REC') {
        $rec[$r['arm']][$r['delta']] = $r;
    } elseif ($r['fixture'] === 'BRU24-HV') {
        $hv = $r;
    }
}
$det2 = $B['TB30R']['UAC']['legit'] > $B['S40']['UAC']['legit'];
$gates['TBK-3 detector TB30R'] = ['pass' => $det2, 'detail' => ["UAC TB30R {$B['TB30R']['UAC']['legit']} vs S40 {$B['S40']['UAC']['legit']}"]];
$tbkPass = [];
foreach (['TBK10R' => 600, 'TBK30R' => 1800, 'TBK60R' => 3600] as $a => $T) {
    $bound = 4 + intdiv(86400, $T);
    $adm = $bru[$a]['attackAdmitted'];
    $t1 = $adm <= $bound && $adm === $bru[$a]['tbTakesRecount'];
    $gates["TBK-1 $a"] = ['pass' => $t1, 'detail' => ["admitted $adm, bound $bound, recount {$bru[$a]['tbTakesRecount']}"]];
    $t2 = [];
    foreach ($rec[$a] as $d => $r) {
        $limit = max(0, $T + 60 - $d);
        if ($r['waitFromArrival'] === null || $r['waitFromArrival'] > $limit) {
            $t2[] = "delta $d: wait " . ($r['waitFromArrival'] ?? 'never') . " > $limit";
        }
    }
    $gates["TBK-2 $a"] = ['pass' => $t2 === [], 'detail' => $t2 === [] ? array_map(static fn($d, $r): string => "delta $d: wait {$r['waitFromArrival']}", array_keys($rec[$a]), $rec[$a]) : $t2];
    $t3 = [];
    foreach ($benign as $s) {
        if ($B[$a][$s]['legit'] > $B['S40'][$s]['legit']) {
            $t3[] = "$s {$B[$a][$s]['legit']} > S40 {$B['S40'][$s]['legit']}";
        }
    }
    $gates["TBK-3 $a"] = ['pass' => $t3 === [], 'detail' => $t3];
    $tbkPass[$a] = $t1 && $t2 === [] && $t3 === [] && $det2;
}
$fail = false;
foreach ($gates as $g) {
    $fail = $fail || ! $g['pass'];
}
$fcnSupported = $det1 ? array_keys(array_filter($fcnPass)) : [];

// ---- summary -------------------------------------------------------------------------------------------------------------------
$md = ['# G3 v2 — generated summary', '', 'Generated by `report.php`. Rules: `PLAN.md` §5–§6. Counts are requests.', '',
    '## V0', '', '| Check | Result |', '| --- | --- |',
    '| S12 / S24 / S40 / FC4 (CAND) and TB30R (NOESC) against the v1 archive | ' . ($miss === [] ? "PASS ($checked rows)" : 'FAIL: ' . implode('; ', array_slice($miss, 0, 5))) . ' |',
    '', '## Gates', '', '| Gate | Result | Detail |', '| --- | --- | --- |'];
foreach ($gates as $n => $g) {
    $md[] = "| $n | " . ($g['pass'] ? 'PASS' : 'FAIL') . ' | ' . implode('; ', $g['detail']) . ' |';
}
$md = array_merge($md, ['', '## Verdicts (PLAN.md §6)', '', '| Subject | Verdict |', '| --- | --- |',
    '| FCN | ' . ($fcnSupported !== [] ? 'EXPERIMENTALLY SUPPORTED — LIMITED SCOPE (' . implode(', ', $fcnSupported) . '; pre+out Hosts only)' : 'NO-GO AFTER EXPERIMENT') . ' |',
    '| FCN8-24, FCN8-40 | FCN-1 … FCN-3 evaluated; FCN-4 not registered for these arms, so no verdict |']);
foreach ($tbkPass as $a => $v) {
    $md[] = "| $a | " . ($v ? 'EXPERIMENTALLY SUPPORTED — LIMITED SCOPE (Host-honest known-device signals)' : 'NO-GO AFTER EXPERIMENT') . ' |';
}
$md = array_merge($md, ['', '## Streams, pre+out (legitimate denied / best-response reach_foreign)', '']);
$cols = array_keys($B);
$md[] = '| Stream | S0 | ' . implode(' | ', $cols) . ' |';
$md[] = '| --- | --- |' . str_repeat(' --- |', count($cols));
foreach (array_keys($B['S40']) as $s) {
    $line = "| $s | {$S0[$s]['legit']} / {$S0[$s]['reach']} |";
    foreach ($cols as $a) {
        $line .= " {$B[$a][$s]['legit']} / {$B[$a][$s]['reach']} |";
    }
    $md[] = $line;
}
$md = array_merge($md, ['', '## pre (checkOnly only)', '']);
$pf = ['S24' => best($fc, 'S24', 'pre'), 'FCN4-24' => best($fc, 'FCN4-24', 'pre'), 'S40' => best($fc, 'S40', 'pre'),
    'FCN4-40' => best($fc, 'FCN4-40', 'pre'), 'TBK30R' => best($tb, 'TBK30R', 'pre')];
$md[] = '| Stream | ' . implode(' | ', array_keys($pf)) . ' |';
$md[] = '| --- |' . str_repeat(' --- |', count($pf));
foreach (array_keys($B['S40']) as $s) {
    $line = "| $s |";
    foreach ($pf as $x) {
        $line .= " {$x[$s]['legit']} / {$x[$s]['reach']} |";
    }
    $md[] = $line;
}
$md = array_merge($md, ['', '## BRU24, REC and the Host-violation control', '',
    '| Arm | BRU24 admitted in 24 h | bound | known-device bypasses | REC wait from arrival (s), delta 0 / 60 / 300 / 900 / 1800 / 3600 |',
    '| --- | --- | --- | --- | --- |']);
foreach ($bru as $a => $r) {
    $md[] = "| $a | {$r['attackAdmitted']} | " . (4 + intdiv(86400, (int) $r['tbT'])) . " | {$r['knownBypass']} | "
        . implode(' / ', array_map(static fn(array $x): string => (string) ($x['waitFromArrival'] ?? 'never'), $rec[$a])) . ' |';
}
$md[] = "| TBK30R-HV (BRU24 with every attacker request Host-verified) | {$hv['attackAdmitted']} | 52 | {$hv['knownBypass']} | — |";
$md[] = '';
@mkdir($outDir, 0777, true);
file_put_contents("$outDir/summary.md", implode("\n", $md) . "\n");
file_put_contents("$outDir/gates.json", json_encode(['v0' => $miss === [], 'v0Rows' => $checked, 'v0Misses' => $miss, 'gates' => $gates,
    'verdicts' => ['FCN' => $fcnSupported, 'TBK' => $tbkPass]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo ($miss === [] ? "V0 PASS ($checked rows)" : 'V0 FAIL') . "\n";
foreach ($gates as $n => $g) {
    printf("%-26s %s\n", $n, $g['pass'] ? 'PASS' : 'FAIL');
}
exit($miss !== [] ? 5 : ($fail ? 3 : 0));
