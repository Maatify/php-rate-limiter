<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Config\AdmissionReservationPolicyInterface;
use Maatify\RateLimiter\Config\KnownDeviceAccountAllowancePolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionAdmissionPolicy;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\PostPunishmentReentryPolicyInterface;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\AdmissionBudgetsDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Enum\AdmissionHostModeEnum;
use Maatify\RateLimiter\Enum\PolicyCapabilityEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\AdmissionFenceControlInterface;
use Maatify\RateLimiter\Service\AdmissionRuntime;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntime;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionInMemoryReferenceStore;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\FullCapability\FullCapabilityInMemoryStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Stage 5-c: the builder line and the first opting policy (5c/PLAN.md §7.6.2 DORMANT, §4.2 REG through the builder, OD-5 the
 * variant, OD-7 the fence method, LT-31, LT-32) and the 5-b3b engine gates that run on the package constants, through the
 * builder (LT-28: SC-1, LANE, the permit, the fence, the rollback).
 */
#[Group('dormant')]
#[Group('reg')]
#[Group('builder')]
final class AdmissionBuilderActivationTest extends TestCase
{
    /**
     * The package constants, typed here with their sources (the only place outside `AdmissionBudgetsDTO::packageDefaults()`):
     * DEC-025 1.4 (`W`, `U_src`, `F`), 2.2 (`C`, `T`), 3.1 (`P_acct`, `P_proof`, `N_P`), 8 as 8.2 (D8 `F`); DEC-028 (`D`, `G`).
     */
    private const PACKAGE_CONSTANTS = [
        'windowSeconds' => 600,
        'sourceCapacity' => 24,
        'sourceFailureCap' => 4,
        'accountBucketTakes' => 4,
        'accountBucketSeconds' => 1800,
        'proofAccountWindow' => 10,
        'proofAccountDay' => 60,
        'proofProofWindow' => 6,
        'proofProofDay' => 30,
        'newDevicesPerDay' => 10,
        'crossAccountFailureCap' => 4,
        'permitDeadlineSeconds' => 30,
        'permitGraceSeconds' => 600,
    ];

    private const IP = '203.0.113.40';

    /** @return array<string, int> */
    private static function fields(AdmissionBudgetsDTO $budgets): array
    {
        self::assertCount(count(self::PACKAGE_CONSTANTS), get_object_vars($budgets), 'no field beyond the registered ones');

        return [
            'windowSeconds' => $budgets->windowSeconds,
            'sourceCapacity' => $budgets->sourceCapacity,
            'sourceFailureCap' => $budgets->sourceFailureCap,
            'accountBucketTakes' => $budgets->accountBucketTakes,
            'accountBucketSeconds' => $budgets->accountBucketSeconds,
            'proofAccountWindow' => $budgets->proofAccountWindow,
            'proofAccountDay' => $budgets->proofAccountDay,
            'proofProofWindow' => $budgets->proofProofWindow,
            'proofProofDay' => $budgets->proofProofDay,
            'newDevicesPerDay' => $budgets->newDevicesPerDay,
            'crossAccountFailureCap' => $budgets->crossAccountFailureCap,
            'permitDeadlineSeconds' => $budgets->permitDeadlineSeconds,
            'permitGraceSeconds' => $budgets->permitGraceSeconds,
        ];
    }

    private static function config(bool $withBindingKey = true): RateLimiterConfig
    {
        return new RateLimiterConfig(EngineLab::KEY_SECRET, EngineLab::FINGERPRINT_SECRET, EngineLab::ENV, bindingKeySecret: $withBindingKey ? EngineLab::BINDING_SECRET : null);
    }

    private static function engineOf(CompositeRateLimiterRuntimeInterface $runtime): RateLimiterEngine
    {
        $engine = (new \ReflectionProperty(CompositeRateLimiterRuntime::class, 'scoreRuntime'))->getValue($runtime);
        self::assertInstanceOf(RateLimiterEngine::class, $engine);

        return $engine;
    }

    private static function admissionOf(CompositeRateLimiterRuntimeInterface $runtime): ?AdmissionRuntime
    {
        $admission = (new \ReflectionProperty(RateLimiterEngine::class, 'admission'))->getValue(self::engineOf($runtime));
        self::assertTrue($admission === null || $admission instanceof AdmissionRuntime);

        return $admission;
    }

    /** @return array<string, int> */
    private static function budgetsOf(AdmissionRuntime $runtime): array
    {
        $budgets = (new \ReflectionProperty(AdmissionRuntime::class, 'budgets'))->getValue($runtime);
        self::assertInstanceOf(AdmissionBudgetsDTO::class, $budgets);

        return self::fields($budgets);
    }

    // ---- DORMANT, new meaning (§7.6.2) ------------------------------------------------------------------------------------------

    public function testThePackageDefaultsAreTheDecidedValues(): void
    {
        self::assertSame(self::PACKAGE_CONSTANTS, self::fields(AdmissionBudgetsDTO::packageDefaults()));
    }

    public function testTheBuilderConstructsTheRuntimeWithThePackageConstantsForTheVariant(): void
    {
        $runtime = RateLimiterBuilder::fromFullCapabilityStore(self::config(), new AdmissionInMemoryReferenceStore(new FixedClock()), new RecordingFailureSignalEmitter())
            ->withPolicy(new LoginProtectionAdmissionPolicy())
            ->build();
        $admission = self::admissionOf($runtime);

        self::assertNotNull($admission);
        self::assertSame(self::PACKAGE_CONSTANTS, self::budgetsOf($admission));
    }

    public function testACompositionWithoutAnOptedPolicyHasNoRuntimeWithOrWithoutABindingKey(): void
    {
        foreach ([true, false] as $withBindingKey) {
            $runtime = RateLimiterBuilder::fromFullCapabilityStore(self::config($withBindingKey), new AdmissionInMemoryReferenceStore(new FixedClock()), new RecordingFailureSignalEmitter())
                ->withPolicy(new LoginProtectionPolicy())
                ->build();

            self::assertNull(self::admissionOf($runtime), 'LT-31: no runtime object without a policy that declares the capability.');
        }
    }

    public function testNoPublicPathOfTheConfigurationOrTheBuilderSetsABudget(): void
    {
        $parameters = array_map(
            static fn(\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(RateLimiterConfig::class, '__construct'))->getParameters(),
        );
        self::assertSame(
            ['keySecret', 'fingerprintSecret', 'environmentScope', 'previousKeySecret', 'previousFingerprintSecret', 'bindingKeySecret', 'previousBindingKeySecret'],
            $parameters,
        );

        $builderMethods = array_map(
            static fn(\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(RateLimiterBuilder::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        sort($builderMethods);
        self::assertSame(['__construct', 'build', 'buildOperationalReader', 'fromFullCapabilityStore', 'withClock', 'withDeviceIdentityResolver', 'withPolicy', 'withSimpleThrottlePolicy'], $builderMethods);

        foreach ([RateLimiterConfig::class, RateLimiterBuilder::class] as $class) {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getParameters() as $parameter) {
                    self::assertStringNotContainsString('AdmissionBudgetsDTO', (string) $parameter->getType(), "{$class}::{$method->getName()}");
                }
            }
        }
    }

    public function testDetectorABuilderThatTakesOneBudgetFromElsewhereFailsTheFieldComparison(): void
    {
        $mutant = self::builderMutant(
            'AdmissionBudgetsDTO::packageDefaults()',
            'new \\Maatify\\RateLimiter\\DTO\\AdmissionBudgetsDTO(600, 25, 4, 4, 1800, 10, 60, 6, 30, 10, 4, 30, 600)',
        );
        /** @var RateLimiterBuilder $builder */
        $builder = $mutant::fromFullCapabilityStore(self::config(), new AdmissionInMemoryReferenceStore(new FixedClock()), new RecordingFailureSignalEmitter());
        $admission = self::admissionOf($builder->withPolicy(new LoginProtectionAdmissionPolicy())->build());

        self::assertNotNull($admission);
        self::assertNotSame(self::PACKAGE_CONSTANTS, self::budgetsOf($admission), 'DETECTOR: a different budget is visible in the comparison');
    }

    /**
     * `RateLimiterBuilder` with one exact replacement, loaded under another class name (the `ServiceMutant` pattern for the
     * builder). The anchor must occur exactly once, so the detector cannot silently stop mutating.
     *
     * @return class-string
     */
    private static function builderMutant(string $search, string $replacement): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Builder/RateLimiterBuilder.php');
        self::assertSame(1, substr_count($source, $search), "The anchor {$search} must occur exactly once in the builder.");
        $name = 'RateLimiterBuilderMutant' . bin2hex(random_bytes(4));
        $source = str_replace($search, $replacement, $source);
        $source = (string) preg_replace('/^(final )?class RateLimiterBuilder\b/m', 'class ' . $name, $source, 1);
        $source = str_replace('new self(', 'new static(', $source);
        $file = sys_get_temp_dir() . '/' . $name . '.php';
        file_put_contents($file, $source);
        try {
            require $file;
        } finally {
            unlink($file);
        }
        $class = 'Maatify\\RateLimiter\\Builder\\' . $name;
        self::assertTrue(class_exists($class));

        return $class;
    }

    // ---- REG through the builder ------------------------------------------------------------------------------------------------

    public function testTheVariantNeedsAStoreWithTheAdmissionCapability(): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('requires AdmissionReservationStoreInterface');

        RateLimiterBuilder::fromFullCapabilityStore(self::config(), new FullCapabilityInMemoryStore(new FixedClock()), new RecordingFailureSignalEmitter())
            ->withPolicy(new LoginProtectionAdmissionPolicy())
            ->build();
    }

    public function testTheVariantNeedsABindingKey(): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage('requires a binding key secret');

        RateLimiterBuilder::fromFullCapabilityStore(self::config(false), new AdmissionInMemoryReferenceStore(new FixedClock()), new RecordingFailureSignalEmitter())
            ->withPolicy(new LoginProtectionAdmissionPolicy())
            ->build();
    }

    // ---- The variant (OD-5, OD-5.1) ---------------------------------------------------------------------------------------------

    public function testTheVariantIsLoginProtectionUnderTheSameNameWithTheCapabilityAndTheDeclaration(): void
    {
        $variant = new LoginProtectionAdmissionPolicy();
        $login = new LoginProtectionPolicy();

        self::assertInstanceOf(AdmissionReservationPolicyInterface::class, $variant);
        self::assertInstanceOf(PostPunishmentReentryPolicyInterface::class, $variant);
        self::assertInstanceOf(KnownDeviceAccountAllowancePolicyInterface::class, $variant);
        self::assertSame('login_protection', $variant->getName());
        self::assertSame([...$login->getCapabilities(), PolicyCapabilityEnum::ADMISSION_RESERVATION], $variant->getCapabilities());
        self::assertSame(AdmissionHostModeEnum::PRE_AND_OUT, $variant->getAdmissionHostMode());
        self::assertSame('login', $variant->getAdmissionBudgetGroup());
        foreach (['getScoreThresholds', 'getScoreDeltas', 'getFailureMode', 'getBudgetConfig', 'getKnownDeviceAccountAllowance', 'getFailureFallbackConfiguration'] as $getter) {
            self::assertEquals($login->{$getter}(), $variant->{$getter}(), $getter);
        }
    }

    // ---- The fence method (OD-7) ------------------------------------------------------------------------------------------------

    public function testThePackageMethodOpensTheFenceAndReturnsItsValue(): void
    {
        $lab = EngineLab::throughBuilder(openFence: false);
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $lab->engine);
        self::assertInstanceOf(AdmissionRuntime::class, $lab->runtime);

        self::assertFalse($lab->runtime->fenceOpen(EngineLab::GROUP));
        self::assertSame(1, $lab->engine->openAdmissionFence(EngineLab::GROUP));
        self::assertTrue($lab->runtime->fenceOpen(EngineLab::GROUP));
        self::assertSame(2, $lab->engine->openAdmissionFence(EngineLab::GROUP));
    }

    public function testOpeningTheFenceWithoutAnAdmissionRuntimeIsATypedError(): void
    {
        $runtime = RateLimiterBuilder::fromFullCapabilityStore(self::config(), new AdmissionInMemoryReferenceStore(new FixedClock()), new RecordingFailureSignalEmitter())->build();
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $runtime);

        $this->expectException(RateLimiterException::class);
        $runtime->openAdmissionFence(EngineLab::GROUP);
    }

    /**
     * A closed fence = the S0 path (CVH-1 in PHPUnit): the variant, built with the golden's secrets, plays scenario A of
     * `AdmissionBuilderS0GoldensTest` and gives the decisions of the `login_protection` golden recorded on the base; the same
     * with the fence open differs (control).
     */
    public function testWithTheFenceClosedTheVariantDecidesAsTheGoldenOfLoginProtection(): void
    {
        /** @var array<string, array<string, array{decisions: string, state: string}>> $golden */
        $golden = (new \ReflectionClassConstant(AdmissionBuilderS0GoldensTest::class, 'GOLDEN'))->getValue();

        self::assertSame($golden['login_protection']['A']['decisions'], self::scenarioAWithTheVariant(false));
        self::assertNotSame($golden['login_protection']['A']['decisions'], self::scenarioAWithTheVariant(true), 'CONTROL: an open fence changes decisions');
    }

    private static function scenarioAWithTheVariant(bool $openFence): string
    {
        $clock = new FixedClock('2026-01-01 12:00:00');
        $config = new RateLimiterConfig('lab-secret', 'lab-fp-secret', 'prod', bindingKeySecret: EngineLab::BINDING_SECRET);
        $runtime = RateLimiterBuilder::fromFullCapabilityStore($config, new AdmissionInMemoryReferenceStore($clock), new RecordingFailureSignalEmitter())
            ->withClock($clock)
            ->withPolicy(new LoginProtectionAdmissionPolicy())
            ->build();
        if ($openFence) {
            self::assertInstanceOf(AdmissionFenceControlInterface::class, $runtime);
            $runtime->openAdmissionFence('login');
        }
        $decisions = '';
        /** @var \Generator<int, array{\Maatify\RateLimiter\DTO\RateLimitContextDTO, \Maatify\RateLimiter\Command\RateLimitCommand, string}> $steps */
        $steps = (new \ReflectionMethod(AdmissionBuilderS0GoldensTest::class, 'scenarioA'))->invoke(null, $clock, 'login_protection');
        foreach ($steps as [$context, $command, $advance]) {
            try {
                $result = $runtime->limit($context, $command);
                $decisions .= $result->decision === RateLimitResultDTO::DECISION_ALLOW ? 'A' : ($result->decision === RateLimitResultDTO::DECISION_HARD_BLOCK ? 'H' : 'S');
            } catch (RateLimiterException) {
                $decisions .= 'X';
            }
            $clock->setNow($clock->now()->modify($advance));
        }

        return $decisions;
    }

    // ---- 5-b3b engine gates through the builder, on the package constants (LT-28) ----------------------------------------------

    /** Spend the source: 24 distinct unknown accounts, each `checkOnly` followed by its success outcome with the permit. */
    private static function exhaustTheSource(EngineLab $lab, string $ip = self::IP): void
    {
        for ($i = 0; $i < 24; $i++) {
            $context = $lab->context('src-' . $i, $ip);
            $r = $lab->check($context);
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $r->decision, "admission {$i}");
            self::assertNotNull(EngineLab::permit($r));
            $lab->outcome($context, true, EngineLab::permit($r));
        }
    }

    public function testSc1TheTwentyFifthAdmissionFromOneSourceIsASourceCapacityRefusal(): void
    {
        $lab = EngineLab::throughBuilder();
        self::exhaustTheSource($lab);

        $r = $lab->check($lab->context('src-24', self::IP));
        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $r->decision);
        self::assertSame(1, $r->blockLevel);
        self::assertSame('source_capacity', EngineLab::cause($r));
        self::assertSame('source', EngineLab::scope($r));
        self::assertGreaterThan(0, $r->retryAfter);
        self::assertLessThanOrEqual(600, $r->retryAfter);
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $lab->check($lab->context('src-24', '203.0.113.41'))->decision, 'CONTROL: another source');
    }

    public function testLaneAVerifiedCredentialIsAdmittedFromAnExhaustedSourceAndATamperedOneIsNot(): void
    {
        $lab = EngineLab::throughBuilder();
        self::exhaustTheSource($lab);
        $credential = $lab->mint('owner', 'inst-owner');
        $tampered = substr($credential, 0, -2) . (str_ends_with($credential, 'AA') ? 'BB' : 'AA');

        self::assertSame('source_capacity', EngineLab::cause($lab->check($lab->context('owner', self::IP, 'inst-owner', $tampered))));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $lab->check($lab->context('owner', self::IP, 'inst-owner', $credential))->decision);
    }

    public function testThePermitIsRequiredAndClaimedOnce(): void
    {
        $lab = EngineLab::throughBuilder();
        $context = $lab->context('acct-permit', self::IP);
        $permit = EngineLab::permit($lab->check($context));
        self::assertNotNull($permit);

        $firstCause = EngineLab::cause($lab->outcome($context, true, $permit));
        self::assertFalse($firstCause !== null && str_starts_with($firstCause, 'permit_'), 'the first claim is accepted');
        $second = $lab->outcome($context, true, $permit);
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $second->decision);
        self::assertSame(0, $second->blockLevel);
        self::assertSame('permit_duplicate', EngineLab::cause($second));
        self::assertSame('permit', EngineLab::scope($second));

        $this->expectException(RateLimiterException::class);
        $lab->outcome($lab->context('acct-permit', self::IP), false, null);
    }

    public function testRollbackABuildWithoutTheVariantIgnoresTheAdmissionState(): void
    {
        $lab = EngineLab::throughBuilder();
        self::exhaustTheSource($lab);
        self::assertSame('source_capacity', EngineLab::cause($lab->check($lab->context('src-24', self::IP))));

        $old = (new RateLimiterBuilder(self::config(), $lab->store, $lab->store->correlationStore(), new \Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore(), new RecordingFailureSignalEmitter()))
            ->withClock($lab->clock)
            ->build();
        self::assertNull(self::admissionOf($old));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $old->limit($lab->context('src-24', self::IP), \Maatify\RateLimiter\Command\RateLimitCommand::checkOnly('login_protection'))->decision);
    }
}
