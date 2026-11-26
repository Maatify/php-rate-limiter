<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\AdmissionReservationPolicyInterface;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\LoginProtectionAdmissionPolicy;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\AdmissionCredentialDTO;
use Maatify\RateLimiter\DTO\AdmissionPermitDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Enum\AdmissionCredentialVerdictEnum;
use Maatify\RateLimiter\Enum\AdmissionHostModeEnum;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\AdmissionBindingControlInterface;
use Maatify\RateLimiter\Service\AdmissionBindingEpochInterface;
use Maatify\RateLimiter\Service\AdmissionCredentialCodec;
use Maatify\RateLimiter\Service\AdmissionFenceControlInterface;
use Maatify\RateLimiter\Service\AdmissionKeyDeriver;
use Maatify\RateLimiter\Service\AdmissionRuntime;
use Maatify\RateLimiter\Service\AdmissionScopePurpose;
use Maatify\RateLimiter\Service\E1AccountScopeKeyDeriver;
use Maatify\RateLimiter\Service\KeyPreimageFraming;
use Maatify\RateLimiter\Service\NonAccountScopeKeyDeriver;
use Maatify\RateLimiter\Service\NonAccountScopePurpose;
use Maatify\RateLimiter\Service\SimpleFixedWindowKeyDeriver;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionInMemoryReferenceStore;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;
use Maatify\RateLimiter\Tests\Support\Admission\OptedLoginPolicy;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * G3 (#284, DEC-048, DEC-049, DEC-050): canonical Admission `a1` key framing, closed 12-purpose registry,
 * explicit trust domain + durable state secret (`admissionStateSecret`), v2 credential/permit context binding,
 * independent synthetic vector oracle, red/green detectors for A1–A3 and direct-store wrapper framing,
 * public usability / fail-fast configuration boundaries, and D7 closed-fence settlement.
 */
final class AdmissionG3HardeningTest extends TestCase
{
    private const ENV = 'prod';
    private const TRUST_DOMAIN = 'auth.example.test';
    private const STATE_SECRET = 'state-secret-vector-0123456789abcdef';
    private const OUTER_SECRET = 'outer-secret-vector';
    private const BINDING_SECRET = 'binding-secret-vector';
    private const T0 = 1_767_225_600;

    // ------------------------------------------------------------------
    // 1. Independent Oracle & DEC-048 Synthetic Vectors (all 12 purposes)
    // ------------------------------------------------------------------

    #[Group('php32')]
    public function testIndependentOracleAndSyntheticVectorsMatchAllAdmissionFamilies(): void
    {
        $deriver = new AdmissionKeyDeriver(self::ENV, self::TRUST_DOMAIN, self::STATE_SECRET);

        $expectedCohort = 'fca526f950d23c289a2b1aafde35bed1da3b86727fa6055d840bcd50ec1b58e0';
        self::assertSame($expectedCohort, $deriver->cohort());
        self::assertSame(
            self::oracleHmacHex(self::STATE_SECRET, AdmissionKeyDeriver::KEY_DOMAIN_COHORT, AdmissionKeyDeriver::MAGIC_COHORT, ['a1', self::ENV, self::TRUST_DOMAIN]),
            $deriver->cohort(),
        );

        self::assertSame(
            'a1.fence.988d60a1692fbcd94001cc6d5a8688bfacfb569886511c468bb24b77409132b8',
            $deriver->fenceKey('login', self::OUTER_SECRET),
        );
        self::assertSame(
            'a1.epoch.beaef2f189b290a8366c3e9d1ecb64fb15301cb2397f1aa18db89b4734bd5fc6',
            $deriver->epochKey('acct-42'),
        );
        self::assertSame(
            'a1.pcfg.9c3bb9e31ad86dd88e8e08fce17f0ab3654bc2528d679e3fb9196b28a13286e9',
            $deriver->configKey('login'),
        );

        $expectedScopes = [
            AdmissionScopePurpose::SRC => ['198.51.100.10', '', 'a1.scope.src.690e990f4b80cff0eae2ec81fb7a412cef991082e18f0e9de86a23eea6482870'],
            AdmissionScopePurpose::ACCT => ['acct-42', '', 'a1.scope.acct.9492185e98f35bb2ddac074425a7796343930dae346904a0ac387786f9a937f9'],
            AdmissionScopePurpose::UFAIL => ['198.51.100.10', '', 'a1.scope.ufail.76ca6f5160fff1f4feb9cc881a18966f03eeb046fe62f400e5d408162e18ff65'],
            AdmissionScopePurpose::PACCT => ['acct-42', '', 'a1.scope.pacct.3b8d419be8fd6528e49bb6a9d7cff7f48a59220dc603fc4101fa15f3eb3ec721'],
            AdmissionScopePurpose::PACCTD => ['acct-42', '', 'a1.scope.pacctd.b61e064a14f0584b766dd413a51b5a0e92a278ce430554184828cc2007938539'],
            AdmissionScopePurpose::PPROOF => ['proof-ref-1', '', 'a1.scope.pproof.7a0d71e7a7fe96fc654dc3cf81dde69ea7f76f27db4ad573e825ee2a1ba11cc0'],
            AdmissionScopePurpose::PPROOFD => ['proof-ref-1', '', 'a1.scope.pproofd.e5d7eaf946614b2debf7e8aa5b196b4c11d251f94f3a0311924510ad91cf056c'],
            AdmissionScopePurpose::PFAIL => ['198.51.100.10', '', 'a1.scope.pfail.1350418c2427331d8a239779463da31c7fcc35494e8312e63e425317294af6c8'],
            AdmissionScopePurpose::PPEND => ['198.51.100.10', '', 'a1.scope.ppend.a11a85a65178a66df37aabcb1c4a7a0d92901945f17a6219a078f218ee3a8a4f'],
            AdmissionScopePurpose::PDEV => ['acct-42', 'set', 'a1.scope.pdev.2bfd07ca989fe93905a7e6317b96d5821e3bfc4dd95a16775c1a4f1b5403e520'],
            AdmissionScopePurpose::MEMBER => ['acct-42', '', 'a1.scope.member.99b91aa598f462c239ae5cd3f414584ff0db5df4e1f5db8cdfe0e4f7a8991cc4'],
            AdmissionScopePurpose::DEV => ['fp-hash-1', '', 'a1.scope.dev.8500fa836f408900035fb1c67efb41152fb37d685946b2af3fe1b8b6bdad0e4d'],
        ];
        self::assertCount(12, AdmissionScopePurpose::all());
        self::assertSame(array_keys(AdmissionScopePurpose::all()), array_keys($expectedScopes));

        foreach ($expectedScopes as $purpose => [$value, $label, $expectedVector]) {
            $actual = $deriver->scopeKey('login', $purpose, $value, $label, self::OUTER_SECRET);
            self::assertSame($expectedVector, $actual, "Synthetic vector for purpose {$purpose}");
            self::assertSame(
                'a1.scope.' . $purpose . '.' . self::oracleHmacHex(
                    self::OUTER_SECRET,
                    AdmissionKeyDeriver::KEY_DOMAIN_SCOPE,
                    AdmissionKeyDeriver::MAGIC_SCOPE,
                    [$expectedCohort, self::ENV, self::TRUST_DOMAIN, 'login', $purpose, $value, $label],
                ),
                $actual,
                "Independent oracle for purpose {$purpose}",
            );
        }

        $ctxDto = new RateLimitContextDTO(
            '198.51.100.10',
            'Mozilla/5.0 Chrome/126',
            'acct-42',
            null,
            null,
            false,
            [],
            false,
            'corr-42',
            clientInstanceId: 'inst-42',
            admissionCredential: 'cred-sample',
        );
        $ctx = $deriver->permitContextCommitment('login', 'login_protection', $ctxDto);
        self::assertSame('2f969c88612631042796908c6a216f6e08eb7f82fa8706078f9841461c085fdb', $ctx);
        self::assertSame(
            'a1.permit.dde2a67975699c6fe577645b8cbc2e01b832ed28396cd986f13b58d95e59b081',
            $deriver->permitRecordId('0123456789abcdef0123456789abcdef', $ctx),
        );
        self::assertSame('9e621a9c10ab6154', $deriver->bindingKeyId(self::BINDING_SECRET));
        self::assertSame(
            '19818dafb9c3750b597cb305f6d52bd2a37a1033ea0740e2fe4414079a52c784',
            bin2hex($deriver->credentialMac('payload-part', self::BINDING_SECRET)),
        );
        self::assertSame(
            '253b52e8e267b4fded3635a1498df0f85ffea19a498de7e1d40d86a6a61635ff',
            bin2hex($deriver->permitMac('payload-part', self::BINDING_SECRET)),
        );
    }

    #[Group('php32')]
    public function testClosedPurposeRegistryRejectsUnknownPurposesAndMismatchedLabels(): void
    {
        $deriver = new AdmissionKeyDeriver(self::ENV, self::TRUST_DOMAIN, self::STATE_SECRET);

        foreach ([
            ['unknown', ''],
            ['', ''],
            ['pdev', ''],
            ['pdev', 'other'],
            ['src', 'set'],
            ['dev', 'set'],
            ['ppend', 'label'],
        ] as [$purpose, $label]) {
            try {
                $deriver->scopeKey('login', $purpose, 'value', $label, self::OUTER_SECRET);
                self::fail("Expected RateLimiterException for ({$purpose}, {$label}).");
            } catch (RateLimiterException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ------------------------------------------------------------------
    // 2. Permanent Red/Green Detectors: A1, A2, A3, Direct-Store Wrapper
    // ------------------------------------------------------------------

    #[Group('php32')]
    public function testDetectorA1LegacyNulJoinCollidesWhileCanonicalAdmissionFramingSeparates(): void
    {
        // Red (legacy NUL-delimited preimage): shifting a field boundary across "\0" produces identical bytes.
        $legacyA = hash_hmac('sha256', implode("\0", ['prod', "a\0b", 'src', 'c', '']), self::OUTER_SECRET);
        $legacyB = hash_hmac('sha256', implode("\0", ['prod', 'a', "b\0src", 'c', '']), self::OUTER_SECRET);
        self::assertSame($legacyA, $legacyB, 'DETECTOR (Red): legacy NUL-delimited Admission preimage collides across field boundaries');

        // Green (G3 DEC-048): canonical length-prefixed framing separates every shifted NUL boundary.
        $deriverA = new AdmissionKeyDeriver("prod\0x", 'auth.example.test', self::STATE_SECRET);
        $deriverB = new AdmissionKeyDeriver('prod', "x\0auth.example.test", self::STATE_SECRET);
        self::assertNotSame($deriverA->cohort(), $deriverB->cohort());

        $deriver = new AdmissionKeyDeriver(self::ENV, self::TRUST_DOMAIN, self::STATE_SECRET);
        self::assertNotSame(
            $deriver->scopeKey("g\0x", AdmissionScopePurpose::SRC, 'v', '', self::OUTER_SECRET),
            $deriver->scopeKey('g', AdmissionScopePurpose::SRC, "x\0v", '', self::OUTER_SECRET),
        );
        self::assertNotSame(
            $deriver->fenceKey("a\0b", self::OUTER_SECRET),
            $deriver->fenceKey("a\0b\0", self::OUTER_SECRET),
        );
        self::assertNotSame(
            $deriver->epochKey("acct\0x"),
            $deriver->epochKey('acct'),
        );
    }

    #[Group('php32')]
    public function testDetectorA2FenceAndEpochAreSeparatedByDomainSecretAndLogicalPrefix(): void
    {
        // Even with identical outer secret and state secret and identical identifier ('login'), fence and epoch cannot collide.
        $sharedSecret = str_repeat('k', 32);
        $deriver = new AdmissionKeyDeriver(self::ENV, self::TRUST_DOMAIN, $sharedSecret);
        $fence = $deriver->fenceKey('login', $sharedSecret);
        $epoch = $deriver->epochKey('login');

        self::assertStringStartsWith('a1.fence.', $fence);
        self::assertStringStartsWith('a1.epoch.', $epoch);
        self::assertNotSame($fence, $epoch);
        self::assertNotSame(substr($fence, strlen('a1.fence.')), substr($epoch, strlen('a1.epoch.')));

        // Reference store also separates fence family from epoch family even if given the exact same raw key string!
        $store = new AdmissionInMemoryReferenceStore(new FixedClock('@' . self::T0));
        self::assertSame(1, $store->bumpAdmissionFence('same-key', null));
        self::assertSame(1, $store->readAdmissionFence('same-key', null));
        self::assertSame(0, $store->readBindingEpoch('same-key'), 'A2: fence write must not affect binding epoch family');
        self::assertSame(1, $store->bumpBindingEpoch('same-key'));
        self::assertSame(1, $store->readAdmissionFence('same-key', null), 'A2: epoch write must not affect fence family');
    }

    #[Group('php32')]
    public function testDetectorA3EpochIsAccountWideAcrossGroupsAndIndependentOfOuterRotation(): void
    {
        $deriver = new AdmissionKeyDeriver(self::ENV, self::TRUST_DOMAIN, self::STATE_SECRET);
        $epochKey = $deriver->epochKey('acct-shared');

        // Rotating outer secret changes fence and scope keys, never epoch or pcfg keys.
        $fence1 = $deriver->fenceKey('login', 'outer-1');
        $fence2 = $deriver->fenceKey('login', 'outer-2');
        self::assertNotSame($fence1, $fence2);
        self::assertSame($epochKey, $deriver->epochKey('acct-shared'));

        // Account-wide revocation applies across multiple budget groups (`login` and `step_up`).
        $store = new AdmissionInMemoryReferenceStore(new FixedClock('@' . self::T0));
        $clock = new FixedClock('@' . self::T0);
        $codec = new AdmissionCredentialCodec(self::BINDING_SECRET, self::ENV, self::TRUST_DOMAIN, self::STATE_SECRET);
        $runtime = new AdmissionRuntime(
            $store,
            $codec,
            EngineLab::budgets(),
            $clock,
            'outer-1',
            self::ENV,
            self::TRUST_DOMAIN,
            self::STATE_SECRET,
        );

        $cred = $codec->encode(new AdmissionCredentialDTO('acct-shared', 'acct-shared', ['login', 'step_up'], 'inst-1', 'jti-1', self::T0 + 3600, self::T0, 0));
        $ctx = new RateLimitContextDTO('198.51.100.1', 'ua', 'acct-shared', null, null, false, [], false, null, clientInstanceId: 'inst-1', admissionCredential: $cred);

        self::assertSame('P', $runtime->resolveLane('login', $ctx)->lane->value);
        self::assertSame('P', $runtime->resolveLane('step_up', $ctx)->lane->value);

        $runtime->revokeAccountBinding('acct-shared');
        self::assertSame(1, $runtime->bindingEpoch('acct-shared'));
        self::assertSame('U', $runtime->resolveLane('login', $ctx)->lane->value, 'A3: login lane invalidated by account-wide epoch');
        self::assertSame('U', $runtime->resolveLane('step_up', $ctx)->lane->value, 'A3: step_up lane invalidated by the same account-wide epoch');

        // Surviving >=2 outer rotations without any request or bump during rotations!
        $runtimeAfterTwoRotations = new AdmissionRuntime(
            $store,
            $codec,
            EngineLab::budgets(),
            $clock,
            'outer-3',
            self::ENV,
            self::TRUST_DOMAIN,
            self::STATE_SECRET,
            'outer-2',
        );
        self::assertSame(1, $runtimeAfterTwoRotations->bindingEpoch('acct-shared'));
        self::assertSame('U', $runtimeAfterTwoRotations->resolveLane('login', $ctx)->lane->value, 'Old epoch=0 credential stays U after >=2 outer rotations');
    }

    #[Group('php32')]
    public function testDirectStoreWrapperSeparatesNulBoundaryAcrossVerifierClassAndKey(): void
    {
        $store = new AdmissionInMemoryReferenceStore(new FixedClock('@' . self::T0));

        $reqA = new AdmissionReservationRequestDTO(
            "a\0b",
            [new AdmissionScopeDTO('cap', AdmissionScopeKindEnum::COUNTER, 'c', 1, 600, null, 'p1')],
            new AdmissionPermitDTO('p1', 'U', str_repeat('a', 64), 'op', self::T0 + 30, 600),
        );
        $reqB = new AdmissionReservationRequestDTO(
            'a',
            [new AdmissionScopeDTO('cap', AdmissionScopeKindEnum::COUNTER, "b\0c", 1, 600, null, 'p2')],
            new AdmissionPermitDTO('p2', 'U', str_repeat('b', 64), 'op', self::T0 + 30, 600),
        );

        self::assertTrue($store->reserveAdmission($reqA, self::T0)->admitted);
        self::assertTrue(
            $store->reserveAdmission($reqB, self::T0)->admitted,
            'Direct-store physical wrapper must keep ("a\0b", "c") distinct from ("a", "b\0c")',
        );
    }

    // ------------------------------------------------------------------
    // 3. Opaque Inputs, U32±1 Framing Boundaries, and Preserved E1/f1/Simple Bytes
    // ------------------------------------------------------------------

    #[Group('php32')]
    public function testOpaqueInputsAndCheckedU32Boundaries(): void
    {
        $deriver = new AdmissionKeyDeriver("prod\0مصر", "auth\0🔐.test", str_repeat("\0x", 16));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $deriver->cohort());
        self::assertNotSame($deriver->epochKey('0'), $deriver->epochKey(''));
        self::assertNotSame($deriver->epochKey("\0"), $deriver->epochKey("\0\0"));
        self::assertTrue(AdmissionKeyDeriver::isNonBlank("\0"));
        self::assertTrue(AdmissionKeyDeriver::isNonBlank('0'));
        self::assertFalse(AdmissionKeyDeriver::isNonBlank(" \t\n\r\v\f"));

        // U32 boundary seam checks (0, 2^32-1 pass on 64-bit; negative and 2^32 fail; PHP_INT_MAX on 32-bit passes).
        KeyPreimageFraming::encodeU32(0, 'test');
        if (PHP_INT_SIZE >= 8) {
            self::assertSame("\xFF\xFF\xFF\xFF", KeyPreimageFraming::encodeU32(4_294_967_295, 'test'));
            $overflowDeriver = new AdmissionKeyDeriver(
                self::ENV,
                self::TRUST_DOMAIN,
                self::STATE_SECRET,
                length: static fn(string $v): int => $v === 'boom' ? 4_294_967_296 : strlen($v),
            );
            $threw = false;
            try {
                $overflowDeriver->epochKey('boom');
            } catch (RateLimiterException) {
                $threw = true;
            }
            self::assertTrue($threw, 'Field length 2^32 must be rejected before pack("N")/HMAC');
        } else {
            self::assertSame(pack('N', PHP_INT_MAX), KeyPreimageFraming::encodeU32(PHP_INT_MAX, 'test'));
        }

        $this->expectException(RateLimiterException::class);
        new AdmissionKeyDeriver(
            self::ENV,
            self::TRUST_DOMAIN,
            self::STATE_SECRET,
            count: static fn(array $f): int => -1,
        );
    }

    #[Group('php32')]
    public function testPreservedE1NonAccountF1AndSimpleFixedWindowVectorsAreUnchanged(): void
    {
        $e1 = new E1AccountScopeKeyDeriver('test-env');
        $f1 = new NonAccountScopeKeyDeriver('test-env');
        $simple = new SimpleFixedWindowKeyDeriver('test-env');

        // Cross-check against exact DEC-046 / DEC-047 independent framing formulas.
        $e1Expected = 'e1.' . self::oracleHmacHex(
            'test-secret',
            E1AccountScopeKeyDeriver::KEY_DOMAIN,
            E1AccountScopeKeyDeriver::MAGIC,
            ['k4', 'login_protection', 'test-env', 'acct-1'],
        );
        self::assertSame($e1Expected, $e1->derive('k4', 'login_protection', 'acct-1', 'test-secret'));

        $f1Expected = self::oracleHmacHex(
            'test-secret',
            NonAccountScopeKeyDeriver::KEY_DOMAIN,
            NonAccountScopeKeyDeriver::MAGIC,
            [NonAccountScopePurpose::K1, 'login_protection', 'test-env', '198.51.100.1'],
        );
        self::assertSame($f1Expected, $f1->deriveK1('login_protection', '198.51.100.1', 'test-secret'));

        $simplePreimage = '';
        foreach (['rate_limiter', 'simple_fixed_window', 'v1', 'test-env', 'checkout', '10', '60', 'subj-1'] as $part) {
            $simplePreimage .= pack('N', strlen($part)) . $part;
        }
        self::assertSame(hash_hmac('sha256', $simplePreimage, 'test-secret'), $simple->derive('checkout', 10, 60, 'subj-1', 'test-secret'));
    }

    // ------------------------------------------------------------------
    // 4. Usability & Fail-Fast Configuration Boundaries
    // ------------------------------------------------------------------

    public function testNonOptedConsumerNeedsZeroAdmissionConfigAndOptedConsumerFailsBeforeBackendCommands(): void
    {
        $clock = new FixedClock('@' . self::T0);
        $store = new AdmissionInMemoryReferenceStore($clock);

        // Non-opted consumer: no bindingKeySecret, no admissionTrustDomain, no admissionStateSecret.
        $nonOpted = new RateLimiterBuilder(
            new RateLimiterConfig(self::OUTER_SECRET, 'fp-secret', self::ENV),
            $store,
            $store->correlationStore(),
            new InMemoryCircuitBreakerStore(),
            new RecordingFailureSignalEmitter(),
        );
        $runtime = $nonOpted->withPolicy(new LoginProtectionPolicy())->withClock($clock)->build();
        self::assertSame(
            RateLimitResultDTO::DECISION_ALLOW,
            $runtime->limit(new RateLimitContextDTO('198.51.100.5', 'ua', 'acct-plain'), RateLimitCommand::checkOnly('login_protection'))->decision,
        );

        // Opted consumer missing bindingKeySecret or trustDomain/stateSecret fails at build() before touching store.
        foreach ([
            new RateLimiterConfig(self::OUTER_SECRET, 'fp-secret', self::ENV),
            new RateLimiterConfig(self::OUTER_SECRET, 'fp-secret', self::ENV, bindingKeySecret: self::BINDING_SECRET),
            new RateLimiterConfig(self::OUTER_SECRET, 'fp-secret', self::ENV, admissionTrustDomain: self::TRUST_DOMAIN, admissionStateSecret: self::STATE_SECRET),
        ] as $invalidConfig) {
            $failed = false;
            try {
                (new RateLimiterBuilder(
                    $invalidConfig,
                    $store,
                    $store->correlationStore(),
                    new InMemoryCircuitBreakerStore(),
                    new RecordingFailureSignalEmitter(),
                ))->withPolicy(new LoginProtectionAdmissionPolicy())->withClock($clock)->build();
            } catch (RateLimiterException) {
                $failed = true;
            }
            self::assertTrue($failed, 'Opted Admission policy must reject incomplete config at build()');
        }

        // Unified valid setup: RateLimiterConfig -> AdmissionCredentialCodec::fromConfig + RateLimiterBuilder.
        $validConfig = new RateLimiterConfig(
            self::OUTER_SECRET,
            'fp-secret',
            self::ENV,
            bindingKeySecret: self::BINDING_SECRET,
            admissionTrustDomain: self::TRUST_DOMAIN,
            admissionStateSecret: self::STATE_SECRET,
        );
        $codec = AdmissionCredentialCodec::fromConfig($validConfig);
        $engine = (new RateLimiterBuilder(
            $validConfig,
            $store,
            $store->correlationStore(),
            new InMemoryCircuitBreakerStore(),
            new RecordingFailureSignalEmitter(),
        ))->withPolicy(new LoginProtectionAdmissionPolicy())->withClock($clock)->build();

        self::assertInstanceOf(AdmissionFenceControlInterface::class, $engine);
        self::assertInstanceOf(AdmissionBindingControlInterface::class, $engine);
        self::assertInstanceOf(AdmissionBindingEpochInterface::class, $engine);
        self::assertSame(1, $engine->openAdmissionFence('login'));

        $cred = $codec->encode(new AdmissionCredentialDTO('acct-u', 'acct-u', ['login'], 'inst-u', 'jti-u', self::T0 + 3600, self::T0, $engine->bindingEpoch('acct-u')));
        $ctx = new RateLimitContextDTO('198.51.100.9', 'ua', 'acct-u', null, null, false, [], false, null, clientInstanceId: 'inst-u', admissionCredential: $cred);
        $check = $engine->limit($ctx, RateLimitCommand::checkOnly('login_protection'));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $check->decision);
        self::assertNotNull($check->metadata?->permit);
    }

    public function testRuntimeRejectsCodecWithDifferentCohort(): void
    {
        $clock = new FixedClock('@' . self::T0);
        $store = new AdmissionInMemoryReferenceStore($clock);
        $foreignCodec = new AdmissionCredentialCodec(self::BINDING_SECRET, self::ENV, 'other.example.test', self::STATE_SECRET);

        $this->expectException(RateLimiterException::class);
        new AdmissionRuntime(
            $store,
            $foreignCodec,
            EngineLab::budgets(),
            $clock,
            self::OUTER_SECRET,
            self::ENV,
            self::TRUST_DOMAIN,
            self::STATE_SECRET,
        );
    }

    // ------------------------------------------------------------------
    // 5. Credential v2 Hardening (v1 rejected, account vs subject, cohorts, rotations)
    // ------------------------------------------------------------------

    #[Group('php32')]
    public function testCredentialV2RejectsV1SeparatesAccountFromSubjectAndEnforcesCohortAndRotation(): void
    {
        $codec = new AdmissionCredentialCodec(self::BINDING_SECRET, self::ENV, self::TRUST_DOMAIN, self::STATE_SECRET);

        // 1. Legacy v1 credential (even with a valid legacy HMAC under the exact same bindingKeySecret) is rejected!
        $v1Json = json_encode([
            'v' => 1,
            'sub' => 'acct-1',
            'grp' => ['login'],
            'inst' => 'inst-1',
            'jti' => 'jti-1',
            'exp' => self::T0 + 3600,
            'iat' => self::T0,
            'ep' => 0,
            'kid' => substr(hash('sha256', self::BINDING_SECRET), 0, 16),
        ], JSON_THROW_ON_ERROR);
        $v1Payload = rtrim(strtr(base64_encode($v1Json), '+/', '-_'), '=');
        $v1Mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $v1Payload, self::BINDING_SECRET, true)), '+/', '-_'), '=');
        $v1Token = $v1Payload . '.' . $v1Mac;
        self::assertSame(
            AdmissionCredentialVerdictEnum::MALFORMED,
            $codec->verify($v1Token, 'acct-1', 'acct-1', 'login', 'inst-1', self::T0, 0)->verdict,
            'Legacy v1 credential must be unconditionally rejected',
        );

        // 2. Explicit account vs subject (correlationId) separation.
        $dto = new AdmissionCredentialDTO('acct-1', 'corr-99', ['login', 'step_up'], 'inst-1', 'jti-1', self::T0 + 3600, self::T0, 0);
        $token = $codec->encode($dto);
        self::assertSame(
            AdmissionCredentialVerdictEnum::VALID,
            $codec->verify($token, 'acct-1', 'corr-99', 'login', 'inst-1', self::T0, 0)->verdict,
        );
        self::assertSame(
            AdmissionCredentialVerdictEnum::VALID,
            $codec->verify($token, 'acct-1', 'corr-99', 'step_up', 'inst-1', self::T0, 0)->verdict,
        );
        self::assertSame(
            AdmissionCredentialVerdictEnum::WRONG_ACCOUNT,
            $codec->verify($token, 'acct-other', 'corr-99', 'login', 'inst-1', self::T0, 0)->verdict,
        );
        self::assertSame(
            AdmissionCredentialVerdictEnum::WRONG_SUBJECT,
            $codec->verify($token, 'acct-1', 'corr-other', 'login', 'inst-1', self::T0, 0)->verdict,
        );
        self::assertSame(
            AdmissionCredentialVerdictEnum::WRONG_BUDGET_GROUP,
            $codec->verify($token, 'acct-1', 'corr-99', 'other_group', 'inst-1', self::T0, 0)->verdict,
        );

        // 3. Cross-env / cross-trust-domain / cross-state-secret rejection (distinct kid + MAC domain).
        $otherEnv = new AdmissionCredentialCodec(self::BINDING_SECRET, 'staging', self::TRUST_DOMAIN, self::STATE_SECRET);
        $otherDomain = new AdmissionCredentialCodec(self::BINDING_SECRET, self::ENV, 'other.example.test', self::STATE_SECRET);
        $otherState = new AdmissionCredentialCodec(self::BINDING_SECRET, self::ENV, self::TRUST_DOMAIN, 'other-state-secret-0123456789abcdef');
        foreach ([$otherEnv, $otherDomain, $otherState] as $foreign) {
            self::assertSame(
                AdmissionCredentialVerdictEnum::INVALID_SIGNATURE,
                $foreign->verify($token, 'acct-1', 'corr-99', 'login', 'inst-1', self::T0, 0)->verdict,
            );
        }

        // 4. Binding key rotation: 1 rotation verifies via previousBindingKey; 2 rotations reject.
        $rotated1 = new AdmissionCredentialCodec('binding-2', self::ENV, self::TRUST_DOMAIN, self::STATE_SECRET, self::BINDING_SECRET);
        self::assertSame(
            AdmissionCredentialVerdictEnum::VALID,
            $rotated1->verify($token, 'acct-1', 'corr-99', 'login', 'inst-1', self::T0, 0)->verdict,
        );
        $rotated2 = new AdmissionCredentialCodec('binding-3', self::ENV, self::TRUST_DOMAIN, self::STATE_SECRET, 'binding-2');
        self::assertSame(
            AdmissionCredentialVerdictEnum::INVALID_SIGNATURE,
            $rotated2->verify($token, 'acct-1', 'corr-99', 'login', 'inst-1', self::T0, 0)->verdict,
        );
    }

    // ------------------------------------------------------------------
    // 6. Permit v2 Context Binding, Max Length 512, Absent vs '-', No Raw Identity
    // ------------------------------------------------------------------

    #[DataProvider('permitMismatchMutations')]
    public function testPermitContextMismatchIsRejectedBeforeStoreClaimAndLeavesOriginalClaimable(
        string $label,
        callable $mutateContext,
    ): void {
        $lab = new EngineLab();
        $cred = $lab->mint('acct-ctx', 'inst-ctx', subject: 'corr-ctx');
        $ctx = new RateLimitContextDTO(
            '198.51.100.44',
            'Mozilla/5.0 Chrome/126',
            'acct-ctx',
            null,
            null,
            false,
            [],
            false,
            'corr-ctx',
            clientInstanceId: 'inst-ctx',
            admissionCredential: $cred,
        );

        $check = $lab->check($ctx);
        $permit = EngineLab::permit($check);
        self::assertNotNull($permit);
        self::assertLessThanOrEqual(AdmissionCredentialCodec::PERMIT_MAX_LENGTH, strlen($permit));

        $mutated = $mutateContext($lab, $ctx);
        self::assertInstanceOf(RateLimitContextDTO::class, $mutated);

        $callsBefore = $lab->admissionStore->calls;
        $rejected = $lab->outcome($mutated, false, $permit);
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $rejected->decision, $label);
        self::assertSame('permit_rejected', $rejected->metadata?->cause, $label);
        self::assertSame('permit', $rejected->metadata->context?->scope, $label);
        // Only the fence read ran (1 store call); claimAdmissionPermitWithOutcome was NOT called!
        $claimCalls = array_filter(
            array_slice($lab->admissionStore->calls, count($callsBefore)),
            static fn(string $c): bool => str_contains($c, 'claim'),
        );
        self::assertSame([], $claimCalls, "{$label}: store claim must not be called when ctx mismatches");

        // Original context can still claim its permit exactly once!
        $accepted = $lab->outcome($ctx, true, $permit);
        self::assertNull($accepted->metadata?->cause, "{$label}: valid control must still claim once");
        $dup = $lab->outcome($ctx, true, $permit);
        self::assertSame('permit_duplicate', $dup->metadata?->cause);
    }

    /** @return iterable<string, array{string, callable(EngineLab, RateLimitContextDTO): RateLimitContextDTO}> */
    public static function permitMismatchMutations(): iterable
    {
        yield 'foreign IP' => ['foreign IP', static fn(EngineLab $l, RateLimitContextDTO $c): RateLimitContextDTO => new RateLimitContextDTO('203.0.113.99', $c->ua, $c->accountId, null, null, false, [], false, $c->correlationId, clientInstanceId: $c->clientInstanceId, admissionCredential: $c->admissionCredential)];
        yield 'foreign account' => ['foreign account', static fn(EngineLab $l, RateLimitContextDTO $c): RateLimitContextDTO => new RateLimitContextDTO($c->ip, $c->ua, 'acct-other', null, null, false, [], false, $c->correlationId, clientInstanceId: $c->clientInstanceId, admissionCredential: $c->admissionCredential)];
        yield 'foreign correlation subject' => ['foreign subject', static fn(EngineLab $l, RateLimitContextDTO $c): RateLimitContextDTO => new RateLimitContextDTO($c->ip, $c->ua, $c->accountId, null, null, false, [], false, 'corr-other', clientInstanceId: $c->clientInstanceId, admissionCredential: $c->admissionCredential)];
        yield 'foreign client instance' => ['foreign instance', static fn(EngineLab $l, RateLimitContextDTO $c): RateLimitContextDTO => new RateLimitContextDTO($c->ip, $c->ua, $c->accountId, null, null, false, [], false, $c->correlationId, clientInstanceId: 'inst-other', admissionCredential: $c->admissionCredential)];
        yield 'missing credential' => ['missing credential', static fn(EngineLab $l, RateLimitContextDTO $c): RateLimitContextDTO => new RateLimitContextDTO($c->ip, $c->ua, $c->accountId, null, null, false, [], false, $c->correlationId, clientInstanceId: $c->clientInstanceId, admissionCredential: null)];
        yield 'different credential' => ['different credential', static fn(EngineLab $l, RateLimitContextDTO $c): RateLimitContextDTO => new RateLimitContextDTO($c->ip, $c->ua, $c->accountId, null, null, false, [], false, $c->correlationId, clientInstanceId: $c->clientInstanceId, admissionCredential: $l->mint('acct-ctx', 'inst-ctx', subject: 'corr-ctx', jti: 'jti-different'))];
    }

    #[Group('php32')]
    public function testPermitSizeCap512AbsentVsDashDistinctnessAndCrossPolicyOperationRejection(): void
    {
        $codec = new AdmissionCredentialCodec(self::BINDING_SECRET, self::ENV, self::TRUST_DOMAIN, self::STATE_SECRET);
        // Oversized permit rejected on sign & verify.
        $oversizedPayload = str_repeat('a', 400);
        try {
            $codec->signPermitToken($oversizedPayload);
            self::fail('Oversized permit (>512 bytes) must be rejected on sign');
        } catch (RateLimiterException) {
            $this->addToAssertionCount(1);
        }
        self::assertNull($codec->verifyPermitToken(str_repeat('a', 513)));

        // Absent identity vs literal '-' must produce distinct context commitments (no NO_SUBJECT = '-' sentinel).
        $deriver = new AdmissionKeyDeriver(self::ENV, self::TRUST_DOMAIN, self::STATE_SECRET);
        $ctxNull = new RateLimitContextDTO('198.51.100.1', 'ua', null, null, null, false, [], false, null);
        $ctxEmpty = new RateLimitContextDTO('198.51.100.1', 'ua', '', null, null, false, [], false, '');
        $ctxDash = new RateLimitContextDTO('198.51.100.1', 'ua', '-', null, null, false, [], false, '-');
        self::assertSame(
            $deriver->permitContextCommitment('login', 'login_protection', $ctxNull),
            $deriver->permitContextCommitment('login', 'login_protection', $ctxEmpty),
            'Empty string and null both represent absence',
        );
        self::assertNotSame(
            $deriver->permitContextCommitment('login', 'login_protection', $ctxNull),
            $deriver->permitContextCommitment('login', 'login_protection', $ctxDash),
            'Absent identity and literal "-" must remain strictly distinct',
        );

        // Cross-policy (same budget group, different operation name) and cross-group rejection.
        $otherPolicySameGroup = new class extends OptedLoginPolicy implements AdmissionReservationPolicyInterface {
            public function getName(): string
            {
                return 'reauth_protection';
            }

            public function getAdmissionHostMode(): AdmissionHostModeEnum
            {
                return AdmissionHostModeEnum::PRE_AND_OUT;
            }

            public function getAdmissionBudgetGroup(): string
            {
                return EngineLab::GROUP;
            }
        };
        $otherGroupPolicy = new class extends OptedLoginPolicy implements AdmissionReservationPolicyInterface {
            public function getName(): string
            {
                return 'other_group_login';
            }

            public function getAdmissionHostMode(): AdmissionHostModeEnum
            {
                return AdmissionHostModeEnum::PRE_AND_OUT;
            }

            public function getAdmissionBudgetGroup(): string
            {
                return 'other_group';
            }
        };
        $lab = new EngineLab(policies: [$otherPolicySameGroup, $otherGroupPolicy]);
        $lab->openFence('other_group');
        $ctx = $lab->context('acct-op', '198.51.100.77');
        $permit = EngineLab::permit($lab->check($ctx));

        $wrongOp = $lab->engine->limit($ctx, new RateLimitCommand('reauth_protection', 1, false, true, false, $permit));
        self::assertSame('permit_rejected', $wrongOp->metadata?->cause, 'Same group but different operation must reject permit');

        $wrongGroup = $lab->engine->limit($ctx, new RateLimitCommand('other_group_login', 1, false, true, false, $permit));
        self::assertSame('permit_rejected', $wrongGroup->metadata?->cause, 'Different budget group must reject permit');
    }

    // ------------------------------------------------------------------
    // 7. D7 (DEC-050): Closed-Fence Permit Settlement & S0 Non-Scoring
    // ------------------------------------------------------------------

    public function testD7ClosedFenceSettlesOutstandingPermitOnceAndNeverScoresInvalidPermitsInS0(): void
    {
        $clock = new FixedClock('@' . self::T0);
        $store = new AdmissionInMemoryReferenceStore($clock);

        // Worker 1 with outer secret S1: open fence, issue one U permit and one P permit.
        $config1 = new RateLimiterConfig(
            'outer-1',
            'fp-1',
            self::ENV,
            bindingKeySecret: self::BINDING_SECRET,
            admissionTrustDomain: self::TRUST_DOMAIN,
            admissionStateSecret: self::STATE_SECRET,
        );
        $codec = AdmissionCredentialCodec::fromConfig($config1);
        $engine1 = (new RateLimiterBuilder($config1, $store, $store->correlationStore(), new InMemoryCircuitBreakerStore(), new RecordingFailureSignalEmitter()))
            ->withPolicy(new LoginProtectionAdmissionPolicy())
            ->withClock($clock)
            ->build();
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $engine1);
        self::assertSame(1, $engine1->openAdmissionFence('login'));

        $ctxU = new RateLimitContextDTO('198.51.100.10', 'ua', 'acct-u-d7');
        $permitU = EngineLab::permit($engine1->limit($ctxU, RateLimitCommand::checkOnly('login_protection')));

        $credP = $codec->encode(new AdmissionCredentialDTO('acct-p-d7', 'acct-p-d7', ['login'], 'inst-p', 'jti-p', self::T0 + 3600, self::T0, 0));
        $ctxP = new RateLimitContextDTO('198.51.100.11', 'ua', 'acct-p-d7', null, null, false, [], false, null, clientInstanceId: 'inst-p', admissionCredential: $credP);
        $permitP = EngineLab::permit($engine1->limit($ctxP, RateLimitCommand::checkOnly('login_protection')));

        // Close the fence by advancing to outer secret S3 (without re-opening fence under S2/S3).
        $configClosed = new RateLimiterConfig(
            'outer-3',
            'fp-1',
            self::ENV,
            'outer-2',
            bindingKeySecret: self::BINDING_SECRET,
            admissionTrustDomain: self::TRUST_DOMAIN,
            admissionStateSecret: self::STATE_SECRET,
        );
        $engineClosed = (new RateLimiterBuilder($configClosed, $store, $store->correlationStore(), new InMemoryCircuitBreakerStore(), new RecordingFailureSignalEmitter()))
            ->withPolicy(new LoginProtectionAdmissionPolicy())
            ->withClock($clock)
            ->build();

        // 1. Closed fence + checkOnly => S0 path, NO permit issued!
        $closedCheck = $engineClosed->limit($ctxU, RateLimitCommand::checkOnly('login_protection'));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $closedCheck->decision);
        self::assertNull($closedCheck->metadata?->permit, 'Closed fence checkOnly must not reserve or issue a permit');

        // 2. Closed fence + invalid / tampered / foreign permit => permit_unknown / permit_rejected and ZERO S0 scoring!
        $invalidCtx = new RateLimitContextDTO('198.51.100.12', 'ua', 'acct-invalid-d7');
        for ($i = 0; $i < 6; $i++) {
            $r = $engineClosed->limit($invalidCtx, new RateLimitCommand('login_protection', 1, false, true, false, 'bogus.permit'));
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $r->decision);
            self::assertSame('permit_unknown', $r->metadata?->cause, 'Invalid permit under closed fence must return permit_unknown, never score in S0');
        }
        $foreign = $engineClosed->limit($invalidCtx, new RateLimitCommand('login_protection', 1, false, true, false, $permitU));
        self::assertSame('permit_rejected', $foreign->metadata?->cause, 'Foreign-context permit under closed fence must return permit_rejected, never score in S0');

        // 3. Closed fence + valid outstanding U permit => settles ONCE via permit claim, replay is permit_duplicate!
        $settleU = $engineClosed->limit($ctxU, new RateLimitCommand('login_protection', 1, false, true, false, $permitU));
        self::assertNull($settleU->metadata?->cause, 'Valid U permit settles once even after fence closes');
        $dupU = $engineClosed->limit($ctxU, new RateLimitCommand('login_protection', 1, false, true, false, $permitU));
        self::assertSame('permit_duplicate', $dupU->metadata?->cause);

        // 4. Closed fence + valid outstanding P permit => settles ONCE on P lane, replay is permit_duplicate!
        $settleP = $engineClosed->limit($ctxP, new RateLimitCommand('login_protection', 1, false, true, false, $permitP));
        self::assertNull($settleP->metadata?->cause, 'Valid P permit settles once even after fence closes');
        $dupP = $engineClosed->limit($ctxP, new RateLimitCommand('login_protection', 1, false, true, false, $permitP));
        self::assertSame('permit_duplicate', $dupP->metadata?->cause);

        // 5. Closed fence + permit-less outcome => S0 path still works!
        $s0Ctx = new RateLimitContextDTO('198.51.100.13', 'ua', 'acct-s0-d7');
        $s0Final = null;
        for ($i = 0; $i < 4; $i++) {
            $s0Final = $engineClosed->limit($s0Ctx, RateLimitCommand::recordFailure('login_protection'));
        }
        self::assertInstanceOf(RateLimitResultDTO::class, $s0Final);
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $s0Final->decision, 'Permit-less outcome under closed fence follows S0');

        // 6. Non-opted policies make zero admission store calls.
        $lab = new EngineLab(policies: [new OtpProtectionPolicy(), new ApiHeavyProtectionPolicy()]);
        $callsBefore = count($lab->admissionStore->calls);
        $lab->engine->limit(new RateLimitContextDTO('198.51.100.14', 'ua', 'acct-otp'), RateLimitCommand::checkOnly('otp_protection'));
        $lab->engine->limit(new RateLimitContextDTO('198.51.100.14', 'ua', 'acct-api'), RateLimitCommand::checkOnly('api_heavy_protection'));
        self::assertSame($callsBefore, count($lab->admissionStore->calls), 'Non-opted policies must perform zero admission store reads');
    }

    /** @param list<string> $fields */
    private static function oracleHmacHex(string $secret, string $domain, string $magic, array $fields): string
    {
        $preimage = $magic . pack('N', count($fields));
        foreach ($fields as $field) {
            $preimage .= pack('N', strlen($field)) . $field;
        }
        $subkey = hash_hmac('sha256', $domain, $secret, true);

        return hash_hmac('sha256', $preimage, $subkey);
    }
}
