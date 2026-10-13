<?php

declare(strict_types=1);

// K06 — storage bounds and TTL hygiene of the K1 spray state on the reference store (the real-Redis counterpart is k07_redis.php).
//   M1 every spray set is capped at 5 members and every K1 owns at most one spray set (+ one WATCH flag), whatever the attacker does
//   M2 the window is FIXED: later members never extend the TTL (600 s); the WATCH flag is fixed (1800 s)
//   M3 everything expires: no spray state remains active after 1800 s of silence
//   M4 the bound is PER K1 (per IP /64): an attacker with many IPs creates state linear in its IP count — recorded as a boundary, not a defect claim
require dirname(__DIR__) . '/lib/k1.php';

k1Emit('K06.provenance', k1Provenance());

/** @return array{sets: int, flags: int, maxSet: int, members: int, maxSetTtl: int, maxFlagTtl: int} spray state only ("credential_spray:" keys), active at $now */
function k1SprayState(Lab $lab, int $now): array
{
    $ref = new \ReflectionClass(\Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore::class);
    $sets = $ref->getProperty('sets')->getValue($lab->corr);
    $flags = $ref->getProperty('flags')->getValue($lab->corr);
    $o = ['sets' => 0, 'flags' => 0, 'maxSet' => 0, 'members' => 0, 'maxSetTtl' => 0, 'maxFlagTtl' => 0];
    foreach ($sets as $k => $s) {
        if (str_starts_with((string) $k, 'credential_spray:') && $s['expiresAt'] !== null && $s['expiresAt'] > $now) {
            $o['sets']++;
            $o['members'] += count($s['items']);
            $o['maxSet'] = max($o['maxSet'], count($s['items']));
            $o['maxSetTtl'] = max($o['maxSetTtl'], $s['expiresAt'] - $now);
        }
    }
    foreach ($flags as $k => $f) {
        if (str_starts_with((string) $k, 'credential_spray:') && $f['expiresAt'] !== null && $f['expiresAt'] > $now) {
            $o['flags']++;
            $o['maxFlagTtl'] = max($o['maxFlagTtl'], $f['expiresAt'] - $now);
        }
    }

    return $o;
}

// M1 — one IP, 5000 distinct subjects in 500 s (1 / 0.1 s): one set of 5 members, at most one WATCH flag.
$lab = k1Lab('A');
$ev = [];
for ($i = 0; $i < 5000; $i++) {
    $ev[] = k1Ev(intdiv($i, 10), '198.51.100.200', null, "bulk$i", 'ok', 'attack', ['uaRaw' => k1Ua(80)]);
}
k1Replay($lab, $ev, 'pre', 'login');
$st = k1SprayState($lab, $lab->t0 + 500);
k1Emit('K06.M1.one-ip-bulk', ['subjects' => 5000] + $st);
K1Asserts::check('K06', 'M1 5000 distinct subjects from ONE IP leave exactly one spray set of 5 members and one WATCH flag (rejected members never grow the set)', $st['sets'] === 1 && $st['maxSet'] === 5 && $st['members'] === 5 && $st['flags'] === 1, $st);

// M2 — fixed windows: first member at t=0, more members at t=100/300/500. TTL remaining must shrink with the clock (never be refreshed).
$lab = k1Lab('A');
$rows = k1Replay($lab, [k1Ev(0, '198.51.100.201', null, 'f0', 'ok', 'attack', ['uaRaw' => k1Ua(81)]), k1Ev(100, '198.51.100.201', null, 'f1', 'ok', 'attack', ['uaRaw' => k1Ua(81)]), k1Ev(300, '198.51.100.201', null, 'f2', 'ok', 'attack', ['uaRaw' => k1Ua(81)]), k1Ev(500, '198.51.100.201', null, 'f3', 'ok', 'attack', ['uaRaw' => k1Ua(81)])], 'pre', 'login');
$ttls = [];
foreach ([0, 100, 300, 500] as $t) {
    $ttls[$t] = null;
}
$at500 = k1SprayState($lab, $lab->t0 + 500);
$at599 = k1SprayState($lab, $lab->t0 + 599);
k1Emit('K06.M2.fixed-window', ['ttlRemainingAt500' => $at500['maxSetTtl'], 'ttlRemainingAt599' => $at599['maxSetTtl'], 'watchTtlRemainingAt500' => $at500['maxFlagTtl']]);
K1Asserts::check('K06', 'M2 later members do not extend the 600 s set TTL (100 s left at t=500, 1 s at t=599) and the WATCH flag keeps its fixed 1800 s TTL (1303 s left at t=500: created by the 4th request at t=500)', $at500['maxSetTtl'] === 100 && $at599['maxSetTtl'] === 1 && $at500['maxFlagTtl'] === 1800, ['at500' => $at500, 'at599' => $at599]);

// M3 — expiry: after the fixed windows nothing is active.
$later = k1SprayState($lab, $lab->t0 + 500 + 1800);
k1Emit('K06.M3.expiry', $later);
K1Asserts::check('K06', 'M3 no spray set and no WATCH flag is active 1800 s after the last request', $later['sets'] === 0 && $later['flags'] === 0, $later);

// M4 — per-IP bound: 2000 IPs x 6 distinct subjects each = 12000 requests, every IP with its own browser major (device dilution inert, so only
// K1 and the independent protections act).
$lab = k1Lab('A');
$ev = [];
$t = 0;
for ($ip = 0; $ip < 2000; $ip++) {
    for ($j = 0; $j < 6; $j++) {
        $ev[] = k1Ev(intdiv($t++, 20), '10.' . intdiv($ip, 250) . '.' . ($ip % 250) . '.1', null, "m4s{$ip}x$j", 'ok', 'attack', ['uaRaw' => k1Ua(1000 + $ip)]);
    }
}
$rows = k1Replay($lab, $ev, 'pre', 'login');
$st = k1SprayState($lab, $lab->t0 + 600);
$reached = count(array_filter($rows, static fn(array $r): bool => $r['reached']));
k1Emit('K06.M4.many-ips', ['ips' => 2000, 'requests' => count($rows), 'reachedVerification' => $reached] + $st);
K1Asserts::check('K06', 'M4 every spray set stays <=5 members and each K1 owns at most one set and one flag: state is linear in the number of IPs, bounded per IP', $st['maxSet'] <= 5 && $st['sets'] <= 2000 && $st['flags'] <= 2000, $st);
K1Asserts::check('K06', 'M4 a 6-subject-per-IP attacker spread over 2000 IPs reaches verification with 4 of every 6 subjects (K1 is per K1/IP and cannot see the aggregate)', $reached === 8000, ['reached' => $reached]);
K1Asserts::finish();
