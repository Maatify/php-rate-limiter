<?php

declare(strict_types=1);

// A04 — memory exhaustion / storage caps on the loaded runtime (in-memory reference store inspected by reflection).
// NEGATIVE CONTROLS: every probe must REACH verification and the store must visibly grow; a run where requests were denied before the
// storage write would otherwise "pass" for the wrong reason.
require dirname(__DIR__) . '/lib/wsb.php';

emit('A04.provenance', wsbProvenance());

/** @param array<string, array{calls: int, maxCount: int}> $classes */
function capsOk(array $classes): bool
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
$flood = static function (string $name, int $n, callable $ctx, bool $correlationDisabled, int $stepSeconds = 1) use ($ua): array {
    // S0: disable ONLY the churn/dilution sets with the store-level control. Candidates: the patch's test-only WSB_MUTATE=no_enforce
    // switch suppresses enforcement instead (their witness sets share the (600 s, cap 3) class with the churn set, so a store-level
    // control would also swallow the witness write under test).
    $base = array_filter(explode(',', (string) getenv('WSB_MUTATE')), static fn(string $m): bool => $m !== 'no_enforce');
    putenv('WSB_MUTATE=' . implode(',', array_merge($base, $correlationDisabled && wsbVariant() !== 'S0' ? ['no_enforce'] : [])));
    $lab = wsbRecordingLab('login', $correlationDisabled && wsbVariant() === 'S0');
    $reached = 0;
    $denied = 0;
    $before = wsbStoreStats($lab, $lab->t0);
    for ($i = 1; $i <= $n; $i++) {
        $lab->at($i * $stepSeconds);
        $c = $ctx($i);
        $r = $lab->attempt($c, 'out', 'fail');
        $r['blocked'] ? $denied++ : $reached++;
    }
    $peak = wsbStoreStats($lab);
    $idleAt = $lab->t0 + $n * $stepSeconds + 2000; // every TTL used by S0 (<= 1800) and by the candidates (<= 700) has elapsed
    $idle = wsbStoreStats($lab, $idleAt);

    return ['variant' => wsbVariant(), 'scenario' => $name, 'requests' => $n, 'reachedStorage' => $reached, 'denied' => $denied, 'peak' => $peak, 'classes' => $lab->corr->classes, 'afterIdle2000s' => $idle, 'entriesPerRequest' => round(($peak['sets'] + $peak['flags']) / $n, 3), 'membersAtPeak' => $peak['members']];
};

// S1 — ONE K2, many distinct failing fingerprints (correlation enforcement disabled so every failure reaches the witness write).
$s1 = $flood('S1.one-K2-300-fingerprints', 300, static fn(int $i) => (new Lab('login'))->ctx('198.51.100.5', CHROME, null, fp("fp$i")), true);
emit('A04.S1', $s1);
WsbAsserts::check('A04', 'S1 negative control: all 300 failing requests reached the storage path', $s1['reachedStorage'] === 300, ['reached' => $s1['reachedStorage']]);
WsbAsserts::check('A04', 'S1 declared caps: every bounded-set class declares cap <= 50 (the largest cap of the baseline) and never exceeds its own cap', capsOk($s1['classes']), ['classes' => $s1['classes']]);
WsbAsserts::check('A04', 'S1 TTL hygiene: nothing survives 2000 s of idleness', $s1['afterIdle2000s']['sets'] === 0 && $s1['afterIdle2000s']['flags'] === 0, $s1['afterIdle2000s']);

// S2 — ONE fingerprint across 300 IPs (dilution scope, fingerprint-anchored witness scope).
$s2 = $flood('S2.one-FP-300-IPs', 300, static fn(int $i) => (new Lab('login'))->ctx('10.' . intdiv($i, 250) . '.' . ($i % 250) . '.1', CHROME, null, fp('shared')), true);
emit('A04.S2', $s2);
WsbAsserts::check('A04', 'S2 negative control: all 300 failing requests reached the storage path', $s2['reachedStorage'] === 300, ['reached' => $s2['reachedStorage']]);
WsbAsserts::check('A04', 'S2 declared caps hold', capsOk($s2['classes']), ['classes' => $s2['classes']]);

// S3 — ANCHOR ROTATION: 3000 requests, each a NEW (IP, Chrome major, fingerprint): every anchor is unique, nothing may trigger.
// Correlation stays ENABLED: a block here would mean the probe never reached the storage write (negative control).
$s3 = $flood('S3.rotate-3000-anchors(ip+ua+fp)', 3000, static fn(int $i) => (new Lab('login'))->ctx('172.' . (16 + intdiv($i, 62500)) . '.' . (intdiv($i, 250) % 250) . '.' . ($i % 250 + 1), $ua(100 + ($i % 900)), null, fp("fp$i")), false);
emit('A04.S3', $s3);
WsbAsserts::check('A04', 'S3 negative control: no request denied (unique anchors never correlate) so every write reached the store', $s3['denied'] === 0 && $s3['reachedStorage'] === 3000, ['denied' => $s3['denied']]);
WsbAsserts::check('A04', 'S3 declared caps hold', capsOk($s3['classes']), ['classes' => $s3['classes']]);
WsbAsserts::check('A04', 'S3 total growth is linear in the rotation rate (<= 12 entries per request incl. all candidate state)', $s3['entriesPerRequest'] <= 12.0, ['entriesPerRequest' => $s3['entriesPerRequest']]);
WsbAsserts::check('A04', 'S3 TTL hygiene: nothing survives 2000 s of idleness', $s3['afterIdle2000s']['sets'] === 0 && $s3['afterIdle2000s']['flags'] === 0, $s3['afterIdle2000s']);

WsbAsserts::finish();
