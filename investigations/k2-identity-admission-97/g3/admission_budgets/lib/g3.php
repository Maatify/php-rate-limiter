<?php

declare(strict_types=1);

// G3 admission-budget study (#97) — harness. EVIDENCE ONLY: not package runtime, never wired into src/.
//
// Reuses, unchanged: the G1 harness (g1/lib/g1.php, g1/lib/admission.php, g1/verify/lib/verify.php), its streams, its cell
// engine and its admission layer. Adds only (PLAN.md §2):
//   G3Gate    a wrapper around G1Admission for the U lane: the FC failure cap (§2.2) and the TB per-account bucket (§2.4);
//   g3Replay  g1Replay() with the gate in place of G1Cell::admit() and an outcome hook for FC members and TB refunds.
// Builds (env G3_BUILD, with G1_ARM=CAND): CAND = the G1 candidate tree; NOESC = CAND + patches/g3-ablation-no-k4u-persist.diff.

require_once dirname(__DIR__, 3) . '/g1/verify/lib/verify.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Service\EvaluationPipeline;

const G3_NOESC_PIPELINE_SHA256 = '2926e1c5d6ce058e62bd06a9aec9fe86e532b8870d9af54a3fe1f42ec55e1db4';

/** Streams of PLAN.md §4.1 (the G1 v07 sweep list). */
const G3_STREAMS = ['L1', 'ABA_B', 'D2', 'N12', 'Nu4', 'Y6b', 'KD', 'UAC', 'MIX', 'OFF12', 'POI', 'L3', 'D1',
    'SP6', 'SP3', 'SP1n', 'ABA_A', 'X2', 'X1', 'X7', 'BRU', 'BRD'];

function g3Build(): string
{
    $b = getenv('G3_BUILD');

    return $b === false || $b === '' ? 'CAND' : $b;
}

/** Aborts (exit 4) unless the loaded pipeline is exactly the build the run claims. @return array<string, string> */
function g3Provenance(): array
{
    if (g3Build() === 'CAND') {
        return g1Provenance();
    }
    $file = (string) (new \ReflectionClass(EvaluationPipeline::class))->getFileName();
    $sha = (string) hash_file('sha256', $file);
    $repo = realpath(dirname(__DIR__, 5) . '/src/Service/EvaluationPipeline.php');
    $ok = g3Build() === 'NOESC' && getenv('G1_ARM') === 'CAND' && realpath($file) !== $repo && $sha === G3_NOESC_PIPELINE_SHA256;
    if (! $ok) {
        fwrite(STDERR, 'G3 provenance violation: build ' . g3Build() . " loaded $file ($sha)\n");
        exit(4);
    }

    return ['runtime' => 'CAND', 'build' => 'NOESC', 'pipeline_sha256' => $sha];
}

/** @return array<string, int> G1 set R with the given U_src / U_acct (PLAN.md §2.1, §3). */
function g3Params(int $uSrc, int $uAcct = 16): array
{
    return ['U_src' => $uSrc, 'U_acct' => $uAcct] + g1Params('R');
}

/**
 * U-lane admission wrapper. FC: refuse a new subject once the source failed on F distinct accounts in this window.
 * TB: per-account token bucket (capacity C, one token per T seconds), integer credit in seconds, full at first sight.
 */
final class G3Gate
{
    /** @var array<string, array<string, true>> source@window => accounts that failed (or had any outcome for FCALL) */
    public array $failSet = [];
    /** @var array<string, array{0: int, 1: int}> account => [credit seconds, last update] */
    public array $bucket = [];
    /** @var list<array{t: int, acct: string, kind: string}> TB admissions and refunds, for the independent recount */
    public array $tbLog = [];
    public int $refunds = 0;

    /** @param array{fc?: int, fcAll?: bool, tbC?: int, tbT?: int, refund?: bool} $o */
    public function __construct(public G1Admission $adm, public array $o = [])
    {
    }

    public function fc(): ?int
    {
        return $this->o['fc'] ?? null;
    }

    public function tb(): bool
    {
        return isset($this->o['tbT']);
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
        if ($this->fc() !== null) {
            $set = $this->failSet[$this->setKey($src, $now)] ?? [];
            if (! isset($set[$acct]) && count($set) >= $this->fc()) {
                return ['ok' => false, 'refused' => 'fail_src', 'created' => 0, 'tb' => false];
            }
        }
        if ($this->tb() && $this->credit($acct, $now) < (int) $this->o['tbT']) {
            return ['ok' => false, 'refused' => 'acct_tb', 'created' => 0, 'tb' => false];
        }
        $res = $this->adm->reserve('U', $src, $acct, $c->sessionDeviceId, null, $now);
        if (! $res['ok'] || ! $this->tb()) {
            return $res + ['tb' => false];
        }
        $this->bucket[$acct] = [$this->credit($acct, $now) - (int) $this->o['tbT'], $now];
        $this->tbLog[] = ['t' => $now, 'acct' => $acct, 'kind' => 'take'];

        return $res + ['tb' => true];
    }

    /** Outcome hook (pre+out and out flows): FC membership and TB refund on success. */
    public function outcome(G1Cell $cell, RateLimitContextDTO $c, string $lane, string $outcome, bool $tokenTaken): void
    {
        if ($lane !== 'U' || $c->accountId === null) {
            return;
        }
        $now = $cell->now();
        if ($this->fc() !== null && ($outcome === 'fail' || ($this->o['fcAll'] ?? false))) {
            $this->failSet[$this->setKey($cell->lab->ipPrefix($c->ip), $now)][$c->accountId] = true;
        }
        if ($tokenTaken && $outcome === 'ok' && ($this->o['refund'] ?? false)) {
            $cap = (int) $this->o['tbC'] * (int) $this->o['tbT'];
            $this->bucket[$c->accountId] = [min($cap, $this->credit($c->accountId, $now) + (int) $this->o['tbT']), $now];
            $this->refunds++;
            $this->tbLog[] = ['t' => $now, 'acct' => $c->accountId, 'kind' => 'refund'];
        }
    }
}

/** A cell on the current build with a gate. The cell's own admission is replaced by the gate. */
function g3Cell(array $p, array $gateOpts = []): array
{
    $cell = new G1Cell('CAND', 'login', $p);
    $gate = new G3Gate($cell->adm ?? throw new \LogicException('CAND cell without admission'), $gateOpts);

    return [$cell, $gate];
}

/**
 * g1Replay() (g1/lib/g1.php) with the gate in place of G1Cell::admit() and the outcome hook after every recorded outcome.
 * Same output fields and encoding, so rows compare with the G1 archive. Extra: per-request times of the owner in 'owner' rows.
 *
 * @param list<array{t: int, ctx: RateLimitContextDTO, proof: ?string, kind: string, own: bool, who: string, outcome: string}> $rows
 * @return array<string, mixed>
 */
function g3Replay(G1Cell $cell, G3Gate $gate, array $rows, string $flow): array
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

/** Best response over strategies: the row with the largest reachForeign (ties: first). */
function g3Emit(string $scenario, array $row): void
{
    echo json_encode(['s' => $scenario] + $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
}

/** Common row payload for one replay. @param list<array{t: int}> $rows */
function g3Row(array $rows, array $r, int $W = 600): array
{
    $ts = array_map(static fn(array $x): int => $x['t'], $rows);

    return ['m' => g1Metrics($r['kinds'], $r['lanes'], $r['reach'], $r['dec'], $ts, $W),
        'legitDeniedByCause' => $r['legitDeniedByCause'], 'attackDeniedByCause' => $r['attackDeniedByCause'],
        'dec' => $r['dec'], 'reach' => $r['reach'], 'kinds' => $r['kinds'], 'lanes' => $r['lanes']];
}

/** One rotating attacker context (best response over free identifiers this pipeline sees: source and UA). */
function g3Attacker(int $n, string $acct): RateLimitContextDTO
{
    $ip = '198.18.' . intdiv($n, 250) % 250 . '.' . (1 + $n % 250);

    return new RateLimitContextDTO($ip, ci97RotUa($n), $acct, null, null, false, [], false, null);
}
