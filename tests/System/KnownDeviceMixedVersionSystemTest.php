<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\System;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Tests\Fixtures\S0Baseline\S0EvaluationPipeline;
use Maatify\RateLimiter\Tests\Fixtures\S0Baseline\S0LoginProtectionPolicy;
use Maatify\RateLimiter\Service\RequestScopedClock;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FullCapability\FullCapabilityInMemoryStore;
use PHPUnit\Framework\TestCase;

/**
 * Rollback and previous-generation regression for the known-device bound
 * (F117-06, DEC-021) on the full-capability reference store, where
 * time can be advanced. The old worker is the pinned S0 pipeline with the S0
 * Login preset; both workers share one store. Real-Redis mixed workers are in
 * KnownDeviceMixedVersionIntegrationTest.
 */
final class KnownDeviceMixedVersionSystemTest extends TestCase
{
    private FixedClock $clock;
    private RequestScopedClock $scoped;
    private FullCapabilityInMemoryStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FixedClock('2025-01-01 12:00:00');
        // #160 fix (a): store and workers share one held reading per process() call; time still advances on $this->clock.
        $this->scoped = new RequestScopedClock($this->clock);
        $this->store = new FullCapabilityInMemoryStore($this->scoped);
    }

    /**
     * M4 — rollback after new-version state, then roll forward. The new worker
     * leaves a K5 score, an allowance counter and device Anti-Equilibrium
     * events (never account events). S0 ignores all three and does not
     * escalate the account. Rolling forward, the new worker still holds the
     * device events and escalates on K5 only; the owner's other device stays
     * allowed.
     */
    public function testRollbackAfterNewDeviceAntiEquilibriumStateAndRollForward(): void
    {
        $laptop = $this->device('m4-laptop');
        $new = $this->newWorker();
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->failure($new, new LoginProtectionPolicy(), 'm4-owner', $laptop);
        }
        for ($retry = 0; $retry < 3; $retry++) {
            self::assertSame('SOFT_BLOCK', $this->check($new, new LoginProtectionPolicy(), 'm4-owner', $laptop)->decision);
        }
        self::assertSame(0, $this->store->getWatchFlag($this->auxiliary('anti_equilibrium', 'm4-owner')));
        self::assertGreaterThanOrEqual(3, $this->store->getWatchFlag($this->auxiliary('anti_equilibrium_device', 'm4-owner:m4-laptop')));

        $this->advance(300);
        $s0 = $this->s0Worker();
        self::assertSame('ALLOW', $this->check($s0, new S0LoginProtectionPolicy(), 'm4-owner', $laptop)->decision);
        self::assertSame('ALLOW', $this->failure($s0, new S0LoginProtectionPolicy(), 'm4-owner', $laptop)->decision);
        self::assertNull($this->store->checkBlock($this->key('k4', 'm4-owner')));

        $this->advance(600);
        $forward = $this->newWorker();
        self::assertSame('ALLOW', $this->check($forward, new LoginProtectionPolicy(), 'm4-owner', $laptop)->decision);
        self::assertSame('HARD_BLOCK', $this->failure($forward, new LoginProtectionPolicy(), 'm4-owner', $laptop)->decision);
        self::assertSame(2, $this->store->checkBlock($this->key('k5', 'm4-owner:m4-laptop'))?->level);
        self::assertNull($this->store->checkBlock($this->key('k4', 'm4-owner')));
        self::assertSame('ALLOW', $this->check($forward, new LoginProtectionPolicy(), 'm4-owner', $this->device('m4-phone'))->decision);
    }

    /**
     * M5 — previous generation. State written under the old outer secret is
     * read through the previous keys by the new worker: an S0 K5 score, S0
     * account Anti-Equilibrium events, and new-worker device events. Writes go
     * to the current secret only; previous keys stay unchanged.
     */
    public function testPreviousGenerationScoresAndWatchersAreReadAcrossRotation(): void
    {
        $s0Old = $this->s0Worker('old_secret');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->failure($s0Old, new S0LoginProtectionPolicy(), 'm5-owner', $this->device('m5-laptop'));
        }
        $rotated = $this->newWorker('new_secret', 'old_secret');
        self::assertSame('SOFT_BLOCK', $this->check($rotated, new LoginProtectionPolicy(), 'm5-owner', $this->device('m5-laptop'))->decision);
        self::assertSame(10, $this->store->get($this->key('k5', 'm5-owner:m5-laptop', 'old_secret'))?->value);

        $this->store->set($this->key('k4', 'm5-account', 'old_secret'), 5, 86400);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            self::assertSame('SOFT_BLOCK', $this->check($s0Old, new S0LoginProtectionPolicy(), 'm5-account', $this->device('m5-new-device', false))->decision);
        }
        $this->store->set($this->key('k4', 'm5-account', 'old_secret'), 0, 86400);
        self::assertSame('HARD_BLOCK', $this->failure($rotated, new LoginProtectionPolicy(), 'm5-account', $this->device('m5-new-device', false))->decision);
        self::assertSame(2, $this->store->checkBlock($this->key('k4', 'm5-account', 'new_secret'))?->level);
        self::assertSame(3, $this->store->getWatchFlag($this->auxiliary('anti_equilibrium', 'm5-account', 'old_secret')));

        $newOld = $this->newWorker('old_secret');
        $laptop = $this->device('m5-device-owner');
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->failure($newOld, new LoginProtectionPolicy(), 'm5-device', $laptop);
        }
        for ($retry = 0; $retry < 3; $retry++) {
            $this->check($newOld, new LoginProtectionPolicy(), 'm5-device', $laptop);
        }
        $deviceEvents = $this->store->getWatchFlag($this->auxiliary('anti_equilibrium_device', 'm5-device:m5-device-owner', 'old_secret'));
        $this->advance(300);
        $rotatedDevice = $this->newWorker('new_secret', 'old_secret');
        self::assertSame('HARD_BLOCK', $this->failure($rotatedDevice, new LoginProtectionPolicy(), 'm5-device', $laptop)->decision);
        self::assertSame(2, $this->store->checkBlock($this->key('k5', 'm5-device:m5-device-owner', 'new_secret'))?->level);
        self::assertNull($this->store->checkBlock($this->key('k4', 'm5-device', 'new_secret')));
        self::assertSame($deviceEvents, $this->store->getWatchFlag($this->auxiliary('anti_equilibrium_device', 'm5-device:m5-device-owner', 'old_secret')));
    }

    /**
     * M3t — the timed #114 stream (25 attempts every 3 s over 72 s, advancing
     * clock) through every mixed-fleet arrangement of the pinned S0 worker and
     * the new worker. Observed values, not parity.
     */
    public function testAlternatingWorkersOnTheTimedTrustedStream(): void
    {
        $observed = [];
        foreach (['s0', 'new', 'alternate-s0-first', 'alternate-new-first', 'pre-s0-out-new', 'pre-new-out-s0'] as $fleet) {
            $this->setUp();
            $s0 = $this->s0Worker();
            $new = $this->newWorker();
            $device = $this->device('m3t-session');
            $reached = 0;
            for ($attempt = 0; $attempt < 25; $attempt++) {
                $preOnS0 = match ($fleet) {
                    's0', 'pre-s0-out-new' => true,
                    'new', 'pre-new-out-s0' => false,
                    'alternate-s0-first' => $attempt % 2 === 0,
                    'alternate-new-first' => $attempt % 2 === 1,
                };
                $outOnS0 = match ($fleet) {
                    'pre-s0-out-new' => false,
                    'pre-new-out-s0' => true,
                    default => $preOnS0,
                };
                $pre = $this->check($preOnS0 ? $s0 : $new, $preOnS0 ? new S0LoginProtectionPolicy() : new LoginProtectionPolicy(), 'm3t-owner', $device);
                if ($pre->decision === RateLimitResultDTO::DECISION_ALLOW) {
                    $reached++;
                    $this->failure($outOnS0 ? $s0 : $new, $outOnS0 ? new S0LoginProtectionPolicy() : new LoginProtectionPolicy(), 'm3t-owner', $device);
                }
                $this->advance(3);
            }
            $observed[$fleet] = $reached;
        }

        self::assertSame([
            's0' => 25,
            'new' => 4,
            'alternate-s0-first' => 15,
            'alternate-new-first' => 14,
            'pre-s0-out-new' => 6,
            'pre-new-out-s0' => 4,
        ], $observed);
    }

    private function check(EvaluationPipeline|S0EvaluationPipeline $worker, BlockPolicyInterface $policy, string $account, DeviceIdentityDTO $device): RateLimitResultDTO
    {
        return $this->scoped->holding(fn(): RateLimitResultDTO => $worker->process($policy, $this->context($account), RateLimitCommand::checkOnly($policy->getName()), $device));
    }

    private function failure(EvaluationPipeline|S0EvaluationPipeline $worker, BlockPolicyInterface $policy, string $account, DeviceIdentityDTO $device): RateLimitResultDTO
    {
        return $this->scoped->holding(fn(): RateLimitResultDTO => $worker->process($policy, $this->context($account), RateLimitCommand::recordFailure($policy->getName()), $device));
    }

    private function newWorker(string $secret = 'test_secret', ?string $previousSecret = null): EvaluationPipeline
    {
        return new EvaluationPipeline($this->store, $this->store, new BudgetTracker($this->store, $this->scoped), new AntiEquilibriumGate($this->store), new DecayCalculator($this->scoped), new EphemeralBucket($this->store), $secret, 'prod', $this->scoped, $previousSecret);
    }

    private function s0Worker(string $secret = 'test_secret', ?string $previousSecret = null): S0EvaluationPipeline
    {
        return new S0EvaluationPipeline($this->store, $this->store, new BudgetTracker($this->store, $this->scoped), new AntiEquilibriumGate($this->store), new DecayCalculator($this->scoped), new EphemeralBucket($this->store), $secret, 'prod', $this->scoped, $previousSecret);
    }

    /**
     * FixedClock::sleep() is a no-op; time must be advanced explicitly.
     */
    private function advance(int $seconds): void
    {
        $this->clock->setNow($this->clock->now()->modify("+{$seconds} seconds"));
    }

    /**
     * One IP per account, so that sub-scenarios sharing the store never meet
     * through K2 churn on a common IP + user agent.
     */
    private function context(string $account): RateLimitContextDTO
    {
        return new RateLimitContextDTO('198.51.100.' . (crc32($account) % 250 + 1), 'Chrome/123', $account, ['device' => 'stable']);
    }

    private function device(string $fingerprint, bool $trusted = true): DeviceIdentityDTO
    {
        return new DeviceIdentityDTO($fingerprint, $trusted ? 'HIGH' : 'MEDIUM', $trusted, false, 'chrome/123', false);
    }

    private function key(string $type, string $scope, string $secret = 'test_secret'): string
    {
        return hash_hmac('sha256', "login_protection:rate_limiter:{$type}:v2:prod:{$scope}", $secret);
    }

    private function auxiliary(string $purpose, string $scope, string $secret = 'test_secret'): string
    {
        return hash_hmac('sha256', "login_protection:rate_limiter:aux:{$purpose}:v1:prod:{$scope}", $secret);
    }
}
