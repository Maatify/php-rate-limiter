<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\DTO;

use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * One scope of an admission reservation, as the package derived it. Keys are opaque package-derived
 * references; the capacity and the lifetime are supplied by the caller, never defaulted here.
 *
 * `successKeepsSettled` (set-size scopes only): a recorded success cancels a pending (not yet due) member of the set but
 * never removes a settled failure. It is the D8 rule; the default is the net rule of the U door (a success removes the
 * member).
 */
final readonly class AdmissionScopeDTO
{
    public function __construct(
        public string $name,
        public AdmissionScopeKindEnum $kind,
        public string $key,
        public int $capacity,
        public int $ttlSeconds,
        public ?string $previousKey = null,
        public ?string $member = null,
        public ?string $previousMember = null,
        public bool $successKeepsSettled = false,
    ) {
        if (trim($name) === '' || trim($key) === '') {
            throw new RateLimiterException('Admission scope name and key must not be empty.');
        }
        if ($capacity < 1 || $ttlSeconds < 1) {
            throw new RateLimiterException('Admission scope capacity and lifetime must be positive integers.');
        }
        $needsMember = $kind === AdmissionScopeKindEnum::DEVICE_MEMBER || $kind === AdmissionScopeKindEnum::SET_SIZE;
        if ($needsMember && ($member === null || trim($member) === '')) {
            throw new RateLimiterException('A device-member or set-size admission scope requires a member.');
        }
        if ($successKeepsSettled && $kind !== AdmissionScopeKindEnum::SET_SIZE) {
            throw new RateLimiterException('Only a set-size admission scope can keep a settled failure on success.');
        }
    }
}
