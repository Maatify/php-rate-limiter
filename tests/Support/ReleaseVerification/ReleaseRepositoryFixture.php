<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\ReleaseVerification;

use RuntimeException;

/**
 * Test-only: builds a real, isolated temporary Git repository holding release content,
 * Decision Records and a Decision Index, so verifiers are exercised against real Git objects.
 */
final class ReleaseRepositoryFixture
{
    public const string CHANNEL = 'https://packages.example.test/maatify';

    public readonly string $path;

    public function __construct()
    {
        $this->path = sys_get_temp_dir() . '/maatify-fixture-' . bin2hex(random_bytes(6));
        mkdir($this->path, 0777, true);
        $this->git(['init', '-q', '-b', 'main']);
        $this->git(['config', 'user.name', 'Test']);
        $this->git(['config', 'user.email', 'test@example.com']);
        $this->git(['config', 'commit.gpgsign', 'false']);
        $this->git(['config', 'core.autocrlf', 'false']);
    }

    public function destroy(): void
    {
        ReleaseContract::removeTree($this->path);
    }

    /**
     * @param list<string> $args
     */
    public function git(array $args): string
    {
        $r = (new GitRepository($this->path))->run($args);
        if ($r['code'] !== 0) {
            throw new RuntimeException('git ' . implode(' ', $args) . ' failed');
        }

        return trim($r['output']);
    }

    public function write(string $relative, string $content): void
    {
        $full = $this->path . '/' . $relative;
        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }
        file_put_contents($full, $content);
    }

    public function remove(string $relative): void
    {
        unlink($this->path . '/' . $relative);
    }

    public function commit(string $message = 'commit'): string
    {
        $this->git(['add', '-A']);
        $this->git(['commit', '-q', '--allow-empty', '-m', $message]);

        return $this->git(['rev-parse', 'HEAD']);
    }

    public function head(): string
    {
        return $this->git(['rev-parse', 'HEAD']);
    }

    public function populateRelease(string $version): void
    {
        $this->write('composer.json', (string) json_encode(['name' => 'maatify/php-rate-limiter', 'license' => 'proprietary'], JSON_PRETTY_PRINT));
        $this->write('README.md', "# php-rate-limiter\nVersion: {$version}\ncomposer require maatify/php-rate-limiter:{$version}\n");
        $this->write('LICENSE', 'Maatify Proprietary License');
        $this->write('CHANGELOG.md', "## [Unreleased]\n\n## [{$version}]\n- Initial feature set\n");
        $this->write('SECURITY.md', "## Supported Versions\n| {$version} | No (pre-release candidate) |\n");
        $this->write('RATE_LIMITER_PACKAGE_REFERENCE.md', '# Package Reference');
        $this->write('docs/guides/USAGE_GUIDE.md', '# Usage Guide');
        $this->write('llms.txt', '# LLM Reference');
        $this->write('src/RateLimiter.php', "<?php\n");
        $this->write('src/Nested/CriticalRuntimeFile.php', "<?php\n// critical\n");
        $this->write('examples/basic.php', "<?php\n");
    }

    /**
     * Writes a semantic review record OUTSIDE the repository (the worktree must stay clean).
     */
    public function semanticReviewFile(string $target, string $sha): string
    {
        $path = $this->path . '-semantic-review.json';
        file_put_contents($path, json_encode([
            'schema_version' => '1.0.0',
            'target' => $target,
            'candidate_sha' => $sha,
            'reviewer' => 'Lead Reviewer <lead@maatify.dev>',
            'reviewed_at' => '2026-10-06T20:00:00Z',
            'disposition' => 'APPROVED',
            'claims' => array_fill_keys(ReleaseContract::REQUIRED_SEMANTIC_CLAIMS, 'CONFIRMED'),
            'notes' => 'Test review.',
        ], JSON_PRETTY_PRINT));

        return $path;
    }

    public function cleanup(): void
    {
        @unlink($this->path . '-semantic-review.json');
        @unlink($this->path . '-evidence.json');
        $this->destroy();
    }

    /**
     * @param array<string, string> $declaration overrides; a value of '' removes the key
     */
    public static function decisionRecord(
        string $id,
        string $status = 'ACTIVE',
        array $declaration = [],
        string $authority = 'Owner-approved package delivery decision.',
        string $supersedes = 'None.',
        string $supersededBy = 'None.',
        bool $withDeclaration = true,
    ): string {
        $values = array_merge([
            'Package' => 'maatify/php-rate-limiter',
            'Composer Channel' => self::CHANNEL,
            'Delivery Mode' => 'source-only',
            'Intentional Canonical Delivery' => 'yes',
            'Version Scope' => '1.0.x',
            'Dist Rationale' => 'The approved channel intentionally publishes no dist archive.',
            'Owner Approval' => 'APPROVED',
            'Approving Authority' => 'Package Owner',
            'Approval Date' => '2026-10-01',
            'Effective Date' => '2026-10-01',
            'Maintenance Owner' => 'Maatify Maintainers',
        ], $declaration);

        $bullets = '';
        foreach ($values as $key => $value) {
            if ($value !== '') {
                $bullets .= "- {$key}: {$value}\n";
            }
        }

        $record = "# {$id} — Test Source-Only Delivery\n\n## Decision ID\n\n`{$id}`\n\n## Status\n\n`{$status}`\n\n## Date\n\n2026-10-01\n\n"
            . "## Decision Authority\n\n{$authority}\n\n## Scope / Concern\n\nSource-only delivery.\n\n";
        if ($withDeclaration) {
            $record .= "## Source-Only Delivery Declaration\n\n{$bullets}\n";
        }

        return $record . "## Supersedes\n\n{$supersedes}\n\n## Superseded By\n\n{$supersededBy}\n";
    }

    /**
     * @param list<array{id: string, status: string, file?: string, supersedes?: string, superseded_by?: string}> $rows
     */
    public static function decisionIndex(array $rows): string
    {
        $out = "# Decision Index\n\n| Decision ID | Title | Status | Scope / Concern | Decision Record | Canonical Contract / Current Owner | Supersedes | Superseded By |\n| --- | --- | --- | --- | --- | --- | --- | --- |\n";
        foreach ($rows as $row) {
            $file = $row['file'] ?? $row['id'] . '_TEST.md';
            $out .= sprintf(
                "| [%s](%s) | Title | %s | Scope | [%s](%s) | Owner | %s | %s |\n",
                $row['id'],
                $file,
                $row['status'],
                $row['id'],
                $file,
                $row['supersedes'] ?? 'None',
                $row['superseded_by'] ?? 'None',
            );
        }

        return $out;
    }

    public static function decisionPath(string $id): string
    {
        return 'docs/decisions/' . $id . '_TEST.md';
    }
}
