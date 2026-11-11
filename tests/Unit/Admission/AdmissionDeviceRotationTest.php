<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionAdmissionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\AdmissionCredentialDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Service\AdmissionCredentialCodec;
use Maatify\RateLimiter\Service\AdmissionRuntime;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionInMemoryReferenceStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for F-185-34: device rotation under all 4 secret rotation combinations:
 * - Case 0: No rotation (key unchanged, fingerprint unchanged)
 * - Case 1: Outer key secret rotated alone
 * - Case 2: Fingerprint secret rotated alone
 * - Case 3: Both key secret and fingerprint secret rotated
 *
 * Six distinct devices send requests in Round 1 and Round 2 on the P-lane.
 * With newDevicesPerDay = 10, if devices were double-counted across the rotation boundary,
 * round 2 would attempt to register 6 new devices (reaching 12), exceeding the cap of 10.
 * Under every rotation case, all 12 requests must be ALLOW (no double-counting of devices).
 */
final class AdmissionDeviceRotationTest extends TestCase
{
    private const ENV = 'prod';
    private const GROUP = 'login';
    private const POLICY = 'login_protection';
    private const BINDING_SECRET = 'binding-secret-185';
    private const KEY_1 = 'key-secret-generation-1';
    private const KEY_2 = 'key-secret-generation-2';
    private const FP_1 = 'fp-secret-generation-1';
    private const FP_2 = 'fp-secret-generation-2';

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function rotationCases(): iterable
    {
        yield 'Case 0: no rotation' => [false, false];
        yield 'Case 1: key rotated alone' => [true, false];
        yield 'Case 2: fingerprint rotated alone' => [false, true];
        yield 'Case 3: both key and fingerprint rotated' => [true, true];
    }

    #[DataProvider('rotationCases')]
    public function testAllFourRotationCasesDoNotDoubleCountDevices(
        bool $rotateKey,
        bool $rotateFp,
    ): void {
        $clock = new FixedClock('@1767268800');
        $store = new AdmissionInMemoryReferenceStore($clock);
        $emitter = new RecordingFailureSignalEmitter();

        // Build Round 1 engine
        $config1 = new RateLimiterConfig(
            keySecret: self::KEY_1,
            fingerprintSecret: self::FP_1,
            environmentScope: self::ENV,
            bindingKeySecret: self::BINDING_SECRET,
        );
        $engine1 = RateLimiterBuilder::fromFullCapabilityStore($config1, $store, $emitter)
            ->withClock($clock)
            ->withPolicy(new LoginProtectionAdmissionPolicy())
            ->build();

        // Open the fleet fence for login group
        $store->bumpBindingEpoch(AdmissionRuntime::fenceKey(self::ENV, self::GROUP, self::KEY_1), null);

        $codec = new AdmissionCredentialCodec(self::BINDING_SECRET);
        $devices = ['dev-1', 'dev-2', 'dev-3', 'dev-4', 'dev-5', 'dev-6'];

        // Round 1: 6 distinct devices make P-lane requests
        foreach ($devices as $d) {
            $cred = $codec->encode(new AdmissionCredentialDTO(
                'acct-1',
                [self::GROUP],
                'inst-' . $d,
                'jti-' . $d,
                $clock->now()->getTimestamp() + 86400,
                $clock->now()->getTimestamp(),
                0,
            ));
            $ctx = new RateLimitContextDTO(
                ip: '198.51.100.1',
                ua: 'Mozilla/5.0',
                accountId: 'acct-1',
                clientFingerprint: ['device' => $d],
                clientInstanceId: 'inst-' . $d,
                admissionCredential: $cred,
            );
            $res = $engine1->limit($ctx, new RateLimitCommand(self::POLICY));
            self::assertSame(
                RateLimitResultDTO::DECISION_ALLOW,
                $res->decision,
                "Round 1 device $d must be ALLOW",
            );
        }

        // Build Round 2 engine with rotated secrets
        $keySecret = $rotateKey ? self::KEY_2 : self::KEY_1;
        $prevKeySecret = $rotateKey ? self::KEY_1 : null;
        $fpSecret = $rotateFp ? self::FP_2 : self::FP_1;
        $prevFpSecret = $rotateFp ? self::FP_1 : null;

        $config2 = new RateLimiterConfig(
            keySecret: $keySecret,
            fingerprintSecret: $fpSecret,
            environmentScope: self::ENV,
            previousKeySecret: $prevKeySecret,
            previousFingerprintSecret: $prevFpSecret,
            bindingKeySecret: self::BINDING_SECRET,
        );
        $engine2 = RateLimiterBuilder::fromFullCapabilityStore($config2, $store, $emitter)
            ->withClock($clock)
            ->withPolicy(new LoginProtectionAdmissionPolicy())
            ->build();

        // If key rotated, also ensure the new fence key reflects open fence
        if ($rotateKey) {
            $store->bumpBindingEpoch(AdmissionRuntime::fenceKey(self::ENV, self::GROUP, self::KEY_2), null);
        }

        // Round 2: all 6 devices send requests again
        foreach ($devices as $d) {
            $cred = $codec->encode(new AdmissionCredentialDTO(
                'acct-1',
                [self::GROUP],
                'inst-' . $d,
                'jti-' . $d,
                $clock->now()->getTimestamp() + 86400,
                $clock->now()->getTimestamp(),
                0,
            ));
            $ctx = new RateLimitContextDTO(
                ip: '198.51.100.1',
                ua: 'Mozilla/5.0',
                accountId: 'acct-1',
                clientFingerprint: ['device' => $d],
                clientInstanceId: 'inst-' . $d,
                admissionCredential: $cred,
            );
            $res = $engine2->limit($ctx, new RateLimitCommand(self::POLICY));
            self::assertSame(
                RateLimitResultDTO::DECISION_ALLOW,
                $res->decision,
                "Round 2 device $d under rotation (key=" . ($rotateKey ? 'yes' : 'no') . ", fp=" . ($rotateFp ? 'yes' : 'no') . ") must be ALLOW (not double-counted as new device)",
            );
        }
    }
}
