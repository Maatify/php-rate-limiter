<?php

declare(strict_types=1);

// A08 — F116-02 historical evidence (added after Lead review; not pre-registered). The SUPERSEDED Redis adapter of the candidate as
// committed at 0df807f (patch sha-256 e8032f5d…, adapter a8af48c7…) is built by run.sh into results/work/candidate-prefix and probed with
// the two wrong-type cases the Lead identified. It shows whether the old Lua scripts left a partial write behind after their runtime
// error. Only the adapter's two public OAAA operations are called (the old pipeline is never run). Real Redis owned by run.sh.
require dirname(__DIR__, 2) . '/workstream-b-anti-evasion/lib/redis.php';

const PREFIX_REDIS_SHA256 = 'a8af48c7661f1a466fe12416530598f7677583ae3683f7dd4a1eb3456002977f';

$file = (string) (new \ReflectionClass(\Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore::class))->getFileName();
$repoFile = realpath(dirname(__DIR__, 3) . '/src/Repository/Redis/RedisFullCapabilityStore.php');
if (hash_file('sha256', $file) !== PREFIX_REDIS_SHA256 || realpath($file) === $repoFile) {
    fwrite(STDERR, "A08 provenance violation: not the superseded 0df807f candidate Redis adapter\n");
    exit(4);
}
emit('A08.provenance', ['runtime' => 'SUPERSEDED_CANDIDATE_0df807f', 'redisAdapterSha256' => PREFIX_REDIS_SHA256]);
$ex = wsbRedisConnectVerified(true);
$store = new \Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore($ex, 'oaaa:a08');
$prefix = (new \ReflectionClassConstant(\Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore::class, 'PREFIX'))->getValue();
$phys = static fn(string $logical): string => $prefix . ':' . hash('sha256', 'oaaa:a08') . ':oaaa:' . hash('sha256', $logical);
$snap = static function (array $logical) use ($ex, $phys): array {
    $o = [];
    foreach ($logical as $l) {
        $k = $phys($l);
        $t = (string) $ex->execute(['TYPE', $k]);
        $o[$l] = ['type' => $t, 'value' => match ($t) {
            'hash' => (array) $ex->execute(['HGETALL', $k]),
            'set' => (array) $ex->execute(['SMEMBERS', $k]),
            'string' => (string) $ex->execute(['GET', $k]),
            default => null,
        }];
    }

    return $o;
};
$call = static function (callable $f): ?string {
    try {
        $f();

        return null;
    } catch (\Throwable $e) {
        return get_class($e);
    }
};
// (a) tuple key is a STRING on reserve
$ex->execute(['SET', $phys('tupleA'), 'not-a-hash', 'EX', '600']);
$keys = ['tupleA', 'oaaa:v1:e:spray:a', 'oaaa:v1:g:e:a'];
$before = $snap($keys);
$err = $call(static fn() => $store->reserveAdmission('tupleA', 600, [['key' => 'oaaa:v1:e:spray:a', 'previousKey' => null, 'cap' => 2, 'ttl' => 600, 'creditKeys' => []], ['key' => 'oaaa:v1:g:e:a', 'previousKey' => null, 'cap' => 12, 'ttl' => 600, 'creditKeys' => []]]));
$after = $snap($keys);
$a = ['error' => $err, 'unchanged' => $after === $before, 'tierTypeAfter' => $after['oaaa:v1:e:spray:a']['type'], 'globalTypeAfter' => $after['oaaa:v1:g:e:a']['type']];
// (b) credit key is a STRING on a success completion (tuple holds one pending reservation on tier B, u = 1)
$store->reserveAdmission('tupleB', 600, [['key' => 'oaaa:v1:e:spray:b', 'previousKey' => null, 'cap' => 2, 'ttl' => 600, 'creditKeys' => []]]);
$ex->execute(['SET', $phys('creditB'), 'not-a-set', 'EX', '600']);
$keys = ['tupleB', 'oaaa:v1:e:spray:b', 'creditB'];
$before = $snap($keys);
$err = $call(static fn() => $store->completeAdmission('tupleB', 'success', ['oaaa:v1:e:spray:b'], 2, ['creditB'], 'member', 2, 1200));
$after = $snap($keys);
$unitsOf = static function (array $s): ?int {
    $v = $s['oaaa:v1:e:spray:b']['value'];
    $i = array_search('u', $v, true);

    return $i === false ? null : (int) $v[$i + 1];
};
$b = ['error' => $err, 'unchanged' => $after === $before, 'tierUnitsBefore' => $unitsOf($before), 'tierUnitsAfter' => $unitsOf($after)];
emit('A08.F116-02.superseded-lua-partial-write', ['reserveTupleIsString' => $a, 'completeCreditIsString' => $b]);
