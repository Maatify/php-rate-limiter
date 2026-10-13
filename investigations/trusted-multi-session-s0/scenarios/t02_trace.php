<?php

declare(strict_types=1);

// T02 — per-request traces on S0 for the trace streams (Login, three flows): which key denied each attempt, which blocks it issued, and a
// request-for-request comparison of the trusted office N12T with the trusted multi-session attacker TMS (same t / ip / ua / fingerprint
// index / trust; only the outcome and the account names differ). Data rows only; the invariants are in T01.
require dirname(__DIR__) . '/lib/tms.php';

emit('T02.provenance', tmsProvenance());
$streams = tmsStreams();
const TRACE_ROWS = 80;

foreach (tmsTraceStreams() as $id) {
    foreach (['pre+out', 'pre', 'out'] as $flow) {
        $m = tmsMeasure($streams[$id]['events'], 'login', $flow, true);
        $rows = $m['rows'];
        $trace = [];
        foreach (array_slice($rows, 0, TRACE_ROWS) as $i => $r) {
            $trace[] = ['i' => $i, 't' => $r['t'], 'acct' => $r['subject'], 'trusted' => $r['trusted'], 'steps' => $r['steps'], 'src' => $r['src'], 'issued' => $r['issued'] === [] ? '' : implode('+', $r['issued'])];
        }
        emit('T02.trace', ['stream' => $id, 'flow' => $flow, 'requests' => count($rows), 'shown' => count($trace), 'runs' => k1Runs($rows), 'reached' => $m['attackReachedVerification'] ?: null, 'legitDenied' => $m['legitDenied'] ?: null, 'rows' => $trace]);
    }
}

// Divergence N12T vs TMS, request for request, per flow: the first index at which the decision (blocked or not) differs, the number of
// differing decisions, and the denial-source multiset of each side. Identical decisions = S0 cannot tell them apart in that flow.
foreach (['pre+out', 'pre', 'out'] as $flow) {
    $a = tmsMeasure($streams['N12T']['events'], 'login', $flow, true)['rows'];
    $b = tmsMeasure($streams['TMS']['events'], 'login', $flow, true)['rows'];
    $diff = 0;
    $first = null;
    $detail = [];
    foreach ($a as $i => $ra) {
        $rb = $b[$i];
        if ($ra['blocked'] !== $rb['blocked']) {
            $diff++;
            $first ??= $i;
            if (count($detail) < 12) {
                $detail[] = ['i' => $i, 't' => $ra['t'], 'N12T' => $ra['steps'], 'TMS' => $rb['steps']];
            }
        }
    }
    $srcA = [];
    $srcB = [];
    foreach ($a as $r) {
        if ($r['blocked']) {
            $srcA[(string) $r['src']] = ($srcA[(string) $r['src']] ?? 0) + 1;
        }
    }
    foreach ($b as $r) {
        if ($r['blocked']) {
            $srcB[(string) $r['src']] = ($srcB[(string) $r['src']] ?? 0) + 1;
        }
    }
    ksort($srcA);
    ksort($srcB);
    emit('T02.divergence', ['flow' => $flow, 'requests' => count($a), 'N12T_denied' => count(array_filter($a, static fn(array $r): bool => $r['blocked'])), 'TMS_denied' => count(array_filter($b, static fn(array $r): bool => $r['blocked'])), 'decisionsDiffer' => $diff, 'firstDifferingIndex' => $first, 'N12T_sources' => $srcA, 'TMS_sources' => $srcB, 'detail' => $detail]);
}
