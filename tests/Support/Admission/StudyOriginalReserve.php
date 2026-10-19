<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;

/**
 * DETECTOR FIXTURE (NM-6, ROT): the reserve script of the G1 study store exactly as archived in
 * `investigations/k2-identity-admission-97/g1/lib/admission.php` (`G1RedisStore::RESERVE`), the store that failed T1-N4b
 * (device sets pruned on refusal) and T1-N5 (previous set written). It is never production code; the gates must fail it.
 * The permit part of the original script is not used here (only counters and the device member).
 */
final class StudyOriginalReserve
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

    /**
     * @param list<array{key: string, cap: int, ttl: int, prev: ?string}> $counters
     * @param array{set: string, prev: ?string, id: string, prevId: ?string, cap: int, ttl: int}|null $member
     * @return array{ok: bool, refusedIndex: int}
     */
    public static function reserve(RedisCommandExecutorInterface $redis, array $counters, ?array $member, int $now): array
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
        $argv[] = '0';
        $reply = $redis->execute(array_merge(['EVAL', self::RESERVE, 0], $argv));
        if (! is_array($reply)) {
            throw new \RuntimeException('Study reserve script returned a non-array reply.');
        }

        return ['ok' => (int) $reply[0] === 1, 'refusedIndex' => (int) $reply[1]];
    }
}
