<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;

/** A recorded outcome applied to the scopes its admission touched (failure sets, refunds). */
final readonly class AdmissionOutcomeDTO
{
    /** @param list<AdmissionScopeDTO> $scopes */
    public function __construct(
        public string $verifierClass,
        public AdmissionOutcomeKindEnum $outcome,
        public array $scopes,
        public ?string $permitId = null,
    ) {
        if (trim($verifierClass) === '') {
            throw new RateLimiterException('Admission verifier class must not be empty.');
        }
        if ($permitId !== null && trim($permitId) === '') {
            throw new RateLimiterException('Admission permit id must not be blank.');
        }
    }
}
