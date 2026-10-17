<?php

declare(strict_types=1);

// G1 targeted step — report (DA_T1_PLAN.md §1.3 / §2). Reads the outputs (argv[1]) and the archived CAND evidence (../expected),
// applies the fixed N1–N4 variant rule and the T1 status rule, writes gates_da_t1.json + summary_da_t1.md into argv[2].

$in = rtrim($argv[1] ?? '', '/');
$out = rtrim($argv[2] ?? $in, '/');
$orig = dirname(__DIR__) . '/expected';
$load = static function (string $f): array {
    return array_map(static fn(string $l): array => json_decode($l, true, 512, JSON_THROW_ON_ERROR), is_file($f) ? file($f) : []);
};
$builds = ['TRACE' => 'CAND', 'DAM' => 'CAND-DA-M', 'DAO' => 'CAND-no-DA-punishment'];
$x01 = $x02 = [];
foreach ($builds as $b => $v) {
    $x01[$v] = $load("$in/x01_matrix.$b.jsonl");
    $x02[$v] = $load("$in/x02_owner.$b.jsonl");
}
$x03 = ['CAND' => $load("$in/x03_unbound.TRACE.jsonl"), 'CAND-no-DA-punishment' => $load("$in/x03_unbound.DAO.jsonl")];
$t1 = $load("$in/t1_fixtures.jsonl");
$g = [];
$key = static fn(array $r): string => implode('|', [$r['stream'], $r['shape'] ?? 'login', $r['strategy'], $r['arm'] ?? 'CAND', $r['set'], $r['flow']]);

// Precondition: the CAND (trace) arm reproduces the archived matrix and the original DV / lifecycle outputs.
$eq = count(array_filter($x01['CAND'], static fn(array $r): bool => $r['equivalentToArchivedCand']));
$same = [];
foreach (['m02_device', 'm04_lifecycle'] as $sc) {
    foreach (array_keys($builds) as $b) {
        $same["$sc.$b"] = is_file("$in/x04_$sc.$b.jsonl") && file_get_contents("$in/x04_$sc.$b.jsonl") === file_get_contents("$orig/$sc.CAND.jsonl");
    }
}
$g['PRECONDITION'] = ['status' => $x01['CAND'] !== [] && $eq === count($x01['CAND']) && $same['m02_device.TRACE'] && $same['m04_lifecycle.TRACE'] ? 'PASS' : 'FAIL',
    'candMatrixCellsEquivalent' => "$eq/" . count($x01['CAND']), 'originalScenariosIdenticalToArchivedCand' => $same];

// Variant rule N1–N4.
$base = [];
foreach ($x01['CAND'] as $r) {
    $base[$key($r)] = $r;
}
foreach ($builds as $b => $v) {
    $rows = $x01[$v];
    $n1 = ['c9Violations' => array_sum(array_map(static fn(array $r): int => $r['c9']['violations'], $rows)),
        'c10Forbidden' => array_sum(array_map(static fn(array $r): int => $r['c10']['forbidden'], $rows)),
        'c10Unattributed' => array_sum(array_map(static fn(array $r): int => $r['c10']['unattributed'], $rows)),
        'c1LogOver' => count(array_filter($rows, static fn(array $r): bool => $r['c1']['logOver'] !== []))];
    $n2 = array_sum(array_map(static fn(array $r): int => $r['benignDA'], $rows));
    $flood = array_sum(array_map(static fn(array $r): int => $r['benignFlood'], $rows));
    $n3 = [];
    foreach ($x02[$v] as $r) {
        $pop = $r['populationOriginDenials'];
        $differs = $r['fixture'] === 'MX' ? ! $r['identicalToControl'] : ! $r['phaseCIdenticalToControl'];
        if ($differs && $pop > 0) {
            $n3[] = $r['fixture'] . ($r['fixture'] === 'MX' ? " {$r['variants']}" : '') . " (+$pop population-origin owner denials)";
        }
    }
    $n4 = count(array_filter($rows, static fn(array $r): bool => $r['c1']['eventOver'] !== []));
    $fail = [];
    if ($n1['c9Violations'] + $n1['c10Forbidden'] + $n1['c10Unattributed'] + $n1['c1LogOver'] > 0) {
        $fail[] = 'N1';
    }
    if ($n2 > 0) {
        $fail[] = 'N2';
    }
    if ($n3 !== []) {
        $fail[] = 'N3';
    }
    if ($n4 > 0) {
        $fail[] = 'N4';
    }
    // Deltas against CAND, per cell (strategy-level, not only best response).
    $deltas = [];
    foreach ($rows as $r) {
        $c = $base[$key($r)] ?? null;
        if ($c === null || $v === 'CAND') {
            continue;
        }
        $d = [];
        foreach (['reachForeign' => 'F', 'reachProof' => 'P', 'reachOwn' => 'O', 'legitDenied' => 'L', 'verifierCalls' => 'V'] as $m => $s) {
            if ($r['m'][$m] !== $c['m'][$m]) {
                $d[$s] = [$c['m'][$m], $r['m'][$m]];
            }
        }
        if ($d !== []) {
            $deltas[$key($r)] = $d;
        }
    }
    $g["VARIANT $v"] = ['status' => $v === 'CAND' ? ($fail === [] ? 'reference (N1–N4 pass)' : 'reference (fails ' . implode(', ', $fail) . ')') : ($fail === [] ? 'GO (engineering component)' : 'NO-GO (' . implode(', ', $fail) . ')'),
        'N1' => $n1, 'N2 benignDAdenials' => $n2, 'benignFloodDenials (reported)' => $flood, 'N3' => $n3, 'N4 eventRecountOverCells' => $n4,
        'matrixCellsChangedVsCand' => count($deltas), 'deltasVsCand' => $deltas,
        'originalScenariosIdenticalToArchivedCand' => ['m02_device' => $same["m02_device.$b"], 'm04_lifecycle' => $same["m04_lifecycle.$b"]]];
}

// X03 diagnostic: strategy-level deltas with the account cap not binding.
$xb = [];
foreach ($x03['CAND'] as $r) {
    $xb["{$r['stream']}|{$r['strategy']}|{$r['set']}|{$r['flow']}"] = $r;
}
$xd = [];
foreach ($x03['CAND-no-DA-punishment'] as $r) {
    $k = "{$r['stream']}|{$r['strategy']}|{$r['set']}|{$r['flow']}";
    $c = $xb[$k] ?? null;
    if ($c !== null && ($c['m']['reachForeign'] !== $r['m']['reachForeign'] || $c['m']['legitDenied'] !== $r['m']['legitDenied'])) {
        $xd[$k] = ['F' => [$c['m']['reachForeign'], $r['m']['reachForeign']], 'L' => [$c['m']['legitDenied'], $r['m']['legitDenied']],
            'T' => [$c['target']['maxPerTargetWindow'], $r['target']['maxPerTargetWindow']]];
    }
}
$best = [];
foreach (['CAND', 'CAND-no-DA-punishment'] as $v) {
    foreach ($x03[$v] as $r) {
        $k = "{$r['stream']}|{$r['set']}|{$r['flow']}";
        $best[$k][$v] = max($best[$k][$v] ?? 0, $r['m']['reachForeign']);
    }
}
$bestChanged = array_filter($best, static fn(array $x): bool => ($x['CAND'] ?? null) !== ($x['CAND-no-DA-punishment'] ?? null));
$g['X03 diagnostic (account cap not binding)'] = ['status' => 'reported', 'strategyLevelChanges' => $xd, 'bestResponseChanges' => $bestChanged];

// T1.
$t1rows = array_values(array_filter($t1, static fn(array $r): bool => isset($r['fixture'])));
$by = [];
foreach ($t1rows as $r) {
    $by[$r['fixture']][$r['store']] = $r['pass'];
}
ksort($by);
$fixedAll = array_reduce($t1rows, static fn(bool $c, array $r): bool => $c && (! str_starts_with($r['store'], 'fixed') || $r['pass']), true);
$origFails = array_values(array_unique(array_map(static fn(array $r): string => $r['fixture'] . ' @ ' . $r['store'],
    array_filter($t1rows, static fn(array $r): bool => str_starts_with($r['store'], 'original') && ! $r['pass']))));
$redisRan = count(array_filter($t1rows, static fn(array $r): bool => str_ends_with($r['store'], 'redis'))) > 0;
$g['T1'] = ['status' => ! $redisRan ? 'UNPROVEN (no Redis)' : ($fixedAll ? 'original T1 NOT RECOMMENDED (failing fixtures kept); fixed store: fixtures pass in isolation (single Redis), UNPROVEN for production' : 'FIXED STORE FAILS'),
    'originalFailing' => $origFails, 'matrix' => $by];

file_put_contents("$out/gates_da_t1.json", json_encode($g, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

$md = "# G1 targeted step — DA ablation and T1 fixtures (generated by verify/report_da_t1.php — do not edit)\n\nPre-declared in [`DA_T1_PLAN.md`](../DA_T1_PLAN.md).\n\n## 1. Variant rule\n\n| Item | Status | Evidence |\n| --- | --- | --- |\n";
foreach ($g as $k => $e) {
    $s = $e['status'];
    unset($e['status'], $e['deltasVsCand'], $e['strategyLevelChanges'], $e['matrix']);
    $md .= "| $k | **$s** | `" . str_replace('|', '\\|', json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "` |\n";
}
$md .= "\n## 2. Owner effect isolation (owner P decisions vs no-other-devices control)\n\n| Arm | Fixture | U families | Identical to control | Owner HARD (control) | Population-origin owner denials (Δ) |\n| --- | --- | --- | --- | --- | --- |\n";
foreach ($x02 as $v => $rows) {
    foreach ($rows as $r) {
        if ($r['fixture'] === 'MX') {
            $md .= "| $v | MX | {$r['variants']} | " . ($r['identicalToControl'] ? 'yes' : '**no**') . " | {$r['ownerHard']} ({$r['controlHard']}) | {$r['populationOriginDenials']} |\n";
        } else {
            $w = $r['withOthers']['phases'];
            $c = $r['control']['phases'];
            $md .= "| $v | MXE | A `{$w['A (P)']}` B `{$w['B (same device, U)']}` C `{$w['C (P again)']}` (control A `{$c['A (P)']}` B `{$c['B (same device, U)']}` C `{$c['C (P again)']}`) | C: " . ($r['phaseCIdenticalToControl'] ? 'yes' : 'no') . " | — | {$r['populationOriginDenials']} |\n";
        }
    }
}
$md .= "\n## 3. Matrix cells changed by the ablation (strategy level; [CAND, variant])\n\n";
foreach (['CAND-DA-M', 'CAND-no-DA-punishment'] as $v) {
    $md .= "### $v\n\n";
    $d = $g["VARIANT $v"]['deltasVsCand'];
    $md .= $d === [] ? "No matrix cell changed.\n\n" : "| Cell | Changes |\n| --- | --- |\n" . implode('', array_map(static fn(string $k, array $x): string => "| $k | `" . json_encode($x) . "` |\n", array_keys($d), $d)) . "\n";
}
$md .= "## 4. X03 diagnostic (account cap not binding): strategy-level changes [CAND, CAND-no-DA-punishment]\n\n| Stream / strategy / set / flow | F | T | L |\n| --- | --- | --- | --- |\n";
foreach ($xd as $k => $x) {
    $md .= "| $k | {$x['F'][0]} → {$x['F'][1]} | {$x['T'][0]} → {$x['T'][1]} | {$x['L'][0]} → {$x['L'][1]} |\n";
}
$md .= "\nBest-response (max over strategies) changes: `" . json_encode($bestChanged) . "`\n\n## 5. T1 fixtures (pass per store)\n\n| Fixture | original/reference | fixed/reference | original/redis | fixed/redis |\n| --- | --- | --- | --- | --- |\n";
$fmt = static fn($x): string => $x === null ? '—' : ($x ? 'pass' : '**FAIL**');
foreach ($by as $f => $s) {
    $md .= "| $f | {$fmt($s['original/reference'] ?? null)} | {$fmt($s['fixed/reference'] ?? null)} | {$fmt($s['original/redis'] ?? null)} | {$fmt($s['fixed/redis'] ?? null)} |\n";
}
$md .= "\n## 6. T1 fixture rows\n\n";
foreach ($t1rows as $r) {
    $md .= '- `' . json_encode($r, JSON_UNESCAPED_SLASHES) . "`\n";
}
file_put_contents("$out/summary_da_t1.md", $md);
foreach ($g as $k => $e) {
    echo "$k: {$e['status']}\n";
}
