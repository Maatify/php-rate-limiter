<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Builder\RateLimiterBuilder;
use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\LoginProtectionAdmissionPolicy;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\AdmissionCredentialDTO;
use Maatify\RateLimiter\DTO\AdmissionPermitDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Exception\AdmissionConfigurationException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Repository\Redis\RedisAdmissionOperations;
use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Service\AdmissionBindingControlInterface;
use Maatify\RateLimiter\Service\AdmissionBindingEpochInterface;
use Maatify\RateLimiter\Service\AdmissionCredentialCodec;
use Maatify\RateLimiter\Service\AdmissionFenceControlInterface;
use Maatify\RateLimiter\Service\AdmissionSharedConfigControlInterface;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\FailureSignal\RecordingFailureSignalEmitter;
use PHPUnit\Framework\Attributes\Group;

/**
 * G3 (#284, DEC-048, DEC-049, DEC-050) real-Redis integration tests:
 *  - Account binding revocation survival across >=2 outer key-secret rotations with zero intermediate requests/bumps
 *  - Durable shared configuration (`pcfg`, DEC-049) survival across >=2 outer rotations with zero intermediate reservations
 *  - Permit v2 context binding, privacy (zero raw identity in keys/hash fields), and D7 closed-fence settlement on Redis
 *  - Direct-store physical wrapper NUL boundary separation on Redis
 *  - Integer overflow rejection BEFORE first write on both 32-bit and 64-bit PHP (`#[Group('php32')]`)
 */
#[Group('integration')]
final class AdmissionG3HardeningRedisTest extends AdmissionRedisGateTestCase
{
    private const NOW = 1_767_225_600;
    private const ENV = 'prod';
    private const TRUST_DOMAIN = 'auth.example.test';
    private const STATE_SECRET = 'redis-g3-durable-state-secret-32-bytes!';
    private const BINDING_1 = 'redis-g3-binding-secret-1';
    private const BINDING_2 = 'redis-g3-binding-secret-2';
    private const SOURCE = '198.51.100.70';

    private FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FixedClock('@' . self::NOW);
    }

    public function testRevocationSurvivesTwoOuterRotationsWithoutIntermediateActivityAndPreservesValidReissuedHolder(): void
    {
        // Step 1: Outer secret S1, open fence, exhaust source U capacity (24), prove epoch=0 holder reaches P.
        $cfg1 = $this->config('outer-s1', null, self::BINDING_1, null);
        $r1 = $this->buildRuntime($cfg1);
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $r1);
        self::assertInstanceOf(AdmissionBindingControlInterface::class, $r1);
        self::assertInstanceOf(AdmissionBindingEpochInterface::class, $r1);
        self::assertSame(1, $r1->openAdmissionFence('login'));

        $codec1 = AdmissionCredentialCodec::fromConfig($cfg1);
        $oldCred = $codec1->encode(new AdmissionCredentialDTO('acct-victim', 'acct-victim', ['login'], 'inst-1', 'jti-old', self::NOW + 86400, self::NOW, 0));
        $victimOldCtx = $this->context('acct-victim', self::SOURCE, 'inst-1', $oldCred);

        $this->exhaustSourceU($r1, self::SOURCE, 's1');
        $beforeRevoke = $r1->limit($victimOldCtx, RateLimitCommand::checkOnly('login_protection'));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $beforeRevoke->decision, 'Epoch-0 credential reaches P before revocation');
        $this->settle($r1, $victimOldCtx, $beforeRevoke, true);

        // Step 2: Revoke account binding (epoch -> 1) and issue new epoch=1 credential to eligible device.
        $r1->revokeAccountBinding('acct-victim');
        self::assertSame(1, $r1->bindingEpoch('acct-victim'));
        $reissuedCred = $codec1->encode(new AdmissionCredentialDTO('acct-victim', 'acct-victim', ['login'], 'inst-2', 'jti-new', self::NOW + 86400, self::NOW, 1));
        $victimNewCtx = $this->context('acct-victim', self::SOURCE, 'inst-2', $reissuedCred);

        // Step 3: Outer rotation #1 (S1 -> S2/S1), reopen fence under S2; ZERO requests/bumps for acct-victim!
        $cfg2 = $this->config('outer-s2', 'outer-s1', self::BINDING_1, null);
        $r2 = $this->buildRuntime($cfg2);
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $r2);
        self::assertSame(2, $r2->openAdmissionFence('login'));

        // Step 4: Outer rotation #2 (S2 -> S3/S2), reopen fence under S3; ZERO requests/bumps for acct-victim!
        $cfg3 = $this->config('outer-s3', 'outer-s2', self::BINDING_1, null);
        $r3 = $this->buildRuntime($cfg3);
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $r3);
        self::assertInstanceOf(AdmissionBindingEpochInterface::class, $r3);
        self::assertSame(3, $r3->openAdmissionFence('login'));

        // Exhaust source U capacity under S3 so Lane U refuses with source_capacity while Lane P admits.
        $this->exhaustSourceU($r3, self::SOURCE, 's3');

        // Assertion: old epoch=0 credential MUST remain Lane U (refused by source_capacity), NEVER revived!
        self::assertSame(1, $r3->bindingEpoch('acct-victim'));
        $revivedAttempt = $r3->limit($victimOldCtx, RateLimitCommand::checkOnly('login_protection'));
        self::assertSame(RateLimitResultDTO::DECISION_SOFT_BLOCK, $revivedAttempt->decision);
        self::assertSame('source_capacity', $revivedAttempt->metadata?->cause, 'Revoked epoch=0 credential must stay Lane U after >=2 outer rotations');

        // Assertion: valid re-issued epoch=1 credential STILL reaches Lane P after >=2 outer rotations!
        $validHolder = $r3->limit($victimNewCtx, RateLimitCommand::checkOnly('login_protection'));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $validHolder->decision, 'Re-issued epoch=1 credential stays Lane P after >=2 outer rotations');
        $this->settle($r3, $victimNewCtx, $validHolder, true);

        // Step 5: Binding key rotation (B1 -> B2/B1) does not alter epoch!
        $cfg4 = $this->config('outer-s3', 'outer-s2', self::BINDING_2, self::BINDING_1);
        $r4 = $this->buildRuntime($cfg4);
        self::assertInstanceOf(AdmissionBindingEpochInterface::class, $r4);
        self::assertSame(1, $r4->bindingEpoch('acct-victim'));
        $validAfterBindRotate = $r4->limit($victimNewCtx, RateLimitCommand::checkOnly('login_protection'));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $validAfterBindRotate->decision);
        $this->settle($r4, $victimNewCtx, $validAfterBindRotate, true);

        // Privacy check: no raw account ID or raw source IP appears in any Redis key!
        foreach (array_keys($this->dump()) as $key) {
            self::assertStringNotContainsString('acct-victim', (string) $key);
            self::assertStringNotContainsString(self::SOURCE, (string) $key);
        }
    }

    public function testDurableSharedConfigSurvivesTwoOuterRotationsWithoutIntermediateReservations(): void
    {
        // Register effective pending limit = 2 under outer S1.
        $r1 = $this->buildRuntime($this->config('outer-s1', null, self::BINDING_1, null), pendingLimit: 2);
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $r1);
        $r1->openAdmissionFence('login');
        $seed = $r1->limit($this->context('acct-seed', self::SOURCE), RateLimitCommand::checkOnly('login_protection'));
        self::assertTrue($seed->isAllowed());
        $this->settle($r1, $this->context('acct-seed', self::SOURCE), $seed, true);

        // Outer rotation #1 (S2/S1) and #2 (S3/S2) with ZERO accepted reservations during rotations!
        $r2 = $this->buildRuntime($this->config('outer-s2', 'outer-s1', self::BINDING_1, null), pendingLimit: 2);
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $r2);
        $r2->openAdmissionFence('login');

        $r3Matching = $this->buildRuntime($this->config('outer-s3', 'outer-s2', self::BINDING_1, null), pendingLimit: 2);
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $r3Matching);
        $r3Matching->openAdmissionFence('login');

        // A stale/mismatched worker (limit = 5 or off = null) under S3 MUST fail with AdmissionConfigurationException and zero writes!
        $beforeMismatch = $this->dump();
        foreach ([5, null] as $wrongLimit) {
            $mismatched = $this->buildRuntime($this->config('outer-s3', 'outer-s2', self::BINDING_1, null), pendingLimit: $wrongLimit);
            $threw = false;
            try {
                $mismatched->limit($this->context('acct-bad', self::SOURCE), RateLimitCommand::checkOnly('login_protection'));
            } catch (AdmissionConfigurationException) {
                $threw = true;
            }
            self::assertTrue($threw, 'Durable pcfg must reject mismatched worker after >=2 outer rotations');
        }
        self::assertSame($beforeMismatch, $this->dump(), 'Rejected mismatched workers must write nothing to Redis');

        // Matching worker (limit = 2) enforces pending cap = 2 cleanly.
        $codec = AdmissionCredentialCodec::fromConfig($this->config('outer-s3', 'outer-s2', self::BINDING_1, null));
        $c1 = $this->context('p1', self::SOURCE, 'i1', $codec->encode(new AdmissionCredentialDTO('p1', 'p1', ['login'], 'i1', 'j1', self::NOW + 3600, self::NOW, 0)));
        $c2 = $this->context('p2', self::SOURCE, 'i2', $codec->encode(new AdmissionCredentialDTO('p2', 'p2', ['login'], 'i2', 'j2', self::NOW + 3600, self::NOW, 0)));
        $c3 = $this->context('p3', self::SOURCE, 'i3', $codec->encode(new AdmissionCredentialDTO('p3', 'p3', ['login'], 'i3', 'j3', self::NOW + 3600, self::NOW, 0)));
        self::assertTrue($r3Matching->limit($c1, RateLimitCommand::checkOnly('login_protection'))->isAllowed());
        self::assertTrue($r3Matching->limit($c2, RateLimitCommand::checkOnly('login_protection'))->isAllowed());
        $refused = $r3Matching->limit($c3, RateLimitCommand::checkOnly('login_protection'));
        self::assertSame('source_pending_permits', $refused->metadata?->cause);

        // Explicit rebind changes durable pcfg to 4.
        $r3Rebind = $this->buildRuntime($this->config('outer-s3', 'outer-s2', self::BINDING_1, null), pendingLimit: 4);
        self::assertInstanceOf(AdmissionSharedConfigControlInterface::class, $r3Rebind);
        $r3Rebind->rebindAdmissionSharedConfig('login');
        self::assertTrue($r3Rebind->limit($c3, RateLimitCommand::checkOnly('login_protection'))->isAllowed());
    }

    public function testD7ClosedFenceSettlementAndPermitContextBindingOnRealRedis(): void
    {
        $cfgOpen = $this->config('outer-s1', null, self::BINDING_1, null);
        $rOpen = $this->buildRuntime($cfgOpen, pendingLimit: 1);
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $rOpen);
        $rOpen->openAdmissionFence('login');

        $codec = AdmissionCredentialCodec::fromConfig($cfgOpen);
        $cred = $codec->encode(new AdmissionCredentialDTO('acct-d7', 'acct-d7', ['login'], 'inst-d7', 'jti-d7', self::NOW + 3600, self::NOW, 0));
        $ctx = $this->context('acct-d7', self::SOURCE, 'inst-d7', $cred);

        $check = $rOpen->limit($ctx, RateLimitCommand::checkOnly('login_protection'));
        self::assertTrue($check->isAllowed());
        $permit = $check->metadata?->permit;
        self::assertNotNull($permit);

        // Close fence (outer-s3 with outer-s2 previous: fence never opened under s2/s3).
        $cfgClosed = $this->config('outer-s3', 'outer-s2', self::BINDING_1, null);
        $rClosed = $this->buildRuntime($cfgClosed, pendingLimit: 1);

        // Foreign context rejected before claim; no Redis permit state changed!
        $foreignCtx = $this->context('acct-d7', '203.0.113.99', 'inst-d7', $cred);
        $beforeForeign = $this->dump();
        $foreignRes = $rClosed->limit($foreignCtx, new RateLimitCommand('login_protection', 1, false, true, false, $permit));
        self::assertSame('permit_rejected', $foreignRes->metadata?->cause);
        self::assertSame($beforeForeign, $this->dump(), 'Foreign permit under closed fence must not mutate Redis');

        // Valid outstanding permit settles ONCE even while fence is closed!
        $settled = $rClosed->limit($ctx, new RateLimitCommand('login_protection', 1, false, true, false, $permit));
        self::assertNull($settled->metadata?->cause);
        $dup = $rClosed->limit($ctx, new RateLimitCommand('login_protection', 1, false, true, false, $permit));
        self::assertSame('permit_duplicate', $dup->metadata?->cause);

        // Privacy check: raw account/IP never appears in any Redis key or permit hash field.
        foreach ($this->dump() as $key => $entry) {
            self::assertStringNotContainsString('acct-d7', (string) $key);
            if ($entry['type'] === 'hash' && str_contains((string) $key, ':adm:a1:permit:')) {
                self::assertIsArray($entry['value']);
                $subject = $entry['value']['subject'] ?? '';
                self::assertIsString($subject);
                self::assertNotSame('acct-d7', $subject);
                self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $subject);
            }
        }
    }

    #[Group('php32')]
    public function testDirectStorePhysicalWrapperSeparatesNulBoundariesOnRealRedis(): void
    {
        $ops = new RedisAdmissionOperations($this->executor, self::NAMESPACE);

        $r1 = $ops->reserve(new AdmissionReservationRequestDTO(
            "a\0b",
            [new AdmissionScopeDTO('cap', AdmissionScopeKindEnum::COUNTER, 'c', 1, 600, null, 'a1.permit.' . str_repeat('1', 64))],
            new AdmissionPermitDTO('a1.permit.' . str_repeat('1', 64), 'U', str_repeat('a', 64), 'op', self::NOW + 30, 600),
        ), self::NOW);
        $r2 = $ops->reserve(new AdmissionReservationRequestDTO(
            'a',
            [new AdmissionScopeDTO('cap', AdmissionScopeKindEnum::COUNTER, "b\0c", 1, 600, null, 'a1.permit.' . str_repeat('2', 64))],
            new AdmissionPermitDTO('a1.permit.' . str_repeat('2', 64), 'U', str_repeat('b', 64), 'op', self::NOW + 30, 600),
        ), self::NOW);

        self::assertTrue($r1->admitted);
        self::assertTrue($r2->admitted, 'Redis physical wrapper must keep ("a\0b", "c") distinct from ("a", "b\0c")');
    }

    #[Group('php32')]
    public function testMonotonicEpochAndFenceRejectOverflowBeforeFirstWriteOn32BitAnd64BitPhp(): void
    {
        $ops = new RedisAdmissionOperations($this->executor, self::NAMESPACE);
        $limit = PHP_INT_SIZE < 8 ? (string) PHP_INT_MAX : '9007199254740991';
        $tag = '{' . hash('sha256', self::NAMESPACE) . '}';

        $epochKey = 'a1.epoch.' . str_repeat('e', 64);
        $fenceKey = 'a1.fence.' . str_repeat('f', 64);
        $physEpoch = 'maatify:rate-limiter:v1:' . $tag . ':adm:a1:epoch:' . hash('sha256', $epochKey);
        $physFence = 'maatify:rate-limiter:v1:' . $tag . ':adm:a1:fence:' . hash('sha256', $fenceKey);

        $this->raw('SET', $physEpoch, $limit);
        $this->raw('SET', $physFence, $limit);

        $epochThrew = false;
        try {
            $ops->bumpEpoch($epochKey);
        } catch (RateLimiterException) {
            $epochThrew = true;
        }
        self::assertTrue($epochThrew, 'Epoch bump at representable limit must fail with RateLimiterException');
        self::assertSame($limit, $this->raw('GET', $physEpoch), 'Epoch value must remain unchanged (rejected BEFORE write)');

        $fenceThrew = false;
        try {
            $ops->bumpFence($fenceKey, null);
        } catch (RateLimiterException) {
            $fenceThrew = true;
        }
        self::assertTrue($fenceThrew, 'Fence bump at representable limit must fail with RateLimiterException');
        self::assertSame($limit, $this->raw('GET', $physFence), 'Fence value must remain unchanged (rejected BEFORE write)');
    }

    private function config(
        string $outer,
        ?string $previousOuter,
        string $binding,
        ?string $previousBinding,
    ): RateLimiterConfig {
        return new RateLimiterConfig(
            $outer,
            'redis-g3-fp',
            self::ENV,
            $previousOuter,
            null,
            $binding,
            $previousBinding,
            self::TRUST_DOMAIN,
            self::STATE_SECRET,
        );
    }

    private function buildRuntime(RateLimiterConfig $config, ?int $pendingLimit = null): CompositeRateLimiterRuntimeInterface
    {
        $builder = RateLimiterBuilder::fromFullCapabilityStore(
            $config,
            new RedisFullCapabilityStore($this->executor, self::NAMESPACE),
            new RecordingFailureSignalEmitter(),
        )->withClock($this->clock)->withPolicy(new LoginProtectionAdmissionPolicy());
        if ($pendingLimit !== null) {
            $builder = $builder->withProvenPendingPermitLimit($pendingLimit);
        }

        return $builder->build();
    }

    private function context(
        string $account,
        string $ip,
        ?string $instance = null,
        ?string $credential = null,
    ): RateLimitContextDTO {
        return new RateLimitContextDTO(
            ip: $ip,
            ua: 'Mozilla/5.0 Chrome/126',
            accountId: $account,
            clientInstanceId: $instance,
            admissionCredential: $credential,
        );
    }

    private function exhaustSourceU(CompositeRateLimiterRuntimeInterface $runtime, string $ip, string $tag): void
    {
        for ($i = 0; $i < 24; $i++) {
            $ctx = $this->context('exhaust-' . $tag . '-' . $i, $ip);
            $r = $runtime->limit($ctx, RateLimitCommand::checkOnly('login_protection'));
            self::assertTrue($r->isAllowed(), "Source exhaust attempt {$i}");
            $this->settle($runtime, $ctx, $r, true);
        }
    }

    private function settle(
        CompositeRateLimiterRuntimeInterface $runtime,
        RateLimitContextDTO $ctx,
        RateLimitResultDTO $check,
        bool $success,
    ): RateLimitResultDTO {
        $permit = $check->metadata?->permit;
        self::assertNotNull($permit);

        return $runtime->limit($ctx, new RateLimitCommand('login_protection', 1, false, ! $success, $success, $permit));
    }
}
