<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

/**
 * The single token one call holds on every resource of its set. It holds no strong reference to its resources or to an object context.
 *
 * @internal
 */
final class CallToken
{
    public const RESERVED = 'reserved';

    public const OWNED = 'owned';

    public const RELEASED = 'released';

    public string $state = self::RESERVED;

    /** Open Host crossings of this call (executor, emitter, clock, resolver, provider). */
    public int $crossings = 0;

    /** @var list<CallScope> scopes that joined this call's ownership (F300-03/04/05): they end with it, whichever ends first */
    public array $joins = [];

    private int|string|null $scalarKey = null;

    /** @var \WeakReference<object>|null */
    private ?\WeakReference $objectKey = null;

    private bool $keyIsObject = false;

    public function own(int|string|object|null $key): void
    {
        if (is_object($key)) {
            $this->objectKey = \WeakReference::create($key);
            $this->keyIsObject = true;
        } else {
            $this->scalarKey = $key;
        }
        $this->state = self::OWNED;
    }

    /** Equal only for the same live context; an object context that has expired is equal to nothing. */
    public function sameContext(int|string|object|null $current): bool
    {
        if ($this->keyIsObject) {
            $held = $this->objectKey?->get();

            return $held !== null && $held === $current;
        }

        return $this->scalarKey === $current;
    }
}
