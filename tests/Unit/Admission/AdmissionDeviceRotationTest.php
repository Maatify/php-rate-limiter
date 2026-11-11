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
use Maatify\RateLimiter\Service\AdmissionFenceControlInterface;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionInMemoryReferenceStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A returning device keeps one admission membership across a supported rotation.
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
    public function testReturningDeviceKeepsOneMembershipAcrossRotation(
        bool $rotateKey,
        bool $rotateFp,
    ): void {
        $clock = new FixedClock('@1767268800');
        $store = new AdmissionInMemoryReferenceStore($clock);
        $emitter = new RecordingFailureSignalEmitter();
        $codec = new AdmissionCredentialCodec(self::BINDING_SECRET);
        $now = $clock->now()->getTimestamp();
        $context = new RateLimitContextDTO(
            ip: '198.51.100.1',
            ua: 'Mozilla/5.0 Chrome/120.0',
            accountId: 'test-account',
            clientFingerprint: ['device' => 'test-device'],
            clientInstanceId: 'test-instance',
            admissionCredential: $codec->encode(new AdmissionCredentialDTO(
                subject: 'test-account',
                budgetGroups: [self::GROUP],
                instance: 'test-instance',
                jti: 'test-credential',
                expiresAt: $now + 3600,
                boundAt: $now,
                epoch: 0,
            )),
        );
        $before = new RateLimiterConfig(
            keySecret: self::KEY_1,
            fingerprintSecret: self::FP_1,
            environmentScope: self::ENV,
            bindingKeySecret: self::BINDING_SECRET,
        );
        $after = new RateLimiterConfig(
            keySecret: $rotateKey ? self::KEY_2 : self::KEY_1,
            fingerprintSecret: $rotateFp ? self::FP_2 : self::FP_1,
            environmentScope: self::ENV,
            previousKeySecret: $rotateKey ? self::KEY_1 : null,
            previousFingerprintSecret: $rotateFp ? self::FP_1 : null,
            bindingKeySecret: self::BINDING_SECRET,
        );

        $memberships = new ReflectionProperty(AdmissionInMemoryReferenceStore::class, 'devices');
        $snapshots = [];
        foreach ([$before, $after] as $config) {
            $runtime = RateLimiterBuilder::fromFullCapabilityStore($config, $store, $emitter)
                ->withClock($clock)
                ->withPolicy(new LoginProtectionAdmissionPolicy())
                ->build();
            self::assertInstanceOf(AdmissionFenceControlInterface::class, $runtime);
            $runtime->openAdmissionFence(self::GROUP);

            $admission = $runtime->limit($context, RateLimitCommand::checkOnly(self::POLICY));
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $admission->decision);
            $permit = $admission->metadata?->permit;
            self::assertNotNull($permit, 'An allowed admission must issue a permit.');
            $outcome = $runtime->limit(
                $context,
                new RateLimitCommand(policyName: self::POLICY, isSuccess: true, permit: $permit),
            );
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $outcome->decision);
            self::assertNull($outcome->metadata?->cause, 'The issued permit must be accepted for the outcome.');

            // Inspect the in-memory fixture to verify membership without exhausting any budget.
            $snapshot = $memberships->getValue($store);
            self::assertIsArray($snapshot);
            self::assertCount(1, $snapshot);
            $deviceSet = array_values($snapshot)[0];
            self::assertIsArray($deviceSet);
            self::assertCount(1, $deviceSet);
            $snapshots[] = $snapshot;
        }

        self::assertSame($snapshots[0], $snapshots[1]);
    }
}
