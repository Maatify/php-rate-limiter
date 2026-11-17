<?php

declare(strict_types=1);

// Measured round trip to an endpoint of the paired p99 measurement (#210, second round): N sequential PINGs on one connection.
//   php rtt-probe.php <host> <port> <label> [n=300]
// Prints one JSON line: label, n, min, p50, p95, p99, max in milliseconds (nearest rank).

[, $host, $port, $label] = array_pad($_SERVER['argv'] ?? [], 4, '');
$n = (int) (($_SERVER['argv'][4] ?? 300));
$socket = stream_socket_client("tcp://{$host}:{$port}", $errno, $error, 5);
if ($socket === false) {
    fwrite(STDERR, "connect failed: {$error}\n");
    exit(1);
}
$ping = "*1\r\n\$4\r\nPING\r\n";
$samples = [];
for ($i = 0; $i < $n + 20; $i++) {
    $started = hrtime(true);
    fwrite($socket, $ping);
    $line = fgets($socket);
    if ($line !== "+PONG\r\n") {
        fwrite(STDERR, "unexpected reply\n");
        exit(1);
    }
    if ($i >= 20) {
        $samples[] = (hrtime(true) - $started) / 1_000_000;
    }
}
sort($samples);
$rank = static fn(float $p): float => round($samples[max(0, (int) ceil($p / 100 * count($samples)) - 1)], 3);
echo json_encode(['label' => $label, 'endpoint' => $host . ':' . $port, 'n' => count($samples), 'minMs' => round($samples[0], 3), 'p50Ms' => $rank(50), 'p95Ms' => $rank(95), 'p99Ms' => $rank(99), 'maxMs' => round($samples[count($samples) - 1], 3)], JSON_THROW_ON_ERROR), "\n";
