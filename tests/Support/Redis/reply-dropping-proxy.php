<?php

declare(strict_types=1);

/**
 * Test support of the #261 study: a TCP proxy between one client and Redis that, on request, loses the reply of the request save at the socket.
 *
 * usage: php reply-dropping-proxy.php <redis host> <redis port> [drop-commit]
 *
 * It listens on a free port of 127.0.0.1 and prints that port on the first line of its output. It serves one client connection at a time and
 * forwards bytes both ways. With `drop-commit`, once the bytes the client sent contain the request commit script (not the read-only verify
 * call), it keeps forwarding that request to Redis, waits for Redis to answer, discards the answer and closes the client side: Redis applied
 * the save, the client's reply was lost on the wire, with no cooperation of the client library or the store. Whatever the client sends or
 * receives before that point is forwarded unchanged.
 */

$upstreamHost = $argv[1] ?? '127.0.0.1';
$upstreamPort = (int) ($argv[2] ?? 6379);
$dropCommit = ($argv[3] ?? '') === 'drop-commit';

$errorCode = 0;
$errorMessage = '';
$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($server === false) {
    fwrite(STDERR, 'listen failed (' . print_r($errorCode, true) . '): ' . print_r($errorMessage, true) . "\n");
    exit(1);
}
echo explode(':', (string) stream_socket_get_name($server, false))[1], "\n";
flush();

/**
 * Removes and returns the first complete RESP command of the buffer, or null when it is not complete yet.
 *
 * @return list<string>|null
 */
function nextCommand(string &$buffer): ?array
{
    if ($buffer === '' || $buffer[0] !== '*') {
        $buffer = '';

        return null;
    }
    $position = strpos($buffer, "\r\n");
    if ($position === false) {
        return null;
    }
    $count = (int) substr($buffer, 1, $position - 1);
    $offset = $position + 2;
    $arguments = [];
    for ($index = 0; $index < $count; $index++) {
        $end = strpos($buffer, "\r\n", $offset);
        if ($end === false) {
            return null;
        }
        $length = (int) substr($buffer, $offset + 1, $end - $offset - 1);
        if (strlen($buffer) < $end + 2 + $length + 2) {
            return null;
        }
        $arguments[] = substr($buffer, $end + 2, $length);
        $offset = $end + 2 + $length + 2;
    }
    $buffer = substr($buffer, $offset);

    return $arguments;
}

$dropsLeft = $dropCommit ? 1 : 0;
while (true) {
    $client = @stream_socket_accept($server, 30);
    if ($client === false) {
        exit(0);
    }
    $upstream = stream_socket_client("tcp://{$upstreamHost}:{$upstreamPort}", $errorCode, $errorMessage, 5);
    if ($upstream === false) {
        fclose($client);
        continue;
    }
    $sent = '';
    $armed = false;
    while (true) {
        $read = [$client, $upstream];
        $write = $except = null;
        $ready = @stream_select($read, $write, $except, 30);
        if ($ready === false || $ready === 0) {
            break;
        }
        foreach ($read as $stream) {
            $data = fread($stream, 65536);
            if ($data === false || $data === '') {
                break 2;
            }
            if ($stream === $client) {
                fwrite($upstream, $data);
                if ($dropsLeft > 0) {
                    $sent .= $data;
                    while (($command = nextCommand($sent)) !== null) {
                        $text = $command[1] ?? '';
                        if (($command[0] ?? '') === 'EVAL' && str_contains($text, 'request commit') && end($command) !== 'verify') {
                            $armed = true;
                        }
                    }
                }
            } elseif ($armed && $dropsLeft > 0) {
                // Redis answered the save: the answer is discarded and the client side is closed.
                $dropsLeft--;
                fclose($client);
                break 2;
            } else {
                fwrite($client, $data);
            }
        }
    }
    @fclose($client);
    @fclose($upstream);
}
