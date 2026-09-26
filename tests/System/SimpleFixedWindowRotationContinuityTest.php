<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\RateLimiter\Service\FixedWindowSimpleRateLimiter;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\RateLimiter\BaseOnlyInMemoryRateLimitStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

final class SimpleFixedWindowRotationContinuityTest extends TestCase
{
    private FixedClock $clock;
    private InMemoryRateLimitStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->store = new InMemoryRateLimitStore($this->clock);
    }

    public function testWithoutAPreviousSecretOnlyCurrentIsUsed(): void
    {
        $limiter = $this->limiter($this->store, 'current-secret', null);

        $result = $limiter->consume('checkout', 'subject-1');

        self::assertTrue($result->allowed);
        $currentKey = $this->key('checkout', 3, 60, 'subject-1', 'current-secret');
        self::assertNotNull($this->store->getBudget($currentKey));
    }

    public function testWhenCurrentExistsPreviousIsIgnored(): void
    {
        $currentKey = $this->key('checkout', 3, 60, 'subject-1', 'current-secret');
        $previousKey = $this->key('checkout', 3, 60, 'subject-1', 'previous-secret');
        $this->store->incrementBudget($currentKey, 60, 1);
        $this->store->incrementBudget($previousKey, 60, 20);

        $limiter = $this->limiter($this->store, 'current-secret', 'previous-secret');
        $result = $limiter->consume('checkout', 'subject-1');

        self::assertTrue($result->allowed);
        self::assertSame(2, $this->store->getBudget($currentKey)?->count);
        self::assertSame(20, $this->store->getBudget($previousKey)?->count, 'Previous must remain read-only once Current is authoritative.');
    }

    public function testCurrentAbsentWithValidPreviousSeedsAtomicallyAndPreservesFixedEnd(): void
    {
        $previousKey = $this->key('checkout', 3, 60, 'subject-1', 'previous-secret');
        $previousBudget = $this->store->incrementBudget($previousKey, 60, 2);

        $limiter = $this->limiter($this->store, 'current-secret', 'previous-secret');
        $result = $limiter->consume('checkout', 'subject-1');

        self::assertTrue($result->allowed);
        self::assertSame(0, $result->remaining);
        self::assertSame($previousBudget->epochStart + 60, $result->resetAt);

        $currentKey = $this->key('checkout', 3, 60, 'subject-1', 'current-secret');
        $currentBudget = $this->store->getBudget($currentKey);
        self::assertNotNull($currentBudget);
        self::assertSame(3, $currentBudget->count);
        self::assertSame($previousBudget->epochStart, $currentBudget->epochStart);

        $remainingPrevious = $this->store->getBudget($previousKey);
        self::assertNotNull($remainingPrevious);
        self::assertSame(2, $remainingPrevious->count, 'Previous must not be written to during migration.');
        self::assertSame($previousBudget->epochStart, $remainingPrevious->epochStart);
    }

    public function testExpiredPreviousStartsANormalCurrentEpoch(): void
    {
        $previousKey = $this->key('checkout', 3, 60, 'subject-1', 'previous-secret');
        $this->store->incrementBudget($previousKey, 60, 2);
        $this->clock->setNow($this->clock->now()->modify('+61 seconds'));

        $limiter = $this->limiter($this->store, 'current-secret', 'previous-secret');
        $result = $limiter->consume('checkout', 'subject-1');

        self::assertTrue($result->allowed);
        self::assertSame(2, $result->remaining, 'A fresh Current epoch must not inherit the expired Previous count.');
        self::assertSame($this->clock->now()->getTimestamp() + 60, $result->resetAt);
    }

    public function testMissingBudgetSeedCapabilityFailsExplicitlyWhenMigrationRequired(): void
    {
        $baseOnlyStore = new BaseOnlyInMemoryRateLimitStore($this->clock);
        $previousKey = $this->key('checkout', 3, 60, 'subject-1', 'previous-secret');
        $baseOnlyStore->incrementBudget($previousKey, 60, 2);

        $limiter = $this->limiter($baseOnlyStore, 'current-secret', 'previous-secret');

        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('BudgetSeedStoreInterface');
        $limiter->consume('checkout', 'subject-1');
    }

    public function testBaseOnlyStoreWithoutValidPreviousContinuesNormallyWithoutTheCapability(): void
    {
        $baseOnlyStore = new BaseOnlyInMemoryRateLimitStore($this->clock);
        $limiter = $this->limiter($baseOnlyStore, 'current-secret', 'previous-secret');

        $result = $limiter->consume('checkout', 'subject-1');

        self::assertTrue($result->allowed);
        self::assertSame(2, $result->remaining);
    }

    public function testRotationNeverMaxesOrSumsCurrentAndPrevious(): void
    {
        $currentKey = $this->key('checkout', 3, 60, 'subject-1', 'current-secret');
        $previousKey = $this->key('checkout', 3, 60, 'subject-1', 'previous-secret');
        $this->store->incrementBudget($currentKey, 60, 1);
        $this->store->incrementBudget($previousKey, 60, 3);

        $limiter = $this->limiter($this->store, 'current-secret', 'previous-secret');
        $result = $limiter->consume('checkout', 'subject-1');

        self::assertSame(1, $result->remaining, 'Result must reflect Current(1)+1=2, never max(1,3) or 1+3.');
    }

    public function testSequentialCurrentInitializationNeverDuplicatesSeedOrLosesIncrements(): void
    {
        $previousKey = $this->key('checkout', 3, 60, 'subject-1', 'previous-secret');
        $previousBudget = $this->store->incrementBudget($previousKey, 60, 1);

        $limiter = $this->limiter($this->store, 'current-secret', 'previous-secret');
        $first = $limiter->consume('checkout', 'subject-1');
        $second = $limiter->consume('checkout', 'subject-1');

        self::assertTrue($first->allowed);
        self::assertSame(1, $first->remaining);
        self::assertTrue($second->allowed);
        self::assertSame(0, $second->remaining);

        $currentKey = $this->key('checkout', 3, 60, 'subject-1', 'current-secret');
        $currentBudget = $this->store->getBudget($currentKey);
        self::assertNotNull($currentBudget);
        self::assertSame(3, $currentBudget->count);
        self::assertSame($previousBudget->epochStart, $currentBudget->epochStart);
    }

    private function limiter(
        RateLimitStoreInterface $store,
        string $keySecret,
        ?string $previousKeySecret,
    ): FixedWindowSimpleRateLimiter {
        return new FixedWindowSimpleRateLimiter(
            [new FixedWindowThrottlePolicy('checkout', 3, 60)],
            $store,
            $this->clock,
            $keySecret,
            'prod',
            $previousKeySecret,
        );
    }

    private function key(string $policyName, int $limit, int $intervalSeconds, string $subject, string $secret): string
    {
        $encode = static fn(string $component): string => pack('N', strlen($component)) . $component;

        $preimage = $encode('rate_limiter')
            . $encode('simple_fixed_window')
            . $encode('v1')
            . $encode('prod')
            . $encode($policyName)
            . $encode((string) $limit)
            . $encode((string) $intervalSeconds)
            . $encode($subject);

        return hash_hmac('sha256', $preimage, $secret);
    }
}
