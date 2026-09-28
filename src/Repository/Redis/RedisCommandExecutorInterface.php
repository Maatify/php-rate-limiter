<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository\Redis;

use Maatify\RateLimiter\Exception\BackendFailureException;

interface RedisCommandExecutorInterface
{
    /**
     * Execute one raw command.
     *
     * Implementations MUST throw BackendFailureException only for an
     * explicitly identified operational transport/backend outage. Unknown
     * throwables, TypeError, programming failures, malformed protocol data,
     * and Redis/server error replies MUST propagate unchanged.
     *
     * @throws BackendFailureException
     * @throws \Throwable
     * @param non-empty-list<int|string|float> $command
     */
    public function execute(array $command): mixed;
}
