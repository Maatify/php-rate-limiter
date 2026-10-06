<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\ReleaseVerification;

use Maatify\RateLimiter\Tests\Support\ReleaseVerification\ReleaseArtifactVerifier;
use PHPUnit\Framework\TestCase;

final class ReleaseArtifactVerifierTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/rav-test-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);
        $this->populateValidFixtureTree($this->tempDir, '1.0.0-rc.3');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    public function testFailsOnMalformedTargetVersionSyntax(): void
    {
        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->verify([
            'target' => 'not-a-semver',
            'candidate_sha' => str_repeat('a', 40),
            'repo_path' => $this->tempDir,
            'require_clean_git' => false,
            'require_semantic_review' => false,
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['target_version_syntax']['status']);
        self::assertStringContainsString('not a valid Semantic Version', $result['checks']['target_version_syntax']['message']);
    }

    public function testFailsOnMalformedCandidateSha(): void
    {
        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->verify([
            'target' => '1.0.0-rc.3',
            'candidate_sha' => 'short-sha',
            'repo_path' => $this->tempDir,
            'require_clean_git' => false,
            'require_semantic_review' => false,
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['candidate_sha_identity']['status']);
        self::assertStringContainsString('40-character hexadecimal commit hash', $result['checks']['candidate_sha_identity']['message']);
    }

    public function testFailsWhenCandidateShaMismatchesRepositoryHead(): void
    {
        // Run against actual repo root where HEAD is bb185f3 or d9138bd
        $realRepo = (string) realpath(__DIR__ . '/../../..');
        $wrongSha = str_repeat('f', 40);

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->verify([
            'target' => '1.0.0-rc.3',
            'candidate_sha' => $wrongSha,
            'repo_path' => $realRepo,
            'require_clean_git' => false,
            'require_semantic_review' => false,
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['candidate_sha_identity']['status']);
        self::assertStringContainsString('does not match candidate SHA', $result['checks']['candidate_sha_identity']['message']);
    }

    public function testFailsWhenRequiredReleaseFacingFileIsMissing(): void
    {
        unlink($this->tempDir . '/llms.txt');

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->verify([
            'target' => '1.0.0-rc.3',
            'candidate_sha' => str_repeat('a', 40),
            'repo_path' => $this->tempDir,
            'require_clean_git' => false,
            'require_semantic_review' => false,
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['required_files']['status']);
        self::assertStringContainsString('Missing required consumer/release-facing files: llms.txt', $result['checks']['required_files']['message']);
    }

    public function testFailsWhenPackageIdentityMismatches(): void
    {
        file_put_contents($this->tempDir . '/composer.json', json_encode(['name' => 'wrong/package'], JSON_PRETTY_PRINT));

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->verify([
            'target' => '1.0.0-rc.3',
            'candidate_sha' => str_repeat('a', 40),
            'repo_path' => $this->tempDir,
            'require_clean_git' => false,
            'require_semantic_review' => false,
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['package_identity_and_manifest']['status']);
        self::assertStringContainsString('does not match expected package identity', $result['checks']['package_identity_and_manifest']['message']);
    }

    public function testFailsWhenTargetSectionMissingFromChangelog(): void
    {
        file_put_contents($this->tempDir . '/CHANGELOG.md', "# Changelog\n\n## [Unreleased]\n- Some feature\n");

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->verify([
            'target' => '1.0.0-rc.3',
            'candidate_sha' => str_repeat('a', 40),
            'repo_path' => $this->tempDir,
            'require_clean_git' => false,
            'require_semantic_review' => false,
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['changelog_target_allocation']['status']);
        self::assertStringContainsString('does not contain an allocated section for target version', $result['checks']['changelog_target_allocation']['message']);
    }

    public function testFailsWhenChangelogContainsInventedPublicationDatePrePublication(): void
    {
        file_put_contents($this->tempDir . '/CHANGELOG.md', "# Changelog\n\n## [Unreleased]\n\n## [1.0.0-rc.3] - 2026-10-10\n- Pre-dated release\n");

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->verify([
            'target' => '1.0.0-rc.3',
            'candidate_sha' => str_repeat('a', 40),
            'repo_path' => $this->tempDir,
            'require_clean_git' => false,
            'require_semantic_review' => false,
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['changelog_target_allocation']['status']);
        self::assertStringContainsString('contains an invented publication date before publication', $result['checks']['changelog_target_allocation']['message']);
    }

    public function testFailsWhenReadmeHasStalePreviousReleaseArtifactIdentity(): void
    {
        file_put_contents($this->tempDir . '/README.md', "# Rate Limiter\n\ncomposer require maatify/php-rate-limiter:1.0.0-rc.2\n");

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->verify([
            'target' => '1.0.0-rc.3',
            'candidate_sha' => str_repeat('a', 40),
            'repo_path' => $this->tempDir,
            'require_clean_git' => false,
            'require_semantic_review' => false,
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['readme_artifact_identity']['status']);
        self::assertStringContainsString('README.md does not reference exact target release identity', $result['checks']['readme_artifact_identity']['message']);
    }

    public function testFailsWhenSecurityPromisesStableSupportForPrerelease(): void
    {
        file_put_contents($this->tempDir . '/SECURITY.md', "# Security Policy\n\n| Version | Supported          |\n| ------- | ------------------ |\n| 1.0.0-rc.3 | :white_check_mark: |\n");

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->verify([
            'target' => '1.0.0-rc.3',
            'candidate_sha' => str_repeat('a', 40),
            'repo_path' => $this->tempDir,
            'require_clean_git' => false,
            'require_semantic_review' => false,
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['security_lifecycle']['status']);
        self::assertStringContainsString('falsely promises active Stable support for pre-release target', $result['checks']['security_lifecycle']['message']);
    }

    public function testFailsWhenSemanticReviewEvidenceMissingWhenRequired(): void
    {
        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->verify([
            'target' => '1.0.0-rc.3',
            'candidate_sha' => str_repeat('a', 40),
            'repo_path' => $this->tempDir,
            'require_clean_git' => false,
            'require_semantic_review' => true,
            'semantic_review_file' => null,
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['semantic_review']['status']);
        self::assertStringContainsString('Semantic review evidence file is required but was not provided', $result['checks']['semantic_review']['message']);
    }

    public function testFailsWhenSemanticReviewEvidenceMismatchesShaOrTarget(): void
    {
        $evidenceFile = $this->tempDir . '/semantic-review.json';
        file_put_contents($evidenceFile, json_encode([
            'target' => '1.0.0-rc.3',
            'candidate_sha' => str_repeat('b', 40),
            'status' => 'APPROVED',
            'reviewer' => 'Lead Reviewer',
            'reviewed_at' => '2026-10-06T20:00:00Z',
            'claims' => ['readme_release_artifact_identity' => 'CONFIRMED'],
        ], JSON_PRETTY_PRINT));

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->verify([
            'target' => '1.0.0-rc.3',
            'candidate_sha' => str_repeat('a', 40),
            'repo_path' => $this->tempDir,
            'require_clean_git' => false,
            'require_semantic_review' => true,
            'semantic_review_file' => $evidenceFile,
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['semantic_review']['status']);
        self::assertStringContainsString('does not match candidate SHA', $result['checks']['semantic_review']['message']);
    }

    public function testPassesOnValidFixtureTreeAndSemanticReview(): void
    {
        $evidenceFile = $this->tempDir . '/semantic-review.json';
        file_put_contents($evidenceFile, json_encode([
            'target' => '1.0.0-rc.3',
            'candidate_sha' => str_repeat('a', 40),
            'status' => 'APPROVED',
            'reviewer' => 'Lead Reviewer',
            'reviewed_at' => '2026-10-06T20:00:00Z',
            'claims' => [
                'readme_release_artifact_identity' => 'CONFIRMED',
                'readme_pre_publication_truth' => 'CONFIRMED',
                'changelog_target_allocation' => 'CONFIRMED',
                'security_lifecycle_semantics' => 'CONFIRMED',
            ],
        ], JSON_PRETTY_PRINT));

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->verify([
            'target' => '1.0.0-rc.3',
            'candidate_sha' => str_repeat('a', 40),
            'repo_path' => $this->tempDir,
            'require_clean_git' => false,
            'require_semantic_review' => true,
            'semantic_review_file' => $evidenceFile,
        ]);

        self::assertSame('PASS', $result['status']);
        self::assertSame([], $result['failures']);
        self::assertSame('PASS', $result['checks']['target_version_syntax']['status']);
        self::assertSame('PASS', $result['checks']['required_files']['status']);
        self::assertSame('PASS', $result['checks']['distribution_safety']['status']);
        self::assertSame('PASS', $result['checks']['readme_artifact_identity']['status']);
        self::assertSame('PASS', $result['checks']['changelog_target_allocation']['status']);
        self::assertSame('PASS', $result['checks']['security_lifecycle']['status']);
        self::assertSame('PASS', $result['checks']['semantic_review']['status']);
    }

    private function populateValidFixtureTree(string $dir, string $target): void
    {
        mkdir($dir . '/src', 0777, true);
        file_put_contents($dir . '/src/RateLimiter.php', "<?php\n");
        mkdir($dir . '/examples', 0777, true);
        file_put_contents($dir . '/examples/basic.php', "<?php\n");
        mkdir($dir . '/docs/guides', 0777, true);
        file_put_contents($dir . '/docs/guides/USAGE_GUIDE.md', "# Usage Guide\n");

        file_put_contents($dir . '/composer.json', json_encode([
            'name' => 'maatify/php-rate-limiter',
            'type' => 'library',
        ], JSON_PRETTY_PRINT));

        file_put_contents($dir . '/README.md', "# Rate Limiter\n\ncomposer require maatify/php-rate-limiter:{$target}\n");
        file_put_contents($dir . '/LICENSE', "MIT License\n");
        file_put_contents($dir . '/CHANGELOG.md', "# Changelog\n\n## [Unreleased]\n\n## [{$target}]\n- Candidate release preparation\n");
        file_put_contents($dir . '/SECURITY.md', "# Security Policy\n\nOnly tagged Stable releases receive official security support.\n");
        file_put_contents($dir . '/RATE_LIMITER_PACKAGE_REFERENCE.md', "# Package Reference\n");
        file_put_contents($dir . '/llms.txt', "# LLMs reference\n");
    }

    private function removeDir(string $dir): void
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
            $p = $dir . '/' . $item;
            if (is_dir($p)) {
                $this->removeDir($p);
            } else {
                unlink($p);
            }
        }
        rmdir($dir);
    }
}
