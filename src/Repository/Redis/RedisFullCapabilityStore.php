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
use Maatify\RateLimiter\DTO\GenerationBoundScoreMutationDTO;
use Maatify\RateLimiter\DTO\GenerationBoundScoreStateDTO;
use Maatify\RateLimiter\DTO\PostPunishmentReentryStateDTO;
use Maatify\RateLimiter\DTO\PunishmentLifecycleTransitionDTO;
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
if redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed score state') end
local value = redis.call('HGET', KEYS[1], 'value')
local updated = redis.call('HGET', KEYS[1], 'updatedAt')
if not value or not updated then
  return redis.error_reply('malformed score state')
end
value = tonumber(value)
updated = tonumber(updated)
if not value or value ~= math.floor(value) or not updated or updated ~= math.floor(updated) then return redis.error_reply('malformed score state') end
value = value + tonumber(ARGV[2])
redis.call('HSET', KEYS[1], 'value', value, 'updatedAt', now)
return {value, now}
LUA;

    private const SCORE_GET = <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 0 then return {} end
if redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed score state') end
local value = redis.call('HGET', KEYS[1], 'value')
local updated = redis.call('HGET', KEYS[1], 'updatedAt')
if not value or not updated then return redis.error_reply('malformed score state') end
value = tonumber(value); updated = tonumber(updated)
if not value or value ~= math.floor(value) or not updated or updated ~= math.floor(updated) then return redis.error_reply('malformed score state') end
return {value, updated}
LUA;

    private const SCORE_SET = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
redis.call('HSET', KEYS[1], 'value', ARGV[2], 'updatedAt', now)
redis.call('EXPIRE', KEYS[1], ARGV[1])
return now
LUA;

    private const LIFECYCLE_MUTATE = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local current = KEYS[1]; local previous = KEYS[2]
local exists = redis.call('EXISTS', current)
local source = current
if exists == 0 and previous ~= '' and redis.call('EXISTS', previous) == 1 then source = previous end
local expectedSource = ARGV[1]
if expectedSource == '' and (redis.call('EXISTS', current) == 1 or (previous ~= '' and redis.call('EXISTS', previous) == 1)) then return {0} end
if expectedSource ~= '' and expectedSource ~= source then return {0} end
local observedGeneration = redis.call('HGET', source, 'generation')
local observedUpdated = redis.call('HGET', source, 'updatedAt')
local observedValue = redis.call('HGET', source, 'value')
if not observedValue or not observedUpdated then
  if redis.call('EXISTS', source) == 0 then
    local ttl = tonumber(ARGV[5]); if ttl <= 0 then return redis.error_reply('invalid score TTL') end
    redis.call('HSET', current, 'value', ARGV[6], 'updatedAt', now, 'generation', 1, 'expiresAt', now + ttl)
    redis.call('EXPIRE', current, ttl)
    return {1, ARGV[6], now, now + ttl, 1}
  end
  return redis.error_reply('malformed generation-bound score state')
end
if expectedSource ~= '' then
  if not observedValue or observedValue ~= ARGV[2] or not observedUpdated or observedUpdated ~= ARGV[3] then return {0} end
  if ARGV[4] ~= '' and (not observedGeneration or observedGeneration ~= ARGV[4]) then return {0} end
end
local requestedTtl = tonumber(ARGV[5]); if requestedTtl <= 0 then return redis.error_reply('invalid score TTL') end
local generation = source == current and (observedGeneration and tonumber(observedGeneration) + 1 or 1) or 1
local ttl = source == current and redis.call('TTL', current) or requestedTtl
if ttl <= 0 then return {0} end
local expiry = source == current and redis.call('HGET', current, 'expiresAt') or nil
if expiry and (not tonumber(expiry) or tonumber(expiry) ~= math.floor(tonumber(expiry))) then return redis.error_reply('malformed score expiry') end
expiry = expiry and tonumber(expiry) or now + ttl
redis.call('HSET', current, 'value', ARGV[6], 'updatedAt', now, 'generation', generation, 'expiresAt', expiry)
redis.call('EXPIRE', current, ttl)
if KEYS[3] ~= '' then redis.call('DEL', KEYS[3]) end
return {1, ARGV[6], now, expiry, generation}
LUA;

    private const LIFECYCLE_READ = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local source = KEYS[1]
if redis.call('EXISTS', source) == 0 then source = KEYS[2] end
if source == '' or redis.call('EXISTS', source) == 0 then return {} end
if redis.call('TTL', source) <= 0 then return {} end
local value = redis.call('HGET', source, 'value'); local updated = redis.call('HGET', source, 'updatedAt')
if not value or not updated or not tonumber(value) or tonumber(value) ~= math.floor(tonumber(value)) or not tonumber(updated) or tonumber(updated) ~= math.floor(tonumber(updated)) then return redis.error_reply('malformed generation-bound score state') end
local expiry = redis.call('HGET', source, 'expiresAt') or (now + redis.call('TTL', source))
if not tonumber(expiry) or tonumber(expiry) ~= math.floor(tonumber(expiry)) or tonumber(expiry) <= now then return redis.error_reply('malformed generation-bound score expiry') end
local generation = redis.call('HGET', source, 'generation') or ''
if generation ~= '' and (not tonumber(generation) or tonumber(generation) ~= math.floor(tonumber(generation)) or tonumber(generation) <= 0) then return redis.error_reply('malformed generation') end
local active = false
for _, blockKey in ipairs({KEYS[3], KEYS[4]}) do
  if blockKey ~= '' and redis.call('EXISTS', blockKey) == 1 then
    local blockExpiry = redis.call('HGET', blockKey, 'expiresAt'); local level = redis.call('HGET', blockKey, 'level')
    if not blockExpiry or not level or not tonumber(blockExpiry) or tonumber(blockExpiry) ~= math.floor(tonumber(blockExpiry)) or not tonumber(level) or tonumber(level) ~= math.floor(tonumber(level)) then return redis.error_reply('malformed hard-block state') end
    if tonumber(blockExpiry) > now and tonumber(level) >= 2 then active = true end
  end
end
local evidenceGeneration = redis.call('HGET', source, 'reentryGeneration') or ''
local evidenceId = redis.call('HGET', source, 'reentryId') or ''
local evidenceUntil = redis.call('HGET', source, 'reentryValidUntil') or ''
if active or generation == '' or evidenceGeneration ~= generation or evidenceId == '' or evidenceUntil == '' or not tonumber(evidenceUntil) or tonumber(evidenceUntil) ~= tonumber(expiry) or tonumber(evidenceUntil) <= now then evidenceId = ''; evidenceUntil = '' end
return {source == KEYS[1] and 1 or 2, tonumber(value), tonumber(updated), tonumber(expiry), generation, evidenceId, evidenceUntil}
LUA;

    private const LIFECYCLE_CLAIM = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
for _, blockKey in ipairs({KEYS[3], KEYS[4]}) do
  if blockKey ~= '' and redis.call('EXISTS', blockKey) == 1 then
    local expires = tonumber(redis.call('HGET', blockKey, 'expiresAt'))
    if expires and expires > now then return 0 end
  end
end
for _, key in ipairs({KEYS[1], KEYS[2]}) do
  if key ~= '' and redis.call('EXISTS', key) == 1 then
    local generation = redis.call('HGET', key, 'generation'); local id = redis.call('HGET', key, 'reentryId'); local validUntil = redis.call('HGET', key, 'reentryValidUntil'); local evidenceGeneration = redis.call('HGET', key, 'reentryGeneration')
    if id and validUntil and generation and evidenceGeneration == generation then
      validUntil = tonumber(validUntil)
      if validUntil and validUntil > now and id == ARGV[1] then
        local markerTtl = math.max(1, validUntil - now)
        if redis.call('SET', KEYS[5], id, 'NX', 'EX', markerTtl) then return 1 end
        return 0
      end
    end
  end
end
return 0
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
if redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed block state') end
local level = redis.call('HGET', KEYS[1], 'level')
local expires = redis.call('HGET', KEYS[1], 'expiresAt')
if not level or not expires then return redis.error_reply('malformed block state') end
level = tonumber(level); expires = tonumber(expires)
if not level or level ~= math.floor(level) or not expires or expires ~= math.floor(expires) then return redis.error_reply('malformed block state') end
if expires <= tonumber(redis.call('TIME')[1]) then
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
local exists = redis.call('EXISTS', KEYS[1])
if exists == 1 and redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed budget state') end
if exists == 1 and (not count or not start or not duration) then return redis.error_reply('malformed budget state') end
if exists == 1 then
  count = tonumber(count); start = tonumber(start); duration = tonumber(duration)
  if not count or count ~= math.floor(count) or not start or start ~= math.floor(start) or not duration or duration <= 0 or duration ~= math.floor(duration) then return redis.error_reply('malformed budget state') end
end
if exists == 1 and now < start + duration then
  count = count + tonumber(ARGV[2])
  redis.call('HSET', KEYS[1], 'count', count)
  return {count, start}
end
if exists == 1 then redis.call('DEL', KEYS[1]) end
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
if redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed budget state') end
count = tonumber(count); start = tonumber(start); duration = tonumber(duration)
if not count or count ~= math.floor(count) or not start or start ~= math.floor(start) or not duration or duration <= 0 or duration ~= math.floor(duration) then return redis.error_reply('malformed budget state') end
if tonumber(redis.call('TIME')[1]) >= start + duration then
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
local exists = redis.call('EXISTS', KEYS[1])
if exists == 1 and redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed budget state') end
if exists == 1 and (not count or not start or not duration) then return redis.error_reply('malformed budget state') end
if exists == 1 then
  count = tonumber(count); start = tonumber(start); duration = tonumber(duration)
  if not count or count ~= math.floor(count) or not start or start ~= math.floor(start) or not duration or duration <= 0 or duration ~= math.floor(duration) then return redis.error_reply('malformed budget state') end
end
if exists == 1 and now < start + duration then
  count = count + tonumber(ARGV[4])
  redis.call('HSET', KEYS[1], 'count', count)
  return {count, start}
end
if exists == 1 then redis.call('DEL', KEYS[1]) end
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
local now = tonumber(redis.call('TIME')[1])
local exists = redis.call('EXISTS', KEYS[1])
local metaExists = redis.call('EXISTS', KEYS[2])
if exists == 1 and redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed distinct state') end
if exists == 0 and metaExists == 1 then return redis.error_reply('malformed distinct metadata') end
if exists == 1 and redis.call('SCARD', KEYS[1]) == 0 then return redis.error_reply('malformed distinct state') end
if exists == 1 then
  local expires = tonumber(redis.call('HGET', KEYS[2], 'expiresAt'))
  if not expires or expires ~= math.floor(expires) or expires <= now or redis.call('TTL', KEYS[2]) < 0 then return redis.error_reply('malformed distinct metadata') end
end
if exists == 0 then redis.call('SADD', KEYS[1], ARGV[2]); redis.call('EXPIRE', KEYS[1], ARGV[1]); redis.call('HSET', KEYS[2], 'expiresAt', tonumber(redis.call('TIME')[1]) + tonumber(ARGV[1])); redis.call('EXPIRE', KEYS[2], ARGV[1])
else redis.call('SADD', KEYS[1], ARGV[2]) end
return redis.call('SCARD', KEYS[1])
LUA;

    private const WATCH_INCREMENT = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local exists = redis.call('EXISTS', KEYS[1])
if exists == 1 and redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed watch state') end
local metaExists = redis.call('EXISTS', KEYS[2])
if exists == 0 and metaExists == 1 then return redis.error_reply('malformed watch metadata') end
if exists == 1 then
  local expires = tonumber(redis.call('HGET', KEYS[2], 'expiresAt'))
  if not expires or expires ~= math.floor(expires) or expires <= now or redis.call('TTL', KEYS[2]) < 0 then return redis.error_reply('malformed watch metadata') end
end
local value
if exists == 0 then value = 1; redis.call('SET', KEYS[1], value, 'EX', ARGV[1]); redis.call('HSET', KEYS[2], 'expiresAt', tonumber(redis.call('TIME')[1]) + tonumber(ARGV[1])); redis.call('EXPIRE', KEYS[2], ARGV[1])
else value = redis.call('INCR', KEYS[1]) end
return value
LUA;

    private const WATCH_GET = <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 0 then return 0 end
if redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed watch state') end
local value = redis.call('GET', KEYS[1])
if not value or not tonumber(value) or tonumber(value) ~= math.floor(tonumber(value)) then return redis.error_reply('malformed watch value') end
return value
LUA;

    private const BOUNDED_SNAPSHOT = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local exists = redis.call('EXISTS', KEYS[1])
if exists == 1 and redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed bounded state') end
local metaExists = redis.call('EXISTS', KEYS[2])
if exists == 0 and metaExists == 1 then return redis.error_reply('malformed bounded metadata') end
if exists == 1 and redis.call('SCARD', KEYS[1]) == 0 then return redis.error_reply('malformed bounded state') end
local expires = nil
if exists == 1 then
  expires = tonumber(redis.call('HGET', KEYS[2], 'expiresAt'))
  if not expires or expires ~= math.floor(expires) or expires <= now or redis.call('TTL', KEYS[2]) < 0 then return redis.error_reply('malformed bounded metadata') end
end
if redis.call('SISMEMBER', KEYS[1], ARGV[2]) == 1 then
  local members = redis.call('SMEMBERS', KEYS[1]); table.sort(members)
  return {#members, 1, 0, tonumber(expires), unpack(members)}
end
local count = redis.call('SCARD', KEYS[1])
if count >= tonumber(ARGV[3]) then
  local members = redis.call('SMEMBERS', KEYS[1]); table.sort(members)
  return {count, 0, 0, tonumber(expires), unpack(members)}
end
if exists == 0 then redis.call('SADD', KEYS[1], ARGV[2]); redis.call('EXPIRE', KEYS[1], ARGV[1]); redis.call('HSET', KEYS[2], 'expiresAt', now + tonumber(ARGV[1])); redis.call('EXPIRE', KEYS[2], ARGV[1]) else redis.call('SADD', KEYS[1], ARGV[2]) end
local members = redis.call('SMEMBERS', KEYS[1]); table.sort(members)
return {#members, 1, 1, exists == 0 and now + tonumber(ARGV[1]) or tonumber(expires), unpack(members)}
LUA;

    private const ROTATED_SNAPSHOT = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local prevExists = redis.call('EXISTS', KEYS[5])
local prevMetaExists = redis.call('EXISTS', KEYS[6])
local prevExpiry = false
if prevExists == 0 and prevMetaExists == 1 then return redis.error_reply('malformed previous bounded metadata') end
if prevExists == 1 then
  if redis.call('TTL', KEYS[5]) < 0 or redis.call('TTL', KEYS[6]) < 0 then return redis.error_reply('malformed previous bounded state') end
  if redis.call('SCARD', KEYS[5]) == 0 then return redis.error_reply('malformed previous bounded state') end
  prevExpiry = redis.call('HGET', KEYS[6], 'expiresAt')
  prevExpiry = tonumber(prevExpiry)
  if not prevExpiry or prevExpiry ~= math.floor(prevExpiry) then return redis.error_reply('malformed previous bounded state') end
  if prevExpiry <= now then prevExists = 0 end
end
if prevExists == 0 then
  local exists = redis.call('EXISTS', KEYS[1])
  local metaExists = redis.call('EXISTS', KEYS[2])
  if exists == 1 and redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed bounded state') end
  if exists == 0 and metaExists == 1 then return redis.error_reply('malformed current bounded metadata') end
  local currentExpiry = nil
  if exists == 1 then
    currentExpiry = tonumber(redis.call('HGET', KEYS[2], 'expiresAt'))
    if not currentExpiry or currentExpiry ~= math.floor(currentExpiry) or currentExpiry <= now or redis.call('TTL', KEYS[2]) < 0 then return redis.error_reply('malformed current bounded state') end
  end
  if redis.call('SISMEMBER', KEYS[1], ARGV[3]) == 1 then
    local members = redis.call('SMEMBERS', KEYS[1]); table.sort(members)
    return {#members, 1, 0, currentExpiry, unpack(members)}
  end
  local count = redis.call('SCARD', KEYS[1])
  if count >= tonumber(ARGV[5]) then
    local members = redis.call('SMEMBERS', KEYS[1]); table.sort(members)
    return {count, 0, 0, currentExpiry, unpack(members)}
  end
  if exists == 0 then redis.call('SADD', KEYS[1], ARGV[3]); redis.call('EXPIRE', KEYS[1], ARGV[2]); redis.call('HSET', KEYS[2], 'expiresAt', now + tonumber(ARGV[2])); redis.call('EXPIRE', KEYS[2], ARGV[2]) else redis.call('SADD', KEYS[1], ARGV[3]) end
  local members = redis.call('SMEMBERS', KEYS[1]); table.sort(members)
  return {#members, 1, 1, redis.call('HGET', KEYS[2], 'expiresAt') or now + tonumber(ARGV[2]), unpack(members)}
end
local previous = redis.call('SMEMBERS', KEYS[5]); table.sort(previous)
local currentExists = redis.call('EXISTS', KEYS[1])
local bridgeExists = redis.call('EXISTS', KEYS[3])
if (currentExists == 1 and redis.call('TTL', KEYS[1]) < 0) or (bridgeExists == 1 and redis.call('TTL', KEYS[3]) < 0) then return redis.error_reply('malformed rotated bounded state') end
if (currentExists == 1 and redis.call('SCARD', KEYS[1]) == 0) or (bridgeExists == 1 and redis.call('SCARD', KEYS[3]) == 0) then return redis.error_reply('malformed rotated bounded state') end
local currentMetaExists = redis.call('EXISTS', KEYS[2])
local bridgeMetaExists = redis.call('EXISTS', KEYS[4])
if currentExists == 0 and currentMetaExists == 1 then return redis.error_reply('malformed current bounded metadata') end
if bridgeExists == 0 and bridgeMetaExists == 1 then return redis.error_reply('malformed bridge metadata') end
local currentExpiry = nil
if currentExists == 1 then
  currentExpiry = tonumber(redis.call('HGET', KEYS[2], 'expiresAt'))
  if not currentExpiry or currentExpiry ~= math.floor(currentExpiry) or currentExpiry <= now or redis.call('TTL', KEYS[2]) < 0 then return redis.error_reply('malformed current bounded state') end
end
local bridgeExpiry = nil
if bridgeExists == 1 then
  bridgeExpiry = tonumber(redis.call('HGET', KEYS[4], 'expiresAt'))
  if not bridgeExpiry or bridgeExpiry ~= math.floor(bridgeExpiry) or bridgeExpiry <= now or redis.call('TTL', KEYS[4]) < 0 or bridgeExpiry > prevExpiry then return redis.error_reply('malformed bridge state') end
end
local bridge = bridgeExists == 1 and redis.call('SMEMBERS', KEYS[3]) or {}
table.sort(bridge)
local members = {}
for _, member in ipairs(previous) do members[#members + 1] = member end
for _, member in ipairs(bridge) do members[#members + 1] = member end
for _, member in ipairs(previous) do for _, other in ipairs(bridge) do if member == other then return redis.error_reply('bridge overlaps previous state') end end end
if #members > tonumber(ARGV[5]) then return redis.error_reply('rotated bounded state exceeds capacity') end
local bridgeKnown = redis.call('SISMEMBER', KEYS[3], ARGV[3]) == 1
local previousKnown = false
for _, member in ipairs(previous) do if member == ARGV[4] then previousKnown = true end end
local known = bridgeKnown or previousKnown
local added = false
local function ensureCurrent()
  if currentExists == 0 then
    redis.call('SADD', KEYS[1], ARGV[3])
    redis.call('EXPIRE', KEYS[1], ARGV[2])
    redis.call('HSET', KEYS[2], 'expiresAt', now + tonumber(ARGV[2]))
    redis.call('EXPIRE', KEYS[2], ARGV[2])
    currentExists = 1
  else
    redis.call('SADD', KEYS[1], ARGV[3])
  end
end
local function ensureBridge()
  if bridgeExists == 0 then
    bridgeExpiry = math.min(now + tonumber(ARGV[2]), tonumber(prevExpiry))
    redis.call('SADD', KEYS[3], ARGV[3])
    redis.call('EXPIRE', KEYS[3], math.max(1, bridgeExpiry - now))
    redis.call('HSET', KEYS[4], 'expiresAt', bridgeExpiry)
    redis.call('EXPIRE', KEYS[4], math.max(1, bridgeExpiry - now))
    bridgeExists = 1
  else
    redis.call('SADD', KEYS[3], ARGV[3])
  end
end
if known then
  ensureCurrent()
elseif #members < tonumber(ARGV[5]) then
  ensureCurrent()
  ensureBridge()
  added = true
  members[#members + 1] = ARGV[3]
end
return {#members, known or added and 1 or 0, added and 1 or 0, tonumber(prevExpiry), unpack(members)}
LUA;

    private const LEASE = <<<'LUA'
local current = redis.call('GET', KEYS[1])
if current then
  local ttl = redis.call('TTL', KEYS[1])
  local expires = tonumber(current)
  if ttl < 0 or not expires or expires ~= math.floor(expires) then return redis.error_reply('malformed probe lease') end
  if expires > tonumber(ARGV[1]) then return 0 end
end
redis.call('SET', KEYS[1], tonumber(ARGV[1]) + tonumber(ARGV[2]), 'EX', ARGV[2])
return 1
LUA;

    private const HARD_BLOCK = <<<'LUA'
local lifecycleGeneration = ARGV[10] or ''
local lifecycleId = ARGV[11] or ''
local now = lifecycleGeneration ~= '' and tonumber(redis.call('TIME')[1]) or tonumber(ARGV[5])
local retention = tonumber(ARGV[9])
local cycleWindow = tonumber(ARGV[6])
if lifecycleGeneration ~= '' then
  local scoreKey = KEYS[7]
  if scoreKey == '' or redis.call('EXISTS', scoreKey) == 0 then return {0} end
  local generation = redis.call('HGET', scoreKey, 'generation'); local value = redis.call('HGET', scoreKey, 'value'); local updated = redis.call('HGET', scoreKey, 'updatedAt'); local scoreExpiry = redis.call('HGET', scoreKey, 'expiresAt')
  if not generation or not value or not updated or not scoreExpiry then return redis.error_reply('malformed lifecycle score state') end
  if not tonumber(generation) or tonumber(generation) ~= math.floor(tonumber(generation)) or generation ~= lifecycleGeneration then return {0} end
  if not tonumber(value) or tonumber(value) ~= math.floor(tonumber(value)) or not tonumber(updated) or tonumber(updated) ~= math.floor(tonumber(updated)) or not tonumber(scoreExpiry) or tonumber(scoreExpiry) ~= math.floor(tonumber(scoreExpiry)) or tonumber(scoreExpiry) <= now then return redis.error_reply('malformed lifecycle score state') end
  if lifecycleId == '' or string.len(lifecycleId) ~= 32 then return redis.error_reply('malformed lifecycle id') end
end
local function validateCycles(key)
  if key == '' or redis.call('EXISTS', key) == 0 then return end
  if redis.call('ZCARD', key) == 0 then error('malformed cycle history') end
  for _, member in ipairs(redis.call('ZRANGE', key, 0, -1)) do
    local score = tonumber(redis.call('ZSCORE', key, member))
    local timestamp = tonumber(member)
    if not score or score ~= math.floor(score) or not timestamp or timestamp ~= math.floor(timestamp) or score ~= timestamp then error('malformed cycle history') end
  end
end
local function mergeCycles(source, target)
  if source == '' or redis.call('EXISTS', source) == 0 then return end
  validateCycles(source)
  for _, member in ipairs(redis.call('ZRANGE', source, 0, -1)) do
    redis.call('ZADD', target, tonumber(redis.call('ZSCORE', source, member)), member)
  end
end
local function extendUntil(key, target)
  if key == '' or redis.call('EXISTS', key) == 0 then return end
  local ttl = redis.call('TTL', key)
  local needed = math.max(1, target - now)
  if ttl < needed then redis.call('EXPIRE', key, needed) end
end
validateCycles(KEYS[1])
if KEYS[2] ~= '' then mergeCycles(KEYS[2], KEYS[1]) end
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', '(' .. (now - tonumber(ARGV[6])))
validateCycles(KEYS[1])
local active = false
for _, blockKey in ipairs({KEYS[3], KEYS[4]}) do
  if blockKey ~= '' and redis.call('EXISTS', blockKey) == 1 then
    if redis.call('TTL', blockKey) < 0 then return redis.error_reply('malformed hard-block state') end
    local expires = redis.call('HGET', blockKey, 'expiresAt'); local level = redis.call('HGET', blockKey, 'level')
    if not expires or not level then return redis.error_reply('malformed hard-block state') end
    expires = tonumber(expires); level = tonumber(level)
    if not expires or expires ~= math.floor(expires) or not level or level ~= math.floor(level) then return redis.error_reply('malformed hard-block state') end
    if expires > now and level >= 2 then active = true end
  end
end
local newCycle = not active
if newCycle then redis.call('ZADD', KEYS[1], now, tostring(now)) end
local cycleCount = redis.call('ZCARD', KEYS[1])
local latestCycle = redis.call('ZREVRANGE', KEYS[1], 0, 0, 'WITHSCORES')
if #latestCycle == 2 then
  local latestScore = tonumber(latestCycle[2])
  if not latestScore or latestScore ~= math.floor(latestScore) then return redis.error_reply('malformed cycle history') end
  extendUntil(KEYS[1], latestScore + cycleWindow)
end
local pauseUntil = 0
local function validatePauses(key)
  if key == '' or redis.call('EXISTS', key) == 0 then return end
  for _, member in ipairs(redis.call('ZRANGE', key, 0, -1)) do
    local sep = string.find(member, ':')
    if not sep then error('malformed pause history') end
    local start = tonumber(string.sub(member, 1, sep - 1)); local finish = tonumber(string.sub(member, sep + 1)); local score = tonumber(redis.call('ZSCORE', key, member))
    if not start or start ~= math.floor(start) or not finish or finish ~= math.floor(finish) or finish < start or not score or score ~= math.floor(score) or score ~= start then error('malformed pause history') end
  end
end
local function mergePauses(source, target)
  if source == '' or redis.call('EXISTS', source) == 0 then return end
  validatePauses(source)
  for _, member in ipairs(redis.call('ZRANGE', source, 0, -1)) do
    redis.call('ZADD', target, tonumber(redis.call('ZSCORE', source, member)), member)
  end
end
validatePauses(KEYS[5])
if KEYS[6] ~= '' then mergePauses(KEYS[6], KEYS[5]) end
validatePauses(KEYS[5])
local pauseCutoff = now - retention
local latestPauseUntil = 0
if redis.call('EXISTS', KEYS[5]) == 1 then
  for _, member in ipairs(redis.call('ZRANGE', KEYS[5], 0, -1)) do
    local sep = string.find(member, ':')
    if not sep then return redis.error_reply('malformed pause history') end
    local finish = tonumber(string.sub(member, sep + 1))
    if not finish or finish ~= math.floor(finish) then return redis.error_reply('malformed pause history') end
    if finish <= pauseCutoff then redis.call('ZREM', KEYS[5], member) else
      if finish > now then pauseUntil = math.max(pauseUntil, finish) end
      latestPauseUntil = math.max(latestPauseUntil, finish)
    end
  end
end
local activated = false
if newCycle and cycleCount >= tonumber(ARGV[7]) and pauseUntil == 0 then
  pauseUntil = now + tonumber(ARGV[8])
  redis.call('ZADD', KEYS[5], now, tostring(now) .. ':' .. tostring(pauseUntil))
  latestPauseUntil = math.max(latestPauseUntil, pauseUntil)
  activated = true
end
if latestPauseUntil > 0 then extendUntil(KEYS[5], latestPauseUntil + retention) end
local expires = now + tonumber(ARGV[2])
redis.call('HSET', KEYS[3], 'level', ARGV[1], 'expiresAt', expires); redis.call('EXPIRE', KEYS[3], ARGV[2])
if lifecycleGeneration ~= '' then
  local scoreKey = KEYS[7]
  local storedId = redis.call('HGET', scoreKey, 'reentryId')
  local actualId = storedId or lifecycleId
  redis.call('HSET', scoreKey, 'reentryId', actualId, 'reentryValidUntil', redis.call('HGET', scoreKey, 'expiresAt'), 'reentryGeneration', lifecycleGeneration)
  return {newCycle and 1 or 0, cycleCount, activated and 1 or 0, pauseUntil, expires, 1, actualId, redis.call('HGET', scoreKey, 'expiresAt'), lifecycleGeneration}
end
return {newCycle and 1 or 0, cycleCount, activated and 1 or 0, pauseUntil}
LUA;

    private const PAUSE_READ = <<<'LUA'
local now = tonumber(ARGV[2]); local from = tonumber(ARGV[1]); local retention = 86400; local intervals = {}
local function read(source)
  if source == '' or redis.call('EXISTS', source) == 0 then return end
  if redis.call('TTL', source) < 0 then error('malformed pause history') end
  for _, member in ipairs(redis.call('ZRANGE', source, 0, -1)) do
    local sep = string.find(member, ':')
    if not sep then error('malformed pause history') end
    local start = tonumber(string.sub(member, 1, sep - 1)); local finish = tonumber(string.sub(member, sep + 1)); local score = tonumber(redis.call('ZSCORE', source, member))
    if not start or start ~= math.floor(start) or not finish or finish ~= math.floor(finish) or finish < start or not score or score ~= math.floor(score) or score ~= start then error('malformed pause history') end
    if finish > now - retention then intervals[#intervals + 1] = {start, finish} end
  end
end
read(KEYS[3]); read(KEYS[4])
table.sort(intervals, function(left, right) return left[1] < right[1] or (left[1] == right[1] and left[2] < right[2]) end)
local elapsed = 0; local active = 0; local mergedStart = nil; local mergedEnd = nil
for _, interval in ipairs(intervals) do
  local start = interval[1]; local finish = interval[2]
  if finish > now then active = math.max(active, finish) end
  local left = math.max(from, start); local right = math.min(now, finish)
  if left < right then
    if mergedStart == nil then mergedStart = left; mergedEnd = right
    elseif left <= mergedEnd then mergedEnd = math.max(mergedEnd, right)
    else elapsed = elapsed + mergedEnd - mergedStart; mergedStart = left; mergedEnd = right end
  end
end
if mergedStart ~= nil then elapsed = elapsed + mergedEnd - mergedStart end
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
        return $this->integerValue($this->tuple($result, 2, 'score increment')[0], 'score value');
    }

    public function get(string $key): ?RateLimitStateDTO
    {
        $result = $this->eval(self::SCORE_GET, [$this->key('score', $key)], []);
        if ($result === []) {
            return null;
        }
        $tuple = $this->tuple($result, 2, 'score state');
        return new RateLimitStateDTO(
            $this->integerValue($tuple[0], 'score value'),
            $this->integerValue($tuple[1], 'score updatedAt'),
        );
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
        return new BlockStateDTO(
            $this->integerValue($tuple[0], 'block level'),
            $this->integerValue($tuple[1], 'block expiresAt'),
        );
    }

    public function getBudget(string $key): ?BudgetStateDTO
    {
        $result = $this->eval(self::BUDGET_GET, [$this->key('budget', $key)], []);
        if ($result === []) {
            return null;
        }
        $tuple = $this->tuple($result, 2, 'budget state');
        return new BudgetStateDTO(
            $this->integerValue($tuple[0], 'budget count'),
            $this->integerValue($tuple[1], 'budget epochStart'),
        );
    }

    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): BudgetStateDTO
    {
        $this->positive($epochDurationSeconds, 'Budget epoch duration');
        $result = $this->eval(self::BUDGET_INCREMENT, [$this->key('budget', $key)], [$epochDurationSeconds, $amount]);
        $tuple = $this->tuple($result, 2, 'budget increment');
        return new BudgetStateDTO(
            $this->integerValue($tuple[0], 'budget count'),
            $this->integerValue($tuple[1], 'budget epochStart'),
        );
    }

    public function incrementBudgetWithSeed(string $key, int $epochDurationSeconds, BudgetStateDTO $seed, int $amount = 1): BudgetStateDTO
    {
        $this->positive($epochDurationSeconds, 'Budget epoch duration');
        $result = $this->eval(self::BUDGET_SEED, [$this->key('budget', $key)], [$epochDurationSeconds, $seed->epochStart, $seed->count, $amount]);
        $tuple = $this->tuple($result, 2, 'seeded budget');
        return new BudgetStateDTO(
            $this->integerValue($tuple[0], 'budget count'),
            $this->integerValue($tuple[1], 'budget epochStart'),
        );
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
local previousExists = redis.call('EXISTS', KEYS[2]); local previousMetaExists = redis.call('EXISTS', KEYS[3])
if previousExists == 0 and previousMetaExists == 1 then return redis.error_reply('malformed previous watch metadata') end
if previousExists == 1 then
  if redis.call('TTL', KEYS[2]) < 0 or redis.call('TTL', KEYS[3]) < 0 then return redis.error_reply('malformed previous watch state') end
  local expiry = tonumber(redis.call('HGET', KEYS[3], 'expiresAt'))
  if not expiry or expiry ~= math.floor(expiry) then return redis.error_reply('malformed previous watch state') end
  if expiry > now then
    local value = tonumber(redis.call('GET', KEYS[2]))
    if not value or value ~= math.floor(value) then return redis.error_reply('malformed previous watch value') end
    previous = value
  end
end
local exists = redis.call('EXISTS', KEYS[1]); local currentMetaExists = redis.call('EXISTS', KEYS[4])
if exists == 0 and currentMetaExists == 1 then return redis.error_reply('malformed current watch metadata') end
if exists == 1 then
  if redis.call('TTL', KEYS[1]) < 0 or redis.call('TTL', KEYS[4]) < 0 then return redis.error_reply('malformed current watch state') end
  local expiry = tonumber(redis.call('HGET', KEYS[4], 'expiresAt'))
  local value = tonumber(redis.call('GET', KEYS[1]))
  if not expiry or expiry ~= math.floor(expiry) or not value or value ~= math.floor(value) then return redis.error_reply('malformed current watch state') end
end
local value
if exists == 0 then value = 1; redis.call('SET', KEYS[1], value, 'EX', ARGV[1]); redis.call('HSET', KEYS[4], 'expiresAt', now + tonumber(ARGV[1])); redis.call('EXPIRE', KEYS[4], ARGV[1]) else value = redis.call('INCR', KEYS[1]) end
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
        return $this->integerValue($result, 'probe lease response') === 1;
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
        $keys = [
            $this->key('cycle', $currentKey),
            $previousKey === null ? '' : $this->key('cycle', $previousKey),
            $this->key('block', $currentKey),
            $previousKey === null ? '' : $this->key('block', $previousKey),
            $this->key('pause', $currentKey),
            $previousKey === null ? '' : $this->key('pause', $previousKey),
        ];
        $result = $this->eval(self::HARD_BLOCK, $keys, [$level, $durationSeconds, $currentKey, $previousKey ?? '', $now, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds]);
        $tuple = $this->tuple($result, 4, 'hard-block cycle result');
        return new HardBlockCycleResultDTO(
            $this->integerValue($tuple[0], 'hard-block cycle flag') === 1,
            $this->integerValue($tuple[1], 'hard-block cycle count'),
            $this->integerValue($tuple[2], 'hard-block pause flag') === 1,
            $this->integerValue($tuple[3], 'hard-block pauseUntil'),
        );
    }

    public function readDecayPauseState(string $currentKey, ?string $previousKey, int $fromTimestamp, int $now): DecayPauseStateDTO
    {
        $keys = [
            $this->key('cycle', $currentKey),
            $previousKey === null ? '' : $this->key('cycle', $previousKey),
            $this->key('pause', $currentKey),
            $previousKey === null ? '' : $this->key('pause', $previousKey),
        ];
        $result = $this->eval(self::PAUSE_READ, $keys, [$fromTimestamp, $now]);
        $tuple = $this->tuple($result, 2, 'decay pause state');
        return new DecayPauseStateDTO(
            $this->integerValue($tuple[0], 'decay elapsed seconds'),
            $this->integerValue($tuple[1], 'decay active pause'),
        );
    }

    public function readGenerationBoundScoreState(string $currentKey, ?string $previousKey): ?GenerationBoundScoreStateDTO
    {
        $keys = [
            $this->key('score', $currentKey),
            $previousKey === null ? '' : $this->key('score', $previousKey),
            $this->key('block', $currentKey),
            $previousKey === null ? '' : $this->key('block', $previousKey),
        ];
        $result = $this->eval(self::LIFECYCLE_READ, $keys, []);
        if ($result === []) {
            return null;
        }
        $tuple = $this->tuple($result, 7, 'generation-bound snapshot');
        $source = $this->integerValue($tuple[0], 'generation-bound source') === 1
            ? GenerationBoundScoreStateDTO::SOURCE_CURRENT : GenerationBoundScoreStateDTO::SOURCE_PREVIOUS;
        $generation = $this->stringValue($tuple[4], 'generation-bound generation');
        $evidenceId = $this->stringValue($tuple[5], 'generation-bound evidence id');
        $evidenceUntil = $this->stringValue($tuple[6], 'generation-bound evidence expiry');
        $evidence = null;
        if ($evidenceId !== '' || $evidenceUntil !== '') {
            $evidence = new PostPunishmentReentryStateDTO($evidenceId, $this->integerValue($evidenceUntil, 'generation-bound evidence expiry'));
        }
        return new GenerationBoundScoreStateDTO(
            $source,
            $this->integerValue($tuple[1], 'generation-bound score'),
            $this->integerValue($tuple[2], 'generation-bound updatedAt'),
            $this->integerValue($tuple[3], 'generation-bound expiry'),
            $generation === '' ? null : $this->integerValue($generation, 'generation-bound generation'),
            $evidence,
        );
    }

    public function mutateGenerationBoundScore(string $currentKey, ?string $previousKey, ?GenerationBoundScoreStateDTO $expectedState, int $ttlSeconds, int $newValue): GenerationBoundScoreMutationDTO
    {
        $this->positive($ttlSeconds, 'Generation-bound score TTL');
        $expectedSource = $expectedState === null ? '' : ($expectedState->source === GenerationBoundScoreStateDTO::SOURCE_CURRENT ? $this->key('score', $currentKey) : $this->key('score', $previousKey ?? ''));
        $result = $this->eval(
            self::LIFECYCLE_MUTATE,
            [$this->key('score', $currentKey), $previousKey === null ? '' : $this->key('score', $previousKey), $this->key('reentry-claim', $currentKey)],
            [$expectedSource, $expectedState === null ? '' : $expectedState->value, $expectedState === null ? '' : $expectedState->updatedAt, $expectedState === null || $expectedState->generation === null ? '' : $expectedState->generation, $ttlSeconds, $newValue],
        );
        $tuple = $this->tuple($result, 1, 'generation-bound mutation');
        if ($this->integerValue($tuple[0], 'generation-bound mutation flag') === 0) {
            return new GenerationBoundScoreMutationDTO(false, null);
        }
        return new GenerationBoundScoreMutationDTO(true, new GenerationBoundScoreStateDTO(GenerationBoundScoreStateDTO::SOURCE_CURRENT, $this->integerValue($tuple[1], 'score value'), $this->integerValue($tuple[2], 'updatedAt'), $this->integerValue($tuple[3], 'expiresAt'), $this->integerValue($tuple[4], 'generation')));
    }

    public function blockWithPunishmentLifecycleTracking(string $currentKey, ?string $previousKey, int $expectedGeneration, string $proposedLifecycleId, int $level, int $durationSeconds, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds): PunishmentLifecycleTransitionDTO
    {
        $id = preg_match('/\A[a-f0-9]{32}\z/D', $proposedLifecycleId) === 1 ? $proposedLifecycleId : bin2hex(random_bytes(16));
        $previousKey = $previousKey === $currentKey ? null : $previousKey;
        $keys = [
            $this->key('cycle', $currentKey), $previousKey === null ? '' : $this->key('cycle', $previousKey),
            $this->key('block', $currentKey), $previousKey === null ? '' : $this->key('block', $previousKey),
            $this->key('pause', $currentKey), $previousKey === null ? '' : $this->key('pause', $previousKey),
            $this->key('score', $currentKey), $previousKey === null ? '' : $this->key('score', $previousKey),
        ];
        $result = $this->eval(self::HARD_BLOCK, $keys, [$level, $durationSeconds, $currentKey, $previousKey ?? '', 0, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds, $expectedGeneration, $id]);
        $tuple = $this->tuple($result, 1, 'punishment lifecycle transition');
        if (count($tuple) < 9 || $this->integerValue($tuple[5], 'punishment lifecycle transition flag') !== 1) {
            return new PunishmentLifecycleTransitionDTO(false, null, null, null);
        }
        $cycle = new HardBlockCycleResultDTO(
            $this->integerValue($tuple[0], 'cycle flag') === 1,
            $this->integerValue($tuple[1], 'cycle count'),
            $this->integerValue($tuple[2], 'pause flag') === 1,
            $this->integerValue($tuple[3], 'pause expiry'),
        );
        $blockExpiry = $this->integerValue($tuple[4], 'block expiry');
        $actualId = $this->stringValue($tuple[6], 'lifecycle id');
        $scoreExpiry = $this->integerValue($tuple[7], 'score expiry');
        return new PunishmentLifecycleTransitionDTO(true, $cycle, new BlockStateDTO($level, $blockExpiry), new PostPunishmentReentryStateDTO($actualId, $scoreExpiry));
    }

    public function claimPostPunishmentReentry(string $currentKey, ?string $previousKey, string $lifecycleId): bool
    {
        $keys = [$this->key('score', $currentKey), $previousKey === null ? '' : $this->key('score', $previousKey), $this->key('block', $currentKey), $previousKey === null ? '' : $this->key('block', $previousKey), $this->key('reentry-claim', $currentKey)];
        return $this->integerValue($this->eval(self::LIFECYCLE_CLAIM, $keys, [$lifecycleId]), 're-entry claim') === 1;
    }

    public function isHealthy(): bool
    {
        try {
            $result = $this->redis->execute(['PING']);
            return $result === 'PONG';
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
        return $this->integerValue($value, $label . ' response');
    }

    private function integerValue(mixed $value, string $label): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            if (! preg_match('/\A-?\d+\z/D', $value)) {
                throw new RateLimiterException('Malformed ' . $label . '.');
            }
            return (int) $value;
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return (int) $value;
        }
        throw new RateLimiterException('Malformed ' . $label . '.');
    }

    private function stringValue(mixed $value, string $label): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }
        throw new RateLimiterException('Malformed ' . $label . '.');
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
        $count = $this->integerValue($tuple[0], $label . ' count');
        $accepted = $this->integerValue($tuple[1], $label . ' accepted flag');
        $added = $this->integerValue($tuple[2], $label . ' added flag');
        $expiresAt = $this->integerValue($tuple[3], $label . ' expiresAt');
        if ($count !== count($members) || ! in_array($accepted, [0, 1], true) || ! in_array($added, [0, 1], true)) {
            throw new RateLimiterException('Malformed ' . $label . ' members.');
        }
        return new BoundedDistinctSnapshotDTO($count, $accepted === 1, $added === 1, $members, $expiresAt);
    }
}
