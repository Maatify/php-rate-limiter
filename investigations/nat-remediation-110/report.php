<?php

declare(strict_types=1);

// #110 plan 1 (OQC) — report generator + fail-closed evidence gates.
//   php report.php <evidence-dir> <out-dir>
// Reads the per-arm scenario outputs, VALIDATES that the required evidence exists and is well-formed, then writes gates.jsonl and
// summary.md. Any missing / empty / malformed evidence writes evidence_problems.txt, marks EVERY gate UNPROVEN and exits 5 (never a
// PASS). A candidate assertion recorded with ok:false is a FINDING (gate FAIL), not an evidence problem. No timestamps, no randomness.
require __DIR__ . '/lib/cells.php';

$in = $argv[1] ?? '';
$out = $argv[2] ?? '';
if (! is_dir($in) || ! is_dir($out)) {
    fwrite(STDERR, "usage: report.php <evidence-dir> <out-dir>\n");
    exit(2);
}
const ARMS = OQC_ARMS_LIST;
const CAND = ['OQC', 'OQCW', 'OQCE'];
const FILES = ['o01_matrix', 'o02_targeted', 'o03_rotation_ipv6', 'o04_bounds_rollback'];
const BASE_SHA = '63fbb4abf0a6d7899d063d1fbd5d6fc7a1920ba1';
const PINNED_S0 = '4b259d3397adf492d9767572141c9bdf5efc5744886bce63592ae9f36b95f4fc';
const PINNED_CAND = '189f6e41020f983cc7e58531e3248b49fe84b8d39f08c3e283950c4d442f776f';

/** Required assertions per file: exact identifier (text before the first space, or a multi-word prefix) => rows, for S0 and for a candidate arm. */
const REQUIRED = [
    'o01_matrix' => ['S0' => ['S0 D2' => 1, 'S0 SP1n/SP3/SP6' => 1, 'S0 L1' => 1, 'S0 ABA_A' => 1], 'cand' => []],
    'o02_targeted' => [
        'S0' => ['A1' => 1, 'A1b' => 1, 'A2' => 1, 'C1' => 1, 'C1b' => 1, 'C2' => 1, 'M1' => 1, 'P1' => 1, 'T1' => 1, 'G1' => 1],
        'cand' => ['A1' => 1, 'A1b' => 1, 'A2' => 1, 'A2b' => 1, 'C1' => 1, 'C1b' => 1, 'C2' => 1, 'M1' => 1, 'P1' => 1, 'T1' => 1, 'G1' => 1],
    ],
    'o03_rotation_ipv6' => ['S0' => ['R2' => 6, 'H1' => 1, 'H2' => 1, 'H2b' => 1], 'cand' => ['R1' => 12, 'R2' => 6, 'H1' => 1, 'H2' => 1, 'H2b' => 1]],
    'o04_bounds_rollback' => ['S0' => ['B1' => 1, 'B2' => 1, 'B3' => 2, 'R1' => 1, 'R2' => 2], 'cand' => ['B1' => 1, 'B2' => 1, 'B3' => 2, 'R1' => 1, 'R2' => 2]],
    'o05_redis' => ['S0' => ['P1' => 1, 'P1b' => 1, 'P2' => 1, 'P2b' => 1, 'P3' => 1, 'P3b' => 1, 'P4' => 1, 'P4b' => 1], 'cand' => ['P1' => 1, 'P1b' => 1, 'P2' => 1, 'P2b' => 1, 'P3' => 1, 'P3b' => 1, 'P4' => 1, 'P4b' => 1]],
    'o06_concurrency' => ['S0' => ['C1' => 2, 'C2' => 2, 'C2s' => 1, 'C3' => 2, 'C4' => 1, 'C4b' => 1, 'E1' => 1, 'T1' => 1], 'cand' => ['C1' => 2, 'C2' => 2, 'C2s' => 1, 'C3' => 2, 'C4' => 1, 'C4b' => 1, 'E1' => 1, 'T1' => 1]],
];

$problems = [];
$data = [];       // file => arm => s => rows
$assertions = []; // file => arm => rows
$load = static function (string $name, string $arm) use ($in, &$problems, &$data, &$assertions): void {
    $path = "$in/$name.$arm.jsonl";
    if (! is_file($path) || filesize($path) === 0) {
        $problems[] = "missing or empty evidence file: $name.$arm.jsonl";

        return;
    }
    $n = 0;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $n++;
        $row = json_decode($line, true);
        if (! is_array($row) || ! isset($row['s'])) {
            $problems[] = "$name.$arm.jsonl line $n is not a JSON object with an 's' field";
            continue;
        }
        if (isset($row['assert'])) {
            $assertions[$name][$arm][] = $row;
        } else {
            $data[$name][$arm][$row['s']][] = $row;
        }
    }
};
$redisStatus = is_file("$in/redis_status.txt") ? trim((string) file_get_contents("$in/redis_status.txt")) : '';
if (! in_array($redisStatus, ['RAN', 'SKIPPED'], true)) {
    $problems[] = 'redis_status.txt missing or not RAN/SKIPPED';
}
$files = FILES;
if ($redisStatus === 'RAN') {
    $files[] = 'o05_redis';
    $files[] = 'o06_concurrency';
}
foreach ($files as $f) {
    foreach (ARMS as $arm) {
        $load($f, $arm);
    }
}
$rowsFor = static fn(string $f, string $arm, string $id): array => array_values(array_filter($assertions[$f][$arm] ?? [], static fn(array $a): bool => str_starts_with($a['assert'], $id . ' ')));

// Provenance: every file of every arm carries exactly one provenance row of the right level and pinned hash.
foreach ($files as $f) {
    foreach (ARMS as $arm) {
        $p = $data[$f][$arm][strtoupper(substr($f, 0, 3)) . '.provenance'] ?? null;
        $okRow = $p !== null && count($p) === 1 && ($p[0]['arm'] ?? '') === $arm && ($p[0]['baseSha'] ?? '') === BASE_SHA
            && ($arm === 'S0'
                ? (($p[0]['level'] ?? '') === 'S0_RUNTIME_OBSERVED' && ($p[0]['patched'] ?? true) === false && ($p[0]['pipelineSha256'] ?? '') === PINNED_S0)
                : (($p[0]['level'] ?? '') === 'CANDIDATE_EXPERIMENTAL_RUNTIME' && ($p[0]['patched'] ?? false) === true && ($p[0]['pipelineSha256'] ?? '') === PINNED_CAND));
        if (! $okRow) {
            $problems[] = "$f.$arm: provenance row missing or not the pinned " . ($arm === 'S0' ? 'unpatched S0 baseline' : 'patched candidate') . ' (arm, level, baseSha, patched flag, pipelineSha256)';
        }
    }
}
// Required assertions: each id present exactly the expected number of times; no unknown rows. (ok:false is a finding, handled by the gates.)
foreach (REQUIRED as $f => $sets) {
    if (in_array($f, ['o05_redis', 'o06_concurrency'], true) && $redisStatus !== 'RAN') {
        continue;
    }
    foreach (ARMS as $arm) {
        $ids = $sets[$arm === 'S0' ? 'S0' : 'cand'];
        $matched = 0;
        foreach ($ids as $id => $expect) {
            $rows = $rowsFor($f, $arm, $id);
            $matched += count($rows);
            if (count($rows) !== $expect) {
                $problems[] = "$f.$arm: assertion '$id' present " . count($rows) . " time(s), expected $expect";
            }
        }
        if ($matched !== count($assertions[$f][$arm] ?? [])) {
            $problems[] = "$f.$arm: " . (count($assertions[$f][$arm] ?? []) - $matched) . ' unknown/unmatched assertion row(s)';
        }
    }
}
// Matrix completeness + integer fields, per arm.
$cells = [];
$expectedCells = [];
foreach (oqcMatrixCells() as [$id, $shape, $flow]) {
    $expectedCells["$id|$shape|$flow"] = true;
}
foreach (ARMS as $arm) {
    foreach ($data['o01_matrix'][$arm]['O01.matrix'] ?? [] as $r) {
        $key = ($r['stream'] ?? '?') . '|' . ($r['shape'] ?? '?') . '|' . ($r['flow'] ?? '?');
        if (! isset($expectedCells[$key])) {
            $problems[] = "o01_matrix.$arm: unknown matrix cell $key";
        } elseif (isset($cells[$arm][$key])) {
            $problems[] = "o01_matrix.$arm: duplicate matrix cell $key";
        }
        if (($r['arm'] ?? '') !== $arm) {
            $problems[] = "o01_matrix.$arm: cell $key carries arm '" . ($r['arm'] ?? '') . "'";
        }
        $cells[$arm][$key] ??= $r;
    }
    foreach (oqcMatrixCells() as [$id, $shape, $flow]) {
        $r = $cells[$arm]["$id|$shape|$flow"] ?? null;
        if ($r === null) {
            $problems[] = "o01_matrix.$arm: missing cell $id / $shape / $flow";
            continue;
        }
        foreach (oqcCellFields() as $field) {
            $v = $r[$field] ?? null;
            if (! is_int($v) || $v < 0) {
                $problems[] = "o01_matrix.$arm: cell $id/$shape/$flow field $field missing or not a non-negative integer";
            }
        }
        if (is_int($r['legitDenied'] ?? null) && $r['legitDenied'] > ($r['legitAttempts'] ?? -1)) {
            $problems[] = "o01_matrix.$arm: cell $id/$shape/$flow denied > attempts";
        }
        if (is_int($r['attackReachedVerification'] ?? null) && $r['attackReachedVerification'] > ($r['attackAttempts'] ?? -1)) {
            $problems[] = "o01_matrix.$arm: cell $id/$shape/$flow reached > attempts";
        }
    }
}
foreach (['o02_targeted' => ['O02.A1.innocent-aba', 'O02.A2.attacker-aba', 'O02.C1.one-observation-per-attempt', 'O02.M1.marker', 'O02.P1.success-padding', 'O02.T1.typo-cost', 'O02.G1.watch-gap'], 'o03_rotation_ipv6' => ['O03.R.rotation', 'O03.H.ipv6'], 'o04_bounds_rollback' => ['O04.B1.one-scope', 'O04.B2.many-scopes', 'O04.B3.arm-keys', 'O04.R1.rollback', 'O04.R2.mixed-version']] as $f => $keys) {
    foreach (ARMS as $arm) {
        foreach ($keys as $k) {
            if (($data[$f][$arm][$k] ?? []) === []) {
                $problems[] = "$f.$arm: missing data rows $k";
            }
        }
    }
}

// ------------------------------------------------------------------------------------------------------------------------------------
// Gates. Evidence problems => every gate UNPROVEN (exit 5). Otherwise PASS / FAIL (finding) / UNPROVEN (Redis skipped).
// ------------------------------------------------------------------------------------------------------------------------------------
$cell = static fn(string $arm, string $id, string $shape, string $flow): ?array => $cells[$arm]["$id|$shape|$flow"] ?? null;
$assertGate = static function (array $need, string $arm) use ($rowsFor, $redisStatus): string {
    $status = 'PASS';
    foreach ($need as $f => $ids) {
        if (in_array($f, ['o05_redis', 'o06_concurrency'], true) && $redisStatus !== 'RAN') {
            return 'UNPROVEN';
        }
        foreach ($ids as $i) {
            $rows = $rowsFor($f, $arm, $i);
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

    return $status;
};
$classes = oqcStreamClasses();
$gates = [];
$findings = []; // arm => list of strings
$g = static function (string $id, string $arm, string $title, string $status, array $detail = []) use (&$gates): void {
    $gates[] = ['gate' => $id, 'arm' => $arm, 'title' => $title, 'status' => $status] + ($detail === [] ? [] : ['detail' => $detail]);
};
if ($problems === []) {
    // G1 — baseline reproduction (S0 only).
    $g('G1', 'S0', 'Baseline reproduction: published S0 numbers (D2 117/115, SP1n/SP3/SP6 4/7/20, L1 127, X1 2, X2 6, Y9b 3) and the #100 Lead control ABA_A 3/120 reproduced on the real pipeline', $assertGate(['o01_matrix' => ['S0 D2', 'S0 SP1n/SP3/SP6', 'S0 L1', 'S0 ABA_A']], 'S0'));
    foreach (CAND as $arm) {
        // G2 — checkOnly-only Hosts are S0 exactly (every `pre` cell identical).
        $diff = [];
        foreach (oqcMatrixCells() as [$id, $shape, $flow]) {
            if ($flow !== 'pre') {
                continue;
            }
            $a = $cell($arm, $id, $shape, $flow);
            $s = $cell('S0', $id, $shape, $flow);
            foreach (['legitDenied', 'attackReachedVerification', 'attackDenied', 'correlationIssuances'] as $k) {
                if ($a[$k] !== $s[$k]) {
                    $diff[] = "$id/$shape $k " . $s[$k] . '->' . $a[$k];
                }
            }
        }
        $g('G2', $arm, 'checkOnly-only Hosts keep S0 exactly (every flow=pre cell identical to S0 in denials, reach, denied and correlation issuances)', $diff === [] ? 'PASS' : 'FAIL', $diff);
        if ($diff !== []) {
            $findings[$arm][] = 'G2 flow=pre differs from S0: ' . implode('; ', $diff);
        }
        // G3 — API_OVERUSE / custom API cells identical to S0 (not an OQC-eligible capability).
        $diff = [];
        foreach (oqcMatrixCells() as [$id, $shape, $flow]) {
            if (! str_starts_with($shape, 'api')) {
                continue;
            }
            $a = $cell($arm, $id, $shape, $flow);
            $s = $cell('S0', $id, $shape, $flow);
            foreach (['legitDenied', 'attackReachedVerification', 'attackDenied', 'correlationIssuances'] as $k) {
                if ($a[$k] !== $s[$k]) {
                    $diff[] = "$id/$shape/$flow $k " . $s[$k] . '->' . $a[$k];
                }
            }
        }
        $g('G3', $arm, 'API_OVERUSE and custom API policies are untouched (every api/api_custom cell identical to S0)', $diff === [] ? 'PASS' : 'FAIL', $diff);
        // G4 — protection: +1 verification per correlation trigger at most (attack/evasion classes, Login pre+out and the shape cells).
        $viol = [];
        $rows = [];
        foreach (oqcMatrixCells() as [$id, $shape, $flow]) {
            if ($flow === 'pre' || str_starts_with($shape, 'api') || ! in_array($classes[$id], ['attack', 'evasion', 'both'], true)) {
                continue;
            }
            if ($flow === 'out') {
                continue; // record-only: every attempt has already been verified by definition; measured as attackDenied in the summary
            }
            $a = $cell($arm, $id, $shape, $flow);
            $s = $cell('S0', $id, $shape, $flow);
            // Bound: every correlation block the arm issued may admit one verification (the evidence arrives after the verification),
            // the untouched 5-distinct-subject K1 budget may admit up to 5 where S0 had fewer (S0 blocked earlier on shared evidence),
            // and one request per 600 s window boundary may run the S0 rule.
            $delta = $a['attackReachedVerification'] - $s['attackReachedVerification'];
            $bound = $a['correlationIssuances'] + max(0, 5 - $s['attackReachedVerification']) + 1;
            if ($delta > $bound) {
                $viol[] = "$id/$shape reach " . $s['attackReachedVerification'] . '->' . $a['attackReachedVerification'] . ' (+' . $delta . ') > bound ' . $bound . ' (triggers ' . $a['correlationIssuances'] . ')';
            }
        }
        $g('G4', $arm, 'Protection bound: on every attack/evasion/mixed cell (pre+out) the extra attacker verifications vs S0 are at most (correlation blocks issued by the arm) + max(0, 5 - S0 reach) + 1', $viol === [] ? 'PASS' : 'FAIL', $viol);
        if ($viol !== []) {
            $findings[$arm][] = 'G4 bound violated: ' . implode('; ', $viol);
        }
        // G5 — fairness: no benign / mixed / poisoning cell is denied more than on S0 (pre+out and record-only).
        $viol = [];
        foreach (oqcMatrixCells() as [$id, $shape, $flow]) {
            if ($flow === 'pre' || str_starts_with($shape, 'api') || ! in_array($classes[$id], ['benign', 'both', 'poisoning'], true)) {
                continue;
            }
            $a = $cell($arm, $id, $shape, $flow);
            $s = $cell('S0', $id, $shape, $flow);
            if ($a['legitDenied'] > $s['legitDenied']) {
                $viol[] = "$id/$shape/$flow legit denied " . $s['legitDenied'] . '->' . $a['legitDenied'];
            }
        }
        $g('G5', $arm, 'Fairness non-regression: no benign, mixed or poisoning cell (pre+out, record-only) has more legitimate denials than S0', $viol === [] ? 'PASS' : 'FAIL', $viol);
        if ($viol !== []) {
            $findings[$arm][] = 'G5 fairness regression: ' . implode('; ', $viol);
        }
    }
    foreach (ARMS as $arm) {
        $g('G6', $arm, 'Targeted probes: A/B/A pair, one K1 observation per attempt, liveness marker lifecycle, success never erases failures, typo cost, WATCH gap', $assertGate(['o02_targeted' => $arm === 'S0' ? ['A1', 'A1b', 'A2', 'C1', 'C1b', 'C2', 'M1', 'P1', 'T1', 'G1'] : ['A1', 'A1b', 'A2', 'A2b', 'C1', 'C1b', 'C2', 'M1', 'P1', 'T1', 'G1']], $arm));
        $g('G7', $arm, 'Current/Previous rotation (3 modes) and IPv6 macro scopes', $assertGate(['o03_rotation_ipv6' => $arm === 'S0' ? ['R2', 'H1', 'H2', 'H2b'] : ['R1', 'R2', 'H1', 'H2', 'H2b']], $arm));
        $g('G8', $arm, 'Storage bounds (2 marker keys per scope, TTL-bounded, 0 per request), rollback and mixed-version safety', $assertGate(['o04_bounds_rollback' => ['B1', 'B2', 'B3', 'R1', 'R2']], $arm));
        $g('G9', $arm, 'Real Redis: decision parity, record-only K1, TTL hygiene, liveness markers on the adapter', $assertGate(['o05_redis' => ['P1', 'P1b', 'P2', 'P2b', 'P3', 'P3b', 'P4', 'P4b']], $arm));
        $g('G10', $arm, 'Concurrency on real Redis (F111-02): simultaneous checkOnly bursts on live K1 / K2 scopes, the silent-scope control, the block after the burst', $assertGate(['o06_concurrency' => ['C1', 'C2', 'C2s', 'C3', 'C4', 'C4b', 'E1', 'T1']], $arm));
        foreach (['o02_targeted', 'o03_rotation_ipv6', 'o04_bounds_rollback', 'o05_redis', 'o06_concurrency'] as $f) {
            foreach ($assertions[$f][$arm] ?? [] as $a) {
                if (($a['ok'] ?? false) !== true) {
                    $findings[$arm][] = "$f: " . $a['assert'];
                }
            }
        }
    }
} else {
    foreach (['G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8', 'G9', 'G10'] as $id) {
        $g($id, '*', 'UNPROVEN (evidence problems)', 'UNPROVEN');
    }
}
$lines = array_map(static fn(array $x): string => json_encode($x, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $gates);
file_put_contents("$out/gates.jsonl", implode("\n", $lines) . "\n");
if ($problems !== []) {
    file_put_contents("$out/evidence_problems.txt", implode("\n", $problems) . "\n");
    fwrite(STDOUT, 'EVIDENCE PROBLEMS: ' . count($problems) . "\n" . implode("\n", array_slice($problems, 0, 20)) . "\n");
    exit(5);
}

// ------------------------------------------------------------------------------------------------------------------------------------
// summary.md
// ------------------------------------------------------------------------------------------------------------------------------------
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
$ld = static fn(string $arm, string $id, string $shape, string $flow): string => $cell($arm, $id, $shape, $flow)['legitDenied'] . '/' . $cell($arm, $id, $shape, $flow)['legitAttempts'];
$ar = static fn(string $arm, string $id, string $shape, string $flow): string => $cell($arm, $id, $shape, $flow)['attackReachedVerification'] . '/' . $cell($arm, $id, $shape, $flow)['attackAttempts'];
$ad = static fn(string $arm, string $id, string $shape, string $flow): string => $cell($arm, $id, $shape, $flow)['attackDenied'] . '/' . $cell($arm, $id, $shape, $flow)['attackAttempts'];
$trig = static fn(string $arm, string $id, string $shape, string $flow): string => (string) $cell($arm, $id, $shape, $flow)['correlationIssuances'];
$src = static function (array $bySrc): string {
    if ($bySrc === []) {
        return '—';
    }
    arsort($bySrc);
    $o = [];
    foreach (array_slice($bySrc, 0, 3, true) as $k => $v) {
        $o[] = "`$k` ×$v";
    }

    return implode('<br>', $o);
};
$titles = [];
foreach ($data['o01_matrix']['S0']['O01.stream'] ?? [] as $r) {
    $titles[$r['stream']] = $r['title'];
}

$h('# #110 plan 1 — OQC candidate vs S0 (generated; do not edit)');
$h('');
$h('Generated by `report.php` from `results/actual/*.jsonl`. **S0** = the unpatched `EvaluationPipeline` (sha-256 `' . substr(PINNED_S0, 0, 8) . '…`, baseline `' . substr(BASE_SHA, 0, 7) . '`), level S0_RUNTIME_OBSERVED. **OQC / OQCW / OQCE** = the isolated patched tree (sha-256 `' . substr(PINNED_CAND, 0, 8) . '…`, `patches/oqc-candidates.patch`), level CANDIDATE_EXPERIMENTAL_RUNTIME. Counts are requests (not people). Flows: `pre+out` = checkOnly then the outcome if allowed; `pre` = checkOnly only; `out` = record-only. Nothing below selects, approves or recommends a policy.');
$h('');
$h('## 1. Gates');
$h('');
$table(['Gate', 'Arm', 'What it checks', 'Status'], array_map(static fn(array $x): array => [$x['gate'], $x['arm'], $x['title'], '**' . $x['status'] . '**'], $gates));
$h('Findings (candidate assertions or bounds that did not hold; evidence is still valid):');
$h('');
foreach (CAND as $arm) {
    foreach ($findings[$arm] ?? [] as $f) {
        $h("* **$arm** — $f");
    }
}
if (array_filter($findings) === []) {
    $h('* none');
}
$h('');

$h('## 2. Fairness — legitimate requests denied (Login with account)');
$h('');
$rows = [];
foreach (['D2', 'L1', 'L1f', 'L2', 'L3', 'L4', 'N3', 'N6', 'N12', 'N12T', 'N12M', 'Nu4', 'Nu6', 'Nu12', 'Nu12T', 'Nu12M', 'ABA_B', 'ABA_B1', 'GAP', 'OTPB', 'D1', 'D6'] as $id) {
    foreach (['pre+out', 'out', 'pre'] as $flow) {
        $rows[] = [$id, $flow, $ld('S0', $id, 'login', $flow), $ld('OQC', $id, 'login', $flow), $ld('OQCW', $id, 'login', $flow), $ld('OQCE', $id, 'login', $flow), $src($cell('OQC', $id, 'login', $flow)['legitDeniedBySource'])];
    }
}
$table(['Stream', 'Flow', 'S0', 'OQC', 'OQCW', 'OQCE', 'Remaining sources on OQC'], $rows);
$h('Mixed and poisoning streams (legitimate denials; the attacker is the poisoning party):');
$h('');
$rows = [];
foreach (['MIX', 'MIXT', 'MIXU', 'MIXN', 'X2L', 'X8', 'X4', 'X6', 'Y4b', 'Y4c', 'Y4d', 'Y4e', 'Y4f', 'Y6b', 'Y6c'] as $id) {
    foreach (['pre+out', 'out'] as $flow) {
        $rows[] = [$id, $flow, $ld('S0', $id, 'login', $flow), $ld('OQC', $id, 'login', $flow), $ld('OQCW', $id, 'login', $flow), $ld('OQCE', $id, 'login', $flow)];
    }
}
$table(['Stream', 'Flow', 'S0', 'OQC', 'OQCW', 'OQCE'], $rows);

$h('## 3. Protection — attacker attempts that reached verification (Login, pre+out) and correlation blocks issued (triggers)');
$h('');
$rows = [];
foreach (['ABA_A', 'SP1f', 'SP1n', 'SP3', 'SP6', 'SP2', 'SP4', 'SP4u', 'SP4b', 'SP5', 'SP5c', 'K1R4', 'X1', 'X2', 'X3', 'X5', 'X7', 'Y1', 'Y2a', 'Y2b', 'Y2c', 'Y2c2', 'Y7', 'Y9a', 'Y9b', 'D5', 'D7', 'MIX', 'MIXT', 'MIXU', 'MIXN'] as $id) {
    $rows[] = [$id, $ar('S0', $id, 'login', 'pre+out') . ' (' . $trig('S0', $id, 'login', 'pre+out') . ')', $ar('OQC', $id, 'login', 'pre+out') . ' (' . $trig('OQC', $id, 'login', 'pre+out') . ')', $ar('OQCW', $id, 'login', 'pre+out') . ' (' . $trig('OQCW', $id, 'login', 'pre+out') . ')', $ar('OQCE', $id, 'login', 'pre+out') . ' (' . $trig('OQCE', $id, 'login', 'pre+out') . ')', $ar('S0', $id, 'login', 'pre') . ' / ' . $ar('OQC', $id, 'login', 'pre')];
}
$table(['Stream', 'S0 reach (triggers)', 'OQC', 'OQCW', 'OQCE', 'checkOnly-only S0 / OQC'], $rows);
$h('Record-only Hosts (every attempt is verified before the library sees it; the measure is attacker requests DENIED):');
$h('');
$rows = [];
foreach (['ABA_A', 'SP1n', 'SP3', 'SP6', 'SP2', 'SP4u', 'SP5c', 'K1R4', 'Y1', 'Y7', 'D7'] as $id) {
    $rows[] = [$id, $ad('S0', $id, 'login', 'out'), $ad('OQC', $id, 'login', 'out'), $ad('OQCW', $id, 'login', 'out'), $ad('OQCE', $id, 'login', 'out')];
}
$table(['Stream', 'S0 denied', 'OQC', 'OQCW', 'OQCE'], $rows);

$h('## 4. Other Host shapes (OTP, Login without account id, API_OVERUSE, custom API)');
$h('');
$rows = [];
foreach (oqcMatrixCells() as [$id, $shape, $flow]) {
    if ($shape === 'login') {
        continue;
    }
    $rows[] = [$id, $shape, $flow, $ld('S0', $id, $shape, $flow) . ' · ' . $ar('S0', $id, $shape, $flow), $ld('OQC', $id, $shape, $flow) . ' · ' . $ar('OQC', $id, $shape, $flow), $ld('OQCW', $id, $shape, $flow) . ' · ' . $ar('OQCW', $id, $shape, $flow), $ld('OQCE', $id, $shape, $flow) . ' · ' . $ar('OQCE', $id, $shape, $flow)];
}
$table(['Stream', 'Shape', 'Flow', 'S0 legit · attacker reach', 'OQC', 'OQCW', 'OQCE'], $rows);

$h('## 5. Targeted probes');
$h('');
foreach (ARMS as $arm) {
    $d = $data['o02_targeted'][$arm];
    $a2 = $d['O02.A2.attacker-aba'][0];
    $h("* **$arm** — innocent A/B/A denied " . $d['O02.A1.innocent-aba'][0]['legitDenied'] . '/120 (first steps `' . implode(' ', $d['O02.A1.innocent-aba'][0]['firstSteps']) . '`); attacker A/B/A reached ' . $a2['reached'] . '/120 with ' . $a2['correlationIssuances'] . ' correlation blocks (' . json_encode($a2['issuancesByScope']) . '), verifications at t=' . implode(',', array_slice($a2['reachedAt'], 0, 8)) . '…; K1 observations per attempt `' . implode('', $d['O02.C1.one-observation-per-attempt'][0]['sprayOpsPerRequest']) . '` (pre+out) / `' . implode('', $d['O02.C2.record-only-observation'][0]['sprayOpsPerRequest']) . '` (record-only); markers ' . json_encode(array_diff_key($d['O02.M1.marker'][0], ['s' => 0, 'arm' => 0])) . '; success padding `' . implode(' ', $d['O02.P1.success-padding'][0]['steps']) . '`; typo cost office collateral at ' . json_encode($d['O02.T1.typo-cost'][0]['officeCollateralAt'] ?? []) . ', typist own ' . json_encode($d['O02.T1.typo-cost'][0]['typistOwnDeniedAt'] ?? []) . '; WATCH gap late steps `' . implode(' ', $d['O02.G1.watch-gap'][0]['lateSteps']) . '`.');
}
$h('');

$h('## 6. Rotation and IPv6');
$h('');
$rows = [];
foreach (ARMS as $arm) {
    foreach ($data['o03_rotation_ipv6'][$arm]['O03.R.rotation'] as $r) {
        $rows[] = [$arm, $r['stream'], $r['mode'] . ' @' . $r['rotationAt'], $r['legitDenied'] . '/' . $r['legitAttempts'] . ' (ref ' . $r['reference']['legitDenied'] . ')', $r['attackReachedVerification'] . '/' . $r['attackAttempts'] . ' (ref ' . $r['reference']['attackReachedVerification'] . ')', (string) $r['correlationIssuances']];
    }
}
$table(['Arm', 'Stream', 'Rotation', 'Legit denied (no-rotation ref)', 'Attacker reach (ref)', 'Triggers'], $rows);
$rows = [];
foreach (ARMS as $arm) {
    foreach ($data['o03_rotation_ipv6'][$arm]['O03.H.ipv6'] as $r) {
        $f = $r['flows'];
        $rows[] = [$arm, $r['case'], $f['pre+out']['legitDenied'] . '/' . $f['pre+out']['legitAttempts'], $f['pre+out']['attackReachedVerification'] . '/' . $f['pre+out']['attackAttempts'], $f['pre']['legitDenied'] . ' · ' . $f['pre']['attackReachedVerification'], $f['out']['legitDenied'] . ' · ' . $f['out']['attackDenied']];
    }
}
$table(['Arm', 'IPv6 case', 'pre+out legit denied', 'pre+out attacker reach', 'pre: legit · reach', 'out: legit · attacker denied'], $rows);

$h('## 7. Bounds, rollback, mixed version, Redis');
$h('');
foreach (ARMS as $arm) {
    $d = $data['o04_bounds_rollback'][$arm];
    $h("* **$arm** — one scope: " . json_encode(array_diff_key($d['O04.B1.one-scope'][0], ['s' => 0, 'arm' => 0])) . '; fifty scopes: ' . json_encode(array_diff_key($d['O04.B2.many-scopes'][0], ['s' => 0, 'arm' => 0])) . '; rollback: ' . json_encode(array_diff_key($d['O04.R1.rollback'][0], ['s' => 0, 'arm' => 0, 'secondSteps' => 0])) . '; mixed version: ' . implode(' / ', array_map(static fn(array $x): string => $x['stream'] . ' mixed ' . $x['reachedMixed'] . ' vs arm-only ' . $x['reachedThisArmOnly'], $d['O04.R2.mixed-version'])) . '.');
}
if ($redisStatus === 'RAN') {
    foreach (ARMS as $arm) {
        $d = $data['o05_redis'][$arm];
        $h("* **$arm Redis** — A/B/A benign `" . implode('', $d['O05.P1.aba-parity'][0]['benignRedis']) . '` attacker `' . implode('', $d['O05.P1.aba-parity'][0]['attackerRedis']) . '` (reference identical: ' . (($d['O05.P1.aba-parity'][0]['benignRedis'] === $d['O05.P1.aba-parity'][0]['benignReference'] && $d['O05.P1.aba-parity'][0]['attackerRedis'] === $d['O05.P1.aba-parity'][0]['attackerReference']) ? 'yes' : 'NO') . '); spray `' . implode('', $d['O05.P2.spray-parity'][0]['redis']) . '`; record-only `' . implode('', $d['O05.P3.record-only-parity'][0]['redis']) . '`; TTL hygiene ' . json_encode(array_diff_key($d['O05.P4.ttl-hygiene'][0], ['s' => 0, 'arm' => 0])) . '.');
    }
    $h('');
    $h('## 8. Concurrency on real Redis (F111-02) — simultaneous checkOnly requests that passed, per round');
    $h('');
    $rows = [];
    foreach (ARMS as $arm) {
        $d = $data['o06_concurrency'][$arm];
        $c1 = $d['O06.C1.k1-live-burst'][0];
        $c2 = $d['O06.C2.k2-live-burst'][0];
        $c3 = $d['O06.C3.k1-silent-burst'][0];
        $rows[] = [$arm, implode(' / ', array_column($c1['rounds'], 'allowedChecks')) . ' of 16', implode(' / ', array_column($c2['rounds'], 'allowedChecks')) . ' of 8', implode(' / ', array_column($c3['rounds'], 'allowedChecks')) . ' of 16', implode(' ', $c1['nextSequentialCheck']), 'L' . max(array_column($c1['rounds'], 'maxLevelIssuedInBurst')) . ' / L' . max(array_column($c2['rounds'], 'maxLevelIssuedInBurst'))];
    }
    $table(['Arm', 'C1 live K1 scope: 16 distinct failing subjects at once', 'C2 live K2 scope: 8 distinct fingerprints at once', 'C3 silent K1 scope (control)', 'C4 next sequential checkOnly', 'Highest block level issued inside one burst (K1 / K2)'], $rows);
    $h('Every worker\'s checkOnly precedes every worker\'s recordFailure (two-phase Redis-clock barrier). On a live scope the candidate observes nothing at checkOnly, so the whole burst is verified before the first failure issues the block: the per-cycle leak equals the concurrency, not 1.');
} else {
    $h('* Real-Redis stage **SKIPPED** — gates G9 and G10 are UNPROVEN.');
}
file_put_contents("$out/summary.md", implode("\n", $md) . "\n");
echo 'OK gates=' . count($gates) . " problems=0\n";
