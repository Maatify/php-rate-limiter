<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #236: a bounded or rotated snapshot answers an `expiresAt` second the CALLER's PHP must hold as a native `int`. A call whose planned answer is above that
 * range is a classified rejection before any write (and before any logically expired Current or bridge is deleted); a 32-bit worker used to write the keys
 * and then fail to read its own answer back, a 64-bit worker with the very same inputs succeeds and must keep succeeding.
 *
 * Every case uses the SAME numbers on both widths (never `PHP_INT_MAX` of each): the caller's second 1.8e9 and a lifetime of 2147483647 s give the expiry
 * 3947483647, outside a 32-bit `int` and inside a 64-bit one. The expectation per width is stated by `narrow()`; the 64-bit run is the proof that the same
 * input is not rejected there. Rejections are judged on the byte-exact DUMP and the exact PEXPIRETIME of EVERY key, an unrelated control key included.
 */
#[Group('integration')]
#[Group('php32')]
#[Group('snapshot-expiry')]
final class SnapshotExpiryNativeRangeRedisTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const NOW_MS = '1800000000000';

    private const NAMESPACE = 'snapshot-expiry';

    private const REJECTION = 'snapshot expiry exceeds the caller native integer range';

    private RespRedisCommandExecutor $executor;

    private RedisFullCapabilityStore $store;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->executor = new RespRedisCommandExecutor($host, (int) $port);
        $this->executor->execute(['FLUSHDB']);
        $this->store = new RedisFullCapabilityStore($this->executor, self::NAMESPACE);
        // An unrelated control key: a rejected call must not touch it either.
        $this->executor->execute(['SET', 'unrelated-control', 'kept']);
        $this->executor->execute(['EXPIRE', 'unrelated-control', '5000']);
    }

    /** A 32-bit `int` cannot hold the 3947483647 these inputs answer; the same inputs succeed on 64-bit. */
    private static function narrow(): bool
    {
        return PHP_INT_SIZE < 8;
    }

    private function raw(string $family, string $logical): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':' . $family . ':' . hash('sha256', $logical);
    }

    /** A live (or stale) set with its metadata: logical `expiresAt` and a 3600 s physical TTL, whatever the logical expiry says. */
    private function seed(string $logical, string $expiresAt, string ...$members): void
    {
        $this->executor->execute(['SADD', $this->raw('distinct', $logical), ...array_values($members)]);
        $this->executor->execute(['EXPIRE', $this->raw('distinct', $logical), '3600']);
        $this->executor->execute(['HSET', $this->raw('distinct-meta', $logical), 'expiresAt', $expiresAt]);
        $this->executor->execute(['EXPIRE', $this->raw('distinct-meta', $logical), '3600']);
    }

    /** @return array<string, array{string, int|string}> byte-exact DUMP and exact PEXPIRETIME of every key */
    private function everything(): array
    {
        $keys = $this->executor->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        sort($keys);
        $all = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $dump = $this->executor->execute(['DUMP', $key]);
            self::assertIsString($dump);
            $expiry = $this->executor->execute(['PEXPIRETIME', $key]);
            self::assertTrue(is_int($expiry) || is_string($expiry));
            $all[$key] = [bin2hex($dump), $expiry];
        }

        return $all;
    }

    /** @return array<string, array{string, bool}> kind, overlay */
    public static function paths(): array
    {
        $cases = [];
        foreach (['bounded' => 'bounded', 'rotated distinct' => 'rotated', 'rotated alias' => 'alias'] as $label => $kind) {
            foreach (['direct' => false, 'overlay' => true] as $path => $overlay) {
                $cases[$label . ' / ' . $path] = [$kind, $overlay];
            }
        }

        return $cases;
    }

    /** @return array<string, array{string, bool}> the rotated kinds only (they have a Previous) */
    public static function rotatedPaths(): array
    {
        return array_filter(self::paths(), static fn(array $case): bool => $case[0] !== 'bounded');
    }

    /** @return array<string, array{string, bool}> the kinds whose Current is its own set (not the aliased Previous) */
    public static function currentPaths(): array
    {
        return array_filter(self::paths(), static fn(array $case): bool => $case[0] !== 'alias');
    }

    /** @return array<string, array{string, bool, int}> kind, overlay, the second a stale set's logical expiry is given */
    public static function stalePaths(): array
    {
        $cases = [];
        foreach (self::currentPaths() as $label => [$kind, $overlay]) {
            foreach (['one second past' => self::NOW - 1, 'exactly at the caller second' => self::NOW] as $boundary => $expiresAt) {
                $cases[$label . ' / expired ' . $boundary] = [$kind, $overlay, $expiresAt];
            }
        }

        return $cases;
    }

    /** The operation through the public store method of `$kind`, directly or inside `transactRequest()`. */
    private function operate(string $kind, int $ttl, bool $overlay): BoundedDistinctSnapshotDTO
    {
        $call = fn(): BoundedDistinctSnapshotDTO => match ($kind) {
            'bounded' => $this->store->addDistinctBoundedWithSnapshotAt('c', 'new', $ttl, 5, self::NOW),
            'rotated' => $this->store->addDistinctBoundedWithSnapshotAcrossRotationAtMilliseconds('c', 'b', 'p', 'new', 'unknown', $ttl, 5, self::NOW_MS),
            'alias' => $this->store->addDistinctBoundedWithSnapshotAcrossRotationAtMilliseconds('p', 'b', 'p', 'new', 'unknown', $ttl, 5, self::NOW_MS),
            default => throw new \LogicException($kind),
        };

        return $overlay ? $this->store->transactRequest($call) : $call();
    }

    /** The call is rejected with the classified message and every key (control included) is byte- and millisecond-identical. */
    private function assertRejectedUnchanged(callable $call, string $label, string $message = self::REJECTION): void
    {
        $before = $this->everything();
        $caught = null;
        try {
            $call();
        } catch (\Throwable $exception) {
            $caught = $exception;
        }
        // The state is judged first: a rejection that had already written or deleted something is the defect, whatever it raised.
        self::assertSame($before, $this->everything(), $label . ': no key, value, metadata or expiry changed, and none was created or deleted.');
        self::assertInstanceOf(RateLimiterException::class, $caught, $label . ': the call must be rejected.');
        self::assertStringContainsString($message, $caught->getMessage(), $label);
    }

    /**
     * Rejected on a 32-bit worker without any change, answered on a 64-bit one with the same input.
     *
     * @return BoundedDistinctSnapshotDTO|null the answer on 64-bit; null where it was rejected
     */
    private function operateOrReject(string $kind, int $ttl, bool $overlay, string $label): ?BoundedDistinctSnapshotDTO
    {
        if (self::narrow()) {
            $this->assertRejectedUnchanged(fn() => $this->operate($kind, $ttl, $overlay), $label);

            return null;
        }

        return $this->operate($kind, $ttl, $overlay);
    }

    // ---- absent state: the fresh epoch's expiry ------------------------------------------------------------------------------------

    #[DataProvider('paths')]
    public function testTheSameInputIsRejectedOn32BitWithoutAnyWriteAndSucceedsOn64Bit(string $kind, bool $overlay): void
    {
        $answer = $this->operateOrReject($kind, 2_147_483_647, $overlay, $kind);

        if (! self::narrow()) {
            self::assertNotNull($answer);
            self::assertSame(3_947_483_647, $answer->expiresAt, 'The 64-bit worker keeps its whole range for the very same inputs.');
            self::assertTrue($answer->added);
        }
    }

    /** Expiry 2147483647 exactly is representable everywhere; one second more is not on 32-bit and is on 64-bit. */
    #[DataProvider('paths')]
    public function testAnExpiryExactlyAtTheNativeMaximumIsAnsweredAndOneSecondBeyondIsNot(string $kind, bool $overlay): void
    {
        $atMaximum = $this->operate($kind, 2_147_483_647 - self::NOW, $overlay);
        self::assertSame(2_147_483_647, $atMaximum->expiresAt, 'The largest second a 32-bit int holds is answered on every width.');
        self::assertTrue($atMaximum->added);

        $this->executor->execute(['FLUSHDB']);
        $this->executor->execute(['SET', 'unrelated-control', 'kept']);
        $this->executor->execute(['EXPIRE', 'unrelated-control', '5000']);
        $beyond = $this->operateOrReject($kind, 2_147_483_647 - self::NOW + 1, $overlay, $kind . ' one second beyond');
        if (! self::narrow()) {
            self::assertNotNull($beyond);
            self::assertSame(2_147_483_648, $beyond->expiresAt);
        }
    }

    // ---- an active epoch is retained, not renewed ----------------------------------------------------------------------------

    /**
     * The requested lifetime (2147483647 s) is NOT used when an active epoch is retained: its stored, representable expiry is answered and neither
     * key's expiry changes. It must neither be renewed nor be refused because of an unused lifetime.
     */
    #[DataProvider('paths')]
    public function testARetainedActiveEpochDoesNotUseTheRequestedLifetimeAndIsNotRenewed(string $kind, bool $overlay): void
    {
        $this->seed($kind === 'alias' ? 'p' : 'c', (string) (self::NOW + 900), 'old');
        if ($kind === 'rotated') {
            $this->seed('p', (string) (self::NOW + 900), 'older');
        }
        $before = $this->everything();
        $expiries = array_map(static fn(array $state): int|string => $state[1], $before);

        $answer = $this->operate($kind, 2_147_483_647, $overlay);

        self::assertSame(self::NOW + 900, $answer->expiresAt, 'The stored epoch is answered, not now + the requested lifetime.');
        $after = $this->everything();
        foreach ($expiries as $key => $expiry) {
            self::assertSame($expiry, $after[$key][1] ?? null, 'The absolute expiry of an existing key is not renewed: ' . $key);
        }
        foreach (['distinct-meta'] as $family) {
            foreach (['c', 'p'] as $logical) {
                $key = $this->raw($family, $logical);
                if (isset($before[$key])) {
                    self::assertSame($before[$key], $after[$key], 'Metadata bytes and expiry are untouched: ' . $logical);
                }
            }
        }
    }

    // ---- state already outside the caller's range (written by a 64-bit worker) --------------------------------------------------

    /** An active Current whose stored expiry (2147483648) was written by a 64-bit worker: rejected on 32-bit, answered on 64-bit. */
    #[DataProvider('currentPaths')]
    public function testAStoredCurrentExpiryOutsideTheCallersRangeIsRejectedOn32BitAndAnsweredOn64Bit(string $kind, bool $overlay): void
    {
        $this->seed('c', '2147483648', 'old');
        $answer = $this->operateOrReject($kind, 60, $overlay, $kind);

        if (! self::narrow()) {
            self::assertNotNull($answer);
            self::assertSame(2_147_483_648, $answer->expiresAt);
            self::assertSame(2, $answer->count, 'The stored member and the new one.');
        }
    }

    /**
     * An active Previous with stored expiry 2147483648 and a 60 s lifetime: the rotated answer is Previous's expiry. Rejected on 32-bit with Previous, Current
     * and bridge absent keys all unchanged; answered on 64-bit with Previous still read-only.
     */
    #[DataProvider('rotatedPaths')]
    public function testAnActivePreviousOutsideTheCallersRangeIsRejectedOn32BitAndIsReadOnlyOn64Bit(string $kind, bool $overlay): void
    {
        $this->seed('p', '2147483648', 'old');
        $previousBefore = [$this->raw('distinct', 'p') => null, $this->raw('distinct-meta', 'p') => null];
        $state = $this->everything();
        foreach (array_keys($previousBefore) as $key) {
            $previousBefore[$key] = $state[$key];
        }

        $answer = $this->operateOrReject($kind, 60, $overlay, $kind);

        if (! self::narrow()) {
            self::assertNotNull($answer);
            self::assertSame(2_147_483_648, $answer->expiresAt);
            $after = $this->everything();
            foreach ($previousBefore as $key => $expected) {
                if ($kind === 'alias') {
                    continue; // the alias set IS Previous: it is read-only, but its own DUMP is asserted unchanged below
                }
                self::assertSame($expected, $after[$key], 'Previous is read-only: ' . $key);
            }
            if ($kind === 'alias') {
                foreach ($previousBefore as $key => $expected) {
                    self::assertSame($expected, $after[$key], 'The aliased Previous is read-only: ' . $key);
                }
            }
        }
    }

    /** Previous expiry exactly at the native maximum (2147483647) is representable on every width; Previous stays read-only. */
    #[DataProvider('rotatedPaths')]
    public function testAnActivePreviousExactlyAtTheNativeMaximumIsAnsweredOnEveryWidth(string $kind, bool $overlay): void
    {
        $this->seed('p', '2147483647', 'old');
        $state = $this->everything();
        $previous = [$this->raw('distinct', 'p') => $state[$this->raw('distinct', 'p')], $this->raw('distinct-meta', 'p') => $state[$this->raw('distinct-meta', 'p')]];

        $answer = $this->operate($kind, 60, $overlay);

        self::assertSame(2_147_483_647, $answer->expiresAt);
        $after = $this->everything();
        foreach ($previous as $key => $expected) {
            if ($kind === 'alias' && str_contains($key, ':distinct:')) {
                continue; // the bridge-writing alias branch never writes the aliased set; its metadata is compared below
            }
            self::assertSame($expected, $after[$key], 'Previous is read-only: ' . $key);
        }
        self::assertSame($previous[$this->raw('distinct-meta', 'p')], $after[$this->raw('distinct-meta', 'p')]);
    }

    // ---- logically expired state that is still physically present ----------------------------------------------------------------

    /**
     * #236 ordering (the F-232-01 shape in the snapshot path): the fresh epoch's expiry (3947483647) is unrepresentable on 32-bit, so the call is rejected
     * and the logically expired Current — still physically present — must NOT have been deleted. On 64-bit the same input replaces it.
     */
    #[DataProvider('stalePaths')]
    public function testARejectedCallDoesNotDeleteAnExpiredButPresentCurrent(string $kind, bool $overlay, int $expiresAt): void
    {
        $this->seed('c', (string) $expiresAt, 'stale-member');
        $answer = $this->operateOrReject($kind, 2_147_483_647, $overlay, $kind);

        if (! self::narrow()) {
            self::assertNotNull($answer);
            self::assertSame(3_947_483_647, $answer->expiresAt);
            self::assertSame(1, $this->executor->execute(['SCARD', $this->raw('distinct', 'c')]), 'The stale member was replaced on 64-bit.');
        }
    }

    /**
     * Previous (2147483648) is active and unrepresentable on 32-bit; Current and the bridge are logically expired but present. Rejected: all four stale keys
     * and Previous's two are still there, byte- and millisecond-identical. On 64-bit the same input rebuilds Current and the bridge.
     */
    #[DataProvider('staleRotatedPaths')]
    public function testARejectedRotationDoesNotDeleteAnExpiredButPresentCurrentOrBridge(string $kind, bool $overlay, int $expiresAt): void
    {
        $this->seed('p', '2147483648', 'old');
        $this->seed('c', (string) $expiresAt, 'stale-current');
        $this->seed('b', (string) $expiresAt, 'stale-bridge');
        self::assertCount(7, $this->everything(), 'Six physical snapshot keys and the control key.');

        $answer = $this->operateOrReject($kind, 60, $overlay, $kind);

        if (! self::narrow()) {
            self::assertNotNull($answer);
            self::assertSame(2_147_483_648, $answer->expiresAt);
            self::assertSame(['new'], $this->executor->execute(['SMEMBERS', $this->raw('distinct', 'b')]), 'The stale bridge was replaced on 64-bit.');
            self::assertSame(['new'], $this->executor->execute(['SMEMBERS', $this->raw('distinct', 'c')]), 'The stale Current was replaced on 64-bit.');
        }
    }

    // ---- wrappers and adjacent paths ---------------------------------------------------------------------------------------------

    /** @return array<string, array{string, bool, int}> the rotated kinds with a stale Current and bridge */
    public static function staleRotatedPaths(): array
    {
        return array_filter(self::stalePaths(), static fn(array $case): bool => $case[0] === 'rotated');
    }

    /** @return array<string, array{string}> */
    public static function wrappers(): array
    {
        return [
            'addDistinctBoundedAt' => ['boundedResult'],
            'addDistinctBoundedAcrossRotationAtMilliseconds' => ['rotatedResult'],
            'addDistinctAcrossRotationAtMilliseconds' => ['rotatedCount'],
            'addDistinctAcrossRotationAt' => ['rotatedCountInt'],
            'addDistinctBoundedAcrossRotationAt' => ['rotatedResultInt'],
        ];
    }

    /** The methods that delegate through a snapshot and return only a count or a result are affected exactly as the snapshot methods are. */
    #[DataProvider('wrappers')]
    public function testTheMethodsThatDelegateThroughASnapshotAreRejectedOn32BitWithoutAnyWrite(string $wrapper): void
    {
        $call = fn(): mixed => match ($wrapper) {
            'boundedResult' => $this->store->addDistinctBoundedAt('c', 'new', 2_147_483_647, 5, self::NOW),
            'rotatedResult' => $this->store->addDistinctBoundedAcrossRotationAtMilliseconds('c', 'b', 'p', 'new', 'unknown', 2_147_483_647, 5, self::NOW_MS),
            'rotatedCount' => $this->store->addDistinctAcrossRotationAtMilliseconds('c', 'b', 'p', 'new', 'unknown', 2_147_483_647, self::NOW_MS),
            'rotatedCountInt' => $this->store->addDistinctAcrossRotationAt('c', 'b', 'p', 'new', 'unknown', 2_147_483_647, (int) self::NOW_MS),
            'rotatedResultInt' => $this->store->addDistinctBoundedAcrossRotationAt('c', 'b', 'p', 'new', 'unknown', 2_147_483_647, 5, (int) self::NOW_MS),
            default => throw new \LogicException($wrapper),
        };
        if (self::narrow() && in_array($wrapper, ['rotatedCountInt', 'rotatedResultInt'], true)) {
            self::markTestSkipped('Outside the 32-bit public input domain: the int-millisecond methods cannot receive 1.8e12; the decimal-millisecond wrappers above cover 32-bit.');
        }

        if (self::narrow()) {
            $this->assertRejectedUnchanged($call, $wrapper);

            return;
        }
        $call();
        self::assertNotSame([], $this->executor->execute(['KEYS', '*:distinct:*']), 'The 64-bit worker wrote its snapshot.');
    }

    /** The count-only `addDistinct()` answers no expiry: it is not affected and succeeds on every width with the same inputs. */
    public function testTheCountOnlyAddDistinctIsNotAffected(): void
    {
        self::assertSame(1, $this->store->addDistinctAt('plain', 'member', 2_147_483_647, self::NOW));
    }

    // ---- a public request --------------------------------------------------------------------------------------------------------

    /**
     * A public request at the Y2038 edge (03:10:00 UTC, 247 s before 2^31 - 1): its correlation windows would expire past the last second a 32-bit `int`
     * holds. On a 32-bit worker the request is rejected as a whole and nothing is saved (every key, byte- and millisecond-exact); on 64-bit the same request
     * is served. `$previousFingerprint` selects a fingerprint-only rotation, whose bounded snapshot goes through the rotated Lua script of this issue; without
     * it the request takes the device-cap observation, which already rejected in PHP, before its commit (unchanged, covered here as the control).
     */
    #[DataProvider('publicRequests')]
    public function testAPublicRequestAtTheYear2038EdgeSavesNothingOn32BitAndIsServedOn64Bit(?string $previousFingerprint, string $message): void
    {
        $limiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('key-1', 'fp-new', 'prod', null, $previousFingerprint),
            new RedisFullCapabilityStore($this->executor, self::NAMESPACE . '-public'),
            new RecordingFailureSignalEmitter(),
        )->withClock(new FixedClock('@2147483400'))->build();
        $context = new RateLimitContextDTO('192.0.2.1', 'ua', 'account-2038', null, 'device-2038', false);

        if (self::narrow()) {
            $this->assertRejectedUnchanged(fn() => $limiter->limit($context, RateLimitCommand::recordFailure('login_protection')), 'public request', $message);

            return;
        }
        $limiter->limit($context, RateLimitCommand::recordFailure('login_protection'));
        self::assertNotSame([], $this->executor->execute(['KEYS', '*']));
    }

    /** @return array<string, array{?string, string}> */
    public static function publicRequests(): array
    {
        return [
            'fingerprint rotation (rotated snapshot script)' => ['fp-old', self::REJECTION],
            'no rotation (device-cap observation, rejected in PHP before its commit)' => [null, 'expiry is not representable'],
        ];
    }
}
