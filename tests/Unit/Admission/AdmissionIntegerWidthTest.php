<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\DTO\AdmissionBudgetsDTO;
use Maatify\RateLimiter\DTO\AdmissionLaneDTO;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Enum\AdmissionLaneEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisAdmissionOperations;
use Maatify\RateLimiter\Service\AdmissionCredentialCodec;
use Maatify\RateLimiter\Service\AdmissionRuntime;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionScenarioBuilder as S;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionStubStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Admission arithmetic that must give the same answer on 32-bit and 64-bit PHP (#213): the exact bucket product bound, a Redis
 * integer reply that a native `int` cannot hold, and a permit deadline that overflows a native `int`. Every expectation here is
 * a typed input-contract failure or an exact value, never a `TypeError` and never a silently saturated number.
 */
#[Group('php32')]
final class AdmissionIntegerWidthTest extends TestCase
{
    private const EXACT_MAX_DECIMAL = '9007199254740991';

    /** The 2^53 - 1 boundary reached with two native `int` operands that both fit 32-bit PHP: (2^22 - 1) * (2^31 - 1) is the largest product at or below it. */
    private const FIT_CAPACITY = 4194303;

    private const OVER_CAPACITY = 4194305;

    private const TAKE_COST = 2147483647;

    /** @var list<list<int|string|float>> */
    private array $sent = [];

    private function operations(mixed $reply = [1, 0, 0]): RedisAdmissionOperations
    {
        $this->sent = [];

        return new RedisAdmissionOperations(
            new CallableRedisCommandExecutor(function (array $command) use ($reply): mixed {
                $this->sent[] = $command;

                return $reply;
            }),
            'width',
        );
    }

    public function testABucketWhoseProductIsExactlyRepresentableIsSentToRedis(): void
    {
        $result = $this->operations()->reserve(S::request(S::bucket('account_bucket', 'acct', self::FIT_CAPACITY, self::TAKE_COST)), S::NOW);

        self::assertTrue($result->admitted);
        self::assertCount(1, $this->sent, 'The accepted bucket reaches Redis.');
        self::assertLessThanOrEqual((float) self::EXACT_MAX_DECIMAL, (float) self::FIT_CAPACITY * (float) self::TAKE_COST);
    }

    public function testABucketWhoseProductExceedsTheExactRangeIsRejectedBeforeAnyCommand(): void
    {
        $operations = $this->operations();
        try {
            $operations->reserve(S::request(S::bucket('account_bucket', 'acct', self::OVER_CAPACITY, self::TAKE_COST)), S::NOW);
            self::fail('The over-range bucket must be rejected.');
        } catch (RateLimiterException $exception) {
            self::assertSame('Admission bucket capacity times its take cost is not representable.', $exception->getMessage());
        }
        self::assertSame([], $this->sent, 'Nothing is sent to Redis.');
    }

    public function testNoNativeIntegerPairEverRaisesATypeErrorAtTheBucketBound(): void
    {
        foreach ([[PHP_INT_MAX, PHP_INT_MAX], [PHP_INT_MAX, 1], [1, PHP_INT_MAX], [2147483647, 2147483647], [1, 1]] as [$capacity, $take]) {
            try {
                $this->operations()->reserve(S::request(S::bucket('account_bucket', 'acct', $capacity, $take)), S::NOW);
            } catch (RateLimiterException) {
                // A typed input rejection is the only acceptable refusal.
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testARedisIntegerReplyThatANativeIntCannotHoldIsNeverSaturated(): void
    {
        // A refusal of the first scope whose retry-after is 2^53 - 1: representable only where `int` is 64-bit.
        $operations = $this->operations([0, 1, self::EXACT_MAX_DECIMAL]);
        $request = S::request(S::counter('source_capacity', 'src', 1, 1200, null, 'member-1'));

        if (PHP_INT_SIZE >= 8) {
            $result = $operations->reserve($request, S::NOW);
            self::assertFalse($result->admitted);
            self::assertSame((int) self::EXACT_MAX_DECIMAL, $result->retryAfterSeconds);

            return;
        }
        try {
            $operations->reserve($request, S::NOW);
            self::fail('A reply beyond the native integer must be rejected on 32-bit PHP.');
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('not representable as a native integer', $exception->getMessage());
        }
    }

    public function testAnOrdinaryRetryAfterIsUnchanged(): void
    {
        $result = $this->operations([0, 1, 1800])->reserve(S::request(S::counter('source_capacity', 'src', 1, 1200, null, 'member-1')), S::NOW);

        self::assertFalse($result->admitted);
        self::assertSame('source_capacity', $result->refusedScope);
        self::assertSame(1800, $result->retryAfterSeconds);
    }

    public function testAPermitDeadlineThatOverflowsANativeIntegerIsATypedRejection(): void
    {
        // A deadline equal to a native integer maximum: `now + D` cannot be a native integer on either width.
        $budgets = new AdmissionBudgetsDTO(PHP_INT_MAX, 24, 4, 4, 1800, 10, 60, 6, 30, 10, 4, PHP_INT_MAX, 600);
        $runtime = new AdmissionRuntime(new AdmissionStubStore(new FixedClock()), new AdmissionCredentialCodec('binding-secret'), $budgets, new FixedClock('@1767269700'), 'key-secret', 'prod');
        $context = new RateLimitContextDTO('198.51.100.7', 'ua', 'acct');
        $allowed = new RateLimitResultDTO(RateLimitResultDTO::DECISION_ALLOW, 0, 0, 'NORMAL');

        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Admission permit deadline is not representable as a native integer.');
        $runtime->reserve('login_protection', 'login', $context, new DeviceIdentityDTO(null, 'LOW', false), new AdmissionLaneDTO(AdmissionLaneEnum::U), $allowed);
    }

    public function testThePendingPermitLimitRangeIsTheSameDecimalContractOnBothWidths(): void
    {
        self::assertTrue(AdmissionBudgetsDTO::isSupportedPendingPermitLimit(AdmissionBudgetsDTO::nativePendingPermitLimitMaximum()));
        self::assertSame(PHP_INT_SIZE >= 8 ? self::EXACT_MAX_DECIMAL : (string) PHP_INT_MAX, (string) AdmissionBudgetsDTO::nativePendingPermitLimitMaximum());
        self::assertTrue(AdmissionBudgetsDTO::isWithinPendingPermitLimitMaximum(self::EXACT_MAX_DECIMAL));
        self::assertFalse(AdmissionBudgetsDTO::isWithinPendingPermitLimitMaximum('9007199254740992'));
    }
}
