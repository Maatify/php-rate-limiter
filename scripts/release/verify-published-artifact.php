<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Maatify\RateLimiter\Tests\Support\ReleaseVerification\PublishedArtifactVerifier;

$options = getopt('', [
    'qualification-evidence::',
    'rav-evidence::',
    'target::',
    'qualified-sha::',
    'package::',
    'composer-repository::',
    'output-json::',
    'format::',
    'keep-temp',
    'help',
]);

$qualificationEvidenceFile = $options['qualification-evidence'] ?? $options['rav-evidence'] ?? null;

if (isset($options['help']) || $qualificationEvidenceFile === null) {
    fwrite(STDOUT, <<<'HELP'
Usage: php scripts/release/verify-published-artifact.php --qualification-evidence=<path> [options]

Post-publication Published Artifact Verification (CI Workflow Standard §2.6, §2.7; Composer Package Standard §26).

Required arguments:
  --qualification-evidence=<f>    Path to machine-readable RAV qualification evidence JSON (alias: --rav-evidence)

Options:
  --target=<version>              Expected target SemVer version (must match qualification evidence)
  --qualified-sha=<sha>           Expected qualified commit SHA (must match qualification evidence)
  --package=<name>                Package identity (default: maatify/php-rate-limiter)
  --composer-repository=<url>     Optional assertion only: MUST equal the approved channel bound in the
                                  qualification evidence, otherwise PAV FAILS (it never selects the channel)
  --format=<summary|json>         Console output format (default: summary)
  --output-json=<path>            Write machine-readable JSON report to file
  --keep-temp                     Retain temporary isolated consumer environment
  --help                          Show this help message

HELP);
    exit(isset($options['help']) ? 0 : 2);
}

$verifier = new PublishedArtifactVerifier();

$verifyOptions = [
    'qualification_evidence_file' => (string) $qualificationEvidenceFile,
    'package' => isset($options['package']) ? (string) $options['package'] : PublishedArtifactVerifier::DEFAULT_PACKAGE_NAME,
    'target' => isset($options['target']) ? (string) $options['target'] : null,
    'qualified_sha' => isset($options['qualified-sha']) ? (string) $options['qualified-sha'] : null,
    'composer_repository' => isset($options['composer-repository']) ? (string) $options['composer-repository'] : null,
    'repo_path' => (string) realpath(__DIR__ . '/../..'),
    'keep_temp' => isset($options['keep-temp']),
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
    "Published Artifact Verification for %s (target %s, qualified SHA %s):\n",
    $result['package_name'],
    $result['target_version'],
    $result['qualified_sha'],
));
fwrite(STDOUT, sprintf("  Observed installation mode: %s\n", $result['installation_mode']));
if ($result['installed_path'] !== '') {
    fwrite(STDOUT, sprintf("  Installed package path: %s\n", $result['installed_path']));
}
$audit = $result['composer_audit'];
fwrite(STDOUT, sprintf("  Composer version: %s\n", (string) ($audit['composer_version'] ?? 'UNKNOWN')));
fwrite(STDOUT, sprintf("  Approved channel (qualification-bound): %s\n", (string) ($audit['effective_repository'] ?? 'UNKNOWN')));

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
