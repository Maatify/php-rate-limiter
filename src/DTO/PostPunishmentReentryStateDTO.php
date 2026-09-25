<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Immutable backend lifecycle identity for one served punishment.
 *
 * This state is package-owned evidence. Consumers receive the corresponding
 * metadata DTO and must use the public runtime claim operation instead of
 * persisting or interpreting this object directly.
 */
final readonly class PostPunishmentReentryStateDTO implements \JsonSerializable
{
    /**
     * @param string $id Lowercase 32-character lifecycle identity.
     * @param int $validUntil Unix timestamp through which the identity may be
     * claimed, subject to the authoritative score expiry.
     */
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
