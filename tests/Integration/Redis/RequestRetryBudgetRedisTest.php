<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\RateLimitConcurrencyException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\OverlayWire;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * DEC-037 retry-budget accounting (#217): the first-conflict origin and the later samples of the monotonic clock are compared
 * as `hrtime()` returns them. On PHP with `PHP_INT_SIZE === 4` that value is a float (about 7 × 10^14 ns after eight days of uptime),
 * and a native-integer cast of it wraps, which made the elapsed time meaningless: the budget looked spent at once, or never.
 *
 * Both conflict paths run through the store's request entry point and through the public builder on an isolated Redis: a commit whose
 * state comparison fails, and an evaluation error over state that changed before the error was returned.
 */
#[Group('integration')]
#[Group('php32')]
final class RequestRetryBudgetRedisTest extends TestCase
{
    private const NOW = 1_793_793_600;

    private const NAMESPACE = 'retry-budget';

    private RespRedisCommandExecutor $executor;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->executor = new RespRedisCommandExecutor($host, (int) $port);
        $this->executor->execute(['FLUSHDB']);
    }

    public function testACommitThatLosesItsComparisonIsRetriedWhileTheBudgetRemainsAndOnlyTheWinningAttemptIsSaved(): void
    {
        [$executor, $probe] = $this->contention(commitConflicts: 6, verifyConflicts: 0);
        $store = new RedisFullCapabilityStore($executor, self::NAMESPACE, null, 600_000);
        $evaluations = 0;

        $store->transactRequest(static function () use ($store, &$evaluations): void {
            ++$evaluations;
            $store->incrementAt('retried', 60, 1, self::NOW);
        });

        self::assertSame(7, $probe->commits, 'Six lost commits, then the winning one.');
        self::assertSame(7, $evaluations);
        self::assertSame(1, $probe->commitsSaved, 'Only the winning attempt reached Redis.');
        self::assertSame(['1'], $this->scoreValues(), 'The six losing attempts saved nothing: the counter was incremented once.');
    }

    public function testAnEvaluationErrorOverStateThatChangedIsRetriedWhileTheBudgetRemainsAndThenAnsweredOverCurrentState(): void
    {
        [$executor, $probe] = $this->contention(commitConflicts: 0, verifyConflicts: 6);
        $store = new RedisFullCapabilityStore($executor, self::NAMESPACE, null, 600_000);
        $evaluations = 0;

        $thrown = null;
        try {
            $store->transactRequest(static function () use ($store, &$evaluations): void {
                ++$evaluations;
                $store->incrementAt('errored', 60, 1, self::NOW);

                throw new RateLimiterException('Classified answer over the current state.');
            });
        } catch (RateLimiterException $exception) {
            $thrown = $exception;
        }
        self::assertInstanceOf(RateLimiterException::class, $thrown, 'The error over current state is the request\'s answer.');
        self::assertSame('Classified answer over the current state.', $thrown->getMessage());

        self::assertSame(7, $evaluations, 'Six evaluations over state that changed, then one over current state.');
        self::assertSame(0, $probe->commits, 'An error is never committed.');
        self::assertSame([], $this->scoreValues(), 'No change of a losing attempt was saved.');
    }

    public function testAnEvaluationErrorOverUnchangedStateIsTheAnswerAtOnce(): void
    {
        [$executor, $probe] = $this->contention(commitConflicts: 0, verifyConflicts: 0);
        $store = new RedisFullCapabilityStore($executor, self::NAMESPACE, null, 600_000);
        $evaluations = 0;

        $thrown = null;
        try {
            $store->transactRequest(static function () use ($store, &$evaluations): void {
                ++$evaluations;
                $store->incrementAt('unchanged', 60, 1, self::NOW);

                throw new RateLimiterException('Answer over unchanged state.');
            });
        } catch (RateLimiterException $exception) {
            $thrown = $exception;
        }
        self::assertInstanceOf(RateLimiterException::class, $thrown);
        self::assertSame('Answer over unchanged state.', $thrown->getMessage());

        self::assertSame(1, $evaluations);
        self::assertSame(0, $probe->commits);
        self::assertSame([], $this->scoreValues());
    }

    public function testAConflictingCommitEndsAfterTheBudgetExpiresWithTheClassifiedResultAndSavesNothing(): void
    {
        [$executor, $probe] = $this->contention(commitConflicts: PHP_INT_MAX, verifyConflicts: 0);
        $store = new RedisFullCapabilityStore($executor, self::NAMESPACE, null, 40);
        $startedAt = hrtime(true);
        $thrown = null;

        try {
            $store->transactRequest(static function () use ($store): void {
                $store->incrementAt('expiring', 60, 1, self::NOW);
            });
        } catch (RateLimitConcurrencyException $exception) {
            // The existing classified result of DEC-029 and DEC-037.
            $thrown = $exception;
        }
        self::assertInstanceOf(RateLimitConcurrencyException::class, $thrown, 'The retries must end with the classified result.');
        $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        self::assertGreaterThanOrEqual(40.0, $elapsedMilliseconds, 'Retries last for the whole configured budget, not less.');
        self::assertGreaterThanOrEqual(2, $probe->commits, 'A request always gets a second evaluation.');
        self::assertLessThan(32, $probe->commits, 'The budget, not the attempt ceiling, ended the retries.');
        self::assertSame(0, $probe->commitsSaved);
        self::assertSame([], $this->scoreValues());
    }

    public function testAnEvaluationErrorOverStateThatKeepsChangingEndsAfterTheBudgetExpiresWithTheClassifiedResult(): void
    {
        [$executor, $probe] = $this->contention(commitConflicts: 0, verifyConflicts: PHP_INT_MAX);
        $store = new RedisFullCapabilityStore($executor, self::NAMESPACE, null, 40);
        $evaluations = 0;
        $startedAt = hrtime(true);
        $thrown = null;

        try {
            $store->transactRequest(static function () use ($store, &$evaluations): void {
                ++$evaluations;
                $store->incrementAt('expiring-error', 60, 1, self::NOW);

                throw new RateLimiterException('Never the answer while the state keeps changing.');
            });
        } catch (RateLimitConcurrencyException $exception) {
            // The same classified result as for a commit that keeps losing.
            $thrown = $exception;
        }
        self::assertInstanceOf(RateLimitConcurrencyException::class, $thrown, 'The retries must end with the classified result.');
        $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        self::assertGreaterThanOrEqual(40.0, $elapsedMilliseconds);
        self::assertGreaterThanOrEqual(2, $evaluations);
        self::assertLessThan(32, $evaluations);
        self::assertSame(0, $probe->commits);
        self::assertSame([], $this->scoreValues());
    }

    public function testThePublicRuntimeRetriesWithinTheBudgetAndThenSavesTheRequestOnce(): void
    {
        [$executor, $probe] = $this->contention(commitConflicts: 4, verifyConflicts: 0);
        $limiter = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('test_secret', 'test_fp', 'prod'),
            new RedisFullCapabilityStore($executor, self::NAMESPACE, null, 600_000),
            new RecordingFailureSignalEmitter(),
        )->withClock(new FixedClock('@' . self::NOW))->build();

        $result = $limiter->limit(new RateLimitContextDTO(ip: '192.0.2.1', ua: 'ua', accountId: 'account-one'), RateLimitCommand::checkOnly('login_protection'));

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision);
        self::assertSame(5, $probe->commits, 'Four lost commits, then the winning one.');
        self::assertSame(1, $probe->commitsSaved);
    }

    /**
     * An executor that makes the first `$commitConflicts` saves find the state moved (the commit script answers 0 and writes nothing) and
     * the first `$verifyConflicts` currency checks of an evaluation error answer that the state changed; later ones reach Redis.
     *
     * @return array{CallableRedisCommandExecutor, object{commits: int, commitsSaved: int}}
     */
    private function contention(int $commitConflicts, int $verifyConflicts): array
    {
        $probe = new class {
            public int $commits = 0;
            public int $commitsSaved = 0;
            public int $verifies = 0;
        };
        $executor = new CallableRedisCommandExecutor(function (array $command) use ($probe, $commitConflicts, $verifyConflicts): mixed {
            if (OverlayWire::isCommit($command)) {
                ++$probe->commits;
                if ($probe->commits <= $commitConflicts) {
                    return 0;
                }
                ++$probe->commitsSaved;

                return $this->executor->execute($command);
            }
            if ($this->isVerify($command)) {
                ++$probe->verifies;
                if ($probe->verifies <= $verifyConflicts) {
                    return 0;
                }
            }

            return $this->executor->execute($command);
        });

        return [$executor, $probe];
    }

    /** @param list<int|string|float> $command */
    private function isVerify(array $command): bool
    {
        $text = $command[1] ?? null;

        return $command[0] === 'EVAL' && is_string($text) && str_contains($text, 'request commit') && end($command) === 'verify';
    }

    /** @return list<string> the `value` field of every score hash, to show what the saved attempts wrote */
    private function scoreValues(): array
    {
        $keys = $this->executor->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        sort($keys);
        $values = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $value = $this->executor->execute(['HGET', $key, 'value']);
            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
