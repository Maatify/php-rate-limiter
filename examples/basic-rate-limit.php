<?php

declare(strict_types=1);

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Contract\CircuitBreakerStoreInterface;
use Maatify\RateLimiter\Contract\CorrelationStoreInterface;
use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\Contract\RateLimitStoreInterface;
use Maatify\RateLimiter\DTO\FailureSignalDTO;
use Maatify\RateLimiter\DTO\Store\BlockStateDTO;
use Maatify\RateLimiter\DTO\Store\BudgetStateDTO;
use Maatify\RateLimiter\DTO\Store\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\Store\RateLimitStateDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Device\DeviceIdentityResolver;
use Maatify\RateLimiter\Device\FingerprintHasher;
use Maatify\RateLimiter\Engine\CircuitBreaker;
use Maatify\RateLimiter\Engine\EvaluationPipeline;
use Maatify\RateLimiter\Engine\FailureModeResolver;
use Maatify\RateLimiter\Engine\RateLimiterEngine;
use Maatify\RateLimiter\Penalty\AntiEquilibriumGate;
use Maatify\RateLimiter\Penalty\BudgetTracker;
use Maatify\RateLimiter\Penalty\DecayCalculator;
use Maatify\RateLimiter\Policy\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Policy\LoginProtectionPolicy;
use Maatify\RateLimiter\Policy\OtpProtectionPolicy;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\SharedCommon\Infrastructure\SystemClock;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * This example supplies small in-memory adapters for the package contracts.
 * Replace these adapters with the host application's atomic storage and
 * observability integrations in production.
 */
final class ExampleRateLimitStore implements RateLimitStoreInterface
{
    /** @var array<string, array{value: int, updatedAt: int, expiresAt: int}> */
    private array $counters = [];

    /** @var array<string, array{level: int, expiresAt: int}> */
    private array $blocks = [];

    /** @var array<string, array{count: int, epochStart: int, epochDuration: int}> */
    private array $budgets = [];

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function increment(string $key, int $ttlSeconds, int $amount = 1): int
    {
        $now = $this->clock->now()->getTimestamp();
        $current = $this->counters[$key] ?? null;

        if ($current === null || $current['expiresAt'] <= $now) {
            $this->counters[$key] = [
                'value' => $amount,
                'updatedAt' => $now,
                'expiresAt' => $now + $ttlSeconds,
            ];

            return $amount;
        }

        $current['value'] += $amount;
        $current['updatedAt'] = $now;
        $this->counters[$key] = $current;

        return $current['value'];
    }

    public function get(string $key): ?RateLimitStateDTO
    {
        $now = $this->clock->now()->getTimestamp();
        $current = $this->counters[$key] ?? null;

        if ($current === null || $current['expiresAt'] <= $now) {
            return null;
        }

        return new RateLimitStateDTO($current['value'], $current['updatedAt']);
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->counters[$key] = [
            'value' => $value,
            'updatedAt' => $now,
            'expiresAt' => $now + $ttlSeconds,
        ];
    }

    public function block(string $key, int $level, int $durationSeconds): void
    {
        $this->blocks[$key] = [
            'level' => $level,
            'expiresAt' => $this->clock->now()->getTimestamp() + $durationSeconds,
        ];
    }

    public function checkBlock(string $key): ?BlockStateDTO
    {
        $current = $this->blocks[$key] ?? null;
        $now = $this->clock->now()->getTimestamp();

        if ($current === null || $current['expiresAt'] <= $now) {
            return null;
        }

        return new BlockStateDTO($current['level'], $current['expiresAt']);
    }

    public function getBudget(string $key): ?BudgetStateDTO
    {
        $current = $this->budgets[$key] ?? null;
        $now = $this->clock->now()->getTimestamp();

        if ($current === null || $now >= $current['epochStart'] + $current['epochDuration']) {
            return null;
        }

        return new BudgetStateDTO($current['count'], $current['epochStart']);
    }

    public function incrementBudget(string $key, int $epochDurationSeconds, int $amount = 1): BudgetStateDTO
    {
        $now = $this->clock->now()->getTimestamp();
        $current = $this->budgets[$key] ?? null;

        if ($current === null || $now >= $current['epochStart'] + $current['epochDuration']) {
            $current = [
                'count' => $amount,
                'epochStart' => $now,
                'epochDuration' => $epochDurationSeconds,
            ];
        } else {
            $current['count'] += $amount;
        }

        $this->budgets[$key] = $current;

        return new BudgetStateDTO($current['count'], $current['epochStart']);
    }

    public function isHealthy(): bool
    {
        return true;
    }
}

final class ExampleCorrelationStore implements CorrelationStoreInterface
{
    /** @var array<string, array{items: array<string, true>, expiresAt: int}> */
    private array $sets = [];

    /** @var array<string, array{count: int, expiresAt: int}> */
    private array $flags = [];

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        $now = $this->clock->now()->getTimestamp();
        $current = $this->sets[$key] ?? null;

        if ($current === null || $current['expiresAt'] <= $now) {
            $current = ['items' => [], 'expiresAt' => $now + $ttlSeconds];
        }

        $current['items'][$item] = true;
        $this->sets[$key] = $current;

        return count($current['items']);
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        $now = $this->clock->now()->getTimestamp();
        $current = $this->flags[$key] ?? null;

        if ($current === null || $current['expiresAt'] <= $now) {
            $current = ['count' => 0, 'expiresAt' => $now + $ttlSeconds];
        }

        $current['count']++;
        $this->flags[$key] = $current;

        return $current['count'];
    }

    public function getWatchFlag(string $key): int
    {
        $current = $this->flags[$key] ?? null;
        $now = $this->clock->now()->getTimestamp();

        if ($current === null || $current['expiresAt'] <= $now) {
            return 0;
        }

        return $current['count'];
    }
}

final class ExampleCircuitBreakerStore implements CircuitBreakerStoreInterface
{
    /** @var array<string, CircuitBreakerStateDTO> */
    private array $states = [];

    public function load(string $policyName): ?CircuitBreakerStateDTO
    {
        return $this->states[$policyName] ?? null;
    }

    public function save(string $policyName, CircuitBreakerStateDTO $state): void
    {
        $this->states[$policyName] = $state;
    }
}

final class ExampleFailureSignalEmitter implements FailureSignalEmitterInterface
{
    public function emit(FailureSignalDTO $signal): void
    {
        // A production host would forward this signal to its observability boundary.
    }
}

$clock = new SystemClock(new DateTimeZone('UTC'));
$rateLimitStore = new ExampleRateLimitStore($clock);
$correlationStore = new ExampleCorrelationStore($clock);
$signalEmitter = new ExampleFailureSignalEmitter();

$pipeline = new EvaluationPipeline(
    $rateLimitStore,
    $correlationStore,
    new BudgetTracker($rateLimitStore, $clock),
    new AntiEquilibriumGate($correlationStore),
    new DecayCalculator($clock),
    new Maatify\RateLimiter\Device\EphemeralBucket($correlationStore),
    'example-key-secret',
    'example',
    $clock
);

$limiter = new RateLimiterEngine(
    new DeviceIdentityResolver(new FingerprintHasher('example-fingerprint-secret')),
    $pipeline,
    new CircuitBreaker(new ExampleCircuitBreakerStore(), $signalEmitter, $clock),
    new FailureModeResolver(),
    $signalEmitter,
    $clock,
    [
        new LoginProtectionPolicy(),
        new OtpProtectionPolicy(),
        new ApiHeavyProtectionPolicy(),
    ]
);

$context = new RateLimitContextDTO(
    ip: '203.0.113.10',
    ua: 'Mozilla/5.0 Chrome/123',
    accountId: 'account-123',
    clientFingerprint: ['platform' => 'web'],
    headers: ['Accept' => 'application/json']
);

$results = [
    'pre_check' => $limiter->limit($context, RateLimitCommand::checkOnly('login_protection')),
    'first_failure' => $limiter->limit($context, RateLimitCommand::recordFailure('login_protection')),
    'second_failure' => $limiter->limit($context, RateLimitCommand::recordFailure('login_protection')),
    'success' => $limiter->limit($context, RateLimitCommand::recordSuccess('login_protection')),
];

foreach ($results as $name => $result) {
    echo $name . ': ' . json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
}
