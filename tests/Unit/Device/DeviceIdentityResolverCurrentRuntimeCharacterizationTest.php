<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Device;

use Maatify\RateLimiter\Device\DeviceIdentityResolver;
use Maatify\RateLimiter\Device\FingerprintHasher;
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

    public function testCurrentCharacterizationTrustedSessionFlagSurvivesMissingSessionIdentifier(): void
    {
        $device = $this->resolver->resolve(new RateLimitContextDTO(
            '198.51.100.18',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'trusted-without-session-id',
            null,
            null,
            true
        ));

        $this->assertTrue($device->isTrustedSession);
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
            true
        ));

        $this->assertTrue($device->isTrustedSession);
        $this->assertSame('HIGH', $device->confidence);
        $this->assertNotNull($device->fingerprintHash);
    }
}
