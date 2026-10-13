<?php

declare(strict_types=1);

// #110 Outcome-Aware Atomic Admission — report generator + fail-closed evidence gates.
//   php report.php <evidence-dir> <out-dir>
// Reads the per-arm scenario outputs, VALIDATES that the required evidence exists, is well-formed, correctly labelled, from the pinned
// runtimes, and that S0 reproduces the merged #115 S0 golden; then evaluates the gates pre-registered in SECURITY_ENVELOPE.md and writes
// gates.jsonl and summary.md. Any evidence problem writes evidence_problems.txt, marks EVERY gate UNPROVEN and exits 5 (never a PASS).
// A violated candidate assertion or a measured limit that does not hold is a FINDING (gate FAIL, exit 0), not an evidence problem.
// No timestamps, no randomness.
require dirname(__DIR__) . '/guarded-network-headroom-110/lib/cells.php';
require __DIR__ . '/lib/cells.php';

$in = $argv[1] ?? '';
$out = $argv[2] ?? '';
if (! is_dir($in) || ! is_dir($out)) {
    fwrite(STDERR, "usage: report.php <evidence-dir> <out-dir>\n");
    exit(2);
}
const ARMS = OAAA_ARMS_LIST;
const FILES = ['a01_matrix', 'a02_lifecycle', 'a03_rotation_ipv6', 'a04_failure_rollback_bounds'];
const REDIS_FILES = ['a05_redis', 'a06_concurrency', 'a07_host_probe'];
const BASE_SHA = '72f6dea8ab0729e63492b622e682e334c527c966';
const PINNED = [
    'S0' => ['pipeline' => '4b259d3397adf492d9767572141c9bdf5efc5744886bce63592ae9f36b95f4fc', 'redis' => '520574d4b0758470c0fc36ec304001825cf20a183e28bf6a999bcd41d54297a4', 'iface' => false],
    'OAAA' => ['pipeline' => '2af6f5599c809a829c0a7c792e35e49dc9e777482f2560250fa60f59067ffa20', 'redis' => 'a8af48c7661f1a466fe12416530598f7677583ae3683f7dd4a1eb3456002977f', 'iface' => true],
];
const PATCH_FILE = __DIR__ . '/patches/oaaa-candidate.patch';
const PATCH_SHA256 = 'e8032f5de23e7185d49daa371c08dc56d7edf723268b24e2d0ece3ff24639aac';
const S0_GOLDEN = __DIR__ . '/../guarded-network-headroom-110/expected/g01_matrix.S0.jsonl';
const GOLDEN_SHA256 = '6958858d2012f79e392c6acdf52cc4488c8f6e99b8ecab6cf229e66d26fd9caa';

/** Required assertions per file: identifier => count, per arm. */
const REQUIRED = [
    'a01_matrix' => ['S0' => ['S0 D2' => 1, 'S0 SP1n/SP3/SP6' => 1, 'S0 L1' => 1, 'S0 ABA_A' => 1, 'S0 TMSd' => 1, 'S0 Nu4' => 1], 'OAAA' => []],
    'a02_lifecycle' => ['S0' => ['L1' => 1, 'L2' => 1, 'L3' => 1, 'L4' => 1, 'L5' => 1, 'L6' => 1, 'L7' => 1, 'L8' => 1, 'L9' => 1, 'L10' => 1, 'L11' => 1, 'L12' => 1, 'L13' => 1, 'L14' => 1, 'L17/L18' => 1, 'L20' => 1, 'L21' => 1, 'L22' => 1, 'L23' => 1], 'OAAA' => ['L1' => 1, 'L2' => 1, 'L3' => 1, 'L4' => 1, 'L5' => 1, 'L6' => 1, 'L7' => 1, 'L8' => 1, 'L9' => 1, 'L10' => 1, 'L11' => 1, 'L12' => 1, 'L13' => 1, 'L14' => 1, 'L17/L18' => 1, 'L20' => 1, 'L21' => 1, 'L22' => 1, 'L23' => 1]],
    'a03_rotation_ipv6' => ['S0' => ['R2' => 1, 'R3' => 1, 'H1' => 1, 'H2' => 1], 'OAAA' => ['R2' => 1, 'R3' => 1, 'H1' => 1, 'H2' => 1]],
    'a04_failure_rollback_bounds' => ['S0' => ['F1' => 1], 'OAAA' => ['S1/S2' => 1, 'F1' => 1, 'R1' => 1, 'R2' => 1, 'R3' => 1, 'R4' => 1, 'R5' => 1]],
    'a05_redis' => ['S0' => ['Q1' => 1, 'Q2' => 1], 'OAAA' => ['Q1' => 1, 'Q2' => 1, 'Q3' => 1, 'Q4' => 1, 'Q5' => 1]],
    'a06_concurrency' => ['S0' => ['C1' => 2, 'C2' => 2, 'C3' => 2, 'C4' => 2, 'C5' => 2, 'C6' => 2, 'C8' => 2, 'T1' => 1], 'OAAA' => ['C1' => 2, 'C2' => 2, 'C3' => 2, 'C4' => 2, 'C5' => 2, 'C6' => 2, 'C7' => 1, 'C8' => 2, 'T1' => 1]],
    'a07_host_probe' => ['S0' => ['H0' => 1, 'H1' => 1, 'H2' => 1], 'OAAA' => ['H0' => 1, 'H1' => 1, 'H2' => 1]],
];
/** Data rows each file must carry ('*' every arm, or one arm). */
const DATA_ROWS = [
    'a01_matrix' => ['*' => ['A01.matrix', 'A01.stream']],
    'a02_lifecycle' => ['*' => ['A02.L1.pure-attacker', 'A02.L2.credit-gating', 'A02.L3.pre-only', 'A02.L4.success-returns', 'A02.L5.same-tuple-refund', 'A02.L6.cross-account', 'A02.L7.replayed-success', 'A02.L8.duplicate-failure', 'A02.L9.out-of-order', 'A02.L10.late-completion', 'A02.L11.epoch-rollover', 'A02.L12.day-tier', 'A02.L13.global-tier', 'A02.L14.own-credit-cap', 'A02.L15.trusted-advisory', 'A02.L16.persisted-block', 'A02.L17.api-overuse', 'A02.L18.login-anon', 'A02.L19.no-capability', 'A02.L20.cancel', 'A02.L21.all-or-nothing', 'A02.L22.pairing', 'A02.L23.record-only']],
    'a03_rotation_ipv6' => ['*' => ['A03.R1.rotation', 'A03.R2.units-across-rotation', 'A03.R3.credit-across-rotation', 'A03.R4.outer-only-single-device', 'A03.H.ipv6']],
    'a04_failure_rollback_bounds' => ['*' => ['A04.S1.storage', 'A04.P1.public-surface', 'A04.F1.oaaa-operation-failure', 'A04.F2a.store-outage', 'A04.F2b.store-outage'], 'S0' => ['A04.R.reference'], 'OAAA' => ['A04.R1.rollback', 'A04.R2.mixed-version', 'A04.R3.s0-family-state-parity', 'A04.R4.rollback-decision-parity', 'A04.R5.roll-forward']],
    'a05_redis' => ['*' => ['A05.Q1.parity', 'A05.Q2.key-hygiene'], 'S0' => ['A05.Q.reference'], 'OAAA' => ['A05.Q3.malformed-state', 'A05.Q4.rollback-redis', 'A05.Q5.mixed-version-redis']],
    'a06_concurrency' => ['*' => ['A06.C1.burst-one-unit-left', 'A06.C2.burst-no-unit-left', 'A06.C3.k1-k2-all-or-nothing', 'A06.C4.global-tier-burst', 'A06.C5.successes-racing-prechecks', 'A06.C6.replayed-completion', 'A06.C8.rotation-active-reservations', 'A06.T1.ttl-hygiene'], 'OAAA' => ['A06.C7.expiry-during-verification']],
    'a07_host_probe' => ['*' => ['A07.H1.host-pre-out', 'A07.H2.host-pre-only']],
];

$problems = [];
$data = [];
$assertions = [];
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
            if (($row['arm'] ?? '') !== $arm || ! is_bool($row['ok'] ?? null)) {
                $problems[] = "$name.$arm.jsonl line $n: assertion row with a foreign arm label or a non-boolean ok";
            }
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
    $files = array_merge($files, REDIS_FILES);
}
foreach ($files as $f) {
    foreach (ARMS as $arm) {
        $load($f, $arm);
    }
}
if (! is_file(PATCH_FILE) || hash_file('sha256', PATCH_FILE) !== PATCH_SHA256) {
    $problems[] = 'patches/oaaa-candidate.patch missing or not the pinned candidate patch';
}
$rowsFor = static fn(string $f, string $arm, string $id): array => array_values(array_filter($assertions[$f][$arm] ?? [], static fn(array $a): bool => str_starts_with($a['assert'], $id . ' ')));
$okOf = static function (string $f, string $arm, string $id) use ($rowsFor): ?bool {
    $r = $rowsFor($f, $arm, $id);

    return $r === [] ? null : array_reduce($r, static fn(bool $c, array $a): bool => $c && $a['ok'] === true, true);
};

// Provenance: one provenance row per file and arm, right level, pinned pipeline + Redis adapter, experimental interface present only on OAAA.
foreach ($files as $f) {
    foreach (ARMS as $arm) {
        $p = $data[$f][$arm][strtoupper(substr($f, 0, 3)) . '.provenance'] ?? null;
        $okRow = $p !== null && count($p) === 1 && ($p[0]['arm'] ?? '') === $arm && ($p[0]['baseSha'] ?? '') === BASE_SHA
            && ($p[0]['level'] ?? '') === ($arm === 'S0' ? 'S0_RUNTIME_OBSERVED' : 'CANDIDATE_EXPERIMENTAL_RUNTIME')
            && ($p[0]['patched'] ?? null) === ($arm !== 'S0') && ($p[0]['pipelineSha256'] ?? '') === PINNED[$arm]['pipeline']
            && ($p[0]['redisAdapterSha256'] ?? '') === PINNED[$arm]['redis'] && ($p[0]['experimentalInterface'] ?? null) === PINNED[$arm]['iface'];
        if (! $okRow) {
            $problems[] = "$f.$arm: provenance row missing or not the pinned " . ($arm === 'S0' ? 'unpatched S0 baseline' : 'patched candidate') . ' (arm, level, baseSha, patched flag, pipeline / Redis adapter sha-256, experimental interface)';
        }
        foreach ($data[$f][$arm] ?? [] as $s => $rows) {
            foreach ($rows as $r) {
                if (array_key_exists('arm', $r) && $r['arm'] !== $arm) {
                    $problems[] = "$f.$arm: data row $s carries arm '{$r['arm']}'";
                }
            }
        }
    }
}
// Required assertions: exact counts, no unknown rows; S0 assertions are baseline invariants.
foreach (REQUIRED as $f => $sets) {
    if (in_array($f, REDIS_FILES, true) && $redisStatus !== 'RAN') {
        continue;
    }
    foreach (ARMS as $arm) {
        $matched = 0;
        foreach ($sets[$arm] as $id => $expect) {
            $rows = $rowsFor($f, $arm, $id);
            $matched += count($rows);
            if (count($rows) !== $expect) {
                $problems[] = "$f.$arm: assertion '$id' present " . count($rows) . " time(s), expected $expect";
            }
        }
        if ($matched !== count($assertions[$f][$arm] ?? [])) {
            $problems[] = "$f.$arm: " . (count($assertions[$f][$arm] ?? []) - $matched) . ' unknown/unmatched assertion row(s)';
        }
        if ($arm === 'S0') {
            foreach ($assertions[$f]['S0'] ?? [] as $a) {
                if ($a['ok'] !== true) {
                    $problems[] = "$f.S0: baseline invariant failed (unreproduced S0): " . $a['assert'];
                }
            }
        }
    }
}
foreach (DATA_ROWS as $f => $sets) {
    if (in_array($f, REDIS_FILES, true) && $redisStatus !== 'RAN') {
        continue;
    }
    foreach (ARMS as $arm) {
        foreach (array_merge($sets['*'] ?? [], $sets[$arm] ?? []) as $s) {
            if (! isset($data[$f][$arm][$s])) {
                $problems[] = "$f.$arm: required data row $s missing";
            }
        }
    }
}
// Matrix: every catalogue cell exactly once per arm, every integer field present.
$M = [];
foreach (ARMS as $arm) {
    foreach ($data['a01_matrix'][$arm]['A01.matrix'] ?? [] as $r) {
        $k = ($r['stream'] ?? '?') . '|' . ($r['shape'] ?? '?') . '|' . ($r['flow'] ?? '?');
        if (isset($M[$arm][$k])) {
            $problems[] = "a01_matrix.$arm: duplicate cell $k";
        }
        foreach (oaaaCellFields() as $field) {
            if (! is_int($r[$field] ?? null)) {
                $problems[] = "a01_matrix.$arm: cell $k field $field missing or not an integer";
                break;
            }
        }
        foreach (['legitDeniedBits', 'attackReachedBits', 'attackReachedBy60', 'attackReachedByIp600'] as $field) {
            if (! array_key_exists($field, $r)) {
                $problems[] = "a01_matrix.$arm: cell $k field $field missing";
                break;
            }
        }
        if (($r['level'] ?? '') !== ($arm === 'S0' ? 'S0_RUNTIME_OBSERVED' : 'CANDIDATE_EXPERIMENTAL_RUNTIME')) {
            $problems[] = "a01_matrix.$arm: cell $k carries level '" . ($r['level'] ?? '') . "'";
        }
        $M[$arm][$k] = $r;
    }
    foreach (oaaaMatrixCells() as [$id, $shape, $flow]) {
        if (! isset($M[$arm]["$id|$shape|$flow"])) {
            $problems[] = "a01_matrix.$arm: matrix cell $id/$shape/$flow missing";
        }
    }
}
// S0 must reproduce the merged #115 S0 golden on every common cell.
if (! is_file(S0_GOLDEN) || hash_file('sha256', S0_GOLDEN) !== GOLDEN_SHA256) {
    $problems[] = 'merged #115 S0 golden (guarded-network-headroom-110/expected/g01_matrix.S0.jsonl) missing or changed';
} else {
    $common = 0;
    foreach (file(S0_GOLDEN, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $g = json_decode($line, true);
        if (($g['s'] ?? '') !== 'G01.matrix') {
            continue;
        }
        $k = "{$g['stream']}|{$g['shape']}|{$g['flow']}";
        if (! isset($M['S0'][$k])) {
            continue;
        }
        $common++;
        foreach (['legitAttempts', 'legitDenied', 'attackAttempts', 'attackReachedVerification', 'attackDenied'] as $field) {
            if ($M['S0'][$k][$field] !== $g[$field]) {
                $problems[] = "a01_matrix.S0: cell $k $field {$M['S0'][$k][$field]} does not reproduce the merged #115 S0 golden ({$g[$field]})";
            }
        }
    }
    if ($common < 300) {
        $problems[] = "a01_matrix.S0: only $common cells in common with the #115 S0 golden (expected >= 300)";
    }
}

// ---------------------------------------------------------------------------------------------------------------------------------------
$gates = [];
$gate = static function (string $id, string $group, string $status, string $rule, array $detail = []) use (&$gates): void {
    $gates[] = ['gate' => $id, 'group' => $group, 'status' => $status, 'rule' => $rule] + ($detail === [] ? [] : ['detail' => $detail]);
};
$problems = array_values(array_unique($problems));
if ($problems !== []) {
    file_put_contents("$out/evidence_problems.txt", implode("\n", $problems) . "\n");
    foreach (['E1', 'E2', 'E3', 'E4', 'E5', 'E6', 'F1', 'F2', 'O1', 'O2', 'O3', 'O4', 'O5', 'O6', 'O7a', 'O7b', 'O7c', 'O7d', 'O8', 'O9', 'O10'] as $g) {
        $gate($g, '-', 'UNPROVEN', 'evidence problems: see evidence_problems.txt');
    }
    $gates[] = ['gate' => 'DECISION', 'group' => '-', 'status' => 'UNPROVEN', 'rule' => 'evidence problems'];
    file_put_contents("$out/gates.jsonl", implode("\n", array_map(static fn(array $g): string => json_encode($g, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $gates)) . "\n");
    fwrite(STDOUT, count($problems) . " evidence problem(s); every gate UNPROVEN\n");
    exit(5);
}
@unlink("$out/evidence_problems.txt");

$classes = oaaaStreamClasses();
$authShapes = ['login', 'otp', 'login-anon', 'login_custom'];
$isAttackClass = static fn(string $c): bool => in_array($c, ['attack', 'evasion', 'poisoning', 'both'], true);
/** per-cell security deltas */
$sec = [];
foreach ($M['S0'] as $k => $s) {
    [$id, $shape, $flow] = explode('|', $k);
    $o = $M['OAAA'][$k];
    if (! in_array($shape, $authShapes, true) || ! $isAttackClass($classes[$id]) || $s['attackAttempts'] === 0) {
        continue;
    }
    // 60 s buckets -> fixed and 60 s-aligned rolling windows
    $b = [];
    foreach ([$s['attackReachedBy60'], $o['attackReachedBy60']] as $i => $by) {
        foreach ($by as $bucket => $n) {
            $b[(int) $bucket][$i] = ($b[(int) $bucket][$i] ?? 0) + $n;
        }
    }
    $maxB = $b === [] ? 0 : max(array_keys($b));
    $d60 = [];
    for ($x = 0; $x <= $maxB; $x++) {
        $d60[$x] = ($b[$x][1] ?? 0) - ($b[$x][0] ?? 0);
    }
    $fixed = static function (int $w) use ($d60, $maxB): int {
        $best = 0;
        for ($x = 0; $x <= $maxB; $x += $w) {
            $best = max($best, array_sum(array_slice($d60, $x, $w)));
        }

        return $best;
    };
    $rolling = static function (int $w) use ($d60, $maxB): int {
        $best = 0;
        for ($x = 0; $x <= $maxB; $x++) {
            $best = max($best, array_sum(array_slice($d60, $x, $w)));
        }

        return $best;
    };
    $ipD = [];
    $winD = [];
    foreach (array_unique(array_merge(array_keys($s['attackReachedByIp600']), array_keys($o['attackReachedByIp600']))) as $ip) {
        $ws = array_unique(array_merge(array_keys($s['attackReachedByIp600'][$ip] ?? []), array_keys($o['attackReachedByIp600'][$ip] ?? [])));
        foreach ($ws as $w) {
            $d = ($o['attackReachedByIp600'][$ip][$w] ?? 0) - ($s['attackReachedByIp600'][$ip][$w] ?? 0);
            $ipD[$ip]['max600'] = max($ipD[$ip]['max600'] ?? PHP_INT_MIN, $d);
            $ipD[$ip]['total'] = ($ipD[$ip]['total'] ?? 0) + $d;
            $winD[$w] = ($winD[$w] ?? 0) + $d;
        }
    }
    $extraWhileS0Blocks = 0;
    $lessReach = 0;
    for ($i = 0, $n = strlen($s['attackReachedBits']); $i < $n; $i++) {
        $extraWhileS0Blocks += $s['attackReachedBits'][$i] === '0' && $o['attackReachedBits'][$i] === '1' ? 1 : 0;
        $lessReach += $s['attackReachedBits'][$i] === '1' && $o['attackReachedBits'][$i] === '0' ? 1 : 0;
    }
    $sec[$k] = [
        'stream' => $id, 'shape' => $shape, 'flow' => $flow, 'class' => $classes[$id], 'attempts' => $s['attackAttempts'],
        's0' => $s['attackReachedVerification'], 'oaaa' => $o['attackReachedVerification'], 'delta' => $o['attackReachedVerification'] - $s['attackReachedVerification'],
        's0Failing' => $s['attackFailingReached'], 'oaaaFailing' => $o['attackFailingReached'], 'failingAttempts' => $s['attackFailingAttempts'],
        'd60' => $fixed(1), 'd600' => $fixed(10), 'd1800' => $fixed(30), 'r600' => $rolling(10), 'r1800' => $rolling(30),
        'scopeMax600' => $ipD === [] ? 0 : max(array_column($ipD, 'max600')), 'scopeMaxTotal' => $ipD === [] ? 0 : max(array_column($ipD, 'total')),
        'aggMax600' => $winD === [] ? 0 : max($winD), 'scopes' => max($s['attackScopes'], $o['attackScopes']),
        'extraWhileS0Blocks' => $extraWhileS0Blocks, 'lessReachThanS0' => $lessReach, 'attackGrants' => $o['attackReservationsGranted'],
    ];
}
$gated = array_filter($sec, static fn(array $c): bool => $c['flow'] !== 'out');

// E4 collapse
$collapse = array_keys(array_filter($gated, static fn(array $c): bool => $c['s0'] < $c['attempts'] && $c['oaaa'] === $c['attempts']));
$gate('E4', 'forbidden', $collapse === [] ? 'PASS' : 'FAIL', 'no attack cell (pre+out / pre) with S0 reach < attempts and OAAA reach = attempts', ['cells' => $collapse]);
// E5 replay / concurrency
$e5ids = ['a02_lifecycle' => ['L6', 'L7', 'L8', 'L9', 'L10', 'L11', 'L21', 'L22'], 'a06_concurrency' => ['C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'C8']];
$e5 = [];
foreach ($e5ids as $f => $ids) {
    foreach ($ids as $id) {
        $e5["$f:$id"] = $f === 'a06_concurrency' && $redisStatus !== 'RAN' ? null : $okOf($f, 'OAAA', $id);
    }
}
$e5status = in_array(null, $e5, true) ? 'UNPROVEN' : (in_array(false, $e5, true) ? 'FAIL' : 'PASS');
$gate('E5', 'forbidden', $e5status, 'every replay / duplicate / late / cross-account / out-of-order / all-or-nothing assertion (a02) and every real-Redis concurrency assertion (a06) holds on OAAA', ['assertions' => $e5, 'redis' => $redisStatus]);
// E6 aggregate
$multi = array_filter($gated, static fn(array $c): bool => $c['scopes'] > 1);
$e6bad = array_keys(array_filter($multi, static fn(array $c): bool => $c['aggMax600'] > OAAA_PARAMS['globalEpochUnits'] || $c['delta'] > OAAA_PARAMS['globalDayUnits']));
$l13 = $okOf('a02_lifecycle', 'OAAA', 'L13');
$gate('E6', 'forbidden', $e6bad === [] && $l13 === true ? 'PASS' : 'FAIL', 'multi-scope attack cells: aggregated delta per fixed 600 s window <= 12 and total delta <= 48; a02 L13 (global exhaustion) holds', ['multiScopeCells' => count($multi), 'worstAggregate600' => $multi === [] ? 0 : max(array_column($multi, 'aggMax600')), 'worstTotal' => $multi === [] ? 0 : max(array_column($multi, 'delta')), 'violations' => $e6bad, 'L13' => $l13]);
// E1 per scope per 600 s
$e1bad = array_filter($gated, static fn(array $c): bool => $c['scopeMax600'] > 6);
$gate('E1', 'provisional', $e1bad === [] ? 'PASS' : 'FAIL', 'max per-scope delta in any fixed 600 s window <= +6', ['worst' => $gated === [] ? 0 : max(array_column($gated, 'scopeMax600')), 'violations' => array_map(static fn(array $c): int => $c['scopeMax600'], $e1bad)]);
// E2 burst
if ($redisStatus === 'RAN') {
    $burst = [];
    foreach (['A06.C1.burst-one-unit-left', 'A06.C2.burst-no-unit-left', 'A06.C3.k1-k2-all-or-nothing'] as $s) {
        $a0 = array_column($data['a06_concurrency']['S0'][$s][0]['rounds'], 'admitted');
        $a1 = array_column($data['a06_concurrency']['OAAA'][$s][0]['rounds'], 'admitted');
        foreach ($a1 as $i => $n) {
            $burst[$s][] = $n - $a0[$i];
        }
    }
    $worst = max(array_map('max', $burst));
    $gate('E2', 'provisional', $worst <= 2 ? 'PASS' : 'FAIL', 'single-scope real-Redis bursts (C1, C2, C3): OAAA admitted - S0 admitted <= 2 in every round', ['deltaPerRound' => $burst, 'worst' => $worst]);
} else {
    $gate('E2', 'provisional', 'UNPROVEN', 'real-Redis concurrency did not run');
}
// E3 S0 = 0 cells
$zero = array_filter($gated, static fn(array $c): bool => $c['s0'] === 0);
$e3bad = array_filter($zero, static fn(array $c): bool => $c['scopeMax600'] > 1 || $c['aggMax600'] > 1 || $c['r600'] > 1 || $c['scopeMaxTotal'] > OAAA_PARAMS['scopeDayUnits']);
$gate('E3', 'provisional', $e3bad === [] ? 'PASS' : 'FAIL', 'attack cells with S0 reach = 0: delta <= 1 in every fixed and 60 s-aligned rolling 600 s window, and total delta per scope <= 6', ['s0ZeroCells' => count($zero), 'violations' => array_map(static fn(array $c): array => ['fixed600' => max($c['scopeMax600'], $c['aggMax600']), 'rolling600' => $c['r600'], 'total' => $c['scopeMaxTotal']], $e3bad)]);

// Fairness
$fair = [];
foreach (oaaaTaskFairnessStreams() as $issue => $ids) {
    foreach ($ids as $id) {
        foreach (['pre+out', 'pre', 'out'] as $flow) {
            $s = $M['S0']["$id|login|$flow"];
            $o = $M['OAAA']["$id|login|$flow"];
            $newDen = 0;
            $newAdm = 0;
            for ($i = 0, $n = strlen($s['legitDeniedBits']); $i < $n; $i++) {
                $newDen += $s['legitDeniedBits'][$i] === '0' && $o['legitDeniedBits'][$i] === '1' ? 1 : 0;
                $newAdm += $s['legitDeniedBits'][$i] === '1' && $o['legitDeniedBits'][$i] === '0' ? 1 : 0;
            }
            $fair[$issue][] = ['stream' => $id, 'flow' => $flow, 'attempts' => $s['legitAttempts'], 's0' => $s['legitDenied'], 'oaaa' => $o['legitDenied'], 'newlyAdmitted' => $newAdm, 'newlyDenied' => $newDen];
        }
    }
}
$gate('F1', 'fairness', 'MEASURED', 'legitimate denials per issue (no pre-set acceptance threshold; Owner decision)', ['perIssue' => array_map(static fn(array $rows): array => array_map(static fn(array $r): string => "{$r['stream']}/{$r['flow']}: {$r['s0']}->{$r['oaaa']}", $rows), $fair)]);
$regress = [];
$newlyDeniedTotal = 0;
foreach ($M['S0'] as $k => $s) {
    if (str_starts_with(explode('|', $k)[1], 'api')) {
        continue;
    }
    $o = $M['OAAA'][$k];
    if ($o['legitDenied'] > $s['legitDenied']) {
        $regress[$k] = "{$s['legitDenied']}->{$o['legitDenied']}";
    }
    for ($i = 0, $n = strlen($s['legitDeniedBits']); $i < $n; $i++) {
        $newlyDeniedTotal += $s['legitDeniedBits'][$i] === '0' && $o['legitDeniedBits'][$i] === '1' ? 1 : 0;
    }
}
$gate('F2', 'fairness', $regress === [] ? 'PASS' : 'FAIL', 'no cell in which OAAA denies more legitimate requests than S0', ['regressions' => $regress, 'legitRequestsNewlyDeniedAllCells' => $newlyDeniedTotal]);

// Operational
$o1 = [];
foreach ($M['S0'] as $k => $s) {
    if (str_ends_with($k, '|pre') && ($s['legitDeniedBits'] !== $M['OAAA'][$k]['legitDeniedBits'] || $s['attackReachedBits'] !== $M['OAAA'][$k]['attackReachedBits'])) {
        $o1[] = $k;
    }
}
$gate('O1', 'operational', $o1 === [] ? 'PASS' : 'FAIL', 'every pre-only cell is request-for-request identical to S0', ['differing' => $o1]);
$o2 = [];
foreach ($M['S0'] as $k => $s) {
    $shape = explode('|', $k)[1];
    if (in_array($shape, ['api', 'api_custom', 'login-anon'], true) && ($s['legitDeniedBits'] !== $M['OAAA'][$k]['legitDeniedBits'] || $s['attackReachedBits'] !== $M['OAAA'][$k]['attackReachedBits'])) {
        $o2[] = $k;
    }
}
foreach (['A02.L1.pure-attacker', 'A02.L15.trusted-advisory', 'A02.L16.persisted-block', 'A02.L17.api-overuse', 'A02.L18.login-anon'] as $s) {
    if ($data['a02_lifecycle']['S0'][$s][0]['seq'] !== $data['a02_lifecycle']['OAAA'][$s][0]['seq']) {
        $o2[] = $s;
    }
}
$l1718 = $okOf('a02_lifecycle', 'OAAA', 'L17/L18');
$gate('O2', 'operational', $o2 === [] && $l1718 === true ? 'PASS' : 'FAIL', 'API_OVERUSE, custom API and account-less cells identical to S0; pure attacker, trusted advisory, persisted block, API and anon probes identical; no OAAA call for API / anon', ['differing' => $o2, 'noOaaaCall' => $l1718]);
$l19 = $data['a02_lifecycle']['S0']['A02.L19.no-capability'][0]['bits'] === $data['a02_lifecycle']['OAAA']['A02.L19.no-capability'][0]['bits'];
$gate('O3', 'operational', $l19 ? 'PASS' : 'FAIL', 'OAAA tree over a store WITHOUT the capability decides D2 / ABA_B / SP1n / OWN1 request-for-request like S0');
$o4 = ['a04 S1/S2' => $okOf('a04_failure_rollback_bounds', 'OAAA', 'S1/S2')];
if ($redisStatus === 'RAN') {
    $o4 += ['a05 Q2 S0' => $okOf('a05_redis', 'S0', 'Q2'), 'a05 Q2 OAAA' => $okOf('a05_redis', 'OAAA', 'Q2'), 'a06 T1 S0' => $okOf('a06_concurrency', 'S0', 'T1'), 'a06 T1 OAAA' => $okOf('a06_concurrency', 'OAAA', 'T1')];
}
$gate('O4', 'operational', in_array(false, $o4, true) ? 'FAIL' : ($redisStatus === 'RAN' ? 'PASS' : 'UNPROVEN'), 'bounded storage: credit sets <= 2, TTL <= 86 400 s, tuples <= grants, nothing alive after a day; every Redis key has a TTL', $o4);
// O5 rotation
$rot = [];
$r1 = [];
foreach (ARMS as $arm) {
    foreach ($data['a03_rotation_ipv6'][$arm]['A03.R1.rotation'] as $r) {
        $r1[$arm]["{$r['stream']}|{$r['mode']}"] = ['legit' => $r['legitDenied'] - $r['reference']['legitDenied'], 'attack' => $r['attackReachedVerification'] - $r['reference']['attackReachedVerification']];
    }
}
foreach ($r1['OAAA'] as $k => $d) {
    if ($d['legit'] > $r1['S0'][$k]['legit'] + 2 || $d['attack'] > $r1['S0'][$k]['attack']) {
        $rot[$k] = ['oaaa' => $d, 's0' => $r1['S0'][$k]];
    }
}
$o5asserts = ['R2' => $okOf('a03_rotation_ipv6', 'OAAA', 'R2'), 'R3' => $okOf('a03_rotation_ipv6', 'OAAA', 'R3'), 'H1' => $okOf('a03_rotation_ipv6', 'OAAA', 'H1'), 'H2' => $okOf('a03_rotation_ipv6', 'OAAA', 'H2')];
$gate('O5', 'operational', $rot === [] && ! in_array(false, $o5asserts, true) ? 'PASS' : 'FAIL', 'rotation: per stream and mode, the rotation effect on OAAA (rotated - unrotated) is <= the S0 effect + 2 legitimate denials and <= the S0 effect in attacker reach; R2 / R3 / H1 / H2 hold', ['violations' => $rot, 'asserts' => $o5asserts]);
$f1 = $data['a04_failure_rollback_bounds']['OAAA']['A04.F1.oaaa-operation-failure'][0];
$f2s = $data['a04_failure_rollback_bounds']['S0']['A04.F2a.store-outage'][0];
$f2o = $data['a04_failure_rollback_bounds']['OAAA']['A04.F2a.store-outage'][0];
$gate('O6', 'operational', 'UNPROVEN', 'DEC-015: the OAAA bound during a backend outage cannot be proven (DEGRADED_MODE allows without consulting any store)', ['oaaaOperationFailureDegradedAllows' => ['S0' => $data['a04_failure_rollback_bounds']['S0']['A04.F1.oaaa-operation-failure'][0]['degradedModeAllows'], 'OAAA' => $f1['degradedModeAllows']], 'wholeStoreOutageIdentical' => $f2s['decisions'] === $f2o['decisions'], 'wholeStoreOutageDegradedAllows' => $f2o['degradedModeAllows'], 'F1 assertion' => $okOf('a04_failure_rollback_bounds', 'OAAA', 'F1')]);
$o7a = ['R1' => $okOf('a04_failure_rollback_bounds', 'OAAA', 'R1'), 'R2' => $okOf('a04_failure_rollback_bounds', 'OAAA', 'R2')];
if ($redisStatus === 'RAN') {
    $o7a += ['Q4' => $okOf('a05_redis', 'OAAA', 'Q4'), 'Q5' => $okOf('a05_redis', 'OAAA', 'Q5')];
}
$gate('O7a', 'operational', in_array(false, $o7a, true) ? 'FAIL' : ($redisStatus === 'RAN' ? 'PASS' : 'UNPROVEN'), 'rollback / mixed version: the REAL S0 code runs on OAAA state without exception (reference store and Redis)', $o7a);
$r3 = $data['a04_failure_rollback_bounds']['OAAA']['A04.R3.s0-family-state-parity'][0]['histories'];
$gate('O7b', 'operational', $okOf('a04_failure_rollback_bounds', 'OAAA', 'R3') ? 'PASS' : 'FAIL', 'S0-family state parity after the same history (key for key)', ['identical' => array_map(static fn(array $h): bool => $h['identical'], $r3)]);
$r4 = $data['a04_failure_rollback_bounds']['OAAA']['A04.R4.rollback-decision-parity'][0]['cases'];
$gate('O7c', 'operational', $okOf('a04_failure_rollback_bounds', 'OAAA', 'R4') ? 'PASS' : 'FAIL', 'rollback decision parity: the REAL S0 code after the OAAA history decides like after an S0-only history', ['decisionsDiffer' => array_map(static fn(array $c): ?int => $c['decisionsDiffer'] ?? null, $r4)]);
$gate('O7d', 'operational', $okOf('a04_failure_rollback_bounds', 'OAAA', 'R5') ? 'PASS' : 'FAIL', 'roll-forward after rollback: no phantom refund, no reset');
if ($redisStatus === 'RAN') {
    $o8 = ['S0' => $okOf('a07_host_probe', 'S0', 'H0') && $okOf('a07_host_probe', 'S0', 'H1') && $okOf('a07_host_probe', 'S0', 'H2'), 'OAAA' => $okOf('a07_host_probe', 'OAAA', 'H0') && $okOf('a07_host_probe', 'OAAA', 'H1') && $okOf('a07_host_probe', 'OAAA', 'H2')];
    $gate('O8', 'operational', in_array(false, $o8, true) ? 'FAIL' : 'PASS', 'Host probe through the public Builder + RateLimiterInterface on Redis, identical adapter code on both arms', ['pre+out' => ['S0' => $data['a07_host_probe']['S0']['A07.H1.host-pre-out'][0], 'OAAA' => $data['a07_host_probe']['OAAA']['A07.H1.host-pre-out'][0]], 'preOnly' => ['S0' => $data['a07_host_probe']['S0']['A07.H2.host-pre-only'][0], 'OAAA' => $data['a07_host_probe']['OAAA']['A07.H2.host-pre-only'][0]]]);
} else {
    $gate('O8', 'operational', 'UNPROVEN', 'real Redis did not run');
}
$gate('O9', 'operational', 'PASS', 'provenance and evidence integrity (pinned runtimes, labels, catalogue, S0 = merged #115 golden on every common cell)');
// O10 public surface
$p1s = $data['a04_failure_rollback_bounds']['S0']['A04.P1.public-surface'][0]['surface'];
$p1o = $data['a04_failure_rollback_bounds']['OAAA']['A04.P1.public-surface'][0]['surface'];
$surfaceDiff = [];
foreach ($p1s as $class => $sv) {
    if ($class === 'experimentalInterface') {
        continue;
    }
    $added = array_values(array_diff($p1o[$class]['methods'], $sv['methods']));
    $removed = array_values(array_diff($sv['methods'], $p1o[$class]['methods']));
    $ifAdded = array_values(array_diff($p1o[$class]['interfaces'], $sv['interfaces']));
    if ($added !== [] || $removed !== [] || $ifAdded !== []) {
        $surfaceDiff[$class] = ['added' => $added, 'removed' => $removed, 'interfacesAdded' => $ifAdded];
    }
}
$redisClass = 'Maatify\\RateLimiter\\Repository\\Redis\\RedisFullCapabilityStore';
$expected = [$redisClass => ['added' => ['public completeAdmission(string $tupleKey,string $outcome,array $tierKeys,int $refundFailures,array $creditKeys,string $creditMember,int $creditCap,int $creditTtlSeconds)', 'public reserveAdmission(string $tupleKey,int $tupleTtlSeconds,array $tiers)'], 'removed' => [], 'interfacesAdded' => ['Maatify\\RateLimiter\\Repository\\OutcomeAdmissionStoreInterface']]];
$gate('O10', 'operational', $surfaceDiff === $expected && $p1s['experimentalInterface'] === null && $p1o['experimentalInterface'] !== null ? 'PASS' : 'FAIL', 'public surface: the patch adds only the experimental interface and its two methods on the Redis adapter; EvaluationPipeline, engine, command, context, result and Builder surfaces unchanged', ['diff' => $surfaceDiff]);

// Decision (pre-registered rule, SECURITY_ENVELOPE.md §3)
$st = array_column($gates, 'status', 'gate');
$forbidden = array_filter(['E4', 'E5', 'E6'], static fn(string $g): bool => $st[$g] === 'FAIL');
$provisional = array_filter(['E1', 'E2', 'E3'], static fn(string $g): bool => $st[$g] === 'FAIL');
$unproven = array_filter(['E1', 'E2', 'E3', 'E4', 'E5', 'E6'], static fn(string $g): bool => $st[$g] === 'UNPROVEN');
$decision = $forbidden !== [] ? 'NO-GO' : ($provisional !== [] ? 'NO-GO' : ($unproven !== [] ? 'UNPROVEN' : 'GO_FOR_OWNER_RISK_REVIEW'));
$gates[] = ['gate' => 'DECISION', 'group' => 'rule', 'status' => $decision, 'rule' => 'SECURITY_ENVELOPE.md §3', 'detail' => ['forbiddenFail' => array_values($forbidden), 'provisionalFail' => array_values($provisional), 'unproven' => array_values($unproven)]];
file_put_contents("$out/gates.jsonl", implode("\n", array_map(static fn(array $g): string => json_encode($g, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $gates)) . "\n");

// ---------------------------------------------------------------------------------------------------------------------------------------
// summary.md (generated; every number comes from the evidence above)
$md = [];
$md[] = '# #110 OAAA — generated summary';
$md[] = '';
$md[] = 'Generated by `report.php` from `expected/*.jsonl`. Do not edit by hand. Runtime levels: S0 = `S0_RUNTIME_OBSERVED` (repository tree, unpatched), OAAA = `CANDIDATE_EXPERIMENTAL_RUNTIME` (isolated patched tree). Redis stage: **' . $redisStatus . '**.';
$md[] = '';
$md[] = '## 1. Gates (pre-registered in SECURITY_ENVELOPE.md)';
$md[] = '';
$md[] = '| Gate | Group | Status | Rule |';
$md[] = '|---|---|---|---|';
foreach ($gates as $g) {
    $md[] = "| {$g['gate']} | {$g['group']} | **{$g['status']}** | " . str_replace('|', '/', $g['rule']) . ' |';
}
$md[] = '';
$md[] = 'E4 collapse cells: ' . ($collapse === [] ? 'none' : '`' . implode('`, `', $collapse) . '`') . '.';
$md[] = '';
$md[] = 'E3 violations (S0 = 0 cells): ' . ($e3bad === [] ? 'none' : implode(', ', array_map(static fn(string $k, array $c): string => "`$k` fixed600 " . max($c['scopeMax600'], $c['aggMax600']) . " / rolling600 {$c['r600']} / total {$c['scopeMaxTotal']}", array_keys($e3bad), $e3bad))) . '.';
$md[] = '';
$md[] = 'E1 worst per-scope delta in a fixed 600 s window: ' . ($gated === [] ? 0 : max(array_column($gated, 'scopeMax600'))) . ($e1bad === [] ? '' : '; violations: ' . implode(', ', array_map(static fn(string $k, array $c): string => "`$k` {$c['scopeMax600']}", array_keys($e1bad), $e1bad))) . '.';
$md[] = '';
$md[] = 'F2 fairness regressions: ' . ($regress === [] ? 'none' : implode(', ', array_map(static fn(string $k, string $v): string => "`$k` $v", array_keys($regress), $regress))) . "; legitimate requests newly denied over all non-API cells: $newlyDeniedTotal.";
$md[] = '';
$md[] = '## 2. Fairness per issue (Login; legitimate requests denied, S0 → OAAA)';
$md[] = '';
foreach ($fair as $issue => $rows) {
    $md[] = "### $issue";
    $md[] = '';
    $md[] = '| Stream | Flow | Legit requests | S0 denied | OAAA denied | Newly admitted | Newly denied |';
    $md[] = '|---|---|---:|---:|---:|---:|---:|';
    foreach ($rows as $r) {
        $md[] = "| {$r['stream']} | {$r['flow']} | {$r['attempts']} | {$r['s0']} | {$r['oaaa']} | {$r['newlyAdmitted']} | {$r['newlyDenied']} |";
    }
    $md[] = '';
}
$md[] = '## 3. Security: every attack cell (Login / OTP / Login-anon / Login-custom), reach of the Host verifier';
$md[] = '';
$md[] = 'Δ = OAAA − S0. Fixed windows from the stream start; rolling windows 60 s-aligned. "scope" = attacker IP (K1 = K2 scope of every modelled attacker). "extra while S0 blocks" = requests S0 denied that OAAA let reach the verifier. "failing" = the subset of attack requests whose Host outcome is a failure (post-hoc view, not a gate).';
$md[] = '';
$md[] = '| Cell | Class | Attempts | S0 | OAAA | Δ | Δ60 | Δ600 | Δ1800 | rolling Δ600 | rolling Δ1800 | max scope Δ600 | max scope total | scopes | extra while S0 blocks | S0 failing | OAAA failing | attack grants |';
$md[] = '|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|';
foreach ($sec as $k => $c) {
    if ($c['flow'] === 'out' && $c['delta'] === 0) {
        continue;
    }
    $md[] = "| `$k` | {$c['class']} | {$c['attempts']} | {$c['s0']} | {$c['oaaa']} | " . ($c['delta'] > 0 ? '+' : '') . "{$c['delta']} | {$c['d60']} | {$c['d600']} | {$c['d1800']} | {$c['r600']} | {$c['r1800']} | {$c['scopeMax600']} | {$c['scopeMaxTotal']} | {$c['scopes']} | {$c['extraWhileS0Blocks']} | {$c['s0Failing']} | {$c['oaaaFailing']} | {$c['attackGrants']} |";
}
$md[] = '';
$unchanged = count(array_filter($sec, static fn(array $c): bool => $c['flow'] === 'out' && $c['delta'] === 0));
$md[] = "Record-only (`out`) attack cells with Δ = 0 omitted above: $unchanged (every attempt reaches the verifier before the library on both arms).";
$md[] = '';
$posthoc = array_keys(array_filter($gated, static fn(array $c): bool => $c['s0Failing'] < $c['failingAttempts'] && $c['oaaaFailing'] === $c['failingAttempts'] && $c['failingAttempts'] > 0));
$md[] = '**Post-hoc view (not a gate):** cells where every FAILING attack attempt reaches the verifier on OAAA but not on S0: ' . ($posthoc === [] ? 'none' : '`' . implode('`, `', $posthoc) . '`') . '. Worst Δ restricted to failing attempts: ' . ($gated === [] ? 0 : max(array_map(static fn(array $c): int => $c['oaaaFailing'] - $c['s0Failing'], $gated))) . '.';
$md[] = '';
$md[] = '## 4. Lifecycle, concurrency, rotation, failure, rollback (key numbers)';
$md[] = '';
foreach (['a02_lifecycle', 'a03_rotation_ipv6', 'a04_failure_rollback_bounds', 'a05_redis', 'a06_concurrency', 'a07_host_probe'] as $f) {
    if (in_array($f, REDIS_FILES, true) && $redisStatus !== 'RAN') {
        $md[] = "* `$f`: not run (Redis SKIPPED) — UNPROVEN";
        continue;
    }
    $md[] = "### `$f`";
    $md[] = '';
    $md[] = '| Assertion | S0 | OAAA |';
    $md[] = '|---|---|---|';
    $ids = array_unique(array_merge(array_keys(REQUIRED[$f]['S0']), array_keys(REQUIRED[$f]['OAAA'])));
    foreach ($ids as $id) {
        $txt = ($rowsFor($f, 'OAAA', $id)[0]['assert'] ?? ($rowsFor($f, 'S0', $id)[0]['assert'] ?? $id));
        $v0 = $okOf($f, 'S0', $id);
        $v1 = $okOf($f, 'OAAA', $id);
        $md[] = '| ' . str_replace('|', '/', $txt) . ' | ' . ($v0 === null ? '—' : ($v0 ? 'ok' : '**FAIL**')) . ' | ' . ($v1 === null ? '—' : ($v1 ? 'ok' : '**FAIL**')) . ' |';
    }
    $md[] = '';
}
$md[] = '### Measured data rows (selected)';
$md[] = '';
$md[] = '* F1 OAAA-operation failure, DEGRADED_MODE ALLOWs: S0 ' . $data['a04_failure_rollback_bounds']['S0']['A04.F1.oaaa-operation-failure'][0]['degradedModeAllows'] . ', OAAA ' . $f1['degradedModeAllows'] . '. F2a whole correlation-store outage (from t=2): identical on both arms = ' . ($f2s['decisions'] === $f2o['decisions'] ? 'yes' : 'no') . ', DEGRADED_MODE ALLOWs ' . $f2o['degradedModeAllows'] . '.';
$md[] = '* R3 S0-family state parity: ' . implode(', ', array_map(static fn(string $h, array $x): string => "$h " . ($x['identical'] ? 'identical' : "+{$x['onlyOnOaaa']} keys only on OAAA / {$x['onlyOnS0']} only on S0 / {$x['sameKeyDifferentValue']} differing values"), array_keys($r3), $r3)) . '.';
$md[] = '* R4 decision parity (requests decided differently by the REAL S0 code after the OAAA history): ' . implode(', ', array_map(static fn(string $h, array $x): string => "$h: " . ($x['decisionsDiffer'] ?? ('mixed reach ' . $x['mixed']['attackReached'] . ' vs ' . $x['s0OnlyHistory']['attackReached'] . ', mixed legit denied ' . $x['mixed']['legitDenied'] . ' vs ' . $x['s0OnlyHistory']['legitDenied'])), array_keys($r4), $r4)) . '.';
if ($redisStatus === 'RAN') {
    $md[] = '* A06.C5 successes racing pre-checks (racy raw counts not recorded): invariants S0 ' . json_encode($data['a06_concurrency']['S0']['A06.C5.successes-racing-prechecks'][0]['invariants']) . ', OAAA ' . json_encode($data['a06_concurrency']['OAAA']['A06.C5.successes-racing-prechecks'][0]['invariants']) . '.';
    foreach (['A06.C1.burst-one-unit-left', 'A06.C2.burst-no-unit-left', 'A06.C3.k1-k2-all-or-nothing', 'A06.C4.global-tier-burst', 'A06.C8.rotation-active-reservations'] as $s) {
        $md[] = "* $s admitted per round: S0 " . json_encode(array_column($data['a06_concurrency']['S0'][$s][0]['rounds'], array_key_exists('admitted', $data['a06_concurrency']['S0'][$s][0]['rounds'][0]) ? 'admitted' : 'admittedAfterRotation')) . ', OAAA ' . json_encode(array_column($data['a06_concurrency']['OAAA'][$s][0]['rounds'], array_key_exists('admitted', $data['a06_concurrency']['OAAA'][$s][0]['rounds'][0]) ? 'admitted' : 'admittedAfterRotation')) . '.';
    }
    $h = $data['a07_host_probe'];
    $md[] = '* Host probe pre+out: office legit denied S0 ' . $h['S0']['A07.H1.host-pre-out'][0]['legitDenied'] . ' / OAAA ' . $h['OAAA']['A07.H1.host-pre-out'][0]['legitDenied'] . ' of 40; co-located sprayer verified S0 ' . $h['S0']['A07.H1.host-pre-out'][0]['attackVerified'] . ' / OAAA ' . $h['OAAA']['A07.H1.host-pre-out'][0]['attackVerified'] . ' of 20; pre-only Host: identical (' . json_encode($h['S0']['A07.H2.host-pre-only'][0]['legitDenied'] === $h['OAAA']['A07.H2.host-pre-only'][0]['legitDenied'] && $h['S0']['A07.H2.host-pre-only'][0]['attackVerified'] === $h['OAAA']['A07.H2.host-pre-only'][0]['attackVerified']) . ').';
}
$md[] = '';
$md[] = "## 5. Decision (pre-registered rule): **$decision**";
$md[] = '';
$md[] = 'Forbidden-row FAILs: ' . ($forbidden === [] ? 'none' : implode(', ', $forbidden)) . '. Provisional-row FAILs: ' . ($provisional === [] ? 'none' : implode(', ', $provisional)) . '. UNPROVEN security rows: ' . ($unproven === [] ? 'none' : implode(', ', $unproven)) . '.';
file_put_contents("$out/summary.md", implode("\n", $md) . "\n");
fwrite(STDOUT, "decision: $decision\n");
exit(0);
