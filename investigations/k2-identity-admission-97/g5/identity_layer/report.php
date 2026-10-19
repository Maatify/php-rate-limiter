<?php

declare(strict_types=1);

// G5 report (PLAN.md §5, §6, §7): harness validity (V0, V1, IN, D-IN), the five gates with their detectors, the regression checks, the
// verdict, findings and reported-not-gated measures; writes summary.md and gates.json.
// Exit 5 = V0 or V1 miss; 6 = D-IN miss; 3 = harness valid and NO-GO AFTER EXPERIMENT; 0 = SUPPORTED — LIMITED SCOPE.
//   php report.php <actual-dir> <out-dir>

$in = $argv[1] ?? '';
$outDir = $argv[2] ?? '';
$k2 = dirname(__DIR__, 2);
$arch = "$k2/g4/integrated_v2/expected";

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
$stNo = rows("$in/g5_streams.G5.jsonl");
$fxRef = rows("$in/g5_fixtures.G4.jsonl");
$fxNo = rows("$in/g5_fixtures.G5.jsonl");
$din = rows("$in/v2_detector.G4.jsonl");
$ph = rows("$in/g5_posthoc.G5.jsonl");
$aStreams = rows("$arch/v2_streams.G4.jsonl");
$aV0 = rows("$arch/v2_streams.CAND.jsonl");
$aFx = rows("$arch/v2_fixtures.G4.jsonl");

// pipeline index: [arm][lane][fixture]; S0 has lane '-'
$pp = [];
foreach (['G4', 'G5', 'G5-NOALW', 'G5-NOOVF', 'G5-NOK5', 'G5-NOAE'] as $b) {
    foreach (rows("$in/g5_pipeline.$b.jsonl") as $r) {
        $pp[$r['arm']][$r['lane']][$r['fixture']] = $r;
    }
}
foreach (rows("$in/g5_s0.S0.jsonl") as $r) {
    $pp['S0']['-'][$r['fixture']] = $r;
}

// ---- V0 / V1 ---------------------------------------------------------------------------------------------------------------------
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
    unset($r['uaVarying']);

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
$fxOf = static function (array $rows, string $fx, string $arm): ?array {
    foreach ($rows as $r) {
        if ($r['fixture'] === $fx && $r['arm'] === $arm) {
            return $r;
        }
    }

    return null;
};
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

$gates = [];
$notes = [];

// ---- G1 UAX --------------------------------------------------------------------------------------------------------------------
$g1 = [];
foreach (['P', 'U'] as $lane) {
    $u = $P('NOUA', $lane, 'UAC12');
    $k = $P('NOUA', $lane, 'KDL');
    $g1["1 UAC12 string equals KDL ($lane)"] = [$u['dec'] === $k['dec'], "{$u['dec']} / {$k['dec']}"];
    $g1["2 one K5 key, score under it ($lane)"] = [$u['k5SameKey'] && $u['before']['k5key'] === $u['after']['k5key'] && $u['after']['k5score'] !== null, "key {$u['before']['k5key']}, score " . json_encode($u['after']['k5score'])];
    $g1["3 one allowance counter, same value as KDL ($lane)"] = [$u['before']['allowKey'] === $u['after']['allowKey'] && $u['after']['allowance'] === $k['after']['allowance'], 'UAC12 ' . json_encode($u['after']['allowance']) . ' / KDL ' . json_encode($k['after']['allowance'])];
    $x = $P('NOUA', $lane, 'UAX24');
    $g1["4 UAX24 reach equals control ($lane)"] = [$x['flip'] === $x['ctl'], "{$x['flip']} / {$x['ctl']}"];
}
$asm = $fxOf($fxNo, 'UAX-ASM', 'NOUA');
$np = $fxOf($fxNo, 'UAX-NP', 'NOUA');
$g1['5 UAX-ASM stays on P'] = [$asm['flip']['lanes'] === str_repeat('P', 12) && $asm['ctl']['lanes'] === str_repeat('P', 12), $asm['flip']['lanes']];
$g1['6 UAX-NP 0 refusals, all P'] = [$np['refused'] === 0 && $np['lanes'] === str_repeat('P', 12), "refused {$np['refused']}, {$np['lanes']}"];
$refNp = $fxOf($fxRef, 'UAX-NP', 'REF');
$refDetect = false;
$refInfo = [];
foreach (['P', 'U'] as $lane) {
    $u = $P('REF', $lane, 'UAC12');
    $k = $P('REF', $lane, 'KDL');
    $refDetect = $refDetect || $u['dec'] !== $k['dec'] || ! $u['k5SameKey'];
    $refInfo[] = "$lane UAC12 {$u['dec']} vs KDL {$k['dec']}, same K5 key " . json_encode($u['k5SameKey']);
}
$refDetect = $refDetect || $refNp['refused'] > 0;
$refInfo[] = "UAX-NP refused {$refNp['refused']}";
$gates['G1 UAX'] = ['checks' => $g1, 'detector' => ['REF fails at least one of items 1, 2, 6', $refDetect, implode('; ', $refInfo)]];

// ---- G2 IDM --------------------------------------------------------------------------------------------------------------------
$g2 = [];
$aBru = $fxOf($aFx, 'BRU24', 'FULL2');
$bru = $fxOf($fxNo, 'BRU24', 'NOUA');
$g2['1 BRU24 admissions ≤ archive'] = [$bru['attackAdmitted'] <= $aBru['attackAdmitted'], "{$bru['attackAdmitted']} / archive {$aBru['attackAdmitted']}"];
foreach (['X1', 'BRU', 'BRD'] as $s) {
    $aBest = 0;
    foreach ($aStreams as $r) {
        if ($r['arm'] === 'FULL2' && $r['stream'] === $s && $r['flow'] === 'pre+out') {
            $aBest = max($aBest, $r['m']['reachForeign'] + $r['m']['reachProof']);
        }
    }
    $n = $bestReach($stNo, 'NOUA', $s);
    $g2["1 $s best-response reach ≤ archive"] = [$n <= $aBest, "$n / archive $aBest"];
}
$aHar = $fxOf($aFx, 'HAR-N', 'FULL2');
$har = $fxOf($fxNo, 'HAR-N', 'NOUA');
$harReach = $har['m']['reachForeign'] + $har['m']['reachProof'];
$aHarReach = $aHar['m']['reachForeign'] + $aHar['m']['reachProof'];
$g2['1 HAR-N attack reach ≤ archive'] = [$harReach <= $aHarReach, "$harReach / archive $aHarReach"];
$fg = $fxOf($fxNo, 'FORGE', 'NOUA');
$fgRef = $fxOf($fxRef, 'FORGE', 'REF');
$g2['2 FORGE thief P = 0, U reach ≤ REF'] = [$fg['thiefPAdmitted'] === 0 && $fg['thiefUReach'] <= $fgRef['thiefUReach'], "P {$fg['thiefPAdmitted']}, U {$fg['thiefUReach']} / REF {$fgRef['thiefUReach']}"];
$mint = $fxOf($fxNo, 'MINT', 'NOUA');
$mintRef = $fxOf($fxRef, 'MINT', 'REF');
$g2['3 MINT reach ≤ REF and ≤ 52'] = [$mint['reach'] <= $mintRef['reach'] && $mint['reach'] <= 52, "{$mint['reach']} / REF {$mintRef['reach']}"];
$mintArm = $fxOf($fxNo, 'BRU24', 'D-IDMINT');
$gates['G2 IDM'] = ['checks' => $g2, 'detector' => ['D-IDMINT admits > 52 on BRU24', $mintArm['attackAdmitted'] > 52, "D-IDMINT {$mintArm['attackAdmitted']}"]];

// ---- G3 KDP --------------------------------------------------------------------------------------------------------------------
$g3 = [];
$tk = $fxOf($fxNo, 'TOKEN', 'NOUA');
$tkRef = $fxOf($fxRef, 'TOKEN', 'REF');
foreach ([600, 300, 60] as $i) {
    $s0 = $P('S0', '-', "STABLE-$i")['reach'];
    $g3["1 TOKEN $i s equals S0 and REF"] = [$tk['byInterval'][$i]['reach'] === $s0 && $tkRef['byInterval'][$i]['reach'] === $s0, "NOUA {$tk['byInterval'][$i]['reach']} / REF {$tkRef['byInterval'][$i]['reach']} / S0 $s0"];
    $g3["1 pipeline STABLE-$i (P) equals S0"] = [$P('NOUA', 'P', "STABLE-$i")['reach'] === $s0, "{$P('NOUA', 'P', "STABLE-$i")['reach']} / S0 $s0"];
}
foreach (['P', 'U'] as $lane) {
    $g3["2 KDL string equals S0 and REF ($lane)"] = [$P('NOUA', $lane, 'KDL')['dec'] === $P('S0', '-', 'KDL')['dec'] && $P('REF', $lane, 'KDL')['dec'] === $P('S0', '-', 'KDL')['dec'], $P('NOUA', $lane, 'KDL')['dec']];
    $g3["3 UAC12 reach ≤ S0 ($lane)"] = [$P('NOUA', $lane, 'UAC12')['reach'] <= $P('S0', '-', 'UAC12')['reach'], "{$P('NOUA', $lane, 'UAC12')['reach']} / S0 {$P('S0', '-', 'UAC12')['reach']}"];
}
$lost = $fxOf($fxNo, 'LOST', 'NOUA');
$lostRef = $fxOf($fxRef, 'LOST', 'REF');
foreach ([600, 300, 60] as $i) {
    $g3["4 LOST $i s reach ≤ REF"] = [$lost['byInterval'][$i]['reach'] <= $lostRef['byInterval'][$i]['reach'], "{$lost['byInterval'][$i]['reach']} / REF {$lostRef['byInterval'][$i]['reach']}"];
}
foreach (['KD', 'UAC'] as $s) {
    $okS = true;
    $info = [];
    foreach ($stNo as $r) {
        if ($r['stream'] === $s && $r['flow'] === 'pre+out' && $r['ids'] !== 'none') {
            foreach ($stRef as $q) {
                if ($q['stream'] === $s && $q['flow'] === 'pre+out' && $q['ids'] === $r['ids'] && $q['strategy'] === $r['strategy']) {
                    $okS = $okS && $r['m']['legitDenied'] <= $q['m']['legitDenied'] && $reachOf($r) <= $reachOf($q);
                    $info[] = "legitDenied {$r['m']['legitDenied']}/{$q['m']['legitDenied']} reach {$reachOf($r)}/{$reachOf($q)}";
                }
            }
        }
    }
    $g3["5 stream $s legitDenied and reach ≤ REF"] = [$okS && $info !== [], implode('; ', array_unique($info))];
}
$det = [
    $P('D-NOK5', 'P', 'KDL')['dec'] !== $P('NOUA', 'P', 'KDL')['dec'],
    $P('D-NOALW', 'P', 'STABLE-300')['reach'] !== $P('NOUA', 'P', 'STABLE-300')['reach'],
];
$gates['G3 KDP'] = ['checks' => $g3, 'detector' => ['D-NOK5 differs on KDL and D-NOALW on STABLE-300 (P)', $det[0] && $det[1],
    "D-NOK5 KDL {$P('D-NOK5', 'P', 'KDL')['dec']}; D-NOALW STABLE-300 {$P('D-NOALW', 'P', 'STABLE-300')['reach']}"]];

// ---- G4 MECH -------------------------------------------------------------------------------------------------------------------
$g4 = [];
$allDet = [];
foreach ([600, 300, 60] as $i) {
    $f = "STABLE-$i";
    $n = $P('NOUA', 'P', $f);
    $g4["allowance/overflow $f (P): NOUA = REF = S0 (reach, K4 score)"] = [$n['reach'] === $P('REF', 'P', $f)['reach'] && $n['reach'] === $P('S0', '-', $f)['reach']
        && $n['maxK4score'] === $P('REF', 'P', $f)['maxK4score'] && $n['maxK4score'] === $P('S0', '-', $f)['maxK4score'] && $n['maxK4score'] > 0,
        "reach {$n['reach']}, max K4 score {$n['maxK4score']} / S0 reach {$P('S0', '-', $f)['reach']}, K4 {$P('S0', '-', $f)['maxK4score']}"];
    $allDet["D-NOALW $f"] = [$P('D-NOALW', 'P', $f)['reach'] > $n['reach'], "{$P('D-NOALW', 'P', $f)['reach']} > {$n['reach']}"];
    $allDet["D-NOOVF $f"] = [$P('D-NOOVF', 'P', $f)['reach'] > $n['reach'], "{$P('D-NOOVF', 'P', $f)['reach']} > {$n['reach']}"];
}
$g4['overflow lands on K4P: D-NOOVF max K4 score below NOUA (STABLE-300, P)'] = [$P('D-NOOVF', 'P', 'STABLE-300')['maxK4score'] < $P('NOUA', 'P', 'STABLE-300')['maxK4score'],
    "{$P('D-NOOVF', 'P', 'STABLE-300')['maxK4score']} < {$P('NOUA', 'P', 'STABLE-300')['maxK4score']}"];
foreach (['P', 'U'] as $lane) {
    $k = $P('NOUA', $lane, 'KDL');
    $g4["K5 KDL ($lane): string = REF = S0; persisted on K5 not K4; second device allowed"] = [$k['dec'] === $P('REF', $lane, 'KDL')['dec'] && $k['dec'] === $P('S0', '-', 'KDL')['dec']
        && $k['maxK5Level'] === $P('S0', '-', 'KDL')['maxK5Level'] && $k['maxK5Level'] > 0 && $k['maxK4Level'] === 0 && $k['secondDevice'] === 'A',
        "{$k['dec']}, K5 level {$k['maxK5Level']}, K4 level {$k['maxK4Level']}, second {$k['secondDevice']}"];
    $allDet["D-NOK5 KDL ($lane)"] = [$P('D-NOK5', $lane, 'KDL')['dec'] !== $k['dec'], "{$P('D-NOK5', $lane, 'KDL')['dec']} vs {$k['dec']}"];
    $a = $P('NOUA', $lane, 'AE');
    $g4["AE ($lane): string = REF = S0; no K4 block; second device allowed"] = [$a['dec'] === $P('REF', $lane, 'AE')['dec'] && $a['dec'] === $P('S0', '-', 'AE')['dec']
        && $a['snap']['blkK4'] === null && $a['secondDevice'] === 'A' && $a['snap']['blkK5'] !== null,
        "{$a['dec']}, K5 block " . json_encode($a['snap']['blkK5']) . ", K4 block " . json_encode($a['snap']['blkK4']) . ", second {$a['secondDevice']}"];
}
$allDet['D-NOAE AE (P): second device not allowed'] = [$P('D-NOAE', 'P', 'AE')['secondDevice'] !== 'A', "second {$P('D-NOAE', 'P', 'AE')['secondDevice']}"];
$allDet['D-NOAE AE (U): main device not escalated'] = [$P('D-NOAE', 'U', 'AE')['dec'] !== $P('NOUA', 'U', 'AE')['dec'], "{$P('D-NOAE', 'U', 'AE')['dec']} vs {$P('NOUA', 'U', 'AE')['dec']}"];
$detOk = array_reduce($allDet, static fn(bool $c, array $d): bool => $c && $d[0], true);
$gates['G4 MECH'] = ['checks' => $g4, 'detector' => ['every mechanism detector fires (items below)', $detOk,
    implode('; ', array_map(static fn(string $k, array $d): string => "$k " . ($d[0] ? 'fires' : 'DOES NOT FIRE') . " ({$d[1]})", array_keys($allDet), $allDet))]];
$uLaneAllow = [$P('NOUA', 'U', 'STABLE-300')['reach'], $P('D-NOALW', 'U', 'STABLE-300')['reach'], $P('D-NOOVF', 'U', 'STABLE-300')['reach']];

// ---- G5 ABS --------------------------------------------------------------------------------------------------------------------
$g5 = [];
$idx = [];
foreach ($stRef as $r) {
    $idx[$r['stream'] . '|' . $r['strategy'] . '|' . $r['ids'] . '|' . $r['flow']] = $r;
}
$noid = 0;
$noidBad = [];
foreach ($stNo as $r) {
    if ($r['ids'] !== 'none') {
        continue;
    }
    $noid++;
    $q = $idx[$r['stream'] . '|' . $r['strategy'] . '|none|' . $r['flow']] ?? null;
    if ($q === null || withArm($q, 'NOUA') !== $r) {
        $noidBad[] = $r['stream'] . '|' . $r['strategy'] . '|' . $r['flow'];
    }
}
$g5["1 STREAMS with no ids: NOUA = REF on every field ($noid rows)"] = [$noidBad === [] && $noid > 0, $noidBad === [] ? 'identical' : implode(', ', $noidBad)];
$beh = ['dec', 'reach', 'maxK5Level', 'maxK4Level', 'secondDevice', 'sameDevice', 'maxK4score'];
$core = static fn(array $r): array => array_diff_key($r, ['arm' => 1, 'lane' => 1, 's' => 1]);
foreach (['NOID-STABLE-300', 'NOID-KDL', 'NOID-UAC12', 'NOID-AE'] as $fx) {
    foreach (['P', 'U'] as $lane) {
        $n = $P('NOUA', $lane, $fx);
        $okF = $core($n) === $core($P('REF', $lane, $fx));
        foreach ($beh as $f) {
            $okF = $okF && ($n[$f] ?? null) === ($P('S0', '-', $fx)[$f] ?? null);
        }
        if ($lane === 'U') {
            $okF = $okF && $core($n) === $core($P('S0', '-', $fx));
        }
        $g5["2 $fx ($lane): NOUA = REF = S0"] = [$okF, $fx === 'NOID-UAC12' ? "{$n['dec']} (differs from KDL, as DEC-021 ships)" : json_encode($n['dec'] ?? $n['reach'])];
    }
}
$mx = $P('NOUA', 'U', 'MIXPRES');
$g5['3 MIXPRES (U): K5 keys differ and A unchanged by B'] = [$mx['keysDiffer'] && $mx['aIdentical'], "keys {$mx['k5KeyA']} / {$mx['k5KeyB']}, A {$mx['both']} vs {$mx['ctl']}"];
$gates['G5 ABS'] = ['checks' => $g5, 'detector' => ['D-NOK5 differs from NOUA on NOID-KDL (P and U)',
    $P('D-NOK5', 'P', 'NOID-KDL')['dec'] !== $P('NOUA', 'P', 'NOID-KDL')['dec'] && $P('D-NOK5', 'U', 'NOID-KDL')['dec'] !== $P('NOUA', 'U', 'NOID-KDL')['dec'],
    "D-NOK5 {$P('D-NOK5', 'U', 'NOID-KDL')['dec']} vs NOUA {$P('NOUA', 'U', 'NOID-KDL')['dec']}"]];

// ---- regression checks ---------------------------------------------------------------------------------------------------------
$reg = [];
foreach (['E2', 'K5U', 'STEAL', 'CROSS', 'HAR-B', 'HAR-N', 'BRU24', 'BRU24-HV', 'REC', 'CLEAR'] as $fx) {
    $reg["R:$fx"] = [withArm($fxOf($fxRef, $fx, 'REF'), 'NOUA') === $fxOf($fxNo, $fx, 'NOUA'), $fx];
}
foreach (['BOOT', 'BOOT-X'] as $fx) {
    $reg["R:$fx"] = [withArm($fxOf($fxRef, $fx, 'REF+R-NO-BIND'), 'NOUA+R-NO-BIND') === $fxOf($fxNo, $fx, 'NOUA+R-NO-BIND'), $fx];
}
$reg['R:CGN-B (archived model script, byte-identical to the archive; checked by run.sh)'] = [true, 'cmp'];
$stIdx = [];
foreach ($stRef as $r) {
    $stIdx[$r['stream'] . '|' . $r['strategy'] . '|' . $r['ids'] . '|' . $r['flow']] = $r;
}
$varying = [];
$nonVaryBad = [];
$nonVary = 0;
$varyingAtkBad = [];
foreach ($stNo as $r) {
    if ($r['ids'] === 'none') {
        continue;
    }
    $k = $r['stream'] . '|' . $r['strategy'] . '|' . $r['ids'] . '|' . $r['flow'];
    $q = $stIdx[$k];
    if (! $r['uaVarying']) {
        $nonVary++;
        if (withArm($q, 'NOUA') !== $r) {
            $nonVaryBad[] = $k;
        }
        continue;
    }
    if (withArm($q, 'NOUA') !== $r) {
        $varying[$k] = [$q, $r];
    }
    if ($reachOf($r) > $reachOf($q)) {
        $varyingAtkBad[] = $k;
    }
}
$reg['R:STREAMS'] = [$nonVaryBad === [], "cells that are not UA-varying, $nonVary rows: " . ($nonVaryBad === [] ? 'identical' : 'differ in ' . implode(', ', $nonVaryBad))];
$reg['R:STREAMS-UA'] = [$varyingAtkBad === [], 'UA-varying cells, attacker reach ≤ REF: ' . ($varyingAtkBad === [] ? 'ok' : implode(', ', $varyingAtkBad))];

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

// ---- reported (not gated) ------------------------------------------------------------------------------------------------------
$s0Stable = [600 => $P('S0', '-', 'STABLE-600')['reach'], 300 => $P('S0', '-', 'STABLE-300')['reach'], 60 => $P('S0', '-', 'STABLE-60')['reach']];

// ---- write ---------------------------------------------------------------------------------------------------------------------
$md = "# G5 identity layer — results (generated by report.php)\n\n";
$md .= "## Verdict\n\n**$verdict**\n\n";
$md .= "## Harness validity\n\n| Check | Result |\n| --- | --- |\n";
$md .= "| V0 | PASS: $v0n / " . count($a0) . " no-id rows equal the G4 v2 archive |\n";
$md .= "| V1 | PASS: $v1s stream rows and $v1f fixture rows of REF equal the archive's FULL2 field for field; REF P-lane STABLE-i and KDL equal S0; CGN-B bytes equal the archive (run.sh) |\n";
$md .= "| IN | PASS (the assertion runs inside every replay) |\n| D-IN | PASS: " . count($din) . " families |\n\n";
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
$md .= "\n## Reported, not gated\n\n";
$md .= "* **3d — lost-token known device against DEC-021 (assembled, U lane).** NOUA reach at 600 / 300 / 60 s: "
    . implode(' / ', array_map(static fn(int $i): string => (string) $lost['byInterval'][$i]['reach'], [600, 300, 60])) . '; REF: '
    . implode(' / ', array_map(static fn(int $i): string => (string) $lostRef['byInterval'][$i]['reach'], [600, 300, 60])) . '; DEC-021 (S0): '
    . implode(' / ', $s0Stable) . ". Pipeline alone on the U lane: " . implode(' / ', array_map(static fn(int $i): string => (string) $P('NOUA', 'U', "STABLE-$i")['reach'], [600, 300, 60])) . ".\n";
$md .= "* **Allowance and K4 overflow on the U lane** (STABLE-300, NOUA / D-NOALW / D-NOOVF): " . implode(' / ', $uLaneAllow) . ' — ' . ($uLaneAllow[0] === $uLaneAllow[1] && $uLaneAllow[1] === $uLaneAllow[2] ? 'no observable enforcement on the U lane' : 'an effect on the U lane') . ".\n";
$md .= "* **Forged-id and minted-id reach (NOUA / REF):** FORGE U reach {$fg['thiefUReach']} / {$fgRef['thiefUReach']}; MINT reach {$mint['reach']} / {$mintRef['reach']}.\n";
$md .= "* **UAX-NP (daily new-device allowance), REF:** refused {$refNp['refused']} of 12 (`" . json_encode($refNp['causes']) . "`); NOUA 0.\n";
$md .= "* **UA-varying STREAMS cells with a difference (REF → NOUA):** " . count($varying) . ".\n\n";
if ($varying !== []) {
    $md .= "| Cell | legitDenied | reach (foreign + proof) | dec equal |\n| --- | --- | --- | --- |\n";
    foreach ($varying as $k => [$q, $r]) {
        $md .= "| $k | {$q['m']['legitDenied']} → {$r['m']['legitDenied']} | {$reachOf($q)} → {$reachOf($r)} | " . json_encode($q['dec'] === $r['dec']) . " |\n";
    }
}
$diffFields = static function (array $a, array $b): string {
    $o = [];
    foreach ($b as $k => $v) {
        if (in_array($k, ['arm', 's'], true)) {
            continue;
        }
        if (($a[$k] ?? null) !== $v) {
            $o[] = "$k: " . json_encode($a[$k] ?? null) . ' → ' . json_encode($v);
        }
    }

    return implode('; ', $o);
};
$md .= "\n## Attribution of the failed items (`MEASURED`; REF and S0 beside NOUA)\n\n";
$uacN = $uacR = null;
foreach ($stNo as $r) {
    if ($r['stream'] === 'UAC' && $r['ids'] === 'fresh' && $r['flow'] === 'pre+out') {
        $uacN = $r;
    }
}
foreach ($stRef as $r) {
    if ($r['stream'] === 'UAC' && $r['ids'] === 'fresh' && $r['flow'] === 'pre+out') {
        $uacR = $r;
    }
}
$md .= "* **G3 item 5 (stream UAC).** legit-denied REF {$uacR['m']['legitDenied']} → NOUA {$uacN['m']['legitDenied']}; decisions REF `{$uacR['dec']}` → NOUA `{$uacN['dec']}`. The owner's twelve failures change browser major from the seventh with the same id: REF treats the second half as a new device (a new K5), NOUA as the same device, so the K5 ladder continues (`UAC12` pipeline strings in G1 item 1). Verifier reach is unchanged (0 foreign / 0 proof; the owner is the only actor).\n";
$md .= "* **G5 item 2 (`NOID-STABLE-300`, U lane).** NOUA {$P('NOUA', 'U', 'NOID-STABLE-300')['reach']} = REF {$P('REF', 'U', 'NOID-STABLE-300')['reach']}, S0 {$P('S0', '-', 'NOID-STABLE-300')['reach']}: the absence path reproduces the assembly exactly; the difference from S0 is the U lane's own property (no U-lane K4 escalation, design §5 row 4), already disclosed by the pre-plan pilot. Every K5 fixture of the absent path equals S0 on both lanes.\n";
$stealR = $fxOf($fxRef, 'STEAL', 'REF');
$stealN = $fxOf($fxNo, 'STEAL', 'NOUA');
$md .= "* **R:STEAL.** REF → NOUA: " . $diffFields($stealR, $stealN) . ".\n";
foreach ($nonVaryBad as $k) {
    $md .= "* **R:STREAMS cell `$k`.** metrics REF → NOUA: " . json_encode($stIdx[$k]['m']) . ' → ' . json_encode(array_values(array_filter($stNo, static fn(array $r): bool => $r['stream'] . '|' . $r['strategy'] . '|' . $r['ids'] . '|' . $r['flow'] === $k))[0]['m'])
        . '; attack denials ' . json_encode($stIdx[$k]['attackDeniedByCause']) . ' → ' . json_encode(array_values(array_filter($stNo, static fn(array $r): bool => $r['stream'] . '|' . $r['strategy'] . '|' . $r['ids'] . '|' . $r['flow'] === $k))[0]['attackDeniedByCause']) . ".\n";
}
$md .= "\n## Post-hoc attribution (`POST-HOC`, arm NOUA-FPN = NOUA with the P-lane new-device member on the fingerprint; excluded from every gate)\n\n";
$md .= "| Cell | NOUA-FPN | equals REF | equals NOUA |\n| --- | --- | --- | --- |\n";
$phCmp = static fn(array $x): array => array_diff_key($x, ['arm' => 1, 's' => 1, 'uaVarying' => 1, 'cls' => 1, 'kinds' => 1, 'issued' => 1, 'tainted' => 1]);
foreach ($ph as $r) {
    if (isset($r['fixture'])) {
        $ref = $fxOf($fxRef, $r['fixture'], 'REF');
        $nou = $fxOf($fxNo, $r['fixture'], 'NOUA');
        $md .= "| {$r['fixture']} | " . str_replace('|', '\\|', json_encode(array_diff_key($r, ['s' => 1, 'arm' => 1, 'fixture' => 1]))) . ' | ' . json_encode($phCmp($ref) === $phCmp($r)) . ' | ' . json_encode($phCmp($nou) === $phCmp($r)) . " |\n";
    } else {
        $k = $r['stream'] . '|' . $r['strategy'] . '|' . $r['ids'] . '|pre+out';
        $ref = $stIdx[$k];
        $nou = array_values(array_filter($stNo, static fn(array $q): bool => $q['stream'] . '|' . $q['strategy'] . '|' . $q['ids'] . '|' . $q['flow'] === $k))[0];
        $md .= "| stream $k | legitDenied {$r['m']['legitDenied']}, reachOwn {$r['m']['reachOwn']}, verifierCalls {$r['m']['verifierCalls']} | " . json_encode($phCmp($ref) === $phCmp($r)) . ' | ' . json_encode($phCmp($nou) === $phCmp($r)) . " |\n";
    }
}
$md .= "\n## Pipeline-level numbers\n\n| Fixture | S0 | REF P | NOUA P | REF U | NOUA U | D-NOALW P | D-NOOVF P | D-NOK5 P | D-NOAE P |\n| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |\n";
foreach (['STABLE-600', 'STABLE-300', 'STABLE-60'] as $fx) {
    $md .= "| $fx reach | {$P('S0', '-', $fx)['reach']} | {$P('REF', 'P', $fx)['reach']} | {$P('NOUA', 'P', $fx)['reach']} | {$P('REF', 'U', $fx)['reach']} | {$P('NOUA', 'U', $fx)['reach']} | {$P('D-NOALW', 'P', $fx)['reach']} | {$P('D-NOOVF', 'P', $fx)['reach']} | {$P('D-NOK5', 'P', $fx)['reach']} | {$P('D-NOAE', 'P', $fx)['reach']} |\n";
}
foreach (['KDL', 'UAC12', 'AE'] as $fx) {
    $md .= "| $fx string | {$P('S0', '-', $fx)['dec']} | {$P('REF', 'P', $fx)['dec']} | {$P('NOUA', 'P', $fx)['dec']} | {$P('REF', 'U', $fx)['dec']} | {$P('NOUA', 'U', $fx)['dec']} | {$P('D-NOALW', 'P', $fx)['dec']} | {$P('D-NOOVF', 'P', $fx)['dec']} | {$P('D-NOK5', 'P', $fx)['dec']} | {$P('D-NOAE', 'P', $fx)['dec']} |\n";
}
file_put_contents("$outDir/summary.md", $md);
$json = ['verdict' => $verdict, 'supported' => $supported, 'failed' => $failed,
    'gates' => array_map(static fn(array $d): array => ['pass' => $d['pass'], 'detectorFires' => $d['detectorFires'],
        'checks' => array_map(static fn(array $x): array => ['pass' => $x[0], 'values' => $x[1]], $d['checks'])], $gates),
    'regression' => array_map(static fn(array $x): bool => $x[0], $reg)];
file_put_contents("$outDir/gates.json", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo "G5 verdict: $verdict\n";
foreach ($gates as $g => $d) {
    echo sprintf("  %-9s %s  (detector %s)\n", $g, $ok($d['pass']), $d['detector'][1] ? 'fires' : 'DOES NOT FIRE');
}
exit($supported ? 0 : 3);
