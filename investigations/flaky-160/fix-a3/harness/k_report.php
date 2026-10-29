<?php
// PROVENANCE: copy of investigations/flaky-160/phase3-k4-hand/harness/k_report.php at 5202bf3, sha256 9412ec64cfdb89371fd399ed04d99d7547acdd6ccc30f97f8ccd5e1d09e4235e; delete this line to get the original byte for byte.

declare(strict_types=1);

// #160 phase 3 report (PLAN.md §4, §5, §13): every registered rule applied to the
// rows of every arm present in <dir> (<arm>.rows.jsonl[.gz]); prints one JSON document.
// Rows are streamed and aggregated per group (no arm is held in memory; step S F-S-01).
// Rates carry Wilson 95 %; nothing here decides (a) or (b) — the REPORT lays them out.
//
// Usage: php k_report.php <dir>

namespace Flaky160D;

use function Flaky160B\poissonBand;
use function Flaky160B\readRows;
use function Flaky160B\wilson;

require __DIR__ . '/k_lib.php';

$dir = $argv[1] ?? '';
$rows = static function (string $arm) use ($dir): \Generator {
    foreach (["$dir/$arm.rows.jsonl", "$dir/$arm.rows.jsonl.gz"] as $path) {
        if (is_file($path)) {
            yield from readRows($path);
            return;
        }
    }
};
$rate = static fn(int $k, int $n): array => ['k' => $k, 'n' => $n, 'rate' => $n === 0 ? null : $k / $n, 'wilson95' => wilson($k, $n)];
$invalidShare = static fn(int $invalid, int $total): array => ['invalid' => $invalid, 'total' => $total,
    'share' => $total === 0 ? null : $invalid / $total, 'holds_le_2pct' => $total > 0 && $invalid / $total <= 0.02];

/**
 * Pair rows grouped by $field: classes, not-applied among valid (invalid and invalid_x excluded), phases of every not-applied.
 * $extra(array &$acc, array $row, string $class) may keep more per group.
 */
$pairs = static function (iterable $source, string $field, ?callable $extra = null) use ($rate, $invalidShare): array {
    $groups = [];
    foreach ($source as $row) {
        $group = (string) ($row[$field] ?? '');
        $groups[$group] ??= ['classes' => [], 'phases' => [], 'total' => 0, 'extra' => []];
        $acc = &$groups[$group];
        $class = classifyPair($row);
        $acc['total']++;
        $acc['classes'][$class] = ($acc['classes'][$class] ?? 0) + 1;
        if (str_starts_with($class, 'not_applied') || str_starts_with($class, 'finding')) {
            $acc['phases'][] = is_int($row['x']) && $row['x'] > 0 ? phase($row['x']) : null;
        }
        if ($extra !== null) {
            $extra($acc['extra'], $row, $class);
        }
        unset($acc);
    }
    ksort($groups);
    $out = [];
    foreach ($groups as $group => $acc) {
        $classes = $acc['classes'];
        $invalid = ($classes['invalid'] ?? 0) + ($classes['invalid_x'] ?? 0);
        $mapping = ($classes['not_applied_in_window'] ?? 0) + ($classes['finding_outside_window'] ?? 0);
        $out[$group] = ['classes' => $classes, 'validity' => $invalidShare($invalid, $acc['total']),
            'not_applied' => $rate($mapping + ($classes['not_applied_expired'] ?? 0), $acc['total'] - $invalid),
            'mapping_not_applied' => $mapping, 'findings_outside_window' => $classes['finding_outside_window'] ?? 0,
            'phases_of_not_applied' => $acc['phases'], 'extra' => $acc['extra']];
    }
    return $out;
};
$report = [];

// K-CAL first: N-K2 uses its E|Δn|
$edn = null;
$ns = [];
$deltas = [];
$zero = 0;
$calInvalid = 0;
$calTotal = 0;
foreach ($rows('kcal') as $row) {
    $calTotal++;
    if ($row['error'] !== null) {
        $calInvalid++;
        continue;
    }
    [$a, $b] = $row['pair'];
    $ns[] = $a['n'];
    $ns[] = $b['n'];
    $zero += (int) ($a['n'] === 0) + (int) ($b['n'] === 0);
    $deltas[] = abs($b['n'] - $a['n']);
}
if ($calTotal > 0) {
    $edn = $deltas === [] ? null : array_sum($deltas) / count($deltas);
    $report['kcal'] = ['validity' => $invalidShare($calInvalid, $calTotal), 'n_ms' => quantiles($ns), 'p_n_zero' => $rate($zero, count($ns)),
        'e_abs_delta_n_ms' => $edn, 'delta_n_nonzero' => $rate(count(array_filter($deltas)), count($deltas)),
        'limit' => 'n of a tiny harness EVAL approximates n of the longer store scripts (LT-160D-03)'];
}
unset($ns, $deltas);

foreach (['knat', 'kheld'] as $arm) {
    $kinds = $pairs($rows($arm), 'kind');
    if ($kinds === []) {
        continue;
    }
    foreach ($kinds as &$summary) {
        unset($summary['extra']);
        $summary['n_k1_holds'] = $summary['findings_outside_window'] === 0;
        if ($edn !== null && $arm === 'knat') {
            $lambda = $summary['not_applied']['n'] * $edn / 1000;
            $band = poissonBand($lambda);
            $summary['n_k2'] = ['lambda' => $lambda, 'band95' => $band, 'count' => $summary['mapping_not_applied'],
                'holds' => $summary['mapping_not_applied'] >= $band[0] && $summary['mapping_not_applied'] <= $band[1]];
        }
    }
    unset($summary);
    if (isset($kinds['P-K4'])) {
        foreach (['P-CUR', 'P-PREV'] as $other) {
            if (! isset($kinds[$other])) {
                continue;
            }
            $a = $kinds['P-K4']['not_applied'];
            $b = $kinds[$other]['not_applied'];
            $p = fisherTwoSided($a['k'], $a['n'] - $a['k'], $b['k'], $b['n'] - $b['k']);
            $kinds['fisher']["P-K4 vs $other"] = ['p' => $p, 'verdict' => $p < 0.01 ? 'refuted' : 'not distinguished'];
        }
    }
    $report[$arm] = $kinds;
}
if (isset($report['knat'], $report['kheld'])) {
    foreach (['P-K4', 'P-PREV'] as $kind) {
        $nat = $report['knat'][$kind]['not_applied']['rate'] ?? null;
        $held = $report['kheld'][$kind]['not_applied']['rate'] ?? null;
        $report['kheld']['against_knat'][$kind] = ['knat' => $nat, 'kheld' => $held, 'ratio' => $nat ? $held / $nat : null];
    }
}

$forced = $pairs($rows('kforced'), 'rho', static function (array &$acc, array $row, string $class): void {
    if (in_array($class, ['invalid', 'invalid_x', 'read_null'], true)) {
        return;
    }
    $acc += ['valid' => 0, 'saw_zero' => 0, 'e_r_ok' => true];
    $acc['valid']++;
    $acc['saw_zero'] += (int) readSawZero($row);
    if (! $row['applied']) {
        $acc['e_r_ok'] = $acc['e_r_ok'] && forcedOneEr($row);
    }
});
if ($forced !== []) {
    $out = [];
    foreach ($forced as $rho => $summary) {
        $extra = $summary['extra'] + ['valid' => 0, 'saw_zero' => 0, 'e_r_ok' => true];
        unset($summary['extra']);
        if ($rho === '500') {
            $summary['prediction'] = '0 not-applied';
            $summary['holds'] = $summary['not_applied']['k'] === 0;
        } else {
            $summary['p_r_n_zero'] = $rate($extra['saw_zero'], $extra['valid']);
            $summary['minimum_criterion'] = ['positive' => $summary['not_applied']['k'] > 0, 'e_r_in_set_on_every_not_applied' => $extra['e_r_ok'],
                'holds' => $summary['not_applied']['k'] > 0 && $extra['e_r_ok']];
        }
        $out["rho=$rho"] = $summary;
    }
    $report['kforced'] = $out;
}

$forcedM = $pairs($rows('kforcedm'), 'arm');
if ($forcedM !== []) {
    $summary = reset($forcedM);
    unset($summary['extra']);
    $valid = $summary['not_applied']['n'];
    $applied = $summary['classes']['applied'] ?? 0;
    $summary['p_m_n_zero'] = $rate($applied, $valid);
    if (isset($report['kforced']['rho=1']['p_r_n_zero']['rate'])) {
        $pR = $report['kforced']['rho=1']['p_r_n_zero'];
        $observed = $report['kforced']['rho=1']['not_applied'];
        $pM = $applied / max(1, $valid);
        $band = independenceBand($pR['wilson95'], $summary['p_m_n_zero']['wilson95']);
        $summary['independence'] = ['predicted' => $pR['rate'] * (1 - $pM) + (1 - $pR['rate']) * $pM, 'predicted_band' => $band,
            'observed_rho1' => $observed, 'overlap' => $observed['wilson95'][0] <= $band[1] && $band[0] <= $observed['wilson95'][1]];
    }
    $report['kforcedm'] = $summary;
}

$classes = [];
$phases = [];
$total = 0;
foreach ($rows('hnat') as $row) {
    $total++;
    $class = classifyHand($row);
    $classes[$class] = ($classes[$class] ?? 0) + 1;
    if ($class === 'zero_in_window' || str_starts_with($class, 'finding')) {
        $phases[] = phase($row['x']);
    }
}
if ($total > 0) {
    $invalid = ($classes['invalid'] ?? 0) + ($classes['invalid_x'] ?? 0);
    $zeroWinners = ($classes['zero_in_window'] ?? 0) + ($classes['finding_zero_outside_window'] ?? 0);
    $report['hnat'] = ['classes' => $classes, 'validity' => $invalidShare($invalid, $total),
        'zero_winners' => $rate($zeroWinners, $total - $invalid - ($classes['read_null'] ?? 0)),
        'n_h1_holds' => ($classes['finding_two_or_more'] ?? 0) === 0, 'n_h2_holds' => ($classes['finding_zero_outside_window'] ?? 0) === 0,
        'phases' => $phases, 'limit' => 'the mixed case (some workers matching) is not separable (LT-160D-05)'];
}

$cells = [];
foreach ($rows('ddet') as $row) {
    $cell = $row['test'] . '-' . $row['variant'];
    $class = $row['test'] === 'K' ? classifyPair($row) : classifyHand($row);
    $cells[$cell][$class] = ($cells[$cell][$class] ?? 0) + 1;
}
if ($cells !== []) {
    // D-DET's X ≡ 500 puts the injected not-applied at phase 499, outside WINDOW_MS by construction: there the
    // "finding_*outside*" class is the registered injected outcome, not a finding.
    $expect = ['K-injected' => 'not_applied_in_window|finding_outside_window', 'K-control' => 'applied',
        'H-injected' => 'zero_in_window|finding_zero_outside_window', 'H-control' => 'one_winner'];
    foreach ($expect as $cell => $wanted) {
        $total = array_sum($cells[$cell] ?? []);
        $hits = 0;
        foreach (explode('|', $wanted) as $class) {
            $hits += $cells[$cell][$class] ?? 0;
        }
        $report['ddet'][$cell] = ['classes' => $cells[$cell] ?? [], 'expected' => $wanted, 'hits' => $hits, 'total' => $total, 'holds' => $total > 0 && $hits === $total];
    }
}

$outcomes = [];
foreach ($rows('vclock') as $row) {
    $outcomes[$row['test']][$row['mode']][] = vclockOutcome($row);
}
foreach ($outcomes as $test => $modes) {
    $with = $modes['with'] ?? [];
    $without = $modes['without'] ?? [];
    sort($with);
    sort($without);
    $report['vclock'][$test] = ['with' => array_count_values($with), 'without' => array_count_values($without), 'identical' => $with !== [] && $with === $without];
}

$walls = [];
foreach ($rows('overhead') as $row) {
    if ($row['error'] === null && ! $row['read_null']) {
        $walls[$row['mode']][] = $row['wall_us'];
    }
}
if ($walls !== []) {
    $recorder = quantiles($walls['recorder'] ?? []);
    $direct = quantiles($walls['direct'] ?? []);
    $ratio = ($direct['p50'] ?? 0) > 0 ? $recorder['p50'] / $direct['p50'] : null;
    $report['overhead'] = ['recorder_us' => $recorder, 'direct_us' => $direct, 'median_ratio' => $ratio, 'holds_le_1_25' => $ratio !== null && $ratio <= 1.25];
}

$equal = 0;
$total = 0;
foreach ($rows('xread') as $row) {
    $total++;
    $equal += (int) ($row['error'] === null && $row['x'] === $row['x_target']);
}
if ($total > 0) {
    $report['xread'] = ['equal' => $equal, 'total' => $total, 'holds' => $equal === $total];
}

if (is_file("$dir/timings.jsonl")) {
    foreach (readRows("$dir/timings.jsonl") as $timing) {
        $report['timings'][$timing['unit']] = $timing;
    }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
