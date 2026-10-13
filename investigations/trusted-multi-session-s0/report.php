<?php

declare(strict_types=1);

// #110 trust family — R23-01 counter-case on S0: report generator + fail-closed evidence gates.
//   php report.php <evidence-dir> <out-dir>
// Reads t01_matrix.jsonl and t02_trace.jsonl, VALIDATES that the required evidence exists and is well-formed, then writes gates.jsonl and
// summary.md. Any missing / empty / malformed / violated evidence writes evidence_problems.txt, marks EVERY gate UNPROVEN and exits 5 (never
// a PASS). The gates are evidence-validity gates; the numbers in §2–§5 are findings for the Owner, not verdicts. No timestamps, no randomness.
require __DIR__ . '/lib/tms.php';

$in = $argv[1] ?? '';
$out = $argv[2] ?? '';
if (! is_dir($in) || ! is_dir($out)) {
    fwrite(STDERR, "usage: report.php <evidence-dir> <out-dir>\n");
    exit(2);
}
const FILES = ['t01_matrix', 't02_trace'];
const REQUIRED = [
    't01_matrix' => ['S0 N12T' => 1, 'S0 SP5' => 1, 'STREAM TMS' => 1, 'STREAM every' => 1],
    't02_trace' => [],
];

$problems = [];
$data = [];
$assertions = [];
$load = static function (string $name) use ($in, &$problems, &$data, &$assertions): void {
    $path = "$in/$name.jsonl";
    if (! is_file($path) || filesize($path) === 0) {
        $problems[] = "missing or empty evidence file: $name.jsonl";

        return;
    }
    $n = 0;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $n++;
        $row = json_decode($line, true);
        if (! is_array($row) || ! isset($row['s'])) {
            $problems[] = "$name.jsonl line $n is not a JSON object with an 's' field";
            continue;
        }
        if (isset($row['assert'])) {
            $assertions[$name][] = $row;
        } else {
            $data[$name][$row['s']][] = $row;
        }
    }
};
foreach (FILES as $f) {
    $load($f);
}
$rowsFor = static fn(string $f, string $id): array => array_values(array_filter($assertions[$f] ?? [], static fn(array $a): bool => str_starts_with($a['assert'], $id . ' ')));

// Provenance: every file carries exactly one S0 provenance row with the pinned baseline and pipeline hash.
foreach (FILES as $f) {
    $p = $data[$f][strtoupper(substr($f, 0, 3)) . '.provenance'] ?? null;
    if ($p === null || count($p) !== 1 || ($p[0]['level'] ?? '') !== 'S0_RUNTIME_OBSERVED' || ($p[0]['patched'] ?? true) !== false
        || ($p[0]['baseSha'] ?? '') !== TMS_BASELINE_SHA || ($p[0]['pipelineSha256'] ?? '') !== TMS_PIPELINE_SHA256) {
        $problems[] = "$f: provenance row missing or not the pinned unpatched S0 baseline (level, baseSha, patched flag, pipelineSha256)";
    }
}
// Required assertions, exact counts, no unknown rows.
foreach (REQUIRED as $f => $ids) {
    $matched = 0;
    foreach ($ids as $id => $expect) {
        $rows = $rowsFor($f, $id);
        $matched += count($rows);
        if (count($rows) !== $expect) {
            $problems[] = "$f: assertion '$id' present " . count($rows) . " time(s), expected $expect";
        }
    }
    if ($matched !== count($assertions[$f] ?? [])) {
        $problems[] = "$f: " . (count($assertions[$f] ?? []) - $matched) . ' unknown/unmatched assertion row(s)';
    }
}
// Matrix completeness + field validity.
$cells = [];
foreach ($data['t01_matrix']['T01.matrix'] ?? [] as $r) {
    $key = ($r['stream'] ?? '?') . '|' . ($r['shape'] ?? '?') . '|' . ($r['flow'] ?? '?');
    if (isset($cells[$key])) {
        $problems[] = "t01_matrix: duplicate cell $key";
    }
    $cells[$key] = $r;
}
$expectedCells = [];
foreach (tmsMatrixCells() as [$id, $shape, $flow]) {
    $expectedCells["$id|$shape|$flow"] = true;
    $r = $cells["$id|$shape|$flow"] ?? null;
    if ($r === null) {
        $problems[] = "t01_matrix: missing cell $id / $shape / $flow";
        continue;
    }
    foreach (tmsCellFields() as $field) {
        $v = $r[$field] ?? null;
        if (! is_int($v) || $v < 0) {
            $problems[] = "t01_matrix: cell $id/$shape/$flow field $field missing or not a non-negative integer";
        }
    }
    if (($r['legitDenied'] ?? 0) > ($r['legitAttempts'] ?? -1) || ($r['attackReachedVerification'] ?? 0) > ($r['attackAttempts'] ?? -1)) {
        $problems[] = "t01_matrix: cell $id/$shape/$flow denied/reached exceeds attempts";
    }
    if (! isset($r['attackDeniedBySource'], $r['legitDeniedBySource'], $r['blockIssuancesByScope'], $r['attackReachedPerAccount'])) {
        $problems[] = "t01_matrix: cell $id/$shape/$flow missing attribution fields";
    }
}
foreach (array_keys($cells) as $key) {
    if (! isset($expectedCells[$key])) {
        $problems[] = "t01_matrix: unexpected cell $key";
    }
}
foreach (array_keys(tmsStreamClasses()) as $id) {
    if (count(array_filter($data['t01_matrix']['T01.stream'] ?? [], static fn(array $r): bool => ($r['stream'] ?? '') === $id)) !== 1) {
        $problems[] = "t01_matrix: stream description row for $id missing or duplicated";
    }
}
// Trace completeness.
$traces = [];
foreach ($data['t02_trace']['T02.trace'] ?? [] as $r) {
    $traces[($r['stream'] ?? '?') . '|' . ($r['flow'] ?? '?')] = $r;
}
foreach (tmsTraceStreams() as $id) {
    foreach (['pre+out', 'pre', 'out'] as $flow) {
        $t = $traces["$id|$flow"] ?? null;
        if ($t === null || ! is_array($t['rows'] ?? null) || $t['rows'] === [] || ! is_string($t['runs'] ?? null) || ! is_int($t['requests'] ?? null)) {
            $problems[] = "t02_trace: trace $id / $flow missing or malformed";
        }
    }
}
$div = [];
foreach ($data['t02_trace']['T02.divergence'] ?? [] as $r) {
    $div[$r['flow'] ?? '?'] = $r;
}
foreach (['pre+out', 'pre', 'out'] as $flow) {
    $d = $div[$flow] ?? null;
    if ($d === null || ! is_int($d['decisionsDiffer'] ?? null) || ! is_int($d['requests'] ?? null) || ! isset($d['N12T_sources'], $d['TMS_sources'])) {
        $problems[] = "t02_trace: divergence row for $flow missing or malformed";
    }
}

const GATES = [
    ['G1', 'Provenance: every scenario ran the repository\'s unpatched EvaluationPipeline at the pinned baseline (S0_RUNTIME_OBSERVED, no candidate arm)', []],
    ['G2', 'Baseline reproduction: the published #108 numbers of the reference streams (N12T 56/61, SP5 200/200) reproduced on the real pipeline', ['t01_matrix' => ['S0 N12T', 'S0 SP5']]],
    ['G3', 'Stream construction: TMS is N12T request for request with every outcome = fail; every attacker stream has 12 targets on the N12T NAT', ['t01_matrix' => ['STREAM TMS', 'STREAM every']]],
    ['G4', 'Evidence completeness: every matrix cell (streams × shapes × flows) with all fields, every trace and every divergence row present', []],
];
$gates = [];
foreach (GATES as [$id, $title, $need]) {
    $status = 'PASS';
    foreach ($need as $f => $ids) {
        foreach ($ids as $i) {
            $rows = $rowsFor($f, $i);
            if ($rows === []) {
                $status = 'UNPROVEN';
            }
            foreach ($rows as $a) {
                if (($a['ok'] ?? false) !== true && $status === 'PASS') {
                    $status = 'FAIL';
                }
            }
        }
    }
    $gates[] = ['gate' => $id, 'title' => $title, 'status' => $problems === [] ? $status : 'UNPROVEN'];
}
file_put_contents("$out/gates.jsonl", implode("\n", array_map(static fn(array $g): string => json_encode($g, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $gates)) . "\n");
if ($problems !== []) {
    file_put_contents("$out/evidence_problems.txt", implode("\n", $problems) . "\n");
    fwrite(STDOUT, "EVIDENCE PROBLEMS (" . count($problems) . "):\n" . implode("\n", $problems) . "\n");
    exit(5);
}
@unlink("$out/evidence_problems.txt");

// ------------------------------------------------------------------------------------------------------------------------------------
// summary.md
// ------------------------------------------------------------------------------------------------------------------------------------
$m = static fn(string $id, string $shape, string $flow): array => $cells["$id|$shape|$flow"];
$md = [];
$h = static function (string $s) use (&$md): void {
    $md[] = $s;
};
$table = static function (array $head, array $rows) use (&$md): void {
    $md[] = '| ' . implode(' | ', $head) . ' |';
    $md[] = '|' . str_repeat('---|', count($head));
    foreach ($rows as $r) {
        $md[] = '| ' . implode(' | ', $r) . ' |';
    }
    $md[] = '';
};
$src = static function (array $bySrc): string {
    if ($bySrc === []) {
        return '—';
    }
    arsort($bySrc);
    $o = [];
    foreach ($bySrc as $k => $v) {
        $o[] = "`$k` ×$v";
    }

    return implode('<br>', $o);
};
$reach = static fn(array $c): string => $c['attackReachedVerification'] . '/' . $c['attackAttempts'];
$legit = static fn(array $c): string => $c['legitAttempts'] === 0 ? '—' : $c['legitDenied'] . '/' . $c['legitAttempts'];
$keys = static fn(array $c): string => json_encode($c['blockIssuancesByScope'], JSON_UNESCAPED_SLASHES);
$streamsMeta = [];
foreach ($data['t01_matrix']['T01.stream'] as $r) {
    $streamsMeta[$r['stream']] = $r;
}

$h('# #110 trust family — the R23-01 counter-case on S0 (generated; do not edit)');
$h('');
$h('Generated by `report.php` from `results/actual/*.jsonl`. Level: **S0_RUNTIME_OBSERVED** only (unpatched `EvaluationPipeline`, sha-256 `' . substr(TMS_PIPELINE_SHA256, 0, 8) . '…' . substr(TMS_PIPELINE_SHA256, -4) . '`, baseline `' . substr(TMS_BASELINE_SHA, 0, 7) . '`). There is no candidate and no arm. Counts are requests, not people. Nothing below selects, approves or rejects a design; the gates in §1 validate the evidence, the numbers in §2–§5 are findings.');
$h('');
$h('## 1. Gates (evidence validity, not policy verdicts)');
$h('');
$table(['Gate', 'What it proves', 'Status'], array_map(static fn(array $g): array => [$g['gate'], $g['title'], '**' . $g['status'] . '**'], $gates));

$h('## 2. Streams');
$h('');
$rows = [];
foreach (tmsStreamClasses() as $id => $class) {
    $s = $streamsMeta[$id];
    $rows[] = [$id, $class, $s['title'], (string) $s['events'], (string) $s['trustedEvents'], (string) $s['spanSeconds']];
}
$table(['Stream', 'Class', 'Content', 'Requests', 'of which trusted', 'Span (s)'], $rows);

$h('## 3. The multi-session attacker on S0 (Login with account): attempts that reached verification, by Host flow');
$h('');
$h('`reach` = attacker attempts that reached the protected operation (allowed at `checkOnly`, or executed before `recordFailure` in record-only). `first 600 s` = of those, inside the first K2/K1 window. `max/account` = most verifications any one target got. Keys = blocks issued by enforcement key (`K5*` = other devices of a distributed-account block).');
$h('');
$rows = [];
foreach (['TMS', 'TMSd', 'TMSs', 'TMR', 'TMU', 'TMX', 'TMXb', 'SP5'] as $id) {
    foreach (['pre+out', 'pre', 'out'] as $flow) {
        $c = $m($id, 'login', $flow);
        $rows[] = [$id, $flow, '**' . $reach($c) . '**', (string) $c['attackReachedFirst600'], (string) $c['attackReachedPerAccountMax'], (string) $c['attackDistinctSubjectsVerified'], $c['attackFirstReachT'] === null ? '—' : (string) $c['attackFirstReachT'], $c['attackFirstDenyT'] === null ? '—' : (string) $c['attackFirstDenyT'], $keys($c), $src($c['attackDeniedBySource'])];
    }
}
$table(['Stream', 'Flow', 'reach', 'first 600 s', 'max/account', 'accounts verified', 'first reach t', 'first deny t', 'blocks issued by key', 'attacker denials by source'], $rows);

$h('## 4. The trusted office vs the trusted multi-session attacker: the same requests, different outcomes');
$h('');
$h('`TMS` is `N12T` request for request (same t, NAT address, browser, session-derived fingerprint index, trust flag); only the outcome (`ok` with 8 % typos → `fail`) and the account names differ. §4a: what each side gets; §4b: request-for-request decision divergence from `t02_trace.php`.');
$h('');
$rows = [];
foreach (['pre+out', 'pre', 'out'] as $flow) {
    $a = $m('N12T', 'login', $flow);
    $b = $m('TMS', 'login', $flow);
    $rows[] = [$flow, $legit($a), $keys($a), $src($a['legitDeniedBySource']), '**' . $reach($b) . '**', $keys($b), $src($b['attackDeniedBySource'])];
}
$table(['Flow', 'N12T legit denied', 'N12T blocks by key', 'N12T denial sources', 'TMS attacker reach', 'TMS blocks by key', 'TMS denial sources'], $rows);
$h('§4b request-for-request:');
$h('');
$rows = [];
foreach (['pre+out', 'pre', 'out'] as $flow) {
    $d = $div[$flow];
    $det = [];
    foreach ($d['detail'] as $x) {
        $det[] = "#{$x['i']} t={$x['t']}: N12T `{$x['N12T']}` / TMS `{$x['TMS']}`";
    }
    $rows[] = [$flow, (string) $d['requests'], (string) $d['N12T_denied'], (string) $d['TMS_denied'], '**' . $d['decisionsDiffer'] . '**', $d['firstDifferingIndex'] === null ? '—' : (string) $d['firstDifferingIndex'], $det === [] ? '—' : implode('<br>', $det)];
}
$table(['Flow', 'Requests', 'N12T denied', 'TMS denied', 'decisions that differ', 'first differing index', 'differing requests (first 12)'], $rows);

$h('## 5. Mixed NAT: the trusted office and the trusted multi-session attacker together');
$h('');
$rows = [];
foreach (['TPAIRT', 'TPAIRM', 'N12T', 'N12M', 'N12'] as $id) {
    foreach (['pre+out', 'pre', 'out'] as $flow) {
        $c = $m($id, 'login', $flow);
        $rows[] = [$id, $flow, $legit($c), $c['attackAttempts'] === 0 ? '—' : '**' . $reach($c) . '**', $keys($c), $src($c['legitDeniedBySource'])];
    }
}
$table(['Stream', 'Flow', 'legit denied', 'attacker reach', 'blocks issued by key', 'legit denials by source'], $rows);

$h('## 6. OTP shape (stricter policy: K4 4/7/10, budget 10, trusted floor L3)');
$h('');
$rows = [];
foreach (['N12T', 'TMS', 'TMSd', 'TMSs', 'TMR', 'TMX', 'TMU'] as $id) {
    foreach (['pre+out', 'pre', 'out'] as $flow) {
        $c = $m($id, 'otp', $flow);
        $rows[] = [$id, $flow, $legit($c), $c['attackAttempts'] === 0 ? '—' : '**' . $reach($c) . '**', (string) $c['attackReachedPerAccountMax'], $keys($c), $src($c['attackDeniedBySource'] !== [] ? $c['attackDeniedBySource'] : $c['legitDeniedBySource'])];
    }
}
$table(['Stream', 'Flow', 'legit denied', 'attacker reach', 'max/account', 'blocks issued by key', 'denials by source'], $rows);

$h('## 7. Per-request traces (first 24 requests of the trace streams, Login, pre+out; the full runs are in `expected/t02_trace.jsonl`)');
$h('');
foreach (tmsTraceStreams() as $id) {
    $t = $traces["$id|pre+out"];
    $h("**$id** (" . $t['requests'] . ' requests; run-length `t:flag` encoding of the whole stream, `-` allowed / `x` denied: `' . $t['runs'] . '`)');
    $h('');
    $rows = [];
    foreach (array_slice($t['rows'], 0, 24) as $r) {
        $rows[] = [(string) $r['i'], (string) $r['t'], (string) $r['acct'], $r['trusted'] ? 'T' : '—', '`' . $r['steps'] . '`', $r['src'] === null ? '—' : '`' . $r['src'] . '`', $r['issued'] === '' ? '—' : $r['issued']];
    }
    $table(['#', 't', 'account', 'trusted', 'steps', 'denial source', 'issued'], $rows);
}

file_put_contents("$out/summary.md", implode("\n", $md) . "\n");
echo "OK: gates written, summary written\n";
