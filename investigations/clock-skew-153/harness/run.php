<?php

declare(strict_types=1);

/*
 * #153 phase 1 matrix runner (PLAN.md §5.4, §11).
 * argv: package root, env (E-SAME | E-DOCKER), Redis host, Redis port, output dir [, scale].
 * Cells run sequentially in the fixed order of §5.4; one store namespace per cell.
 */

namespace Maatify\RateLimiter\Investigations\ClockSkew153;

[$self, $root, $env, $host, $port, $out, $scale] = $argv + [null, null, null, null, null, null, '1'];
// Scale < 1 is for a dry run only; it is written into every cell's meta and report.php refuses it.
$scale = (float) $scale;
const R_SECONDS = 5.0;
require $root . '/vendor/autoload.php';
require __DIR__ . '/lib.php';

$port = (int) $port;
if (! in_array($env, ['E-SAME', 'E-DOCKER'], true)) {
    fwrite(STDERR, "unknown env\n");
    exit(2);
}
@mkdir($out, 0777, true);

$cells = cells($env);
// A per-run token keeps every cell's namespace fresh even on a reused server.
$run = bin2hex(random_bytes(4));

offsetProbe($host, $port, $out . '/offset-before.jsonl');

foreach ($cells as [$clock, $offset, $population, $policyId, $n]) {
    $cell = cellName($clock, $offset, $population, $policyId);
    $started = microtime(true);
    $lab = new Lab($host, $port, 'c153-' . $run . '-' . $env . '-' . $cell, $clock, $offset);
    $policy = POLICY_NAMES[$policyId];
    $handle = fopen($out . '/' . $cell . '.jsonl', 'wb');
    $meta = ['cell' => $cell, 'env' => $env, 'clock' => $clock, 'offset' => $offset, 'population' => $population, 'policy' => $policy, 'scale' => $scale];
    $n = max(1, (int) round($n * $scale));
    $reps = max(1, (int) round(20 * $scale));
    writeRow($handle, ['meta' => $meta]);
    $rows = match ($population) {
        'F' => populationF($lab, $lab->runtime(baseConfig()), $policy, $n, $clock === 'ALIGN-GATED', $handle),
        'ROT-F' => populationF($lab, $lab->runtime(rotatedConfig()), $policy, $n, false, $handle),
        'R' => populationR($lab, null, $lab->runtime(baseConfig()), $policy, 'R', $reps, R_SECONDS * $scale, $handle),
        'ROT-R' => populationR($lab, null, $lab->runtime(rotatedConfig()), $policy, 'ROTR', $reps, R_SECONDS * $scale, $handle),
        'ROT-P' => populationR($lab, $lab->runtime(baseConfig()), $lab->runtime(rotatedConfig()), $policy, 'ROTP', $reps, R_SECONDS * $scale, $handle),
        'Q' => populationQ($lab, $lab->runtime(baseConfig()), $policy, max(1, (int) round(200 * $scale)), $handle),
    };
    fclose($handle);
    printf("%s %s rows=%d %.1fs\n", $env, $cell, $rows, microtime(true) - $started);
}

offsetProbe($host, $port, $out . '/offset-after.jsonl');

/** Compact per-request row (PLAN §4.2). @param array<string, mixed> $record */
function compactRow(array $record, array $extra): array
{
    $row = $extra + ['o' => $record['outcome']];
    if ($record['exception'] !== null) {
        $row['x'] = $record['exception'];
    }
    if ($record['errors'] !== []) {
        $row['err'] = $record['errors'];
    }
    $row['ob'] = count($record['other']);
    $row['s'] = array_map(static function (array $snap): array {
        $short = [
            'sc' => $snap['script'],
            'si' => $snap['site'],
            'ttl' => $snap['ttl'],
            'cn' => $snap['callerNow'] === null ? null : round($snap['callerNow'], 6),
            'ch' => $snap['callerHost'] === null ? null : round($snap['callerHost'], 6),
            'ts' => round($snap['tSend'], 6),
            'tr' => round($snap['tRecv'], 6),
            'v4' => $snap['v4'],
            'ns' => $snap['nowSite'],
        ];
        foreach (['expiresAt' => 'e', 'N' => 'N', 'M' => 'M', 'added' => 'a', 'count' => 'c', 'UP' => 'UP', 'LO' => 'LO', 'error' => 'err'] as $from => $to) {
            if (array_key_exists($from, $snap)) {
                $short[$to] = $snap[$from];
            }
        }

        return $short;
    }, $record['snaps']);

    return $row;
}

function populationF(Lab $lab, $runtime, string $policy, int $n, bool $gated, $handle): int
{
    for ($i = 0; $i < $n; $i++) {
        if ($gated) {
            gate($lab->redisTime);
        }
        $record = $lab->request($runtime, $policy, context('F-' . $i, 'd' . $i, $i));
        writeRow($handle, compactRow($record, ['i' => $i, 'st' => round($record['start'], 6)]));
    }

    return $n;
}

/**
 * R / ROT-R / ROT-P: 20 repetitions, each on a new account, requests back to
 * back for 5.0 s of host time. ROT-P first sends one setup request under the
 * old configuration (it creates the previous key).
 */
function populationR(Lab $lab, $setupRuntime, $runtime, string $policy, string $tag, int $reps, float $seconds, $handle): int
{
    $rows = 0;
    for ($rep = 0; $rep < $reps; $rep++) {
        $account = $tag . '-' . $rep;
        $context = context($account, 'd1', 60_000 + $rep);
        $setupExpiresAt = null;
        if ($setupRuntime !== null) {
            $record = $lab->request($setupRuntime, $policy, $context);
            $setupExpiresAt = $record['snaps'][0]['expiresAt'] ?? null;
            writeRow($handle, compactRow($record, ['rep' => $rep, 'setup' => true, 'st' => round($record['start'], 6)]));
            $rows++;
        }
        $first = null;
        $k = 0;
        while (true) {
            $record = $lab->request($runtime, $policy, $context);
            $first ??= $record['start'];
            $extra = ['rep' => $rep, 'k' => $k, 'dt' => round($record['start'] - $first, 6)];
            if ($setupExpiresAt !== null) {
                $extra['prevHit'] = ($record['snaps'][0]['expiresAt'] ?? null) === $setupExpiresAt;
            }
            writeRow($handle, compactRow($record, $extra));
            $rows++;
            $k++;
            if (microtime(true) - $first >= $seconds) {
                break;
            }
        }
    }

    return $rows;
}

/** Q: one account, devices d1..d4 then d1 again; 200 repetitions. */
function populationQ(Lab $lab, $runtime, string $policy, int $reps, $handle): int
{
    $rows = 0;
    for ($rep = 0; $rep < $reps; $rep++) {
        foreach (['d1', 'd2', 'd3', 'd4', 'd1'] as $step => $device) {
            // One IPv4 address per step: with one address per repetition an IP-scoped
            // rule blocks the third device before the distributed observation.
            $record = $lab->request($runtime, $policy, context('Q-' . $rep, $device, 70_000 + $rep * 5 + $step));
            writeRow($handle, compactRow($record, ['rep' => $rep, 'step' => $step, 'st' => round($record['start'], 6)]));
            $rows++;
        }
    }

    return $rows;
}

/** PLAN §4.3: 1,000 TIME round trips on a dedicated connection. */
function offsetProbe(string $host, int $port, string $file): void
{
    $time = new RedisTime(new \Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor($host, $port));
    $handle = fopen($file, 'wb');
    for ($i = 0; $i < 1000; $i++) {
        [$send, $redis, $recv] = $time->probe();
        writeRow($handle, ['send' => round($send, 6), 'redis' => $redis, 'recv' => round($recv, 6)]);
    }
    fclose($handle);
}
