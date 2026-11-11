<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Service\AdmissionFenceControlInterface;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionRaceRunner;
use Maatify\RateLimiter\Tests\Support\Redis\OverlayWire;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionRaceSpec;
use Maatify\RateLimiter\Tests\Support\Admission\PublicRedisAdmissionFixture;
use Maatify\RateLimiter\Tests\Support\Admission\ReplyValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** Redis resilience and source-capacity contracts through the package builder and public runtime. */
#[Group('integration')]
final class AdmissionPublicRuntimeRedisTest extends AdmissionRedisGateTestCase
{
    private const NOW = 1000000;
    private const POLICY = 'login_protection';

    /** @return iterable<string, array{string, int, int}> */
    public static function interruptions(): iterable
    {
        // T-5: one request is one atomic save, so an interrupted request leaves no score prefix: the keys saved are always 0.
        yield 'before admission commit' => ['before-admission', 0, 0];
        yield 'lost admission commit reply' => ['after-admission', 0, 0];
        yield 'lost first score step reply' => ['after-score', 0, 1];
    }

    #[DataProvider('interruptions')]
    public function testOutcomeRetryAfterRedisInterruption(string $phase, int $expectedScoreKeys, int $expectedScoreSteps): void
    {
        $probe = new class {
            public bool $armed = false;
            public int $faults = 0;
            public int $scoreWrites = 0;
        };
        $adapter = new CallableRedisCommandExecutor(function (array $command) use ($phase, $probe): mixed {
            $script = $command[0] === 'EVAL' ? (string) ($command[1] ?? '') : '';
            $claim = str_contains($script, 'local outcomeKind = ARGV[5]');
            $score = str_contains($script, "pcall(redis.call, 'HINCRBY', KEYS[1], 'value', ARGV[2])");
            $dropReply = $probe->armed && (($claim && $phase === 'after-admission') || ($score && $phase === 'after-score'));
            if ($probe->armed && $claim && $phase === 'before-admission') {
                $probe->armed = false;
                $probe->faults++;
                throw new BackendFailureException('Test interruption before sending admission command.');
            }

            $reply = $this->executor->execute($command);
            if ($score) {
                $probe->scoreWrites++;
            }
            if ($dropReply) {
                $probe->armed = false;
                $probe->faults++;
                throw new BackendFailureException('Test interruption after Redis executed the command.');
            }

            return $reply;
        });
        $runtime = PublicRedisAdmissionFixture::build($adapter, self::NAMESPACE, self::NOW);
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $runtime);
        $runtime->openAdmissionFence('login');
        $context = new RateLimitContextDTO(ip: '192.0.2.10', ua: 'redis-gate', accountId: 'redis-gate-account');
        $check = $runtime->limit($context, RateLimitCommand::checkOnly(self::POLICY));
        self::assertTrue($check->isAllowed());
        $permit = $check->metadata?->permit;
        self::assertNotNull($permit);
        $command = new RateLimitCommand(self::POLICY, 1, false, true, false, $permit);
        self::assertSame('ISSUED', $this->permitState());
        $before = $this->outcomeState();
        $probe->armed = true;

        $interrupted = $runtime->limit($context, $command);
        self::assertSame(1, $probe->faults);
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $interrupted->decision);
        self::assertSame('FAIL_CLOSED', $interrupted->failureMode);
        self::assertSame($expectedScoreSteps, $probe->scoreWrites);
        self::assertSame($phase === 'before-admission' ? 'ISSUED' : 'CLAIMED', $this->permitState());
        $after = $this->outcomeState();
        self::assertCount($expectedScoreKeys, array_filter(array_keys($after), static fn(string $key): bool => str_contains($key, ':score:')));

        $retry = $runtime->limit($context, $command);
        self::assertSame(1, $probe->faults);
        self::assertSame('CLAIMED', $this->permitState());
        if ($phase === 'before-admission') {
            self::assertSame($before, $after);
            self::assertSame('NORMAL', $retry->failureMode);
            self::assertNotSame('permit_duplicate', $retry->metadata?->cause);
            $completed = $this->outcomeState();
            self::assertGreaterThan(1, count(array_filter(array_keys($completed), static fn(string $key): bool => str_contains($key, ':score:'))), 'A complete outcome must persist more than the interrupted score prefix.');
            self::assertNotSame($before, $completed);
        } else {
            self::assertNotSame($before, $after);
            self::assertSame('permit_duplicate', $retry->metadata?->cause);
            self::assertSame($expectedScoreSteps, $probe->scoreWrites);
            self::assertSame($after, $this->outcomeState());
        }
    }

    public function testUnknownLaneSourceCapacityUnderParallelPublicChecks(): void
    {
        $runtime = PublicRedisAdmissionFixture::build($this->executor, self::NAMESPACE, self::NOW);
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $runtime);
        $runtime->openAdmissionFence('login');
        $results = AdmissionRaceRunner::run($this->host, $this->port, 60, new AdmissionRaceSpec(
            self::NAMESPACE,
            'public_check',
            'store',
            self::NOW,
        ));

        self::assertSame(24, AdmissionRaceRunner::admitted($results));
        $refusals = array_filter($results, static fn(array $result): bool => ! $result['ok']);
        self::assertCount(36, $refusals);
        foreach ($refusals as $refusal) {
            self::assertSame('source_capacity', $refusal['refused']);
        }
        $keys = $this->raw('KEYS', substr($this->key(AdmissionScopeKindEnum::COUNTER, 'unused'), 0, -64) . '*');
        self::assertIsArray($keys);
        self::assertCount(1, $keys);
        $key = array_values($keys)[0];
        self::assertIsString($key);
        self::assertSame(24, ReplyValue::integer($this->raw('ZCARD', $key)));
    }

    private function permitState(): mixed
    {
        $keys = $this->raw('KEYS', substr($this->keys->permitKey('unused'), 0, -64) . '*');
        self::assertIsArray($keys);
        self::assertCount(1, $keys);
        $key = array_values($keys)[0];
        self::assertIsString($key);

        return $this->raw('HGET', $key, 'state');
    }

    /** @return array<string, string> Serialized Redis state, excluding circuit breaker bookkeeping. */
    private function outcomeState(): array
    {
        $state = [];
        foreach (array_keys($this->dump()) as $key) {
            if (str_contains($key, ':adm:') || str_contains($key, ':score:')) {
                $dump = $this->raw('DUMP', $key);
                self::assertIsString($dump);
                $state[$key] = $dump;
            }
        }
        ksort($state);

        return $state;
    }
}
