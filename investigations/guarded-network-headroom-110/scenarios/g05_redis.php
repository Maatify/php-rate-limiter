<?php

declare(strict_types=1);

// G05 — real Redis (instance owned and proven by run.sh; PR #103 F103-01 safety model), sequential, the official RedisFullCapabilityStore.
//   P1  decision parity Redis vs reference store on the two budget sequences of G02 (K1 spray zone, K2 churn zone), checkOnly only
//   P2  TTL hygiene: every key written has a TTL; the GNH5 budget flags carry TTL <= 600 s
//   P3  rollback on Redis: this arm writes one K2 scope with 8 fingerprints, then the REAL unpatched S0 code (child process) evaluates it
//   P4  mixed version on Redis: this arm and the REAL S0 code alternate per request on one K2 scope
require dirname(__DIR__, 2) . '/workstream-b-anti-evasion/lib/redis.php';
require dirname(__DIR__) . '/lib/gnh.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

emit('G05.provenance', gnhProvenance());
$arm = gnhArm();
$cand = $arm !== 'S0';
$ex = wsbRedisConnectVerified(true);

$flag = static fn(string $step): string => str_starts_with($step, 'check:A') ? 'a' : 'B';
/** @param list<array{0: string, 1: ?string, 2: string, 3: int}> $reqs */
$onRedis = static function (string $ns, array $reqs) use ($ex, $flag): array {
    $rt = wsbRedisPipeline($ex, $ns);
    $out = [];
    foreach ($reqs as [$ip, $fp, $acct, $ua]) {
        $out[] = $flag(wsbRedisRun($rt, new RateLimitContextDTO($ip, k1Ua($ua), $acct, $fp === null ? null : fp($fp)), 'check'));
    }

    return $out;
};
/** @param list<array{0: string, 1: ?string, 2: string, 3: int}> $reqs */
$onReference = static function (array $reqs): array {
    $ev = array_map(static fn(array $r): array => k1Ev(0, $r[0], $r[1], $r[2], 'fail', 'attack', ['uaRaw' => k1Ua($r[3])]), $reqs);

    return array_map(static fn(array $r): string => $r['blocked'] ? 'B' : 'a', gnhReplay(gnhLab(), $ev, 'pre', 'login', false));
};
$k1Seq = [];
for ($i = 0; $i < 5; $i++) {
    $k1Seq[] = ['10.80.1.1', null, "p1s$i", 111];
}
for ($i = 0; $i < 30; $i++) {
    $k1Seq[] = ['10.80.1.1', null, 'p1s0', 111];
}
$k2Seq = [];
for ($i = 0; $i < 3; $i++) {
    $k2Seq[] = ['10.80.2.1', "p1f$i", "p1a$i", 112];
}
for ($i = 0; $i < 20; $i++) {
    $k2Seq[] = ['10.80.2.1', 'p1f0', 'p1a0', 112];
}
$p1 = [];
foreach (['k1-spray-zone' => $k1Seq, 'k2-churn-zone' => $k2Seq] as $name => $seq) {
    $r = $onRedis("gnh:p1:$name", $seq);
    $f = $onReference($seq);
    $p1[$name] = ['redis' => implode('', $r), 'reference' => implode('', $f), 'allowedRedis' => count(array_keys($r, 'a', true))];
}
emit('G05.P1.parity', ['arm' => $arm, 'sequences' => $p1]);
GnhAsserts::check('G05', 'P1 sequential decision parity: the K1 and K2 zone sequences give identical decisions on the official Redis store and on the reference store', $p1['k1-spray-zone']['redis'] === $p1['k1-spray-zone']['reference'] && $p1['k2-churn-zone']['redis'] === $p1['k2-churn-zone']['reference'], $p1);
GnhAsserts::check('G05', 'P1b ' . match ($arm) {
    'S0' => 'S0 on Redis: 4 of 35 (K1) and 2 of 23 (K2) checkOnly requests allowed',
    'TH5' => 'TH5 on Redis: every request allowed (35 / 23): the zone is free',
    'GNH5' => 'GNH5 on Redis: exactly 4 + B=20 (K1) and 2 + B=12 (K2) allowed',
}, match ($arm) {
    'S0' => $p1['k1-spray-zone']['allowedRedis'] === 4 && $p1['k2-churn-zone']['allowedRedis'] === 2,
    'TH5' => $p1['k1-spray-zone']['allowedRedis'] === 35 && $p1['k2-churn-zone']['allowedRedis'] === 23,
    'GNH5' => $p1['k1-spray-zone']['allowedRedis'] === 24 && $p1['k2-churn-zone']['allowedRedis'] === 14,
}, ['k1' => $p1['k1-spray-zone']['allowedRedis'], 'k2' => $p1['k2-churn-zone']['allowedRedis']]);

// P3 / P4 need the REAL S0 code in another process on the same Redis namespace.
$repo = dirname(__DIR__, 3);
$s0Child = static function (string $ns, array $reqs) use ($repo): array {
    $env = ['PATH' => getenv('PATH'), 'LAB_ROOT' => $repo, 'WSB_REDIS_PORT' => getenv('WSB_REDIS_PORT'), 'WSB_REDIS_PID' => getenv('WSB_REDIS_PID'), 'WSB_REDIS_DIR' => getenv('WSB_REDIS_DIR')];
    $p = proc_open([PHP_BINARY, __DIR__ . '/g05_child.php', $ns, json_encode($reqs, JSON_THROW_ON_ERROR)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $so = trim((string) stream_get_contents($pipes[1]));
    $se = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($p);
    $out = json_decode($so, true);
    if ($rc !== 0 || $se !== '' || ! is_array($out) || ($out['arm'] ?? '') !== 'S0') {
        fwrite(STDERR, "g05 S0 child failed (rc $rc): $so $se\n");
        exit(2);
    }

    return $out;
};
if ($cand) {
    // P3 — this arm writes 8 fingerprints (own accounts) on one IP+UA; then the S0 code evaluates a 9th.
    $reqs = [];
    for ($i = 0; $i < 8; $i++) {
        $reqs[] = ['10.80.3.1', "p3f$i", "p3a$i", 113];
    }
    $mine = $onRedis('gnh:p3', $reqs);
    $s0 = $s0Child('gnh:p3', [['10.80.3.1', 'p3f8', 'p3a8', 113]]);
    $p3 = ['thisArm' => implode('', $mine), 's0Steps' => $s0['steps'], 's0Error' => $s0['error']];
    emit('G05.P3.rollback-redis', ['arm' => $arm] + $p3);
    GnhAsserts::check('G05', 'P3 rollback on Redis: the REAL unpatched S0 code evaluates the K2 scope written by this arm without error', $s0['error'] === null, $p3);
    // P4 — alternate: even requests this arm (in process), odd requests the S0 code (child), 10 fresh fingerprints on one IP+UA.
    $steps = [];
    $error = null;
    for ($i = 0; $i < 10; $i++) {
        $req = ['10.80.4.1', "p4f$i", "p4a$i", 114];
        if ($i % 2 === 0) {
            $steps[] = 'cand:' . $onRedis('gnh:p4', [$req])[0];
        } else {
            $o = $s0Child('gnh:p4', [$req]);
            if ($o['error'] !== null) {
                $error = $o['error'];
                $steps[] = 'S0:ERROR';
                break;
            }
            $steps[] = 'S0:' . $flag($o['steps'][0]);
        }
    }
    emit('G05.P4.mixed-version-redis', ['arm' => $arm, 'steps' => $steps, 'error' => $error]);
    GnhAsserts::check('G05', 'P4 mixed version on Redis: this arm and the REAL S0 code alternating on one K2 scope never error', $error === null, ['steps' => $steps, 'error' => $error]);
} else {
    emit('G05.P.reference', ['arm' => $arm, 'note' => 'rollback / mixed version on Redis are measured on the candidate arms (S0 code is the rollback target)']);
    GnhAsserts::check('G05', 'P3 S0 reference: not applicable on S0 (the rollback target itself)', true);
    GnhAsserts::check('G05', 'P4 S0 reference: not applicable on S0 (the old version itself)', true);
}

// P2 — TTL hygiene of everything written above. The official store hashes logical keys, so the two P1 budget flags are located by their
// derived physical names (logical key "gnh5:budget:<rule>:<rule scope key>").
$keys = array_map('strval', (array) $ex->execute(['KEYS', '*']));
$noTtl = 0;
foreach ($keys as $k) {
    if ((int) $ex->execute(['TTL', $k]) < 0) {
        $noTtl++;
    }
}
$prefix = (new \ReflectionClassConstant(\Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore::class, 'PREFIX'))->getValue();
$physical = static fn(string $ns, string $logical): string => $prefix . ':' . hash('sha256', $ns) . ':watch:' . hash('sha256', $logical);
$lab = gnhLab();
$k1 = $lab->keys($lab->ctx('10.80.1.1', k1Ua(111), 'p1s0'))['K1'];
$k2 = $lab->keys($lab->ctx('10.80.2.1', k1Ua(112), 'p1a0', fp('p1f0')))['K2'];
$budget = [
    'spray' => $physical('gnh:p1:k1-spray-zone', 'gnh5:budget:spray:credential_spray:' . $k1),
    'churn' => $physical('gnh:p1:k2-churn-zone', 'gnh5:budget:churn:' . hash_hmac('sha256', 'login_protection:rate_limiter:correlation:churn:v1:prod:scope:' . $k2, Lab::SECRET)),
];
$budgetState = [];
foreach ($budget as $rule => $key) {
    $ttl = (int) $ex->execute(['TTL', $key]);
    $budgetState[$rule] = ['present' => $ttl !== -2, 'ttlAtMost600' => $ttl > 0 && $ttl <= 600, 'value' => $ttl === -2 ? null : (int) $ex->execute(['GET', $key])];
}
emit('G05.P2.ttl-hygiene', ['arm' => $arm, 'keys' => count($keys) > 0, 'keysWithoutTtl' => $noTtl, 'budgetFlags' => $budgetState]);
GnhAsserts::check('G05', 'P2 every Redis key written on this arm has a TTL; ' . ($arm === 'GNH5' ? 'the two P1 budget flags exist with TTL <= 600 s and values 21 and 13: B admissions plus the one refused command that persists the block (later requests are refused by the persisted block before reaching the budget)' : 'no budget flag exists on this arm'), $noTtl === 0 && count($keys) > 0 && ($arm === 'GNH5'
    ? $budgetState['spray'] === ['present' => true, 'ttlAtMost600' => true, 'value' => 21] && $budgetState['churn'] === ['present' => true, 'ttlAtMost600' => true, 'value' => 13]
    : ! $budgetState['spray']['present'] && ! $budgetState['churn']['present']), ['noTtl' => $noTtl, 'budgetFlags' => $budgetState]);
GnhAsserts::finish();
