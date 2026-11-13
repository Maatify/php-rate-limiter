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
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionRaceRunner;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionRaceSpec;
use Maatify\RateLimiter\Tests\Support\Admission\PublicRedisAdmissionFixture;
use Maatify\RateLimiter\Tests\Support\Admission\ReplyValue;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
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
        self::assertSame(self::CAUSE, $this->check($runtime, 'acct-c')->metadata?->cause, 'the third pending permit is over the limit');
        self::assertSame(2, $this->pendingMembers(), 'a refusal writes nothing');

        // A recorded success frees the slot of its permit.
        $this->settle($runtime, 'acct-a', $a, true);
        self::assertSame(1, $this->pendingMembers());
        $d = $this->check($runtime, 'acct-d');
        self::assertTrue($d->isAllowed());
        self::assertSame(2, $this->pendingMembers());

        // Replaying the outcome of the same permit is not applied and frees nothing.
        $replay = $this->settle($runtime, 'acct-a', $a, true);
        self::assertSame('permit_duplicate', $replay->metadata?->cause);
        self::assertSame(2, $this->pendingMembers());
        self::assertSame(self::CAUSE, $this->check($runtime, 'acct-e')->metadata?->cause, 'the replay did not free a slot for another permit');
        // A replay that flips the outcome is a conflict and frees nothing either.
        self::assertSame('permit_conflict', $this->settle($runtime, 'acct-a', $a, false)->metadata?->cause);
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
        self::assertSame(self::CAUSE, $this->check($runtime, 'acct-c')->metadata?->cause);

        $this->clock->setNow(new \DateTimeImmutable('@' . (self::NOW + 29)));
        self::assertSame(self::CAUSE, $this->check($runtime, 'acct-c')->metadata->cause, 'one second before the deadline D = 30 s the permits still hold');

        $this->clock->setNow(new \DateTimeImmutable('@' . (self::NOW + 30)));
        self::assertTrue($this->check($runtime, 'acct-c')->isAllowed(), 'at the deadline the unclaimed permits no longer hold a slot');
        self::assertTrue($this->check($runtime, 'acct-d')->isAllowed());
        self::assertSame(self::CAUSE, $this->check($runtime, 'acct-e')->metadata?->cause);

        // An outcome after the deadline is late: it frees nothing, and the slot was already free.
        $late = $this->settle($runtime, 'acct-a', $first, true);
        self::assertSame('permit_late', $late->metadata?->cause);
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
        self::assertSame(self::CAUSE, $this->check($after, 'acct-c')->metadata?->cause, 'the previous generation still counts');
        self::assertSame(2, $this->pendingMembers());

        $this->settle($after, 'acct-a', $a, $success);
        self::assertSame(1, $this->pendingMembers(), 'the outcome removed its member from the previous generation');
        self::assertTrue($this->check($after, 'acct-c')->isAllowed(), 'the freed slot is available to a new reservation');
        self::assertSame(self::CAUSE, $this->check($after, 'acct-d')->metadata?->cause);

        // A replay of the same outcome frees no second slot.
        self::assertSame('permit_duplicate', $this->settle($after, 'acct-a', $a, $success)->metadata?->cause);
        self::assertSame(2, $this->pendingMembers());
        self::assertSame(self::CAUSE, $this->check($after, 'acct-d')->metadata?->cause, 'the replay did not free a slot');
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
        self::assertSame(self::CAUSE, $this->check($runtime, 'a4', self::SOURCE_A)->metadata?->cause);

        // A full source costs another source nothing.
        foreach (['b1', 'b2', 'b3'] as $account) {
            self::assertTrue($this->check($runtime, $account, self::SOURCE_B)->isAllowed());
        }
        self::assertSame(self::CAUSE, $this->check($runtime, 'b4', self::SOURCE_B)->metadata?->cause);

        // Refusals by the pending limit never entered the recorded-failure set of the source (limit 4): after the three held
        // permits settle as failures, three accounts are recorded, and a fourth reservation still fits under that cap.
        foreach ($held as $account => $result) {
            $this->settle($runtime, $account, $result, false, self::SOURCE_A);
        }
        self::assertSame(3, $this->pendingMembers(), 'source A holds nothing, source B still holds its three');
        self::assertTrue($this->check($runtime, 'a4', self::SOURCE_A)->isAllowed(), 'three recorded failures + the refused a4 attempt do not exhaust the cap of 4');
    }

    private function runtime(?int $limit): CompositeRateLimiterRuntimeInterface
    {
        return PublicRedisAdmissionFixture::build($this->executor, self::NAMESPACE, self::NOW, 500, $limit, $this->clock);
    }

    private function rotatedRuntime(int $limit): CompositeRateLimiterRuntimeInterface
    {
        $config = new RateLimiterConfig('redis-gate-key-2', 'redis-gate-fingerprint', 'test', 'redis-gate-key', bindingKeySecret: PublicRedisAdmissionFixture::BINDING_SECRET);

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
        $credential = (new AdmissionCredentialCodec(PublicRedisAdmissionFixture::BINDING_SECRET))->encode(
            new AdmissionCredentialDTO($account, ['login'], 'inst-' . $account, 'jti-' . $account, $now + 3600, $now, 0),
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
                if (is_string($ids[$i]) && preg_match('/^[0-9a-f]{32}$/', $ids[$i]) === 1 && ReplyValue::integer($ids[$i + 1]) > $this->clock->now()->getTimestamp()) {
                    $live++;
                }
            }
        }

        return $live;
    }
}
