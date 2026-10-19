<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

/**
 * Detector builder of the 5-b3b gates: a mutation of a real `src/Service` file loaded under another class name in the
 * production namespace (the 5-b3a `PipelineMutant` approach, for any service class). One named method body, or one exact
 * source line, is replaced; an anchor that is not found exactly once is a hard failure. Method bodies registered in the plan
 * contain no brace other than their delimiters (the scanner relies on that).
 */
final class ServiceMutant
{
    private static int $counter = 0;

    /**
     * @param string $class short class name under `src/Service`
     * @param array<string, string> $methodBodies method name => statements that become the whole body
     * @param list<array{0: string, 1: string}> $exact exact [search, replacement] pairs, each found exactly once
     * @return class-string
     */
    public static function load(string $class, array $methodBodies = [], array $exact = []): string
    {
        $path = dirname(__DIR__, 3) . '/src/Service/' . $class . '.php';
        $source = file_get_contents($path);
        if ($source === false) {
            throw new \LogicException("Cannot read {$path}.");
        }
        $name = $class . 'Mutant' . ++self::$counter;
        foreach ($methodBodies as $method => $body) {
            $source = self::replaceBody($source, $method, $body);
        }
        foreach ($exact as [$search, $replacement]) {
            $source = self::replaceOnce($source, $search, $replacement);
        }
        $declared = preg_match_all('/^(final )?class ' . preg_quote($class, '/') . '\b/m', $source);
        if ($declared !== 1) {
            throw new \LogicException("Class declaration of {$class} found {$declared} times, expected once.");
        }
        $source = (string) preg_replace('/^(final )?class ' . preg_quote($class, '/') . '\b/m', '$1class ' . $name, $source, 1);
        $file = sys_get_temp_dir() . '/' . $name . '.php';
        if (file_put_contents($file, $source) === false) {
            throw new \LogicException("Cannot write {$file}.");
        }
        try {
            require $file;
        } finally {
            unlink($file);
        }
        $loaded = 'Maatify\\RateLimiter\\Service\\' . $name;
        if (! class_exists($loaded)) {
            throw new \LogicException("Mutant {$loaded} did not load.");
        }

        return $loaded;
    }

    private static function replaceBody(string $source, string $method, string $body): string
    {
        $found = preg_match_all('/function ' . preg_quote($method, '/') . '\(/', $source, $matches, PREG_OFFSET_CAPTURE);
        if ($found !== 1) {
            throw new \LogicException("Mutation anchor 'function {$method}(' found {$found} times, expected exactly once.");
        }
        $open = strpos($source, '{', $matches[0][0][1]);
        if ($open === false) {
            throw new \LogicException("No body found for {$method}.");
        }
        $depth = 0;
        for ($i = $open, $length = strlen($source); $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}' && --$depth === 0) {
                return substr($source, 0, $open + 1) . "\n        " . $body . "\n    " . substr($source, $i);
            }
        }

        throw new \LogicException("Unterminated body for {$method}.");
    }

    private static function replaceOnce(string $source, string $search, string $replacement): string
    {
        $count = substr_count($source, $search);
        if ($count !== 1) {
            throw new \LogicException("Mutation anchor found {$count} times, expected exactly once: " . trim($search));
        }

        return str_replace($search, $replacement, $source);
    }
}
