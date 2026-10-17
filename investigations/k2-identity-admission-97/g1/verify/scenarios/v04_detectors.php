<?php

declare(strict_types=1);

// G1 verification V04 — detector controls on the reference store (VERIFY_PLAN.md §2, F126-G1-03). Candidate build.
// M-C1: a store admitting one unit over every cap — both the log recount and the independent event recount must flag it, while
//       the real store on the same cells must not.
// R3-H: the R-3 / PA-6 all-or-nothing check at value level, on the real store and on the partial-write mutant M-R3; the ORIGINAL
//       key-name check is evaluated on both to show what it can and cannot see.

require dirname(__DIR__) . '/lib/verify.php';

$prov = g1Provenance();
if ($prov['runtime'] !== 'CAND' || $prov['build'] !== 'CAND') {
    fwrite(STDERR, "v04 must run on the candidate build\n");
    exit(4);
}
$p = g1Params('R');
$cells = [['X1', 'none', 'pre'], ['BRU', 'none', 'pre'], ['SP3', 'none', 'pre'], ['KS50', 'none', 'pre'], ['D24K1', 'none', 'pre'], ['X1API', 'none', 'pre+out']];
foreach (['real' => null, 'M-C1 over-cap store' => 'over'] as $label => $mode) {
    foreach ($cells as [$id, $strategy, $flow]) {
        $shape = g1Streams()[$id]['shapes'][0];
        $rows = g1Materialise($id, $shape, $strategy);
        $store = $mode === null ? new G1RefStore() : new G1VWrapStore(new G1RefStore(), $mode);
        $cell = new G1Cell('CAND', $shape, $p, $store);
        $r = g1VReplay($cell, $rows, $flow);
        $log = g1VOverCap(g1Recount($cell->adm->log, $p));
        $ev = g1VOverCap(g1VEventRecount($cell, $rows, $r['lanes'], $r['reach'], $flow, $p));
        g1VEmit('v04', ['case' => "C1-H $label", 'stream' => $id, 'flow' => $flow, 'logOver' => $log, 'eventOver' => $ev,
            'refusals' => count($cell->adm->refusals), 'flagged' => $log !== [] && $ev !== [], 'clean' => $log === [] && $ev === []]);
    }
}

// R3-H: account full, source has headroom; the refused reservation must leave key names AND values unchanged.
$now = 1767268800 + 60;
$small = ['U_src' => 5, 'U_acct' => 1, 'U_glob' => 0] + $p;
foreach (['real' => null, 'M-R3 partial-write store' => 'partial'] as $label => $mode) {
    $st = $mode === null ? new G1RefStore() : new G1VWrapStore(new G1RefStore(), $mode);
    $a = new G1Admission($st, $small);
    $a->reserve('U', '198.51.100.210', 'victimAN', null, null, $now);
    $keys = $st->liveKeys($now);
    $vals = g1VValues($st, $now);
    $res = $a->reserve('U', '198.51.100.211', 'victimAN', null, null, $now);
    $keysAfter = $st->liveKeys($now);
    $valsAfter = g1VValues($st, $now);
    // The partial mutant increments the FIRST counter (the new source) before validation: a new key appears. Run it also with the
    // first counter on an existing key (same source), where key names cannot reveal the defect.
    $a2 = new G1Admission($mode === null ? new G1RefStore() : new G1VWrapStore(new G1RefStore(), $mode), $small);
    $a2->reserve('U', '198.51.100.212', 'victimAO', null, null, $now);
    $k2 = $a2->store->liveKeys($now);
    $v2 = g1VValues($a2->store, $now);
    $res2 = $a2->reserve('U', '198.51.100.212', 'victimAO', null, null, $now);
    g1VEmit('v04', ['case' => "R3-H $label", 'refused' => [$res['refused'], $res2['refused']],
        'original(keysUnchanged)' => [$keys === $keysAfter, $k2 === $a2->store->liveKeys($now)],
        'hardened(valuesUnchanged)' => [$vals === $valsAfter, $v2 === g1VValues($a2->store, $now)],
        'originalPass' => $res['refused'] === 'acct' && $keys === $keysAfter && $res2['refused'] === 'acct' && $k2 === $a2->store->liveKeys($now),
        'hardenedPass' => $res['refused'] === 'acct' && $keys === $keysAfter && $vals === $valsAfter
            && $res2['refused'] === 'acct' && $k2 === $a2->store->liveKeys($now) && $v2 === g1VValues($a2->store, $now)]);
}
