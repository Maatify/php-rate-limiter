<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;

/** The mutable PHP references mirror Redis admission's caller-input checks before applying state changes. */
final class AdmissionReferenceInput
{
    private const EXACT_MAX = 9007199254740991;

    private const TTL_MAX = 9007199254740;

    private const PERMIT_EXISTS = 'permit_exists';

    public static function reservation(AdmissionReservationRequestDTO $request, int $now, bool $supportsSettlement): void
    {
        self::assertTime($now);
        self::assertScopes($request->scopes, $now, true);
        $permit = $request->permit;
        if ($permit !== null) {
            if ($permit->deadlineAt > self::EXACT_MAX - $permit->graceSeconds) {
                throw new RateLimiterException('Admission permit deadline and grace are not representable.');
            }
            if ($permit->deadlineAt + $permit->graceSeconds - $now > self::TTL_MAX) {
                throw new RateLimiterException('Admission permit lifetime exceeds the representable expiry.');
            }
        }
        if ($supportsSettlement && $permit !== null) {
            foreach ($request->scopes as $scope) {
                if ($scope->kind === AdmissionScopeKindEnum::SET_SIZE && $scope->settlesOnExpiry && $permit->deadlineAt - $now > $scope->ttlSeconds) {
                    throw new RateLimiterException('Admission permit deadline lies beyond the lifetime of a failure-set scope.');
                }
            }
        }
        foreach ($request->scopes as $scope) {
            if ($scope->kind === AdmissionScopeKindEnum::COUNTER) {
                $member = $scope->member;
                if (($member === null || $member === '') && ($permit === null || $permit->id === '')) {
                    throw new RateLimiterException('A counter admission scope requires a member.');
                }
            }
        }
    }

    public static function outcome(AdmissionOutcomeDTO $outcome, int $now): void
    {
        self::assertTime($now);
        self::assertScopes($outcome->scopes, $now, false);
    }

    public static function claim(string $permitId, string $lane, string $subject, string $operation, int $now): void
    {
        self::assertTime($now);
        self::assertClaimIdentity($permitId, $lane, $subject, $operation);
    }

    public static function atomicClaim(string $lane, string $subject, string $operation, AdmissionOutcomeDTO $outcome, int $now): string
    {
        self::assertTime($now);
        $permitId = $outcome->permitId;
        if ($permitId === null || trim($permitId) === '') {
            throw new RateLimiterException('Admission permit id is required for atomic outcome claim.');
        }
        self::assertClaimIdentity($permitId, $lane, $subject, $operation);
        self::assertScopes($outcome->scopes, $now, false);

        return $permitId;
    }

    private static function assertClaimIdentity(string $permitId, string $lane, string $subject, string $operation): void
    {
        foreach (['permit id' => $permitId, 'lane' => $lane, 'subject' => $subject, 'operation' => $operation] as $label => $value) {
            if (trim($value) === '') {
                throw new RateLimiterException('Admission permit ' . $label . ' must not be empty.');
            }
        }
    }

    private static function assertTime(int $now): void
    {
        if ($now < 0 || $now > self::EXACT_MAX) {
            throw new RateLimiterException('Admission caller time must be a non-negative representable timestamp.');
        }
    }

    /**
     * Rejects the same inputs Redis rejects before sending a command: a scope whose fields do not
     * fit its kind, a duplicated key, or numbers that Lua doubles or Redis expiries cannot represent.
     *
     * @param list<AdmissionScopeDTO> $scopes
     */
    private static function assertScopes(array $scopes, int $now, bool $reserve): void
    {
        $seen = [];
        foreach ($scopes as $scope) {
            if ($reserve && $scope->name === self::PERMIT_EXISTS) {
                throw new RateLimiterException('Admission scope name is reserved.');
            }
            $previous = $scope->previousKey === $scope->key ? null : $scope->previousKey;
            $device = $scope->kind === AdmissionScopeKindEnum::DEVICE_MEMBER;
            $keyed = $device || $scope->kind === AdmissionScopeKindEnum::SET_SIZE || $scope->kind === AdmissionScopeKindEnum::COUNTER;
            if ($previous !== null && ! ($device || $scope->kind === AdmissionScopeKindEnum::COUNTER)) {
                throw new RateLimiterException('Only counter and device-member admission scopes can name a previous generation.');
            }
            if ($previous !== null && trim($previous) === '') {
                throw new RateLimiterException('Admission scope previous key must not be blank.');
            }
            if ((! $keyed && $scope->member !== null) || (! $device && $scope->previousMember !== null)) {
                throw new RateLimiterException('Admission scope carries a member its kind does not use.');
            }
            if ($scope->previousMember !== null && trim($scope->previousMember) === '') {
                throw new RateLimiterException('Admission scope previous member must not be blank.');
            }
            if ($scope->ttlSeconds > self::TTL_MAX || $scope->capacity > self::EXACT_MAX || $now > self::EXACT_MAX - $scope->ttlSeconds) {
                throw new RateLimiterException('Admission scope capacity or lifetime is not representable.');
            }
            if ($scope->kind === AdmissionScopeKindEnum::BUCKET && $scope->capacity > intdiv(self::EXACT_MAX, $scope->ttlSeconds)) {
                throw new RateLimiterException('Admission bucket capacity times its take cost is not representable.');
            }
            $identity = $scope->kind->value . "\0" . $scope->key;
            if (isset($seen[$identity])) {
                throw new RateLimiterException('An admission request names the same scope key twice.');
            }
            $seen[$identity] = true;
        }
    }
}
