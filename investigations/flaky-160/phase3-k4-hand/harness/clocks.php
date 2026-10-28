<?php
// PROVENANCE: copy of investigations/flaky-160/phase2/harness/c.php at d37f9a3, sha256 ec603e0da196d726287abc04733173debc0d48b44a3b8c8d5c429ffad9836f9d, ADAPTED: only the two clock classes are kept (milliseconds, a pass-through offset for D-DET); the difference is recorded in PROVENANCE-clocks.php.diff. Delete this line and reverse that diff to get the original byte for byte.

declare(strict_types=1);

// #160 phase 3 clocks (PLAN.md §4, §13 LT-160D-01). Nothing under src/ or
// tests/ changes: the store takes these through its existing third constructor
// parameter (RedisFullCapabilityStore L1381–L1389).
//
//   SettableClock   a fixed reading in milliseconds, set by the harness (V-CLOCK only)
//   RecordingClock  pass-through: returns the inner reading (SystemClock by default) and records it;
//                   offsetMs is 0 everywhere except D-DET's injected MUTATE (+1,000 ms)

namespace Flaky160D;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\SharedCommon\Infrastructure\SystemClock;

function fromMicroseconds(int $microseconds): DateTimeImmutable
{
    $value = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', intdiv($microseconds, 1_000_000), $microseconds % 1_000_000), new DateTimeZone('UTC'));
    if ($value === false) {
        throw new \RuntimeException('unrepresentable clock value');
    }
    return $value;
}

/** Value set by the harness between calls; used only in vclock. */
final class SettableClock implements ClockInterface
{
    public int $reads = 0;
    private DateTimeImmutable $value;

    public function __construct(int $milliseconds)
    {
        $this->set($milliseconds);
    }

    public function set(int $milliseconds): void
    {
        $this->value = fromMicroseconds($milliseconds * 1000);
    }

    public function now(): DateTimeImmutable
    {
        $this->reads++;
        return $this->value;
    }

    public function getTimezone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
}

/** Pass-through recorder: returns the real reading unchanged and records it (LT-160B-02, LT-160D-01). */
final class RecordingClock implements ClockInterface
{
    /** @var list<array{string, string}> */
    public array $reads = [];
    public string $label = '';
    public int $offsetMs = 0;
    private ClockInterface $inner;

    public function __construct(?ClockInterface $inner = null)
    {
        $this->inner = $inner ?? new SystemClock(new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        $reading = $this->inner->now();
        if ($this->offsetMs !== 0) {
            $reading = fromMicroseconds((int) $reading->format('Uu') + $this->offsetMs * 1000);
        }
        $this->reads[] = [$this->label, $reading->format('U.u')];
        return $reading;
    }

    public function getTimezone(): DateTimeZone
    {
        return $this->inner->getTimezone();
    }
}
