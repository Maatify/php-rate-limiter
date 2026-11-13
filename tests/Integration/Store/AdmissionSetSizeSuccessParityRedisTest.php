<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\DTO\AdmissionOutcomeDTO;
use Maatify\RateLimiter\DTO\AdmissionPermitDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionPermitClaimEnum;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionInMemoryReferenceStore;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionScenarioBuilder as S;
use Maatify\RateLimiter\Tests\Support\Admission\MergedAdmissionOperationsSliding;
use Maatify\RateLimiter\Tests\Support\Admission\MergedOperationsStore;
use Maatify\RateLimiter\Tests\Support\Admission\PreSettlementReferenceStoreSliding;
use Maatify\RateLimiter\Tests\Support\Clock\FixedClock;
use PHPUnit\Framework\Attributes\DataProvider;

final class AdmissionSetSizeSuccessParityRedisTest extends AdmissionRedisGateTestCase
{
    #[DataProvider('lanesAndNames')]
    public function testSuccessRemovesTheSuppliedMemberAndPreservesTheOtherMember(string $lane, string $scopeName): void
    {
        $slidingOps = new MergedAdmissionOperationsSliding($this->executor, self::NAMESPACE . ':reference');
        $key = 's:success-parity';
        $subject = 'account-to-remove';
        $other = 'account-to-keep';
        $now = S::NOW;
        $removedScope = S::setSize($scopeName, $key, 1, $subject);
        $retainedScope = S::setSize($scopeName, $key, 1, $other);
        $clock = new FixedClock('@' . $now);
        $stores = [
            'atomic Redis' => $this->store,
            'sliding Redis reference' => new MergedOperationsStore($slidingOps),
            'in-memory reference' => new AdmissionInMemoryReferenceStore($clock),
            'pre-settlement sliding reference' => new PreSettlementReferenceStoreSliding($clock),
        ];

        foreach ([true, false] as $atomic) {
            foreach ($stores as $label => $store) {
                $store->applyAdmissionOutcome(S::outcome(AdmissionOutcomeKindEnum::FAILURE, $removedScope), $now);
                $store->applyAdmissionOutcome(S::outcome(AdmissionOutcomeKindEnum::FAILURE, $retainedScope), $now);
                self::assertTrue($store->reserveAdmission(S::request($removedScope), $now)->admitted, $label);
                self::assertTrue($store->reserveAdmission(S::request($retainedScope), $now)->admitted, $label);

                $redisKey = match ($label) {
                    'atomic Redis' => $this->key(AdmissionScopeKindEnum::SET_SIZE, $key),
                    'sliding Redis reference' => $slidingOps->scopeKey(S::CLASS_NAME, AdmissionScopeKindEnum::SET_SIZE, $key),
                    default => null,
                };
                $expiry = $redisKey === null ? null : $this->raw('PEXPIRETIME', $redisKey);

                if ($atomic) {
                    $permit = new AdmissionPermitDTO('set-size-success', $lane, $subject, 'login', $now + 30, 600);
                    $request = S::withPermit($permit, S::counter('capacity', 'c:success-parity', 10));
                    self::assertTrue($store->reserveAdmission($request, $now)->admitted, $label);
                    $outcome = new AdmissionOutcomeDTO(S::CLASS_NAME, AdmissionOutcomeKindEnum::SUCCESS, [$removedScope], $permit->id);
                    $claim = $store->claimAdmissionPermitWithOutcome($lane, $subject, 'login', $outcome, $now + 1);
                    self::assertSame(AdmissionPermitClaimEnum::ACCEPTED, $claim, $label);
                } else {
                    $store->applyAdmissionOutcome(S::outcome(AdmissionOutcomeKindEnum::SUCCESS, $removedScope), $now + 1);
                }

                self::assertFalse($store->reserveAdmission(S::request($removedScope), $now + 1)->admitted, $label);
                self::assertTrue($store->reserveAdmission(S::request($retainedScope), $now + 1)->admitted, $label);

                if ($redisKey !== null) {
                    self::assertSame($expiry, $this->raw('PEXPIRETIME', $redisKey), $label);
                    self::assertSame([$other, (string) ($now + $retainedScope->ttlSeconds)], $this->raw('ZRANGE', $redisKey, '0', '-1', 'WITHSCORES'), $label);
                }
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function lanesAndNames(): iterable
    {
        yield 'U source failure' => ['U', 'source_failure_cap'];
        yield 'U proof source failure' => ['U', 'source_proof_failure_cap'];
        yield 'P source failure' => ['P', 'source_failure_cap'];
        yield 'P proof source failure' => ['P', 'source_proof_failure_cap'];
    }
}
