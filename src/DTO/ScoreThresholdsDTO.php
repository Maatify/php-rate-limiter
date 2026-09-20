<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Defines the L1, L2, and L3 score thresholds for one limiter scope.
 */
final readonly class ScoreThresholdsDTO implements \JsonSerializable
{
    /**
     * @param int $l1 Soft block threshold.
     * @param int $l2 Hard block threshold.
     * @param int $l3 Extended hard block threshold.
     */
    public function __construct(
        public int $l1,
        public int $l2,
        public int $l3,
    ) {}

    /**
     * Return the three level thresholds in serialized form.
     */
    public function jsonSerialize(): mixed
    {
        return [
            'l1' => $this->l1,
            'l2' => $this->l2,
            'l3' => $this->l3,
        ];
    }
}
