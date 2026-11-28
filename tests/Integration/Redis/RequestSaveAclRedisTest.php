<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\FailureSignalDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Service\LocalFallbackLimiter;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Redis\OverlayWire;
use Maatify\RateLimiter\Tests\Support\Redis\PhpRedisScriptExecutor;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #261 (DEC-037, DEC-015, DEC-018): the request save of the official Redis store under a Redis ACL that does not give the application
 * account everything the request path needs. STUDY ONLY: it characterises what the shipped code does, through the public
 * `transactRequest()` and `RateLimiterBuilder`/`limit()`, with a restricted account per case, a success-control account configured with the
 * commands the request path uses, an independent `MONITOR` connection and the keyspace read from outside Lua. It changes no behaviour.
 *
 * Every case has its own temporary ACL account (removed afterwards); the data is synthetic. With the environment variable
 * `ACL_261_EVIDENCE` set to a file path each case appends one JSON line of what it observed; the assertions below are the observed
 * contract, and the issue's inferred-from-source expectations that the observation refuted are recorded in the study's README.
 */
#[Group('redis-acl-request-save-261')]
#[Group('php32')]
final class RequestSaveAclRedisTest extends TestCase
{
    private const PASSWORD = 'synthetic-study-password';

    private const SCOPE = 'maatify:rate-limiter:v1:*';

    private const NAMESPACE = 'acl-261';

    private const CONTROL_KEY = 'maatify:rate-limiter:v1:control-261';

    /** Every command the request path sends to Redis (see the study's inventory): the commands of the overlay's live reads and of the commit. */
    private const REQUEST_COMMANDS = ['eval', 'time', 'exists', 'type', 'pexpiretime', 'get', 'hgetall', 'smembers', 'zrange', 'del', 'set', 'hset', 'sadd', 'zadd', 'pexpireat'];

    private const WRITES = ['DEL', 'SET', 'HSET', 'SADD', 'ZADD', 'PEXPIREAT', 'HDEL', 'ZREM', 'SREM', 'PERSIST', 'EXPIRE', 'PEXPIRE'];

    private RespRedisCommandExecutor $admin;

    private string $host;

    private int $port;

    /** @var list<string> */
    private array $users = [];

    /** @var list<resource> */
    private array $processes = [];

    /** @var resource|null */
    private $monitor;

    private int $marker = 0;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        if (! extension_loaded('redis')) {
            self::fail('ext-redis is required for the #261 study (the native Host shape).');
        }
        $this->host = $host;
        $this->port = (int) $port;
        $this->admin = new RespRedisCommandExecutor($host, (int) $port, RespRedisCommandExecutor::MODE_EVAL);
        $this->admin->execute(['FLUSHDB']);
        $this->admin->execute(['ACL', 'LOG', 'RESET']);
        $info = self::text($this->admin->execute(['INFO', 'server']));
        self::assertMatchesRegularExpression('/redis_version:7\./', $info, 'The study needs Redis 7 (ACL in scripts, redis.acl_check_cmd).');
        $socket = stream_socket_client("tcp://{$host}:{$port}", $errorCode, $errorMessage, 5);
        self::assertIsResource($socket);
        stream_set_timeout($socket, 5);
        fwrite($socket, "MONITOR\r\n");
        self::assertSame("+OK\r\n", fgets($socket));
        $this->monitor = $socket;
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        if (isset($this->admin)) {
            foreach ($this->users as $user) {
                $this->admin->execute(['ACL', 'DELUSER', $user]);
            }
            $this->admin->execute(['FLUSHDB']);
        }
        if (is_resource($this->monitor)) {
            fclose($this->monitor);
        }
    }

    // ------------------------------------------------------------------ accounts and Host shapes

    /** @param list<string> $denied commands the account lacks
     * @param list<string> $keys the key rules */
    private function createUser(string $user, array $denied = [], array $keys = ['~' . self::SCOPE]): void
    {
        $rules = array_map(static fn(string $command): string => '+' . $command, array_values(array_diff(self::REQUEST_COMMANDS, $denied)));
        $this->admin->execute(['ACL', 'SETUSER', $user, 'reset', 'on', '>' . self::PASSWORD, ...$keys, ...$rules]);
        $this->users[] = $user;
    }

    /** The Host executor of a shape, authenticated as $user. `phpredis` is ext-redis `rawCommand()`, `resp` is the suite's own RESP client. */
    private function host(string $shape, string $user, ?int $port = null): RedisCommandExecutorInterface
    {
        // A proxy port means the reply-dropping proxy of this process, which listens on the loopback.
        $host = $port === null ? $this->host : '127.0.0.1';
        $port ??= $this->port;
        if ($shape === 'resp') {
            $executor = new RespRedisCommandExecutor($host, $port, RespRedisCommandExecutor::MODE_EVAL);
            self::assertSame('OK', $executor->execute(['AUTH', $user, self::PASSWORD]));

            return $executor;
        }
        $redis = new \Redis();
        self::assertTrue($redis->connect($host, $port, 5.0));
        self::assertTrue($redis->auth([$user, self::PASSWORD]));
        $inner = new PhpRedisScriptExecutor($redis, false);

        // The Host's duty (DEC-015/DEC-018): only a failure with positive evidence of a lost connection is a BackendFailureException. ext-redis
        // keeps its connection flag (`isConnected()`) true after a server error reply and after a protocol error, and clears it when the connection is
        // lost ('Connection lost', 'went away'); the flag, not the message text, is the evidence. A server reply (known code or not, coded or not) and
        // an exception without that evidence propagate unchanged: they are not reclassified here.
        return new class ($inner, $redis) implements RedisCommandExecutorInterface {
            public function __construct(private readonly PhpRedisScriptExecutor $inner, private readonly \Redis $redis) {}

            public function execute(array $command): mixed
            {
                try {
                    return $this->inner->execute($command);
                } catch (\RedisException $exception) {
                    if (! $this->redis->isConnected()) {
                        throw new BackendFailureException('Redis transport failure: ' . $exception->getMessage());
                    }

                    throw $exception;
                }
            }
        };
    }

    // ------------------------------------------------------------------ observation from outside the store

    /** @return array<string, array{type: string, dump: string, expires: string}> every key of the database */
    private function keyspace(): array
    {
        $state = [];
        $cursor = '0';
        do {
            $reply = $this->admin->execute(['SCAN', $cursor, 'COUNT', 1000]);
            self::assertIsArray($reply);
            $cursor = self::text($reply[0]);
            foreach ((array) $reply[1] as $name) {
                $key = self::text($name);
                $dump = $this->admin->execute(['DUMP', $key]);
                $state[$key] = [
                    'type' => self::text($this->admin->execute(['TYPE', $key])),
                    'dump' => is_string($dump) ? bin2hex($dump) : '',
                    'expires' => self::text($this->admin->execute(['PEXPIRETIME', $key])),
                ];
            }
        } while ($cursor !== '0');
        ksort($state);

        return $state;
    }

    /** The local fallback limiter's counters (static state of the process), read directly: the direct metric of a fallback charge. */
    private static function fallbackSnapshot(): string
    {
        $counters = new \ReflectionProperty(LocalFallbackLimiter::class, 'counters');
        $tracked = new \ReflectionProperty(LocalFallbackLimiter::class, 'trackedSubjects');

        return json_encode([$counters->getValue(), $tracked->getValue()], JSON_THROW_ON_ERROR);
    }

    /** One pipe of a `proc_open()` call; `$pipes` is typed differently by different PHPStan versions, so it is read through `mixed`. */
    private static function pipeOf(mixed $pipes, int $index): mixed
    {
        return is_array($pipes) ? ($pipes[$index] ?? null) : null;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function family(string $key): string
    {
        if ($key === self::CONTROL_KEY) {
            return 'control';
        }

        return preg_match('/^maatify:rate-limiter:(?:v1|e1):[0-9a-f]{64}:([a-z-]+):/', $key, $match) === 1 ? $match[1] : 'other';
    }

    /**
     * @param array<string, array{type: string, dump: string, expires: string}> $before
     * @param array<string, array{type: string, dump: string, expires: string}> $after
     * @return array<string, string> family:keyIndex => what changed
     */
    private function diff(array $before, array $after): array
    {
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $b = $before[$key] ?? null;
            $a = $after[$key] ?? null;
            $what = match (true) {
                $b === null => 'created',
                $a === null => 'DELETED',
                $a['dump'] !== $b['dump'] && $a['expires'] !== $b['expires'] => 'content+expiry',
                $a['dump'] !== $b['dump'] => 'content',
                $a['expires'] !== $b['expires'] => 'expiry',
                default => null,
            };
            if ($what !== null && $a !== null && $a['expires'] === '-1' && ($b === null || $b['expires'] !== '-1')) {
                $what .= ' (WITHOUT EXPIRY)';
            }
            if ($what !== null) {
                $changes[$this->family($key) . ' ' . substr(sha1($key), 0, 6)] = $what;
            }
        }
        ksort($changes);

        return $changes;
    }

    /** The address of the account's connection, from CLIENT LIST (the account name is unique to this role). */
    private function addressOf(string $user): ?string
    {
        foreach (explode("\n", self::text($this->admin->execute(['CLIENT', 'LIST']))) as $line) {
            if (preg_match('/ addr=(\S+) .* user=' . preg_quote($user, '/') . ' /', $line . ' ', $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }

    private function markMonitor(): void
    {
        $label = 'monitor-marker-' . ++$this->marker;
        $this->admin->execute(['ECHO', $label]);
        $this->drainMonitor($label);
    }

    /** @return list<string> every MONITOR line up to the one that carries $label */
    private function drainMonitor(string $label): array
    {
        $lines = [];
        $socket = $this->monitor;
        self::assertIsResource($socket);
        while (($line = fgets($socket)) !== false) {
            if (str_contains($line, $label)) {
                return $lines;
            }
            $lines[] = rtrim($line);
        }
        self::fail('The MONITOR connection did not deliver its marker.');
    }

    /**
     * @param list<string> $lines
     * @return array{evals: int, luaCommands: array<string, int>, luaWrites: list<string>}
     */
    private function monitorSummary(array $lines, ?string $address): array
    {
        $evals = 0;
        $lua = [];
        $writes = [];
        foreach ($lines as $line) {
            if (preg_match('/^\+?\d+\.\d+ \[\d+ ([^\]]+)\] "([^"]+)"(.*)$/', $line, $match) !== 1) {
                continue;
            }
            $command = strtoupper($match[2]);
            if ($match[1] === 'lua') {
                $lua[$command] = ($lua[$command] ?? 0) + 1;
                if (in_array($command, self::WRITES, true)) {
                    preg_match('/"([^"]*)"/', $match[3], $key);
                    $writes[] = $command . ' ' . $this->family($key[1] ?? '');
                }
            } elseif ($command === 'EVAL' && $match[1] === $address) {
                $evals++;
            }
        }
        ksort($lua);

        return ['evals' => $evals, 'luaCommands' => $lua, 'luaWrites' => $writes];
    }

    /** @return list<array<string, string>> ACL LOG entries of one account, oldest first */
    private function aclLog(string $user): array
    {
        $entries = [];
        foreach ((array) $this->admin->execute(['ACL', 'LOG', 100]) as $entry) {
            $map = [];
            $flat = (array) $entry;
            for ($i = 0; $i + 1 < count($flat); $i += 2) {
                $map[self::text($flat[$i])] = is_array($flat[$i + 1]) ? '[...]' : self::text($flat[$i + 1]);
            }
            if (($map['username'] ?? '') === $user) {
                $entries[] = ['reason' => $map['reason'] ?? '', 'context' => $map['context'] ?? '', 'object' => substr($map['object'] ?? '', 0, 24), 'count' => $map['count'] ?? ''];
            }
        }

        return array_reverse($entries);
    }

    // ------------------------------------------------------------------ the request under study

    /** @return array{score: string, block: string, budget: string, distinct: string, watch: string, bounded: string, cycle: string, 'cycle-previous': string} the synthetic request's logical keys */
    private function names(): array
    {
        return ['score' => 'score-a', 'block' => 'block-a', 'budget' => 'budget-a', 'distinct' => 'distinct-a', 'watch' => 'watch-a', 'bounded' => 'bounded-a', 'cycle' => 'cycle-a', 'cycle-previous' => 'cycle-b'];
    }

    /**
     * The synthetic request: five store operations over the five families the request path saves (string score, block hash, budget
     * hash, distinct set and its metadata hash, watch counter and its metadata hash). Used for seeding (directly) and as the evaluation.
     *
     * @param (callable(int): void)|null $afterStep called after each completed step with its number
     * @return list<mixed>
     */
    private function operations(RedisFullCapabilityStore $store, int $now, ?callable $afterStep = null, int $cycleOffset = 0): array
    {
        $names = $this->names();
        $results = [];
        $steps = [
            fn(): mixed => $store->incrementAt($names['score'], 600, 1, $now),
            static function () use ($store, $names, $now): mixed {
                $store->blockAt($names['block'], 1, 300, $now);

                return null;
            },
            fn(): mixed => $store->incrementBudgetAt($names['budget'], 3600, 1, $now)->count,
            fn(): mixed => $store->addDistinctAt($names['distinct'], 'item-' . random_int(1, PHP_INT_MAX), 600, $now),
            fn(): mixed => $store->incrementWatchFlagAt($names['watch'], 600, $now),
            fn(): mixed => $store->addDistinctBoundedAt($names['bounded'], 'item-' . random_int(1, PHP_INT_MAX), 600, 100, $now)->count,
            fn(): mixed => $store->blockWithCycleTracking($names['cycle'], $names['cycle-previous'], 2, 300, $now + $cycleOffset, 3600, 3, 600, 86400)->cycleCount,
        ];
        foreach ($steps as $index => $step) {
            $results[] = $step();
            if ($afterStep !== null) {
                $afterStep($index + 1);
            }
        }

        return $results;
    }

    /**
     * One case. Returns what it observed.
     *
     * @param array{shape: string, seed?: bool, denied?: list<string>, keys?: list<string>, hook?: string, proxy?: bool, engine?: bool, plan?: string} $case
     * @return array{case: string, shape: string, sentCommit: bool, evaluations: int, stepsReached: int, wire: list<string>, monitor: array{evals: int, luaCommands: array<string, int>, luaWrites: list<string>}, monitorLines: int, monitorSample: list<string>, address: string|null, aclLog: list<array<string, string>>, result: string, thrown: string|null, signals: list<string>, changed: array<string, string>, types: array<string, string>, controlUnchanged: bool, writerSnapshotEqualsFinal: bool|null, fallbackCharged: bool, keysBefore: int, keysAfter: int, redis: string}
     */
    private function observe(string $name, array $case): array
    {
        $this->admin->execute(['FLUSHDB']);
        $this->admin->execute(['ACL', 'LOG', 'RESET']);
        // The seeding account runs the store's direct (non-overlay) scripts, which use more commands than the request path does.
        $this->admin->execute(['ACL', 'SETUSER', 'seed-261', 'reset', 'on', '>' . self::PASSWORD, '~*', '+@all']);
        $this->users[] = 'seed-261';
        $subject = 'subject-261';
        $this->createUser($subject, $case['denied'] ?? [], $case['keys'] ?? ['~' . self::SCOPE]);

        // Seed: the same five operations, directly, by an account that has everything; and a control key outside the request's state.
        $now = time();
        $seedStore = new RedisFullCapabilityStore($this->host('resp', 'seed-261'), self::NAMESPACE);
        if (($case['seed'] ?? true) === true) {
            $this->operations($seedStore, $now);
        }
        if (($case['plan'] ?? '') === 'past-expiry') {
            // The key the request will leave expired, present beforehand so that its removal is visible from outside Lua.
            $seedStore->setAt('past-a', 1, 600, $now);
        }
        $this->admin->execute(['SET', self::CONTROL_KEY, 'control-value', 'PX', 3_600_000]);

        $proxyPort = null;
        if (($case['proxy'] ?? false) === true) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __DIR__ . '/../../Support/Redis/reply-dropping-proxy.php', $this->host, (string) $this->port, 'drop-commit'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $this->processes[] = $process;
            $output = self::pipeOf($pipes, 1);
            self::assertIsResource($output);
            stream_set_timeout($output, 5);
            $proxyPort = (int) fgets($output);
            self::assertGreaterThan(0, $proxyPort);
        }
        $inner = $this->host($case['shape'], $subject, $proxyPort);
        $address = $this->addressOf($subject);

        $log = new class ($inner) implements RedisCommandExecutorInterface {
            /** @var list<array{string, string}> */
            public array $entries = [];

            /** @var array<string, \Closure> a one-shot action before the first command of a kind (`verify`, `commit`) */
            public array $before = [];

            public function __construct(private readonly RedisCommandExecutorInterface $inner) {}

            public function execute(array $command): mixed
            {
                $kind = self::kind($command);
                if (isset($this->before[$kind])) {
                    $action = $this->before[$kind];
                    unset($this->before[$kind]);
                    $action();
                }
                try {
                    $reply = $this->inner->execute($command);
                    $this->entries[] = [$kind, $kind === 'commit' ? 'ok:' . json_encode($reply) : 'ok'];

                    return $reply;
                } catch (\Throwable $exception) {
                    $count = is_int($command[2] ?? null) ? $command[2] : 0;
                    $declared = array_map(static fn(mixed $key): string => (string) $key, array_slice($command, 3, $count));
                    $this->entries[] = [$kind, $exception::class . ': ' . substr(str_replace("\n", ' ', $exception->getMessage()), 0, 150) . ($kind === 'eval' || $kind === 'step' || $kind === 'commit' ? ' [keys: ' . implode(', ', array_map(static fn(string $key): string => preg_replace('/[0-9a-f]{64}/', '#', $key) ?? $key, $declared)) . ']' : '')];

                    throw $exception;
                }
            }

            /** @param non-empty-list<int|string|float> $command */
            private static function kind(array $command): string
            {
                if ($command[0] !== 'EVAL') {
                    return strtolower((string) $command[0]);
                }
                if (OverlayWire::isCommit($command)) {
                    return 'commit';
                }
                $text = is_string($command[1] ?? null) ? $command[1] : '';

                return str_contains($text, 'request commit') ? 'verify' : (str_contains($text, '__ov_result') ? 'step' : 'eval');
            }
        };

        $executor = $log;
        if (($case['hook'] ?? '') === 'outage') {
            // The positive control of the fallback metric: a real backend outage (every request script fails with the Host's BackendFailureException).
            $working = $executor;
            $executor = new class ($working) implements RedisCommandExecutorInterface {
                public function __construct(private readonly RedisCommandExecutorInterface $inner) {}

                public function execute(array $command): mixed
                {
                    if (OverlayWire::isScript($command, '__ov_result')) {
                        throw new BackendFailureException('Test outage.');
                    }

                    return $this->inner->execute($command);
                }
            };
        }
        $store = new RedisFullCapabilityStore($executor, self::NAMESPACE);

        $hook = $case['hook'] ?? '';
        $writerSnapshot = null;
        $names = $this->names();
        $scoreKey = $this->keyOf('score', $names['score']);
        if ($hook === 'revoke-del') {
            $log->before['commit'] = fn() => $this->admin->execute(['ACL', 'SETUSER', $subject, '-del']);
        } elseif ($hook === 'conflict-before-commit') {
            // A competing writer changes the expiry of a key the request read, just before its FIRST save, and the keyspace right after the writer is
            // kept: whatever the two attempts do afterwards must leave exactly that keyspace.
            $log->before['commit'] = function () use ($scoreKey, &$writerSnapshot): void {
                $this->admin->execute(['PEXPIRE', $scoreKey, 777_000]);
                $writerSnapshot = $this->keyspace();
            };
        } elseif ($hook === 'revoke-hset') {
            $log->before['commit'] = fn() => $this->admin->execute(['ACL', 'SETUSER', $subject, '-hset']);
        } elseif ($hook === 'revoke-hgetall-before-commit') {
            $log->before['commit'] = fn() => $this->admin->execute(['ACL', 'SETUSER', $subject, '-hgetall']);
        } elseif ($hook === 'revoke-hgetall-before-verify') {
            $log->before['verify'] = fn() => $this->admin->execute(['ACL', 'SETUSER', $subject, '-hgetall']);
        } elseif ($hook === 'kill') {
            $log->before['commit'] = fn() => $this->admin->execute(['CLIENT', 'KILL', 'USER', $subject]);
        }

        $before = $this->keyspace();
        $this->markMonitor();
        // The suite's RESP client emits an E_NOTICE for a write on a socket the server closed: expected in the cases that close it.
        $transport = in_array($hook, ['kill'], true) || ($case['proxy'] ?? false) === true;
        if ($transport) {
            set_error_handler(static fn(int $number, string $message): bool => $number === E_NOTICE && str_contains($message, 'Broken pipe'));
        }
        $fallbackBefore = self::fallbackSnapshot();
        $evaluations = 0;
        $stepsReached = 0;
        $outcome = null;
        $thrown = null;
        /** @var \ArrayObject<int, string> $signals */
        $signals = new \ArrayObject();
        $engine = $case['engine'] ?? false;
        try {
            if ($engine === true) {
                $emitter = new class ($signals) implements FailureSignalEmitterInterface {
                    /** @param \ArrayObject<int, string> $signals */
                    public function __construct(private readonly \ArrayObject $signals) {}

                    public function emit(FailureSignalDTO $signal): void
                    {
                        $this->signals->append($signal->type);
                    }
                };
                // One request, or four in a row for the outage control (three failures open the circuit, the fourth is answered by the local fallback).
                for ($round = 0; $round < (($case['hook'] ?? '') === 'outage' ? 4 : 1); $round++) {
                    $limiter = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('test_secret', 'test_fp', 'prod'), $store, $emitter)
                        ->withClock(new FixedClock('@' . (1_000_000 + $round)))->build();
                    $result = $limiter->limit(new RateLimitContextDTO(ip: '10.61.0.' . ($round + 1), ua: 'acl-261', accountId: 'acl-261-account-' . $round), RateLimitCommand::checkOnly('login_protection'));
                    $outcome = $result->decision . '/' . $result->failureMode;
                }
            } else {
                $outcome = $store->transactRequest(function () use ($store, $now, &$evaluations, &$stepsReached, $hook, $scoreKey, $case): array {
                    $evaluations++;
                    $plan = $case['plan'] ?? '';
                    if ($plan === 'no-expiry') {
                        // A plan whose only entry is a string written without an expiry (a circuit-breaker state): no PEXPIREAT in the commit.
                        $store->save('policy-261', new CircuitBreakerStateDTO('CLOSED', [], 0, 0, 0, []));
                        $stepsReached = 1;

                        return [];
                    }
                    if ($plan === 'past-expiry') {
                        // A key whose one-second lifetime has run out by the time the request is saved: the commit deletes it (no PEXPIREAT).
                        $store->setAt('past-a', 9, 1, $now);
                        usleep(1_300_000);
                        $stepsReached = 1;

                        return [];
                    }
                    if ($plan === 'batches') {
                        // One set of 401 members: the save writes it in three SADD batches (200, 200, 1).
                        for ($member = 0; $member < 401; $member++) {
                            $store->addDistinctAt('batch-a', 'member-' . $member, 600, $now);
                        }
                        $stepsReached = 401;

                        return [];
                    }
                    if ($plan === 'read-only') {
                        // A request that changes nothing: it sends no commit at all.
                        $store->get('score-a');
                        $stepsReached = 1;

                        return [];
                    }

                    return $this->operations($store, $now, function (int $step) use (&$stepsReached, $hook, $evaluations, $scoreKey): void {
                        $stepsReached = max($stepsReached, $step);
                        if ($hook === 'conflict' && $evaluations === 1 && $step === 1) {
                            // A concurrent writer: the expiry of a key this request has read moves (a conflict, not an ACL matter).
                            $this->admin->execute(['PEXPIRE', $scoreKey, 777_000]);
                        }
                    }, 400);
                });
            }
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }
        if ($transport) {
            restore_error_handler();
        }
        $this->admin->execute(['ECHO', 'monitor-marker-end']);
        $window = $this->drainMonitor('monitor-marker-end');
        $after = $this->keyspace();
        $summary = $this->monitorSummary($window, $address);

        $record = [
            'case' => $name,
            'shape' => $case['shape'],
            'sentCommit' => in_array('commit', array_column($log->entries, 0), true),
            'evaluations' => $evaluations,
            'stepsReached' => $stepsReached,
            'wire' => array_map(static fn(array $entry): string => $entry[0] . ' -> ' . $entry[1], $log->entries),
            'monitor' => $summary,
            'monitorLines' => count($window),
            'monitorSample' => array_map(static fn(string $line): string => substr($line, 0, 100), array_slice($window, 0, 6)),
            'address' => $address,
            'aclLog' => $this->aclLog($subject),
            'result' => $thrown === null ? 'returned ' . json_encode($outcome) : 'thrown ' . $thrown::class . ': ' . substr(str_replace("\n", ' ', $thrown->getMessage()), 0, 170),
            'thrown' => $thrown === null ? null : $thrown::class,
            'signals' => array_values($signals->getArrayCopy()),
            'changed' => $this->diff($before, $after),
            'fallbackCharged' => self::fallbackSnapshot() !== $fallbackBefore,
            'writerSnapshotEqualsFinal' => $writerSnapshot === null ? null : $writerSnapshot === $after,
            'controlUnchanged' => ($before[self::CONTROL_KEY] ?? null) === ($after[self::CONTROL_KEY] ?? null),
            'types' => array_map(static fn(array $entry): string => $entry['type'], $this->typesByFamily($before)),
            'keysBefore' => count($before),
            'keysAfter' => count($after),
            'redis' => $this->redisVersion(),
        ];
        $path = getenv('ACL_261_EVIDENCE');
        if (is_string($path) && $path !== '') {
            // The evidence file is written only if it is not a symbolic link (the runner refuses links; this is the test's own guard).
            self::assertFalse(is_link($path), 'ACL_261_EVIDENCE must not be a symbolic link.');
            file_put_contents($path, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
        }

        return $record;
    }

    /**
     * @param array<string, array{type: string, dump: string, expires: string}> $state
     * @return array<string, array{type: string, dump: string, expires: string}>
     */
    private function typesByFamily(array $state): array
    {
        $byFamily = [];
        foreach ($state as $key => $entry) {
            $byFamily[$this->family($key)] = $entry;
        }

        return $byFamily;
    }

    private function redisVersion(): string
    {
        preg_match('/redis_version:(\S+)/', self::text($this->admin->execute(['INFO', 'server'])), $match);

        return $match[1] ?? '';
    }

    private function keyOf(string $family, string $logical): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':' . $family . ':' . hash('sha256', $logical);
    }

    // ------------------------------------------------------------------ cases

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function cases(): array
    {
        $cases = [];
        foreach (['resp', 'phpredis'] as $shape) {
            $cases["$shape control"] = ['control', ['shape' => $shape]];
            foreach (self::REQUEST_COMMANDS as $command) {
                $cases["$shape deny -$command"] = ["deny -$command", ['shape' => $shape, 'denied' => [$command]]];
            }
            $cases["$shape keys narrow (score family only)"] = ['keys narrow', ['shape' => $shape, 'keys' => ['~maatify:rate-limiter:v1:*:score:*']]];
            $cases["$shape keys read-only"] = ['keys read-only', ['shape' => $shape, 'keys' => ['%R~' . self::SCOPE]]];
            $cases["$shape keys write-only"] = ['keys write-only', ['shape' => $shape, 'keys' => ['%W~' . self::SCOPE]]];
            $cases["$shape conflict"] = ['conflict', ['shape' => $shape, 'hook' => 'conflict']];
            $cases["$shape conflict + deny -smembers"] = ['conflict + deny -smembers', ['shape' => $shape, 'hook' => 'conflict', 'denied' => ['smembers']]];
            $cases["$shape fresh keyspace + deny every read of a key"] = ['fresh keyspace + deny reads', ['shape' => $shape, 'seed' => false, 'denied' => ['type', 'pexpiretime', 'get', 'hgetall', 'smembers', 'zrange']]];
            $cases["$shape revoke -hgetall before the verify that follows a step error"] = ['revoke -hgetall before verify', ['shape' => $shape, 'denied' => ['get'], 'hook' => 'revoke-hgetall-before-verify']];
            $cases["$shape revoke -hgetall before the commit comparison"] = ['revoke -hgetall before commit', ['shape' => $shape, 'hook' => 'revoke-hgetall-before-commit']];
            $cases["$shape plan past expiry (control)"] = ['plan past expiry', ['shape' => $shape, 'seed' => false, 'plan' => 'past-expiry']];
            $cases["$shape plan past expiry + deny -pexpireat"] = ['plan past expiry + deny -pexpireat', ['shape' => $shape, 'seed' => false, 'plan' => 'past-expiry', 'denied' => ['pexpireat']]];
            $cases["$shape plan past expiry + deny -del"] = ['plan past expiry + deny -del', ['shape' => $shape, 'seed' => false, 'plan' => 'past-expiry', 'denied' => ['del']]];
            $cases["$shape plan batches (control)"] = ['plan batches', ['shape' => $shape, 'seed' => false, 'plan' => 'batches']];
            $cases["$shape plan batches + deny -sadd"] = ['plan batches + deny -sadd', ['shape' => $shape, 'seed' => false, 'plan' => 'batches', 'denied' => ['sadd']]];
            $cases["$shape plan read-only + deny every write"] = ['plan read-only + deny writes', ['shape' => $shape, 'plan' => 'read-only', 'denied' => ['del', 'set', 'hset', 'sadd', 'zadd', 'pexpireat']]];
            $cases["$shape conflict at the first save, then deny -hset"] = ['conflict then deny -hset', ['shape' => $shape, 'hook' => 'conflict-before-commit', 'denied' => ['hset']]];
            $cases["$shape revoke -hset before the commit"] = ['revoke -hset before commit', ['shape' => $shape, 'hook' => 'revoke-hset']];
            $cases["$shape plan without expiry (control)"] = ['plan without expiry', ['shape' => $shape, 'seed' => false, 'plan' => 'no-expiry']];
            $cases["$shape plan without expiry + deny -pexpireat"] = ['plan without expiry + deny -pexpireat', ['shape' => $shape, 'seed' => false, 'plan' => 'no-expiry', 'denied' => ['pexpireat']]];
            $cases["$shape revoke -del before the commit"] = ['revoke -del before commit', ['shape' => $shape, 'hook' => 'revoke-del']];
            $cases["$shape connection killed before the commit"] = ['connection killed before commit', ['shape' => $shape, 'hook' => 'kill']];
            $cases["$shape reply lost after the save"] = ['reply lost after the save', ['shape' => $shape, 'proxy' => true]];
            // The engine's scripts declare empty-string placeholder keys: a prefix pattern alone does not admit them, `~` (the empty pattern) does.
            $engineKeys = ['~' . self::SCOPE, '~maatify:rate-limiter:e1:*', '~'];
            $cases["$shape engine outage (positive control of the fallback metric)"] = ['engine outage', ['shape' => $shape, 'engine' => true, 'keys' => $engineKeys, 'hook' => 'outage']];
            $cases["$shape engine control (prefix pattern only)"] = ['engine control (prefix pattern only)', ['shape' => $shape, 'engine' => true]];
            $cases["$shape engine control"] = ['engine control', ['shape' => $shape, 'engine' => true, 'keys' => $engineKeys]];
            $cases["$shape engine deny -pexpiretime"] = ['engine deny -pexpiretime', ['shape' => $shape, 'engine' => true, 'keys' => $engineKeys, 'denied' => ['pexpiretime']]];
            $cases["$shape engine deny -pexpireat"] = ['engine deny -pexpireat', ['shape' => $shape, 'engine' => true, 'keys' => $engineKeys, 'denied' => ['pexpireat']]];
            $cases["$shape engine connection killed before the commit"] = ['engine connection killed before commit', ['shape' => $shape, 'engine' => true, 'keys' => $engineKeys, 'hook' => 'kill']];
            $cases["$shape engine reply lost after the save"] = ['engine reply lost after the save', ['shape' => $shape, 'engine' => true, 'keys' => $engineKeys, 'proxy' => true]];
        }

        return $cases;
    }

    /**
     * What the shipped code was observed to do (Redis 7.0.15, both Host shapes, PHP_INT_SIZE 4 and 8), one entry per case; `Name|shape` where the
     * two Host shapes differ. `kind` is the provenance of the outcome: `returned` (the request's answer), `script` (an ACL denial of a command
     * inside a script), `toplevel-command` and `toplevel-key` (an ACL denial of the EVAL itself or of a key it declares), `preflight` (the study
     * commit's own ACL refusal), `transport` (a lost connection, with positive evidence), `unclassified` (a lost reply that ext-redis reports as a protocol or socket error on a connection it still considers open). `changed` is the family:change of every key whose DUMP or PEXPIRETIME
     * differs before and after, read from outside Lua.
     *
     * @return array<string, array{sent: bool, evaluations: int|null, steps: int|null, kind: string, changed: list<string>, engine: string|null, fallback: bool, signals: list<string>}>
     */
    private static function expectations(): array
    {
        return [
            'conflict' => ['sent' => true, 'evaluations' => 2, 'steps' => 7, 'kind' => 'returned', 'changed' => ['block:content+expiry', 'budget:content', 'cycle:content', 'distinct:content', 'distinct:content', 'score:content+expiry', 'watch:content'], 'engine' => null, 'fallback' => false, 'signals' => []],
            'conflict + deny -smembers' => ['sent' => false, 'evaluations' => 2, 'steps' => 3, 'kind' => 'script', 'changed' => ['score:expiry'], 'engine' => null, 'fallback' => false, 'signals' => []],
            'conflict then deny -hset' => ['sent' => true, 'evaluations' => 2, 'steps' => 7, 'kind' => 'preflight', 'changed' => ['score:expiry'], 'engine' => null, 'fallback' => false, 'signals' => []],
            'connection killed before commit|resp' => ['sent' => true, 'evaluations' => 1, 'steps' => 7, 'kind' => 'transport', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'connection killed before commit|phpredis' => ['sent' => true, 'evaluations' => 1, 'steps' => 7, 'kind' => 'returned', 'changed' => ['block:content+expiry', 'budget:content', 'cycle:content', 'distinct:content', 'distinct:content', 'score:content', 'watch:content'], 'engine' => null, 'fallback' => false, 'signals' => []],
            'control' => ['sent' => true, 'evaluations' => 1, 'steps' => 7, 'kind' => 'returned', 'changed' => ['block:content+expiry', 'budget:content', 'cycle:content', 'distinct:content', 'distinct:content', 'score:content', 'watch:content'], 'engine' => null, 'fallback' => false, 'signals' => []],
            'deny -del' => ['sent' => true, 'evaluations' => 1, 'steps' => 7, 'kind' => 'preflight', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'deny -eval' => ['sent' => false, 'evaluations' => 1, 'steps' => 0, 'kind' => 'toplevel-command', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'deny -exists' => ['sent' => false, 'evaluations' => 1, 'steps' => 0, 'kind' => 'script', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'deny -get' => ['sent' => false, 'evaluations' => 1, 'steps' => 4, 'kind' => 'script', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'deny -hgetall' => ['sent' => false, 'evaluations' => 1, 'steps' => 0, 'kind' => 'script', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'deny -hset' => ['sent' => true, 'evaluations' => 1, 'steps' => 7, 'kind' => 'preflight', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'deny -pexpireat' => ['sent' => true, 'evaluations' => 1, 'steps' => 7, 'kind' => 'preflight', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'deny -pexpiretime' => ['sent' => false, 'evaluations' => 1, 'steps' => 0, 'kind' => 'script', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'deny -sadd' => ['sent' => true, 'evaluations' => 1, 'steps' => 7, 'kind' => 'preflight', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'deny -set' => ['sent' => true, 'evaluations' => 1, 'steps' => 7, 'kind' => 'preflight', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'deny -smembers' => ['sent' => false, 'evaluations' => 1, 'steps' => 3, 'kind' => 'script', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'deny -time' => ['sent' => false, 'evaluations' => 1, 'steps' => 0, 'kind' => 'script', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'deny -type' => ['sent' => false, 'evaluations' => 1, 'steps' => 0, 'kind' => 'script', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'deny -zadd' => ['sent' => true, 'evaluations' => 1, 'steps' => 7, 'kind' => 'preflight', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'deny -zrange' => ['sent' => false, 'evaluations' => 1, 'steps' => 6, 'kind' => 'script', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'engine connection killed before commit|resp' => ['sent' => true, 'evaluations' => null, 'steps' => null, 'kind' => 'returned', 'changed' => [], 'engine' => 'HARD_BLOCK/FAIL_CLOSED', 'fallback' => false, 'signals' => []],
            'engine connection killed before commit|phpredis' => ['sent' => true, 'evaluations' => null, 'steps' => null, 'kind' => 'returned', 'changed' => ['distinct-meta:created', 'distinct-meta:created', 'distinct-meta:created', 'distinct-meta:created', 'distinct-meta:created', 'distinct-meta:created', 'distinct:created', 'distinct:created', 'distinct:created', 'distinct:created', 'distinct:created', 'distinct:created'], 'engine' => 'ALLOW/NORMAL', 'fallback' => false, 'signals' => []],
            'engine control' => ['sent' => true, 'evaluations' => null, 'steps' => null, 'kind' => 'returned', 'changed' => ['distinct-meta:created', 'distinct-meta:created', 'distinct-meta:created', 'distinct-meta:created', 'distinct-meta:created', 'distinct-meta:created', 'distinct:created', 'distinct:created', 'distinct:created', 'distinct:created', 'distinct:created', 'distinct:created'], 'engine' => 'ALLOW/NORMAL', 'fallback' => false, 'signals' => []],
            'engine control (prefix pattern only)' => ['sent' => false, 'evaluations' => null, 'steps' => null, 'kind' => 'toplevel-key', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'engine deny -pexpireat' => ['sent' => true, 'evaluations' => null, 'steps' => null, 'kind' => 'preflight', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'engine deny -pexpiretime' => ['sent' => true, 'evaluations' => null, 'steps' => null, 'kind' => 'returned', 'changed' => ['distinct-meta:created', 'distinct-meta:created', 'distinct-meta:created', 'distinct-meta:created', 'distinct-meta:created', 'distinct-meta:created', 'distinct:created', 'distinct:created', 'distinct:created', 'distinct:created', 'distinct:created', 'distinct:created'], 'engine' => 'ALLOW/NORMAL', 'fallback' => false, 'signals' => []],
            'engine outage' => ['sent' => false, 'evaluations' => null, 'steps' => null, 'kind' => 'returned', 'changed' => ['circuit:created (WITHOUT EXPIRY)'], 'engine' => 'ALLOW/DEGRADED_MODE', 'fallback' => true, 'signals' => ['CB_OPENED']],
            'engine reply lost after the save|resp' => ['sent' => true, 'evaluations' => null, 'steps' => null, 'kind' => 'returned', 'changed' => ['distinct-meta:created', 'distinct-meta:created', 'distinct-meta:created', 'distinct-meta:created', 'distinct-meta:created', 'distinct-meta:created', 'distinct:created', 'distinct:created', 'distinct:created', 'distinct:created', 'distinct:created', 'distinct:created'], 'engine' => 'HARD_BLOCK/FAIL_CLOSED', 'fallback' => false, 'signals' => []],
            'engine reply lost after the save|phpredis' => ['sent' => true, 'evaluations' => null, 'steps' => null, 'kind' => 'unclassified', 'changed' => ['distinct-meta:created', 'distinct-meta:created', 'distinct-meta:created', 'distinct-meta:created', 'distinct-meta:created', 'distinct-meta:created', 'distinct:created', 'distinct:created', 'distinct:created', 'distinct:created', 'distinct:created', 'distinct:created'], 'engine' => null, 'fallback' => false, 'signals' => []],
            'fresh keyspace + deny reads' => ['sent' => true, 'evaluations' => 1, 'steps' => 7, 'kind' => 'returned', 'changed' => ['block:created', 'block:created', 'budget:created', 'cycle:created', 'distinct-meta:created', 'distinct-meta:created', 'distinct:created', 'distinct:created', 'score:created', 'watch-meta:created', 'watch:created'], 'engine' => null, 'fallback' => false, 'signals' => []],
            'keys narrow' => ['sent' => false, 'evaluations' => 1, 'steps' => 1, 'kind' => 'toplevel-key', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'keys read-only' => ['sent' => false, 'evaluations' => 1, 'steps' => 0, 'kind' => 'toplevel-key', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'keys write-only' => ['sent' => false, 'evaluations' => 1, 'steps' => 0, 'kind' => 'toplevel-key', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'plan batches' => ['sent' => true, 'evaluations' => 1, 'steps' => 401, 'kind' => 'returned', 'changed' => ['distinct-meta:created', 'distinct:created'], 'engine' => null, 'fallback' => false, 'signals' => []],
            'plan batches + deny -sadd' => ['sent' => true, 'evaluations' => 1, 'steps' => 401, 'kind' => 'preflight', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'plan past expiry' => ['sent' => true, 'evaluations' => 1, 'steps' => 1, 'kind' => 'returned', 'changed' => ['score:DELETED'], 'engine' => null, 'fallback' => false, 'signals' => []],
            'plan past expiry + deny -del' => ['sent' => true, 'evaluations' => 1, 'steps' => 1, 'kind' => 'preflight', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'plan past expiry + deny -pexpireat' => ['sent' => true, 'evaluations' => 1, 'steps' => 1, 'kind' => 'returned', 'changed' => ['score:DELETED'], 'engine' => null, 'fallback' => false, 'signals' => []],
            'plan read-only + deny writes' => ['sent' => false, 'evaluations' => 1, 'steps' => 1, 'kind' => 'returned', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'plan without expiry' => ['sent' => true, 'evaluations' => 1, 'steps' => 1, 'kind' => 'returned', 'changed' => ['circuit:created (WITHOUT EXPIRY)'], 'engine' => null, 'fallback' => false, 'signals' => []],
            'plan without expiry + deny -pexpireat' => ['sent' => true, 'evaluations' => 1, 'steps' => 1, 'kind' => 'returned', 'changed' => ['circuit:created (WITHOUT EXPIRY)'], 'engine' => null, 'fallback' => false, 'signals' => []],
            'reply lost after the save|resp' => ['sent' => true, 'evaluations' => 1, 'steps' => 7, 'kind' => 'transport', 'changed' => ['block:content+expiry', 'budget:content', 'cycle:content', 'distinct:content', 'distinct:content', 'score:content', 'watch:content'], 'engine' => null, 'fallback' => false, 'signals' => []],
            'reply lost after the save|phpredis' => ['sent' => true, 'evaluations' => 1, 'steps' => 7, 'kind' => 'unclassified', 'changed' => ['block:content+expiry', 'budget:content', 'cycle:content', 'distinct:content', 'distinct:content', 'score:content', 'watch:content'], 'engine' => null, 'fallback' => false, 'signals' => []],
            'revoke -del before commit' => ['sent' => true, 'evaluations' => 1, 'steps' => 7, 'kind' => 'preflight', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'revoke -hgetall before commit' => ['sent' => true, 'evaluations' => 1, 'steps' => 7, 'kind' => 'script', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'revoke -hgetall before verify' => ['sent' => false, 'evaluations' => 1, 'steps' => 4, 'kind' => 'script', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
            'revoke -hset before commit' => ['sent' => true, 'evaluations' => 1, 'steps' => 7, 'kind' => 'preflight', 'changed' => [], 'engine' => null, 'fallback' => false, 'signals' => []],
        ];
    }

    /**
     * The phpredis Host shape's classification controls (F269-02): a server error reply that is not in anyone's list of known codes, with a code or
     * without one, reaches the store as the server's error, never as a transport failure; a connection lost for real (the peer is gone), which
     * ext-redis reports with its connection flag cleared, is the typed transport failure.
     */
    public function testThePhpRedisShapeKeepsAnUnknownServerErrorAServerErrorAndTypesALostConnection(): void
    {
        $this->createUser('shape-261', []);
        $this->admin->execute(['ACL', 'SETUSER', 'shape-261', '+eval', '+ping']);
        $executor = $this->host('phpredis', 'shape-261');
        foreach (['XCUSTOM something unknown', 'plain text without an error code'] as $text) {
            try {
                $executor->execute(['EVAL', "return redis.error_reply('" . $text . "')", 0]);
                self::fail('The script was expected to answer with an error reply.');
            } catch (\RedisException $exception) {
                self::assertSame($text, $exception->getMessage());
            }
        }

        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__ . '/../../Support/Redis/reply-dropping-proxy.php', $this->host, (string) $this->port], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $this->processes[] = $process;
        $output = self::pipeOf($pipes, 1);
        self::assertIsResource($output);
        stream_set_timeout($output, 5);
        $viaProxy = $this->host('phpredis', 'shape-261', (int) fgets($output));
        self::assertNotFalse($viaProxy->execute(['PING']), 'The connection works before the peer disappears.');
        proc_terminate($process);
        proc_close($process);
        $this->processes = [];
        usleep(200_000);
        $this->expectException(BackendFailureException::class);
        $viaProxy->execute(['PING']);
    }

    /**
     * A save by an account with the right permissions writes the exact data and the exact lifetimes, for the three expiry branches and for a
     * save derived into several batches (the success half of the contract, read from outside Lua). The expected values are taken from the plan the
     * evaluation handed to the save (the payload of the commit command as the executor saw it), and compared **exactly**: the absolute expiry as
     * the decimal `PEXPIRETIME` reply, with no margin, and the members of the batched set as a list, not a count (F271-01).
     */
    public function testASuccessfulSaveWritesTheDataAndTheLifetimes(): void
    {
        $this->createUser('writer-261', [], ['~' . self::SCOPE]);
        $capture = new class ($this->host('resp', 'writer-261')) implements RedisCommandExecutorInterface {
            /** @var list<array{string, string}> the key and the exact planned state of every entry of the last commit */
            public array $plan = [];

            public function __construct(private readonly RedisCommandExecutorInterface $inner) {}

            public function execute(array $command): mixed
            {
                if (OverlayWire::isCommit($command)) {
                    $count = is_int($command[2] ?? null) ? $command[2] : 0;
                    $payload = json_decode((string) ($command[3 + $count] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
                    $this->plan = [];
                    if (is_array($payload)) {
                        foreach ($payload as $pair) {
                            if (is_array($pair) && is_string($pair[0] ?? null) && is_string($pair[1] ?? null)) {
                                $this->plan[] = [$pair[0], $pair[1]];
                            }
                        }
                    }
                }

                return $this->inner->execute($command);
            }
        };
        $store = new RedisFullCapabilityStore($capture, self::NAMESPACE);
        // The seeding account runs the store's direct scripts, which use more commands than the save does.
        $this->admin->execute(['ACL', 'SETUSER', 'seed-261', 'reset', 'on', '>' . self::PASSWORD, '~*', '+@all']);
        $this->users[] = 'seed-261';
        $seed = new RedisFullCapabilityStore($this->host('resp', 'seed-261'), self::NAMESPACE);
        $now = time();
        $seed->setAt('past-a', 1, 600, $now);

        $store->transactRequest(function () use ($store, $now): void {
            $store->incrementAt('future-a', 600, 1, $now);                                  // a future expiry
            $store->save('policy-261', new CircuitBreakerStateDTO('CLOSED', [], 0, 0, 0, [])); // no expiry
            $store->setAt('past-a', 9, 1, $now);                                            // an expiry that has passed by the save
            for ($member = 0; $member < 401; $member++) {                                    // three SADD batches
                $store->addDistinctAt('batch-a', 'member-' . $member, 600, $now);
            }
            usleep(1_300_000);
        });

        /** @var array<string, array<array-key, mixed>> $planned */
        $planned = [];
        foreach ($capture->plan as [$key, $json]) {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $planned[$key] = $decoded;
        }
        $redisNow = (array) $this->admin->execute(['TIME']);
        $redisNowMs = self::text($redisNow[0]) . str_pad(substr(self::text($redisNow[1]), 0, 3), 3, '0', STR_PAD_LEFT);

        // A future expiry: the exact absolute value the plan carried.
        $future = $this->keyOf('score', 'future-a');
        self::assertArrayHasKey($future, $planned);
        $plannedExpiry = self::text($planned[$future]['x'] ?? null);
        self::assertMatchesRegularExpression('/^[1-9][0-9]{12,}$/', $plannedExpiry, 'The plan carries an absolute expiry in milliseconds.');
        self::assertSame($plannedExpiry, self::text($this->admin->execute(['PEXPIRETIME', $future])), 'The future expiry is the planned absolute value, to the millisecond.');
        self::assertSame('1', self::text($this->admin->execute(['HGET', $future, 'value'])), 'The future-expiry key holds its value.');

        // No expiry: none.
        $circuit = $this->keyOf('circuit', 'policy-261');
        self::assertArrayHasKey($circuit, $planned);
        self::assertArrayNotHasKey('x', $planned[$circuit], 'The plan of the circuit state carries no expiry.');
        self::assertSame('-1', self::text($this->admin->execute(['PEXPIRETIME', $circuit])), 'The key saved without an expiry has none.');

        // A passed expiry: the plan carried an expiry already behind Redis time, and the key is deleted.
        $past = $this->keyOf('score', 'past-a');
        self::assertArrayHasKey($past, $planned);
        $pastExpiry = self::text($planned[$past]['x'] ?? null);
        self::assertTrue(strlen($pastExpiry) < strlen($redisNowMs) || (strlen($pastExpiry) === strlen($redisNowMs) && $pastExpiry < $redisNowMs), 'The planned expiry is behind Redis time.');
        self::assertSame('-2', self::text($this->admin->execute(['PEXPIRETIME', $past])), 'The key whose lifetime had passed is deleted.');

        // The batched set: the members, as a list, and the planned expiry.
        $batch = $this->keyOf('distinct', 'batch-a');
        self::assertArrayHasKey($batch, $planned);
        $expectedMembers = [];
        for ($member = 0; $member < 401; $member++) {
            $expectedMembers[] = 'member-' . $member;
        }
        sort($expectedMembers, SORT_STRING);
        $plannedMembers = array_map('strval', array_keys((array) $planned[$batch]['v']));
        sort($plannedMembers, SORT_STRING);
        self::assertSame($expectedMembers, $plannedMembers, 'The plan holds the 401 members.');
        $actualMembers = array_map(self::text(...), (array) $this->admin->execute(['SMEMBERS', $batch]));
        sort($actualMembers, SORT_STRING);
        self::assertSame($expectedMembers, $actualMembers, 'Every member of the batched save is written, as a list.');
        self::assertSame(self::text($planned[$batch]['x'] ?? null), self::text($this->admin->execute(['PEXPIRETIME', $batch])), 'The batched set has the planned absolute expiry, to the millisecond.');
    }

    /** The observer's own control: it reports a known change of content, of expiry, a deletion and a creation, and nothing for an untouched key. */
    public function testTheObserverReportsAKnownChangeAndNothingElse(): void
    {
        $this->admin->execute(['SET', self::CONTROL_KEY, 'one', 'PX', 3_600_000]);
        $this->admin->execute(['SET', 'other-261', 'x']);
        $before = $this->keyspace();
        self::assertSame([], $this->diff($before, $this->keyspace()), 'Unchanged state, unchanged report.');

        $this->admin->execute(['SET', self::CONTROL_KEY, 'two', 'KEEPTTL']);
        self::assertSame(['control ' . substr(sha1(self::CONTROL_KEY), 0, 6) => 'content'], $this->diff($before, $this->keyspace()));
        $this->admin->execute(['SET', self::CONTROL_KEY, 'one', 'PX', 3_600_000]);
        $before = $this->keyspace();
        $this->admin->execute(['PEXPIRE', self::CONTROL_KEY, 1_000_000]);
        self::assertSame(['control ' . substr(sha1(self::CONTROL_KEY), 0, 6) => 'expiry'], $this->diff($before, $this->keyspace()));
        $before = $this->keyspace();
        $this->admin->execute(['DEL', 'other-261']);
        $this->admin->execute(['SET', 'third-261', 'y']);
        $expected = ['other ' . substr(sha1('other-261'), 0, 6) => 'DELETED', 'other ' . substr(sha1('third-261'), 0, 6) => 'created (WITHOUT EXPIRY)'];
        ksort($expected);
        self::assertSame($expected, $this->diff($before, $this->keyspace()));
    }

    /**
     * A store call made directly, not inside `transactRequest()`, runs its own script, and that script applies the key's lifetime with
     * `redis.pcall('EXPIRE', ...)`. When the account may not run EXPIRE, #270 classifies the denial with `redis.acl_check_cmd` and answers
     * `NOPERM` naming the command (no key, no value); before #270 the denial was swallowed by that `pcall` and reported as
     * `expiry not representable`, the text of a genuine range refusal. The script removes what it had written either way (observed below);
     * Redis' ACL LOG still holds the denial.
     */
    public function testADirectScriptThatMayNotRunExpireAnswersNoperm(): void
    {
        $this->createUser('direct-261', ['expire']);
        $this->admin->execute(['ACL', 'SETUSER', 'direct-261', '+hincrby', '+hget', '+hset', '+incr', '+get', '+set', '+ttl', '+pttl', '+del', '+exists', '+type', '+hgetall', '+time']);
        $store = new RedisFullCapabilityStore($this->host('resp', 'direct-261'), self::NAMESPACE);
        $before = $this->keyspace();

        try {
            $store->incrementAt('direct-a', 600, 1, time());
            self::fail('The direct script was expected to refuse.');
        } catch (\Maatify\RateLimiter\Exception\RateLimiterException $exception) {
            self::assertStringStartsWith('NOPERM ', $exception->getMessage());
            self::assertStringContainsString("'expire'", $exception->getMessage());
            self::assertStringNotContainsString('direct-a', $exception->getMessage(), 'The refusal names the command, never the key.');
        }
        self::assertSame([], $this->diff($before, $this->keyspace()), 'The script removed what it had written.');
        $log = $this->aclLog('direct-261');
        self::assertSame([['command', 'lua', 'expire']], array_map(static fn(array $entry): array => [$entry['reason'], $entry['context'], $entry['object']], $log), 'Redis logged the denial of EXPIRE inside a script.');
    }

    /** Lossless JSON evidence for binary DUMP fields inside native wire replies. */
    private static function f11ReplyBytes(mixed $value): mixed
    {
        if (is_string($value)) {
            return ['hex' => bin2hex($value)];
        }
        return is_array($value) ? array_map(self::f11ReplyBytes(...), $value) : $value;
    }

    /** The exact deadline implied by a relative TTL at an independently observed Redis clock instant (no PHP millisecond int). */
    private function f11ExpiryDeadline(int $ttl): string
    {
        $clock = $this->admin->execute(['TIME']);
        self::assertIsArray($clock);
        $seconds = self::text($clock[0]);
        $micros = self::text($clock[1]);
        self::assertMatchesRegularExpression('/^[0-9]+$/', $seconds);
        self::assertMatchesRegularExpression('/^[0-9]+$/', $micros);
        return (string) ((int) $seconds + $ttl) . str_pad((string) intdiv((int) $micros, 1000), 3, '0', STR_PAD_LEFT);
    }

    private static function f11CompareDecimal(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    /** @return iterable<string, array{string, string, string, string, bool, string}> */
    public static function f11Cases(): iterable
    {
        $cases = [
            ['bounded', 'fresh', 'hset', 'error', false], ['bounded', 'fresh', 'expire', 'error', false],
            ['bounded', 'fresh', 'sadd', 'clean-error', false], ['bounded', 'fresh', 'del', 'success', false],
            ['bounded', 'reset', 'hset', 'error', false], ['bounded', 'reset', 'expire', 'error', false],
            ['bounded', 'reset', 'del', 'clean-error', false], ['bounded', 'reset', 'sadd', 'error', false],
            ['pair', 'fresh', 'hset', 'error', false], ['pair', 'fresh', 'late-key', 'error', false],
            ['pair', 'fresh', 'del', 'clean-error', false], ['pair', 'reset', 'hset', 'error', false],
            ['group', 'fresh', 'hset', 'error', false], ['group', 'fresh', 'set', 'error', false],
            ['group', 'fresh', 'del', 'error', false], ['group', 'fresh', 'expire', 'error', false],
            ['group', 'retained', 'set', 'error', false], ['group', 'reset', 'hset', 'error', false],
            ['bounded', 'retained', 'hset,expire,del', 'success', false], ['bounded', 'retained', 'sadd', 'clean-error', false],
            ['bounded', 'duplicate', 'all-writes', 'success', false], ['bounded', 'full', 'all-writes', 'success', false],
            ['bounded', 'fresh', '', 'success', false], ['bounded', 'reset', '', 'success', false],
            ['pair', 'fresh', '', 'success', false], ['pair', 'reset', '', 'success', false],
            ['group', 'fresh', '', 'success', false], ['group', 'reset', '', 'success', false],
            ['group', 'retained', 'hset,expire,del', 'success', false], ['group', 'fresh', 'hset', 'conflict', false],
            ['bounded', 'fresh', '', 'success', true], ['pair', 'fresh', '', 'success', true],
            ['group', 'fresh', 'expire', 'success', true], ['group', 'fresh', 'hset', 'error', true],
            ['pair', 'fresh', 'hset', 'error', true],
        ];
        foreach (['resp', 'phpredis'] as $shape) {
            foreach ($cases as [$mode, $branch, $denied, $expect, $overlay]) {
                yield "$shape/$mode/$branch/" . ($denied === '' ? 'allowed' : $denied) . '/' . ($overlay ? 'overlay' : 'direct') . "/$expect" => [$shape, $mode, $branch, $denied, $overlay, $expect];
            }
        }
    }

    /** F-G4-11: public direct observations with real restricted accounts; the existing oracle and MONITOR see every data/meta/control key. */
    #[DataProvider('f11Cases')]
    public function testF11BoundedObservationWritesArePreflightedAsOneActualPlan(string $shape, string $mode, string $branch, string $denied, bool $overlay, string $expect): void
    {
        $now = 1_000_000;
        $ttl = 600;
        $adminStore = new RedisFullCapabilityStore($this->admin, self::NAMESPACE);
        $this->admin->execute(['SET', self::CONTROL_KEY, 'f11-outside-control']);
        $adminStore->addDistinctBoundedWithSnapshotAt('f11-previous', 'previous-secret', $ttl, 2, $now);
        $seedTime = $branch === 'reset' ? $now - $ttl - 1 : $now;
        if ($branch !== 'fresh' || $mode === 'group') {
            $adminStore->addDistinctBoundedWithSnapshotAt('f11-a', $branch === 'duplicate' ? 'new-a' : 'old-a', $ttl, 2, $seedTime);
            if ($branch === 'full') {
                $adminStore->addDistinctBoundedWithSnapshotAt('f11-a', 'old-b', $ttl, 2, $seedTime);
            }
            if ($mode === 'pair') {
                $adminStore->addDistinctBoundedWithSnapshotAt('f11-b', 'old-b', $ttl, 2, $seedTime);
            }
            if ($mode === 'group' && $branch !== 'fresh') {
                $adminStore->incrementWatchFlagAt('f11-watch', $ttl, $seedTime);
            }
        }
        $subject = 'f11-' . $shape;
        $rules = ['~' . self::SCOPE];
        if ($denied === 'late-key') {
            $rules = ['%R~' . self::SCOPE];
            foreach ([['distinct', 'f11-a'], ['distinct-meta', 'f11-a'], ['distinct-meta', 'f11-b']] as [$family, $logical]) {
                $rules[] = '%W~' . $this->keyOf($family, $logical);
            }
        }
        $this->createUser($subject, [], $rules);
        if ($denied === 'late-key') {
            // EVAL may declare all read scopes; write permissions still come only from the restricted command selector.
            $this->admin->execute(['ACL', 'SETUSER', $subject, '(+eval ~' . self::SCOPE . ')']);
        }
        $this->admin->execute(['ACL', 'SETUSER', $subject, '+ttl', '+scard', '+sismember', '+hget', '+dump', '+expire']);
        $denials = $denied === 'all-writes' ? ['del', 'sadd', 'hset', 'expire', 'set', 'pexpireat']
            : ($denied === '' || $denied === 'late-key' ? [] : explode(',', $denied));
        foreach ($denials as $command) {
            $this->admin->execute(['ACL', 'SETUSER', $subject, '-' . $command]);
        }
        $host = $this->host($shape, $subject);
        $trace = new class ($host) implements RedisCommandExecutorInterface {
            /** @var list<array{kind: string, keys: list<string>, reply?: mixed, error?: string}> */
            public array $calls = [];
            public function __construct(private readonly RedisCommandExecutorInterface $inner) {}
            public function execute(array $command): mixed
            {
                $script = is_string($command[1] ?? null) ? $command[1] : '';
                $kind = OverlayWire::isScript($command, \Maatify\RateLimiter\Repository\Redis\RedisRequestOverlay::commitScript()) ? 'request-commit'
                    : (str_contains($script, 'local scopes = #KEYS / 2') ? 'observation-commit' : 'read-or-bounded');
                $entry = ['kind' => $kind, 'keys' => OverlayWire::keys($command)];
                try {
                    $reply = $this->inner->execute($command);
                    $entry['reply'] = $reply;
                    $this->calls[] = $entry;
                    return $reply;
                } catch (\Throwable $error) {
                    $entry['error'] = $error::class . ': ' . $error->getMessage();
                    $this->calls[] = $entry;
                    throw $error;
                }
            }
        };
        $store = new RedisFullCapabilityStore($trace, self::NAMESPACE);
        $groupEvaluations = 0;
        $outerEvaluations = 0;
        $reference = $this->keyspace();
        $before = $reference;
        $invoke = function () use ($store, $adminStore, $mode, $now, $ttl, $expect, &$groupEvaluations, &$reference): array {
            if ($mode === 'bounded') {
                return ['bounded' => get_object_vars($store->addDistinctBoundedWithSnapshotAt('f11-a', 'new-a', $ttl, 2, $now))];
            }
            if ($mode === 'pair') {
                return ['pair' => array_map(get_object_vars(...), $store->addDeviceCapPairAt('f11-a', 'new-a', 2, 'f11-b', 'new-b', 2, $ttl, $now))];
            }
            $evaluate = function () use ($store, $now, $ttl, &$groupEvaluations): array {
                $groupEvaluations++;
                [$bounded, $boundedPlan] = $store->readBoundedObservationPlanAt('f11-a', 'new-a', 2, $ttl, $now);
                [$watch, $watchPlan] = $store->readWatchObservationPlanAt('f11-watch', $ttl, $now);
                return [['bounded' => get_object_vars($bounded), 'watch' => $watch], [$boundedPlan, $watchPlan]];
            };
            if ($expect === 'conflict') {
                [, $plans] = $evaluate();
                $adminStore->addDistinctBoundedWithSnapshotAt('f11-a', 'rival-secret', $ttl, 2, $now);
                $reference = $this->keyspace();
                $this->markMonitor(); // Keep rival writes outside the subject commit's observation window.
                return ['committed' => $store->commitDeviceCapPlans($plans)];
            }
            return $store->commitBoundedObservationGroup($evaluate);
        };
        $firstPossibleExpiry = $this->f11ExpiryDeadline($ttl);
        $this->markMonitor();
        $fallbackBefore = self::fallbackSnapshot();
        $result = null;
        $caught = null;
        try {
            $result = $overlay ? $store->transactRequest(function () use ($invoke, &$outerEvaluations): array {
                $outerEvaluations++;
                return $invoke();
            }) : $invoke();
        } catch (\Throwable $error) {
            $caught = $error;
        }
        $lastPossibleExpiry = $this->f11ExpiryDeadline($ttl);
        $this->admin->execute(['ECHO', 'f11-end']);
        $window = $this->drainMonitor('f11-end');
        $writes = [];
        foreach ($window as $line) {
            if (preg_match('/\[\d+ lua\] "([A-Za-z]+)"/', $line, $match) === 1 && in_array(strtoupper($match[1]), self::WRITES, true)) {
                $writes[] = $line;
            }
        }
        $after = $this->keyspace();
        $actual = ['thrown' => $caught !== null, 'backend' => $caught instanceof BackendFailureException,
            'same' => $reference === $after, 'groupEvaluations' => $groupEvaluations, 'outerEvaluations' => $outerEvaluations,
            'requestCommits' => count(array_filter($trace->calls, static fn(array $call): bool => $call['kind'] === 'request-commit')),
            'observationCommits' => count(array_filter($trace->calls, static fn(array $call): bool => $call['kind'] === 'observation-commit')),
            'fallbackChanged' => self::fallbackSnapshot() !== $fallbackBefore];
        $record = ['actual' => $actual, 'result' => $result, 'errorClass' => $caught === null ? null : $caught::class,
            'message' => $caught?->getMessage(), 'wireBytes' => self::f11ReplyBytes($trace->calls), 'luaWrites' => $writes, 'aclLog' => $this->aclLog($subject),
            'expiryClockBounds' => [$firstPossibleExpiry, $lastPossibleExpiry],
            'before' => $before, 'referenceAfterRival' => $reference, 'after' => $after];
        $diagnostic = json_encode($record, JSON_THROW_ON_ERROR);
        fwrite(STDERR, "\nF-G4-11 $shape/$mode/$branch/" . ($denied === '' ? 'allowed' : $denied) . '/' . ($overlay ? 'overlay' : 'direct') . "/$expect diagnostic: $diagnostic\n");
        $isError = $expect === 'error' || $expect === 'clean-error';
        $noChange = $isError || $expect === 'conflict' || $branch === 'duplicate' || $branch === 'full';
        self::assertSame(['thrown' => $isError, 'backend' => false, 'same' => $noChange,
            'groupEvaluations' => $mode === 'group' ? 1 : 0, 'outerEvaluations' => $overlay ? 1 : 0,
            'requestCommits' => $overlay ? 1 : 0, 'observationCommits' => $mode === 'bounded' ? 0 : 1,
            'fallbackChanged' => false], $actual, $diagnostic);
        if ($isError) {
            self::assertNotNull($caught);
            foreach (['f11-a', 'f11-b', 'f11-watch', 'new-a', 'old-a', 'new-b', self::NAMESPACE] as $secret) {
                self::assertStringNotContainsString($secret, $caught->getMessage());
            }
            if ($expect === 'error') {
                self::assertMatchesRegularExpression('/^NOPERM (bounded observation|request commit):.*(?:plan step) [0-9]+/', $caught->getMessage());
            }
        }
        if ($isError || $expect === 'conflict' || $branch === 'duplicate' || $branch === 'full') {
            self::assertSame([], $writes, 'A refusal/conflict/no-op executes no write command, including no temporary write followed by cleanup.');
        }
        if ($expect === 'conflict') {
            self::assertSame(['committed' => false], $result);
        }
        if ($expect === 'success') {
            self::assertIsArray($result);
            $active = $branch === 'retained' || ($mode === 'group' && $branch === 'fresh');
            $expectedA = ['count' => $active ? 2 : 1, 'accepted' => true, 'added' => true,
                'members' => $active ? ['new-a', 'old-a'] : ['new-a'], 'expiresAt' => $now + $ttl];
            if ($branch === 'duplicate') {
                $expectedA = ['count' => 1, 'accepted' => true, 'added' => false, 'members' => ['new-a'], 'expiresAt' => $now + $ttl];
            } elseif ($branch === 'full') {
                $expectedA = ['count' => 2, 'accepted' => false, 'added' => false, 'members' => ['old-a', 'old-b'], 'expiresAt' => $now + $ttl];
            }
            $expected = $mode === 'pair' ? ['pair' => [$expectedA, ['count' => 1, 'accepted' => true, 'added' => true, 'members' => ['new-b'], 'expiresAt' => $now + $ttl]]]
                : ($mode === 'group' ? ['bounded' => $expectedA, 'watch' => $branch === 'retained' ? 2 : 1] : ['bounded' => $expectedA]);
            self::assertSame($expected, $result);
            $scopes = $mode === 'pair' ? [['distinct', 'distinct-meta', 'f11-a'], ['distinct', 'distinct-meta', 'f11-b']]
                : ($mode === 'group' ? [['distinct', 'distinct-meta', 'f11-a'], ['watch', 'watch-meta', 'f11-watch']] : [['distinct', 'distinct-meta', 'f11-a']]);
            foreach ($scopes as [$dataFamily, $metaFamily, $logical]) {
                $dataKey = $this->keyOf($dataFamily, $logical);
                $metaKey = $this->keyOf($metaFamily, $logical);
                self::assertSame((string) ($now + $ttl), self::text($this->admin->execute(['HGET', $metaKey, 'expiresAt'])));
                if ($branch === 'retained' || $branch === 'duplicate' || $branch === 'full' || ($mode === 'group' && $branch === 'fresh' && $dataFamily === 'distinct')) {
                    self::assertSame($before[$dataKey]['expires'], $after[$dataKey]['expires'], 'Retained physical lifetime is fixed.');
                    self::assertSame($before[$metaKey]['expires'], $after[$metaKey]['expires']);
                } else {
                    // Separate EXPIRE/SET EX commands may choose different milliseconds. Each exact deadline must
                    // belong to the independently measured Redis interval plus the requested TTL, without slack.
                    foreach ([$dataKey, $metaKey] as $key) {
                        self::assertGreaterThanOrEqual(0, self::f11CompareDecimal($after[$key]['expires'], $firstPossibleExpiry));
                        self::assertLessThanOrEqual(0, self::f11CompareDecimal($after[$key]['expires'], $lastPossibleExpiry));
                    }
                }
                if ($dataFamily === 'distinct') {
                    $members = (array) $this->admin->execute(['SMEMBERS', $dataKey]);
                    sort($members);
                    self::assertSame($logical === 'f11-b' ? ['new-b'] : $expectedA['members'], $members);
                } else {
                    self::assertSame($branch === 'retained' ? '2' : '1', self::text($this->admin->execute(['GET', $dataKey])));
                }
            }
        }
        foreach ([self::CONTROL_KEY, $this->keyOf('distinct', 'f11-previous'), $this->keyOf('distinct-meta', 'f11-previous')] as $control) {
            self::assertSame($before[$control], $after[$control]);
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('cases')]
    public function testCase(string $name, array $case): void
    {
        /** @var array{shape: string, seed?: bool, denied?: list<string>, keys?: list<string>, hook?: string, proxy?: bool, engine?: bool} $case */
        $record = $this->observe($name, $case);
        $shape = $case['shape'];
        $expected = self::expectations()[$name . '|' . $shape] ?? self::expectations()[$name] ?? null;
        self::assertNotNull($expected, 'No expectation for ' . $name);

        $changed = array_map(static fn(string $family, string $what): string => explode(' ', $family)[0] . ':' . $what, array_keys($record['changed']), array_values($record['changed']));
        sort($changed);
        $kind = $this->kindOf($record['result']);

        self::assertSame($expected['kind'], $kind, $name . ': provenance of the outcome: ' . $record['result']);
        self::assertSame($expected['sent'], $record['sentCommit'], $name . ': whether the request reached its save');
        if ($expected['evaluations'] !== null) {
            self::assertSame($expected['evaluations'], $record['evaluations'], $name . ': evaluations of the request');
            self::assertSame($expected['steps'], $record['stepsReached'], $name . ': steps completed by the last evaluation');
        }
        // A key that is rewritten also gets a new absolute expiry when the request and its seed straddle a second boundary, so `content` and
        // `content+expiry` are one change here; the exact expiry is asserted by testASuccessfulSaveWritesTheDataAndTheLifetimes, and `expiry` alone,
        // `(WITHOUT EXPIRY)`, `DELETED` and `created` stay distinct.
        $unify = static function (array $list): array {
            $unified = [];
            foreach ($list as $change) {
                $unified[] = str_replace(':content+expiry', ':content', self::text($change));
            }

            return $unified;
        };
        self::assertSame($unify($expected['changed']), $unify($changed), $name . ': keys that changed, read from outside Lua');
        self::assertTrue($record['controlUnchanged'], $name . ': the control key outside the request state is untouched');
        self::assertSame($expected['fallback'], $record['fallbackCharged'], $name . ': the local fallback limiter was charged');
        self::assertSame($expected['signals'], $record['signals'], $name . ': failure signals emitted');
        if ($expected['engine'] !== null) {
            self::assertSame('returned ' . json_encode($expected['engine']), $record['result']);
        }

        if ($name === 'conflict then deny -hset') {
            // A real conflict comes first: the first save answers 0 (nothing written, no plan checked), the request is evaluated again, and the
            // second save is refused with NOPERM over the current state; neither attempt wrote anything beyond the competing writer's change.
            $commits = array_values(array_filter($record['wire'], static fn(string $entry): bool => str_starts_with($entry, 'commit ->')));
            self::assertCount(2, $commits, 'Two saves reached Redis.');
            self::assertSame('commit -> ok:0', $commits[0], 'The first save answered a conflict (0).');
            self::assertStringContainsString('NOPERM request commit: the account may not run', $commits[1], 'The second save was refused by the plan check.');
            self::assertSame(2, $record['evaluations'], 'Evaluated again after the conflict, not after the refusal.');
            self::assertTrue($record['writerSnapshotEqualsFinal'], 'The final keyspace equals the keyspace right after the competing writer: no write from either attempt.');
        }

        // The error class the consumer receives: the Host's own for a server error reply, never a transport failure.
        $thrown = $record['thrown'];
        if (in_array($kind, ['script', 'toplevel-command', 'toplevel-key', 'preflight'], true)) {
            self::assertSame($shape === 'resp' ? \Maatify\RateLimiter\Exception\RateLimiterException::class : \RedisException::class, $thrown);
            self::assertNotSame(BackendFailureException::class, $thrown);
            if ($kind === 'preflight') {
                // The refusal names the command and its position in the plan, never a key or a value (DEC-042).
                self::assertMatchesRegularExpression('/^thrown [^:]+: NOPERM request commit: the account may not run [A-Z]+ \(plan step \d+ of \d+\)$/', $record['result']);
            }
        } elseif ($kind === 'transport') {
            self::assertSame(BackendFailureException::class, $thrown);
        } elseif ($kind === 'unclassified') {
            // ext-redis reported a protocol error or a socket read error (which of the two varies with timing and width) on a connection it still
            // considers open: no positive evidence, so no reclassification.
            self::assertSame(\RedisException::class, $thrown);
        } else {
            self::assertNull($thrown);
        }

        // Redis' own account of the denial (ACL LOG) agrees with the provenance; a transport failure and the study variant's refusal are not in it.
        $log = $record['aclLog'];
        $reasons = [];
        foreach ($log as $entry) {
            $reasons[] = [$entry['reason'] ?? '', $entry['context'] ?? '', $entry['object'] ?? ''];
        }
        match ($kind) {
            'script' => $name === 'revoke -hgetall before verify'
                // The step was refused (GET), then the verify that follows a step error was itself refused (HGETALL): its error is the one that
                // reaches the caller, in place of the step's.
                ? self::assertSame([['command', 'lua', 'get'], ['command', 'lua', 'hgetall']], $reasons)
                : self::assertSame([['command', 'lua']], array_map(static fn(array $entry): array => [$entry[0], $entry[1]], $reasons)),
            'toplevel-command' => self::assertSame([['command', 'toplevel']], array_map(static fn(array $entry): array => [$entry[0], $entry[1]], $reasons)),
            'toplevel-key' => self::assertSame([['key', 'toplevel']], array_map(static fn(array $entry): array => [$entry[0], $entry[1]], $reasons)),
            default => self::assertSame([], $log),
        };

        // The independent MONITOR connection saw the same EVALs from the account's connection as the executor sent, except where the connection
        // was lost, and except an EVAL that ACL refused before it ran (a rejected command is not fed to MONITOR).
        $monitor = $record['monitor'];
        $sentEvals = 0;
        foreach ($record['wire'] as $entry) {
            if (preg_match('/^(step|verify|commit|eval) ->/', $entry) === 1 && ! str_contains($entry, 'NOPERM this user has no permissions')) {
                $sentEvals++;
            }
        }
        if (! str_contains($name, 'killed') && ! str_contains($name, 'lost')) {
            self::assertSame($sentEvals, $monitor['evals'], $name . ': EVALs seen by MONITOR');
        }
    }

    private function kindOf(string $result): string
    {
        if (str_starts_with($result, 'returned')) {
            return 'returned';
        }
        $message = explode(': ', $result, 2)[1] ?? $result;

        return match (true) {
            str_starts_with($message, "ERR The user executing the script can't run") => 'script',
            str_starts_with($message, "NOPERM this user has no permissions to run the") => 'toplevel-command',
            str_starts_with($message, 'NOPERM this user has no permissions to access one') => 'toplevel-key',
            str_starts_with($message, 'NOPERM request commit:') => 'preflight',
            str_contains($result, 'BackendFailureException') => 'transport',
            str_starts_with($result, 'thrown RedisException: protocol error'), str_starts_with($result, 'thrown RedisException: socket error') => 'unclassified',
            default => 'OTHER ' . $result,
        };
    }
}
