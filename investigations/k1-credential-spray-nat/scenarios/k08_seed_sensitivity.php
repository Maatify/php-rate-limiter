<?php

declare(strict_types=1);

// K08 — robustness of the benign-population numbers against the only random-looking input of the streams: WHICH of the logins fail (8 % typos,
// a deterministic LCG). 20 seeds per population, four arms, Login pre+out. The attack streams contain no randomness (every attempt fails by
// construction), so they need no sweep. This gives the observed range of the legitimate-denial counts, not a statistical confidence interval:
// the simulation is deterministic and the population structure is fixed.
require dirname(__DIR__) . '/lib/k1.php';

k1Emit('K08.provenance', k1Provenance());

/** @return list<array<string, mixed>> */
function k1SeededPopulation(string $kind, int $seed): array
{
    $r = new Lcg($seed);
    $ev = [];
    if ($kind === 'D2') {
        for ($t = 0, $n = 1; $t <= 2400; $t += 20, $n++) {
            $ev[] = k1Ev($t, NAT, "office$n", 'off' . ($n % 12), $r->pct() <= 8 ? 'fail' : 'ok', 'legit');
        }

        return $ev;
    }
    $cols = (int) substr($kind, 2);
    for ($t = 0, $i = 0; $t <= 2400; $t += 40, $i++) {
        $u = $i % $cols;
        $ev[] = k1Ev($t, NAT, "stable$u", "col$u", $r->pct() <= 8 ? 'fail' : 'ok', 'legit', ['uaRaw' => k1Ua($u)]);
    }

    return $ev;
}

$ranges = [];
foreach (['D2', 'Nu3', 'Nu4', 'Nu5', 'Nu6', 'Nu12'] as $kind) {
    $perArm = array_fill_keys(K1_ARMS, []);
    for ($seed = 1; $seed <= 20; $seed++) {
        $ev = k1SeededPopulation($kind, 1000 + $seed);
        foreach (K1_ARMS as $arm) {
            $rows = k1Replay(k1Lab($arm), $ev, 'pre+out', 'login');
            $perArm[$arm][] = count(array_filter($rows, static fn(array $r): bool => $r['blocked']));
        }
    }
    $sum = ['population' => $kind, 'seeds' => 20, 'attempts' => count($ev)];
    foreach (K1_ARMS as $arm) {
        $v = $perArm[$arm];
        sort($v);
        $sum["arm$arm"] = ['min' => $v[0], 'median' => $v[9], 'max' => $v[19]];
    }
    $ranges[$kind] = $sum;
    k1Emit('K08.seed-range', $sum);
}
K1Asserts::check('K08', 'D2: over 20 typo seeds arm A denies at least 100 of 121 and arm B at least 100 of 121 (the published 117 / 108 are not a seed artefact), while arm D denies at most 40', $ranges['D2']['armA']['min'] >= 100 && $ranges['D2']['armB']['min'] >= 100 && $ranges['D2']['armD']['max'] <= 40, $ranges['D2']);
K1Asserts::check('K08', 'Nu12 (K1-isolation control): arms A and B deny exactly the same number for EVERY seed (min = max) and arm C (K1 spray off) denies at most 6 of 61 for every seed', $ranges['Nu12']['armA']['min'] === $ranges['Nu12']['armA']['max'] && $ranges['Nu12']['armA'] === $ranges['Nu12']['armB'] && $ranges['Nu12']['armC']['max'] <= 6 && $ranges['Nu12']['armA']['min'] >= 40, $ranges['Nu12']);
K1Asserts::finish();
