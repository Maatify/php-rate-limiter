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
use Maatify\RateLimiter\DTO\Internal\PipelineScoreDTO;
use Maatify\RateLimiter\DTO\Store\BlockStateDTO;
use Maatify\RateLimiter\DTO\Store\BudgetStateDTO;
use Maatify\RateLimiter\DTO\Store\CircuitBreakerStateDTO;
use Maatify\RateLimiter\DTO\Store\RateLimitStateDTO;
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

    public function testSerializationContract(): void
    {
        $thresholds = new ScoreThresholdsDTO(10, 20, 30);
        $policyThresholds = new PolicyThresholdsDTO($thresholds);

        $json = json_encode($policyThresholds, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('k1', $decoded);
        $this->assertIsArray($decoded['k1']);
        $this->assertArrayHasKey('l1', $decoded['k1']);
        $this->assertArrayHasKey('l2', $decoded['k1']);
        $this->assertArrayHasKey('l3', $decoded['k1']);
        $this->assertSame(10, $decoded['k1']['l1']);
        $this->assertSame(20, $decoded['k1']['l2']);
        $this->assertSame(30, $decoded['k1']['l3']);

        $contextMetadata = new RateLimitContextMetadataDTO('test_reason', 'test_scope');
        $metadata = new RateLimitMetadataDTO('test_signal', 'test_cause', $contextMetadata);

        $json = json_encode($metadata, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('signal', $decoded);
        $this->assertArrayHasKey('cause', $decoded);
        $this->assertArrayHasKey('context', $decoded);
        $this->assertSame('test_signal', $decoded['signal']);
        $this->assertSame('test_cause', $decoded['cause']);
        $this->assertIsArray($decoded['context']);
        $this->assertArrayHasKey('reason', $decoded['context']);
        $this->assertArrayHasKey('scope', $decoded['context']);
        $this->assertSame('test_reason', $decoded['context']['reason']);
        $this->assertSame('test_scope', $decoded['context']['scope']);
    }
}
