<?php

declare(strict_types=1);

/**
 * Isolated baseline (1d87036c) vs G3 comparative benchmark across public paths,
 * measuring latency (p50/p95/p99/mean/min/max), CPU time (user/sys/total ms),
 * peak memory, Redis commands/bytes, key cardinality, and per-family TTLs.
 */

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionAdmissionPolicy;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\AdmissionCredentialDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\AdmissionBindingControlInterface;
use Maatify\RateLimiter\Service\AdmissionBindingEpochInterface;
use Maatify\RateLimiter\Service\AdmissionCredentialCodec;
use Maatify\RateLimiter\Service\AdmissionFenceControlInterface;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

$repoRoot = $argv[1];
$arm = $argv[2]; // 'baseline' or 'g3'
$srcRoot = $argv[3];
$host = getenv('REDIS_INTEGRATION_HOST') ?: '127.0.0.1';
$port = (int) (getenv('REDIS_INTEGRATION_PORT') ?: '6379');

$loader = require $repoRoot . '/vendor/autoload.php';
if ($srcRoot !== $repoRoot) {
    $map = [];
    foreach ($loader->getClassMap() as $class => $path) {
        if (str_starts_with($class, 'Maatify\\RateLimiter\\') && ! str_starts_with($class, 'Maatify\\RateLimiter\\Tests\\')) {
            $candidate = $srcRoot . '/src/' . str_replace('\\', '/', substr($class, strlen('Maatify\\RateLimiter\\'))) . '.php';
            if (is_file($candidate)) {
                $map[$class] = $candidate;
            }
        }
    }
    $loader->addClassMap($map);
    $loader->addPsr4('Maatify\\RateLimiter\\', $srcRoot . '/src', true);
}

$loaded = (new ReflectionClass(RateLimiterBuilder::class))->getFileName();
$expected = realpath($srcRoot . '/src/Builder/RateLimiterBuilder.php');
if ($loaded !== $expected) {
    throw new RuntimeException("Wrong source arm loaded: {$loaded} !== {$expected}");
}

$resp = new RespRedisCommandExecutor($host, $port, 'eval');
$now = 1_767_225_600;
$clock = new FixedClock('@' . $now);
$warmup = 15;
$iters = 150;

$makeConfig = static function (string $outer, ?string $prevOuter = null, ?string $binding = null, ?string $prevBinding = null) use ($arm): RateLimiterConfig {
    if ($arm === 'g3' && $binding !== null) {
        return new RateLimiterConfig(
            $outer,
            'bench-fp',
            'bench',
            $prevOuter,
            null,
            $binding,
            $prevBinding,
            'bench.example.test',
            'bench-g3-durable-state-secret-32-bytes!',
        );
    }

    return new RateLimiterConfig($outer, 'bench-fp', 'bench', $prevOuter, null, $binding, $prevBinding);
};

$makeCodec = static function (RateLimiterConfig $config) use ($arm): AdmissionCredentialCodec {
    if ($arm === 'g3') {
        return AdmissionCredentialCodec::fromConfig($config);
    }

    return new AdmissionCredentialCodec((string) $config->bindingKeySecret(), $config->previousBindingKeySecret());
};

$makeCred = static function (AdmissionCredentialCodec $codec, string $account, string $instance, int $epoch) use ($arm, $now): string {
    if ($arm === 'g3') {
        return $codec->encode(new AdmissionCredentialDTO($account, $account, ['login'], $instance, 'jti-' . $account, $now + 86400, $now, $epoch));
    }

    $ref = new ReflectionClass(AdmissionCredentialDTO::class);
    /** @var AdmissionCredentialDTO $dto */
    $dto = $ref->newInstance($account, ['login'], $instance, 'jti-' . $account, $now + 86400, $now, $epoch);

    return $codec->encode($dto);
};

$inspectRedis = static function () use ($resp): array {
    $keys = $resp->execute(['KEYS', '*']);
    $keys = is_array($keys) ? $keys : [];
    $byFamily = [];
    $ttlStats = [];
    $missingTtlScoped = 0;
    $persistentControl = 0;
    foreach ($keys as $k) {
        $k = (string) $k;
        $ttl = (int) $resp->execute(['TTL', $k]);
        if (preg_match('/:(adm:(?:a1:)?[a-z]+):/', $k, $m) === 1) {
            $fam = $m[1];
        } elseif (preg_match('/^maatify:rate-limiter:(v1|e1):[0-9a-f]{64}:([a-z0-9_-]+):/', $k, $m) === 1) {
            $fam = $m[1] . ':' . $m[2];
        } else {
            $fam = 'other';
        }
        $byFamily[$fam] = ($byFamily[$fam] ?? 0) + 1;
        $ttlStats[$fam]['min'] = isset($ttlStats[$fam]['min']) ? min($ttlStats[$fam]['min'], $ttl) : $ttl;
        $ttlStats[$fam]['max'] = isset($ttlStats[$fam]['max']) ? max($ttlStats[$fam]['max'], $ttl) : $ttl;
        $isControl = str_contains($k, ':fence:') || str_contains($k, ':epoch:') || str_contains($k, ':cfg:');
        if ($isControl) {
            if ($ttl === -1) {
                $persistentControl++;
            }
        } elseif ($ttl < 0 && ! str_contains($k, ':circuit:')) {
            $missingTtlScoped++;
        }
    }
    ksort($byFamily);
    ksort($ttlStats);

    return [
        'totalKeys' => count($keys),
        'byFamily' => $byFamily,
        'ttlByFamily' => $ttlStats,
        'persistentControlKeys' => $persistentControl,
        'scopedKeysWithoutTtl' => $missingTtlScoped,
    ];
};

$measure = static function (string $name, Closure $setup, Closure $step) use ($resp, $warmup, $iters, $inspectRedis): array {
    $resp->execute(['FLUSHDB']);
    $state = $setup();
    for ($i = 0; $i < $warmup; $i++) {
        $step($state, $i);
    }
    $c0 = $resp->commands;
    $bSent0 = $resp->bytesSent;
    $bRecv0 = $resp->bytesReceived;
    $ru0 = getrusage();
    memory_reset_peak_usage();
    $times = [];
    for ($i = $warmup; $i < $warmup + $iters; $i++) {
        $t0 = hrtime(true);
        $step($state, $i);
        $times[] = round((hrtime(true) - $t0) / 1000.0, 1);
    }
    $ru1 = getrusage();
    $peakKb = round(memory_get_peak_usage(false) / 1024.0, 2);
    $sorted = $times;
    sort($sorted);
    $pct = static fn(float $p): float => round($sorted[min(count($sorted) - 1, (int) floor($p * (count($sorted) - 1)))], 1);
    $userMs = round(($ru1['ru_utime.tv_sec'] - $ru0['ru_utime.tv_sec']) * 1000.0 + ($ru1['ru_utime.tv_usec'] - $ru0['ru_utime.tv_usec']) / 1000.0, 2);
    $sysMs = round(($ru1['ru_stime.tv_sec'] - $ru0['ru_stime.tv_sec']) * 1000.0 + ($ru1['ru_stime.tv_usec'] - $ru0['ru_stime.tv_usec']) / 1000.0, 2);

    return [
        'scenario' => $name,
        'warmup' => $warmup,
        'iters' => $iters,
        'min_us' => $sorted[0],
        'p50_us' => $pct(0.50),
        'p95_us' => $pct(0.95),
        'p99_us' => $pct(0.99),
        'max_us' => $sorted[count($sorted) - 1],
        'mean_us' => round(array_sum($sorted) / count($sorted), 1),
        'cpu_user_ms' => $userMs,
        'cpu_sys_ms' => $sysMs,
        'cpu_total_ms' => round($userMs + $sysMs, 2),
        'peak_mem_kb' => $peakKb,
        'redis_cmds_total' => $resp->commands - $c0,
        'redis_cmds_per_iter' => round(($resp->commands - $c0) / $iters, 2),
        'bytes_sent_per_iter' => round(($resp->bytesSent - $bSent0) / $iters, 1),
        'bytes_recv_per_iter' => round(($resp->bytesReceived - $bRecv0) / $iters, 1),
        'redis_state' => $inspectRedis(),
        'samples_us' => $times,
    ];
};

$results = [];

// 1. Non-opted login (checkOnly + recordFailure)
$results[] = $measure(
    'non_opted_login',
    static function () use ($makeConfig, $resp, $clock) {
        $store = new RedisFullCapabilityStore($resp, 'bench-non-opted');

        return RateLimiterBuilder::fromFullCapabilityStore($makeConfig('outer-1'), $store, new RecordingFailureSignalEmitter())
            ->withPolicy(new LoginProtectionPolicy())
            ->withClock($clock)
            ->build();
    },
    static function ($rt, int $i): void {
        $ctx = new RateLimitContextDTO('198.18.0.' . (($i % 250) + 1), 'Mozilla/5.0 Chrome/126', 'nonopt-' . $i, ['device' => 'd-' . $i]);
        $rt->limit($ctx, RateLimitCommand::checkOnly('login_protection'));
        $rt->limit($ctx, RateLimitCommand::recordFailure('login_protection'));
    },
);

// 2. Opted Lane U (checkOnly + recordSuccess with permit)
$results[] = $measure(
    'opted_lane_u',
    static function () use ($makeConfig, $resp, $clock) {
        $store = new RedisFullCapabilityStore($resp, 'bench-u');
        $rt = RateLimiterBuilder::fromFullCapabilityStore($makeConfig('outer-1', null, 'bind-1'), $store, new RecordingFailureSignalEmitter())
            ->withPolicy(new LoginProtectionAdmissionPolicy())
            ->withClock($clock)
            ->build();
        assert($rt instanceof AdmissionFenceControlInterface);
        $rt->openAdmissionFence('login');

        return $rt;
    },
    static function ($rt, int $i): void {
        $ctx = new RateLimitContextDTO('198.18.' . intdiv($i, 20) . '.' . (($i % 20) + 1), 'Mozilla/5.0 Chrome/126', 'acct-u-' . $i, ['device' => 'd-' . $i]);
        $check = $rt->limit($ctx, RateLimitCommand::checkOnly('login_protection'));
        $rt->limit($ctx, new RateLimitCommand('login_protection', 1, false, false, true, $check->metadata?->permit));
    },
);

// 3. Opted Lane P (checkOnly + recordSuccess with credential + permit)
$results[] = $measure(
    'opted_lane_p',
    static function () use ($makeConfig, $makeCodec, $makeCred, $resp, $clock, $warmup, $iters) {
        $cfg = $makeConfig('outer-1', null, 'bind-1');
        $store = new RedisFullCapabilityStore($resp, 'bench-p');
        $rt = RateLimiterBuilder::fromFullCapabilityStore($cfg, $store, new RecordingFailureSignalEmitter())
            ->withPolicy(new LoginProtectionAdmissionPolicy())
            ->withClock($clock)
            ->build();
        assert($rt instanceof AdmissionFenceControlInterface);
        $rt->openAdmissionFence('login');
        $codec = $makeCodec($cfg);
        $creds = [];
        for ($i = 0; $i < $warmup + $iters; $i++) {
            $creds[$i] = $makeCred($codec, 'acct-p-' . $i, 'inst-' . $i, 0);
        }

        return ['rt' => $rt, 'creds' => $creds];
    },
    static function (array $s, int $i): void {
        $ctx = new RateLimitContextDTO('198.18.1.' . (($i % 200) + 1), 'Mozilla/5.0 Chrome/126', 'acct-p-' . $i, ['device' => 'd-' . $i], clientInstanceId: 'inst-' . $i, admissionCredential: $s['creds'][$i]);
        $check = $s['rt']->limit($ctx, RateLimitCommand::checkOnly('login_protection'));
        $s['rt']->limit($ctx, new RateLimitCommand('login_protection', 1, false, false, true, $check->metadata?->permit));
    },
);

// 4. Opted Lane P under active outer rotation (previousKeySecret set)
$results[] = $measure(
    'opted_lane_p_outer_rotation',
    static function () use ($makeConfig, $makeCodec, $makeCred, $resp, $clock, $warmup, $iters) {
        $cfg = $makeConfig('outer-2', 'outer-1', 'bind-1');
        $store = new RedisFullCapabilityStore($resp, 'bench-rot');
        $rt = RateLimiterBuilder::fromFullCapabilityStore($cfg, $store, new RecordingFailureSignalEmitter())
            ->withPolicy(new LoginProtectionAdmissionPolicy())
            ->withClock($clock)
            ->build();
        assert($rt instanceof AdmissionFenceControlInterface);
        $rt->openAdmissionFence('login');
        $codec = $makeCodec($cfg);
        $creds = [];
        for ($i = 0; $i < $warmup + $iters; $i++) {
            $creds[$i] = $makeCred($codec, 'acct-r-' . $i, 'inst-' . $i, 0);
        }

        return ['rt' => $rt, 'creds' => $creds];
    },
    static function (array $s, int $i): void {
        $ctx = new RateLimitContextDTO('198.18.2.' . (($i % 200) + 1), 'Mozilla/5.0 Chrome/126', 'acct-r-' . $i, ['device' => 'd-' . $i], clientInstanceId: 'inst-' . $i, admissionCredential: $s['creds'][$i]);
        $check = $s['rt']->limit($ctx, RateLimitCommand::checkOnly('login_protection'));
        $s['rt']->limit($ctx, new RateLimitCommand('login_protection', 1, false, false, true, $check->metadata?->permit));
    },
);

// 5. Account revocation + bindingEpoch read
$results[] = $measure(
    'account_revocation_and_epoch',
    static function () use ($makeConfig, $resp, $clock) {
        $store = new RedisFullCapabilityStore($resp, 'bench-rev');

        return RateLimiterBuilder::fromFullCapabilityStore($makeConfig('outer-2', 'outer-1', 'bind-1'), $store, new RecordingFailureSignalEmitter())
            ->withPolicy(new LoginProtectionAdmissionPolicy())
            ->withClock($clock)
            ->build();
    },
    static function ($rt, int $i): void {
        assert($rt instanceof AdmissionBindingControlInterface && $rt instanceof AdmissionBindingEpochInterface);
        $rt->revokeAccountBinding('acct-rev-' . $i);
        $rt->bindingEpoch('acct-rev-' . $i);
    },
);

echo json_encode([
    'arm' => $arm,
    'source_root' => $srcRoot,
    'php_version' => PHP_VERSION,
    'php_int_size' => PHP_INT_SIZE,
    'redis_host' => $host,
    'redis_port' => $port,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
