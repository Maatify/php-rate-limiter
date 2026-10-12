<?php

declare(strict_types=1);

// D04 — bounded state, TTL hygiene and per-command correlation-store operation counts on the loaded runtime (in-memory reference store
// inspected by reflection; the Redis stage D05 repeats the structural checks on the real adapter).
// NEGATIVE CONTROLS: every flood probe must REACH verification (a denial before the storage write would let the probe pass for the wrong
// reason) and the store must visibly grow.
require dirname(__DIR__) . '/lib/dsg.php';

emit('D04.provenance', dsgProvenance());
$isS0 = wsbVariant() === 'S0';

/** @param array<string, array{calls: int, maxCount: int}> $classes */
function d04CapsOk(array $classes): bool
{
    foreach ($classes as $cls => $v) {
        $cap = (int) explode('/', $cls)[1];
        if ($cap > 50 || $v['maxCount'] > $cap) {
            return false;
        }
    }

    return true;
}

$ua = static fn(int $major): string => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/$major.0.0.0 Safari/537.36";

/**
 * @param callable(int):\Maatify\RateLimiter\DTO\RateLimitContextDTO $ctx
 * @return array<string, mixed>
 */
$flood = static function (string $name, int $n, callable $ctx, string $outcome) use ($ua): array {
    $lab = wsbRecordingLab('login', false);
    $reached = 0;
    $denied = 0;
    for ($i = 1; $i <= $n; $i++) {
        $lab->at($i);
        $r = $lab->attempt($ctx($i), 'out', $outcome);
        $r['blocked'] ? $denied++ : $reached++;
    }
    $peak = wsbStoreStats($lab);
    $idle = wsbStoreStats($lab, $lab->t0 + $n + 2000); // every TTL of S0 (<= 1800) and of the candidates (<= 1800) has elapsed

    return ['variant' => wsbVariant(), 'scenario' => $name, 'requests' => $n, 'reachedStorage' => $reached, 'denied' => $denied, 'peak' => $peak, 'classes' => $lab->corr->classes, 'afterIdle2000s' => $idle, 'entriesPerRequest' => round(($peak['sets'] + $peak['flags']) / $n, 3), 'membersAtPeak' => $peak['members']];
};

// S1 — anchor rotation of FAILING requests: 3000 requests, each a new (IP, Chrome major, fingerprint). Nothing may trigger (unique anchors),
// every request writes the full state of its path: this is the worst per-request growth of the variant (failures write the evidence markers).
$s1 = $flood('S1.rotate-3000-failing-anchors(ip+ua+fp)', 3000, static fn(int $i) => (new Lab('login'))->ctx('172.' . (16 + intdiv($i, 62500)) . '.' . (intdiv($i, 250) % 250) . '.' . ($i % 250 + 1), $ua(100 + ($i % 900)), null, fp("fp$i")), 'fail');
emit('D04.S1', $s1);
WsbAsserts::check('D04', 'S1 negative control: no request denied (unique anchors never correlate) so every write reached the store', $s1['denied'] === 0 && $s1['reachedStorage'] === 3000, ['denied' => $s1['denied']]);
WsbAsserts::check('D04', 'S1 declared caps: every bounded-set class declares cap <= 50 (the largest cap of the baseline) and never exceeds its own cap', d04CapsOk($s1['classes']), ['classes' => $s1['classes']]);
WsbAsserts::check('D04', 'S1 growth is linear in the failure rate (<= 12 entries per failing request, markers included)', $s1['entriesPerRequest'] <= 12.0, ['entriesPerRequest' => $s1['entriesPerRequest']]);
WsbAsserts::check('D04', 'S1 TTL hygiene: nothing survives 2000 s of idleness', $s1['afterIdle2000s']['sets'] === 0 && $s1['afterIdle2000s']['flags'] === 0, $s1['afterIdle2000s']);

// S2 — the same rotation with SUCCESSFUL requests: the success path must not create evidence state (the markers are failure-only).
$s2 = $flood('S2.rotate-3000-successful-anchors(ip+ua+fp)', 3000, static fn(int $i) => (new Lab('login'))->ctx('172.' . (16 + intdiv($i, 62500)) . '.' . (intdiv($i, 250) % 250) . '.' . ($i % 250 + 1), $ua(100 + ($i % 900)), null, fp("fp$i")), 'ok');
emit('D04.S2', $s2);
WsbAsserts::check('D04', 'S2 negative control: no request denied and every success reached the store', $s2['denied'] === 0 && $s2['reachedStorage'] === 3000, ['denied' => $s2['denied']]);
WsbAsserts::check('D04', 'S2 declared caps hold', d04CapsOk($s2['classes']), ['classes' => $s2['classes']]);
WsbAsserts::check('D04', 'S2 TTL hygiene: nothing survives 2000 s of idleness', $s2['afterIdle2000s']['sets'] === 0 && $s2['afterIdle2000s']['flags'] === 0, $s2['afterIdle2000s']);

// S3 — ONE K2, 300 distinct failing fingerprints: the set stays at its cap and the marker is one key per scope.
$s3 = $flood('S3.one-K2-300-fingerprints', 300, static fn(int $i) => (new Lab('login'))->ctx('198.51.100.5', CHROME, null, fp("fp$i")), 'fail');
emit('D04.S3', $s3);
WsbAsserts::check('D04', 'S3 declared caps hold under 300 distinct fingerprints on one K2', d04CapsOk($s3['classes']), ['classes' => $s3['classes']]);
WsbAsserts::check('D04', 'S3 TTL hygiene: nothing survives 2000 s of idleness', $s3['afterIdle2000s']['sets'] === 0 && $s3['afterIdle2000s']['flags'] === 0, $s3['afterIdle2000s']);

// O1 — exact per-command operation counts on canonical sequences (reference store API calls; the Redis adapter issues one atomic script per call).
$count = static function (string $name, callable $seq): array {
    $lab = dsgCountingLab('login');
    $per = [];
    $seq($lab, $per);

    return ['variant' => wsbVariant(), 'case' => $name, 'perCommand' => $per, 'total' => $lab->corr->total(), 'ops' => $lab->corr->ops];
};
$runCmd = static function (Lab $lab, array &$per, int $t, string $label, string $ip, ?string $fpId, ?string $acct, string $cmd): void {
    $lab->at($t);
    $before = $lab->corr->ops;
    $c = $lab->ctx($ip, CHROME, $acct, $fpId === null ? null : fp($fpId));
    $r = $lab->run($c, $cmd);
    $after = $lab->corr->ops;
    $delta = [];
    foreach ($after as $k => $v) {
        $delta[$k] = $v - $before[$k];
    }
    $per[] = ['label' => $label, 'decision' => $r['d'], 'ops' => array_sum($delta), 'by' => $delta];
};
$cases = [
    // legit success path, pre+out (the dominant production path)
    $count('legit-success-pre+out', static function (Lab $lab, array &$per) use ($runCmd): void {
        $runCmd($lab, $per, 1, 'check', '198.51.100.7', 'fpL', 'acctL', 'check');
        $runCmd($lab, $per, 1, 'ok', '198.51.100.7', 'fpL', 'acctL', 'ok');
    }),
    // legit typo, pre+out
    $count('legit-typo-pre+out', static function (Lab $lab, array &$per) use ($runCmd): void {
        $runCmd($lab, $per, 1, 'check', '198.51.100.7', 'fpL', 'acctL', 'check');
        $runCmd($lab, $per, 1, 'fail', '198.51.100.7', 'fpL', 'acctL', 'fail');
    }),
    // LOW-confidence legitimate success (no client fingerprint), pre+out
    $count('low-success-pre+out', static function (Lab $lab, array &$per) use ($runCmd): void {
        $runCmd($lab, $per, 1, 'check', '198.51.100.8', null, 'acctM', 'check');
        $runCmd($lab, $per, 1, 'ok', '198.51.100.8', null, 'acctM', 'ok');
    }),
    // attack: three failing fingerprints, pre+out, ending in the trigger and the following denied probe
    $count('three-failing-fingerprints-pre+out', static function (Lab $lab, array &$per) use ($runCmd): void {
        foreach (['f1', 'f2', 'f3'] as $i => $f) {
            $runCmd($lab, $per, $i, "check-$f", NAT, $f, "ac$i", 'check');
            $runCmd($lab, $per, $i, "fail-$f", NAT, $f, "ac$i", 'fail');
        }
        $runCmd($lab, $per, 5, 'check-denied', NAT, 'f4', 'ac9', 'check');
    }),
];
foreach ($cases as $c) {
    emit('D04.O1.ops', $c);
}
WsbAsserts::finish();
