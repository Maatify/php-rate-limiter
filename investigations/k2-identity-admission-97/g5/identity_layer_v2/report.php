<?php

declare(strict_types=1);

// G5 v2 report (PLAN.md §3, §5, §6, §7): harness validity (V0, V1, V2, V3, IN, D-IN, NP-IN), the five gates with their detectors, the regression
// checks, the verdict, and the reported-not-gated measures; writes summary.md and gates.json.
// Exit 5 = V0 / V1 / V2 / V3 miss; 6 = D-IN miss; 7 = NP-IN miss; 3 = harness valid and NO-GO AFTER EXPERIMENT; 0 = SUPPORTED — LIMITED SCOPE.
//   php report.php <actual-dir> <out-dir>

$in = $argv[1] ?? '';
$outDir = $argv[2] ?? '';
$k2 = dirname(__DIR__, 2);
$arch = "$k2/g4/integrated_v2/expected";
$g5arch = "$k2/g5/identity_layer/expected";

/** @return list<array<string, mixed>> */
function rows(string $file): array
{
    $out = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $out[] = json_decode($l, true, 512, JSON_THROW_ON_ERROR);
    }

    return $out;
}

function withArm(array $r, string $arm): array
{
    $r['arm'] = $arm;

    return $r;
}

$v0 = rows("$in/g5_streams.CAND.jsonl");
$stRef = rows("$in/g5_streams.G4.jsonl");
$stRows = rows("$in/g5v2_streams.G5V2.jsonl");
$stNo = array_values(array_filter($stRows, static fn(array $r): bool => $r['s'] === 'streams'));
$fxRef = rows("$in/g5_fixtures.G4.jsonl");
$fxNo = rows("$in/g5v2_fixtures.G5V2.jsonl");
$costRef = rows("$in/g5v2_costs.G4.jsonl");
$costNo = rows("$in/g5v2_costs.G5V2.jsonl");
$din = rows("$in/v2_detector.G4.jsonl");
$aStreams = rows("$arch/v2_streams.G4.jsonl");
$aV0 = rows("$arch/v2_streams.CAND.jsonl");
$aFx = rows("$arch/v2_fixtures.G4.jsonl");
$g5Ph = rows("$g5arch/g5_posthoc.G5.jsonl");
$g5FxNo = rows("$g5arch/g5_fixtures.G5.jsonl");

// pipeline index: [arm][lane][fixture]; S0 has lane '-'
$pp = [];
foreach (rows("$in/g5_pipeline.G4.jsonl") as $r) {
    $pp[$r['arm']][$r['lane']][$r['fixture']] = $r;
}
foreach (['G5V2', 'G5V2-NOALW', 'G5V2-NOOVF', 'G5V2-NOK5', 'G5V2-NOAE'] as $b) {
    foreach (rows("$in/g5v2_pipeline.$b.jsonl") as $r) {
        $pp[$r['arm']][$r['lane']][$r['fixture']] = $r;
    }
}
foreach (rows("$in/g5_s0.S0.jsonl") as $r) {
    $pp['S0']['-'][$r['fixture']] = $r;
}

// ---- NP-IN (the scenarios abort with exit 7 on a violation; the counts are re-checked here) ----------------------------------------------
$np = [];
foreach (array_merge($stRows, $fxNo, $costRef, $costNo) as $r) {
    if ($r['s'] === 'np') {
        $np[] = $r;
    }
}
$npBad = [];
foreach ($np as $r) {
    $idArm = str_starts_with($r['arm'], 'D-NPID');
    if ($idArm ? $r['memberIsFingerprint'] !== 0 : ($r['memberIsIdComponent'] !== 0 || $r['memberIsFingerprint'] !== $r['pAdmitted'])) {
        $npBad[] = "{$r['arm']} {$r['scenario']}";
    }
}
if ($npBad !== [] || $np === []) {
    fwrite(STDOUT, "NP-IN MISS\n" . implode("\n", $npBad) . "\n");
    exit(7);
}

// ---- V0 / V1 / V2 / V3 ---------------------------------------------------------------------------------------------------------------
$miss = [];
$a0 = [];
foreach ($aV0 as $r) {
    if ($r['s'] === 'v0') {
        $a0[$r['stream'] . '|' . $r['strategy']] = $r;
    }
}
$v0n = 0;
foreach ($v0 as $r) {
    $v0n++;
    if (($a0[$r['stream'] . '|' . $r['strategy']] ?? null) !== $r) {
        $miss[] = "V0 {$r['stream']}|{$r['strategy']}";
    }
}
if ($v0n !== count($a0)) {
    $miss[] = "V0 row count $v0n vs " . count($a0);
}

$strip = static function (array $r): array {
    unset($r['uaVarying'], $r['matVarying']);

    return $r;
};
$aSt = [];
foreach ($aStreams as $r) {
    if ($r['arm'] === 'FULL2') {
        $aSt[$r['stream'] . '|' . $r['strategy'] . '|' . $r['ids'] . '|' . $r['flow']] = $r;
    }
}
$v1s = 0;
foreach ($stRef as $r) {
    if ($r['ids'] === 'none') {
        continue;
    }
    $v1s++;
    $k = $r['stream'] . '|' . $r['strategy'] . '|' . $r['ids'] . '|' . $r['flow'];
    if (($aSt[$k] ?? null) === null || withArm($aSt[$k], 'REF') !== $strip($r)) {
        $miss[] = "V1 stream $k";
    }
}
if ($v1s !== count($aSt)) {
    $miss[] = "V1 stream rows $v1s vs " . count($aSt);
}
$aFxIdx = [];
foreach ($aFx as $r) {
    if (in_array($r['arm'], ['FULL2', 'FULL2+R-NO-BIND'], true)) {
        $aFxIdx[$r['fixture'] . '|' . str_replace('FULL2', 'REF', $r['arm'])] = withArm($r, str_replace('FULL2', 'REF', $r['arm']));
    }
}
$v1f = 0;
foreach ($fxRef as $r) {
    $k = $r['fixture'] . '|' . $r['arm'];
    if (! isset($aFxIdx[$k])) {
        continue;
    }
    $v1f++;
    if ($aFxIdx[$k] !== $r) {
        $miss[] = "V1 fixture $k";
    }
}
if ($v1f !== count($aFxIdx)) {
    $miss[] = "V1 fixture rows $v1f vs " . count($aFxIdx);
}
foreach (['STABLE-600', 'STABLE-300', 'STABLE-60', 'KDL'] as $fx) {
    foreach (['reach', 'dec'] as $f) {
        if (isset($pp['S0']['-'][$fx][$f]) && ($pp['REF']['P'][$fx][$f] ?? null) !== $pp['S0']['-'][$fx][$f]) {
            $miss[] = "V1 pipeline $fx $f (REF P lane vs S0)";
        }
    }
}
// V2: every reference-side output is byte-identical to the G5 archive
$v2files = ['g5_streams.CAND', 'g5_streams.G4', 'g5_fixtures.G4', 'g5_pipeline.G4', 'g5_s0.S0', 'v2_detector.G4', 'v2_cgn.G4'];
foreach ($v2files as $f) {
    if (@file_get_contents("$in/$f.jsonl") !== @file_get_contents("$g5arch/$f.jsonl")) {
        $miss[] = "V2 $f.jsonl differs from the G5 archive";
    }
}
// V3: the primary arm is the arm G5 measured post-hoc; the detector arm is G5's primary; the port reproduces the reference rows
$fxOf = static function (array $rows, string $fx, string $arm): ?array {
    foreach ($rows as $r) {
        if (($r['fixture'] ?? null) === $fx && $r['arm'] === $arm) {
            return $r;
        }
    }

    return null;
};
$core = static fn(array $r): array => array_diff_key($r, ['arm' => 1, 's' => 1]);
$v3 = [];
$phSteal = $fxOf($g5Ph, 'STEAL', 'NOUA-FPN');
$phNp = $fxOf($g5Ph, 'UAX-NP', 'NOUA-FPN');
$v3['NOUA2 STEAL = G5 post-hoc NOUA-FPN'] = $core((array) $fxOf($costNo, 'STEAL', 'NOUA2')) === $core((array) $phSteal);
$v3['NOUA2 UAX-NP = G5 post-hoc NOUA-FPN'] = $core((array) $fxOf($fxNo, 'UAX-NP', 'NOUA2')) === $core((array) $phNp);
foreach ($g5Ph as $ph) {
    if (isset($ph['stream'])) {
        $mine = null;
        foreach ($stNo as $r) {
            if ($r['stream'] === $ph['stream'] && $r['strategy'] === $ph['strategy'] && $r['ids'] === $ph['ids'] && $r['flow'] === $ph['flow']) {
                $mine = $r;
            }
        }
        $same = $mine !== null;
        foreach ($core($ph) as $k => $v) {
            $same = $same && ($mine[$k] ?? null) === $v;
        }
        $v3["NOUA2 stream {$ph['stream']}|{$ph['strategy']}|{$ph['ids']} = G5 post-hoc"] = $same;
    }
}
$g5NoSteal = $fxOf($g5FxNo, 'STEAL', 'NOUA');
$v3['D-NPID STEAL = G5 NOUA STEAL'] = $core((array) $fxOf($costNo, 'STEAL', 'D-NPID')) === $core((array) $g5NoSteal);
$v3['REF STEAL (new code) = G5 archive REF'] = $core((array) $fxOf($costRef, 'STEAL', 'REF')) === $core((array) $fxOf($fxRef, 'STEAL', 'REF'));
$v3['REF UAX-NP (new code) = G5 archive REF'] = false;
foreach ($costRef as $r) {
    if (($r['fixture'] ?? '') === 'UAX-NP-K' && $r['k'] === 12) {
        $v3['REF UAX-NP (new code) = G5 archive REF'] = $core(array_diff_key($r, ['k' => 1, 'fixture' => 1])) === $core(array_diff_key((array) $fxOf($fxRef, 'UAX-NP', 'REF'), ['fixture' => 1]));
    }
}
foreach ($v3 as $k => $okv) {
    if (! $okv) {
        $miss[] = "V3 $k";
    }
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

// ---- helpers -----------------------------------------------------------------------------------------------------------------------
$reachOf = static fn(array $r): int => $r['m']['reachForeign'] + $r['m']['reachProof'];
$bestReach = static function (array $rows, string $arm, string $stream): int {
    $b = 0;
    foreach ($rows as $r) {
        if ($r['arm'] === $arm && $r['stream'] === $stream && $r['flow'] === 'pre+out' && in_array($r['ids'], ['fresh', 'const'], true)) {
            $b = max($b, $r['m']['reachForeign'] + $r['m']['reachProof']);
        }
    }

    return $b;
};
$P = static fn(string $arm, string $lane, string $fx): array => $pp[$arm][$lane][$fx];
$ok = static fn(bool $b): string => $b ? 'PASS' : 'FAIL';
$skey = static fn(array $r): string => $r['stream'] . '|' . $r['strategy'] . '|' . $r['ids'] . '|' . $r['flow'];

$gates = [];

// ---- G1 UAX --------------------------------------------------------------------------------------------------------------------
$g1 = [];
foreach (['P', 'U'] as $lane) {
    $u = $P('NOUA2', $lane, 'UAC12');
    $k = $P('NOUA2', $lane, 'KDL');
    $g1["1 UAC12 string equals KDL ($lane)"] = [$u['dec'] === $k['dec'], "{$u['dec']} / {$k['dec']}"];
    $g1["2 one K5 key, score under it ($lane)"] = [$u['k5SameKey'] && $u['before']['k5key'] === $u['after']['k5key'] && $u['after']['k5score'] !== null, "key {$u['before']['k5key']}, score " . json_encode($u['after']['k5score'])];
    $g1["3 one allowance counter, same value as KDL ($lane)"] = [$u['before']['allowKey'] === $u['after']['allowKey'] && $u['after']['allowance'] === $k['after']['allowance'], 'UAC12 ' . json_encode($u['after']['allowance']) . ' / KDL ' . json_encode($k['after']['allowance'])];
    $x = $P('NOUA2', $lane, 'UAX24');
    $g1["4 UAX24 reach equals control ($lane)"] = [$x['flip'] === $x['ctl'], "{$x['flip']} / {$x['ctl']}"];
}
$asm = $fxOf($fxNo, 'UAX-ASM', 'NOUA2');
$npN = $fxOf($fxNo, 'UAX-NP', 'NOUA2');
$npR = $fxOf($fxRef, 'UAX-NP', 'REF');
$npD = $fxOf($costNo, 'UAX-NP', 'D-NPID');
$g1['5 UAX-ASM stays on P'] = [$asm['flip']['lanes'] === str_repeat('P', 12) && $asm['ctl']['lanes'] === str_repeat('P', 12), $asm['flip']['lanes']];
$g1['6 UAX-NP declared cost: refusals equal REF, every cause cap:p_device'] = [$npN['refused'] === $npR['refused'] && array_keys($npN['causes']) === ($npN['refused'] > 0 ? ['cap:p_device'] : []),
    "refused {$npN['refused']} of 12 (REF {$npR['refused']}), causes " . json_encode($npN['causes'])];
$refFails = [];
foreach (['P', 'U'] as $lane) {
    $u = $P('REF', $lane, 'UAC12');
    $k = $P('REF', $lane, 'KDL');
    $x = $P('REF', $lane, 'UAX24');
    $refFails["1 $lane"] = $u['dec'] !== $k['dec'];
    $refFails["2 $lane"] = ! $u['k5SameKey'];
    $refFails["4 $lane"] = $x['flip'] !== $x['ctl'];
}
$refDetect = in_array(true, $refFails, true);
$gates['G1 UAX'] = ['checks' => $g1, 'detector' => ['REF fails at least one of items 1, 2, 4 and D-NPID refuses fewer than REF on UAX-NP',
    $refDetect && $npD['refused'] < $npR['refused'],
    'REF fails ' . implode(', ', array_keys(array_filter($refFails))) . "; UAX-NP refused REF {$npR['refused']}, D-NPID {$npD['refused']}"]];

// ---- G2 IDM --------------------------------------------------------------------------------------------------------------------
$g2 = [];
$aBru = $fxOf($aFx, 'BRU24', 'FULL2');
$bru = $fxOf($fxNo, 'BRU24', 'NOUA2');
$g2['1 BRU24 admissions ≤ archive'] = [$bru['attackAdmitted'] <= $aBru['attackAdmitted'], "{$bru['attackAdmitted']} / archive {$aBru['attackAdmitted']}"];
foreach (['X1', 'BRU', 'BRD'] as $s) {
    $aBest = 0;
    foreach ($aStreams as $r) {
        if ($r['arm'] === 'FULL2' && $r['stream'] === $s && $r['flow'] === 'pre+out') {
            $aBest = max($aBest, $r['m']['reachForeign'] + $r['m']['reachProof']);
        }
    }
    $n = $bestReach($stNo, 'NOUA2', $s);
    $g2["1 $s best-response reach ≤ archive"] = [$n <= $aBest, "$n / archive $aBest"];
}
$aHar = $fxOf($aFx, 'HAR-N', 'FULL2');
$har = $fxOf($fxNo, 'HAR-N', 'NOUA2');
$harReach = $har['m']['reachForeign'] + $har['m']['reachProof'];
$aHarReach = $aHar['m']['reachForeign'] + $aHar['m']['reachProof'];
$g2['1 HAR-N attack reach ≤ archive'] = [$harReach <= $aHarReach, "$harReach / archive $aHarReach"];
$fg = $fxOf($fxNo, 'FORGE', 'NOUA2');
$fgRef = $fxOf($fxRef, 'FORGE', 'REF');
$g2['2 FORGE thief P = 0, U reach ≤ REF'] = [$fg['thiefPAdmitted'] === 0 && $fg['thiefUReach'] <= $fgRef['thiefUReach'], "P {$fg['thiefPAdmitted']}, U {$fg['thiefUReach']} / REF {$fgRef['thiefUReach']}"];
$mint = $fxOf($fxNo, 'MINT', 'NOUA2');
$mintRef = $fxOf($fxRef, 'MINT', 'REF');
$g2['3 MINT reach ≤ REF and ≤ 52'] = [$mint['reach'] <= $mintRef['reach'] && $mint['reach'] <= 52, "{$mint['reach']} / REF {$mintRef['reach']}"];
$mintArm = $fxOf($fxNo, 'BRU24', 'D-IDMINT');
$gates['G2 IDM'] = ['checks' => $g2, 'detector' => ['D-IDMINT admits > 52 on BRU24', $mintArm['attackAdmitted'] > 52, "D-IDMINT {$mintArm['attackAdmitted']}"]];

// ---- G3 KDP --------------------------------------------------------------------------------------------------------------------
$g3 = [];
$tk = $fxOf($fxNo, 'TOKEN', 'NOUA2');
$tkRef = $fxOf($fxRef, 'TOKEN', 'REF');
foreach ([600, 300, 60] as $i) {
    $s0 = $P('S0', '-', "STABLE-$i")['reach'];
    $g3["1 TOKEN $i s equals S0 and REF"] = [$tk['byInterval'][$i]['reach'] === $s0 && $tkRef['byInterval'][$i]['reach'] === $s0, "NOUA2 {$tk['byInterval'][$i]['reach']} / REF {$tkRef['byInterval'][$i]['reach']} / S0 $s0"];
    $g3["1 pipeline STABLE-$i (P) equals S0"] = [$P('NOUA2', 'P', "STABLE-$i")['reach'] === $s0, "{$P('NOUA2', 'P', "STABLE-$i")['reach']} / S0 $s0"];
}
foreach (['P', 'U'] as $lane) {
    $g3["2 KDL string equals S0 and REF ($lane)"] = [$P('NOUA2', $lane, 'KDL')['dec'] === $P('S0', '-', 'KDL')['dec'] && $P('REF', $lane, 'KDL')['dec'] === $P('S0', '-', 'KDL')['dec'], $P('NOUA2', $lane, 'KDL')['dec']];
    $g3["3 UAC12 reach ≤ S0 ($lane)"] = [$P('NOUA2', $lane, 'UAC12')['reach'] <= $P('S0', '-', 'UAC12')['reach'], "{$P('NOUA2', $lane, 'UAC12')['reach']} / S0 {$P('S0', '-', 'UAC12')['reach']}"];
}
$lost = $fxOf($fxNo, 'LOST', 'NOUA2');
$lostRef = $fxOf($fxRef, 'LOST', 'REF');
foreach ([600, 300, 60] as $i) {
    $g3["4 LOST $i s reach ≤ REF"] = [$lost['byInterval'][$i]['reach'] <= $lostRef['byInterval'][$i]['reach'], "{$lost['byInterval'][$i]['reach']} / REF {$lostRef['byInterval'][$i]['reach']}"];
}
// item 5 (v2): KD and UAC against REF (attacker reach, KD legit-denied) and against the designed behavior (UAC = the same arm's KD)
$refIdx = [];
foreach ($stRef as $r) {
    $refIdx[$skey($r)] = $r;
}
$noIdx = [];
foreach ($stNo as $r) {
    $noIdx[$skey($r)] = $r;
}
$a5 = $b5 = $c5 = true;
$ia = $ib = $ic = [];
foreach ($stNo as $r) {
    if (! in_array($r['stream'], ['KD', 'UAC'], true) || $r['flow'] !== 'pre+out' || $r['ids'] === 'none') {
        continue;
    }
    $q = $refIdx[$skey($r)];
    $a5 = $a5 && $reachOf($r) <= $reachOf($q);
    $ia[] = "{$r['stream']}|{$r['ids']} {$reachOf($r)}/{$reachOf($q)}";
    if ($r['stream'] === 'KD') {
        $b5 = $b5 && $r['m']['legitDenied'] <= $q['m']['legitDenied'];
        $ib[] = "{$r['ids']} {$r['m']['legitDenied']}/{$q['m']['legitDenied']}";
    } else {
        $kd = $noIdx['KD|' . $r['strategy'] . '|' . $r['ids'] . '|pre+out'] ?? null;
        $c5 = $c5 && $kd !== null && $r['dec'] === $kd['dec'] && $r['m']['legitDenied'] === $kd['m']['legitDenied'];
        $ic[] = "{$r['ids']} UAC {$r['dec']} ({$r['m']['legitDenied']}) / KD " . ($kd === null ? 'missing' : "{$kd['dec']} ({$kd['m']['legitDenied']})");
    }
}
$g3['5a streams KD and UAC attacker reach ≤ REF'] = [$a5 && $ia !== [], implode('; ', array_unique($ia))];
$g3['5b stream KD legit-denied ≤ REF'] = [$b5 && $ib !== [], implode('; ', array_unique($ib)) . ' (NOUA2/REF)'];
$g3['5c stream UAC = the same arm\'s KD (one K5 across the browser update)'] = [$c5 && $ic !== [], implode('; ', array_unique($ic))];
$det = [
    $P('D-NOK5', 'P', 'KDL')['dec'] !== $P('NOUA2', 'P', 'KDL')['dec'],
    $P('D-NOALW', 'P', 'STABLE-300')['reach'] !== $P('NOUA2', 'P', 'STABLE-300')['reach'],
];
$gates['G3 KDP'] = ['checks' => $g3, 'detector' => ['D-NOK5 differs on KDL and D-NOALW on STABLE-300 (P)', $det[0] && $det[1],
    "D-NOK5 KDL {$P('D-NOK5', 'P', 'KDL')['dec']}; D-NOALW STABLE-300 {$P('D-NOALW', 'P', 'STABLE-300')['reach']}"]];

// ---- G4 MECH -------------------------------------------------------------------------------------------------------------------
$g4 = [];
$allDet = [];
foreach ([600, 300, 60] as $i) {
    $f = "STABLE-$i";
    $n = $P('NOUA2', 'P', $f);
    $g4["allowance/overflow $f (P): NOUA2 = REF = S0 (reach, K4 score)"] = [$n['reach'] === $P('REF', 'P', $f)['reach'] && $n['reach'] === $P('S0', '-', $f)['reach']
        && $n['maxK4score'] === $P('REF', 'P', $f)['maxK4score'] && $n['maxK4score'] === $P('S0', '-', $f)['maxK4score'] && $n['maxK4score'] > 0,
        "reach {$n['reach']}, max K4 score {$n['maxK4score']} / S0 reach {$P('S0', '-', $f)['reach']}, K4 {$P('S0', '-', $f)['maxK4score']}"];
    $allDet["D-NOALW $f"] = [$P('D-NOALW', 'P', $f)['reach'] > $n['reach'], "{$P('D-NOALW', 'P', $f)['reach']} > {$n['reach']}"];
    $allDet["D-NOOVF $f"] = [$P('D-NOOVF', 'P', $f)['reach'] > $n['reach'], "{$P('D-NOOVF', 'P', $f)['reach']} > {$n['reach']}"];
}
$g4['overflow lands on K4P: D-NOOVF max K4 score below NOUA2 (STABLE-300, P)'] = [$P('D-NOOVF', 'P', 'STABLE-300')['maxK4score'] < $P('NOUA2', 'P', 'STABLE-300')['maxK4score'],
    "{$P('D-NOOVF', 'P', 'STABLE-300')['maxK4score']} < {$P('NOUA2', 'P', 'STABLE-300')['maxK4score']}"];
foreach (['P', 'U'] as $lane) {
    $k = $P('NOUA2', $lane, 'KDL');
    $g4["K5 KDL ($lane): string = REF = S0; persisted on K5 not K4; second device allowed"] = [$k['dec'] === $P('REF', $lane, 'KDL')['dec'] && $k['dec'] === $P('S0', '-', 'KDL')['dec']
        && $k['maxK5Level'] === $P('S0', '-', 'KDL')['maxK5Level'] && $k['maxK5Level'] > 0 && $k['maxK4Level'] === 0 && $k['secondDevice'] === 'A',
        "{$k['dec']}, K5 level {$k['maxK5Level']}, K4 level {$k['maxK4Level']}, second {$k['secondDevice']}"];
    $allDet["D-NOK5 KDL ($lane)"] = [$P('D-NOK5', $lane, 'KDL')['dec'] !== $k['dec'], "{$P('D-NOK5', $lane, 'KDL')['dec']} vs {$k['dec']}"];
    $a = $P('NOUA2', $lane, 'AE');
    $g4["AE ($lane): string = REF = S0; no K4 block; second device allowed"] = [$a['dec'] === $P('REF', $lane, 'AE')['dec'] && $a['dec'] === $P('S0', '-', 'AE')['dec']
        && $a['snap']['blkK4'] === null && $a['secondDevice'] === 'A' && $a['snap']['blkK5'] !== null,
        "{$a['dec']}, K5 block " . json_encode($a['snap']['blkK5']) . ", K4 block " . json_encode($a['snap']['blkK4']) . ", second {$a['secondDevice']}"];
}
$allDet['D-NOAE AE (P): second device not allowed'] = [$P('D-NOAE', 'P', 'AE')['secondDevice'] !== 'A', "second {$P('D-NOAE', 'P', 'AE')['secondDevice']}"];
$allDet['D-NOAE AE (U): main device not escalated'] = [$P('D-NOAE', 'U', 'AE')['dec'] !== $P('NOUA2', 'U', 'AE')['dec'], "{$P('D-NOAE', 'U', 'AE')['dec']} vs {$P('NOUA2', 'U', 'AE')['dec']}"];
$detOk = array_reduce($allDet, static fn(bool $c, array $d): bool => $c && $d[0], true);
$gates['G4 MECH'] = ['checks' => $g4, 'detector' => ['every mechanism detector fires (items below)', $detOk,
    implode('; ', array_map(static fn(string $k, array $d): string => "$k " . ($d[0] ? 'fires' : 'DOES NOT FIRE') . " ({$d[1]})", array_keys($allDet), $allDet))]];
$uLaneAllow = [$P('NOUA2', 'U', 'STABLE-300')['reach'], $P('D-NOALW', 'U', 'STABLE-300')['reach'], $P('D-NOOVF', 'U', 'STABLE-300')['reach']];

// ---- G5 ABS --------------------------------------------------------------------------------------------------------------------
$g5 = [];
$noid = 0;
$noidBad = [];
foreach ($stNo as $r) {
    if ($r['ids'] !== 'none') {
        continue;
    }
    $noid++;
    $q = $refIdx[$r['stream'] . '|' . $r['strategy'] . '|none|' . $r['flow']] ?? null;
    if ($q === null || withArm($strip($q), 'NOUA2') !== $strip($r)) {
        $noidBad[] = $r['stream'] . '|' . $r['strategy'] . '|' . $r['flow'];
    }
}
$g5["1 STREAMS with no ids: NOUA2 = REF on every field ($noid rows)"] = [$noidBad === [] && $noid > 0, $noidBad === [] ? 'identical' : implode(', ', $noidBad)];
$beh = ['dec', 'reach', 'maxK5Level', 'maxK4Level', 'secondDevice', 'sameDevice', 'maxK4score'];
$coreP = static fn(array $r): array => array_diff_key($r, ['arm' => 1, 'lane' => 1, 's' => 1]);
foreach (['NOID-STABLE-300', 'NOID-KDL', 'NOID-UAC12', 'NOID-AE'] as $fx) {
    foreach (['P', 'U'] as $lane) {
        $n = $P('NOUA2', $lane, $fx);
        $okF = $coreP($n) === $coreP($P('REF', $lane, $fx));
        $vsS0 = ! ($fx === 'NOID-STABLE-300' && $lane === 'U');
        if ($vsS0) {
            foreach ($beh as $f) {
                $okF = $okF && ($n[$f] ?? null) === ($P('S0', '-', $fx)[$f] ?? null);
            }
        }
        $g5["2 $fx ($lane): NOUA2 = REF" . ($vsS0 ? ' = S0 (behavioral fields)' : ' (S0 comparison removed: F-G5-04)')] = [$okF, $fx === 'NOID-UAC12' ? "{$n['dec']} (differs from KDL, as DEC-021 ships)" : json_encode($n['dec'] ?? $n['reach'])];
    }
}
$mx = $P('NOUA2', 'U', 'MIXPRES');
$g5['3 MIXPRES (U): K5 keys differ and A unchanged by B'] = [$mx['keysDiffer'] && $mx['aIdentical'], "keys {$mx['k5KeyA']} / {$mx['k5KeyB']}, A {$mx['both']} vs {$mx['ctl']}"];
$gates['G5 ABS'] = ['checks' => $g5, 'detector' => ['D-NOK5 differs from NOUA2 on NOID-KDL (P and U)',
    $P('D-NOK5', 'P', 'NOID-KDL')['dec'] !== $P('NOUA2', 'P', 'NOID-KDL')['dec'] && $P('D-NOK5', 'U', 'NOID-KDL')['dec'] !== $P('NOUA2', 'U', 'NOID-KDL')['dec'],
    "D-NOK5 {$P('D-NOK5', 'U', 'NOID-KDL')['dec']} vs NOUA2 {$P('NOUA2', 'U', 'NOID-KDL')['dec']}"]];

// ---- regression checks ---------------------------------------------------------------------------------------------------------
$reg = [];
foreach (['E2', 'K5U', 'CROSS', 'HAR-B', 'HAR-N', 'BRU24', 'BRU24-HV', 'REC', 'CLEAR'] as $fx) {
    $reg["R:$fx"] = [withArm($fxOf($fxRef, $fx, 'REF'), 'NOUA2') === $fxOf($fxNo, $fx, 'NOUA2'), $fx];
}
foreach (['BOOT', 'BOOT-X'] as $fx) {
    $reg["R:$fx"] = [withArm($fxOf($fxRef, $fx, 'REF+R-NO-BIND'), 'NOUA2+R-NO-BIND') === $fxOf($fxNo, $fx, 'NOUA2+R-NO-BIND'), $fx];
}
$reg['R:CGN-B (archived model script, byte-identical to the archive; V2)'] = [@file_get_contents("$in/v2_cgn.G4.jsonl") === @file_get_contents("$arch/v2_cgn.G4.jsonl"), 'cmp'];
$stealR = $fxOf($fxRef, 'STEAL', 'REF');
$stealN = $fxOf($costNo, 'STEAL', 'NOUA2');
$stealD = $fxOf($costNo, 'STEAL', 'D-NPID');
$reg['R:STEAL (primary arm, rule A)'] = [$stealN['thiefPBeforeChange'] <= $stealR['thiefPBeforeChange'] && $stealN['thiefPMaxPerWindow'] <= $stealR['thiefPMaxPerWindow']
    && $stealN['ownerWaitAfterChange'] === 0 && $stealN['ownerLaneAtAdmission'] === $stealR['ownerLaneAtAdmission'],
    "thief before the change {$stealN['thiefPBeforeChange']} (REF {$stealR['thiefPBeforeChange']}), per window {$stealN['thiefPMaxPerWindow']} (REF {$stealR['thiefPMaxPerWindow']}), owner wait {$stealN['ownerWaitAfterChange']} s, lane {$stealN['ownerLaneAtAdmission']}"];
$stealDetector = $stealD['thiefPBeforeChange'] > $stealR['thiefPBeforeChange'];
$reg['R:STEAL detector (D-NPID thief above REF)'] = [$stealDetector, "D-NPID {$stealD['thiefPBeforeChange']} > REF {$stealR['thiefPBeforeChange']}"];
$varying = [];
$nonVaryBad = [];
$nonVary = 0;
$varyingAtkBad = [];
foreach ($stNo as $r) {
    if ($r['ids'] === 'none') {
        continue;
    }
    $q = $refIdx[$skey($r)];
    if (! $r['matVarying']) {
        $nonVary++;
        if (withArm($strip($q), 'NOUA2') !== $strip($r)) {
            $nonVaryBad[] = $skey($r);
        }
        continue;
    }
    if (withArm($strip($q), 'NOUA2') !== $strip($r)) {
        $varying[$skey($r)] = [$q, $r];
    }
    if ($reachOf($r) > $reachOf($q)) {
        $varyingAtkBad[] = $skey($r);
    }
}
$reg['R:STREAMS'] = [$nonVaryBad === [], "cells that are not material-varying, $nonVary rows: " . ($nonVaryBad === [] ? 'identical' : 'differ in ' . implode(', ', $nonVaryBad))];
$reg['R:STREAMS-UA'] = [$varyingAtkBad === [], 'material-varying cells, attacker reach ≤ REF: ' . ($varyingAtkBad === [] ? 'ok' : implode(', ', $varyingAtkBad))];
$x7Bad = [];
$x7n = 0;
foreach (['rotate', 'uarot'] as $strategy) {
    foreach (['pre+out', 'pre'] as $flow) {
        $k = "X7|$strategy|const|$flow";
        $x7n++;
        if (! isset($noIdx[$k]) || withArm($strip($refIdx[$k]), 'NOUA2') !== $strip($noIdx[$k])) {
            $x7Bad[] = $k;
        }
    }
}
$reg['R:STREAMS-X7'] = [$x7Bad === [], "X7 rotate / uarot, ids const, both flows ($x7n cells): " . ($x7Bad === [] ? 'equal REF on every field' : 'differ in ' . implode(', ', $x7Bad))];

// ---- verdict -------------------------------------------------------------------------------------------------------------------
$failed = [];
foreach ($gates as $g => $d) {
    $pass = array_reduce($d['checks'], static fn(bool $c, array $x): bool => $c && $x[0], true);
    $gates[$g]['pass'] = $pass;
    $gates[$g]['detectorFires'] = $d['detector'][1];
    if (! $pass) {
        $failed[] = substr($g, 0, 2);
    } elseif (! $d['detector'][1]) {
        $failed[] = substr($g, 0, 2) . ' (detector did not fire: not evidenced)';
    }
}
foreach ($reg as $k => $x) {
    if (! $x[0]) {
        $failed[] = $k;
    }
}
$supported = $failed === [];
$verdict = $supported ? 'SUPPORTED — LIMITED SCOPE' : 'NO-GO AFTER EXPERIMENT (' . implode(', ', $failed) . ')';

// ---- write ---------------------------------------------------------------------------------------------------------------------
$s0Stable = [600 => $P('S0', '-', 'STABLE-600')['reach'], 300 => $P('S0', '-', 'STABLE-300')['reach'], 60 => $P('S0', '-', 'STABLE-60')['reach']];
$md = "# G5 v2 identity layer — results (generated by report.php)\n\n";
$md .= "## Verdict\n\n**$verdict**\n\n";
$md .= "## Harness validity\n\n| Check | Result |\n| --- | --- |\n";
$md .= "| V0 | PASS: $v0n / " . count($a0) . " no-id rows equal the G4 v2 archive |\n";
$md .= "| V1 | PASS: $v1s stream rows and $v1f fixture rows of REF equal the archive's FULL2 field for field; REF P-lane STABLE-i and KDL equal S0 |\n";
$md .= "| V2 | PASS: " . count($v2files) . " reference-side outputs (CAND, G4 and S0 scenarios of G5, D-IN, CGN-B) are byte-identical to the G5 archive |\n";
$md .= "| V3 | PASS: " . count($v3) . " equalities with the G5 archive: " . implode('; ', array_keys($v3)) . " |\n";
$md .= "| IN | PASS (the assertion runs inside every replay) |\n| D-IN | PASS: " . count($din) . " families |\n";
$md .= "| NP-IN | PASS: " . count($np) . " audited scenario parts, " . array_sum(array_column($np, 'pAdmitted')) . " P admissions audited |\n\n";
$md .= "## Gates\n\n| Gate | Result | Detector |\n| --- | --- | --- |\n";
foreach ($gates as $g => $d) {
    $md .= "| $g | " . $ok($d['pass']) . ' | ' . ($d['detector'][1] ? 'fires' : 'DOES NOT FIRE') . ': ' . $d['detector'][0] . " |\n";
}
foreach ($gates as $g => $d) {
    $md .= "\n### $g\n\n| Check | Result | Values |\n| --- | --- | --- |\n";
    foreach ($d['checks'] as $name => [$p, $v]) {
        $md .= "| $name | " . $ok($p) . " | " . str_replace('|', '\\|', (string) $v) . " |\n";
    }
    $md .= "\nDetector (" . $d['detector'][0] . '): ' . ($d['detector'][1] ? 'fires' : 'DOES NOT FIRE') . ' — ' . $d['detector'][2] . "\n";
}
$md .= "\n## Regression checks\n\n| Check | Result | Values |\n| --- | --- | --- |\n";
foreach ($reg as $name => [$p, $v]) {
    $md .= "| $name | " . $ok($p) . ' | ' . str_replace('|', '\\|', (string) $v) . " |\n";
}

$md .= "\n## Reported, not gated\n\n### K4P arms and the member material (STEAL family; rule A is the primary arm)\n\n";
$md .= "| Arm | member | K4P rule | STEAL thief P before / after | STEAL owner denied before | STEAL owner wait after (s) | STEAL2 O2 denied before | STEAL2 O2 wait after (s) | MULTITOK P admitted | MULTITOK-S P admitted |\n| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |\n";
foreach (array_merge([['REF', $costRef], ['NOUA2', $costNo], ['NOUA2/B', $costNo], ['NOUA2/C', $costNo], ['D-NPID', $costNo], ['D-NPID/B', $costNo], ['D-NPID/C', $costNo]]) as [$arm, $rowsX]) {
    $sp = ['REF' => ['fingerprint', 'A']] + array_combine(['NOUA2', 'NOUA2/B', 'NOUA2/C', 'D-NPID', 'D-NPID/B', 'D-NPID/C'], [['fingerprint', 'A'], ['fingerprint', 'B'], ['fingerprint', 'C'], ['id', 'A'], ['id', 'B'], ['id', 'C']]);
    $s = $fxOf($rowsX, 'STEAL', $arm);
    $s2 = $fxOf($rowsX, 'STEAL2', $arm);
    $m = $fxOf($rowsX, 'MULTITOK', $arm);
    $ms = $fxOf($rowsX, 'MULTITOK-S', $arm);
    $w = static fn(?int $v): string => $v === null ? 'not admitted by 86,400' : (string) $v;
    $md .= "| $arm | {$sp[$arm][0]} | {$sp[$arm][1]} | {$s['thiefPBeforeChange']} / {$s['thiefPAfterChange']} | {$s['ownerDeniedBeforeChange']} | {$w($s['ownerWaitAfterChange'])} | {$s2['o2DeniedBeforeChange']} | {$w($s2['o2WaitAfterChange'])} | {$m['pAdmitted']} | {$ms['pAdmitted']} |\n";
}
$md .= "\nSTEAL2: O2 is the owner's second device with its own credential; after the password change every older credential is void and only O1's device is re-issued. \"Not admitted by 86,400\": O2 is on the U lane after the bump and the thief's U-lane attempts hold the account bucket for the rest of the day (every arm).\n";
$md .= "\nMULTITOK denials by cause (attacker holding eight credentials, rotating presentation / fixed presentation):\n\n| Arm | rotating | fixed |\n| --- | --- | --- |\n";
foreach ([['REF', $costRef], ['NOUA2', $costNo], ['NOUA2/B', $costNo], ['NOUA2/C', $costNo], ['D-NPID', $costNo], ['D-NPID/B', $costNo], ['D-NPID/C', $costNo]] as [$arm, $rowsX]) {
    $md .= "| $arm | " . str_replace('|', '\\|', json_encode($fxOf($rowsX, 'MULTITOK', $arm)['attackDeniedByCause'])) . ' | ' . str_replace('|', '\\|', json_encode($fxOf($rowsX, 'MULTITOK-S', $arm)['attackDeniedByCause'])) . " |\n";
}
$md .= "\n### UAX-NP-K: refusals against the number of distinct browser majors in one day (N_P = 10)\n\n| Arm | k = 1 | 2 | 5 | 10 | 11 | 12 |\n| --- | --- | --- | --- | --- | --- | --- |\n";
foreach ([['REF', $costRef], ['NOUA2', $costNo], ['D-NPID', $costNo]] as [$arm, $rowsX]) {
    $line = [];
    foreach ($rowsX as $r) {
        if (($r['fixture'] ?? '') === 'UAX-NP-K' && $r['arm'] === $arm) {
            $line[$r['k']] = $r['refused'];
        }
    }
    $md .= "| $arm | " . implode(' | ', array_map(static fn(int $k): string => (string) ($line[$k] ?? '?'), [1, 2, 5, 10, 11, 12])) . " |\n";
}
$md .= "\n### UAC, the owner cost by design (G3 item 5)\n\n";
foreach ($stNo as $r) {
    if ($r['stream'] === 'UAC' && $r['flow'] === 'pre+out' && $r['ids'] === 'fresh') {
        $q = $refIdx[$skey($r)];
        $kd = $noIdx['KD|' . $r['strategy'] . '|fresh|pre+out'];
        $md .= "legit-denied: REF {$q['m']['legitDenied']} (`{$q['dec']}`), NOUA2 {$r['m']['legitDenied']} (`{$r['dec']}`), NOUA2 on KD {$kd['m']['legitDenied']} (`{$kd['dec']}`). Verifier reach NOUA2 {$reachOf($r)}, REF {$reachOf($q)}.\n";
    }
}
$md .= "\n### F-G5-04 — the U-family known device (a U-lane property, independent of this step)\n\n";
$md .= "* `LOST` reach at 600 / 300 / 60 s: NOUA2 " . implode(' / ', array_map(static fn(int $i): string => (string) $lost['byInterval'][$i]['reach'], [600, 300, 60])) . '; REF '
    . implode(' / ', array_map(static fn(int $i): string => (string) $lostRef['byInterval'][$i]['reach'], [600, 300, 60])) . '; DEC-021 (S0) ' . implode(' / ', $s0Stable)
    . '. Pipeline alone on the U lane: ' . implode(' / ', array_map(static fn(int $i): string => (string) $P('NOUA2', 'U', "STABLE-$i")['reach'], [600, 300, 60])) . ".\n";
$u0 = [];
foreach (['NOID-KDL', 'NOID-UAC12', 'NOID-AE'] as $fx) {
    $u0[] = "$fx " . ($coreP($P('NOUA2', 'U', $fx)) === $coreP($P('S0', '-', $fx)) ? 'equal' : 'DIFFERS');
}
$md .= "* U-lane every-recorded-field equality with `S0` (G5's stricter reading of item 2, reported here because the plan text gates the behavioral fields only): " . implode(', ', $u0) . ".\n";
$md .= "* `NOID-STABLE-300`, U lane: NOUA2 {$P('NOUA2', 'U', 'NOID-STABLE-300')['reach']} = REF {$P('REF', 'U', 'NOID-STABLE-300')['reach']}, S0 {$P('S0', '-', 'NOID-STABLE-300')['reach']} (the comparison left the gate).\n";
$md .= "* Allowance and K4 overflow on the U lane (`STABLE-300`, NOUA2 / D-NOALW / D-NOOVF): " . implode(' / ', $uLaneAllow) . ' — ' . ($uLaneAllow[0] === $uLaneAllow[1] && $uLaneAllow[1] === $uLaneAllow[2] ? 'no observable enforcement on the U lane' : 'an effect on the U lane') . ".\n";
$md .= "* **One written proposal for step 4, not measured here:** a known device that lost its token on U stays under DEC-021's allowance and K4 overflow, instead of being bounded only by the account bucket ceiling.\n";
$md .= "\n### Other\n\n";
$md .= "* Forged-id and minted-id reach (NOUA2 / REF): FORGE U reach {$fg['thiefUReach']} / {$fgRef['thiefUReach']}; MINT reach {$mint['reach']} / {$mintRef['reach']}.\n";
$matRows = array_values(array_filter($stNo, static fn(array $r): bool => $r['ids'] !== 'none' && $r['matVarying']));
$uaRows = array_values(array_filter($stNo, static fn(array $r): bool => $r['ids'] !== 'none' && $r['uaVarying']));
$md .= "* STREAMS rows that carry ids: " . ($nonVary + count($matRows)) . "; material-varying (PLAN.md §5.3): " . count($matRows) . " (groups " . implode(', ', array_unique(array_map(static fn(array $r): string => "{$r['stream']}|{$r['strategy']}|{$r['ids']}", $matRows)))
    . "); of which UA-varying under G5's narrower rule: " . count($uaRows) . ".\n";
$md .= "* Material-varying STREAMS cells with a difference (REF → NOUA2): " . count($varying) . ".\n\n";
if ($varying !== []) {
    $md .= "| Cell | legitDenied | reach (foreign + proof) | dec equal |\n| --- | --- | --- | --- |\n";
    foreach ($varying as $k => [$q, $r]) {
        $md .= "| $k | {$q['m']['legitDenied']} → {$r['m']['legitDenied']} | {$reachOf($q)} → {$reachOf($r)} | " . json_encode($q['dec'] === $r['dec']) . " |\n";
    }
}
$md .= "\n### NP-IN counts (P admissions audited against the admission store's member sets)\n\n| Arm | Scenario part | P admissions | member = fingerprint | member = id component |\n| --- | --- | --- | --- | --- |\n";
foreach ($np as $r) {
    $md .= "| {$r['arm']} | {$r['scenario']} | {$r['pAdmitted']} | {$r['memberIsFingerprint']} | {$r['memberIsIdComponent']} |\n";
}
$md .= "\n## Pipeline-level numbers\n\n| Fixture | S0 | REF P | NOUA2 P | REF U | NOUA2 U | D-NOALW P | D-NOOVF P | D-NOK5 P | D-NOAE P |\n| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |\n";
foreach (['STABLE-600', 'STABLE-300', 'STABLE-60'] as $fx) {
    $md .= "| $fx reach | {$P('S0', '-', $fx)['reach']} | {$P('REF', 'P', $fx)['reach']} | {$P('NOUA2', 'P', $fx)['reach']} | {$P('REF', 'U', $fx)['reach']} | {$P('NOUA2', 'U', $fx)['reach']} | {$P('D-NOALW', 'P', $fx)['reach']} | {$P('D-NOOVF', 'P', $fx)['reach']} | {$P('D-NOK5', 'P', $fx)['reach']} | {$P('D-NOAE', 'P', $fx)['reach']} |\n";
}
foreach (['KDL', 'UAC12', 'AE'] as $fx) {
    $md .= "| $fx string | {$P('S0', '-', $fx)['dec']} | {$P('REF', 'P', $fx)['dec']} | {$P('NOUA2', 'P', $fx)['dec']} | {$P('REF', 'U', $fx)['dec']} | {$P('NOUA2', 'U', $fx)['dec']} | {$P('D-NOALW', 'P', $fx)['dec']} | {$P('D-NOOVF', 'P', $fx)['dec']} | {$P('D-NOK5', 'P', $fx)['dec']} | {$P('D-NOAE', 'P', $fx)['dec']} |\n";
}
file_put_contents("$outDir/summary.md", $md);
$json = ['verdict' => $verdict, 'supported' => $supported, 'failed' => $failed,
    'gates' => array_map(static fn(array $d): array => ['pass' => $d['pass'], 'detectorFires' => $d['detectorFires'],
        'checks' => array_map(static fn(array $x): array => ['pass' => $x[0], 'values' => $x[1]], $d['checks'])], $gates),
    'regression' => array_map(static fn(array $x): bool => $x[0], $reg)];
file_put_contents("$outDir/gates.json", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo "G5 v2 verdict: $verdict\n";
foreach ($gates as $g => $d) {
    echo sprintf("  %-9s %s  (detector %s)\n", $g, $ok($d['pass']), $d['detector'][1] ? 'fires' : 'DOES NOT FIRE');
}
exit($supported ? 0 : 3);
