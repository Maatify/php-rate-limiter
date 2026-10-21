<?php

declare(strict_types=1);

/*
 * #153 LT-153-07 cell runner (PLAN.md §5, §12, §13).
 * argv: package root, env (E-SAME | E-DOCKER), Redis host, Redis port, output dir,
 *       family, policy id, sigma (seconds, or AA), pairs [, scale].
 * One cell = one family × policy × sigma, as N pairs of twins in two fresh
 * namespaces. Flows are generators that yield the host time to resume at.
 */

namespace Maatify\RateLimiter\Investigations\ClockMixes153;

use Maatify\RateLimiter\Investigations\ClockSkew153 as P1;

[$self, $root, $env, $host, $port, $out, $family, $policyId, $sigmaArg, $pairs, $scale] = $argv + array_fill(0, 11, null);
$scale = (float) ($scale ?? '1');
require $root . '/vendor/autoload.php';
require $root . '/investigations/clock-skew-153/harness/lib.php';
require __DIR__ . '/mixlib.php';

$port = (int) $port;
$aa = $sigmaArg === 'AA';
$sigma = $aa ? 0.0 : (float) $sigmaArg;
$policy = POLICIES[$policyId] ?? throw new \InvalidArgumentException('policy ' . $policyId);
$pairs = max(1, (int) round((int) $pairs * $scale));
$cell = $family . '_' . $policyId . '_' . ($aa ? 'AA' : sprintf('%+.2f', $sigma));
$run = bin2hex(random_bytes(4));
// Smoke runs only: a cap on stream durations (written into meta; report.php refuses a capped cell).
$cap = (float) (getenv('MIX_SMOKE_CAP') ?: 0);

@mkdir($out, 0777, true);
$handle = fopen($out . '/' . $cell . '.jsonl', 'wb');
// θ = Redis − host (median of 51 TIME round trips): probes are placed in Redis time (PLAN §5.3).
$probe = new P1\RedisTime(new \Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor($host, $port));
$thetas = [];
for ($i = 0; $i < 51; $i++) {
    [$send, $redisAt, $recv] = $probe->probe();
    $thetas[] = $redisAt - ($send + $recv) / 2;
}
sort($thetas);
$theta = $thetas[25];
P1\writeRow($handle, ['meta' => ['cell' => $cell, 'env' => $env, 'family' => $family, 'policy' => $policy, 'sigma' => $aa ? 'AA' : $sigma, 'pairs' => $pairs, 'scale' => $scale, 'cap' => $cap, 'theta' => $theta, 'run' => $run, 'php' => PHP_VERSION, 'started' => microtime(true)]]);

// Twins: 'S' carries sigma, 'Z' is the sigma = 0 reference (PLAN §4.4). Namespaces allow [A-Za-z0-9._:-] only.
$ns = 'm153-' . $run . '-' . strtr($cell, ['+' => 'p']);
$twinS = new Twin($host, $port, $ns . '-S', $sigma);
$twin0 = new Twin($host, $port, $ns . '-Z', 0.0);

/** Writes one step row; keeps all comparison events on probe steps, flips and per-site counts otherwise. */
$emit = static function (int $j, string $twin, int $k, array $row, float $due, bool $probe, array $extra = []) use ($handle): void {
    $counts = [];
    $flips = [];
    foreach ($row['c'] as $c) {
        $counts[$c['s']][0] = ($counts[$c['s']][0] ?? 0) + 1;
        if ($c['flip']) {
            $counts[$c['s']][1] = ($counts[$c['s']][1] ?? 0) + 1;
            $flips[] = $c;
        }
    }
    $rs = [];
    foreach ($row['cmds'] as $cmd) {
        $r = $cmd['r'] ?? null;
        $second = match ($cmd['n']) {
            'SCORE_INCREMENT' => is_array($r) ? $r[1] ?? null : null,
            'LIFECYCLE_MUTATE' => is_array($r) ? $r[2] ?? null : null,
            'SCORE_SET', 'BLOCK_SET' => $r,
            'HARD_BLOCK' => is_array($r) && isset($r[4]) ? (int) $r[4] - (int) $cmd['a'][1] : null,
            default => null,
        };
        if ($second !== null) {
            $rs[] = (int) $second;
        }
    }
    $writes = array_values(array_filter($row['cmds'], static fn (array $c): bool => in_array($c['n'], ['LIFECYCLE_MUTATE', 'HARD_BLOCK', 'BUDGET_INCREMENT', 'BLOCK_SET'], true)));
    $record = ['j' => $j, 't' => $twin, 'k' => $k, 'op' => $row['op'], 'due' => round($due, 6), 'h0' => round($row['h0'], 6), 'h1' => round($row['h1'], 6)];
    foreach (['d', 'lv', 'ra', 're', 'x', 'claim'] as $field) {
        if (array_key_exists($field, $row)) {
            $record[$field] = $row[$field];
        }
    }
    $record['h531'] = $row['h531'] ?? null;
    $record['n'] = $counts;
    $record['rs'] = $rs;
    if ($flips !== []) {
        $record['f'] = $flips;
    }
    if ($probe) {
        $record['c'] = $row['c'];
    }
    if ($writes !== []) {
        $record['w'] = array_map(static fn (array $c): array => array_intersect_key($c, ['n' => 1, 'k' => 1, 'path' => 1, 'a' => 1, 'r' => 1, 'ts' => 1, 'tr' => 1]), $writes);
    }
    $record['sites'] = $row['sites'];
    P1\writeRow($handle, $record + $extra);
};

/** Waits until host time $at (E-SAME: host ≈ Redis, V-2). */
$sleepUntil = static function (float $at): void {
    $remaining = $at - microtime(true);
    if ($remaining > 0) {
        usleep((int) ($remaining * 1_000_000));
    }
};

/**
 * Both twins, back to back, order alternating by step parity (PLAN §4.4).
 *
 * @return array{S: array<string, mixed>, 0: array<string, mixed>}
 */
$pairStep = static function (int $j, int $k, string $op, \Maatify\RateLimiter\DTO\RateLimitContextDTO $context, float $due, bool $probe = false, array $extra = [], ?array $which = null, ?array $claimIds = null) use ($twinS, $twin0, $policy, $emit): array {
    $order = $k % 2 === 0 ? ['S', 'Z'] : ['Z', 'S'];
    $rows = [];
    foreach ($order as $t) {
        if ($which !== null && ! in_array($t, $which, true)) {
            continue;
        }
        $twin = $t === 'S' ? $twinS : $twin0;
        $rows[$t] = $twin->step($op, $policy, $context, $claimIds[$t] ?? null);
    }
    foreach ($rows as $t => $row) {
        $emit($j, $t, $k, $row, $due, $probe, $extra[$t] ?? $extra['*'] ?? []);
    }

    return $rows;
};

$stateRow = static function (int $j, array $keysS, array $keys0) use ($twinS, $twin0, $handle): void {
    P1\writeRow($handle, ['j' => $j, 'state' => ['S' => $twinS->state(array_keys($keysS)), 'Z' => $twin0->state(array_keys($keys0))]]);
};

/** The setup write of interest in a step: the last HARD_BLOCK at level >= 2, or the LIFECYCLE_MUTATE. */
function lastWrite(array $row, string $name): ?array
{
    $found = null;
    foreach ($row['cmds'] as $cmd) {
        if ($cmd['n'] === $name && ($name !== 'HARD_BLOCK' || (int) $cmd['a'][0] >= 2)) {
            $found = $cmd;
        }
    }

    return $found;
}

/** Block setup values for the law of PLAN §1.2 / §13: E, physical expiry (midpoint), level, key, path. */
function blockSetup(array $row, float $theta): ?array
{
    $hb = lastWrite($row, 'HARD_BLOCK');
    if ($hb === null) {
        return null;
    }
    $duration = (int) $hb['a'][1];
    $expires = $hb['path'] === 'R' ? (int) ($hb['r'][4] ?? 0) : (int) $hb['a'][2] + $duration;

    return ['E' => $expires, 'phys' => ($hb['ts'] + $hb['tr']) / 2 + $theta + $duration, 'sec' => (int) floor(($hb['ts'] + $hb['tr']) / 2 + $theta), 'physHalf' => ($hb['tr'] - $hb['ts']) / 2, 'lvl' => (int) $hb['a'][0], 'k' => $hb['k'], 'path' => $hb['path'], 'r' => $hb['r']];
}

/** Band placement (PLAN §5.3): j % 2 == 0 in-window; j % 4 == 1 before; j % 4 == 3 after. */
function band(int $j, float $lo, float $hi, float $width, float $u): array
{
    if ($j % 2 === 0) {
        if ($hi - $lo < 0.001) {
            return ['in-empty', $lo - $width + $u * $width];
        }

        return ['in', $lo + $u * ($hi - $lo)];
    }
    if ($j % 4 === 1) {
        return ['before', $lo - $width + $u * $width];
    }

    return ['after', $hi + $u * $width];
}

$width = max(abs($sigma), 0.001);
$start = microtime(true) + 3.0;
$flows = [];

for ($j = 0; $j < $pairs; $j++) {
    $context = streamContext('a' . $j, $j);
    // Setups are spread over 300 s per cell to bound the load (harness-time note, PLAN §13).
    $t0 = $start + $j * (300.0 / $pairs);
    // PLAN §5.4: one seeded PRNG per stream, fixed by family, policy and stream index, so every sigma
    // and both twins share the schedule.
    $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(crc32($family . '|' . $policyId . '|' . $j)));
    $flows[$j] = (function () use ($j, $t0, $context, $family, $policy, $sigma, $width, $pairStep, $twinS, $twin0, $emit, $stateRow, $rng, $cap, $theta) {
        // Redis time → host time for a wait (E-SAME: θ ≈ 0).
        $host = static fn (float $redisAt): float => $redisAt - $theta;
        $rand = static fn (): float => $rng->getFloat(0.0, 1.0);
        $span = static fn (float $seconds): float => $cap > 0 ? min($seconds, $cap) : $seconds;
        // A sub-second jitter per stream: without it every stream of a cell shares the cell's start
        // phase, and a phase near a second boundary splits the twins systematically (§13).
        $t0 += $rand();
        $keysS = [];
        $keys0 = [];
        $track = static function (array $rows) use (&$keysS, &$keys0): void {
            foreach ($rows as $t => $row) {
                foreach ($row['wk'] as $key) {
                    if ($t === 'S') {
                        $keysS[$key] = true;
                    } else {
                        $keys0[$key] = true;
                    }
                }
            }
        };
        $k = 0;
        switch ($family) {
            case 'P-B':
                yield $t0;
                $track($pairStep($j, $k++, 'fail', $context, $t0));
                yield $t0 + 10;
                $rows = $pairStep($j, $k++, 'fail', $context, $t0 + 10);
                $track($rows);
                $mS = lastWrite($rows['S'], 'LIFECYCLE_MUTATE');
                $m0 = lastWrite($rows['Z'], 'LIFECYCLE_MUTATE');
                $uS = (int) ($mS['r'][2] ?? 0);
                $u0 = (int) ($m0['r'][2] ?? 0);
                // σ twin reaches the 6 → 4 step at R = uS + 1200 − σ; the σ = 0 twin at u0 + 1200.
                $a = $uS + 1200 - $sigma;
                $b = $u0 + 1200;
                [$bandName, $at] = band($j, min($a, $b), max($a, $b), $width, $rand());
                yield $host($at);
                $rows = $pairStep($j, $k++, 'check', $context, $at, true, ['*' => ['probe' => ['band' => $bandName, 'uS' => $uS, 'u0' => $u0, 'vS' => (int) ($mS['r'][1] ?? -1), 'v0' => (int) ($m0['r'][1] ?? -1)]]]);
                $track($rows);
                break;

            case 'P-E2':
            case 'P-E3':
            case 'P-SAT':
            case 'P-I':
            case 'P-RA2':
                yield $t0;
                $track($pairStep($j, $k++, 'fail', $context, $t0));
                yield $t0 + 10;
                $track($pairStep($j, $k++, 'fail', $context, $t0 + 10));
                yield $t0 + 20;
                $rows = $pairStep($j, $k++, 'fail', $context, $t0 + 20);
                $track($rows);
                $bS = blockSetup($rows['S'], $theta);
                $b0 = blockSetup($rows['Z'], $theta);
                if ($bS === null || $b0 === null) {
                    $emit($j, 'S', $k, ['op' => 'setup-failed', 'h0' => microtime(true), 'h1' => microtime(true), 'c' => [], 'cmds' => [], 'sites' => []], 0.0, false);
                    break;
                }
                $endOf = static fn (array $setup, float $s): float => min($setup['phys'], (float) $setup['E'], $setup['E'] - $s);
                if ($family === 'P-E3' || $family === 'P-I') {
                    $at = max($endOf($bS, $sigma), $endOf($b0, 0.0), $bS['phys'], $b0['phys']) + 2.0;
                    yield $host($at);
                    $rows = $pairStep($j, $k++, 'fail', $context, $at);
                    $track($rows);
                    $bS = blockSetup($rows['S'], $theta) ?? $bS;
                    $b0 = blockSetup($rows['Z'], $theta) ?? $b0;
                }
                $setup = ['S' => $bS, 'Z' => $b0];
                if ($family === 'P-RA2') {
                    $poll = $t0 + 50;
                    yield $poll;
                    $rows = $pairStep($j, $k++, 'check', $context, $poll, true, ['*' => ['block' => $setup]]);
                    $track($rows);
                    $returns = [];
                    foreach ($rows as $t => $row) {
                        $returns[$t] = $row['h1'] + (int) ($row['ra'] ?? 0);
                    }
                    asort($returns);
                    foreach ($returns as $t => $at) {
                        yield $at;
                        $only = $pairStep($j, $k, 'check', $context, $at, true, ['*' => ['probe' => ['band' => 'return', 'pred' => $at], 'block' => $setup]], [$t]);
                        $track($only);
                    }
                    $k++;
                    break;
                }
                if ($family === 'P-I') {
                    $finish = 0;
                    foreach (['S', 'Z'] as $t) {
                        $finish = max($finish, (int) ($setup[$t]['r'][3] ?? 0));
                    }
                    $from = microtime(true) + $theta;
                    $to = max($from + 1, $finish + 1200.0);
                    $at = $from + $rand() * ($to - $from);
                    yield $host($at);
                    $track($pairStep($j, $k++, 'check', $context, $at, true, ['*' => ['probe' => ['band' => 'pause'], 'block' => $setup]]));
                    break;
                }
                $endS = $endOf($bS, $sigma);
                $end0 = $endOf($b0, 0.0);
                if ($family === 'P-SAT') {
                    $from = max($bS['phys'], $b0['phys']) - ($bS['lvl'] === 2 ? 60 : 300) + 0.5;
                    $at = $from + $rand() * (min($end0, $b0['phys']) - 0.5 - $from);
                    $bandName = 'block';
                } else {
                    [$bandName, $at] = band($j, min($endS, $end0), max($endS, $end0), $width, $rand());
                }
                yield $host($at);
                $track($pairStep($j, $k++, 'check', $context, $at, true, ['*' => ['probe' => ['band' => $bandName], 'block' => $setup]]));
                break;

            case 'P-RA1':
                yield $t0;
                $track($pairStep($j, $k++, 'fail', $context, $t0));
                yield $t0 + 10;
                $rows = $pairStep($j, $k++, 'fail', $context, $t0 + 10);
                $track($rows);
                $returns = [];
                $setup = [];
                foreach ($rows as $t => $row) {
                    $returns[$t] = $row['h1'] + (int) ($row['ra'] ?? 0);
                    $mutate = lastWrite($row, 'LIFECYCLE_MUTATE');
                    $b84 = null;
                    foreach ($row['c'] as $c) {
                        if ($c['s'] === 'B84' && $c['callerLast'] && $c['scope'] === 'account') {
                            $b84 = $c;
                        }
                    }
                    $setup[$t] = ['u' => (int) ($mutate['r'][2] ?? 0), 'v' => (int) ($mutate['r'][1] ?? -1), 'callerLast' => $b84['last'] ?? null, 'ra' => $row['ra'] ?? null, 'd' => $row['d'] ?? null];
                }
                asort($returns);
                foreach ($returns as $t => $at) {
                    yield $at;
                    $track($pairStep($j, $k, 'check', $context, $at, true, ['*' => ['probe' => ['band' => 'return', 'pred' => $at], 'setup' => $setup]], [$t]));
                }
                $k++;
                break;

            case 'S-ATK':
                $phase = $rand() * 30.0;
                for ($at = $t0 + $phase; $at < $t0 + $span(2400.0); $at += 30.0) {
                    yield $at;
                    $rows = $pairStep($j, $k++, 'check', $context, $at);
                    $track($rows);
                    $allowed = array_keys(array_filter($rows, static fn (array $r): bool => ($r['d'] ?? '') === 'ALLOW'));
                    if ($allowed !== []) {
                        $track($pairStep($j, $k++, 'fail', $context, $at, false, [], $allowed));
                    } else {
                        $k++;
                    }
                }
                break;

            case 'S-BEN':
                $failures = 1 + $j % 3;
                $at = $t0;
                $stopped = [];
                for ($f = 0; $f < $failures; $f++) {
                    yield $at;
                    $rows = $pairStep($j, $k++, 'check', $context, $at);
                    $track($rows);
                    // The failure is recorded only after an ALLOW pre-check (the Host refuses otherwise).
                    $allowed = array_keys(array_filter($rows, static fn (array $r): bool => ($r['d'] ?? '') === 'ALLOW'));
                    if ($allowed !== []) {
                        $track($pairStep($j, $k++, 'fail', $context, $at, false, [], $allowed));
                    } else {
                        $k++;
                    }
                    $at += 5.0 + $rand() * 15.0;
                }
                $last = $at;
                $visits = [];
                for ($v = 0; $v < 3; $v++) {
                    $visits[] = $last + $rand() * $span(3600.0);
                }
                sort($visits);
                foreach ($visits as $at) {
                    $active = array_values(array_diff(['S', 'Z'], $stopped));
                    if ($active === []) {
                        break;
                    }
                    yield $at;
                    $rows = $pairStep($j, $k++, 'check', $context, $at, false, [], $active);
                    $track($rows);
                    $allowed = array_keys(array_filter($rows, static fn (array $r): bool => ($r['d'] ?? '') === 'ALLOW'));
                    if ($allowed !== []) {
                        $track($pairStep($j, $k, 'success', $context, $at, false, [], $allowed));
                        $stopped = array_merge($stopped, $allowed);
                    }
                    $k++;
                }
                break;

            case 'S-RA':
                // Per-twin timing (PLAN §5.4); steps are paired by index in report.php.
                $next = ['S' => $t0, 'Z' => $t0];
                $kk = ['S' => 0, 'Z' => 0];
                $end = $t0 + $span(2400.0);
                while (true) {
                    $t = $next['S'] <= $next['Z'] ? 'S' : 'Z';
                    if ($next[$t] >= $end) {
                        $t = $t === 'S' ? 'Z' : 'S';
                        if ($next[$t] >= $end) {
                            break;
                        }
                    }
                    yield $next[$t];
                    $due = $next[$t];
                    $row = $pairStep($j, $kk[$t]++, 'check', $context, $due, false, [], [$t])[$t];
                    $track([$t => $row]);
                    $wait = 1;
                    if (($row['d'] ?? '') === 'ALLOW') {
                        if (($row['re'] ?? null) !== null) {
                            $track($pairStep($j, $kk[$t]++, 'claim', $context, $due, false, [], [$t], [$t => $row['re']]));
                        }
                        $fail = $pairStep($j, $kk[$t]++, 'fail', $context, $due, false, [], [$t])[$t];
                        $track([$t => $fail]);
                        $wait = (int) ($fail['ra'] ?? 0);
                    } elseif (($row['d'] ?? '') !== 'EXCEPTION') {
                        $wait = (int) ($row['ra'] ?? 0);
                    }
                    $next[$t] = microtime(true) + max(1, $wait);
                }
                break;

            default:
                throw new \InvalidArgumentException('family ' . $family);
        }
        $stateRow($j, $keysS, $keys0);
    })();
}

// The scheduler: resume the flow whose next time is earliest (PLAN §5.4).
$due = [];
foreach ($flows as $j => $flow) {
    $due[$j] = $flow->current();
}
while ($due !== []) {
    $j = array_keys($due, min($due), true)[0];
    $sleepUntil($due[$j]);
    $flows[$j]->send(null);
    if ($flows[$j]->valid()) {
        $due[$j] = $flows[$j]->current();
    } else {
        unset($due[$j]);
    }
}

P1\writeRow($handle, ['end' => microtime(true)]);
fclose($handle);
printf("%s %s pairs=%d %.1fs\n", $env, $cell, $pairs, microtime(true) - $start + 3.0);
