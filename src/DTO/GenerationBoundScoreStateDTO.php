<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/** Coherent current/previous K4 score snapshot. */
final readonly class GenerationBoundScoreStateDTO implements \JsonSerializable
{
    public const SOURCE_CURRENT = 'current';
    public const SOURCE_PREVIOUS = 'previous';

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
