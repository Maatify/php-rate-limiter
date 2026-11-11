<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Redis\OverlayWire;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-185-23 (#185, LT-185-23 (b)): when Current and Previous of a bounded rotation are one key (a fingerprint-secret
 * rotation without a key-secret rotation), the rotation takes its general path. The live set is Previous and stays
 * read-only; a new member is written to the bridge, the count is Previous plus bridge, and a member present in either
 * form is known. On `78526b5` a special alias branch answered "accepted, added" without writing anything, so a cap of
 * 10 accepted every member (adv8 `r1_alias_bounded_store.php`) and, through the public API, the account correlation
 * count stayed at 2 where the non-rotated and fully rotated controls reach 3 (adv8 `r1b` / `r1c`).
 */
#[Group('f-185-23')]
final class RotatedAliasBoundedRedisTest extends TestCase
{
    private const NOW_MS = 1_800_000_000_000;

    private const NAMESPACE = 'f185-23';

    private const MAX = 10;

    private RespRedisCommandExecutor $executor;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->executor = new RespRedisCommandExecutor($host, (int) $port);
        $this->executor->execute(['FLUSHDB']);
    }

    private function store(): RedisFullCapabilityStore
    {
        return new RedisFullCapabilityStore($this->executor, self::NAMESPACE);
    }

    private function raw(string $family, string $logical): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':' . $family . ':' . hash('sha256', $logical);
    }

    private function int(mixed $value): int
    {
        self::assertIsInt($value);

        return $value;
    }

    /** A live Previous set holding `$members`, as an earlier generation left it. */
    private function seedPrevious(string $logical, string ...$members): void
    {
        $this->executor->execute(['SADD', $this->raw('distinct', $logical), ...array_values($members)]);
        $this->executor->execute(['EXPIRE', $this->raw('distinct', $logical), '900']);
        $this->executor->execute(['HSET', $this->raw('distinct-meta', $logical), 'expiresAt', (string) (intdiv(self::NOW_MS, 1000) + 900)]);
        $this->executor->execute(['EXPIRE', $this->raw('distinct-meta', $logical), '900']);
    }

    /** @return list<string|false> `DUMP` of the set and its metadata. */
    private function dumpSet(string $logical): array
    {
        $set = $this->executor->execute(['DUMP', $this->raw('distinct', $logical)]);
        $meta = $this->executor->execute(['DUMP', $this->raw('distinct-meta', $logical)]);
        self::assertIsString($set);
        self::assertIsString($meta);

        return [$set, $meta];
    }

    private function aliased(string $logical, string $member): BoundedDistinctSnapshotDTO
    {
        return $this->store()->addDistinctBoundedWithSnapshotAcrossRotationAt($logical, $logical . '-bridge', $logical, $member, 'prev-' . $member, 900, self::MAX, self::NOW_MS);
    }

    /**
     * @param callable(string): BoundedDistinctSnapshotDTO $add
     * @return list<array{int, bool, bool}> count, accepted, added
     */
    private static function observe(callable $add, int $from, int $to): array
    {
        $out = [];
        for ($i = $from; $i <= $to; $i++) {
            $snapshot = $add('m' . $i);
            $out[] = [$snapshot->count, $snapshot->accepted, $snapshot->added];
        }

        return $out;
    }

    /** @return list<array{int, bool, bool}> */
    private static function expected(int $already, int $from, int $to): array
    {
        $out = [];
        $count = $already;
        for ($i = $from; $i <= $to; $i++) {
            $out[] = $count < self::MAX ? [++$count, true, true] : [self::MAX, false, false];
        }

        return $out;
    }

    /** adv8 r1 with a live Previous: the set is byte-identical, the bridge carries the new members, the cap holds. */
    public function testUnderTheAliasNewMembersGoToTheBridgeAndTheCapHolds(): void
    {
        $this->seedPrevious('scope-A', 'old');
        $before = $this->dumpSet('scope-A');

        self::assertSame(self::expected(1, 1, 12), self::observe(fn(string $m) => $this->aliased('scope-A', $m), 1, 12));
        self::assertSame($before, $this->dumpSet('scope-A'), 'Previous (the aliased set) is read-only.');
        self::assertSame(9, $this->int($this->executor->execute(['SCARD', $this->raw('distinct', 'scope-A-bridge')])));
        self::assertLessThanOrEqual(
            $this->int($this->executor->execute(['PTTL', $this->raw('distinct', 'scope-A')])),
            $this->int($this->executor->execute(['PTTL', $this->raw('distinct', 'scope-A-bridge')])),
        );
    }

    /** adv8 r1 as the Lead ran it, from an empty key: the first member opens the set, the rest go to the bridge. */
    public function testFromAnEmptyKeyTheEffectiveCountStopsAtTheCap(): void
    {
        self::assertSame(self::expected(0, 1, 12), self::observe(fn(string $m) => $this->aliased('scope-E', $m), 1, 12));
        self::assertSame(1, $this->int($this->executor->execute(['SCARD', $this->raw('distinct', 'scope-E')])));
        self::assertSame(9, $this->int($this->executor->execute(['SCARD', $this->raw('distinct', 'scope-E-bridge')])));
    }

    /** A member present in its previous or its current form is known: no bridge write. */
    public function testAMemberKnownInEitherFormIsNotWrittenAgain(): void
    {
        $this->seedPrevious('scope-P', 'prev-x', 'y');
        $before = $this->dumpSet('scope-P');

        $previousForm = $this->aliased('scope-P', 'x');
        $currentForm = $this->aliased('scope-P', 'y');

        self::assertSame([2, true, false], [$previousForm->count, $previousForm->accepted, $previousForm->added]);
        self::assertSame([2, true, false], [$currentForm->count, $currentForm->accepted, $currentForm->added]);
        self::assertSame($before, $this->dumpSet('scope-P'));
        self::assertSame(0, $this->executor->execute(['EXISTS', $this->raw('distinct', 'scope-P-bridge')]));
    }

    /** Under the alias both forms carry the current key secret: a previous form already in the bridge is known. */
    public function testAPreviousFormAlreadyInTheBridgeIsKnown(): void
    {
        $this->seedPrevious('scope-Q', 'old');
        $store = $this->store();
        $first = $store->addDistinctBoundedWithSnapshotAcrossRotationAt('scope-Q', 'scope-Q-bridge', 'scope-Q', 'a', 'prev-a', 900, self::MAX, self::NOW_MS);
        $again = $store->addDistinctBoundedWithSnapshotAcrossRotationAt('scope-Q', 'scope-Q-bridge', 'scope-Q', 'b', 'a', 900, self::MAX, self::NOW_MS);

        self::assertSame([2, true, true], [$first->count, $first->accepted, $first->added]);
        self::assertSame([2, true, false], [$again->count, $again->accepted, $again->added]);
        self::assertSame(1, $this->int($this->executor->execute(['SCARD', $this->raw('distinct', 'scope-Q-bridge')])));
    }

    /** Controls: the non-rotated path and a rotation with a different, absent Previous give the same answers. */
    public function testTheNonRotatedAndRotatedControlsGiveTheSameAnswers(): void
    {
        $store = $this->store();
        self::assertSame(self::expected(0, 1, 12), self::observe(fn(string $m) => $store->addDistinctBoundedWithSnapshotAt('scope-B', $m, 900, self::MAX, intdiv(self::NOW_MS, 1000)), 1, 12));
        self::assertSame(self::expected(0, 1, 12), self::observe(fn(string $m) => $store->addDistinctBoundedWithSnapshotAcrossRotationAt('scope-C', 'bridge-C', 'scope-C-prev', $m, 'prev-' . $m, 900, self::MAX, self::NOW_MS), 1, 12));
    }

    /**
     * Twelve devices on one account through the public API: each decision, and the largest correlation count any
     * bounded snapshot answered (rotated or not).
     *
     * @return array{list<string>, int}
     */
    private function scenario(RateLimiterConfig $config, string $namespace, bool $checkOnly): array
    {
        $reflection = new ReflectionClass(RedisFullCapabilityStore::class);
        $scripts = [$reflection->getConstant('ROTATED_SNAPSHOT'), $reflection->getConstant('BOUNDED_SNAPSHOT')];
        $read = $reflection->getConstant('BOUNDED_OBSERVATION_READ');
        $commit = $reflection->getConstant('DEVICE_CAP_COMMIT');
        self::assertIsString($read);
        self::assertIsString($commit);
        $spy = new class ($this->executor, $scripts, $read, $commit) implements RedisCommandExecutorInterface {
            public int $largest = 0;

            /** @var array<string, int> members a non-rotating observation read before it added its own */
            private array $before = [];

            /** @param list<mixed> $scripts */
            public function __construct(private readonly RedisCommandExecutorInterface $inner, private readonly array $scripts, private readonly string $read, private readonly string $commit) {}

            public function execute(array $command): mixed
            {
                $reply = $this->inner->execute($command);
                $result = OverlayWire::result($command, $reply);
                if (array_filter($this->scripts, static fn(mixed $script): bool => is_string($script) && OverlayWire::isScript($command, $script)) !== [] && is_array($result) && is_int($result[0] ?? null)) {
                    $this->largest = max($this->largest, $result[0]);
                }
                // A non-rotating bounded observation is read, then saved with its own member: the count it answers is
                // the members read plus the one it added.
                if (OverlayWire::isScript($command, $this->read) && is_array($result) && is_array($result[1] ?? null) && is_string($command[3] ?? null)) {
                    $this->before[$command[3]] = max(0, count($result[1]) - 1);
                }
                if (OverlayWire::isScript($command, $this->commit) && (string) ($command[2] ?? '') === '2' && is_string($command[3] ?? null)) {
                    $initialize = (string) ($command[7] ?? '') === '1';
                    $this->largest = max($this->largest, $initialize ? 1 : ($this->before[$command[3]] ?? 0) + (int) ($command[8] ?? 0));
                }

                return $reply;
            }
        };
        $limiter = RateLimiterBuilder::fromFullCapabilityStore($config, new RedisFullCapabilityStore($spy, $namespace), new RecordingFailureSignalEmitter())
            ->withClock(new FixedClock('2026-11-05 12:00:00'))
            ->build();
        $decisions = [];
        for ($i = 1; $i <= 12; $i++) {
            $context = new RateLimitContextDTO('203.0.113.7', 'Mozilla/5.0 Chrome/120.0', 'acct-alias', null, 'device-' . $i, false);
            $decisions[] = $limiter->limit($context, $checkOnly ? RateLimitCommand::checkOnly('login_protection') : RateLimitCommand::recordFailure('login_protection'))->decision;
        }

        return [$decisions, $spy->largest];
    }

    public function testRecordFailureUnderAFingerprintOnlyRotationCountsAsTheControlsDo(): void
    {
        $alias = $this->scenario(new RateLimiterConfig('key-1', 'fp-new', 'prod', null, 'fp-old'), self::NAMESPACE . '-alias-f', false);
        $none = $this->scenario(new RateLimiterConfig('key-1', 'fp-new', 'prod'), self::NAMESPACE . '-none-f', false);
        $full = $this->scenario(new RateLimiterConfig('key-1', 'fp-new', 'prod', 'key-0', 'fp-old'), self::NAMESPACE . '-full-f', false);

        self::assertSame(3, $alias[1], 'The effective correlation count reaches 3, not 2.');
        self::assertSame($none, $alias);
        self::assertSame($full, $alias);
    }

    public function testCheckOnlyUnderAFingerprintOnlyRotationCountsAsTheControlDoes(): void
    {
        $alias = $this->scenario(new RateLimiterConfig('key-1', 'fp-new', 'prod', null, 'fp-old'), self::NAMESPACE . '-alias-c', true);
        $none = $this->scenario(new RateLimiterConfig('key-1', 'fp-new', 'prod'), self::NAMESPACE . '-none-c', true);

        self::assertSame(3, $alias[1], 'The effective correlation count reaches 3, not 2.');
        self::assertSame($none, $alias);
    }
}
