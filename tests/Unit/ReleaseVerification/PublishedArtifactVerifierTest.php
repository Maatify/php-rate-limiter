<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\ReleaseVerification;

use Maatify\RateLimiter\Tests\Support\ReleaseVerification\PublishedArtifactVerifier;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

    public function testFailsWhenInstalledJsonDoesNotExist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Provided installed_json_path does not exist');

        $verifier = new PublishedArtifactVerifier();
        $verifier->verify([
            'target' => '1.0.0-rc.3',
            'qualified_sha' => $this->qualifiedSha,
            'installed_path' => $this->installedPath,
            'installed_json_path' => $this->tempDir . '/nonexistent/installed.json',
        ]);
    }

    public function testFailsWhenInstalledPathDoesNotExist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Provided installed_path does not exist');

        $verifier = new PublishedArtifactVerifier();
        $verifier->verify([
            'target' => '1.0.0-rc.3',
            'qualified_sha' => $this->qualifiedSha,
            'installed_path' => $this->tempDir . '/nonexistent/package',
            'installed_json_path' => $this->installedJsonPath,
        ]);
    }

    public function testFailsWhenPackageNotFoundInInstalledJson(): void
    {
        file_put_contents($this->installedJsonPath, json_encode([
            'packages' => [
                ['name' => 'other/package', 'version' => '1.0.0'],
            ],
        ], JSON_PRETTY_PRINT));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Package "maatify/php-rate-limiter" was not found in installed.json packages list');

        $verifier = new PublishedArtifactVerifier();
        $verifier->verify([
            'target' => '1.0.0-rc.3',
            'qualified_sha' => $this->qualifiedSha,
            'installed_path' => $this->installedPath,
            'installed_json_path' => $this->installedJsonPath,
        ]);
    }

    public function testFailsWhenTargetVersionMismatchesResolvedVersion(): void
    {
        $this->populateValidInstalledJson($this->installedJsonPath, '1.0.0-rc.2', $this->qualifiedSha, 'dist');

        $verifier = new PublishedArtifactVerifier();
        $result = $verifier->verify([
            'target' => '1.0.0-rc.3',
            'qualified_sha' => $this->qualifiedSha,
            'installed_path' => $this->installedPath,
            'installed_json_path' => $this->installedJsonPath,
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['version_resolution']['status']);
        self::assertStringContainsString('does not match exact target version', $result['checks']['version_resolution']['message']);
    }

    public function testFailsWhenInstallationSourceIsUnprovable(): void
    {
        $package = [
            'name' => 'maatify/php-rate-limiter',
            'version' => '1.0.0-rc.3',
            // missing 'installation-source'
            'dist' => [
                'type' => 'zip',
                'url' => 'https://api.github.com/repos/Maatify/php-rate-limiter/zipball/' . $this->qualifiedSha,
                'reference' => $this->qualifiedSha,
            ],
        ];
        $data = ['packages' => [$package]];
        file_put_contents($this->installedJsonPath, json_encode($data, JSON_PRETTY_PRINT));

        $verifier = new PublishedArtifactVerifier();
        $result = $verifier->verify([
            'target' => '1.0.0-rc.3',
            'qualified_sha' => $this->qualifiedSha,
            'installed_path' => $this->installedPath,
            'installed_json_path' => $this->installedJsonPath,
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['installation_mode']['status']);
        self::assertStringContainsString('Unprovable installation mode', $result['checks']['installation_mode']['message']);
    }

    public function testFailsWhenDistExposedButObservedModeIsSource(): void
    {
        // Composer repository metadata exposes dist archive, but installation-source was source
        $package = [
            'name' => 'maatify/php-rate-limiter',
            'version' => '1.0.0-rc.3',
            'installation-source' => 'source',
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
        $data = ['packages' => [$package]];
        file_put_contents($this->installedJsonPath, json_encode($data, JSON_PRETTY_PRINT));

        $verifier = new PublishedArtifactVerifier();
        $result = $verifier->verify([
            'target' => '1.0.0-rc.3',
            'qualified_sha' => $this->qualifiedSha,
            'installed_path' => $this->installedPath,
            'installed_json_path' => $this->installedJsonPath,
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['installation_mode']['status']);
        self::assertStringContainsString('Dist archive was exposed by Composer repository metadata, but actual installation mode was source', $result['checks']['installation_mode']['message']);
    }

    public function testFailsWhenSourceModeObservedWithoutApprovedDecision(): void
    {
        // No dist exposed, mode is source, but no decision provided
        $package = [
            'name' => 'maatify/php-rate-limiter',
            'version' => '1.0.0-rc.3',
            'installation-source' => 'source',
            'source' => [
                'type' => 'git',
                'url' => 'https://github.com/Maatify/php-rate-limiter.git',
                'reference' => $this->qualifiedSha,
            ],
        ];
        $data = ['packages' => [$package]];
        file_put_contents($this->installedJsonPath, json_encode($data, JSON_PRETTY_PRINT));

        $verifier = new PublishedArtifactVerifier();
        $result = $verifier->verify([
            'target' => '1.0.0-rc.3',
            'qualified_sha' => $this->qualifiedSha,
            'installed_path' => $this->installedPath,
            'installed_json_path' => $this->installedJsonPath,
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['installation_mode']['status']);
        self::assertStringContainsString('no valid pre-existing qualification-time source-only Decision was established', $result['checks']['installation_mode']['message']);
    }

    public function testPassesWhenSourceModeObservedWithApprovedDecision(): void
    {
        $decisionFile = $this->tempDir . '/ADR_SOURCE_ONLY.md';
        file_put_contents($decisionFile, <<<MARKDOWN
# Decision: Source Only Delivery
Status: ACTIVE
Scope: maatify/php-rate-limiter
Target: 1.0.0-rc.3
Delivery: source-only
Rationale: Test justification.
MARKDOWN);

        $package = [
            'name' => 'maatify/php-rate-limiter',
            'version' => '1.0.0-rc.3',
            'installation-source' => 'source',
            'source' => [
                'type' => 'git',
                'url' => 'https://github.com/Maatify/php-rate-limiter.git',
                'reference' => $this->qualifiedSha,
            ],
        ];
        $data = ['packages' => [$package]];
        file_put_contents($this->installedJsonPath, json_encode($data, JSON_PRETTY_PRINT));

        $verifier = new PublishedArtifactVerifier();
        $result = $verifier->verify([
            'target' => '1.0.0-rc.3',
            'qualified_sha' => $this->qualifiedSha,
            'source_only_decision_file' => $decisionFile,
            'installed_path' => $this->installedPath,
            'installed_json_path' => $this->installedJsonPath,
        ]);

        self::assertSame('PASS', $result['status']);
        self::assertSame('PASS', $result['checks']['installation_mode']['status']);
        self::assertSame('source', $result['installation_mode']);
    }

    public function testFailsWhenObservedReferenceMismatchesQualifiedSha(): void
    {
        $wrongSha = str_repeat('b', 40);
        $this->populateValidInstalledJson($this->installedJsonPath, '1.0.0-rc.3', $wrongSha, 'dist');

        $verifier = new PublishedArtifactVerifier();
        $result = $verifier->verify([
            'target' => '1.0.0-rc.3',
            'qualified_sha' => $this->qualifiedSha,
            'installed_path' => $this->installedPath,
            'installed_json_path' => $this->installedJsonPath,
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['qualified_reference']['status']);
        self::assertStringContainsString('does not match intended qualified SHA', $result['checks']['qualified_reference']['message']);
    }

    public function testFailsWhenInstalledArtifactMissingRequiredFile(): void
    {
        unlink($this->installedPath . '/RATE_LIMITER_PACKAGE_REFERENCE.md');

        $verifier = new PublishedArtifactVerifier();
        $result = $verifier->verify([
            'target' => '1.0.0-rc.3',
            'qualified_sha' => $this->qualifiedSha,
            'installed_path' => $this->installedPath,
            'installed_json_path' => $this->installedJsonPath,
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['installed_required_files']['status']);
        self::assertStringContainsString('RATE_LIMITER_PACKAGE_REFERENCE.md', $result['checks']['installed_required_files']['message']);
    }

    public function testFailsWhenInstalledComposerManifestHasWrongPackageName(): void
    {
        file_put_contents($this->installedPath . '/composer.json', json_encode(['name' => 'wrong/package'], JSON_PRETTY_PRINT));

        $verifier = new PublishedArtifactVerifier();
        $result = $verifier->verify([
            'target' => '1.0.0-rc.3',
            'qualified_sha' => $this->qualifiedSha,
            'installed_path' => $this->installedPath,
            'installed_json_path' => $this->installedJsonPath,
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['installed_composer_manifest']['status']);
    }

    public function testFailsWhenInstalledReadmeDoesNotReferenceTargetVersion(): void
    {
        file_put_contents($this->installedPath . '/README.md', '# Rate Limiter' . PHP_EOL . 'Unrelated content');

        $verifier = new PublishedArtifactVerifier();
        $result = $verifier->verify([
            'target' => '1.0.0-rc.3',
            'qualified_sha' => $this->qualifiedSha,
            'installed_path' => $this->installedPath,
            'installed_json_path' => $this->installedJsonPath,
        ]);

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['installed_readme_identity']['status']);
        self::assertStringContainsString('does not reference the target version', $result['checks']['installed_readme_identity']['message']);
    }

    public function testPassesWhenValidDistInstallationSatisfiesAllChecks(): void
    {
        $verifier = new PublishedArtifactVerifier();
        $result = $verifier->verify([
            'target' => '1.0.0-rc.3',
            'qualified_sha' => $this->qualifiedSha,
            'installed_path' => $this->installedPath,
            'installed_json_path' => $this->installedJsonPath,
        ]);

        self::assertSame('PASS', $result['status']);
        self::assertSame([], $result['failures']);
        self::assertSame('dist', $result['installation_mode']);
        self::assertSame('maatify/php-rate-limiter', $result['package_name']);
        self::assertSame('1.0.0-rc.3', $result['target_version']);
    }

    private function populateValidInstalledArtifact(string $dir, string $version): void
    {
        file_put_contents($dir . '/composer.json', json_encode(['name' => 'maatify/php-rate-limiter', 'version' => $version], JSON_PRETTY_PRINT));
        file_put_contents($dir . '/README.md', "# php-rate-limiter\nVersion: " . $version . "\n");
        file_put_contents($dir . '/LICENSE', 'MIT License');
        file_put_contents($dir . '/CHANGELOG.md', "## [" . $version . "] - unreleased\n");
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
