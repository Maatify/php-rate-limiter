<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Redis;

use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use RuntimeException;

/**
 * RESP test client for the isolated Redis integration suites.
 *
 * It also carries the EVALSHA study path (#210, `investigations/evalsha-210/`): with the script mode `evalsha*` (constructor
 * argument, or the environment variable `REDIS_INTEGRATION_SCRIPT_MODE`; the default is `eval`, which changes nothing), an `EVAL`
 * command is sent as `EVALSHA <sha1 of the exact script text>` and falls back to `EVAL` only after the server replied with an
 * identified `NOSCRIPT` error, which proves the script did not run. A transport failure or a lost reply is never replayed. The
 * translation happens at the wire, below the store, so every script the store sends (store scripts, request overlay, commit,
 * verify, admission scripts routed through the overlay) has already been intercepted and wrapped when it is hashed.
 *
 * The wire counters below count the bytes this client writes and reads, per executor instance.
 */
final class RespRedisCommandExecutor implements RedisCommandExecutorInterface
{
    public const MODE_EVAL = 'eval';

    public const MODE_EVALSHA = 'evalsha';

    /** SCRIPT FLUSH before every script call: every EVALSHA misses, the fallback follows. */
    public const MODE_EVALSHA_FLUSH_EACH = 'evalsha-flush-each';

    /** SCRIPT FLUSH only before the commit script of a request (a cache loss between the evaluation and the save). */
    public const MODE_EVALSHA_FLUSH_COMMIT = 'evalsha-flush-commit';

    public int $bytesSent = 0;

    public int $bytesReceived = 0;

    /** Redis commands written, including a `SCRIPT FLUSH` of a flush mode and a fallback `EVAL`. */
    public int $commands = 0;

    /** Script calls the caller asked for (`EVAL` commands handed to `execute()`), whatever was written. */
    public int $scriptCalls = 0;

    /** `EVALSHA` commands written. */
    public int $evalshaCalls = 0;

    /** `EVALSHA` answered by `NOSCRIPT` (an identified cache miss). */
    public int $noscriptMisses = 0;

    /** `EVAL` commands written after a `NOSCRIPT` reply. */
    public int $fallbackEvals = 0;

    /** `EVAL` commands written because the mode is `eval`. */
    public int $plainEvals = 0;

    /** @var resource */
    private $socket;

    private string $scriptMode;

    /** @var array<string, string> script text => sha1 */
    private array $hashes = [];

    public function __construct(string $host, int $port, ?string $scriptMode = null)
    {
        $mode = $scriptMode ?? getenv('REDIS_INTEGRATION_SCRIPT_MODE');
        $this->scriptMode = is_string($mode) && $mode !== '' ? $mode : self::MODE_EVAL;
        if (! in_array($this->scriptMode, [self::MODE_EVAL, self::MODE_EVALSHA, self::MODE_EVALSHA_FLUSH_EACH, self::MODE_EVALSHA_FLUSH_COMMIT], true)) {
            throw new RuntimeException('Unknown script mode ' . $this->scriptMode . '.');
        }
        $errorCode = null;
        $errorMessage = null;
        $socket = stream_socket_client("tcp://{$host}:{$port}", $errorCode, $errorMessage, 5);
        if (! is_resource($socket)) {
            $code = is_int($errorCode) ? $errorCode : 0;
            $message = is_string($errorMessage) ? $errorMessage : 'unknown error';
            throw new BackendFailureException('Redis connection failed (' . $code . '): ' . $message);
        }
        stream_set_timeout($socket, 5);
        $this->socket = $socket;
    }

    public function scriptMode(): string
    {
        return $this->scriptMode;
    }

    public function resetCounters(): void
    {
        $this->bytesSent = $this->bytesReceived = $this->commands = $this->scriptCalls = 0;
        $this->evalshaCalls = $this->noscriptMisses = $this->fallbackEvals = $this->plainEvals = 0;
    }

    /** @param non-empty-list<int|string|float> $command */
    public function execute(array $command): mixed
    {
        if ($command[0] !== 'EVAL' || ! is_string($command[1] ?? null)) {
            return $this->send($command);
        }
        $this->scriptCalls++;
        if ($this->scriptMode === self::MODE_EVAL) {
            $this->plainEvals++;

            return $this->send($command);
        }
        if ($this->scriptMode === self::MODE_EVALSHA_FLUSH_EACH
            || ($this->scriptMode === self::MODE_EVALSHA_FLUSH_COMMIT && OverlayWire::isCommit($command))) {
            $this->send(['SCRIPT', 'FLUSH']);
        }
        $script = $command[1];
        $hash = $this->hashes[$script] ??= sha1($script);
        $this->evalshaCalls++;
        try {
            return $this->send(['EVALSHA', $hash, ...array_slice($command, 2)]);
        } catch (RateLimiterException $error) {
            // An error reply is a reply: Redis answered and the script did not run. Only a NOSCRIPT reply is a cache miss.
            if (! str_starts_with($error->getMessage(), 'NOSCRIPT')) {
                throw $error;
            }
            $this->noscriptMisses++;
            $this->fallbackEvals++;

            return $this->send($command);
        }
    }

    /** @param non-empty-list<int|string|float> $command */
    private function send(array $command): mixed
    {
        $payload = '*' . count($command) . "\r\n";
        foreach ($command as $argument) {
            $value = (string) $argument;
            $payload .= '$' . strlen($value) . "\r\n" . $value . "\r\n";
        }
        if (fwrite($this->socket, $payload) !== strlen($payload)) {
            throw new BackendFailureException('Redis command write failed.');
        }
        $this->commands++;
        $this->bytesSent += strlen($payload);

        return $this->readReply();
    }

    private function readReply(): mixed
    {
        $prefix = fread($this->socket, 1);
        if ($prefix === false || $prefix === '') {
            throw new BackendFailureException('Redis reply ended unexpectedly.');
        }
        $this->bytesReceived++;

        return match ($prefix) {
            '+' => $this->readLine(),
            '-' => throw new RateLimiterException((string) $this->readLine()),
            ':' => $this->readIntegerReply(),
            '$' => $this->readBulk(),
            '*' => $this->readArray(),
            default => throw new RuntimeException('Unsupported Redis RESP reply.'),
        };
    }

    private function readLine(): string
    {
        $line = fgets($this->socket);
        if ($line === false) {
            throw new BackendFailureException('Redis reply read failed.');
        }
        if (! str_ends_with($line, "\r\n")) {
            if ($this->transportReadEnded()) {
                throw new BackendFailureException('Redis reply ended unexpectedly.');
            }
            throw new RuntimeException('Malformed Redis RESP line.');
        }
        $this->bytesReceived += strlen($line);

        return substr($line, 0, -2);
    }

    private function readBulk(): ?string
    {
        $length = $this->readIntegerLine();
        if ($length === -1) {
            return null;
        }
        if ($length < -1) {
            throw new RuntimeException('Malformed Redis bulk length.');
        }
        $value = '';
        $remaining = $length + 2;
        while ($remaining > 0) {
            $chunk = fread($this->socket, $remaining);
            if ($chunk === false || $chunk === '') {
                throw new BackendFailureException('Redis bulk reply ended unexpectedly.');
            }
            $value .= $chunk;
            $remaining -= strlen($chunk);
            $this->bytesReceived += strlen($chunk);
        }
        if (! str_ends_with($value, "\r\n")) {
            throw new RuntimeException('Malformed Redis bulk terminator.');
        }
        return substr($value, 0, -2);
    }

    /** @return list<mixed>|null */
    private function readArray(): ?array
    {
        $length = $this->readIntegerLine();
        if ($length === -1) {
            return null;
        }
        if ($length < -1) {
            throw new RuntimeException('Malformed Redis array length.');
        }
        $result = [];
        for ($index = 0; $index < $length; $index++) {
            $result[] = $this->readReply();
        }
        return $result;
    }

    /**
     * A RESP integer reply, signed, exactly as Redis sent it: a native `int` when it fits `PHP_INT_SIZE`, otherwise its canonical
     * decimal string. Redis integers are 64-bit, so on a 32-bit PHP a reply such as `PEXPIRETIME` (about 1.8 × 10^12) must not
     * be saturated, wrapped or turned into a float: a string keeps every digit, and a one-millisecond difference stays visible (#219).
     *
     * @return int|string
     */
    private function readIntegerReply(): int|string
    {
        $line = $this->readLine();
        if (preg_match('/^-?(0|[1-9][0-9]*)$/D', $line) !== 1) {
            throw new RuntimeException('Malformed Redis RESP integer.');
        }
        $value = filter_var($line, FILTER_VALIDATE_INT);

        return $value === false ? $line : $value;
    }

    /** A RESP length line (bulk or array): always a native integer, never an exact-width reply. */
    private function readIntegerLine(): int
    {
        $line = $this->readLine();
        if (preg_match('/^-?(0|[1-9][0-9]*)$/D', $line) !== 1) {
            throw new RuntimeException('Malformed Redis RESP integer.');
        }
        $value = filter_var($line, FILTER_VALIDATE_INT);
        if ($value === false) {
            throw new RuntimeException('Redis RESP integer is out of range.');
        }
        return $value;
    }

    private function transportReadEnded(): bool
    {
        $metadata = stream_get_meta_data($this->socket);
        return $metadata['timed_out'] || $metadata['eof'];
    }
}
