<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\FailureSignal;

use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\DTO\FailureSignalDTO;

class RecordingFailureSignalEmitter implements FailureSignalEmitterInterface
{
    /** @var array<int, FailureSignalDTO> */
    private array $emitted = [];

    public function emit(FailureSignalDTO $signal): void
    {
        $this->emitted[] = $signal;
    }

    /**
     * @return array<int, FailureSignalDTO>
     */
    public function getEmitted(): array
    {
        return $this->emitted;
    }

    public function clear(): void
    {
        $this->emitted = [];
    }
}
