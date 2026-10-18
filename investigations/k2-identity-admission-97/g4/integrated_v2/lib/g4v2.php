<?php

declare(strict_types=1);

// G4 v2 integrated re-assembly (#97, #110) — harness. EVIDENCE ONLY: not package runtime, never wired into src/.
//
// Reuses read-only: the archived G4 library (../../integrated/lib/g4.php: G4Host, G4Gate, g4Ctx, g4Cell, g4Arms, g4Params,
// g4Provenance), and through it the G1 / G3 / G3 v2 libraries. Adds (PLAN.md §1, §3):
//   builders   g4v2FromStream / g4v2Bru / g4v2Row1: the context is replaced by assignment, never by a left-biased array union;
//   IN         g4v2AssertInput(): every replayed row that declares an instance id carries it in the DTO's sessionDeviceId;
//   G4V2Host   G4Host plus the password-change binding and sliding renewal;
//   G4V2Gate   U lane as G4; P lane with the G1 budgets, the refund budget or the net-failure budget (G4V2PBudget);
//   g4v2Replay the G4 replay with the assertion, the P-lane outcome hook and the renewal hook.

require_once dirname(__DIR__, 2) . '/integrated/lib/g4.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

const G4V2_MAX_LIFE = 90 * 86400;

final class G4V2InputViolation extends \RuntimeException
{
}

set_exception_handler(static function (\Throwable $e): void {
    fwrite(STDERR, ($e instanceof G4V2InputViolation ? 'G4 v2 INPUT ASSERTION (IN): ' : 'G4 v2 error: ') . $e->getMessage() . "\n");
    exit($e instanceof G4V2InputViolation ? 6 : 1);
});

/** IN (PLAN.md §1): throws on the first row that declares an instance id the DTO does not carry. */
function g4v2AssertInput(array $rows): void
{
    foreach ($rows as $i => $r) {
        if (isset($r['event']) || ($r['inst'] ?? null) === null) {
            continue;
        }
        if ($r['ctx']->sessionDeviceId !== $r['inst']) {
            throw new G4V2InputViolation("row $i at t={$r['t']}: inst {$r['inst']} but sessionDeviceId " . var_export($r['ctx']->sessionDeviceId, true));
        }
    }
}

/**
 * Arms (PLAN.md §2): build, U_src, U options, boundary mode, part, P budget mode, renewal, password-change binding.
 *
 * @return array<string, array{0: string, 1: int, 2: array<string, mixed>, 3: string, 4: string, 5: string, 6: bool, 7: bool}>
 */
function g4v2Arms(): array
{
    $out = [];
    foreach (g4Arms() as $a => [$b, $uSrc, $u, $mode]) {
        $out["R-$a"] = [$b, $uSrc, $u, $mode, 'R', 'g1', false, false];
    }
    $u = ['fc' => 4, 'net' => true, 'tbC' => 4, 'tbT' => 1800, 'refund' => true];

    return $out + [
        'FULL2' => ['G4', 24, $u, 'strict', 'N', 'refund', true, true],
        'FULL2-40' => ['G4', 40, $u, 'strict', 'N', 'refund', true, true],
        'N-PG1' => ['G4', 24, $u, 'strict', 'N', 'g1', true, true],
        'N-NORENEW' => ['G4', 24, $u, 'strict', 'N', 'refund', false, true],
        'N-NOPWB' => ['G4', 24, $u, 'strict', 'N', 'refund', true, false],
        'N-NOK5SEP' => ['NOESC', 24, $u, 'strict', 'N', 'refund', true, true],
        'P-NETF' => ['G4', 24, $u, 'strict', 'N', 'netf', true, true],
    ];
}

/** G1 set R P-lane caps, enforced by G4V2PBudget in the refund / net-failure modes. */
function g4v2PCaps(): array
{
    $r = g1Params('R');

    return ['pacct' => $r['P_acct'], 'pacct_day' => $r['P_acct_day'], 'pproof' => $r['P_proof'], 'pproof_day' => $r['P_proof_day']];
}

/** P-lane budget of PLAN.md §3: counters per account and per token id, refunded on success (refund) or counting failures (netf). */
final class G4V2PBudget
{
    /** @var array<string, int> */
    public array $c = [];
    public int $refunds = 0;

    public function __construct(public string $mode, public array $caps)
    {
    }

    /** @return array<string, string> */
    public function keys(string $acct, string $jti, int $now): array
    {
        $w = intdiv($now, 600);
        $d = intdiv($now, 86400);

        return ['pacct' => "pacct|$acct|w$w", 'pacct_day' => "pacct_day|$acct|d$d", 'pproof' => "pproof|$jti|w$w", 'pproof_day' => "pproof_day|$jti|d$d"];
    }

    public function check(array $keys): ?string
    {
        foreach ($keys as $name => $k) {
            if (($this->c[$k] ?? 0) >= $this->caps[$name]) {
                return $name;
            }
        }

        return null;
    }

    public function add(array $keys, int $delta): void
    {
        foreach ($keys as $k) {
            $this->c[$k] = max(0, ($this->c[$k] ?? 0) + $delta);
        }
    }

    public function outcome(array $keys, string $outcome): void
    {
        if ($this->mode === 'refund' && $outcome === 'ok') {
            $this->add($keys, -1);
            $this->refunds++;
        } elseif ($this->mode === 'netf' && $outcome === 'fail') {
            $this->add($keys, 1);
        }
    }
}

final class G4V2Host
{
    /** @var array<string, array<string, array<string, mixed>>> */
    public array $jar = [];
    /** @var array<string, int> */
    public array $epoch = [];
    /** @var array<string, array<string, true>> */
    public array $fails = [];
    public int $issued = 0;
    public int $tainted = 0;
    public int $renewed = 0;
    public int $pwBinds = 0;
    private int $seq = 0;

    public function __construct(public string $mode = 'strict', public bool $renew = true, public bool $pwBind = true)
    {
    }

    private function mac(array $t): string
    {
        $p = $t;
        unset($p['mac']);

        return hash_hmac('sha256', json_encode($p, JSON_THROW_ON_ERROR), 'g4v2-study-binding-key');
    }

    private function jarKey(string $holder, string $inst): string
    {
        return $holder . '|' . $inst;
    }

    /** Issue (new chain) or, with $jti, renew a token: exp = min(now + ttl, bindAt + 90 days). */
    public function issue(string $holder, string $acct, string $inst, int $now, int $ttl = G4_TOKEN_TTL, ?int $bindAt = null, ?string $jti = null): void
    {
        $bindAt ??= $now;
        $t = ['v' => 2, 'account' => $acct, 'instance' => $inst, 'iat' => $now, 'exp' => min($now + $ttl, $bindAt + G4V2_MAX_LIFE),
            'epoch' => $this->epoch[$acct] ?? 0, 'jti' => $jti ?? 'jti-' . (++$this->seq), 'ttl' => $ttl, 'bindAt' => $bindAt];
        $t['mac'] = $this->mac($t);
        $this->jar[$this->jarKey($holder, $inst)][$acct] = $t;
        $jti === null ? $this->issued++ : $this->renewed++;
    }

    public function copyJar(string $fromHolder, string $toHolder, string $inst): void
    {
        $this->jar[$this->jarKey($toHolder, $inst)] = $this->jar[$this->jarKey($fromHolder, $inst)] ?? [];
    }

    public function revoke(string $acct): void
    {
        $this->epoch[$acct] = ($this->epoch[$acct] ?? 0) + 1;
    }

    /** Password change / recovery from (holder, inst): epoch bump and, with the binding on, a new chain for that device. */
    public function passwordChange(string $holder, string $acct, string $inst, int $now): void
    {
        $this->revoke($acct);
        if ($this->pwBind && $this->mode !== 'off') {
            $this->issue($holder, $acct, $inst, $now);
            $this->pwBinds++;
        }
    }

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

    /** Host sees its verifier result: failures feed taint; a U success binds (untainted); a P success renews the presented token. */
    public function observe(string $holder, ?string $inst, string $acct, string $lane, string $outcome, int $now, ?string $jti = null): void
    {
        if ($inst === null || $this->mode === 'off') {
            return;
        }
        $w = $inst . '@' . intdiv($now, 600);
        if ($outcome === 'fail') {
            $this->fails[$w][$acct] = true;

            return;
        }
        if ($lane === 'P') {
            if ($this->renew && $jti !== null) {
                foreach ($this->jar[$this->jarKey($holder, $inst)] ?? [] as $t) {
                    if ($t['jti'] === $jti) {
                        $this->issue($holder, $t['account'], $inst, $now, $t['ttl'], $t['bindAt'], $jti);
                        break;
                    }
                }
            }

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

final class G4V2Gate
{
    public G3V2Gate $u;
    public ?G4V2PBudget $pb;

    public function __construct(public G1Admission $adm, array $uOpts, public string $pMode)
    {
        $this->u = new G3V2Gate($adm, $uOpts);
        $this->pb = $pMode === 'g1' ? null : new G4V2PBudget($pMode, g4v2PCaps());
    }

    /** @return array{ok: bool, refused: ?string, tb: bool, pkeys: ?array<string, string>} */
    public function admit(G1Cell $cell, RateLimitContextDTO $c, string $lane, ?string $jti): array
    {
        if ($lane === 'U') {
            return $this->u->admit($cell, $c, 'U') + ['pkeys' => null];
        }
        $now = $cell->now();
        $keys = null;
        if ($this->pb !== null) {
            $keys = $this->pb->keys((string) $c->accountId, (string) $jti, $now);
            $refused = $this->pb->check($keys);
            if ($refused !== null) {
                return ['ok' => false, 'refused' => $refused, 'created' => 0, 'tb' => false, 'pkeys' => null];
            }
        }
        $fp = $cell->lab->resolver->resolve($c)->fingerprintHash;
        $res = $this->adm->reserve('P', $cell->lab->ipPrefix($c->ip), $c->accountId, $jti, $fp, $now);
        if ($res['ok'] && $this->pb !== null && $this->pb->mode === 'refund') {
            $this->pb->add($keys, 1);
        }

        return $res + ['tb' => false, 'pkeys' => $res['ok'] ? $keys : null];
    }

    public function pOutcome(?array $keys, string $outcome): void
    {
        if ($this->pb !== null && $keys !== null) {
            $this->pb->outcome($keys, $outcome);
        }
    }
}

/** G1 parameters: G4's, with the G1 P caps lifted where G4V2PBudget enforces them. */
function g4v2Params(int $uSrc, array $u, string $pMode): array
{
    $p = g4Params($uSrc, $u);
    if ($pMode !== 'g1') {
        $p['P_acct'] = $p['P_acct_day'] = $p['P_proof'] = $p['P_proof_day'] = 1000000;
    }

    return $p;
}

/** @return array{0: G1Cell, 1: G4Gate|G4V2Gate, 2: G4Host|G4V2Host} */
function g4v2Cell(string $arm): array
{
    [, $uSrc, $u, $mode, $part, $pMode, $renew, $pwb] = g4v2Arms()[$arm];
    if ($part === 'R') {
        return g4Cell(substr($arm, 2));
    }
    $cell = new G1Cell('CAND', 'login', g4v2Params($uSrc, $u, $pMode));
    $cell->adm ?? throw new \LogicException('CAND cell without admission');

    return [$cell, new G4V2Gate($cell->adm, $u, $pMode), new G4V2Host($mode, $renew, $pwb)];
}

/**
 * The G4 replay (archived g4Replay) with the IN assertion, the renewal hook (the presented token id goes to the Host) and the
 * P-lane outcome hook. For Part R objects (G4Gate, G4Host) both hooks are no-ops, so Part R replays exactly as G4 did.
 *
 * @return array<string, mixed>
 */
function g4v2Replay(G1Cell $cell, G4Gate|G4V2Gate $gate, G4Host|G4V2Host $host, array $rows, string $flow): array
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
            $cause = $cell->penaltyCause($c, $res['d'], $lane);
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
                        $gate->u->outcome($cell, $c, 'U', $r['outcome'], $a['tb']);
                    } elseif ($gate instanceof G4V2Gate) {
                        $gate->pOutcome($a['pkeys'], $r['outcome']);
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

/** g4FromStream() with the context replaced by assignment (the F131-01 correction). */
function g4v2FromStream(string $id, string $strategy, string $idPolicy, bool $withIds = true): array
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
            $out[] = ['t' => max(0, $r['t'] - 1), 'event' => static fn(G4Host|G4V2Host $h, int $now) => $h->issue($holder, $acct, $inst, $now)];
        }
        $row = $r;
        $row['ctx'] = $withIds ? g4Ctx($c, $inst) : $c;
        $row['inst'] = $inst;
        $row['holder'] = $holder;
        $out[] = $row;
    }

    return $out;
}

/** BRU24 / BRU24-HV attacker rows (G3 v2 g3v2Bru24) with a fresh instance id per request, context replaced by assignment. */
function g4v2Bru(bool $hv, int $until = 86400): array
{
    $rows = [];
    foreach (g3v2Bru24($hv) as $n => $r) {
        if ($r['t'] < $until) {
            $row = $r;
            $row['ctx'] = g4Ctx($r['ctx'], 'x:' . $n);
            $row['inst'] = 'x:' . $n;
            $row['holder'] = 'atk';
            $rows[] = $row;
        }
    }

    return $rows;
}

/** As the archived g4Row1() of the G4 fixtures (already correct there). */
function g4v2Row1(int $t, RateLimitContextDTO $c, string $kind, string $who, string $outcome, ?string $inst, string $holder): array
{
    return ['t' => $t, 'ctx' => g4Ctx($c, $inst), 'proof' => null, 'kind' => $kind, 'own' => false, 'who' => $who,
        'outcome' => $outcome, 'inst' => $inst, 'holder' => $holder];
}

function g4v2Issue(int $t, string $holder, string $acct, string $inst, int $ttl = G4_TOKEN_TTL, ?int $bindOffset = null): array
{
    return ['t' => $t, 'event' => static fn(G4Host|G4V2Host $h, int $now) => $bindOffset === null
        ? $h->issue($holder, $acct, $inst, $now, $ttl)
        : $h->issue($holder, $acct, $inst, $now, $ttl, $now + $bindOffset)];
}

function g4v2Sort(array $rows): array
{
    usort($rows, static fn(array $a, array $b): int => [$a['t'], isset($a['event']) ? 0 : 1, ($a['kind'] ?? '') === 'X' ? 0 : 1]
        <=> [$b['t'], isset($b['event']) ? 0 : 1, ($b['kind'] ?? '') === 'X' ? 0 : 1]);

    return $rows;
}

function g4v2Step(G1Cell $cell, G4Gate|G4V2Gate $gate, G4Host|G4V2Host $host, array $row): array
{
    $r = g4v2Replay($cell, $gate, $host, [$row], 'pre+out');

    return ['d' => $r['dec'], 'lane' => $r['lanes'], 'reach' => $r['reach'], 'cause' => array_key_first($r['legitDeniedByCause'] + $r['attackDeniedByCause'])];
}

/** Arms of a build, in g4v2Arms() order. */
function g4v2ArmsOf(string $build): array
{
    return array_keys(array_filter(g4v2Arms(), static fn(array $a): bool => $a[0] === $build));
}
