<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Defines account-budget enforcement and cooldown behavior for a policy.
 */
final readonly class BudgetConfigDTO implements \JsonSerializable
{
    /**
     * @param int $threshold Count at which the account budget becomes active.
     * @param int $block_level Block level applied while the budget is enforced.
     * @param int $cooldown_seconds Cooldown duration for an issued budget block.
     * @param int $trusted_session_floor_level Minimum level for trusted sessions.
     * @param bool $precheck_enforcement Whether pre-check commands enforce budget state.
     * @param ?int $known_device_micro_cap Optional known-device failure cap.
     * @param bool $recovery_collision_guard_enabled Whether the recovery guard is active.
     */
    public function __construct(
        public int $threshold,
        public int $block_level,
        public int $cooldown_seconds = 0,
        public int $trusted_session_floor_level = 2,
        public bool $precheck_enforcement = true,
        public ?int $known_device_micro_cap = 8,
        public bool $recovery_collision_guard_enabled = false,
    ) {}

    /**
     * Return the configuration fields used by policy inspection and JSON output.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'threshold' => $this->threshold,
            'block_level' => $this->block_level,
            'cooldown_seconds' => $this->cooldown_seconds,
            'trusted_session_floor_level' => $this->trusted_session_floor_level,
            'precheck_enforcement' => $this->precheck_enforcement,
            'known_device_micro_cap' => $this->known_device_micro_cap,
            'recovery_collision_guard_enabled' => $this->recovery_collision_guard_enabled,
        ];
    }
}
