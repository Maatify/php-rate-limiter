<?php

declare(strict_types=1);

// G2 section F (PLAN.md §4–§5 F): atomicity of the proof-path primitives on real Redis. Needs G2_REDIS_PORT (an ephemeral
// redis-server owned by run.sh; nothing else may use it). 32 forked workers per round meet at a barrier, 20 rounds per test.

require dirname(__DIR__) . '/lib/sim.php';
require_once dirname(__DIR__) . '/lib/redis.php';

$port = (int) getenv('G2_REDIS_PORT');
if ($port <= 0) {
    fwrite(STDERR, "G2_REDIS_PORT not set\n");
    exit(2);
}
const F_WORKERS = 32;
const F_ROUNDS = 20;

$connect = static function () use ($port): \Redis {
    $r = new \Redis();
    $r->connect('127.0.0.1', $port, 2.0);

    return $r;
};
$admin = $connect();
if ($admin->dbSize() !== 0) {
    fwrite(STDERR, "the ephemeral Redis is not empty\n");
    exit(2);
}
$emit = static function (array $row): void {
    echo json_encode(['s' => 'f'] + $row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
};

/**
 * Runs $op in F_WORKERS forked processes released together; returns every worker's result string.
 *
 * @param \Closure(G2RedisPathStore, int): string $op
 * @return list<string>
 */
$race = static function (string $tag, int $round, bool $mutant, \Closure $op) use ($connect, $admin): array {
    $ready = "{g2}:barrier:$tag:$round:ready";
    $go = "{g2}:barrier:$tag:$round:go";
    $out = "{g2}:barrier:$tag:$round:out";
    $pids = [];
    for ($w = 0; $w < F_WORKERS; $w++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $r = $connect();
            $store = new G2RedisPathStore($r, $mutant);
            $r->incr($ready);
            while ($r->get($go) === false) {
                usleep(200);
            }
            try {
                $res = $op($store, $w);
            } catch (\Throwable $e) {
                $res = 'error';
            }
            $r->rPush($out, $res);
            // no destructors in the child: they must not touch the parent's inherited connection
            posix_kill(posix_getpid(), SIGKILL);
        }
        $pids[] = $pid;
    }
    while ((int) $admin->get($ready) < F_WORKERS) {
        usleep(500);
    }
    $admin->set($go, '1');
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
    /** @var list<string> $res */
    $res = $admin->lRange($out, 0, -1);
    $admin->del([$ready, $go, $out]);

    return $res;
};
$count = static fn(array $res, string $v): int => count(array_filter($res, static fn(string $x): bool => $x === $v));
$now = 1000;

// ---- F1 nonce, F2 link redemption, F3 issuance cap: atomic and mutant ----
foreach ([false, true] as $mutant) {
    $nonceOk = [];
    $redeemOk = [];
    $issued = [];
    $errors = 0;
    for ($round = 0; $round < F_ROUNDS; $round++) {
        $nonce = "f1-$round-" . ($mutant ? 'm' : 'a');
        $res = $race('f1' . ($mutant ? 'm' : 'a'), $round, $mutant, static fn(G2RedisPathStore $s): string => $s->consumeNonce($nonce, $now + 120, $now));
        $nonceOk[] = $count($res, 'ok');
        $errors += $count($res, 'error');

        $link = "f2-$round-" . ($mutant ? 'm' : 'a');
        (new G2RedisPathStore($admin))->issue([], $link, ['tenant' => 'tenant-a', 'account' => 'acct-f', 'op' => 'login', 'exp' => $now + 1020, 'device' => ''], $now);
        $res = $race('f2' . ($mutant ? 'm' : 'a'), $round, $mutant, static fn(G2RedisPathStore $s, int $w): string
            => $s->redeem($link, ['tenant' => 'tenant-a', 'account' => 'acct-f', 'op' => 'login', 'device' => "d$w"], $now));
        $redeemOk[] = $count($res, 'ok');
        $errors += $count($res, 'error');

        $scope = "f3-$round-" . ($mutant ? 'm' : 'a');
        $res = $race('f3' . ($mutant ? 'm' : 'a'), $round, $mutant, static fn(G2RedisPathStore $s, int $w): string
            => $s->issue(
                [['name' => 'iss_acct', 'key' => "iss:$scope", 'cap' => 3, 'ttl' => 1800]],
                "$scope-link-$w",
                ['tenant' => 'tenant-a', 'account' => 'acct-f', 'op' => 'login', 'exp' => $now + 1020, 'device' => ''],
                $now,
            ) === null ? 'issued' : 'refused');
        $issued[] = $count($res, 'issued');
        $errors += $count($res, 'error');
    }
    $emit(['test' => 'race', 'mutant' => $mutant, 'workers' => F_WORKERS, 'rounds' => F_ROUNDS,
        'nonceSuccessesPerRound' => $nonceOk, 'redeemSuccessesPerRound' => $redeemOk, 'issuedPerRoundCap3' => $issued, 'workerErrors' => $errors,
        'nonceOverAdmittedRounds' => count(array_filter($nonceOk, static fn(int $n): bool => $n > 1)),
        'redeemOverAdmittedRounds' => count(array_filter($redeemOk, static fn(int $n): bool => $n > 1)),
        'issueOverCapRounds' => count(array_filter($issued, static fn(int $n): bool => $n > 3))]);
}

// ---- F4 lost replies: the first reply is discarded, the client retries ----
$s = new G2RedisPathStore($admin);
$s->consumeNonce('f4', $now + 120, $now);
$nonceRetry = $s->consumeNonce('f4', $now + 120, $now);
$s->issue([], 'f4-link', ['tenant' => 'tenant-a', 'account' => 'acct-f', 'op' => 'login', 'exp' => $now + 1020, 'device' => ''], $now);
$s->redeem('f4-link', ['tenant' => 'tenant-a', 'account' => 'acct-f', 'op' => 'login', 'device' => 'd'], $now);
$redeemRetry = $s->redeem('f4-link', ['tenant' => 'tenant-a', 'account' => 'acct-f', 'op' => 'login', 'device' => 'd'], $now);
$issueResults = [];
for ($i = 0; $i < 5; $i++) {
    $issueResults[] = $s->issue(
        [['name' => 'iss_acct', 'key' => 'iss:f4', 'cap' => 3, 'ttl' => 1800]],
        "f4-retry-$i",
        ['tenant' => 'tenant-a', 'account' => 'acct-f', 'op' => 'login', 'exp' => $now + 1020, 'device' => ''],
        $now,
    ) ?? 'issued';
}
$emit(['test' => 'lost-reply', 'nonceRetry' => $nonceRetry, 'redeemRetry' => $redeemRetry, 'issueRetries' => $issueResults,
    'issueCounter' => (int) $admin->get('{g2}:iss:f4')]);

// ---- F5 malformed state: an error before any write ----
$snapshot = static function () use ($admin): string {
    $out = [];
    $keys = $admin->keys('{g2}:*');
    sort($keys);
    foreach ($keys as $k) {
        $type = $admin->type($k);
        $out[$k] = [$type, match ($type) {
            \Redis::REDIS_STRING => $admin->get($k),
            \Redis::REDIS_LIST => $admin->lRange($k, 0, -1),
            \Redis::REDIS_HASH => $admin->hGetAll($k),
            default => null,
        }];
    }

    return hash('sha256', serialize($out));
};
$malformed = [];
$admin->rPush('{g2}:nonce:f5', 'x');
$admin->set('{g2}:iss:f5-a', '1');
$admin->hSet('{g2}:iss:f5-b', 'x', '1');
$admin->set('{g2}:link:f5-link', 'x');
foreach ([
    'nonce-as-list' => static fn(): string => $s->consumeNonce('f5', $now + 120, $now),
    'second-counter-as-hash' => static fn(): string => (string) ($s->issue(
        [['name' => 'a', 'key' => 'iss:f5-a', 'cap' => 9, 'ttl' => 60], ['name' => 'b', 'key' => 'iss:f5-b', 'cap' => 9, 'ttl' => 60]],
        'f5-new',
        ['tenant' => 'tenant-a', 'account' => 'acct-f', 'op' => 'login', 'exp' => $now + 1020, 'device' => ''],
        $now,
    ) ?? 'issued'),
    'link-as-string' => static fn(): string => $s->redeem('f5-link', ['tenant' => 'tenant-a', 'account' => 'acct-f', 'op' => 'login', 'device' => 'd'], $now),
] as $name => $call) {
    $before = $snapshot();
    try {
        $res = $call();
    } catch (\Throwable $e) {
        $res = 'error';
    }
    $malformed[$name] = ['result' => $res, 'unchanged' => $snapshot() === $before];
}
$emit(['test' => 'malformed', 'cases' => $malformed]);

// ---- F6 backend unreachable during acquisition: nothing minted, nothing admitted ----
// A real outage of the ephemeral instance (the last test): phpredis reconnects transparently after close() or a server-side
// kill, so neither models an outage. The client stays bound to this instance's port.
$dead = $connect();
$host = new G2Host(g2Arm('PA'), new G2RedisPathStore($dead));
[$sk, $cred] = $host->register(G2_TENANT, G2_VICTIM);
$adm = new G1Admission(new G1RefStore(), g1Params('R'), 'login');
$admin->flushDb();
try {
    $admin->rawCommand('SHUTDOWN', 'NOSAVE');
} catch (\RedisException) {
    // the server closes the connection while shutting down
}
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) !== false; $i++) {
    usleep(100000);
}
$token = null;
$failure = '-';
try {
    $c = $host->challenge($now, 'owner', G2_OWNER_SRC, G2_TENANT, G2_VICTIM, 'login');
    $token = $host->assert(
        $now + 5,
        'owner',
        G2_OWNER_SRC,
        G2_TENANT,
        G2_VICTIM,
        'login',
        (string) $c['challenge'],
        $cred,
        sodium_crypto_sign_detached(G2Host::signedMessage(G2_TENANT, (string) $c['challenge']), $sk),
        'owner-dev',
    )['token'];
} catch (\RedisException $e) {
    $failure = 'backend-failure';
}
$lane = (new G2Boundary($host))->lane($now + 7, G2_TENANT, G2_VICTIM, 'login', 'owner-dev', $token);
$emit(['test' => 'backend-unreachable', 'failure' => $failure, 'tokenMinted' => $token !== null, 'lane' => $lane['lane'],
    'proofsMinted' => $host->stats['owner']['proofsMinted'] ?? 0, 'admissions' => count($adm->log)]);
