<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Correlation;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

final class CredentialSprayAndTrustedK1Test extends TestCase
{
    private FixedClock $clock;
    private InMemoryRateLimitStore $store;
    private StatefulInMemoryCorrelationStore $correlationStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetState();
    }

    public function testCredentialSprayUsesFiveDistinctSubjectsFromTheSameK1(): void
    {
        $pipeline = $this->createPipeline();
        $policy = new LoginProtectionPolicy();

        for ($index = 1; $index <= 5; $index++) {
            $result = $pipeline->process(
                $policy,
                new RateLimitContextDTO('198.51.100.60', 'Mozilla/5.0 Chrome/123', "account-{$index}"),
                RateLimitCommand::checkOnly('login_protection'),
                $this->device(),
            );

            if ($index < 5) {
                self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
            } else {
                self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
                self::assertSame(2, $result->blockLevel);
            }
        }

        self::assertSame(
            2,
            $this->store->checkBlock($this->key('login_protection', 'k1', '198.51.100.60'))?->level,
        );
    }

    public function testCorrelationIdIsAStableSubjectAcrossAccountsWhileK4RemainsSeparate(): void
    {
        $pipeline = $this->createPipeline();
        $policy = new LoginProtectionPolicy();
        $sharedSubject = 'stable-subject-1';

        foreach (['account-a', 'account-b', 'account-c', 'account-d', 'account-e'] as $accountId) {
            $result = $pipeline->process(
                $policy,
                new RateLimitContextDTO(
                    '198.51.100.61',
                    'Mozilla/5.0 Chrome/123',
                    $accountId,
                    null,
                    null,
                    false,
                    [],
                    false,
                    $sharedSubject,
                ),
                RateLimitCommand::checkOnly('login_protection'),
                $this->device(),
            );

            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        }

        $firstFailure = new RateLimitContextDTO(
            '198.51.100.62',
            'Mozilla/5.0 Chrome/123',
            'k4-account-a',
            null,
            null,
            false,
            [],
            false,
            $sharedSubject,
        );
        $secondFailure = new RateLimitContextDTO(
            '198.51.100.62',
            'Mozilla/5.0 Chrome/123',
            'k4-account-b',
            null,
            null,
            false,
            [],
            false,
            $sharedSubject,
        );

        $pipeline->process($policy, $firstFailure, RateLimitCommand::recordFailure('login_protection'), $this->device());
        $pipeline->process($policy, $secondFailure, RateLimitCommand::recordFailure('login_protection'), $this->device());

        self::assertSame(3, $this->store->get($this->key('login_protection', 'k4', 'k4-account-a'))?->value);
        self::assertSame(3, $this->store->get($this->key('login_protection', 'k4', 'k4-account-b'))?->value);
    }

    public function testNullCorrelationIdFallsBackToAccountId(): void
    {
        $pipeline = $this->createPipeline();
        $policy = new LoginProtectionPolicy();

        for ($index = 1; $index <= 5; $index++) {
            $result = $pipeline->process(
                $policy,
                new RateLimitContextDTO('198.51.100.63', 'Mozilla/5.0 Chrome/123', "fallback-account-{$index}"),
                RateLimitCommand::checkOnly('login_protection'),
                $this->device(),
            );

            if ($index < 5) {
                self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
            } else {
                self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
            }
        }
    }

    public function testNoAccountAndNoCorrelationSubjectDoesNotObserveSpray(): void
    {
        $pipeline = $this->createPipeline();
        $policy = new LoginProtectionPolicy();

        for ($index = 0; $index < 5; $index++) {
            $result = $pipeline->process(
                $policy,
                new RateLimitContextDTO('198.51.100.64', 'Mozilla/5.0 Chrome/123'),
                RateLimitCommand::checkOnly('login_protection'),
                $this->device(),
            );

            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        }
    }

    public function testOtpUsesTheSameCredentialSprayContract(): void
    {
        $pipeline = $this->createPipeline();
        $policy = new OtpProtectionPolicy();

        for ($index = 1; $index <= 5; $index++) {
            $result = $pipeline->process(
                $policy,
                new RateLimitContextDTO('198.51.100.65', 'Mozilla/5.0 Chrome/123', "otp-account-{$index}"),
                RateLimitCommand::checkOnly('otp_protection'),
                $this->device(),
            );

            if ($index < 5) {
                self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
            } else {
                self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
                self::assertSame(2, $result->blockLevel);
            }
        }
    }

    public function testApiHeavyDoesNotObserveCredentialSpray(): void
    {
        $pipeline = $this->createPipeline();
        $policy = new ApiHeavyProtectionPolicy();

        for ($index = 1; $index <= 5; $index++) {
            $result = $pipeline->process(
                $policy,
                new RateLimitContextDTO('198.51.100.66', 'Mozilla/5.0 Chrome/123', "api-account-{$index}"),
                new RateLimitCommand('api_heavy_protection'),
                $this->device(),
            );

            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        }

        self::assertNull($this->store->checkBlock($this->key('api_heavy_protection', 'k1', '198.51.100.66')));
    }

    public function testCheckOnlyThenRecordFailureDoesNotObserveSprayTwice(): void
    {
        $pipeline = $this->createPipeline();
        $policy = new LoginProtectionPolicy();

        for ($index = 1; $index <= 3; $index++) {
            self::assertSame(
                RateLimitResultDTO::DECISION_ALLOW,
                $pipeline->process(
                    $policy,
                    new RateLimitContextDTO('198.51.100.67', 'Mozilla/5.0 Chrome/123', "lifecycle-{$index}"),
                    RateLimitCommand::checkOnly('login_protection'),
                    $this->device(),
                )->decision,
            );
        }

        self::assertSame(
            RateLimitResultDTO::DECISION_ALLOW,
            $pipeline->process(
                $policy,
                new RateLimitContextDTO('198.51.100.67', 'Mozilla/5.0 Chrome/123', 'lifecycle-4'),
                RateLimitCommand::recordFailure('login_protection'),
                $this->device(),
            )->decision,
        );

        self::assertSame(
            RateLimitResultDTO::DECISION_ALLOW,
            $pipeline->process(
                $policy,
                new RateLimitContextDTO('198.51.100.67', 'Mozilla/5.0 Chrome/123', 'lifecycle-5'),
                RateLimitCommand::checkOnly('login_protection'),
                $this->device(),
            )->decision,
        );
    }

    public function testRecordFailureAloneDoesNotObserveCredentialSpray(): void
    {
        $pipeline = $this->createPipeline();
        $policy = new LoginProtectionPolicy();

        for ($index = 1; $index <= 5; $index++) {
            $result = $pipeline->process(
                $policy,
                new RateLimitContextDTO('198.51.100.68', 'Mozilla/5.0 Chrome/123', "failure-{$index}"),
                RateLimitCommand::recordFailure('login_protection'),
                $this->device(),
            );

            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        }
    }

    public function testRecordSuccessAloneDoesNotObserveCredentialSpray(): void
    {
        $pipeline = $this->createPipeline();
        $policy = new LoginProtectionPolicy();

        for ($index = 1; $index <= 5; $index++) {
            $result = $pipeline->process(
                $policy,
                new RateLimitContextDTO('198.51.100.69', 'Mozilla/5.0 Chrome/123', "success-{$index}"),
                RateLimitCommand::recordSuccess('login_protection'),
                $this->device(),
            );

            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        }
    }

    public function testSprayNMinusOneWatchEscalatesOnTheSecondQualifyingPrecheck(): void
    {
        $pipeline = $this->createPipeline();
        $policy = new LoginProtectionPolicy();

        for ($index = 1; $index <= 4; $index++) {
            $result = $pipeline->process(
                $policy,
                new RateLimitContextDTO('198.51.100.70', 'Mozilla/5.0 Chrome/123', "watch-{$index}"),
                RateLimitCommand::checkOnly('login_protection'),
                $this->device(),
            );

            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        }

        $result = $pipeline->process(
            $policy,
            new RateLimitContextDTO('198.51.100.70', 'Mozilla/5.0 Chrome/123', 'watch-4'),
            RateLimitCommand::checkOnly('login_protection'),
            $this->device(),
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(2, $result->blockLevel);
    }

    public function testTrustedFifthSpraySubjectStoresK1BlockButIsNotRejectedByK1(): void
    {
        $pipeline = $this->createPipeline();
        $policy = new LoginProtectionPolicy();

        for ($index = 1; $index <= 4; $index++) {
            $pipeline->process(
                $policy,
                new RateLimitContextDTO('198.51.100.71', 'Mozilla/5.0 Chrome/123', "trusted-{$index}"),
                RateLimitCommand::checkOnly('login_protection'),
                $this->device(),
            );
        }

        $trusted = new RateLimitContextDTO('198.51.100.71', 'Mozilla/5.0 Chrome/123', 'trusted-5');
        $trustedResult = $pipeline->process(
            $policy,
            $trusted,
            RateLimitCommand::checkOnly('login_protection'),
            $this->device('trusted-fp', true, 'HIGH'),
        );

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $trustedResult->decision);
        self::assertSame(2, $this->store->checkBlock($this->key('login_protection', 'k1', '198.51.100.71'))?->level);

        $untrustedResult = $pipeline->process(
            $policy,
            new RateLimitContextDTO('198.51.100.71', 'Mozilla/5.0 Chrome/123', 'trusted-6'),
            RateLimitCommand::checkOnly('login_protection'),
            $this->device(),
        );
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $untrustedResult->decision);

        $trustedFollowUp = $pipeline->process(
            $policy,
            new RateLimitContextDTO('198.51.100.71', 'Mozilla/5.0 Chrome/123', 'trusted-7'),
            RateLimitCommand::checkOnly('login_protection'),
            $this->device('trusted-fp', true, 'HIGH'),
        );
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $trustedFollowUp->decision);
    }

    public function testTrustedSessionBypassesActiveIpv6K1HierarchyOnly(): void
    {
        $ip = '2001:db8:1234:5678::10';
        $policy = new LoginProtectionPolicy();

        foreach (['k1', 'k1_48', 'k1_40', 'k1_32'] as $keyType) {
            $this->resetState();
            $pipeline = $this->createPipeline();
            $context = new RateLimitContextDTO($ip, 'Mozilla/5.0 Chrome/123', "ipv6-{$keyType}");
            $this->store->block($this->keyForContext('login_protection', $keyType, $context, $this->device()), 2, 600);

            $trustedResult = $pipeline->process($policy, $context, RateLimitCommand::checkOnly('login_protection'), $this->device('trusted-ipv6', true, 'HIGH'));
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $trustedResult->decision, $keyType);

            $untrustedResult = $pipeline->process(
                $policy,
                new RateLimitContextDTO($ip, 'Mozilla/5.0 Chrome/123', "ipv6-untrusted-{$keyType}"),
                RateLimitCommand::checkOnly('login_protection'),
                $this->device(),
            );
            self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $untrustedResult->decision, $keyType);
        }
    }

    public function testTrustedK1ScoreUpdateDoesNotCreateRejectingK4Block(): void
    {
        $policy = new class extends LoginProtectionPolicy {
            public function getScoreThresholds(): PolicyThresholdsDTO
            {
                return new PolicyThresholdsDTO(
                    k1: new ScoreThresholdsDTO(5, 8, 12),
                    k4: new ScoreThresholdsDTO(50, 60, 70),
                );
            }
        };
        $pipeline = $this->createPipeline();
        $context = new RateLimitContextDTO('198.51.100.72', 'Mozilla/5.0 Chrome/123', 'trusted-score-account');
        $device = $this->device('trusted-score-fp', true, 'HIGH');

        $result = $pipeline->process($policy, $context, RateLimitCommand::recordFailure('login_protection'), $device);

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertSame(5, $this->store->get($this->keyForContext('login_protection', 'k1', $context, $device))?->value);
        self::assertNull($this->store->get($this->keyForContext('login_protection', 'k4', $context, $device)));
        self::assertNull($this->store->checkBlock($this->keyForContext('login_protection', 'k4', $context, $device)));
    }

    /**
     * @dataProvider authoritativeTrustedKeyProvider
     */
    public function testTrustedSessionStillRejectsAuthoritativeK2ThroughK5Blocks(string $keyType): void
    {
        $pipeline = $this->createPipeline();
        $context = new RateLimitContextDTO('198.51.100.73', 'Mozilla/5.0 Chrome/123', 'authoritative-account');
        $device = $this->device('authoritative-fp', true, 'HIGH');
        $this->store->block($this->keyForContext('login_protection', $keyType, $context, $device), 2, 600);

        $result = $pipeline->process(new LoginProtectionPolicy(), $context, RateLimitCommand::checkOnly('login_protection'), $device);

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
        self::assertSame(2, $result->blockLevel);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function authoritativeTrustedKeyProvider(): iterable
    {
        foreach (['k2', 'k3', 'k4', 'k5'] as $keyType) {
            yield $keyType => [$keyType];
        }
    }

    public function testCorrelationStoreReceivesOnlyHmacDerivedSprayMembers(): void
    {
        $correlationStore = new RecordingCorrelationStore($this->clock);
        $this->correlationStore = $correlationStore;
        $pipeline = $this->createPipeline();
        $context = new RateLimitContextDTO(
            '198.51.100.74',
            'Mozilla/5.0 Chrome/123',
            'raw-account-id',
            null,
            null,
            false,
            [],
            false,
            'raw-correlation-subject',
        );

        $pipeline->process(new LoginProtectionPolicy(), $context, RateLimitCommand::checkOnly('login_protection'), $this->device());

        self::assertNotContains('raw-correlation-subject', $correlationStore->members);
        self::assertContains(
            hash_hmac('sha256', 'credential_spray:subject:v1:raw-correlation-subject', 'test_secret'),
            $correlationStore->members,
        );
    }

    private function resetState(): void
    {
        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->store = new InMemoryRateLimitStore($this->clock);
        $this->correlationStore = new StatefulInMemoryCorrelationStore($this->clock);
    }

    private function createPipeline(): EvaluationPipeline
    {
        return new EvaluationPipeline(
            $this->store,
            $this->correlationStore,
            new BudgetTracker($this->store, $this->clock),
            new AntiEquilibriumGate($this->correlationStore),
            new DecayCalculator($this->clock),
            new EphemeralBucket($this->correlationStore),
            'test_secret',
            'prod',
            $this->clock,
        );
    }

    private function device(string $fingerprint = 'stable-fp', bool $trusted = false, string $confidence = 'MEDIUM'): DeviceIdentityDTO
    {
        return new DeviceIdentityDTO($fingerprint, $confidence, $trusted, false, 'chrome/123');
    }

    private function key(string $policyName, string $keyType, string $scope): string
    {
        return hash_hmac('sha256', "{$policyName}:rate_limiter:{$this->keyNamespace($keyType)}:v2:prod:{$scope}", 'test_secret');
    }

    private function keyForContext(string $policyName, string $keyType, RateLimitContextDTO $context, DeviceIdentityDTO $device): string
    {
        $prefix = $this->ipPrefix($context->ip, $this->keyCidr($keyType));
        $scope = match ($keyType) {
            'k1', 'k1_48', 'k1_40', 'k1_32' => $prefix,
            'k2' => "{$prefix}:{$device->normalizedUa}",
            'k3' => "{$prefix}:{$device->fingerprintHash}",
            'k4' => (string) $context->accountId,
            'k5' => "{$context->accountId}:{$device->fingerprintHash}",
            default => throw new \InvalidArgumentException("Unknown key type {$keyType}"),
        };

        return $this->key($policyName, $keyType, $scope);
    }

    private function keyNamespace(string $keyType): string
    {
        return str_starts_with($keyType, 'k1_') ? 'k1' : $keyType;
    }

    private function keyCidr(string $keyType): int
    {
        return match ($keyType) {
            'k1_48' => 48,
            'k1_40' => 40,
            'k1_32' => 32,
            default => 64,
        };
    }

    private function ipPrefix(string $ip, int $cidr): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            self::assertNotFalse($packed);

            return substr(bin2hex($packed), 0, (int) ceil($cidr / 4));
        }

        return $ip;
    }
}

final class RecordingCorrelationStore extends StatefulInMemoryCorrelationStore
{
    /** @var list<string> */
    public array $members = [];

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        $this->members[] = $item;

        return parent::addDistinct($key, $item, $ttlSeconds);
    }
}
