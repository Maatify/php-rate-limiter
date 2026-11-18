<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Contract;

/**
 * Optional Host contract (DEC-041): the identity of the execution context (Fiber, coroutine, ...) that is running now.
 *
 * Needed only by a scheduler whose contexts are not PHP Fibers; Fibers are recognised without it. One provider INSTANCE is shared by
 * every graph and every directly used package store of the same scheduler: identities of different providers are never compared, and
 * a resource is bound to exactly one provider (or to the default Fiber domain) for as long as that provider lives.
 *
 * Implementation duties: synchronous and cheap; MUST NOT call the library, suspend, yield or switch context, and MUST NOT throw.
 * The returned key is equal (`===`) for the same live context on every call and different for every other live context of the same
 * provider; it may be reused only after the context that had it has ended. `null` is the main stack of that domain.
 */
interface ExecutionContextProviderInterface
{
    /**
     * @return int|string|object|null opaque identity of the running execution context
     */
    public function currentContext(): int|string|object|null;
}
