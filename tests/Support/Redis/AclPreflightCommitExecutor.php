<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Redis;

use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;

/**
 * STUDY VARIANT of #261 (`investigations/redis-acl-request-save-261/`); nothing in `src/` refers to it and the package is unchanged.
 *
 * It rewrites the request commit script on its way to Redis, adding a preflight before the first write: inside the same script, so that
 * no ACL change can fall between the check and the writes, it asks Redis (`redis.acl_check_cmd`, Redis 7.0) whether the account may run every
 * write command the plan will issue on its key, and refuses the whole save with a coded error reply (`NOPERM ...`, so that a Host that
 * classifies by error code keeps it a server reply) when it may not. It checks only what Redis can answer for the ACL; it says nothing about
 * other Redis errors (memory limit, read-only replica, client-output limits), and it is not a rollback.
 */
final class AclPreflightCommitExecutor implements RedisCommandExecutorInterface
{
    private const MARK = 'local function __chunked(command, key, flat, step)';

    private const PREFLIGHT = <<<'LUA'
for _, entry in ipairs(__entries) do
  local key, e = entry[1], entry[2]
  if e.d == 1 then
    local checks = {{'DEL', key}}
    if e.t == 's' then checks[#checks + 1] = {'SET', key, 'v'}
    elseif e.t == 'h' then checks[#checks + 1] = {'HSET', key, 'f', 'v'}
    elseif e.t == 'e' then checks[#checks + 1] = {'SADD', key, 'm'}
    elseif e.t == 'z' then checks[#checks + 1] = {'ZADD', key, '1', 'm'} end
    if e.t ~= 'n' and e.x ~= nil then checks[#checks + 1] = {'PEXPIREAT', key, e.x} end
    for _, check in ipairs(checks) do
      if not redis.acl_check_cmd(unpack(check)) then
        return redis.error_reply('NOPERM request commit: the account may not run ' .. check[1] .. ' on ' .. key)
      end
    end
  end
end

LUA;

    public function __construct(private readonly RedisCommandExecutorInterface $inner) {}

    public function execute(array $command): mixed
    {
        if (OverlayWire::isCommit($command) && is_string($command[1])) {
            if (substr_count($command[1], self::MARK) !== 1) {
                throw new \LogicException('The commit script no longer has the single insertion point of the study variant.');
            }
            $command[1] = str_replace(self::MARK, self::PREFLIGHT . self::MARK, $command[1]);
        }

        return $this->inner->execute($command);
    }
}
