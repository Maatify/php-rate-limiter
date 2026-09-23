<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository\Redis;

use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\DTO\BoundedDistinctSnapshotDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\DecayPauseStateDTO;
use Maatify\RateLimiter\DTO\HardBlockCycleResultDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\FullCapabilityStoreInterface;

/**
 * Official Redis-backed aggregate store.
 *
 * The Host supplies the raw-command executor. This adapter supports one
 * logical non-clustered Redis server and deliberately has no Redis-client
 * dependency.
 */
final class RedisFullCapabilityStore implements FullCapabilityStoreInterface
{
    private const PREFIX = 'maatify:rate-limiter:v1';

    private const SCORE_INCREMENT = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local exists = redis.call('EXISTS', KEYS[1])
if exists == 0 then
  redis.call('HSET', KEYS[1], 'value', ARGV[2], 'updatedAt', now)
  redis.call('EXPIRE', KEYS[1], ARGV[1])
  return {ARGV[2], now}
end
local value = redis.call('HGET', KEYS[1], 'value')
if not value or not redis.call('HGET', KEYS[1], 'updatedAt') then
  return redis.error_reply('malformed score state')
end
value = tonumber(value)
if not value then return redis.error_reply('malformed score value') end
value = value + tonumber(ARGV[2])
redis.call('HSET', KEYS[1], 'value', value, 'updatedAt', now)
return {value, now}
LUA;

    private const SCORE_GET = <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 0 then return {} end
local value = redis.call('HGET', KEYS[1], 'value')
local updated = redis.call('HGET', KEYS[1], 'updatedAt')
if not value or not updated then return redis.error_reply('malformed score state') end
return {value, updated}
LUA;

    private const SCORE_SET = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
redis.call('HSET', KEYS[1], 'value', ARGV[2], 'updatedAt', now)
redis.call('EXPIRE', KEYS[1], ARGV[1])
return now
LUA;

    private const BLOCK_SET = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local expires = now + tonumber(ARGV[2])
redis.call('HSET', KEYS[1], 'level', ARGV[1], 'expiresAt', expires)
redis.call('EXPIRE', KEYS[1], ARGV[2])
return expires
LUA;

    private const BLOCK_GET = <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 0 then return {} end
local level = redis.call('HGET', KEYS[1], 'level')
local expires = redis.call('HGET', KEYS[1], 'expiresAt')
if not level or not expires then return redis.error_reply('malformed block state') end
if tonumber(expires) <= tonumber(redis.call('TIME')[1]) then
  redis.call('DEL', KEYS[1])
  return {}
end
return {level, expires}
LUA;

    private const BUDGET_INCREMENT = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local count = redis.call('HGET', KEYS[1], 'count')
local start = redis.call('HGET', KEYS[1], 'epochStart')
local duration = redis.call('HGET', KEYS[1], 'epochDuration')
if count and start and duration and now < tonumber(start) + tonumber(duration) then
  count = tonumber(count) + tonumber(ARGV[2])
  redis.call('HSET', KEYS[1], 'count', count)
  return {count, start}
end
redis.call('HSET', KEYS[1], 'count', ARGV[2], 'epochStart', now, 'epochDuration', ARGV[1])
redis.call('EXPIRE', KEYS[1], ARGV[1])
return {ARGV[2], now}
LUA;

    private const BUDGET_GET = <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 0 then return {} end
local count = redis.call('HGET', KEYS[1], 'count')
local start = redis.call('HGET', KEYS[1], 'epochStart')
local duration = redis.call('HGET', KEYS[1], 'epochDuration')
if not count or not start or not duration then return redis.error_reply('malformed budget state') end
if tonumber(redis.call('TIME')[1]) >= tonumber(start) + tonumber(duration) then
  redis.call('DEL', KEYS[1])
  return {}
end
return {count, start}
LUA;

    private const BUDGET_SEED = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local count = redis.call('HGET', KEYS[1], 'count')
local start = redis.call('HGET', KEYS[1], 'epochStart')
local duration = redis.call('HGET', KEYS[1], 'epochDuration')
if count and start and duration and now < tonumber(start) + tonumber(duration) then
  count = tonumber(count) + tonumber(ARGV[4])
  redis.call('HSET', KEYS[1], 'count', count)
  return {count, start}
end
local seedStart = tonumber(ARGV[2])
local epochDuration = tonumber(ARGV[1])
if now < seedStart + epochDuration then
  count = tonumber(ARGV[3]) + tonumber(ARGV[4])
  redis.call('HSET', KEYS[1], 'count', count, 'epochStart', seedStart, 'epochDuration', epochDuration)
  redis.call('EXPIRE', KEYS[1], seedStart + epochDuration - now)
  return {count, seedStart}
end
redis.call('HSET', KEYS[1], 'count', ARGV[4], 'epochStart', now, 'epochDuration', epochDuration)
redis.call('EXPIRE', KEYS[1], epochDuration)
return {ARGV[4], now}
LUA;

    private const DISTINCT_ADD = <<<'LUA'
local exists = redis.call('EXISTS', KEYS[1])
if exists == 1 and redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed distinct state') end
if exists == 0 then redis.call('SADD', KEYS[1], ARGV[2]); redis.call('EXPIRE', KEYS[1], ARGV[1]); redis.call('HSET', KEYS[2], 'expiresAt', tonumber(redis.call('TIME')[1]) + tonumber(ARGV[1])); redis.call('EXPIRE', KEYS[2], ARGV[1])
else redis.call('SADD', KEYS[1], ARGV[2]) end
return redis.call('SCARD', KEYS[1])
LUA;

    private const WATCH_INCREMENT = <<<'LUA'
local exists = redis.call('EXISTS', KEYS[1])
if exists == 1 and redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed watch state') end
local value
if exists == 0 then value = 1; redis.call('SET', KEYS[1], value, 'EX', ARGV[1]); redis.call('HSET', KEYS[2], 'expiresAt', tonumber(redis.call('TIME')[1]) + tonumber(ARGV[1])); redis.call('EXPIRE', KEYS[2], ARGV[1])
else value = redis.call('INCR', KEYS[1]) end
return value
LUA;

    private const WATCH_GET = <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 0 then return 0 end
if redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed watch state') end
local value = redis.call('GET', KEYS[1])
if not value or not tonumber(value) then return redis.error_reply('malformed watch value') end
return value
LUA;

    private const BOUNDED_SNAPSHOT = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local exists = redis.call('EXISTS', KEYS[1])
if exists == 1 and redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed bounded state') end
if redis.call('SISMEMBER', KEYS[1], ARGV[2]) == 1 then
  local members = redis.call('SMEMBERS', KEYS[1]); table.sort(members)
  return {#members, 1, 0, ARGV[1] == '' and 0 or (redis.call('HGET', KEYS[2], 'expiresAt') or now), unpack(members)}
end
local count = redis.call('SCARD', KEYS[1])
if count >= tonumber(ARGV[3]) then
  local members = redis.call('SMEMBERS', KEYS[1]); table.sort(members)
  return {count, 0, 0, redis.call('HGET', KEYS[2], 'expiresAt') or now, unpack(members)}
end
if exists == 0 then redis.call('SADD', KEYS[1], ARGV[2]); redis.call('EXPIRE', KEYS[1], ARGV[1]); redis.call('HSET', KEYS[2], 'expiresAt', now + tonumber(ARGV[1])); redis.call('EXPIRE', KEYS[2], ARGV[1]) else redis.call('SADD', KEYS[1], ARGV[2]) end
local members = redis.call('SMEMBERS', KEYS[1]); table.sort(members)
return {#members, 1, 1, redis.call('HGET', KEYS[2], 'expiresAt') or now + tonumber(ARGV[1]), unpack(members)}
LUA;

    private const ROTATED_SNAPSHOT = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local prevExists = redis.call('EXISTS', KEYS[5])
local prevExpiry = prevExists == 1 and redis.call('HGET', KEYS[6], 'expiresAt') or false
if prevExists == 1 and (not prevExpiry or tonumber(prevExpiry) <= now) then prevExists = 0 end
if prevExists == 0 then
  local exists = redis.call('EXISTS', KEYS[1])
  if exists == 1 and redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed bounded state') end
  if redis.call('SISMEMBER', KEYS[1], ARGV[3]) == 1 then
    local members = redis.call('SMEMBERS', KEYS[1]); table.sort(members)
    return {#members, 1, 0, redis.call('HGET', KEYS[2], 'expiresAt') or now, unpack(members)}
  end
  local count = redis.call('SCARD', KEYS[1])
  if count >= tonumber(ARGV[5]) then
    local members = redis.call('SMEMBERS', KEYS[1]); table.sort(members)
    return {count, 0, 0, redis.call('HGET', KEYS[2], 'expiresAt') or now, unpack(members)}
  end
  if exists == 0 then redis.call('SADD', KEYS[1], ARGV[3]); redis.call('EXPIRE', KEYS[1], ARGV[2]); redis.call('HSET', KEYS[2], 'expiresAt', now + tonumber(ARGV[2])); redis.call('EXPIRE', KEYS[2], ARGV[2]) else redis.call('SADD', KEYS[1], ARGV[3]) end
  local members = redis.call('SMEMBERS', KEYS[1]); table.sort(members)
  return {#members, 1, 1, redis.call('HGET', KEYS[2], 'expiresAt') or now + tonumber(ARGV[2]), unpack(members)}
end
local previous = redis.call('SMEMBERS', KEYS[5]); table.sort(previous)
local currentExists = redis.call('EXISTS', KEYS[1])
local bridgeExists = redis.call('EXISTS', KEYS[3])
if (currentExists == 1 and redis.call('TTL', KEYS[1]) < 0) or (bridgeExists == 1 and redis.call('TTL', KEYS[3]) < 0) then return redis.error_reply('malformed rotated bounded state') end
local bridgeExpiry = redis.call('HGET', KEYS[4], 'expiresAt')
if bridgeExists == 1 and not bridgeExpiry then return redis.error_reply('malformed bridge state') end
local bridge = bridgeExists == 1 and redis.call('SMEMBERS', KEYS[3]) or {}
table.sort(bridge)
local members = {}
for _, member in ipairs(previous) do members[#members + 1] = member end
for _, member in ipairs(bridge) do members[#members + 1] = member end
for _, member in ipairs(previous) do for _, other in ipairs(bridge) do if member == other then return redis.error_reply('bridge overlaps previous state') end end end
if #members > tonumber(ARGV[4]) then return redis.error_reply('rotated bounded state exceeds capacity') end
local known = redis.call('SISMEMBER', KEYS[3], ARGV[3]) == 1
for _, member in ipairs(previous) do if member == ARGV[4] then known = true end end
local added = false
if not known and #members < tonumber(ARGV[4]) then
  if currentExists == 0 then redis.call('EXPIRE', KEYS[1], ARGV[2]); redis.call('HSET', KEYS[2], 'expiresAt', now + tonumber(ARGV[2])); redis.call('EXPIRE', KEYS[2], ARGV[2]) end
  redis.call('SADD', KEYS[1], ARGV[3])
  if bridgeExists == 0 then bridgeExpiry = math.min(now + tonumber(ARGV[2]), tonumber(prevExpiry)); redis.call('HSET', KEYS[4], 'expiresAt', bridgeExpiry); redis.call('EXPIRE', KEYS[4], math.max(1, bridgeExpiry - now)); end
  redis.call('SADD', KEYS[3], ARGV[3]); added = true
  members[#members + 1] = ARGV[3]; table.sort(members)
end
return {#members, known or added and 1 or 0, added and 1 or 0, tonumber(prevExpiry), unpack(members)}
LUA;

    private const LEASE = <<<'LUA'
local current = redis.call('GET', KEYS[1])
if current and tonumber(current) > tonumber(ARGV[1]) then return 0 end
redis.call('SET', KEYS[1], tonumber(ARGV[1]) + tonumber(ARGV[2]), 'EX', ARGV[2])
return 1
LUA;

    private const HARD_BLOCK = <<<'LUA'
local now = tonumber(ARGV[5])
local function merge(source)
  if redis.call('EXISTS', source) == 0 then return end
  for _, member in ipairs(redis.call('ZRANGE', source, 0, -1)) do redis.call('ZADD', KEYS[1], tonumber(member), member) end
end
if KEYS[2] ~= '' then merge(KEYS[2]) end
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', '(' .. (now - tonumber(ARGV[6])))
local active = false
for _, blockKey in ipairs({KEYS[3], KEYS[4]}) do
  if blockKey ~= '' and redis.call('EXISTS', blockKey) == 1 then
    local expires = redis.call('HGET', blockKey, 'expiresAt'); local level = redis.call('HGET', blockKey, 'level')
    if not expires or not level then return redis.error_reply('malformed hard-block state') end
    if tonumber(expires) > now and tonumber(level) >= 2 then active = true end
  end
end
local newCycle = not active
if newCycle then redis.call('ZADD', KEYS[1], now, tostring(now)) end
local cycleCount = redis.call('ZCARD', KEYS[1])
local pauseUntil = 0
for _, member in ipairs(redis.call('ZRANGE', KEYS[5], 0, -1)) do local sep = string.find(member, ':'); local start = tonumber(string.sub(member, 1, sep - 1)); local finish = tonumber(string.sub(member, sep + 1)); if finish > now then pauseUntil = math.max(pauseUntil, finish) end end
redis.call('ZREMRANGEBYSCORE', KEYS[5], '-inf', '(' .. (now - tonumber(ARGV[9])))
local activated = false
if newCycle and cycleCount >= tonumber(ARGV[7]) and pauseUntil == 0 then pauseUntil = now + tonumber(ARGV[8]); redis.call('ZADD', KEYS[5], now, tostring(now) .. ':' .. tostring(pauseUntil)); activated = true end
local expires = now + tonumber(ARGV[2])
redis.call('HSET', KEYS[3], 'level', ARGV[1], 'expiresAt', expires); redis.call('EXPIRE', KEYS[3], ARGV[2])
return {newCycle and 1 or 0, cycleCount, activated and 1 or 0, pauseUntil}
LUA;

    private const PAUSE_READ = <<<'LUA'
local now = tonumber(ARGV[2]); local from = tonumber(ARGV[1]); local cycles = {}; local pauses = {}
local function read(source)
  if source == '' or redis.call('EXISTS', source) == 0 then return end
  for _, member in ipairs(redis.call('ZRANGE', source, 0, -1)) do cycles[member] = true end
  for _, member in ipairs(redis.call('ZRANGE', KEYS[3], 0, -1)) do pauses[member] = true end
end
read(KEYS[1]); read(KEYS[2])
local elapsed = 0; local active = 0
for member in pairs(pauses) do local sep = string.find(member, ':'); local start = tonumber(string.sub(member, 1, sep - 1)); local finish = tonumber(string.sub(member, sep + 1)); if finish > now then active = math.max(active, finish) end; local left = math.max(from, start); local right = math.min(now, finish); if left < right then elapsed = elapsed + right - left end end
return {elapsed, active}
LUA;

    public function __construct(
        private readonly RedisCommandExecutorInterface $redis,
        private readonly string $namespace,
    ) {
        if (! preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $namespace)) {
            throw new RateLimiterException('Redis namespace contains invalid characters or length.');
        }
    }

    public function increment(string $key, int $ttlSeconds, int $amount = 1): int
    {
        $this->positive($ttlSeconds, 'Score TTL');
        $result = $this->eval(self::SCORE_INCREMENT, [$this->key('score', $key)], [$ttlSeconds, $amount]);
        return (int) $this->tuple($result, 2, 'score increment')[0];
    }

    public function get(string $key): ?RateLimitStateDTO
    {
        $result = $this->eval(self::SCORE_GET, [$this->key('score', $key)], []);
        if ($result === []) {
            return null;
        }
        $tuple = $this->tuple($result, 2, 'score state');
        return new RateLimitStateDTO((int) $tuple[0], (int) $tuple[1]);
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        $this->positive($ttlSeconds, 'Score TTL');
        $this->eval(self::SCORE_SET, [$this->key('score', $key)], [$ttlSeconds, $value]);
    }

    public function block(string $key, int $level, int $durationSeconds): void
    {
        $this->positive($durationSeconds, 'Block duration');
        $this->eval(self::BLOCK_SET, [$this->key('block', $key)], [$level, $durationSeconds]);
    }

    public function checkBlock(string $key): ?BlockStateDTO
    {
        $result = $this->eval(self::BLOCK_GET, [$this->key('block', $key)], []);
        if ($result === []) {
            return null;
        }
        $tuple = $this->tuple($result, 2, 'block state');
        return new BlockStateDTO((int) $tuple[0], (int) $tuple[1]);
    }

    public function getBudget(string $key): ?BudgetStateDTO
    {
        $result = $this->eval(self::BUDGET_GET, [$this->key('budget', $key)], []);
        if ($result === []) {
            return null;
        }
        $tuple = $this->tuple($result, 2, 'budget state');
        return new BudgetStateDTO((int) $tuple[0], (int) $tuple[1]);
    }

    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): BudgetStateDTO
    {
        $this->positive($epochDurationSeconds, 'Budget epoch duration');
        $result = $this->eval(self::BUDGET_INCREMENT, [$this->key('budget', $key)], [$epochDurationSeconds, $amount]);
        $tuple = $this->tuple($result, 2, 'budget increment');
        return new BudgetStateDTO((int) $tuple[0], (int) $tuple[1]);
    }

    public function incrementBudgetWithSeed(string $key, int $epochDurationSeconds, BudgetStateDTO $seed, int $amount = 1): BudgetStateDTO
    {
        $this->positive($epochDurationSeconds, 'Budget epoch duration');
        $result = $this->eval(self::BUDGET_SEED, [$this->key('budget', $key)], [$epochDurationSeconds, $seed->epochStart, $seed->count, $amount]);
        $tuple = $this->tuple($result, 2, 'seeded budget');
        return new BudgetStateDTO((int) $tuple[0], (int) $tuple[1]);
    }

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        $this->positive($ttlSeconds, 'Correlation TTL');
        return $this->integerResult($this->eval(self::DISTINCT_ADD, [$this->key('distinct', $key), $this->key('distinct-meta', $key)], [$ttlSeconds, $item]), 'distinct count');
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        $this->positive($ttlSeconds, 'Correlation TTL');
        return $this->integerResult($this->eval(self::WATCH_INCREMENT, [$this->key('watch', $key), $this->key('watch-meta', $key)], [$ttlSeconds]), 'watch count');
    }

    public function getWatchFlag(string $key): int
    {
        return $this->integerResult($this->eval(self::WATCH_GET, [$this->key('watch', $key)], []), 'watch count');
    }

    public function addDistinctBounded(string $key, string $item, int $ttlSeconds, int $maxDistinct): BoundedDistinctResultDTO
    {
        $snapshot = $this->addDistinctBoundedWithSnapshot($key, $item, $ttlSeconds, $maxDistinct);
        return new BoundedDistinctResultDTO($snapshot->count, $snapshot->accepted);
    }

    public function addDistinctBoundedWithSnapshot(string $key, string $item, int $ttlSeconds, int $maxDistinct): BoundedDistinctSnapshotDTO
    {
        $this->positive($ttlSeconds, 'Correlation TTL');
        $this->positive($maxDistinct, 'Correlation capacity');
        $result = $this->eval(self::BOUNDED_SNAPSHOT, [$this->key('distinct', $key), $this->key('distinct-meta', $key)], [$ttlSeconds, $item, $maxDistinct]);
        return $this->snapshot($result, $item, 'bounded snapshot');
    }

    public function addDistinctAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds): int
    {
        $snapshot = $this->addDistinctBoundedWithSnapshotAcrossRotation($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, PHP_INT_MAX);
        return $snapshot->count;
    }

    public function addDistinctBoundedAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct): BoundedDistinctResultDTO
    {
        $snapshot = $this->addDistinctBoundedWithSnapshotAcrossRotation($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct);
        return new BoundedDistinctResultDTO($snapshot->count, $snapshot->accepted);
    }

    public function addDistinctBoundedWithSnapshotAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct): BoundedDistinctSnapshotDTO
    {
        $this->positive($ttlSeconds, 'Correlation TTL');
        $this->positive($maxDistinct, 'Correlation capacity');
        $keys = [$this->key('distinct', $currentKey), $this->key('distinct-meta', $currentKey), $this->key('distinct', $bridgeKey), $this->key('distinct-meta', $bridgeKey), $this->key('distinct', $previousKey), $this->key('distinct-meta', $previousKey)];
        $result = $this->eval(self::ROTATED_SNAPSHOT, $keys, [self::BOUNDED_SNAPSHOT, $ttlSeconds, $currentMember, $previousMember, $maxDistinct]);
        return $this->snapshot($result, $currentMember, 'rotated bounded snapshot');
    }

    public function incrementWatchFlagAcrossRotation(string $currentKey, string $previousKey, int $ttlSeconds): int
    {
        $this->positive($ttlSeconds, 'Correlation TTL');
        $script = <<<'LUA'
local now = tonumber(redis.call('TIME')[1]); local previous = 0
if redis.call('EXISTS', KEYS[2]) == 1 then local expiry = redis.call('HGET', KEYS[3], 'expiresAt'); if not expiry then return redis.error_reply('malformed previous watch state') end; if tonumber(expiry) > now then local value = redis.call('GET', KEYS[2]); if not value or not tonumber(value) then return redis.error_reply('malformed previous watch value') end; previous = tonumber(value) end end
local exists = redis.call('EXISTS', KEYS[1]); if exists == 1 and redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed current watch state') end
local value = exists == 0 and 1 or redis.call('INCR', KEYS[1]); if exists == 0 then redis.call('EXPIRE', KEYS[1], ARGV[1]); redis.call('HSET', KEYS[4], 'expiresAt', now + tonumber(ARGV[1])); redis.call('EXPIRE', KEYS[4], ARGV[1]) end
return value + previous
LUA;
        return $this->integerResult($this->eval($script, [$this->key('watch', $currentKey), $this->key('watch', $previousKey), $this->key('watch-meta', $previousKey), $this->key('watch-meta', $currentKey)], [$ttlSeconds]), 'rotated watch count');
    }

    public function load(string $policyName): ?CircuitBreakerStateDTO
    {
        $raw = $this->redis->execute(['GET', $this->key('circuit', $policyName)]);
        if ($raw === null) {
            return null;
        }
        if (! is_string($raw)) {
            throw new RateLimiterException('Malformed circuit-breaker response.');
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RateLimiterException('Malformed circuit-breaker state.', 0, $exception);
        }
        if (! is_array($data) || ! is_string($data['status'] ?? null) || ! is_array($data['failures'] ?? null) || ! is_array($data['reEntries'] ?? null) || ! is_int($data['lastFailure'] ?? null) || ! is_int($data['openSince'] ?? null) || ! is_int($data['lastSuccess'] ?? null) || ! is_int($data['failClosedUntil'] ?? null)) {
            throw new RateLimiterException('Malformed circuit-breaker state.');
        }
        $failures = [];
        foreach ($data['failures'] as $value) {
            if (! is_int($value)) {
                throw new RateLimiterException('Malformed circuit-breaker failures.');
            } $failures[] = $value;
        }
        $reEntries = [];
        foreach ($data['reEntries'] as $value) {
            if (! is_int($value)) {
                throw new RateLimiterException('Malformed circuit-breaker re-entry state.');
            } $reEntries[] = $value;
        }
        return new CircuitBreakerStateDTO($data['status'], $failures, $data['lastFailure'], $data['openSince'], $data['lastSuccess'], $reEntries, $data['failClosedUntil']);
    }

    public function save(string $policyName, CircuitBreakerStateDTO $state): void
    {
        $raw = json_encode($state, JSON_THROW_ON_ERROR);
        $this->redis->execute(['SET', $this->key('circuit', $policyName), $raw]);
    }

    public function acquireProbeLease(string $policyName, int $now, int $leaseSeconds): bool
    {
        $this->positive($leaseSeconds, 'Probe lease duration');
        $result = $this->eval(self::LEASE, [$this->key('probe', $policyName)], [$now, $leaseSeconds]);
        if (! is_int($result) && ! is_string($result)) {
            throw new RateLimiterException('Malformed probe lease response.');
        }
        return (int) $result === 1;
    }

    public function blockWithCycleTracking(string $currentKey, ?string $previousKey, int $level, int $durationSeconds, int $now, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds): HardBlockCycleResultDTO
    {
        foreach ([$durationSeconds, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds] as $value) {
            $this->positive($value, 'Hard-block cycle parameter');
        }
        if ($level < 2) {
            throw new RateLimiterException('Hard-block cycle level must be at least 2.');
        }
        $previousKey = $previousKey === $currentKey ? null : $previousKey;
        $keys = [$this->key('cycle', $currentKey), $previousKey === null ? '' : $this->key('cycle', $previousKey), $this->key('block', $currentKey), $previousKey === null ? '' : $this->key('block', $previousKey), $this->key('pause', $currentKey)];
        $result = $this->eval(self::HARD_BLOCK, $keys, [$level, $durationSeconds, $currentKey, $previousKey ?? '', $now, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds]);
        $tuple = $this->tuple($result, 4, 'hard-block cycle result');
        return new HardBlockCycleResultDTO((int) $tuple[0] === 1, (int) $tuple[1], (int) $tuple[2] === 1, (int) $tuple[3]);
    }

    public function readDecayPauseState(string $currentKey, ?string $previousKey, int $fromTimestamp, int $now): DecayPauseStateDTO
    {
        $keys = [$this->key('cycle', $currentKey), $previousKey === null ? '' : $this->key('cycle', $previousKey), $this->key('pause', $currentKey)];
        $result = $this->eval(self::PAUSE_READ, $keys, [$fromTimestamp, $now]);
        $tuple = $this->tuple($result, 2, 'decay pause state');
        return new DecayPauseStateDTO((int) $tuple[0], (int) $tuple[1]);
    }

    public function isHealthy(): bool
    {
        try {
            $result = $this->redis->execute(['PING']);
            return is_string($result) && strtoupper($result) === 'PONG';
        } catch (\Throwable) {
            return false;
        }
    }

    private function key(string $family, string $logical): string
    {
        return self::PREFIX . ':' . hash('sha256', $this->namespace) . ':' . $family . ':' . hash('sha256', $logical);
    }

    /**
     * @param list<string> $keys
     * @param list<int|string|float> $args
     */
    private function eval(string $script, array $keys, array $args): mixed
    {
        /** @var non-empty-list<int|string|float> $command */
        $command = array_merge(['EVAL', $script, count($keys)], $keys, $args);
        return $this->redis->execute($command);
    }

    private function positive(int $value, string $label): void
    {
        if ($value <= 0) {
            throw new RateLimiterException($label . ' must be positive.');
        }
    }

    private function integerResult(mixed $value, string $label): int
    {
        if (! is_int($value) && ! is_string($value) && ! is_float($value)) {
            throw new RateLimiterException('Malformed ' . $label . ' response.');
        }
        if (! is_numeric($value)) {
            throw new RateLimiterException('Malformed ' . $label . ' response.');
        }
        return (int) $value;
    }

    /** @return list<int|string|float> */
    private function tuple(mixed $value, int $minimum, string $label): array
    {
        if (! is_array($value) || count($value) < $minimum) {
            throw new RateLimiterException('Malformed ' . $label . ' response.');
        }
        $tuple = [];
        foreach (array_values($value) as $part) {
            if (! is_int($part) && ! is_string($part) && ! is_float($part)) {
                throw new RateLimiterException('Malformed ' . $label . ' response.');
            }
            $tuple[] = $part;
        }
        return $tuple;
    }

    private function snapshot(mixed $value, string $item, string $label): BoundedDistinctSnapshotDTO
    {
        $tuple = $this->tuple($value, 4, $label);
        $members = array_map('strval', array_slice($tuple, 4));
        if ((int) $tuple[0] !== count($members)) {
            throw new RateLimiterException('Malformed ' . $label . ' members.');
        }
        return new BoundedDistinctSnapshotDTO((int) $tuple[0], (int) $tuple[1] === 1, (int) $tuple[2] === 1, $members, (int) $tuple[3]);
    }
}
