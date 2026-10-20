<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission\Archive;

use Maatify\RateLimiter\DTO\AdmissionBudgetsDTO;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;

/**
 * @phpstan-import-type DumpRow from ArchiveData
 *
 * The replay of a whole archived stream through the REAL engine: one `ReplaySession` (g4v2Replay ported to the production
 * composition, IN on every row, the lane resolved by the engine) run over the rows, then freed. The FULL2 budgets and the
 * deterministic share rule of the plan live here.
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
        $session = new ReplaySession($lab ?? new EngineLab(self::fullBudgets()), $flow, $dropShare, $rho, $abandonShare, $renew, $passwordBinding);
        $result = $session->replay($rows)->result();
        // The lab (engine, circuit, stores) is a graph of cycles: free it now, a replay set holds hundreds of them.
        unset($session, $lab);
        gc_collect_cycles();

        return $result;
    }
}
