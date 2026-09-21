<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Device;

use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use PHPUnit\Framework\TestCase;

final class DeviceIdentityResolverCurrentRuntimeCharacterizationTest extends TestCase
{
    private DeviceIdentityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new DeviceIdentityResolver(new FingerprintHasher('test_secret'));
    }

    public function testMissingSessionIdentifierDoesNotResolveTrustedSession(): void
    {
        $device = $this->resolver->resolve(new RateLimitContextDTO(
            '198.51.100.18',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'trusted-without-session-id',
            null,
            null,
            true,
        ));

        $this->assertFalse($device->isTrustedSession);
        $this->assertSame('LOW', $device->confidence);
        $this->assertNotNull($device->fingerprintHash);
    }

    public function testCurrentCharacterizationSessionIdentifierWithoutClientFingerprintRaisesTrustedConfidence(): void
    {
        $device = $this->resolver->resolve(new RateLimitContextDTO(
            '198.51.100.19',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'trusted-with-session-id',
            null,
            'session-device-2',
            true,
        ));

        $this->assertTrue($device->isTrustedSession);
        $this->assertSame('HIGH', $device->confidence);
        $this->assertNotNull($device->fingerprintHash);
    }

    public function testSessionIdentifierDoesNotResolveTrustedSessionWhenContextIsUntrusted(): void
    {
        $device = $this->resolver->resolve(new RateLimitContextDTO(
            '198.51.100.20',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'untrusted-with-session-id',
            null,
            'session-device-3',
            false,
        ));

        $this->assertFalse($device->isTrustedSession);
        $this->assertSame('LOW', $device->confidence);
        $this->assertNotNull($device->fingerprintHash);
    }

    public function testPreviouslyVerifiedSignalDefaultsToFalseInResolvedIdentity(): void
    {
        $device = $this->resolver->resolve(new RateLimitContextDTO(
            '198.51.100.21',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'no-signal-account',
        ));

        $this->assertFalse($device->isDevicePreviouslyVerifiedForAccount);
        $this->assertFalse($device->isTrustedSession);
    }

    public function testPreviouslyVerifiedSignalPropagatesWithoutTrustedSession(): void
    {
        $device = $this->resolver->resolve(new RateLimitContextDTO(
            '198.51.100.22',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'verified-account',
            null,
            null,
            false,
            [],
            true,
        ));

        $this->assertTrue($device->isDevicePreviouslyVerifiedForAccount);
        $this->assertFalse($device->isTrustedSession);
        $this->assertSame('LOW', $device->confidence);
    }

    public function testPreviouslyVerifiedSignalDoesNotReplaceTrustedSessionPrerequisite(): void
    {
        $device = $this->resolver->resolve(new RateLimitContextDTO(
            '198.51.100.23',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'verified-account',
            null,
            null,
            true,
            [],
            true,
        ));

        $this->assertFalse($device->isTrustedSession);
        $this->assertTrue($device->isDevicePreviouslyVerifiedForAccount);
    }

    public function testDualHasherResolvesBothFingerprintsFromSameNormalizedIdentity(): void
    {
        $resolver = new DeviceIdentityResolver(
            new FingerprintHasher('new-secret'),
            new FingerprintHasher('old-secret'),
        );

        $context = new RateLimitContextDTO(
            '198.51.100.30',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118.0.0.0 Safari/537.36',
            'acc-dual-hasher',
            ['zebra' => 'z1', 'alpha' => 'a1', 'mango' => 'm1'],
            'sess-dev-7',
            true,
        );

        $normalizedRawIdentity = "v1|chrome/118|{\"alpha\":\"a1\",\"mango\":\"m1\",\"zebra\":\"z1\"}|sess-dev-7";

        $device = $resolver->resolve($context);

        $this->assertSame(hash_hmac('sha256', $normalizedRawIdentity, 'new-secret'), $device->fingerprintHash);
        $this->assertSame(hash_hmac('sha256', $normalizedRawIdentity, 'old-secret'), $device->previousFingerprintHash);
        $this->assertNotSame($device->fingerprintHash, $device->previousFingerprintHash);
    }

    public function testSingleHasherResolverLeavesPreviousFingerprintHashNull(): void
    {
        $device = $this->resolver->resolve(new RateLimitContextDTO(
            '198.51.100.31',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'single-hasher',
        ));

        $this->assertNull($device->previousFingerprintHash);
        $this->assertNotNull($device->fingerprintHash);
    }

    public function testDualHasherDoesNotChangeIdentitySemantics(): void
    {
        $context = new RateLimitContextDTO(
            '198.51.100.32',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36',
            'acc-semantics',
            ['b' => 2, 'a' => 1],
            'sess-dev-8',
            true,
            [],
            true,
        );

        $single = (new DeviceIdentityResolver(new FingerprintHasher('new-secret')))->resolve($context);
        $dual = (new DeviceIdentityResolver(
            new FingerprintHasher('new-secret'),
            new FingerprintHasher('old-secret'),
        ))->resolve($context);

        $this->assertSame($single->normalizedUa, $dual->normalizedUa);
        $this->assertSame($single->confidence, $dual->confidence);
        $this->assertSame($single->isTrustedSession, $dual->isTrustedSession);
        $this->assertSame($single->churnDetected, $dual->churnDetected);
        $this->assertSame($single->isDevicePreviouslyVerifiedForAccount, $dual->isDevicePreviouslyVerifiedForAccount);
        $this->assertSame($single->fingerprintHash, $dual->fingerprintHash);
        $this->assertNull($single->previousFingerprintHash);
        $this->assertNotNull($dual->previousFingerprintHash);
        $this->assertSame('HIGH', $dual->confidence);
        $this->assertTrue($dual->isTrustedSession);
        $this->assertTrue($dual->isDevicePreviouslyVerifiedForAccount);
    }

    public function testPreviousFingerprintHashAloneDoesNotRaiseConfidenceOrTrust(): void
    {
        $resolver = new DeviceIdentityResolver(
            new FingerprintHasher('new-secret'),
            new FingerprintHasher('old-secret'),
        );

        $device = $resolver->resolve(new RateLimitContextDTO(
            '198.51.100.33',
            'Mozilla/5.0 Chrome/120.0.0.0',
            'acc-prev-only',
        ));

        $this->assertNotNull($device->previousFingerprintHash);
        $this->assertSame('LOW', $device->confidence);
        $this->assertFalse($device->isTrustedSession);
        $this->assertFalse($device->isDevicePreviouslyVerifiedForAccount);
    }
}
