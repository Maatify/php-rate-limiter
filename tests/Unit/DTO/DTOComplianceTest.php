<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\DTO;

use PHPUnit\Framework\TestCase;
use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\DTO\BudgetStatusDTO;
use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\EphemeralStateDTO;
use Maatify\RateLimiter\DTO\FailureSignalDTO;
use Maatify\RateLimiter\DTO\FailureStateDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitContextMetadataDTO;
use Maatify\RateLimiter\DTO\RateLimitMetadataDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;
use Maatify\RateLimiter\DTO\PipelineScoreDTO;
use Maatify\RateLimiter\DTO\BlockStateDTO;
use Maatify\RateLimiter\DTO\BudgetStateDTO;
use Maatify\RateLimiter\DTO\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\RateLimitStateDTO;
use ReflectionClass;
use JsonSerializable;

class DTOComplianceTest extends TestCase
{
    /** @var array<int, class-string> */
    private array $dtos = [
        BudgetConfigDTO::class,
        BudgetStatusDTO::class,
        DeviceIdentityDTO::class,
        EphemeralStateDTO::class,
        FailureSignalDTO::class,
        FailureStateDTO::class,
        PolicyThresholdsDTO::class,
        RateLimitContextDTO::class,
        RateLimitContextMetadataDTO::class,
        RateLimitMetadataDTO::class,
        RateLimitResultDTO::class,
        ScoreDeltasDTO::class,
        ScoreThresholdsDTO::class,
        PipelineScoreDTO::class,
        BlockStateDTO::class,
        BudgetStateDTO::class,
        CircuitBreakerStateDTO::class,
        RateLimitStateDTO::class,
    ];

    public function testDTOsAreFinalReadonlyAndImplementJsonSerializable(): void
    {
        foreach ($this->dtos as $dtoClass) {
            $reflection = new ReflectionClass($dtoClass);

            $this->assertTrue($reflection->isFinal(), "$dtoClass should be final");
            $this->assertTrue($reflection->isReadOnly(), "$dtoClass should be readonly");
            $this->assertTrue($reflection->implementsInterface(JsonSerializable::class), "$dtoClass should implement JsonSerializable");

            $method = $reflection->getMethod('jsonSerialize');
            $this->assertSame($dtoClass, $method->getDeclaringClass()->getName(), "$dtoClass must explicitly declare jsonSerialize()");
        }
    }

    public function testRateLimitResultDTOSemantics(): void
    {
        $allowResult = new RateLimitResultDTO(
            RateLimitResultDTO::DECISION_ALLOW,
            null,
            null,
            'NORMAL',
            null
        );
        $this->assertTrue($allowResult->isAllowed());
        $this->assertFalse($allowResult->isBlocked());

        $blockResult = new RateLimitResultDTO(
            RateLimitResultDTO::DECISION_HARD_BLOCK,
            3,
            60,
            'NORMAL',
            null
        );
        $this->assertFalse($blockResult->isAllowed());
        $this->assertTrue($blockResult->isBlocked());
    }

    public function testBudgetConfigDTOLegacyConstructorPreservesRuntimeSemantics(): void
    {
        $config = new BudgetConfigDTO(100, 2);

        $this->assertSame(100, $config->threshold);
        $this->assertSame(2, $config->block_level);
        $this->assertSame(0, $config->cooldown_seconds);
        $this->assertSame(2, $config->trusted_session_floor_level);
        $this->assertTrue($config->precheck_enforcement);
        $this->assertSame(8, $config->known_device_micro_cap);
        $this->assertFalse($config->recovery_collision_guard_enabled);
    }

    public function testRateLimitContextDTOLegacyConstructorDefaultsDeviceSignalToFalse(): void
    {
        $context = new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acc_123');

        $this->assertFalse($context->isDevicePreviouslyVerifiedForAccount);
    }

    public function testDeviceIdentityDTOLegacyConstructorDefaultsDeviceSignalToFalse(): void
    {
        $device = new DeviceIdentityDTO('hash', 'LOW', false);

        $this->assertFalse($device->isDevicePreviouslyVerifiedForAccount);
        $this->assertNull($device->previousFingerprintHash);
    }

    /**
     * @dataProvider dtoSerializationProvider
     * @param array<int, string> $expectedKeys
     * @param array<string, mixed> $expectedValues
     */
    public function testCompleteDTOSerialization(\JsonSerializable $dto, array $expectedKeys, array $expectedValues): void
    {
        $json = json_encode($dto, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        // Assert exactly the existing public data-field keys
        $this->assertSame($expectedKeys, array_keys($decoded), 'Serialized keys must exactly match public properties in constructor/property order');

        // Assert no key is missing and no extra key is introduced
        $this->assertSame(count($expectedKeys), count($decoded));

        // Assert values survive unchanged, including nulls and nested arrays
        foreach ($expectedValues as $key => $expectedValue) {
            if (is_array($expectedValue) && isset($decoded[$key])) {
                $this->assertEquals($expectedValue, $decoded[$key], "Array/nested value for $key should match");
            } else {
                $this->assertSame($expectedValue, $decoded[$key], "Value for $key should match exactly");
            }
        }
    }

    /**
     * @return array<string, array{0: \JsonSerializable, 1: array<int, string>, 2: array<string, mixed>}>
     */
    public static function dtoSerializationProvider(): array
    {
        $contextMetadata = new RateLimitContextMetadataDTO('test_reason', 'test_scope');
        $metadata = new RateLimitMetadataDTO('test_signal', 'test_cause', clone $contextMetadata);
        $scoreThresholds = new ScoreThresholdsDTO(10, 20, 30);

        return [
            BudgetConfigDTO::class => [
                new BudgetConfigDTO(100, 2),
                ['threshold', 'block_level', 'cooldown_seconds', 'trusted_session_floor_level', 'precheck_enforcement', 'known_device_micro_cap', 'recovery_collision_guard_enabled'],
                ['threshold' => 100, 'block_level' => 2, 'cooldown_seconds' => 0, 'trusted_session_floor_level' => 2, 'precheck_enforcement' => true, 'known_device_micro_cap' => 8, 'recovery_collision_guard_enabled' => false]
            ],
            BudgetStatusDTO::class => [
                new BudgetStatusDTO(50, 10),
                ['count', 'epochStart'],
                ['count' => 50, 'epochStart' => 10]
            ],
            DeviceIdentityDTO::class => [
                new DeviceIdentityDTO('hash-123', 'HIGH', true, false, 'ua-456'),
                ['fingerprintHash', 'confidence', 'isTrustedSession', 'churnDetected', 'normalizedUa', 'isDevicePreviouslyVerifiedForAccount', 'previousFingerprintHash'],
                ['fingerprintHash' => 'hash-123', 'confidence' => 'HIGH', 'isTrustedSession' => true, 'churnDetected' => false, 'normalizedUa' => 'ua-456', 'isDevicePreviouslyVerifiedForAccount' => false, 'previousFingerprintHash' => null]
            ],
            EphemeralStateDTO::class => [
                new EphemeralStateDTO(true, 5, 10),
                ['isEphemeral', 'accountDeviceCount', 'ipDeviceCount'],
                ['isEphemeral' => true, 'accountDeviceCount' => 5, 'ipDeviceCount' => 10]
            ],
            FailureSignalDTO::class => [
                new FailureSignalDTO('CRITICAL', 'test_policy', clone $metadata),
                ['type', 'policyName', 'metadata'],
                ['type' => 'CRITICAL', 'policyName' => 'test_policy', 'metadata' => ['signal' => 'test_signal', 'cause' => 'test_cause', 'context' => ['reason' => 'test_reason', 'scope' => 'test_scope']]]
            ],
            FailureStateDTO::class => [
                new FailureStateDTO('OPEN', 3, 1234567, true),
                ['state', 'failureCount', 'lastFailureTimestamp', 'isDegraded'],
                ['state' => 'OPEN', 'failureCount' => 3, 'lastFailureTimestamp' => 1234567, 'isDegraded' => true]
            ],
            PolicyThresholdsDTO::class => [
                new PolicyThresholdsDTO($scoreThresholds, null, null, null, null, clone $scoreThresholds),
                ['k1', 'k2', 'k3', 'k4', 'k5', 'default'],
                ['k1' => ['l1' => 10, 'l2' => 20, 'l3' => 30], 'k2' => null, 'k3' => null, 'k4' => null, 'k5' => null, 'default' => ['l1' => 10, 'l2' => 20, 'l3' => 30]]
            ],
            RateLimitContextDTO::class => [
                new RateLimitContextDTO('127.0.0.1', 'Mozilla', 'acc_123', ['canvas' => 'abcd'], 'dev_456', true, ['Host' => 'localhost']),
                ['ip', 'ua', 'accountId', 'clientFingerprint', 'sessionDeviceId', 'isSessionTrusted', 'headers', 'isDevicePreviouslyVerifiedForAccount'],
                ['ip' => '127.0.0.1', 'ua' => 'Mozilla', 'accountId' => 'acc_123', 'clientFingerprint' => ['canvas' => 'abcd'], 'sessionDeviceId' => 'dev_456', 'isSessionTrusted' => true, 'headers' => ['Host' => 'localhost'], 'isDevicePreviouslyVerifiedForAccount' => false]
            ],
            RateLimitContextMetadataDTO::class => [
                new RateLimitContextMetadataDTO('test_reason', 'test_scope'),
                ['reason', 'scope'],
                ['reason' => 'test_reason', 'scope' => 'test_scope']
            ],
            RateLimitMetadataDTO::class => [
                new RateLimitMetadataDTO('test_signal', 'test_cause', clone $contextMetadata),
                ['signal', 'cause', 'context'],
                ['signal' => 'test_signal', 'cause' => 'test_cause', 'context' => ['reason' => 'test_reason', 'scope' => 'test_scope']]
            ],
            RateLimitResultDTO::class => [
                new RateLimitResultDTO(RateLimitResultDTO::DECISION_HARD_BLOCK, 3, 60, 'NORMAL', clone $metadata),
                ['decision', 'blockLevel', 'retryAfter', 'failureMode', 'metadata'],
                ['decision' => RateLimitResultDTO::DECISION_HARD_BLOCK, 'blockLevel' => 3, 'retryAfter' => 60, 'failureMode' => 'NORMAL', 'metadata' => ['signal' => 'test_signal', 'cause' => 'test_cause', 'context' => ['reason' => 'test_reason', 'scope' => 'test_scope']]]
            ],
            ScoreDeltasDTO::class => [
                new ScoreDeltasDTO(1, 2, 3, 4, 5, 6),
                ['access', 'k1_spray', 'k2_missing_fp', 'k4_failure', 'k4_repeated_missing_fp', 'k5_failure'],
                ['access' => 1, 'k1_spray' => 2, 'k2_missing_fp' => 3, 'k4_failure' => 4, 'k4_repeated_missing_fp' => 5, 'k5_failure' => 6]
            ],
            ScoreThresholdsDTO::class => [
                new ScoreThresholdsDTO(10, 20, 30),
                ['l1', 'l2', 'l3'],
                ['l1' => 10, 'l2' => 20, 'l3' => 30]
            ],
            PipelineScoreDTO::class => [
                new PipelineScoreDTO(100, 1600000000, true),
                ['value', 'updatedAt', 'isFromV1'],
                ['value' => 100, 'updatedAt' => 1600000000, 'isFromV1' => true]
            ],
            BlockStateDTO::class => [
                new BlockStateDTO(2, 3600),
                ['level', 'expiresAt'],
                ['level' => 2, 'expiresAt' => 3600]
            ],
            BudgetStateDTO::class => [
                new BudgetStateDTO(5, 1000),
                ['count', 'epochStart'],
                ['count' => 5, 'epochStart' => 1000]
            ],
            CircuitBreakerStateDTO::class => [
                new CircuitBreakerStateDTO('OPEN', [1, 2], 123, 456, 789, [3, 4], 999),
                ['status', 'failures', 'lastFailure', 'openSince', 'lastSuccess', 'reEntries', 'failClosedUntil'],
                ['status' => 'OPEN', 'failures' => [1, 2], 'lastFailure' => 123, 'openSince' => 456, 'lastSuccess' => 789, 'reEntries' => [3, 4], 'failClosedUntil' => 999]
            ],
            RateLimitStateDTO::class => [
                new RateLimitStateDTO(150, 2000),
                ['value', 'updatedAt'],
                ['value' => 150, 'updatedAt' => 2000]
            ],
        ];
    }
}
