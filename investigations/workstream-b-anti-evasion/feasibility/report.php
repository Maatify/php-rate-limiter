<?php

declare(strict_types=1);

// Unified Security Feasibility (#97 / #100) — gate evaluation + summary generator.
//   php report.php <evidence-dir> <output-dir>      writes <output-dir>/gates.jsonl and <output-dir>/summary.md
// FAILS CLOSED: missing, empty or malformed evidence, a missing matrix cell, a missing required assertion or a provenance mismatch exits 5 and
// prints the problems; no gate / verdict is written for damaged evidence. Pure function of the evidence files: no wall clock, no randomness.
require __DIR__ . '/fixtures/feasibility.php';

$in = rtrim((string) ($argv[1] ?? ''), '/');
$out = rtrim((string) ($argv[2] ?? $in), '/');
if ($in === '' || ! is_dir($in)) {
    fwrite(STDERR, "usage: report.php <evidence-dir> <output-dir>\n");
    exit(2);
}
if (! is_dir($out)) {
    mkdir($out, 0775, true);
}

const VARIANTS = ['S0', 'EQL', 'EQV', 'PSC', 'PSCA'];
const NAMES = ['S0' => 'S0', 'EQL' => 'EQL', 'EQV' => 'EQV', 'PSC' => 'PSC', 'PSCA' => 'PSC-A'];
const BASE_SHA = 'b34f12eb3f892c2dd6fa423280ead0cdaafc868f';
const PIN_S0 = '4b259d3397adf492d9767572141c9bdf5efc5744886bce63592ae9f36b95f4fc';
const PIN_EQ = '98d95cc97836066ea8e76cf8399a41ede457c1e3dca156fdaa05b1b3096813c4';
const CONTROL_FIELDS = ['controlLegitDenied', 'controlAttackReachedVerification'];
const MATRIX_FIELDS = ['stream', 'shape', 'flow', 'class', 'attackAttempts', 'attackReachedVerification', 'attackDenied', 'attackDeniedByCorrelation', 'attackDeniedBySource', 'legitAttempts', 'legitDenied', 'legitDeniedByCorrelation', 'legitDeniedBySource', 'penaltyScopeIssuances', 'blockIssuancesTotal', 'storeOps', 'storeOpsTotal', 'peakLiveCorrelationEntries', 'controlLegitDenied', 'controlAttackReachedVerification'];

$problems = [];
$problem = static function (string $m) use (&$problems): void {
    $problems[] = $m;
};

/** @return list<array<string, mixed>>|null */
function loadRows(string $path, callable $problem): ?array
{
    if (! is_file($path)) {
        $problem("missing evidence file: " . basename($path));

        return null;
    }
    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $i => $line) {
        $r = json_decode($line, true);
        if (! is_array($r)) {
            $problem(basename($path) . ': malformed JSON on line ' . ($i + 1));

            return null;
        }
        $rows[] = $r;
    }
    if ($rows === []) {
        $problem('empty evidence file: ' . basename($path));

        return null;
    }

    return $rows;
}

function kv(string $path): array
{
    $o = [];
    foreach (is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [] as $l) {
        if (str_contains($l, ': ')) {
            [$k, $v] = explode(': ', $l, 2);
            $o[$k] = $v;
        }
    }

    return $o;
}

// ---- load ------------------------------------------------------------------------------------------------------------------------
$build = ['psc' => kv("$in/build_info_psc.txt"), 'eq' => kv("$in/build_info_eq.txt")];
$pinPsc = $build['psc']['target_sha256_after'] ?? '';
if ($pinPsc === '') {
    $problem('missing build_info_psc.txt (candidate provenance)');
}
$pins = ['S0' => PIN_S0, 'EQL' => PIN_EQ, 'EQV' => PIN_EQ, 'PSC' => $pinPsc, 'PSCA' => $pinPsc];

$F = [];
foreach (['f01_matrix', 'f03_recovery_boundaries', 'f04_rotation_ephemeral_failure', 'f05_state_cost', 'a06_indistinguishability', 'a07_dec003_behavior', 'f07_poisoning_cost'] as $sc) {
    foreach (VARIANTS as $v) {
        $F[$sc][$v] = loadRows("$in/$sc.$v.jsonl", $problem);
    }
}
$SENS = [];
foreach ([['churn', [3, 4, 6, 12]], ['dilution', [6, 8, 12, 24]]] as [$knob, $caps]) {
    foreach ($caps as $cap) {
        $SENS[$knob][$cap] = loadRows("$in/f02_sensitivity.PSC.$knob$cap.jsonl", $problem);
    }
}
$redisStatus = is_file("$in/redis_status.txt") ? trim((string) file_get_contents("$in/redis_status.txt")) : null;
if ($redisStatus === null) {
    $problem('missing evidence file: redis_status.txt');
}
$redisLive = is_file("$in/redis_live.txt") ? trim((string) file_get_contents("$in/redis_live.txt")) : '1';
$redisSafety = is_file("$in/redis_safety.txt") ? trim((string) file_get_contents("$in/redis_safety.txt")) : null;
$R = [];
if ($redisStatus === 'RAN') {
    foreach (VARIANTS as $v) {
        $R[$v] = loadRows("$in/f06_redis.$v.jsonl", $problem);
    }
}
$storeCalls = is_file("$in/store_calls.txt") ? (string) file_get_contents("$in/store_calls.txt") : null;
if ($storeCalls === null) {
    $problem('missing evidence file: store_calls.txt');
}
$candDiff = is_file("$in/candidate_diff.txt") ? trim((string) file_get_contents("$in/candidate_diff.txt")) : null;
if ($candDiff === null) {
    $problem('missing evidence file: candidate_diff.txt');
}

// ---- validate shape --------------------------------------------------------------------------------------------------------------
$M = [];
foreach (VARIANTS as $v) {
    $rows = $F['f01_matrix'][$v] ?? null;
    if ($rows === null) {
        continue;
    }
    foreach ($rows as $r) {
        if (($r['s'] ?? '') !== 'F01.matrix') {
            continue;
        }
        foreach (MATRIX_FIELDS as $f) {
            if (! array_key_exists($f, $r)) {
                $problem("f01_matrix.$v: cell " . ($r['stream'] ?? '?') . '/' . ($r['shape'] ?? '?') . '/' . ($r['flow'] ?? '?') . " lacks field $f");
                continue 2;
            }
        }
        $k = $r['stream'] . '|' . $r['shape'] . '|' . $r['flow'];
        if (isset($M[$v][$k])) {
            $problem("f01_matrix.$v: duplicate cell $k");
        }
        $M[$v][$k] = $r;
    }
    foreach (fsbMatrixCells() as [$id, $shape, $flow]) {
        if (! isset($M[$v]["$id|$shape|$flow"])) {
            $problem("f01_matrix.$v: missing cell $id/$shape/$flow");
        }
    }
}
foreach (['f01_matrix', 'f03_recovery_boundaries', 'f04_rotation_ephemeral_failure', 'f05_state_cost', 'a06_indistinguishability', 'a07_dec003_behavior', 'f07_poisoning_cost'] as $sc) {
    foreach (VARIANTS as $v) {
        $first = ($F[$sc][$v] ?? [[]])[0] ?? [];
        if (($F[$sc][$v] ?? null) !== null && (! str_ends_with((string) ($first['s'] ?? ''), '.provenance') || ($first['variant'] ?? '') !== $v || ($first['pipelineSha256'] ?? '') !== $pins[$v])) {
            $problem("$sc.$v: provenance row missing or does not match the pinned EvaluationPipeline for $v");
        }
    }
}
/** @return list<array<string, mixed>> assertion rows */
$asserts = static function (?array $rows): array {
    return array_values(array_filter($rows ?? [], static fn(array $r): bool => array_key_exists('assert', $r)));
};
$needAssert = static function (string $label, ?array $rows, array $prefixes) use ($asserts, $problem): void {
    foreach ($prefixes as $p) {
        $found = array_filter($asserts($rows), static fn(array $r): bool => str_starts_with((string) $r['assert'], $p));
        if (count($found) < 1) {
            $problem("$label: required assertion missing: $p");
        }
    }
};
foreach (['PSC', 'PSCA'] as $v) {
    $needAssert("f03.$v", $F['f03_recovery_boundaries'][$v] ?? null, ['R1 recovery', 'R1 negative control', 'R2 no history-only', 'R3:', 'R4:', 'R5 sustained', 'R6 fixed window', 'R6 distinct', 'R7 renewal']);
    $needAssert("f04.$v", $F['f04_rotation_ephemeral_failure'][$v] ?? null, ['R1:', 'R2:', 'R4:', 'E1:', 'F1a', 'F1b', 'F1c', 'F1d', 'C1:']);
    $needAssert("f05.$v", $F['f05_state_cost'][$v] ?? null, ['S1 negative', 'S1 declared', 'S1 growth', 'S1 TTL', 'S2 negative', 'S3 declared', 'S4 negative', 'S4 growth', 'S4 TTL']);
    $needAssert("a07.$v", $F['a07_dec003_behavior'][$v] ?? null, ['D1 behavioural negative control', 'D1 every issued K2 block', 'D1 pause activations', 'D2 negative control', 'D2 active persisted block', 'D3 negative control', 'D3 Current/Previous']);
    $needAssert("a06.$v", $F['a06_indistinguishability'][$v] ?? null, ['indistinguishable: I1', 'indistinguishable: I2', 'indistinguishable: I3', 'indistinguishable: I4']);
}
$needAssert('f01.S0', $F['f01_matrix']['S0'] ?? null, ['S0 L1 legit denied 127/134', 'S0 D1 legit denied 596/600', 'S0 D2:', 'S0 X1:', 'S0 X2:', 'S0 Y9b:']);
foreach (VARIANTS as $v) {
    $n = count(array_filter($F['f07_poisoning_cost'][$v] ?? [], static fn(array $r): bool => ($r['s'] ?? '') === 'F07.poisoning-cost'));
    if (($F['f07_poisoning_cost'][$v] ?? null) !== null && $n !== 6) {
        $problem("f07_poisoning_cost.$v: expected 6 rows, got $n");
    }
}
foreach ($SENS as $knob => $caps) {
    foreach ($caps as $cap => $rows) {
        $n = count(array_filter($rows ?? [], static fn(array $r): bool => ($r['s'] ?? '') === 'F02.sensitivity'));
        if ($rows !== null && $n !== 5 + ($knob === 'dilution' ? 1 : 0)) {
            $problem("f02_sensitivity.PSC.$knob$cap: expected " . (5 + ($knob === 'dilution' ? 1 : 0)) . " rows, got $n");
        }
    }
}
if ($redisStatus === 'RAN') {
    foreach (VARIANTS as $v) {
        $needAssert("f06.$v", $R[$v] ?? null, ['R1:', 'R2:', 'R3 negative', 'R3: concurrent', 'R3: in every round', 'R4 negative', 'R4: 8', 'R7:']);
    }
    foreach (['PSC'] as $v) {
        $needAssert("f06.$v", $R[$v] ?? null, ['R3: the 60 s window', 'R3: the window set has a fixed', 'R3: the qualification marker', 'R4: 8 concurrent failures of ONE fingerprint never create a corroboration marker']);
    }
}
if ($problems !== []) {
    fwrite(STDOUT, "EVIDENCE PROBLEMS (fail closed):\n - " . implode("\n - ", $problems) . "\n");
    exit(5);
}

// ---- helpers ---------------------------------------------------------------------------------------------------------------------
$c = static fn(string $v, string $id, string $shape, string $flow): array => $M[$v]["$id|$shape|$flow"];
$tol = static fn(int $attempts): int => max(2, (int) ceil(0.05 * $attempts));
$LEGIT = ['L1', 'L2', 'L3', 'D1', 'D2'];
$ATTACK = ['X1', 'X2', 'X7', 'Y7', 'X8'];

/** Reporting convention (NOT a security threshold): how much of S0's correlation benefit (control reach - S0 reach) a variant retains. */
$attackClass = static function (string $v, string $id, string $shape, string $flow) use ($c, $tol): array {
    $row = $c($v, $id, $shape, $flow);
    $s0 = $c('S0', $id, $shape, $flow);
    $att = $row['attackAttempts'];
    $reach = $row['attackReachedVerification'];
    $ctl = $row['controlAttackReachedVerification'];
    $base = $s0['attackReachedVerification'];
    $benefit = $ctl - $base;
    if ($benefit <= $tol($att)) {
        return ['INDEPENDENT', 100, $reach, $ctl];
    }
    $t = max(2, (int) ceil(0.10 * $benefit));
    if ($reach <= $base + $t) {
        return ['PRESERVED', 100, $reach, $ctl];
    }
    $retained = (int) round(100 * ($ctl - $reach) / max(1, $benefit));
    if ($reach >= $ctl - $t) {
        return ['LOST', max(0, $retained), $reach, $ctl];
    }

    return ['PARTIAL', $retained, $reach, $ctl];
};
$fmtL = static fn(array $r): string => $r['legitDenied'] . '[' . $r['legitDeniedByCorrelation'] . ']/' . $r['legitAttempts'];
$fmtA = static fn(array $r): string => $r['attackReachedVerification'] . '/' . $r['attackAttempts'];
$cls = static fn(array $a): string => $a[0] === 'PARTIAL' ? "PARTIAL {$a[1]}%" : $a[0];
$assertOk = static function (?array $rows, string $prefix) use ($asserts): bool {
    $f = array_filter($asserts($rows), static fn(array $r): bool => str_starts_with((string) $r['assert'], $prefix));

    return $f !== [] && count(array_filter($f, static fn(array $r): bool => $r['ok'] !== true)) === 0;
};
$allAssertOk = static fn(?array $rows): bool => $asserts($rows) !== [] && count(array_filter($asserts($rows), static fn(array $r): bool => $r['ok'] !== true)) === 0;
$gates = [];
$gate = static function (string $id, string $title, string $status, string $evidence) use (&$gates): void {
    $gates[] = ['gate' => $id, 'title' => $title, 'status' => $status, 'evidence' => $evidence];
};
$j = static fn(mixed $x): string => (string) json_encode($x, JSON_UNESCAPED_SLASHES);

// ---- gates -----------------------------------------------------------------------------------------------------------------------
// G1 provenance
$gate('G1', 'Provenance: every scenario ran on the pinned EvaluationPipeline of its variant (S0 = repository, EQL/EQV = PR #105 patch, PSC/PSC-A = this study)', 'PASS', 'baseline ' . BASE_SHA . '; pins ' . $j(array_map(static fn(string $p): string => substr($p, 0, 12), $pins)));
// G2 S0 baseline reproduction
$gate('G2', 'S0 reproduces the published #103/#104/#105 baseline numbers', $allAssertOk($F['f01_matrix']['S0']) ? 'PASS' : 'FAIL', 'F01 S0 assertions: ' . count($asserts($F['f01_matrix']['S0'])));
// G3 independent protections
$diffs = [];
foreach (VARIANTS as $v) {
    foreach ($M[$v] as $k => $r) {
        foreach (CONTROL_FIELDS as $f) {
            if ($r[$f] !== $M['S0'][$k][$f]) {
                $diffs[] = "$v $k $f";
            }
        }
    }
}
$gate('G3', 'Independent protections unchanged: the no-correlation control equals S0 in every cell of every variant', $diffs === [] ? 'PASS' : 'FAIL', count($M['S0']) . ' cells x 4 candidates compared; differences: ' . ($diffs === [] ? 'none' : implode('; ', array_slice($diffs, 0, 5))));
// G4 contract surface
$ops = [];
$decl = [];
$section = '';
foreach (explode("\n", (string) $storeCalls) as $l) {
    if (str_starts_with($l, 'store_operations')) {
        $section = 'ops';
    } elseif (str_starts_with($l, 'internal_helpers')) {
        $section = 'helpers';
    } elseif (str_starts_with($l, 'non_private')) {
        $section = 'decl';
    } elseif (trim($l) !== '') {
        if ($section === 'ops') {
            $ops[] = trim($l);
        } elseif ($section === 'decl') {
            $decl[] = trim($l);
        }
    }
}
sort($ops);
$onlyBase = $ops === ['correlationStore->getWatchFlag', 'correlationStore->incrementWatchFlag'];
$declPrivateOnly = true;
foreach ($decl as $l) {
    $declPrivateOnly = false;
}
$c1 = $assertOk($F['f04_rotation_ephemeral_failure']['PSC'], 'C1:') && $assertOk($F['f04_rotation_ephemeral_failure']['PSCA'], 'C1:');
$gate('G4', 'No new interface/capability/config: direct store calls limited to the base WATCH operations, bounded sets via the existing private helper, no non-private declaration, runs on a minimal custom store', $onlyBase && $declPrivateOnly && $c1 ? 'PASS' : 'FAIL', 'direct store calls: ' . $j($ops) . '; non-private declarations added: ' . count($decl) . '; minimal custom store (no snapshot): ' . ($c1 ? 'runs' : 'FAILS'));

// G5 NAT fairness (Login/OTP/login-anon), G6 LOW population
$g5 = [];
$g6 = [];
foreach (['PSC', 'PSCA', 'EQL', 'EQV'] as $v) {
    $bad5 = [];
    $bad6 = [];
    foreach (['login', 'login-anon', 'otp'] as $shape) {
        foreach (['L1', 'L2', 'L3', 'D2'] as $id) {
            if ($c($v, $id, $shape, 'pre+out')['legitDeniedByCorrelation'] !== 0 && ! ($id === 'D2' && $c($v, $id, $shape, 'pre+out')['legitDeniedByCorrelation'] <= 2)) {
                $bad5[] = "$id/$shape=" . $c($v, $id, $shape, 'pre+out')['legitDeniedByCorrelation'];
            }
        }
        $d1 = $c($v, 'D1', $shape, 'pre+out')['legitDeniedByCorrelation'];
        if ($d1 !== 0) {
            $bad6[] = "D1/$shape=$d1";
        }
    }
    $g5[$v] = $bad5;
    $g6[$v] = $bad6;
}
$gate('G5', 'Fairness Login/OTP (shared NAT): legitimate L1/L2/L3 are never denied by correlation; D2 correlation share <= 2 (pre+out)', ($g5['PSC'] === [] && $g5['PSCA'] === []) ? 'PASS' : 'FAIL', 'PSC offenders: ' . $j($g5['PSC']) . '; PSC-A: ' . $j($g5['PSCA']) . '; EQL: ' . $j($g5['EQL']) . '; EQV: ' . $j($g5['EQV']));
$gate('G6', 'LOW population (#97 at scale): D1 (600 legitimate users, one UA, 8% typos) is never denied by correlation (Login/login-anon/OTP, pre+out) — candidate PSC', $g6['PSC'] === [] ? 'PASS' : 'FAIL', 'PSC: ' . $j($g6['PSC']) . ' (S0 login D1 ' . $fmtL($c('S0', 'D1', 'login', 'pre+out')) . '); PSC-A: ' . $j($g6['PSCA']) . '; EQL: ' . $j($g6['EQL']));

// G7 retention of X1/Y7 (the protection the candidate must not give up), pre+out. Auth shapes feed the Login/OTP segment verdict; API shapes the API one.
$g7 = [];
$g7bad = [];
$g7authBad = [];
foreach (['PSC', 'PSCA'] as $v) {
    foreach (['login', 'login-anon', 'otp', 'api', 'api_custom'] as $shape) {
        foreach (['X1', 'Y7'] as $id) {
            $a = $attackClass($v, $id, $shape, 'pre+out');
            $g7[$v][] = "$id/$shape=" . $cls($a) . " ({$a[2]} vs control {$a[3]})";
            if ($a[0] === 'LOST') {
                $g7bad[] = "$v $id/$shape";
                if (in_array($shape, ['login', 'login-anon', 'otp'], true)) {
                    $g7authBad[] = "$v $id/$shape";
                }
            }
        }
    }
}
$gate('G7', 'Low-volume churn protection kept: X1 and Y7 do not collapse to the no-correlation control (Login, Login without account id, OTP, API_OVERUSE, custom API; pre+out; INDEPENDENT cells are held by independent protections)', $g7bad === [] ? 'PASS' : 'FAIL', 'PSC: ' . implode('; ', $g7['PSC']) . ' || collapsed to control: ' . ($g7bad === [] ? 'none' : implode(', ', $g7bad)));

// G8 LOW distributed attacks X2 / X7 / X8
$g8 = [];
$g8lost = false;
foreach (['PSC', 'PSCA', 'EQL'] as $v) {
    foreach (['X2', 'X7', 'X8'] as $id) {
        $a = $attackClass($v, $id, 'login', 'pre+out');
        $g8[$v][] = "$id=" . $cls($a) . " ({$a[2]} vs S0 " . $c('S0', $id, 'login', 'pre+out')['attackReachedVerification'] . ' vs control ' . $a[3] . ')';
        $g8lost = $g8lost || ($v === 'PSC' && $a[0] === 'LOST');
    }
}
$gate('G8', 'Distributed LOW attacks X2/X7/X8 keep S0-class protection without S0\'s population collateral (Login, pre+out)', $g8lost ? 'OWNER_DECISION_REQUIRED' : 'PASS', 'PSC: ' . implode('; ', $g8['PSC']) . ' || PSC-A: ' . implode('; ', $g8['PSCA']) . ' || EQL: ' . implode('; ', $g8['EQL']));

// G9 API (#100)
$g9 = [];
$g9bad = [];
foreach (['PSC', 'PSCA', 'EQL', 'EQV'] as $v) {
    foreach (['api', 'api_custom'] as $shape) {
        foreach (['L1', 'D2', 'D1'] as $id) {
            $cc = $c($v, $id, $shape, 'pre+out')['legitDeniedByCorrelation'];
            $g9[$v][] = "$id/$shape=$cc";
            if ($cc > 2) {
                $g9bad[$v][] = "$id/$shape";
            }
        }
    }
}
$gate('G9', 'API_OVERUSE / custom API (#100): legitimate NAT traffic (L1, D2) and the LOW population (D1) are not denied by correlation (<= 2) — candidate PSC', empty($g9bad['PSC']) ? 'PASS' : 'FAIL', 'legit denied by correlation, pre+out — PSC: ' . implode(' ', $g9['PSC']) . ' | PSC-A: ' . implode(' ', $g9['PSCA']) . ' | EQL: ' . implode(' ', $g9['EQL']) . ' | EQV: ' . implode(' ', $g9['EQV']));

// G10 checkOnly-only hosts
$g10 = [];
$allLost = true;
foreach (['login', 'api'] as $shape) {
    foreach (['X1', 'X2', 'X7', 'Y7', 'X8'] as $id) {
        $a = $attackClass('PSC', $id, $shape, 'pre');
        $s = $c('S0', $id, $shape, 'pre');
        $g10[] = "$id/$shape pre: PSC {$a[2]} vs S0 {$s['attackReachedVerification']} vs control {$a[3]} => " . $cls($a);
        if ($a[0] !== 'LOST' && $a[0] !== 'INDEPENDENT') {
            $allLost = false;
        }
    }
}
$gate('G10', 'checkOnly-only hosts keep meaningful correlation protection without replicating S0 collateral', $allLost ? 'FAIL' : 'PASS', ($allLost ? 'SCOPED NO-GO: ' : '') . 'no outcome => no scored command => no corroboration; ' . implode('; ', array_slice($g10, 0, 6)) . ' ...');

// G11 recovery
$gate('G11', 'Recovery: no re-block from history; fixed non-sliding window; renewal needs new corroboration; sustained leak bounded (60/600/1800 s horizons)', ($allAssertOk($F['f03_recovery_boundaries']['PSC']) && $allAssertOk($F['f03_recovery_boundaries']['PSCA'])) ? 'PASS' : 'FAIL', 'F03 assertions PSC ' . count($asserts($F['f03_recovery_boundaries']['PSC'])) . ', PSC-A ' . count($asserts($F['f03_recovery_boundaries']['PSCA'])));
// G12 rotation + ephemeral
$g12 = $assertOk($F['f04_rotation_ephemeral_failure']['PSC'], 'R1:') && $assertOk($F['f04_rotation_ephemeral_failure']['PSC'], 'R2:') && $assertOk($F['f04_rotation_ephemeral_failure']['PSC'], 'R4:') && $assertOk($F['f04_rotation_ephemeral_failure']['PSC'], 'E1:');
$gate('G12', 'Current/Previous rotation (set continuity, block continuity) and Ephemeral overflow keep the churn signal; IPv6 macro scopes measured in F01 (Y9a/Y9b)', $g12 ? 'PASS' : 'FAIL', 'previous-generation MARKER read is not exercised by any assertion (a block and its marker end together; only dilution/macro scopes would need it): UNPROVEN beyond code symmetry with PR #105');
// G13 failure propagation
$g13 = $assertOk($F['f04_rotation_ephemeral_failure']['PSC'], 'F1a') && $assertOk($F['f04_rotation_ephemeral_failure']['PSC'], 'F1b') && $assertOk($F['f04_rotation_ephemeral_failure']['PSC'], 'F1c') && $assertOk($F['f04_rotation_ephemeral_failure']['PSC'], 'F1d');
if ($redisStatus === 'RAN') {
    $g13r = $assertOk($R['PSC'], 'R7:');
    $gate('G13', 'Backend failure propagates (simulated store failures on every new state path + a real Redis outage); never ALLOW, never a silent skip', $g13 && $g13r ? 'PASS' : 'FAIL', 'simulated: ' . ($g13 ? 'pass' : 'FAIL') . '; real Redis outage: ' . ($g13r ? 'BackendFailureException' : 'FAIL'));
} else {
    $gate('G13', 'Backend failure propagates', 'UNPROVEN', 'simulated failures pass (' . ($g13 ? 'yes' : 'NO') . '); real Redis outage not run (REDIS_SPOTCHECK=0 or redis missing)');
}
// G14 Redis concurrency
if ($redisStatus === 'RAN') {
    $okAll = true;
    foreach (['R1:', 'R2:', 'R3 negative', 'R3: concurrent', 'R3: in every round', 'R3: the 60 s window', 'R3: the window set has a fixed', 'R3: the qualification marker', 'R4 negative', 'R4: 8'] as $p) {
        $okAll = $okAll && $assertOk($R['PSC'], $p);
    }
    $live = $redisLive !== '0' && $assertOk($R['PSC'], 'R6:') && $assertOk($R['S0'], 'S0 R6:');
    $gate('G14', 'Real Redis: semantics, TTL hygiene, concurrency (cap race, duplicate race, window cap + fixed TTL + marker atomicity), live recovery on the Redis clock', $okAll ? ($live ? 'PASS' : 'UNPROVEN') : 'FAIL', 'ownership/safety proof: ' . ($redisSafety ?? 'n/a') . '; live 63 s recovery: ' . ($redisLive === '0' ? 'skipped (UNPROVEN)' : 'ran'));
} else {
    $gate('G14', 'Real Redis stage', 'UNPROVEN', 'stage not run');
}
// G15 bounded state / cost
$gate('G15', 'Bounded state and TTL hygiene (caps <= 50, nothing survives 2000 s idle, linear growth) incl. the access-scored worst case', ($allAssertOk($F['f05_state_cost']['PSC']) && $allAssertOk($F['f05_state_cost']['PSCA'])) ? 'PASS' : 'FAIL', 'F05 assertions: ' . count($asserts($F['f05_state_cost']['PSC'])));

// G16 separability sweep (the SAME candidate, window cap raised)
$sens = static function (string $knob, int $cap, string $id) use ($SENS): array {
    foreach ($SENS[$knob][$cap] as $r) {
        if (($r['s'] ?? '') === 'F02.sensitivity' && $r['stream'] === $id) {
            return $r;
        }
    }

    return [];
};
$churnSep = [];
$churnAny = false;
foreach ([3, 4, 6, 12] as $cap) {
    $d2 = $sens('churn', $cap, 'D2');
    $y7 = $sens('churn', $cap, 'Y7');
    $x1 = $sens('churn', $cap, 'X1');
    $y7s0 = $c('S0', 'Y7', 'api', 'pre+out');
    $x1s0 = $c('S0', 'X1', 'api', 'pre+out');
    $yLost = $y7['attackReachedVerification'] >= $y7['controlAttackReachedVerification'] - $tol($y7['attackAttempts']);
    $xLost = $x1['attackReachedVerification'] >= $x1['controlAttackReachedVerification'] - $tol($x1['attackAttempts']);
    $sep = $d2['legitDeniedByCorrelation'] <= 2 && ! $yLost && ! $xLost;
    $churnAny = $churnAny || $sep;
    $churnSep[] = "cap $cap: D2/api by-corr {$d2['legitDeniedByCorrelation']}, Y7/api reach {$y7['attackReachedVerification']} (S0 {$y7s0['attackReachedVerification']}, control {$y7['controlAttackReachedVerification']}), X1/api reach {$x1['attackReachedVerification']} (S0 {$x1s0['attackReachedVerification']}, control {$x1['controlAttackReachedVerification']}) => " . ($sep ? 'separates' : 'does NOT separate');
}
$lowSep = [];
$lowAny = false;
foreach ([6, 8, 12, 24] as $cap) {
    $d1 = $sens('dilution', $cap, 'D1');
    $x2 = $sens('dilution', $cap, 'X2');
    $x7 = $sens('dilution', $cap, 'X7');
    $x2Lost = $x2['attackReachedVerification'] >= $x2['controlAttackReachedVerification'] - $tol($x2['attackAttempts']);
    $x7Lost = $x7['attackReachedVerification'] >= $x7['controlAttackReachedVerification'] - $tol($x7['attackAttempts']);
    $sep = $d1['legitDeniedByCorrelation'] === 0 && ! $x2Lost && ! $x7Lost;
    $lowAny = $lowAny || $sep;
    $lowSep[] = "cap $cap: D1 by-corr {$d1['legitDeniedByCorrelation']}/600, X2 reach {$x2['attackReachedVerification']}/121, X7 reach {$x7['attackReachedVerification']}/121 (control 121) => " . ($sep ? 'separates' : 'does NOT separate');
}
$gate('G16', 'Separability: some corroboration-window cap of the same candidate removes the legitimate collateral (API D2, LOW D1) while keeping X1/Y7 (API) and X2/X7 (LOW) above the no-correlation control', (! $churnAny && ! $lowAny) ? 'FAIL' : 'PASS', 'API churn: ' . implode(' | ', $churnSep) . ' || LOW: ' . implode(' | ', $lowSep));
// G17 indistinguishability
$g17 = true;
foreach (VARIANTS as $v) {
    $g17 = $g17 && $allAssertOk($F['a06_indistinguishability'][$v]);
}
$gate('G17', 'Indistinguishability (PR #104 A06 rerun unchanged): four byte-identical attacker/legitimate pairs are treated identically by every variant', $g17 ? 'PASS' : 'FAIL', 'a pass here confirms that the inputs cannot separate the pairs; it is the reason G8/G16 are decisions, not defects');

// G18 DEC-003
$g18 = true;
foreach (VARIANTS as $v) {
    $g18 = $g18 && $allAssertOk($F['a07_dec003_behavior'][$v]);
}
$cyc = static fn(string $v): int => $c($v, 'D2', 'login', 'pre+out')['blockIssuancesTotal'];
$gate('G18', 'DEC-003 (multiple-block-cycle decay pause): PR #104 A07 rerun unchanged on every variant (transition-only cycles, no mutation under an active block, Current/Previous one history)', $g18 ? 'PASS' : 'FAIL', 'persisted block cycles in D2 (Login, pre+out): ' . implode(', ', array_map(static fn(string $v): string => NAMES[$v] . ' ' . $cyc($v), VARIANTS)) . ' (fewer re-issues => fewer cycles; semantics unchanged)');

// Verdict
$fails = array_values(array_filter($gates, static fn(array $g): bool => $g['status'] === 'FAIL'));
$unified = ($g6['PSC'] === [] && empty($g9bad['PSC']) && ! $allLost && ($churnAny || $lowAny)) ? 'CONDITIONAL' : 'NO-GO';
$segments = [
    ['Login/OTP shared-NAT churn (#97/#100: L1, D2 correlation share)', ($g5['PSC'] === [] && $g7authBad === [] && $g12) ? 'FEASIBLE within the measured limits (X1/Y7 kept; recovery bounded) — OWNER DECISION REQUIRED' : 'NO-GO'],
    ['LOW population, UA-only (#97: D1) — PSC as specified (corroborated)', $g6['PSC'] === [] ? 'FEASIBLE' : 'NO-GO as specified (D1 ' . $c('PSC', 'D1', 'login', 'pre+out')['legitDeniedByCorrelation'] . '/600 still denied by correlation)'],
    ['LOW population — PSC-A (advisory)', $g6['PSCA'] === [] ? 'collateral removed; X2/X7/X8 protection removed => OWNER DECISION REQUIRED' : 'NO-GO'],
    ['API_OVERUSE / custom API (#100)', empty($g9bad['PSC']) ? 'FEASIBLE' : 'PARTIAL / NO-GO as a unified default (L1 fixed; D2 and D1 not)'],
    ['checkOnly-only hosts', $allLost ? 'SCOPED NO-GO (no outcome => no corroboration)' : 'FEASIBLE'],
];
$gate('VERDICT', 'Unified default candidate (PSC)', $unified === 'NO-GO' ? 'FAIL' : 'OWNER_DECISION_REQUIRED', 'NO-GO: ' . implode(' | ', array_map(static fn(array $g): string => $g['gate'], $fails)) . ' failed; the pairs behind G8/G16 are byte-identical (G17), so the separation the Owner asked for is not obtainable from the same inputs');

// ---- output: gates.jsonl ---------------------------------------------------------------------------------------------------------
$g = fopen("$out/gates.jsonl", 'wb');
foreach ($gates as $row) {
    fwrite($g, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}
fclose($g);

// ---- output: summary.md ----------------------------------------------------------------------------------------------------------
$md = [];
$md[] = '# Unified Security Feasibility (#97 / #100) — generated evidence summary';
$md[] = '';
$md[] = 'Generated by `report.php` from `expected/*.jsonl`. **Evidence only; nothing here selects, recommends or implements a policy.** Baseline `' . BASE_SHA . '`.';
$md[] = 'Variants: **S0** current runtime · **EQL / EQV** PR #105 candidates (re-run, patch read in place) · **PSC** this study\'s single candidate (Policy-Specific Corroboration + Scope-Safe Recovery; LOW cross-IP dilution corroborated, not advisory) · **PSC-A** the same patch with LOW cross-IP dilution advisory (a one-line switch of the same candidate, reported because it is the frontier point that removes the population collateral).';
$md[] = 'Cell notation: legitimate `denied[of which by correlation]/attempts`; attacker `attempts that reached verification/attempts`; `control` = same events with churn/dilution correlation disabled (what independent protections alone allow). In the record-only flow (`out`) every attacker attempt is "verified" before the library sees it, so only attacker **denials** are meaningful there.';
$md[] = '';

$table = static function (string $title, string $shape, string $flow, array $streams) use (&$md, $c, $fmtL, $fmtA): void {
    $md[] = "### $title";
    $md[] = '';
    $md[] = '| Stream | ' . implode(' | ', array_map(static fn(string $v): string => NAMES[$v], VARIANTS)) . ' | control |';
    $md[] = '|---|' . str_repeat('---|', count(VARIANTS) + 1);
    foreach ($streams as $id) {
        $cells = [];
        foreach (VARIANTS as $v) {
            $r = $c($v, $id, $shape, $flow);
            $isAttack = $r['attackAttempts'] > 0 && $r['legitAttempts'] === 0;
            if ($isAttack) {
                $cells[] = $flow === 'out' ? 'denied ' . $r['attackDenied'] . '/' . $r['attackAttempts'] : $fmtA($r);
            } else {
                $cells[] = $fmtL($r);
            }
        }
        $r0 = $c('S0', $id, $shape, $flow);
        $isAttack = $r0['attackAttempts'] > 0 && $r0['legitAttempts'] === 0;
        $cells[] = $isAttack ? ($flow === 'out' ? '—' : (string) $r0['controlAttackReachedVerification']) : (string) $r0['controlLegitDenied'];
        $md[] = "| $id | " . implode(' | ', $cells) . ' |';
    }
    $md[] = '';
};
$md[] = '## 1. Headline comparison';
$md[] = '';
$table('1a. Login (with account id), checkOnly + outcome', 'login', 'pre+out', array_merge($LEGIT, $ATTACK));
$table('1b. Login, record-only (`recordSuccess/recordFailure` only)', 'login', 'out', array_merge($LEGIT, $ATTACK));
$table('1c. Login, checkOnly-only host (no outcome ever reported)', 'login', 'pre', array_merge($LEGIT, $ATTACK));
$table('1d. Login without account id, checkOnly + outcome', 'login-anon', 'pre+out', array_merge($LEGIT, $ATTACK));
$table('1e. OTP, checkOnly + outcome', 'otp', 'pre+out', array_merge($LEGIT, $ATTACK));
$table('1f. OTP, record-only', 'otp', 'out', array_merge($LEGIT, $ATTACK));
$md[] = '## 2. API_OVERUSE and custom API_OVERUSE (access-scored: every outcome is a success)';
$md[] = '';
$table('2a. API_OVERUSE, checkOnly + outcome', 'api', 'pre+out', array_merge($LEGIT, $ATTACK));
$table('2b. API_OVERUSE, record-only', 'api', 'out', array_merge($LEGIT, $ATTACK));
$table('2c. API_OVERUSE, checkOnly-only host', 'api', 'pre', array_merge($LEGIT, $ATTACK));
$table('2d. Custom API_OVERUSE (tight limits), checkOnly + outcome', 'api_custom', 'pre+out', array_merge($LEGIT, $ATTACK));
$table('2e. Custom API_OVERUSE, record-only', 'api_custom', 'out', array_merge($LEGIT, $ATTACK));
$table('2f. Custom API_OVERUSE, checkOnly-only host', 'api_custom', 'pre', array_merge($LEGIT, $ATTACK));
$md[] = '## 3. Boundary, poisoning, recovery and IPv6 streams (Login, checkOnly + outcome)';
$md[] = '';
$md[] = '| Stream | ' . implode(' | ', array_map(static fn(string $v): string => NAMES[$v], VARIANTS)) . ' | control |';
$md[] = '|---|' . str_repeat('---|', count(VARIANTS) + 1);
foreach (fsbExtendedStreams() as $id) {
    $cells = [];
    foreach (VARIANTS as $v) {
        $r = $c($v, $id, 'login', 'pre+out');
        $cells[] = ($r['legitAttempts'] > 0 ? 'legit ' . $fmtL($r) . ' · ' : '') . ($r['attackAttempts'] > 0 ? 'atk reach ' . $fmtA($r) : '');
    }
    $r0 = $c('S0', $id, 'login', 'pre+out');
    $cells[] = ($r0['legitAttempts'] > 0 ? 'legit ' . $r0['controlLegitDenied'] . ' · ' : '') . ($r0['attackAttempts'] > 0 ? 'atk reach ' . $r0['controlAttackReachedVerification'] : '');
    $md[] = "| $id | " . implode(' | ', $cells) . ' |';
}
$md[] = '';
$md[] = '## 4. Attack-protection class (Login and API_OVERUSE, checkOnly + outcome)';
$md[] = '';
$md[] = 'Reporting convention, not a security threshold: benefit = control reach − S0 reach; `INDEPENDENT` = benefit <= max(2, 5% of attempts) (independent protections already hold the attacker to S0\'s level; correlation adds nothing measurable); otherwise tolerance = max(2, 10% of the benefit): `PRESERVED` = within tolerance of S0; `LOST` = within tolerance of the no-correlation control; `PARTIAL n%` = share of S0\'s correlation benefit retained = (control − reach)/(control − S0 reach).';
$md[] = '';
foreach (['login', 'api'] as $shape) {
    $md[] = "**$shape**";
    $md[] = '';
    $md[] = '| Attack | ' . implode(' | ', array_map(static fn(string $v): string => NAMES[$v], array_slice(VARIANTS, 1))) . ' |';
    $md[] = '|---|' . str_repeat('---|', count(VARIANTS) - 1);
    foreach (array_merge($ATTACK, ['D5', 'D7', 'Y2a', 'Y4c', 'Y9a', 'Y9b']) as $id) {
        if (! isset($M['S0']["$id|$shape|pre+out"]) || $c('S0', $id, $shape, 'pre+out')['attackAttempts'] === 0) {
            continue;
        }
        $cells = [];
        foreach (array_slice(VARIANTS, 1) as $v) {
            $a = $attackClass($v, $id, $shape, 'pre+out');
            $cells[] = $cls($a) . " ({$a[2]})";
        }
        $md[] = "| $id (S0 " . $c('S0', $id, $shape, 'pre+out')['attackReachedVerification'] . ", control " . $c('S0', $id, $shape, 'pre+out')['controlAttackReachedVerification'] . ") | " . implode(' | ', $cells) . ' |';
    }
    $md[] = '';
}
// extended streams only exist on login; api section above skips missing cells silently by construction (cells exist only for core + D7)

$md[] = '## 5. Winning decision scope and penalty scope (Login / API, checkOnly + outcome)';
$md[] = '';
$md[] = 'The public result does not expose the winning candidate\'s `source`, so the winner is **derived from persisted enforcement** (see `lib/fsb.php::fsbReplay`): `issued:K2` = a block cycle persisted by that very command on K2; `active:K2` = denied by an already-persisted K2 block; `corr:`/`indep:` = whether the same event is also denied with correlation disabled (counterfactual).';
$md[] = '';
$md[] = '| Cell | Variant | legit denied by source | attacker denied by source | persisted block cycles by key family |';
$md[] = '|---|---|---|---|---|';
foreach ([['L1', 'login'], ['D1', 'login'], ['D2', 'login'], ['X8', 'login'], ['X1', 'login'], ['X2', 'login'], ['L1', 'api'], ['D2', 'api'], ['X1', 'api']] as [$id, $shape]) {
    foreach (VARIANTS as $v) {
        $r = $c($v, $id, $shape, 'pre+out');
        $md[] = "| $id/$shape | " . NAMES[$v] . ' | ' . ($r['legitDeniedBySource'] === [] ? '—' : '`' . $j($r['legitDeniedBySource']) . '`') . ' | ' . ($r['attackDeniedBySource'] === [] ? '—' : '`' . $j($r['attackDeniedBySource']) . '`') . ' | ' . ($r['penaltyScopeIssuances'] === [] ? '—' : '`' . $j($r['penaltyScopeIssuances']) . '`') . ' |';
    }
}
$md[] = '';
$md[] = '## 6. Recovery, rotation, ephemeral, failure (assertions; PSC and PSC-A must pass all)';
$md[] = '';
$md[] = '| Scenario | ' . implode(' | ', array_map(static fn(string $v): string => NAMES[$v], VARIANTS)) . ' |';
$md[] = '|---|' . str_repeat('---|', count(VARIANTS));
foreach (['f03_recovery_boundaries' => 'F03 recovery / boundaries', 'f04_rotation_ephemeral_failure' => 'F04 rotation / ephemeral / failure / custom store', 'f05_state_cost' => 'F05 state bounds / TTL hygiene', 'a06_indistinguishability' => 'A06 indistinguishability (PR #104, unchanged)', 'a07_dec003_behavior' => 'A07 DEC-003 cycle/decay-pause behaviour (PR #104, unchanged)'] as $sc => $label) {
    $cells = [];
    foreach (VARIANTS as $v) {
        $a = $asserts($F[$sc][$v]);
        $cells[] = $a === [] ? '— (none defined)' : (count($a) - count(array_filter($a, static fn(array $r): bool => $r['ok'] !== true))) . '/' . count($a) . ' ok';
    }
    $md[] = "| $label | " . implode(' | ', $cells) . ' |';
}
$md[] = '';
$md[] = 'Key measurements (`F03`):';
$md[] = '';
$md[] = '| Measurement | ' . implode(' | ', array_map(static fn(string $v): string => NAMES[$v], VARIANTS)) . ' |';
$md[] = '|---|' . str_repeat('---|', count(VARIANTS));
$f03row = static function (string $v, string $s, array $filter = []) use ($F): array {
    foreach ($F['f03_recovery_boundaries'][$v] as $r) {
        if (($r['s'] ?? '') === $s && array_diff_assoc($filter, $r) === []) {
            return $r;
        }
    }

    return [];
};
$line = static function (string $label, callable $f) use (&$md): void {
    $md[] = "| $label | " . implode(' | ', array_map(static fn(string $v): string => (string) $f($v), VARIANTS)) . ' |';
};
$line('R1 lone legit client first allowed after a 3-fingerprint failing trigger (t)', static fn(string $v): string => (string) $f03row($v, 'F03.R1.lone-legit-after-trigger')['legitFirstAllowedT']);
$line('R2 last denial of that client after a single seed (t)', static fn(string $v): string => (string) $f03row($v, 'F03.R2.single-seed-then-silence')['lastLegitDenialT']);
$line('R3 colleagues denied after ONE colleague typo on a stale set (of 84)', static fn(string $v): string => (string) $f03row($v, 'F03.R3.single-typo-on-stale-set')['legitDenied']);
$line('R5 attacker verifications over 30 min, 1 fresh fingerprint/s (of 1801)', static fn(string $v): string => (string) $f03row($v, 'F03.R5.sustained-leak-30min')['reachedVerification']);
$line('R6 third distinct failure enforced at +59/+60/+61 s', static fn(string $v): string => implode('/', array_map(static fn(bool $b): string => $b ? 'Y' : 'n', array_slice($f03row($v, 'F03.R6.window-fixed-distinct')['thirdFailingMemberEnforcedAt'], 0, 3))));
$line('R8 N-1 hovering attacker Y2a: reach (of 8)', static fn(string $v): string => (string) $f03row($v, 'F03.R8.n1-watch-gaming', ['stream' => 'Y2a'])['attackReachedVerification']);
$md[] = '';

$md[] = '## 6b. Price of poisoning the shared-NAT cohort through correlation (F07)';
$md[] = '';
$md[] = 'One stable legitimate client (success every 5 s, 120 requests) behind a NAT; an insider fails with a fresh fingerprint + fresh account at the stated rate (Login without account id so K1 spray/K4/K5 cannot mask correlation; checkOnly + outcome). Cell = legitimate requests denied [of which by correlation] / insider failures that reached verification.';
$md[] = '';
$md[] = '| Insider failures / 60 s | ' . implode(' | ', array_map(static fn(string $v): string => NAMES[$v], VARIANTS)) . ' | control |';
$md[] = '|---|' . str_repeat('---|', count(VARIANTS) + 1);
foreach ([1, 2, 3, 4, 6, 12] as $rate) {
    $cells = [];
    $ctlv = 0;
    foreach (VARIANTS as $v) {
        foreach ($F['f07_poisoning_cost'][$v] as $r) {
            if (($r['s'] ?? '') === 'F07.poisoning-cost' && $r['insiderFailuresPer60s'] === $rate) {
                $cells[] = $r['legitDenied'] . '[' . $r['legitDeniedByCorrelation'] . ']/' . $r['insiderFailuresReachedVerification'];
                $ctlv = $r['controlLegitDenied'];
            }
        }
    }
    $md[] = "| $rate | " . implode(' | ', $cells) . " | $ctlv |";
}
$md[] = '';
$md[] = '## 7. Sensitivity of the same candidate to its corroboration-window cap (test-only knob)';
$md[] = '';
$md[] = 'Not a second design: the one mechanism with its cap raised. Rows are produced by `scenarios/f02_sensitivity.php`; S0 and control values from the matrix.';
$md[] = '';
$md[] = '**API_OVERUSE churn cap (checkOnly + outcome)** — default cap = 3 (S0\'s churn threshold)';
$md[] = '';
$md[] = '| cap | L1 legit denied[corr]/134 | D2 legit denied[corr]/121 | X1 reach/61 | Y7 reach/241 | D7 reach/1801 |';
$md[] = '|---|---|---|---|---|---|';
$s0 = static fn(string $id): array => $c('S0', $id, 'api', 'pre+out');
$md[] = '| S0 | ' . $fmtL($s0('L1')) . ' | ' . $fmtL($s0('D2')) . ' | ' . $s0('X1')['attackReachedVerification'] . ' | ' . $s0('Y7')['attackReachedVerification'] . ' | ' . $s0('D7')['attackReachedVerification'] . ' |';
foreach ([3, 4, 6, 12] as $cap) {
    $md[] = "| $cap | " . $fmtL($sens('churn', $cap, 'L1')) . ' | ' . $fmtL($sens('churn', $cap, 'D2')) . ' | ' . $sens('churn', $cap, 'X1')['attackReachedVerification'] . ' | ' . $sens('churn', $cap, 'Y7')['attackReachedVerification'] . ' | ' . $sens('churn', $cap, 'D7')['attackReachedVerification'] . ' |';
}
$md[] = '| control | ' . $s0('L1')['controlLegitDenied'] . ' | ' . $s0('D2')['controlLegitDenied'] . ' | ' . $s0('X1')['controlAttackReachedVerification'] . ' | ' . $s0('Y7')['controlAttackReachedVerification'] . ' | ' . $s0('D7')['controlAttackReachedVerification'] . ' |';
$md[] = '';
$md[] = '**LOW cross-IP dilution cap (Login, checkOnly + outcome)** — default cap = 6 (S0\'s dilution threshold)';
$md[] = '';
$md[] = '| cap | L3 legit denied[corr]/121 | D1 legit denied[corr]/600 | D2 legit denied[corr]/121 | X2 reach/121 | X7 reach/121 | X8 legit denied[corr]/50 · atk reach/151 |';
$md[] = '|---|---|---|---|---|---|---|';
$l0 = static fn(string $id): array => $c('S0', $id, 'login', 'pre+out');
$md[] = '| S0 | ' . $fmtL($l0('L3')) . ' | ' . $fmtL($l0('D1')) . ' | ' . $fmtL($l0('D2')) . ' | ' . $l0('X2')['attackReachedVerification'] . ' | ' . $l0('X7')['attackReachedVerification'] . ' | ' . $fmtL($l0('X8')) . ' · ' . $l0('X8')['attackReachedVerification'] . ' |';
foreach ([6, 8, 12, 24] as $cap) {
    $x8 = $sens('dilution', $cap, 'X8');
    $md[] = "| $cap | " . $fmtL($sens('dilution', $cap, 'L3')) . ' | ' . $fmtL($sens('dilution', $cap, 'D1')) . ' | ' . $fmtL($sens('dilution', $cap, 'D2')) . ' | ' . $sens('dilution', $cap, 'X2')['attackReachedVerification'] . ' | ' . $sens('dilution', $cap, 'X7')['attackReachedVerification'] . ' | ' . $fmtL($x8) . ' · ' . $x8['attackReachedVerification'] . ' |';
}
$md[] = '| control | ' . $l0('L3')['controlLegitDenied'] . ' | ' . $l0('D1')['controlLegitDenied'] . ' | ' . $l0('D2')['controlLegitDenied'] . ' | ' . $l0('X2')['controlAttackReachedVerification'] . ' | ' . $l0('X7')['controlAttackReachedVerification'] . ' | ' . $l0('X8')['controlLegitDenied'] . ' · ' . $l0('X8')['controlAttackReachedVerification'] . ' |';
$md[] = '';

$md[] = '## 8. Cost (reference in-memory store: API calls; the Redis adapter issues one atomic script per call)';
$md[] = '';
$md[] = '| Measurement | ' . implode(' | ', array_map(static fn(string $v): string => NAMES[$v], VARIANTS)) . ' |';
$md[] = '|---|' . str_repeat('---|', count(VARIANTS));
$f05 = static function (string $v, string $s, array $f = []) use ($F): array {
    foreach ($F['f05_state_cost'][$v] as $r) {
        if (($r['s'] ?? '') === $s && array_diff_assoc($f, $r) === []) {
            return $r;
        }
    }

    return [];
};
foreach (['legit-success-pre+out', 'legit-typo-pre+out', 'low-success-pre+out', 'three-failing-fingerprints-pre+out', 'api-success-pre+out'] as $case) {
    $line("store operations: $case", static fn(string $v): string => (string) $f05($v, 'F05.O1.ops', ['case' => $case])['total']);
}
$line('S1 live entries per failing request (Login, 3000 rotating anchors, peak)', static fn(string $v): string => (string) $f05($v, 'F05.S1')['entriesPerRequest']);
$line('S4 live entries per request (API_OVERUSE, 3000 rotating anchors, peak)', static fn(string $v): string => (string) $f05($v, 'F05.S4')['entriesPerRequest']);
$line('S1 / S4 entries left after 2000 s idle', static fn(string $v): string => $f05($v, 'F05.S1')['afterIdle2000s']['sets'] + $f05($v, 'F05.S1')['afterIdle2000s']['flags'] . ' / ' . ($f05($v, 'F05.S4')['afterIdle2000s']['sets'] + $f05($v, 'F05.S4')['afterIdle2000s']['flags']));
$line('declared bounded-set classes (ttl/cap), S1', static fn(string $v): string => implode(' ', array_keys($f05($v, 'F05.S1')['classes'])));
foreach (['D1', 'D7'] as $id) {
    $line("peak live correlation entries, $id Login pre+out", static fn(string $v): string => (string) $c($v, $id, 'login', 'pre+out')['peakLiveCorrelationEntries']);
    $line("total store operations, $id Login pre+out", static fn(string $v): string => (string) $c($v, $id, 'login', 'pre+out')['storeOpsTotal']);
}
$md[] = '';

if ($redisStatus === 'RAN') {
    $md[] = '## 9. Real Redis (ownership-proven loopback instances; Redis owns time)';
    $md[] = '';
    $md[] = 'Safety proof: `' . ($redisSafety ?? 'n/a') . '`. Live 63 s recovery: ' . ($redisLive === '0' ? 'SKIPPED' : 'ran for S0 and PSC') . '.';
    $md[] = '';
    $md[] = '| Row | ' . implode(' | ', array_map(static fn(string $v): string => NAMES[$v], VARIANTS)) . ' |';
    $md[] = '|---|' . str_repeat('---|', count(VARIANTS));
    $rr = static function (string $v, string $s) use ($R): array {
        foreach ($R[$v] as $r) {
            if (($r['s'] ?? '') === $s) {
                return $r;
            }
        }

        return [];
    };
    $line('R1 third failing fingerprint denied', static fn(string $v): string => $rr($v, 'F06.R1.record-only-churn')['thirdDenied'] ? 'yes' : 'no');
    $line('R1c four SUCCESSFUL fingerprints: any denied', static fn(string $v): string => $rr($v, 'F06.R1c.no-corroboration')['anyDenied'] ? 'yes' : 'no');
    $line('R3 concurrent distinct failures: rounds with K2 block (of 12)', static fn(string $v): string => (string) $rr($v, 'F06.R3.distinct-member-race')['roundsWithK2Block']);
    $line('R3 window set <= cap 3 / TTL <= 60 / marker present (of 12)', static fn(string $v): string => isset($rr($v, 'F06.R3.distinct-member-race')['roundsWindowSetWithinCap3']) ? $rr($v, 'F06.R3.distinct-member-race')['roundsWindowSetWithinCap3'] . '/' . $rr($v, 'F06.R3.distinct-member-race')['roundsWindowTtlWithin60'] . '/' . $rr($v, 'F06.R3.distinct-member-race')['roundsWithMarker'] : 'n/a');
    $line('R4 duplicate-fingerprint race: rounds with K2 block (of 12)', static fn(string $v): string => (string) $rr($v, 'F06.R4.duplicate-member-race')['roundsWithK2Block']);
    $line('R6 lone legit allowed 63 s after trigger / one fresh failure enforced', static function (string $v) use ($rr): string {
        $r = $rr($v, 'F06.R6.live-recovery');

        return isset($r['applicable']) ? 'not run' : ($r['reblockedAfter63sFromHistory'] ? 're-blocked from history' : 'allowed') . ' / ' . $r['singleFreshFailureAfterExpiry'];
    });
    $line('R7 Redis outage', static fn(string $v): string => (string) $rr($v, 'F06.R7.redis-outage')['result']);
    $md[] = '';
}

$md[] = '## 10. Indistinguishability (PR #104 A06, unchanged, every variant)';
$md[] = '';
foreach (['S0', 'PSC'] as $v) {
    foreach ($F['a06_indistinguishability'][$v] as $r) {
        if (($r['s'] ?? '') === 'A06.pair') {
            $md[] = '- `' . $v . '` ' . $r['pair'] . ': identical observable digest, labelled-legit denied ' . $r['labelledLegit']['denied'] . ' = labelled-attack denied ' . $r['labelledAttack']['denied'] . ' → identical treatment: **' . ($r['identicalTreatment'] ? 'yes' : 'NO') . '**';
        }
    }
}
$md[] = '';
$md[] = '## 11. Gates and verdict';
$md[] = '';
$md[] = 'Vocabulary: `PASS | FAIL | UNPROVEN | NOT_APPLICABLE | OWNER_DECISION_REQUIRED`.';
$md[] = '';
$md[] = '| Gate | Status | Title |';
$md[] = '|---|---|---|';
foreach ($gates as $row) {
    $md[] = '| ' . $row['gate'] . ' | **' . $row['status'] . '** | ' . $row['title'] . ' |';
}
$md[] = '';
foreach ($gates as $row) {
    $md[] = '- **' . $row['gate'] . '** — ' . $row['evidence'];
}
$md[] = '';
$md[] = '### Segment verdicts (derived)';
$md[] = '';
foreach ($segments as [$name, $v]) {
    $md[] = "- $name: **$v**";
}
$md[] = '';
$md[] = '**Unified default (PSC): ' . $unified . '.**';
$md[] = '';
file_put_contents("$out/summary.md", rtrim(implode("\n", $md)) . "\n");
echo 'gates: ' . count($gates) . ', verdict: ' . $unified . "\n";
exit(0);
