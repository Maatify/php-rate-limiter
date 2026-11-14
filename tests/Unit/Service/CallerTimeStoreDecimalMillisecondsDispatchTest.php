<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Service;

use DateTimeImmutable;
use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\BoundedCorrelationRotationStoreInterface;
use Maatify\RateLimiter\Repository\BoundedCorrelationSnapshotRotationStoreInterface;
use Maatify\RateLimiter\Repository\CallerTimeBoundedCorrelationRotationStoreInterface;
use Maatify\RateLimiter\Repository\CallerTimeBoundedCorrelationSnapshotRotationStoreInterface;
use Maatify\RateLimiter\Repository\CallerTimeCorrelationRotationStoreInterface;
use Maatify\RateLimiter\Repository\CallerTimeDecimalMillisecondsBoundedCorrelationRotationStoreInterface;
use Maatify\RateLimiter\Repository\CallerTimeDecimalMillisecondsBoundedCorrelationSnapshotRotationStoreInterface;
use Maatify\RateLimiter\Repository\CallerTimeDecimalMillisecondsCorrelationRotationStoreInterface;
use Maatify\RateLimiter\Repository\CallerTimeDecimalMillisecondsPunishmentLifecycleStoreInterface;
use Maatify\RateLimiter\Repository\CallerTimePunishmentLifecycleStoreInterface;
use Maatify\RateLimiter\Repository\CorrelationRotationStoreInterface;
use Maatify\RateLimiter\Repository\PunishmentLifecycleStoreInterface;
use Maatify\RateLimiter\Service\CallerTimeStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The six millisecond dispatch sites of `CallerTimeStore` (#215, DEC-039), each with a store that implements the decimal capability,
 * a store that only has the native-integer caller-time method, and a store that keeps its own clock. The instant is a contemporary
 * epoch time whose millisecond value does not fit a native integer where `PHP_INT_SIZE` is 4.
 */
#[Group('php32')]
final class CallerTimeStoreDecimalMillisecondsDispatchTest extends TestCase
{
    private const NOW = '2026-11-14 10:00:00.123456 UTC';

    private const EXACT = '1794650400123';

    /** An instant whose millisecond value fits a native integer on every PHP build. */
    private const SMALL_NOW = '1970-01-01 00:00:01.042 UTC';

    /**
     * Each site: [decimal mock class, legacy mock class, expected method of both, the call into CallerTimeStore, extra expected arguments before the time].
     *
     * @return iterable<string, array{class-string<object>, class-string<object>, string, string}>
     */
    public static function sites(): iterable
    {
        yield 'addDistinctAcrossRotation' => [
            CallerTimeDecimalMillisecondsCorrelationRotationStoreInterface::class,
            CallerTimeCorrelationRotationStoreInterface::class,
            'addDistinctAcrossRotation',
            'addDistinctAcrossRotationAt',
        ];
        yield 'addDistinctBoundedAcrossRotation' => [
            CallerTimeDecimalMillisecondsBoundedCorrelationRotationStoreInterface::class,
            CallerTimeBoundedCorrelationRotationStoreInterface::class,
            'addDistinctBoundedAcrossRotation',
            'addDistinctBoundedAcrossRotationAt',
        ];
        yield 'addDistinctBoundedWithSnapshotAcrossRotation' => [
            CallerTimeDecimalMillisecondsBoundedCorrelationSnapshotRotationStoreInterface::class,
            CallerTimeBoundedCorrelationSnapshotRotationStoreInterface::class,
            'addDistinctBoundedWithSnapshotAcrossRotation',
            'addDistinctBoundedWithSnapshotAcrossRotationAt',
        ];
        yield 'readGenerationBoundScoreState' => [
            CallerTimeDecimalMillisecondsPunishmentLifecycleStoreInterface::class,
            CallerTimePunishmentLifecycleStoreInterface::class,
            'readGenerationBoundScoreState',
            'readGenerationBoundScoreStateAt',
        ];
        yield 'mutateGenerationBoundScore' => [
            CallerTimeDecimalMillisecondsPunishmentLifecycleStoreInterface::class,
            CallerTimePunishmentLifecycleStoreInterface::class,
            'mutateGenerationBoundScore',
            'mutateGenerationBoundScoreAt',
        ];
        yield 'claimPostPunishmentReentry' => [
            CallerTimeDecimalMillisecondsPunishmentLifecycleStoreInterface::class,
            CallerTimePunishmentLifecycleStoreInterface::class,
            'claimPostPunishmentReentry',
            'claimPostPunishmentReentryAt',
        ];
    }

    /**
     * @param class-string<object> $decimalClass
     * @param class-string<object> $legacyClass
     */
    #[DataProvider('sites')]
    public function testAStoreWithTheDecimalCapabilityReceivesTheExactValueAndNeverTheNativeMethod(string $decimalClass, string $legacyClass, string $site, string $legacyMethod): void
    {
        $store = $this->createMock($decimalClass);
        $store->expects(self::once())->method($legacyMethod . 'Milliseconds')
            ->with(...$this->expectedArguments($site, self::EXACT))
            ->willReturn($this->returnFor($site));
        $store->expects(self::never())->method($legacyMethod);
        $store->expects(self::never())->method($site);

        $this->dispatch($site, $store, new DateTimeImmutable(self::NOW));
    }

    /**
     * @param class-string<object> $decimalClass
     * @param class-string<object> $legacyClass
     */
    #[DataProvider('sites')]
    public function testAStoreWithOnlyTheNativeMethodIsCalledWithAnIntegerWhenTheValueFits(string $decimalClass, string $legacyClass, string $site, string $legacyMethod): void
    {
        $store = $this->createMock($legacyClass);
        $store->expects(self::once())->method($legacyMethod)
            ->with(...$this->expectedArguments($site, 1042))
            ->willReturn($this->returnFor($site));
        $store->expects(self::never())->method($site);

        $this->dispatch($site, $store, new DateTimeImmutable(self::SMALL_NOW));
    }

    /**
     * @param class-string<object> $decimalClass
     * @param class-string<object> $legacyClass
     */
    #[DataProvider('sites')]
    public function testAStoreWithOnlyTheNativeMethodBehavesAsBeforeOn64BitAndIsRejectedBeforeTheCallOn32Bit(string $decimalClass, string $legacyClass, string $site, string $legacyMethod): void
    {
        $store = $this->createMock($legacyClass);
        if (PHP_INT_SIZE === 8) {
            $store->expects(self::once())->method($legacyMethod)
                ->with(...$this->expectedArguments($site, 1794650400123))
                ->willReturn($this->returnFor($site));
            $store->expects(self::never())->method($site);
            $this->dispatch($site, $store, new DateTimeImmutable(self::NOW));

            return;
        }

        // The clock is never changed silently: the native method is not invoked and nothing falls back to the store's own clock.
        $store->expects(self::never())->method($legacyMethod);
        $store->expects(self::never())->method($site);
        try {
            $this->dispatch($site, $store, new DateTimeImmutable(self::NOW));
            self::fail('A native-integer-only store must be rejected for a value that is not representable.');
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('not representable as a native integer on this PHP build', $exception->getMessage());
        }
    }

    /**
     * @param class-string<object> $decimalClass
     * @param class-string<object> $legacyClass
     */
    #[DataProvider('sites')]
    public function testAStoreWithoutAnyCallerTimeCapabilityKeepsItsOwnClock(string $decimalClass, string $legacyClass, string $site, string $legacyMethod): void
    {
        $baseInterface = match ($site) {
            'addDistinctAcrossRotation' => CorrelationRotationStoreInterface::class,
            'addDistinctBoundedAcrossRotation' => BoundedCorrelationRotationStoreInterface::class,
            'addDistinctBoundedWithSnapshotAcrossRotation' => BoundedCorrelationSnapshotRotationStoreInterface::class,
            default => PunishmentLifecycleStoreInterface::class,
        };
        $store = $this->createMock($baseInterface);
        $store->expects(self::once())->method($site)->willReturn($this->returnFor($site));

        $this->dispatch($site, $store, new DateTimeImmutable(self::NOW));
    }

    /** @return list<mixed> the arguments the store must receive before and including the time, for `$site`. */
    private function expectedArguments(string $site, int|string $time): array
    {
        return match ($site) {
            'addDistinctAcrossRotation' => ['cur', 'bridge', 'prev', 'm1', 'm0', 30, $time],
            'addDistinctBoundedAcrossRotation', 'addDistinctBoundedWithSnapshotAcrossRotation' => ['cur', 'bridge', 'prev', 'm1', 'm0', 30, 7, $time],
            'readGenerationBoundScoreState' => ['k4', 'k4-prev', $time],
            'mutateGenerationBoundScore' => ['k4', 'k4-prev', null, 30, 5, $time],
            default => ['k4', 'k4-prev', 'life-1', $time],
        };
    }

    private function returnFor(string $site): mixed
    {
        return match ($site) {
            'addDistinctAcrossRotation' => 1,
            'addDistinctBoundedAcrossRotation' => new BoundedDistinctResultDTO(1, true),
            'addDistinctBoundedWithSnapshotAcrossRotation' => new BoundedDistinctSnapshotDTO(1, true, true, ['m1'], 1794651000),
            'readGenerationBoundScoreState' => null,
            'mutateGenerationBoundScore' => new GenerationBoundScoreMutationDTO(false, null),
            default => true,
        };
    }

    private function dispatch(string $site, object $store, DateTimeImmutable $now): void
    {
        match ($site) {
            'addDistinctAcrossRotation' => CallerTimeStore::addDistinctAcrossRotation(self::as(CorrelationRotationStoreInterface::class, $store), 'cur', 'bridge', 'prev', 'm1', 'm0', 30, $now),
            'addDistinctBoundedAcrossRotation' => CallerTimeStore::addDistinctBoundedAcrossRotation(self::as(BoundedCorrelationRotationStoreInterface::class, $store), 'cur', 'bridge', 'prev', 'm1', 'm0', 30, 7, $now),
            'addDistinctBoundedWithSnapshotAcrossRotation' => CallerTimeStore::addDistinctBoundedWithSnapshotAcrossRotation(self::as(BoundedCorrelationSnapshotRotationStoreInterface::class, $store), 'cur', 'bridge', 'prev', 'm1', 'm0', 30, 7, $now),
            'readGenerationBoundScoreState' => CallerTimeStore::readGenerationBoundScoreState(self::as(PunishmentLifecycleStoreInterface::class, $store), 'k4', 'k4-prev', $now),
            'mutateGenerationBoundScore' => CallerTimeStore::mutateGenerationBoundScore(self::as(PunishmentLifecycleStoreInterface::class, $store), 'k4', 'k4-prev', null, 30, 5, $now),
            default => CallerTimeStore::claimPostPunishmentReentry(self::as(PunishmentLifecycleStoreInterface::class, $store), 'k4', 'k4-prev', 'life-1', $now),
        };
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private static function as(string $class, object $store): object
    {
        self::assertInstanceOf($class, $store);

        return $store;
    }
}
