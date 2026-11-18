<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Contract\ExecutionContextProviderInterface;
use Maatify\RateLimiter\Exception\OverlappingRequestException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\RequestScopedClock;

/**
 * Ownership of the in-process state of one request, per resource object (DEC-041).
 *
 * One independent call at a time owns the deduplicated set of resources its entry uses. An entry acquires the whole set all-or-none:
 * phase 0 reserves every slot without any Host call, phase 1 asks the execution-context provider (Host code) under the reservation.
 * Every reservation, marker and cleanup belongs to the record or token the call itself created. Nested operations of the owner are
 * recognised by the owner's execution context and the absence of an open Host crossing; anything else is refused.
 *
 * @internal Not part of the supported API.
 */
final class OwnershipRegistry
{
    /** @var \WeakMap<object, ResourceSlot>|null */
    private static ?\WeakMap $slots = null;

    private static function slot(object $resource): ResourceSlot
    {
        self::$slots ??= new \WeakMap();
        if (! isset(self::$slots[$resource])) {
            self::$slots[$resource] = new ResourceSlot();
        }

        return self::$slots[$resource];
    }

    /**
     * Deduplicated resources: stores and the other objects of a graph; a clock only when it is the request clock.
     *
     * @param array<int, object|null> $objects collaborators: expanded through their own `ownedResources()` when they have one
     * @param array<int, object> $selves the caller's own objects, taken as they are (never expanded, so a class cannot recurse into itself)
     * @return list<object>
     */
    public static function collect(array $objects, array $selves = []): array
    {
        $unique = [];
        foreach ($selves as $self) {
            $unique[spl_object_id($self)] = $self;
        }
        foreach ($objects as $object) {
            if ($object === null) {
                continue;
            }
            if ($object instanceof OwnedResources || method_exists($object, 'ownedResources')) {
                /** @var iterable<object> $inner */
                $inner = $object->ownedResources();
                foreach ($inner as $resource) {
                    $unique[spl_object_id($resource)] = $resource;
                }
                continue;
            }
            if ($object instanceof \Maatify\SharedCommon\Contracts\ClockInterface) {
                continue; // a raw Host clock holds no per-call state; the request clock reports itself through ownedResources()
            }
            $unique[spl_object_id($object)] = $object;
        }

        return array_values($unique);
    }

    /**
     * Binds the whole set to one context domain, or fails without changing anything (step 1 validates, step 2 records only the unbound).
     *
     * @param array<int, object> $resources
     * @throws RateLimiterException on a domain mismatch or a replacement that would touch an active ownership state
     */
    public static function bindAll(array $resources, ?ExecutionContextProviderInterface $provider): BindingLedger
    {
        $set = self::canonical($resources);
        // Step 1: validate everything first.
        foreach ($set as $resource) {
            $slot = self::slot($resource);
            if (! $slot->bound) {
                continue;
            }
            if (self::sameDomain($slot, $provider)) {
                continue;
            }
            if (! $slot->defaultDomain && $slot->provider?->get() === null) {
                if ($slot->record !== null) {
                    throw new RateLimiterException(sprintf('Cannot replace the execution context binding of %s: its previous provider is gone but an ownership state still exists on it.', $resource::class));
                }
                continue; // a vacated binding with no owner, reservation or lookup is replaceable
            }
            throw new RateLimiterException(sprintf('The execution context domain of %s differs from the one requested: share one provider instance among the graphs and stores of one scheduler.', $resource::class));
        }
        // Step 2: record the domain on the resources that were unbound or vacated.
        $ledger = new BindingLedger();
        foreach ($set as $resource) {
            $slot = self::slot($resource);
            if ($slot->bound && self::sameDomain($slot, $provider)) {
                continue;
            }
            $ledger->entries[] = [$resource, $slot->bound, $slot->defaultDomain, $slot->provider];
            $slot->bound = true;
            $slot->defaultDomain = $provider === null;
            $slot->provider = $provider === null ? null : \WeakReference::create($provider);
        }

        return $ledger;
    }

    /**
     * Binds a graph's resource set: to the configured provider, or, when none is configured, to the provider a resource of the set is
     * already bound to (a store built with its own provider), or to the default Fiber domain. Set-wide and failure-atomic like `bindAll`.
     *
     * @param array<int, object> $resources
     */
    public static function bindGraph(array $resources, ?ExecutionContextProviderInterface $provider): BindingLedger
    {
        if ($provider === null) {
            foreach ($resources as $resource) {
                $slot = self::slot($resource);
                $held = $slot->bound && ! $slot->defaultDomain ? $slot->provider?->get() : null;
                if ($held instanceof ExecutionContextProviderInterface) {
                    $provider = $held;
                    break;
                }
            }
        }

        return self::bindAll($resources, $provider);
    }

    /** Undoes exactly what one `bindAll` bound, restoring each previous state. */
    public static function rollback(BindingLedger $ledger): void
    {
        foreach (array_reverse($ledger->entries) as [$resource, $bound, $default, $provider]) {
            $slot = self::slot($resource);
            if ($slot->record !== null) {
                continue;
            }
            $slot->bound = $bound;
            $slot->defaultDomain = $default;
            $slot->provider = $provider;
        }
        $ledger->entries = [];
    }

    /**
     * The domain an entry class works in: the one already recorded on its resources, or the default; unbound resources are bound to it.
     *
     * @param array<int, object> $resources
     */
    public static function adopt(array $resources): ContextDomain
    {
        $candidate = null;
        foreach ($resources as $resource) {
            $slot = self::slot($resource);
            if (! $slot->bound) {
                continue;
            }
            $held = $slot->defaultDomain ? null : $slot->provider?->get();
            if (! $slot->defaultDomain && $held === null) {
                continue; // vacated
            }
            $candidate = ContextDomain::forProvider($held instanceof ExecutionContextProviderInterface ? $held : null);
            break;
        }
        $domain = $candidate ?? ContextDomain::default();
        self::bindAll($resources, $domain->rawProvider());

        return $domain;
    }

    /**
     * Acquires the whole set, all-or-none. Refuses, before any Host call, when any resource is occupied.
     *
     * @param array<int, object> $resources
     * @throws OverlappingRequestException when a resource is owned, reserved or under lookup
     * @throws RateLimiterException when the provider fails or the domain binding is inconsistent
     */
    public static function acquire(array $resources, string $entry, ContextDomain $domain): CallScope
    {
        $set = self::canonical($resources);
        $lease = $domain->provider();
        $token = new CallToken();
        $scope = new CallScope($token, $lease);
        $ok = false;
        $providerFailure = null;
        $key = null;
        try {
            // Phase 0: provider-free reservation, all-or-none.
            foreach ($set as $resource) {
                $slot = self::slot($resource);
                if (! self::slotInDomain($slot, $domain)) {
                    throw new RateLimiterException(sprintf('Inconsistent execution context domain on %s at %s.', $resource::class, $entry));
                }
                if ($slot->record !== null) {
                    throw new OverlappingRequestException($entry);
                }
                $record = new SlotRecord($token);
                $slot->record = $record;
                $scope->add($resource, $record);
            }
            // Phase 1: the identity lookup (Host code) under the reservations.
            $token->crossings++;
            foreach ($scope->records() as $record) {
                $record->lookupBy = $token;
            }
            try {
                $key = $lease->currentContext();
            } catch (\Throwable $failure) {
                $providerFailure = $failure;
            } finally {
                foreach ($scope->records() as $record) {
                    if ($record->lookupBy === $token) {
                        $record->lookupBy = null;
                    }
                }
                $token->crossings--;
            }
            if ($providerFailure !== null) {
                throw new RateLimiterException('The execution context provider failed.', 0, $providerFailure);
            }
            $token->own($key);
            $ok = true;

            return $scope;
        } finally {
            if (! $ok) {
                $scope->release();
            }
        }
    }

    /**
     * Admission of an operation on one resource: nested in the owner's call (null), a short-lived owner when nobody owns it, or refused.
     *
     * @throws OverlappingRequestException
     * @throws RateLimiterException
     */
    public static function admit(object $resource, string $entry, ?ContextDomain $own = null): ?CallScope
    {
        $slot = self::slot($resource);
        if ($slot->record === null) {
            return self::acquire([$resource], $entry, self::domainOf($resource, $slot, $own));
        }
        $record = $slot->record;
        $token = $record->token;
        if ($token->state !== CallToken::OWNED || $record->lookupBy !== null || $record->lookups > 0 || $token->crossings > 0) {
            throw new OverlappingRequestException($entry);
        }
        $domain = self::domainOf($resource, $slot, $own);
        $lease = $domain->provider();
        $record->lookups++;
        try {
            $key = $lease->currentContext();
        } catch (\Throwable $failure) {
            throw new RateLimiterException('The execution context provider failed.', 0, $failure);
        } finally {
            if ($slot->record === $record) {
                $record->lookups--;
            }
        }
        if (! $token->sameContext($key)) {
            throw new OverlappingRequestException($entry);
        }

        return null;
    }

    /**
     * Runs Host code with a crossing open on the call that owns the resource (no effect when nobody does).
     *
     * @template T
     * @param callable(): T $hostCall
     * @return T
     */
    public static function crossing(object $resource, callable $hostCall): mixed
    {
        $record = self::slot($resource)->record;
        if ($record === null) {
            return $hostCall();
        }
        $token = $record->token;
        $token->crossings++;
        try {
            return $hostCall();
        } finally {
            $token->crossings--;
        }
    }

    /** Clears the slot only if it still holds this very record. */
    public static function clear(object $resource, SlotRecord $record): void
    {
        $slot = self::slot($resource);
        if ($slot->record === $record) {
            $slot->record = null;
        }
    }

    /** Test and diagnostics support: whether the resource has an owner, a reservation or a lookup. */
    public static function isOccupied(object $resource): bool
    {
        return self::slot($resource)->record !== null;
    }

    /** Test support: the domain kind recorded on a resource: 'unbound', 'default', 'provider' or 'vacated'. */
    public static function domainKind(object $resource): string
    {
        $slot = self::slot($resource);
        if (! $slot->bound) {
            return 'unbound';
        }
        if ($slot->defaultDomain) {
            return 'default';
        }

        return $slot->provider?->get() === null ? 'vacated' : 'provider';
    }

    private static function domainOf(object $resource, ResourceSlot $slot, ?ContextDomain $own): ContextDomain
    {
        if (! $slot->bound) {
            $provider = $own?->rawProvider();
            self::bindAll([$resource], $provider);

            return $own ?? ContextDomain::default();
        }
        if ($slot->defaultDomain) {
            return ContextDomain::default();
        }
        $provider = $slot->provider?->get();
        if (! $provider instanceof ExecutionContextProviderInterface) {
            throw new RateLimiterException(sprintf('The execution context provider bound to %s is gone: construct the store with a provider or build a graph that supplies one.', $resource::class));
        }

        return ContextDomain::forProvider($provider);
    }

    private static function slotInDomain(ResourceSlot $slot, ContextDomain $domain): bool
    {
        if (! $slot->bound) {
            return false;
        }
        if ($domain->isDefault()) {
            return $slot->defaultDomain;
        }

        return ! $slot->defaultDomain && $slot->provider?->get() === $domain->rawProvider();
    }

    private static function sameDomain(ResourceSlot $slot, ?ExecutionContextProviderInterface $provider): bool
    {
        if ($provider === null) {
            return $slot->defaultDomain;
        }

        return ! $slot->defaultDomain && $slot->provider?->get() === $provider;
    }

    /**
     * @param array<int, object> $resources
     * @return list<object>
     */
    private static function canonical(array $resources): array
    {
        $unique = [];
        foreach ($resources as $resource) {
            $unique[spl_object_id($resource)] = $resource;
        }
        ksort($unique);

        return array_values($unique);
    }
}
