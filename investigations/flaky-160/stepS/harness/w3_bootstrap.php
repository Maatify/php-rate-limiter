<?php

declare(strict_types=1);

// #160 step S — W3 bootstrap: W2 (step M, unchanged logic) then the W3 edits. Passed to PHPUnit as `--bootstrap`.
// FLAKY160_ROOT selects the tree under test (base worktree or change); default is this checkout.

require __DIR__ . '/m2b_lib.php';
require __DIR__ . '/w3_lib.php';

$root = getenv('FLAKY160_ROOT') ?: dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';
$dir = getenv('FLAKY160M_DIR');
$dir = $dir === false || $dir === '' ? null : $dir;
\Flaky160M\M2b::install($root, $dir);
\Flaky160S\W3::install($root, $dir);
