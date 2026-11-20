<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Study263;

use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\DTO\FailureSignalDTO;

/**
 * #263 study: a Host-written emitter. The library calls emit() (the Host's extension point); whether anything is logged is the
 * Host's code. This one serializes the whole signal and writes it to the sink, which is the Host's two steps, wired.
 */
final class LoggingFailureSignalEmitter implements FailureSignalEmitterInterface
{
    /** @var list<FailureSignalDTO> */
    public array $received = [];

    public function __construct(private readonly RecordingLogSink $sink) {}

    public function emit(FailureSignalDTO $signal): void
    {
        $this->received[] = $signal;
        $this->sink->write(json_encode($signal, JSON_THROW_ON_ERROR));
    }
}
