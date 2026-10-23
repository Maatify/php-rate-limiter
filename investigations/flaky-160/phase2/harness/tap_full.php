<?php
// PROVENANCE: copy of investigations/flaky-157/harness/tap.php at 8b79e9b, sha256 59abc0df77c31336c17b833cf82c1a39f435cef22036bb2bef741bbae0bc1a41, ADAPTED (#160 phase 2 LT-160B-01/03) by PROVENANCE-tap.diff; delete this line and reverse that diff to get the original byte for byte.

declare(strict_types=1);

// #157 RESP tap (PLAN.md §5.1, §9.1 LT-157-04, §9.2 V-FRAME).
//
// Forwards every byte between PHPUnit and Redis unchanged, one upstream
// connection per client connection. It parses every command and every reply
// to pair them (V-FRAME), and writes one JSONL record per command that names
// an R1 key or touches the whole keyspace. Before forwarding the MUTATE of an
// R1 key it reads PTTL / EXISTS / TIME / DBSIZE / INFO on its own connection;
// in mode del:<case> it then deletes that case's key (arm D).
//
// Usage: php tap.php --autoload=<vendor/autoload.php> --upstream-port=<p>
//          --port-file=<f> --log=<jsonl> [--mode=record|del:<0|1|2>]
//          [--fault=drop|reorder]   (detector demonstration only: alters the log, never the stream)

namespace Flaky157;

require __DIR__ . '/../../harness/lib.php';

$options = getopt('', ['autoload:', 'upstream-port:', 'port-file:', 'log:', 'mode:', 'fault:']);
foreach (['autoload', 'upstream-port', 'port-file', 'log'] as $required) {
    if (! isset($options[$required]) || ! is_string($options[$required])) {
        fwrite(STDERR, "tap: --{$required} is required\n");
        exit(2);
    }
}
require $options['autoload'];

$upstreamPort = (int) $options['upstream-port'];
$mode = is_string($options['mode'] ?? null) ? $options['mode'] : 'record';
$deleteCase = preg_match('/\Adel:([012])\z/', $mode, $match) === 1 ? (int) $match[1] : null;
$full = $mode === 'full';
if ($mode !== 'record' && $mode !== 'full' && $deleteCase === null) {
    fwrite(STDERR, "tap: unknown mode {$mode}\n");
    exit(2);
}
$fault = is_string($options['fault'] ?? null) ? $options['fault'] : 'none';

$keys = r1Keys();
$keyIndex = array_flip($keys);
$shas = scriptShas();

$log = fopen($options['log'], 'ab');
$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($log === false || $server === false) {
    fwrite(STDERR, "tap: cannot start: {$errorMessage}\n");
    exit(1);
}
stream_set_blocking($server, false);
$listenPort = (int) explode(':', (string) stream_socket_get_name($server, false))[1];
writeJsonl($log, ['t' => 'start', 'listen' => $listenPort, 'upstream' => $upstreamPort, 'mode' => $mode, 'fault' => $fault, 'keys' => $keys, 'shas' => $shas, 'pid' => getmypid(), 'wall' => microtime(true)]);
file_put_contents($options['port-file'], (string) $listenPort);

$stop = false;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use (&$stop): void { $stop = true; });
pcntl_signal(SIGINT, static function () use (&$stop): void { $stop = true; });

/** @var array<int, array<string, mixed>> $connections */
$connections = [];
/** @var array<int, array{0: int, 1: string}> $bySocket */
$bySocket = [];
$nextConnection = 1;
$sequence = 0;
$counters = ['connections' => 0, 'commands' => 0, 'replies' => 0, 'unmatched_commands' => 0, 'unmatched_replies' => 0, 'parse_errors' => 0, 'records' => 0, 'probes' => 0];
$side = null;
$faultArmed = $fault !== 'none';
$heldRecord = null;

function writeAll($socket, string $data): bool
{
    $length = strlen($data);
    $written = 0;
    while ($written < $length) {
        $result = @fwrite($socket, substr($data, $written));
        if ($result === false) {
            return false;
        }
        if ($result === 0) {
            $read = null;
            $write = [$socket];
            $except = null;
            if (@stream_select($read, $write, $except, 1) === false) {
                return false;
            }
            continue;
        }
        $written += $result;
    }
    return true;
}

/**
 * #160 phase 2 (LT-160B-01/03): the pid(s) holding the client end of a connection
 * accepted from 127.0.0.1:$clientPort. Linux: /proc/net/tcp inode, then /proc/<pid>/fd.
 * Elsewhere (local smoke only): lsof, own pid excluded.
 *
 * @return list<int>
 */
function resolvePids(int $clientPort, int $listenPort): array
{
    $pids = [];
    if (is_readable('/proc/net/tcp')) {
        $inode = null;
        foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $table) {
            foreach (@file($table, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $fields = preg_split('/\s+/', trim($line));
                if (count($fields) < 10 || ! str_contains($fields[1], ':') || ! str_contains($fields[2], ':')) {
                    continue;
                }
                if (hexdec(explode(':', $fields[1])[1]) === $clientPort && hexdec(explode(':', $fields[2])[1]) === $listenPort) {
                    $inode = $fields[9];
                    break 2;
                }
            }
        }
        if ($inode === null || $inode === '0') {
            return [];
        }
        foreach (glob('/proc/[0-9]*/fd/*') ?: [] as $fd) {
            if (@readlink($fd) === 'socket:[' . $inode . ']') {
                $pids[(int) explode('/', $fd)[2]] = true;
            }
        }
        return array_keys($pids);
    }
    $output = [];
    @exec('lsof -nP -iTCP@127.0.0.1:' . $clientPort . ' -sTCP:ESTABLISHED -Fpn 2>/dev/null', $output);
    $current = null;
    foreach ($output as $line) {
        if (isset($line[0]) && $line[0] === 'p') {
            $current = (int) substr($line, 1);
        } elseif (isset($line[0]) && $line[0] === 'n' && $current !== null && $current !== getmypid() && str_starts_with(substr($line, 1), '127.0.0.1:' . $clientPort . '->')) {
            $pids[$current] = true;
        }
    }
    return array_keys($pids);
}

function shorten(mixed $value): mixed
{
    if (is_string($value)) {
        return strlen($value) > 200 ? substr($value, 0, 200) . '…(' . strlen($value) . ')' : $value;
    }
    if (is_array($value)) {
        return array_map(shorten(...), $value);
    }
    return $value;
}

$emit = static function (array $record) use ($log, &$counters, &$faultArmed, &$heldRecord, $fault): void {
    // Detector demonstration only (PLAN §10): corrupt the LOG of case 0, never the stream.
    if ($faultArmed && ($record['key'] ?? null) === 0) {
        if ($fault === 'drop' && $record['kind'] === 'READ') {
            $faultArmed = false;
            return;
        }
        if ($fault === 'reorder' && $record['kind'] === 'READ') {
            $heldRecord = $record;
            return;
        }
        if ($fault === 'reorder' && $record['kind'] === 'MUTATE' && $heldRecord !== null) {
            [$record['seq'], $heldRecord['seq']] = [$heldRecord['seq'], $record['seq']];
            writeJsonl($log, $record);
            writeJsonl($log, $heldRecord);
            $counters['records'] += 2;
            $faultArmed = false;
            $heldRecord = null;
            return;
        }
    }
    writeJsonl($log, $record);
    $counters['records']++;
};

$closeConnection = static function (int $id) use (&$connections, &$bySocket, &$counters, $emit): void {
    if (! isset($connections[$id])) {
        return;
    }
    $connection = $connections[$id];
    foreach ($connection['pending'] as $entry) {
        $counters['unmatched_commands']++;
        if (is_array($entry) && $entry['record'] !== null) {
            $entry['record']['reply_missing'] = true;
            $emit($entry['record']);
        }
    }
    foreach (['client', 'up'] as $sideName) {
        unset($bySocket[(int) $connection[$sideName]]);
        @fclose($connection[$sideName]);
    }
    unset($connections[$id]);
};

$handleCommand = static function (int $id, array $argv, string $raw) use (&$connections, &$sequence, &$counters, &$side, $keyIndex, $keys, $shas, $deleteCase, $upstreamPort, $emit, $full): bool {
    $counters['commands']++;
    $name = strtoupper($argv[0]);
    $kind = $name;
    $logged = $argv;
    $keyArguments = array_slice($argv, 1);
    if (($name === 'EVAL' || $name === 'EVALSHA') && count($argv) >= 3) {
        $sha = $name === 'EVAL' ? sha1($argv[1]) : strtolower($argv[1]);
        $kind = $shas[$sha] ?? $name;
        $keyArguments = array_slice($argv, 3, max(0, (int) $argv[2]));
        $logged[1] = 'sha1:' . $sha;
    }
    $case = null;
    foreach ($keyArguments as $argument) {
        if (isset($keyIndex[$argument])) {
            $case = $keyIndex[$argument];
            break;
        }
    }
    $keyspace = in_array($name, KEYSPACE_COMMANDS, true);
    $record = null;
    if ($case !== null || $keyspace || $full) {
        $record = [
            't' => 'cmd',
            'seq' => ++$sequence,
            'conn' => $id,
            'pid' => $connections[$id]['pid'],
            'ns' => hrtime(true),
            'wall' => microtime(true),
            'kind' => $kind,
            'key' => $case,
            'args' => shorten($logged),
        ];
        if ($case !== null && $kind === 'HSET' && $connections[$id]['last_time'] !== null) {
            $record['t0'] = $connections[$id]['last_time'];
        }
        if ($case !== null && $kind === 'MUTATE') {
            $side ??= new Client('127.0.0.1', $upstreamPort);
            $start = hrtime(true);
            $server = $side->info('server');
            $stats = $side->info('stats');
            $record['probe'] = [
                'pttl' => $side->call('PTTL', $keys[$case]),
                'exists' => $side->call('EXISTS', $keys[$case]),
                'time' => $side->call('TIME'),
                'dbsize' => $side->call('DBSIZE'),
                'run_id' => $server['run_id'] ?? null,
                'uptime_in_seconds' => (int) ($server['uptime_in_seconds'] ?? -1),
                'expired_keys' => (int) ($stats['expired_keys'] ?? -1),
                'evicted_keys' => (int) ($stats['evicted_keys'] ?? -1),
            ];
            if ($deleteCase === $case) {
                $record['action'] = ['del' => $side->call('DEL', $keys[$case]), 'pttl_after' => $side->call('PTTL', $keys[$case])];
            }
            $record['probe_ns'] = hrtime(true) - $start;
            $counters['probes']++;
        }
    }
    $connections[$id]['pending'][] = ['record' => $record, 'time' => $name === 'TIME'];
    return writeAll($connections[$id]['up'], $raw);
};

$handleReply = static function (int $id, mixed $reply) use (&$connections, &$counters, $emit): void {
    $counters['replies']++;
    if ($connections[$id]['pending'] === []) {
        $counters['unmatched_replies']++;
        return;
    }
    $entry = array_shift($connections[$id]['pending']);
    if ($entry['time']) {
        $connections[$id]['last_time'] = ['reply' => $reply, 'ns' => hrtime(true)];
    }
    if ($entry['record'] !== null) {
        $record = $entry['record'];
        $record['reply'] = shorten($reply);
        $record['reply_ns'] = hrtime(true);
        $emit($record);
    }
};

$drainDeadline = null;
while (true) {
    if ($stop && $drainDeadline === null) {
        $drainDeadline = hrtime(true) + 1_000_000_000;
    }
    if ($drainDeadline !== null && ($connections === [] || hrtime(true) > $drainDeadline)) {
        break;
    }
    $read = $drainDeadline === null ? [$server] : [];
    foreach ($connections as $connection) {
        $read[] = $connection['client'];
        $read[] = $connection['up'];
    }
    $write = null;
    $except = null;
    if ($read === [] || @stream_select($read, $write, $except, 0, 200_000) === false) {
        continue;
    }
    foreach ($read as $socket) {
        if ($socket === $server) {
            $client = @stream_socket_accept($server, 0);
            if ($client === false) {
                continue;
            }
            $up = stream_socket_client("tcp://127.0.0.1:{$upstreamPort}", $code, $message, 5);
            if ($up === false) {
                fclose($client);
                continue;
            }
            stream_set_blocking($client, false);
            stream_set_blocking($up, false);
            $id = $nextConnection++;
            $counters['connections']++;
            $peer = (string) stream_socket_get_name($client, true);
            $holders = resolvePids((int) substr($peer, (int) strrpos($peer, ':') + 1), $listenPort);
            $pid = count($holders) === 1 ? $holders[0] : null;
            writeJsonl($log, ['t' => 'conn', 'conn' => $id, 'pid' => $pid, 'holders' => $holders, 'peer' => $peer, 'wall' => microtime(true)]);
            $connections[$id] = ['client' => $client, 'up' => $up, 'cbuf' => '', 'sbuf' => '', 'pending' => [], 'pid' => $pid, 'last_time' => null, 'raw_in' => false, 'raw_out' => false];
            $bySocket[(int) $client] = [$id, 'client'];
            $bySocket[(int) $up] = [$id, 'up'];
            continue;
        }
        if (! isset($bySocket[(int) $socket])) {
            continue;
        }
        [$id, $sideName] = $bySocket[(int) $socket];
        $data = @fread($socket, 1 << 16);
        if ($data === false || $data === '') {
            if (feof($socket)) {
                $closeConnection($id);
            }
            continue;
        }
        if ($sideName === 'client') {
            $connections[$id]['cbuf'] .= $data;
            if ($connections[$id]['raw_in']) {
                writeAll($connections[$id]['up'], $connections[$id]['cbuf']);
                $connections[$id]['cbuf'] = '';
                continue;
            }
            $offset = 0;
            $buffer = $connections[$id]['cbuf'];
            try {
                while ($offset < strlen($buffer)) {
                    $parsed = parseCommand($buffer, $offset);
                    if ($parsed instanceof Incomplete) {
                        break;
                    }
                    if (! $handleCommand($id, $parsed[0], substr($buffer, $offset, $parsed[1] - $offset))) {
                        $closeConnection($id);
                        continue 2;
                    }
                    $offset = $parsed[1];
                }
                $connections[$id]['cbuf'] = substr($buffer, $offset);
            } catch (\UnexpectedValueException $exception) {
                $counters['parse_errors']++;
                writeJsonl($log, ['t' => 'parse_error', 'conn' => $id, 'side' => 'client', 'error' => $exception->getMessage()]);
                $connections[$id]['raw_in'] = true;
                writeAll($connections[$id]['up'], substr($buffer, $offset));
                $connections[$id]['cbuf'] = '';
            }
        } else {
            if (! writeAll($connections[$id]['client'], $data)) {
                $closeConnection($id);
                continue;
            }
            if ($connections[$id]['raw_out']) {
                continue;
            }
            $connections[$id]['sbuf'] .= $data;
            $buffer = $connections[$id]['sbuf'];
            $offset = 0;
            try {
                while ($offset < strlen($buffer)) {
                    $parsed = parseReply($buffer, $offset);
                    if ($parsed instanceof Incomplete) {
                        break;
                    }
                    $handleReply($id, $parsed[0]);
                    $offset = $parsed[1];
                }
                $connections[$id]['sbuf'] = substr($buffer, $offset);
            } catch (\UnexpectedValueException $exception) {
                $counters['parse_errors']++;
                writeJsonl($log, ['t' => 'parse_error', 'conn' => $id, 'side' => 'server', 'error' => $exception->getMessage()]);
                $connections[$id]['raw_out'] = true;
                $connections[$id]['sbuf'] = '';
            }
        }
    }
}

$stillOpen = count($connections);
foreach (array_keys($connections) as $id) {
    $closeConnection($id);
}
if ($heldRecord !== null) {
    writeJsonl($log, $heldRecord);
}
writeJsonl($log, ['t' => 'summary', 'open_at_stop' => $stillOpen, 'wall' => microtime(true)] + $counters);
fclose($log);
