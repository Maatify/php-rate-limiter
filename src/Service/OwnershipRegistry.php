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
        $slots = self::slots();
        $slot = $slots[$resource] ?? null;
        if ($slot === null) {
            $slot = new ResourceSlot();
            $slots[$resource] = $slot;
        }

        return $slot;
    }

    /** @return \WeakMap<object, ResourceSlot> */
    private static function slots(): \WeakMap
    {
        if (self::$slots === null) {
            /** @var \WeakMap<object, ResourceSlot> $created */
            $created = new \WeakMap();
            self::$slots = $created;
        }

        return self::$slots;
    }

    /**
     * Deduplicated resources: stores and the other objects of a graph, by object identity. A clock is a resource only when it is the
     * request clock (a package class that reports itself); a raw Host clock holds no per-call state and is left out by its ROLE (the
     * `$clocks` argument), never by its type: an object passed as a store stays protected even if it also implements the clock interface.
     *
     * @param array<int, object|null> $objects collaborators and stores: a package class is expanded through its own `ownedResources()`, any other object is a resource by identity
     * @param array<int, object> $selves the caller's own objects, taken as they are (never expanded, so a class cannot recurse into itself)
     * @param array<int, object|null> $clocks the clock collaborators: expanded when package-owned, otherwise not a resource
     * @return list<object>
     */
    public static function collect(array $objects, array $selves = [], array $clocks = []): array
    {
        $unique = [];
        foreach ($selves as $self) {
            $unique[spl_object_id($self)] = $self;
        }
        foreach ($objects as $object) {
            if ($object === null) {
                continue;
            }
            if (self::isPackageOwned($object)) {
                /** @var OwnedResources $object */
                $inner = $object->ownedResources();
                foreach ($inner as $resource) {
                    $unique[spl_object_id($resource)] = $resource;
                }
                continue;
            }
            $unique[spl_object_id($object)] = $object;
        }
        foreach ($clocks as $clock) {
            if ($clock === null || ! self::isPackageOwned($clock)) {
                continue; // a raw Host clock is not a resource of the call
            }
            /** @var OwnedResources $owned */
            $owned = $clock;
            foreach ($owned->ownedResources() as $resource) {
                $unique[spl_object_id($resource)] = $resource;
            }
        }

        return array_values($unique);
    }

    /**
     * The classes of this package that report their own resources. Only a method DECLARED by one of these is trusted: a Host
     * store or collaborator that happens to have a method of the same name (or that implements the internal interface itself) is a
     * resource by its object identity and its code is never called to build the protected set (#260, F266-14).
     */
    private const PACKAGE_OWNED = [
        \Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore::class,
        RateLimiterEngine::class,
        EvaluationPipeline::class,
        CircuitBreaker::class,
        RequestScopedClock::class,
        BudgetTracker::class,
        AntiEquilibriumGate::class,
        DecayCalculator::class,
        EphemeralBucket::class,
        HostClock::class,
        AdmissionRuntime::class,
    ];

    private static function isPackageOwned(object $object): bool
    {
        if (! method_exists($object, 'ownedResources')) {
            return false;
        }

        return in_array((new \ReflectionMethod($object, 'ownedResources'))->getDeclaringClass()->getName(), self::PACKAGE_OWNED, true);
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
        // Step 2: record the domain on the resources that were unbound or vacated; a matching binding is relied on (one more holder).
        $ledger = new BindingLedger();
        foreach ($set as $resource) {
            $slot = self::slot($resource);
            if ($slot->bound && self::sameDomain($slot, $provider) && $slot->generation !== null) {
                $slot->generation->holders++;
                $ledger->entries[] = [$resource, $slot->generation, false];
                continue;
            }
            $generation = new BindingGeneration($slot->bound, $slot->defaultDomain, $slot->provider);
            $slot->generation = $generation;
            $slot->bound = true;
            $slot->defaultDomain = $provider === null;
            $slot->provider = $provider === null ? null : \WeakReference::create($provider);
            $ledger->entries[] = [$resource, $generation, true];
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

    /**
     * Releases what one `bindAll` call stands on. A binding is undone, back to the state it replaced, only when nobody stands on it any
     * more (the call that recorded it has rolled back and so has every other holder), and only when no call is active on the resource:
     * an active call keeps its domain until its last release, and the undo happens then (F266-17).
     */
    public static function rollback(BindingLedger $ledger): void
    {
        foreach (array_reverse($ledger->entries) as [$resource, $generation, $created]) {
            $generation->holders = max(0, $generation->holders - 1);
            if ($created) {
                $generation->creatorRolledBack = true;
            }
            self::settle($resource);
        }
        $ledger->entries = [];
    }

    /** Undoes the binding of a resource when it was abandoned (nobody stands on it) and no call is active on the resource. */
    private static function settle(object $resource): void
    {
        $slot = self::slot($resource);
        $generation = $slot->generation;
        if ($generation === null || $slot->record !== null || $generation->holders > 0 || ! $generation->creatorRolledBack) {
            return;
        }
        $slot->bound = $generation->previousBound;
        $slot->defaultDomain = $generation->previousDefault;
        $slot->provider = $generation->previousProvider;
        $slot->generation = null; // the state restored is unbound or vacated: nothing stands on it and nothing is kept of the undone binding
    }

    /** @var \WeakMap<object, BuildFrames>|null the build frames of each Fiber */
    private static ?\WeakMap $fiberFrames = null;

    private static ?BuildFrames $mainFrames = null;

    /** @return \WeakMap<object, BuildFrames> */
    private static function fiberFrameMap(): \WeakMap
    {
        if (self::$fiberFrames === null) {
            /** @var \WeakMap<object, BuildFrames> $created */
            $created = new \WeakMap();
            self::$fiberFrames = $created;
        }

        return self::$fiberFrames;
    }

    /** The build frames of the execution context that is running now: a build suspended in one Fiber never marks another context. */
    private static function frames(): BuildFrames
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            return self::$mainFrames ??= new BuildFrames();
        }
        $map = self::fiberFrameMap();
        $frames = $map[$fiber] ?? null;
        if ($frames === null) {
            $frames = new BuildFrames();
            $map[$fiber] = $frames;
        }

        return $frames;
    }

    /**
     * Runs the construction of the components of a graph that a Builder assembles for itself: an entry class that adopts a binding
     * inside it is part of THIS build and does not pin it, whatever Host code runs around (F266-18).
     *
     * @template T
     * @param callable(): T $construction
     * @return T
     */
    public static function buildScope(callable $construction): mixed
    {
        return self::inFrame('own', $construction);
    }

    /**
     * Runs Host code that executes while a graph is being built (policy getters, for example). A runtime or reader that Host code
     * builds by hand from inside it stands on the binding it adopts, so the failure of the build that is running does not undo that
     * binding; a Builder that Host code runs opens a scope of its own for the components it builds (F266-17, F266-18).
     *
     * @template T
     * @param callable(): T $hostCode
     * @return T
     */
    public static function hostRegion(callable $hostCode): mixed
    {
        return self::inFrame('host', $hostCode);
    }

    /**
     * @template T
     * @param 'own'|'host' $kind
     * @param callable(): T $code
     * @return T
     */
    private static function inFrame(string $kind, callable $code): mixed
    {
        $frames = self::frames();
        $frames->frames[] = $kind;
        try {
            return $code();
        } finally {
            array_pop($frames->frames);
        }
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
        $ledger = self::bindAll($resources, $domain->rawProvider());
        // An entry class adopting a binding that already existed does not stand on it while the graph that records it is being built
        // (it would pin a build that fails); an entry built from Host code inside such a build does, and a resource it binds itself stays.
        if (end(self::frames()->frames) === 'own') {
            foreach ($ledger->entries as [, $generation, $created]) {
                if (! $created) {
                    $generation->holders = max(0, $generation->holders - 1);
                }
            }
        }

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
            self::settle($resource);
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
