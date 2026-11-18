<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Contract\ExecutionContextProviderInterface;

/**
 * The resources a `bindAll` call bound by itself, with their previous state, so a later failure rolls back only those.
 *
 * @internal
 */
final class BindingLedger
{
    /** @var list<array{object, bool, bool, \WeakReference<ExecutionContextProviderInterface>|null}> */
    public array $entries = [];

    /** @var list<object> every resource of the set this call stands on (newly bound or already matching) */
    public array $held = [];
}
