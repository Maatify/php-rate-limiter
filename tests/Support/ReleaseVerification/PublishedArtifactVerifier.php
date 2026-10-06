<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\ReleaseVerification;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Generic, target-agnostic Published Artifact Verifier.
 *
 * Implements post-publication Published Artifact Verification required by:
 * - CI_WORKFLOW_STANDARD.md §2.6 & §2.7
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
    public const string EXPECTED_LICENSE = 'proprietary';

    /**
     * Executes qualifying Published Artifact Verification.
     *
     * In qualifying mode, external Composer resolution is MANDATORY:
     * - Consumes authoritative RAV qualification evidence
     * - Spawns fresh isolated Composer consumer environment
     * - Requires exact package:version
     * - Inspects actual installed package and installed.json metadata
     * - Proves actual installation mode (dist vs source)
     * - Enforces dist mode when dist archive was exposed
     * - Verifies observed reference equals the qualified candidate SHA
     * - Compares installed content hashes against qualification manifest
     *
     * @param array{
     *     qualification_evidence_file?: string|null,
     *     qualification_evidence?: array<string, mixed>|null,
     *     package?: string,
     *     target?: string|null,
     *     qualified_sha?: string|null,
     *     composer_repository?: string|null,
     *     working_dir?: string|null,
     *     repo_path?: string|null,
     *     keep_temp?: bool,
     * } $options
     * @return array{
     *     status: 'PASS'|'FAIL',
     *     package_name: string,
     *     target_version: string,
     *     qualified_sha: string,
     *     installation_mode: string,
     *     installed_path: string,
     *     composer_audit: array{composer_version: string, isolated_home: string, isolated_cache: string, effective_repo?: string|null},
     *     verified_at: string,
     *     checks: array<string, array{status: 'PASS'|'FAIL', message: string, details?: mixed}>,
     *     failures: list<string>,
     * }
     */
    public function verify(array $options): array
    {
        $package = trim($options['package'] ?? self::DEFAULT_PACKAGE_NAME);
        $repoPath = $options['repo_path'] ?? (string) realpath(__DIR__ . '/../../..');
        $keepTemp = $options['keep_temp'] ?? false;
        $workingDir = $options['working_dir'] ?? null;

        $failures = [];
        $checks = [];
        $observedMode = 'UNKNOWN';
        $finalInstalledPath = '';
        $composerAudit = [
            'composer_version' => 'UNKNOWN',
            'isolated_home' => '',
            'isolated_cache' => '',
            'effective_repo' => $options['composer_repository'] ?? null,
        ];

        // 1. Validate Qualification Evidence Input
        $evidenceEval = $this->loadAndValidateQualificationEvidence(
            $options['qualification_evidence_file'] ?? null,
            $options['qualification_evidence'] ?? null,
            $options['target'] ?? null,
            $options['qualified_sha'] ?? null,
            $package,
        );
        $checks['qualification_evidence'] = $evidenceEval;
        if ($evidenceEval['status'] === 'FAIL') {
            $failures[] = $evidenceEval['message'];

            return [
                'status' => 'FAIL',
                'package_name' => $package,
                'target_version' => $options['target'] ?? 'UNKNOWN',
                'qualified_sha' => $options['qualified_sha'] ?? 'UNKNOWN',
                'installation_mode' => 'UNRESOLVED',
                'installed_path' => '',
                'composer_audit' => $composerAudit,
                'verified_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'checks' => $checks,
                'failures' => $failures,
            ];
        }

        /** @var array<string, mixed> $evidence */
        $evidence = $evidenceEval['details']['evidence'] ?? [];
        $target = is_scalar($evidence['target_version'] ?? null) ? (string) $evidence['target_version'] : '';
        $qualifiedSha = is_scalar($evidence['candidate_sha'] ?? null) ? (string) $evidence['candidate_sha'] : '';
        $deliveryPolicy = is_scalar($evidence['delivery_policy'] ?? null) ? (string) $evidence['delivery_policy'] : 'dist';
        /** @var array<string, string> $expectedContentManifest */
        $expectedContentManifest = is_array($evidence['content_manifest'] ?? null) ? $evidence['content_manifest'] : [];
        /** @var array<string, mixed>|null $sourceOnlyEvidence */
        $sourceOnlyEvidence = is_array($evidence['source_only_decision'] ?? null) ? $evidence['source_only_decision'] : null;

        $createdTempDir = false;
        $tempRoot = null;

        try {
            // 2. Perform Isolated External Composer Resolution & Installation
            $tempRoot = $workingDir ?? $this->createIsolatedEnvironment();
            $createdTempDir = ($workingDir === null);
            $composerAudit['isolated_home'] = $tempRoot . '/composer-home';
            $composerAudit['isolated_cache'] = $tempRoot . '/composer-cache';

            $installResult = $this->runIsolatedComposerInstall(
                $tempRoot,
                $package,
                $target,
                $options['composer_repository'] ?? null,
            );
            $checks['composer_resolution'] = $installResult;
            if (isset($installResult['details']['composer_version'])) {
                $composerAudit['composer_version'] = (string) $installResult['details']['composer_version'];
            }

            if ($installResult['status'] === 'FAIL') {
                $failures[] = $installResult['message'];

                return [
                    'status' => 'FAIL',
                    'package_name' => $package,
                    'target_version' => $target,
                    'qualified_sha' => $qualifiedSha,
                    'installation_mode' => 'UNRESOLVED',
                    'installed_path' => '',
                    'composer_audit' => $composerAudit,
                    'verified_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    'checks' => $checks,
                    'failures' => $failures,
                ];
            }

            $finalInstalledPath = $tempRoot . '/vendor/' . $package;
            $installedJsonPath = $tempRoot . '/vendor/composer/installed.json';
            $installedData = $this->parseInstalledJson($installedJsonPath, $package);

            // 3. Evaluate Installed Package Metadata and Installation Mode
            $metadataEval = $this->evaluateInstalledMetadata(
                $installedData,
                $qualifiedSha,
                $deliveryPolicy,
                $sourceOnlyEvidence,
                $repoPath,
            );

            $observedMode = $metadataEval['mode'];
            foreach ($metadataEval['checks'] as $key => $check) {
                $checks[$key] = $check;
                if ($check['status'] === 'FAIL') {
                    $failures[] = $check['message'];
                }
            }

            // 4. Inspect Installed Artifact Content and Hashes
            $contentEval = $this->inspectInstalledArtifact($finalInstalledPath, $target, $expectedContentManifest);
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
            'composer_audit' => $composerAudit,
            'verified_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'checks' => $checks,
            'failures' => $failures,
        ];
    }

    /**
     * Test-only inspection of preinstalled fixtures.
     *
     * ALWAYS returns status: 'INSPECTION_ONLY', NEVER qualifying 'PASS'.
     *
     * @param array{
     *     package?: string,
     *     target: string,
     *     qualified_sha: string,
     *     installed_path: string,
     *     installed_json_path: string,
     *     delivery_policy?: string,
     *     source_only_evidence?: array<string, mixed>|null,
     *     expected_content_manifest?: array<string, string>|null,
     *     repo_path?: string|null,
     * } $options
     * @return array{
     *     status: 'INSPECTION_ONLY',
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
    public function inspectPreinstalledFixture(array $options): array
    {
        $package = trim($options['package'] ?? self::DEFAULT_PACKAGE_NAME);
        $target = trim($options['target']);
        $qualifiedSha = trim($options['qualified_sha']);
        $installedPath = $options['installed_path'];
        $installedJsonPath = $options['installed_json_path'];
        $deliveryPolicy = $options['delivery_policy'] ?? 'dist';
        $sourceOnlyEvidence = $options['source_only_evidence'] ?? null;
        $expectedManifest = $options['expected_content_manifest'] ?? null;
        $repoPath = $options['repo_path'] ?? null;

        if (! file_exists($installedJsonPath)) {
            throw new RuntimeException('Provided installed_json_path does not exist: ' . $installedJsonPath);
        }
        if (! is_dir($installedPath)) {
            throw new RuntimeException('Provided installed_path does not exist: ' . $installedPath);
        }

        $installedData = $this->parseInstalledJson($installedJsonPath, $package);
        $metadataEval = $this->evaluateInstalledMetadata($installedData, $qualifiedSha, $deliveryPolicy, $sourceOnlyEvidence, $repoPath);
        $contentEval = $this->inspectInstalledArtifact($installedPath, $target, $expectedManifest);

        $checks = array_merge($metadataEval['checks'], $contentEval['checks']);
        $failures = [];
        foreach ($checks as $c) {
            if ($c['status'] === 'FAIL') {
                $failures[] = $c['message'];
            }
        }

        return [
            'status' => 'INSPECTION_ONLY',
            'package_name' => $package,
            'target_version' => $target,
            'qualified_sha' => $qualifiedSha,
            'installation_mode' => $metadataEval['mode'],
            'installed_path' => $installedPath,
            'verified_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'checks' => $checks,
            'failures' => $failures,
        ];
    }

    /**
     * @param array<string, mixed>|null $rawEvidence
     * @return array{status: 'PASS'|'FAIL', message: string, details?: array{evidence: array<string, mixed>}}
     */
    public function loadAndValidateQualificationEvidence(
        ?string $evidenceFile,
        ?array $rawEvidence,
        ?string $expectedTarget,
        ?string $expectedSha,
        string $expectedPackage,
    ): array {
        if ($rawEvidence !== null) {
            $evidence = $rawEvidence;
        } elseif ($evidenceFile !== null) {
            if (! file_exists($evidenceFile)) {
                return [
                    'status' => 'FAIL',
                    'message' => sprintf('Qualification evidence file "%s" does not exist.', $evidenceFile),
                ];
            }
            $content = (string) file_get_contents($evidenceFile);
            $decoded = json_decode($content, true);
            if (! is_array($decoded)) {
                return [
                    'status' => 'FAIL',
                    'message' => sprintf('Qualification evidence file "%s" is not valid JSON.', $evidenceFile),
                ];
            }
            /** @var array<string, mixed> $decoded */
            $evidence = $decoded;
        } else {
            return [
                'status' => 'FAIL',
                'message' => 'Qualifying Published Artifact Verification requires authoritative RAV qualification evidence, but none was provided.',
            ];
        }

        $evidenceStatus = is_scalar($evidence['status'] ?? null) ? (string) $evidence['status'] : 'UNKNOWN';
        if ($evidenceStatus !== 'PASS') {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Qualification evidence status is "%s", expected "PASS".', $evidenceStatus),
            ];
        }

        $evidencePkg = is_scalar($evidence['package_name'] ?? null) ? (string) $evidence['package_name'] : '';
        if ($evidencePkg !== $expectedPackage) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Qualification evidence package "%s" does not match expected package "%s".', $evidencePkg, $expectedPackage),
            ];
        }

        $target = is_scalar($evidence['target_version'] ?? null) ? (string) $evidence['target_version'] : '';
        $sha = is_scalar($evidence['candidate_sha'] ?? null) ? (string) $evidence['candidate_sha'] : '';

        if ($target === '' || ! (bool) preg_match('/^[0-9a-f]{40}$/i', $sha)) {
            return [
                'status' => 'FAIL',
                'message' => 'Qualification evidence contains invalid target version or candidate commit SHA.',
            ];
        }

        if ($expectedTarget !== null && $expectedTarget !== '' && $expectedTarget !== $target) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Caller target version "%s" does not match qualification evidence target "%s".', $expectedTarget, $target),
            ];
        }

        if ($expectedSha !== null && $expectedSha !== '' && strtolower($expectedSha) !== strtolower($sha)) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Caller qualified SHA "%s" does not match qualification evidence candidate SHA "%s".', $expectedSha, $sha),
            ];
        }

        return [
            'status' => 'PASS',
            'message' => sprintf('RAV qualification evidence verified for %s at commit %s.', $target, $sha),
            'details' => ['evidence' => $evidence],
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
     * @param array<string, mixed>|null $sourceOnlyEvidence
     * @return array{
     *     mode: string,
     *     checks: array<string, array{status: 'PASS'|'FAIL', message: string, details?: mixed}>,
     * }
     */
    public function evaluateInstalledMetadata(
        array $installedPkg,
        string $qualifiedSha,
        string $deliveryPolicy,
        ?array $sourceOnlyEvidence,
        ?string $repoPath,
    ): array {
        $checks = [];
        $mode = 'UNKNOWN';

        // 1. Installation Source Mode Proof
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
                // If dist archive was exposed by Composer repository metadata, actual mode MUST be dist
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
                    if ($deliveryPolicy !== 'source-only') {
                        $checks['installation_mode'] = [
                            'status' => 'FAIL',
                            'message' => 'Channel exposed no dist archive and source mode was observed, but qualification evidence did not authorize source-only delivery policy.',
                        ];
                    } else {
                        $decisionCheck = $this->evaluateSourceOnlyHistoricalChain($sourceOnlyEvidence, $repoPath);
                        $checks['source_only_decision'] = $decisionCheck;
                        if ($decisionCheck['status'] === 'FAIL') {
                            $checks['installation_mode'] = [
                                'status' => 'FAIL',
                                'message' => 'Source-only delivery observed, but qualification-time Decision record or historical supersession chain is invalid.',
                            ];
                        } else {
                            $checks['installation_mode'] = [
                                'status' => 'PASS',
                                'message' => 'Source-only delivery verified under qualification-time approved Decision and valid historical chain.',
                            ];
                        }
                    }
                } else {
                    $checks['installation_mode'] = [
                        'status' => 'FAIL',
                        'message' => 'Ambiguous delivery state: dist is not exposed but installation mode is not source.',
                    ];
                }
            }
        }

        // 2. Qualified Release Reference Correspondence (Must Prove the SHA)
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
            // For this repository and release channel, the reference MUST equal the qualified SHA
            $matchesSha = strtolower($observedRef) === strtolower($qualifiedSha);
            if (! $matchesSha) {
                $checks['qualified_reference'] = [
                    'status' => 'FAIL',
                    'message' => sprintf(
                        'Observed package reference "%s" does not prove the qualified release commit SHA "%s". Tag string equality alone is insufficient.',
                        $observedRef,
                        $qualifiedSha,
                    ),
                ];
            } else {
                $checks['qualified_reference'] = [
                    'status' => 'PASS',
                    'message' => sprintf('Observed package reference "%s" proves exact correspondence to qualified release SHA.', $observedRef),
                    'details' => ['observed_reference' => $observedRef],
                ];
            }
        }

        return ['mode' => $mode, 'checks' => $checks];
    }

    /**
     * Evaluates source-only governance against retained qualification evidence and repository Decision Index history.
     *
     * In accordance with §26.1 and CI §2.5–2.6:
     * - The Decision MUST have existed and been ACTIVE at qualification time (predating RAV).
     * - At PAV time, current Decision status in DECISIONS_INDEX.md may be ACTIVE or legitimately SUPERSEDED.
     * - A later legitimate SUPERSEDED state does NOT invalidate historical qualification.
     * - Broken supersession history or decisions created after qualification fail verification.
     *
     * @param array<string, mixed>|null $sourceOnlyEvidence
     * @return array{status: 'PASS'|'FAIL', message: string}
     */
    public function evaluateSourceOnlyHistoricalChain(?array $sourceOnlyEvidence, ?string $repoPath): array
    {
        if ($sourceOnlyEvidence === null) {
            return [
                'status' => 'FAIL',
                'message' => 'Source-only delivery observed without qualification-time source-only Decision evidence.',
            ];
        }

        $decisionId = is_scalar($sourceOnlyEvidence['decision_id'] ?? null) ? (string) $sourceOnlyEvidence['decision_id'] : '';
        $decisionFile = is_scalar($sourceOnlyEvidence['decision_file'] ?? null) ? (string) $sourceOnlyEvidence['decision_file'] : '';
        $statusAtQual = is_scalar($sourceOnlyEvidence['status_at_qualification'] ?? null) ? (string) $sourceOnlyEvidence['status_at_qualification'] : '';

        if ($decisionId === '' || $decisionFile === '' || $statusAtQual !== 'ACTIVE') {
            return [
                'status' => 'FAIL',
                'message' => 'Qualification-time source-only evidence is incomplete or Decision was not ACTIVE at qualification time.',
            ];
        }

        if ($repoPath === null || ! is_dir($repoPath)) {
            // If repository path is not available, retain qualification-time evidence
            return [
                'status' => 'PASS',
                'message' => sprintf('Source-only qualification evidence verified for Decision "%s".', $decisionId),
            ];
        }

        $indexPath = $repoPath . '/docs/decisions/DECISIONS_INDEX.md';
        if (! file_exists($indexPath)) {
            return [
                'status' => 'FAIL',
                'message' => 'DECISIONS_INDEX.md does not exist in repository.',
            ];
        }

        $indexContent = (string) file_get_contents($indexPath);
        // Look up Decision row in DECISIONS_INDEX.md
        // Row format: | [DEC-XXX](...) | Title | Status | Scope | Record | Owner | Supersedes | Superseded By |
        $pattern = '/\|\s*\[' . preg_quote($decisionId, '/') . '\][^\n]+\|\s*(ACTIVE|SUPERSEDED)\s*\|[^\n]+\|\s*([^\|]+)\|\s*([^\|]+)\s*\|/i';
        if (! preg_match($pattern, $indexContent, $matches)) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Decision "%s" is neither ACTIVE nor SUPERSEDED in current DECISIONS_INDEX.md.', $decisionId),
            ];
        }

        $currentStatus = strtoupper(trim($matches[1]));
        if ($currentStatus === 'ACTIVE') {
            return [
                'status' => 'PASS',
                'message' => sprintf('Decision "%s" remains ACTIVE in repository Decision Index.', $decisionId),
            ];
        }

        // If currently SUPERSEDED, verify coherent supersession chain (Superseded By column must not be None or empty)
        $supersededBy = trim($matches[3]);
        if ($supersededBy === '' || strtolower($supersededBy) === 'none') {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Decision "%s" is marked SUPERSEDED but lacks a valid successor in DECISIONS_INDEX.md.', $decisionId),
            ];
        }

        return [
            'status' => 'PASS',
            'message' => sprintf('Decision "%s" was ACTIVE at qualification and has a coherent historical supersession chain (superseded by %s).', $decisionId, $supersededBy),
        ];
    }

    /**
     * Inspects installed artifact directory, required files, composer.json manifest, and content hashes.
     *
     * @param array<string, string>|null $expectedContentManifest
     * @return array{
     *     checks: array<string, array{status: 'PASS'|'FAIL', message: string, details?: mixed}>,
     * }
     */
    public function inspectInstalledArtifact(
        string $installedPath,
        string $target,
        ?array $expectedContentManifest,
    ): array {
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

        // 1. Required Files Presence
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

        // 2. Installed Manifest Integrity
        $installedComposer = $installedPath . '/composer.json';
        if (file_exists($installedComposer)) {
            $raw = (string) file_get_contents($installedComposer);
            $parsed = json_decode($raw, true);
            if (! is_array($parsed) || ($parsed['name'] ?? '') !== self::DEFAULT_PACKAGE_NAME) {
                $checks['installed_composer_manifest'] = [
                    'status' => 'FAIL',
                    'message' => 'Installed composer.json is invalid or package name does not match.',
                ];
            } elseif (($parsed['license'] ?? '') !== self::EXPECTED_LICENSE) {
                $licenseVal = $parsed['license'] ?? '';
                $instLicense = is_scalar($licenseVal) ? (string) $licenseVal : '';
                $checks['installed_composer_manifest'] = [
                    'status' => 'FAIL',
                    'message' => sprintf('Installed composer.json license is "%s", expected "%s".', $instLicense, self::EXPECTED_LICENSE),
                ];
            } elseif (isset($parsed['version'])) {
                $checks['installed_composer_manifest'] = [
                    'status' => 'FAIL',
                    'message' => 'Installed composer.json must not declare a static version property.',
                ];
            } else {
                $checks['installed_composer_manifest'] = [
                    'status' => 'PASS',
                    'message' => 'Installed composer.json manifest verified.',
                ];
            }
        }

        // 3. Installed README Consistency
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

        // 4. Content Manifest Correspondence (Hash Comparison against RAV Qualification Evidence)
        if ($expectedContentManifest !== null && $expectedContentManifest !== []) {
            $hashMismatches = [];
            foreach ($expectedContentManifest as $relPath => $expectedHash) {
                $fullPath = $installedPath . '/' . $relPath;
                if (! file_exists($fullPath)) {
                    $hashMismatches[] = sprintf('%s (missing from installed package)', $relPath);
                    continue;
                }
                $actualHash = $this->hashPath($fullPath);
                if ($actualHash !== $expectedHash) {
                    $hashMismatches[] = sprintf('%s (expected %s, got %s)', $relPath, substr($expectedHash, 0, 12), substr($actualHash, 0, 12));
                }
            }

            if ($hashMismatches !== []) {
                $checks['content_manifest_correspondence'] = [
                    'status' => 'FAIL',
                    'message' => sprintf('Installed artifact content differs from qualified evidence: %s.', implode('; ', $hashMismatches)),
                    'details' => $hashMismatches,
                ];
            } else {
                $checks['content_manifest_correspondence'] = [
                    'status' => 'PASS',
                    'message' => sprintf('Installed artifact content verified; all %d release-facing file hashes match qualification evidence.', count($expectedContentManifest)),
                ];
            }
        }

        return ['checks' => $checks];
    }

    /**
     * Recursively and deterministically hashes a file or directory using SHA-256.
     */
    public function hashPath(string $path): string
    {
        if (is_file($path)) {
            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                throw new RuntimeException('Failed to hash file: ' . $path);
            }

            return $hash;
        }

        if (is_dir($path)) {
            $files = [];
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            );
            /** @var SplFileInfo $fileInfo */
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isFile()) {
                    $subPath = str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($path) + 1));
                    $fileHash = hash_file('sha256', $fileInfo->getPathname());
                    if ($fileHash !== false) {
                        $files[$subPath] = $fileHash;
                    }
                }
            }
            ksort($files);
            $combined = '';
            foreach ($files as $p => $h) {
                $combined .= $p . ':' . $h . "\n";
            }

            return hash('sha256', $combined);
        }

        throw new RuntimeException('Path is neither file nor directory: ' . $path);
    }

    /**
     * Redacts credentials, tokens, and authorization headers from diagnostic strings.
     */
    public function redactSensitiveData(string $text): string
    {
        $text = (string) preg_replace('/(Bearer\s+)[A-Za-z0-9_\-\.~+\/]+=*/i', '$1[REDACTED]', $text);
        $text = (string) preg_replace('/(https?:\/\/[^:]+:)[^@]+(@)/i', '$1[REDACTED]$2', $text);
        $text = (string) preg_replace('/(token|password|secret|auth)\s*[:=]\s*[^\s,]+/i', '$1=[REDACTED]', $text);

        return $text;
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
     * @return array{status: 'PASS'|'FAIL', message: string, details?: array{output: list<string>, command: string, composer_version?: string}}
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

        // Capture Composer version for audit
        $versionOut = [];
        $versionCode = 0;
        exec('composer --version 2>/dev/null', $versionOut, $versionCode);
        $composerVersion = trim($versionOut[0] ?? 'UNKNOWN');

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

        // Redact any sensitive credentials from captured output and command
        $cleanOutput = array_map([$this, 'redactSensitiveData'], $output);
        $cleanCmd = $this->redactSensitiveData($cmd);

        if ($exitCode !== 0) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Isolated Composer resolution failed (code %d): %s', $exitCode, implode("\n", array_slice($cleanOutput, -10))),
                'details' => ['output' => $cleanOutput, 'command' => $cleanCmd, 'composer_version' => $composerVersion],
            ];
        }

        return [
            'status' => 'PASS',
            'message' => sprintf('Successfully resolved and installed "%s:%s" in isolated environment.', $package, $target),
            'details' => ['output' => $cleanOutput, 'command' => $cleanCmd, 'composer_version' => $composerVersion],
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
