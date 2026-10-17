<?php

declare(strict_types=1);

// G1 verification addendum — report. Reads the verification outputs (argv[1]) and the ORIGINAL archived evidence
// (../expected), evaluates the hardened gates of VERIFY_PLAN.md §2 with their detector controls, the U_acct sweep screen of §3,
// and writes gates_verify.json + summary_verify.md into argv[2]. Exit 0 always; statuses are in the outputs.

$in = rtrim($argv[1] ?? '', '/');
$out = rtrim($argv[2] ?? $in, '/');
$orig = dirname(__DIR__) . '/expected';
$load = static function (string $f): array {
    $rows = [];
    foreach (is_file($f) ? file($f) : [] as $l) {
        $rows[] = json_decode($l, true, 512, JSON_THROW_ON_ERROR);
    }

    return $rows;
};
$v01 = $load("$in/v01_matrix.TRACE.jsonl");
$v02 = $load("$in/v02_c9_mixed.CAND.jsonl");
$v03 = $load("$in/v03_c4_boundary.CAND.jsonl");
$v04 = $load("$in/v04_detectors.CAND.jsonl");
$v05 = $load("$in/v05_c10_mutant.MUTANT.jsonl");
$v06 = $load("$in/v06_redis.jsonl");
$v07 = array_merge($load("$in/v07_sweep.S0.jsonl"), $load("$in/v07_sweep.CAND.jsonl"));
$m01s0 = $load("$orig/m01_matrix.S0.jsonl");
$m06 = $load("$orig/m06_redis.jsonl");
$case = static fn(array $rows, string $c): array => array_values(array_filter($rows, static fn(array $r): bool => ($r['case'] ?? '') === $c));
$status = static fn(bool $pass, bool $detectorOk): string => ! $detectorOk ? 'INCONCLUSIVE' : ($pass ? 'PASS' : 'FAIL');
$g = [];

// Precondition: the TRACE build reproduces every archived matrix cell.
$neq = array_values(array_filter($v01, static fn(array $r): bool => ! $r['equivalent']));
$g['TRACE-EQUIVALENCE'] = ['status' => $v01 !== [] && $neq === [] ? 'PASS' : 'FAIL', 'cells' => count($v01), 'different' => count($neq),
    'first' => array_slice(array_map(static fn(array $r): string => "{$r['stream']}/{$r['strategy']}/{$r['arm']}/{$r['set']}/{$r['flow']}", $neq), 0, 5)];
$equivalent = $g['TRACE-EQUIVALENCE']['status'] === 'PASS';

// C10-H.
$forb = 0;
$unat = 0;
$bad = [];
foreach ($v01 as $r) {
    if (! in_array($r['arm'], ['CAND', 'ALLU'], true)) {
        continue;
    }
    $forb += $r['c10']['forbidden'];
    $unat += $r['c10']['unattributed'];
    if ($r['c10']['forbidden'] + $r['c10']['unattributed'] > 0) {
        $bad[] = "{$r['stream']}/{$r['strategy']}/{$r['arm']}/{$r['set']}/{$r['flow']}";
    }
}
$c10det = $v05 !== [] && array_reduce($v05, static fn(bool $c, array $r): bool => $c && $r['detected'], true);
$g['C10-H'] = ['status' => $equivalent ? $status($forb === 0 && $unat === 0, $c10det) : 'INCONCLUSIVE', 'forbiddenBenignDenials' => $forb,
    'unattributedBenignDenials' => $unat, 'cells' => array_slice($bad, 0, 10),
    'detector' => ['M-C10' => array_map(static fn(array $r): string => "{$r['stream']} {$r['legitDenied']}/{$r['legitAttempts']} " . json_encode($r['benignOrigins']), $v05), 'allDetected' => $c10det]];

// C9-H.
$mChecked = $mMixed = $mViol = 0;
foreach ($v01 as $r) {
    $mChecked += $r['c9']['checked'];
    $mMixed += $r['c9']['mixedChecked'];
    $mViol += $r['c9']['violations'];
}
$mx = $case($v02, 'C9-H MX')[0] ?? null;
$detA = $case($v02, 'C9-H detector M-C9a (lane-leak)')[0] ?? null;
$detB = $case($v02, 'C9-H detector M-C9b (adm-leak)')[0] ?? null;
$detA0 = $case($v02, 'C9-H detector M-C9a0 (lane-leak)')[0] ?? null;
$c9det = ($detA['detected'] ?? false) && ($detB['detected'] ?? false);
$g['C9-H'] = ['status' => $status($mViol === 0 && ($mx['pass'] ?? false), $c9det),
    'matrix' => ['uEventsChecked' => $mChecked, 'onAccountsWithPState' => $mMixed, 'violations' => $mViol],
    'MX' => ['uEventsChecked' => $mx['checkedUEvents'] ?? null, 'onAccountsWithPState' => $mx['mixedChecked'] ?? null, 'violations' => $mx['violations'] ?? null],
    'detector' => ['M-C9a' => $detA['detected'] ?? null, 'M-C9b' => $detB['detected'] ?? null,
        'M-C9a0 (first placement, no P-specific write; recorded)' => $detA0['detected'] ?? null,
        'originalCheckerDetectsAny' => ($detA['originalCheckerDetects'] ?? false) || ($detB['originalCheckerDetects'] ?? false)]];

// C1-H.
$logOver = $evOver = $countMismatch = [];
foreach ($v01 as $r) {
    if (! isset($r['c1'])) {
        continue;
    }
    $id = "{$r['stream']}/{$r['strategy']}/{$r['arm']}/{$r['set']}/{$r['flow']}";
    if ($r['c1']['logOver'] !== []) {
        $logOver[] = $id;
    }
    if ($r['c1']['eventOver'] !== []) {
        $evOver[] = $id;
    }
    if ($r['c1']['eventAdmissions'] !== null && $r['c1']['eventAdmissions'] !== $r['c1']['logAdmissions']) {
        $countMismatch[] = $id;
    }
}
$r1 = $case($m06, 'R-1 counter race')[0] ?? [];
$r1m = $case($m06, 'R-1m mutation control')[0] ?? [];
$real = array_values(array_filter($v04, static fn(array $r): bool => $r['case'] === 'C1-H real'));
$mut = array_values(array_filter($v04, static fn(array $r): bool => $r['case'] === 'C1-H M-C1 over-cap store'));
$binding = [];
foreach ($real as $i => $r) {
    $binding[] = ['stream' => $r['stream'], 'capBindsInRealRun' => $r['refusals'] > 0, 'realClean' => $r['clean'], 'mutantFlagged' => $mut[$i]['flagged'] ?? null];
}
$c1det = ($r1m['overAdmissionDetected'] ?? false) === true
    && array_reduce($binding, static fn(bool $c, array $b): bool => $c && $b['realClean'] && (! $b['capBindsInRealRun'] || $b['mutantFlagged']), true)
    && array_filter($binding, static fn(array $b): bool => $b['capBindsInRealRun']) !== [];
$g['C1-H'] = ['status' => $status($logOver === [] && $evOver === [] && $countMismatch === [] && ($r1['admittedPerRound'] ?? []) === [3, 3, 3], $c1det),
    'logRecountOver' => $logOver, 'eventRecountOver' => $evOver, 'logVsEventCountMismatch' => $countMismatch,
    'redisRace' => $r1['admittedPerRound'] ?? null, 'R-1m detected' => $r1m['overAdmissionDetected'] ?? null, 'M-C1' => $binding,
    'originalGateDifference' => 'the original C1 PASS did not require R-1m; C1-H does, and adds the event recount and the M-C1 control'];

// R3-H.
$ref = $case($v04, 'R3-H real')[0] ?? [];
$refM = $case($v04, 'R3-H M-R3 partial-write store')[0] ?? [];
$red = $case($v06, 'R3-H Redis real')[0] ?? [];
$redM = $case($v06, 'R3-H Redis M-R3 partial-write store')[0] ?? [];
$r3det = ($refM['hardenedPass'] ?? true) === false && ($redM['hardenedPass'] ?? true) === false;
$redisOk = ($red['hardenedPass'] ?? null) !== null;
$g['R3-H'] = ['status' => $redisOk ? $status(($ref['hardenedPass'] ?? false) && ($red['hardenedPass'] ?? false), $r3det) : 'UNPROVEN (no Redis)',
    'reference' => ['real' => $ref['hardenedPass'] ?? null, 'mutantCaughtByHardened' => ($refM['hardenedPass'] ?? null) === false,
        'mutantSameSourceSeenByOriginal' => isset($refM['original(keysUnchanged)'][1]) ? ! $refM['original(keysUnchanged)'][1] : null],
    'redis' => ['real' => $red['hardenedPass'] ?? null, 'mutantCaughtByHardened' => ($redM['hardenedPass'] ?? null) === false,
        'mutantSameSourceSeenByOriginal' => isset($redM['cases']['same source']) ? ! $redM['cases']['same source']['keysUnchanged'] : null]];

// C4-H.
$a = $case($v03, 'C4-H(a) M1 re-derivation from the DTO')[0] ?? [];
$bRows = array_values(array_filter($v03, static fn(array $r): bool => str_starts_with($r['case'], 'B')));
$bNeg = array_values(array_filter($bRows, static fn(array $r): bool => in_array(substr($r['case'], 0, 2), ['B1', 'B2', 'B3'], true)));
$g['C4-H(a) Host mapping M1'] = ['status' => ($a['pass'] ?? false) && array_reduce($bNeg, static fn(bool $c, array $r): bool => $c && $r['pass'] === true, true) ? 'PASS (conditional on the Host implementing M1)' : 'FAIL',
    'eventsChecked' => $a['eventsChecked'] ?? null, 'pLanes' => $a['pLanes'] ?? null, 'mismatches' => $a['mismatches'] ?? null,
    'negative' => array_map(static fn(array $r): string => "{$r['case']}: {$r['lane']}", $bNeg)];
$g['C4-H(b) package boundary'] = ['status' => 'UNPROVEN', 'tests' => array_map(static fn(array $r): string => $r['case'] . ': ' . ($r['status'] ?? json_encode(array_intersect_key($r, array_flip(['attackerReach', 'attackerAttempts', 'ownerDenied', 'ownerDeniedByCause'])))), array_values(array_filter($bRows, static fn(array $r): bool => in_array(substr($r['case'], 0, 2), ['B4', 'B5'], true))))];

$core = ['C1-H', 'C9-H', 'C10-H', 'R3-H'];
$st = array_map(static fn(string $k): string => $g[$k]['status'], $core);
$g['ADDENDUM-DECISION'] = [
    'status' => in_array('FAIL', $st, true) ? 'NO-GO (a hardened core gate failed)' : (count(array_filter($st, static fn(string $s): bool => $s !== 'PASS')) > 0 ? 'INCOMPLETE (a hardened gate is INCONCLUSIVE or UNPROVEN)' : 'hardened gates PASS; package-boundary C4 UNPROVEN'),
    'hardened' => array_combine($core, $st) + ['C4-H(a)' => $g['C4-H(a) Host mapping M1']['status'], 'C4-H(b)' => 'UNPROVEN'],
];

// Sweep screen (VERIFY_PLAN.md §3).
$s0 = [];
foreach ($m01s0 as $r) {
    if ($r['shape'] !== 'login' || $r['flow'] !== 'pre+out') {
        continue;
    }
    $s0[$r['stream']]['legit'][$r['strategy']] = $r['m']['legitDenied'];
    $s0[$r['stream']]['F'] = max($s0[$r['stream']]['F'] ?? 0, $r['m']['reachForeign']);
    $s0[$r['stream']]['attempts'] = $r['m']['legitAttempts'];
}
foreach ($v07 as $r) {
    if ($r['arm'] === 'S0') {
        $s0[$r['stream']] = ['legit' => ['none' => $r['m']['legitDenied']], 'F' => $r['m']['reachForeign'], 'attempts' => $r['m']['legitAttempts']];
    }
}
$cells = [];
foreach ($v07 as $r) {
    if ($r['arm'] === 'S0') {
        continue;
    }
    $k = "{$r['arm']}|{$r['set']}";
    $id = $r['stream'];
    $cells[$k]['meta'] = ['arm' => $r['arm'], 'set' => $r['set'], 'U_acct' => $r['U_acct'], 'U_src' => $r['U_src']];
    if ($r['strategy'] === 'none' && $r['m']['legitAttempts'] > 0) {
        $cells[$k]['L'][$id] = $r['m']['legitDenied'];
        $cells[$k]['LA'][$id] = $r['m']['legitAttempts'];
    }
    if ($r['m']['attackAttempts'] > 0) {
        $cells[$k]['F'][$id] = max($cells[$k]['F'][$id] ?? 0, $r['m']['reachForeign']);
        $cells[$k]['T'][$id] = max($cells[$k]['T'][$id] ?? 0, $r['target']['maxPerTargetWindow']);
    }
}
$screen = [];
foreach ($cells as $k => $c) {
    $B = $A = [];
    foreach ($c['L'] ?? [] as $id => $n) {
        if (isset($s0[$id]['legit']['none']) && $n > $s0[$id]['legit']['none']) {
            $B[] = $id;
        }
    }
    foreach ($c['F'] ?? [] as $id => $n) {
        if (isset($s0[$id]) && $n > $s0[$id]['F']) {
            $A[] = $id;
        }
    }
    $screen[$k] = $c['meta'] + ['B' => count($B), 'Bstreams' => $B, 'A' => count($A), 'Astreams' => $A, 'dominatesS0' => $B === [] && $A === []];
}
$g['SWEEP-SCREEN'] = ['anySetDominatesS0' => array_values(array_filter(array_keys($screen), static fn(string $k): bool => $screen[$k]['dominatesS0'])), 'cells' => $screen];

file_put_contents("$out/gates_verify.json", json_encode($g, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

// ---- summary ----
$md = "# G1 verification addendum — summary (generated by verify/report_verify.php — do not edit)\n\n";
$md .= "Pre-declared in [`VERIFY_PLAN.md`](../VERIFY_PLAN.md). Original evidence: [`../../expected/`](../../expected/) (unchanged).\n\n";
$md .= "## 1. Hardened gates\n\n| Gate | Status | Evidence |\n| --- | --- | --- |\n";
foreach (['TRACE-EQUIVALENCE', 'C1-H', 'C9-H', 'C10-H', 'R3-H', 'C4-H(a) Host mapping M1', 'C4-H(b) package boundary', 'ADDENDUM-DECISION'] as $k) {
    $e = $g[$k];
    $s = $e['status'];
    unset($e['status']);
    $md .= "| $k | **$s** | `" . str_replace('|', '\\|', json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "` |\n";
}
$md .= "\n## 2. Benign denials re-attributed through the trace build (CAND, set R, pre+out; archived cause → origin)\n\n| Stream | Strategy | Archived cause | Origins |\n| --- | --- | --- | --- |\n";
foreach ($v01 as $r) {
    if ($r['arm'] === 'CAND' && $r['set'] === 'R' && $r['flow'] === 'pre+out' && $r['benignOrigins'] !== []) {
        $md .= "| {$r['stream']} | {$r['strategy']} | `" . json_encode($r['archivedCause']) . '` | `' . str_replace('|', '\\|', json_encode($r['benignOrigins'])) . "` |\n";
    }
}
$md .= "\n## 3. I4b decomposition on the mixed account (owner P decisions; variant 2 presents the owner's sessionDeviceId)\n\n| U variants | Presents owner secret | Owner denied | Owner HARD | Identical to control |\n| --- | --- | --- | --- | --- |\n";
foreach ($case($v02, 'I4b decomposition') as $r) {
    $md .= "| {$r['variants']} | " . ($r['presentsOwnerSecret'] ? 'yes' : 'no') . " | {$r['ownerDenied']}/25 | {$r['ownerHard']} | " . ($r['identicalToControl'] ? 'yes' : '**no**') . " |\n";
}
$ctl = $case($v02, 'I4b decomposition control')[0] ?? null;
$md .= "| control (no U) | — | " . ($ctl['ownerDenied'] ?? '?') . "/25 | " . ($ctl['ownerHard'] ?? '?') . " | — |\n";
$md .= "\n## 4. C4-H boundary tests\n\n";
foreach ($v03 as $r) {
    $md .= '- `' . str_replace('`', "'", json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "`\n";
}
$md .= "\n## 5. U_acct sweep (pre+out). L = legitimate denied (strategy none), F = best-response reach_foreign, T = max reach per target account and window\n\n";
$streams = ['L1', 'ABA_B', 'D2', 'N12', 'Nu4', 'Y6b', 'KD', 'UAC', 'MIX', 'OFF12', 'POI', 'L3', 'D1', 'HAR', 'HARp', 'SP6', 'SP3', 'SP1n', 'ABA_A', 'X2', 'X1', 'X7', 'BRU', 'BRD'];
foreach (['CAND', 'ALLU'] as $arm) {
    $md .= "### $arm" . ($arm === 'ALLU' ? ' (day-0: no proof honoured)' : '') . "\n\n| Stream | S0 |";
    $keys = array_values(array_filter(array_keys($cells), static fn(string $k): bool => str_starts_with($k, "$arm|")));
    foreach ($keys as $k) {
        $md .= ' ' . explode('|', $k)[1] . ' |';
    }
    $md .= "\n| --- | --- |" . str_repeat(' --- |', count($keys)) . "\n";
    foreach ($streams as $id) {
        $s0c = isset($s0[$id]) ? trim((isset($s0[$id]['legit']['none']) && ($s0[$id]['attempts'] ?? 0) > 0 ? "L {$s0[$id]['legit']['none']} " : '') . ($s0[$id]['F'] > 0 || in_array($id, ['SP6', 'SP3', 'SP1n', 'ABA_A', 'X2', 'X1', 'X7', 'BRU', 'BRD', 'POI', 'HAR', 'HARp'], true) ? "F {$s0[$id]['F']}" : '')) : '?';
        $md .= "| $id | $s0c |";
        foreach ($keys as $k) {
            $c = $cells[$k];
            $parts = [];
            if (isset($c['L'][$id])) {
                $parts[] = "L {$c['L'][$id]}/{$c['LA'][$id]}";
            }
            if (isset($c['F'][$id])) {
                $parts[] = "F {$c['F'][$id]} T {$c['T'][$id]}";
            }
            $md .= ' ' . implode(' · ', $parts) . ' |';
        }
        $md .= "\n";
    }
    $md .= "\n";
}
$md .= "### Screen: B = benign streams with more legitimate denials than S0, A = attack streams with more reach_foreign than S0's best response\n\n| Arm | Set | B | A | Dominates S0 on the sample | B streams | A streams |\n| --- | --- | --- | --- | --- | --- | --- |\n";
foreach ($screen as $s) {
    $md .= "| {$s['arm']} | {$s['set']} | {$s['B']} | {$s['A']} | " . ($s['dominatesS0'] ? 'yes' : 'no') . ' | ' . implode(', ', $s['Bstreams']) . ' | ' . implode(', ', $s['Astreams']) . " |\n";
}
file_put_contents("$out/summary_verify.md", $md);
echo 'addendum: ' . $g['ADDENDUM-DECISION']['status'] . "\n";
foreach ($g as $k => $e) {
    if ($k !== 'SWEEP-SCREEN' && $k !== 'ADDENDUM-DECISION') {
        echo "$k {$e['status']}\n";
    }
}
echo 'sweep sets dominating S0: ' . json_encode($g['SWEEP-SCREEN']['anySetDominatesS0']) . "\n";
