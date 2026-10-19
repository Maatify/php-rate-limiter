<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionRaceRunner;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionScenarioBuilder as S;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * NM-7, store level (contract §8.5, plan §3): admissions never exceed the ceiling of the reserved scope under the registered
 * concurrency (16 and 64 processes at one barrier, 3 rounds each). Failover is measured by `5b2/failover.php`, not here.
 * Detector: the non-atomic reservation exceeds the cap under the same concurrency.
 */
#[Group('nm7')]
final class AdmissionStoreNm7RedisTest extends AdmissionRedisGateTestCase
{
    private const NOW = S::NOW;

    /** @param list<array<string, mixed>> $scopes @return array<string, mixed> */
    private function spec(array $scopes, string $mode = 'store', string $op = 'reserve', array $extra = []): array
    {
        return ['namespace' => self::NAMESPACE, 'op' => $op, 'mode' => $mode, 'now' => self::NOW, 'scopes' => $scopes] + $extra;
    }

    /** @return list<int> */
    private function rounds(int $processes, callable $seed, array $spec): array
    {
        $out = [];
        for ($round = 0; $round < 3; $round++) {
            $this->resetRedis();
            $seed();
            $out[] = AdmissionRaceRunner::admitted(AdmissionRaceRunner::run($this->host, $this->port, $processes, $spec));
        }

        return $out;
    }

    /** @return iterable<string, array{int}> */
    public static function levels(): iterable
    {
        yield '16 processes' => [16];
        yield '64 processes' => [64];
    }

    #[DataProvider('levels')]
    public function testCounterAdmissionsNeverExceedTheCeiling(int $processes): void
    {
        $seed = function (): void {
            for ($i = 0; $i < 2; $i++) {
                $this->store->reserveAdmission(S::request(S::counter('src', 'c:nm7', 5)), self::NOW);
            }
        };
        $spec = $this->spec([['name' => 'src', 'kind' => 'counter', 'key' => 'c:nm7', 'capacity' => 5, 'ttl' => 1200]]);
        self::assertSame([3, 3, 3], $this->rounds($processes, $seed, $spec));
        self::assertSame('5', $this->raw('GET', $this->key(AdmissionScopeKindEnum::COUNTER, 'c:nm7')));
    }

    #[DataProvider('levels')]
    public function testDeviceMemberAdmissionsNeverExceedTheCeiling(int $processes): void
    {
        $seed = function (): void {
            for ($i = 0; $i < 4; $i++) {
                $this->store->reserveAdmission(S::request(S::device('np', 'd:nm7', 5, 'seed-' . $i)), self::NOW);
            }
        };
        $spec = $this->spec([['name' => 'np', 'kind' => 'device_member', 'key' => 'd:nm7', 'capacity' => 5, 'ttl' => 86400, 'member' => 'dev-{i}']]);
        self::assertSame([1, 1, 1], $this->rounds($processes, $seed, $spec));
        self::assertSame(5, $this->raw('ZCARD', $this->key(AdmissionScopeKindEnum::DEVICE_MEMBER, 'd:nm7')));
    }

    #[DataProvider('levels')]
    public function testBucketTakesNeverExceedTheCapacity(int $processes): void
    {
        $spec = $this->spec([['name' => 'acct_tb', 'kind' => 'bucket', 'key' => 'b:nm7', 'capacity' => 4, 'ttl' => 1800]]);
        self::assertSame([4, 4, 4], $this->rounds($processes, static function (): void {
        }, $spec));
        self::assertSame('0', $this->raw('HGET', $this->key(AdmissionScopeKindEnum::BUCKET, 'b:nm7'), 'credit'));
    }

    #[DataProvider('levels')]
    public function testMultiScopeRaceWritesAllOrNone(int $processes): void
    {
        $seed = function (): void {
            for ($i = 0; $i < 2; $i++) {
                $this->store->reserveAdmission(S::request(S::counter('src', 'c:multi', 6)), self::NOW);
            }
        };
        $spec = $this->spec([
            ['name' => 'src', 'kind' => 'counter', 'key' => 'c:multi', 'capacity' => 6, 'ttl' => 1200],
            ['name' => 'np', 'kind' => 'device_member', 'key' => 'd:multi', 'capacity' => 3, 'ttl' => 86400, 'member' => 'dev-{i}'],
            ['name' => 'acct_tb', 'kind' => 'bucket', 'key' => 'b:multi', 'capacity' => 2, 'ttl' => 1800],
        ]);
        $admitted = [];
        for ($round = 0; $round < 3; $round++) {
            $this->resetRedis();
            $seed();
            $n = AdmissionRaceRunner::admitted(AdmissionRaceRunner::run($this->host, $this->port, $processes, $spec));
            $admitted[] = $n;
            // Every scope advanced by exactly the number of admitted requests, none beyond its cap.
            self::assertSame((string) (2 + $n), $this->raw('GET', $this->key(AdmissionScopeKindEnum::COUNTER, 'c:multi')));
            self::assertSame($n, $this->raw('ZCARD', $this->key(AdmissionScopeKindEnum::DEVICE_MEMBER, 'd:multi')));
            self::assertSame((string) (2 * 1800 - $n * 1800), $this->raw('HGET', $this->key(AdmissionScopeKindEnum::BUCKET, 'b:multi'), 'credit'));
        }
        self::assertSame([2, 2, 2], $admitted);
    }

    #[DataProvider('levels')]
    public function testConcurrentOutcomesAreNotLost(int $processes): void
    {
        for ($round = 0; $round < 3; $round++) {
            $this->resetRedis();
            for ($i = 0; $i < 100; $i++) {
                $this->store->reserveAdmission(S::request(S::counter('p_acct', 'c:refund', 1000)), self::NOW);
            }
            AdmissionRaceRunner::run($this->host, $this->port, $processes, $this->spec([
                ['name' => 'fail_src', 'kind' => 'set_size', 'key' => 's:nm7', 'capacity' => 1000, 'ttl' => 1200, 'member' => 'acct-{i}'],
            ], 'store', 'outcome', ['outcome' => 'failure']));
            self::assertSame($processes, $this->raw('SCARD', $this->key(AdmissionScopeKindEnum::SET_SIZE, 's:nm7')));
            AdmissionRaceRunner::run($this->host, $this->port, $processes, $this->spec([
                ['name' => 'p_acct', 'kind' => 'counter', 'key' => 'c:refund', 'capacity' => 1000, 'ttl' => 1200],
            ], 'store', 'outcome', ['outcome' => 'success']));
            self::assertSame((string) (100 - $processes), $this->raw('GET', $this->key(AdmissionScopeKindEnum::COUNTER, 'c:refund')));
        }
    }

    public function testSetSizeGateIsAFailureCapAndReserveNeverWritesIt(): void
    {
        // Documented property: F bounds failures, not admissions. With the set one below F, every concurrent reserve is admitted.
        $this->resetRedis();
        for ($i = 0; $i < 3; $i++) {
            $this->store->applyAdmissionOutcome(S::outcome(\Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum::FAILURE, S::setSize('fail_src', 's:cap', 4, 'seed-' . $i)), self::NOW);
        }
        $spec = $this->spec([['name' => 'fail_src', 'kind' => 'set_size', 'key' => 's:cap', 'capacity' => 4, 'ttl' => 1200, 'member' => 'new-{i}']]);
        self::assertSame(16, AdmissionRaceRunner::admitted(AdmissionRaceRunner::run($this->host, $this->port, 16, $spec)));
        self::assertSame(3, $this->raw('SCARD', $this->key(AdmissionScopeKindEnum::SET_SIZE, 's:cap')));
        // With the set at F the gate refuses every new account.
        $this->store->applyAdmissionOutcome(S::outcome(\Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum::FAILURE, S::setSize('fail_src', 's:cap', 4, 'seed-3')), self::NOW);
        self::assertSame(0, AdmissionRaceRunner::admitted(AdmissionRaceRunner::run($this->host, $this->port, 16, $spec)));
    }

    public function testDetectorNonAtomicReservationExceedsTheCeilingUnderTheSameConcurrency(): void
    {
        $seed = function (): void {
            for ($i = 0; $i < 2; $i++) {
                $this->store->reserveAdmission(S::request(S::counter('src', 'c:nm7', 5)), self::NOW);
            }
        };
        $spec = $this->spec([['name' => 'src', 'kind' => 'counter', 'key' => 'c:nm7', 'capacity' => 5, 'ttl' => 1200]], 'nonatomic');
        $at16 = $this->rounds(16, $seed, $spec);
        $at64 = $this->rounds(64, $seed, $spec);
        self::assertGreaterThan(3, max([...$at16, ...$at64]), 'DETECTOR: the non-atomic control must exceed the ceiling: ' . json_encode([$at16, $at64]));
    }
}
