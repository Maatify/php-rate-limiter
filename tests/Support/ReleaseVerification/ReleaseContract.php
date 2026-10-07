<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\ReleaseVerification;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Constants and pure helpers shared by Release Artifact Verification (RAV) and
 * Published Artifact Verification (PAV), so both gates evaluate one contract.
 */
final class ReleaseContract
{
    public const string PACKAGE_NAME = 'maatify/php-rate-limiter';
    public const string LICENSE = 'proprietary';

    /** Canonical RAV qualification-evidence schema consumed by PAV. */
    public const string EVIDENCE_SCHEMA_VERSION = '2.0.0';
    public const string SEMANTIC_REVIEW_SCHEMA_VERSION = '1.0.0';

    /** @var list<string> */
    public const array DELIVERY_POLICIES = ['dist', 'source-only'];

    /** Package-approved Composer channel for the normal `dist` delivery policy. */
    public const string DEFAULT_DISTRIBUTION_CHANNEL = 'https://repo.packagist.org';

    /** @var list<string> */
    public const array REQUIRED_PATHS = [
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

    public static function isSha(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{40}$/i', $value);
    }

    public static function isSha256(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{64}$/', $value);
    }

    /** Strict UTC timestamp: YYYY-MM-DDTHH:MM:SSZ. */
    public static function parseTimestamp(string $value): ?int
    {
        if (! (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value)) {
            return null;
        }
        $parsed = strtotime($value);

        return $parsed === false ? null : $parsed;
    }

    /**
     * Date-only values are ambiguous about the time of day, so they are resolved to the
     * END of that UTC day (conservative: the approval can only be treated as effective
     * after the whole day has elapsed). Returns null for any other shape.
     */
    public static function parseEffectiveInstant(string $value): ?int
    {
        if ((bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $parsed = strtotime($value . 'T23:59:59Z');

            return $parsed === false ? null : $parsed;
        }

        return self::parseTimestamp($value);
    }

    public static function normalizeChannel(string $channel): string
    {
        $channel = trim($channel);
        $parts = parse_url($channel);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return rtrim($channel, '/');
        }

        $normalized = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (isset($parts['port'])) {
            $normalized .= ':' . $parts['port'];
        }

        return $normalized . rtrim($parts['path'] ?? '', '/');
    }

    public static function isValidChannel(string $channel): bool
    {
        $parts = parse_url(trim($channel));

        return $parts !== false
            && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower($parts['scheme']), ['https', 'http'], true)
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment']);
    }

    public static function normalizeVersion(string $version): string
    {
        $version = strtolower(trim($version));

        return str_starts_with($version, 'v') ? substr($version, 1) : $version;
    }

    /**
     * Whether $target is inside a bounded Decision version scope.
     *
     * Scope grammar: comma-separated tokens, each an exact SemVer (`1.0.0-rc.3`),
     * a minor line (`1.0.x`) or a major line (`1.x`). Anything else is invalid.
     *
     * @return bool|null null when the scope itself is malformed/ambiguous
     */
    public static function versionInScope(string $target, string $scope): ?bool
    {
        $tokens = array_map('trim', explode(',', $scope));
        $covered = false;
        foreach ($tokens as $token) {
            if ($token === '') {
                return null;
            }
            if ((bool) preg_match('/^(\d+)\.x$/', $token, $m)) {
                $covered = $covered || (bool) preg_match('/^' . $m[1] . '\.\d+\.\d+(?:[-+].*)?$/', $target);
            } elseif ((bool) preg_match('/^(\d+)\.(\d+)\.x$/', $token, $m)) {
                $covered = $covered || (bool) preg_match('/^' . $m[1] . '\.' . $m[2] . '\.\d+(?:[-+].*)?$/', $target);
            } elseif ((bool) preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $token)) {
                $covered = $covered || $token === $target;
            } else {
                return null;
            }
        }

        return $covered;
    }

    /**
     * Whether a relative path is prohibited from release/distribution content.
     */
    public static function isForbiddenPath(string $path): bool
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $base = basename($path);

        foreach (['vendor', '.idea', '.vscode', '.phpunit.cache', 'coverage'] as $dir) {
            if ($path === $dir || str_starts_with($path, $dir . '/')) {
                return true;
            }
        }

        if ($path === 'composer.lock' || $base === 'auth.json' || $base === '.DS_Store') {
            return true;
        }

        if ($base === '.env' || (str_starts_with($base, '.env.') && ! in_array($base, ['.env.example', '.env.dist', '.env.sample'], true))) {
            return true;
        }

        return (bool) preg_match('/\.(pem|key|swp|bak)$|~$/', $base);
    }

    /**
     * @return list<string> relative file paths (forward slashes), `.git` excluded
     */
    public static function listFiles(string $dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        /** @var SplFileInfo $info */
        foreach ($iterator as $info) {
            if (! $info->isFile() && ! $info->isLink()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($info->getPathname(), strlen($dir) + 1));
            if ($relative === '.git' || str_starts_with($relative, '.git/')) {
                continue;
            }
            $files[] = $relative;
        }
        sort($files);

        return $files;
    }

    /**
     * Recursively and deterministically hashes a file or directory using SHA-256.
     */
    public static function hashPath(string $path): string
    {
        if (is_file($path)) {
            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                throw new RuntimeException('Failed to hash file: ' . $path);
            }

            return $hash;
        }

        if (is_dir($path)) {
            $combined = '';
            foreach (self::listFiles($path) as $relative) {
                $fileHash = hash_file('sha256', $path . '/' . $relative);
                if ($fileHash === false) {
                    throw new RuntimeException('Failed to hash file: ' . $path . '/' . $relative);
                }
                $combined .= $relative . ':' . $fileHash . "\n";
            }

            return hash('sha256', $combined);
        }

        throw new RuntimeException('Path is neither file nor directory: ' . $path);
    }

    /**
     * Content manifest over exactly the required release/distribution paths of a directory.
     *
     * @return array<string, string>
     */
    public static function buildManifest(string $dir): array
    {
        $manifest = [];
        foreach (self::REQUIRED_PATHS as $path) {
            if (file_exists($dir . '/' . $path)) {
                $manifest[$path] = self::hashPath($dir . '/' . $path);
            }
        }
        ksort($manifest);

        return $manifest;
    }

    public static function removeTree(string $dir): void
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
            if (is_dir($path) && ! is_link($path)) {
                self::removeTree($path);
            } else {
                @chmod($path, 0666);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Redacts credentials/tokens from a URL: userinfo is dropped and every query value masked.
     */
    public static function redactUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return self::redactSensitiveData($url);
        }

        $out = (isset($parts['scheme']) ? $parts['scheme'] . '://' : '//') . $parts['host'];
        if (isset($parts['port'])) {
            $out .= ':' . $parts['port'];
        }
        $out .= $parts['path'] ?? '';
        if (isset($parts['query'])) {
            $pairs = [];
            foreach (explode('&', $parts['query']) as $pair) {
                $key = explode('=', $pair, 2)[0];
                $pairs[] = $key . '=[REDACTED]';
            }
            $out .= '?' . implode('&', $pairs);
        }

        return $out;
    }

    /**
     * Redacts credentials, tokens, and authorization headers from diagnostic strings.
     */
    public static function redactSensitiveData(string $text): string
    {
        $text = (string) preg_replace('/(Bearer\s+)[A-Za-z0-9_\-\.~+\/]+=*/i', '$1[REDACTED]', $text);
        $text = (string) preg_replace('/(https?:\/\/)[^\/\s:@]+:[^@\s]+(@)/i', '$1[REDACTED]$2', $text);
        $text = (string) preg_replace('/([?&](?:token|access_token|auth|key|password|secret|signature|sig)=)[^&\s]+/i', '$1[REDACTED]', $text);

        return (string) preg_replace('/(token|password|secret|auth)\s*[:=]\s*[^\s,]+/i', '$1=[REDACTED]', $text);
    }
}
