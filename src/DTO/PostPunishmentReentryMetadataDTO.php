<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/** Safe public representation of a re-entry handoff. */
final readonly class PostPunishmentReentryMetadataDTO implements \JsonSerializable
{
    public function __construct(public string $id, public int $validUntil) {}

    public function jsonSerialize(): mixed
    {
        return ['id' => $this->id, 'validUntil' => $this->validUntil];
    }
}
