<?php

declare(strict_types=1);

// A TCP proxy in front of one Redis for the EVALSHA study (#210): a fixed one-way delay on each direction, and optional fault injection.
//   php delay-proxy.php <listen-port> <redis-host> <redis-port> <one-way-delay-ms> [--drop-request-on-script=N] [--drop-reply-on-script=N]
// A "script command" is an EVAL or EVALSHA command seen on the client-to-server direction, counted per proxy process.
//   --drop-request-on-script=N  close both sockets instead of forwarding the Nth script command (the server never sees it)
//   --drop-reply-on-script=N    forward the Nth script command, then close both sockets and discard its reply (the server executed it)
// Listens on 127.0.0.1 only.

$args = array_slice($_SERVER['argv'] ?? [], 1);
if (count($args) < 4) {
    fwrite(STDERR, "usage: delay-proxy.php <listen-port> <redis-host> <redis-port> <one-way-delay-ms> [faults]\n");
    exit(2);
}
[$listen, $redisHost, $redisPort, $delayMs] = [(int) $args[0], $args[1], (int) $args[2], (float) $args[3]];
$dropRequest = $dropReply = 0;
foreach (array_slice($args, 4) as $flag) {
    if (str_starts_with($flag, '--drop-request-on-script=')) {
        $dropRequest = (int) substr($flag, strlen('--drop-request-on-script='));
    } elseif (str_starts_with($flag, '--drop-reply-on-script=')) {
        $dropReply = (int) substr($flag, strlen('--drop-reply-on-script='));
    }
}

$server = stream_socket_server("tcp://127.0.0.1:{$listen}", $errno, $error);
if ($server === false) {
    fwrite(STDERR, "listen failed: {$error}\n");
    exit(1);
}
$delay = $delayMs / 1000.0;
$scripts = 0;
/** @var array<int, array{client: resource, upstream: resource, queue: list<array{float, string, string}>, discard: bool}> $conns */
$conns = [];
$next = 0;

$close = static function (int $id) use (&$conns): void {
    if (isset($conns[$id])) {
        @fclose($conns[$id]['client']);
        @fclose($conns[$id]['upstream']);
        unset($conns[$id]);
    }
};

while (true) {
    $read = [$server];
    foreach ($conns as $conn) {
        $read[] = $conn['client'];
        $read[] = $conn['upstream'];
    }
    $due = null;
    foreach ($conns as $conn) {
        if ($conn['queue'] !== []) {
            $due = $due === null ? $conn['queue'][0][0] : min($due, $conn['queue'][0][0]);
        }
    }
    $timeout = $due === null ? 1.0 : max(0.0, $due - microtime(true));
    $write = $except = null;
    $changed = @stream_select($read, $write, $except, (int) $timeout, (int) (($timeout - (int) $timeout) * 1_000_000));
    if ($changed !== false) {
        foreach ($read as $stream) {
            if ($stream === $server) {
                $client = stream_socket_accept($server, 0);
                if ($client === false) {
                    continue;
                }
                $upstream = @stream_socket_client("tcp://{$redisHost}:{$redisPort}", $e1, $e2, 2);
                if ($upstream === false) {
                    fclose($client);
                    continue;
                }
                stream_set_blocking($client, false);
                stream_set_blocking($upstream, false);
                $conns[++$next] = ['client' => $client, 'upstream' => $upstream, 'queue' => [], 'discard' => false];
                continue;
            }
            foreach ($conns as $id => $conn) {
                if ($stream !== $conn['client'] && $stream !== $conn['upstream']) {
                    continue;
                }
                $data = fread($stream, 65536);
                if ($data === '' || $data === false) {
                    if (feof($stream)) {
                        $close($id);
                    }
                    break;
                }
                if ($stream === $conn['client']) {
                    if (preg_match('/\r\n\$\d+\r\n(EVAL|EVALSHA)\r\n/i', $data) === 1) {
                        $scripts++;
                        if ($dropRequest !== 0 && $scripts === $dropRequest) {
                            $close($id);
                            break;
                        }
                        if ($dropReply !== 0 && $scripts === $dropReply) {
                            $conns[$id]['discard'] = true;
                        }
                    }
                    $conns[$id]['queue'][] = [microtime(true) + $delay, 'up', $data];
                } elseif (! $conn['discard']) {
                    $conns[$id]['queue'][] = [microtime(true) + $delay, 'down', $data];
                } else {
                    // the reply of the dropped-reply script: the server executed, the client never sees it
                    $close($id);
                }
                break;
            }
        }
    }
    $now = microtime(true);
    foreach ($conns as $id => $conn) {
        while ($conns[$id]['queue'] !== [] && $conns[$id]['queue'][0][0] <= $now) {
            [, $direction, $data] = array_shift($conns[$id]['queue']);
            $target = $direction === 'up' ? $conn['upstream'] : $conn['client'];
            $offset = 0;
            while ($offset < strlen($data)) {
                $written = @fwrite($target, substr($data, $offset));
                if ($written === false || $written === 0) {
                    usleep(100);
                    $written = (int) $written;
                }
                $offset += $written;
            }
        }
    }
}
