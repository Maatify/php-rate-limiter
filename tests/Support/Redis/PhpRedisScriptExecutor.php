<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Redis;

use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;

/**
 * EXPERIMENTAL Host adapter of the EVALSHA study (#210, `investigations/evalsha-210/noscript/`). It is test support, not part of the
 * package: nothing in `src/` refers to it and the package default stays `EVAL`.
 *
 * It owns an ext-redis connection and implements `RedisCommandExecutorInterface` the way a Host would, and it keeps the study's
 * question inside the Host: with `$evalsha`, an `EVAL` command is sent as `EVALSHA <sha1 of the exact text>` and falls back to `EVAL`
 * ONCE, only after the server's error reply carries the whole error code `NOSCRIPT`. The caller (the store) sees the same
 * `execute()` as before; the executor contract is not extended.
 *
 * What the adapter has to do for ext-redis 6.3.0 `rawCommand()` (shapes recorded by `NativeNoscriptStudyRedisTest`):
 *
 * - an error reply with a KNOWN code (`ERR`, `WRONGTYPE`, `NOSCRIPT`, ...) is not an exception: the call returns `false` and the
 *   text is in `getLastError()`; a nil reply also returns `false`, with no error text. `false` alone is therefore ambiguous;
 * - `getLastError()` is sticky: it keeps the text of an earlier failed command until `clearLastError()`. A nil reply after a failed
 *   command would look like an error again. The adapter clears it before every command (`$clearsLastError`; `false` is the NEGATIVE
 *   CONTROL of the study and is not a supported shape);
 * - an error reply with an UNKNOWN code is a `RedisException` (the text of the server), and so is a lost connection: the adapter
 *   does not translate either (DEC-018: server error replies and unknown failures propagate unchanged, no blanket wrapping).
 *
 * `false` with a non-null text, after a cleared last error, is an error reply and is rethrown as `\RedisException($text)` unless it is a
 * cache miss (the error code, the first token of the text, is compared whole: `NOSCRIPTISH` is not a miss). `$confirmsMiss` is a second variant the study evaluates and rejects: after a `NOSCRIPT` reply it asks `SCRIPT EXISTS`
 * and falls back only when the script is really absent.
 */
final class PhpRedisScriptExecutor implements RedisCommandExecutorInterface
{
    public int $commands = 0;

    public int $scriptCalls = 0;

    public int $evalshaCalls = 0;

    public int $noscriptMisses = 0;

    public int $fallbackEvals = 0;

    public int $existsChecks = 0;

    /** @var list<string> The first characters of every script that missed, in order (what the study reports). */
    public array $missedScripts = [];

    /** @var (callable(): void)|null Test hook: runs between a NOSCRIPT reply and the `SCRIPT EXISTS` of the confirming variant. */
    public $betweenMissAndConfirmation = null;

    /** @var array<string, string> */
    private array $hashes = [];

    public function __construct(
        private readonly \Redis $redis,
        private readonly bool $evalsha,
        private readonly bool $clearsLastError = true,
        private readonly bool $confirmsMiss = false,
    ) {}

    /** @param non-empty-list<int|string|float> $command */
    public function execute(array $command): mixed
    {
        if (! $this->evalsha || $command[0] !== 'EVAL' || ! is_string($command[1] ?? null)) {
            return $this->unwrap($this->call($command));
        }
        $this->scriptCalls++;
        $script = $command[1];
        $hash = $this->hashes[$script] ??= sha1($script);
        $this->evalshaCalls++;
        [$reply, $text] = $this->call(['EVALSHA', $hash, ...array_slice($command, 2)]);
        if ($text === null || explode(' ', $text, 2)[0] !== 'NOSCRIPT') {
            return $this->unwrap([$reply, $text]);
        }
        // An identified cache miss: Redis answered before running anything.
        $this->noscriptMisses++;
        $this->missedScripts[] = substr(str_replace(["\n", "\r"], ' ', $script), 0, 70) . ' [' . (OverlayWire::isCommit($command) ? 'commit' : (str_contains($script, 'request commit') ? 'verify' : 'script')) . ']';
        if ($this->confirmsMiss) {
            if ($this->betweenMissAndConfirmation !== null) {
                ($this->betweenMissAndConfirmation)();
            }
            $this->existsChecks++;
            if ($this->unwrap($this->call(['SCRIPT', 'EXISTS', $hash])) !== [0]) {
                throw new \RedisException($text);
            }
        }
        $this->fallbackEvals++;

        return $this->unwrap($this->call($command));
    }

    /**
     * @param array{mixed, string|null} $result
     * @return mixed the reply, or the server's error text raised as the `\RedisException` ext-redis itself raises for an unknown code
     */
    private function unwrap(array $result): mixed
    {
        if ($result[1] !== null) {
            throw new \RedisException($result[1]);
        }

        return $result[0];
    }

    /**
     * One command: the reply and the server's error text, if `false` came with one. A lost connection or an unknown error code is the
     * `\RedisException` of ext-redis and leaves this method unchanged.
     *
     * @param non-empty-list<int|string|float> $command
     * @return array{mixed, string|null}
     */
    private function call(array $command): array
    {
        if ($this->clearsLastError) {
            $this->redis->clearLastError();
        }
        $this->commands++;
        $reply = $this->redis->rawCommand(...array_map(static fn(int|string|float $argument): string => (string) $argument, $command));

        return [$reply, $reply === false ? $this->redis->getLastError() : null];
    }
}
