<?php

declare(strict_types=1);

namespace ConsumerVerification;

use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use RuntimeException;

final class RespRedisCommandExecutor implements RedisCommandExecutorInterface
{
    /** @var resource */
    private $socket;

    public function __construct(string $host, int $port)
    {
        $errorCode = null;
        $errorMessage = null;
        $socket = stream_socket_client("tcp://{$host}:{$port}", $errorCode, $errorMessage, 5);
        if (! is_resource($socket)) {
            throw new RuntimeException('Redis connection failed (' . (int) $errorCode . '): ' . (string) $errorMessage);
        }
        stream_set_timeout($socket, 5);
        $this->socket = $socket;
    }

    /** @param non-empty-list<int|string|float> $command */
    public function execute(array $command): mixed
    {
        $payload = '*' . count($command) . "\r\n";
        foreach ($command as $argument) {
            $value = (string) $argument;
            $payload .= '$' . strlen($value) . "\r\n{$value}\r\n";
        }
        $written = fwrite($this->socket, $payload);
        if ($written !== strlen($payload)) {
            throw new RuntimeException('Redis command write failed.');
        }
        return $this->readReply();
    }

    private function readReply(): mixed
    {
        $prefix = fread($this->socket, 1);
        if ($prefix === false || $prefix === '') {
            throw new RuntimeException('Redis reply ended unexpectedly.');
        }
        return match ($prefix) {
            '+' => $this->readLine(),
            '-' => throw new RuntimeException((string) $this->readLine()),
            ':' => (int) $this->readLine(),
            '$' => $this->readBulk(),
            '*' => $this->readArray(),
            default => throw new RuntimeException('Unsupported Redis RESP reply.'),
        };
    }

    private function readLine(): string
    {
        $line = fgets($this->socket);
        if ($line === false || ! str_ends_with($line, "\r\n")) {
            throw new RuntimeException('Malformed Redis RESP line.');
        }
        return substr($line, 0, -2);
    }

    private function readBulk(): ?string
    {
        $length = (int) $this->readLine();
        if ($length === -1) {
            return null;
        }
        if ($length < -1) {
            throw new RuntimeException('Malformed Redis bulk length.');
        }
        $value = '';
        while (strlen($value) < $length + 2) {
            $chunk = fread($this->socket, $length + 2 - strlen($value));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Malformed Redis bulk reply.');
            }
            $value .= $chunk;
        }
        if (! str_ends_with($value, "\r\n")) {
            throw new RuntimeException('Malformed Redis bulk terminator.');
        }
        return substr($value, 0, -2);
    }

    /** @return list<mixed> */
    private function readArray(): array
    {
        $length = (int) $this->readLine();
        if ($length < 0) {
            throw new RuntimeException('Malformed Redis array length.');
        }
        $result = [];
        for ($index = 0; $index < $length; $index++) {
            $result[] = $this->readReply();
        }
        return $result;
    }
}
