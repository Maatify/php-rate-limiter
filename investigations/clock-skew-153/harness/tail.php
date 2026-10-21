<?php

declare(strict_types=1);

/*
 * P-TAIL (LT-153-02) and P-HT (LT-153-03, issue #155) in one 600 s wait, E-SAME
 * only (PLAN.md §11). No state seeding: every window is created by a real
 * checkOnly and reached again by a real checkOnly when Redis time arrives.
 * argv: package root, env, host, port, output file.
 *
 * P-TAIL: per a in {0.5, 1, 2}, 20 accounts. Creation with SYS; target request
 * with AHEAD(a) at Redis time M + 600 - a/2. Predicted: LO (expiresAt <= N).
 * P-HT: 20 accounts created at a Redis fraction >= 0.5 (creation retried on a
 * new account when the second changes during it). Target request with SYS at
 * Redis time S + 600 + f/2, f = the fraction read just before creation.
 * Predicted: a script error reply at an existing-key check, no skew involved.
 */

namespace Maatify\RateLimiter\Investigations\ClockSkew153;

[$self, $root, $env, $host, $port, $outFile] = $argv + [null, null, null, null, null, null];
require $root . '/vendor/autoload.php';
require __DIR__ . '/lib.php';

$port = (int) $port;
$namespace = 'c153-' . bin2hex(random_bytes(4)) . '-' . $env . '-tail';
$policy = 'login_protection';
$creator = new Lab($host, $port, $namespace, 'SYS', 0.0);
$creatorRuntime = $creator->runtime(baseConfig());
$redisTime = $creator->redisTime;
$handle = fopen($outFile, 'wb');
writeRow($handle, ['meta' => ['probe' => 'P-TAIL+P-HT', 'env' => $env, 'policy' => $policy]]);

$targets = [];
$index = 0;
foreach ([0.5, 1.0, 2.0] as $a) {
    for ($j = 0; $j < 20; $j++) {
        $account = sprintf('T%.1f-%d', $a, $j);
        $context = context($account, 'd1', 80_000 + $index++);
        $record = $creator->request($creatorRuntime, $policy, $context);
        $snap = $record['snaps'][0] ?? [];
        writeRow($handle, ['probe' => 'P-TAIL', 'phase' => 'create', 'account' => $account, 'a' => $a, 'o' => $record['outcome'], 'x' => $record['exception'], 'M' => $snap['M'] ?? null, 'added' => $snap['added'] ?? null]);
        if (($snap['added'] ?? 0) !== 1) {
            continue;
        }
        $targets[] = ['probe' => 'P-TAIL', 'account' => $account, 'a' => $a, 'context' => $context, 'M' => $snap['M'], 'at' => $snap['M'] + 600 - $a / 2,
            'window' => [$snap['M'] + 600 - $a, $snap['M'] + 600]];
    }
}

$retries = 0;
for ($j = 0; $j < 20; $j++) {
    while (true) {
        $fraction = fmod($redisTime->now(), 1.0);
        if ($fraction < 0.5 || $fraction > 0.9) {
            usleep((int) ((($fraction < 0.5 ? 0.5 : 1.5) - $fraction) * 1_000_000) + 1000);
            continue;
        }
        $account = 'H-' . $j . '-' . $retries;
        $context = context($account, 'd1', 85_000 + $retries * 100 + $j);
        $before = $redisTime->now();
        $record = $creator->request($creatorRuntime, $policy, $context);
        $after = $redisTime->now();
        $snap = $record['snaps'][0] ?? [];
        $sameSecond = floor($before) === floor($after);
        writeRow($handle, ['probe' => 'P-HT', 'phase' => 'create', 'account' => $account, 'before' => $before, 'after' => $after, 'sameSecond' => $sameSecond, 'o' => $record['outcome'], 'M' => $snap['M'] ?? null, 'added' => $snap['added'] ?? null]);
        if ($sameSecond && ($snap['added'] ?? 0) === 1 && ($snap['M'] ?? null) === (int) floor($before)) {
            $s = (int) floor($before);
            $f = $before - $s;
            $targets[] = ['probe' => 'P-HT', 'account' => $account, 'context' => $context, 'S' => $s, 'f' => $f, 'at' => $s + 600 + $f / 2,
                'window' => [$s + 600, $s + 600 + $f]];
            break;
        }
        $retries++;
    }
}

usort($targets, static fn (array $x, array $y): int => $x['at'] <=> $y['at']);
$ahead = [];
foreach ([0.5, 1.0, 2.0] as $a) {
    $lab = new Lab($host, $port, $namespace, 'AHEAD', $a);
    $ahead[(string) $a] = [$lab, $lab->runtime(baseConfig())];
}

foreach ($targets as $target) {
    waitRedisUntil($redisTime, $target['at']);
    [$lab, $runtime] = $target['probe'] === 'P-TAIL' ? $ahead[(string) $target['a']] : [$creator, $creatorRuntime];
    $before = $redisTime->now();
    $record = $lab->request($runtime, $policy, $target['context']);
    $after = $redisTime->now();
    $row = ['probe' => $target['probe'], 'phase' => 'target', 'account' => $target['account'], 'at' => $target['at'], 'before' => $before, 'after' => $after,
        'inWindow' => $before >= $target['window'][0] && $after < $target['window'][1], 'window' => $target['window'],
        'o' => $record['outcome'], 'x' => $record['exception'], 'err' => $record['errors'], 's' => $record['snaps']];
    if ($target['probe'] === 'P-TAIL') {
        $row['a'] = $target['a'];
    } else {
        $row['S'] = $target['S'];
        $row['f'] = $target['f'];
    }
    writeRow($handle, $row);
}
writeRow($handle, ['summary' => ['targets' => count($targets), 'htCreationRetries' => $retries]]);
fclose($handle);
