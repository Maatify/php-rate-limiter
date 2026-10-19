<?php

declare(strict_types=1);

// G6 report (PLAN.md §3, §5, §6, §7): harness validity (V0 … V4, IN, D-IN, NP-IN), the two gates of arm 1 with their detectors, the verdict, and the
// reported-not-gated measures (the cost side of arm 1, the TMS family of arm 2); writes summary.md and gates.json.
// Exit 5 = V0 / V1 / V2 / V3 / V4 miss; 6 = D-IN miss; 7 = NP-IN miss; 3 = harness valid and arm 1 NO-GO AFTER EXPERIMENT; 0 = arm 1 SUPPORTED — LIMITED SCOPE.
//   php report.php <actual-dir> <out-dir>

$in = $argv[1] ?? '';
$outDir = $argv[2] ?? '';
$k2 = dirname(__DIR__, 2);
$a5 = "$k2/g5/identity_layer_v2/expected";
$a1 = "$k2/g1/expected";

/** @return list<array<string, mixed>> */
function rows(string $file): array
{
    $out = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $out[] = json_decode($l, true, 512, JSON_THROW_ON_ERROR);
    }

    return $out;
}

function skey(array $r): string
{
    return $r['stream'] . '|' . $r['strategy'] . '|' . $r['ids'] . '|' . $r['flow'];
}

function atk(array $r): int
{
    return (int) $r['m']['reachForeign'] + (int) $r['m']['reachProof'];
}

function cnt(string $s): int
{
    return substr_count($s, '1');
}

function jc(mixed $v): string
{
    return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

// ---- inputs ------------------------------------------------------------------------------------------------------------------------
$stAll = rows("$in/g6_streams.G6UK.jsonl");
$fxAll = rows("$in/g6_fixtures.G6UK.jsonl");
$pipeNew = rows("$in/g6_pipeline.G6UK.jsonl");
$tmsRef = rows("$in/g6_tms.G4.jsonl");
$tmsNo = rows("$in/g6_tms.G5V2.jsonl");
$tmsS0 = rows("$in/g6_tms_s0.S0.jsonl");
$post = rows("$in/g6_posthoc.G6UK.jsonl");
$din = rows("$in/v2_detector.G4.jsonl");
$aSt = rows("$a5/g5v2_streams.G5V2.jsonl");
$aFx = rows("$a5/g5v2_fixtures.G5V2.jsonl");
$aCost = rows("$a5/g5v2_costs.G5V2.jsonl");
$aPipe = rows("$a5/g5v2_pipeline.G5V2.jsonl");
$aRefSt = rows("$a5/g5_streams.G4.jsonl");
$aG1 = rows("$a1/m01_matrix.S0.jsonl");

$stArm = ['NOUA2' => [], 'UKD' => []];
$npRows = [];
foreach ($stAll as $r) {
    if ($r['s'] === 'streams') {
        $stArm[$r['arm']][skey($r)] = $r;
    } elseif ($r['s'] === 'np') {
        $npRows[] = $r;
    }
}
$fx = [];
foreach ($fxAll as $r) {
    if ($r['s'] === 'fx') {
        $fx[$r['arm']][$r['fixture']] = $r;
    } elseif ($r['s'] === 'np') {
        $npRows[] = $r;
    }
}
foreach (array_merge($tmsNo) as $r) {
    if ($r['s'] === 'np') {
        $npRows[] = $r;
    }
}
$aStIdx = [];
$aNp = [];
foreach ($aSt as $r) {
    if ($r['s'] === 'streams') {
        $aStIdx[skey($r)] = $r;
    } elseif ($r['s'] === 'np') {
        $aNp[$r['arm'] . '|' . $r['scenario']] = $r;
    }
}
$aFxIdx = [];
foreach ($aFx as $r) {
    if ($r['s'] === 'fx') {
        $aFxIdx[$r['arm']][$r['fixture']] = $r;
    } elseif ($r['s'] === 'np') {
        $aNp[$r['arm'] . '|' . $r['scenario']] = $r;
    }
}
$aSteal = null;
foreach ($aCost as $r) {
    if ($r['s'] === 'fx' && $r['fixture'] === 'STEAL' && $r['arm'] === 'NOUA2') {
        $aSteal = $r;
    }
    if ($r['s'] === 'np' && $r['arm'] === 'NOUA2' && $r['scenario'] === 'STEAL') {
        $aNp['NOUA2|STEAL'] = $r;
    }
}

// ---- NP-IN (the scenarios abort with exit 7 on a violation; the counts are re-checked here) ------------------------------------------
$npBad = [];
foreach ($npRows as $r) {
    if ($r['memberIsIdComponent'] !== 0 || $r['memberIsFingerprint'] !== $r['pAdmitted']) {
        $npBad[] = "{$r['arm']} {$r['scenario']}";
    }
}
if ($npBad !== [] || $npRows === []) {
    fwrite(STDOUT, "NP-IN MISS\n" . implode("\n", $npBad) . "\n");
    exit(7);
}

// ---- D-IN ----------------------------------------------------------------------------------------------------------------------------
$dinOk = count($din) === 3;
foreach ($din as $r) {
    $dinOk = $dinOk && $r['pass'] === true;
}
if (! $dinOk) {
    fwrite(STDOUT, "D-IN MISS\n");
    exit(6);
}

// ---- V0 … V4 -------------------------------------------------------------------------------------------------------------------------
$miss = [];
$v0s = 0;
foreach ($aStIdx as $k => $ar) {
    $v0s++;
    if (($stArm['NOUA2'][$k] ?? null) !== $ar) {
        $miss[] = "V0 stream $k";
    }
}
if (count($stArm['NOUA2']) !== $v0s) {
    $miss[] = 'V0 stream rows ' . count($stArm['NOUA2']) . " vs $v0s";
}
$v0f = 0;
foreach ($aFxIdx as $arm => $list) {
    if (! in_array($arm, ['NOUA2', 'NOUA2+R-NO-BIND'], true)) {
        continue;
    }
    foreach ($list as $name => $ar) {
        $v0f++;
        $new = $fx[$arm][$name] ?? null;
        if ($new !== $ar) {
            $miss[] = "V0 fixture $name|$arm";
        }
    }
}
$newKeys = array_merge(array_keys($fx['NOUA2'] ?? []), array_keys($fx['NOUA2+R-NO-BIND'] ?? []));
foreach (['NOUA2' => $fx['NOUA2'] ?? [], 'NOUA2+R-NO-BIND' => $fx['NOUA2+R-NO-BIND'] ?? []] as $arm => $list) {
    foreach (array_keys($list) as $name) {
        if (! isset($aFxIdx[$arm][$name]) && $name !== 'STEAL') {
            $miss[] = "V0 new fixture without archive row $name|$arm";
        }
    }
}
if (($fx['NOUA2']['STEAL'] ?? null) !== $aSteal) {
    $miss[] = 'V0 fixture STEAL|NOUA2';
}
$v0np = 0;
foreach ($npRows as $r) {
    if ($r['arm'] !== 'NOUA2' || ! isset($aNp['NOUA2|' . $r['scenario']])) {
        continue;
    }
    $v0np++;
    if ($aNp['NOUA2|' . $r['scenario']] !== $r) {
        $miss[] = "V0 np {$r['scenario']}";
    }
}
if ($v0np < 3) {
    $miss[] = "V0 np rows $v0np";
}
$aPipeIdx = [];
foreach ($aPipe as $r) {
    $aPipeIdx[$r['lane'] . '|' . $r['fixture']] = $r;
}
$v1 = 0;
foreach ($pipeNew as $r) {
    if ($r['arm'] !== 'NOUA2') {
        continue;
    }
    $v1++;
    if (($aPipeIdx[$r['lane'] . '|' . $r['fixture']] ?? null) !== $r) {
        $miss[] = "V1 {$r['lane']}|{$r['fixture']}";
    }
}
if ($v1 !== count($aPipeIdx)) {
    $miss[] = "V1 rows $v1 vs " . count($aPipeIdx);
}
if (file_get_contents("$in/g5_s0.S0.jsonl") !== file_get_contents("$a5/g5_s0.S0.jsonl")) {
    $miss[] = 'V2 g5_s0.S0';
}
$aRefIdx = [];
foreach ($aRefSt as $r) {
    $aRefIdx[skey($r)] = $r;
}
$v3 = 0;
foreach ([['REF', $tmsRef, $aRefIdx], ['NOUA2', $tmsNo, $aStIdx]] as [$arm, $list, $idx]) {
    foreach ($list as $r) {
        if ($r['s'] !== 'streams') {
            continue;
        }
        $v3++;
        if (($idx[skey($r)] ?? null) !== $r) {
            $miss[] = "V3 $arm " . skey($r);
        }
    }
}
if ($v3 < 20) {
    $miss[] = "V3 control rows $v3";
}
$g1Idx = [];
foreach ($aG1 as $r) {
    $g1Idx[$r['stream'] . '|' . $r['flow']] = $r;
}
$v4 = 0;
foreach ($tmsS0 as $r) {
    $v4++;
    $g = $g1Idx[$r['stream'] . '|' . $r['flow']] ?? null;
    foreach (['m', 'legitDeniedByCause', 'attackDeniedByCause', 'pLaneForeignProof', 'dec', 'lanes', 'reach', 'kinds'] as $f) {
        if ($g === null || $g[$f] !== $r[$f]) {
            $miss[] = "V4 {$r['stream']}|{$r['flow']} $f";
            break;
        }
    }
}
if ($v4 !== 6) {
    $miss[] = "V4 rows $v4";
}
if ($miss !== []) {
    fwrite(STDOUT, "HARNESS VALIDITY MISS\n" . implode("\n", array_slice($miss, 0, 40)) . "\n");
    exit(5);
}

// ---- gates ---------------------------------------------------------------------------------------------------------------------------
$s0Stable = [];
foreach (rows("$in/g5_s0.S0.jsonl") as $r) {
    if (str_starts_with($r['fixture'], 'STABLE-')) {
        $s0Stable[(int) substr($r['fixture'], 7)] = $r['reach'];
    }
}
$g = ['U1' => ['items' => [], 'pass' => true, 'detector' => false], 'U2' => ['items' => [], 'pass' => true, 'detector' => false]];
$ukLost = $fx['UKD']['LOST']['byInterval'];
$refLost = $aFxIdx['NOUA2']['LOST']['byInterval'];
$detU1 = false;
foreach ([600, 300, 60] as $iv) {
    $ok = $ukLost[$iv]['reach'] <= $s0Stable[$iv];
    $g['U1']['pass'] = $g['U1']['pass'] && $ok;
    $detU1 = $detU1 || $refLost[$iv]['reach'] > $s0Stable[$iv];
    $g['U1']['items'][] = ['check' => "LOST $iv s reach ≤ S0 STABLE-$iv", 'ok' => $ok, 'value' => "UKD {$ukLost[$iv]['reach']} / S0 {$s0Stable[$iv]} / reference NOUA2 {$refLost[$iv]['reach']}"];
}
$g['U1']['detector'] = $detU1;

$bad = [];
$cells = 0;
foreach ($stArm['UKD'] as $k => $r) {
    $cells++;
    if (atk($r) > atk($aStIdx[$k])) {
        $bad[] = $k . ' ' . atk($r) . ' > ' . atk($aStIdx[$k]);
    }
}
$g['U2']['items'][] = ['check' => '1 STREAMS rows: attacker reach (foreign + proof) ≤ reference', 'ok' => $bad === [], 'value' => "$cells rows" . ($bad === [] ? '' : '; violations: ' . implode('; ', array_slice($bad, 0, 10)))];
$cmp = static function (string $name, array $new, array $ref, array $fields) use (&$g): void {
    $bad = [];
    $vals = [];
    foreach ($fields as $f) {
        $n = is_array($f) ? $f[1]($new) : (int) $new[$f];
        $rf = is_array($f) ? $f[1]($ref) : (int) $ref[$f];
        $label = is_array($f) ? $f[0] : $f;
        $vals[] = "$label $n / $rf";
        if ($n > $rf) {
            $bad[] = $label;
        }
    }
    $g['U2']['items'][] = ['check' => "$name ≤ reference", 'ok' => $bad === [], 'value' => implode(', ', $vals)];
};
foreach (['BRU24', 'BRU24-HV'] as $f) {
    $cmp("2 $f attackAdmitted", $fx['UKD'][$f], $aFxIdx['NOUA2'][$f], ['attackAdmitted']);
}
foreach (['HAR-B', 'HAR-N'] as $f) {
    $cmp("3 $f attack reach", $fx['UKD'][$f], $aFxIdx['NOUA2'][$f], [['reachForeign+reachProof', static fn(array $r): int => atk($r)]]);
}
$cmp('4 FORGE', $fx['UKD']['FORGE'], $aFxIdx['NOUA2']['FORGE'], ['thiefPAdmitted', 'thiefUReach']);
$cmp('4 MINT', $fx['UKD']['MINT'], $aFxIdx['NOUA2']['MINT'], ['reach', 'pLane']);
$cmp('4 CROSS', $fx['UKD']['CROSS'], $aFxIdx['NOUA2']['CROSS'], ['victimReach', 'victimPAdmitted']);
$cmp('4 BOOT-X', $fx['UKD+R-NO-BIND']['BOOT-X'] ?? $fx['UKD']['BOOT-X'], $aFxIdx['NOUA2+R-NO-BIND']['BOOT-X'], ['fullAttackReach']);
$cmp('5 STEAL thief', $fx['UKD']['STEAL'], $aSteal, ['thiefPBeforeChange', 'thiefPMaxPerWindow', 'thiefPAfterChange', 'thiefUAfterChange']);
foreach ($g['U2']['items'] as $it) {
    $g['U2']['pass'] = $g['U2']['pass'] && $it['ok'];
}
$byp = $fx['D-UKBYP']['BRU24-HV']['attackAdmitted'];
$g['U2']['detector'] = $byp > $aFxIdx['NOUA2']['BRU24-HV']['attackAdmitted'];
$failed = [];
foreach ($g as $name => $gg) {
    if (! $gg['pass']) {
        $failed[] = $name;
    }
    if (! $gg['detector']) {
        $failed[] = "$name detector";
    }
}
$verdict = $failed === [] ? 'SUPPORTED — LIMITED SCOPE' : 'NO-GO AFTER EXPERIMENT (' . implode(', ', $failed) . ')';

// ---- summary.md ----------------------------------------------------------------------------------------------------------------------
$md = "# G6 budgets — results (generated by report.php)\n\n## Arm 1 verdict (PLAN.md §6)\n\n**$verdict**\n\n";
$md .= "## Harness validity\n\n| Check | Result |\n| --- | --- |\n";
$md .= "| V0 | PASS: `NOUA2` through the new code on `G6UK` equals the G5 v2 archive: $v0s stream rows, $v0f fixture rows, STEAL, $v0np NP-IN rows |\n";
$md .= "| V1 | PASS: $v1 pipeline rows of `G6UK` (U and P lane) equal the G5 v2 archive |\n";
$md .= "| V2 | PASS: the archived `g5_s0` output is byte-identical to the G5 v2 archive |\n";
$md .= "| V3 | PASS: $v3 control rows (`N12`, `SP1n`; `REF` and `NOUA2`) equal the archive field for field |\n";
$md .= "| V4 | PASS: $v4 `S0` rows of `TMS`, `TMSd`, `TMX` equal the G1 archive's `m01` `S0` rows |\n";
$md .= "| IN | PASS (the assertion runs inside every assembled replay) |\n| D-IN | PASS: " . count($din) . " families |\n";
$md .= '| NP-IN | PASS: ' . count($npRows) . ' audited scenario parts, ' . array_sum(array_column($npRows, 'pAdmitted')) . " P admissions audited |\n\n";
$md .= "## Gates\n\n| Gate | Result | Detector |\n| --- | --- | --- |\n";
$md .= '| U1 LOST | ' . ($g['U1']['pass'] ? 'PASS' : 'FAIL') . ' | ' . ($g['U1']['detector'] ? 'fires: the reference `NOUA2` exceeds S0 at an interval' : 'DOES NOT FIRE') . " |\n";
$md .= '| U2 attacker reach | ' . ($g['U2']['pass'] ? 'PASS' : 'FAIL') . ' | ' . ($g['U2']['detector'] ? "fires: `D-UKBYP` admits $byp on BRU24-HV against the reference " . $aFxIdx['NOUA2']['BRU24-HV']['attackAdmitted'] : 'DOES NOT FIRE') . " |\n\n";
foreach ($g as $name => $gg) {
    $md .= "### $name\n\n| Check | Result | Values |\n| --- | --- | --- |\n";
    foreach ($gg['items'] as $it) {
        $md .= "| {$it['check']} | " . ($it['ok'] ? 'PASS' : 'FAIL') . " | {$it['value']} |\n";
    }
    $md .= "\n";
}

// reported: LOST / TOKEN
$md .= "## Reported, not gated — arm 1\n\n### LOST and TOKEN (reach in 24 h at 600 / 300 / 60 s; DEC-021 / S0 STABLE-i: " . implode(' / ', [$s0Stable[600], $s0Stable[300], $s0Stable[60]]) . ")\n\n";
$md .= "| Arm | LOST | TOKEN |\n| --- | --- | --- |\n";
foreach (['NOUA2 (archive)' => $aFxIdx['NOUA2'], 'NOUA2 (new code)' => $fx['NOUA2'], 'UKD' => $fx['UKD'], 'D-UKBYP' => $fx['D-UKBYP']] as $lab => $set) {
    $f = static fn(?array $row): string => $row === null ? '—' : implode(' / ', array_map(static fn(int $iv): string => (string) $row['byInterval'][$iv]['reach'], [600, 300, 60]));
    $md .= "| $lab | " . $f($set['LOST'] ?? null) . ' | ' . $f($set['TOKEN'] ?? null) . " |\n";
}
// pipeline UK lane
$md .= "\n### The UK lane at pipeline level (no admission layer)\n\n| Fixture | S0 | P lane | U lane | UK lane |\n| --- | --- | --- | --- | --- |\n";
$pi = [];
foreach ($pipeNew as $r) {
    $pi[$r['lane'] . '|' . $r['fixture']] = $r;
}
$s0p = [];
foreach (rows("$in/g5_s0.S0.jsonl") as $r) {
    $s0p[$r['fixture']] = $r;
}
foreach (['STABLE-600', 'STABLE-300', 'STABLE-60'] as $fxn) {
    $md .= "| $fxn reach (max K4 score) | {$s0p[$fxn]['reach']} ({$s0p[$fxn]['maxK4score']}) | {$pi["P|$fxn"]['reach']} ({$pi["P|$fxn"]['maxK4score']}) | {$pi["U|$fxn"]['reach']} ({$pi["U|$fxn"]['maxK4score']}) | {$pi["UK|$fxn"]['reach']} ({$pi["UK|$fxn"]['maxK4score']}) |\n";
}
$md .= "| KDL string | {$s0p['KDL']['dec']} | {$pi['P|KDL']['dec']} | {$pi['U|KDL']['dec']} | {$pi['UK|KDL']['dec']} |\n";
$md .= "| UK-lane allowance counter after STABLE-600 / 300 / 60 | — | — | — | " . implode(' / ', array_map(static fn(string $f): string => (string) ($pi["UK|$f"]['allowance'] ?? 'null'), ['STABLE-600', 'STABLE-300', 'STABLE-60'])) . " |\n";

// reported: streams that differ
$md .= "\n### STREAMS rows where `UKD` differs from `NOUA2` (the cost side)\n\n";
$diffRows = [];
$routedTotal = 0;
foreach ($stArm['UKD'] as $k => $r) {
    $n = $aStIdx[$k];
    $routedTotal += $r['routedUk'];
    $same = $r['dec'] === $n['dec'] && $r['lanes'] === $n['lanes'] && $r['reach'] === $n['reach'] && $r['legitDeniedByCause'] === $n['legitDeniedByCause'] && $r['attackDeniedByCause'] === $n['attackDeniedByCause'];
    if (! $same) {
        $diffRows[] = [$k, $n, $r];
    }
}
$md .= count($diffRows) . ' of ' . count($stArm['UKD']) . " rows differ in a decision, lane, reach or cause; $routedTotal requests were routed to the UK lane in all rows.\n\n";
if ($diffRows !== []) {
    $md .= "| Row | legit denied NOUA2 → UKD | attacker reach (foreign + proof) NOUA2 → UKD | own reach | routed to UK | legit causes UKD |\n| --- | --- | --- | --- | --- | --- |\n";
    foreach ($diffRows as [$k, $n, $r]) {
        $md .= "| `$k` | {$n['m']['legitDenied']} → {$r['m']['legitDenied']} | " . atk($n) . ' → ' . atk($r) . " | {$n['m']['reachOwn']} → {$r['m']['reachOwn']} | {$r['routedUk']} | " . jc($r['legitDeniedByCause']) . " |\n";
    }
}
$md .= "\n### Fixtures where `UKD` differs from `NOUA2`\n\n| Fixture | NOUA2 | UKD |\n| --- | --- | --- |\n";
$fxDiff = 0;
foreach ($fx['UKD'] as $name => $r) {
    $n = $fx['NOUA2'][$name] ?? null;
    if ($n === null) {
        continue;
    }
    $a = $n;
    $b = $r;
    unset($a['arm'], $b['arm']);
    if ($a !== $b) {
        $fxDiff++;
        $short = static fn(array $x): string => mb_substr(jc($x), 0, 420);
        $md .= "| $name | `" . $short($a) . '` | `' . $short($b) . "` |\n";
    }
}
if ($fxDiff === 0) {
    $md .= "| (none) | | |\n";
}
$md .= "\n### D-UKBYP (the detector of U2)\n\n| Fixture | attackAdmitted | known-device bucket bypasses |\n| --- | --- | --- |\n";
foreach (['BRU24', 'BRU24-HV'] as $f) {
    $md .= "| $f | {$fx['D-UKBYP'][$f]['attackAdmitted']} | {$fx['D-UKBYP'][$f]['knownBypass']} |\n";
}

// POST-HOC: the causes behind the gated LOST replay
$md .= "\n### POST-HOC (not in the plan, not in any gate): what the gated LOST replay discards\n\n";
$md .= "Added after a development run showed U1 failing. It re-runs the LOST fixture of `NOUA2` and `UKD` unchanged and reads: the attempts, the verifier reach, the account-bucket admissions, the denials by cause, and the recorded failures on the two allowance counters (the unsuffixed U counter and the `_uk` counter). It varies nothing.\n\n";
$md .= "| Arm | Interval | Attempts | Reach | Bucket admissions | Denials by cause | Allowance U | Allowance UK |\n| --- | --- | --- | --- | --- | --- | --- | --- |\n";
foreach ($post as $r) {
    $md .= "| {$r['arm']} | {$r['interval']} s | {$r['attempts']} | {$r['reach']} | {$r['bucketAdmissions']} | " . jc($r['deniedByCause']) . ' | ' . ($r['allowanceU'] ?? 'null') . ' | ' . ($r['allowanceUK'] ?? 'null') . " |\n";
}

// reported: arm 2
$md .= "\n## Reported, not gated — arm 2: the TMS family (verifier reach: attempts reached / attempts; P = of which on the P lane)\n\n";
$md .= "S0 is the repository's own `src/` at this baseline (`4c6ca61`), re-run in this study (V4); `REF` is `FULL2` of G4 v2; `NOUA2` is `FULL2` with the G5 v2 identity layer. Id policies: `fresh` / `const` (attacker mints a new instance id per request / keeps one), `none` (no ids: the U door only).\n\n";
$tmsIdx = ['REF' => [], 'NOUA2' => []];
foreach ([$tmsRef, $tmsNo] as $list) {
    foreach ($list as $r) {
        if ($r['s'] === 'tms') {
            $tmsIdx[$r['arm']][skey($r)] = $r;
        }
    }
}
$s0Idx = [];
foreach ($tmsS0 as $r) {
    $s0Idx[$r['stream'] . '|' . $r['flow']] = $r;
}
$cell = static fn(?array $r): string => $r === null ? '—' : cnt($r['reach']) . '/' . $r['m']['attackAttempts'] . ($r['reachP'] > 0 ? " (P {$r['reachP']})" : '');
$md .= "| Stream | Flow | S0 | REF fresh | REF const | REF none | NOUA2 fresh | NOUA2 const | NOUA2 none | best response REF | best response NOUA2 |\n| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |\n";
$tmsBest = [];
foreach (['TMS', 'TMSd', 'TMX'] as $id) {
    foreach (['pre+out', 'pre'] as $flow) {
        $s0 = $s0Idx["$id|$flow"];
        $line = "| $id | $flow | " . cnt($s0['reach']) . '/' . $s0['m']['attackAttempts'] . ' |';
        foreach (['REF', 'NOUA2'] as $arm) {
            foreach (['fresh', 'const', 'none'] as $pol) {
                $line .= ' ' . $cell($tmsIdx[$arm]["$id|none|$pol|$flow"] ?? null) . ' |';
            }
        }
        foreach (['REF', 'NOUA2'] as $arm) {
            $best = 0;
            foreach (['fresh', 'const', 'none'] as $pol) {
                $best = max($best, cnt($tmsIdx[$arm]["$id|none|$pol|$flow"]['reach']));
            }
            $tmsBest["$id|$flow|$arm"] = $best;
            $line .= " $best/{$s0['m']['attackAttempts']} |";
        }
        $md .= $line . "\n";
    }
}
$md .= "\n### Reach in the first 600 s, maximum on one account, accounts reached, and denial causes of the attacker (policy `fresh` and `none`)\n\n";
$md .= "| Stream | Flow | Arm | Policy | first 600 s | max per account | accounts | attacker denial causes |\n| --- | --- | --- | --- | --- | --- | --- | --- |\n";
foreach (['TMS', 'TMSd', 'TMX'] as $id) {
    foreach (['pre+out', 'pre'] as $flow) {
        $s0 = $s0Idx["$id|$flow"];
        $md .= "| $id | $flow | S0 | — | {$s0['reachFirst600']} | {$s0['reachPerAccountMax']} | {$s0['accountsReached']} | " . jc($s0['attackDeniedByCause']) . " |\n";
        foreach (['REF', 'NOUA2'] as $arm) {
            foreach (['fresh', 'none'] as $pol) {
                $r = $tmsIdx[$arm]["$id|none|$pol|$flow"];
                $md .= "| $id | $flow | $arm | $pol | {$r['reachFirst600']} | {$r['reachPerAccountMax']} | {$r['accountsReached']} | " . jc($r['attackDeniedByCause']) . " |\n";
            }
        }
    }
}
$md .= "\n### History quoted from archives (not re-run here)\n\n| Source | TMS | TMSd | TMX |\n| --- | --- | --- | --- |\n";
$md .= "| #113 `S0` at `ce89cb2`, `pre+out` | 7/61 | 2/200 | 200/200 |\n| #113 `S0` at `ce89cb2`, `pre` | 6/61 | 2/200 | 200/200 |\n";
$md .= '| G1 archive `S0` at `4c6ca61`, `pre+out` (V4) | ' . cnt($s0Idx['TMS|pre+out']['reach']) . '/61 | ' . cnt($s0Idx['TMSd|pre+out']['reach']) . '/200 | ' . cnt($s0Idx['TMX|pre+out']['reach']) . "/200 |\n";
$md .= '| G1 archive `S0` at `4c6ca61`, `pre` (V4) | ' . cnt($s0Idx['TMS|pre']['reach']) . '/61 | ' . cnt($s0Idx['TMSd|pre']['reach']) . '/200 | ' . cnt($s0Idx['TMX|pre']['reach']) . "/200 |\n";
$md .= "| G1 candidate (`G1_VERIFICATION.md` §6.2 item 5, P lane, set R) | 61/61 | 60/200 | 12 F + 5 own-proof P |\n| #119 `MODEL`, relief variants | 61/61 | 60/200 | — |\n";
file_put_contents("$outDir/summary.md", $md);
file_put_contents("$outDir/gates.json", json_encode(['verdict' => $verdict, 'gates' => $g, 'tmsBest' => $tmsBest], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

echo "G6 arm 1 verdict: $verdict\n";
foreach ($g as $name => $gg) {
    echo "  $name  " . ($gg['pass'] ? 'PASS' : 'FAIL') . '  (detector ' . ($gg['detector'] ? 'fires' : 'DOES NOT FIRE') . ")\n";
}
exit($failed === [] ? 0 : 3);
