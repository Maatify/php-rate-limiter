<?php

declare(strict_types=1);

/*
 * G8 (PLAN.md §3) — one arm and one value of `C`: the budgets the runtime receives, every stream row (both flows) and every fixture row.
 *
 *   php g8_replays.php <FULL2|PX4> <C> <out-dir>
 *
 * Writes <out-dir>/g8_streams.<arm>.C<C>.jsonl and <out-dir>/g8_fixtures.<arm>.C<C>.jsonl. Exit 6 on an input violation (IN).
 */

use Maatify\RateLimiter\Tests\Support\Admission\Archive\InputViolation;

require __DIR__ . '/../../../../../vendor/autoload.php';
require __DIR__ . '/../lib/g8.php';

[$arm, $c, $out] = [$argv[1] ?? '', (int) ($argv[2] ?? 0), $argv[3] ?? ''];
if (! in_array($arm, G8\ARMS, true) || ! in_array($c, G8\CS, true) || ! is_dir($out)) {
    fwrite(STDERR, "usage: php g8_replays.php <FULL2|PX4> <2|3|4|1000000> <out-dir>\n");
    exit(2);
}

try {
    $streams = fopen("{$out}/g8_streams.{$arm}.C{$c}.jsonl", 'wb');
    $fixtures = fopen("{$out}/g8_fixtures.{$arm}.C{$c}.jsonl", 'wb');
    if ($streams === false || $fixtures === false) {
        throw new RuntimeException('Cannot open the output files.');
    }
    // The values the runtime receives, read back from the DTO the lab is built with.
    G8\emit($streams, ['s' => 'meta', 'C' => $c, 'arm' => $arm, 'budgets' => get_object_vars(G8\budgets($arm, $c))]);
    foreach (G8\streamRows($arm, $c) as $row) {
        G8\emit($streams, $row);
    }
    foreach (G8\fixtureRows($arm, $c) as $row) {
        G8\emit($fixtures, $row);
        gc_collect_cycles();
    }
    fclose($streams);
    fclose($fixtures);
} catch (InputViolation $e) {
    fwrite(STDERR, 'IN: ' . $e->getMessage() . "\n");
    exit(6);
}
