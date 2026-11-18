<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Ownership;

use Maatify\RateLimiter\Config\SimpleThrottlePolicyInterface;

/** A simple throttle policy whose limit getter runs a Host callback (DEC-041) and can report a configurable limit. */
final class ReentrantSimplePolicy implements SimpleThrottlePolicyInterface
{
    public ?\Closure $reenter = null;

    public function __construct(private readonly string $name = 'api', private readonly int $limit = 5) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getLimit(): int
    {
        if ($this->reenter !== null) {
            ($this->reenter)();
        }

        return $this->limit;
    }

    public function getIntervalSeconds(): int
    {
        return 60;
    }
}
