<?php

declare(strict_types=1);

// O06 — CONCURRENCY on real Redis (instance owned and proven by run.sh; PR #103 F103-01 safety model): the F111-02 question.
// On an outcome-live scope the candidate observes K1 / K2 only at recordFailure, so N simultaneous checkOnly requests that all precede the
// first recordFailure are all allowed: the per-cycle leak is N, not 1. Two-phase barrier: the workers start checkOnly at one Redis instant
// and start recordFailure only after a Redis completion counter proves that EVERY worker's checkOnly has finished (F111-06); the parent
// re-reads that counter after the round and records it.
//   C1  K1: 16 simultaneous distinct failing subjects (no fingerprint) on one IP whose scope is LIVE (one earlier successful login)
//   C2  K2: 8 simultaneous distinct failing fingerprints on one IP+UA whose scope is LIVE
//   C3  control: the same K1 burst on a SILENT scope (no earlier outcome): the pre-check rule applies and the cap holds atomically on every arm
//   C4  after each burst the next sequential checkOnly is denied (the block was issued by the failures)
require dirname(__DIR__, 2) . '/workstream-b-anti-evasion/lib/redis.php';
require dirname(__DIR__) . '/lib/oqc.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

emit('O06.provenance', oqcProvenance());
$arm = oqcArm();
$cand = $arm !== 'S0';
$ex = wsbRedisConnectVerified(true);
$worker = __DIR__ . '/o06_worker.php';

/**
 * @param list<array{0: string, 1: string, 2: string, 3: int}> $jobs [ip, fp|'-', acct, uaIdx]
 * @return array{0: list<string>, 1: int} worker outputs, completion counter read after the round (must equal count($jobs))
 */
$spawnAll = static function (string $ns, array $jobs, int $startCheck) use ($worker, $ex): array {
    $barrierKey = 'oqc:barrier:' . $ns . ':' . $startCheck . ':' . bin2hex(random_bytes(4));
    $workers = count($jobs);
    $env = ['WSB_REDIS_PORT' => getenv('WSB_REDIS_PORT'), 'WSB_REDIS_PID' => getenv('WSB_REDIS_PID'), 'WSB_REDIS_DIR' => getenv('WSB_REDIS_DIR'), 'PATH' => getenv('PATH')];
    foreach (['LAB_ROOT', 'OQC_ARM'] as $k) {
        if (getenv($k) !== false) {
            $env[$k] = getenv($k);
        }
    }
    $procs = [];
    foreach ($jobs as [$ip, $fp, $acct, $ua]) {
        $p = proc_open([PHP_BINARY, $worker, $ns, $ip, $fp, $acct, (string) $ua, (string) $startCheck, $barrierKey, (string) $workers], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $procs[] = [$p, $pipes];
    }
    $out = [];
    foreach ($procs as [$p, $pipes]) {
        $out[] = trim((string) stream_get_contents($pipes[1])) . trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
    }

    return [$out, (int) $ex->execute(['GET', $barrierKey])];
};
$completed = static fn(array $out): int => count(array_filter($out, static fn(string $o): bool => str_starts_with($o, 'check:')));
$allowed = static fn(array $out): int => count(array_filter($out, static fn(string $o): bool => str_starts_with($o, 'check:A')));
$failAllowed = static fn(array $out): int => count(array_filter($out, static fn(string $o): bool => str_ends_with($o, '>fail:A')));
/** Highest block level any worker's recordFailure returned in the burst (0 = none); the order of the concurrent failures is not deterministic, their levels' maximum is. */
$maxLevel = static function (array $out): int {
    $m = 0;
    foreach ($out as $o) {
        if (preg_match('/>fail:H(\d)/', $o, $mm) === 1) {
            $m = max($m, (int) $mm[1]);
        }
    }

    return $m;
};
$denied = static fn(string $s): string => str_contains($s, ':H') ? 'denied' : 'allowed';
$now = static fn(): int => (int) $ex->execute(['TIME'])[0];
$warm = static function (string $ns, string $ip, int $ua, ?string $fp = 'warm') use ($ex): string {
    // one sequential successful login makes the scope outcome-live (pre+out)
    $rt = wsbRedisPipeline($ex, $ns);
    $c = new RateLimitContextDTO($ip, k1Ua($ua), 'warmacct', $fp === null ? null : fp($fp));
    $a = wsbRedisRun($rt, $c, 'check');
    $b = wsbRedisRun($rt, $c, 'ok');

    return $a . '>' . $b;
};
$probe = static function (string $ns, string $ip, int $ua, string $acct, ?string $fp) use ($ex): string {
    $rt = wsbRedisPipeline($ex, $ns);

    return wsbRedisRun($rt, $c = new RateLimitContextDTO($ip, k1Ua($ua), $acct, $fp === null ? null : fp($fp)), 'check');
};

// C1 — K1 burst on a LIVE scope, 3 rounds (one IP per round).
$c1 = [];
$c1Probe = [];
for ($round = 1; $round <= 3; $round++) {
    $ip = "10.90.$round.1";
    $warmSteps = $warm('oqc:c1', $ip, 100 + $round, null); // LOW warm-up (same passive fingerprint as the LOW workers): K1 only
    $t = $now();
    [$out, $counter] = $spawnAll('oqc:c1', array_map(static fn(int $w): array => [$ip, '-', "c1r{$round}v{$w}", 100 + $round], range(0, 15)), $t + 3);
    $c1[] = ['completed' => $completed($out), 'checksCompletedBeforeFailPhase' => $counter, 'allowedChecks' => $allowed($out), 'failAllowed' => $failAllowed($out), 'maxLevelIssuedInBurst' => $maxLevel($out), 'warm' => $warmSteps];
    $c1Probe[] = $denied($probe('oqc:c1', $ip, 100 + $round, "c1r{$round}next", null));
}
emit('O06.C1.k1-live-burst', ['arm' => $arm, 'workersPerRound' => 16, 'rounds' => $c1, 'nextSequentialCheck' => $c1Probe]);
OqcAsserts::check('O06', 'C1 negative control: all 48 concurrent workers completed and every round\'s completion counter proves all 16 checkOnly finished before the first recordFailure', array_sum(array_column($c1, 'completed')) === 48 && array_column($c1, 'checksCompletedBeforeFailPhase') === [16, 16, 16], ['completed' => array_column($c1, 'completed'), 'counters' => array_column($c1, 'checksCompletedBeforeFailPhase')]);
$allowedRounds = array_column($c1, 'allowedChecks');
if ($cand) {
    OqcAsserts::check('O06', 'C1 on this arm ALL 16 simultaneous distinct failing subjects pass checkOnly on a live scope in every round (nothing is observed at checkOnly): the per-cycle leak equals the concurrency', $allowedRounds === [16, 16, 16], ['allowed' => $allowedRounds]);
} else {
    OqcAsserts::check('O06', 'C1 S0: exactly 3 of 16 simultaneous distinct subjects pass checkOnly in every round (the warm-up subject is member 1; members 2..4 pass, the 5th+ is denied: the K1 set is observed atomically at checkOnly)', $allowedRounds === [3, 3, 3], ['allowed' => $allowedRounds]);
}
OqcAsserts::check('O06', 'C4 after the burst the next sequential checkOnly on the same IP is denied on this arm (the failures issued the K1 block)', $c1Probe === ['denied', 'denied', 'denied'], ['probe' => $c1Probe]);
$levels = array_column($c1, 'maxLevelIssuedInBurst');
// (On S0 the failures of the 3 allowed workers run after the 5th worker's checkOnly persisted the K1 block, so they return that L2 block.)
OqcAsserts::check('O06', 'E1 the block level issued within ONE concurrent burst of failures never exceeds L2 (one burst is one block cycle; an escalation step per concurrent failure would be a finding)', max($levels) <= 2, ['maxLevelPerRound' => $levels]);

// C2 — K2 burst on a LIVE IP+UA scope, 3 rounds: 8 distinct fingerprints, distinct accounts.
$c2 = [];
$c2Probe = [];
for ($round = 1; $round <= 3; $round++) {
    $ip = "10.91.$round.1";
    $warmSteps = $warm('oqc:c2', $ip, 120, 'warmfp');
    $t = $now();
    [$out, $counter] = $spawnAll('oqc:c2', array_map(static fn(int $w): array => [$ip, "c2r{$round}fp{$w}", "c2r{$round}a{$w}", 120], range(0, 7)), $t + 3);
    // S0's own race (which rule denies member 2 under concurrency) makes its exact count vary between 0 and 1 from run to run; the golden
    // records the bound for S0 and the exact (deterministic) count for the candidate arms.
    $c2[] = $cand
        ? ['completed' => $completed($out), 'checksCompletedBeforeFailPhase' => $counter, 'allowedChecks' => $allowed($out), 'maxLevelIssuedInBurst' => $maxLevel($out), 'warm' => $warmSteps]
        : ['completed' => $completed($out), 'checksCompletedBeforeFailPhase' => $counter, 'allowedChecksAtMost1' => $allowed($out) <= 1, 'maxLevelIssuedInBurstAtMost2' => $maxLevel($out) <= 2, 'warm' => $warmSteps];
    $c2Probe[] = $denied($probe('oqc:c2', $ip, 120, "c2r{$round}next", "c2r{$round}fpnext"));
}
// C2 reference: the same warm-up + 8 fingerprints run SEQUENTIALLY on Redis and on the reference store (parity of the sequential case).
$rt = wsbRedisPipeline($ex, 'oqc:c2seq');
$seqRedis = [];
$wc = new RateLimitContextDTO('10.91.9.1', k1Ua(120), 'warmacct', fp('warmfp'));
wsbRedisRun($rt, $wc, 'check');
wsbRedisRun($rt, $wc, 'ok');
for ($w = 0; $w < 8; $w++) {
    $seqRedis[] = str_starts_with(wsbRedisRun($rt, new RateLimitContextDTO('10.91.9.1', k1Ua(120), "c2sa{$w}", fp("c2sfp{$w}")), 'check'), 'check:A') ? 'a' : 'B';
}
$lab = oqcLab();
k1Replay($lab, [k1Ev(0, '10.91.9.1', 'warmfp', 'warmacct', 'ok', 'legit', ['uaRaw' => k1Ua(120)])], 'pre+out', 'login');
$seqEv = [];
for ($w = 0; $w < 8; $w++) {
    $seqEv[] = k1Ev(3, '10.91.9.1', "c2sfp{$w}", "c2sa{$w}", 'fail', 'attack', ['uaRaw' => k1Ua(120)]);
}
$seqRef = array_map(static fn(array $r): string => $r['blocked'] ? 'B' : 'a', k1Replay($lab, $seqEv, 'pre', 'login'));
emit('O06.C2.k2-live-burst', ['arm' => $arm, 'workersPerRound' => 8, 'rounds' => $c2, 'nextSequentialCheck' => $c2Probe, 'sequentialRedis' => $seqRedis, 'sequentialReference' => $seqRef]);
OqcAsserts::check('O06', 'C2s sequential parity: the warm-up + 8 fingerprints run one after another give identical decisions on Redis and on the reference store', $seqRedis === $seqRef, ['redis' => $seqRedis, 'reference' => $seqRef]);
OqcAsserts::check('O06', 'C2 negative control: all 24 concurrent workers completed and every round\'s completion counter proves all 8 checkOnly finished before the first recordFailure', array_sum(array_column($c2, 'completed')) === 24 && array_column($c2, 'checksCompletedBeforeFailPhase') === [8, 8, 8], ['completed' => array_column($c2, 'completed'), 'counters' => array_column($c2, 'checksCompletedBeforeFailPhase')]);
if ($cand) {
    $allowedRounds = array_column($c2, 'allowedChecks');
    OqcAsserts::check('O06', 'C2 on this arm ALL 8 simultaneous distinct fingerprints pass checkOnly on a live IP+UA scope in every round (K2 churn is observed only at recordFailure)', $allowedRounds === [8, 8, 8], ['allowed' => $allowedRounds]);
} else {
    // Sequentially exactly one fingerprint (member 2) passes (C2s). Under concurrency S0 denies 7 or all 8 (its own race; which rule denies
    // member 2 was not isolated here). The invariant stated is the upper bound: at most 1 of 8 passes.
    OqcAsserts::check('O06', 'C2 S0: at most 1 of 8 simultaneous distinct fingerprints passes checkOnly in every round (member 2 may pass with WATCH 1; member 3 triggers churn outright; the rest are rejected at the cap and denied)', array_column($c2, 'allowedChecksAtMost1') === [true, true, true], ['atMost1' => array_column($c2, 'allowedChecksAtMost1')]);
}
OqcAsserts::check('O06', 'C4b after the K2 burst the next sequential checkOnly on the same IP+UA is denied on this arm', $c2Probe === ['denied', 'denied', 'denied'], ['probe' => $c2Probe]);

// C3 — control: the K1 burst on a SILENT scope (no earlier outcome). Every arm applies the pre-check rule, so the atomic cap holds.
$c3 = [];
for ($round = 1; $round <= 3; $round++) {
    $ip = "10.92.$round.1";
    $t = $now();
    [$out, $counter] = $spawnAll('oqc:c3', array_map(static fn(int $w): array => [$ip, '-', "c3r{$round}v{$w}", 140 + $round], range(0, 15)), $t + 3);
    $c3[] = ['completed' => $completed($out), 'checksCompletedBeforeFailPhase' => $counter, 'allowedChecks' => $allowed($out), 'maxLevelIssuedInBurst' => $maxLevel($out)];
}
emit('O06.C3.k1-silent-burst', ['arm' => $arm, 'workersPerRound' => 16, 'rounds' => $c3]);
OqcAsserts::check('O06', 'C3 negative control: all 48 concurrent workers completed and every round\'s completion counter proves all 16 checkOnly finished before the first recordFailure', array_sum(array_column($c3, 'completed')) === 48 && array_column($c3, 'checksCompletedBeforeFailPhase') === [16, 16, 16], ['completed' => array_column($c3, 'completed'), 'counters' => array_column($c3, 'checksCompletedBeforeFailPhase')]);
OqcAsserts::check('O06', 'C3 control: on a SILENT scope exactly 4 of 16 simultaneous distinct subjects pass checkOnly in every round on every arm (the silent path is S0, observed atomically at checkOnly)', array_column($c3, 'allowedChecks') === [4, 4, 4], ['allowed' => array_column($c3, 'allowedChecks')]);

// TTL hygiene of everything written by the bursts.
$keys = array_map('strval', (array) $ex->execute(['KEYS', '*']));
$noTtl = 0;
foreach ($keys as $k) {
    if ((int) $ex->execute(['TTL', $k]) < 0) {
        $noTtl++;
    }
}
emit('O06.T1.ttl-hygiene', ['arm' => $arm, 'keys' => count($keys), 'keysWithoutTtl' => $noTtl]);
OqcAsserts::check('O06', 'T1 every Redis key written by the concurrent bursts has a TTL', $noTtl === 0 && count($keys) > 0, ['keys' => count($keys), 'noTtl' => $noTtl]);
OqcAsserts::finish();
