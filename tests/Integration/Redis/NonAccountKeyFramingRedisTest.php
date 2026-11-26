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
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use Maatify\RateLimiter\Tests\Support\NonAccountKeyOracle;
use Maatify\RateLimiter\Tests\Support\Redis\RespRedisCommandExecutor;
use Maatify\RateLimiter\Tests\Support\Redis\StateSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * DEC-047 (G2) real isolated Redis integration verification:
 * - Independent A4 isolation & negative controls for EACH of K1, K2 (including L1 SOFT_BLOCK runtime isolation),
 *   K3, dilution correlation, and IPv6 adaptive hierarchy/spray on a shared Redis namespace and secret;
 * - Writer/reader parity across all three rotation shapes (outer-only, fingerprint-only with aliased Previous set,
 *   and both rotated), K1/K2/K3 before and after write, Previous read-only (`DUMP` + `PEXPIRETIME`), correlation
 *   rotation bridges with capped TTL, and strict reader read-only preservation;
 * - Independent hardcoded-golden physical key preservation for E1 (`e1:`) and simple fixed-window (`v1:budget`);
 * - K5 snapshot block targets under `e1:block:` and DEC-042 ACL preflight (`NOPERM`) across both layouts.
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

    /**
     * Independent fixed goldens computed outside production derivers (KEY_SCOPE_ENCODING_E1.md §3.7 & DEC-047 §4)
     * for secret `synthetic-key-secret-not-for-production`.
     */
    private const GOLDEN_SECRET = 'synthetic-key-secret-not-for-production';
    private const GOLDEN_E1_K4 = 'e1.9678f6f2458e4fc59a0b91701343b4a1e07c81144d9e40025bb2e9aa2b825fa7';
    private const GOLDEN_SIMPLE_KEY = '7986d5743dd9092f6afaf0b23e08e8ec2e971d2e6603ac99619062a55702ef81';

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

    /**
     * @return array<string, array{
     *     scope: 'k1'|'k2'|'k3',
     *     policyA: string,
     *     envA: string,
     *     policyB: string,
     *     envB: string,
     *     limits: array<string, int>,
     *     expectedDecisionA: string,
     *     expectHardBlockA: bool
     * }>
     */
    public static function a4ScoreScopePairs(): array
    {
        return [
            'K1 exact colon-shift pair' => [
                'scope' => 'k1',
                'policyA' => 'a:rate_limiter:k1:v2:b',
                'envA' => 'c',
                'policyB' => 'a',
                'envB' => 'b:rate_limiter:k1:v2:c',
                'limits' => ['k1' => 2, 'k2' => 999, 'k3' => 999],
                'expectedDecisionA' => RateLimitResultDTO::DECISION_HARD_BLOCK,
                'expectHardBlockA' => true,
            ],
            'K2 exact colon-shift pair (L1 SOFT_BLOCK isolation)' => [
                'scope' => 'k2',
                'policyA' => 'a:rate_limiter:k2:v2:b',
                'envA' => 'c',
                'policyB' => 'a',
                'envB' => 'b:rate_limiter:k2:v2:c',
                'limits' => ['k1' => 999, 'k2' => 2, 'k3' => 999],
                'expectedDecisionA' => RateLimitResultDTO::DECISION_SOFT_BLOCK,
                'expectHardBlockA' => false,
            ],
            'K3 exact colon-shift pair' => [
                'scope' => 'k3',
                'policyA' => 'a:rate_limiter:k3:v2:b',
                'envA' => 'c',
                'policyB' => 'a',
                'envB' => 'b:rate_limiter:k3:v2:c',
                'limits' => ['k1' => 999, 'k2' => 999, 'k3' => 2],
                'expectedDecisionA' => RateLimitResultDTO::DECISION_HARD_BLOCK,
                'expectHardBlockA' => true,
            ],
        ];
    }

    /**
     * @param 'k1'|'k2'|'k3' $scope
     * @param array<string, int> $limits
     */
    #[DataProvider('a4ScoreScopePairs')]
    public function testSharedRedisA4IsolationAndNegativeControlForEachOfK1K2K3(
        string $scope,
        string $policyA,
        string $envA,
        string $policyB,
        string $envB,
        array $limits,
        string $expectedDecisionA,
        bool $expectHardBlockA,
    ): void {
        $clock = new FixedClock('@' . self::NOW);

        $objPolicyA = $this->namedApiPolicy($policyA, $limits);
        $objPolicyB = $this->namedApiPolicy($policyB, $limits);

        $builderA = $this->builder($this->admin, $envA, $clock)->withPolicy($objPolicyA);
        $builderB = $this->builder($this->admin, $envB, $clock)->withPolicy($objPolicyB);

        $runtimeA = $builderA->build();
        $readerA = $builderA->buildOperationalReader();
        $runtimeB = $builderB->build();
        $readerB = $builderB->buildOperationalReader();

        $context = new RateLimitContextDTO('192.0.2.77', 'Mozilla/5.0 Chrome/126.0.0.0', null, ['canvas' => 'v1']);
        $mappedContext = new RateLimitContextDTO('::ffff:192.0.2.77', 'Mozilla/5.0 Chrome/126.0.0.0', null, ['canvas' => 'v1']);

        // Sanity: the pre-G2 legacy preimage for this exact scope WOULD collide on (policyA, envA) vs (policyB, envB).
        $fpHash = hash_hmac('sha256', 'v2|chrome/126|{"canvas":"v1"}|', self::FP_SECRET);
        $legacySuffix = match ($scope) {
            'k1' => '192.0.2.77',
            'k2' => '192.0.2.77:chrome/126',
            'k3' => "192.0.2.77:{$fpHash}",
        };
        self::assertSame(
            "{$policyA}:rate_limiter:{$scope}:v2:{$envA}:{$legacySuffix}",
            "{$policyB}:rate_limiter:{$scope}:v2:{$envB}:{$legacySuffix}",
            "Fixture pair must be a true pre-G2 legacy collision witness for {$scope}.",
        );

        // Trigger the configured block on Policy A.
        $runtimeA->limit($context, RateLimitCommand::recordFailure($policyA));
        $blockedA = $runtimeA->limit($context, RateLimitCommand::recordFailure($policyA));
        self::assertSame($expectedDecisionA, $blockedA->decision);

        // Negative control on Policy A (including IPv4-mapped IPv6 canonicalization): reader and checkOnly runtime observe Policy A's state.
        $beforeRead = $this->snapshot();
        $snapA = $readerA->readScorePolicy($mappedContext, $policyA);
        $stateA = match ($scope) {
            'k1' => $snapA->scopes->k1,
            'k2' => $snapA->scopes->k2,
            'k3' => $snapA->scopes->k3,
        };
        self::assertNotNull($stateA);
        self::assertSame(2, $stateA->effectiveScore);
        if ($expectHardBlockA) {
            self::assertNotNull($stateA->activeHardBlock);
        }

        // Policy B on the exact colliding pair observes ZERO score and ZERO block for the target scope.
        $snapB = $readerB->readScorePolicy($context, $policyB);
        $stateB = match ($scope) {
            'k1' => $snapB->scopes->k1,
            'k2' => $snapB->scopes->k2,
            'k3' => $snapB->scopes->k3,
        };
        self::assertNotNull($stateB);
        self::assertNull($stateB->score);
        self::assertSame(0, $stateB->effectiveScore, "Policy B {$scope} score must not observe Policy A state.");
        self::assertNull($stateB->activeHardBlock, "Policy B {$scope} block must not observe Policy A block.");

        // Operational reader must leave real Redis state byte-identical.
        self::assertSame($beforeRead, $this->snapshot(), 'Operational reader must leave Redis state byte-identical.');

        // Negative control on Policy A runtime vs isolation on Policy B runtime (proves K2 L1 SOFT_BLOCK and K1/K3 HARD_BLOCK do not leak).
        self::assertSame(
            $expectedDecisionA,
            $runtimeA->limit($mappedContext, RateLimitCommand::checkOnly($policyA))->decision,
            "Policy A checkOnly must enforce {$scope} block.",
        );
        self::assertSame(
            RateLimitResultDTO::DECISION_ALLOW,
            $runtimeB->limit($context, RateLimitCommand::checkOnly($policyB))->decision,
            "Policy B checkOnly must remain ALLOW and never inherit Policy A {$scope} block.",
        );

        // Verify raw physical Redis keys under v1:score: and v1:block: exist for Policy A and do NOT exist for Policy B.
        $logicalA = match ($scope) {
            'k1' => NonAccountKeyOracle::k1($policyA, $envA, '192.0.2.77', self::SECRET),
            'k2' => NonAccountKeyOracle::k2($policyA, $envA, '192.0.2.77', 'chrome/126', self::SECRET),
            'k3' => NonAccountKeyOracle::k3($policyA, $envA, '192.0.2.77', $fpHash, self::SECRET),
        };
        $logicalB = match ($scope) {
            'k1' => NonAccountKeyOracle::k1($policyB, $envB, '192.0.2.77', self::SECRET),
            'k2' => NonAccountKeyOracle::k2($policyB, $envB, '192.0.2.77', 'chrome/126', self::SECRET),
            'k3' => NonAccountKeyOracle::k3($policyB, $envB, '192.0.2.77', $fpHash, self::SECRET),
        };
        self::assertNotSame($logicalA, $logicalB);

        $keys = $this->scanAllKeys();
        self::assertContains(self::v1PhysicalKey('score', $logicalA), $keys);
        self::assertContains(self::v1PhysicalKey('block', $logicalA), $keys);
        self::assertNotContains(self::v1PhysicalKey('score', $logicalB), $keys);
        self::assertNotContains(self::v1PhysicalKey('block', $logicalB), $keys);
    }

    public function testSharedRedisDilutionCorrelationA4IsolationAndNegativeControl(): void
    {
        $clock = new FixedClock('@' . self::NOW);

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

    public function testE1AndSimpleFixedWindowPhysicalKeysStayByteIdenticalAgainstIndependentGoldens(): void
    {
        $clock = new FixedClock('@' . self::NOW);
        $simplePolicy = new FixedWindowThrottlePolicy('public_burst', 10, 60);

        $builder = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig(self::GOLDEN_SECRET, self::FP_SECRET, 'prod-eu'),
            new RedisFullCapabilityStore($this->admin, self::NAMESPACE),
            new RecordingFailureSignalEmitter(),
        )->withClock($clock)->withSimpleThrottlePolicy($simplePolicy);

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

        // E1 account score key matches the independent KEY_SCOPE_ENCODING_E1.md §3.7 golden under `e1:score:` (never computed from production derivers).
        self::assertContains(self::e1PhysicalKey('score', self::GOLDEN_E1_K4), $keys);

        // Simple fixed-window budget key matches the independent DEC-047 §4 pre-G2 golden under `v1:budget:` (never computed from production derivers).
        self::assertContains(self::v1PhysicalKey('budget', self::GOLDEN_SIMPLE_KEY), $keys);
    }

    /**
     * @return array<string, array{
     *     mode: 'outer-only'|'fingerprint-only'|'both',
     *     seedSecret: string,
     *     seedFpSecret: string,
     *     prevSecret: string|null,
     *     prevFpSecret: string|null,
     *     k1FromPrevBeforeWrite: bool,
     *     k2FromPrevBeforeWrite: bool,
     *     k3FromPrevBeforeWrite: bool
     * }>
     */
    public static function rotationShapes(): array
    {
        return [
            'outer-only rotation' => [
                'mode' => 'outer-only',
                'seedSecret' => self::PREV_SECRET,
                'seedFpSecret' => self::FP_SECRET,
                'prevSecret' => self::PREV_SECRET,
                'prevFpSecret' => null,
                'k1FromPrevBeforeWrite' => true,
                'k2FromPrevBeforeWrite' => true,
                'k3FromPrevBeforeWrite' => true,
            ],
            'fingerprint-only rotation' => [
                'mode' => 'fingerprint-only',
                'seedSecret' => self::SECRET,
                'seedFpSecret' => self::PREV_FP_SECRET,
                'prevSecret' => null,
                'prevFpSecret' => self::PREV_FP_SECRET,
                'k1FromPrevBeforeWrite' => false,
                'k2FromPrevBeforeWrite' => false,
                'k3FromPrevBeforeWrite' => true,
            ],
            'both outer and fingerprint rotated' => [
                'mode' => 'both',
                'seedSecret' => self::PREV_SECRET,
                'seedFpSecret' => self::PREV_FP_SECRET,
                'prevSecret' => self::PREV_SECRET,
                'prevFpSecret' => self::PREV_FP_SECRET,
                'k1FromPrevBeforeWrite' => true,
                'k2FromPrevBeforeWrite' => true,
                'k3FromPrevBeforeWrite' => true,
            ],
        ];
    }

    /**
     * @param 'outer-only'|'fingerprint-only'|'both' $mode
     */
    #[DataProvider('rotationShapes')]
    public function testScoreAndCorrelationRotationContinuityWithPreviousReadOnlyAndBridgeTtlCap(
        string $mode,
        string $seedSecret,
        string $seedFpSecret,
        ?string $prevSecret,
        ?string $prevFpSecret,
        bool $k1FromPrevBeforeWrite,
        bool $k2FromPrevBeforeWrite,
        bool $k3FromPrevBeforeWrite,
    ): void {
        $clock = new FixedClock('@' . self::NOW);
        $policy = new ApiHeavyProtectionPolicy(['k1' => 10, 'k2' => 10, 'k3' => 10]);
        $context = new RateLimitContextDTO('2001:db8:0:1::42', 'Mozilla/5.0 Chrome/126.0.0.0', null, ['gpu' => 'alpha']);

        // 1. Seed initial K1–K3 scores under the pre-rotation secret pair.
        $oldRuntime = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig($seedSecret, $seedFpSecret, 'prod:eu'),
            new RedisFullCapabilityStore($this->admin, self::NAMESPACE),
            new RecordingFailureSignalEmitter(),
        )->withClock($clock)->withPolicy($policy)->build();

        $oldRuntime->limit($context, RateLimitCommand::recordFailure('api_heavy_protection'));
        $oldRuntime->limit($context, RateLimitCommand::recordFailure('api_heavy_protection'));
        $beforeRotationSnapshot = $this->snapshot();

        // Capture exact Previous K3 key snapshot (always rotated in all 3 modes).
        $prevFpHash = hash_hmac('sha256', 'v2|chrome/126|{"gpu":"alpha"}|', $seedFpSecret);
        $prevK3Logical = NonAccountKeyOracle::k3('api_heavy_protection', 'prod:eu', '20010db800000001', $prevFpHash, $seedSecret);
        $prevK3Physical = self::v1PhysicalKey('score', $prevK3Logical);
        $prevK3Before = StateSnapshot::of($this->admin, $prevK3Physical);
        self::assertNotSame('absent', $prevK3Before);

        // 2. Build rotated runtime & reader for this exact rotation mode.
        $rotatedBuilder = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig(self::SECRET, self::FP_SECRET, 'prod:eu', $prevSecret, $prevFpSecret),
            new RedisFullCapabilityStore($this->admin, self::NAMESPACE),
            new RecordingFailureSignalEmitter(),
        )->withClock($clock)->withPolicy($policy);

        $rotatedReader = $rotatedBuilder->buildOperationalReader();
        $snapBeforeWrite = $rotatedReader->readScorePolicy($context, 'api_heavy_protection');
        self::assertSame(2, $snapBeforeWrite->scopes->k1->effectiveScore);
        self::assertSame($k1FromPrevBeforeWrite, $snapBeforeWrite->scopes->k1->scoreFromPreviousGeneration);
        self::assertSame(2, $snapBeforeWrite->scopes->k2->effectiveScore);
        self::assertSame($k2FromPrevBeforeWrite, $snapBeforeWrite->scopes->k2->scoreFromPreviousGeneration);
        self::assertNotNull($snapBeforeWrite->scopes->k3);
        self::assertSame(2, $snapBeforeWrite->scopes->k3->effectiveScore);
        self::assertSame($k3FromPrevBeforeWrite, $snapBeforeWrite->scopes->k3->scoreFromPreviousGeneration);
        self::assertSame($beforeRotationSnapshot, $this->snapshot(), "Read-only inspection during {$mode} must not mutate Redis.");

        // 3. Write 1 failure on rotated runtime -> Current holds 3 across K1/K2/K3; Previous stays strictly read-only.
        $rotatedRuntime = $rotatedBuilder->build();
        $rotatedRuntime->limit($context, RateLimitCommand::recordFailure('api_heavy_protection'));

        $afterWrite = $this->snapshot();
        if ($prevSecret !== null) {
            // Outer secret rotated: EVERY pre-rotation physical key is Previous and must stay byte-identical (DUMP + PEXPIRETIME).
            foreach ($beforeRotationSnapshot as $key => $entry) {
                self::assertArrayHasKey($key, $afterWrite, "Previous key {$key} must remain present under {$mode}.");
                self::assertSame($entry, $afterWrite[$key], "Previous key {$key} must remain strictly read-only under {$mode}.");
            }
        } else {
            // Fingerprint-only rotation: K3 Previous key must stay strictly read-only (K1/K2 stay in the same Current key).
            self::assertSame($prevK3Before, StateSnapshot::of($this->admin, $prevK3Physical), 'Previous K3 key must remain strictly read-only under fingerprint-only rotation.');
        }

        $snapAfterWrite = $rotatedReader->readScorePolicy($context, 'api_heavy_protection');
        self::assertSame(3, $snapAfterWrite->scopes->k1->effectiveScore);
        self::assertFalse($snapAfterWrite->scopes->k1->scoreFromPreviousGeneration);
        self::assertSame(3, $snapAfterWrite->scopes->k2->effectiveScore);
        self::assertFalse($snapAfterWrite->scopes->k2->scoreFromPreviousGeneration);
        self::assertNotNull($snapAfterWrite->scopes->k3);
        self::assertSame(3, $snapAfterWrite->scopes->k3->effectiveScore);
        self::assertFalse($snapAfterWrite->scopes->k3->scoreFromPreviousGeneration);

        // 4. Churn correlation rotation continuity for the exact same mode:
        //    2 devices before rotation -> 3rd device after rotation triggers churn HARD_BLOCK via bridge;
        //    Previous distinct set (including aliased Previous set in fingerprint-only mode!) stays read-only,
        //    and bridge PEXPIRETIME is capped at Previous PEXPIRETIME.
        $this->admin->execute(['FLUSHDB']);
        $preRotChurn = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig($seedSecret, $seedFpSecret, 'prod:eu'),
            new RedisFullCapabilityStore($this->admin, self::NAMESPACE),
            new RecordingFailureSignalEmitter(),
        )->withClock($clock)->build();

        $preRotChurn->limit(new RateLimitContextDTO('198.51.100.55', 'Mozilla/5.0 Chrome/126.0.0.0', null, ['d' => '1']), RateLimitCommand::checkOnly('login_protection'));
        $preRotChurn->limit(new RateLimitContextDTO('198.51.100.55', 'Mozilla/5.0 Chrome/126.0.0.0', null, ['d' => '2']), RateLimitCommand::checkOnly('login_protection'));

        $prevK2 = NonAccountKeyOracle::k2('login_protection', 'prod:eu', '198.51.100.55', 'chrome/126', $seedSecret);
        $prevChurnScope = NonAccountKeyOracle::derive('correlation.churn.scope', 'login_protection', 'prod:eu', $seedSecret, $prevK2);
        $prevChurnPhysical = self::v1PhysicalKey('distinct', $prevChurnScope);
        $prevChurnBefore = StateSnapshot::of($this->admin, $prevChurnPhysical);
        self::assertNotSame('absent', $prevChurnBefore);
        $prevChurnPexpire = $this->pexpiretime($prevChurnPhysical);
        self::assertMatchesRegularExpression('/\A[1-9][0-9]*\z/', $prevChurnPexpire);

        $curK2 = NonAccountKeyOracle::k2('login_protection', 'prod:eu', '198.51.100.55', 'chrome/126', self::SECRET);
        $curChurnScope = NonAccountKeyOracle::derive('correlation.churn.scope', 'login_protection', 'prod:eu', self::SECRET, $curK2);
        if ($mode === 'fingerprint-only') {
            self::assertSame($prevChurnScope, $curChurnScope, 'Fingerprint-only rotation must exercise the aliased Previous scope path.');
        } else {
            self::assertNotSame($prevChurnScope, $curChurnScope, 'Outer secret rotation must separate Current and Previous correlation scopes.');
        }

        $rotatedChurn = RateLimiterBuilder::fromFullCapabilityStore(
            new RateLimiterConfig(self::SECRET, self::FP_SECRET, 'prod:eu', $prevSecret, $prevFpSecret),
            new RedisFullCapabilityStore($this->admin, self::NAMESPACE),
            new RecordingFailureSignalEmitter(),
        )->withClock($clock)->build();

        $blocked = $rotatedChurn->limit(
            new RateLimitContextDTO('198.51.100.55', 'Mozilla/5.0 Chrome/126.0.0.0', null, ['d' => '3']),
            RateLimitCommand::checkOnly('login_protection'),
        );
        self::assertSame(RateLimitResultDTO::DECISION_HARD_BLOCK, $blocked->decision);
        self::assertSame(
            $prevChurnBefore,
            StateSnapshot::of($this->admin, $prevChurnPhysical),
            "Previous churn distinct set must remain strictly read-only under {$mode}.",
        );

        $bridgeLogical = NonAccountKeyOracle::derive('correlation.churn.bridge', 'login_protection', 'prod:eu', self::SECRET, $curChurnScope);
        $bridgePhysical = self::v1PhysicalKey('distinct', $bridgeLogical);
        self::assertNotSame('absent', StateSnapshot::of($this->admin, $bridgePhysical), "Churn rotation bridge key must be persisted under {$mode}.");
        $bridgePexpire = $this->pexpiretime($bridgePhysical);
        self::assertMatchesRegularExpression('/\A[1-9][0-9]*\z/', $bridgePexpire);
        self::assertLessThanOrEqual(
            0,
            self::compareUnsignedDecimal($bridgePexpire, $prevChurnPexpire),
            "Bridge TTL ({$bridgePexpire}) must never exceed Previous remaining TTL ({$prevChurnPexpire}) under {$mode}.",
        );
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
        } catch (RateLimiterException $exception) {
            self::assertStringContainsString('NOPERM request commit:', $exception->getMessage());
        }
        self::assertSame($beforeAclAttempt, $this->snapshot(), 'ACL preflight NOPERM must leave both v1 and e1 keyspaces completely unchanged.');
    }

    public function testIpv6AdaptiveHierarchyAndSprayA4IsolationWithNegativeControlOnRealRedis(): void
    {
        $clock = new FixedClock('@' . self::NOW);

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

    private function pexpiretime(string $physicalKey): string
    {
        $reply = $this->admin->execute(['PEXPIRETIME', $physicalKey]);
        if (is_int($reply)) {
            return (string) $reply;
        }
        self::assertIsString($reply);
        self::assertMatchesRegularExpression('/\A-?[0-9]+\z/', $reply);

        return $reply;
    }

    private static function compareUnsignedDecimal(string $a, string $b): int
    {
        $lenCmp = strlen($a) <=> strlen($b);

        return $lenCmp !== 0 ? $lenCmp : strcmp($a, $b);
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
