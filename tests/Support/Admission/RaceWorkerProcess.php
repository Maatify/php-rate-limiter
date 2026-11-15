<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

/** One child process of `AdmissionRaceRunner`: its pipes, what it has written so far and its state (#228). */
final class RaceWorkerProcess
{
    public string $out = '';

    public string $err = '';

    public bool $ready = false;

    public bool $closed = false;

    public ?int $exit = null;

    /**
     * @param resource $process
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        public readonly int $index,
        public $process,
        public $stdin,
        public $stdout,
        public $stderr,
    ) {}

    public function exited(): bool
    {
        if ($this->exit !== null) {
            return true;
        }
        if (! is_resource($this->process)) {
            return true;
        }
        $status = proc_get_status($this->process);
        if (! $status['running']) {
            // The exit code is reported once; keep it, proc_close() would answer -1 afterwards.
            $this->exit = $status['exitcode'];

            return true;
        }

        return false;
    }

    public function diagnostics(): string
    {
        return sprintf('[worker %d exit=%s stdout=%s stderr=%s]', $this->index, var_export($this->exit, true), json_encode(substr($this->out, 0, 600)), json_encode(substr($this->err, 0, 1500)));
    }
}
