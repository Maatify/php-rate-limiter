<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\Contract\FailureSignalEmitterInterface;
use Maatify\RateLimiter\DTO\FailureSignalDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\RateLimiterInterface;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Redis\OverlayWire;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * T-5 (DEC-037), a Redis outage while a circuit recovers, through the public API. A request that recovers the circuit
 * is saved at once; when that save fails with a backend failure, nothing partial may remain, the probe lease and the
 * recovery signal must not be repeated, and a signal is emitted only after the state it announces is saved.
 */
#[Group('t5-request-atomicity')]
final class RequestAtomicityRecoveryOutageRedisTest extends TestCase
{
    private const NOW = 1_000_000;

    private const NAMESPACE = 't5-outage';

    /** The save fails before it reaches Redis (the request is lost). */
    private const LOST_REQUEST = 'lost request';

    /** The save reaches Redis and applies, but its reply is lost. */
    private const LOST_REPLY = 'lost reply';

    private static int $callers = 0;

    private RespRedisCommandExecutor $executor;

    /** @var \ArrayObject<int, array{string, ?string}> signal type and the circuit state stored in Redis when it was emitted */
    private \ArrayObject $emitted;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->executor = new RespRedisCommandExecutor($host, (int) $port);
        $this->executor->execute(['FLUSHDB']);
        $this->emitted = new \ArrayObject();
    }

    private function circuitKey(): ?string
    {
        $keys = $this->executor->execute(['KEYS', '*:circuit:*']);
        self::assertIsArray($keys);
        $key = $keys[0] ?? null;

        return is_string($key) ? $key : null;
    }

    private function storedCircuit(): ?string
    {
        $key = $this->circuitKey();
        if ($key === null) {
            return null;
        }
        $value = $this->executor->execute(['GET', $key]);

        return is_string($value) ? $value : null;
    }

    /** @return list<string> every key and its content, so two states can be compared whole */
    private function keyspace(): array
    {
        $keys = $this->executor->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        sort($keys);
        $state = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $dump = $this->executor->execute(['DUMP', $key]);
            $state[] = $key . '=' . (is_string($dump) ? bin2hex($dump) : '');
        }

        return $state;
    }

    private function limiter(CallableRedisCommandExecutor|RespRedisCommandExecutor $executor, int $now): RateLimiterInterface
    {
        $emitter = new class ($this) implements FailureSignalEmitterInterface {
            public function __construct(private readonly RequestAtomicityRecoveryOutageRedisTest $test) {}

            public function emit(FailureSignalDTO $signal): void
            {
                $this->test->recordSignal($signal->type);
            }
        };

        return RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig('test_secret', 'test_fp', 'prod'),
            new RedisFullCapabilityStore($executor, self::NAMESPACE),
            $emitter,
        )->withClock(new FixedClock('@' . $now))->build();
    }

    /** @return list<array{string, ?string}> */
    private function emittedSignals(): array
    {
        return array_values($this->emitted->getArrayCopy());
    }

    /** @internal called by the test's emitter */
    public function recordSignal(string $type): void
    {
        $this->emitted->append([$type, $this->storedCircuit()]);
    }

    /**
     * A caller no other request of the process has used: the local fallback limiter keeps its counters in static state
     * for the whole PHPUnit process, so a repeated caller would be limited by earlier tests.
     */
    private function context(string $account): RateLimitContextDTO
    {
        $run = ++self::$callers;

        return new RateLimitContextDTO(ip: '10.77.' . intdiv($run, 250) . '.' . ($run % 250 + 1), ua: 'redis-gate', accountId: $account . '-outage-' . $run);
    }


    /** An outage opens the circuit: three failed requests. */
    private function circuitOpen(): void
    {
        $outage = new CallableRedisCommandExecutor(function (array $command): mixed {
            if (OverlayWire::isScript($command, '__ov_result')) {
                throw new BackendFailureException('Test outage.');
            }

            return $this->executor->execute($command);
        });
        $command = RateLimitCommand::checkOnly('login_protection');
        for ($i = 0; $i < 3; $i++) {
            $this->limiter($outage, self::NOW + $i)->limit($this->context('account-' . $i), $command);
        }
        self::assertNotNull($this->circuitKey());
        $this->emitted->exchangeArray([]);
    }

    /** The open circuit, then the first probe past the open interval makes it HALF_OPEN. */
    private function circuitHalfOpen(): void
    {
        $this->circuitOpen();
        $this->limiter($this->executor, self::NOW + 400)->limit($this->context('account-step'), RateLimitCommand::checkOnly('login_protection'));
        $this->emitted->exchangeArray([]);
    }

    /** @return CallableRedisCommandExecutor an executor whose first save fails the given way */
    private function failingFirstSave(string $way, int &$saves): CallableRedisCommandExecutor
    {
        return new CallableRedisCommandExecutor(function (array $command) use ($way, &$saves): mixed {
            if (OverlayWire::isCommit($command)) {
                if (++$saves === 1) {
                    if ($way === self::LOST_REPLY) {
                        $this->executor->execute($command);
                    }
                    throw new BackendFailureException('Test outage during the save.');
                }
            }

            return $this->executor->execute($command);
        });
    }

    /** @return array<string, array{string}> */
    public static function ways(): array
    {
        return [self::LOST_REQUEST => [self::LOST_REQUEST], self::LOST_REPLY => [self::LOST_REPLY]];
    }

    /**
     * The probe that makes the circuit HALF_OPEN is answered without processing the request. When its save fails the step
     * is repeated on its own, lease and state in one save: the same answer, one lease, one state, nothing repeated or half saved.
     */
    #[DataProvider('ways')]
    public function testARecoveryStepWhoseSaveFailsIsRepeatedWithoutRepeatingItsEffects(string $way): void
    {
        $outage = new CallableRedisCommandExecutor(function (array $command): mixed {
            if (OverlayWire::isScript($command, '__ov_result')) {
                throw new BackendFailureException('Test outage.');
            }

            return $this->executor->execute($command);
        });
        $command = RateLimitCommand::checkOnly('login_protection');
        for ($i = 0; $i < 3; $i++) {
            $this->limiter($outage, self::NOW + $i)->limit($this->context('account-' . $i), $command);
        }
        $this->emitted->exchangeArray([]);
        $before = $this->keyspace();

        // Control: the same step without a fault, on a scratch copy of the keyspace.
        $control = $this->limiter($this->executor, self::NOW + 400)->limit($this->context('account-step'), $command);
        $controlState = $this->keyspace();
        $controlCircuit = $this->storedCircuit();
        $this->executor->execute(['FLUSHDB']);
        $this->restore($before);

        $saves = 0;
        $failing = $this->failingFirstSave($way, $saves);
        $result = $this->limiter($failing, self::NOW + 400)->limit($this->context('account-step'), $command);

        // A lost request is saved by the repeated step on its own (one more save); after a lost reply the lease is held, the
        // repeat changes nothing and sends no save.
        self::assertSame($way === self::LOST_REQUEST ? 2 : 1, $saves, 'The request save, and for a lost request the repeated step save.');
        self::assertEquals($control, $result, 'The answer is the one of a request without a fault.');
        self::assertSame($controlCircuit, $this->storedCircuit(), 'The circuit state is the one saved once.');
        self::assertSame(
            array_map(static fn(string $entry): string => explode('=', $entry)[0], $controlState),
            array_map(static fn(string $entry): string => explode('=', $entry)[0], $this->keyspace()),
            'The same keys exist: one lease, one circuit state, nothing half saved.',
        );
        self::assertCount(0, $this->emittedSignals(), 'No transition signal for a step that only moved to HALF_OPEN.');
        $this->assertOneLease($way);
    }

    private function assertOneLease(string $way): void
    {
        $leases = array_filter($this->keyspace(), static fn(string $entry): bool => str_contains($entry, ':probe:'));
        self::assertCount(1, $leases, $way . ': the probe lease was taken once.');
    }

    /**
     * Re-creates a keyspace captured by keyspace(): only the keys and contents are restored.
     *
     * @param list<string> $entries
     */
    private function restore(array $entries): void
    {
        foreach ($entries as $entry) {
            [$key, $hex] = explode('=', $entry, 2);
            if ($hex !== '') {
                $dump = hex2bin($hex);
                self::assertIsString($dump);
                $this->executor->execute(['RESTORE', $key, 0, $dump, 'REPLACE']);
            }
        }
    }

    /**
     * A request that would close the circuit and process itself fails at its save: nothing is saved, no recovery is
     * announced, and the next request recovers the circuit once, announcing it after the closed state is stored.
     */
    #[DataProvider('ways')]
    public function testARecoveryThatClosesTheCircuitAnnouncesItOnlyAfterItIsSaved(string $way): void
    {
        $this->circuitHalfOpen();
        $command = RateLimitCommand::checkOnly('login_protection');
        $halfOpen = $this->storedCircuit();
        self::assertNotNull($halfOpen);
        self::assertStringContainsString('HALF_OPEN', $halfOpen);
        $before = $this->keyspace();

        $saves = 0;
        $failing = $this->failingFirstSave($way, $saves);
        $first = $this->limiter($failing, self::NOW + 400 + 121)->limit($this->context('account-a'), $command);

        self::assertInstanceOf(RateLimitResultDTO::class, $first, 'The outage is answered by the failure mode, not thrown.');
        self::assertCount(0, $this->emittedSignals(), 'The failed save announced nothing.');
        if ($way === self::LOST_REQUEST) {
            self::assertSame($before, $this->keyspace(), 'A save that never reached Redis left nothing behind.');
            self::assertSame($halfOpen, $this->storedCircuit());
        } else {
            self::assertStringContainsString('CLOSED', (string) $this->storedCircuit(), 'A save that applied and lost its reply is complete, not partial.');
        }

        // The next request: a lost request recovers now (announced once, after the state is saved); after a lost reply the circuit is closed already.
        $this->limiter($this->executor, self::NOW + 400 + 122)->limit($this->context('account-b'), $command);
        $announced = $this->emittedSignals();
        $recoveries = [];
        foreach ($announced as $signal) {
            if ($signal[0] === FailureSignalDTO::TYPE_CB_RECOVERED) {
                $recoveries[] = $signal;
            }
        }
        if ($way === self::LOST_REQUEST) {
            self::assertCount(1, $recoveries, 'The recovery is announced once.');
            self::assertStringContainsString('CLOSED', (string) $recoveries[0][1], 'When it was announced the closed state was already stored.');
        } else {
            self::assertCount(0, $recoveries, 'The recovery that was saved before the reply was lost is not announced a second time.');
        }
        self::assertStringContainsString('CLOSED', (string) $this->storedCircuit());
    }

    /** @return array<string, array{string}> */
    public static function failuresInsideTheStep(): array
    {
        return [
            'the circuit state save' => ["redis.call('SET', KEYS[1], ARGV[1])"],
            'the probe lease' => ['malformed probe lease'],
        ];
    }

    /**
     * The probe lease and the circuit state change are saved together or not at all: a backend failure met by one of the
     * two steps, which the circuit breaker handles and goes on from, must not leave the other one stored alone.
     */
    #[DataProvider('failuresInsideTheStep')]
    public function testABackendFailureInsideTheRecoveryStepLeavesNoStandaloneLease(string $needle): void
    {
        $this->circuitOpen();
        $before = $this->keyspace();
        $failing = new CallableRedisCommandExecutor(function (array $command) use ($needle): mixed {
            if (OverlayWire::isScript($command, $needle)) {
                throw new BackendFailureException('Test outage inside the recovery step.');
            }

            return $this->executor->execute($command);
        });

        $result = $this->limiter($failing, self::NOW + 400)->limit($this->context('account-probe'), RateLimitCommand::checkOnly('login_protection'));

        self::assertInstanceOf(RateLimitResultDTO::class, $result);
        self::assertSame($before, $this->keyspace(), 'No probe lease and no circuit change was stored.');
        self::assertCount(0, $this->emittedSignals());
    }

    /**
     * The request save fails, the step is repeated on its own, and the save of that repeat fails too (before Redis, or
     * after it applied and the reply was lost): either nothing is stored or lease and state are both stored.
     */
    #[DataProvider('ways')]
    public function testAFailedSaveOfTheRepeatedStepStoresNothingOrBothParts(string $way): void
    {
        $this->circuitOpen();
        $before = $this->keyspace();
        $saves = 0;
        $failing = new CallableRedisCommandExecutor(function (array $command) use ($way, &$saves): mixed {
            if (OverlayWire::isCommit($command)) {
                $saves++;
                if ($saves === 1 || $way === self::LOST_REQUEST) {
                    throw new BackendFailureException('Test outage during the save.');
                }
                $reply = $this->executor->execute($command);
                throw new BackendFailureException('Test outage after the save: ' . json_encode($reply));
            }

            return $this->executor->execute($command);
        });

        $result = $this->limiter($failing, self::NOW + 400)->limit($this->context('account-probe'), RateLimitCommand::checkOnly('login_protection'));

        self::assertInstanceOf(RateLimitResultDTO::class, $result);
        self::assertSame(2, $saves, 'The request save, then the save of the repeated step.');
        $leases = array_filter($this->keyspace(), static fn(string $entry): bool => str_contains($entry, ':probe:'));
        if ($way === self::LOST_REQUEST) {
            self::assertSame($before, $this->keyspace(), 'Nothing was stored, in particular no lease alone.');
        } else {
            self::assertCount(1, $leases);
            self::assertStringContainsString('HALF_OPEN', (string) $this->storedCircuit(), 'The lease came with its circuit state.');
        }
    }
}
