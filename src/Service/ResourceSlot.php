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

    /** The binding recorded on this resource (null while unbound). */
    public ?BindingGeneration $generation = null;
}
