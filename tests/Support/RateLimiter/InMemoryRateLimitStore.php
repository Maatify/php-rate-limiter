<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\RateLimiter;

use Maatify\RateLimiter\Repository\BudgetSeedStoreInterface;
use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use Maatify\SharedCommon\Contracts\ClockInterface;

class InMemoryRateLimitStore implements BudgetSeedStoreInterface
{
    /** @var array<string, array{value: int, updatedAt: int, expiresAt: int}> */
    private array $data = [];

    /** @var array<string, array{level: int, expiresAt: int}> */
    private array $blocks = [];

    /** @var array<string, array{count: int, epochStart: int, epochDuration: int}> */
    private array $budgets = [];

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function increment(string $key, int $ttlSeconds, int $amount = 1): int
    {
        $now = $this->clock->now()->getTimestamp();

        if (!isset($this->data[$key]) || $this->data[$key]['expiresAt'] < $now) {
            $this->data[$key] = [
                'value' => $amount,
                'updatedAt' => $now,
                'expiresAt' => $now + $ttlSeconds
            ];
            return $amount;
        }

        $this->data[$key]['value'] += $amount;
        $this->data[$key]['updatedAt'] = $now;
        return $this->data[$key]['value'];
    }

    public function get(string $key): ?RateLimitStateDTO
    {
        $now = $this->clock->now()->getTimestamp();
        if (!isset($this->data[$key]) || $this->data[$key]['expiresAt'] < $now) {
            return null;
        }

        return new RateLimitStateDTO(
            $this->data[$key]['value'],
            $this->data[$key]['updatedAt']
        );
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->data[$key] = [
            'value' => $value,
            'updatedAt' => $now,
            'expiresAt' => $now + $ttlSeconds
        ];
    }

    public function block(string $key, int $level, int $durationSeconds): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->blocks[$key] = [
            'level' => $level,
            'expiresAt' => $now + $durationSeconds
        ];
    }

    public function checkBlock(string $key): ?BlockStateDTO
    {
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
        $now = $this->clock->now()->getTimestamp();
        if (!isset($this->budgets[$key])) {
            return null;
        }

        $epochStart = $this->budgets[$key]['epochStart'];
        $epochDuration = $this->budgets[$key]['epochDuration'];

        if ($now >= $epochStart + $epochDuration) {
            return null;
        }

        return new BudgetStateDTO(
            $this->budgets[$key]['count'],
            $this->budgets[$key]['epochStart']
        );
    }

    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): BudgetStateDTO
    {
        $now = $this->clock->now()->getTimestamp();

        if (!isset($this->budgets[$key]) || $now >= $this->budgets[$key]['epochStart'] + $this->budgets[$key]['epochDuration']) {
            $this->budgets[$key] = [
                'count' => $amount,
                'epochStart' => $now,
                'epochDuration' => $epochDurationSeconds
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
        $now = $this->clock->now()->getTimestamp();
        $current = $this->budgets[$key] ?? null;

        if ($current !== null && $now < $current['epochStart'] + $current['epochDuration']) {
            $current['count'] += $amount;
        } elseif ($now < $seed->epochStart + $epochDurationSeconds) {
            $current = [
                'count' => $seed->count + $amount,
                'epochStart' => $seed->epochStart,
                'epochDuration' => $epochDurationSeconds,
            ];
        } else {
            $current = [
                'count' => $amount,
                'epochStart' => $now,
                'epochDuration' => $epochDurationSeconds,
            ];
        }

        $this->budgets[$key] = $current;

        return new BudgetStateDTO(
            $current['count'],
            $current['epochStart']
        );
    }

    public function isHealthy(): bool
    {
        return true;
    }
}
