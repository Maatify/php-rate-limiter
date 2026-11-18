<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Exception;

/**
 * An independent call began while another call was in flight on the same runtime graph, or on a resource the graph shares with
 * another graph (DEC-041).
 *
 * It is an integration error, never a decision: the refused call changed nothing and consumed nothing, it is not retried by the
 * library, and it is never converted into a backend failure, a concurrency block or a fallback answer. Retrying is the Host's choice
 * and is safe only after the call in flight has finished.
 */
final class OverlappingRequestException extends RateLimiterException
{
    public function __construct(public readonly string $entry, ?\Throwable $previous = null)
    {
        parent::__construct(
            "Overlapping call refused at '{$entry}': another call is in flight on this runtime graph or on a store it shares. "
            . 'Use one runtime graph (with its own store object) per concurrent execution context, or wait for the call in flight to finish.',
            0,
            $previous,
        );
    }
}
