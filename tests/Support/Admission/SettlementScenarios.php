<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission;

use Maatify\RateLimiter\DTO\AdmissionPermitDTO;
use Maatify\RateLimiter\DTO\AdmissionScopeDTO;
use Maatify\RateLimiter\Enum\AdmissionOutcomeKindEnum;
use Maatify\RateLimiter\Enum\AdmissionPermitClaimEnum;
use Maatify\RateLimiter\Enum\AdmissionScopeKindEnum;
use Maatify\RateLimiter\Tests\Support\Admission\AdmissionScenarioBuilder as S;

/**
 * The settlement scenarios of 5b2.1/PLAN.md §5 (SETTLE-1 … SETTLE-4), written once over a `SettlementPort`.
 * D = 30 s and G = 600 s are the archive values (`g1/scenarios/m05_host_modes.php:55-56`); set lifetime 1200 s.
 */
final class SettlementScenarios
{
    public const N = S::NOW;

    public const D = 30;

    public const TTL = 1200;

    public static function member(string $key, string $account, int $capacity, bool $keepsSettled = false): AdmissionScopeDTO
    {
        return new AdmissionScopeDTO('fail_src', AdmissionScopeKindEnum::SET_SIZE, $key, $capacity, self::TTL, null, $account, null, $keepsSettled, settlesOnExpiry: true);
    }

    public static function permit(string $id, int $deadlineAt): AdmissionPermitDTO
    {
        return S::permit($id, $deadlineAt);
    }

    public static function reserve(SettlementPort $port, int $now, AdmissionScopeDTO $scope, ?AdmissionPermitDTO $permit = null): bool
    {
        $request = $permit === null ? S::request($scope) : S::withPermit($permit, $scope);

        return ($port->reserve)($request, $now)->admitted;
    }

    public static function outcome(SettlementPort $port, int $now, AdmissionOutcomeKindEnum $kind, AdmissionScopeDTO $scope): void
    {
        ($port->outcome)(S::outcome($kind, $scope), $now);
    }

    public static function claim(SettlementPort $port, int $now, string $permitId, AdmissionOutcomeKindEnum $kind): AdmissionPermitClaimEnum
    {
        return ($port->claim)($permitId, $kind, $now);
    }

    /**
     * SETTLE-1. Cap 2, one settled failure. A permit-bearing admission of acct-a with deadline N+30.
     * At N+29 a new account is admitted (the pending member is not counted); at N+30 a new account is refused, the member
     * itself is still admitted.
     *
     * @return array{beforeDeadline: bool, atDeadlineNew: bool, atDeadlineOwn: bool}
     */
    public static function exactBound(SettlementPort $port): array
    {
        $key = 's:exact';
        self::outcome($port, self::N, AdmissionOutcomeKindEnum::FAILURE, self::member($key, 'seed', 2));
        self::reserve($port, self::N, self::member($key, 'acct-a', 2), self::permit('permit-exact', self::N + self::D));

        return [
            'beforeDeadline' => self::reserve($port, self::N + self::D - 1, self::member($key, 'new-1', 2)),
            'atDeadlineNew' => self::reserve($port, self::N + self::D, self::member($key, 'new-2', 2)),
            'atDeadlineOwn' => self::reserve($port, self::N + self::D, self::member($key, 'acct-a', 2)),
        ];
    }

    /**
     * SETTLE-2. Cap 1.
     *
     * @return array<string, bool> each value is "a new account is admitted"
     */
    public static function outcomes(SettlementPort $port): array
    {
        $out = [];
        // A success claimed before the deadline leaves no membership; a dropped outcome of another admission does.
        $ok = 's:success';
        self::reserve($port, self::N, self::member($ok, 'acct-ok', 1), self::permit('permit-ok', self::N + self::D));
        self::claim($port, self::N + 10, 'permit-ok', AdmissionOutcomeKindEnum::SUCCESS);
        self::outcome($port, self::N + 10, AdmissionOutcomeKindEnum::SUCCESS, self::member($ok, 'acct-ok', 1));
        $out['successBeforeDeadlineThenNew'] = self::reserve($port, self::N + self::D + 1, self::member($ok, 'new-ok', 1));

        $drop = 's:drop';
        self::reserve($port, self::N, self::member($drop, 'acct-drop', 1), self::permit('permit-drop', self::N + self::D));
        $out['droppedOutcomeThenNew'] = self::reserve($port, self::N + self::D + 1, self::member($drop, 'new-drop', 1));

        // An explicit failure counts at once, as today.
        $failure = 's:failure';
        self::reserve($port, self::N, self::member($failure, 'acct-f', 1), self::permit('permit-f', self::N + self::D));
        self::outcome($port, self::N + 5, AdmissionOutcomeKindEnum::FAILURE, self::member($failure, 'acct-f', 1));
        $out['explicitFailureThenNew'] = self::reserve($port, self::N + 5, self::member($failure, 'new-f', 1));

        // The net rule (no flag): success removes a settled failure.
        $net = 's:net';
        self::outcome($port, self::N, AdmissionOutcomeKindEnum::FAILURE, self::member($net, 'acct-n', 1));
        self::outcome($port, self::N + 1, AdmissionOutcomeKindEnum::SUCCESS, self::member($net, 'acct-n', 1));
        $out['netSuccessThenNew'] = self::reserve($port, self::N + 1, self::member($net, 'new-n', 1));

        return $out;
    }

    /**
     * SETTLE-2, D8 flag: a success keeps a settled failure and cancels a pending member.
     *
     * @return array<string, bool>
     */
    public static function keepsSettledFlag(SettlementPort $port): array
    {
        $out = [];
        $kept = 's:d8-kept';
        self::outcome($port, self::N, AdmissionOutcomeKindEnum::FAILURE, self::member($kept, 'acct-d', 1, true));
        self::reserve($port, self::N + 1, self::member($kept, 'acct-d', 1, true), self::permit('permit-d8', self::N + self::D));
        self::claim($port, self::N + 10, 'permit-d8', AdmissionOutcomeKindEnum::SUCCESS);
        self::outcome($port, self::N + 10, AdmissionOutcomeKindEnum::SUCCESS, self::member($kept, 'acct-d', 1, true));
        $out['settledKeptThenNew'] = self::reserve($port, self::N + 10, self::member($kept, 'new-k', 1, true));

        $cancelled = 's:d8-cancelled';
        self::reserve($port, self::N, self::member($cancelled, 'acct-e', 1, true), self::permit('permit-d8e', self::N + self::D));
        self::claim($port, self::N + 10, 'permit-d8e', AdmissionOutcomeKindEnum::SUCCESS);
        self::outcome($port, self::N + 10, AdmissionOutcomeKindEnum::SUCCESS, self::member($cancelled, 'acct-e', 1, true));
        $out['pendingCancelledThenNew'] = self::reserve($port, self::N + self::D + 1, self::member($cancelled, 'new-c', 1, true));

        return $out;
    }

    /**
     * SETTLE-3, a success never removes a due pending member (Lead addition 2 on #148).
     *
     * @return array{dueSurvivesSuccess: bool}
     */
    public static function dueSurvivesSuccess(SettlementPort $port): array
    {
        $key = 's:due';
        self::reserve($port, self::N, self::member($key, 'acct-due', 1), self::permit('permit-due', self::N + self::D));
        self::outcome($port, self::N + self::D + 1, AdmissionOutcomeKindEnum::SUCCESS, self::member($key, 'acct-due', 1));

        return ['dueSurvivesSuccess' => self::reserve($port, self::N + self::D + 1, self::member($key, 'new-due', 1))];
    }

    /**
     * SETTLE-3, once and late. Cap 3: member b settled, member a due. Two later reservations (one with a new permit) and a
     * late claim add nothing: a new account is still admitted (size 2) until a third settled failure arrives (size 3).
     *
     * @return array<string, mixed>
     */
    public static function onceAndLate(SettlementPort $port): array
    {
        $key = 's:once';
        $t = self::N + 40;
        self::outcome($port, self::N, AdmissionOutcomeKindEnum::FAILURE, self::member($key, 'acct-b', 3));
        self::reserve($port, self::N, self::member($key, 'acct-a', 3), self::permit('permit-once-1', self::N + self::D));
        $out = [];
        $out['ownAgain'] = self::reserve($port, $t, self::member($key, 'acct-a', 3));
        $out['ownWithNewPermit'] = self::reserve($port, $t, self::member($key, 'acct-a', 3), self::permit('permit-once-2', self::N + 35));
        $out['newAdmitted'] = self::reserve($port, $t, self::member($key, 'new-1', 3));
        $out['lateClaim'] = self::claim($port, $t, 'permit-once-1', AdmissionOutcomeKindEnum::FAILURE)->value;
        $out['newAfterLate'] = self::reserve($port, $t, self::member($key, 'new-2', 3));
        self::outcome($port, $t, AdmissionOutcomeKindEnum::FAILURE, self::member($key, 'acct-x', 3));
        $out['newAtSize3'] = self::reserve($port, $t, self::member($key, 'new-3', 3));

        return $out;
    }

    /**
     * SETTLE-4. A permit-bearing request over a counter, a bucket and a set. After the deadline neither the counter nor the
     * bucket has been refunded.
     *
     * @return array{counter: bool, bucket: bool}
     */
    public static function noRefund(SettlementPort $port): array
    {
        $request = S::withPermit(
            self::permit('permit-s4', self::N + self::D),
            S::counter('src', 'c:s4', 1),
            S::bucket('acct_tb', 'b:s4', 1, 1800),
            self::member('s:s4', 'acct-s4', 5),
        );
        ($port->reserve)($request, self::N);
        $later = self::N + self::D + 1;

        return [
            'counter' => ($port->reserve)(S::request(S::counter('src', 'c:s4', 1)), $later)->admitted,
            'bucket' => ($port->reserve)(S::request(S::bucket('acct_tb', 'b:s4', 1, 1800)), $later)->admitted,
        ];
    }
}
