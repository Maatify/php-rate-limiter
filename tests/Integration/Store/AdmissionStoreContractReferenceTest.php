<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\AdmissionCapableFullStoreInterface;
use Maatify\RateLimiter\Repository\AdmissionPermitStoreInterface;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionInMemoryReferenceStore;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionScenarioBuilder as S;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;

/** The same contract on the in-memory reference store (the executable specification the engine tests use). */
final class AdmissionStoreContractReferenceTest extends AdmissionStoreContractTestCase
{
    protected function newStore(): AdmissionCapableFullStoreInterface&AdmissionPermitStoreInterface
    {
        return new AdmissionInMemoryReferenceStore(new FixedClock('@' . self::NOW));
    }

    public function testCounterAdmissionWithoutPermitOrMemberLeavesReferenceStoreUnchanged(): void
    {
        $store = $this->newStore();
        try {
            $store->reserveAdmission(S::request(S::counter('src', 'c:no-member', 5)), self::NOW);
            self::fail('Expected RateLimiterException was not thrown.');
        } catch (RateLimiterException $e) {
            self::assertSame('A counter admission scope requires a member.', $e->getMessage());
        }
        $ref = new \ReflectionProperty(AdmissionInMemoryReferenceStore::class, 'counters');
        self::assertSame([], $ref->getValue($store));
    }
}
