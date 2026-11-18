<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

/**
 * A package class that knows the resource objects it owns or reaches (stores, the request clock, the circuit breaker, itself).
 *
 * @internal Not a Host extension point and not required of Host stores: a Host store is a resource by its object identity.
 */
interface OwnedResources
{
    /**
     * @return list<object>
     */
    public function ownedResources(): array;
}
