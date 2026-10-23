<?php

declare(strict_types=1);

// #160 classifier self-test (PLAN.md §7). Builds synthetic run directories
// from the real reference junit (PHPUnit 11 failure/error text format:
// "Class::method\n<message>\n\n<file>:<line>") and runs check_u.php on each:
//
//   six-failure      a failure in T-K4 (L1847)                -> valid, pooled, no seventh, files kept
//   six-error        an error in T-S0A (validator L62)         -> valid, pooled, no seventh, files kept
//   seventh-failure  a failure in the #157 target (L3749)      -> valid, not pooled, one seventh finding, files kept
//   clean            no failure                                -> valid, not pooled, files deleted
//   order-swapped    two cases before k_ref swapped            -> invalid (V-ORD), files kept
//
// Usage: php selftest_u.php --out-dir=<dir with reference.junit.xml and vref.json>
// Prints one JSON document; exit 1 if any case is classified otherwise.

namespace Flaky160;

require __DIR__ . '/u_lib.php';

$options = getopt('', ['out-dir:']);
$outDir = (string) ($options['out-dir'] ?? '');
$reference = $outDir . '/reference.junit.xml';
if (! is_file($reference) || ! is_file($outDir . '/vref.json')) {
    fwrite(STDERR, "usage: selftest_u.php --out-dir=<dir with reference.junit.xml and vref.json>\n");
    exit(2);
}

/** @return \DOMElement the testcase element of class::method */
function testcase(\DOMDocument $document, string $class, string $method): \DOMElement
{
    foreach ($document->getElementsByTagName('testcase') as $case) {
        if ($case->getAttribute('class') === $class && $case->getAttribute('name') === $method) {
            return $case;
        }
    }
    throw new \RuntimeException("testcase {$class}::{$method} not in the reference junit");
}

function addResult(\DOMDocument $document, string $class, string $method, string $kind, string $type, string $message, string $location): void
{
    $case = testcase($document, $class, $method);
    $element = $document->createElement($kind);
    $element->setAttribute('type', $type);
    $element->appendChild($document->createTextNode("{$class}::{$method}\n{$message}\n\n{$location}"));
    $case->appendChild($element);
}

$cases = [
    'six-failure' => ['exit' => 1, 'expect' => ['valid' => true, 'pooled' => true, 'seventh' => 0, 'kept_files' => true, 'method' => 'T-K4']],
    'six-error' => ['exit' => 2, 'expect' => ['valid' => true, 'pooled' => true, 'seventh' => 0, 'kept_files' => true, 'method' => 'T-S0A']],
    'seventh-failure' => ['exit' => 1, 'expect' => ['valid' => true, 'pooled' => false, 'seventh' => 1, 'kept_files' => true, 'method' => null]],
    'clean' => ['exit' => 0, 'expect' => ['valid' => true, 'pooled' => false, 'seventh' => 0, 'kept_files' => false, 'method' => null]],
    'order-swapped' => ['exit' => 0, 'expect' => ['valid' => false, 'pooled' => false, 'seventh' => 0, 'kept_files' => true, 'method' => null]],
];

$root = '/home/runner/work/php-rate-limiter/php-rate-limiter';
$results = [];
$allHold = true;
foreach ($cases as $name => $spec) {
    $document = new \DOMDocument();
    $document->load($reference);
    switch ($name) {
        case 'six-failure':
            addResult($document, STORE_TEST, SIX['T-K4'][1], 'failure', 'PHPUnit\\Framework\\ExpectationFailedException',
                'Failed asserting that false is true.', "{$root}/tests/Integration/Redis/RedisFullCapabilityStoreIntegrationTest.php:1847");
            break;
        case 'six-error':
            addResult($document, MIXED_TEST, SIX['T-S0A'][1], 'error', 'Maatify\\RateLimiter\\Exception\\RateLimiterException',
                'Maatify\\RateLimiter\\Exception\\RateLimiterException: Bounded correlation store returned an invalid snapshot.',
                "{$root}/src/Service/BoundedCorrelationResultValidator.php:62\n{$root}/tests/Fixtures/S0Baseline/S0EvaluationPipeline.php:800\n{$root}/tests/Integration/Redis/KnownDeviceMixedVersionIntegrationTest.php:160");
            break;
        case 'seventh-failure':
            addResult($document, \Flaky157\TARGET_CLASS, \Flaky157\TARGET_METHOD, 'failure', 'PHPUnit\\Framework\\AssertionFailedError',
                'Malformed persisted Redis state was accepted.', "{$root}/tests/Integration/Redis/RedisFullCapabilityStoreIntegrationTest.php:3749\n{$root}/tests/Integration/Redis/RedisFullCapabilityStoreIntegrationTest.php:1546");
            break;
        case 'order-swapped':
            // The first two adjacent sibling testcases (same suite), swapped.
            $swapped = false;
            foreach ($document->getElementsByTagName('testcase') as $first) {
                $second = $first->nextElementSibling;
                if ($second instanceof \DOMElement && $second->tagName === 'testcase') {
                    $first->parentNode->insertBefore($second, $first);
                    $swapped = true;
                    break;
                }
            }
            if (! $swapped) {
                throw new \RuntimeException('no adjacent sibling testcases to swap');
            }
            break;
    }
    $dir = $outDir . '/selftest/' . $name;
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $document->save($dir . '/junit.xml');
    file_put_contents($dir . '/phpunit.out', "synthetic self-test run\n\nTime: 00:01.000, Memory: 1.00 MB\n");
    $line = shell_exec(sprintf(
        'php %s --arm=selftest --id=0 --dir=%s --vref=%s --exit=%d --redis-port=50000 --wall=1',
        escapeshellarg(__DIR__ . '/check_u.php'),
        escapeshellarg($dir),
        escapeshellarg($outDir . '/vref.json'),
        $spec['exit'],
    ));
    $verdict = json_decode((string) $line, true, 32, JSON_THROW_ON_ERROR);
    $failing = array_keys(array_filter($verdict['six'], static fn(array $entry): bool => $entry['status'] === 'fail'));
    $got = [
        'valid' => $verdict['valid'],
        'pooled' => $verdict['pooled'],
        'seventh' => count($verdict['seventh']),
        'kept_files' => $verdict['kept_files'] && is_file($dir . '/junit.xml') && is_file($dir . '/phpunit.out'),
        'method' => $failing[0] ?? null,
    ];
    $holds = $got === $spec['expect'];
    $allHold = $allHold && $holds;
    $results[$name] = ['holds' => $holds, 'expected' => $spec['expect'], 'got' => $got, 'six' => $verdict['six'], 'seventh_findings' => $verdict['seventh'], 'problems' => $verdict['problems']];
}
echo json_encode(['all_hold' => $allHold, 'cases' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($allHold ? 0 : 1);
