<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

/**
 * One reservation of one resource by one token. Markers and cleanups refer to the record object they created, never to the slot.
 *
 * @internal
 */
final class SlotRecord
{
    /** The acquisition whose provider lookup is running under this reservation. */
    public ?CallToken $lookupBy = null;

    /** Admission lookups (a nested path that asks the provider) currently running on this record. */
    public int $lookups = 0;

    public function __construct(public readonly CallToken $token) {}
}
