<?php

declare(strict_types=1);

// #160 step S — one timing row from one A-test run under W3: every persistWinningCandidates call of every process
// (ns, whether a store-level K4 publication happened inside it). Prints one JSON line.
$o = getopt('', ['dir:', 'arm:', 'run:', 'exit:']);
$calls = [];
$arm = null;
foreach (glob($o['dir'] . '/w3-*.json') ?: [] as $f) {
    $j = json_decode((string) file_get_contents($f), true);
    if (is_array($j)) {
        $arm = $arm ?? $j['arm'];
        foreach ($j['persist'] as [$test, $ns, $pub]) {
            $calls[] = [$ns, $pub];
        }
    }
}
$pubNs = array_values(array_map(static fn(array $c): int => $c[0], array_filter($calls, static fn(array $c): bool => $c[1])));
echo json_encode(['arm' => $o['arm'], 'w3_arm' => $arm, 'run' => (int) $o['run'], 'exit' => (int) $o['exit'], 'calls' => count($calls), 'pub_ns' => $pubNs, 'all_ns' => array_map(static fn(array $c): int => $c[0], $calls)], JSON_UNESCAPED_SLASHES), "\n";
