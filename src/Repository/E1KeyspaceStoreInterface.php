<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository;

/**
 * Required capability for score runtime composition (DEC-046), keeping the account-bearing keys of the E1 derivation under their own physical prefix (DEC-044).
 *
 * `e1Keyspace()` returns a view of the SAME store instance: it is not a second store. It shares the request state, the exclusive request
 * ownership and `transactRequest()` of the store it came from, so a request that writes keys of both layouts is saved in one commit
 * (DEC-037, DEC-041). Only the physical-key function differs. The view accepts only the E1 logical key form (`e1.` followed by 64 lowercase
 * hexadecimal characters) and only for the families that hold E1 keys; anything else is refused before any command is issued.
 * The plain string methods of the store itself are not changed by this capability: a string never selects a layout.
 */
interface E1KeyspaceStoreInterface
{
    /** The view of this very store bound to the E1 layout. The root returns a distinct stable view; the view returns itself. No backend commands are issued. */
    public function e1Keyspace(): static;
}
