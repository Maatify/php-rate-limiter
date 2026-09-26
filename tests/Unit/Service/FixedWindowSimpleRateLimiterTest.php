<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Service;

use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\DTO\SimpleRateLimitResultDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\FixedWindowSimpleRateLimiter;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

final class FixedWindowSimpleRateLimiterTest extends TestCase
{
    private FixedClock $clock;
    private InMemoryRateLimitStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->store = new InMemoryRateLimitStore($this->clock);
    }

    public function testUnknownPolicyIsRejected(): void
    {
        $limiter = $this->limiter([]);

        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Unknown simple throttle policy "missing"');
        $limiter->consume('missing', 'subject-1');
    }

    public function testBlankSubjectIsRejected(): void
    {
        $limiter = $this->limiter([new FixedWindowThrottlePolicy('checkout', 3, 60)]);

        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('subject must not be empty');
        $limiter->consume('checkout', "  \t  ");
    }

    public function testFirstConsumeStartsTheFixedWindow(): void
    {
        $limiter = $this->limiter([new FixedWindowThrottlePolicy('checkout', 3, 60)]);

        $result = $limiter->consume('checkout', 'subject-1');

        self::assertTrue($result->allowed);
        self::assertSame(3, $result->limit);
        self::assertSame(2, $result->remaining);
        self::assertSame(0, $result->retryAfter);
        self::assertSame($this->clock->now()->getTimestamp() + 60, $result->resetAt);
        self::assertSame(SimpleRateLimitResultDTO::NORMAL, $result->failureMode);
    }

    public function testConsumesOneThroughLimitAreAllowedAndLimitPlusOneIsDenied(): void
    {
        $limiter = $this->limiter([new FixedWindowThrottlePolicy('checkout', 3, 60)]);

        $first = $limiter->consume('checkout', 'subject-1');
        $second = $limiter->consume('checkout', 'subject-1');
        $third = $limiter->consume('checkout', 'subject-1');
        $fourth = $limiter->consume('checkout', 'subject-1');

        self::assertTrue($first->allowed);
        self::assertSame(2, $first->remaining);
        self::assertTrue($second->allowed);
        self::assertSame(1, $second->remaining);
        self::assertTrue($third->allowed);
        self::assertSame(0, $third->remaining);

        self::assertFalse($fourth->allowed);
        self::assertSame(0, $fourth->remaining);
        self::assertSame(60, $fourth->retryAfter);
        self::assertSame(SimpleRateLimitResultDTO::NORMAL, $fourth->failureMode);
    }

    public function testLaterDeniedCallsDoNotExtendResetAt(): void
    {
        $limiter = $this->limiter([new FixedWindowThrottlePolicy('checkout', 1, 60)]);

        $first = $limiter->consume('checkout', 'subject-1');
        $this->clock->setNow($this->clock->now()->modify('+10 seconds'));
        $second = $limiter->consume('checkout', 'subject-1');
        $this->clock->setNow($this->clock->now()->modify('+10 seconds'));
        $third = $limiter->consume('checkout', 'subject-1');

        self::assertTrue($first->allowed);
        self::assertFalse($second->allowed);
        self::assertFalse($third->allowed);
        self::assertSame($first->resetAt, $second->resetAt);
        self::assertSame($first->resetAt, $third->resetAt);
        self::assertSame(50, $second->retryAfter);
        self::assertSame(40, $third->retryAfter);
    }

    public function testRemainingClampsAtZeroAndNeverGoesNegative(): void
    {
        $limiter = $this->limiter([new FixedWindowThrottlePolicy('checkout', 2, 60)]);

        for ($i = 0; $i < 5; $i++) {
            $result = $limiter->consume('checkout', 'subject-1');
            self::assertGreaterThanOrEqual(0, $result->remaining);
            if ($i >= 2) {
                self::assertSame(0, $result->remaining);
            }
        }
    }

    public function testExactBoundaryRolloverStartsNewEpochAfterExpiry(): void
    {
        $limiter = $this->limiter([new FixedWindowThrottlePolicy('checkout', 1, 60)]);

        $first = $limiter->consume('checkout', 'subject-1');
        self::assertTrue($first->allowed);

        $this->clock->setNow($this->clock->now()->modify('+59 seconds'));
        $stillDenied = $limiter->consume('checkout', 'subject-1');
        self::assertFalse($stillDenied->allowed);
        self::assertSame($first->resetAt, $stillDenied->resetAt);

        $this->clock->setNow($this->clock->now()->modify('+2 seconds'));
        $newEpoch = $limiter->consume('checkout', 'subject-1');
        self::assertTrue($newEpoch->allowed);
        self::assertSame(0, $newEpoch->remaining);
        self::assertGreaterThan($first->resetAt, $newEpoch->resetAt);
        self::assertSame($this->clock->now()->getTimestamp() + 60, $newEpoch->resetAt);
    }

    public function testDifferentSubjectsAreIsolated(): void
    {
        $limiter = $this->limiter([new FixedWindowThrottlePolicy('checkout', 1, 60)]);

        $subjectOne = $limiter->consume('checkout', 'subject-1');
        $subjectTwo = $limiter->consume('checkout', 'subject-2');

        self::assertTrue($subjectOne->allowed);
        self::assertTrue($subjectTwo->allowed);
        self::assertSame(0, $subjectOne->remaining);
        self::assertSame(0, $subjectTwo->remaining);
    }

    public function testDifferentPolicyNamesAreIsolatedForTheSameSubject(): void
    {
        $limiter = $this->limiter([
            new FixedWindowThrottlePolicy('checkout', 1, 60),
            new FixedWindowThrottlePolicy('search', 1, 60),
        ]);

        $checkout = $limiter->consume('checkout', 'subject-1');
        $search = $limiter->consume('search', 'subject-1');

        self::assertTrue($checkout->allowed);
        self::assertTrue($search->allowed);
    }

    public function testDifferentEnvironmentScopesAreIsolated(): void
    {
        $policy = new FixedWindowThrottlePolicy('checkout', 1, 60);
        $prodLimiter = $this->limiter([$policy], environmentScope: 'prod');
        $stagingLimiter = $this->limiter([$policy], environmentScope: 'staging');

        $prodFirst = $prodLimiter->consume('checkout', 'subject-1');
        $stagingFirst = $stagingLimiter->consume('checkout', 'subject-1');

        self::assertTrue($prodFirst->allowed);
        self::assertTrue($stagingFirst->allowed);
        self::assertSame(0, $prodFirst->remaining);
        self::assertSame(0, $stagingFirst->remaining);
    }

    public function testDifferentLimitsOrIntervalsUseDistinctSemanticNamespaces(): void
    {
        $narrowLimiter = $this->limiter([new FixedWindowThrottlePolicy('checkout', 1, 60)]);
        $narrowLimiter->consume('checkout', 'subject-1');

        $widerLimiter = $this->limiter([new FixedWindowThrottlePolicy('checkout', 5, 120)]);
        $result = $widerLimiter->consume('checkout', 'subject-1');

        self::assertTrue($result->allowed);
        self::assertSame(4, $result->remaining, 'A reconfigured limit/interval must not reinterpret the previous epoch.');
    }

    public function testRawSubjectNeverCrossesTheStoreBoundary(): void
    {
        $limiter = $this->limiter([new FixedWindowThrottlePolicy('checkout', 3, 60)]);
        $limiter->consume('checkout', 'super-secret-raw-subject-marker');

        $budgets = (new \ReflectionProperty($this->store, 'budgets'))->getValue($this->store);
        self::assertIsArray($budgets);
        self::assertNotEmpty($budgets);
        foreach (array_keys($budgets) as $key) {
            self::assertIsString($key);
            self::assertStringNotContainsString('super-secret-raw-subject-marker', $key);
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $key);
        }
    }

    /**
     * @param list<FixedWindowThrottlePolicy> $policies
     */
    private function limiter(
        array $policies,
        string $keySecret = 'active-secret',
        string $environmentScope = 'prod',
        ?string $previousKeySecret = null,
    ): FixedWindowSimpleRateLimiter {
        return new FixedWindowSimpleRateLimiter(
            $policies,
            $this->store,
            $this->clock,
            $keySecret,
            $environmentScope,
            $previousKeySecret,
        );
    }
}
