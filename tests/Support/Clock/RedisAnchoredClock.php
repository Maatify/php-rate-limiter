<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;
use RuntimeException;

/**
 * An application clock anchored to the Redis server's `TIME`.
 *
 * `now()` returns the current Redis time plus a fixed skew (negative = the
 * application is behind Redis) plus a virtual advance that a test moves
 * forward instead of waiting. With `secondStartMinusMicrosecond`, it returns
 * the start of the current Redis second minus one microsecond, so the caller
 * is in the previous second while Redis is in the next one (a deterministic
 * second-boundary crossing). Every call is counted.
 */
final class RedisAnchoredClock implements ClockInterface
{
    private float $advance = 0.0;

    private int $reads = 0;

    public function __construct(
        private readonly RedisCommandExecutorInterface $redis,
        private readonly float $skewSeconds = 0.0,
        private readonly bool $secondStartMinusMicrosecond = false,
    ) {}

    public function advance(float $seconds): void
    {
        $this->advance += $seconds;
    }

    public function reads(): int
    {
        return $this->reads;
    }

    public function redisNow(): float
    {
        $time = $this->redis->execute(['TIME']);
        if (! is_array($time) || ! isset($time[0], $time[1]) || ! is_numeric($time[0]) || ! is_numeric($time[1])) {
            throw new RuntimeException('Redis TIME reply is malformed.');
        }

        return (int) $time[0] + ((int) $time[1]) / 1_000_000;
    }

    public function now(): DateTimeImmutable
    {
        $this->reads++;
        $redis = $this->redisNow();
        $value = $this->secondStartMinusMicrosecond
            ? floor($redis) - 0.000001
            : $redis + $this->skewSeconds;

        return self::at($value + $this->advance);
    }

    public function getTimezone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    public static function at(float $unix): DateTimeImmutable
    {
        $result = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $unix), new DateTimeZone('UTC'));
        if ($result === false) {
            throw new RuntimeException('Unable to build a timestamp.');
        }

        return $result->setTimezone(new DateTimeZone('UTC'));
    }
}
