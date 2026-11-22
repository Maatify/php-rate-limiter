<?php

declare(strict_types=1);

/**
 * #284 K4 cutover-window REHEARSAL (study tool; not part of the package, not run by PHPUnit/CI; no runtime code is changed).
 *
 * Modes:  controller <out-dir>   all scenarios (one isolated Redis, workers = child processes, queues = Redis lists/zsets)
 *         worker <role>          one queue-consuming V2 writer (spawned by the controller)
 *         legacy                 one writer OUTSIDE the inventory (no queue, no heartbeat) spawned by the controller
 *         int                    32/64-bit controls of the decimal arithmetic the gate uses (no Redis)
 *
 * The activation gate reads ONLY Redis raw replies (never the harness's own bookkeeping) for barrier, continuity and block state.
 * E1 is never started: the gate only sets an experimental key.
 */

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

$mode = $argv[1] ?? '';
$root = getenv('S284_ROOT') ?: '/work';
require '/deps/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($root): void {
    foreach (['Maatify\\RateLimiter\\Tests\\' => '/tests/', 'Maatify\\RateLimiter\\' => '/src/'] as $ns => $dir) {
        if (str_starts_with($class, $ns)) {
            $file = $root . $dir . str_replace('\\', '/', substr($class, strlen($ns))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

const SECRET = 'rehearsal-284-key';
const FPSECRET = 'rehearsal-284-fp';
const ENVSCOPE = 'prod';
const PREFIX = 'maatify:rate-limiter:v1';
const APP_USERS = ['w_direct', 'w_deferred', 'w_retry', 'w_legacy'];

// ======================================================================================================================
// decimal arithmetic on strings (PEXPIRETIME / TIME are 13-digit millisecond values: they overflow a 32-bit PHP int)
// ======================================================================================================================
function dcmp(string $a, string $b): int
{
    $a = ltrim($a, '0') ?: '0';
    $b = ltrim($b, '0') ?: '0';
    if (strlen($a) !== strlen($b)) {
        return strlen($a) <=> strlen($b);
    }

    return strcmp($a, $b) <=> 0;
}

function dsub(string $a, string $b): string // a >= b, non-negative decimals
{
    $a = ltrim($a, '0') ?: '0';
    $b = ltrim($b, '0') ?: '0';
    $b = str_pad($b, strlen($a), '0', STR_PAD_LEFT);
    $r = '';
    $borrow = 0;
    for ($i = strlen($a) - 1; $i >= 0; $i--) {
        $d = (int) $a[$i] - (int) $b[$i] - $borrow;
        $borrow = $d < 0 ? 1 : 0;
        $r = (string) ($d + ($borrow ? 10 : 0)) . $r;
    }

    return ltrim($r, '0') ?: '0';
}

function isNonNegDecimal(string $s): bool
{
    return preg_match('/\A(0|[1-9][0-9]*)\z/D', $s) === 1;
}

if ($mode === 'int') {
    $checks = [
        'php_int_size' => PHP_INT_SIZE,
        'dcmp_big_gt' => dcmp('1795000000001', '1795000000000') === 1,
        'dcmp_big_eq' => dcmp('1795000000000', '1795000000000') === 0,
        'dcmp_len' => dcmp('999', '1000') === -1,
        'dsub' => dsub('1795000030000', '1795000000001') === '29999',
        'naive_int_cast_preserves_ms' => ((string) (int) '1795000000001' === '1795000000001') === (PHP_INT_SIZE === 8),
        'neg_one_not_decimal' => ! isNonNegDecimal('-1'),
        'ms_from_time' => msFromTime(['1795000000', '123456']) === '1795000000123',
    ];
    $ok = true;
    foreach ($checks as $k => $v) {
        if ($k !== 'php_int_size' && $v !== true) {
            $ok = false;
        }
    }
    echo json_encode(['mode' => 'int', 'checks' => $checks, 'ok' => $ok], JSON_PRETTY_PRINT), "\n";
    exit($ok ? 0 : 1);
}

/** @param array{0:string,1:string} $t */
function msFromTime(array $t): string
{
    return $t[0] . str_pad((string) intdiv((int) $t[1], 1000), 3, '0', STR_PAD_LEFT);
}

// ======================================================================================================================
// connection helpers
// ======================================================================================================================
function connect(string $user, string $pw): RespRedisCommandExecutor
{
    $exec = new RespRedisCommandExecutor((string) getenv('REDIS_INTEGRATION_HOST'), (int) getenv('REDIS_INTEGRATION_PORT'));
    if ($user !== '') {
        $exec->execute(['AUTH', $user, $pw]);
    }

    return $exec;
}

function pwOf(string $user): string
{
    return $user === 'ctl' ? 'ctl-pw' : 'pw-' . $user;
}

function redisMs(RespRedisCommandExecutor $c): string
{
    $t = $c->execute(['TIME']);
    assert(is_array($t));

    return msFromTime([(string) $t[0], (string) $t[1]]);
}

function nsKeyPattern(string $ns, string $family = 'block'): string
{
    return PREFIX . ':' . hash('sha256', $ns) . ':' . $family . ':*';
}

/** @return list<string> */
function scanAll(RespRedisCommandExecutor $c, string $pattern): array
{
    $cursor = '0';
    $keys = [];
    do {
        $r = $c->execute(['SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '200']);
        assert(is_array($r));
        $cursor = (string) $r[0];
        foreach ((array) $r[1] as $k) {
            $keys[(string) $k] = true;
        }
    } while ($cursor !== '0');

    return array_keys($keys);
}

function jlog(string $tag, array $data = []): void
{
    echo json_encode(['t' => gmdate('H:i:s'), 'tag' => $tag] + $data, JSON_UNESCAPED_SLASHES), "\n";
}

/** @return array{limiter: object, reader: object, store: RedisFullCapabilityStore} */
function runtime(RespRedisCommandExecutor $exec, string $ns): array
{
    $store = new RedisFullCapabilityStore($exec, $ns);
    $builder = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig(SECRET, FPSECRET, ENVSCOPE), $store, new RecordingFailureSignalEmitter());

    return ['limiter' => $builder->build(), 'reader' => $builder->buildOperationalReader(), 'store' => $store];
}

function ctxFor(string $account): RateLimitContextDTO
{
    return new RateLimitContextDTO('198.51.100.' . (1 + (crc32($account) % 200)), 'Mozilla/5.0 Chrome/120.0', $account);
}

function k4Hmac(string $policy, string $account): string
{
    return hash_hmac('sha256', "{$policy}:rate_limiter:k4:v2:" . ENVSCOPE . ":{$account}", SECRET);
}

function runCommand(array $rt, string $policy, string $account, string $kind): void
{
    $cmd = match ($kind) {
        'failure' => RateLimitCommand::recordFailure($policy),
        'success' => RateLimitCommand::recordSuccess($policy),
        default => RateLimitCommand::checkOnly($policy),
    };
    $rt['limiter']->limit(ctxFor($account), $cmd);
}

// ======================================================================================================================
// worker roles
// ======================================================================================================================
if ($mode === 'worker' || $mode === 'legacy') {
    $role = $mode === 'legacy' ? 'legacy' : (string) ($argv[2] ?? 'direct');
    $user = 'w_' . $role;
    $policyNs = json_decode((string) getenv('S284_POLICY_NS'), true);
    assert(is_array($policyNs));
    $exec = connect($user, pwOf($user));
    $rts = [];
    foreach ($policyNs as $p => $ns) {
        $rts[$p] = runtime($exec, (string) $ns);
    }
    $id = $role . '-' . getmypid();
    $stats = ['done' => 0, 'errors' => 0, 'noperm' => 0, 'ok_after_barrier' => 0, 'last_ok_ms' => '0', 'first_noperm_ms' => '0'];
    $barrierAt = static function () use ($exec): string {
        try {
            return (string) $exec->execute(['GET', 'rehearsal:barrier_ms']);
        } catch (\Throwable) {
            return '';
        }
    };
    $note = static function (\Throwable $e) use (&$stats, $exec): void {
        $stats['errors']++;
        if (stripos($e->getMessage(), 'NOPERM') !== false || stripos($e->getMessage(), "can't access") !== false || stripos($e->getMessage(), 'permission') !== false) {
            $stats['noperm']++;
            if ($stats['first_noperm_ms'] === '0') {
                $stats['first_noperm_ms'] = redisMs($exec);
            }
        }
    };
    $mover = "local j=redis.call('ZRANGEBYSCORE',KEYS[1],'-inf',ARGV[1],'LIMIT',0,1) if #j==0 then return false end "
        . "redis.call('ZREM',KEYS[1],j[1]) redis.call('LPUSH',KEYS[2],j[1]) return j[1]";
    $flush = static function () use ($exec, $id, $role, &$stats, $user): void {
        $f = ['role', $role, 'user', $user, 'done', (string) $stats['done'], 'errors', (string) $stats['errors'], 'noperm', (string) $stats['noperm'],
            'last_ok_ms', $stats['last_ok_ms'], 'first_noperm_ms', $stats['first_noperm_ms'], 'ts', redisMs($exec)];
        try {
            $exec->execute(['HSET', 'rehearsal:wstats:' . $id, ...$f]);
        } catch (\Throwable) {
        }
    };
    while (true) {
        try {
            if ((string) $exec->execute(['GET', 'rehearsal:stop']) === '1') {
                $flush();
                break;
            }
        } catch (\Throwable) {
            usleep(100000);
            continue;
        }
        if ($role === 'legacy') {
            // An unregistered writer: no queue, no heartbeat; it simply writes on its own schedule through the public API.
            $p = array_keys($rts)[random_int(0, count($rts) - 1)];
            try {
                runCommand($rts[$p], $p, 'acct-legacy-' . random_int(1, 5), 'failure');
                $stats['done']++;
                $stats['last_ok_ms'] = redisMs($exec);
            } catch (\Throwable $e) {
                $note($e);
            }
            $flush();
            usleep(60000);
            continue;
        }
        $took = false;
        foreach (array_keys($rts) as $p) {
            $proc = "rehearsal:q:proc:{$id}";
            try {
                if ($role === 'direct') {
                    $job = $exec->execute(['LMOVE', "rehearsal:q:ready:{$p}", $proc, 'LEFT', 'RIGHT']);
                } else {
                    $source = $role === 'deferred' ? "rehearsal:q:delayed:{$p}" : "rehearsal:q:retry:{$p}";
                    $job = $exec->execute(['EVAL', $mover, '2', $source, $proc, redisMs($exec)]);
                }
            } catch (\Throwable $e) {
                $note($e);
                continue;
            }
            if (! is_string($job)) {
                continue;
            }
            $took = true;
            $exec->execute(['HSET', 'rehearsal:hb:' . $id, 'role', $role, 'inflight', '1', 'ts', redisMs($exec)]);
            $j = json_decode($job, true);
            assert(is_array($j));
            if (($j['hang_ms'] ?? 0) > 0) {
                usleep((int) $j['hang_ms'] * 1000);
            }
            try {
                runCommand($rts[$p], $p, (string) $j['account'], (string) $j['kind']);
                $stats['done']++;
                $stats['last_ok_ms'] = redisMs($exec);
                if (($j['retry_after_write'] ?? false) && ($j['attempt'] ?? 0) === 0) {
                    // the write happened but the downstream step "failed": the job is re-sent later, so the same write is made again.
                    $j['attempt'] = 1;
                    $exec->execute(['ZADD', "rehearsal:q:retry:{$p}", (string) ((int) redisMs($exec) + 1500), json_encode($j)]);
                }
            } catch (\Throwable $e) {
                $note($e);
            }
            $exec->execute(['LREM', $proc, '1', $job]);
            $exec->execute(['HSET', 'rehearsal:hb:' . $id, 'inflight', '0', 'ts', redisMs($exec)]);
            $flush();
        }
        if (! $took) {
            $exec->execute(['HSET', 'rehearsal:hb:' . $id, 'role', $role, 'inflight', '0', 'ts', redisMs($exec)]);
            $flush();
            usleep(25000);
        }
    }
    exit(0);
}

// ======================================================================================================================
// controller
// ======================================================================================================================
if ($mode !== 'controller') {
    fwrite(STDERR, "usage: rehearsal.php controller|worker|legacy|int\n");
    exit(2);
}

$outDir = (string) ($argv[2] ?? '/out');
@mkdir($outDir, 0777, true);
$first = connect('', '');
ensureCtl($first);
$bootstrap = connect('ctl', pwOf('ctl'));

function setupRedis(RespRedisCommandExecutor $c): void
{
    $c->execute(['FLUSHALL']);
    $c->execute(['ACL', 'LOG', 'RESET']);
    foreach (APP_USERS as $u) {
        $c->execute(['ACL', 'SETUSER', $u, 'reset', 'on', '>' . pwOf($u), '~*', '+@all']);
    }
}

function ensureCtl(RespRedisCommandExecutor $c): void
{
    $c->execute(['ACL', 'SETUSER', 'ctl', 'reset', 'on', '>' . pwOf('ctl'), '~*', '&*', '+@all']);
}

/** @return array<string,mixed> */
function baseline(RespRedisCommandExecutor $ctl): array
{
    $ctl->execute(['SET', 'rehearsal:sentinel', 'S284']);
    $server = (string) $ctl->execute(['INFO', 'server']);
    $stats = (string) $ctl->execute(['INFO', 'stats']);
    preg_match('/run_id:(\S+)/', $server, $r);
    preg_match('/uptime_in_seconds:(\d+)/', $server, $u);
    preg_match('/evicted_keys:(\d+)/', $stats, $e);
    $mp = $ctl->execute(['CONFIG', 'GET', 'maxmemory-policy']);

    return ['run_id' => $r[1] ?? '', 'uptime' => (int) ($u[1] ?? 0), 'evicted_keys' => (int) ($e[1] ?? -1), 'maxmemory_policy' => is_array($mp) ? (string) ($mp[1] ?? '') : ''];
}

/** @param array<string,string> $policyNs */
function v2Digest(RespRedisCommandExecutor $ctl): string
{
    $keys = scanAll($ctl, PREFIX . ':*');
    sort($keys);
    $acc = '';
    foreach ($keys as $k) {
        $dump = $ctl->execute(['DUMP', $k]);
        $acc .= $k . '|' . $ctl->execute(['PEXPIRETIME', $k]) . '|' . sha1((string) $dump) . "\n";
    }

    return sha1($acc) . ':' . count($keys);
}

/** @return array<string,array{pe:string,h:string}> every V2 key of the store with its expiry and a content hash */
function v2Map(RespRedisCommandExecutor $ctl): array
{
    $m = [];
    foreach (scanAll($ctl, PREFIX . ':*') as $k) {
        $m[$k] = ['pe' => (string) $ctl->execute(['PEXPIRETIME', $k]), 'h' => sha1((string) $ctl->execute(['DUMP', $k]))];
    }

    return $m;
}

/** K4 state per family for the lab's known accounts: the physical key is derivable here because the lab knows the secret. @return array<string,mixed> */
function k4Census(RespRedisCommandExecutor $ctl, string $policy, string $ns): array
{
    $accounts = ['acct-hot-1'];
    for ($i = 1; $i <= 12; $i++) {
        $accounts[] = 'acct-' . $i;
    }
    $fam = ['score', 'block', 'budget', 'cycle', 'pause', 'watch', 'distinct'];
    $out = ['families' => array_fill_keys($fam, 0), 'k4_block_max_expiry_ms' => '0'];
    foreach ($accounts as $a) {
        $logical = k4Hmac($policy, $a);
        foreach ($fam as $f) {
            $k = PREFIX . ':' . hash('sha256', $ns) . ':' . $f . ':' . hash('sha256', $logical);
            if ((int) $ctl->execute(['EXISTS', $k]) === 1) {
                $out['families'][$f]++;
                if ($f === 'block') {
                    $pe = (string) $ctl->execute(['PEXPIRETIME', $k]);
                    if (isNonNegDecimal($pe) && dcmp($pe, $out['k4_block_max_expiry_ms']) > 0) {
                        $out['k4_block_max_expiry_ms'] = $pe;
                    }
                }
            }
        }
    }

    return $out;
}

/** The activation gate. Reads Redis only. @return array{ok: bool, reasons: list<string>, detail: array<string,mixed>} */
function gate(RespRedisCommandExecutor $ctl, array $base, array $namespaces, ?array $firstScan = null): array
{
    $reasons = [];
    $d = [];
    // G1 intake closed and static
    $d['intake'] = (string) $ctl->execute(['GET', 'rehearsal:intake']);
    $p1 = (string) $ctl->execute(['GET', 'rehearsal:feeder_pushed']);
    usleep(300000);
    $p2 = (string) $ctl->execute(['GET', 'rehearsal:feeder_pushed']);
    $d['feeder_pushed'] = [$p1, $p2];
    if ($d['intake'] !== 'closed' || $p1 !== $p2) {
        $reasons[] = 'INTAKE_NOT_CLOSED';
    }
    // G2 drain: every queue empty, no in-flight heartbeat
    $queues = [];
    foreach (scanAll($ctl, 'rehearsal:q:*') as $k) {
        $type = (string) $ctl->execute(['TYPE', $k]);
        $n = $type === 'zset' ? $ctl->execute(['ZCARD', $k]) : $ctl->execute(['LLEN', $k]);
        if ((int) $n !== 0) {
            $queues[$k] = (int) $n;
        }
    }
    $inflight = [];
    foreach (scanAll($ctl, 'rehearsal:hb:*') as $k) {
        if ((string) $ctl->execute(['HGET', $k, 'inflight']) !== '0') {
            $inflight[] = $k;
        }
    }
    $d['queues_nonempty'] = $queues;
    $d['inflight_workers'] = $inflight;
    if ($queues !== [] || $inflight !== []) {
        $reasons[] = 'NOT_DRAINED';
    }
    // G3 write barrier: every user except ctl is off or write-denied on a V2 key (ACL DRYRUN, from the server's own ACL table)
    $users = (array) $ctl->execute(['ACL', 'USERS']);
    $holes = [];
    $probeKey = PREFIX . ':gate-probe:block:x';
    foreach ($users as $u) {
        $u = (string) $u;
        if ($u === 'ctl') {
            continue;
        }
        $g = (array) $ctl->execute(['ACL', 'GETUSER', $u]);
        $flags = [];
        for ($i = 0; $i + 1 < count($g); $i += 2) {
            if ($g[$i] === 'flags') {
                $flags = (array) $g[$i + 1];
            }
        }
        if (in_array('off', $flags, true)) {
            continue;
        }
        foreach ([['SET', $probeKey, 'v'], ['DEL', $probeKey], ['HSET', $probeKey, 'f', 'v'], ['EXPIRE', $probeKey, '9'], ['PEXPIREAT', $probeKey, '9'], ['INCRBY', $probeKey, '1'], ['ZADD', $probeKey, '1', 'm']] as $cmd) {
            $res = $ctl->execute(['ACL', 'DRYRUN', $u, ...$cmd]);
            if ($res === 'OK') {
                $holes[] = $u . ':' . $cmd[0];
            }
        }
    }
    $d['barrier_holes'] = $holes;
    if ($holes !== []) {
        $reasons[] = 'WRITE_BARRIER_HOLE';
    }
    // G4 continuity of the SAME Redis data
    $server = (string) $ctl->execute(['INFO', 'server']);
    $stats = (string) $ctl->execute(['INFO', 'stats']);
    preg_match('/run_id:(\S+)/', $server, $r);
    preg_match('/uptime_in_seconds:(\d+)/', $server, $u);
    preg_match('/evicted_keys:(\d+)/', $stats, $e);
    $sentinel = $ctl->execute(['GET', 'rehearsal:sentinel']);
    $sentinelTtl = $ctl->execute(['PTTL', 'rehearsal:sentinel']);
    $mp = $ctl->execute(['CONFIG', 'GET', 'maxmemory-policy']);
    $cont = ['run_id_same' => ($r[1] ?? '') === $base['run_id'], 'uptime_monotonic' => (int) ($u[1] ?? 0) >= $base['uptime'],
        'evicted_same' => (int) ($e[1] ?? -2) === $base['evicted_keys'], 'sentinel_intact' => $sentinel === 'S284' && $sentinelTtl === -1,
        'policy_noeviction' => is_array($mp) && ($mp[1] ?? '') === 'noeviction'];
    $d['continuity'] = $cont;
    if (in_array(false, $cont, true)) {
        $reasons[] = 'DATA_CONTINUITY_BROKEN';
    }
    // G5 block namespaces: full SCAN on this primary, classification by PEXPIRETIME against Redis TIME
    $now = redisMs($ctl);
    $d['redis_now_ms'] = $now;
    $blocks = [];
    foreach ($namespaces as $ns) {
        foreach (scanAll($ctl, nsKeyPattern($ns)) as $k) {
            $pe = (string) $ctl->execute(['PEXPIRETIME', $k]);
            $blocks[$k] = $pe;
        }
    }
    $active = [];
    $unknown = [];
    $expiredPresent = [];
    $gone = [];
    foreach ($blocks as $k => $pe) {
        if ($pe === '-2') {
            $gone[] = $k;
        } elseif ($pe === '-1' || ! isNonNegDecimal($pe)) {
            $unknown[$k] = $pe;
        } elseif (dcmp($pe, $now) > 0) {
            $active[$k] = dsub($pe, $now);
        } else {
            $expiredPresent[] = $k;
        }
    }
    $d['block_keys_seen'] = count($blocks);
    $d['active_remaining_ms'] = $active;
    $d['unknown_expiry'] = $unknown;
    $d['expired_but_present'] = count($expiredPresent);
    if ($unknown !== []) {
        $reasons[] = 'BLOCK_UNKNOWN_EXPIRY';
    }
    if ($active !== []) {
        $reasons[] = 'BLOCK_ACTIVE';
    }
    // G5b unexplained absence / new keys relative to the first scan (a vanished block must have reached its recorded expiry)
    if ($firstScan !== null) {
        $unexplained = [];
        foreach ($firstScan as $k => $pe) {
            if (isNonNegDecimal((string) $pe) && ! array_key_exists($k, $blocks) && dcmp((string) $pe, $now) > 0) {
                $unexplained[] = $k;
            }
        }
        $new = array_values(array_diff(array_keys($blocks), array_keys($firstScan)));
        $d['unexplained_absence'] = $unexplained;
        $d['new_since_first_scan'] = $new;
        if ($unexplained !== []) {
            $reasons[] = 'BLOCK_UNEXPLAINED_ABSENCE';
        }
        if ($new !== []) {
            $reasons[] = 'BLOCK_NEW_SINCE_FIRST_SCAN';
        }
    }
    $d['scan_snapshot'] = $blocks;

    return ['ok' => $reasons === [], 'reasons' => $reasons, 'detail' => $d];
}

final class Scenario
{
    /** @var array<string,resource> */
    public array $procs = [];
    public RespRedisCommandExecutor $ctl;
    public array $base = [];
    public array $timeline = [];
    public array $policyNs;

    public function __construct(public string $name, array $policyNs, private string $redisHost)
    {
        $this->policyNs = $policyNs;
    }

    public function mark(string $event): string
    {
        $ms = redisMs($this->ctl);
        $this->timeline[$event] = $ms;
        jlog('timeline', ['scenario' => $this->name, 'event' => $event, 'redis_ms' => $ms]);

        return $ms;
    }

    public function setup(RespRedisCommandExecutor $bootstrap): void
    {
        setupRedis($bootstrap);
        $this->ctl = connect('ctl', pwOf('ctl'));
        $this->ctl->execute(['ACL', 'SETUSER', 'default', 'off']);
        $this->base = baseline($this->ctl);
        $this->ctl->execute(['SET', 'rehearsal:intake', 'open']);
        $this->ctl->execute(['SET', 'rehearsal:feeder_pushed', '0']);
        jlog('baseline', ['scenario' => $this->name, 'baseline' => $this->base, 'policy_ns' => $this->policyNs]);
    }

    public function spawn(string $label, string $mode, string $role = ''): void
    {
        $env = ['S284_POLICY_NS' => json_encode($this->policyNs), 'S284_ROOT' => (string) getenv('S284_ROOT'), 'REDIS_INTEGRATION_HOST' => (string) getenv('REDIS_INTEGRATION_HOST'),
            'REDIS_INTEGRATION_PORT' => (string) getenv('REDIS_INTEGRATION_PORT'), 'PATH' => (string) getenv('PATH')];
        $cmd = [PHP_BINARY, __FILE__, $mode];
        if ($role !== '') {
            $cmd[] = $role;
        }
        $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/tmp/w-' . $this->name . '-' . $label . '.out', 'w'], 2 => ['file', '/tmp/w-' . $this->name . '-' . $label . '.err', 'w']], $pipes, null, $env);
        assert(is_resource($p));
        $this->procs[$label] = $p;
    }

    public function startWorkers(bool $withLegacy = true): void
    {
        foreach (['direct', 'direct', 'deferred', 'retry'] as $i => $role) {
            $this->spawn($role . $i, 'worker', $role);
        }
        if ($withLegacy) {
            $this->spawn('legacy', 'legacy');
        }
        usleep(800000);
    }

    /** push synthetic traffic for $seconds: normal, delayed (deferred) and retry-after-write jobs */
    public function feed(float $seconds): int
    {
        $n = 0;
        $end = microtime(true) + $seconds;
        $policies = array_keys($this->policyNs);
        while (microtime(true) < $end) {
            foreach ($policies as $p) {
                $acct = 'acct-' . random_int(1, 12);
                if (random_int(1, 4) === 1) {
                    $acct = 'acct-hot-1';
                }
                $kind = ['failure', 'failure', 'failure', 'check', 'success'][random_int(0, 4)];
                $job = ['account' => $acct, 'kind' => $kind, 'attempt' => 0, 'retry_after_write' => random_int(1, 8) === 1];
                $r = random_int(1, 10);
                if ($r === 1) {
                    $this->ctl->execute(['ZADD', "rehearsal:q:delayed:{$p}", (string) ((int) redisMs($this->ctl) + 2000), json_encode($job)]);
                } else {
                    $this->ctl->execute(['RPUSH', "rehearsal:q:ready:{$p}", json_encode($job)]);
                }
                $this->ctl->execute(['INCR', 'rehearsal:feeder_pushed']);
                $n++;
            }
            usleep(40000);
        }

        return $n;
    }

    public function closeIntake(): void
    {
        $this->ctl->execute(['SET', 'rehearsal:intake', 'closed']);
        $this->mark('intake_closed');
    }

    public function waitDrained(int $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $g = gate($this->ctl, $this->base, array_values(array_unique($this->policyNs)));
            if (! in_array('NOT_DRAINED', $g['reasons'], true)) {
                $this->mark('drained');

                return true;
            }
            usleep(200000);
        } while (microtime(true) < $deadline);

        return false;
    }

    public function applyBarrier(array $users = APP_USERS): void
    {
        $this->mark('barrier_start');
        $this->ctl->execute(['SET', 'rehearsal:barrier_ms', redisMs($this->ctl)]);
        foreach ($users as $u) {
            $this->ctl->execute(['ACL', 'SETUSER', $u, 'resetkeys', '%R~' . PREFIX . ':*', '~rehearsal:*']);
        }
        $this->mark('barrier_applied');
    }

    /** @return array<string,mixed> */
    public function probes(): array
    {
        $out = ['per_user' => [], 'digest_before' => v2Digest($this->ctl)];
        $logBefore = count((array) $this->ctl->execute(['ACL', 'LOG']));
        $firstNs = (string) array_values($this->policyNs)[0];
        $key = k4Hmac('login_protection', 'acct-probe');
        foreach (APP_USERS as $u) {
            $res = [];
            try {
                $c = connect($u, pwOf($u));
                $store = new RedisFullCapabilityStore($c, $firstNs);
                $now = time();
                $ops = [
                    'incrementAt' => fn() => $store->incrementAt($key, 60, 1, $now),
                    'setAt' => fn() => $store->setAt($key, 1, 60, $now),
                    'blockAt' => fn() => $store->blockAt($key, 2, 30, $now),
                    'replaceScoreAt' => fn() => $store->replaceScoreAt($key, 5, 60, $now),
                    'incrementBudgetAt' => fn() => $store->incrementBudgetAt($key, 60, 1, $now),
                    'addDistinctAt' => fn() => $store->addDistinctAt($key, 'm', 60, $now),
                    'incrementWatchFlagAt' => fn() => $store->incrementWatchFlagAt($key, 60, $now),
                    'bumpBindingEpoch' => fn() => $store->bumpBindingEpoch($key, null),
                    'claimPostPunishmentReentryAt' => fn() => $store->claimPostPunishmentReentryAt($key, null, 'life-1', $now * 1000),
                    'limit_recordFailure' => function () use ($c, $firstNs): void {
                        runCommand(runtime($c, $firstNs), 'login_protection', 'acct-probe', 'failure');
                    },
                    'raw_SET' => fn() => $c->execute(['SET', PREFIX . ':probe:block:raw', '1']),
                    'raw_EVAL_HSET' => fn() => $c->execute(['EVAL', "return redis.call('HSET',KEYS[1],'f','v')", '1', PREFIX . ':probe:block:eval']),
                ];
                foreach ($ops as $name => $fn) {
                    try {
                        $fn();
                        $res[$name] = 'WROTE';
                    } catch (\Throwable $e) {
                        $m = $e->getMessage();
                        $res[$name] = (stripos($m, 'NOPERM') !== false || stripos($m, "can't access") !== false || stripos($m, 'permission') !== false) ? 'DENIED' : 'ERROR:' . substr($m, 0, 80);
                    }
                }
            } catch (\Throwable $e) {
                $res['connect'] = 'ERROR:' . substr($e->getMessage(), 0, 80);
            }
            $out['per_user'][$u] = $res;
        }
        $out['digest_after'] = v2Digest($this->ctl);
        $log = (array) $this->ctl->execute(['ACL', 'LOG', '200']);
        $out['acl_log_entries_new'] = count($log) - $logBefore;
        $out['acl_log_sample'] = array_slice(array_map(static function ($e): array {
            $a = (array) $e;
            $m = [];
            for ($i = 0; $i + 1 < count($a); $i += 2) {
                if (in_array($a[$i], ['reason', 'context', 'object', 'username'], true)) {
                    $m[(string) $a[$i]] = $a[$i + 1];
                }
            }

            return $m;
        }, $log), 0, 5);

        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    public function workerStats(): array
    {
        $o = [];
        foreach (scanAll($this->ctl, 'rehearsal:wstats:*') as $k) {
            $h = (array) $this->ctl->execute(['HGETALL', $k]);
            $m = [];
            for ($i = 0; $i + 1 < count($h); $i += 2) {
                $m[(string) $h[$i]] = (string) $h[$i + 1];
            }
            $o[$k] = $m;
        }

        return $o;
    }

    public function stopAll(): void
    {
        try {
            $this->ctl->execute(['SET', 'rehearsal:stop', '1']);
        } catch (\Throwable) {
        }
        foreach ($this->procs as $p) {
            $deadline = microtime(true) + 4;
            while (microtime(true) < $deadline && (proc_get_status($p)['running'] ?? false)) {
                usleep(100000);
            }
            if (proc_get_status($p)['running'] ?? false) {
                proc_terminate($p, 9);
            }
            proc_close($p);
        }
        $this->procs = [];
    }
}

$redisHost = (string) getenv('REDIS_INTEGRATION_HOST');
$results = ['base' => ['php' => PHP_VERSION, 'php_int_size' => PHP_INT_SIZE, 'ext_redis' => phpversion('redis') ?: null], 'scenarios' => []];
$failedControls = 0;
$expect = static function (string $id, bool $ok, array $detail = []) use (&$failedControls): void {
    if (! $ok) {
        $failedControls++;
    }
    jlog('control', ['id' => $id, 'ok' => $ok] + $detail);
};

/**
 * One full rehearsal: load -> close intake -> drain -> barrier+probes -> gate while a block is active (must DENY) ->
 * first scan -> wait for natural expiry -> final scan + gate (must PASS) -> validity hold -> experimental signal -> reopen.
 */
$fullRun = static function (string $name, array $policyNs, int $syntheticBlockSeconds) use ($bootstrap, $redisHost, &$results, $expect): array {
    $s = new Scenario($name, $policyNs, $redisHost);
    $s->setup($bootstrap);
    $namespaces = array_values(array_unique($policyNs));
    $s->startWorkers();
    // synthetic active block per affected namespace, created by the public store API; absolute expiry read OUTSIDE Lua with PEXPIRETIME
    $synth = [];
    foreach ($namespaces as $i => $ns) {
        $store = new RedisFullCapabilityStore($s->ctl, $ns);
        $key = k4Hmac('login_protection', 'acct-synth-' . $i);
        $dur = $syntheticBlockSeconds + 10 * $i;
        $store->blockAt($key, 3, $dur, time());
        $phys = PREFIX . ':' . hash('sha256', $ns) . ':block:' . hash('sha256', $key);
        $synth[$ns] = ['key' => $phys, 'duration_s' => $dur, 'pexpiretime_raw' => (string) $s->ctl->execute(['PEXPIRETIME', $phys]), 'redis_now_ms' => redisMs($s->ctl)];
    }
    $pushed = $s->feed(5.0);
    $s->mark('load_end');
    // representability: per policy, which K4 families (derived from the lab's known secret) hold state after the flows; plus the public reader's K4 score
    $k4 = [];
    foreach ($policyNs as $p => $ns) {
        $r = runtime($s->ctl, (string) $ns);
        $score = -1;
        try {
            $snap = $r['reader']->readScorePolicy(ctxFor('acct-hot-1'), $p);
            $score = (int) ($snap->scopes->k4?->score?->value ?? 0);
        } catch (\Throwable) {
        }
        $k4[$p] = ['census' => k4Census($s->ctl, $p, (string) $ns), 'reader_k4_score_hot_account' => $score, 'block_keys_in_ns_all_scopes' => count(scanAll($s->ctl, nsKeyPattern((string) $ns)))];
    }
    // a previous-generation block (previous key secret) lives in the SAME block family and must be seen by the same scan
    $prevNs = (string) array_values($policyNs)[0];
    $prevLogical = hash_hmac('sha256', 'login_protection:rate_limiter:k4:v2:' . ENVSCOPE . ':acct-synth-prev', SECRET . '-PREVIOUS');
    (new RedisFullCapabilityStore($s->ctl, $prevNs))->blockAt($prevLogical, 3, $syntheticBlockSeconds, time());
    $prevPhys = PREFIX . ':' . hash('sha256', $prevNs) . ':block:' . hash('sha256', $prevLogical);
    $synthPrev = ['key' => $prevPhys, 'pexpiretime_raw' => (string) $s->ctl->execute(['PEXPIRETIME', $prevPhys])];
    $mapBefore = [];
    $s->closeIntake();
    $s->timeline['writes_before_barrier'] = (string) array_sum(array_map(static fn($w) => (int) ($w['done'] ?? 0), $s->workerStats()));
    $drained = $s->waitDrained(30);
    $expect($name . ':drained', $drained);
    $s->applyBarrier();
    $legacyAfter = null;
    usleep(800000);
    $probes = $s->probes();
    $s->mark('probes_done');
    $denied = true;
    foreach ($probes['per_user'] as $u => $ops) {
        foreach ($ops as $op => $res) {
            if ($res !== 'DENIED') {
                $denied = false;
            }
        }
    }
    $expect($name . ':late_writes_denied_all_sources', $denied && $probes['digest_before'] === $probes['digest_after'], ['digest_equal' => $probes['digest_before'] === $probes['digest_after']]);
    $mapFirst = v2Map($s->ctl);
    $g1 = gate($s->ctl, $s->base, $namespaces);
    $s->mark('gate_while_block_active');
    $expect($name . ':gate_denies_while_block_active', ! $g1['ok'] && in_array('BLOCK_ACTIVE', $g1['reasons'], true), ['reasons' => $g1['reasons']]);
    // first scan: ids + absolute expiries
    $first = [];
    $maxExp = '0';
    foreach ($namespaces as $ns) {
        foreach (scanAll($s->ctl, nsKeyPattern($ns)) as $k) {
            $pe = (string) $s->ctl->execute(['PEXPIRETIME', $k]);
            $first[$k] = $pe;
            if (isNonNegDecimal($pe) && dcmp($pe, $maxExp) > 0) {
                $maxExp = $pe;
            }
        }
    }
    $s->mark('first_scan_done');
    $s->timeline['max_block_expiry_ms'] = $maxExp;
    while (dcmp(redisMs($s->ctl), $maxExp) <= 0) {
        usleep(200000);
    }
    usleep(1200000); // Redis expires lazily/actively; the final scan below must still find no active block regardless
    $s->mark('natural_expiry_passed');
    $final = gate($s->ctl, $s->base, $namespaces, $first);
    $s->mark('final_scan_done');
    $mapFinal = v2Map($s->ctl);
    $modified = [];
    foreach ($mapFinal as $k => $v) {
        if (! isset($mapFirst[$k]) || $mapFirst[$k] !== $v) {
            $modified[] = $k;
        }
    }
    $expect($name . ':no_v2_key_created_or_modified_between_first_scan_and_final', $modified === [], ['modified_or_new' => array_slice($modified, 0, 5), 'keys_first' => count($mapFirst), 'keys_final' => count($mapFinal)]);
    $expect($name . ':previous_generation_block_seen_by_first_scan', array_key_exists($synthPrev['key'], $first), ['previous_key_pexpiretime' => $synthPrev['pexpiretime_raw']]);
    $expect($name . ':gate_passes_after_natural_expiry', $final['ok'], ['reasons' => $final['reasons']]);
    // validity hold: the proof must still hold some seconds later, immediately before the (experimental) activation signal
    $permitted = false;
    if ($final['ok']) {
        sleep(3);
        $recheck = gate($s->ctl, $s->base, $namespaces, $first);
        if ($recheck['ok']) {
            $s->ctl->execute(['SET', 'rehearsal:activation:allowed', redisMs($s->ctl)]);
            $permitted = true;
            $s->mark('activation_signal_set');
        }
        $expect($name . ':validity_recheck_before_signal', $recheck['ok'], ['reasons' => $recheck['reasons']]);
    }
    // writers' own record of post-barrier behaviour (live connections made before the barrier)
    $ws = $s->workerStats();
    $postBarrierOk = 0;
    foreach ($ws as $w) {
        if (($w['role'] ?? '') === 'legacy' && dcmp((string) ($w['last_ok_ms'] ?? '0'), (string) ($s->timeline['barrier_applied'] ?? '0')) > 0) {
            $postBarrierOk++;
        }
    }
    $expect($name . ':legacy_live_connection_no_success_after_barrier', $postBarrierOk === 0, ['legacy_stats' => array_values(array_filter($ws, static fn($w) => ($w['role'] ?? '') === 'legacy'))]);
    // reopen (to V2: E1 does not exist): restore write permission, reopen intake, first success measured
    foreach (APP_USERS as $u) {
        $s->ctl->execute(['ACL', 'SETUSER', $u, 'resetkeys', '~*']);
    }
    $s->ctl->execute(['SET', 'rehearsal:intake', 'open']);
    $s->mark('reopen_requested');
    $s->ctl->execute(['RPUSH', 'rehearsal:q:ready:' . array_key_first($policyNs), json_encode(['account' => 'acct-reopen', 'kind' => 'failure', 'attempt' => 0])]);
    $ok = false;
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline && ! $ok) {
        foreach ($s->workerStats() as $w) {
            if (($w['role'] ?? '') === 'direct' && dcmp((string) ($w['last_ok_ms'] ?? '0'), (string) $s->timeline['reopen_requested']) > 0) {
                $ok = true;
            }
        }
        usleep(150000);
    }
    $s->mark('first_success_after_reopen');
    $expect($name . ':reopen_resumes_service', $ok);
    $s->stopAll();
    $t = $s->timeline;
    $ms = static fn(string $a, string $b): string => isset($t[$a], $t[$b]) ? dsub($t[$b], $t[$a]) : 'n/a';
    $measured = [
        'drain_ms' => $ms('intake_closed', 'drained'),
        'barrier_and_probes_ms' => $ms('drained', 'probes_done'),
        'first_scan_ms' => $ms('probes_done', 'first_scan_done'),
        'wait_for_natural_expiry_ms' => $ms('first_scan_done', 'natural_expiry_passed'),
        'final_scan_and_gate_ms' => $ms('natural_expiry_passed', 'final_scan_done'),
        'validity_hold_to_signal_ms' => $ms('final_scan_done', 'activation_signal_set'),
        'reopen_to_first_success_ms' => $ms('reopen_requested', 'first_success_after_reopen'),
        'total_stop_to_service_ms' => $ms('intake_closed', 'first_success_after_reopen'),
    ];
    $res = ['name' => $name, 'policy_ns' => $policyNs, 'jobs_pushed' => $pushed, 'k4_representability' => $k4, 'synthetic_blocks' => $synth, 'synthetic_previous_generation_block' => $synthPrev, 'timeline_redis_ms' => $t,
        'measured' => $measured, 'probes' => $probes, 'gate_while_block_active' => $g1, 'gate_final' => $final, 'activation_permitted' => $permitted, 'worker_stats' => $ws];
    $results['scenarios'][$name] = $res;
    jlog('scenario_result', ['scenario' => $name, 'measured' => $measured, 'activation_permitted' => $permitted, 'k4' => $k4]);

    return $res;
};

// ---- positive scenarios: one namespace per policy, then a shared namespace -----------------------------------------------------
$fullRun('A_login_protection_own_ns', ['login_protection' => 'rl-login'], 20);
$fullRun('B_otp_protection_own_ns', ['otp_protection' => 'rl-otp'], 20);
$fullRun('C_api_heavy_protection_own_ns', ['api_heavy_protection' => 'rl-api'], 20);
// shared: login and otp share ONE namespace; the longer synthetic block belongs to a key of that shared namespace, so login's gate waits for it.
$fullRun('D_login_and_otp_shared_ns', ['login_protection' => 'rl-shared', 'otp_protection' => 'rl-shared'], 40);

// ---- negative controls (each on a fresh Redis state) ---------------------------------------------------------------------------
$negBase = ['login_protection' => 'rl-login'];
$neg = static function (string $id, callable $scenario) use ($bootstrap, $redisHost, $negBase, &$results, $expect): void {
    $s = new Scenario($id, $negBase, $redisHost);
    $s->setup($bootstrap);
    $out = $scenario($s);
    $s->stopAll();
    $results['negative'][$id] = $out;
};

// N1: a request still in flight (held) blocks the drain condition
$neg('N1_hung_request_blocks_gate', static function (Scenario $s) use ($expect): array {
    $s->startWorkers(false);
    $s->ctl->execute(['RPUSH', 'rehearsal:q:ready:login_protection', json_encode(['account' => 'acct-hang', 'kind' => 'failure', 'hang_ms' => 6000])]);
    usleep(700000);
    $s->closeIntake();
    $s->applyBarrier();
    $g = gate($s->ctl, $s->base, ['rl-login']);
    $expect('N1:denied_not_drained', ! $g['ok'] && in_array('NOT_DRAINED', $g['reasons'], true), ['reasons' => $g['reasons'], 'inflight' => $g['detail']['inflight_workers']]);
    sleep(7);
    $g2 = gate($s->ctl, $s->base, ['rl-login']);
    $expect('N1:passes_after_the_request_finished', $g2['ok'], ['reasons' => $g2['reasons']]);

    return ['denied' => $g['reasons'], 'after' => $g2['reasons']];
});

// N2: a pending retry job (delayed re-send) blocks the gate; the retry would have been a late V2 write
$neg('N2_pending_retry_and_delayed_jobs_block_gate', static function (Scenario $s) use ($expect): array {
    $s->ctl->execute(['ZADD', 'rehearsal:q:retry:login_protection', (string) ((int) redisMs($s->ctl) + 600000), json_encode(['account' => 'a', 'kind' => 'failure'])]);
    $s->ctl->execute(['ZADD', 'rehearsal:q:delayed:login_protection', (string) ((int) redisMs($s->ctl) + 600000), json_encode(['account' => 'a', 'kind' => 'failure'])]);
    $s->closeIntake();
    $s->applyBarrier();
    $g = gate($s->ctl, $s->base, ['rl-login']);
    $expect('N2:denied_pending_jobs', ! $g['ok'] && in_array('NOT_DRAINED', $g['reasons'], true), ['queues' => $g['detail']['queues_nonempty']]);

    return ['denied' => $g['reasons']];
});

// N3: a straggling writer whose user was NOT revoked (outside the inventory) is a barrier hole: the gate denies AND the write really lands
$neg('N3_unrevoked_writer_is_a_barrier_hole', static function (Scenario $s) use ($expect): array {
    $s->closeIntake();
    $s->applyBarrier(['w_direct', 'w_deferred', 'w_retry']); // w_legacy forgotten
    $g = gate($s->ctl, $s->base, ['rl-login']);
    $expect('N3:denied_barrier_hole', ! $g['ok'] && in_array('WRITE_BARRIER_HOLE', $g['reasons'], true), ['holes' => $g['detail']['barrier_holes']]);
    $c = connect('w_legacy', pwOf('w_legacy'));
    $wrote = false;
    try {
        runCommand(runtime($c, 'rl-login'), 'login_protection', 'acct-straggler', 'failure');
        $wrote = scanAll($s->ctl, PREFIX . ':*') !== [];
    } catch (\Throwable) {
    }
    $expect('N3:the_unrevoked_writer_really_writes', $wrote);
    $s->applyBarrier();
    $g2 = gate($s->ctl, $s->base, ['rl-login']);

    return ['holes' => $g['detail']['barrier_holes'], 'after_fix' => $g2['reasons']];
});

// N4: unknown expiry (a block-family record with no TTL) and a deleted-before-expiry block (unexplained absence) deny
$neg('N4_unknown_expiry_and_unexplained_absence', static function (Scenario $s) use ($expect): array {
    $nsHash = hash('sha256', 'rl-login');
    $noTtl = PREFIX . ':' . $nsHash . ':block:' . hash('sha256', 'no-ttl');
    $s->ctl->execute(['HSET', $noTtl, 'level', '2', 'expiresAt', '0']);
    $store = new RedisFullCapabilityStore($s->ctl, 'rl-login');
    $store->blockAt(k4Hmac('login_protection', 'acct-del'), 3, 60, time());
    $s->closeIntake();
    $s->applyBarrier();
    $first = [];
    foreach (scanAll($s->ctl, nsKeyPattern('rl-login')) as $k) {
        $first[$k] = (string) $s->ctl->execute(['PEXPIRETIME', $k]);
    }
    $g = gate($s->ctl, $s->base, ['rl-login'], $first);
    $expect('N4:denied_unknown_expiry', ! $g['ok'] && in_array('BLOCK_UNKNOWN_EXPIRY', $g['reasons'], true), ['unknown' => $g['detail']['unknown_expiry']]);
    $s->ctl->execute(['DEL', $noTtl]);
    $phys = PREFIX . ':' . $nsHash . ':block:' . hash('sha256', k4Hmac('login_protection', 'acct-del'));
    $s->ctl->execute(['DEL', $phys]); // vanished well before its recorded expiry
    $g2 = gate($s->ctl, $s->base, ['rl-login'], $first);
    $expect('N4:denied_unexplained_absence', ! $g2['ok'] && in_array('BLOCK_UNEXPLAINED_ABSENCE', $g2['reasons'], true), ['unexplained' => $g2['detail']['unexplained_absence']]);

    return ['unknown' => $g['reasons'], 'absence' => $g2['reasons']];
});

// N5: continuity break (sentinel lost = data flushed; and a real Redis restart is exercised by the runner, see N6)
$neg('N5_data_continuity_break_sentinel_lost', static function (Scenario $s) use ($expect): array {
    $s->closeIntake();
    $s->applyBarrier();
    $s->ctl->execute(['DEL', 'rehearsal:sentinel']);
    $g = gate($s->ctl, $s->base, ['rl-login']);
    $expect('N5:denied_continuity', ! $g['ok'] && in_array('DATA_CONTINUITY_BROKEN', $g['reasons'], true), ['continuity' => $g['detail']['continuity']]);

    return ['reasons' => $g['reasons']];
});

// N6: a late writer after the barrier: denied by Redis, logged in ACL LOG, V2 keyspace unchanged, gate stays valid
$neg('N6_late_writer_after_barrier_denied_and_gate_holds', static function (Scenario $s) use ($expect): array {
    $s->startWorkers(true);
    $s->feed(2.0);
    $s->closeIntake();
    $s->waitDrained(20);
    $s->applyBarrier();
    $before = v2Digest($s->ctl);
    usleep(1500000);
    $after = v2Digest($s->ctl);
    $ws = $s->workerStats();
    $legacy = array_values(array_filter($ws, static fn($w) => ($w['role'] ?? '') === 'legacy'));
    $nop = (int) ($legacy[0]['noperm'] ?? 0);
    $log = (array) $s->ctl->execute(['ACL', 'LOG', '50']);
    $viaLegacy = 0;
    foreach ($log as $e) {
        $a = (array) $e;
        for ($i = 0; $i + 1 < count($a); $i += 2) {
            if ($a[$i] === 'username' && $a[$i + 1] === 'w_legacy') {
                $viaLegacy++;
            }
        }
    }
    $g = gate($s->ctl, $s->base, ['rl-login']);
    $expect('N6:legacy_live_writes_denied', $nop > 0 && $before === $after, ['legacy_noperm_count' => $nop, 'digest_same' => $before === $after]);
    $expect('N6:acl_log_attributes_denials_to_legacy_user', $viaLegacy > 0, ['acl_log_w_legacy_entries' => $viaLegacy]);
    $expect('N6:gate_only_reason_is_block_state', array_diff($g['reasons'], ['BLOCK_ACTIVE']) === [], ['reasons' => $g['reasons']]);

    return ['legacy_noperm' => $nop, 'acl_log_legacy' => $viaLegacy, 'gate' => $g['reasons']];
});

// N5b: eviction (a key may have been dropped by memory pressure): policy change and evicted_keys both deny
$neg('N5b_eviction_breaks_continuity', static function (Scenario $s) use ($expect): array {
    $s->closeIntake();
    $s->applyBarrier();
    $used = (int) preg_match('/used_memory:(\d+)/', (string) $s->ctl->execute(['INFO', 'memory']), $m) ? (int) $m[1] : 0;
    $s->ctl->execute(['CONFIG', 'SET', 'maxmemory-policy', 'allkeys-random']);
    $s->ctl->execute(['CONFIG', 'SET', 'maxmemory', (string) ($used + 200000)]);
    for ($i = 0; $i < 4000; $i++) {
        $s->ctl->execute(['SET', 'rehearsal:junk:' . $i, str_repeat('x', 200)]);
    }
    $ev = preg_match('/evicted_keys:(\d+)/', (string) $s->ctl->execute(['INFO', 'stats']), $e) ? (int) $e[1] : 0;
    $s->ctl->execute(['CONFIG', 'SET', 'maxmemory', '0']);
    $s->ctl->execute(['CONFIG', 'SET', 'maxmemory-policy', 'noeviction']);
    $g = gate($s->ctl, $s->base, ['rl-login']);
    $expect('N5b:evictions_happened', $ev > 0, ['evicted_keys' => $ev]);
    $expect('N5b:denied_after_eviction_even_with_policy_restored', ! $g['ok'] && in_array('DATA_CONTINUITY_BROKEN', $g['reasons'], true), ['continuity' => $g['detail']['continuity']]);

    return ['evicted_keys' => $ev, 'reasons' => $g['reasons']];
});

// N7: a real Redis restart (new run_id, empty data, ACL table gone): continuity AND barrier both deny
$neg('N7_redis_restart_breaks_continuity_and_barrier', static function (Scenario $s) use ($expect): array {
    $s->closeIntake();
    $s->applyBarrier();
    $runBefore = $s->base['run_id'];
    try {
        $s->ctl->execute(['SHUTDOWN', 'NOSAVE']);
    } catch (\Throwable) {
    }
    $fresh = null;
    for ($i = 0; $i < 60 && $fresh === null; $i++) {
        usleep(300000);
        try {
            $c = connect('', '');
            $c->execute(['PING']);
            $fresh = $c;
        } catch (\Throwable) {
        }
    }
    $expect('N7:redis_came_back', $fresh !== null);
    if ($fresh === null) {
        return ['error' => 'no restart'];
    }
    ensureCtl($fresh);
    $ctl2 = connect('ctl', pwOf('ctl'));
    $runAfter = preg_match('/run_id:(\S+)/', (string) $ctl2->execute(['INFO', 'server']), $m) ? $m[1] : '';
    $g = gate($ctl2, $s->base, ['rl-login']);
    $expect('N7:run_id_changed', $runAfter !== '' && $runAfter !== $runBefore, ['before' => $runBefore, 'after' => $runAfter]);
    $expect('N7:denied_continuity', ! $g['ok'] && in_array('DATA_CONTINUITY_BROKEN', $g['reasons'], true), ['continuity' => $g['detail']['continuity']]);
    $expect('N7:denied_barrier_hole_default_user_back', in_array('WRITE_BARRIER_HOLE', $g['reasons'], true), ['holes' => array_slice($g['detail']['barrier_holes'], 0, 3)]);

    return ['reasons' => $g['reasons']];
});

jlog('summary', ['control_failures' => $failedControls]);
file_put_contents($outDir . '/rehearsal-results.json', json_encode($results + ['control_failures' => $failedControls], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
exit($failedControls === 0 ? 0 : 1);
