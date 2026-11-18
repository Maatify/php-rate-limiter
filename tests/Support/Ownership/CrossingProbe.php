<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Ownership;

use Fiber;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;

/**
 * A switch for the Host callbacks of the ownership tests (DEC-041): once armed, `fire()` re-enters the store from the stack of the
 * callback that calls it, and `suspendHere()` suspends the fiber named in `$suspendIn` inside that callback.
 */
final class CrossingProbe
{
    public bool $armed = false;

    public ?RedisFullCapabilityStore $store = null;

    /** @var Fiber<mixed, mixed, mixed, mixed>|null */
    public ?Fiber $suspendIn = null;

    public int $reads = 0;

    public function fire(): void
    {
        if ($this->armed) {
            $this->store?->isHealthy();
        }
    }

    public function suspendHere(): void
    {
        if ($this->suspendIn !== null && Fiber::getCurrent() === $this->suspendIn) {
            Fiber::suspend('inside the Host callback');
        }
    }
}
