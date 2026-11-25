<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support;

use Maatify\RateLimiter\Service\E1AccountScopeKeyDeriver;

/** Test data only: synthetic scope helpers for current E1 (DEC-046) and non-account f1 (DEC-047) keys. */
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
        if ($type === 'k1') {
            return NonAccountKeyOracle::k1($policy, $environment, $subject, $secret);
        }
        if ($type === 'k2' || $type === 'k3') {
            $delimiter = strrpos($subject, ':');
            if ($delimiter === false) {
                throw new \LogicException("The synthetic {$type} fixture must provide ipPrefix:tail.");
            }
            $ipPrefix = substr($subject, 0, $delimiter);
            $tail = substr($subject, $delimiter + 1);

            return $type === 'k2'
                ? NonAccountKeyOracle::k2($policy, $environment, $ipPrefix, $tail, $secret)
                : NonAccountKeyOracle::k3($policy, $environment, $ipPrefix, $tail, $secret);
        }

        throw new \LogicException("Unsupported fixture scope type: {$type}.");
    }
}
