<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\Tests\Support\Admission\AdmissionRaceRunner;
use Maatify\RateLimiter\Tests\Support\Admission\RaceRunFailure;
use Maatify\RateLimiter\Tests\Support\Clock\MonotonicClock;
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

    /**
     * Every child the runner started is gone. The pids are the runner's own (from `proc_open()` at the spawn, carried by `RaceRunFailure`, or
     * reported by a worker that succeeded), never a file a child wrote: a sibling killed while it publishes one leaves an empty file, and a
     * check that skipped such files would stop proving that nothing survives.
     *
     * @param list<int> $pids
     */
    private function assertNoChildSurvives(array $pids, int $processes): void
    {
        self::assertCount($processes, $pids, 'Every worker the runner started is accounted for.');
        self::assertCount($processes, array_unique($pids), 'Each has its own pid.');
        foreach ($pids as $pid) {
            self::assertGreaterThan(0, $pid);
            exec('kill -0 ' . $pid . ' 2>/dev/null', $output, $status);
            self::assertNotSame(0, $status, 'Worker pid ' . $pid . ' was reaped.');
        }
    }

    /** @return list<int> the pids of the children of a run that failed */
    private function failedRunPids(string $mode, int $processes, float $readyTimeout = 30.0, float $resultTimeout = 30.0): array
    {
        try {
            $this->launch($mode, $processes, $readyTimeout, $resultTimeout);
        } catch (RaceRunFailure $failure) {
            $this->lastMessage = $failure->getMessage();

            return $failure->pids;
        }
        self::fail('The run must fail.');
    }

    private string $lastMessage = '';

    /**
     * @param list<array<string, mixed>> $replies
     * @return list<int>
     */
    private static function pidsOf(array $replies): array
    {
        return array_map(static fn(array $reply): int => (int) self::number($reply, 'pid'), $replies);
    }

    public function testEverySixtyWorkersTakePartAndNoneRunsBeforeAllAreReady(): void
    {
        $replies = $this->launch('ok', 60);

        self::assertCount(60, $replies);
        self::assertSame(range(0, 59), array_map(static fn(array $reply): int => (int) self::number($reply, 'index'), $replies), 'Every intended worker took part, once.');
        $lastReady = max(array_map(static fn(array $reply): float => self::number($reply, 'readyAt'), $replies) ?: [0.0]);
        $firstReleased = min(array_map(static fn(array $reply): float => self::number($reply, 'releasedAt'), $replies) ?: [0.0]);
        self::assertGreaterThanOrEqual($lastReady, $firstReleased, 'The first release is not earlier than the moment the last worker was ready.');
        $this->assertNoChildSurvives(self::pidsOf($replies), 60);
    }

    public function testASlowWorkerHoldsBackEveryoneUntilItIsReady(): void
    {
        $replies = $this->launch('slow-first', 8);

        $slowReady = self::number($replies[0], 'readyAt');
        foreach ($replies as $reply) {
            self::assertGreaterThanOrEqual($slowReady, self::number($reply, 'releasedAt'), 'Worker ' . (int) self::number($reply, 'index') . ' waited for the slowest worker.');
        }
        self::assertGreaterThan(1.0, $slowReady - min(array_map(static fn(array $reply): float => self::number($reply, 'readyAt'), $replies) ?: [0.0]), 'The slow worker really was late.');
        $this->assertNoChildSurvives(self::pidsOf($replies), 8);
    }

    public function testAWorkerThatDiesBeforeItIsReadyFailsTheRunWithItsDiagnosticsAndNothingSurvives(): void
    {
        $pids = $this->failedRunPids('die-before-ready', 6);

        self::assertStringContainsString('Race worker 1 ended before it was ready', $this->lastMessage);
        self::assertStringContainsString('exit=7', $this->lastMessage);
        self::assertStringContainsString('stub setup failed on purpose', $this->lastMessage);
        $this->assertNoChildSurvives($pids, 6);
    }

    /**
     * #228 (F-G9-PID-01): worker 0 is still inside its startup window, nothing of it published, when worker 1 fails; the runner kills it
     * together with the others. The cleanup is judged against the pids the runner recorded, so the interrupted sibling is counted.
     */
    public function testASiblingStillStartingWhenAnotherWorkerDiesIsKilledAndAccountedFor(): void
    {
        $pids = $this->failedRunPids('sibling-in-startup', 6);

        self::assertFileExists($this->directory . '/starting-0', 'Worker 0 was inside its startup window when worker 1 failed.');
        self::assertStringContainsString('Race worker 1 ended before it was ready', $this->lastMessage);
        $this->assertNoChildSurvives($pids, 6);
    }

    public function testAWorkerThatIsNeverReadyIsBoundedAndEveryChildIsReaped(): void
    {
        $started = MonotonicClock::nanoseconds();
        $pids = $this->failedRunPids('never-ready', 6, 2.0);

        self::assertStringContainsString('not ready within 2', $this->lastMessage);
        self::assertLessThan(15.0, MonotonicClock::elapsedSeconds($started), 'The wait is bounded by the readiness timeout.');
        $this->assertNoChildSurvives($pids, 6);
    }

    public function testAWorkerThatHangsAfterTheReleaseIsBoundedAndEveryChildIsReaped(): void
    {
        $started = MonotonicClock::nanoseconds();
        $pids = $this->failedRunPids('hang-after-go', 6, 30.0, 2.0);

        self::assertStringContainsString('did not exit within 2', $this->lastMessage);
        self::assertLessThan(15.0, MonotonicClock::elapsedSeconds($started), 'The collection is bounded by the result timeout.');
        $this->assertNoChildSurvives($pids, 6);
    }

    public function testAWorkerThatClosesItsOutputButStaysAliveIsBoundedKilledAndReaped(): void
    {
        $started = MonotonicClock::nanoseconds();
        $pids = $this->failedRunPids('close-output-then-hang', 6, 30.0, 1.0);

        self::assertStringContainsString('did not exit within 1', $this->lastMessage);
        self::assertStringContainsString('\\"ok\\":true', $this->lastMessage, 'The output it had already written is kept in the diagnostics (JSON-escaped).');
        self::assertLessThan(15.0, MonotonicClock::elapsedSeconds($started), 'The deadline holds for a live child after its output ended.');
        $this->assertNoChildSurvives($pids, 6);
    }

    public function testAWorkerThatClosesItsOutputAndThenExitsInTimeStillSucceeds(): void
    {
        $replies = $this->launch('close-output-then-exit', 6, 30.0, 10.0);

        self::assertCount(6, $replies);
        $this->assertNoChildSurvives(self::pidsOf($replies), 6);
    }

    public function testAWorkerThatFailsAfterTheReleaseReportsItsExitStatusAndStderr(): void
    {
        $pids = $this->failedRunPids('exit-after-go', 6);

        self::assertStringContainsString('Race worker 1 failed (exit 5)', $this->lastMessage);
        self::assertStringContainsString('stub failed after the release', $this->lastMessage);
        $this->assertNoChildSurvives($pids, 6);
    }
}
