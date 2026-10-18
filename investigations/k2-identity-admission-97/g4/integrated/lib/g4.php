<?php

declare(strict_types=1);

// G4 integrated assembly (#97, #110) — harness. EVIDENCE ONLY: not package runtime, never wired into src/.
//
// Reuses read-only: the G1 cell engine and admission layer, the G3 library and the G3 v2 gate (G3V2Gate). Adds (PLAN.md §1):
//   G4Host     the Host model: binding-token issuance after a verified success on an untainted instance, a per-holder token jar,
//              per-account revocation epochs, and the boundary check (strict, or WEAKBIND without the account check);
//   G4Gate     U lane through G3V2Gate (U_src, net failure cap, bucket); P lane through G1Admission with the token id as proof ref;
//   g4Replay   the G1 / G3 replay with lane = P iff the boundary accepts the presented token.
// Row fields beyond G1: 'inst' (instance id or null, carried in sessionDeviceId, untrusted) and 'holder' (whose token jar).

require_once dirname(__DIR__, 3) . '/g3/admission_budgets_v2/lib/g3v2.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Service\EvaluationPipeline;

const G4_PIPELINE_SHA256 = [
    'CAND' => '1840d6e2b57cd57674beb7101d25d458e97949b719d473580db28f50ac13dfd9',
    'NOESC' => '2926e1c5d6ce058e62bd06a9aec9fe86e532b8870d9af54a3fe1f42ec55e1db4',
    'G4' => '45d201d0ab9d107eb5299ad8f17fcd5d6c8bdc3120bae24df9e6e3e0fd0863fd',
    'C5' => '3111c315cbdc398a870ea396138c09d4b28e68127c9c955fd8a657e214ea6f79',
];
const G4_TOKEN_TTL = 30 * 86400;
const G4_TAINT = 3;

function g4Build(): string
{
    $b = getenv('G4_BUILD');

    return $b === false || $b === '' ? 'G4' : $b;
}

/** Aborts (exit 4) unless the loaded pipeline is exactly the claimed build and not the repository's own src/. */
function g4Provenance(): string
{
    $file = (string) (new \ReflectionClass(EvaluationPipeline::class))->getFileName();
    $sha = (string) hash_file('sha256', $file);
    $repo = realpath(dirname(__DIR__, 5) . '/src/Service/EvaluationPipeline.php');
    $b = g4Build();
    if (getenv('G1_ARM') !== 'CAND' || realpath($file) === $repo || ($G4 = G4_PIPELINE_SHA256[$b] ?? null) !== $sha) {
        fwrite(STDERR, "G4 provenance violation: build $b loaded $file ($sha)\n");
        exit(4);
    }

    return $b;
}

/** Arms (PLAN.md §1.2): build, U_src, U gate options, binding mode. */
function g4Arms(): array
{
    $u = ['fc' => 4, 'net' => true, 'tbC' => 4, 'tbT' => 1800, 'refund' => true];

    return [
        'FULL' => ['G4', 24, $u, 'strict'],
        'FULL-40' => ['G4', 40, $u, 'strict'],
        'NO-BIND' => ['G4', 24, $u, 'off'],
        'NO-FCN' => ['G4', 24, ['tbC' => 4, 'tbT' => 1800, 'refund' => true], 'strict'],
        'NO-TB' => ['G4', 24, ['fc' => 4, 'net' => true], 'strict'],
        'WEAKBIND' => ['G4', 24, $u, 'weak'],
        'NO-K5SEP' => ['NOESC', 24, $u, 'strict'],
        'ESC' => ['C5', 24, $u, 'strict'],
    ];
}

/** U_acct is the fixed window only where the bucket is absent (NO-TB: 16); otherwise off. */
function g4Params(int $uSrc, array $u): array
{
    return g3Params($uSrc, isset($u['tbT']) ? 1000000 : 16);
}

final class G4Host
{
    /** @var array<string, array<string, array<string, mixed>>> holder|inst => account => token */
    public array $jar = [];
    /** @var array<string, int> */
    public array $epoch = [];
    /** @var array<string, array<string, true>> inst@window => accounts failed */
    public array $fails = [];
    public int $issued = 0;
    public int $tainted = 0;
    private int $seq = 0;

    public function __construct(public string $mode = 'strict')
    {
    }

    private function mac(array $t): string
    {
        $p = $t;
        unset($p['mac']);

        return hash_hmac('sha256', json_encode($p, JSON_THROW_ON_ERROR), 'g4-study-binding-key');
    }

    private function jarKey(string $holder, string $inst): string
    {
        return $holder . '|' . $inst;
    }

    /** Host issues a token for (account, instance) to the holder's jar. */
    public function issue(string $holder, string $acct, string $inst, int $now, int $ttl = G4_TOKEN_TTL): void
    {
        $t = ['v' => 1, 'account' => $acct, 'instance' => $inst, 'iat' => $now, 'exp' => $now + $ttl,
            'epoch' => $this->epoch[$acct] ?? 0, 'jti' => 'jti-' . (++$this->seq)];
        $t['mac'] = $this->mac($t);
        $this->jar[$this->jarKey($holder, $inst)][$acct] = $t;
        $this->issued++;
    }

    public function copyJar(string $fromHolder, string $toHolder, string $inst): void
    {
        $this->jar[$this->jarKey($toHolder, $inst)] = $this->jar[$this->jarKey($fromHolder, $inst)] ?? [];
    }

    public function revoke(string $acct): void
    {
        $this->epoch[$acct] = ($this->epoch[$acct] ?? 0) + 1;
    }

    /** Boundary: the token id when the request may enter P, else null. */
    public function verify(string $holder, ?string $inst, string $acct, int $now): ?string
    {
        if ($this->mode === 'off' || $inst === null) {
            return null;
        }
        $tokens = $this->jar[$this->jarKey($holder, $inst)] ?? [];
        $candidates = $this->mode === 'weak' ? array_values($tokens) : (isset($tokens[$acct]) ? [$tokens[$acct]] : []);
        foreach ($candidates as $t) {
            $ok = hash_equals($this->mac($t), $t['mac']) && $t['instance'] === $inst && $now < $t['exp']
                && $t['epoch'] === ($this->epoch[$t['account']] ?? 0)
                && ($this->mode === 'weak' || $t['account'] === $acct);
            if ($ok) {
                return $t['jti'];
            }
        }

        return null;
    }

    /** Host sees its own verifier result for a request that reached the verifier (every flow). */
    public function observe(string $holder, ?string $inst, string $acct, string $lane, string $outcome, int $now): void
    {
        if ($inst === null || $this->mode === 'off') {
            return;
        }
        $w = $inst . '@' . intdiv($now, 600);
        if ($outcome === 'fail') {
            $this->fails[$w][$acct] = true;

            return;
        }
        if ($lane !== 'U') {
            return;
        }
        $others = array_diff(array_keys($this->fails[$w] ?? []), [$acct]);
        if (count($others) >= G4_TAINT) {
            $this->tainted++;

            return;
        }
        $this->issue($holder, $acct, $inst, $now);
    }
}

final class G4Gate
{
    public G3V2Gate $u;

    public function __construct(public G1Admission $adm, array $uOpts)
    {
        $this->u = new G3V2Gate($adm, $uOpts);
    }

    /** @return array{ok: bool, refused: ?string, tb: bool} */
    public function admit(G1Cell $cell, RateLimitContextDTO $c, string $lane, ?string $jti): array
    {
        if ($lane === 'U') {
            return $this->u->admit($cell, $c, 'U');
        }
        $fp = $cell->lab->resolver->resolve($c)->fingerprintHash;

        return $this->adm->reserve('P', $cell->lab->ipPrefix($c->ip), $c->accountId, $jti, $fp, $cell->now()) + ['tb' => false];
    }
}

/** @return array{0: G1Cell, 1: G4Gate, 2: G4Host} */
function g4Cell(string $arm): array
{
    [, $uSrc, $u, $mode] = g4Arms()[$arm];
    $cell = new G1Cell('CAND', 'login', g4Params($uSrc, $u));
    $cell->adm ?? throw new \LogicException('CAND cell without admission');

    return [$cell, new G4Gate($cell->adm, $u), new G4Host($mode)];
}

/**
 * Replay with binding. Output as g3Replay plus 'pAdmits' and per-row 'owner' details when asked.
 *
 * @param list<array{t: int, ctx: RateLimitContextDTO, kind: string, own: bool, who: string, outcome: string, inst: ?string, holder: string}> $rows
 * @return array<string, mixed>
 */
function g4Replay(G1Cell $cell, G4Gate $gate, G4Host $host, array $rows, string $flow): array
{
    $dec = $lanes = $reach = $kinds = '';
    $byCause = [];
    $atkByCause = [];
    foreach ($rows as $r) {
        if (isset($r['event'])) {
            // events carry a stream-relative time; the Host works on the cell's absolute clock
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
            $cause = $cell->penaltyCause($c, $res['d'], $lane);
        } else {
            $a = $gate->admit($cell, $c, $lane, $jti);
            if (! $a['ok']) {
                $denied = true;
                $cause = 'cap:' . $a['refused'];
            } else {
                $reached = true;
                if ($c->accountId !== null) {
                    $host->observe($r['holder'], $r['inst'], $c->accountId, $lane, $r['outcome'], $now);
                }
                if ($flow === 'pre+out') {
                    $rec = $cell->cmd($c, $r['outcome'], $lane);
                    if ($lane === 'U') {
                        $gate->u->outcome($cell, $c, 'U', $r['outcome'], $a['tb']);
                    }
                    if ($rec['d'] !== 'A') {
                        $denied = true;
                        $cause = $cell->penaltyCause($c, $rec['d'], $lane);
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

/** A context with the instance id in sessionDeviceId (untrusted unless the original was trusted). */
function g4Ctx(RateLimitContextDTO $c, ?string $inst, ?bool $verified = null): RateLimitContextDTO
{
    return new RateLimitContextDTO($c->ip, $c->ua, $c->accountId, $c->clientFingerprint, $inst ?? $c->sessionDeviceId,
        $c->isSessionTrusted, $c->headers, $verified ?? $c->isDevicePreviouslyVerifiedForAccount, $c->correlationId);
}

/**
 * A G3 stream as G4 rows (PLAN.md §2 STREAMS). Legitimate actors keep 'i:' + who; attack rows get a fresh id per request or one
 * constant id. Provisioning events give a token to an actor the G1 mapping marks proven, or a legitimate actor the stream marks
 * previously verified, before its first row.
 *
 * @return list<array<string, mixed>>
 */
function g4FromStream(string $id, string $strategy, string $idPolicy, bool $withIds = true): array
{
    $out = [];
    $prov = [];
    foreach (g1Materialise($id, 'login', $strategy) as $i => $r) {
        $c = $r['ctx'];
        $atk = $r['kind'] === 'X';
        $inst = ! $withIds ? null : ($atk ? ($idPolicy === 'fresh' ? 'x:' . $i : 'x:const') : 'i:' . $r['who']);
        $holder = $atk ? 'atk' : 'h:' . $r['who'];
        $provision = $c->accountId !== null && $inst !== null
            && ($r['proof'] !== null || (! $atk && $c->isDevicePreviouslyVerifiedForAccount));
        if ($provision && ! isset($prov[$holder . $inst . $c->accountId])) {
            $prov[$holder . $inst . $c->accountId] = true;
            $acct = $c->accountId;
            $out[] = ['t' => max(0, $r['t'] - 1), 'event' => static fn(G4Host $h, int $now) => $h->issue($holder, $acct, $inst, $now)];
        }
        $out[] = $r + ['ctx' => $withIds ? g4Ctx($c, $inst) : $c, 'inst' => $inst, 'holder' => $holder];
    }

    return $out;
}

function g4Emit(string $s, array $row): void
{
    g3Emit($s, $row);
}

/** Metrics over rows that are not events. */
function g4Row(array $rows, array $r): array
{
    $plain = array_values(array_filter($rows, static fn(array $x): bool => ! isset($x['event'])));

    return g3Row($plain, $r) + ['issued' => $r['issued'], 'tainted' => $r['tainted']];
}
