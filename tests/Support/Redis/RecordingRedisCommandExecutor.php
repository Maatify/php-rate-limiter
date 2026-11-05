<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Redis;

use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;

/** Forwards every command to an inner executor and records it, in order. */
final class RecordingRedisCommandExecutor implements RedisCommandExecutorInterface
{
    /** @var list<non-empty-list<int|string|float>> */
    public array $commands = [];

    public function __construct(private readonly RedisCommandExecutorInterface $inner) {}

    public function execute(array $command): mixed
    {
        $this->commands[] = $command;

        return $this->inner->execute($command);
    }
}
