<?php

declare(strict_types=1);

// G5 identity-layer study (#97, #110) — harness. EVIDENCE ONLY: not package runtime, never wired into src/.
//
// Reuses read-only: the archived G4 v2 library (../../../g4/integrated_v2/lib/g4v2.php: builders, the IN assertion, G4V2Host,
// G4V2PBudget, g4v2Params, g4v2Arms) and through it G4, G3 v2, G3 and G1. Adds (PLAN.md §2, §4):
//   g5Provenance   the pinned builds: CAND / G4 (archived hashes), G5 and its four ablations (build_g5.sh hashes)
//   G5Gate         the G4V2Gate logic with the P-lane new-device member selectable (fingerprint or id material) and the
//                  D-IDMINT switch (the U-lane account budget keyed by account + id)
//   g5Replay       the G4 v2 replay with the penalty cause computed from the id-derived K5 key when the build has one
//   g5Pipe         the pipeline-level fixtures (no admission layer) used by REF, NOUA, the ablations and S0

require_once dirname(__DIR__, 3) . '/g4/integrated_v2/lib/g4v2.php';

use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EvaluationPipeline;

const G5_PIPELINE_SHA256 = [
    'G5' => '6ca8cec495cf3553ce8857cdb8fce4b0e774e912e32a201ac20e4d351f56c1b6',
    'G5-NOALW' => 'cbcd798118cc4c15161377409fb2bee95dc7b055c85c7ceb1599ef80fd79aec4',
    'G5-NOOVF' => '8b1da7781045cc518bbf87174dc61064851d7f921b56d5265c285616c317c65f',
    'G5-NOK5' => '5808e750c08ebe4ee10f15c1df584cdafc1c815643ed895db6cef32d4842cc99',
    'G5-NOAE' => 'c3522d1f7622e0535754dba15290b0feed39073b16f0678a50f69315e44f33c1',
];
const G5_RESOLVER_SHA256 = 'd0687dfbffee90b553126737e50b59c0596afa3e980f775a57a82bc67051924f';
const G5_DTO_SHA256 = '2144979163664229217fbb7cab14edee9ce216b1487fc4bbb76b5f2171060994';

function g5Build(): string
{
    $b = getenv('G5_BUILD');

    return $b === false || $b === '' ? 'G5' : $b;
}

/** Arm name of a build in the pipeline-level scenarios. */
function g5ArmOfBuild(string $build): string
{
    return match ($build) {
        'G4' => 'REF', 'G5' => 'NOUA', 'G5-NOALW' => 'D-NOALW', 'G5-NOOVF' => 'D-NOOVF', 'G5-NOK5' => 'D-NOK5', 'G5-NOAE' => 'D-NOAE',
        default => throw new \LogicException("no arm for build $build"),
    };
}

/** Aborts (exit 4) unless the loaded pipeline (and, for the G5 builds, resolver and DTO) is exactly the claimed build. */
function g5Provenance(): string
{
    $file = (string) (new \ReflectionClass(EvaluationPipeline::class))->getFileName();
    $sha = (string) hash_file('sha256', $file);
    $repo = realpath(dirname(__DIR__, 5) . '/src/Service/EvaluationPipeline.php');
    $b = g5Build();
    $want = G4_PIPELINE_SHA256[$b] ?? G5_PIPELINE_SHA256[$b] ?? null;
    $inRepo = $repo !== false && realpath($file) === $repo;
    $ok = getenv('G1_ARM') === 'CAND' && ! $inRepo && $want === $sha;
    if ($ok && isset(G5_PIPELINE_SHA256[$b])) {
        $res = (string) hash_file('sha256', (string) (new \ReflectionClass(DeviceIdentityResolver::class))->getFileName());
        $dto = (string) hash_file('sha256', (string) (new \ReflectionClass(DeviceIdentityDTO::class))->getFileName());
        $ok = $res === G5_RESOLVER_SHA256 && $dto === G5_DTO_SHA256;
    }
    if (! $ok) {
        fwrite(STDERR, "G5 provenance violation: build $b loaded $file ($sha)\n");
        exit(4);
    }

    return $b;
}

/** The DEC-021 runtime: the repository's own unpatched src/, G1_ARM unset (G1 harness provenance, sha asserted). */
function g5ProvenanceS0(): void
{
    $p = g1Provenance();
    if ($p['runtime'] !== 'S0') {
        fwrite(STDERR, "G5 provenance violation: expected the S0 runtime\n");
        exit(4);
    }
}

function g5Params(): array
{
    return ['U' => ['fc' => 4, 'net' => true, 'tbC' => 4, 'tbT' => 1800, 'refund' => true], 'uSrc' => 24];
}

/** A context with another account id (D-IDMINT: the U-lane budget keyed by account + id). */
function g5WithAccount(RateLimitContextDTO $c, string $account): RateLimitContextDTO
{
    return new RateLimitContextDTO($c->ip, $c->ua, $account, $c->clientFingerprint, $c->sessionDeviceId, $c->isSessionTrusted,
        $c->headers, $c->isDevicePreviouslyVerifiedForAccount, $c->correlationId);
}

/** The id component of the device identity, when the build produces one. */
function g5Material(DeviceIdentityDTO $d): ?string
{
    return $d->deviceIdHash ?? $d->fingerprintHash;
}

/** G4V2Gate with the P-lane new-device member selectable (PLAN.md §2.2) and the D-IDMINT switch. */
final class G5Gate
{
    public G3V2Gate $u;
    public G4V2PBudget $pb;

    public function __construct(public G1Admission $adm, array $uOpts, public string $member, public bool $idMint = false)
    {
        $this->u = new G3V2Gate($adm, $uOpts);
        $this->pb = new G4V2PBudget('refund', g4v2PCaps());
    }

    private function uCtx(RateLimitContextDTO $c): RateLimitContextDTO
    {
        return $this->idMint && $c->accountId !== null && ($c->sessionDeviceId ?? '') !== ''
            ? g5WithAccount($c, $c->accountId . '#' . $c->sessionDeviceId) : $c;
    }

    /** @return array<string, mixed> */
    public function admit(G1Cell $cell, RateLimitContextDTO $c, string $lane, ?string $jti): array
    {
        if ($lane === 'U') {
            return $this->u->admit($cell, $this->uCtx($c), 'U') + ['pkeys' => null];
        }
        $now = $cell->now();
        $keys = $this->pb->keys((string) $c->accountId, (string) $jti, $now);
        $refused = $this->pb->check($keys);
        if ($refused !== null) {
            return ['ok' => false, 'refused' => $refused, 'created' => 0, 'tb' => false, 'pkeys' => null];
        }
        $d = $cell->lab->resolver->resolve($c);
        $fp = $this->member === 'id' ? g5Material($d) : $d->fingerprintHash;
        $res = $this->adm->reserve('P', $cell->lab->ipPrefix($c->ip), $c->accountId, $jti, $fp, $now);
        if ($res['ok']) {
            $this->pb->add($keys, 1);
        }

        return $res + ['tb' => false, 'pkeys' => $res['ok'] ? $keys : null];
    }

    public function uOutcome(G1Cell $cell, RateLimitContextDTO $c, string $outcome, bool $tb): void
    {
        $this->u->outcome($cell, $this->uCtx($c), 'U', $outcome, $tb);
    }

    public function pOutcome(?array $keys, string $outcome): void
    {
        if ($keys !== null) {
            $this->pb->outcome($keys, $outcome);
        }
    }
}

/** Assembled arms REF / NOUA / D-IDMINT: FULL2 of G4 v2 (PLAN.md §2.2); NOUA-FPN is the POST-HOC arm (NOUA with the P-lane new-device member on the fingerprint). @return array{0: G1Cell, 1: G5Gate, 2: G4V2Host} */
function g5Cell(string $arm): array
{
    [, $uSrc, $u] = g4v2Arms()['FULL2'];
    $cell = new G1Cell('CAND', 'login', g4v2Params($uSrc, $u, 'refund'));
    $cell->adm ?? throw new \LogicException('CAND cell without admission');

    return [$cell, new G5Gate($cell->adm, $u, in_array($arm, ['REF', 'NOUA-FPN'], true) ? 'fp' : 'id', $arm === 'D-IDMINT'), new G4V2Host('strict', true, true)];
}

/** G1Cell::penaltyCause with the K5 key derived from the id component when the request has one. */
function g5PenaltyCause(G1Cell $cell, RateLimitContextDTO $c, string $d, string $lane, bool $idMaterial): string
{
    $now = $cell->now();
    $keys = $cell->lab->keys($c);
    if ($idMaterial && $c->accountId !== null) {
        $dev = $cell->lab->resolver->resolve($c);
        if (($dev->deviceIdHash ?? null) !== null) {
            $keys['K5'] = hash_hmac('sha256', "{$cell->lab->policy->getName()}:rate_limiter:k5:v2:prod:{$c->accountId}:{$dev->deviceIdHash}", $cell->lab->secret);
        }
    }
    if ($lane === 'P') {
        $keys['K4'] = null;
        $keys['K4P'] = $cell->k4p($c->accountId);
    }
    $hit = [];
    foreach ($keys as $name => $key) {
        $b = $key === null ? null : $cell->lab->store->checkBlock($key);
        if ($b !== null && $b->level >= 2 && $b->expiresAt > $now) {
            $hit[] = $name;
        }
    }

    return $hit !== [] ? 'pen:' . implode('+', $hit) : ($d === 'S' ? 'soft' : 'pen:unpersisted');
}

/**
 * The G4 v2 replay (archived g4v2Replay) with the IN assertion, the P-lane outcome hook, the renewal hook and the penalty cause of
 * g5PenaltyCause. The control flow and the output encoding are those of the archive, so REF reproduces it (V1).
 *
 * @return array<string, mixed>
 */
function g5Replay(G1Cell $cell, G4Gate|G5Gate $gate, G4Host|G4V2Host $host, array $rows, string $flow, bool $idMaterial): array
{
    g4v2AssertInput($rows);
    $dec = $lanes = $reach = $kinds = '';
    $byCause = [];
    $atkByCause = [];
    foreach ($rows as $r) {
        if (isset($r['event'])) {
            ($r['event'])($host, $cell->lab->t0 + (int) $r['t']);
            continue;
        }
        $cell->lab->at($r['t']);
        $c = $r['ctx'];
        $now = $cell->now();
        $jti = $c->accountId === null ? null : $host->verify($r['holder'], $r['inst'], $c->accountId, $now);
        $lane = $jti !== null ? 'P' : 'U';
        $denied = false;
        $cause = '-';
        $reached = false;
        $res = $cell->cmd($c, 'check', $lane);
        if ($res['d'] !== 'A') {
            $denied = true;
            $cause = g5PenaltyCause($cell, $c, $res['d'], $lane, $idMaterial);
        } else {
            $a = $gate->admit($cell, $c, $lane, $jti);
            if (! $a['ok']) {
                $denied = true;
                $cause = 'cap:' . $a['refused'];
            } else {
                $reached = true;
                if ($c->accountId !== null) {
                    $host->observe($r['holder'], $r['inst'], $c->accountId, $lane, $r['outcome'], $now, $jti);
                }
                if ($flow === 'pre+out') {
                    $rec = $cell->cmd($c, $r['outcome'], $lane);
                    if ($lane === 'U') {
                        $gate instanceof G5Gate ? $gate->uOutcome($cell, $c, $r['outcome'], $a['tb']) : $gate->u->outcome($cell, $c, 'U', $r['outcome'], $a['tb']);
                    } elseif ($gate instanceof G5Gate) {
                        $gate->pOutcome($a['pkeys'], $r['outcome']);
                    }
                    if ($rec['d'] !== 'A') {
                        $denied = true;
                        $cause = g5PenaltyCause($cell, $c, $rec['d'], $lane, $idMaterial);
                    }
                }
            }
        }
        $dec .= $denied ? ($cause === 'soft' || str_starts_with($cause, 'cap:') ? 'S' : 'H') : 'A';
        $lanes .= $lane;
        $reach .= $reached ? '1' : '0';
        $kinds .= $r['kind'] === 'X' ? ($r['own'] ? 'o' : 'X') : $r['kind'];
        if ($denied) {
            if ($r['kind'] !== 'X') {
                $byCause[$cause] = ($byCause[$cause] ?? 0) + 1;
            } else {
                $atkByCause[$cause] = ($atkByCause[$cause] ?? 0) + 1;
            }
        }
    }
    ksort($byCause);
    ksort($atkByCause);

    return ['dec' => $dec, 'lanes' => $lanes, 'reach' => $reach, 'kinds' => $kinds, 'legitDeniedByCause' => $byCause,
        'attackDeniedByCause' => $atkByCause, 'issued' => $host->issued, 'tainted' => $host->tainted];
}

function g5Step(G1Cell $cell, G5Gate $gate, G4V2Host $host, array $row, bool $idMaterial): array
{
    $r = g5Replay($cell, $gate, $host, [$row], 'pre+out', $idMaterial);

    return ['d' => $r['dec'], 'lane' => $r['lanes'], 'reach' => $r['reach'], 'cause' => array_key_first($r['legitDeniedByCause'] + $r['attackDeniedByCause'])];
}

// ---- pipeline-level fixtures (PLAN.md §4.2) ----------------------------------------------------------------------------

const G5_UA_A = CHROME;
const G5_UA_B = CHROME_B;

function g5Kctx(string $ua, ?string $inst, bool $known = true): RateLimitContextDTO
{
    return new RateLimitContextDTO('198.51.100.20', $ua, 'acct-x', null, $inst, false, [], $known, null);
}

/** K5 key of a request as the build derives it (the id component when the build produces one and the request carries an id). */
function g5K5Key(Lab $lab, RateLimitContextDTO $c, ?string $lane): string
{
    $d = $lab->resolver->resolve($c);

    return hash_hmac('sha256', "{$lab->policy->getName()}:rate_limiter:" . ($lane === 'P' ? 'k5p' : 'k5') . ":v2:prod:{$c->accountId}:" . g5Material($d), $lab->secret);
}

function g5K4Key(Lab $lab, RateLimitContextDTO $c, ?string $lane): ?string
{
    return $lane === 'P'
        ? hash_hmac('sha256', "{$lab->policy->getName()}:rate_limiter:k4p:v2:prod:{$c->accountId}", Lab::SECRET)
        : $lab->keys($c)['K4'];
}

function g5AllowanceKey(Lab $lab, string $acct, ?string $lane): string
{
    return hash_hmac('sha256', "{$lab->policy->getName()}:rate_limiter:aux:known_device_allowance" . ($lane === 'P' ? '_p' : '') . ":v1:prod:{$acct}", $lab->secret);
}

function g5BlockLevel(Lab $lab, ?string $key): ?int
{
    $b = $key === null ? null : $lab->store->checkBlock($key);

    return $b !== null && $b->expiresAt > $lab->clock->now()->getTimestamp() ? $b->level : null;
}

/** @return array<string, mixed> state of the K5 / K4 / allowance keys of $c after a ladder. */
function g5Snapshot(Lab $lab, RateLimitContextDTO $c, ?string $lane): array
{
    $k5 = g5K5Key($lab, $c, $lane);
    $k4 = g5K4Key($lab, $c, $lane);

    return ['k5key' => substr($k5, 0, 12), 'k5score' => $lab->store->get($k5)?->value, 'blkK5' => g5BlockLevel($lab, $k5), 'blkK4' => g5BlockLevel($lab, $k4),
        'allowKey' => substr(g5AllowanceKey($lab, (string) $c->accountId, $lane), 0, 12),
        'allowance' => $lab->store->getBudget(g5AllowanceKey($lab, (string) $c->accountId, $lane))?->count];
}

/** One attempt as a host runs it: pre-check, and the failure only if the pre-check allowed. Appends the decision letters. */
function g5Attempt(Lab $lab, RateLimitContextDTO $c, string &$dec, int &$reach): void
{
    $r = $lab->run($c, 'check');
    $dec .= $r['d'];
    if ($r['d'] !== 'A') {
        return;
    }
    $reach++;
    $r2 = $lab->run($c, 'fail');
    $dec .= $r2['d'] === 'A' ? '' : '!' . $r2['d'];
}

/** A Lab on the loaded runtime; $lane null (S0) leaves the pipeline's own default. */
function g5Lab(?string $lane): Lab
{
    $lab = new Lab('login');
    if ($lane !== null) {
        $lab->pipeline->g1SetLane($lane);
    }

    return $lab;
}

/**
 * One pipeline-level fixture of PLAN.md §4.2 on the loaded runtime.
 *
 * @return array<string, mixed>
 */
function g5Pipe(string $fixture, ?string $lane): array
{
    $inst = static fn(string $s): ?string => str_starts_with($fixture, 'NOID-') ? null : $s;
    $base = str_starts_with($fixture, 'NOID-') ? substr($fixture, 5) : $fixture;
    $lab = g5Lab($lane);
    $reach = 0;
    $dec = '';
    switch ($base) {
        case 'STABLE-600':
        case 'STABLE-300':
        case 'STABLE-60':
            $iv = (int) substr($base, 7);
            $maxK4 = 0;
            for ($t = 0; $t < 86400; $t += $iv) {
                $lab->at($t);
                $c = g5Kctx(G5_UA_A, $inst('i:kd'));
                g5Attempt($lab, $c, $dec, $reach);
                $k4 = g5K4Key($lab, $c, $lane);
                $maxK4 = max($maxK4, (int) ($k4 === null ? 0 : $lab->store->get($k4)?->value));
            }

            return ['reach' => $reach, 'maxK4score' => $maxK4];
        case 'KDL':
        case 'UAC12':
            $c1 = g5Kctx(G5_UA_A, $inst('i:kd'));
            $c2 = g5Kctx($base === 'UAC12' ? G5_UA_B : G5_UA_A, $inst('i:kd'));
            $maxK5 = $maxK4 = 0;
            for ($i = 0; $i < 12; $i++) {
                $lab->at($i * 60);
                $cur = $i >= 6 ? $c2 : $c1;
                g5Attempt($lab, $cur, $dec, $reach);
                $snap = g5Snapshot($lab, $cur, $lane);
                $maxK5 = max($maxK5, (int) $snap['blkK5']);
                $maxK4 = max($maxK4, (int) $snap['blkK4']);
            }
            $other = $lab->run(g5Kctx(G5_UA_A, $inst('i:other')), 'check')['d'];

            return ['dec' => $dec, 'reach' => $reach, 'maxK5Level' => $maxK5, 'maxK4Level' => $maxK4, 'secondDevice' => $other, 'before' => g5Snapshot($lab, $c1, $lane),
                'after' => g5Snapshot($lab, $c2, $lane), 'k5SameKey' => g5K5Key($lab, $c1, $lane) === g5K5Key($lab, $c2, $lane)];
        case 'UAX24':
            foreach (['flip' => true, 'ctl' => false] as $k => $flip) {
                $lab = g5Lab($lane);
                $reach = 0;
                $dec = '';
                for ($t = 0; $t < 86400; $t += 60) {
                    $lab->at($t);
                    g5Attempt($lab, g5Kctx($flip && $t >= 43200 ? G5_UA_B : G5_UA_A, $inst('i:kd')), $dec, $reach);
                }
                $out[$k] = $reach;
            }

            return ['flip' => $out['flip'], 'ctl' => $out['ctl']];
        case 'AE':
            $c = g5Kctx(G5_UA_A, $inst('i:kd'));
            $lab->at(0);
            for ($i = 0; $i < 4; $i++) {
                g5Attempt($lab, $c, $dec, $reach);
            }
            for ($i = 0; $i < 3; $i++) {
                $dec .= $lab->run($c, 'check')['d'];
            }
            $lab->at(300);
            $r = $lab->run($c, 'check');
            $dec .= '|' . $r['d'];
            if ($r['d'] === 'A') {
                $f = $lab->run($c, 'fail');
                $dec .= 'f' . $f['d'] . ($f['lvl'] ?? '');
            }
            $same = $lab->run($c, 'check')['d'];
            $second = $lab->run(g5Kctx(G5_UA_A, $inst('i:other')), 'check')['d'];

            return ['dec' => $dec, 'sameDevice' => $same, 'secondDevice' => $second, 'snap' => g5Snapshot($lab, $c, $lane)];
        case 'MIXPRES':
            $out = [];
            foreach (['both' => true, 'ctl' => false] as $k => $withB) {
                $lab = g5Lab($lane);
                $a = $unused = '';
                $n = 0;
                if ($withB) {
                    foreach ([0, 30, 60, 90] as $t) {
                        $lab->at($t);
                        g5Attempt($lab, g5Kctx(G5_UA_B, null), $unused, $n);
                    }
                }
                foreach ([120, 150] as $t) {
                    $lab->at($t);
                    g5Attempt($lab, g5Kctx(G5_UA_A, 'i:a'), $a, $n);
                }
                $out[$k] = $a;
            }
            $keyA = substr(g5K5Key($lab, g5Kctx(G5_UA_A, 'i:a'), $lane), 0, 12);
            $keyB = substr(g5K5Key($lab, g5Kctx(G5_UA_B, null), $lane), 0, 12);

            return ['both' => $out['both'], 'ctl' => $out['ctl'], 'k5KeyA' => $keyA, 'k5KeyB' => $keyB, 'keysDiffer' => $keyA !== $keyB,
                'aIdentical' => $out['both'] === $out['ctl']];
        case 'ROT-300':
            for ($t = 0; $t < 86400; $t += 300) {
                $lab->at($t);
                g5Attempt($lab, g5Kctx(G5_UA_A, $inst('i:r' . $t)), $dec, $reach);
            }

            return ['reach' => $reach];
        case 'UAROT-300':
            for ($t = 0; $t < 86400; $t += 300) {
                $lab->at($t);
                g5Attempt($lab, g5Kctx(intdiv($t, 300) % 2 === 1 ? G5_UA_A : G5_UA_B, $inst('i:kd')), $dec, $reach);
            }

            return ['reach' => $reach];
    }
    throw new \LogicException("unknown pipeline fixture $fixture");
}

/** The fixture list of PLAN.md §4.2 per lane (S0: a single, laneless run). */
function g5PipeFixtures(): array
{
    $f = ['STABLE-600', 'STABLE-300', 'STABLE-60', 'KDL', 'UAC12', 'UAX24', 'AE', 'MIXPRES', 'ROT-300', 'UAROT-300'];

    return array_merge($f, ['NOID-STABLE-300', 'NOID-KDL', 'NOID-UAC12', 'NOID-AE']);
}

/** True when some instance id of the rows carries two or more normalized UAs (the "UA-varying" cell of PLAN.md §5.3). */
function g5UaVarying(array $rows): bool
{
    $seen = [];
    foreach ($rows as $r) {
        if (isset($r['event']) || ($r['inst'] ?? null) === null) {
            continue;
        }
        $seen[$r['inst']][DeviceIdentityResolver::normalizeUserAgent($r['ctx']->ua)] = true;
    }
    foreach ($seen as $uas) {
        if (count($uas) > 1) {
            return true;
        }
    }

    return false;
}
