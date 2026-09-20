<?php

declare(strict_types=1);

namespace ConsumerVerification;

use Maatify\RateLimiter\Repository\BudgetSeedStoreInterface;
use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use Maatify\SharedCommon\Contracts\ClockInterface;

final class InMemoryRateLimitStore implements BudgetSeedStoreInterface
{
    /** @var array<string, array{value: int, updatedAt: int, expiresAt: int}> */
    private array $counters = [];

    /** @var array<string, array{level: int, expiresAt: int}> */
    private array $blocks = [];

    /** @var array<string, array{count: int, epochStart: int, epochDuration: int}> */
    private array $budgets = [];

    private int $operations = 0;

    private int $writes = 0;

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function increment(string $key, int $ttlSeconds, int $amount = 1): int
    {
        $this->operations++;
        $this->writes++;
        $now = $this->clock->now()->getTimestamp();

        if (!isset($this->counters[$key]) || $this->counters[$key]['expiresAt'] <= $now) {
            $this->counters[$key] = [
                'value' => $amount,
                'updatedAt' => $now,
                'expiresAt' => $now + $ttlSeconds,
            ];

            return $amount;
        }

        $this->counters[$key]['value'] += $amount;
        $this->counters[$key]['updatedAt'] = $now;

        return $this->counters[$key]['value'];
    }

    public function get(string $key): ?RateLimitStateDTO
    {
        $this->operations++;
        $now = $this->clock->now()->getTimestamp();

        if (!isset($this->counters[$key]) || $this->counters[$key]['expiresAt'] <= $now) {
            return null;
        }

        return new RateLimitStateDTO(
            $this->counters[$key]['value'],
            $this->counters[$key]['updatedAt']
        );
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        $this->operations++;
        $this->writes++;
        $now = $this->clock->now()->getTimestamp();
        $this->counters[$key] = [
            'value' => $value,
            'updatedAt' => $now,
            'expiresAt' => $now + $ttlSeconds,
        ];
    }

    public function block(string $key, int $level, int $durationSeconds): void
    {
        $this->operations++;
        $this->writes++;
        $this->blocks[$key] = [
            'level' => $level,
            'expiresAt' => $this->clock->now()->getTimestamp() + $durationSeconds,
        ];
    }

    public function checkBlock(string $key): ?BlockStateDTO
    {
        $this->operations++;
        $now = $this->clock->now()->getTimestamp();

        if (!isset($this->blocks[$key]) || $this->blocks[$key]['expiresAt'] <= $now) {
            return null;
        }

        return new BlockStateDTO(
            $this->blocks[$key]['level'],
            $this->blocks[$key]['expiresAt']
        );
    }

    public function getBudget(string $key): ?BudgetStateDTO
    {
        $this->operations++;
        $budget = $this->budgets[$key] ?? null;
        if ($budget === null) {
            return null;
        }

        $now = $this->clock->now()->getTimestamp();
        if ($now >= $budget['epochStart'] + $budget['epochDuration']) {
            return null;
        }

        return new BudgetStateDTO($budget['count'], $budget['epochStart']);
    }

    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): BudgetStateDTO
    {
        $this->operations++;
        $this->writes++;
        $now = $this->clock->now()->getTimestamp();
        $budget = $this->budgets[$key] ?? null;

        if ($budget === null || $now >= $budget['epochStart'] + $budget['epochDuration']) {
            $this->budgets[$key] = [
                'count' => $amount,
                'epochStart' => $now,
                'epochDuration' => $epochDurationSeconds,
            ];
        } else {
            $this->budgets[$key]['count'] += $amount;
        }

        return new BudgetStateDTO(
            $this->budgets[$key]['count'],
            $this->budgets[$key]['epochStart']
        );
    }

    public function incrementBudgetWithSeed(
        string $key,
        int $epochDurationSeconds,
        BudgetStateDTO $seed,
        int $amount = 1
    ): BudgetStateDTO
    {
        $this->operations++;
        $this->writes++;
        $now = $this->clock->now()->getTimestamp();
        $budget = $this->budgets[$key] ?? null;

        if ($budget !== null && $now < $budget['epochStart'] + $budget['epochDuration']) {
            $this->budgets[$key]['count'] += $amount;
        } elseif ($now < $seed->epochStart + $epochDurationSeconds) {
            $this->budgets[$key] = [
                'count' => $seed->count + $amount,
                'epochStart' => $seed->epochStart,
                'epochDuration' => $epochDurationSeconds,
            ];
        } else {
            $this->budgets[$key] = [
                'count' => $amount,
                'epochStart' => $now,
                'epochDuration' => $epochDurationSeconds,
            ];
        }

        return new BudgetStateDTO(
            $this->budgets[$key]['count'],
            $this->budgets[$key]['epochStart']
        );
    }

    public function isHealthy(): bool
    {
        $this->operations++;

        return true;
    }

    public function operationCount(): int
    {
        return $this->operations;
    }

    public function writeCount(): int
    {
        return $this->writes;
    }
}
