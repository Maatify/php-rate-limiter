<?php

declare(strict_types=1);

// Gate evaluation + summary generator for Workstream B / Anti-Evasion. Reads results/actual/<scenario>.<variant>.jsonl produced by
// run.sh for S0, A1, PW, P2 and writes:
//   <out>/gates.jsonl   machine-readable gate results (one row per variant x gate) — golden-diffed by run.sh
//   <out>/summary.md    human-readable tables generated from the SAME data (no hand-typed numbers)
// Gate vocabulary: PASS | FAIL | UNPROVEN | NOT_APPLICABLE. Thresholds are NEVER invented: every comparison is against S0 or against
// a stated contract; where only an Owner could choose a limit the row is flagged ownerDecision=true.
require __DIR__ . '/fixtures/anti_evasion.php';

// Required evidence is validated explicitly below (F104-02); the narrative tables are generated afterwards and may probe optional cells,
// so PHP's missing-offset warnings are not allowed to leak into stderr.
error_reporting(E_ALL & ~E_WARNING);

$dir = $argv[1] ?? (__DIR__ . '/results/actual');
$out = $argv[2] ?? $dir;
$variants = ['S0', 'A1', 'PW', 'P2', 'RL'];
$candidates = ['A1', 'PW', 'P2', 'RL'];

/** @var list<string> evidence problems (missing / malformed required input): any entry makes the report exit non-zero (F104-02) */
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
        return []; // a damaged file proves nothing: every claim that needs it becomes UNPROVEN
    }
    if ($r === []) {
        $problems[] = "empty evidence file $scenario.$variant.jsonl";
    }

    return $r;
}

/** run.sh writes RAN or SKIPPED (REDIS_SPOTCHECK=0 / redis missing). An intentional skip is NOT a problem but blocks every real-Redis claim. */
$redisStatusFile = "$dir/redis_status.txt";
$redisStatus = is_file($redisStatusFile) ? trim((string) file_get_contents($redisStatusFile)) : 'UNKNOWN';
if (! in_array($redisStatus, ['RAN', 'SKIPPED'], true)) {
    $problems[] = 'missing or invalid redis_status.txt (run.sh must record RAN or SKIPPED)';
}

$M = []; // [variant][stream][shape][flow] => row
$A = []; // [variant][scenario-prefix] => rows
foreach ($variants as $v) {
    foreach (rows($dir, 'a01_matrix', $v) as $r) {
        if ($r['s'] === 'A01.matrix') {
            $M[$v][$r['stream']][$r['shape']][$r['flow']] = $r;
        }
    }
    foreach (['a02_boundaries', 'a03_rotation_ephemeral_backend', 'a04_state_bounds', 'a05_redis', 'a06_indistinguishability', 'a07_dec003_behavior'] as $sc) {
        if ($sc === 'a05_redis' && $redisStatus !== 'RAN') {
            continue;
        }
        foreach (rows($dir, $sc, $v) as $r) {
            $A[$v][$sc][] = $r;
        }
    }
}
$assertOk = static function (array $rows, string $needle) use (&$A): ?bool {
    foreach ($rows as $r) {
        if (($r['assert'] ?? null) !== null && str_contains($r['assert'], $needle)) {
            return (bool) $r['ok'];
        }
    }

    return null;
};
$gate = [];
$emitGate = static function (string $variant, string $gateId, string $status, string $evidence, array $extra = []) use (&$gate): void {
    $gate[] = ['variant' => $variant, 'gate' => $gateId, 'status' => $status] + $extra + ['evidence' => $evidence];
};
$get = static fn(string $v, string $stream, string $shape, string $flow): ?array => $M[$v][$stream][$shape][$flow] ?? null;

/** Core numeric fields every A01 cell must carry (F104-02): presence AND type/consistency are validated, not just the row. */
const WSB_CELL_INT_FIELDS = ['attackAttempts', 'attackReachedVerification', 'attackDenied', 'attackDeniedByCorrelation', 'attackDeniedOther', 'legitAttempts', 'legitDenied', 'legitDeniedByCorrelation', 'legitDeniedOther', 'controlLegitDenied', 'controlAttackReachedVerification', 'blockIssuancesTotal'];

/** @return list<string> problems of one cell: missing/non-integer/negative fields or arithmetic inconsistency */
function wsbCellProblems(array $c): array
{
    $p = [];
    foreach (WSB_CELL_INT_FIELDS as $f) {
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

/** @return list<string> required matrix cells missing or invalid for a variant */
$missingCells = static function (string $v) use (&$M): array {
    $m = [];
    foreach (wsbMatrixCells() as [$st, $sh, $fl]) {
        if (! isset($M[$v][$st][$sh][$fl])) {
            $m[] = "$st/$sh/$fl";
            continue;
        }
        $bad = wsbCellProblems($M[$v][$st][$sh][$fl]);
        if ($bad !== []) {
            $m[] = "$st/$sh/$fl: invalid " . implode(',', $bad);
        }
    }

    return $m;
};
/** @return list<string> required assertion fragments / row ids absent from a scenario's rows (a present-but-failed assertion is NOT missing) */
$missingIds = static function (array $rows, array $needles, bool $asRowId = false): array {
    $m = [];
    foreach ($needles as $n) {
        $found = false;
        foreach ($rows as $r) {
            $hay = $asRowId ? ($r['s'] ?? '') : ($r['assert'] ?? '');
            if ($hay !== '' && str_contains($hay, $n)) {
                $found = true;
                break;
            }
        }
        if (! $found) {
            $m[] = $n;
        }
    }

    return $m;
};
$incomplete = [];
/** Missing required evidence turns the gate into UNPROVEN (never PASS) and is reported by the exit code. */
$guard = static function (string $variant, string $gateId, array $missing, string $why = '') use (&$gate, &$incomplete): bool {
    if ($missing === []) {
        return true;
    }
    $gate[] = ['variant' => $variant, 'gate' => $gateId, 'status' => 'UNPROVEN', 'evidence' => 'REQUIRED EVIDENCE MISSING: ' . implode('; ', array_slice($missing, 0, 6)) . (count($missing) > 6 ? ' (+' . (count($missing) - 6) . ' more)' : '') . ($why !== '' ? " — $why" : '')];
    $incomplete[$variant . '|' . $gateId] = $missing;

    return false;
};

$evasionStreams = array_values(array_filter(wsbPrimaryOrder(), static fn(string $id): bool => in_array(wsbClass($id), ['evasion', 'both'], true)));
$poisonPaid = ['X6', 'Y4c', 'Y4e', 'Y4f', 'Y6c'];     // attacker pays only free failures
$benign = ['L1', 'L1f', 'L2', 'L3', 'L4'];

foreach ($candidates as $v) {
    // ---- A. Contract MUST-PRESERVE ----------------------------------------------------------------------------------------------
    // Every gate first validates that its REQUIRED evidence exists (F104-02): absent/partial input => UNPROVEN + nonzero exit, never PASS.
    $a = $A[$v]['a03_rotation_ephemeral_backend'] ?? [];
    $cellMiss = array_merge(array_map(static fn(string $c): string => "S0:$c", $missingCells('S0')), array_map(static fn(string $c): string => "$v:$c", $missingCells($v)));
    $guardSkip = static function (string $gateId, string $why) use (&$gate, $v): void {
        $gate[] = ['variant' => $v, 'gate' => $gateId, 'status' => 'UNPROVEN', 'evidence' => "INTENTIONALLY NOT RUN: $why"];
    };

    $g = 'A.1 rotation continuity (DECISION_MATRIX §5.0, KEY_STRATEGY: previous state must not become a current-only reset)';
    if ($guard($v, $g, $missingIds($a, ['current/previous rotation keeps the evidence/count continuous']))) {
        $rot = $assertOk($a, 'current/previous rotation keeps the evidence/count continuous');
        $emitGate($v, $g, $rot ? 'PASS' : 'FAIL', 'A03.R1 pair split across an outer-key rotation must still be convicted at the third member');
    }

    $b = $A[$v]['a02_boundaries'] ?? [];
    $watch = static function (array $rows, string $flow, int $t): ?bool {
        foreach ($rows as $r) {
            if ($r['s'] === 'A02.B3.watch-boundary' && $r['flow'] === $flow && $r['secondPairEndsAt'] === $t) {
                return $r['blockedAtSecondPairEnd'];
            }
        }

        return null;
    };
    $s0w = [$watch($A['S0']['a02_boundaries'] ?? [], 'out', 1800), $watch($A['S0']['a02_boundaries'] ?? [], 'out', 1801)];
    $vw = [$watch($b, 'out', 1800), $watch($b, 'out', 1801)];
    $g = 'A.2 near-threshold WATCH keeps blocking "hover at N-1" (DECISION_MATRIX §5.0)';
    $watchMissing = [];
    foreach (['S0' => $s0w, $v => $vw] as $who => $pair) {
        foreach ($pair as $i => $x) {
            if ($x === null) {
                $watchMissing[] = "$who A02.B3 record-only " . [1800, 1801][$i];
            }
        }
    }
    if ($guard($v, $g, $watchMissing)) {
        $emitGate($v, $g, $s0w === $vw ? 'PASS' : 'FAIL', 'A02.B3 record-only: S0=' . json_encode($s0w) . ' variant=' . json_encode($vw) . ' (second pair ending at 1800 / 1801 s)', ['ownerDecision' => $s0w !== $vw]);
    }

    $g = 'A.3 backend failures propagate (never ALLOW / silent skip)';
    $needF = ['F1a.churn-set-write'];
    if (in_array($v, ['PW', 'P2', 'RL'], true)) {
        $needF[] = 'F1b.witness-write';
    }
    if ($guard($v, $g, $missingIds($a, $needF))) {
        $f1a = $assertOk($a, 'F1a.churn-set-write');
        $f1b = $assertOk($a, 'F1b.witness-write');
        $emitGate($v, $g, ($f1a === true && ($f1b === null || $f1b === true)) ? 'PASS' : 'FAIL', 'A03.F1a=' . json_encode($f1a) . ' F1b=' . json_encode($f1b));
    }

    $g = 'A.4 bounded storage: declared caps, TTL on every key, no key explosion beyond linear';
    $a04 = $A[$v]['a04_state_bounds'] ?? [];
    $redis = $A[$v]['a05_redis'] ?? [];
    $m4 = $missingIds($a04, ['S1 negative control', 'S1 declared caps', 'S1 TTL hygiene', 'S2 negative control', 'S2 declared caps', 'S3 negative control', 'S3 declared caps', 'S3 total growth', 'S3 TTL hygiene']);
    if ($redisStatus !== 'RAN') {
        $guardSkip($g, 'real-Redis stage not run (status ' . $redisStatus . '; REDIS_SPOTCHECK=0 or redis missing); the Redis half of this claim is UNPROVEN');
    } elseif ($guard($v, $g, array_merge($m4, $missingIds($redis, ['R1:', 'R2:', 'R3 negative control', 'R3: largest set', 'R5 negative control', 'R5: concurrent distinct']))) ) {
        $capFail = 0;
        foreach ($a04 as $r) {
            if (isset($r['assert']) && ! $r['ok']) {
                $capFail++;
            }
        }
        $redisFail = 0;
        foreach ($redis as $r) {
            if (isset($r['assert']) && ! $r['ok'] && ! str_starts_with($r['assert'], 'R4')) {
                $redisFail++;
            }
        }
        $emitGate($v, $g, ($capFail === 0 && $redisFail === 0) ? 'PASS' : 'FAIL', "A04 failed assertions=$capFail, A05 (R1-R3,R5) failed assertions=$redisFail");
    }

    $g = 'A.5 atomic evidence under concurrency (real Redis, 8 concurrent writers)';
    if ($redisStatus !== 'RAN') {
        $guardSkip($g, 'real-Redis stage not run (status ' . $redisStatus . '); concurrency is UNPROVEN');
    } elseif ($v === 'RL') {
        $emitGate($v, $g, 'UNPROVEN', 'RL latch races (a duplicate mis-flagged as fresh defeats the latch, i.e. fails toward S0) need a post-expiry concurrent test; Redis owns time so it cannot be driven deterministically here');
    } elseif (in_array($v, ['PW', 'P2'], true)) {
        if ($guard($v, $g, $missingIds($redis, ['R4:']))) {
            $r4 = $assertOk($redis, 'R4:');
            $emitGate($v, $g, $r4 ? 'PASS' : 'FAIL', 'A05.R4 duplicate-member race', ['ownerDecision' => $r4 === false, 'note' => 'requires an atomic "added" signal = store-contract change if it must be fixed']);
        }
    } else {
        $emitGate($v, $g, 'NOT_APPLICABLE', 'no read-then-increment evidence tally in this variant');
    }

    $g = 'A.6 Public Command Lifecycle coverage kept (record-only, check-only, API_OVERUSE recordSuccess-only hosts)';
    if ($guard($v, $g, $cellMiss)) {
        $cov = [];
        foreach ([['X2', 'login', 'out'], ['X2', 'login', 'pre'], ['X2', 'api', 'out'], ['X1', 'api', 'out']] as [$st, $sh, $fl]) {
            $r0 = $get('S0', $st, $sh, $fl);
            $rv = $get($v, $st, $sh, $fl);
            $cov["$st/$sh/$fl"] = ['S0' => $r0['attackDenied'] . '/' . $r0['attackAttempts'], $v => $rv['attackDenied'] . '/' . $rv['attackAttempts']];
        }
        $lost = array_filter($cov, static fn(array $x): bool => (int) explode('/', $x[$v])[0] < (int) explode('/', $x['S0'])[0]);
        $emitGate($v, $g, $lost === [] ? 'PASS' : 'FAIL', json_encode($cov), ['lostIn' => array_keys($lost)]);
    }

    // A.7 — DEC-003 proven by BEHAVIOUR (A07: cycle transition, 21600 s window, 2-cycle / 600 s pause, active-block ordering, Current/Previous).
    $g = 'A.7 DEC-003 behaviour: cycle transitions, decay pause, active-block ordering, Current/Previous continuity (A07, controlled clock)';
    $a07 = $A[$v]['a07_dec003_behavior'] ?? [];
    $m7 = $missingIds($a07, ['D1 behavioural negative control', 'D1 every issued K2 block', 'D1 pause activations', 'D1 elapsed paused', 'D1 independent histories', 'D2 negative control', 'D2 active persisted block', 'D3 negative control', 'D4 a single failing attempt']);
    if ($guard($v, $g, $m7)) {
        $bad = array_values(array_filter($a07, static fn(array $r): bool => isset($r['assert']) && ! $r['ok']));
        $d3 = $missingIds($a07, ['D3 Current/Previous are one logical cycle history']) === [];
        if ($bad !== []) {
            $emitGate($v, $g, 'FAIL', 'violated: ' . implode('; ', array_map(static fn(array $r): string => substr($r['assert'], 0, 60), $bad)), ['ownerDecision' => true]);
        } elseif (! $d3) {
            $emitGate($v, $g, 'UNPROVEN', 'D1/D2/D4 pass; Current/Previous continuity (D3) could not be exercised: the variant issued no block after rotation');
        } else {
            $emitGate($v, $g, 'PASS', 'D1 transition-only cycles + pause = reference derived from runtime cycle times, D2 no state mutation under an active block, D3 one logical history across rotation, D4 L1/single failure creates no cycle');
        }
    }
    // Limited structural scope check — explicitly NOT a DEC-003 proof (F104-03).
    $patchText = (string) file_get_contents(__DIR__ . '/patches/wsb-candidates.patch');
    $touches = preg_match('/^[+-].*(blockWithCycleTracking|persistWinningCandidates|checkActiveBlocks|buildKeys)/m', $patchText) === 1;
    $gate[] = ['variant' => $v, 'gate' => 'A.7s structural scope of the patch (limited; NOT a DEC-003 proof)', 'status' => 'INFO', 'evidence' => $touches ? 'patch text references a persistence / key function name' : 'patch text adds/removes no line naming blockWithCycleTracking / persistWinningCandidates / checkActiveBlocks / buildKeys; this proves at most that those names were not textually changed'];

    $emitGate($v, 'A.8 normative DECISION_MATRIX §5.3 text unchanged', 'FAIL', 'every candidate changes WHEN a triggered §5.3 rule enforces (evidence gating / LOW exemption / recovery latch); adopting it needs a contract version bump', ['ownerDecision' => true]);

    // ---- B/C/D need the COMPLETE matrix for S0 and the variant (F104-02) ------------------------------------------------------------
    $matrixOk = $guard($v, 'B. security regression: evasion traffic that S0 stops but the variant lets through', $cellMiss);
    $guard($v, 'C. fairness: legitimate users denied by correlation while the attacker pays only free failures / botnet traffic', $cellMiss);
    $guard($v, 'D. residual: attack streams that fully reach verification under S0 AND the variant', $cellMiss);
    if (! $matrixOk) {
        continue;
    }
    // ---- B. Security regression vs S0 -------------------------------------------------------------------------------------------
    // pre+out / pre: an attacker attempt "reaches verification" when checkOnly lets it through, so MORE reach than S0 = lost protection.
    // out (record-only Host): the Host has verified every attempt before recording, so reach is always full; the protection is the
    // denial signal itself, so FEWER denied attacker attempts than S0 = lost protection.
    $regress = [];
    $worst = 0;
    foreach ($evasionStreams as $st) {
        foreach (['login' => ['pre+out', 'out', 'pre'], 'login-anon' => ['pre+out', 'out'], 'otp' => ['pre+out', 'out'], 'api' => ['pre+out', 'out'], 'api_custom' => ['pre+out', 'out']] as $shape => $flows) {
            foreach ($flows as $fl) {
                $r0 = $get('S0', $st, $shape, $fl);
                $rv = $get($v, $st, $shape, $fl);
                if ($r0 === null || $rv === null) {
                    continue;
                }
                if ($fl === 'out') {
                    $d = $r0['attackDenied'] - $rv['attackDenied'];
                    if ($d > 0) {
                        $regress["$st/$shape/$fl"] = ['metric' => 'attackerDenied', 'S0' => $r0['attackDenied'], $v => $rv['attackDenied'], 'of' => $rv['attackAttempts']];
                        $worst = max($worst, $d);
                    }
                } else {
                    $d = $rv['attackReachedVerification'] - $r0['attackReachedVerification'];
                    if ($d > 0) {
                        $regress["$st/$shape/$fl"] = ['metric' => 'attackerReach', 'S0' => $r0['attackReachedVerification'], $v => $rv['attackReachedVerification'], 'of' => $rv['attackAttempts']];
                        $worst = max($worst, $d);
                    }
                }
            }
        }
    }
    $emitGate($v, 'B. security regression: evasion traffic that S0 stops but the variant lets through', $regress === [] ? 'PASS' : 'FAIL', count($regress) . ' (stream/shape/flow) cells with weaker protection than S0; worst delta ' . $worst . ' attacker attempts', ['cells' => count($regress), 'worstDelta' => $worst, 'ownerDecision' => $regress !== [], 'detail' => $regress]);

    // ---- C. Legitimate-user fairness -------------------------------------------------------------------------------------------
    $benefit = [];
    foreach ($benign as $st) {
        $r0 = $get('S0', $st, 'login', 'pre+out');
        $rv = $get($v, $st, 'login', 'pre+out');
        $benefit[$st] = ['S0' => $r0['legitDenied'] . '/' . $r0['legitAttempts'], $v => $rv['legitDenied'] . '/' . $rv['legitAttempts'], 'viaCorrelation' => $rv['legitDeniedByCorrelation']];
    }
    $restored = [];
    foreach ($poisonPaid as $st) {
        foreach (['pre+out', 'out'] as $fl) {
            $rv = $get($v, $st, 'login', $fl);
            $r0 = $get('S0', $st, 'login', $fl);
            if ($rv !== null && $rv['legitDeniedByCorrelation'] > 0) {
                $restored["$st/$fl"] = ['S0' => $r0['legitDeniedByCorrelation'] . '/' . $r0['legitAttempts'], $v => $rv['legitDeniedByCorrelation'] . '/' . $rv['legitAttempts']];
            }
        }
    }
    $x8 = $get($v, 'X8', 'login', 'pre+out');
    if ($x8 !== null && $x8['legitDeniedByCorrelation'] > 0) {
        $restored['X8/login/pre+out'] = ['S0' => $get('S0', 'X8', 'login', 'pre+out')['legitDeniedByCorrelation'] . '/50', $v => $x8['legitDeniedByCorrelation'] . '/50'];
    }
    $emitGate($v, 'C. fairness: legitimate users denied by correlation while the attacker pays only free failures / botnet traffic', $restored === [] ? 'PASS' : 'FAIL', count($restored) . ' poisoning cells still deny legitimate users through correlation', ['cells' => count($restored), 'ownerDecision' => $restored !== [], 'benignBenefit' => $benefit, 'detail' => $restored]);

    // C2 (information): user-visible denial totals under paid poisoning, correlation-attributable or not (K1 score / credential spray
    // collateral is outside every candidate's reach and is reported, not hidden).
    $tot = [];
    foreach ($poisonPaid as $st) {
        foreach (['pre+out', 'out'] as $fl) {
            $r0 = $get('S0', $st, 'login', $fl);
            $rv = $get($v, $st, 'login', $fl);
            if ($r0 !== null && $rv !== null) {
                $tot["$st/$fl"] = ['S0' => $r0['legitDenied'] . '/' . $r0['legitAttempts'], $v => $rv['legitDenied'] . '/' . $rv['legitAttempts'], 'controlNoCorrelation' => $rv['controlLegitDenied']];
            }
        }
    }
    $gate[] = ['variant' => $v, 'gate' => 'C2 user-visible legitimate denials under paid poisoning (all causes)', 'status' => 'INFO', 'evidence' => 'controlNoCorrelation = denials that remain with churn/dilution correlation disabled (K1 score / credential-spray collateral)', 'detail' => $tot];

    // ---- D. Residual threats (undetected by BOTH S0 and the variant) ------------------------------------------------------------
    $resid = [];
    foreach ($evasionStreams as $st) {
        $r0 = $get('S0', $st, 'login', 'pre+out');
        $rv = $get($v, $st, 'login', 'pre+out');
        if ($r0 !== null && $rv !== null && $r0['attackAttempts'] > 0 && $r0['attackReachedVerification'] === $r0['attackAttempts'] && $rv['attackReachedVerification'] === $rv['attackAttempts']) {
            $resid[] = $st;
        }
    }
    $emitGate($v, 'D. residual: attack streams that fully reach verification under S0 AND the variant', 'PASS', 'residual (not separable from the available signals): ' . implode(', ', $resid), ['residualStreams' => $resid, 'note' => 'status PASS = analysis complete, not "no residual threat"']);
}

$indMissing = [];
foreach ($variants as $v) {
    $a06 = $A[$v]['a06_indistinguishability'] ?? [];
    $pairs = count(array_filter($a06, static fn(array $r): bool => ($r['s'] ?? '') === 'A06.pair'));
    if ($pairs !== 4) {
        $indMissing[] = "$v: $pairs of 4 A06 pairs";
    }
    // The verdict rests on the four ASSERTIONS (digest equality + identical treatment), not on the pair records alone.
    foreach ($missingIds($a06, ['indistinguishable: I1', 'indistinguishable: I2', 'indistinguishable: I3', 'indistinguishable: I4']) as $n) {
        $indMissing[] = "$v: assertion '$n' absent";
    }
}
if ($indMissing !== []) {
    $gate[] = ['variant' => 'ALL', 'gate' => 'D2 indistinguishability: attacker and legitimate streams with identical observable tuples', 'status' => 'UNPROVEN', 'evidence' => 'REQUIRED EVIDENCE MISSING: ' . implode('; ', $indMissing)];
    $incomplete['ALL|D2'] = $indMissing;
} else {
    $indOk = true;
    foreach ($variants as $v) {
        foreach ($A[$v]['a06_indistinguishability'] as $r) {
            $indOk = $indOk && ! (isset($r['assert']) && ! $r['ok']);
        }
    }
    $gate[] = ['variant' => 'ALL', 'gate' => 'D2 indistinguishability: attacker and legitimate streams with identical observable tuples', 'status' => $indOk ? 'PASS' : 'FAIL', 'evidence' => '4 pairs with equal observable digests receive identical treatment on every variant (no policy over these inputs can separate them)'];
}

// ---- Offline prediction vs runtime fidelity (PR #103 model vs the experimental runtime) -------------------------------------------
$modelFile = dirname(__DIR__) . '/workstream-b-correlation/expected/model_matrix.jsonl';
$fidelity = [];
if (is_file($modelFile)) {
    foreach (file($modelFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $m = json_decode($l, true);
        if (($m['flow'] ?? '') !== 'pre+out' || ! in_array($m['policy'], ['A1', 'PW', 'P2'], true)) {
            continue;
        }
        $id = explode(' ', $m['stream'])[0];
        $rv = $get($m['policy'], $id, 'login', 'pre+out');
        if ($rv === null) {
            continue;
        }
        $same = $m['legitBlocked'] === $rv['legitDenied'] . '/' . $rv['legitAttempts'] && $m['attackBlocked'] === $rv['attackDenied'] . '/' . $rv['attackAttempts'] && $m['attackReachedVerification'] === $rv['attackReachedVerification'];
        $fidelity[$m['policy']][$id] = ['model' => "{$m['legitBlocked']} {$m['attackBlocked']} reach{$m['attackReachedVerification']}", 'runtime' => "{$rv['legitDenied']}/{$rv['legitAttempts']} {$rv['attackDenied']}/{$rv['attackAttempts']} reach{$rv['attackReachedVerification']}", 'same' => $same];
    }
}
foreach ($fidelity as $p => $rowsF) {
    $diff = array_keys(array_filter($rowsF, static fn(array $x): bool => ! $x['same']));
    $gate[] = ['variant' => $p, 'gate' => 'offline-vs-runtime fidelity (PR #103 model prediction vs experimental runtime, login pre+out)', 'status' => 'INFO', 'evidence' => count($rowsF) . ' streams compared; identical=' . (count($rowsF) - count($diff)) . '; different=' . implode(',', $diff), 'detail' => $rowsF];
}

@mkdir($out, 0777, true);
$problemLines = array_merge($problems, array_map(static fn(string $k, array $m): string => "incomplete gate $k: " . implode(', ', array_slice($m, 0, 4)), array_keys($incomplete), $incomplete));
file_put_contents("$out/evidence_problems.txt", $problemLines === [] ? "none\n" : implode("\n", $problemLines) . "\n");
$fh = fopen("$out/gates.jsonl", 'w');
foreach ($gate as $g) {
    fwrite($fh, json_encode($g, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
}
fclose($fh);

// ---- summary.md ------------------------------------------------------------------------------------------------------------------
$md = "# Generated summary (do not edit; produced by report.php from results/actual)\n\n";
$md .= "## Gate status per candidate\n\n| Gate | " . implode(' | ', $candidates) . " |\n|---|" . str_repeat('---|', count($candidates)) . "\n";
$byGate = [];
foreach ($gate as $g) {
    if (in_array($g['variant'], $candidates, true) && $g['status'] !== 'INFO') {
        $byGate[$g['gate']][$g['variant']] = $g['status'] . (! empty($g['ownerDecision']) ? ' (owner)' : '');
    }
}
foreach ($byGate as $name => $st) {
    $md .= '| ' . $name . ' | ' . implode(' | ', array_map(static fn(string $c): string => $st[$c] ?? '-', $candidates)) . " |\n";
}
$md .= "\n## Attack-vs-legitimate matrix (Login with account id, flow pre+out)\n\nCell = `legit denied/legit attempts (of which attributable to correlation)` · `attacker denied/attempts` · `attacker attempts that reached verification`.\n\n| Stream | class | " . implode(' | ', $variants) . " |\n|---|---|" . str_repeat('---|', count($variants)) . "\n";
foreach (wsbPrimaryOrder() as $st) {
    $cells = [];
    foreach ($variants as $v) {
        $r = $get($v, $st, 'login', 'pre+out');
        $cells[] = $r === null ? '-' : "L {$r['legitDenied']}/{$r['legitAttempts']} (c{$r['legitDeniedByCorrelation']}) · A {$r['attackDenied']}/{$r['attackAttempts']} · reach {$r['attackReachedVerification']}";
    }
    $md .= "| $st | " . wsbClass($st) . ' | ' . implode(' | ', $cells) . " |\n";
}
foreach ([['login', 'out', 'Record-only Host (recordFailure/recordSuccess only)'], ['login', 'pre', 'Check-only Host (checkOnly only)'], ['api', 'out', 'API_OVERUSE Host (recordSuccess only, no account)'], ['otp', 'pre+out', 'OTP policy, pre+out']] as [$shape, $fl, $title]) {
    $md .= "\n## $title (shape=$shape, flow=$fl)\n\n| Stream | class | " . implode(' | ', $variants) . " |\n|---|---|" . str_repeat('---|', count($variants)) . "\n";
    foreach (wsbPrimaryOrder() as $st) {
        $cells = [];
        $any = false;
        foreach ($variants as $v) {
            $r = $get($v, $st, $shape, $fl);
            $any = $any || $r !== null;
            $cells[] = $r === null ? '-' : "L {$r['legitDenied']}/{$r['legitAttempts']} · A {$r['attackDenied']}/{$r['attackAttempts']} · reach {$r['attackReachedVerification']}";
        }
        if ($any) {
            $md .= "| $st | " . wsbClass($st) . ' | ' . implode(' | ', $cells) . " |\n";
        }
    }
}
// ---- generated evidence tables for A02..A05 ----------------------------------------------------------------------------------------
$pick = static function (string $v, string $sc, string $stream): array {
    global $A;
    return array_values(array_filter($A[$v][$sc] ?? [], static fn(array $r): bool => $r['s'] === $stream));
};
$md .= "\n## Exact boundaries and recovery (controlled clock; A02)\n\n| Probe | " . implode(' | ', $variants) . " |\n|---|" . str_repeat('---|', count($variants)) . "\n";
$row = static function (string $label, callable $f) use ($variants): string {
    return '| ' . $label . ' | ' . implode(' | ', array_map($f, $variants)) . " |\n";
};
$md .= $row('B1 lone legitimate client after a 3-member trigger: first allowed second', static fn(string $v): string => (string) ($pick($v, 'a02_boundaries', 'A02.B1.lone-legit-after-trigger')[0]['legitFirstAllowedT'] ?? '-'));
$md .= $row('B5 one seed burst then silence: legit denials / last denial second', static function (string $v) use ($pick): string {
    $r = $pick($v, 'a02_boundaries', 'A02.B5.single-seed-then-silence')[0] ?? null;
    return $r === null ? '-' : $r['legitDenied'] . '/' . $r['legitAttempts'] . ' · last ' . ($r['lastLegitDenialT'] ?? 'none');
});
$md .= $row('B2 third member at 599 s, failing / succeeding (record-only): blocked?', static function (string $v) use ($pick): string {
    $o = [];
    foreach ($pick($v, 'a02_boundaries', 'A02.B2.set-ttl-boundary') as $r) {
        if ($r['flow'] === 'out' && $r['f3At'] === 599) {
            $o[] = $r['f3Outcome'] . '=' . ($r['f3Blocked'] ? 'B' : 'a');
        }
    }
    return implode(' ', $o);
});
$md .= $row('B2 third member at 600 s (set expired), failing / succeeding: blocked?', static function (string $v) use ($pick): string {
    $o = [];
    foreach ($pick($v, 'a02_boundaries', 'A02.B2.set-ttl-boundary') as $r) {
        if ($r['flow'] === 'out' && $r['f3At'] === 600) {
            $o[] = $r['f3Outcome'] . '=' . ($r['f3Blocked'] ? 'B' : 'a');
        }
    }
    return implode(' ', $o);
});
$md .= $row('B3 WATCH: second pair ends at 1800 s / 1801 s (record-only): blocked?', static function (string $v) use ($pick): string {
    $o = [];
    foreach ($pick($v, 'a02_boundaries', 'A02.B3.watch-boundary') as $r) {
        if ($r['flow'] === 'out') {
            $o[] = $r['secondPairEndsAt'] . '=' . ($r['blockedAtSecondPairEnd'] ? 'B' : 'a');
        }
    }
    return implode(' ', $o);
});
$md .= $row('B4 persistent attacker (1 req/s): runs / block issuances', static function (string $v) use ($pick): string {
    $r = $pick($v, 'a02_boundaries', 'A02.B4.persistent-attacker-reissue')[0] ?? null;
    return $r === null ? '-' : $r['attackerRuns'] . ' · ' . $r['blockIssuances'];
});

$md .= "\n## Rotation, ephemeral overflow, backend failure (A03)\n\n| Probe | " . implode(' | ', $variants) . " |\n|---|" . str_repeat('---|', count($variants)) . "\n";
$md .= $row('R1 pair split across an outer-key rotation: third member blocked?', static function (string $v) use ($pick): string {
    foreach ($pick($v, 'a03_rotation_ephemeral_backend', 'A03.R1.rotation') as $r) {
        if (str_starts_with($r['shape'], 'rotated')) {
            return $r['thirdBlocked'] ? 'yes' : '**NO**';
        }
    }
    return '-';
});
$md .= $row('R2 block issued before rotation still enforced after', static fn(string $v): string => ($pick($v, 'a03_rotation_ephemeral_backend', 'A03.R2.block-continuity')[0]['blockedAfterRotation'] ?? null) ? 'yes' : 'NO');
$md .= $row('F1a/F1b backend failure propagates (churn-set write / witness write)', static function (string $v) use ($pick): string {
    $a = $pick($v, 'a03_rotation_ephemeral_backend', 'A03.F1a.churn-set-write')[0] ?? null;
    $b = $pick($v, 'a03_rotation_ephemeral_backend', 'A03.F1b.witness-write')[0] ?? null;

    return ($a['result'] ?? '-') . ' / ' . ($b === null || ! $b['applicable'] ? 'n/a' : $b['result']);
});

$md .= "\n## Storage and state growth (A04 in-memory, A05 real Redis)\n\n| Probe | " . implode(' | ', $variants) . " |\n|---|" . str_repeat('---|', count($variants)) . "\n";
$md .= $row('A04 S3: 3000 unique anchors, correlation ON, nobody denied: entries (sets+flags) per request', static fn(string $v): string => (string) ($pick($v, 'a04_state_bounds', 'A04.S3')[0]['entriesPerRequest'] ?? '-'));
$md .= $row('A04 S3: bounded-set classes (ttl/cap: calls)', static function (string $v) use ($pick): string {
    $c = $pick($v, 'a04_state_bounds', 'A04.S3')[0]['classes'] ?? [];
    return implode(' ', array_map(static fn(string $k, array $x): string => "$k:{$x['calls']}", array_keys($c), $c));
});
$md .= $row('A04 after 2000 s idle: entries left', static function (string $v) use ($pick): string {
    $i = $pick($v, 'a04_state_bounds', 'A04.S3')[0]['afterIdle2000s'] ?? null;
    return $i === null ? '-' : (string) ($i['sets'] + $i['flags']);
});
$md .= $row('A05 R3 real Redis: keys created per request, rounded (400 rotating anchors)', static fn(string $v): string => (string) ($pick($v, 'a05_redis', 'A05.R3.anchor-rotation')[0]['keysPerRequestRounded'] ?? '-'));
$md .= $row('A05 R4 real Redis: 8 concurrent failures of ONE fingerprint (20 rounds) vs the distinct-evidence tally (correct = 1)', static function (string $v) use ($pick): string {
    $r = $pick($v, 'a05_redis', 'A05.R4.duplicate-member-race')[0] ?? null;
    return $r === null || isset($r['applicable']) ? 'n/a' : ($r['inflationObserved'] ? 'inflated' : 'exact') . ' · tally reached the evidence threshold (3) with ONE fingerprint: ' . ($r['oneFingerprintReachedThreshold'] ? 'yes' : 'no');
});

$md .= "\n## User-visible legitimate denials under paid poisoning, all causes (C2)\n\n";
foreach ($gate as $g) {
    if (str_starts_with($g['gate'], 'C2') && in_array($g['variant'], $candidates, true)) {
        $md .= "**{$g['variant']}**\n\n| Stream/flow | S0 | {$g['variant']} | with correlation disabled |\n|---|---|---|---|\n";
        foreach ($g['detail'] as $k => $x) {
            $md .= "| $k | {$x['S0']} | {$x[$g['variant']]} | {$x['controlNoCorrelation']} |\n";
        }
        $md .= "\n";
    }
}

$md .= "\n## Offline prediction (PR #103 model) vs experimental runtime — login pre+out\n\nThe model has no K4/K1-spray/score protections and (for A1/PW/P2) assumes the M2 distinct-arrival WATCH, which needs a store-contract signal; the runtime candidates do not. Differences are therefore expected and are reported, not hidden. A1 here is the runtime control \"LOW exemption only\" (no M2).\n\n";
foreach ($fidelity as $p => $rowsF) {
    $md .= "**$p**: " . count(array_filter($rowsF, static fn(array $x): bool => $x['same'])) . '/' . count($rowsF) . " identical.\n\n| Stream | model | runtime | same |\n|---|---|---|---|\n";
    foreach ($rowsF as $id => $x) {
        $md .= "| $id | {$x['model']} | {$x['runtime']} | " . ($x['same'] ? 'yes' : '**NO**') . " |\n";
    }
    $md .= "\n";
}
file_put_contents("$out/summary.md", rtrim($md) . "\n");
echo "gates: " . count($gate) . " rows -> $out/gates.jsonl\n";

if ($problemLines !== []) {
    fwrite(STDERR, "EVIDENCE INCOMPLETE (" . count($problemLines) . " problem(s)); see " . "$out/evidence_problems.txt\n");
    exit(5);
}
