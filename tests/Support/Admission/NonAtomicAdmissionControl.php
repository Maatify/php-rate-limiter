<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Repository\Redis\RedisAdmissionOperations;
use Maatify\RateLimiter\Repository\Redis\RedisCommandExecutorInterface;

/**
 * DETECTOR FIXTURE (NM-6 R-1 / R-2, NM-7, PERMIT): deliberately NON-ATOMIC controls. A reservation reads in one round trip and
 * writes in another (the study's `reserveNonAtomic` control, extended to the device member); a claim reads the state and
 * writes it afterwards. They exist only to prove that the race gates can detect over-admission. Never production code.
 */
final class NonAtomicAdmissionControl
{
    public function __construct(
        private readonly RedisCommandExecutorInterface $redis,
        private readonly RedisAdmissionOperations $keys,
    ) {
    }

    /** @return bool admitted */
    public function reserve(AdmissionReservationRequestDTO $request, int $now): bool
    {
        foreach ($request->scopes as $scope) {
            $key = $this->keys->scopeKey($request->verifierClass, $scope->kind, $scope->key);
            if ($scope->kind === AdmissionScopeKindEnum::COUNTER) {
                if ((int) ($this->redis->execute(['GET', $key]) ?? 0) >= $scope->capacity) {
                    return false;
                }
            } elseif ($scope->kind === AdmissionScopeKindEnum::DEVICE_MEMBER) {
                $live = $this->redis->execute(['ZCOUNT', $key, '(' . $now, '+inf']);
                if ((is_int($live) ? $live : 0) >= $scope->capacity) {
                    return false;
                }
            }
        }
        usleep(2000);
        foreach ($request->scopes as $scope) {
            $key = $this->keys->scopeKey($request->verifierClass, $scope->kind, $scope->key);
            if ($scope->kind === AdmissionScopeKindEnum::COUNTER) {
                if ((int) $this->redis->execute(['INCR', $key]) === 1) {
                    $this->redis->execute(['EXPIRE', $key, $scope->ttlSeconds]);
                }
            } elseif ($scope->kind === AdmissionScopeKindEnum::DEVICE_MEMBER) {
                $this->redis->execute(['ZADD', $key, $now + $scope->ttlSeconds, (string) $scope->member]);
                $this->redis->execute(['EXPIRE', $key, $scope->ttlSeconds]);
            }
        }

        return true;
    }

    /** @return bool accepted */
    public function claim(string $permitId): bool
    {
        $key = $this->keys->permitKey($permitId);
        if ($this->redis->execute(['HGET', $key, 'state']) !== 'ISSUED') {
            return false;
        }
        usleep(2000);
        $this->redis->execute(['HSET', $key, 'state', 'CLAIMED', 'outcome', 'failure']);

        return true;
    }
}
