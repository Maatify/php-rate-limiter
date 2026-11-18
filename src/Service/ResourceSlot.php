<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Contract\ExecutionContextProviderInterface;

/**
 * Per-resource ownership state: the context domain (held by weak reference to the provider) and the current reservation.
 *
 * @internal
 */
final class ResourceSlot
{
    public bool $bound = false;

    public bool $defaultDomain = false;

    /** @var \WeakReference<ExecutionContextProviderInterface>|null */
    public ?\WeakReference $provider = null;

    public ?SlotRecord $record = null;

    /**
     * How many `bindAll` calls are standing on this binding (the one that recorded it and every later one that found it matching and
     * relied on it). A rollback undoes a binding only when no other call stands on it (F266-15): a runtime that was built meanwhile
     * from Host code keeps the binding it was built on.
     */
    public int $holds = 0;

    /** @var array{bool, bool, \WeakReference<ExecutionContextProviderInterface>|null}|null the state to restore when the call that bound this resource rolled back while another call still stood on it, and that one rolls back last */
    public ?array $deferredRestore = null;
}
