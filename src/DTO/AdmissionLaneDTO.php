<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

use Maatify\RateLimiter\Enum\AdmissionLaneEnum;

/**
 * The lane of one request and, for the proven lane, the verified credential claims (the proof reference `jti` reaches the
 * reservation through them). Never object state: it is returned by the admission runtime and passed explicitly.
 *
 * @internal
 */
final readonly class AdmissionLaneDTO
{
    public function __construct(
        public AdmissionLaneEnum $lane,
        public ?AdmissionCredentialDTO $claims = null,
    ) {}
}
