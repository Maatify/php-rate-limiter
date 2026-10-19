<?php

declare(strict_types=1);

// G7 cross-account P study (#97, step 4) — harness. EVIDENCE ONLY: not package runtime, never wired into src/.
//
// Reuses read-only the archived G6 library (../../../g6/budgets/lib/g6.php: g6Spec, g6Cell, g6Cmd, g6PenaltyCause, g6TmsRow) and through it G5 v2, G5, G4 v2, G3 v2, G3, G1. Adds (PLAN.md §2):
//   G7PxGate   the rule: per window and source, the set of different accounts that failed through P; a further account is refused (cap:pfail_src) when the set holds F
//   g7Spec     the arms: NOUA2 (rule off, the reference), PX4, PX8, D-PXALL (the detector: every admitted outcome counts)
//   g7Replay   the G6 replay (UK lane removed) with the rule before the P admission and the outcome hook after it; with the rule off it is g5v2Replay (V0)

require_once dirname(__DIR__, 3) . '/g6/budgets/lib/g6.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

final class G7PxGate
{
    /** @var array<string, array<string, true>> source@window => accounts that failed through P */
    public array $set = [];

    public function __construct(public ?int $f, public bool $countAll = false, public int $w = 600)
    {
    }

    private function key(G1Cell $cell, RateLimitContextDTO $c, int $now): string
    {
        return $cell->lab->ipPrefix($c->ip) . '@' . intdiv($now, $this->w);
    }

    public function refuse(G1Cell $cell, RateLimitContextDTO $c, int $now): bool
    {
        if ($this->f === null || $c->accountId === null) {
            return false;
        }
        $s = $this->set[$this->key($cell, $c, $now)] ?? [];

        return ! isset($s[$c->accountId]) && count($s) >= $this->f;
    }

    public function record(G1Cell $cell, RateLimitContextDTO $c, string $outcome, int $now): void
    {
        if ($this->f !== null && $c->accountId !== null && ($outcome === 'fail' || $this->countAll)) {
            $this->set[$this->key($cell, $c, $now)][$c->accountId] = true;
        }
    }
}

/** @return array{member: string, rule: string, idMint: bool, idMaterial: bool, uk: bool, knownRoute: bool, f: ?int, countAll: bool} */
function g7Spec(string $arm): array
{
    $base = g6Spec('NOUA2');

    return match ($arm) {
        'NOUA2' => $base + ['f' => null, 'countAll' => false],
        'PX4' => $base + ['f' => 4, 'countAll' => false],
        'PX8' => $base + ['f' => 8, 'countAll' => false],
        'D-PXALL' => $base + ['f' => 4, 'countAll' => true],
        default => throw new \LogicException("unknown arm $arm"),
    };
}

function g7Px(string $arm): G7PxGate
{
    $s = g7Spec($arm);

    return new G7PxGate($s['f'], $s['countAll']);
}

/** @return array{0: G1Cell, 1: G5Gate, 2: G4V2Host} FULL2 with the NOUA2 member (the G5 v2 cell); the rule lives in G7PxGate. */
function g7Cell(string $arm): array
{
    g7Spec($arm);

    return g6Cell('NOUA2');
}

/** The G6 replay with the UK lane removed and the rule of PLAN.md §2 added; with the rule off it is g5v2Replay (V0). @return array<string, mixed> */
function g7Replay(G1Cell $cell, G5Gate $gate, G4V2Host $host, array $rows, string $flow, array $spec, G7PxGate $px): array
{
    g4v2AssertInput($rows);
    $dec = $lanes = $reach = $kinds = '';
    $byCause = [];
    $atkByCause = [];
    $refusedPx = 0;
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
        $pipe = $lane;
        $denied = false;
        $cause = '-';
        $reached = false;
        $res = g6Cmd($cell, $c, 'check', $lane, $pipe);
        if ($res['d'] !== 'A') {
            $denied = true;
            $cause = g6PenaltyCause($cell, $c, $res['d'], $lane, $pipe);
        } elseif ($lane === 'P' && $px->refuse($cell, $c, $now)) {
            $denied = true;
            $cause = 'cap:pfail_src';
            $refusedPx++;
        } else {
            $a = $gate->admit($cell, $c, $lane, $jti);
            if (! $a['ok']) {
                $denied = true;
                $cause = 'cap:' . $a['refused'];
            } else {
                $reached = true;
                if ($lane === 'P') {
                    g5v2NpAudit($cell, $c, $spec['member']);
                }
                if ($c->accountId !== null) {
                    $host->observe($r['holder'], $r['inst'], $c->accountId, $lane, $r['outcome'], $now, $jti);
                }
                if ($flow === 'pre+out') {
                    if ($lane === 'P') {
                        $px->record($cell, $c, $r['outcome'], $now);
                    }
                    $rec = g6Cmd($cell, $c, $r['outcome'], $lane, $pipe);
                    if ($lane === 'U') {
                        $gate->uOutcome($cell, $c, $r['outcome'], $a['tb']);
                    } else {
                        $gate->pOutcome($a['pkeys'], $r['outcome']);
                    }
                    if ($rec['d'] !== 'A') {
                        $denied = true;
                        $cause = g6PenaltyCause($cell, $c, $rec['d'], $lane, $pipe);
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
    $out = ['dec' => $dec, 'lanes' => $lanes, 'reach' => $reach, 'kinds' => $kinds, 'legitDeniedByCause' => $byCause,
        'attackDeniedByCause' => $atkByCause, 'issued' => $host->issued, 'tainted' => $host->tainted];

    return $px->f === null ? $out : $out + ['refusedPx' => $refusedPx];
}

function g7Step(G1Cell $cell, G5Gate $gate, G4V2Host $host, array $row, array $spec, G7PxGate $px): array
{
    $r = g7Replay($cell, $gate, $host, [$row], 'pre+out', $spec, $px);

    return ['d' => $r['dec'], 'lane' => $r['lanes'], 'reach' => $r['reach'], 'cause' => array_key_first($r['legitDeniedByCause'] + $r['attackDeniedByCause'])];
}

/** The archived STEAL fixture (thief side) through g7Replay: same rows and control flow, so the NOUA2 row equals the archive (V0). @return array<string, mixed> */
function g7StealRun(string $arm): array
{
    $spec = g7Spec($arm);
    $px = g7Px($arm);
    $acct = 'acct-st';
    $ownerCtx = new RateLimitContextDTO('198.51.100.141', FIREFOX_130, $acct, null, null, false, [], false, null);
    [$cell, $gate, $host] = g7Cell($arm);
    $parts = [
        [g4v2Issue(0, 'owner', $acct, 'i:st'), ['t' => 600, 'event' => static fn(G4V2Host $h, int $now) => $h->copyJar('owner', 'thief', 'i:st')]],
        array_map(static fn(int $t): array => g4v2Row1($t, $ownerCtx, 'O', 'owner', 'ok', 'i:st', 'owner'), range(60, 43199, 7200)),
        array_map(static fn(int $t): array => g4v2Row1($t, g3Attacker(intdiv($t, 15), $acct), 'X', 'thief', 'fail', 'i:st', 'thief'), range(615, 43199, 15)),
    ];
    $pre = g4v2Sort(array_merge(...$parts));
    $win = [];
    $day = 0;
    $ownerDen = 0;
    foreach ($pre as $row) {
        if (isset($row['event'])) {
            g7Replay($cell, $gate, $host, [$row], 'pre+out', $spec, $px);
            continue;
        }
        $s = g7Step($cell, $gate, $host, $row, $spec, $px);
        if ($row['holder'] === 'thief' && $s['lane'] === 'P' && $s['reach'] === '1') {
            $win[intdiv($row['t'], 600)] = ($win[intdiv($row['t'], 600)] ?? 0) + 1;
            $day++;
        }
        $ownerDen += $row['holder'] === 'owner' && $s['d'] !== 'A' ? 1 : 0;
    }
    g7Replay($cell, $gate, $host, [['t' => 43200, 'event' => static fn(G4V2Host $h, int $now) => $h->passwordChange('owner', $acct, 'i:st', $now)]], 'pre+out', $spec, $px);
    $afterP = 0;
    $afterU = 0;
    $ownerWait = null;
    $ownerLane = null;
    $ownerCauses = [];
    $next = 43260;
    for ($t = 43215; $t < 86400; $t += 15) {
        $s = g7Step($cell, $gate, $host, g4v2Row1($t, g3Attacker(intdiv($t, 15), $acct), 'X', 'thief', 'fail', 'i:st', 'thief'), $spec, $px);
        if ($s['lane'] === 'P' && $s['reach'] === '1') {
            $afterP++;
            $win[intdiv($t, 600)] = ($win[intdiv($t, 600)] ?? 0) + 1;
        }
        $afterU += $s['lane'] === 'U' && $s['reach'] === '1' ? 1 : 0;
        if ($ownerWait === null && $next === $t) {
            $s = g7Step($cell, $gate, $host, g4v2Row1($next, $ownerCtx, 'O', 'owner', 'ok', 'i:st', 'owner'), $spec, $px);
            if ($s['d'] === 'A') {
                $ownerWait = $next - 43260;
                $ownerLane = $s['lane'];
            } else {
                $ownerCauses[$s['cause']] = ($ownerCauses[$s['cause']] ?? 0) + 1;
                $next += 60;
            }
        }
    }
    ksort($ownerCauses);

    return ['fixture' => 'STEAL', 'arm' => $arm, 'thiefPMaxPerWindow' => $win === [] ? 0 : max($win), 'thiefPBeforeChange' => $day,
        'thiefPAfterChange' => $afterP, 'thiefUAfterChange' => $afterU, 'ownerDeniedBeforeChange' => $ownerDen,
        'ownerWaitAfterChange' => $ownerWait, 'ownerLaneAtAdmission' => $ownerLane, 'ownerDeniedAfterChangeByCause' => $ownerCauses,
        'pwBinds' => $host->pwBinds];
}
