<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionRaceRunner;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionRaceSpec;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionScenarioBuilder as S;
use Maatify\RateLimiter\Tests\Support\Admission\StudyOriginalReserve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * NM-6 (contract §8.4, plan §3): production store integrity on a real Redis. Fixtures T1-N4a / N4b / N5, malformed state and
 * R-3 value-level all-or-nothing, and the R-1 / R-2 races. Detectors: the original study reserve script fails N4b / N5, and a
 * deliberately non-atomic reservation over-admits under the race.
 */
#[Group('nm6')]
final class AdmissionStoreNm6RedisTest extends AdmissionRedisGateTestCase
{
    private const NOW = S::NOW;

    /**
     * Seeds the device-set fixture of G1 T1: previous generation holds two live members and an expired one, the current
     * generation holds an expired one. Returns the raw keys.
     *
     * @return array{cur: string, prev: string, counter: string}
     */
    private function seedDeviceSets(): array
    {
        $cur = $this->key(AdmissionScopeKindEnum::DEVICE_MEMBER, 'dev:cur');
        $prev = $this->key(AdmissionScopeKindEnum::DEVICE_MEMBER, 'dev:prev');
        $counter = $this->key(AdmissionScopeKindEnum::COUNTER, 'c:n4a');
        $live = (string) (self::NOW + 3600);
        $this->raw('ZADD', $prev, $live, 'live-1', $live, 'live-2', (string) (self::NOW - 1), 'expired-prev');
        $this->raw('EXPIRE', $prev, '3600');
        $this->raw('ZADD', $cur, (string) (self::NOW - 1), 'expired-cur');
        $this->raw('EXPIRE', $cur, '3600');
        $this->raw('SET', $counter, '2', 'EX', '1200');

        return ['cur' => $cur, 'prev' => $prev, 'counter' => $counter];
    }

    /**
     * Runs one fixture through the production store and through the archived study script.
     *
     * @return array{admitted: bool, refused: ?string}
     */
    private function productionReserve(string $fixture): array
    {
        $device = S::device('np', 'dev:cur', $fixture === 'N5' ? 3 : 2, 'new-member', 86400, 'dev:prev', null);
        $scopes = $fixture === 'N4a' ? [S::counter('ctr', 'c:n4a', 2), $device] : [$device];
        $result = $this->store->reserveAdmission(S::request(...$scopes), self::NOW);

        return ['admitted' => $result->admitted, 'refused' => $result->refusedScope];
    }

    /**
     * @param array{cur: string, prev: string, counter: string} $keys
     * @return array{admitted: bool, refused: ?string}
     */
    private function studyReserve(string $fixture, array $keys): array
    {
        $counters = $fixture === 'N4a' ? [['key' => $keys['counter'], 'cap' => 2, 'ttl' => 1200, 'prev' => null]] : [];
        $member = ['set' => $keys['cur'], 'prev' => $keys['prev'], 'id' => 'new-member', 'prevId' => null, 'cap' => $fixture === 'N5' ? 3 : 2, 'ttl' => 86400];
        $result = StudyOriginalReserve::reserve($this->executor, $counters, $member, self::NOW);

        return ['admitted' => $result['ok'], 'refused' => $result['ok'] ? null : 'refused'];
    }

    /**
     * @param array{cur: string, prev: string, counter: string} $keys
     * @param array<string, array{type: string, value: mixed, hasTtl: bool}> $before
     * @param array<string, array{type: string, value: mixed, hasTtl: bool}> $after
     */
    private function fixturePasses(string $fixture, array $keys, array $before, array $after, bool $admitted): bool
    {
        if (! $admitted) {
            return $before === $after;
        }
        // N5: admitted. The previous set is read-only; the current set gains exactly the new member.
        $previousSame = ($before[$keys['prev']] ?? null) === ($after[$keys['prev']] ?? null);
        $currentMembers = array_keys((array) ($after[$keys['cur']]['value'] ?? []));
        sort($currentMembers);

        return $previousSame && $currentMembers === ['new-member'] && $fixture === 'N5';
    }

    #[DataProvider('deviceFixtures')]
    public function testProductionStorePassesTheDeviceSetFixtures(string $fixture, bool $expectAdmitted, ?string $expectRefused): void
    {
        $keys = $this->seedDeviceSets();
        $before = $this->dump();
        $pttl = $this->pttl();
        $result = $this->productionReserve($fixture);
        self::assertSame($expectAdmitted, $result['admitted']);
        self::assertSame($expectRefused, $result['refused']);
        self::assertTrue($this->fixturePasses($fixture, $keys, $before, $this->dump(), $result['admitted']), $fixture . ' violated the all-or-nothing / previous-read-only rule.');
        // A refusal refreshes no TTL at all; an admission may refresh only the current set it writes, never the previous one.
        $watched = $expectAdmitted ? array_intersect_key($pttl, [$keys['prev'] => 1]) : $pttl;
        self::assertTrue($this->noTtlRefreshed($watched), $fixture . ' refreshed a TTL it must not touch.');
    }

    /** @return iterable<string, array{string, bool, ?string}> */
    public static function deviceFixtures(): iterable
    {
        yield 'N4a refused by a counter' => ['N4a', false, 'ctr'];
        yield 'N4b refused by the device cap' => ['N4b', false, 'np'];
        yield 'N5 admitted, previous read-only' => ['N5', true, null];
    }

    public function testDetectorOriginalStudyScriptFailsN4bAndN5(): void
    {
        $verdicts = [];
        foreach (['N4a', 'N4b', 'N5'] as $fixture) {
            $this->resetRedis();
            $keys = $this->seedDeviceSets();
            $before = $this->dump();
            $result = $this->studyReserve($fixture, $keys);
            $verdicts[$fixture] = $this->fixturePasses($fixture, $keys, $before, $this->dump(), $result['admitted']);
        }
        self::assertTrue($verdicts['N4a'], 'The study script handles a counter refusal (as archived).');
        self::assertFalse($verdicts['N4b'], 'DETECTOR: the study script must prune the device sets on a device-cap refusal.');
        self::assertFalse($verdicts['N5'], 'DETECTOR: the study script must write the previous set.');
    }

    #[DataProvider('malformedStates')]
    public function testMalformedStateIsARateLimiterExceptionWithNothingWritten(string $label): void
    {
        $fresh = $this->key(AdmissionScopeKindEnum::COUNTER, 'c:ok-' . $label);
        $request = match ($label) {
            'counter-hash' => S::request(S::counter('ok', 'c:ok-' . $label, 5), S::counter('bad', 'c:bad', 5)),
            'counter-previous-list' => S::request(S::counter('ok', 'c:ok-' . $label, 5), S::counter('bad', 'c:cur', 5, 1200, 'c:bad')),
            'counter-non-numeric' => S::request(S::counter('ok', 'c:ok-' . $label, 5), S::counter('bad', 'c:bad', 5)),
            'counter-no-ttl' => S::request(S::counter('ok', 'c:ok-' . $label, 5), S::counter('bad', 'c:bad', 5)),
            'device-string' => S::request(S::counter('ok', 'c:ok-' . $label, 5), S::device('bad', 'd:bad', 5, 'm')),
            'device-previous-string' => S::request(S::counter('ok', 'c:ok-' . $label, 5), S::device('bad', 'd:cur', 5, 'm', 86400, 'd:bad', 'p')),
            'set-string' => S::request(S::counter('ok', 'c:ok-' . $label, 5), S::setSize('bad', 's:bad', 5, 'acct')),
            'bucket-string' => S::request(S::counter('ok', 'c:ok-' . $label, 5), S::bucket('bad', 'b:bad', 4, 1800)),
            'bucket-bad-field' => S::request(S::counter('ok', 'c:ok-' . $label, 5), S::bucket('bad', 'b:bad', 4, 1800)),
            default => S::withPermit(S::permit('p-bad', self::NOW + 30), S::counter('ok', 'c:ok-' . $label, 5)),
        };
        match ($label) {
            'counter-hash' => $this->raw('HSET', $this->key(AdmissionScopeKindEnum::COUNTER, 'c:bad'), 'f', 'v'),
            'counter-previous-list' => $this->raw('RPUSH', $this->key(AdmissionScopeKindEnum::COUNTER, 'c:bad'), 'x'),
            'counter-non-numeric' => $this->raw('SET', $this->key(AdmissionScopeKindEnum::COUNTER, 'c:bad'), 'abc', 'EX', '600'),
            'counter-no-ttl' => $this->raw('SET', $this->key(AdmissionScopeKindEnum::COUNTER, 'c:bad'), '1'),
            'device-string' => $this->raw('SET', $this->key(AdmissionScopeKindEnum::DEVICE_MEMBER, 'd:bad'), 'junk'),
            'device-previous-string' => $this->raw('SET', $this->key(AdmissionScopeKindEnum::DEVICE_MEMBER, 'd:bad'), 'junk'),
            'set-string' => $this->raw('SET', $this->key(AdmissionScopeKindEnum::SET_SIZE, 's:bad'), 'junk'),
            'bucket-string' => $this->raw('SET', $this->key(AdmissionScopeKindEnum::BUCKET, 'b:bad'), 'junk'),
            'bucket-bad-field' => $this->raw('HSET', $this->key(AdmissionScopeKindEnum::BUCKET, 'b:bad'), 'credit', 'x', 'last', '5'),
            'permit-string' => $this->raw('SET', $this->keys->permitKey('p-bad'), 'junk'),
            default => null,
        };
        if ($label === 'bucket-bad-field') {
            $this->raw('EXPIRE', $this->key(AdmissionScopeKindEnum::BUCKET, 'b:bad'), '600');
        }
        $before = $this->dump();
        $pttl = $this->pttl();
        try {
            $this->store->reserveAdmission($request, self::NOW);
            self::fail('A malformed key must not be served.');
        } catch (RateLimiterException $e) {
            self::assertNotSame('', $e->getMessage());
        }
        self::assertSame($before, $this->dump(), 'The request wrote to a key before validating them all.');
        self::assertTrue($this->noTtlRefreshed($pttl));
        self::assertArrayNotHasKey($fresh, $this->dump());
    }

    /** @return iterable<string, array{string}> */
    public static function malformedStates(): iterable
    {
        foreach (['counter-hash', 'counter-previous-list', 'counter-non-numeric', 'counter-no-ttl', 'device-string', 'device-previous-string', 'set-string', 'bucket-string', 'bucket-bad-field', 'permit-string'] as $label) {
            yield $label => [$label];
        }
    }

    public function testValueLevelAllOrNothingWhenTheLastScopeRefuses(): void
    {
        self::assertTrue($this->store->reserveAdmission(S::request(S::counter('victim', 'c:victim', 1)), self::NOW)->admitted);
        $before = $this->dump();
        $pttl = $this->pttl();
        $result = $this->store->reserveAdmission(S::withPermit(
            S::permit('p-r3', self::NOW + 30),
            S::counter('src', 'c:src', 5),
            S::device('np', 'd:r3', 5, 'dev-1'),
            S::bucket('acct_tb', 'b:r3', 4, 1800),
            S::counter('victim', 'c:victim', 1),
        ), self::NOW);
        self::assertFalse($result->admitted);
        self::assertSame('victim', $result->refusedScope);
        self::assertSame($before, $this->dump());
        self::assertTrue($this->noTtlRefreshed($pttl));
    }

    private function raceSpec(string $case, string $mode): AdmissionRaceSpec
    {
        $scopes = $case === 'counter'
            ? [['name' => 'src', 'kind' => 'counter', 'key' => 'race:c', 'capacity' => 5, 'ttl' => 1200]]
            : [['name' => 'np', 'kind' => 'device_member', 'key' => 'race:d', 'capacity' => 5, 'ttl' => 86400, 'member' => 'race-dev-{i}']];

        return new AdmissionRaceSpec(self::NAMESPACE, 'reserve', $mode, self::NOW, $scopes);
    }

    private function seedRace(string $case): void
    {
        if ($case === 'counter') {
            for ($i = 0; $i < 2; $i++) {
                $this->store->reserveAdmission(S::request(S::counter('src', 'race:c', 5)), self::NOW);
            }

            return;
        }
        for ($i = 0; $i < 4; $i++) {
            $this->store->reserveAdmission(S::request(S::device('np', 'race:d', 5, 'seed-' . $i)), self::NOW);
        }
    }

    /** @return list<int> */
    private function raceRounds(string $case, string $mode): array
    {
        $rounds = [];
        for ($round = 0; $round < 3; $round++) {
            $this->resetRedis();
            $this->seedRace($case);
            $rounds[] = AdmissionRaceRunner::admitted(AdmissionRaceRunner::run($this->host, $this->port, 16, $this->raceSpec($case, $mode)));
        }

        return $rounds;
    }

    public function testR1CounterRaceAdmitsExactlyTheCapUnderARealRace(): void
    {
        self::assertSame([3, 3, 3], $this->raceRounds('counter', 'store'));
    }

    public function testR2DeviceRaceAdmitsExactlyOneUnderARealRace(): void
    {
        self::assertSame([1, 1, 1], $this->raceRounds('device', 'store'));
    }

    public function testDetectorNonAtomicReservationOverAdmitsUnderTheSameRace(): void
    {
        $counter = $this->raceRounds('counter', 'nonatomic');
        $device = $this->raceRounds('device', 'nonatomic');
        self::assertGreaterThan(3, max([...$counter, 0]), 'DETECTOR: the non-atomic control must exceed the counter cap in a round: ' . json_encode($counter));
        self::assertGreaterThan(1, max([...$device, 0]), 'DETECTOR: the non-atomic control must exceed the device cap in a round: ' . json_encode($device));
    }
}
