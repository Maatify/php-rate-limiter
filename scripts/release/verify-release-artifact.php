<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Maatify\RateLimiter\Tests\Support\ReleaseVerification\EvidenceWriter;
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

$evidenceDestination = $options['output-evidence'] ?? null;

if (
    isset($options['help'])
    || ! isset($options['target'], $options['candidate-sha'])
    || ! is_string($semanticReviewFile)
    || ! is_string($evidenceDestination)
    || trim($evidenceDestination) === ''
) {
    fwrite(STDOUT, <<<'HELP'
Usage: php scripts/release/verify-release-artifact.php --target=<SemVer> --candidate-sha=<SHA> --semantic-review-file=<path> --output-evidence=<evidence-path> [options]

Pre-publication Release Artifact Verification (CI Workflow Standard §2.5, §2.7; Composer Package Standard §26).

Required arguments:
  --target=<version>              Exact target SemVer version (e.g. 1.0.0-rc.3)
  --candidate-sha=<sha>           Exact candidate commit SHA (40 hex characters)
  --semantic-review-file=<f>      Path to canonical semantic review evidence JSON file (alias: --semantic-review-record);
                                  its reviewed_at must not be later than the RAV start
  --output-evidence=<evidence-path>
                                  MANDATORY durable destination for the qualification evidence. It must be OUTSIDE
                                  the candidate repository and must not already exist. PASS is reported only after
                                  the complete evidence is persisted and read back; any persistence failure is FAIL.

Options:
  --repo-path=<path>              Repository root path (default: current directory)
  --delivery-policy=<mode>        Intended delivery policy (dist or source-only, default: dist)
  --source-only-decision-id=<id>  Decision ID when delivery policy is source-only (e.g. DEC-019)
  --source-only-decision-file=<f> Path to source-only Decision Record file
  --source-only-commit=<sha>      Immutable 40-hex commit of the source-only Decision Record (verified from Git
                                  objects; a string is never trusted)
  --output-json=<path>            Optional verification report JSON (also outside the repository; never overwritten)
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
    'is_qualifying' => true,
];

$repoRoot = (string) $verifyOptions['repo_path'];

// The optional report destination is validated BEFORE verification so a bad path can never
// leave valid evidence behind next to a failed command.
if (isset($options['output-json'])) {
    $reportDestination = is_string($options['output-json'])
        ? (new EvidenceWriter())->resolveDestination($options['output-json'], $repoRoot)
        : ['status' => 'FAIL', 'message' => '--output-json requires a path.'];
    if ($reportDestination['status'] === 'FAIL') {
        fwrite(STDERR, $reportDestination['message'] . "\n");
        exit(2);
    }
}

$result = $verifier->verifyQualifying($verifyOptions, $evidenceDestination);

if (isset($options['output-json']) && is_string($options['output-json'])) {
    $reportWrite = (new EvidenceWriter())->write($options['output-json'], $result, $repoRoot);
    if ($reportWrite['status'] === 'FAIL') {
        $result['status'] = 'FAIL';
        $result['failures'][] = $reportWrite['message'];
    }
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
    fwrite(STDOUT, sprintf("  Qualification evidence persisted: %s\n", $result['evidence_path'] ?? 'UNKNOWN'));
    fwrite(STDOUT, "\nOVERALL RESULT: PASS\n");
    exit(0);
}

fwrite(STDERR, "\nOVERALL RESULT: FAIL\n");
foreach ($result['failures'] as $failure) {
    fwrite(STDERR, sprintf("  - %s\n", $failure));
}
exit(1);
