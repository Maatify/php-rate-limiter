<?php

declare(strict_types=1);

/**
 * #284 K4 cutover-window REHEARSAL (study tool; not part of the package, not run by PHPUnit/CI; no runtime code is changed).
 *
 * Modes:  controller <out-dir>   all scenarios (one isolated Redis; workers, feeder and watcher are child processes)
 *         worker <role>          one queue-consuming V2 writer            legacy   one writer outside the inventory (no queue, no heartbeat)
 *         feeder                 the request generator (stays alive after intake closes: it must be REJECTED, not stopped)
 *         watcher                keyspace-event recorder for block keys   int      32/64-bit controls of the decimal arithmetic the gate uses
 *
 * The activation gate reads ONLY Redis raw replies (never the harness bookkeeping) for barrier, continuity, drain and block state.
 * E1 is never started: the gate only sets an experimental key.
 *
 * Exit codes: 0 all controls held; 1 a control failed; 2 usage; 3 loaded library classes are not from the pinned clone; 5 results could not be saved/verified.
 */

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Repository\Redis\RedisRequestOverlay;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\OverlayWire;
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
}, true, true); // PREPENDED: the pinned clone wins over any copy the dependency directory's autoloader could resolve (checked below)

const SECRET = 'rehearsal-284-key';
const FPSECRET = 'rehearsal-284-fp';
const ENVSCOPE = 'prod';
const PREFIX = 'maatify:rate-limiter:v1';
const APP_USERS = ['w_direct', 'w_deferred', 'w_retry', 'w_legacy'];
/** Least privilege for every non-control credential: no ACL management, no CONFIG, no FLUSH*, no DEBUG, no SHUTDOWN (@admin/@dangerous; Redis folds -@admin into -@dangerous when it prints the rule) and no FUNCTION commands (FUNCTION FLUSH is NOT @dangerous in 7.0.15, measured). */
const WRITER_PROFILE = ['+@all', '-@admin', '-@dangerous', '-function'];
const ADMIN_PROBES = [['ACL', 'SETUSER', 'x', 'on'], ['CONFIG', 'SET', 'maxmemory', '0'], ['FLUSHALL'], ['FLUSHDB'], ['SWAPDB', '0', '1'], ['DEBUG', 'SLEEP', '0'], ['SHUTDOWN', 'NOSAVE'], ['FUNCTION', 'FLUSH']];
const WRITE_PROBES = [['SET', PREFIX . ':gate-probe:block:x', 'v'], ['DEL', PREFIX . ':gate-probe:block:x'], ['HSET', PREFIX . ':gate-probe:block:x', 'f', 'v'],
    ['EXPIRE', PREFIX . ':gate-probe:block:x', '9'], ['PEXPIREAT', PREFIX . ':gate-probe:block:x', '9'], ['INCRBY', PREFIX . ':gate-probe:block:x', '1'], ['ZADD', PREFIX . ':gate-probe:block:x', '1', 'm']];

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

/** @param array{0:string,1:string} $t */
function msFromTime(array $t): string
{
    return $t[0] . str_pad((string) intdiv((int) $t[1], 1000), 3, '0', STR_PAD_LEFT);
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

function redisMs(RedisCommandExecutorInterface $c): string
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
function scanAll(RedisCommandExecutorInterface $c, string $pattern): array
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

function isDenied(string $message): bool
{
    return stripos($message, 'NOPERM') !== false || stripos($message, "can't access") !== false || stripos($message, 'permission') !== false;
}

/** @return array{limiter: object, reader: object, store: RedisFullCapabilityStore} */
function runtime(RedisCommandExecutorInterface $exec, string $ns): array
{
    $store = new RedisFullCapabilityStore($exec, $ns);
    $builder = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig(SECRET, FPSECRET, ENVSCOPE), $store, new RecordingFailureSignalEmitter());

    return ['limiter' => $builder->build(), 'reader' => $builder->buildOperationalReader(), 'store' => $store];
}

function ctxFor(string $account): RateLimitContextDTO
{
    return new RateLimitContextDTO('198.51.100.' . (1 + (crc32($account) % 200)), 'Mozilla/5.0 Chrome/120.0', $account);
}

function k4Hmac(string $policy, string $account, string $secret = SECRET): string
{
    return hash_hmac('sha256', "{$policy}:rate_limiter:k4:v2:" . ENVSCOPE . ":{$account}", $secret);
}

/** @return array<string,mixed> */
function runCommand(array $rt, string $policy, string $account, string $kind): array
{
    $cmd = match ($kind) {
        'failure' => RateLimitCommand::recordFailure($policy),
        'success' => RateLimitCommand::recordSuccess($policy),
        default => RateLimitCommand::checkOnly($policy),
    };
    $res = $rt['limiter']->limit(ctxFor($account), $cmd);

    return ['allowed' => $res->isAllowed(), 'blocked' => $res->isBlocked(), 'class' => $res::class];
}

/** Executor that can hold ONE commit script (the request overlay's save) until released: a request that has finished evaluating and is about to commit. */
final class HoldingExecutor implements RedisCommandExecutorInterface
{
    public bool $armed = false;
    public string $jobId = '';

    public function __construct(private readonly RespRedisCommandExecutor $inner) {}

    public function execute(array $command): mixed
    {
        if ($this->armed && OverlayWire::isCommit($command)) {
            $this->armed = false;
            $this->inner->execute(['SET', 'rehearsal:commit_held', $this->jobId]);
            $deadline = microtime(true) + 25;
            while (microtime(true) < $deadline && (string) $this->inner->execute(['GET', 'rehearsal:commit_release']) !== $this->jobId) {
                usleep(40000);
            }
        }

        return $this->inner->execute($command);
    }

    public function raw(): RespRedisCommandExecutor
    {
        return $this->inner;
    }
}

// ======================================================================================================================
// feeder (the request generator). It keeps running after intake is closed and every attempt must be REJECTED by the gateway script.
// ======================================================================================================================
const GATEWAY_READY = "if redis.call('GET',KEYS[1])=='open' then redis.call('RPUSH',KEYS[2],ARGV[1]) redis.call('INCR',KEYS[3]) return 1 else redis.call('INCR',KEYS[4]) return 0 end";
const GATEWAY_DELAYED = "if redis.call('GET',KEYS[1])=='open' then redis.call('ZADD',KEYS[2],ARGV[2],ARGV[1]) redis.call('INCR',KEYS[3]) return 1 else redis.call('INCR',KEYS[4]) return 0 end";

if ($mode === 'feeder') {
    $policyNs = json_decode((string) getenv('S284_POLICY_NS'), true);
    assert(is_array($policyNs));
    $c = connect('feeder', pwOf('feeder'));
    while ((string) $c->execute(['GET', 'rehearsal:stop']) !== '1') {
        foreach (array_keys($policyNs) as $p) {
            $acct = random_int(1, 4) === 1 ? 'acct-hot-1' : 'acct-' . random_int(1, 12);
            $kind = ['failure', 'failure', 'failure', 'check', 'success'][random_int(0, 4)];
            $job = ['account' => $acct, 'kind' => $kind, 'attempt' => 0, 'retry_after_write' => random_int(1, 8) === 1];
            if (random_int(1, 10) === 1) {
                $c->execute(['EVAL', GATEWAY_DELAYED, '4', 'rehearsal:intake', "rehearsal:q:delayed:{$p}", 'rehearsal:accepted', 'rehearsal:rejected', json_encode($job), (string) ((int) redisMs($c) + 2000)]);
            } else {
                $c->execute(['EVAL', GATEWAY_READY, '4', 'rehearsal:intake', "rehearsal:q:ready:{$p}", 'rehearsal:accepted', 'rehearsal:rejected', json_encode($job)]);
            }
        }
        $c->execute(['SET', 'rehearsal:feeder_ts', redisMs($c)]);
        usleep(40000);
    }
    exit(0);
}

// ======================================================================================================================
// watcher: keyspace events for V2 block keys (a vanished/modified block key must be explained by an `expired` event at or after its expiry)
// ======================================================================================================================
final class RawRedis
{
    /** @var resource */
    private $s;

    public function __construct(string $host, int $port, string $user, string $pw)
    {
        $s = stream_socket_client("tcp://{$host}:{$port}", $e, $m, 5);
        assert(is_resource($s));
        stream_set_timeout($s, 2);
        $this->s = $s;
        $this->cmd(['AUTH', $user, $pw]);
    }

    public function send(array $c): void
    {
        $p = '*' . count($c) . "\r\n";
        foreach ($c as $a) {
            $p .= '$' . strlen((string) $a) . "\r\n" . $a . "\r\n";
        }
        fwrite($this->s, $p);
    }

    public function cmd(array $c): mixed
    {
        $this->send($c);

        return $this->read();
    }

    public function read(): mixed
    {
        $line = fgets($this->s);
        if ($line === false) {
            return null;
        }
        $t = $line[0];
        $v = rtrim(substr($line, 1));
        if ($t === '+' || $t === ':') {
            return $v;
        }
        if ($t === '-') {
            return 'ERR:' . $v;
        }
        if ($t === '$') {
            if ((int) $v < 0) {
                return null;
            }
            $d = '';
            $need = (int) $v + 2;
            while (strlen($d) < $need) {
                $chunk = fread($this->s, $need - strlen($d));
                if ($chunk === false || $chunk === '') {
                    return null;
                }
                $d .= $chunk;
            }

            return substr($d, 0, -2);
        }
        if ($t === '*') {
            $o = [];
            for ($i = 0; $i < (int) $v; $i++) {
                $o[] = $this->read();
            }

            return $o;
        }

        return null;
    }
}

if ($mode === 'watcher') {
    $host = (string) getenv('REDIS_INTEGRATION_HOST');
    $port = (int) getenv('REDIS_INTEGRATION_PORT');
    $sub = new RawRedis($host, $port, 'ctl', pwOf('ctl'));
    $out = connect('ctl', pwOf('ctl'));
    $sub->send(['PSUBSCRIBE', '__keyevent@0__:*']);
    $sub->read();
    $out->execute(['SET', 'rehearsal:watcher_ready', '1']);
    while (true) {
        $m = $sub->read();
        if (! is_array($m)) {
            if ((string) $out->execute(['GET', 'rehearsal:stop']) === '1') {
                break;
            }
            continue;
        }
        if (($m[0] ?? '') !== 'pmessage') {
            continue;
        }
        $event = substr((string) $m[2], strlen('__keyevent@0__:'));
        $key = (string) $m[3];
        if (str_contains($key, ':block:') || str_starts_with($key, 'rehearsal:sync:')) {
            $out->execute(['RPUSH', 'rehearsal:events', redisMs($out) . '|' . $event . '|' . $key]);
        }
    }
    exit(0);
}

// ======================================================================================================================
// worker roles
// ======================================================================================================================
if ($mode === 'worker' || $mode === 'legacy') {
    $role = $mode === 'legacy' ? 'legacy' : (string) ($argv[2] ?? 'direct');
    $user = 'w_' . $role;
    $policyNs = json_decode((string) getenv('S284_POLICY_NS'), true);
    assert(is_array($policyNs));
    $exec = new HoldingExecutor(connect($user, pwOf($user)));
    $raw = $exec->raw();
    $rts = [];
    foreach ($policyNs as $p => $ns) {
        $rts[$p] = runtime($exec, (string) $ns);
    }
    $id = $role . '-' . getmypid();
    $stats = ['done' => 0, 'errors' => 0, 'noperm' => 0, 'last_ok_ms' => '0', 'first_noperm_ms' => '0', 'conn_lost' => 0];
    $note = static function (\Throwable $e) use (&$stats, $raw): void {
        $stats['errors']++;
        if (isDenied($e->getMessage())) {
            $stats['noperm']++;
            if ($stats['first_noperm_ms'] === '0') {
                $stats['first_noperm_ms'] = redisMs($raw);
            }
        } else {
            $stats['conn_lost']++;
        }
    };
    $mover = "local j=redis.call('ZRANGEBYSCORE',KEYS[1],'-inf',ARGV[1],'LIMIT',0,1) if #j==0 then return false end "
        . "redis.call('ZREM',KEYS[1],j[1]) redis.call('LPUSH',KEYS[2],j[1]) return j[1]";
    $flush = static function () use ($raw, $id, $role, &$stats, $user): void {
        $f = ['role', $role, 'user', $user, 'done', (string) $stats['done'], 'errors', (string) $stats['errors'], 'noperm', (string) $stats['noperm'],
            'conn_lost', (string) $stats['conn_lost'], 'last_ok_ms', $stats['last_ok_ms'], 'first_noperm_ms', $stats['first_noperm_ms'], 'ts', redisMs($raw)];
        try {
            $raw->execute(['HSET', 'rehearsal:wstats:' . $id, ...$f]);
        } catch (\Throwable) {
        }
    };
    while (true) {
        try {
            if ((string) $raw->execute(['GET', 'rehearsal:stop']) === '1') {
                $flush();
                break;
            }
        } catch (\Throwable) {
            exit(0); // the connection was cut by the barrier (CLIENT KILL): this writer is gone
        }
        if ($role === 'legacy') {
            $p = array_keys($rts)[random_int(0, count($rts) - 1)];
            try {
                runCommand($rts[$p], $p, 'acct-legacy-' . random_int(1, 5), 'failure');
                $stats['done']++;
                $stats['last_ok_ms'] = redisMs($raw);
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
                    $job = $raw->execute(['LMOVE', "rehearsal:q:ready:{$p}", $proc, 'LEFT', 'RIGHT']);
                } else {
                    $source = $role === 'deferred' ? "rehearsal:q:delayed:{$p}" : "rehearsal:q:retry:{$p}";
                    $job = $raw->execute(['EVAL', $mover, '2', $source, $proc, redisMs($raw)]);
                }
            } catch (\Throwable $e) {
                $note($e);
                continue;
            }
            if (! is_string($job)) {
                continue;
            }
            $took = true;
            $j = json_decode($job, true);
            assert(is_array($j));
            if (($j['hold_after_pull_ms'] ?? 0) > 0) {
                // the request is already out of the ready queue (in this worker's processing list) but not yet registered as in-flight
                $raw->execute(['SET', 'rehearsal:pull_held', (string) ($j['id'] ?? 'x')]);
                usleep((int) $j['hold_after_pull_ms'] * 1000);
            }
            $raw->execute(['HSET', 'rehearsal:hb:' . $id, 'role', $role, 'inflight', '1', 'ts', redisMs($raw)]);
            if (($j['hang_ms'] ?? 0) > 0) {
                usleep((int) $j['hang_ms'] * 1000);
            }
            if (($j['hold_at_commit'] ?? false) === true) {
                $exec->armed = true;
                $exec->jobId = (string) ($j['id'] ?? 'x');
            }
            try {
                $r = runCommand($rts[$p], $p, (string) $j['account'], (string) $j['kind']);
                $stats['done']++;
                $stats['last_ok_ms'] = redisMs($raw);
                if (isset($j['result_id'])) {
                    $raw->execute(['SET', 'rehearsal:result:' . $j['result_id'], json_encode(['policy' => $p, 'ok' => true, 'ms' => $stats['last_ok_ms']] + $r)]);
                }
                if (($j['retry_after_write'] ?? false) && ($j['attempt'] ?? 0) === 0) {
                    $j['attempt'] = 1;
                    $raw->execute(['ZADD', "rehearsal:q:retry:{$p}", (string) ((int) redisMs($raw) + 1500), json_encode($j)]);
                }
            } catch (\Throwable $e) {
                $note($e);
                if (isset($j['result_id'])) {
                    try {
                        $raw->execute(['SET', 'rehearsal:result:' . $j['result_id'], json_encode(['policy' => $p, 'ok' => false, 'error' => substr($e->getMessage(), 0, 120)])]);
                    } catch (\Throwable) {
                    }
                }
            }
            $exec->armed = false;
            try {
                $raw->execute(['LREM', $proc, '1', $job]);
                $raw->execute(['HSET', 'rehearsal:hb:' . $id, 'inflight', '0', 'ts', redisMs($raw)]);
            } catch (\Throwable) {
                exit(0);
            }
            $flush();
        }
        if (! $took) {
            try {
                $raw->execute(['HSET', 'rehearsal:hb:' . $id, 'role', $role, 'inflight', '0', 'ts', redisMs($raw)]);
            } catch (\Throwable) {
                exit(0);
            }
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
    fwrite(STDERR, "usage: rehearsal.php controller|worker|legacy|feeder|watcher|int\n");
    exit(2);
}

$outDir = (string) ($argv[2] ?? '/out');
@mkdir($outDir, 0777, true);

// Evidence integrity: the library classes in use MUST come from the pinned clone, not from the dependency directory or anywhere else.
$origin = [];
$rootReal = realpath($root);
foreach ([RateLimiterBuilder::class, RedisFullCapabilityStore::class, EvaluationPipeline::class, RedisRequestOverlay::class, RespRedisCommandExecutor::class, OverlayWire::class, RateLimitContextDTO::class] as $cls) {
    $f = (string) (new \ReflectionClass($cls))->getFileName();
    $origin[$cls] = ['file' => $f, 'sha256' => hash_file('sha256', $f), 'from_clone' => $rootReal !== false && str_starts_with((string) realpath($f), $rootReal . '/')];
}
foreach ($origin as $cls => $o) {
    if (! $o['from_clone']) {
        fwrite(STDERR, "SOURCE ORIGIN MISMATCH: {$cls} loaded from {$o['file']}, not from {$root}\n");
        jlog('source_origin_mismatch', ['class' => $cls, 'file' => $o['file']]);
        exit(3);
    }
}
jlog('source_origin', ['root' => $rootReal, 'classes' => array_map(static fn($o) => ['file' => $o['file'], 'sha256' => $o['sha256']], $origin)]);

function setupRedis(RespRedisCommandExecutor $c): void
{
    $c->execute(['FLUSHALL']);
    $c->execute(['ACL', 'LOG', 'RESET']);
    $c->execute(['CONFIG', 'SET', 'notify-keyspace-events', 'KEA']);
    foreach (APP_USERS as $u) {
        $c->execute(['ACL', 'SETUSER', $u, 'reset', 'on', '>' . pwOf($u), '~*', ...WRITER_PROFILE]);
    }
    $c->execute(['ACL', 'SETUSER', 'feeder', 'reset', 'on', '>' . pwOf('feeder'), '~rehearsal:*', ...WRITER_PROFILE]);
}

function ensureCtl(RespRedisCommandExecutor $c): void
{
    $c->execute(['ACL', 'SETUSER', 'ctl', 'reset', 'on', '>' . pwOf('ctl'), '~*', '&*', '+@all']);
}

$first = connect('', '');
ensureCtl($first);
$first = null; // the bootstrap connection as `default` is closed: no unrestricted connection stays open
$bootstrap = connect('ctl', pwOf('ctl'));

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

function v2Digest(RespRedisCommandExecutor $ctl): string
{
    $keys = scanAll($ctl, PREFIX . ':*');
    sort($keys);
    $acc = '';
    foreach ($keys as $k) {
        $acc .= $k . '|' . $ctl->execute(['PEXPIRETIME', $k]) . '|' . sha1((string) $ctl->execute(['DUMP', $k])) . "\n";
    }

    return sha1($acc) . ':' . count($keys);
}

/** @return array<string,array{pe:string,h:string}> */
function v2Map(RespRedisCommandExecutor $ctl): array
{
    $m = [];
    foreach (scanAll($ctl, PREFIX . ':*') as $k) {
        $m[$k] = ['pe' => (string) $ctl->execute(['PEXPIRETIME', $k]), 'h' => sha1((string) $ctl->execute(['DUMP', $k]))];
    }

    return $m;
}

/** @return array<string,mixed> */
function k4Census(RespRedisCommandExecutor $ctl, string $policy, string $ns, array $accounts = []): array
{
    $accounts = $accounts ?: array_merge(['acct-hot-1'], array_map(static fn($i) => 'acct-' . $i, range(1, 12)));
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

const DRAIN_SNAPSHOT = <<<'LUA'
local q = 0
local detail = {}
for _, k in ipairs(redis.call('KEYS', 'rehearsal:q:*')) do
  local t = redis.call('TYPE', k)['ok']
  local n
  if t == 'zset' then n = redis.call('ZCARD', k) else n = redis.call('LLEN', k) end
  if n > 0 then q = q + n; detail[#detail + 1] = k .. '=' .. n end
end
local inflight = {}
for _, k in ipairs(redis.call('KEYS', 'rehearsal:hb:*')) do
  if redis.call('HGET', k, 'inflight') ~= '0' then inflight[#inflight + 1] = k end
end
return {q, detail, inflight}
LUA;

/** One ATOMIC snapshot of every queue (ready, delayed, retry, per-worker processing list) and every heartbeat: no request can move between two separate reads. @return array{0:int,1:list<string>,2:list<string>} */
function drainSnapshot(RespRedisCommandExecutor $ctl): array
{
    $r = $ctl->execute(['EVAL', DRAIN_SNAPSHOT, '0']);
    assert(is_array($r));

    return [(int) $r[0], array_map('strval', (array) $r[1]), array_map('strval', (array) $r[2])];
}

/** @return array<string,mixed> */
function userProfiles(RespRedisCommandExecutor $ctl): array
{
    $o = [];
    foreach ((array) $ctl->execute(['ACL', 'USERS']) as $u) {
        $g = (array) $ctl->execute(['ACL', 'GETUSER', (string) $u]);
        $m = [];
        for ($i = 0; $i + 1 < count($g); $i += 2) {
            if (in_array($g[$i], ['flags', 'commands', 'keys'], true)) {
                $m[(string) $g[$i]] = $g[$i + 1];
            }
        }
        $o[(string) $u] = $m;
    }

    return $o;
}

/** @return list<array<string,string>> connections as the server reports them */
function clientList(RespRedisCommandExecutor $ctl): array
{
    $rows = [];
    foreach (explode("\n", trim((string) $ctl->execute(['CLIENT', 'LIST']))) as $line) {
        $m = [];
        foreach (explode(' ', trim($line)) as $kv) {
            $p = explode('=', $kv, 2);
            if (count($p) === 2) {
                $m[$p[0]] = $p[1];
            }
        }
        if ($m !== []) {
            $rows[] = $m;
        }
    }

    return $rows;
}

/** The activation gate. Reads Redis only.
 * @param list<array<string,mixed>> $ledgerViolations continuity ledger entries recorded DURING the wait
 * @param list<string> $eventViolations keyspace-event violations for first-scan block keys
 * @return array{ok: bool, reasons: list<string>, detail: array<string,mixed>} */
function gate(RespRedisCommandExecutor $ctl, array $base, array $namespaces, ?array $firstScan = null, array $ledgerViolations = [], array $eventViolations = []): array
{
    $reasons = [];
    $d = [];
    // G1 intake closed, and NOTHING accepted while the generator is still alive (the gateway rejects)
    $d['intake'] = (string) $ctl->execute(['GET', 'rehearsal:intake']);
    $a1 = (string) $ctl->execute(['GET', 'rehearsal:accepted']);
    $r1 = (string) $ctl->execute(['GET', 'rehearsal:rejected']);
    usleep(300000);
    $a2 = (string) $ctl->execute(['GET', 'rehearsal:accepted']);
    $r2 = (string) $ctl->execute(['GET', 'rehearsal:rejected']);
    $d['accepted'] = [$a1, $a2];
    $d['rejected'] = [$r1, $r2];
    if ($d['intake'] !== 'closed' || $a1 !== $a2) {
        $reasons[] = 'INTAKE_NOT_CLOSED';
    }
    // G2 drain: two ATOMIC snapshots (queues + in-flight) 150 ms apart, both empty
    $s1 = drainSnapshot($ctl);
    usleep(150000);
    $s2 = drainSnapshot($ctl);
    $d['drain_snapshots'] = [$s1, $s2];
    $d['queues_nonempty'] = $s1[1] === [] ? $s2[1] : $s1[1];
    $d['inflight_workers'] = $s1[2] === [] ? $s2[2] : $s1[2];
    if ($s1[0] !== 0 || $s2[0] !== 0 || $s1[2] !== [] || $s2[2] !== []) {
        $reasons[] = 'NOT_DRAINED';
    }
    // G3 write barrier and credential isolation: EVERY user except ctl (also users flagged `off`) must be denied writes, ACL management and
    // database-wide commands (ACL DRYRUN, from the server's own ACL table); and no open connection of a write-capable, admin-capable or `off` user may remain.
    $users = array_map('strval', (array) $ctl->execute(['ACL', 'USERS']));
    $profiles = userProfiles($ctl);
    $holes = [];
    $unsafe = [];
    foreach ($users as $u) {
        if ($u === 'ctl') {
            continue;
        }
        $bad = false;
        foreach (WRITE_PROBES as $cmd) {
            if ($ctl->execute(['ACL', 'DRYRUN', $u, ...$cmd]) === 'OK') {
                $holes[] = $u . ':' . $cmd[0];
                $bad = true;
            }
        }
        foreach (ADMIN_PROBES as $cmd) {
            if ($ctl->execute(['ACL', 'DRYRUN', $u, ...$cmd]) === 'OK') {
                $holes[] = $u . ':' . $cmd[0] . (isset($cmd[1]) ? ' ' . $cmd[1] : '');
                $bad = true;
            }
        }
        $off = in_array('off', (array) ($profiles[$u]['flags'] ?? []), true);
        if ($bad || $off) {
            $unsafe[$u] = true;
        }
    }
    $openBad = [];
    foreach (clientList($ctl) as $row) {
        $u = $row['user'] ?? '';
        if ($u !== 'ctl' && isset($unsafe[$u])) {
            $openBad[] = $u . '@' . ($row['addr'] ?? '?');
        }
    }
    $d['barrier_holes'] = $holes;
    $d['open_connections_of_unsafe_users'] = $openBad;
    $d['user_profiles'] = $profiles;
    if ($holes !== []) {
        $reasons[] = 'WRITE_BARRIER_HOLE';
    }
    if ($openBad !== []) {
        $reasons[] = 'OPEN_CONNECTION_OF_UNSAFE_USER';
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
    // G5 block namespaces: full SCAN on this primary; a key SCAN returned but PEXPIRETIME = -2 is ABSENT (not present)
    $now = redisMs($ctl);
    $d['redis_now_ms'] = $now;
    $present = [];
    $gone = [];
    foreach ($namespaces as $ns) {
        foreach (scanAll($ctl, nsKeyPattern($ns)) as $k) {
            $pe = (string) $ctl->execute(['PEXPIRETIME', $k]);
            if ($pe === '-2') {
                $gone[$k] = true;
            } else {
                $present[$k] = $pe;
            }
        }
    }
    $nowAfterScan = redisMs($ctl); // absence is judged against the time AFTER the scan (a key that expired during the scan is explained, not unexplained)
    $active = [];
    $unknown = [];
    $expiredPresent = 0;
    foreach ($present as $k => $pe) {
        if ($pe === '-1' || ! isNonNegDecimal($pe)) {
            $unknown[$k] = $pe;
        } elseif (dcmp($pe, $now) > 0) {
            $active[$k] = dsub($pe, $now);
        } else {
            $expiredPresent++;
        }
    }
    $d['block_keys_present'] = count($present);
    $d['block_keys_seen_then_gone'] = count($gone);
    $d['active_remaining_ms'] = $active;
    $d['unknown_expiry'] = $unknown;
    $d['expired_but_present'] = $expiredPresent;
    if ($unknown !== []) {
        $reasons[] = 'BLOCK_UNKNOWN_EXPIRY';
    }
    if ($active !== []) {
        $reasons[] = 'BLOCK_ACTIVE';
    }
    // G5b: a first-scan key that is absent now (not in the scan, or scanned-then-gone) must have reached its recorded expiry; new keys deny
    if ($firstScan !== null) {
        $unexplained = [];
        foreach ($firstScan as $k => $pe) {
            if (! isset($present[$k]) && isNonNegDecimal((string) $pe) && dcmp((string) $pe, $nowAfterScan) > 0) {
                $unexplained[] = $k;
            }
        }
        $new = array_values(array_diff(array_keys($present), array_keys($firstScan)));
        $d['unexplained_absence'] = $unexplained;
        $d['new_since_first_scan'] = $new;
        if ($unexplained !== []) {
            $reasons[] = 'BLOCK_UNEXPLAINED_ABSENCE';
        }
        if ($new !== []) {
            $reasons[] = 'BLOCK_NEW_SINCE_FIRST_SCAN';
        }
    }
    // G6 continuity DURING the wait: ledger polls and keyspace events (a delete or change inside the last interval before expiry cannot hide)
    $d['ledger_violations'] = $ledgerViolations;
    $d['event_violations'] = $eventViolations;
    if ($ledgerViolations !== []) {
        $reasons[] = 'BLOCK_EARLY_ABSENCE_OR_CHANGE';
    }
    if ($eventViolations !== []) {
        $reasons[] = 'BLOCK_EVENT_VIOLATION';
    }

    return ['ok' => $reasons === [], 'reasons' => $reasons, 'detail' => $d];
}

final class Scenario
{
    /** @var array<string,resource> */
    public array $procs = [];
    public RespRedisCommandExecutor $ctl;
    public array $base = [];
    public array $timeline = [];

    public function __construct(public string $name, public array $policyNs) {}

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
        $this->ctl->execute(['ACL', 'SETUSER', 'default', 'off', 'resetkeys', 'resetchannels', '-@all']);
        $this->ctl->execute(['CLIENT', 'KILL', 'USER', 'default']);
        $this->base = baseline($this->ctl);
        $this->ctl->execute(['SET', 'rehearsal:intake', 'open']);
        $this->ctl->execute(['SET', 'rehearsal:accepted', '0']);
        $this->ctl->execute(['SET', 'rehearsal:rejected', '0']);
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

    public function startWatcher(): void
    {
        $this->ctl->execute(['DEL', 'rehearsal:watcher_ready', 'rehearsal:events']);
        $this->spawn('watcher', 'watcher');
        for ($i = 0; $i < 50 && (string) $this->ctl->execute(['GET', 'rehearsal:watcher_ready']) !== '1'; $i++) {
            usleep(100000);
        }
    }

    public function startWorkers(bool $withLegacy = true, bool $withFeeder = false): void
    {
        foreach (['direct', 'direct', 'deferred', 'retry'] as $i => $role) {
            $this->spawn($role . $i . '-' . random_int(100, 999), 'worker', $role);
        }
        if ($withLegacy) {
            $this->spawn('legacy-' . random_int(100, 999), 'legacy');
        }
        if ($withFeeder) {
            $this->spawn('feeder', 'feeder');
        }
        usleep(800000);
    }

    /** @return array<string,string> */
    public function closeIntake(): array
    {
        $this->ctl->execute(['SET', 'rehearsal:intake', 'closed']);
        $acceptedAtClose = (string) $this->ctl->execute(['GET', 'rehearsal:accepted']);
        $rejectedAtClose = (string) $this->ctl->execute(['GET', 'rehearsal:rejected']);
        $this->mark('intake_closed');

        return ['accepted_at_close' => $acceptedAtClose, 'rejected_at_close' => $rejectedAtClose];
    }

    public function waitDrained(int $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            [$q, , $inflight] = drainSnapshot($this->ctl);
            if ($q === 0 && $inflight === []) {
                usleep(150000);
                [$q2, , $in2] = drainSnapshot($this->ctl);
                if ($q2 === 0 && $in2 === []) {
                    $this->mark('drained');

                    return true;
                }
            }
            usleep(150000);
        } while (microtime(true) < $deadline);

        return false;
    }

    /** restrict key permissions of the writers: reads of V2 keys stay, writes to V2 keys go; control keys stay writable. Credentials stay least-privilege. */
    public function applyBarrier(array $users = APP_USERS): void
    {
        $this->mark('barrier_start');
        $this->ctl->execute(['SET', 'rehearsal:barrier_ms', redisMs($this->ctl)]);
        foreach ($users as $u) {
            $this->ctl->execute(['ACL', 'SETUSER', $u, 'resetkeys', '%R~' . PREFIX . ':*', '~rehearsal:*']);
        }
        $this->mark('barrier_applied');
    }

    /** cut every open connection of the given users (the new permissions already applied to them; this isolates them completely) @return array<string,int> */
    public function killConnections(array $users = APP_USERS): array
    {
        $killed = [];
        foreach ($users as $u) {
            $killed[$u] = (int) $this->ctl->execute(['CLIENT', 'KILL', 'USER', $u]);
        }
        $this->mark('connections_killed');

        return $killed;
    }

    /** @return array<string,mixed> */
    public function probes(): array
    {
        $out = ['per_user' => [], 'admin_per_user' => [], 'digest_before' => v2Digest($this->ctl)];
        $logBefore = count((array) $this->ctl->execute(['ACL', 'LOG']));
        $firstNs = (string) array_values($this->policyNs)[0];
        $key = k4Hmac('login_protection', 'acct-probe');
        foreach (APP_USERS as $u) {
            $res = [];
            $adm = [];
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
                        $res[$name] = isDenied($e->getMessage()) ? 'DENIED' : 'ERROR:' . substr($e->getMessage(), 0, 80);
                    }
                }
                // credential isolation: the writers' own credentials must not be able to lift the barrier or touch the database as a whole
                $adminOps = [
                    'ACL_SETUSER_self_allkeys' => ['ACL', 'SETUSER', $u, 'allkeys', 'allcommands'],
                    'CONFIG_SET' => ['CONFIG', 'SET', 'maxmemory', '0'],
                    'FLUSHALL' => ['FLUSHALL'],
                    'FLUSHDB' => ['FLUSHDB'],
                    'DEBUG_SLEEP' => ['DEBUG', 'SLEEP', '0'],
                    'ACL_DELUSER_ctl' => ['ACL', 'DELUSER', 'ctl'],
                ];
                foreach ($adminOps as $name => $cmd) {
                    try {
                        $c->execute($cmd);
                        $adm[$name] = 'EXECUTED';
                    } catch (\Throwable $e) {
                        // DEBUG answers `not allowed` from the server's own enable-debug-command setting BEFORE any ACL check (Redis 7): it did not run either way
                        $adm[$name] = isDenied($e->getMessage()) ? 'DENIED' : (str_contains($e->getMessage(), 'DEBUG command not allowed') ? 'DENIED' : 'ERROR:' . substr($e->getMessage(), 0, 80));
                    }
                }
            } catch (\Throwable $e) {
                $res['connect'] = 'ERROR:' . substr($e->getMessage(), 0, 80);
            }
            $out['per_user'][$u] = $res;
            $out['admin_per_user'][$u] = $adm;
        }
        $out['digest_after'] = v2Digest($this->ctl);
        $out['ctl_user_still_exists'] = in_array('ctl', array_map('strval', (array) $this->ctl->execute(['ACL', 'USERS'])), true);
        $log = (array) $this->ctl->execute(['ACL', 'LOG', '200']);
        $out['acl_log_entries_new'] = count($log) - $logBefore;

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

    /** wait until the watcher has processed everything emitted before this call (ordered pub/sub stream) */
    public function syncWatcher(): bool
    {
        $tok = 'rehearsal:sync:' . random_int(1000, 999999);
        $this->ctl->execute(['SET', $tok, '1']);
        for ($i = 0; $i < 60; $i++) {
            foreach ((array) $this->ctl->execute(['LRANGE', 'rehearsal:events', '-50', '-1']) as $e) {
                if (str_ends_with((string) $e, '|' . $tok)) {
                    return true;
                }
            }
            usleep(50000);
        }

        return false;
    }

    /** @param array<string,string> $first key => recorded PEXPIRETIME @return list<string> */
    public function eventViolations(array $first, string $sinceMs = '0'): array
    {
        $v = [];
        foreach ((array) $this->ctl->execute(['LRANGE', 'rehearsal:events', '0', '-1']) as $line) {
            [$ms, $event, $key] = array_pad(explode('|', (string) $line, 3), 3, '');
            if (! isset($first[$key]) || dcmp($ms, $sinceMs) < 0) {
                continue; // not tracked, or an event from before the first scan (the keys' creation, not a change during the wait)
            }
            $exp = (string) $first[$key];
            if ($event === 'expired' && isNonNegDecimal($exp) && dcmp($ms, $exp) >= 0) {
                continue; // natural expiry, at or after the recorded absolute expiry
            }
            $v[] = $event . '@' . $ms . ' key=' . substr($key, -12) . ' recorded_expiry=' . $exp;
        }

        return $v;
    }

    /**
     * The continuity ledger: from the first scan to the recorded expiry, every key must keep EXACTLY its recorded PEXPIRETIME (polled every 200 ms).
     * @param array<string,string> $first @return list<array<string,mixed>>
     */
    public function waitForExpiryWithLedger(array $first, string $maxExp, ?callable $midWait = null): array
    {
        $violations = [];
        $seen = [];
        $mid = false;
        while (true) {
            $now = redisMs($this->ctl);
            if (dcmp($now, $maxExp) > 0) {
                break;
            }
            foreach ($first as $k => $rec) {
                if (isset($seen[$k]) || ! isNonNegDecimal((string) $rec)) {
                    continue;
                }
                $pe = (string) $this->ctl->execute(['PEXPIRETIME', $k]);
                // Redis time is read AFTER the observation: an absence/change counts as early only if even that later (ms-floored) time is still before the recorded
                // expiry, i.e. the observation itself certainly happened before it (a TIME read before the observation could be a few ms stale at the boundary).
                $after = redisMs($this->ctl);
                if (dcmp($after, (string) $rec) < 0 && $pe !== (string) $rec) {
                    $violations[] = ['key' => substr($k, -12), 'recorded' => $rec, 'observed' => $pe, 'redis_ms_after_observation' => $after];
                    $seen[$k] = true;
                }
            }
            if ($midWait !== null && ! $mid) {
                $mid = true;
                $midWait();
            }
            usleep(200000);
        }

        return $violations;
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

$results = ['base' => ['php' => PHP_VERSION, 'php_int_size' => PHP_INT_SIZE, 'ext_redis' => phpversion('redis') ?: null, 'harness_sha256' => hash_file('sha256', __FILE__)], 'source_origin' => $origin, 'scenarios' => []];
$failedControls = 0;
$expect = static function (string $id, bool $ok, array $detail = []) use (&$failedControls): void {
    if (! $ok) {
        $failedControls++;
    }
    jlog('control', ['id' => $id, 'ok' => $ok] + $detail);
};

/** Reopen after the barrier: restore permissions, restart the workers (their connections were cut), reopen intake, and ONE verified request PER POLICY. */
$reopen = static function (Scenario $s, array $policyNs, callable $expect, string $name): array {
    foreach (APP_USERS as $u) {
        $s->ctl->execute(['ACL', 'SETUSER', $u, 'resetkeys', '~*']);
    }
    $s->stopAll();
    $s->ctl->execute(['DEL', 'rehearsal:stop']);
    $s->startWorkers(false, false);
    $nsCountBefore = [];
    foreach ($policyNs as $p => $ns) {
        $nsCountBefore[$p] = count(scanAll($s->ctl, PREFIX . ':' . hash('sha256', (string) $ns) . ':*'));
    }
    $s->ctl->execute(['SET', 'rehearsal:intake', 'open']);
    $s->mark('reopen_requested');
    $t0 = $s->timeline['reopen_requested'];
    foreach ($policyNs as $p => $ns) {
        $s->ctl->execute(['RPUSH', "rehearsal:q:ready:{$p}", json_encode(['id' => "reopen-{$p}", 'result_id' => "reopen-{$p}", 'account' => 'acct-reopen-' . $p, 'kind' => 'failure', 'attempt' => 0])]);
    }
    $got = [];
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline && count($got) < count($policyNs)) {
        foreach (array_keys($policyNs) as $p) {
            if (isset($got[$p])) {
                continue;
            }
            $r = $s->ctl->execute(['GET', "rehearsal:result:reopen-{$p}"]);
            if (is_string($r)) {
                $got[$p] = json_decode($r, true);
            }
        }
        usleep(100000);
    }
    $per = [];
    foreach ($policyNs as $p => $ns) {
        $r = $got[$p] ?? null;
        $after = count(scanAll($s->ctl, PREFIX . ':' . hash('sha256', (string) $ns) . ':*'));
        $stateWritten = $after > $nsCountBefore[$p];
        $k4 = k4Census($s->ctl, $p, (string) $ns, ['acct-reopen-' . $p]);
        $verified = is_array($r) && ($r['ok'] ?? false) === true && isset($r['allowed']) && $stateWritten;
        $per[$p] = ['result' => $r, 'ns_keys_before' => $nsCountBefore[$p], 'ns_keys_after' => $after, 'k4_census_for_the_account' => $k4['families'],
            'return_ms' => is_array($r) && isset($r['ms']) ? dsub((string) $r['ms'], $t0) : 'n/a', 'verified' => $verified];
        $expect($name . ':reopen_verified_for_policy_' . $p, $verified, ['return_ms' => $per[$p]['return_ms'], 'result' => $r, 'ns_keys' => [$nsCountBefore[$p], $after]]);
    }
    $s->mark('first_success_after_reopen');

    return $per;
};

/**
 * One full rehearsal: generator ALIVE throughout -> close intake (generator rejected) -> drain -> barrier (restrict) -> probes (every source, admin ops) ->
 * kill connections -> gate while a block is active (DENY) -> first scan -> ledger during the wait -> final scan + gate (PASS) -> hold -> signal -> reopen per policy.
 */
$fullRun = static function (string $name, array $policyNs, int $syntheticBlockSeconds) use ($bootstrap, &$results, $expect, $reopen): array {
    $s = new Scenario($name, $policyNs);
    $s->setup($bootstrap);
    $namespaces = array_values(array_unique($policyNs));
    $s->startWatcher();
    $s->startWorkers(true, true);
    $synth = [];
    foreach ($namespaces as $i => $ns) {
        $store = new RedisFullCapabilityStore($s->ctl, $ns);
        $key = k4Hmac('login_protection', 'acct-synth-' . $i);
        $dur = $syntheticBlockSeconds + 10 * $i;
        $store->blockAt($key, 3, $dur, time());
        $phys = PREFIX . ':' . hash('sha256', $ns) . ':block:' . hash('sha256', $key);
        $synth[$ns] = ['key' => $phys, 'duration_s' => $dur, 'pexpiretime_raw' => (string) $s->ctl->execute(['PEXPIRETIME', $phys]), 'redis_now_ms' => redisMs($s->ctl)];
    }
    sleep(5); // the generator runs while workers consume
    $s->mark('load_end');
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
    $prevNs = (string) array_values($policyNs)[0];
    $prevLogical = k4Hmac('login_protection', 'acct-synth-prev', SECRET . '-PREVIOUS');
    (new RedisFullCapabilityStore($s->ctl, $prevNs))->blockAt($prevLogical, 3, $syntheticBlockSeconds, time());
    $prevPhys = PREFIX . ':' . hash('sha256', $prevNs) . ':block:' . hash('sha256', $prevLogical);
    $synthPrev = ['key' => $prevPhys, 'pexpiretime_raw' => (string) $s->ctl->execute(['PEXPIRETIME', $prevPhys])];

    // ---- close intake while the generator is STILL RUNNING: every further attempt must be rejected atomically by the gateway
    $feederTsBefore = (string) $s->ctl->execute(['GET', 'rehearsal:feeder_ts']);
    $close = $s->closeIntake();
    usleep(1500000);
    $acceptedLater = (string) $s->ctl->execute(['GET', 'rehearsal:accepted']);
    $rejectedLater = (string) $s->ctl->execute(['GET', 'rehearsal:rejected']);
    $feederTsLater = (string) $s->ctl->execute(['GET', 'rehearsal:feeder_ts']);
    $expect($name . ':generator_alive_and_rejected_after_close', $acceptedLater === $close['accepted_at_close'] && dcmp($rejectedLater, $close['rejected_at_close']) > 0 && dcmp($feederTsLater, $feederTsBefore) > 0,
        ['accepted_at_close' => $close['accepted_at_close'], 'accepted_1_5s_later' => $acceptedLater, 'rejected_at_close' => $close['rejected_at_close'], 'rejected_later' => $rejectedLater, 'feeder_ts_advanced' => dcmp($feederTsLater, $feederTsBefore) > 0]);
    $drained = $s->waitDrained(30);
    $expect($name . ':drained_atomic_snapshots', $drained);
    $s->applyBarrier();
    usleep(900000); // live connections keep running and now hit the new permissions
    $probes = $s->probes();
    $liveBeforeKill = $s->workerStats();
    $killed = $s->killConnections();
    $profilesAfter = userProfiles($s->ctl);
    $remaining = array_values(array_filter(clientList($s->ctl), static fn($row) => in_array($row['user'] ?? '', APP_USERS, true)));
    $s->mark('probes_done');
    $allDenied = true;
    foreach ($probes['per_user'] as $ops) {
        foreach ($ops as $res) {
            if ($res !== 'DENIED') {
                $allDenied = false;
            }
        }
    }
    $adminDenied = true;
    foreach ($probes['admin_per_user'] as $ops) {
        foreach ($ops as $res) {
            if ($res !== 'DENIED') {
                $adminDenied = false;
            }
        }
    }
    $expect($name . ':late_writes_denied_all_sources', $allDenied && $probes['digest_before'] === $probes['digest_after'], ['digest_equal' => $probes['digest_before'] === $probes['digest_after']]);
    $expect($name . ':credentials_cannot_lift_barrier_or_touch_database', $adminDenied && $probes['ctl_user_still_exists'], ['admin_per_user' => $probes['admin_per_user']]);
    $expect($name . ':open_connections_cut_and_none_remain', $remaining === [] && array_sum($killed) > 0, ['killed' => $killed, 'remaining' => count($remaining)]);
    $expect($name . ':credentials_least_privilege_profile_recorded', in_array('-@dangerous', explode(' ', (string) ($profilesAfter['w_direct']['commands'] ?? '')), true) && in_array('-function', explode(' ', (string) ($profilesAfter['w_direct']['commands'] ?? '')), true), ['w_direct' => $profilesAfter['w_direct'] ?? null]);
    $mapFirst = v2Map($s->ctl);
    $g1 = gate($s->ctl, $s->base, $namespaces);
    $s->mark('gate_while_block_active');
    $expect($name . ':gate_denies_while_block_active', ! $g1['ok'] && in_array('BLOCK_ACTIVE', $g1['reasons'], true), ['reasons' => $g1['reasons']]);
    $first = [];
    $maxExp = '0';
    foreach ($namespaces as $ns) {
        foreach (scanAll($s->ctl, nsKeyPattern($ns)) as $k) {
            $pe = (string) $s->ctl->execute(['PEXPIRETIME', $k]);
            if ($pe === '-2') {
                continue;
            }
            $first[$k] = $pe;
            if (isNonNegDecimal($pe) && dcmp($pe, $maxExp) > 0) {
                $maxExp = $pe;
            }
        }
    }
    $s->mark('first_scan_done');
    $s->timeline['max_block_expiry_ms'] = $maxExp;
    $ledger = $s->waitForExpiryWithLedger($first, $maxExp);
    usleep(1200000);
    $s->mark('natural_expiry_passed');
    $synced = $s->syncWatcher();
    $since = $s->timeline['first_scan_done'];
    $events = $s->eventViolations($first, $since);
    $final = gate($s->ctl, $s->base, $namespaces, $first, $ledger, $events);
    $s->mark('final_scan_done');
    $expect($name . ':watcher_synced_before_final_gate', $synced);
    $mapFinal = v2Map($s->ctl);
    $modified = [];
    foreach ($mapFinal as $k => $v) {
        if (! isset($mapFirst[$k]) || $mapFirst[$k] !== $v) {
            $modified[] = $k;
        }
    }
    $expect($name . ':no_v2_key_created_or_modified_between_first_scan_and_final', $modified === [], ['modified_or_new' => array_slice($modified, 0, 5), 'keys_first' => count($mapFirst), 'keys_final' => count($mapFinal)]);
    $expect($name . ':previous_generation_block_seen_by_first_scan', array_key_exists($synthPrev['key'], $first), ['previous_key_pexpiretime' => $synthPrev['pexpiretime_raw']]);
    $expect($name . ':ledger_clean_during_wait', $ledger === [], ['violations' => $ledger, 'tracked_block_keys' => count($first)]);
    $expect($name . ':only_natural_expiry_events_for_tracked_keys', $events === [], ['violations' => $events]);
    $expect($name . ':gate_passes_after_natural_expiry', $final['ok'], ['reasons' => $final['reasons']]);
    $permitted = false;
    if ($final['ok']) {
        sleep(3);
        $recheck = gate($s->ctl, $s->base, $namespaces, $first, $ledger, $s->eventViolations($first, $since));
        if ($recheck['ok']) {
            $s->ctl->execute(['SET', 'rehearsal:activation:allowed', redisMs($s->ctl)]);
            $permitted = true;
            $s->mark('activation_signal_set');
        }
        $expect($name . ':validity_recheck_before_signal', $recheck['ok'], ['reasons' => $recheck['reasons']]);
    }
    $legacyLive = array_values(array_filter($liveBeforeKill, static fn($w) => ($w['role'] ?? '') === 'legacy'));
    $postBarrierOk = 0;
    foreach ($legacyLive as $w) {
        if (dcmp((string) ($w['last_ok_ms'] ?? '0'), (string) ($s->timeline['barrier_applied'] ?? '0')) > 0) {
            $postBarrierOk++;
        }
    }
    $expect($name . ':legacy_live_connection_no_success_after_barrier_and_denials_observed', $postBarrierOk === 0 && (int) ($legacyLive[0]['noperm'] ?? 0) > 0, ['legacy_stats_before_kill' => $legacyLive]);
    $perPolicy = $reopen($s, $policyNs, $expect, $name);
    $ws = $s->workerStats();
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
        'reopen_to_all_policies_verified_ms' => $ms('reopen_requested', 'first_success_after_reopen'),
        'total_stop_to_all_policies_verified_ms' => $ms('intake_closed', 'first_success_after_reopen'),
        'per_policy_reopen_to_verified_result_ms' => array_map(static fn($x) => $x['return_ms'], $perPolicy),
    ];
    $res = ['name' => $name, 'policy_ns' => $policyNs, 'intake_close' => $close + ['accepted_later' => $acceptedLater, 'rejected_later' => $rejectedLater], 'k4_representability' => $k4,
        'synthetic_blocks' => $synth, 'synthetic_previous_generation_block' => $synthPrev, 'timeline_redis_ms' => $t, 'measured' => $measured, 'probes' => $probes,
        'user_profiles_after_barrier' => $profilesAfter, 'killed_connections' => $killed, 'gate_while_block_active' => $g1, 'gate_final' => $final, 'activation_permitted' => $permitted,
        'reopen_per_policy' => $perPolicy, 'worker_stats' => $ws];
    $results['scenarios'][$name] = $res;
    jlog('scenario_result', ['scenario' => $name, 'measured' => $measured, 'activation_permitted' => $permitted, 'k4' => $k4]);

    return $res;
};

$want = static fn(string $n): bool => ($f = getenv('S284_SCENARIOS')) === false || $f === '' || in_array(explode('_', $n)[0], explode(',', $f), true);
if ($want('A_')) {
    $fullRun('A_login_protection_own_ns', ['login_protection' => 'rl-login'], 20);
}
if ($want('B_')) {
    $fullRun('B_otp_protection_own_ns', ['otp_protection' => 'rl-otp'], 20);
}
if ($want('C_')) {
    $fullRun('C_api_heavy_protection_own_ns', ['api_heavy_protection' => 'rl-api'], 20);
}
if ($want('D_')) {
    $fullRun('D_login_and_otp_shared_ns', ['login_protection' => 'rl-shared', 'otp_protection' => 'rl-shared'], 40);
}

// ---- negative controls (each on a fresh Redis state) ---------------------------------------------------------------------------
$negBase = ['login_protection' => 'rl-login'];
$neg = static function (string $id, callable $scenario) use ($bootstrap, $negBase, &$results, $want): void {
    if (! $want($id)) {
        return;
    }
    $s = new Scenario($id, $negBase);
    $s->setup($bootstrap);
    $out = $scenario($s);
    $s->stopAll();
    $results['negative'][$id] = $out;
};

// N1: a request still in flight blocks the drain condition
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
    $expect('N1:passes_after_the_request_finished', ! in_array('NOT_DRAINED', $g2['reasons'], true), ['reasons' => $g2['reasons']]);

    return ['denied' => $g['reasons'], 'after' => $g2['reasons']];
});

// N1b (F291-02): a request pulled out of the ready queue but NOT yet registered as in-flight: the atomic snapshot still denies (it sits in the processing list)
$neg('N1b_request_between_pull_and_inflight_registration', static function (Scenario $s) use ($expect): array {
    $s->startWorkers(false);
    $s->ctl->execute(['RPUSH', 'rehearsal:q:ready:login_protection', json_encode(['id' => 'pull1', 'account' => 'acct-pull', 'kind' => 'failure', 'hold_after_pull_ms' => 4000])]);
    for ($i = 0; $i < 60 && (string) $s->ctl->execute(['GET', 'rehearsal:pull_held']) !== 'pull1'; $i++) {
        usleep(50000);
    }
    $held = (string) $s->ctl->execute(['GET', 'rehearsal:pull_held']) === 'pull1';
    $s->closeIntake();
    $snap = drainSnapshot($s->ctl);
    $g = gate($s->ctl, $s->base, ['rl-login']);
    $expect('N1b:request_is_held_between_pull_and_inflight', $held && $snap[0] === 1 && $snap[2] === [], ['snapshot' => $snap]);
    $expect('N1b:gate_denies_with_empty_inflight_registry', ! $g['ok'] && in_array('NOT_DRAINED', $g['reasons'], true), ['queues' => $g['detail']['queues_nonempty'], 'inflight' => $g['detail']['inflight_workers']]);
    sleep(6);
    $g2 = gate($s->ctl, $s->base, ['rl-login']);
    $expect('N1b:passes_after_it_finished', ! in_array('NOT_DRAINED', $g2['reasons'], true), ['reasons' => $g2['reasons']]);

    return ['held_snapshot' => $snap, 'denied' => $g['reasons']];
});

// N2: pending retry and delayed jobs block the gate
$neg('N2_pending_retry_and_delayed_jobs_block_gate', static function (Scenario $s) use ($expect): array {
    $s->ctl->execute(['ZADD', 'rehearsal:q:retry:login_protection', (string) ((int) redisMs($s->ctl) + 600000), json_encode(['account' => 'a', 'kind' => 'failure'])]);
    $s->ctl->execute(['ZADD', 'rehearsal:q:delayed:login_protection', (string) ((int) redisMs($s->ctl) + 600000), json_encode(['account' => 'a', 'kind' => 'failure'])]);
    $s->closeIntake();
    $s->applyBarrier();
    $g = gate($s->ctl, $s->base, ['rl-login']);
    $expect('N2:denied_pending_jobs', ! $g['ok'] && in_array('NOT_DRAINED', $g['reasons'], true), ['queues' => $g['detail']['queues_nonempty']]);

    return ['denied' => $g['reasons']];
});

// N3: a writer not restricted is a hole (and really writes); a user flagged `off` with an open connection is a hole too
$neg('N3_unrevoked_writer_and_off_user_with_open_connection', static function (Scenario $s) use ($expect): array {
    $s->closeIntake();
    $s->applyBarrier(['w_direct', 'w_deferred', 'w_retry']);
    $g = gate($s->ctl, $s->base, ['rl-login']);
    $expect('N3:denied_barrier_hole', ! $g['ok'] && in_array('WRITE_BARRIER_HOLE', $g['reasons'], true), ['holes' => $g['detail']['barrier_holes']]);
    $c = connect('w_legacy', pwOf('w_legacy'));
    $wrote = false;
    try {
        runCommand(runtime($c, 'rl-login'), 'login_protection', 'acct-straggler', 'failure');
        $wrote = scanAll($s->ctl, PREFIX . ':*') !== [];
    } catch (\Throwable) {
    }
    $expect('N3:the_unrestricted_writer_really_writes', $wrote);
    $s->applyBarrier();
    // `off` does NOT cut an open connection: show it, then show the gate refuses it
    $s->ctl->execute(['ACL', 'SETUSER', 'w_legacy', 'off']);
    $stillWorks = false;
    try {
        $c->execute(['SET', 'rehearsal:off_probe', '1']);
        $stillWorks = (string) $s->ctl->execute(['GET', 'rehearsal:off_probe']) === '1';
    } catch (\Throwable) {
    }
    $expect('N3:off_user_open_connection_still_executes', $stillWorks);
    $g2 = gate($s->ctl, $s->base, ['rl-login']);
    $expect('N3:gate_denies_off_user_with_open_connection', ! $g2['ok'] && in_array('OPEN_CONNECTION_OF_UNSAFE_USER', $g2['reasons'], true), ['open' => $g2['detail']['open_connections_of_unsafe_users']]);
    $s->ctl->execute(['CLIENT', 'KILL', 'USER', 'w_legacy']);
    $g3 = gate($s->ctl, $s->base, ['rl-login']);
    $expect('N3:gate_passes_after_connection_cut', ! in_array('OPEN_CONNECTION_OF_UNSAFE_USER', $g3['reasons'], true), ['reasons' => $g3['reasons']]);

    return ['holes' => $g['detail']['barrier_holes'], 'after_off' => $g2['reasons'], 'after_kill' => $g3['reasons']];
});

// N4: unknown expiry
$neg('N4_unknown_expiry', static function (Scenario $s) use ($expect): array {
    $noTtl = PREFIX . ':' . hash('sha256', 'rl-login') . ':block:' . hash('sha256', 'no-ttl');
    $s->ctl->execute(['HSET', $noTtl, 'level', '2', 'expiresAt', '0']);
    $s->closeIntake();
    $s->applyBarrier();
    $g = gate($s->ctl, $s->base, ['rl-login']);
    $expect('N4:denied_unknown_expiry', ! $g['ok'] && in_array('BLOCK_UNKNOWN_EXPIRY', $g['reasons'], true), ['unknown' => $g['detail']['unknown_expiry']]);

    return ['reasons' => $g['reasons']];
});

// N4b (F291-03): a key the first scan listed and that is absent now must be explained by its recorded expiry; here it was deleted early
$neg('N4b_early_delete_is_unexplained_absence', static function (Scenario $s) use ($expect): array {
    $store = new RedisFullCapabilityStore($s->ctl, 'rl-login');
    $store->blockAt(k4Hmac('login_protection', 'acct-vanish'), 3, 60, time());
    $s->closeIntake();
    $s->applyBarrier();
    $first = [];
    foreach (scanAll($s->ctl, nsKeyPattern('rl-login')) as $k) {
        $first[$k] = (string) $s->ctl->execute(['PEXPIRETIME', $k]);
    }
    foreach (array_keys($first) as $k) {
        $s->ctl->execute(['DEL', $k]);
    }
    $g = gate($s->ctl, $s->base, ['rl-login'], $first);
    $expect('N4b:denied_unexplained_absence_by_early_delete', ! $g['ok'] && in_array('BLOCK_UNEXPLAINED_ABSENCE', $g['reasons'], true), ['unexplained' => count($g['detail']['unexplained_absence'])]);

    return ['reasons' => $g['reasons']];
});

// N4c (F291-03): an EARLY DELETE mid-wait is caught by the ledger although the final scan happens after the recorded expiry
// N4d: a TRUNCATED expiry (PEXPIREAT earlier) is caught by the ledger (an earlier expiry would otherwise look like a natural one)
$neg('N4cd_ledger_and_events_catch_early_delete_and_truncation', static function (Scenario $s) use ($expect): array {
    $s->startWatcher();
    $store = new RedisFullCapabilityStore($s->ctl, 'rl-login');
    $keys = [];
    foreach (['early', 'truncate', 'late'] as $i => $n) {
        $logical = k4Hmac('login_protection', 'acct-' . $n);
        $store->blockAt($logical, 3, 14 + $i * 2, time());
        $keys[$n] = PREFIX . ':' . hash('sha256', 'rl-login') . ':block:' . hash('sha256', $logical);
    }
    $s->closeIntake();
    $s->applyBarrier();
    $first = [];
    $maxExp = '0';
    foreach (scanAll($s->ctl, nsKeyPattern('rl-login')) as $k) {
        $pe = (string) $s->ctl->execute(['PEXPIRETIME', $k]);
        $first[$k] = $pe;
        if (dcmp($pe, $maxExp) > 0) {
            $maxExp = $pe;
        }
    }
    $sinceMs = redisMs($s->ctl);
    $ledger = $s->waitForExpiryWithLedger($first, $maxExp, static function () use ($s, $keys): void {
        $s->ctl->execute(['DEL', $keys['early']]);
        $s->ctl->execute(['PEXPIREAT', $keys['truncate'], (string) ((int) redisMs($s->ctl) + 1000)]);
    });
    usleep(1500000);
    $s->syncWatcher();
    $events = $s->eventViolations($first, $sinceMs);
    $g = gate($s->ctl, $s->base, ['rl-login'], $first, $ledger, $events);
    $ledgerKeys = array_map(static fn($v) => $v['key'], $ledger);
    $expect('N4c:ledger_caught_early_delete', in_array(substr($keys['early'], -12), $ledgerKeys, true), ['ledger' => $ledger]);
    $expect('N4d:ledger_caught_truncated_expiry', in_array(substr($keys['truncate'], -12), $ledgerKeys, true), ['ledger' => $ledger]);
    $expect('N4cd:gate_denies_after_the_recorded_expiry_has_passed', ! $g['ok'] && in_array('BLOCK_EARLY_ABSENCE_OR_CHANGE', $g['reasons'], true) && in_array('BLOCK_EVENT_VIOLATION', $g['reasons'], true), ['reasons' => $g['reasons'], 'events' => $events]);
    $expect('N4cd:final_scan_alone_would_see_no_block_at_all', $g['detail']['block_keys_present'] === 0, ['block_keys_present' => $g['detail']['block_keys_present']]);

    return ['reasons' => $g['reasons'], 'ledger' => $ledger, 'events' => $events];
});

// N4e: delete a block in the last ~120 ms before its expiry: polls (200 ms) may miss it, the keyspace events must not
$neg('N4e_last_moment_delete_caught_by_events', static function (Scenario $s) use ($expect): array {
    $s->startWatcher();
    $store = new RedisFullCapabilityStore($s->ctl, 'rl-login');
    $logical = k4Hmac('login_protection', 'acct-lastmoment');
    $store->blockAt($logical, 3, 6, time());
    $phys = PREFIX . ':' . hash('sha256', 'rl-login') . ':block:' . hash('sha256', $logical);
    $s->closeIntake();
    $s->applyBarrier();
    $first = [$phys => (string) $s->ctl->execute(['PEXPIRETIME', $phys])];
    $sinceMs = redisMs($s->ctl);
    $exp = $first[$phys];
    while (dcmp($exp, redisMs($s->ctl)) > 0 && dcmp(dsub($exp, redisMs($s->ctl)), '120') > 0) {
        usleep(10000);
    }
    $s->ctl->execute(['DEL', $phys]);
    $leftMs = dcmp($exp, redisMs($s->ctl)) > 0 ? dsub($exp, redisMs($s->ctl)) : '0';
    while (dcmp(redisMs($s->ctl), $exp) <= 0) {
        usleep(20000);
    }
    usleep(500000);
    $s->syncWatcher();
    $events = $s->eventViolations($first, $sinceMs);
    $g = gate($s->ctl, $s->base, ['rl-login'], $first, [], $events);
    $expect('N4e:events_caught_delete_before_expiry', $events !== [] && in_array('BLOCK_EVENT_VIOLATION', $g['reasons'], true), ['left_ms_at_delete' => $leftMs, 'events' => $events]);

    return ['left_ms_at_delete' => $leftMs, 'events' => $events, 'reasons' => $g['reasons']];
});

// N5: continuity break (sentinel lost)
$neg('N5_data_continuity_break_sentinel_lost', static function (Scenario $s) use ($expect): array {
    $s->closeIntake();
    $s->applyBarrier();
    $s->ctl->execute(['DEL', 'rehearsal:sentinel']);
    $g = gate($s->ctl, $s->base, ['rl-login']);
    $expect('N5:denied_continuity', ! $g['ok'] && in_array('DATA_CONTINUITY_BROKEN', $g['reasons'], true), ['continuity' => $g['detail']['continuity']]);

    return ['reasons' => $g['reasons']];
});

// N6: a late writer after the barrier (live connection): denied by Redis, logged in ACL LOG, V2 keyspace unchanged
$neg('N6_late_writer_after_barrier_denied_and_gate_holds', static function (Scenario $s) use ($expect): array {
    $s->startWatcher();
    $s->startWorkers(true, true);
    sleep(2);
    $s->closeIntake();
    $s->waitDrained(20);
    $s->applyBarrier();
    $before = v2Digest($s->ctl);
    usleep(1500000);
    $after = v2Digest($s->ctl);
    $ws = $s->workerStats();
    $legacy = array_values(array_filter($ws, static fn($w) => ($w['role'] ?? '') === 'legacy'));
    $nop = (int) ($legacy[0]['noperm'] ?? 0);
    $viaLegacy = 0;
    foreach ((array) $s->ctl->execute(['ACL', 'LOG', '50']) as $e) {
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

// N8 (F291-01): a request that has FINISHED evaluating and is about to send its COMMIT when the barrier lands: the commit is denied by Redis, nothing of it is stored
$neg('N8_request_at_commit_when_barrier_lands', static function (Scenario $s) use ($expect): array {
    $s->startWorkers(false);
    $s->ctl->execute(['RPUSH', 'rehearsal:q:ready:login_protection', json_encode(['id' => 'cm1', 'result_id' => 'cm1', 'account' => 'acct-commit', 'kind' => 'failure', 'hold_at_commit' => true])]);
    for ($i = 0; $i < 100 && (string) $s->ctl->execute(['GET', 'rehearsal:commit_held']) !== 'cm1'; $i++) {
        usleep(50000);
    }
    $held = (string) $s->ctl->execute(['GET', 'rehearsal:commit_held']) === 'cm1';
    $expect('N8:request_is_held_at_its_commit', $held);
    $s->closeIntake();
    $gHeld = gate($s->ctl, $s->base, ['rl-login']);
    $expect('N8:gate_denies_while_commit_is_pending', in_array('NOT_DRAINED', $gHeld['reasons'], true), ['reasons' => $gHeld['reasons']]);
    $s->applyBarrier();
    $digestBefore = v2Digest($s->ctl);
    $censusBefore = k4Census($s->ctl, 'login_protection', 'rl-login', ['acct-commit'])['families'];
    $s->ctl->execute(['SET', 'rehearsal:commit_release', 'cm1']);
    $res = null;
    for ($i = 0; $i < 100 && ! is_string($res); $i++) {
        usleep(50000);
        $res = $s->ctl->execute(['GET', 'rehearsal:result:cm1']);
    }
    $r = is_string($res) ? json_decode($res, true) : null;
    $digestAfter = v2Digest($s->ctl);
    $censusAfter = k4Census($s->ctl, 'login_protection', 'rl-login', ['acct-commit'])['families'];
    $expect('N8:commit_denied_by_redis', is_array($r) && ($r['ok'] ?? true) === false && isDenied((string) ($r['error'] ?? '')), ['result' => $r]);
    $expect('N8:nothing_of_the_request_is_stored', $digestBefore === $digestAfter && $censusBefore === $censusAfter && array_sum($censusAfter) === 0, ['census_after' => $censusAfter]);
    $g = gate($s->ctl, $s->base, ['rl-login']);
    $expect('N8:gate_ok_once_the_denied_request_ended', ! in_array('NOT_DRAINED', $g['reasons'], true), ['reasons' => $g['reasons']]);

    return ['held_gate' => $gHeld['reasons'], 'result' => $r, 'after' => $g['reasons']];
});

// N8b: the same request when the connection is CUT while its commit is pending: the commit never reaches Redis
$neg('N8b_connection_cut_while_commit_pending', static function (Scenario $s) use ($expect): array {
    $s->startWorkers(false);
    $s->ctl->execute(['RPUSH', 'rehearsal:q:ready:login_protection', json_encode(['id' => 'cm2', 'result_id' => 'cm2', 'account' => 'acct-commit2', 'kind' => 'failure', 'hold_at_commit' => true])]);
    for ($i = 0; $i < 100 && (string) $s->ctl->execute(['GET', 'rehearsal:commit_held']) !== 'cm2'; $i++) {
        usleep(50000);
    }
    $s->closeIntake();
    $s->applyBarrier();
    $killed = $s->killConnections();
    $s->ctl->execute(['SET', 'rehearsal:commit_release', 'cm2']);
    usleep(1500000);
    $censusAfter = k4Census($s->ctl, 'login_protection', 'rl-login', ['acct-commit2'])['families'];
    $expect('N8b:cut_connection_commit_never_lands', array_sum($censusAfter) === 0 && array_sum($killed) > 0, ['killed' => $killed, 'census_after' => $censusAfter]);

    return ['killed' => $killed, 'census_after' => $censusAfter];
});

// N5b: eviction
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

// N7: a real Redis restart
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
$payload = json_encode($results + ['control_failures' => $failedControls], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$file = $outDir . '/rehearsal-results.json';
$written = $payload !== false && file_put_contents($file, $payload) === strlen($payload);
$readBack = $written ? file_get_contents($file) : false;
$decoded = is_string($readBack) ? json_decode($readBack, true) : null;
$savedOk = $written && is_array($decoded) && ($decoded['control_failures'] ?? null) === $failedControls && (getenv('S284_SCENARIOS') !== false && getenv('S284_SCENARIOS') !== '' || isset($decoded['scenarios']['A_login_protection_own_ns']));
jlog('results_file', ['path' => $file, 'saved_and_verified' => $savedOk, 'bytes' => is_string($readBack) ? strlen($readBack) : 0, 'results_sha256' => is_string($readBack) ? hash('sha256', $readBack) : '']);
if (! $savedOk) {
    fwrite(STDERR, "RESULTS FILE COULD NOT BE SAVED OR VERIFIED\n");
    exit(5);
}
exit($failedControls === 0 ? 0 : 1);
