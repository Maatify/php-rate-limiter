<?php
// PROVENANCE: copy of investigations/flaky-160/phase3-k4-hand/harness/k_lib.php at 5202bf3, sha256 6463ccbb43dd11e842db1284e120f2cad139a965e01fd506c5ac55fce98d3562, ADAPTED: adds the a3 classes (classifyA3Pair, classifyA3Hand), decisionVerdict (V-DECISION) and ufilterVerdict; the difference is recorded in PROVENANCE-k_lib.php.diff. Delete this line and reverse that diff to get the original byte for byte.

declare(strict_types=1);

// #160 phase 3 classification rules (PLAN.md §1.2, §4, §13). One function per
// registered rule, so the synthetic detector (D3) exercises exactly the code the
// report uses. Rows are the lines k.php writes.

namespace Flaky160D;

require_once __DIR__ . '/stats.php';

/** Registered window bound of the transferred prediction: (X − 1) mod 1000 < 10 (§2.1; assumes max n < 10 ms, §1.2). */
const WINDOW_MS = 10;

function phase(int $x): int
{
    return (($x - 1) % 1000 + 1000) % 1000;
}

function ceilSeconds(int $milliseconds): int
{
    return intdiv($milliseconds + 999, 1000);
}

/**
 * One legacy READ → MUTATE row (knat, kheld, kforced, kforcedm, ddet K, overhead).
 * Classes: invalid | read_null (E-EXP before READ) | applied | not_applied_expired (E-EXP, §1.3)
 *          | not_applied_in_window | finding_outside_window | invalid_x (forced X not as targeted).
 * For kheld the window is the one LT-160D-06 registers: a boundary in [X − (r_M − h), X − (r_R − h)), with r_M − h bounded
 * by the host time at MUTATE's return (mutate_end_ms − held + 1); for every other arm it is the registered WINDOW_MS.
 */
function classifyPair(array $row): string
{
    if (! array_key_exists('error', $row) || $row['error'] !== null) {
        return 'invalid';
    }
    if (isset($row['x_target']) && $row['x'] !== $row['x_target']) {
        return 'invalid_x';
    }
    if ($row['read_null']) {
        return 'read_null';
    }
    if ($row['applied']) {
        return 'applied';
    }
    if (! is_int($row['x']) || $row['x'] <= 0) {
        return 'not_applied_expired';
    }
    $window = ($row['held'] ?? null) !== null ? $row['mutate_end_ms'] - $row['held'] + 1 : WINDOW_MS;
    return phase($row['x']) < $window ? 'not_applied_in_window' : 'finding_outside_window';
}

/** kforced ρ = 1 minimum criterion (LT-160D-04): every not-applied has e_R ∈ {⌈X/1000⌉, ⌈X/1000⌉ − 1}. */
function forcedOneEr(array $row): bool
{
    $c = ceilSeconds($row['x']);
    return $row['e_r'] === $c || $row['e_r'] === $c - 1;
}

/** [n_R = 0] at X ≡ 1: READ's mapping is ⌈X/1000⌉ (§4.3). */
function readSawZero(array $row): bool
{
    return $row['e_r'] === ceilSeconds($row['x']);
}

/**
 * One T-HAND row (hnat, ddet H, vclock H).
 * Classes: invalid | read_null | one_winner | zero_in_window | finding_two_or_more | finding_zero_outside_window | invalid_x.
 */
function classifyHand(array $row): string
{
    if (! array_key_exists('error', $row) || $row['error'] !== null) {
        return 'invalid';
    }
    if (isset($row['x_target']) && ($row['x'] ?? null) !== $row['x_target']) {
        return 'invalid_x';
    }
    if ($row['read_null']) {
        return 'read_null';
    }
    foreach ($row['exits'] as $index => $exit) {
        if ($exit !== 0 || ! is_array($row['workers'][$index] ?? null) || $row['workers'][$index]['error'] !== null) {
            return 'invalid';
        }
    }
    if ($row['winners'] >= 2) {
        return 'finding_two_or_more';
    }
    if ($row['winners'] === 1) {
        return 'one_winner';
    }
    return phase($row['x']) < WINDOW_MS ? 'zero_in_window' : 'finding_zero_outside_window';
}

/**
 * fix-a3 (PLAN §4.1, §4.3): under a3 a legacy READ → MUTATE pair applies whenever its source is alive, and writes
 * READ's expiry. Classes: those of classifyPair() that are not an outcome (invalid, invalid_x, read_null,
 * not_applied_expired), then applied (e_M = e_R and the stored expiresAt = e_R) | finding_expiry_differs | finding_not_applied.
 */
function classifyA3Pair(array $row): string
{
    $class = classifyPair($row);
    if (in_array($class, ['invalid', 'invalid_x', 'read_null', 'not_applied_expired'], true)) {
        return $class;
    }
    if ($class !== 'applied') {
        return 'finding_not_applied';
    }
    return $row['e_m'] === $row['e_r'] && ($row['e_written'] ?? null) === (string) $row['e_r'] ? 'applied' : 'finding_expiry_differs';
}

/**
 * fix-a3: a T-HAND sequence has exactly one winner, and current then stores READ's expiry. Classes: invalid, invalid_x,
 * read_null and finding_two_or_more as classifyHand(), then one_winner | finding_expiry_differs | finding_zero_winners.
 */
function classifyA3Hand(array $row): string
{
    $class = classifyHand($row);
    if (in_array($class, ['invalid', 'invalid_x', 'read_null', 'finding_two_or_more'], true)) {
        return $class;
    }
    if ($class !== 'one_winner') {
        return 'finding_zero_winners';
    }
    return ($row['e_written'] ?? null) === (string) $row['e_r'] ? 'one_winner' : 'finding_expiry_differs';
}

/**
 * fix-a3 V-DECISION (PLAN §11 LT-a3-06): per-test junit outcomes (class::name => passed|failure|error|skipped) of the
 * Integration and System suites at main@5202bf3 and at the branch head. Holds iff main ran something, every test of main
 * has the same outcome at the head, and the head's extra tests are exactly the S1 methods ($s1), each present and passed.
 *
 * @param array<string, string> $main
 * @param array<string, string> $head
 * @param list<string> $s1 test method names
 */
function decisionVerdict(array $main, array $head, array $s1): array
{
    $differing = [];
    foreach (array_intersect_key($main, $head) as $test => $outcome) {
        if ($head[$test] !== $outcome) {
            $differing[$test] = [$outcome, $head[$test]];
        }
    }
    $onlyMain = array_keys(array_diff_key($main, $head));
    $onlyHead = array_diff_key($head, $main);
    $method = static fn(string $test): string => preg_replace('/ with data set .*\z/s', '', substr($test, (int) strrpos($test, '::') + 2));
    $notS1 = array_keys(array_filter($onlyHead, static fn(string $outcome, string $test): bool => ! in_array($method($test), $s1, true), ARRAY_FILTER_USE_BOTH));
    $s1NotPassed = array_keys(array_filter($onlyHead, static fn(string $outcome): bool => $outcome !== 'passed'));
    $s1Seen = array_values(array_unique(array_map($method, array_keys($onlyHead))));
    $s1Missing = array_values(array_diff($s1, $s1Seen));
    return ['main_tests' => count($main), 'head_tests' => count($head), 'common' => count(array_intersect_key($main, $head)),
        'differing' => $differing, 'only_main' => $onlyMain, 'only_head' => count($onlyHead), 'only_head_not_s1' => $notS1,
        's1_not_passed' => $s1NotPassed, 's1_missing' => $s1Missing,
        'holds' => $main !== [] && $differing === [] && $onlyMain === [] && $notS1 === [] && $s1NotPassed === [] && $s1Missing === []];
}

/**
 * fix-a3 untapped --filter run (PLAN §11 LT-a3-07). Valid iff its junit exists and ran exactly $expected tests, none
 * skipped (a skipped integration test means Redis did not reach it); passed iff PHPUnit exited 0 with no failure or error.
 *
 * @param array{tests: int, failures: int, errors: int, skipped: int}|null $suite
 */
function ufilterVerdict(?array $suite, int $exit, int $expected): array
{
    $valid = $suite !== null && $suite['tests'] === $expected && $suite['skipped'] === 0;
    return ['valid' => $valid, 'passed' => $valid && $exit === 0 && $suite['failures'] === 0 && $suite['errors'] === 0];
}

/** V-CLOCK: the outcome with its time fields relative to the fixed second, so a row with and without the recorder compare. */
function vclockOutcome(array $row): string
{
    $base = intdiv($row['fixed_ms'], 1000);
    $relative = static fn(?int $value): ?int => $value === null ? null : $value - $base;
    if (($row['kind'] ?? '') === 'T-HAND') {
        return json_encode(['H', array_key_exists('error', $row) ? $row['error'] : 'missing', $row['winners'] ?? null, $relative($row['e_r'] ?? null), $row['exits'] ?? null]);
    }
    $state = $row['state'] ?? null;
    return json_encode(['K', array_key_exists('error', $row) ? $row['error'] : 'missing', $row['applied'] ?? null, $relative($row['e_r'] ?? null), $relative($row['e_m'] ?? null),
        $state === null ? null : [$state[0], $relative($state[1]), $relative($state[2]), $state[3]]]);
}

/** Two-sided Fisher exact p for [[a, b], [c, d]]; the definition of investigations/flaky-160/harness/u_lib.php fisherTwoSided (L81). */
function fisherTwoSided(int $a, int $b, int $c, int $d): float
{
    $row1 = $a + $b;
    $col1 = $a + $c;
    $n = $a + $b + $c + $d;
    $logFact = static function (int $k): float {
        static $cache = [0 => 0.0];
        for ($i = count($cache); $i <= $k; $i++) {
            $cache[$i] = $cache[$i - 1] + log($i);
        }
        return $cache[$k];
    };
    $logP = static fn(int $x): float => $logFact($row1) + $logFact($n - $row1) + $logFact($col1) + $logFact($n - $col1)
        - $logFact($n) - $logFact($x) - $logFact($row1 - $x) - $logFact($col1 - $x) - $logFact($n - $row1 - $col1 + $x);
    $observed = $logP($a);
    $sum = 0.0;
    for ($x = max(0, $row1 + $col1 - $n); $x <= min($row1, $col1); $x++) {
        $lp = $logP($x);
        if ($lp <= $observed + 1e-9) {
            $sum += exp($lp);
        }
    }
    return min(1.0, $sum);
}

/** Independence prediction for ρ = 1 (LT-160D-04): f(p, q) = p(1 − q) + (1 − p)q, bilinear, so its range over the two Wilson boxes is at the corners. */
function independenceBand(array $pR, array $pM): array
{
    $f = static fn(float $p, float $q): float => $p * (1 - $q) + (1 - $p) * $q;
    $values = [];
    foreach ($pR as $p) {
        foreach ($pM as $q) {
            $values[] = $f($p, $q);
        }
    }
    return [min($values), max($values)];
}

/** @param list<int|float> $values */
function quantiles(array $values): array
{
    if ($values === []) {
        return [];
    }
    sort($values);
    $at = static fn(float $q) => $values[(int) min(count($values) - 1, floor($q * (count($values) - 1)))];
    return ['min' => $values[0], 'p01' => $at(0.01), 'p50' => $at(0.5), 'p99' => $at(0.99), 'max' => $values[count($values) - 1],
        'mean' => array_sum($values) / count($values), 'count' => count($values)];
}
