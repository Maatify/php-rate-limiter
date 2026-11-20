<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Study263;

/**
 * #263 study: a Host log destination isolated from the serialization step. The Host's two steps are separate on purpose:
 * (1) it serializes (json_encode of a DTO, or an allowlisted array), (2) it hands the resulting line to write().
 * What reaches this sink is exactly what a Host's logger would persist; nothing in the package ever calls it.
 */
final class RecordingLogSink
{
    /** @var list<string> */
    public array $lines = [];

    public function write(string $line): void
    {
        $this->lines[] = $line;
    }
}
