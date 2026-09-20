<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

use Maatify\RateLimiter\DTO\RateLimitContextMetadataDTO;

/**
 * Carries optional machine-readable outcome and failure diagnostics.
 */
final readonly class RateLimitMetadataDTO implements \JsonSerializable
{
    /**
     * @param ?string $signal Emitted signal or diagnostic identifier.
     * @param ?string $cause Machine-readable cause of the outcome.
     * @param ?RateLimitContextMetadataDTO $context Optional reason and scope context.
     */
    public function __construct(
        public ?string $signal = null,
        public ?string $cause = null,
        public ?RateLimitContextMetadataDTO $context = null,
    ) {}

    /**
     * Return diagnostics in the public serialized shape.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'signal' => $this->signal,
            'cause' => $this->cause,
            'context' => $this->context,
        ];
    }
}
