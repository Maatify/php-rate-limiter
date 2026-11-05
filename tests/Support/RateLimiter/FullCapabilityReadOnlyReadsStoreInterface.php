<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\RateLimiter;

use Maatify\RateLimiter\Repository\FullCapabilityStoreInterface;
use Maatify\RateLimiter\Repository\OperationalReadOnlyStoreInterface;

/** A test-only shape: the full-capability store with the read-only reads of Operational Read (#185, DEC-032), and no more. */
interface FullCapabilityReadOnlyReadsStoreInterface extends FullCapabilityStoreInterface, OperationalReadOnlyStoreInterface {}
