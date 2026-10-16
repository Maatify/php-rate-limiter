<?php

declare(strict_types=1);

// Fail-closed evidence validator, gate evaluator and summary generator for the #97 client-identity study.
//
//   php report.php <c01.jsonl> <c02.jsonl> <c03.jsonl> <out-dir>
//
// Exit 0  evidence valid, every pinned observation reproduced; writes <out-dir>/summary.md and <out-dir>/gates.json.
// Exit 5  INVALID evidence: missing / empty / malformed file, wrong provenance, unknown / duplicate / missing cell or row, a field
//         outside the exact schema, a character outside the decision alphabet, a step not allowed by the flow, an event-kind string
//         that differs from the stream definition, a metric that differs from its re-derivation, a missing counterfactual.
// Exit 6  evidence valid but a pinned observation (O1 … O15, cited by REPORT.md) is NOT reproduced.
//
// The expected matrix and the metric re-derivation come from lib/ci97.php (the same definitions the scenarios use). Gates are the
// pre-registered formulas of VALIDATION_PLAN.md §5, applied mechanically; the verdict rule is §5 "Verdict rule".
require_once __DIR__ . '/lib/ci97.php';

const R_INVALID = 5;
const R_NOT_REPRODUCED = 6;

function fail(string $msg): never
{
    fwrite(STDERR, "INVALID EVIDENCE: $msg\n");
    exit(R_INVALID);
}

/** @return list<array<string, mixed>> */
function load(string $path, string $scenario): array
{
    if (! is_file($path) || filesize($path) === 0) {
        fail("$scenario: missing or empty file $path");
    }
    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $n => $line) {
        try {
            $r = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            fail("$scenario: malformed JSON on line " . ($n + 1));
        }
        if (! is_array($r) || ($r['s'] ?? null) !== $scenario) {
            fail("$scenario: line " . ($n + 1) . ' is not a ' . $scenario . ' row');
        }
        $rows[] = $r;
    }
    $p = $rows[0];
    $pins = [];
    foreach (CI97_RUNTIME_SHA256 as $class => $sha) {
        $pins[substr($class, strrpos($class, '\\') + 1)] = $sha;
    }
    if (($p['row'] ?? null) !== 'provenance' || ($p['baseSha'] ?? null) !== CI97_BASELINE_SHA || ($p['sha256'] ?? null) !== $pins
        || ($p['srcPatched'] ?? null) !== false || ($p['level'] ?? null) !== 'UNPATCHED_PIPELINE_RESOLVER_VARIANTS') {
        fail("$scenario: provenance row missing or not the pinned unpatched baseline");
    }
    foreach (array_slice($rows, 1) as $r) {
        if (($r['row'] ?? null) === 'provenance') {
            fail("$scenario: duplicate provenance row");
        }
    }

    return array_slice($rows, 1);
}

function checkStrings(string $where, string $check, string $record, string $flow, int $n): void
{
    if (strlen($check) !== $n || strlen($record) !== $n) {
        fail("$where: decision strings have length " . strlen($check) . '/' . strlen($record) . ", expected $n");
    }
    if (preg_match('/^[ASH-]*$/', $check) !== 1 || preg_match('/^[ASH-]*$/', $record) !== 1) {
        fail("$where: character outside the decision alphabet");
    }
    for ($i = 0; $i < $n; $i++) {
        $c = $check[$i];
        $r = $record[$i];
        $ok = match ($flow) {
            'pre' => $c !== '-' && $r === '-',
            'out' => $c === '-' && $r !== '-',
            'pre+out' => $c !== '-' && (($c === 'A') === ($r !== '-')),
            default => false,
        };
        if (! $ok) {
            fail("$where: request $i step '$c$r' is not allowed by flow $flow");
        }
    }
}

function expectedKinds(array $events): string
{
    return implode('', array_map(static fn(array $e): string => $e['kind'] === 'attack' ? 'X' : ($e['kind'] === 'owner' ? 'O' : 'L'), $events));
}

function expectedWho(array $events): array
{
    $idx = [];
    $out = [];
    foreach ($events as $e) {
        $idx[(string) $e['who']] ??= count($idx);
        $out[] = $idx[(string) $e['who']];
    }

    return $out;
}

const CELL_FIELDS = ['s', 'row', 'stream', 'cls', 'shape', 'arm', 'strategy', 'flow', 'legitAttempts', 'legitDenied', 'legitUsers',
    'legitUsersDenied', 'attackAttempts', 'reach', 'attackDenied', 'check', 'record', 'kinds', 'who', 'firstDenialBlockedKeys',
    'confMismatch', 'confEscalation', 'ownerFirstDenialScores', 'maxOpsPerCommand', 'distinctFingerprints', 'distinctK3', 'distinctK5'];
const METRIC_FIELDS = ['legitAttempts', 'legitDenied', 'legitUsers', 'legitUsersDenied', 'attackAttempts', 'reach', 'attackDenied'];

/** Validate a matrix row (C01 / C03) against its stream definition; returns the cell key. */
function validateCell(array $r, array $streams, bool $posthoc): string
{
    $fields = CELL_FIELDS;
    if ($posthoc) {
        $fields[] = 'posthoc';
    }
    $needsCtl = ($r['cls'] ?? null) !== 'attack' && ($r['arm'] ?? null) !== 'CIRI-CHOFF';
    if ($needsCtl) {
        array_push($fields, 'ctlCheck', 'ctlRecord');
    }
    $keys = array_keys($r);
    sort($keys);
    sort($fields);
    $where = 'cell ' . json_encode([$r['stream'] ?? '?', $r['shape'] ?? '?', $r['arm'] ?? '?', $r['strategy'] ?? '?', $r['flow'] ?? '?']);
    if ($keys !== $fields) {
        fail("$where: fields differ from the exact schema (" . implode(',', array_diff($keys, $fields)) . ' / ' . implode(',', array_diff($fields, $keys)) . ')');
    }
    if ($posthoc && $r['posthoc'] !== true) {
        fail("$where: post-hoc row not labelled");
    }
    $def = $streams[$r['stream']] ?? fail("$where: unknown stream");
    if ($r['cls'] !== $def['cls'] || ! in_array($r['shape'], $def['shapes'], true)) {
        fail("$where: class or shape does not match the stream definition");
    }
    $events = $def['events'];
    $n = count($events);
    checkStrings($where, $r['check'], $r['record'], $r['flow'], $n);
    if ($r['kinds'] !== expectedKinds($events) || $r['who'] !== expectedWho($events)) {
        fail("$where: event kinds / actors differ from the stream definition");
    }
    if ($needsCtl) {
        checkStrings("$where ctl", $r['ctlCheck'], $r['ctlRecord'], $r['flow'], $n);
    }
    $m = ci97Metrics($r['check'], $r['record'], $r['kinds'], $r['who'], $r['flow']);
    foreach (METRIC_FIELDS as $f) {
        if ($r[$f] !== $m[$f]) {
            fail("$where: $f = {$r[$f]} differs from the re-derived {$m[$f]}");
        }
    }
    foreach (['confMismatch', 'confEscalation', 'maxOpsPerCommand', 'distinctFingerprints', 'distinctK3', 'distinctK5'] as $f) {
        if (! is_int($r[$f]) || $r[$f] < 0) {
            fail("$where: $f is not a non-negative integer");
        }
    }
    if (! is_array($r['firstDenialBlockedKeys']) || array_sum($r['firstDenialBlockedKeys']) !== $r['legitUsersDenied']) {
        fail("$where: first-denial attribution does not cover exactly the denied legitimate actors");
    }
    $owner = $r['cls'] === 'owner' && $r['legitDenied'] > 0;
    if ($owner !== is_array($r['ownerFirstDenialScores'])) {
        fail("$where: owner first-denial scores present iff an owner was denied");
    }

    return implode('|', [$r['stream'], $r['shape'], $r['arm'], $r['strategy'], $r['flow']]);
}

function flowsFor(string $shape): array
{
    return str_starts_with($shape, 'api') ? ['out', 'pre+out'] : ['pre+out', 'pre', 'out'];
}

if ($argc !== 5) {
    fwrite(STDERR, "usage: php report.php <c01.jsonl> <c02.jsonl> <c03.jsonl> <out-dir>\n");
    exit(2);
}
[, $p01, $p02, $p03, $outDir] = $argv;
$streams = ci97Streams();

// ---- C01 / C03: exact matrix ----------------------------------------------------------------------------------------------------------
$expected01 = [];
$expected03 = [];
foreach ($streams as $stream => $def) {
    foreach ($def['shapes'] as $shape) {
        foreach (c01Arms($stream) as $arm) {
            foreach (ci97Strategies($stream, $def['cls'], $arm) as $s) {
                foreach (flowsFor($shape) as $flow) {
                    $expected01[implode('|', [$stream, $shape, $arm, $s, $flow])] = true;
                }
            }
        }
        foreach (ci97Strategies($stream, $def['cls'], 'CIRLI') as $s) {
            foreach (flowsFor($shape) as $flow) {
                $expected03[implode('|', [$stream, $shape, 'CIRLI', $s, $flow])] = true;
            }
        }
    }
}
$C = [];
foreach ([[$p01, 'c01', $expected01, false], [$p03, 'c03', $expected03, true]] as [$path, $sc, $expected, $posthoc]) {
    $seen = [];
    foreach (load($path, $sc) as $r) {
        if (($r['row'] ?? null) !== 'cell') {
            fail("$sc: unknown row type");
        }
        $k = validateCell($r, $streams, $posthoc);
        if (! isset($expected[$k])) {
            fail("$sc: unexpected cell $k");
        }
        if (isset($seen[$k])) {
            fail("$sc: duplicate cell $k");
        }
        $seen[$k] = true;
        $C[$k] = $r;
    }
    if (count($seen) !== count($expected)) {
        fail("$sc: missing cells: " . implode(', ', array_slice(array_keys(array_diff_key($expected, $seen)), 0, 5)));
    }
}

// ---- C02: exact rows -----------------------------------------------------------------------------------------------------------------
$expected02 = [];
foreach (['D1' => '-', 'X2' => 'static'] as $st => $s) {
    foreach (['S0I', 'CIRI'] as $arm) {
        foreach ([false, true] as $rot) {
            $expected02["rot|$st|$arm|$s|" . ($rot ? 'r' : 'n')] = true;
        }
    }
}
foreach (['L1', 'KD', 'D1'] as $st) {
    $expected02["mix|$st|S0I+CIRI|-|"] = true;
}
foreach ([['BRU', 'static'], ['BRU', 'none'], ['KD', '-'], ['UAC', '-']] as [$st, $s]) {
    $expected02["roll|$st|S0I>CIRI|$s|"] = true;
}
foreach (['S0', 'CIR'] as $rt) {
    foreach (['missing', 'empty_array', 'empty_string_id', 'null_id', 'valid_id', 'oversized_1MiB', 'deep_600', 'non_serialisable'] as $in) {
        $expected02["mal|$rt|$in"] = true;
    }
}
foreach (['S0I', 'CIRI'] as $arm) {
    $expected02["mald1|D1|$arm|-|"] = true;
}
foreach ([['D1', 'S0N'], ['D1', 'CIRI'], ['L1', 'S0N'], ['L1', 'CIRI'], ['X7', 'CIRI']] as [$st, $arm]) {
    $expected02["bld|$st|$arm|" . ($st === 'X7' ? 'static' : '-') . '|'] = true;
}
foreach (['S0N', 'S0I', 'CIRI'] as $arm) {
    $expected02["posthoc_v6d1w|D1|$arm|-|"] = true;
}
$L = [];
foreach (load($p02, 'c02') as $r) {
    $type = $r['row'] ?? '';
    if ($type === 'mal') {
        $k = "mal|{$r['runtime']}|{$r['input']}";
        $ok = ($r['outcome'] ?? null) === 'resolved'
            ? array_keys($r) === ['s', 'row', 'runtime', 'input', 'outcome', 'confidence', 'sameAsNoId', 'hashLength'] && in_array($r['confidence'], ['LOW', 'MEDIUM', 'HIGH'], true) && $r['hashLength'] === 64
            : array_keys($r) === ['s', 'row', 'runtime', 'input', 'outcome', 'exception'] && ($r['outcome'] ?? null) === 'exception';
        if (! $ok) {
            fail("c02: $k outside its exact schema");
        }
    } else {
        $suffix = $type === 'rot' ? (($r['rotation'] ?? null) === true ? 'r' : (($r['rotation'] ?? null) === false ? 'n' : '?')) : '';
        $k = "$type|" . ($r['stream'] ?? '?') . '|' . ($r['arm'] ?? '?') . '|' . ($r['strategy'] ?? '?') . "|$suffix";
        $extra = match ($type) {
            'rot' => ['rotation'], 'mix' => ['workers'], 'roll' => ['switchAt'], 'mald1' => ['id'], 'bld' => ['composition'],
            'posthoc_v6d1w' => ['posthoc'], default => fail("c02: unknown row type $type"),
        };
        $want = array_merge(['s', 'row', 'stream', 'cls', 'arm', 'strategy', 'flow'], $extra, METRIC_FIELDS, ['check', 'record', 'kinds', 'who']);
        if (array_keys($r) !== $want) {
            fail("c02: $k outside its exact schema");
        }
        $events = $streams[$r['stream']]['events'];
        checkStrings("c02 $k", $r['check'], $r['record'], $r['flow'], count($events));
        if ($r['kinds'] !== expectedKinds($events) || $r['who'] !== expectedWho($events)) {
            fail("c02: $k event kinds / actors differ from the stream definition");
        }
        $m = ci97Metrics($r['check'], $r['record'], $r['kinds'], $r['who'], $r['flow']);
        foreach (METRIC_FIELDS as $f) {
            if ($r[$f] !== $m[$f]) {
                fail("c02: $k $f differs from its re-derivation");
            }
        }
        if ($type === 'mix' && preg_match('/^[ON]{' . count($events) . '}$/', $r['workers']) !== 1) {
            fail("c02: $k worker string malformed");
        }
    }
    if (! isset($expected02[$k])) {
        fail("c02: unexpected row $k");
    }
    if (isset($L[$k])) {
        fail("c02: duplicate row $k");
    }
    $L[$k] = $r;
}
if (count($L) !== count($expected02)) {
    fail('c02: missing rows: ' . implode(', ', array_slice(array_keys(array_diff_key($expected02, $L)), 0, 5)));
}

// ---- gate evaluation (VALIDATION_PLAN.md §5) -------------------------------------------------------------------------------------------
$cell = static fn(string $st, string $sh, string $arm, string $s, string $fl): array => $C["$st|$sh|$arm|$s|$fl"] ?? fail("gate input $st|$sh|$arm|$s|$fl missing");
$sigmaI = static fn(string $st): array => $st === 'POI' ? [...CI97_SIGMA_I, 'stolen'] : CI97_SIGMA_I;
$ownerReach = static fn(array $r): int => substr_count(implode('', array_map(static fn(int $i): string => $r['kinds'][$i] === 'O' && $r['check'][$i] === 'A' ? '1' : '', range(0, strlen($r['kinds']) - 1))), '1');
$attackStreams = array_keys(array_filter($streams, static fn(array $d): bool => in_array($d['cls'], ['attack', 'both'], true)));
$G = [];
$add = static function (string $gate, bool $pass, string $item) use (&$G): void {
    $G[$gate]['items'][] = ($pass ? 'PASS ' : 'FAIL ') . $item;
    $G[$gate]['pass'] = ($G[$gate]['pass'] ?? true) && $pass;
};
$bestN = [];
foreach ($attackStreams as $st) {
    foreach ($streams[$st]['shapes'] as $sh) {
        foreach (['pre', 'pre+out'] as $fl) {
            if (! in_array($fl, flowsFor($sh), true)) {
                continue;
            }
            $maxN = max(array_map(static fn(string $s): int => $cell($st, $sh, 'S0N', $s, $fl)['reach'], CI97_SIGMA_N));
            $maxI = max(array_map(static fn(string $s): int => $cell($st, $sh, 'CIRI', $s, $fl)['reach'], $sigmaI($st)));
            $bestN["$st|$sh|$fl"] = $maxN;
            $add('G-S1', $maxI <= $maxN, "$st/$sh/$fl best reach CIRI $maxI vs S0N $maxN");
            foreach ($sigmaI($st) as $s) {
                $a = $cell($st, $sh, 'CIRI', $s, $fl)['reach'];
                $b = $cell($st, $sh, 'S0I', $s, $fl)['reach'];
                $add('G-S1b', $a <= $b, "$st/$sh/$fl/$s reach CIRI $a vs S0I $b");
            }
        }
    }
}
foreach ($C as $k => $r) {
    if ($r['arm'] === 'CIRN') {
        $ref = $cell($r['stream'], $r['shape'], 'S0N', $r['strategy'], $r['flow']);
        $add('G-S2', $r['check'] === $ref['check'] && $r['record'] === $ref['record'], "CIRN==S0N $k");
    }
    if ($r['arm'] === 'CIRI' && $r['cls'] === 'attack' && in_array($r['strategy'], CI97_SIGMA_N, true)) {
        $ref = $cell($r['stream'], $r['shape'], 'S0N', $r['strategy'], $r['flow']);
        $add('G-S2', $r['check'] === $ref['check'] && $r['record'] === $ref['record'], "CIRI==S0N $k");
    }
    if ($r['arm'] === 'CIRI') {
        $add('G-S4', $r['confMismatch'] === 0, "confidence/trust equal to shipped resolver $k ({$r['confMismatch']})");
        $ref = $cell($r['stream'], $r['shape'], 'S0I', $r['strategy'], $r['flow']);
        $add('G-S5', $r['maxOpsPerCommand'] <= $ref['maxOpsPerCommand'], "ops/command CIRI {$r['maxOpsPerCommand']} vs S0I {$ref['maxOpsPerCommand']} $k");
    }
}
$s3Base = true;
foreach ($G['G-S1']['items'] ?? [] as $it) {
    if (preg_match('#^FAIL (SP|BRU|BRD)/#', $it) === 1) {
        $s3Base = false;
    }
}
foreach ($G['G-S1b']['items'] ?? [] as $it) {
    if (preg_match('#^FAIL (SP|BRU|BRD)/#', $it) === 1) {
        $s3Base = false;
    }
}
$add('G-S3', $s3Base, 'SP / BRU / BRD covered by G-S1 and G-S1b');
foreach (['KD', 'UAC'] as $st) {
    foreach (['pre+out', 'pre'] as $fl) {
        $a = $cell($st, 'login', 'CIRI', '-', $fl);
        $b = $cell($st, 'login', 'S0I', '-', $fl);
        $add('G-S3', $ownerReach($a) <= $ownerReach($b), "$st/$fl owner-device reach CIRI {$ownerReach($a)} vs S0I {$ownerReach($b)}");
        foreach (['CIRI' => $a, 'S0I' => $b] as $arm => $r) {
            if ($r['legitDenied'] === 0) {
                // checkOnly-only records no failure, so no K5 score and no denial exist in either arm: nothing to attribute.
                $add('G-S3', true, "$st/$fl/$arm no owner denial (no recorded failure), K5 path not applicable");
                continue;
            }
            $k5 = $r['ownerFirstDenialScores']['K5'] ?? -1;
            $add('G-S3', $k5 >= 8, "$st/$fl/$arm first denial on the DEC-021 K5 path (K5 score $k5 >= l1 8)");
        }
    }
}
foreach (['pre+out', 'pre', 'out'] as $fl) {
    $a = $cell('POI', 'login', 'CIRI', 'stolen', $fl);
    $b = $cell('POI', 'login', 'S0N', 'none', $fl);
    $add('G-S4', $a['legitDenied'] <= $b['legitDenied'], "POI/$fl owner denied CIRI(stolen) {$a['legitDenied']} vs S0N(none) {$b['legitDenied']}");
    $add('G-S4', $a['reach'] <= $b['reach'], "POI/$fl attacker reach CIRI(stolen) {$a['reach']} vs S0N(none) {$b['reach']}");
}
foreach (['D1' => '-', 'X2' => 'static'] as $st => $s) {
    $n = $L["rot|$st|CIRI|$s|n"];
    $r = $L["rot|$st|CIRI|$s|r"];
    $add('G-S6', $n['check'] === $r['check'] && $n['record'] === $r['record'], "ROT $st/$s CIRI rotation-transparent");
}
$f1 = static function (string $gate, string $st, string $sh) use ($cell, $add): void {
    foreach (flowsFor($sh) as $fl) {
        $r = $cell($st, $sh, 'CIRI', '-', $fl);
        $k2 = array_sum(array_filter($r['firstDenialBlockedKeys'], static fn(int $v, string $k): bool => str_contains($k, 'K2'), ARRAY_FILTER_USE_BOTH));
        $ok = $r['legitDenied'] * 100 <= $r['legitAttempts'] && $k2 === 0;
        $add($gate, $ok, "$st/$sh/$fl CIRI legit denied {$r['legitDenied']}/{$r['legitAttempts']}, K2-attributed $k2");
    }
};
$f1('G-F1', 'D1', 'login');
$f1('G-F1', 'L3', 'login');
$f1('G-F1', 'D1API', 'api');
$f1('G-F1', 'D1API', 'api_custom');
$f1('G-F2', 'D1A', 'login');
foreach (flowsFor('login') as $fl) {
    $r = $cell('UAC', 'login', 'CIRI', '-', $fl);
    $add('G-F3', $r['distinctFingerprints'] === 1 && $r['distinctK5'] === 1, "UAC/$fl CIRI fingerprints {$r['distinctFingerprints']}, K5 keys {$r['distinctK5']}");
}
foreach (['G-F4' => ['L4', 'RM1', 'RM2'], 'G-F5' => ['L1', 'OFF12', 'V6L1'], 'G-F6' => ['SESS']] as $gate => $list) {
    foreach ($list as $st) {
        foreach (flowsFor('login') as $fl) {
            $a = $cell($st, 'login', 'CIRI', '-', $fl)['legitDenied'];
            $b = $cell($st, 'login', 'S0N', '-', $fl)['legitDenied'];
            $add($gate, $a <= $b, "$st/$fl legit denied CIRI $a vs S0N $b");
        }
    }
}
$hard = ['G-S1', 'G-S1b', 'G-S2', 'G-S3', 'G-S4', 'G-S5', 'G-S6', 'G-F1', 'G-F2', 'G-F3', 'G-F4', 'G-F5', 'G-F6'];
$failed = array_values(array_filter($hard, static fn(string $g): bool => ! ($G[$g]['pass'] ?? false)));
$missing = array_values(array_filter($hard, static fn(string $g): bool => ! isset($G[$g])));
$verdict = $failed !== [] && array_diff($failed, $missing) !== [] ? 'NO-GO' : ($missing !== [] ? 'INCONCLUSIVE' : 'GO FOR OWNER REVIEW');

// Post-hoc diagnosis: the same G-S1 / G-S4b / G-F1 / G-F5 formulas applied to CIRLI (never part of the verdict).
$P = [];
foreach ($attackStreams as $st) {
    foreach ($streams[$st]['shapes'] as $sh) {
        foreach (['pre', 'pre+out'] as $fl) {
            if (! in_array($fl, flowsFor($sh), true)) {
                continue;
            }
            $maxL = max(array_map(static fn(string $s): int => $cell($st, $sh, 'CIRLI', $s, $fl)['reach'], $sigmaI($st)));
            $P['G-S1 (CIRLI)'][] = ($maxL <= $bestN["$st|$sh|$fl"] ? 'PASS ' : 'FAIL ') . "$st/$sh/$fl best reach CIRLI $maxL vs S0N {$bestN["$st|$sh|$fl"]}";
            foreach ($sigmaI($st) as $s) {
                $a = $cell($st, $sh, 'CIRLI', $s, $fl)['reach'];
                $b = $cell($st, $sh, 'S0I', $s, $fl)['reach'];
                $P['G-S1b (CIRLI)'][] = ($a <= $b ? 'PASS ' : 'FAIL ') . "$st/$sh/$fl/$s reach CIRLI $a vs S0I $b";
            }
        }
    }
}
foreach (['pre+out', 'pre'] as $fl) {
    $a = $cell('POI', 'login', 'CIRLI', 'stolen', $fl);
    $b = $cell('POI', 'login', 'S0N', 'none', $fl);
    $P['G-S4b (CIRLI)'][] = ($a['legitDenied'] <= $b['legitDenied'] && $a['reach'] <= $b['reach'] ? 'PASS ' : 'FAIL ') . "POI/$fl owner {$a['legitDenied']} vs {$b['legitDenied']}, attacker {$a['reach']} vs {$b['reach']}";
}
foreach ([['D1', 'login'], ['L3', 'login'], ['D1A', 'login'], ['D1API', 'api'], ['D1API', 'api_custom']] as [$st, $sh]) {
    foreach (flowsFor($sh) as $fl) {
        $r = $cell($st, $sh, 'CIRLI', '-', $fl);
        $P['G-F1/F2 (CIRLI)'][] = ($r['legitDenied'] * 100 <= $r['legitAttempts'] ? 'PASS ' : 'FAIL ') . "$st/$sh/$fl {$r['legitDenied']}/{$r['legitAttempts']}";
    }
}
foreach (['L1', 'OFF12', 'V6L1', 'RM2', 'SESS'] as $st) {
    foreach (flowsFor('login') as $fl) {
        $a = $cell($st, 'login', 'CIRLI', '-', $fl)['legitDenied'];
        $b = $cell($st, 'login', 'S0N', '-', $fl)['legitDenied'];
        $P['G-F4/F5/F6 (CIRLI)'][] = ($a <= $b ? 'PASS ' : 'FAIL ') . "$st/$fl CIRLI $a vs S0N $b";
    }
}

// Post-hoc derived metric (VALIDATION_PLAN.md §7 note 6; not a gate): attacker attempts that are FAILED guesses against an account the
// attacker does not own (X7 pads with successes on its own account; every other attack stream consists of such guesses only).
$victimReach = static function (array $r) use ($streams): int {
    $n = 0;
    foreach ($streams[$r['stream']]['events'] as $i => $e) {
        $n += $e['kind'] === 'attack' && $e['outcome'] === 'fail' && $r['check'][$i] === 'A' ? 1 : 0;
    }

    return $n;
};
$VR = [];
foreach ($attackStreams as $st) {
    foreach ($streams[$st]['shapes'] as $sh) {
        if (str_starts_with($sh, 'api')) {
            continue;
        }
        foreach (['pre', 'pre+out'] as $fl) {
            $best = static fn(string $arm, array $sig): int => max(array_map(static fn(string $s): int => $victimReach($cell($st, $sh, $arm, $s, $fl)), $sig));
            $VR["$st|$fl"] = ['S0N' => $best('S0N', CI97_SIGMA_N), 'CIRI' => $best('CIRI', $sigmaI($st)), 'CIRLI' => $best('CIRLI', $sigmaI($st))];
        }
    }
}

// ---- pinned observations cited by REPORT.md -------------------------------------------------------------------------------------------
$po = static fn(string $st, string $arm, string $s = '-', string $sh = 'login', string $fl = 'pre+out'): array => $cell($st, $sh, $arm, $s, $fl);
$byCorr = static function (array $r): int {
    $n = 0;
    for ($i = 0, $len = strlen($r['kinds']); $i < $len; $i++) {
        if ($r['kinds'][$i] === 'X') {
            continue;
        }
        $d = ($r['check'][$i] !== '-' && $r['check'][$i] !== 'A') || ($r['record'][$i] !== '-' && $r['record'][$i] !== 'A');
        $dc = ($r['ctlCheck'][$i] !== '-' && $r['ctlCheck'][$i] !== 'A') || ($r['ctlRecord'][$i] !== '-' && $r['ctlRecord'][$i] !== 'A');
        $n += $d && ! $dc ? 1 : 0;
    }

    return $n;
};
$O = [
    'O1 baseline reproduces published S0 numbers (D1 596/600, X2 6/121, X7 6/121, L1 with client ids 127/134)' =>
        $po('D1', 'S0N')['legitDenied'] === 596 && $po('X2', 'S0N', 'none')['reach'] === 6 && $po('X7', 'S0N', 'none')['reach'] === 6
        && $po('L1', 'S0I')['legitDenied'] === 127,
    'O2 #97 relief: CIRI D1 / L3 / D1A 0 legitimate denials vs S0N 596 / 104 / 596, all correlation-attributed in S0N' =>
        $po('D1', 'CIRI')['legitDenied'] === 0 && $po('L3', 'CIRI')['legitDenied'] === 0 && $po('D1A', 'CIRI')['legitDenied'] === 0
        && $byCorr($po('D1', 'S0N')) === 596 && $byCorr($po('L3', 'S0N')) === 104 && $byCorr($po('D1A', 'S0N')) === 596,
    'O3 the relief needs no code change: S0I decision strings equal CIRI on D1, L3, D1A, L1, OFF12 (every flow)' =>
        array_reduce(['D1', 'L3', 'D1A', 'L1', 'OFF12'], static fn(bool $c, string $st): bool => $c && array_reduce(flowsFor('login'), static fn(bool $c2, string $fl): bool => $c2 && $cell($st, 'login', 'S0I', '-', $fl)['check'] === $cell($st, 'login', 'CIRI', '-', $fl)['check'] && $cell($st, 'login', 'S0I', '-', $fl)['record'] === $cell($st, 'login', 'CIRI', '-', $fl)['record'], true), true),
    'O4 enforcement-path downgrade: X7 with one static id reaches 121/121 total (incl. own-account padding) under CIRI and S0I; S0N best response 44/121' =>
        $po('X7', 'CIRI', 'static')['reach'] === 121 && $po('X7', 'S0I', 'static')['reach'] === 121 && $bestN['X7|login|pre+out'] === 44,
    'O5 stolen id: POI attacker reach 32/180 (CIRI) vs 4/180 (S0N none); owner denied 8/15 vs 12/15' =>
        $po('POI', 'CIRI', 'stolen')['reach'] === 32 && $po('POI', 'S0N', 'none')['reach'] === 4
        && $po('POI', 'CIRI', 'stolen')['legitDenied'] === 8 && $po('POI', 'S0N', 'none')['legitDenied'] === 12,
    'O6 shared NAT: L1 0 -> 127/134 and OFF12 8 -> 11/13 with ids; with churn neutralised 0 and 8 (churn-attributed)' =>
        $po('L1', 'S0N')['legitDenied'] === 0 && $po('L1', 'CIRI')['legitDenied'] === 127 && $po('L1', 'CIRI-CHOFF')['legitDenied'] === 0
        && $po('OFF12', 'S0N')['legitDenied'] === 8 && $po('OFF12', 'CIRI')['legitDenied'] === 11 && $po('OFF12', 'CIRI-CHOFF')['legitDenied'] === 8,
    'O7 UA update: CIRI keeps 1 fingerprint / 1 K5 key (S0I: 2); known-device reach 5/12 (CIRI) vs 8/12 (S0I)' =>
        $po('UAC', 'CIRI')['distinctK5'] === 1 && $po('UAC', 'S0I')['distinctK5'] === 2
        && $ownerReach($po('UAC', 'CIRI')) === 5 && $ownerReach($po('UAC', 'S0I')) === 8,
    'O8 Q4 null fingerprint (NUA): X2 none 6 -> 121, BRU none 3 -> 11, BRD none 3 -> 11, KD first denial on K4 instead of K5' =>
        $po('X2', 'NUAN', 'none')['reach'] === 121 && $po('BRU', 'NUAN', 'none')['reach'] === 11 && $po('BRD', 'NUAN', 'none')['reach'] === 11
        && ($po('KD', 'NUAN')['firstDenialBlockedKeys'] === ['K4' => 1]),
    'O9 pre-existing session-transition churn: SESS 6/8 denied in S0N already; 0/8 with churn neutralised' =>
        $po('SESS', 'S0N')['legitDenied'] === 6 && $po('SESS', 'CIRI-CHOFF')['legitDenied'] === 0,
    'O10 S0N adaptive attacker: X2 uarot reaches 121/121 (LOW dilution gives no protection against UA rotation); X7 uarot 44/121' =>
        $po('X2', 'S0N', 'uarot')['reach'] === 121 && $po('X7', 'S0N', 'uarot')['reach'] === 44,
    'O11 post-hoc CIRLI: X7 best 44, POI stolen = S0N none (owner 12, attacker 4), D1 0/600, L1 127/134' =>
        max(array_map(static fn(string $s): int => $po('X7', 'CIRLI', $s)['reach'], CI97_SIGMA_I)) === 44
        && $po('POI', 'CIRLI', 'stolen')['legitDenied'] === 12 && $po('POI', 'CIRLI', 'stolen')['reach'] === 4
        && $po('D1', 'CIRLI')['legitDenied'] === 0 && $po('L1', 'CIRLI')['legitDenied'] === 127,
    'O12 the official Builder/Engine composition reproduces the Lab decisions (D1, L1 for S0N and CIRI; X7 static CIRI)' =>
        array_reduce([['D1', 'S0N', '-'], ['D1', 'CIRI', '-'], ['L1', 'S0N', '-'], ['L1', 'CIRI', '-'], ['X7', 'CIRI', 'static']], static fn(bool $c, array $x): bool => $c && $L["bld|{$x[0]}|{$x[1]}|{$x[2]}|"]['check'] === $po($x[0], $x[1], $x[2])['check'] && $L["bld|{$x[0]}|{$x[1]}|{$x[2]}|"]['record'] === $po($x[0], $x[1], $x[2])['record'], true),
    'O13 mixed fleet / rollout: known device KD reaches the verifier 6 (MIX) / 7 (ROLL) times vs 5 (CIRI or S0I alone), with K2 hard blocks from 2 fingerprints of one device' =>
        $ownerReach($L['mix|KD|S0I+CIRI|-|']) === 6 && $ownerReach($L['roll|KD|S0I>CIRI|-|']) === 7 && $ownerReach($po('KD', 'CIRI')) === 5
        && $ownerReach($po('KD', 'S0I')) === 5 && str_contains($L['mix|KD|S0I+CIRI|-|']['check'], 'H') && ! str_contains($po('KD', 'CIRI')['check'], 'H'),
    'O14 fingerprint-secret rotation is decision-transparent for CIRI and S0I (D1, X2 static)' =>
        ($G['G-S6']['pass'] ?? false) && $L['rot|D1|S0I|-|n']['check'] === $L['rot|D1|S0I|-|r']['check'],
    'O15 roaming carrier: RM2 48/60 (S0N, K2) -> 32/60 (CIRI, K3 via MEDIUM dilution)' =>
        $po('RM2', 'S0N')['legitDenied'] === 48 && $po('RM2', 'CIRI')['legitDenied'] === 32 && $po('RM2', 'CIRI')['firstDenialBlockedKeys'] === ['K3' => 1],
    'O16 victim-guess reach (post-hoc metric): X7 best response 40/40 in S0N and CIRI (the 44 -> 121 total-reach delta is own-account padding only); the only best-response increase is BRD checkOnly-only 22 -> 37' =>
        $VR['X7|pre+out'] === ['S0N' => 40, 'CIRI' => 40, 'CIRLI' => 40] && $VR['BRD|pre']['S0N'] === 22 && $VR['BRD|pre']['CIRI'] === 37
        && array_keys(array_filter($VR, static fn(array $v): bool => $v['CIRI'] > $v['S0N'])) === ['BRD|pre'],
];
$notReproduced = array_keys(array_filter($O, static fn(bool $v): bool => ! $v));

// ---- outputs --------------------------------------------------------------------------------------------------------------------------
@mkdir($outDir, 0777, true);
$gatesOut = ['verdict' => $verdict, 'failedHardGates' => $failed, 'gates' => [], 'posthocDiagnosis' => $P, 'observations' => []];
foreach ($hard as $g) {
    $gatesOut['gates'][$g] = ['pass' => $G[$g]['pass'] ?? false, 'failures' => array_values(array_filter($G[$g]['items'] ?? [], static fn(string $i): bool => str_starts_with($i, 'FAIL'))), 'checked' => count($G[$g]['items'] ?? [])];
}
foreach ($O as $text => $v) {
    $gatesOut['observations'][] = ['observation' => $text, 'reproduced' => $v];
}
file_put_contents("$outDir/gates.json", json_encode($gatesOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

$md = ['# Generated summary — #97 client-identity study', '', 'Generated by `report.php` from the archived evidence; do not edit by hand.', ''];
$md[] = "Baseline `main@" . CI97_BASELINE_SHA . '`; runtime: unpatched `src/`, resolver variants only.';
$md[] = '';
$md[] = "## Verdict (pre-registered rule): **$verdict**";
$md[] = '';
$md[] = 'Failed hard gates: ' . ($failed === [] ? 'none' : implode(', ', $failed)) . '.';
$md[] = '';
$md[] = '| Gate | Result | Checks | Failures |';
$md[] = '|---|---|---|---|';
foreach ($gatesOut['gates'] as $g => $x) {
    $md[] = "| $g | " . ($x['pass'] ? 'PASS' : '**FAIL**') . " | {$x['checked']} | " . ($x['failures'] === [] ? '—' : implode('<br>', array_map(static fn(string $f): string => substr($f, 5), array_slice($x['failures'], 0, 8))) . (count($x['failures']) > 8 ? '<br>… (' . count($x['failures']) . ' total)' : '')) . ' |';
}
$md[] = '';
$md[] = '## Legitimate denials, Login `pre+out` (denied / attempts; correlation-attributed by the counterfactual)';
$md[] = '';
$md[] = '| Stream | S0N | S0I | CIRI | CIRN | CIRI-CHOFF | CIRLI (post-hoc) |';
$md[] = '|---|---|---|---|---|---|---|';
foreach (['D1', 'L3', 'D1A', 'L4', 'RM1', 'RM2', 'L1', 'OFF12', 'SESS', 'V6D1', 'V6L1', 'KD', 'UAC'] as $st) {
    $row = "| $st |";
    foreach (['S0N', 'S0I', 'CIRI', 'CIRN', 'CIRI-CHOFF', 'CIRLI'] as $arm) {
        $r = $C["$st|login|$arm|-|pre+out"] ?? null;
        $row .= $r === null ? ' — |' : " {$r['legitDenied']}/{$r['legitAttempts']}" . (isset($r['ctlCheck']) ? ' (corr ' . $byCorr($r) . ')' : '') . ' |';
    }
    $md[] = $row;
}
$md[] = '';
$md[] = '## Attacker reach (attempts allowed by `checkOnly`), `pre+out`, per strategy';
$md[] = '';
$md[] = '| Stream / shape | S0N none | S0N uarot | CIRI none | CIRI uarot | CIRI static | CIRI rotate | CIRI static_uarot | CIRI stolen | S0I static | CIRLI best (post-hoc) |';
$md[] = '|---|---|---|---|---|---|---|---|---|---|---|';
foreach ($attackStreams as $st) {
    foreach ($streams[$st]['shapes'] as $sh) {
        $g = static fn(string $arm, string $s): string => isset($C["$st|$sh|$arm|$s|pre+out"]) ? $C["$st|$sh|$arm|$s|pre+out"]['reach'] . '/' . $C["$st|$sh|$arm|$s|pre+out"]['attackAttempts'] : '—';
        $bestL = max(array_map(static fn(string $s): int => $cell($st, $sh, 'CIRLI', $s, 'pre+out')['reach'], $sigmaI($st)));
        $md[] = "| $st / $sh | {$g('S0N', 'none')} | {$g('S0N', 'uarot')} | {$g('CIRI', 'none')} | {$g('CIRI', 'uarot')} | {$g('CIRI', 'static')} | {$g('CIRI', 'rotate')} | {$g('CIRI', 'static_uarot')} | {$g('CIRI', 'stolen')} | {$g('S0I', 'static')} | $bestL |";
    }
}
$md[] = '';
$md[] = '## Victim-guess reach — post-hoc derived metric, not a gate (best response; failed guesses against accounts the attacker does not own)';
$md[] = '';
$md[] = '| Stream / flow | S0N best | CIRI best | CIRLI best (post-hoc) |';
$md[] = '|---|---|---|---|';
foreach ($VR as $k => $v) {
    $md[] = '| ' . str_replace('|', ' / ', $k) . " | {$v['S0N']} | {$v['CIRI']}" . ($v['CIRI'] > $v['S0N'] ? ' **(+' . ($v['CIRI'] - $v['S0N']) . ')**' : '') . " | {$v['CIRLI']} |";
}
$md[] = '';
$md[] = '## Q4 control NUA (null fingerprint without id), `pre+out`';
$md[] = '';
$md[] = '| Stream | S0N | NUAN | NUAI |';
$md[] = '|---|---|---|---|';
foreach (C01_NUA_STREAMS as $st) {
    $cls = $streams[$st]['cls'];
    $f = static fn(array $r): string => $cls === 'attack' ? "reach {$r['reach']}/{$r['attackAttempts']}" : ($cls === 'owner' ? "owner reach {$ownerReach($r)}/{$r['legitAttempts']}, first denial " . json_encode($r['firstDenialBlockedKeys']) : "denied {$r['legitDenied']}/{$r['legitAttempts']}");
    $s = $cls === 'attack' ? 'none' : '-';
    $md[] = "| $st | {$f($po($st, 'S0N', $s))} | {$f($po($st, 'NUAN', $s))} | {$f($po($st, 'NUAI', $s))} |";
}
$md[] = '';
$md[] = '## Lifecycle and composition (C02)';
$md[] = '';
$md[] = '| Row | Stream | Arm | Strategy | Legit denied | Attacker reach | Owner-device reach |';
$md[] = '|---|---|---|---|---|---|---|';
foreach ($L as $k => $r) {
    if ($r['row'] === 'mal') {
        continue;
    }
    $rot = $r['row'] === 'rot' ? ($r['rotation'] ? ' (rotation)' : ' (no rotation)') : '';
    $md[] = "| {$r['row']}$rot | {$r['stream']} | {$r['arm']} | {$r['strategy']} | {$r['legitDenied']}/{$r['legitAttempts']} | {$r['reach']}/{$r['attackAttempts']} | " . (str_contains($r['kinds'], 'O') ? $ownerReach($r) : '—') . ' |';
}
$md[] = '';
$md[] = '| Identifier input | S0 resolver | CIR resolver |';
$md[] = '|---|---|---|';
foreach (['missing', 'empty_array', 'empty_string_id', 'null_id', 'valid_id', 'oversized_1MiB', 'deep_600', 'non_serialisable'] as $in) {
    $f = static fn(array $r): string => $r['outcome'] === 'exception' ? "exception `{$r['exception']}`" : "{$r['confidence']}" . ($r['sameAsNoId'] ? ', same fingerprint as no id' : ', own fingerprint');
    $md[] = "| `$in` | {$f($L["mal|S0|$in"])} | {$f($L["mal|CIR|$in"])} |";
}
$md[] = '';
$md[] = '## Post-hoc diagnosis (CIRLI; not pre-registered, not part of the verdict)';
$md[] = '';
foreach ($P as $g => $items) {
    $fails = array_values(array_filter($items, static fn(string $i): bool => str_starts_with($i, 'FAIL')));
    $md[] = "* $g: " . ($fails === [] ? 'all ' . count($items) . ' checks PASS' : count($fails) . ' of ' . count($items) . ' FAIL — ' . implode('; ', array_map(static fn(string $f): string => substr($f, 5), array_slice($fails, 0, 6))));
}
$md[] = '';
$md[] = '## Pinned observations';
$md[] = '';
foreach ($O as $text => $v) {
    $md[] = '* ' . ($v ? 'REPRODUCED' : '**NOT REPRODUCED**') . " — $text";
}
file_put_contents("$outDir/summary.md", implode("\n", $md) . "\n");

if ($notReproduced !== []) {
    fwrite(STDERR, "NOT REPRODUCED:\n  " . implode("\n  ", $notReproduced) . "\n");
    exit(R_NOT_REPRODUCED);
}
echo "verdict: $verdict; failed hard gates: " . ($failed === [] ? 'none' : implode(', ', $failed)) . "\n";
exit(0);
