<?php

declare(strict_types=1);

// G1 verification V02 — C9-H on a MIXED account (VERIFY_PLAN.md §2, F126-G1-01). Candidate build (G1_ARM=CAND, G1_BUILD unset).
// Fixture MX: a P owner with live P state on acct-mx; interleaved U failures on the SAME account (rotating sources and UAs, a
// historical-known device, the owner's sessionDeviceId presented without proof, an untrusted session); owner P requests in
// between; then a secret rotation (pipeline previous secret + admission previous generation) handed over mid-fixture.
// Every U command and U admission is checked: the P state of acct-mx (both secrets, both generations) must not change.
// Detector controls: M-C9a (lane leak) and M-C9b (admission leak) must be reported; the ORIGINAL checker is run on them too.

require dirname(__DIR__) . '/lib/verify.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

$prov = g1Provenance();
if ($prov['runtime'] !== 'CAND' || $prov['build'] !== 'CAND') {
    fwrite(STDERR, "v02 must run on the candidate build\n");
    exit(4);
}
$p = g1Params('R');
$acct = 'acct-mx';
$ctx = static fn(string $ip, string $ua, ?string $sid, bool $trusted, bool $verified): RateLimitContextDTO =>
    new RateLimitContextDTO($ip, $ua, $acct, null, $sid, $trusted, [], $verified, null);
$owner = $ctx('198.51.100.150', FIREFOX_130, 'sd-mx-owner', true, false);

/** @return list<array<string, mixed>> */
$phase = static function (int $from, int $to, bool $withU, array $variants = [0, 1, 2, 3]) use ($ctx, $owner, $acct): array {
    $rows = [];
    $row = static fn(int $t, RateLimitContextDTO $c, ?string $proof, string $kind, string $out): array =>
        ['t' => $t, 'ctx' => $c, 'proof' => $proof, 'kind' => $kind, 'own' => false, 'who' => $kind, 'outcome' => $out];
    $n = 0;
    for ($t = $from; $t < $to; $t += 20, $n++) {
        if ($t % 120 === 0) {
            $rows[] = $row($t, $owner, $acct, 'O', intdiv($t, 120) % 3 === 2 ? 'ok' : 'fail');
        }
        if (! $withU || $t < $from + 20 || ! in_array($n % 4, $variants, true)) {
            continue;
        }
        $u = match ($n % 4) {
            0 => $ctx('192.0.2.' . (10 + $n % 20), k1Ua(100 + $n % 20), null, false, false),      // rotating source + UA
            1 => $ctx('198.51.100.150', FIREFOX_130, null, false, true),                          // historical-known device, no proof
            2 => $ctx('203.0.113.' . (10 + $n % 7), FIREFOX_130, 'sd-mx-owner', false, false),    // owner's device secret, no proof
            default => $ctx('198.18.0.' . (1 + $n % 9), CHROME, 'sess-other', false, false),     // untrusted session
        };
        $rows[] = $row($t + 7, $u, null, 'X', 'fail');
    }

    return $rows;
};

$run = static function (?string $mutant, bool $withU, array $variants = [0, 1, 2, 3], ?callable $pick = null) use ($p, $phase, $acct): array {
    $cell = new G1Cell('CAND', 'login', $p);
    $r1 = g1VReplay($cell, $phase(0, 1800, $withU, $variants), 'pre+out', ['c9' => true, 'mutant' => $mutant, 'mutantAcct' => $acct, 'mutantPick' => $pick]);
    $orig1 = g1PStateLeaks($cell);
    // Secret rotation: pipeline secret lab-secret-2 (previous = Lab::SECRET), admission generation g1-secret-2 (previous = current).
    $file = (string) tempnam(sys_get_temp_dir(), 'g1v');
    g1SaveState($cell, $file);
    $rot = new G1Cell('CAND', 'login', $p, gen: 'g1-secret-2', prevGen: 'g1-secret-current', state: g1LoadState($file),
        previousSecret: Lab::SECRET, secret: 'lab-secret-2');
    @unlink($file);
    $rot->pAccounts = $cell->pAccounts;
    $rot->accounts = $cell->accounts;
    $r2 = g1VReplay($rot, $phase(1800, 3000, $withU, $variants), 'pre+out', ['c9' => true, 'secrets' => g1VSecrets(Lab::SECRET, 'lab-secret-2'),
        'mutant' => $r1['mutantApplied'] ? null : $mutant, 'mutantAcct' => $acct, 'mutantPick' => $pick]);

    return ['r1' => $r1, 'r2' => $r2, 'orig' => [$orig1, g1PStateLeaks($rot)], 'pState' => g1VPState($rot, [$acct], g1VSecrets(Lab::SECRET, 'lab-secret-2'))];
};

$ownerDec = static function (array $r): string {
    $o = '';
    foreach ([$r['r1'], $r['r2']] as $x) {
        for ($i = 0, $n = strlen($x['kinds']); $i < $n; $i++) {
            $o .= $x['kinds'][$i] === 'O' ? $x['dec'][$i] : '';
        }
    }

    return $o;
};

$base = $run(null, true);
$control = $run(null, false);
$c9 = static fn(array $r): array => [
    'checked' => $r['r1']['c9']['checked'] + $r['r2']['c9']['checked'],
    'mixedChecked' => $r['r1']['c9']['mixedChecked'] + $r['r2']['c9']['mixedChecked'],
    'violations' => array_merge($r['r1']['c9']['violations'], $r['r2']['c9']['violations']),
];
$b = $c9($base);
g1VEmit('v02', ['case' => 'C9-H MX', 'checkedUEvents' => $b['checked'], 'mixedChecked' => $b['mixedChecked'], 'violations' => count($b['violations']),
    'kinds' => $base['r1']['kinds'] . '|' . $base['r2']['kinds'], 'lanes' => $base['r1']['lanes'] . '|' . $base['r2']['lanes'],
    'dec' => $base['r1']['dec'] . '|' . $base['r2']['dec'],
    'ownerDecisions' => $ownerDec($base), 'ownerDecisionsControlWithoutU' => $ownerDec($control),
    'ownerIdenticalToControl' => $ownerDec($base) === $ownerDec($control), 'pStateIdenticalToControl' => $base['pState'] === $control['pState'],
    'pass' => $b['checked'] > 0 && $b['mixedChecked'] > 0 && $b['violations'] === []]);
// M-C9a0 is the placement as first written (the first U failure on acct-mx, a historical-known device): a known-device failure
// writes no P-specific key even on the P lane (K5 and the shared allowance only), so it cannot exercise the detector; kept as
// recorded. M-C9a places the same lane leak on the first unknown-device U failure that reaches the record step (neither the
// owner's historical device nor the owner's device secret), which does write K4P on the P lane.
$unknown = static fn(RateLimitContextDTO $c): bool => $c->ip !== '198.51.100.150' && $c->sessionDeviceId !== 'sd-mx-owner';
foreach (['M-C9a0' => ['lane-leak', null], 'M-C9a' => ['lane-leak', $unknown], 'M-C9b' => ['adm-leak', null]] as $label => [$m, $pick]) {
    $x = $run($m, true, [0, 1, 2, 3], $pick);
    $v = $c9($x);
    g1VEmit('v02', ['case' => "C9-H detector $label ($m)", 'mutantApplied' => $x['r1']['mutantApplied'] || $x['r2']['mutantApplied'],
        'violations' => count($v['violations']), 'firstViolation' => $v['violations'][0] ?? null,
        'originalCheckerLeaks' => $x['orig'], 'originalCheckerDetects' => $x['orig'][0] !== [] || $x['orig'][1] !== [],
        'detected' => count($v['violations']) > 0]);
}

// Decomposition (diagnostic, after the pre-declared run): the owner's P decisions with each subset of U variants. I4b
// (SECURITY_FEASIBILITY.md) excludes a request presenting the P device's sessionDeviceId (variant 2); the subsets without it
// test I4b as stated, the subsets with it measure the consequence of that declared exception.
$ctrl = $ownerDec($control);
foreach ([[0], [1], [2], [3], [0, 1, 3], [0, 2], [1, 2], [2, 3], [0, 1, 2, 3]] as $vs) {
    $x = $run(null, true, $vs);
    g1VEmit('v02', ['case' => 'I4b decomposition', 'variants' => implode('', $vs), 'presentsOwnerSecret' => in_array(2, $vs, true),
        'ownerDecisions' => $ownerDec($x), 'ownerDenied' => strlen($ownerDec($x)) - substr_count($ownerDec($x), 'A'),
        'ownerHard' => substr_count($ownerDec($x), 'H'), 'identicalToControl' => $ownerDec($x) === $ctrl,
        'c9Violations' => count($c9($x)['violations'])]);
}
g1VEmit('v02', ['case' => 'I4b decomposition control', 'ownerDecisions' => $ctrl, 'ownerDenied' => strlen($ctrl) - substr_count($ctrl, 'A'),
    'ownerHard' => substr_count($ctrl, 'H')]);
