<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionScenarioBuilder as S;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * F-185-01 (#185, DEC-017): every score of the admission sorted sets is a stored temporal field. A score of the current or
 * the previous device set, or of the pending set of a settling failure set, that is not a canonical non-negative integer is
 * a `RateLimiterException` before any write: the malformed member, its key and its TTL stay as they are, and no other scope,
 * no counter and no permit of the request is written. A canonical score behaves as before (NM-6).
 */
#[Group('f-185-01')]
final class AdmissionStoreScoreValidationRedisTest extends AdmissionRedisGateTestCase
{
    private const NOW = S::NOW;

    private const SEEDED = 'seeded';

    /** @return list<string> */
    private static function malformed(): array
    {
        return ['-1', '0.5', '1700001000.5', (self::NOW + 1000) . '.5', 'inf', '-inf', '1e21', '9007199254740992'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedScores(): iterable
    {
        foreach (['current', 'previous', 'pending', 'pending-outcome-success', 'pending-outcome-failure'] as $location) {
            foreach (self::malformed() as $score) {
                yield $location . ' ' . $score => [$location, $score];
            }
        }
    }

    private function settling(): AdmissionScopeDTO
    {
        return new AdmissionScopeDTO('fail', AdmissionScopeKindEnum::SET_SIZE, 's:fail', 5, 1200, null, 'acct-new', null, false, true);
    }

    /** The sorted-set key a location seeds, and the member it seeds there. */
    private function seededKey(string $location): string
    {
        return match ($location) {
            'current' => $this->key(AdmissionScopeKindEnum::DEVICE_MEMBER, 'd:cur'),
            'previous' => $this->key(AdmissionScopeKindEnum::DEVICE_MEMBER, 'd:prev'),
            default => $this->keys->pendingKey(S::CLASS_NAME, 's:fail'),
        };
    }

    private function seededMember(string $location): string
    {
        // An outcome reads the score of its own member only.
        return str_starts_with($location, 'pending-outcome') ? 'acct-new' : self::SEEDED;
    }

    private function seed(string $location, string $score): void
    {
        $key = $this->seededKey($location);
        $this->raw('ZADD', $key, $score, $this->seededMember($location));
        $this->raw('EXPIRE', $key, '3600');
        if (str_starts_with($location, 'pending-outcome')) {
            // State an outcome would change: a counter it would refund, a settled member a success would remove.
            $this->raw('ZADD', $this->key(AdmissionScopeKindEnum::COUNTER, 'c:ok'), (string) (self::NOW + 1200), 'p-185');
            $this->raw('EXPIRE', $this->key(AdmissionScopeKindEnum::COUNTER, 'c:ok'), '1200');
            $this->raw('ZADD', $this->key(AdmissionScopeKindEnum::SET_SIZE, 's:fail'), (string) (self::NOW + 1200), 'acct-new');
            $this->raw('EXPIRE', $this->key(AdmissionScopeKindEnum::SET_SIZE, 's:fail'), '1200');
        }
    }

    /** Runs the operation of a location: a permit-bearing reservation with a counter, or an outcome with a counter. */
    private function operate(string $location): ?bool
    {
        $counter = S::counter('ok', 'c:ok', 5);
        $permit = S::permit('p-185', self::NOW + 30);
        if (str_starts_with($location, 'pending-outcome')) {
            $kind = $location === 'pending-outcome-success' ? AdmissionOutcomeKindEnum::SUCCESS : AdmissionOutcomeKindEnum::FAILURE;
            $this->store->applyAdmissionOutcome(S::outcome($kind, $counter, $this->settling()), self::NOW);

            return null;
        }
        $scope = match ($location) {
            'current' => S::device('np', 'd:cur', 5, 'new-member'),
            'previous' => S::device('np', 'd:cur', 5, 'new-member', 86400, 'd:prev', 'new-member-prev'),
            default => $this->settling(),
        };

        return $this->store->reserveAdmission(S::withPermit($permit, $counter, $scope), self::NOW)->admitted;
    }

    #[DataProvider('malformedScores')]
    public function testANonCanonicalScoreIsARateLimiterExceptionWithNothingWrittenOrDeleted(string $location, string $score): void
    {
        $this->seed($location, $score);
        $key = $this->seededKey($location);
        $before = $this->dump();
        $pttl = $this->pttl();
        $seeded = $before[$key]['value'];
        self::assertIsArray($seeded);
        self::assertArrayHasKey($this->seededMember($location), $seeded, 'The seed did not reach Redis.');
        try {
            $this->operate($location);
            self::fail('A non-canonical score ' . $score . ' must not be served.');
        } catch (RateLimiterException $e) {
            self::assertStringStartsWith('malformed admission state: ', $e->getMessage());
            self::assertStringContainsString('member score is not a canonical non-negative integer', $e->getMessage());
        }
        $after = $this->dump();
        self::assertSame($before, $after, 'The operation wrote, pruned or repaired a key before rejecting the score.');
        self::assertSame($pttl[$key] > 0, $this->pttl()[$key] > 0);
        self::assertTrue($this->noTtlRefreshed($pttl), 'The operation refreshed a TTL.');
        self::assertArrayNotHasKey($this->keys->permitKey('p-185'), $after, 'The permit was written.');
    }

    /** @return iterable<string, array{string}> */
    public static function locations(): iterable
    {
        foreach (['current', 'previous', 'pending', 'pending-outcome-success', 'pending-outcome-failure'] as $location) {
            yield $location => [$location];
        }
    }

    #[DataProvider('locations')]
    public function testControlACanonicalScoreBehavesAsBefore(string $location): void
    {
        $score = (string) (self::NOW + 1000);
        $this->seed($location, $score);
        $admitted = $this->operate($location);
        $after = $this->dump();
        $key = $this->seededKey($location);
        match ($location) {
            'current' => self::assertSame(['new-member' => (string) (self::NOW + 86400), self::SEEDED => $score], $after[$key]['value']),
            'previous' => self::assertSame([self::SEEDED => $score], $after[$key]['value'], 'The previous generation is read-only.'),
            'pending' => self::assertSame(['acct-new' => (string) (self::NOW + 30), self::SEEDED => $score], $after[$key]['value']),
            // A success cancels the pending member that is not yet due; a failure settles it.
            default => self::assertArrayNotHasKey($key, $after),
        };
        if ($admitted !== null) {
            self::assertTrue($admitted);
            self::assertSame(['p-185' => (string) (self::NOW + 1200)], $after[$this->key(AdmissionScopeKindEnum::COUNTER, 'c:ok')]['value']);
            self::assertArrayHasKey($this->keys->permitKey('p-185'), $after);
        }
    }

    public function testControlAnExpiredCanonicalScoreIsStillPrunedOnAdmission(): void
    {
        $this->seed('current', (string) (self::NOW - 1));
        self::assertTrue($this->operate('current'));
        self::assertSame(['new-member' => (string) (self::NOW + 86400)], $this->dump()[$this->seededKey('current')]['value']);
    }

    public function testControlTheLargestCanonicalScoreIsServed(): void
    {
        $this->seed('current', '9007199254740991');
        self::assertTrue($this->operate('current'));
        self::assertSame(
            ['new-member' => (string) (self::NOW + 86400), self::SEEDED => '9007199254740991'],
            $this->dump()[$this->seededKey('current')]['value'],
        );
    }
}
