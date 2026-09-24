<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/** Internal immutable identity for one served punishment lifecycle. */
final readonly class PostPunishmentReentryStateDTO implements \JsonSerializable
{
    public function __construct(public string $id, public int $validUntil)
    {
        if (! preg_match('/\A[a-f0-9]{32}\z/D', $id) || $validUntil <= 0) {
            throw new \InvalidArgumentException('Invalid post-punishment re-entry state.');
        }
    }

    public function jsonSerialize(): mixed
    {
        return ['id' => $this->id, 'validUntil' => $this->validUntil];
    }
}
