<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\PhpRedisScriptExecutor;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #270 (DEC-015, DEC-018, LT-P2-12): provenance of a failed expiry in DIRECT store calls (not `transactRequest()`).
 * It started as the study of #276 (read-only, against the shipped code) and is now the before / after verification of the fix: the same
 * matrix runs under `EXPIRY_270_CONTRACT=before` (the swallowed denial answers `expiry not representable`, the study's finding) and under
 * the default `after` (the denial answers `NOPERM` naming the command; the genuine range refusal keeps `expiry not representable`). Every case has its own temporary ACL account;
 * the data is synthetic; the keyspace is read from outside Lua with DUMP and PEXPIRETIME. With `EXPIRY_270_EVIDENCE` set to a file
 * path each case appends one JSON line of what it observed.
 */
#[Group('redis-expiry-provenance-270')]
#[Group('php32')]
final class ExpiryProvenanceDirectStudy270Test extends TestCase
{
    private const PASSWORD = 'synthetic-study-password';

    private const RANGE_MESSAGE = 'expiry not representable';

    private const NAMESPACE = 'exp-270';

    /** An independent key no call touches: known value and absolute expiry, compared byte for byte before and after in BOTH arms. */
    private const SENTINEL = 'exp270-sentinel';

    /**
     * The branch each (call, denied command, state) is EXPECTED to take. A listed state means the call reaches the denied expiry command
     * (Redis logs the denial, the caller sees the pcall-swallowed range text, nothing is left behind). Anything not listed must NOT reach it:
     * the call returns exactly what the unrestricted control returned and leaves the same keyspace change.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const REACHED = [
        'incrementAt' => ['expire' => ['fresh']],
        'incrementIfSnapshotAt' => ['expire' => ['fresh']],
        'replaceScoreAt' => ['expire' => ['fresh', 'existing']],
        'setAt' => ['expire' => ['fresh', 'existing']],
        'blockAt' => ['expire' => ['fresh']],
        'incrementBudgetAt' => ['expire' => ['fresh']],
        'addDistinctAt' => ['expire' => ['fresh']],
        'incrementWatchFlagAt' => ['expire' => ['fresh']],
        'addDistinctBoundedAt' => ['expire' => ['fresh']],
        'addDistinctBoundedWithSnapshotAt' => ['expire' => ['fresh']],
        'acquireProbeLease' => ['expire' => ['fresh']],
        'blockWithCycleTracking' => ['expire' => ['fresh', 'existing']],
        'addDistinctAcrossRotationAt' => ['expire' => ['fresh']],
        'incrementWatchFlagAcrossRotationAt' => ['expire' => ['fresh']],
        'mutateGenerationBoundScoreAtMilliseconds' => ['expire' => ['fresh']],
        'incrementBudgetAt (epoch ended, key still present)' => ['expire' => ['prerequisite-only']],
        'incrementBudgetWithSeedAt (no Current, active seed)' => ['expire' => ['fresh']],
        'incrementBudgetWithSeedAt (no Current, ended seed)' => ['expire' => ['fresh']],
        'incrementBudgetWithSeedAt (ended Current, active seed)' => ['expire' => ['prerequisite-only']],
        'incrementBudgetWithSeedAt (ended Current, ended seed)' => ['expire' => ['prerequisite-only']],
        'blockAt (stronger than the stored block)' => ['expire' => ['prerequisite-only']],
        'acquireProbeLease (expired lease present)' => ['expire' => ['prerequisite-only']],
        'mutateGenerationBoundScoreAtMilliseconds (logically expired Current)' => ['expire' => ['prerequisite-only']],
        'blockWithPunishmentLifecycleTrackingAt (longer than the stored block)' => ['expire' => ['prerequisite-only', 'existing']],
        'blockWithPunishmentLifecycleTrackingAt' => ['expire' => ['prerequisite-only', 'existing']],
        'claimPostPunishmentReentryAtMilliseconds (block ended, score still alive)' => ['pexpire' => ['prerequisite-only']],
        'mutateGenerationBoundScoreAtMilliseconds (expected snapshot, live Current)' => ['pexpire' => ['prerequisite-only', 'existing']],
        'mutateGenerationBoundScoreAtMilliseconds (expected snapshot, state held in Previous)' => ['pexpire' => ['prerequisite-only', 'existing']],
        'addDistinctBoundedAcrossRotationAtMilliseconds (previous generation present)' => ['expire' => ['prerequisite-only'], 'pexpireat' => ['prerequisite-only']],
        'addDeviceCapPairAt' => ['expire' => ['fresh']],
    ];

    private const CLIENT_CLASS = [
        'resp' => 'Maatify\\RateLimiter\\Exception\\RateLimiterException',
        'phpredis' => 'RedisException',
    ];

    /** Representative paths run again under an account limited by command category rather than `+@all` minus one command. */
    private const LIMITED = ['incrementAt', 'blockAt', 'addDistinctAt', 'blockWithCycleTracking', 'addDistinctBoundedAcrossRotationAtMilliseconds (previous generation present)'];

    /** @var array<string, callable(RedisFullCapabilityStore, int): mixed> */
    private const SETUP = [
        'incrementBudgetAt (epoch ended, key still present)' => [self::class, 'seedEndedBudget'],
        'incrementBudgetWithSeedAt (ended Current, active seed)' => [self::class, 'seedEndedBudget'],
        'incrementBudgetWithSeedAt (ended Current, ended seed)' => [self::class, 'seedEndedBudget'],
        'blockAt (stronger than the stored block)' => [self::class, 'seedShorterBlock'],
        'acquireProbeLease (expired lease present)' => [self::class, 'seedExpiredLease'],
        'mutateGenerationBoundScoreAtMilliseconds (logically expired Current)' => [self::class, 'seedExpiredScore'],
        'blockWithPunishmentLifecycleTrackingAt (longer than the stored block)' => [self::class, 'seedShorterLifecycleBlock'],
        'blockWithPunishmentLifecycleTrackingAt' => [self::class, 'seedScore'],
        'claimPostPunishmentReentryAtMilliseconds (block ended, score still alive)' => [self::class, 'seedLifecycle'],
        'mutateGenerationBoundScoreAtMilliseconds (expected snapshot, live Current)' => [self::class, 'seedScore'],
        'mutateGenerationBoundScoreAtMilliseconds (expected snapshot, state held in Previous)' => [self::class, 'seedHeldInPrevious'],
        'addDistinctBoundedAcrossRotationAtMilliseconds (previous generation present)' => [self::class, 'seedPrevious'],
    ];

    public static function seedScore(RedisFullCapabilityStore $s, int $n): mixed
    {
        return $s->mutateGenerationBoundScoreAtMilliseconds('cur', 'prev', null, 600, 5, $n . '000');
    }

    /** A budget whose epoch ended 100 s ago while the key is still physically present (its TTL is Redis's own clock). */
    public static function seedEndedBudget(RedisFullCapabilityStore $s, int $n): mixed
    {
        return $s->incrementBudgetAt('a', 600, 1, $n - 700);
    }

    public static function seedShorterBlock(RedisFullCapabilityStore $s, int $n): mixed
    {
        $s->blockAt('a', 2, 300, $n);

        return null;
    }

    public static function seedExpiredLease(RedisFullCapabilityStore $s, int $n): mixed
    {
        return $s->acquireProbeLease('p', $n - 100, 60);
    }

    public static function seedExpiredScore(RedisFullCapabilityStore $s, int $n): mixed
    {
        return $s->mutateGenerationBoundScoreAtMilliseconds('cur', 'prev', null, 600, 5, ($n - 700) . '000');
    }

    public static function seedShorterLifecycleBlock(RedisFullCapabilityStore $s, int $n): mixed
    {
        self::seedScore($s, $n);

        return $s->blockWithPunishmentLifecycleTrackingAt('cur', 'prev', 1, 'life-1', 2, 300, 3600, 3, 60, 7200, $n);
    }

    public static function seedHeldInPrevious(RedisFullCapabilityStore $s, int $n): mixed
    {
        return $s->mutateGenerationBoundScoreAtMilliseconds('prev', null, null, 600, 5, $n . '000');
    }

    /** A score that outlives its block (3600 s against a 600 s block), so a claim made after the block ended finds live evidence. */
    public static function seedLifecycle(RedisFullCapabilityStore $s, int $n): mixed
    {
        $s->mutateGenerationBoundScoreAtMilliseconds('cur', 'prev', null, 3600, 5, $n . '000');

        return $s->blockWithPunishmentLifecycleTrackingAt('cur', 'prev', 1, 'life-1', 2, 600, 3600, 3, 60, 7200, $n);
    }

    public static function seedPrevious(RedisFullCapabilityStore $s, int $n): mixed
    {
        return $s->addDistinctBoundedAt('prev', 'x', 600, 10, $n);
    }

    private RespRedisCommandExecutor $admin;

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    private ?\Redis $lastRedis = null;

    private static bool $evidenceStarted = false;

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
            'mutateGenerationBoundScoreAtMilliseconds (expected snapshot, live Current)' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->mutateGenerationBoundScoreAtMilliseconds('cur', 'prev', $s->readGenerationBoundScoreStateAtMilliseconds('cur', 'prev', $n . '000'), 600, 6, $n . '000'),
            'mutateGenerationBoundScoreAtMilliseconds (expected snapshot, state held in Previous)' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->mutateGenerationBoundScoreAtMilliseconds('cur', 'prev', $s->readGenerationBoundScoreStateAtMilliseconds('cur', 'prev', $n . '000'), 600, 6, $n . '000'),
            'incrementBudgetAt (epoch ended, key still present)' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->incrementBudgetAt('a', 600, 1, $n),
            'incrementBudgetWithSeedAt (no Current, active seed)' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->incrementBudgetWithSeedAt('a', 600, new BudgetStateDTO(2, $n), 1, $n),
            'incrementBudgetWithSeedAt (no Current, ended seed)' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->incrementBudgetWithSeedAt('a', 600, new BudgetStateDTO(2, $n - 1200), 1, $n),
            'incrementBudgetWithSeedAt (ended Current, active seed)' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->incrementBudgetWithSeedAt('a', 600, new BudgetStateDTO(2, $n), 1, $n),
            'incrementBudgetWithSeedAt (ended Current, ended seed)' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->incrementBudgetWithSeedAt('a', 600, new BudgetStateDTO(2, $n - 1200), 1, $n),
            'blockAt (stronger than the stored block)' => static function (RedisFullCapabilityStore $s, int $n): mixed {
                $s->blockAt('a', 2, 600, $n);

                return null;
            },
            'acquireProbeLease (expired lease present)' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->acquireProbeLease('p', $n, 60),
            'mutateGenerationBoundScoreAtMilliseconds (logically expired Current)' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->mutateGenerationBoundScoreAtMilliseconds('cur', 'prev', null, 600, 7, $n . '000'),
            'blockWithPunishmentLifecycleTrackingAt (longer than the stored block)' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->blockWithPunishmentLifecycleTrackingAt('cur', 'prev', 1, 'life-1', 2, 600, 3600, 3, 60, 7200, $n),
            'blockWithPunishmentLifecycleTrackingAt' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->blockWithPunishmentLifecycleTrackingAt('cur', 'prev', 1, 'life-1', 2, 600, 3600, 3, 60, 7200, $n),
            'claimPostPunishmentReentryAtMilliseconds (block ended, score still alive)' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->claimPostPunishmentReentryAtMilliseconds('cur', 'prev', (string) $s->readGenerationBoundScoreStateAtMilliseconds('cur', 'prev', ($n + 601) . '000')?->postPunishmentReentry?->id, ($n + 601) . '000'),
            'addDistinctBoundedAcrossRotationAtMilliseconds (previous generation present)' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->addDistinctBoundedAcrossRotationAtMilliseconds('cur', 'bridge', 'prev', 'm', 'n', 600, 10, $n . '000'),
            'addDeviceCapPairAt' => static fn(RedisFullCapabilityStore $s, int $n): mixed => $s->addDeviceCapPairAt('acct', 'm', 10, 'src', 'm', 10, 600, $n),
        ];
    }

    /** @return list<string> */
    private static function statesOf(string $label): array
    {
        if (str_starts_with($label, 'claimPostPunishmentReentry')) {
            return ['prerequisite-only']; // a second claim is a different path, so there is no honest "existing" state
        }

        return isset(self::SETUP[$label]) ? ['prerequisite-only', 'existing'] : ['fresh', 'existing'];
    }

    /** Builds the state the call starts from, identically for the control and for the denied run. */
    private function prepare(string $label, string $state, string $shape, int $now): void
    {
        $this->admin->execute(['FLUSHDB']);
        $call = self::calls()[$label];
        $seed = new RedisFullCapabilityStore($this->host('resp', $this->account('prep-' . $shape . '-' . $state, null)), self::NAMESPACE);
        $setup = self::SETUP[$label] ?? null;
        if ($setup !== null) {
            // the path only reaches its expiry command over state an earlier call left (a lifecycle block, a previous generation)
            $setup($seed, $now);
        }
        if ($state === 'existing') {
            // the same call once more with an unrestricted account, then every TTL moves to a known value
            $call($seed, $now);
            foreach ($this->keys() as $key) {
                $this->admin->execute(['PEXPIRE', $key, 3_000_000]);
            }
        }
        // after everything else (the TTL normalisation above must not touch it); the epoch is a decimal string, safe on 32-bit
        $this->admin->execute(['SET', self::SENTINEL, 'sentinel-value-270']);
        $this->admin->execute(['PEXPIREAT', self::SENTINEL, ($now + 7200) . '000']);
    }

    /**
     * @param callable(RedisFullCapabilityStore, int): mixed $call
     * @return array{outcome: string, class: string, message: string, result: string, diff: list<string>, acl: list<array{reason: string, context: string, object: string}>, stats: array<string, string>, after: array<string, array{type: string, dump: string, expires: string}>}
     */
    private function observe(callable $call, RedisFullCapabilityStore $store, int $now, string $user): array
    {
        $before = $this->keyspace();
        $this->admin->execute(['ACL', 'LOG', 'RESET']);
        $this->admin->execute(['CONFIG', 'RESETSTAT']);
        $outcome = 'returned';
        $class = $message = $result = '';
        try {
            // generated identifiers (a re-entry id) are random per run: the comparison is on everything else
            $result = preg_replace('/\b[0-9a-f]{32}\b/', '<id>', print_r($call($store, $now), true)) ?? '';
        } catch (\Throwable $exception) {
            $outcome = 'threw';
            $class = $exception::class;
            $message = $exception->getMessage();
        }
        $after = $this->keyspace();
        self::assertArrayHasKey(self::SENTINEL, $before, 'the sentinel exists before the call.');
        self::assertSame($before[self::SENTINEL], $after[self::SENTINEL] ?? null, 'the sentinel (DUMP bytes, type, absolute expiry) is identical after the call.');
        self::assertSame(self::text($this->admin->execute(['GET', self::SENTINEL])), 'sentinel-value-270');
        self::assertSame(($now + 7200) . '000', $after[self::SENTINEL]['expires'], 'and its absolute expiry is the known one.');

        return [
            'outcome' => $outcome, 'class' => $class, 'message' => substr($message, 0, 100), 'result' => $result,
            'diff' => $this->diff($before, $after), 'acl' => $this->aclLog($user), 'stats' => $this->expiryStats(), 'after' => $after,
        ];
    }

    #[DataProvider('paths')]
    public function testDeniedExpiryInADirectCall(string $label, string $denied): void
    {
        $rows = 0;
        foreach (['resp', 'phpredis'] as $shape) {
            foreach (self::statesOf($label) as $state) {
                $this->caseRow($label, $denied, $shape, $state, 'wide');
                $rows += 2;
            }
        }
        self::assertCount($rows, $this->rows, 'One control row and one denied row per shape and state.');
    }

    /**
     * The same expectation under an account limited by command CATEGORY (scripting, read, write, connection minus the one denied command)
     * instead of `+@all` minus one. The wide account in the matrix above is a variable-isolation control: it removes exactly one command.
     */
    #[DataProvider('limitedPaths')]
    public function testDeniedExpiryUnderACategoryLimitedAccount(string $label, string $denied): void
    {
        foreach (['resp', 'phpredis'] as $shape) {
            foreach (self::statesOf($label) as $state) {
                $this->caseRow($label, $denied, $shape, $state, 'limited');
            }
        }
        self::assertNotSame([], $this->rows);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function limitedPaths(): array
    {
        $cases = [];
        foreach (self::LIMITED as $label) {
            foreach (['expire', 'pexpireat'] as $denied) {
                $cases["$label / -$denied"] = [$label, $denied];
            }
        }

        return $cases;
    }

    private function caseRow(string $label, string $denied, string $shape, string $state, string $kind): void
    {
        $call = self::calls()[$label];
        $now = time();
        $nowMs = $now * 1000.0; // float: a millisecond epoch overflows a native int on 32-bit

        // unrestricted control from the identical prepared state: what the call returns and leaves when nothing is denied
        $this->prepare($label, $state, $shape, $now);
        $controlUser = $this->account('control-' . $shape, null);
        $control = $this->observe($call, new RedisFullCapabilityStore($this->host($shape, $controlUser), self::NAMESPACE), $now, $controlUser);
        $this->record([
            'kind' => 'control', 'label' => $label, 'denied' => '-', 'shape' => $shape, 'state' => $state, 'outcome' => $control['outcome'], 'class' => $control['class'],
            'message' => $control['message'], 'diff' => $control['diff'], 'acl' => $control['acl'], 'result' => $control['result'], 'stats' => $control['stats'], 'sentinel_dump' => md5($control['after'][self::SENTINEL]['dump']), 'int' => PHP_INT_SIZE,
        ]);
        self::assertSame('returned', $control['outcome'], $label . ': the unrestricted control must succeed (' . $control['message'] . ').');
        self::assertSame([], $control['acl'], $label . ': the control is not denied anything.');
        foreach ($control['after'] as $key => $entry) {
            self::assertNotSame('-1', $entry['expires'], $label . ': control key without expiry: ' . $key);
            self::assertGreaterThan($nowMs - 10_000, (float) $entry['expires'], $label . ': the control leaves an absolute expiry in the future.');
        }

        // the denied run, same state
        $this->prepare($label, $state, $shape, $now);
        $user = $this->account($kind . '-' . $shape, $denied, $kind === 'limited');
        $row = $this->observe($call, new RedisFullCapabilityStore($this->host($shape, $user), self::NAMESPACE), $now, $user);
        $expectReached = in_array($state, self::REACHED[$label][$denied] ?? [], true);
        $this->record([
            'kind' => $kind, 'label' => $label, 'denied' => $denied, 'shape' => $shape, 'state' => $state, 'expected' => $expectReached ? 'denied' : 'unreached',
            'outcome' => $row['outcome'], 'class' => $row['class'], 'message' => $row['message'], 'diff' => $row['diff'], 'acl' => $row['acl'], 'stats' => $row['stats'], 'sentinel_dump' => md5($row['after'][self::SENTINEL]['dump']), 'int' => PHP_INT_SIZE,
        ]);
        $id = "$label / -$denied / $shape / $state / $kind";
        if ($expectReached) {
            // A denial that was REACHED: Redis logs exactly the denied command, the caller sees the range text, and nothing is left behind.
            self::assertSame([$denied], array_values(array_unique(array_map(static fn(array $a): string => $a['object'], $row['acl']))), $id . ': the ACL log names the denied command.');
            self::assertSame('threw', $row['outcome'], $id);
            self::assertSame(self::CLIENT_CLASS[$shape], $row['class'], $id . ': exception type by client.');
            self::assertSame(self::deniedMessage($denied), $row['message'], $id . ': the caller-visible text of the denial under the ' . self::contract() . ' contract.');
            self::assertStringNotContainsString(self::NAMESPACE, $row['message'], $id . ': the refusal never names a key.');
            self::assertSame([], $row['diff'], $id . ': keyspace unchanged (DUMP and PEXPIRETIME) after the refused expiry.');
            self::assertStringContainsString('rejected_calls=1', $row['stats'][$denied] ?? '', $id . ': Redis counts the rejection.');

            return;
        }
        // Not reached: the expiry command the account lacks was not needed, so the call equals the control (same result, same keyspace change).
        self::assertSame([], $row['acl'], $id . ': no denial expected on this branch.');
        self::assertSame('returned', $row['outcome'], $id . ' ' . $row['message']);
        self::assertSame($control['result'], $row['result'], $id . ': same result as the control.');
        self::assertSame(self::comparable($control['diff']), self::comparable($row['diff']), $id . ': same keyspace change as the control.');
        self::assertStringNotContainsString('rejected_calls=1', $row['stats'][$denied] ?? '', $id);
    }

    /**
     * Where a failure is raised, and what it leaves: the same direct score call under conditions that all end in an exception, plus the
     * success control. The decimal-argument range case runs the SHIPPED script text through EVAL (a native int on a 32-bit PHP cannot hold
     * the TTL that Redis rejects), so it is comparable on both widths; the public `incrementAt` range call is measured separately, on 64-bit only.
     */
    public function testProvenanceOfEachFailureOfADirectScoreCall(): void
    {
        $script = (new \ReflectionClassConstant(RedisFullCapabilityStore::class, 'SCORE_INCREMENT'))->getValue();
        self::assertIsString($script);
        $callerNow = time();
        foreach (['resp', 'phpredis'] as $shape) {
            $full = $this->account('full-' . $shape, null);
            $executor = $this->host($shape, $full);
            $store = new RedisFullCapabilityStore($executor, self::NAMESPACE);
            $keyName = self::text((new \ReflectionMethod($store, 'key'))->invoke($store, 'score', 'a'));
            $now = (string) $callerNow;
            $eval = static fn(string $ttl, string $at): mixed => $executor->execute(['EVAL', $script, 1, $keyName, $ttl, '1', (string) PHP_INT_MAX, (string) PHP_INT_MIN, $at]);

            $this->scenario($shape, $full, 'success: valid TTL (control, EVAL)', static fn() => $eval('600', $now));
            $this->scenario($shape, $full, 'success: valid TTL (control, public incrementAt)', static fn() => $store->incrementAt('a', 600, 1, (int) $now));
            $this->scenario($shape, $full, 'public ttl 0: PHP validation', static fn() => $store->incrementAt('a', 0, 1, (int) $now));
            $this->scenario($shape, $full, 'range: decimal TTL past Redis bound via EVAL (judged by EXPIRE inside the script)', static fn() => $eval('9223372036854775', '0'));
            if (PHP_INT_SIZE >= 8) {
                $this->scenario($shape, $full, 'range: public incrementAt with the same TTL (64-bit only)', static fn() => $store->incrementAt('a', 9_223_372_036_854_775, 1, 0));
            } else {
                $this->record(['scenario' => 'range: public incrementAt with the same TTL (64-bit only)', 'shape' => $shape, 'outcome' => 'not-run', 'class' => '', 'message' => 'the TTL is not a native int on this width', 'int' => PHP_INT_SIZE]);
            }
            $this->scenario($shape, $full, 'preflight: TTL past the script bound (Lua preflight)', static fn() => $eval('9223372036854776', $now));
            $this->scenario($shape, $full, 'caller time not representable (Lua)', static fn() => $eval('600', '01'));
            $this->killScenario($shape, 'connection: killed after authentication (default client retries)', null);
            $this->killScenario($shape, 'connection: killed after authentication (client retries disabled)', 0);
        }
        $this->refusedScenarios();

        $by = [];
        foreach ($this->rows as $row) {
            $by[self::text($row['shape']) . ' | ' . self::text($row['scenario'])] = $row;
        }
        foreach (['resp', 'phpredis'] as $shape) {
            foreach (['success: valid TTL (control, EVAL)', 'success: valid TTL (control, public incrementAt)'] as $scenario) {
                $control = $by[$shape . ' | ' . $scenario];
                self::assertSame('returned', $control['outcome'], $scenario);
                self::assertSame(['created'], array_map(static fn(mixed $c): string => preg_replace('/^\S+ /', '', self::text($c)) ?? '', self::listOf($control['diff'] ?? null)), $scenario);
                // what was STORED, through the public interface (value, updatedAt) and Redis (the absolute expiry)
                self::assertSame('1', self::text($control['stored_value'] ?? null), $scenario . ': stored value.');
                self::assertSame((string) $callerNow, self::text($control['stored_updated_at'] ?? null), $scenario . ': stored updatedAt.');
                // EXPIRE 600 sets (Redis clock at the call) + 600 000 ms, so the stored expiry lies between the two Redis TIME readings plus 600 s
                $expires = (float) self::text($control['expires_ms'] ?? null);
                self::assertGreaterThanOrEqual((float) self::text($control['redis_time_before_ms'] ?? null) + 600_000.0, $expires, $scenario . ': not earlier than TIME-before + 600 s.');
                self::assertLessThanOrEqual((float) self::text($control['redis_time_after_ms'] ?? null) + 600_000.0, $expires, $scenario . ': not later than TIME-after + 600 s.');
            }
            self::assertSame('Score TTL must be positive.', $by[$shape . ' | public ttl 0: PHP validation']['message']);
            $range = $by[$shape . ' | range: decimal TTL past Redis bound via EVAL (judged by EXPIRE inside the script)'];
            self::assertSame(self::RANGE_MESSAGE, $range['message'], 'The genuine range refusal keeps its text under both contracts.');
            self::assertSame([], $range['acl'], 'Redis logs no ACL denial for it.');
            self::assertSame([], $range['diff']);
            // independent evidence that Redis itself reached EXPIRE and refused the value (not the PHP side, not the Lua preflight)
            $stats = self::listOf($range['stats'] ?? null);
            self::assertStringContainsString('calls=1', self::text($stats['expire'] ?? null), 'Redis executed EXPIRE once.');
            self::assertStringContainsString('failed_calls=1', self::text($stats['expire'] ?? null), 'and it failed there (a range refusal, not a rejection).');
            self::assertStringContainsString('rejected_calls=0', self::text($stats['expire'] ?? null));
            if (PHP_INT_SIZE >= 8) {
                $public = $by[$shape . ' | range: public incrementAt with the same TTL (64-bit only)'];
                self::assertSame(self::RANGE_MESSAGE, $public['message'], 'The public 64-bit call keeps the range text.');
                self::assertSame([], $public['acl']);
                self::assertSame($range['diff'], $public['diff'], 'The public call leaves the same state as the EVAL control (nothing).');
                self::assertSame([], $public['diff']);
                self::assertStringContainsString('failed_calls=1', self::text(self::listOf($public['stats'] ?? null)['expire'] ?? null));
            }
            self::assertSame('score TTL exceeds Redis backend expiry representability', $by[$shape . ' | preflight: TTL past the script bound (Lua preflight)']['message']);
            self::assertSame('caller time is not representable', $by[$shape . ' | caller time not representable (Lua)']['message']);
            self::assertSame(BackendFailureException::class, $by[$shape . ' | connection: refused (nothing listens on a port proven closed)']['class']);
        }
        self::assertSame(BackendFailureException::class, $by['resp | connection: killed after authentication (default client retries)']['class']);
        self::assertSame(BackendFailureException::class, $by['resp | connection: killed after authentication (client retries disabled)']['class']);
        // phpredis: the outcome depends on OPT_MAX_RETRIES, so it is recorded as an observation CONDITIONED on that setting.
        $default = $by['phpredis | connection: killed after authentication (default client retries)'];
        self::assertGreaterThan(0, (int) self::text($default['max_retries'] ?? null));
        self::assertSame('returned', $default['outcome'], 'with retries enabled ext-redis reconnects and the call succeeds (observation, not a contract).');
        $disabled = $by['phpredis | connection: killed after authentication (client retries disabled)'];
        self::assertSame('0', self::text($disabled['max_retries'] ?? null));
        self::assertSame('threw', $disabled['outcome'], 'with retries disabled the dead connection is not retried ...');
        self::assertSame(BackendFailureException::class, $disabled['class'], '... and the Host shape classifies it as a transport failure.');
        self::assertStringStartsWith('Redis transport failure', self::text($disabled['message'] ?? null));
    }

    /**
     * What the Lua `redis.pcall` primitive hands back. This is a PROBE script (not shipped code): it returns the error text of a pcall'd EXPIRE
     * so the two refusals can be compared at the source. The shipped scripts discard this text today, so a typed distinction built on it needs
     * its own decision before it is proposed.
     */
    public function testPcallReplyShapeForTheTwoRefusals(): void
    {
        $probe = "local r = redis.pcall(ARGV[1], KEYS[1], ARGV[2]); if type(r) == 'table' and r.err then return 'ERR:' .. r.err end return 'OK:' .. tostring(r)";
        foreach (['resp', 'phpredis'] as $shape) {
            foreach (['expire', 'pexpire', 'pexpireat'] as $denied) {
                $user = $this->account('probe-' . $shape . '-' . $denied, $denied);
                $executor = $this->host($shape, $user);
                $reply = self::text($executor->execute(['EVAL', $probe, 1, 'probe-key', $denied, '10']));
                $this->record(['scenario' => 'pcall probe: ACL denial', 'shape' => $shape, 'denied' => $denied, 'reply' => $reply, 'int' => PHP_INT_SIZE]);
                self::assertStringStartsWith('ERR:', $reply, $shape . ' ' . $denied);
                self::assertStringContainsString("can't run this command", $reply, $shape . ' ' . $denied);
            }
            $full = $this->host($shape, $this->account('probe-full-' . $shape, null));
            $range = self::text($full->execute(['EVAL', $probe, 1, 'probe-key', 'expire', '9223372036854775']));
            $this->record(['scenario' => 'pcall probe: genuine range refusal', 'shape' => $shape, 'denied' => '-', 'reply' => $range, 'int' => PHP_INT_SIZE]);
            self::assertStringStartsWith('ERR:', $range, $shape);
            self::assertStringContainsString('invalid expire time', $range, $shape);
        }
    }

    /**
     * @param callable(): mixed $call
     */
    private function scenario(string $shape, string $user, string $label, callable $call): void
    {
        $this->admin->execute(['FLUSHDB']);
        $this->admin->execute(['ACL', 'LOG', 'RESET']);
        $this->admin->execute(['CONFIG', 'RESETSTAT']);
        $before = $this->keyspace();
        $outcome = 'returned';
        $class = $message = '';
        $timeBefore = $this->serverMs();
        try {
            $call();
        } catch (\Throwable $exception) {
            $outcome = 'threw';
            $class = $exception::class;
            $message = $exception->getMessage();
        }
        $timeAfter = $this->serverMs();
        $after = $this->keyspace();
        $row = [
            'scenario' => $label, 'shape' => $shape, 'outcome' => $outcome, 'class' => $class, 'message' => substr($message, 0, 110),
            'diff' => $this->diff($before, $after), 'acl' => $this->aclLog($user), 'stats' => $this->expiryStats(), 'int' => PHP_INT_SIZE,
            'expires_ms' => array_values(array_map(static fn(array $k): string => $k['expires'], $after))[0] ?? '',
            'redis_time_before_ms' => $timeBefore, 'redis_time_after_ms' => $timeAfter,
        ];
        if ($outcome === 'returned' && $after !== []) {
            // what was stored, read through the public interface of the store under test
            $reader = new RedisFullCapabilityStore($this->host('resp', $this->account('reader', null)), self::NAMESPACE);
            $state = $reader->get('a');
            self::assertNotNull($state, 'a returned call that left a key must be readable through get().');
            $row['stored_value'] = (string) $state->value;
            $row['stored_updated_at'] = (string) $state->updatedAt;
        }
        $this->record($row);
    }

    /** Kills exactly the connection the store uses (named with CLIENT SETNAME, killed by id, verified gone) and then calls through it. */
    private function killScenario(string $shape, string $label, ?int $retries): void
    {
        $this->admin->execute(['FLUSHDB']);
        $user = $this->account('kill-' . $shape . '-' . ($retries ?? 'default'), null);
        $name = 'k270-' . $shape . '-' . ($retries ?? 'default');
        $store = new RedisFullCapabilityStore($this->host($shape, $user, $name, $retries), self::NAMESPACE);
        $redis = $this->lastRedis;
        self::assertSame(1, $this->clientsNamed($name), 'the store connection is identifiable before the kill.');
        $id = $this->clientIdOf($name);
        self::assertSame(1, $this->admin->execute(['CLIENT', 'KILL', 'ID', $id]), 'CLIENT KILL reports one killed connection.');
        self::assertSame(0, $this->clientsNamed($name), 'and it is gone.');
        $outcome = 'returned';
        $class = $message = '';
        try {
            $store->incrementAt('a', 600, 1, time());
        } catch (\Throwable $exception) {
            $outcome = 'threw';
            $class = $exception::class;
            $message = substr($exception->getMessage(), 0, 110);
        }
        $this->record([
            'scenario' => $label, 'shape' => $shape, 'outcome' => $outcome, 'class' => $class, 'message' => $message, 'int' => PHP_INT_SIZE,
            'max_retries' => $redis instanceof \Redis ? self::text($redis->getOption(\Redis::OPT_MAX_RETRIES)) : 'n/a',
        ]);
    }

    private function refusedScenarios(): void
    {
        $port = $this->closedPortOnTheRedisHost();
        set_error_handler(static fn(): bool => true);
        try {
            foreach (['resp', 'phpredis'] as $shape) {
                $outcome = 'returned';
                $class = $message = '';
                try {
                    $executor = $shape === 'resp'
                        ? new RespRedisCommandExecutor($this->host, $port, RespRedisCommandExecutor::MODE_EVAL)
                        : (function () use ($port): RedisCommandExecutorInterface {
                            $redis = new \Redis();
                            try {
                                $redis->connect($this->host, $port, 1.0);
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
                $this->record(['scenario' => 'connection: refused (nothing listens on a port proven closed)', 'shape' => $shape, 'outcome' => $outcome, 'class' => $class, 'message' => substr($message, 0, 110), 'int' => PHP_INT_SIZE]);
            }
        } finally {
            restore_error_handler();
        }
    }

    /** A port on the SAME host the Redis tests use, proven closed by a refused connection (ECONNREFUSED) before it is used. */
    private function closedPortOnTheRedisHost(): int
    {
        for ($attempt = 0; $attempt < 60; ++$attempt) {
            $port = random_int(30_000, 60_000);
            if ($port === $this->port) {
                continue;
            }
            $errno = 0;
            $errstr = '';
            $socket = @stream_socket_client('tcp://' . $this->host . ':' . $port, $errno, $errstr, 1.0);
            if ($socket === false && $errno === 111) {
                return $port;
            }
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        self::fail('no closed port found on the Redis host.');
    }

    private function clientsNamed(string $name): int
    {
        return preg_match_all('/(?:^|\s)name=' . preg_quote($name, '/') . '(?:\s|$)/m', self::text($this->admin->execute(['CLIENT', 'LIST']))) ?: 0;
    }

    private function clientIdOf(string $name): string
    {
        foreach (explode("\n", self::text($this->admin->execute(['CLIENT', 'LIST']))) as $line) {
            if (preg_match('/(?:^|\s)id=(\d+)\s.*\sname=' . preg_quote($name, '/') . '(?:\s|$)/', $line, $match) === 1) {
                return $match[1];
            }
        }
        self::fail('no connection named ' . $name);
    }

    /** Redis TIME as epoch milliseconds, a decimal string (a native int cannot hold it on 32-bit). */
    private function serverMs(): string
    {
        $time = $this->admin->execute(['TIME']);
        self::assertIsArray($time);

        return self::text($time[0]) . str_pad((string) intdiv((int) self::text($time[1]), 1000), 3, '0', STR_PAD_LEFT);
    }

    /** @return array<string, string> calls / rejected / failed counters Redis kept for the three expiry commands since CONFIG RESETSTAT. */
    private function expiryStats(): array
    {
        $stats = [];
        foreach (explode("\n", self::text($this->admin->execute(['INFO', 'commandstats']))) as $line) {
            if (preg_match('/^cmdstat_(expire|pexpire|pexpireat):calls=(\d+),.*,rejected_calls=(\d+),failed_calls=(\d+)/', trim($line), $m) === 1) {
                $stats[$m[1]] = 'calls=' . $m[2] . ',rejected_calls=' . $m[3] . ',failed_calls=' . $m[4];
            }
        }

        return $stats;
    }

    private function account(string $name, ?string $denied, bool $limited = false): string
    {
        $rules = $limited
            ? ['reset', 'on', '>' . self::PASSWORD, '~*', '-@all', '+@scripting', '+@read', '+@write', '+@connection']
            : ['reset', 'on', '>' . self::PASSWORD, '~*', '+@all'];
        if ($denied !== null) {
            $rules[] = '-' . $denied;
        }
        $this->admin->execute(['ACL', 'SETUSER', $name, ...$rules]);
        $this->users[] = $name;

        return $name;
    }

    private function host(string $shape, string $user, ?string $name = null, ?int $retries = null): RedisCommandExecutorInterface
    {
        $this->lastRedis = null;
        if ($shape === 'resp') {
            $executor = new RespRedisCommandExecutor($this->host, $this->port, RespRedisCommandExecutor::MODE_EVAL);
            self::assertSame('OK', $executor->execute(['AUTH', $user, self::PASSWORD]));
            if ($name !== null) {
                self::assertSame('OK', $executor->execute(['CLIENT', 'SETNAME', $name]));
            }

            return $executor;
        }
        $redis = new \Redis();
        self::assertTrue($redis->connect($this->host, $this->port, 5.0));
        if ($retries !== null) {
            self::assertTrue($redis->setOption(\Redis::OPT_MAX_RETRIES, $retries));
        }
        self::assertTrue($redis->auth([$user, self::PASSWORD]));
        if ($name !== null) {
            $redis->client('SETNAME', $name);
        }
        $this->lastRedis = $redis;
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

    /**
     * A relative expiry (EXPIRE / PEXPIRE) is applied at Redis's own clock, so the absolute PEXPIRETIME a call leaves differs by the
     * milliseconds between two runs (the control and the denied arm run a moment apart): whether a re-applied expiry shows up as
     * "changed" is timing, not state. On a branch that does NOT reach the denied command (no ACL denial and no rejection counter, asserted
     * separately) an expiry-only difference is dropped from BOTH arms before comparing; the key set, the content and every DUMP stay
     * compared byte for byte, and the control arm asserts that every key carries an absolute expiry in the future. A branch that reaches
     * the denial is never normalised: its diff must be empty.
     *
     * @param list<string> $diff
     * @return list<string>
     */
    private static function comparable(array $diff): array
    {
        $kept = [];
        foreach ($diff as $entry) {
            if (str_ends_with($entry, ' expiry')) {
                continue;
            }
            $kept[] = preg_replace('/\\+expiry$/', '', $entry) ?? $entry;
        }

        return $kept;
    }

    /** `before` reproduces the study's finding on the unfixed code; the default `after` is the contract of #270. */
    private static function contract(): string
    {
        return getenv('EXPIRY_270_CONTRACT') === 'before' ? 'before' : 'after';
    }

    /** What the caller sees when the account may not run `$denied` and the call reached it. */
    private static function deniedMessage(string $denied): string
    {
        return self::contract() === 'before' ? self::RANGE_MESSAGE : "NOPERM this user has no permissions to run the '" . $denied . "' command";
    }

    /** @param array<string, mixed> $row */
    private function record(array $row): void
    {
        $row['contract'] = self::contract();
        $this->rows[] = $row;
        $file = getenv('EXPIRY_270_EVIDENCE');
        if (! is_string($file) || $file === '') {
            return;
        }
        $line = json_encode($row, JSON_THROW_ON_ERROR) . "\n";
        // The first write creates the file exclusively (a stale or shared file fails the run); every write must land completely.
        $handle = fopen($file, self::$evidenceStarted ? 'ab' : 'xb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open the evidence file for writing (it must not exist before the run): ' . $file);
        }
        $written = fwrite($handle, $line);
        $closed = fclose($handle);
        if ($written !== strlen($line) || ! $closed) {
            throw new \RuntimeException('Short or failed evidence write.');
        }
        self::$evidenceStarted = true;
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
