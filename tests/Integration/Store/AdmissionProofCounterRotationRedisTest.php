<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\AdmissionCredentialDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Service\AdmissionCredentialCodec;
use Maatify\RateLimiter\Service\AdmissionFenceControlInterface;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Tests\Support\Admission\PublicRedisAdmissionFixture;
use Maatify\RateLimiter\Tests\Support\Admission\ReplyValue;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use PHPUnit\Framework\Attributes\Group;

/**
 * #209: the success refund of the four proven-lane counters (`pacct`, `pacctd`, `pproof`, `pproofd`) through
 * `RateLimiterBuilder` and the public runtime on a real Redis, in the same generation and after one coordinated key rotation.
 *
 * Contract under test: DEC-033 refunds the reservation's member with `ZREM cur`; KEY_STRATEGY §4.8 says a previous-generation
 * key is never written. A permit reserved before a rotation therefore keeps its member in the previous-generation counter
 * until that member's own expiry, and the success outcome neither removes it nor refreshes the key's lifetime.
 */
#[Group('integration')]
final class AdmissionProofCounterRotationRedisTest extends AdmissionRedisGateTestCase
{
    private const NOW = 1000000;
    private const POLICY = 'login_protection';
    private const IP = '198.51.100.10';

    private FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FixedClock('@' . self::NOW);
    }

    public function testSameGenerationSuccessRefundsEveryProofCounterAndSparesTheControl(): void
    {
        $runtime = $this->runtime();
        $this->open($runtime);
        [$a, $aId] = $this->reserve($runtime, 'acct-a');
        [$b, $bId] = $this->reserve($runtime, 'acct-b');
        self::assertCount(4, $this->countersHolding($aId), 'the reservation entered pacct, pacctd, pproof and pproofd');
        self::assertCount(4, $this->countersHolding($bId));

        $bBefore = $this->counterState($bId);
        self::assertTrue($this->settle($runtime, 'acct-a', $a, true)->isAllowed());

        self::assertSame([], $this->countersHolding($aId), 'the success removed the member from all four counters');
        self::assertSame($bBefore, $this->counterState($bId), 'the control permit is untouched');
        $this->assertDecisionsAfterRefund($runtime, 'acct-a');

        $afterRefund = $this->counterState($bId);
        self::assertSame('permit_duplicate', self::causeOf($this->settle($runtime, 'acct-a', $a, true)));
        self::assertSame($afterRefund, $this->counterState($bId), 'a repeated outcome does not touch the control');
    }

    public function testSuccessAfterOneRotationLeavesPreviousGenerationCountersReadOnly(): void
    {
        $before = $this->runtime();
        $this->open($before);
        [$a, $aId] = $this->reserve($before, 'acct-a');
        [$b, $bId] = $this->reserve($before, 'acct-b');

        $after = $this->rotatedRuntime();
        $aKeys = $this->countersHolding($aId);
        self::assertCount(4, $aKeys, 'under the previous generation');
        $snapshotA = $this->counterState($aId);
        $snapshotB = $this->counterState($bId);
        $expiryA = $this->expiries($aKeys);

        self::assertTrue($this->settle($after, 'acct-a', $a, true)->isAllowed(), 'the success outcome is accepted after the rotation');

        self::assertSame($aKeys, $this->countersHolding($aId), 'the previous generation keeps the member: it is read-only');
        self::assertSame($snapshotA, $this->counterState($aId), 'contents are byte-for-byte unchanged');
        self::assertSame($expiryA, $this->expiries($aKeys), 'no TTL was refreshed or shortened');
        self::assertSame($snapshotB, $this->counterState($bId), 'the control permit is untouched');

        // A repeated outcome neither changes anything nor frees anything.
        self::assertSame('permit_duplicate', self::causeOf($this->settle($after, 'acct-a', $a, true)));
        self::assertSame($snapshotA, $this->counterState($aId));
        self::assertSame($snapshotB, $this->counterState($bId));
        self::assertSame($expiryA, $this->expiries($aKeys));
    }

    public function testPublicEffectOfTheRetainedMemberAfterRotation(): void
    {
        // Proof cap is 6 per window: six reservations of one proof fill it; a success frees a slot only in the same generation.
        $same = $this->runtime();
        $this->open($same);
        $held = $this->fillProof($same);
        self::assertSame('proof_capacity', self::causeOf($this->check($same, 'acct-a')), 'full');
        $this->settle($same, 'acct-a', $held[0], true);
        self::assertTrue($this->check($same, 'acct-a')->isAllowed(), 'same generation: the refunded slot is reusable');

        $this->resetRedis();
        $before = $this->runtime();
        $this->open($before);
        $held = $this->fillProof($before);
        $after = $this->rotatedRuntime();
        self::assertSame('proof_capacity', self::causeOf($this->check($after, 'acct-a')), 'the previous generation counts');
        self::assertTrue($this->settle($after, 'acct-a', $held[0], true)->isAllowed());
        self::assertSame('proof_capacity', self::causeOf($this->check($after, 'acct-a')), 'the member in the previous generation is not refunded; it lapses with its own window');
    }

    /** The refusal or permit cause of a result; one helper so the nullability is the declared one on every PHPStan version. */
    private static function causeOf(RateLimitResultDTO $result): ?string
    {
        return $result->metadata?->cause;
    }

    private function assertDecisionsAfterRefund(CompositeRateLimiterRuntimeInterface $runtime, string $account): void
    {
        self::assertTrue($this->check($runtime, $account)->isAllowed());
    }

    /** @return list<RateLimitResultDTO> */
    private function fillProof(CompositeRateLimiterRuntimeInterface $runtime): array
    {
        $held = [];
        for ($i = 0; $i < 6; ++$i) {
            $result = $this->check($runtime, 'acct-a');
            self::assertTrue($result->isAllowed(), 'reservation ' . $i);
            $held[] = $result;
        }

        return $held;
    }

    /**
     * One admitted check and the permit-ID member it wrote: the single member new to the counters, found by comparison.
     *
     * @return array{RateLimitResultDTO, string}
     */
    private function reserve(CompositeRateLimiterRuntimeInterface $runtime, string $account): array
    {
        $known = $this->allMembers();
        $result = $this->check($runtime, $account);
        self::assertTrue($result->isAllowed());
        $new = array_values(array_diff($this->allMembers(), $known));
        self::assertCount(1, $new, 'one new permit-ID member across the four counters');

        return [$result, $new[0]];
    }

    /** @return list<string> */
    private function allMembers(): array
    {
        $members = [];
        foreach (array_keys($this->dump()) as $key) {
            if (! str_contains((string) $key, ':c:')) {
                continue;
            }
            $flat = ReplyValue::list($this->raw('ZRANGE', (string) $key, '0', '-1'));
            foreach ($flat as $member) {
                $members[] = ReplyValue::text($member);
            }
        }

        return array_values(array_unique($members));
    }

    /** @return list<string> the counter keys (family `c`) that hold the member, sorted */
    private function countersHolding(string $member): array
    {
        $keys = [];
        foreach (array_keys($this->dump()) as $key) {
            if (str_contains((string) $key, ':c:') && $this->raw('ZSCORE', (string) $key, $member) !== null) {
                $keys[] = (string) $key;
            }
        }
        sort($keys);

        return $keys;
    }

    /** @return array<string, mixed> member, score and the whole zset of every counter holding the member, by key */
    private function counterState(string $member): array
    {
        $out = [];
        foreach ($this->countersHolding($member) as $key) {
            $out[$key] = $this->raw('ZRANGE', $key, '0', '-1', 'WITHSCORES');
        }

        return $out;
    }

    /**
     * @param list<string> $keys
     * @return array<string, int> absolute expiry in milliseconds
     */
    private function expiries(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = ReplyValue::integer($this->raw('PEXPIRETIME', $key));
        }

        return $out;
    }

    private function runtime(): CompositeRateLimiterRuntimeInterface
    {
        return PublicRedisAdmissionFixture::build($this->executor, self::NAMESPACE, self::NOW, 500, null, $this->clock);
    }

    private function rotatedRuntime(): CompositeRateLimiterRuntimeInterface
    {
        $config = new RateLimiterConfig('redis-gate-key-2', 'redis-gate-fingerprint', 'test', 'redis-gate-key', bindingKeySecret: PublicRedisAdmissionFixture::BINDING_SECRET);

        return PublicRedisAdmissionFixture::build($this->executor, self::NAMESPACE, self::NOW, 500, null, $this->clock, $config);
    }

    private function open(CompositeRateLimiterRuntimeInterface $runtime): void
    {
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $runtime);
        $runtime->openAdmissionFence('login');
    }

    private function context(string $account): RateLimitContextDTO
    {
        $now = self::NOW;
        $credential = (new AdmissionCredentialCodec(PublicRedisAdmissionFixture::BINDING_SECRET))->encode(
            new AdmissionCredentialDTO($account, ['login'], 'inst-' . $account, 'jti-' . $account, $now + 3600, $now, 0),
        );

        return new RateLimitContextDTO(ip: self::IP, ua: 'redis-gate', accountId: $account, clientInstanceId: 'inst-' . $account, admissionCredential: $credential);
    }

    private function check(CompositeRateLimiterRuntimeInterface $runtime, string $account): RateLimitResultDTO
    {
        return $runtime->limit($this->context($account), RateLimitCommand::checkOnly(self::POLICY));
    }

    private function settle(CompositeRateLimiterRuntimeInterface $runtime, string $account, RateLimitResultDTO $check, bool $success): RateLimitResultDTO
    {
        $permit = $check->metadata?->permit;
        self::assertNotNull($permit);

        return $runtime->limit($this->context($account), new RateLimitCommand(self::POLICY, 1, false, ! $success, $success, $permit));
    }
}
