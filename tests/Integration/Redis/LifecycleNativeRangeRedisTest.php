<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\DTO\HardBlockCycleResultDTO;
use Maatify\RateLimiter\DTO\PunishmentLifecycleTransitionDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\RateLimiter\Tests\Support\Redis\StateSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #242: the lifecycle scripts answer integers (`generation`, `updatedAt`, `expiresAt`, the block expiry, the pause expiry) that the CALLER's PHP must
 * hold as native `int`s. A call whose planned answer is outside that range is a classified rejection BEFORE the first write, expiry change or
 * deletion; a 32-bit worker used to write first and then fail to read its own answer back, while a 64-bit worker with the very same inputs succeeds and
 * must keep succeeding.
 *
 * Every scenario uses the SAME numbers on both widths (never the `PHP_INT_MAX` of each) — the caller's second 1.8e9, a generation of 2147483647 — and
 * `narrow` states what each width must do. A rejection is judged on the byte-exact DUMP and the exact PEXPIRETIME of EVERY key, an unrelated control
 * key included, on the direct primitive and inside `transactRequest()`. The `*Neighbour*` scenarios are the inputs one step inside the boundary, which
 * every width must answer.
 */
#[Group('integration')]
#[Group('php32')]
#[Group('lifecycle-native-range')]
final class LifecycleNativeRangeRedisTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const NAMESPACE = 'lifecycle-native';

    private const REJECTION = 'exceeds the caller native integer range';

    private const ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

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
        $this->executor->execute(['SET', 'unrelated-control', 'kept', 'EX', '5000']);
    }

    private function raw(string $family, string $logical): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':' . $family . ':' . hash('sha256', $logical);
    }

    private function seedScore(string $logical, string $value, string $updatedAt, string $generation, string $expiresAt, bool $evidence = false): void
    {
        $key = $this->raw('score', $logical);
        $fields = ['value', $value, 'updatedAt', $updatedAt, 'generation', $generation, 'expiresAt', $expiresAt];
        if ($evidence) {
            array_push($fields, 'reentryId', str_repeat('c', 32), 'reentryValidUntil', $expiresAt, 'reentryGeneration', $generation);
        }
        $this->executor->execute(['HSET', $key, ...$fields]);
        $this->executor->execute(['EXPIRE', $key, '600']);
    }

    private function state(string $source, int $value, int $updatedAt, int $expiresAt, int $generation): GenerationBoundScoreStateDTO
    {
        return new GenerationBoundScoreStateDTO($source, $value, $updatedAt, $expiresAt, $generation);
    }

    /**
     * @return array{0: callable(): void, 1: callable(): mixed, 2: bool, 3: callable(mixed): void} seed, the operation, whether a 32-bit worker must reject it, the 64-bit check
     */
    private function scenario(string $name): array
    {
        $applied = function (mixed $result, int $generation, int $expiresAt): void {
            self::assertInstanceOf(GenerationBoundScoreMutationDTO::class, $result);
            self::assertTrue($result->applied);
            self::assertSame($generation, $result->state?->generation);
            self::assertSame($expiresAt, $result->state->expiresAt);
        };
        $published = function (mixed $result, int $blockExpiry): void {
            self::assertInstanceOf(PunishmentLifecycleTransitionDTO::class, $result);
            self::assertTrue($result->applied);
            self::assertSame($blockExpiry, $result->block?->expiresAt);
        };
        $mutate = fn(?string $previous, ?GenerationBoundScoreStateDTO $expected, string $ms): callable => fn(): mixed => $this->store->mutateGenerationBoundScoreAtMilliseconds('gen', $previous, $expected, 600, 9, $ms);
        $publish = fn(int $duration): callable => fn(): mixed => $this->store->blockWithPunishmentLifecycleTrackingAt('gen', null, 1, self::ID, 2, $duration, 600, 3, 300, 86400, self::NOW);
        $nowMs = self::NOW . '000';

        return match ($name) {
            // The #242 original: an existing generation at 2^31 - 1 advanced by one.
            'increment past the native generation' => [
                fn() => $this->seedScore('gen', '8', (string) self::NOW, '2147483647', (string) (self::NOW + 601)),
                $mutate(null, $this->state('current', 8, self::NOW, self::NOW + 601, 2_147_483_647), $nowMs),
                true,
                fn($r) => $applied($r, 2_147_483_648, self::NOW + 601),
            ],
            'increment past the native generation of a Previous source' => [
                function (): void {
                    $this->seedScore('prev', '8', (string) self::NOW, '2147483647', (string) (self::NOW + 601));
                },
                $mutate('prev', $this->state('previous', 8, self::NOW, self::NOW + 601, 2_147_483_647), $nowMs),
                true,
                fn($r) => $applied($r, 2_147_483_648, self::NOW + 601),
            ],
            'neighbour: increment to the native generation' => [
                fn() => $this->seedScore('gen', '8', (string) self::NOW, '2147483646', (string) (self::NOW + 601)),
                $mutate(null, $this->state('current', 8, self::NOW, self::NOW + 601, 2_147_483_646), $nowMs),
                false,
                fn($r) => $applied($r, 2_147_483_647, self::NOW + 601),
            ],
            // A fresh Current whose planned expiry (2147484200) is past the native range.
            'fresh expiry past the native range' => [
                static function (): void {},
                $mutate(null, null, '2147483600000'),
                true,
                fn($r) => $applied($r, 1, 2_147_484_200),
            ],
            'neighbour: fresh expiry at the native maximum' => [
                static function (): void {},
                $mutate(null, null, '2147483047000'),
                false,
                fn($r) => $applied($r, 1, 2_147_483_647),
            ],
            'fresh expiry one second past the native maximum' => [
                static function (): void {},
                $mutate(null, null, '2147483048000'),
                true,
                fn($r) => $applied($r, 1, 2_147_483_648),
            ],
            // A fresh Current stamped at a second the caller cannot hold.
            'fresh updatedAt past the native range' => [
                static function (): void {},
                $mutate(null, null, '2147483648000'),
                true,
                fn($r) => $applied($r, 1, 2_147_484_248),
            ],
            // A logically expired Current (still physically present, with evidence and a claim marker) overwritten as a fresh epoch: the
            // rejection must precede every part of the overwrite (EXPIRE, evidence removal, marker deletion, HSET).
            'overwrite of an expired Current, expiry past the native range' => [
                function (): void {
                    $this->seedScore('gen', '8', '2147482000', '3', '2147482500', true);
                    $this->executor->execute(['SET', $this->raw('reentry-claim', 'gen'), 'marker', 'EX', '600']);
                },
                $mutate(null, null, '2147483600000'),
                true,
                fn($r) => $applied($r, 1, 2_147_484_200),
            ],
            'neighbour: overwrite of an expired Current inside the native range' => [
                function (): void {
                    $this->seedScore('gen', '8', '2147482000', '3', '2147482500', true);
                    $this->executor->execute(['SET', $this->raw('reentry-claim', 'gen'), 'marker', 'EX', '600']);
                },
                $mutate(null, null, '2147483047000'),
                false,
                fn($r) => $applied($r, 1, 2_147_483_647),
            ],
            // A stale snapshot is an ordinary unapplied answer, never a range rejection: it returns no integer.
            'stale snapshot at the native generation' => [
                fn() => $this->seedScore('gen', '8', (string) self::NOW, '2147483647', (string) (self::NOW + 601)),
                $mutate(null, $this->state('current', 7, self::NOW, self::NOW + 601, 2_147_483_647), $nowMs),
                false,
                function ($r): void {
                    self::assertInstanceOf(GenerationBoundScoreMutationDTO::class, $r);
                    self::assertFalse($r->applied);
                },
            ],
            // HARD_BLOCK lifecycle publication: the answered block expiry (now + duration = 3947483647) is past the native range.
            'publication block expiry past the native range' => [
                fn() => $this->seedScore('gen', '8', (string) self::NOW, '1', (string) (self::NOW + 600)),
                $publish(2_147_483_647),
                true,
                fn($r) => $published($r, 3_947_483_647),
            ],
            'neighbour: publication block expiry at the native maximum' => [
                fn() => $this->seedScore('gen', '8', (string) self::NOW, '1', (string) (self::NOW + 600)),
                $publish(347_483_647),
                false,
                fn($r) => $published($r, 2_147_483_647),
            ],
            'publication block expiry one second past the native maximum' => [
                fn() => $this->seedScore('gen', '8', (string) self::NOW, '1', (string) (self::NOW + 600)),
                $publish(347_483_648),
                true,
                fn($r) => $published($r, 2_147_483_648),
            ],
            // A score expiry stored by a 64-bit worker is answered by the publication: a 32-bit worker cannot hold it.
            'publication retained score expiry past the native range' => [
                fn() => $this->seedScore('gen', '8', (string) self::NOW, '1', '3947483647'),
                $publish(600),
                true,
                function ($r): void {
                    self::assertInstanceOf(PunishmentLifecycleTransitionDTO::class, $r);
                    self::assertTrue($r->applied);
                    self::assertSame(3_947_483_647, $r->postPunishmentReentry?->validUntil);
                },
            ],
            // A pause stored by a 64-bit worker, answered as `pauseUntil` by the publication.
            'publication pause expiry past the native range' => [
                function (): void {
                    $this->seedScore('gen', '8', (string) self::NOW, '1', (string) (self::NOW + 600));
                    $this->seedPause(self::NOW . ':3947483647');
                },
                $publish(600),
                true,
                function ($r): void {
                    self::assertInstanceOf(PunishmentLifecycleTransitionDTO::class, $r);
                    self::assertTrue($r->applied);
                    self::assertSame(3_947_483_647, $r->cycle?->pauseUntil);
                },
            ],
            'neighbour: publication pause expiry at the native maximum' => [
                function (): void {
                    $this->seedScore('gen', '8', (string) self::NOW, '1', (string) (self::NOW + 600));
                    $this->seedPause(self::NOW . ':2147483647');
                },
                $publish(600),
                false,
                function ($r): void {
                    self::assertInstanceOf(PunishmentLifecycleTransitionDTO::class, $r);
                    self::assertTrue($r->applied);
                    self::assertSame(2_147_483_647, $r->cycle?->pauseUntil);
                },
            ],
            // The cycle-only hard block answers the same stored pause.
            'cycle block pause expiry past the native range' => [
                fn() => $this->seedPause(self::NOW . ':3947483647'),
                fn(): mixed => $this->store->blockWithCycleTracking('gen', null, 2, 600, self::NOW, 600, 3, 300, 86400),
                true,
                function ($r): void {
                    self::assertInstanceOf(HardBlockCycleResultDTO::class, $r);
                    self::assertSame(3_947_483_647, $r->pauseUntil);
                },
            ],
            default => throw new \LogicException($name),
        };
    }

    private function seedPause(string $member): void
    {
        $key = $this->raw('pause', 'gen');
        [$start] = explode(':', $member);
        $this->executor->execute(['ZADD', $key, $start, $member]);
        $this->executor->execute(['EXPIRE', $key, '5000']);
    }

    /** @return array<string, array{string, bool}> */
    public static function scenarios(): array
    {
        $names = [
            'increment past the native generation',
            'increment past the native generation of a Previous source',
            'neighbour: increment to the native generation',
            'fresh expiry past the native range',
            'neighbour: fresh expiry at the native maximum',
            'fresh expiry one second past the native maximum',
            'fresh updatedAt past the native range',
            'overwrite of an expired Current, expiry past the native range',
            'neighbour: overwrite of an expired Current inside the native range',
            'stale snapshot at the native generation',
            'publication block expiry past the native range',
            'neighbour: publication block expiry at the native maximum',
            'publication block expiry one second past the native maximum',
            'publication retained score expiry past the native range',
            'publication pause expiry past the native range',
            'neighbour: publication pause expiry at the native maximum',
            'cycle block pause expiry past the native range',
        ];
        $cases = [];
        foreach ($names as $name) {
            foreach (['direct' => false, 'overlay' => true] as $path => $overlay) {
                $cases[$name . ' / ' . $path] = [$name, $overlay];
            }
        }

        return $cases;
    }

    /** The same fixed inputs: rejected before any write on 32-bit where the answer cannot be held, answered on 64-bit. */
    #[DataProvider('scenarios')]
    public function testTheSameInputsAreRejectedBeforeAnyWriteOn32BitAndAnsweredOn64Bit(string $name, bool $overlay): void
    {
        [$seed, $operation, $narrow, $check] = $this->scenario($name);
        $seed();
        $before = StateSnapshot::all($this->executor);
        $call = fn(): mixed => $overlay ? $this->store->transactRequest($operation) : $operation();

        if (PHP_INT_SIZE < 8 && $narrow) {
            $caught = null;
            try {
                $call();
            } catch (\Throwable $exception) {
                $caught = $exception;
            }
            // The state is judged first: a rejection that had already written or deleted something is the defect, whatever it raised.
            self::assertSame($before, StateSnapshot::all($this->executor), $name . ': no key, value, expiry or evidence changed, and none was created or deleted.');
            self::assertInstanceOf(RateLimiterException::class, $caught, $name . ': the call must be rejected.');
            self::assertStringContainsString(self::REJECTION, $caught->getMessage(), $name);

            return;
        }

        $check($call());
        if ($name === 'stale snapshot at the native generation') {
            self::assertSame($before, StateSnapshot::all($this->executor), 'A stale snapshot writes nothing.');
        }
    }

    /** Where the answer is held, the call really applied: the direct primitive changed the state (the overlay holds it until its commit). */
    public function testAnAnsweredCallChangesTheStateOnEveryWidth(): void
    {
        [$seed, $operation] = $this->scenario('neighbour: increment to the native generation');
        $seed();
        $before = StateSnapshot::all($this->executor);

        $operation();

        self::assertNotSame($before, StateSnapshot::all($this->executor));
        self::assertSame('2147483647', $this->executor->execute(['HGET', $this->raw('score', 'gen'), 'generation']));
    }

    /** A rejection leaves the key usable: once the caller's clock is back in range the same stored state still mutates. */
    public function testARejectedCallLeavesTheStateUsableForTheNextValidCall(): void
    {
        $this->seedScore('gen', '8', (string) self::NOW, '2147483646', (string) (self::NOW + 601));
        $state = $this->state('current', 8, self::NOW, self::NOW + 601, 2_147_483_646);
        $first = $this->store->mutateGenerationBoundScoreAtMilliseconds('gen', null, $state, 600, 9, self::NOW . '000');
        self::assertTrue($first->applied);
        self::assertSame(2_147_483_647, $first->state?->generation);

        $before = StateSnapshot::all($this->executor);
        $caught = null;
        $second = null;
        try {
            $second = $this->store->mutateGenerationBoundScoreAtMilliseconds('gen', null, $first->state, 600, 10, self::NOW . '000');
        } catch (RateLimiterException $exception) {
            $caught = $exception;
        }
        if (PHP_INT_SIZE < 8) {
            self::assertNotNull($caught, 'A 32-bit worker cannot hold generation 2147483648.');
            self::assertSame($before, StateSnapshot::all($this->executor));
        } else {
            self::assertNull($caught);
            self::assertNotNull($second);
            self::assertTrue($second->applied);
            self::assertSame(2_147_483_648, $second->state?->generation);
        }
    }
}
