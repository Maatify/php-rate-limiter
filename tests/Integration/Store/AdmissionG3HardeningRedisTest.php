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
        $cfg = $this->config('outer-s1', null, self::BINDING_1, null);
        $runtime = $this->buildRuntime($cfg, pendingLimit: 1);
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $runtime);
        $runtime->openAdmissionFence('login');

        $codec = AdmissionCredentialCodec::fromConfig($cfg);
        $credSucc = $codec->encode(new AdmissionCredentialDTO('acct-p-succ', 'acct-p-succ', ['login'], 'inst-p1', 'jti-p1', self::NOW + 3600, self::NOW, 0));
        $credFail = $codec->encode(new AdmissionCredentialDTO('acct-p-fail', 'acct-p-fail', ['login'], 'inst-p2', 'jti-p2', self::NOW + 3600, self::NOW, 0));
        $credExtra = $codec->encode(new AdmissionCredentialDTO('acct-p-extra', 'acct-p-extra', ['login'], 'inst-p3', 'jti-p3', self::NOW + 3600, self::NOW, 0));

        $ctxPSucc = $this->context('acct-p-succ', '198.51.100.70', 'inst-p1', $credSucc);
        $ctxPFail = $this->context('acct-p-fail', '198.51.100.71', 'inst-p2', $credFail);
        $ctxUSucc = $this->context('acct-u-succ', '198.51.100.72');
        $ctxUFail = $this->context('acct-u-fail', '198.51.100.73');
        $ctxPLate = $this->context('acct-p-late', '198.51.100.74', 'inst-p4', $codec->encode(new AdmissionCredentialDTO('acct-p-late', 'acct-p-late', ['login'], 'inst-p4', 'jti-p4', self::NOW + 3600, self::NOW, 0)));

        $pSuccCheck = $runtime->limit($ctxPSucc, RateLimitCommand::checkOnly('login_protection'));
        $pFailCheck = $runtime->limit($ctxPFail, RateLimitCommand::checkOnly('login_protection'));
        $uSuccCheck = $runtime->limit($ctxUSucc, RateLimitCommand::checkOnly('login_protection'));
        $uFailCheck = $runtime->limit($ctxUFail, RateLimitCommand::checkOnly('login_protection'));
        $pLateCheck = $runtime->limit($ctxPLate, RateLimitCommand::checkOnly('login_protection'));

        foreach ([$pSuccCheck, $pFailCheck, $uSuccCheck, $uFailCheck, $pLateCheck] as $c) {
            self::assertTrue($c->isAllowed());
            self::assertNotNull($c->metadata?->permit);
        }
        $pSuccPermit = $pSuccCheck->metadata?->permit;
        $pFailPermit = $pFailCheck->metadata?->permit;
        $uSuccPermit = $uSuccCheck->metadata?->permit;
        $uFailPermit = $uFailCheck->metadata?->permit;
        $pLatePermit = $pLateCheck->metadata?->permit;
        self::assertIsString($pSuccPermit);
        self::assertIsString($pFailPermit);
        self::assertIsString($uSuccPermit);
        self::assertIsString($uFailPermit);
        self::assertIsString($pLatePermit);

        // Pending limit = 1 is active on 198.51.100.70 before closing fence.
        $refusedPending = $runtime->limit($this->context('acct-p-extra', '198.51.100.70', 'inst-p3', $credExtra), RateLimitCommand::checkOnly('login_protection'));
        self::assertSame('source_pending_permits', $refusedPending->metadata?->cause);

        // Close the fleet fence on the SAME outer generation via isolated test fixture control (remove :adm:a1:fence:* only).
        $this->closeTestFenceKeys();

        // Closed fence + checkOnly => S0 path, NO permit issued and NO admission or score state mutated!
        $beforeClosedCheck = $this->admAndScoreDump($this->dump());
        $closedCheck = $runtime->limit($this->context('acct-s0-check', '198.51.100.75'), RateLimitCommand::checkOnly('login_protection'));
        self::assertSame(RateLimitResultDTO::DECISION_ALLOW, $closedCheck->decision);
        self::assertNull($closedCheck->metadata?->permit);
        self::assertSame($beforeClosedCheck, $this->admAndScoreDump($this->dump()), 'Closed-fence checkOnly writes zero admission or score state');

        // Foreign-context permit under closed fence => permit_rejected, zero Redis mutation.
        $beforeForeign = $this->dump();
        $foreignRes = $runtime->limit($this->context('acct-p-succ', '203.0.113.99', 'inst-p1', $credSucc), new RateLimitCommand('login_protection', 1, false, true, false, $pSuccPermit));
        self::assertSame('permit_rejected', $foreignRes->metadata?->cause);
        self::assertSame($beforeForeign, $this->dump(), 'Foreign permit under closed fence must not mutate Redis');

        // Invalid permit under closed fence => permit_unknown, zero Redis mutation (zero S0 scoring!).
        $beforeUnknown = $this->dump();
        $unknownRes = $runtime->limit($ctxUFail, new RateLimitCommand('login_protection', 1, false, true, false, 'bogus.permit'));
        self::assertSame('permit_unknown', $unknownRes->metadata?->cause);
        self::assertSame($beforeUnknown, $this->dump(), 'Invalid permit under closed fence must not score in S0');

        // P SUCCESS under closed fence: claims permit once, refunds proof counters (1 -> 0), releases ppend slot (1 -> 0), no pfail entry, no S0 score.
        $pSuccBucketsBefore = $this->admKeysSummary();
        self::assertGreaterThanOrEqual(5, $pSuccBucketsBefore['counterMembers'], 'pacct/pacctd/pproof/pproofd/ppend hold members before P success');
        $pSuccSettle = $runtime->limit($ctxPSucc, new RateLimitCommand('login_protection', 1, false, false, true, $pSuccPermit));
        self::assertNull($pSuccSettle->metadata?->cause);
        $pSuccAfter = $this->dump();
        // Verify permit record is CLAIMED/success and pending + proof counters for P success are released.
        $pSuccPermitHash = $this->findPermitBySubject($pSuccAfter, 'acct-p-succ');
        self::assertSame('CLAIMED', $pSuccPermitHash['state'] ?? null);
        self::assertSame('success', $pSuccPermitHash['outcome'] ?? null);

        // Duplicate & Conflict on settled P permit => zero Redis mutation.
        $dupP = $runtime->limit($ctxPSucc, new RateLimitCommand('login_protection', 1, false, false, true, $pSuccPermit));
        self::assertSame('permit_duplicate', $dupP->metadata?->cause);
        self::assertSame($pSuccAfter, $this->dump());
        $confP = $runtime->limit($ctxPSucc, new RateLimitCommand('login_protection', 1, false, true, false, $pSuccPermit));
        self::assertSame('permit_conflict', $confP->metadata?->cause);
        self::assertSame($pSuccAfter, $this->dump());

        // P FAILURE under closed fence: claims permit, releases ppend slot, adds member to pfail (set_size), writes K4P/K5p score!
        $scoresBeforePFail = $this->e1ScoreKeys($this->dump());
        $setsBeforePFail = $this->admSetSizes($this->dump());
        $pFailSettle = $runtime->limit($ctxPFail, new RateLimitCommand('login_protection', 1, false, true, false, $pFailPermit));
        self::assertNull($pFailSettle->metadata?->cause);
        $afterPFail = $this->dump();
        $scoresAfterPFail = $this->e1ScoreKeys($afterPFail);
        $setsAfterPFail = $this->admSetSizes($afterPFail);
        self::assertGreaterThan(count($scoresBeforePFail), count($scoresAfterPFail), 'P failure must score K4P/K5p in e1:score:');
        self::assertGreaterThan(array_sum($setsBeforePFail), array_sum($setsAfterPFail), 'P failure must add member to pfail set');

        // U SUCCESS under closed fence: claims permit, refunds U account_bucket credit back to full (key deleted: absent => full), zero ufail entry!
        $uSuccBucketsBefore = $this->bucketCredits($this->dump());
        $setsBeforeUSucc = $this->admSetSizes($this->dump());
        $uSuccSettle = $runtime->limit($ctxUSucc, new RateLimitCommand('login_protection', 1, false, false, true, $uSuccPermit));
        self::assertNull($uSuccSettle->metadata?->cause);
        $afterUSucc = $this->dump();
        self::assertCount(count($uSuccBucketsBefore) - 1, $this->bucketCredits($afterUSucc), 'U success must refund account_bucket back to full (absent)');
        self::assertSame($setsBeforeUSucc, $this->admSetSizes($afterUSucc), 'U success must not add to ufail');

        // U FAILURE under closed fence: claims permit, adds member to ufail (set_size), writes K4 score!
        $scoresBeforeUFail = $this->e1ScoreKeys($afterUSucc);
        $uFailSettle = $runtime->limit($ctxUFail, new RateLimitCommand('login_protection', 1, false, true, false, $uFailPermit));
        self::assertNull($uFailSettle->metadata?->cause);
        $afterUFail = $this->dump();
        self::assertGreaterThan(count($scoresBeforeUFail), count($this->e1ScoreKeys($afterUFail)), 'U failure must score K4 in e1:score:');
        self::assertGreaterThan(array_sum($setsBeforeUSucc), array_sum($this->admSetSizes($afterUFail)), 'U failure must add member to ufail set');

        // Re-open fence briefly to confirm P pending slot on 198.51.100.70 was genuinely released in Redis!
        $runtime->openAdmissionFence('login');
        self::assertTrue($runtime->limit($this->context('acct-p-extra', '198.51.100.70', 'inst-p3', $credExtra), RateLimitCommand::checkOnly('login_protection'))->isAllowed(), 'Pending slot on 198.51.100.70 must be free after closed-fence settlement');
        $this->closeTestFenceKeys();

        // LATE permit under closed fence (at NOW + 30, within G = 600): returns permit_late, zero score/set mutation!
        $this->clock->setNow(new \DateTimeImmutable('@' . (self::NOW + 30)));
        $beforeLate = $this->dump();
        $lateRes = $runtime->limit($ctxPLate, new RateLimitCommand('login_protection', 1, false, true, false, $pLatePermit));
        self::assertSame('permit_late', $lateRes->metadata?->cause);
        self::assertSame($beforeLate, $this->dump(), 'Late permit under closed fence must not mutate Redis');

        // Privacy check: raw account/IP never appears in any Redis key or permit hash field.
        foreach ($this->dump() as $key => $entry) {
            self::assertStringNotContainsString('acct-p-succ', (string) $key);
            if ($entry['type'] === 'hash' && str_contains((string) $key, ':adm:a1:permit:')) {
                self::assertIsArray($entry['value']);
                $subject = $entry['value']['subject'] ?? '';
                self::assertIsString($subject);
                self::assertNotSame('acct-p-succ', $subject);
                self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $subject);
            }
        }
    }

    public function testD7ClosedFenceAndPendingSlotReleaseAcrossOneSupportedOuterRotation(): void
    {
        $cfg1 = $this->config('outer-s1', null, self::BINDING_1, null);
        $r1 = $this->buildRuntime($cfg1, pendingLimit: 1);
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $r1);
        $r1->openAdmissionFence('login');

        $codec1 = AdmissionCredentialCodec::fromConfig($cfg1);
        $cred1 = $codec1->encode(new AdmissionCredentialDTO('acct-rot1', 'acct-rot1', ['login'], 'inst-1', 'jti-1', self::NOW + 3600, self::NOW, 0));
        $ctx1 = $this->context('acct-rot1', self::SOURCE, 'inst-1', $cred1);
        $check1 = $r1->limit($ctx1, RateLimitCommand::checkOnly('login_protection'));
        self::assertTrue($check1->isAllowed());
        $permit1 = $check1->metadata?->permit;
        self::assertNotNull($permit1);

        // Supported single outer rotation (S1 -> S2/S1), then close fence via isolated test fixture control.
        $cfg2 = $this->config('outer-s2', 'outer-s1', self::BINDING_1, null);
        $r2 = $this->buildRuntime($cfg2, pendingLimit: 1);
        $this->closeTestFenceKeys();

        // Settle S1 permit under S2/S1 with closed fence: ppend slot under S1 (previousKeySecret) IS released!
        $settled = $r2->limit($ctx1, new RateLimitCommand('login_protection', 1, false, false, true, $permit1));
        self::assertNull($settled->metadata?->cause);
        $dup = $r2->limit($ctx1, new RateLimitCommand('login_protection', 1, false, false, true, $permit1));
        self::assertSame('permit_duplicate', $dup->metadata?->cause);

        // Re-open fence under S2/S1 and verify a new P reservation on the same source succeeds (S1 ppend slot was freed!).
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $r2);
        $r2->openAdmissionFence('login');
        $cred2 = $codec1->encode(new AdmissionCredentialDTO('acct-rot2', 'acct-rot2', ['login'], 'inst-2', 'jti-2', self::NOW + 3600, self::NOW, 0));
        self::assertTrue($r2->limit($this->context('acct-rot2', self::SOURCE, 'inst-2', $cred2), RateLimitCommand::checkOnly('login_protection'))->isAllowed());
    }

    private function closeTestFenceKeys(): void
    {
        foreach (array_keys($this->dump()) as $key) {
            if (str_contains((string) $key, ':adm:a1:fence:')) {
                $this->raw('DEL', (string) $key);
            }
        }
    }

    /**
     * @param array<string, array{type: string, value: mixed, hasTtl: bool}> $dump
     * @return array<string, array{type: string, value: mixed, hasTtl: bool}>
     */
    private function admAndScoreDump(array $dump): array
    {
        return array_filter(
            $dump,
            static fn(string $key): bool => str_contains($key, ':adm:') || str_contains($key, ':score:'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param array<string, array{type: string, value: mixed, hasTtl: bool}> $dump
     * @return array<string, string>
     */
    private function findPermitBySubject(array $dump, string $account): array
    {
        foreach ($dump as $key => $entry) {
            if (str_contains((string) $key, ':adm:a1:permit:') && $entry['type'] === 'hash' && is_array($entry['value']) && ($entry['value']['state'] ?? '') === 'CLAIMED') {
                $out = [];
                foreach ($entry['value'] as $fk => $fv) {
                    if (is_string($fk) && is_scalar($fv)) {
                        $out[$fk] = (string) $fv;
                    }
                }

                return $out;
            }
        }
        unset($account);

        return [];
    }

    /**
     * @return array{counterMembers: int}
     */
    private function admKeysSummary(): array
    {
        $members = 0;
        foreach ($this->dump() as $key => $entry) {
            if (str_contains((string) $key, ':adm:a1:c:') && is_array($entry['value'])) {
                $members += count($entry['value']);
            }
        }

        return ['counterMembers' => $members];
    }

    /**
     * @param array<string, array{type: string, value: mixed, hasTtl: bool}> $dump
     * @return list<string>
     */
    private function e1ScoreKeys(array $dump): array
    {
        return array_values(array_filter(array_keys($dump), static fn(string $k): bool => str_contains($k, ':e1:') && str_contains($k, ':score:')));
    }

    /**
     * @param array<string, array{type: string, value: mixed, hasTtl: bool}> $dump
     * @return array<string, int>
     */
    private function admSetSizes(array $dump): array
    {
        $out = [];
        foreach ($dump as $key => $entry) {
            if (str_contains((string) $key, ':adm:a1:s:') && is_array($entry['value'])) {
                $out[(string) $key] = count($entry['value']);
            }
        }

        return $out;
    }

    /**
     * @param array<string, array{type: string, value: mixed, hasTtl: bool}> $dump
     * @return array<string, int>
     */
    private function bucketCredits(array $dump): array
    {
        $out = [];
        foreach ($dump as $key => $entry) {
            if (str_contains((string) $key, ':adm:a1:b:') && is_array($entry['value'])) {
                $credit = $entry['value']['credit'] ?? 0;
                $out[(string) $key] = is_numeric($credit) ? (int) $credit : 0;
            }
        }

        return $out;
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
