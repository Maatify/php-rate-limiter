<?php

declare(strict_types=1);

/*
 * #153 LT-153-07 harness library (PLAN.md §4, §5, §12). Evidence only: the
 * package is used through its public builder and the store unchanged. Phase 1's
 * lib.php is required as it is on main (LT-M-08); this file adds what §4.2
 * needs beside it and changes nothing in phase 1's directory.
 */

namespace Maatify\RateLimiter\Investigations\ClockMixes153;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\FailureFallbackConfigurationProviderInterface;
use Maatify\RateLimiter\Config\KnownDeviceAccountAllowancePolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\PolicyCapabilityProviderInterface;
use Maatify\RateLimiter\Config\PostPunishmentReentryPolicyInterface;
use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\DTO\FailureFallbackConfigurationDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\Enum\PolicyCapabilityEnum;
use Maatify\RateLimiter\Investigations\ClockSkew153 as P1;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Throwable;

const DAY = 86400;

/**
 * PLAN §4.2: one host reading per now(); value = reading + sigma. Logs the
 * call site and the scalar arguments of the calling frame (read only).
 */
final class OffsetClock implements ClockInterface
{
    public function __construct(private readonly P1\EventLog $log, public readonly float $sigma)
    {
    }

    public function now(): DateTimeImmutable
    {
        $h = microtime(true);
        $v = $h + $this->sigma;
        $trace = debug_backtrace(0, 2);
        $site = basename($trace[0]['file'] ?? '?') . ':' . ($trace[0]['line'] ?? 0);
        $args = [];
        foreach ($trace[1]['args'] ?? [] as $arg) {
            $args[] = is_scalar($arg) || $arg === null ? $arg : get_debug_type($arg);
        }
        $this->log->events[] = ['e' => 'now', 'h' => $h, 'v' => $v, 'site' => $site, 'fn' => $trace[1]['function'] ?? '', 'args' => $args];

        return P1\dt($v);
    }

    public function getTimezone(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }
}

/**
 * PLAN §4.2: logs every EVAL with KEYS, ARGV and reply; tracks the clock that
 * wrote each block key and pause member ('R' Redis, 'C' caller); runs the
 * read-only pre-reads and the PAUSE_READ rerun of §4.3 on a side connection.
 */
final class MixExecutor implements RedisCommandExecutorInterface
{
    /** @var array<string, string> script text => constant name */
    private array $scripts;
    /** @var array<string, string> block key => writer clock */
    public array $blockWriter = [];
    /** @var array<string, string> pause key|member => writer clock */
    public array $pauseWriter = [];
    /** @var array<string, true> keys written during the current step */
    public array $written = [];

    public function __construct(
        private readonly RedisCommandExecutorInterface $inner,
        private readonly RedisCommandExecutorInterface $side,
        private readonly P1\EventLog $log,
        private readonly float $sigma,
    ) {
        $this->scripts = P1\scriptNames();
    }

    public function execute(array $command): mixed
    {
        if ((string) $command[0] !== 'EVAL') {
            return $this->inner->execute($command);
        }
        $name = $this->scripts[(string) $command[1]] ?? 'EVAL?';
        $numKeys = (int) $command[2];
        $keys = array_map('strval', array_slice($command, 3, $numKeys));
        $argv = array_map('strval', array_slice($command, 3 + $numKeys));
        $event = ['e' => 'cmd', 'n' => $name];
        $tSend = microtime(true);
        $event['ts'] = $tSend;
        $mix = $this->preRead($name, $keys, $argv, $tSend);
        try {
            $reply = $this->inner->execute($command);
        } catch (Throwable $error) {
            $event['tr'] = microtime(true);
            $event['err'] = get_class($error) . ': ' . $error->getMessage();
            $this->log->events[] = $event;
            throw $error;
        }
        $event['tr'] = microtime(true);
        $event += $this->describe($name, $keys, $argv, $reply);
        if ($mix !== []) {
            $event['mix'] = $mix;
        }
        if ($name === 'PAUSE_READ') {
            $event['rerun'] = $this->pauseRerun($command, $keys);
        }
        $this->log->events[] = $event;

        return $reply;
    }

    /** Caller and Redis seconds at an instant, in E-SAME (θ ≈ 0, V-2). */
    private function seconds(float $t): array
    {
        return [(int) floor($t + $this->sigma), (int) floor($t)];
    }

    /** @return array{0: ?string, 1: ?string, 2: int} expiresAt, level, PTTL */
    private function readBlock(string $key): array
    {
        if ($key === '') {
            return [null, null, -2];
        }
        $pttl = (int) $this->side->execute(['PTTL', $key]);
        if ($pttl <= 0) {
            return [null, null, $pttl];
        }
        $fields = $this->side->execute(['HMGET', $key, 'expiresAt', 'level']);

        return [$fields[0] ?? null, $fields[1] ?? null, $pttl];
    }

    /**
     * Pre-reads for the Lua mixes L-1 .. L-5 (PLAN §1.3): the comparison is
     * evaluated with the reading clock and with the writer's clock at the
     * same instant; a flip needs the two clocks to differ (writer != reader).
     *
     * @param list<string> $keys
     * @param list<string> $argv
     * @return list<array<string, mixed>>
     */
    private function preRead(string $name, array $keys, array $argv, float $t): array
    {
        [$caller, $redis] = $this->seconds($t);
        $mix = [];
        $blockCheck = function (string $id, string $key, string $reader, bool $l2Only) use (&$mix, $caller, $redis): void {
            [$expires, $level, $pttl] = $this->readBlock($key);
            if ($expires === null || $pttl <= 0) {
                return;
            }
            $writer = $this->blockWriter[$key] ?? '?';
            $at = static fn (int $now): bool => (int) $expires > $now && (! $l2Only || (int) $level >= 2);
            $readerNow = $reader === 'C' ? $caller : $redis;
            $writerNow = $writer === 'C' ? $caller : $redis;
            $mix[] = [
                'id' => $id, 'w' => $writer, 'r' => $reader, 'exp' => (int) $expires, 'lvl' => (int) $level,
                'out' => $at($readerNow), 'cons' => $at($writerNow),
                'flip' => $writer !== '?' && $writer !== $reader && $at($readerNow) !== $at($writerNow),
            ];
        };
        switch ($name) {
            case 'HARD_BLOCK':
                $path = ($argv[9] ?? '') !== '' ? 'R' : 'C';
                $blockCheck('L-1', $keys[2], $path, true);
                $blockCheck('L-1', $keys[3], $path, true);
                $pathNow = $path === 'C' ? (int) $argv[4] : $redis;
                foreach ([$keys[4], $keys[5]] as $pauseKey) {
                    if ($pauseKey === '') {
                        continue;
                    }
                    foreach ($this->side->execute(['ZRANGE', $pauseKey, '0', '-1']) ?: [] as $member) {
                        $writer = $this->pauseWriter[$pauseKey . '|' . $member] ?? '?';
                        $finish = (int) substr((string) $member, strpos((string) $member, ':') + 1);
                        $writerNow = $writer === 'C' ? $caller : $redis;
                        $mix[] = [
                            'id' => 'L-5', 'w' => $writer, 'r' => $path, 'finish' => $finish,
                            'out' => $finish > $pathNow, 'cons' => $finish > $writerNow,
                            'flip' => $writer !== '?' && $writer !== $path && ($finish > $pathNow) !== ($finish > $writerNow),
                        ];
                    }
                }
                break;
            case 'LIFECYCLE_CLAIM':
                $blockCheck('L-2', $keys[2], 'R', false);
                $blockCheck('L-2', $keys[3], 'R', false);
                break;
            case 'BLOCK_GET':
                $blockCheck('L-3', $keys[0], 'R', false);
                break;
            case 'LIFECYCLE_READ':
                $blockCheck('L-4', $keys[2], 'R', true);
                $blockCheck('L-4', $keys[3], 'R', true);
                break;
        }

        return $mix;
    }

    /**
     * PLAN §4.3 for I: the same read-only PAUSE_READ with ARGV[2] = the Redis
     * second of the instant (E-SAME), on the side connection.
     *
     * @param list<mixed> $command
     * @param list<string> $keys
     */
    private function pauseRerun(array $command, array $keys): array
    {
        $numKeys = (int) $command[2];
        $redisNow = (int) floor((float) end($this->log->events)['h']);
        $rerun = $command;
        $rerun[3 + $numKeys + 1] = (string) $redisNow;
        $reply = $this->side->execute($rerun);
        $writers = [];
        foreach ([$keys[2] ?? '', $keys[3] ?? ''] as $pauseKey) {
            if ($pauseKey === '') {
                continue;
            }
            foreach ($this->side->execute(['ZRANGE', $pauseKey, '0', '-1']) ?: [] as $member) {
                $writers[] = $this->pauseWriter[$pauseKey . '|' . $member] ?? '?';
            }
        }

        return ['nowR' => $redisNow, 'el' => (int) $reply[0], 'act' => (int) $reply[1], 'w' => array_values(array_unique($writers))];
    }

    /**
     * Compact reply fields per script; updates the writer maps.
     *
     * @param list<string> $keys
     * @param list<string> $argv
     * @return array<string, mixed>
     */
    private function describe(string $name, array $keys, array $argv, mixed $reply): array
    {
        $r = is_array($reply) ? array_map(static fn ($v) => is_scalar($v) || $v === null ? $v : null, $reply) : $reply;
        switch ($name) {
            case 'SCORE_INCREMENT':
            case 'SCORE_SET':
            case 'LIFECYCLE_MUTATE':
            case 'BUDGET_INCREMENT':
            case 'BUDGET_SEED':
            case 'BLOCK_SET':
                $this->written[$keys[0]] = true;
                if ($name === 'BLOCK_SET') {
                    $this->blockWriter[$keys[0]] = 'R';
                }

                return ['k' => $this->short($keys[0]), 'r' => $r];
            case 'HARD_BLOCK':
                $path = ($argv[9] ?? '') !== '' ? 'R' : 'C';
                foreach ([$keys[0], $keys[2], $keys[4]] as $key) {
                    $this->written[$key] = true;
                }
                if (is_array($reply) && count($reply) >= 4 && (! isset($reply[5]) || (int) $reply[5] === 1)) {
                    $this->blockWriter[$keys[2]] = $path;
                    if ((int) $reply[2] === 1) {
                        // Activated pause member "now:pauseUntil" (L1172): now = pauseUntil - pause seconds.
                        $until = (int) $reply[3];
                        $this->pauseWriter[$keys[4] . '|' . ($until - (int) $argv[7]) . ':' . $until] = $path;
                    }
                }

                return ['k' => $this->short($keys[2]), 'path' => $path, 'a' => [$argv[0], $argv[1], $argv[4]], 'r' => $r];
            case 'BLOCK_GET':
                return ['k' => $this->short($keys[0]), 'w' => $this->blockWriter[$keys[0]] ?? '?', 'r' => $r];
            case 'LIFECYCLE_READ':
            case 'SCORE_GET':
            case 'BUDGET_GET':
            case 'LIFECYCLE_CLAIM':
                return ['k' => $this->short($keys[0]), 'r' => $r];
            case 'PAUSE_READ':
                return ['k' => $this->short($keys[2]), 'a' => $argv, 'r' => $r];
        }

        return [];
    }

    /** Family and logical-hash prefix of a key (namespace-independent, PLAN §6.4). */
    public function short(string $key): string
    {
        $parts = explode(':', $key);
        $n = count($parts);

        return $n >= 2 ? $parts[$n - 2] . ':' . substr($parts[$n - 1], 0, 12) : $key;
    }
}

/** A policy that mirrors LoginProtectionPolicy (PLAN §5.1). */
abstract class LoginMirror implements BlockPolicyInterface, PolicyCapabilityProviderInterface, FailureFallbackConfigurationProviderInterface, KnownDeviceAccountAllowancePolicyInterface
{
    protected LoginProtectionPolicy $login;

    public function __construct()
    {
        $this->login = new LoginProtectionPolicy();
    }

    /** LoginProtectionPolicy's capabilities without DISTRIBUTED_ACCOUNT. */
    public function getCapabilities(): array
    {
        return [PolicyCapabilityEnum::CREDENTIAL_SPRAY, PolicyCapabilityEnum::TRUSTED_AUTHENTICATION];
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

    public function getKnownDeviceAccountAllowance(): ?int
    {
        return $this->login->getKnownDeviceAccountAllowance();
    }
}

/** LOGIN-NA: lifecycle path (PostPunishmentReentryPolicyInterface is a marker). */
final class LoginNaPolicy extends LoginMirror implements PostPunishmentReentryPolicyInterface
{
    public function getName(): string
    {
        return 'login_na';
    }
}

/** CYCLE-NA: the same without PostPunishmentReentryPolicyInterface (cycle path). */
final class CycleNaPolicy extends LoginMirror
{
    public function getName(): string
    {
        return 'cycle_na';
    }
}

const POLICIES = ['LOGIN-NA' => 'login_na', 'CYCLE-NA' => 'cycle_na', 'LOGIN' => 'login_protection'];

/** One side of a pair: its own namespace, clock, executor and runtime (PLAN §4.4). */
final class Twin
{
    public readonly P1\EventLog $log;
    public readonly MixExecutor $executor;
    public readonly CompositeRateLimiterRuntimeInterface $runtime;
    public readonly RespRedisCommandExecutor $side;

    public function __construct(string $host, int $port, public readonly string $namespace, public readonly float $sigma)
    {
        $this->log = new P1\EventLog();
        $this->side = new RespRedisCommandExecutor($host, $port);
        $this->executor = new MixExecutor(new RespRedisCommandExecutor($host, $port), $this->side, $this->log, $sigma);
        $store = new RedisFullCapabilityStore($this->executor, $namespace);
        $this->runtime = RateLimiterBuilder::fromFullCapabilityStore(P1\baseConfig(), $store, new RecordingFailureSignalEmitter())
            ->withClock(new OffsetClock($this->log, $sigma))
            ->withPolicy(new LoginNaPolicy())
            ->withPolicy(new CycleNaPolicy())
            ->build();
    }

    /**
     * One call: 'check' | 'fail' | 'success' | 'claim'. Returns the step
     * record of PLAN §4.5 with the comparison events of §4.3.
     *
     * @return array<string, mixed>
     */
    public function step(string $op, string $policy, RateLimitContextDTO $context, ?string $claimId = null): array
    {
        $this->log->reset();
        $this->executor->written = [];
        $start = microtime(true);
        $row = ['op' => $op, 'h0' => $start];
        try {
            if ($op === 'claim') {
                $row['claim'] = $this->runtime->claimPostPunishmentReentry($context, $policy, (string) $claimId);
            } else {
                $command = match ($op) {
                    'check' => RateLimitCommand::checkOnly($policy),
                    'fail' => RateLimitCommand::recordFailure($policy),
                    'success' => RateLimitCommand::recordSuccess($policy),
                };
                $result = $this->runtime->limit($context, $command);
                $row['d'] = $result->decision;
                $row['lv'] = $result->blockLevel;
                $row['ra'] = $result->retryAfter;
                $reentry = $result->metadata?->postPunishmentReentry;
                $row['re'] = $reentry?->id;
            }
        } catch (Throwable $error) {
            $row['d'] = 'EXCEPTION';
            $row['x'] = get_class($error) . ': ' . $error->getMessage();
        }
        $row['h1'] = microtime(true);
        $row += comparisons($this->log->events, $this->sigma);
        $row['wk'] = array_keys($this->executor->written);

        return $row;
    }

    /**
     * PLAN §6.4: the non-time fields of the given keys, by namespace-independent
     * short name. Time fields (updatedAt, expiresAt, epochStart, members) are
     * left out: they differ between the twins by construction.
     *
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public function state(array $keys): array
    {
        $state = [];
        foreach ($keys as $key) {
            $short = $this->executor->short($key);
            $type = (string) $this->side->execute(['TYPE', $key]);
            if ($type === 'none') {
                $state[$short] = null;
            } elseif ($type === 'hash') {
                $fields = $this->side->execute(['HMGET', $key, 'value', 'level', 'count', 'generation', 'reentryId']);
                $state[$short] = ['v' => $fields[0], 'l' => $fields[1], 'c' => $fields[2], 'g' => $fields[3], 're' => $fields[4] !== null];
            } elseif ($type === 'zset') {
                $state[$short] = ['z' => (int) $this->side->execute(['ZCARD', $key])];
            } else {
                $state[$short] = ['t' => $type];
            }
        }
        ksort($state);

        return $state;
    }
}

/** DecayCalculator::effectiveInterval (L98-L108), read only. */
function interval(string $scope, int $level): int
{
    $base = match ($scope) {
        'account' => 600,
        'device' => 300,
        'ip' => 180,
        default => 600,
    };

    return $level >= 2 ? $base * 2 : $base;
}

/** DecayCalculator::calculateDecay (L40-L53) at an integer now. */
function decayAt(int $score, int $last, int $level, string $scope, int $paused, int $now): int
{
    if ($score <= 0) {
        return 0;
    }
    $elapsed = max(0, ($now - $last) - $paused);

    return $elapsed <= 0 ? 0 : intdiv($elapsed, interval($scope, $level));
}

/** DecayCalculator::secondsUntilBelowThreshold (L66-L96) at an integer now. */
function secondsUntilAt(int $score, int $last, int $level, string $scope, int $threshold, int $paused, int $activeUntil, int $now): int
{
    if ($score < $threshold) {
        return 0;
    }
    $interval = interval($scope, $level);
    $elapsed = max(0, ($now - $last) - $paused);
    $remaining = ($score - $threshold + 1) - intdiv($elapsed, $interval);
    if ($remaining <= 0) {
        return 0;
    }

    return (($remaining * $interval) - ($elapsed % $interval)) + max(0, $activeUntil - $now);
}

/**
 * PLAN §4.3, §6.1: every comparison event of one call, with the outcome at
 * the caller second ⌊h + σ⌋ and at the Redis second ⌊h⌋ (E-SAME), and the
 * flip when the stored operand's writer clock differs from the reading clock.
 * Operand recovery rules: harness-time note (PLAN §13).
 *
 * @param list<array<string, mixed>> $events
 * @return array{c: list<array<string, mixed>>, sites: array<string, int>, cmds: list<array<string, mixed>>, h531: ?float}
 */
function comparisons(array $events, float $sigma): array
{
    $out = [];
    $sites = [];
    $cmds = [];
    $h531 = null;
    $last1780 = null;
    $count = count($events);
    for ($i = 0; $i < $count; $i++) {
        $event = $events[$i];
        if ($event['e'] === 'cmd') {
            foreach ($event['mix'] ?? [] as $mix) {
                $out[] = ['s' => $mix['id']] + $mix;
            }
            $cmds[] = array_diff_key($event, ['e' => 1, 'mix' => 1]);
            continue;
        }
        $site = $event['site'];
        $sites[$site] = ($sites[$site] ?? 0) + 1;
        if ($site === 'EvaluationPipeline.php:1780') {
            $last1780 = $event;
        }
        $nC = (int) floor($event['v']);
        $nR = (int) floor($event['h']);
        $a = $event['args'];
        $prevCmd = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            if ($events[$j]['e'] === 'cmd') {
                $prevCmd = $events[$j];
                break;
            }
            if ($events[$j]['e'] === 'now') {
                break;
            }
        }
        switch ($site) {
            case 'DecayCalculator.php:44':
                // B: calculateDecay(score, last, level, scope, paused); last is a Redis second.
                [$score, $last, $level, $scope, $paused] = $a + [0, 0, 0, 'account', 0];
                $dC = decayAt((int) $score, (int) $last, (int) $level, (string) $scope, (int) $paused, $nC);
                $dR = decayAt((int) $score, (int) $last, (int) $level, (string) $scope, (int) $paused, $nR);
                $out[] = ['s' => 'B', 'scope' => $scope, 'sc' => $score, 'last' => $last, 'lvl' => $level, 'p' => $paused, 'nC' => $nC, 'nR' => $nR, 'out' => $dC, 'cons' => $dR, 'flip' => $dC !== $dR, 'h' => $event['h']];
                break;
            case 'DecayCalculator.php:84':
                // B (retryAfter, secondary). L1780 passes the caller second itself as last.
                [$score, $last, $level, $scope, $threshold, $paused, $active] = $a + [0, 0, 0, 'account', 1, 0, 0];
                // L1778: the L1780 reading is evaluated before currentBlockLevel() (L2740), so it is the
                // latest L1780 event, not the event just before this one.
                $callerLast = $last1780 !== null && (int) floor($last1780['v']) === (int) $last;
                $lastR = $callerLast ? (int) floor($last1780['h']) : (int) $last;
                $last1780 = null;
                $rC = secondsUntilAt((int) $score, (int) $last, (int) $level, (string) $scope, (int) $threshold, (int) $paused, (int) $active, $nC);
                $rR = secondsUntilAt((int) $score, $lastR, (int) $level, (string) $scope, (int) $threshold, (int) $paused, (int) $active, $nR);
                $out[] = ['s' => 'B84', 'scope' => $scope, 'callerLast' => $callerLast, 'last' => (int) $last, 'nC' => $nC, 'out' => $rC, 'cons' => $rR, 'flip' => $rC !== $rR];
                break;
            case 'EvaluationPipeline.php:280':
                // C: the LIFECYCLE_READ reply just before (L279).
                $r = ($prevCmd['n'] ?? '') === 'LIFECYCLE_READ' ? $prevCmd['r'] : null;
                if (is_array($r) && count($r) >= 7) {
                    $generation = (string) $r[4];
                    $hasEvidence = (string) $r[5] !== '' || (string) $r[6] !== '';
                    $until = (int) $r[6];
                    $expiry = (int) $r[3];
                    $at = static fn (int $now): bool => $generation !== '' && $hasEvidence && $until > $now && $expiry > $now;
                    $out[] = ['s' => 'C', 'until' => $until, 'exp' => $expiry, 'nC' => $nC, 'nR' => $nR, 'out' => $at($nC), 'cons' => $at($nR), 'flip' => $at($nC) !== $at($nR)];
                } else {
                    $out[] = ['s' => 'C', 'none' => true, 'out' => false, 'cons' => false, 'flip' => false];
                }
                break;
            case 'EvaluationPipeline.php:531':
                $h531 = $event['h'];
                // E: the BLOCK_GET replies that follow (L549, L558), before any other command or now().
                for ($j = $i + 1; $j < $count && $events[$j]['e'] === 'cmd' && $events[$j]['n'] === 'BLOCK_GET'; $j++) {
                    $r = $events[$j]['r'];
                    if (! is_array($r) || count($r) < 2) {
                        continue;
                    }
                    $writer = $events[$j]['w'];
                    $at = static fn (int $now): bool => (int) $r[0] >= 2 && (int) $r[1] > $now;
                    $cons = $writer === 'C' ? $at($nC) : $at($nR);
                    $out[] = ['s' => 'E', 'k' => $events[$j]['k'], 'w' => $writer, 'lvl' => (int) $r[0], 'exp' => (int) $r[1], 'nC' => $nC, 'nR' => $nR, 'out' => $at($nC), 'cons' => $cons, 'flip' => $at($nC) !== $cons, 'ra' => (int) $r[1] - $nC, 'h' => $event['h']];
                }
                break;
            case 'EvaluationPipeline.php:2740':
                // E′: the BLOCK_GET reply just before (L2739).
                $r = ($prevCmd['n'] ?? '') === 'BLOCK_GET' ? $prevCmd['r'] : null;
                if (is_array($r) && count($r) >= 2) {
                    $writer = $prevCmd['w'];
                    $at = static fn (int $now): bool => (int) $r[0] >= 2 && (int) $r[1] > $now;
                    $cons = $writer === 'C' ? $at($nC) : $at($nR);
                    $out[] = ['s' => "E'", 'k' => $prevCmd['k'], 'w' => $writer, 'lvl' => (int) $r[0], 'exp' => (int) $r[1], 'out' => $at($nC), 'cons' => $cons, 'flip' => $at($nC) !== $cons];
                }
                break;
            case 'BudgetTracker.php:55':
                // F: the BUDGET_GET reply just before (L52 → L40).
                $r = ($prevCmd['n'] ?? '') === 'BUDGET_GET' ? $prevCmd['r'] : null;
                if (is_array($r) && count($r) >= 2) {
                    $at = static fn (int $now): bool => (int) $r[1] + DAY > $now;
                    $out[] = ['s' => 'F', 'count' => (int) $r[0], 'start' => (int) $r[1], 'out' => $at($nC), 'cons' => $at($nR), 'flip' => $at($nC) !== $at($nR)];
                }
                break;
            case 'EvaluationPipeline.php:2765':
                // I: the PAUSE_READ that follows, and its rerun at the Redis second.
                $next = $events[$i + 1] ?? null;
                if ($next !== null && $next['e'] === 'cmd' && $next['n'] === 'PAUSE_READ' && isset($next['rerun'])) {
                    $fromCaller = isset($events[$i - 1]) && $events[$i - 1]['e'] === 'now' && $events[$i - 1]['site'] === 'EvaluationPipeline.php:1776';
                    $el = (int) ($next['r'][0] ?? 0);
                    $act = (int) ($next['r'][1] ?? 0);
                    $out[] = ['s' => 'I', 'from' => (int) $next['a'][0], 'fromCaller' => $fromCaller, 'w' => $next['rerun']['w'], 'out' => $el, 'cons' => $next['rerun']['el'], 'act' => $act, 'actR' => $next['rerun']['act'], 'flip' => $el !== $next['rerun']['el']];
                }
                break;
        }
    }

    return ['c' => $out, 'sites' => $sites, 'cmds' => $cmds, 'h531' => $h531];
}

/** Context of a stream: its own account, device and /24 (PLAN §5.4). */
function streamContext(string $account, int $stream, int $k = 0): RateLimitContextDTO
{
    return P1\context($account, 'dev-' . $account, $stream * 256 + 1 + ($k % 250));
}
