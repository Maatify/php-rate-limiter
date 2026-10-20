<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission\Archive;

use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\DTO\RateLimitResultDTO;
use Maatify\RateLimiter\Exception\RateLimiterException;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;

/**
 * @phpstan-import-type Step from ReplaySession
 *
 * NM-10 (b): the six cases of LT-16 for one admitted attempt, sent right after it (5b3b/PLAN.md §11.3): the outcome with no permit
 * (`permit_missing`, a `RateLimiterException`), a token with a broken MAC (`unknown`), the permit under another account's context
 * (`rejected`), and either, for a dropped outcome, the outcome after the deadline and before `deadline + G` (`late`), or, for a sent
 * one, the same outcome again (`duplicate`) and the opposite outcome (`conflict`). Records which calls returned their registered
 * result and which did not.
 */
final class Lt16Cases
{
    /** @var array<string, int> case => calls that returned the registered result */
    public array $seen = [];

    /** @var list<string> calls that did not */
    public array $wrong = [];

    /** @return \Closure(Step, ReplaySession): void */
    public function observer(): \Closure
    {
        return $this->send(...);
    }

    /** @param Step $step */
    private function send(array $step, ReplaySession $s): void
    {
        $lab = $s->lab;
        $ctx = $step['context'];
        $permit = $step['permit'];
        if ($permit === null) {
            $this->wrong[] = 'an admission of the opted policy without a permit';

            return;
        }
        $ok = $step['outcome'] === 'ok';
        try {
            $lab->outcome($ctx, $ok, null);
            $this->wrong[] = 'missing was accepted';
        } catch (RateLimiterException) {
            $this->seen['missing'] = ($this->seen['missing'] ?? 0) + 1;
        }
        $tampered = substr($permit, 0, -1) . (str_ends_with($permit, 'A') ? 'B' : 'A');
        $this->expect('unknown', $lab->outcome($ctx, $ok, $tampered));
        $other = new RateLimitContextDTO(
            $ctx->ip,
            $ctx->ua,
            'acct-not-the-subject',
            $ctx->clientFingerprint,
            $ctx->sessionDeviceId,
            $ctx->isSessionTrusted,
            $ctx->headers,
            $ctx->isDevicePreviouslyVerifiedForAccount,
            null,
            $ctx->clientInstanceId,
            null,
        );
        $this->expect('rejected', $lab->outcome($other, $ok, $permit));
        if ($step['dropped']) {
            $t = $lab->now() - EngineLab::T0;
            $lab->at($t + 31);
            $this->expect('late', $lab->outcome($ctx, $ok, $permit));
            $lab->at($t);

            return;
        }
        $this->expect('duplicate', $lab->outcome($ctx, $ok, $permit));
        $this->expect('conflict', $lab->outcome($ctx, ! $ok, $permit));
    }

    private function expect(string $case, RateLimitResultDTO $r): void
    {
        $cause = EngineLab::cause($r);
        if ($r->decision === RateLimitResultDTO::DECISION_ALLOW && $cause === 'permit_' . $case && EngineLab::scope($r) === 'permit') {
            $this->seen[$case] = ($this->seen[$case] ?? 0) + 1;

            return;
        }
        $this->wrong[] = $case . ' gave ' . $r->decision . '/' . ($cause ?? '-');
    }
}
