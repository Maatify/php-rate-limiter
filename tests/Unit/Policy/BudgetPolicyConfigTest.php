<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Policy;

use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\Policy\LoginProtectionPolicy;
use Maatify\RateLimiter\Policy\OtpProtectionPolicy;
use PHPUnit\Framework\TestCase;

final class BudgetPolicyConfigTest extends TestCase
{
    public function testLoginBudgetConfigPresetIsLockedExactly(): void
    {
        $config = (new LoginProtectionPolicy())->getBudgetConfig();
        $this->assertInstanceOf(BudgetConfigDTO::class, $config);

        $this->assertSame(20, $config->threshold);
        $this->assertSame(3, $config->block_level);
        $this->assertSame(3600, $config->cooldown_seconds);
        $this->assertSame(2, $config->trusted_session_floor_level);
        $this->assertTrue($config->precheck_enforcement);
        $this->assertSame(8, $config->known_device_micro_cap);
        $this->assertFalse($config->recovery_collision_guard_enabled);
    }

    public function testOtpBudgetConfigPresetIsLockedExactly(): void
    {
        $config = (new OtpProtectionPolicy())->getBudgetConfig();
        $this->assertInstanceOf(BudgetConfigDTO::class, $config);

        $this->assertSame(10, $config->threshold);
        $this->assertSame(4, $config->block_level);
        $this->assertSame(7200, $config->cooldown_seconds);
        $this->assertSame(3, $config->trusted_session_floor_level);
        $this->assertFalse($config->precheck_enforcement);
        $this->assertNull($config->known_device_micro_cap);
        $this->assertTrue($config->recovery_collision_guard_enabled);
    }
}