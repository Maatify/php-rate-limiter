<?php

declare(strict_types=1);

// #160 phase 3 probe — T-K4 and T-HAND (PLAN.md §4, §13). Nothing under src/ or
// tests/ changes: RedisFullCapabilityStore and RespRedisCommandExecutor are used
// as they are; the raw commands, keys and arguments are the tests' own
// (RedisFullCapabilityStoreIntegrationTest L1833–L1858, L1871–L1883, L1963–L1992).
// Only the clock passed through the store's third constructor parameter is the
// harness's (clocks.php).
//
//   knat      n sequences per kind P-K4 / P-CUR / P-PREV, alternated; pass-through recorder (§4.2)
//   kheld     n per kind P-K4 / P-PREV through ...At() with one held millisecond reading (§13 LT-160D-06)
//   kforced   n per rho in {1, 500}, alternated: P-K4 with PEXPIREAT X = floor(h0/1000)*1000 + 2000 + rho (§4.3)
//   kforcedm  n: rho = 1, MUTATE with READ's DTO and expiresAt = ceil(X/1000) forced (§13 LT-160D-04)
//   kcal      n pairs: host reading, then a harness EVAL returning TIME (§13 LT-160D-03)
//   hnat      n T-HAND sequences: parent READ, six forked workers' MUTATE (§4.4)
//   ddet      n injected + n control for K and for H, X ≡ 500, MUTATE offset +1,000 ms (§4.5, LT-160D-01)
//   vclock    n with + n without the recorder on a fixed clock, X ≡ 500, K and H (§5)
//   overhead  n with the recorder + n with SystemClock directly, alternated, P-K4 (§5)
//   xread     n: PEXPIREAT a known X, PEXPIRETIME must return it (D5)
//
// Usage: php k.php --root=<repo> --port=<redis port> --arm=<arm> --n=<n> --out=<rows.jsonl>

namespace Flaky160D;

use DateTimeZone;
use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\SharedCommon\Infrastructure\SystemClock;

$options = getopt('', ['root:', 'port:', 'arm:', 'n:', 'out:']);
require (string) $options['root'] . '/vendor/autoload.php';
require __DIR__ . '/clocks.php';
$port = (int) $options['port'];
$n = (int) $options['n'];
$arm = (string) $options['arm'];
$out = fopen((string) $options['out'], 'wb');
const WORKERS = 6;

function row($handle, array $row): void
{
    fwrite($handle, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}

function hostMs(): int
{
    static $clock = null;
    $clock ??= new SystemClock(new DateTimeZone('UTC'));
    return (int) $clock->now()->format('Uv');
}

/** The store's key layout (RedisFullCapabilityStore L2050), as the test's familyPrefix() (L3755). */
function key(string $namespace, string $family, string $logical): string
{
    return 'maatify:rate-limiter:v1:' . hash('sha256', $namespace) . ':' . $family . ':' . hash('sha256', $logical);
}

function ceilSeconds(int $milliseconds): int
{
    return intdiv($milliseconds + 999, 1000);
}

/** X for a forced phase: floor(h0/1000)*1000 + $ahead + $rho (PLAN §4.3, §4.5). */
function forcedX(int $ahead, int $rho): int
{
    return intdiv(hostMs(), 1000) * 1000 + $ahead + $rho;
}

/** @return list<string> */
function readsOf(?ClockInterface $clock, string $label): array
{
    if (! $clock instanceof RecordingClock) {
        return [];
    }
    $values = [];
    foreach ($clock->reads as [$readLabel, $reading]) {
        if ($readLabel === $label) {
            $values[] = $reading;
        }
    }
    return $values;
}

/** The three legacy kinds: logical keys, value written, the command after the expiry, READ/MUTATE arguments (test lines). */
const KINDS = [
    // T-K4 L1875–L1883
    'P-K4' => ['source' => 'ttl-contract-legacy-previous', 'current' => 'ttl-contract-legacy-current', 'previous' => 'ttl-contract-legacy-previous',
        'value' => '4', 'expire' => ['PEXPIRE', '1200'], 'after' => ['HGETALL', 'PTTL'], 'ttl' => 86400, 'new' => 5],
    // comparator, current legacy L1836–L1844
    'P-CUR' => ['source' => 'legacy-current-boundary', 'current' => 'legacy-current-boundary', 'previous' => null,
        'value' => '4', 'expire' => ['EXPIRE', '600'], 'after' => ['TTL'], 'ttl' => 600, 'new' => 4],
    // comparator, previous legacy L1850–L1858
    'P-PREV' => ['source' => 'legacy-previous-boundary', 'current' => 'legacy-handoff-current', 'previous' => 'legacy-previous-boundary',
        'value' => '6', 'expire' => ['EXPIRE', '600'], 'after' => ['HGETALL'], 'ttl' => 600, 'new' => 7],
];

/**
 * One legacy READ → MUTATE pair. $expireAt null = the test's own expiry command; otherwise PEXPIREAT $expireAt.
 * $held null = the public methods (one fallback reading per call); otherwise both calls get that millisecond reading.
 * $forceExpiry null = MUTATE with READ's DTO; otherwise READ's DTO with that expiresAt.
 * $mutateOffset: RecordingClock offset set just before MUTATE (D-DET only).
 */
function pair(RespRedisCommandExecutor $executor, string $kind, string $namespace, ?ClockInterface $clock, ?int $expireAt = null,
    ?int $held = null, ?int $forceExpiry = null, int $mutateOffset = 0): array
{
    $k = KINDS[$kind];
    $store = new RedisFullCapabilityStore($executor, $namespace, $clock);
    $sourceKey = key($namespace, 'score', $k['source']);
    $row = ['kind' => $kind, 'ns' => $namespace];
    $started = hrtime(true);
    $time = $executor->execute(['TIME']);
    $now = (int) $time[0];
    $executor->execute(['HSET', $sourceKey, 'value', $k['value'], 'updatedAt', (string) $now]);
    $executor->execute($expireAt === null ? [$k['expire'][0], $sourceKey, $k['expire'][1]] : ['PEXPIREAT', $sourceKey, (string) $expireAt]);
    $before = null;
    foreach ($k['after'] as $command) {
        $reply = $executor->execute([$command, $sourceKey]);
        if ($command === 'HGETALL') {
            $before = $reply;
        }
    }
    if ($clock instanceof RecordingClock) {
        $clock->label = 'READ';
    }
    $state = $held === null
        ? $store->readGenerationBoundScoreState($k['current'], $k['previous'])
        : $store->readGenerationBoundScoreStateAt($k['current'], $k['previous'], $held);
    if ($state === null) {
        $row += ['read_null' => true, 'wall_us' => intdiv(hrtime(true) - $started, 1000)];
    } else {
        $expected = $forceExpiry === null ? $state
            : new GenerationBoundScoreStateDTO($state->source, $state->value, $state->updatedAt, $forceExpiry, $state->generation);
        if ($clock instanceof RecordingClock) {
            $clock->label = 'MUTATE';
            $clock->offsetMs = $mutateOffset;
        }
        $mutation = $held === null
            ? $store->mutateGenerationBoundScore($k['current'], $k['previous'], $expected, $k['ttl'], $k['new'])
            : $store->mutateGenerationBoundScoreAt($k['current'], $k['previous'], $expected, $k['ttl'], $k['new'], $held);
        $wall = intdiv(hrtime(true) - $started, 1000);
        $mutateEndMs = hostMs();
        if ($clock instanceof RecordingClock) {
            $clock->offsetMs = 0;
        }
        $row += ['read_null' => false, 'wall_us' => $wall, 'mutate_end_ms' => $mutateEndMs, 'e_r' => $state->expiresAt,
            'applied' => $mutation->applied, 'e_m' => $mutation->state?->expiresAt, 'forced_expiry' => $forceExpiry,
            'state' => $mutation->state === null ? null : [$mutation->state->value, $mutation->state->updatedAt, $mutation->state->expiresAt, $mutation->state->generation]];
    }
    // after the timed part: X, and for a previous key that it is unchanged
    $x = $executor->execute(['PEXPIRETIME', $sourceKey]);
    $row['x'] = $x;
    $row['x_after_mutation'] = $k['previous'] === null && ($row['applied'] ?? false);
    if ($before !== null && $k['previous'] !== null) {
        $row['source_unchanged'] = $executor->execute(['HGETALL', $sourceKey]) === $before;
    }
    $row['h_r'] = readsOf($clock, 'READ');
    $row['h_m'] = readsOf($clock, 'MUTATE');
    $row['held'] = $held;
    $row['expire_at'] = $expireAt;
    $row['updated_at'] = $now;
    return $row;
}

/**
 * One T-HAND sequence (L1965–L1992): parent READ, six forked workers' MUTATE, winners.
 * $clockFor(string $who): ?ClockInterface gives each store its clock; $workerOffset is set on a worker's RecordingClock.
 */
function hand(RespRedisCommandExecutor $executor, string $namespace, callable $clockFor, ?int $expireAt, int $workerOffset, int $port, $out, string $tmp): array
{
    $previousKey = key($namespace, 'score', 'concurrent-previous');
    $row = ['kind' => 'T-HAND', 'ns' => $namespace];
    $parentClock = $clockFor('parent');
    $store = new RedisFullCapabilityStore($executor, $namespace, $parentClock);
    $time = $executor->execute(['TIME']);
    $now = (int) $time[0];
    $executor->execute(['HSET', $previousKey, 'value', '5', 'updatedAt', (string) $now]);
    $executor->execute($expireAt === null ? ['EXPIRE', $previousKey, '600'] : ['PEXPIREAT', $previousKey, (string) $expireAt]);
    if ($parentClock instanceof RecordingClock) {
        $parentClock->label = 'READ';
    }
    $observed = $store->readGenerationBoundScoreState('concurrent-current', 'concurrent-previous');
    if ($observed === null) {
        return $row + ['read_null' => true];
    }
    fflush($out);
    $pids = [];
    for ($index = 0; $index < WORKERS; $index++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('fork failed');
        }
        if ($pid === 0) {
            $clock = $clockFor('worker');
            if ($clock instanceof RecordingClock) {
                $clock->label = 'MUTATE';
                $clock->offsetMs = $workerOffset;
            }
            $applied = false;
            $error = null;
            try {
                $workerStore = new RedisFullCapabilityStore(new RespRedisCommandExecutor('127.0.0.1', $port), $namespace, $clock);
                $applied = $workerStore->mutateGenerationBoundScore('concurrent-current', 'concurrent-previous', $observed, 600, 6)->applied;
                (new RespRedisCommandExecutor('127.0.0.1', $port))->execute(['SET', key($namespace, 'handoff-result', (string) $index), $applied ? '1' : '0', 'EX', '60']);
            } catch (\Throwable $e) {
                $error = get_class($e) . ': ' . $e->getMessage();
            }
            file_put_contents("$tmp/w-$index.json", json_encode(['i' => $index, 'applied' => $applied, 'error' => $error, 'h_m' => readsOf($clock, 'MUTATE')]));
            exit(0);
        }
        $pids[] = $pid;
    }
    $exits = [];
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $exits[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1;
    }
    $winners = 0;
    $results = [];
    $workers = [];
    for ($index = 0; $index < WORKERS; $index++) {
        $result = $executor->execute(['GET', key($namespace, 'handoff-result', (string) $index)]);
        $results[] = $result;
        $winners += (int) $result;
        $file = "$tmp/w-$index.json";
        $workers[] = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        @unlink($file);
    }
    return $row + ['read_null' => false, 'x' => $executor->execute(['PEXPIRETIME', $previousKey]), 'e_r' => $observed->expiresAt,
        'winners' => $winners, 'results' => $results, 'exits' => $exits, 'workers' => $workers, 'h_r' => readsOf($parentClock, 'READ'),
        'expire_at' => $expireAt, 'updated_at' => $now];
}

$executor = new RespRedisCommandExecutor('127.0.0.1', $port);
$tmp = sys_get_temp_dir() . '/f160d-' . getmypid();
@mkdir($tmp);
$sequence = 0;
$flush = static function () use ($executor, &$sequence): void {
    if (++$sequence % 1000 === 0) {
        $executor->execute(['FLUSHDB']); // outside every timed part (PLAN §4.1)
    }
};
$safe = static function (callable $body, array $meta) use ($out): void {
    try {
        $row = $body();
        $row['error'] = null;
    } catch (\Throwable $e) {
        $row = ['error' => get_class($e) . ': ' . $e->getMessage()];
    }
    row($out, $meta + $row);
};
$executor->execute(['FLUSHDB']);

switch ($arm) {
    case 'knat':
        for ($i = 0; $i < $n; $i++) {
            foreach (array_keys(KINDS) as $kind) {
                $safe(static fn(): array => pair($executor, $kind, "f160d-knat-$kind-$i", new RecordingClock()), ['arm' => $arm, 'i' => $i]);
                $flush();
            }
        }
        break;
    case 'kheld':
        for ($i = 0; $i < $n; $i++) {
            foreach (['P-K4', 'P-PREV'] as $kind) {
                $safe(static fn(): array => pair($executor, $kind, "f160d-kheld-$kind-$i", null, null, hostMs()), ['arm' => $arm, 'i' => $i]);
                $flush();
            }
        }
        break;
    case 'kforced':
        for ($i = 0; $i < $n; $i++) {
            foreach ([1, 500] as $rho) {
                $safe(static function () use ($executor, $rho, $i): array {
                    $target = forcedX(2000, $rho);
                    return pair($executor, 'P-K4', "f160d-kforced-$rho-$i", new RecordingClock(), $target) + ['x_target' => $target];
                }, ['arm' => $arm, 'i' => $i, 'rho' => $rho]);
                $flush();
            }
        }
        break;
    case 'kforcedm':
        for ($i = 0; $i < $n; $i++) {
            $safe(static function () use ($executor, $i): array {
                $target = forcedX(2000, 1);
                return pair($executor, 'P-K4', "f160d-kforcedm-$i", new RecordingClock(), $target, null, ceilSeconds($target)) + ['x_target' => $target];
            }, ['arm' => $arm, 'i' => $i, 'rho' => 1]);
            $flush();
        }
        break;
    case 'kcal':
        $script = "local t = redis.call('TIME') return {t[1], t[2]}";
        for ($i = 0; $i < $n; $i++) {
            $safe(static function () use ($executor, $script): array {
                $pair = [];
                for ($c = 0; $c < 2; $c++) {
                    $h = hostMs();
                    $t = $executor->execute(['EVAL', $script, '0']);
                    $r = (int) $t[0] * 1000 + intdiv((int) $t[1], 1000);
                    $pair[] = ['h' => $h, 'r' => $r, 'n' => $r - $h];
                }
                return ['pair' => $pair];
            }, ['arm' => $arm, 'i' => $i]);
        }
        break;
    case 'hnat':
        // BC-1 (PLAN §15): each sequence opens 12 connections as the test does; a uniform random pause of
        // 0..2·pace ms between sequences (outside every timed part) bounds the connection rate without
        // putting X's phase on a fixed grid. FLAKY160_HAND_PACE_MS, default 100.
        $pace = (int) (getenv('FLAKY160_HAND_PACE_MS') ?: 100);
        for ($i = 0; $i < $n; $i++) {
            $safe(static fn(): array => hand($executor, "f160d-hnat-$i", static fn(string $who): ClockInterface => new RecordingClock(), null, 0, $port, $out, $tmp),
                ['arm' => $arm, 'i' => $i, 'pace_ms' => $pace]);
            $flush();
            usleep(random_int(0, 2 * $pace) * 1000);
        }
        break;
    case 'ddet':
        foreach (['injected' => 1000, 'control' => 0] as $variant => $offset) {
            for ($i = 0; $i < $n; $i++) {
                $safe(static function () use ($executor, $variant, $offset, $i): array {
                    $target = forcedX(2000, 500);
                    return pair($executor, 'P-K4', "f160d-ddet-k-$variant-$i", new RecordingClock(), $target, null, null, $offset) + ['x_target' => $target];
                }, ['arm' => $arm, 'test' => 'K', 'variant' => $variant, 'i' => $i]);
                $safe(static function () use ($executor, $variant, $offset, $i, $port, $out, $tmp): array {
                    $target = forcedX(600_000, 500);
                    return hand($executor, "f160d-ddet-h-$variant-$i", static fn(string $who): ClockInterface => new RecordingClock(), $target, $offset, $port, $out, $tmp)
                        + ['x_target' => $target];
                }, ['arm' => $arm, 'test' => 'H', 'variant' => $variant, 'i' => $i]);
            }
        }
        break;
    case 'vclock':
        for ($i = 0; $i < $n; $i++) {
            foreach (['with', 'without'] as $mode) {
                $fixed = hostMs();
                $make = static fn(): ClockInterface => $mode === 'with' ? new RecordingClock(new SettableClock($fixed)) : new SettableClock($fixed);
                $safe(static function () use ($executor, $mode, $i, $make, $fixed): array {
                    $target = forcedX(2000, 500);
                    return pair($executor, 'P-K4', "f160d-vclock-k-$mode-$i", $make(), $target) + ['x_target' => $target, 'fixed_ms' => $fixed];
                }, ['arm' => $arm, 'test' => 'K', 'mode' => $mode, 'i' => $i]);
                $safe(static function () use ($executor, $mode, $i, $make, $fixed, $port, $out, $tmp): array {
                    $target = forcedX(600_000, 500);
                    return hand($executor, "f160d-vclock-h-$mode-$i", static fn(string $who): ClockInterface => $make(), $target, 0, $port, $out, $tmp)
                        + ['x_target' => $target, 'fixed_ms' => $fixed];
                }, ['arm' => $arm, 'test' => 'H', 'mode' => $mode, 'i' => $i]);
            }
        }
        break;
    case 'overhead':
        for ($i = 0; $i < $n; $i++) {
            foreach (['recorder', 'direct'] as $mode) {
                $safe(static fn(): array => pair($executor, 'P-K4', "f160d-overhead-$mode-$i",
                    $mode === 'recorder' ? new RecordingClock() : new SystemClock(new DateTimeZone('UTC'))), ['arm' => $arm, 'mode' => $mode, 'i' => $i]);
                $flush();
            }
        }
        break;
    case 'xread':
        for ($i = 0; $i < $n; $i++) {
            $safe(static function () use ($executor, $i): array {
                $key = "f160d-xread-$i";
                $target = forcedX(2000, [1, 500, 999][$i % 3]);
                $executor->execute(['SET', $key, '1']);
                $executor->execute(['PEXPIREAT', $key, (string) $target]);
                return ['x_target' => $target, 'x' => $executor->execute(['PEXPIRETIME', $key])];
            }, ['arm' => $arm, 'i' => $i]);
        }
        break;
    default:
        fwrite(STDERR, "unknown arm $arm\n");
        exit(2);
}
@rmdir($tmp);
fclose($out);
