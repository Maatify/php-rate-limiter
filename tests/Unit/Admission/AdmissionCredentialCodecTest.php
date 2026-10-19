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

    public function testUntouchedCredentialIsValidAndCarriesItsClaims(): void
    {
        $codec = new AdmissionCredentialCodec('binding-key');
        $result = $codec->verify($codec->encode($this->claims()), 'acct-1', 'login', 'inst-1', self::NOW, 4);

        self::assertSame(AdmissionCredentialVerdictEnum::VALID, $result->verdict);
        self::assertTrue($result->isValid());
        self::assertNotNull($result->claims);
        self::assertSame('jti-1', $result->claims->jti);
        self::assertSame(['login', 'reauth'], $result->claims->budgetGroups);
        self::assertSame(self::NOW - 10, $result->claims->boundAt);
        self::assertNotNull($result->claims->keyId);
    }

    /** @return iterable<string, array{AdmissionCredentialVerdictEnum, string, string, string, int, int}> */
    public static function mismatchProvider(): iterable
    {
        yield 'wrong subject' => [AdmissionCredentialVerdictEnum::WRONG_SUBJECT, 'acct-2', 'login', 'inst-1', self::NOW, 4];
        yield 'wrong budget group' => [AdmissionCredentialVerdictEnum::WRONG_BUDGET_GROUP, 'acct-1', 'otp', 'inst-1', self::NOW, 4];
        yield 'wrong instance' => [AdmissionCredentialVerdictEnum::WRONG_INSTANCE, 'acct-1', 'login', 'inst-2', self::NOW, 4];
        yield 'expired at exp' => [AdmissionCredentialVerdictEnum::EXPIRED, 'acct-1', 'login', 'inst-1', self::NOW + 100, 4];
        yield 'expired after exp' => [AdmissionCredentialVerdictEnum::EXPIRED, 'acct-1', 'login', 'inst-1', self::NOW + 101, 4];
        yield 'old epoch (the account epoch moved on)' => [AdmissionCredentialVerdictEnum::STALE_EPOCH, 'acct-1', 'login', 'inst-1', self::NOW, 5];
        yield 'epoch behind the credential' => [AdmissionCredentialVerdictEnum::STALE_EPOCH, 'acct-1', 'login', 'inst-1', self::NOW, 3];
    }

    #[DataProvider('mismatchProvider')]
    public function testEveryMismatchIsRejectedWithItsOwnVerdict(
        AdmissionCredentialVerdictEnum $expected,
        string $subject,
        string $group,
        string $instance,
        int $now,
        int $epoch,
    ): void {
        $codec = new AdmissionCredentialCodec('binding-key');
        $token = $codec->encode($this->claims());

        self::assertSame($expected, $codec->verify($token, $subject, $group, $instance, $now, $epoch)->verdict);
        self::assertNull($codec->verify($token, $subject, $group, $instance, $now, $epoch)->claims);
        // detector: the same token with the matching arguments is valid
        self::assertTrue($codec->verify($token, 'acct-1', 'login', 'inst-1', self::NOW, 4)->isValid());
    }

    public function testLastValidSecondIsAccepted(): void
    {
        $codec = new AdmissionCredentialCodec('binding-key');

        self::assertTrue($codec->verify($codec->encode($this->claims()), 'acct-1', 'login', 'inst-1', self::NOW + 99, 4)->isValid());
    }

    public function testTamperedPayloadIsRejectedAsInvalidSignature(): void
    {
        $codec = new AdmissionCredentialCodec('binding-key');
        $other = $codec->encode($this->claims(subject: 'acct-9'));
        [$otherPayload] = explode('.', $other);
        [, $mac] = explode('.', $codec->encode($this->claims()));

        $forged = $otherPayload . '.' . $mac;

        self::assertSame(
            AdmissionCredentialVerdictEnum::INVALID_SIGNATURE,
            $codec->verify($forged, 'acct-9', 'login', 'inst-1', self::NOW, 4)->verdict,
        );
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $codec = new AdmissionCredentialCodec('binding-key');
        $token = $codec->encode($this->claims());
        $flipped = substr($token, 0, -1) . (str_ends_with($token, 'A') ? 'B' : 'A');

        self::assertSame(
            AdmissionCredentialVerdictEnum::INVALID_SIGNATURE,
            $codec->verify($flipped, 'acct-1', 'login', 'inst-1', self::NOW, 4)->verdict,
        );
    }

    public function testCredentialSignedWithAnotherKeyIsRejected(): void
    {
        $token = (new AdmissionCredentialCodec('someone-elses-key'))->encode($this->claims());

        self::assertSame(
            AdmissionCredentialVerdictEnum::INVALID_SIGNATURE,
            (new AdmissionCredentialCodec('binding-key'))->verify($token, 'acct-1', 'login', 'inst-1', self::NOW, 4)->verdict,
        );
    }

    public function testPreviousBindingKeyStillVerifiesAndTheOneBeforeItDoesNot(): void
    {
        $oldToken = (new AdmissionCredentialCodec('key-1'))->encode($this->claims());

        $afterOneRotation = new AdmissionCredentialCodec('key-2', 'key-1');
        $afterTwoRotations = new AdmissionCredentialCodec('key-3', 'key-2');

        self::assertTrue($afterOneRotation->verify($oldToken, 'acct-1', 'login', 'inst-1', self::NOW, 4)->isValid());
        self::assertSame(
            AdmissionCredentialVerdictEnum::INVALID_SIGNATURE,
            $afterTwoRotations->verify($oldToken, 'acct-1', 'login', 'inst-1', self::NOW, 4)->verdict,
        );
    }

    public function testEncodingAlwaysUsesTheCurrentKeyAndStampsItsKeyId(): void
    {
        $rotated = new AdmissionCredentialCodec('key-2', 'key-1');
        $token = $rotated->encode($this->claims());

        self::assertTrue((new AdmissionCredentialCodec('key-2'))->verify($token, 'acct-1', 'login', 'inst-1', self::NOW, 4)->isValid());
        self::assertSame(
            AdmissionCredentialVerdictEnum::INVALID_SIGNATURE,
            (new AdmissionCredentialCodec('key-1'))->verify($token, 'acct-1', 'login', 'inst-1', self::NOW, 4)->verdict,
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
        $codec = new AdmissionCredentialCodec('binding-key');

        self::assertSame(
            AdmissionCredentialVerdictEnum::MALFORMED,
            $codec->verify($token, 'acct-1', 'login', 'inst-1', self::NOW, 4)->verdict,
        );
    }

    public function testSignedPayloadWithUnexpectedShapeIsMalformed(): void
    {
        $codec = new AdmissionCredentialCodec('binding-key');
        $token = $codec->encode($this->claims());
        $payload = json_decode((string) base64_decode(strtr(explode('.', $token)[0], '-_', '+/'), true), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $payload['extra'] = 1;
        $encoded = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        // Re-signed through the codec's own primitive is impossible from outside: an unsigned or foreign-signed
        // payload with an unexpected key never reaches claims.
        $result = $codec->verify($encoded . '.' . explode('.', $token)[1], 'acct-1', 'login', 'inst-1', self::NOW, 4);

        self::assertFalse($result->isValid());
        self::assertNull($result->claims);
    }

    public function testClaimsAreValidatedAtConstruction(): void
    {
        $this->expectException(RateLimiterException::class);

        new AdmissionCredentialDTO('', ['login'], 'inst-1', 'jti-1', self::NOW + 100, self::NOW, 1);
    }

    public function testClaimsRejectAnEmptyBudgetGroupList(): void
    {
        $this->expectException(RateLimiterException::class);

        new AdmissionCredentialDTO('acct-1', [], 'inst-1', 'jti-1', self::NOW + 100, self::NOW, 1);
    }

    public function testCodecRejectsABlankKey(): void
    {
        $this->expectException(RateLimiterException::class);

        new AdmissionCredentialCodec('   ');
    }

    public function testCodecRejectsABlankPreviousKey(): void
    {
        $this->expectException(RateLimiterException::class);

        new AdmissionCredentialCodec('key', ' ');
    }

    private function claims(string $subject = 'acct-1'): AdmissionCredentialDTO
    {
        return new AdmissionCredentialDTO($subject, ['login', 'reauth'], 'inst-1', 'jti-1', self::NOW + 100, self::NOW - 10, 4);
    }
}
