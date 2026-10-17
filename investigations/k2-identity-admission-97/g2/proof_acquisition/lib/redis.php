<?php

declare(strict_types=1);

// G2 proof acquisition (#97) — Redis implementation of the proof-path primitives (PLAN.md §4 F). EVIDENCE ONLY.
// One Lua script per primitive; every script validates the type of every key it touches before its first write, never calls
// TIME (window indices and expiry come from the caller), and uses relative EXPIRE durations. All keys carry the {g2} hash tag.
// $nonAtomic = true is a MUTATION CONTROL ONLY: check in one round trip, write in another.

require_once __DIR__ . '/proof.php';

final class G2RedisPathStore implements G2PathStore
{
    public const RESERVE = <<<'LUA'
local now, n = tonumber(ARGV[1]), tonumber(ARGV[2])
local keys, caps, ttls = {}, {}, {}
local i = 3
for c = 1, n do keys[c] = ARGV[i]; caps[c] = tonumber(ARGV[i+1]); ttls[c] = tonumber(ARGV[i+2]); i = i + 3 end
local hasLink = ARGV[i]
for c = 1, n do
  local t = redis.call('TYPE', keys[c]).ok
  if t ~= 'none' and t ~= 'string' then return redis.error_reply('G2 malformed counter ' .. keys[c]) end
end
if hasLink == '1' then
  local t = redis.call('TYPE', ARGV[i+1]).ok
  if t ~= 'none' then return redis.error_reply('G2 link key exists ' .. ARGV[i+1]) end
end
for c = 1, n do
  if (tonumber(redis.call('GET', keys[c])) or 0) >= caps[c] then return {0, c} end
end
for c = 1, n do
  if redis.call('INCR', keys[c]) == 1 then redis.call('EXPIRE', keys[c], ttls[c]) end
end
if hasLink == '1' then
  local lk = ARGV[i+1]
  redis.call('HSET', lk, 'state', 'ISSUED', 'tenant', ARGV[i+2], 'account', ARGV[i+3], 'op', ARGV[i+4], 'exp', ARGV[i+5], 'device', ARGV[i+6])
  redis.call('EXPIRE', lk, math.max(1, tonumber(ARGV[i+5]) - now))
end
return {1, 0}
LUA;

    public const CONSUME = <<<'LUA'
local t = redis.call('TYPE', KEYS[1]).ok
if t ~= 'none' and t ~= 'string' then return redis.error_reply('G2 malformed nonce ' .. KEYS[1]) end
if t == 'string' then return 'used' end
redis.call('SET', KEYS[1], '1', 'EX', math.max(1, tonumber(ARGV[1])))
return 'ok'
LUA;

    public const REDEEM = <<<'LUA'
local t = redis.call('TYPE', KEYS[1]).ok
if t == 'none' then return 'unknown' end
if t ~= 'hash' then return redis.error_reply('G2 malformed link ' .. KEYS[1]) end
local h = {}
local r = redis.call('HGETALL', KEYS[1])
for j = 1, #r, 2 do h[r[j]] = r[j+1] end
if h.state == 'REDEEMED' then return 'used' end
if h.state == 'REVOKED' then return 'revoked' end
if tonumber(h.exp) <= tonumber(ARGV[1]) then return 'expired' end
if h.tenant ~= ARGV[2] or h.account ~= ARGV[3] or h.op ~= ARGV[4] or (h.device ~= '' and h.device ~= ARGV[5]) then return 'mismatch' end
redis.call('HSET', KEYS[1], 'state', 'REDEEMED')
return 'ok'
LUA;

    public function __construct(public \Redis $r, public bool $nonAtomic = false) {}

    private static function k(string $key): string
    {
        return '{g2}:' . $key;
    }

    /**
     * @param list<array{name: string, key: string, cap: int, ttl: int}> $defs
     * @param list<string> $link
     */
    private function script(array $defs, array $link, int $now): ?string
    {
        $argv = [(string) $now, (string) count($defs)];
        foreach ($defs as $d) {
            array_push($argv, self::k($d['key']), (string) $d['cap'], (string) $d['ttl']);
        }
        $argv = array_merge($argv, $link === [] ? ['0'] : array_merge(['1'], $link));
        $res = $this->r->eval(self::RESERVE, $argv, 0);
        if (! is_array($res)) {
            throw new \RuntimeException('G2 reserve failed: ' . (string) $this->r->getLastError());
        }

        return (int) $res[0] === 1 ? null : $defs[(int) $res[1] - 1]['name'];
    }

    public function reserve(array $defs, int $now): ?string
    {
        if ($this->nonAtomic) {
            foreach ($defs as $d) {
                if ((int) ($this->r->get(self::k($d['key'])) ?: 0) >= $d['cap']) {
                    return $d['name'];
                }
            }
            usleep(2000);
            foreach ($defs as $d) {
                if ((int) $this->r->incr(self::k($d['key'])) === 1) {
                    $this->r->expire(self::k($d['key']), $d['ttl']);
                }
            }

            return null;
        }

        return $this->script($defs, [], $now);
    }

    public function consumeNonce(string $nonce, int $exp, int $now): string
    {
        $key = self::k('nonce:' . $nonce);
        if ($this->nonAtomic) {
            if ((int) $this->r->exists($key) > 0) {
                return 'used';
            }
            usleep(2000);
            $this->r->set($key, '1', ['EX' => max(1, $exp - $now)]);

            return 'ok';
        }
        $res = $this->r->eval(self::CONSUME, [$key, (string) ($exp - $now)], 1);
        if (! is_string($res)) {
            throw new \RuntimeException('G2 consume failed: ' . (string) $this->r->getLastError());
        }

        return $res;
    }

    public function issue(array $defs, string $linkKey, array $rec, int $now): ?string
    {
        if ($this->nonAtomic) {
            $refused = $this->reserve($defs, $now);
            if ($refused === null) {
                $this->r->hMSet(self::k('link:' . $linkKey), ['state' => 'ISSUED'] + $rec);
            }

            return $refused;
        }

        return $this->script($defs, [self::k('link:' . $linkKey), $rec['tenant'], $rec['account'], $rec['op'], (string) $rec['exp'], $rec['device']], $now);
    }

    public function redeem(string $linkKey, array $expect, int $now): string
    {
        $key = self::k('link:' . $linkKey);
        if ($this->nonAtomic) {
            if ($this->r->hGet($key, 'state') !== 'ISSUED') {
                return 'used';
            }
            usleep(2000);
            $this->r->hSet($key, 'state', 'REDEEMED');

            return 'ok';
        }
        $res = $this->r->eval(self::REDEEM, [$key, (string) $now, $expect['tenant'], $expect['account'], $expect['op'], $expect['device']], 1);
        if (! is_string($res)) {
            throw new \RuntimeException('G2 redeem failed: ' . (string) $this->r->getLastError());
        }

        return $res;
    }

    public function revokeLinks(string $tenant, string $account, string $op, string $exceptKey): void
    {
        throw new \LogicException('revokeLinks is a reference-store-only arm (N-B-INVAL)');
    }
}
