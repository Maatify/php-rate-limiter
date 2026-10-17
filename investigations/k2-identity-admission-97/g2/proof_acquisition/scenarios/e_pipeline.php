<?php

declare(strict_types=1);

// G2 section E (PLAN.md §4–§5 E): owner proof acquisition on the REAL G1 candidate pipeline, DAOF build (trace-based, no DA
// and no flood punishment), unchanged. The proof column of each owner row is set only when the G2 boundary accepts the
// owner's proof token for that row's device; G1's lane mapping M1 then puts the row in P. Run with
//   G1_ARM=CAND G1_BUILD=DAOF LAB_ROOT=<results/work/daof>

require dirname(__DIR__, 3) . '/g1/verify/lib/verify.php';
require_once dirname(__DIR__) . '/lib/proof.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

$prov = g1Provenance();
if ($prov['build'] !== 'DAOF') {
    fwrite(STDERR, "e_pipeline must run on the DAOF build\n");
    exit(4);
}
$p = g1Params('R');
const E_T = 'tenant-a';

/** @return array{t: int, ctx: RateLimitContextDTO, proof: ?string, kind: string, own: bool, who: string, outcome: string} */
function eRow(int $t, RateLimitContextDTO $c, ?string $proof, string $kind, string $out): array
{
    return ['t' => $t, 'ctx' => $c, 'proof' => $proof, 'kind' => $kind, 'own' => false, 'who' => $kind, 'outcome' => $out];
}

/**
 * Acquisition ready at $t + 5. Case A: challenge at $t, assertion at $t + 5. Case B: issue at $t - 20, read the mailbox, redeem
 * at $t + 5.
 *
 * @param non-empty-string $sk
 * @return array{token: ?string, steps: string}
 */
function eAcquire(G2Host $h, string $acct, string $sk, string $cred, int $t, string $src, string $dev): array
{
    if ($h->arm['proof'] === 'B') {
        $i = $h->issue($t - 20, 'owner', $src, E_T, $acct, 'login', $dev);
        $link = $h->readMailbox($t, E_T, $acct, 'login', $dev);
        $r = $link === null ? ['ok' => false, 'why' => 'mailbox-empty', 'token' => null] : $h->redeem($t + 5, 'owner', $src, E_T, $acct, 'login', $link, $dev);

        return ['token' => $r['token'], 'steps' => 'issue:' . ($i['ok'] ? 'ok' : $i['why']) . '>redeem:' . ($r['ok'] ? 'ok' : $r['why'])];
    }
    $c = $h->challenge($t, 'owner', $src, E_T, $acct, 'login');
    if ($c['challenge'] === null) {
        return ['token' => null, 'steps' => 'challenge:' . $c['why']];
    }
    $a = $h->assert(
        $t + 5,
        'owner',
        $src,
        E_T,
        $acct,
        'login',
        $c['challenge'],
        $cred,
        sodium_crypto_sign_detached(G2Host::signedMessage(E_T, $c['challenge']), $sk),
        $dev,
    );

    return ['token' => $a['token'], 'steps' => 'challenge:ok>assert:' . ($a['ok'] ? 'ok' : $a['why'])];
}

/**
 * Sorts population and owner rows by time (owner last within a second) and replays them on a fresh candidate cell.
 *
 * @param list<array{t: int, ctx: RateLimitContextDTO, proof: ?string, kind: string, own: bool, who: string, outcome: string}> $rows
 * @param array<string, int> $p
 * @return array<string, mixed>
 */
function eRun(array $rows, array $p): array
{
    usort($rows, static fn(array $a, array $b): int => [$a['t'], $a['kind'] === 'O' ? 1 : 0] <=> [$b['t'], $b['kind'] === 'O' ? 1 : 0]);
    $cell = new G1Cell('CAND', 'login', $p);
    $r = g1VReplay($cell, $rows, 'pre+out', ['trace' => true, 'c9' => true]);
    $owner = ['dec' => '', 'lanes' => '', 'causes' => []];
    foreach ($rows as $i => $x) {
        if ($x['kind'] !== 'O') {
            continue;
        }
        $owner['dec'] .= $r['dec'][$i];
        $owner['lanes'] .= $r['lanes'][$i];
        $owner['causes'][] = $r['evOrigins'][$i] ?? '-';
    }
    $pCounters = [];
    $st = $cell->adm?->store;
    if ($st instanceof G1RefStore) {
        foreach ($st->c as $key => [$val]) {
            if (preg_match('/:(pacct|pacct_day|pproof|pproof_day):/', $key, $m) === 1) {
                $pCounters[$m[1]] = ($pCounters[$m[1]] ?? 0) + $val;
            }
        }
        ksort($pCounters);
    }
    $atkReach = 0;
    for ($i = 0, $n = strlen($r['kinds']); $i < $n; $i++) {
        $atkReach += $r['kinds'][$i] === 'X' && $r['reach'][$i] === '1' ? 1 : 0;
    }

    return ['owner' => $owner, 'pCounters' => $pCounters, 'c9Violations' => count($r['c9']['violations']), 'c9Checked' => $r['c9']['checked'],
        'populationAttackerReach' => $atkReach, 'benignOrigins' => $r['benignOrigins']];
}

/**
 * Declared couplings (PLAN.md §5 E iv). Returns the undeclared labels of one owner decision.
 *
 * @return list<string>
 */
function eUndeclared(string $cause, string $lane): array
{
    if ($cause === '-' || str_starts_with($cause, 'cap:')) {
        return [];
    }
    $labels = explode('+', (string) preg_replace('/^origin:/', '', $cause));
    // own-device K5 family: the K5 score / block and DEC-021's device watcher, whose escalation is a K5 HARD_BLOCK on the
    // same account + device only (label mapping disclosed in the report)
    $declared = $lane === 'U' ? ['k5', 'anti_equilibrium:device', 'k4'] : ['k5', 'anti_equilibrium:device', 'k4p'];

    return array_values(array_filter($labels, static fn(string $l): bool => ! in_array($l, $declared, true)));
}

/**
 * @param array<string, mixed> $run
 * @param array<string, mixed> $ctl
 * @return array<string, mixed>
 */
function eCompare(array $run, array $ctl): array
{
    $diff = [];
    $bad = [];
    $pop = 0;
    $o = $run['owner'];
    $c = $ctl['owner'];
    for ($k = 0, $n = strlen($o['dec']); $k < $n; $k++) {
        $pop += preg_match('/distributed_account_attack|flood/', $o['causes'][$k]) === 1 && $o['lanes'][$k] === 'P' ? 1 : 0;
        if ($o['dec'][$k] === $c['dec'][$k] && $o['lanes'][$k] === $c['lanes'][$k]) {
            continue;
        }
        $u = array_merge(eUndeclared($o['causes'][$k], $o['lanes'][$k]), eUndeclared($c['causes'][$k], $c['lanes'][$k]));
        $diff[] = ['k' => $k, 'lane' => $o['lanes'][$k], 'run' => $o['dec'][$k] . ' ' . $o['causes'][$k], 'control' => $c['dec'][$k] . ' ' . $c['causes'][$k],
            'undeclared' => $u];
        if ($u !== []) {
            $bad[] = $k;
        }
    }

    return ['identical' => $o['dec'] === $c['dec'] && $o['lanes'] === $c['lanes'], 'differences' => $diff, 'unattributed' => $bad,
        'populationOriginDenials' => $pop, 'pCountersIdentical' => $run['pCounters'] === $ctl['pCounters'],
        'ownerPAdmissionsRun' => $run['pCounters']['pacct'] ?? 0, 'ownerPAdmissionsControl' => $ctl['pCounters']['pacct'] ?? 0];
}

$emit = static function (array $row): void {
    echo json_encode(['s' => 'e'] + $row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
};
$ctx = static fn(string $ip, string $ua, string $acct, ?string $sid, bool $trusted, bool $verified): RateLimitContextDTO
    => new RateLimitContextDTO($ip, $ua, $acct, null, $sid, $trusted, [], $verified, null);

// ---- E1 / E3: new device acquires a proof while the account's population fails; E3 adds the owner's own known tablet ----
$e1 = static function (string $arm, bool $population, bool $tablet) use ($ctx): array {
    $acct = 'acct-e1';
    $nat = '198.51.100.170';
    $h = new G2Host(g2Arm($arm), new G2RefPathStore());
    [$sk, $cred] = $h->register(E_T, $acct);
    $bd = new G2Boundary($h);
    $dev = 'sd-e1-new';
    $rows = [eRow(300, $ctx($nat, FIREFOX_130, $acct, $dev, false, false), null, 'O', 'ok')];
    $acq = eAcquire($h, $acct, $sk, $cred, 305, $nat, $dev);
    foreach (['fail', 'ok', 'fail', 'fail', 'ok', 'ok', 'fail', 'ok'] as $k => $out) {
        $t = 320 + 120 * $k;
        $proven = $bd->lane($t, E_T, $acct, 'login', $dev, $acq['token'])['lane'] === 'P';
        $rows[] = eRow($t, $ctx($nat, FIREFOX_130, $acct, $dev, false, false), $proven ? $acct : null, 'O', $out);
    }
    if ($population) {
        for ($i = 0; $i < 6; $i++) {
            for ($t = 10 + 10 * $i; $t < 1500; $t += 60) {
                $rows[] = eRow($t, $ctx($nat, k1Ua(200 + $i), $acct, 'sd-e1-x' . $i, false, false), null, 'X', 'fail');
            }
        }
        for ($t = 27, $n = 0; $t < 1500; $t += 20, $n++) {
            $rows[] = eRow($t, $ctx('192.0.2.' . (60 + $n % 20), k1Ua(100 + $n % 20), $acct, null, false, false), null, 'X', 'fail');
        }
    }
    if ($tablet) {
        for ($t = 30; $t <= 630; $t += 60) {
            $rows[] = eRow($t, $ctx($nat, CHROME, $acct, 'sd-e1-tablet', false, true), null, 'L', 'fail');
        }
    }

    return [$rows, $acq['steps']];
};

// ---- E2: MXE-shaped proof expiry on the same device, re-acquisition at 900 s ----
$e2 = static function (string $arm, bool $population, bool $mostlyOk = false) use ($ctx): array {
    $acct = 'acct-e2';
    $ip = '198.51.100.160';
    $dev = 'sd-e2';
    $h = new G2Host(g2Arm($arm), new G2RefPathStore());
    [$sk, $cred] = $h->register(E_T, $acct);
    $bd = new G2Boundary($h);
    $first = eAcquire($h, $acct, $sk, $cred, -1205, $ip, $dev);  // proof valid until 600 s
    $second = eAcquire($h, $acct, $sk, $cred, 900, $ip, $dev);   // re-acquired after expiry
    $rows = [];
    $k = 0;
    for ($t = 0, $n = 0; $t < 2400; $t += 20, $n++) {
        if ($t % 120 === 0) {
            // as G1 MXE, `$n % 3` is 0 on every owner row (n advances by 6), so this owner fails every attempt; the post-hoc
            // diagnostic owner fails only every third attempt
            $out = $mostlyOk ? ($k++ % 3 === 2 ? 'fail' : 'ok') : ($n % 3 === 0 ? 'fail' : 'ok');
            $tok = $t < 905 ? $first['token'] : $second['token'];
            $proven = $bd->lane($t, E_T, $acct, 'login', $dev, $tok)['lane'] === 'P';
            $rows[] = eRow($t, $ctx($ip, FIREFOX_130, $acct, $dev, $proven, false), $proven ? $acct : null, 'O', $out);
        }
        if ($population && $t > 0) {
            $rows[] = eRow($t + 7, $ctx('192.0.2.' . (60 + $n % 25), k1Ua(100 + $n % 25), $acct, null, false, false), null, 'X', 'fail');
        }
    }

    return [$rows, $first['steps'] . ' | ' . $second['steps']];
};

// ---- E4: benign household devices behind the owner's NAT on the same account ----
$e4 = static function (string $arm, bool $population) use ($ctx): array {
    $acct = 'acct-e4';
    $nat = '198.51.100.180';
    $dev = 'sd-e4-new';
    $h = new G2Host(g2Arm($arm), new G2RefPathStore());
    [$sk, $cred] = $h->register(E_T, $acct);
    $bd = new G2Boundary($h);
    $acq = eAcquire($h, $acct, $sk, $cred, 85, $nat, $dev);
    $rows = [];
    foreach ([92, 212, 332] as $t) {
        $proven = $bd->lane($t, E_T, $acct, 'login', $dev, $acq['token'])['lane'] === 'P';
        $rows[] = eRow($t, $ctx($nat, FIREFOX_130, $acct, $dev, false, false), $proven ? $acct : null, 'O', 'ok');
    }
    if ($population) {
        for ($i = 0; $i < 6; $i++) {
            foreach ([10 + 10 * $i, 70 + 10 * $i] as $t) {
                $rows[] = eRow($t, $ctx($nat, k1Ua(300 + $i), $acct, 'sd-hh-' . $i, false, false), null, 'L', 'ok');
            }
        }
    }

    return [$rows, $acq['steps']];
};

$cases = [];
foreach (['PA', 'PB'] as $arm) {
    $cases += [
        "$arm E1 ACQ-POP" => [$arm, fn() => $e1($arm, true, false), fn() => $e1($arm, false, false)],
        "$arm E2 ACQ-EXPIRY" => [$arm, fn() => $e2($arm, true), fn() => $e2($arm, false)],
        "$arm E3 ACQ-KD" => [$arm, fn() => $e1($arm, true, true), fn() => $e1($arm, false, false)],
        "$arm E3 ACQ-KD vs tablet-only" => [$arm, fn() => $e1($arm, true, true), fn() => $e1($arm, false, true)],
        "$arm E4 ACQ-NAT-HH" => [$arm, fn() => $e4($arm, true), fn() => $e4($arm, false)],
        // POST-HOC DIAGNOSTIC, not pre-registered, excluded from every gate and from the verdict
        "$arm E2d ACQ-EXPIRY owner-mostly-ok (post-hoc diagnostic)" => [$arm, fn() => $e2($arm, true, true), fn() => $e2($arm, false, true)],
    ];
}
foreach ($cases as $name => [$arm, $withPop, $control]) {
    [$rowsP, $acqP] = $withPop();
    [$rowsC, $acqC] = $control();
    $run = eRun($rowsP, $p);
    $ctl = eRun($rowsC, $p);
    $emit(['fixture' => substr($name, strlen($arm) + 1), 'arm' => $arm, 'diagnostic' => str_contains($name, 'post-hoc'), 'build' => $prov['build'], 'pipeline_sha256' => $prov['pipeline_sha256'],
        'acquisitionRun' => $acqP, 'acquisitionControl' => $acqC, 'acquisitionIdentical' => $acqP === $acqC,
        'run' => $run['owner'], 'control' => $ctl['owner'], 'pCountersRun' => $run['pCounters'], 'pCountersControl' => $ctl['pCounters'],
        'c9Violations' => $run['c9Violations'], 'c9Checked' => $run['c9Checked'], 'populationAttackerReach' => $run['populationAttackerReach']]
        + eCompare($run, $ctl));
}
