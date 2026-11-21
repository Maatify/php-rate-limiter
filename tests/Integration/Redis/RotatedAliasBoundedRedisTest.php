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
use Maatify\RateLimiter\Tests\Support\Redis\ExactDecimal;
use Maatify\RateLimiter\Tests\Support\Redis\OverlayWire;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
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
#[Group('php32')]
final class RotatedAliasBoundedRedisTest extends TestCase
{
    /** The caller's instant, as the exact decimal epoch milliseconds the DEC-039 capability takes (1.8e12 does not fit a 32-bit `int`). */
    private const NOW_MS = '1800000000000';

    /** The same instant in whole seconds: it fits a native `int` on every width. */
    private const NOW_SECONDS = 1_800_000_000;

    private const NAMESPACE = 'f185-23';

    private const MAX = 10;

    /** 2^53 and 2^54 + 4, as exact decimals (a float on a 32-bit build must never encode a Redis expiry). */
    private const TWO_53 = '9007199254740992';

    private const ABOVE_2_54 = '18014398509481988';

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

    /** An exact non-negative integer reply (`PEXPIRETIME`, ...) as a decimal: a reply past the native range is a string on a 32-bit build. */
    private function exact(mixed $reply): string
    {
        return ExactDecimal::fromReply($reply);
    }

    /** The decimal PEXPIRETIME of `$key`, read from outside Lua. */
    private function expiryAt(string $key): string
    {
        return $this->exact($this->executor->execute(['PEXPIRETIME', $key]));
    }

    /**
     * #234: an input that is outside a 32-bit build's native `int` (a lifetime in seconds above 2^31 - 1) cannot be given to the public method
     * there at all. The case is skipped on that width only, with the domain and the 32-bit equivalent named; it is never clamped or approximated.
     */
    private function requiresNativeIntAbove32Bit(string $input, string $width32Coverage): void
    {
        if (PHP_INT_SIZE < 8) {
            self::markTestSkipped('Outside the 32-bit public input domain: ' . $input . ' 32-bit equivalent: ' . $width32Coverage);
        }
    }

    /** The bounded rotation through the DEC-039 decimal-millisecond capability, so the caller's instant is exact on every width. */
    private function rotate(string $current, string $bridge, string $previous, string $member, string $previousForm, int $ttlSeconds): BoundedDistinctSnapshotDTO
    {
        return $this->store()->addDistinctBoundedWithSnapshotAcrossRotationAtMilliseconds($current, $bridge, $previous, $member, $previousForm, $ttlSeconds, self::MAX, self::NOW_MS);
    }

    /** A live Previous set holding `$members`, as an earlier generation left it. */
    private function seedPrevious(string $logical, string ...$members): void
    {
        $this->executor->execute(['SADD', $this->raw('distinct', $logical), ...array_values($members)]);
        $this->executor->execute(['EXPIRE', $this->raw('distinct', $logical), '900']);
        $this->executor->execute(['HSET', $this->raw('distinct-meta', $logical), 'expiresAt', (string) (self::NOW_SECONDS + 900)]);
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
        return $this->rotate($logical, $logical . '-bridge', $logical, $member, 'prev-' . $member, 900);
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

    /**
     * The bridge ends with Previous, never after it: the absolute expiry (`PEXPIRETIME`) of the bridge's data key and of its
     * metadata key is not later than that of any key of Previous, with no 1 ms tolerance. A relative lifetime applied at a
     * later clock reading ended the bridge 1 ms after Previous in about one run in thirty on Redis 7.0.15.
     */
    public function testNoBridgeKeyOutlivesAnyPreviousKeyInAbsoluteTime(): void
    {
        for ($i = 0; $i < 400; $i++) {
            $logical = 'scope-T' . $i;
            $this->seedPrevious($logical, 'old');
            $previous = [
                $this->expiryAt($this->raw('distinct', $logical)),
                $this->expiryAt($this->raw('distinct-meta', $logical)),
            ];

            $snapshot = $this->aliased($logical, 'new');
            self::assertTrue($snapshot->added, 'The new member went to the bridge (iteration ' . $i . ').');

            foreach (['distinct', 'distinct-meta'] as $family) {
                // The exact PEXPIRETIME (a decimal: past 2^31 on a 32-bit build); a missing expiry (-1/-2) is refused by exact().
                $bridgeAt = $this->expiryAt($this->raw($family, $logical . '-bridge'));
                self::assertGreaterThan(0, ExactDecimal::compare($bridgeAt, '0'), 'The bridge key has an expiry.');
                self::assertLessThanOrEqual(0, ExactDecimal::compare($bridgeAt, ExactDecimal::min($previous)), 'The bridge ' . $family . ' key ends no later than Previous (iteration ' . $i . ').');
            }
        }
    }

    /** @return array<string, array{string}> */
    public static function previousScopes(): array
    {
        return ['Current is Previous (aliased)' => ['aliased'], 'Current differs from Previous' => ['distinct']];
    }

    /**
     * Previous ends at an absolute time just above 2^53 ms, which Lua reads as a rounded double, while its remaining
     * lifetime is far inside the exact range. The requested lifetime and the authoritative expiry are both beyond the
     * exact range, so the bridge is bounded by Previous alone. `PEXPIRETIME` is read from outside Lua, as exact integers:
     * no bridge key ends after any Previous key (no 1 ms tolerance), and the bridge does not collapse far below them.
     */
    #[DataProvider('previousScopes')]
    public function testTheBridgeEndsNoLaterThanPreviousWhenPreviousEndsAbovePrecisionButItsRemainingLifetimeIsExact(string $scope): void
    {
        $this->requiresNativeIntAbove32Bit(
            'the requested lifetime (9.1e12 s) is above 2^31 - 1 s, so a 32-bit caller cannot request it; the precision edge is the 64-bit Redis range.',
            'testPreviousAboveTheExactRangeBoundsTheBridgeByTheClockPlusALargeNativeLifetimeOnEveryWidth.',
        );
        $above = 2 ** 53;
        $requestedSeconds = 9_100_000_000_000;
        foreach ([1, 2, 3, 5, 1_001, 1_000_000_001, 1_000_000_000_001] as $offset) {
            foreach ([0, 1, 2] as $metaOffset) {
                $this->executor->execute(['FLUSHDB']);
                $logical = 'scope-precision';
                $previous = $this->raw('distinct', $logical);
                $previousMeta = $this->raw('distinct-meta', $logical);
                $this->executor->execute(['SADD', $previous, 'old']);
                $this->executor->execute(['PEXPIREAT', $previous, (string) ($above + $offset)]);
                $this->executor->execute(['HSET', $previousMeta, 'expiresAt', (string) $requestedSeconds]);
                $this->executor->execute(['PEXPIREAT', $previousMeta, (string) ($above + $offset + $metaOffset)]);
                self::assertLessThanOrEqual(9_007_199_254_740_991, $this->int($this->executor->execute(['PTTL', $previous])), 'The remaining lifetime is exact.');
                $previousAt = [
                    $this->int($this->executor->execute(['PEXPIRETIME', $previous])),
                    $this->int($this->executor->execute(['PEXPIRETIME', $previousMeta])),
                ];
                $current = $scope === 'aliased' ? $logical : 'scope-precision-current';

                $snapshot = $this->rotate($current, $logical . '-bridge', $logical, 'new', 'unknown', $requestedSeconds);
                self::assertTrue($snapshot->added, 'The new member went to the bridge (' . $offset . '/' . $metaOffset . ').');

                $label = $scope . ' offset ' . $offset . '/' . $metaOffset;
                self::assertSame($previousAt, [
                    $this->int($this->executor->execute(['PEXPIRETIME', $previous])),
                    $this->int($this->executor->execute(['PEXPIRETIME', $previousMeta])),
                ], 'Previous is untouched, ' . $label);
                foreach (['distinct', 'distinct-meta'] as $family) {
                    $bridgeAt = $this->int($this->executor->execute(['PEXPIRETIME', $this->raw($family, $logical . '-bridge')]));
                    self::assertLessThanOrEqual(min($previousAt), $bridgeAt, 'The bridge ' . $family . ' key ends no later than Previous, ' . $label);
                    self::assertGreaterThanOrEqual(min($previousAt) - 2, $bridgeAt, 'The bridge ' . $family . ' key is not shortened beyond the rounding bound, ' . $label);
                }
            }
        }
    }

    /**
     * #234, the 32-bit equivalent of the precision-edge test above (which a 32-bit caller cannot request: its lifetime is above 2^31 - 1 s).
     * Previous ends just above 2^53 ms, a rounded double in Lua; the largest lifetime with a native expiry second (about 2.1e9 s) is requested. The
     * bridge is then bounded by Redis's clock plus that lifetime, far below Previous: this checks the exact PEXPIRETIME of every key, that
     * Previous is untouched and that the bridge ends no later than it. It does NOT exercise the "bounded by Previous alone" branch; on a
     * 64-bit build the test above does. It also runs on 64-bit, where the same lifetime is native.
     */
    #[DataProvider('previousScopes')]
    public function testPreviousAboveTheExactRangeBoundsTheBridgeByTheClockPlusALargeNativeLifetimeOnEveryWidth(string $scope): void
    {
        foreach (['1', '1001', '1000000001', '1000000000001'] as $offset) {
            foreach (['0', '2'] as $metaOffset) {
                $this->executor->execute(['FLUSHDB']);
                $logical = 'scope-native-max';
                $previous = $this->raw('distinct', $logical);
                $previousMeta = $this->raw('distinct-meta', $logical);
                $this->executor->execute(['SADD', $previous, 'old']);
                $this->executor->execute(['PEXPIREAT', $previous, ExactDecimal::add(self::TWO_53, $offset)]);
                $this->executor->execute(['HSET', $previousMeta, 'expiresAt', self::previousExpiresAtSeconds()]);
                $this->executor->execute(['PEXPIREAT', $previousMeta, ExactDecimal::add(ExactDecimal::add(self::TWO_53, $offset), $metaOffset)]);
                $previousAt = [$this->expiryAt($previous), $this->expiryAt($previousMeta)];
                $current = $scope === 'aliased' ? $logical : 'scope-native-max-current';
                $label = $scope . ' offset ' . $offset . '/' . $metaOffset;

                $seconds = $this->largestLifetimeWithANativeExpirySecond();
                $before = $this->redisMilliseconds();
                $snapshot = $this->rotate($current, $logical . '-bridge', $logical, 'new', 'unknown', $seconds);
                $after = $this->redisMilliseconds();
                self::assertTrue($snapshot->added, 'The new member went to the bridge (' . $label . ').');

                self::assertSame($previousAt, [$this->expiryAt($previous), $this->expiryAt($previousMeta)], 'Previous is untouched, ' . $label);
                $lifetime = ExactDecimal::thousand((string) $seconds);
                foreach (['distinct', 'distinct-meta'] as $family) {
                    $bridgeAt = $this->expiryAt($this->raw($family, $logical . '-bridge'));
                    self::assertLessThanOrEqual(0, ExactDecimal::compare($bridgeAt, ExactDecimal::min($previousAt)), 'The bridge ' . $family . ' key ends no later than Previous, ' . $label);
                    self::assertGreaterThanOrEqual(0, ExactDecimal::compare($bridgeAt, ExactDecimal::subtract(ExactDecimal::add($before, $lifetime), '2000')), 'The bridge ' . $family . ' key lives the requested lifetime, ' . $label);
                    self::assertLessThanOrEqual(0, ExactDecimal::compare($bridgeAt, ExactDecimal::add(ExactDecimal::add($after, $lifetime), '2000')), 'The bridge ' . $family . ' key does not outlive it, ' . $label);
                }
            }
        }
    }

    /** The int and the DEC-039 decimal-millisecond capability give the same answer for an instant that fits a native int (64-bit only: the int form cannot carry the instant on 32-bit). */
    public function testTheNativeIntAndTheDecimalMillisecondCapabilitiesAnswerTheSame(): void
    {
        if (PHP_INT_SIZE < 8) {
            self::markTestSkipped('Outside the 32-bit public input domain: the int-millisecond method cannot receive 1.8e12. The decimal method is what every other test here uses on 32-bit.');
        }
        $viaInt = $this->store()->addDistinctBoundedWithSnapshotAcrossRotationAt('scope-I', 'scope-I-bridge', 'scope-I', 'm', 'prev-m', 900, self::MAX, (int) self::NOW_MS);
        $viaDecimal = $this->rotate('scope-J', 'scope-J-bridge', 'scope-J', 'm', 'prev-m', 900);

        self::assertSame([$viaInt->count, $viaInt->accepted, $viaInt->added], [$viaDecimal->count, $viaDecimal->accepted, $viaDecimal->added]);
    }

    /**
     * A bridge that cannot be bounded safely is refused before any write: Previous ends above 2^54 ms (no bound can be derived
     * from it) and the only exact lifetime is within a second of 2^53, where a clock reading could carry it past Previous.
     */
    public function testARotationWhoseBridgeCannotBeBoundedIsRefusedWithoutAnyWrite(): void
    {
        $this->requiresNativeIntAbove32Bit(
            'the refusal needs a requested lifetime of 9.007e12 s, above 2^31 - 1 s; on a 32-bit build no native lifetime reaches the exact-range refusal.',
            'the native-range rejections that write nothing are covered on 32-bit by CounterIncrementNativeRangeRedisTest and NumericBoundsRedisTest.',
        );
        $logical = 'scope-refused';
        $previous = $this->raw('distinct', $logical);
        $previousMeta = $this->raw('distinct-meta', $logical);
        $this->executor->execute(['SADD', $previous, 'old']);
        $this->executor->execute(['PEXPIREAT', $previous, self::ABOVE_2_54]);
        $this->executor->execute(['HSET', $previousMeta, 'expiresAt', '9100000000000']);
        $this->executor->execute(['PEXPIREAT', $previousMeta, self::ABOVE_2_54]);
        $keys = $this->executor->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        $before = $this->snapshotWithExpiry();

        try {
            $this->rotate($logical, $logical . '-bridge', $logical, 'new', 'unknown', 9_007_199_254_740);
            self::fail('The rotation was not refused.');
        } catch (\Maatify\RateLimiter\Exception\RateLimiterException $exception) {
            self::assertStringContainsString('exact integer range', $exception->getMessage());
        }

        self::assertSame($before, $this->snapshotWithExpiry(), 'Redis is unchanged: every key, its content and its expiry.');
        self::assertSame(0, $this->executor->execute(['EXISTS', $this->raw('distinct', $logical . '-bridge')]));
    }

    /** The bridge's expiry code of the rotation script (planning and applying) applies only PEXPIREAT, never a relative expiry. */
    public function testTheRotationScriptAppliesOnlyAbsoluteExpiriesToTheBridge(): void
    {
        $constant = (new ReflectionClass(RedisFullCapabilityStore::class))->getReflectionConstant('ROTATED_SNAPSHOT');
        self::assertNotFalse($constant);
        $text = $constant->getValue();
        self::assertIsString($text);
        $from = strpos($text, 'local function planBridgeExpiry()');
        $to = strpos($text, 'local function ensureBridge(plan)');
        self::assertIsInt($from);
        self::assertIsInt($to);
        $end = strpos($text, "\nend\n", $to);
        self::assertIsInt($end);
        $bridge = substr($text, $from, $end - $from);
        self::assertStringContainsString("expiryApplied(created, 'PEXPIREAT', key, plan.at)", $bridge, 'The helper judges its one expiry command through the shared expiry judge (DEC-043).');
        self::assertSame(3, substr_count($bridge, 'expireBridgeKey('), 'The helper and its two calls, one per bridge key.');
        foreach (['PEXPIRE', 'EXPIRE', 'PSETEX', 'SETEX'] as $relative) {
            self::assertStringNotContainsString("'" . $relative . "'", $bridge, 'The bridge gets no relative expiry (' . $relative . ').');
        }
    }

    /** @return array<string, array{string}> */
    public static function inexactRemainingLifetimes(): array
    {
        return [
            'Previous ends above 2^54 ms' => ['above-2-54'],
            'Previous ends 5 s past 2^53 ms from now (remaining lifetime above the exact range)' => ['just-above-exact-remaining'],
        ];
    }

    /** Redis's own clock in epoch milliseconds, as an exact decimal (a 32-bit `int` cannot hold it). */
    private function redisMilliseconds(): string
    {
        $time = $this->executor->execute(['TIME']);
        self::assertIsArray($time);

        self::assertIsString($time[0]);
        self::assertIsString($time[1]);

        return $time[0] . sprintf('%03d', intdiv((int) $time[1], 1000));
    }

    /**
     * The largest lifetime, in seconds, a 32-bit caller can request whose EXPIRY SECOND (the later of the caller's instant and Redis's now, plus the lifetime) is still a native
     * `int`, less an hour of margin for the run. A longer one makes a bounded snapshot write its keys and then fail to read its own
     * `expiresAt` back as an int (tracked separately, #234); that lifetime is deliberately not used here.
     */
    private function largestLifetimeWithANativeExpirySecond(): int
    {
        // The snapshot's `expiresAt` second comes from the caller's instant, the bridge's absolute expiry from Redis's clock: leave room for both.
        $now = max(self::NOW_SECONDS, (int) substr($this->redisMilliseconds(), 0, -3));

        return 2_147_483_647 - $now - 3_600;
    }

    /** @param string $expiryAt exact decimal absolute expiry in milliseconds */
    private function seedPreviousEndingAt(string $logical, string $expiryAt): void
    {
        $this->executor->execute(['SADD', $this->raw('distinct', $logical), 'old']);
        $this->executor->execute(['PEXPIREAT', $this->raw('distinct', $logical), $expiryAt]);
        $this->executor->execute(['HSET', $this->raw('distinct-meta', $logical), 'expiresAt', self::previousExpiresAtSeconds()]);
        $this->executor->execute(['PEXPIREAT', $this->raw('distinct-meta', $logical), $expiryAt]);
    }

    /**
     * The logical `expiresAt` second Previous's metadata carries. The snapshot answers it as a native `int`, so it is the 64-bit 9.1e12 s
     * only where that fits; on 32-bit it is a second a native int holds, later than every bridge expiry these cases request (below 2^31 - 1 s).
     */
    private static function previousExpiresAtSeconds(): string
    {
        return PHP_INT_SIZE >= 8 ? '9100000000000' : '2147483000';
    }

    /** The absolute expiry (decimal ms) the two "no exact clock" cases give Previous: above 2^54, or 5 s past 2^53 ms from Redis's now. */
    private function inexactPreviousEnd(string $case): string
    {
        return $case === 'above-2-54' ? self::ABOVE_2_54 : ExactDecimal::add(ExactDecimal::add($this->redisMilliseconds(), self::TWO_53), '5000');
    }

    /**
     * Neither Previous key has an exact remaining lifetime, so no exact clock exists. The bridge is still given an absolute
     * expiry (PEXPIREAT, never a relative one): it ends at the clock plus the requested lifetime, and never after Previous,
     * with no 1 ms tolerance. The lifetime requested here is the longest the rotation accepts.
     */
    #[DataProvider('inexactRemainingLifetimes')]
    public function testWithoutAnExactClockTheBridgeStillEndsAtAnAbsoluteTimeBeforePrevious(string $case): void
    {
        // 64-bit: the longest lifetime the rotation accepts (9.007e12 s). 32-bit: that lifetime is not a native int, so the longest one whose
        // expiry second is still a native int is the longest reachable; it does not reach the precision limit and the test does not claim it does.
        $longest = PHP_INT_SIZE >= 8 ? 9_007_199_254_730 : $this->largestLifetimeWithANativeExpirySecond();
        foreach (['aliased', 'distinct'] as $scope) {
            $this->executor->execute(['FLUSHDB']);
            $logical = 'scope-no-clock';
            $this->seedPreviousEndingAt($logical, $this->inexactPreviousEnd($case));
            $previousAt = [
                $this->expiryAt($this->raw('distinct', $logical)),
                $this->expiryAt($this->raw('distinct-meta', $logical)),
            ];
            $current = $scope === 'aliased' ? $logical : 'scope-no-clock-current';

            foreach ([60, $longest] as $seconds) {
                $this->executor->execute(['DEL', $this->raw('distinct', $logical . '-bridge'), $this->raw('distinct-meta', $logical . '-bridge')]);
                $before = $this->redisMilliseconds();
                $snapshot = $this->rotate($current, $logical . '-bridge', $logical, 'new', 'unknown', $seconds);
                $after = $this->redisMilliseconds();
                self::assertTrue($snapshot->added, $case . ' / ' . $scope . ' / ' . $seconds);
                $lifetime = ExactDecimal::thousand((string) $seconds);
                foreach (['distinct', 'distinct-meta'] as $family) {
                    $bridgeAt = $this->expiryAt($this->raw($family, $logical . '-bridge'));
                    self::assertLessThanOrEqual(0, ExactDecimal::compare($bridgeAt, ExactDecimal::min($previousAt)), 'The bridge ' . $family . ' key ends no later than Previous: ' . $case . ' / ' . $scope . ' / ' . $seconds);
                    self::assertGreaterThanOrEqual(0, ExactDecimal::compare($bridgeAt, ExactDecimal::subtract(ExactDecimal::add($before, $lifetime), '2000')), 'The bridge lives its requested lifetime.');
                    self::assertLessThanOrEqual(0, ExactDecimal::compare($bridgeAt, ExactDecimal::add(ExactDecimal::add($after, $lifetime), '2000')), 'The bridge does not outlive its requested lifetime.');
                }
            }
        }
    }

    /** One second more than the longest accepted lifetime: refused, with every key, its content and its expiry unchanged. */
    #[DataProvider('inexactRemainingLifetimes')]
    public function testWithoutAnExactClockALifetimeTooCloseToThePrecisionLimitIsRefusedWithoutAnyWrite(string $case): void
    {
        $this->requiresNativeIntAbove32Bit(
            'the refused lifetime (9.007e12 s) is above 2^31 - 1 s; no native lifetime on a 32-bit build is close to the precision limit.',
            'testWithoutAnExactClockTheBridgeStillEndsAtAnAbsoluteTimeBeforePrevious accepts the longest native lifetime on 32-bit and checks the exact bridge expiry.',
        );
        $logical = 'scope-no-clock-refused';
        $this->seedPreviousEndingAt($logical, $this->inexactPreviousEnd($case));
        $before = $this->snapshotWithExpiry();

        try {
            $this->rotate($logical, $logical . '-bridge', $logical, 'new', 'unknown', 9_007_199_254_740);
            self::fail('The rotation was not refused.');
        } catch (\Maatify\RateLimiter\Exception\RateLimiterException $exception) {
            self::assertStringContainsString('exact integer range', $exception->getMessage());
        }

        self::assertSame($before, $this->snapshotWithExpiry(), 'Redis is unchanged: every key, its content and its expiry.');
    }

    /** @return array<string, array{mixed, mixed}> */
    private function snapshotWithExpiry(): array
    {
        $keys = $this->executor->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        $state = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $state[$key] = [$this->executor->execute(['DUMP', $key]), $this->executor->execute(['PEXPIRETIME', $key])];
        }
        ksort($state);

        return $state;
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
        $first = $this->rotate('scope-Q', 'scope-Q-bridge', 'scope-Q', 'a', 'prev-a', 900);
        $again = $this->rotate('scope-Q', 'scope-Q-bridge', 'scope-Q', 'b', 'a', 900);

        self::assertSame([2, true, true], [$first->count, $first->accepted, $first->added]);
        self::assertSame([2, true, false], [$again->count, $again->accepted, $again->added]);
        self::assertSame(1, $this->int($this->executor->execute(['SCARD', $this->raw('distinct', 'scope-Q-bridge')])));
    }

    /** Controls: the non-rotated path and a rotation with a different, absent Previous give the same answers. */
    public function testTheNonRotatedAndRotatedControlsGiveTheSameAnswers(): void
    {
        $store = $this->store();
        self::assertSame(self::expected(0, 1, 12), self::observe(fn(string $m) => $store->addDistinctBoundedWithSnapshotAt('scope-B', $m, 900, self::MAX, self::NOW_SECONDS), 1, 12));
        self::assertSame(self::expected(0, 1, 12), self::observe(fn(string $m) => $this->rotate('scope-C', 'bridge-C', 'scope-C-prev', $m, 'prev-' . $m, 900), 1, 12));
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
