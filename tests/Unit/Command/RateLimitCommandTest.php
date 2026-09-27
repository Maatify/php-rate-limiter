<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Command;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Exception\RateLimiterException;
use PHPUnit\Framework\TestCase;

class RateLimitCommandTest extends TestCase
{
    public function testCommandIsFinal(): void
    {
        $reflection = new \ReflectionClass(RateLimitCommand::class);
        $this->assertTrue($reflection->isFinal(), 'RateLimitCommand should be final.');
    }

    public function testCommandIsReadOnly(): void
    {
        $reflection = new \ReflectionClass(RateLimitCommand::class);
        $this->assertTrue($reflection->isReadOnly(), 'RateLimitCommand should be readonly.');
    }

    public function testNormalAccessDirectConstructionRemainsValid(): void
    {
        $command = new RateLimitCommand('test_policy');

        $this->assertSame('test_policy', $command->policyName);
        $this->assertSame(1, $command->cost);
        $this->assertFalse($command->isPreCheck);
        $this->assertFalse($command->isFailure);
        $this->assertFalse($command->isSuccess);
    }

    public function testPolicyNameIsPreserved(): void
    {
        $command = new RateLimitCommand('custom_policy');
        $this->assertSame('custom_policy', $command->policyName);
    }

    public function testCustomCostIsPreserved(): void
    {
        $command = new RateLimitCommand('test_policy', 5);
        $this->assertSame(5, $command->cost);
    }

    public function testCheckOnlyProducesCorrectState(): void
    {
        $command = RateLimitCommand::checkOnly('test_policy', 2);

        $this->assertSame('test_policy', $command->policyName);
        $this->assertSame(2, $command->cost);
        $this->assertTrue($command->isPreCheck);
        $this->assertFalse($command->isFailure);
        $this->assertFalse($command->isSuccess);
    }

    public function testRecordFailureProducesCorrectState(): void
    {
        $command = RateLimitCommand::recordFailure('test_policy', 3);

        $this->assertSame('test_policy', $command->policyName);
        $this->assertSame(3, $command->cost);
        $this->assertFalse($command->isPreCheck);
        $this->assertTrue($command->isFailure);
        $this->assertFalse($command->isSuccess);
    }

    public function testRecordSuccessProducesCorrectState(): void
    {
        $command = RateLimitCommand::recordSuccess('test_policy', 4);

        $this->assertSame('test_policy', $command->policyName);
        $this->assertSame(4, $command->cost);
        $this->assertFalse($command->isPreCheck);
        $this->assertFalse($command->isFailure);
        $this->assertTrue($command->isSuccess);
    }

    public function testContradictoryPreCheckAndFailureRejected(): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Invalid command state: contradictory execution intent modes.');

        new RateLimitCommand('test_policy', 1, true, true, false);
    }

    public function testContradictoryPreCheckAndSuccessRejected(): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Invalid command state: contradictory execution intent modes.');

        new RateLimitCommand('test_policy', 1, true, false, true);
    }

    public function testContradictoryFailureAndSuccessRejected(): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Invalid command state: contradictory execution intent modes.');

        new RateLimitCommand('test_policy', 1, false, true, true);
    }

    public function testAllThreeTrueRejected(): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Invalid command state: contradictory execution intent modes.');

        new RateLimitCommand('test_policy', 1, true, true, true);
    }
}
