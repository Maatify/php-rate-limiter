<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/examples',
        __DIR__ . '/consumer-verification',
        __DIR__ . '/scripts/ci',
    ])
    ->append([__DIR__ . '/.php-cs-fixer.dist.php'])
    ->exclude(['vendor']);

return (new Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PER-CS3x0' => true,
    ])
    ->setFinder($finder)
    ->setUsingCache(false);
