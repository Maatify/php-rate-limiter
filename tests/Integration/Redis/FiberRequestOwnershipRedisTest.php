<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Fiber;
use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\FailureSignalDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\BackendFailureException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\CallableRedisCommandExecutor;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Service\RequestScopedClock;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\Redis\OverlayWire;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * #260: what happens when two independent calls overlap on one runtime or one store, with Fibers as the deterministic scheduler.
 *
 * CHARACTERIZATION of `main@316b1de9`, not a statement of the intended contract. Nothing in the documentation says that overlapping
 * independent calls on one runtime/store are supported, and nothing says they are forbidden; the only written rule is that a
 * `transactRequest()` made while one of the store's own requests is being evaluated runs inside that request
 * (`RequestAtomicStoreInterface`), and that a nested runtime call keeps the outer clock reading (`RequestScopedClock`). These tests record
 * what the code does, so that the Owner's decision (support overlapping calls with per-request state, or enforce and document instance
 * exclusivity) has an exact before-state and so the assertions flip deliberately when it lands.
 *
 * Harness: every Redis command is a whole round trip on a sound connection; a Fiber is suspended only BETWEEN commands (after the first
 * held evaluation script of its request), so no connection is ever interleaved at the byte level. Each store owns its connection and the
 * oracle reads over another one, with exact DUMP and PEXPIRETIME. The resumption schedule is explicit; nothing sleeps.
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

    /** @return list<string> the keys left by the failures of account a and account b run one after the other, without overlap */
    private function sequentialKeysOfBothFailures(): array
    {
        $this->oracle->execute(['FLUSHDB']);
        $store = null;
        $runtime = $this->runtime(new FixedClock('@' . self::T0), new RecordingFailureSignalEmitter(), $store);
        $runtime->limit($this->context('acct-a'), RateLimitCommand::recordFailure('login_protection'));
        $runtime->limit($this->context('acct-b'), RateLimitCommand::recordFailure('login_protection'));
        $keys = $this->keyNames();
        $this->oracle->execute(['FLUSHDB']);

        return $keys;
    }

    // ---- 1. the narrow store boundary ------------------------------------------------------------------------------------------------

    public function testAStoreCallMadeWhileAnotherRequestIsOpenIsHeldInThatRequestAndLostWhenItIsRejected(): void
    {
        $store = null;
        $this->runtime(new FixedClock('@' . self::T0), new RecordingFailureSignalEmitter(), $store);
        self::assertInstanceOf(RedisFullCapabilityStore::class, $store);
        $a = new Fiber(function () use ($store): void {
            $store->transactRequest(static function (): void {
                Fiber::suspend('A holds its request');

                throw new RateLimiterException('Call A is rejected.');
            });
        });
        $a->start();
        self::assertTrue($a->isSuspended());
        $before = $this->snapshot();

        // B is an independent call: a direct store write, no request of its own.
        $granted = $store->acquireProbeLease('policy-b', self::T0, 120);

        self::assertTrue($granted, 'B is told it holds the lease.');
        self::assertSame($before, $this->snapshot(), 'Nothing of B reached Redis: its write is held in the request of A.');
        try {
            $a->resume();
            self::fail('Call A is rejected.');
        } catch (RateLimiterException) {
        }
        self::assertSame($before, $this->snapshot(), 'A was rejected: the lease B was granted was never stored.');
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
        try {
            $this->hook = static function (array $command, string $phase): void {
                if ($phase === 'before' && self::isScript($command, '__ov_result')) {
                    throw new RateLimiterException('The next call is rejected.');
                }
            };
            $runtime->limit($this->context('acct-a'), RateLimitCommand::checkOnly('login_protection'));
            self::fail('The next call is rejected.');
        } catch (RateLimiterException) {
        }
        self::assertSame($afterB, $this->snapshot(), 'The rejection of a later call leaves what B stored exactly as it was.');
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

        self::assertSame(1, $commits, 'The nested request does not start a save of its own.');
        self::assertCount(2, $this->keyNames(), 'Both leases were saved together.');

        $scoped = new RequestScopedClock($clock);
        $samples = $scoped->holding(function () use ($scoped, $clock): array {
            $first = $scoped->now()->getTimestamp();
            $clock->setNow(new \DateTimeImmutable('@' . self::T1));

            return [$first, $scoped->holding(static fn(): int => $scoped->now()->getTimestamp()), $scoped->now()->getTimestamp()];
        });
        self::assertSame([self::T0, self::T0, self::T0], $samples, 'A nested call keeps the outer reading (LT-P2-05).');
    }

    // ---- 3. two independent calls overlapping on one runtime ------------------------------------------------------------------------

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

    public function testACompletedIndependentCallIsNotSavedWhileAnotherCallHoldsTheRequest(): void
    {
        [$a, $b] = $this->overlapOnOneRuntime();
        [, $effectsOfB] = $this->sequentialControl();
        $this->hook = $this->suspendAfterTheFirstHeldEvaluation($a, 'A');
        $a->start();
        self::assertTrue($a->isSuspended());
        $before = $this->snapshot();

        $b->start();

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->decision($b), 'B returned its answer.');
        self::assertSame($before, $this->snapshot(), 'B returned before anything of it was saved: its failure was not recorded.');

        $a->resume();

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->decision($a));
        self::assertSame($effectsOfB, array_values(array_intersect($effectsOfB, $this->keyNames())), 'The save of A carried the effects of B.');
    }

    public function testTheFailureOfACompletedIndependentCallIsLostWhenTheCallHoldingTheRequestIsRejected(): void
    {
        [$a, $b] = $this->overlapOnOneRuntime();
        [, $effectsOfB] = $this->sequentialControl();
        $this->hook = $this->suspendAfterTheFirstHeldEvaluation($a, 'A');
        $a->start();
        $before = $this->snapshot();
        $b->start();
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->decision($b));
        $this->hooks($this->rejectOnResume($a));

        try {
            $a->resume();
            self::fail('Call A is rejected.');
        } catch (RateLimiterException) {
        }

        self::assertSame($before, $this->snapshot(), 'Nothing was saved, for either call.');
        self::assertSame([], array_values(array_intersect($effectsOfB, $this->keyNames())), 'B was answered, and the failure it reported was never recorded.');
    }

    /** @return array<string, array{bool}> */
    public static function resumeOrders(): array
    {
        return ['the call that opened the request resumes first' => [true], 'the call that joined it resumes first' => [false]];
    }

    /** @dataProvider resumeOrders */
    #[\PHPUnit\Framework\Attributes\DataProvider('resumeOrders')]
    public function testTwoCallsSuspendedInsideOneRequestHaveTheirEffectsSavedOnlyByTheCallThatOpenedIt(bool $openerFirst): void
    {
        $control = $this->sequentialKeysOfBothFailures();
        $signals = new RecordingFailureSignalEmitter();
        $store = null;
        $runtime = $this->runtime(new FixedClock('@' . self::T0), $signals, $store);
        $a = new Fiber(fn() => $runtime->limit($this->context('acct-a'), RateLimitCommand::recordFailure('login_protection')));
        $b = new Fiber(fn() => $runtime->limit($this->context('acct-b'), RateLimitCommand::recordFailure('login_protection')));
        $this->hooks($this->suspendAfterTheFirstHeldEvaluation($a, 'A'), $this->suspendAfterTheFirstHeldEvaluation($b, 'B'));
        $a->start();
        $b->start();
        self::assertTrue($a->isSuspended() && $b->isSuspended());
        $before = $this->snapshot();
        $first = $openerFirst ? $a : $b;
        $second = $openerFirst ? $b : $a;

        $first->resume();
        $afterFirst = $this->snapshot();
        $second->resume();
        $afterSecond = $this->snapshot();

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->decision($first));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->decision($second));
        if ($openerFirst) {
            self::assertNotSame($before, $afterFirst, 'A saved what the request held at that moment, B included.');
            self::assertGreaterThanOrEqual(count($afterFirst), count($afterSecond));
        } else {
            self::assertSame($before, $afterFirst, 'B returned its answer and saved nothing: it joined A\'s request.');
            self::assertNotSame($before, $afterSecond, 'A saved both when it resumed.');
        }
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->decision($a));
        if ($openerFirst) {
            self::assertNotSame([], array_values(array_diff($control, $this->keyNames())), 'B resumed after A had cleared the request: part of what its answer promised was never stored.');
        } else {
            self::assertSame($control, $this->keyNames(), 'Everything both answers promised was stored, by the one save of A.');
        }
    }

    public function testTheSampleOfTheHostClockOfTheOpeningCallIsUsedByACallThatStartsLater(): void
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
        $this->hook = null;

        $b->start();
        $a->resume();

        self::assertSame(['updatedAt=' . self::T0], $this->storedStamps(['updatedAt']), 'B is stamped with the reading A took, not the Host clock of its own start.');
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

    public function testABackendFailureOfOneCallIsReportedForTheOtherCallThatHadNone(): void
    {
        $signals = new RecordingFailureSignalEmitter();
        $store = null;
        $runtime = $this->runtime(new FixedClock('@' . self::T0), $signals, $store);
        $a = new Fiber(fn() => $runtime->limit($this->context('acct-a'), RateLimitCommand::checkOnly('login_protection')));
        $b = new Fiber(fn() => $runtime->limit($this->context('acct-b'), RateLimitCommand::checkOnly('login_protection')));
        $failuresOfA = 0;
        $failB = new \ArrayObject(['on' => false]);
        $suspend = $this->suspendAfterTheFirstHeldEvaluation($a, 'A');
        $this->hook = function (array $command, string $phase) use ($suspend, $b, $failB): void {
            $suspend($command, $phase);
            if ($failB['on'] === true && $phase === 'before' && Fiber::getCurrent() === $b && self::isScript($command, '__ov_result')) {
                throw new BackendFailureException('The backend failed for call B only.');
            }
        };
        $a->start();
        $failB['on'] = true;
        $b->start();
        $failB['on'] = false;
        $resultB = $b->getReturn();
        self::assertInstanceOf(RateLimitResultDTO::class, $resultB);
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $resultB->decision, 'B met a backend failure: the failure mode answers.');

        try {
            $a->resume();
        } catch (BackendFailureException) {
            $failuresOfA++;
        }

        $resultA = $a->getReturn();
        self::assertInstanceOf(RateLimitResultDTO::class, $resultA);
        self::assertSame(0, $failuresOfA);
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $resultA->decision, 'A had no backend failure of its own and is answered by the failure mode all the same.');
        self::assertSame('FAIL_CLOSED', $resultA->failureMode);
        self::assertSame([], $this->keyNames(), 'Nothing of A was saved.');
    }

    // ---- 4. the same hazard across runtimes, and the isolated control ----------------------------------------------------------------

    public function testTwoRuntimesSharingOneStoreObjectShareTheRequestAndTheLossOfBWhenARejected(): void
    {
        $store = null;
        $runtimeA = $this->runtime(new FixedClock('@' . self::T0), new RecordingFailureSignalEmitter(), $store);
        $runtimeB = $this->runtime(new FixedClock('@' . self::T0), new RecordingFailureSignalEmitter(), $store);
        [, $effectsOfB] = $this->sequentialControl();
        $a = new Fiber(fn() => $runtimeA->limit($this->context('acct-a'), RateLimitCommand::checkOnly('login_protection')));
        $b = new Fiber(fn() => $runtimeB->limit($this->context('acct-b'), RateLimitCommand::recordFailure('login_protection')));
        $this->hook = $this->suspendAfterTheFirstHeldEvaluation($a, 'A');
        $a->start();
        $before = $this->snapshot();

        $b->start();

        self::assertSame($before, $this->snapshot(), 'The store object carries the request: B, on another runtime, is held in the request of A.');
        $this->hooks($this->rejectOnResume($a));
        try {
            $a->resume();
            self::fail('Call A is rejected.');
        } catch (RateLimiterException) {
        }
        self::assertSame([], array_values(array_intersect($effectsOfB, $this->keyNames())), 'The failure B reported was never recorded.');
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->decision($b));
    }

    public function testTheHostClockOfTheOtherRuntimeSelectsTheTimestampOfItsEffectsWhenTheStoreIsShared(): void
    {
        $clockA = new FixedClock('@' . self::T0);
        $clockB = new FixedClock('@' . self::T0);
        $store = null;
        $runtimeA = $this->runtime($clockA, new RecordingFailureSignalEmitter(), $store);
        $runtimeB = $this->runtime($clockB, new RecordingFailureSignalEmitter(), $store);
        $a = new Fiber(fn() => $runtimeA->limit($this->context('acct-a'), RateLimitCommand::checkOnly('login_protection')));
        $b = new Fiber(fn() => $runtimeB->limit($this->context('acct-b'), RateLimitCommand::recordFailure('login_protection')));
        $this->hook = $this->suspendAfterTheFirstHeldEvaluation($a, 'A');
        $a->start();
        // Only the clock of B's runtime moves; A's runtime keeps T0 and holds that reading.
        $clockB->setNow(new \DateTimeImmutable('@' . self::T1));
        $this->hook = null;

        $b->start();
        $a->resume();

        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->decision($a));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->decision($b));
        self::assertSame(
            ['updatedAt=' . self::T1],
            $this->storedStamps(['updatedAt']),
            'The effects of B, saved by the request of A on the shared store, carry the reading of B\'s own Host clock (T1), not the T0 that A holds.',
        );
    }

    public function testRuntimesWithTheirOwnStoreAndClockDoNotShareARequest(): void
    {
        [, $effectsOfB] = $this->sequentialControl();
        $storeA = null;
        $storeB = null;
        $clockA = new FixedClock('@' . self::T0);
        $clockB = new FixedClock('@' . self::T0);
        $runtimeA = $this->runtime($clockA, new RecordingFailureSignalEmitter(), $storeA);
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
        try {
            $a->resume();
            self::fail('Call A is rejected.');
        } catch (RateLimiterException) {
        }
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
    public function testARecoveryTransitionIsAnnouncedWhenAnotherCallReturnsAndBeforeItWasSaved(bool $holdingCallSaved): void
    {
        [, $runtime, $signals] = $this->halfOpenCircuit();
        $a = new Fiber(fn() => $runtime->limit($this->context('acct-a'), RateLimitCommand::checkOnly('login_protection')));
        $b = new Fiber(fn() => $runtime->limit($this->context('acct-b'), RateLimitCommand::checkOnly('login_protection')));
        $this->hook = $this->suspendAfterTheFirstHeldEvaluation($a, 'A');
        $a->start();
        self::assertSame([], array_map(static fn(FailureSignalDTO $signal): string => $signal->type, $signals->getEmitted()), 'A holds its recovery signal while it is unsaved.');
        $this->hook = null;

        $b->start();

        self::assertSame('HALF_OPEN', $this->circuitStatus(), 'The recovery was not saved.');
        self::assertSame(
            [FailureSignalDTO::TYPE_CB_RECOVERED],
            array_map(static fn(FailureSignalDTO $signal): string => $signal->type, $signals->getEmitted()),
            'B ended its own request and released the signal A was holding: CB_RECOVERED left before any save.',
        );
        if (! $holdingCallSaved) {
            $this->hooks($this->rejectOnResume($a));
            try {
                $a->resume();
                self::fail('Call A is rejected.');
            } catch (RateLimiterException) {
            }
            self::assertSame('HALF_OPEN', $this->circuitStatus(), 'The circuit was never closed.');
        } else {
            $a->resume();
            self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $this->decision($a));
        }
        self::assertCount(1, $signals->getEmitted(), 'The announcement was already made; it was not made again.');
    }
}
