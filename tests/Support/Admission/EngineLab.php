<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
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

    /**
     * @param list<BlockPolicyInterface> $policies registered besides the opted Login policy
     * @param class-string $runtimeClass
     * @param class-string $engineClass
     */
    public function __construct(
        ?AdmissionBudgetsDTO $budgets = null,
        bool $openFence = true,
        array $policies = [],
        string $runtimeClass = AdmissionRuntime::class,
        string $engineClass = RateLimiterEngine::class,
        bool $registerOptedPolicy = true,
        ?AdmissionPermitStoreInterface $admissionInner = null,
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
        $pipeline = new EvaluationPipeline(
            $this->store,
            $this->store->correlationStore(),
            new BudgetTracker($this->store, $this->clock),
            new AntiEquilibriumGate($this->store->correlationStore()),
            new DecayCalculator($this->clock),
            new EphemeralBucket($this->store->correlationStore()),
            self::KEY_SECRET,
            self::ENV,
            $this->clock,
        );
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
    public function mint(string $account, string $instance, int $lifetime = 86400, ?int $epoch = null, string $group = self::GROUP, ?string $subject = null, ?AdmissionCredentialCodec $codec = null): string
    {
        $claims = new AdmissionCredentialDTO(
            $subject ?? $account,
            [$group],
            $instance,
            'jti-' . $account . '-' . $instance,
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
