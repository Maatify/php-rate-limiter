<?php

declare(strict_types=1);

// G6 budgets study (#97, step 4) — harness. EVIDENCE ONLY: not package runtime, never wired into src/.
//
// Reuses read-only: the archived G5 v2 library (../../../g5/identity_layer_v2/lib/g5v2.php: g5v2Cell, g5v2Spec, g5v2NpAudit, g5v2NpEmit,
// g5v2MatVarying, the STEAL / NP helpers) and through it G5, G4 v2, G4, G3 v2, G3 and G1. Adds (PLAN.md §2, §3, §4):
//   g6Provenance   the pinned G6UK build (G5V2 + patches/g6-uk-lane.diff); every other build is the archived one
//   g6Spec / Cell  the arms: NOUA2 (reference, UK lane never requested), UKD (UK lane on), D-UKBYP (G3 v2's `known` switch on the U gate)
//   g6Known        the pipeline's own predicate isKnownForAccount(), evaluated on the resolver's DTO
//   g6Replay       the G5 v2 replay with one change: a request on the U door that the Host knows is processed on pipeline lane UK (arm UKD)
//   g6StealRun     the thief side of the archived STEAL fixture, ported to g6Replay
//   g6PipeUk       the pipeline-level UK-lane fixtures (STABLE-i, KDL) with the UK keys
//   g6Tms*         the TMS family on REF / NOUA2 (assembled) and S0 (own process)

require_once dirname(__DIR__, 3) . '/g5/identity_layer_v2/lib/g5v2.php';

use Maatify\RateLimiter\DTO\DeviceIdentityDTO;
use Maatify\RateLimiter\DTO\RateLimitContextDTO;
use Maatify\RateLimiter\Service\DeviceIdentityResolver;
use Maatify\RateLimiter\Service\EvaluationPipeline;

const G6_PIPELINE_SHA256 = ['G6UK' => '924ce11890c8c0e5186a6740c574ca3cd4f2992eb8f7a3c453f8493ed4f8e7a7'];

/** Aborts (exit 4) unless the loaded pipeline (and, for G6UK, resolver and DTO) is exactly the claimed build. Other builds: the archived provenance. */
function g6Provenance(): string
{
    $b = g5Build();
    if (! isset(G6_PIPELINE_SHA256[$b])) {
        return g5v2Provenance();
    }
    $file = (string) (new \ReflectionClass(EvaluationPipeline::class))->getFileName();
    $sha = (string) hash_file('sha256', $file);
    $repo = realpath(dirname(__DIR__, 5) . '/src/Service/EvaluationPipeline.php');
    $res = (string) hash_file('sha256', (string) (new \ReflectionClass(DeviceIdentityResolver::class))->getFileName());
    $dto = (string) hash_file('sha256', (string) (new \ReflectionClass(DeviceIdentityDTO::class))->getFileName());
    $ok = getenv('G1_ARM') === 'CAND' && $repo !== false && realpath($file) !== $repo && G6_PIPELINE_SHA256[$b] === $sha
        && $res === G5_RESOLVER_SHA256 && $dto === G5_DTO_SHA256;
    if (! $ok) {
        fwrite(STDERR, "G6 provenance violation: build $b loaded $file ($sha)\n");
        exit(4);
    }

    return $b;
}

/**
 * The arm table of PLAN.md §2.1 (all with the NOUA2 rules: N_P member on the fingerprint, K5 on the id, K4P rule A).
 *
 * @return array{member: string, rule: string, idMint: bool, idMaterial: bool, uk: bool, knownRoute: bool}
 */
function g6Spec(string $arm): array
{
    $base = g5v2Spec('NOUA2');

    return match ($arm) {
        'NOUA2' => $base + ['uk' => false, 'knownRoute' => false],
        'UKD' => $base + ['uk' => true, 'knownRoute' => false],
        'D-UKBYP' => $base + ['uk' => false, 'knownRoute' => true],
        default => throw new \LogicException("unknown arm $arm"),
    };
}

/** @return array{0: G1Cell, 1: G5Gate, 2: G4V2Host} FULL2 of G4 v2 with the NOUA2 member; D-UKBYP puts G3 v2's `known` switch on the U gate. */
function g6Cell(string $arm): array
{
    $s = g6Spec($arm);
    [, $uSrc, $u] = g4v2Arms()['FULL2'];
    if ($s['knownRoute']) {
        $u['known'] = true;
    }
    $cell = new G1Cell('CAND', 'login', g4v2Params($uSrc, $u, 'refund'));
    $cell->adm ?? throw new \LogicException('CAND cell without admission');

    return [$cell, new G5Gate($cell->adm, $u, $s['member'], $s['idMint']), new G4V2Host('strict', true, true)];
}

/** The pipeline's isKnownForAccount() on the resolver's DTO (PLAN.md §2.1). */
function g6Known(G1Cell $cell, RateLimitContextDTO $c): bool
{
    $d = $cell->lab->resolver->resolve($c);

    return $d->isTrustedSession || $d->isDevicePreviouslyVerifiedForAccount;
}

/** G1Cell::cmd with the pipeline lane given explicitly (door lane for the bookkeeping, pipeline lane for the study switch). @return array<string, mixed> */
function g6Cmd(G1Cell $cell, RateLimitContextDTO $c, string $cmd, string $door, string $pipe): array
{
    $cell->lab->pipeline->g1SetLane($pipe);
    if ($c->accountId !== null) {
        $cell->accounts[$c->accountId] = true;
        if ($door === 'P') {
            $cell->pAccounts[$c->accountId] = true;
        }
    }

    return $cell->lab->run($c, $cmd);
}

/** The K4 key of the UK state (the pipeline's k4uk, hashed as G1Cell::k4p hashes k4p). */
function g6K4ukKey(Lab $lab, ?string $acct): ?string
{
    return $acct === null ? null : hash_hmac('sha256', "{$lab->policy->getName()}:rate_limiter:k4uk:v2:prod:{$acct}", Lab::SECRET);
}

/** g5v2PenaltyCause for the door lane U or P, and for the pipeline lane UK (K4 is read from the k4uk key and named K4UK). */
function g6PenaltyCause(G1Cell $cell, RateLimitContextDTO $c, string $d, string $door, string $pipe): string
{
    if ($pipe !== 'UK') {
        return g5v2PenaltyCause($cell, $c, $d, $door, true, null);
    }
    $now = $cell->now();
    $keys = $cell->lab->keys($c);
    if ($c->accountId !== null) {
        $dev = $cell->lab->resolver->resolve($c);
        if (($dev->deviceIdHash ?? null) !== null) {
            $keys['K5'] = hash_hmac('sha256', "{$cell->lab->policy->getName()}:rate_limiter:k5:v2:prod:{$c->accountId}:{$dev->deviceIdHash}", $cell->lab->secret);
        }
    }
    $keys['K4'] = null;
    $keys['K4UK'] = g6K4ukKey($cell->lab, $c->accountId);
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
 * The G5 v2 replay (g5v2Replay; rule A only) with ONE change: for arm UKD a request that is on the U door and Host-known is processed on the
 * pipeline lane UK. The admission layer stays on the U door (U_src, net failure cap, bucket with refund). With `uk` off the control flow, the
 * pipeline calls and the output encoding are those of g5v2Replay, so NOUA2 reproduces its archive (V0). `routedUk` is added only when the arm
 * routes (UKD) so that a NOUA2 row equals its archive row.
 *
 * @param array{member: string, rule: string, idMint: bool, idMaterial: bool, uk: bool, knownRoute: bool} $spec
 * @return array<string, mixed>
 */
function g6Replay(G1Cell $cell, G5Gate $gate, G4V2Host $host, array $rows, string $flow, array $spec): array
{
    g4v2AssertInput($rows);
    $dec = $lanes = $reach = $kinds = '';
    $byCause = [];
    $atkByCause = [];
    $routed = 0;
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
        if ($spec['uk'] && $lane === 'U' && g6Known($cell, $c)) {
            $pipe = 'UK';
            $routed++;
        }
        $denied = false;
        $cause = '-';
        $reached = false;
        $res = g6Cmd($cell, $c, 'check', $lane, $pipe);
        if ($res['d'] !== 'A') {
            $denied = true;
            $cause = g6PenaltyCause($cell, $c, $res['d'], $lane, $pipe);
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

    return $spec['uk'] ? $out + ['routedUk' => $routed] : $out;
}

function g6Step(G1Cell $cell, G5Gate $gate, G4V2Host $host, array $row, array $spec): array
{
    $r = g6Replay($cell, $gate, $host, [$row], 'pre+out', $spec);

    return ['d' => $r['dec'], 'lane' => $r['lanes'], 'reach' => $r['reach'], 'cause' => array_key_first($r['legitDeniedByCause'] + $r['attackDeniedByCause'])];
}

// ---- STEAL (thief side) ----------------------------------------------------------------------------------------------------------

/**
 * The archived STEAL fixture (g5v2StealRun with $second = false), ported to g6Replay: same rows, same control flow, same encoding, so the NOUA2 row
 * equals the archive's (V0). @return array<string, mixed>
 */
function g6StealRun(string $arm): array
{
    $spec = g6Spec($arm);
    $acct = 'acct-st';
    $ownerCtx = new RateLimitContextDTO('198.51.100.141', FIREFOX_130, $acct, null, null, false, [], false, null);
    [$cell, $gate, $host] = g6Cell($arm);
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
            g6Replay($cell, $gate, $host, [$row], 'pre+out', $spec);
            continue;
        }
        $s = g6Step($cell, $gate, $host, $row, $spec);
        if ($row['holder'] === 'thief' && $s['lane'] === 'P' && $s['reach'] === '1') {
            $win[intdiv($row['t'], 600)] = ($win[intdiv($row['t'], 600)] ?? 0) + 1;
            $day++;
        }
        $ownerDen += $row['holder'] === 'owner' && $s['d'] !== 'A' ? 1 : 0;
    }
    g6Replay($cell, $gate, $host, [['t' => 43200, 'event' => static fn(G4V2Host $h, int $now) => $h->passwordChange('owner', $acct, 'i:st', $now)]], 'pre+out', $spec);
    $afterP = 0;
    $afterU = 0;
    $ownerWait = null;
    $ownerLane = null;
    $ownerCauses = [];
    $next = 43260;
    for ($t = 43215; $t < 86400; $t += 15) {
        $s = g6Step($cell, $gate, $host, g4v2Row1($t, g3Attacker(intdiv($t, 15), $acct), 'X', 'thief', 'fail', 'i:st', 'thief'), $spec);
        if ($s['lane'] === 'P' && $s['reach'] === '1') {
            $afterP++;
            $win[intdiv($t, 600)] = ($win[intdiv($t, 600)] ?? 0) + 1;
        }
        $afterU += $s['lane'] === 'U' && $s['reach'] === '1' ? 1 : 0;
        if ($ownerWait === null && $next === $t) {
            $s = g6Step($cell, $gate, $host, g4v2Row1($next, $ownerCtx, 'O', 'owner', 'ok', 'i:st', 'owner'), $spec);
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

// ---- pipeline level, UK lane -------------------------------------------------------------------------------------------------------

/** Allowance counter of the UK state (the pipeline's known_device_allowance_uk). */
function g6AllowanceUkKey(Lab $lab, string $acct): string
{
    return hash_hmac('sha256', "{$lab->policy->getName()}:rate_limiter:aux:known_device_allowance_uk:v1:prod:{$acct}", $lab->secret);
}

/**
 * Pipeline-level fixtures on the UK lane (PLAN.md §4.2): STABLE-i as G5's g5Pipe (reach, maximum k4uk score) and KDL. Lane UK reads its own keys.
 *
 * @return array<string, mixed>
 */
function g6PipeUk(string $fixture): array
{
    $lab = g5Lab('UK');
    $k4 = g6K4ukKey($lab, 'acct-x');
    $reach = 0;
    $dec = '';
    if (str_starts_with($fixture, 'STABLE-')) {
        $iv = (int) substr($fixture, 7);
        $maxK4 = 0;
        for ($t = 0; $t < 86400; $t += $iv) {
            $lab->at($t);
            g5Attempt($lab, g5Kctx(G5_UA_A, 'i:kd'), $dec, $reach);
            $maxK4 = max($maxK4, (int) $lab->store->get((string) $k4)?->value);
        }

        return ['reach' => $reach, 'maxK4score' => $maxK4, 'allowance' => $lab->store->getBudget(g6AllowanceUkKey($lab, 'acct-x'))?->count];
    }
    $c = g5Kctx(G5_UA_A, 'i:kd');
    $maxK5 = $maxK4 = 0;
    for ($i = 0; $i < 12; $i++) {
        $lab->at($i * 60);
        g5Attempt($lab, $c, $dec, $reach);
        $maxK5 = max($maxK5, (int) g5BlockLevel($lab, g5K5Key($lab, $c, 'U')));
        $maxK4 = max($maxK4, (int) g5BlockLevel($lab, $k4));
    }

    return ['dec' => $dec, 'reach' => $reach, 'maxK5Level' => $maxK5, 'maxK4Level' => $maxK4, 'secondDevice' => $lab->run(g5Kctx(G5_UA_A, 'i:other'), 'check')['d']];
}

// ---- arm 2: the TMS family ---------------------------------------------------------------------------------------------------------

/** The streams of arm 2 (PLAN.md §2.2): the three cells and the two controls of V3. */
const G6_TMS_STREAMS = ['TMS', 'TMSd', 'TMX'];
const G6_TMS_CONTROLS = ['N12', 'SP1n'];

/**
 * Per-row measures of a TMS row, from the plain rows and the replay strings: reach split by lane, reach in the first 600 s, maximum reach on one account.
 *
 * @return array<string, mixed>
 */
function g6TmsRow(array $rows, array $r): array
{
    $plain = array_values(array_filter($rows, static fn(array $x): bool => ! isset($x['event'])));
    $byLane = ['U' => 0, 'P' => 0];
    $first600 = 0;
    $acct = [];
    for ($i = 0, $n = strlen($r['reach']); $i < $n; $i++) {
        if ($r['reach'][$i] !== '1') {
            continue;
        }
        $byLane[$r['lanes'][$i]]++;
        $first600 += $plain[$i]['t'] < 600 ? 1 : 0;
        $a = (string) $plain[$i]['ctx']->accountId;
        $acct[$a] = ($acct[$a] ?? 0) + 1;
    }

    return ['reachU' => $byLane['U'], 'reachP' => $byLane['P'], 'reachFirst600' => $first600, 'reachPerAccountMax' => $acct === [] ? 0 : max($acct), 'accountsReached' => count($acct)];
}
