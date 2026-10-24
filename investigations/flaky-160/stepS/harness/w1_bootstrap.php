<?php

declare(strict_types=1);

// #160 phase 2b — W1 bootstrap (the instrument and its description are in w1_bootstrap_lib.php).
// Passed to PHPUnit as `--bootstrap`; never written into phpunit.xml, tests/ or composer.json.

require __DIR__ . '/w1_bootstrap_lib.php';

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';
$dir = getenv('FLAKY160B_DIR');
\Flaky160B\W1::install($root, $dir === false || $dir === '' ? null : $dir);
