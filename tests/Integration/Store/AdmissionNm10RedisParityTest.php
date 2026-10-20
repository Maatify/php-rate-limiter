<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Repository\Redis\RedisFullCapabilityStore;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\ArchiveData;
use Maatify\RateLimiter\Tests\Support\Admission\Archive\StreamReplay;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;
use Maatify\RateLimiter\Tests\Support\Admission\MergedAdmissionOperations;
use Maatify\RateLimiter\Tests\Support\Admission\MergedOperationsStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * NM-10 (a) on real Redis (5b3b/PLAN.md §11.3, re-registered 2026-10-20): at every registered drop share, the arm with the permit
 * (the production engine over `RedisFullCapabilityStore`, the store at its default) and the arm without it (the same engine over
 * `MergedAdmissionOperations`, the operations of d6aea4d verbatim) replay every archived stream identically, row by row; both
 * equal the in-memory replay over the reference store (the reference store in lockstep with Redis).
 */
#[Group('nm10')]
#[Group('archive')]
final class AdmissionNm10RedisParityTest extends AdmissionRedisGateTestCase
{
    public static function setUpBeforeClass(): void
    {
        ini_set('memory_limit', '1G');
    }

    /** @return iterable<string, array{float}> */
    public static function dropShares(): iterable
    {
        foreach ([0.10, 0.25, 0.50, 1.00] as $share) {
            yield 'share ' . $share => [$share];
        }
    }

    #[DataProvider('dropShares')]
    public function testTheTwoArmsOnRedisAndTheReferenceStoreGiveTheSameRows(float $share): void
    {
        $armDiff = [];
        $referenceDiff = [];
        $tuples = 0;
        foreach (ArchiveData::get()->streams() as $s) {
            $this->resetRedis();
            $with = StreamReplay::run($s['rows'], 'pre+out', new EngineLab(StreamReplay::fullBudgets(), admissionInner: new RedisFullCapabilityStore($this->executor, self::NAMESPACE)), $share);
            $this->resetRedis();
            $without = StreamReplay::run($s['rows'], 'pre+out', new EngineLab(StreamReplay::fullBudgets(), admissionInner: new MergedOperationsStore(new MergedAdmissionOperations($this->executor, self::NAMESPACE))), $share);
            $reference = StreamReplay::run($s['rows'], 'pre+out', new EngineLab(StreamReplay::fullBudgets()), $share);
            $key = $s['stream'] . '|' . $s['strategy'] . '|' . $s['ids'];
            if ($with !== $without) {
                $armDiff[] = $key;
            }
            if ($with !== $reference) {
                $referenceDiff[] = $key;
            }
            $tuples++;
        }

        self::assertSame(91, $tuples);
        self::assertSame([], $armDiff, "rows that differ between the arm with the permit and MergedAdmissionOperations at share $share");
        self::assertSame([], $referenceDiff, "rows that differ between Redis and the reference store at share $share");
    }
}
