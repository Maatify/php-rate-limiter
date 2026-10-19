<?php

declare(strict_types=1);

// G5 POST-HOC attribution (not in PLAN.md, excluded from every gate; PLAN.md §7 "post-hoc"): NOUA with the P-lane new-device member
// kept on the fingerprint (arm NOUA-FPN), on the cells where NOUA differs from REF in a way the registered gates name: STEAL, UAX-NP,
// the STREAMS cells X7 / rotate / const and X7 / uarot / const and UAC / none / fresh. It separates the effect of the id-keyed K5
// material from the effect of the id-keyed new-device member (N_P).

require dirname(__DIR__) . '/lib/g5fx.php';

use Maatify\RateLimiter\DTO\RateLimitContextDTO;

if (g5Provenance() !== 'G5') {
    exit(0);
}
$arm = 'NOUA-FPN';
g4Emit('ph', g5Steal($arm));

$ua = static fn(int $major): string => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/$major.0.0.0 Safari/537.36";
[$cell, $gate, $host] = g5Cell($arm);
$rows = [g4v2Issue(0, 'h:np', 'acct-np', 'i:np')];
for ($i = 0; $i < 12; $i++) {
    $rows[] = g4v2Row1(3600 * $i + 60, new RateLimitContextDTO('198.51.100.60', $ua(120 + $i), 'acct-np', null, null, false, [], true, null), 'O', 'np', 'ok', 'i:np', 'h:np');
}
$r = g5Replay($cell, $gate, $host, g4v2Sort($rows), 'pre+out', true);
g4Emit('ph', ['fixture' => 'UAX-NP', 'arm' => $arm, 'lanes' => $r['lanes'], 'dec' => $r['dec'], 'refused' => strlen($r['dec']) - substr_count($r['dec'], 'A'),
    'causes' => $r['legitDeniedByCause']]);

foreach ([['X7', 'rotate', 'const'], ['X7', 'uarot', 'const'], ['UAC', 'none', 'fresh']] as [$id, $strategy, $policy]) {
    $srows = g4v2FromStream($id, $strategy, $policy);
    [$cell, $gate, $host] = g5Cell($arm);
    $r = g5Replay($cell, $gate, $host, $srows, 'pre+out', true);
    g4Emit('ph', ['stream' => $id, 'strategy' => $strategy, 'ids' => $policy, 'arm' => $arm, 'flow' => 'pre+out'] + g4Row($srows, $r));
}
