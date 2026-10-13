<?php

declare(strict_types=1);

// #110 Guarded Network Headroom — report generator + fail-closed evidence gates.
//   php report.php <evidence-dir> <out-dir>
// Reads the per-arm scenario outputs, VALIDATES that the required evidence exists and is well-formed, then writes gates.jsonl and summary.md.
// Any missing / empty / malformed / mislabelled evidence writes evidence_problems.txt, marks EVERY gate UNPROVEN and exits 5 (never a PASS).
// A candidate assertion recorded with ok:false, or a measured bound that does not hold, is a FINDING (gate FAIL), not an evidence problem.
// No timestamps, no randomness.
require __DIR__ . '/lib/cells.php';

$in = $argv[1] ?? '';
$out = $argv[2] ?? '';
if (! is_dir($in) || ! is_dir($out)) {
    fwrite(STDERR, "usage: report.php <evidence-dir> <out-dir>\n");
    exit(2);
}
const ARMS = GNH_ARMS_LIST;
const CAND = GNH_CANDIDATE_ARMS;
const FILES = ['g01_matrix', 'g02_guard', 'g03_rotation_ipv6', 'g04_bounds_rollback'];
const BASE_SHA = '1ad9af289f83f86346667a014dd486f9c4b8fc08';
const PINNED_S0 = '4b259d3397adf492d9767572141c9bdf5efc5744886bce63592ae9f36b95f4fc';
const PINNED_CAND = 'd7413b9dc497a45d41dcf5f522b5e092e3a957149fad173a010d094fe80d657a';
const PATCH_FILE = __DIR__ . '/patches/gnh-candidates.patch';
const PATCH_SHA256 = 'c01611bc60bf18dc5b11040f85bc08d6497dd4bfd211556e216b3604a98cf6cf';

/** Required assertions per file: identifier (text before the first space after it) => rows, for S0 and for a candidate arm. */
const REQUIRED = [
    'g01_matrix' => ['S0' => ['S0 D2' => 1, 'S0 SP1n/SP3/SP6' => 1, 'S0 L1' => 1, 'S0 ABA_A' => 1, 'S0 TMSd' => 1], 'cand' => []],
    'g02_guard' => ['S0' => ['B1' => 1, 'B2' => 1, 'B3' => 1, 'B4' => 1, 'B5' => 1, 'B6' => 1, 'B7' => 1], 'cand' => ['B1' => 1, 'B2' => 1, 'B3' => 1, 'B4' => 1, 'B5' => 1, 'B6' => 1, 'B7' => 1]],
    'g03_rotation_ipv6' => ['S0' => ['R1' => 9, 'R2' => 12, 'R3' => 1, 'R4' => 1, 'H1' => 1, 'H2' => 1, 'H2b' => 1], 'cand' => ['R1' => 9, 'R2' => 12, 'R3' => 1, 'R4' => 1, 'H1' => 1, 'H2' => 1, 'H2b' => 1]],
    'g04_bounds_rollback' => ['S0' => ['S1' => 1, 'S2' => 1, 'F1' => 1, 'R1' => 1, 'R2' => 1], 'cand' => ['S1' => 1, 'S2' => 1, 'F1' => 1, 'R1' => 1, 'R2' => 1]],
    'g05_redis' => ['S0' => ['P1' => 1, 'P1b' => 1, 'P2' => 1, 'P3' => 1, 'P4' => 1], 'cand' => ['P1' => 1, 'P1b' => 1, 'P2' => 1, 'P3' => 1, 'P4' => 1]],
    'g06_concurrency' => ['S0' => ['C1' => 2, 'C2' => 2, 'C3' => 2, 'C4' => 2, 'T1' => 1], 'cand' => ['C1' => 2, 'C2' => 2, 'C3' => 2, 'C4' => 2, 'T1' => 1]],
];
/** Data rows each file must carry per arm ('*' = every arm, 'S0' / 'cand' = only those). */
const DATA_ROWS = [
    'g02_guard' => ['*' => ['G02.B1.spray-budget', 'G02.B2.window-boundary', 'G02.B3.churn-budget-flows', 'G02.B5.nat-exhaustion', 'G02.B6.boundaries', 'G02.B7.dec003-cycles']],
    'g03_rotation_ipv6' => ['*' => ['G03.R.rotation', 'G03.R3.budget-rotation', 'G03.R4.outer-only-single-device', 'G03.H.ipv6']],
    'g04_bounds_rollback' => ['*' => ['G04.S1.per-scope-storage', 'G04.S2.ttl-bounds', 'G04.F1.budget-backend-failure'], 'S0' => ['G04.R.reference'], 'cand' => ['G04.R1.rollback', 'G04.R2.mixed-version']],
    'g05_redis' => ['*' => ['G05.P1.parity', 'G05.P2.ttl-hygiene'], 'S0' => ['G05.P.reference'], 'cand' => ['G05.P3.rollback-redis', 'G05.P4.mixed-version-redis']],
    'g06_concurrency' => ['*' => ['G06.C1.k1-boundary-burst', 'G06.C2.k2-boundary-burst', 'G06.C3.k1-fresh-burst', 'G06.C4.two-budget-burst', 'G06.T1.ttl-hygiene']],
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
    $files[] = 'g05_redis';
    $files[] = 'g06_concurrency';
}
foreach ($files as $f) {
    foreach (ARMS as $arm) {
        $load($f, $arm);
    }
}
if (! is_file(PATCH_FILE) || hash_file('sha256', PATCH_FILE) !== PATCH_SHA256) {
    $problems[] = 'patches/gnh-candidates.patch missing or not the pinned candidate patch';
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
        foreach ($data[$f][$arm] ?? [] as $s => $rows) {
            foreach ($rows as $r) {
                if (array_key_exists('arm', $r) && $r['arm'] !== $arm) {
                    $problems[] = "$f.$arm: data row $s carries arm '{$r['arm']}'";
                }
            }
        }
    }
}
// Required assertions: each id present exactly the expected number of times; no unknown rows. (ok:false is a finding, handled by the gates.)
foreach (REQUIRED as $f => $sets) {
    if (in_array($f, ['g05_redis', 'g06_concurrency'], true) && $redisStatus !== 'RAN') {
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
        // S0 assertions are baseline invariants: a failed one means this study is not measuring the shipped runtime.
        if ($arm === 'S0') {
            foreach ($assertions[$f]['S0'] ?? [] as $a) {
                if ($a['ok'] !== true) {
                    $problems[] = "$f.S0: baseline invariant failed: " . $a['assert'];
                }
            }
        }
    }
}
// Data rows.
foreach (DATA_ROWS as $f => $sets) {
    if (in_array($f, ['g05_redis', 'g06_concurrency'], true) && $redisStatus !== 'RAN') {
        continue;
    }
    foreach (ARMS as $arm) {
        foreach (array_merge($sets['*'] ?? [], $sets[$arm === 'S0' ? 'S0' : 'cand'] ?? []) as $k) {
            if (($data[$f][$arm][$k] ?? []) === []) {
                $problems[] = "$f.$arm: missing data rows $k";
            }
        }
    }
}
// Matrix completeness + integer fields, per arm.
$cells = [];
$expectedCells = [];
foreach (gnhMatrixCells() as [$id, $shape, $flow]) {
    $expectedCells["$id|$shape|$flow"] = true;
}
foreach (ARMS as $arm) {
    foreach ($data['g01_matrix'][$arm]['G01.matrix'] ?? [] as $r) {
        $key = ($r['stream'] ?? '?') . '|' . ($r['shape'] ?? '?') . '|' . ($r['flow'] ?? '?');
        if (! isset($expectedCells[$key])) {
            $problems[] = "g01_matrix.$arm: unknown matrix cell $key";
        } elseif (isset($cells[$arm][$key])) {
            $problems[] = "g01_matrix.$arm: duplicate matrix cell $key";
        }
        if (($r['level'] ?? '') !== ($arm === 'S0' ? 'S0_RUNTIME_OBSERVED' : 'CANDIDATE_EXPERIMENTAL_RUNTIME')) {
            $problems[] = "g01_matrix.$arm: cell $key carries the wrong runtime level";
        }
        $cells[$arm][$key] ??= $r;
    }
    foreach (gnhMatrixCells() as [$id, $shape, $flow]) {
        $r = $cells[$arm]["$id|$shape|$flow"] ?? null;
        if ($r === null) {
            $problems[] = "g01_matrix.$arm: missing cell $id / $shape / $flow";
            continue;
        }
        foreach (gnhCellFields() as $field) {
            $v = $r[$field] ?? null;
            if (! is_int($v) || $v < 0) {
                $problems[] = "g01_matrix.$arm: cell $id/$shape/$flow field $field missing or not a non-negative integer";
            }
        }
        foreach (['decisions', 'blockIssuancesByScope', 'legitDeniedBySource', 'attackDeniedBySource', 'denialRetryAfter'] as $field) {
            if (! is_array($r[$field] ?? null)) {
                $problems[] = "g01_matrix.$arm: cell $id/$shape/$flow field $field missing";
            }
        }
        if (is_int($r['legitDenied'] ?? null) && $r['legitDenied'] > ($r['legitAttempts'] ?? -1)) {
            $problems[] = "g01_matrix.$arm: cell $id/$shape/$flow denied > attempts";
        }
        if (is_int($r['attackReachedVerification'] ?? null) && $r['attackReachedVerification'] > ($r['attackAttempts'] ?? -1)) {
            $problems[] = "g01_matrix.$arm: cell $id/$shape/$flow reached > attempts";
        }
        if (is_int($r['requests'] ?? null) && $r['requests'] !== ($r['legitAttempts'] ?? -1) + ($r['attackAttempts'] ?? -1)) {
            $problems[] = "g01_matrix.$arm: cell $id/$shape/$flow requests != legit + attack attempts";
        }
        if ($arm === 'S0' && (($r['headroomAdmissions'] ?? -1) !== 0 || ($r['budgetMax'] ?? -1) !== 0)) {
            $problems[] = "g01_matrix.S0: cell $id/$shape/$flow reports a headroom budget on the unpatched runtime";
        }
    }
}

// ------------------------------------------------------------------------------------------------------------------------------------
// Gates. Evidence problems => every gate UNPROVEN (exit 5). Otherwise PASS / FAIL (finding) / UNPROVEN (Redis skipped) /
// OWNER_DECISION_REQUIRED (a measured trade-off that no engineering gate can accept).
// ------------------------------------------------------------------------------------------------------------------------------------
$cell = static fn(string $arm, string $id, string $shape, string $flow): ?array => $cells[$arm]["$id|$shape|$flow"] ?? null;
$assertGate = static function (array $need, string $arm) use ($rowsFor, $redisStatus): string {
    $status = 'PASS';
    foreach ($need as $f => $ids) {
        if (in_array($f, ['g05_redis', 'g06_concurrency'], true) && $redisStatus !== 'RAN') {
            return 'UNPROVEN';
        }
        foreach ($ids as $i) {
            $rows = $rowsFor($f, $arm, $i);
            if ($rows === []) {
                $status = 'UNPROVEN';
            }
            foreach ($rows as $a) {
                if ($a['ok'] !== true && $status === 'PASS') {
                    $status = 'FAIL';
                }
            }
        }
    }

    return $status;
};
$failedAsserts = static function (array $need, string $arm) use ($rowsFor): array {
    $o = [];
    foreach ($need as $f => $ids) {
        foreach ($ids as $i) {
            foreach ($rowsFor($f, $arm, $i) as $a) {
                if ($a['ok'] !== true) {
                    $o[] = "$f: " . $a['assert'];
                }
            }
        }
    }

    return $o;
};
$classes = gnhStreamClasses();
$isAttack = static fn(string $id): bool => in_array($classes[$id], ['attack', 'evasion', 'both', 'poisoning'], true);
$isLegit = static fn(string $id): bool => in_array($classes[$id], ['benign', 'both', 'poisoning'], true);
$gates = [];
$g = static function (string $id, string $arm, string $title, string $status, array $detail = []) use (&$gates): void {
    $gates[] = ['gate' => $id, 'arm' => $arm, 'title' => $title, 'status' => $status] + ($detail === [] ? [] : ['detail' => $detail]);
};
// Static contract check on the committed patch (FEASIBILITY evidence): no public / interface surface, no new store call.
$patchAdded = is_file(PATCH_FILE) ? array_values(array_filter(file(PATCH_FILE, FILE_IGNORE_NEW_LINES), static fn(string $l): bool => str_starts_with($l, '+') && ! str_starts_with($l, '+++'))) : [];
$contractHits = array_values(array_filter($patchAdded, static fn(string $l): bool => preg_match('/\b(public|protected)\s+(static\s+)?function\b|\binterface\b|\$this->(store|correlationStore)->|\bnew\s+\\\\?Maatify\\\\RateLimiter\\\\Repository/', $l) === 1));
$patchCalls = [];
foreach ($patchAdded as $l) {
    if (preg_match_all('/\$this->([A-Za-z]+)\(/', $l, $mm) > 0) {
        foreach ($mm[1] as $m) {
            $patchCalls[$m] = true;
        }
    }
}
ksort($patchCalls);
$trade = [];
if ($problems === []) {
    $g('E0', 'S0', 'Baseline reproduction: the published S0 numbers of PR #103/#104/#105/#108/#111/#113 reproduced on the real pipeline through this replay', $assertGate(['g01_matrix' => ['S0 D2', 'S0 SP1n/SP3/SP6', 'S0 L1', 'S0 ABA_A', 'S0 TMSd']], 'S0'));
    foreach (CAND as $arm) {
        // G1a — the per-scope bound of the zone between the S0 trigger and the x5 trigger, judged from the MEASURED admissions (not from the
        // arm's own assertion texts): sequential exactness, the sliding-window boundary, rotation, concurrency at the boundary, budget failure.
        $B = GNH_BUDGET_PER_RULE;
        $d2 = $data['g02_guard'][$arm];
        $b1 = $d2['G02.B1.spray-budget'][0];
        $b2 = $d2['G02.B2.window-boundary'][0];
        $b3 = $d2['G02.B3.churn-budget-flows'][0]['flows'];
        $r3 = $data['g03_rotation_ipv6'][$arm]['G03.R3.budget-rotation'][0];
        $bound = [];
        if ($b1['allowed'] > 4 + $B['spray']) {
            $bound[] = 'K1 zone (B1): ' . $b1['allowed'] . ' of 65 checkOnly requests allowed > 4 + B';
        }
        if ($b3['pre']['allowedAttempts'] > 2 + $B['churn']) {
            $bound[] = 'K2 zone (B3): ' . $b3['pre']['allowedAttempts'] . ' of 41 allowed > 2 + B';
        }
        if ($b2['maxAllowedInSliding600'] > 2 * (4 + $B['spray'])) {
            $bound[] = 'sliding 600 s (B2): ' . $b2['maxAllowedInSliding600'] . ' allowed > 2(4 + B)';
        }
        if ($r3['afterAllowed'] > $B['spray']) {
            $bound[] = 'after rotation (R3): ' . $r3['afterAllowed'] . ' of 40 allowed > B';
        }
        if ($redisStatus === 'RAN') {
            foreach ($data['g06_concurrency'][$arm]['G06.C1.k1-boundary-burst'][0]['rounds'] as $i => $r) {
                if ($r['allowedInBurst'] > 6) {
                    $bound[] = 'C1 round ' . ($i + 1) . ': ' . $r['allowedInBurst'] . ' of 16 simultaneous in-zone requests allowed > 6 left';
                }
            }
            foreach ($data['g06_concurrency'][$arm]['G06.C2.k2-boundary-burst'][0]['rounds'] as $i => $r) {
                if ($r['allowedInBurst'] > 4) {
                    $bound[] = 'C2 round ' . ($i + 1) . ': ' . $r['allowedInBurst'] . ' of 8 simultaneous in-zone requests allowed > 4 left';
                }
            }
        }
        $need = ['g02_guard' => ['B1', 'B2', 'B3', 'B4', 'B6'], 'g03_rotation_ipv6' => ['R3'], 'g04_bounds_rollback' => ['F1'], 'g06_concurrency' => ['C1', 'C2', 'C3', 'C4']];
        $asserted = $assertGate($need, $arm);
        $g1a = $bound !== [] ? 'FAIL' : $asserted;
        $g('G1a', $arm, 'Bounded Security — per-scope admission bound of the zone between the S0 trigger and the x5 trigger, from measured admissions: <= B per fixed window (sequential), <= 2(4 + B) in a sliding 600 s, carried across rotation, <= the remaining budget under simultaneous requests at the boundary, fail-closed on budget failure', $g1a, $bound !== [] ? $bound : $failedAsserts($need, $arm));
        // G1b — no full collapse: no attack/evasion/mixed/poisoning cell (pre+out or checkOnly-only, every shape) where this arm lets EVERY attempt reach
        // verification while S0 did not.
        $collapse = [];
        foreach (gnhMatrixCells() as [$id, $shape, $flow]) {
            if ($flow === 'out' || ! $isAttack($id)) {
                continue;
            }
            $a = $cell($arm, $id, $shape, $flow);
            $s = $cell('S0', $id, $shape, $flow);
            if ($a['attackAttempts'] > 0 && $a['attackReachedVerification'] === $a['attackAttempts'] && $s['attackReachedVerification'] < $s['attackAttempts']) {
                $collapse[] = "$id/$shape/$flow " . $s['attackReachedVerification'] . '->' . $a['attackReachedVerification'] . '/' . $a['attackAttempts'];
            }
        }
        $g('G1b', $arm, 'Bounded Security — no full collapse: no attack cell (pre+out or checkOnly-only, any shape) where every attempt reaches verification on this arm but not on S0', $collapse === [] ? 'PASS' : 'FAIL', $collapse);
        // G2 — trade-off vs S0 (never a GO by ratio).
        $over5 = [];
        $s0zero = [];
        $full = [];
        $maxAbs = ['delta' => 0, 'cell' => ''];
        $maxWin = ['delta' => 0, 'cell' => ''];
        foreach (gnhMatrixCells() as [$id, $shape, $flow]) {
            if ($flow === 'out' || ! $isAttack($id)) {
                continue;
            }
            $a = $cell($arm, $id, $shape, $flow);
            $s = $cell('S0', $id, $shape, $flow);
            $ra = $a['attackReachedVerification'];
            $rs = $s['attackReachedVerification'];
            if ($a['attackAttempts'] === 0) {
                continue;
            }
            if ($ra > 5 * $rs && $rs > 0) {
                $over5[] = "$id/$shape/$flow $rs->$ra";
            }
            if ($rs === 0 && $ra > 0) {
                $s0zero[] = "$id/$shape/$flow 0->$ra";
            }
            if ($ra === $a['attackAttempts']) {
                $full[] = "$id/$shape/$flow $ra/" . $a['attackAttempts'] . " (S0 $rs)";
            }
            if ($ra - $rs > $maxAbs['delta']) {
                $maxAbs = ['delta' => $ra - $rs, 'cell' => "$id/$shape/$flow"];
            }
            if ($a['attackReachedMaxPer600'] - $s['attackReachedMaxPer600'] > $maxWin['delta']) {
                $maxWin = ['delta' => $a['attackReachedMaxPer600'] - $s['attackReachedMaxPer600'], 'cell' => "$id/$shape/$flow"];
            }
        }
        $trade[$arm] = ['over5xS0' => $over5, 's0ZeroNowPositive' => $s0zero, 'fullReach' => $full, 'maxAbsoluteIncrease' => $maxAbs, 'maxIncreasePer600Window' => $maxWin];
        $g('G2', $arm, 'Security trade-off vs S0 (indicative 5xS0, S0=0 cells, full-reach cells, absolute and per-600 s increase) — measured, not acceptable by ratio', 'OWNER_DECISION_REQUIRED', ['cellsAbove5xS0' => count($over5), 'cellsS0ZeroNowReached' => count($s0zero), 'cellsFullReach' => count($full), 'maxAbsoluteIncrease' => $maxAbs, 'maxIncreasePer600Window' => $maxWin]);
        // G3 — fairness: never more denials than S0 (every shape and flow), measured benefit on the named fairness cells, NAT exhaustion.
        $worse = [];
        $better = 0;
        $legitCells = 0;
        foreach (gnhMatrixCells() as [$id, $shape, $flow]) {
            if (! $isLegit($id)) {
                continue;
            }
            $a = $cell($arm, $id, $shape, $flow);
            $s = $cell('S0', $id, $shape, $flow);
            if ($s['legitAttempts'] === 0) {
                continue;
            }
            $legitCells++;
            if ($a['legitDenied'] > $s['legitDenied']) {
                $worse[] = "$id/$shape/$flow " . $s['legitDenied'] . '->' . $a['legitDenied'];
            } elseif ($a['legitDenied'] < $s['legitDenied']) {
                $better++;
            }
        }
        $g('G3a', $arm, 'Fairness non-regression: no cell with legitimate traffic (any shape, any flow) has more legitimate denials than S0', $worse === [] ? 'PASS' : 'FAIL', $worse === [] ? ['cellsWithLegitTraffic' => $legitCells, 'cellsImproved' => $better] : $worse);
        $s0D = 0;
        $armD = 0;
        foreach (gnhTaskFairnessStreams() as $id) {
            $s0D += $cell('S0', $id, 'login', 'pre+out')['legitDenied'];
            $armD += $cell($arm, $id, 'login', 'pre+out')['legitDenied'];
        }
        $g('G3b', $arm, 'Fairness benefit on the task fairness cells (Login pre+out, summed legitimate denials) and the one-party NAT-exhaustion probe (G02 B5)', 'OWNER_DECISION_REQUIRED', ['sumLegitDeniedS0' => $s0D, 'sumLegitDeniedArm' => $armD, 'natExhaustionProbe' => $assertGate(['g02_guard' => ['B5']], $arm)]);
        // G4 — operational correctness.
        $g('G4a', $arm, 'Real Redis: decision parity with the reference store, the zone admissions on the official adapter, TTL hygiene and budget flags', $assertGate(['g05_redis' => ['P1', 'P1b', 'P2']], $arm));
        $g('G4b', $arm, 'Concurrency on real Redis: simultaneous requests at the budget boundary, a fresh-scope burst, two budgets on the same requests, TTL hygiene', $assertGate(['g06_concurrency' => ['C1', 'C2', 'C3', 'C4', 'T1']], $arm), $failedAsserts(['g06_concurrency' => ['C1', 'C2', 'C3', 'C4', 'T1']], $arm));
        $g('G4c', $arm, 'Current/Previous rotation (3 modes on 7 streams), budget across rotation, IPv6 macro scopes', $assertGate(['g03_rotation_ipv6' => ['R1', 'R2', 'R3', 'R4', 'H1', 'H2', 'H2b']], $arm), $failedAsserts(['g03_rotation_ipv6' => ['R1', 'R2', 'R3', 'R4', 'H1', 'H2', 'H2b']], $arm));
        $g('G4d', $arm, 'Rollback mid-window and mixed version with the REAL S0 code (reference store and official Redis store)', $assertGate(['g04_bounds_rollback' => ['R1', 'R2'], 'g05_redis' => ['P3', 'P4']], $arm), $failedAsserts(['g04_bounds_rollback' => ['R1', 'R2'], 'g05_redis' => ['P3', 'P4']], $arm));
        $g('G4e', $arm, 'Storage: keys per in-zone scope, TTL bounds (every short-TTL key gone 1801 s after a stream, no new long-TTL key), set size <= 30', $assertGate(['g04_bounds_rollback' => ['S1', 'S2']], $arm), $failedAsserts(['g04_bounds_rollback' => ['S1', 'S2']], $arm));
        $g('G4f', $arm, 'Backend failure of the budget operation through the REAL RateLimiterEngine (typed failure, policy failure mode, DEC-015 degraded path) and DEC-003 cycles', $assertGate(['g04_bounds_rollback' => ['F1'], 'g02_guard' => ['B7']], $arm));
    }
    $g('G4g', 'TH5+GNH5', 'Contract surface of the committed patch: no public / protected method, no interface, no new store call — only private methods over the existing addBoundedCorrelation / incrementWatchAcrossRotation capabilities', $contractHits === [] ? 'PASS' : 'FAIL', ['privateCallsAdded' => array_keys($patchCalls), 'contractHits' => $contractHits]);
} else {
    foreach (['E0', 'G1a', 'G1b', 'G2', 'G3a', 'G3b', 'G4a', 'G4b', 'G4c', 'G4d', 'G4e', 'G4f', 'G4g'] as $id) {
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
        $md[] = '| ' . implode(' | ', array_map('strval', $r)) . ' |';
    }
    $md[] = '';
};
$ld = static fn(string $arm, string $id, string $shape, string $flow): string => $cell($arm, $id, $shape, $flow)['legitDenied'] . '/' . $cell($arm, $id, $shape, $flow)['legitAttempts'];
$ar = static fn(string $arm, string $id, string $shape, string $flow): string => $cell($arm, $id, $shape, $flow)['attackReachedVerification'] . '/' . $cell($arm, $id, $shape, $flow)['attackAttempts'];
$ad = static fn(string $arm, string $id, string $shape, string $flow): string => $cell($arm, $id, $shape, $flow)['attackDenied'] . '/' . $cell($arm, $id, $shape, $flow)['attackAttempts'];
$src = static function (array $bySrc): string {
    if ($bySrc === []) {
        return '—';
    }
    arsort($bySrc);
    $o = [];
    foreach (array_slice($bySrc, 0, 2, true) as $k => $v) {
        $o[] = "`$k` ×$v";
    }

    return implode('<br>', $o);
};
$flagCell = static function (string $id, string $shape, string $flow, string $arm) use ($cell): string {
    $a = $cell($arm, $id, $shape, $flow);
    $s = $cell('S0', $id, $shape, $flow);
    $f = [];
    if ($s['attackReachedVerification'] === 0 && $a['attackReachedVerification'] > 0) {
        $f[] = 'S0=0';
    }
    if ($s['attackReachedVerification'] > 0 && $a['attackReachedVerification'] > 5 * $s['attackReachedVerification']) {
        $f[] = '>5×S0';
    }
    if ($a['attackReachedVerification'] === $a['attackAttempts'] && $a['attackAttempts'] > 0) {
        $f[] = $s['attackReachedVerification'] === $s['attackAttempts'] ? 'full (S0 full)' : '**FULL**';
    }

    return $f === [] ? '' : implode(' ', $f);
};

$h('# #110 Guarded Network Headroom — S0 vs TH5 vs GNH5 (generated; do not edit)');
$h('');
$h('Generated by `report.php` from `results/actual/*.jsonl`. **S0** = the unpatched `EvaluationPipeline` (sha-256 `' . substr(PINNED_S0, 0, 8) . '…`, baseline `' . substr(BASE_SHA, 0, 7) . '`), level S0_RUNTIME_OBSERVED. **TH5 / GNH5** = the isolated patched tree (sha-256 `' . substr(PINNED_CAND, 0, 8) . '…`, `patches/gnh-candidates.patch` sha-256 `' . substr(PATCH_SHA256, 0, 8) . '…`), level CANDIDATE_EXPERIMENTAL_RUNTIME. Counts are requests (not people). Flows: `pre+out` = checkOnly then the outcome if allowed; `pre` = checkOnly only; `out` = record-only. GNH5 budget B per scope per fixed 600 s window: spray ' . GNH_BUDGET_PER_RULE['spray'] . ', churn ' . GNH_BUDGET_PER_RULE['churn'] . ', dilution ' . GNH_BUDGET_PER_RULE['dilution'] . ' evaluated commands (experimental parameters). Nothing below selects, approves or recommends a policy.');
$h('');
$h('## 1. Gates');
$h('');
$table(['Gate', 'Arm', 'What it checks', 'Status', 'Detail'], array_map(static fn(array $x): array => [$x['gate'], $x['arm'], $x['title'], '**' . $x['status'] . '**', isset($x['detail']) ? '`' . str_replace('|', '\\|', json_encode($x['detail'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '`' : ''], $gates));

$h('## 2. Protection — attacker attempts that reached verification (Login, pre+out)');
$h('');
$h('`max/600` = the most attacker verifications inside one fixed 600 s window of the stream (S0 / TH5 / GNH5). Flags: `S0=0` S0 admitted none; `>5×S0`; `**FULL**` every attempt reached verification while S0 did not.');
$h('');
$rows = [];
$prot = array_values(array_unique(array_merge(gnhTaskProtectionStreams(), ['X3', 'X5', 'Y2b', 'Y2c', 'Y2c2', 'TMR', 'TMX', 'TMU', 'XN1', 'XN1n', 'XN1k', 'MIX', 'MIXT', 'MIXU', 'MIXN', 'BURN', 'TPAIRT', 'TPAIRM', 'X2L', 'X8', 'D6'])));
foreach ($prot as $id) {
    $s = $cell('S0', $id, 'login', 'pre+out');
    $t = $cell('TH5', $id, 'login', 'pre+out');
    $n = $cell('GNH5', $id, 'login', 'pre+out');
    $rows[] = [$id, $ar('S0', $id, 'login', 'pre+out'), 5 * $s['attackReachedVerification'], $t['attackReachedVerification'] . ' (' . sprintf('%+d', $t['attackReachedVerification'] - $s['attackReachedVerification']) . ') ' . $flagCell($id, 'login', 'pre+out', 'TH5'), $n['attackReachedVerification'] . ' (' . sprintf('%+d', $n['attackReachedVerification'] - $s['attackReachedVerification']) . ') ' . $flagCell($id, 'login', 'pre+out', 'GNH5'), $s['attackReachedMaxPer600'] . ' / ' . $t['attackReachedMaxPer600'] . ' / ' . $n['attackReachedMaxPer600'], $s['correlationIssuances'] . ' / ' . $t['correlationIssuances'] . ' / ' . $n['correlationIssuances'], (string) $n['headroomAdmissions']];
}
$table(['Stream', 'S0 reach', '5×S0', 'TH5 reach (Δ)', 'GNH5 reach (Δ)', 'max/600 S0/TH5/GNH5', 'K1+K2 blocks issued S0/TH5/GNH5', 'GNH5 headroom admissions'], $rows);
$h('checkOnly-only Hosts (`pre`) — attacker reach S0 / TH5 / GNH5; record-only Hosts (`out`) — attacker requests DENIED S0 / TH5 / GNH5 (every record-only attempt is verified before the library sees it):');
$h('');
$rows = [];
foreach ($prot as $id) {
    $rows[] = [$id, $ar('S0', $id, 'login', 'pre') . ' / ' . $ar('TH5', $id, 'login', 'pre') . ' / ' . $ar('GNH5', $id, 'login', 'pre'), $ad('S0', $id, 'login', 'out') . ' / ' . $ad('TH5', $id, 'login', 'out') . ' / ' . $ad('GNH5', $id, 'login', 'out')];
}
$table(['Stream', 'pre: reach S0 / TH5 / GNH5', 'out: attacker denied S0 / TH5 / GNH5'], $rows);

$h('## 3. Fairness — legitimate requests denied (Login with account)');
$h('');
$rows = [];
$fair = array_values(array_unique(array_merge(gnhTaskFairnessStreams(), ['N4', 'N5', 'N12M', 'N12V', 'Nu3', 'Nu5', 'Nu12T', 'Nu12M', 'L2', 'L3', 'L4', 'ABA_B1', 'GAP', 'MIXU', 'BURN', 'TPAIRT', 'TPAIRM', 'X2L', 'X8', 'D6'])));
foreach ($fair as $id) {
    foreach (['pre+out', 'out', 'pre'] as $flow) {
        $rows[] = [$id, $flow, $ld('S0', $id, 'login', $flow), $ld('TH5', $id, 'login', $flow), $ld('GNH5', $id, 'login', $flow), $src($cell('GNH5', $id, 'login', $flow)['legitDeniedBySource'])];
    }
}
$table(['Stream', 'Flow', 'S0', 'TH5', 'GNH5', 'Remaining sources on GNH5'], $rows);
$h('Poisoning streams (legitimate denials; the attacker is the poisoning party), pre+out: S0 / TH5 / GNH5 legit denied · attacker reach');
$h('');
$rows = [];
foreach (['X4', 'X6', 'Y4b', 'Y4c', 'Y4d', 'Y4e', 'Y4f', 'Y6b', 'Y6c'] as $id) {
    $rows[] = [$id, $ld('S0', $id, 'login', 'pre+out') . ' · ' . $ar('S0', $id, 'login', 'pre+out'), $ld('TH5', $id, 'login', 'pre+out') . ' · ' . $ar('TH5', $id, 'login', 'pre+out'), $ld('GNH5', $id, 'login', 'pre+out') . ' · ' . $ar('GNH5', $id, 'login', 'pre+out')];
}
$table(['Stream', 'S0', 'TH5', 'GNH5'], $rows);

$h('## 4. Other Host shapes (OTP, Login without account id, API_OVERUSE, DEC-008 custom API policy)');
$h('');
$rows = [];
foreach (gnhMatrixCells() as [$id, $shape, $flow]) {
    if ($shape === 'login') {
        continue;
    }
    $rows[] = [$id, $shape, $flow, $ld('S0', $id, $shape, $flow) . ' · ' . $ar('S0', $id, $shape, $flow), $ld('TH5', $id, $shape, $flow) . ' · ' . $ar('TH5', $id, $shape, $flow) . ' ' . $flagCell($id, $shape, $flow, 'TH5'), $ld('GNH5', $id, $shape, $flow) . ' · ' . $ar('GNH5', $id, $shape, $flow) . ' ' . $flagCell($id, $shape, $flow, 'GNH5')];
}
$table(['Stream', 'Shape', 'Flow', 'S0 legit denied · attacker reach', 'TH5', 'GNH5'], $rows);

$h('## 5. Per-cell decision detail (Login, pre+out): final decisions, denied levels, longest Retry-After, block issuances / re-issues, headroom, peak storage');
$h('');
$rows = [];
foreach (array_values(array_unique(array_merge(['D2', 'D1', 'L1', 'ABA_B', 'ABA_A', 'SP1n', 'SP6', 'TMSd', 'XN1', 'XN1n', 'MIX', 'MIXN', 'BURN', 'Y7', 'X7', 'Nu4', 'N12', 'OTPB']))) as $id) {
    foreach (ARMS as $arm) {
        $c = $cell($arm, $id, 'login', 'pre+out');
        $rows[] = [$id, $arm, str_replace('|', '\\|', json_encode($c['decisions'])), str_replace('|', '\\|', json_encode($c['deniedLevels'])), $c['maxRetryAfter'], $c['blockIssuances'] . ' / ' . $c['blockReissues'], $c['headroomAdmissions'] . ' (max ' . $c['budgetMax'] . ')', $c['peakCorrelationKeys'] . ' / ' . $c['peakCorrelationMembers']];
    }
}
$table(['Stream', 'Arm', 'Final decisions', 'Denied levels', 'Max Retry-After (s)', 'Blocks issued / re-issued', 'Headroom admissions (budget max)', 'Peak correlation keys / members'], $rows);

$h('## 6. The guard (G02, sequential, reference store)');
$h('');
foreach (ARMS as $arm) {
    $d = $data['g02_guard'][$arm];
    $b1 = $d['G02.B1.spray-budget'][0];
    $b2 = $d['G02.B2.window-boundary'][0];
    $b3 = $d['G02.B3.churn-budget-flows'][0]['flows'];
    $b5 = $d['G02.B5.nat-exhaustion'][0];
    $b6 = $d['G02.B6.boundaries'][0];
    $h("* **$arm** — B1 K1 zone (5 subjects + 60 repeats, checkOnly): " . $b1['allowed'] . '/65 allowed, first denial t=' . json_encode($b1['firstDenyT']) . ', headroom ' . $b1['headroomAdmissions'] . '; B2 two windows: ' . $b2['allowed'] . '/72 allowed, headroom per window ' . json_encode($b2['headroomPerBudgetWindow']) . ', max in a sliding 600 s ' . $b2['maxHeadroomInSliding600'] . ' headroom / ' . $b2['maxAllowedInSliding600'] . ' allowed; B3/B4 K2 zone: checkOnly ' . $b3['pre']['allowedAttempts'] . '/41, pre+out ' . $b3['pre+out']['allowedAttempts'] . '/41, record-only ' . $b3['out']['allowedAttempts'] . '/41 (headroom commands ' . $b3['pre']['headroomCommands'] . ' / ' . $b3['pre+out']['headroomCommands'] . ' / ' . $b3['out']['headroomCommands'] . '); B5 NAT exhaustion: colleague denied ' . $b5['colleagueDenied'] . '/15, neighbour reached ' . $b5['attackerReached'] . '/15; B6 probes `' . implode(' ', array_map(static fn(string $p): string => preg_replace('/\(.*\)/', '', $p), $b6['probes'])) . '` (first denial ' . json_encode($b6['firstDenial']) . ').');
}
$h('');
$h('DEC-003 cycle log (G02 B7, pre+out): ' . implode('; ', array_map(static fn(string $arm): string => "**$arm** " . json_encode($data['g02_guard'][$arm]['G02.B7.dec003-cycles'][0]['streams']), ARMS)));
$h('');

$h('## 7. Rotation and IPv6 (G03)');
$h('');
$rows = [];
foreach (ARMS as $arm) {
    foreach ($data['g03_rotation_ipv6'][$arm]['G03.R.rotation'] as $r) {
        $rows[] = [$arm, $r['stream'], $r['mode'] . ' @' . $r['rotationAt'], $r['legitDenied'] . '/' . $r['legitAttempts'] . ' (ref ' . $r['reference']['legitDenied'] . ')', $r['attackReachedVerification'] . '/' . $r['attackAttempts'] . ' (ref ' . $r['reference']['attackReachedVerification'] . ')', $r['headroomAdmissions']];
    }
}
$table(['Arm', 'Stream', 'Rotation', 'Legit denied (no-rotation ref)', 'Attacker reach (ref)', 'Headroom admissions'], $rows);
foreach (ARMS as $arm) {
    $r3 = $data['g03_rotation_ipv6'][$arm]['G03.R3.budget-rotation'][0];
    $r4 = $data['g03_rotation_ipv6'][$arm]['G03.R4.outer-only-single-device'][0];
    $h("* **$arm** — R3 budget across rotation: before " . $r3['beforeAllowed'] . ' allowed (headroom ' . $r3['beforeHeadroom'] . '), after ' . $r3['afterAllowed'] . '/40 (spray headroom ' . $r3['afterSprayHeadroom'] . ', first denial ' . json_encode($r3['afterFirstDenyT']) . '); R4 single stable device across an outer-key-only rotation: `' . implode(' ', $r4['probes']['client fingerprint']) . '`.');
}
$h('');
$rows = [];
foreach (ARMS as $arm) {
    foreach ($data['g03_rotation_ipv6'][$arm]['G03.H.ipv6'] as $r) {
        $f = $r['flows'];
        $rows[] = [$arm, $r['case'], $f['pre+out']['legitDenied'] . '/' . $f['pre+out']['legitAttempts'], $f['pre+out']['attackReachedVerification'] . '/' . $f['pre+out']['attackAttempts'], $f['pre']['legitDenied'] . ' · ' . $f['pre']['attackReachedVerification'], $f['out']['legitDenied'] . ' · ' . $f['out']['attackDenied'], $f['pre+out']['headroomAdmissions']];
    }
}
$table(['Arm', 'IPv6 case', 'pre+out legit denied', 'pre+out attacker reach', 'pre: legit · reach', 'out: legit · attacker denied', 'pre+out headroom'], $rows);

$h('## 8. Storage, TTL, backend failure, rollback, mixed version (G04)');
$h('');
foreach (ARMS as $arm) {
    $d = $data['g04_bounds_rollback'][$arm];
    $h("* **$arm** — in-zone scopes: " . json_encode(array_diff_key($d['G04.S1.per-scope-storage'][0], ['s' => 0, 'arm' => 0])) . '; TTL after 1801 s: ' . json_encode(array_map(static fn(array $x): array => ['keysAtEnd' => $x['keysAtEnd'], 'maxSet' => $x['maxSetAtEnd'], 'survivorsByWriteTtl' => $x['aliveAfter1801sByWriteTtl']], $d['G04.S2.ttl-bounds'][0]['streams'])) . '; budget failure through the engine: `' . implode(' ', $d['G04.F1.budget-backend-failure'][0]['decisions']) . '`.');
    if ($arm !== 'S0') {
        $h("  * rollback at t=300 to the real S0 code: " . json_encode($d['G04.R1.rollback'][0]['streams']) . '; mixed version: ' . json_encode($d['G04.R2.mixed-version'][0]['streams']));
    }
}
$h('');
if ($redisStatus === 'RAN') {
    $h('## 9. Real Redis (G05) and concurrency (G06)');
    $h('');
    foreach (ARMS as $arm) {
        $d = $data['g05_redis'][$arm];
        $p1 = $d['G05.P1.parity'][0]['sequences'];
        $line = "* **$arm** — K1 zone `" . $p1['k1-spray-zone']['redis'] . '` K2 zone `' . $p1['k2-churn-zone']['redis'] . '` (reference identical: ' . ($p1['k1-spray-zone']['redis'] === $p1['k1-spray-zone']['reference'] && $p1['k2-churn-zone']['redis'] === $p1['k2-churn-zone']['reference'] ? 'yes' : 'NO') . '); TTL ' . json_encode(array_diff_key($d['G05.P2.ttl-hygiene'][0], ['s' => 0, 'arm' => 0]));
        if ($arm !== 'S0') {
            $p3 = $d['G05.P3.rollback-redis'][0];
            $p4 = $d['G05.P4.mixed-version-redis'][0];
            $line .= '; rollback on Redis: S0 code `' . json_encode($p3['s0Steps']) . '` error ' . json_encode($p3['s0Error']) . '; mixed `' . implode(' ', $p4['steps']) . '` error ' . json_encode($p4['error']);
        }
        $h($line . '.');
    }
    $h('');
    $rows = [];
    foreach (ARMS as $arm) {
        $d = $data['g06_concurrency'][$arm];
        $c3 = $d['G06.C3.k1-fresh-burst'][0]['rounds'];
        $rows[] = [$arm, implode(' / ', array_column($d['G06.C1.k1-boundary-burst'][0]['rounds'], 'allowedInBurst')) . ' of 16', implode(' / ', array_column($d['G06.C2.k2-boundary-burst'][0]['rounds'], 'allowedInBurst')) . ' of 8', ($arm === 'S0' ? 'exactly 4: ' . json_encode(array_column($c3, 'allowedExactly4')) : '≤ 24: ' . json_encode(array_column($c3, 'allowedAtMost24'))), implode(' / ', array_column($d['G06.C4.two-budget-burst'][0]['rounds'], 'allowedInBurst')) . ' of 8'];
    }
    $table(['Arm', 'C1 K1 boundary (6 of B=20 left on GNH5)', 'C2 K2 boundary (4 of B=12 left on GNH5)', 'C3 fresh K1 scope, 32 distinct subjects', 'C4 both budgets in zone (K2 5 left, K1 15 left)'], $rows);
} else {
    $h('* Real-Redis stage **SKIPPED** — gates G4a, G4b and the Redis half of G4d are UNPROVEN.');
    $h('');
}
$h('## 10. Trade-off detail (G2)');
$h('');
foreach (CAND as $arm) {
    $t = $trade[$arm];
    $h("* **$arm** — cells above 5×S0 (" . count($t['over5xS0']) . '): ' . implode(', ', $t['over5xS0']) . '.');
    $h("  * S0 admitted none, this arm admits some (" . count($t['s0ZeroNowPositive']) . '): ' . implode(', ', $t['s0ZeroNowPositive']) . '.');
    $h("  * every attempt reaches verification (" . count($t['fullReach']) . '): ' . implode(', ', $t['fullReach']) . '.');
    $h('  * largest absolute increase: +' . $t['maxAbsoluteIncrease']['delta'] . ' (' . $t['maxAbsoluteIncrease']['cell'] . '); largest increase inside one 600 s window: +' . $t['maxIncreasePer600Window']['delta'] . ' (' . $t['maxIncreasePer600Window']['cell'] . ').');
}
file_put_contents("$out/summary.md", implode("\n", $md) . "\n");
echo 'OK gates=' . count($gates) . " problems=0\n";
