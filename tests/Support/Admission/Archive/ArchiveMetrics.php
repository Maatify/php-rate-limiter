<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission\Archive;

/** `g1Metrics()` of the archive (g1/lib/g1.php), ported: the per-stream metrics over the decision, reach, lane and kind strings. */
final class ArchiveMetrics
{
    /**
     * @param list<int> $ts stream-relative times of the non-event rows
     * @return array<string, int>
     */
    public static function of(string $kinds, string $lanes, string $reach, string $dec, array $ts, int $window = 600): array
    {
        $m = ['attackAttempts' => 0, 'reachForeign' => 0, 'reachProof' => 0, 'reachOwn' => 0, 'verifierCalls' => 0,
            'legitAttempts' => 0, 'legitDenied' => 0, 'attackReachMaxPerWindow' => 0, 'attackReachMaxSliding' => 0];
        $win = [];
        $times = [];
        for ($i = 0, $n = strlen($kinds); $i < $n; $i++) {
            $reached = $reach[$i] === '1';
            $m['verifierCalls'] += $reached ? 1 : 0;
            $k = $kinds[$i];
            if ($k === 'X' || $k === 'o') {
                $m['attackAttempts']++;
                if ($reached) {
                    if ($k === 'o') {
                        $m['reachOwn']++;
                    } elseif ($lanes[$i] === 'P') {
                        $m['reachProof']++;
                    } else {
                        $m['reachForeign']++;
                    }
                    $w = intdiv($ts[$i], $window);
                    $win[$w] = ($win[$w] ?? 0) + 1;
                    $times[] = $ts[$i];
                }
                continue;
            }
            $m['legitAttempts']++;
            $m['legitDenied'] += $dec[$i] !== 'A' ? 1 : 0;
        }
        $m['attackReachMaxPerWindow'] = $win === [] ? 0 : max($win);
        $j = 0;
        foreach ($times as $i => $t) {
            while ($times[$j] <= $t - $window) {
                $j++;
            }
            $m['attackReachMaxSliding'] = max($m['attackReachMaxSliding'], $i - $j + 1);
        }

        return $m;
    }
}
