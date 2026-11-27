<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\AdmissionCredentialDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\AdmissionCredentialCodec;
use Maatify\RateLimiter\Service\AdmissionFenceControlInterface;
use Maatify\RateLimiter\Service\AdmissionKeyDeriver;
use Maatify\RateLimiter\Service\AdmissionScopePurpose;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Service\E1AccountScopeKeyDeriver;
use Maatify\RateLimiter\Service\SourcePrefix;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionRaceRunner;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionRaceSpec;
use Maatify\RateLimiter\Tests\Support\Admission\PublicRedisAdmissionFixture;
use Maatify\RateLimiter\Tests\Support\Admission\ReplyValue;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use Maatify\RateLimiter\Tests\Support\Redis\StateSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * #206: the configurable pending-permit limit of the proven lane, through `RateLimiterBuilder` and the public runtime on a
 * real Redis. A permit is pending from its reservation until its outcome is claimed or its deadline (30 s) passes.
 */
#[Group('integration')]
final class AdmissionProvenPendingPermitRedisTest extends AdmissionRedisGateTestCase
{
    private const NOW = 1000000;
    private const POLICY = 'login_protection';
    private const CAUSE = 'source_pending_permits';
    private const SOURCE_A = '198.51.100.10';
    private const SOURCE_B = '198.51.100.11';

    private FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FixedClock('@' . self::NOW);
    }

    public function testLimitHoldsUnderParallelProvenChecksAndNamesItsOwnCause(): void
    {
        $this->open($this->runtime(5));
        $results = AdmissionRaceRunner::run($this->host, $this->port, 40, new AdmissionRaceSpec(
            self::NAMESPACE,
            'public_proven',
            'store',
            self::NOW,
            pendingLimit: 5,
            ip: self::SOURCE_A,
        ));

        self::assertSame(5, AdmissionRaceRunner::admitted($results), 'independent accounts, credentials and proofs: only the pending limit binds');
        $refusals = array_values(array_filter($results, static fn(array $r): bool => ! $r['ok']));
        self::assertCount(35, $refusals);
        foreach ($refusals as $refusal) {
            self::assertSame(self::CAUSE, $refusal['refused'], 'the surplus is refused by the pending limit itself');
        }
        self::assertSame(5, $this->pendingMembers());
    }

    public function testNoLimitConfiguredKeepsTheHistoricalUnboundedBehaviour(): void
    {
        $this->open($this->runtime(null));
        $results = AdmissionRaceRunner::run($this->host, $this->port, 40, new AdmissionRaceSpec(
            self::NAMESPACE,
            'public_proven',
            'store',
            self::NOW,
            ip: self::SOURCE_A,
        ));

        self::assertSame(40, AdmissionRaceRunner::admitted($results), 'without the option every independent proven reservation is admitted');
        self::assertSame(0, $this->pendingMembers(), 'no pending-permit state is written without the option');
    }

    public function testLimitMustBePositive(): void
    {
        $this->expectException(RateLimiterException::class);
        $this->runtime(0);
    }

    public function testSuccessAndFailureReleaseASlotAndAReplayReleasesNoSecondSlot(): void
    {
        $runtime = $this->runtime(2);
        $this->open($runtime);

        $a = $this->check($runtime, 'acct-a');
        $b = $this->check($runtime, 'acct-b');
        self::assertTrue($a->isAllowed());
        self::assertTrue($b->isAllowed());
        self::assertSame(self::CAUSE, self::causeOf($this->check($runtime, 'acct-c')), 'the third pending permit is over the limit');
        self::assertSame(2, $this->pendingMembers(), 'a refusal writes nothing');

        // A recorded success frees the slot of its permit.
        $this->settle($runtime, 'acct-a', $a, true);
        self::assertSame(1, $this->pendingMembers());
        $d = $this->check($runtime, 'acct-d');
        self::assertTrue($d->isAllowed());
        self::assertSame(2, $this->pendingMembers());

        // Replaying the outcome of the same permit is not applied and frees nothing.
        $replay = $this->settle($runtime, 'acct-a', $a, true);
        self::assertSame('permit_duplicate', self::causeOf($replay));
        self::assertSame(2, $this->pendingMembers());
        self::assertSame(self::CAUSE, self::causeOf($this->check($runtime, 'acct-e')), 'the replay did not free a slot for another permit');
        // A replay that flips the outcome is a conflict and frees nothing either.
        self::assertSame('permit_conflict', self::causeOf($this->settle($runtime, 'acct-a', $a, false)));
        self::assertSame(2, $this->pendingMembers());

        // A recorded failure frees the slot as well.
        $this->settle($runtime, 'acct-b', $b, false);
        self::assertSame(1, $this->pendingMembers());
        self::assertTrue($this->check($runtime, 'acct-f')->isAllowed());
        self::assertSame(2, $this->pendingMembers());
    }

    public function testDeadlineExpiryFreesTheSlotsWithoutAnOutcome(): void
    {
        $runtime = $this->runtime(2);
        $this->open($runtime);
        $first = $this->check($runtime, 'acct-a');
        self::assertTrue($first->isAllowed());
        self::assertTrue($this->check($runtime, 'acct-b')->isAllowed());
        self::assertSame(self::CAUSE, self::causeOf($this->check($runtime, 'acct-c')));

        $this->clock->setNow(new \DateTimeImmutable('@' . (self::NOW + 29)));
        self::assertSame(self::CAUSE, self::causeOf($this->check($runtime, 'acct-c')), 'one second before the deadline D = 30 s the permits still hold');

        $this->clock->setNow(new \DateTimeImmutable('@' . (self::NOW + 30)));
        self::assertTrue($this->check($runtime, 'acct-c')->isAllowed(), 'at the deadline the unclaimed permits no longer hold a slot');
        self::assertTrue($this->check($runtime, 'acct-d')->isAllowed());
        self::assertSame(self::CAUSE, self::causeOf($this->check($runtime, 'acct-e')));

        // An outcome after the deadline is late: it frees nothing, and the slot was already free.
        $late = $this->settle($runtime, 'acct-a', $first, true);
        self::assertSame('permit_late', self::causeOf($late));
        self::assertSame(2, $this->pendingMembers());
    }

    /** @return iterable<string, array{bool}> */
    public static function outcomes(): iterable
    {
        yield 'success' => [true];
        yield 'failure' => [false];
    }

    #[DataProvider('outcomes')]
    public function testSlotReservedBeforeKeyRotationIsFreedByTheOutcomeRecordedAfterIt(bool $success): void
    {
        $before = $this->runtime(2);
        $this->open($before);
        $a = $this->check($before, 'acct-a');
        self::assertTrue($a->isAllowed());
        self::assertTrue($this->check($before, 'acct-b')->isAllowed());

        // The key secret rotates: the permits above hold their slots under the previous secret's key.
        $after = $this->rotatedRuntime(2);
        self::assertSame(self::CAUSE, self::causeOf($this->check($after, 'acct-c')), 'the previous generation still counts');
        self::assertSame(2, $this->pendingMembers());

        $this->settle($after, 'acct-a', $a, $success);
        self::assertSame(1, $this->pendingMembers(), 'the outcome removed its member from the previous generation');
        self::assertTrue($this->check($after, 'acct-c')->isAllowed(), 'the freed slot is available to a new reservation');
        self::assertSame(self::CAUSE, self::causeOf($this->check($after, 'acct-d')));

        // A replay of the same outcome frees no second slot.
        self::assertSame('permit_duplicate', self::causeOf($this->settle($after, 'acct-a', $a, $success)));
        self::assertSame(2, $this->pendingMembers());
        self::assertSame(self::CAUSE, self::causeOf($this->check($after, 'acct-d')), 'the replay did not free a slot');
    }

    public function testSourcesDoNotShareSlotsAndTheLimitIsIndependentOfFailureCounters(): void
    {
        $runtime = $this->runtime(3);
        $this->open($runtime);
        $held = [];
        foreach (['a1', 'a2', 'a3'] as $account) {
            $held[$account] = $this->check($runtime, $account, self::SOURCE_A);
            self::assertTrue($held[$account]->isAllowed());
        }
        self::assertSame(self::CAUSE, self::causeOf($this->check($runtime, 'a4', self::SOURCE_A)));

        // A full source costs another source nothing.
        foreach (['b1', 'b2', 'b3'] as $account) {
            self::assertTrue($this->check($runtime, $account, self::SOURCE_B)->isAllowed());
        }
        self::assertSame(self::CAUSE, self::causeOf($this->check($runtime, 'b4', self::SOURCE_B)));

        // Refusals by the pending limit never entered the recorded-failure set of the source (limit 4): after the three held
        // permits settle as failures, three accounts are recorded, and a fourth reservation still fits under that cap.
        foreach ($held as $account => $result) {
            $this->settle($runtime, $account, $result, false, self::SOURCE_A);
        }
        self::assertSame(3, $this->pendingMembers(), 'source A holds nothing, source B still holds its three');
        self::assertTrue($this->check($runtime, 'a4', self::SOURCE_A)->isAllowed(), 'three recorded failures + the refused a4 attempt do not exhaust the cap of 4');
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function aliasCases(): iterable
    {
        yield 'identical previous key secret, success' => ['redis-gate-key', true];
        yield 'identical previous key secret, failure' => ['redis-gate-key', false];
        yield 'NUL-padded HMAC-equivalent previous key secret, success' => ["redis-gate-key\0", true];
        yield 'NUL-padded HMAC-equivalent previous key secret, failure' => ["redis-gate-key\0", false];
    }

    #[DataProvider('aliasCases')]
    #[Group('php32')]
    public function testCurrentAndPreviousAliasReleasesPendingSlotOnceOnSettlement(string $previousKeySecret, bool $success): void
    {
        // Independent assertion: current and previous secrets derive the exact same ppend key.
        $deriver = new AdmissionKeyDeriver(
            PublicRedisAdmissionFixture::ENV,
            PublicRedisAdmissionFixture::TRUST_DOMAIN,
            PublicRedisAdmissionFixture::STATE_SECRET,
        );
        $sourceKey = SourcePrefix::of(self::SOURCE_A);
        $currentDerived = $deriver->scopeKey('login', AdmissionScopePurpose::PPEND, $sourceKey, '', 'redis-gate-key');
        $previousDerived = $deriver->scopeKey('login', AdmissionScopePurpose::PPEND, $sourceKey, '', $previousKeySecret);
        self::assertSame($currentDerived, $previousDerived, 'Fixture current/previous secrets must derive the same ppend key');

        $config = new RateLimiterConfig(
            'redis-gate-key',
            'redis-gate-fingerprint',
            PublicRedisAdmissionFixture::ENV,
            $previousKeySecret,
            bindingKeySecret: PublicRedisAdmissionFixture::BINDING_SECRET,
            admissionTrustDomain: PublicRedisAdmissionFixture::TRUST_DOMAIN,
            admissionStateSecret: PublicRedisAdmissionFixture::STATE_SECRET,
        );
        $runtime = PublicRedisAdmissionFixture::build($this->executor, self::NAMESPACE, self::NOW, 500, 1, $this->clock, $config);
        $this->open($runtime);

        // Independent control on SOURCE_B: must remain untouched across all SOURCE_A operations.
        $ctxCtrl = $this->context('acct-ctrl', self::SOURCE_B);
        $beforeCtrl = $this->dump();
        $ctrl = $runtime->limit($ctxCtrl, RateLimitCommand::checkOnly(self::POLICY));
        self::assertTrue($ctrl->isAllowed());
        $ctrlArtifacts = $this->capturePreCheckArtifacts($beforeCtrl, $this->dump(), $ctxCtrl, 'redis-gate-key', 'jti-acct-ctrl');

        $ctxA = $this->context('acct-a', self::SOURCE_A);
        $beforeA = $this->dump();
        $a = $runtime->limit($ctxA, RateLimitCommand::checkOnly(self::POLICY));
        self::assertTrue($a->isAllowed());
        self::assertNotNull($a->metadata?->permit);
        $aArtifacts = $this->capturePreCheckArtifacts($beforeA, $this->dump(), $ctxA, 'redis-gate-key', 'jti-acct-a');

        $beforeRefusal = StateSnapshot::all($this->executor);
        $refused = $this->check($runtime, 'acct-b');
        self::assertFalse($refused->isAllowed());
        self::assertSame(self::CAUSE, self::causeOf($refused));
        self::assertSame($beforeRefusal, StateSnapshot::all($this->executor), 'Refused second slot must leave Redis unchanged');

        $beforeSettle = $this->dump();
        $snapBeforeSettle = StateSnapshot::all($this->executor);
        $settled = $this->settle($runtime, 'acct-a', $a, $success);
        self::assertTrue($settled->isAllowed(), 'Valid permit P before deadline must settle cleanly even with alias previousKeySecret');
        $afterSettle = $this->dump();
        $snapAfterSettle = StateSnapshot::all($this->executor);

        // Exact permit claimed: only `state` and `outcome` change, and PEXPIRETIME is preserved.
        $beforePermitVal = $beforeSettle[$aArtifacts['permitKey']]['value'];
        $afterPermitVal = $afterSettle[$aArtifacts['permitKey']]['value'];
        self::assertIsArray($beforePermitVal);
        self::assertIsArray($afterPermitVal);
        self::assertSame('ISSUED', $beforePermitVal['state'] ?? null);
        self::assertSame('CLAIMED', $afterPermitVal['state'] ?? null);
        self::assertSame($success ? 'success' : 'failure', $afterPermitVal['outcome'] ?? null);
        unset($beforePermitVal['state'], $afterPermitVal['state'], $afterPermitVal['outcome']);
        self::assertSame($beforePermitVal, $afterPermitVal, 'Permit fields other than state/outcome must remain unchanged');
        self::assertSame(
            $this->snapshotPexpire($snapBeforeSettle[$aArtifacts['permitKey']]),
            $this->snapshotPexpire($snapAfterSettle[$aArtifacts['permitKey']]),
            'Claimed permit PEXPIRETIME must remain unchanged',
        );

        $pFailSetKey = $this->deriveAdmSetKey(AdmissionScopePurpose::PFAIL, self::SOURCE_A, 'redis-gate-key');
        $allowedAdmChanges = $success
            ? array_merge([$aArtifacts['permitKey']], array_values($aArtifacts['namedCounterKeys']))
            : [$aArtifacts['permitKey'], $aArtifacts['namedCounterKeys'][AdmissionScopePurpose::PPEND], $pFailSetKey];

        self::assertSame(
            $this->admSnapshotExcluding($snapBeforeSettle, $allowedAdmChanges),
            $this->admSnapshotExcluding($snapAfterSettle, $allowedAdmChanges),
            'Every other :adm:a1: key (including control PDEV, cfg, fence, and preserved proof counters) must keep identical DUMP and PEXPIRETIME',
        );

        if ($success) {
            foreach ($aArtifacts['namedCounterKeys'] as $purpose => $counterKey) {
                $expectedMembers = $this->zsetMembers($beforeSettle, $counterKey);
                self::assertArrayHasKey($aArtifacts['memberId'], $expectedMembers);
                unset($expectedMembers[$aArtifacts['memberId']]);
                self::assertSame($expectedMembers, $this->zsetMembers($afterSettle, $counterKey), "Success must remove only target member from {$purpose}");
                if ($expectedMembers === []) {
                    self::assertArrayNotHasKey($counterKey, $afterSettle, "Empty {$purpose} ZSET key must be removed");
                }
            }
            self::assertSame(
                $this->e1Snapshot($snapBeforeSettle),
                $this->e1Snapshot($snapAfterSettle),
                'Success must not write or re-expire any E1 score/budget key',
            );
        } else {
            $ppendKey = $aArtifacts['namedCounterKeys'][AdmissionScopePurpose::PPEND];
            $expectedPpendMembers = $this->zsetMembers($beforeSettle, $ppendKey);
            self::assertArrayHasKey($aArtifacts['memberId'], $expectedPpendMembers);
            unset($expectedPpendMembers[$aArtifacts['memberId']]);
            self::assertSame($expectedPpendMembers, $this->zsetMembers($afterSettle, $ppendKey), 'Failure must remove only target member from ppend');
            if ($expectedPpendMembers === []) {
                self::assertArrayNotHasKey($ppendKey, $afterSettle, 'Empty ppend ZSET key must be removed');
            }

            $pFailMember = $this->deriveAdmMember('acct-a', 'redis-gate-key');
            $expectedFailMembers = $this->zsetMembers($beforeSettle, $pFailSetKey);
            $expectedFailMembers[$pFailMember] = (string) (self::NOW + 600);
            self::assertSame($expectedFailMembers, $this->zsetMembers($afterSettle, $pFailSetKey), 'Failure must add only expected member with NOW+600 expiry to pfail');

            $k4pKey = $this->deriveE1ScoreKey('k4p', 'acct-a', 'redis-gate-key');
            $k4WrongKey = $this->deriveE1ScoreKey('k4', 'acct-a', 'redis-gate-key');
            $ctrlK4pKey = $this->deriveE1ScoreKey('k4p', 'acct-ctrl', 'redis-gate-key');
            $beforeRaw = is_array($beforeSettle[$k4pKey]['value'] ?? null) ? ($beforeSettle[$k4pKey]['value']['value'] ?? 0) : 0;
            $afterRaw = is_array($afterSettle[$k4pKey]['value'] ?? null) ? ($afterSettle[$k4pKey]['value']['value'] ?? 0) : 0;
            $beforeScore = is_numeric($beforeRaw) ? (int) $beforeRaw : 0;
            $afterScore = is_numeric($afterRaw) ? (int) $afterRaw : 0;
            self::assertSame(3, $afterScore - $beforeScore, 'Failure must increment K4P by exact delta 3');
            self::assertSame($snapBeforeSettle[$k4WrongKey] ?? null, $snapAfterSettle[$k4WrongKey] ?? null, 'Wrong-lane K4 must remain untouched');
            self::assertSame($snapBeforeSettle[$ctrlK4pKey] ?? null, $snapAfterSettle[$ctrlK4pKey] ?? null, 'Control account K4P must remain untouched');
        }

        // New slot on SOURCE_A is now available for a fresh reservation.
        $b = $this->check($runtime, 'acct-b');
        self::assertTrue($b->isAllowed(), 'Freed slot must admit a new reservation');
        self::assertArrayHasKey($aArtifacts['namedCounterKeys'][AdmissionScopePurpose::PPEND], $this->dump());

        // Replaying same and conflicting outcomes must not free a slot or mutate Redis.
        $beforeReplay = StateSnapshot::all($this->executor);
        self::assertSame('permit_duplicate', self::causeOf($this->settle($runtime, 'acct-a', $a, $success)));
        self::assertSame($beforeReplay, StateSnapshot::all($this->executor));
        self::assertSame('permit_conflict', self::causeOf($this->settle($runtime, 'acct-a', $a, ! $success)));
        self::assertSame($beforeReplay, StateSnapshot::all($this->executor));
    }

    /** The refusal or permit cause of a result; read through one helper so the nullability is the declared one on every PHPStan version. */
    private static function causeOf(RateLimitResultDTO $result): ?string
    {
        return $result->metadata?->cause;
    }

    private function runtime(?int $limit): CompositeRateLimiterRuntimeInterface
    {
        return PublicRedisAdmissionFixture::build($this->executor, self::NAMESPACE, self::NOW, 500, $limit, $this->clock);
    }

    private function rotatedRuntime(int $limit): CompositeRateLimiterRuntimeInterface
    {
        $config = new RateLimiterConfig(
            'redis-gate-key-2',
            'redis-gate-fingerprint',
            PublicRedisAdmissionFixture::ENV,
            'redis-gate-key',
            bindingKeySecret: PublicRedisAdmissionFixture::BINDING_SECRET,
            admissionTrustDomain: PublicRedisAdmissionFixture::TRUST_DOMAIN,
            admissionStateSecret: PublicRedisAdmissionFixture::STATE_SECRET,
        );

        return PublicRedisAdmissionFixture::build($this->executor, self::NAMESPACE, self::NOW, 500, $limit, $this->clock, $config);
    }

    private function open(CompositeRateLimiterRuntimeInterface $runtime): void
    {
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $runtime);
        $runtime->openAdmissionFence('login');
    }

    private function context(string $account, string $ip): RateLimitContextDTO
    {
        $now = self::NOW;
        $credential = PublicRedisAdmissionFixture::codec()->encode(
            new AdmissionCredentialDTO($account, $account, ['login'], 'inst-' . $account, 'jti-' . $account, $now + 3600, $now, 0),
        );

        return new RateLimitContextDTO(ip: $ip, ua: 'redis-gate', accountId: $account, clientInstanceId: 'inst-' . $account, admissionCredential: $credential);
    }

    private function check(CompositeRateLimiterRuntimeInterface $runtime, string $account, string $ip = self::SOURCE_A): RateLimitResultDTO
    {
        return $runtime->limit($this->context($account, $ip), RateLimitCommand::checkOnly(self::POLICY));
    }

    private function settle(CompositeRateLimiterRuntimeInterface $runtime, string $account, RateLimitResultDTO $check, bool $success, string $ip = self::SOURCE_A): RateLimitResultDTO
    {
        $permit = $check->metadata?->permit;
        self::assertNotNull($permit);

        return $runtime->limit($this->context($account, $ip), new RateLimitCommand(self::POLICY, 1, false, ! $success, $success, $permit));
    }

    /** Live members of the pending-permit counters, read from the raw ZSETs. */
    private function pendingMembers(): int
    {
        $live = 0;
        foreach (array_keys($this->dump()) as $key) {
            // The pending-permit counter is the only counter whose lifetime is the permit deadline (30 s); the window and day
            // counters live 600 s and 86400 s.
            if (! str_contains((string) $key, ':c:') || ReplyValue::integer($this->raw('TTL', $key)) > 30) {
                continue;
            }
            $members = $this->raw('ZRANGE', $key, '0', '-1', 'WITHSCORES');
            if (! is_array($members)) {
                continue;
            }
            $ids = array_values($members);
            for ($i = 0; $i < count($ids); $i += 2) {
                if (is_string($ids[$i]) && preg_match('/^a1\.permit\.[0-9a-f]{64}$/', $ids[$i]) === 1 && ReplyValue::integer($ids[$i + 1]) > $this->clock->now()->getTimestamp()) {
                    $live++;
                }
            }
        }

        return $live;
    }

    /**
     * @param array<string, array{type: string, value: mixed, hasTtl: bool}> $before
     * @param array<string, array{type: string, value: mixed, hasTtl: bool}> $after
     * @return array{permitKey: string, memberId: string, namedCounterKeys: array<string, string>}
     */
    private function capturePreCheckArtifacts(
        array $before,
        array $after,
        RateLimitContextDTO $context,
        string $outerSecret,
        string $jti,
    ): array {
        $deriver = new AdmissionKeyDeriver(
            PublicRedisAdmissionFixture::ENV,
            PublicRedisAdmissionFixture::TRUST_DOMAIN,
            PublicRedisAdmissionFixture::STATE_SECRET,
        );
        $expectedSubject = $deriver->permitContextCommitment('login', self::POLICY, $context);
        $permitKey = null;
        $counterKeys = [];
        $memberId = null;

        foreach ($after as $key => $entry) {
            $keyStr = (string) $key;
            if (str_contains($keyStr, ':adm:a1:permit:') && ! array_key_exists($keyStr, $before)) {
                self::assertNull($permitKey);
                self::assertIsArray($entry['value']);
                self::assertSame('ISSUED', $entry['value']['state'] ?? null);
                self::assertSame($expectedSubject, $entry['value']['subject'] ?? null);
                $permitKey = $keyStr;
            }
            if (str_contains($keyStr, ':adm:a1:c:') && $entry['type'] === 'zset' && is_array($entry['value'])) {
                $prevMembers = $this->zsetMembers($before, $keyStr);
                $diff = array_diff(array_keys($entry['value']), array_keys($prevMembers));
                if ($diff !== []) {
                    $counterKeys[] = $keyStr;
                    $first = (string) reset($diff);
                    $memberId ??= $first;
                    self::assertSame($memberId, $first);
                }
            }
        }

        self::assertNotNull($permitKey);
        self::assertNotNull($memberId);
        self::assertSame($this->derivePermitKey($memberId), $permitKey);

        $account = (string) $context->accountId;
        $proof = substr(hash('sha256', $jti), 0, 32);
        $namedCounterKeys = [
            AdmissionScopePurpose::PACCT => $this->deriveAdmCounterKey(AdmissionScopePurpose::PACCT, $account, $outerSecret),
            AdmissionScopePurpose::PACCTD => $this->deriveAdmCounterKey(AdmissionScopePurpose::PACCTD, $account, $outerSecret),
            AdmissionScopePurpose::PPROOF => $this->deriveAdmCounterKey(AdmissionScopePurpose::PPROOF, $proof, $outerSecret),
            AdmissionScopePurpose::PPROOFD => $this->deriveAdmCounterKey(AdmissionScopePurpose::PPROOFD, $proof, $outerSecret),
            AdmissionScopePurpose::PPEND => $this->deriveAdmCounterKey(AdmissionScopePurpose::PPEND, $context->ip, $outerSecret),
        ];
        foreach ($namedCounterKeys as $purpose => $expectedKey) {
            self::assertContains($expectedKey, $counterKeys, "Missing expected {$purpose} counter key");
        }

        return [
            'permitKey' => $permitKey,
            'memberId' => $memberId,
            'namedCounterKeys' => $namedCounterKeys,
        ];
    }

    /**
     * @param array<string, array{type: string, value: mixed, hasTtl: bool}> $dump
     * @return array<string, mixed>
     */
    private function zsetMembers(array $dump, string $key): array
    {
        if (! array_key_exists($key, $dump)) {
            return [];
        }
        self::assertSame('zset', $dump[$key]['type']);
        self::assertIsArray($dump[$key]['value']);
        $out = [];
        foreach ($dump[$key]['value'] as $member => $score) {
            $out[(string) $member] = $score;
        }

        return $out;
    }

    private function deriveAdmCounterKey(string $purpose, string $value, string $outerSecret): string
    {
        $deriver = new AdmissionKeyDeriver(
            PublicRedisAdmissionFixture::ENV,
            PublicRedisAdmissionFixture::TRUST_DOMAIN,
            PublicRedisAdmissionFixture::STATE_SECRET,
        );
        $logical = $deriver->scopeKey('login', $purpose, $value, '', $outerSecret);
        $preimage = "maatify.rate-limiter.redis.adm.a1.scope.c\0"
            . pack('N', 2)
            . pack('N', strlen('login')) . 'login'
            . pack('N', strlen($logical)) . $logical;

        return 'maatify:rate-limiter:v1:{' . hash('sha256', self::NAMESPACE) . '}:adm:a1:c:' . hash('sha256', $preimage);
    }

    private function deriveAdmSetKey(string $purpose, string $value, string $outerSecret): string
    {
        $deriver = new AdmissionKeyDeriver(
            PublicRedisAdmissionFixture::ENV,
            PublicRedisAdmissionFixture::TRUST_DOMAIN,
            PublicRedisAdmissionFixture::STATE_SECRET,
        );
        $logical = $deriver->scopeKey('login', $purpose, $value, '', $outerSecret);
        $preimage = "maatify.rate-limiter.redis.adm.a1.scope.s\0"
            . pack('N', 2)
            . pack('N', strlen('login')) . 'login'
            . pack('N', strlen($logical)) . $logical;

        return 'maatify:rate-limiter:v1:{' . hash('sha256', self::NAMESPACE) . '}:adm:a1:s:' . hash('sha256', $preimage);
    }

    private function deriveAdmMember(string $account, string $outerSecret): string
    {
        $deriver = new AdmissionKeyDeriver(
            PublicRedisAdmissionFixture::ENV,
            PublicRedisAdmissionFixture::TRUST_DOMAIN,
            PublicRedisAdmissionFixture::STATE_SECRET,
        );

        return $deriver->scopeKey('login', AdmissionScopePurpose::MEMBER, $account, '', $outerSecret);
    }

    private function derivePermitKey(string $memberId): string
    {
        return 'maatify:rate-limiter:v1:{' . hash('sha256', self::NAMESPACE) . '}:adm:a1:permit:' . hash('sha256', $memberId);
    }

    private function deriveE1ScoreKey(string $purpose, string $account, string $outerSecret): string
    {
        $logical = (new E1AccountScopeKeyDeriver(PublicRedisAdmissionFixture::ENV))->derive($purpose, self::POLICY, $account, $outerSecret);

        return 'maatify:rate-limiter:e1:' . hash('sha256', self::NAMESPACE) . ':score:' . hash('sha256', $logical);
    }

    /**
     * @param array<string, string> $snapshot
     * @param list<string> $excludedKeys
     * @return array<string, string>
     */
    private function admSnapshotExcluding(array $snapshot, array $excludedKeys): array
    {
        return array_filter(
            $snapshot,
            static fn(string $k): bool => str_contains($k, ':adm:a1:') && ! in_array($k, $excludedKeys, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param array<string, string> $snapshot
     * @return array<string, string>
     */
    private function e1Snapshot(array $snapshot): array
    {
        return array_filter(
            $snapshot,
            static fn(string $k): bool => str_contains($k, ':e1:'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function snapshotPexpire(string $snapshotValue): string
    {
        $pos = strrpos($snapshotValue, '@');
        self::assertNotFalse($pos);

        return substr($snapshotValue, $pos + 1);
    }
}
