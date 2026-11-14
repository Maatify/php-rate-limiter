<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Integration\Store;

use Maatify\RateLimiter\Command\RateLimitCommand;
use Maatify\RateLimiter\DTO\AdmissionBudgetsDTO;
use Maatify\RateLimiter\DTO\AdmissionSharedConfigDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionPermitClaimEnum;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Exception\AdmissionConfigurationException;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Service\AdmissionFenceControlInterface;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionScenarioBuilder as S;
use Maatify\RateLimiter\Tests\Support\Admission\PublicRedisAdmissionFixture;
use Maatify\RateLimiter\Tests\Support\Admission\ReplyValue;
use PHPUnit\Framework\Attributes\Group;

/**
 * Admission reservation, settlement and value boundaries against a real Redis, with the same expectations on 32-bit and 64-bit
 * PHP (#213). The store-level cases use a realistic Unix timestamp (it fits a 32-bit `int` until 2038); the public-runtime case
 * pins the clock like every other public Redis gate.
 */
#[Group('integration')]
#[Group('php32')]
final class AdmissionIntegerWidthRedisTest extends AdmissionRedisGateTestCase
{
    private const MAX_DECIMAL = '9007199254740991';

    public function testTheAccountBucketIsReservedAndRefundedOnceAcrossTheSettlement(): void
    {
        $now = S::NOW;
        $bucket = S::bucket('account_bucket', 'acct-width', 4, 1800);
        $key = $this->key(AdmissionScopeKindEnum::BUCKET, 'acct-width');

        // Reserve: a bucket of 4 takes of 1800 s holds 7200 credit-seconds and one take costs 1800.
        self::assertTrue($this->store->reserveAdmission(S::withPermit(S::permit('p-width', $now + 30), $bucket), $now)->admitted);
        self::assertSame('5400', ReplyValue::text($this->raw('HGET', $key, 'credit')));

        // A failure outcome refunds nothing: the take stays spent and a replay is a duplicate.
        $failure = new \Maatify\RateLimiter\DTO\AdmissionOutcomeDTO(S::CLASS_NAME, AdmissionOutcomeKindEnum::FAILURE, [$bucket], 'p-width');
        self::assertSame(AdmissionPermitClaimEnum::ACCEPTED, $this->store->claimAdmissionPermitWithOutcome('U', 'acctT', 'login', $failure, $now + 1));
        self::assertSame('5400', ReplyValue::text($this->raw('HGET', $key, 'credit')));
        self::assertSame(AdmissionPermitClaimEnum::DUPLICATE, $this->store->claimAdmissionPermitWithOutcome('U', 'acctT', 'login', $failure, $now + 2));

        // A success outcome of a second permit refunds exactly one take, once.
        self::assertTrue($this->store->reserveAdmission(S::withPermit(S::permit('p-width-2', $now + 40), $bucket), $now + 10)->admitted);
        $success = new \Maatify\RateLimiter\DTO\AdmissionOutcomeDTO(S::CLASS_NAME, AdmissionOutcomeKindEnum::SUCCESS, [$bucket], 'p-width-2');
        self::assertSame(AdmissionPermitClaimEnum::ACCEPTED, $this->store->claimAdmissionPermitWithOutcome('U', 'acctT', 'login', $success, $now + 11));
        // Credit 5400 refilled by 10 s, one take (1800) spent at now + 10 = 3610, refilled by 1 s and refunded one take (1800) = 5411.
        self::assertSame('5411', ReplyValue::text($this->raw('HGET', $key, 'credit')));
        $creditAfter = $this->raw('HGET', $key, 'credit');
        self::assertSame(AdmissionPermitClaimEnum::DUPLICATE, $this->store->claimAdmissionPermitWithOutcome('U', 'acctT', 'login', $success, $now + 12));
        self::assertSame($creditAfter, $this->raw('HGET', $key, 'credit'), 'A repeated outcome refunds nothing more.');
    }

    public function testTheBucketBoundaryIsExactAgainstTheLuaRange(): void
    {
        $now = S::NOW;
        // (2^22 - 1) takes of (2^31 - 1) seconds: 2^53 - 2^22 - 2^31 + 1 credit-seconds, inside Lua's exact range.
        self::assertTrue($this->store->reserveAdmission(S::request(S::bucket('account_bucket', 'fit', 4194303, 2147483647)), $now)->admitted);
        self::assertSame(1, ReplyValue::integer($this->raw('EXISTS', $this->key(AdmissionScopeKindEnum::BUCKET, 'fit'))));

        // One take more is 2^53 - 2^22 + 2^31 - 1: outside, rejected as a typed input failure with nothing written.
        $keysBefore = ReplyValue::integer($this->raw('DBSIZE'));
        try {
            $this->store->reserveAdmission(S::request(S::bucket('account_bucket', 'over', 4194305, 2147483647)), $now);
            self::fail('The over-range bucket must be rejected.');
        } catch (RateLimiterException $exception) {
            self::assertSame('Admission bucket capacity times its take cost is not representable.', $exception->getMessage());
        }
        self::assertSame($keysBefore, ReplyValue::integer($this->raw('DBSIZE')), 'Nothing is written.');
        self::assertSame(0, ReplyValue::integer($this->raw('EXISTS', $this->key(AdmissionScopeKindEnum::BUCKET, 'over'))));
    }

    public function testTheSharedConfigurationHoldsTheDecimalMaximumOnEveryWidth(): void
    {
        $now = S::NOW;
        $config = new AdmissionSharedConfigDTO('cfg-width', null, self::MAX_DECIMAL);
        $request = new \Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO(S::CLASS_NAME, [S::counter('source_capacity', 'cfg-src', 5, 1200, null, 'm1')], null, $config);
        self::assertTrue($this->store->reserveAdmission($request, $now)->admitted);
        $cfgKey = $this->keys->configKey(S::CLASS_NAME, 'cfg-width');
        self::assertSame(self::MAX_DECIMAL, ReplyValue::text($this->raw('GET', $cfgKey)));

        $other = new \Maatify\RateLimiter\DTO\AdmissionReservationRequestDTO(S::CLASS_NAME, [S::counter('source_capacity', 'cfg-src', 5, 1200, null, 'm2')], null, new AdmissionSharedConfigDTO('cfg-width', null, '9007199254740990'));
        $this->expectException(AdmissionConfigurationException::class);
        $this->store->reserveAdmission($other, $now);
    }

    public function testThePublicRuntimeRegistersTheHighestNativeBuilderLimit(): void
    {
        $limit = AdmissionBudgetsDTO::nativePendingPermitLimitMaximum();
        $runtime = PublicRedisAdmissionFixture::build($this->executor, self::NAMESPACE, 1000000, pendingLimit: $limit);
        self::assertInstanceOf(AdmissionFenceControlInterface::class, $runtime);
        $runtime->openAdmissionFence('login');
        $context = new RateLimitContextDTO(ip: '192.0.2.10', ua: 'width-gate', accountId: 'width-account');

        $check = $runtime->limit($context, RateLimitCommand::checkOnly('login_protection'));

        self::assertTrue($check->isAllowed());
        $registered = $this->raw('KEYS', substr($this->keys->configKey(S::CLASS_NAME, 'unused'), 0, -64) . '*');
        self::assertIsArray($registered);
        self::assertCount(1, $registered);
        $stored = $this->raw('GET', ReplyValue::text(array_values($registered)[0]));
        self::assertSame((string) $limit, ReplyValue::text($stored));
        self::assertSame(PHP_INT_SIZE >= 8 ? self::MAX_DECIMAL : (string) PHP_INT_MAX, (string) $limit);

        // A second worker with the same limit is admitted; one with another limit is refused with the typed configuration exception, before any reservation.
        $same = PublicRedisAdmissionFixture::build($this->executor, self::NAMESPACE, 1000000, pendingLimit: $limit);
        self::assertTrue($same->limit(new RateLimitContextDTO(ip: '192.0.2.11', ua: 'width-gate', accountId: 'width-account-2'), RateLimitCommand::checkOnly('login_protection'))->isAllowed());
        $different = PublicRedisAdmissionFixture::build($this->executor, self::NAMESPACE, 1000000, pendingLimit: 5);
        $this->expectException(AdmissionConfigurationException::class);
        $different->limit(new RateLimitContextDTO(ip: '192.0.2.12', ua: 'width-gate', accountId: 'width-account-3'), RateLimitCommand::checkOnly('login_protection'));
    }
}
