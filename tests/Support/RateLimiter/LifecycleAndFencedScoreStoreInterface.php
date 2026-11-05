<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\RateLimiter;

use Maatify\RateLimiter\Repository\PunishmentLifecycleStoreInterface;
use Maatify\RateLimiter\Repository\SnapshotFencedScoreStoreInterface;

/** A test-only shape: every rate-limit capability `build()` needs except the DEC-031 marker replace (#185, F-185-17). */
interface LifecycleAndFencedScoreStoreInterface extends PunishmentLifecycleStoreInterface, SnapshotFencedScoreStoreInterface {}
