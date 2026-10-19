<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\AdmissionReservationPolicyInterface;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitMetadataDTO;
use Maatify\RateLimiter\Enum\AdmissionHostModeEnum;
use Maatify\RateLimiter\Enum\PolicyCapabilityEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\AdmissionCapableFullStoreInterface;
use Maatify\RateLimiter\Repository\AdmissionPermitStoreInterface;
use Maatify\RateLimiter\Repository\AdmissionReservationStoreInterface;
use Maatify\RateLimiter\Repository\FullCapabilityStoreInterface;
use Maatify\RateLimiter\Service\AdmissionBindingControlInterface;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use PHPUnit\Framework\TestCase;

/** Shape-level gates of 5-b1: every addition is additive, optional and trailing (DEC-008 Backward Compatibility 1-4). */
final class AdmissionShapesTest extends TestCase
{
    public function testCapabilityEnumGainsExactlyOneCase(): void
    {
        self::assertSame('admission_reservation', PolicyCapabilityEnum::ADMISSION_RESERVATION->value);
        self::assertSame(
            ['credential_spray', 'distributed_account', 'trusted_authentication', 'api_overuse', 'admission_reservation'],
            array_map(static fn(PolicyCapabilityEnum $c): string => $c->value, PolicyCapabilityEnum::cases()),
        );
    }

    public function testHostModeHasTheSingleEligibleCase(): void
    {
        self::assertSame([AdmissionHostModeEnum::PRE_AND_OUT], AdmissionHostModeEnum::cases());
    }

    public function testDeclarationInterfaceIsSeparateFromBlockPolicyInterface(): void
    {
        self::assertFalse(is_subclass_of(AdmissionReservationPolicyInterface::class, BlockPolicyInterface::class));
        self::assertFalse(is_subclass_of(BlockPolicyInterface::class, AdmissionReservationPolicyInterface::class));
        self::assertSame(
            ['getAdmissionBudgetGroup', 'getAdmissionHostMode'],
            self::methodNames(AdmissionReservationPolicyInterface::class),
        );
        self::assertSame(
            ['getBudgetConfig', 'getFailureMode', 'getName', 'getScoreDeltas', 'getScoreThresholds'],
            self::methodNames(BlockPolicyInterface::class),
        );
    }

    public function testFullCapabilityStoreInterfaceIsUntouchedAndTheCombinedInterfaceIsNew(): void
    {
        $full = new \ReflectionClass(FullCapabilityStoreInterface::class);
        self::assertSame([], self::declaredMethodNames(FullCapabilityStoreInterface::class));
        self::assertNotContains(AdmissionReservationStoreInterface::class, $full->getInterfaceNames());

        self::assertTrue((new \ReflectionClass(AdmissionCapableFullStoreInterface::class))->implementsInterface(FullCapabilityStoreInterface::class));
        self::assertTrue((new \ReflectionClass(AdmissionCapableFullStoreInterface::class))->implementsInterface(AdmissionReservationStoreInterface::class));
        self::assertTrue((new \ReflectionClass(AdmissionPermitStoreInterface::class))->implementsInterface(AdmissionReservationStoreInterface::class));
        self::assertFalse(is_subclass_of(AdmissionReservationStoreInterface::class, FullCapabilityStoreInterface::class));
        self::assertSame(
            ['applyAdmissionOutcome', 'bumpBindingEpoch', 'readBindingEpoch', 'reserveAdmission'],
            self::declaredMethodNames(AdmissionReservationStoreInterface::class),
        );
        self::assertSame(['claimAdmissionPermit'], self::declaredMethodNames(AdmissionPermitStoreInterface::class));
        self::assertSame([], self::declaredMethodNames(AdmissionCapableFullStoreInterface::class));
    }

    public function testBindingControlInterfaceCarriesOnlyTheRevocation(): void
    {
        self::assertSame(['revokeAccountBinding'], self::methodNames(AdmissionBindingControlInterface::class));
    }

    public function testContextGainsTwoTrailingOptionalInputsSerialisedOnlyWhenPresent(): void
    {
        $parameters = (new \ReflectionClass(RateLimitContextDTO::class))->getConstructor()?->getParameters() ?? [];
        $names = array_map(static fn(\ReflectionParameter $p): string => $p->name, $parameters);

        self::assertSame(['clientInstanceId', 'admissionCredential'], array_slice($names, -2));
        self::assertSame(
            ['ip', 'ua', 'accountId', 'clientFingerprint', 'sessionDeviceId', 'isSessionTrusted', 'headers', 'isDevicePreviouslyVerifiedForAccount', 'correlationId'],
            array_slice($names, 0, 9),
        );
        foreach (array_slice($parameters, 9) as $parameter) {
            self::assertTrue($parameter->isDefaultValueAvailable());
            self::assertNull($parameter->getDefaultValue());
        }

        $plain = (new RateLimitContextDTO('198.51.100.1', 'ua'))->jsonSerialize();
        self::assertIsArray($plain);
        self::assertSame(
            ['ip', 'ua', 'accountId', 'clientFingerprint', 'sessionDeviceId', 'isSessionTrusted', 'headers', 'isDevicePreviouslyVerifiedForAccount', 'correlationId'],
            array_keys($plain),
        );

        $withInputs = (new RateLimitContextDTO('198.51.100.1', 'ua', clientInstanceId: 'inst', admissionCredential: 'cred'))->jsonSerialize();
        self::assertIsArray($withInputs);
        self::assertSame('inst', $withInputs['clientInstanceId']);
        self::assertSame('cred', $withInputs['admissionCredential']);
    }

    public function testDeviceIdentityGainsTwoTrailingOptionalComponentsSerialisedOnlyWhenPresent(): void
    {
        $names = array_map(
            static fn(\ReflectionParameter $p): string => $p->name,
            (new \ReflectionClass(DeviceIdentityDTO::class))->getConstructor()?->getParameters() ?? [],
        );
        self::assertSame(['deviceIdHash', 'previousDeviceIdHash'], array_slice($names, -2));

        $plain = (new DeviceIdentityDTO('h', 'LOW', false))->jsonSerialize();
        self::assertIsArray($plain);
        self::assertSame(
            ['fingerprintHash', 'confidence', 'isTrustedSession', 'churnDetected', 'normalizedUa', 'isDevicePreviouslyVerifiedForAccount', 'previousFingerprintHash'],
            array_keys($plain),
        );
        $with = (new DeviceIdentityDTO('h', 'LOW', false, deviceIdHash: 'a', previousDeviceIdHash: 'b'))->jsonSerialize();
        self::assertIsArray($with);
        self::assertSame(['a', 'b'], [$with['deviceIdHash'], $with['previousDeviceIdHash']]);
    }

    public function testResolverProducesTheIdComponentWithBothGenerationsAndLeavesTheFingerprintUntouched(): void
    {
        $resolver = new DeviceIdentityResolver(new FingerprintHasher('current'), new FingerprintHasher('previous'));
        $without = $resolver->resolve(new RateLimitContextDTO('198.51.100.1', 'Chrome/120', 'acct'));
        $with = $resolver->resolve(new RateLimitContextDTO('198.51.100.1', 'Chrome/120', 'acct', clientInstanceId: 'inst-1'));

        self::assertNull($without->deviceIdHash);
        self::assertNull($without->previousDeviceIdHash);
        self::assertSame(hash_hmac('sha256', 'id1|inst-1', 'current'), $with->deviceIdHash);
        self::assertSame(hash_hmac('sha256', 'id1|inst-1', 'previous'), $with->previousDeviceIdHash);
        self::assertSame($without->fingerprintHash, $with->fingerprintHash);
        self::assertSame($without->previousFingerprintHash, $with->previousFingerprintHash);
        self::assertSame($without->confidence, $with->confidence);
        self::assertSame($without->isTrustedSession, $with->isTrustedSession);
    }

    public function testResolverWithoutAPreviousHasherProducesNoPreviousIdComponent(): void
    {
        $device = (new DeviceIdentityResolver(new FingerprintHasher('current')))
            ->resolve(new RateLimitContextDTO('198.51.100.1', 'ua', clientInstanceId: 'inst-1'));

        self::assertNotNull($device->deviceIdHash);
        self::assertNull($device->previousDeviceIdHash);
    }

    public function testBlankInstanceIdIsTreatedAsAbsent(): void
    {
        $device = (new DeviceIdentityResolver(new FingerprintHasher('current')))
            ->resolve(new RateLimitContextDTO('198.51.100.1', 'ua', clientInstanceId: ''));

        self::assertNull($device->deviceIdHash);
    }

    public function testInstanceIdDoesNotChangeTrustOrConfidence(): void
    {
        $device = (new DeviceIdentityResolver(new FingerprintHasher('current')))
            ->resolve(new RateLimitContextDTO('198.51.100.1', 'ua', clientInstanceId: 'inst-1', isSessionTrusted: true));

        self::assertFalse($device->isTrustedSession);
        self::assertSame('LOW', $device->confidence);
    }

    public function testCommandGainsAnOptionalTrailingPermitOnOutcomeCommandsOnly(): void
    {
        $names = array_map(
            static fn(\ReflectionParameter $p): string => $p->name,
            (new \ReflectionClass(RateLimitCommand::class))->getConstructor()?->getParameters() ?? [],
        );
        self::assertSame(['policyName', 'cost', 'isPreCheck', 'isFailure', 'isSuccess', 'permit'], $names);

        self::assertNull(RateLimitCommand::checkOnly('p')->permit);
        self::assertNull(RateLimitCommand::recordFailure('p')->permit);
        self::assertSame('permit-1', (new RateLimitCommand('p', 1, false, true, false, 'permit-1'))->permit);
        self::assertSame('permit-2', (new RateLimitCommand('p', 1, false, false, true, 'permit-2'))->permit);
    }

    public function testPermitIsRejectedOnACheckOnlyCommandAndWhenBlank(): void
    {
        try {
            new RateLimitCommand('p', 1, true, false, false, 'permit');
            self::fail('A permit on a checkOnly command must be rejected.');
        } catch (RateLimiterException $e) {
            self::assertStringContainsString('permit', $e->getMessage());
        }

        $this->expectException(RateLimiterException::class);
        new RateLimitCommand('p', 1, false, true, false, '  ');
    }

    public function testMetadataGainsAnOptionalTrailingPermitSerialisedOnlyWhenPresent(): void
    {
        $names = array_map(
            static fn(\ReflectionParameter $p): string => $p->name,
            (new \ReflectionClass(RateLimitMetadataDTO::class))->getConstructor()?->getParameters() ?? [],
        );
        self::assertSame(['signal', 'cause', 'context', 'postPunishmentReentry', 'permit'], $names);

        $plain = (new RateLimitMetadataDTO('s', 'c'))->jsonSerialize();
        self::assertIsArray($plain);
        self::assertSame(['signal', 'cause', 'context'], array_keys($plain));

        $with = (new RateLimitMetadataDTO('s', 'c', permit: 'permit-1'))->jsonSerialize();
        self::assertIsArray($with);
        self::assertSame('permit-1', $with['permit']);
    }

    public function testConfigGainsTwoTrailingOptionalBindingKeys(): void
    {
        $names = array_map(
            static fn(\ReflectionParameter $p): string => $p->name,
            (new \ReflectionClass(RateLimiterConfig::class))->getConstructor()?->getParameters() ?? [],
        );
        self::assertSame(['bindingKeySecret', 'previousBindingKeySecret'], array_slice($names, -2));

        $plain = new RateLimiterConfig('k', 'f', 'prod');
        self::assertNull($plain->bindingKeySecret());
        self::assertNull($plain->previousBindingKeySecret());

        $with = new RateLimiterConfig('k', 'f', 'prod', bindingKeySecret: 'b', previousBindingKeySecret: 'p');
        self::assertSame('b', $with->bindingKeySecret());
        self::assertSame('p', $with->previousBindingKeySecret());
    }

    public function testBlankOrOrphanBindingKeysAreRejected(): void
    {
        foreach ([['  ', null], ['b', ' '], [null, 'p']] as [$current, $previous]) {
            try {
                new RateLimiterConfig('k', 'f', 'prod', bindingKeySecret: $current, previousBindingKeySecret: $previous);
                self::fail('Invalid binding key configuration must be rejected.');
            } catch (RateLimiterException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @param class-string $type
     * @return list<string>
     */
    private static function methodNames(string $type): array
    {
        $names = array_map(static fn(\ReflectionMethod $m): string => $m->name, (new \ReflectionClass($type))->getMethods());
        sort($names);

        return $names;
    }

    /**
     * @param class-string $type
     * @return list<string>
     */
    private static function declaredMethodNames(string $type): array
    {
        $names = array_values(array_map(
            static fn(\ReflectionMethod $m): string => $m->name,
            array_filter(
                (new \ReflectionClass($type))->getMethods(),
                static fn(\ReflectionMethod $m): bool => $m->getDeclaringClass()->name === $type,
            ),
        ));
        sort($names);

        return $names;
    }
}
