<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

use Maatify\RateLimiter\Exception\RateLimiterException;

/** One all-or-nothing reservation over every scope of one verifier class. */
final readonly class AdmissionReservationRequestDTO
{
    /** @param list<AdmissionScopeDTO> $scopes */
    public function __construct(
        public string $verifierClass,
        public array $scopes,
        public ?AdmissionPermitDTO $permit = null,
    ) {
        if (trim($verifierClass) === '') {
            throw new RateLimiterException('Admission verifier class must not be empty.');
        }
        if ($scopes === []) {
            throw new RateLimiterException('An admission reservation requires at least one scope.');
        }
    }
}
