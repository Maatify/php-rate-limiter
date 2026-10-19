<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Repository\Redis;

use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationResultDTO;
use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionPermitClaimEnum;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Exception\RateLimiterException;

/**
 * Redis implementation of the admission store operations (DEC-024, contract §2.4), used by `RedisFullCapabilityStore`.
 *
 * One Lua script per operation. Every script takes the caller's `now` and relative TTLs and never calls `TIME` (DEC-016).
 * Every script reads and validates the type and the fields of every key it is going to touch before its first write, because
 * Redis does not roll back the writes of a script that fails half way; a malformed value is a Lua error reply and surfaces
 * as `RateLimiterException`. A refusal writes nothing, never prunes, and a previous generation is never written. Every key
 * carries the namespace hash tag, so all admission keys of a namespace (hence of every verifier class) share one hash slot
 * on the single, non-clustered Redis the store supports.
 *
 * Failure classification stays with the Host executor: `BackendFailureException` and every other throwable propagate
 * unchanged; nothing is wrapped and there is no fallback.
 *
 * @internal
 */
final class RedisAdmissionOperations
{
    private const PREFIX = 'maatify:rate-limiter:v1';

    private const EXACT_MAX = 9007199254740991;

    /** A relative TTL in seconds that Redis can represent when added to a millisecond clock. */
    private const TTL_MAX = 9007199254740;

    private const PERMIT_EXISTS = 'permit_exists';

    private const KIND_CODE = [
        'counter' => 'c',
        'device_member' => 'd',
        'set_size' => 's',
        'bucket' => 'b',
    ];

    private const LUA_COMMON = <<<'LUA'
local EXACT = 9007199254740991
local function canonical(v)
  if v == nil or v == false or #v > 16 then return nil end
  if v == '0' or string.match(v, '^[1-9]%d*$') ~= nil then
    local x = tonumber(v)
    if x <= EXACT then return x end
  end
  return nil
end
local function keyType(k) return redis.call('TYPE', k)['ok'] end
local function hasExpiry(k) return redis.call('TTL', k) >= 0 end
local function bad(what) return redis.error_reply('malformed admission state: ' .. what) end
-- Absent => {absent = true}; a valid record => its fields; anything else => nil, reason.
local function readPermit(k)
  local t = keyType(k)
  if t == 'none' then return {absent = true} end
  if t ~= 'hash' then return nil, 'permit record has type ' .. t end
  if not hasExpiry(k) then return nil, 'permit record has no expiry' end
  local f = redis.call('HMGET', k, 'state', 'lane', 'subject', 'operation', 'deadline', 'grace', 'outcome')
  local deadline, grace = canonical(f[5]), canonical(f[6])
  if (f[1] ~= 'ISSUED' and f[1] ~= 'CLAIMED') or not f[2] or not f[3] or not f[4] or deadline == nil or grace == nil then
    return nil, 'permit record fields are malformed'
  end
  if f[1] == 'CLAIMED' and f[7] ~= 'failure' and f[7] ~= 'success' then return nil, 'permit record outcome is malformed' end
  return {state = f[1], lane = f[2], subject = f[3], operation = f[4], deadline = deadline, grace = grace, outcome = f[7]}
end
-- Counter: absent => 0; a valid counter => its value; anything else => nil, reason.
local function readCounter(k)
  if k == '' then return 0 end
  local t = keyType(k)
  if t == 'none' then return 0 end
  if t ~= 'string' then return nil, 'counter key has type ' .. t end
  if not hasExpiry(k) then return nil, 'counter key has no expiry' end
  local v = canonical(redis.call('GET', k))
  if v == nil then return nil, 'counter value is not a canonical non-negative integer' end
  return v
end
local function checkType(k, expected, label)
  if k == '' then return nil end
  local t = keyType(k)
  if t ~= 'none' and t ~= expected then return label .. ' key has type ' .. t end
  if t == expected and not hasExpiry(k) then return label .. ' key has no expiry' end
  return nil
end
-- Settled size of a failure set: its members plus the pending members that are due (score <= now) and not yet in it.
local function settledSize(setKey, pendingKey, now)
  local size = redis.call('SCARD', setKey)
  for _, m in ipairs(redis.call('ZRANGEBYSCORE', pendingKey, '-inf', now)) do
    if redis.call('SISMEMBER', setKey, m) == 0 then size = size + 1 end
  end
  return size
end
-- Bucket: absent => full; a valid bucket => its refilled credit; anything else => nil, reason.
local function readBucket(k, capacity, now)
  local t = keyType(k)
  if t == 'none' then return capacity, false end
  if t ~= 'hash' then return nil, 'bucket key has type ' .. t end
  if not hasExpiry(k) then return nil, 'bucket key has no expiry' end
  local f = redis.call('HMGET', k, 'credit', 'last')
  local credit, last = canonical(f[1]), canonical(f[2])
  if credit == nil or last == nil then return nil, 'bucket fields are malformed' end
  local elapsed = now - last
  if elapsed < 0 then elapsed = 0 end
  local refilled = credit + elapsed
  if refilled > capacity then refilled = capacity end
  return refilled, true
end
LUA;

    private const RESERVE = self::LUA_COMMON . "\n" . <<<'LUA'
local nowStr = ARGV[1]
local now = tonumber(nowStr)
local n = tonumber(ARGV[2])
local hasPermit = ARGV[3] == '1'
local sc = {}
-- Phase 1: read and validate every key of every scope and of the permit. Nothing is written.
for i = 1, n do
  local base = 9 + (i - 1) * 5
  local kind = ARGV[base]
  local cap = tonumber(ARGV[base + 1])
  local ttl = tonumber(ARGV[base + 2])
  local cur, prev = KEYS[2 * i - 1], KEYS[2 * i]
  local s = {kind = kind, cap = cap, ttl = ttl, cur = cur, prev = prev, member = ARGV[base + 3], prevMember = ARGV[base + 4]}
  if kind == 'c' then
    local a, ea = readCounter(cur)
    if a == nil then return bad(ea) end
    local b, eb = readCounter(prev)
    if b == nil then return bad(eb) end
    s.a, s.b, s.fresh = a, b, redis.call('EXISTS', cur) == 0
  elseif kind == 'd' then
    local e1 = checkType(cur, 'zset', 'device set')
    if e1 then return bad(e1) end
    local e2 = checkType(prev, 'zset', 'previous device set')
    if e2 then return bad(e2) end
    local exists = false
    local score = redis.call('ZSCORE', cur, s.member)
    if score and tonumber(score) > now then exists = true end
    if not exists and prev ~= '' and s.prevMember ~= '' then
      local pscore = redis.call('ZSCORE', prev, s.prevMember)
      if pscore and tonumber(pscore) > now then exists = true end
    end
    local live = redis.call('ZCOUNT', cur, '(' .. nowStr, '+inf')
    if prev ~= '' then live = live + redis.call('ZCOUNT', prev, '(' .. nowStr, '+inf') end
    s.exists, s.live = exists, live
  elseif kind == 's' then
    -- A set scope carries its pending key in the slot a previous generation would use.
    local e = checkType(cur, 'set', 'set')
    if e then return bad(e) end
    local ep = checkType(prev, 'zset', 'pending set')
    if ep then return bad(ep) end
    s.pending = prev
    s.deadline = hasPermit and tonumber(ARGV[7]) or 0
    s.inSet = redis.call('SISMEMBER', cur, s.member) == 1
    local score = redis.call('ZSCORE', prev, s.member)
    s.isMember = s.inSet or (score and tonumber(score) <= now)
    s.card = settledSize(cur, prev, now)
    s.freshPending = redis.call('EXISTS', prev) == 0
  elseif kind == 'b' then
    s.full = cap * ttl
    local credit, existsOrErr = readBucket(cur, s.full, now)
    if credit == nil then return bad(existsOrErr) end
    s.credit, s.present = credit, existsOrErr
  else
    return bad('unknown scope kind')
  end
  sc[i] = s
end
local permitKey = KEYS[2 * n + 1]
local permitExists = false
if hasPermit then
  local record, err = readPermit(permitKey)
  if record == nil then return bad(err) end
  permitExists = not record.absent
end
-- Phase 2: gates, in the order of the request. The first refusing scope is named; nothing is written.
for i = 1, n do
  local s = sc[i]
  if s.kind == 'c' then
    if s.a + s.b >= s.cap then return {0, i, 0} end
  elseif s.kind == 'd' then
    if not s.exists and s.live >= s.cap then return {0, i, 0} end
  elseif s.kind == 's' then
    if not s.isMember and s.card >= s.cap then return {0, i, 0} end
  else
    if s.credit < s.ttl then return {0, i, s.ttl - s.credit} end
  end
end
if permitExists then return {0, 0, 0} end
-- Phase 3: writes, only reached when every gate admitted.
for i = 1, n do
  local s = sc[i]
  if s.kind == 'c' then
    redis.call('INCR', s.cur)
    if s.fresh then redis.call('EXPIRE', s.cur, s.ttl) end
  elseif s.kind == 'd' then
    if not s.exists then
      redis.call('ZREMRANGEBYSCORE', s.cur, '-inf', nowStr)
      redis.call('ZADD', s.cur, string.format('%d', now + s.ttl), s.member)
      redis.call('EXPIRE', s.cur, s.ttl)
    end
  elseif s.kind == 's' then
    if hasPermit and not s.inSet then
      redis.call('ZADD', s.pending, 'LT', string.format('%d', s.deadline), s.member)
      if s.freshPending then redis.call('EXPIRE', s.pending, s.ttl) end
    end
  elseif s.kind == 'b' then
    local remaining = s.credit - s.ttl
    redis.call('HSET', s.cur, 'credit', string.format('%d', remaining), 'last', nowStr)
    local untilFull = s.full - remaining
    if untilFull < 1 then untilFull = 1 end
    redis.call('EXPIRE', s.cur, string.format('%d', untilFull))
  end
end
if hasPermit then
  redis.call('HSET', permitKey, 'state', 'ISSUED', 'lane', ARGV[4], 'subject', ARGV[5], 'operation', ARGV[6], 'deadline', ARGV[7], 'grace', ARGV[8])
  local life = tonumber(ARGV[7]) + tonumber(ARGV[8]) - now
  if life < 1 then life = 1 end
  redis.call('EXPIRE', permitKey, string.format('%d', life))
end
return {1, 0, 0}
LUA;

    private const OUTCOME = self::LUA_COMMON . "\n" . <<<'LUA'
local nowStr = ARGV[1]
local now = tonumber(nowStr)
local n = tonumber(ARGV[2])
local success = ARGV[3] == 's'
local sc = {}
-- Phase 1: validate every key this outcome will touch. Nothing is written.
for i = 1, n do
  local base = 4 + (i - 1) * 5
  local kind = ARGV[base]
  local cap = tonumber(ARGV[base + 1])
  local ttl = tonumber(ARGV[base + 2])
  local cur = KEYS[2 * i - 1]
  local s = {kind = kind, cap = cap, ttl = ttl, cur = cur, member = ARGV[base + 3], keepsSettled = ARGV[base + 4] == '1'}
  if kind == 's' then
    local e = checkType(cur, 'set', 'set')
    if e then return bad(e) end
    local ep = checkType(KEYS[2 * i], 'zset', 'pending set')
    if ep then return bad(ep) end
    s.pending = KEYS[2 * i]
    local score = redis.call('ZSCORE', s.pending, s.member)
    s.score = score and tonumber(score) or nil
    s.fresh = redis.call('EXISTS', cur) == 0
  elseif kind == 'c' and success then
    local v, e = readCounter(cur)
    if v == nil then return bad(e) end
    s.value = v
  elseif kind == 'b' and success then
    s.full = cap * ttl
    local credit, existsOrErr = readBucket(cur, s.full, now)
    if credit == nil then return bad(existsOrErr) end
    s.credit, s.present = credit, existsOrErr
  end
  sc[i] = s
end
-- Phase 2: apply. A failure settles its member; a success cancels a pending member that is not yet due, removes a settled
-- one unless the scope keeps it, and refunds counters and the bucket. A success never removes a due pending member.
for i = 1, n do
  local s = sc[i]
  if s.kind == 's' then
    if success then
      if not s.keepsSettled then redis.call('SREM', s.cur, s.member) end
      if s.score ~= nil and s.score > now then redis.call('ZREM', s.pending, s.member) end
    else
      redis.call('SADD', s.cur, s.member)
      if s.fresh then redis.call('EXPIRE', s.cur, s.ttl) end
      redis.call('ZREM', s.pending, s.member)
    end
  elseif s.kind == 'c' and success then
    if s.value > 0 then redis.call('DECR', s.cur) end
  elseif s.kind == 'b' and success and s.present then
    local credit = s.credit + s.ttl
    if credit >= s.full then
      redis.call('DEL', s.cur)
    else
      redis.call('HSET', s.cur, 'credit', string.format('%d', credit), 'last', nowStr)
      redis.call('EXPIRE', s.cur, string.format('%d', s.full - credit))
    end
  end
end
return 1
LUA;

    private const CLAIM = self::LUA_COMMON . "\n" . <<<'LUA'
local now = tonumber(ARGV[1])
local record, err = readPermit(KEYS[1])
if record == nil then return bad(err) end
if record.absent then return 'unknown' end
if now >= record.deadline + record.grace then return 'unknown' end
if record.lane ~= ARGV[2] or record.subject ~= ARGV[3] or record.operation ~= ARGV[4] then return 'rejected' end
if record.state == 'CLAIMED' then
  if record.outcome == ARGV[5] then return 'duplicate' end
  return 'conflict'
end
if now >= record.deadline then return 'late' end
redis.call('HSET', KEYS[1], 'state', 'CLAIMED', 'outcome', ARGV[5])
return 'accepted'
LUA;

    private const EPOCH_READ = self::LUA_COMMON . "\n" . <<<'LUA'
local function epoch(k)
  if k == '' then return 0, false end
  local t = keyType(k)
  if t == 'none' then return 0, false end
  if t ~= 'string' then return nil, 'epoch key has type ' .. t end
  local v = canonical(redis.call('GET', k))
  if v == nil then return nil, 'epoch value is not a canonical non-negative integer' end
  return v, true
end
local current, currentPresent = epoch(KEYS[1])
if current == nil then return bad(currentPresent) end
local previous, previousPresent = epoch(KEYS[2])
if previous == nil then return bad(previousPresent) end
if ARGV[1] == 'read' then
  if currentPresent then return current end
  return previous
end
local value = current
if previous > value then value = previous end
value = value + 1
if value > EXACT then return bad('epoch overflow') end
redis.call('SET', KEYS[1], string.format('%d', value))
return value
LUA;

    public function __construct(
        private readonly RedisCommandExecutorInterface $redis,
        private readonly string $namespace,
    ) {}

    public function scopeKey(string $verifierClass, AdmissionScopeKindEnum $kind, string $key): string
    {
        return $this->base() . ':' . self::KIND_CODE[$kind->value] . ':' . hash('sha256', $verifierClass . "\0" . $key);
    }

    /** Pending (not yet settled) members of a failure set: a zset scored by the permit deadline, same logical key as its set. */
    public function pendingKey(string $verifierClass, string $key): string
    {
        return $this->base() . ':p:' . hash('sha256', $verifierClass . "\0" . $key);
    }

    public function permitKey(string $permitId): string
    {
        return $this->base() . ':permit:' . hash('sha256', $permitId);
    }

    public function epochKey(string $key): string
    {
        return $this->base() . ':epoch:' . hash('sha256', $key);
    }

    public function reserve(AdmissionReservationRequestDTO $request, int $now): AdmissionReservationResultDTO
    {
        $this->assertTime($now);
        $this->assertScopes($request->scopes, $now, true);
        $permit = $request->permit;
        if ($permit !== null) {
            if ($permit->deadlineAt > self::EXACT_MAX - $permit->graceSeconds) {
                throw new RateLimiterException('Admission permit deadline and grace are not representable.');
            }
            if ($permit->deadlineAt + $permit->graceSeconds - $now > self::TTL_MAX) {
                throw new RateLimiterException('Admission permit lifetime exceeds the representable expiry.');
            }
        }
        if ($permit !== null) {
            foreach ($request->scopes as $scope) {
                if ($scope->kind === AdmissionScopeKindEnum::SET_SIZE && $permit->deadlineAt - $now > $scope->ttlSeconds) {
                    throw new RateLimiterException('Admission permit deadline lies beyond the lifetime of a failure-set scope.');
                }
            }
        }
        $keys = [];
        $args = [
            (string) $now,
            (string) count($request->scopes),
            $permit === null ? '0' : '1',
            $permit->lane ?? '',
            $permit->subject ?? '',
            $permit->operation ?? '',
            (string) ($permit->deadlineAt ?? 0),
            (string) ($permit->graceSeconds ?? 0),
        ];
        foreach ($request->scopes as $scope) {
            $previous = $this->previousKeyOf($scope);
            $keys[] = $this->scopeKey($request->verifierClass, $scope->kind, $scope->key);
            $keys[] = $scope->kind === AdmissionScopeKindEnum::SET_SIZE
                ? $this->pendingKey($request->verifierClass, $scope->key)
                : ($previous === null ? '' : $this->scopeKey($request->verifierClass, $scope->kind, $previous));
            array_push(
                $args,
                self::KIND_CODE[$scope->kind->value],
                (string) $scope->capacity,
                (string) $scope->ttlSeconds,
                $scope->member ?? '',
                $scope->previousMember ?? '',
            );
        }
        $keys[] = $permit === null ? '' : $this->permitKey($permit->id);

        $tuple = $this->tuple($this->eval(self::RESERVE, $keys, $args), 3, 'admission reservation');
        $verdict = $this->integer($tuple[0], 'admission reservation verdict');
        $index = $this->integer($tuple[1], 'admission reservation scope');
        $retry = $this->integer($tuple[2], 'admission reservation retry-after');
        if ($verdict === 1 && $index === 0 && $retry === 0) {
            return new AdmissionReservationResultDTO(true);
        }
        if ($verdict !== 0 || $retry < 0) {
            throw new RateLimiterException('Malformed admission reservation response.');
        }
        if ($index === 0 && $permit !== null) {
            return new AdmissionReservationResultDTO(false, self::PERMIT_EXISTS);
        }
        if ($index < 1 || $index > count($request->scopes)) {
            throw new RateLimiterException('Malformed admission reservation response.');
        }

        return new AdmissionReservationResultDTO(false, $request->scopes[$index - 1]->name, $retry);
    }

    public function applyOutcome(AdmissionOutcomeDTO $outcome, int $now): void
    {
        $this->assertTime($now);
        $this->assertScopes($outcome->scopes, $now, false);
        if ($outcome->scopes === []) {
            return;
        }
        $keys = [];
        $args = [(string) $now, (string) count($outcome->scopes), $outcome->outcome === AdmissionOutcomeKindEnum::SUCCESS ? 's' : 'f'];
        foreach ($outcome->scopes as $scope) {
            $keys[] = $this->scopeKey($outcome->verifierClass, $scope->kind, $scope->key);
            $keys[] = $scope->kind === AdmissionScopeKindEnum::SET_SIZE ? $this->pendingKey($outcome->verifierClass, $scope->key) : '';
            array_push(
                $args,
                self::KIND_CODE[$scope->kind->value],
                (string) $scope->capacity,
                (string) $scope->ttlSeconds,
                $scope->member ?? '',
                $scope->successKeepsSettled ? '1' : '0',
            );
        }
        if ($this->integer($this->eval(self::OUTCOME, $keys, $args), 'admission outcome response') !== 1) {
            throw new RateLimiterException('Malformed admission outcome response.');
        }
    }

    public function claimPermit(string $permitId, string $lane, string $subject, string $operation, AdmissionOutcomeKindEnum $outcome, int $now): AdmissionPermitClaimEnum
    {
        $this->assertTime($now);
        foreach (['permit id' => $permitId, 'lane' => $lane, 'subject' => $subject, 'operation' => $operation] as $label => $value) {
            if (trim($value) === '') {
                throw new RateLimiterException('Admission permit ' . $label . ' must not be empty.');
            }
        }
        $reply = $this->eval(self::CLAIM, [$this->permitKey($permitId)], [(string) $now, $lane, $subject, $operation, $outcome->value]);
        $claim = is_string($reply) ? AdmissionPermitClaimEnum::tryFrom($reply) : null;
        if ($claim === null) {
            throw new RateLimiterException('Malformed admission permit claim response.');
        }

        return $claim;
    }

    public function readEpoch(string $key, ?string $previousKey): int
    {
        return $this->epoch('read', $key, $previousKey);
    }

    public function bumpEpoch(string $key, ?string $previousKey): int
    {
        return $this->epoch('bump', $key, $previousKey);
    }

    private function epoch(string $mode, string $key, ?string $previousKey): int
    {
        if (trim($key) === '') {
            throw new RateLimiterException('Binding epoch key must not be empty.');
        }
        $previousKey = $previousKey === $key ? null : $previousKey;
        if ($previousKey !== null && trim($previousKey) === '') {
            throw new RateLimiterException('Binding epoch previous key must not be blank.');
        }
        $value = $this->integer(
            $this->eval(self::EPOCH_READ, [$this->epochKey($key), $previousKey === null ? '' : $this->epochKey($previousKey)], [$mode]),
            'binding epoch response',
        );
        if ($value < 0) {
            throw new RateLimiterException('Malformed binding epoch response.');
        }

        return $value;
    }

    private function base(): string
    {
        return self::PREFIX . ':{' . hash('sha256', $this->namespace) . '}:adm';
    }

    private function previousKeyOf(AdmissionScopeDTO $scope): ?string
    {
        return $scope->previousKey === $scope->key ? null : $scope->previousKey;
    }

    private function assertTime(int $now): void
    {
        if ($now < 0 || $now > self::EXACT_MAX) {
            throw new RateLimiterException('Admission caller time must be a non-negative representable timestamp.');
        }
    }

    /**
     * Rejects, before any command is sent, every request the scripts could not serve exactly: a scope whose fields do not
     * fit its kind, a duplicated key, or numbers that Lua doubles or Redis expiries cannot represent.
     *
     * @param list<AdmissionScopeDTO> $scopes
     */
    private function assertScopes(array $scopes, int $now, bool $reserve): void
    {
        $seen = [];
        foreach ($scopes as $scope) {
            if ($reserve && $scope->name === self::PERMIT_EXISTS) {
                throw new RateLimiterException('Admission scope name is reserved.');
            }
            $previous = $this->previousKeyOf($scope);
            $device = $scope->kind === AdmissionScopeKindEnum::DEVICE_MEMBER;
            $keyed = $device || $scope->kind === AdmissionScopeKindEnum::SET_SIZE;
            if ($previous !== null && ! ($device || $scope->kind === AdmissionScopeKindEnum::COUNTER)) {
                throw new RateLimiterException('Only counter and device-member admission scopes can name a previous generation.');
            }
            if ($previous !== null && trim($previous) === '') {
                throw new RateLimiterException('Admission scope previous key must not be blank.');
            }
            if ((! $keyed && $scope->member !== null) || (! $device && $scope->previousMember !== null)) {
                throw new RateLimiterException('Admission scope carries a member its kind does not use.');
            }
            if ($scope->previousMember !== null && trim($scope->previousMember) === '') {
                throw new RateLimiterException('Admission scope previous member must not be blank.');
            }
            if ($scope->ttlSeconds > self::TTL_MAX || $scope->capacity > self::EXACT_MAX || $now > self::EXACT_MAX - $scope->ttlSeconds) {
                throw new RateLimiterException('Admission scope capacity or lifetime is not representable.');
            }
            if ($scope->kind === AdmissionScopeKindEnum::BUCKET && $scope->capacity > intdiv(self::EXACT_MAX, $scope->ttlSeconds)) {
                throw new RateLimiterException('Admission bucket capacity times its take cost is not representable.');
            }
            $identity = $scope->kind->value . "\0" . $scope->key;
            if (isset($seen[$identity])) {
                throw new RateLimiterException('An admission request names the same scope key twice.');
            }
            $seen[$identity] = true;
        }
    }

    /**
     * @param list<string> $keys
     * @param list<string> $args
     */
    private function eval(string $script, array $keys, array $args): mixed
    {
        /** @var non-empty-list<int|string|float> $command */
        $command = array_merge(['EVAL', $script, count($keys)], $keys, $args);
        try {
            return $this->redis->execute($command);
        } catch (BackendFailureException $exception) {
            throw $exception;
        }
    }

    /** @return list<int|string|float> */
    private function tuple(mixed $value, int $size, string $label): array
    {
        if (! is_array($value) || count($value) !== $size) {
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

    private function integer(mixed $value, string $label): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A-?(0|[1-9]\d{0,17})\z/D', $value) === 1) {
            return (int) $value;
        }
        throw new RateLimiterException('Malformed ' . $label . '.');
    }
}
