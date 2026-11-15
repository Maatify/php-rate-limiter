<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Investigations\Evalsha210;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\AdmissionFenceControlInterface;
use Maatify\RateLimiter\Service\RateLimiterInterface;
use Maatify\RateLimiter\Tests\Support\Admission\PublicRedisAdmissionFixture;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\OverlayWire;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;

/**
 * Public-API workloads for the EVALSHA study (#210). Every request goes through the public builder; the only variable between runs
 * is the script mode of the RESP test client (and the network in front of Redis). Wire counters are read from that client.
 */
final class Harness
{
    public const NOW = 1_793_793_600;

    public int $commits = 0;

    public int $verifies = 0;

    private RespRedisCommandExecutor $resp;

    private CallableRedisCommandExecutor $executor;

    public function __construct(string $host, int $port, string $mode, private readonly string $namespace)
    {
        $this->resp = new RespRedisCommandExecutor($host, $port, $mode);
        $this->executor = new CallableRedisCommandExecutor(function (array $command): mixed {
            if (OverlayWire::isCommit($command)) {
                $this->commits++;
            } elseif ($command[0] === 'EVAL' && is_string($command[1] ?? null) && str_contains($command[1], 'request commit')) {
                $this->verifies++;
            }

            return $this->resp->execute($command);
        });
    }

    public function client(): RespRedisCommandExecutor
    {
        return $this->resp;
    }

    public function raw(): RespRedisCommandExecutor
    {
        return new RespRedisCommandExecutor($this->where[0], $this->where[1], RespRedisCommandExecutor::MODE_EVAL);
    }

    /** @var array{string, int} */
    private array $where = ['', 0];

    public static function connect(string $host, int $port, string $mode, string $namespace): self
    {
        $harness = new self($host, $port, $mode, $namespace);
        $harness->where = [$host, $port];

        return $harness;
    }

    public function limiter(int $now = self::NOW): RateLimiterInterface
    {
        return RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('test_secret', 'test_fp', 'prod'),
            new RedisFullCapabilityStore($this->executor, $this->namespace),
            new RecordingFailureSignalEmitter(),
        )->withClock(new FixedClock('@' . $now))->build();
    }

    public function admission(int $now = self::NOW): RateLimiterInterface
    {
        $runtime = PublicRedisAdmissionFixture::build($this->executor, $this->namespace . '-admission', $now);
        if (! $runtime instanceof AdmissionFenceControlInterface) {
            throw new \RuntimeException('The admission runtime has no fence control.');
        }

        return $runtime;
    }

    public function openFence(): void
    {
        $runtime = $this->admission();
        if ($runtime instanceof AdmissionFenceControlInterface) {
            $runtime->openAdmissionFence('login');
        }
        $runtime->limit($this->context('seed'), RateLimitCommand::checkOnly('login_protection'));
    }

    /** @var array<string, string> account => the source address the account always comes from */
    private array $sources = [];

    /**
     * Every account has its own source (its own /24): a workload must not measure the per-source budgets, the credential-spray
     * correlation or the source capacity of the admission lane (the clock is fixed, so no window ever slides).
     */
    public function context(string $account, ?string $device = null): RateLimitContextDTO
    {
        if (! isset($this->sources[$account])) {
            $n = count($this->sources);
            $this->sources[$account] = '10.' . (intdiv($n, 250) % 250) . '.' . ($n % 250) . '.' . (1 + intdiv($n, 62500) % 250);
        }

        return new RateLimitContextDTO($this->sources[$account], 'Mozilla/5.0 study', $account, null, $device ?? 'device-' . $account, false);
    }

    /** An outage of the backend on the evaluation scripts, as in the recovery regression: three failed requests open the circuit. */
    public function openCircuit(): void
    {
        $outage = new CallableRedisCommandExecutor(function (array $command): mixed {
            if (OverlayWire::isScript($command, '__ov_result')) {
                throw new BackendFailureException('Study outage.');
            }

            return $this->resp->execute($command);
        });
        for ($i = 0; $i < 3; $i++) {
            RateLimiterBuilder::fromFullCapabilityStore(
                new RateLimiterConfig('test_secret', 'test_fp', 'prod'),
                new RedisFullCapabilityStore($outage, $this->namespace),
                new RecordingFailureSignalEmitter(),
            )->withClock(new FixedClock('@' . (self::NOW + $i)))->build()->limit($this->context('outage-' . $i), RateLimitCommand::checkOnly('login_protection'));
        }
    }

    /**
     * Prepare one iteration of a workload and return the measured call.
     *
     * @return callable(): mixed
     */
    public function prepare(string $workload, int $i): callable
    {
        switch ($workload) {
            case 'read':
                $limiter = $this->limiter();

                return fn(): mixed => $limiter->limit($this->context('read-fixed'), RateLimitCommand::checkOnly('login_protection'));
            case 'newdevice':
                $limiter = $this->limiter();

                return fn(): mixed => $limiter->limit($this->context('new-' . $i), RateLimitCommand::checkOnly('login_protection'));
            case 'failure':
                $limiter = $this->limiter();

                return fn(): mixed => $limiter->limit($this->context('fail-' . $i), RateLimitCommand::recordFailure('login_protection'));
            case 'admission-reserve':
                $runtime = $this->admission();

                return fn(): mixed => $runtime->limit($this->context('adm-' . $i), RateLimitCommand::checkOnly('login_protection'));
            case 'admission-outcome':
                $runtime = $this->admission();
                $permit = $runtime->limit($this->context('out-' . $i), RateLimitCommand::checkOnly('login_protection'))->metadata?->permit;
                if ($permit === null) {
                    throw new \RuntimeException('No permit was issued.');
                }

                return fn(): mixed => $runtime->limit($this->context('out-' . $i), new RateLimitCommand('login_protection', 1, false, true, false, $permit));
            case 'recovery':
                $this->raw()->execute(['FLUSHDB']);
                $this->openCircuit();
                $this->limiter(self::NOW + 400)->limit($this->context('step'), RateLimitCommand::checkOnly('login_protection'));
                $limiter = $this->limiter(self::NOW + 400 + 121);

                return fn(): mixed => $limiter->limit($this->context('recover-' . $i), RateLimitCommand::checkOnly('login_protection'));
        }
        throw new \RuntimeException('Unknown workload ' . $workload);
    }
}
