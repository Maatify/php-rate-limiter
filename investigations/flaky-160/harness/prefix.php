<?php
// PROVENANCE: copy of investigations/flaky-157/harness/prefix.php at 8b79e9b, sha256 c58bf52028895b69cb85111d4e632735c19852ef2b24af56f27e11d4f8058be7; delete this line to get the original byte for byte.

declare(strict_types=1);

// #157 prefix suite (PLAN.md §9.1 LT-157-03, §9.2 V-ORD).
//
// From the junit of a full core-suite reference run (empty result cache, no
// tap) this writes:
//   * a PHPUnit configuration with the repository's phpunit.xml attributes,
//     cacheResult="false", and one testsuite listing the distinct test files in
//     first-appearance order up to and including the target class's file;
//   * kref.json: the target test's ordinal in the reference run (k_ref) and the
//     reference testcase sequence up to it.
//
// Usage: php prefix.php --root=<repo> --junit=<reference.junit.xml> --out-dir=<dir> [--perturb]
//   --perturb (detector demonstration only, PLAN §10): drops the first file, so V-ORD must fire.

namespace Flaky157;

require __DIR__ . '/lib.php';

$options = getopt('', ['root:', 'junit:', 'out-dir:', 'perturb']);
$root = realpath((string) ($options['root'] ?? ''));
$junit = (string) ($options['junit'] ?? '');
$outDir = (string) ($options['out-dir'] ?? '');
if ($root === false || ! is_file($junit) || ! is_dir($outDir)) {
    fwrite(STDERR, "usage: prefix.php --root=<repo> --junit=<file> --out-dir=<dir> [--perturb]\n");
    exit(2);
}

$cases = junitCases($junit);
$ordinal = targetOrdinal($cases);
if ($ordinal === null) {
    fwrite(STDERR, "prefix: the target test is not in the reference junit\n");
    exit(1);
}
// junit paths are absolute on the machine that ran the reference; keep them repository-relative.
$relative = static function (string $file): string {
    $at = strpos($file, '/tests/');
    if ($at === false) {
        throw new \RuntimeException("prefix: junit file outside tests/: {$file}");
    }
    return substr($file, $at + 1);
};
$files = [];
foreach ($cases as $case) {
    $file = $relative($case['file']);
    if (! in_array($file, $files, true)) {
        $files[] = $file;
    }
    if ($file === TARGET_FILE) {
        break;
    }
}
if (end($files) !== TARGET_FILE) {
    fwrite(STDERR, "prefix: the target file was not reached in the reference junit\n");
    exit(1);
}
if (isset($options['perturb'])) {
    array_shift($files);
}

$document = new \DOMDocument();
$document->preserveWhiteSpace = false;
$document->formatOutput = true;
$document->load($root . '/phpunit.xml');
$phpunit = $document->documentElement;
$phpunit->setAttribute('bootstrap', $root . '/vendor/autoload.php');
$phpunit->setAttribute('cacheResult', 'false');
$phpunit->removeAttribute('cacheDirectory');
$phpunit->setAttribute('colors', 'false');
foreach (iterator_to_array($phpunit->getElementsByTagName('testsuites')) as $old) {
    $phpunit->removeChild($old);
}
$testsuites = $document->createElement('testsuites');
$suite = $document->createElement('testsuite');
$suite->setAttribute('name', 'prefix-157');
foreach ($files as $file) {
    $suite->appendChild($document->createElement('file', $root . '/' . $file));
}
$testsuites->appendChild($suite);
$phpunit->appendChild($testsuites);

$name = isset($options['perturb']) ? 'prefix-perturbed' : 'prefix';
$document->save($outDir . '/' . $name . '.xml');
if (isset($options['perturb'])) {
    echo "perturbed: dropped the first file; files=" . count($files) . " out={$outDir}/{$name}.xml\n";
    exit(0);
}
$sequence = array_map(static fn(array $case): string => $case['class'] . '::' . $case['name'], array_slice($cases, 0, $ordinal));
file_put_contents($outDir . '/kref.json', json_encode([
    'k_ref' => $ordinal,
    'reference_cases' => count($cases),
    'reference_sha256' => hash_file('sha256', $junit),
    'prefix_files' => count($files),
    'sequence_sha256' => hash('sha256', implode("\n", $sequence)),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "k_ref={$ordinal} files=" . count($files) . " out={$outDir}/{$name}.xml\n";
