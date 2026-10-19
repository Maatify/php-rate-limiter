<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Enum\AdmissionLaneEnum;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FingerprintHasher;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;

/**
 * The study's `Lab` (investigations/workstream-b-correlation/lib/bootstrap.php) re-expressed on the package test
 * stores: same secret, same environment, same clock origin and the same resolver pairing, so the key prefixes of the
 * archived G5 v2 runs can be compared byte for byte. It runs the real pipeline and the real resolver.
 */
final class FamilyLab
{
    public const SECRET = 'lab-secret';

    public FixedClock $clock;
    public RecordingCycleStore $store;
    public RecordingCorrelationStore $corr;
    public EvaluationPipeline $pipeline;
    public DeviceIdentityResolver $resolver;
    public int $t0;

    /**
     * @param class-string<EvaluationPipeline> $pipelineClass
     */
    public function __construct(
        public BlockPolicyInterface $policy,
        public ?AdmissionLaneEnum $lane,
        string $pipelineClass = EvaluationPipeline::class,
        public string $secret = self::SECRET,
        ?string $previousSecret = null,
        ?FamilyLab $shareStateWith = null,
    ) {
        if ($shareStateWith !== null) {
            $this->clock = $shareStateWith->clock;
            $this->store = $shareStateWith->store;
            $this->corr = $shareStateWith->corr;
        } else {
            $this->clock = new FixedClock('2026-01-01 12:00:00');
            $this->store = new RecordingCycleStore($this->clock);
            $this->corr = new RecordingCorrelationStore($this->clock);
        }
        $this->t0 = (new \DateTimeImmutable('2026-01-01 12:00:00'))->getTimestamp();
        $this->pipeline = new $pipelineClass(
            $this->store,
            $this->corr,
            new BudgetTracker($this->store, $this->clock),
            new AntiEquilibriumGate($this->corr),
            new DecayCalculator($this->clock),
            new EphemeralBucket($this->corr),
            $secret,
            'prod',
            $this->clock,
            $previousSecret,
        );
        $this->resolver = new DeviceIdentityResolver(
            new FingerprintHasher($secret),
            $previousSecret !== null ? new FingerprintHasher($previousSecret) : null,
        );
    }

    public function at(int $secondsFromStart): void
    {
        $this->clock->setNow((new \DateTimeImmutable('@' . ($this->t0 + $secondsFromStart)))->setTimezone($this->clock->getTimezone()));
    }

    /**
     * One independent public command through the real resolver and pipeline.
     *
     * @param 'check'|'ok'|'fail' $cmd
     */
    public function result(RateLimitContextDTO $context, string $cmd): RateLimitResultDTO
    {
        $name = $this->policy->getName();
        $command = match ($cmd) {
            'check' => RateLimitCommand::checkOnly($name),
            'fail' => RateLimitCommand::recordFailure($name),
            'ok' => RateLimitCommand::recordSuccess($name),
        };

        return $this->pipeline->process($this->policy, $context, $command, $this->resolver->resolve($context), $this->lane);
    }

    /**
     * @param 'check'|'ok'|'fail' $cmd
     * @return array{d: string, lvl: int}
     */
    public function run(RateLimitContextDTO $context, string $cmd): array
    {
        $r = $this->result($context, $cmd);

        return [
            'd' => $r->decision === RateLimitResultDTO::DECISION_ALLOW ? 'A' : ($r->decision === RateLimitResultDTO::DECISION_HARD_BLOCK ? 'H' : 'S'),
            'lvl' => $r->blockLevel,
        ];
    }
}
