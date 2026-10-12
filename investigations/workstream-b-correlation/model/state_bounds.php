<?php

declare(strict_types=1);

// F103-05 — deterministic long-horizon proof that the bounded model (PW, P2) evicts expired state and keeps storage within
// its caps, measured on the entries RETAINED after every command. Exits non-zero on any violated assertion (run.sh gate).
//
// Fixtures (no randomness):
//   H1  24 h of failing attempts every 10 s, each with a NEW IP, NEW user-agent anchor and NEW client fingerprint (rotating UA).
//   H2  single K2 + single fingerprint: 40 distinct failing members inside ONE 60 s window, then one failure per minute for 3 h.
//   H3  idle drain: after H1, silence for 1 h, then ONE lone success — all expirable state must be gone.
require dirname(__DIR__) . '/lib/bootstrap.php';
require __DIR__ . '/PolicyModel.php';

$ev = static fn(int $t, string $ip, string $ua, string $fp, string $o = 'fail'): array => ['t' => $t, 'ip' => $ip, 'ua' => $ua, 'fp' => $fp, 'acct' => null, 'outcome' => $o, 'kind' => 'attack'];
$fail = 0;
$assert = static function (string $name, bool $ok, array $data) use (&$fail): void {
    if (! $ok) {
        $fail++;
    }
    emit('F103-05.state-bounds', ['assert' => $name, 'ok' => $ok] + $data);
};

foreach (['PW', 'P2'] as $policy) {
    // ---- H1: rotating-UA long horizon -------------------------------------------------------------------------------
    $m = new PolicyModel($policy, 'distinct');
    $perHour = [];
    for ($i = 0; $i < 8640; $i++) {
        $t = $i * 10;
        $m->attempt($ev($t, "ip$i", "ua$i", "fp$i"), 'out');
        $perHour[intdiv($t, 3600)] = max($perHour[intdiv($t, 3600)] ?? 0, $m->retained()['bounded']);
    }
    $firstHours = max(array_slice($perHour, 0, 2));
    $laterHours = max(array_slice($perHour, 2));
    $r = $m->retained();
    $assert("$policy.H1.plateau (peak after hour 2 never exceeds peak of first 2 h)", $laterHours <= $firstHours, ['peakFirst2h' => $firstHours, 'peakLater' => $laterHours, 'events' => 8640]);
    $assert("$policy.H1.ua-anchors-only-live-windows (<= 10 windows x 6 events = 60 UA anchors)", $r['uaAnchors'] <= 60, ['uaAnchorsRetained' => $r['uaAnchors'], 'uniqueUaSeen' => 8640]);
    $assert("$policy.H1.no-idealized-state", $r['idealized'] === 0 && $m->peakIdealizedWitnessEntries === 0, ['idealizedPeak' => $m->peakIdealizedWitnessEntries]);
    $assert("$policy.H1.scope-windows<=10", $r['maxScopeWindows'] <= PolicyModel::WINDOWS, ['maxScopeWindows' => $r['maxScopeWindows']]);
    $assert("$policy.H1.window-members<=cap", $r['maxWindowMembers'] <= PolicyModel::WITNESS_CAP, ['maxWindowMembers' => $r['maxWindowMembers']]);
    $assert("$policy.H1.reference-store-plateau (sets/flags/blocks expire by TTL)", $m->peakStoreEntries <= 1500, ['peakStoreEntries' => $m->peakStoreEntries]);

    // ---- H3: idle drain ---------------------------------------------------------------------------------------------
    $tEnd = 8639 * 10;
    $m->attempt($ev($tEnd + 3600, 'lone-ip', 'lone-ua', 'lone-fp', 'ok'), 'out');
    $r = $m->retained();
    $assert("$policy.H3.drained-after-1h-idle", $r['bounded'] <= 3 && $r['uaAnchors'] <= 1 && $r['failWinScopes'] === 0 && $r['latch'] === 0 && $r['store'] <= 4, $r + ['note' => 'only the lone success itself may remain (ratio counters for P2, reference-store members)']);

    // ---- H2: caps on one scope --------------------------------------------------------------------------------------
    $m = new PolicyModel($policy, 'distinct');
    $t0 = 600000;
    for ($i = 0; $i < 40; $i++) {
        $m->attempt($ev($t0 + $i, '198.51.100.9', 'one-ua', "member$i"), 'out');
    }
    $r = $m->retained();
    $assert("$policy.H2.cap-3-per-scope-window (40 distinct failing fingerprints in one window)", $r['maxWindowMembers'] <= 3, ['maxWindowMembers' => $r['maxWindowMembers']]);
    for ($i = 1; $i <= 180; $i++) {
        $m->attempt($ev($t0 + 60 * $i, '198.51.100.9', 'one-ua', "later$i"), 'out');
        $r = $m->retained();
        if ($r['maxScopeWindows'] > PolicyModel::WINDOWS || $r['maxWindowMembers'] > PolicyModel::WITNESS_CAP) {
            $assert("$policy.H2.window-bound-violated", false, $r + ['at' => $i]);
        }
    }
    $r = $m->retained();
    $assert("$policy.H2.10-window-sliding-eviction (3 h of one failure per minute on one K2)", $r['maxScopeWindows'] <= PolicyModel::WINDOWS && $r['bounded'] <= 2 * PolicyModel::WINDOWS * PolicyModel::WITNESS_CAP + 3 * PolicyModel::WINDOWS, $r);
}
exit($fail === 0 ? 0 : 1);
