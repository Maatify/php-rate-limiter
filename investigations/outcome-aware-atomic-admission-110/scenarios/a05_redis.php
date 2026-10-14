<?php

declare(strict_types=1);

// A05 — real Redis (instance owned and proven by run.sh; PR #103 F103-01 safety model), sequential, the official RedisFullCapabilityStore
// (on OAAA: its isolated patched copy with the two Lua scripts).
//   Q1  decision AND unit parity, Redis vs reference store, on five lifecycle sequences (credit + spend, success returns, same-tuple refund,
//       all-or-nothing, pre+out pairing)
//   Q2  key hygiene: every key has a TTL; every OAAA key ("oaaa" family) has TTL <= 86 400 s; every OAAA credit set <= 2 members
//   Q3  malformed OAAA state is an explicit error (never a backend failure, never a silent zero) and leaves no partial write
//   Q4  rollback on Redis: OAAA writes a spent, credited scope; the REAL unpatched S0 code (child process) continues on it
//   Q5  mixed version on Redis: OAAA (in process) and the REAL S0 code (child) alternate per request on one scope
//   --- added after Lead review F116 (not pre-registered; gates X1 / X2 / X3 in report.php) ---
//   Q6  F116-02 wrong-type / malformed keys injected (owned, TTL-bearing): tuple key as a STRING on reserve, credit key as a STRING on a
//       success completion, tier key as a SET on reserve, a non-integer pending field in the tuple on reserve — before / error / after of
//       every involved key; no partial write allowed
//   Q7  F116-01 replay / reorder sequences (L24, L25, L26) on real Redis, units read from Redis, parity with the reference store
//   Q8  F116-03 mixed flows on one shared Redis scope: pre+out office, then a pre-only route
require dirname(__DIR__, 2) . '/workstream-b-anti-evasion/lib/redis.php';
require dirname(__DIR__) . '/lib/oaaa.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

emit('A05.provenance', oaaaProvenance());
$arm = oaaaArm();
$cand = $arm === 'OAAA';
$ex = wsbRedisConnectVerified(true);
$prefix = (new \ReflectionClassConstant(\Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore::class, 'PREFIX'))->getValue();
$physical = static fn(string $ns, string $logical): string => $prefix . ':' . hash('sha256', $ns) . ':oaaa:' . hash('sha256', $logical);

/** One sequence on Redis: list of [ip, fp|null, acct, uaIdx, cmd]; pre+out pairs are written as two entries. */
$onRedis = static function (string $ns, array $seq) use ($ex): string {
    $rt = wsbRedisPipeline($ex, $ns);
    $out = '';
    foreach ($seq as [$ip, $fp, $acct, $ua, $cmd]) {
        $out .= str_starts_with(substr(wsbRedisRun($rt, new RateLimitContextDTO($ip, k1Ua($ua), $acct, $fp === null ? null : fp($fp)), $cmd), strlen($cmd) + 1), 'A') ? 'a' : 'B';
    }

    return $out;
};
/** The same sequence on the reference stores (controlled clock, every command at t=0). @return array{0: string, 1: Lab} */
$onReference = static function (array $seq): array {
    $lab = oaaaLab();
    $out = '';
    foreach ($seq as [$ip, $fp, $acct, $ua, $cmd]) {
        $out .= $lab->run(new RateLimitContextDTO($ip, k1Ua($ua), $acct, $fp === null ? null : fp($fp)), $cmd)['d'] === 'A' ? 'a' : 'B';
    }

    return [$out, $lab];
};
/** Host-realistic attempt expansion: [ip, fp, acct, ua, outcome] -> check + outcome (the outcome is skipped by the runner only if denied:
 *  sequences here are built so that every compared command is executed on both stores, denials included). */
$att = static fn(string $ip, ?string $fp, string $acct, int $ua, string $outcome): array => [[$ip, $fp, $acct, $ua, 'check'], [$ip, $fp, $acct, $ua, $outcome]];
$sat = static function (string $ip, string $tag, int $ua) use ($att): array {
    $s = [];
    for ($i = 0; $i < 5; $i++) {
        $s = array_merge($s, $att($ip, null, "{$tag}s$i", $ua, 'ok'));
    }

    return $s;
};
$sequences = [
    'credit-and-spend' => array_merge($att('10.81.1.1', null, 'q1own0', 30, 'ok'), $att('10.81.1.1', null, 'q1v0', 30, 'fail'), $att('10.81.1.1', null, 'q1v1', 30, 'fail'), $att('10.81.1.1', null, 'q1v2', 30, 'fail'), $att('10.81.1.1', null, 'q1v3', 30, 'fail'), [['10.81.1.1', null, 'q1v4', 30, 'check'], ['10.81.1.1', null, 'q1v5', 30, 'check']]),
    'success-returns' => array_merge($sat('10.81.2.1', 'q2', 31), ...array_map(static fn(int $i): array => $att('10.81.2.1', null, 'q2s' . ($i % 2), 31, 'ok'), range(0, 19))),
    'same-tuple-refund' => array_merge($sat('10.81.3.1', 'q3', 32), $att('10.81.3.1', 'q3fp', 'q3col', 33, 'fail'), $att('10.81.3.1', 'q3fp', 'q3col', 33, 'ok'), $att('10.81.3.1', null, 'q3low', 32, 'fail'), $att('10.81.3.1', null, 'q3low', 32, 'ok')),
    'all-or-nothing' => array_merge([['10.81.4.1', 'fy0', 'q4y0', 41, 'check'], ['10.81.4.1', 'fy1', 'q4y1', 41, 'check']], $att('10.81.4.1', 'fx0', 'q4s0', 40, 'ok'), $att('10.81.4.1', 'fx1', 'q4s1', 40, 'ok'), $att('10.81.4.1', 'fx2', 'q4s2', 40, 'ok'), $att('10.81.4.1', 'fy2', 'q4y2', 41, 'fail')),
    'pairing-churn' => array_merge($att('10.81.5.1', 'ca', 'q5a', 50, 'ok'), $att('10.81.5.1', 'cb', 'q5b', 50, 'ok'), $att('10.81.5.1', 'cc', 'q5c', 50, 'fail')),
];
$tiersOf = static function (Lab $lab, string $ip, int $ua): array {
    $keys = $lab->keys(new RateLimitContextDTO($ip, k1Ua($ua), 'x'));

    return [
        'spray' => 'oaaa:v1:e:spray:credential_spray:' . $keys['K1'],
        'churn' => 'oaaa:v1:e:churn:' . hash_hmac('sha256', 'login_protection:rate_limiter:correlation:churn:v1:prod:scope:' . $keys['K2'], Lab::SECRET),
        'global' => 'oaaa:v1:g:e:' . hash('sha256', 'login_protection:prod'),
    ];
};
$q1 = [];
foreach ($sequences as $name => $seq) {
    $ns = "oaaa:q1:$name";
    $redis = $onRedis($ns, $seq);
    [$reference, $lab] = $onReference($seq);
    $last = end($seq);
    $units = [];
    foreach ($tiersOf($lab, $last[0], $last[3]) as $tier => $logical) {
        $r = $ex->execute(['HGET', $physical($ns, $logical), 'u']);
        $ref = $lab->corr instanceof OaaaStore ? ($lab->corr->tiers[$logical]['u'] ?? null) : null;
        $units[$tier] = ['redis' => $r === null ? null : (int) $r, 'reference' => $ref];
    }
    $q1[$name] = ['redis' => $redis, 'reference' => $reference, 'units' => $units];
}
emit('A05.Q1.parity', ['arm' => $arm, 'sequences' => $q1]);
$parity = true;
foreach ($q1 as $r) {
    $parity = $parity && $r['redis'] === $r['reference'];
    foreach ($r['units'] as $u) {
        $parity = $parity && $u['redis'] === $u['reference'];
    }
}
OaaaAsserts::check('A05', 'Q1 decision and unit parity: five lifecycle sequences give identical decisions and identical OAAA tier units on the official Redis store (Lua) and on the reference store', $parity, $q1);

// Q3 — malformed OAAA state (candidate only): a tier with negative units, then a tuple whose tier cannot absorb its refund.
if ($cand) {
    $rt = wsbRedisPipeline($ex, 'oaaa:q3');
    $store = $rt['store'];
    $t = 'oaaa:v1:e:spray:q3scope';
    $ex->execute(['HSET', $physical('oaaa:q3', $t), 'e', '1.1', 'u', '-1']);
    $ex->execute(['EXPIRE', $physical('oaaa:q3', $t), '600']);
    $err1 = null;
    try {
        $store->reserveAdmission('q3tuple', 600, [['key' => $t, 'previousKey' => null, 'cap' => 2, 'ttl' => 600, 'creditKeys' => []], ['key' => 'oaaa:v1:g:e:q3', 'previousKey' => null, 'cap' => 12, 'ttl' => 600, 'creditKeys' => []]]);
    } catch (\Throwable $e) {
        $err1 = get_class($e) . ': ' . $e->getMessage();
    }
    $partial1 = (int) $ex->execute(['EXISTS', $physical('oaaa:q3', 'q3tuple')]) + (int) $ex->execute(['EXISTS', $physical('oaaa:q3', 'oaaa:v1:g:e:q3')]);
    // a tuple claiming 2 recorded failures on a tier that holds 1 unit
    $t2 = 'oaaa:v1:e:spray:q3scope2';
    $ex->execute(['HSET', $physical('oaaa:q3', $t2), 'e', '2.2', 'u', '1']);
    $ex->execute(['EXPIRE', $physical('oaaa:q3', $t2), '600']);
    $tuplePhys = $physical('oaaa:q3', 'q3tuple2');
    $ex->execute(['HSET', $tuplePhys, 'e|' . $physical('oaaa:q3', $t2), '2.2', 'p|' . $physical('oaaa:q3', $t2), '0', 'f|' . $physical('oaaa:q3', $t2), '2']);
    $ex->execute(['EXPIRE', $tuplePhys, '600']);
    $err2 = null;
    try {
        $store->completeAdmission('q3tuple2', 'success', [$t2], 2, [], 'm', 2, 1200);
    } catch (\Throwable $e) {
        $err2 = get_class($e) . ': ' . $e->getMessage();
    }
    $after2 = ['tierUnits' => (int) $ex->execute(['HGET', $physical('oaaa:q3', $t2), 'u']), 'tupleFailures' => (int) $ex->execute(['HGET', $tuplePhys, 'f|' . $physical('oaaa:q3', $t2)])];
    $q3 = ['negativeUnits' => preg_replace('/script: \w+/', 'script', (string) $err1), 'partialWritesAfterRefusedReserve' => $partial1, 'refundAboveUnits' => preg_replace('/script: \w+/', 'script', (string) $err2), 'stateAfterRefusedComplete' => $after2];
    emit('A05.Q3.malformed-state', ['arm' => $arm] + $q3);
    OaaaAsserts::check('A05', 'Q3 malformed OAAA state is an explicit RateLimiterException (not a BackendFailureException, not a silent zero): negative units refuse the reservation with no partial write; a refund larger than the tier\'s units is refused before any write (tier 1, tuple failures 2 unchanged)', $err1 !== null && str_starts_with($err1, 'Maatify\\RateLimiter\\Exception\\RateLimiterException') && str_contains($err1, 'malformed admission tier') && $partial1 === 0
        && $err2 !== null && str_starts_with($err2, 'Maatify\\RateLimiter\\Exception\\RateLimiterException') && $after2 === ['tierUnits' => 1, 'tupleFailures' => 2], $q3);
}

// Q4 / Q5 need the REAL S0 code in another process on the same Redis namespace.
$repo = dirname(__DIR__, 3);
$s0Child = static function (string $ns, array $seq) use ($repo): array {
    $env = ['PATH' => getenv('PATH'), 'LAB_ROOT' => $repo, 'WSB_REDIS_PORT' => getenv('WSB_REDIS_PORT'), 'WSB_REDIS_PID' => getenv('WSB_REDIS_PID'), 'WSB_REDIS_DIR' => getenv('WSB_REDIS_DIR')];
    $p = proc_open([PHP_BINARY, __DIR__ . '/a05_child.php', $ns, json_encode($seq, JSON_THROW_ON_ERROR)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $so = trim((string) stream_get_contents($pipes[1]));
    $se = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($p);
    $out = json_decode($so, true);
    if ($rc !== 0 || $se !== '' || ! is_array($out) || ($out['arm'] ?? '') !== 'S0') {
        fwrite(STDERR, "a05 S0 child failed (rc $rc): $so $se\n");
        exit(2);
    }

    return $out;
};
if ($cand) {
    // Q4 — OAAA: a credited K1 scope with 2 units spent by failing attempts, then the S0 code continues with 4 more attempts.
    $seq = array_merge($sat('10.81.6.1', 'q4r', 60), $att('10.81.6.1', null, 'q4v0', 60, 'fail'), $att('10.81.6.1', null, 'q4v1', 60, 'fail'));
    $mine = $onRedis('oaaa:q4', $seq);
    $tail = [];
    foreach (['q4r s0', 'q4v2', 'q4v3', 'q4r s1'] as $a) {
        $tail = array_merge($tail, $att('10.81.6.1', null, str_replace(' ', '', $a), 60, 'ok'));
    }
    $s0 = $s0Child('oaaa:q4', $tail);
    $q4 = ['oaaa' => $mine, 's0Steps' => array_map(static fn(string $s): string => explode(':', $s)[0] . ':' . (str_starts_with(explode(':', $s)[1], 'A') ? 'A' : 'B'), $s0['steps']), 's0Error' => $s0['error']];
    emit('A05.Q4.rollback-redis', ['arm' => $arm] + $q4);
    OaaaAsserts::check('A05', 'Q4 rollback on Redis: the REAL unpatched S0 code evaluates the scope written by OAAA (sets, WATCH flags and the OAAA keys it ignores) without error', $s0['error'] === null, $q4);
    // Q5 — alternate on one scope: even requests OAAA (in process), odd requests S0 (child); 5 credited successes first (OAAA), then 10 failing subjects.
    $onRedis('oaaa:q5', $sat('10.81.7.1', 'q5', 70));
    $steps = [];
    $error = null;
    for ($i = 0; $i < 10; $i++) {
        $pair = $att('10.81.7.1', null, "q5v$i", 70, 'fail');
        if ($i % 2 === 0) {
            $steps[] = 'oaaa:' . $onRedis('oaaa:q5', [$pair[0]]);
        } else {
            $o = $s0Child('oaaa:q5', [$pair[0]]);
            if ($o['error'] !== null) {
                $error = $o['error'];
                break;
            }
            $steps[] = 'S0:' . (str_starts_with($o['steps'][0], 'check:A') ? 'a' : 'B');
        }
    }
    emit('A05.Q5.mixed-version-redis', ['arm' => $arm, 'steps' => $steps, 'error' => $error]);
    OaaaAsserts::check('A05', 'Q5 mixed version on Redis: OAAA and the REAL S0 code alternating on one scope never error; once S0 persists its K1 block, OAAA honours it', $error === null && in_array('S0:B', $steps, true) && end($steps) !== 'oaaa:a', ['steps' => $steps, 'error' => $error]);
} else {
    emit('A05.Q.reference', ['arm' => $arm, 'note' => 'malformed OAAA state, rollback and mixed version on Redis are measured on the candidate arm']);
}

// Q6 — F116-02 malformed / wrong-type keys (candidate only). A snapshot is the TYPE and full content of every involved key.
if ($cand) {
    $snapKey = static function (string $k) use ($ex): array {
        $t = (string) $ex->execute(['TYPE', $k]);
        $v = match ($t) {
            'hash' => (array) $ex->execute(['HGETALL', $k]),
            'set' => (static function (array $m): array {
                sort($m);
                return $m;
            })((array) $ex->execute(['SMEMBERS', $k])),
            'string' => (string) $ex->execute(['GET', $k]),
            default => null,
        };

        return ['type' => $t, 'value' => $v];
    };
    $snap = static function (string $ns, array $logical) use ($snapKey, $physical): array {
        $o = [];
        foreach ($logical as $l) {
            $o[$l] = $snapKey($physical($ns, $l));
        }

        return $o;
    };
    $q6 = [];
    $store = wsbRedisPipeline($ex, 'oaaa:q6')['store'];
    $call = static function (callable $f): ?string {
        try {
            $f();

            return null;
        } catch (\Throwable $e) {
            return get_class($e) . ': ' . preg_replace('/script: \w+/', 'script', $e->getMessage());
        }
    };
    $tier = ['key' => 'oaaa:v1:e:spray:q6s', 'previousKey' => null, 'cap' => 2, 'ttl' => 600, 'creditKeys' => []];
    $glob = ['key' => 'oaaa:v1:g:e:q6', 'previousKey' => null, 'cap' => 12, 'ttl' => 600, 'creditKeys' => []];
    // (a) tuple key is a STRING (TTL-bearing) on reserve
    $ex->execute(['SET', $physical('oaaa:q6', 'q6tupleA'), 'not-a-hash', 'EX', '600']);
    $keys = ['q6tupleA', $tier['key'], $glob['key']];
    $before = $snap('oaaa:q6', $keys);
    $err = $call(static fn() => $store->reserveAdmission('q6tupleA', 600, [$tier, $glob]));
    $q6['reserve-tuple-is-string'] = ['error' => $err, 'unchanged' => $snap('oaaa:q6', $keys) === $before];
    // (b) credit key is a STRING on a success completion whose tuple holds one pending reservation on tier T2 (u=1)
    $t2 = 'oaaa:v1:e:spray:q6s2';
    $store->reserveAdmission('q6tupleB', 600, [['key' => $t2, 'previousKey' => null, 'cap' => 2, 'ttl' => 600, 'creditKeys' => []]]);
    $ex->execute(['SET', $physical('oaaa:q6', 'q6credit'), 'not-a-set', 'EX', '600']);
    $keys = ['q6tupleB', $t2, 'q6credit'];
    $before = $snap('oaaa:q6', $keys);
    $err = $call(static fn() => $store->completeAdmission('q6tupleB', 'success', [$t2], 2, ['q6credit'], 'member', 2, 1200));
    $q6['complete-credit-is-string'] = ['error' => $err, 'unchanged' => $snap('oaaa:q6', $keys) === $before, 'tierUnitsBefore' => (int) ($before[$t2]['value'][array_search('u', $before[$t2]['value'], true) + 1] ?? -1)];
    // (c) tier key is a SET on reserve (the global tier, listed after a valid tier)
    $ex->execute(['SADD', $physical('oaaa:q6', 'oaaa:v1:g:e:q6c'), 'x']);
    $ex->execute(['EXPIRE', $physical('oaaa:q6', 'oaaa:v1:g:e:q6c'), '600']);
    $keys = ['q6tupleC', 'oaaa:v1:e:spray:q6s3', 'oaaa:v1:g:e:q6c'];
    $before = $snap('oaaa:q6', $keys);
    $err = $call(static fn() => $store->reserveAdmission('q6tupleC', 600, [['key' => 'oaaa:v1:e:spray:q6s3', 'previousKey' => null, 'cap' => 2, 'ttl' => 600, 'creditKeys' => []], ['key' => 'oaaa:v1:g:e:q6c', 'previousKey' => null, 'cap' => 12, 'ttl' => 600, 'creditKeys' => []]]));
    $q6['reserve-tier-is-set'] = ['error' => $err, 'unchanged' => $snap('oaaa:q6', $keys) === $before];
    // (d) the tuple's pending field for a tier is not an integer
    $tierPhys = $physical('oaaa:q6', 'oaaa:v1:e:spray:q6s4');
    $ex->execute(['HSET', $physical('oaaa:q6', 'q6tupleD'), 'p|' . $tierPhys, 'abc']);
    $ex->execute(['EXPIRE', $physical('oaaa:q6', 'q6tupleD'), '600']);
    $keys = ['q6tupleD', 'oaaa:v1:e:spray:q6s4'];
    $before = $snap('oaaa:q6', $keys);
    $err = $call(static fn() => $store->reserveAdmission('q6tupleD', 600, [['key' => 'oaaa:v1:e:spray:q6s4', 'previousKey' => null, 'cap' => 2, 'ttl' => 600, 'creditKeys' => []]]));
    $q6['reserve-pending-not-integer'] = ['error' => $err, 'unchanged' => $snap('oaaa:q6', $keys) === $before];
    emit('A05.Q6.wrong-type-no-partial-write', ['arm' => $arm, 'probes' => $q6]);
    $ok = true;
    foreach ($q6 as $r) {
        $ok = $ok && $r['error'] !== null && str_starts_with($r['error'], 'Maatify\\RateLimiter\\Exception\\RateLimiterException') && $r['unchanged'];
    }
    OaaaAsserts::check('A05', 'Q6 F116-02: a wrong-type tuple, credit or tier key and a non-integer tuple field are refused with an explicit RateLimiterException and EVERY involved key is unchanged (type and content) — no partial write', $ok, $q6);
}

// Q7 — F116-01 replay / reorder on real Redis (both arms), units read from Redis; parity with the reference store is checked here.
$q7 = [];
$seqs = [
    'L24 two pending + replayed success' => array_merge($sat('10.81.8.1', 'q7a', 80), [['10.81.8.1', 'q7fp', 'q7col', 81, 'check'], ['10.81.8.1', 'q7fp', 'q7col', 81, 'check'], ['10.81.8.1', 'q7fp', 'q7col', 81, 'ok'], ['10.81.8.1', 'q7fp', 'q7col', 81, 'ok'], ['10.81.8.1', 'q7fp', 'q7col', 81, 'fail']]),
    'L25 replayed success, no new attempt' => array_merge($sat('10.81.9.1', 'q7b', 82), $att('10.81.9.1', 'q7fq', 'q7col2', 83, 'fail'), [['10.81.9.1', 'q7fq', 'q7col2', 83, 'ok'], ['10.81.9.1', 'q7fq', 'q7col2', 83, 'ok']]),
    'L26 MEDIUM success-first' => array_merge($sat('10.81.10.1', 'q7c', 84), [['10.81.10.1', 'q7fr', 'q7col3', 85, 'check'], ['10.81.10.1', 'q7fr', 'q7col3', 85, 'check'], ['10.81.10.1', 'q7fr', 'q7col3', 85, 'ok'], ['10.81.10.1', 'q7fr', 'q7col3', 85, 'fail']]),
    'L26 MEDIUM failure-first' => array_merge($sat('10.81.11.1', 'q7d', 86), [['10.81.11.1', 'q7fs', 'q7col4', 87, 'check'], ['10.81.11.1', 'q7fs', 'q7col4', 87, 'check'], ['10.81.11.1', 'q7fs', 'q7col4', 87, 'fail'], ['10.81.11.1', 'q7fs', 'q7col4', 87, 'ok']]),
];
foreach ($seqs as $name => $seq) {
    $ns = 'oaaa:q7:' . md5($name);
    $redis = $onRedis($ns, $seq);
    [$reference, $lab] = $onReference($seq);
    $last = end($seq);
    $logical = $tiersOf($lab, $last[0], $last[3])['spray'];
    $r = $ex->execute(['HGET', $physical($ns, $logical), 'u']);
    $q7[$name] = ['redis' => $redis, 'reference' => $reference, 'unitsRedis' => $r === null ? null : (int) $r, 'unitsReference' => $lab->corr instanceof OaaaStore ? ($lab->corr->tiers[$logical]['u'] ?? null) : null];
}
emit('A05.Q7.replay-reorder-redis', ['arm' => $arm, 'sequences' => $q7]);
OaaaAsserts::check('A05', 'Q7 F116-01 replay / reorder sequences give identical decisions and identical units on Redis (Lua) and on the reference store' . ($cand ? ' — i.e. the attempt-level accounting defects of L24 / L25 / L26 are properties of the design, reproduced on the official adapter' : ''), array_reduce($q7, static fn(bool $c, array $x): bool => $c && $x['redis'] === $x['reference'] && $x['unitsRedis'] === $x['unitsReference'], true), $q7);

// Q8 — F116-03 mixed flows on one shared Redis scope: office pre+out (credits), then a pre-only route with 8 new subjects.
$seq = $sat('10.81.12.1', 'q8', 88);
$mine = $onRedis('oaaa:q8', $seq);
$pre = $onRedis('oaaa:q8', array_map(static fn(int $i): array => ['10.81.12.1', null, "q8p$i", 88, 'check'], range(0, 7)));
$lab = oaaaLab();
$u8 = $ex->execute(['HGET', $physical('oaaa:q8', $tiersOf($lab, '10.81.12.1', 88)['spray']), 'u']);
emit('A05.Q8.mixed-flow-redis', ['arm' => $arm, 'office' => $mine, 'preOnly' => $pre, 'admittedPreOnly' => substr_count($pre, 'a'), 'unitsSpentNeverResolved' => $u8 === null ? 0 : (int) $u8]);

// Q2 — key hygiene of everything written above.
$keys = array_map('strval', (array) $ex->execute(['KEYS', '*']));
$noTtl = 0;
$oaaaKeys = 0;
$oaaaTtlAbove = 0;
$creditMax = 0;
foreach ($keys as $k) {
    $ttl = (int) $ex->execute(['TTL', $k]);
    if ($ttl === -1) {                                // -1 = no expiry; -2 = expired between KEYS and TTL
        $noTtl++;
    }
    if (str_contains($k, ':oaaa:')) {
        $oaaaKeys++;
        $oaaaTtlAbove += $ttl > OAAA_PARAMS['daySeconds'] ? 1 : 0;
        if ((string) $ex->execute(['TYPE', $k]) === 'set') {
            $creditMax = max($creditMax, (int) $ex->execute(['SCARD', $k]));
        }
    }
}
emit('A05.Q2.key-hygiene', ['arm' => $arm, 'keys' => count($keys) > 0, 'keysWithoutTtl' => $noTtl, 'oaaaKeysPresent' => $oaaaKeys > 0, 'oaaaKeysTtlAboveOneDay' => $oaaaTtlAbove, 'maxCreditMembers' => $creditMax]);
OaaaAsserts::check('A05', 'Q2 every Redis key written on this arm has a TTL; ' . ($cand ? 'OAAA keys exist, none has a TTL above 86 400 s, no credit set above 2 members' : 'no OAAA key exists on S0'), $noTtl === 0 && count($keys) > 0 && ($cand ? $oaaaKeys > 0 && $oaaaTtlAbove === 0 && $creditMax <= 2 : $oaaaKeys === 0), ['noTtl' => $noTtl, 'oaaaKeys' => $oaaaKeys > 0, 'above' => $oaaaTtlAbove, 'creditMax' => $creditMax]);
OaaaAsserts::finish();
