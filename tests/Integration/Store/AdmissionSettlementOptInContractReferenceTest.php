<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Repository\AdmissionCapableFullStoreInterface;
use Maatify\RateLimiter\Repository\AdmissionPermitStoreInterface;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionInMemoryReferenceStore;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionScenarioBuilder;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use PHPUnit\Framework\Attributes\Group;

/** The opt-in settlement contract on the in-memory reference store. */
#[Group('settle-opt')]
final class AdmissionSettlementOptInContractReferenceTest extends AdmissionSettlementOptInContractTestCase
{
    protected function newStore(): AdmissionCapableFullStoreInterface&AdmissionPermitStoreInterface
    {
        return new AdmissionInMemoryReferenceStore(new FixedClock('@' . AdmissionScenarioBuilder::NOW));
    }
}
