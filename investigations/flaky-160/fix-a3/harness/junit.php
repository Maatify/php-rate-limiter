<?php

declare(strict_types=1);

// #160 fix (a3) junit reading (PLAN.md §11 LT-a3-06, LT-a3-07); new, not a copy.
//
//   php junit.php suite <junit.xml>                 one JSON object: tests, failures, errors, skipped (or null)
//   php junit.php decide <main.xml> <head.xml> <s1>  decisionVerdict() as JSON; exit 1 unless it holds
//                                                    (<s1>: comma-separated S1 test method names)

namespace Flaky160D;

require_once __DIR__ . '/k_lib.php';

/** Totals of the root <testsuite>, or null when the file is missing or unreadable. */
function junitSuite(string $path): ?array
{
    $xml = is_file($path) ? @simplexml_load_file($path) : false;
    if ($xml === false || ! isset($xml->testsuite)) {
        return null;
    }
    $suite = $xml->testsuite[0];
    return ['tests' => (int) $suite['tests'], 'failures' => (int) $suite['failures'], 'errors' => (int) $suite['errors'], 'skipped' => (int) $suite['skipped']];
}

/** @return array<string, string> class::name => passed|failure|error|skipped */
function junitOutcomes(string $path): array
{
    $xml = simplexml_load_file($path);
    if ($xml === false) {
        throw new \RuntimeException("unreadable junit $path");
    }
    $outcomes = [];
    foreach ($xml->xpath('//testcase') as $case) {
        $id = (string) $case['class'] . '::' . (string) $case['name'];
        if (isset($outcomes[$id])) {
            throw new \RuntimeException("duplicate testcase $id");
        }
        $outcomes[$id] = isset($case->failure) ? 'failure' : (isset($case->error) ? 'error' : (isset($case->skipped) ? 'skipped' : 'passed'));
    }
    return $outcomes;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    switch ($argv[1] ?? '') {
        case 'suite':
            echo json_encode(junitSuite($argv[2] ?? '')), "\n";
            break;
        case 'decide':
            $verdict = decisionVerdict(junitOutcomes($argv[2]), junitOutcomes($argv[3]), explode(',', $argv[4]));
            echo json_encode($verdict, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
            exit($verdict['holds'] ? 0 : 1);
        default:
            fwrite(STDERR, "usage: junit.php suite <junit> | decide <main> <head> <s1>\n");
            exit(2);
    }
}
