<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Fixtures;

use Maatify\RateLimiter\Enum\PolicyCapabilityEnum;
use Maatify\RateLimiter\Tests\Fixtures\S0Baseline\S0LoginProtectionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Proves that tests/Fixtures/S0Baseline/S0EvaluationPipeline.php is the
 * shipped S0 pipeline of main@4d37be5 (src/Service/EvaluationPipeline.php,
 * sha256 4b259d33…) with only a mechanical, reversible rename, so the
 * mixed-version / rollback tests replay the real old worker (F117-06).
 */
final class S0BaselineFixtureProvenanceTest extends TestCase
{
    private const S0_PIPELINE_SHA256 = '4b259d3397adf492d9767572141c9bdf5efc5744886bce63592ae9f36b95f4fc';
    private const S0_LOGIN_POLICY_SNAPSHOT_SHA256 = '9f44eff3f22c2415796136a53443a1e4a0676c3f230ed87546be8a509e661f85';

    public function testFixtureReversesToTheShippedS0PipelineByteForByte(): void
    {
        $fixture = file_get_contents(dirname(__DIR__, 2) . '/Fixtures/S0Baseline/S0EvaluationPipeline.php');
        self::assertIsString($fixture);

        $original = $this->replaceOnce($fixture, "\n// S0 baseline fixture (main@4d37be5); provenance: S0BaselineFixtureProvenanceTest.\n", '');
        $original = $this->replaceOnce($original, "namespace Maatify\\RateLimiter\\Tests\\Fixtures\\S0Baseline;\n", "namespace Maatify\\RateLimiter\\Service;\n");
        $original = $this->replaceOnce($original, "use Maatify\\RateLimiter\\Service\\BoundedCorrelationResultValidator;\n", '');
        $original = $this->replaceOnce($original, "\nclass S0EvaluationPipeline\n", "\nclass EvaluationPipeline\n");

        self::assertSame(self::S0_PIPELINE_SHA256, hash('sha256', $original));
    }

    /**
     * S0LoginProtectionPolicy is a compatibility subclass of the current
     * Login preset, not a copy of the S0 source. Its effective configuration
     * is pinned instead: this snapshot hash was taken from the real
     * LoginProtectionPolicy of main@4d37be5.
     */
    public function testS0LoginPolicyFixtureMatchesTheShippedS0Configuration(): void
    {
        $policy = new S0LoginProtectionPolicy();
        $snapshot = json_encode([
            'name' => $policy->getName(),
            'thresholds' => $policy->getScoreThresholds(),
            'deltas' => $policy->getScoreDeltas(),
            'mode' => $policy->getFailureMode(),
            'budget' => $policy->getBudgetConfig(),
            'capabilities' => array_map(static fn(PolicyCapabilityEnum $capability): string => $capability->name, $policy->getCapabilities()),
            'fallback' => $policy->getFailureFallbackConfiguration(),
        ], JSON_THROW_ON_ERROR);

        self::assertSame(self::S0_LOGIN_POLICY_SNAPSHOT_SHA256, hash('sha256', $snapshot . "\n"));
        self::assertNull($policy->getKnownDeviceAccountAllowance());
    }

    private function replaceOnce(string $subject, string $search, string $replace): string
    {
        self::assertSame(1, substr_count($subject, $search), "Expected exactly one occurrence of: {$search}");

        return str_replace($search, $replace, $subject);
    }
}
