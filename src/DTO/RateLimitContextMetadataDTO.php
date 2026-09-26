<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Provides optional context attached to a rate-limit or failure explanation.
 */
final readonly class RateLimitContextMetadataDTO implements \JsonSerializable
{
    /**
     * @param ?string $reason Machine-readable reason for the outcome.
     * @param ?string $scope Scope associated with the reason, when known.
     */
    public function __construct(
        public ?string $reason = null,
        public ?string $scope = null,
    ) {}

    /**
     * Return the optional explanation context in serialized form.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'reason' => $this->reason,
            'scope' => $this->scope,
        ];
    }
}
