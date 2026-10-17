<?php

declare(strict_types=1);

// G1 verification addendum (VERIFY_PLAN.md) — harness. EVIDENCE ONLY: not package runtime.
// Reuses the original G1 harness (../../lib/g1.php) unchanged in behaviour. g1VReplay() replicates g1Replay() and adds record-only
// hooks: P-state deltas around every U command / U admission (C9-H), decision-origin attribution from the TRACE build (C10-H), and
// the inputs of an independent admission recount (C1-H). Equivalence with the archived strings is checked by the scenarios.

require_once dirname(__DIR__, 2) . '/lib/g1.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

const G1V_FORBIDDEN_ORIGINS = ['k1', 'k2', 'k3', 'correlation', 'credential_spray'];

/** @return list<string> pipeline secrets whose P keys are inspected (current first). */
function g1VSecrets(?string $previous = null, string $current = Lab::SECRET): array
{
    return $previous === null ? [$current] : [$current, $previous];
}

/**
 * Complete P-lane state relevant to I4a, as one comparable string: per account and per pipeline secret the K4P score, block and
 * budget, the P account watcher and the P budget cooldown; plus every P admission counter and P device set of the reference store
 * (all generations: the keys embed the generation).
 *
 * @param list<string> $accounts
 * @param list<string> $secrets
 */
function g1VPState(G1Cell $cell, array $accounts, array $secrets): string
{
    $lab = $cell->lab;
    $p = $lab->policy->getName();
    $out = [];
    foreach ($accounts as $acct) {
        foreach ($secrets as $i => $sec) {
            $k4p = hash_hmac('sha256', "{$p}:rate_limiter:k4p:v2:prod:{$acct}", $sec);
            $ae = hash_hmac('sha256', "{$p}:rate_limiter:aux:anti_equilibrium_p:v1:prod:{$acct}", $sec);
            $cd = hash_hmac('sha256', "{$p}:rate_limiter:budget_cooldown_p:v1:prod:{$acct}", $sec);
            $s = $lab->store->get($k4p);
            $b = $lab->store->checkBlock($k4p);
            $bg = $lab->store->getBudget($k4p);
            $c = $lab->store->get($cd);
            $out[] = [$acct, $i, $s?->value, $b?->level, $b?->expiresAt, $bg?->count, $lab->corr->getWatchFlag($ae), $c?->value];
        }
    }
    $st = $cell->adm?->store;
    if ($st instanceof G1RefStore) {
        foreach ($st->c as $key => $v) {
            if (preg_match('/:(pacct|pacct_day|pproof|pproof_day|pglob):/', $key) === 1) {
                $out[] = [$key, $v];
            }
        }
        foreach ($st->s as $set => $m) {
            if (str_contains($set, ':pdev:')) {
                ksort($m);
                $out[] = [$set, $m];
            }
        }
    } elseif ($st instanceof G1VWrapStore) {
        $out[] = $st->pState();
    }

    return serialize($out);
}

/**
 * Origins of one denied pipeline command, from its TRACE (VERIFY_PLAN.md §2 C10-H). Updates $blockOrigin with the origin of
 * every block the command persisted (so a later active block inherits the origin that wrote it).
 *
 * @param list<array<string, mixed>> $trace
 * @param array<string, list<string>> $blockOrigin
 * @return list<string>
 */
function g1VOrigins(array $trace, string $lane, array &$blockOrigin, bool $denied): array
{
    $label = static fn(string $kt): string => $kt === 'k4' && $lane === 'P' ? 'k4p' : $kt;
    $origins = [];
    $active = array_values(array_filter($trace, static fn(array $e): bool => $e['ev'] === 'active'));
    if ($active !== []) {
        foreach ($active as $e) {
            $kt = (string) $e['keyType'];
            if (in_array($kt, ['k4', 'k5'], true)) {
                $inh = $blockOrigin[(string) $e['key']] ?? ["unattributed:{$label($kt)}-block"];
                $origins = array_merge($origins, $inh);
            } else {
                $origins[] = $label($kt);
            }
        }

        return array_values(array_unique($origins));
    }
    $finals = array_values(array_filter($trace, static fn(array $e): bool => $e['ev'] === 'final'));
    $final = $finals === [] ? null : $finals[count($finals) - 1];
    if ($final === null || $final['decision'] === null) {
        return $denied ? ['unattributed:no-final'] : [];
    }
    $dec = (string) $final['decision'];
    $thresholds = array_values(array_filter($trace, static fn(array $e): bool => $e['ev'] === 'threshold' && $e['decision'] === $dec));
    $levelsE = array_values(array_filter($trace, static fn(array $e): bool => $e['ev'] === 'levels'));
    $levels = $levelsE === [] ? [] : (array) $levelsE[count($levelsE) - 1]['levels'];
    foreach ($final['candidates'] as $w) {
        if ($w['decision'] !== $dec) {
            continue;
        }
        $src = (string) $w['source'];
        if ($src === 'score' || $src === 'score:k4') {
            $kts = array_map(static fn(array $e): string => (string) $e['candidateKeyType'], $thresholds);
            $kts = $kts === [] ? ['unattributed:score'] : $kts;
        } elseif ($src === 'score_update:low_confidence_k3_to_k2') {
            $kts = ['k3'];
        } elseif (str_starts_with($src, 'score_update')) {
            $kts = [];
            foreach ($levels as $kt => $lvl) {
                if ($lvl > 0 && (($lvl >= 2) === ($dec === 'HARD_BLOCK'))) {
                    $kts[] = (string) $kt;
                }
            }
            $kts = $kts === [] ? ['unattributed:score_update'] : $kts;
        } else {
            $kts = [$src];
        }
        $kts = array_map($label, $kts);
        $origins = array_merge($origins, $kts);
        foreach ($w['keys'] as $key) {
            $blockOrigin[(string) $key] = array_values(array_unique(array_merge($blockOrigin[(string) $key] ?? [], $kts)));
        }
    }

    return array_values(array_unique($origins));
}

/**
 * g1Replay() with record-only verification hooks.
 * $o: trace (bool, TRACE build), c9 (bool), secrets (list), mutant (null | 'lane-leak' | 'adm-leak'), mutantAcct (?string).
 *
 * @param list<array{t: int, ctx: RateLimitContextDTO, proof: ?string, kind: string, own: bool, who: string, outcome: string}> $rows
 * @return array<string, mixed>
 */
function g1VReplay(G1Cell $cell, array $rows, string $flow, array $o = []): array
{
    $trace = (bool) ($o['trace'] ?? false);
    $c9 = (bool) ($o['c9'] ?? false);
    $secrets = $o['secrets'] ?? g1VSecrets();
    $mutant = $o['mutant'] ?? null;
    $mutantAcct = $o['mutantAcct'] ?? null;
    $mutantPick = $o['mutantPick'] ?? null;
    $mutantDone = false;
    $dec = $lanes = $reach = $kinds = '';
    $byCause = [];
    $blockOrigin = [];
    $benignOrigins = [];
    $attackOrigins = [];
    $c9v = [];
    $c9Checked = 0;
    $c9Mixed = 0;
    $evOrigins = [];

    $guard = static function (RateLimitContextDTO $c, string $lane, callable $op, string $what, int $i) use ($cell, $c9, $secrets, &$c9v, &$c9Checked, &$c9Mixed) {
        if (! $c9 || $lane !== 'U' || $c->accountId === null) {
            return $op();
        }
        $accts = array_values(array_unique(array_merge([$c->accountId], array_keys($cell->pAccounts))));
        sort($accts);
        $mixed = isset($cell->pAccounts[$c->accountId]);
        $before = g1VPState($cell, $accts, $secrets);
        $res = $op();
        $c9Checked++;
        $c9Mixed += $mixed ? 1 : 0;
        if (g1VPState($cell, $accts, $secrets) !== $before) {
            $c9v[] = ['i' => $i, 'what' => $what, 'acct' => $c->accountId, 'mixed' => $mixed];
        }

        return $res;
    };
    $cmd = function (RateLimitContextDTO $c, string $command, string $lane, int $i) use ($cell, $guard, $trace, $mutant, $mutantAcct, $mutantPick, &$mutantDone, &$blockOrigin): array {
        $leak = $mutant === 'lane-leak' && ! $mutantDone && $lane === 'U' && $c->accountId === $mutantAcct && $command === 'fail'
            && ($mutantPick === null || $mutantPick($c));
        $res = $guard($c, $lane, function () use ($cell, $c, $command, $lane, $leak): array {
            if ($leak) {
                // MUTANT M-C9a: the pipeline runs this U failure on the P lane (writes K4P) while the harness still labels it U.
                $cell->lab->pipeline->g1SetLane('P');

                return $cell->lab->run($c, $command);
            }

            return $cell->cmd($c, $command, $lane);
        }, "cmd:$command", $i);
        if ($leak) {
            $mutantDone = true;
        }
        $res['origins'] = [];
        if ($trace) {
            $t = $cell->lab->pipeline->g1TakeTrace();
            $res['origins'] = g1VOrigins($t, $lane, $blockOrigin, $res['d'] !== 'A');
        }

        return $res;
    };
    $admit = function (RateLimitContextDTO $c, string $lane, int $i) use ($cell, $guard, $mutant, $mutantAcct, &$mutantDone): array {
        return $guard($c, $lane, function () use ($cell, $c, $lane, $mutant, $mutantAcct, &$mutantDone): array {
            $a = $cell->admit($c, $lane);
            if ($mutant === 'adm-leak' && ! $mutantDone && $lane === 'U' && $a['ok'] && $c->accountId === $mutantAcct && $cell->adm !== null) {
                // MUTANT M-C9b: the U admission also consumes the account's P counters.
                $cell->adm->reserve('P', $cell->lab->ipPrefix($c->ip), $c->accountId, $c->accountId, null, $cell->now());
                $mutantDone = true;
            }

            return $a;
        }, 'admit', $i);
    };

    foreach ($rows as $i => $r) {
        $cell->lab->at($r['t']);
        $c = $r['ctx'];
        $lane = $cell->lane($c, $r['proof']);
        $api = $cell->shape === 'api';
        $denied = false;
        $cause = '-';
        $reached = false;
        $origins = [];
        if ($api) {
            $res = $cmd($c, $flow === 'out' ? 'ok' : 'check', $lane, $i);
            if ($res['d'] !== 'A') {
                [$denied, $cause, $origins] = [true, $cell->penaltyCause($c, $res['d'], $lane), $res['origins']];
            } else {
                $a = $admit($c, $lane, $i);
                if (! $a['ok']) {
                    [$denied, $cause] = [true, 'cap:' . $a['refused']];
                } elseif ($flow === 'pre+out') {
                    $cmd($c, 'ok', $lane, $i);
                }
            }
            $reached = ! $denied;
        } elseif ($flow === 'out') {
            $res = $cmd($c, $r['outcome'], $lane, $i);
            $reached = true;
            if ($res['d'] !== 'A') {
                [$denied, $cause, $origins] = [true, $cell->penaltyCause($c, $res['d'], $lane), $res['origins']];
            }
        } else {
            $res = $cmd($c, 'check', $lane, $i);
            if ($res['d'] !== 'A') {
                [$denied, $cause, $origins] = [true, $cell->penaltyCause($c, $res['d'], $lane), $res['origins']];
            } else {
                $a = $admit($c, $lane, $i);
                if (! $a['ok']) {
                    [$denied, $cause] = [true, 'cap:' . $a['refused']];
                } else {
                    $reached = true;
                    if ($flow === 'pre+out') {
                        $rec = $cmd($c, $r['outcome'], $lane, $i);
                        if ($rec['d'] !== 'A') {
                            [$denied, $cause, $origins] = [true, $cell->penaltyCause($c, $rec['d'], $lane), $rec['origins']];
                        }
                    }
                }
            }
        }
        $dec .= $denied ? ($cause === 'soft' || str_starts_with($cause, 'cap:') ? 'S' : 'H') : 'A';
        $lanes .= $lane;
        $reach .= $reached ? '1' : '0';
        $kinds .= $r['kind'] === 'X' ? ($r['own'] ? 'o' : 'X') : $r['kind'];
        if ($denied) {
            $key = str_starts_with($cause, 'cap:') ? $cause : ($trace ? 'origin:' . ($origins === [] ? 'none' : implode('+', $origins)) : $cause);
            if ($r['kind'] !== 'X') {
                $byCause[$cause] = ($byCause[$cause] ?? 0) + 1;
                $benignOrigins[$key] = ($benignOrigins[$key] ?? 0) + 1;
            } else {
                $attackOrigins[$key] = ($attackOrigins[$key] ?? 0) + 1;
            }
        }
    }
    ksort($byCause);
    ksort($benignOrigins);
    ksort($attackOrigins);

    return ['dec' => $dec, 'lanes' => $lanes, 'reach' => $reach, 'kinds' => $kinds, 'legitDeniedByCause' => $byCause,
        'benignOrigins' => $benignOrigins, 'attackOrigins' => $attackOrigins,
        'c9' => ['checked' => $c9Checked, 'mixedChecked' => $c9Mixed, 'violations' => $c9v], 'mutantApplied' => $mutantDone];
    // (mutantPick: optional predicate choosing which U failure the lane-leak mutant hits; null = the first one on mutantAcct)
}

/** C10-H verdict for one cell's benign origins: forbidden and unattributed counts. @return array{forbidden: int, unattributed: int} */
function g1VC10(array $benignOrigins): array
{
    $f = 0;
    $u = 0;
    foreach ($benignOrigins as $k => $n) {
        if (! str_starts_with($k, 'origin:')) {
            continue;
        }
        $parts = explode('+', substr($k, 7));
        if (array_intersect($parts, G1V_FORBIDDEN_ORIGINS) !== []) {
            $f += $n;
        }
        foreach ($parts as $p) {
            if (str_starts_with($p, 'unattributed') || $p === 'none') {
                $u += $n;
                break;
            }
        }
    }

    return ['forbidden' => $f, 'unattributed' => $u];
}

/**
 * C1-H independent recount from the replayed events, NOT from the admission log: every request admitted at T0 (reach = 1 in
 * pre / pre+out / api) is counted on its lane's scopes. Returns the worst count per scope.
 *
 * @return array<string, array{max: int, cap: int}>
 */
function g1VEventRecount(G1Cell $cell, array $rows, string $lanes, string $reach, string $flow, array $p): array
{
    if ($flow === 'out' && $cell->shape !== 'api') {
        return [];
    }
    $W = $p['W'];
    $n = [];
    foreach ($rows as $i => $r) {
        if ($reach[$i] !== '1') {
            continue;
        }
        $c = $r['ctx'];
        $w = intdiv($r['t'] + ($cell->lab->t0), $W);
        $d = intdiv($r['t'] + ($cell->lab->t0), 86400);
        $src = $cell->lab->ipPrefix($c->ip);
        $sc = match ($lanes[$i]) {
            'U' => ['src' => "$src@$w", 'acct' => $c->accountId === null ? null : "{$c->accountId}@$w", 'glob' => "all@$w"],
            // the admission layer's proof reference is the request's sessionDeviceId (G1Cell::admit())
            'P' => ['pacct' => "{$c->accountId}@$w", 'pacct_day' => "{$c->accountId}@$d", 'pproof' => "{$c->sessionDeviceId}@$w", 'pproof_day' => "{$c->sessionDeviceId}@$d"],
            default => ['asrc' => "$src@$w"],
        };
        foreach ($sc as $scope => $v) {
            if ($v !== null) {
                $n[$scope][$v] = ($n[$scope][$v] ?? 0) + 1;
            }
        }
    }
    $caps = ['src' => $p['U_src'], 'acct' => $p['U_acct'], 'glob' => $p['U_glob'], 'pacct' => $p['P_acct'], 'pacct_day' => $p['P_acct_day'],
        'pproof' => $p['P_proof'], 'pproof_day' => $p['P_proof_day'], 'asrc' => $p['A_src']];
    $out = [];
    foreach ($n as $scope => $vals) {
        $out[$scope] = ['max' => max($vals), 'cap' => $caps[$scope] > 0 ? $caps[$scope] : PHP_INT_MAX];
    }
    ksort($out);

    return $out;
}

/** @param array<string, array{max: int, cap: int}> $recount @return list<string> scopes over cap */
function g1VOverCap(array $recount): array
{
    $bad = [];
    foreach ($recount as $scope => $x) {
        if ($x['max'] > $x['cap']) {
            $bad[] = "$scope {$x['max']}>{$x['cap']}";
        }
    }

    return $bad;
}

/** Per-target attacker reach: max per (account, window) and number of distinct accounts reached. @return array{maxPerTargetWindow: int, targetsReached: int} */
function g1VTargetReach(array $rows, string $kinds, string $reach, int $W): array
{
    $n = [];
    $acc = [];
    foreach ($rows as $i => $r) {
        if (($kinds[$i] === 'X') && $reach[$i] === '1' && $r['ctx']->accountId !== null) {
            $k = $r['ctx']->accountId . '@' . intdiv($r['t'], $W);
            $n[$k] = ($n[$k] ?? 0) + 1;
            $acc[$r['ctx']->accountId] = true;
        }
    }

    return ['maxPerTargetWindow' => $n === [] ? 0 : max($n), 'targetsReached' => count($acc)];
}

/**
 * Mutation-control stores (VERIFY_PLAN.md §2). Wrap a real store; never used by the candidate.
 *   'over'    : every scope admits one unit over its cap (M-C1)
 *   'partial' : the first counter is incremented BEFORE validation (not all-or-nothing, M-R3)
 */
final class G1VWrapStore implements G1Store
{
    public function __construct(public G1Store $inner, public string $mode)
    {
    }

    public function reserve(array $counters, ?array $member, ?array $permit, int $now): array
    {
        if ($this->mode === 'over') {
            $counters = array_map(static fn(array $x): array => ['cap' => $x['cap'] + 1] + $x, $counters);
        } elseif ($this->mode === 'partial' && $counters !== []) {
            $first = $counters[0];
            if ($this->inner instanceof G1RefStore) {
                $cur = $this->inner->c[$first['key']] ?? null;
                $this->inner->c[$first['key']] = [($cur === null || $cur[1] <= $now ? 0 : $cur[0]) + 1, $cur === null || $cur[1] <= $now ? $now + $first['ttl'] : $cur[1]];
            } elseif ($this->inner instanceof G1RedisStore) {
                $this->inner->r->incr($first['key']);
            }
        }

        return $this->inner->reserve($counters, $member, $permit, $now);
    }

    public function claim(string $permitId, array $expected, string $outcome, int $now): string
    {
        return $this->inner->claim($permitId, $expected, $outcome, $now);
    }

    public function liveKeys(int $now): array
    {
        return $this->inner->liveKeys($now);
    }

    /** @return array<mixed> */
    public function pState(): array
    {
        return $this->inner instanceof G1RefStore ? [$this->inner->c, $this->inner->s] : [];
    }
}

/** Counter values of a reference or Redis store, for value-level all-or-nothing checks (R3-H). @return array<string, string> */
function g1VValues(G1Store $st, int $now): array
{
    $inner = $st instanceof G1VWrapStore ? $st->inner : $st;
    $out = [];
    if ($inner instanceof G1RefStore) {
        foreach ($inner->c as $k => [$v, $exp]) {
            if ($exp > $now) {
                $out[$k] = (string) $v;
            }
        }
    } elseif ($inner instanceof G1RedisStore) {
        foreach ($inner->r->keys('{g1}*') as $k) {
            $out[$k] = (string) $inner->r->get($k);
        }
    }
    ksort($out);

    return $out;
}

function g1VEmit(string $scenario, array $row): void
{
    g1Emit($scenario, $row);
}
