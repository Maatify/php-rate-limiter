<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/** Result of an optimistic generation-bound score mutation. */
final readonly class GenerationBoundScoreMutationDTO implements \JsonSerializable
{
    public function __construct(public bool $applied, public ?GenerationBoundScoreStateDTO $state)
    {
        if ($applied !== ($state !== null)) {
            throw new \InvalidArgumentException('Applied score mutations must return their new state.');
        }
    }

    public function jsonSerialize(): mixed
    {
        return ['applied' => $this->applied, 'state' => $this->state];
    }
}
