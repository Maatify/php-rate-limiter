<?php

declare(strict_types=1);

// Latency of one command by request size through an endpoint (#210, second round): EVAL of a script padded to 0 to 60 KB, 60 sequential calls each.
// It showed the Nagle / delayed-ACK stall of the first-round proxy on Linux (about 40 ms above 8 KB) and its absence with TCP_NODELAY.
//   php payload-latency-probe.php <host> <port>
[, $host, $port] = $argv;
function cmd($s, array $a) { $p = '*'.count($a)."\r\n"; foreach ($a as $x) $p .= '$'.strlen($x)."\r\n".$x."\r\n"; fwrite($s, $p); return fgets($s); }
foreach ([0, 4000, 13000, 20000, 60000] as $pad) {
  $s = stream_socket_client("tcp://$host:$port"); $script = 'return 1 --' . str_repeat('x', $pad);
  $t = []; for ($i=0;$i<60;$i++) { $a=hrtime(true); cmd($s, ['EVAL',$script,'0']); $t[] = (hrtime(true)-$a)/1e6; }
  sort($t); printf("pad %5d: p50 %.2f ms p95 %.2f ms\n", $pad, $t[30], $t[56]);
}
