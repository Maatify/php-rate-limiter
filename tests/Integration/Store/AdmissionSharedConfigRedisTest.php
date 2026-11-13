<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\Config\RateLimiterConfig;
use Maatify\RateLimiter\DTO\AdmissionCredentialDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\AdmissionConfigurationException;
use Maatify\RateLimiter\Service\AdmissionCredentialCodec;
use Maatify\RateLimiter\Service\AdmissionFenceControlInterface;
use Maatify\RateLimiter\Service\AdmissionSharedConfigControlInterface;
use Maatify\RateLimiter\Service\CompositeRateLimiterRuntimeInterface;
use Maatify\RateLimiter\Tests\Support\Admission\PublicRedisAdmissionFixture;
use Maatify\RateLimiter\Tests\Support\Admission\ReplyValue;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use PHPUnit\Framework\Attributes\Group;

/**
 * DEC-038: the shared pending-permit configuration of a budget group, checked by every worker on one Redis before it accepts a
 * reservation. Public runtime through `RateLimiterBuilder`, real Redis.
 */
#[Group('integration')]
final class AdmissionSharedConfigRedisTest extends AdmissionRedisGateTestCase
{
    private const NOW = 1000000;
    private const POLICY = 'login_protection';
    private const CAUSE = 'source_pending_permits';
    private const IP = '198.51.100.10';

    private FixedClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FixedClock('@' . self::NOW);
    }

    public function testTwoWorkersWithTheSameLimitShareOneLimit(): void
    {
        $a = $this->worker(3);
        $b = $this->worker(3);
        $this->open($a);
        self::assertTrue($this->check($a, 'acct-1')->isAllowed());
        self::assertTrue($this->check($b, 'acct-2')->isAllowed());
        self::assertTrue($this->check($a, 'acct-3')->isAllowed());
        self::assertSame(self::CAUSE, self::causeOf($this->check($b, 'acct-4')), 'three pending permits across both workers reach the one limit');
        self::assertSame(self::CAUSE, self::causeOf($this->check($a, 'acct-4')));
        self::assertSame(3, $this->pendingMembers());
    }

    public function testWorkersWithDifferentLimitsRaiseAConfigurationErrorBeforeReserving(): void
    {
        $three = $this->worker(3);
        $five = $this->worker(5);
        $this->open($three);
        self::assertTrue($this->check($three, 'acct-1')->isAllowed(), 'the first accepted reservation registers 3');
        $before = $this->snapshot();

        try {
            $this->check($five, 'acct-9');
            self::fail('A worker with a different limit must not reserve.');
        } catch (AdmissionConfigurationException $e) {
            self::assertStringContainsString('differs', $e->getMessage());
        }
        self::assertSame($before, $this->snapshot(), 'nothing was reserved or written by the mismatched worker');
        self::assertTrue($this->check($three, 'acct-2')->isAllowed(), 'the worker holding the registered value is unaffected');

        // The unproven lane is checked too: the configuration is per budget group, not per lane.
        $this->expectException(AdmissionConfigurationException::class);
        $five->limit(new RateLimitContextDTO(ip: '203.0.113.9', ua: 'redis-gate', accountId: 'stranger'), RateLimitCommand::checkOnly(self::POLICY));
    }

    public function testAnEnabledWorkerAndADisabledWorkerDisagreeInEitherOrder(): void
    {
        foreach ([[null, 3], [3, null]] as [$first, $second]) {
            $this->resetRedis();
            $one = $this->worker($first);
            $two = $this->worker($second);
            $this->open($one);
            self::assertTrue($this->check($one, 'acct-1')->isAllowed());
            $before = $this->snapshot();
            try {
                $this->check($two, 'acct-2');
                self::fail('The enabled and the disabled worker must not both reserve.');
            } catch (AdmissionConfigurationException) {
                self::assertSame($before, $this->snapshot());
            }
        }
    }

    public function testRebindingIsExplicitAndTheLimitGovernsPermitsThatAlreadyExist(): void
    {
        $five = $this->worker(5);
        $this->open($five);
        $held = [];
        foreach (['a1', 'a2', 'a3', 'a4'] as $account) {
            $held[$account] = $this->check($five, $account);
            self::assertTrue($held[$account]->isAllowed());
        }

        $two = $this->worker(2);
        try {
            $this->check($two, 'b1');
            self::fail('A worker must not change the shared value by itself.');
        } catch (AdmissionConfigurationException) {
            self::assertTrue($this->check($five, 'a5')->isAllowed(), 'the registered value is still 5');
        }

        // Lowering: the operator rebinds. The four existing permits are not revoked, they govern new reservations.
        self::assertInstanceOf(AdmissionSharedConfigControlInterface::class, $two);
        $two->rebindAdmissionSharedConfig('login');
        self::assertSame(self::CAUSE, self::causeOf($this->check($two, 'b1')), 'five permits are pending against a limit of 2');
        try {
            $this->check($five, 'b2');
            self::fail('The old worker now disagrees.');
        } catch (AdmissionConfigurationException) {
            self::addToAssertionCount(1);
        }
        foreach (['a1', 'a2', 'a3', 'a4'] as $account) {
            $this->settle($two, $account, $held[$account], true);
        }
        self::assertSame(1, $this->pendingMembers());
        self::assertTrue($this->check($two, 'b1')->isAllowed(), 'only once the pending count is under the new limit');
        self::assertSame(self::CAUSE, self::causeOf($this->check($two, 'b3')));

        // Disabling: the pending slots are ignored, and they still hold members until their deadline.
        $off = $this->worker(null);
        self::assertInstanceOf(AdmissionSharedConfigControlInterface::class, $off);
        $off->rebindAdmissionSharedConfig('login');
        for ($i = 0; $i < 4; $i++) {
            self::assertTrue($this->check($off, 'c' . $i)->isAllowed());
        }
        // Permits reserved while disabled hold no slot (nothing is written to the counter); the two reserved before still do.
        self::assertSame(2, $this->pendingMembers());
        $two->rebindAdmissionSharedConfig('login');
        self::assertSame(self::CAUSE, self::causeOf($this->check($two, 'c9')));
    }

    public function testTheRegistrationSurvivesAKeyRotation(): void
    {
        $before = $this->worker(3);
        $this->open($before);
        self::assertTrue($this->check($before, 'acct-1')->isAllowed());

        $rotatedSame = $this->rotatedWorker(3);
        self::assertTrue($this->check($rotatedSame, 'acct-2')->isAllowed(), 'the same limit passes through the previous-generation registration');
        $rotatedOther = $this->rotatedWorker(4);
        $this->expectException(AdmissionConfigurationException::class);
        $this->check($rotatedOther, 'acct-3');
    }

    /** The refusal or permit cause of a result; read through one helper so the nullability is the declared one on every PHPStan version. */
    private static function causeOf(RateLimitResultDTO $result): ?string
    {
        return $result->metadata?->cause;
    }

    private function worker(?int $limit): CompositeRateLimiterRuntimeInterface
    {
        return PublicRedisAdmissionFixture::build($this->executor, self::NAMESPACE, self::NOW, 500, $limit, $this->clock);
    }

    private function rotatedWorker(?int $limit): CompositeRateLimiterRuntimeInterface
    {
        $config = new RateLimiterConfig('redis-gate-key-2', 'redis-gate-fingerprint', 'test', 'redis-gate-key', bindingKeySecret: PublicRedisAdmissionFixture::BINDING_SECRET);

        return PublicRedisAdmissionFixture::build($this->executor, self::NAMESPACE, self::NOW, 500, $limit, $this->clock, $config);
    }

    private function open(CompositeRateLimiterRuntimeInterface $runtime): void
    {
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $runtime);
        $runtime->openAdmissionFence('login');
    }

    private function context(string $account): RateLimitContextDTO
    {
        $credential = (new AdmissionCredentialCodec(PublicRedisAdmissionFixture::BINDING_SECRET))->encode(
            new AdmissionCredentialDTO($account, ['login'], 'inst-' . $account, 'jti-' . $account, self::NOW + 3600, self::NOW, 0),
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

    /** @return array<string, array{type: string, value: mixed, hasTtl: bool}> */
    private function snapshot(): array
    {
        return $this->dump();
    }

    /** Live members of the pending-permit counters (the only counters living as long as the permit deadline). */
    private function pendingMembers(): int
    {
        $live = 0;
        foreach (array_keys($this->dump()) as $key) {
            if (! str_contains((string) $key, ':c:') || ReplyValue::integer($this->raw('TTL', $key)) > 30) {
                continue;
            }
            $members = $this->raw('ZRANGE', $key, '0', '-1', 'WITHSCORES');
            if (! is_array($members)) {
                continue;
            }
            $ids = array_values($members);
            for ($i = 0; $i < count($ids); $i += 2) {
                if (ReplyValue::integer($ids[$i + 1]) > $this->clock->now()->getTimestamp()) {
                    $live++;
                }
            }
        }

        return $live;
    }
}
