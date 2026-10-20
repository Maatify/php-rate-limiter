<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission\Archive;

use Maatify\RateLimiter\DTO\AdmissionLaneDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;

/**
 * @phpstan-import-type DumpRow from ArchiveData
 * @phpstan-import-type Effect from ArchiveData
 * @phpstan-type Step array{d: string, lane: string, presented: bool, reach: string, kind: string, cause: string, scope: ?string, engineLane: ?string, context: RateLimitContextDTO, permit: ?string, outcome: string, dropped: bool}
 *
 * One replay through the REAL engine, one row at a time (g4v2Replay / g4v2Step, g4v2.php:307-414, ported to the production
 * composition): the study's gate and lane code are not used; the lane, the reservation, the permit and the outcome hooks are
 * the engine's. The Host model supplies the credential and the instance id; the context is the archived context plus those two.
 *
 * The input-shape assertion IN (g4v2AssertInput, g4v2.php:30-41, the F131-01 correction) runs on every row before it is
 * replayed: a row that declares an instance id must carry it in the archived context's `sessionDeviceId`, and the context the
 * engine receives must carry it in `clientInstanceId`. A violation is an `InputViolation`, never a skipped row.
 *
 * Host behaviours registered by the plan: a share of outcomes dropped by the deterministic rule of §4 (NM-10), a probe factor
 * `rho` (extra `checkOnly` per attempt, NM-5), a share of abandoned attempts (NM-5), and an optional observer called after
 * every admitted attempt (NM-10 (b) sends the six LT-16 cases from it).
 */
final class ReplaySession
{
    public string $dec = '';
    public string $lanes = '';
    public string $reach = '';
    public string $kinds = '';
    /** @var list<int> */
    public array $ts = [];
    /** @var array<string, int> */
    public array $causes = [];
    /** @var array<string, int> */
    public array $attackCauses = [];
    public readonly StudyHost $host;
    private int $outcomeIndex = 0;
    private int $attemptIndex = 0;

    /** @param (\Closure(Step, ReplaySession): void)|null $observer */
    public function __construct(
        public readonly EngineLab $lab,
        public readonly string $flow = 'pre+out',
        private readonly float $dropShare = 0.0,
        private readonly int $rho = 1,
        private readonly float $abandonShare = 0.0,
        bool $renew = true,
        bool $passwordBinding = true,
        private readonly ?\Closure $observer = null,
        bool $binding = true,
    ) {
        $this->host = new StudyHost($lab, ArchiveData::get()->constants(), $renew, $passwordBinding, $binding);
    }

    /**
     * IN (g4v2.php:30-41): every row that declares an instance id carries it in the archived context's `sessionDeviceId`.
     *
     * @param list<DumpRow> $rows
     */
    public static function assertInput(array $rows): void
    {
        foreach ($rows as $i => $r) {
            if (isset($r['effects']) || ($r['inst'] ?? null) === null) {
                continue;
            }
            if (($r['ctx']['sessionDeviceId'] ?? null) !== $r['inst']) {
                throw new InputViolation("row $i at t={$r['t']}: inst {$r['inst']} but sessionDeviceId " . var_export($r['ctx']['sessionDeviceId'] ?? null, true));
            }
        }
    }

    /** @param list<DumpRow> $rows */
    public function replay(array $rows): self
    {
        self::assertInput($rows);
        foreach ($rows as $r) {
            if (isset($r['effects'])) {
                $this->event($r['effects'], $r['t']);
            } else {
                $this->step($r);
            }
        }

        return $this;
    }

    /** @param list<Effect> $effects */
    public function event(array $effects, int $t): void
    {
        $this->host->apply($effects, EngineLab::T0 + $t);
    }

    /**
     * @param DumpRow $r
     * @return Step
     */
    public function step(array $r): array
    {
        self::assertInput([$r]);
        if (! isset($r['ctx'], $r['holder'], $r['kind'], $r['outcome'], $r['own'])) {
            throw new \UnexpectedValueException('Malformed dumped row.');
        }
        $lab = $this->lab;
        $holder = $r['holder'];
        $lab->at($r['t']);
        $now = $lab->now();
        $c = $r['ctx'];
        $acct = $c['accountId'];
        $inst = $r['inst'] ?? null;
        $token = $acct === null ? null : $this->host->token($holder, $inst, $acct, $now);
        $context = new RateLimitContextDTO(
            $c['ip'],
            $c['ua'],
            $acct,
            $c['clientFingerprint'],
            $c['sessionDeviceId'],
            $c['isSessionTrusted'],
            $c['headers'],
            $c['isDevicePreviouslyVerifiedForAccount'],
            $c['correlationId'],
            clientInstanceId: $inst,
            admissionCredential: $token === null ? null : $this->host->credential($token),
        );
        if ($inst !== null && $context->clientInstanceId !== $inst) {
            throw new InputViolation("row at t={$r['t']}: inst {$inst} is not the engine context's clientInstanceId");
        }
        $engineLane = $this->engineLane($context);
        for ($p = 1; $p < $this->rho; $p++) {
            $lab->check($context);
        }
        $abandoned = StreamReplay::selects($this->attemptIndex++, $this->abandonShare);
        $denied = false;
        $reached = false;
        $dropped = false;
        $cause = '-';
        $scope = null;
        $blocking = RateLimitResultDTO::DECISION_ALLOW;
        $result = $lab->check($context);
        $permit = EngineLab::permit($result);
        if ($result->decision !== RateLimitResultDTO::DECISION_ALLOW) {
            $denied = true;
            $blocking = $result->decision;
            $cause = (string) (EngineLab::cause($result) ?? ($result->decision === RateLimitResultDTO::DECISION_SOFT_BLOCK ? 'soft' : 'hard'));
            $scope = EngineLab::scope($result);
        } elseif (! $abandoned) {
            $reached = true;
            if ($acct !== null && $inst !== null) {
                $this->host->observe($holder, $inst, $acct, $token !== null, $r['outcome'], $now, $token);
            }
            $dropped = $this->flow === 'pre+out' && StreamReplay::selects($this->outcomeIndex++, $this->dropShare);
            if ($this->flow === 'pre+out' && ! $dropped) {
                $recorded = $lab->outcome($context, $r['outcome'] === 'ok', $permit);
                if ($recorded->decision !== RateLimitResultDTO::DECISION_ALLOW) {
                    $denied = true;
                    $blocking = $recorded->decision;
                    $cause = (string) (EngineLab::cause($recorded) ?? 'penalty');
                    $scope = EngineLab::scope($recorded);
                }
            }
        }
        $step = [
            'd' => ! $denied ? 'A' : ($blocking === RateLimitResultDTO::DECISION_SOFT_BLOCK ? 'S' : 'H'),
            'lane' => $engineLane,
            'presented' => $token !== null,
            'reach' => $reached ? '1' : '0',
            'kind' => $r['kind'] === 'X' ? ($r['own'] ? 'o' : 'X') : $r['kind'],
            'cause' => $cause,
            'scope' => $scope,
            'engineLane' => $permit === null ? null : $this->permitLane($permit),
            'context' => $context,
            'permit' => $permit,
            'outcome' => $r['outcome'],
            'dropped' => $dropped || ($reached && $this->flow !== 'pre+out'),
        ];
        if ($step['engineLane'] !== null && $step['engineLane'] !== $step['lane']) {
            throw new \LogicException("row at t={$r['t']}: the engine resolved lane {$step['lane']} but admitted on {$step['engineLane']}");
        }
        $this->dec .= $step['d'];
        $this->lanes .= $step['lane'];
        $this->reach .= $step['reach'];
        $this->kinds .= $step['kind'];
        $this->ts[] = $r['t'];
        if ($denied && $r['kind'] !== 'X') {
            $this->causes[$cause] = ($this->causes[$cause] ?? 0) + 1;
        } elseif ($denied) {
            $this->attackCauses[$cause] = ($this->attackCauses[$cause] ?? 0) + 1;
        }
        if ($reached && $this->observer !== null) {
            ($this->observer)($step, $this);
        }

        return $step;
    }

    /** @return array{dec: string, reach: string, kinds: string, lanes: string, m: array<string, int>, issued: int, tainted: int, causes: array<string, int>, attackCauses: array<string, int>} */
    public function result(): array
    {
        $causes = $this->causes;
        $attackCauses = $this->attackCauses;
        ksort($causes);
        ksort($attackCauses);

        return ['dec' => $this->dec, 'reach' => $this->reach, 'kinds' => $this->kinds, 'lanes' => $this->lanes,
            'm' => ArchiveMetrics::of($this->kinds, $this->lanes, $this->reach, $this->dec, $this->ts),
            'issued' => $this->host->issued, 'tainted' => $this->host->tainted, 'causes' => $causes, 'attackCauses' => $attackCauses];
    }

    /**
     * The lane the engine resolves for this context now (`AdmissionRuntime::resolveLane`, a read of the binding epoch only). It is
     * the lane of the row, as the archive's lane is its Host's `verify()` result (g4v2.php:186-203): a credential the Host presents
     * and the engine does not verify (another instance, an old epoch) is the unproven lane, decided by the library.
     */
    private function engineLane(RateLimitContextDTO $context): string
    {
        $resolve = [$this->lab->runtime, 'resolveLane'];
        if (! is_callable($resolve)) {
            throw new \LogicException('The runtime under test cannot resolve a lane.');
        }
        $lane = $resolve(EngineLab::GROUP, $context);
        if (! $lane instanceof AdmissionLaneDTO) {
            throw new \LogicException('The runtime under test returned no lane.');
        }

        return $lane->lane->value;
    }

    /** The lane the engine admitted on, read from the signed permit token it issued (LT-14). */
    private function permitLane(string $permit): ?string
    {
        $json = $this->lab->codec->verifyPermitToken($permit);
        if ($json === null) {
            throw new \LogicException('The engine issued a permit token that does not verify.');
        }
        /** @var array{lane?: string} $payload */
        $payload = json_decode($json, true, 4, JSON_THROW_ON_ERROR);

        return $payload['lane'] ?? null;
    }
}
