<?php

declare(strict_types=1);

// #160 phase 2 arm A per-run verdict (PLAN.md §4.1, §8). Prints one JSON line.
//
// Usage: php a_check.php --arm=<ref|arec|arate> --id=<n> --dir=<run dir> --exit=<phpunit status>
//          --redis-port=<p> --tapped=<0|1> --wall=<seconds>
//
// Role mapping (recorded in the REPORT with its smoke evidence): the store keys are
// PREFIX:sha256(ns):<family>:sha256(logical) with ns = 'it-known-device-allowance-' . <run>,
// run read from the test's first command (SET test:known-device-allowance-foreign:<run>).
//   AUX  = budget of the known_device_allowance aux key     (U = BUDGET_INCREMENT / BUDGET_SEED on it)
//   K5   = score of the K5 key                               (K = SCORE_INCREMENT on it, amount 2)
//   K4, BLK4, BLK5 = the other keys of the pipeline, recorded in the signature only.
// A worker = a pid with one barrier INCR; its outcome = the value of its outcome SET.

namespace Flaky160B;

require __DIR__ . '/../../harness/lib.php';
require __DIR__ . '/../../../../vendor/autoload.php';

use function Flaky157\junitCases;
use function Flaky157\readJsonl;

const STORE = 'Maatify\\RateLimiter\\Repository\\Redis\\RedisFullCapabilityStore';
const WORKERS_PER_WAVE = 16;
const TEST_CLASS_SUFFIX = 'KnownDeviceAllowanceConcurrencyIntegrationTest';
const TEST_METHOD = 'testLoginOverlapSplitsIntoRecordedConflictAndEarlyBlockExactly';

/** @return array<string, string> sha1 of each store script constant => constant name */
function scriptNames(): array
{
    $names = [];
    foreach ((new \ReflectionClass(STORE))->getReflectionConstants() as $constant) {
        $value = $constant->getValue();
        if (is_string($value) && strlen($value) > 60) {
            $names[sha1($value)] = $constant->getName();
        }
    }
    return $names;
}

/** @return array{roles: array<string, string>, run: string}|null */
function roleMap(array $commands): ?array
{
    $run = null;
    foreach ($commands as $command) {
        if ($command['kind'] === 'SET' && isset($command['args'][1]) && str_starts_with($command['args'][1], 'test:known-device-allowance-foreign:')) {
            $run = substr($command['args'][1], strlen('test:known-device-allowance-foreign:'));
            break;
        }
    }
    if ($run === null) {
        return null;
    }
    $prefix = 'maatify:rate-limiter:v1:' . hash('sha256', 'it-known-device-allowance-' . $run) . ':';
    $hmac = static fn(string $message): string => hash_hmac('sha256', $message, 'test_secret');
    $aux = $hmac('login_protection:rate_limiter:aux:known_device_allowance:v1:prod:login-owner');
    $k5 = $hmac('login_protection:rate_limiter:k5:v2:prod:login-owner:concurrent-fp');
    $k4 = $hmac('login_protection:rate_limiter:k4:v2:prod:login-owner');
    return ['run' => $run, 'roles' => [
        $prefix . 'budget:' . hash('sha256', $aux) => 'AUX',
        $prefix . 'score:' . hash('sha256', $k5) => 'K5',
        $prefix . 'score:' . hash('sha256', $k4) => 'K4',
        $prefix . 'block:' . hash('sha256', $k5) => 'BLK5',
        $prefix . 'block:' . hash('sha256', $k4) => 'BLK4',
    ]];
}

$options = getopt('', ['arm:', 'id:', 'dir:', 'exit:', 'redis-port:', 'tapped:', 'wall:']);
$dir = (string) $options['dir'];
$tapped = $options['tapped'] === '1';
$verdict = [
    'arm' => (string) $options['arm'],
    'id' => (int) $options['id'],
    'exit' => (int) $options['exit'],
    'redis_port' => (int) $options['redis-port'],
    'v_port' => (int) $options['redis-port'] !== 6379,
    'tapped' => $tapped,
    'wall_seconds' => (float) $options['wall'],
];
$problems = [];

// --- junit ------------------------------------------------------------------
$junit = $dir . '/junit.xml';
$cases = is_file($junit) ? junitCases($junit) : [];
$target = null;
foreach ($cases as $case) {
    if ($case['name'] === TEST_METHOD && str_ends_with($case['class'], TEST_CLASS_SUFFIX)) {
        $target = $case;
    }
}
$verdict['tests'] = count($cases);
$verdict['test'] = $target === null ? 'missing' : ($target['failure'] === null ? 'pass' : 'fail');
if ($target === null || count($cases) !== 1) {
    $problems[] = 'junit_shape';
}
if ($target !== null && $target['failure'] !== null) {
    preg_match_all('/' . TEST_CLASS_SUFFIX . '\.php:(\d+)/', $target['failure'], $lines);
    preg_match('/Failed asserting that (.+)\./', $target['failure'], $message);
    $verdict['failure'] = ['lines' => array_map('intval', $lines[1]), 'message' => $message[1] ?? strtok($target['failure'], "\n")];
}

// --- tap log ------------------------------------------------------------------
if ($tapped) {
    $rows = readJsonl($dir . '/tap.jsonl');
    $summary = null;
    $commands = [];
    $connections = [];
    foreach ($rows as $row) {
        match ($row['t']) {
            'summary' => $summary = $row,
            'cmd' => $commands[] = $row,
            'conn' => $connections[$row['conn']] = $row,
            'parse_error' => $problems[] = 'parse_error:' . $row['side'],
            default => null,
        };
    }
    usort($commands, static fn(array $a, array $b): int => $a['seq'] <=> $b['seq']);
    // V-FRAME
    if ($summary === null) {
        $problems[] = 'no_tap_summary';
    } else {
        foreach (['unmatched_commands', 'unmatched_replies', 'parse_errors'] as $counter) {
            if ($summary[$counter] !== 0) {
                $problems[] = 'v_frame:' . $counter . '=' . $summary[$counter];
            }
        }
        if ($summary['commands'] !== $summary['replies'] || $summary['records'] !== count($commands) || $summary['connections'] !== count($connections) || $summary['commands'] !== count($commands)) {
            $problems[] = 'v_frame:counts';
        }
        $seqs = array_column($commands, 'seq');
        if ($seqs !== range(1, count($seqs))) {
            $problems[] = 'v_frame:seq_gap';
        }
        foreach ($commands as $command) {
            if (! array_key_exists('reply', $command) || ($command['reply_missing'] ?? false)) {
                $problems[] = 'v_frame:reply_missing';
                break;
            }
        }
    }
    // V-WORKER: every connection has one pid
    $unresolved = count(array_filter($connections, static fn(array $c): bool => $c['pid'] === null));
    $verdict['unresolved_pids'] = $unresolved;
    if ($unresolved > 0) {
        $problems[] = 'v_worker:pid_unresolved';
    }

    $roleMap = roleMap($commands);
    $names = scriptNames();
    if ($roleMap === null) {
        $problems[] = 'no_run_id';
        $roleMap = ['run' => '', 'roles' => []];
    }
    $parent = $commands[0]['pid'] ?? null;
    $byPid = [];
    foreach ($commands as $command) {
        $byPid[$command['pid'] ?? 'null'][] = $command;
    }
    $workers = [];
    foreach ($byPid as $pid => $list) {
        $barrier = null;
        $outcome = null;
        foreach ($list as $command) {
            if ($command['kind'] === 'INCR' && str_contains($command['args'][1] ?? '', ':barrier')) {
                $barrier = str_contains($command['args'][1], ':first:') ? 1 : (str_contains($command['args'][1], ':second:') ? 2 : 0);
            }
            if ($command['kind'] === 'SET' && str_contains($command['args'][1] ?? '', ':outcome:')) {
                $outcome = $command['args'][2] ?? null;
                $outcomeWave = str_contains($command['args'][1], ':first:') ? 1 : 2;
                $outcomeIndex = (int) substr($command['args'][1], strrpos($command['args'][1], ':') + 1);
            }
        }
        if ($barrier === null) {
            continue;
        }
        $u = 0;
        $k = 0;
        $signature = [];
        $unknownK5 = [];
        foreach ($list as $command) {
            if ($command['args'][0] !== 'EVAL') {
                continue;
            }
            $count = (int) $command['args'][2];
            $keys = array_slice($command['args'], 3, $count);
            $name = $names[substr($command['args'][1], 5)] ?? '?';
            $roles = [];
            foreach ($keys as $key) {
                if (isset($roleMap['roles'][$key])) {
                    $roles[] = $roleMap['roles'][$key];
                }
            }
            if ($roles === []) {
                continue;
            }
            $arguments = array_slice($command['args'], 3 + $count);
            if (in_array('AUX', $roles, true) && in_array($name, ['BUDGET_INCREMENT', 'BUDGET_SEED'], true)) {
                $u++;
            }
            if (in_array('K5', $roles, true) && $name === 'SCORE_INCREMENT' && ($arguments[1] ?? null) === '2') {
                $k++;
            }
            if (in_array('K5', $roles, true) && ! in_array($name, ['SCORE_INCREMENT', 'SCORE_GET'], true)) {
                $unknownK5[] = $name;
            }
            $signature[] = $name . ':' . implode('+', $roles);
        }
        $workers[$pid] = ['wave' => $barrier, 'outcome' => $outcome, 'index' => $outcomeIndex ?? null, 'u' => $u, 'k' => $k, 'signature' => $signature, 'unknown_k5' => $unknownK5];
    }
    // V-WORKER: 16 per wave, one outcome each, indexes 0..15
    foreach ([1, 2] as $wave) {
        $inWave = array_filter($workers, static fn(array $w): bool => $w['wave'] === $wave);
        if (count($inWave) !== WORKERS_PER_WAVE) {
            $problems[] = "v_worker:wave{$wave}_count=" . count($inWave);
        }
        $indexes = array_column($inWave, 'index');
        sort($indexes);
        if ($indexes !== range(0, WORKERS_PER_WAVE - 1)) {
            $problems[] = "v_worker:wave{$wave}_outcomes";
        }
    }
    // V-KEY: the parent's last read of AUX and K5 equals the sum of the workers' U and 2 * K
    $consumed = null;
    $k5Final = null;
    foreach ($byPid[$parent] ?? [] as $command) {
        if ($command['args'][0] !== 'EVAL') {
            continue;
        }
        $count = (int) $command['args'][2];
        $keys = array_slice($command['args'], 3, $count);
        $name = $names[substr($command['args'][1], 5)] ?? '?';
        if ($name === 'BUDGET_GET' && ($roleMap['roles'][$keys[0] ?? ''] ?? null) === 'AUX' && is_array($command['reply'])) {
            $consumed = (int) $command['reply'][0];
        }
        if ($name === 'SCORE_GET' && ($roleMap['roles'][$keys[0] ?? ''] ?? null) === 'K5' && is_array($command['reply'])) {
            $k5Final = (int) $command['reply'][0];
        }
    }
    $sumU = array_sum(array_column($workers, 'u'));
    $sumK = array_sum(array_column($workers, 'k'));
    $verdict['v_key'] = ['consumed_read' => $consumed, 'sum_u' => $sumU, 'k5_read' => $k5Final, 'sum_2k' => 2 * $sumK];
    if ($consumed === null || $consumed !== $sumU) {
        $problems[] = 'v_key:consumed';
    }
    if ($k5Final === null || $k5Final !== 2 * $sumK) {
        // an ended K5 read can legitimately differ only when the test failed on it; recorded, not an invalidation
        $verdict['v_key']['k5_differs'] = true;
    }
    // anomalies (registered prediction: a failing run has exactly one worker taking a unit without K5 and without conflict)
    $anomalies = [];
    foreach ($workers as $pid => $worker) {
        $expectedK = $worker['outcome'] === 'conflict' ? 0 : $worker['u'];
        if ($worker['u'] > 1) {
            $anomalies[] = ['pid' => $pid, 'class' => 'two_units', 'worker' => $worker];
        } elseif ($worker['k'] !== $expectedK) {
            $anomalies[] = ['pid' => $pid, 'class' => $worker['u'] === 1 && $worker['k'] === 0 ? 'unit_without_k5_no_conflict' : 'k5_without_unit', 'worker' => $worker];
        }
    }
    $verdict['workers'] = array_map(static fn(array $w): string => $w['wave'] . ':' . ($w['outcome'] ?? '?') . ':' . $w['u'] . '/' . $w['k'], array_values($workers));
    $verdict['anomalies'] = $anomalies;
    $verdict['conflicts'] = count(array_filter($workers, static fn(array $w): bool => $w['outcome'] === 'conflict'));
    $verdict['unknown_k5_scripts'] = array_values(array_unique(array_merge(...array_column($workers, 'unknown_k5') ?: [[]])));
    $verdict['tap'] = $summary === null ? null : array_intersect_key($summary, array_flip(['connections', 'commands', 'replies', 'records', 'open_at_stop']));
}
$verdict['problems'] = $problems;
$verdict['valid'] = $problems === [] && $verdict['v_port'];
echo json_encode($verdict, JSON_UNESCAPED_SLASHES), "\n";
