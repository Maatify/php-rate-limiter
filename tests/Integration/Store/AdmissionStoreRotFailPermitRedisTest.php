<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionPermitClaimEnum;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionRaceRunner;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionScenarioBuilder as S;
use Maatify\RateLimiter\Tests\Support\Admission\StudyOriginalReserve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** ROT, FAIL and PERMIT gates of the store part (plan §3), on a real Redis. */
final class AdmissionStoreRotFailPermitRedisTest extends AdmissionRedisGateTestCase
{
    private const NOW = S::NOW;

    // ---- ROT -----------------------------------------------------------------------------------------------------------

    /** @return array{prevCounter: string, prevSet: string, curSet: string} */
    private function seedPreviousGeneration(): array
    {
        $prevCounter = $this->key(AdmissionScopeKindEnum::COUNTER, 'rot:old');
        $prevSet = $this->key(AdmissionScopeKindEnum::DEVICE_MEMBER, 'rot:dev-old');
        $curSet = $this->key(AdmissionScopeKindEnum::DEVICE_MEMBER, 'rot:dev-new');
        $this->raw('SET', $prevCounter, '2', 'EX', '1200');
        $this->raw('ZADD', $prevSet, (string) (self::NOW + 3600), 'live-prev', (string) (self::NOW - 1), 'expired-prev');
        $this->raw('EXPIRE', $prevSet, '3600');

        return ['prevCounter' => $prevCounter, 'prevSet' => $prevSet, 'curSet' => $curSet];
    }

    #[Group('rot')]
    public function testCountersSumCurrentAndPreviousAndWriteCurrentOnlyOnAdmitAndRefuse(): void
    {
        $keys = $this->seedPreviousGeneration();
        $prevBefore = $this->dump()[$keys['prevCounter']];
        $pttl = $this->pttl();
        $scope = static fn() => S::counter('src', 'rot:new', 4, 1200, 'rot:old');
        // Previous holds 2: two more fit under a cap of 4, then a refusal. The previous counter stays at 2 throughout.
        self::assertTrue($this->store->reserveAdmission(S::request($scope()), self::NOW)->admitted);
        self::assertTrue($this->store->reserveAdmission(S::request($scope()), self::NOW)->admitted);
        self::assertFalse($this->store->reserveAdmission(S::request($scope()), self::NOW)->admitted);
        self::assertSame($prevBefore, $this->dump()[$keys['prevCounter']]);
        self::assertSame('2', $this->raw('GET', $keys['prevCounter']));
        self::assertSame('2', $this->raw('GET', $this->key(AdmissionScopeKindEnum::COUNTER, 'rot:new')));
        self::assertTrue($this->noTtlRefreshed(array_intersect_key($pttl, [$keys['prevCounter'] => 1])));
    }

    #[Group('rot')]
    public function testPreviousDeviceSetIsNeverWrittenOnAdmitOrRefuse(): void
    {
        $keys = $this->seedPreviousGeneration();
        $before = $this->dump()[$keys['prevSet']];
        $pttl = $this->pttl();
        $admit = S::device('np', 'rot:dev-new', 3, 'new-1', 86400, 'rot:dev-old', null);
        self::assertTrue($this->store->reserveAdmission(S::request($admit), self::NOW)->admitted);
        self::assertSame($before, $this->dump()[$keys['prevSet']], 'Admitted: the previous set must be byte-identical (T1-N5).');
        $refuse = S::device('np', 'rot:dev-new', 2, 'new-2', 86400, 'rot:dev-old', null);
        self::assertFalse($this->store->reserveAdmission(S::request($refuse), self::NOW)->admitted);
        self::assertSame($before, $this->dump()[$keys['prevSet']], 'Refused: the previous set must be byte-identical (T1-N4b).');
        self::assertTrue($this->noTtlRefreshed($pttl));
    }

    #[Group('rot')]
    public function testDetectorStudyScriptWritesThePreviousSet(): void
    {
        $keys = $this->seedPreviousGeneration();
        $before = $this->dump()[$keys['prevSet']];
        StudyOriginalReserve::reserve($this->executor, [], ['set' => $keys['curSet'], 'prev' => $keys['prevSet'], 'id' => 'new-1', 'prevId' => null, 'cap' => 3, 'ttl' => 86400], self::NOW);
        self::assertNotSame($before, $this->dump()[$keys['prevSet']], 'DETECTOR: the study script prunes (writes) the previous set.');
    }

    // ---- FAIL ----------------------------------------------------------------------------------------------------------

    /** Runs the five operations through `$executor` and reports each outcome's throwable class (null when none). @return array<string, ?class-string<\Throwable>> */
    private function failureClasses(RedisCommandExecutorInterface $executor): array
    {
        $store = $this->executorFor($executor);
        $calls = [
            'reserve' => static fn() => $store->reserveAdmission(S::request(S::counter('src', 'f:c', 3)), self::NOW),
            'outcome' => static fn() => $store->applyAdmissionOutcome(S::outcome(AdmissionOutcomeKindEnum::SUCCESS, S::counter('src', 'f:c', 3)), self::NOW),
            'epochRead' => static fn() => $store->readBindingEpoch('f:e', null),
            'epochBump' => static fn() => $store->bumpBindingEpoch('f:e', null),
            'claim' => static fn() => $store->claimAdmissionPermit('f:p', 'U', 'acctT', 'login', AdmissionOutcomeKindEnum::FAILURE, self::NOW),
        ];
        $out = [];
        foreach ($calls as $name => $call) {
            try {
                $call();
                $out[$name] = null;
            } catch (\Throwable $t) {
                $out[$name] = $t::class;
            }
        }

        return $out;
    }

    private function failingExecutor(\Throwable $throwable): RedisCommandExecutorInterface
    {
        return new class ($this->executor, $throwable) implements RedisCommandExecutorInterface {
            public function __construct(private readonly RedisCommandExecutorInterface $inner, private readonly \Throwable $throwable)
            {
            }

            public function execute(array $command): mixed
            {
                if (strtoupper((string) $command[0]) === 'EVAL') {
                    throw $this->throwable;
                }

                return $this->inner->execute($command);
            }
        };
    }

    #[Group('fail')]
    public function testTypedOutageReachesTheCallerFromEveryOperationWithNothingWritten(): void
    {
        $before = $this->dump();
        $classes = $this->failureClasses($this->failingExecutor(new BackendFailureException('Redis connection lost.')));
        self::assertSame(array_fill_keys(['reserve', 'outcome', 'epochRead', 'epochBump', 'claim'], BackendFailureException::class), $classes);
        self::assertSame($before, $this->dump());
    }

    #[Group('fail')]
    public function testUnclassifiedThrowableIsNeitherWrappedNorReclassified(): void
    {
        $classes = $this->failureClasses($this->failingExecutor(new \LogicException('programming error')));
        self::assertSame(array_fill_keys(['reserve', 'outcome', 'epochRead', 'epochBump', 'claim'], \LogicException::class), $classes);
    }

    #[Group('fail')]
    public function testDetectorBlanketWrappingExecutorIsCaught(): void
    {
        $wrapping = new class ($this->failingExecutor(new \LogicException('programming error'))) implements RedisCommandExecutorInterface {
            public function __construct(private readonly RedisCommandExecutorInterface $inner)
            {
            }

            public function execute(array $command): mixed
            {
                try {
                    return $this->inner->execute($command);
                } catch (\Throwable $t) {
                    throw new BackendFailureException('wrapped: ' . $t->getMessage());
                }
            }
        };
        $classes = $this->failureClasses($wrapping);
        self::assertNotSame(\LogicException::class, $classes['reserve'], 'DETECTOR: a blanket-wrapping executor turns an unclassified throwable into a backend failure.');
        self::assertSame(BackendFailureException::class, $classes['reserve']);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedEpochAndPermitState(): iterable
    {
        yield 'epoch not an integer' => ['epoch-text'];
        yield 'epoch negative' => ['epoch-negative'];
        yield 'epoch wrong type' => ['epoch-hash'];
        yield 'permit record not a hash' => ['permit-string'];
        yield 'permit record missing fields' => ['permit-missing'];
        yield 'permit record bad deadline' => ['permit-deadline'];
    }

    #[Group('fail')]
    #[DataProvider('malformedEpochAndPermitState')]
    public function testMalformedStoredStateIsARateLimiterExceptionNeverABackendFailure(string $case): void
    {
        $epoch = $this->keys->epochKey('m:e');
        $permit = $this->keys->permitKey('m:p');
        match ($case) {
            'epoch-text' => $this->raw('SET', $epoch, 'abc'),
            'epoch-negative' => $this->raw('SET', $epoch, '-3'),
            'epoch-hash' => $this->raw('HSET', $epoch, 'f', 'v'),
            'permit-string' => $this->raw('SET', $permit, 'junk', 'EX', '600'),
            'permit-missing' => $this->raw('HSET', $permit, 'state', 'ISSUED'),
            'permit-deadline' => $this->raw('HSET', $permit, 'state', 'ISSUED', 'lane', 'U', 'subject', 'acctT', 'operation', 'login', 'deadline', 'soon'),
            default => throw new \LogicException('Unknown case.'),
        };
        if (str_starts_with($case, 'permit-') && $case !== 'permit-string') {
            $this->raw('EXPIRE', $permit, '600');
        }
        $before = $this->dump();
        $operation = str_starts_with($case, 'epoch')
            ? fn() => $this->store->bumpBindingEpoch('m:e', null)
            : fn() => $this->store->claimAdmissionPermit('m:p', 'U', 'acctT', 'login', AdmissionOutcomeKindEnum::FAILURE, self::NOW);
        try {
            $operation();
            self::fail('Malformed state must not be served.');
        } catch (BackendFailureException) {
            self::fail('Malformed state must never be a backend failure.');
        } catch (RateLimiterException) {
            self::addToAssertionCount(1);
        }
        self::assertSame($before, $this->dump());
    }

    // ---- PERMIT --------------------------------------------------------------------------------------------------------

    /** @return list<array{ok: bool, refused: ?string, claim: ?string}> */
    private function claimRace(string $mode): array
    {
        $this->resetRedis();
        self::assertTrue($this->store->reserveAdmission(S::withPermit(S::permit('p-race', self::NOW + 30), S::counter('src', 'c:permit-race', 100)), self::NOW)->admitted);

        return AdmissionRaceRunner::run($this->host, $this->port, 16, [
            'namespace' => self::NAMESPACE, 'op' => 'claim', 'mode' => $mode, 'now' => self::NOW + 5, 'permit' => 'p-race', 'scopes' => [],
        ]);
    }

    #[Group('permit')]
    public function testRaceOfClaimsOnOnePermitAcceptsExactlyOne(): void
    {
        for ($round = 0; $round < 3; $round++) {
            $results = $this->claimRace('store');
            self::assertSame(1, AdmissionRaceRunner::admitted($results));
            $others = array_values(array_unique(array_map(static fn(array $r): ?string => $r['claim'], array_filter($results, static fn(array $r): bool => ! $r['ok']))));
            self::assertSame([AdmissionPermitClaimEnum::DUPLICATE->value], $others);
        }
    }

    #[Group('permit')]
    public function testDetectorNonAtomicClaimAcceptsMoreThanOnce(): void
    {
        $accepted = [];
        for ($round = 0; $round < 3; $round++) {
            $accepted[] = AdmissionRaceRunner::admitted($this->claimRace('nonatomic'));
        }
        self::assertGreaterThan(1, max($accepted), 'DETECTOR: the non-atomic claim must accept twice in a round: ' . json_encode($accepted));
    }

    #[Group('permit')]
    public function testRaceOfIssuesOfOnePermitIdAdmitsExactlyOne(): void
    {
        for ($round = 0; $round < 3; $round++) {
            $this->resetRedis();
            $results = AdmissionRaceRunner::run($this->host, $this->port, 16, [
                'namespace' => self::NAMESPACE, 'op' => 'reserve', 'mode' => 'store', 'now' => self::NOW, 'issuePermit' => 'p-single',
                'scopes' => [['name' => 'src', 'kind' => 'counter', 'key' => 'c:issue-race', 'capacity' => 100, 'ttl' => 1200]],
            ]);
            self::assertSame(1, AdmissionRaceRunner::admitted($results));
            self::assertSame('1', $this->raw('GET', $this->key(AdmissionScopeKindEnum::COUNTER, 'c:issue-race')));
            self::assertSame(['permit_exists'], array_values(array_unique(array_map(static fn(array $r): ?string => $r['refused'], array_filter($results, static fn(array $r): bool => ! $r['ok'])))));
        }
    }
}
