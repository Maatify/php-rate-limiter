<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Correlation;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BoundedCorrelationCoverageTest extends TestCase
{
    private const SECRET = 'test_secret';

    private FixedClock $clock;
    private InMemoryRateLimitStore $store;
    private StatefulInMemoryCorrelationStore $correlationStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetState();
    }

    public function testChurnFirstAndSecondDistinctObservationsOnlyReachFirstWatch(): void
    {
        $pipeline = $this->pipeline();
        $policy = new OtpProtectionPolicy();
        $context = $this->context('203.0.113.10', 'Mozilla/5.0');

        $first = $pipeline->process($policy, $context, $this->check($policy), $this->device('churn-first'));
        $second = $pipeline->process($policy, $context, $this->check($policy), $this->device('churn-second'));

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $first->decision);
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $second->decision);
        self::assertSame(2, $this->correlationStore->distinctCount($this->churnScope('203.0.113.10', 'Mozilla/5.0')));
        self::assertSame(1, $this->correlationStore->watchValue($this->churnWatch('203.0.113.10', 'Mozilla/5.0')));
        self::assertNull($this->store->checkBlock($this->key($policy->getName(), 'k2', '203.0.113.10:Mozilla/5.0')));
    }

    public function testChurnSecondQualifyingObservationHardBlocksAtTwoAndDoesNotGrowPastTwo(): void
    {
        $pipeline = $this->pipeline();
        $policy = new OtpProtectionPolicy();
        $context = $this->context('203.0.113.11', 'Mozilla/5.0');

        $pipeline->process($policy, $context, $this->check($policy), $this->device('churn-first'));
        $pipeline->process($policy, $context, $this->check($policy), $this->device('churn-second'));
        $qualifying = $pipeline->process($policy, $context, $this->check($policy), $this->device('churn-second'));

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $qualifying->decision);
        self::assertSame(2, $qualifying->blockLevel);
        self::assertSame(2, $this->correlationStore->distinctCount($this->churnScope('203.0.113.11', 'Mozilla/5.0')));
        self::assertSame(2, $this->correlationStore->watchValue($this->churnWatch('203.0.113.11', 'Mozilla/5.0')));
        self::assertSame(
            2,
            $this->store->checkBlock($this->key($policy->getName(), 'k2', '203.0.113.11:Mozilla/5.0'))?->level,
        );
    }

    public function testChurnThirdDistinctHardBlocksAndTheSetCannotExceedThree(): void
    {
        $pipeline = $this->pipeline();
        $policy = new OtpProtectionPolicy();
        $ip = '203.0.113.12';
        $ua = 'Mozilla/5.0';
        $context = $this->context($ip, $ua);

        $first = $pipeline->process($policy, $context, $this->check($policy), $this->device('churn-first'));
        $second = $pipeline->process($policy, $context, $this->check($policy), $this->device('churn-second'));
        $third = $pipeline->process($policy, $context, $this->check($policy), $this->device('churn-third'));
        $fourth = $pipeline->process($policy, $context, $this->check($policy), $this->device('churn-fourth'));

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $first->decision);
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $second->decision);
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $third->decision);
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $fourth->decision);
        self::assertSame(3, $this->correlationStore->distinctCount($this->churnScope($ip, $ua)));
        self::assertSame(2, $third->blockLevel);
    }

    #[DataProvider('rotationShapes')]
    public function testChurnThirdDistinctDecisionAndLogicalCountContinueAcrossRotation(string $shape): void
    {
        $config = $this->rotationConfig($shape, 'churn-third');
        $policy = new OtpProtectionPolicy();
        $ip = '203.0.113.20';
        $ua = 'Mozilla/5.0';
        $oldPipeline = $this->pipeline($config['oldSecret']);
        $oldContext = $this->context($ip, $ua);

        $oldPipeline->process($policy, $oldContext, $this->check($policy), $this->device('churn-first'));
        $oldPipeline->process($policy, $oldContext, $this->check($policy), $this->device('churn-second'));

        $previousScope = $this->churnScope($ip, $ua, $config['oldSecret']);
        $previousItems = $this->correlationStore->distinctItems($previousScope);
        $previousExpiry = $this->correlationStore->distinctExpiresAt($previousScope);
        $previousWatch = $this->churnWatch($ip, $ua, $config['oldSecret']);
        $previousWatchValue = $this->correlationStore->watchValue($previousWatch);
        $previousWatchExpiry = $this->correlationStore->watchExpiresAt($previousWatch);

        $currentPipeline = $this->pipeline($config['currentSecret'], $config['previousSecret']);
        $result = $currentPipeline->process(
            $policy,
            $this->context($ip, $ua),
            $this->check($policy),
            $this->device($config['currentFingerprint'], 'LOW', $config['previousFingerprint']),
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision, $shape);
        self::assertSame(2, $result->blockLevel, $shape);
        self::assertSame(
            2,
            $this->store->checkBlock($this->key($policy->getName(), 'k2', "{$ip}:{$ua}", $config['currentSecret']))?->level,
            $shape,
        );
        self::assertSame($previousExpiry, $this->correlationStore->distinctExpiresAt($previousScope), $shape);
        self::assertSame($previousWatchValue, $this->correlationStore->watchValue($previousWatch), $shape);
        self::assertSame($previousWatchExpiry, $this->correlationStore->watchExpiresAt($previousWatch), $shape);

        $currentScope = $this->churnScope($ip, $ua, $config['currentSecret']);
        $currentItems = $this->correlationStore->distinctItems($currentScope);
        self::assertSame(3, $this->logicalChurnCount($shape, $previousScope, $currentScope, $config['currentSecret'], $ip, $ua));
        if ($shape === 'fingerprint-only') {
            foreach ($previousItems as $previousItem) {
                self::assertContains($previousItem, $currentItems, $shape);
            }
        } else {
            self::assertSame($previousItems, $this->correlationStore->distinctItems($previousScope), $shape);
        }
    }

    #[DataProvider('rotationShapes')]
    public function testChurnSecondQualifyingWatchContinuesAcrossRotation(string $shape): void
    {
        $config = $this->rotationConfig($shape, 'churn-second');
        $policy = new OtpProtectionPolicy();
        $ip = '203.0.113.21';
        $ua = 'Mozilla/5.0';
        $oldPipeline = $this->pipeline($config['oldSecret']);
        $context = $this->context($ip, $ua);

        $oldPipeline->process($policy, $context, $this->check($policy), $this->device('churn-first'));
        $oldPipeline->process($policy, $context, $this->check($policy), $this->device('churn-second'));

        $previousScope = $this->churnScope($ip, $ua, $config['oldSecret']);
        $previousItems = $this->correlationStore->distinctItems($previousScope);
        $previousExpiry = $this->correlationStore->distinctExpiresAt($previousScope);
        $previousWatch = $this->churnWatch($ip, $ua, $config['oldSecret']);
        $previousWatchValue = $this->correlationStore->watchValue($previousWatch);
        $previousWatchExpiry = $this->correlationStore->watchExpiresAt($previousWatch);

        $result = $this->pipeline($config['currentSecret'], $config['previousSecret'])->process(
            $policy,
            $context,
            $this->check($policy),
            $this->device($config['currentFingerprint'], 'LOW', $config['previousFingerprint']),
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision, $shape);
        self::assertSame(2, $result->blockLevel, $shape);
        self::assertSame($previousExpiry, $this->correlationStore->distinctExpiresAt($previousScope), $shape);
        self::assertSame(
            $shape === 'fingerprint-only' ? 2 : $previousWatchValue,
            $this->correlationStore->watchValue($previousWatch),
            $shape,
        );
        self::assertSame($previousWatchExpiry, $this->correlationStore->watchExpiresAt($previousWatch), $shape);
        self::assertSame(
            2,
            $this->logicalChurnCount(
                $shape,
                $previousScope,
                $this->churnScope($ip, $ua, $config['currentSecret']),
                $config['currentSecret'],
                $ip,
                $ua,
            ),
        );
        foreach ($previousItems as $previousItem) {
            self::assertContains($previousItem, $this->correlationStore->distinctItems($previousScope), $shape);
        }
    }

    public function testDilutionSetIsCappedAndLowConfidenceUsesK2WithoutK3CorrelationBlock(): void
    {
        $pipeline = $this->pipeline();
        $policy = new OtpProtectionPolicy();
        $fingerprint = 'dilution-low-fingerprint';
        $ips = [];

        for ($index = 1; $index <= 7; $index++) {
            $ip = "198.51.100.{$index}";
            $ips[] = $ip;
            $result = $pipeline->process(
                $policy,
                $this->context($ip, 'Mozilla/5.0'),
                $this->check($policy),
                $this->device($fingerprint, 'LOW'),
            );

            if ($index < 6) {
                self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
            } else {
                self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
                self::assertSame(2, $result->blockLevel);
                self::assertSame(
                    2,
                    $this->store->checkBlock($this->key($policy->getName(), 'k2', "{$ip}:Mozilla/5.0"))?->level,
                );
                self::assertNull($this->store->checkBlock($this->key($policy->getName(), 'k3', "{$ip}:{$fingerprint}")));
            }
        }

        $scope = $this->dilutionScope($policy->getName(), $fingerprint);
        $items = $this->correlationStore->distinctItems($scope);
        self::assertSame(6, $this->correlationStore->distinctCount($scope));
        foreach ($ips as $ip) {
            self::assertNotContains($ip, $items);
            self::assertNotContains($ip, $this->correlationStore->distinctKeys());
        }
    }

    #[DataProvider('confidenceLevels')]
    public function testDilutionMediumAndHighRequireTwoConsecutiveWindows(string $confidence): void
    {
        $pipeline = $this->pipeline();
        $policy = new OtpProtectionPolicy();
        $fingerprint = "dilution-{$confidence}-fingerprint";
        $firstWindowId = $this->windowId();

        for ($index = 1; $index <= 6; $index++) {
            $result = $pipeline->process(
                $policy,
                $this->context("198.51.101.{$index}", 'Mozilla/5.0'),
                $this->check($policy),
                $this->device($fingerprint, $confidence),
            );

            if ($index === 6) {
                self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
            }
        }

        $scope = $this->dilutionScope($policy->getName(), $fingerprint);
        $previousConfirmation = $this->confirmationKey($policy->getName(), $scope, $firstWindowId);
        $previousConfirmationExpiry = $this->correlationStore->watchExpiresAt($previousConfirmation);
        self::assertSame(1, $this->correlationStore->watchValue($previousConfirmation));
        self::assertNull($this->store->checkBlock($this->key($policy->getName(), 'k3', "198.51.101.6:{$fingerprint}")));

        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:10:00'));
        $secondWindowId = $this->windowId();
        for ($index = 7; $index <= 12; $index++) {
            $result = $pipeline->process(
                $policy,
                $this->context("198.51.101.{$index}", 'Mozilla/5.0'),
                $this->check($policy),
                $this->device($fingerprint, $confidence),
            );

            if ($index >= 11) {
                self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
                self::assertSame(2, $result->blockLevel);
            }
        }

        self::assertSame($previousConfirmationExpiry, $this->correlationStore->watchExpiresAt($previousConfirmation));
        self::assertSame(1, $this->correlationStore->watchValue($previousConfirmation));
        self::assertSame(
            2,
            $this->correlationStore->watchValue($this->confirmationKey($policy->getName(), $scope, $secondWindowId)),
        );
        self::assertSame(6, $this->correlationStore->distinctCount($scope));
        self::assertSame(
            2,
            $this->store->checkBlock($this->key($policy->getName(), 'k3', "198.51.101.12:{$fingerprint}"))?->level,
        );
    }

    #[DataProvider('rotationShapes')]
    public function testDilutionTwoWindowConfirmationContinuesAcrossRotation(string $shape): void
    {
        $config = $this->rotationConfig($shape, 'dilution-current');
        $policy = new OtpProtectionPolicy();
        $oldFingerprint = $config['previousFingerprint'] ?? $config['currentFingerprint'];
        $oldPipeline = $this->pipeline($config['oldSecret']);
        $oldWindowId = $this->windowId();

        for ($index = 1; $index <= 6; $index++) {
            $result = $oldPipeline->process(
                $policy,
                $this->context("198.51.102.{$index}", 'Mozilla/5.0'),
                $this->check($policy),
                $this->device($oldFingerprint, 'MEDIUM'),
            );

            if ($index === 6) {
                self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision, $shape);
            }
        }

        $previousScope = $this->dilutionScope($policy->getName(), $oldFingerprint, $config['oldSecret']);
        $previousItems = $this->correlationStore->distinctItems($previousScope);
        $previousExpiry = $this->correlationStore->distinctExpiresAt($previousScope);
        $previousConfirmation = $this->confirmationKey($policy->getName(), $previousScope, $oldWindowId, $config['oldSecret']);
        $previousConfirmationExpiry = $this->correlationStore->watchExpiresAt($previousConfirmation);
        self::assertSame(1, $this->correlationStore->watchValue($previousConfirmation));

        $this->clock->setNow(new \DateTimeImmutable('2025-01-01 12:10:00'));
        $currentPipeline = $this->pipeline($config['currentSecret'], $config['previousSecret']);
        $currentWindowId = $this->windowId();
        for ($index = 7; $index <= 12; $index++) {
            $result = $currentPipeline->process(
                $policy,
                $this->context("198.51.102.{$index}", 'Mozilla/5.0'),
                $this->check($policy),
                $this->device($config['currentFingerprint'], 'MEDIUM', $config['previousFingerprint']),
            );

            if ($index >= 11) {
                self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision, $shape);
                self::assertSame(2, $result->blockLevel, $shape);
            }
        }

        $currentScope = $this->dilutionScope($policy->getName(), $config['currentFingerprint'], $config['currentSecret']);
        self::assertSame($previousItems, $this->correlationStore->distinctItems($previousScope), $shape);
        self::assertSame($previousExpiry, $this->correlationStore->distinctExpiresAt($previousScope), $shape);
        self::assertSame($previousConfirmationExpiry, $this->correlationStore->watchExpiresAt($previousConfirmation), $shape);
        self::assertSame(1, $this->correlationStore->watchValue($previousConfirmation), $shape);
        self::assertSame(6, $this->correlationStore->distinctCount($currentScope), $shape);
        self::assertSame(
            2,
            $this->correlationStore->watchValue(
                $this->confirmationKey($policy->getName(), $currentScope, $currentWindowId, $config['currentSecret']),
            ),
            $shape,
        );
        self::assertSame(
            2,
            $this->store->checkBlock(
                $this->key($policy->getName(), 'k3', "198.51.102.12:{$config['currentFingerprint']}", $config['currentSecret']),
            )?->level,
            $shape,
        );
    }

    public function testEphemeralOverflowRetainsChurnButSkipsDilutionStateAndK3(): void
    {
        $pipeline = $this->pipeline();
        $policy = new LoginProtectionPolicy();
        $accountId = 'ephemeral-overflow-account';
        $ip = '203.0.113.90';

        for ($index = 1; $index <= 10; $index++) {
            $ua = $index <= 2 ? 'shared-ua' : "ua-{$index}";
            $result = $pipeline->process(
                $policy,
                $this->context($ip, $ua, $accountId),
                $this->check($policy),
                $this->device("overflow-device-{$index}", 'MEDIUM', null, false, $ua),
            );

            if ($index <= 3) {
                self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
            } else {
                self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $result->decision);
            }
        }

        $overflowFingerprint = 'overflow-device-11';
        $overflow = $pipeline->process(
            $policy,
            $this->context($ip, 'shared-ua', $accountId),
            $this->check($policy),
            $this->device($overflowFingerprint, 'MEDIUM', null, false, 'shared-ua'),
        );

        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $overflow->decision);
        self::assertSame(
            2,
            $this->store->checkBlock($this->key($policy->getName(), 'k2', "{$ip}:shared-ua"))?->level,
        );
        self::assertSame(0, $this->correlationStore->distinctCount($this->dilutionScope($policy->getName(), $overflowFingerprint)));
        self::assertSame(0, $this->correlationStore->watchValue($this->dilutionWatch($policy->getName(), $overflowFingerprint)));
        self::assertSame(
            0,
            $this->correlationStore->watchValue(
                $this->confirmationKey(
                    $policy->getName(),
                    $this->dilutionScope($policy->getName(), $overflowFingerprint),
                    $this->windowId(),
                ),
            ),
        );
        self::assertNull($this->store->checkBlock($this->key($policy->getName(), 'k3', "{$ip}:{$overflowFingerprint}")));
        self::assertNull($this->store->get($this->key($policy->getName(), 'k5', "{$accountId}:{$overflowFingerprint}")));
    }

    /** @return iterable<string, array{string}> */
    public static function rotationShapes(): iterable
    {
        yield 'outer-key-only' => ['outer-key-only'];
        yield 'fingerprint-only' => ['fingerprint-only'];
        yield 'both-rotated' => ['both-rotated'];
    }

    /** @return iterable<string, array{string}> */
    public static function confidenceLevels(): iterable
    {
        yield 'medium' => ['MEDIUM'];
        yield 'high' => ['HIGH'];
    }

    private function resetState(): void
    {
        $this->clock = new FixedClock('2025-01-01 12:00:00');
        $this->store = new InMemoryRateLimitStore($this->clock);
        $this->correlationStore = new StatefulInMemoryCorrelationStore($this->clock);
    }

    private function pipeline(string $secret = self::SECRET, ?string $previousSecret = null): EvaluationPipeline
    {
        return new EvaluationPipeline(
            $this->store,
            $this->correlationStore,
            new BudgetTracker($this->store, $this->clock),
            new AntiEquilibriumGate($this->correlationStore),
            new DecayCalculator($this->clock),
            new EphemeralBucket($this->correlationStore),
            $secret,
            'prod',
            $this->clock,
            $previousSecret,
        );
    }

    private function check(BlockPolicyInterface $policy): RateLimitCommand
    {
        return RateLimitCommand::checkOnly($policy->getName());
    }

    private function context(string $ip, string $ua, ?string $accountId = null): RateLimitContextDTO
    {
        return new RateLimitContextDTO($ip, $ua, $accountId);
    }

    private function device(
        string $fingerprint,
        string $confidence = 'LOW',
        ?string $previousFingerprint = null,
        bool $trusted = false,
        string $normalizedUa = 'Mozilla/5.0',
    ): DeviceIdentityDTO {
        return new DeviceIdentityDTO(
            $fingerprint,
            $confidence,
            $trusted,
            false,
            $normalizedUa,
            false,
            $previousFingerprint,
        );
    }

    private function key(string $policy, string $type, string $scope, string $secret = self::SECRET): string
    {
        return hash_hmac('sha256', "{$policy}:rate_limiter:{$type}:v2:prod:{$scope}", $secret);
    }

    private function correlationScope(string $policy, string $purpose, string $anchor, string $secret = self::SECRET): string
    {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:correlation:{$purpose}:v1:prod:scope:{$anchor}",
            $secret,
        );
    }

    private function correlationStateKey(string $policy, string $purpose, string $anchor, string $secret = self::SECRET): string
    {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:correlation:{$purpose}:v1:prod:{$anchor}",
            $secret,
        );
    }

    private function churnScope(string $ip, string $ua, string $secret = self::SECRET): string
    {
        return $this->correlationScope(
            'otp_protection',
            'churn',
            $this->key('otp_protection', 'k2', "{$ip}:{$ua}", $secret),
            $secret,
        );
    }

    private function churnWatch(string $ip, string $ua, string $secret = self::SECRET): string
    {
        return $this->correlationStateKey('otp_protection', 'churn_watch', $this->churnScope($ip, $ua, $secret), $secret);
    }

    private function dilutionScope(string $policy, string $fingerprint, string $secret = self::SECRET): string
    {
        return $this->correlationScope($policy, 'dilution', $fingerprint, $secret);
    }

    private function dilutionWatch(string $policy, string $fingerprint, string $secret = self::SECRET): string
    {
        return $this->correlationStateKey($policy, 'dilution_watch', $this->dilutionScope($policy, $fingerprint, $secret), $secret);
    }

    private function confirmationKey(
        string $policy,
        string $scope,
        int $windowId,
        string $secret = self::SECRET,
    ): string {
        return $this->correlationStateKey($policy, 'dilution_confirmation', "{$scope}:{$windowId}", $secret);
    }

    private function windowId(): int
    {
        return (int) floor($this->clock->now()->getTimestamp() / 600);
    }

    /** @return array{oldSecret: string, currentSecret: string, previousSecret: ?string, currentFingerprint: string, previousFingerprint: ?string} */
    private function rotationConfig(string $shape, string $logicalFingerprint): array
    {
        return match ($shape) {
            'outer-key-only' => [
                'oldSecret' => 'old-secret',
                'currentSecret' => 'new-secret',
                'previousSecret' => 'old-secret',
                'currentFingerprint' => $logicalFingerprint,
                'previousFingerprint' => null,
            ],
            'fingerprint-only' => [
                'oldSecret' => self::SECRET,
                'currentSecret' => self::SECRET,
                'previousSecret' => null,
                'currentFingerprint' => "new-{$logicalFingerprint}",
                'previousFingerprint' => $logicalFingerprint,
            ],
            'both-rotated' => [
                'oldSecret' => 'old-secret',
                'currentSecret' => 'new-secret',
                'previousSecret' => 'old-secret',
                'currentFingerprint' => "new-{$logicalFingerprint}",
                'previousFingerprint' => $logicalFingerprint,
            ],
            default => throw new \InvalidArgumentException("Unknown rotation shape {$shape}"),
        };
    }

    private function logicalChurnCount(
        string $shape,
        string $previousScope,
        string $currentScope,
        string $currentSecret,
        string $ip,
        string $ua,
    ): int {
        if ($shape === 'fingerprint-only') {
            return $this->correlationStore->distinctCount($currentScope);
        }

        $bridgeScope = $this->correlationBridge(
            'otp_protection',
            'churn',
            $currentScope,
            $currentSecret,
        );

        return $this->correlationStore->distinctCount($previousScope)
            + $this->correlationStore->distinctCount($bridgeScope);
    }

    private function correlationBridge(string $policy, string $purpose, string $anchor, string $secret): string
    {
        return hash_hmac(
            'sha256',
            "{$policy}:rate_limiter:correlation:{$purpose}:v1:prod:bridge:{$anchor}",
            $secret,
        );
    }
}
