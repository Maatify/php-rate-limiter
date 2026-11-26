<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\DTO\AdmissionCredentialDTO;
use Maatify\RateLimiter\Enum\AdmissionCredentialVerdictEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\AdmissionCredentialCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Gate LANE, codec part (RUNTIME_IMPLEMENTATION_CONTRACT.md §8.3; ILD §4.2).
 * Any failed check is a verdict, never an exception: the lane resolver treats it as "unproven".
 * Detector of every rejection: the untouched credential verifies as VALID with the same arguments.
 */
final class AdmissionCredentialCodecTest extends TestCase
{
    private const NOW = 1_000_000;
    private const ENV = 'prod';
    private const DOMAIN = 'auth-authority';
    private const STATE_SECRET = 'codec-test-state-secret-32-bytes';

    private function codec(string $bindingKey = 'binding-key', ?string $previousBindingKey = null): AdmissionCredentialCodec
    {
        return new AdmissionCredentialCodec($bindingKey, self::ENV, self::DOMAIN, self::STATE_SECRET, $previousBindingKey);
    }

    public function testUntouchedCredentialIsValidAndCarriesItsClaims(): void
    {
        $codec = $this->codec();
        $result = $codec->verify($codec->encode($this->claims()), 'acct-1', 'acct-1', 'login', 'inst-1', self::NOW, 4);

        self::assertSame(AdmissionCredentialVerdictEnum::VALID, $result->verdict);
        self::assertTrue($result->isValid());
        self::assertNotNull($result->claims);
        self::assertSame('acct-1', $result->claims->account);
        self::assertSame('acct-1', $result->claims->subject);
        self::assertSame('jti-1', $result->claims->jti);
        self::assertSame(['login', 'reauth'], $result->claims->budgetGroups);
        self::assertSame(self::NOW - 10, $result->claims->boundAt);
        self::assertNotNull($result->claims->keyId);
    }

    /** @return iterable<string, array{AdmissionCredentialVerdictEnum, string, string, string, string, int, int}> */
    public static function mismatchProvider(): iterable
    {
        yield 'wrong account' => [AdmissionCredentialVerdictEnum::WRONG_ACCOUNT, 'acct-2', 'acct-1', 'login', 'inst-1', self::NOW, 4];
        yield 'wrong subject' => [AdmissionCredentialVerdictEnum::WRONG_SUBJECT, 'acct-1', 'acct-2', 'login', 'inst-1', self::NOW, 4];
        yield 'wrong budget group' => [AdmissionCredentialVerdictEnum::WRONG_BUDGET_GROUP, 'acct-1', 'acct-1', 'otp', 'inst-1', self::NOW, 4];
        yield 'wrong instance' => [AdmissionCredentialVerdictEnum::WRONG_INSTANCE, 'acct-1', 'acct-1', 'login', 'inst-2', self::NOW, 4];
        yield 'expired at exp' => [AdmissionCredentialVerdictEnum::EXPIRED, 'acct-1', 'acct-1', 'login', 'inst-1', self::NOW + 100, 4];
        yield 'expired after exp' => [AdmissionCredentialVerdictEnum::EXPIRED, 'acct-1', 'acct-1', 'login', 'inst-1', self::NOW + 101, 4];
        yield 'old epoch (the account epoch moved on)' => [AdmissionCredentialVerdictEnum::STALE_EPOCH, 'acct-1', 'acct-1', 'login', 'inst-1', self::NOW, 5];
        yield 'epoch behind the credential' => [AdmissionCredentialVerdictEnum::STALE_EPOCH, 'acct-1', 'acct-1', 'login', 'inst-1', self::NOW, 3];
    }

    #[DataProvider('mismatchProvider')]
    public function testEveryMismatchIsRejectedWithItsOwnVerdict(
        AdmissionCredentialVerdictEnum $expected,
        string $account,
        string $subject,
        string $group,
        string $instance,
        int $now,
        int $epoch,
    ): void {
        $codec = $this->codec();
        $token = $codec->encode($this->claims());

        self::assertSame($expected, $codec->verify($token, $account, $subject, $group, $instance, $now, $epoch)->verdict);
        self::assertNull($codec->verify($token, $account, $subject, $group, $instance, $now, $epoch)->claims);
        // detector: the same token with the matching arguments is valid
        self::assertTrue($codec->verify($token, 'acct-1', 'acct-1', 'login', 'inst-1', self::NOW, 4)->isValid());
    }

    public function testLastValidSecondIsAccepted(): void
    {
        $codec = $this->codec();

        self::assertTrue($codec->verify($codec->encode($this->claims()), 'acct-1', 'acct-1', 'login', 'inst-1', self::NOW + 99, 4)->isValid());
    }

    public function testTamperedPayloadIsRejectedAsInvalidSignature(): void
    {
        $codec = $this->codec();
        $other = $codec->encode($this->claims(account: 'acct-9', subject: 'acct-9'));
        [$otherPayload] = explode('.', $other);
        [, $mac] = explode('.', $codec->encode($this->claims()));

        $forged = $otherPayload . '.' . $mac;

        self::assertSame(
            AdmissionCredentialVerdictEnum::INVALID_SIGNATURE,
            $codec->verify($forged, 'acct-9', 'acct-9', 'login', 'inst-1', self::NOW, 4)->verdict,
        );
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $codec = $this->codec();
        $token = $codec->encode($this->claims());
        $flipped = substr($token, 0, -1) . (str_ends_with($token, 'A') ? 'B' : 'A');

        self::assertSame(
            AdmissionCredentialVerdictEnum::INVALID_SIGNATURE,
            $codec->verify($flipped, 'acct-1', 'acct-1', 'login', 'inst-1', self::NOW, 4)->verdict,
        );
    }

    public function testCredentialSignedWithAnotherKeyIsRejected(): void
    {
        $token = $this->codec('someone-elses-key')->encode($this->claims());

        self::assertSame(
            AdmissionCredentialVerdictEnum::INVALID_SIGNATURE,
            $this->codec('binding-key')->verify($token, 'acct-1', 'acct-1', 'login', 'inst-1', self::NOW, 4)->verdict,
        );
    }

    public function testPreviousBindingKeyStillVerifiesAndTheOneBeforeItDoesNot(): void
    {
        $oldToken = $this->codec('key-1')->encode($this->claims());

        $afterOneRotation = $this->codec('key-2', 'key-1');
        $afterTwoRotations = $this->codec('key-3', 'key-2');

        self::assertTrue($afterOneRotation->verify($oldToken, 'acct-1', 'acct-1', 'login', 'inst-1', self::NOW, 4)->isValid());
        self::assertSame(
            AdmissionCredentialVerdictEnum::INVALID_SIGNATURE,
            $afterTwoRotations->verify($oldToken, 'acct-1', 'acct-1', 'login', 'inst-1', self::NOW, 4)->verdict,
        );
    }

    public function testEncodingAlwaysUsesTheCurrentKeyAndStampsItsKeyId(): void
    {
        $rotated = $this->codec('key-2', 'key-1');
        $token = $rotated->encode($this->claims());

        self::assertTrue($this->codec('key-2')->verify($token, 'acct-1', 'acct-1', 'login', 'inst-1', self::NOW, 4)->isValid());
        self::assertSame(
            AdmissionCredentialVerdictEnum::INVALID_SIGNATURE,
            $this->codec('key-1')->verify($token, 'acct-1', 'acct-1', 'login', 'inst-1', self::NOW, 4)->verdict,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function malformedProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'no separator' => ['abc'];
        yield 'three parts' => ['a.b.c'];
        yield 'not base64url payload' => ['!!!.???'];
        yield 'payload is not json' => [rtrim(strtr(base64_encode('not json'), '+/', '-_'), '=') . '.AAAA'];
        yield 'oversize' => [str_repeat('A', 5000) . '.' . str_repeat('A', 100)];
    }

    #[DataProvider('malformedProvider')]
    public function testMalformedInputIsAVerdictNeverAnException(string $token): void
    {
        $codec = $this->codec();

        self::assertSame(
            AdmissionCredentialVerdictEnum::MALFORMED,
            $codec->verify($token, 'acct-1', 'acct-1', 'login', 'inst-1', self::NOW, 4)->verdict,
        );
    }

    public function testEncodeAcceptsExactMaxLengthAndRejectsClosestOversizeAnd4096AsciiAccount(): void
    {
        $codec = $this->codec();

        // Find the largest ASCII account length whose encoded token fits within MAX_LENGTH (4096).
        // JSON overhead with acct-1 / ['login', 'reauth'] / inst-1 / jti-1 / exp=1000100 / bat=999990 / epo=4 / 16-hex kid
        // leaves exact 4096-byte token at account length 2894 (payload 4052 + '.' + 43-char MAC = 4096).
        $baseClaims = static fn(string $account): AdmissionCredentialDTO => new AdmissionCredentialDTO(
            $account,
            'acct-1',
            ['login', 'reauth'],
            'inst-1',
            'jti-1',
            self::NOW + 100,
            self::NOW - 10,
            4,
        );

        $maxAccountLen = 2894;
        $exactAccount = str_repeat('a', $maxAccountLen);
        $exactClaims = $baseClaims($exactAccount);
        $exactToken = $codec->encode($exactClaims);
        self::assertSame(AdmissionCredentialCodec::MAX_LENGTH, strlen($exactToken));
        self::assertSame(
            AdmissionCredentialVerdictEnum::VALID,
            $codec->verify($exactToken, $exactAccount, 'acct-1', 'login', 'inst-1', self::NOW, 4)->verdict,
        );

        // Closest possible length above the 4096 boundary (+1 byte on account -> 4098 chars) must be rejected at encode().
        // Construct DTO outside try so only encode() is under test, and verify the actual codec bindingKey/stateSecret are never leaked.
        $closestOversizeAccount = str_repeat('a', $maxAccountLen + 1);
        $closestOversizeClaims = $baseClaims($closestOversizeAccount);
        $oversizeRejected = false;
        try {
            $codec->encode($closestOversizeClaims);
        } catch (RateLimiterException $e) {
            $oversizeRejected = true;
            self::assertSame('Encoded admission credential exceeds the maximum length of 4096 characters.', $e->getMessage());
            self::assertStringNotContainsString($closestOversizeAccount, $e->getMessage());
            self::assertStringNotContainsString('binding-key', $e->getMessage());
            self::assertStringNotContainsString(self::STATE_SECRET, $e->getMessage());
        }
        self::assertTrue($oversizeRejected, 'encode() must reject closest length over MAX_LENGTH');

        // F-G4-01 regression: 4096-char ASCII account previously produced a 5699-char token that verify() rejected as MALFORMED.
        $legacy4096Claims = $baseClaims(str_repeat('a', 4096));
        $legacy4096Rejected = false;
        try {
            $codec->encode($legacy4096Claims);
        } catch (RateLimiterException $e) {
            $legacy4096Rejected = true;
            self::assertSame('Encoded admission credential exceeds the maximum length of 4096 characters.', $e->getMessage());
        }
        self::assertTrue($legacy4096Rejected, 'encode() must reject 4096-char ASCII account instead of issuing an unusable 5699-char token');

        // Signed v2 credential built independent of encode() at the closest length over 4096 (4098 bytes)
        // must be rejected as MALFORMED by verify(), isolating the MAX_LENGTH guard from JSON/format errors.
        $keys = new \Maatify\RateLimiter\Service\AdmissionKeyDeriver(self::ENV, self::DOMAIN, self::STATE_SECRET);
        $kid = $keys->bindingKeyId('binding-key');
        $b64Url = static fn(string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $oversizePayload = $b64Url(json_encode([
            'v' => AdmissionCredentialCodec::VERSION,
            'acc' => $closestOversizeAccount,
            'sub' => 'acct-1',
            'grp' => ['login', 'reauth'],
            'ins' => 'inst-1',
            'jti' => 'jti-1',
            'exp' => self::NOW + 100,
            'bat' => self::NOW - 10,
            'epo' => 4,
            'kid' => $kid,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $oversizeSignedToken = $oversizePayload . '.' . $b64Url($keys->credentialMac($oversizePayload, 'binding-key'));
        self::assertSame(4098, strlen($oversizeSignedToken));
        self::assertSame(
            AdmissionCredentialVerdictEnum::MALFORMED,
            $codec->verify($oversizeSignedToken, $closestOversizeAccount, 'acct-1', 'login', 'inst-1', self::NOW, 4)->verdict,
        );
    }

    public function testEncodeThrowsJsonExceptionOnNonUtf8Claims(): void
    {
        $codec = $this->codec();
        $nonUtf8Claims = new AdmissionCredentialDTO(
            "\xFF",
            'acct-1',
            ['login'],
            'inst-1',
            'jti-1',
            self::NOW + 100,
            self::NOW - 10,
            4,
        );

        $this->expectException(\JsonException::class);
        $codec->encode($nonUtf8Claims);
    }

    public function testSignedPayloadWithUnexpectedShapeIsMalformed(): void
    {
        $codec = $this->codec();
        $token = $codec->encode($this->claims());
        $payload = json_decode((string) base64_decode(strtr(explode('.', $token)[0], '-_', '+/'), true), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $payload['extra'] = 1;
        $encoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        // Re-signed through the codec's own primitive is impossible from outside: an unsigned or foreign-signed
        // payload with an unexpected key never reaches claims.
        $result = $codec->verify($encoded . '.' . explode('.', $token)[1], 'acct-1', 'acct-1', 'login', 'inst-1', self::NOW, 4);

        self::assertFalse($result->isValid());
        self::assertNull($result->claims);
    }

    public function testClaimsAreValidatedAtConstruction(): void
    {
        $this->expectException(RateLimiterException::class);

        new AdmissionCredentialDTO('', 'acct-1', ['login'], 'inst-1', 'jti-1', self::NOW + 100, self::NOW, 1);
    }

    public function testClaimsRejectAnEmptyBudgetGroupList(): void
    {
        $this->expectException(RateLimiterException::class);

        new AdmissionCredentialDTO('acct-1', 'acct-1', [], 'inst-1', 'jti-1', self::NOW + 100, self::NOW, 1);
    }

    public function testCodecRejectsABlankKey(): void
    {
        $this->expectException(RateLimiterException::class);

        $this->codec('   ');
    }

    public function testCodecRejectsABlankPreviousKey(): void
    {
        $this->expectException(RateLimiterException::class);

        $this->codec('key', ' ');
    }

    private function claims(string $account = 'acct-1', string $subject = 'acct-1'): AdmissionCredentialDTO
    {
        return new AdmissionCredentialDTO($account, $subject, ['login', 'reauth'], 'inst-1', 'jti-1', self::NOW + 100, self::NOW - 10, 4);
    }
}
