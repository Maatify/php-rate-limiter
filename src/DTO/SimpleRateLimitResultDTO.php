<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Typed result of one simple fixed-window throttle consume().
 *
 * This is a dedicated contract, separate from RateLimitResultDTO: simple
 * throttling has no block level or score metadata (DEC-009).
 */
final readonly class SimpleRateLimitResultDTO implements \JsonSerializable
{
    public const NORMAL = 'NORMAL';
    public const FAIL_CLOSED = 'FAIL_CLOSED';

    /**
     * @param bool $allowed Whether this consume was admitted.
     * @param int $limit The policy-defined limit this result was evaluated against.
     * @param int $remaining Consumes left in the current window, clamped at zero.
     * @param ?int $retryAfter Seconds until the window ends when denied; 0 when allowed; null on FAIL_CLOSED.
     * @param ?int $resetAt Fixed end instant (unix timestamp) of the current window; null on FAIL_CLOSED.
     * @param string $failureMode One of self::NORMAL or self::FAIL_CLOSED.
     */
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public ?int $retryAfter,
        public ?int $resetAt,
        public string $failureMode,
    ) {}

    public function jsonSerialize(): mixed
    {
        return [
            'allowed' => $this->allowed,
            'limit' => $this->limit,
            'remaining' => $this->remaining,
            'retryAfter' => $this->retryAfter,
            'resetAt' => $this->resetAt,
            'failureMode' => $this->failureMode,
        ];
    }
}
