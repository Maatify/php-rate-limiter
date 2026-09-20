<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

final readonly class RateLimitOperationalBudgetDTO implements \JsonSerializable
{
    public function __construct(
        public ?BudgetStateDTO $accountBudget,
        public bool $accountBudgetFromPreviousGeneration,
        public bool $accountBudgetActive,
        public ?BudgetStateDTO $knownDeviceMicroCap,
        public bool $knownDeviceMicroCapFromPreviousGeneration,
        public bool $knownDeviceMicroCapExceeded,
        public ?RateLimitStateDTO $cooldown,
        public bool $cooldownFromPreviousGeneration,
        public int $cooldownRemainingSeconds,
    ) {}

    public function jsonSerialize(): mixed
    {
        return [
            'accountBudget' => $this->accountBudget,
            'accountBudgetFromPreviousGeneration' => $this->accountBudgetFromPreviousGeneration,
            'accountBudgetActive' => $this->accountBudgetActive,
            'knownDeviceMicroCap' => $this->knownDeviceMicroCap,
            'knownDeviceMicroCapFromPreviousGeneration' => $this->knownDeviceMicroCapFromPreviousGeneration,
            'knownDeviceMicroCapExceeded' => $this->knownDeviceMicroCapExceeded,
            'cooldown' => $this->cooldown,
            'cooldownFromPreviousGeneration' => $this->cooldownFromPreviousGeneration,
            'cooldownRemainingSeconds' => $this->cooldownRemainingSeconds,
        ];
    }
}
