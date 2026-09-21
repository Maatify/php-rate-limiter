<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Device;

use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use PHPUnit\Framework\TestCase;

final class DeviceIdentityResolverContractTest extends TestCase
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

    public function testTrustedSessionWithoutClientFingerprintHasHighConfidence(): void
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

    public function testClientFingerprintRaisesConfidenceToMedium(): void
    {
        $device = $this->resolver->resolve(new RateLimitContextDTO(
            '198.51.100.24',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'client-assisted',
            ['timezone' => 'UTC', 'screen' => 'wide'],
        ));

        $this->assertSame('MEDIUM', $device->confidence);
        $this->assertFalse($device->isTrustedSession);
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

        $normalizedRawIdentity = "v2|chrome/118|{\"alpha\":\"a1\",\"mango\":\"m1\",\"zebra\":\"z1\"}|sess-dev-7";

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

    public function testEmptyClientFingerprintDoesNotRaiseConfidence(): void
    {
        $device = $this->resolver->resolve(new RateLimitContextDTO(
            '198.51.100.34',
            'Mozilla/5.0 Chrome/121.0.0.0',
            'empty-client',
            [],
        ));

        $this->assertSame('LOW', $device->confidence);
    }

    public function testNestedAssociativeMapKeyOrderDoesNotChangeFingerprint(): void
    {
        $firstClientFingerprint = [
            'outer' => ['z' => 1, 'a' => ['second' => true, 'first' => null]],
            'list' => ['first', 'second'],
        ];
        $secondClientFingerprint = [
            'list' => ['first', 'second'],
            'outer' => ['a' => ['first' => null, 'second' => true], 'z' => 1],
        ];
        $first = new RateLimitContextDTO(
            '198.51.100.35',
            'Mozilla/5.0 Chrome/122.0.0.0',
            'nested-map',
            $firstClientFingerprint,
        );
        $second = new RateLimitContextDTO(
            '198.51.100.35',
            'Mozilla/5.0 Chrome/122.0.0.0',
            'nested-map',
            $secondClientFingerprint,
        );

        $this->assertSame(
            $this->resolver->resolve($first)->fingerprintHash,
            $this->resolver->resolve($second)->fingerprintHash,
        );
    }

    public function testListOrderRemainsMeaningful(): void
    {
        $first = new RateLimitContextDTO(
            '198.51.100.36',
            'Mozilla/5.0 Chrome/122.0.0.0',
            'ordered-list',
            ['signals' => ['first', 'second']],
        );
        $second = new RateLimitContextDTO(
            '198.51.100.36',
            'Mozilla/5.0 Chrome/122.0.0.0',
            'ordered-list',
            ['signals' => ['second', 'first']],
        );

        $this->assertNotSame(
            $this->resolver->resolve($first)->fingerprintHash,
            $this->resolver->resolve($second)->fingerprintHash,
        );
    }

    public function testInvalidClientFingerprintPayloadFailsExplicitly(): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Client fingerprint payload could not be serialized.');

        $this->resolver->resolve(new RateLimitContextDTO(
            '198.51.100.37',
            'Mozilla/5.0 Chrome/122.0.0.0',
            'invalid-client',
            ['callback' => static fn(): string => 'not serializable'],
        ));
    }

    public function testHeadersDoNotChangeDefaultResolverFingerprint(): void
    {
        $first = new RateLimitContextDTO(
            '198.51.100.38',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'headers-ignored',
            ['hint' => 'bucketed'],
            'session-device-headers',
            true,
            ['Accept-Language' => 'en-US', 'X-Platform' => 'Windows'],
        );
        $second = new RateLimitContextDTO(
            '198.51.100.38',
            'Mozilla/5.0 Chrome/123.0.0.0',
            'headers-ignored',
            ['hint' => 'bucketed'],
            'session-device-headers',
            true,
            ['Accept-Language' => 'ar-EG', 'X-Platform' => 'Linux'],
        );

        $this->assertSame(
            $this->resolver->resolve($first)->fingerprintHash,
            $this->resolver->resolve($second)->fingerprintHash,
        );
    }

    /**
     * @dataProvider userAgentNormalizationProvider
     */
    public function testUserAgentNormalizationUsesBoundedBrowserMajorContract(string $ua, string $expected): void
    {
        $this->assertSame($expected, DeviceIdentityResolver::normalizeUserAgent($ua));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function userAgentNormalizationProvider(): iterable
    {
        yield 'opera before chrome' => [
            'Mozilla/5.0 Chrome/123.0.0.0 OPR/99.0.0.0 Safari/537.36',
            'opera/99',
        ];
        yield 'modern edge' => ['Mozilla/5.0 Chrome/123.0.0.0 Edg/123.0.0.0', 'edge/123'];
        yield 'android edge' => ['Mozilla/5.0 Chrome/123.0.0.0 EdgA/123.0.0.0', 'edge/123'];
        yield 'ios edge' => ['Mozilla/5.0 CriOS/123.0.0.0 EdgiOS/123.0.0.0', 'edge/123'];
        yield 'legacy edge' => ['Mozilla/5.0 Edge/18.19041', 'edge/18'];
        yield 'firefox' => ['Mozilla/5.0 Firefox/124.0', 'firefox/124'];
        yield 'ios firefox' => ['Mozilla/5.0 FxiOS/124.0', 'firefox/124'];
        yield 'chrome' => ['Mozilla/5.0 Chrome/123.0.0.0 Safari/537.36', 'chrome/123'];
        yield 'ios chrome' => ['Mozilla/5.0 CriOS/123.0.0.0 Mobile/15E148', 'chrome/123'];
        yield 'safari browser major' => [
            'Mozilla/5.0 Version/17.4.1 Mobile/15E148 Safari/604.1',
            'safari/17',
        ];
        yield 'safari build is not browser major' => ['Mozilla/5.0 Safari/605.1.15', 'other/0'];
        yield 'unknown bounded fallback' => ['custom-client raw high entropy value', 'other/0'];
    }
}
