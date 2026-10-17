<?php

declare(strict_types=1);

// G1 M07 — conditional source capacity ceiling (D7, ARCHITECTURE_PROPOSAL.md §6.7): SC-1 … SC-5 on the candidate. SC-6
// (innocent co-tenant refusals) is aggregated by report.php from the M01 matrix (cause cap:src / cap:asrc per NAT stream).
// The LOCKED KEY_STRATEGY is NOT amended; this only tests the conditional option.

require dirname(__DIR__) . '/lib/g1.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

g1Provenance();
if (g1Runtime() !== 'CAND') {
    fwrite(STDERR, "m07 runs on the candidate runtime only\n");
    exit(4);
}
const SRC = '198.51.100.88';
$p = ['U_src' => 4] + g1Params('R');

function scCtx(string $acct, ?string $sid = null, bool $trusted = false, string $ua = CHROME, ?string $fp = null): RateLimitContextDTO
{
    return new RateLimitContextDTO(SRC, $ua, $acct, $fp === null ? null : fp($fp), $sid, $trusted, [], false, null);
}

function scTry(G1Cell $cell, RateLimitContextDTO $c, ?string $proof, string $outcome = 'fail'): string
{
    $lane = $cell->lane($c, $proof);
    $r = $cell->cmd($c, 'check', $lane);
    if ($r['d'] !== 'A') {
        return $cell->penaltyCause($c, $r['d'], $lane);
    }
    $a = $cell->admit($c, $lane);
    if (! $a['ok']) {
        return 'cap:' . $a['refused'];
    }
    $cell->cmd($c, $outcome, $lane);

    return 'A';
}

/** @return array<string, mixed> scores, budgets and watcher counts of every account touched, plus K1 block state */
function scState(G1Cell $cell, array $accts): array
{
    $o = [];
    foreach ($accts as $a) {
        $c = scCtx($a);
        $o[$a] = $cell->lab->scores($c) + ['blocks' => $cell->lab->blocks($c), 'w' => ci97WatcherCounts($cell->lab, $c)];
    }

    return $o;
}

$cell = new G1Cell('CAND', 'login', $p);
$base = $cell->lab->t0 - ($cell->lab->t0 % 600) + 600 - $cell->lab->t0; // start of the next fixed window, relative
$seq = [];
for ($i = 0; $i < 5; $i++) {
    $cell->lab->at($base + $i);
    if ($i === 4) {
        $before = scState($cell, ['v0', 'v1', 'v2', 'v3', 'v4']);
    }
    $seq[] = scTry($cell, scCtx("v$i"), null);
}
$after = scState($cell, ['v0', 'v1', 'v2', 'v3', 'v4']);
g1Emit('m07', ['sc' => 'SC-1 cause', 'sequence' => $seq, 'pass' => end($seq) === 'cap:src']);
g1Emit('m07', ['sc' => 'SC-3 no escalation', 'stateUnchangedByRefusal' => $before === $after, 'pass' => $before === $after]);
$cell->lab->at($base + 600);
$next = scTry($cell, scCtx('v5'), null);
$k1Blocked = $cell->lab->blocks(scCtx('v5'))['K1'] ?? null;
g1Emit('m07', ['sc' => 'SC-2 no persistence', 'nextWindow' => $next, 'k1Block' => $k1Blocked, 'pass' => $next === 'A' && $k1Blocked === null]);

// SC-4 — the source is exhausted for U; a proven request from it is admitted (its own scopes have headroom).
$cell = new G1Cell('CAND', 'login', $p);
for ($i = 0; $i < 4; $i++) {
    $cell->lab->at($base + $i);
    scTry($cell, scCtx("w$i"), null);
}
$cell->lab->at($base + 10);
$u = scTry($cell, scCtx('w9'), null);
$pr = scTry($cell, scCtx('acctP', 'sd-acctP-1', true), 'acctP', 'ok');
g1Emit('m07', ['sc' => 'SC-4 lane scope', 'unprovenAfterExhaustion' => $u, 'provenFromSameSource' => $pr, 'pass' => $u === 'cap:src' && $pr === 'A']);

// SC-5 — rotation from one source (UA major and client fingerprint per request, 30 accounts): admissions stay at U_src per window.
$cell = new G1Cell('CAND', 'login', $p);
$admitted = 0;
for ($i = 0; $i < 30; $i++) {
    $cell->lab->at($base + 5 * $i);
    $admitted += scTry($cell, scCtx("r$i", null, false, k1Ua(100 + $i), "rot$i"), null) === 'A' ? 1 : 0;
}
g1Emit('m07', ['sc' => 'SC-5 rotation invariance', 'attempts' => 30, 'admittedInOneWindow' => $admitted, 'cap' => $p['U_src'], 'pass' => $admitted <= $p['U_src']]);
