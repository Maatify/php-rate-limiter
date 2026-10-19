<?php

declare(strict_types=1);

// G7 report (PLAN.md §3, §5, §6, §7): harness validity (V0, V1, V2, IN, D-IN, NP-IN), the gates X1, X3, X4 with their detectors, the declared cost X2, the verdict and
// the reported measures; writes summary.md and gates.json.
// Exit 5 = V0 / V1 / V2 miss; 6 = D-IN miss; 7 = NP-IN miss; 3 = harness valid and NO-GO AFTER EXPERIMENT; 0 = SUPPORTED — LIMITED SCOPE.
//   php report.php <actual-dir> <out-dir>

$in = $argv[1] ?? '';
$outDir = $argv[2] ?? '';
$k2 = dirname(__DIR__, 2);
$a5 = "$k2/g5/identity_layer_v2/expected";
$a6 = "$k2/g6/budgets/expected";

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

function reach(array $r): int
{
    return substr_count($r['reach'], '1');
}

function jc(mixed $v): string
{
    return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

// ---- inputs ----------------------------------------------------------------------------------------------------------------------
$stAll = rows("$in/g7_streams.G5V2.jsonl");
$fxAll = rows("$in/g7_fixtures.G5V2.jsonl");
$din = rows("$in/v2_detector.G4.jsonl");
$aSt = rows("$a5/g5v2_streams.G5V2.jsonl");
$aFx = rows("$a5/g5v2_fixtures.G5V2.jsonl");
$aCost = rows("$a5/g5v2_costs.G5V2.jsonl");
$aTms = rows("$a6/g6_tms.G5V2.jsonl");

$S = [];   // [arm][kind][key] => row   (kind: streams | tms | xs)
$npRows = [];
foreach (array_merge($stAll, $fxAll) as $r) {
    if ($r['s'] === 'np') {
        $npRows[] = $r;
    } elseif (in_array($r['s'], ['streams', 'tms', 'xs'], true)) {
        $S[$r['arm']][$r['s']][skey($r)] = $r;
    }
}
$fx = [];
foreach ($fxAll as $r) {
    if ($r['s'] === 'fx') {
        $fx[$r['arm']][$r['fixture']] = $r;
    }
}
$aStIdx = [];
$aNp = [];
foreach ($aSt as $r) {
    if ($r['s'] === 'streams') {
        $aStIdx[skey($r)] = $r;
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
$aTmsIdx = [];
foreach ($aTms as $r) {
    if ($r['s'] === 'tms') {
        $aTmsIdx[skey($r)] = $r;
    }
}

// ---- NP-IN, D-IN -----------------------------------------------------------------------------------------------------------------
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
$dinOk = count($din) === 3;
foreach ($din as $r) {
    $dinOk = $dinOk && $r['pass'] === true;
}
if (! $dinOk) {
    fwrite(STDOUT, "D-IN MISS\n");
    exit(6);
}

// ---- V0, V1, V2 --------------------------------------------------------------------------------------------------------------------
$miss = [];
$v0s = 0;
foreach ($aStIdx as $k => $ar) {
    $v0s++;
    if (($S['NOUA2']['streams'][$k] ?? null) !== $ar) {
        $miss[] = "V0 stream $k";
    }
}
if (count($S['NOUA2']['streams'] ?? []) !== $v0s) {
    $miss[] = 'V0 stream rows ' . count($S['NOUA2']['streams'] ?? []) . " vs $v0s";
}
$v0f = 0;
foreach ($aFxIdx as $arm => $list) {
    if (! in_array($arm, ['NOUA2', 'NOUA2+R-NO-BIND'], true)) {
        continue;
    }
    foreach ($list as $name => $ar) {
        $v0f++;
        if (($fx[$arm][$name] ?? null) !== $ar) {
            $miss[] = "V0 fixture $name|$arm";
        }
    }
}
if (($fx['NOUA2']['STEAL'] ?? null) !== $aSteal) {
    $miss[] = 'V0 fixture STEAL|NOUA2';
}
$v0np = 0;
foreach ($npRows as $r) {
    if ($r['arm'] === 'NOUA2' && in_array($r['scenario'], ['FIXTURES', 'STEAL'], true)) {
        $v0np++;
        if (($aNp['NOUA2|' . $r['scenario']] ?? null) !== $r) {
            $miss[] = "V0 np {$r['scenario']}";
        }
    }
}
if ($v0np !== 2) {
    $miss[] = "V0 np rows $v0np";
}
$v1 = 0;
foreach ($S['NOUA2']['tms'] ?? [] as $k => $r) {
    $v1++;
    if (($aTmsIdx[$k] ?? null) !== $r) {
        $miss[] = "V1 $k";
    }
}
if ($v1 !== count($aTmsIdx) || $v1 !== 18) {
    $miss[] = "V1 rows $v1 vs " . count($aTmsIdx);
}
if (file_get_contents("$in/g6_tms_s0.S0.jsonl") !== file_get_contents("$a6/g6_tms_s0.S0.jsonl")) {
    $miss[] = 'V2 g6_tms_s0.S0';
}
if ($miss !== []) {
    fwrite(STDOUT, "HARNESS VALIDITY MISS\n" . implode("\n", array_slice($miss, 0, 40)) . "\n");
    exit(5);
}

// ---- gates -------------------------------------------------------------------------------------------------------------------------
$g = ['X1' => ['items' => [], 'pass' => true, 'detector' => false], 'X3' => ['items' => [], 'pass' => true, 'detector' => true], 'X4' => ['items' => [], 'pass' => true, 'detector' => true]];
$ref = 'NOUA2';
$px = 'PX4';

// X1
$bad = [];
$n = 0;
foreach (['streams', 'xs'] as $kind) {
    foreach ($S[$px][$kind] ?? [] as $k => $r) {
        if (! in_array($r['cls'], ['benign', 'both'], true) || $r['stream'] === 'TPAIRT') {
            continue;
        }
        $n++;
        $rf = $S[$ref][$kind][$k];
        if ($r['m']['legitDenied'] > $rf['m']['legitDenied']) {
            $bad[] = "$k {$r['m']['legitDenied']} > {$rf['m']['legitDenied']}";
        }
    }
}
$g['X1']['items'][] = ['check' => "legit denied ≤ reference on N12T and the legitimate STREAMS rows, `pre+out` and `pre` ($n rows)", 'ok' => $bad === [], 'value' => $bad === [] ? 'no row above' : implode('; ', array_slice($bad, 0, 8))];
$n12t = [];
foreach (['pre+out', 'pre'] as $flow) {
    foreach (['fresh', 'none'] as $pol) {
        $k = "N12T|none|$pol|$flow";
        $n12t[] = "$flow/$pol: " . $S[$px]['xs'][$k]['m']['legitDenied'] . ' (ref ' . $S[$ref]['xs'][$k]['m']['legitDenied'] . ')';
    }
}
$g['X1']['items'][] = ['check' => 'N12T legit denied (PX4 / reference)', 'ok' => true, 'value' => implode('; ', $n12t)];
$detX1 = [];
foreach (['N12T' => 'xs', 'N12' => 'streams'] as $id => $kind) {
    foreach (['fresh', 'none'] as $pol) {
        $k = "$id|none|$pol|pre+out";
        $d = $S['D-PXALL'][$kind][$k]['m']['legitDenied'] ?? null;
        $rv = $S[$ref][$kind][$k]['m']['legitDenied'];
        $detX1[] = "$k D-PXALL $d / ref $rv";
        $g['X1']['detector'] = $g['X1']['detector'] || ($d !== null && $d > $rv);
    }
}
$g['X1']['detail'] = implode('; ', $detX1);
foreach ($g['X1']['items'] as $it) {
    $g['X1']['pass'] = $g['X1']['pass'] && $it['ok'];
}

// best response per stream and flow
$best = static function (string $arm, string $id, string $flow) use ($S): int {
    $b = 0;
    foreach (['fresh', 'const', 'none'] as $pol) {
        $b = max($b, reach($S[$arm]['tms']["$id|none|$pol|$flow"]));
    }

    return $b;
};
// X3
foreach (['pre+out', 'pre'] as $flow) {
    $a = $best($px, 'TMX', $flow);
    $b = $best($ref, 'TMX', $flow);
    $g['X3']['items'][] = ['check' => "TMX best response, $flow, ≤ reference", 'ok' => $a <= $b, 'value' => "$a / ref $b"];
    $g['X3']['pass'] = $g['X3']['pass'] && $a <= $b;
}
// X4
$bad = [];
$cells = 0;
foreach (['streams', 'xs', 'tms'] as $kind) {
    foreach ($S[$px][$kind] ?? [] as $k => $r) {
        $cells++;
        if (atk($r) > atk($S[$ref][$kind][$k])) {
            $bad[] = "$k " . atk($r) . ' > ' . atk($S[$ref][$kind][$k]);
        }
    }
}
$g['X4']['items'][] = ['check' => "STREAMS, N12T / TPAIRT, TMS family rows: attacker reach (foreign + proof) ≤ reference ($cells rows)", 'ok' => $bad === [], 'value' => $bad === [] ? 'no row above' : implode('; ', array_slice($bad, 0, 8))];
$cmp = static function (string $name, array $new, array $old, array $fields) use (&$g): void {
    $bad = [];
    $vals = [];
    foreach ($fields as $f) {
        $x = is_array($f) ? $f[1]($new) : (int) $new[$f];
        $y = is_array($f) ? $f[1]($old) : (int) $old[$f];
        $vals[] = (is_array($f) ? $f[0] : $f) . " $x / $y";
        if ($x > $y) {
            $bad[] = is_array($f) ? $f[0] : $f;
        }
    }
    $g['X4']['items'][] = ['check' => "$name ≤ reference", 'ok' => $bad === [], 'value' => implode(', ', $vals)];
};
foreach (['BRU24', 'BRU24-HV'] as $f) {
    $cmp($f . ' attackAdmitted', $fx[$px][$f], $fx[$ref][$f], ['attackAdmitted']);
}
foreach (['HAR-B', 'HAR-N'] as $f) {
    $cmp("$f attack reach", $fx[$px][$f], $fx[$ref][$f], [['reachForeign+reachProof', static fn(array $r): int => atk($r)]]);
}
$cmp('FORGE', $fx[$px]['FORGE'], $fx[$ref]['FORGE'], ['thiefPAdmitted', 'thiefUReach']);
$cmp('MINT', $fx[$px]['MINT'], $fx[$ref]['MINT'], ['reach', 'pLane']);
$cmp('CROSS', $fx[$px]['CROSS'], $fx[$ref]['CROSS'], ['victimReach', 'victimPAdmitted']);
$cmp('BOOT-X', $fx["$px+R-NO-BIND"]['BOOT-X'], $fx["$ref+R-NO-BIND"]['BOOT-X'], ['fullAttackReach']);
$cmp('STEAL thief', $fx[$px]['STEAL'], $fx[$ref]['STEAL'], ['thiefPBeforeChange', 'thiefPMaxPerWindow', 'thiefPAfterChange', 'thiefUAfterChange']);
foreach ($g['X4']['items'] as $it) {
    $g['X4']['pass'] = $g['X4']['pass'] && $it['ok'];
}
// detector of the study: the reference returns 61/61 and 120/200
$rTms = $best($ref, 'TMS', 'pre+out');
$rTmsd = $best($ref, 'TMSd', 'pre+out');
$detTms = $rTms === 61 && $rTmsd === 120;
$failed = [];
foreach ($g as $name => $gg) {
    if (! $gg['pass']) {
        $failed[] = $name;
    }
    if (! $gg['detector']) {
        $failed[] = "$name detector";
    }
}
if (! $detTms) {
    $failed[] = 'TMS detector (reference 61/61 and 120/200)';
}
$verdict = $failed === [] ? 'SUPPORTED — LIMITED SCOPE' : 'NO-GO AFTER EXPERIMENT (' . implode(', ', $failed) . ')';

// ---- summary.md --------------------------------------------------------------------------------------------------------------------
$md = "# G7 cross-account P — results (generated by report.php)\n\n## Verdict (PLAN.md §6)\n\n**$verdict**\n\n";
$md .= "## Harness validity\n\n| Check | Result |\n| --- | --- |\n";
$md .= "| V0 | PASS: `NOUA2` through the G7 code equals the G5 v2 archive: $v0s stream rows, $v0f fixture rows, STEAL, $v0np NP-IN rows |\n";
$md .= "| V1 | PASS: $v1 `NOUA2` rows of `TMS`, `TMSd`, `TMX` equal the G6 archive |\n| V2 | PASS: the archived `g6_tms_s0` output is byte-identical to the G6 archive |\n";
$md .= "| IN | PASS (the assertion runs inside every replay) |\n| D-IN | PASS: " . count($din) . " families |\n";
$md .= '| NP-IN | PASS: ' . count($npRows) . ' audited scenario parts, ' . array_sum(array_column($npRows, 'pAdmitted')) . " P admissions audited |\n\n";
$md .= "## Gates\n\n| Gate | Result | Detector |\n| --- | --- | --- |\n";
$md .= '| X1 the office | ' . ($g['X1']['pass'] ? 'PASS' : 'FAIL') . ' | ' . ($g['X1']['detector'] ? 'fires: `D-PXALL` refuses the office: ' . $g['X1']['detail'] : 'DOES NOT FIRE: ' . $g['X1']['detail']) . " |\n";
$md .= '| X3 `TMX` | ' . ($g['X3']['pass'] ? 'PASS' : 'FAIL') . " | — |\n| X4 attacker cells | " . ($g['X4']['pass'] ? 'PASS' : 'FAIL') . " | — |\n";
$md .= '| Study detector: reference `TMS` / `TMSd` best response, `pre+out` | ' . ($detTms ? 'fires' : 'DOES NOT FIRE') . " | $rTms/61 and $rTmsd/200 |\n\n";
foreach ($g as $name => $gg) {
    $md .= "### $name\n\n| Check | Result | Values |\n| --- | --- | --- |\n";
    foreach ($gg['items'] as $it) {
        $md .= "| {$it['check']} | " . ($it['ok'] ? 'PASS' : 'FAIL') . " | {$it['value']} |\n";
    }
    $md .= "\n";
}
// X2 and the TMS family
$s0 = [];
foreach (rows("$in/g6_tms_s0.S0.jsonl") as $r) {
    $s0[$r['stream'] . '|' . $r['flow']] = reach($r) . '/' . $r['m']['attackAttempts'];
}
$md .= "## X2 — declared cost (not a gate): `TMS` and `TMSd` against S0 at this baseline\n\n";
$md .= "| Stream | Flow | `S0` | reference `FULL2` | **`PX4`** | `PX8` |\n| --- | --- | --- | --- | --- | --- |\n";
$x2 = [];
foreach (['TMS' => 61, 'TMSd' => 200, 'TMX' => 200] as $id => $nAtt) {
    foreach (['pre+out', 'pre'] as $flow) {
        $v = [];
        foreach (['NOUA2', 'PX4', 'PX8'] as $arm) {
            $v[$arm] = $best($arm, $id, $flow);
        }
        $x2["$id|$flow"] = $v;
        $md .= "| `$id` | $flow | {$s0["$id|$flow"]} | {$v['NOUA2']}/$nAtt | **{$v['PX4']}/$nAtt** | {$v['PX8']}/$nAtt |\n";
    }
}
$md .= "\nBest response over the id policies `fresh`, `const`, `none`. `pre`: the rule is inert (no outcome), so the three arms are equal there.\n\n";
$md .= "### TMS family by id policy (reach / attempts; P = on the P lane; refused = `cap:pfail_src`)\n\n| Stream | Flow | Policy | reference | PX4 | PX8 |\n| --- | --- | --- | --- | --- | --- |\n";
foreach (['TMS', 'TMSd', 'TMX'] as $id) {
    foreach (['pre+out', 'pre'] as $flow) {
        foreach (['fresh', 'const', 'none'] as $pol) {
            $line = "| $id | $flow | $pol |";
            foreach (['NOUA2', 'PX4', 'PX8'] as $arm) {
                $r = $S[$arm]['tms']["$id|none|$pol|$flow"];
                $line .= ' ' . reach($r) . '/' . $r['m']['attackAttempts'] . " (P {$r['reachP']}" . (isset($r['refusedPx']) ? ", refused {$r['refusedPx']}" : '') . ') |';
            }
            $md .= $line . "\n";
        }
    }
}
// TPAIRT
$md .= "\n### `TPAIRT` (the office and the `TMSd` attacker behind one NAT; reported)\n\n| Flow | Policy | Arm | office denied / attempts | attacker reach / attempts |\n| --- | --- | --- | --- | --- |\n";
foreach (['pre+out', 'pre'] as $flow) {
    foreach (['fresh', 'const', 'none'] as $pol) {
        foreach (['NOUA2', 'PX4', 'PX8'] as $arm) {
            $r = $S[$arm]['xs']["TPAIRT|none|$pol|$flow"];
            $md .= "| $flow | $pol | $arm | {$r['m']['legitDenied']} / {$r['m']['legitAttempts']} | " . atk($r) . " / {$r['m']['attackAttempts']} |\n";
        }
    }
}
$md .= "\n### `N12T` and `N12` (the office; legit denied / attempts) and the detector\n\n| Stream | Flow | Policy | reference | PX4 | PX8 | D-PXALL |\n| --- | --- | --- | --- | --- | --- | --- |\n";
foreach (['N12T' => 'xs', 'N12' => 'streams'] as $id => $kind) {
    foreach (['pre+out', 'pre'] as $flow) {
        foreach (['fresh', 'none'] as $pol) {
            $k = "$id|none|$pol|$flow";
            $line = "| $id | $flow | $pol |";
            foreach (['NOUA2', 'PX4', 'PX8', 'D-PXALL'] as $arm) {
                $r = $S[$arm][$kind][$k] ?? null;
                $line .= ' ' . ($r === null ? '—' : "{$r['m']['legitDenied']} / {$r['m']['legitAttempts']}") . ' |';
            }
            $md .= $line . "\n";
        }
    }
}
// differences
$md .= "\n### Rows where `PX4` differs from the reference (decision, lane, reach or cause)\n\n";
$diff = [];
foreach (['streams', 'xs', 'tms'] as $kind) {
    foreach ($S[$px][$kind] ?? [] as $k => $r) {
        $o = $S[$ref][$kind][$k];
        if ($r['dec'] !== $o['dec'] || $r['reach'] !== $o['reach'] || $r['attackDeniedByCause'] !== $o['attackDeniedByCause'] || $r['legitDeniedByCause'] !== $o['legitDeniedByCause']) {
            $diff[] = "| `$k` | {$o['m']['legitDenied']} → {$r['m']['legitDenied']} | " . atk($o) . ' → ' . atk($r) . ' | ' . ($r['refusedPx'] ?? 0) . ' |';
        }
    }
}
$md .= count($diff) . " rows differ.\n\n";
if ($diff !== []) {
    $md .= "| Row | legit denied | attacker reach | `cap:pfail_src` refusals |\n| --- | --- | --- | --- |\n" . implode("\n", $diff) . "\n";
}
$md .= "\n### Fixtures where `PX4` differs from the reference\n\n";
$nd = 0;
foreach ($fx[$px] as $name => $r) {
    $o = $fx[$ref][$name] ?? null;
    if ($o === null) {
        continue;
    }
    $a = $o;
    $b = $r;
    unset($a['arm'], $b['arm']);
    if ($a !== $b) {
        $nd++;
        $md .= "* `$name`: reference `" . mb_substr(jc($a), 0, 300) . '` → PX4 `' . mb_substr(jc($b), 0, 300) . "`\n";
    }
}
$md .= $nd === 0 ? "None.\n" : '';
$md .= "\n### D-PXALL on BRU24\n\n| Fixture | attackAdmitted |\n| --- | --- |\n";
foreach (['BRU24', 'BRU24-HV'] as $f) {
    $md .= "| $f | {$fx['D-PXALL'][$f]['attackAdmitted']} |\n";
}
file_put_contents("$outDir/summary.md", $md);
file_put_contents("$outDir/gates.json", json_encode(['verdict' => $verdict, 'gates' => $g, 'x2' => $x2], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo "G7 verdict: $verdict\n";
foreach ($g as $name => $gg) {
    echo "  $name  " . ($gg['pass'] ? 'PASS' : 'FAIL') . '  (detector ' . ($gg['detector'] ? 'fires' : 'DOES NOT FIRE') . ")\n";
}
exit($failed === [] ? 0 : 3);
