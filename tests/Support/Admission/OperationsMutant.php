<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

/**
 * Detector builder of the 5-b2.1 gates: a mutation of the real `RedisAdmissionOperations` source, loaded under another
 * class name. Each exact anchor must be found exactly once, so a detector cannot silently stop mutating.
 */
final class OperationsMutant
{
    private static int $counter = 0;

    /**
     * @param list<array{0: string, 1: string}> $exact exact [search, replacement] pairs
     * @return class-string
     */
    public static function load(array $exact): string
    {
        $path = dirname(__DIR__, 3) . '/src/Repository/Redis/RedisAdmissionOperations.php';
        $source = file_get_contents($path);
        if ($source === false) {
            throw new \LogicException("Cannot read {$path}.");
        }
        $name = 'RedisAdmissionOperationsMutant' . ++self::$counter;
        $exact[] = ["\nfinal class RedisAdmissionOperations\n", "\nfinal class {$name}\n"];
        foreach ($exact as [$search, $replacement]) {
            $count = substr_count($source, $search);
            if ($count !== 1) {
                throw new \LogicException("Mutation anchor found {$count} times, expected exactly once: " . trim($search));
            }
            $source = str_replace($search, $replacement, $source);
        }
        $file = sys_get_temp_dir() . '/' . $name . '.php';
        if (file_put_contents($file, $source) === false) {
            throw new \LogicException("Cannot write {$file}.");
        }
        try {
            require $file;
        } finally {
            unlink($file);
        }
        $class = 'Maatify\\RateLimiter\\Repository\\Redis\\' . $name;
        if (! class_exists($class)) {
            throw new \LogicException("Mutant {$class} did not load.");
        }

        return $class;
    }
}
