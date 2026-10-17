<?php

declare(strict_types=1);

// G1 isolated comparative study (#97) — harness. EVIDENCE ONLY: not package runtime.
//
// Reuses, UNCHANGED: the PR #103 Lab (REAL EvaluationPipeline + REAL DeviceIdentityResolver + reference stores + controlled
// clock, via the #125 / #119 / #115 libraries) and the merged streams of #125 (ci97Streams), #119 (akdrVStreams) and #115
// (gnhStreams). The runtime is selected by LAB_ROOT + env G1_ARM:
//   S0   (G1_ARM unset)   the repository's unpatched src/ (pipeline sha-256 asserted, must live in the repository)
//   CAND (G1_ARM=CAND)    the isolated tree built by build_candidate.sh (patched pipeline sha-256 asserted, must NOT be the repo)
// Cell arms on the CAND runtime: CAND (pipeline + admission layer), NOADM (pipeline only), ALLU (admission layer, every request U).

require_once dirname(__DIR__, 3) . '/ua-client-identity-97/lib/ci97.php';
require_once dirname(__DIR__, 3) . '/account-scoped-known-device-relief-110/lib/akdr.php';
require_once dirname(__DIR__, 3) . '/guarded-network-headroom-110/lib/gnh.php';
require_once __DIR__ . '/admission.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Service\EvaluationPipeline;

const G1_BASELINE_SHA = '4c6ca618d8206ba502961eaf06ffb82a5d61b50d';
const G1_S0_PIPELINE_SHA256 = '402139452040cb319208ca7a441b2f70210bf1acee4bd4a0ad666e8101ef6eab';
const G1_CAND_PIPELINE_SHA256 = '1840d6e2b57cd57674beb7101d25d458e97949b719d473580db28f50ac13dfd9';
// Verification addendum builds (verify/build_verify.sh), selected with G1_ARM=CAND + G1_BUILD=TRACE|MUTANT. Unset = the candidate.
const G1_VERIFY_PIPELINE_SHA256 = [
    'TRACE' => '9c23ec24c11b89d2e8e8376a473f76270590b42e7828526b30206d3d25aecf6d',
    'MUTANT' => 'ecc9928d211cfd4366fce1975a72c9ab7dfaf1eedb39a2cde61c8c45615c4661',
    // DA ablation arms (verify/DA_T1_PLAN.md §1), both on top of the trace build
    'DAM' => 'abd2e51f2ccba4a4da1bff973c532387a8f13e87f1970e49547732c65fafe6e7',
    'DAO' => 'fa29130ab27955a46081ffa098c5a8071b11bc69e3c6daed72a6325c20e0ca96',
];

function g1Build(): string
{
    $b = getenv('G1_BUILD');

    return $b === false || $b === '' ? 'CAND' : $b;
}

function g1Runtime(): string
{
    $a = getenv('G1_ARM');

    return $a === false || $a === '' ? 'S0' : $a;
}

/** Aborts (exit 4) unless the loaded runtime is exactly the one the arm claims. @return array<string, string> */
function g1Provenance(): array
{
    foreach (['OAAA_ARM', 'GNH_ARM', 'OQC_ARM', 'WSB_CANDIDATE'] as $foreign) {
        $v = getenv($foreign);
        if ($v !== false && $v !== '') {
            fwrite(STDERR, "G1 provenance violation: foreign arm variable $foreign is set\n");
            exit(4);
        }
    }
    $file = (string) (new \ReflectionClass(EvaluationPipeline::class))->getFileName();
    $sha = (string) hash_file('sha256', $file);
    $repo = realpath(dirname(__DIR__, 4) . '/src/Service/EvaluationPipeline.php');
    $inRepo = $repo !== false && realpath($file) === $repo;
    $rt = g1Runtime();
    $ok = match ($rt) {
        'S0' => $inRepo && $sha === G1_S0_PIPELINE_SHA256,
        'CAND' => ! $inRepo && $sha === (g1Build() === 'CAND' ? G1_CAND_PIPELINE_SHA256 : (G1_VERIFY_PIPELINE_SHA256[g1Build()] ?? '')),
        default => false,
    };
    if (! $ok) {
        fwrite(STDERR, "G1 provenance violation: runtime $rt loaded $file ($sha)\n");
        exit(4);
    }

    return ['runtime' => $rt, 'build' => $rt === 'S0' ? 'S0' : g1Build(), 'pipeline_sha256' => $sha];
}

/** @return list<string> cell arms allowed on the current runtime */
function g1Arms(): array
{
    return g1Runtime() === 'S0' ? ['S0'] : ['CAND', 'NOADM', 'ALLU'];
}

// ---- workload ----------------------------------------------------------------------------------------------------------------------

const G1_CI97 = ['D1', 'L1', 'L3', 'OFF12', 'X1', 'X2', 'X7', 'X2L', 'BRU', 'BRD', 'POI', 'KD', 'UAC', 'SESS', 'D1API', 'X1API', 'X2API'];
const G1_GNH = ['D2', 'Nu4', 'Nu12', 'N12V', 'ABA_A', 'ABA_B', 'SP1n', 'SP3', 'SP6', 'MIX', 'Y6b'];
const G1_AKDR = ['N12', 'N12T', 'SP5', 'TMS', 'TMSd', 'TMX', 'TPAIRT', 'TMUA', 'KS50', 'D24K1'];

/** @return array<string, array{src: string, cls: string, shapes: list<string>, events: list<array<string, mixed>>}> */
function g1Streams(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $out = [];
    $c = ci97Streams();
    foreach (G1_CI97 as $id) {
        $out[$id] = ['src' => 'ci97', 'cls' => $c[$id]['cls'], 'shapes' => $c[$id]['shapes'], 'events' => $c[$id]['events']];
    }
    $g = gnhStreams();
    foreach (G1_GNH as $id) {
        $out[$id] = ['src' => 'gnh', 'cls' => $g[$id]['class'], 'shapes' => ['login'], 'events' => $g[$id]['events']];
    }
    $a = akdrVStreams();
    foreach (G1_AKDR as $id) {
        $out[$id] = ['src' => 'akdr', 'cls' => $a[$id]['class'] ?? $a[$id]['cls'], 'shapes' => ['login'], 'events' => $a[$id]['events']];
    }

    return $cache = $out;
}

/** #125 attacker strategies for #125 attack streams; the other libraries encode their own rotations. @return list<string> */
function g1Strategies(string $id): array
{
    $s = g1Streams()[$id];
    if ($s['src'] !== 'ci97' || ! in_array($s['cls'], ['attack', 'both'], true)) {
        return ['none'];
    }

    return ['none', 'uarot', 'rotate'];
}

/**
 * One stream materialised for a shape and strategy: context, proof account (mapping M1), labels.
 *
 * @return list<array{t: int, ctx: RateLimitContextDTO, proof: ?string, kind: string, own: bool, who: string, outcome: string}>
 */
function g1Materialise(string $id, string $shape, string $strategy): array
{
    $s = g1Streams()[$id];
    $rows = [];
    $events = $s['src'] === 'ci97' ? ci97Materialise($s['events'], 'S0N', $strategy) : $s['events'];
    foreach ($events as $ev) {
        if ($s['src'] === 'ci97') {
            $ctx = ci97Ctx($ev, $shape);
            $who = (string) $ev['who'];
            $proof = (($ev['trusted'] ?? false) && isset($ev['sid']) && $ev['sid'] === 'sess-' . $who && $ev['acct'] === 'acct-' . $who) ? $ev['acct'] : null;
        } else {
            $ctx = k1Ctx($ev, $shape);
            $who = (string) ($ev['who'] ?? $ev['fp'] ?? $ev['acct'] ?? $ev['ip']);
            $sess = ($ev['trusted'] ?? false) ? (string) ($ev['sess'] ?? 'sess-' . ($ev['acct'] ?? 'x')) : null;
            $proof = ($sess !== null && $ev['acct'] !== null && $sess === 'sess-' . $ev['acct']) ? $ev['acct'] : null;
        }
        $kind = $ev['kind'] === 'attack' ? 'X' : ($ev['kind'] === 'owner' ? 'O' : 'L');
        $rows[] = [
            't' => (int) $ev['t'],
            'ctx' => $ctx,
            'proof' => $shape === 'api' ? null : $proof,
            'kind' => $kind,
            'own' => $kind === 'X' && $ev['outcome'] === 'ok' && ! str_starts_with($shape, 'api'),
            'who' => $who,
            'outcome' => str_starts_with($shape, 'api') ? 'ok' : (string) $ev['outcome'],
        ];
    }

    return $rows;
}

// ---- cell engine -------------------------------------------------------------------------------------------------------------------

final class G1Cell
{
    public Lab $lab;
    public ?G1Admission $adm;
    /** @var array<string, true> accounts that issued at least one P-lane request (C9) */
    public array $pAccounts = [];
    /** @var array<string, true> */
    public array $accounts = [];

    /**
     * @param array<string, int> $p
     * @param array{0: object, 1: object, 2: object, 3: ?G1Store}|null $state serialized [clock, store, corr, admission store]
     */
    public function __construct(public string $arm, public string $shape, public array $p, ?G1Store $store = null, string $gen = 'g1-secret-current', ?string $prevGen = null, ?array $state = null, ?string $previousSecret = null, string $secret = Lab::SECRET)
    {
        if (! in_array($arm, g1Arms(), true)) {
            throw new \RuntimeException("arm $arm not allowed on runtime " . g1Runtime());
        }
        $proto = null;
        if ($state !== null) {
            // Rebuild the Lab around deserialized state so the REAL pipeline of THIS runtime operates on it (rollback / mixed fleet).
            $proto = (new \ReflectionClass(Lab::class))->newInstanceWithoutConstructor();
            $proto->clock = $state[0];
            $proto->store = $state[1];
            $proto->corr = $state[2];
            $store ??= $state[3];
        }
        $this->lab = new Lab($shape, previousSecret: $previousSecret, secret: $secret, shareStateWith: $proto);
        $class = $shape === 'api' ? 'api' : ($shape === 'otp' ? 'otp' : 'login');
        $this->adm = in_array($arm, ['CAND', 'ALLU'], true) ? new G1Admission($store ?? new G1RefStore(), $p, $class, $gen, $prevGen) : null;
    }

    public function cand(): bool
    {
        return $this->arm !== 'S0';
    }

    public function now(): int
    {
        return $this->lab->clock->now()->getTimestamp();
    }

    public function lane(RateLimitContextDTO $c, ?string $proof): string
    {
        if ($this->shape === 'api') {
            return 'A';
        }
        if ($this->arm === 'S0' || $this->arm === 'ALLU') {
            return 'U';
        }

        return ($proof !== null && $proof === $c->accountId && ($c->sessionDeviceId ?? '') !== '') ? 'P' : 'U';
    }

    /** @return array{cmd: string, d: string, lvl: ?int, retry: ?int, conf: string, dOps: int, wOps: int} */
    public function cmd(RateLimitContextDTO $c, string $cmd, string $lane): array
    {
        if ($this->cand()) {
            $this->lab->pipeline->g1SetLane($lane === 'P' ? 'P' : 'U');
        }
        if ($c->accountId !== null) {
            $this->accounts[$c->accountId] = true;
            if ($lane === 'P') {
                $this->pAccounts[$c->accountId] = true;
            }
        }

        return $this->lab->run($c, $cmd);
    }

    /** @return array{ok: bool, refused: ?string, created: int} */
    public function admit(RateLimitContextDTO $c, string $lane, ?array $permit = null): array
    {
        if ($this->adm === null) {
            return ['ok' => true, 'refused' => null, 'created' => 0];
        }
        $fp = $lane === 'P' ? $this->lab->resolver->resolve($c)->fingerprintHash : null;

        return $this->adm->reserve($lane, $this->lab->ipPrefix($c->ip), $lane === 'A' ? null : $c->accountId, $c->sessionDeviceId, $fp, $this->now(), $permit);
    }

    public function k4p(?string $acct): ?string
    {
        return $acct === null ? null : hash_hmac('sha256', "{$this->lab->policy->getName()}:rate_limiter:k4p:v2:prod:{$acct}", Lab::SECRET);
    }

    /** Cause of a pipeline denial: the persisted L2+ blocks on the request's keys (K4P included), else the soft/unpersisted class. */
    public function penaltyCause(RateLimitContextDTO $c, string $d, string $lane): string
    {
        $now = $this->now();
        $keys = $this->lab->keys($c);
        if ($lane === 'P') {
            $keys['K4'] = null;
            $keys['K4P'] = $this->k4p($c->accountId);
        }
        $hit = [];
        foreach ($keys as $name => $key) {
            $b = $key === null ? null : $this->lab->store->checkBlock($key);
            if ($b !== null && $b->level >= 2 && $b->expiresAt > $now) {
                $hit[] = $name;
            }
        }

        return $hit !== [] ? 'pen:' . implode('+', $hit) : ($d === 'S' ? 'soft' : 'pen:unpersisted');
    }
}

/**
 * Host-realistic replay of one materialised stream on one cell.
 * flow 'pre' | 'pre+out' : admission at checkOnly (T0); the verifier is reached iff admitted.
 * flow 'out'             : record only; the verifier was reached before the library call (no pre-verifier bound).
 * shape 'api'            : the single library call gates the operation; 'served' = allowed; admission at that call.
 *
 * @param list<array{t: int, ctx: RateLimitContextDTO, proof: ?string, kind: string, own: bool, who: string, outcome: string}> $rows
 * @return array<string, mixed>
 */
function g1Replay(G1Cell $cell, array $rows, string $flow): array
{
    $dec = '';
    $lanes = '';
    $reach = '';
    $kinds = '';
    $byCause = [];
    $atkByCause = [];
    $pForeign = 0;
    $t0 = $cell->lab->t0;
    foreach ($rows as $i => $r) {
        $cell->lab->at($r['t']);
        $c = $r['ctx'];
        $lane = $cell->lane($c, $r['proof']);
        // C4 event-level check: a P lane is only allowed for a proof naming this request's own account, with a device secret.
        if ($lane === 'P' && ($r['proof'] === null || $r['proof'] !== $c->accountId || ($c->sessionDeviceId ?? '') === '')) {
            $pForeign++;
        }
        $api = $cell->shape === 'api';
        $denied = false;
        $cause = '-';
        $reached = false;
        if ($api) {
            $first = $flow === 'out' ? 'ok' : 'check';
            $res = $cell->cmd($c, $first, $lane);
            if ($res['d'] !== 'A') {
                $denied = true;
                $cause = $cell->penaltyCause($c, $res['d'], $lane);
            } else {
                $a = $cell->admit($c, $lane);
                if (! $a['ok']) {
                    $denied = true;
                    $cause = 'cap:' . $a['refused'];
                } elseif ($flow === 'pre+out') {
                    $cell->cmd($c, 'ok', $lane);
                }
            }
            $reached = ! $denied;
        } elseif ($flow === 'out') {
            $res = $cell->cmd($c, $r['outcome'], $lane);
            $reached = true;
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
                $a = $cell->admit($c, $lane);
                if (! $a['ok']) {
                    $denied = true;
                    $cause = 'cap:' . $a['refused'];
                } else {
                    $reached = true;
                    if ($flow === 'pre+out') {
                        // As #125 ci97Replay: a denial returned by the outcome command (after the verifier ran) is a denial too.
                        $rec = $cell->cmd($c, $r['outcome'], $lane);
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

    return ['dec' => $dec, 'lanes' => $lanes, 'reach' => $reach, 'kinds' => $kinds, 'legitDeniedByCause' => $byCause, 'attackDeniedByCause' => $atkByCause, 'pLaneForeignProof' => $pForeign];
}

/**
 * Metrics re-derivable from the archived strings (report.php recomputes them).
 *
 * @param list<int> $ts relative timestamps of the requests
 * @return array<string, int>
 */
function g1Metrics(string $kinds, string $lanes, string $reach, string $dec, array $ts, int $W = 600): array
{
    $m = ['attackAttempts' => 0, 'reachForeign' => 0, 'reachProof' => 0, 'reachOwn' => 0, 'verifierCalls' => 0,
        'legitAttempts' => 0, 'legitDenied' => 0, 'attackReachMaxPerWindow' => 0, 'attackReachMaxSliding' => 0];
    $win = [];
    $times = [];
    for ($i = 0, $n = strlen($kinds); $i < $n; $i++) {
        $reached = $reach[$i] === '1';
        $m['verifierCalls'] += $reached ? 1 : 0;
        $k = $kinds[$i];
        if ($k === 'X' || $k === 'o') {
            $m['attackAttempts']++;
            if ($reached) {
                if ($k === 'o') {
                    $m['reachOwn']++;
                } elseif ($lanes[$i] === 'P') {
                    $m['reachProof']++;
                } else {
                    $m['reachForeign']++;
                }
                $w = intdiv($ts[$i], $W);
                $win[$w] = ($win[$w] ?? 0) + 1;
                $times[] = $ts[$i];
            }
            continue;
        }
        $m['legitAttempts']++;
        $m['legitDenied'] += $dec[$i] !== 'A' ? 1 : 0;
    }
    $m['attackReachMaxPerWindow'] = $win === [] ? 0 : max($win);
    $j = 0;
    foreach ($times as $i => $t) {
        while ($times[$j] <= $t - $W) {
            $j++;
        }
        $m['attackReachMaxSliding'] = max($m['attackReachMaxSliding'], $i - $j + 1);
    }

    return $m;
}

/**
 * C9: U requests never wrote P-lane state. Every account WITHOUT a P request must have no K4P score or block, no P account
 * Anti-Equilibrium events and no P budget cooldown marker.
 *
 * @return list<string> violating accounts
 */
function g1PStateLeaks(G1Cell $cell): array
{
    $p = $cell->lab->policy->getName();
    $bad = [];
    foreach (array_keys($cell->accounts) as $acct) {
        if (isset($cell->pAccounts[$acct])) {
            continue;
        }
        $k4p = $cell->k4p($acct);
        $ae = hash_hmac('sha256', "{$p}:rate_limiter:aux:anti_equilibrium_p:v1:prod:{$acct}", Lab::SECRET);
        $cd = hash_hmac('sha256', "{$p}:rate_limiter:budget_cooldown_p:v1:prod:{$acct}", Lab::SECRET);
        if ($cell->lab->store->get((string) $k4p) !== null || $cell->lab->store->checkBlock((string) $k4p) !== null
            || $cell->lab->store->getBudget((string) $k4p) !== null || $cell->lab->corr->getWatchFlag($ae) > 0 || $cell->lab->store->get($cd) !== null) {
            $bad[] = $acct;
        }
    }
    sort($bad);

    return $bad;
}

/**
 * Penalty-relevant state of one request: scores and active blocks on K4, K4P and K5, the K4 / K4P budget counters and the shared
 * DEC-021 allowance counter. A null K5 score after a known-device failure means the request was routed ephemeral.
 *
 * @return array<string, mixed>
 */
function g1Snap(G1Cell $cell, RateLimitContextDTO $c): array
{
    $lab = $cell->lab;
    $p = $lab->policy->getName();
    $keys = $lab->keys($c);
    $k4p = $cell->k4p($c->accountId);
    $now = $cell->now();
    $blk = static function (?string $k) use ($lab, $now): ?string {
        $b = $k === null ? null : $lab->store->checkBlock($k);

        return $b !== null && $b->expiresAt > $now ? 'L' . $b->level : null;
    };
    $allow = $c->accountId === null ? null : $lab->store->getBudget(hash_hmac('sha256', "{$p}:rate_limiter:aux:known_device_allowance:v1:prod:{$c->accountId}", $lab->secret));

    return [
        'K4' => $keys['K4'] === null ? null : $lab->store->get($keys['K4'])?->value,
        'K4P' => $k4p === null ? null : $lab->store->get($k4p)?->value,
        'K5' => $keys['K5'] === null ? null : $lab->store->get($keys['K5'])?->value,
        'K5key' => $keys['K5'] === null ? null : substr($keys['K5'], 0, 12),
        'blkK4' => $blk($keys['K4']),
        'blkK4P' => $blk($k4p),
        'blkK5' => $blk($keys['K5']),
        'budgetK4' => $keys['K4'] === null ? null : $lab->store->getBudget($keys['K4'])?->count,
        'budgetK4P' => $k4p === null ? null : $lab->store->getBudget($k4p)?->count,
        'allowance' => $allow?->count,
    ];
}

/** Persist the full mutable state of a cell for another runtime process (rollback / mixed fleet). */
function g1SaveState(G1Cell $cell, string $file): void
{
    file_put_contents($file, serialize([$cell->lab->clock, $cell->lab->store, $cell->lab->corr, $cell->adm?->store]));
}

/** @return array{0: object, 1: object, 2: object, 3: ?G1Store} */
function g1LoadState(string $file): array
{
    /** @var array{0: object, 1: object, 2: object, 3: ?G1Store} $s */
    $s = unserialize((string) file_get_contents($file));

    return $s;
}

function g1Emit(string $scenario, array $row): void
{
    echo json_encode(['s' => $scenario] + $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
}
