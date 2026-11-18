<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Ownership;

use Maatify\RateLimiter\Contract\ExecutionContextProviderInterface;

/** A scripted execution-context provider for the ownership tests (DEC-041): counts its calls, returns what its callback returns. */
final class StubContextProvider implements ExecutionContextProviderInterface
{
    public int $calls = 0;

    /** @param callable(): (int|string|object|null) $currentContext */
    public function __construct(private $currentContext) {}

    public function currentContext(): int|string|object|null
    {
        $this->calls++;

        return ($this->currentContext)();
    }
}
