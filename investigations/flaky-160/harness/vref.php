<?php

declare(strict_types=1);

// #160 V-REF (PLAN.md §4.3, §12.2 LT-160-04). After prefix.php has written
// prefix.xml and kref.json from a fresh reference run, this checks the three
// registered facts against #157's committed reference and writes vref.json:
//   * the prefix file list (repository-relative, in order) equals the 75 files
//     of investigations/flaky-157/logs/l/E-CI/prefix.xml;
//   * k_ref = 1329;
//   * sequence_sha256 = fccd09eb…
// It also records the reference ordinal of each of the six methods and of the
// #157 target, which check_u.php requires of every U run (V-ORD).
// Exit 1 on any mismatch: U does not start (run.sh refuses without ok=true).
//
// Usage: php vref.php --root=<repo> --out-dir=<dir with prefix.xml, kref.json, reference.junit.xml>
//          [--prefix=<other prefix xml>] (detector: check a perturbed list)

namespace Flaky160;

require __DIR__ . '/u_lib.php';

$options = getopt('', ['root:', 'out-dir:', 'prefix:']);
$root = (string) ($options['root'] ?? '');
$outDir = (string) ($options['out-dir'] ?? '');
$prefixPath = (string) ($options['prefix'] ?? $outDir . '/prefix.xml');
if (! is_dir($root) || ! is_file($prefixPath) || ! is_file($outDir . '/kref.json') || ! is_file($outDir . '/reference.junit.xml')) {
    fwrite(STDERR, "usage: vref.php --root=<repo> --out-dir=<dir> [--prefix=<xml>]\n");
    exit(2);
}

/** @return list<string> repository-relative test files of a prefix configuration, in order */
function prefixFiles(string $path): array
{
    $xml = simplexml_load_file($path);
    if ($xml === false) {
        throw new \RuntimeException("unreadable prefix: {$path}");
    }
    $files = [];
    foreach ($xml->xpath('//testsuite/file') ?: [] as $file) {
        $text = (string) $file;
        $at = strpos($text, '/tests/');
        $files[] = $at === false ? $text : substr($text, $at + 1);
    }
    return $files;
}

$expectedFiles = prefixFiles($root . '/investigations/flaky-157/logs/l/E-CI/prefix.xml');
$files = prefixFiles($prefixPath);
$kref = json_decode((string) file_get_contents($outDir . '/kref.json'), true, 8, JSON_THROW_ON_ERROR);
$cases = \Flaky157\junitCases($outDir . '/reference.junit.xml');
$ordinals = ordinals($cases);

$checks = [
    'files_equal_157' => $files === $expectedFiles,
    'files_count' => count($files),
    'files_count_157' => count($expectedFiles),
    'k_ref' => $kref['k_ref'],
    'k_ref_ok' => $kref['k_ref'] === REF_K,
    'sequence_sha256' => $kref['sequence_sha256'],
    'sequence_ok' => $kref['sequence_sha256'] === REF_SEQUENCE_SHA256,
    'reference_cases' => count($cases),
    'reference_failures' => count(array_filter($cases, static fn(array $case): bool => $case['failure'] !== null)),
    'ordinals' => $ordinals,
    'all_six_in_prefix' => ! in_array(null, $ordinals, true) && array_filter(
        $ordinals,
        static fn(int $ordinal): bool => ! in_array(substr($cases[$ordinal - 1]['file'], (int) strpos($cases[$ordinal - 1]['file'], '/tests/') + 1), $files, true),
    ) === [],
];
if (! $checks['files_equal_157']) {
    $checks['files_only_here'] = array_values(array_diff($files, $expectedFiles));
    $checks['files_only_157'] = array_values(array_diff($expectedFiles, $files));
}
$checks['ok'] = $checks['files_equal_157'] && $checks['files_count'] === REF_PREFIX_FILES && $checks['k_ref_ok']
    && $checks['sequence_ok'] && $checks['all_six_in_prefix'];

$name = isset($options['prefix']) ? 'vref-' . basename($prefixPath, '.xml') . '.json' : 'vref.json';
file_put_contents($outDir . '/' . $name, json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo ($checks['ok'] ? 'V-REF OK' : 'V-REF FAIL') . " files={$checks['files_count']} equal_157=" . var_export($checks['files_equal_157'], true)
    . " k_ref={$checks['k_ref']} sequence_ok=" . var_export($checks['sequence_ok'], true) . " out={$outDir}/{$name}\n";
exit($checks['ok'] ? 0 : 1);
