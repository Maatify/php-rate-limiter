<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

final readonly class ScoreThresholdsDTO implements \JsonSerializable
{
    /**
     * @param int $l1 Soft Block Threshold
     * @param int $l2 Hard Block Threshold
     * @param int $l3 Hard Block Extended Threshold
     */
    public function __construct(
        public int $l1,
        public int $l2,
        public int $l3
    ) {}

    public function jsonSerialize(): mixed
    {
        return [
            'l1' => $this->l1,
            'l2' => $this->l2,
            'l3' => $this->l3,
        ];
    }
}
