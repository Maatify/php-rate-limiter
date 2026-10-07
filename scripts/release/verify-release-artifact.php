<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Maatify\RateLimiter\Tests\Support\ReleaseVerification\ReleaseArtifactVerifier;

$options = getopt('', [
    'target:',
    'candidate-sha:',
    'semantic-review-file::',
    'semantic-review-record::',
    'repo-path::',
    'delivery-policy::',
    'source-only-decision-id::',
    'source-only-decision-file::',
    'source-only-commit::',
    'output-evidence::',
    'output-json::',
    'format::',
    'help',
]);

$semanticReviewFile = $options['semantic-review-file'] ?? $options['semantic-review-record'] ?? null;

if (
    isset($options['help'])
    || ! isset($options['target'], $options['candidate-sha'])
    || $semanticReviewFile === null
) {
    fwrite(STDOUT, <<<'HELP'
Usage: php scripts/release/verify-release-artifact.php --target=<SemVer> --candidate-sha=<SHA> --semantic-review-file=<path> [options]

Pre-publication Release Artifact Verification (CI Workflow Standard §2.5, §2.7; Composer Package Standard §26).

Required arguments:
  --target=<version>              Exact target SemVer version (e.g. 1.0.0-rc.3)
  --candidate-sha=<sha>           Exact candidate commit SHA (40 hex characters)
  --semantic-review-file=<f>      Path to canonical semantic review evidence JSON file (alias: --semantic-review-record)

Options:
  --repo-path=<path>              Repository root path (default: current directory)
  --delivery-policy=<mode>        Intended delivery policy (dist or source-only, default: dist)
  --source-only-decision-id=<id>  Decision ID when delivery policy is source-only (e.g. DEC-019)
  --source-only-decision-file=<f> Path to source-only Decision Record file
  --source-only-commit=<sha>      Immutable 40-hex commit of the source-only Decision Record (verified from Git
                                  objects; a string is never trusted)
  --output-evidence=<path>        Path to write machine-readable RAV qualification evidence JSON
  --output-json=<path>            Path to write verification report JSON
  --format=<summary|json>         Console output format (default: summary)
  --help                          Show this help message

HELP);
    exit(isset($options['help']) ? 0 : 2);
}

$verifier = new ReleaseArtifactVerifier();

$verifyOptions = [
    'target' => (string) $options['target'],
    'candidate_sha' => (string) $options['candidate-sha'],
    'repo_path' => isset($options['repo-path']) ? (string) $options['repo-path'] : (string) realpath(__DIR__ . '/../..'),
    'semantic_review_file' => (string) $semanticReviewFile,
    'delivery_policy' => isset($options['delivery-policy']) ? (string) $options['delivery-policy'] : 'dist',
    'source_only_decision_id' => isset($options['source-only-decision-id']) ? (string) $options['source-only-decision-id'] : null,
    'source_only_decision_file' => isset($options['source-only-decision-file']) ? (string) $options['source-only-decision-file'] : null,
    'source_only_commit' => isset($options['source-only-commit']) ? (string) $options['source-only-commit'] : null,
    'output_evidence' => isset($options['output-evidence']) ? (string) $options['output-evidence'] : null,
    'is_qualifying' => true,
];

$result = $verifier->verify($verifyOptions);

if (isset($options['output-json'])) {
    file_put_contents(
        (string) $options['output-json'],
        (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    );
}

$format = isset($options['format']) ? strtolower((string) $options['format']) : 'summary';

if ($format === 'json') {
    fwrite(STDOUT, (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($result['status'] === 'PASS' ? 0 : 1);
}

fwrite(STDOUT, sprintf(
    "Release Artifact Verification for %s (target %s, candidate SHA %s):\n",
    $result['package_name'],
    $result['target_version'],
    $result['candidate_sha'],
));
if ($result['candidate_tree_sha'] !== '') {
    fwrite(STDOUT, sprintf("  Candidate tree SHA: %s\n", $result['candidate_tree_sha']));
}
fwrite(STDOUT, sprintf("  Delivery policy: %s\n", $result['delivery_policy']));

foreach ($result['checks'] as $name => $check) {
    fwrite(STDOUT, sprintf("  [%s] %s: %s\n", $check['status'], $name, $check['message']));
}

if ($result['status'] === 'PASS') {
    fwrite(STDOUT, "\nOVERALL RESULT: PASS\n");
    exit(0);
}

fwrite(STDERR, "\nOVERALL RESULT: FAIL\n");
foreach ($result['failures'] as $failure) {
    fwrite(STDERR, sprintf("  - %s\n", $failure));
}
exit(1);
