<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Ownership;

use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Service\CompositeRateLimitOperationalReaderInterface;

/** The runtime or reader that Host code built while another build was still running (DEC-041, F266-15). */
final class BuiltDuringBuild
{
    public ?CompositeRateLimiterRuntimeInterface $runtime = null;

    public ?CompositeRateLimitOperationalReaderInterface $reader = null;

    public ?\Maatify\RateLimiter\Service\FixedWindowSimpleRateLimiter $direct = null;

    /** @var \Fiber<mixed, mixed, mixed, mixed>|null */
    public ?\Fiber $fiber = null;

    public function noop(): void {}
}
