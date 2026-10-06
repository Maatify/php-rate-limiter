<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\ReleaseVerification;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Generic, target-agnostic Release Artifact Verifier.
 *
 * Implements pre-publication Release Artifact Verification required by:
 * - CI_WORKFLOW_STANDARD.md §2.5
 * - COMPOSER_PACKAGE_STANDARD.md §26 & §26.1
 * - LIBRARY_PRESENTATION_STANDARD.md §8.1.2, §14 & §23
 */
final class ReleaseArtifactVerifier
{
    /** @var list<string> */
    public const array REQUIRED_RELEASE_FACING_FILES = [
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

    /** @var list<string> */
    public const array REQUIRED_SEMANTIC_CLAIMS = [
        'readme.release_artifact_identity',
        'readme.exact_install_target',
        'readme.pre_publication_truth',
        'changelog.target_allocation',
        'changelog.undated_target_preparation',
        'changelog.no_unallocated_represented_changes',
        'security.lifecycle_support_semantics',
        'package_reference.consumer_identity_consistency',
    ];

    public const string EXPECTED_PACKAGE_NAME = 'maatify/php-rate-limiter';
    public const string EXPECTED_LICENSE = 'proprietary';
    public const string SEMANTIC_REVIEW_SCHEMA_VERSION = '1.0.0';
    public const string QUALIFICATION_EVIDENCE_SCHEMA_VERSION = '1.0.0';

    /**
     * Executes qualifying Release Artifact Verification.
     *
     * In qualifying mode (default), all evidence gates are strictly required:
     * - Real Git repository with valid candidate commit object
     * - Candidate SHA matching checked-out HEAD
     * - Clean Git worktree and index
     * - Valid canonical semantic review record with all required claims
     * - Deterministic package manifest, distribution safety, and artifact checks
     *
     * @param array{
     *     target: string,
     *     candidate_sha: string,
     *     repo_path?: string,
     *     semantic_review_file?: string|null,
     *     delivery_policy?: string,
     *     source_only_decision_id?: string|null,
     *     source_only_decision_file?: string|null,
     *     source_only_commit?: string|null,
     *     output_evidence?: string|null,
     *     is_qualifying?: bool,
     * } $options
     * @return array{
     *     status: 'PASS'|'FAIL'|'INSPECTION_ONLY',
     *     package_name: string,
     *     target_version: string,
     *     candidate_sha: string,
     *     candidate_tree_sha: string,
     *     delivery_policy: string,
     *     verified_at: string,
     *     checks: array<string, array{status: 'PASS'|'FAIL', message: string, details?: mixed}>,
     *     failures: list<string>,
     *     qualification_evidence?: array<string, mixed>,
     * }
     */
    public function verify(array $options): array
    {
        $target = trim($options['target']);
        $candidateSha = trim($options['candidate_sha']);
        $repoPath = realpath($options['repo_path'] ?? (string) getcwd());
        if ($repoPath === false || ! is_dir($repoPath)) {
            throw new RuntimeException('Invalid repository path provided: ' . ($options['repo_path'] ?? ''));
        }

        $semanticReviewFile = $options['semantic_review_file'] ?? null;
        $deliveryPolicy = strtolower(trim($options['delivery_policy'] ?? 'dist'));
        $isQualifying = $options['is_qualifying'] ?? true;

        $checks = [];
        $failures = [];
        $candidateTreeSha = '';

        // 1. Target Version Syntax
        $syntaxCheck = $this->evaluateTargetVersionSyntax($target);
        $checks['target_version_syntax'] = $syntaxCheck;
        if ($syntaxCheck['status'] === 'FAIL') {
            $failures[] = $syntaxCheck['message'];
        }

        // 2. Candidate SHA, Git Identity, and Clean Tree
        $gitCheck = $this->evaluateCandidateShaAndGit($repoPath, $candidateSha);
        $checks['candidate_sha_and_git'] = $gitCheck;
        $candidateTreeSha = '';
        if ($gitCheck['status'] === 'FAIL') {
            $failures[] = $gitCheck['message'];
        } elseif (isset($gitCheck['details'])) {
            $candidateTreeSha = $gitCheck['details']['candidate_tree_sha'];
        }

        // 3. Package Identity and Composer Manifest
        $manifestCheck = $this->evaluatePackageManifest($repoPath);
        $checks['package_identity_and_manifest'] = $manifestCheck;
        if ($manifestCheck['status'] === 'FAIL') {
            $failures[] = $manifestCheck['message'];
        }

        // 4. Required Release-Facing Files
        $filesCheck = $this->evaluateRequiredFiles($repoPath);
        $checks['required_files'] = $filesCheck;
        if ($filesCheck['status'] === 'FAIL') {
            $failures[] = $filesCheck['message'];
        }

        // 5. Distribution Safety and Forbidden Artifacts
        $safetyCheck = $this->evaluateDistributionSafety($repoPath);
        $checks['distribution_safety'] = $safetyCheck;
        if ($safetyCheck['status'] === 'FAIL') {
            $failures[] = $safetyCheck['message'];
        }

        // 6. Candidate Git Archive Export Verification
        if ($gitCheck['status'] === 'PASS') {
            $archiveCheck = $this->evaluateCandidateArchiveExport($repoPath, $candidateSha);
            $checks['candidate_archive_export'] = $archiveCheck;
            if ($archiveCheck['status'] === 'FAIL') {
                $failures[] = $archiveCheck['message'];
            }
        }

        // 7. Canonical Semantic Review Evidence Boundary
        $semanticReviewResult = $this->evaluateSemanticReviewEvidence($semanticReviewFile, $target, $candidateSha);
        $checks['semantic_review'] = $semanticReviewResult;
        if ($semanticReviewResult['status'] === 'FAIL') {
            $failures[] = $semanticReviewResult['message'];
        }

        // Extract confirmed semantic claims (if semantic review succeeded)
        $semanticDetails = $semanticReviewResult['details'] ?? [];
        $claims = isset($semanticDetails['claims']) && is_array($semanticDetails['claims'])
            ? $semanticDetails['claims']
            : [];
        $noUnallocatedRepresented = ($claims['changelog.no_unallocated_represented_changes'] ?? null) === 'CONFIRMED';

        // 8. README Release Artifact Identity
        $readmeCheck = $this->evaluateReadmeIdentity($repoPath, $target);
        $checks['readme_artifact_identity'] = $readmeCheck;
        if ($readmeCheck['status'] === 'FAIL') {
            $failures[] = $readmeCheck['message'];
        }

        // 9. CHANGELOG Target Allocation and Boundaries
        $changelogCheck = $this->evaluateChangelogAllocation($repoPath, $target, $noUnallocatedRepresented);
        $checks['changelog_target_allocation'] = $changelogCheck;
        if ($changelogCheck['status'] === 'FAIL') {
            $failures[] = $changelogCheck['message'];
        }

        // 10. SECURITY Pre-Release Lifecycle Semantics
        $securityCheck = $this->evaluateSecurityLifecycle($repoPath, $target);
        $checks['security_lifecycle'] = $securityCheck;
        if ($securityCheck['status'] === 'FAIL') {
            $failures[] = $securityCheck['message'];
        }

        // 11. Delivery Policy & Source-Only Qualification Governance
        $sourceOnlyOptions = $deliveryPolicy === 'source-only' ? [
            'decision_id' => $options['source_only_decision_id'] ?? null,
            'decision_file' => $options['source_only_decision_file'] ?? null,
            'commit_ref' => $options['source_only_commit'] ?? null,
        ] : null;

        $policyCheck = $this->evaluateSourceOnlyPolicy($sourceOnlyOptions, $repoPath, $target, $candidateSha, $deliveryPolicy);
        $checks['delivery_policy'] = $policyCheck;
        if ($policyCheck['status'] === 'FAIL') {
            $failures[] = $policyCheck['message'];
        }

        // Non-qualifying inspection mode handling
        if (! $isQualifying) {
            return [
                'status' => 'INSPECTION_ONLY',
                'package_name' => self::EXPECTED_PACKAGE_NAME,
                'target_version' => $target,
                'candidate_sha' => $candidateSha,
                'candidate_tree_sha' => $candidateTreeSha,
                'delivery_policy' => $deliveryPolicy,
                'verified_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'checks' => $checks,
                'failures' => $failures,
            ];
        }

        $overallStatus = ($failures === []) ? 'PASS' : 'FAIL';
        $result = [
            'status' => $overallStatus,
            'package_name' => self::EXPECTED_PACKAGE_NAME,
            'target_version' => $target,
            'candidate_sha' => $candidateSha,
            'candidate_tree_sha' => $candidateTreeSha,
            'delivery_policy' => $deliveryPolicy,
            'verified_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'checks' => $checks,
            'failures' => $failures,
        ];

        // If qualifying RAV passed, emit authoritative qualification evidence
        if ($overallStatus === 'PASS') {
            $contentManifest = $this->buildContentManifest($repoPath);
            $qualificationEvidence = [
                'schema_version' => self::QUALIFICATION_EVIDENCE_SCHEMA_VERSION,
                'package_name' => self::EXPECTED_PACKAGE_NAME,
                'target_version' => $target,
                'candidate_sha' => $candidateSha,
                'candidate_tree_sha' => $candidateTreeSha,
                'delivery_policy' => $deliveryPolicy,
                'qualified_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'content_manifest' => $contentManifest,
                'semantic_review' => $semanticDetails,
                'source_only_decision' => $policyCheck['details'] ?? null,
                'status' => 'PASS',
            ];

            $result['qualification_evidence'] = $qualificationEvidence;

            if (isset($options['output_evidence'])) {
                file_put_contents(
                    (string) $options['output_evidence'],
                    (string) json_encode($qualificationEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                );
            }
        }

        return $result;
    }

    /**
     * @return array{status: 'PASS'|'FAIL', message: string}
     */
    public function evaluateTargetVersionSyntax(string $target): array
    {
        $isSemVer = (bool) preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/', $target);
        if (! $isSemVer) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Target version "%s" is not a valid Semantic Version.', $target),
            ];
        }

        return [
            'status' => 'PASS',
            'message' => sprintf('Target version "%s" is valid SemVer.', $target),
        ];
    }

    /**
     * @return array{status: 'PASS'|'FAIL', message: string, details?: array{candidate_sha: string, candidate_tree_sha: string, head_sha: string}}
     */
    public function evaluateCandidateShaAndGit(string $repoPath, string $candidateSha): array
    {
        // 1. Is repository inside a valid Git work tree?
        $out = [];
        $code = 0;
        exec(sprintf('git -C %s rev-parse --is-inside-work-tree 2>/dev/null', escapeshellarg($repoPath)), $out, $code);
        if ($code !== 0 || trim($out[0] ?? '') !== 'true') {
            return [
                'status' => 'FAIL',
                'message' => 'Repository path is not inside a valid Git work tree. Qualifying RAV requires a real Git repository.',
            ];
        }

        // 2. Validate candidate SHA syntax
        if (! (bool) preg_match('/^[0-9a-f]{40}$/i', $candidateSha)) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Candidate SHA "%s" must be a 40-character hexadecimal commit hash.', $candidateSha),
            ];
        }

        // 3. Verify candidate SHA exists as a commit object in the repository
        $typeOut = [];
        $typeCode = 0;
        exec(sprintf('git -C %s cat-file -t %s 2>/dev/null', escapeshellarg($repoPath), escapeshellarg($candidateSha)), $typeOut, $typeCode);
        if ($typeCode !== 0 || trim($typeOut[0] ?? '') !== 'commit') {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Candidate SHA "%s" does not exist as a commit object in this repository.', $candidateSha),
            ];
        }

        // 4. Verify checked-out HEAD equals candidate SHA
        $headOut = [];
        $headCode = 0;
        exec(sprintf('git -C %s rev-parse HEAD 2>/dev/null', escapeshellarg($repoPath)), $headOut, $headCode);
        $headSha = trim($headOut[0] ?? '');
        if ($headCode !== 0 || strtolower($headSha) !== strtolower($candidateSha)) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Checked-out HEAD "%s" does not match candidate SHA "%s".', $headSha, $candidateSha),
            ];
        }

        // 5. Obtain candidate tree SHA
        $treeOut = [];
        $treeCode = 0;
        exec(sprintf('git -C %s rev-parse %s^{tree} 2>/dev/null', escapeshellarg($repoPath), escapeshellarg($candidateSha)), $treeOut, $treeCode);
        $treeSha = trim($treeOut[0] ?? '');
        if ($treeCode !== 0 || ! (bool) preg_match('/^[0-9a-f]{40}$/i', $treeSha)) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Unable to resolve candidate tree object for commit "%s".', $candidateSha),
            ];
        }

        // 6. Verify clean worktree and clean index
        $statusOut = [];
        $statusCode = 0;
        exec(sprintf('git -C %s status --porcelain 2>/dev/null', escapeshellarg($repoPath)), $statusOut, $statusCode);
        if ($statusCode !== 0 || ! empty($statusOut)) {
            return [
                'status' => 'FAIL',
                'message' => sprintf(
                    'Git worktree or index is not clean (%d uncommitted modification(s)). Qualifying RAV requires an immutable clean tree.',
                    count($statusOut),
                ),
            ];
        }

        return [
            'status' => 'PASS',
            'message' => sprintf('Candidate commit object (%s) and tree (%s) verified; git HEAD matches and working tree is clean.', $candidateSha, $treeSha),
            'details' => [
                'candidate_sha' => $candidateSha,
                'candidate_tree_sha' => $treeSha,
                'head_sha' => $headSha,
            ],
        ];
    }

    /**
     * @return array{status: 'PASS'|'FAIL', message: string}
     */
    public function evaluatePackageManifest(string $repoPath): array
    {
        $manifestPath = $repoPath . '/composer.json';
        if (! file_exists($manifestPath)) {
            return [
                'status' => 'FAIL',
                'message' => 'composer.json does not exist in repository root.',
            ];
        }

        $manifestRaw = file_get_contents($manifestPath);
        $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
        if (! is_array($manifest)) {
            return [
                'status' => 'FAIL',
                'message' => 'composer.json contains invalid JSON.',
            ];
        }

        $manifestName = is_scalar($manifest['name'] ?? null) ? (string) $manifest['name'] : '';
        if ($manifestName !== self::EXPECTED_PACKAGE_NAME) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('composer.json name "%s" does not match expected package identity "%s".', $manifestName, self::EXPECTED_PACKAGE_NAME),
            ];
        }

        $manifestLicense = is_scalar($manifest['license'] ?? null) ? (string) $manifest['license'] : '';
        if ($manifestLicense !== self::EXPECTED_LICENSE) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('composer.json license "%s" does not match expected package license "%s".', $manifestLicense, self::EXPECTED_LICENSE),
            ];
        }

        if (isset($manifest['version'])) {
            return [
                'status' => 'FAIL',
                'message' => 'composer.json MUST NOT declare a static "version" field; versions are governed by Git tags.',
            ];
        }

        return [
            'status' => 'PASS',
            'message' => 'Package identity matches, license is proprietary, and manifest is structurally valid.',
        ];
    }

    /**
     * @return array{status: 'PASS'|'FAIL', message: string, details?: list<string>}
     */
    public function evaluateRequiredFiles(string $repoPath): array
    {
        $missingFiles = [];
        foreach (self::REQUIRED_RELEASE_FACING_FILES as $file) {
            $fullPath = $repoPath . '/' . $file;
            if (! file_exists($fullPath)) {
                $missingFiles[] = $file;
            }
        }

        if ($missingFiles !== []) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Missing required consumer/release-facing files: %s.', implode(', ', $missingFiles)),
                'details' => $missingFiles,
            ];
        }

        return [
            'status' => 'PASS',
            'message' => 'All required consumer/release-facing files are present.',
        ];
    }

    /**
     * @return array{status: 'PASS'|'FAIL', message: string, details?: list<string>}
     */
    public function evaluateDistributionSafety(string $repoPath): array
    {
        $forbiddenFound = [];
        foreach (['.env', '.env.local', 'composer.lock'] as $forbidden) {
            if (file_exists($repoPath . '/' . $forbidden)) {
                $forbiddenFound[] = $forbidden;
            }
        }

        $keysFound = glob($repoPath . '/*.{pem,key}', GLOB_BRACE);
        if (is_array($keysFound) && $keysFound !== []) {
            foreach ($keysFound as $k) {
                $forbiddenFound[] = basename($k);
            }
        }

        if (file_exists($repoPath . '/.gitattributes')) {
            $gitAttr = (string) file_get_contents($repoPath . '/.gitattributes');
            foreach (self::REQUIRED_RELEASE_FACING_FILES as $rf) {
                if (preg_match('/^' . preg_quote($rf, '/') . '\s+.*export-ignore/m', $gitAttr)) {
                    $forbiddenFound[] = sprintf('Required file "%s" is excluded by .gitattributes export-ignore', $rf);
                }
            }
        }

        if ($forbiddenFound !== []) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Distribution safety violation: %s.', implode(', ', $forbiddenFound)),
                'details' => $forbiddenFound,
            ];
        }

        return [
            'status' => 'PASS',
            'message' => 'Distribution safety verified: no forbidden artifacts or invalid export exclusions.',
        ];
    }

    /**
     * @return array{status: 'PASS'|'FAIL', message: string, details?: mixed}
     */
    public function evaluateCandidateArchiveExport(string $repoPath, string $candidateSha): array
    {
        $cmd = sprintf('git -C %s archive --format=tar %s 2>&1', escapeshellarg($repoPath), escapeshellarg($candidateSha));
        $output = [];
        $exitCode = 0;
        exec($cmd . ' | tar -tf - 2>/dev/null', $output, $exitCode);

        if ($exitCode !== 0 || $output === []) {
            return [
                'status' => 'FAIL',
                'message' => 'Unable to generate or inspect git archive for candidate commit ' . $candidateSha,
            ];
        }

        $archiveEntries = array_map(static fn(string $f): string => rtrim($f, '/'), $output);
        $missing = [];
        foreach (self::REQUIRED_RELEASE_FACING_FILES as $rf) {
            if (! in_array($rf, $archiveEntries, true)) {
                $missing[] = $rf;
            }
        }

        if ($missing !== []) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Candidate git archive export excludes required release-facing file(s): %s.', implode(', ', $missing)),
                'details' => ['missing' => $missing],
            ];
        }

        return [
            'status' => 'PASS',
            'message' => 'Candidate git archive export verified; all required release-facing files are preserved in distribution.',
        ];
    }

    /**
     * @return array{status: 'PASS'|'FAIL', message: string}
     */
    public function evaluateReadmeIdentity(string $repoPath, string $target): array
    {
        $readmePath = $repoPath . '/README.md';
        if (! file_exists($readmePath)) {
            return [
                'status' => 'FAIL',
                'message' => 'README.md not found.',
            ];
        }

        $readmeContent = (string) file_get_contents($readmePath);
        $hasTargetMention = str_contains($readmeContent, $target);
        $hasFalsePublishedClaim = (bool) preg_match('/(?:^|\n)#[^\n]*Published on Packagist[^\n]*\b' . preg_quote($target, '/') . '\b/i', $readmeContent);

        if (! $hasTargetMention) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('README.md does not reference exact target release identity "%s".', $target),
            ];
        }

        if ($hasFalsePublishedClaim) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('README.md contains unqualified pre-publication availability claim for "%s".', $target),
            ];
        }

        return [
            'status' => 'PASS',
            'message' => 'README artifact identity references target without false pre-publication assertions.',
        ];
    }

    /**
     * Evaluates CHANGELOG allocation against the adopted Library Presentation Standard:
     * - The target heading must be an undated exact-target section: `## [<target>]`
     * - Headings with dates (`## [<target>] - YYYY-MM-DD`) or status suffixes (`- upcoming`, `- unreleased`, `(planned)`) are strictly rejected.
     * - `[Unreleased]` boundary:
     *   - MUST exist when represented implemented changes remain unallocated.
     *   - MAY be omitted when semantic review positively confirms no unallocated represented changes remain.
     *
     * @return array{status: 'PASS'|'FAIL', message: string}
     */
    public function evaluateChangelogAllocation(string $repoPath, string $target, bool $noUnallocatedRepresentedChanges): array
    {
        $changelogPath = $repoPath . '/CHANGELOG.md';
        if (! file_exists($changelogPath)) {
            return [
                'status' => 'FAIL',
                'message' => 'CHANGELOG.md not found.',
            ];
        }

        $changelogContent = (string) file_get_contents($changelogPath);
        $hasUnreleased = str_contains($changelogContent, '## [Unreleased]');

        // Undated exact-target heading requirement: must be "## [<target>]" alone on heading line
        $hasExactUndatedTarget = (bool) preg_match('/^## \[' . preg_quote($target, '/') . '\]\s*$/m', $changelogContent);

        // Detect improper suffixed or dated headings
        $hasDatedTarget = (bool) preg_match('/^## \[' . preg_quote($target, '/') . '\]\s*-\s*\d{4}-\d{2}-\d{2}/m', $changelogContent);
        $hasSuffixedTarget = (bool) preg_match('/^## \[' . preg_quote($target, '/') . '\]\s*-\s*(upcoming|unreleased|planned)/im', $changelogContent);

        if ($hasDatedTarget) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('CHANGELOG.md target section "[%s]" contains a publication date prior to publication.', $target),
            ];
        }

        if ($hasSuffixedTarget) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('CHANGELOG.md target section "[%s]" must be an exact undated heading without status suffix (e.g. "## [%s]").', $target, $target),
            ];
        }

        if (! $hasExactUndatedTarget) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('CHANGELOG.md does not contain an allocated undated section for target version "## [%s]".', $target),
            ];
        }

        // [Unreleased] section check:
        // If absent, allowed ONLY if semantic review confirms no unallocated represented changes remain
        if (! $hasUnreleased && ! $noUnallocatedRepresentedChanges) {
            return [
                'status' => 'FAIL',
                'message' => 'CHANGELOG.md is missing "## [Unreleased]" boundary heading while unallocated represented changes are not confirmed absent by semantic review.',
            ];
        }

        return [
            'status' => 'PASS',
            'message' => sprintf('CHANGELOG undated target section "## [%s]" and allocation boundaries verified.', $target),
        ];
    }

    /**
     * @return array{status: 'PASS'|'FAIL', message: string}
     */
    public function evaluateSecurityLifecycle(string $repoPath, string $target): array
    {
        $securityPath = $repoPath . '/SECURITY.md';
        if (! file_exists($securityPath)) {
            return [
                'status' => 'FAIL',
                'message' => 'SECURITY.md not found.',
            ];
        }

        $securityContent = (string) file_get_contents($securityPath);
        $isPrerelease = str_contains($target, '-');
        $promisesPrereleaseSupport = $isPrerelease && (bool) preg_match('/\|[ ]*' . preg_quote($target, '/') . '[ ]*\|[ ]*:white_check_mark:[ ]*\|/i', $securityContent);

        if ($promisesPrereleaseSupport) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('SECURITY.md falsely promises active Stable support for pre-release target "%s".', $target),
            ];
        }

        return [
            'status' => 'PASS',
            'message' => 'SECURITY.md lifecycle and pre-release support semantics verified.',
        ];
    }

    /**
     * Validates canonical semantic review evidence.
     *
     * Schema requirements:
     * - schema_version: "1.0.0"
     * - target: exact SemVer target
     * - candidate_sha: exact 40-hex commit SHA
     * - reviewer: non-empty string
     * - reviewed_at: non-empty ISO 8601 UTC timestamp
     * - disposition: "APPROVED"
     * - claims: array containing all 8 REQUIRED_SEMANTIC_CLAIMS confirmed as "CONFIRMED"
     *
     * @return array{status: 'PASS'|'FAIL', message: string, details?: array<string, mixed>}
     */
    public function evaluateSemanticReviewEvidence(?string $filePath, string $target, string $candidateSha): array
    {
        if ($filePath === null || ! file_exists($filePath)) {
            return [
                'status' => 'FAIL',
                'message' => 'Canonical semantic review evidence record is required for qualifying RAV but was not provided or does not exist.',
            ];
        }

        $raw = file_get_contents($filePath);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($data)) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Semantic review evidence file "%s" is not valid JSON.', $filePath),
            ];
        }

        $recordedSchema = is_scalar($data['schema_version'] ?? null) ? (string) $data['schema_version'] : '';
        $recordedTarget = is_scalar($data['target'] ?? null) ? (string) $data['target'] : '';
        $recordedSha = is_scalar($data['candidate_sha'] ?? null) ? (string) $data['candidate_sha'] : '';
        $disposition = is_scalar($data['disposition'] ?? null) ? (string) $data['disposition'] : '';
        $reviewer = is_scalar($data['reviewer'] ?? null) ? (string) $data['reviewer'] : '';
        $reviewedAt = is_scalar($data['reviewed_at'] ?? null) ? (string) $data['reviewed_at'] : '';
        $claims = $data['claims'] ?? null;

        if ($recordedSchema !== self::SEMANTIC_REVIEW_SCHEMA_VERSION) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Semantic review evidence schema_version "%s" does not match expected version "%s".', $recordedSchema, self::SEMANTIC_REVIEW_SCHEMA_VERSION),
            ];
        }

        if ($recordedTarget !== $target) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Semantic review target "%s" does not match verification target "%s".', $recordedTarget, $target),
            ];
        }

        if (strtolower($recordedSha) !== strtolower($candidateSha)) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Semantic review SHA "%s" does not match candidate SHA "%s".', $recordedSha, $candidateSha),
            ];
        }

        if ($disposition !== 'APPROVED') {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Semantic review disposition is "%s", expected "APPROVED".', $disposition),
            ];
        }

        if ($reviewer === '' || $reviewedAt === '') {
            return [
                'status' => 'FAIL',
                'message' => 'Semantic review evidence must include non-empty reviewer identity and reviewed_at timestamp.',
            ];
        }

        if (! is_array($claims)) {
            return [
                'status' => 'FAIL',
                'message' => 'Semantic review evidence claims must be an associative map of confirmed assertions.',
            ];
        }

        $missingClaims = [];
        $unconfirmedClaims = [];
        foreach (self::REQUIRED_SEMANTIC_CLAIMS as $requiredClaim) {
            if (! array_key_exists($requiredClaim, $claims)) {
                $missingClaims[] = $requiredClaim;
            } elseif ($claims[$requiredClaim] !== 'CONFIRMED') {
                $unconfirmedClaims[] = sprintf('%s (value: %s)', $requiredClaim, is_scalar($claims[$requiredClaim]) ? (string) $claims[$requiredClaim] : get_debug_type($claims[$requiredClaim]));
            }
        }

        if ($missingClaims !== []) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Semantic review evidence is missing required claim(s): %s.', implode(', ', $missingClaims)),
            ];
        }

        if ($unconfirmedClaims !== []) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Semantic review evidence contains unconfirmed required claim(s): %s.', implode(', ', $unconfirmedClaims)),
            ];
        }

        return [
            'status' => 'PASS',
            'message' => sprintf('Semantic review evidence approved by %s at %s with all %d required claims CONFIRMED.', $reviewer, $reviewedAt, count(self::REQUIRED_SEMANTIC_CLAIMS)),
            'details' => [
                'schema_version' => $recordedSchema,
                'target' => $recordedTarget,
                'candidate_sha' => $recordedSha,
                'reviewer' => $reviewer,
                'reviewed_at' => $reviewedAt,
                'disposition' => $disposition,
                'claims' => $claims,
                'notes' => is_scalar($data['notes'] ?? null) ? (string) $data['notes'] : null,
            ],
        ];
    }

    /**
     * @param array{
     *     decision_id?: string|null,
     *     decision_file?: string|null,
     *     commit_ref?: string|null,
     * }|null $sourceOnlyOptions
     * @return array{status: 'PASS'|'FAIL', message: string, details?: mixed}
     */
    public function evaluateSourceOnlyPolicy(
        ?array $sourceOnlyOptions,
        string $repoPath,
        string $target,
        string $candidateSha,
        string $deliveryPolicy,
    ): array {
        if ($deliveryPolicy !== 'source-only') {
            return [
                'status' => 'PASS',
                'message' => 'Standard archive distribution (dist) delivery policy verified.',
                'details' => null,
            ];
        }

        if ($sourceOnlyOptions === null) {
            return [
                'status' => 'FAIL',
                'message' => 'Delivery policy is configured as source-only, but no source-only governance parameters were provided.',
            ];
        }

        $decisionId = trim($sourceOnlyOptions['decision_id'] ?? '');
        $decisionFilePath = trim($sourceOnlyOptions['decision_file'] ?? '');
        $commitRef = trim($sourceOnlyOptions['commit_ref'] ?? '');

        if ($decisionId === '' || $decisionFilePath === '' || $commitRef === '') {
            return [
                'status' => 'FAIL',
                'message' => 'Source-only delivery policy requires non-empty decision_id, decision_file, and commit_ref.',
            ];
        }

        $resolvedFile = realpath($repoPath . '/' . ltrim($decisionFilePath, '/'));
        if ($resolvedFile === false || ! file_exists($resolvedFile)) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Source-only Decision file "%s" does not exist in repository.', $decisionFilePath),
            ];
        }

        // Verify Decision Index record
        $indexPath = $repoPath . '/docs/decisions/DECISIONS_INDEX.md';
        if (! file_exists($indexPath)) {
            return [
                'status' => 'FAIL',
                'message' => 'DECISIONS_INDEX.md does not exist in repository.',
            ];
        }

        $indexContent = (string) file_get_contents($indexPath);
        // Must be indexed with status ACTIVE at qualification time
        $indexedActive = (bool) preg_match('/\|\s*\[' . preg_quote($decisionId, '/') . '\][^\n]+\|\s*ACTIVE\s*\|/i', $indexContent);
        if (! $indexedActive) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Decision "%s" is not indexed with status ACTIVE in DECISIONS_INDEX.md.', $decisionId),
            ];
        }

        // Verify Decision Record file contents
        $content = (string) file_get_contents($resolvedFile);
        $hasActiveStatus = (bool) preg_match('/(?:Status:\s*ACTIVE|##\s*Status\s*\n\s*ACTIVE)/i', $content);
        $mentionsPackage = str_contains($content, self::EXPECTED_PACKAGE_NAME);
        $mentionsSourceOnly = str_contains($content, 'source-only') || str_contains($content, 'delivery mode = source-only');

        if (! $hasActiveStatus || ! $mentionsPackage || ! $mentionsSourceOnly) {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Decision file "%s" is not a valid ACTIVE source-only Decision Record for "%s".', $decisionFilePath, self::EXPECTED_PACKAGE_NAME),
            ];
        }

        $evidence = [
            'decision_id' => $decisionId,
            'decision_file' => str_replace('\\', '/', ltrim($decisionFilePath, '/')),
            'commit_ref' => $commitRef,
            'status_at_qualification' => 'ACTIVE',
            'package_name' => self::EXPECTED_PACKAGE_NAME,
            'target_version' => $target,
            'candidate_sha' => $candidateSha,
            'qualification_time' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        return [
            'status' => 'PASS',
            'message' => sprintf('Source-only delivery policy authorized under ACTIVE Decision "%s".', $decisionId),
            'details' => $evidence,
        ];
    }

    /**
     * Builds deterministic SHA-256 content hashes of all required release-facing files and composer.json.
     *
     * @return array<string, string>
     */
    public function buildContentManifest(string $repoPath): array
    {
        $manifest = [];
        foreach (self::REQUIRED_RELEASE_FACING_FILES as $file) {
            $fullPath = $repoPath . '/' . $file;
            if (file_exists($fullPath)) {
                $manifest[$file] = $this->hashPath($fullPath);
            }
        }
        ksort($manifest);

        return $manifest;
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
}
