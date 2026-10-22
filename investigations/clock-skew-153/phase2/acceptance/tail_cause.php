<?php

declare(strict_types=1);

/*
 * #153 phase 2, LT-P2-15 / LT-P2-17 (PLAN §12.3, §12.4): a descriptive replay of
 * the archived tail.php (P-TAIL and P-HT) on the same head, recording each
 * request's full result instead of the decision alone: decision, blockLevel,
 * retryAfter, failureMode, metadata (signal, cause, context reason / scope) and
 * the names of the Redis scripts the call ran, with (ttl, max, count) for the
 * bounded scripts (added after the first replay, 2026-10-22, which kept names only
 * and could not tell the collective rules apart). The flow below is tail.php's,
 * unchanged except `Lab::request` -> `fullRequest()` and the extra fields; it
 * requires the archived lib.php unchanged. No verdict depends on it.
 * argv: package root, env, host, port, output file.
 */

namespace Maatify\RateLimiter\Investigations\ClockSkew153;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Throwable;

[$self, $root, $env, $host, $port, $outFile] = $argv + [null, null, null, null, null, null];
require $root . '/vendor/autoload.php';
require $root . '/investigations/clock-skew-153/harness/lib.php';

/**
 * Lab::request with the full result kept (one checkOnly through limit()).
 *
 * @return array<string, mixed>
 */
function fullRequest(Lab $lab, CompositeRateLimiterRuntimeInterface $runtime, string $policy, RateLimitContextDTO $context): array
{
    $lab->log->reset();
    $start = microtime(true);
    $full = ['ip' => $context->ip];
    try {
        $result = $runtime->limit($context, RateLimitCommand::checkOnly($policy));
        $outcome = $result->decision;
        $exception = null;
        $full += ['level' => $result->blockLevel, 'ra' => $result->retryAfter, 'mode' => $result->failureMode, 'meta' => json_decode(json_encode($result->metadata, JSON_THROW_ON_ERROR), true)];
    } catch (Throwable $error) {
        $outcome = 'EXCEPTION';
        $exception = get_class($error) . ': ' . $error->getMessage();
    }
    // Script name, with (ttl, max) for the bounded scripts as the archived RecordingExecutor logs them.
    $full['cmds'] = array_values(array_map(
        static fn (array $e): string => (string) $e['name'] . (isset($e['ttl']) ? '(' . $e['ttl'] . ',' . ($e['max'] ?? '?') . (isset($e['count']) ? ',c=' . $e['count'] : '') . ')' : ''),
        array_filter($lab->log->events, static fn (array $e): bool => $e['e'] === 'cmd'),
    ));

    return ['start' => $start, 'outcome' => $outcome, 'exception' => $exception, 'full' => $full] + analyse($lab->log->events);
}

$port = (int) $port;
$namespace = 'c153p2-' . bin2hex(random_bytes(4)) . '-' . $env . '-tail-cause';
$policy = 'login_protection';
$creator = new Lab($host, $port, $namespace, 'SYS', 0.0);
$creatorRuntime = $creator->runtime(baseConfig());
$redisTime = $creator->redisTime;
$handle = fopen($outFile, 'wb');
writeRow($handle, ['meta' => ['probe' => 'P-TAIL+P-HT cause replay', 'env' => $env, 'policy' => $policy, 'head' => trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD'))]]);

$targets = [];
$index = 0;
foreach ([0.5, 1.0, 2.0] as $a) {
    for ($j = 0; $j < 20; $j++) {
        $account = sprintf('T%.1f-%d', $a, $j);
        $context = context($account, 'd1', 80_000 + $index++);
        $record = fullRequest($creator, $creatorRuntime, $policy, $context);
        $snap = $record['snaps'][0] ?? [];
        writeRow($handle, ['probe' => 'P-TAIL', 'phase' => 'create', 'account' => $account, 'a' => $a, 'o' => $record['outcome'], 'x' => $record['exception'], 'M' => $snap['M'] ?? null, 'added' => $snap['added'] ?? null, 'full' => $record['full']]);
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
        $record = fullRequest($creator, $creatorRuntime, $policy, $context);
        $after = $redisTime->now();
        $snap = $record['snaps'][0] ?? [];
        $sameSecond = floor($before) === floor($after);
        writeRow($handle, ['probe' => 'P-HT', 'phase' => 'create', 'account' => $account, 'before' => $before, 'after' => $after, 'sameSecond' => $sameSecond, 'o' => $record['outcome'], 'M' => $snap['M'] ?? null, 'added' => $snap['added'] ?? null, 'full' => $record['full']]);
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
    $record = fullRequest($lab, $runtime, $policy, $target['context']);
    $after = $redisTime->now();
    $row = ['probe' => $target['probe'], 'phase' => 'target', 'account' => $target['account'], 'at' => $target['at'], 'before' => $before, 'after' => $after,
        'inWindow' => $before >= $target['window'][0] && $after < $target['window'][1], 'window' => $target['window'],
        'o' => $record['outcome'], 'x' => $record['exception'], 'err' => $record['errors'], 's' => $record['snaps'], 'full' => $record['full']];
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
