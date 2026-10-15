<?php

declare(strict_types=1);

// V01 — known-device attackers and their controls on main (DEC-021 ACTIVE), and the same streams under the relief MODELS A, B, C1, C2,
// C3, D1, D2 (lib/akdr.php header). Flows pre+out / pre for every variant, out (record-only) for MAIN and C1. Login with account; OTP for
// a subset. The #113 streams are reused unchanged (tmsStreams()); #113's S0 numbers are NOT re-run here (they are prior evidence, read by
// report.php from investigations/trusted-multi-session-s0/expected/ and printed in a separate column).
//
// Gates emitted here (report.php re-checks every one):
//   G-EQ      the MAIN variant (inert decorators) gives, request for request, the same denial / reach / attribution as the unchanged
//             PR #108 k1Replay on k1Lab('A') — the decorators do not change main.
//   G-STREAM  stream construction: compliant streams target only accounts their known device is bound to; Host-violating ones do not.
//   G-SUPP    no relieved request is denied by a network-only source (the relief view is effective).
require dirname(__DIR__) . '/lib/akdr.php';

emit('V01', ['row' => 'provenance'] + akdrProvenance());
$S = akdrVStreams();

// ---- G-EQ ---------------------------------------------------------------------------------------------------------------------------
$eqCells = 0;
$eqDiff = 0;
foreach ($S as $id => $s) {
    foreach (['login', 'otp'] as $policy) {
        foreach (['pre+out', 'pre', 'out'] as $flow) {
            $ref = k1Replay(k1Lab('A', $policy), $s['events'], $flow, $policy === 'otp' ? 'otp' : 'login');
            $mine = akdrReplay($s['events'], $policy, $flow, 'MAIN');
            $eqCells++;
            foreach ($ref as $i => $r) {
                if ($r['blocked'] !== $mine[$i]['blocked'] || $r['reached'] !== $mine[$i]['reached'] || $r['src'] !== $mine[$i]['src'] || $mine[$i]['relieved']) {
                    $eqDiff++;
                }
            }
        }
    }
}
emit('V01', ['row' => 'gate', 'gate' => 'G-EQ', 'ok' => $eqDiff === 0, 'cells' => $eqCells, 'differingRequests' => $eqDiff]);

// ---- G-STREAM -----------------------------------------------------------------------------------------------------------------------
foreach ($S as $id => $s) {
    $binding = [];
    $known = 0;
    foreach ($s['events'] as $e) {
        $c = k1Ctx($e, 'login');
        if ($e['kind'] !== 'attack' || ! akdrIsKnown($c)) {
            continue;
        }
        $known++;
        // the known device's own account binding: trusted session id or the previously-verified device fingerprint label
        $dev = $c->isSessionTrusted ? 'sess:' . $c->sessionDeviceId : 'fp:' . ($e['fp'] ?? 'LOW');
        $binding[$dev][(string) $c->accountId] = true;
    }
    $multi = count(array_filter($binding, static fn(array $a): bool => count($a) > 1));
    // LOW previously-verified devices share one label by construction; for them the Host's own cookie is the binding (not observable here).
    $lowShared = in_array($id, ['VMSdL', 'D24V'], true);
    $ok = $s['compliant'] ? ($multi === 0 || $lowShared) : ($multi > 0 || $id === 'SPV' || $id === 'SP5');
    emit('V01', ['row' => 'gate', 'gate' => 'G-STREAM', 'stream' => $id, 'ok' => $ok, 'compliant' => $s['compliant'], 'knownAttackRequests' => $known, 'devices' => count($binding), 'devicesBoundToSeveralAccounts' => $multi]);
}

// ---- cells --------------------------------------------------------------------------------------------------------------------------
// G-SUPP: a relieved request must never be denied by a source made only of the scopes its variant relieves (a persisted block on them,
// or a block the PIPELINE issued on them in that request). Model-persisted collective triggers (^) are excluded by attribution.
$suppViolations = 0;
$relievedDenials = 0;
foreach (akdrVCells() as [$id, $policy, $flow, $variant]) {
    $rows = akdrReplay($S[$id]['events'], $policy, $flow, $variant);
    foreach ($rows as $r) {
        if (! $r['relieved'] || ! $r['blocked']) {
            continue;
        }
        $relievedDenials++;
        $cause = akdrCause($r['src']);
        $scopes = explode('+', substr($cause, strpos($cause, ':') + 1));
        if ($scopes !== ['-'] && array_diff($scopes, AKDR_VARIANTS[$variant]['hide']) === []) {
            $suppViolations++;
        }
    }
    emit('V01', ['row' => 'cell', 'stream' => $id, 'policy' => $policy, 'flow' => $flow, 'variant' => $variant, 'class' => $S[$id]['class'], 'compliant' => $S[$id]['compliant']]
        + akdrAggregate($rows) + akdrTrace($rows));
}
emit('V01', ['row' => 'gate', 'gate' => 'G-SUPP', 'ok' => $suppViolations === 0, 'relievedDenials' => $relievedDenials, 'relievedDeniedByRelievedScopesOnly' => $suppViolations]);
