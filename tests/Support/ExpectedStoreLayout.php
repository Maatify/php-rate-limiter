<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support;

use Maatify\RateLimiter\Repository\E1KeyspaceStoreInterface;
use Maatify\RateLimiter\Service\E1AccountScopeKeyDeriver;

/**
 * Test oracle only: fixture-derived account identities must be observed/seeded in the account view. Production routing never uses
 * this helper. A production mutation routed to the root incorrectly remains absent here and fails its behavioral assertion.
 */
final class ExpectedStoreLayout
{
    /**
     * @template T of object
     * @param T $store
     * @return T
     */
    public static function forKey(object $store, string $expectedKey): object
    {
        if ($store instanceof E1KeyspaceStoreInterface && E1AccountScopeKeyDeriver::hasKeyForm($expectedKey)) {
            return $store->e1Keyspace();
        }

        return $store;
    }
}
