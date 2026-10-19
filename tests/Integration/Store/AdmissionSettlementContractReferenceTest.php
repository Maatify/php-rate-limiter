<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Repository\AdmissionCapableFullStoreInterface;
use Maatify\RateLimiter\Repository\AdmissionPermitStoreInterface;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionInMemoryReferenceStore;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionScenarioBuilder;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use PHPUnit\Framework\Attributes\Group;

/** The settlement contract on the in-memory reference store (the executable specification). */
#[Group('settle')]
final class AdmissionSettlementContractReferenceTest extends AdmissionSettlementContractTestCase
{
    protected function newStore(): AdmissionCapableFullStoreInterface&AdmissionPermitStoreInterface
    {
        return new AdmissionInMemoryReferenceStore(new FixedClock('@' . AdmissionScenarioBuilder::NOW));
    }
}
