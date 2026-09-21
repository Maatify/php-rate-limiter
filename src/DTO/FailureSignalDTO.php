<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Describes an observable circuit-breaker transition for an integration sink.
 */
final readonly class FailureSignalDTO implements \JsonSerializable
{
    public const TYPE_CB_OPENED = 'CB_OPENED';
    public const TYPE_CB_RECOVERED = 'CB_RECOVERED';
    public const TYPE_CB_RE_ENTRY_VIOLATION = 'CB_RE_ENTRY_VIOLATION';

    /**
     * @param string $type One of the package-defined signal type constants.
     * @param string $policyName Policy whose circuit-breaker state changed.
     * @param ?RateLimitMetadataDTO $metadata Optional diagnostic context.
     */
    public function __construct(
        public string $type,
        public string $policyName,
        public ?RateLimitMetadataDTO $metadata = null,
    ) {}

    /**
     * Return the signal and optional diagnostic metadata in serialized form.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'type' => $this->type,
            'policyName' => $this->policyName,
            'metadata' => $this->metadata,
        ];
    }
}
