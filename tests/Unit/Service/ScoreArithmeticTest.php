<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Service;

use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\ScoreArithmetic;
use Maatify\RateLimiter\Tests\Support\Redis\NativeDomain;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** F-185-11 (#185): explicit overflow checks, no `(int)` cast, and non-negative score deltas. */
#[Group('f-185-11')]
#[Group('php32')]
final class ScoreArithmeticTest extends TestCase
{
    /** @return iterable<string, array{string, int|string, int, ?int}> operation, a (text when it needs a 64-bit int), b, exact result or null for overflow */
    public static function operations(): iterable
    {
        yield 'product at the bound' => ['product', 1, PHP_INT_MAX, PHP_INT_MAX];
        yield 'product of intdiv' => ['product', 2, intdiv(PHP_INT_MAX, 2), intdiv(PHP_INT_MAX, 2) * 2];
        yield 'product past the bound' => ['product', 2, intdiv(PHP_INT_MAX, 2) + 1, null];
        // Past a 64-bit int only: 2^33 is not an int on a 32-bit build (the operand is carried as text and skipped there, per case, in the test).
        yield 'product 2^33 x (2^31 - 1)' => ['product', '8589934592', 2_147_483_647, null];
        // Past a 32-bit int and exact on 64-bit: 2^16 x (2^31 - 1) = 140737488289792.
        yield 'product 2^16 x (2^31 - 1)' => ['product', 65_536, 2_147_483_647, PHP_INT_SIZE >= 8 ? 140_737_488_289_792 : null];
        yield 'product with zero' => ['product', 0, PHP_INT_MAX, 0];
        yield 'sum at the bound' => ['sum', PHP_INT_MAX - 1, 1, PHP_INT_MAX];
        yield 'sum past the bound' => ['sum', PHP_INT_MAX, 1, null];
        yield 'sum below PHP_INT_MIN' => ['sum', PHP_INT_MIN, -1, null];
        yield 'sum of opposite signs' => ['sum', PHP_INT_MAX, PHP_INT_MIN, -1];
        yield 'difference at the bound' => ['difference', PHP_INT_MIN + 1, 1, PHP_INT_MIN];
        yield 'difference below PHP_INT_MIN' => ['difference', PHP_INT_MIN, 1, null];
        yield 'difference past PHP_INT_MAX' => ['difference', PHP_INT_MAX, -1, null];
        yield 'difference of a full decay' => ['difference', 2, 5, -3];
    }

    #[DataProvider('operations')]
    public function testTheResultIsExactOrRejected(string $operation, int|string $a, int $b, ?int $expected): void
    {
        if (is_string($a)) {
            $a = NativeDomain::int($a, 'the "product 2^16 x (2^31 - 1)" data set (a product past the caller\'s own maximum).');
        }

        if ($expected === null) {
            $this->expectException(RateLimiterException::class);
            $this->expectExceptionMessage('not representable as a PHP integer');
        }

        self::assertSame($expected, ScoreArithmetic::{$operation}($a, $b, 'test'));
    }

    public function testAProductOfANegativeOperandIsRejected(): void
    {
        $this->expectException(RateLimiterException::class);

        ScoreArithmetic::product(-1, 1, 'test');
    }

    /** @return iterable<string, array{string}> */
    public static function deltaFields(): iterable
    {
        foreach (['access', 'k1_spray', 'k2_missing_fp', 'k4_failure', 'k4_repeated_missing_fp', 'k5_failure'] as $field) {
            yield $field => [$field];
        }
    }

    #[DataProvider('deltaFields')]
    public function testANegativeScoreDeltaIsRejected(string $field): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Score delta ' . $field . ' must be a non-negative integer.');

        new ScoreDeltasDTO(...[$field => -1]);
    }

    public function testControlZeroAndPositiveDeltasAreAccepted(): void
    {
        $deltas = new ScoreDeltasDTO(access: 0, k1_spray: 5, k5_failure: PHP_INT_MAX);

        self::assertSame(PHP_INT_MAX, $deltas->k5_failure);
    }
}
