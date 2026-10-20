<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission\Archive;

use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;

/**
 * Gates A1, A2, A3 of `g4/integrated_v2/PLAN.md` §6.3 over replayed streams, with the definitions of the archive's
 * `report.php` (best response over strategies and id policies; the S0, S24 and S12 references read from the archive's own
 * expected files at run time, never re-typed).
 */
final class StreamGates
{
    public const BENIGN = ['L1', 'ABA_B', 'D2', 'N12', 'Nu4', 'OFF12', 'Y6b', 'KD', 'UAC', 'L3', 'D1'];

    /**
     * Benign streams excluded from A1 as declared costs, as `MIX` is (5b3b/PLAN.md §11.5, the Owner's decision of 2026-10-20):
     * `UAC` = `KD` once K5 is on the instance id; G5 v2 `g5/identity_layer_v2/expected/summary.md` L79 and L193 (`6 → 9`), F-G5v2-06.
     */
    public const DECLARED_COSTS = ['UAC'];
    public const SPRAY = ['SP1n', 'SP3', 'SP6', 'ABA_A'];

    /**
     * Replay every archived stream that carries ids through a fresh lab and keep the per-stream metrics.
     *
     * @param \Closure(): EngineLab $labFor
     * @return list<array{stream: string, strategy: string, ids: string, result: array{dec: string, reach: string, kinds: string, lanes: string, m: array<string, int>, issued: int, tainted: int, causes: array<string, int>, attackCauses: array<string, int>}}>
     */
    public static function replayAll(\Closure $labFor, string $flow, float $dropShare = 0.0, int $rho = 1, float $abandon = 0.0): array
    {
        $out = [];
        foreach (ArchiveData::get()->streams() as $s) {
            if ($s['ids'] === 'none') {
                continue;
            }
            $out[] = ['stream' => $s['stream'], 'strategy' => $s['strategy'], 'ids' => $s['ids'],
                'result' => StreamReplay::run($s['rows'], $flow, $labFor(), $dropShare, $rho, $abandon)];
        }

        return $out;
    }

    /**
     * @param list<array{stream: string, result: array{m: array<string, int>}}> $replays
     * @return array<string, array{legit: int, reach: int}>
     */
    public static function best(array $replays): array
    {
        $o = [];
        foreach ($replays as $r) {
            $m = $r['result']['m'];
            $c = $o[$r['stream']] ?? ['legit' => 0, 'reach' => 0];
            $c['legit'] = max($c['legit'], $m['legitDenied']);
            $c['reach'] = max($c['reach'], $m['reachForeign'] + $m['reachProof']);
            $o[$r['stream']] = $c;
        }

        return $o;
    }

    /** @return array<string, array{legit: int, reach: int}> the S0 baseline (`g3/admission_budgets/expected/s00_s0.jsonl`) */
    public static function s0(): array
    {
        $o = [];
        foreach (ArchiveData::expected('g3/admission_budgets/expected/s00_s0.jsonl') as $row) {
            /** @var array{stream: string, m: array<string, int>} $r */
            $r = $row;
            $c = $o[$r['stream']] ?? ['legit' => 0, 'reach' => 0];
            $c['legit'] = max($c['legit'], $r['m']['legitDenied']);
            $c['reach'] = max($c['reach'], $r['m']['reachForeign']);
            $o[$r['stream']] = $c;
        }

        return $o;
    }

    /** @return array<string, array{legit: int, reach: int}> the S24 reference (V0 rows, pre+out) */
    public static function s24(): array
    {
        return self::archiveBest('g4/integrated_v2/expected/v2_streams.CAND.jsonl', 'S24', 'pre+out');
    }

    /** @return array<string, array{legit: int, reach: int}> the S12 reference (G3 v2) */
    public static function s12(): array
    {
        return self::archiveBest('g3/admission_budgets_v2/expected/v2_fc.CAND.jsonl', 'S12', 'pre+out');
    }

    /**
     * @return array<string, array{legit: int, reach: int}>
     */
    private static function archiveBest(string $file, string $arm, string $flow): array
    {
        $o = [];
        foreach (ArchiveData::expected($file) as $row) {
            /** @var array{stream: string, arm?: string, flow?: string, m?: array<string, int>} $r */
            $r = $row;
            if (($r['arm'] ?? null) !== $arm || ($r['flow'] ?? null) !== $flow || ! isset($r['m'])) {
                continue;
            }
            $c = $o[$r['stream']] ?? ['legit' => 0, 'reach' => 0];
            $c['legit'] = max($c['legit'], $r['m']['legitDenied']);
            $c['reach'] = max($c['reach'], $r['m']['reachForeign'] + $r['m']['reachProof']);
            $o[$r['stream']] = $c;
        }

        return $o;
    }

    /**
     * A1: every benign stream (MIX and the declared costs apart) at most S0 and at most S24.
     *
     * @param array<string, array{legit: int, reach: int}> $best
     * @return list<string> the failing streams, empty when the gate passes
     */
    public static function a1(array $best): array
    {
        $s0 = self::s0();
        $s24 = self::s24();
        $failed = [];
        foreach (array_diff(self::BENIGN, self::DECLARED_COSTS) as $s) {
            if ($best[$s]['legit'] > $s0[$s]['legit'] || $best[$s]['legit'] > $s24[$s]['legit']) {
                $failed[] = "$s {$best[$s]['legit']} (S0 {$s0[$s]['legit']}, S24 {$s24[$s]['legit']})";
            }
        }

        return $failed;
    }

    /** @param array<string, array{legit: int, reach: int}> $best */
    public static function a2(array $best): bool
    {
        return $best['MIX']['legit'] <= self::s0()['MIX']['legit'];
    }

    /**
     * A3: SP1n, SP3, SP6, ABA_A best-response reach at most S12.
     *
     * @param array<string, array{legit: int, reach: int}> $best
     * @return list<string> the failing streams
     */
    public static function a3(array $best): array
    {
        $s12 = self::s12();
        $failed = [];
        foreach (self::SPRAY as $s) {
            if ($best[$s]['reach'] > $s12[$s]['reach']) {
                $failed[] = "$s {$best[$s]['reach']} (S12 {$s12[$s]['reach']})";
            }
        }

        return $failed;
    }
}
