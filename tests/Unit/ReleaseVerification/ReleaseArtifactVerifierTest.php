<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\ReleaseVerification;

use Maatify\RateLimiter\Tests\Support\ReleaseVerification\ReleaseArtifactVerifier;
use PHPUnit\Framework\TestCase;

final class ReleaseArtifactVerifierTest extends TestCase
{
    private string $tempDir;
    private string $realRepoPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/rav-test-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);
        $this->realRepoPath = (string) realpath(__DIR__ . '/../../..');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    public function testFailsQualifyingRavOnNonGitRepository(): void
    {
        $this->populateValidFixtureTree($this->tempDir, '1.0.0-rc.3');
        $semanticFile = $this->createValidSemanticReviewFile($this->tempDir, '1.0.0-rc.3', str_repeat('a', 40));

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->verify([
            'target' => '1.0.0-rc.3',
            'candidate_sha' => str_repeat('a', 40),
            'repo_path' => $this->tempDir, // not a git repo
            'semantic_review_file' => $semanticFile,
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['candidate_sha_and_git']['status']);
        self::assertStringContainsString('not inside a valid Git work tree', $result['checks']['candidate_sha_and_git']['message']);
    }

    public function testFailsOnMalformedTargetVersionSyntax(): void
    {
        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->evaluateTargetVersionSyntax('not-a-semver');

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('not a valid Semantic Version', $result['message']);
    }

    public function testPassesOnValidTargetVersionSyntax(): void
    {
        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->evaluateTargetVersionSyntax('1.0.0-rc.3');

        self::assertSame('PASS', $result['status']);
    }

    public function testFailsOnMalformedCandidateSha(): void
    {
        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->evaluateCandidateShaAndGit($this->realRepoPath, 'short-sha');

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('40-character hexadecimal commit hash', $result['message']);
    }

    public function testFailsWhenCandidateShaMismatchesRepositoryHead(): void
    {
        $wrongSha = str_repeat('f', 40);

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->evaluateCandidateShaAndGit($this->realRepoPath, $wrongSha);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('does not exist as a commit object', $result['message']);
    }

    public function testFailsWhenCandidateCommitExistsButNotCheckedOutHead(): void
    {
        // Obtain parent commit SHA
        $parentOut = [];
        exec(sprintf('git -C %s rev-parse HEAD~1 2>/dev/null', escapeshellarg($this->realRepoPath)), $parentOut);
        $parentSha = trim($parentOut[0] ?? '');
        if ($parentSha === '') {
            self::markTestSkipped('No parent commit available for testing HEAD mismatch.');
        }

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->evaluateCandidateShaAndGit($this->realRepoPath, $parentSha);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('does not match candidate SHA', $result['message']);
    }

    public function testFailsWhenPackageManifestHasWrongLicense(): void
    {
        $this->populateValidFixtureTree($this->tempDir, '1.0.0-rc.3');
        $manifest = [
            'name' => 'maatify/php-rate-limiter',
            'license' => 'MIT', // Expected proprietary
        ];
        file_put_contents($this->tempDir . '/composer.json', json_encode($manifest, JSON_PRETTY_PRINT));

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->evaluatePackageManifest($this->tempDir);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('does not match expected package license "proprietary"', $result['message']);
    }

    public function testFailsWhenPackageManifestHasStaticVersion(): void
    {
        $this->populateValidFixtureTree($this->tempDir, '1.0.0-rc.3');
        $manifest = [
            'name' => 'maatify/php-rate-limiter',
            'license' => 'proprietary',
            'version' => '1.0.0-rc.3', // Forbidden static version
        ];
        file_put_contents($this->tempDir . '/composer.json', json_encode($manifest, JSON_PRETTY_PRINT));

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->evaluatePackageManifest($this->tempDir);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('MUST NOT declare a static "version" field', $result['message']);
    }

    public function testFailsWhenRequiredReleaseFacingFileIsMissing(): void
    {
        $this->populateValidFixtureTree($this->tempDir, '1.0.0-rc.3');
        unlink($this->tempDir . '/llms.txt');

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->evaluateRequiredFiles($this->tempDir);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('Missing required consumer/release-facing files: llms.txt', $result['message']);
    }

    public function testFailsWhenForbiddenDistributionArtifactPresent(): void
    {
        $this->populateValidFixtureTree($this->tempDir, '1.0.0-rc.3');
        file_put_contents($this->tempDir . '/.env', 'SECRET=123');

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->evaluateDistributionSafety($this->tempDir);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('Distribution safety violation: .env', $result['message']);
    }

    public function testFailsWhenRequiredFileExportIgnoredInGitAttributes(): void
    {
        $this->populateValidFixtureTree($this->tempDir, '1.0.0-rc.3');
        file_put_contents($this->tempDir . '/.gitattributes', "README.md export-ignore\n");

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->evaluateDistributionSafety($this->tempDir);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('Required file "README.md" is excluded by .gitattributes export-ignore', $result['message']);
    }

    public function testFailsWhenReadmeMismatchesTargetVersion(): void
    {
        $this->populateValidFixtureTree($this->tempDir, '1.0.0-rc.3');
        file_put_contents($this->tempDir . '/README.md', '# Unrelated Content');

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->evaluateReadmeIdentity($this->tempDir, '1.0.0-rc.3');

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('README.md does not reference exact target release identity', $result['message']);
    }

    public function testFailsWhenReadmeMakesFalsePublishedClaim(): void
    {
        $this->populateValidFixtureTree($this->tempDir, '1.0.0-rc.3');
        file_put_contents($this->tempDir . '/README.md', "# php-rate-limiter 1.0.0-rc.3\n# Published on Packagist 1.0.0-rc.3\n");

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->evaluateReadmeIdentity($this->tempDir, '1.0.0-rc.3');

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('unqualified pre-publication availability claim', $result['message']);
    }

    public function testFailsWhenChangelogTargetSectionIsDated(): void
    {
        $this->populateValidFixtureTree($this->tempDir, '1.0.0-rc.3');
        $changelog = "## [Unreleased]\n\n## [1.0.0-rc.3] - 2026-10-10\n- Changes\n";
        file_put_contents($this->tempDir . '/CHANGELOG.md', $changelog);

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->evaluateChangelogAllocation($this->tempDir, '1.0.0-rc.3', false);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('contains a publication date prior to publication', $result['message']);
    }

    public function testFailsWhenChangelogTargetSectionHasUpcomingOrUnreleasedSuffix(): void
    {
        $this->populateValidFixtureTree($this->tempDir, '1.0.0-rc.3');

        $verifier = new ReleaseArtifactVerifier();

        file_put_contents($this->tempDir . '/CHANGELOG.md', "## [Unreleased]\n\n## [1.0.0-rc.3] - upcoming\n");
        $resultUpcoming = $verifier->evaluateChangelogAllocation($this->tempDir, '1.0.0-rc.3', false);
        self::assertSame('FAIL', $resultUpcoming['status']);
        self::assertStringContainsString('must be an exact undated heading without status suffix', $resultUpcoming['message']);

        file_put_contents($this->tempDir . '/CHANGELOG.md', "## [Unreleased]\n\n## [1.0.0-rc.3] - unreleased\n");
        $resultUnreleased = $verifier->evaluateChangelogAllocation($this->tempDir, '1.0.0-rc.3', false);
        self::assertSame('FAIL', $resultUnreleased['status']);
        self::assertStringContainsString('must be an exact undated heading without status suffix', $resultUnreleased['message']);
    }

    public function testPassesWhenChangelogHasExactUndatedTargetHeading(): void
    {
        $this->populateValidFixtureTree($this->tempDir, '1.0.0-rc.3');
        $changelog = "## [Unreleased]\n\n## [1.0.0-rc.3]\n- Allocated changes\n";
        file_put_contents($this->tempDir . '/CHANGELOG.md', $changelog);

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->evaluateChangelogAllocation($this->tempDir, '1.0.0-rc.3', false);

        self::assertSame('PASS', $result['status']);
    }

    public function testPassesWhenChangelogOmitsUnreleasedHeadingIfSemanticallyConfirmedNoUnallocatedChanges(): void
    {
        $this->populateValidFixtureTree($this->tempDir, '1.0.0-rc.3');
        // No ## [Unreleased] heading
        $changelog = "## [1.0.0-rc.3]\n- All changes allocated\n";
        file_put_contents($this->tempDir . '/CHANGELOG.md', $changelog);

        $verifier = new ReleaseArtifactVerifier();

        // When no unallocated represented changes are confirmed: PASS
        $resultConfirmed = $verifier->evaluateChangelogAllocation($this->tempDir, '1.0.0-rc.3', true);
        self::assertSame('PASS', $resultConfirmed['status']);

        // When unallocated represented changes are NOT confirmed: FAIL
        $resultUnconfirmed = $verifier->evaluateChangelogAllocation($this->tempDir, '1.0.0-rc.3', false);
        self::assertSame('FAIL', $resultUnconfirmed['status']);
        self::assertStringContainsString('missing "## [Unreleased]" boundary heading', $resultUnconfirmed['message']);
    }

    public function testFailsWhenSecurityFalselyPromisesStableSupportForPrerelease(): void
    {
        $this->populateValidFixtureTree($this->tempDir, '1.0.0-rc.3');
        $security = "| 1.0.0-rc.3 | :white_check_mark: |\n";
        file_put_contents($this->tempDir . '/SECURITY.md', $security);

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->evaluateSecurityLifecycle($this->tempDir, '1.0.0-rc.3');

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('falsely promises active Stable support for pre-release target', $result['message']);
    }

    public function testFailsWhenSemanticReviewRecordIsMissing(): void
    {
        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->evaluateSemanticReviewEvidence(null, '1.0.0-rc.3', str_repeat('a', 40));

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('Canonical semantic review evidence record is required', $result['message']);
    }

    public function testFailsWhenSemanticReviewTargetMismatches(): void
    {
        $sha = str_repeat('a', 40);
        $file = $this->createValidSemanticReviewFile($this->tempDir, '1.0.0-rc.2', $sha);

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->evaluateSemanticReviewEvidence($file, '1.0.0-rc.3', $sha);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('does not match verification target', $result['message']);
    }

    public function testFailsWhenSemanticReviewShaMismatches(): void
    {
        $sha1 = str_repeat('a', 40);
        $sha2 = str_repeat('b', 40);
        $file = $this->createValidSemanticReviewFile($this->tempDir, '1.0.0-rc.3', $sha1);

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->evaluateSemanticReviewEvidence($file, '1.0.0-rc.3', $sha2);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('does not match candidate SHA', $result['message']);
    }

    public function testFailsWhenSemanticReviewDispositionNotApproved(): void
    {
        $sha = str_repeat('a', 40);
        $data = $this->getValidSemanticReviewData('1.0.0-rc.3', $sha);
        $data['disposition'] = 'REJECTED';
        $file = $this->tempDir . '/semantic.json';
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->evaluateSemanticReviewEvidence($file, '1.0.0-rc.3', $sha);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('disposition is "REJECTED", expected "APPROVED"', $result['message']);
    }

    public function testFailsWhenSemanticReviewMissingOneRequiredClaim(): void
    {
        $sha = str_repeat('a', 40);
        $data = $this->getValidSemanticReviewData('1.0.0-rc.3', $sha);
        unset($data['claims']['security.lifecycle_support_semantics']);
        $file = $this->tempDir . '/semantic.json';
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->evaluateSemanticReviewEvidence($file, '1.0.0-rc.3', $sha);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('missing required claim(s): security.lifecycle_support_semantics', $result['message']);
    }

    public function testFailsWhenSemanticReviewHasUnconfirmedClaim(): void
    {
        $sha = str_repeat('a', 40);
        $data = $this->getValidSemanticReviewData('1.0.0-rc.3', $sha);
        $data['claims']['readme.pre_publication_truth'] = 'PENDING';
        $file = $this->tempDir . '/semantic.json';
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->evaluateSemanticReviewEvidence($file, '1.0.0-rc.3', $sha);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('unconfirmed required claim(s): readme.pre_publication_truth', $result['message']);
    }

    public function testPassesWhenSemanticReviewHasAllClaimsConfirmed(): void
    {
        $sha = str_repeat('a', 40);
        $file = $this->createValidSemanticReviewFile($this->tempDir, '1.0.0-rc.3', $sha);

        $verifier = new ReleaseArtifactVerifier();
        $result = $verifier->evaluateSemanticReviewEvidence($file, '1.0.0-rc.3', $sha);

        self::assertSame('PASS', $result['status']);
        self::assertArrayHasKey('details', $result);
        $details = $result['details'] ?? [];
        self::assertArrayHasKey('claims', $details);
        self::assertIsArray($details['claims']);
        self::assertCount(8, $details['claims']);
    }

    public function testBuildContentManifestProducesDeterministicSha256Hashes(): void
    {
        $this->populateValidFixtureTree($this->tempDir, '1.0.0-rc.3');

        $verifier = new ReleaseArtifactVerifier();
        $manifest1 = $verifier->buildContentManifest($this->tempDir);
        $manifest2 = $verifier->buildContentManifest($this->tempDir);

        self::assertSame($manifest1, $manifest2);
        self::assertArrayHasKey('composer.json', $manifest1);
        self::assertArrayHasKey('README.md', $manifest1);
        self::assertArrayHasKey('src', $manifest1);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $manifest1['composer.json']);
    }

    private function populateValidFixtureTree(string $dir, string $version): void
    {
        mkdir($dir . '/src', 0777, true);
        mkdir($dir . '/docs/guides', 0777, true);
        mkdir($dir . '/examples', 0777, true);

        file_put_contents($dir . '/composer.json', json_encode([
            'name' => 'maatify/php-rate-limiter',
            'license' => 'proprietary',
        ], JSON_PRETTY_PRINT));
        file_put_contents($dir . '/README.md', "# php-rate-limiter\nVersion: " . $version . "\ncomposer require maatify/php-rate-limiter:" . $version . "\n");
        file_put_contents($dir . '/LICENSE', 'Maatify Proprietary License');
        file_put_contents($dir . '/CHANGELOG.md', "## [Unreleased]\n\n## [" . $version . "]\n- Initial feature set\n");
        file_put_contents($dir . '/SECURITY.md', "## Supported Versions\n| " . $version . " | No (pre-release candidate) |\n");
        file_put_contents($dir . '/RATE_LIMITER_PACKAGE_REFERENCE.md', '# Package Reference');
        file_put_contents($dir . '/docs/guides/USAGE_GUIDE.md', '# Usage Guide');
        file_put_contents($dir . '/llms.txt', '# LLM Reference');
        file_put_contents($dir . '/src/RateLimiter.php', "<?php\n");
        file_put_contents($dir . '/examples/basic.php', "<?php\n");
    }

    /**
     * @return array{
     *     schema_version: string,
     *     target: string,
     *     candidate_sha: string,
     *     reviewer: string,
     *     reviewed_at: string,
     *     disposition: string,
     *     claims: array<string, string>,
     *     notes: string
     * }
     */
    private function getValidSemanticReviewData(string $target, string $sha): array
    {
        return [
            'schema_version' => '1.0.0',
            'target' => $target,
            'candidate_sha' => $sha,
            'reviewer' => 'Lead Reviewer <lead@maatify.dev>',
            'reviewed_at' => '2026-10-06T20:00:00Z',
            'disposition' => 'APPROVED',
            'claims' => [
                'readme.release_artifact_identity' => 'CONFIRMED',
                'readme.exact_install_target' => 'CONFIRMED',
                'readme.pre_publication_truth' => 'CONFIRMED',
                'changelog.target_allocation' => 'CONFIRMED',
                'changelog.undated_target_preparation' => 'CONFIRMED',
                'changelog.no_unallocated_represented_changes' => 'CONFIRMED',
                'security.lifecycle_support_semantics' => 'CONFIRMED',
                'package_reference.consumer_identity_consistency' => 'CONFIRMED',
            ],
            'notes' => 'Test semantic review notes.',
        ];
    }

    private function createValidSemanticReviewFile(string $dir, string $target, string $sha): string
    {
        $path = $dir . '/semantic-review.json';
        file_put_contents($path, json_encode($this->getValidSemanticReviewData($target, $sha), JSON_PRETTY_PRINT));

        return $path;
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
