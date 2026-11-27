<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Fiber;
use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\DTO\AdmissionPermitDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\DTO\FailureSignalDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionPermitClaimEnum;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntime;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Service\RequestScopedClock;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\OverlayWire;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #260 (DEC-041): independent calls that overlap on one runtime graph or on a store it shares are REFUSED, with Fibers as the deterministic
 * scheduler. These are intended-behaviour tests: they replace the characterization of the baseline (`main@316b1de9`, kept in the history of
 * PR #266 at `515b09f0` and in its logs), which recorded the silent loss and the cross-call effects this decision removes.
 *
 * Harness: every Redis command is a whole round trip on a sound connection; a Fiber is suspended only BETWEEN commands (or inside a Host
 * callback, where the case says so), so no connection is ever interleaved at the byte level. Each store owns its connection and the
 * oracle reads over another one, with exact DUMP and PEXPIRETIME. The resumption schedule is explicit; nothing sleeps. The refusal is
 * identified by the class name as a string so that the same file runs against the baseline source, where no refusal exists and the
 * assertions fail for the intended reason.
 */
#[Group('integration')]
#[Group('fiber-ownership-260')]
#[Group('php32')]
final class FiberRequestOwnershipRedisTest extends TestCase
{
    private const T0 = 1_000_000;

    private const T1 = 2_000_000;

    private RespRedisCommandExecutor $oracle;

    /** @var (callable(array<mixed>, string): void)|null */
    private $hook = null;

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->oracle = new RespRedisCommandExecutor($host, (int) $port);
        $this->oracle->execute(['FLUSHDB']);
        $this->hook = null;
    }

    /** @param array<mixed> $command */
    private static function isScript(array $command, string $script): bool
    {
        /** @var list<int|string|float> $command */
        return OverlayWire::isScript($command, $script);
    }

    /** @param array<mixed> $command */
    private static function isCommit(array $command): bool
    {
        /** @var list<int|string|float> $command */
        return OverlayWire::isCommit($command);
    }

    /** @return list<string> */
    private function keyNames(): array
    {
        $keys = $this->oracle->execute(['KEYS', '*']);
        self::assertIsArray($keys);
        $names = [];
        foreach ($keys as $key) {
            self::assertIsString($key);
            $names[] = $key;
        }
        sort($names);

        return $names;
    }

    /** @return array<string, string> key => exact DUMP and PEXPIRETIME read from outside Lua */
    private function snapshot(): array
    {
        $state = [];
        foreach ($this->keyNames() as $key) {
            $dump = $this->oracle->execute(['DUMP', $key]);
            self::assertIsString($dump);
            $expiry = $this->oracle->execute(['PEXPIRETIME', $key]);
            self::assertTrue(is_int($expiry) || is_string($expiry));
            $state[$key] = bin2hex($dump) . '@' . $expiry;
        }

        return $state;
    }

    /**
     * One runtime graph over its own connection; `$store` is the store it uses (a given one is shared, a new one is created otherwise).
     *
     * @param-out RedisFullCapabilityStore $store
     */
    private function runtime(FixedClock $clock, RecordingFailureSignalEmitter $signals, ?RedisFullCapabilityStore &$store): CompositeRateLimiterRuntimeInterface
    {
        if ($store === null) {
            $connection = new RespRedisCommandExecutor((string) getenv('REDIS_INTEGRATION_HOST'), (int) getenv('REDIS_INTEGRATION_PORT'));
            $store = new RedisFullCapabilityStore(new CallableRedisCommandExecutor(function (array $command) use ($connection): mixed {
                $hook = $this->hook;
                if ($hook !== null) {
                    $hook($command, 'before');
                }
                $reply = $connection->execute($command);
                if ($hook !== null) {
                    $hook($command, 'after');
                }

                return $reply;
            }), 'fiber-260');
        }

        return RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('test_secret', 'test_fp', 'prod'), $store, $signals)->withClock($clock)->build();
    }

    private function context(string $account): RateLimitContextDTO
    {
        return new RateLimitContextDTO(ip: '198.51.100.7', ua: 'fiber-260', accountId: $account);
    }

    /**
     * @param Fiber<mixed, mixed, mixed, mixed> $fiber
     * @return callable(array<mixed>, string): void a hook that suspends the fiber once, after its first held evaluation of a request */
    private function suspendAfterTheFirstHeldEvaluation(Fiber $fiber, string $tag): callable
    {
        $fired = false;

        return function (array $command, string $phase) use ($fiber, &$fired, $tag): void {
            if ($phase === 'after' && ! $fired && Fiber::getCurrent() === $fiber && self::isScript($command, '__ov_result')) {
                $fired = true;
                Fiber::suspend($tag);
            }
        };
    }

    /** @param callable(array<mixed>, string): void ...$hooks */
    private function hooks(callable ...$hooks): void
    {
        $this->hook = static function (array $command, string $phase) use ($hooks): void {
            foreach ($hooks as $hook) {
                $hook($command, $phase);
            }
        };
    }

    /** @param Fiber<mixed, mixed, mixed, mixed> $fiber */
    private function rejectOnResume(Fiber $fiber): callable
    {
        return static function (array $command, string $phase) use ($fiber): void {
            if ($phase === 'before' && Fiber::getCurrent() === $fiber && self::isScript($command, '__ov_result')) {
                throw new RateLimiterException('Call A is rejected after it was resumed.');
            }
        };
    }

    /** @param Fiber<mixed, mixed, mixed, mixed> $fiber */
    private function decision(Fiber $fiber): string
    {
        self::assertTrue($fiber->isTerminated());
        $result = $fiber->getReturn();
        self::assertInstanceOf(RateLimitResultDTO::class, $result);

        return $result->decision;
    }

    /**
     * The keys the failure of account b adds when it runs alone after call A (no overlap): the effects its answer promises.
     *
     * @return array{list<string>, list<string>} the keys of call A alone, and of B after it
     */
    private function sequentialControl(): array
    {
        $this->oracle->execute(['FLUSHDB']);
        $store = null;
        $runtime = $this->runtime(new FixedClock('@' . self::T0), new RecordingFailureSignalEmitter(), $store);
        $runtime->limit($this->context('acct-a'), RateLimitCommand::checkOnly('login_protection'));
        $afterA = $this->keyNames();
        $runtime->limit($this->context('acct-b'), RateLimitCommand::recordFailure('login_protection'));
        $afterB = $this->keyNames();
        $this->oracle->execute(['FLUSHDB']);

        return [$afterA, array_values(array_diff($afterB, $afterA))];
    }


    private const REFUSAL = 'Maatify\\RateLimiter\\Exception\\OverlappingRequestException';

    /** @return string|null the class of the exception the call threw, or null when it returned */
    private function refusalOf(callable $call): ?string
    {
        try {
            $call();
        } catch (\Throwable $caught) {
            return $caught::class;
        }

        return null;
    }

    private function assertRefused(callable $call, string $message = 'The overlapping call is refused.'): void
    {
        self::assertSame(self::REFUSAL, $this->refusalOf($call), $message);
    }


    // ---- scheduling helpers ----------------------------------------------------------------------------------------------------------

    /**
     * @param Fiber<mixed, mixed, mixed, mixed> $fiber
     * @return callable(array<mixed>, string): void suspends the fiber once at the given phase of its first command (inside the executor)
     */
    private function suspendAtTheFirstCommand(Fiber $fiber, string $phase, string $tag): callable
    {
        $fired = false;

        return function (array $command, string $at) use ($fiber, $phase, &$fired, $tag): void {
            if ($at === $phase && ! $fired && Fiber::getCurrent() === $fiber) {
                $fired = true;
                Fiber::suspend($tag);
            }
        };
    }

    /**
     * @param Fiber<mixed, mixed, mixed, mixed> $fiber
     * @return callable(array<mixed>, string): void suspends the fiber once before its commit command (the commit window)
     */
    private function suspendBeforeTheCommit(Fiber $fiber): callable
    {
        $fired = false;

        return function (array $command, string $phase) use ($fiber, &$fired): void {
            if ($phase === 'before' && ! $fired && Fiber::getCurrent() === $fiber && self::isCommit($command)) {
                $fired = true;
                Fiber::suspend('before the commit');
            }
        };
    }

    /**
     * @param Fiber<mixed, mixed, mixed, mixed> $fiber
     * @return callable(array<mixed>, string): void counts the commands one fiber sends
     */
    private function countCommandsOf(Fiber $fiber, int &$count): callable
    {
        return static function (array $command, string $phase) use ($fiber, &$count): void {
            if ($phase === 'before' && Fiber::getCurrent() === $fiber) {
                $count++;
            }
        };
    }

    /**
     * @return list<string> the keys one call leaves when it runs alone on an empty Redis
     */
    private function keysOfCallAlone(string $account, RateLimitCommand $command): array
    {
        $this->oracle->execute(['FLUSHDB']);
        $store = null;
        $runtime = $this->runtime(new FixedClock('@' . self::T0), new RecordingFailureSignalEmitter(), $store);
        $runtime->limit($this->context($account), $command);
        $keys = $this->keyNames();
        $this->oracle->execute(['FLUSHDB']);

        return $keys;
    }

    /** @return array{Fiber<mixed, mixed, mixed, mixed>, Fiber<mixed, mixed, mixed, mixed>, CompositeRateLimiterRuntimeInterface, RecordingFailureSignalEmitter} */
    private function overlapOnOneRuntime(): array
    {
        $signals = new RecordingFailureSignalEmitter();
        $store = null;
        $runtime = $this->runtime(new FixedClock('@' . self::T0), $signals, $store);
        $a = new Fiber(fn() => $runtime->limit($this->context('acct-a'), RateLimitCommand::checkOnly('login_protection')));
        $b = new Fiber(fn() => $runtime->limit($this->context('acct-b'), RateLimitCommand::recordFailure('login_protection')));

        return [$a, $b, $runtime, $signals];
    }

    /**
     * @param list<string> $fields
     * @return list<string> the distinct `field=value` pairs of the given fields found on stored hashes
     */
    private function storedStamps(array $fields): array
    {
        $found = [];
        foreach ($this->keyNames() as $key) {
            if ($this->oracle->execute(['TYPE', $key]) !== 'hash') {
                continue;
            }
            $hash = $this->oracle->execute(['HGETALL', $key]);
            self::assertIsArray($hash);
            for ($i = 0; $i + 1 < count($hash); $i += 2) {
                $name = $hash[$i];
                $value = $hash[$i + 1];
                self::assertTrue(is_string($name) && (is_string($value) || is_int($value)));
                if (in_array($name, $fields, true)) {
                    $found[$name . '=' . $value] = true;
                }
            }
        }
        $pairs = array_keys($found);
        sort($pairs);

        return $pairs;
    }

    /** @return list<string> */
    private function emittedTypes(RecordingFailureSignalEmitter $signals): array
    {
        return array_values(array_map(static fn(FailureSignalDTO $signal): string => $signal->type, $signals->getEmitted()));
    }

    // ---- 1. the narrow store boundary: identity, not crossing depth ------------------------------------------------------------------

    public function testACallFromAnotherContextIsRefusedWhileACallbackSuspendedInsideADirectTransaction(): void
    {
        $store = null;
        $this->runtime(new FixedClock('@' . self::T0), new RecordingFailureSignalEmitter(), $store);
        self::assertInstanceOf(RedisFullCapabilityStore::class, $store);
        $nested = null;
        $a = new Fiber(function () use ($store, &$nested): void {
            $store->transactRequest(function () use ($store, &$nested): void {
                // A legitimate nested capability call from the owner's own context: allowed.
                $nested = $store->acquireProbeLease('nested', self::T0, 120);
                Fiber::suspend('A holds its request');

                throw new RateLimiterException('Call A is rejected.');
            });
        });
        $a->start();
        self::assertTrue($a->isSuspended());
        self::assertTrue($nested, 'The nested call of the owner was not refused.');
        $before = $this->snapshot();

        // B comes from another execution context while A's callback is suspended (no Host crossing is open).
        $this->assertRefused(static fn() => $store->acquireProbeLease('policy-b', self::T0, 120), 'B is refused by the owner\'s execution context.');
        self::assertSame($before, $this->snapshot(), 'Nothing of B reached Redis.');
        try {
            $a->resume();
            self::fail('Call A is rejected.');
        } catch (RateLimiterException $caught) {
            self::assertNotSame(self::REFUSAL, $caught::class, 'A is rejected by its own callback, not refused.');
        }
        self::assertSame($before, $this->snapshot(), 'A was rejected: nothing was stored, and nothing of B was held for it.');
        self::assertTrue($store->acquireProbeLease('policy-b', self::T0, 120), 'The same call succeeds once A has ended.');
        self::assertNotSame($before, $this->snapshot());
    }

    public function testACallFromAnotherContextIsRefusedWhileADirectCapabilityIsSuspendedInTheExecutor(): void
    {
        $store = null;
        $this->runtime(new FixedClock('@' . self::T0), new RecordingFailureSignalEmitter(), $store);
        self::assertInstanceOf(RedisFullCapabilityStore::class, $store);
        $a = new Fiber(static fn(): bool => $store->acquireProbeLease('policy-a', self::T0, 120));
        $this->hook = $this->suspendAtTheFirstCommand($a, 'before', 'A in the executor');
        $a->start();
        self::assertTrue($a->isSuspended());
        $before = $this->snapshot();

        $this->assertRefused(static fn() => $store->acquireProbeLease('policy-b', self::T0, 120));
        $this->assertRefused(static fn() => $store->isHealthy(), 'The health check is admitted too.');
        self::assertSame($before, $this->snapshot());
        $a->resume();

        self::assertTrue($a->getReturn());
        self::assertCount(1, $this->keyNames(), 'Only A\'s lease was stored.');
    }

    // ---- 2. controls: sequential and intentionally nested ----------------------------------------------------------------------------

    public function testSequentialCallsAreSavedBeforeEachReturnsAndARejectionDoesNotReachTheOthers(): void
    {
        [, $effectsOfB] = $this->sequentialControl();
        self::assertNotSame([], $effectsOfB, 'The failure of account b stores state.');
        $signals = new RecordingFailureSignalEmitter();
        $store = null;
        $runtime = $this->runtime(new FixedClock('@' . self::T0), $signals, $store);

        $runtime->limit($this->context('acct-b'), RateLimitCommand::recordFailure('login_protection'));
        $afterB = $this->snapshot();

        self::assertSame($effectsOfB, array_values(array_intersect($effectsOfB, array_keys($afterB))), 'B is stored when it returns.');
        $this->hook = static function (array $command, string $phase): void {
            if ($phase === 'before' && self::isScript($command, '__ov_result')) {
                throw new RateLimiterException('The next call is rejected.');
            }
        };
        $this->assertNotRefusedButRejected(fn() => $runtime->limit($this->context('acct-a'), RateLimitCommand::checkOnly('login_protection')));
        self::assertSame($afterB, $this->snapshot(), 'The rejection of a later call leaves what B stored exactly as it was.');
    }

    private function assertNotRefusedButRejected(callable $call): void
    {
        $class = $this->refusalOf($call);
        self::assertNotNull($class, 'The call is rejected.');
        self::assertNotSame(self::REFUSAL, $class, 'A rejection by the evaluation is not an overlap refusal.');
    }

    public function testANestedRequestInsideOneRequestIsOneSaveAndKeepsTheOuterClockReading(): void
    {
        $store = null;
        $clock = new FixedClock('@' . self::T0);
        $this->runtime($clock, new RecordingFailureSignalEmitter(), $store);
        self::assertInstanceOf(RedisFullCapabilityStore::class, $store);
        $commits = 0;
        $this->hook = static function (array $command, string $phase) use (&$commits): void {
            if ($phase === 'before' && self::isCommit($command)) {
                $commits++;
            }
        };

        $store->transactRequest(function () use ($store): void {
            $store->acquireProbeLease('outer', self::T0, 120);
            $store->transactRequest(static function () use ($store): void {
                $store->acquireProbeLease('inner', self::T0, 120);
            });
        });

        self::assertSame(1, $commits, 'The nested request does not start a save of its own, and no nested call is refused.');
        self::assertCount(2, $this->keyNames(), 'Both leases were saved together.');

        $scoped = new RequestScopedClock($clock);
        $samples = $scoped->holding(function () use ($scoped, $clock): array {
            $first = $scoped->now()->getTimestamp();
            $clock->setNow(new \DateTimeImmutable('@' . self::T1));

            return [$first, $scoped->holding(static fn(): int => $scoped->now()->getTimestamp()), $scoped->now()->getTimestamp()];
        });
        self::assertSame([self::T0, self::T0, self::T0], $samples, 'A nested call keeps the outer reading (LT-P2-05).');
    }

    // ---- 3. two independent calls overlapping on one runtime: the second is refused --------------------------------------------------

    public function testAnIndependentCallIsRefusedAtItsEntryWhileAnotherCallHoldsTheRequest(): void
    {
        [$a, $b] = $this->overlapOnOneRuntime();
        [$afterAAlone] = $this->sequentialControl();
        $bCommands = 0;
        $this->hooks($this->suspendAfterTheFirstHeldEvaluation($a, 'A'), $this->countCommandsOf($b, $bCommands));
        $a->start();
        self::assertTrue($a->isSuspended());
        $before = $this->snapshot();

        $this->assertRefused(static fn() => $b->start());

        self::assertSame(0, $bCommands, 'B was refused before it sent any command.');
        self::assertSame($before, $this->snapshot(), 'Nothing of B reached Redis, and nothing of A was altered.');
        $a->resume();
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->decision($a));
        self::assertSame($afterAAlone, $this->keyNames(), 'A stored exactly what it stores when it runs alone.');
    }

    public function testTheCallHoldingTheRequestCanBeRejectedAndTheRefusedCallWasNeverAnswered(): void
    {
        [$a, $b] = $this->overlapOnOneRuntime();
        $this->hook = $this->suspendAfterTheFirstHeldEvaluation($a, 'A');
        $a->start();
        $before = $this->snapshot();
        $this->assertRefused(static fn() => $b->start());
        $this->hooks($this->rejectOnResume($a));

        $this->assertNotRefusedButRejected(static fn() => $a->resume());

        self::assertSame($before, $this->snapshot(), 'A was rejected: nothing was stored, and B left nothing behind (it never ran).');
    }

    /** @return array<string, array{bool}> */
    public static function startOrders(): array
    {
        return ['A starts first' => [true], 'B starts first' => [false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('startOrders')]
    public function testWhicheverCallStartsSecondIsRefusedAndTheFirstStoresExactlyItsOwnEffects(bool $aFirst): void
    {
        $signals = new RecordingFailureSignalEmitter();
        $store = null;
        $runtime = $this->runtime(new FixedClock('@' . self::T0), $signals, $store);
        $a = new Fiber(fn() => $runtime->limit($this->context('acct-a'), RateLimitCommand::recordFailure('login_protection')));
        $b = new Fiber(fn() => $runtime->limit($this->context('acct-b'), RateLimitCommand::recordFailure('login_protection')));
        $first = $aFirst ? $a : $b;
        $second = $aFirst ? $b : $a;
        $firstAccount = $aFirst ? 'acct-a' : 'acct-b';
        $control = $this->keysOfCallAlone($firstAccount, RateLimitCommand::recordFailure('login_protection'));
        $this->hook = $this->suspendAfterTheFirstHeldEvaluation($first, 'first');
        $first->start();
        self::assertTrue($first->isSuspended());

        $this->assertRefused(static fn() => $second->start());
        $first->resume();

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->decision($first));
        self::assertSame($control, $this->keyNames(), 'The call that started first stored exactly what it stores alone.');
    }

    public function testTheHostClockReadingOfTheCallHoldingTheRequestIsNotUsedByARefusedCallAndTheNextCallReadsTheNewTime(): void
    {
        $signals = new RecordingFailureSignalEmitter();
        $store = null;
        $clock = new FixedClock('@' . self::T0);
        $runtime = $this->runtime($clock, $signals, $store);
        $a = new Fiber(fn() => $runtime->limit($this->context('acct-a'), RateLimitCommand::checkOnly('login_protection')));
        $b = new Fiber(fn() => $runtime->limit($this->context('acct-b'), RateLimitCommand::recordFailure('login_protection')));
        $this->hook = $this->suspendAfterTheFirstHeldEvaluation($a, 'A');
        $a->start();
        $clock->setNow(new \DateTimeImmutable('@' . self::T1));

        $this->assertRefused(static fn() => $b->start());
        $this->hook = null;
        $a->resume();
        self::assertSame([], $this->storedStamps(['updatedAt']), 'A stored no stamp and B never ran.');
        $later = new Fiber(fn() => $runtime->limit($this->context('acct-b'), RateLimitCommand::recordFailure('login_protection')));
        $later->start();

        self::assertSame(['updatedAt=' . self::T1], $this->storedStamps(['updatedAt']), 'After A has ended, the next call reads the Host clock\'s new value.');
    }

    public function testABackendFailureIsNeverCrossedBetweenCallsBecauseTheOverlappingCallIsRefusedFirst(): void
    {
        $signals = new RecordingFailureSignalEmitter();
        $store = null;
        $runtime = $this->runtime(new FixedClock('@' . self::T0), $signals, $store);
        $a = new Fiber(fn() => $runtime->limit($this->context('acct-a'), RateLimitCommand::checkOnly('login_protection')));
        $b = new Fiber(fn() => $runtime->limit($this->context('acct-b'), RateLimitCommand::checkOnly('login_protection')));
        $bCommands = 0;
        $suspend = $this->suspendAfterTheFirstHeldEvaluation($a, 'A');
        $count = $this->countCommandsOf($b, $bCommands);
        $this->hook = function (array $command, string $phase) use ($suspend, $count, $b): void {
            $suspend($command, $phase);
            $count($command, $phase);
            if ($phase === 'before' && Fiber::getCurrent() === $b && self::isScript($command, '__ov_result')) {
                throw new BackendFailureException('The backend would fail for call B only.');
            }
        };
        $a->start();

        $this->assertRefused(static fn() => $b->start());
        $a->resume();

        $resultA = $a->getReturn();
        self::assertInstanceOf(RateLimitResultDTO::class, $resultA);
        self::assertSame(0, $bCommands, 'B was refused before it sent any command, so no failure exists to cross.');
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $resultA->decision, 'A completes with its own answer.');
        self::assertNotSame('FAIL_CLOSED', $resultA->failureMode);
        self::assertNotSame([], $this->keyNames(), 'A saved its own effects.');
        self::assertSame([], array_values(array_filter($this->keyNames(), static fn(string $key): bool => str_contains($key, ':circuit:'))), 'No circuit state was written.');
    }

    // ---- 4. runtimes that share one store object, and graphs that share nothing ------------------------------------------------------

    public function testACallOnAnotherRuntimeSharingTheStoreObjectIsRefusedAtItsEntry(): void
    {
        $store = null;
        $runtimeA = $this->runtime(new FixedClock('@' . self::T0), new RecordingFailureSignalEmitter(), $store);
        $runtimeB = $this->runtime(new FixedClock('@' . self::T0), new RecordingFailureSignalEmitter(), $store);
        $a = new Fiber(fn() => $runtimeA->limit($this->context('acct-a'), RateLimitCommand::checkOnly('login_protection')));
        $b = new Fiber(fn() => $runtimeB->limit($this->context('acct-b'), RateLimitCommand::recordFailure('login_protection')));
        [$afterAAlone] = $this->sequentialControl();
        $bCommands = 0;
        $this->hooks($this->suspendAfterTheFirstHeldEvaluation($a, 'A'), $this->countCommandsOf($b, $bCommands));
        $a->start();
        $before = $this->snapshot();

        $this->assertRefused(static fn() => $b->start());

        self::assertSame($before, $this->snapshot());
        self::assertSame(0, $bCommands);
        $a->resume();
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->decision($a));
        self::assertSame($afterAAlone, $this->keyNames());
    }

    public function testAfterTheCallInFlightEndsTheOtherRuntimeSharingTheStoreCarriesItsOwnClockReading(): void
    {
        $clockB = new FixedClock('@' . self::T0);
        $store = null;
        $runtimeA = $this->runtime(new FixedClock('@' . self::T0), new RecordingFailureSignalEmitter(), $store);
        $runtimeB = $this->runtime($clockB, new RecordingFailureSignalEmitter(), $store);
        $a = new Fiber(fn() => $runtimeA->limit($this->context('acct-a'), RateLimitCommand::checkOnly('login_protection')));
        $b = new Fiber(fn() => $runtimeB->limit($this->context('acct-b'), RateLimitCommand::recordFailure('login_protection')));
        $this->hook = $this->suspendAfterTheFirstHeldEvaluation($a, 'A');
        $a->start();
        $clockB->setNow(new \DateTimeImmutable('@' . self::T1));
        $this->assertRefused(static fn() => $b->start());
        $this->hook = null;
        $a->resume();

        $later = new Fiber(fn() => $runtimeB->limit($this->context('acct-b'), RateLimitCommand::recordFailure('login_protection')));
        $later->start();

        self::assertSame(['updatedAt=' . self::T1], $this->storedStamps(['updatedAt']), 'Runtime B\'s effects carry the reading of runtime B\'s own Host clock (T1); the wrong value would be T0.');
    }

    public function testRuntimesWithTheirOwnStoreAndClockDoNotShareARequest(): void
    {
        [, $effectsOfB] = $this->sequentialControl();
        $storeA = null;
        $storeB = null;
        $clockB = new FixedClock('@' . self::T0);
        $runtimeA = $this->runtime(new FixedClock('@' . self::T0), new RecordingFailureSignalEmitter(), $storeA);
        $runtimeB = $this->runtime($clockB, new RecordingFailureSignalEmitter(), $storeB);
        $a = new Fiber(fn() => $runtimeA->limit($this->context('acct-a'), RateLimitCommand::checkOnly('login_protection')));
        $b = new Fiber(fn() => $runtimeB->limit($this->context('acct-b'), RateLimitCommand::recordFailure('login_protection')));
        $this->hook = $this->suspendAfterTheFirstHeldEvaluation($a, 'A');
        $a->start();
        $clockB->setNow(new \DateTimeImmutable('@' . self::T1));

        $b->start();

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->decision($b));
        self::assertSame($effectsOfB, array_values(array_intersect($effectsOfB, $this->keyNames())), 'B is stored when it returns, whatever A does next.');
        self::assertSame(['updatedAt=' . self::T1], $this->storedStamps(['updatedAt']), 'B is stamped with its own Host clock.');
        $afterB = $this->snapshot();
        $this->hooks($this->rejectOnResume($a));
        $this->assertNotRefusedButRejected(static fn() => $a->resume());
        self::assertSame($afterB, $this->snapshot(), 'The rejection of A leaves what B stored exactly as it was.');
    }

    // ---- 5. circuit-recovery signals -------------------------------------------------------------------------------------------------

    /** @return array{FixedClock, CompositeRateLimiterRuntimeInterface, RecordingFailureSignalEmitter} a runtime whose circuit is half-open and probe-eligible at 999530 */
    private function halfOpenCircuit(): array
    {
        $clock = new FixedClock('@999000');
        $signals = new RecordingFailureSignalEmitter();
        $store = null;
        $runtime = $this->runtime($clock, $signals, $store);
        $this->hook = static function (array $command, string $phase): void {
            if ($phase === 'before' && self::isScript($command, '__ov_result')) {
                throw new BackendFailureException('Test outage.');
            }
        };
        for ($i = 0; $i < 3; $i++) {
            $clock->setNow(new \DateTimeImmutable('@' . (999_000 + $i)));
            $runtime->limit($this->context('outage-' . $i), RateLimitCommand::checkOnly('login_protection'));
        }
        $this->hook = null;
        $clock->setNow(new \DateTimeImmutable('@999400'));
        $runtime->limit($this->context('first-probe'), RateLimitCommand::checkOnly('login_protection'));
        $clock->setNow(new \DateTimeImmutable('@999530'));
        self::assertSame('HALF_OPEN', $this->circuitStatus());
        $signals->clear();

        return [$clock, $runtime, $signals];
    }

    private function circuitStatus(): ?string
    {
        foreach ($this->keyNames() as $key) {
            if (str_contains($key, ':circuit:')) {
                $json = $this->oracle->execute(['GET', $key]);
                self::assertIsString($json);
                $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
                self::assertIsArray($decoded);

                return is_string($decoded['status'] ?? null) ? $decoded['status'] : null;
            }
        }

        return null;
    }

    /** @return array<string, array{bool}> */
    public static function endingsOfTheHoldingCall(): array
    {
        return ['the holding call is saved' => [true], 'the holding call is rejected' => [false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('endingsOfTheHoldingCall')]
    public function testARecoveryTransitionIsAnnouncedOnlyAfterItsSaveAndAnOverlappingCallIsRefused(bool $holdingCallSaved): void
    {
        [, $runtime, $signals] = $this->halfOpenCircuit();
        $a = new Fiber(fn() => $runtime->limit($this->context('acct-a'), RateLimitCommand::checkOnly('login_protection')));
        $b = new Fiber(fn() => $runtime->limit($this->context('acct-b'), RateLimitCommand::checkOnly('login_protection')));
        $this->hook = $this->suspendAfterTheFirstHeldEvaluation($a, 'A');
        $a->start();
        self::assertSame([], $this->emittedTypes($signals), 'A holds its recovery signal while it is unsaved.');
        $this->hook = null;

        $this->assertRefused(static fn() => $b->start());

        self::assertSame('HALF_OPEN', $this->circuitStatus(), 'The recovery was not saved.');
        self::assertSame([], $this->emittedTypes($signals), 'The refused call released nothing: no signal left before any save.');
        if (! $holdingCallSaved) {
            $this->hooks($this->rejectOnResume($a));
            $this->assertNotRefusedButRejected(static fn() => $a->resume());
            self::assertSame('HALF_OPEN', $this->circuitStatus(), 'The circuit was never closed.');
            self::assertSame([], $this->emittedTypes($signals), 'A rejected request announces nothing.');

            return;
        }
        $a->resume();
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->decision($a));
        self::assertSame('CLOSED', $this->circuitStatus(), 'A saved its recovery step.');
        self::assertSame([FailureSignalDTO::TYPE_CB_RECOVERED], $this->emittedTypes($signals), 'Announced once, after the save.');
    }

    // ---- 6. windows the review named: owner without a transaction, commit window, claims, partial sharing, re-entrancy ------------------

    public function testADirectTransactionWithoutWritesIsRefusedWhileACallIsSuspendedInTheHostClock(): void
    {
        $clock = new class ('@' . self::T0) extends FixedClock {
            /** @var Fiber<mixed, mixed, mixed, mixed>|null */
            public ?Fiber $suspendIn = null;

            public bool $fired = false;

            public function now(): \DateTimeImmutable
            {
                if ($this->suspendIn !== null && ! $this->fired && Fiber::getCurrent() === $this->suspendIn) {
                    $this->fired = true;
                    Fiber::suspend('A in the Host clock');
                }

                return parent::now();
            }
        };
        $store = null;
        $runtime = $this->runtime($clock, new RecordingFailureSignalEmitter(), $store);
        self::assertInstanceOf(RedisFullCapabilityStore::class, $store);
        $a = new Fiber(fn() => $runtime->limit($this->context('acct-a'), RateLimitCommand::checkOnly('login_protection')));
        $clock->suspendIn = $a;
        $a->start();
        self::assertTrue($a->isSuspended(), 'A is suspended inside the Host clock, before any store transaction.');
        $before = $this->snapshot();

        $this->assertRefused(static fn() => $store->transactRequest(static fn(): int => 123), 'A direct transaction that would send no command is refused all the same.');
        $this->assertRefused(static fn() => $runtime->limit(new RateLimitContextDTO(ip: '198.51.100.7', ua: 'fiber-260', accountId: 'acct-b'), RateLimitCommand::checkOnly('login_protection')));
        self::assertSame($before, $this->snapshot());
        $a->resume();

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->decision($a));
    }

    public function testACallArrivingInTheCommitWindowIsRefused(): void
    {
        [$a, $b, $runtime] = $this->overlapOnOneRuntime();
        $store = null;
        $this->hook = $this->suspendBeforeTheCommit($a);
        $a->start();
        self::assertTrue($a->isSuspended(), 'A is suspended just before its commit command.');
        $before = $this->snapshot();

        $this->assertRefused(static fn() => $b->start());
        self::assertSame($before, $this->snapshot(), 'Nothing of A was saved and nothing of B was.');
        $a->resume();

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->decision($a));
        self::assertNotSame($before, $this->snapshot(), 'A saved its own request.');
        unset($store, $runtime);
    }

    public function testAnOutcomeClaimAndAHealthCheckFromAnUnrelatedCallerAreRefused(): void
    {
        $store = null;
        $this->runtime(new FixedClock('@' . self::T0), new RecordingFailureSignalEmitter(), $store);
        self::assertInstanceOf(RedisFullCapabilityStore::class, $store);
        $a = new Fiber(static fn(): bool => $store->acquireProbeLease('policy-a', self::T0, 120));
        $this->hook = $this->suspendAtTheFirstCommand($a, 'before', 'A in the executor');
        $a->start();
        $before = $this->snapshot();

        $this->assertRefused(static fn() => $store->claimAdmissionPermitWithOutcome('U', 'subject', 'login_protection', new AdmissionOutcomeDTO('login', AdmissionOutcomeKindEnum::FAILURE, [], 'permit-x'), self::T0), 'The claim stays its own atomic command for a legitimate caller; an unrelated caller is refused.');
        $this->assertRefused(static fn() => $store->isHealthy());
        self::assertSame($before, $this->snapshot());
        $a->resume();
    }

    public function testGraphsThatShareOnlyTheCorrelationStoreOrOnlyTheCircuitStoreContend(): void
    {
        foreach (['correlation', 'circuit'] as $shared) {
            $this->oracle->execute(['FLUSHDB']);
            $make = fn(): RedisFullCapabilityStore => $this->standaloneStore();
            $s1 = $make();
            $s2 = $make();
            $k1 = $shared === 'correlation' ? $make() : $make();
            $k2 = $shared === 'correlation' ? $k1 : $make();
            $b1 = $shared === 'circuit' ? $make() : $make();
            $b2 = $shared === 'circuit' ? $b1 : $make();
            $config = new RateLimiterConfig('test_secret', 'test_fp', 'prod');
            $g1 = (new RateLimiterBuilder($config, $s1, $k1, $b1, new RecordingFailureSignalEmitter()))->withClock(new FixedClock('@' . self::T0))->build();
            $g2 = (new RateLimiterBuilder($config, $s2, $k2, $b2, new RecordingFailureSignalEmitter()))->withClock(new FixedClock('@' . self::T0))->build();
            $a = new Fiber(fn() => $g1->limit($this->context('acct-a'), RateLimitCommand::checkOnly('login_protection')));
            $bCommands = 0;
            $b = new Fiber(fn() => $g2->limit($this->context('acct-b'), RateLimitCommand::checkOnly('login_protection')));
            $this->hooks($this->suspendAtTheFirstCommand($a, 'before', 'A in the executor'), $this->countCommandsOf($b, $bCommands));
            $a->start();
            self::assertTrue($a->isSuspended());
            $before = $this->snapshot();

            $this->assertRefused(static fn() => $b->start(), 'The graph that shares only the ' . $shared . ' store is refused on the shared object.');

            self::assertSame(0, $bCommands, 'It was refused before any command.');
            self::assertSame($before, $this->snapshot());
            $a->resume();
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->decision($a));
            $later = new Fiber(fn() => $g2->limit($this->context('acct-b'), RateLimitCommand::checkOnly('login_protection')));
            $later->start();
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->decision($later), 'After A has ended the other graph runs.');
            $this->hook = null;
        }
    }

    private function standaloneStore(): RedisFullCapabilityStore
    {
        $connection = new RespRedisCommandExecutor((string) getenv('REDIS_INTEGRATION_HOST'), (int) getenv('REDIS_INTEGRATION_PORT'));

        return new RedisFullCapabilityStore(new CallableRedisCommandExecutor(function (array $command) use ($connection): mixed {
            $hook = $this->hook;
            if ($hook !== null) {
                $hook($command, 'before');
            }
            $reply = $connection->execute($command);
            if ($hook !== null) {
                $hook($command, 'after');
            }

            return $reply;
        }), 'fiber-260');
    }

    public function testACallMadeFromInsideTheExecutorOnTheSameStackIsRefused(): void
    {
        $store = null;
        $runtime = $this->runtime(new FixedClock('@' . self::T0), new RecordingFailureSignalEmitter(), $store);
        self::assertInstanceOf(RedisFullCapabilityStore::class, $store);
        $inner = [];
        $reentering = false;
        $this->hook = function (array $command, string $phase) use ($runtime, $store, &$inner, &$reentering): void {
            if ($phase === 'before' && ! $reentering) {
                $reentering = true;
                $inner = [
                    $this->refusalOf(fn() => $runtime->limit($this->context('re-entrant'), RateLimitCommand::checkOnly('login_protection'))),
                    $this->refusalOf(static fn() => $store->isHealthy()),
                ];
            }
        };

        $result = $runtime->limit($this->context('acct-a'), RateLimitCommand::checkOnly('login_protection'));

        self::assertSame([self::REFUSAL, self::REFUSAL], $inner, 'Re-entrant calls from the executor, on the owner\'s own stack, are refused.');
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $result->decision, 'The call in flight is unaffected.');
    }

    public function testACallMadeFromInsideTheSignalEmitterIsRefusedBecauseTheOwnerIsReleasedLast(): void
    {
        $emitted = new class extends RecordingFailureSignalEmitter {
            /** @var (callable(): void)|null */
            public $onEmit = null;

            public function emit(FailureSignalDTO $signal): void
            {
                parent::emit($signal);
                if ($this->onEmit !== null) {
                    ($this->onEmit)();
                }
            }
        };
        $clock = new FixedClock('@999000');
        $store = null;
        $runtime = $this->runtime($clock, $emitted, $store);
        $this->hook = static function (array $command, string $phase): void {
            if ($phase === 'before' && self::isScript($command, '__ov_result')) {
                throw new BackendFailureException('Test outage.');
            }
        };
        for ($i = 0; $i < 3; $i++) {
            $clock->setNow(new \DateTimeImmutable('@' . (999_000 + $i)));
            $runtime->limit($this->context('outage-' . $i), RateLimitCommand::checkOnly('login_protection'));
        }
        $this->hook = null;
        $clock->setNow(new \DateTimeImmutable('@999400'));
        $runtime->limit($this->context('first-probe'), RateLimitCommand::checkOnly('login_protection'));
        $clock->setNow(new \DateTimeImmutable('@999530'));
        $emitted->clear();
        $refusals = [];
        $emitted->onEmit = function () use ($runtime, &$refusals): void {
            $refusals[] = $this->refusalOf(fn() => $runtime->limit($this->context('from-the-emitter'), RateLimitCommand::checkOnly('login_protection')));
        };

        $runtime->limit($this->context('acct-a'), RateLimitCommand::checkOnly('login_protection'));

        self::assertSame([FailureSignalDTO::TYPE_CB_RECOVERED], $this->emittedTypes($emitted));
        self::assertSame([self::REFUSAL], $refusals, 'The slots are released after the signals: a call made from the emitter is refused.');
    }

    public function testAnOverlapRefusalInsideATransactionIsNotRetriedAndIsNotACircuitReport(): void
    {
        $store = null;
        $this->runtime(new FixedClock('@' . self::T0), new RecordingFailureSignalEmitter(), $store);
        self::assertInstanceOf(RedisFullCapabilityStore::class, $store);
        $evaluations = 0;
        $commands = 0;
        $heldKey = null;
        $reentering = false;
        $this->hook = function (array $command, string $phase) use ($store, &$commands, &$heldKey, &$reentering): void {
            if ($phase !== 'before' || $reentering) {
                return;
            }
            $commands++;
            if ($commands === 1) {
                $heldKey = self::keysOf($command)[0] ?? null;
            }
            if ($commands === 2) {
                // The state the request read is now stale (it would be a conflict), and a re-entrant call from the executor is refused.
                self::assertNotNull($heldKey);
                $this->oracle->execute(['SET', $heldKey, 'changed outside the request']);
                $reentering = true;
                $store->isHealthy();
            }
        };

        $class = $this->refusalOf(static function () use ($store, &$evaluations): void {
            $store->transactRequest(static function () use ($store, &$evaluations): void {
                $evaluations++;
                $store->acquireProbeLease('x', self::T0, 120);
                $store->acquireProbeLease('y', self::T0, 120);
            });
        });

        self::assertSame(self::REFUSAL, $class, 'The refusal reaches the caller unchanged.');
        self::assertSame(1, $evaluations, 'It was not retried as a conflict over stale state, although the state it read had changed.');
        self::assertSame([$heldKey], $this->keyNames(), 'Only the key changed from outside exists: nothing was saved and no circuit state was written.');
    }

    /**
     * @param array<mixed> $command
     * @return list<string>
     */
    private static function keysOf(array $command): array
    {
        /** @var list<int|string|float> $command */
        return OverlayWire::keys($command);
    }

    public function testADestroyedSuspendedFiberReleasesTheOwnershipAndALaterCallRuns(): void
    {
        $store = null;
        $this->runtime(new FixedClock('@' . self::T0), new RecordingFailureSignalEmitter(), $store);
        self::assertInstanceOf(RedisFullCapabilityStore::class, $store);
        $a = new Fiber(static fn(): bool => $store->acquireProbeLease('policy-a', self::T0, 120));
        $this->hook = $this->suspendAtTheFirstCommand($a, 'before', 'A in the executor');
        $a->start();
        $this->assertRefused(static fn() => $store->acquireProbeLease('policy-b', self::T0, 120));

        $this->hook = null;
        unset($a);
        gc_collect_cycles();

        self::assertTrue($store->acquireProbeLease('policy-b', self::T0, 120), 'Destroying the suspended Fiber unwound it and released its slots.');
    }

    public function testConsumeTheOperationalReadersAndTheAdmissionControlsAreRefusedWhileACallIsInFlight(): void
    {
        $store = $this->standaloneStore();
        $builder = RateLimiterBuilder::fromFullCapabilityStore(new RateLimiterConfig('test_secret', 'test_fp', 'prod'), $store, new RecordingFailureSignalEmitter())
            ->withSimpleThrottlePolicy(new FixedWindowThrottlePolicy('checkout', 5, 60))
            ->withClock(new FixedClock('@' . self::T0));
        $runtime = $builder->build();
        self::assertInstanceOf(CompositeRateLimiterRuntime::class, $runtime);
        $reader = $builder->buildOperationalReader();
        $a = new Fiber(fn() => $runtime->limit($this->context('acct-a'), RateLimitCommand::checkOnly('login_protection')));
        $commands = 0;
        $other = new Fiber(static fn(): null => null);
        $this->hooks($this->suspendAtTheFirstCommand($a, 'before', 'A in the executor'), $this->countCommandsOf($other, $commands));
        $a->start();
        self::assertTrue($a->isSuspended());
        $before = $this->snapshot();

        $this->assertRefused(static fn() => $runtime->consume('checkout', 'subject-1'), 'The simple runtime shares the store and the clock.');
        $this->assertRefused(static fn() => $reader->readSimpleThrottle('checkout', 'subject-1'), 'An overlapping reader is refused, not passed through.');
        $this->assertRefused(fn() => $reader->readScorePolicy($this->context('acct-b'), 'login_protection'));
        $this->assertRefused(static fn() => $runtime->bindingEpoch('acct-b'));
        $this->assertRefused(static fn() => $runtime->openAdmissionFence('login'));
        $this->assertRefused(static fn() => $runtime->revokeAccountBinding('acct-b'));
        $this->assertRefused(static fn() => $runtime->rebindAdmissionSharedConfig('login'));
        $this->assertRefused(fn() => $runtime->claimPostPunishmentReentry($this->context('acct-b'), 'login_protection', 're-entry-1'));
        self::assertSame($before, $this->snapshot(), 'None of them reached Redis.');
        $a->resume();

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->decision($a));
        $this->hook = null;
        self::assertTrue($runtime->consume('checkout', 'subject-1')->allowed, 'After A has ended the simple runtime runs.');
        self::assertSame(1, $reader->readSimpleThrottle('checkout', 'subject-1')->count, 'And so does the reader.');
    }

    public function testWarmCloneOwnershipForAdmissionAndOutcomeHelpers(): void
    {
        $original = $this->standaloneStore();

        // Warm both lazy helpers on the idle original before cloning.
        self::assertSame(0, $original->readBindingEpoch('a1.epoch.' . str_repeat('a', 64)));
        self::assertSame(
            AdmissionPermitClaimEnum::UNKNOWN,
            $original->claimAdmissionPermitWithOutcome('U', 'subj', 'login', new AdmissionOutcomeDTO('login', AdmissionOutcomeKindEnum::SUCCESS, [], 'p-warm-probe'), self::T0),
        );

        // Seed two real ISSUED permits for B3.
        $b3Scope = new AdmissionScopeDTO('src', AdmissionScopeKindEnum::COUNTER, 'c-b3', 5, 600, null, 'p-claim-b3');
        self::assertTrue($original->reserveAdmission(new AdmissionReservationRequestDTO('login', [$b3Scope], new AdmissionPermitDTO('p-claim-b3', 'U', 'subj-b3', 'login', self::T0 + 30, 600)), self::T0)->admitted);
        $b3Dec037Scope = new AdmissionScopeDTO('src', AdmissionScopeKindEnum::COUNTER, 'c-b3-2', 5, 600, null, 'p-claim-b3-dec037');
        self::assertTrue($original->reserveAdmission(new AdmissionReservationRequestDTO('login', [$b3Dec037Scope], new AdmissionPermitDTO('p-claim-b3-dec037', 'U', 'subj-b3', 'login', self::T0 + 30, 600)), self::T0)->admitted);

        $copy = clone $original;
        $other = new Fiber(static fn(): null => null);
        $commands = 0;

        // B1: copy owns a suspended transactRequest; independent calls on copy's warm admission/outcome helpers are refused before Redis.
        $fCopy = new Fiber(static fn() => $copy->transactRequest(static fn() => $copy->incrementAt('copy-held-b1', 600, 1, self::T0)));
        $this->hooks($this->suspendAfterTheFirstHeldEvaluation($fCopy, 'copy-held-b1'), $this->countCommandsOf($other, $commands));
        $fCopy->start();
        self::assertTrue($fCopy->isSuspended());
        $beforeB1 = $this->snapshot();

        $b1Scope = new AdmissionScopeDTO('src', AdmissionScopeKindEnum::COUNTER, 'c-b1', 5, 600, null, 'p-b1');
        $this->assertRefused(static fn() => $copy->reserveAdmission(new AdmissionReservationRequestDTO('login', [$b1Scope]), self::T0));
        $this->assertRefused(static fn() => $copy->readAdmissionFence('f-b1', null));
        $this->assertRefused(static fn() => $copy->bumpAdmissionFence('f-b1', null));
        $this->assertRefused(static fn() => $copy->readBindingEpoch('e-b1'));
        $this->assertRefused(static fn() => $copy->bumpBindingEpoch('e-b1'));
        $this->assertRefused(static fn() => $copy->claimAdmissionPermitWithOutcome('U', 'subj-b3', 'login', new AdmissionOutcomeDTO('login', AdmissionOutcomeKindEnum::SUCCESS, [$b3Scope], 'p-claim-b3'), self::T0));
        self::assertSame(0, $commands);
        self::assertSame($beforeB1, $this->snapshot());

        // Idle original can still run direct operations while copy is suspended.
        self::assertSame(0, $original->readBindingEpoch('e-orig-idle'));
        self::assertTrue($original->acquireProbeLease('orig-idle', self::T0, 120));

        $this->hook = null;
        $fCopy->resume();
        self::assertSame(1, $copy->get('copy-held-b1')?->value);

        // B2: original owns a suspended cancelled transactRequest; independent copy2 commits cleanly and survives original's cancel.
        $copy2 = clone $original;
        $fOrig = new Fiber(static function () use ($original): void {
            $original->transactRequest(static function () use ($original): void {
                $original->incrementAt('orig-b2-cancel', 600, 1, self::T0);

                throw new \LogicException('cancel-orig-b2');
            });
        });
        $this->hook = $this->suspendAfterTheFirstHeldEvaluation($fOrig, 'orig-b2');
        $fOrig->start();
        self::assertTrue($fOrig->isSuspended());

        $fCopy2 = new Fiber(static fn() => $copy2->transactRequest(static fn() => $copy2->incrementAt('copy2-b2-keep', 600, 1, self::T0)));
        $fCopy2->start();
        self::assertTrue($fCopy2->isTerminated());
        self::assertSame(1, $copy2->bumpBindingEpoch('e-copy2-b2'));

        $this->hook = null;
        try {
            $fOrig->resume();
            self::fail('Expected LogicException');
        } catch (\LogicException $e) {
            self::assertSame('cancel-orig-b2', $e->getMessage());
        }
        self::assertNull($original->get('orig-b2-cancel'));
        self::assertSame(1, $copy2->get('copy2-b2-keep')?->value);
        self::assertSame(1, $copy2->readBindingEpoch('e-copy2-b2'));

        // B3: legitimate claim on copy2 succeeds once, replays DUPLICATE/CONFLICT without mutation, and DEC-036/037 keeps claim on cancelled scoring transaction.
        self::assertSame(
            AdmissionPermitClaimEnum::ACCEPTED,
            $copy2->claimAdmissionPermitWithOutcome('U', 'subj-b3', 'login', new AdmissionOutcomeDTO('login', AdmissionOutcomeKindEnum::SUCCESS, [$b3Scope], 'p-claim-b3'), self::T0),
        );
        $afterB3 = $this->snapshot();
        self::assertSame(
            AdmissionPermitClaimEnum::DUPLICATE,
            $copy2->claimAdmissionPermitWithOutcome('U', 'subj-b3', 'login', new AdmissionOutcomeDTO('login', AdmissionOutcomeKindEnum::SUCCESS, [$b3Scope], 'p-claim-b3'), self::T0),
        );
        self::assertSame(
            AdmissionPermitClaimEnum::CONFLICT,
            $copy2->claimAdmissionPermitWithOutcome('U', 'subj-b3', 'login', new AdmissionOutcomeDTO('login', AdmissionOutcomeKindEnum::FAILURE, [$b3Scope], 'p-claim-b3'), self::T0),
        );
        self::assertSame($afterB3, $this->snapshot());

        try {
            $copy2->transactRequest(static function () use ($copy2, $b3Dec037Scope): void {
                $copy2->incrementAt('held-b3-cancel', 600, 1, self::T0);
                self::assertSame(
                    AdmissionPermitClaimEnum::ACCEPTED,
                    $copy2->claimAdmissionPermitWithOutcome('U', 'subj-b3', 'login', new AdmissionOutcomeDTO('login', AdmissionOutcomeKindEnum::SUCCESS, [$b3Dec037Scope], 'p-claim-b3-dec037'), self::T0),
                );

                throw new \LogicException('cancel-scoring-b3');
            });
        } catch (\Throwable $e) {
            self::assertInstanceOf(\LogicException::class, $e);
            self::assertSame('cancel-scoring-b3', $e->getMessage());
        }
        self::assertNull($copy2->get('held-b3-cancel'));
        self::assertSame(
            AdmissionPermitClaimEnum::DUPLICATE,
            $copy2->claimAdmissionPermitWithOutcome('U', 'subj-b3', 'login', new AdmissionOutcomeDTO('login', AdmissionOutcomeKindEnum::SUCCESS, [$b3Dec037Scope], 'p-claim-b3-dec037'), self::T0),
        );

        // D: Fiber overlap across root, view, and copy of view is refused with zero Redis commands.
        $view = $original->e1Keyspace();
        $viewCopy = clone $view;
        $fRoot = new Fiber(static fn() => $original->transactRequest(static fn() => $original->incrementAt('root-view-overlap', 600, 1, self::T0)));
        $commands = 0;
        $this->hooks($this->suspendAfterTheFirstHeldEvaluation($fRoot, 'root-view-overlap'), $this->countCommandsOf($other, $commands));
        $fRoot->start();
        self::assertTrue($fRoot->isSuspended());
        $this->assertRefused(static fn() => $view->get('e1.' . str_repeat('a', 64)));
        $this->assertRefused(static fn() => $viewCopy->get('e1.' . str_repeat('a', 64)));
        self::assertSame(0, $commands);
        $this->hook = null;
        $fRoot->resume();
    }
}
