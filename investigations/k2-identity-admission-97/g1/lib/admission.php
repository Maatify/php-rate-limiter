<?php

declare(strict_types=1);

// G1 isolated comparative study (#97) — admission layer. EVIDENCE ONLY: not package runtime, never wired into src/.
//
// One all-or-nothing reservation per admitted attempt (ARCHITECTURE_PROPOSAL.md §6, SECURITY_FEASIBILITY.md §2):
//   counters  : per-scope fixed-window (or day) counters; a request is admitted only if EVERY scope has headroom, then all are
//               incremented; nothing is written on refusal; counters are never decremented (no refunds).
//   rotation  : a counter may name a previous-generation key; the cap applies to current + previous (read-only previous).
//   P devices : optional distinct member in a per-account set (cap N_P, member TTL T_P); a member present in the current OR the
//               previous generation set is an existing member; new members go to the current set only, so the two sets stay
//               disjoint and their union is counted once (PA, P-3).
//   permits   : optional T1 permit record ISSUED -> CLAIMED, with EXPIRED / UNKNOWN reported on claim (F§2.3).
// Two implementations with one contract: G1RefStore (PHP arrays, controlled clock) and G1RedisStore (one Lua script per
// reservation / claim, caller-supplied time for window indices, member expiry and permit deadlines; key lifetimes are relative
// EXPIRE durations so the server clock never decides a window), all keys in one hash slot).

interface G1Store
{
    /**
     * @param list<array{name: string, key: string, cap: int, ttl: int, prev: ?string}> $counters
     * @param array{name: string, set: string, prev: ?string, id: string, prevId: ?string, cap: int, ttl: int}|null $member
     * @param array{id: string, lane: string, subject: string, op: string, deadline: int, grace: int}|null $permit
     * @return array{ok: bool, refused: ?string, created: int}
     */
    public function reserve(array $counters, ?array $member, ?array $permit, int $now): array;

    /**
     * @param array{lane: string, subject: string, op: string} $expected
     * @return string accepted|duplicate|conflict|rejected|late|unknown
     */
    public function claim(string $permitId, array $expected, string $outcome, int $now): string;

    /** @return list<string> live keys (for storage / namespace gates) */
    public function liveKeys(int $now): array;
}

final class G1RefStore implements G1Store
{
    /** @var array<string, array{0: int, 1: int}> key => [value, expiresAt] */
    public array $c = [];
    /** @var array<string, array<string, int>> set => [member => expiresAt] */
    public array $s = [];
    /** @var array<string, array{state: string, lane: string, subject: string, op: string, deadline: int, grace: int, outcome: ?string}> */
    public array $p = [];
    public int $writes = 0;

    private function get(?string $k, int $now): int
    {
        if ($k === null || ! isset($this->c[$k]) || $this->c[$k][1] <= $now) {
            return 0;
        }

        return $this->c[$k][0];
    }

    /** @return array<string, int> */
    private function members(?string $set, int $now): array
    {
        if ($set === null || ! isset($this->s[$set])) {
            return [];
        }

        return array_filter($this->s[$set], static fn(int $exp): bool => $exp > $now);
    }

    public function reserve(array $counters, ?array $member, ?array $permit, int $now): array
    {
        foreach ($counters as $x) {
            if ($this->get($x['key'], $now) + $this->get($x['prev'], $now) >= $x['cap']) {
                return ['ok' => false, 'refused' => $x['name'], 'created' => 0];
            }
        }
        $newMember = false;
        if ($member !== null) {
            $cur = $this->members($member['set'], $now);
            $prev = $this->members($member['prev'], $now);
            $exists = isset($cur[$member['id']]) || ($member['prevId'] !== null && isset($prev[$member['prevId']]));
            if (! $exists) {
                if (count($cur) + count($prev) >= $member['cap']) {
                    return ['ok' => false, 'refused' => $member['name'], 'created' => 0];
                }
                $newMember = true;
            }
        }
        $created = 0;
        foreach ($counters as $x) {
            if (! isset($this->c[$x['key']]) || $this->c[$x['key']][1] <= $now) {
                $this->c[$x['key']] = [0, $now + $x['ttl']];
                $created++;
            }
            $this->c[$x['key']][0]++;
            $this->writes++;
        }
        if ($newMember && $member !== null) {
            if (! isset($this->s[$member['set']]) || $this->members($member['set'], $now) === []) {
                $created++;
            }
            $this->s[$member['set']] = $this->members($member['set'], $now);
            $this->s[$member['set']][$member['id']] = $now + $member['ttl'];
            $this->writes++;
        }
        if ($permit !== null) {
            $this->p[$permit['id']] = $permit + ['state' => 'ISSUED', 'outcome' => null];
            $created++;
            $this->writes++;
        }

        return ['ok' => true, 'refused' => null, 'created' => $created];
    }

    public function claim(string $permitId, array $expected, string $outcome, int $now): string
    {
        $r = $this->p[$permitId] ?? null;
        if ($r === null || $r['deadline'] + $r['grace'] <= $now) {
            return 'unknown';
        }
        if ($r['lane'] !== $expected['lane'] || $r['subject'] !== $expected['subject'] || $r['op'] !== $expected['op']) {
            return 'rejected';
        }
        if ($r['state'] === 'CLAIMED') {
            return $r['outcome'] === $outcome ? 'duplicate' : 'conflict';
        }
        if ($r['deadline'] <= $now) {
            return 'late';
        }
        $this->p[$permitId]['state'] = 'CLAIMED';
        $this->p[$permitId]['outcome'] = $outcome;

        return 'accepted';
    }

    public function liveKeys(int $now): array
    {
        $k = [];
        foreach ($this->c as $key => [$v, $exp]) {
            if ($exp > $now) {
                $k[] = $key;
            }
        }
        foreach ($this->s as $set => $_) {
            if ($this->members($set, $now) !== []) {
                $k[] = $set;
            }
        }
        foreach ($this->p as $id => $r) {
            if ($r['deadline'] + $r['grace'] > $now) {
                $k[] = 'permit:' . $id;
            }
        }
        sort($k);

        return $k;
    }
}

/**
 * Redis implementation: one Lua script per reservation (validate every key before the first write, then write), caller time
 * (DEC-016 style: the script never calls TIME; key lifetimes are relative EXPIRE durations). Keys carry the {g1} hash tag. A deliberately NON-atomic mutation control
 * (check with one round trip, write with another) exists only to prove that the race test can detect over-admission.
 */
final class G1RedisStore implements G1Store
{
    public const RESERVE = <<<'LUA'
local now = tonumber(ARGV[1])
local nc = tonumber(ARGV[2])
local i = 3
local keys, caps, ttls, prevs = {}, {}, {}, {}
for c = 1, nc do
  keys[c] = ARGV[i]; caps[c] = tonumber(ARGV[i+1]); ttls[c] = tonumber(ARGV[i+2]); prevs[c] = ARGV[i+3]; i = i + 4
end
local function val(k)
  if k == '' then return 0 end
  local t = redis.call('TYPE', k).ok
  if t == 'none' then return 0 end
  if t ~= 'string' then return redis.error_reply('G1 malformed counter ' .. k) end
  return tonumber(redis.call('GET', k)) or 0
end
for c = 1, nc do
  local a = val(keys[c]); if type(a) == 'table' then return a end
  local b = val(prevs[c]); if type(b) == 'table' then return b end
  if a + b >= caps[c] then return {0, c, 0} end
end
local hasMember = ARGV[i]; i = i + 1
local newMember = false
local mset, mprev, mid, mprevId, mcap, mttl
if hasMember == '1' then
  mset = ARGV[i]; mprev = ARGV[i+1]; mid = ARGV[i+2]; mprevId = ARGV[i+3]; mcap = tonumber(ARGV[i+4]); mttl = tonumber(ARGV[i+5]); i = i + 6
  for _, z in ipairs({mset, mprev}) do
    if z ~= '' then
      local t = redis.call('TYPE', z).ok
      if t ~= 'none' and t ~= 'zset' then return redis.error_reply('G1 malformed device set ' .. z) end
      redis.call('ZREMRANGEBYSCORE', z, '-inf', now)
    end
  end
  local exists = redis.call('ZSCORE', mset, mid) ~= false
  if not exists and mprev ~= '' and mprevId ~= '' then exists = redis.call('ZSCORE', mprev, mprevId) ~= false end
  if not exists then
    local n = redis.call('ZCARD', mset)
    if mprev ~= '' then n = n + redis.call('ZCARD', mprev) end
    if n >= mcap then return {0, -1, 0} end
    newMember = true
  end
end
local created = 0
for c = 1, nc do
  if redis.call('EXISTS', keys[c]) == 0 then created = created + 1 end
  local v = redis.call('INCR', keys[c])
  if v == 1 then redis.call('EXPIRE', keys[c], ttls[c]) end
end
if newMember then
  if redis.call('EXISTS', mset) == 0 then created = created + 1 end
  redis.call('ZADD', mset, now + mttl, mid)
  redis.call('EXPIRE', mset, mttl)
end
local hasPermit = ARGV[i]
if hasPermit == '1' then
  local pk = ARGV[i+1]
  redis.call('HSET', pk, 'state', 'ISSUED', 'lane', ARGV[i+2], 'subject', ARGV[i+3], 'op', ARGV[i+4], 'deadline', ARGV[i+5])
  redis.call('EXPIRE', pk, math.max(1, tonumber(ARGV[i+5]) + tonumber(ARGV[i+6]) - now))
  created = created + 1
end
return {1, 0, created}
LUA;

    public const CLAIM = <<<'LUA'
local pk, now = KEYS[1], tonumber(ARGV[1])
if redis.call('EXISTS', pk) == 0 then return 'unknown' end
local r = redis.call('HGETALL', pk)
local h = {}
for j = 1, #r, 2 do h[r[j]] = r[j+1] end
if h.lane ~= ARGV[2] or h.subject ~= ARGV[3] or h.op ~= ARGV[4] then return 'rejected' end
if h.state == 'CLAIMED' then if h.outcome == ARGV[5] then return 'duplicate' else return 'conflict' end end
if tonumber(h.deadline) <= now then return 'late' end
redis.call('HSET', pk, 'state', 'CLAIMED', 'outcome', ARGV[5])
return 'accepted'
LUA;

    public function __construct(public \Redis $r, public bool $nonAtomicMutation = false)
    {
    }

    public function reserve(array $counters, ?array $member, ?array $permit, int $now): array
    {
        if ($this->nonAtomicMutation) {
            return $this->reserveNonAtomic($counters, $now);
        }
        $argv = [(string) $now, (string) count($counters)];
        foreach ($counters as $x) {
            array_push($argv, $x['key'], (string) $x['cap'], (string) $x['ttl'], $x['prev'] ?? '');
        }
        if ($member === null) {
            $argv[] = '0';
        } else {
            array_push($argv, '1', $member['set'], $member['prev'] ?? '', $member['id'], $member['prevId'] ?? '', (string) $member['cap'], (string) $member['ttl']);
        }
        if ($permit === null) {
            $argv[] = '0';
        } else {
            array_push($argv, '1', '{g1}:permit:' . $permit['id'], $permit['lane'], $permit['subject'], $permit['op'], (string) $permit['deadline'], (string) $permit['grace']);
        }
        $res = $this->r->eval(self::RESERVE, $argv, 0);
        if (! is_array($res)) {
            throw new \RuntimeException('G1 reserve failed: ' . (string) $this->r->getLastError());
        }
        if ((int) $res[0] === 1) {
            return ['ok' => true, 'refused' => null, 'created' => (int) $res[2]];
        }
        $idx = (int) $res[1];

        return ['ok' => false, 'refused' => $idx === -1 ? (string) $member['name'] : $counters[$idx - 1]['name'], 'created' => 0];
    }

    /** MUTATION CONTROL ONLY: check in one round trip, increment in another (not all-or-nothing, not atomic). */
    private function reserveNonAtomic(array $counters, int $now): array
    {
        foreach ($counters as $x) {
            $v = (int) ($this->r->get($x['key']) ?: 0);
            if ($v >= $x['cap']) {
                return ['ok' => false, 'refused' => $x['name'], 'created' => 0];
            }
        }
        usleep(2000);
        foreach ($counters as $x) {
            if ((int) $this->r->incr($x['key']) === 1) {
                $this->r->expire($x['key'], $x['ttl']);
            }
        }

        return ['ok' => true, 'refused' => null, 'created' => 0];
    }

    public function claim(string $permitId, array $expected, string $outcome, int $now): string
    {
        $res = $this->r->eval(self::CLAIM, ['{g1}:permit:' . $permitId, (string) $now, $expected['lane'], $expected['subject'], $expected['op'], $outcome], 1);

        return is_string($res) ? $res : 'error';
    }

    public function liveKeys(int $now): array
    {
        $k = $this->r->keys('{g1}*');
        sort($k);

        return array_values($k);
    }
}

/** Test parameter sets (PLAN.md §3). Never approved or recommended production values. */
const G1_PARAMS = [
    'R' => ['W' => 600, 'U_src' => 12, 'U_acct' => 4, 'U_glob' => 1000, 'P_acct' => 10, 'P_acct_day' => 60, 'P_proof' => 6,
        'P_proof_day' => 30, 'P_glob' => 0, 'N_P' => 10, 'T_P' => 86400, 'A_src' => 300, 'A_glob' => 0],
    'R-src4' => ['U_src' => 4],
    'R-src40' => ['U_src' => 40],
];

/** @return array<string, int> */
function g1Params(string $set): array
{
    return $set === 'R' ? G1_PARAMS['R'] : G1_PARAMS[$set] + G1_PARAMS['R'];
}

/**
 * Builds the scopes of one reservation and calls the store. Keys are HMACs of the scope value under the generation secret
 * (privacy, rotation), grouped under one hash tag. Every admitted reservation is logged for the independent C1/C2 recount.
 */
final class G1Admission
{
    /** @var list<array{t: int, lane: string, scopes: array<string, string>, created: int, scopeCount: int}> */
    public array $log = [];
    /** @var list<array{t: int, lane: string, refused: string}> */
    public array $refusals = [];

    /** @param array<string, int> $p */
    public function __construct(
        public G1Store $store,
        public array $p,
        public string $class = 'login',
        public string $gen = 'g1-secret-current',
        public ?string $prevGen = null,
    ) {
    }

    private function h(string $v, string $secret): string
    {
        return substr(hash_hmac('sha256', $v, $secret), 0, 24);
    }

    private function key(string $scope, string $value, string $bucket, string $secret): string
    {
        return '{g1}:' . $this->class . ':' . substr(hash('sha256', $secret), 0, 6) . ':' . $scope . ':' . $this->h($value, $secret) . ':' . $bucket;
    }

    /**
     * @param 'U'|'P'|'A' $lane
     * @return array{ok: bool, refused: ?string, created: int}
     */
    public function reserve(string $lane, string $source, ?string $subject, ?string $proofRef, ?string $deviceMember, int $now, ?array $permit = null): array
    {
        $W = $this->p['W'];
        $w = 'w' . intdiv($now, $W);
        $d = 'd' . intdiv($now, 86400);
        $defs = [];
        if ($lane === 'U') {
            $defs[] = ['src', $source, $w, $this->p['U_src'], 2 * $W];
            if ($subject !== null) {
                $defs[] = ['acct', $subject, $w, $this->p['U_acct'], 2 * $W];
            }
            if ($this->p['U_glob'] > 0) {
                $defs[] = ['glob', 'all', $w, $this->p['U_glob'], 2 * $W];
            }
        } elseif ($lane === 'P') {
            $defs[] = ['pacct', (string) $subject, $w, $this->p['P_acct'], 2 * $W];
            $defs[] = ['pacct_day', (string) $subject, $d, $this->p['P_acct_day'], 2 * 86400];
            $defs[] = ['pproof', (string) $proofRef, $w, $this->p['P_proof'], 2 * $W];
            $defs[] = ['pproof_day', (string) $proofRef, $d, $this->p['P_proof_day'], 2 * 86400];
            if ($this->p['P_glob'] > 0) {
                $defs[] = ['pglob', 'all', $w, $this->p['P_glob'], 2 * $W];
            }
        } else {
            $defs[] = ['asrc', $source, $w, $this->p['A_src'], 2 * $W];
            if ($this->p['A_glob'] > 0) {
                $defs[] = ['aglob', 'all', $w, $this->p['A_glob'], 2 * $W];
            }
        }
        $counters = [];
        $scopes = [];
        foreach ($defs as [$scope, $value, $bucket, $cap, $ttl]) {
            $counters[] = [
                'name' => $scope,
                'key' => $this->key($scope, $value, $bucket, $this->gen),
                'cap' => $cap,
                'ttl' => $ttl,
                'prev' => $this->prevGen === null ? null : $this->key($scope, $value, $bucket, $this->prevGen),
            ];
            $scopes[$scope] = $value . '@' . $bucket;
        }
        $member = null;
        if ($lane === 'P' && $deviceMember !== null) {
            $member = [
                'name' => 'p_device',
                'set' => $this->key('pdev', (string) $subject, 'set', $this->gen),
                'prev' => $this->prevGen === null ? null : $this->key('pdev', (string) $subject, 'set', $this->prevGen),
                'id' => $this->h($deviceMember, $this->gen),
                'prevId' => $this->prevGen === null ? null : $this->h($deviceMember, $this->prevGen),
                'cap' => $this->p['N_P'],
                'ttl' => $this->p['T_P'],
            ];
        }
        $res = $this->store->reserve($counters, $member, $permit, $now);
        if ($res['ok']) {
            $this->log[] = ['t' => $now, 'lane' => $lane, 'scopes' => $scopes, 'created' => $res['created'],
                'scopeCount' => count($counters) + ($member === null ? 0 : 1) + ($permit === null ? 0 : 1)];
        } else {
            $this->refusals[] = ['t' => $now, 'lane' => $lane, 'refused' => (string) $res['refused']];
        }

        return $res;
    }
}

/**
 * C6: keys created by admitted reservations never exceed the reservation's scope count (s + p), summed per window.
 *
 * @param list<array{t: int, created: int, scopeCount: int}> $log
 * @return array{maxCreatedPerWindow: int, boundPerWindow: int, ok: bool}
 */
function g1StorageCheck(array $log, int $W): array
{
    $c = [];
    $b = [];
    foreach ($log as $e) {
        $w = intdiv($e['t'], $W);
        $c[$w] = ($c[$w] ?? 0) + $e['created'];
        $b[$w] = ($b[$w] ?? 0) + $e['scopeCount'];
    }
    $ok = true;
    foreach ($c as $w => $n) {
        $ok = $ok && $n <= $b[$w];
    }

    return ['maxCreatedPerWindow' => $c === [] ? 0 : max($c), 'boundPerWindow' => $b === [] ? 0 : max($b), 'ok' => $ok];
}

/**
 * Independent recount of the admission log (C1 / C2): the maximum number of admissions per scope value and window, which must
 * not exceed the scope's cap. Returns the worst ratio per scope name.
 *
 * @param list<array{t: int, lane: string, scopes: array<string, string>}> $log
 * @return array<string, array{max: int, cap: int}>
 */
function g1Recount(array $log, array $p): array
{
    $caps = ['src' => $p['U_src'], 'acct' => $p['U_acct'], 'glob' => $p['U_glob'], 'pacct' => $p['P_acct'], 'pacct_day' => $p['P_acct_day'],
        'pproof' => $p['P_proof'], 'pproof_day' => $p['P_proof_day'], 'pglob' => $p['P_glob'], 'asrc' => $p['A_src'], 'aglob' => $p['A_glob']];
    $n = [];
    foreach ($log as $e) {
        foreach ($e['scopes'] as $scope => $v) {
            $n[$scope][$v] = ($n[$scope][$v] ?? 0) + 1;
        }
    }
    $out = [];
    foreach ($n as $scope => $vals) {
        $out[$scope] = ['max' => max($vals), 'cap' => $caps[$scope]];
    }
    ksort($out);

    return $out;
}
