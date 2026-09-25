<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Coherent, read-only current/previous K4 score snapshot.
 *
 * `source` identifies the authoritative logical generation selected by the
 * store. A null generation denotes legacy state; generated state is fenced by
 * a positive generation and requires an authoritative expiry. Re-entry state
 * is evidence attached to the snapshot and is not itself a claim.
 */
final readonly class GenerationBoundScoreStateDTO implements \JsonSerializable
{
    public const SOURCE_CURRENT = 'current';
    public const SOURCE_PREVIOUS = 'previous';

    /**
     * @param string $source Either SOURCE_CURRENT or SOURCE_PREVIOUS.
     * @param int $value Persisted K4 score value.
     * @param int $updatedAt Unix timestamp of the score mutation.
     * @param int $expiresAt Authoritative score expiry timestamp.
     * @param ?int $generation Null for legacy state, otherwise a positive fence.
     * @param ?PostPunishmentReentryStateDTO $postPunishmentReentry Historical
     * evidence for the currently served punishment, when present.
     */
    public function __construct(
        public string $source,
        public int $value,
        public int $updatedAt,
        public int $expiresAt,
        public ?int $generation,
        public ?PostPunishmentReentryStateDTO $postPunishmentReentry = null,
    ) {
        if (! in_array($source, [self::SOURCE_CURRENT, self::SOURCE_PREVIOUS], true)
            || $updatedAt < 0 || $expiresAt <= 0 || $expiresAt < $updatedAt) {
            throw new \InvalidArgumentException('Invalid generation-bound score state.');
        }
    }

    public function jsonSerialize(): mixed
    {
        return ['source' => $this->source, 'value' => $this->value, 'updatedAt' => $this->updatedAt,
            'expiresAt' => $this->expiresAt, 'generation' => $this->generation,
            'postPunishmentReentry' => $this->postPunishmentReentry];
    }
}
