<?php

declare(strict_types=1);

// G5 v2 identity-layer study (#97, #110) — harness. EVIDENCE ONLY: not package runtime, never wired into src/.
//
// Reuses read-only: the archived G5 library (../../identity_layer/lib/g5.php, g5fx.php: g5Provenance, G5Gate, g5Pipe, the fixture row
// builders) and through it the G4 v2, G4, G3 v2, G3 and G1 libraries. Adds (PLAN.md §2, §3, §4):
//   g5v2Provenance  the pinned G5V2 builds (G5 + patches/g5v2-k4p-scope.diff, and the four G5 ablations on top of it)
//   g5v2Spec/Cell   the arms: N_P member (fingerprint | id) and the K4P rule (A | B | C), PLAN.md §2.2
//   g5v2NpAudit     NP-IN: after each P admission the admission store's member sets are read and checked against the material
//   g5v2Replay      the G5 replay with the K4P scope set before every request and the NP-IN audit after every P admission
//   fixtures        STEAL / STEAL2 / MULTITOK / UAX-NP / UAX-NP-K and the material-varying rule

require_once dirname(__DIR__, 2) . '/identity_layer/lib/g5fx.php';

use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EvaluationPipeline;

const G5V2_PIPELINE_SHA256 = [
    'G5V2' => 'dcf1161afe779bed2cbd22c3c9e5897449f1ef048cab5f68cd8aeaf0caa53b77',
    'G5V2-NOALW' => 'f59ce0084c697f3c71fbb01e5df7e493cf76767a9c34a4757225b0118e54fdce',
    'G5V2-NOOVF' => 'b181947652606968609c43d9aa8f95dc9edcda9cd073dbeae6440d8b786a71e1',
    'G5V2-NOK5' => '497596412ceb4ec947351389f4ed9888677bdbf38453b9f33ad57c554af43d71',
    'G5V2-NOAE' => 'dc77958d08a4a0bc3674cac6686214de02134156ac89ed3a7acf9990c17234b0',
];

/** Arm name of a build in the pipeline-level scenarios. */
function g5v2ArmOfBuild(string $build): string
{
    return match ($build) {
        'G5V2' => 'NOUA2', 'G5V2-NOALW' => 'D-NOALW', 'G5V2-NOOVF' => 'D-NOOVF', 'G5V2-NOK5' => 'D-NOK5', 'G5V2-NOAE' => 'D-NOAE',
        default => throw new \LogicException("no arm for build $build"),
    };
}

/** Aborts (exit 4) unless the loaded pipeline (and, for G5V2 builds, resolver and DTO) is exactly the claimed build. CAND and G4 are G5's. */
function g5v2Provenance(): string
{
    $b = g5Build();
    if (! isset(G5V2_PIPELINE_SHA256[$b])) {
        return g5Provenance();
    }
    $file = (string) (new \ReflectionClass(EvaluationPipeline::class))->getFileName();
    $sha = (string) hash_file('sha256', $file);
    $repo = realpath(dirname(__DIR__, 5) . '/src/Service/EvaluationPipeline.php');
    $res = (string) hash_file('sha256', (string) (new \ReflectionClass(DeviceIdentityResolver::class))->getFileName());
    $dto = (string) hash_file('sha256', (string) (new \ReflectionClass(DeviceIdentityDTO::class))->getFileName());
    $ok = getenv('G1_ARM') === 'CAND' && $repo !== false && realpath($file) !== $repo && G5V2_PIPELINE_SHA256[$b] === $sha
        && $res === G5_RESOLVER_SHA256 && $dto === G5_DTO_SHA256;
    if (! $ok) {
        fwrite(STDERR, "G5 v2 provenance violation: build $b loaded $file ($sha)\n");
        exit(4);
    }

    return $b;
}

/**
 * The arm table of PLAN.md §2.2: N_P member material, K4P rule, D-IDMINT switch, and whether the K5 material is the id (the penalty
 * cause of the replay reads the id-derived K5 key).
 *
 * @return array{member: string, rule: string, idMint: bool, idMaterial: bool}
 */
function g5v2Spec(string $arm): array
{
    return match ($arm) {
        'REF' => ['member' => 'fp', 'rule' => 'A', 'idMint' => false, 'idMaterial' => false],
        'NOUA2' => ['member' => 'fp', 'rule' => 'A', 'idMint' => false, 'idMaterial' => true],
        'NOUA2/B' => ['member' => 'fp', 'rule' => 'B', 'idMint' => false, 'idMaterial' => true],
        'NOUA2/C' => ['member' => 'fp', 'rule' => 'C', 'idMint' => false, 'idMaterial' => true],
        'D-NPID' => ['member' => 'id', 'rule' => 'A', 'idMint' => false, 'idMaterial' => true],
        'D-NPID/B' => ['member' => 'id', 'rule' => 'B', 'idMint' => false, 'idMaterial' => true],
        'D-NPID/C' => ['member' => 'id', 'rule' => 'C', 'idMint' => false, 'idMaterial' => true],
        'D-IDMINT' => ['member' => 'fp', 'rule' => 'A', 'idMint' => true, 'idMaterial' => true],
        default => throw new \LogicException("unknown arm $arm"),
    };
}

/** @return array{0: G1Cell, 1: G5Gate, 2: G4V2Host} FULL2 of G4 v2 with the arm's N_P member (G5Gate takes it as a constructor argument). */
function g5v2Cell(string $arm): array
{
    $s = g5v2Spec($arm);
    [, $uSrc, $u] = g4v2Arms()['FULL2'];
    $cell = new G1Cell('CAND', 'login', g4v2Params($uSrc, $u, 'refund'));
    $cell->adm ?? throw new \LogicException('CAND cell without admission');

    return [$cell, new G5Gate($cell->adm, $u, $s['member'], $s['idMint']), new G4V2Host('strict', true, true)];
}

// ---- NP-IN ---------------------------------------------------------------------------------------------------------------------

/** @var array{p: int, fp: int, id: int} counters of the audit since the last g5v2NpEmit() */
$GLOBALS['G5V2_NP'] = ['p' => 0, 'fp' => 0, 'id' => 0];

/**
 * NP-IN (PLAN.md §3): after a P-lane admission, the admission store's `p_device` sets are read (independently of the gate's variable).
 * A fingerprint arm: the member of the request's fingerprintHash is in a set and the member of its id component is in none. The id
 * arm: the reverse (a request with no id has only the fingerprint member). A violation aborts with exit 7.
 */
function g5v2NpAudit(G1Cell $cell, RateLimitContextDTO $c, string $member): void
{
    $adm = $cell->adm ?? throw new \LogicException('no admission');
    $store = $adm->store;
    if (! $store instanceof G1RefStore) {
        throw new \LogicException('NP-IN reads the reference store');
    }
    $d = $cell->lab->resolver->resolve($c);
    $h = static fn(string $v): string => substr(hash_hmac('sha256', $v, $adm->gen), 0, 24);
    $now = $cell->now();
    $has = static function (string $m) use ($store, $now): bool {
        foreach ($store->s as $set) {
            if (isset($set[$m]) && $set[$m] > $now) {
                return true;
            }
        }

        return false;
    };
    $fpM = $h((string) $d->fingerprintHash);
    $idHash = $d->deviceIdHash ?? null;
    $idM = $idHash === null ? null : $h($idHash);
    $inFp = $has($fpM);
    $inId = $idM !== null && $has($idM);
    $GLOBALS['G5V2_NP']['p']++;
    $GLOBALS['G5V2_NP']['fp'] += $inFp ? 1 : 0;
    $GLOBALS['G5V2_NP']['id'] += $inId ? 1 : 0;
    $ok = $member === 'fp' ? ($inFp && ! $inId) : ($idM === null ? $inFp : ($inId && ! $inFp));
    if (! $ok) {
        fwrite(STDERR, "G5 v2 NP-IN violation: member $member, fingerprint member present " . json_encode($inFp) . ', id member present ' . json_encode($inId) . "\n");
        exit(7);
    }
}

/** Emits and resets the NP-IN counters of the scenario part just run. */
function g5v2NpEmit(string $arm, string $scenario): void
{
    $n = $GLOBALS['G5V2_NP'];
    g4Emit('np', ['arm' => $arm, 'scenario' => $scenario, 'pAdmitted' => $n['p'], 'memberIsFingerprint' => $n['fp'], 'memberIsIdComponent' => $n['id']]);
    $GLOBALS['G5V2_NP'] = ['p' => 0, 'fp' => 0, 'id' => 0];
}

// ---- K4P rules -----------------------------------------------------------------------------------------------------------------

/** The scope the rule puts on the P-lane account penalty state of a request (null = the account-wide state of rule A, and every U-lane request). */
function g5v2Scope(string $rule, G4V2Host $host, ?string $acct, ?string $jti, string $lane): ?string
{
    if ($lane !== 'P' || $rule === 'A' || $acct === null) {
        return null;
    }

    return $rule === 'B' ? 'e' . ($host->epoch[$acct] ?? 0) : 'j' . $jti;
}

/** g5PenaltyCause with the K4P key of the rule's scope (the pipeline's k4p~scope key, PLAN.md §2.2). */
function g5v2PenaltyCause(G1Cell $cell, RateLimitContextDTO $c, string $d, string $lane, bool $idMaterial, ?string $scope): string
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
        $keys['K4P'] = $scope === null || $c->accountId === null
            ? $cell->k4p($c->accountId)
            : hash_hmac('sha256', "{$cell->lab->policy->getName()}:rate_limiter:k4p~{$scope}:v2:prod:{$c->accountId}", Lab::SECRET);
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
 * The G5 replay (g5Replay: control flow and output encoding unchanged) with (1) the K4P scope of the arm's rule set on the pipeline
 * before the request's first command (rules B and C only; rule A leaves the pipeline untouched, as G5) and (2) the NP-IN audit after
 * every P-lane admission.
 *
 * @param array{member: string, rule: string, idMint: bool, idMaterial: bool} $spec
 * @return array<string, mixed>
 */
function g5v2Replay(G1Cell $cell, G5Gate $gate, G4V2Host $host, array $rows, string $flow, array $spec): array
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
        $scope = g5v2Scope($spec['rule'], $host, $c->accountId, $jti, $lane);
        if ($spec['rule'] !== 'A') {
            $cell->lab->pipeline->g5v2SetK4pScope($scope);
        }
        $denied = false;
        $cause = '-';
        $reached = false;
        $res = $cell->cmd($c, 'check', $lane);
        if ($res['d'] !== 'A') {
            $denied = true;
            $cause = g5v2PenaltyCause($cell, $c, $res['d'], $lane, $spec['idMaterial'], $scope);
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
                    $rec = $cell->cmd($c, $r['outcome'], $lane);
                    if ($lane === 'U') {
                        $gate->uOutcome($cell, $c, $r['outcome'], $a['tb']);
                    } else {
                        $gate->pOutcome($a['pkeys'], $r['outcome']);
                    }
                    if ($rec['d'] !== 'A') {
                        $denied = true;
                        $cause = g5v2PenaltyCause($cell, $c, $rec['d'], $lane, $spec['idMaterial'], $scope);
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

function g5v2Step(G1Cell $cell, G5Gate $gate, G4V2Host $host, array $row, array $spec): array
{
    $r = g5v2Replay($cell, $gate, $host, [$row], 'pre+out', $spec);

    return ['d' => $r['dec'], 'lane' => $r['lanes'], 'reach' => $r['reach'], 'cause' => array_key_first($r['legitDeniedByCause'] + $r['attackDeniedByCause'])];
}

/** True when some instance id of the rows resolves to two or more distinct fingerprintHash values (UA or client fingerprint varying; PLAN.md §5.3). */
function g5v2MatVarying(G1Cell $cell, array $rows): bool
{
    $seen = [];
    foreach ($rows as $r) {
        if (isset($r['event']) || ($r['inst'] ?? null) === null) {
            continue;
        }
        $seen[$r['inst']][(string) $cell->lab->resolver->resolve($r['ctx'])->fingerprintHash] = true;
    }
    foreach ($seen as $hashes) {
        if (count($hashes) > 1) {
            return true;
        }
    }

    return false;
}

// ---- the STEAL family (PLAN.md §4.2) -------------------------------------------------------------------------------------------

function g5v2Ua(int $major): string
{
    return "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/$major.0.0.0 Safari/537.36";
}

/**
 * STEAL (password-change variant) or STEAL2 (a second credential holder). With $second = false the encoding and the control flow are
 * those of the archived g5Steal(), so the row of the same arm equals the archive's (V3).
 *
 * @return array<string, mixed>
 */
function g5v2StealRun(string $arm, bool $second): array
{
    $spec = g5v2Spec($arm);
    $acct = $second ? 'acct-s2' : 'acct-st';
    $ownerCtx = new RateLimitContextDTO('198.51.100.141', FIREFOX_130, $acct, null, null, false, [], false, null);
    $o2Ctx = new RateLimitContextDTO('198.51.100.142', CHROME, $acct, null, null, false, [], false, null);
    [$cell, $gate, $host] = g5v2Cell($arm);
    $parts = [
        [g4v2Issue(0, 'owner', $acct, 'i:st'), ['t' => 600, 'event' => static fn(G4V2Host $h, int $now) => $h->copyJar('owner', 'thief', 'i:st')]],
        array_map(static fn(int $t): array => g4v2Row1($t, $ownerCtx, 'O', 'owner', 'ok', 'i:st', 'owner'), range(60, 43199, 7200)),
        array_map(static fn(int $t): array => g4v2Row1($t, g3Attacker(intdiv($t, 15), $acct), 'X', 'thief', 'fail', 'i:st', 'thief'), range(615, 43199, 15)),
    ];
    if ($second) {
        $parts[] = [g4v2Issue(0, 'owner2', $acct, 'i:o2')];
        $parts[] = array_map(static fn(int $t): array => g4v2Row1($t, $o2Ctx, 'O', 'owner2', 'ok', 'i:o2', 'owner2'), range(120, 43199, 600));
    }
    $pre = g4v2Sort(array_merge(...$parts));
    $win = [];
    $day = 0;
    $ownerDen = 0;
    $o2Den = 0;
    $o2DenCauses = [];
    foreach ($pre as $row) {
        if (isset($row['event'])) {
            g5v2Replay($cell, $gate, $host, [$row], 'pre+out', $spec);
            continue;
        }
        $s = g5v2Step($cell, $gate, $host, $row, $spec);
        if ($row['holder'] === 'thief' && $s['lane'] === 'P' && $s['reach'] === '1') {
            $win[intdiv($row['t'], 600)] = ($win[intdiv($row['t'], 600)] ?? 0) + 1;
            $day++;
        }
        $ownerDen += $row['holder'] === 'owner' && $s['d'] !== 'A' ? 1 : 0;
        if ($row['holder'] === 'owner2' && $s['d'] !== 'A') {
            $o2Den++;
            $o2DenCauses[$s['cause']] = ($o2DenCauses[$s['cause']] ?? 0) + 1;
        }
    }
    g5v2Replay($cell, $gate, $host, [['t' => 43200, 'event' => static fn(G4V2Host $h, int $now) => $h->passwordChange('owner', $acct, 'i:st', $now)]], 'pre+out', $spec);
    $afterP = 0;
    $afterU = 0;
    $ownerWait = null;
    $ownerLane = null;
    $ownerCauses = [];
    $next = 43260;
    $o2Wait = null;
    $o2Lane = null;
    $o2Causes = [];
    $o2Next = 43260;
    for ($t = 43215; $t < 86400; $t += 15) {
        $s = g5v2Step($cell, $gate, $host, g4v2Row1($t, g3Attacker(intdiv($t, 15), $acct), 'X', 'thief', 'fail', 'i:st', 'thief'), $spec);
        if ($s['lane'] === 'P' && $s['reach'] === '1') {
            $afterP++;
            $win[intdiv($t, 600)] = ($win[intdiv($t, 600)] ?? 0) + 1;
        }
        $afterU += $s['lane'] === 'U' && $s['reach'] === '1' ? 1 : 0;
        if ($ownerWait === null && $next === $t) {
            $s = g5v2Step($cell, $gate, $host, g4v2Row1($next, $ownerCtx, 'O', 'owner', 'ok', 'i:st', 'owner'), $spec);
            if ($s['d'] === 'A') {
                $ownerWait = $next - 43260;
                $ownerLane = $s['lane'];
            } else {
                $ownerCauses[$s['cause']] = ($ownerCauses[$s['cause']] ?? 0) + 1;
                $next += 60;
            }
        }
        if ($second && $o2Wait === null && $o2Next === $t) {
            $s = g5v2Step($cell, $gate, $host, g4v2Row1($o2Next, $o2Ctx, 'O', 'owner2', 'ok', 'i:o2', 'owner2'), $spec);
            if ($s['d'] === 'A') {
                $o2Wait = $o2Next - 43260;
                $o2Lane = $s['lane'];
            } else {
                $o2Causes[$s['cause']] = ($o2Causes[$s['cause']] ?? 0) + 1;
                $o2Next += 60;
            }
        }
    }
    ksort($ownerCauses);
    ksort($o2Causes);
    ksort($o2DenCauses);
    $row = ['fixture' => $second ? 'STEAL2' : 'STEAL', 'arm' => $arm, 'thiefPMaxPerWindow' => $win === [] ? 0 : max($win), 'thiefPBeforeChange' => $day,
        'thiefPAfterChange' => $afterP, 'thiefUAfterChange' => $afterU, 'ownerDeniedBeforeChange' => $ownerDen,
        'ownerWaitAfterChange' => $ownerWait, 'ownerLaneAtAdmission' => $ownerLane, 'ownerDeniedAfterChangeByCause' => $ownerCauses,
        'pwBinds' => $host->pwBinds];

    return $second ? $row + ['o2DeniedBeforeChange' => $o2Den, 'o2DeniedBeforeByCause' => $o2DenCauses, 'o2WaitAfterChange' => $o2Wait,
        'o2LaneAtAdmission' => $o2Lane, 'o2DeniedAfterChangeByCause' => $o2Causes] : $row;
}

/**
 * MULTITOK: eight credentials of one account in the attacker's hands, round robin, no password change. With $static each credential is
 * presented from one fixed (IP, UA) pair (eight presentations, so N_P on the fingerprint does not bind first); otherwise IP and UA rotate on
 * every request. @return array<string, mixed>
 */
function g5v2Multitok(string $arm, bool $static): array
{
    $spec = g5v2Spec($arm);
    [$cell, $gate, $host] = g5v2Cell($arm);
    $rows = [];
    for ($k = 1; $k <= 8; $k++) {
        $rows[] = g4v2Issue(0, 'mt' . $k, 'acct-mt', 'i:mt' . $k);
    }
    for ($t = 60, $n = 0; $t < 86400; $t += 15, $n++) {
        $k = $n % 8 + 1;
        $rows[] = g4v2Row1($t, g3Attacker($static ? $k : $n, 'acct-mt'), 'X', 'mt' . $k, 'fail', 'i:mt' . $k, 'mt' . $k);
    }
    $r = g5v2Replay($cell, $gate, $host, g4v2Sort($rows), 'pre+out', $spec);
    $p = 0;
    $u = 0;
    for ($i = 0, $n = strlen($r['lanes']); $i < $n; $i++) {
        $p += $r['lanes'][$i] === 'P' && $r['reach'][$i] === '1' ? 1 : 0;
        $u += $r['lanes'][$i] === 'U' && $r['reach'][$i] === '1' ? 1 : 0;
    }

    return ['fixture' => $static ? 'MULTITOK-S' : 'MULTITOK', 'arm' => $arm, 'attempts' => strlen($r['lanes']), 'pAdmitted' => $p, 'uReach' => $u, 'attackDeniedByCause' => $r['attackDeniedByCause']];
}

/** UAX-NP-K: twelve successful logins, one id and credential, k distinct browser majors (k = 12 is G5's UAX-NP). @return array<string, mixed> */
function g5v2NpK(string $arm, int $k, string $fixture): array
{
    $spec = g5v2Spec($arm);
    [$cell, $gate, $host] = g5v2Cell($arm);
    $rows = [g4v2Issue(0, 'h:np', 'acct-np', 'i:np')];
    for ($i = 0; $i < 12; $i++) {
        $rows[] = g4v2Row1(3600 * $i + 60, new RateLimitContextDTO('198.51.100.60', g5v2Ua(120 + intdiv($i * $k, 12)), 'acct-np', null, null, false, [], true, null), 'O', 'np', 'ok', 'i:np', 'h:np');
    }
    $r = g5v2Replay($cell, $gate, $host, g4v2Sort($rows), 'pre+out', $spec);
    $row = ['fixture' => $fixture, 'arm' => $arm, 'lanes' => $r['lanes'], 'dec' => $r['dec'], 'refused' => strlen($r['dec']) - substr_count($r['dec'], 'A'),
        'causes' => $r['legitDeniedByCause']];

    return $fixture === 'UAX-NP-K' ? $row + ['k' => $k] : $row;
}
