<?php

declare(strict_types=1);

// G1 final verification — report (FLOOD_PLAN.md §2 / §3). Reads the outputs (argv[1]) and the archived DAO evidence
// (expected_da_t1/), applies the fixed F1–F4 rule, writes gates_flood.json + summary_flood.md into argv[2].

$in = rtrim($argv[1] ?? '', '/');
$out = rtrim($argv[2] ?? $in, '/');
$da = __DIR__ . '/expected_da_t1';
$orig = dirname(__DIR__) . '/expected';
$load = static fn(string $f): array => array_map(static fn(string $l): array => json_decode($l, true, 512, JSON_THROW_ON_ERROR), is_file($f) ? file($f) : []);
$f01 = array_merge($load("$in/f01_flood_p.TRACE.jsonl"), $load("$in/f01_flood_p.DAO.jsonl"), $load("$in/f01_flood_p.DAOF.jsonl"));
$x01 = ['DAO' => $load("$da/x01_matrix.DAO.jsonl"), 'DAOF' => $load("$in/x01_matrix.DAOF.jsonl")];
$x02 = $load("$in/x02_owner.DAOF.jsonl");
$x03 = ['DAO' => $load("$da/x03_unbound.DAO.jsonl"), 'DAOF' => $load("$in/x03_unbound.DAOF.jsonl")];
$g = [];
$key = static fn(array $r): string => implode('|', [$r['stream'], $r['shape'] ?? 'login', $r['strategy'], $r['arm'] ?? 'CAND', $r['set'], $r['flow']]);

// Counterexample per arm.
$cx = [];
foreach ($f01 as $r) {
    $cx[$r['arm']][$r['fixture']] = ['provenDecisions' => $r['provenDecisions'], 'control' => $r['controlProvenDecisions'],
        'provenDeniedByFlood' => $r['provenDeniedByFlood'], 'confirmed' => $r['counterexampleConfirmed']];
}
$g['FLOOD-P counterexample'] = ['status' => (($cx['CAND-no-DA-punishment']['FLOOD-P']['confirmed'] ?? false) ? 'CONFIRMED (declared fixture)' : 'NOT EXERCISED by the declared fixture')
    . ' / ' . (($cx['CAND-no-DA-punishment']['FLOOD-P-NAT']['confirmed'] ?? false) ? 'CONFIRMED (shared-IP fixture, added)' : 'not confirmed (shared-IP fixture)'),
    'whyDeclaredNotExercised' => 'the flood device count is EphemeralBucket::check(accountObservation) keyed by accountAnchor() = K4 + K1, i.e. per (account, source); six devices on six IPs give count 1 per anchor (measured), so flood_stage is never set',
    'perArm' => $cx];

// DAOF rule F1–F4.
$rows = $x01['DAOF'];
$f1 = ['c9Violations' => array_sum(array_map(static fn(array $r): int => $r['c9']['violations'], $rows)),
    'c10Forbidden' => array_sum(array_map(static fn(array $r): int => $r['c10']['forbidden'], $rows)),
    'c10Unattributed' => array_sum(array_map(static fn(array $r): int => $r['c10']['unattributed'], $rows)),
    'c1LogOver' => count(array_filter($rows, static fn(array $r): bool => $r['c1']['logOver'] !== []))];
$f2 = ['benignDA' => array_sum(array_column($rows, 'benignDA')), 'benignFlood' => array_sum(array_column($rows, 'benignFlood'))];
$f3 = [];
foreach ($f01 as $r) {
    if ($r['arm'] === 'CAND-no-DA-no-flood-punishment' && ($r['provenDecisions'] !== $r['controlProvenDecisions'] || $r['provenDeniedByFlood'] > 0)) {
        $f3[] = $r['fixture'];
    }
}
foreach ($x02 as $r) {
    $differs = $r['fixture'] === 'MX' ? ! $r['identicalToControl'] : ! $r['phaseCIdenticalToControl'];
    if ($differs && $r['populationOriginDenials'] > 0) {
        $f3[] = $r['fixture'] . ($r['fixture'] === 'MX' ? " {$r['variants']}" : '');
    }
}
$f4 = count(array_filter($rows, static fn(array $r): bool => $r['c1']['eventOver'] !== []));
$same = [];
foreach (['m02_device', 'm04_lifecycle'] as $sc) {
    $same[$sc] = is_file("$in/x04_$sc.DAOF.jsonl") && file_get_contents("$in/x04_$sc.DAOF.jsonl") === file_get_contents("$orig/$sc.CAND.jsonl");
}
$fail = [];
if (array_sum($f1) > 0) {
    $fail[] = 'F1';
}
if (array_sum($f2) > 0) {
    $fail[] = 'F2';
}
if ($f3 !== []) {
    $fail[] = 'F3';
}
if ($f4 > 0) {
    $fail[] = 'F4';
}
$base = [];
foreach ($x01['DAO'] as $r) {
    $base[$key($r)] = $r;
}
$deltas = [];
foreach ($rows as $r) {
    $c = $base[$key($r)] ?? null;
    if ($c === null) {
        $deltas[$key($r)] = 'missing DAO reference';
        continue;
    }
    $d = [];
    foreach (['reachForeign' => 'F', 'reachProof' => 'P', 'reachOwn' => 'O', 'legitDenied' => 'L', 'verifierCalls' => 'V'] as $m => $s) {
        if ($r['m'][$m] !== $c['m'][$m]) {
            $d[$s] = [$c['m'][$m], $r['m'][$m]];
        }
    }
    if ($r['target']['maxPerTargetWindow'] !== $c['target']['maxPerTargetWindow']) {
        $d['T'] = [$c['target']['maxPerTargetWindow'], $r['target']['maxPerTargetWindow']];
    }
    if ($d !== []) {
        $deltas[$key($r)] = $d;
    }
}
$xb = [];
foreach ($x03['DAO'] as $r) {
    $xb["{$r['stream']}|{$r['strategy']}|{$r['set']}|{$r['flow']}"] = $r;
}
$xd = [];
$best = [];
foreach ($x03['DAOF'] as $r) {
    $k = "{$r['stream']}|{$r['strategy']}|{$r['set']}|{$r['flow']}";
    $c = $xb[$k] ?? null;
    if ($c !== null && ($c['m']['reachForeign'] !== $r['m']['reachForeign'] || $c['m']['legitDenied'] !== $r['m']['legitDenied'] || $c['m']['reachOwn'] !== $r['m']['reachOwn'])) {
        $xd[$k] = ['F' => [$c['m']['reachForeign'], $r['m']['reachForeign']], 'O' => [$c['m']['reachOwn'], $r['m']['reachOwn']],
            'L' => [$c['m']['legitDenied'], $r['m']['legitDenied']], 'T' => [$c['target']['maxPerTargetWindow'], $r['target']['maxPerTargetWindow']]];
    }
    $bk = "{$r['stream']}|{$r['set']}|{$r['flow']}";
    $best[$bk]['DAOF'] = max($best[$bk]['DAOF'] ?? 0, $r['m']['reachForeign']);
    $best[$bk]['DAO'] = max($best[$bk]['DAO'] ?? 0, $c['m']['reachForeign'] ?? 0);
}
$g['VARIANT CAND-no-DA-no-flood-punishment'] = ['status' => $fail === [] ? 'GO (engineering component)' : 'NO-GO (' . implode(', ', $fail) . ')',
    'F1' => $f1, 'F2' => $f2, 'F3' => $f3, 'F4 eventRecountOverCells' => $f4, 'matrixCellsChangedVsDAO' => count($deltas),
    'originalScenariosIdenticalToArchivedCand' => $same, 'deltasVsDAO' => $deltas];
$g['X03 vs DAO'] = ['status' => 'reported', 'strategyLevelChanges' => $xd,
    'bestResponseChanges' => array_filter($best, static fn(array $x): bool => $x['DAO'] !== $x['DAOF'])];
file_put_contents("$out/gates_flood.json", json_encode($g, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

$md = "# G1 final verification — FLOOD-P and flood ablation (generated by verify/report_flood.php — do not edit)\n\nPre-declared in [`FLOOD_PLAN.md`](../FLOOD_PLAN.md).\n\n## 1. Verdicts\n\n| Item | Status | Evidence |\n| --- | --- | --- |\n";
foreach ($g as $k => $e) {
    $s = $e['status'];
    unset($e['status'], $e['deltasVsDAO'], $e['strategyLevelChanges']);
    $md .= "| $k | **$s** | `" . str_replace('|', '\\|', json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "` |\n";
}
$md .= "\n## 2. FLOOD-P owner timelines (owner requests; population rows in the JSONL)\n\n| Arm | Fixture | Owner (t: lane decision origin K5-block) | Control |\n| --- | --- | --- | --- |\n";
foreach ($f01 as $r) {
    $fmt = static fn(array $ev): string => implode(' · ', array_map(static fn(array $e): string => "{$e['t']}: {$e['lane']} {$e['decision']}" . ($e['decision'] !== 'A' ? " {$e['origin']}" : '') . ($e['k5Block'] ? " K5 {$e['k5Block']}" : ''),
        array_values(array_filter($ev, static fn(array $e): bool => ! isset($e['who'])))));
    $md .= "| {$r['arm']} | {$r['fixture']} | " . str_replace('|', '\\|', $fmt($r['population'])) . ' | ' . str_replace('|', '\\|', $fmt($r['control'])) . " |\n";
}
$md .= "\n## 3. Matrix cells changed by the flood ablation (DAO → DAOF)\n\n";
$md .= $deltas === [] ? "No matrix cell changed.\n" : "| Cell | Changes |\n| --- | --- |\n" . implode('', array_map(static fn(string $k, $x): string => "| $k | `" . json_encode($x) . "` |\n", array_keys($deltas), $deltas));
$md .= "\n## 4. X03 diagnostic (account cap not binding), DAO → DAOF\n\n| Stream / strategy / set / flow | F | O | T | L |\n| --- | --- | --- | --- | --- |\n";
foreach ($xd as $k => $x) {
    $md .= "| $k | {$x['F'][0]} → {$x['F'][1]} | {$x['O'][0]} → {$x['O'][1]} | {$x['T'][0]} → {$x['T'][1]} | {$x['L'][0]} → {$x['L'][1]} |\n";
}
$md .= "\nBest-response changes: `" . json_encode($g['X03 vs DAO']['bestResponseChanges']) . "`\n\n## 5. MX / MXE on DAOF\n\n";
foreach ($x02 as $r) {
    $md .= $r['fixture'] === 'MX'
        ? "- MX {$r['variants']}: identical to control " . ($r['identicalToControl'] ? 'yes' : 'no') . ", population-origin Δ {$r['populationOriginDenials']}\n"
        : "- MXE: phase C identical " . ($r['phaseCIdenticalToControl'] ? 'yes' : 'no') . ", population-origin Δ {$r['populationOriginDenials']}, phases " . json_encode($r['withOthers']['phases']) . "\n";
}
file_put_contents("$out/summary_flood.md", $md);
foreach ($g as $k => $e) {
    echo "$k: {$e['status']}\n";
}
