<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

use Maatify\RateLimiter\Enum\AdmissionCredentialVerdictEnum;

/** Verdict of a credential check. Claims are exposed only for a VALID credential. */
final readonly class AdmissionCredentialVerificationDTO
{
    public function __construct(
        public AdmissionCredentialVerdictEnum $verdict,
        public ?AdmissionCredentialDTO $claims = null,
    ) {}

    public function isValid(): bool
    {
        return $this->verdict === AdmissionCredentialVerdictEnum::VALID;
    }
}
