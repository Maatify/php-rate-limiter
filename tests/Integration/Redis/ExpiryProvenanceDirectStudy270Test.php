<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\PhpRedisScriptExecutor;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #270 study (DEC-015, DEC-018, LT-P2-12): provenance of a failed expiry in DIRECT store calls (not `transactRequest()`).
 * STUDY ONLY: it characterises what the shipped code does and changes no behaviour. Every case has its own temporary ACL account;
 * the data is synthetic; the keyspace is read from outside Lua with DUMP and PEXPIRETIME. With `EXPIRY_270_EVIDENCE` set to a file
 * path each case appends one JSON line of what it observed.
 */
#[Group('redis-expiry-provenance-270')]
#[Group('php32')]
final class ExpiryProvenanceDirectStudy270Test extends TestCase
{
    private const PASSWORD = 'synthetic-study-password';

    private const NAMESPACE = 'exp-270';

    /** @var array<string, callable(RedisFullCapabilityStore, int): mixed> */
    private const SETUP = [
        'blockWithPunishmentLifecycleTrackingAt' => [self::class, 'seedScore'],
        'claimPostPunishmentReentryAtMilliseconds (needs a lifecycle block)' => [self::class, 'seedLifecycle'],
        'addDistinctBoundedAcrossRotationAtMilliseconds (previous generation present)' => [self::class, 'seedPrevious'],
    ];

    public static function seedScore(RedisFullCapabilityStore $s, int $n): mixed
    {
        return $s->mutateGenerationBoundScoreAtMilliseconds('cur', 'prev', null, 600, 5, $n . '000');
    }

    public static function seedLifecycle(RedisFullCapabilityStore $s, int $n): mixed
    {
        self::seedScore($s, $n);

        return $s->blockWithPunishmentLifecycleTrackingAt('cur', 'prev', 1, 'life-1', 2, 600, 3600, 3, 60, 7200, $n);
    }

    public static function seedPrevious(RedisFullCapabilityStore $s, int $n): mixed
    {
        return $s->addDistinctBoundedAt('prev', 'x', 600, 10, $n);
    }

    private RespRedisCommandExecutor $admin;

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    private string $host;

    private int $port;

    /** @var list<string> */
    private array $users = [];

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        if (! extension_loaded('redis')) {
            self::fail('ext-redis is required for the #270 study (the native Host shape).');
        }
        $this->host = $host;
        $this->port = (int) $port;
        $this->admin = new RespRedisCommandExecutor($host, (int) $port, RespRedisCommandExecutor::MODE_EVAL);
        $this->admin->execute(['FLUSHDB']);
        $this->admin->execute(['ACL', 'LOG', 'RESET']);
        self::assertMatchesRegularExpression('/redis_version:7\./', self::text($this->admin->execute(['INFO', 'server'])));
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            foreach ($this->users as $user) {
                $this->admin->execute(['ACL', 'DELUSER', $user]);
            }
            $this->admin->execute(['FLUSHDB']);
        }
    }

    /**
     * Direct public calls by script family. Each entry: label, the call (receives the store and the caller time), the command the
     * account lacks, and the logical keys the call touches (only for documentation).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function paths(): array
    {
        $cases = [];
        foreach (array_keys(self::calls()) as $label) {
            foreach (['expire', 'pexpire', 'pexpireat'] as $denied) {
                $cases["$label / -$denied"] = [$label, $denied];
            }
        }

        return $cases;
    }

    /** @return array<string, callable(RedisFullCapabilityStore, int): mixed> */
    private static function calls(): array
    {
        return [
            'incrementAt' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->incrementAt('a', 600, 1, $n),
            'incrementIfSnapshotAt' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->incrementIfSnapshotAt('a', 600, null, null, 5, $n),
            'replaceScoreAt' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->replaceScoreAt('a', 5, 600, $n),
            'setAt' => static function (RedisFullCapabilityStore $s, int $n): mixed {
                $s->setAt('a', 5, 600, $n);

                return null;
            },
            'blockAt' => static function (RedisFullCapabilityStore $s, int $n): mixed {
                $s->blockAt('a', 2, 600, $n);

                return null;
            },
            'incrementBudgetAt' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->incrementBudgetAt('a', 600, 1, $n),
            'addDistinctAt' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->addDistinctAt('a', 'i', 600, $n),
            'incrementWatchFlagAt' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->incrementWatchFlagAt('a', 600, $n),
            'addDistinctBoundedAt' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->addDistinctBoundedAt('a', 'i', 600, 10, $n),
            'addDistinctBoundedWithSnapshotAt' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->addDistinctBoundedWithSnapshotAt('a', 'i', 600, 10, $n),
            'acquireProbeLease' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->acquireProbeLease('p', $n, 60),
            'blockWithCycleTracking' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->blockWithCycleTracking('cur', 'prev', 2, 600, $n, 3600, 3, 60, 7200),
            'addDistinctAcrossRotationAt' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->addDistinctAcrossRotationAt('cur', 'bridge', 'prev', 'm', 'n', 600, $n),
            'incrementWatchFlagAcrossRotationAt' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->incrementWatchFlagAcrossRotationAt('cur', 'prev', 600, $n),
            'mutateGenerationBoundScoreAtMilliseconds' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->mutateGenerationBoundScoreAtMilliseconds('cur', 'prev', null, 600, 5, $n . '000'),
            'blockWithPunishmentLifecycleTrackingAt' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->blockWithPunishmentLifecycleTrackingAt('cur', 'prev', 1, 'life-1', 2, 600, 3600, 3, 60, 7200, $n),
            'claimPostPunishmentReentryAtMilliseconds (needs a lifecycle block)' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->claimPostPunishmentReentryAtMilliseconds('cur', 'prev', (string) $s->readGenerationBoundScoreStateAtMilliseconds('cur', 'prev', $n . '000')?->postPunishmentReentry?->id, $n . '000'),
            'addDistinctBoundedAcrossRotationAtMilliseconds (previous generation present)' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->addDistinctBoundedAcrossRotationAtMilliseconds('cur', 'bridge', 'prev', 'm', 'n', 600, 10, $n . '000'),
            'addDeviceCapPairAt' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->addDeviceCapPairAt('acct', 'm', 10, 'src', 'm', 10, 600, $n),
        ];
    }

    #[DataProvider('paths')]
    public function testDeniedExpiryInADirectCall(string $label, string $denied): void
    {
        foreach (['resp', 'phpredis'] as $shape) {
            foreach (['fresh', 'existing'] as $state) {
                $this->admin->execute(['FLUSHDB']);
                $this->admin->execute(['ACL', 'LOG', 'RESET']);
                $call = self::calls()[$label];
                $now = time();
                $setup = self::SETUP[$label] ?? null;
                if ($setup !== null) {
                    // the path only reaches its expiry command over state an earlier call left (a lifecycle block, a previous generation)
                    $setup(new RedisFullCapabilityStore($this->host('resp', $this->account('prep-' . $shape . '-' . $state, null)), self::NAMESPACE), $now);
                }
                if ($state === 'existing' && $setup === null) {
                    // seed through the same call with an unrestricted account, then move every TTL to a known value
                    $call(new RedisFullCapabilityStore($this->host('resp', $this->account('seed-' . $shape, null)), self::NAMESPACE), $now);
                    foreach ($this->keys() as $key) {
                        $this->admin->execute(['PEXPIRE', $key, 3_000_000]);
                    }
                }
                $user = $this->account('u-' . $shape . '-' . $state, $denied);
                $store = new RedisFullCapabilityStore($this->host($shape, $user), self::NAMESPACE);
                $before = $this->keyspace();
                $this->admin->execute(['ACL', 'LOG', 'RESET']);
                $outcome = 'returned';
                $class = '';
                $message = '';
                try {
                    $call($store, $now);
                } catch (\Throwable $exception) {
                    $outcome = 'threw';
                    $class = $exception::class;
                    $message = $exception->getMessage();
                }
                $after = $this->keyspace();
                $this->record([
                    'label' => $label, 'denied' => $denied, 'shape' => $shape, 'state' => $state, 'outcome' => $outcome, 'class' => $class,
                    'message' => substr($message, 0, 100), 'diff' => $this->diff($before, $after), 'acl' => $this->aclLog($user),
                    'int' => PHP_INT_SIZE,
                ]);
            }
        }
        foreach ($this->rows as $row) {
            if (($row['acl'] ?? []) === []) {
                continue;
            }
            // A denial that was REACHED: Redis logs exactly the denied command, the caller sees the range message, and nothing is left behind.
            self::assertSame([$denied], array_values(array_unique(array_map(static fn(mixed $a): string => self::text(is_array($a) ? ($a['object'] ?? null) : null), self::listOf($row['acl'])))), $label . ': the ACL log names the denied command.');
            self::assertSame('threw', $row['outcome'], $label);
            self::assertSame('expiry not representable', $row['message'], $label);
            self::assertSame([], $row['diff'], $label . ': keyspace unchanged (DUMP and PEXPIRETIME) after the refused expiry.');
        }
        self::assertCount(4, $this->rows, 'Two shapes by two states.');
    }

    /**
     * Where a failure is raised, and what it leaves: the same direct score call under five conditions that all end in an exception, plus the
     * success control. The range cases run the SHIPPED script text with string arguments (the public methods take a native int, which on a
     * 32-bit PHP cannot hold the TTL that Redis rejects), so they are comparable on both widths.
     */
    public function testProvenanceOfEachFailureOfADirectScoreCall(): void
    {
        $script = (new \ReflectionClassConstant(RedisFullCapabilityStore::class, 'SCORE_INCREMENT'))->getValue();
        self::assertIsString($script);
        foreach (['resp', 'phpredis'] as $shape) {
            $full = $this->account('full-' . $shape, null);
            $store = new RedisFullCapabilityStore($this->host($shape, $full), self::NAMESPACE);
            $key = (new \ReflectionMethod($store, 'key'))->invoke($store, 'score', 'a');
            $executor = $this->host($shape, $full);
            $now = (string) time();

            $run = function (string $label, callable $call) use ($shape, $full): void {
                $this->admin->execute(['FLUSHDB']);
                $this->admin->execute(['ACL', 'LOG', 'RESET']);
                $before = $this->keyspace();
                $outcome = 'returned';
                $class = '';
                $message = '';
                try {
                    $call();
                } catch (\Throwable $exception) {
                    $outcome = 'threw';
                    $class = $exception::class;
                    $message = $exception->getMessage();
                }
                $after = $this->keyspace();
                $this->record([
                    'scenario' => $label, 'shape' => $shape, 'outcome' => $outcome, 'class' => $class, 'message' => substr($message, 0, 110),
                    'diff' => $this->diff($before, $after), 'acl' => $this->aclLog($full), 'int' => PHP_INT_SIZE,
                    'ttl_ms' => array_values(array_map(static fn(array $k): string => $k['expires'], $after)),
                ]);
            };
            $keyName = self::text($key);
            $eval = static fn(string $ttl, string $at): mixed => $executor->execute(['EVAL', $script, 1, $keyName, $ttl, '1', (string) PHP_INT_MAX, (string) PHP_INT_MIN, $at]);

            $run('success: valid TTL (control)', static fn() => $eval('600', $now));
            $run('public ttl 0: PHP validation', static fn() => $store->incrementAt('a', 0, 1, (int) $now));
            $run('range: TTL past Redis bound (judged by EXPIRE inside the script)', static fn() => $eval('9223372036854775', '0'));
            $run('preflight: TTL past the script bound (Lua preflight)', static fn() => $eval('9223372036854776', $now));
            $run('caller time not representable (Lua)', static fn() => $eval('600', '01'));
            $run('connection: killed after authentication', function () use ($full, $store): void {
                $address = $this->addressOf($full);
                self::assertNotNull($address);
                $this->admin->execute(['CLIENT', 'KILL', 'ADDR', $address]);
                $store->incrementAt('a', 600, 1, time());
            });
        }
        $closedPort = $this->closedPort();
        set_error_handler(static fn(): bool => true);
        foreach (['resp', 'phpredis'] as $shape) {
            $outcome = 'returned';
            $class = $message = '';
            try {
                $executor = $shape === 'resp'
                    ? new RespRedisCommandExecutor($this->host, $closedPort, RespRedisCommandExecutor::MODE_EVAL)
                    : (function () use ($closedPort): RedisCommandExecutorInterface {
                        $redis = new \Redis();
                        try {
                            $redis->connect($this->host, $closedPort, 1.0);
                        } catch (\RedisException $exception) {
                            throw new BackendFailureException('Redis transport failure: ' . $exception->getMessage());
                        }

                        return new PhpRedisScriptExecutor($redis, false);
                    })();
                (new RedisFullCapabilityStore($executor, self::NAMESPACE))->incrementAt('a', 600, 1, time());
            } catch (\Throwable $exception) {
                $outcome = 'threw';
                $class = $exception::class;
                $message = $exception->getMessage();
            }
            $this->record(['scenario' => 'connection: refused (nothing listens)', 'shape' => $shape, 'outcome' => $outcome, 'class' => $class, 'message' => substr($message, 0, 110), 'int' => PHP_INT_SIZE]);
        }
        restore_error_handler();

        $by = [];
        foreach ($this->rows as $row) {
            $by[self::text($row['shape']) . ' | ' . self::text($row['scenario'])] = $row;
        }
        foreach (['resp', 'phpredis'] as $shape) {
            self::assertSame('returned', $by[$shape . ' | success: valid TTL (control)']['outcome']);
            self::assertSame(['created'], array_map(static fn(mixed $c): string => preg_replace('/^\S+ /', '', self::text($c)) ?? '', self::listOf($by[$shape . ' | success: valid TTL (control)']['diff'] ?? null)));
            self::assertSame('Score TTL must be positive.', $by[$shape . ' | public ttl 0: PHP validation']['message']);
            $range = $by[$shape . ' | range: TTL past Redis bound (judged by EXPIRE inside the script)'];
            self::assertSame('expiry not representable', $range['message'], 'The genuine range refusal carries the same text as an ACL refusal ...');
            self::assertSame([], $range['acl'], '... but Redis logs no ACL denial for it: only the ACL log tells them apart.');
            self::assertSame([], $range['diff']);
            self::assertSame('score TTL exceeds Redis backend expiry representability', $by[$shape . ' | preflight: TTL past the script bound (Lua preflight)']['message']);
            self::assertSame('caller time is not representable', $by[$shape . ' | caller time not representable (Lua)']['message']);
            self::assertSame(BackendFailureException::class, $by[$shape . ' | connection: refused (nothing listens)']['class']);
        }
        self::assertSame(BackendFailureException::class, $by['resp | connection: killed after authentication']['class']);
        self::assertSame('returned', $by['phpredis | connection: killed after authentication']['outcome'], 'ext-redis reconnects once and the call succeeds.');
    }

    private function closedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
    }

    /** The address of the account's connection, from CLIENT LIST. */
    private function addressOf(string $user): ?string
    {
        foreach (explode("\n", self::text($this->admin->execute(['CLIENT', 'LIST']))) as $line) {
            if (preg_match('/ addr=(\S+) .* user=' . preg_quote($user, '/') . ' /', $line . ' ', $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }

    private function account(string $name, ?string $denied): string
    {
        $rules = ['reset', 'on', '>' . self::PASSWORD, '~*', '+@all'];
        if ($denied !== null) {
            $rules[] = '-' . $denied;
        }
        $this->admin->execute(['ACL', 'SETUSER', $name, ...$rules]);
        $this->users[] = $name;

        return $name;
    }

    private function host(string $shape, string $user): RedisCommandExecutorInterface
    {
        if ($shape === 'resp') {
            $executor = new RespRedisCommandExecutor($this->host, $this->port, RespRedisCommandExecutor::MODE_EVAL);
            self::assertSame('OK', $executor->execute(['AUTH', $user, self::PASSWORD]));

            return $executor;
        }
        $redis = new \Redis();
        self::assertTrue($redis->connect($this->host, $this->port, 5.0));
        self::assertTrue($redis->auth([$user, self::PASSWORD]));
        $inner = new PhpRedisScriptExecutor($redis, false);

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

    /** @return list<string> */
    private function keys(): array
    {
        $keys = [];
        foreach ((array) $this->admin->execute(['KEYS', '*']) as $key) {
            $keys[] = self::text($key);
        }
        sort($keys);

        return $keys;
    }

    /** @return array<string, array{type: string, dump: string, expires: string}> */
    private function keyspace(): array
    {
        $state = [];
        foreach ($this->keys() as $key) {
            $dump = $this->admin->execute(['DUMP', $key]);
            $state[$key] = [
                'type' => self::text($this->admin->execute(['TYPE', $key])),
                'dump' => is_string($dump) ? bin2hex($dump) : '',
                'expires' => self::text($this->admin->execute(['PEXPIRETIME', $key])),
            ];
        }

        return $state;
    }

    /**
     * @param array<string, array{type: string, dump: string, expires: string}> $before
     * @param array<string, array{type: string, dump: string, expires: string}> $after
     * @return list<string>
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
                $changes[] = substr($key, strlen('maatify:rate-limiter:v1:') + 65) . ' ' . $what;
            }
        }
        sort($changes);

        return $changes;
    }

    /** @return list<array{reason: string, context: string, object: string}> */
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
                $entries[] = ['reason' => $map['reason'] ?? '', 'context' => $map['context'] ?? '', 'object' => substr($map['object'] ?? '', 0, 24)];
            }
        }

        return array_reverse($entries);
    }

    /** @param array<string, mixed> $row */
    private function record(array $row): void
    {
        $this->rows[] = $row;
        $file = getenv('EXPIRY_270_EVIDENCE');
        if (is_string($file) && $file !== '') {
            file_put_contents($file, json_encode($row, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
        }
    }

    /** @return array<mixed> */
    private static function listOf(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
