<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission\Archive;

use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\DTO\AdmissionBudgetsDTO;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;

/**
 * @phpstan-import-type DumpRow from ArchiveData
 *
 * The replay of an archived stream through the REAL engine (g4v2Replay, ported to the production composition). The study's
 * gate and lane code are not used: the lane, the reservation, the permit and the outcome hooks are the engine's. The Host
 * model supplies the credential and the instance id; the context is the archived context plus those two inputs.
 *
 * Host behaviours registered by the 5-b3b plan: a share of outcomes dropped by a deterministic rule (NM-10), a probe factor
 * `rho` (extra `checkOnly` per attempt, NM-5) and a share of abandoned attempts (NM-5).
 */
final class StreamReplay
{
    /**
     * FULL2 arm: the G4 v2 values (contract §0.1, DEC-025) with no cross-account D8 cap (the arm of RP-G4 / RP-G6, before G7).
     */
    public static function fullBudgets(int $sourceCapacity = 24): AdmissionBudgetsDTO
    {
        return EngineLab::budgets(sourceCapacity: $sourceCapacity, crossAccountFailureCap: 1_000_000);
    }

    /** Deterministic share rule of the plan: item `j` (0-based) is selected iff floor((j+1)s) > floor(js). */
    public static function selects(int $j, float $share): bool
    {
        return $share > 0.0 && (int) floor(($j + 1) * $share + 1e-9) > (int) floor($j * $share + 1e-9);
    }

    /**
     * @param list<DumpRow> $rows the dumped rows (events carry `effects`)
     * @return array{dec: string, reach: string, kinds: string, lanes: string, m: array<string, int>, issued: int, tainted: int, causes: array<string, int>, attackCauses: array<string, int>}
     */
    public static function run(
        array $rows,
        string $flow,
        ?EngineLab $lab = null,
        float $dropShare = 0.0,
        int $rho = 1,
        float $abandonShare = 0.0,
        bool $renew = true,
        bool $passwordBinding = true,
    ): array {
        $lab ??= new EngineLab(self::fullBudgets());
        $host = new StudyHost($lab, ArchiveData::get()->constants(), $renew, $passwordBinding);
        $dec = $lanes = $reach = $kinds = '';
        $ts = [];
        $causes = [];
        $attackCauses = [];
        $outcomeIndex = 0;
        $attemptIndex = 0;
        foreach ($rows as $r) {
            if (isset($r['effects'])) {
                $host->apply($r['effects'], EngineLab::T0 + $r['t']);
                continue;
            }
            if (! isset($r['ctx'], $r['holder'], $r['kind'], $r['outcome'], $r['own'])) {
                throw new \UnexpectedValueException('Malformed dumped row.');
            }
            $holder = $r['holder'];
            $lab->at($r['t']);
            $now = $lab->now();
            $c = $r['ctx'];
            $acct = $c['accountId'];
            $inst = $r['inst'] ?? null;
            $token = $acct === null ? null : $host->token($holder, $inst, $acct, $now);
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
                admissionCredential: $token === null ? null : $host->credential($token),
            );
            for ($p = 1; $p < $rho; $p++) {
                $lab->check($context);
            }
            $attempt = $attemptIndex++;
            $abandoned = self::selects($attempt, $abandonShare);
            $denied = false;
            $reached = false;
            $cause = '-';
            $blocking = RateLimitResultDTO::DECISION_ALLOW;
            $result = $lab->check($context);
            if ($result->decision !== RateLimitResultDTO::DECISION_ALLOW) {
                $denied = true;
                $blocking = $result->decision;
                $cause = (string) (EngineLab::cause($result) ?? ($result->decision === RateLimitResultDTO::DECISION_SOFT_BLOCK ? 'soft' : 'hard'));
            } elseif (! $abandoned) {
                $reached = true;
                if ($acct !== null && $inst !== null) {
                    $host->observe($holder, $inst, $acct, $token !== null, $r['outcome'], $now, $token);
                }
                if ($flow === 'pre+out' && ! self::selects($outcomeIndex++, $dropShare)) {
                    $recorded = $lab->outcome($context, $r['outcome'] === 'ok', EngineLab::permit($result));
                    if ($recorded->decision !== RateLimitResultDTO::DECISION_ALLOW) {
                        $denied = true;
                        $blocking = $recorded->decision;
                        $cause = (string) (EngineLab::cause($recorded) ?? 'penalty');
                    }
                }
            }
            $dec .= ! $denied ? 'A' : ($blocking === RateLimitResultDTO::DECISION_SOFT_BLOCK ? 'S' : 'H');
            $lanes .= $token !== null ? 'P' : 'U';
            $reach .= $reached ? '1' : '0';
            $kinds .= $r['kind'] === 'X' ? ($r['own'] ? 'o' : 'X') : $r['kind'];
            $ts[] = $r['t'];
            if ($denied && $r['kind'] !== 'X') {
                $causes[$cause] = ($causes[$cause] ?? 0) + 1;
            } elseif ($denied) {
                $attackCauses[$cause] = ($attackCauses[$cause] ?? 0) + 1;
            }
        }
        ksort($causes);
        ksort($attackCauses);

        $result = ['dec' => $dec, 'reach' => $reach, 'kinds' => $kinds, 'lanes' => $lanes, 'm' => ArchiveMetrics::of($kinds, $lanes, $reach, $dec, $ts),
            'issued' => $host->issued, 'tainted' => $host->tainted, 'causes' => $causes, 'attackCauses' => $attackCauses];
        // The lab (engine, circuit, stores) is a graph of cycles: free it now, a replay set holds hundreds of them.
        unset($host, $lab);
        gc_collect_cycles();

        return $result;
    }
}
