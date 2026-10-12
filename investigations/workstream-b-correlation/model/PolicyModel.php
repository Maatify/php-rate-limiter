<?php

declare(strict_types=1);

/**
 * OFFLINE policy model — NOT runtime code, and NOT proof that any proposed policy is implementable.
 *
 * S0 reproduces the current §5.3 behaviour; crosscheck.php compares it with the REAL pipeline per step (decision, level,
 * retry-after), per persisted K2/K3 block remaining time, per verification-reached flag and per DEC-003 cycle count.
 * It does NOT compare WATCH/score internals. Every alternative (A1, A2, P, PW, P2, P0) is an UNVERIFIED offline prediction.
 *
 * Lifecycle (matches EvaluationPipeline::process()): an ACTIVE block short-circuits the request BEFORE any state is
 * mutated, for every command. Failure witnesses / ratio counters are therefore written only for requests that were not
 * denied by an active block (regression: model/regression.php).
 *
 * State model (F103-05) — each policy allocates ONLY the structure it is defined to use:
 *   S0, A1             — reference-store state only: bounded distinct sets / WATCH flags / blocks with fixed TTL. Expired entries
 *                        are garbage-collected on every command (same visible semantics: expired == absent).
 *   A2, P, P0 (ideal)  — plus failure witnesses with per-member timestamps, NO cap and NO eviction: IDEALIZED-UNBOUNDED,
 *                        shown only to separate policy logic from storage; not an implementable bounded design.
 *   PW                 — plus failure witnesses in 60 s window sets, cap 3 members per (scope, window), 10 windows kept; empty
 *                        scopes are removed. It allocates NO idealized `fail` state and NO ratio state.
 *   P2                 — PW witnesses plus ratio state: per UA per 60 s window two integers and a failing-K1 set capped at 6;
 *                        10 windows kept; empty UA anchors are removed.
 *   Retained state is MEASURED after every command (`retained()`), split into reference-store entries and witness entries, and
 *   the peak of each is reported by run_matrix.php. Rotation (current+previous) and ephemeral interaction of witness state are
 *   NOT modelled and remain unproven.
 *
 * Observation mechanisms: every | pre (M1) | distinct (M2) | debounce (M3) — see model/mechanisms.php.
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

    /** Removes expired state. Visible semantics are unchanged: every accessor already treats expired as absent. */
    public function gc(int $now): void
    {
        foreach ($this->sets as $k => $v) {
            if ($v['e'] <= $now) {
                unset($this->sets[$k]);
            }
        }
        foreach ($this->flags as $k => $v) {
            if ($v['e'] <= $now) {
                unset($this->flags[$k]);
            }
        }
        foreach ($this->blocks as $k => $exp) {
            if ($exp <= $now) {
                unset($this->blocks[$k]);
            }
        }
    }

    public function entries(): int
    {
        $n = count($this->flags) + count($this->blocks);
        foreach ($this->sets as $v) {
            $n += count($v['i']);
        }

        return $n;
    }
}

final class PolicyModel
{
    public const DEBOUNCE = 2;
    public const WIN = 60;
    public const WINDOWS = 10;
    public const WITNESS_CAP = 3;
    public const FAILING_K1_CAP = 6;
    public const LATCH_RETAIN = 600; // = witness horizon: after it no witness can satisfy the >=3 evidence rule, so the marker is redundant

    public ModelStore $st;
    /** @var array<string, array<string, int>> IDEALIZED-UNBOUNDED timestamped witnesses: scope => member => last t */
    private array $fail = [];
    /** @var array<string, array<int, array<string, int>>> BOUNDED window witnesses: scope => window => members (cap 3) */
    private array $failWin = [];
    /** @var array<string, array<int, array{f: int, s: int, k: array<string, int>}>> BOUNDED ratio state per UA window */
    private array $ratio = [];
    /** @var array<string, int> */
    private array $lastObs = [];
    /** @var array<string, int> latch marker (P family): scope key => expiry of the last correlation block; kept LATCH_RETAIN s past expiry */
    private array $latch = [];

    public int $cycles = 0;
    public int $witnessWritesWhileDenied = 0;
    public int $peakStoreEntries = 0;
    public int $peakWitnessEntries = 0;
    public int $peakIdealizedWitnessEntries = 0;
    public int $peakBoundedWitnessEntries = 0;

    /** Regression switch: reproduces the REJECTED earlier ordering (witness written before the active-block check). */
    public function __construct(public string $enforce, public string $observe = 'every', public bool $legacyWitnessBeforeActiveCheck = false, public int $witnessCap = self::WITNESS_CAP)
    {
        $this->st = new ModelStore();
    }

    private function active(string $k, int $now): int
    {
        return max(0, ($this->st->blocks[$k] ?? 0) - $now);
    }

    /** @return array{k2: ?int, k3: ?int} remaining seconds of persisted K2/K3 blocks for this event's keys */
    public function blockState(array $e, int $now): array
    {
        $k2 = $e['ip'] . '|' . $e['ua'];
        $k3 = $e['ip'] . '|' . ($e['fp'] ?? ('LOW:' . $e['ua']));
        $r2 = $this->active($k2, $now);
        $r3 = $this->active($k3, $now);

        return ['k2' => $r2 > 0 ? $r2 : null, 'k3' => $r3 > 0 ? $r3 : null];
    }

    private function failMembers(string $scope, int $now, bool $bounded): int
    {
        if ($bounded) {
            $set = [];
            $w = intdiv($now, self::WIN);
            for ($i = $w - self::WINDOWS + 1; $i <= $w; $i++) {
                foreach ($this->failWin[$scope][$i] ?? [] as $m => $_) {
                    $set[$m] = 1;
                }
            }

            return count($set);
        }
        $n = 0;
        foreach ($this->fail[$scope] ?? [] as $t) {
            $n += $t > $now - 600 ? 1 : 0;
        }

        return $n;
    }

    private function freshSince(string $scope, int $since, bool $bounded): bool
    {
        if (! $bounded) {
            foreach ($this->fail[$scope] ?? [] as $t) {
                if ($t >= $since) {
                    return true;
                }
            }

            return false;
        }
        foreach ($this->failWin[$scope] ?? [] as $win => $members) {
            if ($win >= intdiv($since, self::WIN) && $members !== []) {
                return true;
            }
        }

        return false;
    }

    /** Number of failed records that reached the witness-write path (storage-cap probe: all must be admitted). */
    public int $witnessWriteAttempts = 0;

    /** @return list<int> live window ids retained for a witness scope, ascending */
    public function windowIds(string $scope): array
    {
        $ids = array_keys($this->failWin[$scope] ?? []);
        sort($ids);

        return $ids;
    }

    /** @return list<string> members retained for a scope in one window */
    public function windowMembers(string $scope, int $window): array
    {
        return array_keys($this->failWin[$scope][$window] ?? []);
    }

    private function writeWitness(array $e, int $now): void
    {
        $k1 = $e['ip'];
        $k2 = $e['ip'] . '|' . $e['ua'];
        $fp = $e['fp'] ?? ('LOW:' . $e['ua']);
        $win = intdiv($now, self::WIN);
        if (in_array($this->enforce, ['A2', 'P'], true)) {
            // IDEALIZED-UNBOUNDED timestamped witnesses (only for the policies defined to use them)
            if ($e['outcome'] === 'fail') {
                $this->fail['k2:' . $k2][$fp] = $now;
                $this->fail['fp:' . $fp][$k1] = $now;
            }

            return;
        }
        if (($this->enforce === 'PW' || $this->enforce === 'P2') && $e['outcome'] === 'fail') {
            $this->witnessWriteAttempts++;
        }
        if ($this->enforce === 'PW' || $this->enforce === 'P2') {
            // BOUNDED window witnesses: cap 3 per (scope, window); a full window rejects new members (bounded-set semantics)
            if ($e['outcome'] === 'fail') {
                foreach (['k2:' . $k2 => $fp, 'fp:' . $fp => $k1] as $scope => $member) {
                    $set = $this->failWin[$scope][$win] ?? [];
                    if (isset($set[$member]) || count($set) < $this->witnessCap) {
                        $set[$member] = 1;
                        $this->failWin[$scope][$win] = $set;
                    }
                }
            }
        }
        if ($this->enforce === 'P2') {
            $r = $this->ratio[$e['ua']][$win] ?? ['f' => 0, 's' => 0, 'k' => []];
            $r[$e['outcome'] === 'fail' ? 'f' : 's']++;
            if ($e['outcome'] === 'fail' && count($r['k']) < self::FAILING_K1_CAP) {
                $r['k'][$k1] = 1;
            }
            $this->ratio[$e['ua']][$win] = $r;
        }
    }

    /** Evicts expired windows and removes emptied scope / UA anchors (called on every command, like store TTL expiry). */
    private function evict(int $now): void
    {
        $win = intdiv($now, self::WIN);
        foreach ($this->failWin as $scope => $windows) {
            foreach (array_keys($windows) as $w) {
                if ($w <= $win - self::WINDOWS) {
                    unset($this->failWin[$scope][$w]);
                }
            }
            if ($this->failWin[$scope] === []) {
                unset($this->failWin[$scope]);
            }
        }
        foreach ($this->ratio as $ua => $windows) {
            foreach (array_keys($windows) as $w) {
                if ($w <= $win - self::WINDOWS) {
                    unset($this->ratio[$ua][$w]);
                }
            }
            if ($this->ratio[$ua] === []) {
                unset($this->ratio[$ua]);
            }
        }
        foreach ($this->latch as $k => $exp) {
            if ($exp + self::LATCH_RETAIN <= $now) {
                unset($this->latch[$k]);
            }
        }
        foreach ($this->lastObs as $d => $t) {
            if ($now - $t >= self::DEBOUNCE) {
                unset($this->lastObs[$d]);
            }
        }
    }

    /** @return array{store: int, latch: int, idealized: int, bounded: int, uaAnchors: int, failWinScopes: int, maxScopeWindows: int, maxWindowMembers: int} entries RETAINED right now */
    public function retained(): array
    {
        $ideal = 0;
        foreach ($this->fail as $members) {
            $ideal += count($members);
        }
        $bounded = 0;
        $maxScopeWindows = 0;
        $maxWindowMembers = 0;
        foreach ($this->failWin as $windows) {
            $maxScopeWindows = max($maxScopeWindows, count($windows));
            foreach ($windows as $members) {
                $bounded += count($members);
                $maxWindowMembers = max($maxWindowMembers, count($members));
            }
        }
        foreach ($this->ratio as $windows) {
            foreach ($windows as $r) {
                $bounded += 2 + count($r['k']);
            }
        }

        return [
            'store' => $this->st->entries() + count($this->lastObs),
            'latch' => count($this->latch),
            'idealized' => $ideal,
            'bounded' => $bounded,
            'uaAnchors' => count($this->ratio),
            'failWinScopes' => count($this->failWin),
            'maxScopeWindows' => $maxScopeWindows,
            'maxWindowMembers' => $maxWindowMembers,
        ];
    }

    private function track(): void
    {
        $r = $this->retained();
        $this->peakStoreEntries = max($this->peakStoreEntries, $r['store']);
        $this->peakIdealizedWitnessEntries = max($this->peakIdealizedWitnessEntries, $r['idealized']);
        $this->peakBoundedWitnessEntries = max($this->peakBoundedWitnessEntries, $r['bounded']);
        $this->peakWitnessEntries = max($this->peakWitnessEntries, $r['idealized'] + $r['bounded'] + $r['latch']);
    }

    /**
     * @param array<string, mixed> $e
     * @return array{blocked: bool, retry: int}
     */
    public function observeCommand(array $e, string $phase): array
    {
        $this->st->gc($e['t']);
        $this->evict($e['t']);
        $r = $this->observeCommandInner($e, $phase);
        $this->track();

        return $r;
    }

    /**
     * @param array<string, mixed> $e
     * @return array{blocked: bool, retry: int}
     */
    private function observeCommandInner(array $e, string $phase): array
    {
        $now = $e['t'];
        $k1 = $e['ip'];
        $k2 = $e['ip'] . '|' . $e['ua'];
        $low = $e['fp'] === null;
        $fp = $e['fp'] ?? ('LOW:' . $e['ua']);
        $k3 = $e['ip'] . '|' . $fp;

        // Lifecycle: active block first, before ANY mutation (matches EvaluationPipeline::process()).
        $remaining = max($this->active($k2, $now), $this->active($k3, $now));
        if ($this->legacyWitnessBeforeActiveCheck && $phase === 'record') {
            $this->witnessWritesWhileDenied += $remaining > 0 ? 1 : 0;
            $this->writeWitness($e, $now);
        }
        if ($remaining > 0) {
            return ['blocked' => true, 'retry' => $remaining];
        }
        if ($phase === 'record' && ! $this->legacyWitnessBeforeActiveCheck) {
            $this->writeWitness($e, $now);
        }
        if ($this->observe === 'pre' && $phase === 'record') {
            return ['blocked' => false, 'retry' => 0];
        }
        if ($this->observe === 'debounce') {
            $d = $k2 . '#' . $fp;
            if (isset($this->lastObs[$d]) && $now - $this->lastObs[$d] < self::DEBOUNCE) {
                return ['blocked' => false, 'retry' => 0];
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
            return ['blocked' => false, 'retry' => 0];
        }
        switch ($this->enforce) {
            case 'S0':
                break;
            case 'A1':
                if ($low) {
                    return ['blocked' => false, 'retry' => 0];
                }
                break;
            case 'A2':
                if ($low) {
                    return ['blocked' => false, 'retry' => 0];
                }
                if ($this->failMembers($trig[0] === 'k2' ? 'k2:' . $k2 : 'fp:' . $fp, $now, false) < 2) {
                    return ['blocked' => false, 'retry' => 0];
                }
                break;
            case 'P':
            case 'PW':
            case 'P2':
                $bounded = $this->enforce !== 'P';
                $scope = $trig[0] === 'k2' ? 'k2:' . $k2 : 'fp:' . $fp;
                if ($trig[0] === 'k2' && ! $low) {
                    $ok = $this->failMembers($scope, $now, $bounded) >= 3;
                } elseif ($trig[0] === 'k3') {
                    $ok = $this->failMembers($scope, $now, $bounded) >= 3;
                } else {
                    return $this->enforce === 'P2'
                        ? ['blocked' => $this->p2Soft($e['ua'], $now), 'retry' => 0]
                        : ['blocked' => false, 'retry' => 0];
                }
                if (! $ok) {
                    return ['blocked' => false, 'retry' => 0];
                }
                $exp = $this->latch[$trig[1]] ?? 0;
                if ($exp > 0 && ! $this->freshSince($scope, $exp, $bounded)) {
                    return ['blocked' => false, 'retry' => 0];
                }
                break;
        }
        $S->blocks[$trig[1]] = $now + 60;
        if (in_array($this->enforce, ['P', 'PW', 'P2'], true)) {
            $this->latch[$trig[1]] = $now + 60;
        }
        $this->cycles++;

        return ['blocked' => true, 'retry' => 60];
    }

    private function p2Soft(string $ua, int $now): bool
    {
        $w = intdiv($now, self::WIN);
        $f = 0;
        $s = 0;
        $k = [];
        for ($i = $w - self::WINDOWS + 1; $i <= $w; $i++) {
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
     * @return array{blocked: bool, reachedVerification: bool, steps: list<array{cmd: string, blocked: bool, level: ?int, retry: ?int}>}
     */
    public function attempt(array $e, string $flow = 'pre+out'): array
    {
        $blocked = false;
        $reached = false;
        $steps = [];
        if ($flow !== 'out') {
            $r = $this->observeCommand($e, 'check');
            $steps[] = ['cmd' => 'check', 'blocked' => $r['blocked'], 'level' => $r['blocked'] ? 2 : 0, 'retry' => $r['retry']];
            $blocked = $r['blocked'];
            $reached = ! $blocked;
        }
        if ($flow !== 'pre' && ! $blocked) {
            $r = $this->observeCommand($e, 'record');
            $steps[] = ['cmd' => 'record', 'blocked' => $r['blocked'], 'level' => $r['blocked'] ? 2 : 0, 'retry' => $r['retry']];
            $blocked = $r['blocked'];
            $reached = true;
        }

        return ['blocked' => $blocked, 'reachedVerification' => $reached, 'steps' => $steps];
    }
}

/**
 * @param list<array<string, mixed>> $events
 * @return array{results: list<array<string, mixed>>, model: PolicyModel}
 */
function modelReplay(string $enforce, string $observe, array $events, string $flow = 'pre+out'): array
{
    $m = new PolicyModel($enforce, $observe);
    $res = [];
    foreach ($events as $e) {
        $r = $m->attempt($e, $flow);
        $bs = $m->blockState($e, $e['t']);
        $res[] = $r + ['kind' => $e['kind'], 't' => $e['t'], 'k2' => $bs['k2'], 'k3' => $bs['k3']];
    }

    return ['results' => $res, 'model' => $m];
}

/**
 * @param list<array<string, mixed>> $events
 * @return list<array<string, mixed>>
 */
function modelRun(string $enforce, string $observe, array $events, string $flow = 'pre+out'): array
{
    return modelReplay($enforce, $observe, $events, $flow)['results'];
}

/** @param list<array<string, mixed>> $res */
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
