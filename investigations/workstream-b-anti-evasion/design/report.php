<?php

declare(strict_types=1);

// Gate evaluation + summary generator for the Design & Proof Gate (#97 / #100). Reads results/actual/<scenario>.<variant>.jsonl produced by
// run.sh for S0, EQN, EQW, EQL, EQV and writes:
//   <out>/gates.jsonl   machine-readable gate results (one row per variant x gate) — golden-diffed by run.sh
//   <out>/summary.md    human-readable tables generated from the SAME data (no hand-typed numbers)
// Gate vocabulary: PASS | FAIL | UNPROVEN | NOT_APPLICABLE | OWNER_DECISION_REQUIRED. Thresholds are NEVER fitted: every comparison is against
// S0 or against a bound derived from the design (stated next to the gate). Missing / empty / malformed required evidence exits 5 and never
// yields a PASS for the damaged variant.
require __DIR__ . '/fixtures/design.php';

error_reporting(E_ALL & ~E_WARNING);

$dir = $argv[1] ?? (__DIR__ . '/results/actual');
$out = $argv[2] ?? $dir;
$variants = ['S0', 'EQN', 'EQW', 'EQL', 'EQV'];
$candidates = ['EQN', 'EQW', 'EQL', 'EQV'];
const RECOMMENDED = 'EQL';
$published = dirname(__DIR__) . '/expected';

/** @var list<string> */
$problems = [];

/** @return list<array<string, mixed>> */
function rows(string $dir, string $scenario, string $variant): array
{
    global $problems;
    $f = "$dir/$scenario.$variant.jsonl";
    if (! is_file($f)) {
        $problems[] = "missing evidence file $scenario.$variant.jsonl";

        return [];
    }
    $r = [];
    $bad = false;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $n => $l) {
        try {
            $r[] = json_decode($l, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $problems[] = "malformed JSON in $scenario.$variant.jsonl line " . ($n + 1);
            $bad = true;
        }
    }
    if ($bad) {
        return [];
    }
    if ($r === []) {
        $problems[] = "empty evidence file $scenario.$variant.jsonl";
    }

    return $r;
}

$redisStatusFile = "$dir/redis_status.txt";
$redisStatus = is_file($redisStatusFile) ? trim((string) file_get_contents($redisStatusFile)) : 'UNKNOWN';
if (! in_array($redisStatus, ['RAN', 'SKIPPED'], true)) {
    $problems[] = 'missing or invalid redis_status.txt (run.sh must record RAN or SKIPPED)';
}
$liveFile = "$dir/redis_live.txt";
$redisLive = is_file($liveFile) ? trim((string) file_get_contents($liveFile)) !== '0' : false;

$M = [];
$C = [];
$A = [];
$scenarios = ['d02_recovery_boundaries', 'd03_rotation_ephemeral_failure', 'd04_state_bounds_ops', 'd05_redis', 'd06_api_surface', 'a06_indistinguishability', 'a07_dec003_behavior'];
foreach ($variants as $v) {
    foreach (rows($dir, 'd01_matrix', $v) as $r) {
        if ($r['s'] === 'D01.matrix') {
            $M[$v][$r['stream']][$r['shape']][$r['flow']] = $r;
        } elseif ($r['s'] === 'D01.controls') {
            $C[$v][$r['stream']] = $r;
        }
    }
    foreach ($scenarios as $sc) {
        if ($sc === 'd05_redis' && $redisStatus !== 'RAN') {
            continue;
        }
        foreach (rows($dir, $sc, $v) as $r) {
            $A[$v][$sc][] = $r;
        }
    }
}

$D7 = [];
foreach (['s0', 'advisory', 'narrow', 'wide'] as $opt) {
    $f = "$dir/d07_low_scope.$opt.jsonl";
    if (! is_file($f)) {
        $problems[] = "missing evidence file d07_low_scope.$opt.jsonl";
        continue;
    }
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $n => $l) {
        try {
            $r = json_decode($l, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $problems[] = "malformed JSON in d07_low_scope.$opt.jsonl line " . ($n + 1);
            continue;
        }
        if (($r['s'] ?? '') === 'D07.option') {
            $D7[$opt][$r['stream']] = $r;
        }
    }
    if (count($D7[$opt] ?? []) !== 9) {
        $problems[] = "d07_low_scope.$opt must carry 9 D07.option rows";
    }
}

const DSG_CELL_INT_FIELDS = ['attackAttempts', 'attackReachedVerification', 'attackDenied', 'attackDeniedByCorrelation', 'attackDeniedOther', 'legitAttempts', 'legitDenied', 'legitDeniedByCorrelation', 'legitDeniedOther', 'controlLegitDenied', 'controlAttackReachedVerification', 'blockIssuancesTotal'];

/**
 * Identifier (I1..I4) a pair row ('pair') or an assertion ('assert') refers to: the first token after the optional 'indistinguishable: ' prefix.
 */
function dsgA06Id(array $r): ?string
{
    $text = (string) ($r['pair'] ?? $r['assert'] ?? '');
    $text = preg_replace('/^indistinguishable:\s*/', '', $text) ?? '';

    return preg_match('/^(I[1-4])\b/', $text, $m) === 1 ? $m[1] : null;
}

/** @return list<string> */
function dsgCellProblems(array $c): array
{
    $p = [];
    foreach (DSG_CELL_INT_FIELDS as $f) {
        if (! array_key_exists($f, $c) || ! is_int($c[$f]) || $c[$f] < 0) {
            $p[] = $f;
        }
    }
    if ($p === []) {
        if ($c['attackDenied'] !== $c['attackDeniedByCorrelation'] + $c['attackDeniedOther'] || $c['legitDenied'] !== $c['legitDeniedByCorrelation'] + $c['legitDeniedOther']) {
            $p[] = 'denied != byCorrelation + other';
        }
        if ($c['attackDenied'] > $c['attackAttempts'] || $c['legitDenied'] > $c['legitAttempts'] || $c['attackReachedVerification'] > $c['attackAttempts']) {
            $p[] = 'count exceeds attempts';
        }
    }

    return $p;
}

// ---- required-evidence validation (fail closed) ------------------------------------------------------------------------------------
$requiredRows = [
    'd02_recovery_boundaries' => ['D02.provenance', 'D02.B10.evidence-window-boundary', 'D02.B11.window-not-sliding', 'D02.B1.lone-legit-after-trigger', 'D02.B4.persistent-attacker-reissue', 'D02.B5.single-seed-then-silence', 'D02.B6.evidence-boundary', 'D02.B7.single-typo-on-stale-set', 'D02.B8.sustained-leak-30min'],
    'd03_rotation_ephemeral_failure' => ['D03.provenance', 'D03.R1.rotation-failing-pair-then-third', 'D03.R2.marker-continuity', 'D03.R3.rotation-no-evidence', 'D03.R4.block-continuity', 'D03.E1.ephemeral-overflow', 'D03.F1a.churn-set-write', 'D03.F1b.marker-write', 'D03.F1c.marker-read', 'D03.C1.custom-store-minimal-capabilities'],
    'd04_state_bounds_ops' => ['D04.provenance', 'D04.S1', 'D04.S2', 'D04.S3', 'D04.O1.ops'],
    'd06_api_surface' => ['D06.provenance', 'D06.surface', 'D06.private'],
    'a06_indistinguishability' => ['A06.provenance', 'A06.pair'],
    'a07_dec003_behavior' => ['A07.provenance'],
    'd05_redis' => ['D05.provenance', 'D05.R1.record-only-churn', 'D05.R2.anchor-rotation', 'D05.R3.distinct-member-race', 'D05.R4.duplicate-member-race', 'D05.R5.marker-atomicity', 'D05.R6.live-recovery'],
];
foreach ($variants as $v) {
    foreach (dsgMatrixCells() as [$id, $shape, $flow]) {
        $cell = $M[$v][$id][$shape][$flow] ?? null;
        if ($cell === null) {
            $problems[] = "missing matrix cell $v/$id/$shape/$flow";
            unset($M[$v]);
            break;
        }
        if (($bad = dsgCellProblems($cell)) !== []) {
            $problems[] = "invalid matrix cell $v/$id/$shape/$flow: " . implode(',', $bad);
            unset($M[$v]);
            break;
        }
    }
    foreach (dsgMatrixOrder() as $id) {
        if (! isset($C[$v][$id]['spanSeconds'])) {
            $problems[] = "missing D01.controls row for $v/$id";
            break;
        }
    }
    foreach ($requiredRows as $sc => $names) {
        if ($sc === 'd05_redis' && $redisStatus !== 'RAN') {
            continue;
        }
        $have = array_column($A[$v][$sc] ?? [], 's');
        foreach ($names as $n) {
            if (! in_array($n, $have, true)) {
                $problems[] = "missing row $n in $sc.$v";
            }
        }
    }
    if (count(array_filter($A[$v]['a06_indistinguishability'] ?? [], static fn(array $r): bool => $r['s'] === 'A06.pair')) !== 4) {
        $problems[] = "a06.$v must carry exactly four A06.pair rows";
    }
    $a06Pairs = array_values(array_filter($A[$v]['a06_indistinguishability'] ?? [], static fn(array $r): bool => $r['s'] === 'A06.pair'));
    $a06Asserts = array_values(array_filter($A[$v]['a06_indistinguishability'] ?? [], static fn(array $r): bool => isset($r['assert']) && str_starts_with($r['assert'], 'indistinguishable:')));
    $pairIds = array_map('dsgA06Id', $a06Pairs);
    $assertIds = array_map('dsgA06Id', $a06Asserts);
    sort($pairIds);
    sort($assertIds);
    if ($pairIds !== ['I1', 'I2', 'I3', 'I4'] || $assertIds !== ['I1', 'I2', 'I3', 'I4']) {
        $problems[] = "a06.$v: the four A06.pair rows and the four 'indistinguishable:' assertions must cover exactly I1, I2, I3, I4 once each (pairs " . json_encode($pairIds) . ', assertions ' . json_encode($assertIds) . ')';
    }
    if (in_array($v, ['EQW', 'EQL', 'EQV'], true)) {
        foreach (['B10 evidence window', 'B11 fixed window'] as $needle) {
            $n = count(array_filter($A[$v]['d02_recovery_boundaries'] ?? [], static fn(array $r): bool => isset($r['assert']) && str_starts_with($r['assert'], $needle)));
            if ($n !== 1) {
                $problems[] = "d02.$v must carry exactly one '$needle' assertion next to its measurement row (found $n)";
            }
        }
    }
    if (count(array_filter($A[$v]['a07_dec003_behavior'] ?? [], static fn(array $r): bool => isset($r['assert']))) < 3) {
        $problems[] = "a07.$v carries fewer than three DEC-003 behaviour assertions";
    }
    if (count(array_filter($A[$v]['d06_api_surface'] ?? [], static fn(array $r): bool => isset($r['assert']))) !== 3) {
        $problems[] = "d06.$v must carry its three fingerprint sensitivity controls";
    }
}

/** @return ?bool */
$assertOk = static function (string $v, string $sc, string $needle) use (&$A): ?bool {
    $found = null;
    foreach ($A[$v][$sc] ?? [] as $r) {
        if (isset($r['assert']) && str_contains($r['assert'], $needle)) {
            $found = ($found ?? true) && (bool) $r['ok'];
        }
    }

    return $found;
};
$allAsserts = static function (string $v, string $sc, string $prefix = '') use (&$A): array {
    $ok = 0;
    $bad = [];
    foreach ($A[$v][$sc] ?? [] as $r) {
        if (isset($r['assert']) && ($prefix === '' || str_starts_with($r['assert'], $prefix))) {
            $r['ok'] ? $ok++ : $bad[] = $r['assert'];
        }
    }

    return [$ok, $bad];
};
$row = static function (string $v, string $sc, string $name) use (&$A): ?array {
    foreach ($A[$v][$sc] ?? [] as $r) {
        if ($r['s'] === $name) {
            return $r;
        }
    }

    return null;
};
$cell = static fn(string $v, string $id, string $shape, string $flow): ?array => $M[$v][$id][$shape][$flow] ?? null;

$gates = [];
$gate = static function (string $variant, string $id, string $status, string $evidence, array $extra = []) use (&$gates): void {
    $gates[] = ['variant' => $variant, 'gate' => $id, 'status' => $status] + $extra + ['evidence' => $evidence];
};
$status = static fn(?bool $ok): string => $ok === null ? 'UNPROVEN' : ($ok ? 'PASS' : 'FAIL');

// Attack streams (classes evasion|both) and benign streams for the gates below.
$allIds = dsgMatrixOrder();
$classOf = static fn(string $id): string => dsgClass()[$id] ?? wsbClass($id);
$attackIds = array_values(array_filter($allIds, static fn(string $id): bool => in_array($classOf($id), ['evasion', 'both'], true)));
$benignIds = ['L1', 'L1f', 'L2', 'L3', 'L4', 'D1', 'D2'];
$shapesAll = ['login', 'login-anon', 'otp', 'api', 'api_custom'];

/** Attacker verification budget the DESIGN allows over a stream (derived, not fitted): S0's reach plus one verification per 60 s block cycle. */
$leakBound = static function (int $s0Reach, int $span): int {
    return $s0Reach + (int) ceil($span / 60) + 1;
};

$s0Surface = $row('S0', 'd06_api_surface', 'D06.surface')['surfaceSha256'] ?? null;
$storeCallsFile = "$dir/store_calls.txt";
$storeCalls = is_file($storeCallsFile) ? array_values(array_filter(array_map('trim', file($storeCallsFile) ?: []), static fn(string $l): bool => str_starts_with($l, 'correlationStore->'))) : null;
$baseStoreOps = ['correlationStore->getWatchFlag', 'correlationStore->incrementWatchFlag'];

// Per-cell classification of attacker verification versus S0 (used by G6 and the summary).
$classify = static function (string $v, string $id, string $shape, string $flow) use ($cell, $leakBound, $C): array {
    $s0 = $cell('S0', $id, $shape, $flow);
    $c = $cell($v, $id, $shape, $flow);
    if ($s0 === null || $c === null) {
        return ['class' => 'unknown'];
    }
    $s0r = $s0['attackReachedVerification'];
    $r = $c['attackReachedVerification'];
    $ctl = $c['controlAttackReachedVerification'];
    $span = (int) ($C['S0'][$id]['spanSeconds'] ?? 0);
    if ($r <= $s0r) {
        $class = 'equal-or-better';
    } elseif ($r <= $leakBound($s0r, $span)) {
        $class = 'bounded-renewal';
    } elseif ($r <= $ctl) {
        // beyond the renewal bound but not worse than having no correlation at all: S0's protective gain is partly or fully gone
        $class = 'removed-correlation-protection';
    } else {
        $class = 'unbounded-regression';
    }

    $gain = $ctl - $s0r;

    return ['class' => $class, 's0' => $s0r, 'v' => $r, 'ctl' => $ctl, 'bound' => $leakBound($s0r, $span), 'attempts' => $c['attackAttempts'], 'lostPct' => $gain > 0 ? (int) round(100 * ($r - $s0r) / $gain) : 0];
};

foreach ($candidates as $v) {
    $present = isset($M[$v]) && isset($M['S0']);

    // G1 public API surface
    $surface = $row($v, 'd06_api_surface', 'D06.surface')['surfaceSha256'] ?? null;
    [$ctlOk, $ctlBad] = $allAsserts($v, 'd06_api_surface');
    $gate($v, 'G1 public API surface identical to S0', $s0Surface === null || $surface === null ? 'UNPROVEN' : ($surface === $s0Surface && $ctlBad === [] ? 'PASS' : 'FAIL'), "surface sha256 $surface vs S0 $s0Surface over " . ($row($v, 'd06_api_surface', 'D06.surface')['classes'] ?? '?') . ' classes; fingerprint sensitivity controls ok=' . $ctlOk);

    // G2 store contract
    $c1 = $assertOk($v, 'd03_rotation_ephemeral_failure', 'C1:');
    $callsOk = $storeCalls !== null && array_diff($storeCalls, $baseStoreOps) === [];
    $gate($v, 'G2 Redis/Store contract: existing base operations only, custom store works', $status($c1 === null ? null : ($c1 && $callsOk)), 'operations added by the patch: ' . ($storeCalls === null ? 'unknown' : implode(', ', $storeCalls)) . '; minimal custom store (no snapshot capability) ran: ' . var_export($c1, true));

    // G3 independent protections untouched
    $mismatch = [];
    foreach ($allIds as $id) {
        foreach (dsgMatrixCells() as [$cid, $shape, $flow]) {
            if ($cid !== $id) {
                continue;
            }
            $a = $cell('S0', $id, $shape, $flow);
            $b = $cell($v, $id, $shape, $flow);
            if ($a !== null && $b !== null && ($a['controlLegitDenied'] !== $b['controlLegitDenied'] || $a['controlAttackReachedVerification'] !== $b['controlAttackReachedVerification'])) {
                $mismatch[] = "$id/$shape/$flow";
            }
        }
    }
    $gate($v, 'G3 independent protections (K1 spray, K4, K5, scores) unchanged', $present ? ($mismatch === [] ? 'PASS' : 'FAIL') : 'UNPROVEN', $mismatch === [] ? 'correlation-disabled control runs equal S0 in every matrix cell' : 'control differs in: ' . implode(', ', array_slice($mismatch, 0, 6)));

    // G4 false positives
    $worse = [];
    $sumS0 = 0;
    $sumV = 0;
    foreach ($benignIds as $id) {
        foreach (['pre+out', 'out', 'pre'] as $flow) {
            $a = $cell('S0', $id, 'login', $flow);
            $b = $cell($v, $id, 'login', $flow);
            if ($a === null || $b === null) {
                continue;
            }
            $sumS0 += $a['legitDeniedByCorrelation'];
            $sumV += $b['legitDeniedByCorrelation'];
            if ($b['legitDeniedByCorrelation'] > $a['legitDeniedByCorrelation']) {
                $worse[] = "$id/$flow";
            }
        }
    }
    $zero = true;
    foreach (['L1', 'L2', 'L3', 'D1'] as $id) {
        $b = $cell($v, $id, 'login', 'pre+out');
        $zero = $zero && $b !== null && $b['legitDeniedByCorrelation'] === 0;
    }
    $gate($v, 'G4 benign traffic denied by correlation (L1,L2,L3,D1 = 0; no stream worse than S0)', $present ? ($worse === [] && $zero && $sumV < $sumS0 ? 'PASS' : 'FAIL') : 'UNPROVEN', "legit denials attributed to correlation over benign streams x 3 Login flows: S0 $sumS0 -> $v $sumV; zero on L1/L2/L3/D1 pre+out: " . var_export($zero, true));

    // G5 recovery
    $b1 = $assertOk($v, 'd02_recovery_boundaries', 'B1 recovery');
    $b5 = $assertOk($v, 'd02_recovery_boundaries', 'B5 no history-only');
    $b6 = $assertOk($v, 'd02_recovery_boundaries', 'B6 evidence boundary');
    $b7 = $assertOk($v, 'd02_recovery_boundaries', 'B7:');
    $b10 = $assertOk($v, 'd02_recovery_boundaries', 'B10 evidence window');
    $b11 = $assertOk($v, 'd02_recovery_boundaries', 'B11 fixed window');
    $windowOk = in_array($v, ['EQW', 'EQL', 'EQV'], true) ? ($b10 === true && $b11 === true) : true;
    $liveRow = $row($v, 'd05_redis', 'D05.R6.live-recovery');
    $liveRan = $redisStatus === 'RAN' && $redisLive && $liveRow !== null && ($liveRow['applicable'] ?? true) !== false;
    $liveOk = $liveRan ? $assertOk($v, 'd05_redis', 'R6:') : null;
    $recStatus = 'FAIL';
    if ($b1 === true && $b5 === true && $b6 === true && $b7 === true && $windowOk) {
        $recStatus = in_array($v, ['EQL', 'EQV'], true) ? ($liveOk === true ? 'PASS' : ($liveOk === false ? 'FAIL' : 'UNPROVEN')) : 'PASS';
    }
    $b1row = $row($v, 'd02_recovery_boundaries', 'D02.B1.lone-legit-after-trigger');
    $b5row = $row($v, 'd02_recovery_boundaries', 'D02.B5.single-seed-then-silence');
    $gate($v, 'G5 recovery without history-only re-block (controlled clock + live Redis clock)', $recStatus, 'lone legit first allowed at t=' . ($b1row['legitFirstAllowedT'] ?? '?') . ' (S0 ' . ($row('S0', 'd02_recovery_boundaries', 'D02.B1.lone-legit-after-trigger')['legitFirstAllowedT'] ?? '?') . '); last denial after a single seed t=' . ($b5row['lastLegitDenialT'] ?? '?') . ' (S0 ' . ($row('S0', 'd02_recovery_boundaries', 'D02.B5.single-seed-then-silence')['lastLegitDenialT'] ?? '?') . '); evidence-window assertions B10 (0/59/60/61/119 s) = ' . var_export($b10, true) . ', B11 (not sliding) = ' . var_export($b11, true) . '; live Redis clock: ' . ($liveOk === null ? 'not run' : var_export($liveOk, true)));

    // G6 security regression versus S0 (never averaged: every attack cell is classified)
    $counts = ['equal-or-better' => 0, 'bounded-renewal' => 0, 'removed-correlation-protection' => 0, 'unbounded-regression' => 0, 'unknown' => 0];
    $removed = [];
    $unbounded = [];
    foreach ($attackIds as $id) {
        foreach ($shapesAll as $shape) {
            $flow = 'pre+out';
            if ($cell('S0', $id, $shape, $flow) === null) {
                continue;
            }
            $k = $classify($v, $id, $shape, $flow);
            $counts[$k['class']]++;
            if ($k['class'] === 'removed-correlation-protection') {
                $removed["$id/$shape"] = $k;
            }
            if ($k['class'] === 'unbounded-regression') {
                $unbounded["$id/$shape"] = $k;
            }
        }
    }
    $g6 = $present ? ($unbounded !== [] ? 'FAIL' : ($removed !== [] ? 'OWNER_DECISION_REQUIRED' : 'PASS')) : 'UNPROVEN';
    $gate($v, 'G6 attacker verification vs S0 (Login/OTP/API shapes, pre+out)', $g6, 'attack cells: ' . json_encode($counts) . '; correlation protection lost or degraded (share of S0\'s gain over the no-correlation control that is gone) in: ' . ($removed === [] ? 'none' : implode(', ', array_map(static fn(string $k, array $x): string => "$k {$x['lostPct']}%", array_keys($removed), $removed))) . '; unbounded: ' . ($unbounded === [] ? 'none' : implode(', ', array_keys($unbounded))), ['ownerDecision' => $removed !== []]);

    // G7 concurrency / atomicity on real Redis
    if ($redisStatus !== 'RAN') {
        $gate($v, 'G7 concurrency and atomic evidence on real Redis', 'UNPROVEN', 'real-Redis stage did not run');
    } else {
        $r3 = $assertOk($v, 'd05_redis', 'R3:');
        $r4 = $assertOk($v, 'd05_redis', 'R4:');
        $r5 = $assertOk($v, 'd05_redis', 'R5:');
        $ok = $r3 === true && $r4 === true && ($v === 'EQN' || $r5 === true);
        $gate($v, 'G7 concurrency and atomic evidence on real Redis', $ok ? 'PASS' : 'FAIL', 'cap race R3=' . var_export($r3, true) . ', duplicate race R4=' . var_export($r4, true) . ', marker atomicity R5=' . ($v === 'EQN' ? 'n/a' : var_export($r5, true)));
    }

    // G8 rotation continuity
    [$rok, $rbad] = $allAsserts($v, 'd03_rotation_ephemeral_failure', 'R');
    [$eok, $ebad] = $allAsserts($v, 'd03_rotation_ephemeral_failure', 'E1');
    $gate($v, 'G8 Current/Previous rotation and ephemeral continuity', $rbad === [] && $ebad === [] && $rok > 0 ? 'PASS' : 'FAIL', "rotation assertions ok=$rok, violated=" . json_encode($rbad) . '; ephemeral ok=' . $eok . ' violated=' . json_encode($ebad));

    // G9 WATCH preserved
    [$wok, $wbad] = $allAsserts($v, 'd02_recovery_boundaries', 'B3');
    $gate($v, 'G9 N-1 WATCH boundary equals S0', $wbad === [] && $wok === 4 ? 'PASS' : 'FAIL', "B3 assertions ok=$wok violated=" . json_encode($wbad));

    // G10 bounded state
    [$sok, $sbad] = $allAsserts($v, 'd04_state_bounds_ops');
    $r2 = $redisStatus === 'RAN' ? $assertOk($v, 'd05_redis', 'R2:') : null;
    $gate($v, 'G10 bounded state, TTL on every key, declared caps', $sbad === [] && $sok > 0 ? ($redisStatus === 'RAN' ? ($r2 === true ? 'PASS' : 'FAIL') : 'UNPROVEN') : 'FAIL', "reference-store assertions ok=$sok violated=" . json_encode($sbad) . '; real Redis R2=' . var_export($r2, true));

    // G11 failure semantics
    [$fok, $fbad] = $allAsserts($v, 'd03_rotation_ephemeral_failure', 'F1');
    $gate($v, 'G11 backend failure propagates (never ALLOW / silent skip)', $fbad === [] && $fok >= 1 ? 'PASS' : 'FAIL', "F1 assertions ok=$fok violated=" . json_encode($fbad));

    // G12 DEC-003 by behaviour
    [$dok, $dbad] = $allAsserts($v, 'a07_dec003_behavior');
    $gate($v, 'G12 DEC-003 hard-block cycle / pause behaviour (PR #104 A07, unchanged)', $dbad === [] && $dok >= 3 ? 'PASS' : 'FAIL', "A07 assertions ok=$dok violated=" . json_encode($dbad));

    // G13 API_OVERUSE (access-scored): is #100 (NAT churn) fixed there?
    $apiNotImproved = [];
    $apiRows = [];
    foreach (['api', 'api_custom'] as $shape) {
        foreach (['L1', 'X4', 'Y4e', 'D2'] as $id) {
            $a = $cell('S0', $id, $shape, 'pre+out');
            $b = $cell($v, $id, $shape, 'pre+out');
            if ($a !== null && $b !== null && $a['legitDeniedByCorrelation'] > 0) {
                $apiRows[] = "$id/$shape {$a['legitDeniedByCorrelation']}->{$b['legitDeniedByCorrelation']}";
                if ($b['legitDeniedByCorrelation'] >= $a['legitDeniedByCorrelation']) {
                    $apiNotImproved[] = "$id/$shape";
                }
            }
        }
    }
    $gate($v, 'G13 API_OVERUSE (access-scored): #100 NAT churn improved', $v === 'EQN' ? 'FAIL' : ($apiNotImproved === [] ? 'PASS' : 'OWNER_DECISION_REQUIRED'), 'legit denials attributed to correlation S0->' . $v . ': ' . implode(', ', $apiRows) . '; NOT improved on: ' . ($apiNotImproved === [] ? 'none' : implode(', ', $apiNotImproved)) . ($apiNotImproved === [] ? '. The security cost of the volume rule is classified in G6 (API shapes).' : '. Every call is scored activity, so the evidence rule cannot discriminate'), ['ownerDecision' => $apiNotImproved !== []]);

    // G17 check-only-only hosts
    $pre = [];
    foreach (['X1', 'X3', 'Y1', 'Y9a', 'Y9b', 'X2'] as $id) {
        $a = $cell('S0', $id, 'login', 'pre');
        $b = $cell($v, $id, 'login', 'pre');
        if ($a !== null && $b !== null) {
            $pre[] = "$id {$a['attackReachedVerification']}->{$b['attackReachedVerification']}/{$a['attackAttempts']}";
        }
    }
    $gate($v, 'G17 check-only-only integrations keep correlation protection', 'NO_GO_SCOPED', 'NO-GO for this integration within the API/Redis constraints: a host that only calls checkOnly never reports an outcome, so no outcome-based evidence exists and S0 behaviour can only be restored by treating checkOnly itself as evidence (= S0, i.e. the original false positives). Attacker reach in the check-only flow S0->' . $v . ': ' . implode(', ', $pre), ['ownerDecision' => true]);

    // G14 cost
    $ops = [];
    foreach (['S0', $v] as $x) {
        foreach ($A[$x]['d04_state_bounds_ops'] ?? [] as $r) {
            if ($r['s'] === 'D04.O1.ops') {
                $ops[$x][$r['case']] = $r['total'];
            }
        }
    }
    $succDelta = ($ops[$v]['legit-success-pre+out'] ?? 99) - ($ops['S0']['legit-success-pre+out'] ?? 0);
    $typoDelta = ($ops[$v]['legit-typo-pre+out'] ?? 99) - ($ops['S0']['legit-typo-pre+out'] ?? 0);
    $lowDelta = ($ops[$v]['low-success-pre+out'] ?? 99) - ($ops['S0']['low-success-pre+out'] ?? 0);
    $gate($v, 'G14 cost: success path no more store operations than S0; failing request <= +2', $v === 'EQN' ? 'NOT_APPLICABLE' : ($succDelta <= 0 && $typoDelta <= 2 && $lowDelta <= 0 ? 'PASS' : 'FAIL'), "per-command correlation-store operations (checkOnly+outcome): legit success $succDelta, legit typo $typoDelta, LOW success $lowDelta (vs S0)");

    // G15 indistinguishability
    $pairs = array_values(array_filter($A[$v]['a06_indistinguishability'] ?? [], static fn(array $r): bool => $r['s'] === 'A06.pair'));
    $assertRows = array_values(array_filter($A[$v]['a06_indistinguishability'] ?? [], static fn(array $r): bool => isset($r['assert']) && str_starts_with($r['assert'], 'indistinguishable:')));
    $same = count($pairs) === 4 && count(array_filter($pairs, static fn(array $r): bool => $r['identicalTreatment'] === true)) === 4;
    $assertsOk = count($assertRows) === 4 && count(array_filter($assertRows, static fn(array $r): bool => $r['ok'] === true)) === 4;
    $pairIds = array_map('dsgA06Id', $pairs);
    $assertIds = array_map('dsgA06Id', $assertRows);
    sort($pairIds);
    sort($assertIds);
    $idsMatch = $pairIds === ['I1', 'I2', 'I3', 'I4'] && $assertIds === ['I1', 'I2', 'I3', 'I4'];
    $gate($v, 'G15 A06: attacker/legitimate pairs with identical inputs are treated identically (no pretence of separation)', $same && $assertsOk && $idsMatch ? 'PASS' : 'FAIL', count($pairs) . ' pair rows (identical decisions: ' . var_export($same, true) . ') AND ' . count($assertRows) . ' indistinguishable assertions (all ok: ' . var_export($assertsOk, true) . '); identifiers I1–I4 matched one-to-one between pairs and assertions: ' . var_export($idsMatch, true) . '; all three are required');
}

// G16 provenance and S0 baseline stability (not per candidate)
$provOk = true;
$provNote = [];
foreach ($variants as $v) {
    $shas = [];
    foreach (['d01_matrix' => 'D01.provenance', 'd02_recovery_boundaries' => 'D02.provenance', 'd03_rotation_ephemeral_failure' => 'D03.provenance', 'd04_state_bounds_ops' => 'D04.provenance', 'd06_api_surface' => 'D06.provenance'] as $sc => $name) {
        $r = $sc === 'd01_matrix' ? ((rows($dir, 'd01_matrix', $v)[0] ?? null)) : $row($v, $sc, $name);
        $shas[] = ($r['variant'] ?? '?') . ':' . ($r['pipelineSha256'] ?? '?');
        $provOk = $provOk && ($r['variant'] ?? null) === $v && ($r['level'] ?? '') === ($v === 'S0' ? 'S0_RUNTIME_OBSERVED' : 'CANDIDATE_EXPERIMENTAL_RUNTIME');
    }
    $provOk = $provOk && count(array_unique($shas)) === 1;
    $provNote[] = $v . ' ' . substr(explode(':', $shas[0])[1], 0, 12);
}
$s0Match = true;
$compared = 0;
$s104 = [];
foreach (file("$published/a01_matrix.S0.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
    $r = json_decode($l, true);
    if (($r['s'] ?? '') === 'A01.matrix') {
        $s104[$r['stream'] . '|' . $r['shape'] . '|' . $r['flow']] = $r;
    }
}
foreach ($s104 as $key => $r) {
    [$id, $shape, $flow] = explode('|', $key);
    $mine = $cell('S0', $id, $shape, $flow);
    if ($mine === null) {
        continue;
    }
    $compared++;
    foreach (['attackAttempts', 'attackReachedVerification', 'attackDenied', 'legitAttempts', 'legitDenied', 'legitDeniedByCorrelation', 'blockIssuancesTotal'] as $f) {
        $s0Match = $s0Match && $mine[$f] === $r[$f];
    }
}
$gate('ALL', 'G16 provenance (each file ran its own pinned pipeline) and S0 equals the PR #104 published baseline', $provOk && $s0Match && $compared > 0 ? 'PASS' : ($compared === 0 ? 'UNPROVEN' : 'FAIL'), implode('; ', $provNote) . "; S0 cells compared with PR #104 expected/a01_matrix.S0: $compared, identical core fields: " . var_export($s0Match, true));

// ---- verdicts ---------------------------------------------------------------------------------------------------------------------
$verdict = [];
foreach ($candidates as $v) {
    $mine = array_values(array_filter($gates, static fn(array $g): bool => $g['variant'] === $v || $g['variant'] === 'ALL'));
    $by = array_count_values(array_column($mine, 'status'));
    $hard = ($by['FAIL'] ?? 0) > 0;
    $scoped = ($by['NO_GO_SCOPED'] ?? 0) > 0;
    $verdict[$v] = $hard ? 'NO-GO' : (($by['OWNER_DECISION_REQUIRED'] ?? 0) > 0 || ($by['UNPROVEN'] ?? 0) > 0 || $scoped ? 'CONDITIONAL — OWNER DECISION REQUIRED' . ($scoped ? ' — NOT A UNIFIED READY FIX (scoped NO-GO: check-only-only)' : '') : 'GO');
    $gate($v, 'VERDICT', $verdict[$v], json_encode($by));
}

// ---- evidence problems stop here ---------------------------------------------------------------------------------------------------
if ($problems !== []) {
    @mkdir($out, 0775, true);
    file_put_contents("$out/evidence_problems.txt", implode("\n", array_unique($problems)) . "\n");
    fwrite(STDOUT, 'EVIDENCE PROBLEMS: ' . count(array_unique($problems)) . "\n" . implode("\n", array_slice(array_unique($problems), 0, 20)) . "\n");
    exit(5);
}

// ---- gates.jsonl -------------------------------------------------------------------------------------------------------------------
@mkdir($out, 0775, true);
$fh = fopen("$out/gates.jsonl", 'w');
foreach ($gates as $g) {
    fwrite($fh, json_encode($g, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
}
fclose($fh);

// ---- summary.md --------------------------------------------------------------------------------------------------------------------
$md = [];
$md[] = '# Design & Proof Gate — summary (generated by `report.php`; do not edit by hand)';
$md[] = '';
$md[] = 'Every number below is read from `results/actual/*.jsonl` produced by `run.sh`. `S0` = unpatched repository runtime; `EQN`, `EQW`, `EQL`, `EQV` = isolated experimental runtimes (baseline + `patches/eq-candidates.patch`). **None of this is production code or an approved policy.**';
$md[] = '';
$md[] = '## 1. Variants';
$md[] = '';
$md[] = '| Variant | Definition (as implemented in the patch) |';
$md[] = '|---|---|';
$md[] = '| S0 | Unpatched `EvaluationPipeline` at the pinned baseline. |';
$md[] = '| EQN | *Rejected ablation.* Detection **and** enforcement only on policy-scored commands (a reported failure, or access scoring); nothing is observed or enforced at `checkOnly`. |';
$md[] = '| EQW | *Alternative.* S0 detection on every command, unchanged. Enforcement only with **fresh scored evidence** (a fixed 60 s WATCH marker written by a scored command) in the detection scope — for every rule including the LOW (UA-only) dilution. |';
$md[] = '| **EQL** | **Base design under evaluation (not adopted).** EQW, except that the LOW-confidence (UA-only) cross-IP dilution is **advisory**: no state is written and nothing is enforced. |';
$md[] = '| EQV | *Bounded alternative for the API_OVERUSE shortfall.* EQL, except that for access-scored policies a call is evidence only when the K2/K3 volume score reaches the near-threshold level (L1 - 1) that §5.0 already uses for WATCH. |';
$md[] = '';
$md[] = '## 2. Gates (`OWNER_DECISION_REQUIRED` = a measured loss of protection that only the Owner can accept)';
$md[] = '';
$md[] = '| Gate | ' . implode(' | ', $candidates) . ' |';
$md[] = '|---|' . str_repeat('---|', count($candidates));
$ids = [];
foreach ($gates as $g) {
    if ($g['variant'] !== 'ALL' && $g['gate'] !== 'VERDICT') {
        $ids[$g['gate']] = true;
    }
}
foreach (array_keys($ids) as $gid) {
    $cells = [];
    foreach ($candidates as $v) {
        $g = array_values(array_filter($gates, static fn(array $x): bool => $x['variant'] === $v && $x['gate'] === $gid))[0] ?? null;
        $cells[] = $g === null ? '-' : ($g['status'] === 'PASS' ? 'PASS' : '**' . $g['status'] . '**');
    }
    $md[] = "| $gid | " . implode(' | ', $cells) . ' |';
}
$md[] = '| **Verdict** | ' . implode(' | ', array_map(static fn(string $c): string => '**' . $verdict[$c] . '**', $candidates)) . ' |';
foreach ($gates as $g) {
    if ($g['variant'] === 'ALL') {
        $md[] = '| ' . $g['gate'] . ' | ' . $g['status'] . ' (all variants) | | |';
    }
}
$md[] = '';
$md[] = '### Gate evidence';
$md[] = '';
foreach ($gates as $g) {
    if ($g['gate'] === 'VERDICT') {
        continue;
    }
    $md[] = '* `' . $g['variant'] . '` — ' . $g['gate'] . ': **' . $g['status'] . '** — ' . $g['evidence'];
}
$md[] = '';

$fmt = static function (?array $c, bool $attack): string {
    if ($c === null) {
        return '-';
    }

    return $attack
        ? $c['attackReachedVerification'] . '/' . $c['attackAttempts']
        : $c['legitDenied'] . '[' . $c['legitDeniedByCorrelation'] . ']/' . $c['legitAttempts'];
};
$tableFor = static function (string $shape, string $flow, array $ids) use ($cell, $fmt, $classOf, $variants): array {
    $t = [];
    $names = implode(' | ', array_slice($variants, 1));
    $t[] = "| Stream | Class | S0 attacker reach | $names | no-correlation control reach | S0 legit denied[by correlation] | $names |";
    $t[] = '|---|---|---|' . str_repeat('---|', count($variants) - 1) . '---|---|' . str_repeat('---|', count($variants) - 1);
    foreach ($ids as $id) {
        $s0 = $cell('S0', $id, $shape, $flow);
        if ($s0 === null) {
            continue;
        }
        $line = "| $id | " . $classOf($id) . ' |';
        foreach ($variants as $v) {
            $line .= ' ' . $fmt($cell($v, $id, $shape, $flow), true) . ' |';
        }
        $line .= ' ' . $s0['controlAttackReachedVerification'] . ' |';
        foreach ($variants as $v) {
            $line .= ' ' . $fmt($cell($v, $id, $shape, $flow), false) . ' |';
        }
        $t[] = $line;
    }

    return $t;
};
$md[] = '## 3. Security / fairness matrix — Login, precheck + outcome (the dominant Host flow)';
$md[] = '';
$md[] = 'Attacker reach = attacker attempts that reached the protected verification / attempts. Legit = denied[of which attributed to correlation by the paired control run]/attempts.';
$md[] = '';
foreach ($tableFor('login', 'pre+out', $allIds) as $l) {
    $md[] = $l;
}
$md[] = '';
foreach (['login' => ['out', 'pre'], 'login-anon' => ['pre+out'], 'otp' => ['pre+out'], 'api' => ['pre+out'], 'api_custom' => ['pre+out']] as $shape => $flows) {
    foreach ($flows as $flow) {
        $md[] = "### Shape `$shape`, flow `$flow`";
        $md[] = '';
        $subset = $shape === 'login' ? $allIds : dsgShapeStreams();
        foreach ($tableFor($shape, $flow, $subset) as $l) {
            $md[] = $l;
        }
        $md[] = '';
    }
}

$md[] = '### 3b. Legitimate denials NOT attributable to correlation (independent protections; the no-correlation control equals S0 in every variant)';
$md[] = '';
$md[] = '| Stream | Shape/flow | S0 legit denied by other protections | EQL | no-correlation control |';
$md[] = '|---|---|---|---|---|';
foreach (array_merge($benignIds, ['X4', 'X6', 'Y4e', 'Y4f', 'D3', 'D4']) as $id) {
    $a = $cell('S0', $id, 'login', 'pre+out');
    $b = $cell(RECOMMENDED, $id, 'login', 'pre+out');
    if ($a !== null && $b !== null && ($a['legitDeniedOther'] > 0 || $b['legitDeniedOther'] > 0)) {
        $md[] = "| $id | login / pre+out | {$a['legitDeniedOther']} of {$a['legitAttempts']} | {$b['legitDeniedOther']} of {$b['legitAttempts']} | {$a['controlLegitDenied']} |";
    }
}
$md[] = '';
$md[] = '### 3c. First denial, recovery and enforcement scope (Login, precheck + outcome)';
$md[] = '';
$md[] = 'First denial = stream second of the first denied attacker / legitimate request. Recovered = second of the first legitimate request allowed after the last legitimate denial (`none` = never within the stream; `-` = no denial). Scope = hard-block issuances by enforcement key (DEC-003 cycle log).';
$md[] = '';
$md[] = '| Stream | S0 attacker first denial | EQL | S0 legit first denial / recovered | EQL | S0 block issuances by scope | EQL |';
$md[] = '|---|---|---|---|---|---|---|';
$dash = static fn($x): string => $x === null ? '-' : (string) $x;
foreach ($allIds as $id) {
    $a = $cell('S0', $id, 'login', 'pre+out');
    $b = $cell(RECOMMENDED, $id, 'login', 'pre+out');
    if ($a === null || $b === null) {
        continue;
    }
    $rec = static fn(array $c): string => $c['legitDenied'] === 0 ? '-' : $dash($c['legitFirstDenyT']) . ' / ' . ($c['legitFirstAllowedAfterLastDeny'] === null ? 'none' : (string) $c['legitFirstAllowedAfterLastDeny']);
    $md[] = "| $id | " . $dash($a['attackFirstDenyT'] ?? null) . ' | ' . $dash($b['attackFirstDenyT'] ?? null) . ' | ' . $rec($a) . ' | ' . $rec($b) . ' | ' . json_encode($a['blockIssuancesByScope']) . ' | ' . json_encode($b['blockIssuancesByScope']) . ' |';
}
$md[] = '';
$md[] = '## 4. Per-cell classification of attacker verification versus S0 (EQL, pre+out, every shape)';
$md[] = '';
$md[] = 'Bound derived from the design, not fitted: S0 reach + one verification per 60 s block cycle over the stream span + 1.';
$md[] = '';
$md[] = '| Stream | Shape | S0 reach | EQL reach | design bound | no-correlation control | Class | share of S0 gain lost |';
$md[] = '|---|---|---|---|---|---|---|---|';
foreach ($attackIds as $id) {
    foreach ($shapesAll as $shape) {
        if ($cell('S0', $id, $shape, 'pre+out') === null) {
            continue;
        }
        $k = $classify(RECOMMENDED, $id, $shape, 'pre+out');
        if ($k['class'] === 'equal-or-better' && $k['v'] === $k['s0']) {
            continue; // identical rows are summarised below
        }
        $md[] = "| $id | $shape | {$k['s0']} | {$k['v']}/{$k['attempts']} | {$k['bound']} | {$k['ctl']} | {$k['class']} | {$k['lostPct']}% |";
    }
}
$md[] = '';
$identical = 0;
foreach ($attackIds as $id) {
    foreach ($shapesAll as $shape) {
        if ($cell('S0', $id, $shape, 'pre+out') !== null) {
            $k = $classify(RECOMMENDED, $id, $shape, 'pre+out');
            $identical += ($k['class'] === 'equal-or-better' && $k['v'] === $k['s0']) ? 1 : 0;
        }
    }
}
$md[] = "Attack cells with attacker reach identical to S0 (omitted above): $identical.";
$md[] = '';

$md[] = '## 4b. The LOW (UA-only) cross-IP scope options (OD-1; same pinned patched file, options selected by test-only injectors — `d07_low_scope`)';
$md[] = '';
$md[] = '`advisory` = EQL (no state, no enforcement). `narrow` = enforce only the scored (evidence-bearing) request. `wide` = enforce population-wide while fresh evidence exists (= variant EQW\'s LOW rule). Cells: attacker reach/attempts · legit denied[by correlation]/attempts. Control = reach with correlation disabled.';
$md[] = '';
$md[] = '| Stream | class | control reach | S0 | advisory (EQL) | narrow | wide (EQW rule) |';
$md[] = '|---|---|---|---|---|---|---|';
$narrowAdds = [];
foreach (['L3', 'D1', 'D3', 'D4', 'X2', 'X7', 'X2L', 'X8', 'Y2c'] as $id) {
    $line = "| $id | " . ($D7['s0'][$id]['class'] ?? '?') . ' | ' . ($D7['s0'][$id]['controlReach'] ?? '?') . ' |';
    foreach (['s0', 'advisory', 'narrow', 'wide'] as $opt) {
        $r = $D7[$opt][$id] ?? null;
        $line .= $r === null ? ' - |' : ' ' . $r['attackReach'] . '/' . $r['attackAttempts'] . ' · ' . $r['legitDenied'] . '[' . $r['legitDeniedByCorrelation'] . ']/' . $r['legitAttempts'] . ' |';
    }
    $md[] = $line;
    if (isset($D7['narrow'][$id]) && $D7['narrow'][$id]['attackAttempts'] > 0 && $D7['narrow'][$id]['attackReach'] < $D7['narrow'][$id]['controlReach']) {
        $narrowAdds[] = $id;
    }
}
$md[] = '';
$md[] = 'Streams where `narrow` protects better than the no-correlation control: ' . ($narrowAdds === [] ? '**none**' : implode(', ', $narrowAdds)) . '. Legitimate denials by correlation under `narrow` (D1): ' . ($D7['narrow']['D1']['legitDeniedByCorrelation'] ?? '?') . ' of ' . ($D7['narrow']['D1']['legitAttempts'] ?? '?') . '; under `wide` (D1): ' . ($D7['wide']['D1']['legitDeniedByCorrelation'] ?? '?') . '; under `advisory`: ' . ($D7['advisory']['D1']['legitDeniedByCorrelation'] ?? '?') . '.';
$md[] = '';
$md[] = '## 4c. API_OVERUSE shortfall: S0 vs EQL vs EQV (bounded alternative; pre+out; reach = attacker attempts reaching verification, legit = denied[by correlation]/attempts; control = reach with correlation disabled)';
$md[] = '';
$md[] = '| Stream | Shape | control reach | S0 | EQL | EQV |';
$md[] = '|---|---|---|---|---|---|';
foreach (['api', 'api_custom'] as $shape) {
    foreach (['L1', 'L2', 'D1', 'D2', 'X4', 'Y4e', 'X1', 'X3', 'Y7', 'D5', 'D3'] as $id) {
        $s0c = $cell('S0', $id, $shape, 'pre+out');
        if ($s0c === null) {
            continue;
        }
        $line4 = "| $id | $shape | {$s0c['controlAttackReachedVerification']} |";
        foreach (['S0', 'EQL', 'EQV'] as $v4) {
            $c4 = $cell($v4, $id, $shape, 'pre+out');
            $line4 .= ' ' . $c4['attackReachedVerification'] . '/' . $c4['attackAttempts'] . ' · ' . $c4['legitDenied'] . '[' . $c4['legitDeniedByCorrelation'] . ']/' . $c4['legitAttempts'] . ' |';
        }
        $md[] = $line4;
    }
}
$md[] = '';
$md[] = '## 5. Recovery, renewal and boundaries (D02, controlled clock)';
$md[] = '';
$md[] = '| Measurement | S0 | EQN | EQW | EQL | EQV |';
$md[] = '|---|---|---|---|---|---|';
$line = static function (string $label, callable $f) use ($variants): string {
    $s = "| $label |";
    foreach ($variants as $v) {
        $s .= ' ' . $f($v) . ' |';
    }

    return $s;
};
$md[] = $line('B1 lone legit first allowed (after a 3-fp failing trigger at t=0..2)', fn($v) => 't=' . ($row($v, 'd02_recovery_boundaries', 'D02.B1.lone-legit-after-trigger')['legitFirstAllowedT'] ?? '?'));
$md[] = $line('B5 single seed then silence: legit denied / last denial', fn($v) => ($row($v, 'd02_recovery_boundaries', 'D02.B5.single-seed-then-silence')['legitDenied'] ?? '?') . ' of 300 / t=' . ($row($v, 'd02_recovery_boundaries', 'D02.B5.single-seed-then-silence')['lastLegitDenialT'] ?? '?'));
$md[] = $line('B5 block issuances (cycles) over 1500 s', fn($v) => (string) ($row($v, 'd02_recovery_boundaries', 'D02.B5.single-seed-then-silence')['blockIssuances'] ?? '?'));
$md[] = $line('B4 persistent attacker 0..130 s: verifications reached', fn($v) => (string) ($row($v, 'd02_recovery_boundaries', 'D02.B4.persistent-attacker-reissue')['attackerReached'] ?? '?'));
$md[] = $line('B8 persistent attacker 30 min: verifications reached / block issuances', fn($v) => ($row($v, 'd02_recovery_boundaries', 'D02.B8.sustained-leak-30min')['reachedVerification'] ?? '?') . ' / ' . ($row($v, 'd02_recovery_boundaries', 'D02.B8.sustained-leak-30min')['blockIssuances'] ?? '?'));
$md[] = $line('B7 NAT, stale set of successes + ONE typo: legit denials, last denial', fn($v) => ($row($v, 'd02_recovery_boundaries', 'D02.B7.single-typo-on-stale-set')['legitDenied'] ?? '?') . ' of 84, t=' . ($row($v, 'd02_recovery_boundaries', 'D02.B7.single-typo-on-stale-set')['lastDeniedT'] ?? '?'));
$md[] = $line('B6 evidence boundary: probe blocked at +1/+30/+59/+60/+61/+120 s', function ($v) use ($row) {
    $p = $row($v, 'd02_recovery_boundaries', 'D02.B6.evidence-boundary')['probeBlockedAtPlusK'] ?? [];

    return implode('', array_map(static fn($k): string => ($p[$k] ?? null) === true ? 'B' : (($p[$k] ?? null) === false ? 'a' : '?'), [1, 30, 59, 60, 61, 120]));
});
$md[] = '';
$md[] = 'B2/B3 rows (set-TTL and N-1 WATCH boundaries) are asserted in `gates.jsonl` G9 and in `expected/d02_recovery_boundaries.*.jsonl`.';
$md[] = '';

$md[] = '## 6. Redis / store cost (correlation-store API calls per command; the Redis adapter issues one atomic script per call)';
$md[] = '';
$md[] = '| Case | S0 | EQN | EQW | EQL | EQV |';
$md[] = '|---|---|---|---|---|---|';
foreach (['legit-success-pre+out', 'legit-typo-pre+out', 'low-success-pre+out', 'three-failing-fingerprints-pre+out'] as $case) {
    $md[] = $line($case, function ($v) use ($A, $case) {
        foreach ($A[$v]['d04_state_bounds_ops'] ?? [] as $r) {
            if ($r['s'] === 'D04.O1.ops' && $r['case'] === $case) {
                return (string) $r['total'];
            }
        }

        return '?';
    });
}
foreach (['S1' => 'S1 3000 failing unique anchors', 'S2' => 'S2 3000 successful unique anchors', 'S3' => 'S3 one K2, 300 fingerprints'] as $sid => $label) {
    $md[] = $line("$label: live entries at peak (sets+markers) / per request", function ($v) use ($row, $sid) {
        $r = $row($v, 'd04_state_bounds_ops', "D04.$sid");

        return $r === null ? '?' : ($r['peak']['sets'] + $r['peak']['flags']) . ' / ' . $r['entriesPerRequest'];
    });
}
$md[] = '';

$md[] = '## 7. Real Redis (D05)';
$md[] = '';
if ($redisStatus !== 'RAN') {
    $md[] = 'The real-Redis stage did not run: every real-Redis claim is **UNPROVEN**.';
} else {
    $md[] = '| Check | S0 | EQN | EQW | EQL | EQV |';
    $md[] = '|---|---|---|---|---|---|';
    foreach (['R3: in every round' => 'R3 concurrent distinct members: K2 block in all 12 rounds', 'R4: 8 concurrent' => 'R4 8 concurrent duplicates: no block', 'R5:' => 'R5 marker counts exactly 8 writers', 'R2: every key' => 'R2 TTL on every key', 'R6: a lone legitimate' => 'R6 live recovery at 63 s (Redis clock)'] as $needle => $label) {
        $md[] = $line($label, function ($v) use ($assertOk, $needle) {
            $r = $assertOk($v, 'd05_redis', $needle);

            return $r === null ? 'n/a' : ($r ? 'PASS' : 'FAIL');
        });
    }
}
$md[] = '';

$md[] = '## 8. Reference: published PR #104 candidates (read from `../expected`, NOT re-run here)';
$md[] = '';
$md[] = '| Stream (Login pre+out) | S0 | #104 RL | #104 PW | #104 P2 | #104 A1 | EQL (this design) |';
$md[] = '|---|---|---|---|---|---|---|';
$ref = [];
foreach (['S0', 'RL', 'PW', 'P2', 'A1'] as $rv) {
    foreach (file("$published/a01_matrix.$rv.jsonl", FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        $r = json_decode($l, true);
        if (($r['s'] ?? '') === 'A01.matrix' && $r['shape'] === 'login' && $r['flow'] === 'pre+out') {
            $ref[$rv][$r['stream']] = $r;
        }
    }
}
foreach (['L1', 'L2', 'L3', 'X1', 'X2', 'X7', 'X8', 'Y6b', 'Y7', 'Y9b'] as $id) {
    $s = "| $id |";
    foreach (['S0', 'RL', 'PW', 'P2', 'A1'] as $rv) {
        $r = $ref[$rv][$id] ?? null;
        $s .= ' ' . ($r === null ? '-' : 'reach ' . $r['attackReachedVerification'] . '/' . $r['attackAttempts'] . ', legit ' . $r['legitDenied'] . '/' . $r['legitAttempts']) . ' |';
    }
    $e = $cell('EQL', $id, 'login', 'pre+out');
    $s .= ' ' . ($e === null ? '-' : 'reach ' . $e['attackReachedVerification'] . '/' . $e['attackAttempts'] . ', legit ' . $e['legitDenied'] . '/' . $e['legitAttempts']) . ' |';
    $md[] = $s;
}
$md[] = '';
file_put_contents("$out/summary.md", rtrim(implode("\n", $md)) . "\n");
echo 'OK: ' . count($gates) . " gate rows\n";
