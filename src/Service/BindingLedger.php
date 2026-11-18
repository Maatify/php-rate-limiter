<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

/**
 * What one `bindAll` call stands on: for each resource the binding generation it recorded (`created`) or relied on, so that a later
 * failure of that call releases exactly its own holds.
 *
 * @internal
 */
final class BindingLedger
{
    /** @var list<array{object, BindingGeneration, bool}> resource, generation, whether this call recorded it */
    public array $entries = [];
}
