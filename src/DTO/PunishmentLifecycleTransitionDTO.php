<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/** Atomic hard-block, cycle, and K4 punishment-evidence transition. */
final readonly class PunishmentLifecycleTransitionDTO implements \JsonSerializable
{
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

    public function jsonSerialize(): mixed
    {
        return ['applied' => $this->applied, 'cycle' => $this->cycle, 'block' => $this->block,
            'postPunishmentReentry' => $this->postPunishmentReentry];
    }
}
