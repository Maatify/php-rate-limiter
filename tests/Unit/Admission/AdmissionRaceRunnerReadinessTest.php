<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\Tests\Support\Admission\AdmissionRaceRunner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #228: the process harness releases its workers only after every one of them is ready, bounds every wait, keeps the stderr and the
 * exit status of a failing worker, and reaps every child on success and on failure. Stand-in workers record when they were ready and
 * when they were released; no Redis is involved, so the result does not depend on the speed of the machine or of the PHP width.
 */
#[Group('php32')]
final class AdmissionRaceRunnerReadinessTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/race-runner-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    /** @return list<array<string, mixed>> */
    private function launch(string $mode, int $processes, float $readyTimeout = 30.0, float $resultTimeout = 30.0): array
    {
        return AdmissionRaceRunner::launch(
            dirname(__DIR__, 2) . '/Support/Admission/race-stub-worker.php',
            fn(int $index): array => [$mode, (string) $index, $this->directory],
            $processes,
            $readyTimeout,
            $resultTimeout,
        );
    }

    /** @param array<string, mixed> $reply */
    private static function number(array $reply, string $key): float
    {
        $value = $reply[$key] ?? null;
        self::assertTrue(is_int($value) || is_float($value), $key . ' is a number.');

        return (float) $value;
    }

    private function assertNoChildSurvives(int $processes): void
    {
        for ($index = 0; $index < $processes; $index++) {
            $pid = (int) file_get_contents($this->directory . '/pid-' . $index);
            exec('kill -0 ' . $pid . ' 2>/dev/null', $output, $status);
            self::assertNotSame(0, $status, 'Worker ' . $index . ' (pid ' . $pid . ') was reaped.');
        }
    }

    public function testEverySixtyWorkersTakePartAndNoneRunsBeforeAllAreReady(): void
    {
        $replies = $this->launch('ok', 60);

        self::assertCount(60, $replies);
        self::assertSame(range(0, 59), array_map(static fn(array $reply): int => (int) self::number($reply, 'index'), $replies), 'Every intended worker took part, once.');
        $lastReady = max(array_map(static fn(array $reply): float => self::number($reply, 'readyAt'), $replies) ?: [0.0]);
        $firstReleased = min(array_map(static fn(array $reply): float => self::number($reply, 'releasedAt'), $replies) ?: [0.0]);
        self::assertGreaterThanOrEqual($lastReady, $firstReleased, 'The first release is not earlier than the moment the last worker was ready.');
        $this->assertNoChildSurvives(60);
    }

    public function testASlowWorkerHoldsBackEveryoneUntilItIsReady(): void
    {
        $replies = $this->launch('slow-first', 8);

        $slowReady = self::number($replies[0], 'readyAt');
        foreach ($replies as $reply) {
            self::assertGreaterThanOrEqual($slowReady, self::number($reply, 'releasedAt'), 'Worker ' . (int) self::number($reply, 'index') . ' waited for the slowest worker.');
        }
        self::assertGreaterThan(1.0, $slowReady - min(array_map(static fn(array $reply): float => self::number($reply, 'readyAt'), $replies) ?: [0.0]), 'The slow worker really was late.');
        $this->assertNoChildSurvives(8);
    }

    public function testAWorkerThatDiesBeforeItIsReadyFailsTheRunWithItsDiagnosticsAndNothingSurvives(): void
    {
        try {
            $this->launch('die-before-ready', 6);
            self::fail('The run must fail.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Race worker 1 ended before it was ready', $exception->getMessage());
            self::assertStringContainsString('exit=7', $exception->getMessage());
            self::assertStringContainsString('stub setup failed on purpose', $exception->getMessage());
        }
        $this->assertNoChildSurvives(6);
    }

    public function testAWorkerThatIsNeverReadyIsBoundedAndEveryChildIsReaped(): void
    {
        $started = microtime(true);
        try {
            $this->launch('never-ready', 6, 2.0);
            self::fail('The run must fail.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('not ready within 2', $exception->getMessage());
            self::assertStringContainsString('1', $exception->getMessage());
        }
        self::assertLessThan(15.0, microtime(true) - $started, 'The wait is bounded by the readiness timeout.');
        $this->assertNoChildSurvives(6);
    }

    public function testAWorkerThatHangsAfterTheReleaseIsBoundedAndEveryChildIsReaped(): void
    {
        $started = microtime(true);
        try {
            $this->launch('hang-after-go', 6, 30.0, 2.0);
            self::fail('The run must fail.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('did not answer within 2', $exception->getMessage());
        }
        self::assertLessThan(15.0, microtime(true) - $started, 'The collection is bounded by the result timeout.');
        $this->assertNoChildSurvives(6);
    }

    public function testAWorkerThatFailsAfterTheReleaseReportsItsExitStatusAndStderr(): void
    {
        try {
            $this->launch('exit-after-go', 6);
            self::fail('The run must fail.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Race worker 1 failed (exit 5)', $exception->getMessage());
            self::assertStringContainsString('stub failed after the release', $exception->getMessage());
        }
        $this->assertNoChildSurvives(6);
    }
}
