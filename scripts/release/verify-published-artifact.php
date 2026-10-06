<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Maatify\RateLimiter\Tests\Support\ReleaseVerification\PublishedArtifactVerifier;

$options = getopt('', [
    'package::',
    'target:',
    'qualified-sha:',
    'qualified-reference::',
    'expected-mode::',
    'source-only-decision::',
    'source-only-decision-file::',
    'composer-repository::',
    'installed-path::',
    'installed-json-path::',
    'output-json::',
    'format::',
    'keep-temp',
    'help',
]);

if (isset($options['help']) || ! isset($options['target'], $options['qualified-sha'])) {
    fwrite(STDOUT, <<<'HELP'
Usage: php scripts/release/verify-published-artifact.php --target=<SemVer> --qualified-sha=<SHA> [options]

Post-publication Published Artifact Verification (CI Workflow Standard §2.6).

Required arguments:
  --target=<version>              Exact target SemVer version (e.g. 1.0.0-rc.2)
  --qualified-sha=<sha>           Intended qualified commit SHA (40 hex characters)

Options:
  --package=<name>                Package identity (default: maatify/php-rate-limiter)
  --qualified-reference=<ref>     Intended qualified Git tag or ref (default: target version)
  --expected-mode=<dist|source>   Expected installation mode (default: dist)
  --source-only-decision=<id>     Decision ID if canonical delivery is source-only
  --source-only-decision-file=<f> Path to source-only Decision Record file
  --composer-repository=<repo>    Custom Composer repository URL or JSON definition
  --installed-path=<path>         Inspect already-installed package path (offline/inspection mode)
  --installed-json-path=<path>    Inspect already-obtained installed.json (offline/inspection mode)
  --format=<summary|json>         Console output format (default: summary)
  --output-json=<path>            Write machine-readable JSON report to file
  --keep-temp                     Retain temporary isolated consumer environment
  --help                          Show this help message

HELP);
    exit(isset($options['help']) ? 0 : 2);
}

$verifier = new PublishedArtifactVerifier();

$verifyOptions = [
    'package' => isset($options['package']) ? (string) $options['package'] : PublishedArtifactVerifier::DEFAULT_PACKAGE_NAME,
    'target' => (string) $options['target'],
    'qualified_sha' => (string) $options['qualified-sha'],
    'qualified_reference' => isset($options['qualified-reference']) ? (string) $options['qualified-reference'] : null,
    'expected_mode' => isset($options['expected-mode']) ? (string) $options['expected-mode'] : 'dist',
    'source_only_decision' => isset($options['source-only-decision']) ? (string) $options['source-only-decision'] : null,
    'source_only_decision_file' => isset($options['source-only-decision-file']) ? (string) $options['source-only-decision-file'] : null,
    'composer_repository' => isset($options['composer-repository']) ? (string) $options['composer-repository'] : null,
    'installed_path' => isset($options['installed-path']) ? (string) $options['installed-path'] : null,
    'installed_json_path' => isset($options['installed-json-path']) ? (string) $options['installed-json-path'] : null,
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
