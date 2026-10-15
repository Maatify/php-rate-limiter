<?php

declare(strict_types=1);

// O01 — shared NAT offices of 12 / 50 / 100 / 250 browsers (one IP, one Firefox major), fingerprint mode LOW (no client data) or MED
// (stable client fingerprint per browser), a share of browsers already known to their own account (Host previously-verified fact: 0, 50,
// 90 or 100 %), with no attacker or with a credential-less sprayer (NC) / a credentialed known attacker (KC) on the same NAT. One morning,
// fresh store, Host flow pre+out with retries (lib/akdr.php "Office engine"). Every relief variant is a MODEL (lib/akdr.php header).
//
// Unknown browsers in a mixed office stand for "new user" AND "lost the Host's device cookie": the library receives the same inputs for
// both (no previously-verified flag; in MED the client fingerprint is the browser's own either way).
require dirname(__DIR__) . '/lib/akdr.php';

emit('O01', ['row' => 'provenance'] + akdrProvenance());
foreach (akdrOCells() as [$n, $mode, $pct, $attacker, $variant]) {
    $lab = new AkdrLab('login');
    $state = new AkdrReliefState();
    $origin = [];
    $known = akdrKnownSet($n, $pct, 4242 + $n);
    $r = akdrOfficeDay($lab, $state, $origin, 0, $n, $mode, $known, $attacker, $variant, 1102 + $n);
    emit('O01', [
        'row' => 'cell', 'size' => $n, 'mode' => $mode, 'knownPct' => $pct, 'attacker' => $attacker, 'variant' => $variant,
        'known' => implode('', array_map(static fn(bool $k): string => $k ? '1' : '0', $known)),
        'relievedRequests' => $r['relievedRequests'],
        'attackReached' => $r['attack']['reached'], 'attackAttempts' => $r['attack']['n'], 'attackAccounts' => count($r['attack']['accounts']),
        'attackMaxPerAccount' => $r['attack']['accounts'] === [] ? 0 : max($r['attack']['accounts']),
        'causes' => $r['causes'],
        'tokens' => implode(' ', $r['tokens']),
    ]);
}
