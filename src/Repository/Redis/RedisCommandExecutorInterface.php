<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository\Redis;

use Maatify\RateLimiter\Exception\BackendFailureException;

/**
 * Host-facing boundary for executing raw Redis commands.
 *
 * The Host owns the Redis client and connection lifecycle. This package does
 * not depend on ext-redis or Predis; an implementation returns the raw result
 * of the command. The health PING reply is accepted as exactly 'PONG' or
 * exactly true (ext-redis rawCommand() may return bool(true); DEC-018).
 * Only an identified operational transport or backend failure may be
 * classified as BackendFailureException. Programming, protocol, and
 * unknown failures remain distinguishable and must not be blanket-wrapped.
 */
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
