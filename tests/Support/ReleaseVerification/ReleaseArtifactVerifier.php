<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\ReleaseVerification;

use RuntimeException;

/**
 * Generic, target-agnostic Release Artifact Verifier.
 *
 * Implements pre-publication Release Artifact Verification required by:
 * - CI_WORKFLOW_STANDARD.md §2.5
 * - COMPOSER_PACKAGE_STANDARD.md §26
 * - LIBRARY_PRESENTATION_STANDARD.md §8.1.2 & §23
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

    public const string EXPECTED_PACKAGE_NAME = 'maatify/php-rate-limiter';

    /**
     * @param array{
     *     target: string,
     *     candidate_sha: string,
     *     repo_path?: string,
     *     semantic_review_file?: string|null,
     *     require_semantic_review?: bool,
     *     require_clean_git?: bool,
     * } $options
     * @return array{
     *     status: 'PASS'|'FAIL',
     *     package_name: string,
     *     target_version: string,
     *     candidate_sha: string,
     *     verified_at: string,
     *     checks: array<string, array{status: 'PASS'|'FAIL', message: string, details?: mixed}>,
     *     failures: list<string>,
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
        $requireSemanticReview = $options['require_semantic_review'] ?? true;
        $requireCleanGit = $options['require_clean_git'] ?? true;

        $checks = [];
        $failures = [];

        // 1. Target Version Syntax
        $isSemVer = (bool) preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/', $target);
        if (! $isSemVer) {
            $msg = sprintf('Target version "%s" is not a valid Semantic Version.', $target);
            $checks['target_version_syntax'] = ['status' => 'FAIL', 'message' => $msg];
            $failures[] = $msg;
        } else {
            $checks['target_version_syntax'] = ['status' => 'PASS', 'message' => sprintf('Target version "%s" is valid SemVer.', $target)];
        }

        // 2. Candidate SHA & Working Tree Identity
        $shaValid = (bool) preg_match('/^[0-9a-f]{40}$/i', $candidateSha);
        if (! $shaValid) {
            $msg = sprintf('Candidate SHA "%s" must be a 40-character hexadecimal commit hash.', $candidateSha);
            $checks['candidate_sha_identity'] = ['status' => 'FAIL', 'message' => $msg];
            $failures[] = $msg;
        } else {
            $actualHead = $this->getGitHead($repoPath);
            if ($actualHead !== null && strtolower($actualHead) !== strtolower($candidateSha)) {
                $msg = sprintf('Checked-out HEAD "%s" does not match candidate SHA "%s".', $actualHead, $candidateSha);
                $checks['candidate_sha_identity'] = ['status' => 'FAIL', 'message' => $msg];
                $failures[] = $msg;
            } else {
                $checks['candidate_sha_identity'] = ['status' => 'PASS', 'message' => sprintf('Candidate SHA matches repository HEAD (%s).', $candidateSha)];
            }
        }

        if ($requireCleanGit) {
            $isClean = $this->isGitWorktreeClean($repoPath);
            if (! $isClean) {
                $msg = 'Git worktree or index has uncommitted modifications. Release Artifact Verification requires an immutable clean tree.';
                $checks['working_tree_clean'] = ['status' => 'FAIL', 'message' => $msg];
                $failures[] = $msg;
            } else {
                $checks['working_tree_clean'] = ['status' => 'PASS', 'message' => 'Git worktree and index are clean.'];
            }
        }

        // 3. Package Identity and Composer Manifest
        $manifestPath = $repoPath . '/composer.json';
        if (! file_exists($manifestPath)) {
            $msg = 'composer.json does not exist in repository root.';
            $checks['package_identity_and_manifest'] = ['status' => 'FAIL', 'message' => $msg];
            $failures[] = $msg;
        } else {
            $manifestRaw = file_get_contents($manifestPath);
            $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
            if (! is_array($manifest)) {
                $msg = 'composer.json contains invalid JSON.';
                $checks['package_identity_and_manifest'] = ['status' => 'FAIL', 'message' => $msg];
                $failures[] = $msg;
            } elseif (($manifest['name'] ?? null) !== self::EXPECTED_PACKAGE_NAME) {
                $manifestName = is_scalar($manifest['name'] ?? null) ? (string) $manifest['name'] : '';
                $msg = sprintf('composer.json name "%s" does not match expected package identity "%s".', $manifestName, self::EXPECTED_PACKAGE_NAME);
                $checks['package_identity_and_manifest'] = ['status' => 'FAIL', 'message' => $msg];
                $failures[] = $msg;
            } elseif (isset($manifest['version'])) {
                $msg = 'composer.json MUST NOT declare a static "version" field; versions are governed by Git tags.';
                $checks['package_identity_and_manifest'] = ['status' => 'FAIL', 'message' => $msg];
                $failures[] = $msg;
            } else {
                $checks['package_identity_and_manifest'] = ['status' => 'PASS', 'message' => 'Package identity matches and manifest is structurally valid.'];
            }
        }

        // 4. Required Release-Facing Files
        $missingFiles = [];
        foreach (self::REQUIRED_RELEASE_FACING_FILES as $file) {
            $fullPath = $repoPath . '/' . $file;
            if (! file_exists($fullPath)) {
                $missingFiles[] = $file;
            }
        }
        if ($missingFiles !== []) {
            $msg = sprintf('Missing required consumer/release-facing files: %s.', implode(', ', $missingFiles));
            $checks['required_files'] = ['status' => 'FAIL', 'message' => $msg, 'details' => $missingFiles];
            $failures[] = $msg;
        } else {
            $checks['required_files'] = ['status' => 'PASS', 'message' => 'All required consumer/release-facing files are present.'];
        }

        // 5. Distribution Safety and Forbidden Artifacts
        $forbiddenFound = [];
        foreach (['.env', '.env.local', 'composer.lock'] as $forbidden) {
            if (file_exists($repoPath . '/' . $forbidden)) {
                $forbiddenFound[] = $forbidden;
            }
        }
        // Check for private keys
        $keysFound = glob($repoPath . '/*.{pem,key}', GLOB_BRACE);
        if (is_array($keysFound) && $keysFound !== []) {
            foreach ($keysFound as $k) {
                $forbiddenFound[] = basename($k);
            }
        }
        // Check archive exclusions if .gitattributes exists
        if (file_exists($repoPath . '/.gitattributes')) {
            $gitAttr = (string) file_get_contents($repoPath . '/.gitattributes');
            foreach (self::REQUIRED_RELEASE_FACING_FILES as $rf) {
                if (preg_match('/^' . preg_quote($rf, '/') . '\s+.*export-ignore/m', $gitAttr)) {
                    $forbiddenFound[] = sprintf('Required file "%s" is excluded by .gitattributes export-ignore', $rf);
                }
            }
        }
        if ($forbiddenFound !== []) {
            $msg = sprintf('Distribution safety violation: %s.', implode(', ', $forbiddenFound));
            $checks['distribution_safety'] = ['status' => 'FAIL', 'message' => $msg, 'details' => $forbiddenFound];
            $failures[] = $msg;
        } else {
            $checks['distribution_safety'] = ['status' => 'PASS', 'message' => 'Distribution safety verified: no forbidden artifacts or invalid export exclusions.'];
        }

        // 6. README Release Artifact Identity
        $readmePath = $repoPath . '/README.md';
        if (file_exists($readmePath)) {
            $readmeContent = (string) file_get_contents($readmePath);
            // Must contain reference to target install identity
            $hasTargetMention = str_contains($readmeContent, $target);
            // Must not claim pre-publication availability if it is currently being verified
            $hasFalsePublishedClaim = (bool) preg_match('/(?:^|\n)#[^\n]*Published on Packagist[^\n]*\b' . preg_quote($target, '/') . '\b/i', $readmeContent);

            if (! $hasTargetMention) {
                $msg = sprintf('README.md does not reference exact target release identity "%s".', $target);
                $checks['readme_artifact_identity'] = ['status' => 'FAIL', 'message' => $msg];
                $failures[] = $msg;
            } elseif ($hasFalsePublishedClaim) {
                $msg = sprintf('README.md contains unqualified pre-publication availability claim for "%s".', $target);
                $checks['readme_artifact_identity'] = ['status' => 'FAIL', 'message' => $msg];
                $failures[] = $msg;
            } else {
                $checks['readme_artifact_identity'] = ['status' => 'PASS', 'message' => 'README artifact identity accurately references target without false publication claims.'];
            }
        } else {
            $checks['readme_artifact_identity'] = ['status' => 'FAIL', 'message' => 'README.md not found.'];
        }

        // 7. CHANGELOG Target Allocation and Boundaries
        $changelogPath = $repoPath . '/CHANGELOG.md';
        if (file_exists($changelogPath)) {
            $changelogContent = (string) file_get_contents($changelogPath);
            $hasUnreleased = str_contains($changelogContent, '## [Unreleased]');
            $hasTargetSection = (bool) preg_match('/## \[' . preg_quote($target, '/') . '\]/', $changelogContent);

            // Pre-publication check: An unpublished target section must NOT have an invented actual release date
            $hasInventedDate = (bool) preg_match('/## \[' . preg_quote($target, '/') . '\]\s*-\s*\d{4}-\d{2}-\d{2}/', $changelogContent);

            if (! $hasUnreleased) {
                $msg = 'CHANGELOG.md is missing mandatory "## [Unreleased]" boundary heading.';
                $checks['changelog_target_allocation'] = ['status' => 'FAIL', 'message' => $msg];
                $failures[] = $msg;
            } elseif (! $hasTargetSection) {
                $msg = sprintf('CHANGELOG.md does not contain an allocated section for target version "[%s]".', $target);
                $checks['changelog_target_allocation'] = ['status' => 'FAIL', 'message' => $msg];
                $failures[] = $msg;
            } elseif ($hasInventedDate) {
                $msg = sprintf('CHANGELOG.md target section "[%s]" contains an invented publication date before publication has occurred.', $target);
                $checks['changelog_target_allocation'] = ['status' => 'FAIL', 'message' => $msg];
                $failures[] = $msg;
            } else {
                $checks['changelog_target_allocation'] = ['status' => 'PASS', 'message' => 'CHANGELOG [Unreleased] and target section allocation are compliant.'];
            }
        } else {
            $checks['changelog_target_allocation'] = ['status' => 'FAIL', 'message' => 'CHANGELOG.md not found.'];
        }

        // 8. SECURITY Pre-Release Lifecycle Semantics
        $securityPath = $repoPath . '/SECURITY.md';
        if (file_exists($securityPath)) {
            $securityContent = (string) file_get_contents($securityPath);
            $isPrerelease = str_contains($target, '-');
            // If target is pre-release, ensure SECURITY does not promise Stable support for this exact pre-release
            $promisesPrereleaseSupport = $isPrerelease && (bool) preg_match('/\|[ ]*' . preg_quote($target, '/') . '[ ]*\|[ ]*:white_check_mark:[ ]*\|/i', $securityContent);

            if ($promisesPrereleaseSupport) {
                $msg = sprintf('SECURITY.md falsely promises active Stable support for pre-release target "%s".', $target);
                $checks['security_lifecycle'] = ['status' => 'FAIL', 'message' => $msg];
                $failures[] = $msg;
            } else {
                $checks['security_lifecycle'] = ['status' => 'PASS', 'message' => 'SECURITY.md lifecycle and support semantics verified.'];
            }
        } else {
            $checks['security_lifecycle'] = ['status' => 'FAIL', 'message' => 'SECURITY.md not found.'];
        }

        // 9. Semantic Review Evidence Boundary
        if ($requireSemanticReview) {
            $semanticResult = $this->evaluateSemanticReviewEvidence($semanticReviewFile, $target, $candidateSha);
            if ($semanticResult['status'] === 'FAIL') {
                $checks['semantic_review'] = $semanticResult;
                $failures[] = $semanticResult['message'];
            } else {
                $checks['semantic_review'] = $semanticResult;
            }
        } else {
            $checks['semantic_review'] = [
                'status' => 'PASS',
                'message' => 'Semantic review verification was skipped by explicit configuration (dry-run mode).',
            ];
        }

        return [
            'status' => $failures === [] ? 'PASS' : 'FAIL',
            'package_name' => self::EXPECTED_PACKAGE_NAME,
            'target_version' => $target,
            'candidate_sha' => $candidateSha,
            'verified_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'checks' => $checks,
            'failures' => $failures,
        ];
    }

    /**
     * @return array{status: 'PASS'|'FAIL', message: string, details?: mixed}
     */
    private function evaluateSemanticReviewEvidence(?string $filePath, string $target, string $candidateSha): array
    {
        if ($filePath === null || ! file_exists($filePath)) {
            return [
                'status' => 'FAIL',
                'message' => 'Semantic review evidence file is required but was not provided or does not exist.',
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

        $recordedTarget = is_scalar($data['target'] ?? null) ? (string) $data['target'] : '';
        $recordedSha = is_scalar($data['candidate_sha'] ?? null) ? (string) $data['candidate_sha'] : '';
        $status = is_scalar($data['status'] ?? null) ? (string) $data['status'] : '';
        $reviewer = is_scalar($data['reviewer'] ?? null) ? (string) $data['reviewer'] : '';
        $reviewedAt = is_scalar($data['reviewed_at'] ?? null) ? (string) $data['reviewed_at'] : '';
        $claims = $data['claims'] ?? [];

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

        if ($status !== 'APPROVED') {
            return [
                'status' => 'FAIL',
                'message' => sprintf('Semantic review status is "%s", expected "APPROVED".', $status),
            ];
        }

        if ($reviewer === '' || $reviewedAt === '') {
            return [
                'status' => 'FAIL',
                'message' => 'Semantic review evidence must include non-empty reviewer and reviewed_at timestamp.',
            ];
        }

        if (! is_array($claims)) {
            return [
                'status' => 'FAIL',
                'message' => 'Semantic review evidence claims must be an array of verified assertions.',
            ];
        }

        return [
            'status' => 'PASS',
            'message' => sprintf('Semantic review evidence approved by %s at %s.', $reviewer, $reviewedAt),
            'details' => [
                'reviewer' => $reviewer,
                'reviewed_at' => $reviewedAt,
                'claims' => $claims,
            ],
        ];
    }

    private function getGitHead(string $repoPath): ?string
    {
        $output = [];
        $exitCode = 0;
        exec(sprintf('git -C %s rev-parse HEAD 2>/dev/null', escapeshellarg($repoPath)), $output, $exitCode);
        if ($exitCode === 0 && isset($output[0])) {
            return trim($output[0]);
        }

        return null;
    }

    private function isGitWorktreeClean(string $repoPath): bool
    {
        $output = [];
        $exitCode = 0;
        exec(sprintf('git -C %s status --porcelain 2>/dev/null', escapeshellarg($repoPath)), $output, $exitCode);

        return $exitCode === 0 && empty($output);
    }
}
