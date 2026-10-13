<?php

declare(strict_types=1);

// K07 — real Redis (instance owned and proven by run.sh; PR #103 F103-01 safety model). Redis owns time (DEC-016): exact 600/1800 s boundaries
// are proved on the controlled clock in K03; here: parity of the decisions, TTL hygiene, atomic cap under CONCURRENCY and rotation.
//   R1 sequential parity with the reference store       R2 fixed TTL / bounded set on real keys
//   R3 CONCURRENCY distinct subjects (cap race)          R4 CONCURRENCY one subject (duplicate race)
//   R5 Current/Previous rotation on the real adapter
require dirname(__DIR__, 2) . '/workstream-b-anti-evasion/lib/redis.php';
require dirname(__DIR__) . '/lib/k1.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\RateLimiter\Service\EvaluationPipeline;

k1Emit('K07.provenance', k1Provenance());
$ex = wsbRedisConnectVerified(true);

/**
 * Inventory of the REAL Redis state. The adapter hashes every logical key (`<prefix>:sha256(namespace):<family>:sha256(logical)`), so the spray
 * set of an IP is located by its logical name `credential_spray:<K1>`, derived exactly as the pipeline derives K1 (Lab::keys()).
 *
 * @param list<array{0: string, 1: string, 2?: string}> $pairs [namespace, ip, secret?]
 * @return array{sprayKeys: int, sprayMaxCard: int, sprayMinCard: int, sprayTtlWithin1To600: bool, keysWithoutTtl: int}
 */
$inventory = static function (RespRedisCommandExecutor $ex, array $pairs = []): array {
    $keys = array_map('strval', (array) $ex->execute(['KEYS', '*']));
    $o = ['sprayKeys' => 0, 'sprayMaxCard' => 0, 'sprayMinCard' => PHP_INT_MAX, 'sprayTtlWithin1To600' => true, 'keysWithoutTtl' => 0];
    foreach ($keys as $k) {
        if ((int) $ex->execute(['TTL', $k]) < 0) {
            $o['keysWithoutTtl']++;
        }
    }
    foreach ($pairs as $pr) {
        $lab = new Lab('login', null, false, $pr[2] ?? Lab::SECRET);
        $k1 = $lab->keys(new RateLimitContextDTO($pr[1], CHROME, 'x', fp('x')))['K1'];
        $hits = array_map('strval', (array) $ex->execute(['KEYS', '*:' . hash('sha256', $pr[0]) . ':distinct:' . hash('sha256', 'credential_spray:' . $k1)]));
        foreach ($hits as $k) {
            $ttl = (int) $ex->execute(['TTL', $k]);
            $card = (int) $ex->execute(['SCARD', $k]);
            $o['sprayKeys']++;
            $o['sprayMaxCard'] = max($o['sprayMaxCard'], $card);
            $o['sprayMinCard'] = min($o['sprayMinCard'], $card);
            $o['sprayTtlWithin1To600'] = $o['sprayTtlWithin1To600'] && $ttl >= 1 && $ttl <= 600; // raw TTLs depend on wall time and are NOT recorded
        }
    }
    if ($o['sprayKeys'] === 0) {
        $o['sprayMinCard'] = 0;
        $o['sprayTtlWithin1To600'] = false;
    }

    return $o;
};
$ctx = static fn(string $ip, int $u, ?string $acct = null, array $x = []): RateLimitContextDTO => new RateLimitContextDTO($ip, k1Ua($u), $acct ?? "s$u", fp("fp$u"), $x['sess'] ?? null, (bool) ($x['trusted'] ?? false));

// R1 — sequential parity: 6 distinct subjects (5th denied, 6th persisted), then 4 subjects + a repeat (WATCH), then a trusted 5th.
$rt = wsbRedisPipeline($ex, 'k1:r1');
$steps = [];
for ($u = 1; $u <= 6; $u++) {
    $steps[] = wsbRedisRun($rt, $ctx('10.50.0.1', $u), 'check');
}
$ref = [];
$lab = k1Lab('A');
foreach (k1Replay($lab, array_map(static fn(int $u): array => k1Ev($u - 1, '10.50.0.1', "fp$u", "s$u", 'ok', 'legit', ['uaRaw' => k1Ua($u)]), range(1, 6)), 'pre', 'login') as $r) {
    $ref[] = $r['blocked'] ? 'H2' : 'A';
}
$norm = static fn(array $s): array => array_map(static fn(string $x): string => str_starts_with($x, 'check:H2') ? 'H2' : 'A', $s);
emit('K07.R1.sequential-parity', ['redis' => $norm($steps), 'reference' => $ref]);
WsbAsserts::check('K07', 'R1 five-of-five parity: the real Redis adapter and the reference store give the same decisions for 6 sequential distinct subjects', $norm($steps) === $ref && $ref === ['A', 'A', 'A', 'A', 'H2', 'H2'], ['redis' => $norm($steps), 'reference' => $ref]);

$rt = wsbRedisPipeline($ex, 'k1:r1b');
$steps = [];
foreach ([1, 2, 3, 4, 2] as $u) {
    $steps[] = wsbRedisRun($rt, $ctx('10.50.0.2', $u), 'check');
}
emit('K07.R1b.watch-parity', ['redis' => $norm($steps)]);
WsbAsserts::check('K07', 'R1b WATCH parity: 4 distinct subjects + a repeat is denied on real Redis too (the 4/5 WATCH counts requests)', $norm($steps) === ['A', 'A', 'A', 'A', 'H2'], ['redis' => $norm($steps)]);

$rt = wsbRedisPipeline($ex, 'k1:r1c');
foreach ([1, 2, 3, 4] as $u) {
    wsbRedisRun($rt, $ctx('10.50.0.3', $u), 'check');
}
$trusted = wsbRedisRun($rt, $ctx('10.50.0.3', 5, null, ['sess' => 'sess-5', 'trusted' => true]), 'check');
$untrusted = wsbRedisRun($rt, $ctx('10.50.0.3', 6), 'check');
emit('K07.R1c.trusted-advisory', ['trusted5th' => $trusted, 'untrustedNext' => $untrusted]);
WsbAsserts::check('K07', 'R1c trusted advisory on real Redis: the trusted 5th subject is allowed, the K1 block it triggered denies the next untrusted subject', str_starts_with($trusted, 'check:A') && str_starts_with($untrusted, 'check:H2'), ['trusted' => $trusted, 'untrusted' => $untrusted]);

// R2 — TTL hygiene on the real keys written so far.
$inv = $inventory($ex, [['k1:r1', '10.50.0.1'], ['k1:r1b', '10.50.0.2'], ['k1:r1c', '10.50.0.3']]);
emit('K07.R2.ttl-hygiene', $inv);
WsbAsserts::check('K07', 'R2 the three spray sets exist on Redis (cardinality 4..5), every Redis key has a TTL, no spray set exceeds the fixed 600 s window or 5 members', $inv['sprayKeys'] === 3 && $inv['keysWithoutTtl'] === 0 && $inv['sprayTtlWithin1To600'] && $inv['sprayMaxCard'] === 5 && $inv['sprayMinCard'] === 4, $inv);

// Concurrency helpers.
$worker = __DIR__ . '/k07_worker.php';
$spawnAll = static function (string $ns, array $jobs, int $startAt) use ($worker): array {
    $procs = [];
    foreach ($jobs as [$acct, $ip, $ua]) {
        $env = ['WSB_REDIS_PORT' => getenv('WSB_REDIS_PORT'), 'WSB_REDIS_PID' => getenv('WSB_REDIS_PID'), 'WSB_REDIS_DIR' => getenv('WSB_REDIS_DIR'), 'PATH' => getenv('PATH')];
        $p = proc_open([PHP_BINARY, $worker, $ns, $acct, $ip, (string) $ua, (string) $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $procs[] = [$p, $pipes];
    }
    $out = [];
    foreach ($procs as [$p, $pipes]) {
        $out[] = trim((string) stream_get_contents($pipes[1])) . trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
    }

    return $out;
};
$completed = static fn(array $out): int => count(array_filter($out, static fn(string $o): bool => str_starts_with($o, 'check:')));
$allowedOf = static fn(array $out): int => count(array_filter($out, static fn(string $o): bool => str_starts_with($o, 'check:A')));

// R3 — distinct-subject race: 16 workers with 16 DIFFERENT subjects (own browser major each) on one IP at one instant, 8 rounds. The set cap must hold
// (<=5 members) and EXACTLY 4 requests may be allowed per round (members 1..4); the 5th+ is denied. A lost update would allow more.
$ok = 0;
$allowedPerRound = [];
for ($round = 1; $round <= 8; $round++) {
    $out = $spawnAll('k1:r3', array_map(static fn(int $w): array => ["r{$round}s{$w}", "10.60.$round.1", 200 + $w], range(0, 15)), (int) $ex->execute(['TIME'])[0] + 2);
    $ok += $completed($out);
    $allowedPerRound[] = $allowedOf($out);
}
$inv = $inventory($ex, array_map(static fn(int $r): array => ['k1:r3', "10.60.$r.1"], range(1, 8)));
emit('K07.R3.distinct-race', ['rounds' => 8, 'workers' => 128, 'workersCompleted' => $ok, 'allowedPerRound' => $allowedPerRound] + $inv);
WsbAsserts::check('K07', 'R3 negative control: all 128 concurrent workers completed', $ok === 128, ['completed' => $ok]);
WsbAsserts::check('K07', 'R3 atomic cap under concurrency: in every round exactly 4 of 16 simultaneous distinct subjects are allowed (never 5+), each of the 8 spray sets holds exactly 5 members with a TTL <= 600', $allowedPerRound === array_fill(0, 8, 4) && $inv['sprayKeys'] === 8 && $inv['sprayMaxCard'] === 5 && $inv['sprayMinCard'] === 5 && $inv['sprayTtlWithin1To600'], ['allowedPerRound' => $allowedPerRound, 'maxCard' => $inv['sprayMaxCard']]);

// R4 — duplicate race: 12 workers with the SAME subject (same account, own UA each is not needed: same UA, same fp) at one instant, 6 rounds.
$ok = 0;
$allowedPerRound = [];
for ($round = 1; $round <= 6; $round++) {
    $out = $spawnAll('k1:r4', array_map(static fn(int $w): array => ['same', "10.70.$round.1", 300], range(0, 11)), (int) $ex->execute(['TIME'])[0] + 2);
    $ok += $completed($out);
    $allowedPerRound[] = $allowedOf($out);
}
emit('K07.R4.duplicate-race', ['rounds' => 6, 'workers' => 72, 'workersCompleted' => $ok, 'allowedPerRound' => $allowedPerRound]);
WsbAsserts::check('K07', 'R4 negative control: all 72 concurrent workers completed', $ok === 72, ['completed' => $ok]);
WsbAsserts::check('K07', 'R4 one subject is one member: 12 simultaneous requests of the SAME subject are all allowed (no inflated distinct count)', $allowedPerRound === array_fill(0, 6, 12), ['allowedPerRound' => $allowedPerRound]);

// R5 — rotation on the real adapter: 3 subjects under the previous secret, 2 under the new one (previous = old): the 5th distinct subject is denied.
$clock = wsbRedisClock($ex);
$store = new RedisFullCapabilityStore($ex, 'k1:r5');
$mk = static fn(string $secret, ?string $prev): EvaluationPipeline => new EvaluationPipeline($store, $store, new \Maatify\RateLimiter\Service\BudgetTracker($store, $clock), new \Maatify\RateLimiter\Service\AntiEquilibriumGate($store), new \Maatify\RateLimiter\Service\DecayCalculator($clock), new \Maatify\RateLimiter\Service\EphemeralBucket($store), $secret, 'prod', $clock, $prev);
$resolver = static fn(string $secret, ?string $prev): \Maatify\RateLimiter\Service\DeviceIdentityResolver => new \Maatify\RateLimiter\Service\DeviceIdentityResolver(new \Maatify\RateLimiter\Service\FingerprintHasher($secret), $prev === null ? null : new \Maatify\RateLimiter\Service\FingerprintHasher($prev));
$policy = new \Maatify\RateLimiter\Config\LoginProtectionPolicy();
$run = static function (EvaluationPipeline $p, \Maatify\RateLimiter\Service\DeviceIdentityResolver $r, RateLimitContextDTO $c) use ($policy): string {
    $res = $p->process($policy, $c, \Maatify\RateLimiter\Command\RateLimitCommand::checkOnly($policy->getName()), $r->resolve($c));

    return $res->decision === 'ALLOW' ? 'A' : 'H' . $res->blockLevel;
};
$old = $mk('old-secret', null);
$oldR = $resolver('old-secret', null);
$new = $mk('new-secret', 'old-secret');
$newR = $resolver('new-secret', 'old-secret');
$rot = [];
foreach ([1, 2, 3] as $u) {
    $rot[] = $run($old, $oldR, $ctx('10.80.0.1', $u));
}
foreach ([4, 5] as $u) {
    $rot[] = $run($new, $newR, $ctx('10.80.0.1', $u));
}
$rotRepeat = [];
foreach ([1, 2, 3, 4] as $u) {
    $rotRepeat[] = $run($old, $oldR, $ctx('10.80.0.2', $u));
}
$rotRepeat[] = $run($new, $newR, $ctx('10.80.0.2', 2));
emit('K07.R5.rotation', ['splitThreeTwo' => $rot, 'fourThenRepeatAfterRotation' => $rotRepeat]);
WsbAsserts::check('K07', 'R5 rotation on real Redis: 3 subjects under the previous secret + 2 under the new one deny the 5th distinct subject (previous set read, bridge written)', $rot === ['A', 'A', 'A', 'A', 'H2'], ['steps' => $rot]);
WsbAsserts::check('K07', 'R5 a subject already in the previous set is not double counted across rotation: 4 + one repeat gives the same WATCH denial as without rotation', $rotRepeat === ['A', 'A', 'A', 'A', 'H2'], ['steps' => $rotRepeat]);
$inv = $inventory($ex, [['k1:r5', '10.80.0.1', 'new-secret'], ['k1:r5', '10.80.0.2', 'new-secret']]);
emit('K07.R5.final-inventory', $inv);
WsbAsserts::check('K07', 'R5 after rotation every Redis key still has a TTL; the current-generation spray sets exist (bridge cardinality <= 5) with TTL <= 600', $inv['keysWithoutTtl'] === 0 && $inv['sprayKeys'] === 2 && $inv['sprayTtlWithin1To600'] && $inv['sprayMaxCard'] <= 5, $inv);
WsbAsserts::finish();
