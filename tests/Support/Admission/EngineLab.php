<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionAdmissionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\AdmissionBudgetsDTO;
use Maatify\RateLimiter\DTO\AdmissionCredentialDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Repository\AdmissionPermitStoreInterface;
use Maatify\RateLimiter\Service\AdmissionCredentialCodec;
use Maatify\RateLimiter\Service\AdmissionRuntime;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\CircuitBreaker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FailureModeResolver;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Service\AdmissionBindingControlInterface;
use Maatify\RateLimiter\Service\AdmissionBindingEpochInterface;
use Maatify\RateLimiter\Service\AdmissionFenceControlInterface;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntime;
use Maatify\RateLimiter\Service\RateLimiterEngine;
use Maatify\RateLimiter\Service\RateLimiterRuntimeInterface;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;

/**
 * The real engine built DIRECTLY (the builder cannot opt in, contract §1.1) over the reference store, the real pipeline, the
 * real resolver and the real `AdmissionRuntime`, plus the Host model of the study (credential minting with the codec, the
 * password-change bump). Study budget values live here and in the tests only (never in `src/`).
 */
final class EngineLab
{
    public const KEY_SECRET = 'key-secret';
    public const FINGERPRINT_SECRET = 'fingerprint-secret';
    public const BINDING_SECRET = 'binding-secret';
    public const ENV = 'prod';
    public const GROUP = 'login';
    public const POLICY = 'login_protection';
    public const T0 = 1767268800;

    public FixedClock $clock;
    public AdmissionInMemoryReferenceStore $store;
    public FaultInjectingAdmissionStore $admissionStore;
    public AdmissionCredentialCodec $codec;
    /** An `AdmissionRuntime` or a mutation of it (detectors). */
    public object $runtime;

    /** A `RateLimiterEngine` or a mutation of it (detectors). */
    public RateLimiterRuntimeInterface&AdmissionBindingControlInterface&AdmissionBindingEpochInterface $engine;
    public RecordingFailureSignalEmitter $emitter;
    public EvaluationPipeline $pipeline;

    /** The pipeline's score store when the lab records hard-block cycles (`$recordCycles`); the admission store stays separate. */
    public ?RecordingCycleStore $cycles = null;

    /**
     * @param list<BlockPolicyInterface> $policies registered besides the opted Login policy
     * @param class-string $runtimeClass
     * @param class-string $engineClass
     * @param class-string<EvaluationPipeline> $pipelineClass the real pipeline or a 5-b3a seam mutation of it (`PipelineMutant`)
     * @param bool $recordCycles the pipeline runs on a cycle-recording score store (`$cycles`) and its own correlation store
     */
    public function __construct(
        ?AdmissionBudgetsDTO $budgets = null,
        bool $openFence = true,
        array $policies = [],
        string $runtimeClass = AdmissionRuntime::class,
        string $engineClass = RateLimiterEngine::class,
        bool $registerOptedPolicy = true,
        ?AdmissionPermitStoreInterface $admissionInner = null,
        string $pipelineClass = EvaluationPipeline::class,
        bool $recordCycles = false,
    ) {
        if ($runtimeClass !== AdmissionRuntime::class && $engineClass === RateLimiterEngine::class) {
            // A mutation of the runtime is not an `AdmissionRuntime`: the engine under test accepts any object in its place.
            $engineClass = ServiceMutant::load('RateLimiterEngine', [], [
                ['private readonly ?AdmissionRuntime $admission = null,', 'private readonly ?object $admission = null,'],
                ["        AdmissionRuntime \$admission,\n        BlockPolicyInterface \$policy,", "        object \$admission,\n        BlockPolicyInterface \$policy,"],
                ['private function requireAdmission(): AdmissionRuntime', 'private function requireAdmission(): object'],
            ]);
        }
        $this->clock = new FixedClock('@' . self::T0);
        $this->store = new AdmissionInMemoryReferenceStore($this->clock);
        $this->admissionStore = new FaultInjectingAdmissionStore($admissionInner ?? $this->store);
        $this->codec = new AdmissionCredentialCodec(self::BINDING_SECRET);
        $this->emitter = new RecordingFailureSignalEmitter();
        $this->runtime = new $runtimeClass(
            $this->admissionStore,
            $this->codec,
            $budgets ?? self::budgets(),
            $this->clock,
            self::KEY_SECRET,
            self::ENV,
        );
        $scores = $this->store;
        $correlation = $this->store->correlationStore();
        if ($recordCycles) {
            $scores = $this->cycles = new RecordingCycleStore($this->clock);
            $correlation = new RecordingCorrelationStore($this->clock);
        }
        $pipeline = new $pipelineClass(
            $scores,
            $correlation,
            new BudgetTracker($scores, $this->clock),
            new AntiEquilibriumGate($correlation),
            new DecayCalculator($this->clock),
            new EphemeralBucket($correlation),
            self::KEY_SECRET,
            self::ENV,
            $this->clock,
        );
        $this->pipeline = $pipeline;
        $all = $registerOptedPolicy ? [new OptedEngineLoginPolicy(self::GROUP), ...$policies] : $policies;
        $engine = new $engineClass(
            new DeviceIdentityResolver(new FingerprintHasher(self::FINGERPRINT_SECRET)),
            $pipeline,
            new CircuitBreaker(new InMemoryCircuitBreakerStore(), $this->emitter, $this->clock),
            new FailureModeResolver(),
            $this->emitter,
            $this->clock,
            $all,
            $this->runtime,
        );
        if (! $engine instanceof RateLimiterRuntimeInterface || ! $engine instanceof AdmissionBindingControlInterface || ! $engine instanceof AdmissionBindingEpochInterface) {
            throw new \LogicException('The engine under test must implement the binding interfaces.');
        }
        $this->engine = $engine;
        if ($openFence) {
            $this->openFence();
        }
    }

    /**
     * The same lab composed through the public builder (stage 5-c, LT-28): `RateLimiterBuilder` with the Login opt-in variant
     * over the reference store, so the runtime, its budgets (the package constants) and the codec are the ones the builder
     * constructs; the fence is opened with the package method. `admissionStore` wraps the same store but is not on the
     * engine's path, so its `admitted` log stays empty in a builder lab: the B1 recount and NP-IN stay on the direct lab.
     * A shared `$store` and `$clock` give several builds over one state (a key rotation, a rollback).
     */
    public static function throughBuilder(
        bool $openFence = true,
        ?AdmissionInMemoryReferenceStore $store = null,
        ?FixedClock $clock = null,
        string $keySecret = self::KEY_SECRET,
        ?string $previousKeySecret = null,
    ): self {
        $lab = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $lab->clock = $clock ?? new FixedClock('@' . self::T0);
        $lab->store = $store ?? new AdmissionInMemoryReferenceStore($lab->clock);
        $lab->admissionStore = new FaultInjectingAdmissionStore($lab->store);
        $lab->codec = new AdmissionCredentialCodec(self::BINDING_SECRET);
        $lab->emitter = new RecordingFailureSignalEmitter();
        $config = new RateLimiterConfig($keySecret, self::FINGERPRINT_SECRET, self::ENV, $previousKeySecret, bindingKeySecret: self::BINDING_SECRET);
        $runtime = (new RateLimiterBuilder($config, $lab->store, $lab->store->correlationStore(), new InMemoryCircuitBreakerStore(), $lab->emitter))
            ->withClock($lab->clock)
            ->withPolicy(new LoginProtectionAdmissionPolicy())
            ->build();
        if (! $runtime instanceof CompositeRateLimiterRuntime || ! $runtime instanceof AdmissionFenceControlInterface) {
            throw new \LogicException('The builder must return the composite runtime with the fence control.');
        }
        $lab->engine = $runtime;
        $engine = (new \ReflectionProperty(CompositeRateLimiterRuntime::class, 'scoreRuntime'))->getValue($runtime);
        if (! $engine instanceof RateLimiterEngine) {
            throw new \LogicException('The composite runtime must wrap the engine.');
        }
        $admission = (new \ReflectionProperty(RateLimiterEngine::class, 'admission'))->getValue($engine);
        $pipeline = (new \ReflectionProperty(RateLimiterEngine::class, 'pipeline'))->getValue($engine);
        if (! $admission instanceof AdmissionRuntime || ! $pipeline instanceof EvaluationPipeline) {
            throw new \LogicException('The builder constructed no admission runtime for the opted policy.');
        }
        $lab->runtime = $admission;
        $lab->pipeline = $pipeline;
        if ($openFence) {
            $runtime->openAdmissionFence(self::GROUP);
        }

        return $lab;
    }

    /** Study values of the contract §0.1 table (DEC-025), as the tests' arguments; `D` / `G` from G1 M05. */
    public static function budgets(
        int $sourceCapacity = 24,
        int $sourceFailureCap = 4,
        int $bucketTakes = 4,
        int $proofAccountWindow = 10,
        int $proofProofWindow = 6,
        int $newDevices = 10,
        int $crossAccountFailureCap = 4,
    ): AdmissionBudgetsDTO {
        return new AdmissionBudgetsDTO(
            windowSeconds: 600,
            sourceCapacity: $sourceCapacity,
            sourceFailureCap: $sourceFailureCap,
            accountBucketTakes: $bucketTakes,
            accountBucketSeconds: 1800,
            proofAccountWindow: $proofAccountWindow,
            proofAccountDay: 60,
            proofProofWindow: $proofProofWindow,
            proofProofDay: 30,
            newDevicesPerDay: $newDevices,
            crossAccountFailureCap: $crossAccountFailureCap,
            permitDeadlineSeconds: 30,
            permitGraceSeconds: 600,
        );
    }

    public function openFence(string $group = self::GROUP): void
    {
        $this->admissionStore->bumpBindingEpoch(AdmissionRuntime::fenceKey(self::ENV, $group, self::KEY_SECRET), null);
    }

    public function at(int $secondsFromStart): void
    {
        $this->clock->setNow((new \DateTimeImmutable('@' . (self::T0 + $secondsFromStart)))->setTimezone($this->clock->getTimezone()));
    }

    public function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    /** Host model: a credential for `$account` on `$instance`, bound to the account's current epoch unless given. */
    public function mint(string $account, string $instance, int $lifetime = 86400, ?int $epoch = null, string $group = self::GROUP, ?string $subject = null, ?AdmissionCredentialCodec $codec = null, ?string $jti = null): string
    {
        $claims = new AdmissionCredentialDTO(
            $subject ?? $account,
            [$group],
            $instance,
            $jti ?? 'jti-' . $account . '-' . $instance,
            $this->now() + $lifetime,
            $this->now(),
            $epoch ?? $this->engine->bindingEpoch($account),
        );

        return ($codec ?? $this->codec)->encode($claims);
    }

    public function context(string $account, string $ip = '198.51.100.7', ?string $instance = null, ?string $credential = null, string $device = 'd1', bool $known = false, bool $trusted = false): RateLimitContextDTO
    {
        return new RateLimitContextDTO(
            $ip,
            'Mozilla/5.0 Chrome/120.0 Safari/537.36',
            $account,
            ['device' => $device],
            null,
            $trusted,
            [],
            $known,
            null,
            clientInstanceId: $instance,
            admissionCredential: $credential,
        );
    }

    public function check(RateLimitContextDTO $context, string $policy = self::POLICY): RateLimitResultDTO
    {
        return $this->engine->limit($context, RateLimitCommand::checkOnly($policy));
    }

    public function outcome(RateLimitContextDTO $context, bool $success, ?string $permit, string $policy = self::POLICY): RateLimitResultDTO
    {
        return $this->engine->limit($context, new RateLimitCommand($policy, 1, false, ! $success, $success, $permit));
    }

    public static function cause(RateLimitResultDTO $r): ?string
    {
        return $r->metadata?->cause;
    }

    public static function scope(RateLimitResultDTO $r): ?string
    {
        return $r->metadata?->context?->scope;
    }

    public static function permit(RateLimitResultDTO $r): ?string
    {
        return $r->metadata?->permit;
    }
}
