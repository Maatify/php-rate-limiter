<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository\Redis;

/**
 * Callable adapter for the Host-provided raw Redis command executor.
 *
 * The adapter does not own the Redis client or connection lifecycle and does
 * not reclassify failures. It forwards the callable's raw result and
 * preserves the failure provenance established by the Host boundary and the
 * executor contract.
 */
final class CallableRedisCommandExecutor implements RedisCommandExecutorInterface
{
    /** @var \Closure(non-empty-list<int|string|float>): mixed */
    private \Closure $executor;

    /**
     * The callback is an explicit Host boundary: it is responsible for
     * mapping only known operational transport failures to
     * BackendFailureException. This adapter intentionally performs no
     * catch-all reinterpretation.
     *
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
