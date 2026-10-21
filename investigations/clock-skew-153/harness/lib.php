<?php

declare(strict_types=1);

/*
 * #153 phase 1 harness library (PLAN.md §4, §5, §11). Evidence only: it uses
 * the package through its public builder and the store unchanged, and reads
 * private script constants once through reflection to recognise them.
 */

namespace Maatify\RateLimiter\Investigations\ClockSkew153;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\FailureFallbackConfigurationProviderInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\PolicyCapabilityProviderInterface;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\DTO\FailureFallbackConfigurationDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\Enum\PolicyCapabilityEnum;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\SharedCommon\Infrastructure\SystemClock;
use ReflectionClassConstant;
use Throwable;

const VALIDATOR_MESSAGE = 'Bounded correlation store returned an invalid snapshot.';

/** Ordered event log shared by the recording clock and executor, reset per request. */
final class EventLog
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    public function reset(): void
    {
        $this->events = [];
    }
}

/** Every now() is logged with its returned value and the host time of the call. */
final class RecordingClock implements ClockInterface
{
    /** @param \Closure(): float $source returns the arm's time as Unix seconds with microseconds */
    public function __construct(private readonly EventLog $log, private readonly \Closure $source)
    {
    }

    public function now(): DateTimeImmutable
    {
        $host = microtime(true);
        $value = ($this->source)();
        // The call site of now(): frame 0 is where the runtime called it.
        $frame = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
        $site = basename($frame['file'] ?? '?') . ':' . ($frame['line'] ?? 0);
        $this->log->events[] = ['e' => 'now', 'host' => $host, 'value' => $value, 'site' => $site];

        return dt($value);
    }

    public function getTimezone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
}

function dt(float $unix): DateTimeImmutable
{
    $result = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $unix), new DateTimeZone('UTC'));
    if ($result === false) {
        throw new \RuntimeException('cannot build time ' . $unix);
    }

    return $result->setTimezone(new DateTimeZone('UTC'));
}

/** Delegates to the RESP executor; logs every command, and snapshot replies. */
final class RecordingExecutor implements RedisCommandExecutorInterface
{
    /** @var array<string, string> script text => constant name */
    private array $scripts;

    public function __construct(private readonly RedisCommandExecutorInterface $inner, private readonly EventLog $log)
    {
        $this->scripts = scriptNames();
    }

    public function execute(array $command): mixed
    {
        $name = (string) $command[0];
        if ($name === 'EVAL') {
            $name = $this->scripts[(string) $command[1]] ?? 'EVAL?';
        }
        $event = ['e' => 'cmd', 'name' => $name, 'tSend' => microtime(true)];
        try {
            $reply = $this->inner->execute($command);
        } catch (Throwable $error) {
            $event['tRecv'] = microtime(true);
            $event['error'] = get_class($error) . ': ' . $error->getMessage();
            $this->log->events[] = $event;
            throw $error;
        }
        $event['tRecv'] = microtime(true);
        if ($name === 'BOUNDED_SNAPSHOT' || $name === 'ROTATED_SNAPSHOT') {
            $keys = (int) $command[2];
            // BOUNDED: ARGV = [ttl, item, max]; ROTATED: ARGV = [script, ttl, current, previous, max].
            $event['ttl'] = (int) $command[3 + $keys + ($name === 'BOUNDED_SNAPSHOT' ? 0 : 1)];
            $event['max'] = (string) $command[3 + $keys + ($name === 'BOUNDED_SNAPSHOT' ? 2 : 4)];
            if (is_array($reply) && count($reply) >= 4) {
                $event['count'] = (int) $reply[0];
                $event['accepted'] = (int) $reply[1];
                $event['added'] = (int) $reply[2];
                $event['expiresAt'] = $reply[3] === null || $reply[3] === false ? null : (int) $reply[3];
            } else {
                $event['reply'] = is_scalar($reply) ? $reply : gettype($reply);
            }
        }
        $this->log->events[] = $event;

        return $reply;
    }
}

/** @return array<string, string> */
function scriptNames(): array
{
    static $names = null;
    if ($names !== null) {
        return $names;
    }
    $names = [];
    $class = new \ReflectionClass(RedisFullCapabilityStore::class);
    foreach ($class->getReflectionConstants() as $constant) {
        $value = $constant->getValue();
        if (is_string($value) && str_contains($value, 'redis.call')) {
            $names[$value] = $constant->getName();
        }
    }
    // Sanity: the two scripts of #153 must be recognisable.
    foreach (['BOUNDED_SNAPSHOT', 'ROTATED_SNAPSHOT'] as $required) {
        $text = (new ReflectionClassConstant(RedisFullCapabilityStore::class, $required))->getValue();
        if (! is_string($text) || ($names[$text] ?? null) !== $required) {
            throw new \RuntimeException('cannot identify ' . $required);
        }
    }

    return $names;
}

/** Redis TIME on a dedicated connection, as Unix seconds with microseconds. */
final class RedisTime
{
    public function __construct(private readonly RedisCommandExecutorInterface $redis)
    {
    }

    public function now(): float
    {
        $time = $this->redis->execute(['TIME']);
        if (! is_array($time)) {
            throw new \RuntimeException('TIME');
        }

        return (int) $time[0] + ((int) $time[1]) / 1_000_000;
    }

    /** @return array{0: float, 1: float, 2: float} host send, redis time, host receive */
    public function probe(): array
    {
        $send = microtime(true);
        $value = $this->now();

        return [$send, $value, microtime(true)];
    }
}

/**
 * Clock arms of PLAN §5.2 and §11 (P-TAIL): SYS, BEHIND(s), AHEAD(a), ALIGN,
 * ALIGN-GATED, ALIGN-BEHIND1. Each returns a time source closure.
 */
function clockSource(string $clock, float $offset, RedisTime $redisTime): \Closure
{
    $system = new SystemClock(new DateTimeZone('UTC'));

    return match ($clock) {
        'SYS' => static fn (): float => (float) $system->now()->format('U.u'),
        'BEHIND' => static fn (): float => microtime(true) - $offset,
        'AHEAD' => static fn (): float => microtime(true) + $offset,
        'ALIGN', 'ALIGN-GATED' => static fn (): float => $redisTime->now(),
        'ALIGN-BEHIND1' => static fn (): float => $redisTime->now() - 1.0,
        default => throw new \InvalidArgumentException($clock),
    };
}

/**
 * The CUSTOM arm of PLAN §5.1: a Host-defined policy (DEC-008) that declares
 * only DISTRIBUTED_ACCOUNT, with the thresholds, deltas, failure mode,
 * budget and bounded fallback of LoginProtectionPolicy. Registration requires
 * the fallback for an authentication policy (RateLimiterEngine L115).
 */
final class CustomDistributedPolicy implements BlockPolicyInterface, PolicyCapabilityProviderInterface, FailureFallbackConfigurationProviderInterface
{
    private LoginProtectionPolicy $login;

    public function __construct()
    {
        $this->login = new LoginProtectionPolicy();
    }

    public function getName(): string
    {
        return 'custom_distributed';
    }

    public function getCapabilities(): array
    {
        return [PolicyCapabilityEnum::DISTRIBUTED_ACCOUNT];
    }

    public function getScoreThresholds(): PolicyThresholdsDTO
    {
        return $this->login->getScoreThresholds();
    }

    public function getScoreDeltas(): ScoreDeltasDTO
    {
        return $this->login->getScoreDeltas();
    }

    public function getFailureMode(): string
    {
        return $this->login->getFailureMode();
    }

    public function getBudgetConfig(): ?BudgetConfigDTO
    {
        return $this->login->getBudgetConfig();
    }

    public function getFailureFallbackConfiguration(): FailureFallbackConfigurationDTO
    {
        return $this->login->getFailureFallbackConfiguration();
    }
}

const POLICY_NAMES = ['LOGIN' => 'login_protection', 'OTP' => 'otp_protection', 'CUSTOM' => 'custom_distributed'];

/** One runtime over one store namespace, with the recording wrappers. */
final class Lab
{
    public readonly EventLog $log;
    public readonly RedisFullCapabilityStore $store;
    public readonly RecordingFailureSignalEmitter $emitter;
    public readonly RedisTime $redisTime;
    private RecordingClock $clock;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        string $namespace,
        string $clock,
        float $offset,
    ) {
        $this->log = new EventLog();
        $this->redisTime = new RedisTime(new RespRedisCommandExecutor($host, $port));
        $executor = new RecordingExecutor(new RespRedisCommandExecutor($host, $port), $this->log);
        $this->store = new RedisFullCapabilityStore($executor, $namespace);
        $this->emitter = new RecordingFailureSignalEmitter();
        $this->clock = new RecordingClock($this->log, clockSource($clock, $offset, $this->redisTime));
    }

    public function runtime(RateLimiterConfig $config): CompositeRateLimiterRuntimeInterface
    {
        return RateLimiterBuilder::fromFullCapabilityStore($config, $this->store, $this->emitter)
            ->withClock($this->clock)
            ->withPolicy(new CustomDistributedPolicy())
            ->build();
    }

    /**
     * One checkOnly through limit(), returning the per-request record of PLAN §4.2.
     *
     * @return array<string, mixed>
     */
    public function request(CompositeRateLimiterRuntimeInterface $runtime, string $policy, RateLimitContextDTO $context): array
    {
        $this->log->reset();
        $start = microtime(true);
        try {
            $result = $runtime->limit($context, RateLimitCommand::checkOnly($policy));
            $outcome = $result->decision;
            $exception = null;
        } catch (Throwable $error) {
            $outcome = 'EXCEPTION';
            $exception = get_class($error) . ': ' . $error->getMessage();
        }

        return ['start' => $start, 'outcome' => $outcome, 'exception' => $exception] + analyse($this->log->events);
    }
}

/**
 * Per snapshot EVAL: the L867 reading is the last now() before it (PLAN §4.1);
 * V-4 counts the now() events since the previous Redis command.
 *
 * @param list<array<string, mixed>> $events
 * @return array{snaps: list<array<string, mixed>>, errors: list<string>, other: list<array<string, mixed>>}
 */
function analyse(array $events): array
{
    $snaps = [];
    $errors = [];
    $other = [];
    $lastNow = null;
    $nowsSinceCommand = 0;
    $sitesSinceCommand = [];
    foreach ($events as $event) {
        if ($event['e'] === 'now') {
            $lastNow = $event;
            $nowsSinceCommand++;
            $sitesSinceCommand[] = $event['site'];
            continue;
        }
        if (isset($event['error'])) {
            $errors[] = $event['name'] . ' ' . $event['error'];
        }
        if (($event['name'] === 'BOUNDED_SNAPSHOT' || $event['name'] === 'ROTATED_SNAPSHOT') && ! validatedSite($event)) {
            // addDistinctBounded* paths run the same scripts but drop expiresAt
            // (store L1365-L1368, L1379-L1388); never compared with the caller clock.
            $other[] = ['n' => $event['name'], 'ttl' => $event['ttl'] ?? null, 'max' => $event['max'] ?? null];
        } elseif ($event['name'] === 'BOUNDED_SNAPSHOT' || $event['name'] === 'ROTATED_SNAPSHOT') {
            $snap = [
                'script' => $event['name'] === 'BOUNDED_SNAPSHOT' ? 'bounded' : 'rotated',
                'site' => $event['ttl'] === 86400 ? 'occurrence' : 'device',
                'ttl' => $event['ttl'] ?? null,
                'callerNow' => $lastNow['value'] ?? null,
                'callerHost' => $lastNow['host'] ?? null,
                'v4' => $nowsSinceCommand,
                'nowSite' => $lastNow['site'] ?? null,
                'sites' => $sitesSinceCommand,
                'tSend' => $event['tSend'],
                'tRecv' => $event['tRecv'],
            ];
            if (isset($event['error'])) {
                $snap['error'] = $event['error'];
            }
            if (array_key_exists('expiresAt', $event) && $event['expiresAt'] !== null && $lastNow !== null) {
                $n = (int) floor($lastNow['value']);
                $m = $event['expiresAt'] - $event['ttl'];
                $snap += [
                    'expiresAt' => $event['expiresAt'],
                    'N' => $n,
                    'M' => $m,
                    'added' => $event['added'],
                    'count' => $event['count'],
                    'UP' => $m > $n,
                    'LO' => $m + $event['ttl'] <= $n,
                ];
            }
            $snaps[] = $snap;
        }
        $nowsSinceCommand = 0;
        $sitesSinceCommand = [];
    }

    return ['snaps' => $snaps, 'errors' => $errors, 'other' => $other];
}

/**
 * The two validated call sites, by (ttl, max): the device window (600, 4),
 * EvaluationPipeline L690, and the occurrence history (86400, 3), L719. Every
 * other caller of the two scripts uses another pair, except the IPv6 /40
 * scope (600, 4), which no population reaches (all contexts are IPv4).
 *
 * @param array<string, mixed> $event
 */
function validatedSite(array $event): bool
{
    return in_array([$event['ttl'] ?? null, $event['max'] ?? null], [[600, '4'], [86400, '3']], true);
}

function ip(int $i): string
{
    // Benchmarking range 198.18.0.0/15 (PLAN §5.3, population F).
    return '198.' . (18 + intdiv($i, 65536)) . '.' . (intdiv($i, 256) % 256) . '.' . ($i % 256);
}

function context(string $account, string $device, int $ipIndex): RateLimitContextDTO
{
    return new RateLimitContextDTO(ip($ipIndex), 'Mozilla/5.0', $account, ['device' => $device]);
}

function baseConfig(): RateLimiterConfig
{
    return new RateLimiterConfig('k153-active', 'f153-active', 'prod');
}

function rotatedConfig(): RateLimiterConfig
{
    return new RateLimiterConfig('k153-next', 'f153-next', 'prod', 'k153-active', 'f153-active');
}

/** Wait until the Redis sub-second fraction is below 0.5 (ALIGN-GATED, PLAN §5.2). */
function gate(RedisTime $redisTime): void
{
    while (true) {
        $fraction = fmod($redisTime->now(), 1.0);
        if ($fraction < 0.5) {
            return;
        }
        usleep((int) ((1.0 - $fraction) * 1_000_000) + 200);
    }
}

/** Wait until Redis time reaches $target (Unix seconds with microseconds). */
function waitRedisUntil(RedisTime $redisTime, float $target): void
{
    while (true) {
        $remaining = $target - $redisTime->now();
        if ($remaining <= 0) {
            return;
        }
        usleep(max(100, (int) (($remaining > 0.02 ? $remaining - 0.01 : $remaining) * 1_000_000)));
    }
}

/** @param array<string, mixed> $row */
function writeRow($handle, array $row): void
{
    fwrite($handle, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n");
}

/**
 * The matrix of PLAN.md §5.4 in its fixed order.
 *
 * @return list<array{0: string, 1: float, 2: string, 3: string, 4: int}> clock, offset, population, policy id, N (F cells)
 */
function cells(string $env): array
{
    $behind = [0.25, 0.50, 0.75, 1.00, 2.00];
    $reuse = ['R', 'Q', 'ROT-F', 'ROT-R', 'ROT-P'];
    $cells = [];
    foreach (['LOGIN', 'OTP', 'CUSTOM'] as $policy) {
        $cells[] = ['SYS', 0.0, 'F', $policy, 10_000];
    }
    foreach ($reuse as $population) {
        $cells[] = ['SYS', 0.0, $population, 'LOGIN', $population === 'ROT-F' ? 2_000 : 0];
    }
    foreach ($behind as $s) {
        foreach (['LOGIN', 'OTP', 'CUSTOM'] as $policy) {
            $cells[] = ['BEHIND', $s, 'F', $policy, 2_000];
        }
    }
    if ($env === 'E-SAME') {
        foreach ($behind as $s) {
            foreach ($reuse as $population) {
                $cells[] = ['BEHIND', $s, $population, 'LOGIN', $population === 'ROT-F' ? 2_000 : 0];
            }
        }
    }
    foreach (['ALIGN-GATED', 'ALIGN', 'ALIGN-BEHIND1'] as $clock) {
        $cells[] = [$clock, 0.0, 'F', 'LOGIN', 2_000];
    }

    return $cells;
}

function cellName(string $clock, float $offset, string $population, string $policyId): string
{
    return $clock . ($clock === 'BEHIND' ? sprintf('%.2f', $offset) : '') . '_' . $population . '_' . $policyId;
}
