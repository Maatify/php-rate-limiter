<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\ReleaseVerification;

use RuntimeException;

/**
 * Generic, target-agnostic Published Artifact Verifier.
 *
 * Implements post-publication Published Artifact Verification required by:
 * - CI_WORKFLOW_STANDARD.md §2.6 & §2.7
 * - COMPOSER_PACKAGE_STANDARD.md §26 & §26.1
 * - LIBRARY_PRESENTATION_STANDARD.md §14 & §23
 *
 * Qualifying PAV (`verify()`) requires complete RAV qualification evidence, resolves
 * ONLY from the qualification-bound approved channel, runs Composer in a fully
 * controlled environment, and proves exact version, delivery mode, reference and
 * content from Composer-supported metadata. `inspectPreinstalledFixture()` is
 * test-only and can never produce a qualifying PASS.
 */
final class PublishedArtifactVerifier
{
    /** @var list<string> */
    public const array REQUIRED_INSTALLED_FILES = ReleaseContract::REQUIRED_PATHS;

    public const string DEFAULT_PACKAGE_NAME = ReleaseContract::PACKAGE_NAME;
    public const string EXPECTED_LICENSE = ReleaseContract::LICENSE;

    /**
     * Network transport settings that cannot change repository resolution or install mode;
     * only their NAMES are ever recorded (values may embed credentials).
     *
     * @var list<string>
     */
    private const array TRANSPORT_PASSTHROUGH = [
        'HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY', 'http_proxy', 'https_proxy', 'no_proxy',
        'SSL_CERT_FILE', 'SSL_CERT_DIR', 'CURL_CA_BUNDLE',
    ];

    /**
     * Executes qualifying Published Artifact Verification.
     *
     * @param array{
     *     qualification_evidence_file?: string|null,
     *     qualification_evidence?: array<string, mixed>|null,
     *     package?: string,
     *     target?: string|null,
     *     qualified_sha?: string|null,
     *     composer_repository?: string|null,
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
     *     composer_audit: array<string, mixed>,
     *     delivery_evidence: array<string, mixed>,
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

        $checks = [];
        $failures = [];
        $audit = $this->emptyAudit();

        $fail = function (string $target, string $sha, string $mode) use (&$checks, &$failures, &$audit, $package): array {
            return $this->buildResult('FAIL', $package, $target, $sha, $mode, '', $audit, $checks, $failures, []);
        };

        // 1. Complete qualification-evidence validation BEFORE any network work
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

            return $fail($options['target'] ?? 'UNKNOWN', $options['qualified_sha'] ?? 'UNKNOWN', 'UNRESOLVED');
        }

        /** @var array<string, mixed> $evidence */
        $evidence = $evidenceEval['details']['evidence'] ?? [];
        $target = self::text($evidence, 'target_version');
        $qualifiedSha = self::text($evidence, 'candidate_sha');
        $deliveryPolicy = self::text($evidence, 'delivery_policy');
        $channel = self::text($evidence, 'approved_distribution_channel');
        /** @var array<string, string> $expectedContentManifest */
        $expectedContentManifest = $evidence['content_manifest'];
        /** @var array<string, mixed>|null $sourceOnlyEvidence */
        $sourceOnlyEvidence = is_array($evidence['source_only_decision']) ? $evidence['source_only_decision'] : null;
        $audit['effective_repository'] = ReleaseContract::redactUrl($channel);

        // 2. Approved distribution channel is bound to qualification
        $override = $options['composer_repository'] ?? null;
        $channelCheck = $this->evaluateChannelBinding($channel, $override);
        $checks['approved_channel'] = $channelCheck;
        if ($channelCheck['status'] === 'FAIL') {
            $failures[] = $channelCheck['message'];

            return $fail($target, $qualifiedSha, 'UNRESOLVED');
        }

        // 3. Source-only historical chain, proven BEFORE installation
        $sourceOnlyCheck = null;
        if ($deliveryPolicy === 'source-only') {
            $git = is_dir($repoPath) ? new GitRepository($repoPath) : null;
            $sourceOnlyCheck = (new SourceOnlyDecisionVerifier())->verifyHistoricalChain($git, $sourceOnlyEvidence ?? [], $target, $qualifiedSha);
            $checks['source_only_decision'] = $sourceOnlyCheck;
            if ($sourceOnlyCheck['status'] === 'FAIL') {
                $failures[] = $sourceOnlyCheck['message'];

                return $fail($target, $qualifiedSha, 'UNRESOLVED');
            }
        }

        $tempRoot = null;
        $observedMode = 'UNKNOWN';
        $installedPath = '';
        $deliveryEvidence = [];

        try {
            // 4. Isolated external Composer resolution & installation
            $tempRoot = $this->createIsolatedEnvironment();
            $audit['isolated_root'] = $tempRoot;
            $audit['isolated_home'] = $tempRoot . '/composer-home';
            $audit['isolated_cache'] = $tempRoot . '/composer-cache';

            $installResult = $this->runIsolatedComposerInstall($tempRoot, $package, $target, $channel, $audit);
            $checks['composer_resolution'] = $installResult;
            if ($installResult['status'] === 'FAIL') {
                $failures[] = $installResult['message'];

                return $fail($target, $qualifiedSha, 'UNRESOLVED');
            }

            // 5. Composer-supported package metadata
            try {
                $installedPkg = $this->parseInstalledJson($tempRoot . '/vendor/composer/installed.json', $package);
                $lockPkg = $this->parseComposerLock($tempRoot . '/composer.lock', $package);
            } catch (RuntimeException $e) {
                $checks['installed_metadata'] = ['status' => 'FAIL', 'message' => 'Unable to read Composer installed-package metadata: ' . $e->getMessage()];
                $failures[] = $checks['installed_metadata']['message'];

                return $fail($target, $qualifiedSha, 'UNRESOLVED');
            }

            $versionEval = $this->evaluateResolvedVersion($installedPkg, $lockPkg, $package, $target);
            $checks['resolved_version'] = $versionEval;
            if ($versionEval['status'] === 'FAIL') {
                $failures[] = $versionEval['message'];
            }

            $installedPath = $this->resolveInstalledPath($tempRoot, $installedPkg, $package);
            if ($installedPath === null) {
                $checks['installed_path'] = ['status' => 'FAIL', 'message' => 'Installed package path could not be proven from Composer metadata.'];
                $failures[] = $checks['installed_path']['message'];
                $installedPath = '';
            }

            $metadataEval = $this->evaluateInstalledMetadata($installedPkg, $qualifiedSha, $deliveryPolicy, $sourceOnlyCheck, $lockPkg);
            $observedMode = $metadataEval['mode'];
            $deliveryEvidence = $metadataEval['delivery_evidence'];
            foreach ($metadataEval['checks'] as $key => $check) {
                $checks[$key] = $check;
                if ($check['status'] === 'FAIL') {
                    $failures[] = $check['message'];
                }
            }

            // 6. Installed artifact content
            if ($installedPath !== '') {
                $contentEval = $this->inspectInstalledArtifact($installedPath, $target, $expectedContentManifest);
                foreach ($contentEval['checks'] as $key => $check) {
                    $checks[$key] = $check;
                    if ($check['status'] === 'FAIL') {
                        $failures[] = $check['message'];
                    }
                }
            }
        } finally {
            if ($tempRoot !== null && ! $keepTemp) {
                ReleaseContract::removeTree($tempRoot);
            }
        }

        return $this->buildResult(
            $failures === [] ? 'PASS' : 'FAIL',
            $package,
            $target,
            $qualifiedSha,
            $observedMode,
            $installedPath,
            $audit,
            $checks,
            $failures,
            $deliveryEvidence,
        );
    }

    /**
     * Qualifying PAV lifecycle: verification AND durable machine-readable report persistence.
     *
     * The report destination is validated (outside the repository tree) before any network
     * work. PASS is returned only after the complete report has been persisted and read back.
     *
     * @param array{
     *     qualification_evidence_file?: string|null,
     *     qualification_evidence?: array<string, mixed>|null,
     *     package?: string,
     *     target?: string|null,
     *     qualified_sha?: string|null,
     *     composer_repository?: string|null,
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
     *     composer_audit: array<string, mixed>,
     *     delivery_evidence: array<string, mixed>,
     *     verified_at: string,
     *     checks: array<string, array{status: 'PASS'|'FAIL', message: string, details?: mixed}>,
     *     failures: list<string>,
     *     report_path?: string,
     * }
     */
    public function verifyQualifying(array $options, string $reportPath, ?EvidenceWriter $writer = null): array
    {
        $writer ??= new EvidenceWriter();
        $repoPath = $options['repo_path'] ?? (string) realpath(__DIR__ . '/../../..');

        $destination = $writer->resolveDestination($reportPath, $repoPath);
        if ($destination['status'] === 'FAIL') {
            $audit = $this->emptyAudit();

            return $this->buildResult(
                'FAIL',
                trim($options['package'] ?? self::DEFAULT_PACKAGE_NAME),
                $options['target'] ?? 'UNKNOWN',
                $options['qualified_sha'] ?? 'UNKNOWN',
                'UNRESOLVED',
                '',
                $audit,
                ['report_persistence' => ['status' => 'FAIL', 'message' => $destination['message']]],
                [$destination['message']],
                [],
            );
        }

        return $this->finalizePersistence($this->verify($options), $reportPath, $repoPath, $writer);
    }

    /**
     * Makes report persistence part of PAV success. The complete result (PASS or FAIL) is
     * persisted; a PASS survives only if persistence and read-back both succeed.
     *
     * @param array{
     *     status: 'PASS'|'FAIL',
     *     package_name: string,
     *     target_version: string,
     *     qualified_sha: string,
     *     installation_mode: string,
     *     installed_path: string,
     *     composer_audit: array<string, mixed>,
     *     delivery_evidence: array<string, mixed>,
     *     verified_at: string,
     *     checks: array<string, array{status: 'PASS'|'FAIL', message: string, details?: mixed}>,
     *     failures: list<string>,
     * } $result
     * @return array{
     *     status: 'PASS'|'FAIL',
     *     package_name: string,
     *     target_version: string,
     *     qualified_sha: string,
     *     installation_mode: string,
     *     installed_path: string,
     *     composer_audit: array<string, mixed>,
     *     delivery_evidence: array<string, mixed>,
     *     verified_at: string,
     *     checks: array<string, array{status: 'PASS'|'FAIL', message: string, details?: mixed}>,
     *     failures: list<string>,
     *     report_path?: string,
     * }
     */
    public function finalizePersistence(array $result, string $reportPath, string $repoPath, ?EvidenceWriter $writer = null): array
    {
        $writer ??= new EvidenceWriter();
        $written = $writer->write($reportPath, $result, $repoPath);

        $failure = null;
        $persistedPath = null;
        if ($written['status'] === 'FAIL') {
            $failure = $written['message'];
        } else {
            $persistedPath = $written['path'];
            $decoded = json_decode((string) file_get_contents($written['path']), true);
            if (! is_array($decoded) || $decoded !== json_decode((string) json_encode($result), true)) {
                unlink($written['path']);
                $failure = 'Persisted PAV report differs from the generated result.';
            }
        }

        if ($failure !== null) {
            $result['status'] = 'FAIL';
            $result['checks']['report_persistence'] = ['status' => 'FAIL', 'message' => $failure];
            $result['failures'][] = $failure;

            return $result;
        }

        if ($persistedPath !== null) {
            $result['report_path'] = $persistedPath;
        }

        return $result;
    }

    /**
     * Test-only inspection of preinstalled fixtures.
     *
     * ALWAYS returns status: 'INSPECTION_ONLY', NEVER qualifying 'PASS'. Fixture and
     * test repositories can never become qualifying publication evidence.
     *
     * @param array{
     *     package?: string,
     *     target: string,
     *     qualified_sha: string,
     *     installed_path: string,
     *     installed_json_path: string,
     *     composer_lock_path?: string|null,
     *     delivery_policy?: string,
     *     source_only_check?: array{status: 'PASS'|'FAIL', message: string}|null,
     *     expected_content_manifest?: array<string, string>|null,
     * } $options
     * @return array{
     *     status: 'INSPECTION_ONLY',
     *     package_name: string,
     *     target_version: string,
     *     qualified_sha: string,
     *     installation_mode: string,
     *     installed_path: string,
     *     verified_at: string,
     *     delivery_evidence: array<string, mixed>,
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

        if (! file_exists($installedJsonPath)) {
            throw new RuntimeException('Provided installed_json_path does not exist: ' . $installedJsonPath);
        }
        if (! is_dir($installedPath)) {
            throw new RuntimeException('Provided installed_path does not exist: ' . $installedPath);
        }

        $installedData = $this->parseInstalledJson($installedJsonPath, $package);
        $lockPath = $options['composer_lock_path'] ?? null;
        $lockPkg = $lockPath !== null ? $this->parseComposerLock($lockPath, $package) : null;

        $metadataEval = $this->evaluateInstalledMetadata($installedData, $qualifiedSha, $options['delivery_policy'] ?? 'dist', $options['source_only_check'] ?? null, $lockPkg);
        $versionEval = $this->evaluateResolvedVersion($installedData, $lockPkg, $package, $target);
        $contentEval = $this->inspectInstalledArtifact($installedPath, $target, $options['expected_content_manifest'] ?? null);

        $checks = array_merge(['resolved_version' => $versionEval], $metadataEval['checks'], $contentEval['checks']);
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
            'delivery_evidence' => $metadataEval['delivery_evidence'],
            'checks' => $checks,
            'failures' => $failures,
        ];
    }

    /**
     * Loads RAV qualification evidence and validates it COMPLETELY against the canonical schema.
     *
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
            $decoded = json_decode((string) file_get_contents($evidenceFile), true);
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

        $errors = QualificationEvidenceSchema::validate($evidence);
        if ($errors !== []) {
            return [
                'status' => 'FAIL',
                'message' => 'Qualification evidence is incomplete or invalid: ' . implode('; ', $errors) . '.',
            ];
        }

        $evidencePkg = self::text($evidence, 'package_name');
        if ($evidencePkg !== $expectedPackage) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Qualification evidence package "%s" does not match expected package "%s".', $evidencePkg, $expectedPackage),
            ];
        }

        $target = self::text($evidence, 'target_version');
        $sha = self::text($evidence, 'candidate_sha');

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
            'message' => sprintf('Complete RAV qualification evidence verified for %s at commit %s.', $target, $sha),
            'details' => ['evidence' => $evidence],
        ];
    }

    /**
     * A caller-supplied repository is never evidence: it must equal the qualification-bound channel.
     *
     * @return array{status: 'PASS'|'FAIL', message: string}
     */
    public function evaluateChannelBinding(string $qualifiedChannel, ?string $requestedRepository): array
    {
        if ($requestedRepository !== null && trim($requestedRepository) !== '') {
            if (ReleaseContract::normalizeChannel($requestedRepository) !== ReleaseContract::normalizeChannel($qualifiedChannel)) {
                return [
                    'status' => 'FAIL',
                    'message' => sprintf(
                        'Caller-supplied Composer repository "%s" is not the approved distribution channel bound to qualification ("%s"); it cannot produce qualifying PAV.',
                        ReleaseContract::redactUrl($requestedRepository),
                        ReleaseContract::redactUrl($qualifiedChannel),
                    ),
                ];
            }
        }

        return [
            'status' => 'PASS',
            'message' => sprintf('Resolution is bound to the qualification-approved channel "%s".', ReleaseContract::redactUrl($qualifiedChannel)),
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

        $matches = [];
        foreach ($packages as $pkg) {
            if (is_array($pkg) && ($pkg['name'] ?? null) === $package) {
                /** @var array<string, mixed> $pkg */
                $matches[] = $pkg;
            }
        }

        if ($matches === []) {
            throw new RuntimeException(sprintf('Package "%s" was not found in installed.json packages list.', $package));
        }
        if (count($matches) > 1) {
            throw new RuntimeException(sprintf('Package "%s" appears %d times in installed.json (ambiguous).', $package, count($matches)));
        }

        return $matches[0];
    }

    /**
     * @return array<string, mixed>|null the lock entry, or null when no lock file is available
     */
    public function parseComposerLock(string $lockPath, string $package): ?array
    {
        if (! file_exists($lockPath)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($lockPath), true);
        if (! is_array($decoded) || ! is_array($decoded['packages'] ?? null)) {
            throw new RuntimeException('Malformed composer.lock');
        }

        $matches = [];
        foreach ($decoded['packages'] as $pkg) {
            if (is_array($pkg) && ($pkg['name'] ?? null) === $package) {
                /** @var array<string, mixed> $pkg */
                $matches[] = $pkg;
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException(sprintf('Package "%s" resolved %d times in composer.lock (expected exactly 1).', $package, count($matches)));
        }

        return $matches[0];
    }

    /**
     * Proves the observed package name and exact version from Composer-supported metadata.
     * The package's own composer.json intentionally has no static version and is never used.
     *
     * @param array<string, mixed> $installedPkg
     * @param array<string, mixed>|null $lockPkg
     * @return array{status: 'PASS'|'FAIL', message: string, details?: array<string, string|null>}
     */
    public function evaluateResolvedVersion(array $installedPkg, ?array $lockPkg, string $package, string $target): array
    {
        $name = is_string($installedPkg['name'] ?? null) ? $installedPkg['name'] : '';
        $version = is_string($installedPkg['version'] ?? null) ? $installedPkg['version'] : '';
        $lockVersion = is_array($lockPkg) && is_string($lockPkg['version'] ?? null) ? $lockPkg['version'] : null;
        $details = [
            'requested_version' => $target,
            'resolved_version' => $version === '' ? null : $version,
            'lock_version' => $lockVersion,
            'installed_package' => $name === '' ? null : $name,
        ];

        if ($name !== $package) {
            return ['status' => 'FAIL', 'message' => sprintf('Observed package "%s" is not the qualified package "%s".', $name, $package), 'details' => $details];
        }
        if ($version === '') {
            return ['status' => 'FAIL', 'message' => 'Composer metadata exposes no observed package version.', 'details' => $details];
        }
        if (ReleaseContract::normalizeVersion($version) !== ReleaseContract::normalizeVersion($target)) {
            return ['status' => 'FAIL', 'message' => sprintf('Observed installed version "%s" is not the exact qualified target "%s".', $version, $target), 'details' => $details];
        }
        if ($lockPkg !== null && ($lockVersion === null || ReleaseContract::normalizeVersion($lockVersion) !== ReleaseContract::normalizeVersion($target))) {
            return ['status' => 'FAIL', 'message' => sprintf('composer.lock resolved version "%s" disagrees with the qualified target "%s".', $lockVersion ?? 'none', $target), 'details' => $details];
        }

        return ['status' => 'PASS', 'message' => sprintf('Composer metadata proves %s resolved exactly to %s.', $package, $version), 'details' => $details];
    }

    /**
     * @param array<string, mixed> $installedPkg
     * @param array{status: 'PASS'|'FAIL', message: string, details?: array<string, mixed>}|null $sourceOnlyCheck
     * @param array<string, mixed>|null $lockPkg
     * @return array{
     *     mode: string,
     *     delivery_evidence: array{
     *         installation_source: string|null,
     *         dist: array{type: ?string, url: ?string, reference: ?string}|null,
     *         source: array{type: ?string, url: ?string, reference: ?string}|null,
     *         resolved_package: mixed,
     *         resolved_version: mixed,
     *         install_path: mixed,
     *     },
     *     checks: array<string, array{status: 'PASS'|'FAIL', message: string, details?: mixed}>,
     * }
     */
    public function evaluateInstalledMetadata(
        array $installedPkg,
        string $qualifiedSha,
        string $deliveryPolicy,
        ?array $sourceOnlyCheck,
        ?array $lockPkg = null,
    ): array {
        $checks = [];
        $mode = 'UNKNOWN';

        $dist = $this->describeReference($installedPkg['dist'] ?? null);
        $source = $this->describeReference($installedPkg['source'] ?? null);
        $installSource = $installedPkg['installation-source'] ?? null;
        $evidence = [
            'installation_source' => is_string($installSource) ? $installSource : null,
            'dist' => $dist,
            'source' => $source,
            'resolved_package' => $installedPkg['name'] ?? null,
            'resolved_version' => $installedPkg['version'] ?? null,
            'install_path' => $installedPkg['install-path'] ?? null,
        ];

        // 1. Installation Source Mode Proof
        if (! is_string($installSource) || ! in_array($installSource, ['dist', 'source'], true)) {
            $sourceStr = is_scalar($installSource) ? (string) $installSource : get_debug_type($installSource);
            $checks['installation_mode'] = [
                'status' => 'FAIL',
                'message' => sprintf('Unprovable installation mode: installation-source is "%s", expected "dist" or "source".', $sourceStr),
            ];
        } else {
            $mode = $installSource;
            $distExposed = $dist !== null && $dist['type'] !== null;
            $lockDistExposed = is_array($lockPkg) && is_array($lockPkg['dist'] ?? null) && ($lockPkg['dist']['type'] ?? null) !== null;

            if ($lockPkg !== null && $distExposed !== $lockDistExposed) {
                $checks['installation_mode'] = [
                    'status' => 'FAIL',
                    'message' => 'Ambiguous delivery state: installed.json and composer.lock disagree on whether dist is exposed.',
                ];
            } elseif ($distExposed) {
                // If dist archive was exposed by Composer repository metadata, actual mode MUST be dist
                $checks['installation_mode'] = $mode !== 'dist' ? [
                    'status' => 'FAIL',
                    'message' => 'Dist archive was exposed by Composer repository metadata, but actual installation mode was source (fallback or preference failure is prohibited).',
                ] : [
                    'status' => 'PASS',
                    'message' => 'Verified actual installation mode = dist, matching exposed dist archive.',
                ];
            } elseif ($mode !== 'source') {
                $checks['installation_mode'] = [
                    'status' => 'FAIL',
                    'message' => 'Ambiguous delivery state: dist is not exposed but installation mode is not source.',
                ];
            } elseif ($deliveryPolicy !== 'source-only') {
                $checks['installation_mode'] = [
                    'status' => 'FAIL',
                    'message' => 'Channel exposed no dist archive and source mode was observed, but qualification evidence did not authorize source-only delivery policy.',
                ];
            } else {
                if ($sourceOnlyCheck === null) {
                    $sourceOnlyCheck = [
                        'status' => 'FAIL',
                        'message' => 'Source-only delivery observed without qualification-time source-only Decision evidence.',
                    ];
                }
                $checks['source_only_decision'] = $sourceOnlyCheck;
                if ($sourceOnlyCheck['status'] === 'FAIL') {
                    $checks['installation_mode'] = [
                        'status' => 'FAIL',
                        'message' => 'Source-only delivery observed, but qualification-time Decision record or historical supersession chain is invalid.',
                    ];
                } elseif ($source === null || $source['type'] === null || $source['url'] === null) {
                    $checks['installation_mode'] = [
                        'status' => 'FAIL',
                        'message' => 'Source-only delivery requires the channel to expose source type and URL for the exact version.',
                    ];
                } else {
                    $checks['installation_mode'] = [
                        'status' => 'PASS',
                        'message' => 'Source-only delivery verified: dist absent, source exposed and installed, under the qualification-time approved Decision and a valid historical chain.',
                    ];
                }
            }
        }

        // 2. Qualified Release Reference Correspondence (Must Prove the SHA)
        $observed = $mode === 'dist' ? $dist : ($mode === 'source' ? $source : null);
        $observedRef = $observed['reference'] ?? null;

        if ($observedRef === null || $observedRef === '') {
            $checks['qualified_reference'] = [
                'status' => 'FAIL',
                'message' => 'Installed package metadata does not expose an exact commit or tag reference.',
            ];
        } elseif (strtolower($observedRef) !== strtolower($qualifiedSha)) {
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

        return ['mode' => $mode, 'delivery_evidence' => $evidence, 'checks' => $checks];
    }

    /**
     * Inspects installed artifact directory, required files, composer.json manifest,
     * forbidden content, and content hashes against the COMPLETE qualified manifest.
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
            if (! file_exists($installedPath . '/' . $file)) {
                $missing[] = $file;
            }
        }

        $checks['installed_required_files'] = $missing !== [] ? [
            'status' => 'FAIL',
            'message' => sprintf('Installed artifact is missing required consumer/release-facing files: %s.', implode(', ', $missing)),
            'details' => $missing,
        ] : [
            'status' => 'PASS',
            'message' => 'All required consumer/release-facing files are present in the installed artifact.',
        ];

        // 2. Prohibited content: distribution must not add sensitive/development material
        $prohibited = [];
        foreach (ReleaseContract::listFiles($installedPath) as $file) {
            if (ReleaseContract::isForbiddenPath($file) || is_link($installedPath . '/' . $file)) {
                $prohibited[] = $file;
            }
        }
        $checks['installed_forbidden_content'] = $prohibited !== [] ? [
            'status' => 'FAIL',
            'message' => sprintf('Installed artifact contains prohibited content: %s.', implode(', ', $prohibited)),
            'details' => $prohibited,
        ] : [
            'status' => 'PASS',
            'message' => 'Installed artifact contains no prohibited sensitive/development material.',
        ];

        // 3. Installed Manifest Integrity
        $installedComposer = $installedPath . '/composer.json';
        if (file_exists($installedComposer)) {
            $parsed = json_decode((string) file_get_contents($installedComposer), true);
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

        // 4. Installed README Consistency
        $installedReadme = $installedPath . '/README.md';
        if (file_exists($installedReadme)) {
            $checks['installed_readme_identity'] = ! str_contains((string) file_get_contents($installedReadme), $target) ? [
                'status' => 'FAIL',
                'message' => sprintf('Installed README.md does not reference the target version "%s".', $target),
            ] : [
                'status' => 'PASS',
                'message' => 'Installed README.md accurately represents target artifact identity.',
            ];
        }

        // 5. Content Manifest Correspondence against the COMPLETE RAV manifest
        if ($expectedContentManifest === null || $expectedContentManifest === []) {
            $checks['content_manifest_correspondence'] = [
                'status' => 'FAIL',
                'message' => 'No qualified content manifest is available; installed content cannot be verified.',
            ];
        } else {
            $incomplete = array_diff(self::REQUIRED_INSTALLED_FILES, array_keys($expectedContentManifest));
            if ($incomplete !== []) {
                $checks['content_manifest_correspondence'] = [
                    'status' => 'FAIL',
                    'message' => sprintf('Qualified content manifest is incomplete (missing: %s); content correspondence cannot be proven.', implode(', ', $incomplete)),
                ];

                return ['checks' => $checks];
            }

            $hashMismatches = [];
            foreach ($expectedContentManifest as $relPath => $expectedHash) {
                $fullPath = $installedPath . '/' . $relPath;
                if (! file_exists($fullPath)) {
                    $hashMismatches[] = sprintf('%s (missing from installed package)', $relPath);
                    continue;
                }
                $actualHash = ReleaseContract::hashPath($fullPath);
                if ($actualHash !== $expectedHash) {
                    $hashMismatches[] = sprintf('%s (expected %s, got %s)', $relPath, substr($expectedHash, 0, 12), substr($actualHash, 0, 12));
                }
            }

            $checks['content_manifest_correspondence'] = $hashMismatches !== [] ? [
                'status' => 'FAIL',
                'message' => sprintf('Installed artifact content differs from qualified evidence: %s.', implode('; ', $hashMismatches)),
                'details' => $hashMismatches,
            ] : [
                'status' => 'PASS',
                'message' => sprintf('Installed artifact content verified; all %d qualified content hashes match qualification evidence.', count($expectedContentManifest)),
            ];
        }

        return ['checks' => $checks];
    }

    /**
     * Recursively and deterministically hashes a file or directory using SHA-256.
     */
    public function hashPath(string $path): string
    {
        return ReleaseContract::hashPath($path);
    }

    /**
     * Redacts credentials, tokens, and authorization headers from diagnostic strings.
     */
    public function redactSensitiveData(string $text): string
    {
        return ReleaseContract::redactSensitiveData($text);
    }

    /**
     * Builds the minimal, explicit Composer child environment. NOTHING is inherited:
     * every COMPOSER_* / HOME / vendor-dir / root-selection variable is either set to an
     * isolated value here or absent. Only PATH and transport settings are passed through.
     *
     * @param array<string, string> $parentEnv
     * @return array{env: array<string, string>, evidence: array{set: array<string, string>, cleared: list<string>, passthrough: list<string>}}
     */
    public function buildComposerEnvironment(string $root, array $parentEnv): array
    {
        $set = [
            'HOME' => $root . '/home',
            'COMPOSER_HOME' => $root . '/composer-home',
            'COMPOSER_CACHE_DIR' => $root . '/composer-cache',
            'COMPOSER_VENDOR_DIR' => $root . '/vendor',
            'COMPOSER' => $root . '/composer.json',
            'COMPOSER_NO_INTERACTION' => '1',
        ];

        $env = $set;
        $env['PATH'] = $parentEnv['PATH'] ?? '/usr/local/bin:/usr/bin:/bin';

        $passthrough = [];
        foreach (self::TRANSPORT_PASSTHROUGH as $name) {
            if (isset($parentEnv[$name]) && $parentEnv[$name] !== '') {
                $env[$name] = $parentEnv[$name];
                $passthrough[] = $name;
            }
        }

        // Names (never values) of inherited state that is deliberately NOT forwarded:
        // COMPOSER_AUTH, COMPOSER_ROOT_VERSION, COMPOSER_MIRROR_PATH_REPOS, COMPOSER_PREFER_STABLE,
        // COMPOSER_MINIMAL_CHANGES, COMPOSER_WITH_ALL_DEPENDENCIES, COMPOSER_HOME overrides, etc.
        $cleared = [];
        foreach (array_keys($parentEnv) as $name) {
            if ((str_starts_with($name, 'COMPOSER') || in_array($name, ['HOME', 'XDG_CONFIG_HOME', 'XDG_CACHE_HOME', 'XDG_DATA_HOME', 'GIT_CONFIG_GLOBAL', 'GIT_CONFIG_SYSTEM', 'GIT_DIR', 'PHP_INI_SCAN_DIR', 'PHPRC'], true)) && ! isset($set[$name])) {
                $cleared[] = $name;
            }
        }
        sort($cleared);

        return ['env' => $env, 'evidence' => ['set' => $set, 'cleared' => $cleared, 'passthrough' => $passthrough]];
    }

    /**
     * Fresh external consumer manifest. Only the qualification-bound channel is enabled
     * (default Packagist is explicitly disabled so resolution cannot silently use it).
     *
     * @return array{
     *     name: string,
     *     type: string,
     *     require: array<string, string>,
     *     repositories: list<array<string, bool|string>>,
     *     minimum-stability: string,
     *     prefer-stable: bool,
     *     config: array{secure-http: bool, preferred-install: string, allow-plugins: bool, audit: array<string, string>},
     * }
     */
    public function buildConsumerManifest(string $package, string $target, string $channel): array
    {
        return [
            'name' => 'maatify/published-artifact-verification-consumer',
            'type' => 'project',
            'require' => [
                'php' => '>=8.4',
                $package => $target,
            ],
            'repositories' => [
                ['type' => 'composer', 'url' => $channel],
                ['packagist.org' => false],
            ],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            'config' => [
                'secure-http' => true,
                'preferred-install' => 'dist',
                'allow-plugins' => false,
                'audit' => [
                    'abandoned' => 'fail',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function text(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyAudit(): array
    {
        return [
            'composer_version' => 'UNKNOWN',
            'isolated_root' => '',
            'isolated_home' => '',
            'isolated_cache' => '',
            'effective_repository' => null,
            'prefer_install' => ['requested_flag' => '--prefer-dist', 'configured' => 'dist', 'effective_config' => 'UNKNOWN'],
            'controlled_environment' => ['set' => [], 'cleared' => [], 'passthrough' => []],
        ];
    }

    /**
     * @param 'PASS'|'FAIL' $status
     * @param array<string, mixed> $audit
     * @param array<string, array{status: 'PASS'|'FAIL', message: string, details?: mixed}> $checks
     * @param list<string> $failures
     * @param array<string, mixed> $deliveryEvidence
     * @return array{
     *     status: 'PASS'|'FAIL',
     *     package_name: string,
     *     target_version: string,
     *     qualified_sha: string,
     *     installation_mode: string,
     *     installed_path: string,
     *     composer_audit: array<string, mixed>,
     *     delivery_evidence: array<string, mixed>,
     *     verified_at: string,
     *     checks: array<string, array{status: 'PASS'|'FAIL', message: string, details?: mixed}>,
     *     failures: list<string>,
     * }
     */
    private function buildResult(
        string $status,
        string $package,
        string $target,
        string $sha,
        string $mode,
        string $installedPath,
        array $audit,
        array $checks,
        array $failures,
        array $deliveryEvidence,
    ): array {
        return [
            'status' => $status,
            'package_name' => $package,
            'target_version' => $target,
            'qualified_sha' => $sha,
            'installation_mode' => $mode,
            'installed_path' => $installedPath,
            'composer_audit' => $audit,
            'delivery_evidence' => $deliveryEvidence,
            'verified_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'checks' => $checks,
            'failures' => $failures,
        ];
    }

    /**
     * @return array{type: ?string, url: ?string, reference: ?string}|null
     */
    private function describeReference(mixed $node): ?array
    {
        if (! is_array($node)) {
            return null;
        }
        $str = static fn(string $k): ?string => is_string($node[$k] ?? null) && $node[$k] !== '' ? $node[$k] : null;
        $url = $str('url');

        return ['type' => $str('type'), 'url' => $url === null ? null : ReleaseContract::redactUrl($url), 'reference' => $str('reference')];
    }

    /**
     * @param array<string, mixed> $installedPkg
     */
    private function resolveInstalledPath(string $root, array $installedPkg, string $package): ?string
    {
        $installPath = $installedPkg['install-path'] ?? null;
        $expected = realpath($root . '/vendor/' . $package);
        if (! is_string($installPath) || $installPath === '') {
            return $expected === false ? null : $expected;
        }
        $resolved = realpath($root . '/vendor/composer/' . $installPath);
        $vendor = realpath($root . '/vendor');
        if ($resolved === false || $vendor === false || ! str_starts_with($resolved, $vendor . '/') || ($expected !== false && $expected !== $resolved)) {
            return null;
        }

        return $resolved;
    }

    private function createIsolatedEnvironment(): string
    {
        $dir = sys_get_temp_dir() . '/maatify-pav-' . bin2hex(random_bytes(6));
        foreach ([$dir, $dir . '/home', $dir . '/composer-home', $dir . '/composer-cache'] as $d) {
            if (! mkdir($d, 0700, true) && ! is_dir($d)) {
                throw new RuntimeException('Unable to create isolated directory: ' . $d);
            }
        }
        // Explicit, empty global configuration: no inherited global Composer policy.
        file_put_contents($dir . '/composer-home/config.json', "{}\n");

        return $dir;
    }

    /**
     * @param array<string, mixed> $audit
     * @return array{status: 'PASS'|'FAIL', message: string, details?: array<string, mixed>}
     */
    private function runIsolatedComposerInstall(string $root, string $package, string $target, string $channel, array &$audit): array
    {
        file_put_contents(
            $root . '/composer.json',
            (string) json_encode($this->buildConsumerManifest($package, $target, $channel), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        /** @var array<string, string> $parentEnv */
        $parentEnv = array_filter(getenv(), 'is_string');
        $controlled = $this->buildComposerEnvironment($root, $parentEnv);
        $env = $controlled['env'];
        $audit['controlled_environment'] = $controlled['evidence'];

        $version = ProcessRunner::run(['composer', '--version', '--no-ansi'], $root, $env, null, true);
        $audit['composer_version'] = $version['code'] === 0 ? trim(explode("\n", $version['output'])[0]) : 'UNKNOWN';

        $prefer = ProcessRunner::run(['composer', 'config', '--no-ansi', 'preferred-install'], $root, $env, null, false);
        $audit['prefer_install'] = [
            'requested_flag' => '--prefer-dist',
            'configured' => 'dist',
            'effective_config' => $prefer['code'] === 0 ? trim($prefer['output']) : 'UNKNOWN',
        ];

        $run = ProcessRunner::run(
            ['composer', 'update', '--no-interaction', '--prefer-dist', '--no-progress', '--no-plugins', '--no-scripts', '--no-ansi'],
            $root,
            $env,
            null,
            true,
        );

        $cleanOutput = array_map([$this, 'redactSensitiveData'], explode("\n", $run['output']));

        if ($run['code'] !== 0) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Isolated Composer resolution failed (code %d): %s', $run['code'], implode("\n", array_slice($cleanOutput, -10))),
                'details' => ['output' => $cleanOutput],
            ];
        }

        return [
            'status' => 'PASS',
            'message' => sprintf('Successfully resolved and installed "%s:%s" in isolated environment.', $package, $target),
            'details' => ['output' => $cleanOutput],
        ];
    }
}
