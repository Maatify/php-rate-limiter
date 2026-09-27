<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Opaque current/previous references used by bounded correlation services.
 */
final readonly class BoundedCorrelationObservationDTO implements \JsonSerializable
{
    public function __construct(
        public string $currentKey,
        public string $currentMember,
        public ?string $previousKey = null,
        public ?string $previousMember = null,
        public ?string $bridgeKey = null,
    ) {
        $hasPreviousKey = $this->previousKey !== null;
        $hasPreviousMember = $this->previousMember !== null;
        $hasBridgeKey = $this->bridgeKey !== null;
        if ($hasPreviousKey !== $hasPreviousMember || $hasPreviousKey !== $hasBridgeKey) {
            throw new RateLimiterException(
                'Previous bounded-correlation key, member, and bridge must be provided together.',
            );
        }
    }

    public function jsonSerialize(): mixed
    {
        return [
            'currentKey' => $this->currentKey,
            'currentMember' => $this->currentMember,
            'previousKey' => $this->previousKey,
            'previousMember' => $this->previousMember,
            'bridgeKey' => $this->bridgeKey,
        ];
    }
}
