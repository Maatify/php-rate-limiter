<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Result of an atomic hard-block, cycle, and K4 evidence transition.
 *
 * An applied transition always returns the block, cycle, and claimable
 * lifecycle state together. An unapplied transition is an expected generation
 * conflict and returns no partial state.
 */
final readonly class PunishmentLifecycleTransitionDTO implements \JsonSerializable
{
    /**
     * @param bool $applied Whether the expected generation was still current.
     * @param ?HardBlockCycleResultDTO $cycle Updated DEC-003 cycle/pause state.
     * @param ?BlockStateDTO $block Persisted hard-block state.
     * @param ?PostPunishmentReentryStateDTO $postPunishmentReentry Claimable
     * evidence attached to the same atomic transition.
     */
    public function __construct(
        public bool $applied,
        public ?HardBlockCycleResultDTO $cycle,
        public ?BlockStateDTO $block,
        public ?PostPunishmentReentryStateDTO $postPunishmentReentry,
    ) {
        if ($applied !== ($cycle !== null && $block !== null && $postPunishmentReentry !== null)) {
            throw new \InvalidArgumentException('Applied lifecycle transitions require all state results.');
        }
    }

    /**
     * Return the stable atomic transition shape; an unapplied conflict keeps
     * all resulting state members null, while an applied transition includes
     * cycle, block, and claimable lifecycle state together.
     */
    public function jsonSerialize(): mixed
    {
        return ['applied' => $this->applied, 'cycle' => $this->cycle, 'block' => $this->block,
            'postPunishmentReentry' => $this->postPunishmentReentry];
    }
}
