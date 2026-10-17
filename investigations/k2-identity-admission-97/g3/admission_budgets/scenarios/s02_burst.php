<?php

declare(strict_types=1);

// G3 S02 — BURST40 (PLAN.md §4.2): one source sends 100 checkOnly on 100 distinct accounts in the same second; the outcomes
// (all failures) are recorded 2 s later; three windows. Arms S40, FC8, FCNA8. In this single-threaded reference harness FC8
// and FCNA8 read the same empty set during the burst (no outcome exists yet), so the measured leak is the outcome-coupling
// leak of #111, bounded by the outer U_src; FCNA8 is kept as the registered arm and reported as such.

require dirname(__DIR__) . '/lib/g3.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

$prov = g3Provenance();
if ($prov['build'] !== 'CAND') {
    fwrite(STDERR, "s02 must run on the CAND build\n");
    exit(4);
}
foreach (['S40' => [], 'FC8' => ['fc' => 8], 'FCNA8' => ['fc' => 8]] as $arm => $opts) {
    [$cell, $gate] = g3Cell(g3Params(40), $opts);
    $perWindow = [];
    for ($w = 0; $w < 3; $w++) {
        $t = $w * 600 + 10;
        $admitted = [];
        $cell->lab->at($t);
        for ($i = 0; $i < 100; $i++) {
            $c = new RateLimitContextDTO('203.0.113.77', CHROME, "acct-b$w-$i", null, null, false, [], false, null);
            $res = $cell->cmd($c, 'check', 'U');
            if ($res['d'] === 'A' && $gate->admit($cell, $c, 'U')['ok']) {
                $admitted[] = $c;
            }
        }
        $cell->lab->at($t + 2);
        foreach ($admitted as $c) {
            $cell->cmd($c, 'fail', 'U');
            $gate->outcome($cell, $c, 'U', 'fail', false);
        }
        $later = 0;
        $cell->lab->at($t + 30);
        for ($i = 0; $i < 20; $i++) {
            $c = new RateLimitContextDTO('203.0.113.77', CHROME, "acct-c$w-$i", null, null, false, [], false, null);
            if ($cell->cmd($c, 'check', 'U')['d'] === 'A' && $gate->admit($cell, $c, 'U')['ok']) {
                $later++;
            }
        }
        $perWindow[] = ['burstAdmitted' => count($admitted), 'afterOutcomesAdmitted' => $later];
    }
    g3Emit('s02', ['arm' => $arm, 'windows' => $perWindow]);
}
