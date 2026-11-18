<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Contract\ExecutionContextProviderInterface;

/**
 * One recorded binding of a resource to a context domain (DEC-041, M2), with the calls that stand on THIS binding and the state it
 * replaced. A binding is a generation of its own: a call that stood on an earlier generation never keeps a later one alive, so a
 * rollback of a replacement is decided by the holders of the replacement alone (#260, F266-17).
 *
 * @internal
 */
final class BindingGeneration
{
    /** @var int the calls standing on this binding: the one that recorded it, every later `bindAll` that relied on it, and every object built from Host code while it was being built */
    public int $holders = 1;

    /** True once the call that recorded this binding rolled back while others still stood on it: the last one standing to roll back undoes it. */
    public bool $creatorRolledBack = false;

    /**
     * @param \WeakReference<ExecutionContextProviderInterface>|null $previousProvider
     */
    public function __construct(
        public readonly bool $previousBound,
        public readonly bool $previousDefault,
        public readonly ?\WeakReference $previousProvider,
        public readonly ?self $previous,
    ) {}
}
