<?php
// PROVENANCE: copy of investigations/flaky-160/phase2/harness/stats.php at d37f9a3, sha256 35bf5f7755583f6d198436526818736afbca7590fdd5cad044fd93ca298fa577; delete this line to get the original byte for byte.

declare(strict_types=1);

// Shared statistics for the phase 2 reports: Wilson 95 % and the exact (Garwood) Poisson 95 % interval.

namespace Flaky160B;

function wilson(int $successes, int $trials, float $z = 1.959963984540054): array
{
    if ($trials === 0) {
        return [0.0, 1.0];
    }
    $p = $successes / $trials;
    $denominator = 1 + $z * $z / $trials;
    $centre = ($p + $z * $z / (2 * $trials)) / $denominator;
    $half = $z * sqrt($p * (1 - $p) / $trials + $z * $z / (4 * $trials * $trials)) / $denominator;
    return [max(0.0, $centre - $half), min(1.0, $centre + $half)];
}

/** P(X <= k) for X ~ Poisson(lambda), by log-space summation. */
function poissonCdf(int $k, float $lambda): float
{
    if ($lambda <= 0.0) {
        return 1.0;
    }
    $sum = 0.0;
    $term = -$lambda; // log of e^-lambda * lambda^0 / 0!
    for ($i = 0; $i <= $k; $i++) {
        if ($i > 0) {
            $term += log($lambda) - log($i);
        }
        $sum += exp($term);
    }
    return min(1.0, $sum);
}

/**
 * Two-sided 95 % acceptance band for a Poisson count around lambda: the smallest
 * [lo, hi] with P(X < lo) <= 2.5 % and P(X > hi) <= 2.5 %.
 *
 * @return array{0: int, 1: int}
 */
function poissonBand(float $lambda): array
{
    $lo = 0;
    while ($lo <= 10_000_000 && poissonCdf($lo, $lambda) <= 0.025) {
        $lo++;
    }
    $hi = $lo;
    while (1.0 - poissonCdf($hi, $lambda) > 0.025) {
        $hi++;
    }
    return [$lo, $hi];
}

function readRows(string $path): \Generator
{
    $handle = str_ends_with($path, '.gz') ? gzopen($path, 'rb') : fopen($path, 'rb');
    $gz = str_ends_with($path, '.gz');
    while (($line = $gz ? gzgets($handle) : fgets($handle)) !== false) {
        $line = trim($line);
        if ($line !== '') {
            yield json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        }
    }
}
