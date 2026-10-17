<?php

declare(strict_types=1);

// G3 report (PLAN.md §5, §6): reads the scenario outputs, checks V0 against the G1 archive, evaluates every gate and detector
// with the pre-registered rules, and writes summary.md and gates.json. Exit 5 = V0 miss (harness invalid); exit 3 = harness
// valid, a gate fails or a detector does not fire; exit 0 = every gate passes.
//   php report.php <actual-dir> <out-dir>

$in = $argv[1] ?? '';
$outDir = $argv[2] ?? '';
$g1 = dirname(__DIR__, 2) . '/g1';

/** @return list<array<string, mixed>> */
function rows(string $file): array
{
    $out = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $out[] = json_decode($l, true, 512, JSON_THROW_ON_ERROR);
    }

    return $out;
}

$s00 = rows("$in/s00_s0.jsonl");
$s01 = rows("$in/s01_sweep.CAND.jsonl");
$s02 = rows("$in/s02_burst.CAND.jsonl");
$s03 = array_merge(rows("$in/s03_tb.CAND.jsonl"), rows("$in/s03_tb.NOESC.jsonl"));
$s04 = rows("$in/s04_cgn.jsonl");
$fields = ['dec', 'reach', 'kinds', 'lanes', 'legitDeniedByCause'];

// ---- V0 ------------------------------------------------------------------------------------------------------------------------
$v0Miss = [];
$arch = [];
foreach (rows("$g1/verify/expected/v07_sweep.CAND.jsonl") as $r) {
    if ($r['arm'] === 'CAND' && in_array($r['set'], ['Ua16-s12', 'Ua16-s40'], true)) {
        $arch[$r['stream'] . '|' . $r['strategy'] . '|' . ($r['set'] === 'Ua16-s12' ? 'S12' : 'S40')] = $r;
    }
}
$v0 = [];
foreach ($s01 as $r) {
    if ($r['s'] !== 'v0') {
        continue;
    }
    $k = $r['stream'] . '|' . $r['strategy'] . '|' . $r['set'];
    $v0[$k] = $r;
    foreach ($fields as $f) {
        if (! isset($arch[$k]) || $arch[$k][$f] !== $r[$f]) {
            $v0Miss[] = "v07 $k $f";
        }
    }
}
foreach ($s01 as $r) {
    if ($r['s'] === 's01' && in_array($r['arm'], ['S12', 'S40'], true) && $r['flow'] === 'pre+out') {
        $k = $r['stream'] . '|' . $r['strategy'] . '|' . $r['arm'];
        foreach ($fields as $f) {
            if (! isset($v0[$k]) || $v0[$k][$f] !== $r[$f]) {
                $v0Miss[] = "wrapper $k $f";
            }
        }
    }
}
$s0Arch = [];
foreach (rows("$g1/expected/m01_matrix.S0.jsonl") as $r) {
    if (($r['flow'] ?? '') === 'pre+out' && ($r['shape'] ?? '') === 'login' && ($r['arm'] ?? '') === 'S0') {
        $s0Arch[$r['stream'] . '|' . $r['strategy']] = $r;
    }
}
foreach ($s00 as $r) {
    $k = $r['stream'] . '|' . $r['strategy'];
    foreach (['dec', 'reach', 'kinds', 'lanes'] as $f) {
        if (! isset($s0Arch[$k]) || $s0Arch[$k][$f] !== $r[$f]) {
            $v0Miss[] = "S0 $k $f";
        }
    }
}

// ---- per-stream best response ------------------------------------------------------------------------------------------------
/** @return array<string, array{cls: string, legit: int, legitN: int, reach: int}> */
function best(array $rows, string $arm, string $flow): array
{
    $out = [];
    foreach ($rows as $r) {
        if (($r['arm'] ?? null) !== $arm || ($r['flow'] ?? null) !== $flow || ! isset($r['m']) || ($r['fixture'] ?? 'stream') !== 'stream') {
            continue;
        }
        $k = $r['stream'];
        $c = $out[$k] ?? ['cls' => $r['cls'], 'legit' => 0, 'legitN' => $r['m']['legitAttempts'], 'reach' => 0];
        $c['legit'] = max($c['legit'], $r['m']['legitDenied']);
        $c['reach'] = max($c['reach'], $r['m']['reachForeign']);
        $out[$k] = $c;
    }

    return $out;
}

/** @return array<string, string> stream|strategy => dec */
function decs(array $rows, string $arm, string $flow): array
{
    $out = [];
    foreach ($rows as $r) {
        if (($r['arm'] ?? null) === $arm && ($r['flow'] ?? null) === $flow && isset($r['dec']) && ($r['fixture'] ?? 'stream') === 'stream') {
            $out[$r['stream'] . '|' . $r['strategy']] = $r['dec'];
        }
    }
    ksort($out);

    return $out;
}

$S0 = [];
foreach ($s00 as $r) {
    $c = $S0[$r['stream']] ?? ['cls' => $r['cls'], 'legit' => 0, 'legitN' => $r['m']['legitAttempts'], 'reach' => 0];
    $c['legit'] = max($c['legit'], $r['m']['legitDenied']);
    $c['reach'] = max($c['reach'], $r['m']['reachForeign']);
    $S0[$r['stream']] = $c;
}
$all = array_merge($s01, $s03);
$B = [];
foreach (['S12', 'S24', 'S40', 'FC4', 'FC8', 'FCALL8', 'FW16', 'TB10', 'TB30', 'TB60', 'TB10R', 'TB30R', 'TB60R'] as $a) {
    $B[$a] = best($all, $a, 'pre+out');
}
$benign = ['L1', 'ABA_B', 'D2', 'N12', 'Nu4', 'OFF12', 'Y6b', 'KD', 'UAC', 'L3', 'D1', 'MIX'];
$spray = ['SP1n', 'SP3', 'SP6', 'MIX', 'ABA_A'];
$gates = [];
$fail = false;

// ---- FC ------------------------------------------------------------------------------------------------------------------------
$fcVerdict = [];
foreach (['FC4', 'FC8'] as $a) {
    $f1 = [];
    foreach ($spray as $s) {
        if ($B[$a][$s]['reach'] > $B['S12'][$s]['reach']) {
            $f1[] = "$s {$B[$a][$s]['reach']} > S12 {$B['S12'][$s]['reach']}";
        }
    }
    $f2 = [];
    foreach ($benign as $s) {
        if ($B[$a][$s]['legit'] > $B['S40'][$s]['legit']) {
            $f2[] = "$s {$B[$a][$s]['legit']} > S40 {$B['S40'][$s]['legit']}";
        }
    }
    $pre = decs($all, $a, 'pre') === decs($all, 'S40', 'pre');
    $gates["FC-1 $a"] = ['pass' => $f1 === [], 'detail' => $f1];
    $gates["FC-2 $a"] = ['pass' => $f2 === [], 'detail' => $f2];
    $gates["FC-3 $a"] = ['pass' => $pre, 'detail' => $pre ? [] : ['pre decisions differ from S40']];
    $fcVerdict[$a] = $f1 === [] && $f2 === [] && $pre;
}
$det = [];
foreach (['L1', 'N12', 'Nu4', 'D2'] as $s) {
    if ($B['FCALL8'][$s]['legit'] > $B['FC8'][$s]['legit']) {
        $det[] = "$s {$B['FCALL8'][$s]['legit']} > {$B['FC8'][$s]['legit']}";
    }
}
$gates['FC-2 detector FCALL8'] = ['pass' => $det !== [], 'detail' => $det];
$fcSupported = $det !== [] && in_array(true, $fcVerdict, true);

// ---- TB ------------------------------------------------------------------------------------------------------------------------
$bru = [];
$rec = [];
foreach ($s03 as $r) {
    if ($r['fixture'] === 'BRU24') {
        $bru[$r['arm']] = $r;
    } elseif ($r['fixture'] === 'REC') {
        $rec[$r['arm']][$r['delta']] = $r['admittedAfterAttackEnd'];
    }
}
$tbDet1 = $bru['FW16']['attackAdmitted'] > $bru['TB10']['attackAdmitted'];
$tbDet2 = false;
foreach ($rec['G1ESC'] as $d => $v) {
    $tbDet2 = $tbDet2 || $v === null || $v > 1800;
}
$gates['TB-1 detector FW16'] = ['pass' => $tbDet1, 'detail' => ["FW16 {$bru['FW16']['attackAdmitted']} vs TB10 {$bru['TB10']['attackAdmitted']}"]];
$gates['TB-2 detector G1ESC'] = ['pass' => $tbDet2, 'detail' => array_map(static fn($d, $v): string => "delta $d: " . ($v ?? 'never'), array_keys($rec['G1ESC']), $rec['G1ESC'])];
$tbVerdict = [];
foreach ([10 => 600, 30 => 1800, 60 => 3600] as $m => $T) {
    $a = "TB$m";
    $bound = 4 + intdiv(86400, $T);
    $adm = $bru[$a]['attackAdmitted'];
    $t1 = $adm <= $bound && $adm === $bru[$a]['tbTakesRecount'];
    $gates["TB-1 $a"] = ['pass' => $t1, 'detail' => ["admitted $adm, bound $bound, recount {$bru[$a]['tbTakesRecount']}"]];
    $t2d = [];
    foreach ($rec[$a] as $d => $v) {
        if ($v === null || $v > $T + 60) {
            $t2d[] = "delta $d: " . ($v ?? 'never');
        }
    }
    $gates["TB-2 $a"] = ['pass' => $t2d === [], 'detail' => $t2d === [] ? array_map(static fn($d, $v): string => "delta $d: $v s", array_keys($rec[$a]), $rec[$a]) : $t2d];
    $t3d = [];
    foreach ($benign as $s) {
        if ($B["{$a}R"][$s]['legit'] > $B['S40'][$s]['legit']) {
            $t3d[] = "$s {$B["{$a}R"][$s]['legit']} > S40 {$B['S40'][$s]['legit']}";
        }
    }
    $gates["TB-3 {$a}R"] = ['pass' => $t3d === [], 'detail' => $t3d];
    $tbVerdict[$a] = $tbDet1 && $tbDet2 && $t1 && $t2d === [] && $t3d === [];
}
foreach ($gates as $g) {
    $fail = $fail || ! $g['pass'];
}

// ---- reporting tables ----------------------------------------------------------------------------------------------------------
$md = [];
$md[] = '# G3 admission budgets — generated summary';
$md[] = '';
$md[] = 'Generated by `report.php` from the scenario outputs. Rules: `PLAN.md` §5–§6. Counts are requests.';
$md[] = '';
$md[] = '## V0 reproduction';
$md[] = '';
$md[] = '| Check | Result |';
$md[] = '| --- | --- |';
$md[] = '| S12 / S40 vs archived G1 v07 rows, wrapper equivalence, S0 vs archived G1 m01 rows | ' . ($v0Miss === [] ? 'PASS (' . count($v0) . ' v07 rows, ' . count($s00) . ' S0 rows)' : 'FAIL: ' . implode('; ', array_slice($v0Miss, 0, 5))) . ' |';
$md[] = '';
$md[] = '## Gates';
$md[] = '';
$md[] = '| Gate | Result | Detail |';
$md[] = '| --- | --- | --- |';
foreach ($gates as $name => $g) {
    $md[] = "| $name | " . ($g['pass'] ? 'PASS' : 'FAIL') . ' | ' . implode('; ', $g['detail']) . ' |';
}
$md[] = '';
$md[] = '## Verdicts (PLAN.md §6)';
$md[] = '';
$md[] = '| Subject | Verdict |';
$md[] = '| --- | --- |';
$md[] = '| FC | ' . ($fcSupported ? 'EXPERIMENTALLY SUPPORTED — LIMITED SCOPE (' . implode(', ', array_keys(array_filter($fcVerdict))) . ')' : 'NO-GO AFTER EXPERIMENT') . ' |';
foreach ($tbVerdict as $a => $v) {
    $md[] = "| $a | " . ($v ? 'EXPERIMENTALLY SUPPORTED — LIMITED SCOPE' : 'NO-GO AFTER EXPERIMENT') . ' |';
}
$md[] = '| MID, CG | no verdict (reporting only) |';
$md[] = '';
$md[] = '## Streams, pre+out (legitimate denied / best-response reach_foreign)';
$md[] = '';
$cols = array_keys($B);
$md[] = '| Stream | S0 | ' . implode(' | ', $cols) . ' |';
$md[] = '| --- | --- |' . str_repeat(' --- |', count($cols));
foreach (array_keys($B['S40']) as $s) {
    $line = "| $s | {$S0[$s]['legit']} / {$S0[$s]['reach']} |";
    foreach ($cols as $a) {
        $line .= ' ' . ($B[$a][$s]['legit'] ?? '-') . ' / ' . ($B[$a][$s]['reach'] ?? '-') . ' |';
    }
    $md[] = $line;
}
$md[] = '';
$md[] = '## pre (checkOnly only) and out (record only)';
$md[] = '';
$pf = ['S40 pre' => best($all, 'S40', 'pre'), 'FC4 pre' => best($all, 'FC4', 'pre'), 'FC8 pre' => best($all, 'FC8', 'pre'),
    'TB30 pre' => best($all, 'TB30', 'pre'), 'TB30R pre' => best($all, 'TB30R', 'pre'), 'S40 out' => best($all, 'S40', 'out'),
    'FC8 out' => best($all, 'FC8', 'out'), 'TB30R out' => best($all, 'TB30R', 'out')];
$md[] = '| Stream | ' . implode(' | ', array_keys($pf)) . ' |';
$md[] = '| --- |' . str_repeat(' --- |', count($pf));
foreach (array_keys($B['S40']) as $s) {
    $line = "| $s |";
    foreach ($pf as $x) {
        $line .= ' ' . ($x[$s]['legit'] ?? '-') . ' / ' . ($x[$s]['reach'] ?? '-') . ' |';
    }
    $md[] = $line;
}
$md[] = '';
$md[] = '## BRU24 and REC';
$md[] = '';
$md[] = '| Arm | BRU24 attacker admissions in 24 h | bound C + 86400/T | REC: owner admitted after the attack\'s last request (s), per delta 0 / 60 / 300 / 900 / 1800 / 3600 |';
$md[] = '| --- | --- | --- | --- |';
foreach ($bru as $a => $r) {
    $bound = $r['tbT'] === null ? '-' : (string) (4 + intdiv(86400, (int) $r['tbT']));
    $md[] = "| $a | {$r['attackAdmitted']} | $bound | " . implode(' / ', array_map(static fn($v): string => $v === null ? 'never' : (string) $v, $rec[$a])) . ' |';
}
$md[] = '';
$md[] = '**POST-HOC diagnostic (not part of any gate or verdict).** Owner wait counted from the owner\'s own arrival (admitted after the attack\'s last request minus delta), per delta 0 / 60 / 300 / 900 / 1800 / 3600:';
$md[] = '';
$md[] = '| Arm | wait from arrival (s) |';
$md[] = '| --- | --- |';
foreach ($rec as $a => $byD) {
    $md[] = "| $a | " . implode(' / ', array_map(static fn($d, $v): string => $v === null ? 'never' : (string) ($v - $d), array_keys($byD), $byD)) . ' |';
}
$md[] = '';
$md[] = '## BURST40';
$md[] = '';
$md[] = '| Arm | admitted per burst (3 windows) | leak above F_src = 8 |';
$md[] = '| --- | --- | --- |';
foreach ($s02 as $r) {
    $adm = array_map(static fn(array $w): int => $w['burstAdmitted'], $r['windows']);
    $md[] = "| {$r['arm']} | " . implode(' / ', $adm) . ' | ' . ($r['arm'] === 'S40' ? '-' : implode(' / ', array_map(static fn(int $n): int => $n - 8, $adm))) . ' |';
}
$md[] = '';
$md[] = '## CGN (mean refused share of legitimate unproven attempts, 24 replications; ε = 1 %)';
$md[] = '';
$md[] = '| Arm | λ | p | attacker | N = 50 | N = 500 | N = 5000 | largest N with share ≤ ε | mobile refused / 288 (4 attempts × 24 runs × 3 N) | attacker admitted (24 runs × 3 N) |';
$md[] = '| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |';
$cg = [];
foreach ($s04 as $r) {
    $cg[$r['arm'] . '|' . $r['lambda'] . '|' . $r['p'] . '|' . ($r['attacker'] ? 'on' : 'off')][$r['n']] = $r;
}
foreach ($cg as $k => $byN) {
    [$a, $l, $p, $at] = explode('|', $k);
    $nMax = 'none';
    foreach ([50, 500, 5000] as $n) {
        if ($byN[$n]['refusedShare'] <= 0.01) {
            $nMax = (string) $n;
        } else {
            break;
        }
    }
    $mob = array_sum(array_map(static fn(array $r): int => $r['mobileRefused'], $byN));
    $atk = array_sum(array_map(static fn(array $r): int => $r['attackAdmitted'], $byN));
    $md[] = "| $a | $l | $p | $at | " . implode(' | ', array_map(static fn(int $n): string => sprintf('%.2f %%', 100 * $byN[$n]['refusedShare']), [50, 500, 5000])) . " | $nMax | $mob | $atk |";
}
$md[] = '';

@mkdir($outDir, 0777, true);
file_put_contents("$outDir/summary.md", implode("\n", $md) . "\n");
file_put_contents("$outDir/gates.json", json_encode(['v0' => $v0Miss === [], 'v0Misses' => $v0Miss, 'gates' => $gates,
    'verdicts' => ['FC' => $fcSupported, 'TB' => $tbVerdict]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo ($v0Miss === [] ? 'V0 PASS' : 'V0 FAIL') . "\n";
foreach ($gates as $name => $g) {
    printf("%-28s %s\n", $name, $g['pass'] ? 'PASS' : 'FAIL');
}
exit($v0Miss !== [] ? 5 : ($fail ? 3 : 0));
