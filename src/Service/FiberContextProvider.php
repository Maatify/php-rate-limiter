<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use Maatify\RateLimiter\Contract\ExecutionContextProviderInterface;

/**
 * The default execution-context domain: the current PHP Fiber (null on the main stack).
 *
 * @internal
 */
final class FiberContextProvider implements ExecutionContextProviderInterface
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** @return \Fiber<mixed, mixed, mixed, mixed>|null */
    public function currentContext(): ?\Fiber
    {
        return \Fiber::getCurrent();
    }
}
