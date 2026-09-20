<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

final readonly class BudgetConfigDTO implements \JsonSerializable
{
    public function __construct(
        public int $threshold,
        public int $block_level,
        public int $cooldown_seconds = 0,
        public int $trusted_session_floor_level = 2,
        public bool $precheck_enforcement = true,
        public ?int $known_device_micro_cap = 8,
        public bool $recovery_collision_guard_enabled = false,
    ) {}

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
