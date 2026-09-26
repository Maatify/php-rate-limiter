<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Correlation;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\OtpProtectionPolicy;
use Maatify\RateLimiter\DTO\BoundedCorrelationObservationDTO;
use Maatify\RateLimiter\DTO\BoundedDistinctResultDTO;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\BoundedCorrelationRotationStoreInterface;
use Maatify\RateLimiter\Service\AntiEquilibriumGate;
use Maatify\RateLimiter\Service\BudgetTracker;
use Maatify\RateLimiter\Service\DecayCalculator;
use Maatify\RateLimiter\Service\EphemeralBucket;
use Maatify\RateLimiter\Service\EvaluationPipeline;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MalformedBoundedCorrelationStore implements BoundedCorrelationRotationStoreInterface
{
    public function __construct(
        private readonly BoundedDistinctResultDTO $invalidResult,
        private readonly bool $returnValidDeviceCaps = false,
        private readonly ?BoundedDistinctResultDTO $rotationResult = null,
    ) {}

    public function addDistinct(string $key, string $item, int $ttlSeconds): int
    {
        return 1;
    }

    public function addDistinctBounded(
        string $key,
        string $item,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctResultDTO {
        return $this->returnValidDeviceCaps && in_array($maxDistinct, [10, 50], true)
            ? new BoundedDistinctResultDTO(1, true)
            : $this->invalidResult;
    }

    public function addDistinctAcrossRotation(
        string $currentKey,
        string $bridgeKey,
        string $previousKey,
        string $currentMember,
        string $previousMember,
        int $ttlSeconds,
    ): int {
        return 1;
    }

    public function addDistinctBoundedAcrossRotation(
        string $currentKey,
        string $bridgeKey,
        string $previousKey,
        string $currentMember,
        string $previousMember,
        int $ttlSeconds,
        int $maxDistinct,
    ): BoundedDistinctResultDTO {
        if ($this->rotationResult !== null) {
            return $this->rotationResult;
        }

        return $this->returnValidDeviceCaps && in_array($maxDistinct, [10, 50], true)
            ? new BoundedDistinctResultDTO(1, true)
            : $this->invalidResult;
    }

    public function incrementWatchFlag(string $key, int $ttlSeconds): int
    {
        return 1;
    }

    public function getWatchFlag(string $key): int
    {
        return 0;
    }

    public function incrementWatchFlagAcrossRotation(string $currentKey, string $previousKey, int $ttlSeconds): int
    {
        return 1;
    }
}

final class BoundedCorrelationResultValidationTest extends TestCase
{
    #[DataProvider('invalidResults')]
    public function testEphemeralBucketRejectsEveryMalformedNonRotationResult(BoundedDistinctResultDTO $result): void
    {
        $store = new MalformedBoundedCorrelationStore($result);
        $bucket = new EphemeralBucket($store);

        $this->expectException(RateLimiterException::class);
        $bucket->check(new BoundedCorrelationObservationDTO('ip', 'member'));
    }

    public function testEphemeralBucketRejectsMalformedRotationResult(): void
    {
        $store = new MalformedBoundedCorrelationStore(
            new BoundedDistinctResultDTO(1, true),
            false,
            new BoundedDistinctResultDTO(0, true),
        );
        $bucket = new EphemeralBucket($store);

        $this->expectException(RateLimiterException::class);
        $bucket->check(new BoundedCorrelationObservationDTO(
            'current',
            'member',
            'previous',
            'previous-member',
            'bridge',
        ));
    }

    #[DataProvider('invalidResults')]
    public function testEvaluationPipelineRejectsMalformedChurnResult(BoundedDistinctResultDTO $result): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $rateStore = new InMemoryRateLimitStore($clock);
        $correlationStore = new MalformedBoundedCorrelationStore($result, true);
        $pipeline = $this->pipeline($clock, $rateStore, $correlationStore);

        $this->expectException(RateLimiterException::class);
        $pipeline->process(
            new OtpProtectionPolicy(),
            new RateLimitContextDTO('203.0.113.50', 'Mozilla/5.0', 'account'),
            RateLimitCommand::checkOnly('otp_protection'),
            new DeviceIdentityDTO('fingerprint', 'LOW', false, false, 'mozilla/5.0'),
        );
    }

    #[DataProvider('invalidResults')]
    public function testCredentialSprayRejectsMalformedBoundedResult(BoundedDistinctResultDTO $result): void
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $rateStore = new InMemoryRateLimitStore($clock);
        $correlationStore = new MalformedBoundedCorrelationStore($result);
        $pipeline = $this->pipeline($clock, $rateStore, $correlationStore);

        $this->expectException(RateLimiterException::class);
        $pipeline->process(
            new LoginProtectionPolicy(),
            new RateLimitContextDTO('203.0.113.51', 'Mozilla/5.0', 'account', correlationId: 'subject'),
            RateLimitCommand::checkOnly('login_protection'),
            new DeviceIdentityDTO(null, 'LOW', false, false, 'other/0'),
        );
    }

    /** @return array<string, array{BoundedDistinctResultDTO}> */
    public static function invalidResults(): array
    {
        return [
            'negative count' => [new BoundedDistinctResultDTO(-1, true)],
            'zero after observation' => [new BoundedDistinctResultDTO(0, true)],
            'over capacity' => [new BoundedDistinctResultDTO(51, true)],
            'rejected below capacity' => [new BoundedDistinctResultDTO(49, false)],
        ];
    }

    private function pipeline(
        FixedClock $clock,
        InMemoryRateLimitStore $rateStore,
        MalformedBoundedCorrelationStore $correlationStore,
    ): EvaluationPipeline {
        return new EvaluationPipeline(
            $rateStore,
            $correlationStore,
            new BudgetTracker($rateStore, $clock),
            new AntiEquilibriumGate($correlationStore),
            new DecayCalculator($clock),
            new EphemeralBucket($correlationStore),
            'test-secret',
            'prod',
            $clock,
        );
    }
}
