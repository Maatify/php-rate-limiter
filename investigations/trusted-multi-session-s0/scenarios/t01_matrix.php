<?php

declare(strict_types=1);

// T01 — the R23-01 matrix on S0: every stream in the Host flows pre+out / pre (checkOnly only) / out (record-only) on Login (with account),
// plus the OTP shape for the attacker streams and the trusted office. Same events, same controlled clock, same attribution as PR #108 / #111.
require dirname(__DIR__) . '/lib/tms.php';

emit('T01.provenance', tmsProvenance());
$streams = tmsStreams();
foreach (tmsMatrixCells() as [$id, $shape, $flow]) {
    emit('T01.matrix', ['stream' => $id, 'class' => tmsStreamClasses()[$id], 'shape' => $shape, 'flow' => $flow] + tmsMeasure($streams[$id]['events'], $shape, $flow));
}
foreach (tmsStreamClasses() as $id => $class) {
    $s = $streams[$id];
    $times = array_column($s['events'], 't');
    emit('T01.stream', ['stream' => $id, 'title' => $s['title'], 'class' => $class, 'events' => count($s['events']), 'spanSeconds' => max($times) - min($times), 'trustedEvents' => count(array_filter($s['events'], static fn(array $e): bool => (bool) ($e['trusted'] ?? false)))]);
}

// S0 invariants (hard-fail): the published #108 numbers of the reference streams must be reproduced on the real pipeline, otherwise this
// study is not measuring the same runtime the trust family was reviewed against.
$m = static fn(string $id, string $flow): array => tmsMeasure($streams[$id]['events'], 'login', $flow);
$n12t = $m('N12T', 'pre+out');
$sp5 = $m('SP5', 'pre+out');
TmsAsserts::check('T01', 'S0 N12T reproduces the published 56/61 legitimate denials (Login, pre+out)', $n12t['legitDenied'] === 56 && $n12t['legitAttempts'] === 61, ['N12T' => $n12t['legitDenied']]);
TmsAsserts::check('T01', 'S0 SP5 reproduces the published 200/200 attacker verifications from one trusted session against 200 fresh accounts (Login, pre+out)', $sp5['attackReachedVerification'] === 200 && $sp5['attackAttempts'] === 200, ['SP5' => $sp5['attackReachedVerification']]);
// Structural sanity of the study's own streams (fail-closed on a mis-built stream, not a policy claim).
TmsAsserts::check('T01', 'STREAM TMS is N12T request for request (t, ip, ua, fingerprint index, trust) with every outcome = fail', (static function () use ($streams): bool {
    $a = $streams['N12T']['events'];
    $b = $streams['TMS']['events'];
    if (count($a) !== count($b)) {
        return false;
    }
    foreach ($a as $i => $e) {
        $f = $b[$i];
        if ($e['t'] !== $f['t'] || $e['ip'] !== $f['ip'] || $e['ua'] !== $f['ua'] || $e['fp'] !== $f['fp'] || $f['outcome'] !== 'fail' || ($f['trusted'] ?? false) !== true) {
            return false;
        }
    }

    return true;
})(), ['events' => count($streams['TMS']['events'])]);
TmsAsserts::check('T01', 'STREAM every attacker stream carries exactly 12 distinct target accounts and sits on the N12T NAT address', (static function () use ($streams): bool {
    foreach (['TMS', 'TMSd', 'TMSs', 'TMR', 'TMX', 'TMXb', 'TMU'] as $id) {
        $accts = array_unique(array_column($streams[$id]['events'], 'acct'));
        $ips = array_unique(array_column($streams[$id]['events'], 'ip'));
        if (count($accts) !== 12 || $ips !== [NAT]) {
            return false;
        }
    }

    return true;
})());
TmsAsserts::finish();
