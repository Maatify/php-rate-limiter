<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

use Maatify\RateLimiter\Config\FailureFallbackDimension;

/**
 * One bounded backend-failure fallback rule for a single dimension.
 */
final readonly class FailureFallbackRuleDTO implements \JsonSerializable
{
    /**
     * @param FailureFallbackDimension $dimension Scope this rule bounds.
     * @param int $limit Maximum allowed count within the window.
     * @param int $windowSeconds Fixed window duration in seconds.
     */
    public function __construct(
        public FailureFallbackDimension $dimension,
        public int $limit,
        public int $windowSeconds,
    ) {}

    /**
     * Return the rule fields in serialized form.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'dimension' => $this->dimension->value,
            'limit' => $this->limit,
            'windowSeconds' => $this->windowSeconds,
        ];
    }
}
