<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PackageStructureTest extends TestCase
{
    public function testCanonicalSourceRootsAreTheOnlyDirectRuntimeResponsibilities(): void
    {
        $sourceRoot = dirname(__DIR__, 2) . '/src';
        $entries = scandir($sourceRoot);
        if ($entries === false) {
            self::fail('Unable to inspect the package source root.');
        }

        $actualRoots = array_values(array_filter(
            $entries,
            static fn(string $entry): bool => $entry !== '.' && $entry !== '..' && is_dir($sourceRoot . '/' . $entry),
        ));
        sort($actualRoots);

        self::assertSame(
            ['Command', 'Config', 'Contract', 'DTO', 'Exception', 'Repository', 'Service'],
            $actualRoots,
        );

        foreach (['Engine', 'Device', 'Penalty', 'Policy'] as $forbiddenRoot) {
            self::assertDirectoryDoesNotExist($sourceRoot . '/' . $forbiddenRoot);
        }

        foreach (['Internal', 'Store'] as $forbiddenDtoRoot) {
            self::assertDirectoryDoesNotExist($sourceRoot . '/DTO/' . $forbiddenDtoRoot);
        }
    }

    public function testRelocatedPublicRuntimeTypesAreAutoloadable(): void
    {
        $classes = [
            'Maatify\\RateLimiter\\Config\\BlockPolicyInterface',
            'Maatify\\RateLimiter\\Config\\LoginProtectionPolicy',
            'Maatify\\RateLimiter\\Config\\OtpProtectionPolicy',
            'Maatify\\RateLimiter\\Config\\ApiHeavyProtectionPolicy',
            'Maatify\\RateLimiter\\Repository\\RateLimitStoreInterface',
            'Maatify\\RateLimiter\\Repository\\BudgetSeedStoreInterface',
            'Maatify\\RateLimiter\\Repository\\CorrelationStoreInterface',
            'Maatify\\RateLimiter\\Repository\\BoundedCorrelationStoreInterface',
            'Maatify\\RateLimiter\\Repository\\BoundedCorrelationRotationStoreInterface',
            'Maatify\\RateLimiter\\Repository\\CircuitBreakerStoreInterface',
            'Maatify\\RateLimiter\\Repository\\CircuitBreakerProbeStoreInterface',
            'Maatify\\RateLimiter\\Service\\RateLimiterInterface',
            'Maatify\\RateLimiter\\Service\\DeviceIdentityResolverInterface',
            'Maatify\\RateLimiter\\Service\\RateLimitOperationalReaderInterface',
            'Maatify\\RateLimiter\\Service\\RateLimitOperationalReader',
            'Maatify\\RateLimiter\\Service\\RateLimiterEngine',
            'Maatify\\RateLimiter\\Service\\EvaluationPipeline',
            'Maatify\\RateLimiter\\Service\\BoundedCorrelationResultValidator',
            'Maatify\\RateLimiter\\Service\\CircuitBreaker',
            'Maatify\\RateLimiter\\Service\\FailureModeResolver',
            'Maatify\\RateLimiter\\Service\\LocalFallbackLimiter',
            'Maatify\\RateLimiter\\Service\\DeviceIdentityResolver',
            'Maatify\\RateLimiter\\Service\\EphemeralBucket',
            'Maatify\\RateLimiter\\Service\\FingerprintHasher',
            'Maatify\\RateLimiter\\Service\\AntiEquilibriumGate',
            'Maatify\\RateLimiter\\Service\\BudgetTracker',
            'Maatify\\RateLimiter\\Service\\DecayCalculator',
            'Maatify\\RateLimiter\\Service\\PenaltyLadder',
            'Maatify\\RateLimiter\\DTO\\PipelineScoreDTO',
            'Maatify\\RateLimiter\\DTO\\BoundedDistinctResultDTO',
            'Maatify\\RateLimiter\\DTO\\BoundedCorrelationObservationDTO',
            'Maatify\\RateLimiter\\DTO\\BlockStateDTO',
            'Maatify\\RateLimiter\\DTO\\BudgetStateDTO',
            'Maatify\\RateLimiter\\DTO\\CircuitBreakerStateDTO',
            'Maatify\\RateLimiter\\DTO\\RateLimitStateDTO',
            'Maatify\\RateLimiter\\DTO\\RateLimitOperationalKeyStateDTO',
            'Maatify\\RateLimiter\\DTO\\RateLimitOperationalScopesDTO',
            'Maatify\\RateLimiter\\DTO\\RateLimitOperationalBudgetDTO',
            'Maatify\\RateLimiter\\DTO\\RateLimitOperationalSnapshotDTO',
        ];

        foreach ($classes as $class) {
            self::assertTrue($this->runtimeTypeExists($class), $class . ' must autoload.');
        }
    }

    public function testRelocatedTypesDoNotRemainUnderOldRuntimeNamespaces(): void
    {
        $oldTypes = [
            'Maatify\\RateLimiter\\Contract\\BlockPolicyInterface',
            'Maatify\\RateLimiter\\Contract\\RateLimitStoreInterface',
            'Maatify\\RateLimiter\\Contract\\BudgetSeedStoreInterface',
            'Maatify\\RateLimiter\\Contract\\CorrelationStoreInterface',
            'Maatify\\RateLimiter\\Contract\\CircuitBreakerStoreInterface',
            'Maatify\\RateLimiter\\Contract\\RateLimiterInterface',
            'Maatify\\RateLimiter\\Contract\\DeviceIdentityResolverInterface',
            'Maatify\\RateLimiter\\Policy\\LoginProtectionPolicy',
            'Maatify\\RateLimiter\\Policy\\OtpProtectionPolicy',
            'Maatify\\RateLimiter\\Policy\\ApiHeavyProtectionPolicy',
            'Maatify\\RateLimiter\\Engine\\RateLimiterEngine',
            'Maatify\\RateLimiter\\Engine\\EvaluationPipeline',
            'Maatify\\RateLimiter\\Engine\\CircuitBreaker',
            'Maatify\\RateLimiter\\Engine\\FailureModeResolver',
            'Maatify\\RateLimiter\\Engine\\LocalFallbackLimiter',
            'Maatify\\RateLimiter\\Device\\DeviceIdentityResolver',
            'Maatify\\RateLimiter\\Device\\EphemeralBucket',
            'Maatify\\RateLimiter\\Device\\FingerprintHasher',
            'Maatify\\RateLimiter\\Penalty\\AntiEquilibriumGate',
            'Maatify\\RateLimiter\\Penalty\\BudgetTracker',
            'Maatify\\RateLimiter\\Penalty\\DecayCalculator',
            'Maatify\\RateLimiter\\Penalty\\PenaltyLadder',
            'Maatify\\RateLimiter\\DTO\\Internal\\PipelineScoreDTO',
            'Maatify\\RateLimiter\\DTO\\Store\\BlockStateDTO',
            'Maatify\\RateLimiter\\DTO\\Store\\BudgetStateDTO',
            'Maatify\\RateLimiter\\DTO\\Store\\CircuitBreakerStateDTO',
            'Maatify\\RateLimiter\\DTO\\Store\\RateLimitStateDTO',
        ];

        foreach ($oldTypes as $oldType) {
            self::assertFalse($this->runtimeTypeExists($oldType), $oldType . ' must not remain autoloadable.');
        }
    }

    private function runtimeTypeExists(string $type): bool
    {
        return class_exists($type) || interface_exists($type);
    }
}
