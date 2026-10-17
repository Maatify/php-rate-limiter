<?php

declare(strict_types=1);

// G1 targeted step (DA_T1_PLAN.md §2) — FIXED admission store pair, study code only. The original stores in ../../lib/admission.php
// are unchanged (their T1 defects are kept as failing fixtures). Differences, all enforced BEFORE the first write:
//   * a permit id that already exists (any type, any state) is refused with 'permit_exists' (single issuance, at-most-once);
//   * device sets are validated and counted without pruning; nothing is written on refusal; the previous-generation set is never
//     written (read-only); the current set is pruned only as part of an admitted write.

require_once dirname(__DIR__, 2) . '/lib/admission.php';

final class G1RefStoreV2 implements G1Store
{
    /** @var array<string, array{0: int, 1: int}> */
    public array $c = [];
    /** @var array<string, array<string, int>> */
    public array $s = [];
    /** @var array<string, array<string, mixed>> */
    public array $p = [];
    public int $writes = 0;

    private function get(?string $k, int $now): int
    {
        return $k === null || ! isset($this->c[$k]) || $this->c[$k][1] <= $now ? 0 : $this->c[$k][0];
    }

    /** @return array<string, int> */
    private function live(?string $set, int $now): array
    {
        return $set === null || ! isset($this->s[$set]) ? [] : array_filter($this->s[$set], static fn(int $exp): bool => $exp > $now);
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
            $cur = $this->live($member['set'], $now);
            $prev = $this->live($member['prev'], $now);
            if (! isset($cur[$member['id']]) && ! ($member['prevId'] !== null && isset($prev[$member['prevId']]))) {
                if (count($cur) + count($prev) >= $member['cap']) {
                    return ['ok' => false, 'refused' => $member['name'], 'created' => 0];
                }
                $newMember = true;
            }
        }
        if ($permit !== null && isset($this->p[$permit['id']])) {
            return ['ok' => false, 'refused' => 'permit_exists', 'created' => 0];
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
            if ($this->live($member['set'], $now) === []) {
                $created++;
            }
            $this->s[$member['set']] = $this->live($member['set'], $now) + [$member['id'] => $now + $member['ttl']];
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
            if ($this->live($set, $now) !== []) {
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

final class G1RedisStoreV2 implements G1Store
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
-- phase 1: validate EVERYTHING, write nothing
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
    end
  end
  local live = function(z, id)
    if z == '' or id == '' then return false end
    local s = redis.call('ZSCORE', z, id)
    return s ~= false and tonumber(s) > now
  end
  local exists = live(mset, mid) or live(mprev, mprevId)
  if not exists then
    local n = redis.call('ZCOUNT', mset, '(' .. now, '+inf')
    if mprev ~= '' then n = n + redis.call('ZCOUNT', mprev, '(' .. now, '+inf') end
    if n >= mcap then return {0, -1, 0} end
    newMember = true
  end
end
local hasPermit = ARGV[i]
local pk
if hasPermit == '1' then
  pk = ARGV[i+1]
  if redis.call('EXISTS', pk) == 1 then return {0, -2, 0} end
end
-- phase 2: writes (only reached when admitted)
local created = 0
for c = 1, nc do
  if redis.call('EXISTS', keys[c]) == 0 then created = created + 1 end
  local v = redis.call('INCR', keys[c])
  if v == 1 then redis.call('EXPIRE', keys[c], ttls[c]) end
end
if newMember then
  redis.call('ZREMRANGEBYSCORE', mset, '-inf', now)
  if redis.call('EXISTS', mset) == 0 then created = created + 1 end
  redis.call('ZADD', mset, now + mttl, mid)
  redis.call('EXPIRE', mset, mttl)
end
if hasPermit == '1' then
  redis.call('HSET', pk, 'state', 'ISSUED', 'lane', ARGV[i+2], 'subject', ARGV[i+3], 'op', ARGV[i+4], 'deadline', ARGV[i+5])
  redis.call('EXPIRE', pk, math.max(1, tonumber(ARGV[i+5]) + tonumber(ARGV[i+6]) - now))
  created = created + 1
end
return {1, 0, created}
LUA;

    public function __construct(public \Redis $r)
    {
    }

    public function reserve(array $counters, ?array $member, ?array $permit, int $now): array
    {
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

        return ['ok' => false, 'refused' => match (true) {
            $idx === -1 => (string) $member['name'],
            $idx === -2 => 'permit_exists',
            default => $counters[$idx - 1]['name'],
        }, 'created' => 0];
    }

    public function claim(string $permitId, array $expected, string $outcome, int $now): string
    {
        $res = $this->r->eval(G1RedisStore::CLAIM, ['{g1}:permit:' . $permitId, (string) $now, $expected['lane'], $expected['subject'], $expected['op'], $outcome], 1);

        return is_string($res) ? $res : 'error';
    }

    public function liveKeys(int $now): array
    {
        $k = $this->r->keys('{g1}*');
        sort($k);

        return array_values($k);
    }
}

/** Complete dump of a Redis store's {g1} keys (type-aware), for byte-level before/after comparisons. @return array<string, mixed> */
function g1VRedisDump(\Redis $r): array
{
    $out = [];
    $keys = $r->keys('{g1}*');
    sort($keys);
    foreach ($keys as $k) {
        $t = $r->type($k);
        $out[$k] = match ($t) {
            \Redis::REDIS_STRING => $r->get($k),
            \Redis::REDIS_ZSET => $r->zRange($k, 0, -1, true),
            \Redis::REDIS_HASH => (static function (array $h): array {
                ksort($h);

                return $h;
            })($r->hGetAll($k)),
            default => "type:$t",
        };
    }

    return $out;
}
