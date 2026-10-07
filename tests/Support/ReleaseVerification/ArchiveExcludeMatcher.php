<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\ReleaseVerification;

/**
 * Evaluates the effective impact of gitignore-style `composer.json` `archive.exclude`
 * patterns on a set of tracked files (last matching pattern wins; `!` re-includes;
 * a file inside an excluded directory is excluded).
 */
final class ArchiveExcludeMatcher
{
    /** @var list<array{regex: string, negated: bool, dirOnly: bool}> */
    private array $rules = [];

    /**
     * @param list<string> $patterns
     */
    public function __construct(array $patterns)
    {
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '' || str_starts_with($pattern, '#')) {
                continue;
            }
            $negated = str_starts_with($pattern, '!');
            if ($negated) {
                $pattern = substr($pattern, 1);
            }
            $dirOnly = str_ends_with($pattern, '/');
            $pattern = rtrim($pattern, '/');
            $anchored = str_starts_with($pattern, '/') || str_contains($pattern, '/');
            $pattern = ltrim($pattern, '/');

            $regex = '';
            $length = strlen($pattern);
            for ($i = 0; $i < $length; $i++) {
                $char = $pattern[$i];
                if ($char === '*') {
                    if (($pattern[$i + 1] ?? '') === '*') {
                        $regex .= '.*';
                        $i++;
                        if (($pattern[$i + 1] ?? '') === '/') {
                            $i++;
                            $regex = substr($regex, 0, -2) . '(?:.*/)?';
                        }
                    } else {
                        $regex .= '[^/]*';
                    }
                } elseif ($char === '?') {
                    $regex .= '[^/]';
                } else {
                    $regex .= preg_quote($char, '#');
                }
            }

            $this->rules[] = [
                'regex' => '#^' . ($anchored ? '' : '(?:.*/)?') . $regex . '$#',
                'negated' => $negated,
                'dirOnly' => $dirOnly,
            ];
        }
    }

    public function isExcluded(string $path): bool
    {
        $segments = explode('/', $path);
        $prefix = '';
        foreach ($segments as $index => $segment) {
            $prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
            $isDirectory = $index < count($segments) - 1;
            if ($this->matches($prefix, $isDirectory)) {
                return true;
            }
        }

        return false;
    }

    private function matches(string $path, bool $isDirectory): bool
    {
        $excluded = false;
        foreach ($this->rules as $rule) {
            if ($rule['dirOnly'] && ! $isDirectory) {
                continue;
            }
            if ((bool) preg_match($rule['regex'], $path)) {
                $excluded = ! $rule['negated'];
            }
        }

        return $excluded;
    }
}
