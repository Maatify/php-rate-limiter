<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\E1KeyspaceStoreInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;

/**
 * Captures the exact root/account-view pair before a graph is bound (DEC-046).
 *
 * Construction is closed: callers cannot supply a view independently. Checks issue no backend command; the adapter's view method must
 * itself be pure. Structural identity and interface preservation cannot prove an arbitrary adapter's transaction or storage semantics.
 *
 * @internal Not a Host configuration or extension point.
 */
final readonly class E1StoreBinding
{
    private function __construct(public object $root, public object $view) {}

    public static function capture(object $root): self
    {
        if (! $root instanceof E1KeyspaceStoreInterface) {
            throw new RateLimiterException('Account-scope composition requires E1KeyspaceStoreInterface (DEC-046).');
        }
        $view = $root->e1Keyspace();
        if ($view === $root || $root->e1Keyspace() !== $view || $view->e1Keyspace() !== $view) {
            throw new RateLimiterException('Account-scope composition requires a root and a distinct, stable, idempotent E1 view.');
        }
        foreach (class_implements($root) as $interface) {
            if (! $view instanceof $interface) {
                throw new RateLimiterException('The account view does not preserve the root capability ' . $interface . '.');
            }
        }
        if ($root instanceof RedisFullCapabilityStore && $view instanceof RedisFullCapabilityStore && $view->ownedResources() !== $root->ownedResources()) {
            throw new RateLimiterException('The official account view must own the same root resource.');
        }

        return new self($root, $view);
    }

    /** Reuse a captured pair without asking the adapter for another view. */
    public function forRoot(object $root): object
    {
        if ($this->root !== $root) {
            throw new RateLimiterException('The account-view binding belongs to another root.');
        }

        return $this->view;
    }
}
