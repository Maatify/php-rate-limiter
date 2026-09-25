<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Safe public representation of a claimable post-punishment handoff.
 *
 * The metadata exposes only the opaque identity and validity timestamp. It
 * does not grant re-entry until the composite runtime consumes it atomically.
 */
final readonly class PostPunishmentReentryMetadataDTO implements \JsonSerializable
{
    /**
     * @param string $id Opaque lifecycle identity to pass back to the runtime.
     * @param int $validUntil Public validity boundary in Unix seconds.
     */
    public function __construct(public string $id, public int $validUntil) {}

    public function jsonSerialize(): mixed
    {
        return ['id' => $this->id, 'validUntil' => $this->validUntil];
    }
}
