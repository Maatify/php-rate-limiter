<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

/**
 * The frames of the builds that are running in ONE execution context (the main stack or one Fiber): `own` while a Builder constructs
 * the components of its own graph, `host` while Host code (a policy getter) runs inside a build. The innermost frame decides who an
 * entry class that adopts a binding is (DEC-041, F266-18).
 *
 * @internal
 */
final class BuildFrames
{
    /** @var list<'own'|'host'> */
    public array $frames = [];
}
