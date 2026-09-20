<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Contract;

use Maatify\RateLimiter\DTO\FailureSignalDTO;

/**
 * Receives observable circuit-breaker and failure-mode transitions.
 */
interface FailureSignalEmitterInterface
{
    /**
     * Emit a signal without changing the rate-limiter decision itself.
     */
    public function emit(FailureSignalDTO $signal): void;
}
