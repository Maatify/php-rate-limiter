<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Fixtures;

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

    private function replaceOnce(string $subject, string $search, string $replace): string
    {
        self::assertSame(1, substr_count($subject, $search), "Expected exactly one occurrence of: {$search}");

        return str_replace($search, $replace, $subject);
    }
}
