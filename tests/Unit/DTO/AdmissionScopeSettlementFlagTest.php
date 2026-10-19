<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Unit\DTO;

use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Exception\RateLimiterException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** LT-S2 (5b2.1): the optional trailing `successKeepsSettled` field, valid on a set-size scope only, default false. */
#[Group('settle')]
final class AdmissionScopeSettlementFlagTest extends TestCase
{
    public function testTheFlagDefaultsToFalseForEveryKind(): void
    {
        foreach (AdmissionScopeKindEnum::cases() as $kind) {
            $member = $kind === AdmissionScopeKindEnum::DEVICE_MEMBER || $kind === AdmissionScopeKindEnum::SET_SIZE ? 'm' : null;
            self::assertFalse((new AdmissionScopeDTO('n', $kind, 'k', 1, 1, null, $member))->successKeepsSettled);
        }
    }

    public function testTheFlagIsAcceptedOnASetSizeScope(): void
    {
        self::assertTrue((new AdmissionScopeDTO('n', AdmissionScopeKindEnum::SET_SIZE, 'k', 1, 1, null, 'm', null, true))->successKeepsSettled);
    }

    public function testTheFlagIsRejectedOnEveryOtherKind(): void
    {
        foreach ([AdmissionScopeKindEnum::COUNTER, AdmissionScopeKindEnum::DEVICE_MEMBER, AdmissionScopeKindEnum::BUCKET] as $kind) {
            $member = $kind === AdmissionScopeKindEnum::DEVICE_MEMBER ? 'm' : null;
            try {
                new AdmissionScopeDTO('n', $kind, 'k', 1, 1, null, $member, null, true);
                self::fail('The flag must be rejected on ' . $kind->value);
            } catch (RateLimiterException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
