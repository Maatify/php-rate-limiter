<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

/**
 * A failed `AdmissionRaceRunner` run. It carries the pids of every child the runner itself started, taken from `proc_open()` at the
 * spawn, so that a test can check the cleanup against an identity the parent holds and not against anything a child published (#228).
 */
final class RaceRunFailure extends \RuntimeException
{
    /** @param list<int> $pids */
    public function __construct(string $message, public readonly array $pids, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
