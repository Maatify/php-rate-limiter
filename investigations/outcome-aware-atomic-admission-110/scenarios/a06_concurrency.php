<?php

declare(strict_types=1);

// A06 — CONCURRENCY on real Redis (instance owned and proven by run.sh). Every burst is started at one Redis instant by separate PHP
// processes; a Redis completion counter (re-read by the parent) proves every worker finished. Each case runs 3 rounds on its own scope.
//   C1  one unit left on a credited K1 scope: 16 simultaneous pre-checks (16 new subjects)
//   C2  no unit left: 16 simultaneous pre-checks
//   C3  K1 + K2 in the same requests: K1 credited with room, K2 churn without credit: 8 simultaneous -> none admitted, K1 keeps 0 units
//   C4  8 credited scopes (16 units of scope room) but 12 global units: 16 simultaneous pre-checks over the 8 IPs
//   C5  successes racing pre-checks: 16 credited colleagues, each pre-check + immediate success, simultaneously, on a scope with 2 units
//   C6  one success replayed by 8 workers at once on a tuple with 1 unresolved reservation and 1 recorded failure
//   C7  expiry during verification (simulated by expiring the study's own tuple / tier key): no refund, never into a new epoch
//   C8  rotation with active reservations: units taken under the old secret block a 16-way burst under the new secret; an old reservation
//       is never refunded through the new generation
//   T1  TTL hygiene of every key the bursts wrote
require dirname(__DIR__, 2) . '/workstream-b-anti-evasion/lib/redis.php';
require dirname(__DIR__) . '/lib/oaaa.php';

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\FingerprintHasher;

emit('A06.provenance', oaaaProvenance());
$arm = oaaaArm();
$cand = $arm === 'OAAA';
$ex = wsbRedisConnectVerified(true);
$worker = __DIR__ . '/a06_worker.php';
$prefix = (new \ReflectionClassConstant(RedisFullCapabilityStore::class, 'PREFIX'))->getValue();
$physical = static fn(string $ns, string $logical): string => $prefix . ':' . hash('sha256', $ns) . ':oaaa:' . hash('sha256', $logical);
$units = static function (string $ns, string $logical) use ($ex, $physical): int {
    $v = $ex->execute(['HGET', $physical($ns, $logical), 'u']);

    return $v === null ? 0 : (int) $v;
};

/** Sequential commands in THIS process (setup). @param list<array{0: string, 1: ?string, 2: string, 3: int, 4: string}> $cmds */
$seq = static function (string $ns, array $cmds, string $secret = Lab::SECRET, ?string $prev = null) use ($ex): string {
    $clock = wsbRedisClock($ex);
    $store = new RedisFullCapabilityStore($ex, $ns);
    $pipeline = new EvaluationPipeline($store, $store, new BudgetTracker($store, $clock), new AntiEquilibriumGate($store), new DecayCalculator($clock), new EphemeralBucket($store), $secret, 'prod', $clock, $prev);
    $resolver = new DeviceIdentityResolver(new FingerprintHasher($secret), $prev === null ? null : new FingerprintHasher($prev));
    $policy = new LoginProtectionPolicy();
    $out = '';
    foreach ($cmds as [$ip, $fp, $acct, $ua, $cmd]) {
        $c = new RateLimitContextDTO($ip, k1Ua($ua), $acct, $fp === null ? null : fp($fp));
        $command = match ($cmd) {
            'check' => RateLimitCommand::checkOnly('login_protection'),
            'ok' => RateLimitCommand::recordSuccess('login_protection'),
            'fail' => RateLimitCommand::recordFailure('login_protection'),
        };
        $out .= $pipeline->process($policy, $c, $command, $resolver->resolve($c))->decision === 'ALLOW' ? 'a' : 'B';
    }

    return $out;
};
/** Simultaneous burst: one worker per command list. @param list<list<array{0: string, 1: ?string, 2: string, 3: int, 4: string}>> $jobs */
$burst = static function (string $ns, array $jobs, string $secret = Lab::SECRET, ?string $prev = null) use ($worker, $ex): array {
    $counterKey = 'oaaa:counter:' . $ns . ':' . bin2hex(random_bytes(4));
    $startAt = (int) $ex->execute(['TIME'])[0] + 3;
    $env = ['WSB_REDIS_PORT' => getenv('WSB_REDIS_PORT'), 'WSB_REDIS_PID' => getenv('WSB_REDIS_PID'), 'WSB_REDIS_DIR' => getenv('WSB_REDIS_DIR'), 'PATH' => getenv('PATH')];
    foreach (['LAB_ROOT', 'OAAA_ARM'] as $k) {
        if (getenv($k) !== false) {
            $env[$k] = getenv($k);
        }
    }
    $procs = [];
    foreach ($jobs as $cmds) {
        $p = proc_open([PHP_BINARY, $worker, $ns, $secret, $prev ?? '-', json_encode($cmds, JSON_THROW_ON_ERROR), (string) $startAt, $counterKey], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $procs[] = [$p, $pipes];
    }
    $out = [];
    foreach ($procs as [$p, $pipes]) {
        $so = trim((string) stream_get_contents($pipes[1]));
        $se = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
        $d = json_decode($so, true);
        $out[] = is_array($d) && $se === '' ? $d['steps'] : ['ERROR:' . $so . $se];
    }

    return [$out, (int) $ex->execute(['GET', $counterKey])];
};
$admitted = static fn(array $out): int => count(array_filter($out, static fn(array $s): bool => ($s[0] ?? '') === 'check:A'));
$completed = static fn(array $out): int => count(array_filter($out, static fn(array $s): bool => isset($s[0]) && str_starts_with($s[0], 'check:') || isset($s[0]) && str_starts_with($s[0], 'ok:')));
$errors = static fn(array $out): int => count(array_filter($out, static fn(array $s): bool => isset($s[0]) && str_starts_with($s[0], 'ERROR')));
$a = static fn(string $ip, ?string $fp, string $acct, int $ua, string $o): array => [[$ip, $fp, $acct, $ua, 'check'], [$ip, $fp, $acct, $ua, $o]];
$credited = static function (string $ns, string $ip, string $tag, int $ua) use ($seq, $a): string {
    $c = [];
    for ($i = 0; $i < 5; $i++) {
        $c = array_merge($c, $a($ip, null, "{$tag}s$i", $ua, 'ok'));
    }

    return $seq($ns, $c);
};
$lab = oaaaLab();
$tier = static fn(string $ip, int $ua, string $rule = 'spray'): string => match ($rule) {
    'spray' => 'oaaa:v1:e:spray:credential_spray:' . $lab->keys(new RateLimitContextDTO($ip, k1Ua($ua), 'x'))['K1'],
    'churn' => 'oaaa:v1:e:churn:' . hash_hmac('sha256', 'login_protection:rate_limiter:correlation:churn:v1:prod:scope:' . $lab->keys(new RateLimitContextDTO($ip, k1Ua($ua), 'x'))['K2'], Lab::SECRET),
};
$global = 'oaaa:v1:g:e:' . hash('sha256', 'login_protection:prod');
$pick = static fn(array $rounds, string $f): array => array_column($rounds, $f);

// C1 / C2
foreach (['C1' => 1, 'C2' => 2] as $case => $spent) {
    $rounds = [];
    for ($r = 1; $r <= 3; $r++) {
        $ns = "oaaa:$case:$r";
        $ip = '10.9' . ($case === 'C1' ? '1' : '2') . ".$r.1";
        $setup = $credited($ns, $ip, "{$case}r$r", 30);
        for ($i = 0; $i < $spent; $i++) {
            $setup .= $seq($ns, $a($ip, null, "{$case}r{$r}sp$i", 30, 'fail'));
        }
        $before = $units($ns, $tier($ip, 30));
        [$out, $counter] = $burst($ns, array_map(static fn(int $w): array => [[$ip, null, "{$case}r{$r}w$w", 30, 'check']], range(0, 15)));
        $rounds[] = ['setup' => $setup, 'unitsBefore' => $before, 'admitted' => $admitted($out), 'completed' => $completed($out), 'counter' => $counter, 'errors' => $errors($out), 'unitsAfter' => $units($ns, $tier($ip, 30))];
    }
    emit("A06.$case.burst-" . ($spent === 1 ? 'one-unit-left' : 'no-unit-left'), ['arm' => $arm, 'workersPerRound' => 16, 'rounds' => $rounds]);
    OaaaAsserts::check('A06', "$case negative control: all 48 concurrent workers completed without error and every round's completion counter reads 16", $pick($rounds, 'counter') === [16, 16, 16] && array_sum($pick($rounds, 'completed')) === 48 && array_sum($pick($rounds, 'errors')) === 0, ['counters' => $pick($rounds, 'counter'), 'errors' => $pick($rounds, 'errors')]);
    $want = $case === 'C1' ? 1 : 0;
    OaaaAsserts::check('A06', "$case " . ($cand ? "with " . (2 - $spent) . " unit(s) left, exactly $want of 16 simultaneous pre-checks is admitted in every round and the scope ends at 2 units" : 'S0: none of the 16 simultaneous pre-checks is admitted'), $cand
        ? $pick($rounds, 'admitted') === [$want, $want, $want] && $pick($rounds, 'unitsBefore') === [$spent, $spent, $spent] && $pick($rounds, 'unitsAfter') === [2, 2, 2]
        : $pick($rounds, 'admitted') === [0, 0, 0], ['admitted' => $pick($rounds, 'admitted'), 'before' => $pick($rounds, 'unitsBefore'), 'after' => $pick($rounds, 'unitsAfter')]);
}

// C3 — K1 + K2 all-or-nothing under concurrency.
$rounds = [];
for ($r = 1; $r <= 3; $r++) {
    $ns = "oaaa:C3:$r";
    $ip = "10.93.$r.1";
    $setup = $seq($ns, [[$ip, 'fy0', "C3r{$r}y0", 41, 'check'], [$ip, 'fy1', "C3r{$r}y1", 41, 'check']]);
    for ($i = 0; $i < 3; $i++) {
        $setup .= $seq($ns, $a($ip, "fx$i", "C3r{$r}s$i", 40, 'ok'));
    }
    [$out, $counter] = $burst($ns, array_map(static fn(int $w): array => [[$ip, "fz$w", "C3r{$r}w$w", 41, 'check']], range(0, 7)));
    $rounds[] = ['setup' => $setup, 'admitted' => $admitted($out), 'counter' => $counter, 'errors' => $errors($out), 'sprayUnits' => $units($ns, $tier($ip, 41)), 'churnUnits' => $units($ns, $tier($ip, 41, 'churn')), 'globalUnits' => $units($ns, $global)];
}
emit('A06.C3.k1-k2-all-or-nothing', ['arm' => $arm, 'workersPerRound' => 8, 'rounds' => $rounds]);
OaaaAsserts::check('A06', 'C3 negative control: every round\'s completion counter reads 8, no worker error', $pick($rounds, 'counter') === [8, 8, 8] && array_sum($pick($rounds, 'errors')) === 0, ['counters' => $pick($rounds, 'counter')]);
OaaaAsserts::check('A06', 'C3 ' . ($cand ? '8 simultaneous requests firing K1 (credited, 2 units of room) and K2 churn (no credit): none admitted and NO unit taken on K1 or globally in any round (contrast #115 G06 C4 over-spend)' : 'S0: none admitted'), $cand
    ? $pick($rounds, 'admitted') === [0, 0, 0] && $pick($rounds, 'sprayUnits') === [0, 0, 0] && $pick($rounds, 'globalUnits') === [0, 0, 0]
    : $pick($rounds, 'admitted') === [0, 0, 0], ['admitted' => $pick($rounds, 'admitted'), 'spray' => $pick($rounds, 'sprayUnits'), 'global' => $pick($rounds, 'globalUnits')]);

// C4 — global tier under concurrency: 8 credited scopes (16 units of scope room), 12 global units.
$rounds = [];
for ($r = 1; $r <= 3; $r++) {
    $ns = "oaaa:C4:$r";
    $setup = '';
    for ($ip = 0; $ip < 8; $ip++) {
        $setup .= $credited($ns, "10.94.$r.$ip", "C4r{$r}i$ip", 50 + $ip);
    }
    $jobs = [];
    for ($ip = 0; $ip < 8; $ip++) {
        for ($j = 0; $j < 2; $j++) {
            $jobs[] = [["10.94.$r.$ip", null, "C4r{$r}v{$ip}_$j", 50 + $ip, 'check']];
        }
    }
    [$out, $counter] = $burst($ns, $jobs);
    $rounds[] = ['admitted' => $admitted($out), 'counter' => $counter, 'errors' => $errors($out), 'globalUnits' => $units($ns, $global)];
}
emit('A06.C4.global-tier-burst', ['arm' => $arm, 'workersPerRound' => 16, 'rounds' => $rounds]);
OaaaAsserts::check('A06', 'C4 negative control: every round\'s completion counter reads 16, no worker error', $pick($rounds, 'counter') === [16, 16, 16] && array_sum($pick($rounds, 'errors')) === 0, ['counters' => $pick($rounds, 'counter')]);
OaaaAsserts::check('A06', 'C4 ' . ($cand ? '16 simultaneous pre-checks over 8 credited scopes (16 units of scope room): exactly 12 admitted (the global epoch tier) in every round' : 'S0: none admitted (every scope already blocked by its 5th subject)'), $cand
    ? $pick($rounds, 'admitted') === [12, 12, 12] && $pick($rounds, 'globalUnits') === [12, 12, 12]
    : $pick($rounds, 'admitted') === [0, 0, 0], ['admitted' => $pick($rounds, 'admitted'), 'global' => $pick($rounds, 'globalUnits')]);

// C5 — successes racing pre-checks: 16 credited colleagues (subjects that already succeeded) do pre-check + success at once.
$rounds = [];
for ($r = 1; $r <= 3; $r++) {
    $ns = "oaaa:C5:$r";
    $ip = "10.95.$r.1";
    $credited($ns, $ip, "C5r$r", 60);
    [$out, $counter] = $burst($ns, array_map(static fn(int $w): array => $a($ip, null, "C5r{$r}s" . ($w % 5), 60, 'ok'), range(0, 15)));
    $unresolved = count(array_filter($out, static fn(array $s): bool => ($s[0] ?? '') === 'check:A' && ($s[1] ?? '') !== 'ok:A'));
    $rounds[] = ['admitted' => $admitted($out), 'admittedSuccessDeniedByPersistedBlock' => $unresolved, 'counter' => $counter, 'errors' => $errors($out), 'unitsAfter' => $units($ns, $tier($ip, 60)), 'globalAfter' => $units($ns, $global)];
}
// The raw numbers of this case are a race (how many credited successes return their unit before the first refusal persists the S0
// block), so only the invariants are emitted; the raw rounds go to stderr-free scratch output only.
$c5 = ['admittedAtLeast2EveryRound' => min($pick($rounds, 'admitted')) >= 2, 'unitsNeverAbove2' => max($pick($rounds, 'unitsAfter')) <= 2, 'unitsNeverNegative' => min($pick($rounds, 'unitsAfter')) >= 0, 'globalMirrorsScope' => $pick($rounds, 'unitsAfter') === $pick($rounds, 'globalAfter'), 'spentUnitsEqualAdmittedSuccessesThatMetTheBlock' => $pick($rounds, 'unitsAfter') === $pick($rounds, 'admittedSuccessDeniedByPersistedBlock'), 'everyRoundAdmittedFewerThan16' => max($pick($rounds, 'admitted')) < 16];
emit('A06.C5.successes-racing-prechecks', ['arm' => $arm, 'workersPerRound' => 16, 'invariants' => $c5, 'counters' => $pick($rounds, 'counter')]);
OaaaAsserts::check('A06', 'C5 negative control: every round\'s completion counter reads 16, no worker error', $pick($rounds, 'counter') === [16, 16, 16] && array_sum($pick($rounds, 'errors')) === 0, ['counters' => $pick($rounds, 'counter')]);
OaaaAsserts::check('A06', 'C5 ' . ($cand ? 'a 16-way burst of credited colleagues (pre-check + success) on a scope with 2 units: at least 2 are admitted; the first refusal persists the S0 K1 block, so the rest are denied (legitimate-concurrency limit); units never exceed 2 or go negative, the global tier mirrors the scope, and a unit stays spent only for an admitted attempt whose success met the persisted block (S0 ordering: such an outcome is not resolved)' : 'S0: none admitted'), $cand
    ? min($pick($rounds, 'admitted')) >= 2 && max($pick($rounds, 'unitsAfter')) <= 2 && min($pick($rounds, 'unitsAfter')) >= 0 && $pick($rounds, 'unitsAfter') === $pick($rounds, 'globalAfter') && $pick($rounds, 'unitsAfter') === $pick($rounds, 'admittedSuccessDeniedByPersistedBlock')
    : $pick($rounds, 'admitted') === [0, 0, 0], $c5);

// C6 — one success replayed by 8 workers at once. Tuple T (MEDIUM device, own UA): 1 recorded failure + 1 unresolved reservation.
$rounds = [];
for ($r = 1; $r <= 3; $r++) {
    $ns = "oaaa:C6:$r";
    $ip = "10.96.$r.1";
    $credited($ns, $ip, "C6r$r", 70);
    $setup = $seq($ns, $a($ip, 'c6dev', "C6r{$r}col", 71, 'fail')) . $seq($ns, [[$ip, 'c6dev', "C6r{$r}col", 71, 'check']]);
    $before = $units($ns, $tier($ip, 71));
    [$out, $counter] = $burst($ns, array_map(static fn(int $w): array => [[$ip, 'c6dev', "C6r{$r}col", 71, 'ok']], range(0, 7)));
    $rounds[] = ['setup' => $setup, 'unitsBefore' => $before, 'counter' => $counter, 'errors' => $errors($out), 'unitsAfter' => $units($ns, $tier($ip, 71))];
}
emit('A06.C6.replayed-completion', ['arm' => $arm, 'workersPerRound' => 8, 'rounds' => $rounds]);
OaaaAsserts::check('A06', 'C6 negative control: every round\'s completion counter reads 8, no worker error', $pick($rounds, 'counter') === [8, 8, 8] && array_sum($pick($rounds, 'errors')) === 0, ['counters' => $pick($rounds, 'counter')]);
OaaaAsserts::check('A06', 'C6 ' . ($cand ? 'the same success replayed by 8 workers at once resolves the tuple once: 2 units before, exactly 0 after (one own unit + one recorded failure returned; never negative, never more)' : 'S0 reference: no OAAA units exist'), $cand
    ? $pick($rounds, 'unitsBefore') === [2, 2, 2] && $pick($rounds, 'unitsAfter') === [0, 0, 0]
    : $pick($rounds, 'unitsAfter') === [0, 0, 0], ['before' => $pick($rounds, 'unitsBefore'), 'after' => $pick($rounds, 'unitsAfter')]);

// C7 — expiry during verification (simulated by expiring this study's own keys on its own instance).
if ($cand) {
    $ns = 'oaaa:C7';
    $ip = '10.97.1.1';
    $credited($ns, $ip, 'C7', 80);
    $seq($ns, [[$ip, 'c7a', 'C7a', 80, 'check']]);                      // tuple A holds one reservation (unit 1)
    $rt = new RedisFullCapabilityStore($ex, $ns);
    $tupleA = hash_hmac('sha256', 'login_protection:rate_limiter:oaaa:tuple:v1:prod:' . $lab->keys(new RateLimitContextDTO($ip, k1Ua(80), 'C7a', fp('c7a')))['K4'] . ':' . $lab->resolver->resolve(new RateLimitContextDTO($ip, k1Ua(80), 'C7a', fp('c7a')))->fingerprintHash . ':' . $lab->keys(new RateLimitContextDTO($ip, k1Ua(80), 'x'))['K1'] . ':' . $lab->keys(new RateLimitContextDTO($ip, k1Ua(80), 'x'))['K2'], Lab::SECRET);
    $tupleExisted = (int) $ex->execute(['EXISTS', $physical($ns, $tupleA)]);
    $ex->execute(['PEXPIRE', $physical($ns, $tupleA), '1']);
    usleep(20000);
    $late = $seq($ns, [[$ip, 'c7a', 'C7a', 80, 'ok']]);
    $afterLate = $units($ns, $tier($ip, 80));
    // epoch rollover: tuple B reserves, the tier expires and is recreated by tuple C, then B's success arrives
    $seq($ns, [[$ip, 'c7b', 'C7b', 80, 'check']]);
    $ex->execute(['PEXPIRE', $physical($ns, $tier($ip, 80)), '1']);
    usleep(20000);
    $seq($ns, [[$ip, 'c7c', 'C7c', 80, 'check']]);
    $newEpochBefore = $units($ns, $tier($ip, 80));
    $seq($ns, [[$ip, 'c7b', 'C7b', 80, 'ok']]);
    $c7 = ['tupleExisted' => $tupleExisted === 1, 'lateSuccess' => $late, 'unitsAfterLateSuccess' => $afterLate, 'newEpochUnitsBefore' => $newEpochBefore, 'newEpochUnitsAfterOldSuccess' => $units($ns, $tier($ip, 80))];
    emit('A06.C7.expiry-during-verification', ['arm' => $arm] + $c7);
    OaaaAsserts::check('A06', 'C7 a success arriving after its tuple expired returns nothing (1 unit stays), and a success for a reservation of an expired epoch is never credited into the new epoch (stays 1)', $c7['tupleExisted'] && $afterLate === 1 && $newEpochBefore === 1 && $c7['newEpochUnitsAfterOldSuccess'] === 1, $c7);
}

// C8 — rotation with active reservations.
$rounds = [];
for ($r = 1; $r <= 3; $r++) {
    $ns = "oaaa:C8:$r";
    $ip = "10.98.$r.1";
    $c = [];
    for ($i = 0; $i < 5; $i++) {
        $c = array_merge($c, $a($ip, null, "C8r{$r}s$i", 90, 'ok'));
    }
    $setup = $seq($ns, $c, 'old-secret');
    $setup .= $seq($ns, [[$ip, 'c8a', "C8r{$r}a", 90, 'check'], [$ip, 'c8b', "C8r{$r}b", 90, 'check']], 'old-secret');   // 2 unresolved, old generation
    [$out, $counter] = $burst($ns, array_map(static fn(int $w): array => [[$ip, null, "C8r{$r}w$w", 90, 'check']], range(0, 15)), 'new-secret', 'old-secret');
    $oldSuccess = $seq($ns, [[$ip, 'c8a', "C8r{$r}a", 90, 'ok']], 'new-secret', 'old-secret');
    $oldTier = 'oaaa:v1:e:spray:credential_spray:' . hash_hmac('sha256', "login_protection:rate_limiter:k1:v2:prod:$ip", 'old-secret');
    $rounds[] = ['setup' => $setup, 'admittedAfterRotation' => $admitted($out), 'counter' => $counter, 'errors' => $errors($out), 'oldGenerationUnits' => $units($ns, $oldTier), 'oldSuccess' => $oldSuccess];
}
emit('A06.C8.rotation-active-reservations', ['arm' => $arm, 'workersPerRound' => 16, 'rounds' => $rounds]);
OaaaAsserts::check('A06', 'C8 negative control: every round\'s completion counter reads 16, no worker error', $pick($rounds, 'counter') === [16, 16, 16] && array_sum($pick($rounds, 'errors')) === 0, ['counters' => $pick($rounds, 'counter')]);
OaaaAsserts::check('A06', 'C8 ' . ($cand ? 'two unresolved reservations taken under the old secret count after rotation: none of 16 simultaneous pre-checks under the new secret is admitted, and a success of an old reservation leaves the old generation at 2 units (never written, never reset)' : 'S0: none admitted'), $cand
    ? $pick($rounds, 'admittedAfterRotation') === [0, 0, 0] && $pick($rounds, 'oldGenerationUnits') === [2, 2, 2]
    : $pick($rounds, 'admittedAfterRotation') === [0, 0, 0], ['admitted' => $pick($rounds, 'admittedAfterRotation'), 'oldUnits' => $pick($rounds, 'oldGenerationUnits')]);

// T1
$keys = array_map('strval', (array) $ex->execute(['KEYS', '*']));
$noTtl = 0;
$families = [];
foreach ($keys as $k) {
    if ((int) $ex->execute(['TTL', $k]) === -1) {   // -1 = no expiry; -2 = expired between KEYS and TTL (a 60 s block of an early case)
        $noTtl++;
        $families[] = preg_replace('/[0-9a-f]{64}/', '<h>', $k);
    }
}
sort($families);
emit('A06.T1.ttl-hygiene', ['arm' => $arm, 'keysPresent' => count($keys) > 0, 'keysWithoutTtl' => $noTtl, 'withoutTtl' => $families]);
OaaaAsserts::check('A06', 'T1 every Redis key written by the concurrent bursts has a TTL', $noTtl === 0 && count($keys) > 0, ['noTtl' => $noTtl]);
OaaaAsserts::finish();
