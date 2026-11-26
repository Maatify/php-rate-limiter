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

        $beforePSuccCheck = $this->dump();
        $pSuccCheck = $runtime->limit($ctxPSucc, RateLimitCommand::checkOnly('login_protection'));
        $pSuccArtifacts = $this->capturePreCheckArtifacts($beforePSuccCheck, $this->dump(), $ctxPSucc);

        $beforePFailCheck = $this->dump();
        $pFailCheck = $runtime->limit($ctxPFail, RateLimitCommand::checkOnly('login_protection'));
        $pFailArtifacts = $this->capturePreCheckArtifacts($beforePFailCheck, $this->dump(), $ctxPFail);

        $beforeUSuccCheck = $this->dump();
        $uSuccCheck = $runtime->limit($ctxUSucc, RateLimitCommand::checkOnly('login_protection'));
        $uSuccArtifacts = $this->capturePreCheckArtifacts($beforeUSuccCheck, $this->dump(), $ctxUSucc);

        $beforeUFailCheck = $this->dump();
        $uFailCheck = $runtime->limit($ctxUFail, RateLimitCommand::checkOnly('login_protection'));
        $uFailArtifacts = $this->capturePreCheckArtifacts($beforeUFailCheck, $this->dump(), $ctxUFail);

        $beforePLateCheck = $this->dump();
        $pLateCheck = $runtime->limit($ctxPLate, RateLimitCommand::checkOnly('login_protection'));
        $pLateArtifacts = $this->capturePreCheckArtifacts($beforePLateCheck, $this->dump(), $ctxPLate);

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
        self::assertNotNull($uSuccArtifacts['bucketKey']);
        self::assertNotNull($uFailArtifacts['bucketKey']);
        self::assertNotSame($uSuccArtifacts['bucketKey'], $uFailArtifacts['bucketKey']);

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

        // P SUCCESS under closed fence: claims exact permit, refunds all 4 proof counters + ppend slot, no pfail/score/control mutation.
        $beforePSuccSettle = $this->dump();
        $pSuccSettle = $runtime->limit($ctxPSucc, new RateLimitCommand('login_protection', 1, false, false, true, $pSuccPermit));
        self::assertNull($pSuccSettle->metadata?->cause);
        $pSuccAfter = $this->dump();
        $this->assertExactPermitClaimed($beforePSuccSettle, $pSuccAfter, $pSuccArtifacts['permitKey'], 'success');
        self::assertCount(5, $pSuccArtifacts['counterKeys'], 'P admission must touch pacct/pacctd/pproof/pproofd/ppend');
        foreach ($pSuccArtifacts['counterKeys'] as $counterKey) {
            self::assertArrayHasKey($pSuccArtifacts['memberId'], $this->zsetMembers($beforePSuccSettle, $counterKey));
            self::assertArrayNotHasKey($pSuccArtifacts['memberId'], $this->zsetMembers($pSuccAfter, $counterKey), "P success must remove member from {$counterKey}");
        }
        self::assertSame($this->admSetSizes($beforePSuccSettle), $this->admSetSizes($pSuccAfter), 'P success must not mutate failure sets');
        self::assertSame($this->e1ScoreValues($beforePSuccSettle), $this->e1ScoreValues($pSuccAfter), 'P success must not write E1 scores');

        // Duplicate & Conflict on settled P permit => zero Redis mutation.
        $dupP = $runtime->limit($ctxPSucc, new RateLimitCommand('login_protection', 1, false, false, true, $pSuccPermit));
        self::assertSame('permit_duplicate', $dupP->metadata?->cause);
        self::assertSame($pSuccAfter, $this->dump());
        $confP = $runtime->limit($ctxPSucc, new RateLimitCommand('login_protection', 1, false, true, false, $pSuccPermit));
        self::assertSame('permit_conflict', $confP->metadata?->cause);
        self::assertSame($pSuccAfter, $this->dump());

        // P FAILURE under closed fence: claims exact permit, releases only 1 ppend slot (4 proof counters stay), adds to pfail set, scores K4P/K5p!
        $pFailSettle = $runtime->limit($ctxPFail, new RateLimitCommand('login_protection', 1, false, true, false, $pFailPermit));
        self::assertNull($pFailSettle->metadata?->cause);
        $afterPFail = $this->dump();
        $this->assertExactPermitClaimed($pSuccAfter, $afterPFail, $pFailArtifacts['permitKey'], 'failure');
        $releasedCounters = 0;
        $retainedCounters = 0;
        foreach ($pFailArtifacts['counterKeys'] as $counterKey) {
            if (! array_key_exists($pFailArtifacts['memberId'], $this->zsetMembers($afterPFail, $counterKey))) {
                $releasedCounters++;
            } else {
                $retainedCounters++;
            }
        }
        self::assertSame(1, $releasedCounters, 'P failure must release only the ppend slot');
        self::assertSame(4, $retainedCounters, 'P failure must NOT refund the 4 proof counters');
        self::assertSame(1, array_sum($this->admSetSizes($afterPFail)) - array_sum($this->admSetSizes($pSuccAfter)), 'P failure must add 1 member to pfail');
        self::assertGreaterThan(count($this->e1ScoreValues($pSuccAfter)), count($this->e1ScoreValues($afterPFail)), 'P failure must score K4P/K5p in e1:score:');

        // U SUCCESS under closed fence: claims exact permit, deletes exact acct-u-succ bucket (absent => full), leaves acct-u-fail bucket intact, zero ufail/score!
        $uSuccSettle = $runtime->limit($ctxUSucc, new RateLimitCommand('login_protection', 1, false, false, true, $uSuccPermit));
        self::assertNull($uSuccSettle->metadata?->cause);
        $afterUSucc = $this->dump();
        $this->assertExactPermitClaimed($afterPFail, $afterUSucc, $uSuccArtifacts['permitKey'], 'success');
        self::assertArrayHasKey($uSuccArtifacts['bucketKey'], $afterPFail);
        self::assertArrayNotHasKey($uSuccArtifacts['bucketKey'], $afterUSucc, 'U success must refund exact acct-u-succ bucket back to full (absent)');
        self::assertSame($afterPFail[$uFailArtifacts['bucketKey']], $afterUSucc[$uFailArtifacts['bucketKey']], 'U success must not touch acct-u-fail bucket');
        self::assertSame($this->admSetSizes($afterPFail), $this->admSetSizes($afterUSucc), 'U success must not add to ufail');
        self::assertSame($this->e1ScoreValues($afterPFail), $this->e1ScoreValues($afterUSucc), 'U success must not write E1 scores');

        // U FAILURE under closed fence: claims exact permit, keeps exact acct-u-fail bucket, adds to ufail set, scores K4!
        $uFailSettle = $runtime->limit($ctxUFail, new RateLimitCommand('login_protection', 1, false, true, false, $uFailPermit));
        self::assertNull($uFailSettle->metadata?->cause);
        $afterUFail = $this->dump();
        $this->assertExactPermitClaimed($afterUSucc, $afterUFail, $uFailArtifacts['permitKey'], 'failure');
        self::assertSame($afterUSucc[$uFailArtifacts['bucketKey']], $afterUFail[$uFailArtifacts['bucketKey']], 'U failure must not refund acct-u-fail bucket');
        self::assertSame(1, array_sum($this->admSetSizes($afterUFail)) - array_sum($this->admSetSizes($afterUSucc)), 'U failure must add 1 member to ufail');
        self::assertGreaterThan(count($this->e1ScoreValues($afterUSucc)), count($this->e1ScoreValues($afterUFail)), 'U failure must score K4 in e1:score:');

        // Re-open fence briefly to confirm P pending slot on 198.51.100.70 was genuinely released in Redis!
        $runtime->openAdmissionFence('login');
        self::assertTrue($runtime->limit($this->context('acct-p-extra', '198.51.100.70', 'inst-p3', $credExtra), RateLimitCommand::checkOnly('login_protection'))->isAllowed(), 'Pending slot on 198.51.100.70 must be free after closed-fence settlement');
        $this->closeTestFenceKeys();

        // LATE permit under closed fence (at NOW + 30, within G = 600): returns permit_late, zero score/set/permit mutation!
        $this->clock->setNow(new \DateTimeImmutable('@' . (self::NOW + 30)));
        $beforeLate = $this->dump();
        $lateRes = $runtime->limit($ctxPLate, new RateLimitCommand('login_protection', 1, false, true, false, $pLatePermit));
        self::assertSame('permit_late', $lateRes->metadata?->cause);
        self::assertSame($beforeLate, $this->dump(), 'Late permit under closed fence must not mutate Redis');
        $latePermitValue = $beforeLate[$pLateArtifacts['permitKey']]['value'] ?? null;
        self::assertIsArray($latePermitValue);
        self::assertSame('ISSUED', $latePermitValue['state'] ?? null);

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
        $beforeS1Check = $this->dump();
        $check1 = $r1->limit($ctx1, RateLimitCommand::checkOnly('login_protection'));
        $s1Artifacts = $this->capturePreCheckArtifacts($beforeS1Check, $this->dump(), $ctx1);
        self::assertTrue($check1->isAllowed());
        $permit1 = $check1->metadata?->permit;
        self::assertNotNull($permit1);

        // Supported single outer rotation (S1 -> S2/S1), then close fence via isolated test fixture control.
        $cfg2 = $this->config('outer-s2', 'outer-s1', self::BINDING_1, null);
        $r2 = $this->buildRuntime($cfg2, pendingLimit: 1);
        $this->closeTestFenceKeys();

        // Settle S1 permit under S2/S1 with closed fence: exact S1 permit claimed, all 5 S1 counter/ppend keys release member!
        $beforeRotSettle = $this->dump();
        $settled = $r2->limit($ctx1, new RateLimitCommand('login_protection', 1, false, false, true, $permit1));
        self::assertNull($settled->metadata?->cause);
        $afterRotSettle = $this->dump();
        $this->assertExactPermitClaimed($beforeRotSettle, $afterRotSettle, $s1Artifacts['permitKey'], 'success');
        $releasedRot = 0;
        $retainedRot = 0;
        foreach ($s1Artifacts['counterKeys'] as $s1CounterKey) {
            if (! array_key_exists($s1Artifacts['memberId'], $this->zsetMembers($afterRotSettle, $s1CounterKey))) {
                $releasedRot++;
            } else {
                $retainedRot++;
                self::assertSame($beforeRotSettle[$s1CounterKey], $afterRotSettle[$s1CounterKey], "Previous proof key {$s1CounterKey} value/TTL must remain untouched across outer rotation");
            }
        }
        self::assertSame(1, $releasedRot, 'Exactly 1 S1 ppend slot must be released across S1->S2/S1');
        self::assertSame(4, $retainedRot, 'All 4 S1 proof counter keys must remain untouched across S1->S2/S1');
        $dup = $r2->limit($ctx1, new RateLimitCommand('login_protection', 1, false, false, true, $permit1));
        self::assertSame('permit_duplicate', $dup->metadata?->cause);
        self::assertSame($afterRotSettle, $this->dump());

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

    public function testDetectorCatchesWrongPermitAttributionOrUnrelatedPermitMutation(): void
    {
        $permitA = 'maatify:rate-limiter:v1:{ns}:adm:a1:permit:' . str_repeat('a', 64);
        $permitB = 'maatify:rate-limiter:v1:{ns}:adm:a1:permit:' . str_repeat('b', 64);
        $before = [
            $permitA => ['type' => 'hash', 'value' => ['state' => 'ISSUED'], 'hasTtl' => true],
            $permitB => ['type' => 'hash', 'value' => ['state' => 'ISSUED'], 'hasTtl' => true],
        ];
        $wrongAfter = [
            $permitA => ['type' => 'hash', 'value' => ['state' => 'ISSUED'], 'hasTtl' => true],
            $permitB => ['type' => 'hash', 'value' => ['state' => 'CLAIMED', 'outcome' => 'success'], 'hasTtl' => true],
        ];

        $caught = false;
        try {
            $this->assertExactPermitClaimed($before, $wrongAfter, $permitA, 'success');
        } catch (\PHPUnit\Framework\AssertionFailedError) {
            $caught = true;
        }
        self::assertTrue($caught, 'DETECTOR (F302-R2-03): claiming wrong permit must be caught by exact-key attribution');
    }

    /**
     * Captures exact physical keys created/updated by a single pre-check for exact-key attribution.
     *
     * @param array<string, array{type: string, value: mixed, hasTtl: bool}> $before
     * @param array<string, array{type: string, value: mixed, hasTtl: bool}> $after
     * @return array{permitKey: string, memberId: string, counterKeys: list<string>, bucketKey: ?string}
     */
    private function capturePreCheckArtifacts(array $before, array $after, RateLimitContextDTO $context): array
    {
        $deriver = new \Maatify\RateLimiter\Service\AdmissionKeyDeriver(self::ENV, self::TRUST_DOMAIN, self::STATE_SECRET);
        $expectedSubject = $deriver->permitContextCommitment('login', 'login_protection', $context);

        $permitKey = null;
        $counterKeys = [];
        $memberId = null;
        $bucketKey = null;

        foreach ($after as $key => $entry) {
            $keyStr = (string) $key;
            if (str_contains($keyStr, ':adm:a1:permit:') && ! array_key_exists($keyStr, $before)) {
                self::assertNull($permitKey, 'Each pre-check must create exactly one permit key');
                self::assertIsArray($entry['value']);
                self::assertSame('ISSUED', $entry['value']['state'] ?? null);
                self::assertSame($expectedSubject, $entry['value']['subject'] ?? null, 'Permit subject commitment must match context');
                $permitKey = $keyStr;
            }
            if (str_contains($keyStr, ':adm:a1:c:') && $entry['type'] === 'zset' && is_array($entry['value'])) {
                $prevMembers = $this->zsetMembers($before, $keyStr);
                $diff = array_diff(array_keys($entry['value']), array_keys($prevMembers));
                if ($diff !== []) {
                    $counterKeys[] = $keyStr;
                    $first = (string) reset($diff);
                    $memberId ??= $first;
                    self::assertSame($memberId, $first, 'All counter keys for one pre-check must share the same permit member id');
                }
            }
            if (str_contains($keyStr, ':adm:a1:b:') && (! array_key_exists($keyStr, $before) || $before[$keyStr] !== $entry)) {
                $bucketKey = $keyStr;
            }
        }

        self::assertNotNull($permitKey, 'Missing permit key for context');
        self::assertNotNull($memberId, 'Missing counter/pending member id for context');

        return [
            'permitKey' => $permitKey,
            'memberId' => $memberId,
            'counterKeys' => $counterKeys,
            'bucketKey' => $bucketKey,
        ];
    }

    /**
     * @param array<string, array{type: string, value: mixed, hasTtl: bool}> $before
     * @param array<string, array{type: string, value: mixed, hasTtl: bool}> $after
     */
    private function assertExactPermitClaimed(array $before, array $after, string $expectedPermitKey, string $expectedOutcome): void
    {
        self::assertArrayHasKey($expectedPermitKey, $before);
        self::assertArrayHasKey($expectedPermitKey, $after);
        self::assertIsArray($before[$expectedPermitKey]['value']);
        self::assertIsArray($after[$expectedPermitKey]['value']);
        self::assertSame('ISSUED', $before[$expectedPermitKey]['value']['state'] ?? null);
        self::assertSame('CLAIMED', $after[$expectedPermitKey]['value']['state'] ?? null);
        self::assertSame($expectedOutcome, $after[$expectedPermitKey]['value']['outcome'] ?? null);

        foreach ($after as $key => $entry) {
            $keyStr = (string) $key;
            if (str_contains($keyStr, ':adm:a1:permit:') && $keyStr !== $expectedPermitKey) {
                self::assertSame($before[$keyStr] ?? null, $entry, "Settlement must not mutate unrelated permit {$keyStr}");
            }
        }
    }

    /**
     * @param array<string, array{type: string, value: mixed, hasTtl: bool}> $dump
     * @return array<string, mixed>
     */
    private function zsetMembers(array $dump, string $key): array
    {
        if (! array_key_exists($key, $dump) || $dump[$key]['type'] !== 'zset' || ! is_array($dump[$key]['value'])) {
            return [];
        }
        $out = [];
        foreach ($dump[$key]['value'] as $member => $score) {
            $out[(string) $member] = $score;
        }

        return $out;
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
     * @return array<string, mixed>
     */
    private function e1ScoreValues(array $dump): array
    {
        $out = [];
        foreach ($dump as $key => $entry) {
            $keyStr = (string) $key;
            if (str_contains($keyStr, ':e1:') && str_contains($keyStr, ':score:')) {
                $out[$keyStr] = $entry['value'];
            }
        }

        return $out;
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
