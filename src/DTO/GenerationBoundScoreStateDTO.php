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
 *
 * This DTO is the public semantic boundary for every
 * `PunishmentLifecycleStoreInterface` implementation, not a Redis-specific
 * detail: the constructor enforces `source` is one of the two constants,
 * `updatedAt >= 0`, `expiresAt > 0`, `expiresAt >= updatedAt`, `generation`
 * is either null or a positive integer, a null (legacy) generation cannot
 * carry `postPunishmentReentry` evidence, and when evidence is present its
 * `validUntil` equals this snapshot's `expiresAt` exactly. No implementation
 * may construct or return a structurally incoherent instance.
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
     * evidence for the currently served punishment, when present. Its
     * `validUntil` must equal `$expiresAt`; evidence bound to a different
     * expiry is not a valid snapshot of this score.
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
            || $updatedAt < 0 || $expiresAt <= 0 || $expiresAt < $updatedAt
            || ($generation !== null && $generation <= 0)
            || ($postPunishmentReentry !== null && $generation === null)
            || ($postPunishmentReentry !== null && $postPunishmentReentry->validUntil !== $expiresAt)) {
            throw new \InvalidArgumentException('Invalid generation-bound score state.');
        }
    }

    /**
     * Return the stable score snapshot shape, including nullable legacy
     * generation and optional lifecycle evidence fields.
     */
    public function jsonSerialize(): mixed
    {
        return ['source' => $this->source, 'value' => $this->value, 'updatedAt' => $this->updatedAt,
            'expiresAt' => $this->expiresAt, 'generation' => $this->generation,
            'postPunishmentReentry' => $this->postPunishmentReentry];
    }
}
