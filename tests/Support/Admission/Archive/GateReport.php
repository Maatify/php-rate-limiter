<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission\Archive;

/**
 * The reported (not gated) values of the plan: one line per report on STDERR, `REPORT <gate> <json>`, so the full suite log
 * carries them for `RESULTS.md` and for a re-run by the reviewer. Nothing is asserted from it.
 */
final class GateReport
{
    /** @param array<string, mixed> $values */
    public static function emit(string $gate, array $values): void
    {
        fwrite(STDERR, 'REPORT ' . $gate . ' ' . json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
