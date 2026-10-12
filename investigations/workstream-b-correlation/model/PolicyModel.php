<?php

declare(strict_types=1);

/**
 * OFFLINE policy model — NOT runtime code. Replays fixture events under the CURRENT §5.3 behaviour (S0, validated
 * event-by-event against the real pipeline by crosscheck.php) and under PROPOSED alternatives. Stores mimic the
 * reference stores: fixed TTL from creation (never sliding), bounded distinct sets.
 *
 * Observation mechanisms (how often a logical attempt feeds the correlation state):
 *   every     — current behaviour: every public command (checkOnly / recordSuccess / recordFailure) observes.
 *   pre       — M1: only checkOnly observes (record-only hosts are NOT covered).
 *   distinct  — M2: WATCH increments only when the observation ADDED a new distinct member (needs an `added` signal).
 *   debounce  — M3: an identical (scope, member) observation within DEBOUNCE seconds is skipped.
 *
 * Enforcement policies: S0 current | A1 LOW advisory | A2 A1 + >=2 failing members | P idealised witnesses with
 * timestamps | PW witnesses keyed by 60 s window id (implementable with bounded sets/counters) | P2 PW + ratio-gated
 * transient SOFT for LOW (bounded denominator counters).
 */
final class ModelStore
{
    /** @var array<string, array{i: array<string, int>, e: int}> */
    public array $sets = [];
    /** @var array<string, array{c: int, e: int}> */
    public array $flags = [];
    /** @var array<string, int> */
    public array $blocks = [];

    /** @return array{0: int, 1: bool} [count, added] */
    public function add(string $k, string $m, int $now, int $ttl, int $cap): array
    {
        $s = $this->sets[$k] ?? null;
        if ($s === null || $s['e'] <= $now) {
            $s = ['i' => [], 'e' => $now + $ttl];
        }
        $added = false;
        if (! isset($s['i'][$m]) && count($s['i']) < $cap) {
            $s['i'][$m] = 1;
            $added = true;
        }
        $this->sets[$k] = $s;

        return [count($s['i']), $added];
    }

    public function watch(string $k, int $now, int $ttl): int
    {
        $f = $this->flags[$k] ?? null;
        if ($f === null || $f['e'] <= $now) {
            $f = ['c' => 0, 'e' => $now + $ttl];
        }
        $f['c']++;
        $this->flags[$k] = $f;

        return $f['c'];
    }

    public function wget(string $k, int $now): int
    {
        $f = $this->flags[$k] ?? null;

        return ($f !== null && $f['e'] > $now) ? $f['c'] : 0;
    }
}

final class PolicyModel
{
    public const DEBOUNCE = 2;

    public ModelStore $st;
    /** @var array<string, list<array{int, string}>> failure witnesses with timestamps */
    private array $fail = [];
    /** @var array<string, array<int, array<string, int>>> window-keyed witnesses: scope => window => members */
    private array $failWin = [];
    /** @var array<string, list<array{int, string, string}>> ua bucket attempts (t, k1, outcome) */
    private array $att = [];
    /** @var array<string, array<int, array{f: int, s: int, k: array<string, int>}>> bounded ratio counters per window */
    private array $ratio = [];
    /** @var array<string, int> */
    private array $lastObs = [];

    public function __construct(public string $enforce, public string $observe = 'every')
    {
        $this->st = new ModelStore();
    }

    private function active(string $k, int $now): bool
    {
        return ($this->st->blocks[$k] ?? 0) > $now;
    }

    private function failMembers(string $scope, int $now, int $win = 600): int
    {
        $w = array_filter($this->fail[$scope] ?? [], static fn(array $x): bool => $x[0] > $now - $win);

        return count(array_unique(array_column($w, 1)));
    }

    private function failMembersWin(string $scope, int $now): int
    {
        $set = [];
        $w = intdiv($now, 60);
        for ($i = $w - 9; $i <= $w; $i++) {
            foreach ($this->failWin[$scope][$i] ?? [] as $m => $_) {
                $set[$m] = 1;
            }
        }

        return count($set);
    }

    private function freshSince(string $scope, int $since): bool
    {
        if ($this->enforce === 'P') {
            foreach ($this->fail[$scope] ?? [] as [$t]) {
                if ($t >= $since) {
                    return true;
                }
            }

            return false;
        }
        foreach ($this->failWin[$scope] ?? [] as $win => $_) {
            if ($win >= intdiv($since, 60)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $e */
    public function observeCommand(array $e, string $phase): bool
    {
        $now = $e['t'];
        $k1 = $e['ip'];
        $k2 = $e['ip'] . '|' . $e['ua'];
        $low = $e['fp'] === null;
        $fp = $e['fp'] ?? ('LOW:' . $e['ua']);
        $k3 = $e['ip'] . '|' . $fp;
        $win = intdiv($now, 60);

        if ($phase === 'record') {
            $this->att['ua:' . $e['ua']][] = [$now, $k1, $e['outcome']];
            if ($e['outcome'] === 'fail') {
                $this->fail['k2:' . $k2][] = [$now, $fp];
                $this->fail['fp:' . $fp][] = [$now, $k1];
                $this->failWin['k2:' . $k2][$win][$fp] = 1;
                $this->failWin['fp:' . $fp][$win][$k1] = 1;
            }
            $this->ratio[$e['ua']][$win] ??= ['f' => 0, 's' => 0, 'k' => []];
            $this->ratio[$e['ua']][$win][$e['outcome'] === 'fail' ? 'f' : 's']++;
            if ($e['outcome'] === 'fail') {
                $this->ratio[$e['ua']][$win]['k'][$k1] = 1;
            }
        }
        if ($this->active($k2, $now) || $this->active($k3, $now)) {
            return true;
        }
        if ($this->observe === 'pre' && $phase === 'record') {
            return false;
        }
        if ($this->observe === 'debounce') {
            $d = $k2 . '#' . $fp;
            if (isset($this->lastObs[$d]) && $now - $this->lastObs[$d] < self::DEBOUNCE) {
                return false;
            }
            $this->lastObs[$d] = $now;
        }
        $distinctWatch = $this->observe === 'distinct';

        $S = $this->st;
        $trig = null;
        [$c, $added] = $S->add("churn:$k2", $fp, $now, 600, 3);
        $ch = $c >= 3;
        if (! $ch && $c === 2 && (! $distinctWatch || $added)) {
            $ch = $S->watch("cw:$k2", $now, 1800) >= 2;
        }
        if ($ch) {
            $trig = ['k2', $k2];
        }
        if ($trig === null) {
            [$d, $added] = $S->add("dil:$fp", $k1, $now, 600, 6);
            $met = $d >= 6;
            if ($d === 5 && (! $distinctWatch || $added)) {
                $met = $S->watch("dw:$fp", $now, 1800) >= 2;
            }
            if ($met) {
                if ($low) {
                    $trig = ['k2', $k2];
                } else {
                    $w = intdiv($now, 600);
                    $S->watch("dc:$fp:$w", $now, 1200);
                    if ($S->wget("dc:$fp:" . ($w - 1), $now) > 0) {
                        $trig = ['k3', $k3];
                    }
                }
            }
        }
        if ($trig === null) {
            return false;
        }
        $wf = $this->enforce === 'PW';
        switch ($this->enforce) {
            case 'S0':
                break;
            case 'A1':
                if ($low) {
                    return false;
                }
                break;
            case 'A2':
                if ($low) {
                    return false;
                }
                $scope = $trig[0] === 'k2' ? 'k2:' . $k2 : 'fp:' . $fp;
                if ($this->failMembers($scope, $now) < 2) {
                    return false;
                }
                break;
            case 'P':
            case 'PW':
            case 'P2':
                $isWin = $this->enforce !== 'P';
                $cnt = static fn(string $s): int => 0;
                $fm = fn(string $s): int => $isWin ? $this->failMembersWin($s, $now) : $this->failMembers($s, $now);
                if ($trig[0] === 'k2' && ! $low) {
                    $ok = $fm('k2:' . $k2) >= 3;
                } elseif ($trig[0] === 'k3') {
                    $ok = $fm('fp:' . $fp) >= 3;
                } else {
                    if ($this->enforce === 'P2') {
                        return $this->p2Soft($e['ua'], $now);
                    }

                    return false;
                }
                if (! $ok) {
                    return false;
                }
                $exp = $S->blocks[$trig[1]] ?? 0;
                if ($exp > 0 && ! $this->freshSince($trig[0] === 'k2' ? 'k2:' . $k2 : 'fp:' . $fp, $exp)) {
                    return false;
                }
                break;
        }
        $S->blocks[$trig[1]] = $now + 60;

        return true;
    }

    /** Ratio-gated transient SOFT: bounded denominator = two counters + capped failing-K1 set per 60 s window, 10 windows. */
    private function p2Soft(string $ua, int $now): bool
    {
        $w = intdiv($now, 60);
        $f = 0;
        $s = 0;
        $k = [];
        for ($i = $w - 9; $i <= $w; $i++) {
            $r = $this->ratio[$ua][$i] ?? null;
            if ($r === null) {
                continue;
            }
            $f += $r['f'];
            $s += $r['s'];
            foreach ($r['k'] as $ip => $_) {
                $k[$ip] = 1;
            }
        }

        return count($k) >= 6 && ($f + $s) > 0 && $f / ($f + $s) >= 0.5;
    }

    /**
     * One logical attempt. $flow: pre+out (host-realistic: record only if the pre-check allowed) | out | pre.
     *
     * @param array<string, mixed> $e
     * @return array{blocked: bool, reachedVerification: bool}
     */
    public function attempt(array $e, string $flow = 'pre+out'): array
    {
        $blocked = false;
        $reached = false;
        if ($flow !== 'out') {
            $blocked = $this->observeCommand($e, 'check');
            $reached = ! $blocked;
        }
        if ($flow !== 'pre' && ! $blocked) {
            $blocked = $this->observeCommand($e, 'record');
            $reached = true;
        }

        return ['blocked' => $blocked, 'reachedVerification' => $reached];
    }
}

/**
 * @param list<array<string, mixed>> $events
 * @return array<string, mixed>
 */
function modelRun(string $enforce, string $observe, array $events, string $flow = 'pre+out'): array
{
    $m = new PolicyModel($enforce, $observe);
    $res = [];
    foreach ($events as $e) {
        $r = $m->attempt($e, $flow);
        $res[] = $r + ['kind' => $e['kind'], 't' => $e['t']];
    }

    return $res;
}

/** @param list<array{blocked: bool, reachedVerification: bool, kind: string, t: int}> $res */
function modelSummary(array $res): array
{
    $legit = array_filter($res, static fn(array $r): bool => $r['kind'] === 'legit');
    $atk = array_filter($res, static fn(array $r): bool => $r['kind'] === 'attack');
    $first = null;
    foreach ($atk as $r) {
        if ($r['blocked']) {
            $first = $r['t'];
            break;
        }
    }

    return [
        'legitBlocked' => count(array_filter($legit, static fn(array $r): bool => $r['blocked'])) . '/' . count($legit),
        'attackBlocked' => count(array_filter($atk, static fn(array $r): bool => $r['blocked'])) . '/' . count($atk),
        'attackReachedVerification' => count(array_filter($atk, static fn(array $r): bool => $r['reachedVerification'])),
        'firstAttackBlockT' => $first,
    ];
}
