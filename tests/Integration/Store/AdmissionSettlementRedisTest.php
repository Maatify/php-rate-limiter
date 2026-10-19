<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisAdmissionOperations;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionScenarioBuilder as S;
use Maatify\RateLimiter\Tests\Support\Admission\MergedAdmissionOperations;
use Maatify\RateLimiter\Tests\Support\Admission\OperationsMutant;
use Maatify\RateLimiter\Tests\Support\Admission\ReplyValue;
use Maatify\RateLimiter\Tests\Support\Admission\SettlementPort;
use Maatify\RateLimiter\Tests\Support\Admission\SettlementScenarios as Sc;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * NM-6 and the SETTLE detectors on the production operations (5b2.1/PLAN.md §5), real Redis. A detector is a mutation of the
 * real source (or the operations as merged at d6aea4d) and must make the same scenario fail.
 */
#[Group('settle')]
#[Group('nm6')]
final class AdmissionSettlementRedisTest extends AdmissionRedisGateTestCase
{
    private function pending(string $logical): string
    {
        return $this->keys->pendingKey('login', $logical);
    }

    private function portFor(object $ops): SettlementPort
    {
        /** @var RedisAdmissionOperations|MergedAdmissionOperations $ops */
        return new SettlementPort(
            static fn($request, int $now) => $ops->reserve($request, $now),
            static fn($outcome, int $now) => $ops->applyOutcome($outcome, $now),
            static fn(string $id, AdmissionOutcomeKindEnum $kind, int $now) => $ops->claimPermit($id, 'U', 'acctT', 'login', $kind, $now),
        );
    }

    /** @param list<array{0: string, 1: string}> $anchors */
    private function mutantPort(array $anchors): SettlementPort
    {
        $class = OperationsMutant::load($anchors);

        return $this->portFor(new $class($this->executor, self::NAMESPACE));
    }

    public function testARefusedReserveWritesNothingPendingKeyIncluded(): void
    {
        $port = $this->portFor(new RedisAdmissionOperations($this->executor, self::NAMESPACE));
        Sc::outcome($port, Sc::N, AdmissionOutcomeKindEnum::FAILURE, Sc::member('s:r', 'seed', 5));
        Sc::reserve($port, Sc::N, Sc::member('s:r', 'acct-a', 5), Sc::permit('permit-r1', Sc::N + Sc::D));
        $before = $this->dump();
        $pttl = $this->pttl();
        self::assertArrayHasKey($this->pending('s:r'), $before, 'a permit-bearing admission creates the pending key');

        // Cap 1 with one settled failure: the gate refuses and nothing is written (no p key for the refused member).
        self::assertFalse(Sc::reserve($port, Sc::N + Sc::D + 1, Sc::member('s:r', 'acct-n', 1), Sc::permit('permit-r2', Sc::N + 2 * Sc::D)));
        self::assertSame($before, $this->dump());
        self::assertTrue($this->noTtlRefreshed($pttl));
    }

    public function testThePendingKeyLivesForTheScopeLifetimeAndIsNeverRenewed(): void
    {
        $port = $this->portFor(new RedisAdmissionOperations($this->executor, self::NAMESPACE));
        Sc::reserve($port, Sc::N, Sc::member('s:life', 'acct-1', 9), Sc::permit('permit-l1', Sc::N + Sc::D));
        $first = ReplyValue::integer($this->raw('PTTL', $this->pending('s:life')));
        self::assertGreaterThan((Sc::TTL - 30) * 1000, $first);
        self::assertLessThanOrEqual(Sc::TTL * 1000, $first);
        usleep(1_200_000);
        Sc::reserve($port, Sc::N + 5, Sc::member('s:life', 'acct-2', 9), Sc::permit('permit-l2', Sc::N + Sc::D));
        self::assertLessThan($first, ReplyValue::integer($this->raw('PTTL', $this->pending('s:life'))), 'a second pending member must not renew the lifetime');
    }

    /** @return iterable<string, array{string}> */
    public static function malformedPending(): iterable
    {
        yield 'pending key of the wrong type' => ['wrong-type'];
        yield 'pending key without an expiry' => ['no-expiry'];
    }

    #[DataProvider('malformedPending')]
    public function testMalformedPendingStateIsARateLimiterExceptionAndWritesNothing(string $case): void
    {
        $port = $this->portFor(new RedisAdmissionOperations($this->executor, self::NAMESPACE));
        $key = $this->pending('s:bad');
        if ($case === 'wrong-type') {
            $this->raw('SET', $key, 'junk');
            $this->raw('EXPIRE', $key, '100');
        } else {
            $this->raw('ZADD', $key, '5', 'm');
        }
        $before = $this->dump();
        foreach ([
            static fn() => Sc::reserve($port, Sc::N, Sc::member('s:bad', 'acct', 3), Sc::permit('permit-bad', Sc::N + Sc::D)),
            static fn() => Sc::outcome($port, Sc::N, AdmissionOutcomeKindEnum::FAILURE, Sc::member('s:bad', 'acct', 3)),
            static fn() => Sc::outcome($port, Sc::N, AdmissionOutcomeKindEnum::SUCCESS, Sc::member('s:bad', 'acct', 3)),
        ] as $operation) {
            try {
                $operation();
                self::fail('Malformed pending state must be rejected.');
            } catch (RateLimiterException) {
                self::assertSame($before, $this->dump());
            }
        }
    }

    /** @return array<string, array{type: string, value: mixed, hasTtl: bool}> counter and bucket keys only */
    private function counterAndBucketDump(): array
    {
        return array_filter($this->dump(), static fn(string $key): bool => str_contains($key, ':adm:c:') || str_contains($key, ':adm:b:'), ARRAY_FILTER_USE_KEY);
    }

    /** @return array{0: bool, 1: bool} whether a read-only pass left the whole dump unchanged, and the counters unchanged */
    private function readOnlyPass(SettlementPort $port): array
    {
        Sc::outcome($port, Sc::N, AdmissionOutcomeKindEnum::FAILURE, Sc::member('s:ro', 'seed', 2));
        ($port->reserve)(
            S::withPermit(Sc::permit('permit-ro', Sc::N + Sc::D), S::counter('src', 'c:ro', 5), S::bucket('acct_tb', 'b:ro', 4, 1800), Sc::member('s:ro', 'acct-ro', 2)),
            Sc::N,
        );
        $whole = $this->dump();
        $counters = $this->counterAndBucketDump();
        // The clock passes the deadline; refused reservations and a late claim only read.
        Sc::reserve($port, Sc::N + Sc::D + 1, Sc::member('s:ro', 'new-ro', 2));
        Sc::claim($port, Sc::N + Sc::D + 1, 'permit-ro', AdmissionOutcomeKindEnum::FAILURE);

        return [$whole === $this->dump(), $counters === $this->counterAndBucketDump()];
    }

    public function testSettle4TheClockPassingTheDeadlineWritesAndRefundsNothing(): void
    {
        $port = $this->portFor(new RedisAdmissionOperations($this->executor, self::NAMESPACE));
        [$whole, $counters] = $this->readOnlyPass($port);
        self::assertTrue($whole, 'a refused reservation and a late claim after the deadline write nothing');
        self::assertTrue($counters);
    }

    // ---- Detectors -------------------------------------------------------------------------------------------------

    public function testDetectorTheStoreAsMergedAtD6aea4dFailsSettle1(): void
    {
        $expected = ['beforeDeadline' => true, 'atDeadlineNew' => false, 'atDeadlineOwn' => true];
        $merged = new MergedAdmissionOperations($this->executor, self::NAMESPACE);
        self::assertNotSame($expected, Sc::exactBound($this->portFor($merged)), 'DETECTOR: nothing is counted at the deadline in the merged store');
    }

    public function testDetectorAnExclusiveBoundFailsSettle1(): void
    {
        $port = $this->mutantPort([["redis.call('ZRANGEBYSCORE', pendingKey, '-inf', now)", "redis.call('ZRANGEBYSCORE', pendingKey, '-inf', '(' .. now)"]]);
        self::assertNotSame(['beforeDeadline' => true, 'atDeadlineNew' => false, 'atDeadlineOwn' => true], Sc::exactBound($port));
    }

    public function testDetectorIgnoringTheKeepsSettledFlagFailsSettle2(): void
    {
        $port = $this->mutantPort([[
            "if not s.keepsSettled then redis.call('SREM', s.cur, s.member) end",
            "redis.call('SREM', s.cur, s.member)",
        ]]);
        self::assertNotSame(['settledKeptThenNew' => false, 'pendingCancelledThenNew' => true], Sc::keepsSettledFlag($port));
    }

    public function testDetectorASuccessThatRemovesADueMemberFailsSettle3(): void
    {
        $port = $this->mutantPort([["if s.score ~= nil and s.score > now then redis.call('ZREM', s.pending, s.member) end", "redis.call('ZREM', s.pending, s.member)"]]);
        self::assertNotSame(['dueSurvivesSuccess' => false], Sc::dueSurvivesSuccess($port));
    }

    public function testDetectorAPerReservationMemberCountsTwiceAndFailsSettle3(): void
    {
        $port = $this->mutantPort([[
            "redis.call('ZADD', s.pending, 'LT', string.format('%d', s.deadline), s.member)",
            "redis.call('ZADD', s.pending, 'LT', string.format('%d', s.deadline), s.member .. nowStr)",
        ]]);
        self::assertFalse(Sc::onceAndLate($port)['newAdmitted'], 'DETECTOR: a per-reservation member over-counts');
    }

    public function testDetectorAReadThatPrunesFailsSettle4(): void
    {
        $port = $this->mutantPort([[
            "if redis.call('SISMEMBER', setKey, m) == 0 then size = size + 1 end",
            "if redis.call('SISMEMBER', setKey, m) == 0 then size = size + 1 redis.call('ZREM', pendingKey, m) end",
        ]]);
        [$whole] = $this->readOnlyPass($port);
        self::assertFalse($whole, 'DETECTOR: a settlement that writes on a read must be seen by the dump comparison');
    }
}
