<?php

declare(strict_types=1);

// G1 verification V03 — C4-H (VERIFY_PLAN.md §2, F126-G1-02). Candidate build.
// (a) Host mapping M1: every archived CAND lane re-derived from what the LIBRARY receives (RateLimitContextDTO: session id,
//     trusted flag, account), not from G1Cell::lane() or the stream labels.
// (b) Package boundary: negative tests B1 … B5. B4 / B5 are expected NOT to be enforced (no typed proof binding exists in the
//     isolated patch, and none may be built); their measured consequence is reported, the guarantee is UNPROVEN.

require dirname(__DIR__) . '/lib/verify.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

$prov = g1Provenance();
if ($prov['runtime'] !== 'CAND' || $prov['build'] !== 'CAND') {
    fwrite(STDERR, "v03 must run on the candidate build\n");
    exit(4);
}
$p = g1Params('R');

// (a) Re-derivation from the DTO: a session id naming the target account ("sess-<acct>" or "sess-<who>" for "acct-<who>"),
// trusted, non-empty. Everything else must be U.
$mismatch = [];
$checked = 0;
$pLanes = 0;
foreach (file(dirname(__DIR__, 2) . '/expected/m01_matrix.CAND.jsonl') as $line) {
    $x = json_decode($line, true);
    if ($x['arm'] !== 'CAND' || $x['set'] !== 'R' || $x['flow'] !== 'pre+out' || $x['shape'] === 'api') {
        continue;
    }
    $rows = g1Materialise($x['stream'], $x['shape'], $x['strategy']);
    foreach ($rows as $i => $r) {
        $c = $r['ctx'];
        $sid = (string) ($c->sessionDeviceId ?? '');
        $acct = (string) ($c->accountId ?? '');
        $names = $sid !== '' && $acct !== '' && ($sid === 'sess-' . $acct || 'acct-' . substr($sid, 5) === $acct);
        $want = ($c->isSessionTrusted && $names) ? 'P' : 'U';
        $checked++;
        $pLanes += $x['lanes'][$i] === 'P' ? 1 : 0;
        if ($x['lanes'][$i] !== $want) {
            $mismatch[] = "{$x['stream']}/{$x['strategy']}#$i:{$x['lanes'][$i]}≠$want";
        }
    }
}
g1VEmit('v03', ['case' => 'C4-H(a) M1 re-derivation from the DTO', 'eventsChecked' => $checked, 'pLanes' => $pLanes, 'mismatches' => count($mismatch),
    'first' => array_slice($mismatch, 0, 5), 'pass' => $mismatch === [] && $checked > 0, 'scope' => 'conditional on the Host implementing M1']);

// (b) Boundary negative tests on the harness classifier (the Host role) and on the isolated package surfaces.
$cell = new G1Cell('CAND', 'login', $p);
$ctx = static fn(string $acct, ?string $sid, bool $trusted): RateLimitContextDTO => new RateLimitContextDTO('198.51.100.60', FIREFOX_130, $acct, null, $sid, $trusted, [], false, null);
$b = [
    'B1 proof names another account' => $cell->lane($ctx('acct-v', 'sd-v', true), 'acct-other'),
    'B2 empty device secret' => $cell->lane($ctx('acct-v', '', true), 'acct-v'),
    'B2 null device secret' => $cell->lane($ctx('acct-v', null, true), 'acct-v'),
];
$sp5 = g1Materialise('SP5', 'login', 'none');
$b['B3 Host-asserted trusted session of another target (SP5, all events)'] = implode('', array_unique(array_map(
    static fn(array $r): string => $cell->lane($r['ctx'], $r['proof']),
    $sp5,
)));
$otp = new G1Cell('CAND', 'otp', $p);
$b['B4 proof minted for login presented on otp'] = $otp->lane($ctx('acct-v', 'sd-v', true), 'acct-v');
foreach ($b as $case => $lane) {
    $expectedU = ! str_starts_with($case, 'B4');
    g1VEmit('v03', ['case' => $case, 'lane' => $lane, 'expected' => $expectedU ? 'U' : 'not enforced by M1 (no operation binding)',
        'pass' => $expectedU ? $lane === 'U' : null, 'status' => $expectedU ? ($lane === 'U' ? 'PASS (M1)' : 'FAIL') : ($lane === 'P' ? 'NOT ENFORCED' : 'ENFORCED')]);
}
// B5 — direct lane claims at the isolated package surfaces, for an account the caller holds no proof for.
$cell = new G1Cell('CAND', 'login', $p);
$forged = $ctx('acct-victim5', 'sd-forged', false);
$pipelineAccepts = true;
try {
    $cell->lab->pipeline->g1SetLane('P');
    $cell->lab->run($forged, 'check');
} catch (\Throwable) {
    $pipelineAccepts = false;
}
$adm = $cell->adm?->reserve('P', '198.51.100.60', 'acct-victim5', 'sd-forged', 'fp-forged', $cell->now());
g1VEmit('v03', ['case' => 'B5 direct lane claim', 'pipelineAcceptsLaneP' => $pipelineAccepts, 'admissionAcceptsForeignP' => $adm['ok'] ?? null,
    'status' => 'NOT ENFORCED ⇒ package-boundary guarantee UNPROVEN (no typed proof/account/operation binding in the isolated patch; building one needs a new API, not authorized)']);

// Consequence of a forged P claim the Host accepted (the boundary failing): victim acct-vb, owner with a real proof logs in every
// 300 s for 1 h; attacker fails every 15 s from one IP claiming P for acct-vb with (i) one forged device secret, (ii) a new forged
// secret per attempt. Measured: attacker reach on the victim, owner decisions and causes, the victim's K4P / P device state.
foreach (['fixed secret' => false, 'rotating secrets' => true] as $label => $rotate) {
    $rows = [];
    $ownerCtx = new RateLimitContextDTO('198.51.100.70', FIREFOX_130, 'acct-vb', null, 'sd-vb-owner', true, [], false, null);
    for ($t = 0, $n = 0; $t < 3600; $t += 15, $n++) {
        if ($t % 300 === 0) {
            $rows[] = ['t' => $t, 'ctx' => $ownerCtx, 'proof' => 'acct-vb', 'kind' => 'O', 'own' => false, 'who' => 'owner', 'outcome' => 'ok'];
        }
        $sid = $rotate ? "sd-forged-$n" : 'sd-forged';
        $rows[] = ['t' => $t + 3, 'ctx' => new RateLimitContextDTO('192.0.2.77', CHROME, 'acct-vb', null, $sid, true, [], false, null),
            'proof' => 'acct-vb', 'kind' => 'X', 'own' => false, 'who' => 'atk', 'outcome' => 'fail'];
    }
    $cell = new G1Cell('CAND', 'login', $p);
    $r = g1VReplay($cell, $rows, 'pre+out');
    $o = '';
    $reach = 0;
    $atk = 0;
    for ($i = 0, $n = strlen($r['kinds']); $i < $n; $i++) {
        if ($r['kinds'][$i] === 'O') {
            $o .= $r['dec'][$i];
        } else {
            $atk++;
            $reach += $r['reach'][$i] === '1' ? 1 : 0;
        }
    }
    g1VEmit('v03', ['case' => "B5 consequence: forged P claim, $label", 'attackerAttempts' => $atk, 'attackerReach' => $reach,
        'attackerLanes' => count_chars($r['lanes'], 3), 'ownerDecisions' => $o, 'ownerDenied' => strlen($o) - substr_count($o, 'A'),
        'ownerDeniedByCause' => $r['legitDeniedByCause'], 'victimSnap' => g1Snap($cell, $ownerCtx)]);
}
