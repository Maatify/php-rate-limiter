<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Config;

use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Exception\RateLimiterException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RateLimiterConfigTest extends TestCase
{
    #[DataProvider('invalidConfigurationProvider')]
    public function testInvalidConfigurationIsRejected(
        string $keySecret,
        string $fingerprintSecret,
        string $environmentScope,
        ?string $previousKeySecret,
        ?string $previousFingerprintSecret,
    ): void {
        $this->expectException(RateLimiterException::class);

        new RateLimiterConfig(
            $keySecret,
            $fingerprintSecret,
            $environmentScope,
            $previousKeySecret,
            $previousFingerprintSecret,
        );
    }

    /**
     * @return iterable<string, array{string, string, string, ?string, ?string}>
     */
    public static function invalidConfigurationProvider(): iterable
    {
        yield 'empty active key secret' => ['', 'fingerprint', 'prod', null, null];
        yield 'whitespace active key secret' => ['   ', 'fingerprint', 'prod', null, null];
        yield 'empty active fingerprint secret' => ['key', '', 'prod', null, null];
        yield 'whitespace active fingerprint secret' => ['key', "\t\n", 'prod', null, null];
        yield 'empty environment scope' => ['key', 'fingerprint', '', null, null];
        yield 'whitespace environment scope' => ['key', 'fingerprint', '  ', null, null];
        yield 'empty previous key secret' => ['key', 'fingerprint', 'prod', '', null];
        yield 'whitespace previous key secret' => ['key', 'fingerprint', 'prod', "\n  ", null];
        yield 'empty previous fingerprint secret' => ['key', 'fingerprint', 'prod', null, ''];
        yield 'whitespace previous fingerprint secret' => ['key', 'fingerprint', 'prod', null, "\t "];
    }

    public function testIndependentRotationInputsAreAcceptedAndPreserved(): void
    {
        $config = new RateLimiterConfig(
            ' active-key ',
            'active-fingerprint',
            ' production ',
            'previous-key',
            ' previous-fingerprint ',
        );

        self::assertSame(' active-key ', $config->keySecret());
        self::assertSame('active-fingerprint', $config->fingerprintSecret());
        self::assertSame(' production ', $config->environmentScope());
        self::assertSame('previous-key', $config->previousKeySecret());
        self::assertSame(' previous-fingerprint ', $config->previousFingerprintSecret());
    }

    public function testConfigurationIsFinalReadonlyAndSecretsAreSensitiveParameters(): void
    {
        $reflection = new \ReflectionClass(RateLimiterConfig::class);
        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());

        $parameters = $reflection->getConstructor()?->getParameters();
        self::assertNotNull($parameters);
        self::assertCount(9, $parameters);
        self::assertNotEmpty($parameters[0]->getAttributes(\SensitiveParameter::class));
        self::assertNotEmpty($parameters[1]->getAttributes(\SensitiveParameter::class));
        self::assertNotEmpty($parameters[3]->getAttributes(\SensitiveParameter::class));
        self::assertNotEmpty($parameters[4]->getAttributes(\SensitiveParameter::class));
        self::assertNotEmpty($parameters[5]->getAttributes(\SensitiveParameter::class));
        self::assertNotEmpty($parameters[6]->getAttributes(\SensitiveParameter::class));
        self::assertSame([], $parameters[7]->getAttributes(\SensitiveParameter::class));
        self::assertNotEmpty($parameters[8]->getAttributes(\SensitiveParameter::class));
    }

    #[DataProvider('invalidAdmissionConfigurationProvider')]
    public function testInvalidAdmissionConfigurationIsRejected(
        ?string $trustDomain,
        ?string $stateSecret,
    ): void {
        $this->expectException(RateLimiterException::class);

        new RateLimiterConfig(
            'key-secret',
            'fingerprint-secret',
            'prod',
            admissionTrustDomain: $trustDomain,
            admissionStateSecret: $stateSecret,
        );
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function invalidAdmissionConfigurationProvider(): iterable
    {
        $validSecret = str_repeat('s', RateLimiterConfig::MIN_ADMISSION_STATE_SECRET_BYTES);
        yield 'trust domain without state secret' => ['auth.example.test', null];
        yield 'state secret without trust domain' => [null, $validSecret];
        yield 'empty trust domain' => ['', $validSecret];
        yield 'whitespace trust domain' => [" \t\n ", $validSecret];
        yield 'empty state secret' => ['auth.example.test', ''];
        yield 'whitespace state secret' => ['auth.example.test', str_repeat(' ', 32)];
        yield 'short state secret (31 bytes)' => ['auth.example.test', str_repeat('a', 31)];
        yield 'multibyte 31 bytes rejected' => ['auth.example.test', str_repeat('a', 29) . "\xC3\xA9"];
    }

    public function testAdmissionTrustDomainAndStateSecretArePreservedWhenValid(): void
    {
        $stateSecret = str_repeat("\x00x", 16); // 32 bytes including NUL
        $config = new RateLimiterConfig(
            'key-secret',
            'fingerprint-secret',
            'prod',
            bindingKeySecret: 'binding-secret',
            admissionTrustDomain: 'auth.example.test',
            admissionStateSecret: $stateSecret,
        );

        self::assertSame('auth.example.test', $config->admissionTrustDomain());
        self::assertSame($stateSecret, $config->admissionStateSecret());
    }
}
