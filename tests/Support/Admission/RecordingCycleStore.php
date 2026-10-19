<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\DTO\HardBlockCycleResultDTO;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;

/** Reference store that records every persisted hard-block cycle transition (DEC-003). It changes no behaviour. */
final class RecordingCycleStore extends InMemoryRateLimitStore
{
    /** @var list<array{key: string, result: HardBlockCycleResultDTO}> */
    public array $cycleLog = [];

    public function blockWithCycleTracking(
        string $currentKey,
        ?string $previousKey,
        int $level,
        int $durationSeconds,
        int $now,
        int $cycleWindowSeconds,
        int $cycleThreshold,
        int $pauseSeconds,
        int $pauseHistoryRetentionSeconds,
    ): HardBlockCycleResultDTO {
        $result = parent::blockWithCycleTracking(
            $currentKey,
            $previousKey,
            $level,
            $durationSeconds,
            $now,
            $cycleWindowSeconds,
            $cycleThreshold,
            $pauseSeconds,
            $pauseHistoryRetentionSeconds,
        );
        $this->cycleLog[] = ['key' => $currentKey, 'result' => $result];

        return $result;
    }
}
