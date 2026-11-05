<?php

declare(strict_types=1);

// Loads the checkout given as the first argument: Composer's loader for vendor packages, then a prepended loader that
// serves that checkout's src/ and tests/; a guard rejects a run whose pipeline did not come from it.
$root = (string) realpath($argv[1]);
require $root . '/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['Maatify\\RateLimiter\\Tests\\' => '/tests/', 'Maatify\\RateLimiter\\' => '/src/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $root . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
}, true, true);
$origin = (string) (new ReflectionClass(Maatify\RateLimiter\Service\EvaluationPipeline::class))->getFileName();
if (! str_starts_with($origin, $root . '/src/')) {
    fwrite(STDERR, 'pipeline loaded from ' . $origin . ", not from {$root}\n");
    exit(2);
}
