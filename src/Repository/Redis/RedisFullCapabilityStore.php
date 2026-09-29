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
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Repository\FullCapabilityStoreInterface;

/**
 * Official Redis-backed aggregate store.
 *
 * The Host supplies the raw-command executor. This adapter supports one
 * logical non-clustered Redis server and deliberately has no Redis-client
 * dependency. Redis-owned-time primitives use Redis server time, while
 * capabilities receiving caller-supplied semantic time preserve that value;
 * current-first/previous-read-only rotation semantics, and fail explicitly on
 * structurally malformed generated lifecycle state. The Host executor owns
 * operational failure classification; this adapter does not reinterpret
 * arbitrary throwables as backend outages.
 */
final class RedisFullCapabilityStore implements FullCapabilityStoreInterface
{
    private const PREFIX = 'maatify:rate-limiter:v1';

    private const LUA_EXACT_INTEGER_MAX = 9007199254740991;

    private const SCORE_INCREMENT = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local function validPhpInteger(value)
  if value == nil or string.match(value, '^%-?%d+$') == nil then return false end
  local negative = string.sub(value, 1, 1) == '-'
  local digits = negative and string.sub(value, 2) or value
  if digits == '' or (string.len(digits) > 1 and string.sub(digits, 1, 1) == '0') or (negative and digits == '0') then return false end
  local maximum = negative and '9223372036854775808' or '9223372036854775807'
  return string.len(digits) < 19 or (string.len(digits) == 19 and digits <= maximum)
end
local function ttlSecondsBackendRepresentable(rawTtl, nowSecondsStr)
  if rawTtl == nil or string.match(rawTtl, '^[1-9]%d*$') == nil then return false end
  if string.len(rawTtl) > 16 or (string.len(rawTtl) == 16 and rawTtl > '9223372036854775') then return false end
  local ra, rb = rawTtl:reverse(), nowSecondsStr:reverse()
  local out, carry = {}, 0
  local n = math.max(#ra, #rb)
  for i = 1, n do
    local da = tonumber(ra:sub(i, i)) or 0
    local db = tonumber(rb:sub(i, i)) or 0
    local s = da + db + carry
    carry = s >= 10 and 1 or 0
    out[i] = tostring(s % 10)
  end
  if carry == 1 then out[n + 1] = '1' end
  local rev = {}
  for i = #out, 1, -1 do rev[#rev + 1] = out[i] end
  local sum = table.concat(rev)
  if #sum ~= 16 then return #sum < 16 end
  return sum <= '9223372036854775'
end
local exists = redis.call('EXISTS', KEYS[1])
if exists == 0 then
  if not validPhpInteger(ARGV[2]) then return redis.error_reply('malformed score state') end
  if not ttlSecondsBackendRepresentable(ARGV[1], tostring(now)) then return redis.error_reply('score TTL exceeds Redis backend expiry representability') end
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
if not validPhpInteger(value) or string.match(updated, '^%d+$') == nil then return redis.error_reply('malformed score state') end
local ok, result = pcall(redis.call, 'HINCRBY', KEYS[1], 'value', ARGV[2])
if not ok then return redis.error_reply('score integer overflow') end
redis.call('HSET', KEYS[1], 'updatedAt', now)
return {result, now}
LUA;

    private const SCORE_GET = <<<'LUA'
local function validPhpInteger(value)
  if value == nil or string.match(value, '^%-?%d+$') == nil then return false end
  local negative = string.sub(value, 1, 1) == '-'
  local digits = negative and string.sub(value, 2) or value
  if digits == '' or (string.len(digits) > 1 and string.sub(digits, 1, 1) == '0') or (negative and digits == '0') then return false end
  local maximum = negative and '9223372036854775808' or '9223372036854775807'
  return string.len(digits) < 19 or (string.len(digits) == 19 and digits <= maximum)
end
if redis.call('EXISTS', KEYS[1]) == 0 then return {} end
if redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed score state') end
local value = redis.call('HGET', KEYS[1], 'value')
local updated = redis.call('HGET', KEYS[1], 'updatedAt')
if not value or not updated then return redis.error_reply('malformed score state') end
if not validPhpInteger(value) or string.match(updated, '^%d+$') == nil then return redis.error_reply('malformed score state') end
return {value, updated}
LUA;

    private const SCORE_SET = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local function ttlSecondsBackendRepresentable(rawTtl, nowSecondsStr)
  if rawTtl == nil or string.match(rawTtl, '^[1-9]%d*$') == nil then return false end
  if string.len(rawTtl) > 16 or (string.len(rawTtl) == 16 and rawTtl > '9223372036854775') then return false end
  local ra, rb = rawTtl:reverse(), nowSecondsStr:reverse()
  local out, carry = {}, 0
  local n = math.max(#ra, #rb)
  for i = 1, n do
    local da = tonumber(ra:sub(i, i)) or 0
    local db = tonumber(rb:sub(i, i)) or 0
    local s = da + db + carry
    carry = s >= 10 and 1 or 0
    out[i] = tostring(s % 10)
  end
  if carry == 1 then out[n + 1] = '1' end
  local rev = {}
  for i = #out, 1, -1 do rev[#rev + 1] = out[i] end
  local sum = table.concat(rev)
  if #sum ~= 16 then return #sum < 16 end
  return sum <= '9223372036854775'
end
if not ttlSecondsBackendRepresentable(ARGV[1], tostring(now)) then return redis.error_reply('score TTL exceeds Redis backend expiry representability') end
redis.call('HSET', KEYS[1], 'value', ARGV[2], 'updatedAt', now)
redis.call('EXPIRE', KEYS[1], ARGV[1])
return now
LUA;

    private const LIFECYCLE_MUTATE = <<<'LUA'
local redisTime = redis.call('TIME')
local nowSeconds = tonumber(redisTime[1])
local nowMs = (nowSeconds * 1000) + math.floor(tonumber(redisTime[2]) / 1000)
local exactIntegerMax = 9007199254740991
local exactIntegerMaxTtlSeconds = '9007199254740'
local function ttlMillisecondsRepresentable(rawTtl, baseMs)
  if rawTtl == nil or string.match(rawTtl, '^[1-9]%d*$') == nil then return false end
  if string.len(rawTtl) > 13 or (string.len(rawTtl) == 13 and rawTtl > exactIntegerMaxTtlSeconds) then return false end
  if baseMs == nil or baseMs < 0 or baseMs > exactIntegerMax then return false end
  local ttlMs = tonumber(rawTtl) * 1000
  return ttlMs <= exactIntegerMax - baseMs
end
local function validPositiveInteger(value)
  return value ~= nil and string.match(value, '^[1-9]%d*$') ~= nil and string.len(value) <= 19 and (string.len(value) < 19 or value <= '9223372036854775807')
end
local current = KEYS[1]; local previous = KEYS[2]
local expectedSource = ARGV[1]
local function validInteger(value, allowZero)
  if value == nil or string.match(value, '^%-?%d+$') == nil then return false end
  local negative = string.sub(value, 1, 1) == '-'
  local digits = negative and string.sub(value, 2) or value
  if not allowZero and digits == '0' then return false end
  local normalized = string.gsub(digits, '^0+', '')
  if normalized == '' then normalized = '0' end
  local maximum = negative and '9223372036854775808' or '9223372036854775807'
  return string.len(normalized) < 19 or (string.len(normalized) == 19 and normalized <= maximum)
end
local function validPhpInteger(value)
  if value == nil or string.match(value, '^%-?%d+$') == nil then return false end
  local negative = string.sub(value, 1, 1) == '-'
  local digits = negative and string.sub(value, 2) or value
  if digits == '' or (string.len(digits) > 1 and string.sub(digits, 1, 1) == '0') or (negative and digits == '0') then return false end
  local maximum = negative and '9223372036854775808' or '9223372036854775807'
  return string.len(digits) < 19 or (string.len(digits) == 19 and digits <= maximum)
end
-- DEC-017 / G12-R03-D: `updatedAt`, `expiresAt`, and `reentryValidUntil`
-- are canonical non-negative PHP-int-range decimal integers. They are
-- HGET-sourced Lua strings (never Redis native-integer replies), so this
-- adapter fully controls whether they are ever narrowed through a lossy
-- `tonumber()`; validation and ordering comparisons stay string-exact up
-- to PHP_INT_MAX and are never rejected merely for exceeding 2^53.
local function validNonNegativeInteger(value)
  return value ~= nil and (value == '0' or (string.match(value, '^[1-9]%d*$') ~= nil and string.len(value) <= 19 and (string.len(value) < 19 or value <= '9223372036854775807')))
end
local function compareDecimalStrings(a, b)
  if #a ~= #b then return (#a < #b) and -1 or 1 end
  if a == b then return 0 end
  return (a < b) and -1 or 1
end
-- Physical PTTL-derived milliseconds (nowMs/physicalDeadlineMs) are, by
-- construction, always <= 2^53: Redis hands PTTL to Lua as a native
-- number (a real, provable RESP-Integer-to-Lua-number boundary, not a
-- narrowing this adapter introduces), and the generation-bound score's
-- own absolute deadline is capped at creation time (below) specifically
-- so every later PTTL read of it stays exact. `expiresAt * 1000`,
-- compared against such a bounded number, is therefore exact without
-- ever computing a lossy `tonumber()` on a huge string: once the
-- seconds-string is provably too large to keep `*1000` <= 2^53, the
-- comparison side is already decided.
local function compareExpiryMsToBoundedNumber(expirySecondsStr, boundedMs)
  if string.len(expirySecondsStr) > 13 or (string.len(expirySecondsStr) == 13 and expirySecondsStr > '9007199254740') then return 1 end
  local ms = tonumber(expirySecondsStr) * 1000
  if ms < boundedMs then return -1 elseif ms > boundedMs then return 1 else return 0 end
end
local function cappedExpiryMs(expirySecondsStr, capMs)
  if string.len(expirySecondsStr) > 13 or (string.len(expirySecondsStr) == 13 and expirySecondsStr > '9007199254740') then return capMs end
  local ms = tonumber(expirySecondsStr) * 1000
  if ms > capMs then return capMs end
  return ms
end
local function incrementInteger(value)
  local digits = {}; for digit in string.gmatch(value, '%d') do digits[#digits + 1] = tonumber(digit) end
  local carry = 1
  for index = #digits, 1, -1 do
    local nextDigit = digits[index] + carry
    digits[index] = nextDigit % 10; carry = math.floor(nextDigit / 10)
  end
  if carry == 1 then table.insert(digits, 1, 1) end
  local result = {}; for _, digit in ipairs(digits) do result[#result + 1] = tostring(digit) end
  return table.concat(result)
end
local currentPttl = redis.call('PTTL', current)
if currentPttl == -1 then return redis.error_reply('malformed generation-bound score state') end
local source = ''
local sourcePttl = 0
if currentPttl > 0 then
  source = current
  sourcePttl = currentPttl
elseif previous ~= '' then
  local previousPttl = redis.call('PTTL', previous)
  if previousPttl == -1 then return redis.error_reply('malformed generation-bound score state') end
  if previousPttl > 0 then source = previous; sourcePttl = previousPttl end
end
if source == '' then
  if expectedSource ~= '' then return {0} end
  local rawTtl = ARGV[6]
  local ttl = tonumber(rawTtl); if ttl <= 0 then return redis.error_reply('invalid score TTL') end
  if not ttlMillisecondsRepresentable(rawTtl, nowMs) then return redis.error_reply('generation-bound score expiry exceeds Redis Lua exact integer range') end
  local requestedDeadlineMs = nowMs + (ttl * 1000)
  local expiry = math.floor((requestedDeadlineMs + 999) / 1000)
  redis.call('HSET', current, 'value', ARGV[7], 'updatedAt', nowSeconds, 'generation', 1, 'expiresAt', expiry)
  redis.call('PEXPIREAT', current, requestedDeadlineMs)
  return {1, ARGV[7], nowSeconds, expiry, 1}
end
local observedGeneration = redis.call('HGET', source, 'generation')
local observedUpdated = redis.call('HGET', source, 'updatedAt')
local observedValue = redis.call('HGET', source, 'value')
if not observedValue or not observedUpdated then
  return redis.error_reply('malformed generation-bound score state')
end
if not validPhpInteger(observedValue) or not validPhpInteger(ARGV[7]) or not validNonNegativeInteger(observedUpdated) then return redis.error_reply('malformed generation-bound score state') end
local observedExpiry = redis.call('HGET', source, 'expiresAt')
if observedGeneration then
  if not validInteger(observedGeneration, false) then return redis.error_reply('malformed generation') end
  if not observedExpiry then return redis.error_reply('malformed generation-bound score expiry') end
end
if observedExpiry then
  if not validNonNegativeInteger(observedExpiry) or observedExpiry == '0' or compareDecimalStrings(observedExpiry, observedUpdated) < 0 then return redis.error_reply('malformed score expiry') end
end
local evidenceCount = redis.call('HEXISTS', source, 'reentryId') + redis.call('HEXISTS', source, 'reentryValidUntil') + redis.call('HEXISTS', source, 'reentryGeneration')
if evidenceCount ~= 0 and evidenceCount ~= 3 then return redis.error_reply('malformed lifecycle evidence') end
if evidenceCount == 3 then
  local evidenceId = redis.call('HGET', source, 'reentryId')
  local evidenceUntil = redis.call('HGET', source, 'reentryValidUntil')
  local evidenceGeneration = redis.call('HGET', source, 'reentryGeneration')
  if not observedGeneration then return redis.error_reply('malformed lifecycle evidence generation') end
  if not evidenceId or string.len(evidenceId) ~= 32 or string.match(evidenceId, '^[a-f0-9]+$') == nil then return redis.error_reply('malformed lifecycle id') end
  if not validNonNegativeInteger(evidenceUntil) or evidenceUntil == '0' or not validInteger(evidenceGeneration, false) then return redis.error_reply('malformed lifecycle evidence') end
end
local effectiveObservedExpiry = observedExpiry
if not effectiveObservedExpiry then effectiveObservedExpiry = tostring(math.floor((nowMs + sourcePttl + 999) / 1000)) end
local physicalDeadlineMs = nowMs + sourcePttl
if observedGeneration and compareExpiryMsToBoundedNumber(effectiveObservedExpiry, physicalDeadlineMs) < 0 then return redis.error_reply('inconsistent generated score expiry') end
if expectedSource == '' or expectedSource ~= source then return {0} end
if observedValue ~= ARGV[2] or observedUpdated ~= ARGV[3] or effectiveObservedExpiry ~= ARGV[5] then return {0} end
if ARGV[4] == '' then
  if observedGeneration then return {0} end
elseif not observedGeneration or observedGeneration ~= ARGV[4] then return {0} end
local requestedTtl = tonumber(ARGV[6]); if requestedTtl <= 0 then return redis.error_reply('invalid score TTL') end
local generation = observedGeneration and incrementInteger(observedGeneration) or '1'
if string.len(generation) > 19 or (string.len(generation) == 19 and generation > '9223372036854775807') then return redis.error_reply('generation integer overflow') end
local expiry = observedExpiry
if expiry then
  if compareExpiryMsToBoundedNumber(expiry, nowMs) <= 0 then return {0} end
else
  expiry = tostring(math.floor((nowMs + sourcePttl + 999) / 1000))
end
local destinationDeadlineMs = cappedExpiryMs(expiry, physicalDeadlineMs)
if destinationDeadlineMs <= nowMs then return {0} end
redis.call('HSET', current, 'value', ARGV[7], 'updatedAt', nowSeconds, 'generation', generation, 'expiresAt', expiry)
redis.call('PEXPIREAT', current, destinationDeadlineMs)
redis.call('HDEL', current, 'reentryId', 'reentryValidUntil', 'reentryGeneration')
if KEYS[3] ~= '' then redis.call('DEL', KEYS[3]) end
return {1, ARGV[7], nowSeconds, expiry, generation}
LUA;

    private const LIFECYCLE_READ = <<<'LUA'
local redisTime = redis.call('TIME')
local nowSeconds = tonumber(redisTime[1])
local nowSecondsStr = tostring(nowSeconds)
local nowMs = (nowSeconds * 1000) + math.floor(tonumber(redisTime[2]) / 1000)
local function validPositiveInteger(value)
  return value ~= nil and string.match(value, '^[1-9]%d*$') ~= nil and string.len(value) <= 19 and (string.len(value) < 19 or value <= '9223372036854775807')
end
local function validPhpInteger(value)
  if value == nil or string.match(value, '^%-?%d+$') == nil then return false end
  local negative = string.sub(value, 1, 1) == '-'
  local digits = negative and string.sub(value, 2) or value
  if digits == '' or (string.len(digits) > 1 and string.sub(digits, 1, 1) == '0') or (negative and digits == '0') then return false end
  local maximum = negative and '9223372036854775808' or '9223372036854775807'
  return string.len(digits) < 19 or (string.len(digits) == 19 and digits <= maximum)
end
local function validNonNegativeInteger(value)
  return value ~= nil and (value == '0' or (string.match(value, '^[1-9]%d*$') ~= nil and string.len(value) <= 19 and (string.len(value) < 19 or value <= '9223372036854775807')))
end
local function validBackendExpiry(value)
  if value == nil then return false end
  if string.match(value, '^[1-9]%d*$') == nil then return false end
  if #value > 16 then return false end
  if #value == 16 and value > '9223372036854775' then return false end
  return true
end
local function compareDecimalStrings(a, b)
  if #a ~= #b then return (#a < #b) and -1 or 1 end
  if a == b then return 0 end
  return (a < b) and -1 or 1
end
local function compareExpiryMsToBoundedNumber(expirySecondsStr, boundedMs)
  if string.len(expirySecondsStr) > 13 or (string.len(expirySecondsStr) == 13 and expirySecondsStr > '9007199254740') then return 1 end
  local ms = tonumber(expirySecondsStr) * 1000
  if ms < boundedMs then return -1 elseif ms > boundedMs then return 1 else return 0 end
end
local source = KEYS[1]
local currentPttl = redis.call('PTTL', source)
if currentPttl == -1 then return redis.error_reply('malformed generation-bound score state') end
if currentPttl <= 0 then
  source = KEYS[2]
  if source == '' then return {} end
  local previousPttl = redis.call('PTTL', source)
  if previousPttl == -1 then return redis.error_reply('malformed generation-bound score state') end
  if previousPttl <= 0 then return {} end
end
local value = redis.call('HGET', source, 'value'); local updated = redis.call('HGET', source, 'updatedAt')
if not value or not updated or not validPhpInteger(value) or not validNonNegativeInteger(updated) then return redis.error_reply('malformed generation-bound score state') end
local generation = redis.call('HGET', source, 'generation') or ''
if generation ~= '' and (string.match(generation, '^[1-9]%d*$') == nil or string.len(generation) > 19 or (string.len(generation) == 19 and generation > '9223372036854775807')) then return redis.error_reply('malformed generation') end
local expiry = redis.call('HGET', source, 'expiresAt')
if generation == '' and not expiry then
  local pttl = redis.call('PTTL', source)
  if pttl <= 0 then return {} end
  expiry = tostring(math.floor((nowMs + pttl + 999) / 1000))
end
if not expiry or not validNonNegativeInteger(expiry) or expiry == '0' or compareDecimalStrings(expiry, updated) < 0 then return redis.error_reply('malformed generation-bound score expiry') end
local sourcePttl = redis.call('PTTL', source)
if sourcePttl <= 0 then return {} end
if generation and compareExpiryMsToBoundedNumber(expiry, nowMs + sourcePttl) < 0 then return redis.error_reply('inconsistent generated score expiry') end
if compareExpiryMsToBoundedNumber(expiry, nowMs) <= 0 then return {} end
local active = false
for _, blockKey in ipairs({KEYS[3], KEYS[4]}) do
  if blockKey ~= '' and redis.call('EXISTS', blockKey) == 1 then
    local blockPttl = redis.call('PTTL', blockKey)
    if blockPttl == -1 then return redis.error_reply('malformed hard-block state') end
    if blockPttl > 0 then
      local blockExpiry = redis.call('HGET', blockKey, 'expiresAt'); local level = redis.call('HGET', blockKey, 'level')
      if not blockExpiry or not validBackendExpiry(blockExpiry) or not level or not tonumber(level) or tonumber(level) ~= math.floor(tonumber(level)) or tonumber(level) < 1 or tonumber(level) > 6 then return redis.error_reply('malformed hard-block state') end
      if compareDecimalStrings(blockExpiry, nowSecondsStr) > 0 and tonumber(level) >= 2 then active = true end
    end
  end
end
local evidenceCount = redis.call('HEXISTS', source, 'reentryId') + redis.call('HEXISTS', source, 'reentryValidUntil') + redis.call('HEXISTS', source, 'reentryGeneration')
if evidenceCount ~= 0 and evidenceCount ~= 3 then return redis.error_reply('malformed lifecycle evidence') end
local evidenceGeneration = ''
local evidenceId = ''
local evidenceUntil = ''
if evidenceCount == 3 then
  evidenceGeneration = redis.call('HGET', source, 'reentryGeneration')
  evidenceId = redis.call('HGET', source, 'reentryId')
  evidenceUntil = redis.call('HGET', source, 'reentryValidUntil')
  if not evidenceId or string.len(evidenceId) ~= 32 or string.match(evidenceId, '^[a-f0-9]+$') == nil then return redis.error_reply('malformed lifecycle id') end
  if not validNonNegativeInteger(evidenceUntil) or evidenceUntil == '0' or not validPositiveInteger(evidenceGeneration) then return redis.error_reply('malformed lifecycle evidence') end
  if generation == '' then return redis.error_reply('legacy score cannot carry lifecycle evidence') end
  if active or evidenceGeneration ~= generation or evidenceUntil ~= expiry or compareDecimalStrings(evidenceUntil, nowSecondsStr) <= 0 then evidenceId = ''; evidenceUntil = '' end
end
return {source == KEYS[1] and 1 or 2, value, updated, expiry, generation, evidenceId, evidenceUntil}
LUA;

    private const LIFECYCLE_CLAIM = <<<'LUA'
local redisTime = redis.call('TIME')
local nowSeconds = tonumber(redisTime[1])
local nowSecondsStr = tostring(nowSeconds)
local nowMs = (nowSeconds * 1000) + math.floor(tonumber(redisTime[2]) / 1000)
local function validPositiveInteger(value)
  return value ~= nil and string.match(value, '^[1-9]%d*$') ~= nil and string.len(value) <= 19 and (string.len(value) < 19 or value <= '9223372036854775807')
end
local function validPhpInteger(value)
  if value == nil or string.match(value, '^%-?%d+$') == nil then return false end
  local negative = string.sub(value, 1, 1) == '-'
  local digits = negative and string.sub(value, 2) or value
  if digits == '' or (string.len(digits) > 1 and string.sub(digits, 1, 1) == '0') or (negative and digits == '0') then return false end
  local maximum = negative and '9223372036854775808' or '9223372036854775807'
  return string.len(digits) < 19 or (string.len(digits) == 19 and digits <= maximum)
end
local function validNonNegativeInteger(value)
  return value ~= nil and (value == '0' or (string.match(value, '^[1-9]%d*$') ~= nil and string.len(value) <= 19 and (string.len(value) < 19 or value <= '9223372036854775807')))
end
local function validBackendExpiry(value)
  if value == nil then return false end
  if string.match(value, '^[1-9]%d*$') == nil then return false end
  if #value > 16 then return false end
  if #value == 16 and value > '9223372036854775' then return false end
  return true
end
local function compareDecimalStrings(a, b)
  if #a ~= #b then return (#a < #b) and -1 or 1 end
  if a == b then return 0 end
  return (a < b) and -1 or 1
end
local function compareExpiryMsToBoundedNumber(expirySecondsStr, boundedMs)
  if string.len(expirySecondsStr) > 13 or (string.len(expirySecondsStr) == 13 and expirySecondsStr > '9007199254740') then return 1 end
  local ms = tonumber(expirySecondsStr) * 1000
  if ms < boundedMs then return -1 elseif ms > boundedMs then return 1 else return 0 end
end
local function cappedExpiryMs(expirySecondsStr, capMs)
  if string.len(expirySecondsStr) > 13 or (string.len(expirySecondsStr) == 13 and expirySecondsStr > '9007199254740') then return capMs end
  local ms = tonumber(expirySecondsStr) * 1000
  if ms > capMs then return capMs end
  return ms
end
for _, blockKey in ipairs({KEYS[3], KEYS[4]}) do
  if blockKey ~= '' then
    local blockPttl = redis.call('PTTL', blockKey)
    if blockPttl == -1 then return redis.error_reply('malformed hard-block state') end
    if blockPttl > 0 then
    local rawExpires = redis.call('HGET', blockKey, 'expiresAt'); local rawLevel = redis.call('HGET', blockKey, 'level')
    if not rawExpires or not validBackendExpiry(rawExpires) or not rawLevel then return redis.error_reply('malformed hard-block state') end
    local level = tonumber(rawLevel)
    if not level or level ~= math.floor(level) or level < 1 or level > 6 then return redis.error_reply('malformed hard-block state') end
    if compareDecimalStrings(rawExpires, nowSecondsStr) > 0 then return 0 end
    end
  end
end
local source = KEYS[1]
local currentPttl = redis.call('PTTL', source)
if currentPttl == -1 then return redis.error_reply('malformed generation-bound score state') end
if currentPttl <= 0 then source = KEYS[2] end
if source ~= '' then
  local sourcePttl = redis.call('PTTL', source)
  if sourcePttl == -1 then return redis.error_reply('malformed generation-bound score state') end
  if sourcePttl <= 0 then return 0 end
  local rawValue = redis.call('HGET', source, 'value'); local rawUpdated = redis.call('HGET', source, 'updatedAt')
  if not rawValue or not rawUpdated then return redis.error_reply('malformed generation-bound score state') end
  local value = rawValue; local updated = rawUpdated
  if not validPhpInteger(value) or not validNonNegativeInteger(updated) then return redis.error_reply('malformed generation-bound score state') end
  local generation = redis.call('HGET', source, 'generation')
  local rawExpiry = redis.call('HGET', source, 'expiresAt'); local expiry = nil
  if rawExpiry then
    expiry = rawExpiry
    if not validNonNegativeInteger(expiry) or expiry == '0' or compareDecimalStrings(expiry, updated) < 0 then return redis.error_reply('malformed generation-bound score expiry') end
  end
  if generation and expiry == nil then return redis.error_reply('malformed generation-bound score expiry') end
  if generation then
    if string.match(generation, '^[1-9]%d*$') == nil or string.len(generation) > 19 or (string.len(generation) == 19 and generation > '9223372036854775807') then return redis.error_reply('malformed generation') end
  end
  local physicalDeadlineMs = nowMs + sourcePttl
  if generation and compareExpiryMsToBoundedNumber(expiry, physicalDeadlineMs) < 0 then return redis.error_reply('inconsistent generated score expiry') end
  if expiry ~= nil and compareDecimalStrings(expiry, nowSecondsStr) <= 0 then return 0 end
  local evidenceCount = redis.call('HEXISTS', source, 'reentryId') + redis.call('HEXISTS', source, 'reentryValidUntil') + redis.call('HEXISTS', source, 'reentryGeneration')
  if evidenceCount ~= 0 and evidenceCount ~= 3 then return redis.error_reply('malformed lifecycle evidence') end
  if evidenceCount == 3 then
    local id = redis.call('HGET', source, 'reentryId'); local validUntil = redis.call('HGET', source, 'reentryValidUntil'); local evidenceGeneration = redis.call('HGET', source, 'reentryGeneration')
    if not id or #id ~= 32 or string.match(id, '^[a-f0-9]+$') == nil then return redis.error_reply('malformed lifecycle id') end
    if not validNonNegativeInteger(validUntil) or validUntil == '0' or not validPositiveInteger(evidenceGeneration) then return redis.error_reply('malformed lifecycle evidence') end
    if not generation then return redis.error_reply('legacy score cannot carry lifecycle evidence') end
    if evidenceGeneration ~= generation then return 0 end
    if expiry ~= nil and validUntil ~= expiry then return 0 end
    if compareDecimalStrings(validUntil, nowSecondsStr) <= 0 or id ~= ARGV[1] then return 0 end
    local marker = redis.call('GET', KEYS[5])
    local markerPttl = redis.call('PTTL', KEYS[5])
    if markerPttl == -1 then return redis.error_reply('malformed lifecycle claim marker') end
    if markerPttl > 0 then
      if marker == id then return 0 end
      return redis.error_reply('inconsistent lifecycle claim marker')
    end
    local markerDeadlineMs = cappedExpiryMs(validUntil, physicalDeadlineMs)
    if markerDeadlineMs <= nowMs then return 0 end
    local created = redis.call('SET', KEYS[5], id, 'NX')
    if not created then
      local existingPttl = redis.call('PTTL', KEYS[5])
      if existingPttl == -1 then return redis.error_reply('malformed lifecycle claim marker') end
      if existingPttl > 0 then
        local existing = redis.call('GET', KEYS[5])
        if existing == id then return 0 end
        return redis.error_reply('inconsistent lifecycle claim marker')
      end
      return 0
    end
    redis.call('PEXPIREAT', KEYS[5], markerDeadlineMs)
    return 1
  end
end
return 0
LUA;

    private const BLOCK_SET = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local function addDecimalStrings(a, b)
  local ra, rb = a:reverse(), b:reverse()
  local out, carry = {}, 0
  local n = math.max(#ra, #rb)
  for i = 1, n do
    local da = tonumber(ra:sub(i, i)) or 0
    local db = tonumber(rb:sub(i, i)) or 0
    local s = da + db + carry
    carry = s >= 10 and 1 or 0
    out[i] = tostring(s % 10)
  end
  if carry == 1 then out[n + 1] = '1' end
  local rev = {}
  for i = #out, 1, -1 do rev[#rev + 1] = out[i] end
  return table.concat(rev)
end
local function ttlSecondsBackendRepresentable(rawTtl, nowSecondsStr)
  if rawTtl == nil or string.match(rawTtl, '^[1-9]%d*$') == nil then return false end
  if string.len(rawTtl) > 16 or (string.len(rawTtl) == 16 and rawTtl > '9223372036854775') then return false end
  local sum = addDecimalStrings(rawTtl, nowSecondsStr)
  if #sum ~= 16 then return #sum < 16 end
  return sum <= '9223372036854775'
end
if not ttlSecondsBackendRepresentable(ARGV[2], tostring(now)) then return redis.error_reply('block duration exceeds Redis backend expiry representability') end
local expires = addDecimalStrings(tostring(now), ARGV[2])
redis.call('HSET', KEYS[1], 'level', ARGV[1], 'expiresAt', expires)
redis.call('EXPIRE', KEYS[1], ARGV[2])
return expires
LUA;

    private const BLOCK_GET = <<<'LUA'
local function validBackendExpiry(value)
  if value == nil or value == '0' then return value == '0' end
  if string.match(value, '^[1-9]%d*$') == nil then return false end
  if #value > 16 then return false end
  if #value == 16 and value > '9223372036854775' then return false end
  return true
end
local function compareDecimalStrings(a, b)
  if #a ~= #b then return (#a < #b) and -1 or 1 end
  if a == b then return 0 end
  return (a < b) and -1 or 1
end
if redis.call('EXISTS', KEYS[1]) == 0 then return {} end
if redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed block state') end
local level = redis.call('HGET', KEYS[1], 'level')
local expires = redis.call('HGET', KEYS[1], 'expiresAt')
if not level or not validBackendExpiry(expires) then return redis.error_reply('malformed block state') end
level = tonumber(level)
if not level or level ~= math.floor(level) or level < 1 or level > 6 then return redis.error_reply('malformed block state') end
if compareDecimalStrings(expires, tostring(tonumber(redis.call('TIME')[1]))) <= 0 then
  redis.call('DEL', KEYS[1])
  return {}
end
return {level, expires}
LUA;

    private const BUDGET_INCREMENT = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local exactIntegerMax = 9007199254740991
local count = redis.call('HGET', KEYS[1], 'count')
local start = redis.call('HGET', KEYS[1], 'epochStart')
local storedDuration = redis.call('HGET', KEYS[1], 'epochDuration')
local requestedDuration = tonumber(ARGV[1])
local exists = redis.call('EXISTS', KEYS[1])
if exists == 1 and redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed budget state') end
if exists == 1 and (not count or not start or not storedDuration) then return redis.error_reply('malformed budget state') end
if exists == 1 then
  start = tonumber(start); storedDuration = tonumber(storedDuration)
  if not string.match(count, '^%-?%d+$') or not start or start ~= math.floor(start) or not storedDuration or storedDuration <= 0 or storedDuration ~= math.floor(storedDuration) then return redis.error_reply('malformed budget state') end
  if start > exactIntegerMax - storedDuration then return redis.error_reply('budget expiry exceeds Redis Lua exact integer range') end
end
if exists == 1 and now < start + storedDuration then
  redis.call('HINCRBY', KEYS[1], 'count', ARGV[2])
  return {redis.call('HGET', KEYS[1], 'count'), start}
end
if not requestedDuration or requestedDuration <= 0 or requestedDuration ~= math.floor(requestedDuration) then return redis.error_reply('malformed budget state') end
if requestedDuration > exactIntegerMax - now then return redis.error_reply('budget expiry exceeds Redis Lua exact integer range') end
if exists == 1 then redis.call('DEL', KEYS[1]) end
redis.call('HSET', KEYS[1], 'count', ARGV[2], 'epochStart', now, 'epochDuration', ARGV[1])
redis.call('EXPIRE', KEYS[1], ARGV[1])
return {redis.call('HGET', KEYS[1], 'count'), now}
LUA;

    private const BUDGET_GET = <<<'LUA'
if redis.call('EXISTS', KEYS[1]) == 0 then return {} end
local count = redis.call('HGET', KEYS[1], 'count')
local start = redis.call('HGET', KEYS[1], 'epochStart')
local duration = redis.call('HGET', KEYS[1], 'epochDuration')
if not count or not start or not duration then return redis.error_reply('malformed budget state') end
if redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed budget state') end
start = tonumber(start); duration = tonumber(duration)
if not string.match(count, '^%-?%d+$') or not start or start ~= math.floor(start) or not duration or duration <= 0 or duration ~= math.floor(duration) then return redis.error_reply('malformed budget state') end
if tonumber(redis.call('TIME')[1]) >= start + duration then
  redis.call('DEL', KEYS[1])
  return {}
end
return {redis.call('HGET', KEYS[1], 'count'), start}
LUA;

    private const BUDGET_SEED = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local exactIntegerMax = 9007199254740991
local count = redis.call('HGET', KEYS[1], 'count')
local start = redis.call('HGET', KEYS[1], 'epochStart')
local duration = redis.call('HGET', KEYS[1], 'epochDuration')
local exists = redis.call('EXISTS', KEYS[1])
if exists == 1 and redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed budget state') end
if exists == 1 and (not count or not start or not duration) then return redis.error_reply('malformed budget state') end
if exists == 1 then
  start = tonumber(start); duration = tonumber(duration)
  if not string.match(count, '^%-?%d+$') or not start or start ~= math.floor(start) or not duration or duration <= 0 or duration ~= math.floor(duration) then return redis.error_reply('malformed budget state') end
  if start > exactIntegerMax - duration then return redis.error_reply('budget expiry exceeds Redis Lua exact integer range') end
end
if exists == 1 and now < start + duration then
  redis.call('HINCRBY', KEYS[1], 'count', ARGV[4])
  return {redis.call('HGET', KEYS[1], 'count'), start}
end
local seedStart = tonumber(ARGV[2])
local epochDuration = tonumber(ARGV[1])
if not seedStart or seedStart ~= math.floor(seedStart) or epochDuration <= 0 or epochDuration ~= math.floor(epochDuration) then return redis.error_reply('malformed seed expiry') end
if seedStart > exactIntegerMax - epochDuration then return redis.error_reply('seed expiry exceeds Redis Lua exact integer range') end
if now < seedStart + epochDuration then
  if ARGV[6] == '1' then return redis.error_reply('seeded budget count overflow') end
  if exists == 1 then redis.call('DEL', KEYS[1]) end
  redis.call('HSET', KEYS[1], 'count', ARGV[5], 'epochStart', seedStart, 'epochDuration', epochDuration)
  redis.call('EXPIRE', KEYS[1], seedStart + epochDuration - now)
  return {redis.call('HGET', KEYS[1], 'count'), seedStart}
end
if epochDuration > exactIntegerMax - now then return redis.error_reply('budget expiry exceeds Redis Lua exact integer range') end
if exists == 1 then redis.call('DEL', KEYS[1]) end
redis.call('HSET', KEYS[1], 'count', ARGV[4], 'epochStart', now, 'epochDuration', epochDuration)
redis.call('EXPIRE', KEYS[1], epochDuration)
return {redis.call('HGET', KEYS[1], 'count'), now}
LUA;

    private const DISTINCT_ADD = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local nowStr = tostring(now)
local function compareDecimalStrings(a, b)
  if #a ~= #b then return (#a < #b) and -1 or 1 end
  if a == b then return 0 end
  return (a < b) and -1 or 1
end
local function validBackendExpiry(value)
  if value == nil or value == '0' then return value == '0' end
  if string.match(value, '^[1-9]%d*$') == nil then return false end
  if #value > 16 then return false end
  if #value == 16 and value > '9223372036854775' then return false end
  return true
end
local function addDecimalStrings(a, b)
  local ra, rb = a:reverse(), b:reverse()
  local out, carry = {}, 0
  local n = math.max(#ra, #rb)
  for i = 1, n do
    local da = tonumber(ra:sub(i, i)) or 0
    local db = tonumber(rb:sub(i, i)) or 0
    local s = da + db + carry
    carry = s >= 10 and 1 or 0
    out[i] = tostring(s % 10)
  end
  if carry == 1 then out[n + 1] = '1' end
  local rev = {}
  for i = #out, 1, -1 do rev[#rev + 1] = out[i] end
  return table.concat(rev)
end
local function ttlSecondsBackendRepresentable(rawTtl)
  if rawTtl == nil or string.match(rawTtl, '^[1-9]%d*$') == nil then return false end
  if string.len(rawTtl) > 16 or (string.len(rawTtl) == 16 and rawTtl > '9223372036854775') then return false end
  local sum = addDecimalStrings(rawTtl, nowStr)
  if #sum ~= 16 then return #sum < 16 end
  return sum <= '9223372036854775'
end
local exists = redis.call('EXISTS', KEYS[1])
local metaExists = redis.call('EXISTS', KEYS[2])
if exists == 1 and redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed distinct state') end
if exists == 0 and metaExists == 1 then return redis.error_reply('malformed distinct metadata') end
if exists == 1 and redis.call('SCARD', KEYS[1]) == 0 then return redis.error_reply('malformed distinct state') end
if exists == 1 then
  local rawExpires = redis.call('HGET', KEYS[2], 'expiresAt')
  if not validBackendExpiry(rawExpires) then return redis.error_reply('malformed distinct metadata') end
  if compareDecimalStrings(rawExpires, nowStr) <= 0 or redis.call('TTL', KEYS[2]) < 0 then return redis.error_reply('malformed distinct metadata') end
end
if exists == 0 then
  if not ttlSecondsBackendRepresentable(ARGV[1]) then return redis.error_reply('correlation TTL exceeds Redis backend expiry representability') end
  redis.call('SADD', KEYS[1], ARGV[2]); redis.call('EXPIRE', KEYS[1], ARGV[1]); redis.call('HSET', KEYS[2], 'expiresAt', addDecimalStrings(nowStr, ARGV[1])); redis.call('EXPIRE', KEYS[2], ARGV[1])
else redis.call('SADD', KEYS[1], ARGV[2]) end
return redis.call('SCARD', KEYS[1])
LUA;

    private const WATCH_INCREMENT = <<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local nowStr = tostring(now)
local function compareDecimalStrings(a, b)
  if #a ~= #b then return (#a < #b) and -1 or 1 end
  if a == b then return 0 end
  return (a < b) and -1 or 1
end
local function validBackendExpiry(value)
  if value == nil or value == '0' then return value == '0' end
  if string.match(value, '^[1-9]%d*$') == nil then return false end
  if #value > 16 then return false end
  if #value == 16 and value > '9223372036854775' then return false end
  return true
end
local function addDecimalStrings(a, b)
  local ra, rb = a:reverse(), b:reverse()
  local out, carry = {}, 0
  local n = math.max(#ra, #rb)
  for i = 1, n do
    local da = tonumber(ra:sub(i, i)) or 0
    local db = tonumber(rb:sub(i, i)) or 0
    local s = da + db + carry
    carry = s >= 10 and 1 or 0
    out[i] = tostring(s % 10)
  end
  if carry == 1 then out[n + 1] = '1' end
  local rev = {}
  for i = #out, 1, -1 do rev[#rev + 1] = out[i] end
  return table.concat(rev)
end
local function ttlSecondsBackendRepresentable(rawTtl)
  if rawTtl == nil or string.match(rawTtl, '^[1-9]%d*$') == nil then return false end
  if string.len(rawTtl) > 16 or (string.len(rawTtl) == 16 and rawTtl > '9223372036854775') then return false end
  local sum = addDecimalStrings(rawTtl, nowStr)
  if #sum ~= 16 then return #sum < 16 end
  return sum <= '9223372036854775'
end
local exists = redis.call('EXISTS', KEYS[1])
if exists == 1 and redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed watch state') end
local metaExists = redis.call('EXISTS', KEYS[2])
if exists == 0 and metaExists == 1 then return redis.error_reply('malformed watch metadata') end
if exists == 1 then
  local rawExpires = redis.call('HGET', KEYS[2], 'expiresAt')
  if not validBackendExpiry(rawExpires) then return redis.error_reply('malformed watch metadata') end
  if compareDecimalStrings(rawExpires, nowStr) <= 0 or redis.call('TTL', KEYS[2]) < 0 then return redis.error_reply('malformed watch metadata') end
end
local value
if exists == 0 then
  if not ttlSecondsBackendRepresentable(ARGV[1]) then return redis.error_reply('correlation TTL exceeds Redis backend expiry representability') end
  value = 1; redis.call('SET', KEYS[1], value, 'EX', ARGV[1]); redis.call('HSET', KEYS[2], 'expiresAt', addDecimalStrings(nowStr, ARGV[1])); redis.call('EXPIRE', KEYS[2], ARGV[1])
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
local nowStr = tostring(now)
local function compareDecimalStrings(a, b)
  if #a ~= #b then return (#a < #b) and -1 or 1 end
  if a == b then return 0 end
  return (a < b) and -1 or 1
end
local function validBackendExpiry(value)
  if value == nil or value == '0' then return value == '0' end
  if string.match(value, '^[1-9]%d*$') == nil then return false end
  if #value > 16 then return false end
  if #value == 16 and value > '9223372036854775' then return false end
  return true
end
local function addDecimalStrings(a, b)
  local ra, rb = a:reverse(), b:reverse()
  local out, carry = {}, 0
  local n = math.max(#ra, #rb)
  for i = 1, n do
    local da = tonumber(ra:sub(i, i)) or 0
    local db = tonumber(rb:sub(i, i)) or 0
    local s = da + db + carry
    carry = s >= 10 and 1 or 0
    out[i] = tostring(s % 10)
  end
  if carry == 1 then out[n + 1] = '1' end
  local rev = {}
  for i = #out, 1, -1 do rev[#rev + 1] = out[i] end
  return table.concat(rev)
end
local function ttlSecondsBackendRepresentable(rawTtl)
  if rawTtl == nil or string.match(rawTtl, '^[1-9]%d*$') == nil then return false end
  if string.len(rawTtl) > 16 or (string.len(rawTtl) == 16 and rawTtl > '9223372036854775') then return false end
  local sum = addDecimalStrings(rawTtl, nowStr)
  if #sum ~= 16 then return #sum < 16 end
  return sum <= '9223372036854775'
end
local exists = redis.call('EXISTS', KEYS[1])
if exists == 1 and redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed bounded state') end
local metaExists = redis.call('EXISTS', KEYS[2])
if exists == 0 and metaExists == 1 then return redis.error_reply('malformed bounded metadata') end
if exists == 1 and redis.call('SCARD', KEYS[1]) == 0 then return redis.error_reply('malformed bounded state') end
local expires = nil
if exists == 1 then
  local rawExpires = redis.call('HGET', KEYS[2], 'expiresAt')
  if not validBackendExpiry(rawExpires) then return redis.error_reply('malformed bounded metadata') end
  expires = rawExpires
  if compareDecimalStrings(expires, nowStr) <= 0 or redis.call('TTL', KEYS[2]) < 0 then return redis.error_reply('malformed bounded metadata') end
end
local count = exists == 1 and redis.call('SCARD', KEYS[1]) or 0
if count > tonumber(ARGV[3]) then return redis.error_reply('malformed bounded state') end
if redis.call('SISMEMBER', KEYS[1], ARGV[2]) == 1 then
  local members = redis.call('SMEMBERS', KEYS[1]); table.sort(members)
  return {#members, 1, 0, expires, unpack(members)}
end
if count >= tonumber(ARGV[3]) then
  local members = redis.call('SMEMBERS', KEYS[1]); table.sort(members)
  return {count, 0, 0, expires, unpack(members)}
end
if exists == 0 then
  if not ttlSecondsBackendRepresentable(ARGV[1]) then return redis.error_reply('correlation TTL exceeds Redis backend expiry representability') end
  redis.call('SADD', KEYS[1], ARGV[2]); redis.call('EXPIRE', KEYS[1], ARGV[1]); redis.call('HSET', KEYS[2], 'expiresAt', addDecimalStrings(nowStr, ARGV[1])); redis.call('EXPIRE', KEYS[2], ARGV[1])
else redis.call('SADD', KEYS[1], ARGV[2]) end
local members = redis.call('SMEMBERS', KEYS[1]); table.sort(members)
return {#members, 1, 1, exists == 0 and addDecimalStrings(nowStr, ARGV[1]) or expires, unpack(members)}
LUA;

    private const ROTATED_SNAPSHOT = <<<'LUA'
local redisTime = redis.call('TIME')
local now = tonumber(redisTime[1])
local nowStr = tostring(now)
local nowMs = (now * 1000) + math.floor(tonumber(redisTime[2]) / 1000)
local function compareDecimalStrings(a, b)
  if #a ~= #b then return (#a < #b) and -1 or 1 end
  if a == b then return 0 end
  return (a < b) and -1 or 1
end
local function validBackendExpiry(value)
  if value == nil or value == '0' then return value == '0' end
  if string.match(value, '^[1-9]%d*$') == nil then return false end
  if #value > 16 then return false end
  if #value == 16 and value > '9223372036854775' then return false end
  return true
end
local function addDecimalStrings(a, b)
  local ra, rb = a:reverse(), b:reverse()
  local out, carry = {}, 0
  local n = math.max(#ra, #rb)
  for i = 1, n do
    local da = tonumber(ra:sub(i, i)) or 0
    local db = tonumber(rb:sub(i, i)) or 0
    local s = da + db + carry
    carry = s >= 10 and 1 or 0
    out[i] = tostring(s % 10)
  end
  if carry == 1 then out[n + 1] = '1' end
  local rev = {}
  for i = #out, 1, -1 do rev[#rev + 1] = out[i] end
  return table.concat(rev)
end
local function ttlSecondsBackendRepresentable(rawTtl)
  if rawTtl == nil or string.match(rawTtl, '^[1-9]%d*$') == nil then return false end
  if string.len(rawTtl) > 16 or (string.len(rawTtl) == 16 and rawTtl > '9223372036854775') then return false end
  local sum = addDecimalStrings(rawTtl, nowStr)
  if #sum ~= 16 then return #sum < 16 end
  return sum <= '9223372036854775'
end
if not ttlSecondsBackendRepresentable(ARGV[2]) then return redis.error_reply('correlation TTL exceeds Redis backend expiry representability') end
local alias = KEYS[1] == KEYS[5]
local prevExists = redis.call('EXISTS', KEYS[5])
local prevMetaExists = redis.call('EXISTS', KEYS[6])
local prevExpiry = false
if prevExists == 0 and prevMetaExists == 1 then return redis.error_reply('malformed previous bounded metadata') end
if prevExists == 1 then
  if redis.call('TTL', KEYS[5]) < 0 or redis.call('TTL', KEYS[6]) < 0 then return redis.error_reply('malformed previous bounded state') end
  if redis.call('SCARD', KEYS[5]) == 0 then return redis.error_reply('malformed previous bounded state') end
  local rawPrevExpiry = redis.call('HGET', KEYS[6], 'expiresAt')
  if not validBackendExpiry(rawPrevExpiry) then return redis.error_reply('malformed previous bounded state') end
  prevExpiry = rawPrevExpiry
  if redis.call('SCARD', KEYS[5]) > tonumber(ARGV[5]) then return redis.error_reply('malformed previous bounded state') end
  if compareDecimalStrings(prevExpiry, nowStr) <= 0 then prevExists = 0 end
end
if prevExists == 0 then
  local exists = redis.call('EXISTS', KEYS[1])
  local metaExists = redis.call('EXISTS', KEYS[2])
  if exists == 1 and redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed bounded state') end
  if exists == 0 and metaExists == 1 then return redis.error_reply('malformed current bounded metadata') end
  local currentExpiry = nil
  if exists == 1 then
    local rawCurrentExpiry = redis.call('HGET', KEYS[2], 'expiresAt')
    if not validBackendExpiry(rawCurrentExpiry) then return redis.error_reply('malformed current bounded state') end
    currentExpiry = rawCurrentExpiry
    if compareDecimalStrings(currentExpiry, nowStr) <= 0 or redis.call('TTL', KEYS[2]) < 0 then return redis.error_reply('malformed current bounded state') end
  end
  local count = exists == 1 and redis.call('SCARD', KEYS[1]) or 0
  if count > tonumber(ARGV[5]) then return redis.error_reply('malformed bounded state') end
  if redis.call('SISMEMBER', KEYS[1], ARGV[3]) == 1 then
    local members = redis.call('SMEMBERS', KEYS[1]); table.sort(members)
    return {#members, 1, 0, currentExpiry, unpack(members)}
  end
  if count >= tonumber(ARGV[5]) then
    local members = redis.call('SMEMBERS', KEYS[1]); table.sort(members)
    return {count, 0, 0, currentExpiry, unpack(members)}
  end
  local newExpiry = addDecimalStrings(nowStr, ARGV[2])
  if exists == 0 then redis.call('SADD', KEYS[1], ARGV[3]); redis.call('EXPIRE', KEYS[1], ARGV[2]); redis.call('HSET', KEYS[2], 'expiresAt', newExpiry); redis.call('EXPIRE', KEYS[2], ARGV[2]) else redis.call('SADD', KEYS[1], ARGV[3]) end
  local members = redis.call('SMEMBERS', KEYS[1]); table.sort(members)
  return {#members, 1, 1, exists == 0 and newExpiry or currentExpiry, unpack(members)}
end
local previous = redis.call('SMEMBERS', KEYS[5]); table.sort(previous)
if alias then
  local previousKnown = false
  for _, member in ipairs(previous) do if member == ARGV[3] or member == ARGV[4] then previousKnown = true end end
  if not previousKnown and #previous < tonumber(ARGV[5]) then
    previous[#previous + 1] = ARGV[3]; table.sort(previous)
    return {#previous, 1, 1, prevExpiry, unpack(previous)}
  end
  return {#previous, previousKnown and 1 or 0, 0, prevExpiry, unpack(previous)}
end
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
  local rawCurrentExpiry = redis.call('HGET', KEYS[2], 'expiresAt')
  if not validBackendExpiry(rawCurrentExpiry) then return redis.error_reply('malformed current bounded state') end
  currentExpiry = rawCurrentExpiry
  if compareDecimalStrings(currentExpiry, nowStr) <= 0 or redis.call('TTL', KEYS[2]) < 0 then return redis.error_reply('malformed current bounded state') end
end
local bridgeExpiry = nil
if bridgeExists == 1 then
  local rawBridgeExpiry = redis.call('HGET', KEYS[4], 'expiresAt')
  if not validBackendExpiry(rawBridgeExpiry) then return redis.error_reply('malformed bridge state') end
  bridgeExpiry = rawBridgeExpiry
  if compareDecimalStrings(bridgeExpiry, nowStr) <= 0 or redis.call('TTL', KEYS[4]) < 0 or compareDecimalStrings(bridgeExpiry, prevExpiry) > 0 then return redis.error_reply('malformed bridge state') end
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
    redis.call('HSET', KEYS[2], 'expiresAt', addDecimalStrings(nowStr, ARGV[2]))
    redis.call('EXPIRE', KEYS[2], ARGV[2])
    currentExists = 1
  else
    redis.call('SADD', KEYS[1], ARGV[3])
  end
end
local function ensureBridge()
  -- The bridge's physical TTL is capped by real PTTL replies from Previous
  -- (KEYS[5]/KEYS[6]), which Redis's scripting engine hands to Lua as
  -- native numbers; that RESP-Integer-to-Lua-number conversion is the
  -- backend/Lua boundary for this specific arithmetic, not a narrowing
  -- this adapter introduces. `math.min` against those real, bounded PTTLs
  -- is unaffected by the magnitude of `prevExpiry` itself: this branch is
  -- only reachable while Previous is still alive (bridgePttl derives from
  -- its real remaining PTTL), so it stays within the same bound proven by
  -- G12-R04's closed bridge-PTTL-exact-bound regression.
  if bridgeExists == 0 then
    local previousPttl = redis.call('PTTL', KEYS[5])
    local previousMetaPttl = redis.call('PTTL', KEYS[6])
    if previousPttl <= 0 or previousMetaPttl <= 0 then return redis.error_reply('malformed previous bounded state') end
    local previousRemainingMs = (tonumber(prevExpiry) * 1000) - nowMs
    local bridgePttl = math.min(previousPttl, previousMetaPttl, previousRemainingMs, tonumber(ARGV[2]) * 1000)
    if bridgePttl <= 0 then return redis.error_reply('malformed previous bounded state') end
    bridgeExpiry = prevExpiry
    redis.call('SADD', KEYS[3], ARGV[3])
    redis.call('PEXPIRE', KEYS[3], bridgePttl)
    redis.call('HSET', KEYS[4], 'expiresAt', bridgeExpiry)
    redis.call('PEXPIRE', KEYS[4], bridgePttl)
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
return {#members, known or added and 1 or 0, added and 1 or 0, prevExpiry, unpack(members)}
LUA;

    private const LEASE = <<<'LUA'
local function validBackendExpiry(value)
  return value ~= nil and string.match(value, '^[1-9]%d*$') ~= nil and string.len(value) <= 19 and (string.len(value) < 19 or value <= '9223372036854775807')
end
local function compareDecimalStrings(a, b)
  if #a ~= #b then return (#a < #b) and -1 or 1 end
  if a == b then return 0 end
  return (a < b) and -1 or 1
end
local function addDecimalStrings(a, b)
  local ra, rb = a:reverse(), b:reverse()
  local out, carry = {}, 0
  local n = math.max(#ra, #rb)
  for i = 1, n do
    local da = tonumber(ra:sub(i, i)) or 0
    local db = tonumber(rb:sub(i, i)) or 0
    local s = da + db + carry
    carry = s >= 10 and 1 or 0
    out[i] = tostring(s % 10)
  end
  if carry == 1 then out[n + 1] = '1' end
  local rev = {}
  for i = #out, 1, -1 do rev[#rev + 1] = out[i] end
  return table.concat(rev)
end
local current = redis.call('GET', KEYS[1])
if current then
  local ttl = redis.call('TTL', KEYS[1])
  if ttl < 0 or not validBackendExpiry(current) then return redis.error_reply('malformed probe lease') end
  if compareDecimalStrings(current, ARGV[1]) > 0 then return 0 end
end
redis.call('SET', KEYS[1], addDecimalStrings(ARGV[1], ARGV[2]), 'EX', ARGV[2])
return 1
LUA;

    private const HARD_BLOCK = <<<'LUA'
local lifecycleGeneration = ARGV[10] or ''
local lifecycleId = ARGV[11] or ''
local lifecycleTime = redis.call('TIME')
local function validPositiveInteger(value)
  return value ~= nil and string.match(value, '^[1-9]%d*$') ~= nil and string.len(value) <= 19 and (string.len(value) < 19 or value <= '9223372036854775807')
end
local function validPhpInteger(value)
  if value == nil or string.match(value, '^%-?%d+$') == nil then return false end
  local negative = string.sub(value, 1, 1) == '-'
  local digits = negative and string.sub(value, 2) or value
  if digits == '' or (string.len(digits) > 1 and string.sub(digits, 1, 1) == '0') or (negative and digits == '0') then return false end
  local maximum = negative and '9223372036854775808' or '9223372036854775807'
  return string.len(digits) < 19 or (string.len(digits) == 19 and digits <= maximum)
end
local exactIntegerMax = 9007199254740991
local function validExactInteger(value)
  if value == nil or string.match(value, '^%-?%d+$') == nil then return false end
  local negative = string.sub(value, 1, 1) == '-'
  local digits = negative and string.sub(value, 2) or value
  if digits == '' or (string.len(digits) > 1 and string.sub(digits, 1, 1) == '0') or (negative and digits == '0') then return false end
  return string.len(digits) < 16 or (string.len(digits) == 16 and digits <= '9007199254740991')
end
local function validExactPositiveInteger(value)
  return value ~= nil and string.match(value, '^[1-9]%d*$') ~= nil and string.len(value) <= 16 and (string.len(value) < 16 or value <= '9007199254740991')
end
local function validNonNegativeInteger(value)
  return value ~= nil and (value == '0' or (string.match(value, '^[1-9]%d*$') ~= nil and string.len(value) <= 19 and (string.len(value) < 19 or value <= '9223372036854775807')))
end
local function compareDecimalStrings(a, b)
  if #a ~= #b then return (#a < #b) and -1 or 1 end
  if a == b then return 0 end
  return (a < b) and -1 or 1
end
local function compareExpiryMsToBoundedNumber(expirySecondsStr, boundedMs)
  if string.len(expirySecondsStr) > 13 or (string.len(expirySecondsStr) == 13 and expirySecondsStr > '9007199254740') then return 1 end
  local ms = tonumber(expirySecondsStr) * 1000
  if ms < boundedMs then return -1 elseif ms > boundedMs then return 1 else return 0 end
end
if not validExactPositiveInteger(ARGV[2]) then return redis.error_reply('hard-block expiry is not representable') end
if not validExactPositiveInteger(ARGV[6]) then return redis.error_reply('cycle boundary is not representable') end
if not validExactPositiveInteger(ARGV[8]) then return redis.error_reply('pause boundary is not representable') end
if not validExactPositiveInteger(ARGV[9]) then return redis.error_reply('pause retention boundary is not representable') end
local durationSeconds = tonumber(ARGV[2])
local cycleWindow = tonumber(ARGV[6])
local pauseSeconds = tonumber(ARGV[8])
local retention = tonumber(ARGV[9])
local now
if lifecycleGeneration ~= '' then
  now = tonumber(lifecycleTime[1])
else
  if not validExactInteger(ARGV[5]) then return redis.error_reply('hard-block caller time is not representable') end
  now = tonumber(ARGV[5])
end
local nowMs = lifecycleGeneration ~= '' and ((tonumber(lifecycleTime[1]) * 1000) + math.floor(tonumber(lifecycleTime[2]) / 1000)) or (now * 1000)
if now > exactIntegerMax - durationSeconds then return redis.error_reply('hard-block expiry is not representable') end
if now < -exactIntegerMax + cycleWindow then return redis.error_reply('cycle boundary is not representable') end
if now < -exactIntegerMax + retention then return redis.error_reply('pause retention boundary is not representable') end
if now > exactIntegerMax - pauseSeconds then return redis.error_reply('pause boundary is not representable') end
if lifecycleGeneration ~= '' then
  local scoreKey = KEYS[7]
  local scorePttl = redis.call('PTTL', scoreKey)
  if scoreKey == '' then return redis.error_reply('lifecycle publication requires current generated score') end
  if scorePttl == -2 then
    if KEYS[8] ~= '' then
      local previousScorePttl = redis.call('PTTL', KEYS[8])
      if previousScorePttl == -1 then return redis.error_reply('malformed lifecycle previous score state') end
      if previousScorePttl > 0 then
        local previousValue = redis.call('HGET', KEYS[8], 'value')
        local previousUpdated = redis.call('HGET', KEYS[8], 'updatedAt')
        if not previousValue or not previousUpdated then return redis.error_reply('malformed lifecycle previous score state') end
        if not validPhpInteger(previousValue) or not validNonNegativeInteger(previousUpdated) then return redis.error_reply('malformed lifecycle previous score state') end
        local previousGeneration = redis.call('HGET', KEYS[8], 'generation')
        local previousExpiry = redis.call('HGET', KEYS[8], 'expiresAt')
        if previousGeneration then
          if not validPositiveInteger(previousGeneration) then return redis.error_reply('malformed lifecycle previous score generation') end
          if not previousExpiry then return redis.error_reply('malformed lifecycle previous score expiry') end
        end
        if previousExpiry then
          if not validNonNegativeInteger(previousExpiry) or previousExpiry == '0' or compareDecimalStrings(previousExpiry, previousUpdated) < 0 then return redis.error_reply('malformed lifecycle previous score expiry') end
        end
        local previousEvidenceCount = redis.call('HEXISTS', KEYS[8], 'reentryId') + redis.call('HEXISTS', KEYS[8], 'reentryValidUntil') + redis.call('HEXISTS', KEYS[8], 'reentryGeneration')
        if previousEvidenceCount ~= 0 and previousEvidenceCount ~= 3 then return redis.error_reply('malformed lifecycle previous evidence') end
        if previousEvidenceCount == 3 then
          if not previousGeneration then return redis.error_reply('legacy previous score cannot carry lifecycle evidence') end
          local previousEvidenceId = redis.call('HGET', KEYS[8], 'reentryId')
          local previousEvidenceUntil = redis.call('HGET', KEYS[8], 'reentryValidUntil')
          local previousEvidenceGeneration = redis.call('HGET', KEYS[8], 'reentryGeneration')
          if not previousEvidenceId or string.len(previousEvidenceId) ~= 32 or string.match(previousEvidenceId, '^[a-f0-9]+$') == nil then return redis.error_reply('malformed lifecycle previous evidence id') end
          if not validNonNegativeInteger(previousEvidenceUntil) or previousEvidenceUntil == '0' or not validPositiveInteger(previousEvidenceGeneration) then return redis.error_reply('malformed lifecycle previous evidence') end
        end
        if previousGeneration and compareExpiryMsToBoundedNumber(previousExpiry, nowMs + previousScorePttl) < 0 then return redis.error_reply('inconsistent lifecycle previous score expiry') end
      end
    end
    -- Current is absent; a structurally valid Previous is historical/read-only,
    -- not a publishable source, so this is an ordinary conflict, not a failure.
    return {0}
  end
  if scorePttl == -1 then return redis.error_reply('malformed lifecycle score physical expiry') end
  local generation = redis.call('HGET', scoreKey, 'generation'); local value = redis.call('HGET', scoreKey, 'value'); local updated = redis.call('HGET', scoreKey, 'updatedAt'); local scoreExpiry = redis.call('HGET', scoreKey, 'expiresAt')
  if not generation or not value or not updated or not scoreExpiry then return redis.error_reply('malformed lifecycle score state') end
  if not validPositiveInteger(generation) then return redis.error_reply('malformed lifecycle score generation') end
  if not validPhpInteger(value) or not validNonNegativeInteger(updated) or not validNonNegativeInteger(scoreExpiry) or scoreExpiry == '0' or compareDecimalStrings(scoreExpiry, updated) < 0 then return redis.error_reply('malformed lifecycle score state') end
  if compareExpiryMsToBoundedNumber(scoreExpiry, nowMs + scorePttl) < 0 then return redis.error_reply('inconsistent lifecycle score expiry') end
  if generation ~= lifecycleGeneration then return {0} end
  if compareExpiryMsToBoundedNumber(scoreExpiry, nowMs) <= 0 then return {0} end
  if lifecycleId == '' or string.len(lifecycleId) ~= 32 then return redis.error_reply('malformed lifecycle id') end
  local evidenceCount = redis.call('HEXISTS', scoreKey, 'reentryId') + redis.call('HEXISTS', scoreKey, 'reentryValidUntil') + redis.call('HEXISTS', scoreKey, 'reentryGeneration')
  if evidenceCount ~= 0 and evidenceCount ~= 3 then return redis.error_reply('malformed lifecycle evidence') end
  if evidenceCount == 3 then
    local evidenceId = redis.call('HGET', scoreKey, 'reentryId'); local evidenceUntil = redis.call('HGET', scoreKey, 'reentryValidUntil'); local evidenceGeneration = redis.call('HGET', scoreKey, 'reentryGeneration')
    if not evidenceId or string.len(evidenceId) ~= 32 or string.match(evidenceId, '^[a-f0-9]+$') == nil then return redis.error_reply('malformed lifecycle id') end
    if not validNonNegativeInteger(evidenceUntil) or evidenceUntil == '0' or not validPositiveInteger(evidenceGeneration) then return redis.error_reply('malformed lifecycle evidence') end
  end
end
local function extendUntil(key, target)
  if key == '' or redis.call('EXISTS', key) == 0 then return end
  local ttl = redis.call('TTL', key)
  local needed = math.max(1, target - now)
  if ttl < needed then redis.call('EXPIRE', key, needed) end
end

-- Phase A: validate every existing structure and compute merged state without writes.
local cyclesByMember = {}; local cycleMembers = {}
local function readCycles(key)
  if key == '' or redis.call('EXISTS', key) == 0 then return end
  if redis.call('TTL', key) < 0 then error('malformed cycle history') end
  if redis.call('ZCARD', key) == 0 then error('malformed cycle history') end
  for _, member in ipairs(redis.call('ZRANGE', key, 0, -1)) do
    if not validExactInteger(member) then error('malformed cycle history') end
    local rawScore = redis.call('ZSCORE', key, member)
    if not validExactInteger(rawScore) then error('malformed cycle history') end
    local score = tonumber(rawScore); local timestamp = tonumber(member)
    if score ~= timestamp then error('malformed cycle history') end
    if timestamp >= now - cycleWindow and cyclesByMember[member] == nil then
      cyclesByMember[member] = timestamp; cycleMembers[#cycleMembers + 1] = member
    end
  end
end
readCycles(KEYS[1]); readCycles(KEYS[2])
local active = false
for _, blockKey in ipairs({KEYS[3], KEYS[4]}) do
  if blockKey ~= '' and redis.call('EXISTS', blockKey) == 1 then
    local blockPttl = redis.call('PTTL', blockKey)
    if blockPttl == -1 then return redis.error_reply('malformed hard-block state') end
    if blockPttl > 0 then
      local rawExpires = redis.call('HGET', blockKey, 'expiresAt'); local rawLevel = redis.call('HGET', blockKey, 'level')
      if not validExactInteger(rawExpires) or not rawLevel then return redis.error_reply('malformed hard-block state') end
      local expires = tonumber(rawExpires); local level = tonumber(rawLevel)
      if not level or level ~= math.floor(level) or level < 1 or level > 6 then return redis.error_reply('malformed hard-block state') end
      if expires > now and level >= 2 then active = true end
    end
  end
end
local newCycle = not active
if newCycle and cyclesByMember[tostring(now)] == nil then cyclesByMember[tostring(now)] = now; cycleMembers[#cycleMembers + 1] = tostring(now) end
local cycleCount = #cycleMembers; local latestCycle = 0
for _, member in ipairs(cycleMembers) do latestCycle = math.max(latestCycle, cyclesByMember[member]) end
if cycleCount > 0 and latestCycle > exactIntegerMax - cycleWindow then return redis.error_reply('cycle boundary is not representable') end

local pausesByMember = {}; local pauseMembers = {}
local function readPauses(key)
  if key == '' or redis.call('EXISTS', key) == 0 then return end
  if redis.call('TTL', key) < 0 then error('malformed pause history') end
  for _, member in ipairs(redis.call('ZRANGE', key, 0, -1)) do
    local sep = string.find(member, ':')
    if not sep then error('malformed pause history') end
    local startStr = string.sub(member, 1, sep - 1); local finishStr = string.sub(member, sep + 1)
    if not validExactInteger(startStr) or not validExactInteger(finishStr) then error('malformed pause history') end
    local rawScore = redis.call('ZSCORE', key, member)
    if not validExactInteger(rawScore) then error('malformed pause history') end
    local start = tonumber(startStr); local finish = tonumber(finishStr); local score = tonumber(rawScore)
    if finish < start or score ~= start then error('malformed pause history') end
    if finish > now - retention and pausesByMember[member] == nil then pausesByMember[member] = {start, finish}; pauseMembers[#pauseMembers + 1] = member end
  end
end
readPauses(KEYS[5]); readPauses(KEYS[6])
local pauseUntil = 0; local latestPauseUntil = 0
for _, member in ipairs(pauseMembers) do
  local finish = pausesByMember[member][2]; latestPauseUntil = math.max(latestPauseUntil, finish)
  if finish > now then pauseUntil = math.max(pauseUntil, finish) end
end
local activated = false
if newCycle and cycleCount >= tonumber(ARGV[7]) and pauseUntil == 0 then
  pauseUntil = now + pauseSeconds; local member = tostring(now) .. ':' .. tostring(pauseUntil)
  pausesByMember[member] = {now, pauseUntil}; pauseMembers[#pauseMembers + 1] = member; latestPauseUntil = math.max(latestPauseUntil, pauseUntil); activated = true
end
if #pauseMembers > 0 and latestPauseUntil > exactIntegerMax - retention then return redis.error_reply('pause retention boundary is not representable') end

-- Phase B is complete. Phase C contains writes only and uses prevalidated values.
for _, member in ipairs(cycleMembers) do redis.call('ZADD', KEYS[1], cyclesByMember[member], member) end
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', '(' .. (now - cycleWindow))
if redis.call('EXISTS', KEYS[5]) == 1 then
  for _, member in ipairs(redis.call('ZRANGE', KEYS[5], 0, -1)) do
    if pausesByMember[member] == nil then redis.call('ZREM', KEYS[5], member) end
  end
end
for _, member in ipairs(pauseMembers) do redis.call('ZADD', KEYS[5], pausesByMember[member][1], member) end
if #pauseMembers > 0 then extendUntil(KEYS[5], latestPauseUntil + retention) end
if cycleCount > 0 then extendUntil(KEYS[1], latestCycle + cycleWindow) end
local expires = now + durationSeconds
redis.call('HSET', KEYS[3], 'level', ARGV[1], 'expiresAt', expires); redis.call('EXPIRE', KEYS[3], ARGV[2])
if lifecycleGeneration ~= '' then
  local scoreKey = KEYS[7]
  local storedId = redis.call('HGET', scoreKey, 'reentryId')
  local storedUntil = redis.call('HGET', scoreKey, 'reentryValidUntil')
  local storedGeneration = redis.call('HGET', scoreKey, 'reentryGeneration')
  local scoreExpiry = redis.call('HGET', scoreKey, 'expiresAt')
  local actualId = storedGeneration == lifecycleGeneration and storedId and storedUntil == scoreExpiry and storedId or lifecycleId
  redis.call('HSET', scoreKey, 'reentryId', actualId, 'reentryValidUntil', redis.call('HGET', scoreKey, 'expiresAt'), 'reentryGeneration', lifecycleGeneration)
  return {newCycle and 1 or 0, cycleCount, activated and 1 or 0, pauseUntil, expires, 1, actualId, redis.call('HGET', scoreKey, 'expiresAt'), lifecycleGeneration}
end
return {newCycle and 1 or 0, cycleCount, activated and 1 or 0, pauseUntil}
LUA;

    private const PAUSE_READ = <<<'LUA'
local exactIntegerMax = 9007199254740991
local retention = 86400
local function validExactInteger(value)
  if value == nil or string.match(value, '^%-?%d+$') == nil then return false end
  local negative = string.sub(value, 1, 1) == '-'
  local digits = negative and string.sub(value, 2) or value
  if digits == '' or (string.len(digits) > 1 and string.sub(digits, 1, 1) == '0') or (negative and digits == '0') then return false end
  return string.len(digits) < 16 or (string.len(digits) == 16 and digits <= '9007199254740991')
end
if not validExactInteger(ARGV[2]) then return redis.error_reply('caller time is not representable') end
if not validExactInteger(ARGV[1]) then return redis.error_reply('caller from-timestamp is not representable') end
local now = tonumber(ARGV[2]); local from = tonumber(ARGV[1])
if now < -exactIntegerMax + retention then return redis.error_reply('pause retention boundary is not representable') end
if from > 0 and now < -exactIntegerMax + from then return redis.error_reply('elapsed interval is not representable') end
if from < 0 and now > exactIntegerMax + from then return redis.error_reply('elapsed interval is not representable') end
local intervals = {}
local function read(source)
  if source == '' or redis.call('EXISTS', source) == 0 then return end
  if redis.call('TTL', source) < 0 then error('malformed pause history') end
  for _, member in ipairs(redis.call('ZRANGE', source, 0, -1)) do
    local sep = string.find(member, ':')
    if not sep then error('malformed pause history') end
    local startStr = string.sub(member, 1, sep - 1); local finishStr = string.sub(member, sep + 1)
    if not validExactInteger(startStr) or not validExactInteger(finishStr) then error('malformed pause history') end
    local rawScore = redis.call('ZSCORE', source, member)
    if not validExactInteger(rawScore) then error('malformed pause history') end
    local start = tonumber(startStr); local finish = tonumber(finishStr); local score = tonumber(rawScore)
    if finish < start or score ~= start then error('malformed pause history') end
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
        $this->validateBaseBlockLevel($level);
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
        $seededCount = $this->tryIntegerAddition($seed->count, $amount);
        $result = $this->eval(self::BUDGET_SEED, [$this->key('budget', $key)], [$epochDurationSeconds, $seed->epochStart, $seed->count, $amount, $seededCount ?? 0, $seededCount === null ? 1 : 0]);
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
local now = tonumber(redis.call('TIME')[1]); local nowStr = tostring(now); local previous = 0
local function compareDecimalStrings(a, b)
  if #a ~= #b then return (#a < #b) and -1 or 1 end
  if a == b then return 0 end
  return (a < b) and -1 or 1
end
local function validBackendExpiry(value)
  if value == nil or value == '0' then return value == '0' end
  if string.match(value, '^[1-9]%d*$') == nil then return false end
  if #value > 16 then return false end
  if #value == 16 and value > '9223372036854775' then return false end
  return true
end
local function addDecimalStrings(a, b)
  local ra, rb = a:reverse(), b:reverse()
  local out, carry = {}, 0
  local n = math.max(#ra, #rb)
  for i = 1, n do
    local da = tonumber(ra:sub(i, i)) or 0
    local db = tonumber(rb:sub(i, i)) or 0
    local s = da + db + carry
    carry = s >= 10 and 1 or 0
    out[i] = tostring(s % 10)
  end
  if carry == 1 then out[n + 1] = '1' end
  local rev = {}
  for i = #out, 1, -1 do rev[#rev + 1] = out[i] end
  return table.concat(rev)
end
local function ttlSecondsBackendRepresentable(rawTtl)
  if rawTtl == nil or string.match(rawTtl, '^[1-9]%d*$') == nil then return false end
  if string.len(rawTtl) > 16 or (string.len(rawTtl) == 16 and rawTtl > '9223372036854775') then return false end
  local sum = addDecimalStrings(rawTtl, nowStr)
  if #sum ~= 16 then return #sum < 16 end
  return sum <= '9223372036854775'
end
local previousExists = redis.call('EXISTS', KEYS[2]); local previousMetaExists = redis.call('EXISTS', KEYS[3])
if previousExists == 0 and previousMetaExists == 1 then return redis.error_reply('malformed previous watch metadata') end
if previousExists == 1 then
  if redis.call('TTL', KEYS[2]) < 0 or redis.call('TTL', KEYS[3]) < 0 then return redis.error_reply('malformed previous watch state') end
  local rawExpiry = redis.call('HGET', KEYS[3], 'expiresAt')
  if not validBackendExpiry(rawExpiry) then return redis.error_reply('malformed previous watch state') end
  if compareDecimalStrings(rawExpiry, nowStr) > 0 then
    local value = tonumber(redis.call('GET', KEYS[2]))
    if not value or value ~= math.floor(value) then return redis.error_reply('malformed previous watch value') end
    previous = value
  end
end
local exists = redis.call('EXISTS', KEYS[1]); local currentMetaExists = redis.call('EXISTS', KEYS[4])
if exists == 0 and currentMetaExists == 1 then return redis.error_reply('malformed current watch metadata') end
if exists == 1 then
  if redis.call('TTL', KEYS[1]) < 0 or redis.call('TTL', KEYS[4]) < 0 then return redis.error_reply('malformed current watch state') end
  local rawExpiry = redis.call('HGET', KEYS[4], 'expiresAt')
  if not validBackendExpiry(rawExpiry) then return redis.error_reply('malformed current watch state') end
  local value = tonumber(redis.call('GET', KEYS[1]))
  if not value or value ~= math.floor(value) then return redis.error_reply('malformed current watch state') end
end
local value
if exists == 0 then
  if not ttlSecondsBackendRepresentable(ARGV[1]) then return redis.error_reply('correlation TTL exceeds Redis backend expiry representability') end
  value = 1; redis.call('SET', KEYS[1], value, 'EX', ARGV[1]); redis.call('HSET', KEYS[4], 'expiresAt', addDecimalStrings(nowStr, ARGV[1])); redis.call('EXPIRE', KEYS[4], ARGV[1])
else value = redis.call('INCR', KEYS[1]) end
return value + previous
LUA;
        return $this->integerResult($this->eval($script, [$this->key('watch', $currentKey), $this->key('watch', $previousKey), $this->key('watch-meta', $previousKey), $this->key('watch-meta', $currentKey)], [$ttlSeconds]), 'rotated watch count');
    }

    public function load(string $policyName): ?CircuitBreakerStateDTO
    {
        $raw = $this->command(['GET', $this->key('circuit', $policyName)]);
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
        if (! in_array($data['status'], ['CLOSED', 'OPEN', 'HALF_OPEN'], true)) {
            throw new RateLimiterException('Malformed circuit-breaker status.');
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
        $this->command(['SET', $this->key('circuit', $policyName), $raw]);
    }

    public function acquireProbeLease(string $policyName, int $now, int $leaseSeconds): bool
    {
        $this->nonNegativeSemanticTimestamp($now, 'Probe lease caller time');
        $this->positive($leaseSeconds, 'Probe lease duration');
        if ($this->tryIntegerAddition($now, $leaseSeconds) === null) {
            throw new RateLimiterException('Probe lease expiry is not representable.');
        }
        if ($leaseSeconds > intdiv(PHP_INT_MAX, 1000)) {
            throw new RateLimiterException('Probe lease duration exceeds Redis backend expiry representability.');
        }
        $result = $this->eval(self::LEASE, [$this->key('probe', $policyName)], [$now, $leaseSeconds]);
        if (! is_int($result) && ! is_string($result)) {
            throw new RateLimiterException('Malformed probe lease response.');
        }
        return $this->integerValue($result, 'probe lease response') === 1;
    }

    public function blockWithCycleTracking(string $currentKey, ?string $previousKey, int $level, int $durationSeconds, int $now, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds): HardBlockCycleResultDTO
    {
        $this->nonNegativeSemanticTimestamp($now, 'Hard-block caller time');
        foreach ([$durationSeconds, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds] as $value) {
            $this->positive($value, 'Hard-block cycle parameter');
        }
        $this->validateHardBlockLevel($level);
        $this->ensureRepresentableAddition($now, $durationSeconds, 'Hard-block expiry');
        $this->ensureRepresentableAddition($now, $cycleWindowSeconds, 'Cycle boundary');
        $this->ensureRepresentableAddition($now, $pauseSeconds, 'Pause expiry');
        $this->ensureRepresentableAddition($now, $pauseHistoryRetentionSeconds, 'Pause retention boundary');
        $this->ensureRepresentableSubtraction($now, $cycleWindowSeconds, 'Cycle boundary');
        $this->ensureRepresentableSubtraction($now, $pauseHistoryRetentionSeconds, 'Pause retention boundary');
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
        $this->nonNegativeSemanticTimestamp($fromTimestamp, 'Decay-pause from-timestamp');
        $this->nonNegativeSemanticTimestamp($now, 'Decay-pause caller time');
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

    /**
     * Read one coherent current-first K4 lifecycle snapshot.
     *
     * The previous key is a read-only fallback consulted only when current
     * has no live state; the result is null only when neither has live
     * state. Generation-less state remains legacy-compatible by deriving
     * expiry from its Redis TTL, while generated state requires an integer
     * authoritative expiry. A malformed core score field, a stored
     * generation present but not a positive integer, a malformed or
     * physically inconsistent generated expiry, and partial lifecycle
     * evidence all raise an explicit package/state exception — as does complete,
     * structurally valid lifecycle evidence attached to a generation-less
     * score, which is impossible persisted state rather than evidence to
     * hide. Lifecycle evidence is otherwise valid only as a complete
     * structurally valid tuple; absent evidence is normal, and stale,
     * expired, active-block, or generation-mismatched evidence is returned
     * as non-satisfying state without mutation and without raising.
     */
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

    /**
     * Apply one Redis-atomic, optimistic generation-bound K4 score mutation.
     *
     * The supplied snapshot fences the current/previous namespace; an
     * ordinary stale snapshot — one that no longer matches the observed
     * state — returns an unapplied DTO, never an exception. That is distinct
     * from structurally malformed persisted state, which always raises an
     * explicit package/state exception before any mutation or clearing, regardless
     * of whether the snapshot also happens to be stale: a malformed core
     * field, a stored generation present but not a positive integer, a
     * malformed, missing, or physically inconsistent generated-score expiry,
     * partial lifecycle
     * evidence, and complete lifecycle evidence attached to a
     * generation-less score are all explicit package/state failures. Legacy state keeps
     * its remaining TTL; applied mutations write only current state, advance
     * generation, and invalidate lifecycle evidence. Absent or complete
     * structurally valid evidence follows the normal mutation path.
     */
    public function mutateGenerationBoundScore(string $currentKey, ?string $previousKey, ?GenerationBoundScoreStateDTO $expectedState, int $ttlSeconds, int $newValue): GenerationBoundScoreMutationDTO
    {
        $this->positive($ttlSeconds, 'Generation-bound score TTL');
        $this->ensureGenerationTtlRepresentable($ttlSeconds);
        $expectedSource = $expectedState === null ? '' : ($expectedState->source === GenerationBoundScoreStateDTO::SOURCE_CURRENT ? $this->key('score', $currentKey) : $this->key('score', $previousKey ?? ''));
        $result = $this->eval(
            self::LIFECYCLE_MUTATE,
            [$this->key('score', $currentKey), $previousKey === null ? '' : $this->key('score', $previousKey), $this->key('reentry-claim', $currentKey)],
            [$expectedSource, $expectedState === null ? '' : $expectedState->value, $expectedState === null ? '' : $expectedState->updatedAt, $expectedState === null || $expectedState->generation === null ? '' : $expectedState->generation, $expectedState === null ? '' : $expectedState->expiresAt, $ttlSeconds, $newValue],
        );
        $tuple = $this->tuple($result, 1, 'generation-bound mutation');
        if ($this->integerValue($tuple[0], 'generation-bound mutation flag') === 0) {
            return new GenerationBoundScoreMutationDTO(false, null);
        }
        return new GenerationBoundScoreMutationDTO(true, new GenerationBoundScoreStateDTO(GenerationBoundScoreStateDTO::SOURCE_CURRENT, $this->integerValue($tuple[1], 'score value'), $this->integerValue($tuple[2], 'updatedAt'), $this->integerValue($tuple[3], 'expiresAt'), $this->integerValue($tuple[4], 'generation')));
    }

    /**
     * Atomically publish DEC-003 cycle/pause state, an L2+ hard block, and K4
     * lifecycle evidence for the expected generation.
     *
     * Publication requires a Current generated score as its source; Previous
     * is historical, read-only input and is never itself published from.
     * `$expectedGeneration`, `$level` (L2+), and every
     * duration/window/threshold/pause/retention parameter are validated as
     * explicit contract preconditions before any Redis access.
     *
     * Redis server time owns the publication timestamps. Source-race
     * semantics distinguish ordinary optimistic conflict from structural
     * corruption: structural validation of the persisted state always runs
     * first. When a Current score exists, its generation, core fields,
     * expiry, and lifecycle evidence are validated structurally before any
     * comparison against `$expectedGeneration`; a structurally valid
     * generation that simply does not match `$expectedGeneration` then
     * returns an ordinary unapplied transition with no partial write — never
     * a backend-health failure. When no Current score exists, the absence of
     * any live source and a structurally valid Previous (generated or
     * legacy) are the same ordinary unapplied conflict, because Previous is
     * read-only history rather than a publishable source, not because
     * anything is wrong; Previous is left untouched either way. Only
     * structurally malformed persisted state raises an explicit package/state
     * failure instead of a conflict: a stored Current generation present but
     * not a positive integer, a malformed core score field, a malformed or
     * physically inconsistent expiry, partial lifecycle evidence, a
     * generation-less legacy score carrying complete lifecycle evidence, and
     * — when Current is absent — a Previous persisted without a physical
     * deadline. When Current is absent, Previous runs this exact same
     * structural validation — core fields, generation, authoritative
     * expiry, physical-versus-authoritative expiry consistency, and
     * lifecycle evidence structure and legacy-impossibility — before it can
     * be treated as an ordinary conflict.
     *
     * An applied transition couples the block, cycle/pause accounting, score
     * expiry, and lifecycle evidence. When the resolved Current source
     * already carries valid lifecycle evidence for this exact generation
     * (its `validUntil` still equals the score's authoritative expiry), the
     * existing lifecycle identity is preserved rather than replaced by
     * `$proposedLifecycleId`; a publication for a new generation always
     * establishes a new identity. The separate public claim owns the
     * one-shot marker.
     */
    public function blockWithPunishmentLifecycleTracking(string $currentKey, ?string $previousKey, int $expectedGeneration, string $proposedLifecycleId, int $level, int $durationSeconds, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds): PunishmentLifecycleTransitionDTO
    {
        $this->validatePunishmentLifecycleParameters($expectedGeneration, $level, $durationSeconds, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds);
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

    /**
     * Atomically consume the one-shot claim marker for complete lifecycle
     * evidence after all active hard blocks have ended.
     *
     * Current state is authoritative and previous state is read-only fallback.
     * Absent, stale, expired, generation-mismatched, or replayed evidence
     * returns false without consuming punishment evidence or changing score,
     * generation, block, or cycle state. Partial or structurally malformed
     * evidence, malformed blocks, and generated state without authoritative
     * expiry raise an explicit package/state exception; only the separate claim marker is
     * consumed when the claim succeeds.
     */
    public function claimPostPunishmentReentry(string $currentKey, ?string $previousKey, string $lifecycleId): bool
    {
        $keys = [$this->key('score', $currentKey), $previousKey === null ? '' : $this->key('score', $previousKey), $this->key('block', $currentKey), $previousKey === null ? '' : $this->key('block', $previousKey), $this->key('reentry-claim', $currentKey)];
        return $this->integerValue($this->eval(self::LIFECYCLE_CLAIM, $keys, [$lifecycleId]), 're-entry claim') === 1;
    }

    public function isHealthy(): bool
    {
        try {
            $result = $this->command(['PING']);
            return $result === 'PONG';
        } catch (BackendFailureException) {
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
        return $this->command($command);
    }

    /**
     * Preserve the executor's explicit failure provenance. A Host executor
     * must raise BackendFailureException for a known operational outage;
     * unknown throwables, server replies, and malformed protocol/state errors
     * remain unchanged rather than being reclassified here.
     *
     * @param non-empty-list<int|string|float> $command
     */
    private function command(array $command): mixed
    {
        try {
            return $this->redis->execute($command);
        } catch (BackendFailureException $exception) {
            throw $exception;
        }
    }

    private function positive(int $value, string $label): void
    {
        if ($value <= 0) {
            throw new RateLimiterException($label . ' must be positive.');
        }
    }

    /**
     * DEC-017: a semantic Unix timestamp is non-negative.
     */
    private function nonNegativeSemanticTimestamp(int $value, string $label): void
    {
        if ($value < 0) {
            throw new RateLimiterException($label . ' must be a non-negative Unix timestamp.');
        }
    }

    private function ensureRepresentableAddition(int $left, int $right, string $label): void
    {
        if ($this->tryIntegerAddition($left, $right) === null
            || $left < -self::LUA_EXACT_INTEGER_MAX
            || $left > self::LUA_EXACT_INTEGER_MAX
            || $right < -self::LUA_EXACT_INTEGER_MAX
            || $right > self::LUA_EXACT_INTEGER_MAX
            || ($right > 0 && $left > self::LUA_EXACT_INTEGER_MAX - $right)
            || ($right < 0 && $left < -self::LUA_EXACT_INTEGER_MAX - $right)
        ) {
            throw new RateLimiterException($label . ' is not representable.');
        }
    }

    private function ensureRepresentableSubtraction(int $left, int $right, string $label): void
    {
        if ($this->tryIntegerSubtraction($left, $right) === null
            || $left < -self::LUA_EXACT_INTEGER_MAX
            || $left > self::LUA_EXACT_INTEGER_MAX
            || $right < -self::LUA_EXACT_INTEGER_MAX
            || $right > self::LUA_EXACT_INTEGER_MAX
            || ($right > 0 && $left < -self::LUA_EXACT_INTEGER_MAX + $right)
            || ($right < 0 && $left > self::LUA_EXACT_INTEGER_MAX + $right)
        ) {
            throw new RateLimiterException($label . ' is not representable.');
        }
    }

    private function ensureGenerationTtlRepresentable(int $ttlSeconds): void
    {
        if ($ttlSeconds > intdiv(PHP_INT_MAX, 1000)) {
            throw new RateLimiterException('Generation-bound score expiry is not representable.');
        }
        $time = $this->command(['TIME']);
        if (! is_array($time) || ! array_key_exists(0, $time) || ! array_key_exists(1, $time)) {
            throw new RateLimiterException('Redis time response is malformed.');
        }
        $seconds = $this->integerValue($time[0], 'Redis time seconds');
        $microseconds = $this->integerValue($time[1], 'Redis time microseconds');
        if ($seconds < 0 || $microseconds < 0 || $microseconds > 999999) {
            throw new RateLimiterException('Redis time response is malformed.');
        }
        if ($seconds > intdiv(PHP_INT_MAX, 1000)) {
            throw new RateLimiterException('Redis time response is malformed.');
        }
        $nowMs = $this->tryIntegerAddition($seconds * 1000, intdiv($microseconds, 1000));
        if ($nowMs === null || $nowMs > self::LUA_EXACT_INTEGER_MAX || $ttlSeconds > intdiv(self::LUA_EXACT_INTEGER_MAX - $nowMs, 1000)) {
            throw new RateLimiterException('Generation-bound score expiry is not representable.');
        }
    }

    private function validateBaseBlockLevel(int $level): void
    {
        if ($level < 1 || $level > 6) {
            throw new RateLimiterException('Block level must be between L1 and L6.');
        }
    }

    private function validateHardBlockLevel(int $level): void
    {
        if ($level < 2 || $level > 6) {
            throw new RateLimiterException('Hard-block level must be between L2 and L6.');
        }
    }

    private function validatePunishmentLifecycleParameters(int $expectedGeneration, int $level, int $durationSeconds, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds): void
    {
        if ($expectedGeneration <= 0) {
            throw new RateLimiterException('Expected lifecycle generation must be positive.');
        }
        $this->validateHardBlockLevel($level);
        foreach ([
            'Block duration' => $durationSeconds,
            'Cycle window' => $cycleWindowSeconds,
            'Cycle threshold' => $cycleThreshold,
            'Pause duration' => $pauseSeconds,
            'Pause history retention' => $pauseHistoryRetentionSeconds,
        ] as $label => $value) {
            $this->positive($value, $label);
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
            $negative = str_starts_with($value, '-');
            $digits = ltrim($negative ? substr($value, 1) : $value, '0');
            $digits = $digits === '' ? '0' : $digits;
            $maximum = $negative
                ? (PHP_INT_SIZE === 8 ? '9223372036854775808' : '2147483648')
                : (string) PHP_INT_MAX;
            if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
                throw new RateLimiterException('Malformed ' . $label . '.');
            }
            return (int) $value;
        }
        throw new RateLimiterException('Malformed ' . $label . '.');
    }

    private function tryIntegerAddition(int $left, int $right): ?int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right) || ($right < 0 && $left < PHP_INT_MIN - $right)) {
            return null;
        }
        return $left + $right;
    }

    private function tryIntegerSubtraction(int $left, int $right): ?int
    {
        if (($right < 0 && $left > PHP_INT_MAX + $right) || ($right > 0 && $left < PHP_INT_MIN + $right)) {
            return null;
        }
        return $left - $right;
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
