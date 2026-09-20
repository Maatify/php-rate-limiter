<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

use Maatify\RateLimiter\DTO\RateLimitMetadataDTO;

final readonly class RateLimitResultDTO implements \JsonSerializable
{
    public const DECISION_ALLOW = 'ALLOW';
    public const DECISION_SOFT_BLOCK = 'SOFT_BLOCK';
    public const DECISION_HARD_BLOCK = 'HARD_BLOCK';

    public function __construct(
        public string $decision,
        public ?int $blockLevel,
        public ?int $retryAfter, // in seconds
        public string $failureMode, // NORMAL, DEGRADED, FAIL_OPEN
        public ?RateLimitMetadataDTO $metadata = null,
    ) {}

    public function isAllowed(): bool
    {
        return $this->decision === self::DECISION_ALLOW;
    }

    public function isBlocked(): bool
    {
        return $this->decision !== self::DECISION_ALLOW;
    }

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
