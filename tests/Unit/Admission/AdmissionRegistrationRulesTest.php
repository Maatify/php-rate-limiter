<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Enum\PolicyCapabilityEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\AdmissionRuntime;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FailureModeResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionReservationOnlyStore;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionStubStore;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionTestPolicy;
use Maatify\RateLimiter\Tests\Support\Admission\DeclaresCapabilityWithoutInterfacePolicy;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\NullCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\FullCapability\FullCapabilityInMemoryStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Gates REG and DORMANT of the 5-b1 contract (RUNTIME_IMPLEMENTATION_CONTRACT.md §8.3, §1.1).
 *
 * Every rule has its own rejection test. The detector of each rule is the control
 * policy that is valid in every respect and registers: a rejection is therefore the
 * effect of exactly one rule and not of a blanket refusal.
 */
final class AdmissionRegistrationRulesTest extends TestCase
{
    // ---- engine-level rules (an AdmissionRuntime is present, so only the policy rules can reject) ----

    public function testControlPolicyRegistersWhenAnAdmissionRuntimeIsPresent(): void
    {
        $this->engine(self::runtime(), [new AdmissionTestPolicy()]);
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string, BlockPolicyInterface}> */
    public static function invalidPolicyProvider(): iterable
    {
        yield 'declares the capability without the declaration interface' => [
            'ADMISSION_RESERVATION requires AdmissionReservationPolicyInterface',
            new DeclaresCapabilityWithoutInterfacePolicy('no-interface', true),
        ];
        yield 'implements the declaration interface without the capability' => [
            'AdmissionReservationPolicyInterface requires the ADMISSION_RESERVATION capability',
            new AdmissionTestPolicy('no-capability', []),
        ];
        yield 'empty budget group' => [
            'admission budget group must not be empty',
            new AdmissionTestPolicy('empty-group', budgetGroup: ''),
        ];
        yield 'whitespace budget group' => [
            'admission budget group must not be empty',
            new AdmissionTestPolicy('blank-group', budgetGroup: "  \t"),
        ];
        yield 'combined with API_OVERUSE' => [
            'Authentication and API_OVERUSE capabilities cannot be combined',
            new AdmissionTestPolicy('with-api', [PolicyCapabilityEnum::ADMISSION_RESERVATION, PolicyCapabilityEnum::API_OVERUSE]),
        ];
        yield 'FAIL_OPEN' => [
            'FAIL_OPEN is not allowed',
            new AdmissionTestPolicy('fail-open', mode: 'FAIL_OPEN'),
        ];
        yield 'no BudgetConfig' => [
            'Authentication policies require BudgetConfig',
            new AdmissionTestPolicy('no-budget', budget: null),
        ];
    }

    #[DataProvider('invalidPolicyProvider')]
    public function testEveryRuleRejectsAtRegistrationWithATypedException(string $message, BlockPolicyInterface $policy): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage($message);

        $this->engine(self::runtime(), [$policy]);
    }

    // ---- DORMANT (§1.1) ----

    public function testEngineWithoutAdmissionRuntimeRejectsAValidAdmissionPolicy(): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('ADMISSION_RESERVATION is not available: no admission runtime is configured');

        $this->engine(null, [new AdmissionTestPolicy('dormant')]);
    }

    public function testRuleRejectionIsReportedBeforeDormancyForAnInvalidPolicy(): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('requires AdmissionReservationPolicyInterface');

        $this->engine(null, [new DeclaresCapabilityWithoutInterfacePolicy('no-interface', true)]);
    }

    public function testBuilderRejectsAFullyEquippedAdmissionPolicyBecauseItNeverBuildsTheRuntime(): void
    {
        $builder = RateLimiterBuilder::fromFullCapabilityStore(
            $this->config(withBindingKey: true),
            new AdmissionStubStore(new FixedClock()),
            new RecordingFailureSignalEmitter(),
        )->withPolicy(new AdmissionTestPolicy('builder-dormant'));

        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('ADMISSION_RESERVATION is not available: no admission runtime is configured');

        $builder->build();
    }

    public function testBuilderWithoutAnAdmissionPolicyBuildsExactlyAsBefore(): void
    {
        RateLimiterBuilder::fromFullCapabilityStore(
            $this->config(withBindingKey: false),
            new FullCapabilityInMemoryStore(new FixedClock()),
            new RecordingFailureSignalEmitter(),
        )->withPolicy(new DeclaresCapabilityWithoutInterfacePolicy('plain-auth'))->build();

        $this->addToAssertionCount(1);
    }

    // ---- builder-level rules (store capability, binding key) ----

    public function testStoreWithoutTheReservationCapabilityIsRejected(): void
    {
        $builder = RateLimiterBuilder::fromFullCapabilityStore(
            $this->config(withBindingKey: true),
            new FullCapabilityInMemoryStore(new FixedClock()),
            new RecordingFailureSignalEmitter(),
        )->withPolicy(new AdmissionTestPolicy('no-store-capability'));

        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Policy no-store-capability requires AdmissionReservationStoreInterface');

        $builder->build();
    }

    public function testStoreWithoutThePermitCapabilityIsRejected(): void
    {
        $builder = RateLimiterBuilder::fromFullCapabilityStore(
            $this->config(withBindingKey: true),
            new AdmissionReservationOnlyStore(new FixedClock()),
            new RecordingFailureSignalEmitter(),
        )->withPolicy(new AdmissionTestPolicy('no-permit-capability'));

        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Policy no-permit-capability requires AdmissionPermitStoreInterface');

        $builder->build();
    }

    public function testMissingBindingKeyIsRejected(): void
    {
        $builder = RateLimiterBuilder::fromFullCapabilityStore(
            $this->config(withBindingKey: false),
            new AdmissionStubStore(new FixedClock()),
            new RecordingFailureSignalEmitter(),
        )->withPolicy(new AdmissionTestPolicy('no-binding-key'));

        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('Policy no-binding-key requires a binding key secret');

        $builder->build();
    }

    public function testOfficialPresetsDoNotDeclareTheCapability(): void
    {
        foreach ([new LoginProtectionPolicy(), new OtpProtectionPolicy(), new ApiHeavyProtectionPolicy()] as $preset) {
            self::assertNotContains(PolicyCapabilityEnum::ADMISSION_RESERVATION, $preset->getCapabilities());
        }

        RateLimiterBuilder::fromFullCapabilityStore(
            $this->config(withBindingKey: false),
            new FullCapabilityInMemoryStore(new FixedClock()),
            new RecordingFailureSignalEmitter(),
        )->build();

        $this->addToAssertionCount(1);
    }

    /** A real runtime (5-b3b, LT-22): the empty shell of 5-b1 no longer exists. Test key, fixed clock, reference store. */
    private static function runtime(): AdmissionRuntime
    {
        $clock = new FixedClock();

        return new AdmissionRuntime(
            new \Maatify\RateLimiter\Tests\Support\Admission\AdmissionInMemoryReferenceStore($clock),
            new \Maatify\RateLimiter\Service\AdmissionCredentialCodec('binding-secret'),
            \Maatify\RateLimiter\Tests\Support\Admission\EngineLab::budgets(),
            $clock,
            'key-secret',
            'prod',
        );
    }

    /** @param list<BlockPolicyInterface> $policies */
    private function engine(?AdmissionRuntime $admission, array $policies): RateLimiterEngine
    {
        $clock = new FixedClock();
        $store = new InMemoryRateLimitStore($clock);
        $emitter = new RecordingFailureSignalEmitter();

        return new RateLimiterEngine(
            new DeviceIdentityResolver(new FingerprintHasher('test_secret')),
            new EvaluationPipeline(
                $store,
                new NullCorrelationStore(),
                new BudgetTracker($store, $clock),
                new AntiEquilibriumGate(new NullCorrelationStore()),
                new DecayCalculator($clock),
                new EphemeralBucket(new NullCorrelationStore()),
                'test_secret',
                'prod',
                $clock,
            ),
            new CircuitBreaker(new InMemoryCircuitBreakerStore(), $emitter, $clock),
            new FailureModeResolver(),
            $emitter,
            $clock,
            $policies,
            $admission,
        );
    }

    private function config(bool $withBindingKey): RateLimiterConfig
    {
        return new RateLimiterConfig(
            'key-secret',
            'fingerprint-secret',
            'prod',
            bindingKeySecret: $withBindingKey ? 'binding-secret' : null,
        );
    }
}
