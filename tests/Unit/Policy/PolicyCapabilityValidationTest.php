<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Policy;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Config\BlockPolicyInterface;
use Maatify\RateLimiter\Config\FailureFallbackConfigurationProviderInterface;
use Maatify\RateLimiter\Config\FailureFallbackDimension;
use Maatify\RateLimiter\Config\PolicyCapability;
use Maatify\RateLimiter\Config\PolicyCapabilityProviderInterface;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\BudgetConfigDTO;
use Maatify\RateLimiter\DTO\FailureFallbackConfigurationDTO;
use Maatify\RateLimiter\DTO\FailureFallbackRuleDTO;
use Maatify\RateLimiter\DTO\PolicyThresholdsDTO;
use Maatify\RateLimiter\DTO\ScoreDeltasDTO;
use Maatify\RateLimiter\DTO\ScoreThresholdsDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Tests\Support\CircuitBreaker\InMemoryCircuitBreakerStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Correlation\StatefulInMemoryCorrelationStore;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\RateLimiter\InMemoryRateLimitStore;
use PHPUnit\Framework\TestCase;

final class PolicyCapabilityValidationTest extends TestCase
{
    /**
     * @dataProvider invalidPolicyProvider
     */
    public function testInvalidTypedPolicyIsRejected(string $expectedMessage, BlockPolicyInterface $policy): void
    {
        $this->expectException(RateLimiterException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->builder()->withPolicy($policy)->build();
    }

    /**
     * @return iterable<string, array{string, BlockPolicyInterface}>
     */
    public static function invalidPolicyProvider(): iterable
    {
        $auth = [
            PolicyCapability::CREDENTIAL_SPRAY,
            PolicyCapability::DISTRIBUTED_ACCOUNT,
            PolicyCapability::TRUSTED_AUTHENTICATION,
        ];
        $api = [PolicyCapability::API_OVERUSE];

        $authConfig = new FailureFallbackConfigurationDTO([
            new FailureFallbackRuleDTO(FailureFallbackDimension::ACCOUNT, 3, 600),
            new FailureFallbackRuleDTO(FailureFallbackDimension::IP_PREFIX, 20, 600),
        ]);
        $apiConfig = new FailureFallbackConfigurationDTO([
            new FailureFallbackRuleDTO(FailureFallbackDimension::IP_PREFIX, 120, 60),
            new FailureFallbackRuleDTO(FailureFallbackDimension::IP_PREFIX_NORMALIZED_USER_AGENT, 60, 60),
        ]);

        yield 'auth without budget' => [
            'Authentication policies require BudgetConfig',
            new ValidationPolicy('invalid-auth-budget', $auth, $authConfig, budget: null),
        ];
        yield 'auth without device signal' => [
            'Authentication policies require a device-aware positive signal',
            new ValidationPolicy('invalid-auth-device', $auth, $authConfig, deltas: new ScoreDeltasDTO(k4_failure: 1)),
        ];
        yield 'auth fail open' => [
            'FAIL_OPEN is not allowed',
            new ValidationPolicy('invalid-auth-mode', $auth, $authConfig, mode: 'FAIL_OPEN'),
        ];
        yield 'auth without fallback configuration' => [
            'Authentication policies require a bounded fallback configuration with ACCOUNT and IP_PREFIX dimensions',
            new NoConfigurationValidationPolicy('invalid-auth-config', $auth),
        ];
        yield 'auth fallback configuration missing account' => [
            'Authentication policies require a bounded fallback configuration with ACCOUNT and IP_PREFIX dimensions',
            new ValidationPolicy('invalid-auth-missing-account', $auth, new FailureFallbackConfigurationDTO([
                new FailureFallbackRuleDTO(FailureFallbackDimension::IP_PREFIX, 20, 600),
            ])),
        ];
        yield 'auth fallback configuration missing ip prefix' => [
            'Authentication policies require a bounded fallback configuration with ACCOUNT and IP_PREFIX dimensions',
            new ValidationPolicy('invalid-auth-missing-ip', $auth, new FailureFallbackConfigurationDTO([
                new FailureFallbackRuleDTO(FailureFallbackDimension::ACCOUNT, 3, 600),
            ])),
        ];
        yield 'auth and api combined' => [
            'Authentication and API_OVERUSE capabilities cannot be combined',
            new ValidationPolicy('invalid-auth-api', [...$auth, PolicyCapability::API_OVERUSE], $apiConfig),
        ];
        yield 'api without fallback configuration' => [
            'API_OVERUSE requires a bounded fallback configuration with IP_PREFIX and IP_PREFIX_NORMALIZED_USER_AGENT dimensions',
            new NoConfigurationValidationPolicy('invalid-api-config', $api, mode: 'FAIL_OPEN', api: true),
        ];
        yield 'api fallback configuration missing ip prefix' => [
            'API_OVERUSE requires a bounded fallback configuration with IP_PREFIX and IP_PREFIX_NORMALIZED_USER_AGENT dimensions',
            new ValidationPolicy('invalid-api-missing-ip', $api, new FailureFallbackConfigurationDTO([
                new FailureFallbackRuleDTO(FailureFallbackDimension::IP_PREFIX_NORMALIZED_USER_AGENT, 60, 60),
            ]), mode: 'FAIL_OPEN'),
        ];
        yield 'api fallback configuration missing ip prefix ua' => [
            'API_OVERUSE requires a bounded fallback configuration with IP_PREFIX and IP_PREFIX_NORMALIZED_USER_AGENT dimensions',
            new ValidationPolicy('invalid-api-missing-ua', $api, new FailureFallbackConfigurationDTO([
                new FailureFallbackRuleDTO(FailureFallbackDimension::IP_PREFIX, 120, 60),
            ]), mode: 'FAIL_OPEN'),
        ];
        yield 'api fallback configuration includes account (incompatible capability/configuration)' => [
            'API_OVERUSE fallback configuration must not include an ACCOUNT dimension',
            new ValidationPolicy('invalid-api-account-dimension', $api, new FailureFallbackConfigurationDTO([
                new FailureFallbackRuleDTO(FailureFallbackDimension::IP_PREFIX, 120, 60),
                new FailureFallbackRuleDTO(FailureFallbackDimension::IP_PREFIX_NORMALIZED_USER_AGENT, 60, 60),
                new FailureFallbackRuleDTO(FailureFallbackDimension::ACCOUNT, 3, 600),
            ]), mode: 'FAIL_OPEN'),
        ];
        yield 'fail open without api' => [
            'FAIL_OPEN requires API_OVERUSE capability and a bounded fallback configuration',
            new NoConfigurationValidationPolicy('invalid-open', [], mode: 'FAIL_OPEN'),
        ];
        yield 'fallback configuration zero limit' => [
            'Fallback configuration ip_prefix limit must be positive',
            new ValidationPolicy('invalid-fallback-zero-limit', $api, new FailureFallbackConfigurationDTO([
                new FailureFallbackRuleDTO(FailureFallbackDimension::IP_PREFIX, 0, 60),
                new FailureFallbackRuleDTO(FailureFallbackDimension::IP_PREFIX_NORMALIZED_USER_AGENT, 60, 60),
            ]), mode: 'FAIL_OPEN'),
        ];
        yield 'fallback configuration negative limit' => [
            'Fallback configuration ip_prefix limit must be positive',
            new ValidationPolicy('invalid-fallback-negative-limit', $api, new FailureFallbackConfigurationDTO([
                new FailureFallbackRuleDTO(FailureFallbackDimension::IP_PREFIX, -1, 60),
                new FailureFallbackRuleDTO(FailureFallbackDimension::IP_PREFIX_NORMALIZED_USER_AGENT, 60, 60),
            ]), mode: 'FAIL_OPEN'),
        ];
        yield 'fallback configuration zero window' => [
            'Fallback configuration ip_prefix window must be positive',
            new ValidationPolicy('invalid-fallback-zero-window', $api, new FailureFallbackConfigurationDTO([
                new FailureFallbackRuleDTO(FailureFallbackDimension::IP_PREFIX, 120, 0),
                new FailureFallbackRuleDTO(FailureFallbackDimension::IP_PREFIX_NORMALIZED_USER_AGENT, 60, 60),
            ]), mode: 'FAIL_OPEN'),
        ];
        yield 'fallback configuration negative window' => [
            'Fallback configuration ip_prefix window must be positive',
            new ValidationPolicy('invalid-fallback-negative-window', $api, new FailureFallbackConfigurationDTO([
                new FailureFallbackRuleDTO(FailureFallbackDimension::IP_PREFIX, 120, -60),
                new FailureFallbackRuleDTO(FailureFallbackDimension::IP_PREFIX_NORMALIZED_USER_AGENT, 60, 60),
            ]), mode: 'FAIL_OPEN'),
        ];
        yield 'fallback configuration duplicate dimension' => [
            'Fallback configuration declares a duplicate ip_prefix dimension',
            new ValidationPolicy('invalid-fallback-duplicate', $api, new FailureFallbackConfigurationDTO([
                new FailureFallbackRuleDTO(FailureFallbackDimension::IP_PREFIX, 120, 60),
                new FailureFallbackRuleDTO(FailureFallbackDimension::IP_PREFIX, 200, 60),
                new FailureFallbackRuleDTO(FailureFallbackDimension::IP_PREFIX_NORMALIZED_USER_AGENT, 60, 60),
            ]), mode: 'FAIL_OPEN'),
        ];
        yield 'api zero threshold' => [
            'API_OVERUSE k1 thresholds must be positive and monotonic',
            new ValidationPolicy('invalid-api-zero', $api, $apiConfig, mode: 'FAIL_OPEN', thresholds: new PolicyThresholdsDTO(
                k1: new ScoreThresholdsDTO(0, 1, 1),
                k2: new ScoreThresholdsDTO(1, 1, 1),
                k3: new ScoreThresholdsDTO(1, 1, 1),
            )),
        ];
        yield 'api non monotonic threshold' => [
            'API_OVERUSE k2 thresholds must be positive and monotonic',
            new ValidationPolicy('invalid-api-order', $api, $apiConfig, mode: 'FAIL_OPEN', thresholds: new PolicyThresholdsDTO(
                k1: new ScoreThresholdsDTO(1, 1, 1),
                k2: new ScoreThresholdsDTO(3, 2, 4),
                k3: new ScoreThresholdsDTO(1, 1, 1),
            )),
        ];
        yield 'api non positive access' => [
            'API_OVERUSE requires a positive access delta',
            new ValidationPolicy('invalid-api-access', $api, $apiConfig, mode: 'FAIL_OPEN', deltas: new ScoreDeltasDTO()),
        ];
    }

    private function builder(): RateLimiterBuilder
    {
        $clock = new FixedClock('2025-01-01 12:00:00');
        $store = new InMemoryRateLimitStore($clock);
        return new RateLimiterBuilder(
            new RateLimiterConfig('key-secret', 'fingerprint-secret', 'prod'),
            $store,
            new StatefulInMemoryCorrelationStore($clock),
            new InMemoryCircuitBreakerStore(),
            new RecordingFailureSignalEmitter(),
        );
    }
}

final class ValidationPolicy implements BlockPolicyInterface, PolicyCapabilityProviderInterface, FailureFallbackConfigurationProviderInterface
{
    /** @param list<PolicyCapability> $capabilities */
    public function __construct(
        private string $name,
        private array $capabilities,
        private FailureFallbackConfigurationDTO $configuration,
        private string $mode = 'FAIL_CLOSED',
        private ?BudgetConfigDTO $budget = new BudgetConfigDTO(20, 3),
        private ScoreDeltasDTO $deltas = new ScoreDeltasDTO(k2_missing_fp: 1, k4_failure: 1),
        private PolicyThresholdsDTO $thresholds = new PolicyThresholdsDTO(k4: new ScoreThresholdsDTO(1, 2, 3), k1: new ScoreThresholdsDTO(1, 2, 3), k2: new ScoreThresholdsDTO(1, 2, 3), k3: new ScoreThresholdsDTO(1, 2, 3)),
    ) {}

    public function getName(): string
    {
        return $this->name;
    }
    /** @return list<PolicyCapability> */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }
    public function getFailureFallbackConfiguration(): FailureFallbackConfigurationDTO
    {
        return $this->configuration;
    }
    public function getScoreThresholds(): PolicyThresholdsDTO
    {
        return $this->thresholds;
    }
    public function getScoreDeltas(): ScoreDeltasDTO
    {
        return $this->deltas;
    }
    public function getFailureMode(): string
    {
        return $this->mode;
    }
    public function getBudgetConfig(): ?BudgetConfigDTO
    {
        return $this->budget;
    }
}

final class NoConfigurationValidationPolicy implements BlockPolicyInterface, PolicyCapabilityProviderInterface
{
    /** @param list<PolicyCapability> $capabilities */
    public function __construct(private string $name, private array $capabilities, private string $mode = 'FAIL_CLOSED', private bool $api = false) {}
    public function getName(): string
    {
        return $this->name;
    }
    /** @return list<PolicyCapability> */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }
    public function getScoreThresholds(): PolicyThresholdsDTO
    {
        return $this->api
            ? new PolicyThresholdsDTO(k1: new ScoreThresholdsDTO(1, 2, 3), k2: new ScoreThresholdsDTO(1, 2, 3), k3: new ScoreThresholdsDTO(1, 2, 3))
            : new PolicyThresholdsDTO(k4: new ScoreThresholdsDTO(1, 2, 3));
    }
    public function getScoreDeltas(): ScoreDeltasDTO
    {
        return $this->api ? new ScoreDeltasDTO(access: 1) : new ScoreDeltasDTO(k2_missing_fp: 1, k4_failure: 1);
    }
    public function getFailureMode(): string
    {
        return $this->mode;
    }
    public function getBudgetConfig(): ?BudgetConfigDTO
    {
        return $this->api ? null : new BudgetConfigDTO(20, 3);
    }
}
