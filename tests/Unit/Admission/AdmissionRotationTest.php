<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\Admission;

use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Service\AdmissionRuntime;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionInMemoryReferenceStore;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\GateReport;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;
use Maatify\RateLimiter\Tests\Support\Admission\ServiceMutant;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Stage 5-c, LT-27 (5c/PLAN.md §7.6.4): the admission layer across key-secret rotations, through the builder, over one store.
 * (1) a second rotation inside one window re-issues the source capacity (its size is reported); (2) the set-size and bucket
 * scopes restart at one rotation (reported); (3) the fleet fence follows the previous key across one rotation.
 */
#[Group('lt-27')]
#[Group('rot')]
final class AdmissionRotationTest extends TestCase
{
    private const S1 = 'key-secret-1';
    private const S2 = 'key-secret-2';
    private const S3 = 'key-secret-3';

    private AdmissionInMemoryReferenceStore $store;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FixedClock('@' . EngineLab::T0);
        $this->store = new AdmissionInMemoryReferenceStore($this->clock);
    }

    private function build(string $key, ?string $previous, bool $openFence): EngineLab
    {
        return EngineLab::throughBuilder($openFence, $this->store, $this->clock, $key, $previous);
    }

    private function at(int $seconds): void
    {
        $this->clock->setNow((new \DateTimeImmutable('@' . (EngineLab::T0 + $seconds)))->setTimezone($this->clock->getTimezone()));
    }

    /** Admissions of fresh unknown accounts from one source, each followed by its success, until the first refusal. */
    private static function admittedUntilRefused(EngineLab $lab, string $ip, string $prefix, int $limit = 100): int
    {
        for ($i = 0; $i < $limit; $i++) {
            $context = $lab->context($prefix . $i, $ip);
            $r = $lab->check($context);
            if ($r->decision !== RateLimitResultDTO::DECISION_ALLOW) {
                self::assertSame('source_capacity', EngineLab::cause($r));

                return $i;
            }
            $lab->outcome($context, true, EngineLab::permit($r));
        }

        return $limit;
    }

    public function testOneRotationKeepsTheSpentCapacityAndASecondOneReissuesIt(): void
    {
        $ip = '203.0.113.60';
        self::assertSame(24, self::admittedUntilRefused($this->build(self::S1, null, true), $ip, 'r1-'));

        $this->at(100);
        $afterFirst = self::admittedUntilRefused($this->build(self::S2, self::S1, true), $ip, 'r2-');
        $this->at(200);
        $afterSecond = self::admittedUntilRefused($this->build(self::S3, self::S2, true), $ip, 'r3-');

        GateReport::emit('lt-27-rotation-capacity', ['afterFirstRotation' => $afterFirst, 'afterSecondRotation' => $afterSecond]);
        self::assertSame(0, $afterFirst, 'C-c3: counters sum current and previous across one rotation');
    }

    public function testDetectorABuildWithoutThePreviousGenerationReissuesAtTheFirstRotation(): void
    {
        $ip = '203.0.113.61';
        self::admittedUntilRefused($this->build(self::S1, null, true), $ip, 'd1-');
        $this->at(100);

        self::assertSame(24, self::admittedUntilRefused($this->build(self::S2, null, true), $ip, 'd2-'), 'DETECTOR: no previous generation, full re-issue');
    }

    public function testTheFailureSetAndTheBucketRestartAtOneRotation(): void
    {
        $lab = $this->build(self::S1, null, true);
        $failIp = '203.0.113.62';
        for ($i = 0; $i < 4; $i++) {
            $context = $lab->context('f-' . $i, $failIp);
            $lab->outcome($context, false, EngineLab::permit($lab->check($context)));
        }
        self::assertSame('source_failure_cap', EngineLab::cause($lab->check($lab->context('f-4', $failIp))));

        $bucketIp = '203.0.113.63';
        $owner = $lab->context('bucket-owner', $bucketIp);
        for ($i = 0; $i < 4; $i++) {
            $lab->outcome($owner, false, EngineLab::permit($lab->check($owner)));
        }
        self::assertSame('account_bucket', EngineLab::cause($lab->check($owner)));

        $this->at(100);
        $rotated = $this->build(self::S2, self::S1, true);
        $failures = 0;
        for ($i = 0; $i < 20; $i++) {
            $context = $rotated->context('g-' . $i, $failIp);
            $r = $rotated->check($context);
            if ($r->decision !== RateLimitResultDTO::DECISION_ALLOW) {
                self::assertSame('source_failure_cap', EngineLab::cause($r));
                break;
            }
            $rotated->outcome($context, false, EngineLab::permit($r));
            $failures++;
        }
        $takes = 0;
        for ($i = 0; $i < 20; $i++) {
            $r = $rotated->check($owner);
            if ($r->decision !== RateLimitResultDTO::DECISION_ALLOW) {
                self::assertSame('account_bucket', EngineLab::cause($r));
                break;
            }
            $rotated->outcome($owner, false, EngineLab::permit($r));
            $takes++;
        }

        GateReport::emit('lt-27-set-and-bucket-restart', ['failingAccountsAfterRotation' => $failures, 'bucketTakesAfterRotation' => $takes]);
        self::assertGreaterThan(0, $failures + $takes, 'the restart is observable (KS §4.8)');
    }

    public function testTheFenceFollowsThePreviousKeyAcrossOneRotationAndClosesAtTheSecond(): void
    {
        $first = $this->build(self::S1, null, true);
        self::assertTrue($this->runtimeOf($first)->fenceOpen(EngineLab::GROUP));

        $second = $this->build(self::S2, self::S1, false);
        self::assertTrue($this->runtimeOf($second)->fenceOpen(EngineLab::GROUP), 'LT-27 (3): read the current key, else the previous one');

        $third = $this->build(self::S3, self::S2, false);
        self::assertFalse($this->runtimeOf($third)->fenceOpen(EngineLab::GROUP), 'not re-opened under s2: closed after the second rotation');
        $third->engine instanceof \Maatify\RateLimiter\Service\AdmissionFenceControlInterface
            ? $third->engine->openAdmissionFence(EngineLab::GROUP)
            : self::fail('the composite runtime has the fence control');
        self::assertTrue($this->runtimeOf($third)->fenceOpen(EngineLab::GROUP));
    }

    public function testDetectorAFenceReadOnTheCurrentKeyOnlyClosesAtTheFirstRotation(): void
    {
        $this->build(self::S1, null, true);
        $mutant = ServiceMutant::load('AdmissionRuntime', [
            'fenceOpen' => 'return $this->store->readBindingEpoch(self::fenceKey($this->envScope, $budgetGroup, $this->keySecret), null) >= 1;',
        ]);
        $runtime = new $mutant($this->store, new \Maatify\RateLimiter\Service\AdmissionCredentialCodec(EngineLab::BINDING_SECRET), EngineLab::budgets(), $this->clock, self::S2, EngineLab::ENV, self::S1);
        self::assertTrue(method_exists($runtime, 'fenceOpen'));

        self::assertFalse($runtime->fenceOpen(EngineLab::GROUP), 'DETECTOR: the 5-b3b read closes the fence at a rotation');
    }

    private function runtimeOf(EngineLab $lab): AdmissionRuntime
    {
        self::assertInstanceOf(AdmissionRuntime::class, $lab->runtime);

        return $lab->runtime;
    }
}
