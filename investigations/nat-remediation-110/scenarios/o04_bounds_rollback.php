<?php

declare(strict_types=1);

// O04 — storage bounds of the OQC state and rollback / mixed-version safety on the loaded arm (reference store, controlled clock).
//   B1  one scope -> exactly the two expected liveness markers (K1 scope, K2 scope), expiry = first outcome + 600 s
//   B2  N scopes -> 2N markers, all gone after 600 s (linear in observed scopes, TTL-bounded, no per-request keys)
//   B3  arm-specific extra keys (OQCW tally per K1 scope, OQCE re-issue counter per triggered scope) are TTL-bounded
//   R1  rollback: the OLD code (arm S0) reads state written by the candidate without error and keeps S0 semantics
//   R2  mixed version: alternating old/new code per request on the attacker stream never errors
require dirname(__DIR__) . '/lib/oqc.php';

use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;

emit('O04.provenance', oqcProvenance());
$arm = oqcArm();
$cand = $arm !== 'S0';
$streams = oqcStreams();
$flagsOf = static function (Lab $lab): array {
    $ref = new \ReflectionClass(StatefulInMemoryCorrelationStore::class);

    return $ref->getProperty('flags')->getValue($lab->corr);
};
$churnScope = static fn(string $k2): string => hash_hmac('sha256', 'login_protection:rate_limiter:correlation:churn:v1:prod:scope:' . $k2, Lab::SECRET);

// B1 — one IP+UA scope, one colleague, one successful login at t=0.
$lab = oqcLab();
$liveKey = static fn(string $scope): string => hash('sha256', 'oqc:live:v1:' . $scope . ':' . intdiv($lab->t0, 600)); // window id of t=0
$ev = k1Ev(0, '198.51.100.93', 'fpb', 'b1', 'ok', 'legit');
$c = k1Ctx($ev, 'login');
$keys = $lab->keys($c);
$expected = [$liveKey('credential_spray:' . $keys['K1']), $liveKey($churnScope($keys['K2']))];
if ($arm === 'OQCW') {
    $expected[] = hash('sha256', 'oqc:tally:v1:credential_spray:' . $keys['K1']); // the K1 arrival tally of arm 1b, one per K1 scope
}
sort($expected);
k1Replay($lab, [$ev], 'pre+out', 'login');
$flags = $flagsOf($lab);
$present = array_keys($flags);
sort($present);
$expiry = array_values(array_unique(array_map(static fn(array $f): ?int => $f['expiresAt'], $flags)));
emit('O04.B1.one-scope', ['arm' => $arm, 'flags' => count($flags), 'expectedPresent' => $present === $expected, 'expiresAtOffsets' => array_map(static fn(?int $e): ?int => $e === null ? null : $e - $lab->t0, $expiry)]);
if ($cand) {
    $wantExpiry = $arm === 'OQCW' ? [$lab->t0 + 1200, $lab->t0 + 600] : [$lab->t0 + 1200];
    sort($wantExpiry);
    $gotExpiry = $expiry;
    sort($gotExpiry);
    OqcAsserts::check('O04', 'B1 one outcome-live scope holds exactly the expected keys (sha-256 of "oqc:live:v1:" + the K1 spray scope / the K2 churn scope key + the 600 s window id; plus one K1 tally on arm OQCW), markers expiring 1200 s after the first outcome (tally 600 s)', $present === $expected && $gotExpiry === $wantExpiry, ['flags' => count($flags), 'expectedKeys' => count($expected), 'offsets' => array_map(static fn(?int $e): ?int => $e === null ? null : $e - $lab->t0, $expiry)]);
} else {
    OqcAsserts::check('O04', 'B1 S0 holds no WATCH flag after one successful login', $flags === [], ['flags' => count($flags)]);
}

// B2 — 50 distinct IPs, one successful login each at t=i: 2 markers per scope, none per request, all expired at t=650.
$lab = oqcLab();
$events = [];
for ($i = 0; $i < 50; $i++) {
    $events[] = k1Ev($i, '198.51.101.' . ($i + 1), "fp$i", "b2u$i", 'ok', 'legit');
}
k1Replay($lab, $events, 'pre+out', 'login');
$n50 = count($flagsOf($lab));
$lab->at(599);
$alive599 = wsbStoreStats($lab)['flags'];
$lab->at(1199);
$alive1199 = wsbStoreStats($lab)['flags'];
$lab->at(1200 + 49);
$alive1249 = wsbStoreStats($lab)['flags'];
$perScope = $arm === 'OQCW' ? 3 : 2;
emit('O04.B2.many-scopes', ['arm' => $arm, 'scopes' => 50, 'flagsWritten' => $n50, 'aliveAt599' => $alive599, 'aliveAt1199' => $alive1199, 'aliveAt1249' => $alive1249, 'keysPerScope' => $perScope]);
if ($cand) {
    OqcAsserts::check('O04', 'B2 fifty scopes with one login each write exactly 2 marker keys per scope (+1 tally on arm OQCW) and 0 per request; the 100 markers are alive at t=1199 and all expired at t=1249 (1200 s after the last write)', $n50 === 50 * $perScope && $alive599 === 50 * $perScope && $alive1199 === 100 && $alive1249 === 0, ['written' => $n50, 'alive599' => $alive599, 'alive1199' => $alive1199, 'alive1249' => $alive1249]);
} else {
    OqcAsserts::check('O04', 'B2 S0 writes no WATCH flag for fifty single successful logins', $n50 === 0, ['written' => $n50]);
}

// B3 — extra keys of the W / E arms on the attacker streams are TTL-bounded (no key survives the longest TTL, 1800 s).
foreach (['SP1n' => $streams['SP1n']['events'], 'ABA_A' => $streams['ABA_A']['events']] as $id => $ev) {
    $lab = oqcLab();
    k1Replay($lab, $ev, 'pre+out', 'login');
    $end = max(array_column($ev, 't'));
    $atEnd = wsbStoreStats($lab);
    $lab->at($end + 1801);
    $later = wsbStoreStats($lab);
    emit('O04.B3.arm-keys', ['arm' => $arm, 'stream' => $id, 'flagsAtEnd' => $atEnd['flags'], 'setsAtEnd' => $atEnd['sets'], 'flagsAfter1801s' => $later['flags'], 'setsAfter1801s' => $later['sets']]);
    OqcAsserts::check('O04', "B3 $id every correlation key written on this arm (markers, WATCH, tally, re-issue counters, bounded sets) has expired 1801 s after the stream ends", $later['flags'] === 0 && $later['sets'] === 0, ['flagsAfter' => $later['flags'], 'setsAfter' => $later['sets']]);
}

// R1 — rollback: the candidate runs the first 600 s of ABA_A, then the OLD code (arm S0 on the same state) runs the second 600 s.
$lab = oqcLab();
$ev = $streams['ABA_A']['events'];
$first = array_values(array_filter($ev, static fn(array $e): bool => $e['t'] < 600));
$second = array_values(array_filter($ev, static fn(array $e): bool => $e['t'] >= 600));
$r1 = k1Replay($lab, $first, 'pre+out', 'login');
putenv('OQC_ARM=');
try {
    $r2 = k1Replay($lab, $second, 'pre+out', 'login');
    $error = null;
} catch (\Throwable $e) {
    $r2 = [];
    $error = get_class($e) . ': ' . $e->getMessage();
}
putenv("OQC_ARM=$arm");
$reached1 = count(array_filter($r1, static fn(array $r): bool => $r['reached']));
$reached2 = count(array_filter($r2, static fn(array $r): bool => $r['reached']));
emit('O04.R1.rollback', ['arm' => $arm, 'reachedFirst600sOnThisArm' => $reached1, 'reachedSecond600sOnOldCode' => $reached2, 'error' => $error, 'secondSteps' => oqcSteps(array_slice($r2, 0, 8))]);
if ($cand) {
    OqcAsserts::check('O04', 'R1 rollback: the unpatched rule (arm S0) continues on state written by the candidate without error and the attacker is again denied at checkOnly (at most 3 of 60 reach verification in the second 600 s)', $error === null && $reached2 <= 3, ['reached2' => $reached2, 'error' => $error]);
} else {
    OqcAsserts::check('O04', 'R1 S0 reference: both halves of the attacker stream run on the unpatched rule (3/120 overall)', $error === null && $reached1 + $reached2 === 3, ['reached1' => $reached1, 'reached2' => $reached2]);
}

// R2 — mixed version: even requests on the candidate arm, odd requests on the old code, same state (SP1n and ABA_A).
foreach (['SP1n' => $streams['SP1n']['events'], 'ABA_A' => $streams['ABA_A']['events']] as $id => $ev) {
    $lab = oqcLab();
    $rows = [];
    $error = null;
    try {
        foreach ($ev as $i => $e) {
            putenv($i % 2 === 0 ? "OQC_ARM=$arm" : 'OQC_ARM=');
            $rows = array_merge($rows, k1Replay($lab, [$e], 'pre+out', 'login'));
        }
    } catch (\Throwable $x) {
        $error = get_class($x) . ': ' . $x->getMessage();
    }
    putenv("OQC_ARM=$arm");
    $reached = count(array_filter($rows, static fn(array $r): bool => $r['reached']));
    $mono = oqcMeasure($ev, 'login', 'pre+out')['attackReachedVerification'];
    emit('O04.R2.mixed-version', ['arm' => $arm, 'stream' => $id, 'reachedMixed' => $reached, 'reachedThisArmOnly' => $mono, 'error' => $error]);
    OqcAsserts::check('O04', "R2 $id mixed old/new code on one state never errors and the attacker reach stays within [S0-like, this arm] (<= this arm's own reach + 1)", $error === null && $reached <= $mono + 1, ['mixed' => $reached, 'mono' => $mono, 'error' => $error]);
}
OqcAsserts::finish();
