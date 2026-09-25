<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

/**
 * Result of one optimistic generation-bound score mutation.
 *
 * `applied=false` is an expected stale-snapshot conflict and carries no state;
 * `applied=true` carries the atomically persisted current state after the
 * generation advance. Backend corruption is not represented as a conflict.
 */
final readonly class GenerationBoundScoreMutationDTO implements \JsonSerializable
{
    /**
     * @param bool $applied Whether the optimistic mutation won its state fence.
     * @param ?GenerationBoundScoreStateDTO $state The new state only when
     * applied is true.
     */
    public function __construct(public bool $applied, public ?GenerationBoundScoreStateDTO $state)
    {
        if ($applied !== ($state !== null)) {
            throw new \InvalidArgumentException('Applied score mutations must return their new state.');
        }
    }

    /**
     * Return the stable serialized mutation result, including `state` only as
     * the applied generation-bound state and otherwise as null for a conflict.
     */
    public function jsonSerialize(): mixed
    {
        return ['applied' => $this->applied, 'state' => $this->state];
    }
}
