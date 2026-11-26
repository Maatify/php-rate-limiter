<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission\Archive;

use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;

/**
 * @phpstan-import-type Step from ReplaySession
 *
 * NP-IN (G5 v2 PLAN §… "NP-IN", g5v2NpEmit): after every admission on the proven door, the `N_P` member of the reservation the
 * store admitted is read and classified: the device key of the request's fingerprint hash, or the device key of its instance-id
 * hash. The key derivation is `AdmissionRuntime::key()` (purpose `dev`, empty label), repeated here so that a mutation of the
 * runtime cannot change both sides of the check.
 */
final class NpAudit
{
    public int $pAdmitted = 0;
    public int $memberIsFingerprint = 0;
    public int $memberIsIdComponent = 0;
    public int $withoutMember = 0;
    private DeviceIdentityResolver $resolver;

    public function __construct()
    {
        $this->resolver = new DeviceIdentityResolver(new FingerprintHasher(EngineLab::FINGERPRINT_SECRET));
    }

    /** @param list<string> $fields */
    private static function encodeTuple(string $magic, array $fields): string
    {
        $out = $magic . pack('N', count($fields));
        foreach ($fields as $field) {
            $out .= pack('N', strlen($field)) . $field;
        }

        return $out;
    }

    /** Independent oracle of the Admission `a1` `dev` scope key (DEC-048 D2), intentionally not calling `AdmissionKeyDeriver`. */
    private static function devKey(string $value): string
    {
        $cohortSubkey = hash_hmac('sha256', 'maatify.rate-limiter.key-domain.admission.cohort.a1', EngineLab::STATE_SECRET, true);
        $cohort = hash_hmac(
            'sha256',
            self::encodeTuple("maatify.rate-limiter.admission.cohort.a1\x00", ['a1', EngineLab::ENV, EngineLab::TRUST_DOMAIN]),
            $cohortSubkey,
        );
        $scopeSubkey = hash_hmac('sha256', 'maatify.rate-limiter.key-domain.admission.scope.a1', EngineLab::KEY_SECRET, true);
        $digest = hash_hmac(
            'sha256',
            self::encodeTuple("maatify.rate-limiter.admission.scope.a1\x00", [$cohort, EngineLab::ENV, EngineLab::TRUST_DOMAIN, EngineLab::GROUP, 'dev', $value, '']),
            $scopeSubkey,
        );

        return 'a1.scope.dev.' . $digest;
    }

    /** @return \Closure(Step, ReplaySession): void */
    public function observer(): \Closure
    {
        return $this->observe(...);
    }

    /** @param Step $step */
    private function observe(array $step, ReplaySession $s): void
    {
        if ($step['engineLane'] !== 'P') {
            return;
        }
        $this->pAdmitted++;
        $admitted = $s->lab->admissionStore->admitted;
        $request = end($admitted);
        if ($request === false) {
            throw new \LogicException('An admission on P left no admitted reservation.');
        }
        $member = null;
        foreach ($request->scopes as $scope) {
            if ($scope->kind === AdmissionScopeKindEnum::DEVICE_MEMBER) {
                $member = $scope->member;
            }
        }
        if ($member === null) {
            $this->withoutMember++;

            return;
        }
        $device = $this->resolver->resolve($step['context']);
        $this->memberIsFingerprint += $device->fingerprintHash !== null && $member === self::devKey($device->fingerprintHash) ? 1 : 0;
        $this->memberIsIdComponent += $device->deviceIdHash !== null && $member === self::devKey($device->deviceIdHash) ? 1 : 0;
    }
}
