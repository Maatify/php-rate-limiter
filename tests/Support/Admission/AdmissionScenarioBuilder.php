<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\DTO\AdmissionPermitDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;

/** Short constructors for the admission request shapes used by the store gates. */
final class AdmissionScenarioBuilder
{
    public const CLASS_NAME = 'login';

    public const NOW = 1767268800 + 900;

    public static function counter(string $name, string $key, int $capacity, int $ttl = 1200, ?string $previousKey = null): AdmissionScopeDTO
    {
        return new AdmissionScopeDTO($name, AdmissionScopeKindEnum::COUNTER, $key, $capacity, $ttl, $previousKey);
    }

    public static function device(string $name, string $key, int $capacity, string $member, int $ttl = 86400, ?string $previousKey = null, ?string $previousMember = null): AdmissionScopeDTO
    {
        return new AdmissionScopeDTO($name, AdmissionScopeKindEnum::DEVICE_MEMBER, $key, $capacity, $ttl, $previousKey, $member, $previousMember);
    }

    public static function setSize(string $name, string $key, int $capacity, string $member, int $ttl = 1200): AdmissionScopeDTO
    {
        return new AdmissionScopeDTO($name, AdmissionScopeKindEnum::SET_SIZE, $key, $capacity, $ttl, null, $member);
    }

    /** Bucket: `capacity` = C takes, `ttl` = T seconds per take (credit-seconds C*T, refill 1 per second). */
    public static function bucket(string $name, string $key, int $takes, int $seconds): AdmissionScopeDTO
    {
        return new AdmissionScopeDTO($name, AdmissionScopeKindEnum::BUCKET, $key, $takes, $seconds);
    }

    public static function request(AdmissionScopeDTO ...$scopes): AdmissionReservationRequestDTO
    {
        return new AdmissionReservationRequestDTO(self::CLASS_NAME, array_values($scopes));
    }

    public static function withPermit(AdmissionPermitDTO $permit, AdmissionScopeDTO ...$scopes): AdmissionReservationRequestDTO
    {
        return new AdmissionReservationRequestDTO(self::CLASS_NAME, array_values($scopes), $permit);
    }

    public static function permit(string $id, int $deadlineAt, int $grace = 600): AdmissionPermitDTO
    {
        return new AdmissionPermitDTO($id, 'U', 'acctT', 'login', $deadlineAt, $grace);
    }

    public static function outcome(AdmissionOutcomeKindEnum $kind, AdmissionScopeDTO ...$scopes): AdmissionOutcomeDTO
    {
        return new AdmissionOutcomeDTO(self::CLASS_NAME, $kind, array_values($scopes));
    }
}
