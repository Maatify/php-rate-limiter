<?php
// PROVENANCE: copy of investigations/flaky-157/harness/lib.php at 8b79e9b, sha256 058d1e28ec4db421ec1de44283182ec2f3c2aef4faa4df0e98065e48b5014628; delete this line to get the original byte for byte.

declare(strict_types=1);

// #157 phase 1 harness library (PLAN.md §5, §9). No dependency on the package:
// the tap and the checkers must not share code with what they observe.

namespace Flaky157;

const TARGET_CLASS = 'Maatify\\RateLimiter\\Tests\\Integration\\Redis\\RedisFullCapabilityStoreIntegrationTest';
const TARGET_METHOD = 'testMalformedStoredGenerationFailsAcrossReadMutateClaimAndPublication';
const TARGET_FILE = 'tests/Integration/Redis/RedisFullCapabilityStoreIntegrationTest.php';
const NAMESPACE_USED = 'integration:test';
const CASES = ['0', '-1', 'not-a-number'];
const EXPECTED_SEQUENCE = ['HSET', 'EXPIRE', 'READ', 'MUTATE', 'CLAIM', 'PUBLICATION'];
const KEYSPACE_COMMANDS = ['FLUSHDB', 'FLUSHALL', 'SWAPDB', 'SELECT', 'DEBUG', 'CONFIG', 'SHUTDOWN', 'SCRIPT'];
const FAILURE_MESSAGE = 'Malformed persisted Redis state was accepted.';

final class Incomplete
{
}

/**
 * Parses one RESP2/RESP3 reply from $buffer at $offset.
 *
 * @return array{0: mixed, 1: int}|Incomplete value and next offset, or Incomplete
 */
function parseReply(string $buffer, int $offset): array|Incomplete
{
    $lineEnd = strpos($buffer, "\r\n", $offset);
    if ($lineEnd === false) {
        return new Incomplete();
    }
    $type = $buffer[$offset];
    $line = substr($buffer, $offset + 1, $lineEnd - $offset - 1);
    $next = $lineEnd + 2;
    switch ($type) {
        case '+':
        case '(':
        case ',':
            return [$line, $next];
        case '-':
        case '!':
            return [['error' => $line], $next];
        case ':':
            return [(int) $line, $next];
        case '_':
            return [null, $next];
        case '#':
            return [$line === 't', $next];
        case '$':
        case '=':
        case '|':
            $length = (int) $line;
            if ($length < 0) {
                return [null, $next];
            }
            if (strlen($buffer) < $next + $length + 2) {
                return new Incomplete();
            }
            return [substr($buffer, $next, $length), $next + $length + 2];
        case '*':
        case '~':
        case '>':
        case '%':
            $count = (int) $line;
            if ($count < 0) {
                return [null, $next];
            }
            if ($type === '%') {
                $count *= 2;
            }
            $items = [];
            for ($i = 0; $i < $count; $i++) {
                $item = parseReply($buffer, $next);
                if ($item instanceof Incomplete) {
                    return $item;
                }
                [$items[], $next] = $item;
            }
            return [$items, $next];
        default:
            throw new \UnexpectedValueException('Unparseable RESP reply type 0x' . bin2hex($type));
    }
}

/**
 * Parses one client command (RESP array of bulk strings).
 *
 * @return array{0: list<string>, 1: int}|Incomplete
 */
function parseCommand(string $buffer, int $offset): array|Incomplete
{
    if ($buffer[$offset] !== '*') {
        throw new \UnexpectedValueException('Unparseable RESP command (inline or unknown framing).');
    }
    $parsed = parseReply($buffer, $offset);
    if ($parsed instanceof Incomplete) {
        return $parsed;
    }
    [$argv, $next] = $parsed;
    if (! is_array($argv) || $argv === []) {
        throw new \UnexpectedValueException('Empty or malformed RESP command.');
    }
    foreach ($argv as $argument) {
        if (! is_string($argument)) {
            throw new \UnexpectedValueException('RESP command argument is not a bulk string.');
        }
    }
    /** @var list<string> $argv */
    return [$argv, $next];
}

function encodeCommand(array $argv): string
{
    $payload = '*' . count($argv) . "\r\n";
    foreach ($argv as $argument) {
        $value = (string) $argument;
        $payload .= '$' . strlen($value) . "\r\n" . $value . "\r\n";
    }
    return $payload;
}

/** Minimal blocking client for the tap's side connection, probes and config reads. */
final class Client
{
    /** @var resource */
    private $socket;

    private string $buffer = '';

    public function __construct(string $host, int $port)
    {
        $socket = stream_socket_client("tcp://{$host}:{$port}", $code, $message, 5);
        if (! is_resource($socket)) {
            throw new \RuntimeException("Side connection failed: {$message}");
        }
        stream_set_timeout($socket, 5);
        $this->socket = $socket;
    }

    public function call(string ...$argv): mixed
    {
        $payload = encodeCommand($argv);
        if (fwrite($this->socket, $payload) !== strlen($payload)) {
            throw new \RuntimeException('Side connection write failed.');
        }
        while (true) {
            if ($this->buffer !== '') {
                $parsed = parseReply($this->buffer, 0);
                if (! $parsed instanceof Incomplete) {
                    $this->buffer = substr($this->buffer, $parsed[1]);
                    return $parsed[0];
                }
            }
            $chunk = fread($this->socket, 65536);
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('Side connection read failed.');
            }
            $this->buffer .= $chunk;
        }
    }

    /** @return array<string, string> */
    public function info(string $section): array
    {
        $fields = [];
        foreach (explode("\n", (string) $this->call('INFO', $section)) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#' && str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $fields[$name] = $value;
            }
        }
        return $fields;
    }
}

/** The store's key for the R1 logical key, computed by reflection on the tested head's class. */
function r1Keys(): array
{
    $class = 'Maatify\\RateLimiter\\Repository\\Redis\\RedisFullCapabilityStore';
    $executor = new class implements \Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface {
        public function execute(array $command): mixed
        {
            throw new \LogicException('The key helper store must not reach Redis.');
        }
    };
    $store = new $class($executor, NAMESPACE_USED);
    $key = new \ReflectionMethod($class, 'key');
    $keys = [];
    foreach (array_keys(CASES) as $index) {
        $keys[$index] = $key->invoke($store, 'score', 'r1-malformed-generation-' . $index);
    }
    return $keys;
}

/** SHA-1 of each lifecycle script body, read by reflection (LT-157-04). */
function scriptShas(): array
{
    $class = 'Maatify\\RateLimiter\\Repository\\Redis\\RedisFullCapabilityStore';
    $shas = [];
    foreach (['READ' => 'LIFECYCLE_READ', 'MUTATE' => 'LIFECYCLE_MUTATE', 'CLAIM' => 'LIFECYCLE_CLAIM', 'PUBLICATION' => 'HARD_BLOCK'] as $kind => $constant) {
        $value = (new \ReflectionClassConstant($class, $constant))->getValue();
        if (! is_string($value)) {
            throw new \RuntimeException("{$constant} is not a string.");
        }
        $shas[sha1($value)] = $kind;
    }
    return $shas;
}

/** Wilson score interval, 95 %. @return array{0: float, 1: float} */
function wilson(int $successes, int $trials, float $z = 1.959963984540054): array
{
    if ($trials === 0) {
        return [0.0, 1.0];
    }
    $p = $successes / $trials;
    $denominator = 1 + $z * $z / $trials;
    $centre = ($p + $z * $z / (2 * $trials)) / $denominator;
    $half = $z * sqrt($p * (1 - $p) / $trials + $z * $z / (4 * $trials * $trials)) / $denominator;
    return [max(0.0, $centre - $half), min(1.0, $centre + $half)];
}

/**
 * Testcases of a junit file in document order.
 *
 * @return list<array{class: string, name: string, file: string, failure: ?string}>
 */
function junitCases(string $path): array
{
    $xml = simplexml_load_file($path);
    if ($xml === false) {
        throw new \RuntimeException("Unreadable junit: {$path}");
    }
    $cases = [];
    foreach ($xml->xpath('//testcase') ?: [] as $case) {
        $failure = null;
        foreach (['failure', 'error'] as $kind) {
            if (isset($case->{$kind})) {
                $failure = $kind . ': ' . (string) $case->{$kind};
            }
        }
        $cases[] = [
            'class' => (string) $case['class'],
            'name' => (string) $case['name'],
            'file' => (string) $case['file'],
            'failure' => $failure,
        ];
    }
    return $cases;
}

/** 1-based ordinal of the target test, or null. */
function targetOrdinal(array $cases): ?int
{
    foreach ($cases as $index => $case) {
        if ($case['class'] === TARGET_CLASS && $case['name'] === TARGET_METHOD) {
            return $index + 1;
        }
    }
    return null;
}

function readJsonl(string $path): array
{
    $rows = [];
    if (! is_file($path)) {
        return $rows;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $rows[] = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
    }
    return $rows;
}

function writeJsonl($handle, array $row): void
{
    fwrite($handle, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
