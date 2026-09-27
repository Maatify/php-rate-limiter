<?php

declare(strict_types=1);

namespace ConsumerVerification;

use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\DTO\FailureSignalDTO;

final class RecordingFailureSignalEmitter implements FailureSignalEmitterInterface
{
    /** @var list<FailureSignalDTO> */
    private array $signals = [];

    public function emit(FailureSignalDTO $signal): void
    {
        $this->signals[] = $signal;
    }

    /**
     * @return list<FailureSignalDTO>
     */
    public function signals(): array
    {
        return $this->signals;
    }
}
