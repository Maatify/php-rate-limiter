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
// The R23-01 premise itself (F113-01): in every multi-session attacker stream the 12 target accounts are bound to 12 distinct, stable trusted
// sessions (a bijection on the events) and the REAL resolver derives 12 distinct, stable fingerprint hashes from them. The Host-violating
// controls must show the opposite: 12 accounts, ONE session, ONE fingerprint.
foreach (['TMS', 'TMSd', 'TMSs', 'TMR'] as $id) {
    $b = tmsSessionBindings($streams[$id]['events'], 12);
    if ($id === 'TMR') {
        // TMR rotates the client fingerprint on purpose: the session binding must still be a stable bijection, the fingerprint is not stable.
        $b['ok'] = $b['bijection'] && $b['trustedAll'] && $b['sessions'] === 12;
    }
    TmsAsserts::check('T01', "BINDING $id: 12 target accounts <-> 12 distinct trusted session identifiers (bijection, stable for the whole stream), and the real resolver derives one distinct fingerprint per account" . ($id === 'TMR' ? ' (session binding only; the client fingerprint rotates by design)' : ''), $b['ok'], $b);
}
foreach (['TMX', 'TMXb'] as $id) {
    $b = tmsSessionBindings($streams[$id]['events'], 12);
    TmsAsserts::check('T01', "BINDING $id control: 12 target accounts but ONE session identifier and ONE resolved fingerprint (Host-violating by DECISION_MATRIX §0)", $b['sessions'] === 1 && $b['fingerprints'] === 1 && $b['accounts'] === 12 && $b['trustedAll'] && ! $b['bijection'], $b);
}
$bu = tmsSessionBindings($streams['TMU']['events'], 12);
TmsAsserts::check('T01', 'BINDING TMU control: the TMSd bijection with isSessionTrusted = false on every request', $bu['bijection'] && $bu['stable'] && $bu['fingerprints'] === 12 && ! $bu['trustedAll'], $bu);
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

// Contract-COMPLIANT slice of TMXb: its first 25 requests target vic0, the account the single session is bound to. For that slice the
// request is a trusted session by DECISION_MATRIX §0, so it is a compliant measurement of the per-account bound of one trusted session.
$x = tmsMeasure($streams['TMXb']['events'], 'login', 'pre+out', true);
$slice = array_values(array_filter($x['rows'], static fn(array $r): bool => $r['subject'] === 'vic0'));
emit('T01.compliant', ['stream' => 'TMXb', 'account' => 'vic0', 'flow' => 'pre+out', 'requests' => count($slice), 'reached' => count(array_filter($slice, static fn(array $r): bool => $r['reached'])), 'denied' => count(array_filter($slice, static fn(array $r): bool => $r['blocked'])), 'spanSeconds' => $slice === [] ? 0 : end($slice)['t'] - $slice[0]['t'], 'runs' => k1Runs($slice)]);
TmsAsserts::finish();
