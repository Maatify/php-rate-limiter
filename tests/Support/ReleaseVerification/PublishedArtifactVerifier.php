<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\ReleaseVerification;

use RuntimeException;

/**
 * Generic, target-agnostic Published Artifact Verifier.
 *
 * Implements post-publication Published Artifact Verification required by:
 * - CI_WORKFLOW_STANDARD.md §2.6
 * - COMPOSER_PACKAGE_STANDARD.md §26 & §26.1
 * - LIBRARY_PRESENTATION_STANDARD.md §14 & §23
 */
final class PublishedArtifactVerifier
{
    /** @var list<string> */
    public const array REQUIRED_INSTALLED_FILES = [
        'src',
        'composer.json',
        'README.md',
        'LICENSE',
        'CHANGELOG.md',
        'SECURITY.md',
        'RATE_LIMITER_PACKAGE_REFERENCE.md',
        'docs/guides/USAGE_GUIDE.md',
        'examples',
        'llms.txt',
    ];

    public const string DEFAULT_PACKAGE_NAME = 'maatify/php-rate-limiter';

    /**
     * @param array{
     *     package?: string,
     *     target: string,
     *     qualified_sha: string,
     *     qualified_reference?: string|null,
     *     expected_mode?: string,
     *     source_only_decision?: string|null,
     *     source_only_decision_file?: string|null,
     *     composer_repository?: string|null,
     *     working_dir?: string|null,
     *     installed_path?: string|null,
     *     installed_json_path?: string|null,
     *     keep_temp?: bool,
     * } $options
     * @return array{
     *     status: 'PASS'|'FAIL',
     *     package_name: string,
     *     target_version: string,
     *     qualified_sha: string,
     *     installation_mode: string,
     *     installed_path: string,
     *     verified_at: string,
     *     checks: array<string, array{status: 'PASS'|'FAIL', message: string, details?: mixed}>,
     *     failures: list<string>,
     * }
     */
    public function verify(array $options): array
    {
        $package = trim($options['package'] ?? self::DEFAULT_PACKAGE_NAME);
        $target = trim($options['target']);
        $qualifiedSha = trim($options['qualified_sha']);
        $qualifiedReference = $options['qualified_reference'] ?? $target;
        $sourceOnlyDecisionFile = $options['source_only_decision_file'] ?? null;
        $workingDir = $options['working_dir'] ?? null;
        $installedPath = $options['installed_path'] ?? null;
        $installedJsonPath = $options['installed_json_path'] ?? null;
        $keepTemp = $options['keep_temp'] ?? false;

        $createdTempDir = false;
        $tempRoot = null;
        $failures = [];
        $checks = [];
        $observedMode = 'UNKNOWN';
        $finalInstalledPath = '';

        try {
            // Mode A: Offline / Pre-installed inspection (if installed_path and installed_json_path supplied)
            if ($installedPath !== null && $installedJsonPath !== null) {
                if (! file_exists($installedJsonPath)) {
                    throw new RuntimeException('Provided installed_json_path does not exist: ' . $installedJsonPath);
                }
                if (! is_dir($installedPath)) {
                    throw new RuntimeException('Provided installed_path does not exist: ' . $installedPath);
                }
                $finalInstalledPath = realpath($installedPath) ?: $installedPath;
                $installedData = $this->parseInstalledJson($installedJsonPath, $package);
            } else {
                // Mode B: Clean isolated Composer execution
                $tempRoot = $workingDir ?? $this->createIsolatedEnvironment();
                $createdTempDir = ($workingDir === null);

                $installResult = $this->runIsolatedComposerInstall(
                    $tempRoot,
                    $package,
                    $target,
                    $options['composer_repository'] ?? null,
                );

                if ($installResult['status'] === 'FAIL') {
                    $failures[] = $installResult['message'];
                    $checks['composer_resolution'] = $installResult;

                    return [
                        'status' => 'FAIL',
                        'package_name' => $package,
                        'target_version' => $target,
                        'qualified_sha' => $qualifiedSha,
                        'installation_mode' => 'UNRESOLVED',
                        'installed_path' => '',
                        'verified_at' => gmdate('Y-m-d\TH:i:s\Z'),
                        'checks' => $checks,
                        'failures' => $failures,
                    ];
                }

                $checks['composer_resolution'] = $installResult;
                $finalInstalledPath = $tempRoot . '/vendor/' . $package;
                $installedJsonPath = $tempRoot . '/vendor/composer/installed.json';
                $installedData = $this->parseInstalledJson($installedJsonPath, $package);
            }

            // 1. Evaluate Installed Package Metadata and Installation Mode
            $metadataEval = $this->evaluateInstalledMetadata(
                $installedData,
                $target,
                $qualifiedSha,
                $qualifiedReference,
                $sourceOnlyDecisionFile,
            );

            $observedMode = $metadataEval['mode'];
            foreach ($metadataEval['checks'] as $key => $check) {
                $checks[$key] = $check;
                if ($check['status'] === 'FAIL') {
                    $failures[] = $check['message'];
                }
            }

            // 2. Inspect Installed Artifact Content
            $contentEval = $this->inspectInstalledArtifact($finalInstalledPath, $target);
            foreach ($contentEval['checks'] as $key => $check) {
                $checks[$key] = $check;
                if ($check['status'] === 'FAIL') {
                    $failures[] = $check['message'];
                }
            }
        } finally {
            if ($createdTempDir && ! $keepTemp && $tempRoot !== null && is_dir($tempRoot)) {
                $this->removeDirectory($tempRoot);
            }
        }

        return [
            'status' => $failures === [] ? 'PASS' : 'FAIL',
            'package_name' => $package,
            'target_version' => $target,
            'qualified_sha' => $qualifiedSha,
            'installation_mode' => $observedMode,
            'installed_path' => $finalInstalledPath,
            'verified_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'checks' => $checks,
            'failures' => $failures,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function parseInstalledJson(string $installedJsonPath, string $package): array
    {
        $content = file_get_contents($installedJsonPath);
        if ($content === false) {
            throw new RuntimeException('Unable to read installed.json at: ' . $installedJsonPath);
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Malformed JSON in installed.json');
        }

        $packages = $decoded['packages'] ?? $decoded;
        if (! is_array($packages)) {
            throw new RuntimeException('Invalid installed.json format: packages list missing.');
        }

        foreach ($packages as $pkg) {
            if (is_array($pkg) && ($pkg['name'] ?? null) === $package) {
                /** @var array<string, mixed> $pkg */
                return $pkg;
            }
        }

        throw new RuntimeException(sprintf('Package "%s" was not found in installed.json packages list.', $package));
    }

    /**
     * @param array<string, mixed> $installedPkg
     * @return array{
     *     mode: string,
     *     checks: array<string, array{status: 'PASS'|'FAIL', message: string, details?: mixed}>,
     * }
     */
    public function evaluateInstalledMetadata(
        array $installedPkg,
        string $target,
        string $qualifiedSha,
        ?string $qualifiedRef,
        ?string $sourceOnlyDecisionFile,
    ): array {
        $checks = [];
        $mode = 'UNKNOWN';

        // Version Exactness
        $installedVersion = is_scalar($installedPkg['version'] ?? null) ? (string) $installedPkg['version'] : '';
        $normalizedInstalled = ltrim($installedVersion, 'v');
        $normalizedTarget = ltrim($target, 'v');
        if ($normalizedInstalled !== $normalizedTarget) {
            $checks['version_resolution'] = [
                'status' => 'FAIL',
                'message' => sprintf('Resolved package version "%s" does not match exact target version "%s".', $installedVersion, $target),
            ];
        } else {
            $checks['version_resolution'] = [
                'status' => 'PASS',
                'message' => sprintf('Resolved exact target version "%s".', $installedVersion),
            ];
        }

        // Installation Source Mode
        $installSource = $installedPkg['installation-source'] ?? null;
        if (! is_string($installSource) || ! in_array($installSource, ['dist', 'source'], true)) {
            $sourceStr = is_scalar($installSource) ? (string) $installSource : get_debug_type($installSource);
            $checks['installation_mode'] = [
                'status' => 'FAIL',
                'message' => sprintf('Unprovable installation mode: installation-source is "%s", expected "dist" or "source".', $sourceStr),
            ];
        } else {
            $mode = $installSource;
            $distExposed = isset($installedPkg['dist']) && is_array($installedPkg['dist']) && ($installedPkg['dist']['type'] ?? null) !== null;

            if ($distExposed) {
                if ($mode !== 'dist') {
                    $checks['installation_mode'] = [
                        'status' => 'FAIL',
                        'message' => 'Dist archive was exposed by Composer repository metadata, but actual installation mode was source (fallback or preference failure is prohibited).',
                    ];
                } else {
                    $checks['installation_mode'] = [
                        'status' => 'PASS',
                        'message' => 'Verified actual installation mode = dist, matching exposed dist archive.',
                    ];
                }
            } else {
                // Channel exposed no dist, observed mode is source
                if ($mode === 'source') {
                    $pkgName = is_scalar($installedPkg['name'] ?? null) ? (string) $installedPkg['name'] : '';
                    $decisionCheck = $this->evaluateSourceOnlyDecision($sourceOnlyDecisionFile, $pkgName, $target);
                    $checks['source_only_decision'] = $decisionCheck;
                    if ($decisionCheck['status'] === 'FAIL') {
                        $checks['installation_mode'] = [
                            'status' => 'FAIL',
                            'message' => 'Channel exposed no dist and source mode was observed, but no valid pre-existing qualification-time source-only Decision was established.',
                        ];
                    } else {
                        $checks['installation_mode'] = [
                            'status' => 'PASS',
                            'message' => 'Source-only delivery verified under pre-existing approved Decision.',
                        ];
                    }
                } else {
                    $checks['installation_mode'] = [
                        'status' => 'FAIL',
                        'message' => 'Ambiguous delivery state: dist is not exposed but installation mode is not source.',
                    ];
                }
            }
        }

        // Qualified Release Reference Correspondence
        $observedRef = null;
        $dist = $installedPkg['dist'] ?? null;
        $source = $installedPkg['source'] ?? null;
        if ($mode === 'dist' && is_array($dist) && isset($dist['reference']) && is_scalar($dist['reference'])) {
            $observedRef = (string) $dist['reference'];
        } elseif ($mode === 'source' && is_array($source) && isset($source['reference']) && is_scalar($source['reference'])) {
            $observedRef = (string) $source['reference'];
        }

        if ($observedRef === null || $observedRef === '') {
            $checks['qualified_reference'] = [
                'status' => 'FAIL',
                'message' => 'Installed package metadata does not expose an exact commit or tag reference.',
            ];
        } else {
            $matchesSha = strtolower($observedRef) === strtolower($qualifiedSha);
            $matchesRef = ($qualifiedRef !== null && ($observedRef === $qualifiedRef || str_ends_with($observedRef, $qualifiedRef)));

            if (! $matchesSha && ! $matchesRef) {
                $checks['qualified_reference'] = [
                    'status' => 'FAIL',
                    'message' => sprintf(
                        'Observed package reference "%s" does not match intended qualified SHA "%s" or reference "%s".',
                        $observedRef,
                        $qualifiedSha,
                        (string) $qualifiedRef,
                    ),
                ];
            } else {
                $checks['qualified_reference'] = [
                    'status' => 'PASS',
                    'message' => sprintf('Observed package reference "%s" corresponds to qualified release identity.', $observedRef),
                    'details' => ['observed_reference' => $observedRef],
                ];
            }
        }

        return ['mode' => $mode, 'checks' => $checks];
    }

    /**
     * @return array{
     *     checks: array<string, array{status: 'PASS'|'FAIL', message: string, details?: mixed}>,
     * }
     */
    public function inspectInstalledArtifact(string $installedPath, string $target): array
    {
        $checks = [];

        if (! is_dir($installedPath)) {
            $checks['installed_path_exists'] = [
                'status' => 'FAIL',
                'message' => sprintf('Installed package directory does not exist: %s.', $installedPath),
            ];

            return ['checks' => $checks];
        }

        $checks['installed_path_exists'] = [
            'status' => 'PASS',
            'message' => sprintf('Installed package path exists: %s.', $installedPath),
        ];

        // Required Files Check
        $missing = [];
        foreach (self::REQUIRED_INSTALLED_FILES as $file) {
            $p = $installedPath . '/' . $file;
            if (! file_exists($p)) {
                $missing[] = $file;
            }
        }

        if ($missing !== []) {
            $checks['installed_required_files'] = [
                'status' => 'FAIL',
                'message' => sprintf('Installed artifact is missing required consumer/release-facing files: %s.', implode(', ', $missing)),
                'details' => $missing,
            ];
        } else {
            $checks['installed_required_files'] = [
                'status' => 'PASS',
                'message' => 'All required consumer/release-facing files are present in the installed artifact.',
            ];
        }

        // Installed Manifest Integrity
        $installedComposer = $installedPath . '/composer.json';
        if (file_exists($installedComposer)) {
            $raw = (string) file_get_contents($installedComposer);
            $parsed = json_decode($raw, true);
            if (! is_array($parsed) || ($parsed['name'] ?? '') !== self::DEFAULT_PACKAGE_NAME) {
                $checks['installed_composer_manifest'] = [
                    'status' => 'FAIL',
                    'message' => 'Installed composer.json is invalid or package name does not match.',
                ];
            } else {
                $checks['installed_composer_manifest'] = [
                    'status' => 'PASS',
                    'message' => 'Installed composer.json manifest verified.',
                ];
            }
        }

        // Installed README Consistency
        $installedReadme = $installedPath . '/README.md';
        if (file_exists($installedReadme)) {
            $readmeContent = (string) file_get_contents($installedReadme);
            if (! str_contains($readmeContent, $target)) {
                $checks['installed_readme_identity'] = [
                    'status' => 'FAIL',
                    'message' => sprintf('Installed README.md does not reference the target version "%s".', $target),
                ];
            } else {
                $checks['installed_readme_identity'] = [
                    'status' => 'PASS',
                    'message' => 'Installed README.md accurately represents target artifact identity.',
                ];
            }
        }

        return ['checks' => $checks];
    }

    /**
     * @return array{status: 'PASS'|'FAIL', message: string, details?: mixed}
     */
    private function evaluateSourceOnlyDecision(?string $decisionFile, string $package, string $target): array
    {
        if ($decisionFile === null || ! file_exists($decisionFile)) {
            return [
                'status' => 'FAIL',
                'message' => 'Source-only delivery requires a pre-existing Owner-approved Decision Record, but none was provided.',
            ];
        }

        $content = (string) file_get_contents($decisionFile);
        $isActive = str_contains($content, 'Status: ACTIVE') || str_contains($content, '## Status') && str_contains($content, 'ACTIVE');
        $mentionsPackage = str_contains($content, $package);
        $mentionsSourceOnly = str_contains($content, 'source-only') || str_contains($content, 'delivery mode = source-only');

        if (! $isActive || ! $mentionsPackage || ! $mentionsSourceOnly) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Decision file "%s" is not an ACTIVE source-only decision for "%s".', $decisionFile, $package),
            ];
        }

        return [
            'status' => 'PASS',
            'message' => sprintf('Valid source-only Decision verified for %s at %s.', $package, $target),
            'details' => ['decision_file' => $decisionFile],
        ];
    }

    private function createIsolatedEnvironment(): string
    {
        $dir = sys_get_temp_dir() . '/maatify-pav-' . bin2hex(random_bytes(6));
        if (! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException('Unable to create isolated directory: ' . $dir);
        }
        mkdir($dir . '/composer-home', 0777, true);
        mkdir($dir . '/composer-cache', 0777, true);

        return $dir;
    }

    /**
     * @return array{status: 'PASS'|'FAIL', message: string, details?: mixed}
     */
    private function runIsolatedComposerInstall(
        string $root,
        string $package,
        string $target,
        ?string $customRepository,
    ): array {
        $composerJsonData = [
            'name' => 'maatify/published-artifact-verification-consumer',
            'type' => 'project',
            'require' => [
                'php' => '>=8.4',
                $package => $target,
            ],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            'config' => [
                'secure-http' => true,
                'audit' => [
                    'abandoned' => 'fail',
                ],
            ],
        ];

        if ($customRepository !== null && $customRepository !== '') {
            $repoConfig = json_decode($customRepository, true);
            if (is_array($repoConfig)) {
                $composerJsonData['repositories'] = $repoConfig;
            } else {
                $composerJsonData['repositories'] = [
                    ['type' => 'composer', 'url' => $customRepository],
                ];
            }
        }

        file_put_contents(
            $root . '/composer.json',
            (string) json_encode($composerJsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        $env = sprintf(
            'COMPOSER_HOME=%s COMPOSER_CACHE_DIR=%s',
            escapeshellarg($root . '/composer-home'),
            escapeshellarg($root . '/composer-cache'),
        );

        $cmd = sprintf(
            '%s composer update --working-dir=%s --no-interaction --prefer-dist --no-progress 2>&1',
            $env,
            escapeshellarg($root),
        );

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Isolated Composer resolution failed (code %d): %s', $exitCode, implode("\n", array_slice($output, -10))),
                'details' => ['output' => $output, 'command' => $cmd],
            ];
        }

        return [
            'status' => 'PASS',
            'message' => sprintf('Successfully resolved and installed "%s:%s" in isolated environment.', $package, $target),
        ];
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
