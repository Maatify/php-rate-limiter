<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

final readonly class FailureSignalDTO implements \JsonSerializable
{
    public const TYPE_CB_OPENED = 'CB_OPENED';
    public const TYPE_CB_RECOVERED = 'CB_RECOVERED';
    public const TYPE_CB_RE_ENTRY_VIOLATION = 'CB_RE_ENTRY_VIOLATION';

    public function __construct(
        public string $type,
        public string $policyName,
        public ?RateLimitMetadataDTO $metadata = null,
    ) {}

    public function jsonSerialize(): mixed
    {
        return [
            'type' => $this->type,
            'policyName' => $this->policyName,
            'metadata' => $this->metadata,
        ];
    }
}
