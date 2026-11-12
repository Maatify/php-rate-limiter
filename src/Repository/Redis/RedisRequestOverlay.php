<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository\Redis;

/**
 * Lua building blocks of the request-atomic transaction used by
 * {@see RedisFullCapabilityStore::transactRequest()}.
 *
 * Every store script of one request runs unchanged against a request overlay:
 * `redis.call` / `redis.pcall` are replaced by an implementation that reads
 * live state once per key, keeps every later read and write in the overlay,
 * and never issues a write command. Each evaluation returns the changed or
 * newly read key states, which PHP keeps and sends back with the next call, so
 * every step sees the results of the earlier steps exactly as it would after a
 * real write. When the whole request has been evaluated, one commit script
 * compares every key it read with the state it was built on, and only if all
 * of them still match applies every change in one batch.
 *
 * @internal
 */
final class RedisRequestOverlay
{
    /**
     * Helpers shared by the overlay and the commit script. A key state is
     * `{t = 'n'|'s'|'h'|'e'|'z', v = value, x = absolute expiry in ms or nil,
     * o = fingerprint of the live state it was read from}`.
     */
    private const COMMON = <<<'LUA'
local __real = redis
local function __ser(t, v, x)
  local parts = {t}
  if t == 's' then
    parts[2] = v
  elseif t == 'h' or t == 'z' or t == 'e' then
    local names = {}
    for name in pairs(v) do names[#names + 1] = name end
    table.sort(names)
    for _, name in ipairs(names) do
      parts[#parts + 1] = #name .. ':' .. name
      if t ~= 'e' then
        local value = v[name]
        parts[#parts + 1] = #value .. ':' .. value
      end
    end
  end
  parts[#parts + 1] = x or '-'
  return table.concat(parts, '\0')
end
local function __loadLive(key)
  if __real.call('EXISTS', key) == 0 then return {t = 'n', o = 'n'} end
  local kind = __real.call('TYPE', key)
  if type(kind) == 'table' then kind = kind.ok end
  local e = {}
  local ms = __real.call('PEXPIRETIME', key)
  if ms >= 0 then e.x = string.format('%.0f', ms) end
  if kind == 'string' then
    e.t = 's'
    e.v = __real.call('GET', key)
  elseif kind == 'hash' then
    e.t = 'h'
    e.v = {}
    local flat = __real.call('HGETALL', key)
    e.f = {}
    for i = 1, #flat, 2 do e.v[flat[i]] = flat[i + 1]; e.f[#e.f + 1] = flat[i] end
  elseif kind == 'set' then
    e.t = 'e'
    e.v = {}
    for _, member in ipairs(__real.call('SMEMBERS', key)) do e.v[member] = true end
  elseif kind == 'zset' then
    e.t = 'z'
    e.v = {}
    local flat = __real.call('ZRANGE', key, 0, -1, 'WITHSCORES')
    for i = 1, #flat, 2 do e.v[flat[i]] = flat[i + 1] end
  else
    -- A type the overlay does not model is answered as Redis answers a script that reads a key of the wrong type: with the
    -- WRONGTYPE code. A message without a Redis error code reaches phpredis as an untyped RedisException instead of the reply
    -- the store classifies as a RateLimiterException.
    error({err = 'WRONGTYPE Operation against a key holding the wrong kind of value'})
  end
  e.o = __real.sha1hex(__ser(e.t, e.v, e.x))
  return e
end
LUA;

    /**
     * Runs before the wrapped store script. Reads the overlay payload off the
     * end of ARGV so the script sees exactly the arguments it was written for.
     */
    private const OVERLAY_HEAD = <<<'LUA'
local __payload = cjson.decode(ARGV[#ARGV])
ARGV[#ARGV] = nil
local __clock = __real.call('TIME')
local __nowMs = tonumber(__clock[1]) * 1000 + math.floor(tonumber(__clock[2]) / 1000)
local __nowMsStr = string.format('%.0f', __nowMs)
local __int64Max = '9223372036854775807'
local __wrongType = 'WRONGTYPE Operation against a key holding the wrong kind of value'
local __notInteger = 'ERR value is not an integer or out of range'

local __E = {}
local __declared = {}
for _, key in ipairs(KEYS) do __declared[key] = true end
for _, pair in ipairs(__payload) do __E[pair[1]] = cjson.decode(pair[2]) end

local function __cmpDec(a, b)
  if #a ~= #b then return (#a < #b) and -1 or 1 end
  if a == b then return 0 end
  return (a < b) and -1 or 1
end
local function __addDec(a, b)
  local ra, rb = a:reverse(), b:reverse()
  local out, carry = {}, 0
  local n = math.max(#ra, #rb)
  for i = 1, n do
    local s = (tonumber(ra:sub(i, i)) or 0) + (tonumber(rb:sub(i, i)) or 0) + carry
    carry = s >= 10 and 1 or 0
    out[i] = tostring(s % 10)
  end
  if carry == 1 then out[n + 1] = '1' end
  local rev = {}
  for i = #out, 1, -1 do rev[#rev + 1] = out[i] end
  return table.concat(rev)
end
local function __subDec(a, b)
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
-- Exact signed 64-bit addition of two canonical decimal strings; nil on overflow.
local function __signedAdd(a, b)
  local an, bn = string.sub(a, 1, 1) == '-', string.sub(b, 1, 1) == '-'
  local ad, bd = an and string.sub(a, 2) or a, bn and string.sub(b, 2) or b
  local negative, magnitude
  if an == bn then
    negative, magnitude = an, __addDec(ad, bd)
  elseif __cmpDec(ad, bd) >= 0 then
    negative, magnitude = an, __subDec(ad, bd)
  else
    negative, magnitude = bn, __subDec(bd, ad)
  end
  if magnitude == '0' then return '0' end
  if __cmpDec(magnitude, negative and '9223372036854775808' or __int64Max) > 0 then return nil end
  return (negative and '-' or '') .. magnitude
end
local function __int64(raw, message)
  if type(raw) ~= 'string' or string.match(raw, '^%-?%d+$') == nil then error({err = message}) end
  local negative = string.sub(raw, 1, 1) == '-'
  local digits = negative and string.sub(raw, 2) or raw
  if (#digits > 1 and string.sub(digits, 1, 1) == '0') or (negative and digits == '0')
    or __cmpDec(digits, negative and '9223372036854775808' or __int64Max) > 0 then error({err = message}) end
  return raw
end
local function __str(value)
  if type(value) == 'number' then return string.format('%.17g', value) end
  return value
end
local function __ent(key)
  if not __declared[key] then error({err = 'request overlay: key is not declared in KEYS: ' .. tostring(key)}) end
  local e = __E[key]
  if e == nil then
    e = __loadLive(key)
    e.new = true
    __E[key] = e
  end
  if e.t ~= 'n' and e.x ~= nil and tonumber(e.x) <= __nowMs then
    e.t = 'n'; e.v = nil; e.f = nil; e.x = nil
  end
  return e
end
local function __touch(e) e.d = 1; e.mod = true end
local function __need(e, t)
  if e.t ~= 'n' and e.t ~= t then error({err = __wrongType}) end
end
local function __create(e, t)
  if e.t == 'n' then
    e.t = t
    e.v = (t == 's') and '' or {}
    if t == 'h' then e.f = {} end
    e.x = nil
  end
end
local function __drop(e)
  e.t = 'n'; e.v = nil; e.f = nil; e.x = nil
  __touch(e)
end
local function __emptied(e)
  if e.t ~= 'n' and e.t ~= 's' and next(e.v) == nil then __drop(e) end
end
local function __parseInt(raw)
  raw = __str(raw)
  if type(raw) ~= 'string' or string.match(raw, '^%-?%d+$') == nil then error({err = __notInteger}) end
  local negative = string.sub(raw, 1, 1) == '-'
  local digits = negative and string.sub(raw, 2) or raw
  if (#digits > 1 and string.sub(digits, 1, 1) == '0') or (negative and digits == '0') then error({err = __notInteger}) end
  if #digits > 19 or (#digits == 19 and digits > __int64Max) then error({err = __notInteger}) end
  return negative, digits
end
local function __setExpiry(e, raw, unitMs, name)
  local negative, digits = __parseInt(raw)
  if e.t == 'n' then return 0 end
  if negative or digits == '0' then __drop(e); return 1 end
  local ms = (unitMs == 1000) and (digits .. '000') or digits
  local absolute = __addDec(ms, __nowMsStr)
  if __cmpDec(ms, __int64Max) > 0 or __cmpDec(absolute, __int64Max) > 0 then
    error({err = "ERR invalid expire time in '" .. name .. "' command"})
  end
  e.x = absolute
  __touch(e)
  return 1
end
-- PEXPIREAT: the expiry is the given absolute time in milliseconds; a time at or before now removes the key, as in Redis.
local function __setExpiryAt(e, raw)
  local negative, digits = __parseInt(raw)
  if e.t == 'n' then return 0 end
  if negative or __cmpDec(digits, __nowMsStr) <= 0 then __drop(e); return 1 end
  e.x = digits
  __touch(e)
  return 1
end
local function __rangeBound(raw, isMin)
  raw = __str(raw)
  if raw == '-inf' then return -math.huge, false end
  if raw == '+inf' or raw == 'inf' then return math.huge, false end
  local exclusive = string.sub(raw, 1, 1) == '('
  local number = tonumber(exclusive and string.sub(raw, 2) or raw)
  if number == nil then error({err = 'ERR min or max is not a float'}) end
  return number, exclusive
end
local function __sortedMembers(e)
  local members = {}
  for member in pairs(e.v) do members[#members + 1] = member end
  table.sort(members, function(a, b)
    local sa, sb = tonumber(e.v[a]), tonumber(e.v[b])
    if sa ~= sb then return sa < sb end
    return a < b
  end)
  return members
end

local __commands = {}
__commands.EXISTS = function(...)
  local count = 0
  for _, key in ipairs({...}) do
    if __ent(key).t ~= 'n' then count = count + 1 end
  end
  return count
end
__commands.DEL = function(...)
  local count = 0
  for _, key in ipairs({...}) do
    local e = __ent(key)
    if e.t ~= 'n' then count = count + 1; __drop(e) end
  end
  return count
end
__commands.GET = function(key)
  local e = __ent(key); __need(e, 's')
  if e.t == 'n' then return false end
  return e.v
end
__commands.SET = function(key, value, ...)
  local e = __ent(key)
  local nx, keepTtl, ttl = false, false, nil
  local opts = {...}
  local i = 1
  while i <= #opts do
    local opt = string.upper(__str(opts[i]))
    if opt == 'NX' then nx = true
    elseif opt == 'KEEPTTL' then keepTtl = true
    elseif opt == 'EX' then i = i + 1; ttl = opts[i]
    else error({err = 'ERR syntax error'}) end
    i = i + 1
  end
  if nx and e.t ~= 'n' then return false end
  local previousExpiry = e.x
  e.t = 's'; e.v = __str(value); e.x = nil
  if keepTtl then e.x = previousExpiry end
  __touch(e)
  if ttl ~= nil then __setExpiry(e, ttl, 1000, 'set') end
  return {ok = 'OK'}
end
__commands.HGET = function(key, field)
  local e = __ent(key); __need(e, 'h')
  if e.t == 'n' then return false end
  local value = e.v[__str(field)]
  if value == nil then return false end
  return value
end
__commands.HEXISTS = function(key, field)
  local e = __ent(key); __need(e, 'h')
  if e.t == 'n' or e.v[__str(field)] == nil then return 0 end
  return 1
end
__commands.HSET = function(key, ...)
  local pairsList = {...}
  if #pairsList == 0 or #pairsList % 2 ~= 0 then error({err = "ERR wrong number of arguments for 'hset' command"}) end
  local e = __ent(key); __need(e, 'h')
  __create(e, 'h')
  local added = 0
  for i = 1, #pairsList, 2 do
    local field = __str(pairsList[i])
    if e.v[field] == nil then added = added + 1; e.f[#e.f + 1] = field end
    e.v[field] = __str(pairsList[i + 1])
  end
  __touch(e)
  return added
end
__commands.HINCRBY = function(key, field, amount)
  local e = __ent(key); __need(e, 'h')
  field = __str(field)
  local step = __int64(__str(amount), __notInteger)
  local current = '0'
  if e.t ~= 'n' and e.v[field] ~= nil then current = __int64(e.v[field], 'ERR hash value is not an integer') end
  local result = __signedAdd(current, step)
  if result == nil then error({err = 'ERR increment or decrement would overflow'}) end
  __create(e, 'h')
  if e.v[field] == nil then e.f[#e.f + 1] = field end
  e.v[field] = result
  __touch(e)
  return tonumber(result)
end
__commands.INCR = function(key)
  local e = __ent(key); __need(e, 's')
  local current = '0'
  if e.t ~= 'n' then current = __int64(e.v, __notInteger) end
  local result = __signedAdd(current, '1')
  if result == nil then error({err = 'ERR increment or decrement would overflow'}) end
  __create(e, 's')
  e.v = result
  __touch(e)
  return tonumber(result)
end
__commands.HDEL = function(key, ...)
  local e = __ent(key); __need(e, 'h')
  if e.t == 'n' then return 0 end
  local removed = 0
  for _, field in ipairs({...}) do
    field = __str(field)
    if e.v[field] ~= nil then
      e.v[field] = nil
      removed = removed + 1
      for i, name in ipairs(e.f) do
        if name == field then table.remove(e.f, i); break end
      end
    end
  end
  if removed > 0 then __touch(e); __emptied(e) end
  return removed
end
__commands.SADD = function(key, ...)
  local e = __ent(key); __need(e, 'e')
  __create(e, 'e')
  local added = 0
  for _, member in ipairs({...}) do
    member = __str(member)
    if e.v[member] == nil then e.v[member] = true; added = added + 1 end
  end
  if added > 0 then __touch(e) end
  return added
end
__commands.SCARD = function(key)
  local e = __ent(key); __need(e, 'e')
  if e.t == 'n' then return 0 end
  local count = 0
  for _ in pairs(e.v) do count = count + 1 end
  return count
end
__commands.SMEMBERS = function(key)
  local e = __ent(key); __need(e, 'e')
  local members = {}
  if e.t == 'n' then return members end
  for member in pairs(e.v) do members[#members + 1] = member end
  table.sort(members)
  return members
end
__commands.SISMEMBER = function(key, member)
  local e = __ent(key); __need(e, 'e')
  if e.t == 'n' or e.v[__str(member)] == nil then return 0 end
  return 1
end
__commands.ZADD = function(key, ...)
  local args = {...}
  local onlyLess = false
  if #args > 0 and type(args[1]) == 'string' and string.upper(args[1]) == 'LT' then
    onlyLess = true
    table.remove(args, 1)
  end
  if #args == 0 or #args % 2 ~= 0 then error({err = "ERR syntax error"}) end
  local e = __ent(key); __need(e, 'z')
  __create(e, 'z')
  local added = 0
  for i = 1, #args, 2 do
    local score = tonumber(__str(args[i]))
    if score == nil then error({err = 'ERR value is not a valid float'}) end
    local member = __str(args[i + 1])
    local current = e.v[member]
    if current == nil then
      added = added + 1
      e.v[member] = string.format('%.17g', score)
      __touch(e)
    elseif not onlyLess or score < tonumber(current) then
      if tonumber(current) ~= score then
        e.v[member] = string.format('%.17g', score)
        __touch(e)
      end
    end
  end
  __emptied(e)
  return added
end
__commands.ZSCORE = function(key, member)
  local e = __ent(key); __need(e, 'z')
  if e.t == 'n' then return false end
  local score = e.v[__str(member)]
  if score == nil then return false end
  return score
end
__commands.ZCARD = function(key)
  local e = __ent(key); __need(e, 'z')
  if e.t == 'n' then return 0 end
  local count = 0
  for _ in pairs(e.v) do count = count + 1 end
  return count
end
__commands.ZRANGE = function(key, first, last, withScores)
  local e = __ent(key); __need(e, 'z')
  if e.t == 'n' then return {} end
  if tonumber(__str(first)) ~= 0 or tonumber(__str(last)) ~= -1 then error({err = 'request overlay supports only ZRANGE key 0 -1'}) end
  local members = __sortedMembers(e)
  if withScores == nil then return members end
  if string.upper(__str(withScores)) ~= 'WITHSCORES' then error({err = 'ERR syntax error'}) end
  local flat = {}
  for _, member in ipairs(members) do flat[#flat + 1] = member; flat[#flat + 1] = e.v[member] end
  return flat
end
__commands.ZRANGEBYSCORE = function(key, min, max, ...)
  local e = __ent(key); __need(e, 'z')
  if e.t == 'n' then return {} end
  local low, lowExclusive = __rangeBound(min, true)
  local high, highExclusive = __rangeBound(max, false)
  local withScores, offset, count = false, 0, -1
  local opts = {...}
  local i = 1
  while i <= #opts do
    local opt = string.upper(__str(opts[i]))
    if opt == 'WITHSCORES' then withScores = true
    elseif opt == 'LIMIT' then offset = tonumber(__str(opts[i + 1])); count = tonumber(__str(opts[i + 2])); i = i + 2
    else error({err = 'ERR syntax error'}) end
    i = i + 1
  end
  local out, seen = {}, 0
  for _, member in ipairs(__sortedMembers(e)) do
    local score = tonumber(e.v[member])
    local aboveLow = lowExclusive and score > low or (not lowExclusive and score >= low)
    local belowHigh = highExclusive and score < high or (not highExclusive and score <= high)
    if aboveLow and belowHigh then
      seen = seen + 1
      if seen > offset and (count < 0 or #out / (withScores and 2 or 1) < count) then
        out[#out + 1] = member
        if withScores then out[#out + 1] = e.v[member] end
      end
    end
  end
  return out
end
__commands.ZCOUNT = function(key, min, max)
  local e = __ent(key); __need(e, 'z')
  if e.t == 'n' then return 0 end
  local low, lowExclusive = __rangeBound(min, true)
  local high, highExclusive = __rangeBound(max, false)
  local total = 0
  for _, raw in pairs(e.v) do
    local score = tonumber(raw)
    local aboveLow = lowExclusive and score > low or (not lowExclusive and score >= low)
    local belowHigh = highExclusive and score < high or (not highExclusive and score <= high)
    if aboveLow and belowHigh then total = total + 1 end
  end
  return total
end
__commands.HMGET = function(key, ...)
  local e = __ent(key); __need(e, 'h')
  local out = {}
  for i, field in ipairs({...}) do
    local value = (e.t ~= 'n') and e.v[__str(field)] or nil
    out[i] = (value == nil) and false or value
  end
  return out
end
__commands.TYPE = function(key)
  local e = __ent(key)
  local names = {n = 'none', s = 'string', h = 'hash', e = 'set', z = 'zset'}
  return {ok = names[e.t]}
end
__commands.ZREM = function(key, ...)
  local e = __ent(key); __need(e, 'z')
  if e.t == 'n' then return 0 end
  local removed = 0
  for _, member in ipairs({...}) do
    member = __str(member)
    if e.v[member] ~= nil then e.v[member] = nil; removed = removed + 1 end
  end
  if removed > 0 then __touch(e); __emptied(e) end
  return removed
end
__commands.ZREMRANGEBYSCORE = function(key, min, max)
  local e = __ent(key); __need(e, 'z')
  if e.t == 'n' then return 0 end
  local low, lowExclusive = __rangeBound(min, true)
  local high, highExclusive = __rangeBound(max, false)
  local removed = 0
  for member, raw in pairs(e.v) do
    local score = tonumber(raw)
    local aboveLow = lowExclusive and score > low or (not lowExclusive and score >= low)
    local belowHigh = highExclusive and score < high or (not highExclusive and score <= high)
    if aboveLow and belowHigh then e.v[member] = nil; removed = removed + 1 end
  end
  if removed > 0 then __touch(e); __emptied(e) end
  return removed
end
__commands.PEXPIRETIME = function(key)
  local e = __ent(key)
  if e.t == 'n' then return -2 end
  if e.x == nil then return -1 end
  return tonumber(e.x)
end
__commands.PTTL = function(key)
  local e = __ent(key)
  if e.t == 'n' then return -2 end
  if e.x == nil then return -1 end
  return tonumber(e.x) - __nowMs
end
__commands.TTL = function(key)
  local e = __ent(key)
  if e.t == 'n' then return -2 end
  if e.x == nil then return -1 end
  return math.floor((tonumber(e.x) - __nowMs + 500) / 1000)
end
__commands.EXPIRE = function(key, seconds) return __setExpiry(__ent(key), seconds, 1000, 'expire') end
__commands.PEXPIRE = function(key, millis) return __setExpiry(__ent(key), millis, 1, 'pexpire') end
__commands.PEXPIREAT = function(key, millis) return __setExpiryAt(__ent(key), millis) end
__commands.DUMP = function(key)
  local e = __ent(key)
  if e.t == 'n' then return false end
  return __ser(e.t, e.v, e.x)
end

local function __call(name, ...)
  local command = __commands[string.upper(__str(name))]
  if command == nil then error({err = 'request overlay does not support command ' .. tostring(name)}) end
  return command(...)
end
local __overlayRedis = setmetatable({
  call = __call,
  pcall = function(name, ...)
    local ok, reply = pcall(__call, name, ...)
    if ok then return reply end
    if type(reply) == 'table' and reply.err then return reply end
    return {err = tostring(reply)}
  end,
}, {__index = __real})
LUA;

    private const OVERLAY_TAIL = <<<'LUA'
if type(__ov_result) == 'table' and __ov_result.err then return __ov_result end
local __delta = {}
for key, e in pairs(__E) do
  if e.new or e.mod then
    e.new = nil
    e.mod = nil
    __delta[#__delta + 1] = {key, cjson.encode(e)}
  end
end
return {cjson.encode(__delta), __ov_result}
LUA;

    /**
     * KEYS: every key of the request; ARGV[1]: JSON `[[key, state], ...]`.
     * Returns 0 and writes nothing when any key no longer matches the state
     * it was read from; otherwise applies every changed key and returns 1.
     * With ARGV[2] = 'verify' it only answers whether every key still matches.
     */
    private const COMMIT = <<<'LUA'
local __payload = cjson.decode(ARGV[1])
local __clock = __real.call('TIME')
local __nowMs = tonumber(__clock[1]) * 1000 + math.floor(tonumber(__clock[2]) / 1000)
local __declared = {}
for _, key in ipairs(KEYS) do __declared[key] = true end
local __entries = {}
for _, pair in ipairs(__payload) do
  if not __declared[pair[1]] then return redis.error_reply('request commit: key is not declared in KEYS') end
  __entries[#__entries + 1] = {pair[1], cjson.decode(pair[2])}
end
for _, entry in ipairs(__entries) do
  if __loadLive(entry[1]).o ~= entry[2].o then return 0 end
end
if ARGV[2] == 'verify' then return 1 end
-- The whole plan is validated before the first write: a script error after a write has started cannot be undone.
for _, entry in ipairs(__entries) do
  local e = entry[2]
  if e.d == 1 then
    local valid = e.t == 'n' or e.t == 's' or e.t == 'h' or e.t == 'e' or e.t == 'z'
    if valid and e.t == 's' and type(e.v) ~= 'string' then valid = false end
    if valid and (e.t == 'h' or e.t == 'e' or e.t == 'z') then
      if type(e.v) ~= 'table' or next(e.v) == nil then valid = false else
        for name, value in pairs(e.v) do
          if type(name) ~= 'string' then valid = false end
          if e.t == 'h' and type(value) ~= 'string' then valid = false end
          if e.t == 'z' and (type(value) ~= 'string' or tonumber(value) == nil) then valid = false end
          if e.t == 'e' and value ~= true then valid = false end
        end
      end
    end
    if valid and e.t == 'h' then
      local ordered = 0
      if type(e.f) ~= 'table' then valid = false else
        for _, field in ipairs(e.f) do
          ordered = ordered + 1
          if e.v[field] == nil then valid = false end
        end
        local total = 0
        for _ in pairs(e.v) do total = total + 1 end
        if total ~= ordered then valid = false end
      end
    end
    if valid and e.x ~= nil and (type(e.x) ~= 'string' or string.match(e.x, '^[1-9]%d*$') == nil or #e.x > 19 or (#e.x == 19 and e.x > '9223372036854775807')) then valid = false end
    if not valid then return redis.error_reply('request commit: malformed plan for ' .. entry[1]) end
  end
end
local function __chunked(command, key, flat, step)
  local i = 1
  while i <= #flat do
    local chunk = {}
    for j = i, math.min(i + 199, #flat) do chunk[#chunk + 1] = flat[j] end
    __real.call(command, key, unpack(chunk))
    i = i + 200
  end
end
for _, entry in ipairs(__entries) do
  local key, e = entry[1], entry[2]
  if e.d == 1 then
    __real.call('DEL', key)
    if e.t == 's' then
      __real.call('SET', key, e.v)
    elseif e.t == 'h' then
      local flat = {}
      for _, field in ipairs(e.f) do flat[#flat + 1] = field; flat[#flat + 1] = e.v[field] end
      __chunked('HSET', key, flat)
    elseif e.t == 'e' then
      local flat = {}
      for member in pairs(e.v) do flat[#flat + 1] = member end
      __chunked('SADD', key, flat)
    elseif e.t == 'z' then
      local flat = {}
      for member, score in pairs(e.v) do flat[#flat + 1] = score; flat[#flat + 1] = member end
      __chunked('ZADD', key, flat)
    end
    if e.t ~= 'n' and e.x ~= nil then
      if tonumber(e.x) <= __nowMs then __real.call('DEL', key) else __real.call('PEXPIREAT', key, e.x) end
    end
  end
end
return 1
LUA;

    /** Wrap a store script so it runs against the request overlay. */
    public static function wrap(string $script): string
    {
        return self::COMMON . "\n" . self::OVERLAY_HEAD . "\n"
            . "local __ov_result = (function(redis)\n" . $script . "\nend)(__overlayRedis)\n"
            . self::OVERLAY_TAIL;
    }

    public static function commitScript(): string
    {
        return self::COMMON . "\n" . self::COMMIT;
    }
}
