<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Result of a reservation: admitted, or refused by the first refusing scope with nothing written.
 *
 * `$refusedScope` is the name of the refusing scope (or `permit_exists` for a permit id that already exists) and is
 * `null` exactly when `$admitted`; `$retryAfterSeconds` is the non-negative wait until that scope can admit. The
 * constructor throws `RateLimiterException` for an admitted result that names a scope, a refusal that names none, or
 * a negative wait.
 */
final readonly class AdmissionReservationResultDTO
{
    public function __construct(
        public bool $admitted,
        public ?string $refusedScope = null,
        public int $retryAfterSeconds = 0,
    ) {
        if ($admitted && $refusedScope !== null) {
            throw new RateLimiterException('An admitted reservation cannot name a refusing scope.');
        }
        if (! $admitted && ($refusedScope === null || trim($refusedScope) === '')) {
            throw new RateLimiterException('A refused reservation must name the refusing scope.');
        }
        if ($retryAfterSeconds < 0) {
            throw new RateLimiterException('Retry-after must not be negative.');
        }
    }
}
