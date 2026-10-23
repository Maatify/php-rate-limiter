<?php

declare(strict_types=1);

// FIX-A: copy of m.php (unchanged evidence harness) with one added arm, det-held: the S0 worker and the
// store share one RequestScopedClock over a StepClock and process() runs inside holding() (ruling LT-160A-01).

// #160 arm M (PLAN.md §5, §12.2 LT-160-01/05/06, §12.4, §12.5). One sub-arm
// per invocation, against the Redis on --port; one JSON row per trial / call.
// Nothing under src/ or tests/ is changed: the pipelines, the store, FixedClock
// and RespRedisCommandExecutor are used as they are; the clocks and recorders
// below live only here.
//
//   det, det-control            S0 worker: FixedClock('@T') / store FixedClock('@T+1') (control '@T'); process() checkOnly
//   ep-det, ep-det-control      newWorker()-built EvaluationPipeline, StepClock (control: no step); addBoundedSnapshot() by reflection
//   ep-builder-limit            RateLimiterBuilder ->withClock(StepClock)->build(); limit() checkOnly
//   ep-builder-outside          the same builder-wired pipeline; addBoundedSnapshot() by reflection outside holding()
//   nat                         S0 worker with the test's clocks behind pass-through recorders; process() checkOnly (K calls)
//   ep-nat                      newWorker() with the test's RedisTimeClock behind a recorder; process() checkOnly (K calls)
//
// Usage: php m.php --root=<repo> --port=<redis port> --subarm=<name> --n=<trials or K> --out=<rows.jsonl>

namespace Flaky160\M;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\BoundedCorrelationObservationDTO;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Service\RequestScopedClock;
use Maatify\RateLimiter\Tests\Fixtures\S0Baseline\S0EvaluationPipeline;
use Maatify\RateLimiter\Tests\Fixtures\S0Baseline\S0LoginProtectionPolicy;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\SharedCommon\Infrastructure\SystemClock;

$options = getopt('', ['root:', 'port:', 'subarm:', 'n:', 'out:']);
$root = (string) $options['root'];
require $root . '/vendor/autoload.php';

const SNAPSHOT_MESSAGE = 'Bounded correlation store returned an invalid snapshot.';

/** Shared in-memory log of clock readings, cleared per call. */
final class ReadLog
{
    /** @var list<array<string, mixed>> */
    public static array $reads = [];

    /**
     * One bounded backtrace taken inside a clock's now(): [0] = the line that
     * called now() and the function it is in, [1], [2] = the callers above it.
     * ($trace[0] is this method, $trace[1] is now().)
     */
    public static function frames(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        $frames = [];
        for ($i = 2; $i <= 4; $i++) {
            if (! isset($trace[$i])) {
                break;
            }
            $frames[] = [
                'call' => basename((string) ($trace[$i - 1]['file'] ?? '?')) . ':' . ($trace[$i - 1]['line'] ?? 0),
                'in' => ($trace[$i]['class'] ?? '') . '::' . $trace[$i]['function'],
            ];
        }
        return $frames;
    }
}

/**
 * Harness copy of the test's RedisTimeClock (KnownDeviceMixedVersionIntegrationTest
 * L230–L247): same command, same returned value ('@' . seconds). It also keeps
 * the reply's microseconds in ReadLog, after the reading is taken (LT-160-06).
 */
final class RecordingRedisTimeClock implements ClockInterface
{
    public function __construct(private readonly RespRedisCommandExecutor $executor, private readonly string $role) {}

    public function now(): DateTimeImmutable
    {
        $time = $this->executor->execute(['TIME']);
        if (! is_array($time) || ! is_string($time[0] ?? null)) {
            throw new \RuntimeException('Redis TIME reply is malformed.');
        }
        $reading = new DateTimeImmutable('@' . $time[0]);
        ReadLog::$reads[] = ['role' => $this->role, 's' => (int) $time[0], 'us' => (int) $time[1], 'frames' => ReadLog::frames()];
        return $reading;
    }

    public function getTimezone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
}

/** Pass-through recorder around SystemClock(UTC): returns the real reading unchanged (LT-160-06). */
final class RecordingSystemClock implements ClockInterface
{
    private SystemClock $inner;

    public function __construct(private readonly string $role)
    {
        $this->inner = new SystemClock(new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        $reading = $this->inner->now();
        ReadLog::$reads[] = ['role' => $this->role, 's' => (int) $reading->format('U'), 'us' => (int) $reading->format('u'), 'frames' => ReadLog::frames()];
        return $reading;
    }

    public function getTimezone(): DateTimeZone
    {
        return $this->inner->getTimezone();
    }
}

/** Harness step clock (PLAN §5.3, §12.4): second T on its first read, T + $step on every later read. */
final class StepClock implements ClockInterface
{
    public int $reads = 0;

    public function __construct(private readonly int $t, private readonly int $step) {}

    public function now(): DateTimeImmutable
    {
        $second = $this->reads === 0 ? $this->t : $this->t + $this->step;
        $this->reads++;
        ReadLog::$reads[] = ['role' => 'step', 's' => $second, 'us' => 0, 'frames' => ReadLog::frames()];
        return new DateTimeImmutable('@' . $second);
    }

    public function getTimezone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
}

/**
 * Pass-through executor recorder (PLAN §12.4): forwards every command and
 * returns every reply unchanged; records each EVAL of the store's
 * BOUNDED_SNAPSHOT body (TTL, caller time, reply expiresAt / error).
 */
final class RecordingExecutor implements RedisCommandExecutorInterface
{
    /** @var list<array<string, mixed>> */
    public array $snapshots = [];

    public function __construct(private readonly RedisCommandExecutorInterface $inner, private readonly string $boundedSnapshot) {}

    public function execute(array $command): mixed
    {
        $isSnapshot = ($command[0] ?? null) === 'EVAL' && ($command[1] ?? null) === $this->boundedSnapshot;
        try {
            $reply = $this->inner->execute($command);
        } catch (\Throwable $exception) {
            if ($isSnapshot) {
                $this->snapshots[] = ['ttl' => (int) $command[5], 'caller_time' => (int) $command[8], 'error' => $exception->getMessage()];
            }
            throw $exception;
        }
        if ($isSnapshot) {
            // EVAL script 2 KEYS[1] KEYS[2] ttl item maxDistinct callerTime -> {count, accepted, added, expiresAt, members...}
            $this->snapshots[] = ['ttl' => (int) $command[5], 'caller_time' => (int) $command[8],
                'expires_at' => is_array($reply) ? (int) ($reply[3] ?? -1) : null, 'count' => is_array($reply) ? ($reply[0] ?? null) : null];
        }
        return $reply;
    }
}

function boundedSnapshotBody(): string
{
    return (string) (new \ReflectionClassConstant(RedisFullCapabilityStore::class, 'BOUNDED_SNAPSHOT'))->getValue();
}

/** @return array<string, mixed> outcome of a call: the decision, or the exception with its frames */
function outcome(callable $call): array
{
    $started = hrtime(true);
    try {
        $result = $call();
        $out = ['type' => 'returned', 'decision' => $result instanceof RateLimitResultDTO ? $result->decision : get_debug_type($result)];
    } catch (\Throwable $exception) {
        $frames = [];
        foreach ($exception->getTrace() as $frame) {
            if (isset($frame['file'], $frame['line'])) {
                $frames[] = basename($frame['file']) . ':' . $frame['line'];
            }
        }
        $out = ['type' => 'exception', 'class' => $exception::class, 'message' => $exception->getMessage(),
            'thrown_at' => basename($exception->getFile()) . ':' . $exception->getLine(), 'frames' => array_slice($frames, 0, 12)];
    }
    $out['call_us'] = intdiv(hrtime(true) - $started, 1000);
    return $out;
}

function redisSecond(RespRedisCommandExecutor $executor): int
{
    $time = $executor->execute(['TIME']);
    return (int) $time[0];
}

function s0Worker(RedisFullCapabilityStore $store, ClockInterface $clock): S0EvaluationPipeline
{
    // As KnownDeviceMixedVersionIntegrationTest::s0Worker() (L173–L176).
    return new S0EvaluationPipeline($store, $store, new BudgetTracker($store, $clock), new AntiEquilibriumGate($store), new DecayCalculator($clock), new EphemeralBucket($store), 'test_secret', 'prod', $clock, null);
}

function newWorker(RedisFullCapabilityStore $store, ClockInterface $clock): EvaluationPipeline
{
    // As KnownDeviceMixedVersionIntegrationTest::newWorker() (L168–L171).
    return new EvaluationPipeline($store, $store, new BudgetTracker($store, $clock), new AntiEquilibriumGate($store), new DecayCalculator($clock), new EphemeralBucket($store), 'test_secret', 'prod', $clock, null);
}

function context(string $account): RateLimitContextDTO
{
    // As KnownDeviceMixedVersionIntegrationTest::context() (L178–L181).
    return new RateLimitContextDTO('198.51.100.80', 'Chrome/123', $account, ['device' => 'stable']);
}

function device(string $fingerprint): DeviceIdentityDTO
{
    // As KnownDeviceMixedVersionIntegrationTest::device($fp, true) (L183–L186).
    return new DeviceIdentityDTO($fingerprint, 'HIGH', true, false, 'chrome/123', true);
}

function invokeSnapshot(EvaluationPipeline $pipeline, string $label): mixed
{
    $method = new \ReflectionMethod(EvaluationPipeline::class, 'addBoundedSnapshot');
    return $method->invoke($pipeline, new BoundedCorrelationObservationDTO('m160-window-' . $label, 'm160-member-' . $label), 600, 4);
}

/** The EvaluationPipeline inside a built runtime, found by reflection (composite -> engine -> pipeline). */
function pipelineOf(object $runtime, int $depth = 0): ?EvaluationPipeline
{
    if ($depth > 3) {
        return null;
    }
    foreach ((new \ReflectionObject($runtime))->getProperties() as $property) {
        if (! $property->isInitialized($runtime)) {
            continue;
        }
        $value = $property->getValue($runtime);
        if ($value instanceof EvaluationPipeline) {
            return $value;
        }
        if (is_object($value) && str_starts_with($value::class, 'Maatify\\RateLimiter\\')) {
            $found = pipelineOf($value, $depth + 1);
            if ($found !== null) {
                return $found;
            }
        }
    }
    return null;
}

function builderRuntime(RedisFullCapabilityStore $store, ClockInterface $clock): object
{
    return RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('m160-key-secret', 'm160-fingerprint-secret', 'prod'), $store, new RecordingFailureSignalEmitter())
        ->withClock($clock)
        ->build();
}

/** NAT pairs (PLAN §12.5): [first reading, second reading] for each bounded-snapshot clock pair of one call. */
function natPairs(string $subarm): array
{
    $pairs = [];
    $reads = ReadLog::$reads;
    foreach ($reads as $index => $read) {
        if ($subarm === 'nat') {
            // The store's fallback reading made for addDistinctBoundedWithSnapshot, paired with the last S0 reading before it (L785).
            $forSnapshot = $read['role'] === 'store' && array_filter($read['frames'], static fn(array $f): bool => str_ends_with($f['in'], '::addDistinctBoundedWithSnapshot')) !== [];
            if (! $forSnapshot) {
                continue;
            }
            $first = null;
            for ($j = $index - 1; $j >= 0; $j--) {
                if ($reads[$j]['role'] === 's0') {
                    $first = $reads[$j];
                    break;
                }
            }
            $pairs[] = [$first, $read];
        } else {
            // EvaluationPipeline: the reading at L867 and the next reading (L881 or L911).
            if (($read['frames'][0]['call'] ?? '') !== 'EvaluationPipeline.php:867') {
                continue;
            }
            $pairs[] = [$read, $reads[$index + 1] ?? null];
        }
    }
    return array_map(static function (array $pair): array {
        [$first, $second] = $pair;
        if ($first === null || $second === null) {
            return ['complete' => false, 'first' => $first, 'second' => $second];
        }
        return [
            'complete' => true,
            'first_s' => $first['s'], 'first_us' => $first['us'], 'first_at' => $first['frames'][0]['call'] ?? null,
            'second_s' => $second['s'], 'second_us' => $second['us'], 'second_at' => $second['frames'][0]['call'] ?? null,
            'second_for' => $second['frames'][1]['in'] ?? null,
            'g_us' => ($second['s'] - $first['s']) * 1_000_000 + ($second['us'] - $first['us']),
            'straddle' => $second['s'] > $first['s'],
        ];
    }, $pairs);
}

$port = (int) $options['port'];
$subarm = (string) $options['subarm'];
$n = (int) $options['n'];
$out = fopen((string) $options['out'], 'wb');
$executor = new RespRedisCommandExecutor('127.0.0.1', $port);
$body = boundedSnapshotBody();
$run = bin2hex(random_bytes(4));
$counts = ['rows' => 0, 'exceptions' => 0];
$armStarted = hrtime(true);

for ($i = 1; $i <= $n; $i++) {
    ReadLog::$reads = [];
    $row = ['subarm' => $subarm, 'i' => $i];
    $namespace = "m160-{$subarm}-{$run}-{$i}";
    switch ($subarm) {
        case 'det':
        case 'det-control':
            $t = redisSecond($executor);
            $recorder = new RecordingExecutor($executor, $body);
            $store = new RedisFullCapabilityStore($recorder, $namespace, new FixedClock('@' . ($subarm === 'det' ? $t + 1 : $t)));
            $worker = s0Worker($store, new FixedClock('@' . $t));
            $row['t'] = $t;
            $row['outcome'] = outcome(static fn() => $worker->process(new S0LoginProtectionPolicy(), context("det-{$run}-{$i}"), RateLimitCommand::checkOnly('login_protection'), device("det-fp-{$run}-{$i}")));
            $row['snapshots'] = $recorder->snapshots;
            break;
        case 'det-held':
            $t = redisSecond($executor);
            $recorder = new RecordingExecutor($executor, $body);
            $host = new StepClock($t, 1);
            $scoped = new RequestScopedClock($host);
            $store = new RedisFullCapabilityStore($recorder, $namespace, $scoped);
            $worker = s0Worker($store, $scoped);
            $row['t'] = $t;
            $row['outcome'] = outcome(static fn() => $scoped->holding(static fn() => $worker->process(new S0LoginProtectionPolicy(), context("det-held-{$run}-{$i}"), RateLimitCommand::checkOnly('login_protection'), device("det-held-fp-{$run}-{$i}"))));
            $row['host_reads'] = $host->reads;
            $row['snapshots'] = $recorder->snapshots;
            break;
        case 'ep-det':
        case 'ep-det-control':
            $t = redisSecond($executor);
            $recorder = new RecordingExecutor($executor, $body);
            $store = new RedisFullCapabilityStore($recorder, $namespace);
            $clock = new StepClock($t, $subarm === 'ep-det' ? 1 : 0);
            $pipeline = newWorker($store, $clock);
            $row['t'] = $t;
            $row['outcome'] = outcome(static fn() => invokeSnapshot($pipeline, "{$run}-{$i}"));
            $row['snapshots'] = $recorder->snapshots;
            $row['clock_reads'] = array_map(static fn(array $r): array => ['s' => $r['s'], 'at' => $r['frames'][0]['call'] ?? null], ReadLog::$reads);
            break;
        case 'ep-builder-limit':
        case 'ep-builder-outside':
            $t = redisSecond($executor);
            $recorder = new RecordingExecutor($executor, $body);
            $store = new RedisFullCapabilityStore($recorder, $namespace);
            $clock = new StepClock($t, 1);
            $runtime = builderRuntime($store, $clock);
            $row['t'] = $t;
            $row['reads_before_call'] = $clock->reads;
            if ($subarm === 'ep-builder-limit') {
                $account = "bl-{$run}-{$i}";
                $row['outcome'] = outcome(static fn() => $runtime->limit(
                    new RateLimitContextDTO('198.51.100.80', 'Chrome/123', $account, ['device' => 'd-' . $account]),
                    RateLimitCommand::checkOnly('login_protection'),
                ));
            } else {
                $pipeline = pipelineOf($runtime);
                $row['pipeline_found'] = $pipeline !== null;
                $row['pipeline_clock_is_request_scoped'] = $pipeline !== null
                    && (new \ReflectionProperty(EvaluationPipeline::class, 'clock'))->getValue($pipeline) instanceof RequestScopedClock;
                $row['outcome'] = $pipeline === null ? ['type' => 'harness_error', 'message' => 'pipeline not found'] : outcome(static fn() => invokeSnapshot($pipeline, "{$run}-{$i}"));
            }
            $row['snapshots'] = $recorder->snapshots;
            $row['clock_reads'] = array_map(static fn(array $r): array => ['s' => $r['s'], 'at' => $r['frames'][0]['call'] ?? null], ReadLog::$reads);
            break;
        case 'nat':
            if (($i - 1) % 1000 === 0) {
                $executor->execute(['FLUSHDB']);
                $clock = new RecordingRedisTimeClock($executor, 's0');
                $fallback = new RecordingSystemClock('store');
            }
            // A fresh namespace per call: no state carries over between calls (an IP-scoped block
            // would end the call before the device window), so every call creates its window set.
            // A new worker per call, as the test (L146).
            $store = new RedisFullCapabilityStore($executor, $namespace, $fallback);
            $worker = s0Worker($store, $clock);
            ReadLog::$reads = [];
            $row['outcome'] = outcome(static fn() => $worker->process(new S0LoginProtectionPolicy(), context("nat-{$run}-{$i}"), RateLimitCommand::checkOnly('login_protection'), device("nat-fp-{$run}-{$i}")));
            $row['pairs'] = natPairs('nat');
            $row['reads'] = count(ReadLog::$reads);
            break;
        case 'ep-nat':
            if (($i - 1) % 1000 === 0) {
                $executor->execute(['FLUSHDB']);
                $clock = new RecordingRedisTimeClock($executor, 'ep');
            }
            $store = new RedisFullCapabilityStore($executor, $namespace);
            $worker = newWorker($store, $clock);
            ReadLog::$reads = [];
            $row['outcome'] = outcome(static fn() => $worker->process(new LoginProtectionPolicy(), context("epnat-{$run}-{$i}"), RateLimitCommand::checkOnly('login_protection'), device("epnat-fp-{$run}-{$i}")));
            $row['pairs'] = natPairs('ep-nat');
            $row['reads'] = count(ReadLog::$reads);
            break;
        default:
            fwrite(STDERR, "unknown subarm {$subarm}\n");
            exit(2);
    }
    $counts['rows']++;
    $counts['exceptions'] += ($row['outcome']['type'] ?? '') === 'exception' ? 1 : 0;
    fwrite($out, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
}
fclose($out);
$counts['seconds'] = round((hrtime(true) - $armStarted) / 1e9, 3);
echo json_encode(['subarm' => $subarm, 'n' => $n] + $counts, JSON_UNESCAPED_SLASHES), "\n";
