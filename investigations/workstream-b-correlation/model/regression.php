<?php

declare(strict_types=1);

// F103-02 regression: requests DENIED by an active block must not feed failure witnesses (the real pipeline returns
// before any mutation). Record-only host; an attacker keeps failing with fresh fingerprints during the 60 s block and
// stops exactly at expiry. Correct lifecycle => no fresh witness => no re-block. The rejected legacy ordering invents
// witnesses from denied requests and re-blocks.
require dirname(__DIR__) . '/lib/bootstrap.php';
require __DIR__ . '/PolicyModel.php';

$e = static fn(int $t, string $fp, string $o = 'fail'): array => ['t' => $t, 'ip' => '198.51.100.77', 'ua' => 'chrome126', 'fp' => $fp, 'acct' => 'victim', 'outcome' => $o, 'kind' => 'attack'];
// Three failing fingerprints trigger the block at t=20 (record-only flow); denied failures follow until t=75 (block ends t=80);
// at t=80/85 only a lone SUCCESS by an already-seen fingerprint arrives after expiry (no new evidence of abuse).
$events = [$e(0, 'a'), $e(10, 'b'), $e(20, 'c')];
for ($t = 25; $t <= 75; $t += 10) {
    $events[] = $e($t, "denied$t");
}
$events[] = $e(80, 'a', 'ok');
$events[] = $e(85, 'a', 'ok');

foreach (['PW' => false, 'PW-legacy-order(REJECTED)' => true] as $label => $legacy) {
    $m = new PolicyModel('PW', 'distinct', $legacy);
    $rows = [];
    foreach ($events as $ev) {
        $r = $m->attempt($ev, 'out');
        $rows[] = $ev['t'] . ':' . ($r['blocked'] ? 'H' . $r['steps'][0]['retry'] : 'A');
    }
    emit('F103-02.denied-record-only', ['model' => $label, 'timeline' => $rows, 'witnessWritesWhileDenied' => $m->witnessWritesWhileDenied]);
}
