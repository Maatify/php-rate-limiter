<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Correlation;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\CorrelationStoreInterface;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\NullCorrelationStore;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DistributedAccountAttackTest extends TestCase
{
    private const SECRET = 'test_secret';

    private FixedClock $clock;
    private InMemoryRateLimitStore $store;
    private StatefulInMemoryCorrelationStore $correlationStore;

    protected function setUp(): void
    {
        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->store = new InMemoryRateLimitStore($this->clock);
        $this->correlationStore = new StatefulInMemoryCorrelationStore($this->clock);
    }

    public function testLoginDirectFourthDeviceBlocksAllInvolvedK5sWithoutBlockingK4(): void
    {
        $pipeline = $this->pipeline(self::SECRET);
        $policy = new LoginProtectionPolicy();
        $results = [];

        for ($index = 1; $index <= 4; $index++) {
            $results[] = $pipeline->process(
                $policy,
                $this->context('distributed-login', $index),
                RateLimitCommand::checkOnly($policy->getName()),
                $this->device($index),
            );
        }

        self::assertSame(
            [RateLimitResultDTO::DECISION_ALLOW, RateLimitResultDTO::DECISION_ALLOW, RateLimitResultDTO::DECISION_ALLOW, RateLimitResultDTO::DECISION_HARD_BLOCK],
            array_map(static fn(RateLimitResultDTO $result): string => $result->decision, $results),
        );
        self::assertSame(2, $results[3]->blockLevel);
        self::assertNull($this->store->checkBlock($this->key('login_protection', 'k4', 'distributed-login')));

        foreach (range(1, 4) as $index) {
            self::assertSame(
                2,
                $this->store->checkBlock($this->key('login_protection', 'k5', "distributed-login:fp-{$index}"))?->level,
            );
        }

        $scope = $this->distributedDeviceScope('login_protection', 'distributed-login', self::SECRET);
        self::assertSame(4, $this->correlationStore->distinctCount($scope));
        self::assertSame(
            [$this->key('login_protection', 'k5', 'distributed-login:fp-1'), $this->key('login_protection', 'k5', 'distributed-login:fp-2'), $this->key('login_protection', 'k5', 'distributed-login:fp-3'), $this->key('login_protection', 'k5', 'distributed-login:fp-4')],
            $this->correlationStore->distinctItems($scope),
        );
    }

    public function testThreeDevicesSetOneWatchAndSecondQualifyingObservationReachesHardL2(): void
    {
        $pipeline = $this->pipeline(self::SECRET);
        $policy = new LoginProtectionPolicy();

        for ($index = 1; $index <= 3; $index++) {
            $result = $pipeline->process(
                $policy,
                $this->context('watch-account', $index),
                RateLimitCommand::checkOnly($policy->getName()),
                $this->device($index),
            );
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        }

        $watch = $this->distributedWatch('login_protection', 'watch-account', self::SECRET);
        self::assertSame(1, $this->correlationStore->watchValue($watch));

        $result = $pipeline->process(
            $policy,
            $this->context('watch-account', 3),
            RateLimitCommand::checkOnly($policy->getName()),
            $this->device(3),
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(2, $result->blockLevel);
        self::assertSame(2, $this->correlationStore->watchValue($watch));
    }

    public function testTheSameDistributedWindowCreatesExactlyOneOccurrenceAndFifthMemberDoesNotGrowIt(): void
    {
        $pipeline = $this->pipeline(self::SECRET);
        $policy = new LoginProtectionPolicy();
        for ($index = 1; $index <= 4; $index++) {
            $pipeline->process(
                $policy,
                $this->context('one-occurrence', $index),
                RateLimitCommand::checkOnly($policy->getName()),
                $this->device($index),
            );
        }

        $occurrenceScope = $this->occurrenceScope('login_protection', 'one-occurrence', self::SECRET);
        self::assertSame(1, $this->correlationStore->distinctCount($occurrenceScope));

        $fifth = $pipeline->process(
            $policy,
            $this->context('one-occurrence', 5),
            RateLimitCommand::checkOnly($policy->getName()),
            $this->device(5),
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $fifth->decision);
        self::assertSame(4, $this->correlationStore->distinctCount($this->distributedDeviceScope('login_protection', 'one-occurrence', self::SECRET)));
        self::assertSame(1, $this->correlationStore->distinctCount($occurrenceScope));
        self::assertNull($this->store->checkBlock($this->key('login_protection', 'k5', 'one-occurrence:fp-5')));
    }

    public function testThirdSeparateWindowHardBlocksK4AtL4AndRetainsK5L2(): void
    {
        $pipeline = $this->pipeline(self::SECRET);
        $policy = new OtpProtectionPolicy();
        $account = 'three-occurrences';

        for ($window = 1; $window <= 3; $window++) {
            for ($index = 1; $index <= 4; $index++) {
                $pipeline->process(
                    $policy,
                    $this->context($account, ($window * 10) + $index),
                    RateLimitCommand::checkOnly($policy->getName()),
                    $this->device(($window * 10) + $index),
                );
            }

            if ($window < 3) {
                $this->clock->setNow($this->clock->now()->modify('+601 seconds'));
            }
        }

        $k4 = $this->key($policy->getName(), 'k4', $account);
        $accountBlock = $this->store->checkBlock($k4);
        self::assertNotNull($accountBlock);
        self::assertSame(4, $accountBlock->level);
        self::assertSame(1800, $accountBlock->expiresAt - $this->clock->now()->getTimestamp());
        self::assertSame(
            2,
            $this->store->checkBlock($this->key($policy->getName(), 'k5', "{$account}:fp-31"))?->level,
        );
        self::assertSame(3, $this->correlationStore->distinctCount($this->occurrenceScope($policy->getName(), $account, self::SECRET)));
    }

    #[DataProvider('policies')]
    public function testAuthenticationPoliciesShareTheContractButApiHeavyDoesNotObserveIt(string $policyName): void
    {
        $pipeline = $this->pipeline(self::SECRET);
        $policy = $policyName === 'login_protection' ? new LoginProtectionPolicy() : new OtpProtectionPolicy();
        for ($index = 1; $index <= 4; $index++) {
            $pipeline->process(
                $policy,
                $this->context("{$policyName}-account", $index),
                RateLimitCommand::checkOnly($policyName),
                $this->device($index),
            );
        }

        $scope = $this->distributedDeviceScope($policyName, "{$policyName}-account", self::SECRET);
        self::assertSame(4, $this->correlationStore->distinctCount($scope));

        $api = new \Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy();
        $pipeline->process(
            $api,
            $this->context('api-account', 1),
            RateLimitCommand::checkOnly($api->getName()),
            $this->device(1),
        );
        self::assertSame(0, $this->correlationStore->distinctCount($this->distributedDeviceScope($api->getName(), 'api-account', self::SECRET)));
    }

    /** @return iterable<string, array{string}> */
    public static function policies(): iterable
    {
        yield 'login' => ['login_protection'];
        yield 'otp' => ['otp_protection'];
    }

    public function testRecordFailureDoesNotReobserveDistributedState(): void
    {
        $pipeline = $this->pipeline(self::SECRET);
        $policy = new LoginProtectionPolicy();
        for ($index = 1; $index <= 4; $index++) {
            $pipeline->process(
                $policy,
                $this->context('lifecycle-account', $index),
                RateLimitCommand::checkOnly($policy->getName()),
                $this->device($index),
            );
        }

        $scope = $this->distributedDeviceScope($policy->getName(), 'lifecycle-account', self::SECRET);
        $before = $this->correlationStore->distinctItems($scope);
        $pipeline->process(
            $policy,
            $this->context('lifecycle-account', 5),
            RateLimitCommand::recordFailure($policy->getName()),
            $this->device(5),
        );

        self::assertSame($before, $this->correlationStore->distinctItems($scope));
    }

    public function testDistributedObservationFailsClosedWithoutSnapshotCapability(): void
    {
        $pipeline = $this->pipeline(self::SECRET, null, new NullCorrelationStore());
        $policy = new LoginProtectionPolicy();

        $this->expectException(RateLimiterException::class);

        $pipeline->process(
            $policy,
            $this->context('missing-snapshot-capability', 1),
            RateLimitCommand::checkOnly($policy->getName()),
            $this->device(1),
        );
    }

    #[DataProvider('rotationShapes')]
    public function testDistributedMembershipKeepsLogicalContinuityAcrossRotation(string $shape): void
    {
        $policy = new LoginProtectionPolicy();
        $account = "rotation-{$shape}";
        $oldSecret = 'old_secret';
        $currentSecret = 'new_secret';
        $oldPipeline = $this->pipeline($oldSecret);

        for ($index = 1; $index <= 2; $index++) {
            $oldFingerprint = in_array($shape, ['fingerprint-only', 'both'], true)
                ? "old-fp-{$index}"
                : "fp-{$index}";
            $oldPipeline->process(
                $policy,
                $this->context($account, $index),
                RateLimitCommand::checkOnly($policy->getName()),
                $this->deviceWithFingerprint($oldFingerprint, null, $index),
            );
        }

        $previousScope = $this->distributedDeviceScope($policy->getName(), $account, $oldSecret);
        $previousItems = $this->correlationStore->distinctItems($previousScope);
        $previousExpiry = $this->correlationStore->distinctExpiresAt($previousScope);
        self::assertCount(2, $previousItems);

        $previousOuter = $shape === 'fingerprint-only' ? null : $oldSecret;
        $activeSecret = $shape === 'fingerprint-only' ? $oldSecret : $currentSecret;
        $currentPipeline = $this->pipeline($activeSecret, $previousOuter);
        for ($index = 3; $index <= 4; $index++) {
            $oldFingerprint = in_array($shape, ['fingerprint-only', 'both'], true)
                ? "old-fp-{$index}"
                : "fp-{$index}";
            $currentFingerprint = in_array($shape, ['fingerprint-only', 'both'], true)
                ? "new-fp-{$index}"
                : "fp-{$index}";
            $result = $currentPipeline->process(
                $policy,
                $this->context($account, $index),
                RateLimitCommand::checkOnly($policy->getName()),
                $this->deviceWithFingerprint($currentFingerprint, $oldFingerprint, $index),
            );

            if ($index === 3) {
                self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision, $shape);
            } else {
                self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision, $shape);
            }
        }

        self::assertSame($previousExpiry, $this->correlationStore->distinctExpiresAt($previousScope), $shape);
        if ($shape !== 'fingerprint-only') {
            self::assertSame($previousItems, $this->correlationStore->distinctItems($previousScope), $shape);
        } else {
            foreach ($previousItems as $member) {
                self::assertContains($member, $this->correlationStore->distinctItems($previousScope), $shape);
            }
        }

        foreach ([1, 2] as $index) {
            $oldFingerprint = in_array($shape, ['fingerprint-only', 'both'], true)
                ? "old-fp-{$index}"
                : "fp-{$index}";
            self::assertSame(
                2,
                $this->store->checkBlock($this->key($policy->getName(), 'k5', "{$account}:{$oldFingerprint}", $oldSecret))?->level,
                $shape,
            );
        }

        if ($shape === 'both') {
            self::assertNull($this->store->checkBlock($this->key($policy->getName(), 'k5', "{$account}:old-fp-3", $currentSecret)));
            self::assertNull($this->store->checkBlock($this->key($policy->getName(), 'k5', "{$account}:new-fp-3", $oldSecret)));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function rotationShapes(): iterable
    {
        yield 'outer-only' => ['outer-only'];
        yield 'fingerprint-only' => ['fingerprint-only'];
        yield 'both' => ['both'];
    }

    private function pipeline(
        string $currentSecret,
        ?string $previousSecret = null,
        ?CorrelationStoreInterface $correlationStore = null,
    ): EvaluationPipeline {
        return new EvaluationPipeline(
            $this->store,
            $correlationStore ?? $this->correlationStore,
            new BudgetTracker($this->store, $this->clock),
            new AntiEquilibriumGate($this->correlationStore),
            new DecayCalculator($this->clock),
            new EphemeralBucket($this->correlationStore),
            $currentSecret,
            'prod',
            $this->clock,
            $previousSecret,
        );
    }

    private function context(string $account, int $index): RateLimitContextDTO
    {
        return new RateLimitContextDTO(
            '198.51.100.20',
            "Mozilla/5.0 Chrome/{$index}",
            $account,
            ['device' => "device-{$index}"],
        );
    }

    private function device(int $index, ?string $previousFingerprint = null): DeviceIdentityDTO
    {
        return $this->deviceWithFingerprint("fp-{$index}", $previousFingerprint, $index);
    }

    private function deviceWithFingerprint(string $fingerprint, ?string $previousFingerprint = null, ?int $index = null): DeviceIdentityDTO
    {
        $index ??= 1;

        return new DeviceIdentityDTO($fingerprint, 'MEDIUM', false, false, "chrome/{$index}", false, $previousFingerprint);
    }

    private function key(string $policy, string $type, string $scope, string $secret = self::SECRET): string
    {
        return hash_hmac('sha256', "{$policy}:rate_limiter:{$type}:v2:prod:{$scope}", $secret);
    }

    private function distributedDeviceScope(string $policy, string $account, string $secret): string
    {
        $k4 = $this->key($policy, 'k4', $account, $secret);

        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:correlation:distributed_account_devices:v1:prod:scope:{$k4}",
            $secret,
        );
    }

    private function distributedWatch(string $policy, string $account, string $secret): string
    {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:correlation:distributed_account_watch:v1:prod:{$this->distributedDeviceScope($policy, $account, $secret)}",
            $secret,
        );
    }

    private function occurrenceScope(string $policy, string $account, string $secret): string
    {
        $k4 = $this->key($policy, 'k4', $account, $secret);

        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:correlation:distributed_account_occurrences:v1:prod:scope:{$k4}",
            $secret,
        );
    }
}
