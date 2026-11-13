<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * T-5 (DEC-037), the default retry limit (32) and time budget (500 ms) under contention: many processes send public
 * requests for one hot account at the same instant. The request time must stay within the budget and a request must
 * not end as a concurrency block more often than a small share.
 */
#[Group('t5-request-atomicity')]
final class RequestAtomicityDefaultBudgetRedisTest extends TestCase
{
    private const WORKERS = 8;

    private const REQUESTS_PER_WORKER = 12;

    /** The budget counts from the first conflict; one evaluation and a commit may follow it. */
    private const REQUEST_TIME_LIMIT_MILLISECONDS = 500 + 1500;

    private const CONFLICT_BLOCK_SHARE_LIMIT = 0.10;

    /**
     * The two output pipes of a started worker; the shape of what proc_open fills in is checked, not assumed.
     *
     * @return array{resource, resource}
     */
    private static function pipes(mixed $pipes): array
    {
        self::assertIsArray($pipes);
        self::assertArrayHasKey(1, $pipes);
        self::assertArrayHasKey(2, $pipes);
        self::assertIsResource($pipes[1]);
        self::assertIsResource($pipes[2]);

        return [$pipes[1], $pipes[2]];
    }

    /** @return array<string, array{string}> */
    public static function modes(): array
    {
        return ['recorded failures' => ['record'], 'check only' => ['check']];
    }

    #[DataProvider('modes')]
    public function testTheDefaultBudgetKeepsRequestTimeBoundedAndConcurrencyBlocksRare(string $mode): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        (new RespRedisCommandExecutor($host, (int) $port))->execute(['FLUSHDB']);

        $barrier = microtime(true) + 1.0;
        /** @var list<array{resource, resource, resource}> $workers */
        $workers = [];
        for ($i = 0; $i < self::WORKERS; $i++) {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, dirname(__DIR__, 2) . '/Support/Redis/default-budget-worker.php', $host, $port, sprintf('%.6F', $barrier), (string) self::REQUESTS_PER_WORKER, $mode],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            $opened = self::pipes($pipes);
            $workers[] = [$process, $opened[0], $opened[1]];
        }

        $requests = 0;
        $conflicts = 0;
        $errors = 0;
        $milliseconds = [];
        foreach ($workers as [$process, $stdout, $stderr]) {
            $output = stream_get_contents($stdout);
            $diagnostics = stream_get_contents($stderr);
            fclose($stdout);
            fclose($stderr);
            self::assertSame(0, proc_close($process), (string) $diagnostics);
            self::assertIsString($output);
            $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($report);
            self::assertIsInt($report['requests']);
            self::assertIsInt($report['conflicts']);
            self::assertIsInt($report['errors']);
            self::assertIsArray($report['milliseconds']);
            $requests += $report['requests'];
            $conflicts += $report['conflicts'];
            $errors += $report['errors'];
            foreach ($report['milliseconds'] as $value) {
                self::assertIsFloat($value);
                $milliseconds[] = $value;
            }
        }

        self::assertNotEmpty($milliseconds);
        sort($milliseconds);
        self::assertSame(self::WORKERS * self::REQUESTS_PER_WORKER, $requests);
        self::assertSame(0, $errors, 'Every request answered with a decision.');
        self::assertLessThan(self::REQUEST_TIME_LIMIT_MILLISECONDS, $milliseconds[count($milliseconds) - 1], 'The slowest request stayed within the time budget plus one evaluation.');
        self::assertLessThanOrEqual(self::CONFLICT_BLOCK_SHARE_LIMIT, $conflicts / $requests, 'Concurrency blocks are rare under the default budget.');
    }
}
