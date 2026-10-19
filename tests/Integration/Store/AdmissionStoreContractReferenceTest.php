<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Repository\AdmissionCapableFullStoreInterface;
use Maatify\RateLimiter\Repository\AdmissionPermitStoreInterface;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionInMemoryReferenceStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;

/** The same contract on the in-memory reference store (the executable specification the engine tests use). */
final class AdmissionStoreContractReferenceTest extends AdmissionStoreContractTestCase
{
    protected function newStore(): AdmissionCapableFullStoreInterface&AdmissionPermitStoreInterface
    {
        return new AdmissionInMemoryReferenceStore(new FixedClock('@' . self::NOW));
    }
}
