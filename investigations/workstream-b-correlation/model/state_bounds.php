<?php

declare(strict_types=1);

// F103-05 — deterministic long-horizon proof that the bounded model (PW, P2) evicts expired state and keeps storage within
// its caps, measured on the entries RETAINED after every command. Exits non-zero on any violated assertion (run.sh gate).
//
// Sections: H1/H2/H3 = end-to-end, enforcement-COUPLED regressions; C1/C2 = ISOLATED storage-cap probes (enforcement suppressed on purpose).
// Fixtures (no randomness):
//   H1  24 h of failing attempts every 10 s, each with a NEW IP, NEW user-agent anchor and NEW client fingerprint (rotating UA).
//   H2  single K2 + single fingerprint: 40 distinct failing members inside ONE 60 s window, then one failure per minute for 3 h.
//   H3  idle drain: after H1, silence for 1 h, then ONE lone success — all expirable state must be gone.
require dirname(__DIR__) . '/lib/bootstrap.php';
require __DIR__ . '/PolicyModel.php';

// --self-test-violation: runs the isolated storage-cap probes against a DELIBERATELY uncapped witness store (cap 1000).
// The gate must then FAIL (exit 1); run.sh asserts that, proving the assertions can actually fail.
$selfTest = in_array('--self-test-violation', $argv, true);
$cap = $selfTest ? 1000 : PolicyModel::WITNESS_CAP;

$ev = static fn(int $t, string $ip, string $ua, string $fp, string $o = 'fail'): array => ['t' => $t, 'ip' => $ip, 'ua' => $ua, 'fp' => $fp, 'acct' => null, 'outcome' => $o, 'kind' => 'attack'];
$fail = 0;
$assert = static function (string $name, bool $ok, array $data) use (&$fail): void {
    if (! $ok) {
        $fail++;
    }
    emit('F103-05.state-bounds', ['assert' => $name, 'ok' => $ok] + $data);
};

if (! $selfTest) {
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
}

// ======================================================================================================================
// C1/C2 — ISOLATED storage-cap probes (F103-05b). INTENTIONALLY NON-PRODUCTION CONTROL SEMANTICS:
//   observe='pre' + record-only flow means correlation observation/enforcement is SUPPRESSED (observeCommandInner returns
//   right after the witness write for the record phase), while every failed record still reaches writeWitness(). So no block is
//   ever issued and no attempt can be short-circuited — the cap/eviction behaviour is exercised in isolation from enforcement.
// ======================================================================================================================
foreach (['PW', 'P2'] as $policy) {
    // C1a: 40 DISTINCT failing fingerprints on ONE K2 inside ONE 60 s window, all admitted.
    $m = new PolicyModel($policy, 'pre', false, $cap);
    $t0 = 1200000; // window-aligned (1200000 / 60 = 20000)
    for ($i = 0; $i < 40; $i++) {
        $m->attempt($ev($t0 + $i, '198.51.100.9', 'cap-ua', "member$i"), 'out');
    }
    $scope = 'k2:198.51.100.9|cap-ua';
    $win = intdiv($t0, 60);
    $assert("$policy.C1a.all-40-admitted-to-witness-path", $m->witnessWriteAttempts === 40 && $m->cycles === 0, ['witnessWriteAttempts' => $m->witnessWriteAttempts, 'blocksIssued' => $m->cycles]);
    $assert("$policy.C1a.exactly-3-members-retained-for-k2-window (40 distinct offered)", count($m->windowMembers($scope, $win)) === 3, ['retained' => count($m->windowMembers($scope, $win)), 'members' => $m->windowMembers($scope, $win), 'offered' => 40]);

    // C1b: ONE fingerprint failing from 40 DISTINCT K1s (fp scope), one window, all admitted.
    $m = new PolicyModel($policy, 'pre', false, $cap);
    for ($i = 0; $i < 40; $i++) {
        $m->attempt($ev($t0 + $i, "10.0.0.$i", 'cap-ua', 'one-fp'), 'out');
    }
    $assert("$policy.C1b.all-40-admitted-to-witness-path", $m->witnessWriteAttempts === 40 && $m->cycles === 0, ['witnessWriteAttempts' => $m->witnessWriteAttempts, 'blocksIssued' => $m->cycles]);
    $assert("$policy.C1b.exactly-3-k1-members-retained-for-fp-window (40 distinct offered)", count($m->windowMembers('fp:one-fp', $win)) === 3, ['retained' => count($m->windowMembers('fp:one-fp', $win)), 'offered' => 40]);

    // C2: 14 consecutive 60 s windows, one admitted failure per window on the SAME scope.
    $m = new PolicyModel($policy, 'pre', false, $cap);
    $scope = 'k2:198.51.100.10|win-ua';
    $trace = [];
    for ($w = 0; $w < 14; $w++) {
        $m->attempt($ev($t0 + 60 * $w + 5, '198.51.100.10', 'win-ua', "w$w"), 'out');
        $trace[] = $m->windowIds($scope);
    }
    $base = $win;
    $at10th = $trace[9];   // after the 10th window's write: windows base..base+9, all 10 live
    $at11th = $trace[10];  // after the 11th window's write: window `base` MUST be evicted, base+1..base+10 live
    $at14th = $trace[13];
    $assert("$policy.C2.boundary-10th-window: exactly 10 live, none evicted yet", $at10th === range($base, $base + 9), ['live' => [$at10th[0] - $base, end($at10th) - $base], 'count' => count($at10th)]);
    $assert("$policy.C2.boundary-11th-window: oldest window evicted, 10 live", $at11th === range($base + 1, $base + 10) && ! in_array($base, $at11th, true), ['liveOffsets' => [$at11th[0] - $base, end($at11th) - $base], 'count' => count($at11th), 'window0Present' => in_array($base, $at11th, true)]);
    $assert("$policy.C2.14-windows-never-exceed-10-live-and-slide", $at14th === range($base + 4, $base + 13) && max(array_map('count', $trace)) === 10 && $m->witnessWriteAttempts === 14 && $m->cycles === 0, ['liveOffsets' => [$at14th[0] - $base, end($at14th) - $base], 'maxLive' => max(array_map('count', $trace)), 'admitted' => $m->witnessWriteAttempts, 'blocksIssued' => $m->cycles]);
}
exit($fail === 0 ? 0 : 1);
