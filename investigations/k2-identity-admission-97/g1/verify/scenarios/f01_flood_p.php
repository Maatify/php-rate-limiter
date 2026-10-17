<?php

declare(strict_types=1);

// G1 final verification F01 — counterexample FLOOD-P (FLOOD_PLAN.md §2). Runs on G1_BUILD=TRACE (CAND), DAO and DAOF.
// Six distinct U devices on acct-fl (t = 0 … 50), then the owner's device unproven (t = 60, proof expired, same device secret),
// then the same device proven (t = 120 … 300). Control: the owner sequence alone. Per owner request: lane, decision, trace origin,
// the device's K5 block after the request, and whether the account flood stage is active.
// FLOOD-P / FLOOD-Pok are exactly as pre-declared (population on six distinct IPs). The device count behind the flood rule is keyed
// by accountAnchor() = K4 + K1, i.e. per (account, source): six devices on six IPs never reach it. FLOOD-P-NAT / FLOOD-Pok-NAT are
// ADDED (disclosed in G1_FINAL_DISPOSITION.md): the population shares the owner's IP (household / office NAT, or an attacker in the
// same IPv4 / IPv6 /64), and one more proven request at t = 90 lies inside the 60 s L2 duration (t = 120 is its expiry instant).

require dirname(__DIR__) . '/lib/verify.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

$prov = g1Provenance();
if (! in_array($prov['build'], ['TRACE', 'DAO', 'DAOF'], true)) {
    fwrite(STDERR, "f01 must run on the TRACE, DAO or DAOF build\n");
    exit(4);
}
$arm = ['TRACE' => 'CAND', 'DAO' => 'CAND-no-DA-punishment', 'DAOF' => 'CAND-no-DA-no-flood-punishment'][$prov['build']];
$p = g1Params('R');
$owner = static fn(bool $proven): RateLimitContextDTO => new RateLimitContextDTO('198.51.100.170', FIREFOX_130, 'acct-fl', null, 'sd-fl-owner', $proven, [], false, null);
$fixture = static function (bool $population, string $popOutcome, bool $nat) use ($owner): array {
    $rows = [];
    if ($population) {
        for ($i = 0; $i < 6; $i++) {
            $rows[] = ['t' => 10 * $i, 'ctx' => new RateLimitContextDTO($nat ? '198.51.100.170' : '192.0.2.' . (80 + $i), k1Ua(100 + $i), 'acct-fl', null, null, false, [], false, null),
                'proof' => null, 'kind' => 'X', 'own' => false, 'who' => "pop$i", 'outcome' => $popOutcome];
        }
    }
    $rows[] = ['t' => 60, 'ctx' => $owner(false), 'proof' => null, 'kind' => 'O', 'own' => false, 'who' => 'owner', 'outcome' => 'ok'];
    foreach ($nat ? [90, 120, 180, 240, 300] : [120, 180, 240, 300] as $t) {
        $rows[] = ['t' => $t, 'ctx' => $owner(true), 'proof' => 'acct-fl', 'kind' => 'O', 'own' => false, 'who' => 'owner', 'outcome' => 'ok'];
    }

    return $rows;
};
$floodStage = static function (G1Cell $cell): int {
    $p = $cell->lab->policy->getName();

    return $cell->lab->corr->getWatchFlag(hash_hmac('sha256', "{$p}:rate_limiter:aux:flood_stage:v1:prod:acct-fl", Lab::SECRET));
};
foreach (['FLOOD-P' => ['fail', false], 'FLOOD-Pok' => ['ok', false], 'FLOOD-P-NAT' => ['fail', true], 'FLOOD-Pok-NAT' => ['ok', true]] as $fx => [$popOutcome, $nat]) {
    $out = [];
    foreach (['population' => true, 'control' => false] as $label => $pop) {
        $rows = $fixture($pop, $popOutcome, $nat);
        $cell = new G1Cell('CAND', 'login', $p);
        $events = [];
        // Replay one request at a time on the same cell so the device's K5 block and the flood stage can be read after each owner
        // request; the per-request decisions equal a single full replay (the cell carries all state).
        $blockOrigin = [];
        $trace = [];
        foreach ($rows as $i => $row) {
            $r = g1VReplay($cell, [$row], 'pre+out', ['trace' => true, 'blockOrigin' => &$blockOrigin]);
            if ($row['kind'] === 'O') {
                $snap = g1Snap($cell, $row['ctx']);
                $events[] = ['t' => $row['t'], 'lane' => $r['lanes'], 'decision' => $r['dec'], 'origin' => $r['evOrigins'][0] ?? '-',
                    'k5Block' => $snap['blkK5'], 'floodStage' => $floodStage($cell)];
            } else {
                $events[] = ['t' => $row['t'], 'who' => $row['who'], 'lane' => $r['lanes'], 'decision' => $r['dec'], 'reached' => $r['reach'],
                    'origin' => $r['evOrigins'][0] ?? '-', 'floodStage' => $floodStage($cell)];
            }
        }
        $out[$label] = $events;
    }
    $provenAfter = static fn(array $ev): array => array_values(array_filter($ev, static fn(array $e): bool => ! isset($e['who']) && $e['t'] > 60));
    $pp = $provenAfter($out['population']);
    $pc = $provenAfter($out['control']);
    $floodDenied = count(array_filter($pp, static fn(array $e): bool => $e['decision'] !== 'A' && str_contains($e['origin'], 'flood')));
    $ctlFloodDenied = count(array_filter($pc, static fn(array $e): bool => $e['decision'] !== 'A' && str_contains($e['origin'], 'flood')));
    $popReach = count(array_filter($out['population'], static fn(array $e): bool => isset($e['who']) && ($e['reached'] ?? '0') === '1'));
    g1VEmit('f01', ['arm' => $arm, 'fixture' => $fx, 'population' => $out['population'], 'control' => $out['control'],
        'provenDecisions' => implode('', array_column($pp, 'decision')), 'controlProvenDecisions' => implode('', array_column($pc, 'decision')),
        'provenDeniedByFlood' => $floodDenied, 'controlProvenDeniedByFlood' => $ctlFloodDenied, 'populationReach' => $popReach,
        'counterexampleConfirmed' => $floodDenied > 0 && $ctlFloodDenied === 0,
        'ownerIdenticalToControl' => implode('', array_column(array_filter($out['population'], static fn(array $e): bool => ! isset($e['who'])), 'decision'))
            === implode('', array_column($out['control'], 'decision'))]);
}
