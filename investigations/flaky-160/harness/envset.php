<?php
// PROVENANCE: copy of investigations/flaky-157/harness/envset.php at 8b79e9b, sha256 7bae9faa98e1b0b23fc848f6f15f261d375ad0c3bd5860ba5c2b60e082ee6ca5; delete this line to get the original byte for byte.

declare(strict_types=1);

// #157 resolved-set comparison (Lead condition 1 before arm L). Reads every
// env/<label>/ written by env.sh under the given directories (downloaded
// artifacts) and prints one JSON document: per label the resolved set (name,
// version and source reference of every package and dev package), the PHPUnit
// and PHP version lines, and every label whose set or PHPUnit differs from the
// reference job's. A label found in several artifacts with different contents
// is listed under "conflicts".
//
// Usage: php envset.php <dir> [...]

namespace Flaky157;

/** @return array{lockset_sha256: string, packages: int} */
function lockset(string $path): array
{
    $lock = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $lines = [];
    foreach (['packages', 'packages-dev'] as $section) {
        foreach ($lock[$section] ?? [] as $package) {
            $lines[] = $section . ' ' . $package['name'] . ' ' . $package['version'] . ' '
                . ($package['source']['reference'] ?? $package['dist']['reference'] ?? '-');
        }
    }
    sort($lines);
    return ['lockset_sha256' => hash('sha256', implode("\n", $lines)), 'packages' => count($lines)];
}

function firstLine(string $path): ?string
{
    if (! is_file($path)) {
        return null;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        return trim($line);
    }
    return null;
}

$labels = [];
$conflicts = [];
foreach (array_slice($argv, 1) as $base) {
    foreach (glob(rtrim($base, '/') . '/{,*/,*/*/}env/*/composer.lock', GLOB_BRACE) ?: [] as $lockPath) {
        $dir = dirname($lockPath);
        $label = basename($dir);
        $entry = lockset($lockPath) + [
            'phpunit' => firstLine($dir . '/phpunit-version'),
            'php' => firstLine($dir . '/php-version'),
        ];
        if (is_file($dir . '/h-head.composer.lock')) {
            $entry['h_head'] = lockset($dir . '/h-head.composer.lock') + ['phpunit' => firstLine($dir . '/h-head.phpunit-version')];
        }
        if (isset($labels[$label]) && $labels[$label] !== $entry) {
            $conflicts[$label][] = $entry;
            continue;
        }
        $labels[$label] = $entry;
    }
}
ksort($labels, SORT_NATURAL);

$groups = [];
foreach ($labels as $label => $entry) {
    $groups[$entry['lockset_sha256'] . ' | ' . $entry['phpunit']][] = $label;
}
$reference = $labels['reference'] ?? null;
$differs = [];
if ($reference !== null) {
    foreach ($labels as $label => $entry) {
        if ($entry['lockset_sha256'] !== $reference['lockset_sha256'] || $entry['phpunit'] !== $reference['phpunit']) {
            $differs[] = $label;
        }
    }
}

echo json_encode([
    'jobs' => count($labels),
    'reference' => $reference,
    'differs_from_reference' => $reference === null ? 'no reference job in these artifacts' : $differs,
    'groups' => $groups,
    'conflicts' => $conflicts,
    'labels' => $labels,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
