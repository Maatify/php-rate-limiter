<?php

declare(strict_types=1);

// G1 report — recomputes every metric from the archived per-request strings, checks the S0 reproduction (hard-fail, exit 5, for
// streams whose source baseline has a src/ tree identical to the G1 baseline), evaluates the pre-registered gates C1–C10
// (PLAN.md §5) and writes summary.md + gates.json. A gate FAIL is a finding, not a script error.
//   php report.php <evidence-dir> <out-dir>

require __DIR__ . '/lib/admission.php';

[$_, $in, $out] = $argv + [null, __DIR__ . '/expected', __DIR__ . '/expected'];

/** @return list<array<string, mixed>> */
function rows(string $file): array
{
    if (! is_file($file)) {
        fwrite(STDERR, "missing evidence $file\n");
        exit(5);
    }
    $o = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $l) {
        if ($l !== '') {
            $o[] = json_decode($l, true, 512, JSON_THROW_ON_ERROR);
        }
    }

    return $o;
}

/** Same definition as lib/g1.php g1Metrics (duplicated so the report does not need the runtime). */
function metrics(array $r, int $W = 600): array
{
    $m = ['attackAttempts' => 0, 'reachForeign' => 0, 'reachProof' => 0, 'reachOwn' => 0, 'verifierCalls' => 0, 'legitAttempts' => 0, 'legitDenied' => 0];
    for ($i = 0, $n = strlen($r['kinds']); $i < $n; $i++) {
        $reached = $r['reach'][$i] === '1';
        $m['verifierCalls'] += $reached ? 1 : 0;
        $k = $r['kinds'][$i];
        if ($k === 'X' || $k === 'o') {
            $m['attackAttempts']++;
            if ($reached) {
                $m[$k === 'o' ? 'reachOwn' : ($r['lanes'][$i] === 'P' ? 'reachProof' : 'reachForeign')]++;
            }
            continue;
        }
        $m['legitAttempts']++;
        $m['legitDenied'] += $r['dec'][$i] !== 'A' ? 1 : 0;
    }

    return $m;
}

$m01 = array_merge(rows("$in/m01_matrix.S0.jsonl"), rows("$in/m01_matrix.CAND.jsonl"));
$m02 = array_merge(rows("$in/m02_device.S0.jsonl"), rows("$in/m02_device.CAND.jsonl"));
$m03 = rows("$in/m03_capacity.CAND.jsonl");
$m04 = array_merge(rows("$in/m04_lifecycle.S0.jsonl"), rows("$in/m04_lifecycle.CAND.jsonl"));
$m05 = rows("$in/m05_host_modes.CAND.jsonl");
$m06 = rows("$in/m06_redis.jsonl");
$m07 = rows("$in/m07_source_ceiling.CAND.jsonl");

// ---- integrity: archived metrics equal the recomputation --------------------------------------------------------------------------
foreach ($m01 as $r) {
    $x = metrics($r);
    foreach ($x as $k => $v) {
        if ($r['m'][$k] !== $v) {
            fwrite(STDERR, "metric mismatch {$r['stream']} {$r['arm']} {$r['flow']} $k\n");
            exit(5);
        }
    }
}

$cell = static function (string $stream, string $arm, string $flow, string $set = 'R', string $strategy = 'none') use ($m01): ?array {
    foreach ($m01 as $r) {
        if ($r['stream'] === $stream && $r['arm'] === $arm && $r['flow'] === $flow && $r['set'] === $set && $r['strategy'] === $strategy) {
            return $r;
        }
    }

    return null;
};

// ---- S0 reproduction --------------------------------------------------------------------------------------------------------------
// [stream, strategy, flow, metric, published value, source, src-identical baseline?]
$repro = [
    ['D1', 'none', 'pre+out', 'legitDenied', 596, '#125 (main@9e95f2f)', true],
    ['L1', 'none', 'pre+out', 'legitDenied', 0, '#125 S0N (main@9e95f2f)', true],
    ['OFF12', 'none', 'pre+out', 'legitDenied', 8, '#125 (main@9e95f2f)', true],
    ['X2', 'none', 'pre+out', 'reachForeign', 6, '#125 (main@9e95f2f)', true],
    ['X2', 'uarot', 'pre+out', 'reachForeign', 121, '#125 (main@9e95f2f)', true],
    ['X7', 'uarot', 'pre+out', 'reachForeign', 40, '#125 victim guesses (main@9e95f2f)', true],
    ['BRD', 'none', 'pre', 'reachForeign', 22, '#125 checkOnly-only (main@9e95f2f)', true],
    ['TMSd', 'none', 'pre+out', 'reachForeign', 2, '#119 (main@ebeb0a3)', true],
    ['TMS', 'none', 'pre+out', 'reachForeign', 7, '#119 M1 (main@ebeb0a3)', true],
    ['TMUA', 'none', 'pre+out', 'reachForeign', 36, '#119 R16 (main@ebeb0a3)', true],
    ['D24K1', 'none', 'pre', 'reachForeign', 1440, '#119 R16 (main@ebeb0a3)', true],
    ['N12T', 'none', 'pre+out', 'legitDenied', 56, '#113 / #119 (main@ebeb0a3)', true],
    ['D2', 'none', 'pre+out', 'legitDenied', 117, '#108 (pre-DEC-021 baseline, context only)', false],
    ['Nu4', 'none', 'pre+out', 'legitDenied', 47, '#108 (pre-DEC-021 baseline, context only)', false],
    ['ABA_B', 'none', 'pre+out', 'legitDenied', 118, '#115 (pre-DEC-021 baseline, context only)', false],
    ['SP1n', 'none', 'pre+out', 'reachForeign', 4, '#108 (pre-DEC-021 baseline, context only)', false],
];
$reproRows = [];
$hardFail = false;
foreach ($repro as [$s, $st, $f, $k, $pub, $src, $hard]) {
    $r = $cell($s, 'S0', $f, 'R', $st);
    $v = $r === null ? null : $r['m'][$k];
    $ok = $v === $pub;
    $hardFail = $hardFail || ($hard && ! $ok);
    $reproRows[] = "| $s | $st | $f | $k | $pub | " . ($v ?? 'missing') . " | $src | " . ($ok ? 'reproduced' : ($hard ? '**MISMATCH**' : 'differs (baseline drift)')) . ' |';
}

// ---- gates --------------------------------------------------------------------------------------------------------------------------
$gates = [];
$cand = array_values(array_filter($m01, static fn(array $r): bool => $r['arm'] !== 'S0'));
$withAdm = array_values(array_filter($cand, static fn(array $r): bool => isset($r['recount'])));
$c1 = [];
foreach ($withAdm as $r) {
    foreach ($r['recount'] as $scope => $x) {
        if ($x['cap'] > 0 && $x['max'] > $x['cap']) {
            $c1[] = "{$r['stream']}/{$r['arm']}/{$r['set']}/{$r['flow']}/{$r['strategy']}:$scope {$x['max']}>{$x['cap']}";
        }
    }
}
$r1 = array_values(array_filter($m06, static fn(array $r): bool => $r['case'] === 'R-1 counter race'))[0] ?? null;
$r1m = array_values(array_filter($m06, static fn(array $r): bool => $r['case'] === 'R-1m mutation control'))[0] ?? null;
$redisOk = $r1 !== null && ($r1['pass'] ?? false) === true;
$gates['C1'] = ['status' => $c1 === [] && $redisOk ? 'PASS' : ($c1 !== [] ? 'FAIL' : 'UNPROVEN'), 'matrixViolations' => $c1,
    'cellsChecked' => count($withAdm), 'redisRace' => $r1['admittedPerRound'] ?? null, 'mutationControlDetected' => $r1m['overAdmissionDetected'] ?? null];

$attackCells = array_values(array_filter($withAdm, static fn(array $r): bool => in_array($r['cls'], ['attack', 'both', 'evasion', 'poisoning'], true)));
$c2 = array_values(array_filter($c1, static fn(string $v): bool => true));
$sc5 = array_values(array_filter($m07, static fn(array $r): bool => $r['sc'] === 'SC-5 rotation invariance'))[0];
$gates['C2'] = ['status' => $c2 === [] && $sc5['pass'] ? 'PASS' : 'FAIL', 'attackCellsChecked' => count($attackCells), 'sc5' => $sc5['admittedInOneWindow'] . '/' . $sc5['cap']];

$dv = static fn(string $id, string $arm) => array_values(array_filter($m02, static fn(array $r): bool => $r['dv'] === $id && $r['arm'] === $arm))[0] ?? null;
$dv3 = $dv('DV-3', 'CAND');
$c3ok = ($dv('DV-1', 'CAND')['identicalToControl'] ?? false) && ($dv('DV-2', 'CAND')['identicalToControl'] ?? false)
    && ($dv('DV-4', 'CAND')['identicalToControl'] ?? false) && $dv3 !== null && $dv3['extra'][1] === 'cap:p_device' && $dv3['stateUnchanged']
    && $dv3['admissionWritesUnchanged'] && $dv3['admissionKeysUnchanged'] && $dv3['existingDeviceAfter'][0] === 'A';
$gates['C3'] = ['status' => $c3ok ? 'PASS' : 'FAIL', 'S0_DV1_identical' => $dv('DV-1', 'S0')['identicalToControl'] ?? null,
    'S0_DV4_identical' => $dv('DV-4', 'S0')['identicalToControl'] ?? null, 'DV5_sharedK5' => $dv('DV-5', 'CAND')['sameK5Key'] ?? null,
    'DV6_oldWorkerTrace' => $dv('DV-6', 'CAND->S0')['oldWorkerResult']['trace'] ?? null];

// C4 as pre-registered: no P admission in SP5 / POI (every proof there names another account). Stricter, on EVERY candidate cell:
// no P lane whose proof does not name the request's own account (pLaneForeignProof). TMX legitimately has P lanes: the 17 requests
// where its single session sess-vic0 targets vic0 itself (an own-account proof); the event-level check covers it.
$c4 = [];
$pOwnTmx = 0;
foreach ($cand as $r) {
    if (in_array($r['stream'], ['SP5', 'POI'], true) && str_contains($r['lanes'], 'P')) {
        $c4[] = "{$r['stream']}/{$r['arm']}/{$r['flow']}";
    }
    if (($r['pLaneForeignProof'] ?? 0) !== 0) {
        $c4[] = "{$r['stream']}/{$r['arm']}/{$r['flow']}: foreign-proof P lanes {$r['pLaneForeignProof']}";
    }
    if ($r['stream'] === 'TMX' && $r['arm'] === 'CAND' && $r['set'] === 'R' && $r['flow'] === 'pre+out') {
        $pOwnTmx = substr_count($r['lanes'], 'P');
    }
}
$gates['C4'] = ['status' => $c4 === [] ? 'PASS' : 'FAIL', 'violations' => $c4, 'tmxOwnAccountProofLanes' => $pOwnTmx,
    'checkerCorrection' => 'the first report run also listed TMX at stream level, which counted 17 valid own-account proofs as violations; corrected to the pre-registered SP5/POI list plus the event-level check'];

$pre = array_values(array_filter($c1, static fn(string $v): bool => str_contains($v, '/pre/')));
$gates['C5'] = ['status' => $pre === [] ? 'PASS' : 'FAIL', 'preFlowViolations' => $pre];

$c6 = [];
foreach ($withAdm as $r) {
    if (! $r['storage']['ok']) {
        $c6[] = "{$r['stream']}/{$r['arm']}/{$r['set']}/{$r['flow']}";
    }
}
$pa6 = array_values(array_filter($m03, static fn(array $r): bool => $r['pa'] === 'PA-6'))[0];
$r3 = array_values(array_filter($m06, static fn(array $r): bool => $r['case'] === 'R-3 all-or-nothing'))[0] ?? null;
$gates['C6'] = ['status' => $c6 === [] && $pa6['pass'] && ($r3 === null || ($r3['pass'] ?? false)) ? 'PASS' : 'FAIL', 'violations' => $c6];

$pa = array_values(array_filter($m03, static fn(array $r): bool => ! $r['pass']));
$r2 = array_values(array_filter($m06, static fn(array $r): bool => $r['case'] === 'R-2 device-set race'))[0] ?? null;
$gates['C7'] = ['status' => $pa === [] && $c3ok && ($r2['pass'] ?? false) ? 'PASS' : ($pa !== [] ? 'FAIL' : 'UNPROVEN'), 'failing' => array_column($pa, 'pa'),
    'redisDeviceRace' => $r2['admittedPerRound'] ?? null];

$h4 = array_values(array_filter($m05, static fn(array $r): bool => $r['case'] === 'H-4 T1 claims'))[0];
$r8 = array_values(array_filter($m06, static fn(array $r): bool => $r['case'] === 'R-8 T1 parity'))[0] ?? null;
$gates['C8'] = ['status' => $h4['atMostOncePerPermit'] && ($r8['pass'] ?? false) ? 'PASS' : 'FAIL', 'reference' => $h4['claims'], 'redis' => $r8['claims'] ?? null];

$c9 = [];
foreach ($cand as $r) {
    if (($r['pStateLeaks'] ?? []) !== []) {
        $c9[] = "{$r['stream']}/{$r['arm']}/{$r['flow']}: " . implode(',', $r['pStateLeaks']);
    }
}
$gates['C9'] = ['status' => $c9 === [] ? 'PASS' : 'FAIL', 'violations' => $c9];

$c10 = [];
foreach ($cand as $r) {
    foreach ($r['legitDeniedByCause'] as $cause => $n) {
        if (preg_match('/^pen:.*K[123]/', (string) $cause) === 1) {
            $c10[] = "{$r['stream']}/{$r['arm']}/{$r['set']}/{$r['flow']}:$cause=$n";
        }
    }
}
$gates['C10'] = ['status' => $c10 === [] ? 'PASS' : 'FAIL', 'violations' => $c10];

$core = array_map(static fn(array $g): string => $g['status'], $gates);
$decision = in_array('FAIL', $core, true) ? 'NO-GO' : (in_array('UNPROVEN', $core, true) ? 'INCONCLUSIVE' : 'GO for later policy consideration (G2/G3)');
$gates['DECISION'] = ['status' => $decision, 'core' => $core];

// ---- comparison tables --------------------------------------------------------------------------------------------------------------
$best = static function (string $stream, string $arm, string $flow, string $set = 'R') use ($m01): ?array {
    $c = array_values(array_filter($m01, static fn(array $r): bool => $r['stream'] === $stream && $r['arm'] === $arm && $r['flow'] === $flow && $r['set'] === $set));
    if ($c === []) {
        return null;
    }
    usort($c, static fn(array $a, array $b): int => [$b['m']['reachForeign'] + $b['m']['reachProof'], $b['m']['reachOwn']] <=> [$a['m']['reachForeign'] + $a['m']['reachProof'], $a['m']['reachOwn']]);

    return $c[0];
};
$fmt = static function (?array $r): string {
    if ($r === null) {
        return 'n/a';
    }
    $m = $r['m'];
    $s = [];
    if ($m['attackAttempts'] > 0) {
        $s[] = "F {$m['reachForeign']} P {$m['reachProof']} O {$m['reachOwn']} /{$m['attackAttempts']}";
    }
    if ($m['legitAttempts'] > 0) {
        $s[] = "L {$m['legitDenied']}/{$m['legitAttempts']}";
    }
    $s[] = "V {$m['verifierCalls']}";
    if ($r['strategy'] !== 'none') {
        $s[] = "({$r['strategy']})";
    }

    return implode(' · ', $s);
};
$streams = [];
foreach ($m01 as $r) {
    $streams[$r['stream']] = $r['cls'];
}
$cmp = [];
foreach (['pre+out', 'pre', 'out'] as $flow) {
    $cmp[$flow] = [];
    foreach ($streams as $s => $cls) {
        if ($best($s, 'S0', $flow) === null) {
            continue;
        }
        $cmp[$flow][] = "| $s | $cls | " . $fmt($best($s, 'S0', $flow)) . ' | ' . $fmt($best($s, 'CAND', $flow)) . ' | ' . $fmt($best($s, 'CAND', $flow, 'R-src4'))
            . ' | ' . $fmt($best($s, 'CAND', $flow, 'R-src40')) . ' | ' . $fmt($best($s, 'NOADM', $flow)) . ' | ' . $fmt($best($s, 'ALLU', $flow)) . ' |';
    }
}

// Legitimate denials by cause (pre+out, best attacker strategy cell) for S0 and CAND R.
$causeRows = [];
foreach ($streams as $s => $cls) {
    if (in_array($cls, ['attack', 'evasion'], true)) {
        continue;
    }
    $a = $best($s, 'S0', 'pre+out') ?? $best($s, 'S0', 'out');
    $b = $best($s, 'CAND', 'pre+out') ?? $best($s, 'CAND', 'out');
    $c = static fn(?array $r): string => $r === null || $r['legitDeniedByCause'] === [] ? '-' : implode(', ', array_map(static fn($k, $v) => "$k $v", array_keys($r['legitDeniedByCause']), $r['legitDeniedByCause']));
    $causeRows[] = "| $s | " . $c($a) . ' | ' . $c($b) . ' |';
}

// SC-6 innocent co-tenant refusals with a source cause.
$sc6 = [];
foreach ($streams as $s => $cls) {
    if (in_array($cls, ['attack', 'evasion'], true)) {
        continue;
    }
    $line = [];
    foreach (['R-src4', 'R', 'R-src40'] as $set) {
        $r = $best($s, 'CAND', 'pre+out', $set) ?? $best($s, 'CAND', 'out', $set);
        $n = 0;
        foreach (($r['legitDeniedByCause'] ?? []) as $k => $v) {
            $n += in_array($k, ['cap:src', 'cap:asrc'], true) ? $v : 0;
        }
        $line[] = $r === null ? 'n/a' : "$n/{$r['m']['legitAttempts']}";
    }
    $sc6[] = "| $s | " . implode(' | ', $line) . ' |';
}

// ---- summary --------------------------------------------------------------------------------------------------------------------------
$md = [];
$md[] = '# G1 summary (generated by report.php — do not edit)';
$md[] = '';
$md[] = 'Legend: `F` reach_foreign, `P` reach_proof, `O` reach_own (attacker requests that reached the verifier), `/n` attacker attempts; `L d/n` legitimate requests denied; `V` verifier calls (all requests). The best attacker strategy per cell is shown. Test parameters only (PLAN.md §3).';
$md[] = '';
$md[] = '## 1. S0 reproduction';
$md[] = '';
$md[] = '| Stream | Strategy | Flow | Metric | Published | Measured S0 | Source | Result |';
$md[] = '| --- | --- | --- | --- | --- | --- | --- | --- |';
array_push($md, ...$reproRows);
$md[] = '';
$md[] = '## 2. Gates (PLAN.md §5)';
$md[] = '';
$md[] = '| Gate | Status | Detail |';
$md[] = '| --- | --- | --- |';
foreach ($gates as $g => $x) {
    $d = $x;
    unset($d['status']);
    $md[] = "| $g | **{$x['status']}** | `" . str_replace('|', '\\|', json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '` |';
}
foreach ($cmp as $flow => $rowsF) {
    $md[] = '';
    $md[] = "## 3. Comparison — flow `$flow`";
    $md[] = '';
    $md[] = '| Stream | Class | S0 | CAND R | CAND R-src4 | CAND R-src40 | NOADM | ALLU |';
    $md[] = '| --- | --- | --- | --- | --- | --- | --- | --- |';
    array_push($md, ...$rowsF);
}
$md[] = '';
$md[] = '## 4. Legitimate denials by cause (pre+out; out for API)';
$md[] = '';
$md[] = '| Stream | S0 | CAND R |';
$md[] = '| --- | --- | --- |';
array_push($md, ...$causeRows);
$md[] = '';
$md[] = '## 5. SC-6 — innocent refusals with a source cause (CAND, pre+out; out for API)';
$md[] = '';
$md[] = '| Stream | R-src4 | R (12) | R-src40 |';
$md[] = '| --- | --- | --- | --- |';
array_push($md, ...$sc6);
$md[] = '';
$md[] = '## 6. Scenario evidence';
foreach (['M02 device isolation' => $m02, 'M03 capacity' => $m03, 'M04 lifecycle / LT-1' => $m04, 'M05 host modes' => $m05, 'M06 Redis' => $m06, 'M07 source ceiling' => $m07] as $title => $rs) {
    $md[] = '';
    $md[] = "### $title";
    $md[] = '';
    foreach ($rs as $r) {
        unset($r['s']);
        $md[] = '- `' . json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '`';
    }
}
file_put_contents("$out/summary.md", implode("\n", $md) . "\n");
file_put_contents("$out/gates.json", json_encode($gates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo "decision: $decision\n";
foreach ($core as $g => $s) {
    echo "$g $s\n";
}
if ($hardFail) {
    fwrite(STDERR, "S0 reproduction MISMATCH on a src-identical baseline\n");
    exit(5);
}
