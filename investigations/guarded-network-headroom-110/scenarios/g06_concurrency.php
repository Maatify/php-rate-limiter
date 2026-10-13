<?php

declare(strict_types=1);

// G06 — CONCURRENCY on real Redis (instance owned and proven by run.sh): the budget at its boundary with simultaneous requests that exceed
// the remaining balance. Every burst is checkOnly only (no outcome), started at one Redis instant by separate processes; a Redis completion
// counter (re-read by the parent) proves every worker finished. Three rounds per case, each on its own scope.
//   C1  K1: the spray zone with 14 of B=20 already spent (remaining 6), then 16 simultaneous requests with KNOWN subjects
//   C2  K2: the churn zone with 8 of B=12 spent (remaining 4), then 8 simultaneous requests with KNOWN devices
//   C3  a fresh K1 scope and 32 simultaneous DISTINCT subjects (the zone opens inside the burst)
//   C4  both budgets in zone (K2 remaining 5, K1 remaining 15): 8 simultaneous requests known on both scopes — no cross-key reservation
//   T1  TTL hygiene of every key the bursts wrote
require dirname(__DIR__, 2) . '/workstream-b-anti-evasion/lib/redis.php';
require dirname(__DIR__) . '/lib/gnh.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

emit('G06.provenance', gnhProvenance());
$arm = gnhArm();
$ex = wsbRedisConnectVerified(true);
$worker = __DIR__ . '/g06_worker.php';

/**
 * @param list<array{0: string, 1: ?string, 2: string, 3: int}> $jobs [ip, fp|null, account, uaIdx]
 * @return array{0: list<string>, 1: int} worker outputs, completion counter read after the round
 */
$burst = static function (string $ns, array $jobs) use ($worker, $ex): array {
    $counterKey = 'gnh:counter:' . $ns . ':' . bin2hex(random_bytes(4));
    $startAt = (int) $ex->execute(['TIME'])[0] + 3;
    $env = ['WSB_REDIS_PORT' => getenv('WSB_REDIS_PORT'), 'WSB_REDIS_PID' => getenv('WSB_REDIS_PID'), 'WSB_REDIS_DIR' => getenv('WSB_REDIS_DIR'), 'PATH' => getenv('PATH')];
    foreach (['LAB_ROOT', 'GNH_ARM'] as $k) {
        if (getenv($k) !== false) {
            $env[$k] = getenv($k);
        }
    }
    $procs = [];
    foreach ($jobs as [$ip, $fp, $acct, $ua]) {
        $p = proc_open([PHP_BINARY, $worker, $ns, $ip, $fp ?? '-', $acct, (string) $ua, (string) $startAt, $counterKey], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $procs[] = [$p, $pipes];
    }
    $out = [];
    foreach ($procs as [$p, $pipes]) {
        $out[] = trim((string) stream_get_contents($pipes[1])) . trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
    }

    return [$out, (int) $ex->execute(['GET', $counterKey])];
};
$completed = static fn(array $out): int => count(array_filter($out, static fn(string $o): bool => str_starts_with($o, 'check:')));
$allowed = static fn(array $out): int => count(array_filter($out, static fn(string $o): bool => str_starts_with($o, 'check:A')));
/** @param list<array{0: string, 1: ?string, 2: string, 3: int}> $reqs */
$sequential = static function (string $ns, array $reqs) use ($ex): string {
    $rt = wsbRedisPipeline($ex, $ns);
    $s = '';
    foreach ($reqs as [$ip, $fp, $acct, $ua]) {
        $s .= str_starts_with(wsbRedisRun($rt, new RateLimitContextDTO($ip, k1Ua($ua), $acct, $fp === null ? null : fp($fp)), 'check'), 'check:A') ? 'a' : 'B';
    }

    return $s;
};
$prefix = (new \ReflectionClassConstant(\Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore::class, 'PREFIX'))->getValue();
$lab = gnhLab();
$budgetValue = static function (string $ns, string $rule, string $ip, int $ua, string $acct, ?string $fp) use ($ex, $prefix, $lab): int {
    $keys = $lab->keys($lab->ctx($ip, k1Ua($ua), $acct, $fp === null ? null : fp($fp)));
    $logical = $rule === 'spray'
        ? 'gnh5:budget:spray:credential_spray:' . $keys['K1']
        : 'gnh5:budget:churn:' . hash_hmac('sha256', 'login_protection:rate_limiter:correlation:churn:v1:prod:scope:' . $keys['K2'], Lab::SECRET);
    $v = $ex->execute(['GET', $prefix . ':' . hash('sha256', $ns) . ':watch:' . hash('sha256', $logical)]);

    return $v === null ? 0 : (int) $v;
};
$expect = static fn(array $byArm): mixed => $byArm[gnhArm()];

// C1 — K1 spray boundary.
$c1 = [];
for ($round = 1; $round <= 3; $round++) {
    $ip = "10.95.$round.1";
    $ua = 130 + $round;
    $pre = [];
    for ($i = 0; $i < 5; $i++) {
        $pre[] = [$ip, null, "c1r{$round}s$i", $ua];
    }
    for ($i = 0; $i < 13; $i++) {
        $pre[] = [$ip, null, "c1r{$round}s0", $ua];
    }
    $seq = $sequential('gnh:c1', $pre);
    $before = $budgetValue('gnh:c1', 'spray', $ip, $ua, "c1r{$round}s0", null);
    [$out, $counter] = $burst('gnh:c1', array_map(static fn(int $w): array => [$ip, null, "c1r{$round}s" . ($w % 4), $ua], range(0, 15)));
    $after = $budgetValue('gnh:c1', 'spray', $ip, $ua, "c1r{$round}s0", null);
    $probe = $sequential('gnh:c1', [[$ip, null, "c1r{$round}s1", $ua]]);
    $c1[] = ['sequentialPrefix' => $seq, 'budgetBefore' => $before, 'completed' => $completed($out), 'counter' => $counter, 'allowedInBurst' => $allowed($out), 'admissionsInWindow' => $before === 0 ? 0 : min($after, GNH_BUDGET_PER_RULE['spray']), 'budgetAfterAtMostBeforePlus16' => $after <= $before + 16, 'nextCheck' => $probe];
}
emit('G06.C1.k1-boundary-burst', ['arm' => $arm, 'workersPerRound' => 16, 'rounds' => $c1]);
GnhAsserts::check('G06', 'C1 negative control: all 48 concurrent workers completed and every round\'s completion counter reads 16', array_sum(array_column($c1, 'completed')) === 48 && array_column($c1, 'counter') === [16, 16, 16], ['completed' => array_column($c1, 'completed'), 'counters' => array_column($c1, 'counter')]);
GnhAsserts::check('G06', 'C1 ' . $expect([
    'S0' => 'S0: the sequential prefix is denied from the 5th subject on and none of the 16 simultaneous requests passes (persisted K1 block)',
    'TH5' => 'TH5: all 16 simultaneous in-zone requests pass in every round (no budget): the zone admits whatever concurrency it receives',
    'GNH5' => 'GNH5: with 6 of B=20 left, exactly 6 of 16 simultaneous requests pass in every round; the window total is exactly B=20 and the next request is denied',
]), $expect([
    'S0' => array_column($c1, 'allowedInBurst') === [0, 0, 0] && array_column($c1, 'sequentialPrefix') === array_fill(0, 3, 'aaaa' . str_repeat('B', 14)),
    'TH5' => array_column($c1, 'allowedInBurst') === [16, 16, 16],
    'GNH5' => array_column($c1, 'allowedInBurst') === [6, 6, 6] && array_column($c1, 'budgetBefore') === [14, 14, 14] && array_column($c1, 'admissionsInWindow') === [20, 20, 20] && array_column($c1, 'nextCheck') === ['B', 'B', 'B'],
]), ['allowed' => array_column($c1, 'allowedInBurst'), 'before' => array_column($c1, 'budgetBefore'), 'window' => array_column($c1, 'admissionsInWindow')]);

// C2 — K2 churn boundary.
$c2 = [];
for ($round = 1; $round <= 3; $round++) {
    $ip = "10.96.$round.1";
    $ua = 140 + $round;
    $pre = [];
    for ($i = 0; $i < 3; $i++) {
        $pre[] = [$ip, "c2r{$round}f$i", "c2r{$round}a$i", $ua];
    }
    for ($i = 0; $i < 7; $i++) {
        $pre[] = [$ip, "c2r{$round}f0", "c2r{$round}a0", $ua];
    }
    $seq = $sequential('gnh:c2', $pre);
    $before = $budgetValue('gnh:c2', 'churn', $ip, $ua, "c2r{$round}a0", "c2r{$round}f0");
    [$out, $counter] = $burst('gnh:c2', array_map(static fn(int $w): array => [$ip, "c2r{$round}f" . ($w % 3), "c2r{$round}a" . ($w % 3), $ua], range(0, 7)));
    $after = $budgetValue('gnh:c2', 'churn', $ip, $ua, "c2r{$round}a0", "c2r{$round}f0");
    $c2[] = ['sequentialPrefix' => $seq, 'budgetBefore' => $before, 'completed' => $completed($out), 'counter' => $counter, 'allowedInBurst' => $allowed($out), 'admissionsInWindow' => $before === 0 ? 0 : min($after, GNH_BUDGET_PER_RULE['churn'])];
}
emit('G06.C2.k2-boundary-burst', ['arm' => $arm, 'workersPerRound' => 8, 'rounds' => $c2]);
GnhAsserts::check('G06', 'C2 negative control: all 24 concurrent workers completed and every round\'s completion counter reads 8', array_sum(array_column($c2, 'completed')) === 24 && array_column($c2, 'counter') === [8, 8, 8], ['completed' => array_column($c2, 'completed'), 'counters' => array_column($c2, 'counter')]);
GnhAsserts::check('G06', 'C2 ' . $expect([
    'S0' => 'S0: the 3rd device is denied sequentially and none of the 8 simultaneous requests passes (persisted K2 block)',
    'TH5' => 'TH5: all 8 simultaneous in-zone requests pass in every round',
    'GNH5' => 'GNH5: with 4 of B=12 left, exactly 4 of 8 simultaneous requests pass in every round; the window total is exactly B=12',
]), $expect([
    'S0' => array_column($c2, 'allowedInBurst') === [0, 0, 0] && array_column($c2, 'sequentialPrefix') === array_fill(0, 3, 'aa' . str_repeat('B', 8)),
    'TH5' => array_column($c2, 'allowedInBurst') === [8, 8, 8],
    'GNH5' => array_column($c2, 'allowedInBurst') === [4, 4, 4] && array_column($c2, 'budgetBefore') === [8, 8, 8] && array_column($c2, 'admissionsInWindow') === [12, 12, 12],
]), ['allowed' => array_column($c2, 'allowedInBurst'), 'before' => array_column($c2, 'budgetBefore'), 'window' => array_column($c2, 'admissionsInWindow')]);

// C3 — fresh K1 scope, 32 simultaneous distinct subjects. Which worker wins which set slot is a race; the bounds are not.
$c3 = [];
for ($round = 1; $round <= 3; $round++) {
    $ip = "10.97.$round.1";
    $ua = 160 + $round;
    [$out, $counter] = $burst('gnh:c3', array_map(static fn(int $w): array => [$ip, null, "c3r{$round}s$w", $ua], range(0, 31)));
    $budget = $budgetValue('gnh:c3', 'spray', $ip, $ua, "c3r{$round}s0", null);
    $n = $allowed($out);
    $c3[] = ['completed' => $completed($out), 'counter' => $counter, 'allowedExactly4' => $n === 4, 'allowedAtMost24' => $n <= 24, 'zoneAdmissionsAtMostB' => min($budget, 99) <= 32 && max(0, $n - 4) <= GNH_BUDGET_PER_RULE['spray']];
}
emit('G06.C3.k1-fresh-burst', ['arm' => $arm, 'workersPerRound' => 32, 'rounds' => $c3]);
GnhAsserts::check('G06', 'C3 negative control: all 96 concurrent workers completed and every round\'s completion counter reads 32', array_sum(array_column($c3, 'completed')) === 96 && array_column($c3, 'counter') === [32, 32, 32], ['completed' => array_column($c3, 'completed'), 'counters' => array_column($c3, 'counter')]);
GnhAsserts::check('G06', 'C3 ' . $expect([
    'S0' => 'S0: exactly 4 of 32 simultaneous distinct subjects pass in every round (the bounded set is atomic)',
    'TH5' => 'TH5: at most 24 of 32 pass in every round (x5 set: N-1 = 24)',
    'GNH5' => 'GNH5: at most 4 + B = 24 of 32 pass in every round and at most B of them are headroom admissions',
]), $expect([
    'S0' => array_column($c3, 'allowedExactly4') === [true, true, true],
    'TH5' => array_column($c3, 'allowedAtMost24') === [true, true, true],
    'GNH5' => array_column($c3, 'allowedAtMost24') === [true, true, true] && array_column($c3, 'zoneAdmissionsAtMostB') === [true, true, true],
]), ['rounds' => $c3]);

// C4 — two budgets in zone on the same requests (K2 churn remaining 5, K1 spray remaining 15): no reservation spans both keys.
$c4 = [];
for ($round = 1; $round <= 3; $round++) {
    $ip = "10.98.$round.1";
    $ua = 170 + $round;
    $pre = [[$ip, "c4r{$round}f0", "c4r{$round}s0", $ua], [$ip, "c4r{$round}f1", "c4r{$round}s1", $ua], [$ip, "c4r{$round}f2", "c4r{$round}s2", $ua], [$ip, "c4r{$round}f0", "c4r{$round}s3", $ua], [$ip, "c4r{$round}f0", "c4r{$round}s4", $ua]];
    for ($i = 0; $i < 4; $i++) {
        $pre[] = [$ip, "c4r{$round}f0", "c4r{$round}s0", $ua];
    }
    $seq = $sequential('gnh:c4', $pre);
    $churnBefore = $budgetValue('gnh:c4', 'churn', $ip, $ua, "c4r{$round}s0", "c4r{$round}f0");
    $sprayBefore = $budgetValue('gnh:c4', 'spray', $ip, $ua, "c4r{$round}s0", "c4r{$round}f0");
    [$out, $counter] = $burst('gnh:c4', array_map(static fn(int $w): array => [$ip, "c4r{$round}f" . ($w % 3), "c4r{$round}s" . ($w % 3), $ua], range(0, 7)));
    $sprayAfter = $budgetValue('gnh:c4', 'spray', $ip, $ua, "c4r{$round}s0", "c4r{$round}f0");
    $n = $allowed($out);
    $c4[] = ['sequentialPrefix' => $seq, 'churnBefore' => $churnBefore, 'sprayBefore' => $sprayBefore, 'completed' => $completed($out), 'counter' => $counter, 'allowedInBurst' => $n, 'spraySpentByRefusedRequestsAtLeast1' => $sprayAfter - $sprayBefore - $n >= 1];
}
emit('G06.C4.two-budget-burst', ['arm' => $arm, 'workersPerRound' => 8, 'rounds' => $c4]);
GnhAsserts::check('G06', 'C4 negative control: all 24 concurrent workers completed and every round\'s completion counter reads 8', array_sum(array_column($c4, 'completed')) === 24 && array_column($c4, 'counter') === [8, 8, 8], ['completed' => array_column($c4, 'completed'), 'counters' => array_column($c4, 'counter')]);
GnhAsserts::check('G06', 'C4 ' . $expect([
    'S0' => 'S0: none of the 8 simultaneous requests passes (K2 block persisted by the sequential prefix)',
    'TH5' => 'TH5: all 8 pass in every round (no budget on either scope)',
    'GNH5' => 'GNH5: exactly 5 of 8 pass (the smaller remaining budget, K2) in every round, and the K1 budget is also spent by at least one request that K2 refused: two independent atomic counters, no cross-key reservation (fail-closed over-spend)',
]), $expect([
    'S0' => array_column($c4, 'allowedInBurst') === [0, 0, 0],
    'TH5' => array_column($c4, 'allowedInBurst') === [8, 8, 8],
    'GNH5' => array_column($c4, 'allowedInBurst') === [5, 5, 5] && array_column($c4, 'churnBefore') === [7, 7, 7] && array_column($c4, 'sprayBefore') === [5, 5, 5] && array_column($c4, 'spraySpentByRefusedRequestsAtLeast1') === [true, true, true],
]), ['allowed' => array_column($c4, 'allowedInBurst'), 'churnBefore' => array_column($c4, 'churnBefore'), 'sprayBefore' => array_column($c4, 'sprayBefore'), 'overspend' => array_column($c4, 'spraySpentByRefusedRequestsAtLeast1')]);

// T1 — TTL hygiene of everything written by the bursts (the completion counters carry TTL 120 s).
$keys = array_map('strval', (array) $ex->execute(['KEYS', '*']));
$noTtl = 0;
foreach ($keys as $k) {
    if ((int) $ex->execute(['TTL', $k]) < 0) {
        $noTtl++;
    }
}
emit('G06.T1.ttl-hygiene', ['arm' => $arm, 'keysPresent' => count($keys) > 0, 'keysWithoutTtl' => $noTtl]);
GnhAsserts::check('G06', 'T1 every Redis key written by the concurrent bursts has a TTL', $noTtl === 0 && count($keys) > 0, ['noTtl' => $noTtl]);
GnhAsserts::finish();
