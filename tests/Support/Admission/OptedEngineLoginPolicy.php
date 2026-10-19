<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\Config\AdmissionReservationPolicyInterface;
use Maatify\RateLimiter\Enum\AdmissionHostModeEnum;

/** The shipped Login preset with the admission capability and its A-2 declaration, for engine-level gates. */
final class OptedEngineLoginPolicy extends OptedLoginPolicy implements AdmissionReservationPolicyInterface
{
    public function __construct(private readonly string $budgetGroup = 'login') {}

    public function getAdmissionHostMode(): AdmissionHostModeEnum
    {
        return AdmissionHostModeEnum::PRE_AND_OUT;
    }

    public function getAdmissionBudgetGroup(): string
    {
        return $this->budgetGroup;
    }
}
