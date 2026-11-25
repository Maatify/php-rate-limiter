<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\FullCapability;

use Maatify\RateLimiter\Repository\FullCapabilityStoreInterface;
use Maatify\RateLimiter\Repository\OperationalReadOnlyStoreInterface;
use Maatify\RateLimiter\Repository\SnapshotFencedScoreStoreInterface;
use Maatify\RateLimiter\Repository\CircuitBreakerStateCasStoreInterface;
use Maatify\RateLimiter\Repository\ScoreReplaceStoreInterface;

/** Negative composition fixture: all default score capabilities except E1. */
interface NonE1FullStoreInterface extends FullCapabilityStoreInterface, OperationalReadOnlyStoreInterface, SnapshotFencedScoreStoreInterface, CircuitBreakerStateCasStoreInterface, ScoreReplaceStoreInterface {}
