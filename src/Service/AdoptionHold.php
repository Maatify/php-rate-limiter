<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

/**
 * The hold one entry class took on an existing binding when it adopted it (DEC-041, M2). It names the resource only weakly, so the
 * registry never keeps a store alive through an entry that points back at it, and it releases itself when the entry class is gone or
 * when its build releases it explicitly, so an entry that died never pins a binding (F266-20, review follow-up).
 *
 * @internal
 */
final class AdoptionHold
{
    private bool $released = false;

    /** @param \WeakReference<object> $resource */
    public function __construct(
        private readonly \WeakReference $resource,
        private readonly BindingGeneration $generation,
    ) {}

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        $this->generation->holders = max(0, $this->generation->holders - 1);
        $resource = $this->resource->get();
        if ($resource !== null) {
            OwnershipRegistry::settle($resource);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
