<?php

declare(strict_types=1);

// #106 — report generator + fail-closed evidence gates.
//   php report.php <evidence-dir> <out-dir>
// Reads the scenario outputs, VALIDATES that the required evidence exists and is well-formed, then writes gates.jsonl and summary.md.
// Any missing / empty / malformed / violated evidence writes evidence_problems.txt, marks EVERY gate UNPROVEN and exits 5 (never a PASS).
// No timestamps, no randomness: the output is a pure function of the evidence.
require __DIR__ . '/lib/cells.php';

$in = $argv[1] ?? '';
$out = $argv[2] ?? '';
if (! is_dir($in) || ! is_dir($out)) {
    fwrite(STDERR, "usage: report.php <evidence-dir> <out-dir>\n");
    exit(2);
}

const FILES = ['k02_matrix', 'k03_boundaries_recovery', 'k04_lifecycle_trust', 'k05_ipv6_rotation_subject', 'k06_bounds', 'k08_seed_sensitivity'];

/** Required assertion identifiers (leading token of the assertion text) per scenario file. */
const REQUIRED = [
    'k02_matrix' => ['D2 arm A', 'D2 arm B', 'D2 record-only', 'CONTROL: arm C', 'CONTROL: arm B', 'CONTROL: the spray', 'DEC: spray'],
    'k03_boundaries_recovery' => ['B1', 'B2', 'B3', 'B4', 'B5', 'B6', 'B7', 'B8'],
    'k04_lifecycle_trust' => ['L1', 'L2', 'T1', 'T2'],
    'k05_ipv6_rotation_subject' => ['H1', 'H2', 'H2b', 'R1', 'R2', 'R3', 'R4', 'R5', 'S1', 'S2', 'S3', 'S4', 'S5'],
    'k06_bounds' => ['M1', 'M2', 'M3', 'M4'],
    'k08_seed_sensitivity' => ['D2:', 'Nu12'],
    'k07_redis' => ['R1', 'R1b', 'R1c', 'R2', 'R3', 'R4', 'R5'],
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
$redisStatus = is_file("$in/redis_status.txt") ? trim((string) file_get_contents("$in/redis_status.txt")) : '';
if (! in_array($redisStatus, ['RAN', 'SKIPPED'], true)) {
    $problems[] = 'redis_status.txt missing or not RAN/SKIPPED';
}
if ($redisStatus === 'RAN') {
    $load('k07_redis');
}

// Provenance: every file must carry exactly the S0 provenance row.
foreach (array_merge(FILES, $redisStatus === 'RAN' ? ['k07_redis'] : []) as $f) {
    $p = $data[$f][strtoupper(substr($f, 0, 3)) . '.provenance'] ?? null;
    if ($p === null || count($p) !== 1 || ($p[0]['level'] ?? '') !== 'S0_RUNTIME_OBSERVED' || ($p[0]['patched'] ?? true) !== false || ($p[0]['baseSha'] ?? '') !== '4d9495ded05f647ce0421352781ce948ceeaa606') {
        $problems[] = "$f: provenance row missing or not the unpatched S0 baseline";
    }
}

// Required assertions: present, and ALL true.
foreach (REQUIRED as $f => $ids) {
    if ($f === 'k07_redis' && $redisStatus !== 'RAN') {
        continue;
    }
    foreach ($ids as $id) {
        $rows = array_filter($assertions[$f] ?? [], static fn(array $a): bool => str_starts_with($a['assert'], $id));
        if ($rows === []) {
            $problems[] = "$f: required assertion '$id' not present";
            continue;
        }
        foreach ($rows as $a) {
            if (($a['ok'] ?? false) !== true) {
                $problems[] = "$f: assertion violated: " . $a['assert'];
            }
        }
    }
}

// Matrix completeness + integer fields.
$cells = [];
foreach ($data['k02_matrix']['K02.matrix'] ?? [] as $r) {
    $cells[$r['stream'] . '|' . $r['shape'] . '|' . $r['flow']] = $r;
}
foreach (k1MatrixCells() as [$id, $shape, $flow]) {
    $r = $cells["$id|$shape|$flow"] ?? null;
    if ($r === null) {
        $problems[] = "k02_matrix: missing cell $id / $shape / $flow";
        continue;
    }
    foreach (K1_ARMS_LIST as $arm) {
        foreach (k1ArmFields() as $field) {
            $v = $r['arms'][$arm][$field] ?? null;
            if (! is_int($v) || $v < 0) {
                $problems[] = "k02_matrix: cell $id/$shape/$flow arm $arm field $field missing or not a non-negative integer";
            }
        }
        $a = $r['arms'][$arm] ?? [];
        if (is_int($a['legitDenied'] ?? null) && $a['legitDenied'] > ($a['legitAttempts'] ?? -1)) {
            $problems[] = "k02_matrix: cell $id/$shape/$flow arm $arm denied > attempts";
        }
        if (is_int($a['attackReachedVerification'] ?? null) && $a['attackReachedVerification'] > ($a['attackAttempts'] ?? -1)) {
            $problems[] = "k02_matrix: cell $id/$shape/$flow arm $arm reached > attempts";
        }
    }
    foreach (['legitPartition', 'attackPartition'] as $pk) {
        if (! isset($r[$pk]['deniedA'])) {
            $problems[] = "k02_matrix: cell $id/$shape/$flow missing $pk";
        }
    }
}
foreach (['k03_boundaries_recovery' => ['K03.B1.four-five-boundary', 'K03.B4.recovery', 'K03.B5.fixed-window-boundary', 'K03.B6.watch-1800-boundary'], 'k05_ipv6_rotation_subject' => ['K05.H.ipv6', 'K05.R1.split-across-rotation', 'K05.S.subject-quality'], 'k06_bounds' => ['K06.M1.one-ip-bulk', 'K06.M4.many-ips'], 'k08_seed_sensitivity' => ['K08.seed-range'], 'k04_lifecycle_trust' => ['K04.L1.lifecycle', 'K04.T1.trust-shape']] as $f => $keys) {
    foreach ($keys as $k) {
        if (($data[$f][$k] ?? []) === []) {
            $problems[] = "$f: missing data rows $k";
        }
    }
}

const GATES = [
    ['G1', 'Baseline reproduction: the four arms reproduce the published S0 D2 numbers (117/108/115/7)', ['k02_matrix' => ['D2 arm A', 'D2 arm B', 'D2 record-only']]],
    ['G2', 'Isolated TEST-ONLY controls work (arm C/D disable K1 spray, arm B keeps it; spray stream constrained only by K1)', ['k02_matrix' => ['CONTROL: arm C', 'CONTROL: arm B', 'CONTROL: the spray'], 'k03_boundaries_recovery' => ['B7']]],
    ['G3', 'K1 boundaries and recovery measured exactly (4/5 WATCH, 5/5, 600 s fixed window, 1800 s WATCH, 60 s block vs set, OTP)', ['k03_boundaries_recovery' => ['B1', 'B2', 'B3', 'B4', 'B5', 'B6', 'B8']]],
    ['G4', 'Command lifecycle: precheck-only observation, no double count, record-only has no K1', ['k02_matrix' => ['DEC: spray'], 'k04_lifecycle_trust' => ['L1', 'L2']]],
    ['G5', 'Trust: only session-bound trusted devices are advisory; advisory still persists the block', ['k04_lifecycle_trust' => ['T1', 'T2']]],
    ['G6', 'IPv6 macro scopes and Current/Previous rotation', ['k05_ipv6_rotation_subject' => ['H1', 'H2', 'H2b', 'R1', 'R2', 'R3', 'R4', 'R5']]],
    ['G7', 'Host correlation-subject quality (absent / opaque / constant / per-request unique)', ['k05_ipv6_rotation_subject' => ['S1', 'S2', 'S3', 'S4', 'S5']]],
    ['G8', 'Reference-store bounds and TTL hygiene', ['k06_bounds' => ['M1', 'M2', 'M3', 'M4']]],
    ['G9', 'Typo-seed robustness of the benign numbers (20 seeds)', ['k08_seed_sensitivity' => ['D2:', 'Nu12']]],
    ['G10', 'Real Redis: parity, TTL hygiene, atomic cap under concurrency, rotation', ['k07_redis' => ['R1', 'R1b', 'R1c', 'R2', 'R3', 'R4', 'R5']]],
];

$gates = [];
foreach (GATES as [$id, $title, $need]) {
    $status = 'PASS';
    foreach ($need as $f => $ids) {
        if ($f === 'k07_redis' && $redisStatus !== 'RAN') {
            $status = 'UNPROVEN';
            continue;
        }
        foreach ($ids as $i) {
            $rows = array_filter($assertions[$f] ?? [], static fn(array $a): bool => str_starts_with($a['assert'], $i));
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
$lines = array_map(static fn(array $g): string => json_encode($g, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $gates);
file_put_contents("$out/gates.jsonl", implode("\n", $lines) . "\n");

if ($problems !== []) {
    file_put_contents("$out/evidence_problems.txt", implode("\n", $problems) . "\n");
    fwrite(STDOUT, 'EVIDENCE PROBLEMS: ' . count($problems) . "\n" . implode("\n", array_slice($problems, 0, 20)) . "\n");
    exit(5);
}

// ------------------------------------------------------------------------------------------------------------------------------------
// summary.md
// ------------------------------------------------------------------------------------------------------------------------------------
$m = static fn(string $id, string $shape, string $flow): array => $cells["$id|$shape|$flow"];
$arms = K1_ARMS_LIST;
$ld = static fn(array $cell, string $arm): string => $cell['arms'][$arm]['legitDenied'] . '/' . $cell['arms'][$arm]['legitAttempts'];
$ar = static fn(array $cell, string $arm): string => $cell['arms'][$arm]['attackReachedVerification'] . '/' . $cell['arms'][$arm]['attackAttempts'];
$md = [];
$h = static function (array &$md, string $s): void {
    $md[] = $s;
};
$table = static function (array &$md, array $head, array $rows): void {
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

$h($md, '# #106 — K1 credential-spray / shared-NAT evidence (generated; do not edit)');
$h($md, '');
$h($md, 'Generated by `report.php` from `results/actual/*.jsonl`. Level: **S0_RUNTIME_OBSERVED** (unpatched `EvaluationPipeline`, sha-256 `4b259d33…f4fc`, baseline `4d9495d`). Arms B/C/D are **TEST ONLY** controls in the injected correlation store: **A** = S0; **B** = churn+dilution sets off; **C** = K1 spray sets off; **D** = both off. Counts are requests (not people). No threshold, policy or runtime change is selected by anything below.');
$h($md, '');
$h($md, '## 1. Gates (evidence validity, not policy verdicts)');
$h($md, '');
$table($md, ['Gate', 'What it proves', 'Status'], array_map(static fn(array $g): array => [$g['gate'], $g['title'], '**' . $g['status'] . '**'], $gates));

$h($md, '## 2. D2 (shared NAT office, 12 colleagues, fresh fingerprint per login, 121 requests): who denies the 108/121?');
$h($md, '');
$rows = [];
foreach (['pre+out', 'pre', 'out'] as $flow) {
    $c = $m('D2', 'login', $flow);
    $p = $c['legitPartition'];
    $rows[] = [$flow, $ld($c, 'A'), $ld($c, 'B'), $ld($c, 'C'), $ld($c, 'D'), $p['sprayOnly(B denies,C frees)'], $p['corrOnly(C denies,B frees)'], $p['overlap(B,C each sufficient,D frees)'], $p['otherProtections(D denies)'], $p['interaction(only A denies)']];
}
$table($md, ['Flow (Login)', 'A S0', 'B churn/dil off', 'C K1 spray off', 'D both off', 'A-denials freed only by turning spray off', '…only by turning churn/dil off', '…by EITHER alone (overlap)', '…still denied with both off (other protections)', 'interaction'], $rows);
$h($md, 'Winning decision source / enforcement key of the denied legitimate requests (Login, pre+out), per arm:');
$h($md, '');
$c = $m('D2', 'login', 'pre+out');
$rows = [];
foreach ($arms as $a) {
    $rows[] = [$a, $src($c['arms'][$a]['legitDeniedBySource']), json_encode($c['arms'][$a]['blockIssuancesByScope'], JSON_UNESCAPED_SLASHES), $c['arms'][$a]['sprayThresholdRequests']];
}
$table($md, ['Arm', 'Denied legit requests by source (`issued`/`persisted`:enforcement key(signals observed in the request that issued the block))', 'Blocks issued by key', 'Requests that met the K1 spray threshold'], $rows);
$h($md, '`K5*` = K5 keys of the other devices of a distributed-account block (not an IP key). `unpersisted(...)` = denied without a persisted block (score thresholds).');
$h($md, '');

$h($md, '## 3. Benign shared-NAT populations: legitimate requests denied (Login)');
$h($md, '');
$rows = [];
foreach (['D2', 'N3', 'N4', 'N5', 'N6', 'N12', 'N12T', 'N12M', 'Nu3', 'Nu4', 'Nu5', 'Nu6', 'Nu12', 'Nu12T', 'Nu12M'] as $id) {
    foreach (['pre+out', 'pre', 'out'] as $flow) {
        $c = $m($id, 'login', $flow);
        $rows[] = [$id, $flow, $ld($c, 'A'), $ld($c, 'B'), $ld($c, 'C'), $ld($c, 'D'), $src($c['arms']['A']['legitDeniedBySource'])];
    }
}
$table($md, ['Stream', 'Flow', 'A S0', 'B churn/dil off', 'C K1 spray off', 'D both off', 'Winning source in A'], $rows);
$h($md, '`N*`: same UA for every colleague (realistic; K2 churn can also fire). `Nu*`: **K1-isolation control** — every colleague has a different browser major, so §5.3 cannot fire; not a realistic population. `T` = all trusted session devices, `M` = half trusted.');
$h($md, '');

$h($md, '## 4. Attack streams: attempts that reached credential verification (Login)');
$h($md, '');
$rows = [];
foreach (['SP1f', 'SP1n', 'SP3', 'SP6', 'SP2', 'SP4', 'SP4u', 'SP4b', 'SP5'] as $id) {
    foreach (['pre+out', 'pre', 'out'] as $flow) {
        $c = $m($id, 'login', $flow);
        $rows[] = [$id, $flow, $ar($c, 'A'), $ar($c, 'B'), $ar($c, 'C'), $ar($c, 'D'), $c['arms']['A']['attackDistinctSubjectsVerified'] . ' / ' . $c['arms']['C']['attackDistinctSubjectsVerified'], $src($c['arms']['A']['attackDeniedBySource'])];
    }
}
$table($md, ['Stream', 'Flow', 'A S0', 'B churn/dil off', 'C K1 spray off', 'D both off', 'distinct victims verified A / C', 'Winning source of the attacker denials in A'], $rows);
$h($md, 'SP1f spray with fresh fingerprints; SP1n spray without fingerprint (LOW); SP3 stuffing 1000 accounts at 1/s; SP6 one hour of 1 victim / 3 s from one IP; SP2 slow spray at 4 victims / 601 s; SP4 botnet of 8 IPs (same UA), SP4u (own UA per IP), SP4b (3 victims per IP per cycle); SP5 spray from a trusted session device.');
$h($md, '');

$h($md, '## 5. The measured frontier (what turning K1 spray off buys and costs)');
$h($md, '');
$h($md, 'Pairs **B → D** isolate K1 (§5.3 churn/dilution already off in both) and pairs **A → C** are the product configuration with only K1 spray removed. Login, `pre+out`.');
$h($md, '');
$rows = [];
foreach (['D2', 'Nu4', 'Nu5', 'Nu12', 'Nu12M', 'Nu12T'] as $id) {
    $c = $m($id, 'login', 'pre+out');
    $rows[] = ["benign `$id`", $ld($c, 'A') . ' → ' . $ld($c, 'C'), $ld($c, 'B') . ' → ' . $ld($c, 'D')];
}
foreach (['SP1f', 'SP1n', 'SP3', 'SP6', 'SP2', 'SP4', 'SP4u', 'SP4b', 'SP5'] as $id) {
    $c = $m($id, 'login', 'pre+out');
    $rows[] = ["attack `$id` (reached verification)", $ar($c, 'A') . ' → ' . $ar($c, 'C'), $ar($c, 'B') . ' → ' . $ar($c, 'D')];
}
$table($md, ['Population / attack', 'A → C (K1 spray off, §5.3 on)', 'B → D (K1 spray off, §5.3 off)'], $rows);
$h($md, 'Mixed streams (benign colleagues AND a spray attacker behind the same NAT; one stream, one clock):');
$h($md, '');
$rows = [];
foreach (['MIX', 'MIXT', 'MIXU', 'MIXN'] as $id) {
    foreach (['pre+out', 'pre', 'out'] as $flow) {
        $c = $m($id, 'login', $flow);
        $both = static fn(string $a): string => 'legit ' . $ld($c, $a) . ' · attacker ' . $ar($c, $a);
        $rows[] = [$id, $flow, $both('A'), $both('B'), $both('C'), $both('D')];
    }
}
$table($md, ['Stream', 'Flow', 'A S0', 'B churn/dil off', 'C K1 spray off', 'D both off'], $rows);
$h($md, 'MIX = D2 office + attacker; MIXT = 12 trusted colleagues (same UA) + attacker; MIXU = 12 trusted isolated colleagues + attacker; MIXN = 12 untrusted isolated colleagues + attacker.');
$h($md, '');

$h($md, '## 6. Other Host shapes (Login without account id, OTP)');
$h($md, '');
$rows = [];
foreach (k1MatrixCells() as [$id, $shape, $flow]) {
    if ($shape === 'login') {
        continue;
    }
    $c = $m($id, $shape, $flow);
    $rows[] = [$id, $shape, $flow, $ld($c, 'A'), $ld($c, 'B'), $ld($c, 'C'), $ld($c, 'D'), $ar($c, 'A'), $ar($c, 'B'), $ar($c, 'C'), $ar($c, 'D')];
}
$table($md, ['Stream', 'Shape', 'Flow', 'legit A', 'legit B', 'legit C', 'legit D', 'attack A', 'attack B', 'attack C', 'attack D'], $rows);

$h($md, '## 7. Boundaries and recovery (controlled clock, Login checkOnly)');
$h($md, '');
$d = $data['k03_boundaries_recovery'];
$h($md, '* **4/5 and 5/5** — steps `' . implode(' ', $d['K03.B1.four-five-boundary'][0]['steps']) . '` (`t:decision#spray-count`).');
foreach ($d['K03.B2.watch-counts-requests'] ?? [] as $r) {
    $h($md, '* **WATCH counts requests** — ' . $r['case'] . ': `' . implode(' ', $r['steps']) . '` → ' . $r['src']);
}
$h($md, '* **3 subjects with repeats** — ' . $d['K03.B3.three-subjects-repeats'][0]['denied'] . ' denied, max count ' . $d['K03.B3.three-subjects-repeats'][0]['maxSprayCount'] . '.');
foreach ($d['K03.B4.recovery'] as $r) {
    $h($md, '* **Recovery** — ' . $r['probe'] . ': `' . $r['runs'] . '`, first allowed t=' . $r['firstAllowedT'] . ', K1 block issuances ' . $r['blockIssuances'] . '.');
}
$h($md, '* **600 s fixed window** — 5th subject at t=' . implode(', ', array_map(static fn(array $r): string => $r['fifthAt'] . ($r['blocked'] ? ' denied' : ' allowed'), $d['K03.B5.fixed-window-boundary'])) . '.');
$h($md, '* **1800 s WATCH** — 4th subject of the 2nd window at t=' . implode(', ', array_map(static fn(array $r): string => $r['fourthOfSecondWindowAt'] . ($r['blocked'] ? ' denied' : ' allowed'), $d['K03.B6.watch-1800-boundary'])) . '.');
$h($md, '');

$h($md, '## 8. Lifecycle, trust, IPv6, rotation, subject quality');
$h($md, '');
$rows = [];
foreach ($data['k04_lifecycle_trust']['K04.L1.lifecycle'] as $r) {
    $rows[] = [$r['shape'], $r['flow'], (string) $r['subjects'], (string) $r['sprayObservations'], (string) $r['reachedVerification'], $r['firstDenyT'] === null ? '—' : (string) $r['firstDenyT']];
}
$table($md, ['Shape', 'Flow', 'Distinct subjects', 'Spray observations', 'Reached verification', 'First deny t'], $rows);
$rows = [];
foreach ($data['k04_lifecycle_trust']['K04.T1.trust-shape'] as $r) {
    $rows[] = [$r['shape'], $r['allowed'] ? 'allowed' : 'denied', (string) $r['sprayCount'], $r['src'] ?? '—'];
}
$table($md, ['6th-subject probe on a filled K1 set', 'Decision', 'Spray count seen', 'Source'], $rows);
foreach ($data['k05_ipv6_rotation_subject']['K05.H.ipv6'] as $r) {
    $h($md, '* **' . $r['case'] . '** — legit denied A/B/C/D: ' . implode('/', array_map(static fn(string $a): string => (string) $r['arms'][$a]['legitDenied'], $arms)) . '; attacker verified A/B/C/D: ' . implode('/', array_map(static fn(string $a): string => (string) $r['arms'][$a]['attackReachedVerification'], $arms)) . '.');
}
foreach (['K05.R1.split-across-rotation', 'K05.R2.repeat-across-rotation', 'K05.R3.rotation-as-reset', 'K05.R4.block-continuity'] as $k) {
    $r = $data['k05_ipv6_rotation_subject'][$k][0];
    $h($md, '* **' . $k . '** — `' . implode(' ', $r['steps']) . '`' . (isset($r['noRotationReference']) ? ' (no-rotation reference `' . implode(' ', $r['noRotationReference']) . '`)' : '') . '.');
}
$h($md, '');
$rows = [];
foreach ($data['k05_ipv6_rotation_subject']['K05.S.subject-quality'] as $r) {
    $rows[] = [$r['case'], $r['arms']['A']['attackReachedVerification'] . '/200', $r['arms']['A']['legitDenied'] . '/6', (string) $r['arms']['A']['sprayObservations'], $r['arms']['C']['attackReachedVerification'] . '/200'];
}
$table($md, ['Correlation subject supplied by the Host', 'Attacker verified (A)', 'Legit denied (A)', 'Spray observations (A)', 'Attacker verified (C, K1 off)'], $rows);

$h($md, '## 9. Bounds, seed robustness, Redis');
$h($md, '');
foreach (['K06.M1.one-ip-bulk', 'K06.M4.many-ips'] as $k) {
    $r = $data['k06_bounds'][$k][0];
    unset($r['s']);
    $h($md, '* `' . $k . '` ' . json_encode($r, JSON_UNESCAPED_SLASHES));
}
$rows = [];
foreach ($data['k08_seed_sensitivity']['K08.seed-range'] as $r) {
    $f = static fn(array $x): string => $x['min'] === $x['max'] ? (string) $x['min'] : $x['min'] . '–' . $x['max'] . ' (median ' . $x['median'] . ')';
    $rows[] = [$r['population'], (string) $r['attempts'], $f($r['armA']), $f($r['armB']), $f($r['armC']), $f($r['armD'])];
}
$h($md, '');
$table($md, ['Population (20 typo seeds)', 'Attempts', 'A legit denied', 'B', 'C', 'D'], $rows);
if ($redisStatus === 'RAN') {
    foreach ($data['k07_redis'] as $k => $rs) {
        if (str_contains($k, 'provenance')) {
            continue;
        }
        $r = $rs[0];
        unset($r['s']);
        $h($md, '* `' . $k . '` ' . json_encode($r, JSON_UNESCAPED_SLASHES));
    }
} else {
    $h($md, '* Real-Redis stage **SKIPPED** — gate G10 is UNPROVEN.');
}
file_put_contents("$out/summary.md", implode("\n", $md) . "\n");
echo "OK gates=" . count($gates) . ' problems=0' . "\n";
