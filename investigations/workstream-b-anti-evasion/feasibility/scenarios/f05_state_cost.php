<?php

declare(strict_types=1);

// F05 — bounded state, TTL hygiene and per-command correlation-store operation counts on the loaded runtime (in-memory reference store inspected
// by reflection; F06 repeats the structural checks on the real Redis adapter).
// NEGATIVE CONTROLS: every flood probe must REACH storage (a denial before the write would let a probe pass for the wrong reason) and the store
// must visibly grow.
require dirname(__DIR__) . '/lib/fsb.php';

emit('F05.provenance', fsbProvenance());

/** @param array<string, array{calls: int, maxCount: int}> $classes */
function f05CapsOk(array $classes): bool
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
$ipOf = static fn(int $i): string => '172.' . (16 + intdiv($i, 62500)) . '.' . (intdiv($i, 250) % 250) . '.' . ($i % 250 + 1);

/**
 * @param callable(int):\Maatify\RateLimiter\DTO\RateLimitContextDTO $ctx
 * @return array<string, mixed>
 */
$flood = static function (string $name, string $shape, int $n, callable $ctx, string $outcome): array {
    $lab = wsbRecordingLab($shape, false);
    $reached = 0;
    $denied = 0;
    for ($i = 1; $i <= $n; $i++) {
        $lab->at($i);
        $r = $lab->attempt($ctx($i), 'out', $outcome);
        $r['blocked'] ? $denied++ : $reached++;
    }
    $peak = wsbStoreStats($lab);
    $idle = wsbStoreStats($lab, $lab->t0 + $n + 2000); // every TTL of S0 and of the candidates (<= 1800) has elapsed

    return ['variant' => wsbVariant(), 'scenario' => $name, 'shape' => $shape, 'requests' => $n, 'reachedStorage' => $reached, 'denied' => $denied, 'peak' => $peak, 'classes' => $lab->corr->classes, 'afterIdle2000s' => $idle, 'entriesPerRequest' => round(($peak['sets'] + $peak['flags']) / $n, 3), 'membersAtPeak' => $peak['members']];
};
$helper = new Lab('login');
$rot = static fn(int $i) => $helper->ctx($ipOf($i), $ua(100 + ($i % 900)), null, fp("fp$i"));

// S1 — anchor rotation of FAILING requests (Login): 3000 requests, each a new (IP, Chrome major, fingerprint). Nothing may trigger.
$s1 = $flood('S1.rotate-3000-failing-anchors(ip+ua+fp)', 'login', 3000, $rot, 'fail');
emit('F05.S1', $s1);
WsbAsserts::check('F05', 'S1 negative control: no request denied (unique anchors never correlate) so every write reached the store', $s1['denied'] === 0 && $s1['reachedStorage'] === 3000, ['denied' => $s1['denied']]);
WsbAsserts::check('F05', 'S1 declared caps: every bounded-set class declares cap <= 50 and never exceeds its own cap', f05CapsOk($s1['classes']), ['classes' => $s1['classes']]);
WsbAsserts::check('F05', 'S1 growth is linear in the failure rate (<= 12 entries per failing request)', $s1['entriesPerRequest'] <= 12.0, ['entriesPerRequest' => $s1['entriesPerRequest']]);
WsbAsserts::check('F05', 'S1 TTL hygiene: nothing survives 2000 s of idleness', $s1['afterIdle2000s']['sets'] === 0 && $s1['afterIdle2000s']['flags'] === 0, $s1['afterIdle2000s']);

// S2 — the same rotation with SUCCESSFUL requests (Login): the success path writes no corroboration state.
$s2 = $flood('S2.rotate-3000-successful-anchors(ip+ua+fp)', 'login', 3000, $rot, 'ok');
emit('F05.S2', $s2);
WsbAsserts::check('F05', 'S2 negative control: no request denied and every success reached the store', $s2['denied'] === 0 && $s2['reachedStorage'] === 3000, ['denied' => $s2['denied']]);
WsbAsserts::check('F05', 'S2 declared caps hold', f05CapsOk($s2['classes']), ['classes' => $s2['classes']]);
WsbAsserts::check('F05', 'S2 TTL hygiene: nothing survives 2000 s of idleness', $s2['afterIdle2000s']['sets'] === 0 && $s2['afterIdle2000s']['flags'] === 0, $s2['afterIdle2000s']);

// S3 — ONE K2, 300 distinct failing fingerprints: sets stay at their cap, one marker per scope.
$s3 = $flood('S3.one-K2-300-fingerprints', 'login', 300, static fn(int $i) => $helper->ctx('198.51.100.5', CHROME, null, fp("fp$i")), 'fail');
emit('F05.S3', $s3);
WsbAsserts::check('F05', 'S3 declared caps hold under 300 distinct fingerprints on one K2', f05CapsOk($s3['classes']), ['classes' => $s3['classes']]);
WsbAsserts::check('F05', 'S3 TTL hygiene: nothing survives 2000 s of idleness', $s3['afterIdle2000s']['sets'] === 0 && $s3['afterIdle2000s']['flags'] === 0, $s3['afterIdle2000s']);

// S4 — API_OVERUSE (access-scored: EVERY outcome is a scored command): 3000 successful requests, each a new (IP, UA, fingerprint). This is the
// worst-case write amplification of the candidate (a window set per request on a policy where every request is evidence).
$s4 = $flood('S4.api-rotate-3000-successful-anchors(ip+ua+fp)', 'api', 3000, $rot, 'ok');
emit('F05.S4', $s4);
WsbAsserts::check('F05', 'S4 negative control: no API request denied (unique anchors) and every request reached the store', $s4['denied'] === 0 && $s4['reachedStorage'] === 3000, ['denied' => $s4['denied']]);
WsbAsserts::check('F05', 'S4 declared caps hold on the access-scored policy', f05CapsOk($s4['classes']), ['classes' => $s4['classes']]);
WsbAsserts::check('F05', 'S4 growth is linear in the request rate (<= 12 entries per request)', $s4['entriesPerRequest'] <= 12.0, ['entriesPerRequest' => $s4['entriesPerRequest']]);
WsbAsserts::check('F05', 'S4 TTL hygiene: nothing survives 2000 s of idleness', $s4['afterIdle2000s']['sets'] === 0 && $s4['afterIdle2000s']['flags'] === 0, $s4['afterIdle2000s']);

// O1 — exact per-command operation counts on canonical sequences (reference store API calls; the Redis adapter issues one atomic script per call).
$count = static function (string $name, string $shape, callable $seq): array {
    $lab = dsgCountingLab($shape);
    $per = [];
    $seq($lab, $per);

    return ['variant' => wsbVariant(), 'case' => $name, 'perCommand' => $per, 'total' => $lab->corr->total(), 'ops' => $lab->corr->ops];
};
$runCmd = static function (Lab $lab, array &$per, int $t, string $label, string $ip, ?string $fpId, ?string $acct, string $cmd): void {
    $lab->at($t);
    $before = $lab->corr->ops;
    $c = $lab->ctx($ip, CHROME, $acct, $fpId === null ? null : fp($fpId));
    $r = $lab->run($c, $cmd);
    $delta = [];
    foreach ($lab->corr->ops as $k => $v) {
        $delta[$k] = $v - $before[$k];
    }
    $per[] = ['label' => $label, 'decision' => $r['d'], 'ops' => array_sum($delta), 'by' => $delta];
};
$cases = [
    $count('legit-success-pre+out', 'login', static function (Lab $lab, array &$per) use ($runCmd): void {
        $runCmd($lab, $per, 1, 'check', '198.51.100.7', 'fpL', 'acctL', 'check');
        $runCmd($lab, $per, 1, 'ok', '198.51.100.7', 'fpL', 'acctL', 'ok');
    }),
    $count('legit-typo-pre+out', 'login', static function (Lab $lab, array &$per) use ($runCmd): void {
        $runCmd($lab, $per, 1, 'check', '198.51.100.7', 'fpL', 'acctL', 'check');
        $runCmd($lab, $per, 1, 'fail', '198.51.100.7', 'fpL', 'acctL', 'fail');
    }),
    $count('low-success-pre+out', 'login', static function (Lab $lab, array &$per) use ($runCmd): void {
        $runCmd($lab, $per, 1, 'check', '198.51.100.8', null, 'acctM', 'check');
        $runCmd($lab, $per, 1, 'ok', '198.51.100.8', null, 'acctM', 'ok');
    }),
    $count('three-failing-fingerprints-pre+out', 'login', static function (Lab $lab, array &$per) use ($runCmd): void {
        foreach (['f1', 'f2', 'f3'] as $i => $f) {
            $runCmd($lab, $per, $i, "check-$f", NAT, $f, "ac$i", 'check');
            $runCmd($lab, $per, $i, "fail-$f", NAT, $f, "ac$i", 'fail');
        }
        $runCmd($lab, $per, 5, 'check-denied', NAT, 'f4', 'ac9', 'check');
    }),
    $count('api-success-pre+out', 'api', static function (Lab $lab, array &$per) use ($runCmd): void {
        $runCmd($lab, $per, 1, 'check', '198.51.100.7', 'fpL', null, 'check');
        $runCmd($lab, $per, 1, 'ok', '198.51.100.7', 'fpL', null, 'ok');
    }),
];
foreach ($cases as $c) {
    emit('F05.O1.ops', $c);
}
WsbAsserts::finish();
