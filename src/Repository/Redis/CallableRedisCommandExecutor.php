<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository\Redis;

final class CallableRedisCommandExecutor implements RedisCommandExecutorInterface
{
    /** @var \Closure(non-empty-list<int|string|float>): mixed */
    private \Closure $executor;

    /**
     * @param callable(non-empty-list<int|string|float>): mixed $executor
     */
    public function __construct(callable $executor)
    {
        $this->executor = \Closure::fromCallable($executor);
    }

    /**
     * @param non-empty-list<int|string|float> $command
     */
    public function execute(array $command): mixed
    {
        return ($this->executor)($command);
    }
}
