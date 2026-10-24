<?php

declare(strict_types=1);

// #160 phase 2b — shared helpers: the test's key identities, the recorder-file reader and the
// per-worker analysis (PLAN.md §3, §4). Pure functions, no Redis.

namespace Flaky160B;

final class Keys
{
    public const SECRET = 'test_secret';

    public static function k(string $policy, string $type, string $scope): string
    {
        return hash_hmac('sha256', "{$policy}:rate_limiter:{$type}:v2:prod:{$scope}", self::SECRET);
    }

    public static function allowance(): string
    {
        return hash_hmac('sha256', 'login_protection:rate_limiter:aux:known_device_allowance:v1:prod:login-owner', self::SECRET);
    }

    public static function k4(): string
    {
        return self::k('login_protection', 'k4', 'login-owner');
    }

    public static function k5(string $fp = 'concurrent-fp'): string
    {
        return self::k('login_protection', 'k5', 'login-owner:' . $fp);
    }
}

final class Analysis
{
    /**
     * Reads one recorder file. Returns [records, problems]. A file is complete only if it ends with the
     * `#end` trailer whose n equals the number of records and the records' seq values are exactly 0..n-1.
     * @return array{0: list<array<string,mixed>>, 1: list<string>, 2: bool}
     */
    public static function readFile(string $path): array
    {
        $records = [];
        $problems = [];
        $trailer = null;
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $r = json_decode($line, true);
            if (! is_array($r)) {
                $problems[] = 'unparseable_line';
                continue;
            }
            if (($r['m'] ?? '') === '#end') {
                $trailer = $r;
                continue;
            }
            $records[] = $r;
        }
        if ($trailer === null) {
            $problems[] = 'no_trailer';
        } else {
            if ($trailer['n'] !== count($records)) {
                $problems[] = 'record_count_mismatch';
            }
            $seqs = array_map(static fn(array $r): int => (int) $r['s'], $records);
            sort($seqs);
            if ($seqs !== range(0, max(0, count($records) - 1)) && $records !== []) {
                $problems[] = 'seq_gap';
            }
        }
        usort($records, static fn(array $a, array $b): int => $a['t0'] <=> $b['t0'] ?: $a['s'] <=> $b['s']);

        return [$records, $problems, (bool) ($trailer['root'] ?? false)];
    }

    /**
     * One worker's classification from its records.
     * @param list<array<string,mixed>> $records ordered by start time
     * @return array<string,mixed>
     */
    public static function worker(array $records, bool $change = false): array
    {
        $allowance = Keys::allowance();
        $k4 = Keys::k4();
        $k5 = Keys::k5();
        $u = 0;
        $k = 0;
        $maxCount = 0;
        $muts = [];
        $pubs = [];
        $pubTimes = [];
        $obs = [];
        $lastRead = null;
        $k5After = false;
        $exit = [];
        foreach ($records as $r) {
            $m = (string) $r['m'];
            $a = $r['a'];
            if ($m === '#obs') {
                if (($a['key'] ?? null) === $k4) {
                    $obs[] = ['n' => $a['n'], 'level' => $a['level'], 'expiresAt' => $a['expiresAt']];
                }
                continue;
            }
            if (($r['p'] ?? '') === 'exit' && (int) $r['d'] === 0 && $m === 'checkBlock') {
                $exit[$a[0] === $k4 ? 'k4' : ($a[0] === $k5 ? 'k5' : 'other')] = $r['r']['level'] ?? 0;
                continue;
            }
            if ((int) $r['d'] !== 0 || ($r['p'] ?? '') !== 'run') {
                continue;
            }
            $key = $a[0] ?? null;
            if (preg_match('/^incrementBudget(WithSeed)?(At)?$/', $m) && $key === $allowance) {
                $u++;
                $maxCount = max($maxCount, (int) ($r['r']['count'] ?? 0));
            } elseif (preg_match('/^increment(At)?$/', $m) && $key === $k5 && ($a[2] ?? null) === 2) {
                $k++;
                $k5After = true;
            } elseif (preg_match('/^mutateGenerationBoundScore(At)?$/', $m) && $key === $k4) {
                $muts[] = (bool) ($r['r']['applied'] ?? false);
                $k5After = false;
            } elseif (preg_match('/^blockWithPunishmentLifecycleTracking(At)?$/', $m) && $key === $k4) {
                $pubs[] = (bool) ($r['r']['applied'] ?? false);
                $pubTimes[] = [(int) $r['t0'], (int) $r['t1'], (bool) ($r['r']['applied'] ?? false)];
                $lastRead = null;
            } elseif (preg_match('/^readGenerationBoundScoreState(At)?$/', $m) && $key === $k4) {
                $lastRead = $r['r'] === null ? 'null' : 'state';
            }
        }
        $conflictPub = $pubs !== [] && end($pubs) === false;
        // Step S (DEC-029): after the change a non-applied last publication is not an exception but a deferral (or a
        // dropped K4, when the re-read level fell below 2); the only remaining exception site is the K4 mutation.
        $outcome = 'none';
        if ($pubs !== []) {
            if (end($pubs) === true) {
                $outcome = 'applied_' . count($pubs);
            } else {
                $outcome = count($pubs) === 4 ? 'deferred_r3' : ($lastRead === 'null' ? 'deferred_b1' : 'dropped_b2');
            }
        }
        if ($change) {
            $conflictPub = false;
        }
        $conflictMut = $muts !== [] && end($muts) === false && ! $k5After && $pubs === [];
        $route = null;
        if ($conflictPub) {
            $route = count($pubs) === 3 ? 'r3' : ($lastRead === 'null' ? 'b1' : 'b2');
        }
        $nonApplied = array_keys(array_filter($pubs, static fn(bool $x): bool => ! $x));

        return [
            'u' => $u, 'k' => $k, 'max_count' => $maxCount,
            'conflict' => $conflictPub || $conflictMut, 'conflict_site' => $conflictPub ? 'publication' : ($conflictMut ? 'mutation' : null),
            'route' => $route, 'pub_outcome' => $outcome, 'pub_times' => $pubTimes, 'pubs' => count($pubs), 'pub_non_applied' => count($nonApplied), 'muts' => count($muts),
            'obs_first' => $obs[0] ?? null, 'obs_last' => $obs === [] ? null : $obs[count($obs) - 1], 'exit' => $exit,
        ];
    }
}
