<?php

declare(strict_types=1);

// #160 arm M report (PLAN.md §5.2–§5.4, §12.4, §12.5). Reads the rows of one
// or more sub-arms and prints one JSON document, judging each registered
// prediction. Nothing here changes a prediction; it only counts.
//
// Usage: php m_report.php [--expect=<subarm>:<n>]... <rows.jsonl> [...]

namespace Flaky160;

require __DIR__ . '/lib.php';

const MESSAGE = 'Bounded correlation store returned an invalid snapshot.';
const EXCEPTION = 'Maatify\\RateLimiter\\Exception\\RateLimiterException';

/** @param array<string, mixed> $outcome */
function isSnapshotException(array $outcome): bool
{
    return ($outcome['type'] ?? '') === 'exception' && $outcome['class'] === EXCEPTION && $outcome['message'] === MESSAGE
        && $outcome['thrown_at'] === 'BoundedCorrelationResultValidator.php:62';
}

/** @param list<string> $frames */
function hasFrames(array $outcome, array $frames): bool
{
    return array_diff($frames, $outcome['frames'] ?? []) === [];
}

/** Smallest and largest k with P(X < k) <= 2.5 % and P(X > k) <= 2.5 %, X ~ Poisson(lambda). */
function poissonBand(float $lambda): array
{
    $p = exp(-$lambda);
    $cumulative = $p;
    $low = null;
    $k = 0;
    while (true) {
        if ($low === null && $cumulative >= 0.025) {
            $low = $k;
        }
        if ($cumulative >= 0.975) {
            return [$low ?? 0, $k];
        }
        $k++;
        $p *= $lambda / $k;
        $cumulative += $p;
        if ($k > 100000) {
            return [$low ?? 0, $k];
        }
    }
}

/** @param list<int> $values */
function distribution(array $values): ?array
{
    if ($values === []) {
        return null;
    }
    sort($values);
    $at = static fn(float $q): int => $values[min(count($values) - 1, (int) floor($q * count($values)))];
    return ['n' => count($values), 'min' => $values[0], 'p01' => $at(0.01), 'p50' => $at(0.5), 'p99' => $at(0.99), 'max' => end($values)];
}

$expect = [];
$bySubarm = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--expect=([a-z-]+):(\d+)$/', $argument, $match) === 1) {
        $expect[$match[1]] = (int) $match[2];
        continue;
    }
    foreach (\Flaky157\readJsonl($argument) as $row) {
        $bySubarm[$row['subarm']][] = $row;
    }
}

$report = [];
foreach ($bySubarm as $subarm => $rows) {
    $entry = ['rows' => count($rows), 'expected' => $expect[$subarm] ?? null];
    $entry['shortfall'] = $entry['expected'] === null ? null : $entry['expected'] - count($rows);
    $exceptions = array_values(array_filter($rows, static fn(array $row): bool => ($row['outcome']['type'] ?? '') === 'exception'));
    $entry['exceptions'] = count($exceptions);
    $entry['snapshot_exceptions'] = count(array_filter($rows, static fn(array $row): bool => isSnapshotException($row['outcome'])));
    $entry['other_outcomes'] = [];
    switch ($subarm) {
        case 'det':
        case 'det-control':
        case 'ep-det':
        case 'ep-det-control':
        case 'ep-builder-limit':
        case 'ep-builder-outside':
            $frames = str_starts_with($subarm, 'det') ? ['S0EvaluationPipeline.php:800', 'S0EvaluationPipeline.php:608', 'S0EvaluationPipeline.php:278'] : ['EvaluationPipeline.php:884'];
            $throws = in_array($subarm, ['det', 'ep-det', 'ep-builder-outside'], true);
            $valid = 0;
            $matching = 0;
            foreach ($rows as $row) {
                // Validity: the device-window snapshot (TTL 600) actually ran (PLAN §12.4).
                $reached = array_filter($row['snapshots'] ?? [], static fn(array $s): bool => $s['ttl'] === 600) !== [];
                $valid += $reached ? 1 : 0;
                $ok = $throws
                    ? isSnapshotException($row['outcome']) && hasFrames($row['outcome'], $frames)
                    : ($row['outcome']['type'] ?? '') === 'returned';
                $matching += ($reached && $ok) ? 1 : 0;
                if (! $ok) {
                    $entry['other_outcomes'][] = ['i' => $row['i'], 'outcome' => $row['outcome']];
                }
            }
            $entry['valid'] = $valid;
            $entry['prediction'] = $throws
                ? "invalid snapshot at validator L62 with frames " . implode(', ', $frames) . ' in every trial'
                : 'no exception in any trial';
            $entry['matching'] = $matching;
            $entry['holds'] = $matching === count($rows) && $valid === count($rows) && ($entry['expected'] === null || $entry['shortfall'] === 0);
            if ($subarm === 'ep-builder-limit') {
                $entry['snapshot_caller_time_is_t'] = count(array_filter($rows, static fn(array $row): bool => array_filter(
                    $row['snapshots'] ?? [],
                    static fn(array $s): bool => $s['caller_time'] !== $row['t'],
                ) === []));
                $entry['clock_reads_per_call'] = array_count_values(array_map(static fn(array $row): int => count($row['clock_reads'] ?? []), $rows));
            }
            if ($subarm === 'ep-builder-outside') {
                $entry['pipeline_found'] = count(array_filter($rows, static fn(array $row): bool => $row['pipeline_found'] ?? false));
                $entry['pipeline_clock_is_request_scoped'] = count(array_filter($rows, static fn(array $row): bool => $row['pipeline_clock_is_request_scoped'] ?? false));
            }
            break;
        case 'nat':
        case 'ep-nat':
            $valid = 0;
            $straddles = 0;
            $gs = [];
            $findings = [];
            $phase = array_fill(0, 10, ['pairs' => 0, 'straddles' => 0]);
            foreach ($rows as $row) {
                $pairs = $row['pairs'];
                $complete = array_values(array_filter($pairs, static fn(array $p): bool => $p['complete']));
                if ($complete === [] || count($complete) !== count($pairs)) {
                    $entry['invalid_calls'][] = ['i' => $row['i'], 'pairs' => count($pairs), 'outcome' => $row['outcome']];
                    continue;
                }
                $valid++;
                foreach ($complete as $pair) {
                    $gs[] = $pair['g_us'];
                    $straddles += $pair['straddle'] ? 1 : 0;
                    $bucket = min(9, intdiv($pair['first_us'], 100000));
                    $phase[$bucket]['pairs']++;
                    $phase[$bucket]['straddles'] += $pair['straddle'] ? 1 : 0;
                }
                // N-1: the call throws `invalid snapshot` iff its last pair straddled, and no earlier pair did.
                $last = end($complete);
                $earlier = array_slice($complete, 0, -1);
                $earlierStraddle = array_filter($earlier, static fn(array $p): bool => $p['straddle']) !== [];
                $threw = isSnapshotException($row['outcome']);
                $other = ($row['outcome']['type'] ?? '') === 'exception' && ! $threw;
                if ($other || $threw !== ($last['straddle'] && ! $earlierStraddle) || $earlierStraddle) {
                    $findings[] = ['i' => $row['i'], 'pairs' => $complete, 'outcome' => $row['outcome']];
                }
            }
            $sumG = array_sum($gs);
            $lambda = $sumG / 1e6;
            $entry['valid_calls'] = $valid;
            $entry['pairs'] = count($gs);
            $entry['straddles'] = $straddles;
            $entry['straddle_rate'] = count($gs) > 0 ? $straddles / count($gs) : null;
            $entry['straddle_wilson95'] = \Flaky157\wilson($straddles, count($gs));
            $entry['g_us'] = distribution($gs);
            $entry['g_mean_us'] = count($gs) > 0 ? $sumG / count($gs) : null;
            $entry['n1_discordant_findings'] = $findings;
            $entry['n1_holds'] = $findings === [];
            $entry['n2_expected_straddles'] = $lambda;
            $entry['n2_poisson95_band'] = poissonBand($lambda);
            $entry['n2_holds'] = $straddles >= $entry['n2_poisson95_band'][0] && $straddles <= $entry['n2_poisson95_band'][1];
            $entry['by_first_reading_phase_100ms'] = $phase;
            $entry['call_us'] = distribution(array_map(static fn(array $row): int => $row['outcome']['call_us'], $rows));
            break;
    }
    $report[$subarm] = $entry;
}
ksort($report);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
