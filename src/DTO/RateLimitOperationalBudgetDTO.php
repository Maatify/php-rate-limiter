<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Snapshot of account budget, known-device micro-cap, and cooldown state.
 */
final readonly class RateLimitOperationalBudgetDTO implements \JsonSerializable
{
    /**
     * @param ?BudgetStateDTO $accountBudget Current or previous-generation account budget.
     * @param bool $accountBudgetFromPreviousGeneration Whether the account budget is legacy.
     * @param bool $accountBudgetActive Whether the account budget is currently active.
     * @param ?BudgetStateDTO $knownDeviceMicroCap Known-device micro-cap state, when configured.
     * @param bool $knownDeviceMicroCapFromPreviousGeneration Whether the micro-cap is legacy.
     * @param bool $knownDeviceMicroCapExceeded Whether the micro-cap has been exceeded.
     * @param ?RateLimitStateDTO $cooldown Budget cooldown marker, when present.
     * @param bool $cooldownFromPreviousGeneration Whether the cooldown is legacy.
     * @param int $cooldownRemainingSeconds Remaining cooldown duration at observation time.
     */
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

    /**
     * Return the operational budget snapshot in serialized form.
     */
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
