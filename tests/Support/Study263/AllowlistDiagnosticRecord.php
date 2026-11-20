<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Study263;

use Maatify\RateLimiter\DTO\RateLimitResultDTO;

/**
 * #263 study: the EXAMPLE of an explicit allowlist for operational diagnostics (a Host-side class, not part of the package).
 * It builds the log record from named fields of the result only; it never serializes the DTO and never receives the request context,
 * so no header, fingerprint, admission input, permit or nested metadata can reach the line. The policy name is Host knowledge.
 * Enumerated values are checked against the known constants so a surprising string cannot be written through them.
 */
final class AllowlistDiagnosticRecord
{
    private const DECISIONS = [
        RateLimitResultDTO::DECISION_ALLOW,
        RateLimitResultDTO::DECISION_SOFT_BLOCK,
        RateLimitResultDTO::DECISION_HARD_BLOCK,
    ];

    private const FAILURE_MODES = ['NORMAL', 'DEGRADED_MODE', 'FAIL_OPEN', 'FAIL_CLOSED'];

    /**
     * @return array{policy: string, decision: string, failureMode: string, blockLevel: ?int, retryAfter: ?int}
     */
    public static function fromResult(string $policyName, RateLimitResultDTO $result): array
    {
        return [
            'policy' => $policyName,
            'decision' => \in_array($result->decision, self::DECISIONS, true) ? $result->decision : 'unrecognized',
            'failureMode' => \in_array($result->failureMode, self::FAILURE_MODES, true) ? $result->failureMode : 'unrecognized',
            'blockLevel' => $result->blockLevel,
            'retryAfter' => $result->retryAfter,
        ];
    }
}
