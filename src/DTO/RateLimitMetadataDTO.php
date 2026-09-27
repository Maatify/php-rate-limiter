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
     * @param ?PostPunishmentReentryMetadataDTO $postPunishmentReentry Opaque,
     * one-shot application handoff metadata; it is present only when a public
     * post-punishment check exposes claimable evidence.
     */
    public function __construct(
        public ?string $signal = null,
        public ?string $cause = null,
        public ?RateLimitContextMetadataDTO $context = null,
        public ?PostPunishmentReentryMetadataDTO $postPunishmentReentry = null,
    ) {}

    /**
     * Return diagnostics in the public serialized shape. The
     * `postPunishmentReentry` member is included only when metadata exists and
     * is omitted entirely when the value is null.
     */
    public function jsonSerialize(): mixed
    {
        $serialized = [
            'signal' => $this->signal,
            'cause' => $this->cause,
            'context' => $this->context,
        ];
        if ($this->postPunishmentReentry !== null) {
            $serialized['postPunishmentReentry'] = $this->postPunishmentReentry;
        }
        return $serialized;
    }
}
