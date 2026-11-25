<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Redis;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\ApiHeavyProtectionPolicy;
use Maatify\RateLimiter\Config\FixedWindowThrottlePolicy;
use Maatify\RateLimiter\Config\LoginProtectionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\E1AccountScopeKeyDeriver;
use Maatify\RateLimiter\Service\SimpleFixedWindowKeyDeriver;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\NonAccountKeyOracle;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\RateLimiter\Tests\Support\Redis\StateSnapshot;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * DEC-047 (G2) real isolated Redis integration verification:
 * - A4 isolation & negative controls on a shared Redis namespace and secret;
 * - Writer/reader parity across Current/Previous rotations, IPv4/mapped IPv6/IPv6 prefixes, custom/default policies,
 *   and strict reader read-only preservation;
 * - Byte-for-byte physical key preservation for E1 (`e1:`) and simple fixed-window (`v1:`);
 * - Rotation (outer-only, fingerprint-only alias+bridge, both), Previous read-only, K5 snapshot block target under `e1:`,
 *   single-commit request atomicity and ACL preflight (`NOPERM`).
 */
#[Group('integration')]
#[Group('php32')]
#[Group('g2-non-account-framing')]
final class NonAccountKeyFramingRedisTest extends TestCase
{
    private const NOW = 1_793_793_600;
    private const NAMESPACE = 'g2-284-framing';
    private const SECRET = 'synthetic-g2-secret-not-for-production';
    private const PREV_SECRET = 'synthetic-g2-prev-secret-not-for-production';
    private const FP_SECRET = 'synthetic-g2-fp-secret-not-for-production';
    private const PREV_FP_SECRET = 'synthetic-g2-prev-fp-secret-not-for-production';
    private const ACL_PASSWORD = 'synthetic-g2-acl-password';

    private RespRedisCommandExecutor $admin;
    private string $host;
    private int $port;
    /** @var list<string> */
    private array $users = [];

    protected function setUp(): void
    {
        $host = getenv('REDIS_INTEGRATION_HOST');
        $port = getenv('REDIS_INTEGRATION_PORT');
        if ($host === false || $port === false) {
            self::markTestSkipped('Real Redis integration orchestration is required for this suite.');
        }
        $this->host = $host;
        $this->port = (int) $port;
        $this->admin = new RespRedisCommandExecutor($host, $this->port);
        $this->admin->execute(['FLUSHDB']);
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            foreach ($this->users as $user) {
                $this->admin->execute(['ACL', 'DELUSER', $user]);
            }
            $this->admin->execute(['FLUSHDB']);
        }
    }

    public function testSharedRedisA4IsolationAndNegativeControlForScoresBlocksAndCorrelation(): void
    {
        $clock = new FixedClock('@' . self::NOW);

        // Pair A vs Pair B: classic A4 colon-boundary shift sharing ONE Redis namespace and ONE secret.
        $policyA = $this->namedApiPolicy('a:rate_limiter:k1:v2:b', ['k1' => 2, 'k2' => 999, 'k3' => 999]);
        $policyB = $this->namedApiPolicy('a', ['k1' => 2, 'k2' => 999, 'k3' => 999]);

        $builderA = $this->builder($this->admin, 'c', $clock)->withPolicy($policyA);
        $builderB = $this->builder($this->admin, 'b:rate_limiter:k1:v2:c', $clock)->withPolicy($policyB);

        $runtimeA = $builderA->build();
        $readerA = $builderA->buildOperationalReader();
        $runtimeB = $builderB->build();
        $readerB = $builderB->buildOperationalReader();

        $context = new RateLimitContextDTO('192.0.2.77', 'Mozilla/5.0 Chrome/126.0.0.0', null, ['canvas' => 'v1']);

        // Trigger K1 hard block on Policy A.
        $runtimeA->limit($context, RateLimitCommand::recordFailure($policyA->getName()));
        $blockedA = $runtimeA->limit($context, RateLimitCommand::recordFailure($policyA->getName()));
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $blockedA->decision);

        // Negative control: same (policyA, env='c') DOES observe the score and hard block, including via IPv4-mapped IPv6!
        $mappedContext = new RateLimitContextDTO('::ffff:192.0.2.77', 'Mozilla/5.0 Chrome/126.0.0.0', null, ['canvas' => 'v1']);
        $beforeRead = $this->snapshot();
        $snapA = $readerA->readScorePolicy($mappedContext, $policyA->getName());
        self::assertSame(2, $snapA->scopes->k1->effectiveScore);
        self::assertNotNull($snapA->scopes->k1->activeHardBlock);
        self::assertSame(2, $snapA->scopes->k2->effectiveScore);
        self::assertNotNull($snapA->scopes->k3);
        self::assertSame(2, $snapA->scopes->k3->effectiveScore);

        // Policy B on the colliding (policy, env) pair observes ZERO score and ZERO block.
        $snapB = $readerB->readScorePolicy($context, $policyB->getName());
        self::assertSame(0, $snapB->scopes->k1->effectiveScore);
        self::assertNull($snapB->scopes->k1->activeHardBlock);
        self::assertSame(0, $snapB->scopes->k2->effectiveScore);
        self::assertNotNull($snapB->scopes->k3);
        self::assertSame(0, $snapB->scopes->k3->effectiveScore);

        // Reader must be strictly read-only on real Redis.
        self::assertSame($beforeRead, $this->snapshot(), 'Operational reader must leave Redis state byte-identical.');

        // Legitimate user on Policy B is allowed.
        self::assertSame(
            RateLimitResultDTO::DECISION_ALLOW,
            $runtimeB->limit($context, RateLimitCommand::checkOnly($policyB->getName()))->decision,
        );

        // Verify physical key lives under v1 (not e1) with exact DEC-047 logical digest.
        $expectedK1Logical = NonAccountKeyOracle::k1($policyA->getName(), 'c', '192.0.2.77', self::SECRET);
        $expectedK1Physical = self::v1PhysicalKey('score', $expectedK1Logical);
        self::assertContains($expectedK1Physical, $this->scanAllKeys());

        // Dilution correlation A4 isolation + negative control on real Redis.
        $loginA = $this->namedLoginPolicy('a:rate_limiter:correlation:dilution:v1:b');
        $loginB = $this->namedLoginPolicy('a');
        $loginRuntimeA = $this->builder($this->admin, 'c', $clock)->withPolicy($loginA)->build();
        $loginRuntimeB = $this->builder($this->admin, 'b:rate_limiter:correlation:dilution:v1:c', $clock)->withPolicy($loginB)->build();

        for ($i = 1; $i <= 5; $i++) {
            self::assertSame(
                RateLimitResultDTO::DECISION_ALLOW,
                $loginRuntimeA->limit(new RateLimitContextDTO("198.51.100.{$i}", 'Mozilla/5.0 Chrome/126.0.0.0'), RateLimitCommand::checkOnly($loginA->getName()))->decision,
            );
        }

        // Policy B must remain ALLOW on its first request from IP .6 (isolated).
        self::assertSame(
            RateLimitResultDTO::DECISION_ALLOW,
            $loginRuntimeB->limit(new RateLimitContextDTO('198.51.100.6', 'Mozilla/5.0 Chrome/126.0.0.0'), RateLimitCommand::checkOnly($loginB->getName()))->decision,
        );

        // Negative control: Policy A on IP .6 DOES trigger the 6th-IP dilution HARD_BLOCK.
        self::assertSame(
            RateLimitResultDTO::DECISION_HARD_BLOCK,
            $loginRuntimeA->limit(new RateLimitContextDTO('198.51.100.6', 'Mozilla/5.0 Chrome/126.0.0.0'), RateLimitCommand::checkOnly($loginA->getName()))->decision,
        );
    }

    public function testE1AndSimpleFixedWindowPhysicalKeysStayByteIdenticalBeforeAndAfterG2(): void
    {
        $clock = new FixedClock('@' . self::NOW);
        $simplePolicy = new FixedWindowThrottlePolicy('public_burst', 10, 60);

        $builder = $this->builder($this->admin, 'prod-eu', $clock)
            ->withSimpleThrottlePolicy($simplePolicy);

        $runtime = $builder->build();
        $reader = $builder->buildOperationalReader();

        // Trigger account E1 key + non-account K1–K3 keys in one login failure.
        $runtime->limit(
            new RateLimitContextDTO('203.0.113.10', 'Mozilla/5.0 Chrome/126.0.0.0', 'acct-0001', ['canvas' => 'v1']),
            RateLimitCommand::recordFailure('login_protection'),
        );

        // Trigger simple fixed-window budget key.
        $simpleResult = $runtime->consume('public_burst', 'subj-0001', 2);
        self::assertTrue($simpleResult->allowed);
        self::assertSame(2, $reader->readSimpleThrottle('public_burst', 'subj-0001')->count);

        $keys = $this->scanAllKeys();

        // E1 account score key keeps exact e1: physical prefix & unchanged DEC-046 E1 digest.
        $expectedE1K4 = E1AccountScopeKeyDeriver::key(self::SECRET, ['k4', 'login_protection', 'prod-eu', 'acct-0001']);
        self::assertContains(self::e1PhysicalKey('score', $expectedE1K4), $keys);

        // Simple fixed-window key keeps exact v1: physical prefix & pre-G2 wire digest.
        $expectedSimpleLogical = SimpleFixedWindowKeyDeriver::preimage('prod-eu', 'public_burst', 10, 60, 'subj-0001');
        $expectedSimpleDigest = hash_hmac('sha256', $expectedSimpleLogical, self::SECRET);
        self::assertContains(self::v1PhysicalKey('budget', $expectedSimpleDigest), $keys);
    }

    public function testRotationContinuityAcrossOuterOnlyFingerprintOnlyAndBothWithPreviousReadOnly(): void
    {
        $clock = new FixedClock('@' . self::NOW);
        $policy = new ApiHeavyProtectionPolicy(['k1' => 10, 'k2' => 10, 'k3' => 10]);
        $context = new RateLimitContextDTO('2001:db8:0:1::42', 'Mozilla/5.0 Chrome/126.0.0.0', null, ['gpu' => 'alpha']);

        // 1. Seed initial state under PREV_SECRET + PREV_FP_SECRET.
        $oldRuntime = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig(self::PREV_SECRET, self::PREV_FP_SECRET, 'prod:eu'),
            new RedisFullCapabilityStore($this->admin, self::NAMESPACE),
            new RecordingFailureSignalEmitter(),
        )->withClock($clock)->withPolicy($policy)->build();

        $oldRuntime->limit($context, RateLimitCommand::recordFailure('api_heavy_protection'));
        $oldRuntime->limit($context, RateLimitCommand::recordFailure('api_heavy_protection'));
        $previousKeysSnapshot = $this->snapshot();

        // 2. Rotate BOTH outer secret and fingerprint secret; reader & writer must read Previous read-only and write Current.
        $rotatedBuilder = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig(self::SECRET, self::FP_SECRET, 'prod:eu', self::PREV_SECRET, self::PREV_FP_SECRET),
            new RedisFullCapabilityStore($this->admin, self::NAMESPACE),
            new RecordingFailureSignalEmitter(),
        )->withClock($clock)->withPolicy($policy);

        $rotatedReader = $rotatedBuilder->buildOperationalReader();
        $snapBeforeWrite = $rotatedReader->readScorePolicy($context, 'api_heavy_protection');
        self::assertSame(2, $snapBeforeWrite->scopes->k1->effectiveScore);
        self::assertTrue($snapBeforeWrite->scopes->k1->scoreFromPreviousGeneration);
        self::assertSame(2, $snapBeforeWrite->scopes->k2->effectiveScore);
        self::assertNotNull($snapBeforeWrite->scopes->k3);
        self::assertSame(2, $snapBeforeWrite->scopes->k3->effectiveScore);
        self::assertTrue($snapBeforeWrite->scopes->k3->scoreFromPreviousGeneration);
        self::assertSame($previousKeysSnapshot, $this->snapshot(), 'Read-only inspection during rotation must not mutate Redis.');

        $rotatedRuntime = $rotatedBuilder->build();
        $rotatedRuntime->limit($context, RateLimitCommand::recordFailure('api_heavy_protection'));

        // Every Previous physical key's value & PEXPIRETIME must remain completely untouched (Previous is read-only).
        $afterWrite = $this->snapshot();
        foreach ($previousKeysSnapshot as $key => $entry) {
            self::assertArrayHasKey($key, $afterWrite, "Previous key {$key} must remain present.");
            self::assertSame($entry, $afterWrite[$key], "Previous key {$key} must remain read-only (value & PEXPIRETIME unchanged).");
        }

        // Current generation now holds score 3 (2 carried + 1 new) and reader sees Current generation.
        $snapAfterWrite = $rotatedReader->readScorePolicy($context, 'api_heavy_protection');
        self::assertSame(3, $snapAfterWrite->scopes->k1->effectiveScore);
        self::assertFalse($snapAfterWrite->scopes->k1->scoreFromPreviousGeneration);
        self::assertNotNull($snapAfterWrite->scopes->k3);
        self::assertSame(3, $snapAfterWrite->scopes->k3->effectiveScore);
        self::assertFalse($snapAfterWrite->scopes->k3->scoreFromPreviousGeneration);
    }

    public function testDistributedAccountAttackK5SnapshotMembersBlockUnderE1LayoutAndAclPreflightLeavesBothLayoutsUnchanged(): void
    {
        $clock = new FixedClock('@' . self::NOW);
        $runtime = $this->builder($this->admin, 'prod:eu', $clock)->build();

        // 4 distinct devices hitting the same account trigger distributed_account_attack -> K5 snapshot members blocked under e1:block:.
        $last = null;
        for ($i = 1; $i <= 4; $i++) {
            $ctx = new RateLimitContextDTO("203.0.113.{$i}", 'Mozilla/5.0 Chrome/126.0.0.0', 'acct-dist', ['dev' => "d{$i}"]);
            $last = $runtime->limit($ctx, RateLimitCommand::checkOnly('login_protection'));
        }
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $last->decision);

        $keys = $this->scanAllKeys();
        $e1Blocks = array_values(array_filter(
            $keys,
            static fn(string $k): bool => str_starts_with($k, self::e1Prefix() . 'block:'),
        ));
        self::assertCount(4, $e1Blocks, 'All 4 K5 snapshot members must be persisted as blocks under the e1:block: layout.');

        // ACL preflight (DEC-042): Redis user can read v1 + e1 keys and run EVAL, but lacks `hset` required by the commit plan;
        // commit must fail with `NOPERM request commit:` before any write, leaving both v1 and e1 keyspaces completely unchanged.
        $user = 'g2_ro_' . bin2hex(random_bytes(4));
        $this->users[] = $user;
        $this->admin->execute([
            'ACL', 'SETUSER', $user, 'reset', 'on', '>' . self::ACL_PASSWORD,
            '~maatify:rate-limiter:*', '~',
            '+eval', '+evalsha', '+time', '+exists', '+type', '+pexpiretime', '+get', '+hgetall', '+smembers', '+zrange',
            '+del', '+set', '+sadd', '+zadd', '+pexpireat', '+hget', '+hmget', '+ping',
        ]);

        $beforeAclAttempt = $this->snapshot();
        $restrictedExecutor = new RespRedisCommandExecutor($this->host, $this->port);
        self::assertSame('OK', $restrictedExecutor->execute(['AUTH', $user, self::ACL_PASSWORD]));
        $restrictedRuntime = $this->builder($restrictedExecutor, 'prod:eu', $clock)->build();

        try {
            $restrictedRuntime->limit(
                new RateLimitContextDTO('203.0.113.99', 'Mozilla/5.0 Chrome/126.0.0.0', 'acct-acl', ['dev' => 'acl']),
                RateLimitCommand::recordFailure('login_protection'),
            );
            self::fail('ACL preflight must refuse the commit with NOPERM.');
        } catch (\Maatify\RateLimiter\Exception\RateLimiterException $exception) {
            self::assertStringContainsString('NOPERM request commit:', $exception->getMessage());
        }
        self::assertSame($beforeAclAttempt, $this->snapshot(), 'ACL preflight NOPERM must leave both v1 and e1 keyspaces completely unchanged.');
    }

    public function testFingerprintOnlyAliasRotationAndIpv6AdaptiveA4IsolationOnRealRedis(): void
    {
        $clock = new FixedClock('@' . self::NOW);

        // 1. Fingerprint-only rotation on churn correlation: outer secret unchanged -> currentScope === previousScope (alias!).
        // Previous aliased distinct set must remain read-only while the 3rd fingerprint enters the bridge and triggers churn HARD_BLOCK.
        $preRotRuntime = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig(self::SECRET, self::PREV_FP_SECRET, 'prod:eu'),
            new RedisFullCapabilityStore($this->admin, self::NAMESPACE),
            new RecordingFailureSignalEmitter(),
        )->withClock($clock)->build();

        $preRotRuntime->limit(new RateLimitContextDTO('198.51.100.55', 'Mozilla/5.0 Chrome/126.0.0.0', null, ['d' => '1']), RateLimitCommand::checkOnly('login_protection'));
        $preRotRuntime->limit(new RateLimitContextDTO('198.51.100.55', 'Mozilla/5.0 Chrome/126.0.0.0', null, ['d' => '2']), RateLimitCommand::checkOnly('login_protection'));

        $k2 = NonAccountKeyOracle::k2('login_protection', 'prod:eu', '198.51.100.55', 'chrome/126', self::SECRET);
        $churnScope = NonAccountKeyOracle::derive('correlation.churn.scope', 'login_protection', 'prod:eu', self::SECRET, $k2);
        $churnDistinctPhysical = self::v1PhysicalKey('distinct', $churnScope);
        $aliasedBefore = StateSnapshot::of($this->admin, $churnDistinctPhysical);
        self::assertNotSame('absent', $aliasedBefore);

        $fpRotatedRuntime = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig(self::SECRET, self::FP_SECRET, 'prod:eu', null, self::PREV_FP_SECRET),
            new RedisFullCapabilityStore($this->admin, self::NAMESPACE),
            new RecordingFailureSignalEmitter(),
        )->withClock($clock)->build();

        $blocked = $fpRotatedRuntime->limit(
            new RateLimitContextDTO('198.51.100.55', 'Mozilla/5.0 Chrome/126.0.0.0', null, ['d' => '3']),
            RateLimitCommand::checkOnly('login_protection'),
        );
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $blocked->decision);
        self::assertSame(
            $aliasedBefore,
            StateSnapshot::of($this->admin, $churnDistinctPhysical),
            'Aliased Previous distinct set under fingerprint-only rotation must remain strictly read-only.',
        );

        // 2. IPv6 adaptive hierarchy/spray A4 isolation on shared Redis namespace & secret.
        $this->admin->execute(['FLUSHDB']);
        $policyA = $this->namedLoginPolicy('a:rate_limiter:ipv6_adaptive:hierarchy:v1:48:b');
        $policyB = $this->namedLoginPolicy('a');
        $runtimeA = $this->builder($this->admin, 'c', $clock)->withPolicy($policyA)->build();
        $runtimeB = $this->builder($this->admin, 'b:rate_limiter:ipv6_adaptive:hierarchy:v1:48:c', $clock)->withPolicy($policyB)->build();

        // Policy A activates /48 across 2 distinct /64 prefixes (subnet 1 then subnet 2), then fills 4 distinct macro spray subjects (spray-2..spray-5 -> 4 distinct).
        $runtimeA->limit(new RateLimitContextDTO('2001:db8:1:1::1', 'Mozilla/5.0 Chrome/126.0.0.0', 'spray-1'), RateLimitCommand::checkOnly($policyA->getName()));
        for ($i = 2; $i <= 5; $i++) {
            self::assertSame(
                RateLimitResultDTO::DECISION_ALLOW,
                $runtimeA->limit(
                    new RateLimitContextDTO("2001:db8:1:{$i}::1", 'Mozilla/5.0 Chrome/126.0.0.0', "spray-{$i}"),
                    RateLimitCommand::checkOnly($policyA->getName()),
                )->decision,
            );
        }

        // Policy B's 1st request in the same /48 must stay ALLOW (must not collide on Policy A's /48 hierarchy or macro spray state).
        $resB = $runtimeB->limit(
            new RateLimitContextDTO('2001:db8:1:9::1', 'Mozilla/5.0 Chrome/126.0.0.0', 'spray-6'),
            RateLimitCommand::checkOnly($policyB->getName()),
        );
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $resB->decision);

        // Negative control: Policy A's 5th distinct subject observed after /48 activation DOES trigger macro spray HARD_BLOCK.
        $resA6 = $runtimeA->limit(
            new RateLimitContextDTO('2001:db8:1:6::1', 'Mozilla/5.0 Chrome/126.0.0.0', 'spray-6'),
            RateLimitCommand::checkOnly($policyA->getName()),
        );
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $resA6->decision);
    }

    private function builder(RedisCommandExecutorInterface $executor, string $envScope, FixedClock $clock): RateLimiterBuilder
    {
        return RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig(self::SECRET, self::FP_SECRET, $envScope),
            new RedisFullCapabilityStore($executor, self::NAMESPACE),
            new RecordingFailureSignalEmitter(),
        )->withClock($clock);
    }

    /** @return list<string> */
    private function scanAllKeys(): array
    {
        $keys = [];
        $cursor = '0';
        do {
            $reply = $this->admin->execute(['SCAN', $cursor, 'COUNT', 1000]);
            self::assertIsArray($reply);
            self::assertIsString($reply[0]);
            $cursor = $reply[0];
            self::assertIsArray($reply[1]);
            foreach ($reply[1] as $key) {
                self::assertIsString($key);
                $keys[] = $key;
            }
        } while ($cursor !== '0');
        sort($keys);

        return $keys;
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        return StateSnapshot::all($this->admin);
    }

    private static function v1Prefix(): string
    {
        return 'maatify:rate-limiter:v1:' . hash('sha256', self::NAMESPACE) . ':';
    }

    private static function e1Prefix(): string
    {
        return 'maatify:rate-limiter:e1:' . hash('sha256', self::NAMESPACE) . ':';
    }

    private static function v1PhysicalKey(string $family, string $logical): string
    {
        return self::v1Prefix() . $family . ':' . hash('sha256', $logical);
    }

    private static function e1PhysicalKey(string $family, string $logical): string
    {
        return self::e1Prefix() . $family . ':' . hash('sha256', $logical);
    }

    /** @param array<string, int> $limits */
    private function namedApiPolicy(string $name, array $limits): ApiHeavyProtectionPolicy
    {
        return new class ($name, $limits) extends ApiHeavyProtectionPolicy {
            /** @param array<string, int> $limits */
            public function __construct(private readonly string $policyName, array $limits)
            {
                parent::__construct($limits);
            }

            public function getName(): string
            {
                return $this->policyName;
            }
        };
    }

    private function namedLoginPolicy(string $name): LoginProtectionPolicy
    {
        return new class ($name) extends LoginProtectionPolicy {
            public function __construct(private readonly string $policyName) {}

            public function getName(): string
            {
                return $this->policyName;
            }
        };
    }
}
