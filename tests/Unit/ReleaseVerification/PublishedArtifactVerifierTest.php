<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\ReleaseVerification;

use Maatify\RateLimiter\Tests\Support\ReleaseVerification\PublishedArtifactVerifier;
use PHPUnit\Framework\TestCase;

final class PublishedArtifactVerifierTest extends TestCase
{
    private string $tempDir;
    private string $installedPath;
    private string $installedJsonPath;
    private string $qualifiedSha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/pav-test-' . bin2hex(random_bytes(6));
        $this->installedPath = $this->tempDir . '/vendor/maatify/php-rate-limiter';
        $this->installedJsonPath = $this->tempDir . '/vendor/composer/installed.json';
        $this->qualifiedSha = '35276355e35a61c2320305800a4551496e883c18';

        mkdir($this->tempDir . '/vendor/composer', 0777, true);
        mkdir($this->installedPath . '/src', 0777, true);
        mkdir($this->installedPath . '/docs/guides', 0777, true);
        mkdir($this->installedPath . '/examples', 0777, true);

        $this->populateValidInstalledArtifact($this->installedPath, '1.0.0-rc.3');
        $this->populateValidInstalledJson($this->installedJsonPath, '1.0.0-rc.3', $this->qualifiedSha, 'dist');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    public function testPreinstalledFixtureInspectionAlwaysReturnsInspectionOnlyNeverPass(): void
    {
        $verifier = new PublishedArtifactVerifier();
        $result = $verifier->inspectPreinstalledFixture([
            'target' => '1.0.0-rc.3',
            'qualified_sha' => $this->qualifiedSha,
            'installed_path' => $this->installedPath,
            'installed_json_path' => $this->installedJsonPath,
        ]);

        // MUST be INSPECTION_ONLY, NEVER PASS
        self::assertSame('INSPECTION_ONLY', $result['status']);
        self::assertSame('dist', $result['installation_mode']);
    }

    public function testQualifyingPavFailsWhenRavQualificationEvidenceIsMissing(): void
    {
        $verifier = new PublishedArtifactVerifier();
        $result = $verifier->verify([
            'target' => '1.0.0-rc.3',
            'qualified_sha' => $this->qualifiedSha,
            // No qualification_evidence_file provided
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['qualification_evidence']['status']);
        self::assertStringContainsString('requires authoritative RAV qualification evidence', $result['checks']['qualification_evidence']['message']);
    }

    public function testQualifyingPavFailsWhenQualificationEvidenceHasFailedStatus(): void
    {
        $evidence = $this->createValidQualificationEvidence('1.0.0-rc.3', $this->qualifiedSha);
        $evidence['status'] = 'FAIL';

        $verifier = new PublishedArtifactVerifier();
        $eval = $verifier->loadAndValidateQualificationEvidence(null, $evidence, '1.0.0-rc.3', $this->qualifiedSha, 'maatify/php-rate-limiter');

        self::assertSame('FAIL', $eval['status']);
        self::assertStringContainsString('status is "FAIL", expected "PASS"', $eval['message']);
    }

    public function testQualifyingPavFailsWhenTargetMismatchesQualificationEvidence(): void
    {
        $evidence = $this->createValidQualificationEvidence('1.0.0-rc.3', $this->qualifiedSha);

        $verifier = new PublishedArtifactVerifier();
        $eval = $verifier->loadAndValidateQualificationEvidence(null, $evidence, '1.0.0-rc.2', $this->qualifiedSha, 'maatify/php-rate-limiter');

        self::assertSame('FAIL', $eval['status']);
        self::assertStringContainsString('does not match qualification evidence target', $eval['message']);
    }

    public function testQualifyingPavFailsWhenShaMismatchesQualificationEvidence(): void
    {
        $evidence = $this->createValidQualificationEvidence('1.0.0-rc.3', $this->qualifiedSha);
        $otherSha = str_repeat('c', 40);

        $verifier = new PublishedArtifactVerifier();
        $eval = $verifier->loadAndValidateQualificationEvidence(null, $evidence, '1.0.0-rc.3', $otherSha, 'maatify/php-rate-limiter');

        self::assertSame('FAIL', $eval['status']);
        self::assertStringContainsString('does not match qualification evidence candidate SHA', $eval['message']);
    }

    public function testFailsWhenInstallationSourceIsUnknownOrUnprovable(): void
    {
        $pkg = [
            'name' => 'maatify/php-rate-limiter',
            'version' => '1.0.0-rc.3',
            // Missing 'installation-source'
            'dist' => [
                'type' => 'zip',
                'url' => 'https://api.github.com/repos/Maatify/php-rate-limiter/zipball/' . $this->qualifiedSha,
                'reference' => $this->qualifiedSha,
            ],
        ];

        $verifier = new PublishedArtifactVerifier();
        $eval = $verifier->evaluateInstalledMetadata($pkg, $this->qualifiedSha, 'dist', null, null);

        self::assertSame('FAIL', $eval['checks']['installation_mode']['status']);
        self::assertStringContainsString('Unprovable installation mode', $eval['checks']['installation_mode']['message']);
    }

    public function testFailsWhenDistExposedButObservedInstallationModeIsSource(): void
    {
        $pkg = [
            'name' => 'maatify/php-rate-limiter',
            'version' => '1.0.0-rc.3',
            'installation-source' => 'source', // silent fallback / preference failure
            'dist' => [
                'type' => 'zip',
                'url' => 'https://api.github.com/repos/Maatify/php-rate-limiter/zipball/' . $this->qualifiedSha,
                'reference' => $this->qualifiedSha,
            ],
            'source' => [
                'type' => 'git',
                'url' => 'https://github.com/Maatify/php-rate-limiter.git',
                'reference' => $this->qualifiedSha,
            ],
        ];

        $verifier = new PublishedArtifactVerifier();
        $eval = $verifier->evaluateInstalledMetadata($pkg, $this->qualifiedSha, 'dist', null, null);

        self::assertSame('FAIL', $eval['checks']['installation_mode']['status']);
        self::assertStringContainsString('Dist archive was exposed by Composer repository metadata, but actual installation mode was source', $eval['checks']['installation_mode']['message']);
    }

    public function testFailsWhenSourceModeObservedWithoutAuthorizingQualificationEvidence(): void
    {
        $pkg = [
            'name' => 'maatify/php-rate-limiter',
            'version' => '1.0.0-rc.3',
            'installation-source' => 'source',
            'source' => [
                'type' => 'git',
                'url' => 'https://github.com/Maatify/php-rate-limiter.git',
                'reference' => $this->qualifiedSha,
            ],
        ];

        $verifier = new PublishedArtifactVerifier();
        // delivery_policy was 'dist' in qualification evidence, but channel delivered source
        $eval = $verifier->evaluateInstalledMetadata($pkg, $this->qualifiedSha, 'dist', null, null);

        self::assertSame('FAIL', $eval['checks']['installation_mode']['status']);
        self::assertStringContainsString('qualification evidence did not authorize source-only delivery policy', $eval['checks']['installation_mode']['message']);
    }

    public function testFailsWhenSourceModeHasNoQualificationDecisionEvidence(): void
    {
        $pkg = [
            'name' => 'maatify/php-rate-limiter',
            'version' => '1.0.0-rc.3',
            'installation-source' => 'source',
            'source' => [
                'type' => 'git',
                'url' => 'https://github.com/Maatify/php-rate-limiter.git',
                'reference' => $this->qualifiedSha,
            ],
        ];

        $verifier = new PublishedArtifactVerifier();
        $eval = $verifier->evaluateInstalledMetadata($pkg, $this->qualifiedSha, 'source-only', null, null);

        self::assertSame('FAIL', $eval['checks']['source_only_decision']['status']);
        self::assertStringContainsString('without qualification-time source-only Decision evidence', $eval['checks']['source_only_decision']['message']);
    }

    public function testFailsWhenDecisionWasNotActiveAtQualificationTime(): void
    {
        $evidence = [
            'decision_id' => 'DEC-099',
            'decision_file' => 'docs/decisions/DEC-099.md',
            'status_at_qualification' => 'PROPOSED', // not ACTIVE
        ];

        $verifier = new PublishedArtifactVerifier();
        $result = $verifier->evaluateSourceOnlyHistoricalChain($evidence, null);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('Decision was not ACTIVE at qualification time', $result['message']);
    }

    public function testFailsWhenDecisionMissingFromIndexAtPavTime(): void
    {
        $repoDir = $this->tempDir . '/repo';
        mkdir($repoDir . '/docs/decisions', 0777, true);
        file_put_contents($repoDir . '/docs/decisions/DECISIONS_INDEX.md', "# Decision Index\n\n| Decision ID | Status |\n");

        $evidence = [
            'decision_id' => 'DEC-099',
            'decision_file' => 'docs/decisions/DEC-099.md',
            'status_at_qualification' => 'ACTIVE',
        ];

        $verifier = new PublishedArtifactVerifier();
        $result = $verifier->evaluateSourceOnlyHistoricalChain($evidence, $repoDir);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('neither ACTIVE nor SUPERSEDED in current DECISIONS_INDEX.md', $result['message']);
    }

    public function testPassesWhenQualificationDecisionWasActiveAndRemainsActiveInIndex(): void
    {
        $repoDir = $this->tempDir . '/repo';
        mkdir($repoDir . '/docs/decisions', 0777, true);
        $indexContent = "| [DEC-019](DEC-019.md) | Title | ACTIVE | Scope | Record | Owner | None | None |\n";
        file_put_contents($repoDir . '/docs/decisions/DECISIONS_INDEX.md', $indexContent);

        $evidence = [
            'decision_id' => 'DEC-019',
            'decision_file' => 'docs/decisions/DEC-019.md',
            'status_at_qualification' => 'ACTIVE',
        ];

        $verifier = new PublishedArtifactVerifier();
        $result = $verifier->evaluateSourceOnlyHistoricalChain($evidence, $repoDir);

        self::assertSame('PASS', $result['status']);
        self::assertStringContainsString('remains ACTIVE in repository Decision Index', $result['message']);
    }

    public function testPassesWhenQualificationDecisionWasActiveAndIsLegitimatelySupersededLater(): void
    {
        $repoDir = $this->tempDir . '/repo';
        mkdir($repoDir . '/docs/decisions', 0777, true);
        // DEC-019 was later superseded by DEC-020
        $indexContent = "| [DEC-019](DEC-019.md) | Title | SUPERSEDED | Scope | Record | Owner | None | DEC-020 |\n";
        file_put_contents($repoDir . '/docs/decisions/DECISIONS_INDEX.md', $indexContent);

        $evidence = [
            'decision_id' => 'DEC-019',
            'decision_file' => 'docs/decisions/DEC-019.md',
            'status_at_qualification' => 'ACTIVE',
        ];

        $verifier = new PublishedArtifactVerifier();
        $result = $verifier->evaluateSourceOnlyHistoricalChain($evidence, $repoDir);

        self::assertSame('PASS', $result['status']);
        self::assertStringContainsString('coherent historical supersession chain (superseded by DEC-020)', $result['message']);
    }

    public function testFailsWhenSupersededDecisionHasBrokenHistory(): void
    {
        $repoDir = $this->tempDir . '/repo';
        mkdir($repoDir . '/docs/decisions', 0777, true);
        // SUPERSEDED but Superseded By is None
        $indexContent = "| [DEC-019](DEC-019.md) | Title | SUPERSEDED | Scope | Record | Owner | None | None |\n";
        file_put_contents($repoDir . '/docs/decisions/DECISIONS_INDEX.md', $indexContent);

        $evidence = [
            'decision_id' => 'DEC-019',
            'decision_file' => 'docs/decisions/DEC-019.md',
            'status_at_qualification' => 'ACTIVE',
        ];

        $verifier = new PublishedArtifactVerifier();
        $result = $verifier->evaluateSourceOnlyHistoricalChain($evidence, $repoDir);

        self::assertSame('FAIL', $result['status']);
        self::assertStringContainsString('lacks a valid successor in DECISIONS_INDEX.md', $result['message']);
    }

    public function testFailsWhenObservedReferenceDoesNotProveQualifiedSha(): void
    {
        $wrongSha = str_repeat('d', 40);
        $pkg = [
            'name' => 'maatify/php-rate-limiter',
            'version' => '1.0.0-rc.3',
            'installation-source' => 'dist',
            'dist' => [
                'type' => 'zip',
                'url' => 'https://api.github.com/repos/Maatify/php-rate-limiter/zipball/' . $wrongSha,
                'reference' => 'v1.0.0-rc.3', // Tag string instead of exact SHA
            ],
        ];

        $verifier = new PublishedArtifactVerifier();
        $eval = $verifier->evaluateInstalledMetadata($pkg, $this->qualifiedSha, 'dist', null, null);

        self::assertSame('FAIL', $eval['checks']['qualified_reference']['status']);
        self::assertStringContainsString('Tag string equality alone is insufficient', $eval['checks']['qualified_reference']['message']);
    }

    public function testFailsWhenInstalledContentDiffersFromQualificationEvidenceManifest(): void
    {
        $licenseHash = hash_file('sha256', $this->installedPath . '/LICENSE');
        self::assertIsString($licenseHash);
        $expectedManifest = [
            'README.md' => str_repeat('0', 64), // Mismatched expected hash
            'LICENSE' => $licenseHash,
        ];

        $verifier = new PublishedArtifactVerifier();
        $eval = $verifier->inspectInstalledArtifact($this->installedPath, '1.0.0-rc.3', $expectedManifest);

        self::assertSame('FAIL', $eval['checks']['content_manifest_correspondence']['status']);
        self::assertStringContainsString('Installed artifact content differs from qualified evidence', $eval['checks']['content_manifest_correspondence']['message']);
    }

    public function testPassesWhenInstalledContentMatchesQualificationEvidenceManifest(): void
    {
        $readmeHash = hash_file('sha256', $this->installedPath . '/README.md');
        $licenseHash = hash_file('sha256', $this->installedPath . '/LICENSE');
        self::assertIsString($readmeHash);
        self::assertIsString($licenseHash);
        $expectedManifest = [
            'README.md' => $readmeHash,
            'LICENSE' => $licenseHash,
        ];

        $verifier = new PublishedArtifactVerifier();
        $eval = $verifier->inspectInstalledArtifact($this->installedPath, '1.0.0-rc.3', $expectedManifest);

        self::assertSame('PASS', $eval['checks']['content_manifest_correspondence']['status']);
    }

    public function testRedactsCredentialsAndTokensFromAuditDiagnostics(): void
    {
        $verifier = new PublishedArtifactVerifier();

        $textWithToken = 'composer update --token=ghp_ABC1234567890XYZ Authorization: Bearer secret-token-value';
        $redacted = $verifier->redactSensitiveData($textWithToken);

        self::assertStringNotContainsString('ghp_ABC1234567890XYZ', $redacted);
        self::assertStringNotContainsString('secret-token-value', $redacted);
        self::assertStringContainsString('Bearer [REDACTED]', $redacted);
    }

    /**
     * @return array<string, mixed>
     */
    private function createValidQualificationEvidence(string $target, string $sha): array
    {
        return [
            'schema_version' => '1.0.0',
            'package_name' => 'maatify/php-rate-limiter',
            'target_version' => $target,
            'candidate_sha' => $sha,
            'candidate_tree_sha' => str_repeat('e', 40),
            'delivery_policy' => 'dist',
            'qualified_at' => '2026-10-06T20:00:00Z',
            'content_manifest' => [
                'composer.json' => hash_file('sha256', $this->installedPath . '/composer.json'),
                'README.md' => hash_file('sha256', $this->installedPath . '/README.md'),
            ],
            'semantic_review' => [],
            'source_only_decision' => null,
            'status' => 'PASS',
        ];
    }

    private function populateValidInstalledArtifact(string $dir, string $version): void
    {
        file_put_contents($dir . '/composer.json', json_encode([
            'name' => 'maatify/php-rate-limiter',
            'license' => 'proprietary',
        ], JSON_PRETTY_PRINT));
        file_put_contents($dir . '/README.md', "# php-rate-limiter\nVersion: " . $version . "\n");
        file_put_contents($dir . '/LICENSE', 'Maatify Proprietary License');
        file_put_contents($dir . '/CHANGELOG.md', "## [" . $version . "]\n- Initial release\n");
        file_put_contents($dir . '/SECURITY.md', "## Supported Versions\n| " . $version . " | Yes |\n");
        file_put_contents($dir . '/RATE_LIMITER_PACKAGE_REFERENCE.md', '# Package Reference');
        file_put_contents($dir . '/docs/guides/USAGE_GUIDE.md', '# Usage Guide');
        file_put_contents($dir . '/llms.txt', '# LLM Reference');
        file_put_contents($dir . '/src/RateLimiter.php', "<?php\n");
        file_put_contents($dir . '/examples/basic.php', "<?php\n");
    }

    private function populateValidInstalledJson(string $path, string $version, string $sha, string $mode): void
    {
        $pkg = [
            'name' => 'maatify/php-rate-limiter',
            'version' => $version,
            'installation-source' => $mode,
        ];

        if ($mode === 'dist') {
            $pkg['dist'] = [
                'type' => 'zip',
                'url' => 'https://api.github.com/repos/Maatify/php-rate-limiter/zipball/' . $sha,
                'reference' => $sha,
                'shasum' => '',
            ];
        } else {
            $pkg['source'] = [
                'type' => 'git',
                'url' => 'https://github.com/Maatify/php-rate-limiter.git',
                'reference' => $sha,
            ];
        }

        $data = ['packages' => [$pkg]];
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
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
