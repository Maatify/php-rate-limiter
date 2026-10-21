<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository\Redis;

use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationResultDTO;
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
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionPermitClaimEnum;
use Maatify\RateLimiter\Repository\AdmissionCapableFullStoreInterface;
use Maatify\RateLimiter\Repository\AdmissionPermitStoreInterface;
use Maatify\RateLimiter\Repository\CallerTimeBoundedCorrelationSnapshotRotationStoreInterface;
use Maatify\RateLimiter\Repository\CallerTimeBudgetSeedStoreInterface;
use Maatify\RateLimiter\Repository\CallerTimePunishmentLifecycleStoreInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\SharedCommon\Infrastructure\SystemClock;
use DateTimeZone;

/**
 * Official Redis-backed aggregate store.
 *
 * The Host supplies the raw-command executor. This adapter supports one
 * logical non-clustered Redis server and deliberately has no Redis-client
 * dependency. Every timestamp it stores or compares is the caller's semantic
 * time (#153 phase 2, DEC-016 rule 1): the caller-time methods receive it, and
 * the existing methods without a time parameter use the store's own clock,
 * never Redis `TIME`. Physical key lifetimes stay relative on the Redis clock;
 * Redis judges every expiry and a rejected one leaves no state behind;
 * current-first/previous-read-only rotation semantics, and fail explicitly on
 * structurally malformed generated lifecycle state. The Host executor owns
 * operational failure classification; this adapter does not reinterpret
 * arbitrary throwables as backend outages.
 */
final class RedisFullCapabilityStore implements
    AdmissionCapableFullStoreInterface,
    AdmissionPermitStoreInterface,
    CallerTimeBudgetSeedStoreInterface,
    CallerTimeBoundedCorrelationSnapshotRotationStoreInterface,
    CallerTimePunishmentLifecycleStoreInterface
{
    private const PREFIX = 'maatify:rate-limiter:v1';

    private ?RedisAdmissionOperations $admission = null;

    private const LUA_EXACT_INTEGER_MAX = 9007199254740991;

    /**
     * #153 phase 2 (DEC-016 rule 1): the caller's semantic time is the last
     * ARGV, a canonical non-negative Lua-exact integer (DEC-017). No script
     * reads Redis `TIME`. Physical lifetimes stay relative (Redis clock);
     * logical deadlines are the caller's `now` plus a TTL.
     */
    private const LUA_TIME_PRELUDE = <<<'LUA'
local nowStr = ARGV[#ARGV]
if nowStr == nil or string.match(nowStr, '^%d+$') == nil or (#nowStr > 1 and string.sub(nowStr, 1, 1) == '0') or #nowStr > 16 or (#nowStr == 16 and nowStr > '9007199254740991') then return redis.error_reply('caller time is not representable') end
local now = tonumber(nowStr)
local nowMsStr = nowStr == '0' and '0' or (nowStr .. '000')
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
-- Requires a >= b, both canonical non-negative decimal strings.
local function subtractDecimalStrings(a, b)
  local ra, rb = a:reverse(), b:reverse()
  local out, borrow = {}, 0
  for i = 1, #ra do
    local d = tonumber(ra:sub(i, i)) - borrow - (tonumber(rb:sub(i, i)) or 0)
    if d < 0 then d = d + 10; borrow = 1 else borrow = 0 end
    out[i] = tostring(d)
  end
  local rev = {}
  for i = #out, 1, -1 do rev[#rev + 1] = out[i] end
  local result = string.gsub(table.concat(rev), '^0+', '')
  if result == '' then result = '0' end
  return result
end
-- Redis validates a relative EXPIRE / SET EX as `ttl * 1000 + command-time ms
-- <= 9223372036854775807`, in milliseconds. This preflight judges it on the
-- caller's time (cheap, no state touched); Redis stays the final judge through
-- `expiryApplied()`. Both operands stay decimal strings, so a TTL above 2^53
-- is judged exactly.
local function ttlBackendRepresentable(rawTtl)
  if rawTtl == nil or string.match(rawTtl, '^[1-9]%d*$') == nil or string.len(rawTtl) > 16 then return false end
  return compareDecimalStrings(addDecimalStrings(rawTtl .. '000', nowMsStr), '9223372036854775807') <= 0
end
-- LT-P2-12: an expiry command is run through redis.pcall. When Redis rejects
-- it, every key this call created is deleted (all or nothing) and the caller
-- returns `expiry not representable`. Only EXPIRE / PEXPIRE are used as the
-- judge: `SET ... EX` past Redis's bound replies OK and stores nothing.
local function expiryApplied(reply, createdKeys)
  if type(reply) == 'table' and reply.err then
    for _, createdKey in ipairs(createdKeys) do redis.call('DEL', createdKey) end
    return false
  end
  return true
end
LUA;

    private const SCORE_INCREMENT = self::LUA_TIME_PRELUDE . "\n" . <<<'LUA'
local function validPhpInteger(value)
  if value == nil or string.match(value, '^%-?%d+$') == nil then return false end
  local negative = string.sub(value, 1, 1) == '-'
  local digits = negative and string.sub(value, 2) or value
  if digits == '' or (string.len(digits) > 1 and string.sub(digits, 1, 1) == '0') or (negative and digits == '0') then return false end
  local maximum = negative and '9223372036854775808' or '9223372036854775807'
  return string.len(digits) < 19 or (string.len(digits) == 19 and digits <= maximum)
end
-- DEC-017: `updatedAt` is a canonical non-negative PHP-int Unix timestamp.
local function validNonNegativeInteger(value)
  return value ~= nil and (value == '0' or (string.match(value, '^[1-9]%d*$') ~= nil and string.len(value) <= 19 and (string.len(value) < 19 or value <= '9223372036854775807')))
end
local exists = redis.call('EXISTS', KEYS[1])
if exists == 0 then
  if not validPhpInteger(ARGV[2]) then return redis.error_reply('malformed score state') end
  if not ttlBackendRepresentable(ARGV[1]) then return redis.error_reply('score TTL exceeds Redis backend expiry representability') end
  redis.call('HSET', KEYS[1], 'value', ARGV[2], 'updatedAt', now)
  if not expiryApplied(redis.pcall('EXPIRE', KEYS[1], ARGV[1]), {KEYS[1]}) then return redis.error_reply('expiry not representable') end
  return {ARGV[2], now}
end
if redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed score state') end
local value = redis.call('HGET', KEYS[1], 'value')
local updated = redis.call('HGET', KEYS[1], 'updatedAt')
if not value or not updated then
  return redis.error_reply('malformed score state')
end
if not validPhpInteger(value) or not validNonNegativeInteger(updated) then return redis.error_reply('malformed score state') end
local ok, result = pcall(redis.call, 'HINCRBY', KEYS[1], 'value', ARGV[2])
if not ok then return redis.error_reply('score integer overflow') end
redis.call('HSET', KEYS[1], 'updatedAt', now)
return {result, now}
LUA;

    /**
     * Atomic, read-only circuit-state read (DEC-018). redis.pcall keeps a Redis
     * ERROR distinguishable from a Redis NIL before any client representation:
     * missing key => {'absent'}; string => {'value', payload};
     * command error => {'error', text}.
     */
    private const CIRCUIT_READ = <<<'LUA'
local reply = redis.pcall('GET', KEYS[1])
if type(reply) == 'table' and reply.err then return {'error', reply.err} end
if reply == false then return {'absent'} end
if type(reply) == 'string' then return {'value', reply} end
return {'error', 'unexpected GET reply type'}
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
-- DEC-017: `updatedAt` is a canonical non-negative PHP-int Unix timestamp.
local function validNonNegativeInteger(value)
  return value ~= nil and (value == '0' or (string.match(value, '^[1-9]%d*$') ~= nil and string.len(value) <= 19 and (string.len(value) < 19 or value <= '9223372036854775807')))
end
if redis.call('EXISTS', KEYS[1]) == 0 then return {} end
if redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed score state') end
local value = redis.call('HGET', KEYS[1], 'value')
local updated = redis.call('HGET', KEYS[1], 'updatedAt')
if not value or not updated then return redis.error_reply('malformed score state') end
if not validPhpInteger(value) or not validNonNegativeInteger(updated) then return redis.error_reply('malformed score state') end
return {value, updated}
LUA;

    private const SCORE_SET = self::LUA_TIME_PRELUDE . "\n" . <<<'LUA'
if not ttlBackendRepresentable(ARGV[1]) then return redis.error_reply('score TTL exceeds Redis backend expiry representability') end
if redis.call('EXISTS', KEYS[1]) == 1 then
  if not expiryApplied(redis.pcall('EXPIRE', KEYS[1], ARGV[1]), {}) then return redis.error_reply('expiry not representable') end
  redis.call('HSET', KEYS[1], 'value', ARGV[2], 'updatedAt', now)
else
  redis.call('HSET', KEYS[1], 'value', ARGV[2], 'updatedAt', now)
  if not expiryApplied(redis.pcall('EXPIRE', KEYS[1], ARGV[1]), {KEYS[1]}) then return redis.error_reply('expiry not representable') end
end
return now
LUA;

    private const LIFECYCLE_MUTATE = <<<'LUA'
-- #153 phase 2: the caller's time in milliseconds is the last ARGV, a
-- canonical non-negative Lua-exact integer (DEC-016 rule 1, DEC-017).
local nowMsStr = ARGV[#ARGV]
if nowMsStr == nil or string.match(nowMsStr, '^%d+$') == nil or (#nowMsStr > 1 and string.sub(nowMsStr, 1, 1) == '0') or #nowMsStr > 16 or (#nowMsStr == 16 and nowMsStr > '9007199254740991') then return redis.error_reply('caller time is not representable') end
local nowMs = tonumber(nowMsStr)
local nowSeconds = math.floor(nowMs / 1000)
local nowSecondsStr = tostring(nowSeconds)
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
-- LT-P2-02: the logical `expiresAt` alone decides expiry; `PTTL` only says
-- whether a key is alive. Both are decimal strings here, so a 16+ digit
-- expiry is compared and subtracted exactly.
-- Requires a >= b, both canonical non-negative decimal strings.
local function subtractDecimalStrings(a, b)
  local ra, rb = a:reverse(), b:reverse()
  local out, borrow = {}, 0
  for i = 1, #ra do
    local d = tonumber(ra:sub(i, i)) - borrow - (tonumber(rb:sub(i, i)) or 0)
    if d < 0 then d = d + 10; borrow = 1 else borrow = 0 end
    out[i] = tostring(d)
  end
  local rev = {}
  for i = #out, 1, -1 do rev[#rev + 1] = out[i] end
  local result = string.gsub(table.concat(rev), '^0+', '')
  if result == '' then result = '0' end
  return result
end
-- LT-P2-12: Redis judges every expiry; on rejection the keys this call
-- created are deleted (all or nothing).
local function expiryApplied(reply, createdKeys)
  if type(reply) == 'table' and reply.err then
    for _, createdKey in ipairs(createdKeys) do redis.call('DEL', createdKey) end
    return false
  end
  return true
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
  local expiry = math.floor((nowMs + (ttl * 1000) + 999) / 1000)
  redis.call('HSET', current, 'value', ARGV[7], 'updatedAt', nowSeconds, 'generation', 1, 'expiresAt', expiry)
  if not expiryApplied(redis.pcall('EXPIRE', current, rawTtl), {current}) then return redis.error_reply('expiry not representable') end
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
  -- LT-P2-11: the same positive-integer rule as READ, CLAIM and PUBLICATION.
  if not validPositiveInteger(observedGeneration) then return redis.error_reply('malformed generation-bound score state') end
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
-- A legacy score (no `expiresAt`) is mapped onto the caller's timeline by its remaining life.
if not effectiveObservedExpiry then effectiveObservedExpiry = tostring(math.floor((nowMs + sourcePttl + 999) / 1000)) end
if expectedSource == '' or expectedSource ~= source then return {0} end
if observedValue ~= ARGV[2] or observedUpdated ~= ARGV[3] or effectiveObservedExpiry ~= ARGV[5] then return {0} end
if ARGV[4] == '' then
  if observedGeneration then return {0} end
elseif not observedGeneration or observedGeneration ~= ARGV[4] then return {0} end
local requestedTtl = tonumber(ARGV[6]); if requestedTtl <= 0 then return redis.error_reply('invalid score TTL') end
local generation = observedGeneration and incrementInteger(observedGeneration) or '1'
if string.len(generation) > 19 or (string.len(generation) == 19 and generation > '9223372036854775807') then return redis.error_reply('generation integer overflow') end
local expiry = effectiveObservedExpiry
local expiryMsStr = expiry .. '000'
if compareDecimalStrings(expiryMsStr, nowMsStr) <= 0 then return {0} end
-- The physical life stays relative: the logical remainder, never capped (LT-P2-02).
local remaining = subtractDecimalStrings(expiryMsStr, nowMsStr)
if redis.call('EXISTS', current) == 1 then
  if not expiryApplied(redis.pcall('PEXPIRE', current, remaining), {}) then return redis.error_reply('expiry not representable') end
  redis.call('HSET', current, 'value', ARGV[7], 'updatedAt', nowSeconds, 'generation', generation, 'expiresAt', expiry)
else
  redis.call('HSET', current, 'value', ARGV[7], 'updatedAt', nowSeconds, 'generation', generation, 'expiresAt', expiry)
  if not expiryApplied(redis.pcall('PEXPIRE', current, remaining), {current}) then return redis.error_reply('expiry not representable') end
end
redis.call('HDEL', current, 'reentryId', 'reentryValidUntil', 'reentryGeneration')
if KEYS[3] ~= '' then redis.call('DEL', KEYS[3]) end
return {1, ARGV[7], nowSeconds, expiry, generation}
LUA;

    private const LIFECYCLE_READ = <<<'LUA'
-- #153 phase 2: the caller's time in milliseconds is the last ARGV, a
-- canonical non-negative Lua-exact integer (DEC-016 rule 1, DEC-017).
local nowMsStr = ARGV[#ARGV]
if nowMsStr == nil or string.match(nowMsStr, '^%d+$') == nil or (#nowMsStr > 1 and string.sub(nowMsStr, 1, 1) == '0') or #nowMsStr > 16 or (#nowMsStr == 16 and nowMsStr > '9007199254740991') then return redis.error_reply('caller time is not representable') end
local nowMs = tonumber(nowMsStr)
local nowSeconds = math.floor(nowMs / 1000)
local nowSecondsStr = tostring(nowSeconds)
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
  -- A legacy score (no `expiresAt`) is mapped onto the caller's timeline by its remaining life.
  expiry = tostring(math.floor((nowMs + pttl + 999) / 1000))
end
if not expiry or not validNonNegativeInteger(expiry) or expiry == '0' or compareDecimalStrings(expiry, updated) < 0 then return redis.error_reply('malformed generation-bound score expiry') end
local sourcePttl = redis.call('PTTL', source)
if sourcePttl <= 0 then return {} end
-- LT-P2-02: the logical deadline alone decides; PTTL only says the key is alive.
if compareDecimalStrings(expiry .. '000', nowMsStr) <= 0 then return {} end
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
-- #153 phase 2: the caller's time in milliseconds is the last ARGV, a
-- canonical non-negative Lua-exact integer (DEC-016 rule 1, DEC-017).
local nowMsStr = ARGV[#ARGV]
if nowMsStr == nil or string.match(nowMsStr, '^%d+$') == nil or (#nowMsStr > 1 and string.sub(nowMsStr, 1, 1) == '0') or #nowMsStr > 16 or (#nowMsStr == 16 and nowMsStr > '9007199254740991') then return redis.error_reply('caller time is not representable') end
local nowMs = tonumber(nowMsStr)
local nowSeconds = math.floor(nowMs / 1000)
local nowSecondsStr = tostring(nowSeconds)
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
-- Requires a >= b, both canonical non-negative decimal strings.
local function subtractDecimalStrings(a, b)
  local ra, rb = a:reverse(), b:reverse()
  local out, borrow = {}, 0
  for i = 1, #ra do
    local d = tonumber(ra:sub(i, i)) - borrow - (tonumber(rb:sub(i, i)) or 0)
    if d < 0 then d = d + 10; borrow = 1 else borrow = 0 end
    out[i] = tostring(d)
  end
  local rev = {}
  for i = #out, 1, -1 do rev[#rev + 1] = out[i] end
  local result = string.gsub(table.concat(rev), '^0+', '')
  if result == '' then result = '0' end
  return result
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
  -- LT-P2-02: the logical deadline alone decides; PTTL only says the key is alive.
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
    -- The marker lives for the evidence's logical remainder, relative and uncapped (LT-P2-02).
    local markerRemaining = subtractDecimalStrings(validUntil .. '000', nowMsStr)
    if markerRemaining == '0' then return 0 end
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
    -- Redis judges the expiry (LT-P2-12); a rejected one removes the marker this call created.
    local reply = redis.pcall('PEXPIRE', KEYS[5], markerRemaining)
    if type(reply) == 'table' and reply.err then redis.call('DEL', KEYS[5]); return redis.error_reply('expiry not representable') end
    return 1
  end
end
return 0
LUA;

    private const BLOCK_SET = self::LUA_TIME_PRELUDE . "\n" . <<<'LUA'
if not ttlBackendRepresentable(ARGV[2]) then return redis.error_reply('block duration exceeds Redis backend expiry representability') end
local expires = addDecimalStrings(nowStr, ARGV[2])
if redis.call('EXISTS', KEYS[1]) == 1 then
  if not expiryApplied(redis.pcall('EXPIRE', KEYS[1], ARGV[2]), {}) then return redis.error_reply('expiry not representable') end
  redis.call('HSET', KEYS[1], 'level', ARGV[1], 'expiresAt', expires)
else
  redis.call('HSET', KEYS[1], 'level', ARGV[1], 'expiresAt', expires)
  if not expiryApplied(redis.pcall('EXPIRE', KEYS[1], ARGV[2]), {KEYS[1]}) then return redis.error_reply('expiry not representable') end
end
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
if ARGV[1] == nil or string.match(ARGV[1], '^%d+$') == nil or (#ARGV[1] > 1 and string.sub(ARGV[1], 1, 1) == '0') or #ARGV[1] > 16 or (#ARGV[1] == 16 and ARGV[1] > '9007199254740991') then return redis.error_reply('caller time is not representable') end
if redis.call('EXISTS', KEYS[1]) == 0 then return {} end
if redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed block state') end
local level = redis.call('HGET', KEYS[1], 'level')
local expires = redis.call('HGET', KEYS[1], 'expiresAt')
if not level or not validBackendExpiry(expires) then return redis.error_reply('malformed block state') end
level = tonumber(level)
if not level or level ~= math.floor(level) or level < 1 or level > 6 then return redis.error_reply('malformed block state') end
if compareDecimalStrings(expires, ARGV[1]) <= 0 then
  redis.call('DEL', KEYS[1])
  return {}
end
return {level, expires}
LUA;

    private const BUDGET_INCREMENT = self::LUA_TIME_PRELUDE . "\n" . <<<'LUA'
local exactIntegerMax = 9007199254740991
-- DEC-017: `epochStart` is a canonical non-negative PHP-int Unix timestamp. It
-- is compared and added as a decimal string, so a value above 2^53 is handled
-- exactly instead of being narrowed through `tonumber()`.
local function validNonNegativeInteger(value)
  return value ~= nil and (value == '0' or (string.match(value, '^[1-9]%d*$') ~= nil and string.len(value) <= 19 and (string.len(value) < 19 or value <= '9223372036854775807')))
end
local function validPositiveDuration(value)
  return value ~= nil and string.match(value, '^[1-9]%d*$') ~= nil and string.len(value) <= 19 and (string.len(value) < 19 or value <= '9223372036854775807')
end
-- Returns epoch-end as a decimal string, or nil plus an error for malformed state.
local function persistedEpochEnd(count, start, duration)
  if not count or not start or not duration then return nil, 'malformed budget state' end
  if string.match(count, '^%-?%d+$') == nil or not validNonNegativeInteger(start) or not validPositiveDuration(duration) then return nil, 'malformed budget state' end
  if compareDecimalStrings(duration, '9007199254740991') > 0 then return nil, 'budget expiry exceeds Redis Lua exact integer range' end
  return addDecimalStrings(start, duration)
end
local count = redis.call('HGET', KEYS[1], 'count')
local start = redis.call('HGET', KEYS[1], 'epochStart')
local storedDuration = redis.call('HGET', KEYS[1], 'epochDuration')
local requestedDuration = tonumber(ARGV[1])
local exists = redis.call('EXISTS', KEYS[1])
if exists == 1 and redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed budget state') end
local storedEnd = nil
if exists == 1 then
  local endError
  storedEnd, endError = persistedEpochEnd(count, start, storedDuration)
  if storedEnd == nil then return redis.error_reply(endError) end
end
if exists == 1 and compareDecimalStrings(nowStr, storedEnd) < 0 then
  redis.call('HINCRBY', KEYS[1], 'count', ARGV[2])
  return {redis.call('HGET', KEYS[1], 'count'), start}
end
if not requestedDuration or requestedDuration <= 0 or requestedDuration ~= math.floor(requestedDuration) then return redis.error_reply('malformed budget state') end
if requestedDuration > exactIntegerMax - now then return redis.error_reply('budget expiry exceeds Redis Lua exact integer range') end
if exists == 1 then
  if not expiryApplied(redis.pcall('EXPIRE', KEYS[1], ARGV[1]), {}) then return redis.error_reply('expiry not representable') end
  redis.call('DEL', KEYS[1])
end
redis.call('HSET', KEYS[1], 'count', ARGV[2], 'epochStart', now, 'epochDuration', ARGV[1])
if not expiryApplied(redis.pcall('EXPIRE', KEYS[1], ARGV[1]), {KEYS[1]}) then return redis.error_reply('expiry not representable') end
return {redis.call('HGET', KEYS[1], 'count'), now}
LUA;

    private const BUDGET_GET = self::LUA_TIME_PRELUDE . "\n" . <<<'LUA'
local exactIntegerMax = 9007199254740991
-- DEC-017: `epochStart` is a canonical non-negative PHP-int Unix timestamp. It
-- is compared and added as a decimal string, so a value above 2^53 is handled
-- exactly instead of being narrowed through `tonumber()`.
local function validNonNegativeInteger(value)
  return value ~= nil and (value == '0' or (string.match(value, '^[1-9]%d*$') ~= nil and string.len(value) <= 19 and (string.len(value) < 19 or value <= '9223372036854775807')))
end
local function validPositiveDuration(value)
  return value ~= nil and string.match(value, '^[1-9]%d*$') ~= nil and string.len(value) <= 19 and (string.len(value) < 19 or value <= '9223372036854775807')
end
-- Returns epoch-end as a decimal string, or nil plus an error for malformed state.
local function persistedEpochEnd(count, start, duration)
  if not count or not start or not duration then return nil, 'malformed budget state' end
  if string.match(count, '^%-?%d+$') == nil or not validNonNegativeInteger(start) or not validPositiveDuration(duration) then return nil, 'malformed budget state' end
  if compareDecimalStrings(duration, '9007199254740991') > 0 then return nil, 'budget expiry exceeds Redis Lua exact integer range' end
  return addDecimalStrings(start, duration)
end
if redis.call('EXISTS', KEYS[1]) == 0 then return {} end
local count = redis.call('HGET', KEYS[1], 'count')
local start = redis.call('HGET', KEYS[1], 'epochStart')
local duration = redis.call('HGET', KEYS[1], 'epochDuration')
if redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed budget state') end
local storedEnd, endError = persistedEpochEnd(count, start, duration)
if storedEnd == nil then return redis.error_reply(endError) end
if compareDecimalStrings(nowStr, storedEnd) >= 0 then
  redis.call('DEL', KEYS[1])
  return {}
end
return {redis.call('HGET', KEYS[1], 'count'), start}
LUA;

    private const BUDGET_SEED = self::LUA_TIME_PRELUDE . "\n" . <<<'LUA'
local exactIntegerMax = 9007199254740991
-- DEC-017: `epochStart` is a canonical non-negative PHP-int Unix timestamp. It
-- is compared and added as a decimal string, so a value above 2^53 is handled
-- exactly instead of being narrowed through `tonumber()`.
local function validNonNegativeInteger(value)
  return value ~= nil and (value == '0' or (string.match(value, '^[1-9]%d*$') ~= nil and string.len(value) <= 19 and (string.len(value) < 19 or value <= '9223372036854775807')))
end
local function validPositiveDuration(value)
  return value ~= nil and string.match(value, '^[1-9]%d*$') ~= nil and string.len(value) <= 19 and (string.len(value) < 19 or value <= '9223372036854775807')
end
-- Returns epoch-end as a decimal string, or nil plus an error for malformed state.
local function persistedEpochEnd(count, start, duration)
  if not count or not start or not duration then return nil, 'malformed budget state' end
  if string.match(count, '^%-?%d+$') == nil or not validNonNegativeInteger(start) or not validPositiveDuration(duration) then return nil, 'malformed budget state' end
  if compareDecimalStrings(duration, '9007199254740991') > 0 then return nil, 'budget expiry exceeds Redis Lua exact integer range' end
  return addDecimalStrings(start, duration)
end
local count = redis.call('HGET', KEYS[1], 'count')
local start = redis.call('HGET', KEYS[1], 'epochStart')
local duration = redis.call('HGET', KEYS[1], 'epochDuration')
local exists = redis.call('EXISTS', KEYS[1])
if exists == 1 and redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed budget state') end
local storedEnd = nil
if exists == 1 then
  local endError
  storedEnd, endError = persistedEpochEnd(count, start, duration)
  if storedEnd == nil then return redis.error_reply(endError) end
end
if exists == 1 and compareDecimalStrings(nowStr, storedEnd) < 0 then
  redis.call('HINCRBY', KEYS[1], 'count', ARGV[4])
  return {redis.call('HGET', KEYS[1], 'count'), start}
end
-- The seed is consulted only once no active Current epoch is authoritative;
-- only then does its epoch start have to be a valid semantic timestamp.
local epochDuration = tonumber(ARGV[1])
if not validNonNegativeInteger(ARGV[2]) or not epochDuration or epochDuration <= 0 or epochDuration ~= math.floor(epochDuration) then return redis.error_reply('malformed seed expiry') end
if epochDuration > exactIntegerMax then return redis.error_reply('seed expiry exceeds Redis Lua exact integer range') end
local seedEnd = addDecimalStrings(ARGV[2], ARGV[1])
if compareDecimalStrings(nowStr, seedEnd) < 0 then
  if ARGV[6] == '1' then return redis.error_reply('seeded budget count overflow') end
  local remaining = subtractDecimalStrings(seedEnd, nowStr)
  if not ttlBackendRepresentable(remaining) then return redis.error_reply('seed expiry exceeds Redis backend expiry representability') end
  if exists == 1 then
    if not expiryApplied(redis.pcall('EXPIRE', KEYS[1], remaining), {}) then return redis.error_reply('expiry not representable') end
    redis.call('DEL', KEYS[1])
  end
  redis.call('HSET', KEYS[1], 'count', ARGV[5], 'epochStart', ARGV[2], 'epochDuration', ARGV[1])
  if not expiryApplied(redis.pcall('EXPIRE', KEYS[1], remaining), {KEYS[1]}) then return redis.error_reply('expiry not representable') end
  return {redis.call('HGET', KEYS[1], 'count'), ARGV[2]}
end
if epochDuration > exactIntegerMax - now then return redis.error_reply('budget expiry exceeds Redis Lua exact integer range') end
if exists == 1 then
  if not expiryApplied(redis.pcall('EXPIRE', KEYS[1], ARGV[1]), {}) then return redis.error_reply('expiry not representable') end
  redis.call('DEL', KEYS[1])
end
redis.call('HSET', KEYS[1], 'count', ARGV[4], 'epochStart', now, 'epochDuration', ARGV[1])
if not expiryApplied(redis.pcall('EXPIRE', KEYS[1], ARGV[1]), {KEYS[1]}) then return redis.error_reply('expiry not representable') end
return {redis.call('HGET', KEYS[1], 'count'), now}
LUA;

    private const DISTINCT_ADD = self::LUA_TIME_PRELUDE . "\n" . <<<'LUA'
local function validBackendExpiry(value)
  if value == nil or value == '0' then return value == '0' end
  if string.match(value, '^[1-9]%d*$') == nil then return false end
  if #value > 16 then return false end
  if #value == 16 and value > '9223372036854775' then return false end
  return true
end
local exists = redis.call('EXISTS', KEYS[1])
local metaExists = redis.call('EXISTS', KEYS[2])
if exists == 1 and redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed distinct state') end
if exists == 0 and metaExists == 1 then return redis.error_reply('malformed distinct metadata') end
if exists == 1 and redis.call('SCARD', KEYS[1]) == 0 then return redis.error_reply('malformed distinct state') end
if exists == 1 then
  local rawExpires = redis.call('HGET', KEYS[2], 'expiresAt')
  if not validBackendExpiry(rawExpires) then return redis.error_reply('malformed distinct metadata') end
  if redis.call('TTL', KEYS[2]) < 0 then return redis.error_reply('malformed distinct metadata') end
  -- LT-P2-03: logically expired while physically present is expired, not malformed.
  if compareDecimalStrings(rawExpires, nowStr) <= 0 then redis.call('DEL', KEYS[1], KEYS[2]); exists = 0 end
end
if exists == 0 then
  if not ttlBackendRepresentable(ARGV[1]) then return redis.error_reply('correlation TTL exceeds Redis backend expiry representability') end
  redis.call('SADD', KEYS[1], ARGV[2])
  if not expiryApplied(redis.pcall('EXPIRE', KEYS[1], ARGV[1]), {KEYS[1]}) then return redis.error_reply('expiry not representable') end
  redis.call('HSET', KEYS[2], 'expiresAt', addDecimalStrings(nowStr, ARGV[1]))
  if not expiryApplied(redis.pcall('EXPIRE', KEYS[2], ARGV[1]), {KEYS[1], KEYS[2]}) then return redis.error_reply('expiry not representable') end
else redis.call('SADD', KEYS[1], ARGV[2]) end
return redis.call('SCARD', KEYS[1])
LUA;

    private const WATCH_INCREMENT = self::LUA_TIME_PRELUDE . "\n" . <<<'LUA'
local function validBackendExpiry(value)
  if value == nil or value == '0' then return value == '0' end
  if string.match(value, '^[1-9]%d*$') == nil then return false end
  if #value > 16 then return false end
  if #value == 16 and value > '9223372036854775' then return false end
  return true
end
local exists = redis.call('EXISTS', KEYS[1])
if exists == 1 and redis.call('TTL', KEYS[1]) < 0 then return redis.error_reply('malformed watch state') end
local metaExists = redis.call('EXISTS', KEYS[2])
if exists == 0 and metaExists == 1 then return redis.error_reply('malformed watch metadata') end
if exists == 1 then
  local rawExpires = redis.call('HGET', KEYS[2], 'expiresAt')
  if not validBackendExpiry(rawExpires) then return redis.error_reply('malformed watch metadata') end
  if redis.call('TTL', KEYS[2]) < 0 then return redis.error_reply('malformed watch metadata') end
  -- LT-P2-03: logically expired while physically present is expired, not malformed.
  if compareDecimalStrings(rawExpires, nowStr) <= 0 then redis.call('DEL', KEYS[1], KEYS[2]); exists = 0 end
end
local value
if exists == 0 then
  if not ttlBackendRepresentable(ARGV[1]) then return redis.error_reply('correlation TTL exceeds Redis backend expiry representability') end
  value = 1
  redis.call('SET', KEYS[1], value)
  if not expiryApplied(redis.pcall('EXPIRE', KEYS[1], ARGV[1]), {KEYS[1]}) then return redis.error_reply('expiry not representable') end
  redis.call('HSET', KEYS[2], 'expiresAt', addDecimalStrings(nowStr, ARGV[1]))
  if not expiryApplied(redis.pcall('EXPIRE', KEYS[2], ARGV[1]), {KEYS[1], KEYS[2]}) then return redis.error_reply('expiry not representable') end
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

    private const BOUNDED_SNAPSHOT = self::LUA_TIME_PRELUDE . "\n" . <<<'LUA'
local function validBackendExpiry(value)
  if value == nil or value == '0' then return value == '0' end
  if string.match(value, '^[1-9]%d*$') == nil then return false end
  if #value > 16 then return false end
  if #value == 16 and value > '9223372036854775' then return false end
  return true
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
  if redis.call('TTL', KEYS[2]) < 0 then return redis.error_reply('malformed bounded metadata') end
  -- LT-P2-03: logically expired while physically present is expired, not malformed.
  if compareDecimalStrings(expires, nowStr) <= 0 then redis.call('DEL', KEYS[1], KEYS[2]); exists = 0; expires = nil end
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
  if not ttlBackendRepresentable(ARGV[1]) then return redis.error_reply('correlation TTL exceeds Redis backend expiry representability') end
  redis.call('SADD', KEYS[1], ARGV[2])
  if not expiryApplied(redis.pcall('EXPIRE', KEYS[1], ARGV[1]), {KEYS[1]}) then return redis.error_reply('expiry not representable') end
  redis.call('HSET', KEYS[2], 'expiresAt', addDecimalStrings(nowStr, ARGV[1]))
  if not expiryApplied(redis.pcall('EXPIRE', KEYS[2], ARGV[1]), {KEYS[1], KEYS[2]}) then return redis.error_reply('expiry not representable') end
else redis.call('SADD', KEYS[1], ARGV[2]) end
local members = redis.call('SMEMBERS', KEYS[1]); table.sort(members)
return {#members, 1, 1, exists == 0 and addDecimalStrings(nowStr, ARGV[1]) or expires, unpack(members)}
LUA;

    private const ROTATED_SNAPSHOT = self::LUA_TIME_PRELUDE . "\n" . <<<'LUA'
local function validBackendExpiry(value)
  if value == nil or value == '0' then return value == '0' end
  if string.match(value, '^[1-9]%d*$') == nil then return false end
  if #value > 16 then return false end
  if #value == 16 and value > '9223372036854775' then return false end
  return true
end
-- The bridge lifetime is derived in milliseconds: ARGV[6] is the caller's
-- time in milliseconds, consistent with the seconds the prelude read.
if ARGV[6] == nil or string.match(ARGV[6], '^%d+$') == nil or (#ARGV[6] > 1 and string.sub(ARGV[6], 1, 1) == '0') or #ARGV[6] > 19 or compareDecimalStrings(ARGV[6], nowMsStr) < 0 or compareDecimalStrings(subtractDecimalStrings(ARGV[6], nowMsStr), '999') > 0 then return redis.error_reply('caller time is not representable') end
nowMsStr = ARGV[6]
if not ttlBackendRepresentable(ARGV[2]) then return redis.error_reply('correlation TTL exceeds Redis backend expiry representability') end
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
    if redis.call('TTL', KEYS[2]) < 0 then return redis.error_reply('malformed current bounded state') end
    -- LT-P2-03: a logically expired current window is expired, not malformed.
    if compareDecimalStrings(currentExpiry, nowStr) <= 0 then redis.call('DEL', KEYS[1], KEYS[2]); exists = 0; currentExpiry = nil end
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
  if exists == 0 then
    redis.call('SADD', KEYS[1], ARGV[3])
    if not expiryApplied(redis.pcall('EXPIRE', KEYS[1], ARGV[2]), {KEYS[1]}) then return redis.error_reply('expiry not representable') end
    redis.call('HSET', KEYS[2], 'expiresAt', newExpiry)
    if not expiryApplied(redis.pcall('EXPIRE', KEYS[2], ARGV[2]), {KEYS[1], KEYS[2]}) then return redis.error_reply('expiry not representable') end
  else redis.call('SADD', KEYS[1], ARGV[3]) end
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
  if redis.call('TTL', KEYS[2]) < 0 then return redis.error_reply('malformed current bounded state') end
end
local bridgeExpiry = nil
if bridgeExists == 1 then
  local rawBridgeExpiry = redis.call('HGET', KEYS[4], 'expiresAt')
  if not validBackendExpiry(rawBridgeExpiry) then return redis.error_reply('malformed bridge state') end
  bridgeExpiry = rawBridgeExpiry
  if redis.call('TTL', KEYS[4]) < 0 or compareDecimalStrings(bridgeExpiry, prevExpiry) > 0 then return redis.error_reply('malformed bridge state') end
end
-- LT-P2-03: a logically expired current window or bridge is expired, not
-- malformed. Both are writable state; the previous generation stays read-only.
if currentExists == 1 and compareDecimalStrings(currentExpiry, nowStr) <= 0 then redis.call('DEL', KEYS[1], KEYS[2]); currentExists = 0; currentExpiry = nil end
if bridgeExists == 1 and compareDecimalStrings(bridgeExpiry, nowStr) <= 0 then redis.call('DEL', KEYS[3], KEYS[4]); bridgeExists = 0; bridgeExpiry = nil end
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
local created = {}
local function ensureCurrent()
  if currentExists == 0 then
    redis.call('SADD', KEYS[1], ARGV[3]); created[#created + 1] = KEYS[1]
    if not expiryApplied(redis.pcall('EXPIRE', KEYS[1], ARGV[2]), created) then return false end
    redis.call('HSET', KEYS[2], 'expiresAt', addDecimalStrings(nowStr, ARGV[2])); created[#created + 1] = KEYS[2]
    if not expiryApplied(redis.pcall('EXPIRE', KEYS[2], ARGV[2]), created) then return false end
    currentExists = 1
  else
    redis.call('SADD', KEYS[1], ARGV[3])
  end
  return true
end
-- The bridge's physical lifetime is min(Previous PTTL, Previous-metadata
-- PTTL, Previous authoritative remaining ms, requested ms), never rounded
-- upward. PTTL reaches Lua as a native number, which is exact only up to
-- 2^53 - 1: a value at or above that is treated as "at least 2^53" and a
-- candidate below it is exact. The authoritative-remaining and requested
-- candidates are derived from decimal strings, never from `tonumber()` of a
-- huge value. Any exact candidate is therefore provably <= every inexact
-- one, so the exact minimum is the true minimum; when every candidate is
-- inexact the lifetime cannot be bounded exactly and the whole rotation
-- fails before any Current or Bridge mutation.
local exactMsMax = '9007199254740991'
local function exactCandidate(decimal)
  if compareDecimalStrings(decimal, exactMsMax) <= 0 then return tonumber(decimal) end
  return nil
end
local function planBridgePttl()
  local previousPttl = redis.call('PTTL', KEYS[5])
  local previousMetaPttl = redis.call('PTTL', KEYS[6])
  if previousPttl <= 0 or previousMetaPttl <= 0 then return nil, 'malformed previous bounded state' end
  local candidates = {}
  if previousPttl <= 9007199254740991 then candidates[#candidates + 1] = previousPttl end
  if previousMetaPttl <= 9007199254740991 then candidates[#candidates + 1] = previousMetaPttl end
  local remaining = exactCandidate(subtractDecimalStrings(prevExpiry .. '000', nowMsStr))
  if remaining ~= nil then candidates[#candidates + 1] = remaining end
  local requested = exactCandidate(ARGV[2] .. '000')
  if requested ~= nil then candidates[#candidates + 1] = requested end
  if #candidates == 0 then return nil, 'rotated bridge lifetime exceeds Redis Lua exact integer range' end
  local bridgePttl = candidates[1]
  for _, candidate in ipairs(candidates) do if candidate < bridgePttl then bridgePttl = candidate end end
  if bridgePttl <= 0 then return nil, 'malformed previous bounded state' end
  return bridgePttl
end
local function ensureBridge(bridgePttl)
  if bridgeExists == 0 then
    bridgeExpiry = prevExpiry
    redis.call('SADD', KEYS[3], ARGV[3]); created[#created + 1] = KEYS[3]
    if not expiryApplied(redis.pcall('PEXPIRE', KEYS[3], bridgePttl), created) then return false end
    redis.call('HSET', KEYS[4], 'expiresAt', bridgeExpiry); created[#created + 1] = KEYS[4]
    if not expiryApplied(redis.pcall('PEXPIRE', KEYS[4], bridgePttl), created) then return false end
    bridgeExists = 1
  else
    redis.call('SADD', KEYS[3], ARGV[3])
  end
  return true
end
if known then
  if not ensureCurrent() then return redis.error_reply('expiry not representable') end
elseif #members < tonumber(ARGV[5]) then
  local bridgePttl = nil
  if bridgeExists == 0 then
    local planError
    bridgePttl, planError = planBridgePttl()
    if bridgePttl == nil then return redis.error_reply(planError) end
  end
  if not ensureCurrent() or not ensureBridge(bridgePttl) then return redis.error_reply('expiry not representable') end
  added = true
  members[#members + 1] = ARGV[3]
end
return {#members, known or added and 1 or 0, added and 1 or 0, prevExpiry, unpack(members)}
LUA;

    private const LEASE = self::LUA_TIME_PRELUDE . "\n" . <<<'LUA'
local function validBackendExpiry(value)
  return value ~= nil and string.match(value, '^[1-9]%d*$') ~= nil and string.len(value) <= 19 and (string.len(value) < 19 or value <= '9223372036854775807')
end
local current = redis.call('GET', KEYS[1])
if current then
  local ttl = redis.call('TTL', KEYS[1])
  if ttl < 0 or not validBackendExpiry(current) then return redis.error_reply('malformed probe lease') end
  if compareDecimalStrings(current, ARGV[1]) > 0 then return 0 end
end
if not ttlBackendRepresentable(ARGV[2]) then return redis.error_reply('probe lease duration exceeds Redis backend expiry representability') end
if current then
  if not expiryApplied(redis.pcall('EXPIRE', KEYS[1], ARGV[2]), {}) then return redis.error_reply('expiry not representable') end
  redis.call('SET', KEYS[1], addDecimalStrings(ARGV[1], ARGV[2]), 'KEEPTTL')
else
  redis.call('SET', KEYS[1], addDecimalStrings(ARGV[1], ARGV[2]))
  if not expiryApplied(redis.pcall('EXPIRE', KEYS[1], ARGV[2]), {KEYS[1]}) then return redis.error_reply('expiry not representable') end
end
return 1
LUA;

    private const HARD_BLOCK = <<<'LUA'
local lifecycleGeneration = ARGV[10] or ''
local lifecycleId = ARGV[11] or ''
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
-- DEC-017: persisted semantic timestamps (cycle members/scores, pause
-- start/finish/scores, hard-block `expiresAt`) are canonical non-negative
-- decimals, exact within Lua's integer range where ZSET arithmetic needs it.
local function validExactNonNegativeInteger(value)
  return value ~= nil and (value == '0' or (string.match(value, '^[1-9]%d*$') ~= nil and string.len(value) <= 16 and (string.len(value) < 16 or value <= '9007199254740991')))
end
local function validExactPositiveInteger(value)
  return value ~= nil and string.match(value, '^[1-9]%d*$') ~= nil and string.len(value) <= 16 and (string.len(value) < 16 or value <= '9007199254740991')
end
-- DEC-017: hard-block `expiresAt` is a Hash decimal string, not a ZSET score.
-- It is canonical, non-negative, within the backend expiry range BLOCK_SET can
-- create, and is only ever compared as a decimal string, never via tonumber().
local function validHardBlockExpiry(value)
  if value == nil or value == '0' then return value == '0' end
  return string.match(value, '^[1-9]%d*$') ~= nil and (#value < 16 or (#value == 16 and value <= '9223372036854775'))
end
local function validNonNegativeInteger(value)
  return value ~= nil and (value == '0' or (string.match(value, '^[1-9]%d*$') ~= nil and string.len(value) <= 19 and (string.len(value) < 19 or value <= '9223372036854775807')))
end
local function compareDecimalStrings(a, b)
  if #a ~= #b then return (#a < #b) and -1 or 1 end
  if a == b then return 0 end
  return (a < b) and -1 or 1
end
if not validExactPositiveInteger(ARGV[2]) then return redis.error_reply('hard-block expiry is not representable') end
if not validExactPositiveInteger(ARGV[6]) then return redis.error_reply('cycle boundary is not representable') end
if not validExactPositiveInteger(ARGV[8]) then return redis.error_reply('pause boundary is not representable') end
if not validExactPositiveInteger(ARGV[9]) then return redis.error_reply('pause retention boundary is not representable') end
local durationSeconds = tonumber(ARGV[2])
local cycleWindow = tonumber(ARGV[6])
local pauseSeconds = tonumber(ARGV[8])
local retention = tonumber(ARGV[9])
-- #153 phase 2: both the cycle path and the lifecycle path take the caller's
-- semantic time from ARGV[5] (DEC-016 rule 1); no Redis TIME.
if not validExactNonNegativeInteger(ARGV[5]) then return redis.error_reply('hard-block caller time is not representable') end
local now = tonumber(ARGV[5])
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
  if generation ~= lifecycleGeneration then return {0} end
  -- LT-P2-02: the logical deadline alone decides; PTTL only says the score is alive.
  if compareDecimalStrings(scoreExpiry, ARGV[5]) <= 0 then return {0} end
  if lifecycleId == '' or string.len(lifecycleId) ~= 32 then return redis.error_reply('malformed lifecycle id') end
  local evidenceCount = redis.call('HEXISTS', scoreKey, 'reentryId') + redis.call('HEXISTS', scoreKey, 'reentryValidUntil') + redis.call('HEXISTS', scoreKey, 'reentryGeneration')
  if evidenceCount ~= 0 and evidenceCount ~= 3 then return redis.error_reply('malformed lifecycle evidence') end
  if evidenceCount == 3 then
    local evidenceId = redis.call('HGET', scoreKey, 'reentryId'); local evidenceUntil = redis.call('HGET', scoreKey, 'reentryValidUntil'); local evidenceGeneration = redis.call('HGET', scoreKey, 'reentryGeneration')
    if not evidenceId or string.len(evidenceId) ~= 32 or string.match(evidenceId, '^[a-f0-9]+$') == nil then return redis.error_reply('malformed lifecycle id') end
    if not validNonNegativeInteger(evidenceUntil) or evidenceUntil == '0' or not validPositiveInteger(evidenceGeneration) then return redis.error_reply('malformed lifecycle evidence') end
  end
end
-- `needed` is at most the cycle window or the pause plus its retention, so
-- Redis cannot reject it once `now` passed the exact-range checks above; the
-- pcall keeps Redis the judge all the same (LT-P2-12).
local function extendUntil(key, target)
  if key == '' or redis.call('EXISTS', key) == 0 then return true end
  local ttl = redis.call('TTL', key)
  local needed = math.max(1, target - now)
  if ttl < needed then
    local reply = redis.pcall('EXPIRE', key, needed)
    if type(reply) == 'table' and reply.err then return false end
  end
  return true
end

-- Phase A: validate every existing structure and compute merged state without writes.
local cyclesByMember = {}; local cycleMembers = {}
local function readCycles(key)
  if key == '' or redis.call('EXISTS', key) == 0 then return end
  if redis.call('TTL', key) < 0 then error('malformed cycle history') end
  if redis.call('ZCARD', key) == 0 then error('malformed cycle history') end
  for _, member in ipairs(redis.call('ZRANGE', key, 0, -1)) do
    if not validExactNonNegativeInteger(member) then error('malformed cycle history') end
    local rawScore = redis.call('ZSCORE', key, member)
    if not validExactNonNegativeInteger(rawScore) then error('malformed cycle history') end
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
      if not validHardBlockExpiry(rawExpires) or not rawLevel then return redis.error_reply('malformed hard-block state') end
      local level = tonumber(rawLevel)
      if not level or level ~= math.floor(level) or level < 1 or level > 6 then return redis.error_reply('malformed hard-block state') end
      if compareDecimalStrings(rawExpires, string.format('%.0f', now)) > 0 and level >= 2 then active = true end
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
    if not validExactNonNegativeInteger(startStr) or not validExactNonNegativeInteger(finishStr) then error('malformed pause history') end
    local rawScore = redis.call('ZSCORE', key, member)
    if not validExactNonNegativeInteger(rawScore) then error('malformed pause history') end
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
-- The block is written first, so Redis judges its expiry (LT-P2-12) before any
-- other state changes: an existing block takes the expiry before its fields
-- change; a new block is deleted again when Redis rejects it.
local expires = now + durationSeconds
if redis.call('EXISTS', KEYS[3]) == 1 then
  local reply = redis.pcall('EXPIRE', KEYS[3], ARGV[2])
  if type(reply) == 'table' and reply.err then return redis.error_reply('expiry not representable') end
  redis.call('HSET', KEYS[3], 'level', ARGV[1], 'expiresAt', expires)
else
  redis.call('HSET', KEYS[3], 'level', ARGV[1], 'expiresAt', expires)
  local reply = redis.pcall('EXPIRE', KEYS[3], ARGV[2])
  if type(reply) == 'table' and reply.err then redis.call('DEL', KEYS[3]); return redis.error_reply('expiry not representable') end
end
for _, member in ipairs(cycleMembers) do redis.call('ZADD', KEYS[1], cyclesByMember[member], member) end
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', '(' .. (now - cycleWindow))
if redis.call('EXISTS', KEYS[5]) == 1 then
  for _, member in ipairs(redis.call('ZRANGE', KEYS[5], 0, -1)) do
    if pausesByMember[member] == nil then redis.call('ZREM', KEYS[5], member) end
  end
end
for _, member in ipairs(pauseMembers) do redis.call('ZADD', KEYS[5], pausesByMember[member][1], member) end
if #pauseMembers > 0 and not extendUntil(KEYS[5], latestPauseUntil + retention) then return redis.error_reply('expiry not representable') end
if cycleCount > 0 and not extendUntil(KEYS[1], latestCycle + cycleWindow) then return redis.error_reply('expiry not representable') end
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
-- DEC-017: persisted semantic timestamps (cycle members/scores, pause
-- start/finish/scores, hard-block `expiresAt`) are canonical non-negative
-- decimals, exact within Lua's integer range where ZSET arithmetic needs it.
local function validExactNonNegativeInteger(value)
  return value ~= nil and (value == '0' or (string.match(value, '^[1-9]%d*$') ~= nil and string.len(value) <= 16 and (string.len(value) < 16 or value <= '9007199254740991')))
end
if not validExactNonNegativeInteger(ARGV[2]) then return redis.error_reply('caller time is not representable') end
if not validExactNonNegativeInteger(ARGV[1]) then return redis.error_reply('caller from-timestamp is not representable') end
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
    if not validExactNonNegativeInteger(startStr) or not validExactNonNegativeInteger(finishStr) then error('malformed pause history') end
    local rawScore = redis.call('ZSCORE', source, member)
    if not validExactNonNegativeInteger(rawScore) then error('malformed pause history') end
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

    private readonly ClockInterface $fallbackClock;

    /**
     * The Host supplies the raw-command executor; this adapter owns the
     * namespace used for its keys. The namespace must match
     * [A-Za-z0-9._:-]{1,128}; an invalid namespace throws
     * RateLimiterException before the store can be used.
     *
     * The optional clock serves only the methods that receive no caller time
     * (a direct Host call of an existing contract method): it defaults to
     * `SystemClock(UTC)`, never Redis `TIME` (#153 phase 2, LT-P2-06). The
     * runtime always passes its own `now` through the caller-time methods.
     */
    public function __construct(
        private readonly RedisCommandExecutorInterface $redis,
        private readonly string $namespace,
        ?ClockInterface $clock = null,
    ) {
        if (! preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $namespace)) {
            throw new RateLimiterException('Redis namespace contains invalid characters or length.');
        }
        $this->fallbackClock = $clock ?? new SystemClock(new DateTimeZone('UTC'));
    }

    public function increment(string $key, int $ttlSeconds, int $amount = 1): int
    {
        return $this->incrementAt($key, $ttlSeconds, $amount, $this->fallbackNow());
    }

    public function incrementAt(string $key, int $ttlSeconds, int $amount, int $now): int
    {
        $this->positive($ttlSeconds, 'Score TTL');
        $result = $this->eval(self::SCORE_INCREMENT, [$this->key('score', $key)], [$ttlSeconds, $amount, $this->callerTime($now)]);
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
        $this->setAt($key, $value, $ttlSeconds, $this->fallbackNow());
    }

    public function setAt(string $key, int $value, int $ttlSeconds, int $now): void
    {
        $this->positive($ttlSeconds, 'Score TTL');
        $this->eval(self::SCORE_SET, [$this->key('score', $key)], [$ttlSeconds, $value, $this->callerTime($now)]);
    }

    public function block(string $key, int $level, int $durationSeconds): void
    {
        $this->blockAt($key, $level, $durationSeconds, $this->fallbackNow());
    }

    public function blockAt(string $key, int $level, int $durationSeconds, int $now): void
    {
        $this->positive($durationSeconds, 'Block duration');
        $this->validateBaseBlockLevel($level);
        $this->eval(self::BLOCK_SET, [$this->key('block', $key)], [$level, $durationSeconds, $this->callerTime($now)]);
    }

    public function checkBlock(string $key): ?BlockStateDTO
    {
        return $this->checkBlockAt($key, $this->fallbackNow());
    }

    public function checkBlockAt(string $key, int $now): ?BlockStateDTO
    {
        $result = $this->eval(self::BLOCK_GET, [$this->key('block', $key)], [$this->callerTime($now)]);
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
        return $this->getBudgetAt($key, $this->fallbackNow());
    }

    public function getBudgetAt(string $key, int $now): ?BudgetStateDTO
    {
        $result = $this->eval(self::BUDGET_GET, [$this->key('budget', $key)], [$this->callerTime($now)]);
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
        return $this->incrementBudgetAt($key, $epochDurationSeconds, $amount, $this->fallbackNow());
    }

    public function incrementBudgetAt(string $key, int $epochDurationSeconds, int $amount, int $now): BudgetStateDTO
    {
        $this->positive($epochDurationSeconds, 'Budget epoch duration');
        $result = $this->eval(self::BUDGET_INCREMENT, [$this->key('budget', $key)], [$epochDurationSeconds, $amount, $this->callerTime($now)]);
        $tuple = $this->tuple($result, 2, 'budget increment');
        return new BudgetStateDTO(
            $this->integerValue($tuple[0], 'budget count'),
            $this->integerValue($tuple[1], 'budget epochStart'),
        );
    }

    public function incrementBudgetWithSeed(string $key, int $epochDurationSeconds, BudgetStateDTO $seed, int $amount = 1): BudgetStateDTO
    {
        return $this->incrementBudgetWithSeedAt($key, $epochDurationSeconds, $seed, $amount, $this->fallbackNow());
    }

    public function incrementBudgetWithSeedAt(string $key, int $epochDurationSeconds, BudgetStateDTO $seed, int $amount, int $now): BudgetStateDTO
    {
        $this->positive($epochDurationSeconds, 'Budget epoch duration');
        $seededCount = $this->tryIntegerAddition($seed->count, $amount);
        $result = $this->eval(self::BUDGET_SEED, [$this->key('budget', $key)], [$epochDurationSeconds, $seed->epochStart, $seed->count, $amount, $seededCount ?? 0, $seededCount === null ? 1 : 0, $this->callerTime($now)]);
        $tuple = $this->tuple($result, 2, 'seeded budget');
        return new BudgetStateDTO(
            $this->integerValue($tuple[0], 'budget count'),
            $this->integerValue($tuple[1], 'budget epochStart'),
        );
    }

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        return $this->addDistinctAt($key, $item, $ttlSeconds, $this->fallbackNow());
    }

    public function addDistinctAt(string $key, string $item, int $ttlSeconds, int $now): int
    {
        $this->positive($ttlSeconds, 'Correlation TTL');
        return $this->integerResult($this->eval(self::DISTINCT_ADD, [$this->key('distinct', $key), $this->key('distinct-meta', $key)], [$ttlSeconds, $item, $this->callerTime($now)]), 'distinct count');
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        return $this->incrementWatchFlagAt($key, $ttlSeconds, $this->fallbackNow());
    }

    public function incrementWatchFlagAt(string $key, int $ttlSeconds, int $now): int
    {
        $this->positive($ttlSeconds, 'Correlation TTL');
        return $this->integerResult($this->eval(self::WATCH_INCREMENT, [$this->key('watch', $key), $this->key('watch-meta', $key)], [$ttlSeconds, $this->callerTime($now)]), 'watch count');
    }

    public function getWatchFlag(string $key): int
    {
        return $this->integerResult($this->eval(self::WATCH_GET, [$this->key('watch', $key)], []), 'watch count');
    }

    public function addDistinctBounded(string $key, string $item, int $ttlSeconds, int $maxDistinct): BoundedDistinctResultDTO
    {
        return $this->addDistinctBoundedAt($key, $item, $ttlSeconds, $maxDistinct, $this->fallbackNow());
    }

    public function addDistinctBoundedAt(string $key, string $item, int $ttlSeconds, int $maxDistinct, int $now): BoundedDistinctResultDTO
    {
        $snapshot = $this->addDistinctBoundedWithSnapshotAt($key, $item, $ttlSeconds, $maxDistinct, $now);
        return new BoundedDistinctResultDTO($snapshot->count, $snapshot->accepted);
    }

    public function addDistinctBoundedWithSnapshot(string $key, string $item, int $ttlSeconds, int $maxDistinct): BoundedDistinctSnapshotDTO
    {
        return $this->addDistinctBoundedWithSnapshotAt($key, $item, $ttlSeconds, $maxDistinct, $this->fallbackNow());
    }

    public function addDistinctBoundedWithSnapshotAt(string $key, string $item, int $ttlSeconds, int $maxDistinct, int $now): BoundedDistinctSnapshotDTO
    {
        $this->positive($ttlSeconds, 'Correlation TTL');
        $this->positive($maxDistinct, 'Correlation capacity');
        $result = $this->eval(self::BOUNDED_SNAPSHOT, [$this->key('distinct', $key), $this->key('distinct-meta', $key)], [$ttlSeconds, $item, $maxDistinct, $this->callerTime($now)]);
        return $this->snapshot($result, $item, 'bounded snapshot');
    }

    public function addDistinctAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds): int
    {
        return $this->addDistinctAcrossRotationAt($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $this->fallbackNowMilliseconds());
    }

    public function addDistinctAcrossRotationAt(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $nowMilliseconds): int
    {
        $snapshot = $this->addDistinctBoundedWithSnapshotAcrossRotationAt($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, PHP_INT_MAX, $nowMilliseconds);
        return $snapshot->count;
    }

    public function addDistinctBoundedAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct): BoundedDistinctResultDTO
    {
        return $this->addDistinctBoundedAcrossRotationAt($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct, $this->fallbackNowMilliseconds());
    }

    public function addDistinctBoundedAcrossRotationAt(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct, int $nowMilliseconds): BoundedDistinctResultDTO
    {
        $snapshot = $this->addDistinctBoundedWithSnapshotAcrossRotationAt($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct, $nowMilliseconds);
        return new BoundedDistinctResultDTO($snapshot->count, $snapshot->accepted);
    }

    public function addDistinctBoundedWithSnapshotAcrossRotation(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct): BoundedDistinctSnapshotDTO
    {
        return $this->addDistinctBoundedWithSnapshotAcrossRotationAt($currentKey, $bridgeKey, $previousKey, $currentMember, $previousMember, $ttlSeconds, $maxDistinct, $this->fallbackNowMilliseconds());
    }

    public function addDistinctBoundedWithSnapshotAcrossRotationAt(string $currentKey, string $bridgeKey, string $previousKey, string $currentMember, string $previousMember, int $ttlSeconds, int $maxDistinct, int $nowMilliseconds): BoundedDistinctSnapshotDTO
    {
        $nowMilliseconds = $this->callerTime($nowMilliseconds);
        $this->positive($ttlSeconds, 'Correlation TTL');
        $this->positive($maxDistinct, 'Correlation capacity');
        $keys = [$this->key('distinct', $currentKey), $this->key('distinct-meta', $currentKey), $this->key('distinct', $bridgeKey), $this->key('distinct-meta', $bridgeKey), $this->key('distinct', $previousKey), $this->key('distinct-meta', $previousKey)];
        $result = $this->eval(self::ROTATED_SNAPSHOT, $keys, [self::BOUNDED_SNAPSHOT, $ttlSeconds, $currentMember, $previousMember, $maxDistinct, $nowMilliseconds, intdiv($nowMilliseconds, 1000)]);
        return $this->snapshot($result, $currentMember, 'rotated bounded snapshot');
    }

    public function incrementWatchFlagAcrossRotation(string $currentKey, string $previousKey, int $ttlSeconds): int
    {
        return $this->incrementWatchFlagAcrossRotationAt($currentKey, $previousKey, $ttlSeconds, $this->fallbackNow());
    }

    public function incrementWatchFlagAcrossRotationAt(string $currentKey, string $previousKey, int $ttlSeconds, int $now): int
    {
        $this->positive($ttlSeconds, 'Correlation TTL');
        $script = self::LUA_TIME_PRELUDE . "\n" . <<<'LUA'
local previous = 0
local function validBackendExpiry(value)
  if value == nil or value == '0' then return value == '0' end
  if string.match(value, '^[1-9]%d*$') == nil then return false end
  if #value > 16 then return false end
  if #value == 16 and value > '9223372036854775' then return false end
  return true
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
  if not ttlBackendRepresentable(ARGV[1]) then return redis.error_reply('correlation TTL exceeds Redis backend expiry representability') end
  value = 1
  redis.call('SET', KEYS[1], value)
  if not expiryApplied(redis.pcall('EXPIRE', KEYS[1], ARGV[1]), {KEYS[1]}) then return redis.error_reply('expiry not representable') end
  redis.call('HSET', KEYS[4], 'expiresAt', addDecimalStrings(nowStr, ARGV[1]))
  if not expiryApplied(redis.pcall('EXPIRE', KEYS[4], ARGV[1]), {KEYS[1], KEYS[4]}) then return redis.error_reply('expiry not representable') end
else value = redis.call('INCR', KEYS[1]) end
return value + previous
LUA;
        return $this->integerResult($this->eval($script, [$this->key('watch', $currentKey), $this->key('watch', $previousKey), $this->key('watch-meta', $previousKey), $this->key('watch-meta', $currentKey)], [$ttlSeconds, $this->callerTime($now)]), 'rotated watch count');
    }

    public function load(string $policyName): ?CircuitBreakerStateDTO
    {
        $raw = $this->circuitReadResult(
            $this->eval(self::CIRCUIT_READ, [$this->key('circuit', $policyName)], []),
        );
        if ($raw === null) {
            return null;
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
        $state = new CircuitBreakerStateDTO($data['status'], $failures, $data['lastFailure'], $data['openSince'], $data['lastSuccess'], $reEntries, $data['failClosedUntil']);
        if (! $this->circuitTimestampsNonNegative($state)) {
            throw new RateLimiterException('Malformed circuit-breaker timestamp.');
        }
        return $state;
    }

    public function save(string $policyName, CircuitBreakerStateDTO $state): void
    {
        if (! $this->circuitTimestampsNonNegative($state)) {
            throw new RateLimiterException('Circuit-breaker timestamps must be non-negative Unix timestamps.');
        }
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
        $result = $this->eval(self::LEASE, [$this->key('probe', $policyName)], [$now, $leaseSeconds, $now]);
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
        return $this->readGenerationBoundScoreStateAt($currentKey, $previousKey, $this->fallbackNowMilliseconds());
    }

    public function readGenerationBoundScoreStateAt(string $currentKey, ?string $previousKey, int $nowMilliseconds): ?GenerationBoundScoreStateDTO
    {
        $keys = [
            $this->key('score', $currentKey),
            $previousKey === null ? '' : $this->key('score', $previousKey),
            $this->key('block', $currentKey),
            $previousKey === null ? '' : $this->key('block', $previousKey),
        ];
        $result = $this->eval(self::LIFECYCLE_READ, $keys, [$this->callerTime($nowMilliseconds)]);
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
        return $this->mutateGenerationBoundScoreAt($currentKey, $previousKey, $expectedState, $ttlSeconds, $newValue, $this->fallbackNowMilliseconds());
    }

    public function mutateGenerationBoundScoreAt(string $currentKey, ?string $previousKey, ?GenerationBoundScoreStateDTO $expectedState, int $ttlSeconds, int $newValue, int $nowMilliseconds): GenerationBoundScoreMutationDTO
    {
        $this->positive($ttlSeconds, 'Generation-bound score TTL');
        $nowMilliseconds = $this->callerTime($nowMilliseconds);
        $this->ensureGenerationTtlRepresentable($ttlSeconds, $nowMilliseconds);
        $expectedSource = $expectedState === null ? '' : ($expectedState->source === GenerationBoundScoreStateDTO::SOURCE_CURRENT ? $this->key('score', $currentKey) : $this->key('score', $previousKey ?? ''));
        $result = $this->eval(
            self::LIFECYCLE_MUTATE,
            [$this->key('score', $currentKey), $previousKey === null ? '' : $this->key('score', $previousKey), $this->key('reentry-claim', $currentKey)],
            [$expectedSource, $expectedState === null ? '' : $expectedState->value, $expectedState === null ? '' : $expectedState->updatedAt, $expectedState === null || $expectedState->generation === null ? '' : $expectedState->generation, $expectedState === null ? '' : $expectedState->expiresAt, $ttlSeconds, $newValue, $nowMilliseconds],
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
     * The caller's time owns the publication timestamps (#153 phase 2): this
     * method uses the store's fallback clock, the caller-time variant the
     * request's own time. Source-race
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
        return $this->blockWithPunishmentLifecycleTrackingAt($currentKey, $previousKey, $expectedGeneration, $proposedLifecycleId, $level, $durationSeconds, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds, $this->fallbackNow());
    }

    public function blockWithPunishmentLifecycleTrackingAt(string $currentKey, ?string $previousKey, int $expectedGeneration, string $proposedLifecycleId, int $level, int $durationSeconds, int $cycleWindowSeconds, int $cycleThreshold, int $pauseSeconds, int $pauseHistoryRetentionSeconds, int $now): PunishmentLifecycleTransitionDTO
    {
        $now = $this->callerTime($now);
        $this->validatePunishmentLifecycleParameters($expectedGeneration, $level, $durationSeconds, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds);
        $id = preg_match('/\A[a-f0-9]{32}\z/D', $proposedLifecycleId) === 1 ? $proposedLifecycleId : bin2hex(random_bytes(16));
        $previousKey = $previousKey === $currentKey ? null : $previousKey;
        $keys = [
            $this->key('cycle', $currentKey), $previousKey === null ? '' : $this->key('cycle', $previousKey),
            $this->key('block', $currentKey), $previousKey === null ? '' : $this->key('block', $previousKey),
            $this->key('pause', $currentKey), $previousKey === null ? '' : $this->key('pause', $previousKey),
            $this->key('score', $currentKey), $previousKey === null ? '' : $this->key('score', $previousKey),
        ];
        $result = $this->eval(self::HARD_BLOCK, $keys, [$level, $durationSeconds, $currentKey, $previousKey ?? '', $now, $cycleWindowSeconds, $cycleThreshold, $pauseSeconds, $pauseHistoryRetentionSeconds, $expectedGeneration, $id]);
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
        return $this->claimPostPunishmentReentryAt($currentKey, $previousKey, $lifecycleId, $this->fallbackNowMilliseconds());
    }

    public function claimPostPunishmentReentryAt(string $currentKey, ?string $previousKey, string $lifecycleId, int $nowMilliseconds): bool
    {
        $keys = [$this->key('score', $currentKey), $previousKey === null ? '' : $this->key('score', $previousKey), $this->key('block', $currentKey), $previousKey === null ? '' : $this->key('block', $previousKey), $this->key('reentry-claim', $currentKey)];
        return $this->integerValue($this->eval(self::LIFECYCLE_CLAIM, $keys, [$lifecycleId, $this->callerTime($nowMilliseconds)]), 're-entry claim') === 1;
    }

    public function reserveAdmission(AdmissionReservationRequestDTO $request, int $now): AdmissionReservationResultDTO
    {
        return $this->admission()->reserve($request, $now);
    }

    public function applyAdmissionOutcome(AdmissionOutcomeDTO $outcome, int $now): void
    {
        $this->admission()->applyOutcome($outcome, $now);
    }

    public function readBindingEpoch(string $key, ?string $previousKey): int
    {
        return $this->admission()->readEpoch($key, $previousKey);
    }

    public function bumpBindingEpoch(string $key, ?string $previousKey): int
    {
        return $this->admission()->bumpEpoch($key, $previousKey);
    }

    public function claimAdmissionPermit(
        string $permitId,
        string $lane,
        string $subject,
        string $operation,
        AdmissionOutcomeKindEnum $outcome,
        int $now,
    ): AdmissionPermitClaimEnum {
        return $this->admission()->claimPermit($permitId, $lane, $subject, $operation, $outcome, $now);
    }

    private function admission(): RedisAdmissionOperations
    {
        return $this->admission ??= new RedisAdmissionOperations($this->redis, $this->namespace);
    }

    /**
     * Healthy only for a supported PING reply representation (DEC-018).
     *
     * BackendFailureException is unhealthy; every other throwable propagates
     * unchanged.
     */
    public function isHealthy(): bool
    {
        try {
            return $this->pingReplyIsHealthy($this->command(['PING']));
        } catch (BackendFailureException) {
            return false;
        }
    }

    /**
     * Official Redis adapter interpretation of the native PING reply: a string
     * client returns 'PONG'; ext-redis rawCommand() returns bool(true) for the
     * status reply. Strict identity only, never truthiness (DEC-018).
     */
    private function pingReplyIsHealthy(mixed $reply): bool
    {
        return $reply === 'PONG' || $reply === true;
    }

    /**
     * Strict parser for the CIRCUIT_READ tagged reply. Only the exact tuples
     * ['absent'], ['value', string], and ['error', string] are accepted. A
     * Redis command error is an explicit package failure: never absent state,
     * never BackendFailureException (DEC-015).
     */
    private function circuitReadResult(mixed $reply): ?string
    {
        if (is_array($reply) && array_is_list($reply) && $reply !== []) {
            $count = count($reply);
            if ($reply[0] === 'absent' && $count === 1) {
                return null;
            }
            if ($reply[0] === 'value' && $count === 2 && is_string($reply[1])) {
                return $reply[1];
            }
            if ($reply[0] === 'error' && $count === 2 && is_string($reply[1])) {
                throw new RateLimiterException('Redis rejected the circuit-breaker state read: ' . $reply[1]);
            }
        }
        throw new RateLimiterException('Malformed circuit-breaker response.');
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

    /** DEC-017: the caller's semantic time is a non-negative Unix timestamp. */
    private function callerTime(int $now): int
    {
        $this->nonNegativeSemanticTimestamp($now, 'Caller time');

        return $now;
    }

    /** The time of an existing contract method called directly: the store's clock, never Redis `TIME`. */
    private function fallbackNow(): int
    {
        return $this->fallbackClock->now()->getTimestamp();
    }

    /** The same fallback time, in milliseconds, for the operations that derive millisecond lifetimes. */
    private function fallbackNowMilliseconds(): int
    {
        return (int) $this->fallbackClock->now()->format('Uv');
    }

    private function positive(int $value, string $label): void
    {
        if ($value <= 0) {
            throw new RateLimiterException($label . ' must be positive.');
        }
    }

    /**
     * DEC-017: every circuit-breaker temporal field is a non-negative Unix
     * timestamp; `0` stays valid where circuit semantics already use it.
     */
    private function circuitTimestampsNonNegative(CircuitBreakerStateDTO $state): bool
    {
        foreach ([$state->lastFailure, $state->openSince, $state->lastSuccess, $state->failClosedUntil, ...$state->failures, ...$state->reEntries] as $timestamp) {
            if ($timestamp < 0) {
                return false;
            }
        }
        return true;
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

    /**
     * The generation-bound score's expiry stays inside Lua's exact integer
     * range, judged on the caller's time (#153 phase 2, LT-P2-10); the script
     * repeats the same check atomically on the same value.
     */
    private function ensureGenerationTtlRepresentable(int $ttlSeconds, int $nowMilliseconds): void
    {
        if ($ttlSeconds > intdiv(PHP_INT_MAX, 1000)) {
            throw new RateLimiterException('Generation-bound score expiry is not representable.');
        }
        if ($nowMilliseconds > self::LUA_EXACT_INTEGER_MAX || $ttlSeconds > intdiv(self::LUA_EXACT_INTEGER_MAX - $nowMilliseconds, 1000)) {
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
