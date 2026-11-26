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
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\AdmissionBindingControlInterface;
use Maatify\RateLimiter\Service\AdmissionBindingEpochInterface;
use Maatify\RateLimiter\Service\AdmissionCredentialCodec;
use Maatify\RateLimiter\Service\AdmissionFenceControlInterface;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

if ($argc < 4) {
    throw new RuntimeException('Usage: php bench.php <repoRoot> <baseline|g3> <srcRoot>');
}
$repoRoot = $argv[1];
$arm = $argv[2]; // 'baseline' or 'g3'
$srcRoot = $argv[3];

$host = getenv('REDIS_INTEGRATION_HOST');
$portRaw = getenv('REDIS_INTEGRATION_PORT');
if (! is_string($host) || $host === '' || ! is_string($portRaw) || ! ctype_digit($portRaw)) {
    throw new RuntimeException('Refusing to run without explicit isolated REDIS_INTEGRATION_HOST and REDIS_INTEGRATION_PORT.');
}
if (getenv('BENCH_ALLOW_FLUSHDB') !== '1') {
    throw new RuntimeException('Refusing destructive FLUSHDB without BENCH_ALLOW_FLUSHDB=1 on an isolated disposable Redis.');
}
$port = (int) $portRaw;

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
$timedExecutor = new class ($resp) implements RedisCommandExecutorInterface {
    public int $waitNs = 0;

    public function __construct(private readonly RespRedisCommandExecutor $inner) {}

    public function execute(array $command): mixed
    {
        $t0 = hrtime(true);
        try {
            return $this->inner->execute($command);
        } finally {
            $this->waitNs += hrtime(true) - $t0;
        }
    }
};
$now = 1_767_225_600;
$clock = new FixedClock('@' . $now);
$warmup = 15;
$iters = 150;

$gitOut = static function (string $cmd) use ($srcRoot): string {
    $lines = [];
    $exit = 0;
    exec('git -C ' . escapeshellarg($srcRoot) . ' ' . $cmd . ' 2>&1', $lines, $exit);
    if ($exit !== 0) {
        throw new RuntimeException("git {$cmd} failed (exit {$exit}): " . implode("\n", $lines));
    }

    return trim(implode("\n", $lines));
};
$sourceSha = $gitOut('rev-parse HEAD');
$sourceTree = $gitOut('rev-parse HEAD^{tree}');
$sourceDirty = $gitOut('status --porcelain') !== '';

$srcFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file instanceof SplFileInfo && $file->isFile()) {
        $rel = substr($file->getPathname(), strlen($srcRoot) + 1);
        $srcFiles[$rel] = hash_file('sha256', $file->getPathname());
    }
}
ksort($srcFiles);
$srcTreeDiskSha256 = hash('sha256', json_encode($srcFiles, JSON_THROW_ON_ERROR));

$assertPermitLane = static function (string $permit, string $expectedLane): void {
    $parts = explode('.', $permit);
    $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
    if (! is_string($json)) {
        throw new RuntimeException('Invalid permit payload encoding.');
    }
    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($decoded) || ($decoded['lane'] ?? null) !== $expectedLane) {
        $actual = is_array($decoded) ? ($decoded['lane'] ?? 'null') : 'non-array';
        throw new RuntimeException("Expected permit lane {$expectedLane}, got {$actual}.");
    }
};

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

$measure = static function (string $name, Closure $setup, Closure $step) use ($resp, $timedExecutor, $warmup, $iters, $inspectRedis): array {
    $resp->execute(['FLUSHDB']);
    $state = $setup();
    for ($i = 0; $i < $warmup; $i++) {
        $step($state, $i);
    }
    $c0 = $resp->commands;
    $bSent0 = $resp->bytesSent;
    $bRecv0 = $resp->bytesReceived;
    $wait0 = $timedExecutor->waitNs;
    $ru0 = getrusage();
    memory_reset_peak_usage();
    $times = [];
    $wall0 = hrtime(true);
    for ($i = $warmup; $i < $warmup + $iters; $i++) {
        $t0 = hrtime(true);
        $step($state, $i);
        $times[] = round((hrtime(true) - $t0) / 1000.0, 1);
    }
    $wallNs = hrtime(true) - $wall0;
    $waitNs = $timedExecutor->waitNs - $wait0;
    $ru1 = getrusage();
    $peakKb = round(memory_get_peak_usage(false) / 1024.0, 2);
    $sorted = $times;
    sort($sorted);
    $pct = static fn(float $p): float => round($sorted[min(count($sorted) - 1, (int) floor($p * (count($sorted) - 1)))], 1);
    $userMs = round(($ru1['ru_utime.tv_sec'] - $ru0['ru_utime.tv_sec']) * 1000.0 + ($ru1['ru_utime.tv_usec'] - $ru0['ru_utime.tv_usec']) / 1000.0, 2);
    $sysMs = round(($ru1['ru_stime.tv_sec'] - $ru0['ru_stime.tv_sec']) * 1000.0 + ($ru1['ru_stime.tv_usec'] - $ru0['ru_stime.tv_usec']) / 1000.0, 2);
    $wallMs = round($wallNs / 1_000_000.0, 2);
    $redisWaitMs = round($waitNs / 1_000_000.0, 2);

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
        'wall_total_ms' => $wallMs,
        'redis_wait_ms' => $redisWaitMs,
        'php_work_ms' => round(max(0.0, $wallMs - $redisWaitMs), 2),
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
    static function () use ($makeConfig, $timedExecutor, $clock) {
        $store = new RedisFullCapabilityStore($timedExecutor, 'bench-non-opted');

        return RateLimiterBuilder::fromFullCapabilityStore($makeConfig('outer-1'), $store, new RecordingFailureSignalEmitter())
            ->withPolicy(new LoginProtectionPolicy())
            ->withClock($clock)
            ->build();
    },
    static function ($rt, int $i): void {
        $ctx = new RateLimitContextDTO('198.18.0.' . (($i % 250) + 1), 'Mozilla/5.0 Chrome/126', 'nonopt-' . $i, ['device' => 'd-' . $i]);
        $check = $rt->limit($ctx, RateLimitCommand::checkOnly('login_protection'));
        $fail = $rt->limit($ctx, RateLimitCommand::recordFailure('login_protection'));
        if (! $check->isAllowed() || $fail->decision !== RateLimitResultDTO::DECISION_ALLOW || $fail->metadata?->permit !== null) {
            throw new RuntimeException("Unexpected non_opted_login result at iter {$i}");
        }
    },
);

// 2. Opted Lane U (checkOnly + recordSuccess with permit)
$results[] = $measure(
    'opted_lane_u',
    static function () use ($makeConfig, $timedExecutor, $clock) {
        $store = new RedisFullCapabilityStore($timedExecutor, 'bench-u');
        $rt = RateLimiterBuilder::fromFullCapabilityStore($makeConfig('outer-1', null, 'bind-1'), $store, new RecordingFailureSignalEmitter())
            ->withPolicy(new LoginProtectionAdmissionPolicy())
            ->withClock($clock)
            ->build();
        assert($rt instanceof AdmissionFenceControlInterface);
        $rt->openAdmissionFence('login');

        return $rt;
    },
    static function ($rt, int $i) use ($assertPermitLane): void {
        $ctx = new RateLimitContextDTO('198.18.' . intdiv($i, 20) . '.' . (($i % 20) + 1), 'Mozilla/5.0 Chrome/126', 'acct-u-' . $i, ['device' => 'd-' . $i]);
        $check = $rt->limit($ctx, RateLimitCommand::checkOnly('login_protection'));
        $permit = $check->metadata?->permit;
        if (! $check->isAllowed() || $permit === null) {
            throw new RuntimeException("Expected Lane U permit at iter {$i}");
        }
        if ($i === 0) {
            $assertPermitLane($permit, 'U');
        }
        $settle = $rt->limit($ctx, new RateLimitCommand('login_protection', 1, false, false, true, $permit));
        if (! $settle->isAllowed() || $settle->metadata?->cause !== null) {
            throw new RuntimeException("Unexpected Lane U settle failure at iter {$i}");
        }
    },
);

// 3. Opted Lane P (checkOnly + recordSuccess with credential + permit)
$results[] = $measure(
    'opted_lane_p',
    static function () use ($makeConfig, $makeCodec, $makeCred, $timedExecutor, $clock, $warmup, $iters) {
        $cfg = $makeConfig('outer-1', null, 'bind-1');
        $store = new RedisFullCapabilityStore($timedExecutor, 'bench-p');
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
    static function (array $s, int $i) use ($assertPermitLane): void {
        $ctx = new RateLimitContextDTO('198.18.1.' . (($i % 200) + 1), 'Mozilla/5.0 Chrome/126', 'acct-p-' . $i, ['device' => 'd-' . $i], clientInstanceId: 'inst-' . $i, admissionCredential: $s['creds'][$i]);
        $check = $s['rt']->limit($ctx, RateLimitCommand::checkOnly('login_protection'));
        $permit = $check->metadata?->permit;
        if (! $check->isAllowed() || $permit === null) {
            throw new RuntimeException("Expected Lane P permit at iter {$i}");
        }
        if ($i === 0) {
            $assertPermitLane($permit, 'P');
        }
        $settle = $s['rt']->limit($ctx, new RateLimitCommand('login_protection', 1, false, false, true, $permit));
        if (! $settle->isAllowed() || $settle->metadata?->cause !== null) {
            throw new RuntimeException("Unexpected Lane P settle failure at iter {$i}");
        }
    },
);

// 4. Opted Lane P with previousKeySecret configured on fresh store (previous-configured)
$results[] = $measure(
    'opted_lane_p_previous_configured',
    static function () use ($makeConfig, $makeCodec, $makeCred, $timedExecutor, $clock, $warmup, $iters) {
        $cfg = $makeConfig('outer-2', 'outer-1', 'bind-1');
        $store = new RedisFullCapabilityStore($timedExecutor, 'bench-rot');
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
    static function (array $s, int $i) use ($assertPermitLane): void {
        $ctx = new RateLimitContextDTO('198.18.2.' . (($i % 200) + 1), 'Mozilla/5.0 Chrome/126', 'acct-r-' . $i, ['device' => 'd-' . $i], clientInstanceId: 'inst-' . $i, admissionCredential: $s['creds'][$i]);
        $check = $s['rt']->limit($ctx, RateLimitCommand::checkOnly('login_protection'));
        $permit = $check->metadata?->permit;
        if (! $check->isAllowed() || $permit === null) {
            throw new RuntimeException("Expected Lane P (previous-configured) permit at iter {$i}");
        }
        if ($i === 0) {
            $assertPermitLane($permit, 'P');
        }
        $settle = $s['rt']->limit($ctx, new RateLimitCommand('login_protection', 1, false, false, true, $permit));
        if (! $settle->isAllowed() || $settle->metadata?->cause !== null) {
            throw new RuntimeException("Unexpected Lane P (previous-configured) settle failure at iter {$i}");
        }
    },
);

// 5. Account revocation + bindingEpoch read
$results[] = $measure(
    'account_revocation_and_epoch',
    static function () use ($makeConfig, $timedExecutor, $clock) {
        $store = new RedisFullCapabilityStore($timedExecutor, 'bench-rev');

        return RateLimiterBuilder::fromFullCapabilityStore($makeConfig('outer-2', 'outer-1', 'bind-1'), $store, new RecordingFailureSignalEmitter())
            ->withPolicy(new LoginProtectionAdmissionPolicy())
            ->withClock($clock)
            ->build();
    },
    static function ($rt, int $i): void {
        assert($rt instanceof AdmissionBindingControlInterface && $rt instanceof AdmissionBindingEpochInterface);
        $rt->revokeAccountBinding('acct-rev-' . $i);
        if ($rt->bindingEpoch('acct-rev-' . $i) !== 1) {
            throw new RuntimeException("Expected bindingEpoch === 1 after revoke at iter {$i}");
        }
    },
);

echo json_encode([
    'arm' => $arm,
    'source_root' => $srcRoot,
    'source_sha' => $sourceSha,
    'source_tree' => $sourceTree,
    'source_dirty' => $sourceDirty,
    'src_tree_disk_sha256' => $srcTreeDiskSha256,
    'php_version' => PHP_VERSION,
    'php_int_size' => PHP_INT_SIZE,
    'redis_host' => $host,
    'redis_port' => $port,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
