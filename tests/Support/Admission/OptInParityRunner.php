<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\DTO\AdmissionPermitDTO;
use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;

/**
 * OFF-PARITY runner (5b2.2/PLAN.md §4): a deterministic, seeded sequence of admission operations over a small key space
 * (counters, a device set, failure sets with and without outcomes, a bucket, permits with deadlines, claims, malformed state)
 * executed step by step against ONE operations object. For every step it records the result and the full key dump, so the
 * log of the operations at d6aea4d and the log of the production operations can be compared line by line.
 *
 * Set scopes are built WITHOUT `settlesOnExpiry` (the default); `successKeepsSettled` is mixed in at random because at the
 * default it must be inert.
 */
final class OptInParityRunner
{
    private const CLASS_NAME = 'login';

    /**
     * @param object $ops an object with reserve(), applyOutcome(), claimPermit() and scopeKey()
     * @return list<array{step: int, record: string, dump: array<string, array{type: string, value: mixed, hasTtl: bool}>}>
     */
    public static function run(object $ops, RedisCommandExecutorInterface $redis, int $seed, int $steps): array
    {
        mt_srand($seed);
        $now = 1767268800;
        $permits = [];
        $log = [];
        for ($i = 0; $i < $steps; $i++) {
            $now += mt_rand(0, 400);
            $op = mt_rand(0, 9);
            // NM-6 style malformed state: a wrong-type failure set for a while, then removed (both runs see the same).
            $malformed = $ops->scopeKey(self::CLASS_NAME, AdmissionScopeKindEnum::SET_SIZE, 's:parity:2');
            if ($i === 120) {
                $redis->execute(['SET', $malformed, 'junk']);
            }
            if ($i === 140) {
                $redis->execute(['DEL', $malformed]);
            }
            try {
                if ($op <= 4) {
                    $scopes = self::scopes(mt_rand(1, 3));
                    $permit = null;
                    if (mt_rand(0, 2) > 0) {
                        $id = 'permit-' . $i;
                        $permit = new AdmissionPermitDTO($id, 'U', 'acctT', 'login', $now + mt_rand(0, 60), 600);
                        $permits[] = $id;
                    }
                    $result = $ops->reserve(new AdmissionReservationRequestDTO(self::CLASS_NAME, $scopes, $permit), $now);
                    $record = 'reserve:' . json_encode([$result->admitted, $result->refusedScope, $result->retryAfterSeconds]);
                } elseif ($op <= 7) {
                    $kind = mt_rand(0, 1) === 0 ? AdmissionOutcomeKindEnum::FAILURE : AdmissionOutcomeKindEnum::SUCCESS;
                    $ops->applyOutcome(new AdmissionOutcomeDTO(self::CLASS_NAME, $kind, self::scopes(mt_rand(1, 3))), $now);
                    $record = 'outcome:' . $kind->value;
                } elseif ($permits !== []) {
                    $id = $permits[mt_rand(0, count($permits) - 1)];
                    $kind = mt_rand(0, 1) === 0 ? AdmissionOutcomeKindEnum::FAILURE : AdmissionOutcomeKindEnum::SUCCESS;
                    $record = 'claim:' . $ops->claimPermit($id, 'U', 'acctT', 'login', $kind, $now)->value;
                } else {
                    $record = 'noop';
                }
            } catch (\Throwable $e) {
                $record = 'throw:' . $e::class;
            }
            $log[] = ['step' => $i, 'record' => $record, 'dump' => AdmissionRedisDump::take($redis)];
        }

        return $log;
    }

    /** @return list<AdmissionScopeDTO> */
    private static function scopes(int $n): array
    {
        $out = [];
        $used = [];
        while (count($out) < $n) {
            $pick = mt_rand(0, 5);
            if (isset($used[$pick])) {
                continue;
            }
            $used[$pick] = true;
            $out[] = match ($pick) {
                0 => new AdmissionScopeDTO('src', AdmissionScopeKindEnum::COUNTER, 'c:parity:1', mt_rand(1, 4), 1200),
                1 => new AdmissionScopeDTO('pacct', AdmissionScopeKindEnum::COUNTER, 'c:parity:2', mt_rand(1, 4), 2400, 'c:parity:2-prev'),
                2 => new AdmissionScopeDTO('np', AdmissionScopeKindEnum::DEVICE_MEMBER, 'd:parity', 3, 86400, null, 'dev-' . mt_rand(0, 4)),
                3 => new AdmissionScopeDTO('fail_src', AdmissionScopeKindEnum::SET_SIZE, 's:parity:1', mt_rand(1, 3), 1200, null, 'acct-' . mt_rand(0, 4), null, mt_rand(0, 1) === 1),
                4 => new AdmissionScopeDTO('d8', AdmissionScopeKindEnum::SET_SIZE, 's:parity:2', mt_rand(1, 3), 1200, null, 'acct-' . mt_rand(0, 4), null, mt_rand(0, 1) === 1),
                default => new AdmissionScopeDTO('acct_tb', AdmissionScopeKindEnum::BUCKET, 'b:parity', 2, 300),
            };
        }

        return $out;
    }
}
