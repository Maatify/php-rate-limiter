<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Exception\OverlappingRequestException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Repository\RateLimitStoreInterface;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\E1AccountScopeKeyDeriver;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\AllowanceOnlyPolicy;
use Maatify\RateLimiter\Tests\Support\Redis\BudgetSeedlessRedisStore;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** F300-01/02: the same tests run unchanged against rejected HEAD 304a5835 and corrected source. */
#[Group('integration')]
#[Group('php32')]
#[Group('g1-review-regression')]
final class G1ReviewRegressionRedisTest extends TestCase
{
    private const NOW = 1_793_793_600;
    private RespRedisCommandExecutor $admin;
    private int $commands = 0;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Requires isolated real Redis.');
        }
        $this->admin = new RespRedisCommandExecutor($host, (int) $port);
        $this->admin->execute(['FLUSHDB']);
    }

    private function store(string $namespace = 'review-300'): RedisFullCapabilityStore
    {
        return new RedisFullCapabilityStore(new CallableRedisCommandExecutor(function (array $command): mixed {
            $this->commands++;
            return $this->admin->execute($command);
        }), $namespace, new FixedClock('@' . self::NOW));
    }

    private function pipeline(RateLimitStoreInterface $rate, RedisFullCapabilityStore $correlation, ?string $previous = null): EvaluationPipeline
    {
        $clock = new FixedClock('@' . self::NOW);
        return new EvaluationPipeline($rate, $correlation, new DecayCalculator($clock), 'current', 'prod', $clock, $clock, $clock, $clock, $previous);
    }

    private function context(?string $account = 'outer'): RateLimitContextDTO
    {
        return new RateLimitContextDTO('192.0.2.77', 'browser', $account);
    }

    private function device(bool $known = false, ?string $fingerprint = 'fp'): DeviceIdentityDTO
    {
        return new DeviceIdentityDTO($fingerprint, 'HIGH', false, false, 'browser', $known);
    }

    /** @return array<string, string> raw Redis values; expiry is checked separately, DUMP does not include server expiry time */
    private function snapshot(): array
    {
        $keys = $this->admin->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        sort($keys);
        $state = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $dump = $this->admin->execute(['DUMP', $key]);
            self::assertIsString($dump);
            $ttl = $this->admin->execute(['PTTL', $key]);
            self::assertIsInt($ttl);
            self::assertGreaterThan(0, $ttl);
            $state[$key] = bin2hex($dump);
        }
        return $state;
    }

    /** @return iterable<string, array{bool, ?string}> */
    public static function overlaps(): iterable
    {
        foreach ([false, true] as $fiber) {
            foreach ([null, 'intruder'] as $account) {
                yield ($fiber ? 'fiber' : 'host') . '-' . ($account ?? 'absent') => [$fiber, $account];
            }
        }
    }

    #[DataProvider('overlaps')]
    public function testClaimRefusesBeforeChangingOuterRoutingAndBothRedisLayouts(bool $fiber, ?string $account): void
    {
        $root = $this->store();
        $pipeline = $this->pipeline($root, $root);
        $policy = new AllowanceOnlyPolicy();
        $command = RateLimitCommand::recordFailure('allowance');
        $control = $pipeline->process($policy, $this->context(), $command, $this->device());
        $expected = $this->snapshot();
        self::assertNotEmpty(array_filter(array_keys($expected), static fn(string $key): bool => str_contains($key, ':e1:')));
        self::assertNotEmpty(array_filter(array_keys($expected), static fn(string $key): bool => str_contains($key, ':v1:')));
        $this->admin->execute(['FLUSHDB']);
        $attempted = false;
        $refusal = null;
        $before = $after = [];
        $policy->onThresholds = function () use (&$attempted, &$refusal, &$before, &$after, $fiber, $account, $pipeline): void {
            if ($attempted) {
                return;
            }
            $attempted = true;
            $registry = new \ReflectionProperty($pipeline, 'accountScopedKeys');
            $before = $registry->getValue($pipeline);
            $claim = function () use (&$refusal, $pipeline, $account): void {
                try {
                    $pipeline->claimPostPunishmentReentry($this->context($account), $this->device(), 'allowance', 'unpublished');
                } catch (\Throwable $failure) {
                    $refusal = $failure::class;
                }
            };
            if ($fiber) {
                \Fiber::suspend();
                // The main execution context runs the competing claim while this request remains suspended.
            } else {
                $beforeCommands = $this->commands;
                $claim();
                self::assertSame($beforeCommands, $this->commands, 'Refused Host claim does not reach the backend.');
            }
            $after = $registry->getValue($pipeline);
        };
        if ($fiber) {
            $outer = new \Fiber(fn() => $pipeline->process($policy, $this->context(), $command, $this->device()));
            $outer->start();
            self::assertTrue($outer->isSuspended());
            $beforeCommands = $this->commands;
            try {
                $pipeline->claimPostPunishmentReentry($this->context($account), $this->device(), 'allowance', 'unpublished');
            } catch (\Throwable $failure) {
                $refusal = $failure::class;
            }
            self::assertSame($beforeCommands, $this->commands, 'Refused Fiber claim does not reach the backend.');
            $outer->resume();
            $result = $outer->getReturn();
        } else {
            $result = $pipeline->process($policy, $this->context(), $command, $this->device());
        }
        // Compare persistence even when the old source silently accepts the absent-account claim.
        self::assertSame($expected, $this->snapshot(), 'An attempted overlap must leave the outer Redis outcome unchanged on both layouts.');
        self::assertEquals($control, $result);
        self::assertNotEmpty($before);
        self::assertSame($before, $after, 'Reject before resetting or deriving any account key.');
        self::assertSame(OverlappingRequestException::class, $refusal);
        $policy->onThresholds = null;
        $followup = $pipeline->process($policy, new RateLimitContextDTO('192.0.2.88', 'another-browser', 'outer'), RateLimitCommand::checkOnly('allowance'), $this->device());
        self::assertSame('SOFT_BLOCK', $followup->decision, 'Account punishment is enforced even on another IP.');
        self::assertFalse($pipeline->claimPostPunishmentReentry($this->context(), $this->device(), 'allowance', 'unpublished'), 'Ownership is released.');
        self::assertSame(false, $root->transactRequest(fn(): bool => $pipeline->claimPostPunishmentReentry($this->context(), $this->device(), 'allowance', 'unpublished')), 'Owner nesting remains allowed.');
    }

    /** @return iterable<string, array{bool}> */
    public static function knownDevices(): iterable
    {
        yield 'verified device' => [false];
        yield 'trusted session' => [true];
    }

    #[DataProvider('knownDevices')]
    public function testAllowanceWithoutBudgetRejectsMissingSeedBeforeAnyBackendAccess(bool $trusted): void
    {
        $rate = $this->store();
        $correlation = $this->store('correlation-300');
        $previous = E1AccountScopeKeyDeriver::key('previous', ['aux.known_device_allowance', 'allowance', 'prod', 'outer']);
        $rate->e1Keyspace()->incrementBudgetAt($previous, 86400, 7, self::NOW);
        $before = $this->snapshot();
        $pipeline = $this->pipeline(new BudgetSeedlessRedisStore($rate), $correlation, 'previous');
        $this->commands = 0;
        $failure = null;
        try {
            $pipeline->process(new AllowanceOnlyPolicy(), $this->context(), RateLimitCommand::recordFailure('allowance'), new DeviceIdentityDTO('fp', 'HIGH', $trusted, false, 'browser', ! $trusted));
        } catch (RateLimiterException $caught) {
            $failure = $caught;
        }
        $accesses = $this->commands;
        $after = $this->snapshot();
        self::assertNotNull($failure);
        self::assertStringContainsString('BudgetSeedStoreInterface', $failure->getMessage());
        self::assertSame(0, $accesses, 'No reads or writes, including correlation, before capability refusal.');
        self::assertSame($before, $after, 'Rejected composition must preserve raw Previous and both stores.');
    }

    public function testBuilderRejectsAllowanceOnlyGraphBeforeBackendAccess(): void
    {
        $root = $this->store();
        $builder = new RateLimiterBuilder(new RateLimiterConfig('current', 'fp-secret', 'prod', 'previous'), new BudgetSeedlessRedisStore($root), $root, $root, new RecordingFailureSignalEmitter());
        foreach (['login_protection', 'otp_protection', 'api_heavy_protection'] as $name) {
            $builder->withPolicy(new AllowanceOnlyPolicy($name));
        }
        $this->commands = 0;
        $failure = null;
        try {
            $builder->build();
        } catch (RateLimiterException $caught) {
            $failure = $caught;
        }
        self::assertSame(0, $this->commands);
        self::assertNotNull($failure, 'Active allowance is independent of getBudgetConfig().');
        self::assertStringContainsString('Authentication policies require BudgetConfig', $failure->getMessage());
    }
    /** @return iterable<string, array{?string, bool, ?string, ?string, ?int, int, bool}> */
    public static function inapplicableAllowance(): iterable
    {
        yield 'no previous' => [null, true, 'outer', 'fp', 8, 2, false];
        yield 'unknown device' => ['previous', false, 'outer', 'fp', 8, 2, false];
        yield 'no account' => ['previous', true, null, 'fp', 8, 2, false];
        yield 'no fingerprint' => ['previous', true, 'outer', null, 8, 2, false];
        yield 'disabled allowance' => ['previous', true, 'outer', 'fp', null, 2, false];
        yield 'no K5 delta' => ['previous', true, 'outer', 'fp', 8, 0, false];
        yield 'precheck' => ['previous', true, 'outer', 'fp', 8, 2, true];
    }

    #[DataProvider('inapplicableAllowance')]
    public function testSeedCapabilityIsNotRequiredForUnreachableAllowanceMigration(?string $previous, bool $known, ?string $account, ?string $fingerprint, ?int $limit, int $k5, bool $precheck): void
    {
        $rate = new BudgetSeedlessRedisStore($this->store());
        $pipeline = $this->pipeline($rate, $this->store('separate-correlation'), $previous);
        $policy = new AllowanceOnlyPolicy(limit: $limit, k5: $k5);
        $result = $pipeline->process($policy, $this->context($account), $precheck ? RateLimitCommand::checkOnly('allowance') : RateLimitCommand::recordFailure('allowance'), $this->device($known, $fingerprint));
        self::assertNotSame('', $result->decision);
        self::assertGreaterThan(0, $this->commands, 'The applicable sequential path actually ran.');
    }

    public function testSupportedAllowanceMigratesPreviousAndMemoizesGuardedPolicyGetter(): void
    {
        $root = $this->store();
        $view = $root->e1Keyspace();
        $previous = E1AccountScopeKeyDeriver::key('previous', ['aux.known_device_allowance', 'allowance', 'prod', 'outer']);
        $current = E1AccountScopeKeyDeriver::key('current', ['aux.known_device_allowance', 'allowance', 'prod', 'outer']);
        $view->incrementBudgetAt($previous, 86400, 7, self::NOW);
        $oldState = $view->getBudgetAt($previous, self::NOW);
        $pipeline = $this->pipeline($root, $this->store('separate-correlation'), 'previous');
        $policy = new AllowanceOnlyPolicy();
        $refusal = null;
        $policy->onAllowance = function () use ($pipeline, &$refusal): void {
            try {
                $pipeline->claimPostPunishmentReentry($this->context(null), $this->device(), 'allowance', 'unpublished');
            } catch (\Throwable $failure) {
                $refusal = $failure::class;
            }
        };
        $pipeline->process($policy, $this->context(), RateLimitCommand::recordFailure('allowance'), $this->device(true));
        self::assertSame(OverlappingRequestException::class, $refusal);
        self::assertSame(1, $policy->allowanceReads, 'Preflight and execution use one Host value.');
        self::assertSame(8, $view->getBudgetAt($current, self::NOW)?->count);
        self::assertEquals($oldState, $view->getBudgetAt($previous, self::NOW), 'Previous stays read-only.');
        self::assertNull($root->getBudgetAt($current, self::NOW), 'Allowance remains in the E1 layout.');
    }

    public function testFingerprintOnlyRotationDoesNotRequireAccountAllowanceSeed(): void
    {
        $root = $this->store();
        $pipeline = $this->pipeline(new BudgetSeedlessRedisStore($root), $this->store('separate-correlation'));
        $policy = new AllowanceOnlyPolicy();
        $pipeline->process($policy, $this->context(), RateLimitCommand::recordFailure('allowance'), new DeviceIdentityDTO('fp', 'HIGH', true, false, 'browser', false, 'previous-fp'));
        $current = E1AccountScopeKeyDeriver::key('current', ['aux.known_device_allowance', 'allowance', 'prod', 'outer']);
        self::assertSame(1, $root->e1Keyspace()->getBudgetAt($current, self::NOW)?->count);
    }

    public function testHealthEntryGuardsCustomStoreBeforeCallingItsBackend(): void
    {
        $pipeline = $this->pipeline(new BudgetSeedlessRedisStore($this->store()), $this->store('separate-correlation'));
        $policy = new AllowanceOnlyPolicy();
        $attempted = false;
        $refusal = null;
        $policy->onThresholds = function () use ($pipeline, &$attempted, &$refusal): void {
            if ($attempted) {
                return;
            }
            $attempted = true;
            $before = $this->commands;
            try {
                $pipeline->isBackendHealthy();
            } catch (\Throwable $caught) {
                $refusal = $caught::class;
            }
            self::assertSame($before, $this->commands, 'The custom store must not receive the health call during another request.');
        };
        $pipeline->process($policy, $this->context(), RateLimitCommand::recordFailure('allowance'), $this->device());
        self::assertSame(OverlappingRequestException::class, $refusal);
        self::assertTrue($pipeline->isBackendHealthy(), 'Ordinary health and scope cleanup remain supported.');
    }

}
