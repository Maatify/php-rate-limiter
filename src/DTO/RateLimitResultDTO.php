<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

use Maatify\RateLimiter\DTO\RateLimitMetadataDTO;

/**
 * Immutable result returned after normal or degraded policy evaluation.
 */
final readonly class RateLimitResultDTO implements \JsonSerializable
{
    public const DECISION_ALLOW = 'ALLOW';
    public const DECISION_SOFT_BLOCK = 'SOFT_BLOCK';
    public const DECISION_HARD_BLOCK = 'HARD_BLOCK';

    /**
     * @param string $decision One of the DECISION_* constants.
     * @param ?int $blockLevel Applied level, or null when no block was selected.
     * @param ?int $retryAfter Seconds until retry, when applicable.
     * @param string $failureMode NORMAL, DEGRADED_MODE, or FAIL_OPEN/FAIL_CLOSED.
     * @param ?RateLimitMetadataDTO $metadata Optional outcome diagnostics.
     */
    public function __construct(
        public string $decision,
        public ?int $blockLevel,
        public ?int $retryAfter, // in seconds
        public string $failureMode, // NORMAL, DEGRADED, FAIL_OPEN
        public ?RateLimitMetadataDTO $metadata = null,
    ) {}

    /**
     * Return whether the decision permits the request.
     */
    public function isAllowed(): bool
    {
        return $this->decision === self::DECISION_ALLOW;
    }

    /**
     * Return whether the decision is either a soft or hard block.
     */
    public function isBlocked(): bool
    {
        return $this->decision !== self::DECISION_ALLOW;
    }

    /**
     * Return the decision and diagnostics in the public serialized shape.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'decision' => $this->decision,
            'blockLevel' => $this->blockLevel,
            'retryAfter' => $this->retryAfter,
            'failureMode' => $this->failureMode,
            'metadata' => $this->metadata,
        ];
    }
}
