<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\Admission\Archive;

use Maatify\RateLimiter\DTO\AdmissionCredentialDTO;
use Maatify\RateLimiter\Tests\Support\Admission\EngineLab;

/**
 * @phpstan-import-type Effect from ArchiveData
 * @phpstan-type Token array{account: string, instance: string, iat: int, exp: int, epoch: int, jti: string, ttl: int, bindAt: int}
 *
 * The Host model of the study (`G4V2Host`, g4v2.php) on the production codec and the engine's epoch: tokens in a jar, taint,
 * sliding renewal and the password-change binding. It is the Host's side only: whether a presented credential puts a request on
 * the proven door is decided by the engine (the library), not here. The Host checks its own expiry and epoch before it presents
 * a credential, exactly as the study's `verify()` did.
 */
final class StudyHost
{
    /** @var array<string, array<string, Token>> holder|inst => account => token */
    public array $jar = [];

    /** @var array<string, array<string, true>> instance@window => accounts that failed */
    public array $fails = [];

    public int $issued = 0;
    public int $tainted = 0;
    public int $renewed = 0;
    public int $pwBinds = 0;
    private int $seq = 0;

    /**
     * @param array{tokenTtl: int, taint: int, maxLife: int} $constants
     * @param bool $binding false is the study's Host mode `off` (`R-NO-BIND`, g4/integrated/lib/g4.php:58): no credential is ever
     *                      presented and nothing is bound
     */
    public function __construct(
        private readonly EngineLab $lab,
        private readonly array $constants,
        public bool $renew = true,
        public bool $passwordBinding = true,
        public bool $binding = true,
    ) {}

    public function issue(string $holder, string $acct, string $inst, int $now, ?int $ttl = null, ?int $bindAt = null, ?string $jti = null): void
    {
        $ttl ??= $this->constants['tokenTtl'];
        $bindAt ??= $now;
        $epoch = $this->lab->engine->bindingEpoch($acct);
        $token = [
            'account' => $acct, 'instance' => $inst, 'iat' => $now, 'exp' => min($now + $ttl, $bindAt + $this->constants['maxLife']),
            'epoch' => $epoch, 'jti' => $jti ?? 'jti-' . (++$this->seq), 'ttl' => $ttl, 'bindAt' => $bindAt,
        ];
        $this->jar[$holder . '|' . $inst][$acct] = $token;
        $jti === null ? $this->issued++ : $this->renewed++;
    }

    public function revoke(string $acct, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->lab->engine->revokeAccountBinding($acct);
        }
    }

    /** `copyJar()` and FORGE's jar copy (g4v2.php:166-169; g5v2_fixtures.php:168-170): the holder's tokens become another's. */
    public function copy(string $fromJarKey, string $toJarKey): void
    {
        $this->jar[$toJarKey] = $this->jar[$fromJarKey] ?? [];
    }

    /** `passwordChange()` (g4v2.php:177-185): an epoch bump and, with the binding on, a new chain for the changing device only. */
    public function passwordChange(string $holder, string $acct, string $inst, int $now): void
    {
        $this->revoke($acct);
        if ($this->passwordBinding && $this->binding) {
            $this->issue($holder, $acct, $inst, $now);
            $this->pwBinds++;
        }
    }

    /**
     * Apply the observable effects of one archived event (see archive-dump.php).
     *
     * @param list<Effect> $effects
     */
    public function apply(array $effects, int $now): void
    {
        foreach ($effects as $effect) {
            if (isset($effect['issue'])) {
                $i = $effect['issue'];
                $this->issue($i['holder'], $i['acct'], $i['inst'], $now, $i['ttl'], $now + $i['bindOffset']);
            }
            if (isset($effect['revoke'])) {
                $this->revoke($effect['revoke']['acct'], $effect['revoke']['times']);
            }
            if (isset($effect['copy'])) {
                $this->copy($effect['copy']['from'], $effect['copy']['to']);
            }
            if (isset($effect['password'])) {
                $this->passwordChange($effect['password']['holder'], $effect['password']['acct'], $effect['password']['inst'], $now);
            }
        }
    }

    /**
     * The token the Host may present for this request, else null (expired, revoked, none).
     *
     * @return Token|null
     */
    public function token(string $holder, ?string $inst, string $acct, int $now): ?array
    {
        if ($inst === null || ! $this->binding) {
            return null;
        }
        $t = $this->jar[$holder . '|' . $inst][$acct] ?? null;
        if ($t === null || $now >= $t['exp'] || $t['epoch'] !== $this->lab->engine->bindingEpoch($t['account'])) {
            return null;
        }

        return $t;
    }

    /** @param Token $token */
    public function credential(array $token): string
    {
        return $this->lab->codec->encode(new AdmissionCredentialDTO(
            $token['account'],
            [EngineLab::GROUP],
            $token['instance'],
            $token['jti'],
            $token['exp'],
            $token['bindAt'],
            $token['epoch'],
        ));
    }

    /**
     * The Host's own view of a verifier result: failures feed taint; a U success binds; a P success renews.
     *
     * @param Token|null $token
     */
    public function observe(string $holder, string $inst, string $acct, bool $proven, string $outcome, int $now, ?array $token): void
    {
        if (! $this->binding) {
            return;
        }
        $w = $inst . '@' . intdiv($now, 600);
        if ($outcome === 'fail') {
            $this->fails[$w][$acct] = true;

            return;
        }
        if ($proven) {
            if ($this->renew && $token !== null) {
                $this->issue($holder, $token['account'], $inst, $now, $token['ttl'], $token['bindAt'], $token['jti']);
            }

            return;
        }
        if (count(array_diff(array_keys($this->fails[$w] ?? []), [$acct])) >= $this->constants['taint']) {
            $this->tainted++;

            return;
        }
        $this->issue($holder, $acct, $inst, $now);
    }
}
