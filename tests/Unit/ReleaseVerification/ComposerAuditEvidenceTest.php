<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\ReleaseVerification;

use Maatify\RateLimiter\Tests\Support\ReleaseVerification\EvidenceFixtures;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\PublishedArtifactVerifier;
use Maatify\RateLimiter\Tests\Support\ReleaseVerification\ReleaseContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F94-04: Composer version and effective preferred-install evidence are REQUIRED qualifying
 * evidence. A controlled fake Composer executable proves each failure is a qualifying-path
 * FAIL that happens BEFORE any installation, even when `composer update` would have succeeded.
 */
final class ComposerAuditEvidenceTest extends TestCase
{
    private const string TARGET = '1.0.0-rc.3';
    private const string SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private string $dir;
    private string $fixture;
    private string $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/maatify-fakecomposer-' . bin2hex(random_bytes(6));
        $this->fixture = $this->dir . '/fixture';
        mkdir($this->fixture, 0777, true);
        $this->buildInstalledFixture();

        $this->fake = $this->dir . '/composer';
        file_put_contents($this->fake, <<<'BASH'
            #!/usr/bin/env bash
            dir="$(cd "$(dirname "$0")" && pwd)"
            case "$1" in
              --version)
                case "$(cat "$dir/version.mode")" in
                  ok) echo "Composer version 2.10.3 2026-08-27 13:34:23" ;;
                  ok-with-noise) echo "Deprecated: noise"; echo "Composer version 2.11.0-RC1 2026-09-01 00:00:00" ;;
                  fail) exit 1 ;;
                  empty) ;;
                  blank) echo "   " ;;
                  malformed) echo "this is not composer" ;;
                  nonsemver) echo "Composer version dev-main" ;;
                esac ;;
              config)
                case "$(cat "$dir/config.mode")" in
                  dist) echo "dist" ;;
                  json-dist) echo '{"*":"dist"}' ;;
                  json-mixed) echo '{"*":"dist","vendor/*":"source"}' ;;
                  json-no-star) echo '{"vendor/*":"dist"}' ;;
                  fail) exit 1 ;;
                  empty) ;;
                  unknown) echo "UNKNOWN" ;;
                  source) echo "source" ;;
                  auto) echo "auto" ;;
                  garbage) echo "¯\_(ツ)_/¯" ;;
                esac ;;
              update)
                touch "$dir/update.ran"
                cp -R "$(cat "$dir/fixture.path")/." "$PWD/" ;;
            esac
            exit 0
            BASH);
        chmod($this->fake, 0755);
        file_put_contents($this->dir . '/fixture.path', $this->fixture);
        $this->modes('ok', 'dist');
    }

    protected function tearDown(): void
    {
        ReleaseContract::removeTree($this->dir);
        parent::tearDown();
    }

    // ----------------------------------------------------------- pure evidence evaluators

    /**
     * @return iterable<string, array{int, string, bool}>
     */
    public static function versionOutputs(): iterable
    {
        yield 'valid release' => [0, "Composer version 2.10.3 2026-08-27 13:34:23\n", true];
        yield 'valid prerelease' => [0, "Composer version 2.11.0-RC1 2026-09-01\n", true];
        yield 'valid with leading noise' => [0, "Warning: something\nComposer version 2.10.3 2026\n", true];
        yield 'non-zero exit' => [1, "Composer version 2.10.3 2026\n", false];
        yield 'empty' => [0, '', false];
        yield 'whitespace only' => [0, "  \n\t\n", false];
        yield 'malformed' => [0, "this is not composer\n", false];
        yield 'no numeric version' => [0, "Composer version dev-main\n", false];
        yield 'UNKNOWN literal' => [0, "UNKNOWN\n", false];
    }

    #[DataProvider('versionOutputs')]
    public function testComposerVersionEvidenceEvaluator(int $code, string $output, bool $eligible): void
    {
        $r = (new PublishedArtifactVerifier())->evaluateComposerVersionEvidence($code, $output);

        self::assertSame($eligible ? 'PASS' : 'FAIL', $r['status'], $r['message']);
        if ($eligible) {
            self::assertStringStartsWith('Composer version ', $r['version'] ?? '');
        } else {
            self::assertArrayNotHasKey('version', $r);
        }
    }

    /**
     * @return iterable<string, array{int, string, bool}>
     */
    public static function preferredInstallOutputs(): iterable
    {
        yield 'plain dist' => [0, "dist\n", true];
        yield 'structured all dist' => [0, '{"*":"dist"}', true];
        yield 'structured multiple dist' => [0, '{"*":"dist","vendor/*":"dist"}', true];
        yield 'non-zero exit' => [1, 'dist', false];
        yield 'empty' => [0, '', false];
        yield 'whitespace only' => [0, "  \n", false];
        yield 'UNKNOWN' => [0, 'UNKNOWN', false];
        yield 'unknown lower-case' => [0, 'unknown', false];
        yield 'source contradicts' => [0, 'source', false];
        yield 'auto contradicts' => [0, 'auto', false];
        yield 'unparseable' => [0, 'maybe-dist', false];
        yield 'structured with source entry' => [0, '{"*":"dist","vendor/*":"source"}', false];
        yield 'structured without catch-all' => [0, '{"vendor/*":"dist"}', false];
        yield 'structured invalid json' => [0, '{"*":', false];
    }

    #[DataProvider('preferredInstallOutputs')]
    public function testPreferredInstallEvidenceEvaluator(int $code, string $output, bool $eligible): void
    {
        $r = (new PublishedArtifactVerifier())->evaluatePreferredInstallEvidence($code, $output);

        self::assertSame($eligible ? 'PASS' : 'FAIL', $r['status'], $r['message']);
    }

    // ----------------------------------------------------------- qualifying PAV path

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function failingModes(): iterable
    {
        yield 'version command fails' => ['fail', 'dist', 'composer_version_evidence'];
        yield 'version output empty' => ['empty', 'dist', 'composer_version_evidence'];
        yield 'version output whitespace' => ['blank', 'dist', 'composer_version_evidence'];
        yield 'version output malformed' => ['malformed', 'dist', 'composer_version_evidence'];
        yield 'version without numeric version' => ['nonsemver', 'dist', 'composer_version_evidence'];
        yield 'preferred-install query fails' => ['ok', 'fail', 'preferred_install_evidence'];
        yield 'preferred-install empty' => ['ok', 'empty', 'preferred_install_evidence'];
        yield 'preferred-install UNKNOWN' => ['ok', 'unknown', 'preferred_install_evidence'];
        yield 'preferred-install unparseable' => ['ok', 'garbage', 'preferred_install_evidence'];
        yield 'preferred-install source' => ['ok', 'source', 'preferred_install_evidence'];
        yield 'preferred-install auto' => ['ok', 'auto', 'preferred_install_evidence'];
        yield 'preferred-install structured mixed' => ['ok', 'json-mixed', 'preferred_install_evidence'];
        yield 'preferred-install structured no catch-all' => ['ok', 'json-no-star', 'preferred_install_evidence'];
    }

    #[DataProvider('failingModes')]
    public function testMissingOrContradictoryComposerEvidenceFailsQualifyingPavEvenIfUpdateWouldSucceed(string $version, string $config, string $failingCheck): void
    {
        $this->modes($version, $config);

        $result = $this->verify();

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks'][$failingCheck]['status'], $failingCheck);
        self::assertContains($result['checks'][$failingCheck]['message'], $result['failures']);
        self::assertFileDoesNotExist($this->dir . '/update.ran', 'composer update must not run when required audit evidence is missing');
        self::assertArrayNotHasKey('composer_resolution', $result['checks']);
        self::assertSame('UNRESOLVED', $result['installation_mode']);
        self::assertStringNotContainsString('UNKNOWN', (string) json_encode($result['composer_audit']), 'UNKNOWN must never be retained as evidence');
    }

    public function testValidVersionAndPreferredInstallEvidenceWithOtherwiseValidPathPasses(): void
    {
        $result = $this->verify();

        self::assertSame('PASS', $result['status'], implode("\n", $result['failures']));
        self::assertFileExists($this->dir . '/update.ran');
        self::assertSame('PASS', $result['checks']['composer_version_evidence']['status']);
        self::assertSame('PASS', $result['checks']['preferred_install_evidence']['status']);
        self::assertSame('Composer version 2.10.3 2026-08-27 13:34:23', $result['composer_audit']['composer_version']);
        self::assertSame(
            ['requested_flag' => '--prefer-dist', 'configured' => 'dist', 'effective_config' => 'dist', 'effective_normalized' => 'dist'],
            $result['composer_audit']['prefer_install'],
        );
        self::assertSame('dist', $result['installation_mode']);
    }

    public function testStructuredDistPreferenceAndNoisyVersionOutputAreEligible(): void
    {
        $this->modes('ok-with-noise', 'json-dist');

        $result = $this->verify();

        self::assertSame('PASS', $result['status'], implode("\n", $result['failures']));
        self::assertSame('Composer version 2.11.0-RC1 2026-09-01 00:00:00', $result['composer_audit']['composer_version']);
        self::assertIsArray($result['composer_audit']['prefer_install']);
        self::assertSame('{"*":"dist"}', $result['composer_audit']['prefer_install']['effective_config']);
    }

    public function testMissingComposerExecutableFailsClosed(): void
    {
        $result = $this->verify(new PublishedArtifactVerifier($this->dir . '/does-not-exist'));

        self::assertSame('FAIL', $result['status']);
        self::assertSame('FAIL', $result['checks']['composer_version_evidence']['status']);
    }

    // ----------------------------------------------------------- helpers

    private function modes(string $version, string $config): void
    {
        file_put_contents($this->dir . '/version.mode', $version);
        file_put_contents($this->dir . '/config.mode', $config);
        @unlink($this->dir . '/update.ran');
    }

    /**
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
    private function verify(?PublishedArtifactVerifier $verifier = null): array
    {
        $evidence = EvidenceFixtures::validDist(self::TARGET, self::SHA);
        $evidence['content_manifest'] = ReleaseContract::buildManifest($this->fixture . '/vendor/maatify/php-rate-limiter');

        return ($verifier ?? new PublishedArtifactVerifier($this->fake))->verify(['qualification_evidence' => $evidence]);
    }

    private function buildInstalledFixture(): void
    {
        $pkg = $this->fixture . '/vendor/maatify/php-rate-limiter';
        foreach (['src', 'docs/guides', 'examples', $this->fixture . '/vendor/composer'] as $d) {
            mkdir(str_starts_with($d, '/') ? $d : $pkg . '/' . $d, 0777, true);
        }
        file_put_contents($pkg . '/composer.json', (string) json_encode(['name' => 'maatify/php-rate-limiter', 'license' => 'proprietary']));
        file_put_contents($pkg . '/README.md', "# php-rate-limiter\ncomposer require maatify/php-rate-limiter:" . self::TARGET . "\n");
        foreach (['LICENSE' => 'Proprietary', 'CHANGELOG.md' => '# c', 'SECURITY.md' => '# s', 'RATE_LIMITER_PACKAGE_REFERENCE.md' => '# r', 'docs/guides/USAGE_GUIDE.md' => '# u', 'llms.txt' => 'l', 'src/A.php' => "<?php\n", 'examples/e.php' => "<?php\n"] as $f => $c) {
            file_put_contents($pkg . '/' . $f, $c);
        }
        $dist = ['type' => 'zip', 'url' => 'https://api.example.test/zipball/' . self::SHA, 'reference' => self::SHA];
        file_put_contents($this->fixture . '/vendor/composer/installed.json', (string) json_encode(['packages' => [[
            'name' => 'maatify/php-rate-limiter',
            'version' => self::TARGET,
            'installation-source' => 'dist',
            'install-path' => '../maatify/php-rate-limiter',
            'dist' => $dist,
        ]]]));
        file_put_contents($this->fixture . '/composer.lock', (string) json_encode(['packages' => [[
            'name' => 'maatify/php-rate-limiter',
            'version' => self::TARGET,
            'dist' => $dist,
        ]]]));
    }
}
