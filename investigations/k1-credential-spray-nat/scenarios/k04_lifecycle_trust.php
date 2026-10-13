<?php

declare(strict_types=1);

// K04 — command lifecycle and trust qualification of the K1 spray rule (Login and OTP).
//   L1 which Host commands observe spray: checkOnly yes, record-only NO, recordFailure/recordSuccess after an allowed check do NOT repeat it
//   L2 a record-only Host has no K1 spray protection at all
//   T1 who is "trusted" for K1 advisory: only a session-bound trusted device; known/verified devices and half-trusted shapes are NOT
//   T2 advisory is not exemption: a trusted request that meets the threshold still persists the K1 block that denies untrusted traffic
require dirname(__DIR__) . '/lib/k1.php';

k1Emit('K04.provenance', k1Provenance());
$IP = '203.0.113.88';
$sub = static fn(int $u, int $t, string $o = 'fail', array $x = []): array => k1Ev($t, $IP, "fp$u", "s$u", $o, 'legit', $x + ['uaRaw' => k1Ua($u)]);

// L1 — 8 distinct subjects, one SUCCESSFUL login each (the spray set is outcome-independent; a successful outcome keeps OTP's own failure
// scoring out of the picture), per flow. Observations stop once the K1 block is active (the engine returns early on an active block).
foreach (['login', 'otp'] as $shape) {
    $stat = [];
    foreach (['pre+out', 'pre', 'out'] as $flow) {
        foreach ([4, 8] as $n) {
            $lab = k1Lab('A', $shape === 'otp' ? 'otp' : 'login');
            $ev = [];
            for ($u = 1; $u <= $n; $u++) {
                $ev[] = $sub($u, $u - 1, 'ok');
            }
            $rows = k1Replay($lab, $ev, $flow, $shape);
            $firstDeny = null;
            foreach ($rows as $x) {
                if ($x['blocked']) {
                    $firstDeny = $x['t'];
                    break;
                }
            }
            $stat[$flow . '/' . $n] = ['sprayObservations' => array_sum(array_column($rows, 'sprayOps')), 'attempts' => count($rows), 'firstDenyT' => $firstDeny, 'reachedVerification' => count(array_filter($rows, static fn(array $x): bool => $x['reached']))];
            k1Emit('K04.L1.lifecycle', ['shape' => $shape, 'flow' => $flow, 'subjects' => $n] + $stat[$flow . '/' . $n]);
        }
    }
    K1Asserts::check('K04', "L1 [$shape] 4 distinct subjects (no block): pre+out and checkOnly-only make the SAME 4 observations, one per logical attempt — recordFailure/recordSuccess after an allowed check add none (no double count)", $stat['pre+out/4']['sprayObservations'] === 4 && $stat['pre/4']['sprayObservations'] === 4, [$stat['pre+out/4'], $stat['pre/4']]);
    K1Asserts::check('K04', "L1 [$shape] 8 distinct subjects: the 5th is denied at t=4 in both pre+out and checkOnly-only; observations stop at 5 because the active K1 block short-circuits the engine; 4 attempts reach verification", $stat['pre+out/8']['firstDenyT'] === 4 && $stat['pre/8']['firstDenyT'] === 4 && $stat['pre+out/8']['sprayObservations'] === 5 && $stat['pre/8']['sprayObservations'] === 5 && $stat['pre+out/8']['reachedVerification'] === 4, [$stat['pre+out/8'], $stat['pre/8']]);
    K1Asserts::check('K04', "L2 [$shape] record-only: ZERO spray observations and no K1 denial for 8 distinct subjects — a Host that only calls recordFailure/recordSuccess has no K1 spray protection", $stat['out/8']['sprayObservations'] === 0 && $stat['out/8']['firstDenyT'] === null && $stat['out/8']['reachedVerification'] === 8, $stat['out/8']);
}

// T1 — who is trusted for K1 advisory. 5 untrusted subjects fill the set (the 5th is denied at t=4), then ONE probing request at t=10 from a
// 6th subject with its own browser major (K2 sees one fingerprint), under each trust shape.
$shapes = [
    'untrusted, no session' => [],
    'session id present, NOT trusted' => ['sess' => 'sess-p', 'trusted' => false],
    'trusted flag but NO session id' => ['sess' => '', 'trusted' => true],
    'previously verified device (known), no session trust' => ['verified' => true],
    'trusted session device' => ['sess' => 'sess-p', 'trusted' => true],
];
$allowed = [];
foreach ($shapes as $label => $x) {
    $ev = [];
    for ($u = 1; $u <= 5; $u++) {
        $ev[] = $sub($u, $u - 1, 'ok');
    }
    $ev[] = $sub(6, 10, 'ok', $x + ['who' => 'probe']);
    $lab = k1Lab('A');
    $rows = k1Replay($lab, $ev, 'pre', 'login');
    $p = $rows[5];
    $allowed[$label] = ! $p['blocked'];
    k1Emit('K04.T1.trust-shape', ['shape' => $label, 'allowed' => ! $p['blocked'], 'src' => $p['src'], 'sprayCount' => $p['sprayCount']]);
}
K1Asserts::check('K04', 'T1 only a session-bound trusted device is exempt from K1 enforcement: untrusted / session-not-trusted / trusted-flag-without-session / previously-verified (known) devices are all denied', $allowed['untrusted, no session'] === false && $allowed['session id present, NOT trusted'] === false && $allowed['trusted flag but NO session id'] === false && $allowed['previously verified device (known), no session trust'] === false && $allowed['trusted session device'] === true, $allowed);

// T2 — advisory is not erasure: 5 untrusted subjects fill the set, a TRUSTED request meets the threshold at t=10 (allowed, advisory) and an
// untrusted request right after is denied by the persisted K1 block; the trusted request's own block issuance is the persistence.
$ev = [];
for ($u = 1; $u <= 4; $u++) {
    $ev[] = $sub($u, $u - 1, 'ok');
}
$ev[] = $sub(5, 4, 'ok', ['trusted' => true, 'sess' => 'sess-5', 'who' => 'trusted']);
$ev[] = $sub(6, 5, 'ok', ['who' => 'untrusted']);
$lab = k1Lab('A');
$rows = k1Replay($lab, $ev, 'pre', 'login');
k1Emit('K04.T2.advisory-persists', ['trustedAllowed' => ! $rows[4]['blocked'], 'trustedIssued' => $rows[4]['issued'], 'untrustedDenied' => $rows[5]['blocked'], 'untrustedSrc' => $rows[5]['src']]);
K1Asserts::check('K04', 'T2 the 5th subject being TRUSTED is allowed (advisory) but the K1 block it triggers is persisted and denies the next untrusted subject of the same IP', $rows[4]['blocked'] === false && $rows[4]['issued'] === ['K1'] && $rows[5]['blocked'] === true && str_starts_with((string) $rows[5]['src'], 'persisted:K1'), ['untrustedSrc' => $rows[5]['src']]);
K1Asserts::finish();
