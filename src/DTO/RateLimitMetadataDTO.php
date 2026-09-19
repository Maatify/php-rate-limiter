<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

use Maatify\RateLimiter\DTO\RateLimitContextMetadataDTO;

final readonly class RateLimitMetadataDTO implements \JsonSerializable
{
    public function __construct(
        public ?string $signal = null,
        public ?string $cause = null,
        public ?RateLimitContextMetadataDTO $context = null
    ) {}

    public function jsonSerialize(): mixed
    {
        return [
            'signal' => $this->signal,
            'cause' => $this->cause,
            'context' => $this->context,
        ];
    }
}
