<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Service;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * DEC-041 (F266-16): a raw Host clock handed to a package class that is assembled by hand (not through the Builder). Every reading and
 * timezone query is passed through one-to-one, so the number of readings and the time authority are unchanged, and is a Host crossing
 * of the call that owns this clock: a library call made from the clock on the same stack is a re-entry and is refused.
 *
 * The request clock of the Builder graph and an already wrapped clock are never wrapped again.
 *
 * @internal
 */
final class HostClock implements ClockInterface, OwnedResources
{
    private function __construct(private readonly ClockInterface $inner) {}

    public static function of(ClockInterface $clock): ClockInterface
    {
        return $clock instanceof RequestScopedClock || $clock instanceof self ? $clock : new self($clock);
    }

    public function now(): DateTimeImmutable
    {
        return OwnershipRegistry::crossing($this, fn(): DateTimeImmutable => $this->inner->now());
    }

    public function getTimezone(): DateTimeZone
    {
        return OwnershipRegistry::crossing($this, fn(): DateTimeZone => $this->inner->getTimezone());
    }

    /** @return list<object> */
    public function ownedResources(): array
    {
        return [$this];
    }
}
