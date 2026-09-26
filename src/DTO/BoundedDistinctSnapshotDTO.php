<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Reports one atomic bounded-distinct observation and its fixed logical window.
 */
final readonly class BoundedDistinctSnapshotDTO implements \JsonSerializable
{
    /**
     * @param list<string> $members Complete logical members retained by the bounded set.
     */
    public function __construct(
        public int $count,
        public bool $accepted,
        public bool $added,
        public array $members,
        public int $expiresAt,
    ) {}

    public function jsonSerialize(): mixed
    {
        return [
            'count' => $this->count,
            'accepted' => $this->accepted,
            'added' => $this->added,
            'members' => $this->members,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
