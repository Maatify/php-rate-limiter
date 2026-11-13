<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

use Maatify\RateLimiter\DTO\AdmissionSharedConfigDTO;

/**
 * A store that holds the shared admission configuration of a budget group and checks it with every reservation that carries
 * one (DEC-038). The reservation raises `AdmissionConfigurationException` when the carried value differs from the registered
 * one; an unregistered group is registered by the first accepted reservation. Rebinding is the only way to change the
 * registered value and is an explicit operator action.
 */
interface SharedAdmissionConfigStoreInterface
{
    /** Set the registered value of `$budgetGroup` to the value of `$config`, replacing whatever is registered. */
    public function rebindAdmissionConfig(string $budgetGroup, AdmissionSharedConfigDTO $config): void;
}
