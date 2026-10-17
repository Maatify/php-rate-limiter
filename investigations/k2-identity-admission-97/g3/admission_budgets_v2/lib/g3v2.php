<?php

declare(strict_types=1);

// G3 v2 (#97) — harness. EVIDENCE ONLY: not package runtime, never wired into src/.
// Reuses the G3 v1 library read-only (../../admission_budgets/lib/g3.php) and adds, per PLAN.md §2:
//   G3V2Gate     the v1 gate logic with two switches: 'net' (a later success on the same account from the same source removes it
//                from the failure set) and 'known' (a request whose device is known for the account is not charged to the bucket);
//   g3v2Replay   g3Replay() (v1) for a G3V2Gate; identical control flow and output encoding.

require_once dirname(__DIR__, 2) . '/admission_budgets/lib/g3.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

final class G3V2Gate
{
    /** @var array<string, array<string, true>> */
    public array $failSet = [];
    /** @var array<string, array{0: int, 1: int}> */
    public array $bucket = [];
    /** @var list<array{t: int, acct: string, kind: string}> */
    public array $tbLog = [];
    public int $refunds = 0;
    public int $knownBypass = 0;

    /** @param array{fc?: int, net?: bool, tbC?: int, tbT?: int, refund?: bool, known?: bool} $o */
    public function __construct(public G1Admission $adm, public array $o = [])
    {
    }

    private function setKey(string $src, int $now): string
    {
        return $src . '@' . intdiv($now, $this->adm->p['W']);
    }

    private function credit(string $acct, int $now): int
    {
        $cap = (int) $this->o['tbC'] * (int) $this->o['tbT'];
        [$c, $last] = $this->bucket[$acct] ?? [$cap, $now];

        return min($cap, $c + max(0, $now - $last));
    }

    private function known(G1Cell $cell, RateLimitContextDTO $c): bool
    {
        $d = $cell->lab->resolver->resolve($c);

        return $d->isTrustedSession || $d->isDevicePreviouslyVerifiedForAccount;
    }

    /** @return array{ok: bool, refused: ?string, created: int, tb: bool} */
    public function admit(G1Cell $cell, RateLimitContextDTO $c, string $lane): array
    {
        $now = $cell->now();
        if ($lane !== 'U' || $c->accountId === null) {
            $fp = $lane === 'P' ? $cell->lab->resolver->resolve($c)->fingerprintHash : null;

            return $this->adm->reserve($lane, $cell->lab->ipPrefix($c->ip), $lane === 'A' ? null : $c->accountId, $c->sessionDeviceId, $fp, $now) + ['tb' => false];
        }
        $src = $cell->lab->ipPrefix($c->ip);
        $acct = $c->accountId;
        if (isset($this->o['fc'])) {
            $set = $this->failSet[$this->setKey($src, $now)] ?? [];
            if (! isset($set[$acct]) && count($set) >= $this->o['fc']) {
                return ['ok' => false, 'refused' => 'fail_src', 'created' => 0, 'tb' => false];
            }
        }
        $charge = isset($this->o['tbT']);
        if ($charge && ($this->o['known'] ?? false) && $this->known($cell, $c)) {
            $charge = false;
            $this->knownBypass++;
        }
        if ($charge && $this->credit($acct, $now) < (int) $this->o['tbT']) {
            return ['ok' => false, 'refused' => 'acct_tb', 'created' => 0, 'tb' => false];
        }
        $res = $this->adm->reserve('U', $src, $acct, $c->sessionDeviceId, null, $now);
        if (! $res['ok'] || ! $charge) {
            return $res + ['tb' => false];
        }
        $this->bucket[$acct] = [$this->credit($acct, $now) - (int) $this->o['tbT'], $now];
        $this->tbLog[] = ['t' => $now, 'acct' => $acct, 'kind' => 'take'];

        return $res + ['tb' => true];
    }

    public function outcome(G1Cell $cell, RateLimitContextDTO $c, string $lane, string $outcome, bool $tokenTaken): void
    {
        if ($lane !== 'U' || $c->accountId === null) {
            return;
        }
        $now = $cell->now();
        if (isset($this->o['fc'])) {
            $k = $this->setKey($cell->lab->ipPrefix($c->ip), $now);
            if ($outcome === 'fail') {
                $this->failSet[$k][$c->accountId] = true;
            } elseif (($this->o['net'] ?? false) && $outcome === 'ok') {
                unset($this->failSet[$k][$c->accountId]);
            }
        }
        if ($tokenTaken && $outcome === 'ok' && ($this->o['refund'] ?? false)) {
            $cap = (int) $this->o['tbC'] * (int) $this->o['tbT'];
            $this->bucket[$c->accountId] = [min($cap, $this->credit($c->accountId, $now) + (int) $this->o['tbT']), $now];
            $this->refunds++;
            $this->tbLog[] = ['t' => $now, 'acct' => $c->accountId, 'kind' => 'refund'];
        }
    }
}

function g3v2Cell(array $p, array $opts = []): array
{
    $cell = new G1Cell('CAND', 'login', $p);

    return [$cell, new G3V2Gate($cell->adm ?? throw new \LogicException('CAND cell without admission'), $opts)];
}

/** g3Replay() of v1 with a G3V2Gate. @return array<string, mixed> */
function g3v2Replay(G1Cell $cell, G3V2Gate $gate, array $rows, string $flow): array
{
    $dec = $lanes = $reach = $kinds = '';
    $byCause = [];
    $atkByCause = [];
    foreach ($rows as $r) {
        $cell->lab->at($r['t']);
        $c = $r['ctx'];
        $lane = $cell->lane($c, $r['proof']);
        $denied = false;
        $cause = '-';
        $reached = false;
        if ($flow === 'out') {
            $res = $cell->cmd($c, $r['outcome'], $lane);
            $reached = true;
            $gate->outcome($cell, $c, $lane, $r['outcome'], false);
            if ($res['d'] !== 'A') {
                $denied = true;
                $cause = $cell->penaltyCause($c, $res['d'], $lane);
            }
        } else {
            $res = $cell->cmd($c, 'check', $lane);
            if ($res['d'] !== 'A') {
                $denied = true;
                $cause = $cell->penaltyCause($c, $res['d'], $lane);
            } else {
                $a = $gate->admit($cell, $c, $lane);
                if (! $a['ok']) {
                    $denied = true;
                    $cause = 'cap:' . $a['refused'];
                } else {
                    $reached = true;
                    if ($flow === 'pre+out') {
                        $rec = $cell->cmd($c, $r['outcome'], $lane);
                        $gate->outcome($cell, $c, $lane, $r['outcome'], $a['tb']);
                        if ($rec['d'] !== 'A') {
                            $denied = true;
                            $cause = $cell->penaltyCause($c, $rec['d'], $lane);
                        }
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

    return ['dec' => $dec, 'lanes' => $lanes, 'reach' => $reach, 'kinds' => $kinds, 'legitDeniedByCause' => $byCause, 'attackDeniedByCause' => $atkByCause];
}

/** v1 BRU24: one victim, the attacker fails every 15 s for 86 400 s; $hv marks every attacker request as Host-verified. */
function g3v2Bru24(bool $hv = false): array
{
    $rows = [];
    for ($t = 0, $n = 0; $t < 86400; $t += 15, $n++) {
        $c = g3Attacker($n, 'acct-v');
        if ($hv) {
            $c = new RateLimitContextDTO($c->ip, $c->ua, $c->accountId, null, null, false, [], true, null);
        }
        $rows[] = ['t' => $t, 'ctx' => $c, 'proof' => null, 'kind' => 'X', 'own' => false, 'who' => 'atk', 'outcome' => 'fail'];
    }

    return $rows;
}
