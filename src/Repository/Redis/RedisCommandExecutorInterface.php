<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository\Redis;

interface RedisCommandExecutorInterface
{
    /**
     * @param non-empty-list<int|string|float> $command
     */
    public function execute(array $command): mixed;
}
