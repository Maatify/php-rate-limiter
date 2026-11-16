<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Redis;

use PHPUnit\Framework\TestCase;

/**
 * Per-case applicability of a fixture value to the calling PHP's native `int` (#240).
 *
 * A literal above 2^31 - 1 is a float on a 32-bit build, and a float cannot enter an `int` parameter; casting or clamping it would test a different
 * input. A fixture that needs such a value keeps it as an exact decimal string and goes through `int()`: on a build whose `int` holds it, it is the
 * integer; on a narrower build the case is skipped, with the input domain it is outside of and the in-domain equivalent that stays covered named in
 * the reason. Never a blanket skip of a class, and never a silent narrowing.
 */
final class NativeDomain
{
    /**
     * @param string $decimal canonical non-negative decimal
     * @param string $equivalent the in-domain case or suite that covers the same contract on a build where `$decimal` does not fit
     */
    public static function int(string $decimal, string $equivalent): int
    {
        if (ExactDecimal::compare($decimal, (string) PHP_INT_MAX) > 0) {
            TestCase::markTestSkipped('Outside the 32-bit public input domain: ' . $decimal . ' is above PHP_INT_MAX (' . PHP_INT_MAX . '). Covered on this width by: ' . $equivalent);
        }

        return (int) $decimal;
    }
}
