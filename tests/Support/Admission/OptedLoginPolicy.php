<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Enum\PolicyCapabilityEnum;

/**
 * The shipped Login preset (same name, thresholds, deltas, budget, allowance and lifecycle) plus the admission
 * capability. Keeping the preset's name keeps every derived key comparable with the archived study runs.
 */
class OptedLoginPolicy extends LoginProtectionPolicy
{
    /** @return list<PolicyCapabilityEnum> */
    public function getCapabilities(): array
    {
        return [...parent::getCapabilities(), PolicyCapabilityEnum::ADMISSION_RESERVATION];
    }
}
