<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\Service\EvaluationPipeline;

/**
 * Detector builder of the 5-b3a gates (5b3a/PLAN.md §4): a mutation of the real source file, never a second
 * implementation. One named method body, or one exact source line, is replaced and the result is loaded under another
 * class name in the production namespace. An anchor that is not found exactly once is a hard failure, so a detector
 * cannot silently stop mutating.
 *
 * Seam bodies registered in the plan contain no brace other than their delimiters; the body scanner relies on that.
 */
final class PipelineMutant
{
    private static int $counter = 0;

    /**
     * @param array<string, string> $methodBodies method name => statements that become the whole body
     * @param list<array{0: string, 1: string}> $exact exact [search, replacement] pairs, each found exactly once
     * @return class-string<EvaluationPipeline>
     */
    public static function pipeline(array $methodBodies = [], array $exact = []): string
    {
        $path = dirname(__DIR__, 3) . '/src/Service/EvaluationPipeline.php';
        $name = 'EvaluationPipelineMutant' . ++self::$counter;
        $source = self::mutate(self::read($path), $methodBodies, $exact);
        $source = self::replaceOnce($source, "\nclass EvaluationPipeline\n", "\nclass {$name} extends EvaluationPipeline\n");

        $class = 'Maatify\\RateLimiter\\Service\\' . $name;
        self::load($source, $name);
        self::assertClass($class, EvaluationPipeline::class);

        return $class;
    }

    /**
     * @param array<string, string> $methodBodies
     * @param list<array{0: string, 1: string}> $exact
     * @return class-string<LaneAwareReaderInterface>
     */
    public static function reader(array $methodBodies = [], array $exact = []): string
    {
        $path = dirname(__DIR__, 3) . '/src/Service/RateLimitOperationalReader.php';
        $name = 'RateLimitOperationalReaderMutant' . ++self::$counter;
        $source = self::mutate(self::read($path), $methodBodies, $exact);
        $source = self::replaceOnce(
            $source,
            "\nfinal class RateLimitOperationalReader implements RateLimitOperationalReaderInterface\n",
            "\nfinal class {$name} implements RateLimitOperationalReaderInterface, \\Maatify\\RateLimiter\\Tests\\Support\\Admission\\LaneAwareReaderInterface\n",
        );

        $class = 'Maatify\\RateLimiter\\Service\\' . $name;
        self::load($source, $name);
        self::assertClass($class, LaneAwareReaderInterface::class);

        return $class;
    }

    /**
     * @param array<string, string> $methodBodies
     * @param list<array{0: string, 1: string}> $exact
     */
    private static function mutate(string $source, array $methodBodies, array $exact): string
    {
        foreach ($methodBodies as $method => $body) {
            $source = self::replaceBody($source, $method, $body);
        }
        foreach ($exact as [$search, $replacement]) {
            $source = self::replaceOnce($source, $search, $replacement);
        }

        return $source;
    }

    private static function replaceBody(string $source, string $method, string $body): string
    {
        $found = preg_match_all('/function ' . preg_quote($method, '/') . '\(/', $source, $matches, PREG_OFFSET_CAPTURE);
        if ($found !== 1) {
            throw new \LogicException("Mutation anchor 'function {$method}(' found {$found} times, expected exactly once.");
        }
        $signature = $matches[0][0][1];
        $open = strpos($source, '{', $signature);
        if ($open === false) {
            throw new \LogicException("No body found for {$method}.");
        }
        $depth = 0;
        $length = strlen($source);
        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, 0, $open + 1) . "\n        " . $body . "\n    " . substr($source, $i);
                }
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

    private static function read(string $path): string
    {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new \LogicException("Cannot read {$path}.");
        }

        return $source;
    }

    private static function load(string $source, string $name): void
    {
        $file = sys_get_temp_dir() . '/' . $name . '.php';
        if (file_put_contents($file, $source) === false) {
            throw new \LogicException("Cannot write {$file}.");
        }
        try {
            require $file;
        } finally {
            unlink($file);
        }
    }

    /** @param class-string $parent */
    private static function assertClass(string $class, string $parent): void
    {
        if (! class_exists($class) || ! is_a($class, $parent, true)) {
            throw new \LogicException("Mutant {$class} did not load as a {$parent}.");
        }
    }
}
