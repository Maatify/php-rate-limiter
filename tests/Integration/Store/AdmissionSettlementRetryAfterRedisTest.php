<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionInMemoryReferenceStore;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionScenarioBuilder as S;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;

final class AdmissionSettlementRetryAfterRedisTest extends AdmissionRedisGateTestCase
{
    private const TTL = 100;

    public function testPendingMemberContributesFromItsDeadlineThroughItsExpiry(): void
    {
        $reference = new AdmissionInMemoryReferenceStore(new FixedClock('@' . S::NOW));
        $stores = [$this->store, $reference];
        $key = 's:settlement-retry-pending';
        $scope = $this->settlingScope($key, 'due', 1);
        $probe = S::request($this->settlingScope($key, 'next', 1));
        $deadline = S::NOW + 10;

        foreach ($stores as $store) {
            self::assertTrue($store->reserveAdmission(S::withPermit(S::permit('p:due', $deadline), $scope), S::NOW)->admitted);
        }

        self::assertTrue($this->store->reserveAdmission($probe, $deadline - 1)->admitted);
        self::assertTrue($reference->reserveAdmission($probe, $deadline - 1)->admitted);
        $this->assertRefusalWithoutWrite($reference, $key, $deadline, self::TTL, 1);
        $this->assertRefusalWithoutWrite($reference, $key, $deadline + self::TTL - 1, 1, 1);
        self::assertTrue($this->store->reserveAdmission($probe, $deadline + self::TTL)->admitted);
        self::assertTrue($reference->reserveAdmission($probe, $deadline + self::TTL)->admitted);
    }

    public function testExplicitAndDueMembershipsUseTheLaterExpiryForAnOverlappingSubject(): void
    {
        $reference = new AdmissionInMemoryReferenceStore(new FixedClock('@' . S::NOW));
        $stores = [$this->store, $reference];
        $key = 's:settlement-retry-overlap';
        $overlap = $this->settlingScope($key, 'overlap', 2);
        $pendingOnly = $this->settlingScope($key, 'pending', 2);
        $probe = S::request($this->settlingScope($key, 'next', 2));

        foreach ($stores as $store) {
            $later = S::withPermit(S::permit('p:overlap', S::NOW + 20), $overlap);
            $earlier = S::withPermit(S::permit('p:pending', S::NOW + 10), $pendingOnly);
            self::assertTrue($store->reserveAdmission($later, S::NOW)->admitted);
            self::assertTrue($store->reserveAdmission($earlier, S::NOW)->admitted);
            // A non-settling outcome records an explicit entry while leaving the pending entry alone.
            $explicitOverlap = S::setSize('failure', $key, 2, 'overlap', self::TTL);
            $explicitOnly = S::setSize('failure', $key, 2, 'explicit', self::TTL);
            $store->applyAdmissionOutcome(S::outcome(AdmissionOutcomeKindEnum::FAILURE, $explicitOverlap), S::NOW + 5);
            $store->applyAdmissionOutcome(S::outcome(AdmissionOutcomeKindEnum::FAILURE, $explicitOnly), S::NOW + 15);
        }

        // Three subjects fill two slots. Their effective expiries are +110, +115, and +120.
        $this->assertRefusalWithoutWrite($reference, $key, S::NOW + 20, 95, 2);
        $this->assertRefusalWithoutWrite($reference, $key, S::NOW + 114, 1, 2);
        self::assertTrue($this->store->reserveAdmission($probe, S::NOW + 115)->admitted);
        self::assertTrue($reference->reserveAdmission($probe, S::NOW + 115)->admitted);
    }

    private function assertRefusalWithoutWrite(
        AdmissionInMemoryReferenceStore $reference,
        string $key,
        int $now,
        int $retryAfter,
        int $capacity,
    ): void {
        $request = S::request($this->settlingScope($key, 'next', $capacity));
        $set = $this->key(AdmissionScopeKindEnum::SET_SIZE, $key);
        $pending = $this->keys->pendingKey(S::CLASS_NAME, $key);
        $before = $this->dump();
        $expiry = [$this->raw('PEXPIRETIME', $set), $this->raw('PEXPIRETIME', $pending)];
        $result = $this->store->reserveAdmission($request, $now);
        $inMemory = $reference->reserveAdmission($request, $now);

        self::assertFalse($result->admitted);
        self::assertSame('failure', $result->refusedScope);
        self::assertSame($retryAfter, $result->retryAfterSeconds);
        self::assertEquals($result, $inMemory);
        self::assertSame($before, $this->dump());
        self::assertSame($expiry, [$this->raw('PEXPIRETIME', $set), $this->raw('PEXPIRETIME', $pending)]);
    }

    private function settlingScope(string $key, string $member, int $capacity): AdmissionScopeDTO
    {
        return new AdmissionScopeDTO(
            'failure',
            AdmissionScopeKindEnum::SET_SIZE,
            $key,
            $capacity,
            self::TTL,
            member: $member,
            settlesOnExpiry: true,
        );
    }
}
