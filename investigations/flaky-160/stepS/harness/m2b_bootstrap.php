<?php

declare(strict_types=1);

// #160 step M — W2 bootstrap (the instrument and its description are in m2b_lib.php).
// Passed to PHPUnit as `--bootstrap`; never written into phpunit.xml, tests/ or composer.json.

require __DIR__ . '/m2b_lib.php';

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';
$dir = getenv('FLAKY160M_DIR');
\Flaky160M\M2b::install($root, $dir === false || $dir === '' ? null : $dir);
