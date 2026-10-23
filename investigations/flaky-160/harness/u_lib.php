<?php

declare(strict_types=1);

// #160 arm U definitions (PLAN.md §1.1, §4, §12.2 LT-160-02/03). Shares the
// copied #157 lib.php (junit reading, Wilson, the #157 target for V-ORD).

namespace Flaky160;

require_once __DIR__ . '/lib.php';

const STORE_TEST = 'Maatify\\RateLimiter\\Tests\\Integration\\Redis\\RedisFullCapabilityStoreIntegrationTest';
const MIXED_TEST = 'Maatify\\RateLimiter\\Tests\\Integration\\Redis\\KnownDeviceMixedVersionIntegrationTest';
const ALLOW_TEST = 'Maatify\\RateLimiter\\Tests\\Integration\\Redis\\KnownDeviceAllowanceConcurrencyIntegrationTest';

/** The six methods of PLAN §1.1, by id. */
const SIX = [
    'T-K4' => [STORE_TEST, 'testLifecycleMutationPreservesPhysicalAndAuthoritativeExpiryAcrossAllK4Boundaries'],
    'T-CLAIM' => [STORE_TEST, 'testGenerationBoundLifecycleIsAtomicAndClaimDoesNotConsumeEvidence'],
    'T-S0A' => [MIXED_TEST, 'testAlternatingWorkersOnTheTrustedBurst'],
    'T-S0B' => [MIXED_TEST, 'testS0WorkerHonorsANewK5BlockForThatDeviceOnly'],
    'T-ALLOW' => [ALLOW_TEST, 'testLoginOverlapSplitsIntoRecordedConflictAndEarlyBlockExactly'],
    'T-HAND' => [STORE_TEST, 'testConcurrentPreviousHandoffHasNoDoubleSeedAndGenerationMutationsHaveNoLostUpdates'],
];

/** #157 arm L (REPORT §4): runs with a failure in one of the six. */
const L_FAILED = 11;
const L_RUNS = 3300;

/** The #157 committed reference facts U must reproduce (LT-160-04). */
const REF_K = 1329;
const REF_SEQUENCE_SHA256 = 'fccd09eb496600e55e6c642c9c2693d1d6c9f5c8776dfd7f3d8024a4df39f3b3';
const REF_PREFIX_FILES = 75;

/**
 * Ordinal (1-based) of every one of the six and of the #157 target.
 *
 * @param list<array{class: string, name: string, file: string, failure: ?string}> $cases
 * @return array<string, ?int>
 */
function ordinals(array $cases): array
{
    $wanted = SIX + ['TARGET-157' => [\Flaky157\TARGET_CLASS, \Flaky157\TARGET_METHOD]];
    $found = array_fill_keys(array_keys($wanted), null);
    foreach ($cases as $index => $case) {
        foreach ($wanted as $id => [$class, $method]) {
            if ($found[$id] === null && $case['class'] === $class && $case['name'] === $method) {
                $found[$id] = $index + 1;
            }
        }
    }
    return $found;
}

/** @param list<array{class: string, name: string}> $cases */
function sequenceSha(array $cases, int $length): string
{
    $sequence = array_map(static fn(array $case): string => $case['class'] . '::' . $case['name'], array_slice($cases, 0, $length));
    return hash('sha256', implode("\n", $sequence));
}

/**
 * First line of a junit failure/error text after the "failure: " / "error: "
 * prefix, and the assertion lines in the test's own file.
 *
 * @return array{kind: string, first_line: string, lines: list<int>}
 */
function describeFailure(string $text, string $file): array
{
    $kind = str_starts_with($text, 'error: ') ? 'error' : 'failure';
    $body = substr($text, strlen($kind) + 2);
    $basename = preg_quote(basename($file), '/');
    preg_match_all('/' . $basename . ':(\d+)/', $body, $lines);
    // junit's text starts with "Class::method\n" then the message.
    $parts = explode("\n", trim($body));
    $first = count($parts) > 1 ? $parts[1] : $parts[0];
    return ['kind' => $kind, 'first_line' => $first, 'lines' => array_values(array_unique(array_map('intval', $lines[1])))];
}

/** Two-sided Fisher exact p-value for the 2x2 table [[a, b], [c, d]]. */
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
