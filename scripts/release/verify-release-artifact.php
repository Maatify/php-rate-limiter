<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Maatify\RateLimiter\Tests\Support\ReleaseVerification\ReleaseArtifactVerifier;

$options = getopt('', [
    'target:',
    'candidate-sha:',
    'repo-path::',
    'semantic-review-file::',
    'semantic-review-record::',
    'require-semantic-review::',
    'skip-semantic-review',
    'skip-clean-git',
    'no-clean-check',
    'output-json::',
    'format::',
    'help',
]);

if (isset($options['help']) || ! isset($options['target'], $options['candidate-sha'])) {
    fwrite(STDOUT, <<<'HELP'
Usage: php scripts/release/verify-release-artifact.php --target=<SemVer> --candidate-sha=<SHA> [options]

Pre-publication Release Artifact Verification (CI Workflow Standard §2.5).

Required arguments:
  --target=<version>          Exact target SemVer version (e.g. 1.0.0-rc.3)
  --candidate-sha=<sha>       Exact candidate commit SHA (40 hex characters)

Options:
  --repo-path=<path>          Repository root path (default: current directory)
  --semantic-review-file=<f>  Path to semantic review evidence JSON file (alias: --semantic-review-record)
  --skip-semantic-review      Skip semantic review evidence check (for dry-run only)
  --skip-clean-git            Skip clean Git tree requirement (alias: --no-clean-check)
  --format=<summary|json>     Console output format (default: summary)
  --output-json=<path>        Write machine-readable JSON report to file
  --help                      Show this help message

HELP);
    exit(isset($options['help']) ? 0 : 2);
}

$verifier = new ReleaseArtifactVerifier();

$semanticReviewFile = $options['semantic-review-file'] ?? $options['semantic-review-record'] ?? null;
$skipCleanGit = isset($options['skip-clean-git']) || isset($options['no-clean-check']);

$verifyOptions = [
    'target' => (string) $options['target'],
    'candidate_sha' => (string) $options['candidate-sha'],
    'repo_path' => isset($options['repo-path']) ? (string) $options['repo-path'] : (string) realpath(__DIR__ . '/../..'),
    'semantic_review_file' => $semanticReviewFile !== null ? (string) $semanticReviewFile : null,
    'require_semantic_review' => ! isset($options['skip-semantic-review']),
    'require_clean_git' => ! $skipCleanGit,
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
