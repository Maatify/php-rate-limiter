<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support;

use Maatify\RateLimiter\Service\E1AccountScopeKeyDeriver;

/** Test data only: the old synthetic scope helpers join a colon-free material after the account. Never used to read persisted keys. */
final class FixtureKeys
{
    public static function scope(string $type, string $policy, string $environment, string $subject, string $secret): string
    {
        if (in_array($type, ['k4', 'k4p'], true)) {
            return (new E1AccountScopeKeyDeriver($environment))->derive($type, $policy, $subject, $secret);
        }
        if (in_array($type, ['k5', 'k5p'], true)) {
            $delimiter = strrpos($subject, ':');
            if ($delimiter === false) {
                throw new \LogicException('The synthetic device fixture must provide account:material.');
            }
            return (new E1AccountScopeKeyDeriver($environment))->derive($type, $policy, substr($subject, 0, $delimiter), $secret, substr($subject, $delimiter + 1));
        }
        return hash_hmac('sha256', "{$policy}:rate_limiter:{$type}:v2:{$environment}:{$subject}", $secret);
    }
}
